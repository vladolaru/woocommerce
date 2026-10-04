<?php
/**
 * PayPal Commerce Script Data Handler.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * This class is responsible for localizing the scripts and styles for the settings page.
 */
class ScriptDataHandler {

	private AssetGetter $asset_getter;
	protected bool $paylater_is_available;
	protected string $store_country;
	protected string $merchant_id;
	protected array $button_language_choices;
	protected PartnerAttribution $partner_attribution;
	protected SettingsProvider $settings_provider;

	/**
	 * Whether the SDK v6 module is loaded. Defaulted for existing callers.
	 */
	private bool $is_sdk_v6_active;

	public function __construct(
		AssetGetter $asset_getter,
		bool $paylater_is_available,
		string $store_country,
		string $merchant_id,
		array $button_language_choices,
		PartnerAttribution $partner_attribution,
		SettingsProvider $settings_provider,
		bool $is_sdk_v6_active = false
	) {
		$this->asset_getter                    = $asset_getter;
		$this->paylater_is_available           = $paylater_is_available;
		$this->store_country                   = $store_country;
		$this->merchant_id                     = $merchant_id;
		$this->button_language_choices         = $button_language_choices;
		$this->partner_attribution             = $partner_attribution;
		$this->settings_provider               = $settings_provider;
		$this->is_sdk_v6_active                = $is_sdk_v6_active;
	}

	/**
	 * Localize scripts.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function localize_scripts( string $hook_suffix ): void {
		/**
		 * Param types removed to avoid third-party issues.
		 *
		 * @psalm-suppress MissingClosureParamType
		 */

		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		/**
		 * Require resolves.
		 *
		 * @psalm-suppress UnresolvableInclude
		 */
		$script_asset_file = require $this->asset_getter->get_asset_php_path( 'index.js' );

		wp_register_script(
			'ppcp-admin-settings',
			$this->asset_getter->get_asset_url( 'index.js' ),
			$script_asset_file['dependencies'],
			$script_asset_file['version'],
			true
		);

		wp_enqueue_script( 'ppcp-admin-settings', '', array( 'wp-i18n' ), $script_asset_file['version'], true );
		wp_set_script_translations(
			'ppcp-admin-settings',
			'woocommerce',
		);

		/** @psalm-suppress UnresolvableInclude */
		$style_asset_file = require $this->asset_getter->get_asset_php_path( 'styles.css' );

		wp_register_style(
			'ppcp-admin-settings',
			$this->asset_getter->get_asset_url( 'styles.css' ),
			$style_asset_file['dependencies'],
			$style_asset_file['version']
		);

		wp_enqueue_style( 'ppcp-admin-settings' );

		wp_enqueue_style( 'ppcp-admin-settings-font', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap', array(), $style_asset_file['version'] );

		$is_pay_later_configurator_available = $this->paylater_is_available;

		$transformed_button_choices = array_map(
			function ( $key, $value ) {
				return array(
					'value' => $key,
					'label' => $value,
				);
			},
			array_keys( $this->button_language_choices ),
			$this->button_language_choices
		);

		$script_data = array(
			'assets'                              => array(
				'imagesUrl' => $this->asset_getter->get_static_asset_url( 'images/' ),
			),
			'wcPaymentsTabUrl'                    => admin_url( 'admin.php?page=wc-settings&tab=checkout' ),
			'pluginSettingsUrl'                   => admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' ),
			'debug'                               => defined( 'WP_DEBUG' ) && WP_DEBUG, // @phpstan-ignore booleanAnd.rightAlwaysFalse
			'isPayLaterConfiguratorAvailable'     => $is_pay_later_configurator_available,
			'storeCountry'                        => $this->store_country,
			'storePostcode'                       => get_option( 'woocommerce_store_postcode', '' ),
			'buttonLanguageChoices'               => $transformed_button_choices,
			'blueprint'                           => array(
				'isActive'  => 'yes' === get_option( 'woocommerce_feature_blueprint_enabled', 'no' ),
				'importUrl' => admin_url( 'admin.php?page=wc-settings&tab=advanced&section=blueprint' ),
			),
		);

		if ( $is_pay_later_configurator_available ) {

			wp_enqueue_script(
				'ppcp-paylater-configurator-lib',
				'https://www.paypalobjects.com/merchant-library/merchant-configurator.js',
				array( 'wp-i18n' ),
				$script_asset_file['version'],
				true
			);
			wp_set_script_translations(
				'ppcp-paylater-configurator-lib',
				'woocommerce',
			);
			$script_data['PcpPayLaterConfigurator'] = array(
				'config'           => array(),
				'merchantClientId' => $this->settings_provider->merchant_data()->client_id,
				'partnerClientId'  => $this->merchant_id,
				'bnCode'           => $this->partner_attribution->get_bn_code(),
				// v6 serves neither shop nor home and styles text only.
				'isSdkV6Active'    => $this->is_sdk_v6_active,
			);
		}

		wp_localize_script(
			'ppcp-admin-settings',
			'ppcpSettings',
			$script_data
		);

		do_action( 'woocommerce_paypal_payments_settings_scripts_enqueued' );
	}
}
