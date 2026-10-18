<?php
/**
 * WooPaymentsFeatureController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;

/**
 * Defines the WooPayments feature, which turns the built-in WooPayments on, and refuses its untick while the built-in takes payments.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsFeatureController {

	/** WooPayments feature identifier. */
	public const FEATURE_ID = 'woopayments';

	/** Option storing whether the WooPayments feature is enabled. */
	public const FEATURE_ENABLE_OPTION = 'woocommerce_feature_woopayments_enabled';

	/** Whether the WooPayments feature is on for a store that never chose. */
	public const ENABLED_BY_DEFAULT = false;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Built-in WooPayments setup tier.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Initialize the feature controller.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter    Runtime ownership arbiter.
	 * @param WooPaymentsSetupTier      $setup_tier Built-in WooPayments setup tier.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsSetupTier $setup_tier ): void {
		$this->arbiter    = $arbiter;
		$this->setup_tier = $setup_tier;
	}

	/**
	 * Add the WooPayments feature definition.
	 *
	 * @since 11.2.0
	 *
	 * @param FeaturesController $features_controller Feature controller receiving the definition.
	 */
	public function add_feature_definition( FeaturesController $features_controller ): void {
		$features_controller->add_feature_definition(
			self::FEATURE_ID,
			__( 'WooPayments', 'woocommerce' ),
			array(
				'option_key'                   => self::FEATURE_ENABLE_OPTION,
				'description'                  => $this->get_description(),
				'enabled_by_default'           => self::ENABLED_BY_DEFAULT,
				'disable_ui'                   => false,
				'is_experimental'              => false,
				'skip_compatibility_checks'    => true,
				'default_plugin_compatibility' => FeaturePluginCompatibility::COMPATIBLE,
				'setting'                      => $this->get_feature_setting(),
			)
		);
	}

	/**
	 * Get the dynamically evaluated WooPayments feature setting.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_feature_setting(): array {
		return array(
			'id'          => self::FEATURE_ENABLE_OPTION,
			'title'       => __( 'WooPayments', 'woocommerce' ),
			'type'        => 'radio',
			'options'     => array(
				'yes' => __( 'Enable', 'woocommerce' ),
				'no'  => __( 'Disable', 'woocommerce' ),
			),
			'value'       => function (): string {
				return get_option( self::FEATURE_ENABLE_OPTION, wc_bool_to_string( self::ENABLED_BY_DEFAULT ) );
			},
			'disabled'    => function (): array {
				return $this->is_disable_protected() ? array( 'no' ) : array();
			},
			'desc'        => function (): string {
				if ( ! $this->is_disable_protected() ) {
					return $this->get_description();
				}

				return $this->get_disable_protected_message() . ' ' . $this->get_disable_anyway_link();
			},
			'desc_at_end' => true,
		);
	}

	/**
	 * Tell whether the disable choice is protected: the built-in WooPayments owns payments on a connected or active store.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_disable_protected(): bool {
		return $this->arbiter->is_builtin_owner()
			&& in_array( $this->setup_tier->get_stored_tier(), array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true );
	}

	/**
	 * Get the sentence shown where the disable choice is protected.
	 *
	 * @since 11.2.0
	 *
	 * @return string
	 */
	public function get_disable_protected_message(): string {
		return __( 'WooPayments is taking payments on this store. Turning this off stops checkout. To go back to the WooPayments extension, activate it from Plugins.', 'woocommerce' );
	}

	/**
	 * Get the URL of the core-managed confirmation that turns the WooPayments feature off.
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
	 * Get the feature description.
	 *
	 * @return string
	 */
	private function get_description(): string {
		return __( 'Use WooPayments built into WooCommerce. A store without the WooPayments extension sets it up from Payments settings; a store running the extension is offered a one-click switch.', 'woocommerce' );
	}

	/**
	 * Get the core-managed confirmation link that turns the WooPayments feature off.
	 *
	 * @return string Link markup.
	 */
	private function get_disable_anyway_link(): string {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->get_disable_anyway_url() ),
			esc_html__( 'Disable WooPayments', 'woocommerce' )
		);
	}
}
