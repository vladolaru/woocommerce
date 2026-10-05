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
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Helper_Order;
use WC_Unit_Test_Case;
use WC_Webhook;

/**
 * Tests for the WooPaymentsWooPayOrderStatusSync class.
 */
class WooPaymentsWooPayOrderStatusSyncTest extends WC_Unit_Test_Case {

	private const WEBHOOK_NAME       = 'WooPayments woopay order status sync';
	private const WOOPAY_URL         = 'https://pay.woo.com/wp-json/platform-checkout/v1/merchant-notification';
	private const CLAIM_OPTION       = '_transient_woocommerce_woopayments_woopay_webhook_claim';
	private const CLAIM_EXPIRY       = '_transient_timeout_woocommerce_woopayments_woopay_webhook_claim';
	private const SETTINGS_OPTION    = 'woocommerce_woocommerce_payments_settings';
	private const PLUGIN_SECRET      = 'plugin-secret';
	private const TRANSLATED_NAME    = 'Synchronisation du statut des commandes WooPay';
	private const PLUGIN_TEXT_DOMAIN = 'woocommerce-payments';

	/**
	 * Created sync instances whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsWooPayOrderStatusSync[]
	 */
	private array $syncs = array();

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
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->syncs as $sync ) {
			$this->remove_sync_hooks( $sync );
		}

		remove_all_actions( 'wcpay_webhook_platform_checkout_order_status_changed' );
		remove_all_filters( 'woocommerce_logging_class' );
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
		$this->assertSame( 10, has_action( 'admin_init', array( $native_sync, 'maybe_create_woopay_order_webhook' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $native_sync, 'reconcile_webhook' ) ) );
		$this->assertSame( 10, has_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $native_sync, 'handle_settings_update' ) ) );
		$this->assertFalse( has_action( 'wcpay_store_setup_sync', array( $native_sync, 'reconcile_webhook' ) ), 'Client 11.1.0 does not touch the webhook on the store setup sync.' );

		$plugin_sync = $this->create_sync( false );
		$plugin_sync->register();

		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_resources', array( $plugin_sync, 'add_resource' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_valid_webhook_events', array( $plugin_sync, 'add_event' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_topic_hooks', array( $plugin_sync, 'add_topics' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_webhook_payload', array( $plugin_sync, 'create_payload' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_changed', array( $plugin_sync, 'send_webhook' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $plugin_sync, 'maybe_create_woopay_order_webhook' ) ) );
		$this->assertFalse( has_action( 'woocommerce_payments_account_refreshed', array( $plugin_sync, 'reconcile_webhook' ) ) );
		$this->assertFalse( has_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $plugin_sync, 'handle_settings_update' ) ) );
	}

	/**
	 * @testdox Enabled native WooPay creates the exact WooCommerce webhook row and registers its secret.
	 */
	public function test_enabled_native_woopay_creates_exact_webhook(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();

		$this->run_admin_init_for( $sync );

		$webhook_ids = $this->find_woopay_webhook_ids();
		$this->assertCount( 1, $webhook_ids );
		$webhook = wc_get_webhook( $webhook_ids[0] );
		$this->assertInstanceOf( WC_Webhook::class, $webhook );
		$this->assertSame( self::WEBHOOK_NAME, $webhook->get_name() );
		$this->assertSame( get_current_user_id(), $webhook->get_user_id() );
		$this->assertSame( 'order.status_changed', $webhook->get_topic() );
		$this->assertSame( self::WOOPAY_URL, $webhook->get_delivery_url() );
		$this->assertSame( 'active', $webhook->get_status() );
		$this->assertSame( 'wp_api_v3', $webhook->get_api_version() );
		$this->assertSame( 50, strlen( $webhook->get_secret() ) );
		$this->assertSame( array( array( 'webhook_secret' => $webhook->get_secret() ) ), $this->api_client->woopay_updates, 'The new webhook secret must be registered with the platform.' );
		$this->assertNull( $this->read_option_row( self::CLAIM_OPTION ), 'The creation lock must be released.' );
		$this->assertNull( $this->read_option_row( self::CLAIM_EXPIRY ) );
	}

	/**
	 * @testdox A store switching from the plugin keeps the plugin's webhook: one delivery per status change, the plugin's secret, no platform call.
	 *
	 * Fails when the webhook is identified by anything the plugin's row lacks, such as an option only native writes: native then
	 * creates a second row, rotates WooPay's secret and every status change is delivered twice.
	 */
	public function test_switch_with_woopay_on_adopts_the_plugin_webhook(): void {
		$plugin_webhook = $this->create_plugin_webhook();
		$sync           = $this->create_sync( true, true );
		$sync->register();

		$this->run_admin_init_for( $sync );

		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( 'is_woopay', true );
		$order->save();
		$deliveries = $this->count_deliveries_of_a_status_change( $order );

		$this->assertSame( array( $plugin_webhook->get_id() ), $deliveries, 'Exactly one webhook, the plugin\'s, must deliver the status change.' );
		$this->assertSame( self::PLUGIN_SECRET, wc_get_webhook( $plugin_webhook->get_id() )->get_secret(), 'WooPay already holds the plugin row\'s secret.' );
		$this->assertSame( array(), $this->api_client->woopay_updates, 'Adopting the plugin row must not call the platform.' );
	}

	/**
	 * @testdox Turning WooPay off removes every WooPay webhook, the plugin's included, and leaves other webhooks alone.
	 *
	 * Fails when removal is limited to rows native created: the plugin's row keeps delivering to WooPay.
	 */
	public function test_turning_woopay_off_removes_the_plugin_webhook(): void {
		$plugin_webhook   = $this->create_plugin_webhook();
		$other_url        = $this->create_webhook_row( self::WEBHOOK_NAME, 'https://example.com/webhook', 'order.status_changed', 'active' );
		$other_topic      = $this->create_webhook_row( 'WooPay order updates', self::WOOPAY_URL, 'order.updated', 'active' );
		$disabled_webhook = $this->create_webhook_row( self::WEBHOOK_NAME, self::WOOPAY_URL, 'order.status_changed', 'disabled' );
		$sync             = $this->create_sync( true, true );
		$sync->register();
		update_option( self::SETTINGS_OPTION, array( 'platform_checkout' => 'yes' ) );
		wp_set_current_user( 0 );

		update_option( self::SETTINGS_OPTION, array( 'platform_checkout' => 'no' ) );

		$this->assertNull( wc_get_webhook( $plugin_webhook->get_id() ) );
		$this->assertSame( array(), $this->find_woopay_webhook_ids() );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $other_url->get_id() ), 'A webhook to another URL is not WooPay\'s.' );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $other_topic->get_id() ), 'A webhook on another topic is not WooPay\'s.' );
		$this->assertInstanceOf( WC_Webhook::class, wc_get_webhook( $disabled_webhook->get_id() ), 'Inactive rows are left alone, as in client 11.1.0.' );
	}

	/**
	 * @testdox Several WooPay webhooks are replaced by one new webhook whose secret is registered once.
	 *
	 * Fails when the fast path accepts any number of rows, or when several rows are left in place: WooPay holds at most one of
	 * their secrets, so the others' deliveries fail verification.
	 */
	public function test_several_woopay_webhooks_collapse_to_one_known_pair(): void {
		$first  = $this->create_plugin_webhook();
		$second = $this->create_plugin_webhook();
		$sync   = $this->create_sync( true, true );
		$sync->register();

		$this->run_admin_init_for( $sync );

		$webhook_ids = $this->find_woopay_webhook_ids();
		$this->assertCount( 1, $webhook_ids );
		$this->assertNotContains( $first->get_id(), $webhook_ids );
		$this->assertNotContains( $second->get_id(), $webhook_ids );
		$this->assertSame( array( array( 'webhook_secret' => wc_get_webhook( $webhook_ids[0] )->get_secret() ) ), $this->api_client->woopay_updates );
	}

	/**
	 * @testdox A creation lock another request holds stops this request from creating a webhook, even when this request cached the lock as missing.
	 *
	 * Fails when the lock goes back to add_option() or update_option(): both trust the request's notoptions cache and upsert, so
	 * two requests both create a row and the second rotates WooPay's secret.
	 */
	public function test_held_creation_lock_blocks_creation(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		// This request reads the lock as missing, which WordPress records in its notoptions cache.
		$this->assertFalse( get_option( self::CLAIM_OPTION ) );
		// Another request then takes the lock.
		$this->insert_claim_rows( 'other-request', time() + 300 );

		$this->run_admin_init_for( $sync );

		$this->assertSame( array(), $this->find_woopay_webhook_ids() );
		$this->assertSame( array(), $this->api_client->woopay_updates );
		$this->assertSame( 'other-request', $this->read_option_row( self::CLAIM_OPTION ), 'The other request must keep its lock.' );
	}

	/**
	 * @testdox An expired creation lock left by a stopped request is taken over, and released after creation.
	 *
	 * Fails when the takeover goes: a request that died holding the lock would block the webhook forever. Fails when the release
	 * goes: every admin page would then wait for the lock to expire.
	 */
	public function test_expired_creation_lock_is_taken_over_and_released(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$this->insert_claim_rows( 'stopped-request', time() - 10 );

		$this->run_admin_init_for( $sync );

		$this->assertCount( 1, $this->find_woopay_webhook_ids() );
		$this->assertCount( 1, $this->api_client->woopay_updates );
		$this->assertNull( $this->read_option_row( self::CLAIM_OPTION ) );
		$this->assertNull( $this->read_option_row( self::CLAIM_EXPIRY ) );
	}

	/**
	 * @testdox A reactivated plugin finds native's webhook with its own translated name lookup.
	 *
	 * Fails when the name or its text domain changes: the plugin then misses native's row, creates a second one and rotates
	 * WooPay's secret.
	 */
	public function test_reactivated_plugin_finds_native_webhook_in_its_locale(): void {
		$translate = static function ( $translation, $text, $domain ) {
			return self::PLUGIN_TEXT_DOMAIN === $domain && self::WEBHOOK_NAME === $text ? self::TRANSLATED_NAME : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		$sync = $this->create_sync( true, true );
		$sync->register();
		$this->run_admin_init_for( $sync );

		// The plugin's own lookup, client 11.1.0 WooPay_Order_Status_Sync::get_webhook().
		$plugin_lookup = \WC_Data_Store::load( 'webhook' )->search_webhooks(
			array(
				'search' => __( 'WooPayments woopay order status sync', 'woocommerce-payments' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The plugin's lookup, in its text domain.
				'status' => 'active',
				'limit'  => 1,
			)
		);
		remove_filter( 'gettext', $translate, 10 );

		$this->assertSame( $this->find_woopay_webhook_ids(), array_map( 'intval', $plugin_lookup ) );
		$this->assertCount( 1, $plugin_lookup );
	}

	/**
	 * @testdox A failed platform secret registration deletes the new row and releases the lock, so the next admin page creates the pair.
	 *
	 * Fails when the rollback goes (WooPay would not hold the secret of an active row) or when the lock is not released on failure.
	 */
	public function test_failed_secret_registration_rolls_back_and_the_next_admin_page_retries(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = $this->create_sync( true, true );
		$sync->register();
		// The platform answers an update it cannot apply with Bad_Request_Exception, code wcpay_bad_request, status 400.
		$this->api_client->update_woopay_exception = new WooPaymentsApiException( 'Error updating account. Webhook secret is empty.', 'wcpay_bad_request', 400 );

		$this->run_admin_init_for( $sync );

		$this->assertCount( 1, $this->api_client->woopay_updates );
		$this->assertSame( array(), $this->find_woopay_webhook_ids(), 'A webhook whose secret WooPay does not hold must not stay active.' );
		$this->assertNull( $this->read_option_row( self::CLAIM_OPTION ), 'The lock must be released after a failure.' );
		$this->assertSame( array( 'Unable to register the WooPay order-status webhook secret with the platform.' ), array_column( $logger->error_calls, 'message' ) );

		$this->api_client->update_woopay_exception = null;
		$this->run_admin_init_for( $sync );

		$webhook_ids = $this->find_woopay_webhook_ids();
		$this->assertCount( 1, $webhook_ids );
		$this->assertCount( 2, $this->api_client->woopay_updates );
		$this->assertSame( array( 'webhook_secret' => wc_get_webhook( $webhook_ids[0] )->get_secret() ), $this->api_client->woopay_updates[1] );
	}

	/**
	 * @testdox With its webhook in place, an admin page runs no write and no platform call.
	 */
	public function test_admin_page_with_the_webhook_in_place_writes_nothing(): void {
		$sync = $this->create_sync( true, true );
		$sync->register();
		$this->run_admin_init_for( $sync );
		$webhook_ids = $this->find_woopay_webhook_ids();
		$writes      = array();
		$record      = static function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );

		$this->run_admin_init_for( $sync );
		remove_filter( 'query', $record );

		$this->assertSame( array(), $writes );
		$this->assertSame( $webhook_ids, $this->find_woopay_webhook_ids() );
		$this->assertCount( 1, $this->api_client->woopay_updates, 'The secret is registered only when a webhook is created.' );
	}

	/**
	 * @testdox Admin pages run no query while WooPay is off, like client 11.1.0, which registers nothing then.
	 */
	public function test_admin_init_runs_no_query_while_woopay_is_off(): void {
		global $wpdb;
		$sync = $this->create_sync( true, false );
		$sync->register();
		$queries_before = $wpdb->num_queries;

		$this->run_admin_init_for( $sync );

		$this->assertSame( 0, $wpdb->num_queries - $queries_before );
	}

	/**
	 * @testdox A gateway settings save that keeps WooPay on keeps the webhook, whatever else it changes.
	 */
	public function test_settings_save_that_keeps_woopay_on_keeps_the_webhook(): void {
		$plugin_webhook = $this->create_plugin_webhook();
		$sync           = $this->create_sync( true, true );
		$sync->register();
		update_option(
			self::SETTINGS_OPTION,
			array(
				'platform_checkout' => 'yes',
				'test_mode'         => 'no',
			)
		);

		update_option(
			self::SETTINGS_OPTION,
			array(
				'platform_checkout' => 'yes',
				'test_mode'         => 'yes',
			)
		);

		$this->assertSame( array( $plugin_webhook->get_id() ), $this->find_woopay_webhook_ids() );
	}

	/**
	 * @testdox An account refresh that leaves WooPay unavailable removes every WooPay webhook, without a current administrator.
	 */
	public function test_account_refresh_removes_the_webhooks_when_woopay_is_unavailable(): void {
		$this->create_plugin_webhook();
		$sync = $this->create_sync( true, true );
		$sync->register();
		$this->run_admin_init_for( $sync );
		$this->account_service->woopay_enabled = false;
		wp_set_current_user( 0 );

		do_action( 'woocommerce_payments_account_refreshed', array() );

		$this->assertSame( array(), $this->find_woopay_webhook_ids() );
	}

	/**
	 * @testdox Plugin-owned runtime never touches WooPay webhook rows.
	 */
	public function test_plugin_owned_runtime_does_not_touch_webhooks(): void {
		$plugin_webhook = $this->create_plugin_webhook();
		$sync           = $this->create_sync( false, false );
		$sync->register();

		$sync->maybe_create_woopay_order_webhook();
		$sync->reconcile_webhook();
		$sync->handle_settings_update( array( 'platform_checkout' => 'yes' ), array( 'platform_checkout' => 'no' ) );

		$this->assertSame( array( $plugin_webhook->get_id() ), $this->find_woopay_webhook_ids() );
		$this->assertSame( array(), $this->api_client->woopay_updates );
	}

	/**
	 * @testdox Webhook creation requires a WooCommerce-managing administrator.
	 */
	public function test_creation_requires_a_woocommerce_manager(): void {
		wp_set_current_user( 0 );
		$sync = $this->create_sync( true, true );
		$sync->register();

		$this->run_admin_init_for( $sync );

		$this->assertSame( array(), $this->find_woopay_webhook_ids() );
	}

	/**
	 * @testdox Restricted account status $status prevents creation and removes the existing webhook.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_restricted_account_prevents_creation_and_removes_the_webhook( string $status ): void {
		$sync = $this->create_sync( true, true, $status );
		$sync->register();
		$this->run_admin_init_for( $sync );
		$this->assertSame( array(), $this->find_woopay_webhook_ids() );

		$plugin_webhook = $this->create_plugin_webhook();
		do_action( 'woocommerce_payments_account_refreshed', array() );

		$this->assertNull( wc_get_webhook( $plugin_webhook->get_id() ) );
	}

	/**
	 * @testdox Invalid account data prevents creation and removes the existing webhook.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_invalid_account_prevents_creation_and_removes_the_webhook( array $account_data ): void {
		$sync = $this->create_sync( true, true, '', $account_data );
		$sync->register();
		$this->run_admin_init_for( $sync );
		$this->assertSame( array(), $this->find_woopay_webhook_ids() );

		$plugin_webhook = $this->create_plugin_webhook();
		do_action( 'woocommerce_payments_account_refreshed', array() );

		$this->assertNull( wc_get_webhook( $plugin_webhook->get_id() ) );
	}

	/**
	 * @testdox A failed webhook save is logged, leaves no row, makes no platform call and releases the lock.
	 */
	public function test_failed_webhook_save_is_logged(): void {
		$logger = new Task25RecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$sync = $this->create_sync( true, true );
		$sync->register();
		$fail_insert = static function ( $query ) {
			return false !== stripos( $query, 'INSERT INTO `' . $GLOBALS['wpdb']->prefix . 'wc_webhooks`' ) ? '' : $query;
		};
		add_filter( 'query', $fail_insert );

		$this->run_admin_init_for( $sync );
		remove_filter( 'query', $fail_insert );

		$this->assertSame( array(), $this->find_woopay_webhook_ids() );
		$this->assertSame( array(), $this->api_client->woopay_updates );
		$this->assertNull( $this->read_option_row( self::CLAIM_OPTION ) );
		$this->assertSame( array( 'Unable to create the WooPay order-status webhook.' ), array_column( $logger->error_calls, 'message' ) );
		$this->assertSame( 'woocommerce-woopayments', $logger->error_calls[0]['context']['source'] ?? null );
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

		$woopay_webhook            = $this->create_plugin_webhook();
		$non_woopay_webhook        = $this->create_webhook_row( self::WEBHOOK_NAME, 'https://example.com/webhook', 'order.status_changed', 'active' );
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
	 * @param bool                $native_register Whether native should register.
	 * @param bool                $woopay_enabled  Whether WooPay is on in the gateway settings.
	 * @param string              $account_status  Account status.
	 * @param array<string,mixed> $account_data    Account data overrides.
	 * @return WooPaymentsWooPayOrderStatusSync
	 */
	private function create_sync( bool $native_register, bool $woopay_enabled = true, string $account_status = '', array $account_data = array() ): WooPaymentsWooPayOrderStatusSync {
		$this->account_service                         = new Task25WooPayAccountService();
		$this->account_service->woopay_enabled         = $woopay_enabled;
		$this->account_service->account_status         = $account_status;
		$this->account_service->account_data_overrides = $account_data;
		$session_service                               = new Task25WooPaySessionService();
		$session_service->init( $this->account_service, new WooPaymentsFrontendStylesService(), new WooPaymentsFrontendTrackingController() );
		$this->api_client = new Task25WooPayApiClient();
		$sync             = new WooPaymentsWooPayOrderStatusSync();
		$sync->init( new StaticNativeRuntimeArbiter( $native_register ), $session_service, $this->api_client, new TransientRowLock() );

		$this->syncs[] = $sync;

		return $sync;
	}

	/**
	 * Create a WooPay webhook row the way client 11.1.0 WooPay_Order_Status_Sync::register_webhook() does.
	 *
	 * @return WC_Webhook
	 */
	private function create_plugin_webhook(): WC_Webhook {
		return $this->create_webhook_row( self::WEBHOOK_NAME, self::WOOPAY_URL, 'order.status_changed', 'active', self::PLUGIN_SECRET );
	}

	/**
	 * Create a webhook row while the plugin's topic filters are present, as the plugin created its rows.
	 *
	 * @param string $name         Webhook name.
	 * @param string $delivery_url Delivery URL.
	 * @param string $topic        Topic.
	 * @param string $status       Status.
	 * @param string $secret       Secret.
	 * @return WC_Webhook
	 */
	private function create_webhook_row( string $name, string $delivery_url, string $topic, string $status, string $secret = 'test-secret' ): WC_Webhook {
		// Client 11.1.0 WooPay_Order_Status_Sync::add_resource(), add_event() and add_topics() make the order.status_changed topic valid.
		$add_resource = static fn( array $resources ): array => array_merge( $resources, array( 'order' ) );
		$add_event    = static fn( array $events ): array => array_merge( $events, array( 'status_changed' ) );
		add_filter( 'woocommerce_valid_webhook_resources', $add_resource );
		add_filter( 'woocommerce_valid_webhook_events', $add_event );

		try {
			$webhook = new WC_Webhook();
			$webhook->set_name( $name );
			$webhook->set_user_id( get_current_user_id() );
			$webhook->set_topic( $topic );
			$webhook->set_secret( $secret );
			$webhook->set_delivery_url( $delivery_url );
			$webhook->set_status( $status );
			$webhook->save();
		} finally {
			remove_filter( 'woocommerce_valid_webhook_resources', $add_resource );
			remove_filter( 'woocommerce_valid_webhook_events', $add_event );
		}

		$this->assertSame( $topic, $webhook->get_topic(), 'The fixture row must keep its topic.' );

		return $webhook;
	}

	/**
	 * Find the active WooPay order-status webhooks straight from the database.
	 *
	 * @return int[]
	 */
	private function find_woopay_webhook_ids(): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE status = 'active' AND topic = 'order.status_changed' AND delivery_url = %s ORDER BY webhook_id",
					self::WOOPAY_URL
				)
			)
		);
	}

	/**
	 * Change a WooPay order's status with every active webhook loaded, and return the IDs of the webhooks that processed a delivery.
	 *
	 * @param \WC_Order $order WooPay order.
	 * @return int[]
	 */
	private function count_deliveries_of_a_status_change( \WC_Order $order ): array {
		$deliveries = array();
		$record     = static function ( $webhook ) use ( &$deliveries ): void {
			$deliveries[] = $webhook->get_id();
		};
		remove_action( 'woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10 );
		add_action( 'woocommerce_webhook_process_delivery', $record );
		wc_load_webhooks( 'active' );

		$order->update_status( 'completed' );

		remove_action( 'woocommerce_webhook_process_delivery', $record );
		add_action( 'woocommerce_webhook_process_delivery', 'wc_webhook_process_delivery', 10, 2 );

		return $deliveries;
	}

	/**
	 * Store the creation lock rows directly in the database, as another request would.
	 *
	 * @param string $value      Lock value.
	 * @param int    $expiration Lock expiry timestamp.
	 */
	private function insert_claim_rows( string $value, int $expiration ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => self::CLAIM_EXPIRY,
				'option_value' => (string) $expiration,
				'autoload'     => 'off',
			)
		);
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => self::CLAIM_OPTION,
				'option_value' => $value,
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Read an option row straight from the database.
	 *
	 * @param string $name Option name.
	 * @return string|null Stored value, or null when the row does not exist.
	 */
	private function read_option_row( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
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
		remove_action( 'admin_init', array( $sync, 'maybe_create_woopay_order_webhook' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $sync, 'reconcile_webhook' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $sync, 'handle_settings_update' ) );
	}

	/**
	 * Run only the admin_init callbacks the given sync registered.
	 *
	 * @param WooPaymentsWooPayOrderStatusSync $sync Sync instance.
	 */
	private function run_admin_init_for( WooPaymentsWooPayOrderStatusSync $sync ): void {
		global $wp_filter;
		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $sync === $callback['function'][0] ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing

/**
 * WooPay session service with a fixed WooPay host.
 */
class Task25WooPaySessionService extends WooPaymentsWooPaySessionService {
	public function get_woopay_rest_url( string $endpoint ): string {
		return 'https://pay.woo.com/wp-json/platform-checkout/v1/' . ltrim( $endpoint, '/' );
	}
}

/**
 * Recording WooPay API client.
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

		// The platform's POST accounts/platform_checkout handler answers { "result": "success" }, which client 11.1.0 update_woopay() returns as decoded.
		return array( 'result' => 'success' );
	}
}

/**
 * Mutable WooPayments account service.
 */
class Task25WooPayAccountService extends WooPaymentsAccountService {
	/**
	 * Whether WooPay is on in the gateway settings.
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
				'account_id'                 => 'acct_woopay_sync',
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
