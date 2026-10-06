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
	 * Persisted Multi-Currency usage detector.
	 *
	 * @var MultiCurrencyUsageDetector
	 */
	private MultiCurrencyUsageDetector $usage_detector;

	/**
	 * Initialize the feature controller.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyUsageDetector $usage_detector Persisted usage detector.
	 */
	final public function init( MultiCurrencyUsageDetector $usage_detector ): void {
		$this->usage_detector = $usage_detector;
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
			add_option( self::FEATURE_ENABLE_OPTION, 'yes', '', true );
		}
	}

	/**
	 * Hand the plugin's Multi-Currency state over to the feature option when the plugin stops owning the site.
	 *
	 * Called once at that moment, before native serves a request: the cutover job's seeding stage while the plugin is still
	 * active, or the manual deactivation hook. Until then the option does not control prices, so a stored value (a Features
	 * page save stores "no") is replaced. Without plugin use only an existing "yes" is turned off; an unset option stays unset.
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
		return $has_prior_use && '1' === (string) get_option( '_wcpay_feature_customer_multi_currency', '1' );
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
				'disable_ui'                   => false,
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

				return __( 'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.', 'woocommerce' ) . ' ' . $this->get_disable_anyway_link();
			},
			'desc_at_end' => true,
		);
	}

	/**
	 * Tell whether the Features setting must protect the disable choice.
	 *
	 * @return bool True when the feature is enabled and persisted data exists or is uncertain.
	 */
	private function is_disable_protected(): bool {
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
	 * Get the core-managed confirmation link for disabling Multi-Currency.
	 *
	 * @return string Disable confirmation link markup.
	 */
	private function get_disable_anyway_link(): string {
		$url = add_query_arg(
			array(
				self::FEATURE_ID => 0,
				'_feature_nonce' => wp_create_nonce( 'change_feature_enable' ),
			),
			admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' )
		);

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Disable Multi-Currency', 'woocommerce' )
		);
	}
}
