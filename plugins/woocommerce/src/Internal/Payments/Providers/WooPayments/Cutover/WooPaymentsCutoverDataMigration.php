<?php
/**
 * WooPaymentsCutoverDataMigration class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsDefaults;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates the WooPayments extension's stored settings and options once per migration version: limits manual-capture payment
 * methods, adds missing gateway setting defaults, persists the gateway settings, deletes retired options and transients, and
 * clears retired cron events.
 *
 * Each retired option and transient it removes carries the extension version that stopped reading it, so re-activating the
 * extension at the floor version still finds what it reads. It also clears the UPE appearance caches in APPEARANCE_TRANSIENTS,
 * which the floor version rebuilds itself (10.5.x reads them as day-long caches; the extension deletes them in its 10.6.0
 * migration).
 *
 * In the shared gateway settings option it removes no key and changes no value's type. It adds missing form defaults, and
 * drops from the enabled payment method list the methods that cannot be captured manually while manual capture is on, which
 * the extension has filtered since 8.3.0, and the discontinued giropay and sofort, which the platform no longer processes and
 * which the extension removes itself from 11.0.0 and 11.1.0; a rollback to the floor version reads a list without two
 * methods it could not charge.
 *
 * It also runs once on every store where the built-in WooPayments is active, which is how a store that never had the
 * extension gets its missing form defaults stored.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsCutoverDataMigration implements RegisterHooksInterface {

	/**
	 * Option holding the completed migration version. Its stored name keeps "normalization", so migrated stores do not migrate again.
	 */
	private const MIGRATION_VERSION_OPTION = 'woocommerce_woopayments_cutover_normalization_version';

	/**
	 * Migration version stored in MIGRATION_VERSION_OPTION.
	 */
	private const MIGRATION_VERSION = '4';

	/**
	 * Options the migration deletes, with the extension version that stopped reading each.
	 *
	 * Re-activating the extension is the rollback lever, so the cutover deletes only options that the cutover floor
	 * (WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION) no longer reads; raising the floor is the only way
	 * to widen this list. Each version is the first extension release tag with no read outside its own migrations.
	 *
	 * The extension's own Erase_Deprecated_Flags_And_Options migration deletes the same list for installs older than 9.8.0.
	 *
	 * @var array<string,string>
	 */
	public const RETIRED_OPTIONS = array(
		'_wcpay_feature_auth_and_capture'              => '9.8.0',
		'_wcpay_feature_progressive_onboarding'        => '6.9.0',
		'_wcpay_feature_client_secret_encryption'      => '7.7.0',
		'_wcpay_feature_allow_subscription_migrations' => '8.1.0',
		'_wcpay_feature_custom_deposit_schedules'      => '5.8.0',
		'_wcpay_feature_account_overview_task_list'    => '6.2.0',
		'_wcpay_feature_account_overview'              => '2.5.0',
		'_wcpay_feature_sepa'                          => '3.4.0',
		'_wcpay_feature_sofort'                        => '3.4.0',
		'_wcpay_feature_giropay'                       => '3.4.0',
		'_wcpay_feature_grouped_settings'              => '2.9.0',
		'_wcpay_feature_upe_settings_preview'          => '7.0.0',
		'_wcpay_feature_upe'                           => '7.0.0',
		'_wcpay_feature_upe_split'                     => '7.0.0',
		'_wcpay_feature_upe_deferred_intent'           => '7.0.0',
		'_wcpay_feature_dispute_on_transaction_page'   => '6.7.0',
		'_wcpay_feature_streamline_refunds'            => '8.1.0',
		'wcpay_fraud_protection_settings_active'       => '5.9.0',
		'_wcpay_feature_mc_order_meta_helper'          => '6.4.0',
		'_wcpay_feature_pay_for_order_flow'            => '7.6.0',
		'_wcpay_feature_simplify_deposits_ui'          => '5.9.0',
		// No release ever read it; the extension's erase migration has deleted it since 8.1.0.
		'_wcpay_fraud_protection_settings_enabled'     => '8.1.0',
		'_wcpay_feature_platform_checkout_subscriptions_enabled' => '4.8.0',
		// 4.0.3 is the first tagged 4.0 release.
		'_wcpay_feature_platform_checkout'             => '4.0.3',
		'_wcpay_feature_capital'                       => '3.8.0',
		'wcpay_capability_request_dismissed_notices'   => '9.3.0',
		// Client 11.1.0 still lists it in its generic settings-option REST route (class-wc-rest-payments-settings-option-controller.php:27), but no client code reads it since 9.8.0.
		'wcpay_onboarding_eligibility_modal_dismissed' => '9.8.0',
	);

	/**
	 * Transients the migration deletes, with the extension version that stopped reading each (same floor rule).
	 *
	 * The BNPL announcement user meta `_wcpay_bnpl_april15_viewed` retired with it.
	 *
	 * @var array<string,string>
	 */
	public const RETIRED_TRANSIENTS = array(
		'wcpay_bnpl_april15_successful_purchases_count' => '8.1.0',
	);

	/**
	 * UPE appearance caches the migration deletes.
	 *
	 * Extension 10.5.x still reads them, but only as day-long caches it rebuilds and clears itself (10.5.0
	 * class-wc-payment-gateway-wcpay.php:4281-4282, :4303-4312), so deleting them loses no merchant setting.
	 *
	 * @var string[]
	 */
	public const APPEARANCE_TRANSIENTS = array(
		'wcpay_upe_appearance',
		'wcpay_upe_add_payment_method_appearance',
		'wcpay_wc_blocks_upe_appearance',
		'wcpay_upe_bnpl_product_page_appearance',
		'wcpay_upe_bnpl_classic_cart_appearance',
		'wcpay_upe_bnpl_cart_block_appearance',
		'wcpay_upe_appearance_theme',
		'wcpay_upe_add_payment_method_appearance_theme',
		'wcpay_wc_blocks_upe_appearance_theme',
		'wcpay_upe_bnpl_product_page_appearance_theme',
		'wcpay_upe_bnpl_classic_cart_appearance_theme',
		'wcpay_upe_bnpl_cart_block_appearance_theme',
	);

	/**
	 * Canonical-to-split gateway settings synchronizer.
	 *
	 * @var WooPaymentsGatewaySettingsSynchronizer
	 */
	private WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer Gateway settings synchronizer.
	 */
	final public function init( WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer ): void {
		$this->gateway_settings_synchronizer = $gateway_settings_synchronizer;
	}

	/**
	 * Register the one-shot data migration hook.
	 *
	 * The setup tier lists this class only on the active tier, which exists only while the built-in WooPayments owns the store.
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_run' ), 1 );
	}

	/**
	 * Run the data migration as an `init` callback, which must return nothing; `run()` returns the list of changes.
	 */
	public function maybe_run(): void {
		$this->run();
	}

	/**
	 * Run the data migration.
	 *
	 * @return array{changes:string[]}
	 */
	public function run(): array {
		if ( self::MIGRATION_VERSION === (string) get_option( self::MIGRATION_VERSION_OPTION, '' ) ) {
			return array( 'changes' => array( 'already_normalized' ) );
		}

		$changes          = array();
		$settings         = $this->get_gateway_settings();
		$previous_version = $this->get_previous_version();

		if ( $this->normalize_manual_capture_payment_methods( $settings ) ) {
			$changes[] = 'manual_capture_payment_methods';
		}

		if ( $this->add_missing_form_field_defaults( $settings ) ) {
			$changes[] = 'form_field_defaults';
		}

		$persisted = $this->gateway_settings_synchronizer->persist( $settings );
		if ( ! $persisted['persisted'] ) {
			$this->log_persistence_failure( $persisted['failed_option_names'] );

			return array( 'changes' => array( 'settings_persistence_failed' ) );
		}
		if ( ! empty( $persisted['removed_discontinued_method_ids'] ) ) {
			$changes[] = 'discontinued_payment_methods';
		}
		if ( ! empty( $persisted['updated_split_options'] ) ) {
			$changes[] = 'split_gateway_settings';
		}

		if ( $this->delete_appearance_transients() ) {
			$changes[] = 'appearance_transients';
		}

		if ( $this->delete_deprecated_options() ) {
			$changes[] = 'deprecated_flags_and_options';
		}

		if ( $this->delete_bnpl_announcement_state() ) {
			$changes[] = 'bnpl_announcement_state';
		}

		if ( $this->mark_multi_currency_cache_autodetect_done( $previous_version ) ) {
			$changes[] = 'multi_currency_cache_autodetect';
		}

		if ( $this->clear_retired_woopay_compatibility_cron() ) {
			$changes[] = 'retired_woopay_compatibility_cron';
		}

		if ( $this->clear_retired_jetpack_sync_cron() ) {
			$changes[] = 'retired_jetpack_sync_cron';
		}

		update_option( self::MIGRATION_VERSION_OPTION, self::MIGRATION_VERSION, true );

		if ( empty( $changes ) ) {
			$changes[] = 'no_changes';
		}

		$this->log_summary( $changes );

		return array( 'changes' => $changes );
	}

	/**
	 * Clear the extension's daily WP-Cron WooPay compatibility event.
	 *
	 * Client 11.1.0 woopay/class-woopay-scheduler.php:51, :57-59 clears it only from its own deactivation hook; the built-in
	 * WooPayments runs the same check through Action Scheduler.
	 *
	 * @return bool Whether an event was cleared.
	 */
	private function clear_retired_woopay_compatibility_cron(): bool {
		if ( false === wp_next_scheduled( 'validate_woopay_compatibility' ) ) {
			return false;
		}

		wp_clear_scheduled_hook( 'validate_woopay_compatibility' );

		return true;
	}

	/**
	 * Clear the WooPayments extension's two Jetpack Sync WP-Cron events when nothing in this request handles them.
	 *
	 * The extension's deactivation hook leaves them (client 11.1.0 woocommerce-payments.php:56-63). An event with a
	 * registered handler belongs to a Sync that still runs (the extension's before its deactivation, Jetpack's, or
	 * WooCommerce's own), so it is kept. WordPress drops an orphaned one on its first due run with a log line; this saves that.
	 *
	 * @return bool Whether an event was cleared.
	 */
	private function clear_retired_jetpack_sync_cron(): bool {
		$cleared = false;
		foreach ( array( 'jetpack_sync_cron', 'jetpack_sync_full_cron' ) as $hook ) {
			if ( false !== wp_next_scheduled( $hook ) && ! has_action( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
				$cleared = true;
			}
		}

		return $cleared;
	}

	/**
	 * Get the last WooPayments extension version that wrote the shared version option.
	 *
	 * @return string
	 */
	private function get_previous_version(): string {
		$version = get_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION, '' );

		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * Get WooPayments gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_gateway_settings(): array {
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Add missing WooPayments form-field defaults without overwriting saved values.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool Whether a missing setting was added.
	 */
	private function add_missing_form_field_defaults( array &$settings ): bool {
		$updated = false;
		foreach ( WooPaymentsSettingsDefaults::all() as $key => $value ) {
			if ( array_key_exists( $key, $settings ) ) {
				continue;
			}

			$settings[ $key ] = $value;
			$updated          = true;
		}

		return $updated;
	}

	/**
	 * Keep only the payment methods that are safe with manual capture when manual capture is enabled.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool
	 */
	private function normalize_manual_capture_payment_methods( array &$settings ): bool {
		if ( 'yes' !== (string) ( $settings['manual_capture'] ?? 'no' ) || ! is_array( $settings['upe_enabled_payment_method_ids'] ?? null ) ) {
			return false;
		}

		$previous = $this->normalize_string_list( $settings['upe_enabled_payment_method_ids'] );
		$filtered = array_values(
			array_filter(
				$previous,
				static fn( string $payment_method_id ): bool => in_array( $payment_method_id, WooPaymentsSettingsService::MANUAL_CAPTURE_PAYMENT_METHOD_IDS, true )
			)
		);

		if ( $filtered === $previous ) {
			return false;
		}

		$settings['upe_enabled_payment_method_ids'] = $filtered;

		return true;
	}

	/**
	 * Delete stale UPE appearance transients.
	 *
	 * @return bool
	 */
	private function delete_appearance_transients(): bool {
		$deleted = false;

		foreach ( self::APPEARANCE_TRANSIENTS as $transient ) {
			$deleted = delete_transient( $transient ) || $deleted;
		}

		return $deleted;
	}

	/**
	 * Delete deprecated WooPayments feature flags and options.
	 *
	 * @return bool
	 */
	private function delete_deprecated_options(): bool {
		$deleted = false;

		foreach ( array_keys( self::RETIRED_OPTIONS ) as $option ) {
			$deleted = delete_option( $option ) || $deleted;
		}

		return $deleted;
	}

	/**
	 * Delete stale April 2024 BNPL announcement state.
	 *
	 * @return bool
	 */
	private function delete_bnpl_announcement_state(): bool {
		global $wpdb;

		$deleted_transient = false;
		foreach ( array_keys( self::RETIRED_TRANSIENTS ) as $transient ) {
			$deleted_transient = delete_transient( $transient ) || $deleted_transient;
		}
		$deleted_meta = $wpdb->delete(
			$wpdb->usermeta,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional cleanup of a known retired WooPayments user meta key.
				'meta_key' => '_wcpay_bnpl_april15_viewed',
			),
			array(
				'%s',
			)
		);

		return $deleted_transient || ( false !== $deleted_meta && $deleted_meta > 0 );
	}

	/**
	 * Preserve existing install multi-currency cache rendering behavior.
	 *
	 * @param string $previous_version Previous WooPayments extension version.
	 * @return bool
	 */
	private function mark_multi_currency_cache_autodetect_done( string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '11.0.0' ) ) {
			return false;
		}

		return update_option( MultiCurrencyCacheRenderingService::AUTODETECT_DONE_OPTION, 'yes' );
	}

	/**
	 * Tell whether the stored extension version predates a migration version.
	 *
	 * @param string $previous_version Stored WooPayments extension version.
	 * @param string $migration_version Migration version.
	 * @return bool
	 */
	private function is_upgrade_from_before( string $previous_version, string $migration_version ): bool {
		return '' !== $previous_version && version_compare( $migration_version, $previous_version, '>' );
	}

	/**
	 * Normalize scalar string arrays.
	 *
	 * @param mixed $values Raw values.
	 * @return string[]
	 */
	private function normalize_string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $value ): string => is_scalar( $value ) ? (string) $value : '',
						$values
					),
					static fn( string $value ): bool => '' !== $value
				)
			)
		);
	}

	/**
	 * Log a concise data migration summary.
	 *
	 * @param string[] $changes Change IDs.
	 */
	private function log_summary( array $changes ): void {
		wc_get_logger()->info(
			'WooPayments cutover data migration completed: ' . implode( ', ', $changes ),
			array( 'source' => 'woopayments-cutover' )
		);
	}

	/**
	 * Log failed settings writes without marking cutover complete.
	 *
	 * @param string[] $failed_option_names Failed option names.
	 */
	private function log_persistence_failure( array $failed_option_names ): void {
		wc_get_logger()->error(
			'WooPayments cutover data migration could not persist settings: ' . implode( ', ', $failed_option_names ),
			array( 'source' => 'woopayments-cutover' )
		);
	}
}
