<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedTransactionRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentMappingContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Legacy as StoreApiLegacy;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext as StoreApiPaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult as StoreApiPaymentResult;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPublicFraudServices;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\LegacyRuntimeProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the NativeWooPaymentsGateway class.
 */
class NativeWooPaymentsGatewayTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Make core Multi-Currency own the runtime, or not.
	 *
	 * @param bool $enabled Whether core Multi-Currency should own the runtime.
	 */
	private function set_core_multi_currency( bool $enabled ): void {
		$arbiter   = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_core_register' ) )
			->getMock();
		$container = wc_get_container();
		// Keep the real feature definition working if FeaturesController registers it while the mock is in place.
		$arbiter->init( $container->get( NativePaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'should_core_register' )->willReturn( $enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
	}

	/**
	 * Make native the payments owner, which the renewal handlers require.
	 */
	private function make_native_own_payments(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Make native the payments owner in the active tier, the only tier where the gateway is offered at checkout.
	 */
	private function activate_native_tier(): void {
		$this->make_native_own_payments();
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::ACTIVE, true );
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
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
		$subscription_handlers = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'attached' );
		$subscription_handlers->setAccessible( true );
		$subscription_handlers->setValue( null, false );
		$classic_checkout_fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$classic_checkout_fallback_hooks->setAccessible( true );
		$classic_checkout_fallback_hooks->setValue( null, false );
		remove_all_filters( 'woocommerce_email_classes' );
		remove_all_filters( 'wcs_get_retry_rule_raw' );
		unset( $_POST['wcpay-setup-intent'] );
		unset( $_POST['wcpay-payment-method'] );
		unset( $_POST['wcpay-payment-method-error-message'] );
		unset( $_POST['wcpay-payment-method-error-code'] );
		unset( $_POST['wcpay-is-platform-payment-method'] );
		unset( $_POST['wcpay-express-payment-method-types'] );
		unset( $_POST['wcpay-express-checkout-context'] );
		unset( $_POST['wcpay-fraud-prevention-token'] );
		unset( $_POST['is-woopay-preflight-check'] );
		unset( $_POST['platform-checkout-intent'] );
		unset( $_POST['is_woopay'] );
		unset( $_POST['woocommerce_pay'] );
		unset( $_POST['_wcsnonce'] );
		unset( $_POST['change_payment_method'] );
		unset( $_POST['woocommerce_change_payment'] );
		unset( $_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] );
		unset( $_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-new-payment-method' ] );
		unset( $_POST['update_all_subscriptions_payment_method'] );
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'], $GLOBALS['wcpay_test_cart_contains_subscription'] );
		if ( WC() && WC()->session ) {
			WC()->session->set( 'wcpay_paid_intent_id', null );
		}
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'reset' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
		}
		wc_clear_notices();
		wp_set_current_user( 0 );
		$this->reset_container_replacements();
		wc_get_container()->reset_all_resolved();
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
				$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $gateway->get_icon() );
				$this->assertStringContainsString( 'alt="Visa"', $gateway->get_icon() );
				$this->assertStringContainsString( '/assets/images/payment-methods/mastercard-color.svg', $gateway->get_icon() );
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
	 * @testdox Should brand the gateway titles by account country like the reference client
	 */
	public function test_gateway_titles_follow_account_country_branding(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'afterpay_clearpay' );
		$this->assertNotNull( $definition );

		$expected_titles = array(
			'GB' => 'Clearpay',
			'US' => 'Cash App Afterpay',
			'AU' => 'Afterpay',
		);

		foreach ( $expected_titles as $account_country => $expected_title ) {
			$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
				->getMock();
			$account_service->method( 'get_cached_account_data' )->willReturn(
				array( 'country' => $account_country )
			);
			$account_service->method( 'is_gateway_enabled' )->willReturn( true );
			$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );
			$gateway->handle_init();

			$this->assertSame( $expected_title, $gateway->get_title(), "Gateway title for {$account_country}" );
			$this->assertSame( "WooPayments ({$expected_title})", $gateway->get_method_title(), "Method title for {$account_country}" );
		}
	}

	/**
	 * @testdox Should hide the gateway when the account's customer-supported currencies exclude the store currency
	 */
	public function test_gateway_availability_follows_account_customer_supported_currencies(): void {
		$this->activate_native_tier();
		$supported_currencies = array( 'usd' );
		$account_service      = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( &$supported_currencies ): array {
				return array(
					'country'             => 'US',
					'capabilities'        => array( 'card_payments' => 'active' ),
					'customer_currencies' => array( 'supported' => $supported_currencies ),
				);
			}
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function (): string {
			return 'EUR';
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway();
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available(), 'EUR store must be unavailable when the account only supports usd.' );

			$supported_currencies = array( 'usd', 'eur' );

			$this->assertTrue( $gateway->is_available(), 'EUR store must be available when the account supports eur.' );

			$supported_currencies = array();

			$this->assertTrue( $gateway->is_available(), 'An empty supported list must not disable the gateway.' );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should hide domestic-only methods when the checkout currency is not the account domestic currency
	 * @dataProvider provider_domestic_only_currency_cases
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @param string $account_country   Merchant account country.
	 * @param mixed  $store_currencies  Account store_currencies payload, or null to omit it.
	 * @param string $capability_key    Active capability key.
	 * @param string $currency          Checkout currency.
	 * @param bool   $expected          Expected availability.
	 */
	public function test_domestic_only_methods_require_the_account_domestic_currency(
		string $payment_method_id,
		string $account_country,
		$store_currencies,
		string $capability_key,
		string $currency,
		bool $expected
	): void {
		$this->activate_native_tier();
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
		$this->assertNotNull( $definition );

		$account_data = array(
			'country'      => $account_country,
			'capabilities' => array( $capability_key => 'active' ),
		);
		if ( null !== $store_currencies ) {
			$account_data['store_currencies'] = $store_currencies;
		}

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function () use ( $currency ): string {
			return $currency;
		};
		$settings_option = "pre_option_woocommerce_woocommerce_payments_{$payment_method_id}_settings";
		add_filter( $settings_option, $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertSame( $expected, $gateway->is_available() );
		} finally {
			remove_filter( $settings_option, $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * Domestic-only currency gate cases mirroring the reference is_currency_valid().
	 *
	 * @return array<string,array{string,string,mixed,string,string,bool}>
	 */
	public function provider_domestic_only_currency_cases(): array {
		return array(
			'US Afterpay in USD is domestic'     => array( 'afterpay_clearpay', 'US', array( 'default' => 'usd' ), 'afterpay_clearpay_payments', 'USD', true ),
			'US Afterpay in GBP is not domestic' => array( 'afterpay_clearpay', 'US', array( 'default' => 'usd' ), 'afterpay_clearpay_payments', 'GBP', false ),
			'DE Klarna in EUR without store_currencies uses country locale data' => array( 'klarna', 'DE', null, 'klarna_payments', 'EUR', true ),
			'DE Klarna in SEK is not domestic'   => array( 'klarna', 'DE', null, 'klarna_payments', 'SEK', false ),
			'unknown country falls back to account default currency' => array( 'klarna', 'XX', array( 'default' => 'sek' ), 'klarna_payments', 'SEK', true ),
		);
	}

	/**
	 * @testdox Should hide a split gateway when its definition does not support the checkout currency.
	 */
	public function test_split_gateway_availability_follows_payment_method_definition(): void {
		$this->activate_native_tier();
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
	 * @testdox Should recalculate availability when the checkout currency and payment method definition change.
	 * WooPayments client 11.1.0 restricts Bancontact to EUR in `BancontactDefinition.php`, applied by `class-upe-payment-method.php::is_currency_valid()` and `is_enabled_at_checkout()`, while Card has no currency restriction.
	 */
	public function test_gateway_availability_recalculates_for_currency_and_payment_method_definition_changes(): void {
		$this->activate_native_tier();
		$registry              = new WooPaymentsPaymentMethodRegistry();
		$card_definition       = $registry->get( 'card' );
		$bancontact_definition = $registry->get( 'bancontact' );
		$this->assertNotNull( $card_definition );
		$this->assertNotNull( $bancontact_definition );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'BE',
				'capabilities' => array(
					'card_payments'       => 'active',
					'bancontact_payments' => 'active',
				),
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
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $card_definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$payment_method_definition = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'payment_method_definition' );
			$payment_method_definition->setAccessible( true );
			$availability_matrix = array(
				'USD/Card'                 => array( 'USD', $card_definition, true ),
				'USD/Bancontact'           => array( 'USD', $bancontact_definition, false ),
				'EUR/Card'                 => array( 'EUR', $card_definition, true ),
				'EUR/Bancontact'           => array( 'EUR', $bancontact_definition, true ),
				'USD/Bancontact after EUR' => array( 'USD', $bancontact_definition, false ),
			);

			foreach ( $availability_matrix as $case => $availability_case ) {
				list( $currency, $definition, $expected ) = $availability_case;
				$payment_method_definition->setValue( $gateway, $definition );

				$this->assertSame( $expected, $gateway->is_available(), "Availability for {$case}" );
			}
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * Client 11.1.0 `P24Definition.php` supports EUR and PLN for Polish shoppers; `class-upe-payment-method.php` applies the
	 * currencies in `is_currency_valid()` (:311-326) and hands the countries to checkout through `get_countries()` (:386-397).
	 *
	 * @testdox Should offer the P24 gateway only for the client's P24 currencies and keep its Polish shopper rule.
	 */
	public function test_p24_gateway_availability_follows_client_currencies_and_shopper_country(): void {
		$this->activate_native_tier();
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'p24' );
		$this->assertNotNull( $definition );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency        = 'PLN';
		$currency_filter = static function () use ( &$currency ): string {
			return $currency;
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_p24_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new NativeWooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'PL', 'p24_payments', true, 'pln' ) );

			$this->assertSame( OrderPaymentStore::GATEWAY_ID_PREFIX . 'p24', $gateway->id );
			$availability_by_currency = array(
				'PLN' => true,
				'EUR' => true,
				'USD' => false,
				'GBP' => false,
			);
			foreach ( $availability_by_currency as $currency_case => $expected ) {
				$currency = $currency_case;
				$this->assertSame( $expected, $gateway->is_available(), "P24 availability for {$currency_case}" );
			}
			$this->assertSame( array( 'PL' ), $gateway->get_payment_method_definition()->get_supported_countries( 'PL' ) );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_p24_settings', $settings_filter );
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
	 * @param string   $account_default_currency   Account default (domestic) currency.
	 */
	public function test_gateway_availability_keeps_shopper_country_rules_separate_from_merchant_country_rules(
		string $payment_method_id,
		string $account_country,
		string $currency,
		string $capability_key,
		array $expected_shopper_countries,
		string $account_default_currency = 'usd'
	): void {
		$this->activate_native_tier();
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
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( $account_country, $capability_key, true, $account_default_currency ) );

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
				'PR',
				'USD',
				'affirm_payments',
				array( 'US', 'CA' ),
			),
			'Afterpay admits a nonmatching merchant while preserving domestic shopper filtering' => array(
				'afterpay_clearpay',
				'PR',
				'USD',
				'afterpay_clearpay_payments',
				array( 'US', 'CA', 'AU', 'NZ', 'GB' ),
			),
			'Klarna admits a nonmatching EEA merchant while preserving calculated shopper filtering' => array(
				'klarna',
				'EE',
				'EUR',
				'klarna_payments',
				array( 'AT', 'BE', 'FI', 'FR', 'DE', 'IE', 'IT', 'NL', 'ES' ),
				'eur',
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
		$this->activate_native_tier();
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
	 * Client 11.1.0 offers an express gateway in the list only with both placement controls on (class-wc-payment-gateway-wcpay.php:881-887)
	 * and only when the method is among the methods enabled at checkout (:941, :4778-4810), which the Apple Pay toggle never writes.
	 *
	 * @testdox Should place an express gateway in the payment-method list only with both placement controls on and the method enabled at checkout.
	 */
	public function test_express_gateway_availability_requires_payment_method_list_placement(): void {
		$this->activate_native_tier();
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'apple_pay' );
		$this->assertNotNull( $definition );

		$canonical_settings = array(
			'express_checkout_in_payment_methods' => 'no',
			'upe_enabled_payment_method_ids'      => array( 'card', 'apple_pay' ),
		);
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
			$this->assertTrue( $gateway->is_available(), 'Both placement controls should expose a method enabled at checkout.' );

			// The Apple Pay toggle enables its gateway but never adds apple_pay to the methods enabled at checkout.
			$canonical_settings['upe_enabled_payment_method_ids'] = array( 'card' );
			$this->assertFalse( $gateway->is_available(), 'A method missing from the methods enabled at checkout is not offered, placement or not.' );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_apple_pay_settings', $split_settings );
			remove_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * Client 11.1.0 skips the placement check in admin (class-wc-payment-gateway-wcpay.php:881) but still requires the methods enabled at
	 * checkout (:941): Amazon Pay, whose toggle writes amazon_pay to that list, is offered; Apple Pay and Google Pay, whose toggles never do, are not.
	 *
	 * @testdox In admin, an express gateway is offered only when it is enabled at checkout, whatever the placement, while card and an enabled Klarna stay offered.
	 */
	public function test_express_gateways_are_unavailable_in_admin_regardless_of_placement(): void {
		$this->activate_native_tier();
		$registry           = new WooPaymentsPaymentMethodRegistry();
		$canonical_settings = array(
			'express_checkout_in_payment_methods' => 'no',
			'upe_enabled_payment_method_ids'      => array( 'card', 'klarna', 'amazon_pay' ),
		);
		$feature_flag       = '0';
		$account_service    = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'          => 'US',
				'capabilities'     => array(
					'card_payments'       => 'active',
					'klarna_payments'     => 'active',
					'amazon_pay_payments' => 'active',
				),
				'store_currencies' => array( 'default' => 'usd' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( &$canonical_settings ) {
				return $canonical_settings[ $key ] ?? $fallback;
			}
		);
		$enabled_settings = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$feature_filter   = static function () use ( &$feature_flag ): string {
			return $feature_flag;
		};
		$currency_filter  = static function (): string {
			return 'USD';
		};
		$settings_options = array( 'pre_option_woocommerce_woocommerce_payments_settings' );
		foreach ( array( 'apple_pay', 'google_pay', 'amazon_pay', 'klarna' ) as $payment_method_id ) {
			$settings_options[] = "pre_option_woocommerce_woocommerce_payments_{$payment_method_id}_settings";
		}
		foreach ( $settings_options as $settings_option ) {
			add_filter( $settings_option, $enabled_settings );
		}
		add_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );
		$previous_cart = WC()->cart;
		WC()->cart     = new \WC_Cart();
		WC()->cart->set_total( '0' );
		set_current_screen( 'edit-shop_subscription' );

		try {
			$this->assertTrue( is_admin(), 'The subscription edit screen is an admin request.' );
			$provider = $this->create_processing_ready_provider();
			$gateways = array();
			foreach ( array( 'card', 'apple_pay', 'google_pay', 'amazon_pay', 'klarna' ) as $payment_method_id ) {
				$definition = $registry->get( $payment_method_id );
				$this->assertNotNull( $definition, "{$payment_method_id} should be registered." );
				$gateway = new NativeWooPaymentsGateway( $definition );
				$gateway->init( new RecordingPaymentProcessingService(), $provider, null, null, $account_service );
				$gateways[ $payment_method_id ] = $gateway;
			}

			$placements = array(
				'placement off' => array( 'no', '0' ),
				'placement on'  => array( 'yes', '1' ),
			);
			foreach ( $placements as $placement => list( $setting, $flag ) ) {
				$canonical_settings['express_checkout_in_payment_methods'] = $setting;
				$feature_flag = $flag;

				foreach ( array( 'apple_pay', 'google_pay' ) as $payment_method_id ) {
					$this->assertFalse( $gateways[ $payment_method_id ]->is_available(), "{$payment_method_id} is never enabled at checkout, so never offered in admin ({$placement})." );
				}
				$this->assertTrue( $gateways['amazon_pay']->is_available(), "Amazon Pay enabled at checkout is offered in admin ({$placement})." );
				$this->assertTrue( $gateways['card']->is_available(), "Card stays offered in admin ({$placement})." );
				$this->assertTrue( $gateways['klarna']->is_available(), "An enabled Klarna stays offered in admin ({$placement})." );
			}
		} finally {
			set_current_screen( 'front' );
			WC()->cart = $previous_cart;
			foreach ( $settings_options as $settings_option ) {
				remove_filter( $settings_option, $enabled_settings );
			}
			remove_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should apply definition amount limits to split gateway availability.
	 */
	public function test_split_gateway_availability_respects_definition_amount_limits(): void {
		$this->activate_native_tier();
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
		$this->activate_native_tier();
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
		$this->activate_native_tier();
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

				$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $gateway->get_icon() );
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

				$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $gateway->get_icon() );
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
	 * @testdox Should keep amount and date changes while the Stripe Billing module is not loaded, even with the toggle on.
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
		$this->make_native_own_payments();
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
		$method->setAccessible( true );

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
	 * @testdox Should leave a card subscription on automatic renewal because card is a reusable native method.
	 *
	 * Mirrors client 11.1.0 `tr:1399-1401` (`maybe_force_subscription_to_manual`): a reusable gateway
	 * (card, Amazon Pay) returns before touching `requires_manual_renewal` or the original-method meta.
	 * `test_maybe_force_subscription_to_manual_preserves_non_reusable_method` above only covers the
	 * non-reusable (split gateway) branch; this covers the reusable early-return branch the client
	 * takes for `WC_Payment_Gateway_WCPay::GATEWAY_ID` itself.
	 */
	public function test_maybe_force_subscription_to_manual_leaves_card_subscription_automatic(): void {
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
		$subscription->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$subscription->save();

		( new NativeWooPaymentsGateway() )->maybe_force_subscription_to_manual( $subscription );

		$this->assertFalse( $subscription->is_manual() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $subscription->get_payment_method() );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_original_payment_method_id', true ) );
	}

	/**
	 * @testdox Should attach base subscription renewal handlers once across gateway instances.
	 */
	public function test_subscription_handler_registration_is_idempotent_across_gateway_instances(): void {
		$this->make_native_own_payments();
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
		$this->make_native_own_payments();
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$active_token = $this->create_card_token( $user_id, 'pm_amazon_renewal' );
		$order->add_payment_token( $active_token );
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
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Unusable saved renewal methods fail through the native lifecycle with an actionable note.
	 */
	public function test_scheduled_subscription_payment_fails_unusable_saved_method_with_actionable_note(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_unusable_saved_method' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->update_meta_data( '_payment_method_id', 'pm_unusable_saved_method' );
		$order->save();

		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Last native request.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_request_data = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @throws WooPaymentsApiException Always, to model the unusable saved method.
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				throw new WooPaymentsApiException( 'Provider diagnostic for pm_unusable_saved_method.', 'payment_method_no_longer_available', 400, 'invalid_request_error', '', array(), 'pi_unusable_saved_method' );
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_renewal' );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wc_get_container()->replace( WooPaymentsCustomerService::class, $customer_service );
		wc_get_container()->reset_all_resolved();

		$status_changes         = 0;
		$status_change_callback = static function ( int $order_id, string $from, string $to ) use ( $order, &$status_changes ): void {
			unset( $from );
			if ( $order->get_id() === $order_id && 'failed' === $to ) {
				++$status_changes;
			}
		};
		add_action(
			'woocommerce_order_status_changed',
			$status_change_callback,
			10,
			3
		);

		try {
			$gateway = new NativeWooPaymentsGateway();
			$gateway->init(
				wc_get_container()->get( PaymentProcessingService::class ),
				wc_get_container()->get( WooPaymentsProvider::class )
			);
			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
			$order            = wc_get_order( $order->get_id() );
			$notes            = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$actionable_notes = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'the saved payment method <strong>' . $token->get_display_name() . '</strong> can no longer be used' )
			);
			$raw_notes        = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'Provider diagnostic' ) || false !== strpos( (string) $note->content, 'pm_unusable_saved_method' )
			);

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'failed', $order->get_status() );
			$this->assertSame( 1, $status_changes );
			$this->assertCount( 1, $actionable_notes );
			$this->assertCount( 0, $raw_notes );
			$this->assertArrayNotHasKey( 'saved_payment_method_display_name', $api_client->last_request_data );
			$this->assertSame( OrderPaymentStore::GATEWAY_ID, $order->get_payment_method() );
			$this->assertSame( 'pm_unusable_saved_method', $order->get_meta( '_payment_method_id', true ) );
			$order->update_meta_data( '_intention_status', 'processing' );
			$order->save_meta_data();
			$this->assertSame( 'processing', $order->get_meta( '_intention_status', true ) );

			wc_get_container()->get( WooPaymentsEventIngestor::class )->process(
				array(
					'id'   => 'evt_unusable_method_replay',
					'type' => 'payment_intent.payment_failed',
					'data' => array(
						'object' => array(
							'id'                 => 'pi_unusable_saved_method',
							'status'             => 'requires_payment_method',
							'currency'           => 'usd',
							'payment_method'     => 'pm_unusable_saved_method',
							'metadata'           => array(
								'order_id'  => (string) $order->get_id(),
								'order_key' => $order->get_order_key(),
							),
							'last_payment_error' => array(
								'code'           => 'payment_method_no_longer_available',
								'message'        => 'Raw provider diagnostic for pm_unusable_saved_method.',
								'payment_method' => array(
									'id'   => 'pm_unusable_saved_method',
									'type' => 'card',
								),
							),
						),
					),
				)
			);
			$order            = wc_get_order( $order->get_id() );
			$notes            = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$actionable_notes = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'the saved payment method <strong>' . $token->get_display_name() . '</strong> can no longer be used' )
			);
			$raw_notes        = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'Raw provider diagnostic' ) || false !== strpos( (string) $note->content, 'pm_unusable_saved_method' )
			);

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'failed', $order->get_status() );
			$this->assertSame( 1, $status_changes );
			$this->assertSame( 'pi_unusable_saved_method', $order->get_meta( '_intent_id', true ) );
			$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
			$this->assertCount( 1, $actionable_notes );
			$this->assertCount( 0, $raw_notes );
		} finally {
			remove_action( 'woocommerce_order_status_changed', $status_change_callback, 10 );
			delete_transient( 'wcpay_processed_event_' . md5( 'evt_unusable_method_replay' ) );
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
		}
	}

	/**
	 * @testdox Should register WooPayments failed-renewal authentication emails when subscriptions are supported.
	 */
	public function test_subscription_support_registers_failed_renewal_authentication_emails(): void {
		$this->make_native_own_payments();
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
		$this->make_native_own_payments();
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
		$this->make_native_own_payments();
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
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, $this->create_unhooked_token_service() );

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
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Should charge tokenized renewals while the Stripe Billing module is not loaded, even with the Stripe Billing options set.
	 */
	public function test_scheduled_subscription_payment_uses_tokenized_renewal_when_deprecated_stripe_billing_flags_remain(): void {
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( '_wcpay_feature_stripe_billing', '1' );

		try {
			$user_id = self::factory()->user->create();
			$order   = $this->create_order();
			$order->set_customer_id( $user_id );
			$active_token = $this->create_card_token( $user_id, 'pm_tokenized_renewal' );
			$order->add_payment_token( $active_token );
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
			$this->assertSame(
				array(
					'scheduled_subscription_payment'    => true,
					'saved_payment_method_display_name' => $active_token->get_display_name(),
				),
				$service->last_checkout_context->get_provider_data()
			);
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
		$active_token = $this->create_card_token( $user_id, 'pm_renewal' );
		$renewal->add_payment_token( $active_token );
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
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
				'renewal_mandate'                   => 'mandate_parent',
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
	 * @testdox A customer-action hook callback that throws a $throwable_class leaves the renewal pending and fails the scheduled action.
	 *
	 * Client 11.1.0 `gw:1921` runs the hook without a catch, so the throwable reaches Action Scheduler before
	 * `mark_payment_failed()` (monitor ruling 2026-10-04 (2)). Native logs it whatever the logging setting.
	 *
	 * @testWith ["RuntimeException"]
	 *           ["TypeError"]
	 *
	 * @param string $throwable_class Class the hook callback throws.
	 */
	public function test_scheduled_subscription_payment_rethrows_when_requires_action_hook_throws( string $throwable_class ): void {
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

		$thrown = new $throwable_class( 'email callback failed' );
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( $thrown ): void {
				throw $thrown;
			}
		);
		$logger = RecordingWcLogger::install();

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		}

		$this->assertSame( $thrown, $caught, 'The hook failure reaches the scheduled action.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'Failed to run WooPayments subscription renewal authentication hooks.' === $line[1] ) );
		$this->assertCount( 1, $lines, 'The failure is logged whatever the logging setting.' );
		$this->assertSame( $throwable_class, $logger->contexts[ $lines[0] ]['exception'] ?? '' );
	}

	/**
	 * @testdox A customer-action hook callback that throws $_dataName is logged by the platform's status and code, never a message.
	 *
	 * A callback can call the platform and let its error out, directly or wrapped, so the message is not native text.
	 *
	 * @dataProvider hook_platform_failures
	 *
	 * @param bool $wrapped Whether the callback wraps the platform error in its own exception.
	 */
	public function test_requires_action_hook_failure_log_leaves_out_platform_text( bool $wrapped ): void {
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

		$platform_error = self::make_provider_error();
		$thrown         = $wrapped ? new \RuntimeException( 'Reminder email failed: ' . $platform_error->getMessage(), 0, $platform_error ) : $platform_error;
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( $thrown ): void {
				throw $thrown;
			}
		);
		$logger = RecordingWcLogger::install();

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		}

		$this->assertSame( $thrown, $caught, 'The hook failure still reaches the scheduled action.' );
		$context = $this->get_logged_context( $logger, 'Failed to run WooPayments subscription renewal authentication hooks.' );
		$this->assertSame( array( get_class( $thrown ), 404, 'resource_missing' ), array( $context['exception'], $context['http_status'], $context['error_code'] ) );
		$this->assertSame( array( $order->get_id(), 'pi_requires_action' ), array( $context['order_id'], $context['intent_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Platform errors a hook callback can throw.
	 *
	 * @return array<string,array{bool}>
	 */
	public function hook_platform_failures(): array {
		return array(
			'a platform error'               => array( false ),
			'its own exception wrapping one' => array( true ),
		);
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
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. The SetupIntent is now REC-3DS-5's real succeeded
	 * challenge (`Fixtures/rec-t3-3ds-manual.json`, pair `my_account_setup_intent_challenge_completed`):
	 * a My Account add-payment-method SetupIntent that succeeded after a hosted 3DS challenge.
	 */
	public function test_add_payment_method_saves_successful_setup_intent_token(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$recorded                    = $this->load_recorded_manual_3ds_entry( 'my_account_setup_intent_challenge_completed' );
		$_POST['wcpay-setup-intent'] = $recorded['body']['id'];

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( $recorded['body']['id'] )
			->willReturn( $recorded['body'] );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service
			->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( $recorded['body']['payment_method'], $user_id )
			->willReturn( $this->create_card_token( $user_id, (string) $recorded['body']['payment_method'] ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, (string) $recorded['body']['customer'] ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should refuse to save a payment method from a non-succeeded SetupIntent.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. REC-3DS-5 failed
	 * (`Fixtures/rec-t3-3ds-manual.json`, pair `my_account_setup_intent_challenge_failed`): a My
	 * Account add-payment-method SetupIntent left `requires_payment_method` after a failed 3DS
	 * challenge. `add_payment_method()` returns its error before ever calling the token service
	 * (`NativeWooPaymentsGateway.php:645-647`), so a hosted challenge failure must never attach a
	 * payment method to the shopper's account. The notice is the client's exact copy, with no
	 * trailing period (client 11.1.0 `gw:4446-4451`).
	 */
	public function test_add_payment_method_refuses_non_succeeded_setup_intent_without_saving_token(): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$recorded                    = $this->load_recorded_manual_3ds_entry( 'my_account_setup_intent_challenge_failed' );
		$_POST['wcpay-setup-intent'] = $recorded['body']['id'];

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_setup_intention' )
			->with( $recorded['body']['id'] )
			->willReturn( $recorded['body'] );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->expects( $this->never() )->method( 'get_or_create_token_for_user' );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, (string) $recorded['body']['customer'] ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( array( 'result' => 'error' ), $result );
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertSame(
			'Failed to add the provided payment method. Please try again later',
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * Load one recorded REC-3DS-2/3/5 manual-run entry's response body by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{body:array<string,mixed>}
	 */
	private function load_recorded_manual_3ds_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Providers/WooPayments/Fixtures/rec-t3-3ds-manual.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array( 'body' => $entry['response']['body'] );
			}
		}

		$this->fail( "REC-3DS manual fixture has no entry for pair '$pair'." );
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
					'customer'       => 'cus_me',
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
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

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
	 *
	 * The customer check is native's own; its notice reuses the client's non-succeeded copy verbatim,
	 * with no trailing period (client 11.1.0 `class-wc-payment-gateway-wcpay.php:4448`).
	 */
	public function test_add_payment_method_rejects_setup_intent_owned_by_another_customer(): void {
		wc_clear_notices();
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
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertSame(
			'Failed to add the provided payment method. Please try again later',
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should refuse to save a setup intent without a customer to bind it to: $_dataName.
	 *
	 * The client refuses a user with no stored WooPayments customer before it reads the intent, with its "not able"
	 * copy (client 11.1.0 `class-wc-payment-gateway-wcpay.php:4434-4439`). Native also refuses an intent without a
	 * customer, with the non-succeeded copy its customer comparison already uses (`:4448`).
	 *
	 * @dataProvider setup_intent_without_customer_provider
	 *
	 * @param string|null $user_customer   Customer stored for the current user.
	 * @param string|null $intent_customer Customer on the SetupIntent.
	 * @param bool        $reads_intent    Whether the SetupIntent is read.
	 * @param string      $expected        Expected shopper notice.
	 */
	public function test_add_payment_method_refuses_setup_intent_without_a_customer_to_bind( ?string $user_customer, ?string $intent_customer, bool $reads_intent, string $expected ): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_foreign';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client
			->expects( $reads_intent ? $this->once() : $this->never() )
			->method( 'get_setup_intention' )
			->willReturn(
				array_filter(
					array(
						'id'             => 'seti_foreign',
						'status'         => 'succeeded',
						'customer'       => $intent_customer,
						'payment_method' => 'pm_foreign',
					)
				)
			);

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->expects( $this->never() )->method( 'get_or_create_token_for_user' );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, $user_customer ) );

		$this->assertSame( array( 'result' => 'error' ), $gateway->add_payment_method() );
		$this->assertSame( $expected, wc_get_notices( 'error' )[0]['notice'] ?? '' );
	}

	/**
	 * Customer pairs add_payment_method() has nothing to bind the intent to.
	 *
	 * @return array<string,array{0:?string,1:?string,2:bool,3:string}>
	 */
	public function setup_intent_without_customer_provider(): array {
		return array(
			'user without a WooPayments customer' => array( null, 'cus_victim', false, "We're not able to add this payment method. Please try again later" ),
			'intent without a customer'           => array( 'cus_me', null, true, 'Failed to add the provided payment method. Please try again later' ),
		);
	}

	/**
	 * Create a customer service that knows one user's stored WooPayments customer.
	 *
	 * @param int         $user_id     User ID.
	 * @param string|null $customer_id Stored customer ID, or null when the user has none.
	 * @return WooPaymentsCustomerService
	 */
	private function create_customer_service_for_user( int $user_id, ?string $customer_id ): WooPaymentsCustomerService {
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$customer_service->method( 'get_customer_id_by_user_id' )->with( $user_id )->willReturn( $customer_id );

		return $customer_service;
	}

	/**
	 * @testdox Should refuse a setup intent it cannot save with the client's copy: $_dataName.
	 *
	 * Oracle: client 11.1.0 `class-wc-payment-gateway-wcpay.php:4437` "We're not able to add this payment
	 * method. Please try again later", with no trailing period. Native uses that copy for a succeeded
	 * intent without a payment method, a token that cannot be created, and an unexpected non-API exception
	 * (where the client shows the exception's own text).
	 *
	 * @dataProvider unsaveable_setup_intent_provider
	 *
	 * @param string $failure Which step fails: `no_payment_method`, `no_token` or `exception`.
	 */
	public function test_add_payment_method_refuses_unsaveable_setup_intent_with_client_copy( string $failure ): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client    = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$intent_lookup = $api_client->method( 'get_setup_intention' )->with( 'seti_native' );
		if ( 'exception' === $failure ) {
			$intent_lookup->willThrowException( new \RuntimeException( 'Unexpected failure.' ) );
		} else {
			$intent_lookup->willReturn(
				array_filter(
					array(
						'id'             => 'seti_native',
						'status'         => 'succeeded',
						'customer'       => 'cus_me',
						'payment_method' => 'no_payment_method' === $failure ? null : 'pm_added',
					)
				)
			);
		}

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willReturn( null );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( array( 'result' => 'error' ), $result );
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertSame(
			"We're not able to add this payment method. Please try again later",
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should show the client's filtered message when the setup intent lookup fails with an API error: $_dataName.
	 *
	 * Oracle: client 11.1.0 `class-wc-payment-gateway-wcpay.php:4467-4468` passes every exception through
	 * `WC_Payments_Utils::get_filtered_error_message()` (`class-wc-payments-utils.php:769-819`) and logs it at info
	 * level (`:4471`).
	 *
	 * @dataProvider add_payment_method_api_error_provider
	 *
	 * @param WooPaymentsApiException $exception Exception thrown by the setup intent lookup.
	 * @param string                  $expected  Expected shopper notice.
	 */
	public function test_add_payment_method_filters_api_errors_like_the_client( WooPaymentsApiException $exception, string $expected ): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client->method( 'get_setup_intention' )->willThrowException( $exception );

		$this->enable_debug_logging();
		$logger  = RecordingWcLogger::install();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, null, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$this->assertSame( array( 'result' => 'error' ), $gateway->add_payment_method() );
		$this->assertSame( $expected, wc_get_notices( 'error' )[0]['notice'] ?? '' );
		$this->assertContains( array( 'info', 'Error when adding payment method.', 'woopayments' ), $logger->lines );
	}

	/**
	 * @testdox A platform error while adding a payment method is logged with its status and code, never its message.
	 */
	public function test_add_payment_method_log_leaves_out_platform_text(): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';
		$api_client                  = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client->method( 'get_setup_intention' )->willThrowException( self::make_provider_error() );
		$this->enable_debug_logging();
		$logger  = RecordingWcLogger::install();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, null, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$gateway->add_payment_method();

		$context = $this->get_logged_context( $logger, 'Error when adding payment method.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * API errors and the client's filtered message for each (utils.php:773-774, :795-796).
	 *
	 * @return array<string,array{0:WooPaymentsApiException,1:string}>
	 */
	public function add_payment_method_api_error_provider(): array {
		return array(
			'typed non-card error'    => array(
				new WooPaymentsApiException( 'No such setupintent', 'resource_missing', 404, 'invalid_request_error' ),
				"We're not able to process this request. Please refresh the page and try again.",
			),
			'transport failure'       => array(
				new WooPaymentsApiException( 'Http request failed. Reason: timeout', 'wcpay_http_request_failed', 500 ),
				'There was an error while processing this request. If you continue to see this notice, please contact the admin.',
			),
			'typeless platform error' => array(
				new WooPaymentsApiException( 'The platform could not read this setup intent.', 'wcpay_setup_intent_unreadable', 400 ),
				'The platform could not read this setup intent.',
			),
		);
	}

	/**
	 * Steps of add_payment_method() that fail with the client's "not able" copy.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unsaveable_setup_intent_provider(): array {
		return array(
			'succeeded intent without a payment method' => array( 'no_payment_method' ),
			'token cannot be created'                   => array( 'no_token' ),
			'setup intent lookup throws'                => array( 'exception' ),
		);
	}

	/**
	 * @testdox Should reject add-payment-method requests with invalid fraud-prevention tokens before reading the setup intent.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:4578-4586` refuses without touching
	 * the session token; the only rotation is `wc-payment-api/class-wc-payments-api-client.php:2956`,
	 * after a `fraudulent` or `wcpay_card_testing_prevention` API decline.
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
		$this->assertSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ), 'A refusal must not rotate the session token; the client rotates it only on a fraudulent API decline.' );
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
		$gateway_title_filter  = static function ( string $title ): string {
			return 'Filtered: ' . $title;
		};
		$method_title_filter   = static function ( string $title ): string {
			return 'Filtered: ' . $title;
		};

		unset( $wp_actions['init'] );
		add_filter( 'gettext', $filter, 10, 3 );
		add_filter( 'woocommerce_gateway_title', $gateway_title_filter );
		add_filter( 'woocommerce_gateway_method_title', $method_title_filter );

		try {
			$gateway = new NativeWooPaymentsGateway();

			$this->assertSame( 'Card', $gateway->title );
			$this->assertSame( 'WooPayments', $gateway->method_title );
			$this->assertSame( 'Accept payments with WooPayments.', $gateway->method_description );

			// When handle_init() runs on the 'init' hook, did_action( 'init' ) is already 1.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate running on the init hook.
			$wp_actions['init'] = 1;

			$gateway->handle_init();

			$this->assertSame( 'Filtered: Translated: Card', $gateway->get_title() );
			$this->assertSame( 'Filtered: Translated: WooPayments', $gateway->get_method_title() );
			$this->assertSame( 'Translated: Accept payments with WooPayments.', $gateway->method_description );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
			remove_filter( 'woocommerce_gateway_title', $gateway_title_filter );
			remove_filter( 'woocommerce_gateway_method_title', $method_title_filter );

			if ( $had_init_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate pre-init construction for the WP 6.7 textdomain guard.
				$wp_actions['init'] = $previous_init_count;
			} else {
				unset( $wp_actions['init'] );
			}
		}
	}

	/**
	 * @testdox Should record a failed order when the client reports a payment method creation error.
	 */
	public function test_process_payment_fails_order_for_client_payment_method_error(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
		$_POST['wcpay-payment-method-error-message'] = 'Your card number is invalid.';
		$_POST['wcpay-payment-method-error-code']    = 'incomplete_number';

		$this->enable_debug_logging();
		$logger = $this->capture_logs();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertNull( $service->last_checkout_context );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status() );

		// Client 11.1.0 throws the error (gw:1664-1666); its catch writes this note (gw:1354-1401) and logs (gw:1274).
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertContains(
			'A payment of ' . wc_price( 12.00, array( 'currency' => $order->get_currency() ) ) . ' <strong>failed</strong> to complete with the following message: <code>Your card number is invalid</code>.',
			$notes
		);
		$this->assertCount( 1, array_filter( $notes, static fn( string $note ): bool => false !== strpos( $note, 'Your card number is invalid' ) ), 'The status change must not carry the raw message as a second note.' );
		$this->assertSame( array( 'Your card number is invalid.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$error_messages = array_column( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ), 'message' );
		$this->assertContains( 'Error occurred during the payment process.', $error_messages );
		// The message is Stripe.js text the browser posted; only the refusal's code is logged.
		$this->assertStringNotContainsString( 'Your card number is invalid', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox Should keep an order whose intent already succeeded when the client reports a payment method creation error.
	 *
	 * Oracle: WooPayments 11.1.0 throws the client error inside process_payment()'s try (`gw:1664-1666`), so the catch's
	 * succeeded-intent check (`gw:1283-1304`) runs before the failed status (`gw:1326-1327`) and the failure note.
	 * The order is still pending here: the earlier duplicate checks found no paid status and no attached PaymentIntent.
	 */
	public function test_process_payment_client_payment_method_error_on_succeeded_intent_keeps_order(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );

		$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
		$_POST['wcpay-payment-method-error-message'] = 'Your card number is invalid.';

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertNull( $service->last_checkout_context, 'A refused checkout must not reach the provider.' );
		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Your card number is invalid.', 'payment_method_error', $logger, 'pending' );
	}

	/**
	 * @testdox Should not flip the order status for a client payment method error on a subscription payment-method change.
	 */
	public function test_process_payment_client_error_keeps_status_on_subscription_change(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$this->ensure_wcs_subscription_detector_double();
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );

		$previous_status = $order->get_status();

		$_POST['_wcsnonce']                          = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']         = (string) $order->get_id();
		$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
		$_POST['wcpay-payment-method-error-message'] = 'Your card number is invalid.';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( $previous_status, wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should fall back to a generic message when the client error carries none.
	 */
	public function test_process_payment_client_error_uses_generic_message_fallback(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'woocommerce_payments_payment_method_error';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should process payments through the native processing service.
	 */
	public function test_process_payment_delegates_to_processing_service(): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_mock' );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['is_platform_payment_method'] );
		$this->assertSame( 'pi_mock', WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox A plugin-origin saved card reaches native checkout unchanged and accepts the recorded successful outcome.
	 */
	public function test_process_payment_reuses_plugin_origin_saved_card(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_plugin_history' );
		$token = $this->create_card_token( $user_id, 'pm_plugin_history' );
		$token->set_expiry_month( '02' );
		$token->set_expiry_year( '2045' );
		$token->save();
		/** @var \WC_Payment_Token_Data_Store $token_data_store */
		$token_data_store = \WC_Data_Store::load( 'payment-token' );
		$token_data_store->set_default_status( $token->get_id(), true );

		$order = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->set_total( '10.99' );
		$order->save();

		// Oracle: WooPayments 11.1.0 writes this saved-card/customer shape, and the read-only :8082 capture recorded its native reuse as a succeeded, captured USD 10.99 payment.
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_plugin_history_use', '', 'pm_plugin_history', 'cus_plugin_history' );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( (string) $token->get_id(), $service->last_checkout_context->get_payment_data()['payment_token'] );
		$this->assertSame( '10.99', $service->last_checkout_context->get_order()->get_total() );
		$this->assertSame( 'USD', $service->last_checkout_context->get_order()->get_currency() );
		$this->assertSame( 'pi_plugin_history_use', WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox A customer-present early renewal reuses the exact plugin-origin subscription-shaped order and saved card under the recorded successful outcome.
	 */
	public function test_process_payment_reuses_plugin_origin_subscription_card_for_early_renewal(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_plugin_subscription' );

		$token = $this->create_card_token( $user_id, 'pm_plugin_subscription' );
		$token->set_expiry_month( '02' );
		$token->set_expiry_year( '2045' );
		$token->save();

		$parent_order = $this->create_order();
		$parent_order->set_customer_id( $user_id );
		$parent_order->set_currency( 'USD' );
		$parent_order->set_total( '11.98' );
		$parent_order->set_payment_method( 'woocommerce_payments' );
		$parent_order->add_payment_token( $token );
		$parent_order->update_meta_data( '_payment_method_id', 'pm_plugin_subscription' );
		$parent_order->update_meta_data( '_stripe_customer_id', 'cus_plugin_subscription' );
		$parent_order->save();

		$subscription = wc_create_order( array( 'customer_id' => $user_id ) );
		if ( ! $subscription instanceof WC_Order ) {
			throw new \RuntimeException( 'Could not create a plugin-shaped historical subscription.' );
		}
		$subscription->set_parent_id( $parent_order->get_id() );
		$subscription->set_currency( 'USD' );
		$subscription->set_total( '9.99' );
		$subscription_status_filter = static function ( array $statuses ): array {
			$statuses['wc-active'] = 'Active';
			return $statuses;
		};
		add_filter( 'wc_order_statuses', $subscription_status_filter );
		try {
			$subscription->set_status( 'active' );
			$subscription->set_payment_method( 'woocommerce_payments' );
			$subscription->add_payment_token( $token );
			$subscription->update_meta_data( '_payment_method_id', 'pm_plugin_subscription' );
			$subscription->update_meta_data( '_stripe_customer_id', 'cus_plugin_subscription' );
			$subscription->update_meta_data( '_schedule_start', '2026-09-22 15:00:00' );
			$subscription->update_meta_data( '_schedule_next_payment', '2026-10-22 15:00:00' );
			$subscription->save();
		} finally {
			remove_filter( 'wc_order_statuses', $subscription_status_filter );
		}

		$renewal_order = $this->create_order();
		$renewal_order->set_customer_id( $user_id );
		$renewal_order->set_currency( 'USD' );
		$renewal_order->set_total( '9.99' );
		$renewal_order->set_payment_method( 'woocommerce_payments' );
		$renewal_order->add_payment_token( $token );
		$renewal_order->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		$renewal_order->save();

		// Oracle: WooPayments 11.1.0 OrderService::get_payment_metadata() preserves this renewal relationship, and the read-only :8082 provider family recorded a succeeded, captured USD 9.99 renewal on the same historical customer and Visa 4242 method.
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_plugin_subscription_renewal', '', 'pm_plugin_subscription', 'cus_plugin_subscription' );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();
		$GLOBALS['wcpay_test_renewal_subscription_ids']                    = array( $renewal_order->get_id() => array( $subscription->get_id() ) );

		try {
			$result = $gateway->process_payment( $renewal_order->get_id() );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}
		$subscription_fresh = wc_get_order( $subscription->get_id() );
		$renewal_fresh      = wc_get_order( $renewal_order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertInstanceOf( WC_Order::class, $subscription_fresh );
		$this->assertInstanceOf( WC_Order::class, $renewal_fresh );
		$this->assertSame( $renewal_order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( (string) $token->get_id(), $service->last_checkout_context->get_payment_data()['payment_token'] );
		$this->assertSame( '9.99', $service->last_checkout_context->get_order()->get_total() );
		$this->assertSame( 'USD', $service->last_checkout_context->get_order()->get_currency() );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['scheduled_subscription_payment'] ?? false, 'A customer-present early renewal must not become an off-session scheduled renewal.' );
		$this->assertSame( 'active', $subscription_fresh->get_status() );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $subscription_fresh->get_payment_tokens() ) );
		$this->assertSame( $parent_order->get_id(), $subscription_fresh->get_parent_id() );
		$this->assertSame( $subscription->get_id(), absint( $renewal_fresh->get_meta( '_subscription_renewal', true ) ) );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $renewal_fresh->get_payment_tokens() ) );
		$this->assertSame( 'pi_plugin_subscription_renewal', WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox Should store no paid-intent witness for non-completed or ID-less payment outcomes.
	 *
	 * @dataProvider outcomes_without_paid_intent_witness
	 *
	 * @param string $status Provider outcome status.
	 * @param string $provider_payment_id Provider payment ID.
	 */
	public function test_process_payment_does_not_store_paid_intent_witness_for_non_completed_or_idless_outcomes( string $status, string $provider_payment_id ): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( $status, $provider_payment_id );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		WC()->session->set( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY, null );

		$gateway->process_payment( $order->get_id() );

		$this->assertNull( WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * Provide outcomes that do not prove a completed paid intent.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function outcomes_without_paid_intent_witness(): array {
		return array(
			'failed'             => array( PaymentOutcome::STATUS_FAILED, 'pi_failed' ),
			'pending'            => array( PaymentOutcome::STATUS_PENDING_ASYNC, 'pi_pending' ),
			'redirect'           => array( PaymentOutcome::STATUS_REQUIRES_REDIRECT, 'pi_redirect' ),
			'authorized'         => array( PaymentOutcome::STATUS_AUTHORIZED, 'pi_authorized' ),
			'empty completed ID' => array( PaymentOutcome::STATUS_COMPLETED, '' ),
		);
	}

	/**
	 * @testdox Should complete a payment without a paid-intent witness when the WC session is unavailable.
	 */
	public function test_process_payment_skips_paid_intent_witness_without_wc_session(): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$gateway                   = new NativeWooPaymentsGateway();
		$original_session          = WC()->session;
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_mock' );
		$gateway->init( $service, new WooPaymentsProvider() );
		WC()->session = null;

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( 'success', $result['result'] );
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
	 * @testdox Should reject checkout requests with invalid or absent fraud-prevention tokens before creating a payment context, on the card gateway and every split redirect gateway.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1219-1227` reads one shared
	 * `Fraud_Prevention_Service::get_instance()` regardless of which gateway ID is processing.
	 * `:1221` reads `null` when the POST field is absent (`isset($_POST[...]) ? ... : null`), and
	 * `fraud-prevention/class-fraud-prevention-service.php:172-174` refuses a non-string token
	 * outright; `:168-177` (`verify_token`) otherwise refuses a token that does not hash equal to the
	 * session's. The thrown exception reaches the catch at `:1326-1327`, which marks the order
	 * `failed` (no payment information exists yet), and the token is not rotated (only
	 * `wc-payment-api/class-wc-payments-api-client.php:2956` rotates it, after a fraudulent decline).
	 *
	 * @dataProvider fraud_prevention_token_gateway_provider
	 *
	 * @param string|null $payment_method_id Split payment method ID, or null for the card gateway.
	 * @param bool        $token_posted      Whether a (tampered) token is posted at all, or the field is absent.
	 */
	public function test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled( ?string $payment_method_id, bool $token_posted ): void {
		wc_clear_notices();
		$order      = $this->create_order();
		$service    = new RecordingPaymentProcessingService();
		$session    = $this->create_session();
		$definition = null === $payment_method_id ? null : ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
		if ( null !== $payment_method_id ) {
			$this->assertNotNull( $definition );
		}
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
		if ( $token_posted ) {
			$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';
		} else {
			unset( $_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] );
		}

		$gateway = new NativeWooPaymentsGateway( $definition );
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
		$this->assertSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame(
			array(),
			preg_grep( '#<strong>failed</strong>#', array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) ),
			'No payment note: the failed-payment note needs payment information (`:1354`) and the rate-limiter note is for `rate_limiter_enabled` only (`:1403`).'
		);
		$this->assertSame(
			"We're not able to process this payment. Please refresh the page and try again.",
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * @testdox Should refuse a billing phone over 20 characters before any other check, as the client does.
	 *
	 * Client 11.1.0 throws Invalid_Phone_Number_Exception first thing in process_payment()'s try
	 * (`class-wc-payment-gateway-wcpay.php:1204-1209`), ahead of the fraud-prevention token check; the
	 * catch marks the order failed without a payment note and shows the exception's message.
	 */
	public function test_process_payment_refuses_a_billing_phone_over_20_characters_first(): void {
		wc_clear_notices();
		$order = $this->create_order();
		$order->set_billing_phone( str_repeat( '1', 21 ) );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, null, null, $this->create_fraud_prevention_service( true, $session ) );

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
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array( 'Invalid phone number.' ), array_column( wc_get_notices( 'error' ), 'notice' ), 'The phone guard runs before the fraud-prevention check, so only its notice shows.' );
	}

	/**
	 * @testdox Should accept a billing phone of exactly 20 characters, as the client does.
	 */
	public function test_process_payment_accepts_a_billing_phone_of_20_characters(): void {
		wc_clear_notices();
		$order = $this->create_order();
		$order->set_billing_phone( str_repeat( '1', 20 ) );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( array(), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * Gateways, crossed with a tampered vs. absent token, the refusal must cover.
	 *
	 * @return array<string,array{0:string|null,1:bool}>
	 */
	public function fraud_prevention_token_gateway_provider(): array {
		return array(
			'card, tampered token'              => array( null, true ),
			'card, absent token'                => array( null, false ),
			'Affirm, tampered token'            => array( 'affirm', true ),
			'Affirm, absent token'              => array( 'affirm', false ),
			'Cash App Afterpay, tampered token' => array( 'afterpay_clearpay', true ),
			'Cash App Afterpay, absent token'   => array( 'afterpay_clearpay', false ),
			'Bancontact, tampered token'        => array( 'bancontact', true ),
			'Bancontact, absent token'          => array( 'bancontact', false ),
		);
	}

	/**
	 * @testdox Should admit a checkout request whose fraud-prevention token matches the session.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1219-1227` and
	 * `fraud-prevention/class-fraud-prevention-service.php:168-177`: a token that hashes equal to the
	 * session's is admitted without regenerating it.
	 */
	public function test_process_payment_admits_matching_fraud_prevention_token_when_enabled(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, '0123456789abcdef' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = '0123456789abcdef';

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

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertSame( '0123456789abcdef', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * @testdox Should admit a matching fraud-prevention token on split redirect gateways.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1219-1227`: `process_payment`
	 * reads one shared `Fraud_Prevention_Service::get_instance()`, not a per-gateway one, so a matching
	 * token admits the checkout identically whichever split gateway ID is processing.
	 *
	 * @dataProvider split_redirect_gateway_provider
	 *
	 * @param string $payment_method_id Split payment method ID.
	 */
	public function test_process_payment_admits_valid_fraud_token_on_split_redirect_gateways( string $payment_method_id ): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
		$this->assertNotNull( $definition );

		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, '0123456789abcdef' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = '0123456789abcdef';

		$gateway = new NativeWooPaymentsGateway( $definition );
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

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID_PREFIX . $payment_method_id, $service->last_checkout_context->get_gateway_id() );
	}

	/**
	 * Split redirect gateways the matching fraud-prevention token must be admitted on.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function split_redirect_gateway_provider(): array {
		return array(
			'Affirm'            => array( 'affirm' ),
			'Cash App Afterpay' => array( 'afterpay_clearpay' ),
			'Bancontact'        => array( 'bancontact' ),
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
	 * @testdox Should retry a plugin-era failed intent on order pay.
	 *
	 * Oracle: WooPayments 11.1.0 commit f85392666c9b543cd24dbbf903e0dbe4cb2c5cee,
	 * WC_Payment_Gateway_WCPay::process_payment(), and
	 * Duplicate_Payment_Prevention_Service::check_payment_intent_attached_to_order_succeeded().
	 *
	 * @dataProvider plugin_failed_intent_order_pay_cases
	 *
	 * @param bool   $card_testing_protection_enabled Whether card-testing protection is enabled.
	 * @param string $fraud_prevention_token          Posted fraud-prevention token.
	 */
	public function test_process_payment_retries_plugin_failed_intent_on_order_pay( bool $card_testing_protection_enabled, string $fraud_prevention_token ): void {
		$order = $this->create_order();
		$order->set_status( 'failed' );
		$order->update_meta_data( '_intent_id', 'pi_plugin_failed' );
		$order->save();

		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, '0123456789abcdef' );
		$_POST['woocommerce_pay']                               = '1';
		$_POST['wcpay-payment-method']                          = 'pm_plugin_failed_recovery';
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = $fraud_prevention_token;

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_plugin_failed' )
			->willReturn(
				array(
					'id'                => 'pi_plugin_failed',
					'status'            => 'requires_payment_method',
					'amount'            => 1200,
					'amount_capturable' => 0,
					'amount_received'   => 0,
					'currency'          => strtolower( $order->get_currency() ),
					'metadata'          => array( 'order_id' => (string) $order->get_id() ),
					'charges'           => array(
						'data' => array(
							array(
								'id'              => 'ch_plugin_failed',
								'amount'          => 1200,
								'amount_captured' => 0,
								'captured'        => false,
								'paid'            => false,
							),
						),
					),
				)
			);

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( $card_testing_protection_enabled, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session ),
			$this->create_duplicate_payment_prevention_service( $session, $api_client )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 1, $service->checkout_attempt_count );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertSame( '0123456789abcdef', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * Plugin-era failed intent recovery cases for order pay.
	 *
	 * @return array<string,array{bool,string}>
	 */
	public static function plugin_failed_intent_order_pay_cases(): array {
		return array(
			'card-testing protection disabled' => array( false, 'tampered-token' ),
			'card-testing protection enabled'  => array( true, '0123456789abcdef' ),
		);
	}

	/**
	 * @testdox Should reject checkout before creating a payment context when the failed-transaction limiter is active.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1229-1234` throws
	 * `rate_limiter_enabled`; the catch marks the order `failed` (`:1326-1327`) and adds the rate-limiter
	 * note (`:1403-1421`) with the order total through the explicit-price formatter, which leaves a
	 * single-currency price unsuffixed.
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
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertContains(
			'A payment of ' . wc_price( 12.00, array( 'currency' => $order->get_currency() ) ) . ' <strong>failed</strong> to complete because of too many failed transactions. A rate limiter was enabled for the user to prevent more attempts temporarily.',
			array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) )
		);
	}

	/**
	 * @testdox Should suffix the rate-limiter note amount with the order currency in a multi-currency store.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1418` passes the total through
	 * `WC_Payments_Explicit_Price_Formatter::get_explicit_price()`, which appends ` <currency code>`
	 * when customer multi-currency is on and more than one currency is enabled
	 * (`class-wc-payments-explicit-price-formatter.php:106-142`, `:167-190`). Native reads core Multi-Currency.
	 */
	public function test_process_payment_rate_limiter_note_uses_explicit_price_in_multi_currency_store(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->set_core_multi_currency( true );
		$order   = $this->create_order();
		$session = $this->create_session();
		$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			new RecordingPaymentProcessingService(),
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$gateway->process_payment( $order->get_id() );

		$this->assertSame( 'USD', $order->get_currency() );
		$this->assertContains(
			'A payment of ' . wc_price( 12.00, array( 'currency' => 'USD' ) ) . ' USD <strong>failed</strong> to complete because of too many failed transactions. A rate limiter was enabled for the user to prevent more attempts temporarily.',
			array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) )
		);
	}

	/**
	 * @testdox Should omit the explicit currency from the rate-limiter note while Multi-Currency is off.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payments-explicit-price-formatter.php:167-172` returns the bare price
	 * when Multi-Currency is off, even with enabled currencies left in the option. Native reads core Multi-Currency.
	 */
	public function test_process_payment_rate_limiter_note_omits_explicit_price_when_multi_currency_is_off(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->set_core_multi_currency( false );
		$order   = $this->create_order();
		$session = $this->create_session();
		$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			new RecordingPaymentProcessingService(),
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( false, $session ),
			new WooPaymentsFailedTransactionRateLimiter( $session )
		);

		$gateway->process_payment( $order->get_id() );

		$this->assertSame( 'USD', $order->get_currency() );
		$this->assertContains(
			'A payment of ' . wc_price( 12.00, array( 'currency' => 'USD' ) ) . ' <strong>failed</strong> to complete because of too many failed transactions. A rate limiter was enabled for the user to prevent more attempts temporarily.',
			array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) )
		);
	}

	/**
	 * @testdox Should keep a paid order and return success when checkout is refused after the intent succeeded: $_dataName.
	 *
	 * Oracle: WooPayments 11.1.0 throws these refusals inside process_payment()'s try
	 * (`class-wc-payment-gateway-wcpay.php:1206-1233`, `class-duplicate-payment-prevention-service.php:131-139`).
	 * The catch checks the intention status first (`gw:1283`); when it is `succeeded` it adds the
	 * downstream-error note, logs a warning and returns success with the return URL (`gw:1284-1304`),
	 * before the failed status (`gw:1326-1327`), the rate-limiter note (`gw:1403-1421`) and the notice (`gw:1425`).
	 *
	 * @dataProvider refused_checkout_with_succeeded_intent_provider
	 *
	 * @param string $refusal Which refusal runs: `phone`, `fraud_token`, `rate_limiter` or `amount_mismatch`.
	 */
	public function test_process_payment_refusal_on_succeeded_intent_keeps_order_and_returns_success( string $refusal ): void {
		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();

		$session                  = $this->create_session();
		$fraud_prevention_service = $this->create_fraud_prevention_service( false, $session );
		$api_client               = null;
		if ( 'phone' === $refusal ) {
			$order->set_billing_phone( '+1 555 0100 0000 0000 0' );
			$order->save();
			$message = 'Invalid phone number.';
			$label   = 'invalid_phone_number';
		} elseif ( 'fraud_token' === $refusal ) {
			$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
			$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';
			$fraud_prevention_service                               = $this->create_fraud_prevention_service( true, $session );
			$message = "We're not able to process this payment. Please refresh the page and try again.";
			$label   = 'fraud_prevention_enabled';
		} elseif ( 'rate_limiter' === $refusal ) {
			$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );
			$message = 'Your payment was not processed.';
			$label   = 'rate_limiter_enabled';
		} else {
			$order->update_meta_data( '_intent_id', 'pi_existing' );
			$order->save();
			$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_payment_intention' ) )
				->getMock();
			$api_client->method( 'get_payment_intention' )->willReturn( $this->create_intent_response( $order, 'succeeded', 1000 ) );
			$message = sprintf(
				'This order was already paid for %1$s, but the order total has since changed to %2$s, so we prevented an overpayment. Please create a new order for any additional items.',
				wc_price( 10.00, array( 'currency' => $order->get_currency() ) ),
				wc_price( 12.00, array( 'currency' => $order->get_currency() ) )
			);
			$label   = 'duplicate_payment_amount_mismatch';
		}
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$fraud_prevention_service,
			new WooPaymentsFailedTransactionRateLimiter( $session ),
			$this->create_duplicate_payment_prevention_service( $session, $api_client )
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertNull( $service->last_checkout_context, 'A refused checkout must not reach the provider.' );
		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, $message, $label, $logger );
	}

	/**
	 * Refusals process_payment() can return before charging.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function refused_checkout_with_succeeded_intent_provider(): array {
		return array(
			'billing phone over 20 characters' => array( 'phone' ),
			'fraud-token refusal'              => array( 'fraud_token' ),
			'rate-limiter refusal'             => array( 'rate_limiter' ),
			'attached intent amount mismatch'  => array( 'amount_mismatch' ),
		);
	}

	/**
	 * @testdox Should keep a paid checkout order and return success when post-payment processing throws.
	 *
	 * Oracle: WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4531`
	 * (WOOPMNT-6145): when process_payment_for_order() throws after the intent succeeded, the catch
	 * (`class-wc-payment-gateway-wcpay.php:1283-1304`) keeps the order status, adds the downstream-error
	 * note, logs a warning and returns success with get_return_url().
	 */
	public function test_process_payment_downstream_exception_on_succeeded_intent_keeps_order_and_returns_success(): void {
		// Pending like the client test's order: a paid status would stop at the already-paid guard first.
		$order = $this->create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \Exception( 'Auth credentials missing' );
		$gateway                     = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context, 'The payment must have run before the downstream failure.' );
		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Auth credentials missing', 'Exception', $logger, 'pending' );
	}

	/**
	 * @testdox Should keep a subscription and return success when its payment-method change throws after the intent succeeded.
	 *
	 * Oracle: WooPayments 11.1.0 runs a subscription payment-method change through the same
	 * process_payment() try (`class-wc-payment-gateway-wcpay.php:1201-1282`, flagged at `:1637`), so the
	 * succeeded-intent catch (`:1283-1304`) covers it too; only the failed-status branch below it
	 * excludes the change (`:1326`).
	 */
	public function test_process_payment_downstream_exception_on_succeeded_intent_keeps_subscription_payment_method_change(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$subscription = $this->create_order();
		$subscription->update_meta_data( '_intention_status', 'succeeded' );
		$subscription->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $subscription );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $subscription->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $subscription->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \RuntimeException( 'Subscription hook failed' );
		$gateway                     = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $subscription->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'The request must run as a validated subscription payment-method change.' );
		$this->assert_succeeded_intent_defense( $subscription->get_id(), $result, $return_url, $note_count, 'Subscription hook failed', 'RuntimeException', $logger, 'pending' );
	}

	/**
	 * @testdox Should keep a paid order and return success when a step after the payment throws.
	 *
	 * Oracle: WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4531` (WOOPMNT-6145):
	 * when the payment step throws after the intent succeeded, the catch (`class-wc-payment-gateway-wcpay.php:1283-1304`)
	 * keeps the order status, adds the downstream-error note, logs a warning and returns success with get_return_url().
	 * Native runs the real processing service here: the provider's post-lifecycle step throws after the order is paid.
	 */
	public function test_process_payment_post_lifecycle_failure_after_payment_keeps_order_and_returns_success(): void {
		$order      = $this->create_order();
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );
		$provider   = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_downstream', '', 'pm_card_visa' ),
			'post_lifecycle_effects',
			new \RuntimeException( 'Auth credentials missing' )
		);
		$gateway    = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( $return_url, $result['redirect'] ?? '' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status(), 'The paid order must keep its status.' );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertContains( 'Payment succeeded, but a downstream error occurred during post-payment processing: Auth credentials missing. Order status preserved.', $notes );
		$this->assertSame(
			array( sprintf( 'Payment intent already succeeded; downstream RuntimeException on order #%d suppressed to preserve order status.', $order->get_id() ) ),
			$logger->warning_messages()
		);
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not see an error notice.' );
	}

	/**
	 * @testdox A $throwable_class after the payment succeeded with debug logging $logging keeps the order and is logged with its trace: $written.
	 *
	 * Client 11.1.0 writes `Logger::exception( 'Error occurred during the payment process.', $e )` with the class, code and
	 * trace before its succeeded-intent check (`class-wc-payment-gateway-wcpay.php:1274`, `includes/class-logger.php:100-112`),
	 * behind the debug setting; a PHP error, which fatals there, is written whatever the setting (review 34 F4). The
	 * succeeded-intent warning and the success answer stay as they are.
	 *
	 * @testWith ["TypeError", "no", true]
	 *           ["RuntimeException", "yes", true]
	 *           ["RuntimeException", "no", false]
	 *
	 * @param string $throwable_class Class thrown after the payment.
	 * @param string $logging         Gateway `enable_logging` setting.
	 * @param bool   $written         Whether the error line is written.
	 */
	public function test_process_payment_succeeded_intent_failure_is_logged_with_trace( string $throwable_class, string $logging, bool $written ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $logging ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order    = $this->create_order();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_downstream', '', 'pm_card_visa' ),
			'post_lifecycle_effects',
			new $throwable_class( 'Downstream step failed' )
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && str_starts_with( $line[1], 'Payment intent already succeeded' ) ) );
		$this->assertCount( 1, $warnings, 'The succeeded-intent warning is written whatever the setting.' );
		$lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'Error occurred during the payment process.' === $line[1] ) );
		if ( ! $written ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'error', 'woopayments' ), array( $logger->lines[ $lines[0] ][0], $logger->lines[ $lines[0] ][2] ) );
		$this->assertSame( $throwable_class, $logger->contexts[ $lines[0] ]['exception'] ?? '' );
		$this->assertSame( 0, $logger->contexts[ $lines[0] ]['code'] ?? null );
		$this->assertNotSame( '', $logger->contexts[ $lines[0] ]['trace'] ?? '' );
	}

	/**
	 * @testdox Should keep a charged order and return success when applying the charge fails before the order records it.
	 *
	 * Oracle: WooPayments 11.1.0 attaches the succeeded intent to the order before any later step can throw
	 * (`class-wc-payments-order-service.php:1230-1249`), so its catch (`class-wc-payment-gateway-wcpay.php:1283-1304`)
	 * always sees the succeeded status. Native records it while applying the outcome; when that fails first, the
	 * handed-back outcome must still count as a succeeded intent.
	 */
	public function test_process_payment_effect_failure_before_order_records_charge_keeps_order_and_returns_success(): void {
		$order      = $this->create_order();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );
		$provider   = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_unrecorded', '', 'pm_card_visa' ),
			'operation_effects',
			new \RuntimeException( 'Provider effect write failed' )
		);
		$gateway    = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Provider effect write failed', 'RuntimeException', $logger, 'pending' );
		$this->assertSame( 'pi_unrecorded', wc_get_order( $order->get_id() )->get_transaction_id(), 'The charge must stay reconcilable.' );
	}

	/**
	 * @testdox Should keep a renewal's provider outcome when applying it fails.
	 *
	 * Scheduled renewals have no checkout to answer, so the handed-back failure must not escape into Action Scheduler.
	 */
	public function test_scheduled_subscription_payment_keeps_outcome_when_applying_it_fails(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_renewal_downstream', '', 'pm_renewal_card' ),
			'post_lifecycle_effects',
			new \RuntimeException( 'Renewal display details failed' )
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_renewal_downstream', $order->get_transaction_id() );
	}

	/**
	 * @testdox A PHP error applying a $outcome_status renewal outcome is rethrown: $rethrown; the renewal ends $expected_status.
	 *
	 * Client 11.1.0 catches only API_Exception around the renewal payment (`trait-wc-payment-gateway-wcpay-subscriptions.php:426`),
	 * so a PHP error escapes to Action Scheduler, which fails the action (monitor ruling 2026-10-04 on renewal apply errors).
	 * Native logs it whatever the logging setting and rethrows it; the charge stays reconcilable on the renewal. A renewal
	 * that needs customer action is the exception: it still runs the requires-action handling, which fails the renewal and
	 * fires the authentication hook, as the client does for that outcome (gw:1921), instead of the scheduled action failing
	 * (review 35 F7).
	 *
	 * @testWith ["completed", true, "pending"]
	 *           ["requires_customer_action", false, "failed"]
	 *
	 * @param string $outcome_status  Provider outcome status.
	 * @param bool   $rethrown        Whether the PHP error reaches Action Scheduler.
	 * @param string $expected_status Renewal status afterwards.
	 */
	public function test_scheduled_subscription_payment_rethrows_php_error_applying_outcome( string $outcome_status, bool $rethrown, string $expected_status ): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$error    = new \TypeError( 'Argument #1 must be of type array, null given' );
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( $outcome_status, 'pi_renewal_error', '', 'pm_renewal_card' ),
			'operation_effects',
			$error
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$logger              = RecordingWcLogger::install();
		$authentication_hook = 0;
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( &$authentication_hook ): void {
				++$authentication_hook;
			}
		);

		$thrown = null;
		try {
			$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$thrown = $throwable;
		}

		$this->assertSame( $rethrown ? $error : null, $thrown, $rethrown ? 'The PHP error must reach Action Scheduler.' : 'A requires-action renewal must not fail the scheduled action.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( 'pi_renewal_error', $order->get_transaction_id() );
		$this->assertSame( $rethrown ? 0 : 1, $authentication_hook );
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] && str_starts_with( $line[1], 'Error applying the WooPayments subscription renewal payment' ) ) );
		$this->assertCount( 1, $lines, 'The PHP error is logged whatever the logging setting.' );
		$this->assertSame( 'error', $lines[0][0] );
	}

	/**
	 * @testdox Should fail the order, add the failure note and return failure when post-payment processing throws before the intent succeeded.
	 *
	 * Oracle: WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4594`: without a succeeded intent
	 * the guard (`class-wc-payment-gateway-wcpay.php:1283`) does not fire; the catch fails the order (`:1326-1327`), adds
	 * the failed-payment note because the payment was attempted (`:1354-1401`), shows the message as an error notice
	 * (`:1425`) and returns a failure (`:1436-1439`).
	 */
	public function test_process_payment_exception_without_succeeded_intent_fails_order_with_note_and_notice(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->save();
		$this->enable_debug_logging();
		$logger = $this->capture_logs();

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \Exception( 'Genuine payment failure' );
		$gateway                     = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( '', $result['redirect'] ?? null );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertContains(
			sprintf(
				'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.',
				wc_price( 12.00, array( 'currency' => $order->get_currency() ) ),
				'Genuine payment failure'
			),
			$notes
		);
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( 'Payment succeeded, but a downstream error occurred', $note );
		}
		$this->assertSame( array( 'Genuine payment failure' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( array(), $logger->warning_messages() );
		$error_messages = array_column( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ), 'message' );
		$this->assertContains( 'Error occurred during the payment process.', $error_messages );
	}

	/**
	 * @testdox A platform error that ends checkout is logged with its status and code, never its message.
	 */
	public function test_process_payment_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$order    = $this->create_order();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, 'pi_platform_error', '', 'pm_card_visa' ),
			'post_lifecycle_effects',
			self::make_provider_error()
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$gateway->process_payment( $order->get_id() );

		$context = $this->get_logged_context( $logger, 'Error occurred during the payment process.' );
		$this->assertSame( array( 404, 'resource_missing', $order->get_id() ), array( $context['http_status'], $context['error_code'], $context['order_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Should fail the order without the failure note when checkout throws before the payment is attempted.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1326-1327` fails the order when the payment
	 * information was never prepared, and adds the failed-payment note only when it was (`:1354`); the notice
	 * (`:1425`) and the failure return (`:1436-1439`) are the same.
	 */
	public function test_process_payment_exception_before_payment_fails_order_without_failure_note(): void {
		$order      = $this->create_order();
		$session    = $this->create_session();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$duplicate_payment_prevention_service = $this->getMockBuilder( WooPaymentsDuplicatePaymentPreventionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'check_against_session_processing_order' ) )
			->getMock();
		$duplicate_payment_prevention_service
			->method( 'check_against_session_processing_order' )
			->willThrowException( new \RuntimeException( 'Session storage is unavailable.' ) );

		$service = new RecordingPaymentProcessingService();
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
			$duplicate_payment_prevention_service
		);

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertNull( $service->last_checkout_context, 'The payment must not have been attempted.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertCount( $note_count + 1, $notes, 'Only the status-change note may be added.' );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( 'to complete with the following message', $note );
		}
		$this->assertSame( array( 'Session storage is unavailable.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * @testdox Should keep a subscription's status but add the failure note when its payment-method change throws before the intent succeeded.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1326` does not fail a subscription whose
	 * payment method is being changed; the failed-payment note (`:1354-1401`), the notice (`:1425`) and the
	 * failure return (`:1436-1439`) still apply.
	 */
	public function test_process_payment_exception_on_subscription_payment_method_change_keeps_status(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$subscription = $this->create_order();

		$GLOBALS['wcpay_test_subscription_ids'] = array( $subscription->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $subscription->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \RuntimeException( 'Subscription hook failed' );
		$gateway                     = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $subscription->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'The request must run as a validated subscription payment-method change.' );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 'pending', $subscription->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );
		$this->assertContains(
			sprintf(
				'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.',
				wc_price( 12.00, array( 'currency' => $subscription->get_currency() ) ),
				'Subscription hook failed'
			),
			$notes
		);
		$this->assertSame( array( 'Subscription hook failed' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * @testdox Should fail the order with the mismatch as its note when the attached intent's amount no longer matches.
	 *
	 * Oracle: WooPayments 11.1.0 throws the mismatch (`class-duplicate-payment-prevention-service.php:131-139`);
	 * without a succeeded intent on the order, the catch fails the order with the message as the status note
	 * (`class-wc-payment-gateway-wcpay.php:1324-1325`), shows it as a notice (`:1425`) and returns a failure.
	 */
	public function test_process_payment_amount_mismatch_without_succeeded_intent_fails_order(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();
		$session    = $this->create_session();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'get_payment_intention' )->willReturn( $this->create_intent_response( $order, 'succeeded', 1000 ) );
		$message = sprintf(
			'This order was already paid for %1$s, but the order total has since changed to %2$s, so we prevented an overpayment. Please create a new order for any additional items.',
			wc_price( 10.00, array( 'currency' => $order->get_currency() ) ),
			wc_price( 12.00, array( 'currency' => $order->get_currency() ) )
		);

		$service = new RecordingPaymentProcessingService();
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

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertNull( $service->last_checkout_context );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( string $note ): bool => str_starts_with( $note, $message ) ), 'The status note must carry the mismatch message.' );
		$this->assertSame( array( $message ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * @testdox An exception applying an authorized outcome still fails the order, as the plugin's catch does.
	 *
	 * Client 11.1.0 `gw:1272-1327` fails an order whose intent has not succeeded; only a PHP error leaves it (ruling 2026-10-04 (4)).
	 */
	public function test_process_payment_exception_applying_authorized_outcome_fails_order(): void {
		$order    = $this->create_order();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_authorized', '', 'pm_card_visa' ),
			'operation_effects',
			new \RuntimeException( 'Provider effect write failed' )
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array( 'Provider effect write failed' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * @testdox When the duplicate guard's intent lookup throws a $throwable_class, checkout charges: $charges.
	 *
	 * Client 11.1.0 `src/Internal/Service/DuplicatePaymentPreventionService.php:100` catches only exceptions: an exception
	 * lets checkout go on, a PHP Error fatals and charges nothing. Native refuses instead of the fatal and leaves the order
	 * pending (monitor ruling 2026-10-04 (1)).
	 *
	 * @testWith ["Error", false]
	 *           ["RuntimeException", true]
	 *
	 * @param string $throwable_class Class thrown by the intent lookup.
	 * @param bool   $charges         Whether checkout goes on to charge.
	 */
	public function test_process_payment_refuses_when_the_duplicate_guard_lookup_raises_a_php_error( string $throwable_class, bool $charges ): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();
		$session    = $this->create_session();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'get_payment_intention' )->willThrowException( new $throwable_class( 'Undefined index: status' ) );
		$logger  = RecordingWcLogger::install();
		$service = new RecordingPaymentProcessingService();
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
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( $charges ? 1 : 0, $service->checkout_attempt_count );
		$guard_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => str_starts_with( $line[1], 'Failed to fetch attached' ) ) );
		if ( $charges ) {
			$this->assertSame( array(), $guard_lines, 'An exception follows the logging setting, which is off.' );
			return;
		}
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array( "We're not able to process this payment. Please try again later." ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertCount( 1, $guard_lines, 'A PHP Error is logged whatever the logging setting.' );
		$this->assertSame( 'Error', $logger->contexts[ $guard_lines[0] ]['exception'] ?? '' );
	}

	/**
	 * @testdox A PHP error applying a $outcome_status outcome at $stage leaves the order $expected_status, with the generic notice.
	 *
	 * The plugin's catch takes only exceptions (`class-wc-payment-gateway-wcpay.php:1272`), so a PHP error fatals and
	 * leaves the order as it was. Native keeps an order whose payment is authorized for reconciliation and logs the
	 * error whatever the logging setting (monitor ruling 2026-10-04 (4)); any other unsucceeded outcome fails the order
	 * as the plugin does for an exception. The error's text stays out of the shopper notice either way. An order that already
	 * shows the authorization is covered by test_process_payment_php_error_after_order_shows_authorization().
	 *
	 * @testWith ["authorized", "operation_effects", "pending"]
	 *           ["requires_customer_action", "operation_effects", "failed"]
	 *
	 * @param string $outcome_status  Provider outcome status.
	 * @param string $stage           Where applying the outcome throws.
	 * @param string $expected_status Order status afterwards.
	 */
	public function test_process_payment_php_error_without_succeeded_intent_shows_generic_notice( string $outcome_status, string $stage, string $expected_status ): void {
		$order    = $this->create_order();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( $outcome_status, 'pi_authorized', '', 'pm_card_visa' ),
			$stage,
			new \TypeError( 'Argument #1 must be of type array, null given, called in /var/www/html/wp-content/plugins/example/example.php on line 12' )
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( array( WooPaymentsErrorMessages::get_generic_message() ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$type_error_lines = array_keys( array_filter( $logger->contexts, static fn( array $context ): bool => 'woopayments' === ( $context['source'] ?? '' ) && 'TypeError' === ( $context['exception'] ?? '' ) ) );
		$this->assertNotSame( array(), $type_error_lines, 'The PHP error is logged whatever the logging setting.' );
		$this->assertSame( 'TypeError', $logger->contexts[ $type_error_lines[0] ]['exception'] ?? '' );
		if ( 'failed' !== $expected_status ) {
			$this->assertStringContainsString( 'raised TypeError', $logger->lines[ $type_error_lines[0] ][1] );
			$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
			$this->assertSame( array(), array_filter( $notes, static fn( string $note ): bool => str_contains( $note, 'failed' ) ), 'No failure note is added to an order left for reconciliation.' );
		}
	}

	/**
	 * @testdox A PHP error after the order went on hold for $intent ends checkout with $result.
	 *
	 * The plugin's catch takes only exceptions (`class-wc-payment-gateway-wcpay.php:1272`), so a PHP error fatals with the
	 * order on hold; a retry then creates a new order and authorizes the card again. Native answers as it does for an
	 * authorized payment once a fresh read shows the order on hold for the outcome's intent, and keeps the refusal when the
	 * order is bound to another intent (monitor ruling 2026-10-04 on review 34 F2). The error is logged whatever the setting.
	 * A Multibanco voucher also puts the order on hold but its intent is `requires_action`, so it keeps the refusal: the
	 * shopper is returned to checkout with the generic error, while the voucher stays on the on-hold order and expires if
	 * unpaid (review 35 F7, review 37 F5). When the fresh read itself throws, the original error is still logged
	 * and checkout keeps the refusal instead of letting the read failure escape (review 35 F3).
	 *
	 * @testWith ["its intent", "success"]
	 *           ["another intent", "failure"]
	 *           ["its intent, as a Multibanco voucher", "failure"]
	 *           ["its intent, on an order that cannot be read again", "failure"]
	 *
	 * @param string $intent Which intent the order is bound to when the error strikes.
	 * @param string $result Expected checkout result.
	 */
	public function test_process_payment_php_error_after_order_shows_authorization( string $intent, string $result ): void {
		$order = $this->create_order();
		if ( 'another intent' === $intent ) {
			add_action(
				'woocommerce_order_status_on-hold',
				static function ( $order_id ): void {
					$concurrent_order = wc_get_order( $order_id );
					$concurrent_order->set_transaction_id( 'pi_other' );
					$concurrent_order->update_meta_data( '_intent_id', 'pi_other' );
					$concurrent_order->save();
				}
			);
		}
		$gateway_lifecycle = null;
		if ( 'its intent, on an order that cannot be read again' === $intent ) {
			// The gateway's fresh read after the error fails, as the data store does for a missing order.
			$gateway_lifecycle = new class() extends OrderPaymentLifecycleService {
				/**
				 * Fail the read as the data store does for a missing order.
				 *
				 * @param WC_Order $order Order object.
				 * @throws \Exception Always.
				 */
				public function get_fresh_order_from_data_store( WC_Order $order ): WC_Order {
					unset( $order );
					throw new \Exception( 'Invalid order.' );
				}
			};
		}
		$outcome  = 'its intent, as a Multibanco voucher' === $intent
			? new PaymentOutcome(
				PaymentOutcome::STATUS_AUTHORIZED,
				'pi_authorized',
				$order->get_checkout_order_received_url(),
				'pm_card_visa',
				'',
				array( PaymentOutcome::DATA_META => array( '_intention_status' => 'requires_action' ) )
			)
			: new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_authorized', '', 'pm_card_visa' );
		$provider = $this->create_provider_failing_after_charge(
			$outcome,
			'post_lifecycle_effects',
			new \TypeError( 'Argument #1 must be of type array, null given' )
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider, null, null, null, null, null, null, null, null, $gateway_lifecycle );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$checkout = $gateway->process_payment( $order->get_id() );

		$this->assertSame( $result, $checkout['result'] ?? '' );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$error_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] && str_contains( $line[1], 'raised TypeError' ) ) );
		$this->assertCount( 1, $error_lines, 'The PHP error is logged whatever the logging setting.' );
		if ( 'failure' === $result ) {
			$this->assertSame( array( WooPaymentsErrorMessages::get_generic_message() ), array_column( wc_get_notices( 'error' ), 'notice' ) );
			return;
		}
		$this->assertSame( $reloaded->get_checkout_order_received_url(), $checkout['redirect'] ?? '' );
		$this->assertSame( 'pm_card_visa', $checkout['payment_method'] ?? '' );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not be asked to pay again.' );
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
	 *
	 * Client `class-wc-payment-gateway-wcpay.php:4891-4893` (11.1.0) bumps only for
	 * `card_declined`, `incorrect_number` and `incorrect_cvc`; `expired_card` is not
	 * in that list, so an expired-card decline must not count toward the limiter.
	 *
	 * @dataProvider failed_transaction_unlimited_error_codes
	 *
	 * @param string $error_code Provider error code.
	 */
	public function test_process_payment_does_not_bump_failed_transaction_rate_limiter_for_other_errors( string $error_code ): void {
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
		$this->assertSame( array(), $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array() ) );
	}

	/**
	 * Provider error codes that must not bump the failed-transaction rate limiter.
	 *
	 * @return array<string,array{string}>
	 */
	public function failed_transaction_unlimited_error_codes(): array {
		return array(
			'processing error' => array( 'processing_error' ),
			'expired card'     => array( 'expired_card' ),
		);
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
	 * @testdox A checkout that ends with $label $outcome the order from duplicate-payment session tracking.
	 *
	 * Client 11.1.0 process_payment_for_order() removes the session's processing order when the intent succeeded or is
	 * `requires_action` for an offline method, that is Multibanco (gw:2021, 2147-2148; `class-payment-information.php:521-523`;
	 * Payment_Method::OFFLINE_PAYMENT_METHODS). The method is the gateway's own (gw:1451, 2479-2482), not the intent's
	 * next action. An authorized card or a card waiting on a 3DS challenge stays tracked until its order-received page.
	 *
	 * @testWith ["a Multibanco voucher", "multibanco", "requires_action", "multibanco_display_details", "removes"]
	 *           ["a Multibanco intent waiting without voucher details", "multibanco", "requires_action", "", "removes"]
	 *           ["a failed Multibanco intent", "multibanco", "requires_payment_method", "", "keeps"]
	 *           ["an authorized card payment", "card", "requires_capture", "", "keeps"]
	 *           ["a 3DS card challenge", "card", "requires_action", "use_stripe_sdk", "keeps"]
	 *           ["a card intent whose next action shows voucher details", "card", "requires_action", "multibanco_display_details", "keeps"]
	 *
	 * @param string $label          Case label.
	 * @param string $payment_method Payment method of the gateway that takes the payment.
	 * @param string $status         PaymentIntent status.
	 * @param string $next_action    PaymentIntent next action type, if any.
	 * @param string $outcome        Whether the gateway removes or keeps the tracked order.
	 */
	public function test_process_payment_clears_session_processing_order_for_offline_voucher( string $label, string $payment_method, string $status, string $next_action, string $outcome ): void {
		unset( $label );
		$order   = $this->create_order();
		$session = $this->create_session();
		$service = new RecordingPaymentProcessingService();
		$intent  = array(
			'id'       => 'pi_session_marker',
			'status'   => $status,
			'amount'   => 1200,
			'currency' => 'eur',
			'metadata' => array( 'order_id' => $order->get_id() ),
		);
		if ( 'use_stripe_sdk' === $next_action ) {
			$intent['payment_method_types'] = array( 'card' );
			$intent['next_action']          = array(
				'type'           => 'use_stripe_sdk',
				'use_stripe_sdk' => array( 'type' => 'three_d_secure_redirect' ),
			);
		}
		if ( 'multibanco_display_details' === $next_action ) {
			$intent['next_action'] = array(
				'type'                       => 'multibanco_display_details',
				'multibanco_display_details' => array(
					'reference'          => '123 456 789',
					'entity'             => '12345',
					'hosted_voucher_url' => 'https://payments.stripe.com/multibanco/voucher/test',
					'expires_at'         => time() + DAY_IN_SECONDS,
				),
			);
		}
		// The outcome the real provider hands the gateway: the codec's mapping enriched with the order effects.
		$service->checkout_outcome = wc_get_container()->get( WooPaymentsOrderEffectApplier::class )->enrich_outcome_for_lifecycle(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ),
			WooPaymentsIntentCodec::outcome_from_intention( $intent, WooPaymentsIntentMappingContext::for_native( $order->get_id(), $order->get_checkout_order_received_url() ) ),
			WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )
		);

		$gateway = new NativeWooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method ) );
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
		$_POST['wcpay-payment-method'] = 'pm_session_marker';

		$gateway->process_payment( $order->get_id() );

		$this->assertSame( 1, $service->checkout_attempt_count );
		$this->assertSame( 'removes' === $outcome ? null : $order->get_id(), $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );
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
		$_GET['change_payment_method']       = (string) $order->get_id();

		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';
		// The outcome branded the subscription before WCS records the change.
		$order->set_payment_method_title( 'Visa credit card' );
		$order->save();

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
		$this->assertSame( 'Visa credit card', wc_get_order( $order->get_id() )->get_payment_method_title(), 'Client 11.1.0 brands the subscription after WCS records the change, so the stored title stays the card title, not "Card".' );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['recurring_payment'] ?? false );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'A validated subscription payment-method change must reach the provider context.' );
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
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * @dataProvider invalid_subscription_change_authority_requests
	 * @testdox Subscription-change provider data requires a valid nonce and the exact processed subscription.
	 *
	 * @param string $nonce Nonce request value.
	 * @param bool   $use_mismatched_subscription Whether the request names a different real subscription.
	 */
	public function test_process_payment_rejects_invalid_subscription_change_request_authority( string $nonce, bool $use_mismatched_subscription ): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$request_id                             = $use_mismatched_subscription ? $order->get_id() + 1 : $order->get_id();
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id(), $request_id );
		if ( '' !== $nonce ) {
			$_POST['_wcsnonce'] = 'valid' === $nonce ? wp_create_nonce( 'wcs_change_payment_method' ) : $nonce;
		}
		$_POST['woocommerce_change_payment']                               = (string) $request_id;
		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = 'new';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function invalid_subscription_change_authority_requests(): array {
		return array(
			'missing nonce'                => array( '', false ),
			'invalid nonce'                => array( 'invalid-nonce', false ),
			'mismatched real subscription' => array( 'valid', true ),
		);
	}

	/**
	 * @testdox Should transport validated saved-method subscription changes without changing new-method semantics.
	 */
	public function test_process_payment_transports_validated_saved_method_subscription_change(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = '123';
		// WooCommerce Subscriptions points the return URL at My Account during a change request and then sends the shopper to the subscription.
		$return_url_filter = static function ( string $return_url, WC_Order $filtered_order ) use ( $order ): string {
			return $order->get_id() === $filtered_order->get_id() ? 'https://example.test/my-account/' : $return_url;
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11, 2 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertSame( 'https://example.test/my-account/', $result['redirect'], 'Client 11.1.0 returns get_return_url() for a saved-method change too, so the shopper lands on the subscription, not on "Order received".' );
		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertFalse( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false, 'Saved-method handling must keep its existing new-method-only save policy.' );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'Validated saved-method changes must preserve provider billing.' );
	}

	/**
	 * @testdox A change to a saved token is not refused as already paid when the store counts the subscription's status as paid.
	 *
	 * Client 11.1.0 skips the already-paid check for any payment-method change, new method or saved token
	 * (`includes/class-duplicate-payment-prevention-service.php:226`, keyed on `change_payment_method` through
	 * `includes/compat/subscriptions/trait-wc-payments-subscriptions-utilities.php:38-43`). `wc_get_is_paid_statuses()` is
	 * filterable, so the check could otherwise refuse the change.
	 */
	public function test_process_payment_saved_token_subscription_change_skips_the_already_paid_check(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$this->ensure_wcs_subscription_detector_double();
		$order = $this->create_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$paid_statuses = static fn( $statuses ) => array_merge( (array) $statuses, array( 'on-hold' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', $paid_statuses );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST[ 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token' ] = '123';

		try {
			$gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_order_is_paid_statuses', $paid_statuses );
		}

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context, 'The saved-token change must reach payment processing.' );
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
	 * @testdox Should reject WooPay preflight checks with invalid fraud-prevention tokens before changing the order status.
	 */
	public function test_process_payment_rejects_woopay_preflight_with_invalid_fraud_token_before_status_change(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$order->update_status( 'failed' );
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';
		$_POST['is-woopay-preflight-check']                     = '1';

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
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			),
			$result
		);
		$this->assertSame(
			"We're not able to process this payment. Please refresh the page and try again.",
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertNull( $service->last_checkout_context );
	}

	/**
	 * @testdox Should reject rate-limited WooPay preflight checks before changing the order status.
	 */
	public function test_process_payment_rejects_rate_limited_woopay_preflight_before_status_change(): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$order->update_status( 'failed' );
		$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );
		$_POST['is-woopay-preflight-check'] = '1';

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
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			),
			$result
		);
		$this->assertSame( 'Your payment was not processed.', wc_get_notices( 'error' )[0]['notice'] ?? '' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertNull( $service->last_checkout_context );
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
		$this->assertNull( WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
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
	 * @testdox Should pass the WooPay intent id through provider data with the client's sanitize rule.
	 */
	public function test_process_payment_passes_the_sanitized_woopay_intent_to_provider_data(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		// WooPay's merchant checkout body (PaymentsHandler::get_merchant_checkout_body()) sends the
		// intent it confirmed under this key; client 11.1.0 keeps only word characters after unslashing.
		$_POST['wcpay-payment-method']     = 'pm_woopay';
		$_POST['platform-checkout-intent'] = 'pi_3Ab-c<x>\\\'';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( 'pi_3Abcx', $service->last_checkout_context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID ] ?? null );
	}

	/**
	 * @testdox Should leave the WooPay intent id empty when the checkout sends none.
	 */
	public function test_process_payment_leaves_the_woopay_intent_empty_without_one(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( '', $service->last_checkout_context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID ] ?? null );
	}

	/**
	 * @testdox Should mark a WooPay checkout's order with is_woopay before the payment runs, whatever its outcome.
	 */
	public function test_process_payment_marks_a_woopay_order_even_when_the_payment_fails(): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array() );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		// WooPay's merchant checkout body sends is_woopay (PaymentsHandler::get_merchant_checkout_body()).
		$_POST['wcpay-payment-method'] = 'pm_woopay';
		$_POST['is_woopay']            = '1';

		$gateway->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '1', $order->get_meta( 'is_woopay', true ), 'Client 11.1.0 Payment_Information::from_payment_request() adds is_woopay before processing (class-payment-information.php:284-287).' );
	}

	/**
	 * @testdox Should keep one is_woopay entry when a WooPay order is paid again, as the client adds it as unique.
	 */
	public function test_process_payment_keeps_one_is_woopay_entry_on_a_retry(): void {
		$order = $this->create_order();
		$order->add_meta_data( 'is_woopay', '1', true );
		$order->save_meta_data();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array() );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_woopay';
		$_POST['is_woopay']            = '1';

		$gateway->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_meta( 'is_woopay', false ), 'Client 11.1.0 adds is_woopay with $unique = true (class-payment-information.php:285).' );
	}

	/**
	 * @testdox Should mark a WooPay order before the client payment-method error check, as the client does.
	 */
	public function test_process_payment_marks_a_woopay_order_before_the_client_error_check(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
		$_POST['wcpay-payment-method-error-message'] = 'Your card number is invalid.';
		$_POST['wcpay-payment-method-error-code']    = 'incomplete_number';
		$_POST['is_woopay']                          = '1';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '1', $order->get_meta( 'is_woopay', true ), 'Client 11.1.0 writes is_woopay before it turns the payment-method error into a failure (class-payment-information.php:284-296).' );
	}

	/**
	 * @testdox Should not mark an ordinary checkout's order with is_woopay.
	 */
	public function test_process_payment_does_not_mark_an_ordinary_order_as_woopay(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '', $order->get_meta( 'is_woopay', true ) );
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

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context );
		$this->assertSame( 'ctoken_express', $service->last_checkout_context->get_payment_method_id() );
		$this->assertSame( array( 'card', 'amazon_pay' ), $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY ] );
		$this->assertSame( 'pay_for_order', $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] );
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

		$recorded = $this->record_tracks_events();

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
		$this->assertArrayHasKey( 'wcadmin_wcpay_edit_order_refund_success', $recorded->events, 'A successful refund records the client\'s success event.' );
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
	 * @testdox Should refuse a refund amount outside the order total without a platform call: $_dataName.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2932-2938` returns
	 * `invalid-amount` before any request when the amount is negative or above the order total.
	 *
	 * @dataProvider invalid_refund_amount_provider
	 *
	 * @param float $amount Refund amount.
	 */
	public function test_process_refund_refuses_invalid_amount( float $amount ): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), $amount, 'Adjustment' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid-amount', $result->get_error_code() );
		$this->assertSame( 'The refund amount is not valid.', $result->get_error_message() );
		$this->assertNull( $service->last_refund_context, 'An invalid amount must never reach the platform refund call.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( $note_count, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * Refund amounts outside the 12.00 order total.
	 *
	 * @return array<string,array{0:float}>
	 */
	public function invalid_refund_amount_provider(): array {
		return array(
			'negative amount'          => array( -1.0 ),
			'one cent above the total' => array( 12.01 ),
			'negative sub-cent amount' => array( -0.001 ),
		);
	}

	/**
	 * @testdox Should accept a refund that rounds to 0.00 without the amount check: $_dataName.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2926-2930` returns `true` when
	 * `sprintf( '%0.2f', $amount )` is `'0.00'`, before the amount check at `:2932-2938`, so a refund of
	 * 0.004 on a zero-total order is not `invalid-amount` even though it is above the total.
	 *
	 * @dataProvider zero_refund_amount_provider
	 *
	 * @param float  $amount      Refund amount.
	 * @param string $order_total Order total.
	 */
	public function test_process_refund_accepts_zero_refund( float $amount, string $order_total ): void {
		$order = $this->create_order();
		$order->set_total( $order_total );
		$order->save();

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider() );

		$this->assertTrue( $gateway->process_refund( $order->get_id(), $amount, 'Restock only' ) );
	}

	/**
	 * Refund amounts the client treats as a 0.00 no-op.
	 *
	 * @return array<string,array{0:float,1:string}>
	 */
	public function zero_refund_amount_provider(): array {
		return array(
			'0.00 on a 12.00 order'          => array( 0.0, '12.00' ),
			'sub-cent on a zero-total order' => array( 0.004, '0.00' ),
		);
	}

	/**
	 * @testdox Should accept a refund of exactly the order total.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2932` refuses only an amount
	 * strictly above the order total, so a full refund still reaches the platform.
	 */
	public function test_process_refund_accepts_the_full_order_total(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 12.0, 'Full refund' );

		$this->assertTrue( $result );
		$this->assertInstanceOf( PaymentContext::class, $service->last_refund_context );
		$this->assertSame( 12.0, $service->last_refund_context->get_payment_data()['amount'] );
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
	 * @testdox Should write a failure note and mark the refund failed when a synchronous refund attempt fails.
	 */
	public function test_process_refund_failure_writes_order_note_and_failed_status(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new class() extends RecordingPaymentProcessingService {
			/**
			 * Fail the refund like a provider refusal.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentContext $context, ProviderContract $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'expired_or_canceled_card', 'The card was declined.' );
			}
		};
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'failed to complete', (string) $notes[0]->content );
		$this->assertStringContainsString( 'The card was declined.', (string) $notes[0]->content );
	}

	/**
	 * @testdox Should give an insufficient-balance refund failure the dedicated funding-guidance note.
	 */
	public function test_process_refund_insufficient_balance_writes_dedicated_note(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new class() extends RecordingPaymentProcessingService {
			/**
			 * Fail the refund with the platform's insufficient-balance code.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentContext $context, ProviderContract $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'insufficient_balance_for_refund', 'Could not refund the payment: insufficient funds.' );
			}
		};
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, $this->create_account_service_for_country( 'US' ) );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'insufficient funds in your WooPayments balance', (string) $notes[0]->content );
		$this->assertStringContainsString( 'FROD', (string) $notes[0]->content, 'A US account supports FROD, so the note must carry the funding guidance.' );
		$this->assertStringNotContainsString( 'failed to complete', (string) $notes[0]->content, 'The generic failure line must not bury the funding guidance.' );
	}

	/**
	 * @testdox Should not record a refund failure when the attempt was refused for a concurrent refund.
	 */
	public function test_process_refund_lock_refusal_records_no_failure(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new class() extends RecordingPaymentProcessingService {
			/**
			 * Refuse the refund before any platform attempt.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentContext $context, ProviderContract $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'native_payment_refund_locked', 'A payment operation is already in progress for this order.' );
			}
		};
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ), 'A refusal that made no platform attempt must not mark the refund failed.' );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should refuse a refund with no refund row before the platform call, returning the client's refund-not-found error.
	 *
	 * Client 11.1.0 refunds first and then returns `wcpay_edit_order_refund_not_found` after tracking the
	 * success (class-wc-payment-gateway-wcpay.php:2979, :3003-3007). Native keeps the code and message but
	 * refuses before any money moves, so it records neither the success event nor a failure.
	 */
	public function test_process_refund_without_a_refund_row_is_refused_before_the_platform_call(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();
		$provider = new class() extends WooPaymentsProvider {
			/**
			 * Number of platform refund calls.
			 *
			 * @var int
			 */
			public int $refund_calls = 0;

			/**
			 * Skip the parent constructor; this double only answers refund().
			 */
			public function __construct() {}

			/**
			 * Record the platform refund call and report it as successful.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				++$this->refund_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_no_row' );
			}
		};
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$recorded = $this->record_tracks_events();

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertSame( 0, $provider->refund_calls, 'A refund with no row to link must never reach the platform.' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_edit_order_refund_not_found', $result->get_error_code() );
		$this->assertSame( 'A refund cannot be found for order: ' . $order->get_id(), $result->get_error_message() );
		$this->assertArrayNotHasKey( 'wcadmin_wcpay_edit_order_refund_success', $recorded->events, 'No refund happened, so no success event.' );
		$this->assertArrayNotHasKey( 'wcadmin_wcpay_edit_order_refund_failure', $recorded->events );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ), 'A refusal before the platform call must not mark the refund failed.' );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should short-circuit process_payment when the order is already paid, before reaching the processing service.
	 */
	public function test_process_payment_short_circuits_when_order_already_paid(): void {
		$order = $this->create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'processing' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] );
		$this->assertSame( 0, $service->checkout_attempt_count, 'An already-paid order must never reach the processing service.' );
		$this->assertNull( WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox Should answer as for an already-paid order when another submission paid it while this one waited for the lock.
	 */
	public function test_process_payment_answers_as_already_paid_when_the_order_was_paid_under_the_lock(): void {
		$order = $this->create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();

		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, '', '', '', '', array( PaymentOutcome::DATA_ORDER_PAID_BY_ANOTHER_REQUEST => true ) );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );
		$notes  = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertSame( 1, $service->checkout_attempt_count );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'], 'The answer must match the already-paid check before the lock.' );
		$this->assertSame( 'WooPayments: detected and prevented a second payment for this order, which had already been paid.', $notes[0]->content ?? null );
		$this->assertNull( WC()->session->get( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox Should resolve native dependencies when WooCommerce instantiates the gateway directly.
	 */
	public function test_process_payment_resolves_dependencies_without_explicit_init(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		wc_get_container()->replace( PaymentProcessingService::class, $service );
		$gateway = new NativeWooPaymentsGateway();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'], 'Only a resolved processing service and provider produce a successful checkout.' );
		$this->assertSame( 1, $service->checkout_attempt_count, 'The container-resolved processing service must run the checkout.' );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
	}

	/**
	 * @testdox A split gateway renders its payment fields with the card gateway's supports, as the client's one checkout does.
	 */
	public function test_split_gateway_payment_fields_use_the_card_gateway_supports(): void {
		$registry          = new WooPaymentsPaymentMethodRegistry();
		$card_gateway      = new NativeWooPaymentsGateway( $registry->get( 'card' ) );
		$received_supports = null;
		$bridge            = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge->method( 'render_payment_fields' )->willReturnCallback(
			static function ( array $supports ) use ( &$received_supports ): void {
				$received_supports = $supports;
			}
		);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'get_gateway_for_method' )->willReturnMap( array( array( 'card', $card_gateway ) ) );
		$card_gateway->supports = array( 'products', 'refunds', 'subscriptions', 'card_only_marker' );

		$klarna_gateway = new NativeWooPaymentsGateway( $registry->get( 'klarna' ) );
		$klarna_gateway->init( new RecordingPaymentProcessingService(), $provider, $bridge );
		$klarna_gateway->form();

		$this->assertSame( $card_gateway->supports, $received_supports );
	}

	/**
	 * @testdox Should delegate payment fields rendering to the checkout bridge with the gateway's own supports.
	 */
	public function test_payment_fields_delegate_to_checkout_bridge(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		// A customer with a saved card: core lists it with the new-card option, and the save checkbox follows.
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_test_saved' );
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $customer_id );
		$token->save();

		$service           = new RecordingPaymentProcessingService();
		$received_supports = null;
		$bridge            = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge
			->expects( $this->once() )
			->method( 'render_payment_fields' )
			->willReturnCallback(
				static function ( array $supports, $payment_method_definition = null, ?callable $render_saved_payment_methods = null ) use ( &$received_supports ): void {
					$received_supports = $supports;
					echo '<div id="wcpay-bridge-marker">';
					if ( null !== $render_saved_payment_methods ) {
						$render_saved_payment_methods();
					}
					echo '</div>';
				}
			);

		$output           = '';
		$gateway_supports = array();
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $service, $bridge, &$output, &$gateway_supports ): void {
					$gateway = new NativeWooPaymentsGateway();
					$gateway->init( $service, new WooPaymentsProvider(), $bridge );
					$gateway_supports = $gateway->supports;

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}

		$this->assertSame( $gateway_supports, $received_supports );
		$this->assertContains( PaymentGatewayFeature::TOKENIZATION, $received_supports );
		$this->assertStringContainsString( 'wcpay-bridge-marker', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-new-payment-method', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-payment-token-new', $output );
		// The bridge prints the saved payment methods inside its form, below the test-mode instructions, as in
		// client 11.1.0 (includes/class-wc-payments-checkout.php:474-499); the save checkbox follows the form.
		$this->assertMatchesRegularExpression( '/<div id="wcpay-bridge-marker">.*wc-woocommerce_payments-payment-token-new.*<\/div>.*wc-woocommerce_payments-new-payment-method/s', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]*>/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should load core's tokenization-form.js on the My Account add-payment-method form without saved methods or a save checkbox, as client 11.1.0 does.
	 */
	public function test_payment_fields_load_tokenization_form_on_add_payment_method_page(): void {
		global $wp;

		$my_account_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$original_page_id   = get_option( 'woocommerce_myaccount_page_id' );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
		$this->go_to( get_permalink( $my_account_page_id ) );
		$wp->query_vars['add-payment-method'] = '';
		wp_dequeue_script( 'woocommerce-tokenization-form' );

		$received_callback = 'not called';
		$bridge            = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge->method( 'render_payment_fields' )->willReturnCallback(
			static function ( array $supports, $payment_method_definition = null, ?callable $render_saved_payment_methods = null ) use ( &$received_callback ): void {
				$received_callback = $render_saved_payment_methods;
				echo '<div id="wcpay-bridge-marker"></div>';
			}
		);

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $bridge, &$output ): void {
					$gateway = new NativeWooPaymentsGateway();
					$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $bridge );

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			unset( $wp->query_vars['add-payment-method'] );
			update_option( 'woocommerce_myaccount_page_id', $original_page_id );
		}

		// Client 11.1.0 includes/class-wc-payments-checkout.php:401,493-499: tokenization shows on this page, so core's
		// script loads, but the saved methods list does not.
		$this->assertTrue( wp_script_is( 'woocommerce-tokenization-form', 'enqueued' ) );
		$this->assertNull( $received_callback );
		$this->assertStringContainsString( 'wcpay-bridge-marker', $output );
		$this->assertStringNotContainsString( 'woocommerce-SavedPaymentMethods', $output );
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
	 * @testdox Should print neither the saved methods list nor the save checkbox for a guest outside a subscription.
	 */
	public function test_payment_fields_offer_a_guest_no_saving(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		wp_set_current_user( 0 );
		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'render_payment_fields' ) )
			->getMock();
		$bridge->method( 'render_payment_fields' )->willReturnCallback(
			static function ( array $supports, $payment_method_definition = null, ?callable $render_saved_payment_methods = null ): void {
				unset( $supports, $payment_method_definition );
				if ( null !== $render_saved_payment_methods ) {
					$render_saved_payment_methods();
				}
			}
		);

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $bridge, &$output ): void {
					$gateway = new NativeWooPaymentsGateway();
					$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $bridge );

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}

		$this->assertStringNotContainsString( 'wc-saved-payment-methods', $output );
		$this->assertStringNotContainsString( 'wc-woocommerce_payments-new-payment-method', $output );
	}

	/**
	 * @testdox Should turn the express gateways off in the block editor before the Checkout block lists enabled gateways.
	 */
	public function test_block_editor_turns_express_gateways_off(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$gateways = array();
		foreach ( array( 'card', 'apple_pay', 'klarna' ) as $payment_method_id ) {
			$gateway          = new NativeWooPaymentsGateway( $registry->get( $payment_method_id ) );
			$gateway->enabled = 'yes';
			$gateways[]       = $gateway;
		}
		$wc_gateways = WC()->payment_gateways();
		$previous    = $wc_gateways->payment_gateways;
		// WC_Payment_Gateways::payment_gateways() returns this public list.
		$wc_gateways->payment_gateways = $gateways;

		try {
			// The Checkout block reads the enabled gateways at the default priority (PaymentUtils::get_enabled_payment_gateways()).
			$this->assertSame( 1, has_action( 'enqueue_block_editor_assets', array( NativeWooPaymentsGateway::class, 'handle_enqueue_block_editor_assets' ) ) );
			NativeWooPaymentsGateway::handle_enqueue_block_editor_assets();
		} finally {
			$wc_gateways->payment_gateways = $previous;
		}

		$this->assertSame( array( 'yes', 'no', 'yes' ), array_column( $gateways, 'enabled' ) );
	}

	/**
	 * @testdox Should print no saved payment methods list for a customer without saved methods, and the list otherwise.
	 */
	public function test_saved_payment_methods_list_needs_saved_methods(): void {
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );

		$render         = static function (): string {
			$gateway = new NativeWooPaymentsGateway();
			ob_start();
			$gateway->saved_payment_methods();
			return (string) ob_get_clean();
		};
		$without_tokens = '';
		$with_tokens    = '';

		$this->with_gateway_settings(
			array( 'saved_cards' => 'yes' ),
			function () use ( $render, $customer_id, &$without_tokens, &$with_tokens ): void {
				$without_tokens = $render();

				$token = new \WC_Payment_Token_CC();
				$token->set_token( 'pm_test_saved' );
				$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
				$token->set_card_type( 'visa' );
				$token->set_last4( '4242' );
				$token->set_expiry_month( '12' );
				$token->set_expiry_year( '2040' );
				$token->set_user_id( $customer_id );
				$token->save();

				$with_tokens = $render();
			}
		);

		$this->assertSame( '', $without_tokens );
		$this->assertStringContainsString( 'wc-saved-payment-methods', $with_tokens );
		$this->assertStringContainsString( '4242', $with_tokens );
	}

	/**
	 * @testdox Should hide a checked save-payment control for a subscription cart.
	 */
	public function test_save_payment_method_checkbox_hides_checked_control_for_subscription_cart(): void {
		WooCommerceSubscriptionsDoubles::load_cart();
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
		WooCommerceSubscriptionsDoubles::load_cart();
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
	 * @testdox The card save checkbox is $expected when WooPay applies to the checkout page ($platform), unchecked either way.
	 *
	 * Client 11.1.0 save_payment_method_checkbox() hides the checkbox when it is forced or when
	 * should_use_stripe_platform_on_checkout_page() holds (gw:1146), and that predicate is false for every gateway but card
	 * (gw:1174-1176). Ported from the client's test_save_payment_method_checkbox_displayed and
	 * test_save_payment_method_checkbox_not_displayed_when_stripe_platform_account_used, and
	 * test_should_not_use_stripe_platform_on_checkout_page_for_non_card (tests/unit/test-class-wc-payment-gateway-wcpay.php:1754-1758, 1893-1944).
	 *
	 * @testWith ["card", true, "hidden"]
	 *           ["card", false, "visible"]
	 *           ["sepa_debit", true, "visible"]
	 *
	 * @param string $payment_method Payment method of the gateway.
	 * @param bool   $platform       Whether the checkout bridge reports WooPay on the checkout page.
	 * @param string $expected       Whether the checkbox is hidden or visible.
	 */
	public function test_save_payment_method_checkbox_hides_for_woopay_on_the_card_checkout( string $payment_method, bool $platform, string $expected ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = false;
		$bridge = $this->createMock( WooPaymentsCheckoutBridge::class );
		$bridge->method( 'should_use_stripe_platform_on_checkout_page' )->willReturn( $platform );
		$gateway = new NativeWooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method ) );
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $bridge );

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="wc-' . $gateway->id . '-new-payment-method"' ) );
		$hidden = preg_match( '/<div style="display:none;">.*<input[^>]+id="wc-' . preg_quote( $gateway->id, '/' ) . '-new-payment-method".*<\/div>/s', $output );
		$this->assertSame( 'hidden' === $expected ? 1 : 0, $hidden );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox The save checkbox reads the client's label on a $cart cart.
	 *
	 * Client 11.1.0 labels the checkbox "Save payment information to my account for future purchases." (gw:1160), visible
	 * or forced; core's "Save to account" never shows.
	 *
	 * @testWith ["regular"]
	 *           ["subscription"]
	 *
	 * @param string $cart Cart kind.
	 */
	public function test_save_payment_method_checkbox_uses_the_client_label( string $cart ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = 'subscription' === $cart;
		$gateway = new NativeWooPaymentsGateway();

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<label for="wc-woocommerce_payments-new-payment-method" style="display:inline;">Save payment information to my account for future purchases\.</label>#', $output );
		$this->assertStringNotContainsString( 'Save to account', $output );
	}

	/**
	 * @testdox Should keep the visible unchecked save control on a renewal-only classic cart, like the extension.
	 */
	public function test_save_payment_method_checkbox_stays_visible_for_renewal_only_cart(): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = false;
		$GLOBALS['wcpay_test_cart_contains_renewal']      = true;

		$gateway = new NativeWooPaymentsGateway();

		try {
			ob_start();
			$gateway->save_payment_method_checkbox();
			$output = (string) ob_get_clean();
		} finally {
			unset( $GLOBALS['wcpay_test_cart_contains_renewal'] );
		}

		$this->assertStringNotContainsString( 'style="display:none;"', $output, 'The extension keeps the classic checkbox visible on renewal carts; the save is forced server-side regardless.' );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should keep the ordinary save control when subscription change form identity does not match.
	 */
	public function test_payment_fields_reject_mismatched_subscription_change_form_identity(): void {
		RecordedPublicFraudServices::answer();
		$this->ensure_wcs_subscription_detector_double();
		$order = $this->create_order();
		// Changing a subscription's payment method is a My Account flow; a guest gets no save control at all.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

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
		RecordedPublicFraudServices::answer();
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
	 * @param string $country          Merchant account country.
	 * @param string $capability_key   Active payment method capability key.
	 * @param bool   $test_mode        Whether the account is in test mode.
	 * @param string $default_currency Account default (domestic) currency.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_for_country( string $country, string $capability_key = 'card_payments', bool $test_mode = true, string $default_currency = 'usd' ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'          => $country,
				'capabilities'     => array( $capability_key => 'active' ),
				'store_currencies' => array( 'default' => $default_currency ),
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
		$this->assertStringContainsString( 'path=%2Fpayments%2Ftransactions%2Fdetails', $url );
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
	 * Assert the succeeded-intent defense of client 11.1.0 `class-wc-payment-gateway-wcpay.php:1283-1304`.
	 *
	 * @param int                  $order_id   Order ID.
	 * @param array<string,string> $result     process_payment() result.
	 * @param string               $return_url Filtered return URL for the order.
	 * @param int                  $note_count Order note count before processing.
	 * @param string               $message    Downstream error message.
	 * @param string               $label      What the warning names as the downstream failure.
	 * @param object               $logger     Logger from capture_logs().
	 * @param string               $status     Order status that must be preserved.
	 */
	private function assert_succeeded_intent_defense( int $order_id, array $result, string $return_url, int $note_count, string $message, string $label, object $logger, string $status = 'processing' ): void {
		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( $return_url, $result['redirect'] ?? '' );

		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status, $order->get_status(), 'The order status must be preserved.' );

		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) );
		$this->assertCount( $note_count + 1, $notes, 'Only the downstream-error note may be added.' );
		$this->assertContains( 'Payment succeeded, but a downstream error occurred during post-payment processing: ' . esc_html( $message ) . '. Order status preserved.', $notes );

		$this->assertSame(
			array( sprintf( 'Payment intent already succeeded; downstream %s on order #%d suppressed to preserve order status.', $label, $order_id ) ),
			$logger->warning_messages()
		);
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not see an error notice.' );
	}

	/**
	 * @testdox A platform error applying a renewal payment is logged with its status and code, never its message.
	 */
	public function test_renewal_apply_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_renewal_platform_error', '', 'pm_renewal_card' ),
			'post_lifecycle_effects',
			self::make_provider_error()
		);
		$gateway  = new NativeWooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$logger = RecordingWcLogger::install();

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$context = $this->get_logged_context( $logger, 'Error applying the WooPayments subscription renewal payment.' );
		$this->assertSame( array( 404, 'resource_missing', $order->get_id() ), array( $context['http_status'], $context['error_code'], $context['order_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error during the renewal token repair is logged with its status and code, never its message.
	 */
	public function test_renewal_token_repair_log_leaves_out_platform_text(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		self::enable_woopayments_debug_logging();
		$customer_id = self::factory()->user->create();
		$parent      = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();
		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();
		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willThrowException( self::make_provider_error() );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$logger  = RecordingWcLogger::install();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$context = $this->get_logged_context( $logger, 'Error repairing subscription renewal payment token for order #' . $renewal->get_id() . '.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A failed synchronous refund is logged with a listed error code, never the platform's message.
	 *
	 * Client 11.1.0 logs the failure note (gw:2994), which carries the platform's message; the note itself is unchanged.
	 */
	public function test_process_refund_failure_log_leaves_out_platform_text(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();
		$service = new class() extends RecordingPaymentProcessingService {
			/**
			 * Fail the refund with the platform's code and message, as the processing service hands them back.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentContext $context, ProviderContract $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'https://pay.example.test/code', "Error: No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123" );
			}
		};
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$logger = RecordingWcLogger::install();

		$gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$context = $this->get_logged_context( $logger, 'A WooPayments refund failed to complete.' );
		$this->assertSame( array( $order->get_id(), 'unknown_error' ), array( $context['order_id'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error fetching the recommended payment methods is logged with its status and code, never its message.
	 */
	public function test_recommended_payment_methods_fetch_log_leaves_out_platform_text(): void {
		delete_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' );
		self::enable_woopayments_debug_logging();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client->method( 'get_recommended_payment_methods' )->willThrowException( self::make_provider_error() );
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client );
		$logger = RecordingWcLogger::install();

		$this->assertSame( array(), $gateway->get_recommended_payment_methods( 'GB' ) );

		$context = $this->get_logged_context( $logger, 'Failed to fetch the WooPayments recommended payment methods.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Create a WooPayments provider whose charge returns a fixed outcome and whose effects can throw.
	 *
	 * @param PaymentOutcome $outcome Outcome the charge returns.
	 * @param string         $stage   Where to throw: `operation_effects` (before the lifecycle) or `post_lifecycle_effects` (after it).
	 * @param \Throwable     $failure What to throw.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_failing_after_charge( PaymentOutcome $outcome, string $stage, \Throwable $failure ): WooPaymentsProvider {
		return new class( $outcome, $stage, $failure ) extends WooPaymentsProvider {
			/**
			 * Outcome the charge returns.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Where to throw.
			 *
			 * @var string
			 */
			private string $stage;

			/**
			 * What to throw.
			 *
			 * @var \Throwable
			 */
			private \Throwable $failure;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome Outcome the charge returns.
			 * @param string         $stage   Where to throw.
			 * @param \Throwable     $failure What to throw.
			 */
			public function __construct( PaymentOutcome $outcome, string $stage, \Throwable $failure ) {
				$this->outcome = $outcome;
				$this->stage   = $stage;
				$this->failure = $failure;
			}

			/**
			 * Return the fixed charge outcome.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->outcome;
			}

			/**
			 * Throw before the lifecycle when asked to.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws \Throwable When the stage is `operation_effects`.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				if ( 'operation_effects' === $this->stage ) {
					throw $this->failure;
				}

				return $outcome;
			}

			/**
			 * Throw after the lifecycle when asked to.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Applied provider outcome.
			 * @param string         $operation Operation name.
			 * @throws \Throwable When the stage is `post_lifecycle_effects`.
			 */
			public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $context, $outcome, $operation );
				if ( 'post_lifecycle_effects' === $this->stage ) {
					throw $this->failure;
				}
			}
		};
	}

	/**
	 * Route the order's return URL to a distinctive value.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function filter_return_url( WC_Order $order ): string {
		$return_url = 'https://example.test/return/' . $order->get_id();
		add_filter(
			'woocommerce_get_return_url',
			static function ( $url, $filtered_order ) use ( $order, $return_url ) {
				return $filtered_order instanceof WC_Order && $filtered_order->get_id() === $order->get_id() ? $return_url : $url;
			},
			10,
			2
		);

		return $return_url;
	}

	/**
	 * Turn on WooPayments debug logging, which the client's Logger requires for checkout errors (`src/Internal/Logger.php:64-91`).
	 */
	private function enable_debug_logging(): void {
		$settings = get_option( 'woocommerce_woocommerce_payments_settings', array() );
		update_option( 'woocommerce_woocommerce_payments_settings', array_merge( is_array( $settings ) ? $settings : array(), array( 'enable_logging' => 'yes' ) ) );
	}

	/**
	 * Capture WooCommerce log entries through the woocommerce_logging_class filter.
	 *
	 * @return object Logger with a warning_messages() reader.
	 */
	private function capture_logs(): object {
		$logger = new class() implements \WC_Logger_Interface {
			/**
			 * Logged entries.
			 *
			 * @var array<int,array{level:string,message:string}>
			 */
			public array $entries = array();

			/**
			 * Messages logged at warning level.
			 *
			 * @return string[]
			 */
			public function warning_messages(): array {
				return array_values( array_map( static fn( $entry ) => $entry['message'], array_filter( $this->entries, static fn( $entry ) => 'warning' === $entry['level'] ) ) );
			}

			/**
			 * Add a log entry.
			 *
			 * @param string $handle  File handle.
			 * @param string $message Log message.
			 * @param string $level   Log level.
			 * @return bool
			 */
			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				unset( $handle );
				$this->log( $level, $message );
				return true;
			}

			/**
			 * Add a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function log( $level, $message, $context = array() ) {
				unset( $context );
				$this->entries[] = array(
					'level'   => (string) $level,
					'message' => (string) $message,
				);
			}

			/**
			 * Log an emergency entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function emergency( $message, $context = array() ) {
				$this->log( 'emergency', $message, $context );
			}

			/**
			 * Log an alert entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function alert( $message, $context = array() ) {
				$this->log( 'alert', $message, $context );
			}

			/**
			 * Log a critical entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function critical( $message, $context = array() ) {
				$this->log( 'critical', $message, $context );
			}

			/**
			 * Log an error entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function error( $message, $context = array() ) {
				$this->log( 'error', $message, $context );
			}

			/**
			 * Log a warning entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function warning( $message, $context = array() ) {
				$this->log( 'warning', $message, $context );
			}

			/**
			 * Log a notice entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function notice( $message, $context = array() ) {
				$this->log( 'notice', $message, $context );
			}

			/**
			 * Log an info entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function info( $message, $context = array() ) {
				$this->log( 'info', $message, $context );
			}

			/**
			 * Log a debug entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function debug( $message, $context = array() ) {
				$this->log( 'debug', $message, $context );
			}
		};
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		return $logger;
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
	 * Record Tracks events by name.
	 *
	 * @return \stdClass Holder whose `events` property maps event names to properties.
	 */
	private function record_tracks_events(): \stdClass {
		if ( ! function_exists( 'wc_admin_record_tracks_event' ) ) {
			require_once WC_ABSPATH . 'includes/react-admin/core-functions.php';
		}
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded         = new \stdClass();
		$recorded->events = array();
		add_filter(
			'woocommerce_tracks_event_properties',
			static function ( $properties, $event_name ) use ( $recorded ) {
				$recorded->events[ $event_name ] = $properties;
				return $properties;
			},
			10,
			2
		);

		return $recorded;
	}

	/**
	 * Create a token service built from the container's dependencies, with no hooks registered.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function create_unhooked_token_service(): WooPaymentsTokenService {
		$container     = wc_get_container();
		$token_service = new WooPaymentsTokenService();
		$token_service->init(
			$container->get( WooPaymentsPaymentMethodDetailsService::class ),
			new StaticNativeRuntimeArbiter( false ),
			$container->get( WooPaymentsApiClient::class ),
			$container->get( WooPaymentsCustomerService::class ),
			$container->get( WooPaymentsAccountService::class )
		);

		return $token_service;
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
	 * @testdox Should repair a renewal order's missing token from the parent order and charge it.
	 */
	public function test_scheduled_subscription_payment_repairs_missing_token_from_parent_order(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->save();

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_token( 'pm_repair_123' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->set_total( '10.00' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertInstanceOf( PaymentContext::class, $service->last_checkout_context, 'The repaired token must let the renewal charge proceed.' );
		$this->assertSame( (string) $token->get_id(), $service->last_checkout_context->get_payment_data()['payment_token'] );

		$renewal_fresh = wc_get_order( $renewal->get_id() );
		$this->assertNotSame( 'failed', $renewal_fresh->get_status() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $renewal_fresh->get_payment_tokens() ), 'The restored token must land on the renewal order itself.' );
		$renewal_notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$this->assertContains( 'Recovered missing subscription payment method token from the parent order.', $renewal_notes );

		$subscription_fresh = wc_get_order( $subscription->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription_fresh->get_payment_tokens() ), 'The subscription must get the restored token so the next renewal does not need repair.' );
		$subscription_notes = implode( ' | ', array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) ) );
		$this->assertStringContainsString( 'restored', $subscription_notes );
	}

	/**
	 * @testdox Should still fail the renewal when the parent order has no payment method to restore.
	 */
	public function test_scheduled_subscription_payment_fails_when_repair_finds_nothing(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		$this->enable_debug_logging();
		$logger = RecordingWcLogger::install();

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertNull( $service->last_checkout_context );
		$renewal_fresh = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'failed', $renewal_fresh->get_status() );
		// Client 11.1.0 trait:415.
		$this->assertContains( array( 'error', 'There is no saved payment token for order #' . $renewal->get_id(), 'woopayments' ), $logger->lines );
	}

	/**
	 * @testdox When the token repair throws a $throwable_class, the renewal ends $status and the throwable propagates: $propagates.
	 *
	 * Client 11.1.0 trait:538 catches only exceptions: an exception fails the renewal for a missing token (trait:413-418),
	 * a PHP Error reaches the scheduled action and leaves the renewal pending (monitor ruling 2026-10-04 (3)).
	 *
	 * @testWith ["RuntimeException", "failed", false]
	 *           ["TypeError", "pending", true]
	 *
	 * @param string $throwable_class Class the repair throws.
	 * @param string $status          Renewal status afterwards.
	 * @param bool   $propagates      Whether the throwable leaves scheduled_subscription_payment().
	 */
	public function test_scheduled_subscription_payment_token_repair_failure( string $throwable_class, string $status, bool $propagates ): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$thrown        = new $throwable_class( 'Call to a member function get_id() on null' );
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willThrowException( $thrown );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$logger = RecordingWcLogger::install();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertSame( $propagates ? $thrown : null, $caught );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( $status, wc_get_order( $renewal->get_id() )->get_status() );
		$repair_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => str_starts_with( $line[1], 'Error repairing subscription renewal payment token' ) ) );
		$this->assertCount( $propagates ? 1 : 0, $repair_lines, 'Only a PHP Error is logged with debug logging off.' );
	}

	/**
	 * @testdox A PHP error fetching the payment method during token repair fails the scheduled action and leaves the renewal pending.
	 *
	 * Client 11.1.0 fetches the payment method without a catch (`class-wc-payments-token-service.php:136`) and the repair
	 * catches only Exception (trait:538), so the PHP error reaches Action Scheduler instead of failing the renewal with
	 * "No saved payment method found" (review 34 F1, monitor ruling 2026-10-04 (3)). The error is logged once, by the
	 * repair (review 35 F8).
	 */
	public function test_scheduled_subscription_payment_token_repair_payment_method_fetch_php_error(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_fetch' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$error          = new \TypeError( 'Return value must be of type array, null returned' );
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyRuntimeProxy( false ) );
		$details_service = new WooPaymentsPaymentMethodDetailsService();
		$details_service->init(
			$legacy_runtime,
			new class( $error ) extends WooPaymentsApiClient {
				/**
				 * Error to throw.
				 *
				 * @var \TypeError
				 */
				private \TypeError $error;

				/**
				 * Constructor.
				 *
				 * @param \TypeError $error Error to throw.
				 */
				public function __construct( \TypeError $error ) {
					$this->error = $error;
				}

				// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
				/**
				 * Throw the PHP error.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @return array<string,mixed>
				 * @throws \TypeError Always.
				 */
				public function get_payment_method( string $payment_method_id ): array {
					unset( $payment_method_id );
					throw $this->error;
				}
				// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
			},
			new StaticNativeRuntimeArbiter( true )
		);
		$token_service = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticNativeRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$logger = RecordingWcLogger::install();

		$caught = null;
		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertSame( $error, $caught );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( 'pending', wc_get_order( $renewal->get_id() )->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$this->assertNotContains( 'Subscription renewal failed: No saved payment method found.', $notes );
		$error_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => str_starts_with( $line[1], 'Error repairing subscription renewal payment token' ) ) );
		$this->assertCount( 1, $error_lines, 'The PHP error is logged once.' );
		$this->assertSame( \TypeError::class, $logger->contexts[ $error_lines[0] ]['exception'] );
	}

	/**
	 * @testdox Should not attempt token repair when network-wide saved cards are forced.
	 */
	public function test_scheduled_subscription_payment_skips_repair_for_network_saved_cards(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		$filter = static fn(): bool => true;
		add_filter( 'wcpay_force_network_saved_cards', $filter );

		$service = new RecordingPaymentProcessingService();
		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		try {
			$gateway->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			remove_filter( 'wcpay_force_network_saved_cards', $filter );
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( 'failed', wc_get_order( $renewal->get_id() )->get_status() );
	}

	/**
	 * Ensure a minimal renewal-subscriptions lookup double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_renewal_subscriptions_double(): void {
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public renewal lookup.
		eval( 'namespace { function wcs_get_subscriptions_for_renewal_order( $order_id ) { $ids = $GLOBALS["wcpay_test_renewal_subscription_ids"][ $order_id ] ?? array(); return array_map( "wc_get_order", $ids ); } }' );
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
				public static $is_request_to_change_payment = false;
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
					// The real method sets the gateway, whose title then replaces the stored one.
					if ( is_object( $order ) && method_exists( $order, "set_payment_method_title" ) ) {
						$order->set_payment_method_title( "Card" );
						$order->save();
					}
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
