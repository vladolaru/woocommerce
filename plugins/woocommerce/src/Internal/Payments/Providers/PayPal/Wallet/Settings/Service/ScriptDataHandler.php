<?php
/**
 * PayPal Commerce Script Data Handler.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * This class is responsible for localizing the scripts and styles for the settings page.
 */
class ScriptDataHandler {

	/**
	 * The admin client build of the settings page: the `ppcp-admin-settings` script, its stylesheet and the app images.
	 * The app itself is a lazy chunk of the Payments settings route.
	 */
	private const ADMIN_BUILD_NAME = 'paypal-wallet-settings';

	/**
	 * Whether the Pay Later configurator is available.
	 *
	 * @var bool
	 */
	protected bool $paylater_is_available;

	/**
	 * The store country code.
	 *
	 * @var string
	 */
	protected string $store_country;

	/**
	 * The PayPal merchant ID of the partner.
	 *
	 * @var string
	 */
	protected string $merchant_id;

	/**
	 * The language choices of the buttons.
	 *
	 * @var array
	 */
	protected array $button_language_choices;

	/**
	 * Provides the PayPal partner attribution.
	 *
	 * @var PartnerAttribution
	 */
	protected PartnerAttribution $partner_attribution;

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	protected SettingsProvider $settings_provider;

	/**
	 * Whether the SDK v6 module is loaded. Defaulted for existing callers.
	 *
	 * @var bool
	 */
	private bool $is_sdk_v6_active;

	/**
	 * Constructor.
	 *
	 * @param bool               $paylater_is_available   Whether the Pay Later configurator is available.
	 * @param string             $store_country           The store country code.
	 * @param string             $merchant_id             The PayPal merchant ID of the partner.
	 * @param array              $button_language_choices The language choices of the buttons.
	 * @param PartnerAttribution $partner_attribution     Provides the PayPal partner attribution.
	 * @param SettingsProvider   $settings_provider       The settings provider.
	 * @param bool               $is_sdk_v6_active        Whether the SDK v6 module is loaded.
	 */
	public function __construct(
		bool $paylater_is_available,
		string $store_country,
		string $merchant_id,
		array $button_language_choices,
		PartnerAttribution $partner_attribution,
		SettingsProvider $settings_provider,
		bool $is_sdk_v6_active = false
	) {
		$this->paylater_is_available   = $paylater_is_available;
		$this->store_country           = $store_country;
		$this->merchant_id             = $merchant_id;
		$this->button_language_choices = $button_language_choices;
		$this->partner_attribution     = $partner_attribution;
		$this->settings_provider       = $settings_provider;
		$this->is_sdk_v6_active        = $is_sdk_v6_active;
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

		$script_asset_file = $this->get_admin_asset_data( 'wp-admin-scripts', self::ADMIN_BUILD_NAME, 'js' );

		wp_register_script(
			'ppcp-admin-settings',
			WCAdminAssets::get_url( 'wp-admin-scripts/' . self::ADMIN_BUILD_NAME, 'js' ),
			$script_asset_file['dependencies'],
			$script_asset_file['version'],
			true
		);

		// No wp_set_script_translations() here: this script holds no strings. The app's strings are in its lazy chunk,
		// whose translations core's admin combines into the wc-admin-app translations of the page.
		wp_enqueue_script( 'ppcp-admin-settings', '', array( 'wp-i18n' ), $script_asset_file['version'], true );

		$style_asset_file = $this->get_admin_asset_data( self::ADMIN_BUILD_NAME, 'style', 'css' );

		wp_register_style(
			'ppcp-admin-settings',
			WCAdminAssets::get_url( self::ADMIN_BUILD_NAME . '/style', 'css' ),
			$style_asset_file['dependencies'],
			$style_asset_file['version']
		);
		wp_style_add_data( 'ppcp-admin-settings', 'rtl', 'replace' );

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
			'assets'                          => array(
				'imagesUrl' => plugins_url( WCAdminAssets::get_path( 'css' ) . self::ADMIN_BUILD_NAME . '/images/', WC_ADMIN_PLUGIN_FILE ),
			),
			'wcPaymentsTabUrl'                => admin_url( 'admin.php?page=wc-settings&tab=checkout' ),
			'pluginSettingsUrl'               => admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' ),
			'debug'                           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'isPayLaterConfiguratorAvailable' => $is_pay_later_configurator_available,
			'storeCountry'                    => $this->store_country,
			'storePostcode'                   => get_option( 'woocommerce_store_postcode', '' ),
			'buttonLanguageChoices'           => $transformed_button_choices,
			'blueprint'                       => array(
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

		/**
		 * Fires after the scripts and styles of the settings app are registered and localized.
		 *
		 * @since 11.3.0
		 */
		do_action( 'woocommerce_paypal_payments_settings_scripts_enqueued' );
	}

	/**
	 * The dependencies and version webpack wrote next to a file of the admin client build. A missing build gives no
	 * dependencies and the WooCommerce version, so the page still loads.
	 *
	 * @param string $path_name The folder of the file under the admin build.
	 * @param string $file      The file name, without its extension.
	 * @param string $ext       The file extension, `js` or `css`.
	 * @return array{dependencies: string[], version: string}
	 */
	private function get_admin_asset_data( string $path_name, string $file, string $ext ): array {
		try {
			$asset = require WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . $path_name . '/' . WCAdminAssets::get_script_asset_filename( $path_name, $file );
		} catch ( \Exception $e ) {
			$asset = array();
		}

		$dependencies = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? array_values( array_filter( $asset['dependencies'], 'is_string' ) ) : array();
		$version      = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : null;

		return array(
			'dependencies' => $dependencies,
			'version'      => (string) WCAdminAssets::get_file_version( $ext, $version ),
		);
	}
}
