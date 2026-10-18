<?php
/**
 * MultiCurrencyFeatureController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;

/**
 * Defines the native Multi-Currency feature and its safe disable presentation.
 *
 * @since 11.2.0
 * @internal
 */
class MultiCurrencyFeatureController {

	/** Multi-Currency feature identifier. */
	public const FEATURE_ID = 'multi_currency';

	/** Option storing whether the Multi-Currency feature is enabled. */
	public const FEATURE_ENABLE_OPTION = 'woocommerce_feature_multi_currency_enabled';

	/**
	 * Per-blog option recording the payments owner this site last ran under ('extension' or 'builtin').
	 */
	public const LAST_PAYMENTS_OWNER_OPTION = 'woocommerce_multi_currency_last_payments_owner';

	/**
	 * Stored marker value: the site last ran with the WooPayments extension owning payments.
	 */
	public const LAST_PAYMENTS_OWNER_EXTENSION = 'extension';

	/**
	 * Stored marker value: the site last ran with the built-in WooPayments owning payments.
	 */
	public const LAST_PAYMENTS_OWNER_BUILTIN = 'builtin';

	/**
	 * The WooPayments extension's own Multi-Currency flag, the only switch the extension reads.
	 */
	private const EXTENSION_MULTI_CURRENCY_FLAG_OPTION = '_wcpay_feature_customer_multi_currency';

	/**
	 * Persisted Multi-Currency usage detector.
	 *
	 * @var MultiCurrencyUsageDetector
	 */
	private MultiCurrencyUsageDetector $usage_detector;

	/**
	 * Multi-Currency runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $runtime_arbiter;

	/**
	 * Initialize the feature controller.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyUsageDetector  $usage_detector  Persisted usage detector.
	 * @param MultiCurrencyRuntimeArbiter $runtime_arbiter Multi-Currency runtime owner arbiter.
	 */
	final public function init( MultiCurrencyUsageDetector $usage_detector, MultiCurrencyRuntimeArbiter $runtime_arbiter ): void {
		$this->usage_detector  = $usage_detector;
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Turn the feature on for a store that used the WooPayments plugin's Multi-Currency, unless a choice is already stored.
	 *
	 * Runs at upgrade. Without prior use the option stays unset, so the feature stays off; the WooPayments cutover later
	 * hands the plugin's state over with hand_over_plugin_state().
	 *
	 * @since 11.2.0
	 */
	public static function seed_from_prior_use(): void {
		if ( self::is_plugin_multi_currency_in_use() ) {
			self::add_option_if_absent( self::FEATURE_ENABLE_OPTION, 'yes' );
		}
	}

	/**
	 * Add an autoloaded option only when its row does not exist yet.
	 *
	 * WordPress's add_option() checks for the option in PHP and then writes with INSERT ... ON DUPLICATE KEY UPDATE, so a request that read the
	 * option before another one saved it would replace that choice. Here the database keeps an existing row.
	 *
	 * @param string $option Option name.
	 * @param string $value  Option value.
	 */
	private static function add_option_if_absent( string $option, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An insert-only write; the caches are cleared below.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = option_name",
				$option,
				$value,
				wp_determine_option_autoload_value( $option, $value, $value, true )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Record the payments owner this site runs under, and hand the plugin's Multi-Currency state over on the first request after the plugin stops owning it.
	 *
	 * The marker is a per-blog option read with get_option(), not a network-wide site option, so each site of a network hands over on its own first
	 * native-owned request. A plugin-owned request re-arms it, so a reactivation hands over again at the next switch. A native-owned request with no
	 * marker gets the upgrade seed and the 'builtin' marker. Runs before the Multi-Currency arbiter reads the feature option.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $extension_owns_payments Whether the WooPayments extension owns payments on this request.
	 * @param bool $builtin_owns_payments   Whether the built-in WooPayments owns payments on this request.
	 */
	public static function track_payments_owner( bool $extension_owns_payments, bool $builtin_owns_payments ): void {
		$last_owner = get_option( self::LAST_PAYMENTS_OWNER_OPTION );
		if ( $extension_owns_payments ) {
			if ( self::LAST_PAYMENTS_OWNER_EXTENSION !== $last_owner ) {
				update_option( self::LAST_PAYMENTS_OWNER_OPTION, self::LAST_PAYMENTS_OWNER_EXTENSION, true );
			}
			return;
		}

		if ( ! $builtin_owns_payments ) {
			return;
		}
		if ( false === $last_owner ) {
			// No plugin-owned request was recorded (a network site may get none before a network deactivation). Apply the upgrade seed,
			// which never overwrites a stored choice, and mark the site so later requests read the autoloaded marker instead of a missing option.
			self::seed_from_prior_use();
			update_option( self::LAST_PAYMENTS_OWNER_OPTION, self::LAST_PAYMENTS_OWNER_BUILTIN, true );
			return;
		}
		if ( self::LAST_PAYMENTS_OWNER_EXTENSION === $last_owner && self::claim_handover() ) {
			self::hand_over_plugin_state();
		}
	}

	/**
	 * Claim the plugin-to-native transition with one conditional update of the marker row.
	 *
	 * Two first native-owned requests can both read 'extension'; only the one whose update changes the row hands over, so a choice the
	 * merchant saves after the first handover is never overwritten by the second.
	 *
	 * @return bool True when this request moved the marker from 'extension' to 'builtin'.
	 */
	private static function claim_handover(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic compare-and-set; the caches are cleared below.
		$claimed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => self::LAST_PAYMENTS_OWNER_BUILTIN ),
			array(
				'option_name'  => self::LAST_PAYMENTS_OWNER_OPTION,
				'option_value' => self::LAST_PAYMENTS_OWNER_EXTENSION,
			)
		);
		wp_cache_delete( self::LAST_PAYMENTS_OWNER_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return 1 === $claimed;
	}

	/**
	 * Hand the plugin's Multi-Currency state over to the feature option.
	 *
	 * While the plugin owns payments the option does not control prices, so a stored value (a Features page save stores "no") is replaced.
	 * Without plugin use only an existing "yes" is turned off; an unset option stays unset.
	 *
	 * @since 11.2.0
	 */
	public static function hand_over_plugin_state(): void {
		if ( self::is_plugin_multi_currency_in_use() ) {
			update_option( self::FEATURE_ENABLE_OPTION, 'yes', true );
		} elseif ( 'yes' === get_option( self::FEATURE_ENABLE_OPTION ) ) {
			update_option( self::FEATURE_ENABLE_OPTION, 'no', true );
		}
	}

	/**
	 * Tell whether the plugin runs Multi-Currency: it was set up and the plugin's own feature flag is on.
	 *
	 * @return bool
	 */
	private static function is_plugin_multi_currency_in_use(): bool {
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$has_prior_use      = ( is_array( $enabled_currencies ) && ! empty( $enabled_currencies ) )
			|| filter_var( get_option( 'wcpay_multi_currency_setup_completed', false ), FILTER_VALIDATE_BOOLEAN );

		// The plugin's own feature flag, on unless the merchant turned it off (client 11.1.0 `includes/class-wc-payments-features.php:67-69`).
		return $has_prior_use && '1' === (string) get_option( self::EXTENSION_MULTI_CURRENCY_FLAG_OPTION, '1' );
	}

	/**
	 * Add the Multi-Currency feature definition.
	 *
	 * @since 11.2.0
	 *
	 * @param FeaturesController $features_controller Feature controller receiving the definition.
	 */
	public function add_feature_definition( FeaturesController $features_controller ): void {
		$features_controller->add_feature_definition(
			self::FEATURE_ID,
			__( 'Multi-currency', 'woocommerce' ),
			array(
				'option_key'                   => self::FEATURE_ENABLE_OPTION,
				'description'                  => __( 'Let customers shop and pay in their own currency.', 'woocommerce' ),
				'enabled_by_default'           => false,
				// While the WooPayments extension is active, its own Multi-Currency setting decides and this one would do nothing.
				'disable_ui'                   => $this->runtime_arbiter->is_payments_extension_owner(),
				'is_experimental'              => false,
				'default_plugin_compatibility' => FeaturePluginCompatibility::COMPATIBLE,
				'setting'                      => $this->get_feature_setting(),
			)
		);
	}

	/**
	 * Get the dynamically evaluated Multi-Currency feature setting.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_feature_setting(): array {
		return array(
			'id'          => self::FEATURE_ENABLE_OPTION,
			'title'       => __( 'Multi-currency', 'woocommerce' ),
			'type'        => 'radio',
			'options'     => array(
				'yes' => __( 'Enable', 'woocommerce' ),
				'no'  => __( 'Disable', 'woocommerce' ),
			),
			'value'       => function (): string {
				return get_option( self::FEATURE_ENABLE_OPTION, 'no' );
			},
			'disabled'    => function (): array {
				return $this->is_disable_protected() ? array( 'no' ) : array();
			},
			'desc'        => function (): string {
				$description = __( 'Let customers shop and pay in their own currency.', 'woocommerce' );
				if ( ! $this->is_disable_protected() ) {
					return $description;
				}

				return $this->get_disable_protected_message() . ' ' . $this->get_disable_anyway_link();
			},
			'desc_at_end' => true,
		);
	}

	/**
	 * Tell whether the disable choice is protected: the Features setting offers only the confirmation link, and the WooPayments
	 * settings refuse an untick.
	 *
	 * @since 11.2.0
	 *
	 * @return bool True when the feature is enabled and persisted data exists or is uncertain.
	 */
	public function is_disable_protected(): bool {
		if ( 'yes' !== get_option( self::FEATURE_ENABLE_OPTION, 'no' ) ) {
			return false;
		}

		if ( $this->usage_detector->has_additional_enabled_currencies() ) {
			return true;
		}

		try {
			return $this->usage_detector->has_foreign_currency_orders();
		} catch ( \RuntimeException $e ) {
			return true;
		}
	}

	/**
	 * Get the sentence shown where the disable choice is protected.
	 *
	 * @since 11.2.0
	 *
	 * @return string
	 */
	public function get_disable_protected_message(): string {
		return __( 'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.', 'woocommerce' );
	}

	/**
	 * Get the URL of the core-managed confirmation for disabling Multi-Currency.
	 *
	 * @since 11.2.0
	 *
	 * @return string
	 */
	public function get_disable_anyway_url(): string {
		return add_query_arg(
			array(
				self::FEATURE_ID => 0,
				'_feature_nonce' => wp_create_nonce( 'change_feature_enable' ),
			),
			admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' )
		);
	}

	/**
	 * Write the WooPayments extension's Multi-Currency flag when the Multi-Currency feature changes, so a later return to
	 * the extension runs its Multi-Currency on the merchant's last choice (the extension reads only that flag).
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $feature_id Feature that changed.
	 * @param mixed $enabled    Whether it is now enabled.
	 */
	public static function mirror_extension_flag( $feature_id, $enabled ): void {
		if ( self::FEATURE_ID !== $feature_id ) {
			return;
		}

		update_option( self::EXTENSION_MULTI_CURRENCY_FLAG_OPTION, $enabled ? '1' : '0' );
	}

	/**
	 * Get the core-managed confirmation link for disabling Multi-Currency.
	 *
	 * @return string Disable confirmation link markup.
	 */
	private function get_disable_anyway_link(): string {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->get_disable_anyway_url() ),
			esc_html__( 'Disable Multi-Currency', 'woocommerce' )
		);
	}
}
