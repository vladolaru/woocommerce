<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverDataMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsDefaults;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments cutover normalization runner.
 */
class WooPaymentsCutoverDataMigrationTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const MIGRATION_VERSION_OPTION = 'woocommerce_woopayments_cutover_normalization_version';

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
	private const EXTENSION_READ_OPTIONS = array(
		'wcpay_multi_currency_enabled_currencies'         => array( 'USD', 'EUR' ),
		'wcpay_multi_currency_stored_customer_currencies' => array( 'EUR' ),
		'_wcpay_feature_woopay_express_checkout'          => '1',
	);

	/**
	 * System under test.
	 *
	 * @var WooPaymentsCutoverDataMigration|null
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
		if ( $this->runner instanceof WooPaymentsCutoverDataMigration ) {
			remove_action( 'init', array( $this->runner, 'maybe_run' ), 1 );
		}

		delete_option( self::SETTINGS_OPTION );
		delete_option( self::VERSION_OPTION );
		delete_option( self::MIGRATION_VERSION_OPTION );
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
	 * @testdox Registers the one-shot normalization hook early on init; the setup tier decides on which stores it loads.
	 */
	public function test_register_adds_the_one_shot_hook_early_on_init(): void {
		$runner = $this->create_runner();

		$runner->register();

		$this->assertSame( 1, has_action( 'init', array( $runner, 'maybe_run' ) ) );
	}

	/**
	 * @testdox Normalizes the shared gateway options of a floor-version extension store once on the first request the built-in WooPayments owns.
	 */
	public function test_run_normalizes_pre_cutover_gateway_option_shapes_once(): void {
		$this->seed_extension_options();

		$summary = $this->create_runner()->run();
		$stored  = get_option( self::SETTINGS_OPTION );

		$this->assertIsArray( $stored );
		$this->assertContains( 'manual_capture_payment_methods', $summary['changes'] );
		$this->assertSame( array( 'card', 'link', 'amazon_pay' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'woopay', 'amazon_pay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $stored['express_checkout_checkout_methods'] );
		$this->assertSame( 'small', $stored['payment_request_button_size'] );
		$this->assertSame( 'buy', $stored['payment_request_button_type'] );
		$this->assertSame( '4', wp_load_alloptions( true )[ self::MIGRATION_VERSION_OPTION ] ?? null, 'Completed cutover normalization must not leave its marker as a request-level option read.' );

		$second_summary = $this->create_runner()->run();

		$this->assertSame( array( 'already_normalized' ), $second_summary['changes'] );
	}

	/**
	 * @testdox Leaves the marker unset when a discontinued split gateway update fails and retries it.
	 */
	public function test_run_retries_discontinued_method_cleanup_after_split_gateway_write_failure(): void {
		$option_name = 'woocommerce_woocommerce_payments_sofort_settings';
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

		$this->assertSame( array( 'settings_persistence_failed' ), $failed_summary['changes'] );
		$this->assertFalse( get_option( self::MIGRATION_VERSION_OPTION, false ) );
		$this->assertSame( array( 'card' ), get_option( self::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'card' ), get_option( self::SETTINGS_OPTION )['upe_available_payment_methods'] );
		$this->assertSame( 'yes', get_option( $option_name )['enabled'] );

		$retry_summary = $this->create_runner()->run();

		$this->assertSame( array( 'split_gateway_settings' ), $retry_summary['changes'] );
		$this->assertSame( '4', get_option( self::MIGRATION_VERSION_OPTION ) );
		$this->assertSame( 'no', get_option( $option_name )['enabled'] );

		$third_summary = $this->create_runner()->run();

		$this->assertSame( array( 'already_normalized' ), $third_summary['changes'] );
	}

	/**
	 * @testdox Should add missing form-field defaults without overwriting saved settings during cutover.
	 */
	public function test_run_adds_missing_form_field_defaults_without_overwriting_saved_settings(): void {
		update_option( self::VERSION_OPTION, '10.8.0' );
		update_option( self::SETTINGS_OPTION, array( 'saved_cards' => 'no' ) );

		$this->create_runner()->run();
		$stored = get_option( self::SETTINGS_OPTION );

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
	 * @testdox A partial gateway settings write leaves cutover incomplete and preserves later cleanup state.
	 */
	public function test_run_does_not_complete_when_gateway_settings_persistence_fails(): void {
		$this->seed_extension_options();
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

		$this->assertSame( array( 'settings_persistence_failed' ), $summary['changes'] );
		$this->assertFalse( get_option( self::MIGRATION_VERSION_OPTION, false ) );
		$this->assertSame( array( 'theme' => 'stale' ), get_transient( 'wcpay_upe_appearance' ) );
	}

	/**
	 * @testdox Different Apple Pay and Google Pay choices stay as the merchant saved them.
	 *
	 * The extension's settings controller enables and disables each wallet gateway on its own (client 11.1.0
	 * includes/admin/class-wc-rest-payments-settings-controller.php:766).
	 */
	public function test_run_preserves_asymmetric_wallet_settings(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled'                        => 'yes',
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

		$this->create_runner()->run();

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
	 * @testdox Cleans stale local state that is safe to remove during the cutover.
	 */
	public function test_run_deletes_stale_transients_options_and_user_meta(): void {
		$this->seed_extension_options();
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
	 * @testdox Clears the WooPayments extension's WP-Cron events only when nothing handles them: $_dataName.
	 *
	 * @dataProvider extension_cron_residue_provider
	 *
	 * @param string[] $hooks       The extension's cron hooks.
	 * @param string   $schedule    The schedule the extension registered for them.
	 * @param string   $change_name The change normalization reports.
	 * @param bool     $handled     Whether a handler is registered for the hooks, as a Sync that still runs registers one.
	 */
	public function test_run_clears_only_the_extension_cron_events_nothing_handles( array $hooks, string $schedule, string $change_name, bool $handled ): void {
		$this->seed_extension_options();
		// The extension registered its schedule while it was active; after deactivation nothing registers it.
		$add_schedule = static function ( array $schedules ) use ( $schedule ): array {
			$schedules[ $schedule ] = $schedules[ $schedule ] ?? array(
				'interval' => 300,
				'display'  => $schedule,
			);
			return $schedules;
		};
		add_filter( 'cron_schedules', $add_schedule ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Recreates the extension's schedule for the fixture.
		foreach ( $hooks as $hook ) {
			wp_schedule_event( time(), $schedule, $hook );
			if ( $handled ) {
				add_action( $hook, '__return_null' );
			}
		}
		remove_filter( 'cron_schedules', $add_schedule );

		$summary = $this->create_runner()->run();

		$this->assertSame( ! $handled, in_array( $change_name, $summary['changes'], true ) );
		foreach ( $hooks as $hook ) {
			$scheduled = false !== wp_next_scheduled( $hook );
			$this->assertSame( $handled, $scheduled, $handled ? "The handled $hook event must be kept." : "The orphaned $hook event must be cleared." );
		}
	}

	/**
	 * The extension's cron events, the schedule they ran on, the change normalization reports for them and whether a
	 * handler is registered.
	 *
	 * @return array<string, array{0: string[], 1: string, 2: string, 3: bool}>
	 */
	public function extension_cron_residue_provider(): array {
		return array(
			// Client 11.1.0 includes/woopay/class-woopay-scheduler.php:63-68 schedules it daily; :51, :57-59 clear it.
			'WooPay compatibility check'   => array( array( 'validate_woopay_compatibility' ), 'daily', 'retired_woopay_compatibility_cron', false ),
			// Client 11.1.0 bundles jetpack-sync 4.24.1: vendor/automattic/jetpack-sync/src/class-actions.php:81 names the
			// schedule, :672 registers it (300 seconds), :1033-1050 and :1067-1085 schedule both hooks on it.
			'Jetpack Sync with no handler' => array( array( 'jetpack_sync_cron', 'jetpack_sync_full_cron' ), 'jetpack_sync_interval', 'retired_jetpack_sync_cron', false ),
			// A Sync that still runs in the request (the extension's before deactivation, Jetpack's) has added its handlers
			// (jetpack-sync class-actions.php:1067-1071), as on the cutover job's finalization run.
			'Jetpack Sync that still runs' => array( array( 'jetpack_sync_cron', 'jetpack_sync_full_cron' ), 'jetpack_sync_interval', 'retired_jetpack_sync_cron', true ),
		);
	}

	/**
	 * @testdox Cutover removes discontinued method IDs and projects canonical split gateway availability.
	 */
	public function test_run_projects_split_gateway_settings_and_removes_discontinued_methods(): void {
		update_option( self::VERSION_OPTION, WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION );
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
		$this->assertContains( 'discontinued_payment_methods', $summary['changes'] );
		$this->assertContains( 'split_gateway_settings', $summary['changes'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_giropay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_sofort_settings' )['enabled'] );
	}

	/**
	 * @testdox The manual-capture method filter changes only the method list, once; a rerun with the marker cleared changes nothing more.
	 */
	public function test_manual_capture_method_filter_is_idempotent_across_reruns(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled'                        => 'yes',
				'manual_capture'                 => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal', 'amazon_pay' ),
			)
		);
		$filtered = array(
			'enabled'                        => 'yes',
			'manual_capture'                 => 'yes',
			'upe_enabled_payment_method_ids' => array( 'card', 'amazon_pay' ),
		) + WooPaymentsSettingsDefaults::all();

		$first = $this->create_runner()->run();

		$this->assertContains( 'manual_capture_payment_methods', $first['changes'] );
		$this->assertSame( array( 'card', 'amazon_pay' ), get_option( self::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( self::SETTINGS_OPTION )['manual_capture'], 'The merchant keeps manual capture.' );
		$this->assertSame( $filtered, get_option( self::SETTINGS_OPTION ), 'The filter writes no other setting.' );

		delete_option( self::MIGRATION_VERSION_OPTION );
		$second = $this->create_runner()->run();

		$this->assertNotContains( 'manual_capture_payment_methods', $second['changes'] );
		$this->assertSame( $filtered, get_option( self::SETTINGS_OPTION ) );
	}

	/**
	 * @testdox Multi-currency cache autodetection is marked done only when the stored extension version predates 11.0.0, and a rerun does not mark it again ($_dataName).
	 * @dataProvider multi_currency_cache_autodetect_provider
	 *
	 * @param string $previous_version Stored extension version.
	 * @param bool   $marked           Whether the run marks the autodetection done.
	 */
	public function test_multi_currency_cache_autodetect_version_boundary( string $previous_version, bool $marked ): void {
		update_option( self::VERSION_OPTION, $previous_version );

		$first = $this->create_runner()->run();

		$this->assertSame( $marked, in_array( 'multi_currency_cache_autodetect', $first['changes'], true ) );
		$this->assertSame( $marked ? 'yes' : false, get_option( 'wcpay_multi_currency_cache_autodetect_done' ) );

		delete_option( self::MIGRATION_VERSION_OPTION );
		$second = $this->create_runner()->run();

		$this->assertNotContains( 'multi_currency_cache_autodetect', $second['changes'] );
		$this->assertSame( $marked ? 'yes' : false, get_option( 'wcpay_multi_currency_cache_autodetect_done' ) );
	}

	/**
	 * Stored extension versions around the 11.0.0 boundary.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function multi_currency_cache_autodetect_provider(): array {
		return array(
			'just below the boundary' => array( '10.9.9', true ),
			'at the boundary'         => array( '11.0.0', false ),
			'above the boundary'      => array( '11.0.1', false ),
		);
	}

	/**
	 * @testdox Every retired name normalization may remove was last read by a plugin version below the cutover floor.
	 */
	public function test_retired_allowlists_stay_at_or_below_the_cutover_plugin_floor(): void {
		$allowlists = array(
			'RETIRED_OPTIONS'    => WooPaymentsCutoverDataMigration::RETIRED_OPTIONS,
			'RETIRED_TRANSIENTS' => WooPaymentsCutoverDataMigration::RETIRED_TRANSIENTS,
		);
		$floors     = array(
			WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION,
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
	 * @testdox Normalization keeps every shared setting of a store at the cutover floor, with its express checkout location lists unchanged.
	 */
	public function test_run_keeps_every_shared_setting_of_a_floor_store(): void {
		// Client 11.1.0 includes/class-wc-payment-gateway-wcpay.php: all three express checkout location lists accept `link`
		// (:510-540), and `payment_request_button_locations` is still declared (:471). Link next to WooPay (`platform_checkout`
		// 'yes') is deliberately combined: the extension's settings endpoint accepts it (includes/admin/class-wc-rest-payments-settings-controller.php:322-347, :1236-1247).
		$express_locations = array(
			'express_checkout_product_methods'  => array( 'payment_request', 'link', 'amazon_pay' ),
			'express_checkout_cart_methods'     => array( 'woopay', 'link' ),
			'express_checkout_checkout_methods' => array( 'link' ),
		);
		$settings          = array_merge(
			array(
				'enabled'                          => 'yes',
				'test_mode'                        => 'yes',
				'manual_capture'                   => 'yes',
				'saved_cards'                      => 'yes',
				'platform_checkout'                => 'yes',
				'upe_enabled_payment_method_ids'   => array( 'card', 'link', 'ideal' ),
				'payment_request_button_type'      => 'buy',
				'payment_request_button_size'      => 'small',
				'payment_request_button_locations' => array( 'product', 'cart', 'checkout' ),
			),
			$express_locations,
			self::UNKNOWN_SETTINGS
		);
		update_option( self::VERSION_OPTION, WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION );
		update_option( self::SETTINGS_OPTION, $settings );
		foreach ( self::SPLIT_SETTINGS as $option_name => $split_settings ) {
			update_option( $option_name, $split_settings );
		}

		$this->create_runner()->run();
		$stored = get_option( self::SETTINGS_OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( array(), array_keys( array_diff_key( $settings, $stored ) ), 'A store at the cutover floor holds no retired key, so nothing may be removed.' );
		foreach ( $express_locations as $key => $methods ) {
			$this->assertSame( $methods, $stored[ $key ], "Normalization changed the express checkout location list {$key}; the extension accepts Link in every location." );
		}
		foreach ( self::UNKNOWN_SETTINGS as $key => $value ) {
			$this->assertArrayHasKey( $key, $stored, "Unknown shared setting {$key} must survive normalization." );
			$this->assertSame( $value, $stored[ $key ], "Unknown shared setting {$key} must keep its value." );
		}
		foreach ( array_intersect_key( $settings, $stored ) as $key => $value ) {
			$this->assertSame( $this->value_shape( $value ), $this->value_shape( $stored[ $key ] ), "Normalization reshaped the shared setting {$key}." );
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
	 * @testdox Normalization deletes only allowlisted options and transients and keeps every other WooPayments option.
	 */
	public function test_run_deletes_only_allowlisted_options_and_transients(): void {
		$this->seed_extension_options();
		foreach ( array_keys( WooPaymentsCutoverDataMigration::RETIRED_OPTIONS ) as $option_name ) {
			update_option( $option_name, '1' );
		}
		foreach ( self::EXTENSION_READ_OPTIONS as $option_name => $value ) {
			update_option( $option_name, $value );
		}
		$transients = array_merge( WooPaymentsCutoverDataMigration::APPEARANCE_TRANSIENTS, array_keys( WooPaymentsCutoverDataMigration::RETIRED_TRANSIENTS ) );
		foreach ( $transients as $transient ) {
			set_transient( $transient, 'stale', HOUR_IN_SECONDS );
		}
		set_transient( 'wcpay_fraud_protection_settings', array( 'kept' => true ), HOUR_IN_SECONDS );
		$deletable = array_keys( WooPaymentsCutoverDataMigration::RETIRED_OPTIONS );
		foreach ( $transients as $transient ) {
			$deletable[] = '_transient_' . $transient;
			$deletable[] = '_transient_timeout_' . $transient;
		}
		$before = $this->get_woopayments_option_names();

		$this->create_runner()->run();
		$deleted = array_values( array_diff( $before, $this->get_woopayments_option_names() ) );

		$this->assertNotEmpty( $deleted, 'The fixture must exercise option deletion.' );
		$this->assertSame( array(), array_values( array_diff( $deleted, $deletable ) ), 'Normalization deleted plugin options that are not on an allowlist.' );
		foreach ( self::EXTENSION_READ_OPTIONS as $option_name => $value ) {
			$this->assertSame( $value, get_option( $option_name ), "Plugin-read option {$option_name} must survive normalization unchanged." );
		}
		$this->assertSame( array( 'kept' => true ), get_transient( 'wcpay_fraud_protection_settings' ) );
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
	private function get_woopayments_option_names(): array {
		global $wpdb;

		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%wcpay%' OR option_name LIKE '%woocommerce_payments%' OR option_name LIKE '%woopayments%'" );
	}

	/**
	 * Create the runner under test.
	 *
	 * @return WooPaymentsCutoverDataMigration
	 */
	private function create_runner(): WooPaymentsCutoverDataMigration {
		$this->assertTrue( class_exists( WooPaymentsCutoverDataMigration::class ), 'WooPaymentsCutoverDataMigration should exist.' );

		$this->runner = new WooPaymentsCutoverDataMigration();
		$this->runner->init( wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class ) );

		return $this->runner;
	}

	/**
	 * Seed the shared options of an extension store at the cutover floor.
	 *
	 * Shapes follow client 11.1.0 includes/class-wc-payment-gateway-wcpay.php: `upe_enabled_payment_method_ids` (:491-496),
	 * `payment_request_button_type` (:432-443), `payment_request_button_size` (:497-507) and the location-centric express
	 * checkout lists (:510-540) that includes/migrations/class-migrate-express-checkout-locations.php:73-97 and
	 * class-add-amazon-pay-to-express-checkout-locations.php:65-80 write. Deliberately adversarial: `manual_capture` 'yes' with
	 * `sepa_debit` and `ideal` enabled (the extension's own manual-capture migration, class-manual-capture-payment-method-settings-update.php:68-87,
	 * removes such methods), and Link next to WooPay (`platform_checkout` 'yes'), which the extension's settings endpoint accepts.
	 */
	private function seed_extension_options(): void {
		update_option( self::VERSION_OPTION, WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION );
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled'                           => 'yes',
				'payment_request_button_size'       => 'small',
				'payment_request_button_type'       => 'buy',
				'manual_capture'                    => 'yes',
				'platform_checkout'                 => 'yes',
				'upe_enabled_payment_method_ids'    => array(
					'card',
					'link',
					'sepa_debit',
					'amazon_pay',
					'ideal',
				),
				'express_checkout_product_methods'  => array( 'payment_request', 'amazon_pay' ),
				'express_checkout_cart_methods'     => array( 'woopay', 'amazon_pay' ),
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
			)
		);
	}
}
