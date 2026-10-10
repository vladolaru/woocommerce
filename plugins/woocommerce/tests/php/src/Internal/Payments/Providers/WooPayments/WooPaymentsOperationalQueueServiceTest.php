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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminNoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Data_Store;
use WC_Order;
use WC_Unit_Test_Case;

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
		$this->delete_notes_with_name( 'wc-payments-notes-test-to-live' );
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
	 * @testdox Operational queue hooks are registered when native owns runtime.
	 */
	public function test_registers_preserved_operational_hooks_when_native_owns_runtime(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );

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
	 * @testdox Operational queue hooks are not registered when plugin owns runtime.
	 */
	public function test_registers_no_operational_hooks_when_plugin_owns_runtime(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( false ) );

		$service->register();

		$this->assertFalse( has_action( 'wcpay_store_setup_sync', array( $service, 'handle_wcpay_store_setup_sync' ) ) );
		$this->assertFalse( has_action( 'wcpay_update_compatibility_data', array( $service, 'handle_wcpay_update_compatibility_data' ) ) );
		$this->assertFalse( has_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) ) );
		$this->assertFalse( has_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) ) );
	}

	/**
	 * @testdox A live WooPayments paid-status transition records the sale and invalidates cached post-KYC eligibility.
	 */
	public function test_live_woopayments_order_status_transition_invalidates_post_kyc_eligibility(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$service->register();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
	 */
	public function test_paid_order_status_transition_invalidates_one_and_done_cache( string $payment_method, string $mode, string $cached_value ): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$order   = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		if ( '' !== $mode ) {
			$order->update_meta_data( '_wcpay_mode', $mode );
		}
		$order->save();
		update_option( 'wcpay_has_live_sale', '1', false );
		set_transient( 'wcpay_one_and_done_eligible', $cached_value, HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order->get_id(), OrderStatus::PENDING, OrderStatus::PROCESSING, $order );

		$this->assertFalse( get_transient( 'wcpay_one_and_done_eligible' ) );
		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Relevant transitions and both cached results.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function provide_one_and_done_cache_invalidations(): array {
		return array(
			'second live WooPayments order with positive cache' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', '1' ),
			'alternate gateway order with negative cache' => array( 'cod', '', '0' ),
		);
	}

	/**
	 * @testdox A paid transition skips order mode metadata when no eligibility cache remains after a live sale.
	 */
	public function test_paid_order_status_transition_without_eligibility_cache_skips_mode_meta(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$order   = $this->createMock( WC_Order::class );
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
		$service  = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
		$service  = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
	 * @testdox Referrer CTA handler skips recording and never redirects when the stage is not allowlisted.
	 */
	public function test_referrer_cta_skips_recording_with_non_allowlisted_stage(): void {
		$service  = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['wcpay_referrer'] = 'post_kyc_email';
		// Stage 3 is not in POST_KYC_STAGE_DAYS.
		$_GET['wcpay_referrer_stage'] = '3';

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

		$this->assertFalse( $redirected, 'A non-allowlisted stage must not record analytics or trigger a redirect.' );
	}

	/**
	 * @testdox Referrer CTA handler skips recording and never redirects without the manage_woocommerce capability.
	 */
	public function test_referrer_cta_skips_recording_without_manage_woocommerce(): void {
		$service     = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );

		$_GET['wcpay_referrer']       = 'post_kyc_email';
		$_GET['wcpay_referrer_stage'] = '7';

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

		$this->assertFalse( $redirected, 'A user without manage_woocommerce must not record analytics or trigger a redirect.' );
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
							&& true === $snapshot['woopay']['enabled']
							&& array( 'product', 'checkout' ) === $snapshot['woopay']['enabled_locations'];
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'fees' => array(
					'card'  => array(),
					'ideal' => array(),
				),
			)
		);
		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $account_service )->handle_wcpay_store_setup_sync();
	}

	/**
	 * @testdox Should report real duplicate gateways and the Multi-Currency flag in the store setup snapshot.
	 */
	public function test_store_setup_sync_reports_duplicates_and_multi_currency(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		update_option( '_wcpay_feature_customer_multi_currency', '0' );

		$settings_service = $this->getMockBuilder( WooPaymentsSettingsService::class )
			->onlyMethods( array( 'get_duplicated_payment_method_ids' ) )
			->getMock();
		$settings_service->method( 'get_duplicated_payment_method_ids' )
			->willReturn( array( 'card' => array( 'woocommerce_payments', 'stripe' ) ) );

		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'send_store_setup' )
			->with(
				$this->callback(
					function ( array $snapshot ): bool {
						return array( 'card' => array( 'woocommerce_payments', 'stripe' ) ) === $snapshot['payment_methods']['duplicates']
							&& false === $snapshot['multi_currency_enabled']
							&& false === $snapshot['stripe_billing_enabled'];
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, null, $settings_service )->handle_wcpay_store_setup_sync();
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
		$module->init( new StaticWooPaymentsRuntimeArbiter( true ) );
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

		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client )->handle_wcpay_store_setup_sync();

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
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json `account.fees`.
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
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json `account.fees`.
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
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json `account.fees`.
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
	 * Run the store setup sync and return the one snapshot it sends to the platform.
	 *
	 * @param array<string,mixed>            $settings        Gateway settings to store first.
	 * @param WooPaymentsAccountService|null $account_service Account service, or the default double.
	 * @return array<string,mixed>
	 */
	private function send_store_setup_snapshot( array $settings = array( 'enabled' => 'yes' ), ?WooPaymentsAccountService $account_service = null ): array {
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

		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $account_service )->handle_wcpay_store_setup_sync();

		$this->assertCount( 1, $snapshots, 'The store setup sync sends one snapshot.' );

		return $snapshots[0];
	}

	/**
	 * @testdox Should report Multi-Currency as enabled by default when the flag option was never written, matching the plugin.
	 */
	public function test_store_setup_sync_defaults_multi_currency_flag_to_enabled(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		delete_option( '_wcpay_feature_customer_multi_currency' );

		$settings_service = $this->getMockBuilder( WooPaymentsSettingsService::class )
			->onlyMethods( array( 'get_duplicated_payment_method_ids' ) )
			->getMock();
		$settings_service->method( 'get_duplicated_payment_method_ids' )
			->willReturn( array() );

		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'send_store_setup' )
			->with(
				$this->callback(
					function ( array $snapshot ): bool {
						return true === $snapshot['multi_currency_enabled']
							&& array() === $snapshot['payment_methods']['duplicates'];
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, null, $settings_service )->handle_wcpay_store_setup_sync();
	}

	/**
	 * @testdox Should propagate a site-language change to the connected account's locale.
	 */
	public function test_site_language_change_propagates_locale_to_account(): void {
		update_option( 'WPLANG', '' );
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->expects( $this->once() )
			->method( 'update_account' )
			->with( array( 'locale' => 'de_DE' ) )
			->willReturn( array() );
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( array( 'account_id' => 'acct_native_test' ) ) );
		$service->register();

		update_option( 'WPLANG', 'de_DE' );
	}

	/**
	 * @testdox Should send the en_US locale fallback when the site language is reset to the default.
	 */
	public function test_site_language_reset_sends_default_locale(): void {
		update_option( 'WPLANG', 'de_DE' );
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->expects( $this->once() )
			->method( 'update_account' )
			->with( array( 'locale' => 'en_US' ) )
			->willReturn( array() );
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( array( 'account_id' => 'acct_native_test' ) ) );
		$service->register();

		update_option( 'WPLANG', '' );
	}

	/**
	 * @testdox Should not contact the platform for a language change without a connected account.
	 */
	public function test_site_language_change_without_account_skips_platform(): void {
		update_option( 'WPLANG', '' );
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->expects( $this->never() )
			->method( 'update_account' );
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( array() ) );
		$service->register();

		update_option( 'WPLANG', 'de_DE' );
	}

	/**
	 * @testdox Should re-send the account's business URL once when the store goes live.
	 */
	public function test_store_launch_resends_business_url(): void {
		update_option( 'woocommerce_coming_soon', 'yes' );
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->expects( $this->once() )
			->method( 'update_account' )
			->with( array( 'business_url' => 'https://shop.example.com' ) )
			->willReturn( array() );
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( self::get_account_with_business_url() ) );
		$service->register();

		update_option( 'woocommerce_coming_soon', 'no' );
	}

	/**
	 * @testdox Should not contact the platform when the store does not go live, has no account or has no business URL.
	 * @testWith ["store goes private", "no", "yes", true, true]
	 *           ["no connected account", "yes", "no", false, true]
	 *           ["no business URL", "yes", "no", true, false]
	 *
	 * @param string $scenario         Scenario label.
	 * @param string $old_value        Coming-soon value before the write.
	 * @param string $new_value        Coming-soon value written.
	 * @param bool   $has_account      Whether the store has a connected account.
	 * @param bool   $has_business_url Whether the account has a business URL.
	 */
	public function test_store_launch_skips_platform( string $scenario, string $old_value, string $new_value, bool $has_account, bool $has_business_url ): void {
		unset( $scenario );
		update_option( 'woocommerce_coming_soon', $old_value );
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->expects( $this->never() )->method( 'update_account' );
		$account_data = $has_business_url ? self::get_account_with_business_url() : array( 'account_id' => 'acct_native_test' );
		$service      = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( $has_account ? $account_data : array() ) );
		$service->register();

		update_option( 'woocommerce_coming_soon', $new_value );
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
	 * @testdox Should auto-enable the currencies a newly enabled payment method requires.
	 */
	public function test_settings_save_auto_adds_currencies_for_enabled_methods(): void {
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		$this->register_unavailable_rate_provider();
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

		$account_service = $this->create_account_service(
			array(
				'account_id'       => 'acct_native_test',
				'country'          => 'US',
				'store_currencies' => array( 'default' => 'usd' ),
			)
		);
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $account_service );
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) ) );

		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies' );
		$this->assertIsArray( $enabled_currencies );
		$this->assertContains( 'EUR', $enabled_currencies, 'Enabling iDEAL must auto-enable its EUR requirement.' );
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

		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $this->create_account_service( array( 'account_id' => 'acct_native_test' ) ) );
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
	 * @testdox Test-to-live inbox sync should be unhooked while the test-mode clock stays active.
	 */
	public function test_test_to_live_inbox_sync_is_unhooked(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$service->register();

		$this->assertFalse( has_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_sync_test_to_live_inbox_note' ) ) );
		$this->assertFalse( has_action( 'wcpay_store_setup_sync', array( $service, 'maybe_sync_test_to_live_inbox_note' ) ) );
		$this->assertNotFalse( has_action( 'update_option_' . WooPaymentsSettingsService::SETTINGS_OPTION, array( $service, 'maybe_handle_test_mode_toggle' ) ) );
	}

	/**
	 * @testdox The deprecated test-to-live inbox method should only remove old notes.
	 */
	public function test_test_to_live_inbox_note_follows_eligibility(): void {
		update_option( 'wcpay_test_mode_enabled_date', time() - 8 * DAY_IN_SECONDS, false );
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();

		$eligible_account = $this->create_account_service(
			array(
				'account_id'       => 'acct_native_test',
				'payments_enabled' => true,
			),
			true
		);
		$service          = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $eligible_account );
		$note             = new \Automattic\WooCommerce\Admin\Notes\Note();
		$note->set_name( 'wc-payments-notes-test-to-live' );
		$note->set_title( 'Previous inbox nudge' );
		$note->set_content( 'Previous inbox nudge' );
		$note->set_type( \Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->save();
		$this->assertNotEmpty( $this->get_note_ids_with_name( 'wc-payments-notes-test-to-live' ) );

		$service->maybe_sync_test_to_live_inbox_note();
		$service->maybe_sync_test_to_live_inbox_note();
		$this->assertEmpty( $this->get_note_ids_with_name( 'wc-payments-notes-test-to-live' ) );
	}

	/**
	 * @testdox Recurring store setup sync is scheduled once in the WooPayments group.
	 */
	public function test_schedule_recurring_actions_schedules_store_setup_sync_once(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );

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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new WooPaymentsActionSchedulerService(), $api_client );
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
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );
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

		$this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client )->handle_wcpay_update_compatibility_data();
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), $api_client, $this->create_account_service( self::get_account_with_business_url() ) );
		$logger  = RecordingWcLogger::install();

		switch ( $job ) {
			case 'site language':
				$service->handle_site_language_update( 'WPLANG', '', 'de_DE' );
				$expected = 'Failed to propagate the site language to the WooPayments account locale.';
				break;
			case 'store setup sync':
				$service->handle_wcpay_store_setup_sync();
				$expected = null;
				break;
			case 'compatibility data update':
				$service->handle_wcpay_update_compatibility_data();
				$expected = null;
				break;
			case 'store launch':
				$service->handle_store_launch( 'yes', 'no' );
				$expected = 'Failed to re-send the WooPayments business URL after the store went live.';
				break;
		}

		$errors = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] && 'woopayments' === $line[2] ) );
		$this->assertCount( 1, $errors );
		if ( null !== $expected ) {
			$this->assertSame( $expected, $logger->lines[ $errors[0] ][1] );
		}
		$this->assertSame( array( 404, 'resource_missing' ), array( $logger->contexts[ $errors[0] ]['http_status'], $logger->contexts[ $errors[0] ]['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Instant deposit eligibility refresh creates the preserved inbox note and reminder.
	 */
	public function test_instant_deposit_eligibility_refresh_creates_note_and_reminder(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );

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
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );

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
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler, null, $account_service );

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
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );
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
	 * @testdox Post-KYC completion schedules activation emails under the plugin's post-KYC group.
	 *
	 * Client 11.1.0 class-wc-payments-post-kyc-activation-email-service.php:113 schedules each stage
	 * under the literal 'woocommerce-payments', not the scheduler service's 'woocommerce_payments'.
	 */
	public function test_post_kyc_completion_schedules_emails_under_plugin_group(): void {
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new WooPaymentsActionSchedulerService() );

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
	 * @testdox Post-KYC completion does not re-schedule activation emails when the scheduled marker already exists.
	 */
	public function test_post_kyc_completion_does_not_reschedule_when_marker_already_exists(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );

		// Simulate a concurrent account-refresh handler that already won the scheduling gate.
		update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		$this->assertSame( array(), $scheduler->scheduled_jobs, 'No activation email stages should be scheduled when the gate marker already exists.' );
	}

	/**
	 * @testdox Post-KYC completion uses an atomic insert so a concurrent loser schedules nothing.
	 */
	public function test_post_kyc_completion_atomic_gate_blocks_concurrent_loser(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );

		// A concurrent caller already inserted the gate row, while this request's cache still records the option as
		// missing, as it would if it read just before that insert committed. add_option() then skips its own existence
		// check and runs INSERT ... ON DUPLICATE KEY UPDATE, which affects no row for the same value and returns false.
		update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );
		wp_cache_delete( 'wcpay_post_kyc_activation_emails_scheduled', 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : array();
		$notoptions['wcpay_post_kyc_activation_emails_scheduled'] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		$this->assertSame(
			array(),
			$scheduler->scheduled_jobs,
			'A concurrent loser must schedule nothing once the gate row exists, even when the cached read misses it.'
		);
	}

	/**
	 * @testdox Account refresh records post-KYC completion once and schedules staged emails.
	 */
	public function test_account_refresh_records_post_kyc_completion_and_schedules_staged_emails(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );
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
		$service           = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), $scheduler );
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
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
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
		$this->assertTrue( class_exists( WooPaymentsIppReceiptEmail::class ), 'Native IPP receipt email class should exist before it can be registered.' );
		$service = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ) );
		$this->assertTrue( method_exists( $service, 'add_ipp_receipt_email' ), 'Operational queue service should register the native IPP receipt email.' );
		$emails = $service->add_ipp_receipt_email( array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_IPP_Receipt', $emails );
		$this->assertInstanceOf( WooPaymentsIppReceiptEmail::class, $emails['WC_Payments_Email_IPP_Receipt'] );
		$this->assertSame( 'new_receipt', $emails['WC_Payments_Email_IPP_Receipt']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_new_receipt_settings', $emails['WC_Payments_Email_IPP_Receipt']->get_option_key() );
		$this->assertSame( 'emails/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_html );
		$this->assertSame( 'emails/plain/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_plain );
	}

	/**
	 * @testdox Post-KYC activation email jobs mark the stage only after successful delivery.
	 */
	public function test_post_kyc_activation_email_job_marks_stage_after_successful_delivery(): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		add_filter( 'pre_wp_mail', '__return_true' );

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
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $account_service );
		$service->register();
		$this->reset_mailer_emails();

		$service->handle_wcpay_post_kyc_activation_email_send( 7 );

		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertSame( array( 7 ), get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
	}

	/**
	 * @testdox Post-KYC activation email jobs leave the stage unrecorded when delivery fails, so a later job can retry it.
	 */
	public function test_post_kyc_activation_email_job_leaves_stage_unrecorded_after_failed_delivery(): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		add_filter( 'pre_wp_mail', '__return_false' );

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
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $account_service );
		$service->register();
		$this->reset_mailer_emails();

		$service->handle_wcpay_post_kyc_activation_email_send( 7 );

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
	}

	/**
	 * @testdox Post-KYC activation email jobs fail closed when the WooCommerce email registry omits the preserved email.
	 */
	public function test_post_kyc_activation_email_job_skips_when_email_registry_omits_preserved_email(): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
		$this->reset_mailer_emails();

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
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $account_service );

		$service->handle_wcpay_post_kyc_activation_email_send( 7 );

		remove_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
	}

	/**
	 * @testdox Post-KYC activation email jobs do not consume stages when the merchant already has a live sale.
	 */
	public function test_post_kyc_activation_email_job_skips_live_sale_stores(): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		add_filter( 'pre_wp_mail', '__return_true' );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();

		$account_service = $this->create_account_service(
			array(
				'is_live'           => true,
				'is_test_drive'     => false,
				'payments_enabled'  => true,
				'details_submitted' => true,
			),
			false,
			true,
			false
		);
		$service         = $this->create_service( new StaticWooPaymentsRuntimeArbiter( true ), new RecordingActionSchedulerService(), null, $account_service );

		$service->handle_wcpay_post_kyc_activation_email_send( 7 );

		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Create an operational queue service.
	 *
	 * @param WooPaymentsRuntimeArbiter              $arbiter            Runtime arbiter.
	 * @param WooPaymentsActionSchedulerService|null $scheduler          Scheduler service.
	 * @param WooPaymentsApiClient|null              $api_client         API client.
	 * @param WooPaymentsAccountService|null         $account_service    Account service.
	 * @param WooPaymentsSettingsService|null        $settings_service   Settings service.
	 * @return WooPaymentsOperationalQueueService
	 */
	private function create_service(
		WooPaymentsRuntimeArbiter $arbiter,
		?WooPaymentsActionSchedulerService $scheduler = null,
		?WooPaymentsApiClient $api_client = null,
		?WooPaymentsAccountService $account_service = null,
		?WooPaymentsSettingsService $settings_service = null
	): WooPaymentsOperationalQueueService {
		$service = new WooPaymentsOperationalQueueService();
		$service->init(
			$arbiter,
			$scheduler ?? new RecordingActionSchedulerService(),
			$api_client ?? $this->create_api_client( array() ),
			$account_service ?? $this->create_account_service(),
			$settings_service
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
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );

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
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_sync_test_to_live_inbox_note' ) );
		remove_action( 'wcpay_store_setup_sync', array( $service, 'maybe_sync_test_to_live_inbox_note' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) );
	}

	/**
	 * Get note IDs stored under a name.
	 *
	 * @param string $name Note name.
	 * @return array<int|string>
	 */
	private function get_note_ids_with_name( string $name ): array {
		$data_store = \Automattic\WooCommerce\Admin\Notes\Notes::load_data_store();

		return $data_store->get_notes_with_name( $name );
	}

	/**
	 * Delete all notes stored under a name.
	 *
	 * @param string $name Note name.
	 */
	private function delete_notes_with_name( string $name ): void {
		foreach ( $this->get_note_ids_with_name( $name ) as $note_id ) {
			$note = \Automattic\WooCommerce\Admin\Notes\Notes::get_note( (int) $note_id );
			if ( $note instanceof \Automattic\WooCommerce\Admin\Notes\Note ) {
				$note->delete();
			}
		}
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
}
