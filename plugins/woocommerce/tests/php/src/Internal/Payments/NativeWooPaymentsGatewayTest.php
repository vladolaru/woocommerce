<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedTransactionRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Legacy as StoreApiLegacy;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext as StoreApiPaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult as StoreApiPaymentResult;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the NativeWooPaymentsGateway class.
 */
class NativeWooPaymentsGatewayTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'woocommerce_checkout_subscription_created' );
		remove_all_actions( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID );
		remove_all_actions( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay' );
		remove_all_actions( 'woocommerce_subscription_failing_payment_method_updated_' . OrderPaymentStore::GATEWAY_ID );
		remove_all_actions( 'woocommerce_subscription_failing_payment_method_updated_woocommerce_payments_amazon_pay' );
		remove_all_filters( 'woocommerce_subscription_payment_meta' );
		remove_all_actions( 'woocommerce_subscription_validate_payment_meta' );
		remove_all_actions( 'wcs_save_other_payment_meta' );
		remove_all_filters( 'wcs_copy_payment_meta_to_order' );
		remove_all_filters( 'woocommerce_my_subscriptions_payment_method' );
		remove_all_filters( 'woocommerce_subscription_payment_method_to_display' );
		remove_all_filters( 'wcs_view_subscription_actions' );
		remove_all_filters( 'user_has_cap' );
		remove_all_filters( 'woocommerce_subscription_note_old_payment_method_title' );
		remove_all_filters( 'woocommerce_subscription_note_new_payment_method_title' );
		remove_all_actions( 'woocommerce_admin_order_data_after_billing_address' );
		remove_all_filters( 'woocommerce_subscriptions_update_subscription_token' );
		remove_all_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode' );
		remove_all_actions( 'wp_ajax_wcpay_get_user_payment_tokens' );
		remove_all_actions( 'woocommerce_woocommerce_payments_payment_requires_action' );
		remove_all_filters( 'woocommerce_woopayments_subscriptions_for_renewal_order' );
		$subscription_handlers = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'has_attached_subscription_handlers' );
		$subscription_handlers->setValue( null, false );
		remove_all_filters( 'woocommerce_email_classes' );
		remove_all_filters( 'wcs_get_retry_rule_raw' );
		unset( $_POST['wcpay-setup-intent'] );
		unset( $_POST['wcpay-payment-method'] );
		unset( $_POST['wcpay-is-platform-payment-method'] );
		unset( $_POST['wcpay-express-payment-method-types'] );
		unset( $_POST['wcpay-express-checkout-context'] );
		unset( $_POST['wcpay-fraud-prevention-token'] );
		unset( $_POST['is-woopay-preflight-check'] );
		unset( $_POST['woocommerce_pay'] );
		unset( $_POST['_wcsnonce'] );
		unset( $_POST['change_payment_method'] );
		unset( $_POST['woocommerce_change_payment'] );
		unset( $_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] );
		unset( $_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-new-payment-method' ] );
		unset( $_POST['update_all_subscriptions_payment_method'] );
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'], $GLOBALS['wcpay_test_cart_contains_subscription'] );
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'reset' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
		}
		wc_clear_notices();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Should preserve the WooPayments gateway identity and settings option key.
	 */
	public function test_preserves_gateway_identity(): void {
		$this->with_gateway_settings(
			array( 'saved_cards' => 'no' ),
			function (): void {
				$gateway = new NativeWooPaymentsGateway();

				$this->assertSame( OrderPaymentStore::GATEWAY_ID, $gateway->id );
				$this->assertSame( 'woocommerce_woocommerce_payments_settings', $gateway->get_option_key() );
				$this->assertSame( 'Card', $gateway->title );
				$this->assertStringContainsString( '/assets/images/payment-methods/visa.svg', $gateway->get_icon() );
				$this->assertStringContainsString( 'alt="Visa"', $gateway->get_icon() );
				$this->assertStringContainsString( '/assets/images/payment-methods/mastercard.svg', $gateway->get_icon() );
				$this->assertStringContainsString( '+ 3', $gateway->get_icon() );
				$this->assertContains( 'products', $gateway->supports );
				$this->assertContains( 'refunds', $gateway->supports );
				$this->assertNotContains( PaymentGatewayFeature::TOKENIZATION, $gateway->supports );
				$this->assertNotContains( PaymentGatewayFeature::ADD_PAYMENT_METHOD, $gateway->supports );
				$this->assertNotContains( 'subscriptions', $gateway->supports );
			}
		);
	}

	/**
	 * @testdox Should hide a split gateway when its definition does not support the checkout currency.
	 */
	public function test_split_gateway_availability_follows_payment_method_definition(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'BE',
				'capabilities' => array( 'bancontact_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency        = 'USD';
		$currency_filter = static function () use ( &$currency ): string {
			return $currency;
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available() );

			$currency = 'EUR';

			$this->assertTrue( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Shopper country rules should not restrict gateway availability by merchant country.
	 * @dataProvider payment_method_country_availability_provider
	 *
	 * @param string   $payment_method_id           Payment method ID.
	 * @param string   $account_country             Merchant account country.
	 * @param string   $currency                    Checkout currency.
	 * @param string   $capability_key              Active payment method capability key.
	 * @param string[] $expected_shopper_countries Expected shopper billing countries.
	 */
	public function test_gateway_availability_keeps_shopper_country_rules_separate_from_merchant_country_rules(
		string $payment_method_id,
		string $account_country,
		string $currency,
		string $capability_key,
		array $expected_shopper_countries
	): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
		$this->assertNotNull( $definition );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function () use ( $currency ): string {
			return $currency;
		};
		$settings_option = "pre_option_woocommerce_woocommerce_payments_{$payment_method_id}_settings";
		$previous_cart   = WC()->cart;
		WC()->cart       = new \WC_Cart();
		WC()->cart->set_total( '0' );
		add_filter( $settings_option, $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( $account_country, $capability_key ) );

			$this->assertTrue( $gateway->is_available(), "{$payment_method_id} should be admitted for a {$account_country} merchant accepting {$currency}." );
			$this->assertSame(
				$expected_shopper_countries,
				$gateway->get_payment_method_definition()->get_supported_countries( $account_country ),
				"{$payment_method_id} should preserve its shopper billing-country rules."
			);
		} finally {
			remove_filter( $settings_option, $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			WC()->cart = $previous_cart;
		}
	}

	/**
	 * Data provider for merchant and shopper country availability scenarios.
	 *
	 * @return array<string,array{string,string,string,string,string[]}>
	 */
	public function payment_method_country_availability_provider(): array {
		return array(
			'Bancontact admits a US merchant while preserving Belgian shopper filtering' => array(
				'bancontact',
				'US',
				'EUR',
				'bancontact_payments',
				array( 'BE' ),
			),
			'Affirm admits a nonmatching merchant while preserving domestic shopper filtering' => array(
				'affirm',
				'NL',
				'USD',
				'affirm_payments',
				array( 'US', 'CA' ),
			),
			'Afterpay admits a nonmatching merchant while preserving domestic shopper filtering' => array(
				'afterpay_clearpay',
				'NL',
				'USD',
				'afterpay_clearpay_payments',
				array( 'US', 'CA', 'AU', 'NZ', 'GB' ),
			),
			'Klarna admits a nonmatching EEA merchant while preserving calculated shopper filtering' => array(
				'klarna',
				'PL',
				'EUR',
				'klarna_payments',
				array( 'AT', 'BE', 'FI', 'FR', 'DE', 'IE', 'IT', 'NL', 'ES' ),
			),
			'Alipay preserves unrestricted shopper filtering' => array(
				'alipay',
				'US',
				'USD',
				'alipay_payments',
				array(),
			),
			'Affirm limits shopper filtering to the US for a US merchant' => array(
				'affirm',
				'US',
				'USD',
				'affirm_payments',
				array( 'US' ),
			),
			'Cash App Afterpay limits shopper filtering to the US for a US merchant' => array(
				'afterpay_clearpay',
				'US',
				'USD',
				'afterpay_clearpay_payments',
				array( 'US' ),
			),
		);
	}

	/**
	 * @testdox Should hide a split gateway when its gateway setting is disabled.
	 */
	public function test_split_gateway_availability_requires_enabled_setting(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'no' );
		};
		$currency_filter = static function (): string {
			return 'EUR';
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'BE', 'bancontact_payments' ) );

			$this->assertFalse( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should hide split gateways when the canonical WooPayments gateway is disabled.
	 */
	public function test_split_gateway_availability_requires_master_gateway(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'BE',
				'capabilities' => array( 'bancontact_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( false );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'EUR';
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should apply provider readiness and explicit account capability state at availability time.
	 */
	public function test_gateway_availability_requires_provider_and_capability_readiness(): void {
		$account_data    = array(
			'country'      => 'US',
			'capabilities' => array( 'card_payments' => 'restricted' ),
		);
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( &$account_data ): array {
				return $account_data;
			}
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$provider_ready = true;
		$provider       = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturnCallback(
			static function () use ( &$provider_ready ): bool {
				return $provider_ready;
			}
		);

		$this->with_gateway_settings(
			array( 'enabled' => 'yes' ),
			function () use ( &$account_data, &$provider_ready, $account_service, $provider ): void {
				$gateway = new NativeWooPaymentsGateway();
				$gateway->init( new RecordingPaymentProcessingService(), $provider, null, null, $account_service );

				$this->assertFalse( $gateway->is_available(), 'An explicit restricted card capability must remain unavailable.' );

				$account_data['capabilities']['card_payments'] = 'active';
				$provider_ready                                = false;
				$this->assertFalse( $gateway->is_available(), 'An unavailable provider transport/account must remain unavailable.' );

				$provider_ready = true;
				$this->assertTrue( $gateway->is_available() );
			}
		);
	}

	/**
	 * @testdox Should hide an internal payment method that is not placed as a checkout gateway.
	 */
	public function test_gateway_availability_requires_checkout_gateway_placement(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'link' );
		$this->assertNotNull( $definition );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'USD';
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_link_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, null, $this->create_account_service_for_country( 'US' ) );

			$this->assertFalse( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_link_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should only place express gateways in the payment-method list when both placement controls are enabled.
	 */
	public function test_express_gateway_availability_requires_payment_method_list_placement(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'apple_pay' );
		$this->assertNotNull( $definition );

		$canonical_settings = array( 'express_checkout_in_payment_methods' => 'no' );
		$feature_flag       = '1';
		$account_service    = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( &$canonical_settings ) {
				return $canonical_settings[ $key ] ?? $fallback;
			}
		);
		$split_settings  = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$feature_filter  = static function () use ( &$feature_flag ): string {
			return $feature_flag;
		};
		$currency_filter = static function (): string {
			return 'USD';
		};

		add_filter( 'pre_option_woocommerce_woocommerce_payments_apple_pay_settings', $split_settings );
		add_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available(), 'The gateway setting must enable payment-method-list placement.' );

			$canonical_settings['express_checkout_in_payment_methods'] = 'yes';
			$feature_flag = '0';
			$this->assertFalse( $gateway->is_available(), 'The dynamic checkout feature flag must also be enabled.' );

			$feature_flag = '1';
			$this->assertTrue( $gateway->is_available(), 'Both placement controls should expose the express gateway.' );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_apple_pay_settings', $split_settings );
			remove_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should apply definition amount limits to split gateway availability.
	 */
	public function test_split_gateway_availability_respects_definition_amount_limits(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'affirm' );
		$this->assertNotNull( $definition );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'USD';
		};
		$previous_cart   = WC()->cart;
		WC()->cart       = new \WC_Cart();
		add_filter( 'pre_option_woocommerce_woocommerce_payments_affirm_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'US', 'affirm_payments' ) );

			WC()->cart->set_total( '34.99' );
			$this->assertFalse( $gateway->is_available(), 'Amounts below the definition minimum should be unavailable.' );

			WC()->cart->set_total( '35.00' );
			$this->assertTrue( $gateway->is_available(), 'The definition minimum should be available.' );

			WC()->cart->set_total( '30000.00' );
			$this->assertTrue( $gateway->is_available(), 'The definition maximum should be available.' );

			WC()->cart->set_total( '30000.01' );
			$this->assertFalse( $gateway->is_available(), 'Amounts above the definition maximum should be unavailable.' );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_affirm_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			WC()->cart = $previous_cart;
		}
	}

	/**
	 * @testdox Live checkout requires HTTPS while test mode remains available over HTTP.
	 */
	public function test_gateway_availability_requires_https_only_in_live_mode(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$is_test_mode    = false;
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'BE',
				'capabilities' => array( 'bancontact_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturnCallback(
			static function () use ( &$is_test_mode ): bool {
				return $is_test_mode;
			}
		);

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'EUR';
		};
		$ssl_checkout    = 'no';
		$ssl_filter      = static function () use ( &$ssl_checkout ): string {
			return $ssl_checkout;
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );
		add_filter( 'pre_option_woocommerce_force_ssl_checkout', $ssl_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available() );

			$ssl_checkout = 'yes';
			$this->assertTrue( $gateway->is_available() );

			$ssl_checkout = 'no';
			$is_test_mode = true;
			$this->assertTrue( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			remove_filter( 'pre_option_woocommerce_force_ssl_checkout', $ssl_filter );
		}
	}

	/**
	 * @testdox BNPL order-pay availability requires a usable address and Affirm shopper name.
	 */
	public function test_bnpl_order_pay_availability_validates_address_and_affirm_name(): void {
		$registry            = new WooPaymentsPaymentMethodRegistry();
		$affirm_definition   = $registry->get( 'affirm' );
		$afterpay_definition = $registry->get( 'afterpay_clearpay' );
		$this->assertNotNull( $affirm_definition );
		$this->assertNotNull( $afterpay_definition );

		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '100.00' );
		$order->save();
		set_query_var( 'order-pay', $order->get_id() );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'USD';
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_affirm_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_afterpay_clearpay_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$affirm = new NativeWooPaymentsGateway( $affirm_definition );
			$affirm->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'US', 'affirm_payments' ) );
			$this->assertFalse( $affirm->is_available() );

			$order->set_billing_country( 'US' );
			$order->set_billing_state( 'CA' );
			$order->set_billing_city( 'San Francisco' );
			$order->set_billing_postcode( '94107' );
			$order->set_billing_address_1( '123 Market St' );
			$order->save();
			$this->assertFalse( $affirm->is_available() );

			$order->set_billing_first_name( 'Test' );
			$order->set_billing_last_name( 'Shopper' );
			$order->save();
			$this->assertTrue( $affirm->is_available() );

			$order->set_shipping_first_name( 'Shipping' );
			$order->set_shipping_last_name( 'Shopper' );
			$order->set_shipping_country( 'AE' );
			$order->set_shipping_city( 'Dubai' );
			$order->set_shipping_address_1( '1 Test Street' );
			$order->set_shipping_state( '' );
			$order->set_shipping_postcode( '' );
			$order->set_billing_country( '' );
			$order->save();
			$this->assertTrue( $affirm->is_available() );

			$order->set_billing_first_name( '' );
			$order->set_billing_last_name( '' );
			$order->set_shipping_first_name( '' );
			$order->set_shipping_last_name( '' );
			$order->save();
			$afterpay = new NativeWooPaymentsGateway( $afterpay_definition );
			$afterpay->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'US', 'afterpay_clearpay_payments' ) );
			$this->assertTrue( $afterpay->is_available() );
		} finally {
			set_query_var( 'order-pay', 0 );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_affirm_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_afterpay_clearpay_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			$order->delete( true );
		}
	}

	/**
	 * @testdox Should show the checkout test-mode badge when test mode is enabled.
	 */
	public function test_gateway_icon_shows_test_mode_badge_when_test_mode_is_enabled(): void {
		$this->with_gateway_settings(
			array( 'test_mode' => 'yes' ),
			function (): void {
				$gateway = new NativeWooPaymentsGateway();

				$this->assertStringContainsString( '/assets/images/payment-methods/visa.svg', $gateway->get_icon() );
				$this->assertStringContainsString( 'test-mode badge', $gateway->get_icon() );
				$this->assertStringContainsString( 'background-color:#fff2d7', $gateway->get_icon() );
				$this->assertStringContainsString( 'Test Mode', $gateway->get_icon() );
			}
		);
	}

	/**
	 * @testdox Should hide the checkout test-mode badge when test mode is disabled.
	 */
	public function test_gateway_icon_hides_test_mode_badge_when_test_mode_is_disabled(): void {
		$this->with_gateway_settings(
			array( 'test_mode' => 'no' ),
			function (): void {
				$gateway = new NativeWooPaymentsGateway();

				$this->assertStringContainsString( '/assets/images/payment-methods/visa.svg', $gateway->get_icon() );
				$this->assertStringNotContainsString( 'test-mode badge', $gateway->get_icon() );
				$this->assertStringNotContainsString( 'Test Mode', $gateway->get_icon() );
			}
		);
	}

	/**
	 * @testdox Should show Cartes Bancaires in the checkout gateway icon for France merchants.
	 */
	public function test_gateway_icon_includes_cartes_bancaires_for_france_merchants(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'FR' ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, null, $account_service );

		$icon = $gateway->get_icon();

		$this->assertStringContainsString( '<span class="payment-methods--logos-count">+ 4</span>', $icon );
	}

	/**
	 * @testdox Should expose saved-card support only when saved cards are enabled.
	 */
	public function test_saved_card_support_tracks_saved_cards_setting(): void {
		$this->with_gateway_settings(
			array( 'saved_cards' => 'yes' ),
			function (): void {
				$gateway = new NativeWooPaymentsGateway();

				$this->assertContains( PaymentGatewayFeature::TOKENIZATION, $gateway->supports );
				$this->assertContains( PaymentGatewayFeature::ADD_PAYMENT_METHOD, $gateway->supports );
			}
		);

		$this->with_gateway_settings(
			array( 'saved_cards' => 'no' ),
			function (): void {
				$gateway = new NativeWooPaymentsGateway();

				$this->assertNotContains( PaymentGatewayFeature::TOKENIZATION, $gateway->supports );
				$this->assertNotContains( PaymentGatewayFeature::ADD_PAYMENT_METHOD, $gateway->supports );
			}
		);
	}

	/**
	 * @testdox Should expose WooPayments subscription support when subscriptions are enabled.
	 */
	public function test_subscription_support_tracks_subscriptions_availability(): void {
		$this->with_gateway_settings(
			array( 'saved_cards' => 'yes' ),
			function (): void {
				$gateway = new class() extends NativeWooPaymentsGateway {
					/**
					 * Tell whether subscriptions support is available.
					 *
					 * @return bool
					 */
					public function is_subscriptions_enabled(): bool {
						return true;
					}

				};

				$this->assertContains( 'multiple_subscriptions', $gateway->supports );
				$this->assertContains( 'subscription_cancellation', $gateway->supports );
				$this->assertContains( 'subscription_payment_method_change_admin', $gateway->supports );
				$this->assertContains( 'subscription_payment_method_change_customer', $gateway->supports );
				$this->assertContains( 'subscription_payment_method_change', $gateway->supports );
				$this->assertContains( 'subscription_reactivation', $gateway->supports );
				$this->assertContains( 'subscription_suspension', $gateway->supports );
				$this->assertContains( 'subscriptions', $gateway->supports );
				$this->assertContains( 'subscription_amount_changes', $gateway->supports );
				$this->assertContains( 'subscription_date_changes', $gateway->supports );
				$this->assertContains( PaymentGatewayFeature::TOKENIZATION, $gateway->supports );
				$this->assertContains( PaymentGatewayFeature::ADD_PAYMENT_METHOD, $gateway->supports );
				$this->assertNotContains( 'gateway_scheduled_payments', $gateway->supports );
			}
		);
	}

	/**
	 * @testdox Should ignore deprecated Stripe Billing flags for native subscription support.
	 */
	public function test_subscription_support_ignores_deprecated_stripe_billing_mode(): void {
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( '_wcpay_feature_stripe_billing', '1' );

		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function (): void {
					$gateway = new class() extends NativeWooPaymentsGateway {
						/**
						 * Tell whether subscriptions support is available.
						 *
						 * @return bool
						 */
						public function is_subscriptions_enabled(): bool {
							return true;
						}
					};

					$this->assertNotContains( 'gateway_scheduled_payments', $gateway->supports );
					$this->assertContains( 'subscription_amount_changes', $gateway->supports );
					$this->assertContains( 'subscription_date_changes', $gateway->supports );
				}
			);
		} finally {
			delete_option( '_wcpay_feature_subscriptions' );
			delete_option( '_wcpay_feature_stripe_billing' );
		}
	}

	/**
	 * @testdox Should register subscription renewal handlers when subscriptions are supported.
	 */
	public function test_subscription_support_registers_subscription_handlers(): void {
		$gateway = new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		$this->assertSame( 10, has_action( 'woocommerce_checkout_subscription_created', array( $gateway, 'maybe_force_subscription_to_manual' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID, array( $gateway, 'scheduled_subscription_payment' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay', array( $gateway, 'scheduled_subscription_payment' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_subscription_failing_payment_method_updated_' . OrderPaymentStore::GATEWAY_ID, array( $gateway, 'update_failing_payment_method' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_subscription_failing_payment_method_updated_woocommerce_payments_amazon_pay', array( $gateway, 'update_failing_payment_method' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_payment_meta', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'add_subscription_payment_meta' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_subscription_validate_payment_meta', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'validate_subscription_payment_meta' ) ) );
		$this->assertSame( 10, has_action( 'wcs_save_other_payment_meta', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'save_meta_in_order_tokens' ) ) );
		$this->assertSame( 10, has_filter( 'wcs_copy_payment_meta_to_order', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'append_payment_meta' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_my_subscriptions_payment_method', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'maybe_render_subscription_payment_method' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_payment_method_to_display', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'maybe_render_subscription_payment_method' ) ) );
		$this->assertSame( 10, has_filter( 'wcs_view_subscription_actions', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'maybe_hide_change_payment_for_manual_subscriptions' ) ) );
		$this->assertSame( 100, has_filter( 'user_has_cap', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'maybe_hide_auto_renew_toggle_for_manual_subscriptions' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_note_old_payment_method_title', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'get_specific_old_payment_method_title' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_note_new_payment_method_title', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'get_specific_new_payment_method_title' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_order_data_after_billing_address', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'add_payment_method_select_to_subscription_edit' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscriptions_update_subscription_token', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_subscription_token' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_payment_method_for_subscriptions' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_wcpay_get_user_payment_tokens', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'ajax_get_user_payment_tokens' ) ) );

		remove_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID, array( $gateway, 'scheduled_subscription_payment' ) );
		remove_action( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay', array( $gateway, 'scheduled_subscription_payment' ) );
		remove_action( 'woocommerce_subscription_failing_payment_method_updated_' . OrderPaymentStore::GATEWAY_ID, array( $gateway, 'update_failing_payment_method' ) );
		remove_action( 'woocommerce_subscription_failing_payment_method_updated_woocommerce_payments_amazon_pay', array( $gateway, 'update_failing_payment_method' ) );
	}

	/**
	 * @testdox Split gateways advertise subscription support without owning shared renewal hooks.
	 */
	public function test_split_gateway_subscription_support_is_independent_from_hook_ownership(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$gateway = new class( $definition ) extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		$this->assertContains( 'subscriptions', $gateway->supports );
		$this->assertFalse( has_action( 'woocommerce_checkout_subscription_created', array( $gateway, 'maybe_force_subscription_to_manual' ) ) );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway->id, array( $gateway, 'scheduled_subscription_payment' ) ) );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay', array( $gateway, 'scheduled_subscription_payment' ) ) );
	}

	/**
	 * @testdox Subscription checkout eligibility follows reusability and manual renewal policy.
	 */
	public function test_subscription_checkout_eligibility_is_independent_from_hook_ownership(): void {
		$registry              = new WooPaymentsPaymentMethodRegistry();
		$bancontact_definition = $registry->get( 'bancontact' );
		$amazon_definition     = $registry->get( 'amazon_pay' );
		$this->assertNotNull( $bancontact_definition );
		$this->assertNotNull( $amazon_definition );

		$method = new \ReflectionMethod( NativeWooPaymentsGateway::class, 'is_available_for_subscription_context' );

		$this->assertTrue( $method->invoke( new NativeWooPaymentsGateway( $bancontact_definition ), false, false ) );
		$this->assertFalse( $method->invoke( new NativeWooPaymentsGateway( $bancontact_definition ), true, false ) );
		$this->assertTrue( $method->invoke( new NativeWooPaymentsGateway( $bancontact_definition ), true, true ) );
		$this->assertTrue( $method->invoke( new NativeWooPaymentsGateway( $amazon_definition ), true, false ) );
	}

	/**
	 * @testdox Should preserve a non-reusable method while forcing its subscription to manual renewal.
	 */
	public function test_maybe_force_subscription_to_manual_preserves_non_reusable_method(): void {
		$subscription = new class() extends WC_Order {
			/**
			 * Whether manual renewal is required.
			 *
			 * @var bool
			 */
			private bool $requires_manual_renewal = false;

			/**
			 * Set whether manual renewal is required.
			 *
			 * @param bool $requires_manual_renewal Whether manual renewal is required.
			 */
			public function set_requires_manual_renewal( $requires_manual_renewal ): void {
				$this->requires_manual_renewal = (bool) $requires_manual_renewal;
			}

			/**
			 * Tell whether manual renewal is required.
			 *
			 * @return bool
			 */
			public function is_manual(): bool {
				return $this->requires_manual_renewal;
			}
		};
		$gateway_id   = OrderPaymentStore::GATEWAY_ID_PREFIX . 'bancontact';
		$subscription->set_payment_method( $gateway_id );
		$subscription->save();

		$gateway = new NativeWooPaymentsGateway();
		if ( ! method_exists( $gateway, 'maybe_force_subscription_to_manual' ) ) {
			$this->fail( 'The subscription creation policy callback does not exist.' );
		}
		$gateway->maybe_force_subscription_to_manual( $subscription );

		$this->assertTrue( $subscription->is_manual() );
		$this->assertSame( $gateway_id, $subscription->get_payment_method() );
		$this->assertSame( $gateway_id, $subscription->get_meta( '_wcpay_original_payment_method_id', true ) );
	}

	/**
	 * @testdox Should attach base subscription renewal handlers once across gateway instances.
	 */
	public function test_subscription_handler_registration_is_idempotent_across_gateway_instances(): void {
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		global $wp_filter;

		foreach ( array( OrderPaymentStore::GATEWAY_ID, 'woocommerce_payments_amazon_pay' ) as $gateway_id ) {
			$callbacks = $wp_filter[ 'woocommerce_scheduled_subscription_payment_' . $gateway_id ]->callbacks[10] ?? array();
			$matches   = array_filter(
				$callbacks,
				static function ( array $callback ): bool {
					return is_array( $callback['function'] ?? null )
						&& $callback['function'][0] instanceof NativeWooPaymentsGateway
						&& 'scheduled_subscription_payment' === ( $callback['function'][1] ?? null );
				}
			);

			$this->assertCount( 1, $matches, 'Expected one base gateway renewal callback for ' . $gateway_id . '.' );
		}
	}

	/**
	 * @testdox Should process Amazon Pay scheduled subscription renewals through the native gateway handler.
	 */
	public function test_amazon_pay_scheduled_subscription_payment_hook_reaches_gateway_handler(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_amazon_renewal' ) );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};
		$gateway->init( $service, new WooPaymentsProvider() );

		/**
		 * Fires a scheduled WooPayments Amazon Pay subscription renewal payment.
		 *
		 * @since 11.0.0
		 */
		do_action( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay', 12.0, wc_get_order( $order->get_id() ) );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( array( 'scheduled_subscription_payment' => true ), $service->last_checkout_context->get_provider_data() );
	}

	/**
	 * @testdox Should register WooPayments failed-renewal authentication emails when subscriptions are supported.
	 */
	public function test_subscription_support_registers_failed_renewal_authentication_emails(): void {
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		/**
		 * Filters the registered WooCommerce email classes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $emails Email classes.
		 */
		$emails = apply_filters( 'woocommerce_email_classes', array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_Failed_Renewal_Authentication', $emails );
		$this->assertSame( 'failed_renewal_authentication', $emails['WC_Payments_Email_Failed_Renewal_Authentication']->id );
		$this->assertSame( 'failed-renewal-authentication.php', $emails['WC_Payments_Email_Failed_Renewal_Authentication']->template_html );
		$this->assertSame( 'plain/failed-renewal-authentication.php', $emails['WC_Payments_Email_Failed_Renewal_Authentication']->template_plain );
		$this->assertSame( 10, has_action( 'woocommerce_woocommerce_payments_payment_requires_action', array( $emails['WC_Payments_Email_Failed_Renewal_Authentication'], 'trigger' ) ) );
		$this->assertArrayHasKey( 'WC_Payments_Email_Failed_Authentication_Retry', $emails );
		$this->assertSame( 'failed_authentication_requested', $emails['WC_Payments_Email_Failed_Authentication_Retry']->id );
		$this->assertSame( 'failed-renewal-authentication-requested.php', $emails['WC_Payments_Email_Failed_Authentication_Retry']->template_html );
		$this->assertSame( 'plain/failed-renewal-authentication-requested.php', $emails['WC_Payments_Email_Failed_Authentication_Retry']->template_plain );
	}

	/**
	 * @testdox Should register WooPayments failed-renewal authentication emails only once across gateway instances.
	 */
	public function test_subscription_email_registration_is_idempotent_across_gateway_instances(): void {
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		global $wp_filter;
		$callbacks = $wp_filter['woocommerce_email_classes']->callbacks[20] ?? array();
		$matches   = array_filter(
			$callbacks,
			static function ( array $callback ): bool {
				return is_array( $callback['function'] ?? null )
					&& NativeWooPaymentsGateway::class === ( $callback['function'][0] ?? null )
					&& 'add_subscription_emails' === ( $callback['function'][1] ?? null );
			}
		);

		$this->assertCount( 1, $matches );
	}

	/**
	 * @testdox Should update retry rules for failed renewals that need authentication.
	 */
	public function test_failed_renewal_authentication_email_updates_retry_rules(): void {
		new class() extends NativeWooPaymentsGateway {
			/**
			 * Tell whether subscriptions support is available.
			 *
			 * @return bool
			 */
			public function is_subscriptions_enabled(): bool {
				return true;
			}
		};

		/**
		 * Filters the registered WooCommerce email classes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $emails Email classes.
		 */
		$emails = apply_filters( 'woocommerce_email_classes', array() );
		$order  = $this->create_order();
		$email  = $emails['WC_Payments_Email_Failed_Renewal_Authentication'];

		$email->object = $order;

		$customer_rule = $email->prevent_retry_notification_email(
			array(
				'email_template_customer' => 'WCS_Email_Customer_Renewal_Invoice',
				'email_template_admin'    => 'WCS_Email_Payment_Retry',
			),
			1,
			$order->get_id()
		);
		$admin_rule    = $email->set_store_owner_custom_email(
			array(
				'email_template_customer' => 'WCS_Email_Customer_Renewal_Invoice',
				'email_template_admin'    => 'WCS_Email_Payment_Retry',
			),
			1,
			$order->get_id()
		);

		$this->assertSame( '', $customer_rule['email_template_customer'] );
		$this->assertSame( 'WC_Payments_Email_Failed_Authentication_Retry', $admin_rule['email_template_admin'] );
	}

	/**
	 * @testdox Should process scheduled subscription payments with the saved renewal token.
	 */
	public function test_scheduled_subscription_payment_uses_saved_renewal_token(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_old_renewal' ) );
		$active_token = $this->create_card_token( $user_id, 'pm_renewal' );
		$order->add_payment_token( $active_token );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, new WooPaymentsTokenService() );

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame(
			array(
				'payment_token'       => (string) $active_token->get_id(),
				'save_payment_method' => false,
			),
			$service->last_checkout_context->get_payment_data()
		);
		$this->assertSame( array( 'scheduled_subscription_payment' => true ), $service->last_checkout_context->get_provider_data() );
	}

	/**
	 * @testdox Should keep tokenized WC Subscriptions renewals independent of deprecated Stripe Billing flags.
	 */
	public function test_scheduled_subscription_payment_uses_tokenized_renewal_when_deprecated_stripe_billing_flags_remain(): void {
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( '_wcpay_feature_stripe_billing', '1' );

		try {
			$user_id = self::factory()->user->create();
			$order   = $this->create_order();
			$order->set_customer_id( $user_id );
			$order->add_payment_token( $this->create_card_token( $user_id, 'pm_tokenized_renewal' ) );
			$order->save();

			$service = new RecordingPaymentProcessingService();
			$gateway = new NativeWooPaymentsGateway();
			$gateway->init( $service, new WooPaymentsProvider() );

			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

			$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
			$this->assertSame(
				array(
					'payment_token'       => (string) $order->get_payment_tokens()[0],
					'save_payment_method' => false,
				),
				$service->last_checkout_context->get_payment_data()
			);
			$this->assertSame( array( 'scheduled_subscription_payment' => true ), $service->last_checkout_context->get_provider_data() );
		} finally {
			delete_option( '_wcpay_feature_subscriptions' );
			delete_option( '_wcpay_feature_stripe_billing' );
		}
	}

	/**
	 * @testdox Should use the current subscription customer when processing a scheduled renewal.
	 */
	public function test_scheduled_subscription_payment_uses_current_subscription_customer(): void {
		$user_id      = self::factory()->user->create();
		$parent_order = $this->create_order();
		$subscription = $this->create_order();
		$renewal      = $this->create_order();

		$parent_order->update_meta_data( '_stripe_customer_id', 'cus_parent_stale' );
		$parent_order->update_meta_data( '_stripe_mandate_id', 'mandate_parent' );
		$parent_order->save();
		$subscription->set_parent_id( $parent_order->get_id() );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_subscription_current' );
		$subscription->save();
		$renewal->set_customer_id( $user_id );
		$renewal->add_payment_token( $this->create_card_token( $user_id, 'pm_renewal' ) );
		$renewal->save();

		add_filter(
			'woocommerce_woopayments_subscriptions_for_renewal_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $renewal, $subscription ): array {
				return $renewal->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $renewal->get_id() ) );

		$renewal = wc_get_order( $renewal->get_id() );

		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertSame( 'cus_subscription_current', $renewal->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame(
			array(
				'scheduled_subscription_payment' => true,
				'renewal_mandate'                => 'mandate_parent',
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Should fail scheduled renewals and fire the preserved action when customer authentication is required.
	 */
	public function test_scheduled_subscription_payment_fails_and_fires_requires_action_hook(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
		$order->save();

		$service  = new class( new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'charge_id' => 'ch_requires_action',
				'meta'      => array(
					'_charge_id' => 'ch_legacy_meta',
				),
			)
		) ) extends RecordingPaymentProcessingService {
			/**
			 * Outcome returned by process_checkout_outcome.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome Outcome returned by process_checkout_outcome.
			 */
			public function __construct( PaymentOutcome $outcome ) {
				$this->outcome = $outcome;
			}

			/**
			 * Process checkout payment and return the neutral outcome.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Provider.
			 * @return PaymentOutcome
			 */
			public function process_checkout_outcome( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				$this->last_checkout_context = $context;

				return $this->outcome;
			}
		};
		$received = array();
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function ( WC_Order $hook_order, string $intent_id, string $payment_method_id, string $customer_id, string $charge_id, string $currency ) use ( &$received ): void {
				$received = array(
					'hook_order'        => $hook_order,
					'intent_id'         => $intent_id,
					'payment_method_id' => $payment_method_id,
					'customer_id'       => $customer_id,
					'charge_id'         => $charge_id,
					'currency'          => $currency,
				);
			},
			10,
			6
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( $order->get_id(), $received['hook_order']->get_id() );
		$this->assertSame( 'pi_requires_action', $received['intent_id'] );
		$this->assertSame( 'pm_requires_action', $received['payment_method_id'] );
		$this->assertSame( 'cus_requires_action', $received['customer_id'] );
		$this->assertSame( 'ch_requires_action', $received['charge_id'] );
		$this->assertSame( 'USD', $received['currency'] );
	}

	/**
	 * @testdox Should still fail scheduled renewals when a customer-action hook callback throws.
	 */
	public function test_scheduled_subscription_payment_fails_when_requires_action_hook_throws(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
		$order->save();

		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'meta' => array(
					'_charge_id' => 'ch_requires_action',
				),
			)
		);

		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function (): void {
				throw new \RuntimeException( 'email callback failed' );
			}
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		try {
			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \RuntimeException $exception ) {
			$this->fail( 'Requires-action hook exceptions should not prevent renewal failure handling.' );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
	}

	/**
	 * @testdox Should copy the successful renewal token onto a failing subscription.
	 */
	public function test_update_failing_payment_method_copies_renewal_token(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_order();
		$renewal      = $this->create_order();
		$token        = $this->create_card_token( $user_id, 'pm_recovered' );

		$subscription->set_customer_id( $user_id );
		$subscription->save();
		$renewal->set_customer_id( $user_id );
		$renewal->add_payment_token( $token );
		$renewal->save();

		$gateway = new NativeWooPaymentsGateway();

		$gateway->update_failing_payment_method( wc_get_order( $subscription->get_id() ), wc_get_order( $renewal->get_id() ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription->get_payment_tokens() ) );
	}

	/**
	 * @testdox Should reappend a previously used renewal token so it becomes active on the failing subscription.
	 */
	public function test_update_failing_payment_method_reappends_previously_used_renewal_token(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_order();
		$renewal      = $this->create_order();
		$token_a      = $this->create_card_token( $user_id, 'pm_a' );
		$token_b      = $this->create_card_token( $user_id, 'pm_b' );

		$subscription->set_customer_id( $user_id );
		$subscription->add_payment_token( $token_a );
		$subscription->add_payment_token( $token_b );
		$subscription->save();
		$renewal->set_customer_id( $user_id );
		$renewal->add_payment_token( $token_a );
		$renewal->save();

		$gateway = new NativeWooPaymentsGateway();
		$gateway->update_failing_payment_method( wc_get_order( $subscription->get_id() ), wc_get_order( $renewal->get_id() ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame(
			array( $token_a->get_id(), $token_b->get_id(), $token_a->get_id() ),
			array_values( array_map( 'absint', $subscription->get_payment_tokens() ) )
		);
	}

	/**
	 * @testdox Should save setup-intent payment methods from the account add-payment-method form.
	 */
	public function test_add_payment_method_saves_successful_setup_intent_token(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( 'seti_native' )
			->willReturn(
				array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'payment_method' => 'pm_added',
				)
			);

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service
			->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( 'pm_added', $user_id )
			->willReturn( $this->create_card_token( $user_id, 'pm_added' ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should save a non-card setup-intent payment method from My Account.
	 */
	public function test_add_payment_method_uses_type_aware_token_creation(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_sepa';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( 'seti_sepa' )
			->willReturn(
				array(
					'id'             => 'seti_sepa',
					'status'         => 'succeeded',
					'payment_method' => 'pm_sepa',
				)
			);

		$token = new WooPaymentsSepaToken();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID . '_sepa_debit' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_sepa' );
		$token->set_last4( '3000' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service
			->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( 'pm_sepa', $user_id )
			->willReturn( $token );

		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'sepa_debit' );
		$this->assertNotNull( $definition );

		$gateway = new NativeWooPaymentsGateway( $definition );
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should save setup-intent payment methods when the intent customer matches the current user.
	 */
	public function test_add_payment_method_saves_setup_intent_when_customer_matches(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( 'seti_native' )
			->willReturn(
				array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_me',
					'payment_method' => 'pm_added',
				)
			);

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$customer_service
			->method( 'get_customer_id_by_user_id' )
			->with( $user_id )
			->willReturn( 'cus_me' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service
			->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( 'pm_added', $user_id )
			->willReturn( $this->create_card_token( $user_id, 'pm_added' ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $customer_service );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should reject setup intents whose customer belongs to another user and not create a token.
	 */
	public function test_add_payment_method_rejects_setup_intent_owned_by_another_customer(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( 'seti_native' )
			->willReturn(
				array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_other',
					'payment_method' => 'pm_added',
				)
			);

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$customer_service
			->method( 'get_customer_id_by_user_id' )
			->with( $user_id )
			->willReturn( 'cus_me' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service
			->expects( $this->never() )
			->method( 'get_or_create_token_for_user' );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $customer_service );

		$result = $gateway->add_payment_method();

		$this->assertSame( array( 'result' => 'error' ), $result );
	}

	/**
	 * @testdox Should reject add-payment-method requests with invalid fraud-prevention tokens before reading the setup intent.
	 */
	public function test_add_payment_method_rejects_invalid_fraud_prevention_token_when_enabled(): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';

		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->never() )
			->method( 'get_setup_intention' );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			new RecordingPaymentProcessingService(),
			new WooPaymentsProvider(),
			null,
			$api_client,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( true, $session )
		);

		$result = $gateway->add_payment_method();

		$this->assertSame( array( 'result' => 'error' ), $result );
		$this->assertSame( 16, strlen( (string) $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) ) );
		$this->assertNotSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
		$this->assertSame(
			"We're not able to add this payment method. Please refresh the page and try again.",
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should not translate gateway labels during construction before init.
	 */
	public function test_constructor_does_not_translate_gateway_labels_before_init(): void {
		global $wp_actions;

		$had_init_action_count = is_array( $wp_actions ) && array_key_exists( 'init', $wp_actions );
		$previous_init_count   = $had_init_action_count ? $wp_actions['init'] : null;
		$translated            = array();
		$filter                = static function ( $translation, $text, $domain ) use ( &$translated ) {
			if ( 'woocommerce' === $domain ) {
				$translated[] = $text;
			}

			return $translation;
		};

		unset( $wp_actions['init'] );
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			new NativeWooPaymentsGateway();
		} finally {
			remove_filter( 'gettext', $filter, 10 );

			if ( $had_init_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate pre-init construction for the WP 6.7 textdomain guard.
				$wp_actions['init'] = $previous_init_count;
			} else {
				unset( $wp_actions['init'] );
			}
		}

		$this->assertSame( array(), $translated, 'Native gateway construction must not call translation APIs before init.' );
	}

	/**
	 * @testdox Should translate gateway labels from the init hook.
	 */
	public function test_translates_gateway_labels_from_init_hook(): void {
		global $wp_actions;

		$had_init_action_count = is_array( $wp_actions ) && array_key_exists( 'init', $wp_actions );
		$previous_init_count   = $had_init_action_count ? $wp_actions['init'] : null;
		$filter                = static function ( $translation, $text, $domain ) {
			return 'woocommerce' === $domain ? 'Translated: ' . $text : $translation;
		};

		unset( $wp_actions['init'] );
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			$gateway = new NativeWooPaymentsGateway();

			$this->assertSame( 'Card', $gateway->title );
			$this->assertSame( 'WooPayments', $gateway->method_title );
			$this->assertSame( 'Accept payments with WooPayments.', $gateway->method_description );

			$gateway->handle_init();

			$this->assertSame( 'Translated: Card', $gateway->title );
			$this->assertSame( 'Translated: WooPayments', $gateway->method_title );
			$this->assertSame( 'Translated: Accept payments with WooPayments.', $gateway->method_description );
		} finally {
			remove_filter( 'gettext', $filter, 10 );

			if ( $had_init_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate pre-init construction for the WP 6.7 textdomain guard.
				$wp_actions['init'] = $previous_init_count;
			} else {
				unset( $wp_actions['init'] );
			}
		}
	}

	/**
	 * @testdox Should process payments through the native processing service.
	 */
	public function test_process_payment_delegates_to_processing_service(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['is_platform_payment_method'] );
	}

	/**
	 * @testdox Should carry checked save intent only across order-pay confirmation redirects.
	 *
	 * @dataProvider confirmation_redirect_save_intent_contexts
	 *
	 * @param bool $is_order_pay       Whether the request came from the order-pay form.
	 * @param bool $should_save        Whether the shopper requested payment-method saving.
	 * @param bool $should_carry_intent Whether the confirmation redirect should carry the save intent.
	 */
	public function test_process_payment_carries_checked_save_intent_only_across_order_pay_confirmation_redirect( bool $is_order_pay, bool $should_save, bool $should_carry_intent ): void {
		$order                 = $this->create_order();
		$confirmation_redirect = '#wcpay-confirm-pi:' . $order->get_id() . ':pi_native_secret_abc:nonce';
		$service               = new RecordingPaymentProcessingService();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_native',
			$confirmation_redirect,
			'pm_native'
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		if ( $is_order_pay ) {
			$_POST['woocommerce_pay'] = '1';
		}
		if ( $should_save ) {
			$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-new-payment-method' ] = 'true';
		}

		$filtered_payment_url = 'https://example.test/filtered-order-pay/?pay_for_order=true&key=wc_order_test&extension=kept#extension-anchor';
		$payment_url_filter   = static function ( string $payment_url, WC_Order $filtered_order ) use ( $order, $filtered_payment_url ): string {
			return $filtered_order->get_id() === $order->get_id() ? $filtered_payment_url : $payment_url;
		};
		add_filter( 'woocommerce_get_checkout_payment_url', $payment_url_filter, 10, 2 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_checkout_payment_url', $payment_url_filter, 10 );
		}

		$expected_payment_url = 'https://example.test/filtered-order-pay/?pay_for_order=true&key=wc_order_test&extension=kept';
		$expected_redirect    = $should_carry_intent
			? add_query_arg( 'save_payment_method', 'yes', $expected_payment_url ) . $confirmation_redirect
			: $confirmation_redirect;

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $expected_redirect, $result['redirect'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $should_save, $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
	}

	/**
	 * Confirmation redirect request contexts.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool}>
	 */
	public function confirmation_redirect_save_intent_contexts(): array {
		return array(
			'checked order pay'   => array( true, true, true ),
			'unchecked order pay' => array( true, false, false ),
			'checked checkout'    => array( false, true, false ),
		);
	}

	/**
	 * @testdox Should reject checkout requests with invalid fraud-prevention tokens before creating a payment context.
	 */
	public function test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( true, $session )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame(
			array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			),
			$result
		);
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( 16, strlen( (string) $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) ) );
		$this->assertNotSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
		$this->assertSame(
			"We're not able to process this payment. Please refresh the page and try again.",
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should skip checkout fraud-prevention token checks when card-testing protection is not enabled.
	 */
	public function test_process_payment_skips_fraud_prevention_token_check_when_disabled(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * @testdox Should reject checkout before creating a payment context when the failed-transaction limiter is active.
	 */
	public function test_process_payment_rejects_checkout_when_failed_transaction_rate_limiter_is_active(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame(
			array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			),
			$result
		);
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame(
			'Your payment was not processed.',
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should bump the failed-transaction limiter for extension-matching decline error codes.
	 *
	 * @dataProvider failed_transaction_limited_error_codes
	 *
	 * @param string $error_code Provider error code.
	 */
	public function test_process_payment_bumps_failed_transaction_rate_limiter_for_decline_error_codes( string $error_code ): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => $error_code,
				PaymentOutcome::DATA_ERROR_MESSAGE => 'Declined.',
			)
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array() ) );
	}

	/**
	 * @testdox Should not bump the failed-transaction limiter for non-card-decline failures.
	 */
	public function test_process_payment_does_not_bump_failed_transaction_rate_limiter_for_other_errors(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => 'processing_error',
				PaymentOutcome::DATA_ERROR_MESSAGE => 'Temporary provider error.',
			)
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array() ) );
	}

	/**
	 * @testdox A failed provider checkout adds exactly one localized shopper notice without exposing raw diagnostics.
	 */
	public function test_process_payment_adds_one_localized_notice_for_failed_provider_outcome(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_declined',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => 'card_declined',
				PaymentOutcome::DATA_ERROR_MESSAGE         => 'Provider diagnostic for request req_private.',
				PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => 'Error: Your card has insufficient funds.',
			)
		);

		$sut = new NativeWooPaymentsGateway();
		$sut->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$result  = $sut->process_payment( $order->get_id() );
		$notices = wc_get_notices( 'error' );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'Error: Your card has insufficient funds.', $notices[0]['notice'] );
		$this->assertStringNotContainsString( 'req_private', $notices[0]['notice'] );
	}

	/**
	 * @testdox Failed outcomes without shopper copy add one generic safe notice.
	 */
	public function test_process_payment_adds_one_generic_notice_for_failed_transport_outcome(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => 'wcpay_native_transport_error',
				PaymentOutcome::DATA_ERROR_MESSAGE => 'Connection refused at private-provider-host:8443.',
			)
		);

		$sut = new NativeWooPaymentsGateway();
		$sut->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$result  = $sut->process_payment( $order->get_id() );
		$notices = wc_get_notices( 'error' );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, $notices );
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			$notices[0]['notice']
		);
		$this->assertStringNotContainsString( 'private-provider-host', $notices[0]['notice'] );
	}

	/**
	 * @testdox Store API legacy checkout converts a failed native provider notice to one safe route exception.
	 */
	public function test_store_api_legacy_checkout_surfaces_failed_provider_shopper_notice(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_declined',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => 'card_declined',
				PaymentOutcome::DATA_ERROR_MESSAGE         => 'Provider diagnostic for request req_private.',
				PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => 'Error: Your card has insufficient funds.',
			)
		);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$available_gateway_filter = static function ( array $gateways ) use ( $gateway ): array {
			$gateways[ $gateway->id ] = $gateway;

			return $gateways;
		};
		add_filter( 'woocommerce_available_payment_gateways', $available_gateway_filter );

		$context = new StoreApiPaymentContext();
		$context->set_order( $order );
		$context->set_payment_method( $gateway->id );
		$context->set_payment_data( array() );
		$result = new StoreApiPaymentResult();

		try {
			( new StoreApiLegacy() )->process_legacy_payment( $context, $result );
			$this->fail( 'A failed native provider outcome should surface its shopper notice through the Store API.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'woocommerce_rest_payment_error', $exception->getErrorCode() );
			$this->assertSame( 'Error: Your card has insufficient funds.', $exception->getMessage() );
			$this->assertStringNotContainsString( 'req_private', $exception->getMessage() );
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $available_gateway_filter );
		}

		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}

	/**
	 * Failed transaction error codes that match standalone WooPayments rate-limiter behavior.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function failed_transaction_limited_error_codes(): array {
		return array(
			'card declined'    => array( 'card_declined' ),
			'incorrect number' => array( 'incorrect_number' ),
			'incorrect cvc'    => array( 'incorrect_cvc' ),
		);
	}

	/**
	 * @testdox Should redirect duplicate checkout attempts to a paid matching session order before charging.
	 */
	public function test_process_payment_redirects_duplicate_checkout_to_paid_session_order_before_processing(): void {
		$customer_id = self::factory()->user->create();
		$cart_hash   = 'same-cart-hash';
		$session     = $this->create_session();
		$service     = new RecordingPaymentProcessingService();

		$paid_order = $this->create_order();
		$paid_order->set_cart_hash( $cart_hash );
		$paid_order->set_customer_id( $customer_id );
		$paid_order->update_status( 'completed' );
		$paid_order->save();

		$current_order = $this->create_order();
		$current_order->set_cart_hash( $cart_hash );
		$current_order->set_customer_id( $customer_id );
		$current_order->update_status( 'pending' );
		$current_order->save();

		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $paid_order->get_id() );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session ),
			$this->create_duplicate_payment_prevention_service( $session )
		);

		$result = $gateway->process_payment( $current_order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( (string) $paid_order->get_id(), $result['redirect'] );
		$this->assertStringContainsString( 'wcpay_paid_for_previous_order=yes', $result['redirect'] );
		$this->assertNull( $service->last_checkout_context );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );

		$deleted_order = wc_get_order( $current_order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $deleted_order );
		$this->assertSame( 'trash', $deleted_order->get_status() );
	}

	/**
	 * @testdox Should keep completed same-cart checkouts available for duplicate replay redirects.
	 */
	public function test_process_payment_redirects_same_cart_replay_after_completed_outcome(): void {
		$customer_id = self::factory()->user->create();
		$cart_hash   = 'same-cart-hash';
		$session     = $this->create_session();
		$service     = new RecordingPaymentProcessingService();
		$gateway     = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session ),
			$this->create_duplicate_payment_prevention_service( $session )
		);

		$first_order = $this->create_order();
		$first_order->set_cart_hash( $cart_hash );
		$first_order->set_customer_id( $customer_id );
		$first_order->save();

		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$first_result                  = $gateway->process_payment( $first_order->get_id() );
		$first_order->update_status( 'processing' );
		$first_order->save();

		$second_order = $this->create_order();
		$second_order->set_cart_hash( $cart_hash );
		$second_order->set_customer_id( $customer_id );
		$second_order->update_status( 'pending' );
		$second_order->save();

		$second_result = $gateway->process_payment( $second_order->get_id() );

		$this->assertSame( 'success', $first_result['result'] );
		$this->assertSame( 'success', $second_result['result'] );
		$this->assertSame( 1, $service->checkout_attempt_count );
		$this->assertStringContainsString( (string) $first_order->get_id(), $second_result['redirect'] );
		$this->assertStringContainsString( 'wcpay_paid_for_previous_order=yes', $second_result['redirect'] );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );

		$deleted_order = wc_get_order( $second_order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $deleted_order );
		$this->assertSame( 'trash', $deleted_order->get_status() );
	}

	/**
	 * @testdox Should redirect an order with an already successful attached PaymentIntent before charging again.
	 */
	public function test_process_payment_redirects_successful_attached_intent_before_processing(): void {
		$order   = $this->create_order();
		$session = $this->create_session();
		$service = new RecordingPaymentProcessingService();
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();
		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $order->get_id() );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_existing' )
			->willReturn( $this->create_intent_response( $order, 'succeeded', 1200 ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session ),
			$this->create_duplicate_payment_prevention_service( $session, $api_client )
		);

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] );
		$this->assertNull( $service->last_checkout_context );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertContains( $order->get_status(), wc_get_is_paid_statuses() );
	}

	/**
	 * @testdox Should hand successful subscription payment-method changes back to WC Subscriptions.
	 */
	public function test_process_payment_updates_subscription_payment_method_after_successful_new_method_change(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_payment_method_for_subscriptions' ), 10, 3 );
		$_POST['_wcsnonce'] = wp_create_nonce( 'wcs_change_payment_method' );

		$_POST['woocommerce_change_payment'] = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$return_url_filter = static function ( string $return_url, WC_Order $filtered_order ) use ( $order ): string {
			return $order->get_id() === $filtered_order->get_id() ? 'https://example.test/my-account/' : $return_url;
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11, 2 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/my-account/', $result['redirect'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['recurring_payment'] ?? false );
		$this->assertSame(
			array(
				array(
					'order_id'   => $order->get_id(),
					'gateway_id' => OrderPaymentStore::GATEWAY_ID,
				),
			),
			\WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods
		);
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_payment_method_for_subscriptions' ) ) );
	}

	/**
	 * @testdox Should preserve confirmation redirects and delay update-all bookkeeping for subscription changes.
	 */
	public function test_process_payment_preserves_subscription_change_confirmation_redirect(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order                     = $this->create_order();
		$confirmation_redirect     = '#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce';
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			$confirmation_redirect,
			'pm_new'
		);
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$_POST['update_all_subscriptions_payment_method'] = '1';

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $confirmation_redirect, $result['redirect'] );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $order->get_meta( '_delayed_update_payment_method_all', true ) );
	}

	/**
	 * @testdox Should preserve provider redirects and defer subscription bookkeeping until authorization returns.
	 */
	public function test_process_payment_defers_subscription_change_update_for_provider_redirect(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order                     = $this->create_order();
		$provider_redirect         = 'https://payments.example.test/authorize';
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_REDIRECT,
			'pi_requires_redirect',
			$provider_redirect,
			'pm_new'
		);
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $provider_redirect, $result['redirect'] );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * @testdox Should reject no-external-payment success semantics when a new subscription credential is missing.
	 */
	public function test_process_payment_does_not_complete_subscription_change_without_provider_credential(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$return_filter_calls = 0;
		$return_url_filter   = static function () use ( &$return_filter_calls ): string {
			++$return_filter_calls;
			return 'https://example.test/my-account/';
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );
		$this->assertSame( '', $result['payment_method'] );
		$this->assertSame( 0, $return_filter_calls );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * @testdox Should not update subscription payment methods or success redirects after a failed new-method change.
	 */
	public function test_process_payment_does_not_update_subscription_payment_method_after_failed_new_method_change(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$return_filter_calls = 0;
		$return_url_filter   = static function ( string $return_url ) use ( &$return_filter_calls ): string {
			++$return_filter_calls;
			return $return_url;
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 0, $return_filter_calls );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * @testdox Should require a positive matching subscription ID for new-method change semantics.
	 * @dataProvider invalid_subscription_change_requests
	 *
	 * @param string $request_id_type Request ID scenario.
	 * @param bool   $is_subscription Whether the processed order should be identified as a subscription.
	 */
	public function test_process_payment_rejects_invalid_subscription_change_request_identity( string $request_id_type, bool $is_subscription ): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = $is_subscription ? array( $order->get_id() ) : array();
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['change_payment_method']         = 'zero' === $request_id_type ? '0' : (string) ( $order->get_id() + ( 'mismatch' === $request_id_type ? 1 : 0 ) );

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertFalse( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['recurring_payment'] ?? false );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * Invalid subscription change request cases.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public static function invalid_subscription_change_requests(): array {
		return array(
			'zero request ID'       => array( 'zero', true ),
			'mismatched request ID' => array( 'mismatch', true ),
			'non-subscription ID'   => array( 'match', false ),
		);
	}

	/**
	 * @testdox Should short-circuit WooPay preflight checks before charging an order.
	 */
	public function test_process_payment_short_circuits_woopay_preflight_checks(): void {
		$order = $this->create_order();
		$order->update_status( 'failed' );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST['is-woopay-preflight-check'] = '1';

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => '',
			),
			$result
		);
		$this->assertNull( $service->last_checkout_context );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox Should pass platform-created payment method state through provider data.
	 */
	public function test_process_payment_passes_platform_payment_method_state_to_provider_data(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method']             = 'pm_platform';
		$_POST['wcpay-is-platform-payment-method'] = 'true';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( 'pm_platform', $service->last_checkout_context->get_payment_method_id() );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['is_platform_payment_method'] );
	}

	/**
	 * @testdox Should pass submitted express checkout payment method types through provider data.
	 */
	public function test_process_payment_passes_express_payment_method_types_to_provider_data(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-confirmation-token']           = 'ctoken_express';
		$_POST['wcpay-express-payment-method-types'] = wp_json_encode( array( 'card', 'amazon_pay', 'unknown_method', array( 'nested' ) ) );
		$_POST['wcpay-express-checkout-context']     = 'pay_for_order';
		$_POST['wcpay-is-platform-payment-method']   = 'true';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( 'ctoken_express', $service->last_checkout_context->get_payment_method_id() );
		$this->assertSame( array( 'card', 'amazon_pay' ), $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY ] );
		$this->assertSame( 'pay_for_order', $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['is_platform_payment_method'] );
	}

	/**
	 * @testdox Should process refunds through the native processing service.
	 */
	public function test_process_refund_delegates_to_processing_service(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertTrue( $result );
		$this->assertInstanceOf( PaymentContext::class, $service->last_refund_context );
		$this->assertSame(
			array(
				'amount' => 4.25,
				'reason' => 'Adjustment',
			),
			$service->last_refund_context->get_payment_data()
		);
	}

	/**
	 * @testdox Should only allow refunds for orders with a WooPayments charge.
	 */
	public function test_can_refund_order_requires_charge_id(): void {
		$order   = $this->create_order();
		$gateway = new NativeWooPaymentsGateway();

		$this->assertFalse( $gateway->can_refund_order( $order ) );

		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$this->assertTrue( $gateway->can_refund_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * @testdox Should refuse refunds while the payment is only authorized, pointing at the capture actions.
	 */
	public function test_process_refund_refuses_uncaptured_payment(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$this->assertSame( 'uncaptured-payment', $result->get_error_code() );
		$this->assertStringContainsString( 'not captured yet', $result->get_error_message() );
		$this->assertNull( $service->last_refund_context, 'An uncaptured payment must never reach the platform refund call.' );
	}

	/**
	 * @testdox Should fail refunds that do not have a WooPayments charge.
	 */
	public function test_process_refund_fails_without_charge_id(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$this->assertSame( 'native_payment_refund_missing_charge', $result->get_error_code() );
		$this->assertNull( $service->last_refund_context );
	}

	/**
	 * @testdox Should resolve native dependencies when WooCommerce instantiates the gateway directly.
	 */
	public function test_process_payment_resolves_dependencies_without_explicit_init(): void {
		$order   = $this->create_order();
		$gateway = new NativeWooPaymentsGateway();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame(
			array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			),
			$result
		);
	}

	/**
	 * @testdox Should delegate payment fields rendering to the checkout bridge.
	 */
	public function test_payment_fields_delegate_to_checkout_bridge(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$service = new RecordingPaymentProcessingService();
		$bridge  = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge
			->expects( $this->once() )
			->method( 'render_payment_fields' )
			->willReturnCallback(
				static function (): void {
					echo '<div id="wcpay-bridge-marker"></div>';
				}
			);

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $service, $bridge, &$output ): void {
					$gateway = new NativeWooPaymentsGateway();
					$gateway->init( $service, new WooPaymentsProvider(), $bridge );

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}

		$this->assertStringContainsString( 'wcpay-bridge-marker', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-new-payment-method', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-payment-token-new', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]*>/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should render mandatory subscription change saving as a visually hidden checked checkbox.
	 */
	public function test_payment_fields_hide_checked_save_payment_method_for_subscription_change(): void {
		$this->ensure_wcs_subscription_detector_double();
		$order = $this->create_order();

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_GET['change_payment_method']          = (string) $order->get_id();

		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$service = new RecordingPaymentProcessingService();
		$bridge  = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge->method( 'render_payment_fields' )->willReturnCallback(
			static function (): void {
				echo '<div id="wcpay-bridge-marker"></div>';
			}
		);

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $service, $bridge, &$output ): void {
					$gateway = new class() extends NativeWooPaymentsGateway {
						/**
						 * Simulate optional WooCommerce Subscriptions availability.
						 *
						 * @return bool
						 */
						public function is_subscriptions_enabled(): bool {
							return true;
						}
					};
					$gateway->init( $service, new WooPaymentsProvider(), $bridge );

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			unset( $wp->query_vars['order-pay'] );
		}

		$this->assertSame( 1, substr_count( $output, 'id="wc-woocommerce_payments-new-payment-method"' ) );
		$this->assertStringContainsString( 'style="display:none;"', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should hide a checked save-payment control for a subscription cart.
	 */
	public function test_save_payment_method_checkbox_hides_checked_control_for_subscription_cart(): void {
		$this->ensure_wcs_cart_double();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = true;

		$gateway = new NativeWooPaymentsGateway();

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="wc-woocommerce_payments-new-payment-method"' ) );
		$this->assertStringContainsString( 'style="display:none;"', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should keep the save-payment control visible and unchecked for a regular cart.
	 */
	public function test_save_payment_method_checkbox_keeps_visible_unchecked_control_for_regular_cart(): void {
		$this->ensure_wcs_cart_double();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = false;

		$gateway = new NativeWooPaymentsGateway();

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="wc-woocommerce_payments-new-payment-method"' ) );
		$this->assertStringNotContainsString( 'style="display:none;"', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should keep the ordinary save control when subscription change form identity does not match.
	 */
	public function test_payment_fields_reject_mismatched_subscription_change_form_identity(): void {
		$this->ensure_wcs_subscription_detector_double();
		$order = $this->create_order();

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_GET['change_payment_method']          = (string) ( $order->get_id() + 1 );

		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( &$output ): void {
					$gateway = new class() extends NativeWooPaymentsGateway {
						/**
						 * Simulate optional WooCommerce Subscriptions availability.
						 *
						 * @return bool
						 */
						public function is_subscriptions_enabled(): bool {
							return true;
						}
					};

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			unset( $wp->query_vars['order-pay'] );
		}

		$this->assertStringNotContainsString( 'style="display:none;"', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]*>/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should resolve checkout bridge dependencies when payment fields are rendered directly.
	 */
	public function test_payment_fields_resolve_dependencies_without_explicit_init(): void {
		$gateway = new NativeWooPaymentsGateway();

		ob_start();
		$gateway->payment_fields();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-core-checkout-form', $output );
	}

	/**
	 * @testdox Should expose recommended payment methods for the settings provider list.
	 */
	public function test_get_recommended_payment_methods_delegates_to_native_api_client(): void {
		delete_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( 'GB', 'en_US' )
			->willReturn(
				array(
					array(
						'id'    => 'card',
						'title' => 'Cards',
					),
					array(
						'id'       => 'link',
						'title'    => 'Link',
						'type'     => 'available',
						'priority' => '7',
					),
					array(
						'title' => 'Invalid recommendation',
					),
				)
			);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client );

		$result = $gateway->get_recommended_payment_methods( 'GB' );

		$this->assertCount( 2, $result );
		$this->assertSame( 'card', $result[0]['id'] );
		$this->assertTrue( $result[0]['enabled'] );
		$this->assertSame( 0, $result[0]['priority'] );
		$this->assertSame( 'link', $result[1]['id'] );
		$this->assertFalse( $result[1]['enabled'] );
		$this->assertSame( 7, $result[1]['priority'] );

		delete_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' );
	}

	/**
	 * @testdox Should cache recommended payment methods by country and locale.
	 */
	public function test_get_recommended_payment_methods_uses_cached_country_locale_data(): void {
		delete_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( 'GB', 'en_US' )
			->willReturn(
				array(
					array(
						'id'    => 'card',
						'title' => 'Cards',
					),
				)
			);

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client );

		$first_result  = $gateway->get_recommended_payment_methods( 'GB' );
		$second_result = $gateway->get_recommended_payment_methods( 'GB' );

		$this->assertSame( $first_result, $second_result );
		$this->assertSame( 'card', $second_result[0]['id'] );

		delete_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' );
	}

	/**
	 * Create an account service test double for a merchant country.
	 *
	 * @param string $country        Merchant account country.
	 * @param string $capability_key Active payment method capability key.
	 * @param bool   $test_mode      Whether the account is in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_for_country( string $country, string $capability_key = 'card_payments', bool $test_mode = true ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => $country,
				'capabilities' => array( $capability_key => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		return $account_service;
	}

	/**
	 * Create a processing-ready WooPayments provider test double.
	 *
	 * @return WooPaymentsProvider
	 */
	private function create_processing_ready_provider(): WooPaymentsProvider {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		return $provider;
	}

	/**
	 * Create a paid order carrying the provider identifiers native writes.
	 *
	 * @param string $intention_status Provider intention status meta.
	 * @param string $intent_id        Provider intent ID meta.
	 * @param string $charge_id        Provider charge ID meta.
	 * @return WC_Order
	 */
	private function create_order_with_provider_meta( string $intention_status, string $intent_id, string $charge_id = '' ): WC_Order {
		$order = $this->create_order();
		$order->update_meta_data( '_intention_status', $intention_status );
		$order->update_meta_data( '_intent_id', $intent_id );
		if ( '' !== $charge_id ) {
			$order->update_meta_data( '_charge_id', $charge_id );
		}
		$order->save();

		return $order;
	}

	/**
	 * A succeeded payment links the admin order page to its transaction details.
	 *
	 * Native writes `_transaction_id` on every paid order, so WooCommerce renders the
	 * identifier on the order page either way; answering `get_transaction_url()` is
	 * what makes it the link the merchant had before switching runtimes.
	 */
	public function test_get_transaction_url_links_a_succeeded_payment_to_its_intent(): void {
		$order   = $this->create_order_with_provider_meta( 'succeeded', 'pi_transaction_url', 'ch_transaction_url' );
		$gateway = new NativeWooPaymentsGateway();

		$url = $gateway->get_transaction_url( $order );

		$this->assertStringContainsString( 'page=wc-admin', $url );
		$this->assertStringContainsString( 'id=pi_transaction_url', $url );
		// The path is carried unencoded because native composes this through the same
		// admin-URL helper its order notes use, so a note's link and the order page's
		// link are byte-identical. The client plugin rawurlencodes it instead; both
		// forms read back as the same `path` query value, so the destination is the
		// same and the internal consistency is the more useful property.
		$this->assertStringContainsString( 'path=/payments/transactions/details', $url );
	}

	/**
	 * An authorized-but-uncaptured payment still has a transaction to show.
	 */
	public function test_get_transaction_url_links_an_uncaptured_authorization(): void {
		$order   = $this->create_order_with_provider_meta( 'requires_capture', 'pi_uncaptured' );
		$gateway = new NativeWooPaymentsGateway();

		$this->assertStringContainsString( 'id=pi_uncaptured', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * The charge ID carries the link when no intent ID was stored.
	 */
	public function test_get_transaction_url_falls_back_to_the_charge_id(): void {
		$order   = $this->create_order_with_provider_meta( 'succeeded', '', 'ch_only' );
		$gateway = new NativeWooPaymentsGateway();

		$this->assertStringContainsString( 'id=ch_only', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * An unauthorized intention has no payment to link to.
	 *
	 * @dataProvider provider_unlinkable_intention_statuses
	 *
	 * @param string $intention_status Provider intention status meta.
	 */
	public function test_get_transaction_url_is_empty_for_an_unauthorized_intention( string $intention_status ): void {
		$order   = $this->create_order_with_provider_meta( $intention_status, 'pi_unauthorized' );
		$gateway = new NativeWooPaymentsGateway();

		$this->assertSame( '', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * Intention statuses that must not produce a transaction link.
	 *
	 * @return array<string, array<string>>
	 */
	public function provider_unlinkable_intention_statuses(): array {
		return array(
			'requires payment method' => array( 'requires_payment_method' ),
			'requires action'         => array( 'requires_action' ),
			'canceled'                => array( 'canceled' ),
			'no status recorded'      => array( '' ),
		);
	}

	/**
	 * A SetupIntent stores no payment, so it must not be linked as a transaction.
	 */
	public function test_get_transaction_url_refuses_a_setup_intent(): void {
		$order   = $this->create_order_with_provider_meta( 'succeeded', 'seti_saved_card' );
		$gateway = new NativeWooPaymentsGateway();

		$this->assertSame( '', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * An order carrying no provider identifier has nothing to link.
	 */
	public function test_get_transaction_url_is_empty_without_provider_identifiers(): void {
		$order   = $this->create_order_with_provider_meta( 'succeeded', '' );
		$gateway = new NativeWooPaymentsGateway();

		$this->assertSame( '', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * Create an order for gateway tests.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( '12.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create a saved WooPayments card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Create a fraud-prevention service with controlled account eligibility.
	 *
	 * @param bool        $eligible Whether card-testing protection is eligible.
	 * @param \WC_Session $session  WooCommerce session test double.
	 * @return WooPaymentsFraudPreventionService
	 */
	private function create_fraud_prevention_service( bool $eligible, \WC_Session $session ): WooPaymentsFraudPreventionService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( array( 'card_testing_protection_eligible' => $eligible ) );

		$service = new WooPaymentsFraudPreventionService( $session );
		$service->init( $account_service );

		return $service;
	}

	/**
	 * Create a duplicate-payment prevention service.
	 *
	 * @param \WC_Session               $session    WooCommerce session test double.
	 * @param WooPaymentsApiClient|null $api_client Optional API client.
	 * @return WooPaymentsDuplicatePaymentPreventionService
	 */
	private function create_duplicate_payment_prevention_service( \WC_Session $session, ?WooPaymentsApiClient $api_client = null ): WooPaymentsDuplicatePaymentPreventionService {
		$service = new WooPaymentsDuplicatePaymentPreventionService( $session );
		$service->init(
			$api_client ?? $this->createStub( WooPaymentsApiClient::class ),
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new WooPaymentsOrderDataService()
		);

		return $service;
	}

	/**
	 * Create a PaymentIntent response.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Intent status.
	 * @param int      $amount Intent amount in minor units.
	 * @return array<string,mixed>
	 */
	private function create_intent_response( WC_Order $order, string $status, int $amount ): array {
		return array(
			'id'       => 'pi_existing',
			'status'   => $status,
			'amount'   => $amount,
			'currency' => strtolower( $order->get_currency() ),
			'customer' => 'cus_existing',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array(
						'id'             => 'ch_existing',
						'payment_method' => 'pm_existing',
					),
				),
			),
		);
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return \WC_Session
	 */
	private function create_session(): \WC_Session {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}

	/**
	 * Run assertions with controlled WooPayments gateway settings.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @param callable            $callback Assertion callback.
	 * @return void
	 */
	private function with_gateway_settings( array $settings, callable $callback ): void {
		$filter = static function () use ( $settings ) {
			return $settings;
		};

		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $filter );

		try {
			$callback();
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $filter );
		}
	}

	/**
	 * Ensure a minimal WooCommerce Subscriptions detector double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_subscription_detector_double(): void {
		if ( function_exists( 'wcs_is_subscription' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public detector contract.
		eval( 'namespace { function wcs_is_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : $subscription_id; return in_array( absint( $subscription_id ), $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );
	}

	/**
	 * Ensure a minimal WooCommerce Subscriptions cart double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_cart_double(): void {
		if ( class_exists( 'WC_Subscriptions_Cart', false ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public cart contract.
		eval( 'class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return (bool) ( $GLOBALS["wcpay_test_cart_contains_subscription"] ?? false ); } }' );
	}

	/**
	 * Ensure a minimal WC Subscriptions change-payment gateway double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_change_payment_gateway_double(): void {
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- The production class is optional; tests need a process-local stand-in.
		eval(
			'class WC_Subscriptions_Change_Payment_Gateway {
				public static $updated_payment_methods = array();
				public static $updated_all_payment_methods = array();
				public static $will_update_all_payment_methods = true;

				public static function reset() {
					self::$updated_payment_methods = array();
					self::$updated_all_payment_methods = array();
					self::$will_update_all_payment_methods = true;
				}

				public static function update_payment_method( $order, $gateway_id ) {
					self::$updated_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
				}

				public static function will_subscription_update_all_payment_methods( $order ) {
					unset( $order );
					return self::$will_update_all_payment_methods;
				}

				public static function update_all_payment_methods_from_subscription( $order, $gateway_id ) {
					self::$updated_all_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
					return true;
				}
			}'
		);
	}
}
