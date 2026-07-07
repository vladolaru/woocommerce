<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayOrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Helper_Order;
use WC_Unit_Test_Case;
use WC_Webhook;

/**
 * Tests for the WooPaymentsWooPayOrderStatusSync class.
 */
class WooPaymentsWooPayOrderStatusSyncTest extends WC_Unit_Test_Case {

	/**
	 * Created sync instances whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsWooPayOrderStatusSync[]
	 */
	private array $syncs = array();

	/**
	 * Created webhook IDs.
	 *
	 * @var int[]
	 */
	private array $webhook_ids = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->syncs as $sync ) {
			$this->remove_sync_hooks( $sync );
		}

		foreach ( $this->webhook_ids as $webhook_id ) {
			$webhook = new WC_Webhook( $webhook_id );
			$webhook->delete( true );
		}

		remove_all_actions( 'wcpay_webhook_platform_checkout_order_status_changed' );

		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::delete_option( 'id' );
		}

		parent::tearDown();
	}

	/**
	 * @testdox WooPay order-status hooks are registered only when native owns runtime.
	 */
	public function test_registers_hooks_only_when_native_owns_runtime(): void {
		$native_sync = $this->create_sync( true );

		$native_sync->register();

		$this->assertSame( 10, has_filter( 'woocommerce_valid_webhook_resources', array( $native_sync, 'add_resource' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_valid_webhook_events', array( $native_sync, 'add_event' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_webhook_topic_hooks', array( $native_sync, 'add_topics' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_webhook_payload', array( $native_sync, 'create_payload' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_changed', array( $native_sync, 'send_webhook' ) ) );

		$plugin_sync = $this->create_sync( false );
		$plugin_sync->register();

		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_resources', array( $plugin_sync, 'add_resource' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_events', array( $plugin_sync, 'add_event' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_topic_hooks', array( $plugin_sync, 'add_topics' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_payload', array( $plugin_sync, 'create_payload' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_changed', array( $plugin_sync, 'send_webhook' ) ) );
	}

	/**
	 * @testdox A plugin-created WooPay order-status webhook row resolves under native topic filters.
	 */
	public function test_plugin_created_webhook_row_resolves_under_native_filters(): void {
		$this->assertFalse( wc_is_webhook_valid_topic( 'order.status_changed' ) );

		$this->with_plugin_topic_filters(
			function (): void {
				$webhook                 = $this->create_plugin_created_webhook( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
				$this->webhook_ids[]     = $webhook->get_id();
				$this->assertSame( 'order.status_changed', $webhook->get_topic() );
				$this->assertNotSame( 0, $webhook->get_id() );
			}
		);

		$this->assertFalse( wc_is_webhook_valid_topic( 'order.status_changed' ) );

		$sync = $this->create_sync( true );
		$sync->register();

		$this->assertTrue( wc_is_webhook_valid_topic( 'order.status_changed' ) );
		$this->assertContains(
			'wcpay_webhook_platform_checkout_order_status_changed',
			WC_Webhook::get_default_topic_hooks()[ 'order.status_changed' ]
		);

		$webhook = new WC_Webhook( $this->webhook_ids[0] );
		$this->assertSame( 'order.status_changed', $webhook->get_topic() );
		$this->assertContains( 'wcpay_webhook_platform_checkout_order_status_changed', $webhook->get_hooks() );
	}

	/**
	 * @testdox WooPay status changes fire the preserved delivery action only for WooPay orders.
	 */
	public function test_status_change_fires_preserved_action_only_for_woopay_orders(): void {
		$sync = $this->create_sync( true );
		$sync->register();

		$woopay_order = WC_Helper_Order::create_order();
		$woopay_order->update_meta_data( 'is_woopay', true );
		$woopay_order->save();

		$regular_order = WC_Helper_Order::create_order();

		$observed = array();
		add_action(
			'wcpay_webhook_platform_checkout_order_status_changed',
			function ( int $order_id, string $next_status ) use ( &$observed ): void {
				$observed[] = array( $order_id, $next_status );
			},
			10,
			2
		);

		$sync->send_webhook( $woopay_order->get_id(), 'pending', 'processing' );
		$sync->send_webhook( $regular_order->get_id(), 'pending', 'processing' );

		$this->assertSame( array( array( $woopay_order->get_id(), 'processing' ) ), $observed );
	}

	/**
	 * @testdox WooPay merchant-notification payloads are rewritten to the platform checkout contract.
	 */
	public function test_create_payload_rewrites_only_woopay_merchant_notification_webhooks(): void {
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::update_option( 'id', 98765 );
		}

		$sync = $this->create_sync( true );
		$sync->register();

		$woopay_webhook             = $this->create_plugin_created_webhook( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
		$this->webhook_ids[]       = $woopay_webhook->get_id();
		$non_woopay_webhook        = $this->create_plugin_created_webhook( 'https://example.com/webhook' );
		$this->webhook_ids[]       = $non_woopay_webhook->get_id();
		$pre_processing_payload    = array(
			'status' => 'processing',
			'id'     => 123,
		);
		$expected_platform_payload = array(
			'blog_id'      => class_exists( '\Jetpack_Options' ) ? \Jetpack_Options::get_option( 'id' ) : false,
			'order_id'     => 123,
			'order_status' => 'processing',
		);

		$this->assertSame(
			$expected_platform_payload,
			$sync->create_payload( $pre_processing_payload, 'order', 123, $woopay_webhook->get_id() )
		);
		$this->assertSame(
			$pre_processing_payload,
			$sync->create_payload( $pre_processing_payload, 'order', 123, $non_woopay_webhook->get_id() )
		);
	}

	/**
	 * Create a sync instance.
	 *
	 * @param bool $native_register Whether native should register.
	 * @return WooPaymentsWooPayOrderStatusSync
	 */
	private function create_sync( bool $native_register ): WooPaymentsWooPayOrderStatusSync {
		$this->assertTrue( class_exists( WooPaymentsWooPayOrderStatusSync::class ), 'WooPaymentsWooPayOrderStatusSync should exist.' );

		$session_service = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_rest_url' ) )
			->getMock();
		$session_service->method( 'get_woopay_rest_url' )
			->with( 'merchant-notification' )
			->willReturn( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );

		$sync = new WooPaymentsWooPayOrderStatusSync();
		$sync->init( new StaticNativeRuntimeArbiter( $native_register ), $session_service );

		$this->syncs[] = $sync;

		return $sync;
	}

	/**
	 * Create a WooPay webhook row using the plugin-created row shape.
	 *
	 * @param string $delivery_url Delivery URL.
	 * @return WC_Webhook
	 */
	private function create_plugin_created_webhook( string $delivery_url ): WC_Webhook {
		$webhook = new WC_Webhook();
		$webhook->set_name( 'WooPayments woopay order status sync' );
		$webhook->set_user_id( get_current_user_id() );
		$webhook->set_topic( 'order.status_changed' );
		$webhook->set_secret( 'test-secret' );
		$webhook->set_delivery_url( $delivery_url );
		$webhook->set_status( 'active' );
		$webhook->save();

		return $webhook;
	}

	/**
	 * Run a callback while the old plugin's topic filters are present.
	 *
	 * @param callable $callback Callback.
	 */
	private function with_plugin_topic_filters( callable $callback ): void {
		$add_resource = static function ( array $resources ): array {
			$resources[] = 'order';
			return $resources;
		};
		$add_event    = static function ( array $events ): array {
			$events[] = 'status_changed';
			return $events;
		};
		$add_topic    = static function ( array $topic_hooks ): array {
			$topic_hooks['order.status_changed'][] = 'wcpay_webhook_platform_checkout_order_status_changed';
			return $topic_hooks;
		};

		add_filter( 'woocommerce_valid_webhook_resources', $add_resource );
		add_filter( 'woocommerce_valid_webhook_events', $add_event );
		add_filter( 'woocommerce_webhook_topic_hooks', $add_topic, 20 );

		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_valid_webhook_resources', $add_resource );
			remove_filter( 'woocommerce_valid_webhook_events', $add_event );
			remove_filter( 'woocommerce_webhook_topic_hooks', $add_topic, 20 );
		}
	}

	/**
	 * Remove hooks registered by a sync instance.
	 *
	 * @param WooPaymentsWooPayOrderStatusSync $sync Sync instance.
	 */
	private function remove_sync_hooks( WooPaymentsWooPayOrderStatusSync $sync ): void {
		remove_filter( 'woocommerce_valid_webhook_resources', array( $sync, 'add_resource' ) );
		remove_filter( 'woocommerce_valid_webhook_events', array( $sync, 'add_event' ) );
		remove_filter( 'woocommerce_webhook_topic_hooks', array( $sync, 'add_topics' ), 20 );
		remove_filter( 'woocommerce_webhook_payload', array( $sync, 'create_payload' ) );
		remove_action( 'woocommerce_order_status_changed', array( $sync, 'send_webhook' ) );
	}
}
