<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionFacadeLoader as MultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Payments_Features as Features;
use WC_Unit_Test_Case;
use WCPay\MultiCurrency\MultiCurrency;

/**
 * Feature questions asked by extensions after the compatibility loader has registered.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WooPaymentsFeaturesFacadeTest extends WC_Unit_Test_Case {

	/**
	 * Seed an account and register the same two loaders that run at plugins_loaded.
	 *
	 * @param bool $active Whether checkout is enabled for the connected account.
	 * @return WooPaymentsAccountService
	 */
	private function load_facades( bool $active ): WooPaymentsAccountService {
		$container = wc_get_container();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'           => $active ? 'yes' : 'no',
				'platform_checkout' => 'yes',
			)
		);

		$client = $this->createMock( Client::class );
		$client->method( 'is_available' )->willReturn( true );
		$client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );
		$container->replace( Client::class, $client );

		$account = $container->get( WooPaymentsAccountService::class );
		$account->cache_account_data(
			array(
				'account_id'                            => 'acct_123',
				'is_live'                               => true,
				'payments_enabled'                      => true,
				'platform_checkout_eligible'            => true,
				'platform_direct_checkout_eligible'     => true,
				'platform_woopay_force_signed_requests' => true,
				'platform_global_theme_support_enabled' => true,
				'is_documents_enabled'                  => true,
				'reports_area_enabled'                  => false,
			)
		);
		$this->assertSame( $active ? WooPaymentsSetupTier::ACTIVE : WooPaymentsSetupTier::CONNECTED, $container->get( WooPaymentsSetupTier::class )->get_effective_tier() );
		$container->get( WooPaymentsExtensionFacadeLoader::class )->load();
		$container->get( MultiCurrencyFacadeLoader::class )->load();
		return $account;
	}

	/**
	 * @testdox CartFlows can check WooPay eligibility on every request and observes later changes to the account.
	 */
	public function test_cartflows_woopay_check(): void {
		$account = $this->load_facades( true );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::is_woopay_eligible()' );
		$this->assertTrue( class_exists( 'WC_Payments' ) && class_exists( 'WC_Payments_Features' ) && Features::is_woopay_eligible() );

		$data                               = $account->get_stored_account_data();
		$data['platform_checkout_eligible'] = false;
		$account->cache_account_data( $data );
		$this->assertFalse( Features::is_woopay_eligible() );
	}

	/**
	 * @testdox Feature answers follow the current account, settings and built-in capabilities at both exposed tiers.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $active Whether checkout is enabled.
	 */
	public function test_feature_answers( bool $active ): void {
		$this->load_facades( $active );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( '_wcpay_feature_stripe_billing', '1' );
		update_option( 'wcpay_frt_review_feature_active', '1' );
		update_option( 'wcpay_fraud_protection_welcome_tour_dismissed', '1' );
		update_option( 'wcpay_next_deposit_notice_dismissed', '1' );
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );

		$answers  = array(
			'are_payments_enabled'                         => true,
			'is_woopay_enabled'                            => true,
			'is_customer_multi_currency_enabled'           => true,
			'is_wcpay_subscriptions_enabled'               => false,
			'is_wcpay_subscriptions_eligible'              => false,
			'is_woopay_eligible'                           => true,
			'is_woopay_force_signed_requests_enabled'      => true,
			'is_documents_section_enabled'                 => true,
			'is_woopay_express_checkout_enabled'           => true,
			'is_woopay_direct_checkout_enabled'            => $active,
			'is_woopay_global_theme_support_eligible'      => true,
			'is_frt_review_feature_active'                 => true,
			'is_fraud_protection_welcome_tour_dismissed'   => true,
			'is_stripe_billing_enabled'                    => true,
			'is_stripe_billing_eligible'                   => true,
			'should_use_stripe_billing'                    => false,
			'is_dispute_issuer_evidence_enabled'           => false,
			'is_dispute_additional_evidence_types_enabled' => true,
			'is_dispute_outcome_view_enabled'              => true,
			'is_dispute_readiness_overview_enabled'        => true,
			'is_reports_area_enabled'                      => false,
			'is_next_deposit_notice_dismissed'             => true,
			'is_dynamic_checkout_place_order_button_enabled' => true,
			'is_mc_cache_optimized_enabled'                => true,
			'is_amazon_pay_enabled'                        => true,
			'is_ece_confirmation_tokens_enabled'           => true,
		);
		$warnings = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $member, $message, $version ) use ( &$warnings ) {
				$warnings[] = array( $member, $message, $version );
			},
			10,
			3
		);
		foreach ( $answers as $method => $expected ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Features::' . $method . '()' );
			$this->assertSame( $expected, Features::$method(), $method );
		}

		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::to_array()' );
		$this->assertSame(
			array_fill_keys(
				array( 'multiCurrency', 'woopay', 'documents', 'woopayExpressCheckout', 'isDisputeAdditionalEvidenceTypesEnabled', 'isDisputeOutcomeViewEnabled', 'isDisputeReadinessOverviewEnabled', 'isFRTReviewFeatureActive', 'isDynamicCheckoutPlaceOrderButtonEnabled', 'amazonPay', 'isEceUsingConfirmationTokens' ),
				true
			),
			Features::to_array()
		);
		$expected_warnings = array();
		foreach ( array_merge( array_keys( $answers ), array( 'to_array' ) ) as $method ) {
			Features::$method();
			$member              = 'WC_Payments_Features::' . $method . '()';
			$message             = 'is_customer_multi_currency_enabled' === $method
				? 'Use Automattic\WooCommerce\Utilities\MultiCurrencyUtil::is_active() instead.'
				: $member . ' is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.';
			$expected_warnings[] = array( $member, $message, '11.4.0' );
		}
		$this->assertSame( $expected_warnings, $warnings );

		update_option( '_wcpay_feature_woopay_express_checkout', '0' );
		$this->assertFalse( Features::is_woopay_enabled() );
		$this->assertFalse( Features::is_woopay_express_checkout_enabled() );
		$this->assertFalse( Features::is_woopay_direct_checkout_enabled() );
	}

	/**
	 * @testdox Pricewise can finish its check and skip its legacy global conversion function.
	 */
	public function test_pricewise_currency_check(): void {
		$this->load_facades( false );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::is_customer_multi_currency_enabled()' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ) && Features::is_customer_multi_currency_enabled() );
		$this->assertFalse( function_exists( 'WC_Payments_Multi_Currency' ) );
	}

	/**
	 * @testdox FOX can read the enabled currencies and the default currency through the facade.
	 */
	public function test_currency_consumers_follow_module_ownership(): void {
		$this->load_facades( false );
		update_option( 'woocommerce_currency', 'USD' );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::is_customer_multi_currency_enabled()' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ) && class_exists( MultiCurrency::class ) && Features::is_customer_multi_currency_enabled() );
		foreach ( array( 'instance', 'get_enabled_currencies', 'get_default_currency' ) as $method ) {
			$this->setExpectedIncorrectUsage( MultiCurrency::class . '::' . $method . '()' );
		}
		$module = MultiCurrency::instance();
		$this->assertArrayHasKey( 'USD', $module->get_enabled_currencies() );
		$this->assertSame( 'USD', $module->get_default_currency()->get_code() );
	}

	/**
	 * @testdox Guarded integrations can call the predicate and read the Multi-Currency setting.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $enabled Whether the module is on.
	 */
	public function test_guarded_currency_integrations( bool $enabled ): void {
		$this->load_facades( false );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, $enabled ? 'yes' : 'no' );
		$this->assertTrue( is_callable( array( Features::class, 'is_customer_multi_currency_enabled' ) ) );
		$this->assertTrue( method_exists( Features::class, 'is_customer_multi_currency_enabled' ) );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::is_customer_multi_currency_enabled()' );
		$this->assertSame( $enabled, Features::is_customer_multi_currency_enabled() );
	}

	/**
	 * @testdox An empty account read keeps the active tier, so only an explicit drop lowers it. The registered facade still answers at the lower tier.
	 * @testWith ["is_customer_multi_currency_enabled"]
	 *           ["is_reports_area_enabled"]
	 *
	 * @param string $method Predicate called after the account disappears.
	 */
	public function test_account_disappears_after_loader_registration( string $method ): void {
		$account = $this->load_facades( true );
		$tier    = wc_get_container()->get( WooPaymentsSetupTier::class );
		update_option( '_wcpay_feature_reports_area', '1' );
		$account->cache_account_data_until_refreshed( array( 'account_id' => 'acct_123' ) );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::' . $method . '()' );
		$before_read = Features::$method();
		$this->assertSame( array(), $account->get_cached_account_data() );
		$this->assertSame( array( WooPaymentsSetupTier::ACTIVE, $before_read ), array( $tier->get_effective_tier(), Features::$method() ) );

		$account->drop_to_available();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $tier->get_effective_tier() );
		$this->assertTrue( class_exists( MultiCurrency::class ) && class_exists( 'WC_Payments_Features' ) && Features::$method() );
	}
}
