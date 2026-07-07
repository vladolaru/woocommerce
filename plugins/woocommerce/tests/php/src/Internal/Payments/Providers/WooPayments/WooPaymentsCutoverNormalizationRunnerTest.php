<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments cutover normalization runner.
 */
class WooPaymentsCutoverNormalizationRunnerTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const NORMALIZED_OPTION = 'woocommerce_native_woopayments_cutover_normalization_version';

	/**
	 * System under test.
	 *
	 * @var WooPaymentsCutoverNormalizationRunner|null
	 */
	private $runner = null;

	/**
	 * User fixture ID used by stale BNPL meta cleanup tests.
	 *
	 * @var int|null
	 */
	private ?int $user_id = null;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->runner instanceof WooPaymentsCutoverNormalizationRunner ) {
			remove_action( 'init', array( $this->runner, 'maybe_run' ), 1 );
		}

		delete_option( self::SETTINGS_OPTION );
		delete_option( self::VERSION_OPTION );
		delete_option( self::NORMALIZED_OPTION );
		delete_option( 'woocommerce_woocommerce_payments_apple_pay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_google_pay_settings' );
		delete_option( '_wcpay_feature_auth_and_capture' );
		delete_option( '_wcpay_feature_sofort' );
		delete_option( 'wcpay_onboarding_eligibility_modal_dismissed' );
		delete_option( 'wcpay_multi_currency_cache_autodetect_done' );
		delete_transient( 'wcpay_upe_appearance' );
		delete_transient( 'wcpay_upe_bnpl_cart_block_appearance_theme' );
		delete_transient( 'wcpay_bnpl_april15_successful_purchases_count' );

		if ( null !== $this->user_id ) {
			delete_user_meta( $this->user_id, '_wcpay_bnpl_april15_viewed' );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Registers the one-shot normalization hook only when native owns WooPayments.
	 */
	public function test_register_is_native_runtime_gated(): void {
		$inactive_runner = $this->create_runner( false );

		$inactive_runner->register();

		$this->assertFalse( has_action( 'init', array( $inactive_runner, 'maybe_run' ) ) );

		$active_runner = $this->create_runner( true );

		$active_runner->register();

		$this->assertSame( 1, has_action( 'init', array( $active_runner, 'maybe_run' ) ) );
	}

	/**
	 * @testdox Normalizes pre-cutover gateway option shapes on the first native-owned request.
	 */
	public function test_run_normalizes_legacy_gateway_option_shapes_once(): void {
		$this->seed_legacy_options();

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertIsArray( $stored );
		$this->assertTrue( $summary['ran'] );
		$this->assertContains( 'express_checkout_locations', $summary['changes'] );
		$this->assertContains( 'payment_request_split_settings', $summary['changes'] );
		$this->assertContains( 'amazon_pay_express_checkout_locations', $summary['changes'] );
		$this->assertContains( 'payment_request_button_size', $summary['changes'] );
		$this->assertContains( 'payment_request_button_type', $summary['changes'] );
		$this->assertContains( 'manual_capture_payment_methods', $summary['changes'] );
		$this->assertContains( 'link_woopay_mutual_exclusion', $summary['changes'] );
		$this->assertSame( 'yes', $stored['payment_request'], 'Native still reads this flag and the cutover normalizer must not remove it yet.' );
		$this->assertArrayNotHasKey( 'payment_request_button_locations', $stored );
		$this->assertArrayNotHasKey( 'platform_checkout_button_locations', $stored );
		$this->assertArrayNotHasKey( 'payment_request_button_branded_type', $stored );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'woopay', 'amazon_pay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_checkout_methods'] );
		$this->assertSame( 'small', $stored['payment_request_button_size'] );
		$this->assertSame( 'buy', $stored['payment_request_button_type'] );
		$this->assertSame( array( 'card', 'apple_pay', 'google_pay', 'amazon_pay' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'enabled' => 'yes' ), get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' ) );
		$this->assertSame( array( 'enabled' => 'yes' ), get_option( 'woocommerce_woocommerce_payments_google_pay_settings' ) );
		$this->assertSame( '1', get_option( self::NORMALIZED_OPTION ) );

		$second_summary = $this->create_runner()->run();

		$this->assertFalse( $second_summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $second_summary['changes'] );
	}

	/**
	 * @testdox Cleans stale local state that is safe to remove during native cutover.
	 */
	public function test_run_deletes_stale_transients_options_and_user_meta(): void {
		$this->seed_legacy_options();
		update_option( '_wcpay_feature_auth_and_capture', '1' );
		update_option( '_wcpay_feature_sofort', '1' );
		update_option( 'wcpay_onboarding_eligibility_modal_dismissed', true );
		set_transient( 'wcpay_upe_appearance', array( 'theme' => 'stale' ) );
		set_transient( 'wcpay_upe_bnpl_cart_block_appearance_theme', array( 'theme' => 'stale' ) );
		set_transient( 'wcpay_bnpl_april15_successful_purchases_count', 3 );
		$this->user_id = self::factory()->user->create();
		update_user_meta( $this->user_id, '_wcpay_bnpl_april15_viewed', '1' );

		$summary = $this->create_runner()->run();

		$this->assertContains( 'appearance_transients', $summary['changes'] );
		$this->assertContains( 'deprecated_flags_and_options', $summary['changes'] );
		$this->assertContains( 'bnpl_announcement_state', $summary['changes'] );
		$this->assertContains( 'multi_currency_cache_autodetect', $summary['changes'] );
		$this->assertFalse( get_option( '_wcpay_feature_auth_and_capture' ) );
		$this->assertFalse( get_option( '_wcpay_feature_sofort' ) );
		$this->assertFalse( get_option( 'wcpay_onboarding_eligibility_modal_dismissed' ) );
		$this->assertFalse( get_transient( 'wcpay_upe_appearance' ) );
		$this->assertFalse( get_transient( 'wcpay_upe_bnpl_cart_block_appearance_theme' ) );
		$this->assertFalse( get_transient( 'wcpay_bnpl_april15_successful_purchases_count' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_cache_autodetect_done' ) );
		$this->assertSame( '', get_user_meta( $this->user_id, '_wcpay_bnpl_april15_viewed', true ) );
	}

	/**
	 * Create the runner under test.
	 *
	 * @param bool $native_register Whether native runtime should own registration.
	 * @return WooPaymentsCutoverNormalizationRunner
	 */
	private function create_runner( bool $native_register = true ): WooPaymentsCutoverNormalizationRunner {
		$this->assertTrue( class_exists( WooPaymentsCutoverNormalizationRunner::class ), 'WooPaymentsCutoverNormalizationRunner should exist.' );

		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$this->runner = new WooPaymentsCutoverNormalizationRunner();
		$this->runner->init( $arbiter );

		return $this->runner;
	}

	/**
	 * Seed legacy WooPayments option shapes.
	 */
	private function seed_legacy_options(): void {
		update_option( self::VERSION_OPTION, '2.5.0' );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled'                             => 'yes',
				'payment_request'                     => 'yes',
				'payment_request_button_locations'    => array( 'product', 'checkout' ),
				'platform_checkout_button_locations'  => array( 'cart' ),
				'payment_request_button_size'         => 'default',
				'payment_request_button_type'         => 'branded',
				'payment_request_button_branded_type' => 'long',
				'manual_capture'                      => 'yes',
				'platform_checkout'                   => 'yes',
				'upe_enabled_payment_method_ids'      => array(
					'card',
					'link',
					'sepa_debit',
					'apple_pay',
					'google_pay',
					'amazon_pay',
					'ideal',
				),
			)
		);
	}
}
