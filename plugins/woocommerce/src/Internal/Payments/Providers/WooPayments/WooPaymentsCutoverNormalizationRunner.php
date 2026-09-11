<?php
/**
 * WooPaymentsCutoverNormalizationRunner class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot native WooPayments option normalization for plugin-to-core cutover.
 *
 * Extension migration audit:
 * - class-add-amazon-pay-to-express-checkout-locations.php: PORT. Adds `amazon_pay`
 *   to existing location-centric express-checkout settings for pre-10.5 installs.
 * - class-additional-payment-methods-admin-notes-removal.php: OBSOLETE. Extension
 *   admin-note cleanup is not a native cutover settings dependency.
 * - class-allowed-payment-request-button-sizes-update.php: PORT. Normalizes old
 *   `default` button size to `small` for pre-6.9 installs.
 * - class-allowed-payment-request-button-types-update.php: PORT. Maps deprecated
 *   `branded`/`custom` button types to current values.
 * - class-delete-active-woopay-webhook.php: OBSOLETE. Native preserves the
 *   `order.status_changed` topic so existing WooPay webhook rows remain valid.
 * - class-delete-appearance-transients.php: PORT. Deletes stale UPE appearance
 *   transients that are safe local cache state.
 * - class-erase-bnpl-announcement-meta.php: PORT. Deletes stale April 2024 BNPL
 *   announcement transient and user meta.
 * - class-erase-deprecated-flags-and-options.php: PORT. Deletes stale feature flags.
 * - class-gateway-settings-sync.php: PORT. Canonical settings are projected to
 *   every existing or enabled split gateway through the shared synchronizer.
 * - class-link-woopay-mutual-exclusion-handler.php: PORT. Removes Link when WooPay
 *   is enabled, including native express-checkout location arrays.
 * - class-manual-capture-payment-method-settings-update.php: PORT WITH NATIVE
 *   ADJUSTMENT. Uses native's current manual-capture-safe method list.
 * - class-migrate-express-checkout-locations.php: PORT. Converts old method-centric
 *   express-checkout location arrays to current location-centric arrays.
 * - class-migrate-payment-request-to-express-checkout-enabled.php: PORT. Writes
 *   split Apple Pay / Google Pay settings and removes the obsolete card switch.
 * - class-multi-currency-cache-autodetect-existing-install.php: PORT. Marks existing
 *   installs as already auto-detected so cutover does not change rendering mode.
 * - class-payment-method-deprecation-settings-update.php: PORT. Deprecated
 *   giropay/sofort IDs are removed and any existing split gateways are disabled.
 * - class-update-service-data-from-server.php: OBSOLETE. Live account refreshes
 *   belong to native account services, not first-request option normalization.
 * - class-wc-payments-remediate-canceled-auth-fees.php: OBSOLETE. Already ported as
 *   WooPaymentsCanceledAuthorizationFeeRemediationService.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverNormalizationRunner implements RegisterHooksInterface {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const NORMALIZED_OPTION = 'woocommerce_native_woopayments_cutover_normalization_version';

	private const NORMALIZATION_VERSION = '3';

	private const LOCATIONS = array( 'product', 'cart', 'checkout' );

	private const MANUAL_CAPTURE_PAYMENT_METHOD_IDS = array(
		'amazon_pay',
		'apple_pay',
		'card',
		'google_pay',
		'link',
	);

	private const APPEARANCE_TRANSIENTS = array(
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

	private const DEPRECATED_OPTIONS = array(
		'_wcpay_feature_auth_and_capture',
		'_wcpay_feature_progressive_onboarding',
		'_wcpay_feature_client_secret_encryption',
		'_wcpay_feature_allow_subscription_migrations',
		'_wcpay_feature_custom_deposit_schedules',
		'_wcpay_feature_account_overview_task_list',
		'_wcpay_feature_account_overview',
		'_wcpay_feature_sepa',
		'_wcpay_feature_sofort',
		'_wcpay_feature_giropay',
		'_wcpay_feature_grouped_settings',
		'_wcpay_feature_upe_settings_preview',
		'_wcpay_feature_upe',
		'_wcpay_feature_upe_split',
		'_wcpay_feature_upe_deferred_intent',
		'_wcpay_feature_dispute_on_transaction_page',
		'_wcpay_feature_streamline_refunds',
		'wcpay_fraud_protection_settings_active',
		'_wcpay_feature_mc_order_meta_helper',
		'_wcpay_feature_pay_for_order_flow',
		'_wcpay_feature_simplify_deposits_ui',
		'_wcpay_fraud_protection_settings_enabled',
		'_wcpay_feature_platform_checkout_subscriptions_enabled',
		'_wcpay_feature_platform_checkout',
		'_wcpay_feature_capital',
		'wcpay_capability_request_dismissed_notices',
		'wcpay_onboarding_eligibility_modal_dismissed',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Canonical-to-split gateway settings synchronizer.
	 *
	 * @var WooPaymentsGatewaySettingsSynchronizer|null
	 */
	private ?WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter                $arbiter Runtime owner arbiter.
	 * @param WooPaymentsGatewaySettingsSynchronizer|null $gateway_settings_synchronizer Optional gateway settings synchronizer.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, ?WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer = null ): void {
		$this->arbiter                       = $arbiter;
		$this->gateway_settings_synchronizer = $gateway_settings_synchronizer;
	}

	/**
	 * Register the one-shot normalization hook.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'init', array( $this, 'maybe_run' ), 1 );
	}

	/**
	 * Run normalization when it has not yet completed.
	 */
	public function maybe_run(): void {
		$this->run();
	}

	/**
	 * Run cutover normalization.
	 *
	 * @return array{ran:bool,changes:string[]}
	 */
	public function run(): array {
		if ( self::NORMALIZATION_VERSION === (string) get_option( self::NORMALIZED_OPTION, '' ) ) {
			$alloptions = wp_load_alloptions();
			if ( ! array_key_exists( self::NORMALIZED_OPTION, $alloptions ) ) {
				wp_set_option_autoload( self::NORMALIZED_OPTION, true );
			}

			return array(
				'ran'     => false,
				'changes' => array( 'already_normalized' ),
			);
		}

		$changes          = array();
		$previous_version = $this->get_previous_version();
		$settings         = $this->get_gateway_settings();

		if ( $this->should_migrate_payment_request_split_settings( $settings, $previous_version ) ) {
			$changes[] = 'payment_request_split_settings';
		}

		if ( $this->migrate_express_checkout_locations( $settings, $previous_version ) ) {
			$changes[] = 'express_checkout_locations';
		}

		if ( $this->add_amazon_pay_to_express_checkout_locations( $settings, $previous_version ) ) {
			$changes[] = 'amazon_pay_express_checkout_locations';
		}

		if ( $this->normalize_payment_request_button_size( $settings, $previous_version ) ) {
			$changes[] = 'payment_request_button_size';
		}

		if ( $this->normalize_payment_request_button_type( $settings, $previous_version ) ) {
			$changes[] = 'payment_request_button_type';
		}

		if ( $this->normalize_manual_capture_payment_methods( $settings ) ) {
			$changes[] = 'manual_capture_payment_methods';
		}

		if ( $this->normalize_link_woopay_mutual_exclusion( $settings ) ) {
			$changes[] = 'link_woopay_mutual_exclusion';
		}

		if ( $this->add_missing_form_field_defaults( $settings ) ) {
			$changes[] = 'form_field_defaults';
		}

		$projection = $this->get_gateway_settings_synchronizer()->persist( $settings );
		if ( ! $projection['persisted'] ) {
			$this->log_persistence_failure( $projection['failed_option_names'] );

			return array(
				'ran'     => false,
				'changes' => array( 'settings_persistence_failed' ),
			);
		}
		$settings = $projection['settings'];
		if ( ! empty( $projection['removed_deprecated_method_ids'] ) ) {
			$changes[] = 'deprecated_payment_methods';
		}
		if ( ! empty( $projection['updated_split_options'] ) ) {
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

		update_option( self::NORMALIZED_OPTION, self::NORMALIZATION_VERSION, true );

		if ( empty( $changes ) ) {
			$changes[] = 'no_changes';
		}

		$this->log_summary( $changes );

		return array(
			'ran'     => true,
			'changes' => $changes,
		);
	}

	/**
	 * Get the last WooPayments plugin version that wrote the shared version option.
	 *
	 * @return string
	 */
	private function get_previous_version(): string {
		$version = get_option( self::VERSION_OPTION, '' );

		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * Get WooPayments gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_gateway_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

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
	 * Tell whether the shared settings synchronizer should consume the legacy payment-request setting.
	 *
	 * @param array<string,mixed> $settings         Gateway settings.
	 * @param string              $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function should_migrate_payment_request_split_settings( array $settings, string $previous_version ): bool {
		return $this->is_upgrade_from_before( $previous_version, '10.4.0' ) && array_key_exists( 'payment_request', $settings );
	}

	/**
	 * Get the canonical-to-split gateway settings synchronizer.
	 *
	 * @return WooPaymentsGatewaySettingsSynchronizer
	 */
	private function get_gateway_settings_synchronizer(): WooPaymentsGatewaySettingsSynchronizer {
		if ( null === $this->gateway_settings_synchronizer ) {
			$this->gateway_settings_synchronizer = wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class );
		}

		return $this->gateway_settings_synchronizer;
	}

	/**
	 * Convert method-centric express-checkout location settings into location-centric settings.
	 *
	 * @param array<string,mixed> $settings         Gateway settings.
	 * @param string              $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function migrate_express_checkout_locations( array &$settings, string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '10.4.0' ) ) {
			return false;
		}

		$has_old_settings = isset( $settings['payment_request_button_locations'] )
			|| isset( $settings['platform_checkout_button_locations'] );
		if ( ! $has_old_settings || isset( $settings['express_checkout_product_methods'] ) ) {
			return false;
		}

		$payment_request_locations = $this->normalize_location_list( $settings['payment_request_button_locations'] ?? self::LOCATIONS );
		$woopay_locations          = $this->normalize_location_list( $settings['platform_checkout_button_locations'] ?? self::LOCATIONS );

		foreach ( self::LOCATIONS as $location ) {
			$methods = array();

			if ( in_array( $location, $payment_request_locations, true ) ) {
				$methods[] = 'payment_request';
			}

			if ( in_array( $location, $woopay_locations, true ) ) {
				$methods[] = 'woopay';
			}

			$settings[ "express_checkout_{$location}_methods" ] = $methods;
		}

		unset( $settings['payment_request_button_locations'], $settings['platform_checkout_button_locations'] );

		return true;
	}

	/**
	 * Add Amazon Pay to current express-checkout location settings for older installs.
	 *
	 * @param array<string,mixed> $settings         Gateway settings.
	 * @param string              $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function add_amazon_pay_to_express_checkout_locations( array &$settings, string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '10.5.0' ) || ! isset( $settings['express_checkout_product_methods'] ) ) {
			return false;
		}

		$updated = false;
		foreach ( self::LOCATIONS as $location ) {
			$key     = "express_checkout_{$location}_methods";
			$methods = $this->normalize_string_list( $settings[ $key ] ?? array() );

			if ( ! in_array( 'amazon_pay', $methods, true ) ) {
				$methods[] = 'amazon_pay';
				$updated   = true;
			}

			$settings[ $key ] = $methods;
		}

		return $updated;
	}

	/**
	 * Normalize payment request button size.
	 *
	 * @param array<string,mixed> $settings         Gateway settings.
	 * @param string              $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function normalize_payment_request_button_size( array &$settings, string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '6.9.0' ) || 'default' !== ( $settings['payment_request_button_size'] ?? null ) ) {
			return false;
		}

		$settings['payment_request_button_size'] = 'small';

		return true;
	}

	/**
	 * Normalize payment request button type and remove stale branded-type helper state.
	 *
	 * @param array<string,mixed> $settings         Gateway settings.
	 * @param string              $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function normalize_payment_request_button_type( array &$settings, string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '2.6.0' ) ) {
			return false;
		}

		$previous_type = $settings['payment_request_button_type'] ?? null;
		$mapped_type   = $this->map_payment_request_button_type(
			$previous_type,
			$settings['payment_request_button_branded_type'] ?? null
		);
		$changed       = $mapped_type !== $previous_type || array_key_exists( 'payment_request_button_branded_type', $settings );

		if ( null !== $mapped_type ) {
			$settings['payment_request_button_type'] = $mapped_type;
		}
		unset( $settings['payment_request_button_branded_type'] );

		return $changed;
	}

	/**
	 * Keep only native manual-capture-safe payment methods when manual capture is enabled.
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
				static fn( string $payment_method_id ): bool => in_array( $payment_method_id, self::MANUAL_CAPTURE_PAYMENT_METHOD_IDS, true )
			)
		);

		if ( $filtered === $previous ) {
			return false;
		}

		$settings['upe_enabled_payment_method_ids'] = $filtered;

		return true;
	}

	/**
	 * Remove Link when WooPay is enabled.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool
	 */
	private function normalize_link_woopay_mutual_exclusion( array &$settings ): bool {
		if ( 'yes' !== (string) ( $settings['platform_checkout'] ?? 'no' ) ) {
			return false;
		}

		$updated = false;
		if ( is_array( $settings['upe_enabled_payment_method_ids'] ?? null ) ) {
			$updated = $this->remove_method_from_setting( $settings, 'upe_enabled_payment_method_ids', 'link' );
		}

		foreach ( self::LOCATIONS as $location ) {
			$key = "express_checkout_{$location}_methods";
			if ( is_array( $settings[ $key ] ?? null ) ) {
				$updated = $this->remove_method_from_setting( $settings, $key, 'link' ) || $updated;
			}
		}

		return $updated;
	}

	/**
	 * Remove a method ID from a settings array.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @param string              $key      Settings key.
	 * @param string              $method   Method ID to remove.
	 * @return bool
	 */
	private function remove_method_from_setting( array &$settings, string $key, string $method ): bool {
		$previous = $this->normalize_string_list( $settings[ $key ] ?? array() );
		$filtered = array_values(
			array_filter(
				$previous,
				static fn( string $payment_method_id ): bool => $method !== $payment_method_id
			)
		);

		if ( $filtered === $previous ) {
			return false;
		}

		$settings[ $key ] = $filtered;

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

		foreach ( self::DEPRECATED_OPTIONS as $option ) {
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

		$deleted_transient = delete_transient( 'wcpay_bnpl_april15_successful_purchases_count' );
			$deleted_meta  = $wpdb->delete(
				$wpdb->usermeta,
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional cleanup of a known legacy WooPayments user meta key.
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
	 * @param string $previous_version Previous WooPayments plugin version.
	 * @return bool
	 */
	private function mark_multi_currency_cache_autodetect_done( string $previous_version ): bool {
		if ( ! $this->is_upgrade_from_before( $previous_version, '11.0.0' ) ) {
			return false;
		}

		return update_option( 'wcpay_multi_currency_cache_autodetect_done', 'yes' );
	}

	/**
	 * Tell whether the stored plugin version predates a migration version.
	 *
	 * @param string $previous_version Stored WooPayments plugin version.
	 * @param string $migration_version Migration version.
	 * @return bool
	 */
	private function is_upgrade_from_before( string $previous_version, string $migration_version ): bool {
		return '' !== $previous_version && version_compare( $migration_version, $previous_version, '>' );
	}

	/**
	 * Normalize express checkout location values.
	 *
	 * @param mixed $locations Raw location list.
	 * @return string[]
	 */
	private function normalize_location_list( $locations ): array {
		$locations = is_array( $locations ) ? $locations : array();

		return array_values(
			array_intersect(
				self::LOCATIONS,
				$this->normalize_string_list( $locations )
			)
		);
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
	 * Map deprecated payment request button types.
	 *
	 * @param mixed $button_type  Existing button type.
	 * @param mixed $branded_type Existing branded helper type.
	 * @return string|null
	 */
	private function map_payment_request_button_type( $button_type, $branded_type ): ?string {
		if ( ! is_scalar( $button_type ) ) {
			return null;
		}

		$button_type = (string) $button_type;

		if ( 'branded' === $button_type && 'short' === $branded_type ) {
			return 'default';
		}

		if ( 'branded' === $button_type || 'custom' === $button_type ) {
			return 'buy';
		}

		return $button_type;
	}

	/**
	 * Log a concise normalization summary.
	 *
	 * @param string[] $changes Change IDs.
	 */
	private function log_summary( array $changes ): void {
		wc_get_logger()->info(
			'Native WooPayments cutover normalization completed: ' . implode( ', ', $changes ),
			array( 'source' => 'woocommerce-native-payments' )
		);
	}

	/**
	 * Log failed settings writes without marking cutover complete.
	 *
	 * @param string[] $failed_option_names Failed option names.
	 */
	private function log_persistence_failure( array $failed_option_names ): void {
		wc_get_logger()->error(
			'Native WooPayments cutover normalization could not persist settings: ' . implode( ', ', $failed_option_names ),
			array( 'source' => 'woocommerce-native-payments' )
		);
	}
}
