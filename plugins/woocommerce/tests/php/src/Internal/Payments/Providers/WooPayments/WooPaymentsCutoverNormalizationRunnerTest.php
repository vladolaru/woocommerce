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
		delete_option( 'woocommerce_woocommerce_payments_ideal_settings' );
		delete_option( 'woocommerce_woocommerce_payments_giropay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_sofort_settings' );
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
		$this->assertContains( 'split_gateway_settings', $summary['changes'] );
		$this->assertContains( 'amazon_pay_express_checkout_locations', $summary['changes'] );
		$this->assertContains( 'payment_request_button_size', $summary['changes'] );
		$this->assertContains( 'payment_request_button_type', $summary['changes'] );
		$this->assertContains( 'manual_capture_payment_methods', $summary['changes'] );
		$this->assertContains( 'link_woopay_mutual_exclusion', $summary['changes'] );
		$this->assertArrayNotHasKey( 'payment_request', $stored );
		$this->assertArrayNotHasKey( 'payment_request_button_locations', $stored );
		$this->assertArrayNotHasKey( 'platform_checkout_button_locations', $stored );
		$this->assertArrayNotHasKey( 'payment_request_button_branded_type', $stored );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'woopay', 'amazon_pay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_checkout_methods'] );
		$this->assertSame( 'small', $stored['payment_request_button_size'] );
		$this->assertSame( 'buy', $stored['payment_request_button_type'] );
		$this->assertSame( array( 'card', 'amazon_pay' ), $stored['upe_enabled_payment_method_ids'] );
		$expected_split_settings = array( 'enabled' => 'yes' );
		$this->assertSame( $expected_split_settings, get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' ) );
		$this->assertSame( $expected_split_settings, get_option( 'woocommerce_woocommerce_payments_google_pay_settings' ) );
		$this->assertSame( '2', get_option( self::NORMALIZED_OPTION ) );

		$second_summary = $this->create_runner()->run();

		$this->assertFalse( $second_summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $second_summary['changes'] );
	}

	/**
	 * @testdox A partial gateway projection leaves cutover incomplete and preserves later cleanup state.
	 */
	public function test_run_does_not_complete_when_gateway_settings_projection_fails(): void {
		$this->seed_legacy_options();
		set_transient( 'wcpay_upe_appearance', array( 'theme' => 'stale' ) );
		$option_name = 'woocommerce_woocommerce_payments_giropay_settings';
		update_option( $option_name, array( 'enabled' => 'yes' ) );
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $option_name, $reject_update, 10, 2 );

		try {
			$summary = $this->create_runner()->run();
		} finally {
			remove_filter( 'pre_update_option_' . $option_name, $reject_update, 10 );
		}

		$this->assertFalse( $summary['ran'] );
		$this->assertSame( array( 'settings_persistence_failed' ), $summary['changes'] );
		$this->assertFalse( get_option( self::NORMALIZED_OPTION, false ) );
		$this->assertSame( array( 'theme' => 'stale' ), get_transient( 'wcpay_upe_appearance' ) );
	}

	/**
	 * @testdox Existing split wallet settings take precedence over the stale payment request setting.
	 */
	public function test_run_preserves_existing_asymmetric_split_wallet_settings(): void {
		update_option( self::VERSION_OPTION, '10.3.0' );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'payment_request'                => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_apple_pay_settings',
			array(
				'enabled'     => 'no',
				'button_type' => 'plain',
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_google_pay_settings',
			array(
				'enabled'     => 'yes',
				'button_type' => 'buy',
			)
		);

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertContains( 'payment_request_split_settings', $summary['changes'] );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'payment_request', $stored );
		$this->assertSame(
			array(
				'enabled'     => 'no',
				'button_type' => 'plain',
			),
			get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )
		);
		$this->assertSame(
			array(
				'enabled'     => 'yes',
				'button_type' => 'buy',
			),
			get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )
		);
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
	 * @testdox Cutover removes deprecated method IDs and projects canonical split gateway availability.
	 */
	public function test_run_projects_split_gateway_settings_and_removes_deprecated_methods(): void {
		update_option( self::VERSION_OPTION, '7.3.0' );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'manual_capture'                 => 'no',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal', 'giropay', 'sofort' ),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_giropay_settings', array( 'enabled' => 'yes' ) );
		update_option( 'woocommerce_woocommerce_payments_sofort_settings', array( 'enabled' => 'yes' ) );

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( array( 'card', 'ideal' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertContains( 'deprecated_payment_methods', $summary['changes'] );
		$this->assertContains( 'split_gateway_settings', $summary['changes'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_giropay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_sofort_settings' )['enabled'] );
	}

	/**
	 * @testdox Versioned settings migrations honor before, equal, and after boundaries and are intrinsically idempotent.
	 * @dataProvider versioned_settings_migration_provider
	 *
	 * @param string              $method_name     Migration method name.
	 * @param string              $before_version  Version before the migration boundary.
	 * @param string              $boundary_version Exact migration boundary.
	 * @param string              $after_version   Version after the migration boundary.
	 * @param array<string,mixed> $initial         Pre-migration settings.
	 * @param array<string,mixed> $expected        Expected migrated settings.
	 */
	public function test_versioned_settings_migration_boundaries(
		string $method_name,
		string $before_version,
		string $boundary_version,
		string $after_version,
		array $initial,
		array $expected
	): void {
		$runner    = $this->create_runner();
		$method    = new \ReflectionMethod( $runner, $method_name );
		$settings  = $initial;
		$arguments = array( &$settings, $before_version );

		$this->assertTrue( $method->invokeArgs( $runner, $arguments ) );
		$this->assertSame( $expected, $settings );

		$arguments = array( &$settings, $before_version );
		$this->assertFalse( $method->invokeArgs( $runner, $arguments ), 'A migrated shape should be an intrinsic no-op when the outer marker is absent.' );
		$this->assertSame( $expected, $settings );

		foreach ( array( $boundary_version, $after_version ) as $non_migrating_version ) {
			$settings  = $initial;
			$arguments = array( &$settings, $non_migrating_version );
			$this->assertFalse( $method->invokeArgs( $runner, $arguments ) );
			$this->assertSame( $initial, $settings );
		}
	}

	/**
	 * Versioned settings migration fixtures.
	 *
	 * @return array<string,array{string,string,string,string,array<string,mixed>,array<string,mixed>}>
	 */
	public function versioned_settings_migration_provider(): array {
		return array(
			'express checkout locations'  => array(
				'migrate_express_checkout_locations',
				'10.3.9',
				'10.4.0',
				'10.4.1',
				array(
					'payment_request_button_locations'   => array( 'product', 'checkout' ),
					'platform_checkout_button_locations' => array( 'cart' ),
				),
				array(
					'express_checkout_product_methods'  => array( 'payment_request' ),
					'express_checkout_cart_methods'     => array( 'woopay' ),
					'express_checkout_checkout_methods' => array( 'payment_request' ),
				),
			),
			'Amazon Pay locations'        => array(
				'add_amazon_pay_to_express_checkout_locations',
				'10.4.9',
				'10.5.0',
				'10.5.1',
				array(
					'express_checkout_product_methods'  => array( 'payment_request' ),
					'express_checkout_cart_methods'     => array( 'woopay' ),
					'express_checkout_checkout_methods' => array(),
				),
				array(
					'express_checkout_product_methods'  => array( 'payment_request', 'amazon_pay' ),
					'express_checkout_cart_methods'     => array( 'woopay', 'amazon_pay' ),
					'express_checkout_checkout_methods' => array( 'amazon_pay' ),
				),
			),
			'payment request button size' => array(
				'normalize_payment_request_button_size',
				'6.8.9',
				'6.9.0',
				'6.9.1',
				array( 'payment_request_button_size' => 'default' ),
				array( 'payment_request_button_size' => 'small' ),
			),
			'payment request button type' => array(
				'normalize_payment_request_button_type',
				'2.5.9',
				'2.6.0',
				'2.6.1',
				array(
					'payment_request_button_type'         => 'branded',
					'payment_request_button_branded_type' => 'long',
				),
				array( 'payment_request_button_type' => 'buy' ),
			),
		);
	}

	/**
	 * @testdox Unversioned settings normalizers are intrinsically idempotent.
	 * @dataProvider unversioned_settings_migration_provider
	 *
	 * @param string              $method_name Migration method name.
	 * @param array<string,mixed> $initial     Pre-migration settings.
	 * @param array<string,mixed> $expected    Expected migrated settings.
	 */
	public function test_unversioned_settings_migrations_are_idempotent( string $method_name, array $initial, array $expected ): void {
		$runner   = $this->create_runner();
		$method   = new \ReflectionMethod( $runner, $method_name );
		$settings = $initial;

		$this->assertTrue( $method->invokeArgs( $runner, array( &$settings ) ) );
		$this->assertSame( $expected, $settings );
		$this->assertFalse( $method->invokeArgs( $runner, array( &$settings ) ) );
		$this->assertSame( $expected, $settings );
	}

	/**
	 * Unversioned settings migration fixtures.
	 *
	 * @return array<string,array{string,array<string,mixed>,array<string,mixed>}>
	 */
	public function unversioned_settings_migration_provider(): array {
		return array(
			'manual capture methods'    => array(
				'normalize_manual_capture_payment_methods',
				array(
					'manual_capture'                 => 'yes',
					'upe_enabled_payment_method_ids' => array( 'card', 'ideal', 'amazon_pay' ),
				),
				array(
					'manual_capture'                 => 'yes',
					'upe_enabled_payment_method_ids' => array( 'card', 'amazon_pay' ),
				),
			),
			'Link and WooPay exclusion' => array(
				'normalize_link_woopay_mutual_exclusion',
				array(
					'platform_checkout'                 => 'yes',
					'upe_enabled_payment_method_ids'    => array( 'card', 'link' ),
					'express_checkout_product_methods'  => array( 'link', 'payment_request' ),
					'express_checkout_cart_methods'     => array( 'woopay', 'link' ),
					'express_checkout_checkout_methods' => array( 'link' ),
				),
				array(
					'platform_checkout'                 => 'yes',
					'upe_enabled_payment_method_ids'    => array( 'card' ),
					'express_checkout_product_methods'  => array( 'payment_request' ),
					'express_checkout_cart_methods'     => array( 'woopay' ),
					'express_checkout_checkout_methods' => array(),
				),
			),
		);
	}

	/**
	 * @testdox Multi-currency cache autodetection honors its version boundary and rerun contract.
	 */
	public function test_multi_currency_cache_autodetect_version_boundary(): void {
		$runner = $this->create_runner();
		$method = new \ReflectionMethod( $runner, 'mark_multi_currency_cache_autodetect_done' );

		$this->assertTrue( $method->invoke( $runner, '10.9.9' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_cache_autodetect_done' ) );
		$this->assertFalse( $method->invoke( $runner, '10.9.9' ) );

		delete_option( 'wcpay_multi_currency_cache_autodetect_done' );
		$this->assertFalse( $method->invoke( $runner, '11.0.0' ) );
		$this->assertFalse( $method->invoke( $runner, '11.0.1' ) );
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
					'amazon_pay',
					'ideal',
				),
			)
		);
	}
}
