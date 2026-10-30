<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AppearanceStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\BlocksDataExtractor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Tests for the WooPaymentsCheckoutAssets class.
 */
class WooPaymentsCheckoutAssetsTest extends WC_Unit_Test_Case {

	/**
	 * Card gateway support features passed to the config builders where the test does not exercise them.
	 */
	private const CARD_SUPPORTS = array( 'products' );

	/**
	 * Connected account A's cached data, for the account service double the test can switch.
	 */
	private const SWITCHABLE_ACCOUNT_A = array(
		'account_id'           => 'acct_A',
		'live_publishable_key' => 'pk_live_A',
		'test_publishable_key' => 'pk_test_A',
		'payments_enabled'     => true,
		'details_submitted'    => true,
		'country'              => 'US',
	);

	/**
	 * Connected account B's cached data.
	 */
	private const SWITCHABLE_ACCOUNT_B = array(
		'account_id'           => 'acct_B',
		'live_publishable_key' => 'pk_live_B',
		'test_publishable_key' => 'pk_test_B',
		'payments_enabled'     => true,
		'details_submitted'    => true,
		'country'              => 'US',
	);

	/**
	 * @testdox Should bootstrap payment-list wallets when no ordinary WooPayments fields render.
	 */
	public function test_after_checkout_form_bootstraps_payment_list_wallets_without_card_fields(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'upe_enabled_payment_method_ids'      => array( 'apple_pay', 'google_pay' ),
			)
		);
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		try {
			$gateway = $this->create_card_gateway( $sut );
			ob_start();
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
			$output = (string) ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
		$features = $this->get_localized_core_checkout_config( $script_data )['features'];
		$this->assertSame( $gateway->supports, $features );
		$this->assertContains( 'refunds', $features );
		$this->assertContains( 'tokenization', $features );
	}

	/**
	 * @testdox Should localize no checkout config from the fallback while the WooPayments extension owns payments.
	 */
	public function test_after_checkout_form_fallback_does_nothing_when_builtin_does_not_own_payments(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$sut = new WooPaymentsCheckoutAssets();
		$sut->init( $this->create_account_service( true ), $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		try {
			$this->create_card_gateway( $sut );
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		}

		$this->assertFalse( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringNotContainsString( 'wcpay_core_checkout_config', (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' ) );
	}

	/**
	 * @testdox Should bootstrap payment-list wallets on the classic order-pay form when card fields do not render.
	 */
	public function test_order_pay_before_payment_bootstraps_payment_list_wallets_without_card_fields(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'USD' );
		$order->set_total( '12.34' );
		$order->save();
		wp_set_current_user( $customer_id );
		set_query_var( 'order-pay', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'upe_enabled_payment_method_ids'      => array( 'apple_pay', 'google_pay' ),
			)
		);
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		try {
			$gateway = $this->create_card_gateway( $sut );
			ob_start();
			/** This action is documented in templates/checkout/form-pay.php */
			do_action( 'woocommerce_pay_order_before_payment' );
			$output = (string) ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertSame( $gateway->supports, $this->get_localized_core_checkout_config( $script_data )['features'] );
		$this->assertStringContainsString( '"isOrderPay":"1"', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
	}

	/**
	 * @testdox Should not relocalize base checkout config after ordinary card fields render.
	 */
	public function test_after_checkout_form_does_not_duplicate_rendered_card_config(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$account_service = $this->create_account_service( true );
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		try {
			$gateway = $this->create_card_gateway( $sut );
			ob_start();
			$gateway->form();
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
			ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( 1, substr_count( $script_data, 'var wcpay_core_checkout_config = ' ) );
	}

	/**
	 * @testdox On $label, the payment fields config filter runs $expected_on_fields time(s) for the fields and $expected_after_form time(s) after the checkout form.
	 *
	 * The fallback on woocommerce_after_checkout_form checks whether the card config is already localized before it
	 * builds, so the filter runs once per classic checkout page whether or not the card fields rendered.
	 *
	 * @testWith ["a checkout with card fields", true, 1, 0]
	 *           ["a checkout without WooPayments fields", false, 0, 1]
	 *
	 * @param string $label               Case description.
	 * @param bool   $render_fields       Whether the card gateway renders its payment fields.
	 * @param int    $expected_on_fields  Filter calls while the fields render.
	 * @param int    $expected_after_form Filter calls on woocommerce_after_checkout_form.
	 */
	public function test_payment_fields_config_filter_runs_once_per_checkout_render( string $label, bool $render_fields, int $expected_on_fields, int $expected_after_form ): void {
		unset( $label );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$sut = new WooPaymentsCheckoutAssets();
		$sut->init( $this->create_account_service( true ), $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$filtered = 0;
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( $config ) use ( &$filtered ) {
				++$filtered;
				return $config;
			}
		);

		try {
			$gateway = $this->create_card_gateway( $sut );
			ob_start();
			if ( $render_fields ) {
				$gateway->form();
			}
			$on_fields = $filtered;
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
			ob_end_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		}

		$this->assertSame( $expected_on_fields, $on_fields, 'Filter calls while the payment fields render.' );
		$this->assertSame( $expected_after_form, $filtered - $on_fields, 'Filter calls on woocommerce_after_checkout_form.' );
	}

	/**
	 * @testdox A kept checkout assets service builds the classic config again when $change.
	 *
	 * The service can outlive a request (a persistent worker, a long-running CLI process), and run_in_test_mode_context()
	 * changes the mode within one, so the memo's key holds the account, the effective mode and the change-payment flag.
	 *
	 * @testWith ["the account switches to test mode"]
	 *           ["another account connects"]
	 *           ["the page turns from an empty-cart checkout into a subscription's change payment method page"]
	 *
	 * @param string $change What changes between the two renders.
	 */
	public function test_kept_service_rebuilds_the_classic_config_when_a_key_input_changes( string $change ): void {
		$account_data = self::SWITCHABLE_ACCOUNT_A;
		$account      = $this->create_switchable_account_service( $account_data );
		$sut          = new WooPaymentsCheckoutAssets();
		$sut->init( $account, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$filtered = 0;
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( $config ) use ( &$filtered ) {
				++$filtered;
				return $config;
			}
		);
		$first = $sut->get_payment_fields_config( self::CARD_SUPPORTS );
		$this->assertSame( 'pk_live_A', $first['publishableKey'] );
		$this->assertArrayNotHasKey( 'isChangingPayment', $first );
		$filtered = 0;

		global $wp;
		$second = array();
		try {
			if ( 'the account switches to test mode' === $change ) {
				$account->run_in_test_mode_context(
					true,
					static function () use ( $sut, &$second ): void {
						$second = $sut->get_payment_fields_config( self::CARD_SUPPORTS );
					}
				);
			} elseif ( 'another account connects' === $change ) {
				$account_data = self::SWITCHABLE_ACCOUNT_B;
				$second       = $sut->get_payment_fields_config( self::CARD_SUPPORTS );
			} else {
				$this->report_subscriptions_loaded();
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
				$wp->query_vars['order-pay']                                  = 123;
				$_GET['change_payment_method']                                = '123';
				$second = $sut->get_payment_fields_config( self::CARD_SUPPORTS );
			}
		} finally {
			unset( $wp->query_vars['order-pay'] );
		}

		if ( 'the account switches to test mode' === $change ) {
			$this->assertSame( 'pk_test_A', $second['publishableKey'] );
			$this->assertTrue( $second['testMode'] );
		} elseif ( 'another account connects' === $change ) {
			$this->assertSame( 'acct_B', $second['accountId'] );
			$this->assertSame( 'pk_live_B', $second['publishableKey'] );
		} else {
			$this->assertSame( 0, $filtered, 'A change payment method config skips the wcpay_payment_fields_js_config filter.' );
			$this->assertTrue( $second['isChangingPayment'] ?? false, 'The change payment method page gets its own config.' );
		}
	}

	/**
	 * @testdox On a kept checkout assets service, the next request by $label builds the classic config once, with none of the request before's session values.
	 *
	 * A persistent worker or a long-running CLI process can keep the service. The base carries values bound to the
	 * shopper's session and the time, which no key can hold, so the service clears its memos on each request's init.
	 *
	 * @testWith ["another guest", false]
	 *           ["another guest, in test mode", true]
	 *
	 * @param string $label     Who makes the next request.
	 * @param bool   $test_mode Whether the next request runs in test mode.
	 */
	public function test_next_request_rebuilds_the_classic_config_without_the_last_session_values( string $label, bool $test_mode ): void {
		unset( $label );
		$account_data = array_merge(
			self::SWITCHABLE_ACCOUNT_A,
			array(
				'fraud_services' => array(
					'sift' => array(
						'beacon_key'         => 'live-beacon',
						'sandbox_beacon_key' => 'sandbox-beacon',
					),
				),
			)
		);
		$account      = $this->create_switchable_account_service( $account_data );
		// The WooPay config carries the WooPay session nonce, the Store API cart token of the shopper's session and the
		// WooPay session email (WooPay/SessionService.php:721, :741-747, :351-358).
		$woopay = $this->getMockBuilder( SessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config' ) )
			->getMock();
		$woopay->method( 'get_woopay_frontend_config' )->willReturnCallback(
			static fn(): array => array(
				'woopaySessionNonce'       => wp_create_nonce( 'woopay_session_nonce' ),
				'woopayMinimumSessionData' => array( 'store_api_token' => CartTokenUtils::get_cart_token( (string) WC()->session->get_customer_id() ) ),
				'woopaySessionEmail'       => (string) WC()->session->get( 'woopay_session_email', '' ),
			)
		);
		$sut = new WooPaymentsCheckoutAssets();
		$sut->init( $account, $woopay, $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$fraud_service = new WooPaymentsFraudService();
		$fraud_service->init( $account, $this->createMock( WooPaymentsCustomerService::class ), new WooPaymentsSessionService(), $this->createMock( Client::class ) );
		$property = new \ReflectionProperty( WooPaymentsCheckoutAssets::class, 'fraud_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $fraud_service );
		$builds = 0;
		add_filter(
			'wc_payments_account_id_for_intent_confirmation',
			static function ( $value ) use ( &$builds ) {
				++$builds;
				return $value;
			}
		);
		$registry = new PaymentMethodRegistry();
		$request  = static function () use ( $sut, $registry ): array {
			$card = $sut->get_payment_fields_config( self::CARD_SUPPORTS );
			$sut->get_payment_fields_config( self::CARD_SUPPORTS, $registry->get( 'klarna' ) );

			return $card;
		};
		// Only the service's own init callback runs; the hook snapshot restores the others when the test ends.
		remove_all_actions( 'init' );
		$sut->register();
		wp_set_current_user( 0 );
		$original_session = WC()->session;

		try {
			WC()->session = $this->create_guest_session( 'guest-a', 'a@example.com' );
			$first        = $request();
			$this->assertSame( 1, $builds, 'The first request builds the classic config once.' );

			WC()->session = $this->create_guest_session( 'guest-b', 'b@example.com' );
			// Half a nonce life later.
			add_filter( 'nonce_life', static fn(): int => HOUR_IN_SECONDS );
			if ( $test_mode ) {
				add_filter( 'wcpay_test_mode', '__return_true' );
			}
			/** This action is documented in wp-settings.php */
			do_action( 'init' );
			$second              = $request();
			$expected_nonce      = wp_create_nonce( 'wcpay_create_setup_intent_nonce' );
			$expected_session_id = ( new WooPaymentsSessionService() )->get_sift_session_id();
		} finally {
			WC()->session = $original_session;
		}

		$this->assertNotSame( $first['createSetupIntentNonce'], $second['createSetupIntentNonce'] );
		$this->assertSame( $expected_nonce, $second['createSetupIntentNonce'], 'The nonce of the next request.' );
		$this->assertSame( 'guest-b', CartTokenUtils::get_cart_token_payload( $second['woopayMinimumSessionData']['store_api_token'] )['user_id'], 'The cart token of the next guest.' );
		$this->assertNotSame( $first['fraudServices']['sift']['session_id'], $second['fraudServices']['sift']['session_id'] );
		$this->assertSame( $expected_session_id, $second['fraudServices']['sift']['session_id'], 'The Sift session of the next guest.' );
		$this->assertSame( 'b@example.com', $second['woopaySessionEmail'], 'The WooPay email of the next guest.' );
		$this->assertSame( $test_mode, $second['testMode'] );
		$this->assertSame( 2, $builds, 'The next request builds the classic config once.' );
	}

	/**
	 * Create a guest's WooCommerce session with a customer ID and a WooPay session email.
	 *
	 * @param string $customer_id Session customer ID.
	 * @param string $email       WooPay session email.
	 * @return \WC_Session
	 */
	private function create_guest_session( string $customer_id, string $email ): \WC_Session {
		$session = $this->create_session();
		$session->set( 'woopay_session_email', $email );
		$property = new \ReflectionProperty( \WC_Session::class, '_customer_id' );
		$property->setAccessible( true );
		$property->setValue( $session, $customer_id );

		return $session;
	}

	/**
	 * Create an account service double over the given cached account data; the test can swap the data between calls.
	 * Its mode, publishable key, account ID and payment readiness come from the real methods.
	 *
	 * @param array<string,mixed> $account_data Cached account data, read by reference.
	 * @return WooPaymentsAccountService
	 */
	private function create_switchable_account_service( array &$account_data ): WooPaymentsAccountService {
		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_onboarding_enabled' ) )
			->getMock();
		$account->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( &$account_data ): array {
				return $account_data;
			}
		);
		$account->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				unset( $key );
				return $fallback;
			}
		);
		$account->method( 'is_test_mode_onboarding_enabled' )->willReturn( false );

		return $account;
	}

	/**
	 * Build the card gateway on the given checkout assets, with saved cards on, so it adds its classic checkout fallback hooks.
	 *
	 * @param WooPaymentsCheckoutAssets $assets Checkout assets under test.
	 * @return WooPaymentsGateway
	 */
	private function create_card_gateway( WooPaymentsCheckoutAssets $assets ): WooPaymentsGateway {
		$this->reset_classic_checkout_fallback_hooks_flag();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		update_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings', array( 'saved_cards' => 'yes' ) );

		$provider = $this->createMock( WooPaymentsProvider::class );
		$provider->method( 'can_process_payments' )->willReturn( true );

		$gateway = new WooPaymentsGateway();
		$gateway->init( $this->createMock( PaymentProcessingService::class ), $provider, $assets );

		return $gateway;
	}

	/**
	 * Render one gateway's classic payment fields as the gateway does: build the config, then enqueue the assets with it.
	 *
	 * @param WooPaymentsCheckoutAssets    $assets     Checkout assets under test.
	 * @param string[]                     $supports   Card gateway support features.
	 * @param PaymentMethodDefinition|null $definition Payment method definition.
	 * @return array<string,mixed> The config the gateway prints in its form.
	 */
	private static function render_payment_fields( WooPaymentsCheckoutAssets $assets, array $supports, ?PaymentMethodDefinition $definition = null ): array {
		$config = $assets->get_payment_fields_config( $supports, $definition );
		$assets->enqueue_payment_fields_assets( $config, $supports );

		return $config;
	}

	/**
	 * Let the next card gateway add its classic checkout fallback hooks again.
	 */
	private function reset_classic_checkout_fallback_hooks_flag(): void {
		$hooks_added = new \ReflectionProperty( WooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$hooks_added->setAccessible( true );
		$hooks_added->setValue( null, false );
	}

	/**
	 * Decode the `wcpay_core_checkout_config` object localized on the classic checkout script.
	 *
	 * @param string $script_data Localized script data.
	 * @return array<string,mixed>
	 */
	private function get_localized_core_checkout_config( string $script_data ): array {
		$this->assertSame( 1, preg_match( '/^var wcpay_core_checkout_config = (.+);$/m', $script_data, $matches ) );

		return json_decode( $matches[1], true );
	}

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
		// A test of another class can leave the checkout script registered with its localized config.
		$this->reset_checkout_assets();
		// A post or query left by an earlier test would give the express checkout handler a product or cart context.
		unset( $GLOBALS['post'] );
		$GLOBALS['wp_query']     = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		// The fraud service falls back to the platform's public fraud-services
		// config when the account payload carries none; unit tests must never
		// reach the real platform.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, string $url ) {
				if ( false !== strpos( $url, 'public-api.wordpress.com' ) ) {
					return new \WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' );
				}

				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_frontend_surface_state();
		unset( $_GET['change_payment_method'], $_GET['pay_for_order'], $_GET['key'], $_POST['email'], $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] );
		delete_option( '_wcpay_feature_woopay_express_checkout' );
		delete_option( 'woocommerce_woocommerce_payments_apple_pay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_google_pay_settings' );
		remove_all_filters( 'wcpay_woopay_enabled' );
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		remove_all_filters( 'wcpay_payment_fields_js_config' );
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'woocommerce_woopayments_public_fraud_services' );
		$this->reset_checkout_assets();
		wp_set_current_user( 0 );
		$this->reset_classic_checkout_fallback_hooks_flag();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		parent::tearDown();
	}

	/**
	 * Dequeue and deregister the checkout scripts and styles, with their localized config.
	 */
	private function reset_checkout_assets(): void {
		wp_dequeue_script( 'wc-woopayments-checkout' );
		wp_deregister_script( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
		wp_deregister_script( 'wc-woopayments-common' );
		wp_deregister_script( 'wc-woopayments-woopay-common' );
		wp_dequeue_style( 'wc-woopayments-checkout' );
		wp_deregister_style( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wcpay-fraud-prevention-token' );
		wp_deregister_script( 'wcpay-fraud-prevention-token' );
		wp_dequeue_script( 'woocommerce-tokenization-form' );
		wp_deregister_script( 'woocommerce-tokenization-form' );
	}

	/**
	 * Report the WooCommerce Subscriptions core library as loaded, through the LegacyProxy check the checkout assets' subscription
	 * policy uses (the mock is reset after every test), and define the shared `wcs_is_subscription()` double.
	 */
	private function report_subscriptions_loaded(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		wp_reset_postdata();
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * @testdox Should keep the unfiltered checkout config when a wcpay_payment_fields_js_config callback returns no array.
	 */
	public function test_get_payment_fields_js_config_ignores_a_non_array_filter_result(): void {

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		add_filter( 'wcpay_payment_fields_js_config', '__return_null' );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'woocommerce_payments', $config['gatewayId'], 'The payment fields must still get their config.' );
	}

	/**
	 * The classic card form names its brand logos and brand popover for screen readers (woopayments-checkout.js:1893,
	 * :1966); the labels come from the config so they are translated.
	 *
	 * @testdox Should send translated labels for the card brand logos and popover.
	 */
	public function test_get_payment_fields_js_config_translates_card_brand_labels(): void {

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$translate = static function ( $translation, $text ) {
			return 'Supported credit card brands' === $text || 'Show all supported credit card brands' === $text ? 'translated: ' . $text : $translation;
		};
		add_filter( 'gettext', $translate, 10, 2 );

		try {
			$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( 'translated: Supported credit card brands', $config['cardBrandPopoverLabel'] ?? null );
		$this->assertSame( 'translated: Show all supported credit card brands', $config['cardBrandLogosLabel'] ?? null );
	}

	/**
	 * @testdox Should preserve the card checkout config shape and filter it through wcpay_payment_fields_js_config.
	 */
	public function test_get_payment_fields_js_config_preserves_card_checkout_shape(): void {
		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'pk_test_123', $config['publishableKey'] );
		$this->assertSame( 'acct_123', $config['accountId'] );
		$this->assertSame( 'woocommerce_payments', $config['gatewayId'] );
		$this->assertTrue( $config['testMode'] );
		$this->assertSame( 'yes', $config['filtered'] );
		$this->assertArrayHasKey( 'paymentMethodsConfig', $config );
		$this->assertSame( 'Card', $config['paymentMethodsConfig']['card']['title'] );
		$this->assertSame( 'Card', $config['paymentMethodsConfig']['card']['label'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
		$this->assertSame( 'visa', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['id'] );
		$this->assertSame( 'Visa', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['alt'] );
		$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['src'] );
		$this->assertStringContainsString( '4000 0064 2000 0001', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'js-woopayments-copy-test-number', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringNotContainsString( 'aria-label', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'role="status"', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'data-copied-message="Copied to clipboard."', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'testing guide', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertArrayHasKey( 'enabledBillingFields', $config );
		$this->assertArrayHasKey( 'currency', $config );
		$this->assertArrayHasKey( 'cartTotal', $config );
		$this->assertFalse( $config['cartContainsSubscription'] );
		$this->assertSame( 'styles-v1', $config['stylesCacheVersion'] );
		$this->assertSame( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION, $config['wcpayVersionNumber'] );
		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		// The order-status nonce travels only in the confirmation hash of a checkout that needs authentication, as on
		// client 11.1.0 (class-wc-payment-gateway-wcpay.php:2114, :2421); no script reads it from this config.
		$this->assertArrayNotHasKey( 'updateOrderStatusNonce', $config );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertSame( 12345, $config['woopayMerchantId'] );
		$this->assertArrayHasKey( 'initWooPayNonce', $config );
		$this->assertArrayNotHasKey( 'woopayInitNonce', $config );
		$this->assertArrayHasKey( 'woopaySessionNonce', $config );
		$this->assertArrayHasKey( 'woopaySignatureNonce', $config );
		$this->assertSame( array( 'encrypted' => 'minimum' ), $config['woopayMinimumSessionData'] );
		$this->assertSame( 'There was a problem processing the payment. Please check your email inbox and refresh the page to try again.', $config['genericErrorMessage'] );
		// Client 11.2.0 client/checkout/utils/fingerprint.js:12-15 (FINGERPRINT_GENERIC_ERROR).
		$this->assertSame( 'An error was encountered when preparing the payment form. Please try again later.', $config['fingerprintErrorMessage'] );
		// With no account fraud-services config and the platform unreachable,
		// the prepared config falls back to the bare stripe default.
		$this->assertSame( array( 'stripe' => array() ), $config['fraudServices'] );
		$this->assertContains( 'products', $config['features'] );
		$this->assertFalse( $config['isPreview'] );
		$this->assertFalse( $config['isShortcodeCheckout'] );
		$this->assertSame( '', $config['accountIdForIntentConfirmation'] );
		$this->assertSame( '', $config['icon'] );
		$this->assertFalse( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWoopayFirstPartyAuthEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
		$this->assertFalse( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertFalse( $config['forceNetworkSavedCards'] );
		$this->assertArrayHasKey( 'platformTrackerNonce', $config );
		$this->assertSame(
			array(
				'type'    => 'default',
				'theme'   => 'dark',
				'height'  => '48',
				'radius'  => '4',
				'size'    => 'default',
				'context' => 'checkout',
			),
			$config['woopayButton']
		);
		$this->assertArrayHasKey( 'woopayButtonNonce', $config );
		$this->assertArrayHasKey( 'addToCartNonce', $config );
		$this->assertTrue( $config['shouldShowWooPayButton'] );
		$this->assertSame( 'shopper@example.com', $config['woopaySessionEmail'] );
		$this->assertTrue( $config['woopayIsCountryAvailable'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertSame( array(), $config['woopayFontRules'] );
		$this->assertSame( 'Securely save my information for 1-click checkout', $config['woopaySaveUserLabel'] );
		$this->assertSame( 'Mobile phone number', $config['woopayPhoneLabel'] );
		$this->assertTrue( $config['isShopperTrackingEnabled'] );
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $config['tracksUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['tracksRestNonce'], 'wp_rest' ) );
	}

	/**
	 * The WooPay objects read these keys on pages that have no card config, so building them reads only what the keys need.
	 * With the real tracking controller over an account stub that writes nothing, the account data is read once (for the
	 * tracking flag), no option is written, and nothing builds the customer data, the styles version or the WooPay config,
	 * or touches the session. The real account accessor can still write (a due cache refresh, or the dev-mode onboarding
	 * flag); that is the account service's own behaviour, covered by its tests.
	 *
	 * @testdox The shared script config carries the eight keys both checkout scripts read and reads only what they need over a non-writing account stub, test mode $test_mode.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $test_mode Whether the account is in test mode.
	 */
	public function test_get_shared_script_config_reads_only_what_its_keys_need( bool $test_mode ): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$account_reads   = 0;
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_cached_account_data', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				return 'enabled' === $key ? 'yes' : $fallback;
			}
		);
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( &$account_reads ): array {
				++$account_reads;
				return array( 'country' => 'US' );
			}
		);
		$tracking_controller = new WooPaymentsFrontendTrackingController();
		$tracking_controller->init( $account_service );
		$woopay_service = $this->createMock( SessionService::class );
		$woopay_service->expects( $this->never() )->method( 'get_woopay_frontend_config' );
		$styles_service = $this->createMock( WooPaymentsFrontendStylesService::class );
		$styles_service->expects( $this->never() )->method( 'get_or_create_styles_cache_version' );
		$customer_service = $this->createMock( WooPaymentsCustomerService::class );
		$customer_service->expects( $this->never() )->method( 'get_prepared_customer_data' );
		$sut = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $woopay_service, $this->create_woopay_eligibility( true ), $styles_service, $tracking_controller, new PaymentMethodRegistry(), null, $customer_service );

		$option_writes = 0;
		$count_write   = static function () use ( &$option_writes ): void {
			++$option_writes;
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, $count_write );
		}
		$session          = new class() extends \WC_Session {
			/**
			 * Number of session reads and writes.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Count a session read.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				unset( $key );
				++$this->calls;
				return $default_value;
			}

			/**
			 * Count a session write.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				unset( $key, $value );
				++$this->calls;
			}
		};
		$original_session = WC()->session;
		WC()->session     = $session;
		try {
			$config = $sut->get_shared_script_config();
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( array( 'ajaxUrl', 'platformTrackerNonce', 'tracksUrl', 'tracksRestNonce', 'isShopperTrackingEnabled', 'woopayHost', 'testMode', 'wcpayVersionNumber' ), array_keys( $config ), 'The keys, in the order the WooPay object carries them.' );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['platformTrackerNonce'], 'platform_tracks_nonce' ) );
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $config['tracksUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['tracksRestNonce'], 'wp_rest' ) );
		$this->assertTrue( $config['isShopperTrackingEnabled'] );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertSame( $test_mode, $config['testMode'], 'The express OTP iframe opens in the account mode.' );
		$this->assertSame( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION, $config['wcpayVersionNumber'] );
		$this->assertSame( 1, $account_reads, 'The account data is read once, for the tracking flag.' );
		$this->assertSame( 0, $option_writes, 'No option is written by the method itself.' );
		$this->assertSame( 0, $session->calls, 'The session is not read or written.' );
	}

	/**
	 * The light direct-checkout config emits only these four keys, so building them must not compute the tracking keys.
	 *
	 * @testdox The connection script config carries the four keys the light WooPay config reads and reads no tracking policy.
	 */
	public function test_get_connection_script_config_reads_no_tracking_policy(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_cached_account_data', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->expects( $this->never() )->method( 'get_cached_account_data' );
		$account_service->expects( $this->never() )->method( 'get_gateway_setting' );
		$tracking_controller = new WooPaymentsFrontendTrackingController();
		$tracking_controller->init( $account_service );
		$sut = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->createMock( SessionService::class ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $tracking_controller, new PaymentMethodRegistry() );
		$tracking_reads = 0;
		$count_read     = static function ( $value ) use ( &$tracking_reads ) {
			++$tracking_reads;
			return $value;
		};
		add_filter( 'wcpay_shopper_tracking_enabled', $count_read );
		add_filter( 'pre_option_woocommerce_allow_tracking', $count_read );

		$config = $sut->get_connection_script_config();

		$this->assertSame( array( 'ajaxUrl', 'platformTrackerNonce', 'woopayHost', 'testMode' ), array_keys( $config ) );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['platformTrackerNonce'], 'platform_tracks_nonce' ) );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertTrue( $config['testMode'] );
		$this->assertSame( 0, $tracking_reads, 'No tracking option read and no tracking filter.' );
	}

	/**
	 * The WooPay config merged into the classic and Blocks checkout configs carries the store-level tracking flag, as client
	 * 11.2.0's payment fields config does (class-woopay-tracker.php:663-665, should_enable_tracking( false, true )).
	 *
	 * @testdox Should keep the shopper tracking flag in the checkout configs with WooPay off, tracking option $allow_tracking.
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $allow_tracking The woocommerce_allow_tracking option.
	 * @param bool   $expected       Expected isShopperTrackingEnabled.
	 */
	public function test_get_payment_fields_js_config_keeps_the_shopper_tracking_flag_with_woopay_off( string $allow_tracking, bool $expected ): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', $allow_tracking );
		$account_service     = $this->create_account_service(
			true,
			array(
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			),
			array(
				'enabled'           => 'yes',
				'platform_checkout' => 'no',
			)
		);
		$tracking_controller = new WooPaymentsFrontendTrackingController();
		$tracking_controller->init( $account_service );
		$eligibility = new Eligibility();
		$eligibility->init( $account_service );
		$appearance_store = new AppearanceStore();
		$appearance_store->init( new WooPaymentsFrontendStylesService() );
		$woopay_service = new SessionService();
		$woopay_service->init( $account_service, $eligibility, $appearance_store, $tracking_controller, wc_get_container()->get( BlocksDataExtractor::class ), new AdaptedExtensions(), $this->createMock( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsLogger::class ) );
		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $woopay_service, $eligibility, $this->create_frontend_styles_service(), $tracking_controller, new PaymentMethodRegistry() );

		$config      = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$blocks_data = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertFalse( $config['isWooPayEnabled'] );
		$this->assertSame(
			array(
				'classic' => $expected,
				'blocks'  => $expected,
			),
			array(
				'classic' => $config['isShopperTrackingEnabled'],
				'blocks'  => $blocks_data['isShopperTrackingEnabled'],
			)
		);
	}

	/**
	 * @testdox Should expose change-payment state only for an order-pay subscription request without mutating it.
	 */
	public function test_get_payment_fields_js_config_exposes_subscription_change_payment_request_state(): void {
		$this->report_subscriptions_loaded();

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$this->create_account_service( true ),
			$this->create_woopay_session_service( false ),
			$this->create_woopay_eligibility( false ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry()
		);

		$filter_calls = 0;
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ) use ( &$filter_calls ): array {
				++$filter_calls;
				$config['testFilterMutation'] = true;
				return $config;
			}
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The test verifies read-only request detection and non-mutation.
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
		$_GET['change_payment_method']                                = '123';
		$original_request = $_GET;
		$not_order_pay    = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $not_order_pay );
		$this->assertTrue( $not_order_pay['testFilterMutation'] );
		$this->assertSame( $original_request, $_GET );

		global $wp;
		$wp->query_vars['order-pay'] = 456;
		unset( $_GET['change_payment_method'] );
		$missing_request = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $missing_request );
		$this->assertTrue( $missing_request['testFilterMutation'] );

		$_GET['change_payment_method'] = '456';
		$non_subscription_request      = $_GET;
		$non_subscription              = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $non_subscription );
		$this->assertTrue( $non_subscription['testFilterMutation'] );
		$this->assertSame( $non_subscription_request, $_GET );

		$_GET['change_payment_method'] = '123';
		$subscription_request          = $_GET;
		$changing_payment              = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayHasKey( 'isChangingPayment', $changing_payment );
		$this->assertTrue( $changing_payment['isChangingPayment'] );
		$this->assertArrayNotHasKey( 'testFilterMutation', $changing_payment );
		$this->assertSame( $subscription_request, $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->assertSame( 3, $filter_calls );
	}

	/**
	 * @testdox Should resolve a subscription payment-method change to the cart context, not the order behind its order-pay URL.
	 */
	public function test_get_payment_fields_js_config_change_payment_request_uses_cart_context(): void {
		$this->report_subscriptions_loaded();

		update_option( 'woocommerce_currency', 'USD' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->set_billing_country( 'BE' );
		$order->save();
		wp_set_current_user( $customer_id );

		// WooCommerce Subscriptions serves the change form at
		// order-pay/<subscription_id>?change_payment_method=<subscription_id>.
		// The endpoint detector reads `$wp->query_vars` while the context
		// reads `$wp_query`, so the request must exist in both.
		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		set_query_var( 'order-pay', $order->get_id() );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( $order->get_id() );
		$_GET['change_payment_method']                                = (string) $order->get_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context matching WooCommerce Subscriptions.

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$this->create_account_service( true ),
			$this->create_woopay_session_service( false ),
			$this->create_woopay_eligibility( false ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry()
		);

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		// A payment-method change collects no payment, so the order's 12.34 EUR
		// must not become the element's context: the checkout script derives a
		// payment-mode Payment Element from any positive `cartTotal`, where the
		// WooPayments client plugin serves this surface a setup-mode element.
		$this->assertTrue( $config['isChangingPayment'] );
		$this->assertSame( 0, $config['cartTotal'] );
		$this->assertSame( 'USD', $config['currency'] );
		$this->assertArrayNotHasKey( 'isOrderPay', $config );
		$this->assertArrayNotHasKey( 'orderId', $config );
		// A change-payment request carries no pay_for_order flag, so the client
		// plugin ships no prefilled billing details there - and neither must we:
		// the order's BE billing address stays out of the element defaults.
		$this->assertSame( array(), $config['customerData'] );
	}

	/**
	 * @testdox Should fold enabled Link configuration into the card Payment Element: $_dataName.
	 *
	 * @dataProvider provider_link_folded_into_card
	 *
	 * @param bool $explicit_card_definition Whether the config is built through the registry's card definition, as production does.
	 */
	public function test_get_payment_fields_js_config_folds_link_into_card( bool $explicit_card_definition ): void {
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$registry        = new PaymentMethodRegistry();
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), $registry );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS, $explicit_card_definition ? $registry->get( 'card' ) : null );

		$this->assertArrayHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( 'link', $config['paymentMethodsConfig']['link']['id'] );
		$this->assertTrue( $config['paymentMethodsConfig']['link']['isReusable'] );
		$this->assertSame( array( 'card', 'link' ), $config['paymentMethodTypes'] );
	}

	/**
	 * Client 11.2.0 `class-wc-payments-checkout.php:634-636` titles each checkout method for the account country, and
	 * `LinkDefinition.php:64-70`, `:152-158` name Link "Onelink" with `onelink.svg` for a GB account.
	 *
	 * @testdox Should name Link "Onelink" with its own logo in a GB account's checkout form.
	 */
	public function test_get_payment_fields_js_config_names_link_onelink_for_a_gb_account(): void {
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'GB',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$registry        = new PaymentMethodRegistry();
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), $registry );

		$link_config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS, $registry->get( 'card' ) )['paymentMethodsConfig']['link'];

		$this->assertSame( 'Onelink', $link_config['title'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/onelink.svg', $link_config['icon'] );
	}

	/**
	 * Card config paths that fold Link in.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function provider_link_folded_into_card(): array {
		return array(
			'card config'              => array( false ),
			'explicit card definition' => array( true ),
		);
	}

	/**
	 * @testdox Should offer the card alone, without Link: $_dataName.
	 *
	 * @dataProvider provider_link_kept_out_of_card
	 *
	 * @param string              $link_status      Platform status of the link_payments capability.
	 * @param bool                $has_fee          Whether the account lists a link fee.
	 * @param array<string,mixed> $gateway_settings Gateway settings.
	 * @param string              $currency         Store currency to set, or '' to keep the default.
	 */
	public function test_get_payment_fields_js_config_keeps_link_out_of_card( string $link_status, bool $has_fee, array $gateway_settings, string $currency ): void {
		if ( '' !== $currency ) {
			update_option( 'woocommerce_currency', $currency );
		}
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => $link_status,
				),
				'fees'         => $has_fee ? array( 'link' => array() ) : array( 'card' => array() ),
			),
			$gateway_settings
		);
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( array( 'card' ), array_keys( $config['paymentMethodsConfig'] ) );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * Accounts and stores where Link stays out of the card Payment Element.
	 *
	 * Link folds into card only while `link_payments` is exactly `active`, the account has a `link` fee entry, Link is a saved
	 * enabled method and Link supports the currency. The account reports capability statuses keyed by capability (client
	 * 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:4696-4717`) and fees keyed by payment method ID (`:4867`,
	 * `includes/class-wc-payments-account.php:639-641`).
	 *
	 * @return array<string,array{0:string,1:bool,2:array<string,mixed>,3:string}>
	 */
	public function provider_link_kept_out_of_card(): array {
		$card_and_link = array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) );

		return array(
			'capability inactive'         => array( 'inactive', true, $card_and_link, '' ),
			'capability pending'          => array( 'pending', true, $card_and_link, '' ),
			'capability disabled'         => array( 'disabled', true, $card_and_link, '' ),
			'no Link fee entry'           => array( 'active', false, $card_and_link, '' ),
			'no saved enabled methods'    => array( 'active', true, array(), '' ),
			'currency Link does not take' => array( 'active', true, $card_and_link, 'EUR' ),
		);
	}

	/**
	 * @testdox Should expose country-aware shopper icons for a split payment method.
	 */
	public function test_get_payment_fields_js_config_exposes_country_aware_afterpay_icons(): void {
		$account_service = $this->create_account_service(
			true,
			array( 'country' => 'US' )
		);
		$registry        = new PaymentMethodRegistry();
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), $registry );

		$config          = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS, $registry->get( 'afterpay_clearpay' ) );
		$afterpay_config = $config['paymentMethodsConfig']['afterpay_clearpay'];

		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $afterpay_config['icon'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo-dark.svg', $afterpay_config['darkIcon'] );
	}

	/**
	 * Client 11.1.0 `P24Definition.php` titles the method "Przelewy24 (P24)", ships `p24.svg` as every icon and limits shoppers
	 * to Poland; `class-upe-payment-method.php::get_countries()` (:386-397) is what checkout filters billing countries by.
	 *
	 * @testdox Should expose the P24 split gateway to checkout with the WooPayments extension's title, icon and Polish shopper rule.
	 */
	public function test_get_payment_fields_js_config_exposes_p24_like_the_woopayments_extension(): void {
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'PL',
				'capabilities' => array(
					'card_payments' => 'active',
					'p24_payments'  => 'active',
				),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'p24' ) )
		);
		$registry        = new PaymentMethodRegistry();
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), $registry );

		$p24_config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS, $registry->get( 'p24' ) )['paymentMethodsConfig']['p24'];

		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'p24', $p24_config['gatewayId'] );
		$this->assertSame( 'Przelewy24 (P24)', $p24_config['title'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/p24-color.svg', $p24_config['icon'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/p24-color.svg', $p24_config['darkIcon'] );
		$this->assertSame( array( 'PL' ), $p24_config['countries'] );
		$this->assertFalse( $p24_config['isReusable'] );
		$this->assertFalse( $p24_config['isBnpl'] );
	}

	/**
	 * @testdox Should expose enabled payment-list wallets separately from Payment Element methods.
	 */
	public function test_get_payment_fields_js_config_exposes_payment_list_wallets_with_gateway_identity(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'upe_enabled_payment_method_ids'      => array( 'card' ),
			)
		);
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertArrayNotHasKey( 'apple_pay', $config['paymentMethodsConfig'] );
		$this->assertArrayNotHasKey( 'google_pay', $config['paymentMethodsConfig'] );
		$this->assertSame( 'woocommerce_payments_apple_pay', $config['paymentListWalletsConfig']['apple_pay']['gatewayId'] );
		$this->assertSame( 'woocommerce_payments_google_pay', $config['paymentListWalletsConfig']['google_pay']['gatewayId'] );
		$this->assertTrue( $config['paymentListWalletsConfig']['apple_pay']['isExpressCheckout'] );
		$this->assertTrue( $config['paymentListWalletsConfig']['google_pay']['isExpressCheckout'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * Apple Pay and Google Pay run on the card_payments capability; the client offers a method only while it is `active`
	 * (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:908-913`).
	 *
	 * @testdox Should leave the payment-list wallets out while card payments are reported but not active.
	 */
	public function test_get_payment_fields_js_config_omits_payment_list_wallets_without_active_card_payments(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'pending' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'upe_enabled_payment_method_ids'      => array( 'card' ),
			)
		);
		$sut             = new WooPaymentsCheckoutAssets();
		$sut->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertEmpty( $config['paymentListWalletsConfig'] ?? array() );
	}

	/**
	 * @testdox Should publish a guest order's pay context only to a pay link that carries the order key.
	 *
	 * @testWith ["", false]
	 *           ["wrong_key", false]
	 *           ["order_key", true]
	 *
	 * @param string $key           The pay link's key: none, a wrong one, or the order's ("order_key").
	 * @param bool   $expects_order Whether the order's pay context is published.
	 */
	public function test_guest_order_pay_context_needs_the_order_key( string $key, bool $expects_order ): void {
		update_option( 'woocommerce_currency', 'USD' );
		// A cart with its own total, so the fallback is shown to be the cart's.
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => 10 ) )->get_id(), 1 );
		WC()->cart->calculate_totals();
		// No customer: core grants pay_for_order on such an order to every visitor (wc-user-functions.php).
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->save();
		wp_set_current_user( 0 );
		set_query_var( 'order-pay', $order->get_id() );
		if ( '' !== $key ) {
			$_GET['key'] = 'order_key' === $key ? $order->get_order_key() : $key;
		}

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$this->create_account_service(
				true,
				array(
					'country'      => 'US',
					'capabilities' => array( 'card_payments' => 'active' ),
				)
			),
			$this->create_woopay_session_service( false ),
			$this->create_woopay_eligibility( false ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry()
		);

		$config     = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$cart_total = (int) round( (float) WC()->cart->get_total( '' ) * 100 );
		WC()->cart->empty_cart();

		$this->assertTrue( current_user_can( 'pay_for_order', $order->get_id() ) );
		if ( $expects_order ) {
			$this->assertSame( $order->get_id(), $config['orderId'] );
			$this->assertSame( 'EUR', $config['currency'] );
			$this->assertSame( 1234, $config['cartTotal'] );
		} else {
			$this->assertArrayNotHasKey( 'orderId', $config );
			$this->assertArrayNotHasKey( 'isOrderPay', $config );
			$this->assertSame( 'USD', $config['currency'] );
			$this->assertNotSame( 1234, $config['cartTotal'] );
			$this->assertSame( $cart_total, $config['cartTotal'] );
		}
	}

	/**
	 * @testdox Should use one authorized order-pay context for payment configuration and eligibility.
	 */
	public function test_get_payment_fields_js_config_uses_order_pay_context(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->set_billing_country( 'BE' );
		$order->save();
		wp_set_current_user( $customer_id );
		set_query_var( 'order-pay', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$this->create_account_service(
				true,
				array(
					'country'      => 'US',
					'capabilities' => array(
						'card_payments' => 'active',
						'link_payments' => 'active',
					),
					'fees'         => array( 'link' => array() ),
				),
				array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
			),
			$this->create_woopay_session_service( false ),
			$this->create_woopay_eligibility( false ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry()
		);

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isOrderPay'] );
		$this->assertSame( $order->get_id(), $config['orderId'] );
		$this->assertSame( 'EUR', $config['currency'] );
		$this->assertSame( 1234, $config['cartTotal'] );
		$this->assertSame( 'BE', $config['customerData']['billing_country'] );
		$this->assertArrayNotHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should source add-payment-method billing details from the customer service.
	 */
	public function test_get_payment_fields_js_config_uses_customer_data_on_add_payment_method_page(): void {
		$user_id = self::factory()->user->create(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'user_email' => 'ada@example.com',
			)
		);
		update_user_meta( $user_id, 'billing_country', 'RO' );
		wp_set_current_user( $user_id );

		$my_account_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
		$this->go_to( get_permalink( $my_account_page_id ) );
		$GLOBALS['wp']->query_vars['add-payment-method'] = '';

		$account_service  = $this->create_account_service( true );
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $this->createStub( Client::class ), $account_service, new WooPaymentsSessionService() );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$account_service,
			$this->create_woopay_session_service( false ),
			$this->create_woopay_eligibility( false ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry(),
			null,
			$customer_service
		);

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'Ada Lovelace', $config['customerData']['name'] );
		$this->assertSame( 'ada@example.com', $config['customerData']['email'] );
		$this->assertSame( 'RO', $config['customerData']['billing_country'] );
	}

	/**
	 * @testdox Should expose card platform checkout config independently from WooPay frontend config.
	 */
	public function test_card_platform_checkout_config_is_independent_from_woopay_frontend_config(): void {
		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$account_service = $this->create_account_service(
			true,
			array(
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['forceNetworkSavedCards'] );
		$this->assertTrue( $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
	}

	/**
	 * @testdox Should merge direct checkout eligibility from the WooPay session service.
	 */
	public function test_get_payment_fields_js_config_merges_direct_checkout_eligibility(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( true, true ), $this->create_woopay_eligibility( true, true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isWooPayDirectCheckoutEnabled'] );
	}

	/**
	 * @testdox The card config forces network saved cards exactly when the WooPay session service's platform predicate holds ($platform).
	 *
	 * Client 11.1.0 has one should_use_stripe_platform_on_checkout_page() predicate for the card config and the WooPay
	 * config (class-wc-payments-checkout.php:194, :599); the WooPay session service owns it and its own tests cover its cases.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $platform Whether the session service says checkout uses the platform account.
	 */
	public function test_card_config_reads_the_session_service_platform_predicate( bool $platform ): void {

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( false, false, $platform ), $this->create_woopay_eligibility( false, false, $platform ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( $platform, $config['forceNetworkSavedCards'] );
		$this->assertSame( $platform, $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
	}

	/**
	 * @testdox Should offer the card save option only with saved cards enabled, outside WooPay and subscription carts: $_dataName.
	 *
	 * Client 11.1.0 shows the save option once a payment method is reusable, saved cards are enabled for the gateway, the
	 * shopper is not logged in to WooPay, and the cart carries no subscription item (class-wc-payments-checkout.php:579,
	 * :604-619). Its is_subscription_item_in_cart() counts a renewal (trait-wc-payments-subscriptions-utilities.php:96-101),
	 * so a renewal-only cart also sends `cartContainsSubscription`, the forced-save signal.
	 *
	 * @dataProvider provider_card_save_option
	 *
	 * @param bool                $logged_in                  Whether a shopper is logged in.
	 * @param bool                $woopay                     Whether WooPay is enabled.
	 * @param array<string,mixed> $gateway_settings           Gateway settings.
	 * @param bool                $renewal_cart               Whether the cart holds only a subscription renewal.
	 * @param bool                $saved_cards_enabled        Expected `isSavedCardsEnabled`.
	 * @param bool                $show_save_option           Expected card `showSaveOption`.
	 * @param bool                $cart_contains_subscription Expected `cartContainsSubscription`.
	 */
	public function test_get_payment_fields_js_config_decides_the_card_save_option( bool $logged_in, bool $woopay, array $gateway_settings, bool $renewal_cart, bool $saved_cards_enabled, bool $show_save_option, bool $cart_contains_subscription ): void {
		if ( $logged_in ) {
			wp_set_current_user( self::factory()->user->create() );
		}
		if ( $renewal_cart ) {
			$this->report_subscriptions_loaded();
			WooCommerceSubscriptionsDoubles::load_cart();
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] = true;
		}
		$account_service = $this->create_account_service( true, array( 'country' => 'RO' ), $gateway_settings );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( $woopay ), $this->create_woopay_eligibility( $woopay ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( $saved_cards_enabled, $config['isSavedCardsEnabled'] );
		$this->assertSame( $show_save_option, $config['paymentMethodsConfig']['card']['showSaveOption'] );
		$this->assertSame( $cart_contains_subscription, $config['cartContainsSubscription'] );
	}

	/**
	 * Shopper, settings and cart cases for the card save option.
	 *
	 * @return array<string,array{0:bool,1:bool,2:array<string,mixed>,3:bool,4:bool,5:bool,6:bool}>
	 */
	public function provider_card_save_option(): array {
		return array(
			'logged-in WooPay shopper'                => array( true, true, array(), false, true, false, false ),
			'saved cards disabled'                    => array( false, false, array( 'saved_cards' => 'no' ), false, false, false, false ),
			'saved cards enabled'                     => array( false, false, array( 'saved_cards' => 'yes' ), false, true, true, false ),
			'renewal-only cart'                       => array( false, false, array( 'saved_cards' => 'yes' ), true, true, false, true ),
			'renewal-only cart, no saved-cards value' => array( false, false, array(), true, true, false, true ),
		);
	}

	/**
	 * @testdox Should keep the save option for a reusable non-card method when WooPay hides it for cards.
	 *
	 * Source: client 11.1.0 class-wc-payments-checkout.php:610-620 applies the logged-in WooPay guard to the card method only;
	 * other reusable methods (Link carries TOKENIZATION in LinkDefinition.php) keep the saved-cards rule.
	 */
	public function test_get_payment_fields_js_config_keeps_non_card_save_option_for_logged_in_woopay_shoppers(): void {
		wp_set_current_user( self::factory()->user->create() );

		$account_service = $this->create_account_service(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array(
				'saved_cards'                    => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['paymentMethodsConfig']['link']['isReusable'] );
		$this->assertTrue( $config['paymentMethodsConfig']['link']['showSaveOption'], 'The WooPay guard must not hide the save option for a reusable non-card method.' );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'], 'Logged-in WooPay shoppers must not see the card save option.' );
	}

	/**
	 * @testdox Should build no payment methods for a stored empty list of enabled methods (client 11.1.0 `get_enabled_payment_method_config()`, `class-wc-payments-checkout.php:291-320`, builds them from the enabled list).
	 */
	public function test_get_payment_fields_js_config_builds_no_methods_for_an_empty_enabled_list(): void {
		$account_service = $this->create_account_service(
			true,
			array( 'country' => 'RO' ),
			array(
				'saved_cards'                    => 'yes',
				'upe_enabled_payment_method_ids' => array(),
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( array(), $config['paymentMethodsConfig'] );
	}

	/**
	 * @testdox Should send the store base country as storeCountry and show Cartes Bancaires for a store based in France.
	 */
	public function test_get_payment_fields_js_config_includes_cartes_bancaires_for_stores_based_in_france(): void {
		update_option( 'woocommerce_default_country', 'FR' );
		$account_service = $this->create_account_service(
			true,
			array(
				'country' => 'US',
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$icons  = $config['paymentMethodsConfig']['card']['cardBrandIcons'];

		// Client 11.1.0 sends the store base country (includes/class-wc-payments-checkout.php:270) and decides the brand from it
		// (client/utils/card-brands.ts:43-50).
		$this->assertSame( 'FR', $config['storeCountry'] );
		$this->assertContains( 'cartes_bancaires', array_column( $icons, 'id' ) );
		$this->assertContains( 'Cartes Bancaires', array_column( $icons, 'alt' ) );
		$this->assertStringContainsString( '/assets/images/payment-methods/jcb-color.svg', $icons[4]['src'] );
		$this->assertStringContainsString( '/assets/images/payment-methods/unionpay-color.svg', $icons[5]['src'] );
		$this->assertStringContainsString( '/assets/images/payment-methods/cartes_bancaires-color.svg', $icons[6]['src'] );
	}

	/**
	 * @testdox Should leave Cartes Bancaires out for a French account on a store based outside France.
	 */
	public function test_get_payment_fields_js_config_leaves_cartes_bancaires_out_for_stores_based_outside_france(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		$account_service = $this->create_account_service(
			true,
			array(
				'country' => 'FR',
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'US', $config['storeCountry'] );
		$this->assertNotContains( 'cartes_bancaires', array_column( $config['paymentMethodsConfig']['card']['cardBrandIcons'], 'id' ) );
	}

	/**
	 * @testdox Should give Stripe the site locale, not the admin user's, in the checkout config.
	 */
	public function test_get_payment_fields_js_config_uses_the_site_locale(): void {
		$account_service = $this->create_account_service( true );
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $this->in_admin_with_french_user_locale( static fn() => $assets->get_payment_fields_js_config( self::CARD_SUPPORTS ) );

		// Client 11.1.0 sends the site locale, get_locale(), not the user's (includes/class-wc-payments-checkout.php:195).
		$this->assertSame( 'en', $config['locale'] );
	}

	/**
	 * @testdox Should show the US test card, not the store country's, when the account data has no country.
	 */
	public function test_get_payment_fields_js_config_uses_the_us_test_card_without_an_account_country(): void {
		update_option( 'woocommerce_default_country', 'GB' );
		// Client 11.1.0 picks the test card for the account country, else US (includes/class-wc-payments-checkout.php:474-481;
		// includes/class-wc-payments-account.php:2731-2734; includes/constants/class-country-test-cards.php:92-94).
		$account_service = $this->create_account_service( true, array() );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertStringContainsString( '4242 4242 4242 4242', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringNotContainsString( '4000 0082 6000 0000', $config['paymentMethodsConfig']['card']['testingInstructions'] );
	}

	/**
	 * The checkout script reads the config printed in the form's `data-wcpay-config` after WooCommerce re-renders the payment
	 * fields (`client/legacy/js/frontend/woopayments-checkout.js:205`), as the client's script reads the refreshed
	 * `paymentMethodsConfig`, `currency` and `cartTotal` (client 11.1.0 includes/class-wc-payments-checkout.php:353); the
	 * amount decides between a payment and a setup Payment Element.
	 *
	 * @testdox Should enqueue the checkout assets and print the $surface amount, currency and identity in the rendered form.
	 * @testWith ["checkout page"]
	 *           ["order-pay page"]
	 *
	 * @param string $surface Checkout surface.
	 */
	public function test_payment_fields_enqueues_core_owned_assets_and_preserves_wcpay_config_filter( string $surface ): void {
		$account_service = $this->create_account_service( true );
		$customer_id     = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );
		if ( 'order-pay page' === $surface ) {
			$order = wc_create_order( array( 'customer_id' => $customer_id ) );
			$order->set_currency( 'EUR' );
			$order->set_total( '56.78' );
			$order->save();
			set_query_var( 'order-pay', $order->get_id() );
			$_GET['key'] = $order->get_order_key();
			$expected    = array(
				'cartTotal'  => 5678,
				'currency'   => 'EUR',
				'gatewayId'  => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'isOrderPay' => true,
				'orderId'    => $order->get_id(),
			);
		} else {
			WC()->cart->add_to_cart(
				\WC_Helper_Product::create_simple_product(
					true,
					array(
						'regular_price' => '12.34',
						'price'         => '12.34',
					)
				)->get_id(),
				1
			);
			WC()->cart->calculate_totals();
			$expected = array(
				'cartTotal'  => 1234,
				'currency'   => 'USD',
				'gatewayId'  => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'isOrderPay' => null,
				'orderId'    => null,
			);
		}

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		$gateway = $this->create_card_gateway( $assets );
		try {
			ob_start();
			$gateway->form();
			$output = (string) ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			set_query_var( 'order-pay', '' );
			WC()->cart->empty_cart();
		}

		$this->assertSame( 1, preg_match( '/data-wcpay-config="([^"]*)"/', $output, $matches ) );
		$printed = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $printed[ $key ] ?? null, "The form prints {$key}." );
		}

		$this->assertStringContainsString( 'wcpay-core-checkout-form', $output );
		// Core's tokenization-form.js hides `.wc-payment-form` while a saved method is selected, so it must wrap the card element.
		// The wrapper is the client's fieldset (client includes/class-wc-payments-checkout.php), inline padding included.
		$this->assertStringContainsString( '<fieldset style="padding: 7px" class="wc-payment-form"><div id="wcpay-core-payment-element" class="wcpay-core-payment-element wcpay-upe-element" data-payment-method-type="card"></div></fieldset>', $output );
		$this->assertStringContainsString( 'wcpay-core-test-mode-instructions', $output );
		$this->assertStringContainsString( '4000 0064 2000 0001', $output );
		$this->assertStringContainsString( 'js-woopayments-copy-test-number', $output );
		$this->assertStringContainsString( 'data-wcpay-config', $output );
		$this->assertStringContainsString( 'filtered', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/js/frontend/woopayments-checkout',
			wp_scripts()->registered['wc-woopayments-checkout']->src
		);
		$this->assertContains( 'wc-woopayments-appearance', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		$this->assertTrue( wp_style_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/css/woopayments-checkout.css',
			wp_styles()->registered['wc-woopayments-checkout']->src
		);
	}

	/**
	 * @testdox Should make core's tokenization-form.js a dependency of the classic checkout script when the card gateway supports tokenization, as client 11.1.0 does.
	 */
	public function test_classic_script_depends_on_tokenization_form_when_tokenization_is_supported(): void {
		wp_deregister_script( 'woocommerce-tokenization-form' );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		self::render_payment_fields( $assets, array( 'products', 'tokenization' ) );

		// Client 11.1.0 includes/class-wc-payments-checkout.php:130-131: WordPress then prints tokenization-form.js first,
		// so its listener is bound before the checkout script mounts the card element.
		$this->assertContains( 'woocommerce-tokenization-form', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		// A missing dependency would make WordPress drop the checkout script, so the handle is registered with core's params.
		$this->assertTrue( wp_script_is( 'woocommerce-tokenization-form', 'registered' ) );
		$this->assertStringContainsString( '/assets/js/frontend/tokenization-form', wp_scripts()->registered['woocommerce-tokenization-form']->src );
		$this->assertStringContainsString( 'wc_tokenization_form_params', (string) wp_scripts()->get_data( 'woocommerce-tokenization-form', 'data' ) );
	}

	/**
	 * @testdox Should not load core's tokenization-form.js with the classic checkout script when the card gateway does not support tokenization.
	 */
	public function test_classic_script_does_not_depend_on_tokenization_form_without_tokenization(): void {
		wp_deregister_script( 'woocommerce-tokenization-form' );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		self::render_payment_fields( $assets, self::CARD_SUPPORTS );

		$this->assertNotContains( 'woocommerce-tokenization-form', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		$this->assertFalse( wp_script_is( 'woocommerce-tokenization-form', 'registered' ) );
	}

	/**
	 * @testdox Should register the classic checkout script with its full dependencies when WooCommerce registers frontend scripts first, as on classic themes.
	 */
	public function test_payment_fields_registers_full_checkout_script_after_frontend_script_registration(): void {
		\WC_Frontend_Scripts::load_scripts();

		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$payment_method_registry = new PaymentMethodRegistry();

		self::render_payment_fields( $assets, self::CARD_SUPPORTS, $payment_method_registry->get( 'ideal' ) );

		$checkout_script = wp_scripts()->registered['wc-woopayments-checkout'];
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertContains( 'wc-woopayments-fingerprintjs', $checkout_script->deps, 'Without FingerprintJS the classic checkout posts an empty device fingerprint.' );
		$this->assertContains( 'stripe', $checkout_script->deps );
		$this->assertContains( 'wc-woopayments-appearance', $checkout_script->deps );
		$this->assertContains( 'wc-checkout', $checkout_script->deps );
		$this->assertStringStartsWith( WC()->plugin_url() . '/assets/js/frontend/woopayments-checkout', $checkout_script->src );
		$this->assertSame( 1, wp_scripts()->get_data( 'wc-woopayments-checkout', 'group' ), 'The checkout script loads in the footer.' );
		$this->assertFalse( wp_scripts()->get_data( 'wc-woopayments-checkout', 'strategy' ) );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
		$this->assertTrue( wp_script_is( 'wc-woopayments-appearance', 'registered' ) );
	}

	/**
	 * Card configs whose WooPay flags turn the classic WooPay email check on or off, as the checkout script reads them.
	 *
	 * @return array<string,array{0:mixed,1:mixed,2:bool}>
	 */
	public function provide_woopay_email_check_flags(): array {
		return array(
			'WooPay and its email input on' => array( true, true, true ),
			'WooPay off'                    => array( false, true, false ),
			'the email input off'           => array( true, false, false ),
			'a filtered "0", which the browser reads as on' => array( true, '0', true ),
		);
	}

	/**
	 * @testdox Should list the WooPay protocol with the classic checkout script only when the card config turns the WooPay email check on: $_dataName.
	 *
	 * @dataProvider provide_woopay_email_check_flags
	 *
	 * @param mixed $woopay_enabled      The card config's isWooPayEnabled.
	 * @param mixed $email_input_enabled The card config's isWooPayEmailInputEnabled.
	 * @param bool  $listed              Whether the script lists the protocol.
	 */
	public function test_classic_script_lists_the_woopay_protocol_only_with_the_email_check( $woopay_enabled, $email_input_enabled, bool $listed ): void {
		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $this->create_account_service( true ), $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ) use ( $woopay_enabled, $email_input_enabled ): array {
				$config['isWooPayEnabled']           = $woopay_enabled;
				$config['isWooPayEmailInputEnabled'] = $email_input_enabled;

				return $config;
			}
		);

		self::render_payment_fields( $assets, self::CARD_SUPPORTS );

		$deps = wp_scripts()->registered['wc-woopayments-checkout']->deps;
		if ( $listed ) {
			$this->assertContains( 'wc-woopayments-woopay-common', $deps, 'The checkout script wires the WooPay email check from window.wc.woopayments.woopay.' );
			$this->assertTrue( wp_script_is( 'wc-woopayments-woopay-common', 'registered' ), 'An unregistered dependency would make WordPress drop the checkout script.' );
		} else {
			$this->assertNotContains( 'wc-woopayments-woopay-common', $deps, 'Without the email check the checkout script never reads window.wc.woopayments.woopay.' );
		}
	}

	/**
	 * @testdox Should localize split gateway classic config under a gateway-specific object name.
	 */
	public function test_payment_fields_localizes_split_gateway_config_under_gateway_specific_object_name(): void {
		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$payment_method_registry = new PaymentMethodRegistry();

		self::render_payment_fields( $assets, self::CARD_SUPPORTS, $payment_method_registry->get( 'klarna' ) );

		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertStringContainsString( 'var wcpay_core_checkout_config_woocommerce_payments_klarna = ', $script_data );
		$this->assertStringContainsString( '"gatewayId":"woocommerce_payments_klarna"', $script_data );
		$this->assertStringContainsString( '"paymentMethodTypes":["klarna"]', $script_data );
		$this->assertStringNotContainsString( 'var wcpay_core_checkout_config = ', $script_data );
	}

	/**
	 * @testdox Split gateways build the gateway-independent Blocks config once per request, and still get their own gateway keys and filter pass.
	 */
	public function test_split_gateway_blocks_data_builds_the_shared_config_once(): void {
		$account_service = $this->create_account_service( true );
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$registry = new PaymentMethodRegistry();
		$builds   = 0;
		$filtered = 0;
		$count    = static function ( $value ) use ( &$builds ) {
			++$builds;
			return $value;
		};
		$filter   = static function ( $config ) use ( &$filtered ) {
			++$filtered;
			return $config;
		};
		add_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
		add_filter( 'wcpay_payment_fields_js_config', $filter );

		try {
			$gateways = array();
			foreach ( array( 'card', 'klarna', 'affirm' ) as $payment_method_id ) {
				$gateways[] = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS, $registry->get( $payment_method_id ) )['gatewayId'];
			}
		} finally {
			remove_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
			remove_filter( 'wcpay_payment_fields_js_config', $filter );
		}

		$this->assertSame( 1, $builds, 'Client 11.1.0 builds the payment fields config once for its single Blocks method.' );
		$this->assertSame( 3, $filtered, 'Each gateway config still passes the wcpay_payment_fields_js_config filter.' );
		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), $gateways );
	}

	/**
	 * @testdox On the $_dataName the classic payment list builds the gateway-independent config once per request, and again when $changed changes.
	 *
	 * Client 11.1.0 builds the classic config once per request: payment_fields() builds it only while the checkout script
	 * is not yet enqueued (`includes/class-wc-payments-checkout.php:409-424`). Native shares one base between the
	 * gateways in the same way, with no hook window, and builds it again only when an input of the base changes.
	 *
	 * @testWith ["checkout page", "the cart total"]
	 *           ["order-pay page", "the order"]
	 *           ["update_order_review refresh", "the shopper"]
	 *           ["checkout page", "the card gateway supports"]
	 *
	 * @param string $surface Where the payment list renders.
	 * @param string $changed Which input of the config base changes before the last render.
	 */
	public function test_classic_payment_list_builds_the_shared_config_once( string $surface, string $changed ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$account_service = $this->create_account_service( true );
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$registry = new PaymentMethodRegistry();
		$builds   = 0;
		$filtered = 0;
		$count    = static function ( $value ) use ( &$builds ) {
			++$builds;
			return $value;
		};
		$filter   = static function ( $config ) use ( &$filtered ) {
			++$filtered;
			return $config;
		};
		$render   = static function ( array $supports, string $payment_method_id = 'card' ) use ( $assets, $registry ): array {
			return self::render_payment_fields( $assets, $supports, $registry->get( $payment_method_id ) );
		};
		add_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
		add_filter( 'wcpay_payment_fields_js_config', $filter );

		$gateways = array();
		try {
			$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
			wp_set_current_user( $customer_id );
			if ( 'order-pay page' === $surface ) {
				$order = wc_create_order( array( 'customer_id' => $customer_id ) );
				$order->set_total( '12.34' );
				$order->save();
				set_query_var( 'order-pay', $order->get_id() );
				$_GET['key'] = $order->get_order_key();
			} else {
				if ( 'update_order_review refresh' === $surface ) {
					add_filter( 'wp_doing_ajax', '__return_true' );
				}
				WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
				WC()->cart->calculate_totals();
			}
			foreach ( array( 'card', 'klarna', 'affirm' ) as $payment_method_id ) {
				$gateways[] = $render( self::CARD_SUPPORTS, $payment_method_id )['gatewayId'] ?? '';
			}
			$builds_in_list   = $builds;
			$before           = $render( self::CARD_SUPPORTS );
			$builds_unchanged = $builds;

			$supports = self::CARD_SUPPORTS;
			switch ( $changed ) {
				case 'the cart total':
					WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
					WC()->cart->calculate_totals();
					$field = 'cartTotal';
					break;
				case 'the order':
					$other_order = wc_create_order( array( 'customer_id' => $customer_id ) );
					$other_order->set_total( '56.78' );
					$other_order->save();
					set_query_var( 'order-pay', $other_order->get_id() );
					$_GET['key'] = $other_order->get_order_key();
					$field       = 'orderId';
					break;
				case 'the shopper':
					wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
					$field = 'createSetupIntentNonce';
					break;
				default:
					$supports = array_merge( self::CARD_SUPPORTS, array( 'tokenization' ) );
					$field    = 'features';
			}
			$after = $render( $supports );
		} finally {
			remove_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
			remove_filter( 'wcpay_payment_fields_js_config', $filter );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			set_query_var( 'order-pay', '' );
			WC()->cart->empty_cart();
		}

		$this->assertSame( 1, $builds_in_list, 'The payment list builds the shared config once.' );
		$this->assertSame( 1, $builds_unchanged, 'A later render in the same request with the same inputs reuses it.' );
		$this->assertSame( 2, $builds, 'A render after ' . $changed . ' changed builds fresh config.' );
		$this->assertNotSame( $before[ $field ] ?? null, $after[ $field ] ?? null, 'The fresh config carries the changed input.' );
		$this->assertSame( 5, $filtered, 'Each gateway config still passes the wcpay_payment_fields_js_config filter.' );
		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), $gateways );
		$localized = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );
		$this->assertStringContainsString( 'var wcpay_core_checkout_config = ', $localized, 'The card gateway config is localized under the base object.' );
		$this->assertStringNotContainsString( 'var wcpay_core_checkout_config_woocommerce_payments =', $localized, 'The checkout script reads the card config from the base object only.' );
	}

	/**
	 * @testdox Should include WooPay save-user data in Blocks payment method data.
	 */
	public function test_get_blocks_payment_method_data_includes_woopay_save_user_data(): void {
		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$data = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isWooPayEnabled'] );
		$this->assertTrue( $data['PRE_CHECK_SAVE_MY_INFO'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
	}

	/**
	 * @testdox Should send the card gateway's own supports as features and Blocks supports, with no per-method copy.
	 *
	 * Client 11.1.0 sends the gateway's `$supports` as `features` (`class-wc-payments-checkout.php:193`), so values the
	 * gateway declares reach the checkout and values a filter removed stay out.
	 */
	public function test_config_features_are_the_supports_passed_in(): void {
		$account_service = $this->create_account_service( true );
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$registry = new PaymentMethodRegistry();

		// An extension removed `products` from the gateway's supports, leaving a gap in the keys.
		$supports = array( 'products', 'refunds', 'tokenization', 'add_payment_method' );
		unset( $supports[0] );
		$expected = array( 'refunds', 'tokenization', 'add_payment_method' );

		$classic = $assets->get_payment_fields_js_config( $supports );
		$blocks  = $assets->get_blocks_payment_method_data( $supports );
		$split   = $assets->get_blocks_payment_method_data( $supports, $registry->get( 'klarna' ) );

		$this->assertSame( $expected, $classic['features'] );
		$this->assertSame( $expected, $blocks['features'] );
		$this->assertSame( $expected, $blocks['supports'] );
		$this->assertSame( $expected, $split['features'] );
		$this->assertSame( $expected, $split['supports'] );
		$this->assertArrayNotHasKey( 'supports', $classic['paymentMethodsConfig']['card'] );
		$this->assertArrayNotHasKey( 'supports', $split['paymentMethodsConfig']['klarna'] );
	}

	/**
	 * @testdox Should expose the WooPay express-method gate flags in Blocks payment method data.
	 *
	 * The Blocks bundle only registers WooPay's express payment method once
	 * `isWooPayEnabled` and `shouldShowWooPayButton` both come back true (client
	 * client/checkout/blocks/index.js:123-134).
	 */
	public function test_get_blocks_payment_method_data_exposes_woopay_express_gate_flags(): void {
		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service( true, self::EXPRESS_ACCOUNT_DATA, self::EXPRESS_GATEWAY_SETTINGS );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$data = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isWooPayEnabled'] );
		$this->assertTrue( $data['shouldShowWooPayButton'] );
	}

	/**
	 * @testdox Should build the Blocks WooPay button flag for the page's button location, so the cart location alone decides it on a Blocks cart.
	 *
	 * Client 11.2.0 reads only the cart location on a cart page (class-wc-payments-woopay-button-handler.php:273-281). The
	 * session service answers per location; here the cart location allows WooPay and the checkout location leaves it out.
	 */
	public function test_get_blocks_payment_method_data_reads_the_woopay_button_location_of_the_page(): void {
		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service( true, self::EXPRESS_ACCOUNT_DATA, self::EXPRESS_GATEWAY_SETTINGS );
		$woopay_config   = $this->create_woopay_session_service( true )->get_woopay_frontend_config( 'checkout' );
		$woopay_service  = $this->getMockBuilder( SessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config', 'get_save_user_checkout_data' ) )
			->getMock();
		$woopay_service->method( 'get_woopay_frontend_config' )->willReturnCallback(
			static function ( string $context ) use ( $woopay_config ): array {
				return array_merge( $woopay_config, array( 'shouldShowWooPayButton' => 'cart' === $context ) );
			}
		);
		$woopay_service->method( 'get_save_user_checkout_data' )->willReturn( array() );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $woopay_service, $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$this->assertTrue( $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS, null, 'cart' )['shouldShowWooPayButton'], 'The Blocks cart reads the cart location.' );
		$this->assertFalse( $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS, null, 'checkout' )['shouldShowWooPayButton'], 'The Blocks checkout reads the checkout location.' );
	}

	/**
	 * Top-level keys of the client 11.1.0 Blocks payment method data on a checkout page with WooPay enabled.
	 *
	 * Sources: class-wc-payments-blocks-payment-method.php:88-104 (title, description, is_admin, woopayHost),
	 * class-wc-payments-checkout.php:183-274 (payment fields config), class-wc-payments-woopay-button-handler.php:144-160
	 * (WooPay button keys) and class-woopay-tracker.php:660-662 (isShopperTrackingEnabled).
	 */
	private const WOOPAYMENTS_EXTENSION_BLOCKS_DATA_KEYS = array(
		'title',
		'description',
		'is_admin',
		'woopayHost',
		'publishableKey',
		'testMode',
		'accountId',
		'ajaxUrl',
		'wcAjaxUrl',
		'createSetupIntentNonce',
		'initWooPayNonce',
		'genericErrorMessage',
		'fraudServices',
		'features',
		'forceNetworkSavedCards',
		'locale',
		'isPreview',
		'isSavedCardsEnabled',
		'isWooPayEnabled',
		'isWoopayExpressCheckoutEnabled',
		'isWoopayFirstPartyAuthEnabled',
		'isWooPayEmailInputEnabled',
		'isWooPayDirectCheckoutEnabled',
		'isWooPayGlobalThemeSupportEnabled',
		'isShortcodeCheckout',
		'platformTrackerNonce',
		'accountIdForIntentConfirmation',
		'wcpayVersionNumber',
		'woopaySignatureNonce',
		'woopaySessionNonce',
		'woopayMerchantId',
		'icon',
		'woopayMinimumSessionData',
		'gatewayId',
		'isCheckout',
		'paymentMethodsConfig',
		'cartContainsSubscription',
		'currency',
		'stylesCacheVersion',
		'cartTotal',
		'enabledBillingFields',
		'storeCountry',
		'isExpressCheckoutInPaymentMethodsEnabled',
		'isShopperTrackingEnabled',
		'woopayButton',
		'woopayButtonNonce',
		'addToCartNonce',
		'shouldShowWooPayButton',
		'woopaySessionEmail',
		'woopayIsCountryAvailable',
		'woopayAppearance',
		'woopayFontRules',
		'isPaymentRequestEnabled',
		'isAmazonPayEnabled',
	);

	/**
	 * Native-only Blocks data keys that the native Blocks bundle reads (client/blocks/assets/js/extensions/payment-methods/woopayments).
	 */
	private const BLOCKS_BUNDLE_DATA_KEYS_WITH_CONSUMERS = array(
		'isCoreNativeCheckoutAvailable',   // index.js:1040,1268 and woopay/index.js:736,748.
		'paymentMethodTypes',              // index.js:517-521, the split gateway's own Stripe method type.
		'supports',                        // index.js:1277, woopay/index.js:18.
		'PRE_CHECK_SAVE_MY_INFO',          // index.js:706 (the client localizes it as woopayCheckout).
		'woopayOtpIframeTitle',            // woopay/email-input-iframe.js:269, woopay/express-checkout-iframe.js:98.
		'woopayOtpCloseLabel',             // woopay/email-input-iframe.js:420, woopay/express-checkout-iframe.js:187.
		'woopayUnavailableMessage',        // woopay/email-input-iframe.js:432.
		'woopayExpressUnavailableMessage', // woopay/express-checkout-iframe.js:254.
		'tracksUrl',                       // tracks.js:21, the shopper Tracks REST route.
		'tracksRestNonce',                 // tracks.js:38-39, the REST nonce sent with it.
	);

	/**
	 * Keys the Blocks data must not carry: the client never sends them and the native Blocks bundle does not need them.
	 */
	private const DROPPED_BLOCKS_DATA_KEYS = array(
		'confirmationErrorMessage',
		'customerData',
		'fingerprintErrorMessage',
		'paymentListWalletsConfig',
		'updateOrderStatusNonce',
		'woopayButtonLabels',
		'woopayAdditionalInfoText',
		'woopayAgreementText',
		'woopayTermsOfServiceLabel',
		'woopayPrivacyPolicyLabel',
		'woopaySaveUserLabel',
		'woopayPhoneLabel',
		'cardBrandPopoverLabel',
		'cardBrandLogosLabel',
		'is_shopper_tracking_enabled',
	);

	/**
	 * WooPay button keys the client adds only while its WooPay button handler runs (class-wc-payments-woopay-button-handler.php:144-160).
	 */
	private const WOOPAY_BUTTON_KEYS = array(
		'woopayButton',
		'woopayButtonNonce',
		'addToCartNonce',
		'shouldShowWooPayButton',
		'woopaySessionEmail',
		'woopayIsCountryAvailable',
		'woopayAppearance',
		'woopayFontRules',
	);

	/**
	 * Build checkout assets whose WooPay config carries every key the real WooPay session service emits.
	 *
	 * @return WooPaymentsCheckoutAssets
	 */
	private function create_assets_with_full_woopay_config(): WooPaymentsCheckoutAssets {
		$real_account_service = new WooPaymentsAccountService();
		$database_cache       = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$real_account_service->init( new LegacyProxy(), $database_cache );
		$real_eligibility = new Eligibility();
		$real_eligibility->init( $real_account_service );
		$real_appearance_store = new AppearanceStore();
		$real_appearance_store->init( new WooPaymentsFrontendStylesService() );
		$real_woopay_service = new SessionService();
		$real_woopay_service->init( $real_account_service, $real_eligibility, $real_appearance_store, $this->create_frontend_tracking_controller(), wc_get_container()->get( BlocksDataExtractor::class ), new AdaptedExtensions(), $this->createMock( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsLogger::class ) );
		$woopay_config = array_merge(
			array_fill_keys( array_keys( $real_woopay_service->get_woopay_frontend_config( 'checkout' ) ), '' ),
			array(
				'isWooPayEnabled'        => true,
				'shouldShowWooPayButton' => true,
				'forceNetworkSavedCards' => false,
			)
		);

		$woopay_service = $this->getMockBuilder( SessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config', 'get_save_user_checkout_data' ) )
			->getMock();
		$woopay_service->method( 'get_woopay_frontend_config' )->willReturn( $woopay_config );
		$woopay_service->method( 'get_save_user_checkout_data' )->willReturn( array( 'PRE_CHECK_SAVE_MY_INFO' => true ) );

		$this->set_wallet_gateway_settings( true );
		$account_service = $this->create_account_service( true, self::EXPRESS_ACCOUNT_DATA, self::EXPRESS_GATEWAY_SETTINGS );
		$assets          = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $woopay_service, $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$this->inject_express_checkout_service( $assets, $account_service );

		return $assets;
	}

	/**
	 * @testdox Blocks payment method data carries exactly the WooPayments extension's keys plus the keys the Blocks bundle reads.
	 */
	public function test_get_blocks_payment_method_data_has_the_woopayments_extension_key_set_plus_the_keys_the_blocks_bundle_reads(): void {
		$data = $this->create_assets_with_full_woopay_config()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$expected = array_merge( self::WOOPAYMENTS_EXTENSION_BLOCKS_DATA_KEYS, self::BLOCKS_BUNDLE_DATA_KEYS_WITH_CONSUMERS );
		sort( $expected );
		$actual = array_keys( $data );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * @testdox Blocks payment method data never carries the classic-only keys, while the classic config keeps them.
	 */
	public function test_get_blocks_payment_method_data_omits_classic_only_keys(): void {
		$assets = $this->create_assets_with_full_woopay_config();
		$data   = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$split  = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS, ( new PaymentMethodRegistry() )->get( 'klarna' ) );

		foreach ( self::DROPPED_BLOCKS_DATA_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $data, "Blocks data must not carry {$key}." );
			$this->assertArrayNotHasKey( $key, $split, "Split-gateway Blocks data must not carry {$key}." );
		}

		$classic = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayHasKey( 'customerData', $classic );
		$this->assertArrayHasKey( 'paymentListWalletsConfig', $classic );
		$this->assertArrayHasKey( 'woopayAgreementText', $classic );
	}

	/**
	 * @testdox Blocks payment method data reports is_admin like the WooPayments extension: false on the storefront, true in wp-admin.
	 */
	public function test_get_blocks_payment_method_data_reports_is_admin(): void {
		$assets = $this->create_assets_with_full_woopay_config();

		$this->assertFalse( $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS )['is_admin'] );

		set_current_screen( 'edit-post' );
		try {
			$this->assertTrue( $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS )['is_admin'] );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Account data under which the client runs its express checkout handlers and can use Amazon Pay, with the status and
	 * payments flag recorded in Fixtures/platform-test-drive-account.json `account`.
	 */
	private const EXPRESS_ACCOUNT_DATA = array(
		'country'          => 'US',
		'status'           => 'complete',
		'payments_enabled' => true,
		'capabilities'     => array( 'amazon_pay_payments' => 'active' ),
		'fees'             => array( 'amazon_pay' => array( 'base' => array( 'currency' => 'usd' ) ) ),
	);

	/**
	 * Gateway settings with the gateway enabled and Amazon Pay switched on, with Apple Pay/Google Pay and Amazon Pay listed at checkout.
	 *
	 * Apple Pay and Google Pay are on in their own gateway settings, which `set_wallet_gateway_settings()` writes.
	 */
	private const EXPRESS_GATEWAY_SETTINGS = array(
		'enabled'                           => 'yes',
		'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
		'express_checkout_product_methods'  => array( 'payment_request' ),
		'express_checkout_cart_methods'     => array( 'payment_request' ),
		'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
	);

	/**
	 * @testdox Blocks data carries the WooPayments extension's express checkout switches for the checkout page's own location settings.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_data_express_switches_follow_the_checkout_location(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertTrue( $data['isAmazonPayEnabled'] );

		$data = $this->create_assets_for_express_handlers( array( 'express_checkout_checkout_methods' => array( 'amazon_pay' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertFalse( $data['isPaymentRequestEnabled'], 'Apple Pay/Google Pay is not listed at checkout.' );
		$this->assertTrue( $data['isAmazonPayEnabled'] );

		$data = $this->create_assets_for_express_handlers( array( 'express_checkout_checkout_methods' => array( 'payment_request' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertFalse( $data['isAmazonPayEnabled'], 'Amazon Pay is not listed at checkout.' );
	}

	/**
	 * @testdox Blocks data reports Amazon Pay off once the merchant switched it off or the account cannot use it.
	 */
	public function test_blocks_data_amazon_pay_switch_uses_amazon_pay_availability(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$toggled_off = $this->create_assets_for_express_handlers( array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$ineligible  = $this->create_assets_for_express_handlers( array(), array( 'capabilities' => array() ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $toggled_off['isPaymentRequestEnabled'] );
		$this->assertFalse( $toggled_off['isAmazonPayEnabled'] );
		$this->assertFalse( $ineligible['isAmazonPayEnabled'] );
	}

	/**
	 * @testdox Blocks data outside the product, cart and checkout pages reports the switches without a location, as the WooPayments extension does.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_data_express_switches_ignore_locations_without_a_page_context(): void {
		$data = $this->create_assets_for_express_handlers(
			array(
				'express_checkout_checkout_methods' => array(),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_product_methods'  => array(),
			)
		)->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertTrue( $data['isAmazonPayEnabled'] );
	}

	/**
	 * Pages and the location whose express settings the switches follow. An escaped `[[product_page]]` tag prints as text,
	 * so it does not make a product page, as client 11.2.0 is_product() reads it
	 * (class-wc-payments-express-checkout-button-helper.php:255-259, :975-1031).
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:bool}>
	 */
	public function provide_pages_for_the_express_switch_location(): array {
		return array(
			'checkout page holding an escaped product shortcode' => array( '[woocommerce_checkout] [[product_page id="1"]]', true, true, false ),
			'page holding a product shortcode' => array( '[product_page id="1"]', false, false, true ),
		);
	}

	/**
	 * @testdox Blocks data takes the express switches from the visited page's location, counting only unescaped product shortcodes: $_dataName.
	 * @dataProvider provide_pages_for_the_express_switch_location
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $content          Page content.
	 * @param bool   $checkout_page    Whether the page is the store checkout page.
	 * @param bool   $payment_request  Expected Apple Pay/Google Pay switch: on only at checkout.
	 * @param bool   $amazon_pay       Expected Amazon Pay switch: on only on product pages.
	 */
	public function test_blocks_data_express_switches_follow_the_visited_page( string $content, bool $checkout_page, bool $payment_request, bool $amazon_pay ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		if ( $checkout_page ) {
			update_option( 'woocommerce_checkout_page_id', $page_id );
		}
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
		$this->assertSame( $checkout_page, is_checkout(), 'Precondition: only the store checkout page is a checkout.' );

		$data = $this->create_assets_for_express_handlers(
			array(
				'express_checkout_product_methods'  => array( 'amazon_pay' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		)->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertSame( $payment_request, $data['isPaymentRequestEnabled'] );
		$this->assertSame( $amazon_pay, $data['isAmazonPayEnabled'] );
	}

	/**
	 * @testdox Blocks data leaves out the express checkout switches while express checkout is off.
	 *
	 * @dataProvider provider_express_handler_off
	 *
	 * @param array<string,mixed> $settings     Gateway setting overrides.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $change_page  Whether the request is a change payment method page.
	 * @param bool                $wallets_on   Whether Apple Pay and Google Pay are enabled in their own settings.
	 */
	public function test_blocks_data_omits_express_switches_while_express_checkout_is_off( array $settings, array $account_data, bool $change_page, bool $wallets_on = true ): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		if ( $change_page ) {
			$_GET['change_payment_method'] = '123';
		}

		$data = $this->create_assets_for_express_handlers( $settings, $account_data, false, $wallets_on )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertArrayNotHasKey( 'isPaymentRequestEnabled', $data );
		$this->assertArrayNotHasKey( 'isAmazonPayEnabled', $data );
	}

	/**
	 * Cases where the client's express checkout button handler does not add its config.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool,3?:bool}>
	 */
	public function provider_express_handler_off(): array {
		return array(
			'gateway disabled'                  => array( array( 'enabled' => 'no' ), array(), false ),
			'payments not enabled on account'   => array( array(), array( 'payments_enabled' => false ), false ),
			'no express checkout method usable' => array(
				array( 'upe_enabled_payment_method_ids' => array( 'card' ) ),
				array(),
				false,
				false,
			),
			'change payment method page'        => array( array(), array(), true ),
		);
	}

	/**
	 * @testdox Blocks data carries the WooPayments extension's order-pay keys on a pay-for-order link, with the order's email for its own customer.
	 */
	public function test_blocks_data_carries_the_woopayments_extension_order_pay_keys(): void {
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = \WC_Helper_Order::create_order( $customer_id );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		wp_set_current_user( $customer_id );
		$this->go_to_pay_for_order_link( $order );

		$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( 'true', $data['pay_for_order'] );
		$this->assertSame( $order->get_order_key(), $data['key'] );
		$this->assertSame( 'order@example.com', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data carries no order-pay keys for a pay-for-order link with a wrong key.
	 */
	public function test_blocks_data_carries_no_order_pay_keys_with_a_wrong_key(): void {
		$order = \WC_Helper_Order::create_order( 0 );
		wp_set_current_user( 0 );
		$this->go_to_pay_for_order_link( $order );
		$_GET['key'] = 'wc_order_wrong';

		$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( current_user_can( 'pay_for_order', $order->get_id() ) );
		foreach ( array( 'order_id', 'pay_for_order', 'key', 'billing_email' ) as $order_pay_key ) {
			$this->assertArrayNotHasKey( $order_pay_key, $data );
		}
	}

	/**
	 * @testdox Blocks data gives a shopper who cannot see the order the email they typed, not the order's.
	 *
	 * On the configured checkout page, where core defines DONOTCACHEPAGE (WC_Cache_Helper::prevent_caching()); the constant
	 * is set here because an earlier test in the process may have defined it either way.
	 */
	public function test_blocks_data_order_pay_email_hides_the_order_email_from_other_payers(): void {
		$order = \WC_Helper_Order::create_order( 0 );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		$this->go_to_pay_for_order_link( $order );
		$_POST['email'] = 'typed@example.com';
		Constants::set_constant( 'DONOTCACHEPAGE', true );

		try {
			$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		} finally {
			Constants::clear_single_constant( 'DONOTCACHEPAGE' );
		}

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( 'typed@example.com', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data keeps a guest's $source email out of the order-pay keys on another page carrying the checkout shortcode.
	 *
	 * Core defines DONOTCACHEPAGE only on the configured cart, checkout and My Account pages (WC_Cache_Helper::prevent_caching()),
	 * so a page cache could serve this page's HTML to the next visitor. The constant is set to what that page gets, because an
	 * earlier test in the process may have defined it.
	 *
	 * @testWith ["session"]
	 *           ["posted"]
	 *
	 * @param string $source Where the visitor's email comes from.
	 */
	public function test_blocks_data_keeps_a_guest_email_off_a_page_core_does_not_protect( string $source ): void {
		$order = \WC_Helper_Order::create_order( 0 );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		$this->go_to_pay_for_order_link( $order );
		$session          = WC()->session;
		$session_customer = $session->get( 'customer' );
		$session->set( 'customer', 'session' === $source ? array( 'email' => 'session@example.com' ) : null );
		if ( 'posted' === $source ) {
			$_POST['email'] = 'typed@example.com';
		}
		Constants::set_constant( 'DONOTCACHEPAGE', false );

		try {
			$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		} finally {
			Constants::clear_single_constant( 'DONOTCACHEPAGE' );
			$session->set( 'customer', $session_customer );
		}

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( $order->get_order_key(), $data['key'] );
		$this->assertSame( '', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data leaves out the order-pay keys without the order key, for a user who cannot pay the order, or without payments enabled.
	 */
	public function test_blocks_data_omits_order_pay_keys_outside_an_authorized_pay_for_order_link(): void {
		$order_keys  = array( 'order_id', 'pay_for_order', 'key', 'billing_email' );
		$owner_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = \WC_Helper_Order::create_order( $owner_id );
		$assert_none = function ( array $data, string $message ) use ( $order_keys ): void {
			foreach ( $order_keys as $key ) {
				$this->assertArrayNotHasKey( $key, $data, $message );
			}
		};

		wp_set_current_user( $owner_id );
		$this->go_to_pay_for_order_link( $order );
		unset( $_GET['key'] );
		$assert_none( $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Missing order key.' );

		$this->go_to_pay_for_order_link( $order );
		wp_set_current_user( $other_id );
		$assert_none( $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Another customer cannot pay the order.' );

		wp_set_current_user( $owner_id );
		$assert_none( $this->create_assets_for_express_handlers( array(), array( 'payments_enabled' => false ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Payments are not enabled.' );
	}

	/**
	 * @testdox Blocks data leaves out the order-pay keys on a subscription's change payment method page, where the WooPayments extension skips its config filters.
	 */
	public function test_blocks_data_omits_order_pay_keys_while_changing_a_subscription_payment_method(): void {
		$this->report_subscriptions_loaded();

		$order = \WC_Helper_Order::create_order( 0 );
		$this->go_to_pay_for_order_link( $order );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
		$_GET['change_payment_method']                                = '123';

		$data = $this->create_assets_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isChangingPayment'] );
		$this->assertArrayNotHasKey( 'order_id', $data );
		$this->assertArrayNotHasKey( 'billing_email', $data );
	}

	/**
	 * Simulate opening an order's pay-for-order link.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function go_to_pay_for_order_link( \WC_Order $order ): void {
		global $wp;

		$_GET['pay_for_order']       = 'true';
		$_GET['key']                 = $order->get_order_key();
		$wp->query_vars['order-pay'] = (string) $order->get_id();
	}

	/**
	 * @testdox Blocks data carries the WooPayments extension's WooPay button keys while WooPay and its express button are enabled.
	 */
	public function test_blocks_data_carries_woopay_button_keys_while_woopay_is_enabled(): void {
		$data = $this->create_assets_for_express_handlers( array(), array(), true )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		foreach ( self::WOOPAY_BUTTON_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
	}

	/**
	 * @testdox Blocks data leaves out the WooPay button keys while the WooPay button is off.
	 *
	 * @dataProvider provider_woopay_button_handler_off
	 *
	 * @param string $scenario Scenario.
	 */
	public function test_blocks_data_omits_woopay_button_keys_while_woopay_is_off( string $scenario ): void {
		$woopay   = 'woopay disabled' !== $scenario;
		$settings = 'gateway disabled' === $scenario ? array( 'enabled' => 'no' ) : array();
		if ( 'express button flag off' === $scenario ) {
			update_option( '_wcpay_feature_woopay_express_checkout', '0' );
		}
		if ( 'filtered off' === $scenario ) {
			add_filter( 'wcpay_woopay_enabled', '__return_false' );
		}
		if ( 'change payment method page' === $scenario ) {
			$_GET['change_payment_method'] = '123';
		}

		$data = $this->create_assets_for_express_handlers( $settings, array(), $woopay )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		foreach ( self::WOOPAY_BUTTON_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $data, "{$scenario}: {$key}" );
		}
		$this->assertArrayHasKey( 'isWooPayEnabled', $data );
	}

	/**
	 * Cases where the client's WooPay button handler does not add its config.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_woopay_button_handler_off(): array {
		return array(
			'woopay disabled'            => array( 'woopay disabled' ),
			'express button flag off'    => array( 'express button flag off' ),
			'filtered off'               => array( 'filtered off' ),
			'gateway disabled'           => array( 'gateway disabled' ),
			'change payment method page' => array( 'change payment method page' ),
		);
	}

	/**
	 * @testdox Should strip script tags from filter-injected card testing instructions in the Blocks payload.
	 */
	public function test_get_blocks_payment_method_data_sanitizes_filtered_testing_instructions(): void {
		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['paymentMethodsConfig']['card']['testingInstructions'] = '<script>alert(1)</script><strong>ok</strong>';
				return $config;
			}
		);

		$data                 = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$testing_instructions = $data['paymentMethodsConfig']['card']['testingInstructions'];

		$this->assertStringNotContainsString( '<script>', $testing_instructions );
		$this->assertStringContainsString( '<strong>ok</strong>', $testing_instructions );
	}

	/**
	 * @testdox Should leave the fraud-prevention token out of card checkout config and Blocks payment method data, as the page global carries it.
	 */
	public function test_get_payment_fields_js_config_leaves_the_fraud_prevention_token_to_the_page_global(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$account_service,
			$this->create_woopay_session_service( true ),
			$this->create_woopay_eligibility( true ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry(),
			$this->create_fraud_prevention_service( true, $session )
		);

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$data   = $assets->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		// Client 11.2.0 delivers the token only as window.wcpayFraudPreventionToken (class-fraud-prevention-service.php:73-105).
		$this->assertArrayNotHasKey( 'fraudPreventionToken', $config );
		$this->assertArrayNotHasKey( 'fraudPreventionToken', $data );
	}

	/**
	 * @testdox Should publish the fraud-prevention token on window when rendering classic payment fields.
	 */
	public function test_payment_fields_publish_fraud_prevention_token_inline_script_when_enabled(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$account_service,
			$this->create_woopay_session_service( true ),
			$this->create_woopay_eligibility( true ),
			$this->create_frontend_styles_service(),
			$this->create_frontend_tracking_controller(),
			new PaymentMethodRegistry(),
			$this->create_fraud_prevention_service( true, $session )
		);

		self::render_payment_fields( $assets, self::CARD_SUPPORTS );

		$this->assertTrue( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		$inline_scripts = wp_scripts()->registered[ WooPaymentsFraudPreventionService::TOKEN_NAME ]->extra['after'] ?? array();
		$this->assertStringContainsString(
			"window.wcpayFraudPreventionToken = 'fraud-token-123';",
			implode( "\n", $inline_scripts )
		);
	}

	/**
	 * @testdox Should hide checkout surface controls when the account readiness fails.
	 */
	public function test_get_payment_fields_js_config_hides_controls_when_account_is_not_ready(): void {
		$account_service = $this->create_account_service( false );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( true ), $this->create_woopay_eligibility( true ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'pk_test_123', $config['publishableKey'] );
		$this->assertSame( 'acct_123', $config['accountId'] );
		$this->assertFalse( $config['isCoreNativeCheckoutAvailable'] );
		$this->assertArrayNotHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayNotHasKey( 'updateOrderStatusNonce', $config );
		$this->assertArrayNotHasKey( 'initWooPayNonce', $config );
		$this->assertArrayNotHasKey( 'woopaySessionNonce', $config );
		$this->assertArrayNotHasKey( 'woopaySignatureNonce', $config );
		$this->assertArrayNotHasKey( 'woopayMerchantId', $config );
		$this->assertArrayNotHasKey( 'woopayMinimumSessionData', $config );
	}

	/**
	 * @testdox Should expose the checkout nonces and keep the WooPay flags off when WooPay is disabled.
	 *
	 * The setup-intent request needs its nonce on every store, not only where WooPay is on.
	 */
	public function test_get_payment_fields_js_config_preserves_woopay_config_shape_when_woopay_is_disabled(): void {
		$account_service = $this->create_account_service( true );

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayHasKey( 'initWooPayNonce', $config );
		$this->assertSame( array(), $config['woopayMinimumSessionData'] );
		$this->assertFalse( $config['isWooPayEnabled'] );
		$this->assertFalse( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertFalse( $config['isWoopayFirstPartyAuthEnabled'] );
		$this->assertFalse( $config['isWooPayEmailInputEnabled'] );
	}

	/**
	 * @testdox Should expose fraud-services config from the preserved account payload.
	 *
	 * The account's config wins over the platform's public config and the bare `stripe` fallback (client 11.1.0
	 * class-wc-payments-fraud-service.php:92-113). The payload differs from that fallback, so a config that ignored the
	 * account would fail here.
	 */
	public function test_get_payment_fields_js_config_uses_account_fraud_services(): void {
		$account_service = $this->create_account_service(
			true,
			array(
				'country'        => 'US',
				'fraud_services' => array( 'stripe' => array( 'source' => 'account' ) ),
			)
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( false ), $this->create_woopay_eligibility( false ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$this->inject_fraud_service( $assets, $account_service );

		$config = $assets->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( array( 'stripe' => array( 'source' => 'account' ) ), $config['fraudServices'] );
	}

	/**
	 * Inject a fraud service wired to the given (mocked) account service into the checkout assets.
	 *
	 * The class otherwise resolves the fraud service from the container, whose
	 * account service is the real one — the test's mocked account payload would
	 * never reach it.
	 *
	 * @param WooPaymentsCheckoutAssets $assets          Checkout assets under test.
	 * @param WooPaymentsAccountService $account_service Account service (usually a mock) the fraud service should read.
	 */
	private function inject_fraud_service( WooPaymentsCheckoutAssets $assets, WooPaymentsAccountService $account_service ): void {
		$api_client       = new Client();
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, new WooPaymentsSessionService() );

		$fraud_service = new WooPaymentsFraudService();
		$fraud_service->init( $account_service, $customer_service, new WooPaymentsSessionService(), $api_client );

		$property = new \ReflectionProperty( WooPaymentsCheckoutAssets::class, 'fraud_service' );
		$property->setAccessible( true );
		$property->setValue( $assets, $fraud_service );
	}

	/**
	 * Turn Apple Pay and Google Pay on or off in their own gateway settings.
	 *
	 * @param bool $enabled Whether both wallets are enabled.
	 */
	private function set_wallet_gateway_settings( bool $enabled ): void {
		foreach ( array( 'apple_pay', 'google_pay' ) as $wallet_method_id ) {
			update_option( 'woocommerce_woocommerce_payments_' . $wallet_method_id . '_settings', array( 'enabled' => $enabled ? 'yes' : 'no' ) );
		}
	}

	/**
	 * Create an account service mock for checkout assets tests.
	 *
	 * @param bool  $can_process_payments Whether the account can process payments.
	 * @param array $account_data         Account cache data, in the platform account shape the client caches (country,
	 *                                    capabilities keyed by capability with a status; client 11.1.0
	 *                                    includes/class-wc-payments-account.php:269-292).
	 * @param array $gateway_settings     Gateway settings.
	 * @return WooPaymentsAccountService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_account_service( bool $can_process_payments, array $account_data = array( 'country' => 'RO' ), array $gateway_settings = array() ) {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_publishable_key', 'get_account_id', 'get_cached_account_data', 'get_gateway_setting', 'can_process_payments', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service
			->method( 'get_publishable_key' )
			->willReturn( 'pk_test_123' );
		$account_service
			->method( 'get_account_id' )
			->willReturn( 'acct_123' );
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( $account_data );
		$account_service
			->method( 'get_gateway_setting' )
			->willReturnCallback(
				static function ( string $key, $fallback = null ) use ( $gateway_settings ) {
					return array_key_exists( $key, $gateway_settings ) ? $gateway_settings[ $key ] : $fallback;
				}
			);
		$account_service
			->method( 'can_process_payments' )
			->willReturn( $can_process_payments );
		$account_service
			->method( 'is_test_mode_enabled' )
			->willReturn( true );

		return $account_service;
	}

	/**
	 * Give the checkout assets a real express checkout service backed by their account service.
	 *
	 * @param WooPaymentsCheckoutAssets $assets          Checkout assets.
	 * @param WooPaymentsAccountService $account_service Account service mock.
	 */
	private function inject_express_checkout_service( WooPaymentsCheckoutAssets $assets, WooPaymentsAccountService $account_service ): void {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$service = new WooPaymentsExpressCheckoutService();
		$service->init( $account_service, $provider, $this->create_frontend_tracking_controller() );

		$property = new \ReflectionProperty( WooPaymentsCheckoutAssets::class, 'express_checkout_service' );
		$property->setAccessible( true );
		$property->setValue( $assets, $service );
	}

	/**
	 * Build checkout assets whose account can run the client's express checkout handlers.
	 *
	 * @param array<string,mixed> $settings     Gateway setting overrides.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $woopay       Whether the WooPay session service reports WooPay enabled.
	 * @param bool                $wallets_on   Whether Apple Pay and Google Pay are enabled in their own gateway settings.
	 * @return WooPaymentsCheckoutAssets
	 */
	private function create_assets_for_express_handlers( array $settings = array(), array $account_data = array(), bool $woopay = false, bool $wallets_on = true ): WooPaymentsCheckoutAssets {
		$this->set_wallet_gateway_settings( $wallets_on );
		$account_service = $this->create_account_service(
			true,
			array_merge( self::EXPRESS_ACCOUNT_DATA, $account_data ),
			array_merge( self::EXPRESS_GATEWAY_SETTINGS, $settings )
		);

		$assets = new WooPaymentsCheckoutAssets();
		$assets->init( $account_service, $this->create_woopay_session_service( $woopay ), $this->create_woopay_eligibility( $woopay ), $this->create_frontend_styles_service(), $this->create_frontend_tracking_controller(), new PaymentMethodRegistry() );
		$this->inject_express_checkout_service( $assets, $account_service );

		return $assets;
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
	 * Create a WooPay eligibility double for checkout assets tests.
	 *
	 * @param bool $enabled                 Whether WooPay is enabled.
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 * @param bool $uses_stripe_platform    Whether checkout uses the Stripe platform account for WooPay.
	 * @return Eligibility|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_woopay_eligibility( bool $enabled, bool $direct_checkout_enabled = false, bool $uses_stripe_platform = false ) {
		$eligibility = $this->getMockBuilder( Eligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_enabled', 'is_woopay_direct_checkout_enabled', 'should_use_stripe_platform_on_checkout_page' ) )
			->getMock();

		$eligibility->method( 'is_woopay_enabled' )->willReturn( $enabled );
		$eligibility->method( 'should_use_stripe_platform_on_checkout_page' )->willReturn( $uses_stripe_platform );
		$eligibility->method( 'is_woopay_direct_checkout_enabled' )->willReturn( $direct_checkout_enabled );

		return $eligibility;
	}

	/**
	 * Create a WooPay session service mock for checkout assets tests: the WooPay config and the save-user data.
	 *
	 * @param bool $enabled                 Whether WooPay is enabled.
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 * @param bool $uses_stripe_platform    Whether checkout uses the Stripe platform account for WooPay.
	 * @return SessionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_woopay_session_service( bool $enabled, bool $direct_checkout_enabled = false, bool $uses_stripe_platform = false ) {
		$service = $this->getMockBuilder( SessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config', 'get_save_user_checkout_data' ) )
			->getMock();

		$service->method( 'get_woopay_frontend_config' )->willReturn(
			array(
				'isWooPayEnabled'                   => $enabled,
				'isWoopayExpressCheckoutEnabled'    => $enabled,
				'isWoopayFirstPartyAuthEnabled'     => $enabled,
				'isWooPayEmailInputEnabled'         => $enabled,
				'isWooPayDirectCheckoutEnabled'     => $direct_checkout_enabled,
				'isWooPayGlobalThemeSupportEnabled' => false,
				'forceNetworkSavedCards'            => false,
				'platformTrackerNonce'              => 'platform-tracks-nonce',
				'woopayHost'                        => 'https://pay.woo.com',
				'wcpayVersionNumber'                => \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION,
				'woopayMerchantId'                  => 12345,
				'initWooPayNonce'                   => 'init-woopay-nonce',
				'woopaySessionNonce'                => 'woopay-session-nonce',
				'woopaySignatureNonce'              => 'woopay-signature-nonce',
				'woopayMinimumSessionData'          => $enabled ? array( 'encrypted' => 'minimum' ) : array(),
				'woopayButton'                      => array(
					'type'    => 'default',
					'theme'   => 'dark',
					'height'  => '48',
					'radius'  => '4',
					'size'    => 'default',
					'context' => 'checkout',
				),
				'woopayButtonNonce'                 => 'woopay-button-nonce',
				'addToCartNonce'                    => 'add-to-cart-nonce',
				'shouldShowWooPayButton'            => true,
				'woopaySessionEmail'                => 'shopper@example.com',
				'woopayIsCountryAvailable'          => true,
				'woopayAppearance'                  => null,
				'woopayFontRules'                   => array(),
				'woopaySaveUserLabel'               => 'Securely save my information for 1-click checkout',
				'woopayPhoneLabel'                  => 'Mobile phone number',
			)
		);
		$service->method( 'get_save_user_checkout_data' )->willReturn( array( 'PRE_CHECK_SAVE_MY_INFO' => true ) );

		return $service;
	}

	/**
	 * Create a frontend styles service mock for checkout assets tests.
	 *
	 * @return WooPaymentsFrontendStylesService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_frontend_styles_service() {
		$service = $this->getMockBuilder( WooPaymentsFrontendStylesService::class )
			->onlyMethods( array( 'get_or_create_styles_cache_version' ) )
			->getMock();

		$service->method( 'get_or_create_styles_cache_version' )->willReturn( 'styles-v1' );

		return $service;
	}

	/**
	 * Create a frontend tracking controller mock for checkout assets tests.
	 *
	 * @return WooPaymentsFrontendTrackingController|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_frontend_tracking_controller() {
		$controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled', 'record_user_event' ) )
			->getMock();

		// The page flag asks the every-store question, not the WooPay one.
		$controller->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );
		$controller->init( wc_get_container()->get( WooPaymentsAccountService::class ) );

		return $controller;
	}

	/**
	 * Run a callback in wp-admin for a user whose locale is French while the site stays English, so determine_locale()
	 * gives fr_FR and get_locale() gives en_US.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's result.
	 */
	private function in_admin_with_french_user_locale( callable $callback ) {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'   => 'administrator',
					'locale' => 'fr_FR',
				)
			)
		);
		set_current_screen( 'dashboard' );

		try {
			return $callback();
		} finally {
			set_current_screen( 'front' );
			wp_set_current_user( 0 );
		}
	}
}
