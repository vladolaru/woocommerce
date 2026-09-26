<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayOrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Helper_Order;
use WC_Unit_Test_Case;
use WC_Webhook;

/**
 * Tests for the WooPaymentsWooPayOrderStatusSync class.
 */
class WooPaymentsWooPayOrderStatusSyncTest extends WC_Unit_Test_Case {
	private const WEBHOOK_ID_OPTION   = 'woocommerce_native_woopayments_woopay_webhook_id';
	private const WEBHOOK_LOCK_OPTION = 'woocommerce_native_woopayments_woopay_webhook_lock';


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
	 * Most recently created mutable WooPay session service.
	 *
	 * @var Task25WooPaySessionService|null
	 */
	private ?Task25WooPaySessionService $session_service = null;

	/**
	 * Most recently created mutable WooPayments account service.
	 *
	 * @var Task25WooPayAccountService|null
	 */
	private ?Task25WooPayAccountService $account_service = null;

	/**
	 * Most recently created recording WooPay API client.
	 *
	 * @var Task25WooPayApiClient|null
	 */
	private ?Task25WooPayApiClient $api_client = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::WEBHOOK_ID_OPTION );
		delete_option( self::WEBHOOK_LOCK_OPTION );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

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
		$owned_webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		if ( 0 < $owned_webhook_id ) {
			$owned_webhook = wc_get_webhook( $owned_webhook_id );
			if ( $owned_webhook instanceof WC_Webhook ) {
				$owned_webhook->delete( true );
			}
		}

		remove_all_actions( 'wcpay_webhook_platform_checkout_order_status_changed' );
		remove_all_filters( 'woocommerce_logging_class' );
		remove_all_filters( 'woocommerce_pre_delete_data' );
		delete_option( self::WEBHOOK_ID_OPTION );
		delete_option( self::WEBHOOK_LOCK_OPTION );
		wp_set_current_user( 0 );

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
		$this->assertSame( 10, has_action( 'admin_init', array( $native_sync, 'reconcile_webhook' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_store_setup_sync', array( $native_sync, 'reconcile_webhook' ) ) );

		$plugin_sync = $this->create_sync( false );
		$plugin_sync->register();

		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_resources', array( $plugin_sync, 'add_resource' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_events', array( $plugin_sync, 'add_event' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_topic_hooks', array( $plugin_sync, 'add_topics' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_payload', array( $plugin_sync, 'create_payload' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_changed', array( $plugin_sync, 'send_webhook' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $plugin_sync, 'reconcile_webhook' ) ) );
		$this->assertFalse( has_action( 'wcpay_store_setup_sync', array( $plugin_sync, 'reconcile_webhook' ) ) );
	}

	/**
	 * @testdox Enabled native WooPay creates and owns the exact WooCommerce webhook row.
	 */
	public function test_enabled_native_woopay_creates_and_stores_exact_webhook(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();

		$sync->reconcile_webhook();

		$webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$webhook    = wc_get_webhook( $webhook_id );
		$this->assertGreaterThan( 0, $webhook_id );
		$this->assertInstanceOf( WC_Webhook::class, $webhook );
		$this->assertSame( 'WooPayments woopay order status sync', $webhook->get_name() );
		$this->assertSame( get_current_user_id(), $webhook->get_user_id() );
		$this->assertSame( 'order.status_changed', $webhook->get_topic() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification', $webhook->get_delivery_url() );
		$this->assertSame( 'active', $webhook->get_status() );
		$this->assertSame( 'wp_api_v3', $webhook->get_api_version() );
		$this->assertSame( 50, strlen( $webhook->get_secret() ) );
		$this->assertArrayNotHasKey( self::WEBHOOK_ID_OPTION, wp_load_alloptions( true ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertSame( array( array( 'webhook_secret' => $webhook->get_secret() ) ), $this->api_client->woopay_updates, 'The new webhook secret must be registered with the platform.' );
	}

	/**
	 * @testdox A failed platform secret registration rolls the local webhook back.
	 */
	public function test_failed_woopay_secret_registration_rolls_back_local_webhook(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$this->api_client->update_woopay_exception = new WooPaymentsApiException( 'Error updating account.', 'wcpay_bad_request', 400 );

		$sync->reconcile_webhook();

		$this->assertCount( 1, $this->api_client->woopay_updates );
		$this->assertSame( 0, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ), 'A webhook whose secret WooPay does not hold must not stay owned.' );
		$this->assertCount( 0, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ), 'The local webhook row must be rolled back.' );
	}

	/**
	 * @testdox Repeated enabled reconciliation keeps exactly one owned webhook.
	 */
	public function test_repeated_enabled_reconciliation_is_idempotent(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();

		$sync->reconcile_webhook();
		$first_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$sync->reconcile_webhook();
		$second_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$this->assertGreaterThan( 0, $first_id );
		$this->assertSame( $first_id, $second_id );
		$this->assertCount( 1, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
		$this->assertCount( 1, $this->api_client->woopay_updates, 'The secret must only be registered when a webhook is created.' );
	}

	/**
	 * @testdox Enabled reconciliation replaces an inactive owned webhook without touching unrelated rows.
	 */
	public function test_enabled_reconciliation_repairs_inactive_owned_webhook(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();

		$owned_webhook = wc_get_webhook( absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertInstanceOf( WC_Webhook::class, $owned_webhook );
		$owned_webhook->set_status( 'disabled' );
		$owned_webhook->save();

		$same_url_webhook    = $this->create_named_webhook( 'Same URL but unrelated', 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
		$unrelated_webhook   = $this->create_named_webhook( 'Unrelated webhook', 'https://example.com/webhook' );
		$this->webhook_ids[] = $same_url_webhook->get_id();
		$this->webhook_ids[] = $unrelated_webhook->get_id();

		$sync->reconcile_webhook();

		$final_id      = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$final_webhook = wc_get_webhook( $final_id );
		$this->assertGreaterThan( 0, $final_id );
		$this->assertInstanceOf( WC_Webhook::class, $final_webhook );
		$this->assertSame( 'active', $final_webhook->get_status() );
		$this->assertSame( 'WooPayments woopay order status sync', $final_webhook->get_name() );
		$this->assertSame( 'order.status_changed', $final_webhook->get_topic() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification', $final_webhook->get_delivery_url() );
		$this->assertSame( 'wp_api_v3', $final_webhook->get_api_version() );
		$this->assertCount( 1, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $same_url_webhook->get_id() ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $unrelated_webhook->get_id() ) );
	}

	/**
	 * @testdox Failed inactive ownership deletion aborts replacement and remains retryable.
	 */
	public function test_inactive_owned_webhook_delete_failure_preserves_ownership_until_retry(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();

		$inactive_id      = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$inactive_webhook = wc_get_webhook( $inactive_id );
		$this->assertInstanceOf( WC_Webhook::class, $inactive_webhook );
		$inactive_webhook->set_status( 'disabled' );
		$inactive_webhook->save();
		$same_url_webhook    = $this->create_named_webhook( 'Same URL but unrelated', 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
		$unrelated_webhook   = $this->create_named_webhook( 'Unrelated webhook', 'https://example.com/webhook' );
		$this->webhook_ids[] = $inactive_id;
		$this->webhook_ids[] = $same_url_webhook->get_id();
		$this->webhook_ids[] = $unrelated_webhook->get_id();
		$prevent_delete      = static function ( $check, $data_object ) use ( $inactive_id ) {
			return $data_object instanceof WC_Webhook && $inactive_id === $data_object->get_id() ? false : $check;
		};
		add_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10, 2 );

		$sync->reconcile_webhook();
		$blocked_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids   = array_values( array_unique( array_merge( $this->webhook_ids, $blocked_webhook_ids ) ) );
		remove_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10 );

		$this->assertSame( $inactive_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertSame( 'disabled', wc_get_webhook( $inactive_id )->get_status() );
		$this->assertSame( array( $inactive_id ), $blocked_webhook_ids );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] ?? null );

		$sync->reconcile_webhook();

		$final_id          = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$final_webhook     = wc_get_webhook( $final_id );
		$final_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $final_webhook_ids ) ) );
		$this->assertNotSame( $inactive_id, $final_id );
		$this->assertNull( wc_get_webhook( $inactive_id ) );
		$this->assertSame( array( $final_id ), $final_webhook_ids );
		$this->assertInstanceOf( WC_Webhook::class, $final_webhook );
		$this->assertSame( 'active', $final_webhook->get_status() );
		$this->assertSame( 'order.status_changed', $final_webhook->get_topic() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification', $final_webhook->get_delivery_url() );
		$this->assertSame( 'wp_api_v3', $final_webhook->get_api_version() );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $same_url_webhook->get_id() ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $unrelated_webhook->get_id() ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox Disabling WooPay during webhook persistence leaves no owned row and re-enabling creates one.
	 */
	public function test_disable_during_webhook_save_cleans_published_ownership(): void {
		$sync = new Task25DisableDuringSaveWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();
		$sync->after_save_callback = function () use ( $sync ): void {
			$this->account_service->woopay_enabled = false;
			$sync->reconcile_webhook();
		};

		$sync->reconcile_webhook();
		$disabled_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$disabled_option_id   = get_option( self::WEBHOOK_ID_OPTION, false );
		$disabled_lock        = get_option( self::WEBHOOK_LOCK_OPTION, false );

		$sync->after_save_callback             = null;
		$this->account_service->woopay_enabled = true;
		$sync->reconcile_webhook();
		$final_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $disabled_webhook_ids, $final_webhook_ids ) ) );

		$this->assertFalse( $disabled_option_id );
		$this->assertFalse( $disabled_lock );
		$this->assertCount( 0, $disabled_webhook_ids );
		$this->assertGreaterThan( 0, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertCount( 1, $final_webhook_ids );
	}

	/**
	 * @testdox A stale lock holder cannot release its successor or admit a third creator.
	 */
	public function test_stale_creation_lock_release_preserves_successor_ownership(): void {
		$sync = new Task25InspectableLockWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();

		$first_token = $sync->acquire_test_creation_lock();
		$this->assertNotNull( $first_token );
		$stale_lock               = get_option( self::WEBHOOK_LOCK_OPTION, array() );
		$stale_lock['created_at'] = time() - MINUTE_IN_SECONDS - 1;
		update_option( self::WEBHOOK_LOCK_OPTION, $stale_lock, false );

		$successor_token = $sync->acquire_test_creation_lock();
		$this->assertNotNull( $successor_token );
		$this->assertNotSame( $first_token, $successor_token );
		$sync->release_test_creation_lock( $first_token );
		$successor_lock_after_release = get_option( self::WEBHOOK_LOCK_OPTION, false );

		$sync->reconcile_webhook();
		$webhook_ids_while_successor_owns_lock = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$sync->release_test_creation_lock( $successor_token );
		$sync->reconcile_webhook();
		$final_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $webhook_ids_while_successor_owns_lock, $final_webhook_ids ) ) );

		$this->assertIsArray( $successor_lock_after_release );
		$this->assertSame( $successor_token, $successor_lock_after_release['token'] ?? null );
		$this->assertCount( 0, $webhook_ids_while_successor_owns_lock );
		$this->assertCount( 1, $final_webhook_ids );
	}

	/**
	 * @testdox Two stale-lock successors leave one current holder and one exact owned webhook.
	 */
	public function test_stale_creation_lock_takeover_fences_two_successors(): void {
		$first_successor  = new Task25CoordinatedStaleLockWooPayOrderStatusSync();
		$second_successor = new Task25InspectableLockWooPayOrderStatusSync();
		$this->create_sync( true, true, $first_successor )->register();
		$this->create_sync( true, true, $second_successor );

		$stale_holder_token = $first_successor->acquire_test_creation_lock();
		$this->assertNotNull( $stale_holder_token );
		$stale_lock               = get_option( self::WEBHOOK_LOCK_OPTION, array() );
		$stale_lock['created_at'] = time() - MINUTE_IN_SECONDS - 1;
		update_option( self::WEBHOOK_LOCK_OPTION, $stale_lock, false );

		$second_successor_token                    = null;
		$first_successor->during_stale_replacement = function () use ( $second_successor, &$second_successor_token ): void {
			$second_successor_token = $second_successor->acquire_test_creation_lock();
		};
		$first_successor_token                     = $first_successor->acquire_test_creation_lock();

		$successor_tokens = array_values( array_filter( array( $first_successor_token, $second_successor_token ) ) );
		$this->assertCount( 1, $successor_tokens, 'Only one successor may return an acquired stale lock.' );

		if ( is_string( $second_successor_token ) ) {
			$second_successor->release_test_creation_lock( $second_successor_token );
		}
		if ( is_string( $first_successor_token ) ) {
			$first_successor->release_test_creation_lock( $first_successor_token );
		}
		$first_successor->reconcile_webhook();

		$webhook_ids       = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $webhook_ids ) ) );
		$owned_id          = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$owned_webhook     = wc_get_webhook( $owned_id );
		$this->assertCount( 1, $webhook_ids );
		$this->assertSame( $webhook_ids[0], $owned_id );
		$this->assertInstanceOf( WC_Webhook::class, $owned_webhook );
		$this->assertSame( 'active', $owned_webhook->get_status() );
		$this->assertSame( 'order.status_changed', $owned_webhook->get_topic() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification', $owned_webhook->get_delivery_url() );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox The valid publisher removes a previously published contender by owned ID.
	 */
	public function test_valid_publication_removes_prior_contender_by_owned_id(): void {
		$sync = new Task25DisableDuringSaveWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();

		$prior_contender_id        = 0;
		$sync->after_save_callback = function () use ( &$prior_contender_id ): void {
			$prior_contender     = $this->create_named_webhook( 'WooPayments woopay order status sync', 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
			$prior_contender_id  = $prior_contender->get_id();
			$this->webhook_ids[] = $prior_contender_id;
			update_option( self::WEBHOOK_ID_OPTION, $prior_contender_id, false );
		};

		$sync->reconcile_webhook();

		$webhook_ids       = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $webhook_ids ) ) );
		$owned_id          = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$this->assertGreaterThan( 0, $prior_contender_id );
		$this->assertNull( wc_get_webhook( $prior_contender_id ) );
		$this->assertCount( 1, $webhook_ids );
		$this->assertSame( $webhook_ids[0], $owned_id );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox Failed prior contender deletion aborts publication and cleans the new unowned row.
	 */
	public function test_prior_contender_delete_failure_preserves_contender_and_aborts_publication(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = new Task25DisableDuringSaveWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();

		$prior_contender_id        = 0;
		$sync->after_save_callback = function () use ( &$prior_contender_id ): void {
			$prior_contender     = $this->create_named_webhook( 'WooPayments woopay order status sync', 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
			$prior_contender_id  = $prior_contender->get_id();
			$this->webhook_ids[] = $prior_contender_id;
			update_option( self::WEBHOOK_ID_OPTION, $prior_contender_id, false );
		};
		$prevent_delete            = static function ( $check, $data_object ) use ( &$prior_contender_id ) {
			return $data_object instanceof WC_Webhook && $prior_contender_id === $data_object->get_id() ? false : $check;
		};
		add_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10, 2 );

		$sync->reconcile_webhook();
		$sync->after_save_callback = null;
		$webhook_ids               = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids         = array_values( array_unique( array_merge( $this->webhook_ids, $webhook_ids ) ) );
		remove_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10 );

		$this->assertGreaterThan( 0, $prior_contender_id );
		$this->assertSame( $prior_contender_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertSame( array( $prior_contender_id ), $webhook_ids );
		$this->assertSame( 'active', wc_get_webhook( $prior_contender_id )->get_status() );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] ?? null );
	}

	/**
	 * @testdox A holder that loses its lock after save cannot publish or remove successor ownership.
	 */
	public function test_lost_lock_holder_does_not_publish_or_delete_successor_lock(): void {
		$sync = new Task25SaveInterleavingWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();
		$successor_token           = wp_generate_uuid4();
		$sync->after_save_callback = static function () use ( $successor_token ): void {
			update_option(
				self::WEBHOOK_LOCK_OPTION,
				array(
					'token'      => $successor_token,
					'created_at' => time(),
				),
				false
			);
		};

		$sync->reconcile_webhook();

		$successor_lock = get_option( self::WEBHOOK_LOCK_OPTION, false );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertCount( 0, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
		$this->assertIsArray( $successor_lock );
		$this->assertSame( $successor_token, $successor_lock['token'] ?? null );

		$sync->after_save_callback = null;
		$sync->release_test_creation_lock( $successor_token );
		$sync->reconcile_webhook();
		$final_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $final_webhook_ids ) ) );
		$this->assertCount( 1, $final_webhook_ids );
		$this->assertSame( $final_webhook_ids[0], absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox A vetoed pre-publication loser cleanup is logged without touching successor ownership.
	 */
	public function test_lost_lock_holder_logs_vetoed_unowned_webhook_cleanup(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = new Task25SaveInterleavingWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();
		$successor_token           = wp_generate_uuid4();
		$sync->after_save_callback = static function () use ( $successor_token ): void {
			update_option(
				self::WEBHOOK_LOCK_OPTION,
				array(
					'token'      => $successor_token,
					'created_at' => time(),
				),
				false
			);
		};
		$prevent_delete            = static function ( $check, $data_object ) {
			return $data_object instanceof WC_Webhook && 'WooPayments woopay order status sync' === $data_object->get_name() ? false : $check;
		};
		add_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10, 2 );

		$sync->reconcile_webhook();
		$unowned_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids   = array_values( array_unique( array_merge( $this->webhook_ids, $unowned_webhook_ids ) ) );
		remove_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10 );

		$successor_lock = get_option( self::WEBHOOK_LOCK_OPTION, false );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertCount( 1, $unowned_webhook_ids );
		$this->assertIsArray( $successor_lock );
		$this->assertSame( $successor_token, $successor_lock['token'] ?? null );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'Unable to clean up an unowned WooPay order-status webhook.', $logger->error_calls[0]['message'] ?? null );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] ?? null );

		$sync->after_save_callback = null;
		$sync->release_test_creation_lock( $successor_token );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox A holder that loses its lock after publication leaves valid ownership for its successor.
	 */
	public function test_post_publication_lock_loss_preserves_valid_owned_row_and_successor_lock(): void {
		$sync = new Task25AfterPublicationWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();
		$successor_token                  = wp_generate_uuid4();
		$sync->after_publication_callback = static function () use ( $successor_token ): void {
			update_option(
				self::WEBHOOK_LOCK_OPTION,
				array(
					'token'      => $successor_token,
					'created_at' => time(),
				),
				false
			);
		};

		$sync->reconcile_webhook();

		$owned_id       = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$owned_webhook  = wc_get_webhook( $owned_id );
		$successor_lock = get_option( self::WEBHOOK_LOCK_OPTION, false );
		$this->assertGreaterThan( 0, $owned_id );
		$this->assertInstanceOf( WC_Webhook::class, $owned_webhook );
		$this->assertSame( 'active', $owned_webhook->get_status() );
		$this->assertIsArray( $successor_lock );
		$this->assertSame( $successor_token, $successor_lock['token'] ?? null );

		$sync->release_test_creation_lock( $successor_token );
		$sync->reconcile_webhook();
		$final_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $final_webhook_ids ) ) );
		$this->assertCount( 1, $final_webhook_ids );
		$this->assertSame( $owned_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox Rapid disable and re-enable after publication finishes with one exact owned webhook.
	 */
	public function test_post_publication_disable_reenable_retries_missing_ownership(): void {
		$sync = new Task25AfterPublicationWooPayOrderStatusSync();
		$this->create_sync( true, true, $sync )->register();
		$account_service = $this->account_service;
		$this->assertInstanceOf( Task25WooPayAccountService::class, $account_service );
		$unrelated_webhook                = $this->create_named_webhook( 'Unrelated webhook', 'https://example.com/webhook' );
		$this->webhook_ids[]              = $unrelated_webhook->get_id();
		$sync->after_publication_callback = function () use ( $sync, $account_service ): void {
			$account_service->woopay_enabled = false;
			$sync->reconcile_webhook();
			$account_service->woopay_enabled = true;
			$sync->reconcile_webhook();
		};

		$sync->reconcile_webhook();

		$webhook_ids       = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		$this->webhook_ids = array_values( array_unique( array_merge( $this->webhook_ids, $webhook_ids ) ) );
		$owned_id          = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$owned_webhook     = wc_get_webhook( $owned_id );
		$this->assertCount( 1, $webhook_ids );
		$this->assertSame( $webhook_ids[0], $owned_id );
		$this->assertInstanceOf( WC_Webhook::class, $owned_webhook );
		$this->assertSame( 'active', $owned_webhook->get_status() );
		$this->assertSame( 'order.status_changed', $owned_webhook->get_topic() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification', $owned_webhook->get_delivery_url() );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $unrelated_webhook->get_id() ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
	}

	/**
	 * @testdox Disabling WooPay deletes only the stored native webhook ID.
	 */
	public function test_disabled_reconciliation_deletes_only_owned_webhook(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$same_url_webhook                      = $this->create_named_webhook( 'Same URL but unrelated', 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
		$different_url_webhook                 = $this->create_named_webhook( 'Unrelated webhook', 'https://example.com/webhook' );
		$this->webhook_ids[]                   = $same_url_webhook->get_id();
		$this->webhook_ids[]                   = $different_url_webhook->get_id();
		$this->account_service->woopay_enabled = false;

		$sync->reconcile_webhook();

		$this->assertNull( wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $same_url_webhook->get_id() ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $different_url_webhook->get_id() ) );
	}

	/**
	 * @testdox Failed disabled ownership deletion retains the row and option for retry.
	 */
	public function test_disabled_owned_webhook_delete_failure_preserves_ownership_until_retry(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();

		$owned_id                              = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$this->webhook_ids[]                   = $owned_id;
		$this->account_service->woopay_enabled = false;
		$prevent_delete                        = static function ( $check, $data_object ) use ( $owned_id ) {
			return $data_object instanceof WC_Webhook && $owned_id === $data_object->get_id() ? false : $check;
		};
		add_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10, 2 );

		$sync->reconcile_webhook();
		remove_filter( 'woocommerce_pre_delete_data', $prevent_delete, 10 );

		$this->assertSame( $owned_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] ?? null );

		$sync->reconcile_webhook();

		$this->assertNull( wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertCount( 1, $logger->error_calls );
	}

	/**
	 * @testdox Missing and stale owned webhook IDs are safe when WooPay is disabled.
	 */
	public function test_disabled_reconciliation_cleans_missing_and_stale_ids_safely(): void {
		$sync = $this->create_sync( true, false );
		$sync->register();

		$sync->reconcile_webhook();
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );

		update_option( self::WEBHOOK_ID_OPTION, 999999, false );
		$sync->reconcile_webhook();

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
	}

	/**
	 * @testdox Enabled reconciliation recovers from a stale stored webhook ID.
	 */
	public function test_enabled_reconciliation_recovers_from_stale_id(): void {
		update_option( self::WEBHOOK_ID_OPTION, 999999, false );
		$sync = $this->create_sync( true, true );
		$sync->register();

		$sync->reconcile_webhook();

		$webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		$this->assertGreaterThan( 0, $webhook_id );
		$this->assertNotSame( 999999, $webhook_id );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $webhook_id ) );
	}

	/**
	 * @testdox Plugin-owned runtime never reconciles WooPay webhook rows.
	 */
	public function test_plugin_owned_runtime_does_not_mutate_webhooks(): void {
		$sync = $this->create_sync( false, true );
		$sync->register();

		$sync->reconcile_webhook();
		$sync->reconcile_webhook();

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertCount( 0, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
	}

	/**
	 * @testdox Webhook creation requires a WooCommerce-managing administrator.
	 */
	public function test_enabled_reconciliation_does_not_create_without_capability(): void {
		wp_set_current_user( 0 );
		$sync = $this->create_sync( true, true );
		$sync->register();

		$sync->reconcile_webhook();

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
	}

	/**
	 * @testdox Restricted account status $status prevents native WooPay webhook creation.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_restricted_account_does_not_create_native_webhook( string $status ): void {
		$sync = $this->create_sync( true, true, null, $status );
		$sync->register();

		$sync->reconcile_webhook();

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertCount( 0, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
	}

	/**
	 * @testdox Restricted account status $status removes the existing native-owned WooPay webhook.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_restricted_account_removes_native_owned_webhook( string $status ): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$this->account_service->account_status = $status;
		$sync->reconcile_webhook();

		$this->assertGreaterThan( 0, $owned_id );
		$this->assertNull( wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
	}

	/**
	 * @testdox Plugin ownership leaves the existing native webhook untouched for restricted status $status.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_plugin_owned_restricted_account_does_not_mutate_native_webhook( string $status ): void {
		$native_sync = $this->create_sync( true, true );
		$native_sync->register();
		$native_sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$plugin_sync = $this->create_sync( false, true, null, $status );
		$plugin_sync->register();
		$plugin_sync->reconcile_webhook();

		$this->assertGreaterThan( 0, $owned_id );
		$this->assertSame( $owned_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $owned_id ) );
	}

	/**
	 * @testdox Invalid account data prevents native WooPay webhook creation.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_invalid_account_does_not_create_native_webhook( array $account_data ): void {
		$sync = $this->create_sync( true, true, null, '', $account_data );
		$sync->register();

		$sync->reconcile_webhook();

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertCount( 0, $this->find_webhooks_by_name( 'WooPayments woopay order status sync' ) );
	}

	/**
	 * @testdox Invalid account data removes the existing native-owned WooPay webhook.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_invalid_account_removes_native_owned_webhook( array $account_data ): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$this->account_service->account_data_overrides = $account_data;
		$sync->reconcile_webhook();

		$this->assertGreaterThan( 0, $owned_id );
		$this->assertNull( wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
	}

	/**
	 * @testdox Plugin ownership leaves the native webhook untouched for invalid account data.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_plugin_owned_invalid_account_does_not_mutate_native_webhook( array $account_data ): void {
		$native_sync = $this->create_sync( true, true );
		$native_sync->register();
		$native_sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$plugin_sync = $this->create_sync( false, true, null, '', $account_data );
		$plugin_sync->register();
		$plugin_sync->reconcile_webhook();

		$this->assertGreaterThan( 0, $owned_id );
		$this->assertSame( $owned_id, absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $owned_id ) );
	}

	/**
	 * @testdox Disabled reconciliation removes the owned webhook without requiring a current administrator.
	 */
	public function test_disabled_reconciliation_removes_owned_webhook_without_capability(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$sync->reconcile_webhook();
		$owned_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );

		$this->account_service->woopay_enabled = false;
		wp_set_current_user( 0 );
		$sync->reconcile_webhook();

		$this->assertGreaterThan( 0, $owned_id );
		$this->assertNull( wc_get_webhook( $owned_id ) );
		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
	}

	/**
	 * @testdox Webhook save failures are contained, logged, and leave no ownership residue.
	 * @dataProvider webhook_save_failure_modes
	 *
	 * @param string $failure_mode Synthetic save failure mode.
	 */
	public function test_webhook_save_failure_is_safe_and_logged( string $failure_mode ): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = new Task25FailingWooPayOrderStatusSync( $failure_mode );
		$this->create_sync( true, true, $sync )->register();

		$sync->reconcile_webhook();
		$remaining_webhook_ids = $this->find_webhooks_by_name( 'WooPayments woopay order status sync' );
		array_push( $this->webhook_ids, ...$remaining_webhook_ids );

		$this->assertFalse( get_option( self::WEBHOOK_ID_OPTION, false ) );
		$this->assertFalse( get_option( self::WEBHOOK_LOCK_OPTION, false ) );
		$this->assertCount( 0, $remaining_webhook_ids );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] );
	}

	/**
	 * Webhook save failure modes.
	 *
	 * @return array<string,array{string}>
	 */
	public function webhook_save_failure_modes(): array {
		return array(
			'zero ID'                      => array( 'zero' ),
			'throwable before persistence' => array( 'throw_before' ),
			'throwable after persistence'  => array( 'throw_after' ),
		);
	}

	/**
	 * Restricted account statuses.
	 *
	 * @return array<string,array{string}>
	 */
	public function restricted_account_statuses(): array {
		return array(
			'under review' => array( 'under_review' ),
			'rejected'     => array( 'rejected.fraud' ),
		);
	}

	/**
	 * Invalid WooPay accounts.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function invalid_woopay_accounts(): array {
		return array(
			'missing account'           => array( array( 'account_id' => '' ) ),
			'details not submitted'     => array( array( 'details_submitted' => false ) ),
			'missing card payments'     => array( array( 'capabilities' => array() ) ),
			'card payments unrequested' => array( array( 'capabilities' => array( 'card_payments' => 'unrequested' ) ) ),
		);
	}

	/**
	 * @testdox A plugin-created WooPay order-status webhook row resolves under native topic filters.
	 */
	public function test_plugin_created_webhook_row_resolves_under_native_filters(): void {
		$this->assertFalse( wc_is_webhook_valid_topic( 'order.status_changed' ) );

		$this->with_plugin_topic_filters(
			function (): void {
				$webhook             = $this->create_plugin_created_webhook( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
				$this->webhook_ids[] = $webhook->get_id();
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
			WC_Webhook::get_default_topic_hooks()['order.status_changed']
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

		$woopay_webhook            = $this->create_plugin_created_webhook( 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification' );
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
	 * @param bool                                  $native_register Whether native should register.
	 * @param bool                                  $woopay_enabled Whether native WooPay is enabled.
	 * @param WooPaymentsWooPayOrderStatusSync|null $sync           Optional sync test double.
	 * @param string                                $account_status Restricted account status.
	 * @param array<string,mixed>                   $account_data   Account data overrides.
	 * @return WooPaymentsWooPayOrderStatusSync
	 */
	private function create_sync( bool $native_register, bool $woopay_enabled = true, ?WooPaymentsWooPayOrderStatusSync $sync = null, string $account_status = '', array $account_data = array() ): WooPaymentsWooPayOrderStatusSync {
		$this->assertTrue( class_exists( WooPaymentsWooPayOrderStatusSync::class ), 'WooPaymentsWooPayOrderStatusSync should exist.' );

		$this->account_service                         = new Task25WooPayAccountService();
		$this->account_service->woopay_enabled         = $woopay_enabled;
		$this->account_service->account_status         = $account_status;
		$this->account_service->account_data_overrides = $account_data;
		$this->session_service                         = new Task25WooPaySessionService();
		$this->session_service->init( $this->account_service, new WooPaymentsFrontendStylesService(), new WooPaymentsFrontendTrackingController() );
		$this->api_client = new Task25WooPayApiClient();
		$sync             = $sync ?? new WooPaymentsWooPayOrderStatusSync();
		$sync->init( new StaticNativeRuntimeArbiter( $native_register ), $this->session_service, $this->api_client );

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
		return $this->create_named_webhook( 'WooPayments woopay order status sync', $delivery_url );
	}

	/**
	 * Create a named webhook row.
	 *
	 * @param string $name         Webhook name.
	 * @param string $delivery_url Delivery URL.
	 * @return WC_Webhook
	 */
	private function create_named_webhook( string $name, string $delivery_url ): WC_Webhook {
		$webhook = new WC_Webhook();
		$webhook->set_name( $name );
		$webhook->set_user_id( get_current_user_id() );
		$webhook->set_topic( 'order.status_changed' );
		$webhook->set_secret( 'test-secret' );
		$webhook->set_delivery_url( $delivery_url );
		$webhook->set_status( 'active' );
		$webhook->save();

		return $webhook;
	}

	/**
	 * Find webhook IDs by name through the WooCommerce webhook data store.
	 *
	 * @param string $name Webhook name.
	 * @return int[]
	 */
	private function find_webhooks_by_name( string $name ): array {
		$data_store = \WC_Data_Store::load( 'webhook' );

		return array_map(
			'absint',
			$data_store->search_webhooks(
				array(
					'search' => $name,
					'limit'  => -1,
				)
			)
		);
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
		remove_action( 'admin_init', array( $sync, 'reconcile_webhook' ) );
		remove_action( 'wcpay_store_setup_sync', array( $sync, 'reconcile_webhook' ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing

/**
 * Mutable WooPay session service for webhook lifecycle tests.
 */
class Task25WooPaySessionService extends WooPaymentsWooPaySessionService {
	public function get_woopay_rest_url( string $endpoint ): string {
		return 'https://pay.woo.com/wp-json/platform-checkout/v1/' . ltrim( $endpoint, '/' );
	}
}

/**
 * Recording WooPay API client for webhook lifecycle tests.
 */
class Task25WooPayApiClient extends WooPaymentsApiClient {
	/**
	 * Recorded update_woopay payloads.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $woopay_updates = array();

	/**
	 * Exception to throw from update_woopay, when configured.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $update_woopay_exception = null;

	public function update_woopay( array $data ): array {
		$this->woopay_updates[] = $data;

		if ( $this->update_woopay_exception instanceof WooPaymentsApiException ) {
			throw $this->update_woopay_exception;
		}

		return array( 'result' => 'success' );
	}
}

/**
 * Mutable WooPayments account service for webhook lifecycle tests.
 */
class Task25WooPayAccountService extends WooPaymentsAccountService {
	/**
	 * Whether WooPay is enabled in gateway settings.
	 *
	 * @var bool
	 */
	public bool $woopay_enabled = true;

	/**
	 * Cached account status.
	 *
	 * @var string
	 */
	public string $account_status = '';

	/**
	 * Account data overriding the valid fixture defaults.
	 *
	 * @var array<string,mixed>
	 */
	public array $account_data_overrides = array();

	public function get_gateway_setting( string $key, $fallback = null ) {
		return 'platform_checkout' === $key ? ( $this->woopay_enabled ? 'yes' : 'no' ) : $fallback;
	}

	public function get_cached_account_data( bool $force_refresh = false ): array {
		unset( $force_refresh );

		return array_merge(
			array(
				'account_id'                 => 'acct_task_25',
				'details_submitted'          => true,
				'capabilities'               => array( 'card_payments' => 'active' ),
				'platform_checkout_eligible' => true,
				'status'                     => $this->account_status,
			),
			$this->account_data_overrides
		);
	}
}

/**
 * WooPay sync whose webhook persistence fails for test coverage.
 */
class Task25FailingWooPayOrderStatusSync extends WooPaymentsWooPayOrderStatusSync {
	/**
	 * Synthetic save failure mode.
	 *
	 * @var string
	 */
	private string $failure_mode;

	public function __construct( string $failure_mode ) {
		$this->failure_mode = $failure_mode;
	}

	protected function save_webhook( WC_Webhook $webhook ): int {
		if ( 'throw_before' === $this->failure_mode ) {
			throw new \RuntimeException( 'Synthetic webhook save failure.' );
		}
		if ( 'throw_after' === $this->failure_mode ) {
			parent::save_webhook( $webhook );
			throw new \RuntimeException( 'Synthetic webhook post-save failure.' );
		}

		return 0;
	}
}

/**
 * WooPay sync that coordinates an account-state change during persistence.
 */
class Task25DisableDuringSaveWooPayOrderStatusSync extends WooPaymentsWooPayOrderStatusSync {
	/**
	 * Callback invoked after the webhook row is persisted but before save returns.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $after_save_callback = null;

	protected function save_webhook( WC_Webhook $webhook ): int {
		$webhook_id = parent::save_webhook( $webhook );
		if ( $this->after_save_callback instanceof \Closure ) {
			( $this->after_save_callback )();
		}

		return $webhook_id;
	}
}

/**
 * WooPay sync exposing creation-lock ownership transitions for concurrency tests.
 */
class Task25InspectableLockWooPayOrderStatusSync extends WooPaymentsWooPayOrderStatusSync {
	public function acquire_test_creation_lock(): ?string {
		return parent::acquire_creation_lock();
	}

	public function release_test_creation_lock( string $token ): void {
		parent::release_creation_lock( $token );
	}
}

/**
 * WooPay sync coordinating two successors that observed the same stale lock.
 */
class Task25CoordinatedStaleLockWooPayOrderStatusSync extends Task25InspectableLockWooPayOrderStatusSync {
	/**
	 * Callback invoked after this request observes a stale lock and before replacement.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $during_stale_replacement = null;

	protected function replace_stale_creation_lock( array $observed_lock, array $replacement ): bool {
		if ( $this->during_stale_replacement instanceof \Closure ) {
			$callback                       = $this->during_stale_replacement;
			$this->during_stale_replacement = null;
			$callback();
		}

		return parent::replace_stale_creation_lock( $observed_lock, $replacement );
	}
}

/**
 * WooPay sync coordinating a lock ownership change immediately after persistence.
 */
class Task25SaveInterleavingWooPayOrderStatusSync extends WooPaymentsWooPayOrderStatusSync {
	/**
	 * Callback invoked after the webhook row is persisted but before save returns.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $after_save_callback = null;

	protected function save_webhook( WC_Webhook $webhook ): int {
		$webhook_id = parent::save_webhook( $webhook );
		if ( $this->after_save_callback instanceof \Closure ) {
			( $this->after_save_callback )();
		}

		return $webhook_id;
	}

	public function release_test_creation_lock( string $token ): void {
		parent::release_creation_lock( $token );
	}
}

/**
 * WooPay sync coordinating state transitions immediately after ownership publication.
 */
class Task25AfterPublicationWooPayOrderStatusSync extends WooPaymentsWooPayOrderStatusSync {
	/**
	 * One-shot callback invoked after ownership publication.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $after_publication_callback = null;

	protected function publish_webhook_ownership( int $webhook_id ): bool {
		$published = parent::publish_webhook_ownership( $webhook_id );
		if ( $published && $this->after_publication_callback instanceof \Closure ) {
			$callback                         = $this->after_publication_callback;
			$this->after_publication_callback = null;
			$callback();
		}

		return $published;
	}

	public function release_test_creation_lock( string $token ): void {
		parent::release_creation_lock( $token );
	}
}

/**
 * Recording logger for webhook lifecycle failures.
 */
class Task25RecordingLogger implements \WC_Logger_Interface {
	/** @var array<int,array{message:mixed,context:array<string,mixed>}> */
	public array $error_calls = array();

	public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
		unset( $handle, $message, $level );
		return true;
	}

	public function log( $level, $message, $context = array() ) {
		unset( $level, $message, $context );
	}

	public function emergency( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function alert( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function critical( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function notice( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function debug( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function info( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function warning( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function error( $message, $context = array() ) {
		$this->error_calls[] = array(
			'message' => $message,
			'context' => $context,
		);
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing
