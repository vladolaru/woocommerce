<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedTransactionRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentMappingContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderSuccessPage;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Legacy as StoreApiLegacy;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext as StoreApiPaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult as StoreApiPaymentResult;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPublicFraudServices;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Payment_Token;

/**
 * Tests for the WooPaymentsGateway class.
 */
class WooPaymentsGatewayTest extends WC_Unit_Test_Case {

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
		$arbiter->init( $container->get( WooPaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'should_core_register' )->willReturn( $enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
	}

	/**
	 * Make the built-in WooPayments the payments owner, which the renewal handlers require.
	 */
	private function make_builtin_own_payments(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Make the built-in WooPayments the payments owner in the active tier, the only tier where the gateway is offered at checkout.
	 */
	private function activate_builtin_tier(): void {
		$this->make_builtin_own_payments();
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE, true );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$classic_checkout_fallback_hooks = new \ReflectionProperty( WooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$classic_checkout_fallback_hooks->setAccessible( true );
		$classic_checkout_fallback_hooks->setValue( null, false );
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
		unset( $_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] );
		unset( $_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-new-payment-method' ] );
		unset( $_POST['update_all_subscriptions_payment_method'] );
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'], $GLOBALS['wcpay_test_cart_contains_subscription'] );
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		delete_option( 'woocommerce_default_country' );
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
				$gateway = new WooPaymentsGateway();

				$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $gateway->id );
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
	 * @testdox Should brand the Afterpay gateway titles by account country like the WooPayments extension: $_dataName.
	 *
	 * With empty account data, while the platform connection is down, client 11.1.0 titles the gateway for US, not the
	 * store country (includes/class-wc-payment-gateway-wcpay.php:3604-3613; includes/class-wc-payments-account.php:2731-2734).
	 *
	 * @dataProvider provider_afterpay_titles_by_account_country
	 *
	 * @param array<string,string> $account_data   Cached account data.
	 * @param string|null          $store_country  Store base country, or null to keep the default.
	 * @param string               $expected_title Expected gateway title.
	 */
	public function test_gateway_titles_follow_account_country_branding( array $account_data, ?string $store_country, string $expected_title ): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'afterpay_clearpay' );
		$this->assertNotNull( $definition );
		if ( null !== $store_country ) {
			update_option( 'woocommerce_default_country', $store_country );
		}

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$gateway = new WooPaymentsGateway( $definition );
		$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );
		$gateway->handle_init();

		$this->assertSame( $expected_title, $gateway->get_title() );
		$this->assertSame( "WooPayments ({$expected_title})", $gateway->get_method_title() );
	}

	/**
	 * Account data, store country and the Afterpay title they give.
	 *
	 * @return array<string,array{0:array<string,string>,1:string|null,2:string}>
	 */
	public function provider_afterpay_titles_by_account_country(): array {
		return array(
			'GB account'                   => array( array( 'country' => 'GB' ), null, 'Clearpay' ),
			'US account'                   => array( array( 'country' => 'US' ), null, 'Cash App Afterpay' ),
			'AU account'                   => array( array( 'country' => 'AU' ), null, 'Afterpay' ),
			'no account country, GB store' => array( array(), 'GB', 'Cash App Afterpay' ),
		);
	}

	/**
	 * @testdox Should hide the gateway when the account's customer-supported currencies exclude the store currency
	 */
	public function test_gateway_availability_follows_account_customer_supported_currencies(): void {
		$this->activate_builtin_tier();
		$supported_currencies = array( 'usd' );
		$account_service      = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
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
			$gateway = new WooPaymentsGateway();
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
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
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
	 * @testdox Should hide a split gateway when its definition does not support the checkout currency, again after a supported one.
	 */
	public function test_split_gateway_availability_follows_payment_method_definition(): void {
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertFalse( $gateway->is_available() );

			$currency = 'EUR';

			$this->assertTrue( $gateway->is_available() );

			$currency = 'USD';

			$this->assertFalse( $gateway->is_available() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * @testdox Should leave a method that cannot be saved unavailable on the My Account add-payment-method page, while card stays available (client 11.1.0 class-wc-payment-gateway-wcpay.php:915-917).
	 */
	public function test_non_reusable_gateway_is_unavailable_on_the_add_payment_method_page(): void {
		global $wp;

		$this->activate_builtin_tier();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
		// Account fields as recorded in Providers/WooPayments/Fixtures/platform-test-drive-account.json `account` (`country`, capability statuses keyed by
		// capability); varied: a Belgian account holding only the two capabilities this test needs.
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
		$settings_filter    = static fn(): array => array( 'enabled' => 'yes' );
		$currency_filter    = static fn(): string => 'EUR';
		$my_account_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$original_page_id   = get_option( 'woocommerce_myaccount_page_id' );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' ) );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );
			$card_gateway = new WooPaymentsGateway();
			$card_gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );
			$this->assertTrue( $gateway->is_available(), 'Bancontact is offered at checkout.' );

			update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
			$this->go_to( get_permalink( $my_account_page_id ) );
			$wp->query_vars['add-payment-method'] = '';
			$this->assertTrue( is_add_payment_method_page() );

			$this->assertFalse( $gateway->is_available() );
			$this->assertTrue( $card_gateway->is_available(), 'Card can be saved, so it stays available.' );
		} finally {
			unset( $wp->query_vars['add-payment-method'] );
			update_option( 'woocommerce_myaccount_page_id', $original_page_id );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_bancontact_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
	}

	/**
	 * Client 11.1.0 reads saved cards per gateway (class-wc-payment-gateway-wcpay.php:987-989), and only the card gateway has
	 * the saved_cards form field with its "yes" default (:387-410, merged at :545; a split gateway gets only `enabled`,
	 * :379-385). A split gateway therefore reads its own stored value, or '' when its row holds none (WC_Settings_API::get_option()),
	 * and claims tokenization and adding payment methods (:338-340) only when its row stores "yes": rows first written before
	 * 10.1.0 saved the full defaults, later rows hold no saved_cards. With saved cards off on the card gateway, an available
	 * iDEAL gateway keeps WooCommerce's My Account "Payment methods" entry (wc-account-functions.php:123-136) only in the first
	 * history. Either way iDEAL offers no save checkbox at checkout (class-wc-payments-checkout.php:505) and no place on the
	 * add-payment-method page (class-wc-payment-gateway-wcpay.php:915-917).
	 *
	 * @testdox Should keep the My Account payment methods entry through a split gateway with saved cards off only when its row stores saved cards: $history.
	 * @testWith ["row without saved_cards (first written on 10.1.0 or later, or by native)", null, false]
	 *           ["row storing saved_cards yes (first written before 10.1.0)", "yes", true]
	 *
	 * @param string      $history          Store history.
	 * @param string|null $ideal_saved_cards iDEAL's stored saved_cards, or null for none.
	 * @param bool        $keeps_entry      Whether the Payment methods entry stays.
	 */
	public function test_split_gateway_keeps_the_payment_methods_entry_only_with_its_own_saved_cards( string $history, ?string $ideal_saved_cards, bool $keeps_entry ): void {
		global $wp;

		$this->activate_builtin_tier();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
		// Account fields as recorded in Providers/WooPayments/Fixtures/platform-test-drive-account.json `account` (`country`, capability statuses keyed by
		// capability); varied: a Dutch account holding only the two capabilities this test needs.
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'NL',
				'capabilities' => array(
					'card_payments'  => 'active',
					'ideal_payments' => 'active',
				),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_gateway_for_method' )->willReturn( null );
		$assets = $this->create_checkout_assets_double();
		// The WooPayments settings turn saved cards off. iDEAL's own row holds only what the client's settings sync writes
		// (enabled and the enabled list), plus saved_cards in the pre-10.1.0 history.
		$card_settings      = static fn(): array => array(
			'enabled'                        => 'yes',
			'saved_cards'                    => 'no',
			'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
		);
		$ideal_settings     = static fn(): array => array_merge(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			),
			null === $ideal_saved_cards ? array() : array( 'saved_cards' => $ideal_saved_cards )
		);
		$currency_filter    = static fn(): string => 'EUR';
		$my_account_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$original_page_id   = get_option( 'woocommerce_myaccount_page_id' );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $card_settings );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_ideal_settings', $ideal_settings );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$card_gateway = new WooPaymentsGateway();
			$card_gateway->init( new RecordingPaymentProcessingService(), $provider, $assets, null, $account_service );
			$ideal_gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( 'ideal' ) );
			$ideal_gateway->init( new RecordingPaymentProcessingService(), $provider, $assets, null, $account_service );
			WC()->payment_gateways()->payment_gateways = array(
				$card_gateway->id  => $card_gateway,
				$ideal_gateway->id => $ideal_gateway,
			);

			$this->assertSame( $keeps_entry, array_key_exists( 'payment-methods', wc_get_account_menu_items() ), $history );
			// The client adds both features together on a gateway whose own setting is on (class-wc-payment-gateway-wcpay.php:338-340).
			$this->assertSame( $keeps_entry, $ideal_gateway->supports( PaymentGatewayFeature::TOKENIZATION ), $history );
			$this->assertSame( $keeps_entry, $ideal_gateway->supports( PaymentGatewayFeature::ADD_PAYMENT_METHOD ), $history );

			add_filter( 'woocommerce_is_checkout', '__return_true' );
			ob_start();
			$ideal_gateway->payment_fields();
			$fields = (string) ob_get_clean();
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			$this->assertStringContainsString( 'id="wcpay-core-checkout-form"', $fields );
			$this->assertStringNotContainsString( 'wc-woocommerce_payments_ideal-new-payment-method', $fields, 'iDEAL cannot be saved, so it offers no save checkbox.' );

			update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
			$this->go_to( get_permalink( $my_account_page_id ) );
			$wp->query_vars['add-payment-method'] = '';
			$this->assertArrayNotHasKey( $ideal_gateway->id, WC()->payment_gateways()->get_available_payment_gateways(), 'iDEAL has nothing to add on the add-payment-method page.' );
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			unset( $wp->query_vars['add-payment-method'] );
			update_option( 'woocommerce_myaccount_page_id', $original_page_id );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $card_settings );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_ideal_settings', $ideal_settings );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * @testdox Should expose saved-card support on the card gateway only while saved cards are on: $_dataName.
	 *
	 * With no saved cards setting stored, saved cards are on, as the client's form field default (class-wc-payment-gateway-wcpay.php:404-410,987-989).
	 *
	 * @dataProvider provider_card_gateway_saved_cards_setting
	 *
	 * @param array<string,string> $settings WooPayments gateway settings.
	 * @param bool                 $expected Whether saved cards are on.
	 */
	public function test_card_gateway_saved_card_support_follows_saved_cards_setting( array $settings, bool $expected ): void {
		$this->with_gateway_settings(
			$settings,
			function () use ( $expected ): void {
				$gateway = new WooPaymentsGateway();

				$this->assertSame( $expected, $gateway->is_saved_cards_enabled() );
				$this->assertSame( $expected, $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
				$this->assertSame( $expected, $gateway->supports( PaymentGatewayFeature::ADD_PAYMENT_METHOD ) );
				$this->assertSame( $expected, in_array( PaymentGatewayFeature::TOKENIZATION, $gateway->supports, true ) );
				$this->assertSame( $expected, in_array( PaymentGatewayFeature::ADD_PAYMENT_METHOD, $gateway->supports, true ) );
			}
		);
	}

	/**
	 * Card gateway settings and whether they turn saved cards on.
	 *
	 * @return array<string,array{0:array<string,string>,1:bool}>
	 */
	public function provider_card_gateway_saved_cards_setting(): array {
		return array(
			'no saved cards setting stored' => array( array( 'enabled' => 'yes' ), true ),
			'saved cards on'                => array( array( 'saved_cards' => 'yes' ), true ),
			'saved cards off'               => array( array( 'saved_cards' => 'no' ), false ),
		);
	}

	/**
	 * Client 11.1.0 gives only the card gateway the saved_cards form field (class-wc-payment-gateway-wcpay.php:387-410, merged at
	 * :545); a split gateway reads its own row (:987-989), which the settings sync writes with only `enabled` and the enabled list,
	 * so it claims neither feature (:338-340), even for a method that can be saved.
	 *
	 * @testdox Should give a reusable split gateway with no saved cards setting of its own neither tokenization nor adding payment methods.
	 */
	public function test_reusable_split_gateway_without_its_own_saved_cards_claims_no_tokenization(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'apple_pay' );
		$this->assertNotNull( $definition );
		$apple_pay_settings = static fn(): array => array( 'enabled' => 'yes' );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_apple_pay_settings', $apple_pay_settings );

		try {
			$this->with_gateway_settings(
				array(
					'enabled'     => 'yes',
					'saved_cards' => 'yes',
				),
				function () use ( $definition ): void {
					$gateway = new WooPaymentsGateway( $definition );

					$this->assertFalse( $gateway->is_saved_cards_enabled() );
					$this->assertFalse( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
					$this->assertFalse( $gateway->supports( PaymentGatewayFeature::ADD_PAYMENT_METHOD ) );
				}
			);
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_apple_pay_settings', $apple_pay_settings );
		}
	}

	/**
	 * Client 11.1.0 `P24Definition.php` supports EUR and PLN for Polish shoppers; `class-upe-payment-method.php` applies the
	 * currencies in `is_currency_valid()` (:311-326) and hands the countries to checkout through `get_countries()` (:386-397).
	 *
	 * @testdox Should offer the P24 gateway only for the WooPayments extension's P24 currencies and keep its Polish shopper rule.
	 */
	public function test_p24_gateway_availability_follows_woopayments_extension_currencies_and_shopper_country(): void {
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $this->create_account_service_for_country( 'PL', 'p24_payments', true, 'pln' ) );

			$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'p24', $gateway->id );
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
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
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
	 * @testdox Should hide a method that cannot be reused from a subscription or renewal cart unless manual renewals are on: $_dataName.
	 *
	 * Client 11.1.0 `class-upe-payment-method.php:218-236`. Amazon Pay is offered as a list gateway only while it is enabled at
	 * checkout with list placement on, so its row turns both on.
	 *
	 * @dataProvider provider_gateway_availability_by_subscription_cart
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @param string $currency          Store currency.
	 * @param string $cart              Cart the shopper checks out: a subscription, a renewal or neither.
	 * @param bool   $manual_renewals   Whether WooCommerce Subscriptions accepts manual renewals.
	 * @param bool   $expected          Whether the method is offered.
	 */
	public function test_gateway_availability_hides_a_non_reusable_method_from_subscription_carts( string $payment_method_id, string $currency, string $cart, bool $manual_renewals, bool $expected ): void {
		$this->activate_builtin_tier();
		// WooCommerce Subscriptions loads its core library with its cart; the mock is reset after every test.
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] = 'subscription' === $cart;
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ]      = 'renewal' === $cart ? array( 'subscription_renewal' => array() ) : false;
		$this->ensure_wcs_manual_renewal_double();
		$GLOBALS['wcpay_test_manual_renewal_enabled'] = $manual_renewals;

		// Account fields as client 11.1.0 reads them: country and default currency (class-wc-payments-account.php:2731-2743)
		// and the capability statuses (class-wc-payment-gateway-wcpay.php:4696-4716).
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'          => 'US',
				'capabilities'     => array( "{$payment_method_id}_payments" => 'active' ),
				'store_currencies' => array( 'default' => 'usd' ),
			)
		);
		// Gateway settings the client reads for express list placement and enabled methods
		// (class-wc-payment-gateway-wcpay.php:1020-1022, :4682-4688).
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( $payment_method_id ) {
				$settings = array(
					'express_checkout_in_payment_methods' => 'yes',
					'upe_enabled_payment_method_ids'      => array( $payment_method_id ),
				);

				return $settings[ $key ] ?? $fallback;
			}
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		$settings_filter = static function (): array {
			return array( 'enabled' => 'yes' );
		};
		$currency_filter = static function () use ( $currency ): string {
			return $currency;
		};
		$feature_filter  = static fn(): string => '1';
		$settings_option = "pre_option_woocommerce_woocommerce_payments_{$payment_method_id}_settings";
		$previous_cart   = WC()->cart;
		WC()->cart       = new \WC_Cart();
		WC()->cart->set_total( '0' );
		add_filter( $settings_option, $settings_filter );
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );
		add_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );

		try {
			$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
			$this->assertNotNull( $definition );
			$gateway = new WooPaymentsGateway( $definition );
			$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

			$this->assertSame( $expected, $gateway->is_available() );
		} finally {
			remove_filter( $settings_option, $settings_filter );
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			remove_filter( 'pre_option__wcpay_feature_dynamic_checkout_place_order_button', $feature_filter );
			unset( $GLOBALS['wcpay_test_manual_renewal_enabled'] );
			WC()->cart = $previous_cart;
		}
	}

	/**
	 * Payment methods, carts and manual-renewal settings, and whether the method is offered.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool,4:bool}>
	 */
	public function provider_gateway_availability_by_subscription_cart(): array {
		return array(
			'Bancontact, subscription cart'          => array( 'bancontact', 'EUR', 'subscription', false, false ),
			'Bancontact, renewal cart'               => array( 'bancontact', 'EUR', 'renewal', false, false ),
			'Bancontact, ordinary cart'              => array( 'bancontact', 'EUR', 'ordinary', false, true ),
			'Bancontact, subscription cart, manual renewals on' => array( 'bancontact', 'EUR', 'subscription', true, true ),
			'reusable Amazon Pay, subscription cart' => array( 'amazon_pay', 'USD', 'subscription', false, true ),
		);
	}

	/**
	 * Define `wcs_is_manual_renewal_enabled()`, which reports the `wcpay_test_manual_renewal_enabled` global, false when unset.
	 */
	private function ensure_wcs_manual_renewal_double(): void {
		if ( function_exists( 'wcs_is_manual_renewal_enabled' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its manual renewal setting.
		eval( 'namespace { function wcs_is_manual_renewal_enabled() { return ! empty( $GLOBALS["wcpay_test_manual_renewal_enabled"] ); } }' );
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
			$gateway = new WooPaymentsGateway( $definition );
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
			$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
		$account_data    = array(
			'country'      => 'US',
			'capabilities' => array( 'card_payments' => 'restricted' ),
		);
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
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
				$gateway = new WooPaymentsGateway();
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
	 * @testdox Should tell WooCommerce Subscriptions active exactly while its class is loaded: $loaded (client 11.1.0 `trait-wc-payments-subscriptions-utilities.php:120-122`).
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $loaded Whether the WC_Subscriptions class is loaded.
	 */
	public function test_subscriptions_plugin_active_follows_the_loaded_class( bool $loaded ): void {
		wc_get_container()->get( LegacyProxy::class )->register_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name ? $loaded : class_exists( $class_name, ...$args ),
			)
		);

		$this->assertSame( $loaded, ( new WooPaymentsGateway() )->is_subscriptions_plugin_active() );
	}

	/**
	 * Client 11.1.0 offers its card gateway only while card is among the methods enabled at checkout (class-wc-payment-gateway-wcpay.php:941),
	 * read from the gateway's own settings with card as the default when no list is stored, and as the empty value WC_Settings_API
	 * puts in place of a stored '' (:4682-4689; abstract-wc-settings-api.php:310-312). '' is what WC_Settings_API's multiselect
	 * validation saves for an empty selection.
	 *
	 * @testdox Should offer the card gateway only while the stored enabled methods include card: $scenario.
	 * @testWith ["no stored list", null, true]
	 *           ["card listed", ["card", "ideal"], true]
	 *           ["card not listed", ["ideal"], false]
	 *           ["empty list", [], false]
	 *           ["stored empty string", "", true]
	 *
	 * @param string               $scenario        Scenario name.
	 * @param string[]|string|null $enabled_methods Stored enabled payment method IDs, '' as stored by an empty selection, or null for none stored.
	 * @param bool                 $expected        Whether the card gateway should be offered.
	 */
	public function test_card_gateway_availability_requires_card_among_the_enabled_methods( string $scenario, $enabled_methods, bool $expected ): void {
		unset( $scenario );
		$this->activate_builtin_tier();
		$canonical_settings = null === $enabled_methods ? array() : array( 'upe_enabled_payment_method_ids' => $enabled_methods );
		$account_service    = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		// Account fields as recorded in Providers/WooPayments/Fixtures/platform-test-drive-account.json `account` (`country`, capability statuses keyed by
		// capability); varied: only the card capability this test needs.
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $canonical_settings ) ? $canonical_settings[ $key ] : $fallback
		);
		$currency_filter = static fn(): string => 'USD';
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );

		try {
			$this->with_gateway_settings(
				array( 'enabled' => 'yes' ),
				function () use ( $account_service, $expected ): void {
					$gateway = new WooPaymentsGateway();
					$gateway->init( new RecordingPaymentProcessingService(), $this->create_processing_ready_provider(), null, null, $account_service );

					$this->assertSame( $expected, $gateway->is_available() );
				}
			);
		} finally {
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
		}
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
			$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
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
				$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
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
			$gateway = new WooPaymentsGateway( $definition );
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
		$this->activate_builtin_tier();
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
			$affirm = new WooPaymentsGateway( $affirm_definition );
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
			$afterpay = new WooPaymentsGateway( $afterpay_definition );
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
	 * @testdox Should show the checkout test-mode badge only in test mode: $_dataName.
	 *
	 * @dataProvider provider_test_mode_badge
	 *
	 * @param string $test_mode Gateway `test_mode` setting.
	 * @param bool   $shown     Whether the badge shows.
	 */
	public function test_gateway_icon_shows_test_mode_badge_only_in_test_mode( string $test_mode, bool $shown ): void {
		$this->with_gateway_settings(
			array( 'test_mode' => $test_mode ),
			function () use ( $shown ): void {
				$icon = ( new WooPaymentsGateway() )->get_icon();

				$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $icon );
				$this->assertSame( $shown, str_contains( $icon, 'test-mode badge' ) );
				$this->assertSame( $shown, str_contains( $icon, 'Test Mode' ) );
			}
		);
	}

	/**
	 * Test mode settings and whether the badge shows.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provider_test_mode_badge(): array {
		return array(
			'test mode on'  => array( 'yes', true ),
			'test mode off' => array( 'no', false ),
		);
	}

	/**
	 * @testdox Should show Cartes Bancaires in the checkout gateway icon for a store based in France, whatever the account country.
	 *
	 * Client 11.1.0 decides the brand from the store base country it sends as storeCountry (client/utils/card-brands.ts:43-50,
	 * includes/class-wc-payments-checkout.php:270).
	 *
	 * @testWith ["FR", "US", "+ 4"]
	 *           ["US", "FR", "+ 3"]
	 *
	 * @param string $store_country   Store base country.
	 * @param string $account_country Account country.
	 * @param string $more_count      The count of brands beyond the three shown.
	 */
	public function test_gateway_icon_shows_cartes_bancaires_for_stores_based_in_france( string $store_country, string $account_country, string $more_count ): void {
		update_option( 'woocommerce_default_country', $store_country );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => $account_country ) );

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, null, $account_service );

		$icon = $gateway->get_icon();

		$this->assertStringContainsString( '<span class="payment-methods--logos-count">' . $more_count . '</span>', $icon );
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
					$gateway = new class() extends WooPaymentsGateway {
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
	 * @testdox Split gateways advertise subscription support.
	 */
	public function test_split_gateway_subscription_support_is_independent_from_hook_ownership(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'bancontact' );
		$this->assertNotNull( $definition );

		$gateway = new class( $definition ) extends WooPaymentsGateway {
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
	}

	/**
	 * @testdox Should force only a non-reusable method's subscription to manual renewal, keeping its method: $_dataName.
	 *
	 * Mirrors client 11.1.0 `tr:1399-1401` (`maybe_force_subscription_to_manual`): a reusable gateway (card, Amazon Pay)
	 * returns before touching `requires_manual_renewal` or the original-method meta.
	 *
	 * @dataProvider provider_subscription_renewal_mode_by_method
	 *
	 * @param string $gateway_id    Gateway ID of the subscription's payment method.
	 * @param bool   $manual        Whether the subscription is forced to manual renewal.
	 * @param string $original_meta Expected `_wcpay_original_payment_method_id` meta.
	 */
	public function test_maybe_force_subscription_to_manual_follows_method_reusability( string $gateway_id, bool $manual, string $original_meta ): void {
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
		$subscription->set_payment_method( $gateway_id );
		$subscription->save();

		( new WooPaymentsGateway() )->maybe_force_subscription_to_manual( $subscription );

		$this->assertSame( $manual, $subscription->is_manual() );
		$this->assertSame( $gateway_id, $subscription->get_payment_method() );
		$this->assertSame( $original_meta, $subscription->get_meta( '_wcpay_original_payment_method_id', true ) );
	}

	/**
	 * Subscription payment methods and the renewal mode they leave.
	 *
	 * @return array<string,array{0:string,1:bool,2:string}>
	 */
	public function provider_subscription_renewal_mode_by_method(): array {
		$bancontact = WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'bancontact';

		return array(
			'non-reusable Bancontact' => array( $bancontact, true, $bancontact ),
			'reusable card'           => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, false, '' ),
		);
	}

	/**
	 * @testdox Should append the successful renewal token to a failing subscription so it becomes active: $_dataName.
	 *
	 * @dataProvider provider_failing_payment_method_token_updates
	 *
	 * @param string[] $subscription_tokens Payment method IDs of the subscription's tokens, in order.
	 * @param string   $renewal_token       Payment method ID of the renewal's token.
	 * @param string[] $expected            Payment method IDs of the subscription's tokens afterwards, in order.
	 */
	public function test_update_failing_payment_method_appends_the_renewal_token( array $subscription_tokens, string $renewal_token, array $expected ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_order();
		$renewal      = $this->create_order();
		$tokens       = array();
		foreach ( array_unique( array_merge( $subscription_tokens, array( $renewal_token ) ) ) as $payment_method_id ) {
			$tokens[ $payment_method_id ] = $this->create_card_token( $user_id, $payment_method_id );
		}

		$subscription->set_customer_id( $user_id );
		foreach ( $subscription_tokens as $payment_method_id ) {
			$subscription->add_payment_token( $tokens[ $payment_method_id ] );
		}
		$subscription->save();
		$renewal->set_customer_id( $user_id );
		$renewal->add_payment_token( $tokens[ $renewal_token ] );
		$renewal->save();

		$gateway = new WooPaymentsGateway();
		$gateway->update_failing_payment_method( wc_get_order( $subscription->get_id() ), wc_get_order( $renewal->get_id() ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame(
			array_map( static fn( string $payment_method_id ): int => $tokens[ $payment_method_id ]->get_id(), $expected ),
			array_values( array_map( 'absint', $subscription->get_payment_tokens() ) )
		);
	}

	/**
	 * Subscription and renewal tokens, and the subscription's tokens after the update.
	 *
	 * @return array<string,array{0:string[],1:string,2:string[]}>
	 */
	public function provider_failing_payment_method_token_updates(): array {
		return array(
			'new renewal token'             => array( array(), 'pm_recovered', array( 'pm_recovered' ) ),
			'previously used renewal token' => array( array( 'pm_a', 'pm_b' ), 'pm_a', array( 'pm_a', 'pm_b', 'pm_a' ) ),
		);
	}

	/**
	 * @testdox Should save setup-intent payment methods from the account add-payment-method form.
	 *
	 * The SetupIntent is a recorded succeeded 3DS challenge (`Fixtures/platform-3ds-challenge-results.json`, pair
	 * `my_account_setup_intent_challenge_completed`): a My Account add-payment-method SetupIntent that succeeded after a
	 * hosted 3DS challenge.
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

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, (string) $recorded['body']['customer'] ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should save a non-card (SEPA) setup-intent payment method from the account add-payment-method form.
	 *
	 * The SetupIntent carries the fields add_payment_method() reads, as client 11.1.0 maps the response
	 * (`includes/wc-payment-api/class-wc-payments-api-client.php:2457-2469`). The client saves whatever token its token
	 * service makes (`class-wc-payment-gateway-wcpay.php:4453-4454`), so a SEPA token is accepted like a card token.
	 */
	public function test_add_payment_method_saves_a_sepa_setup_intent_token(): void {
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
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit' );
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

		$gateway = new WooPaymentsGateway( $definition );
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( wc_get_endpoint_url( 'payment-methods' ), $result['redirect'] );
	}

	/**
	 * @testdox Should refuse to save a payment method from a non-succeeded SetupIntent.
	 *
	 * The SetupIntent is a recorded failed 3DS challenge (`Fixtures/platform-3ds-challenge-results.json`, pair
	 * `my_account_setup_intent_challenge_failed`): a My
	 * Account add-payment-method SetupIntent left `requires_payment_method` after a failed 3DS
	 * challenge. `add_payment_method()` returns its error before ever calling the token service
	 * (`WooPaymentsGateway.php:645-647`), so a hosted challenge failure must never attach a
	 * payment method to the shopper's account. The notice is the client's sentence (client 11.1.0 `gw:4446-4451`), ended
	 * with a period.
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

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, (string) $recorded['body']['customer'] ) );

		$result = $gateway->add_payment_method();

		$this->assertSame( array( 'result' => 'error' ), $result );
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertSame(
			'Failed to add the provided payment method. Please try again later.',
			wc_get_notices( 'error' )[0]['notice'] ?? ''
		);
	}

	/**
	 * Load one recorded manual 3DS entry's response body by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{body:array<string,mixed>}
	 */
	private function load_recorded_manual_3ds_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/platform-3ds-challenge-results.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array( 'body' => $entry['response']['body'] );
			}
		}

		$this->fail( "The manual 3DS fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Should refuse to save a setup intent without a customer to bind it to, or of another customer: $_dataName.
	 *
	 * The client refuses a user with no stored WooPayments customer before it reads the intent, with its "not able"
	 * copy (client 11.1.0 `class-wc-payment-gateway-wcpay.php:4434-4439`). The gateway also refuses an intent without a
	 * customer, or one of another customer, with the non-succeeded copy (`:4448`); the client checks only that the user
	 * has a customer (`:4434-4454`). No row creates a token.
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

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, $user_customer ) );

		$this->assertSame( array( 'result' => 'error' ), $gateway->add_payment_method() );
		$this->assertSame( $expected, wc_get_notices( 'error' )[0]['notice'] ?? '' );
	}

	/**
	 * Customer pairs add_payment_method() refuses to bind the intent to.
	 *
	 * @return array<string,array{0:?string,1:?string,2:bool,3:string}>
	 */
	public function setup_intent_without_customer_provider(): array {
		return array(
			'user without a WooPayments customer' => array( null, 'cus_victim', false, "We're not able to add this payment method. Please try again later." ),
			'intent without a customer'           => array( 'cus_me', null, true, 'Failed to add the provided payment method. Please try again later.' ),
			'intent of another customer'          => array( 'cus_me', 'cus_other', true, 'Failed to add the provided payment method. Please try again later.' ),
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
			->onlyMethods( array( 'migrate_and_get_customer_id' ) )
			->getMock();
		$customer_service->method( 'migrate_and_get_customer_id' )->with( $user_id )->willReturn( $customer_id );

		return $customer_service;
	}

	/**
	 * @testdox Should show the WooPayments extension's filtered message when the setup intent lookup fails with an API error: $_dataName.
	 *
	 * Client 11.1.0 `class-wc-payment-gateway-wcpay.php:4469-4470` passes every exception through
	 * `WC_Payments_Utils::get_filtered_error_message()` (`class-wc-payments-utils.php:769-819`) and logs it at info
	 * level (`:4471`).
	 *
	 * @dataProvider add_payment_method_api_error_provider
	 *
	 * @param WooPaymentsApiException $exception Exception thrown by the setup intent lookup.
	 * @param string                  $expected  Expected shopper notice.
	 */
	public function test_add_payment_method_filters_api_errors_like_the_woopayments_extension( WooPaymentsApiException $exception, string $expected ): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_POST['wcpay-setup-intent'] = 'seti_native';

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$api_client->method( 'get_setup_intention' )->willThrowException( $exception );

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, null, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$this->assertSame( array( 'result' => 'error' ), $gateway->add_payment_method() );
		$this->assertSame( $expected, wc_get_notices( 'error' )[0]['notice'] ?? '' );
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
		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, null, $this->create_customer_service_for_user( $user_id, 'cus_me' ) );

		$gateway->add_payment_method();

		$info_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'info' === $line[0] && 'woopayments' === $line[2] ) );
		$this->assertCount( 1, $info_lines, 'One info line under woopayments. Recorded: ' . (string) wp_json_encode( $logger->lines ) );
		$context = $logger->contexts[ $info_lines[0] ];
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
	 * @testdox The My Account add-payment-method form ends the refusal notice with a period: $_dataName.
	 *
	 * The form posts through WC_Form_Handler::add_payment_method_action(), which calls the gateway's add_payment_method().
	 * Client 11.1.0 shows these three sentences without a final period (class-wc-payment-gateway-wcpay.php:4423, :4437,
	 * :4448); WooCommerce ends them with one, as its checkout setup-intent path already does for the same sentence.
	 *
	 * @dataProvider add_payment_method_refusal_provider
	 *
	 * @param string $refusal  Which check refuses the request.
	 * @param string $expected Expected shopper notice.
	 */
	public function test_add_payment_method_form_ends_each_refusal_notice_with_a_period( string $refusal, string $expected ): void {
		wc_clear_notices();
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user_id );
		if ( 'no_setup_intent' !== $refusal ) {
			$_POST['wcpay-setup-intent'] = 'seti_form';
		}

		$api_client    = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_setup_intention' ) )
			->getMock();
		$intent_lookup = $api_client->method( 'get_setup_intention' );
		if ( 'lookup_throws' === $refusal ) {
			$intent_lookup->willThrowException( new \RuntimeException( 'Unexpected failure.' ) );
		} else {
			// The subset of the SetupIntent response that add_payment_method() reads, as client 11.1.0 maps it
			// (includes/wc-payment-api/class-wc-payments-api-client.php:2457-2469).
			$intent_lookup->willReturn(
				array_filter(
					array(
						'id'             => 'seti_form',
						'status'         => 'not_succeeded' === $refusal ? 'requires_payment_method' : 'succeeded',
						'customer'       => 'other_customer' === $refusal ? 'cus_other' : 'cus_me',
						'payment_method' => 'no_payment_method' === $refusal ? null : 'pm_form',
					)
				)
			);
		}

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willReturn( null );

		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), null, $api_client, null, $token_service, $this->create_customer_service_for_user( $user_id, 'no_customer' === $refusal ? null : 'cus_me' ) );

		$this->submit_add_payment_method_form( $gateway );

		$this->assertSame( array( $expected ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * One row per check in add_payment_method() that refuses with one of the three sentences.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function add_payment_method_refusal_provider(): array {
		$retry  = "We're not able to add this payment method. Please try again later.";
		$failed = 'Failed to add the provided payment method. Please try again later.';

		return array(
			'no setup intent posted'                    => array( 'no_setup_intent', 'A WooPayments payment method was not provided.' ),
			'user without a WooPayments customer'       => array( 'no_customer', $retry ),
			'setup intent not succeeded'                => array( 'not_succeeded', $failed ),
			'setup intent of another customer'          => array( 'other_customer', $failed ),
			'succeeded intent without a payment method' => array( 'no_payment_method', $retry ),
			'token cannot be created'                   => array( 'no_token', $retry ),
			'setup intent lookup throws'                => array( 'lookup_throws', $retry ),
		);
	}

	/**
	 * Submit the My Account add-payment-method form for the WooPayments gateway, as WooCommerce handles it.
	 *
	 * @param WooPaymentsGateway $gateway Gateway the form handler finds for its ID.
	 */
	private function submit_add_payment_method_form( WooPaymentsGateway $gateway ): void {
		$offer_gateway = static function ( $gateways ) use ( $gateway ) {
			$gateways[ $gateway->id ] = $gateway;

			return $gateways;
		};

		// The rate limiter is keyed per user and would refuse a second submission.
		\WC_Rate_Limiter::set_rate_limit( 'add_payment_method_' . get_current_user_id(), -1 );
		$nonce                                   = wp_create_nonce( 'woocommerce-add-payment-method' );
		$_POST['woocommerce_add_payment_method'] = '1';
		$_POST['payment_method']                 = $gateway->id;
		$_POST['_wpnonce']                       = $nonce;
		$_REQUEST['_wpnonce']                    = $nonce;
		add_filter( 'woocommerce_available_payment_gateways', $offer_gateway );
		$buffer_level = ob_get_level();

		try {
			\WC_Form_Handler::add_payment_method_action();
		} finally {
			// add_payment_method_action() leaves its output buffer open when no redirect follows.
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			remove_filter( 'woocommerce_available_payment_gateways', $offer_gateway );
			unset( $_POST['woocommerce_add_payment_method'], $_POST['payment_method'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * @testdox Should reject add-payment-method requests with invalid fraud-prevention tokens before reading the setup intent.
	 *
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:4578-4586` refuses without touching
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

		$gateway = new WooPaymentsGateway();
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
			new WooPaymentsGateway();
		} finally {
			remove_filter( 'gettext', $filter, 10 );

			if ( $had_init_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate pre-init construction for the WP 6.7 textdomain guard.
				$wp_actions['init'] = $previous_init_count;
			} else {
				unset( $wp_actions['init'] );
			}
		}

		$this->assertSame( array(), $translated, 'Gateway construction must not call translation APIs before init.' );
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
			$gateway = new WooPaymentsGateway();

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
	 * @testdox Should record a failed order when checkout reports a payment method creation error.
	 */
	public function test_process_payment_fails_order_for_payment_method_creation_error(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
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
		// The message is Stripe.js text the browser posted; only the refusal's code is logged.
		$this->assertStringNotContainsString( 'Your card number is invalid', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox Should keep an order whose intent already succeeded when checkout reports a payment method creation error.
	 *
	 * WooPayments 11.1.0 throws the client error inside process_payment()'s try (`gw:1664-1666`), so the catch's
	 * succeeded-intent check (`gw:1283-1304`) runs before the failed status (`gw:1326-1327`) and the failure note.
	 * The order is still pending here: the earlier duplicate checks found no paid status and no attached PaymentIntent.
	 */
	public function test_process_payment_method_creation_error_on_succeeded_intent_keeps_order(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $order );

		$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
		$_POST['wcpay-payment-method-error-message'] = 'Your card number is invalid.';

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertNull( $service->last_checkout_context, 'A refused checkout must not reach the provider.' );
		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Your card number is invalid.', 'payment_method_error', $logger, 'pending' );
	}

	/**
	 * @testdox Should not flip the order status for a payment method creation error on a subscription payment-method change.
	 */
	public function test_process_payment_method_creation_error_keeps_status_on_subscription_change(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		WooCommerceSubscriptionsDoubles::load_subscription_detector();
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
	 * @testdox Should fail the payment and the order when the payment method creation error carries no message.
	 */
	public function test_process_payment_method_creation_error_without_a_message_fails_the_order(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'woocommerce_payments_payment_method_error';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should process payments through the payment processing service.
	 */
	public function test_process_payment_delegates_to_processing_service(): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_mock' );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['is_platform_payment_method'] );
		$this->assertSame( 'pi_mock', WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox A saved card the WooPayments extension wrote reaches checkout unchanged and accepts the recorded successful outcome.
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

		// WooPayments 11.1.0 writes this saved-card/customer shape; reusing it is recorded as a succeeded, captured USD 10.99 payment.
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_plugin_history_use', '', 'pm_plugin_history', 'cus_plugin_history' );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();
		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( (string) $token->get_id(), $service->last_checkout_context->get_payment_data()['payment_token'] );
		$this->assertSame( 'pi_plugin_history_use', WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox A renewal the shopper pays at checkout (early renewal) is charged as that renewal order, without scheduled-renewal semantics.
	 *
	 * WooCommerce Subscriptions marks the renewal order with `_subscription_renewal`. Only the subscriptions controller's
	 * scheduled payment sets `scheduled_subscription_payment`, which makes the request builder charge off-session with
	 * automatic capture; a shopper-present renewal through process_payment() must not.
	 */
	public function test_process_payment_charges_a_customer_present_renewal_without_scheduled_payment_semantics(): void {
		$user_id      = self::factory()->user->create( array( 'role' => 'customer' ) );
		$token        = $this->create_card_token( $user_id, 'pm_early_renewal' );
		$subscription = $this->create_order();
		$renewal      = $this->create_order();
		$renewal->set_customer_id( $user_id );
		// The relation WooCommerce Subscriptions stores on a renewal order: `_subscription_renewal` holding the subscription
		// ID (subscriptions-core 6.7.1, as WooPayments 11.1.0 bundles it: wcs-renewal-functions.php:36,
		// data-stores/class-wcs-related-order-store-cpt.php:28-30, :92-104).
		$renewal->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		$renewal->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();
		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );

		$gateway->process_payment( $renewal->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $renewal->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertArrayNotHasKey( 'scheduled_subscription_payment', $service->last_checkout_context->get_provider_data() );
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
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		WC()->session->set( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY, null );
		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertNull( WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
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
		$gateway                   = new WooPaymentsGateway();
		$original_session          = WC()->session;
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_mock' );
		$gateway->init( $service, new WooPaymentsProvider() );
		WC()->session = null;

		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( 'success', $result['result'] );
	}

	/**
	 * Client 11.1.0 asks the filter only when a session exists: `WC()->session && ! apply_filters( ... )`
	 * (includes/class-wc-payment-gateway-wcpay.php:1219). Fails when the filter runs before the session check.
	 *
	 * @testdox Should not ask wcpay_is_woopay_store_api_request on a checkout without a WooCommerce session.
	 */
	public function test_process_payment_without_a_session_does_not_ask_the_woopay_store_api_filter(): void {
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$gateway                   = new WooPaymentsGateway();
		$original_session          = WC()->session;
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_mock' );
		$gateway->init( $service, new WooPaymentsProvider() );
		$filter_calls = 0;
		add_filter(
			'wcpay_is_woopay_store_api_request',
			static function ( $value ) use ( &$filter_calls ) {
				++$filter_calls;

				return $value;
			}
		);
		WC()->session = null;

		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 0, $filter_calls );
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

		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		if ( $is_order_pay ) {
			$_POST['woocommerce_pay'] = '1';
		}
		if ( $should_save ) {
			$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-new-payment-method' ] = 'true';
		}

		$filtered_payment_url = 'https://example.test/filtered-order-pay/?pay_for_order=true&key=wc_order_test&extension=kept#extension-anchor';
		$payment_url_filter   = static function ( string $payment_url, WC_Order $filtered_order ) use ( $order, $filtered_payment_url ): string {
			return $filtered_order->get_id() === $order->get_id() ? $filtered_payment_url : $payment_url;
		};
		add_filter( 'woocommerce_get_checkout_payment_url', $payment_url_filter, 10, 2 );

		$_POST['wcpay-payment-method'] = 'pm_card';
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
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1219-1227` reads one shared
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

		$gateway = new WooPaymentsGateway( $definition );
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
	 * @testdox Should refuse a billing phone over 20 characters before any other check, as the WooPayments extension does.
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

		$gateway = new WooPaymentsGateway();
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
	 * @testdox Should accept a billing phone of exactly 20 characters, as the WooPayments extension does.
	 */
	public function test_process_payment_accepts_a_billing_phone_of_20_characters(): void {
		wc_clear_notices();
		$order = $this->create_order();
		$order->set_billing_phone( str_repeat( '1', 20 ) );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( array(), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * @testdox A $total checkout with no payment method and no saved token is refused before any payment runs ($scenario).
	 *
	 * Client 11.1.0 builds its payment information right after the already-paid check (`class-wc-payment-gateway-wcpay.php:1270`,
	 * `:1451`), and the constructor throws `payment_method_not_provided` when the request has neither a payment method nor a
	 * token (`includes/class-payment-information.php:157-162`). The catch fails the order without a payment note, because no
	 * payment information was built (`gw:1326-1328`, `:1354`), and shows the exception's message (`gw:1425`). A $0 order that
	 * needs payment, such as a WooCommerce Subscriptions free trial, must not complete without a payment method, and a step that
	 * throws while completing it must not leave a succeeded intent status behind.
	 *
	 * @testWith ["0.00", "nothing throws", false]
	 *           ["0.00", "a completion hook throws", true]
	 *           ["12.00", "nothing throws", false]
	 *
	 * @param string $total             Order total.
	 * @param string $scenario          Scenario label.
	 * @param bool   $completion_throws Whether a woocommerce_payment_complete callback fails with an Error.
	 */
	public function test_process_payment_refuses_a_checkout_without_a_payment_method_or_token( string $total, string $scenario, bool $completion_throws ): void {
		unset( $scenario );
		wc_clear_notices();
		$logger = $this->capture_logs();
		$order  = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( $total );
		$order->save();
		if ( $completion_throws ) {
			add_action(
				'woocommerce_payment_complete',
				static function (): void {
					throw new \Error( 'A completion hook failed.' );
				}
			);
		}

		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), wc_get_container()->get( WooPaymentsProvider::class ) );

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( array( 'Invalid or missing payment details. Please ensure the provided payment method is correctly entered.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'No intent exists, so no intent status is written.' );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), preg_grep( '/downstream error|<strong>failed<\/strong> to complete/', $notes ), 'Neither a downstream-error note nor a payment-failed note is added.' );
		$this->assertSame( array(), preg_grep( '/reconcilable outcome/', array_column( $logger->entries, 'message' ) ), 'No provider operation is logged.' );
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
	 * @testdox Should admit a fraud-prevention token that matches the session, without regenerating it: $_dataName.
	 *
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1219-1227`: `process_payment`
	 * reads one shared `Fraud_Prevention_Service::get_instance()`, not a per-gateway one, so a matching
	 * token admits the checkout identically whichever gateway ID is processing, and
	 * `fraud-prevention/class-fraud-prevention-service.php:168-177` admits it without regenerating it.
	 *
	 * @dataProvider split_redirect_gateway_provider
	 *
	 * @param string|null $payment_method_id Split payment method ID, or null for the card gateway.
	 * @param string      $gateway_id        Expected gateway ID of the checkout.
	 */
	public function test_process_payment_admits_valid_fraud_token_on_split_redirect_gateways( ?string $payment_method_id, string $gateway_id ): void {
		$definition = null === $payment_method_id ? null : ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );
		$this->assertSame( null === $payment_method_id, null === $definition );

		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, '0123456789abcdef' );
		$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = '0123456789abcdef';

		$gateway = new WooPaymentsGateway( $definition );
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

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $gateway_id, $service->last_checkout_context->get_gateway_id() );
		$this->assertSame( '0123456789abcdef', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * Gateways the matching fraud-prevention token must be admitted on: card and the split redirect gateways.
	 *
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public function split_redirect_gateway_provider(): array {
		return array(
			'card'              => array( null, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			'Affirm'            => array( 'affirm', WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'affirm' ),
			'Cash App Afterpay' => array( 'afterpay_clearpay', WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'afterpay_clearpay' ),
			'Bancontact'        => array( 'bancontact', WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'bancontact' ),
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

		$gateway = new WooPaymentsGateway();
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

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( 'valid-token', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * @testdox Should retry a failed intent the WooPayments extension left on order pay.
	 *
	 * WooPayments 11.1.0 commit f85392666c9b543cd24dbbf903e0dbe4cb2c5cee,
	 * WC_Payment_Gateway_WCPay::process_payment(), and
	 * Duplicate_Payment_Prevention_Service::check_payment_intent_attached_to_order_succeeded().
	 *
	 * @dataProvider extension_failed_intent_order_pay_cases
	 *
	 * @param bool   $card_testing_protection_enabled Whether card-testing protection is enabled.
	 * @param string $fraud_prevention_token          Posted fraud-prevention token.
	 */
	public function test_process_payment_retries_an_extension_failed_intent_on_order_pay( bool $card_testing_protection_enabled, string $fraud_prevention_token ): void {
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
		$gateway = new WooPaymentsGateway();
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
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $service->last_checkout_context->get_gateway_id() );
		$this->assertSame( '0123456789abcdef', $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * Recovery cases for a failed intent the WooPayments extension left, on order pay.
	 *
	 * @return array<string,array{bool,string}>
	 */
	public static function extension_failed_intent_order_pay_cases(): array {
		return array(
			'card-testing protection disabled' => array( false, 'tampered-token' ),
			'card-testing protection enabled'  => array( true, '0123456789abcdef' ),
		);
	}

	/**
	 * @testdox Should reject checkout before creating a payment context when the failed-transaction limiter is active.
	 *
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1229-1234` throws
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

		$gateway = new WooPaymentsGateway();
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
	 * @testdox Should suffix the rate-limiter note amount with the order currency only while Multi-Currency is on: $_dataName.
	 *
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1418` passes the total through
	 * `WC_Payments_Explicit_Price_Formatter::get_explicit_price()`, which appends ` <currency code>`
	 * when customer multi-currency is on and more than one currency is enabled
	 * (`class-wc-payments-explicit-price-formatter.php:106-142`, `:167-190`), and returns the bare price while
	 * Multi-Currency is off, even with enabled currencies left in the option (`:167-172`). The gateway reads core Multi-Currency.
	 *
	 * @dataProvider provider_rate_limiter_note_currency_suffix
	 *
	 * @param bool   $multi_currency Whether core Multi-Currency owns the runtime.
	 * @param string $suffix         Expected text after the note amount.
	 */
	public function test_process_payment_rate_limiter_note_suffixes_the_currency_only_with_multi_currency( bool $multi_currency, string $suffix ): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->set_core_multi_currency( $multi_currency );
		$order   = $this->create_order();
		$session = $this->create_session();
		$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );

		$gateway = new WooPaymentsGateway();
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
			'A payment of ' . wc_price( 12.00, array( 'currency' => 'USD' ) ) . $suffix . ' <strong>failed</strong> to complete because of too many failed transactions. A rate limiter was enabled for the user to prevent more attempts temporarily.',
			array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) )
		);
	}

	/**
	 * Multi-Currency states and the text the rate-limiter note puts after the amount.
	 *
	 * @return array<string,array{0:bool,1:string}>
	 */
	public function provider_rate_limiter_note_currency_suffix(): array {
		return array(
			'Multi-Currency on'  => array( true, ' USD' ),
			'Multi-Currency off' => array( false, '' ),
		);
	}

	/**
	 * @testdox Should keep a paid order and return success when checkout is refused after the intent succeeded: $_dataName.
	 *
	 * WooPayments 11.1.0 throws these refusals inside process_payment()'s try
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
		$gateway = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4531`
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
		$gateway                     = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context, 'The payment must have run before the downstream failure.' );
		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Auth credentials missing', 'Exception', $logger, 'pending' );
	}

	/**
	 * @testdox Should keep a subscription and return success when its payment-method change throws after the intent succeeded.
	 *
	 * WooPayments 11.1.0 runs a subscription payment-method change through the same
	 * process_payment() try (`class-wc-payment-gateway-wcpay.php:1201-1282`, flagged at `:1637`), so the
	 * succeeded-intent catch (`:1283-1304`) covers it too; only the failed-status branch below it
	 * excludes the change (`:1326`).
	 */
	public function test_process_payment_downstream_exception_on_succeeded_intent_keeps_subscription_payment_method_change(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$subscription = $this->create_order();
		$subscription->update_meta_data( '_intention_status', 'succeeded' );
		$subscription->save();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );
		$logger     = $this->capture_logs();
		$return_url = $this->filter_return_url( $subscription );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $subscription->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $subscription->get_id();

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \RuntimeException( 'Subscription hook failed' );
		$gateway                     = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $subscription->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'The request must run as a validated subscription payment-method change.' );
		$this->assert_succeeded_intent_defense( $subscription->get_id(), $result, $return_url, $note_count, 'Subscription hook failed', 'RuntimeException', $logger, 'pending' );
	}

	/**
	 * @testdox A recurring checkout whose card cannot be saved after the payment succeeded logs the failure and completes the order without a token.
	 *
	 * Client 11.1.0 catches the token-save failure on the synchronous checkout, logs it and completes the order
	 * (class-wc-payment-gateway-wcpay.php:2036-2067); only the order-status callback refuses (gw:4308-4321).
	 */
	public function test_process_payment_completes_recurring_order_when_token_save_fails_after_succeeded_intent(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$user_id = $this->factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->save();
		// The recorded subscription sign-up PaymentIntent (Fixtures/platform-intention-subscription.json, pair signup_initial_setup_future_usage).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-intention-subscription.json' ), true );
		$intent    = array_values( array_filter( $recording['entries'], static fn( array $entry ) => 'signup_initial_setup_future_usage' === $entry['pair'] ) )[0]['response']['body'];
		$outcome   = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			new WooPaymentsIntentMappingContext( $order->get_checkout_order_received_url(), (string) $intent['payment_method'], (string) $intent['customer'] )
		)->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $intent, true ) );

		$token_service = new class() extends WooPaymentsTokenService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail the token save, as a failed platform payment-method fetch does.
			 *
			 * @param string $payment_method_id Provider payment method ID.
			 * @param int    $user_id           User ID.
			 * @return WC_Payment_Token|null
			 * @throws \RuntimeException Always.
			 */
			public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token {
				unset( $payment_method_id, $user_id );
				throw new \RuntimeException( 'Payment method fetch failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$provider = $this->create_provider_charging( $outcome, $token_service );
		$gateway  = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = (string) $intent['payment_method'];
		$logger                        = RecordingWcLogger::install();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] ?? '' );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not see an error notice.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid(), 'The paid order must complete.' );
		$this->assertSame( (string) $intent['id'], $order->get_transaction_id() );
		$this->assertSame( array(), $order->get_payment_tokens(), 'No token is attached when it could not be saved.' );
		$this->assertContains( 'Error when saving payment method.', array_column( $logger->lines, 1 ), 'The token-save failure is logged.' );
	}

	/**
	 * @testdox A free-trial checkout whose card cannot be saved after its SetupIntent succeeded completes the order without a token.
	 *
	 * Client 11.1.0 catches the token-save failure for any authorized intent on the synchronous checkout and completes the
	 * order (class-wc-payment-gateway-wcpay.php:2036-2067); nothing is charged on a $0 order.
	 */
	public function test_process_payment_completes_free_trial_order_when_token_save_fails_after_succeeded_setup_intent(): void {
		$user_id = $this->factory()->user->create();
		$order   = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '0.00' );
		$order->save();
		// The recorded succeeded SetupIntent (Fixtures/platform-3ds-challenge-results.json, pair my_account_setup_intent_challenge_completed).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-3ds-challenge-results.json' ), true );
		$intent    = array_values( array_filter( $recording['entries'], static fn( array $entry ) => 'my_account_setup_intent_challenge_completed' === $entry['pair'] ) )[0]['response']['body'];
		$outcome   = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			new WooPaymentsIntentMappingContext( $order->get_checkout_order_received_url(), (string) $intent['payment_method'], (string) $intent['customer'], '', 'si' )
		)->with_effect_plan(
			WooPaymentsOrderEffectPlan::for_setup_intent(
				$intent,
				true,
				array(
					'_wcpay_intent_currency' => 'USD',
					'_wcpay_mode'            => 'test',
				)
			)
		);

		// A failed platform payment-method fetch leaves no details and no token (WooPaymentsTokenService::get_bound_payment_method_details()).
		$token_service = new class() extends WooPaymentsTokenService {
			/**
			 * Answer as the token service does when the platform payment-method fetch fails.
			 *
			 * @param string $payment_method_id              Provider payment method ID.
			 * @param int    $user_id                         User ID.
			 * @param bool   $include_existing_token_details Whether to retrieve details for an existing token.
			 * @return array{token:WC_Payment_Token|null,payment_method_details:array<string,mixed>}
			 */
			public function get_or_create_token_and_details_for_user( string $payment_method_id, int $user_id, bool $include_existing_token_details = false ): array {
				unset( $payment_method_id, $user_id, $include_existing_token_details );

				return array(
					'token'                  => null,
					'payment_method_details' => array(),
				);
			}
		};

		$provider = $this->create_provider_charging( $outcome, $token_service );
		$gateway  = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = (string) $intent['payment_method'];

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not see an error notice.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid(), 'The $0 order must complete.' );
		$this->assertSame( (string) $intent['id'], $order->get_transaction_id() );
		$this->assertSame( array(), $order->get_payment_tokens(), 'No token is attached when it could not be saved.' );
	}

	/**
	 * @testdox A $0 subscription payment-method change whose new card cannot be saved after its SetupIntent succeeded is refused and keeps the subscription's payment method and status.
	 *
	 * Nothing is charged on a change, so it is refused as the change confirmed after 3D Secure is (client 11.1.0
	 * class-wc-payment-gateway-wcpay.php:4308-4321). The extension's synchronous change instead completes without the token
	 * (gw:2036-2067), and the subscription's renewals keep charging the card the shopper meant to replace.
	 */
	public function test_process_payment_refuses_subscription_change_when_new_card_cannot_be_saved(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$user_id      = $this->factory()->user->create();
		$subscription = $this->create_order();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		// WooCommerce Subscriptions zeroes the subscription's total on the change request.
		$subscription->set_total( '0.00' );
		$subscription->set_status( 'on-hold' );
		$subscription->update_meta_data( '_payment_method_id', 'pm_old' );
		$subscription->save();
		// The recorded succeeded SetupIntent (Fixtures/platform-3ds-challenge-results.json, pair my_account_setup_intent_challenge_completed).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-3ds-challenge-results.json' ), true );
		$intent    = array_values( array_filter( $recording['entries'], static fn( array $entry ) => 'my_account_setup_intent_challenge_completed' === $entry['pair'] ) )[0]['response']['body'];
		$outcome   = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			new WooPaymentsIntentMappingContext( $subscription->get_checkout_order_received_url(), (string) $intent['payment_method'], (string) $intent['customer'], '', 'si' )
		)->with_effect_plan(
			WooPaymentsOrderEffectPlan::for_setup_intent(
				$intent,
				true,
				array(
					'_wcpay_intent_currency' => 'USD',
					'_wcpay_mode'            => 'test',
				)
			)
		);

		// A failed platform payment-method fetch leaves no details and no token (WooPaymentsTokenService::get_bound_payment_method_details()).
		$token_service = new class() extends WooPaymentsTokenService {
			/**
			 * Answer as the token service does when the platform payment-method fetch fails.
			 *
			 * @param string $payment_method_id              Provider payment method ID.
			 * @param int    $user_id                         User ID.
			 * @param bool   $include_existing_token_details Whether to retrieve details for an existing token.
			 * @return array{token:WC_Payment_Token|null,payment_method_details:array<string,mixed>}
			 */
			public function get_or_create_token_and_details_for_user( string $payment_method_id, int $user_id, bool $include_existing_token_details = false ): array {
				unset( $payment_method_id, $user_id, $include_existing_token_details );

				return array(
					'token'                  => null,
					'payment_method_details' => array(),
				);
			}
		};

		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_charging( $outcome, $token_service ) );
		$GLOBALS['wcpay_test_subscription_ids'] = array( $subscription->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $subscription->get_id();
		$_POST['wcpay-payment-method']          = (string) $intent['payment_method'];

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$result = $gateway->process_payment( $subscription->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( array( 'Unable to save payment method for subscription. Please try again or use a different payment method.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 'on-hold', $subscription->get_status() );
		$this->assertSame( 'pm_old', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $subscription->get_transaction_id() );
	}

	/**
	 * @testdox A $throwable_class after the payment succeeded with debug logging $logging keeps the order and is logged with its trace: $written.
	 *
	 * WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4531` (WOOPMNT-6145): when the payment step
	 * throws after the intent succeeded, the catch (`class-wc-payment-gateway-wcpay.php:1283-1304`) keeps the order status,
	 * adds the downstream-error note, logs a warning and returns success with get_return_url(). The test runs the real
	 * processing service: the provider's post-lifecycle step throws after the order is paid.
	 *
	 * Client 11.1.0 also writes `Logger::exception( 'Error occurred during the payment process.', $e )` with the class, code and
	 * trace before its succeeded-intent check (`class-wc-payment-gateway-wcpay.php:1274`, `includes/class-logger.php:100-112`),
	 * behind the debug setting; a PHP error, which fatals there, is written whatever the setting.
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
		$order      = $this->create_order();
		$return_url = $this->filter_return_url( $order );
		$provider   = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_downstream', '', 'pm_card_visa' ),
			'post_lifecycle_effects',
			new $throwable_class( 'Downstream step failed' )
		);
		$gateway    = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( $return_url, $result['redirect'] ?? '' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status(), 'The paid order must keep its status.' );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertContains( 'Payment succeeded, but a downstream error occurred during post-payment processing: Downstream step failed. Order status preserved.', $notes );
		$warnings = array_values( array_map( static fn( array $line ): string => $line[1], array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ) );
		$this->assertSame(
			array( sprintf( 'Payment intent already succeeded; downstream %s on order #%d suppressed to preserve order status.', $throwable_class, $order->get_id() ) ),
			$warnings,
			'The succeeded-intent warning is written whatever the setting.'
		);
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not see an error notice.' );
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
	 * WooPayments 11.1.0 attaches the succeeded intent to the order before any later step can throw
	 * (`class-wc-payments-order-service.php:1230-1249`), so its catch (`class-wc-payment-gateway-wcpay.php:1283-1304`)
	 * always sees the succeeded status. The runtime records it while applying the outcome; when that fails first, the
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
		$gateway    = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assert_succeeded_intent_defense( $order->get_id(), $result, $return_url, $note_count, 'Provider effect write failed', 'RuntimeException', $logger, 'pending' );
		$this->assertSame( 'pi_unrecorded', wc_get_order( $order->get_id() )->get_transaction_id(), 'The charge must stay reconcilable.' );
	}

	/**
	 * @testdox Should fail the order, add the failure note and return failure when post-payment processing throws before the intent succeeded.
	 *
	 * WooPayments 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay.php:4594`: without a succeeded intent
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
		$gateway                     = new WooPaymentsGateway();
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
		$gateway  = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1326-1327` fails the order when the payment
	 * information was never prepared, and adds the failed-payment note only when it was (`:1354`); the notice
	 * (`:1425`) and the failure return (`:1436-1439`) are the same.
	 */
	public function test_process_payment_exception_before_payment_fails_order_without_failure_note(): void {
		$order      = $this->create_order();
		$session    = $this->create_session();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$duplicate_payment_prevention_service = $this->getMockBuilder( WooPaymentsDuplicatePaymentPreventionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'delete_duplicate_of_paid_session_order' ) )
			->getMock();
		$duplicate_payment_prevention_service
			->method( 'delete_duplicate_of_paid_session_order' )
			->willThrowException( new \RuntimeException( 'Session storage is unavailable.' ) );

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1326` does not fail a subscription whose
	 * payment method is being changed; the failed-payment note (`:1354-1401`), the notice (`:1425`) and the
	 * failure return (`:1436-1439`) still apply.
	 */
	public function test_process_payment_exception_on_subscription_payment_method_change_keeps_status(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$subscription = $this->create_order();

		$GLOBALS['wcpay_test_subscription_ids'] = array( $subscription->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $subscription->get_id();

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$service                     = new RecordingPaymentProcessingService();
		$service->checkout_exception = new \RuntimeException( 'Subscription hook failed' );
		$gateway                     = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $subscription->get_id() );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
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
	 * WooPayments 11.1.0 throws the mismatch (`class-duplicate-payment-prevention-service.php:131-139`);
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
		$gateway = new WooPaymentsGateway();
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
	 * @testdox An exception applying an authorized outcome never fails the order: $_dataName.
	 *
	 * The authorization or debit stands, so the order is left for the webhook or the shopper's retry. Client 11.1.0 fails it
	 * for an Exception (class-wc-payment-gateway-wcpay.php:1272-1328). A third-party callback on woocommerce_order_note_added
	 * reaches this after the order went on hold: core catches exceptions from status hooks, but the error notes its own
	 * catch handlers add fire that hook with no catch. Once the order shows the authorization, checkout answers as for an
	 * authorized payment; a failure before that keeps the generic notice and leaves the order pending. Either way the order
	 * keeps the intent's own status, which the saved reference carries.
	 *
	 * @dataProvider provider_exceptions_applying_an_authorized_outcome
	 *
	 * @param string $where            Where the exception is thrown.
	 * @param string $payment          Which authorized payment the platform returned.
	 * @param string $result           Expected checkout result.
	 * @param string $order_status     Expected order status.
	 * @param string $intention_status Expected `_intention_status` on the order.
	 */
	public function test_process_payment_exception_applying_authorized_outcome_keeps_the_order( string $where, string $payment, string $result, string $order_status, string $intention_status ): void {
		$order   = $this->create_order();
		$outcome = $this->create_authorized_outcome( $order, $payment );
		if ( 'note hook' === $where ) {
			$provider = $this->create_provider_charging( $outcome, $this->create_unhooked_token_service() );
			add_action(
				'woocommerce_order_note_added',
				static function (): void {
					throw new \Exception( 'A note listener failed.' );
				}
			);
		} else {
			$provider = $this->create_provider_failing_after_charge( $outcome, 'operation_effects', new \RuntimeException( 'Provider effect write failed' ) );
		}
		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$logger                        = RecordingWcLogger::install();

		$checkout = $gateway->process_payment( $order->get_id() );

		$this->assertSame( $result, $checkout['result'] ?? '' );
		$this->assertSame( $order_status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount(
			1,
			array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] && str_contains( $line[1], 'Applying the authorized payment to order #' . $order->get_id() . ' raised' ) ),
			'The failure is logged whatever the logging setting.'
		);
		$this->assertSame( 'success' === $result ? array() : array( WooPaymentsErrorMessages::get_generic_message() ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( $intention_status, (string) wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
	}

	/**
	 * Where an exception stops an authorized outcome, for a held card, a debit still processing and a Multibanco voucher.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function provider_exceptions_applying_an_authorized_outcome(): array {
		return array(
			'a note listener throws after the order went on hold, held card' => array( 'note hook', 'held card', 'success', OrderStatus::ON_HOLD, 'requires_capture' ),
			'a note listener throws after the order went on hold, debit processing' => array( 'note hook', 'debit processing', 'success', OrderStatus::ON_HOLD, 'processing' ),
			'a note listener throws after the order went on hold, Multibanco voucher' => array( 'note hook', 'Multibanco voucher', 'success', OrderStatus::ON_HOLD, 'requires_action' ),
			'the provider effects throw before the order went on hold' => array( 'provider effects', 'held card', 'failure', OrderStatus::PENDING, 'requires_capture' ),
			'the provider effects throw before the order went on hold, debit processing' => array( 'provider effects', 'debit processing', 'failure', OrderStatus::PENDING, 'processing' ),
			'the provider effects throw before the order went on hold, Multibanco voucher' => array( 'provider effects', 'Multibanco voucher', 'failure', OrderStatus::PENDING, 'requires_action' ),
		);
	}

	/**
	 * Build the authorized outcome checkout hands on: the intent mapped by the codec, with its order effect plan.
	 *
	 * The held card is REC-CAP's manual-capture authorization, a Visa ending 4242 (Fixtures/platform-manual-capture.json,
	 * pair authorize_manual_capture); the debit changes only its status to processing. The voucher keeps that intent's ID
	 * and payment method with the multibanco_display_details next action Stripe returns
	 * (https://docs.stripe.com/api/payment_intents/object#payment_intent_object-next_action-multibanco_display_details).
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param string   $payment `held card`, `debit processing` or `Multibanco voucher`.
	 * @return PaymentOutcome
	 */
	private function create_authorized_outcome( WC_Order $order, string $payment ): PaymentOutcome {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-manual-capture.json' ), true );
		$intent    = array_values( array_filter( $recording['entries'], static fn( array $entry ): bool => 'authorize_manual_capture' === $entry['pair'] ) )[0]['response']['body'];
		if ( 'debit processing' === $payment ) {
			$intent['status'] = 'processing';
		}
		if ( 'Multibanco voucher' === $payment ) {
			$intent = array(
				'id'                   => $intent['id'],
				'status'               => 'requires_action',
				'amount'               => $intent['amount'],
				'currency'             => 'eur',
				'customer'             => $intent['customer'],
				'payment_method'       => $intent['payment_method'],
				'payment_method_types' => array( 'multibanco' ),
				'next_action'          => array(
					'type'                       => 'multibanco_display_details',
					'multibanco_display_details' => array(
						'reference'          => '123 456 789',
						'entity'             => '12345',
						'hosted_voucher_url' => 'https://payments.stripe.com/multibanco/voucher/test',
						'expires_at'         => time() + DAY_IN_SECONDS,
					),
				),
			);
		}

		return WooPaymentsIntentCodec::outcome_from_intention( $intent, new WooPaymentsIntentMappingContext( $order->get_checkout_order_received_url() ) )
			->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false ) );
	}

	/**
	 * @testdox When the duplicate guard's intent lookup throws a $throwable_class, checkout charges: $charges.
	 *
	 * Client 11.1.0 `src/Internal/Service/DuplicatePaymentPreventionService.php:100` catches only exceptions: an exception
	 * lets checkout go on, a PHP Error fatals and charges nothing. The gateway refuses instead of the fatal and leaves the order
	 * pending.
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
		$gateway = new WooPaymentsGateway();
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
		if ( $charges ) {
			$this->assertSame( array(), $logger->lines, 'An exception follows the logging setting, which is off.' );
			return;
		}
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array( "We're not able to process this payment. Please try again later." ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 'Error', 'pi_existing' ), array( $context['exception'] ?? '', $context['intent_id'] ?? '' ), 'A PHP Error is logged whatever the logging setting.' );
	}

	/**
	 * @testdox A PHP error applying a $outcome_status outcome at $stage leaves the order $expected_status, with the generic notice.
	 *
	 * The WooPayments extension's catch takes only exceptions (`class-wc-payment-gateway-wcpay.php:1272`), so a PHP error fatals and
	 * leaves the order as it was. The gateway keeps an order whose payment is authorized for reconciliation and logs the
	 * error whatever the logging setting; any other unsucceeded outcome fails the order
	 * as the WooPayments extension does for an exception. The error's text stays out of the shopper notice either way. An order that already
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
		$gateway  = new WooPaymentsGateway();
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
	 * The WooPayments extension's catch takes only exceptions (`class-wc-payment-gateway-wcpay.php:1272`), so a PHP error fatals with the
	 * order on hold; a retry then creates a new order and authorizes the card again. The gateway answers as it does for an
	 * authorized payment once a fresh read shows the order on hold for the outcome's intent, and keeps the refusal when the
	 * order is bound to another intent. The error is logged whatever the setting.
	 * A Multibanco voucher also puts the order on hold and answers the same: order-received shows its one voucher, as a
	 * Multibanco payment ends; a refusal would let a retry make a second order with a second voucher. When the fresh read
	 * itself throws, the original error is still logged and checkout keeps the refusal instead of letting the read failure
	 * escape.
	 *
	 * @testWith ["its intent", "success"]
	 *           ["another intent", "failure"]
	 *           ["its intent, as a Multibanco voucher", "success"]
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
		$outcome  = $this->create_authorized_outcome( $order, 'its intent, as a Multibanco voucher' === $intent ? 'Multibanco voucher' : 'held card' );
		$provider = $this->create_provider_failing_after_charge(
			$outcome,
			'post_lifecycle_effects',
			new \TypeError( 'Argument #1 must be of type array, null given' )
		);
		$gateway  = new WooPaymentsGateway();
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
		$this->assertSame( $outcome->get_payment_method_id(), $checkout['payment_method'] ?? '' );
		$this->assertSame( 0, wc_notice_count( 'error' ), 'The shopper must not be asked to pay again.' );
	}

	/**
	 * @testdox Should bump the failed-transaction limiter only for the WooPayments extension's decline error codes: $_dataName.
	 *
	 * Client `class-wc-payment-gateway-wcpay.php:4891-4893` (11.1.0) bumps only for
	 * `card_declined`, `incorrect_number` and `incorrect_cvc`; `expired_card` is not
	 * in that list, so an expired-card decline must not count toward the limiter.
	 *
	 * @dataProvider provider_failed_transaction_rate_limiter_error_codes
	 *
	 * @param string $error_code     Provider error code.
	 * @param int    $expected_count Failed transactions the limiter records.
	 */
	public function test_process_payment_bumps_failed_transaction_rate_limiter_only_for_decline_error_codes( string $error_code, int $expected_count ): void {
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

		$gateway = new WooPaymentsGateway();
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

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( $expected_count, $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array() ) );
	}

	/**
	 * Provider error codes and the failed transactions the rate limiter records for them.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public function provider_failed_transaction_rate_limiter_error_codes(): array {
		return array(
			'card declined'    => array( 'card_declined', 1 ),
			'incorrect number' => array( 'incorrect_number', 1 ),
			'incorrect cvc'    => array( 'incorrect_cvc', 1 ),
			'processing error' => array( 'processing_error', 0 ),
			'expired card'     => array( 'expired_card', 0 ),
		);
	}

	/**
	 * @testdox A failed provider checkout adds exactly one shopper notice, its shopper copy or a generic sentence, without raw diagnostics: $_dataName.
	 *
	 * @dataProvider provider_failed_outcome_shopper_notices
	 *
	 * @param string              $intent_id     Intent ID of the failed outcome.
	 * @param array<string,mixed> $outcome_data  Data of the failed outcome.
	 * @param string              $notice        Expected shopper notice.
	 * @param string              $private_value Diagnostic text the notice must not show.
	 */
	public function test_process_payment_adds_one_safe_notice_for_failed_outcome( string $intent_id, array $outcome_data, string $notice, string $private_value ): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();

		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, $intent_id, '', '', '', $outcome_data );

		$sut = new WooPaymentsGateway();
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

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result  = $sut->process_payment( $order->get_id() );
		$notices = wc_get_notices( 'error' );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, $notices );
		$this->assertSame( $notice, $notices[0]['notice'] );
		$this->assertStringNotContainsString( $private_value, $notices[0]['notice'] );
	}

	/**
	 * Failed outcomes and the one notice the shopper sees.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:string,3:string}>
	 */
	public function provider_failed_outcome_shopper_notices(): array {
		return array(
			'provider decline with shopper copy'   => array(
				'pi_declined',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'card_declined',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Provider diagnostic for request req_private.',
					WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => 'Error: Your card has insufficient funds.',
				),
				'Error: Your card has insufficient funds.',
				'req_private',
			),
			'transport error without shopper copy' => array(
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'wcpay_native_transport_error',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Connection refused at private-provider-host:8443.',
				),
				"We're not able to process this request. Please refresh the page and try again.",
				'private-provider-host',
			),
		);
	}

	/**
	 * @testdox Store API legacy checkout converts a failed provider notice to one safe route exception.
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
				PaymentOutcome::DATA_ERROR_CODE    => 'card_declined',
				PaymentOutcome::DATA_ERROR_MESSAGE => 'Provider diagnostic for request req_private.',
				WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => 'Error: Your card has insufficient funds.',
			)
		);

		$gateway = new WooPaymentsGateway();
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
		$context->set_payment_data( array( 'wcpay-payment-method' => 'pm_card' ) );
		$result = new StoreApiPaymentResult();

		try {
			( new StoreApiLegacy() )->process_legacy_payment( $context, $result );
			$this->fail( 'A failed provider outcome should surface its shopper notice through the Store API.' );
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

		$gateway = new WooPaymentsGateway();
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
		$gateway     = new WooPaymentsGateway();
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
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			WooPaymentsIntentCodec::outcome_from_intention( $intent, new WooPaymentsIntentMappingContext( $order->get_checkout_order_received_url() ) ),
			WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )
		);

		$gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method ) );
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

		$gateway = new WooPaymentsGateway();
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
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( wc_get_container()->get( WooPaymentsSubscriptionAdminPaymentMethodHandler::class ), 'update_payment_method_for_subscriptions' ), 10, 3 );
		$_POST['_wcsnonce'] = wp_create_nonce( 'wcs_change_payment_method' );

		$_POST['woocommerce_change_payment'] = (string) $order->get_id();
		$_GET['change_payment_method']       = (string) $order->get_id();

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';
		// The outcome branded the subscription before WCS records the change.
		$order->set_payment_method_title( 'Visa credit card' );
		$order->save();

		$return_url_filter = static function ( string $return_url, WC_Order $filtered_order ) use ( $order ): string {
			return $order->get_id() === $filtered_order->get_id() ? 'https://example.test/my-account/' : $return_url;
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11, 2 );

		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/my-account/', $result['redirect'] );
		$this->assertSame( 'Visa credit card', wc_get_order( $order->get_id() )->get_payment_method_title(), 'Client 11.1.0 brands the subscription after WCS records the change, so the stored title stays the card title, not "Card".' );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['recurring_payment'] ?? false );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'A validated subscription payment-method change must reach the provider context.' );
		$this->assertSame(
			array(
				array(
					'order_id'   => $order->get_id(),
					'gateway_id' => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				),
			),
			\WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods
		);
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( wc_get_container()->get( WooPaymentsSubscriptionAdminPaymentMethodHandler::class ), 'update_payment_method_for_subscriptions' ) ) );
	}

	/**
	 * @testdox Should keep a non-terminal outcome's redirect and defer subscription-change bookkeeping until it returns: $_dataName.
	 *
	 * @dataProvider provider_subscription_change_non_terminal_outcomes
	 *
	 * @param string $status          Outcome status.
	 * @param string $intent_id       Outcome intent ID.
	 * @param string $redirect_format Outcome redirect URL, with `%d` for the order ID.
	 * @param bool   $update_all      Whether the shopper asks to update all subscriptions.
	 * @param string $delayed_update  Expected `_delayed_update_payment_method_all` meta.
	 */
	public function test_process_payment_keeps_the_redirect_and_defers_subscription_change_bookkeeping( string $status, string $intent_id, string $redirect_format, bool $update_all, string $delayed_update ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order                     = $this->create_order();
		$redirect                  = sprintf( $redirect_format, $order->get_id() );
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( $status, $intent_id, $redirect, 'pm_new' );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		if ( $update_all ) {
			$_POST['update_all_subscriptions_payment_method'] = '1';
		}

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $redirect, $result['redirect'] );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $delayed_update, $order->get_meta( '_delayed_update_payment_method_all', true ) );
	}

	/**
	 * Non-terminal outcomes of a subscription payment-method change, and the update-all bookkeeping they leave.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool,4:string}>
	 */
	public function provider_subscription_change_non_terminal_outcomes(): array {
		return array(
			'confirmation redirect, update all' => array( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, 'pi_requires_action', '#wcpay-confirm-pi:%d:secret:nonce', true, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			'provider redirect'                 => array( PaymentOutcome::STATUS_REQUIRES_REDIRECT, 'pi_requires_redirect', 'https://payments.example.test/authorize', false, '' ),
		);
	}

	/**
	 * @testdox A subscription payment-method change with no payment method and no saved token is refused and keeps the subscription as it is.
	 *
	 * WooCommerce Subscriptions calls the gateway for the change inside a try (subscriptions-core 6.7.1, which client 11.1.0
	 * pins, `includes/class-wc-subscriptions-change-payment-gateway.php:335-338`). Client 11.1.0 refuses the request before its
	 * payment information exists (`includes/class-payment-information.php:157-162`) and then attempts to fail the subscription
	 * (`class-wc-payment-gateway-wcpay.php:1326`); native keeps the subscription's status.
	 */
	public function test_process_payment_does_not_complete_subscription_change_without_provider_credential(): void {
		wc_clear_notices();
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertNull( $service->last_checkout_context, 'Nothing reaches payment processing.' );
		$this->assertSame( array( 'Invalid or missing payment details. Please ensure the provided payment method is correctly entered.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * @testdox A subscription payment-method change refused before any charge answers the refusal and keeps the subscription as it is: $_dataName.
	 *
	 * WooCommerce Subscriptions passes the subscription itself as the order and answers "Payment method updated." on a
	 * success result (subscriptions-core 6.7.1, which client 11.1.0 pins, includes/class-wc-subscriptions-change-payment-gateway.php:319,
	 * :335-347, :376-378). Client 11.1.0 throws the phone, fraud-token, rate-limiter and missing-payment-method refusals
	 * before its payment information exists (class-wc-payment-gateway-wcpay.php:1206-1233; class-payment-information.php:157-162),
	 * so its catch answers success when the subscription's intention status is succeeded from an earlier change (:1283-1304)
	 * and otherwise fails the subscription (:1323-1328), which moves an active WooPayments subscription to pending. It throws
	 * the payment-method error after building its payment information (class-payment-information.php:288;
	 * class-wc-payment-gateway-wcpay.php:1664), so that refusal keeps the subscription's status (:1326) but still answers the
	 * same success after an earlier change. Here every one of them is refused and the subscription left as it is, whether
	 * the change enters a new card or selects a saved one.
	 *
	 * @dataProvider provider_subscription_change_refusals
	 *
	 * @param string $refusal                   Which refusal runs.
	 * @param string $message                   The refusal's notice.
	 * @param bool   $earlier_change_succeeded  Whether an earlier change left the subscription's intention status succeeded.
	 */
	public function test_refused_subscription_change_keeps_the_subscription_and_answers_the_refusal( string $refusal, string $message, bool $earlier_change_succeeded ): void {
		wc_clear_notices();
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order = $this->create_order();
		$order->set_status( 'on-hold' );
		if ( $earlier_change_succeeded ) {
			$order->update_meta_data( '_intention_status', 'succeeded' );
		}
		$order->save();
		$session                  = $this->create_session();
		$fraud_prevention_service = $this->create_fraud_prevention_service( false, $session );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST['wcpay-payment-method']          = 'pm_card';

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		if ( 'phone' === $refusal ) {
			$order->set_billing_phone( '+1 555 0100 0000 0000 0' );
			$order->save();
		} elseif ( 'phone_with_saved_card' === $refusal ) {
			$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
			$token       = new \WC_Payment_Token_CC();
			$token->set_token( 'pm_saved_card' );
			$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( '2040' );
			$token->set_user_id( $customer_id );
			$token->save();
			$order->set_customer_id( $customer_id );
			$order->set_billing_phone( '+1 555 0100 0000 0000 0' );
			$order->save();
			unset( $_POST['wcpay-payment-method'] );
			$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();
		} elseif ( 'fraud_token' === $refusal ) {
			$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
			$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';
			$fraud_prevention_service                               = $this->create_fraud_prevention_service( true, $session );
		} elseif ( 'rate_limiter' === $refusal ) {
			$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );
		} elseif ( 'no_payment_method' === $refusal ) {
			unset( $_POST['wcpay-payment-method'] );
		} else {
			$_POST['wcpay-payment-method']               = 'woocommerce_payments_payment_method_error';
			$_POST['wcpay-payment-method-error-message'] = $message;
		}

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, null, null, $fraud_prevention_service, new WooPaymentsFailedTransactionRateLimiter( $session ) );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'], 'A refused change must not answer success, which WooCommerce Subscriptions shows as "Payment method updated."' );
		$this->assertSame( array( $message ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
		$this->assertSame(
			array(),
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( $note->content, 'Payment succeeded, but a downstream error occurred' )
			)
		);
	}

	/**
	 * Refusals a subscription payment-method change can meet before any charge, with and without an earlier change's
	 * succeeded intention status on the subscription.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function provider_subscription_change_refusals(): array {
		$refusals = array(
			'billing phone over 20 characters'             => array( 'phone', 'Invalid phone number.' ),
			'billing phone over 20 characters, saved card' => array( 'phone_with_saved_card', 'Invalid phone number.' ),
			'fraud-token refusal'                          => array( 'fraud_token', "We're not able to process this payment. Please refresh the page and try again." ),
			'rate-limiter refusal'                         => array( 'rate_limiter', 'Your payment was not processed.' ),
			'no payment method'                            => array( 'no_payment_method', 'Invalid or missing payment details. Please ensure the provided payment method is correctly entered.' ),
			'payment method error'                         => array( 'payment_method_error', 'Your card number is invalid.' ),
		);
		$cases    = array();
		foreach ( $refusals as $name => $refusal ) {
			$cases[ $name ]                              = array( $refusal[0], $refusal[1], false );
			$cases[ $name . ' after an earlier change' ] = array( $refusal[0], $refusal[1], true );
		}

		return $cases;
	}

	/**
	 * @testdox An outcome other than completed or authorized does not complete a subscription payment-method change: $_dataName.
	 *
	 * The service answers no external payment for a $0 checkout with no credential, which the gateway lets through only with
	 * network-wide saved cards; the recording service returns it here whatever the request carries. Only a completed or
	 * authorized outcome updates the subscription's payment method and takes the change's return URL.
	 *
	 * @dataProvider provider_subscription_change_outcomes_that_do_not_complete
	 *
	 * @param string $status                  Outcome status.
	 * @param string $result                  Expected checkout result.
	 * @param bool   $redirects_order_received Whether checkout redirects to the order-received page, or to nothing.
	 */
	public function test_process_payment_does_not_complete_subscription_change_on_an_incomplete_outcome( string $status, string $result, bool $redirects_order_received ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order                     = $this->create_order();
		$service                   = new RecordingPaymentProcessingService();
		$redirect                  = $redirects_order_received ? $order->get_checkout_order_received_url() : '';
		$service->checkout_outcome = new PaymentOutcome( $status );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST['wcpay-payment-method']          = 'pm_card';

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$return_filter_calls = 0;
		$return_url_filter   = static function () use ( &$return_filter_calls ): string {
			++$return_filter_calls;
			return 'https://example.test/my-account/';
		};
		add_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );

		try {
			$checkout = $gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_get_return_url', $return_url_filter, 11 );
		}

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertTrue( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false, 'The request must run as a validated subscription payment-method change.' );
		$this->assertSame( $result, $checkout['result'] );
		$this->assertSame( $redirect, $checkout['redirect'] );
		$this->assertSame( 0, $return_filter_calls );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * Outcomes that leave a subscription payment-method change incomplete, and what checkout answers.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function provider_subscription_change_outcomes_that_do_not_complete(): array {
		return array(
			'no external payment' => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, 'success', true ),
			'failed'              => array( PaymentOutcome::STATUS_FAILED, 'failure', false ),
		);
	}

	/**
	 * @testdox Should give a subscription change request new-method change semantics only with a valid nonce and the exact processed subscription: $_dataName.
	 *
	 * @dataProvider provider_invalid_subscription_change_requests
	 *
	 * @param string   $nonce                 Nonce to post: '' for none, 'valid' for a valid one, or the literal value.
	 * @param string   $request_key           POST key naming the subscription.
	 * @param int|null $request_offset        Offset of the posted subscription ID from the order ID, or null to post '0'.
	 * @param int[]    $subscription_offsets  Offsets from the order ID of the IDs registered as subscriptions.
	 */
	public function test_process_payment_rejects_invalid_subscription_change_requests( string $nonce, string $request_key, ?int $request_offset, array $subscription_offsets ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_subscription_ids'] = array_map( static fn( int $offset ): int => $order->get_id() + $offset, $subscription_offsets );
		if ( '' !== $nonce ) {
			$_POST['_wcsnonce'] = 'valid' === $nonce ? wp_create_nonce( 'wcs_change_payment_method' ) : $nonce;
		}
		$_POST[ $request_key ] = null === $request_offset ? '0' : (string) ( $order->get_id() + $request_offset );

		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = 'new';

		$_POST['wcpay-payment-method'] = 'pm_card';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertFalse( $service->last_checkout_context->get_payment_data()['save_payment_method'] ?? false );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['recurring_payment'] ?? false );
		$this->assertFalse( $service->last_checkout_context->get_provider_data()['subscription_payment_method_change'] ?? false );
		$this->assertSame( array(), \WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods );
	}

	/**
	 * Subscription change requests that must not get new-method change semantics.
	 *
	 * The first three post `change_payment_method` with a valid nonce and identify the wrong subscription; the last three
	 * post `woocommerce_change_payment` naming a real subscription without the authority to change it.
	 *
	 * @return array<string,array{0:string,1:string,2:int|null,3:int[]}>
	 */
	public static function provider_invalid_subscription_change_requests(): array {
		return array(
			'zero request ID'              => array( 'valid', 'change_payment_method', null, array( 0 ) ),
			'mismatched request ID'        => array( 'valid', 'change_payment_method', 1, array( 0 ) ),
			'non-subscription ID'          => array( 'valid', 'change_payment_method', 0, array() ),
			'missing nonce'                => array( '', 'woocommerce_change_payment', 0, array( 0, 0 ) ),
			'invalid nonce'                => array( 'invalid-nonce', 'woocommerce_change_payment', 0, array( 0, 0 ) ),
			'mismatched real subscription' => array( 'valid', 'woocommerce_change_payment', 1, array( 0, 1 ) ),
		);
	}

	/**
	 * @testdox Should transport validated saved-method subscription changes without changing new-method semantics.
	 */
	public function test_process_payment_transports_validated_saved_method_subscription_change(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order   = $this->create_order();
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$token   = $this->create_card_token( $user_id, 'pm_saved_card' );
		$order->set_customer_id( $user_id );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();
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
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
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
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order   = $this->create_order();
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$token   = $this->create_card_token( $user_id, 'pm_saved_card' );
		$order->set_customer_id( $user_id );
		$order->set_status( 'on-hold' );
		$order->save();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$paid_statuses = static fn( $statuses ) => array_merge( (array) $statuses, array( 'on-hold' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', $paid_statuses );

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_POST['_wcsnonce']                     = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']    = (string) $order->get_id();
		$_POST[ 'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' ] = (string) $token->get_id();

		try {
			$gateway->process_payment( $order->get_id() );
		} finally {
			remove_filter( 'woocommerce_order_is_paid_statuses', $paid_statuses );
		}

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context, 'The saved-token change must reach payment processing.' );
	}

	/**
	 * @testdox Should refuse a WooPay preflight check before changing the order status: $_dataName.
	 *
	 * @dataProvider provider_refused_woopay_preflights
	 *
	 * @param bool   $tampered_token Whether card-testing protection is on and the posted fraud-prevention token is tampered.
	 * @param bool   $rate_limited   Whether the failed-transaction rate limiter is active.
	 * @param string $notice         Expected shopper notice.
	 */
	public function test_process_payment_refuses_woopay_preflight_before_status_change( bool $tampered_token, bool $rate_limited, string $notice ): void {
		wc_clear_notices();
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$session = $this->create_session();
		$order->update_status( 'failed' );
		if ( $tampered_token ) {
			$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'valid-token' );
			$_POST[ WooPaymentsFraudPreventionService::TOKEN_NAME ] = 'tampered-token';
		}
		if ( $rate_limited ) {
			$session->set( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY, array_fill( 0, 5, time() ) );
		}
		$_POST['is-woopay-preflight-check'] = '1';

		$gateway = new WooPaymentsGateway();
		$gateway->init(
			$service,
			new WooPaymentsProvider(),
			null,
			null,
			null,
			null,
			null,
			$this->create_fraud_prevention_service( $tampered_token, $session ),
			$rate_limited ? new WooPaymentsFailedTransactionRateLimiter( $session ) : null
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
		$this->assertSame( $notice, wc_get_notices( 'error' )[0]['notice'] ?? '' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertNull( $service->last_checkout_context );
	}

	/**
	 * WooPay preflight refusals and the notice the shopper sees.
	 *
	 * @return array<string,array{0:bool,1:bool,2:string}>
	 */
	public function provider_refused_woopay_preflights(): array {
		return array(
			'tampered fraud-prevention token' => array( true, false, "We're not able to process this payment. Please refresh the page and try again." ),
			'rate limiter active'             => array( false, true, 'Your payment was not processed.' ),
		);
	}

	/**
	 * @testdox Should short-circuit WooPay preflight checks before charging an order.
	 */
	public function test_process_payment_short_circuits_woopay_preflight_checks(): void {
		$order = $this->create_order();
		$order->update_status( 'failed' );

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
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
		$this->assertNull( WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox Should pass the WooPay intent id through provider data with the WooPayments extension's sanitize rule, empty without one: $_dataName.
	 *
	 * WooPay's merchant checkout body (PaymentsHandler::get_merchant_checkout_body()) sends the intent it confirmed under
	 * `platform-checkout-intent`; client 11.1.0 keeps only word characters after unslashing.
	 *
	 * @dataProvider provider_woopay_intent_ids
	 *
	 * @param string      $payment_method_id Posted payment method ID.
	 * @param string|null $posted_intent     Posted WooPay intent ID, or null when none is posted.
	 * @param string      $expected          Expected WooPay intent ID in the provider data.
	 */
	public function test_process_payment_passes_the_sanitized_woopay_intent_to_provider_data( string $payment_method_id, ?string $posted_intent, string $expected ): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = $payment_method_id;
		if ( null !== $posted_intent ) {
			$_POST['platform-checkout-intent'] = $posted_intent;
		}

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $expected, $service->last_checkout_context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID ] ?? null );
	}

	/**
	 * Posted WooPay intents and the intent ID the provider data carries.
	 *
	 * @return array<string,array{0:string,1:string|null,2:string}>
	 */
	public function provider_woopay_intent_ids(): array {
		return array(
			'WooPay intent with unsafe characters' => array( 'pm_woopay', 'pi_3Ab-c<x>\\\'', 'pi_3Abcx' ),
			'no WooPay intent'                     => array( 'pm_card', null, '' ),
		);
	}

	/**
	 * @testdox Should mark only a WooPay checkout's order with is_woopay, before the payment runs, whatever its outcome: $_dataName.
	 *
	 * WooPay's merchant checkout body sends is_woopay (PaymentsHandler::get_merchant_checkout_body()). Client 11.1.0
	 * Payment_Information::from_payment_request() adds it before processing, and before it turns a payment-method creation
	 * error into a failure (class-payment-information.php:284-296).
	 *
	 * @dataProvider provider_is_woopay_order_meta
	 *
	 * @param string|null          $outcome_status Status of the processing outcome, or null for the recording service's default.
	 * @param array<string,string> $post           Submitted checkout fields.
	 * @param string               $result         Expected checkout result.
	 * @param string               $is_woopay      Expected is_woopay meta.
	 */
	public function test_process_payment_marks_only_a_woopay_order_as_woopay( ?string $outcome_status, array $post, string $result, string $is_woopay ): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		if ( null !== $outcome_status ) {
			$service->checkout_outcome = new PaymentOutcome( $outcome_status, '', '', '', '', array() );
		}
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST = array_merge( $_POST, $post ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture; tearDown unsets each field.

		$checkout = $gateway->process_payment( $order->get_id() );

		$this->assertSame( $result, $checkout['result'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $is_woopay, $order->get_meta( 'is_woopay', true ) );
	}

	/**
	 * Checkouts and the is_woopay meta they leave on the order.
	 *
	 * @return array<string,array{0:string|null,1:array<string,string>,2:string,3:string}>
	 */
	public function provider_is_woopay_order_meta(): array {
		return array(
			'WooPay checkout whose payment fails' => array(
				PaymentOutcome::STATUS_FAILED,
				array(
					'wcpay-payment-method' => 'pm_woopay',
					'is_woopay'            => '1',
				),
				'failure',
				'1',
			),
			'WooPay checkout with a payment-method creation error' => array(
				null,
				array(
					'wcpay-payment-method'               => 'woocommerce_payments_payment_method_error',
					'wcpay-payment-method-error-message' => 'Your card number is invalid.',
					'wcpay-payment-method-error-code'    => 'incomplete_number',
					'is_woopay'                          => '1',
				),
				'failure',
				'1',
			),
			'ordinary checkout'                   => array( null, array( 'wcpay-payment-method' => 'pm_card' ), 'success', '' ),
		);
	}

	/**
	 * @testdox Should keep one is_woopay entry when a WooPay order is paid again, as the WooPayments extension adds it as unique.
	 */
	public function test_process_payment_keeps_one_is_woopay_entry_on_a_retry(): void {
		$order = $this->create_order();
		$order->add_meta_data( 'is_woopay', '1', true );
		$order->save_meta_data();
		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array() );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_woopay';
		$_POST['is_woopay']            = '1';

		$gateway->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_meta( 'is_woopay', false ), 'Client 11.1.0 adds is_woopay with $unique = true (class-payment-information.php:285).' );
	}

	/**
	 * @testdox Should pass submitted express checkout payment method types through provider data.
	 */
	public function test_process_payment_passes_express_payment_method_types_to_provider_data(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-confirmation-token']           = 'ctoken_express';
		$_POST['wcpay-express-payment-method-types'] = wp_json_encode( array( 'card', 'amazon_pay', 'unknown_method', array( 'nested' ) ) );
		$_POST['wcpay-express-checkout-context']     = 'pay_for_order';

		$gateway->process_payment( $order->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( 'ctoken_express', $service->last_checkout_context->get_payment_method_id() );
		$this->assertSame( array( 'card', 'amazon_pay' ), $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY ] );
		$this->assertSame( 'pay_for_order', $service->last_checkout_context->get_provider_data()[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] );
	}

	/**
	 * @testdox Should process refunds through the payment processing service.
	 */
	public function test_process_refund_delegates_to_processing_service(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$recorded = $this->record_tracks_events();

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertTrue( $result );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_refund_context );
		$payment_data = $service->last_refund_context->get_payment_data();
		$this->assertSame( 4.25, $payment_data['amount'] ?? null );
		$this->assertSame( 'Adjustment', $payment_data['reason'] ?? null );
		$this->assertArrayHasKey( 'wcadmin_wcpay_edit_order_refund_success', $recorded->events, 'A successful refund records the client\'s success event.' );
	}

	/**
	 * @testdox Should only allow refunds for orders with a WooPayments charge.
	 */
	public function test_can_refund_order_requires_charge_id(): void {
		$order   = $this->create_order();
		$gateway = new WooPaymentsGateway();

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
		$gateway = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2932-2938` returns
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
		$gateway = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2926-2930` returns `true` when
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

		$gateway = new WooPaymentsGateway();
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
	 * WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2932` refuses only an amount
	 * strictly above the order total, so a full refund still reaches the platform.
	 */
	public function test_process_refund_accepts_the_full_order_total(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_test' );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 12.0, 'Full refund' );

		$this->assertTrue( $result );
		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_refund_context );
		$this->assertSame( 12.0, $service->last_refund_context->get_payment_data()['amount'] );
	}

	/**
	 * @testdox Should fail refunds that do not have a WooPayments charge.
	 */
	public function test_process_refund_fails_without_charge_id(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$this->assertSame( 'order_payment_refund_missing_charge', $result->get_error_code() );
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'expired_or_canceled_card', 'The card was declined.' );
			}
		};
		$gateway = new WooPaymentsGateway();
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'insufficient_balance_for_refund', 'Could not refund the payment: insufficient funds.' );
			}
		};
		$gateway = new WooPaymentsGateway();
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'order_payment_refund_locked', 'A payment operation is already in progress for this order.' );
			}
		};
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$this->assertWPError( $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ), 'A refusal that made no platform attempt must not mark the refund failed.' );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should refuse a refund with no refund row before the platform call, returning the WooPayments extension's refund-not-found error.
	 *
	 * Client 11.1.0 refunds first and then returns `wcpay_edit_order_refund_not_found` after tracking the
	 * success (class-wc-payment-gateway-wcpay.php:2979, :3003-3007). The gateway keeps the code and message but
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
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				++$this->refund_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_no_row' );
			}
		};
		$gateway  = new WooPaymentsGateway();
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
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] );
		$this->assertSame( 0, $service->checkout_attempt_count, 'An already-paid order must never reach the processing service.' );
		$this->assertNull( WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
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
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );
		$notes  = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertSame( 1, $service->checkout_attempt_count );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'], 'The answer must match the already-paid check before the lock.' );
		$this->assertSame( 'WooPayments: detected and prevented a second payment for this order, which had already been paid.', $notes[0]->content ?? null );
		$this->assertNull( WC()->session->get( WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY ) );
	}

	/**
	 * @testdox Should resolve its dependencies when WooCommerce instantiates the gateway directly.
	 */
	public function test_process_payment_resolves_dependencies_without_explicit_init(): void {
		$order   = $this->create_order();
		$service = new RecordingPaymentProcessingService();
		wc_get_container()->replace( PaymentProcessingService::class, $service );
		$gateway = new WooPaymentsGateway();

		$_POST['wcpay-payment-method'] = 'pm_card';

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'], 'Only a resolved processing service and provider produce a successful checkout.' );
		$this->assertSame( 1, $service->checkout_attempt_count, 'The container-resolved processing service must run the checkout.' );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
	}

	/**
	 * @testdox A split gateway renders its payment fields with the card gateway's supports, as the WooPayments extension's one checkout does.
	 */
	public function test_split_gateway_payment_fields_use_the_card_gateway_supports(): void {
		$registry          = new WooPaymentsPaymentMethodRegistry();
		$card_gateway      = new WooPaymentsGateway( $registry->get( 'card' ) );
		$received_supports = null;
		$assets            = $this->create_checkout_assets_double(
			array(),
			true,
			static function ( array $supports ) use ( &$received_supports ): void {
				$received_supports = $supports;
			}
		);
		$provider          = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'get_gateway_for_method' )->willReturnMap( array( array( 'card', $card_gateway ) ) );
		$card_gateway->supports = array( 'products', 'refunds', 'subscriptions', 'card_only_marker' );

		$klarna_gateway = new WooPaymentsGateway( $registry->get( 'klarna' ) );
		$klarna_gateway->init( new RecordingPaymentProcessingService(), $provider, $assets );
		ob_start();
		$klarna_gateway->form();
		ob_end_clean();

		$this->assertSame( $card_gateway->supports, $received_supports );
	}

	/**
	 * @testdox Should print the saved payment methods inside the payment form, below the test-mode instructions, with the gateway's own supports.
	 */
	public function test_payment_fields_print_saved_methods_inside_the_form_with_the_gateway_supports(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		// A customer with a saved card: core lists it with the new-card option, and the save checkbox follows.
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_test_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $customer_id );
		$token->save();

		$service           = new RecordingPaymentProcessingService();
		$received_supports = null;
		$assets            = $this->create_checkout_assets_double(
			array(
				'testMode'             => true,
				'paymentMethodsConfig' => array( 'card' => array( 'testingInstructions' => 'Use test card 4242 4242 4242 4242.' ) ),
			),
			true,
			static function ( array $supports ) use ( &$received_supports ): void {
				$received_supports = $supports;
			}
		);

		$output           = '';
		$gateway_supports = array();
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $service, $assets, &$output, &$gateway_supports ): void {
					$gateway = new WooPaymentsGateway();
					$gateway->init( $service, new WooPaymentsProvider(), $assets );
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
		$this->assertStringContainsString( 'id="wcpay-core-checkout-form"', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-new-payment-method', $output );
		$this->assertStringContainsString( 'wc-woocommerce_payments-payment-token-new', $output );
		// The saved payment methods print inside the form, below the test-mode instructions and above the card element, as in
		// client 11.1.0 (includes/class-wc-payments-checkout.php:462-502); the save checkbox follows the form.
		$this->assertMatchesRegularExpression( '/<div id="wcpay-core-checkout-form"[^>]*>.*wcpay-core-test-mode-instructions.*wc-woocommerce_payments-payment-token-new.*<fieldset style="padding: 7px" class="wc-payment-form">.*<\/div>.*wc-woocommerce_payments-new-payment-method/s', $output );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]*>/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox Should print the classic checkout error region with an assertive live-region role.
	 *
	 * T.3 Task 4, the classic-error-region owner MISSING row: a screen reader
	 * only announces a payment failure as soon as it appears when the region is an assertive live
	 * region (`role="alert"`); a plain or absent live-region role can leave it unannounced while
	 * focus stays on the payment fields. The payment form prints the region hidden up front so the
	 * checkout script can fill and reveal it once a native confirmation callback
	 * (`update_order_status`/`confirm_intent_for_order`) reports a failure (the config's
	 * `confirmationErrorMessage`). Oracle: WooPayments 11.1.0
	 * `client/checkout/utils/show-error-checkout.js:15` wraps a checkout error in
	 * `<ul class="woocommerce-error" role="alert">`, the same `woocommerce-error` class and
	 * `role="alert"` pairing native's region carries; the assertion below checks only that pairing,
	 * not the surrounding `hidden` markup, which is native-only structure with no client
	 * counterpart to cite.
	 */
	public function test_payment_form_prints_assertive_payment_error_region(): void {
		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $this->create_checkout_assets_double() );

		ob_start();
		$gateway->form();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'woocommerce-error', $output );
		$this->assertStringContainsString( 'role="alert"', $output );
	}

	/**
	 * @testdox Should tell the shopper inside the payment form that WooPayments checkout is unavailable only while the account cannot take payments: $scenario.
	 * @testWith ["account can take payments", true, false]
	 *           ["account cannot take payments", false, true]
	 *
	 * @param string $scenario          Scenario description.
	 * @param bool   $can_take_payments Whether the account can take payments.
	 * @param bool   $expects_notice    Whether the unavailable notice prints.
	 */
	public function test_payment_form_prints_the_unavailable_notice_only_when_the_account_cannot_take_payments( string $scenario, bool $can_take_payments, bool $expects_notice ): void {
		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $this->create_checkout_assets_double( array(), $can_take_payments ) );

		ob_start();
		$gateway->form();
		$output = (string) ob_get_clean();

		$this->assertSame(
			$expects_notice ? 1 : 0,
			preg_match( '/<div id="wcpay-core-checkout-form"[^>]*>.*<p class="woocommerce-info wcpay-core-checkout-unavailable">WooPayments checkout is not available right now\. Please choose another payment method\.<\/p><\/div>$/s', $output ),
			$scenario
		);
	}

	/**
	 * Client 11.1.0 also prints a hidden, pre-checked save checkbox on this page (class-wc-payments-checkout.php:513-516,
	 * class-wc-payment-gateway-wcpay.php:1144-1148). Native prints none, a decided difference: nothing reads that field when a
	 * payment method is added.
	 *
	 * @testdox Should load core's tokenization-form.js on the My Account add-payment-method form, without saved methods or a save checkbox.
	 */
	public function test_payment_fields_load_tokenization_form_on_add_payment_method_page(): void {
		global $wp;

		$my_account_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$original_page_id   = get_option( 'woocommerce_myaccount_page_id' );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
		$this->go_to( get_permalink( $my_account_page_id ) );
		$wp->query_vars['add-payment-method'] = '';
		wp_dequeue_script( 'woocommerce-tokenization-form' );

		// A customer with a saved card, so the saved methods list would print if the form were given it.
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_test_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $customer_id );
		$token->save();
		$assets = $this->create_checkout_assets_double();

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $assets, &$output ): void {
					$gateway = new WooPaymentsGateway();
					$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $assets );

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
		// script loads, but the saved methods list does not. The save checkbox is the decided difference above.
		$this->assertTrue( wp_script_is( 'woocommerce-tokenization-form', 'enqueued' ) );
		$this->assertStringContainsString( 'id="wcpay-core-checkout-form"', $output );
		$this->assertStringNotContainsString( 'woocommerce-SavedPaymentMethods', $output );
	}

	/**
	 * @testdox Should render mandatory subscription change saving as a visually hidden checked checkbox.
	 */
	public function test_payment_fields_hide_checked_save_payment_method_for_subscription_change(): void {
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$order = $this->create_order();

		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() );
		$_GET['change_payment_method']          = (string) $order->get_id();

		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$service = new RecordingPaymentProcessingService();
		$assets  = $this->create_checkout_assets_double();

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $service, $assets, &$output ): void {
					$gateway = new class() extends WooPaymentsGateway {
						/**
						 * Simulate optional WooCommerce Subscriptions availability.
						 *
						 * @return bool
						 */
						public function is_subscriptions_enabled(): bool {
							return true;
						}
					};
					$gateway->init( $service, new WooPaymentsProvider(), $assets );

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
		$assets = $this->create_checkout_assets_double();

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => 'yes' ),
				function () use ( $assets, &$output ): void {
					$gateway = new WooPaymentsGateway();
					$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $assets );

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
	 * Client 11.1.0 offers the save checkbox only while saved cards are on and the method can be saved: reusable, in a valid
	 * currency (class-wc-payments-checkout.php:505-517, class-wc-payment-gateway-wcpay.php:4900-4902,4961-4968). A gateway
	 * claiming tokenization through the core supports filter does not change that.
	 *
	 * @testdox Should print no save checkbox, even when a filter makes the gateway claim tokenization: $scenario.
	 * @testWith ["iDEAL, which cannot be saved, in a currency it takes", "ideal", "yes", "EUR"]
	 *           ["card with saved cards off", "card", "no", "USD"]
	 *
	 * @param string $scenario          Scenario description.
	 * @param string $payment_method_id Payment method of the gateway.
	 * @param string $saved_cards       Saved cards setting.
	 * @param string $currency          Store currency.
	 */
	public function test_payment_fields_offer_no_saving_the_woopayments_extension_does_not_offer( string $scenario, string $payment_method_id, string $saved_cards, string $currency ): void {
		unset( $scenario );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$gateway_id          = 'card' === $payment_method_id ? WooPaymentsPersistenceVocabulary::GATEWAY_ID : WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_' . $payment_method_id;
		$claims_tokenization = static fn( bool $supported, string $feature, $gateway ): bool => $supported || ( PaymentGatewayFeature::TOKENIZATION === $feature && $gateway_id === $gateway->id );
		$store_currency      = static fn(): string => $currency;
		add_filter( 'woocommerce_payment_gateway_supports', $claims_tokenization, 10, 3 );
		add_filter( 'woocommerce_currency', $store_currency );
		$assets   = $this->create_checkout_assets_double();
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'get_gateway_for_method' )->willReturn( null );

		$output = '';
		try {
			$this->with_gateway_settings(
				array( 'saved_cards' => $saved_cards ),
				function () use ( $assets, $provider, $payment_method_id, &$output ): void {
					$gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id ) );
					$gateway->init( new RecordingPaymentProcessingService(), $provider, $assets );
					$this->assertTrue( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ), 'The filter makes the gateway claim tokenization.' );

					ob_start();
					$gateway->payment_fields();
					$output = (string) ob_get_clean();
				}
			);
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			remove_filter( 'woocommerce_payment_gateway_supports', $claims_tokenization, 10 );
			remove_filter( 'woocommerce_currency', $store_currency );
		}

		$this->assertStringContainsString( 'id="wcpay-core-checkout-form"', $output );
		$this->assertStringNotContainsString( 'wc-' . $gateway_id . '-new-payment-method', $output );
	}

	/**
	 * @testdox Should turn the express gateways off in the block editor before the Checkout block lists enabled gateways.
	 */
	public function test_block_editor_turns_express_gateways_off(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$gateways = array();
		foreach ( array( 'card', 'apple_pay', 'klarna' ) as $payment_method_id ) {
			$gateway          = new WooPaymentsGateway( $registry->get( $payment_method_id ) );
			$gateway->enabled = 'yes';
			$gateways[]       = $gateway;
		}
		$wc_gateways = WC()->payment_gateways();
		$previous    = $wc_gateways->payment_gateways;
		// WC_Payment_Gateways::payment_gateways() returns this public list.
		$wc_gateways->payment_gateways = $gateways;

		try {
			// The Checkout block reads the enabled gateways at the default priority (PaymentUtils::get_enabled_payment_gateways()).
			$this->assertSame( 1, has_action( 'enqueue_block_editor_assets', array( WooPaymentsGateway::class, 'handle_enqueue_block_editor_assets' ) ) );
			WooPaymentsGateway::handle_enqueue_block_editor_assets();
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
			$gateway = new WooPaymentsGateway();
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
				$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
	 * @testdox Should hide and check the save-payment control only for a subscription cart: $_dataName.
	 *
	 * The extension keeps the classic checkbox visible on renewal carts; the save is forced server-side regardless.
	 *
	 * @dataProvider provider_save_checkbox_by_cart
	 *
	 * @param bool $subscription       Whether the cart contains a subscription.
	 * @param bool $renewal            Whether the cart contains a renewal.
	 * @param bool $hidden_and_checked Whether the control is hidden and checked.
	 */
	public function test_save_payment_method_checkbox_hides_a_checked_control_only_for_subscription_carts( bool $subscription, bool $renewal, bool $hidden_and_checked ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = $subscription;
		if ( $renewal ) {
			$GLOBALS['wcpay_test_cart_contains_renewal'] = true;
		}

		$gateway = new WooPaymentsGateway();

		try {
			ob_start();
			$gateway->save_payment_method_checkbox();
			$output = (string) ob_get_clean();
		} finally {
			unset( $GLOBALS['wcpay_test_cart_contains_renewal'] );
		}

		$this->assertSame( 1, substr_count( $output, 'id="wc-woocommerce_payments-new-payment-method"' ) );
		$this->assertSame( $hidden_and_checked, str_contains( $output, 'style="display:none;"' ) );
		$this->assertSame( (int) $hidden_and_checked, preg_match( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+type="checkbox"[^>]+checked[^>]*>/', $output ) );
		$this->assertSame( (int) $hidden_and_checked, preg_match( '/<input[^>]+id="wc-woocommerce_payments-new-payment-method"[^>]+checked[^>]*>/', $output ) );
	}

	/**
	 * Carts and whether the save-payment control is hidden and checked.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool}>
	 */
	public function provider_save_checkbox_by_cart(): array {
		return array(
			'subscription cart' => array( true, false, true ),
			'regular cart'      => array( false, false, false ),
			'renewal-only cart' => array( false, true, false ),
		);
	}

	/**
	 * @testdox The card save checkbox is $expected when WooPay applies to the checkout page ($platform), unchecked either way.
	 *
	 * Client 11.1.0 save_payment_method_checkbox() hides the checkbox when it is forced or when
	 * should_use_stripe_platform_on_checkout_page() holds (gw:1146), and that predicate is false for every gateway but card
	 * (gw:1174-1176). Covers the cases of the client's test_save_payment_method_checkbox_displayed and
	 * test_save_payment_method_checkbox_not_displayed_when_stripe_platform_account_used, and
	 * test_should_not_use_stripe_platform_on_checkout_page_for_non_card (tests/unit/test-class-wc-payment-gateway-wcpay.php:1754-1758, 1893-1944).
	 *
	 * @testWith ["card", true, "hidden"]
	 *           ["card", false, "visible"]
	 *           ["sepa_debit", true, "visible"]
	 *
	 * @param string $payment_method Payment method of the gateway.
	 * @param bool   $platform       Whether the checkout assets report WooPay on the checkout page.
	 * @param string $expected       Whether the checkbox is hidden or visible.
	 */
	public function test_save_payment_method_checkbox_hides_for_woopay_on_the_card_checkout( string $payment_method, bool $platform, string $expected ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = false;
		$assets = $this->createMock( WooPaymentsCheckoutAssets::class );
		$assets->method( 'should_use_stripe_platform_on_checkout_page' )->willReturn( $platform );
		$gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method ) );
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider(), $assets );

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="wc-' . $gateway->id . '-new-payment-method"' ) );
		$hidden = preg_match( '/<div style="display:none;">.*<input[^>]+id="wc-' . preg_quote( $gateway->id, '/' ) . '-new-payment-method".*<\/div>/s', $output );
		$this->assertSame( 'hidden' === $expected ? 1 : 0, $hidden );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+checked[^>]*>/', $output );
	}

	/**
	 * @testdox The save checkbox reads the WooPayments extension's label on a $cart cart.
	 *
	 * Client 11.1.0 labels the checkbox "Save payment information to my account for future purchases." (gw:1160), visible
	 * or forced; core's "Save to account" never shows.
	 *
	 * @testWith ["regular"]
	 *           ["subscription"]
	 *
	 * @param string $cart Cart kind.
	 */
	public function test_save_payment_method_checkbox_uses_the_woopayments_extension_label( string $cart ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS['wcpay_test_cart_contains_subscription'] = 'subscription' === $cart;
		$gateway = new WooPaymentsGateway();

		ob_start();
		$gateway->save_payment_method_checkbox();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<label for="wc-woocommerce_payments-new-payment-method" style="display:inline;">Save payment information to my account for future purchases\.</label>#', $output );
		$this->assertStringNotContainsString( 'Save to account', $output );
	}

	/**
	 * @testdox Should keep the ordinary save control when subscription change form identity does not match.
	 */
	public function test_payment_fields_reject_mismatched_subscription_change_form_identity(): void {
		RecordedPublicFraudServices::answer();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
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
					$gateway = new class() extends WooPaymentsGateway {
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
	 * @testdox Should resolve checkout assets dependencies when payment fields are rendered directly.
	 */
	public function test_payment_fields_resolve_dependencies_without_explicit_init(): void {
		RecordedPublicFraudServices::answer();
		$gateway = new WooPaymentsGateway();

		ob_start();
		$gateway->payment_fields();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-core-checkout-form', $output );
	}

	/**
	 * @testdox The gateway answers trunk's settings provider list with the recommended payment methods the onboarding fetches.
	 */
	public function test_get_recommended_payment_methods_answers_from_the_onboarding(): void {
		$recommended = array(
			array(
				'id'       => 'card',
				'title'    => 'Cards',
				'enabled'  => true,
				'priority' => 0,
			),
		);
		$onboarding  = $this->createMock( WooPaymentsOnboardingService::class );
		$onboarding->expects( $this->once() )->method( 'get_recommended_payment_methods' )->with( 'GB' )->willReturn( $recommended );
		wc_get_container()->replace( WooPaymentsOnboardingService::class, $onboarding );

		$this->assertSame( $recommended, ( new WooPaymentsGateway() )->get_recommended_payment_methods( 'GB' ) );
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
	 * Create a paid order carrying the provider identifiers the gateway writes.
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
	 * @testdox Should link an authorized payment on the admin order page to its transaction details: $_dataName.
	 *
	 * The gateway writes `_transaction_id` on every paid order, so WooCommerce renders the
	 * identifier on the order page either way; answering `get_transaction_url()` is
	 * what makes it the link the merchant had before switching runtimes.
	 *
	 * @dataProvider provider_linkable_transaction_orders
	 *
	 * @param string $intention_status Provider intention status meta.
	 * @param string $intent_id        Provider intent ID meta.
	 * @param string $charge_id        Provider charge ID meta.
	 * @param string $linked_id        ID the link names.
	 */
	public function test_get_transaction_url_links_an_authorized_payment( string $intention_status, string $intent_id, string $charge_id, string $linked_id ): void {
		$order   = $this->create_order_with_provider_meta( $intention_status, $intent_id, $charge_id );
		$gateway = new WooPaymentsGateway();

		$url = $gateway->get_transaction_url( $order );

		$this->assertStringContainsString( 'page=wc-admin', $url );
		$this->assertStringContainsString( "id={$linked_id}", $url );
		$this->assertStringContainsString( 'path=%2Fpayments%2Ftransactions%2Fdetails', $url );
	}

	/**
	 * Orders with an authorized payment and the ID their link names: the intent ID, or the charge ID without one.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function provider_linkable_transaction_orders(): array {
		return array(
			'succeeded payment'        => array( 'succeeded', 'pi_transaction_url', 'ch_transaction_url', 'pi_transaction_url' ),
			'uncaptured authorization' => array( 'requires_capture', 'pi_uncaptured', '', 'pi_uncaptured' ),
			'charge ID without intent' => array( 'succeeded', '', 'ch_only', 'ch_only' ),
		);
	}

	/**
	 * @testdox Should not link an order without an authorized payment: $_dataName.
	 *
	 * An unauthorized intention has no payment to link to, a SetupIntent stores no payment, and an order carrying no
	 * provider identifier has nothing to link.
	 *
	 * @dataProvider provider_unlinkable_transaction_orders
	 *
	 * @param string $intention_status Provider intention status meta.
	 * @param string $intent_id        Provider intent ID meta.
	 */
	public function test_get_transaction_url_is_empty_without_an_authorized_payment( string $intention_status, string $intent_id ): void {
		$order   = $this->create_order_with_provider_meta( $intention_status, $intent_id );
		$gateway = new WooPaymentsGateway();

		$this->assertSame( '', $gateway->get_transaction_url( $order ) );
	}

	/**
	 * Orders that must not produce a transaction link.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_unlinkable_transaction_orders(): array {
		return array(
			'requires payment method' => array( 'requires_payment_method', 'pi_unauthorized' ),
			'requires action'         => array( 'requires_action', 'pi_unauthorized' ),
			'canceled'                => array( 'canceled', 'pi_unauthorized' ),
			'no status recorded'      => array( '', 'pi_unauthorized' ),
			'setup intent'            => array( 'succeeded', 'seti_saved_card' ),
			'no provider identifiers' => array( 'succeeded', '' ),
		);
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return bool|\WP_Error
			 */
			public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
				parent::process_refund( $context, $provider );

				return new \WP_Error( 'https://pay.example.test/code', "Error: No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123" );
			}
		};
		$gateway = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );
		$logger = RecordingWcLogger::install();

		$gateway->process_refund( $order->get_id(), 4.25, 'Adjustment' );

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( $order->get_id(), 'unknown_error' ), array( $context['order_id'], $context['error_code'] ) );
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Create the real WooPayments provider whose charge returns a fixed outcome, with the real effect applier on the given token service.
	 *
	 * @param PaymentOutcome          $outcome       Outcome the charge returns.
	 * @param WooPaymentsTokenService $token_service Token service the effect applier saves tokens with.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_charging( PaymentOutcome $outcome, WooPaymentsTokenService $token_service ): WooPaymentsProvider {
		$payment_operations = new class( $outcome ) extends \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService {
			/**
			 * Outcome the charge returns.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome Outcome the charge returns.
			 */
			public function __construct( PaymentOutcome $outcome ) {
				$this->outcome = $outcome;
			}

			/**
			 * Return the fixed outcome without a platform request.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Charge idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->outcome;
			}
		};

		$container = wc_get_container();
		$applier   = new WooPaymentsOrderEffectApplier();
		$applier->init(
			$token_service,
			$container->get( WooPaymentsOrderDataService::class ),
			$container->get( WooPaymentsAccountService::class ),
			$container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class ),
			$container->get( WooPaymentsPaymentMethodRegistry::class ),
			$container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService::class )
		);
		$provider = new WooPaymentsProvider();
		$provider->init( $payment_operations, $container->get( WooPaymentsApiClient::class ), $container->get( WooPaymentsAccountService::class ), new WooPaymentsPaymentMethodRegistry(), $applier );

		return $provider;
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
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->outcome;
			}

			/**
			 * Throw before the lifecycle when asked to.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws \Throwable When the stage is `operation_effects`.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				if ( 'operation_effects' === $this->stage ) {
					throw $this->failure;
				}

				return $outcome;
			}

			/**
			 * Throw after the lifecycle when asked to.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Applied provider outcome.
			 * @param string                  $operation Operation name.
			 * @throws \Throwable When the stage is `post_lifecycle_effects`.
			 */
			public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
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
	 * Create a saved WooPayments card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
			wc_get_container()->get( OrderPaymentLifecycleService::class )
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
	 * Create a checkout assets double that returns a fixed payment fields config.
	 *
	 * @param array<string,mixed> $config            Config the double returns for the payment form.
	 * @param bool                $can_take_payments Whether the account can take payments.
	 * @param callable|null       $on_enqueue        Optional callback that receives the supports and payment method definition the gateway passes.
	 * @return WooPaymentsCheckoutAssets
	 */
	private function create_checkout_assets_double( array $config = array(), bool $can_take_payments = true, ?callable $on_enqueue = null ): WooPaymentsCheckoutAssets {
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'enqueue_classic_checkout_assets_for_fields', 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'enqueue_classic_checkout_assets_for_fields' )->willReturnCallback(
			static function ( array $supports, $payment_method_definition = null ) use ( $config, $on_enqueue ): array {
				if ( null !== $on_enqueue ) {
					$on_enqueue( $supports, $payment_method_definition );
				}

				return $config;
			}
		);
		$assets->method( 'should_expose_checkout_surface' )->willReturn( $can_take_payments );

		return $assets;
	}

	/**
	 * @testdox A 3DS confirmation nonce created after classic checkout creates the shopper's account is valid on the shopper's next request.
	 *
	 * Runs in its own process: the checkout defines WOOCOMMERCE_CHECKOUT for the rest of the run.
	 * Client 11.1.0 keeps the new login cookie for the rest of the request (class-wc-payment-gateway-wcpay.php:573,762-766).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_confirmation_nonce_created_after_checkout_account_creation_verifies_on_the_next_request(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		new WooPaymentsGateway();
		// What WC_Checkout::process_checkout() and wc_create_new_customer() do before the payment runs.
		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
		do_action( 'woocommerce_created_customer', $user_id, array(), false );
		$next_request_cookie = $this->set_auth_cookie_as_checkout_does( $user_id );

		$nonce = wp_create_nonce( 'wcpay_update_order_status_nonce' );
		// The shopper's next request, the order-status AJAX call, sends the new login cookie.
		$_COOKIE[ LOGGED_IN_COOKIE ] = $next_request_cookie;

		$this->assertSame( 1, wp_verify_nonce( $nonce, 'wcpay_update_order_status_nonce' ), 'The order-status AJAX call must accept the nonce the checkout response carried.' );
	}

	/**
	 * @testdox $label leaves the current request's login cookie alone.
	 *
	 * Runs in its own process so no earlier test has defined WOOCOMMERCE_CHECKOUT.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testWith ["A login outside checkout", false, false]
	 *           ["An account created outside checkout, as My Account registration does", false, true]
	 *           ["A login during a checkout that created no account", true, false]
	 *
	 * @param string $label           Case description.
	 * @param bool   $in_checkout     Whether the request is a checkout.
	 * @param bool   $account_created Whether the request created the customer's account.
	 */
	public function test_login_outside_checkout_account_creation_leaves_the_request_cookie_alone( string $label, bool $in_checkout, bool $account_created ): void {
		unset( $label );
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		new WooPaymentsGateway();
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		if ( $in_checkout ) {
			wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
		}
		if ( $account_created ) {
			do_action( 'woocommerce_created_customer', $user_id, array(), false ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- What wc_create_new_customer() fires.
		}

		$this->set_auth_cookie_as_checkout_does( $user_id );

		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, $_COOKIE, 'Only a checkout that created the account switches the request to the new cookie (client 11.1.0 class-wc-payment-gateway-wcpay.php:763).' );
	}

	/**
	 * Log a user in as wc_set_customer_auth_cookie() does, without sending headers, and return the new login cookie.
	 *
	 * @param int $user_id User ID.
	 * @return string The logged-in cookie the browser receives.
	 */
	private function set_auth_cookie_as_checkout_does( int $user_id ): string {
		$cookie = '';
		add_action(
			'set_logged_in_cookie',
			static function ( $logged_in_cookie ) use ( &$cookie ): void {
				$cookie = (string) $logged_in_cookie;
			},
			0
		);
		add_filter( 'send_auth_cookies', '__return_false' );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return $cookie;
	}

	/**
	 * @testdox Should not resolve account data while constructing the gateway.
	 */
	public function test_construction_does_not_touch_wc_instance_when_country_uncached(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_cached_account_data' ) )->getMock();
		$account_service->expects( $this->never() )->method( 'get_cached_account_data' );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();

		new WooPaymentsGateway();
	}

	/**
	 * @testdox Should read the account country once, on the first title read: $_dataName.
	 *
	 * @dataProvider provider_branded_title_reads
	 *
	 * @param string|null $payment_method_id Payment method ID, or null for the default card gateway.
	 * @param string      $title_method      Title method to call.
	 * @param string      $expected          Expected title.
	 */
	public function test_title_reads_lazily_memoize_account_country_branding( ?string $payment_method_id, string $title_method, string $expected ): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_cached_account_data' ) )->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_cached_account_data' )
			->willReturn( array( 'country' => 'US' ) );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();

		$gateway = new WooPaymentsGateway( null === $payment_method_id ? null : ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id ) );

		$this->assertSame( $expected, $gateway->{$title_method}() );
		$this->assertSame( $expected, $gateway->{$title_method}() );
	}

	/**
	 * Gateways, the title method read and the title it gives.
	 *
	 * @return array<string,array{0:string|null,1:string,2:string}>
	 */
	public function provider_branded_title_reads(): array {
		return array(
			'card title'                    => array( null, 'get_title', 'Card' ),
			'Cash App Afterpay admin title' => array( 'afterpay_clearpay', 'get_method_title', 'WooPayments (Cash App Afterpay)' ),
		);
	}

	/**
	 * @testdox Should enqueue the classic checkout fallback stack only when the account can take payments.
	 * @testWith [true, 1]
	 *           [false, 0]
	 *
	 * @param bool $can_process_payments Whether the provider can take payments.
	 * @param int  $expected_enqueues    Expected fallback enqueue calls.
	 */
	public function test_classic_checkout_fallback_enqueues_only_when_account_can_take_payments( bool $can_process_payments, int $expected_enqueues ): void {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsRuntimeArbiter::class, $arbiter );
		$provider = $this->createMock( WooPaymentsProvider::class );
		$provider->method( 'can_process_payments' )->willReturn( $can_process_payments );
		$assets = $this->createMock( WooPaymentsCheckoutAssets::class );
		$assets->expects( $this->exactly( $expected_enqueues ) )->method( 'enqueue_classic_checkout_assets_without_fields' );

		$gateway = new WooPaymentsGateway();
		$gateway->init( $this->createMock( PaymentProcessingService::class ), $provider, $assets );
		/** This action is documented in templates/checkout/form-checkout.php */
		do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
	}

	/**
	 * @testdox Should reload settings once when the cached blog context is stale.
	 */
	public function test_site_derived_state_reloads_once_when_the_cached_blog_context_is_stale(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'     => 'yes',
				'saved_cards' => 'yes',
			)
		);
		$gateway = new WooPaymentsGateway();
		$this->assertSame( 'yes', $gateway->get_option( 'saved_cards' ) );

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'     => 'no',
				'saved_cards' => 'no',
			)
		);
		$blog_id = new \ReflectionProperty( WooPaymentsGateway::class, 'settings_blog_id' );
		$blog_id->setAccessible( true );
		$blog_id->setValue( $gateway, get_current_blog_id() + 1 );

		$settings_reads = 0;
		$count_read     = static function ( $value ) use ( &$settings_reads ) {
			++$settings_reads;
			return $value;
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_read );

		try {
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'no', $gateway->enabled );
			$this->assertSame( 1, $settings_reads );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_read );
		}
	}

	/**
	 * @testdox Checkout provider data should carry the WooPay save-user opt-in and fire the save action.
	 */
	public function test_checkout_provider_data_carries_woopay_save_user_opt_in(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();

		$action_fired = 0;
		add_action(
			'woocommerce_payments_save_user_in_woopay',
			function () use ( &$action_fired ): void {
				++$action_fired;
			}
		);

		try {
			$_POST['wcpay-payment-method'] = 'pm_card';
			$_POST['save_user_in_woopay']  = 'true';
			$provider_data                 = $this->checkout_context( new WooPaymentsGateway( $registry->get( 'card' ) ) )->get_provider_data();

			$this->assertTrue( $provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );

			unset( $_POST['save_user_in_woopay'] );
			$bare_provider_data = $this->checkout_context( new WooPaymentsGateway( $registry->get( 'card' ) ) )->get_provider_data();

			$this->assertFalse( $bare_provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );
		} finally {
			unset( $_POST['save_user_in_woopay'] );
			remove_all_actions( 'woocommerce_payments_save_user_in_woopay' );
		}
	}

	/**
	 * Submissions for the platform-payment-method checks.
	 *
	 * @return array<string,array{string,array<string,string>,bool}>
	 */
	public function platform_payment_method_submission_provider(): array {
		return array(
			'new card created on the platform'          => array( 'card', array( 'wcpay-is-platform-payment-method' => 'true' ), true ),
			'new card created on the connected account' => array( 'card', array( 'wcpay-is-platform-payment-method' => 'false' ), false ),
			'new card chosen over saved cards'          => array(
				'card',
				array(
					'wcpay-is-platform-payment-method' => 'true',
					'wc-woocommerce_payments-payment-token' => 'new',
				),
				true,
			),
			'saved card'                                => array(
				'card',
				array(
					'wcpay-is-platform-payment-method' => 'true',
					'wc-woocommerce_payments-payment-token' => '12',
				),
				false,
			),
			'express payment'                           => array(
				'card',
				array(
					'wcpay-is-platform-payment-method'   => 'true',
					'wcpay-express-payment-method-types' => 'apple_pay',
				),
				false,
			),
			'non-card method'                           => array( 'klarna', array( 'wcpay-is-platform-payment-method' => 'true' ), false ),
		);
	}

	/**
	 * @testdox Only a new card checkout should be treated as a platform-created payment method, like the WooPayments extension's checkout service, and the payment method passes through: $_dataName.
	 * @dataProvider platform_payment_method_submission_provider
	 *
	 * @param string               $payment_method_id Payment method definition ID.
	 * @param array<string,string> $post              Submitted checkout fields.
	 * @param bool                 $expected          Expected platform flag.
	 */
	public function test_checkout_provider_data_limits_the_platform_payment_method_flag( string $payment_method_id, array $post, bool $expected ): void {
		$registry      = new WooPaymentsPaymentMethodRegistry();
		$original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Saved to restore the fixture.

		try {
			// A submitted payment method keeps every row past the missing-payment-details refusal.
			$_POST = array_merge( $_POST, array( 'wcpay-payment-method' => 'pm_submitted' ), $post ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture.

			$context = $this->checkout_context( new WooPaymentsGateway( $registry->get( $payment_method_id ) ) );

			$this->assertSame( 'pm_submitted', $context->get_payment_method_id() );
			$this->assertSame( $expected, $context->get_provider_data()['is_platform_payment_method'] );
		} finally {
			$_POST = $original_post;
		}
	}

	/**
	 * Pay a new order through process_payment() and return the checkout context the processing service received.
	 *
	 * @param WooPaymentsGateway $gateway Gateway to pay with; it is initialized with a recording processing service.
	 * @return PaymentOperationContext
	 */
	private function checkout_context( WooPaymentsGateway $gateway ): PaymentOperationContext {
		$service = new RecordingPaymentProcessingService();
		$gateway->init( $service, new WooPaymentsProvider() );

		$gateway->process_payment( $this->create_order()->get_id() );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );

		return $service->last_checkout_context;
	}

	/**
	 * @testdox Should use the custom place-order button without ordinary fields only for payment-list wallets: $_dataName.
	 *
	 * @dataProvider provider_place_order_button_by_method
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @param bool   $wallet            Whether the method is a payment-list wallet.
	 */
	public function test_only_payment_list_wallets_use_custom_place_order_button( string $payment_method_id, bool $wallet ): void {
		$sut = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id ) );

		$this->assertSame( $wallet, $sut->has_custom_place_order_button );
		$this->assertSame( ! $wallet, $sut->has_fields );
	}

	/**
	 * Payment methods and whether they are payment-list wallets.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provider_place_order_button_by_method(): array {
		return array(
			'Apple Pay'  => array( 'apple_pay', true ),
			'Google Pay' => array( 'google_pay', true ),
			'card'       => array( 'card', false ),
		);
	}

	/**
	 * @testdox Should refresh subscription capabilities after third-party plugins finish loading.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_init_refreshes_late_loaded_subscription_capabilities(): void {
		$gateway = new WooPaymentsGateway();

		$this->assertFalse( $gateway->supports( 'subscriptions' ), 'The fixture must construct the gateway before Subscriptions is available.' );

		require_once __DIR__ . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( Fixtures\LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$gateway->handle_init();
		$gateway->handle_init();

		$expected_features = array(
			'subscriptions',
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
		);

		foreach ( $expected_features as $feature ) {
			$this->assertTrue( $gateway->supports( $feature ), "The late-loaded gateway should support {$feature}." );
			$this->assertSame( 1, count( array_keys( $gateway->supports, $feature, true ) ), "The {$feature} capability should remain idempotent." );
		}
	}

	/**
	 * @testdox The subscription supports follow the Stripe Billing toggle: on adds gateway-scheduled payments, off keeps amount and date changes (client test_maybe_init_subscriptions, test_maybe_init_subscriptions_with_stripe_billing_enabled).
	 * @dataProvider stripe_billing_subscription_supports
	 *
	 * @param string   $toggle   Stripe Billing toggle option value.
	 * @param string[] $expected Subscription supports, in the client's order.
	 */
	public function test_subscription_supports_follow_the_stripe_billing_toggle( string $toggle, array $expected ): void {
		$this->load_stripe_billing_module( $toggle );

		$gateway = new WooPaymentsGateway();

		$this->assertSame( $expected, $this->get_subscription_supports( $gateway ) );
	}

	/**
	 * @testdox A gateway built before the Stripe Billing module loads gets the toggle-on supports once init runs.
	 */
	public function test_init_replaces_supports_settled_before_the_stripe_billing_module_loaded(): void {
		$this->report_subscriptions_loaded();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );
		$gateway = new WooPaymentsGateway();
		$this->assertContains( 'subscription_amount_changes', $gateway->supports, 'The fixture must build the gateway before the module loads.' );

		$this->load_stripe_billing_module( '1' );
		$gateway->handle_init();

		$this->assertSame( self::stripe_billing_subscription_supports()['toggle on'][1], $this->get_subscription_supports( $gateway ) );
	}

	/**
	 * @testdox A successful change to a saved payment method notes the new method on the subscription; a failed one notes nothing (client `class-wc-payment-gateway-wcpay.php:1720-1745`).
	 * @testWith ["card", "completed", "Payment method is changed to: <strong>Credit card ending in 4242</strong>."]
	 *           ["link", "completed", "Payment method is changed to: <strong>Link ending in ***pper@example.com</strong>."]
	 *           ["card", "failed", ""]
	 *
	 * @param string $token_type     Saved payment method type: `card` or `link`.
	 * @param string $outcome_status Outcome of the change request.
	 * @param string $expected_note  Note expected on the subscription, empty for none.
	 */
	public function test_a_saved_method_change_notes_the_new_payment_method( string $token_type, string $outcome_status, string $expected_note ): void {
		WooCommerceSubscriptionsDoubles::load();
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, '' );
		$token        = 'link' === $token_type ? $this->create_link_token( $user_id ) : $this->create_recorded_card_token( $user_id );
		$note_count   = count( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );

		$this->process_saved_method_change( $subscription, $token, $outcome_status );

		$new_notes = array_slice( array_map( static fn( $note ) => $note->content, array_reverse( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) ) ), $note_count );
		$this->assertSame( '' === $expected_note ? array() : array( $expected_note ), $new_notes );
	}

	/**
	 * @testdox A successful change to a saved payment method fires the client hook once with the subscription and the new token; a failed one fires nothing (client `class-wc-payment-gateway-wcpay.php:1747-1757`).
	 * @testWith ["completed", 1]
	 *           ["failed", 0]
	 *
	 * @param string $outcome_status Outcome of the change request.
	 * @param int    $expected_calls How often the hook fires.
	 */
	public function test_a_saved_method_change_fires_the_changed_payment_method_hook( string $outcome_status, int $expected_calls ): void {
		WooCommerceSubscriptionsDoubles::load();
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, '' );
		$token        = $this->create_recorded_card_token( $user_id );
		$calls        = array();
		add_action(
			'woocommerce_payments_changed_subscription_payment_method',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			5
		);

		$this->process_saved_method_change( $subscription, $token, $outcome_status );

		$this->assertCount( $expected_calls, $calls );
		if ( 0 === $expected_calls ) {
			return;
		}
		$this->assertCount( 2, $calls[0], 'The hook passes the subscription and the token, as the client does.' );
		$this->assertInstanceOf( WC_Order::class, $calls[0][0] );
		$this->assertSame( $subscription->get_id(), $calls[0][0]->get_id() );
		$this->assertInstanceOf( WC_Payment_Token::class, $calls[0][1] );
		$this->assertSame( $token->get_id(), $calls[0][1]->get_id() );
	}

	/**
	 * @testdox A change to a saved payment method runs the payment with WooCommerce's payment-complete stock reduction off, and turns it back on after (client `class-wc-payment-gateway-wcpay.php:2197-2205`).
	 */
	public function test_a_saved_method_change_runs_the_payment_without_stock_reduction(): void {
		WooCommerceSubscriptionsDoubles::load();
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, '' );
		$token        = $this->create_recorded_card_token( $user_id );
		$service      = new class() extends RecordingPaymentProcessingService {
			/**
			 * What WooCommerce would decide about reducing stock while the payment runs.
			 *
			 * @var bool|null
			 */
			public $reduces_stock = null;

			/**
			 * Record the stock decision, then answer as the recording service does.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return PaymentOutcome
			 */
			public function process_checkout_outcome( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				$this->reduces_stock = (bool) apply_filters( 'woocommerce_payment_complete_reduce_order_stock', true, $context->get_order_id() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				return parent::process_checkout_outcome( $context, $provider );
			}
		};

		$this->process_saved_method_change( $subscription, $token, 'completed', $service );

		$this->assertFalse( $service->reduces_stock, 'The payment of a payment method change does not take stock.' );
		$this->assertTrue( (bool) apply_filters( 'woocommerce_payment_complete_reduce_order_stock', true, $subscription->get_id() ), 'Stock reduction is back on after the change.' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
	}

	/**
	 * @testdox After a Stripe-billed subscription is switched to a saved card, the Stripe Billing module charges its pending invoice and completes the failed renewal (client `class-wc-payments-subscription-service.php:658-694`).
	 */
	public function test_a_saved_method_change_charges_the_pending_stripe_billing_invoice(): void {
		$this->load_stripe_billing_module( '0' );
		$http_client  = $this->use_recorded_platform( 'charge_invoice' );
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );
		$subscription->update_meta_data( '_wcpay_pending_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$subscription->save();
		$renewal = \WC_Helper_Order::create_order( $user_id );
		$renewal->update_meta_data( '_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$renewal->set_status( 'failed' );
		$renewal->save();
		$token = $this->create_recorded_card_token( $user_id );

		$this->process_saved_method_change( $subscription, $token, 'completed' );

		$this->assertSame( array( 'POST /sites/4/wcpay/invoices/in_1UM1VrBzWlxcwgpPgrIwNSlu/pay' ), array_map( static fn( array $request ) => $request['method'] . ' ' . $request['path'], $http_client->requests ) );
		$this->assertSame( '', wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_pending_invoice_id', true ) );
		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertTrue( $renewal->is_paid(), 'The failed renewal is completed once Stripe collected the invoice.' );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $renewal->get_payment_tokens() ) );
	}

	/**
	 * Subscription supports per toggle value, from client 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay-subscriptions-trait.php:63-110`.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public static function stripe_billing_subscription_supports(): array {
		$base = array(
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_payment_method_change_admin',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change',
			'subscription_reactivation',
			'subscription_suspension',
			'subscriptions',
		);

		return array(
			'toggle off' => array( '0', array_merge( $base, array( 'subscription_amount_changes', 'subscription_date_changes' ) ) ),
			'toggle on'  => array( '1', array_merge( $base, array( 'gateway_scheduled_payments' ) ) ),
		);
	}

	/**
	 * @testdox Should render country-aware definition branding for a classic split gateway.
	 */
	public function test_classic_split_gateway_icon_uses_country_aware_definition_branding(): void {
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new WooPaymentsGateway( $registry->get( 'afterpay_clearpay' ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$property = new \ReflectionProperty( WooPaymentsGateway::class, 'account_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $account_service );

		$filter_calls = 0;
		$filter       = static function ( string $icon, string $gateway_id ) use ( &$filter_calls ): string {
			++$filter_calls;
			return $icon . '<span data-gateway-id="' . esc_attr( $gateway_id ) . '"></span>';
		};
		add_filter( 'woocommerce_gateway_icon', $filter, 10, 2 );

		try {
			$icon = $sut->get_icon();
		} finally {
			remove_filter( 'woocommerce_gateway_icon', $filter, 10 );
		}

		$this->assertStringContainsString( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $icon );
		$this->assertStringContainsString( 'alt="Cash App Afterpay"', $icon );
		$this->assertStringNotContainsString( '/assets/images/payment-methods/visa-color.svg', $icon );
		$this->assertStringContainsString( 'data-gateway-id="woocommerce_payments_afterpay_clearpay"', $icon );
		$this->assertSame( 1, $filter_calls );
	}

	/**
	 * @testdox Should report a connected account only when account data is cached, like the client's is_connected().
	 */
	public function test_is_connected_follows_cached_account_data(): void {
		$this->assertFalse( $this->gateway_with_account( array() )->is_connected(), 'No cached account data means no connected account.' );
		$this->assertTrue( $this->gateway_with_account( $this->sandbox_account( false, false ) )->is_connected(), 'Cached account data means a connected account.' );
	}

	/**
	 * @testdox Should report a partially onboarded account while a connected account has not submitted its details.
	 */
	public function test_is_account_partially_onboarded_matches_the_woopayments_extension(): void {
		$this->assertTrue(
			$this->gateway_with_account( $this->sandbox_account( false, false ) )->is_account_partially_onboarded(),
			'A connected account without submitted details is partially onboarded.'
		);
		$this->assertFalse(
			$this->gateway_with_account( $this->sandbox_account( true, true ) )->is_account_partially_onboarded(),
			'A connected account with submitted details is fully onboarded.'
		);
		$this->assertFalse(
			$this->gateway_with_account( array() )->is_account_partially_onboarded(),
			'Without an account there is no partial onboarding.'
		);
	}

	/**
	 * @testdox Should need setup without an account, with incomplete account status, or while payments are disabled.
	 */
	public function test_needs_setup_matches_the_woopayments_extension(): void {
		$without_status = $this->sandbox_account( true, true );
		unset( $without_status['status'] );

		$this->assertTrue( $this->gateway_with_account( array() )->needs_setup(), 'No account needs setup.' );
		$this->assertTrue( $this->gateway_with_account( $this->sandbox_account( false, false ) )->needs_setup(), 'Disabled payments need setup.' );
		$this->assertTrue( $this->gateway_with_account( $without_status )->needs_setup(), 'Account data without a status needs setup.' );
		$this->assertFalse( $this->gateway_with_account( $this->sandbox_account( true, true ) )->needs_setup(), 'An account with payments enabled does not need setup.' );
	}

	/**
	 * Build a card gateway that reads the given cached account data.
	 *
	 * @param array $account_data Cached account data.
	 * @return WooPaymentsGateway
	 */
	private function gateway_with_account( array $account_data ): WooPaymentsGateway {
		$sut             = new WooPaymentsGateway();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );

		$property = new \ReflectionProperty( WooPaymentsGateway::class, 'account_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $account_service );

		return $sut;
	}

	/**
	 * Build cached data for a sandbox account.
	 *
	 * @param bool $details_submitted Whether the account details were submitted.
	 * @param bool $payments_enabled  Whether the account can accept payments.
	 * @return array
	 */
	private function sandbox_account( bool $details_submitted, bool $payments_enabled ): array {
		return array(
			'account_id'        => 'acct_test123',
			'status'            => $payments_enabled ? 'complete' : 'restricted',
			'is_live'           => false,
			'is_test_drive'     => false,
			'details_submitted' => $details_submitted,
			'payments_enabled'  => $payments_enabled,
		);
	}

	/**
	 * Report WooCommerce Subscriptions as loaded to the Stripe Billing module and the gateway's subscription supports, which
	 * ask LegacyProxy. The mock is reset after every test.
	 */
	private function report_subscriptions_loaded(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, array( 'WC_Subscriptions', 'WC_Subscriptions_Core_Plugin' ), true ) || class_exists( $class_name, ...$args ),
			)
		);
	}

	/**
	 * Load WooCommerce Subscriptions and the Stripe Billing module with the given toggle, while native owns payments.
	 *
	 * @param string $toggle Stripe Billing toggle option value.
	 * @return WooPaymentsStripeBillingModule
	 */
	private function load_stripe_billing_module( string $toggle ): WooPaymentsStripeBillingModule {
		$this->report_subscriptions_loaded();
		WooCommerceSubscriptionsDoubles::load();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, $toggle );

		$module = new WooPaymentsStripeBillingModule();
		$module->register();
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		return $module;
	}

	/**
	 * Get the gateway's subscription supports, in the gateway's order.
	 *
	 * @param WooPaymentsGateway $gateway Gateway.
	 * @return string[]
	 */
	private function get_subscription_supports( WooPaymentsGateway $gateway ): array {
		$all = array_merge( self::stripe_billing_subscription_supports()['toggle off'][1], array( 'gateway_scheduled_payments' ) );

		return array_values( array_intersect( $gateway->supports, $all ) );
	}

	/**
	 * Create a WooPayments subscription for a customer, billed by Stripe Billing when it has a Stripe subscription ID.
	 *
	 * @param int    $user_id               Customer user ID.
	 * @param string $wcpay_subscription_id Stripe subscription ID, empty for a tokenized subscription.
	 * @return SubscriptionDouble
	 */
	private function create_subscription( int $user_id, string $wcpay_subscription_id ): SubscriptionDouble {
		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_customer_id( $user_id );
		if ( '' !== $wcpay_subscription_id ) {
			$subscription->update_meta_data( '_wcpay_subscription_id', $wcpay_subscription_id );
		}
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		return $subscription;
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
			$container->get( WooPaymentsApiClient::class ),
			$container->get( WooPaymentsCustomerService::class ),
			$container->get( WooPaymentsAccountService::class ),
			$container->get( WooPaymentsOrderDataService::class ),
			$container->get( WooPaymentsPaymentMethodRegistry::class )
		);

		return $token_service;
	}

	/**
	 * Create a saved card for a customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return \WC_Payment_Token_CC
	 */
	private function create_recorded_card_token( int $user_id ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2034' );
		$token->save();

		return $token;
	}

	/**
	 * Create a saved Link payment method for a customer, with Link enabled on the store.
	 *
	 * @param int $user_id Customer user ID.
	 * @return WooPaymentsLinkToken
	 */
	private function create_link_token( int $user_id ): WooPaymentsLinkToken {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) ) );
		add_filter( 'woocommerce_payment_token_class', array( new WooPaymentsTokenClassMapController(), 'handle_woocommerce_payment_token_class' ), 10, 2 );

		$token = new WooPaymentsLinkToken();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPLinkT63' );
		$token->set_email( 'shopper@example.com' );
		$token->save();

		return $token;
	}

	/**
	 * Send a customer's request to switch a subscription to a saved payment method through the gateway.
	 *
	 * @param WC_Order                               $subscription   Subscription.
	 * @param WC_Payment_Token                       $token          Saved payment method picked.
	 * @param string                                 $outcome_status Outcome the payment processing answers with.
	 * @param RecordingPaymentProcessingService|null $service        Payment processing to use, a plain recording one when null.
	 */
	private function process_saved_method_change( WC_Order $subscription, WC_Payment_Token $token, string $outcome_status, ?RecordingPaymentProcessingService $service = null ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		wp_set_current_user( $subscription->get_customer_id() );
		$_POST['_wcsnonce']                             = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']            = (string) $subscription->get_id();
		$_POST['wc-woocommerce_payments-payment-token'] = (string) $token->get_id();

		$service                   = $service ?? new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( $outcome_status, 'seti_1UM1VrBzWlxcwgpPChgT63' );
		$gateway                   = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, $this->create_unhooked_token_service() );

		$gateway->process_payment( $subscription->get_id() );
	}

	/**
	 * Answer the Stripe Billing module's platform requests with a recorded response, on the account and blog it was recorded on.
	 *
	 * @param string $pair Entry name in `Fixtures/platform-stripe-billing-api.json`.
	 * @return FakeWooPaymentsHttpClient
	 */
	private function use_recorded_platform( string $pair ): FakeWooPaymentsHttpClient {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-stripe-billing-api.json' ), true );
		$entries   = array_values( array_filter( array_merge( $recording['entries'], $recording['supporting_entries'] ?? array() ), static fn( array $entry ) => $pair === $entry['pair'] ) );
		$this->assertNotEmpty( $entries, "Fixture entry $pair is missing." );

		$http_client              = new FakeWooPaymentsHttpClient();
		$http_client->blog_id     = 4;
		$http_client->responses[] = array(
			'response' => array( 'code' => (int) $entries[0]['response']['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $entries[0]['response']['body'] ),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( 'acct_1TrY2nBzWlxcwgpP' );
		$account_service->method( 'get_gateway_setting' )->willReturn( '' );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( WooPaymentsTransportLog::class ) );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		wc_get_container()->replace( StripeBillingApi::class, $api );

		return $http_client;
	}
}
