<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler_Store;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminNoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Data_Store;
use WC_Order;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\RecordingWooPaySessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;

/**
 * Tests for the WooPaymentsOperationalQueueService class.
 */
class WooPaymentsOperationalQueueServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Created services whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsOperationalQueueService[]
	 */
	private array $services = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->services as $service ) {
			$this->remove_operational_hooks( $service );
		}

		remove_all_filters( 'wcpay_test_mode' );
		delete_option( 'WPLANG' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );
		delete_option( 'wcpay_instant_deposits_previously_eligible' );
		delete_option( 'wcpay_post_kyc_activation_email_sent_stages' );
		delete_option( 'wcpay_post_kyc_activation_emails_scheduled' );
		delete_option( 'wcpay_kyc_completion_date' );
		delete_option( 'wcpay_kyc_submitted_date' );
		delete_option( 'wcpay_has_live_sale' );
		delete_option( 'wcpay_test_mode_enabled_date' );
		delete_transient( 'wcpay_test_to_live_eligible' );
		delete_transient( 'wcpay_post_kyc_activation_eligible' );
		delete_transient( 'wcpay_one_and_done_eligible' );
		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars( array() );
		$this->delete_instant_deposit_note();
		remove_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
		remove_filter( 'pre_wp_mail', '__return_true' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_store_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_compliance_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_notification' );
		remove_all_filters( 'woocommerce_email_preview_dummy_order' );
		remove_all_filters( 'woocommerce_email_preview_dummy_address' );
		remove_all_filters( 'woocommerce_email_preview_placeholders' );
		$this->reset_mailer_emails();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION, null, WooPaymentsActionSchedulerService::GROUP_ID );
			as_unschedule_all_actions( 'wcpay_post_kyc_activation_email_send', null, WooPaymentsActionSchedulerService::GROUP_ID );
			as_unschedule_all_actions( 'wcpay_post_kyc_activation_email_send', null, 'woocommerce-payments' );
		}
		unset( $_GET['wcpay_referrer'], $_GET['wcpay_referrer_stage'], $_GET['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		parent::tearDown();
	}

	/**
	 * @testdox Operational queue hooks are registered when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_preserved_operational_hooks_when_builtin_owns_runtime(): void {
		$service = $this->create_service();

		$service->register();

		$this->assertSame( 10, has_action( 'wcpay_store_setup_sync', array( $service, 'handle_wcpay_store_setup_sync' ) ) );
		// Native never fires the plugin's update hook (no plugin version); a WooCommerce update queues the sync instead.
		$this->assertFalse( has_action( 'woocommerce_woocommerce_payments_updated', array( $service, 'handle_wcpay_store_setup_sync' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_update_compatibility_data', array( $service, 'handle_wcpay_update_compatibility_data' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'schedule_compatibility_data_update' ) ) );
		$this->assertSame( 10, has_action( 'after_switch_theme', array( $service, 'schedule_compatibility_data_update' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_record_kyc_completion_date' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) ) );
		$this->assertSame( 10, has_action( 'add_option_wcpay_kyc_completion_date', array( $service, 'handle_add_option_wcpay_kyc_completion_date' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $service, 'handle_wcpay_post_kyc_activation_email_cta' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) ) );
	}

	/**
	 * @testdox A live WooPayments paid-status transition records the sale and invalidates cached post-KYC eligibility.
	 * @testWith ["woocommerce_payments"]
	 *           ["woocommerce_payments_ideal"]
	 *
	 * @param string $payment_method WooPayments gateway ID stored on the order.
	 */
	public function test_live_woopayments_order_status_transition_invalidates_post_kyc_eligibility( string $payment_method ): void {
		$service = $this->create_service();
		$service->register();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		// Plugin 11.1.0 `maybe_record_first_live_sale()` matches `Order_Mode::PRODUCTION` (class-wc-payments-order-service.php:305).
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );

		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );
	}

	/**
	 * @testdox Relevant paid transitions invalidate either cached one-and-done result after the live-sale marker exists.
	 * @dataProvider provide_one_and_done_cache_invalidations
	 *
	 * @param string $payment_method Payment gateway ID.
	 * @param string $mode           WooPayments order mode.
	 * @param string $cached_value   Cached one-and-done eligibility.
	 * @param string $new_status     Paid status the order moves to.
	 */
	public function test_paid_order_status_transition_invalidates_one_and_done_cache( string $payment_method, string $mode, string $cached_value, string $new_status ): void {
		$service = $this->create_service();
		$order   = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		if ( '' !== $mode ) {
			$order->update_meta_data( '_wcpay_mode', $mode );
		}
		$order->save();
		update_option( 'wcpay_has_live_sale', '1', false );
		set_transient( 'wcpay_one_and_done_eligible', $cached_value, HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order->get_id(), OrderStatus::PENDING, $new_status, $order );

		$this->assertFalse( get_transient( 'wcpay_one_and_done_eligible' ) );
		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Relevant transitions and both cached results.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public static function provide_one_and_done_cache_invalidations(): array {
		return array(
			'second live WooPayments order with positive cache' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', '1', OrderStatus::PROCESSING ),
			'alternate gateway order with negative cache' => array( 'cod', '', '0', OrderStatus::COMPLETED ),
			// Client 11.1.0 keeps the cache only for a test-mode card gateway order (admin/attach-rate/class-wc-payments-one-and-done-notice.php:103-106).
			'test-mode iDEAL order with positive cache'   => array( 'woocommerce_payments_ideal', 'test', '1', OrderStatus::PROCESSING ),
		);
	}

	/**
	 * @testdox A paid transition skips order mode metadata when no eligibility cache remains after a live sale.
	 */
	public function test_paid_order_status_transition_without_eligibility_cache_skips_mode_meta(): void {
		$service = $this->create_service();
		$order   = $this->createMock( WC_Order::class );
		$order->method( 'get_payment_method' )->willReturn( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->expects( $this->never() )->method( 'get_meta' );
		update_option( 'wcpay_has_live_sale', '1', false );
		delete_transient( 'wcpay_one_and_done_eligible' );

		$service->handle_woocommerce_order_status_changed( 123, OrderStatus::PENDING, OrderStatus::PROCESSING, $order );

		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * @testdox An alternate-gateway paid transition invalidates cached eligibility without reading order mode metadata.
	 */
	public function test_alternate_gateway_order_status_transition_invalidates_cache_without_mode_meta(): void {
		$service = $this->create_service();
		$order   = $this->createMock( WC_Order::class );
		$order->expects( $this->once() )->method( 'get_payment_method' )->willReturn( 'cod' );
		$order->expects( $this->never() )->method( 'get_meta' );
		set_transient( 'wcpay_one_and_done_eligible', '0', HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( 123, OrderStatus::PENDING, OrderStatus::COMPLETED, $order );

		$this->assertFalse( get_transient( 'wcpay_one_and_done_eligible' ) );
	}

	/**
	 * @testdox A test WooPayments transition leaves cached one-and-done eligibility unchanged.
	 */
	public function test_test_order_status_transition_preserves_one_and_done_cache(): void {
		$service = $this->create_service();
		$order   = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		update_option( 'wcpay_has_live_sale', '1', false );
		set_transient( 'wcpay_one_and_done_eligible', '1', HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order->get_id(), OrderStatus::PENDING, OrderStatus::PROCESSING, $order );

		$this->assertSame( '1', get_transient( 'wcpay_one_and_done_eligible' ) );
	}

	/**
	 * @testdox Ineligible order-status transitions leave the live-sale marker and post-KYC eligibility unchanged.
	 * @dataProvider provide_ineligible_order_status_transitions
	 *
	 * @param string $payment_method Payment method stored on the order.
	 * @param string $mode           WooPayments mode stored on the order.
	 * @param string $new_status     Target order status supplied by the hook.
	 * @param bool   $valid_order    Whether the hook receives a WC_Order.
	 */
	public function test_ineligible_order_status_transitions_preserve_post_kyc_eligibility( string $payment_method, string $mode, string $new_status, bool $valid_order ): void {
		$service  = $this->create_service();
		$order    = new \stdClass();
		$order_id = 123;
		if ( $valid_order ) {
			$order = wc_create_order();
			$this->assertInstanceOf( WC_Order::class, $order );
			$order->set_payment_method( $payment_method );
			$order->update_meta_data( '_wcpay_mode', $mode );
			$order->save();
			$order_id = $order->get_id();
		}
		delete_option( 'wcpay_has_live_sale' );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order_id, OrderStatus::PENDING, $new_status, $order );

		$this->assertFalse( get_option( 'wcpay_has_live_sale' ), 'An ineligible transition must not record a live sale.' );
		$this->assertSame( '1', get_transient( 'wcpay_post_kyc_activation_eligible' ), 'An ineligible transition must not invalidate cached eligibility.' );
	}

	/**
	 * Ineligible order-status hook inputs.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public static function provide_ineligible_order_status_transitions(): array {
		return array(
			'test-mode WooPayments order' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'test', OrderStatus::PROCESSING, true ),
			// The account-mode slug is not an order mode; plugin 11.1.0 matches only `prod`.
			'account-mode live value'     => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'live', OrderStatus::PROCESSING, true ),
			'wrong payment gateway'       => array( 'cod', 'prod', OrderStatus::PROCESSING, true ),
			'unpaid order status'         => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', OrderStatus::PENDING, true ),
			'invalid order object'        => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', OrderStatus::PROCESSING, false ),
		);
	}

	/**
	 * @testdox Referrer CTA handler records the event and redirects for an allowlisted stage without requiring a nonce.
	 */
	public function test_referrer_cta_records_event_with_allowlisted_stage(): void {
		$service  = $this->create_service();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// The email CTA link carries no nonce; the handler must still record the click.
		$_GET['wcpay_referrer']       = 'post_kyc_email';
		$_GET['wcpay_referrer_stage'] = '7';

		$redirected = false;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirected ) {
				$redirected = true;
				// Stand in for the handler's exit so the test run is not terminated.
				throw new \Exception( esc_html( (string) $location ) );
			}
		);

		try {
			$service->handle_wcpay_post_kyc_activation_email_cta();
		} catch ( \Exception $e ) {
			// The redirect exception is the expected end of the happy path.
			unset( $e );
		}

		$this->assertTrue( $redirected, 'An allowlisted stage should record the event and redirect without a nonce.' );
	}

	/**
	 * @testdox Referrer CTA handler skips recording and never redirects for $_dataName.
	 * @dataProvider provider_referrer_cta_not_recorded
	 *
	 * @param string $role  Role of the current user.
	 * @param string $stage The referrer stage in the CTA link.
	 */
	public function test_referrer_cta_skips_recording( string $role, string $stage ): void {
		$service = $this->create_service();
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$_GET['wcpay_referrer']       = 'post_kyc_email';
		$_GET['wcpay_referrer_stage'] = $stage;

		$redirected = false;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirected ) {
				unset( $location );
				$redirected = true;
				// Guard the test run: a redirect here would otherwise reach the handler's exit.
				throw new \Exception( 'unexpected redirect' );
			}
		);

		try {
			$service->handle_wcpay_post_kyc_activation_email_cta();
		} catch ( \Exception $e ) {
			unset( $e );
		}

		$this->assertFalse( $redirected, 'The handler must not record analytics or trigger a redirect.' );
	}

	/**
	 * CTA clicks the handler must not record.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function provider_referrer_cta_not_recorded(): array {
		return array(
			// Stage 3 is not in POST_KYC_STAGE_DAYS.
			'a stage that is not allowlisted'   => array( 'administrator', '3' ),
			'a user without manage_woocommerce' => array( 'customer', '7' ),
		);
	}

	/**
	 * @testdox Store setup sync sends a WooPayments-compatible snapshot through the API client.
	 */
	public function test_store_setup_sync_sends_store_snapshot(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                           => 'yes',
				'test_mode'                         => 'yes',
				'upe_enabled_payment_method_ids'    => array( 'card' ),
				'manual_capture'                    => 'yes',
				'enable_logging'                    => 'yes',
				'saved_cards'                       => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request', 'woopay' ),
				'express_checkout_cart_methods'     => array( 'payment_request' ),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);

		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'send_store_setup' )
			->with(
				$this->callback(
					function ( array $snapshot ): bool {
						return true === $snapshot['gateway']['enabled']
							&& true === $snapshot['gateway']['test_mode']
							&& array( 'card', 'ideal', 'apple_pay', 'google_pay' ) === $snapshot['payment_methods']['available']
							&& array( 'card' ) === $snapshot['payment_methods']['enabled']
							&& array( 'ideal', 'apple_pay', 'google_pay' ) === $snapshot['payment_methods']['disabled']
							&& in_array( 'card_payments', $snapshot['provider_capabilities']['enabled'], true )
							&& in_array( 'ideal_payments', $snapshot['provider_capabilities']['disabled'], true )
							&& true === $snapshot['manual_capture_enabled']
							&& true === $snapshot['debug_log_enabled']
							&& true === $snapshot['payment_request']['enabled']
							&& array( 'product', 'cart' ) === $snapshot['payment_request']['enabled_locations']
							&& array( 'product', 'checkout' ) === $snapshot['woopay']['enabled_locations'];
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'fees' => array(
					'card'  => array(),
					'ideal' => array(),
				),
			)
		);
		$this->create_service( new RecordingActionSchedulerService(), $api_client, $account_service )->handle_wcpay_store_setup_sync();
	}

	/**
	 * @testdox Should report the Stripe Billing toggle in the store setup snapshot while WooCommerce Subscriptions is active (client `class-wc-payments-account.php:2977`).
	 * @testWith ["1", true]
	 *           ["0", false]
	 *
	 * @param string $toggle   Stripe Billing toggle option value.
	 * @param bool   $expected Reported `stripe_billing_enabled`.
	 */
	public function test_store_setup_sync_reports_stripe_billing( string $toggle, bool $expected ): void {
		// The module asks LegacyProxy whether WooCommerce Subscriptions is active; the mock is reset after every test.
		wc_get_container()->get( LegacyProxy::class )->register_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, $toggle );
		$module = new WooPaymentsStripeBillingModule();
		$module->register();
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		$snapshots  = array();
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'send_store_setup' )->willReturnCallback(
			static function ( array $snapshot ) use ( &$snapshots ): array {
				$snapshots[] = $snapshot;
				return array( 'result' => 'success' );
			}
		);

		$this->create_service( new RecordingActionSchedulerService(), $api_client )->handle_wcpay_store_setup_sync();

		$this->assertCount( 1, $snapshots );
		$this->assertSame( $expected, $snapshots[0]['stripe_billing_enabled'] );
	}

	/**
	 * @testdox Should report WooCommerce Subscriptions as active when its class is loaded, whatever folder holds it (client 11.1.0 `class-wc-payments-account.php:3001`).
	 */
	public function test_store_setup_sync_reports_subscriptions_active_by_its_loaded_class(): void {
		// WooCommerce Subscriptions as WooCommerce.com installs it, outside the woocommerce-subscriptions folder.
		update_option( 'active_plugins', array( 'woocommerce-com-woocommerce-subscriptions/woocommerce-subscriptions.php' ) );
		wc_get_container()->get( LegacyProxy::class )->register_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);

		$snapshot = $this->send_store_setup_snapshot();

		$this->assertTrue( $snapshot['wc_setup']['wc_subscriptions_active'] );
	}

	/**
	 * @testdox Should report the WooPayments version the store reports everywhere else as the store setup plugin version, not WooCommerce's.
	 *
	 * Client 11.1.0 sends its own version there (class-wc-payments-account.php:2981); the user agent reports the same
	 * WooPaymentsClientVersion, and WooCommerce's version goes under wc_setup.
	 */
	public function test_store_setup_sync_reports_the_woopayments_version_as_the_plugin_version(): void {
		$snapshot = $this->send_store_setup_snapshot();

		$this->assertSame( WooPaymentsClientVersion::VERSION, $snapshot['plugin']['version'] );
	}

	/**
	 * @testdox Should report no WooCommerce Subscriptions version while the plugin is installed but not loaded (client 11.1.0 `class-wc-payments-account.php:3002`).
	 */
	public function test_store_setup_sync_reports_no_subscriptions_version_while_not_loaded(): void {
		update_option( 'active_plugins', array() );
		// get_plugins() answers from this cache, so the installed plugin needs no file on disk.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'woocommerce-subscriptions/woocommerce-subscriptions.php' => array(
						'Name'    => 'WooCommerce Subscriptions',
						'Version' => '7.1.0',
					),
				),
			),
			'plugins'
		);

		try {
			$snapshot = $this->send_store_setup_snapshot();
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}

		$this->assertFalse( $snapshot['wc_setup']['wc_subscriptions_active'] );
		$this->assertNull( $snapshot['wc_setup']['wc_subscriptions_version'] );
	}

	/**
	 * @testdox Should report the loaded WooCommerce Subscriptions class's version (client 11.1.0 `trait-wc-payments-subscriptions-utilities.php:129-131`, sent at `class-wc-payments-account.php:3002`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_setup_sync_reports_the_loaded_subscriptions_version(): void {
		// The global WC_Subscriptions class cannot be unloaded, so this test runs in its own process.
		require_once __DIR__ . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( Fixtures\LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$snapshot = $this->send_store_setup_snapshot();

		$this->assertTrue( $snapshot['wc_setup']['wc_subscriptions_active'] );
		$this->assertSame( Fixtures\LateLoadedSubscriptions::$version, $snapshot['wc_setup']['wc_subscriptions_version'] );
	}

	/**
	 * @testdox Should report the available payment methods as the registered methods the account has fees for (client 11.1.0 `class-wc-payment-gateway-wcpay.php:4848-4879`, sent at `class-wc-payments-account.php:2908-2909`).
	 */
	public function test_store_setup_sync_reports_available_methods_from_account_fees(): void {
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'country' => 'US',
				'fees'    => array(
					'card'       => array(),
					'bancontact' => array(),
					'link'       => array(),
				),
			)
		);

		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			),
			$account_service
		);

		// Registry order; Apple Pay and Google Pay are charged at card fees, so they count while card has fees.
		$this->assertSame( array( 'card', 'bancontact', 'link', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['available'] );
		$this->assertSame( array( 'card', 'link' ), $snapshot['payment_methods']['enabled'] );
		$this->assertSame( array( 'bancontact', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['disabled'] );
		$this->assertSame( array( 'bancontact_payments', 'card_payments' ), $snapshot['provider_capabilities']['disabled'] );
	}

	/**
	 * @testdox Should report Amazon Pay as available only while its feature flag is on (client 11.1.0 `PaymentMethodDefinitionRegistry.php:102-104` registers it only then).
	 * @testWith ["1", true]
	 *           ["0", false]
	 *
	 * @param string $flag     The Amazon Pay feature flag option value.
	 * @param bool   $expected Whether Amazon Pay is reported as available.
	 */
	public function test_store_setup_sync_reports_amazon_pay_only_while_its_feature_is_on( string $flag, bool $expected ): void {
		update_option( '_wcpay_feature_amazon_pay', $flag );
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'fees' => array(
					'card'       => array(),
					'amazon_pay' => array(),
				),
			)
		);

		try {
			$snapshot = $this->send_store_setup_snapshot( array( 'enabled' => 'yes' ), $account_service );
		} finally {
			delete_option( '_wcpay_feature_amazon_pay' );
		}

		$this->assertSame( $expected, in_array( 'amazon_pay', $snapshot['payment_methods']['available'], true ) );
	}

	/**
	 * @testdox Should report a stored empty list of enabled payment methods as empty (client 11.1.0 `get_upe_enabled_payment_method_ids()`, `class-wc-payment-gateway-wcpay.php:4682-4689`, sent at `class-wc-payments-account.php:2909`).
	 */
	public function test_store_setup_sync_reports_a_stored_empty_enabled_list_as_empty(): void {
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service( array( 'fees' => array( 'card' => array() ) ) );

		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array(),
			),
			$account_service
		);

		$this->assertSame( array(), $snapshot['payment_methods']['enabled'] );
		$this->assertSame( array( 'card', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['disabled'] );
	}

	/**
	 * A store onboarded natively stores only the keys onboarding writes until the merchant saves the settings page; every
	 * other setting reads as its form default, as WC_Settings_API::get_option() answers a key that was never saved.
	 *
	 * @testdox Should report the settings an onboarded store never saved from their defaults.
	 */
	public function test_store_setup_sync_reports_unsaved_settings_from_their_defaults(): void {
		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);

		$this->assertTrue( $snapshot['saved_cards_enabled'] );
		$this->assertSame( array( 'product', 'cart', 'checkout' ), $snapshot['payment_request']['enabled_locations'] );
		$this->assertSame( array( 'product', 'cart', 'checkout' ), $snapshot['woopay']['enabled_locations'] );
		$this->assertSame(
			array( 'default', 'medium', 'dark' ),
			array( $snapshot['payment_request']['button_type'], $snapshot['payment_request']['button_size'], $snapshot['payment_request']['button_theme'] )
		);
	}

	/**
	 * @testdox Should report WooPay as enabled only when the account is eligible, the WooPay setting is on and the WooPay express checkout flag is on: $_dataName.
	 * @dataProvider provider_store_setup_sync_woopay_enabled
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Cached account data.
	 * @param string|null         $flag         WooPay express checkout flag option, or null to leave it never written.
	 * @param bool                $expected     Reported `woopay.enabled`.
	 */
	public function test_store_setup_sync_reports_woopay_enabled_by_the_feature_check( array $settings, array $account_data, ?string $flag, bool $expected ): void {
		delete_option( '_wcpay_feature_woopay_express_checkout' );
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_woopay_express_checkout', $flag );
		}

		$account_service    = $this->create_account_service( $account_data );
		$woopay_eligibility = $this->create_woopay_eligibility( $account_service );

		try {
			$snapshot = $this->send_store_setup_snapshot( $settings, $account_service, $woopay_eligibility );
		} finally {
			delete_option( '_wcpay_feature_woopay_express_checkout' );
		}

		$this->assertSame( $expected, $snapshot['woopay']['enabled'] );
	}

	/**
	 * WooPay settings, account eligibility and flag states, with the `woopay.enabled` each should report.
	 *
	 * @return array<string,array{array<string,mixed>,array<string,mixed>,string|null,bool}>
	 */
	public static function provider_store_setup_sync_woopay_enabled(): array {
		$listed_everywhere = array(
			'enabled'                           => 'yes',
			'express_checkout_product_methods'  => array( 'woopay' ),
			'express_checkout_cart_methods'     => array( 'woopay' ),
			'express_checkout_checkout_methods' => array( 'woopay' ),
		);
		$woopay_on         = array_merge( $listed_everywhere, array( 'platform_checkout' => 'yes' ) );
		// The account cache field the WooPay eligibility check reads (client 11.1.0 includes/class-wc-payments-features.php:206-209).
		$eligible = array( 'platform_checkout_eligible' => true );

		return array(
			'listed at every location, setting off' => array( $listed_everywhere, $eligible, null, false ),
			'setting on, account not eligible'      => array( $woopay_on, array(), null, false ),
			'setting on, eligible, flag off'        => array( $woopay_on, $eligible, '0', false ),
			'setting on, eligible, flag on'         => array( $woopay_on, $eligible, '1', true ),
			'setting on, eligible, flag never set'  => array( $woopay_on, $eligible, null, true ),
		);
	}

	/**
	 * @testdox Should report whether each active plugin declares WooCommerce support in its headers.
	 */
	public function test_store_setup_sync_reports_woocommerce_awareness_of_active_plugins(): void {
		// Both files exist in the test install, so WordPress keeps them as active and valid.
		update_option( 'active_plugins', array( 'hello.php', 'sample-woo-plugin.php' ) );
		// get_plugins() answers from this cache, so the headers are the test's own: WordPress keeps it as the 'plugins' entry
		// of the 'plugins' cache group, keyed by plugin folder, '' for the plugins root (wp-admin/includes/plugin.php
		// get_plugins()). Core's PluginUtil::is_woocommerce_aware_plugin() reads the 'WC tested up to' header.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'hello.php'             => array(
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					),
					'sample-woo-plugin.php' => array(
						'Name'            => 'Sample Woo Plugin',
						'Version'         => '1.0.0',
						'WC tested up to' => '11.2',
					),
				),
			),
			'plugins'
		);

		try {
			$snapshot = $this->send_store_setup_snapshot();
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}

		$this->assertSame(
			array(
				array(
					'name'     => 'Hello Dolly',
					'slug'     => '.',
					'version'  => '1.7.2',
					'wc_aware' => false,
				),
				array(
					'name'     => 'Sample Woo Plugin',
					'slug'     => '.',
					'version'  => '1.0.0',
					'wc_aware' => true,
				),
			),
			$snapshot['wp_setup']['active_plugins']
		);
	}

	/**
	 * Run the store setup sync and return the one snapshot it sends to the platform.
	 *
	 * @param array<string,mixed>                            $settings                          Gateway settings to store first.
	 * @param WooPaymentsAccountService|null                 $account_service                   Account service, or the default double.
	 * @param WooPaymentsWooPayEligibility|null              $woopay_eligibility                WooPay eligibility rules, or the container's.
	 * @param WooPaymentsDuplicateGatewayMethodsService|null $duplicate_gateway_methods_service Duplicate gateway methods service, or the container's.
	 * @return array<string,mixed>
	 */
	private function send_store_setup_snapshot( array $settings = array( 'enabled' => 'yes' ), ?WooPaymentsAccountService $account_service = null, ?WooPaymentsWooPayEligibility $woopay_eligibility = null, ?WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service = null ): array {
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );

		$snapshots  = array();
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'send_store_setup' )->willReturnCallback(
			static function ( array $snapshot ) use ( &$snapshots ): array {
				$snapshots[] = $snapshot;
				return array( 'result' => 'success' );
			}
		);

		$this->create_service( new RecordingActionSchedulerService(), $api_client, $account_service, $woopay_eligibility, $duplicate_gateway_methods_service )->handle_wcpay_store_setup_sync();

		$this->assertCount( 1, $snapshots, 'The store setup sync sends one snapshot.' );

		return $snapshots[0];
	}

	/**
	 * @testdox Should report real duplicate gateways and the Multi-Currency flag in the store setup snapshot: $_dataName.
	 * @dataProvider provider_store_setup_sync_multi_currency_flag
	 *
	 * @param array<string,string>   $flag_options        Multi-Currency flag option to write; none leaves it never written.
	 * @param array<string,string[]> $duplicates          Duplicate gateways found, by payment method.
	 * @param bool                   $expected_mc_enabled The Multi-Currency flag the snapshot reports.
	 */
	public function test_store_setup_sync_reports_duplicates_and_multi_currency( array $flag_options, array $duplicates, bool $expected_mc_enabled ): void {
		delete_option( '_wcpay_feature_customer_multi_currency' );
		foreach ( $flag_options as $option => $value ) {
			update_option( $option, $value );
		}

		$duplicate_gateway_methods_service = $this->getMockBuilder( WooPaymentsDuplicateGatewayMethodsService::class )
			->onlyMethods( array( 'get_duplicated_payment_method_ids' ) )
			->getMock();
		$duplicate_gateway_methods_service->method( 'get_duplicated_payment_method_ids' )
			->willReturn( $duplicates );

		$snapshot = $this->send_store_setup_snapshot( array( 'enabled' => 'yes' ), null, null, $duplicate_gateway_methods_service );

		$this->assertSame( $duplicates, $snapshot['payment_methods']['duplicates'] );
		$this->assertSame( $expected_mc_enabled, $snapshot['multi_currency_enabled'] );
		$this->assertFalse( $snapshot['stripe_billing_enabled'] );
	}

	/**
	 * Multi-Currency flag states and duplicate gateways, with the flag the snapshot should report.
	 *
	 * @return array<string,array{array<string,string>,array<string,string[]>,bool}>
	 */
	public static function provider_store_setup_sync_multi_currency_flag(): array {
		return array(
			'flag off, one duplicate'           => array(
				array( '_wcpay_feature_customer_multi_currency' => '0' ),
				array( 'card' => array( 'woocommerce_payments', 'stripe' ) ),
				false,
			),
			// The WooPayments extension defaults the flag to enabled.
			'flag never written, no duplicates' => array( array(), array(), true ),
		);
	}

	/**
	 * @testdox Should send the account locale to the platform when the site language changes: $_dataName.
	 * @dataProvider provider_site_language_change
	 *
	 * @param string                         $old_language     WPLANG before the change.
	 * @param string                         $new_language     WPLANG written.
	 * @param array<string,mixed>            $account_data     Cached account data.
	 * @param array<int,array<string,mixed>> $expected_updates Account updates sent to the platform.
	 */
	public function test_site_language_change_updates_the_account_locale( string $old_language, string $new_language, array $account_data, array $expected_updates ): void {
		update_option( 'WPLANG', $old_language );
		$updates = array();
		$service = $this->create_service( new RecordingActionSchedulerService(), $this->create_account_update_recorder( $updates ), $this->create_account_service( $account_data ) );
		$service->register();

		update_option( 'WPLANG', $new_language );

		$this->assertSame( $expected_updates, $updates );
	}

	/**
	 * Site language changes, with the account updates each should send.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,array<int,array<string,mixed>>}>
	 */
	public static function provider_site_language_change(): array {
		$account = array( 'account_id' => 'acct_native_test' );

		return array(
			'a new language is sent'             => array( '', 'de_DE', $account, array( array( 'locale' => 'de_DE' ) ) ),
			'a reset to the default sends en_US' => array( 'de_DE', '', $account, array( array( 'locale' => 'en_US' ) ) ),
			'nothing is sent without an account' => array( '', 'de_DE', array(), array() ),
		);
	}

	/**
	 * @testdox Should re-send the account's business URL once only when the store goes live: $_dataName.
	 * @dataProvider provider_store_launch
	 *
	 * @param string                         $old_value        Coming-soon value before the write.
	 * @param string                         $new_value        Coming-soon value written.
	 * @param array<string,mixed>            $account_data     Cached account data.
	 * @param array<int,array<string,mixed>> $expected_updates Account updates sent to the platform.
	 */
	public function test_store_launch_resends_the_business_url_only_when_the_store_goes_live( string $old_value, string $new_value, array $account_data, array $expected_updates ): void {
		update_option( 'woocommerce_coming_soon', $old_value );
		$updates = array();
		$service = $this->create_service( new RecordingActionSchedulerService(), $this->create_account_update_recorder( $updates ), $this->create_account_service( $account_data ) );
		$service->register();

		update_option( 'woocommerce_coming_soon', $new_value );

		$this->assertSame( $expected_updates, $updates );
	}

	/**
	 * Coming-soon changes and accounts, with the account updates each should send.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,array<int,array<string,mixed>>}>
	 */
	public static function provider_store_launch(): array {
		$account_with_url = self::get_account_with_business_url();

		return array(
			'the store goes live'    => array( 'yes', 'no', $account_with_url, array( array( 'business_url' => 'https://shop.example.com' ) ) ),
			'the store goes private' => array( 'no', 'yes', $account_with_url, array() ),
			'no connected account'   => array( 'yes', 'no', array(), array() ),
			'no business URL'        => array( 'yes', 'no', array( 'account_id' => 'acct_native_test' ), array() ),
		);
	}

	/**
	 * Create an API client that records each account update it is asked to send.
	 *
	 * @param array<int,array<string,mixed>> $updates Receives each update body.
	 * @return WooPaymentsApiClient
	 */
	private function create_account_update_recorder( array &$updates ): WooPaymentsApiClient {
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->method( 'update_account' )->willReturnCallback(
			static function ( array $body ) use ( &$updates ): array {
				$updates[] = $body;
				return array();
			}
		);

		return $api_client;
	}

	/**
	 * Account data with a business URL, in the shape the client reads it (client 11.1.0
	 * `includes/class-wc-payments-account.php:459-462`, `business_profile.url`).
	 *
	 * @return array<string,mixed>
	 */
	private static function get_account_with_business_url(): array {
		return array(
			'account_id'       => 'acct_native_test',
			'business_profile' => array( 'url' => 'https://shop.example.com' ),
		);
	}

	/**
	 * Client 11.1.0 adds the currencies of the methods in its payment method map (`includes/compat/multi-currency/class-wc-payments-currency-manager.php:67-70,96-135`),
	 * which holds Amazon Pay only while its feature is on (`includes/payment-methods/Configs/Registry/PaymentMethodDefinitionRegistry.php:102-105`).
	 * A domestic-only method requires the account's domestic currency, the currency of the account's country, not the account's default currency (`:71`, `:81`).
	 *
	 * @testdox Should auto-enable the currencies a newly enabled payment method requires: $_dataName.
	 * @dataProvider provider_settings_save_currency_auto_add
	 *
	 * @param array<string,string> $feature_flags        Feature flag options to write.
	 * @param string               $country              Account country.
	 * @param string               $default_currency     Account default currency.
	 * @param array<string,float>  $rates                Cached currency rates.
	 * @param string               $method               Payment method enabled beside card.
	 * @param string[]             $expected_enabled     Currencies that must be enabled after the save.
	 * @param string[]             $expected_not_enabled Currencies that must not be enabled after the save.
	 */
	public function test_settings_save_auto_adds_the_currencies_a_method_requires( array $feature_flags, string $country, string $default_currency, array $rates, string $method, array $expected_enabled, array $expected_not_enabled ): void {
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		foreach ( $feature_flags as $option => $value ) {
			update_option( $option, $value );
		}
		$this->register_unavailable_rate_provider();
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => $rates,
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );

		// The account fields follow the recorded Fixtures/platform-test-drive-account.json `account` (`account_id`, `country`, `store_currencies.default`).
		$account_service = $this->create_account_service(
			array(
				'account_id'       => 'acct_native_test',
				'country'          => $country,
				'store_currencies' => array( 'default' => $default_currency ),
			)
		);
		$service         = $this->create_service( new RecordingActionSchedulerService(), null, $account_service );
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', $method ) ) );

		// A save that needs no currency leaves the option unwritten.
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$this->assertSame( $expected_enabled, array_values( array_intersect( $expected_enabled, $enabled_currencies ) ), 'The method\'s required currencies must be auto-enabled.' );
		$this->assertSame( array(), array_values( array_intersect( $expected_not_enabled, $enabled_currencies ) ), 'A currency the method does not require must stay disabled.' );
	}

	/**
	 * Accounts and newly enabled methods, with the currencies the save should and should not enable.
	 *
	 * @return array<string,array{array<string,string>,string,string,array<string,float>,string,string[],string[]}>
	 */
	public static function provider_settings_save_currency_auto_add(): array {
		return array(
			'iDEAL on a US account'                     => array( array(), 'US', 'usd', array( 'eur' => 0.9 ), 'ideal', array( 'EUR' ), array() ),
			// Amazon Pay's supported currencies on a GB account include EUR (client 11.1.0 `includes/payment-methods/Configs/Definitions/AmazonPayDefinition.php:106-128`).
			'Amazon Pay with its feature on'            => array( array( '_wcpay_feature_amazon_pay' => '1' ), 'GB', 'gbp', array( 'eur' => 0.9 ), 'amazon_pay', array( 'EUR' ), array() ),
			'Amazon Pay with its feature off'           => array( array( '_wcpay_feature_amazon_pay' => '0' ), 'GB', 'gbp', array( 'eur' => 0.9 ), 'amazon_pay', array(), array( 'EUR' ) ),
			'Affirm on a CA account with a EUR default' => array(
				array(),
				'CA',
				'eur',
				array(
					'cad' => 1.37,
					'eur' => 0.9,
				),
				'affirm',
				array( 'CAD' ),
				array( 'EUR' ),
			),
		);
	}

	/**
	 * Register an unavailable automatic-rate provider so the test exercises the production cache-fallback path.
	 */
	private function register_unavailable_rate_provider(): void {
		$provider = $this->createMock( CurrencyRateProvider::class );
		$provider->method( 'get_id' )->willReturn( 'test-outage' );
		$provider->method( 'is_available' )->willReturn( false );

		$registrar = $this->createMock( CurrencyRateProviderRegistrarInterface::class );
		$registrar->method( 'register' )->willReturnCallback(
			static function ( CurrencyRateProviderRegistry $registry ) use ( $provider ): void {
				$registry->register( $provider );
			}
		);

		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars(
			array( $registrar )
		);
	}

	/**
	 * @testdox Should leave the enabled currencies untouched when Multi-Currency is disabled.
	 */
	public function test_settings_save_does_not_touch_currencies_when_multi_currency_disabled(): void {
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array( 'eur' => 0.9 ),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );

		$service = $this->create_service( new RecordingActionSchedulerService(), null, $this->create_account_service( array( 'account_id' => 'acct_native_test' ) ) );
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) ) );

		$this->assertFalse( get_option( 'wcpay_multi_currency_enabled_currencies' ), 'Disabled Multi-Currency must not gain enabled currencies.' );
	}

	/**
	 * @testdox Should stamp the test-mode enable date and clear notice eligibility on a mode flip.
	 */
	public function test_test_mode_toggle_stamps_and_clears_bookkeeping(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		set_transient( 'wcpay_test_to_live_eligible', '1', 100 );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', 100 );
		$service = $this->create_service();
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$enabled_date = (int) get_option( 'wcpay_test_mode_enabled_date' );
		$this->assertGreaterThan( 0, $enabled_date );
		$this->assertFalse( get_transient( 'wcpay_test_to_live_eligible' ) );
		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );

		$this->assertFalse( get_option( 'wcpay_test_mode_enabled_date' ), 'Disabling test mode must restart the nudge clock.' );
	}

	/**
	 * @testdox Should preserve the original enable date across saves that do not flip the mode.
	 */
	public function test_test_mode_bookkeeping_preserves_enable_date_without_a_flip(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option( 'wcpay_test_mode_enabled_date', 1234567890, false );
		$service = $this->create_service();
		$service->register();

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'test_mode'      => 'yes',
				'enable_logging' => 'yes',
			)
		);

		$this->assertSame( 1234567890, (int) get_option( 'wcpay_test_mode_enabled_date' ), 'A save without a mode flip must not restamp the enable date.' );
	}

	/**
	 * @testdox Recurring store setup sync is scheduled once in the WooPayments group.
	 */
	public function test_schedule_recurring_actions_schedules_store_setup_sync_once(): void {
		$service = $this->create_service();

		$service->schedule_recurring_actions();
		$service->schedule_recurring_actions();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION,
					'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
	}

	/**
	 * @testdox A WooCommerce update queues one store setup sync beside the recurring one, and that sync sends the snapshot.
	 */
	public function test_queue_store_setup_sync_adds_one_update_sync(): void {
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )->method( 'send_store_setup' );
		$service = $this->create_service( new WooPaymentsActionSchedulerService(), $api_client );
		$service->register();
		$service->schedule_recurring_actions();

		$service->queue_store_setup_sync();
		$service->queue_store_setup_sync();

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION,
				'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 2, $pending, 'The recurring sync and one sync for the update, however often the update asks.' );
		$update_sync = as_get_scheduled_actions(
			array(
				'hook'   => WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION,
				'args'   => array( 'woocommerce_updated' ),
				'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $update_sync );
		$update_sync = array_values( $update_sync );

		\ActionScheduler::store()->fetch_action( (string) $update_sync[0] )->execute();
	}

	/**
	 * @testdox Compatibility updates are scheduled two minutes out through the preserved Action Scheduler hook.
	 */
	public function test_schedule_compatibility_data_update_schedules_delayed_job(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$before    = time();

		$service->schedule_compatibility_data_update();

		$this->assertSame( 'wcpay_update_compatibility_data', $scheduler->scheduled_jobs[0]['hook'] );
		$this->assertSame( array(), $scheduler->scheduled_jobs[0]['args'] );
		// Two minutes out, as the client schedules it (class-compatibility-service.php:54-57).
		$this->assertGreaterThanOrEqual( $before + 2 * MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + 2 * MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
	}

	/**
	 * @testdox Compatibility hook sends the WooPayments-compatible payload through the API client.
	 */
	public function test_update_compatibility_data_sends_payload(): void {
		$api_client = $this->create_api_client( array( 'update_compatibility_data' ) );
		$api_client->expects( $this->once() )
			->method( 'update_compatibility_data' )
			->with(
				$this->callback(
					function ( array $payload ): bool {
						return isset(
							$payload['woopayments_version'],
							$payload['woocommerce_version'],
							$payload['woocommerce_permalinks'],
							$payload['woocommerce_shop'],
							$payload['woocommerce_cart'],
							$payload['woocommerce_checkout'],
							$payload['blog_theme'],
							$payload['active_plugins'],
							$payload['post_types_count']
						);
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		$this->create_service( new RecordingActionSchedulerService(), $api_client )->handle_wcpay_update_compatibility_data();
	}

	/**
	 * @testdox A platform error in the $job job is logged with its status and code, never its message.
	 * @testWith ["site language"]
	 *           ["store setup sync"]
	 *           ["compatibility data update"]
	 *           ["store launch"]
	 *
	 * Client 11.1.0 appends the platform's message to these lines. The Fee details job's line is covered in WooPaymentsFeeDetailsNoteControllerTest.
	 *
	 * @param string $job Operational job whose platform call fails.
	 */
	public function test_job_platform_error_log_leaves_out_platform_text( string $job ): void {
		$methods    = array(
			'site language'             => array( 'update_account' ),
			'store setup sync'          => array( 'is_available', 'send_store_setup' ),
			'compatibility data update' => array( 'update_compatibility_data' ),
			'store launch'              => array( 'update_account' ),
		);
		$api_client = $this->create_api_client( $methods[ $job ] );
		if ( 'store setup sync' === $job ) {
			$api_client->method( 'is_available' )->willReturn( true );
		}
		$api_client->method( end( $methods[ $job ] ) )->willThrowException( self::make_provider_error() );
		$service = $this->create_service( new RecordingActionSchedulerService(), $api_client, $this->create_account_service( self::get_account_with_business_url() ) );
		$logger  = RecordingWcLogger::install();

		switch ( $job ) {
			case 'site language':
				$service->handle_site_language_update( 'WPLANG', '', 'de_DE' );
				break;
			case 'store setup sync':
				$service->handle_wcpay_store_setup_sync();
				break;
			case 'compatibility data update':
				$service->handle_wcpay_update_compatibility_data();
				break;
			case 'store launch':
				$service->handle_store_launch( 'yes', 'no' );
				break;
		}

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Instant deposit eligibility refresh creates the preserved inbox note and reminder.
	 */
	public function test_instant_deposit_eligibility_refresh_creates_note_and_reminder(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );

		$service->handle_wcpay_instant_deposits_inbox_note(
			array(
				'instant_deposits_eligible' => true,
			)
		);

		$this->assertTrue( (bool) get_option( 'wcpay_instant_deposits_previously_eligible', false ) );
		$this->assertNotSame( array(), $this->get_instant_deposit_note_ids() );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertSame( 'wcpay_instant_deposit_reminder', $scheduler->scheduled_jobs[0]['hook'] );
		$this->assertSame( array(), $scheduler->scheduled_jobs[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 90 * DAY_IN_SECONDS - 5, $scheduler->scheduled_jobs[0]['timestamp'] ?? 0 );
	}

	/**
	 * @testdox Instant deposit eligibility refresh skips ineligible accounts.
	 */
	public function test_instant_deposit_eligibility_refresh_skips_ineligible_accounts(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );

		$service->handle_wcpay_instant_deposits_inbox_note(
			array(
				'instant_deposits_eligible' => false,
			)
		);

		$this->assertFalse( (bool) get_option( 'wcpay_instant_deposits_previously_eligible', false ) );
		$this->assertSame( array(), $this->get_instant_deposit_note_ids() );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox Instant deposit reminder refreshes the note from cached account data.
	 */
	public function test_instant_deposit_reminder_refreshes_note_from_cached_account_data(): void {
		$scheduler       = new RecordingActionSchedulerService();
		$account_service = $this->create_account_service(
			array(
				'instant_deposits_eligible' => true,
			),
			false
		);
		$service         = $this->create_service( $scheduler, null, $account_service );

		$service->handle_wcpay_instant_deposit_reminder();

		$this->assertNotSame( array(), $this->get_instant_deposit_note_ids() );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertSame( 'wcpay_instant_deposit_reminder', $scheduler->scheduled_jobs[0]['hook'] );
	}

	/**
	 * @testdox Post-KYC completion schedules staged activation emails.
	 */
	public function test_post_kyc_completion_schedules_staged_activation_emails(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$kyc_date  = time();

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', $kyc_date );

		$this->assertSame( '1', get_option( 'wcpay_post_kyc_activation_emails_scheduled' ) );
		$this->assertSame(
			array( array( 7 ), array( 14 ), array( 30 ) ),
			array_column( $scheduler->scheduled_jobs, 'args' )
		);
		$this->assertSame(
			array( 'wcpay_post_kyc_activation_email_send', 'wcpay_post_kyc_activation_email_send', 'wcpay_post_kyc_activation_email_send' ),
			array_column( $scheduler->scheduled_jobs, 'hook' )
		);
	}

	/**
	 * @testdox Post-KYC completion schedules activation emails under the hyphenated woocommerce-payments group.
	 *
	 * Client 11.1.0 class-wc-payments-post-kyc-activation-email-service.php:113 schedules each stage
	 * under the literal 'woocommerce-payments', not the scheduler service's 'woocommerce_payments'.
	 */
	public function test_post_kyc_completion_schedules_emails_under_the_hyphenated_group(): void {
		$service = $this->create_service( new WooPaymentsActionSchedulerService() );

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		foreach ( array( 7, 14, 30 ) as $stage ) {
			$this->assertIsInt( as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( $stage ), 'woocommerce-payments' ), "Stage {$stage} must be scheduled under woocommerce-payments." );
		}
		$this->assertFalse( as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', null, WooPaymentsActionSchedulerService::GROUP_ID ) );
	}

	/**
	 * @testdox Dismissing a post-KYC notice leaves email eligibility, sent stages, and scheduled delivery unchanged.
	 */
	public function test_post_kyc_notice_dismissal_does_not_change_email_delivery(): void {
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$scheduled_at = time() + DAY_IN_SECONDS;
		wp_set_current_user( $user_id );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );
		as_schedule_single_action(
			$scheduled_at,
			'wcpay_post_kyc_activation_email_send',
			array( 7 ),
			'woocommerce-payments',
			true
		);
		$scheduled_before = as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( 7 ), 'woocommerce-payments' );
		$notice_service   = new WooPaymentsAdminNoticeService( static fn(): int => 1700000000 );
		$notice_service->init( $this->createMock( WooPaymentsAccountService::class ) );

		$this->assertTrue( $notice_service->record_action( 'post_kyc_activation', 'dismiss', 7 ) );

		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );
		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
		$this->assertSame( $scheduled_before, as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( 7 ), 'woocommerce-payments' ) );
		$this->assertSame( $scheduled_at, $scheduled_before );
	}

	/**
	 * @testdox Post-KYC completion schedules no activation emails when another request already claimed the gate: $_dataName.
	 * @dataProvider provider_post_kyc_gate_already_claimed
	 *
	 * @param callable $claim_gate Writes the gate marker as the concurrent request left it.
	 */
	public function test_post_kyc_completion_does_not_schedule_when_the_gate_is_claimed( callable $claim_gate ): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$claim_gate();

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		$this->assertSame( array(), $scheduler->scheduled_jobs, 'No activation email stages should be scheduled once the gate row exists.' );
	}

	/**
	 * Gate markers a concurrent request left behind.
	 *
	 * @return array<string,array{callable}>
	 */
	public static function provider_post_kyc_gate_already_claimed(): array {
		return array(
			'marker in the option cache' => array(
				static function (): void {
					update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );
				},
			),
			// The cache still records the option as missing, as it would for a read just before the other insert committed.
			// add_option() then skips its existence check and runs INSERT ... ON DUPLICATE KEY UPDATE, which affects no row
			// for the same value and returns false.
			'marker missed by the cache' => array(
				static function (): void {
					update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );
					wp_cache_delete( 'wcpay_post_kyc_activation_emails_scheduled', 'options' );
					$notoptions = wp_cache_get( 'notoptions', 'options' );
					$notoptions = is_array( $notoptions ) ? $notoptions : array();
					$notoptions['wcpay_post_kyc_activation_emails_scheduled'] = true;
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				},
			),
		);
	}

	/**
	 * @testdox Account refresh records post-KYC completion once and schedules staged emails.
	 */
	public function test_account_refresh_records_post_kyc_completion_and_schedules_staged_emails(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$service->register();
		update_option( 'wcpay_kyc_submitted_date', time() - HOUR_IN_SECONDS, false );

		/**
		 * Fires after WooPayments account data is refreshed.
		 *
		 * @since 11.0.0
		 */
		do_action(
			'woocommerce_payments_account_refreshed',
			array(
				'payments_enabled' => true,
				'is_live'          => true,
				'is_test_drive'    => false,
				'created'          => time() - MONTH_IN_SECONDS,
			)
		);

		$post_kyc_jobs = array_values(
			array_filter(
				$scheduler->scheduled_jobs,
				static fn( array $job ): bool => 'wcpay_post_kyc_activation_email_send' === $job['hook']
			)
		);

		$this->assertSame( '1', get_option( 'wcpay_post_kyc_activation_emails_scheduled' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'wcpay_kyc_completion_date', 0 ) );
		$this->assertSame(
			array( array( 7 ), array( 14 ), array( 30 ) ),
			array_column( $post_kyc_jobs, 'args' )
		);
	}

	/**
	 * @testdox Account refresh does not overwrite an existing post-KYC completion date.
	 */
	public function test_account_refresh_does_not_overwrite_existing_post_kyc_completion_date(): void {
		$scheduler         = new RecordingActionSchedulerService();
		$service           = $this->create_service( $scheduler );
		$existing_kyc_date = time() - YEAR_IN_SECONDS;

		update_option( 'wcpay_kyc_completion_date', $existing_kyc_date, false );
		$service->maybe_record_kyc_completion_date(
			array(
				'payments_enabled' => true,
				'is_live'          => true,
				'is_test_drive'    => false,
				'created'          => time() - MONTH_IN_SECONDS,
			)
		);

		$this->assertSame( $existing_kyc_date, (int) get_option( 'wcpay_kyc_completion_date', 0 ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox Post-KYC activation email registration preserves the WooPayments email settings key.
	 */
	public function test_post_kyc_activation_email_registration_preserves_settings_key(): void {
		$service = $this->create_service();
		$emails  = $service->add_post_kyc_activation_email( array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_Post_Kyc_Activation', $emails );
		$this->assertSame( 'wcpay_post_kyc_activation', $emails['WC_Payments_Email_Post_Kyc_Activation']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_wcpay_post_kyc_activation_settings', $emails['WC_Payments_Email_Post_Kyc_Activation']->get_option_key() );
		$this->assertSame( 'emails/post-kyc-activation.php', $emails['WC_Payments_Email_Post_Kyc_Activation']->template_html );
		$this->assertSame( 'emails/plain/post-kyc-activation.php', $emails['WC_Payments_Email_Post_Kyc_Activation']->template_plain );
	}

	/**
	 * @testdox IPP receipt email registration preserves the WooPayments email settings key and template paths.
	 */
	public function test_ipp_receipt_email_registration_preserves_settings_key_and_template_paths(): void {
		$service = $this->create_service();
		$emails  = $service->add_ipp_receipt_email( array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_IPP_Receipt', $emails );
		$this->assertInstanceOf( WooPaymentsIppReceiptEmail::class, $emails['WC_Payments_Email_IPP_Receipt'] );
		$this->assertSame( 'new_receipt', $emails['WC_Payments_Email_IPP_Receipt']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_new_receipt_settings', $emails['WC_Payments_Email_IPP_Receipt']->get_option_key() );
		$this->assertSame( 'emails/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_html );
		$this->assertSame( 'emails/plain/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_plain );
	}

	/**
	 * @testdox Post-KYC activation email jobs record the stage only after a delivery: $_dataName.
	 * @dataProvider provider_post_kyc_email_job_stage_recording
	 *
	 * @param callable             $send_mail            pre_wp_mail filter that decides the delivery.
	 * @param callable             $arrange_store        Sets up the store state the row needs.
	 * @param array<int,int>|false $expected_sent_stages The sent stages option after the job.
	 * @param string|false         $expected_live_sale   The live-sale marker option after the job.
	 */
	public function test_post_kyc_activation_email_job_records_the_stage_only_after_delivery( callable $send_mail, callable $arrange_store, $expected_sent_stages, $expected_live_sale ): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		$arrange_store();

		// Account flags per client 11.1.0 `includes/class-wc-payments-account.php:367-377`; card_payments in the
		// capabilities map, as the client's account validity check reads it (`:290-292`).
		$account_service = $this->create_account_service(
			array(
				'is_live'           => true,
				'is_test_drive'     => false,
				'payments_enabled'  => true,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments' => 'active',
				),
			),
			false,
			true,
			false
		);
		$service         = $this->create_service( new RecordingActionSchedulerService(), null, $account_service );
		$service->register();
		$this->reset_mailer_emails();

		add_filter( 'pre_wp_mail', $send_mail );
		try {
			$service->handle_wcpay_post_kyc_activation_email_send( 7 );
		} finally {
			remove_filter( 'pre_wp_mail', $send_mail );
		}

		$this->assertSame( $expected_sent_stages, get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
		$this->assertSame( $expected_live_sale, get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Delivery outcomes and store states, with the sent stages and live-sale marker each should leave.
	 *
	 * @return array<string,array{callable,callable,array<int,int>|false,string|false}>
	 */
	public static function provider_post_kyc_email_job_stage_recording(): array {
		$nothing = static function (): void {};

		return array(
			'delivered'                         => array( '__return_true', $nothing, array( 7 ), false ),
			// A later job can retry the stage.
			'delivery failed'                   => array( '__return_false', $nothing, false, false ),
			'the email registry omits it'       => array(
				'__return_true',
				static function (): void {
					add_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
				},
				false,
				false,
			),
			// The order exists before the service registers, so only the job's own live-sale check can find it.
			'the store already has a live sale' => array(
				'__return_true',
				static function (): void {
					$order = wc_create_order();
					$order->set_payment_method( 'woocommerce_payments' );
					$order->set_status( 'completed' );
					$order->update_meta_data( '_wcpay_mode', 'prod' );
					$order->save();
				},
				false,
				'1',
			),
		);
	}

	/**
	 * Create an operational queue service.
	 *
	 * @param WooPaymentsActionSchedulerService|null         $scheduler          Scheduler service.
	 * @param WooPaymentsApiClient|null                      $api_client         API client.
	 * @param WooPaymentsAccountService|null                 $account_service    Account service.
	 * @param WooPaymentsWooPayEligibility|null              $woopay_eligibility WooPay eligibility rules, or the container's.
	 * @param WooPaymentsDuplicateGatewayMethodsService|null $duplicate_gateway_methods_service Duplicate gateway methods service, or the container's.
	 * @return WooPaymentsOperationalQueueService
	 */
	private function create_service(
		?WooPaymentsActionSchedulerService $scheduler = null,
		?WooPaymentsApiClient $api_client = null,
		?WooPaymentsAccountService $account_service = null,
		?WooPaymentsWooPayEligibility $woopay_eligibility = null,
		?WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service = null
	): WooPaymentsOperationalQueueService {
		$service = new WooPaymentsOperationalQueueService();
		$service->init(
			$scheduler ?? new RecordingActionSchedulerService(),
			$api_client ?? $this->create_api_client( array() ),
			$account_service ?? $this->create_account_service(),
			$duplicate_gateway_methods_service ?? wc_get_container()->get( WooPaymentsDuplicateGatewayMethodsService::class ),
			$woopay_eligibility ?? wc_get_container()->get( WooPaymentsWooPayEligibility::class )
		);

		$this->services[] = $service;

		return $service;
	}

	/**
	 * Create a WooPayments API client mock.
	 *
	 * @param string[] $methods Mocked method names.
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client( array $methods ): WooPaymentsApiClient {
		$builder = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor();

		if ( ! empty( $methods ) ) {
			$builder->onlyMethods( $methods );
		}

		return $builder->getMock();
	}

	/**
	 * Create an account service mock.
	 *
	 * @param array<string,mixed> $account_data         Account data returned by the mock.
	 * @param bool                $test_mode            Whether test mode is enabled.
	 * @param bool                $can_process_payments Whether the account can process payments.
	 * @param bool                $test_account         Whether the account is a test-drive account.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $account_data = array(), bool $test_mode = true, bool $can_process_payments = true, bool $test_account = false ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_cached_account_data', 'can_process_payments', 'has_test_account', 'is_payment_request_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'can_process_payments' )->willReturn( $can_process_payments );
		$account_service->method( 'has_test_account' )->willReturn( $test_account );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( true );
		// Gateway settings are read through the real get_gateway_setting(), which needs the proxy.
		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( wc_get_container()->get( LegacyProxy::class ) );
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), $database_cache );

		return $account_service;
	}

	/**
	 * Remove registered operational hooks for a service.
	 *
	 * @param WooPaymentsOperationalQueueService $service Service instance.
	 */
	private function remove_operational_hooks( WooPaymentsOperationalQueueService $service ): void {
		remove_action( 'wcpay_store_setup_sync', array( $service, 'handle_wcpay_store_setup_sync' ) );
		remove_action( 'wcpay_update_compatibility_data', array( $service, 'handle_wcpay_update_compatibility_data' ) );
		remove_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) );
		remove_action( 'add_option_wcpay_kyc_completion_date', array( $service, 'handle_add_option_wcpay_kyc_completion_date' ) );
		remove_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) );
		remove_action( 'admin_init', array( $service, 'handle_wcpay_post_kyc_activation_email_cta' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'schedule_compatibility_data_update' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_record_kyc_completion_date' ) );
		remove_action( 'after_switch_theme', array( $service, 'schedule_compatibility_data_update' ) );
		remove_action( 'action_scheduler_ensure_recurring_actions', array( $service, 'schedule_recurring_actions' ) );
		remove_action( 'updated_option', array( $service, 'handle_site_language_update' ) );
		remove_action( 'update_option_woocommerce_coming_soon', array( $service, 'handle_store_launch' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $service, 'maybe_add_missing_currencies' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $service, 'maybe_handle_test_mode_toggle' ) );
		remove_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) );
	}


	/**
	 * Rebuild WooCommerce's cached email registry under the currently registered filters.
	 */
	private function reset_mailer_emails(): void {
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			WC()->mailer()->emails = array();
			WC()->mailer()->init();
		}
	}

	/**
	 * Get preserved instant deposit note IDs.
	 *
	 * @return int[]
	 */
	private function get_instant_deposit_note_ids(): array {
		$data_store = WC_Data_Store::load( 'admin-note' );
		$note_ids   = $data_store->get_notes_with_name( 'wc-payments-notes-instant-deposits-eligible' );

		return array_map( 'absint', $note_ids );
	}

	/**
	 * Delete preserved instant deposit notes.
	 */
	private function delete_instant_deposit_note(): void {
		$data_store = WC_Data_Store::load( 'admin-note' );
		foreach ( $this->get_instant_deposit_note_ids() as $note_id ) {
			$note = \Automattic\WooCommerce\Admin\Notes\Notes::get_note( $note_id );
			if ( $note instanceof \Automattic\WooCommerce\Admin\Notes\Note ) {
				$data_store->delete( $note );
			}
		}
	}

	/**
	 * Create the WooPay eligibility rules over an account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsWooPayEligibility
	 */
	private function create_woopay_eligibility( WooPaymentsAccountService $account_service ): WooPaymentsWooPayEligibility {
		$woopay_eligibility = new WooPaymentsWooPayEligibility();
		$woopay_eligibility->init( $account_service );

		return $woopay_eligibility;
	}
}
