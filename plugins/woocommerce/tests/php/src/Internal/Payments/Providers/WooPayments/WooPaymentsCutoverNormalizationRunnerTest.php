<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments cutover normalization runner.
 */
class WooPaymentsCutoverNormalizationRunnerTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const NORMALIZED_OPTION = 'woocommerce_woopayments_cutover_normalization_version';

	/**
	 * Shared settings keys native does not know, one per value shape; normalization must keep them as they are.
	 */
	private const UNKNOWN_SETTINGS = array(
		'future_string_key' => 'kept',
		'future_int_key'    => 7,
		'future_bool_key'   => true,
		'future_float_key'  => 1.5,
		'future_null_key'   => null,
		'future_list_key'   => array( 'a', 'b' ),
		'future_map_key'    => array(
			'nested' => array( 'deep' => 'value' ),
		),
	);

	/**
	 * Split per-method options with keys native never writes.
	 */
	private const SPLIT_SETTINGS = array(
		'woocommerce_woocommerce_payments_apple_pay_settings' => array(
			'enabled'     => 'yes',
			'button_type' => 'plain',
			'future_key'  => array( 'nested' => true ),
		),
		'woocommerce_woocommerce_payments_ideal_settings' => array(
			'enabled'    => 'yes',
			'title'      => 'iDEAL',
			'future_key' => 7,
		),
	);

	/**
	 * Options WooPayments 10.5.0 and 11.1.0 both read, which normalization must never delete.
	 */
	private const PLUGIN_READ_OPTIONS = array(
		'wcpay_multi_currency_enabled_currencies'         => array( 'USD', 'EUR' ),
		'wcpay_multi_currency_stored_customer_currencies' => array( 'EUR' ),
		'_wcpay_feature_woopay_express_checkout'          => '1',
	);

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
		$this->assertSame( array( 'product', 'cart', 'checkout' ), $stored['payment_request_button_locations'] );
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
		$this->assertSame( '4', get_option( self::NORMALIZED_OPTION ) );

		$second_summary = $this->create_runner()->run();

		$this->assertFalse( $second_summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $second_summary['changes'] );
	}

	/**
	 * @testdox Stores the completed cutover marker in the autoloaded option set.
	 */
	public function test_run_stores_completed_marker_as_an_autoloaded_option(): void {
		$summary    = $this->create_runner()->run();
		$alloptions = wp_load_alloptions( true );

		$this->assertTrue( $summary['ran'] );
		$this->assertSame( '4', $alloptions[ self::NORMALIZED_OPTION ] ?? null, 'Completed cutover normalization must not leave its marker as a request-level option read.' );
	}

	/**
	 * @testdox Repairs a historical non-autoloaded marker without rerunning cutover normalization.
	 */
	public function test_run_repairs_historical_non_autoloaded_marker_without_rerunning_normalization(): void {
		add_option( self::NORMALIZED_OPTION, '4', '', false );

		$this->assertArrayNotHasKey( self::NORMALIZED_OPTION, wp_load_alloptions( true ), 'The historical marker fixture must begin outside alloptions.' );

		$summary    = $this->create_runner()->run();
		$alloptions = wp_load_alloptions( true );

		$this->assertFalse( $summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $summary['changes'] );
		$this->assertSame( '4', $alloptions[ self::NORMALIZED_OPTION ] ?? null, 'An existing cutover marker must be repaired into alloptions.' );
	}

	/**
	 * @testdox Reruns only deprecated method cleanup for the previous completed marker.
	 */
	public function test_run_reruns_deprecated_method_cleanup_from_previous_completed_marker(): void {
		update_option( self::NORMALIZED_OPTION, '3' );
		update_option( self::VERSION_OPTION, '10.4.0' );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'upe_enabled_payment_method_ids'    => array( 'card', 'ideal', 'sofort' ),
				'upe_available_payment_methods'     => array( 'card', 'ideal', 'sofort' ),
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array( 'woopay' ),
				'express_checkout_checkout_methods' => array(),
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_sofort_settings',
			array(
				'enabled' => 'yes',
				'custom'  => 'preserve-me',
			)
		);

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertTrue( $summary['ran'] );
		$this->assertSame( array( 'deprecated_payment_methods', 'split_gateway_settings' ), $summary['changes'] );
		$this->assertSame( array( 'card', 'ideal' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'card', 'ideal' ), $stored['upe_available_payment_methods'] );
		$this->assertSame( array( 'payment_request' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'woopay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array(), $stored['express_checkout_checkout_methods'] );
		$this->assertArrayNotHasKey( 'manual_capture', $stored );
		$this->assertSame(
			array(
				'enabled'                        => 'no',
				'custom'                         => 'preserve-me',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			),
			get_option( 'woocommerce_woocommerce_payments_sofort_settings' )
		);
		$this->assertSame( '4', wp_load_alloptions( true )[ self::NORMALIZED_OPTION ] ?? null );

		$second_summary = $this->create_runner()->run();

		$this->assertFalse( $second_summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $second_summary['changes'] );
	}

	/**
	 * @testdox Retains the previous marker when a deprecated split gateway update fails and retries it.
	 */
	public function test_run_retries_deprecated_method_cleanup_after_split_gateway_write_failure(): void {
		$option_name = 'woocommerce_woocommerce_payments_sofort_settings';
		update_option( self::NORMALIZED_OPTION, '3' );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'sofort' ),
				'upe_available_payment_methods'  => array( 'card', 'sofort' ),
			)
		);
		update_option(
			$option_name,
			array(
				'enabled' => 'yes',
				'custom'  => 'preserve-me',
			)
		);
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $option_name, $reject_update, 10, 2 );

		try {
			$failed_summary = $this->create_runner()->run();
		} finally {
			remove_filter( 'pre_update_option_' . $option_name, $reject_update, 10 );
		}

		$this->assertFalse( $failed_summary['ran'] );
		$this->assertSame( array( 'settings_persistence_failed' ), $failed_summary['changes'] );
		$this->assertSame( '3', get_option( self::NORMALIZED_OPTION ) );
		$this->assertSame( array( 'card' ), get_option( self::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'card' ), get_option( self::SETTINGS_OPTION )['upe_available_payment_methods'] );
		$this->assertSame( 'yes', get_option( $option_name )['enabled'] );

		$retry_summary = $this->create_runner()->run();

		$this->assertTrue( $retry_summary['ran'] );
		$this->assertSame( array( 'split_gateway_settings' ), $retry_summary['changes'] );
		$this->assertSame( '4', get_option( self::NORMALIZED_OPTION ) );
		$this->assertSame( 'no', get_option( $option_name )['enabled'] );

		$third_summary = $this->create_runner()->run();

		$this->assertFalse( $third_summary['ran'] );
		$this->assertSame( array( 'already_normalized' ), $third_summary['changes'] );
	}

	/**
	 * @testdox Should add missing form-field defaults without overwriting saved settings during cutover.
	 */
	public function test_run_adds_missing_form_field_defaults_without_overwriting_saved_settings(): void {
		update_option( self::VERSION_OPTION, '10.8.0' );
		update_option( self::SETTINGS_OPTION, array( 'saved_cards' => 'no' ) );

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertTrue( $summary['ran'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'no', $stored['saved_cards'] );
		$this->assertArrayHasKey( 'manual_capture', $stored );
		$this->assertSame( 'no', $stored['manual_capture'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $stored['express_checkout_checkout_methods'] );
	}

	/**
	 * @testdox Should persist localized form-field defaults during cutover.
	 */
	public function test_run_persists_localized_form_field_defaults(): void {
		update_option( self::VERSION_OPTION, '10.8.0' );
		$translations       = array(
			'Buy now' => 'Jetzt kaufen',
			'By placing this order, you agree to our [terms] and understand our [privacy_policy].' => 'Mit der Bestellung stimmen Sie unseren [terms] zu und verstehen unsere [privacy_policy].',
		);
		$translation_filter = static function ( string $translation, string $text, string $domain ) use ( $translations ): string {
			return 'woocommerce' === $domain ? ( $translations[ $text ] ?? $translation ) : $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );

		try {
			$this->create_runner()->run();
			$stored = get_option( self::SETTINGS_OPTION );
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
		}

		$this->assertIsArray( $stored );
		$this->assertSame( 'Jetzt kaufen', $stored['payment_request_button_label'] );
		$this->assertSame( 'Mit der Bestellung stimmen Sie unseren [terms] zu und verstehen unsere [privacy_policy].', $stored['platform_checkout_custom_message'] );
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
	 * @testdox Clears the plugin's WP-Cron WooPay compatibility event that its deactivation hook can leave behind.
	 *
	 * Source: client 11.1.0 includes/woopay/class-woopay-scheduler.php:63-68 schedules it daily; :51, :57-59 clear it.
	 */
	public function test_run_clears_the_legacy_woopay_compatibility_cron_event(): void {
		$this->seed_legacy_options();
		wp_schedule_event( time(), 'daily', 'validate_woopay_compatibility' );

		$summary = $this->create_runner()->run();

		$this->assertContains( 'legacy_woopay_compatibility_cron', $summary['changes'] );
		$this->assertFalse( wp_next_scheduled( 'validate_woopay_compatibility' ) );
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
		$runner = $this->create_runner();
		$method = new \ReflectionMethod( $runner, $method_name );
		$method->setAccessible( true );
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
		$runner = $this->create_runner();
		$method = new \ReflectionMethod( $runner, $method_name );
		$method->setAccessible( true );
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
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $runner, '10.9.9' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_cache_autodetect_done' ) );
		$this->assertFalse( $method->invoke( $runner, '10.9.9' ) );

		delete_option( 'wcpay_multi_currency_cache_autodetect_done' );
		$this->assertFalse( $method->invoke( $runner, '11.0.0' ) );
		$this->assertFalse( $method->invoke( $runner, '11.0.1' ) );
	}

	/**
	 * @testdox Every retired name normalization may remove was last read by a plugin version below the cutover floor.
	 */
	public function test_retired_allowlists_stay_at_or_below_the_cutover_plugin_floor(): void {
		$allowlists = array(
			'RETIRED_SETTINGS_KEYS' => WooPaymentsCutoverNormalizationRunner::RETIRED_SETTINGS_KEYS,
			'RETIRED_OPTIONS'       => WooPaymentsCutoverNormalizationRunner::RETIRED_OPTIONS,
			'RETIRED_TRANSIENTS'    => WooPaymentsCutoverNormalizationRunner::RETIRED_TRANSIENTS,
		);
		$floors     = array(
			WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION,
			WooPaymentsCutoverController::MINIMUM_CUTOVER_PLUGIN_VERSION,
		);

		foreach ( $allowlists as $allowlist_name => $allowlist ) {
			$this->assertNotEmpty( $allowlist, "{$allowlist_name} must not be empty." );
			foreach ( $allowlist as $name => $stopped_reading_version ) {
				$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/D', $stopped_reading_version, "{$allowlist_name}[{$name}] must name a plugin release version." );
				foreach ( $floors as $floor ) {
					$this->assertTrue(
						version_compare( $stopped_reading_version, $floor, '<=' ),
						"{$allowlist_name}[{$name}] was read until {$stopped_reading_version}, above the cutover floor {$floor}: a plugin re-activated after rollback could still read it."
					);
				}
			}
		}
	}

	/**
	 * @testdox Normalization removes or reshapes only allowlisted shared settings keys ($fixture_name).
	 * @dataProvider shared_settings_contract_provider
	 *
	 * @param string              $fixture_name     Fixture label.
	 * @param string              $previous_version Last active plugin version.
	 * @param array<string,mixed> $settings         Settings before normalization, without the unknown keys.
	 * @param bool                $expects_removal  Whether the fixture holds retired keys that must go.
	 */
	public function test_run_removes_or_reshapes_only_allowlisted_shared_settings( string $fixture_name, string $previous_version, array $settings, bool $expects_removal ): void {
		unset( $fixture_name );
		$settings = array_merge( $settings, self::UNKNOWN_SETTINGS );
		update_option( self::VERSION_OPTION, $previous_version );
		update_option( self::SETTINGS_OPTION, $settings );
		foreach ( self::SPLIT_SETTINGS as $option_name => $split_settings ) {
			update_option( $option_name, $split_settings );
		}
		$retired_keys = array_keys( WooPaymentsCutoverNormalizationRunner::RETIRED_SETTINGS_KEYS );

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertTrue( $summary['ran'] );
		$this->assertIsArray( $stored );
		$removed = array_keys( array_diff_key( $settings, $stored ) );
		$this->assertSame( array(), array_values( array_diff( $removed, $retired_keys ) ), 'Normalization removed shared settings keys that are not on the retired allowlist.' );
		if ( $expects_removal ) {
			$this->assertNotEmpty( $removed, 'The legacy fixture must exercise at least one retired-key removal.' );
		} else {
			$this->assertSame( array(), $removed, 'A store at the cutover floor holds no retired key, so nothing may be removed.' );
		}
		foreach ( self::UNKNOWN_SETTINGS as $key => $value ) {
			$this->assertArrayHasKey( $key, $stored, "Unknown shared setting {$key} must survive normalization." );
			$this->assertSame( $value, $stored[ $key ], "Unknown shared setting {$key} must keep its value." );
		}
		foreach ( array_intersect_key( $settings, $stored ) as $key => $value ) {
			if ( in_array( $key, $retired_keys, true ) ) {
				continue;
			}
			$this->assertSame( $this->value_shape( $value ), $this->value_shape( $stored[ $key ] ), "Normalization reshaped the shared setting {$key}, which is not on the retired allowlist." );
		}
		foreach ( self::SPLIT_SETTINGS as $option_name => $split_settings ) {
			$stored_split = get_option( $option_name );
			$this->assertIsArray( $stored_split, "{$option_name} must stay an array." );
			foreach ( $split_settings as $key => $value ) {
				$this->assertArrayHasKey( $key, $stored_split, "Normalization removed {$key} from {$option_name}; split options have no retired keys." );
				$this->assertSame( $this->value_shape( $value ), $this->value_shape( $stored_split[ $key ] ), "Normalization reshaped {$key} in {$option_name}." );
			}
		}
	}

	/**
	 * Shared settings contract fixtures.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,bool}>
	 */
	public function shared_settings_contract_provider(): array {
		return array(
			'legacy plugin 2.5.0 shapes'  => array(
				'legacy plugin 2.5.0 shapes',
				'2.5.0',
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
					'upe_enabled_payment_method_ids'      => array( 'card', 'link', 'sepa_debit', 'amazon_pay', 'ideal', 'sofort' ),
				),
				true,
			),
			'plugin at the cutover floor' => array(
				'plugin at the cutover floor',
				WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION,
				array(
					'enabled'                           => 'yes',
					'test_mode'                         => 'yes',
					'manual_capture'                    => 'yes',
					'saved_cards'                       => 'yes',
					'platform_checkout'                 => 'yes',
					'upe_enabled_payment_method_ids'    => array( 'card', 'link', 'ideal' ),
					'express_checkout_product_methods'  => array( 'payment_request', 'link' ),
					'express_checkout_cart_methods'     => array( 'woopay' ),
					'express_checkout_checkout_methods' => array(),
					'payment_request_button_type'       => 'buy',
					'payment_request_button_size'       => 'small',
				),
				false,
			),
		);
	}

	/**
	 * @testdox Normalization deletes only allowlisted options and transients and keeps every other plugin option.
	 */
	public function test_run_deletes_only_allowlisted_options_and_transients(): void {
		$this->seed_legacy_options();
		foreach ( array_keys( WooPaymentsCutoverNormalizationRunner::RETIRED_OPTIONS ) as $option_name ) {
			update_option( $option_name, '1' );
		}
		foreach ( self::PLUGIN_READ_OPTIONS as $option_name => $value ) {
			update_option( $option_name, $value );
		}
		$transients = array_merge( WooPaymentsCutoverNormalizationRunner::APPEARANCE_TRANSIENTS, array_keys( WooPaymentsCutoverNormalizationRunner::RETIRED_TRANSIENTS ) );
		foreach ( $transients as $transient ) {
			set_transient( $transient, 'stale', HOUR_IN_SECONDS );
		}
		set_transient( 'wcpay_fraud_protection_settings', array( 'kept' => true ), HOUR_IN_SECONDS );
		$deletable = array_keys( WooPaymentsCutoverNormalizationRunner::RETIRED_OPTIONS );
		foreach ( $transients as $transient ) {
			$deletable[] = '_transient_' . $transient;
			$deletable[] = '_transient_timeout_' . $transient;
		}
		$before = $this->get_plugin_option_names();

		$this->create_runner()->run();
		$deleted = array_values( array_diff( $before, $this->get_plugin_option_names() ) );

		$this->assertNotEmpty( $deleted, 'The fixture must exercise option deletion.' );
		$this->assertSame( array(), array_values( array_diff( $deleted, $deletable ) ), 'Normalization deleted plugin options that are not on an allowlist.' );
		foreach ( self::PLUGIN_READ_OPTIONS as $option_name => $value ) {
			$this->assertSame( $value, get_option( $option_name ), "Plugin-read option {$option_name} must survive normalization unchanged." );
		}
		$this->assertSame( array( 'kept' => true ), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Refuses to remove a shared settings key that is not on the retired allowlist.
	 */
	public function test_remove_retired_settings_refuses_a_key_outside_the_allowlist(): void {
		$runner   = $this->create_runner();
		$method   = new \ReflectionMethod( $runner, 'remove_retired_settings' );
		$settings = array(
			'saved_cards'     => 'yes',
			'payment_request' => 'yes',
		);
		$method->setAccessible( true );
		$this->setExpectedIncorrectUsage( WooPaymentsCutoverNormalizationRunner::class . '::remove_retired_settings' );

		$method->invokeArgs( $runner, array( &$settings, 'saved_cards', 'payment_request' ) );

		$this->assertSame( array( 'saved_cards' => 'yes' ), $settings );
	}

	/**
	 * Describe a value's shape: its PHP type, and for arrays whether it is a list or a map.
	 *
	 * @param mixed $value Setting value.
	 * @return string
	 */
	private function value_shape( $value ): string {
		if ( ! is_array( $value ) ) {
			return gettype( $value );
		}

		return array_values( $value ) === $value ? 'list' : 'map';
	}

	/**
	 * Read every stored option name that belongs to the plugin or to native WooPayments.
	 *
	 * @return string[]
	 */
	private function get_plugin_option_names(): array {
		global $wpdb;

		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%wcpay%' OR option_name LIKE '%woocommerce_payments%' OR option_name LIKE '%woopayments%'" );
	}

	/**
	 * Create the runner under test.
	 *
	 * @param bool $native_register Whether native runtime should own registration.
	 * @return WooPaymentsCutoverNormalizationRunner
	 */
	private function create_runner( bool $native_register = true ): WooPaymentsCutoverNormalizationRunner {
		$this->assertTrue( class_exists( WooPaymentsCutoverNormalizationRunner::class ), 'WooPaymentsCutoverNormalizationRunner should exist.' );

		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $native_register );

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
