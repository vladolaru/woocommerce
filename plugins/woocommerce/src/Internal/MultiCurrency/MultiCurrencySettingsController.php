<?php
/**
 * MultiCurrencySettingsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Registers native multi-currency settings hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencySettingsController implements RegisterHooksInterface {

	/**
	 * The Multi-currency settings tab id, the WooPayments extension's.
	 */
	public const TAB_ID = 'wcpay_multi_currency';

	private const SETTINGS_SCREEN_BASE  = 'woocommerce_page_wc-settings';
	private const SETTINGS_FIELD_TYPE   = 'wcpay_multi_currency_settings_page';
	private const SETTINGS_CONTAINER_ID = 'wcpay_multi_currency_settings_container';
	private const ADMIN_ASSETS          = array(
		'script' => array(
			'entry'  => 'multi-currency-settings',
			'handle' => 'wc-admin-multi-currency-settings',
		),
		'style'  => array(
			'entry'  => 'multi-currency-settings',
			'file'   => 'style',
			'handle' => 'wc-admin-multi-currency-settings',
		),
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Cache rendering service.
	 *
	 * @var MultiCurrencyCacheRenderingService
	 */
	private MultiCurrencyCacheRenderingService $cache_rendering_service;

	/**
	 * Proxy for the emoji script printer and the asset-file checks.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Admin asset registrar.
	 *
	 * @var callable|null
	 */
	private $asset_registrar = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter             $arbiter                 Runtime owner arbiter.
	 * @param LegacyProxy                             $legacy_proxy            Proxy for the emoji script printer and the asset-file checks.
	 * @param MultiCurrencyCacheRenderingService|null $cache_rendering_service Cache rendering service.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, ?MultiCurrencyCacheRenderingService $cache_rendering_service = null ): void {
		$this->arbiter                 = $arbiter;
		$this->legacy_proxy            = $legacy_proxy;
		$this->cache_rendering_service = $cache_rendering_service ?? wc_get_container()->get( MultiCurrencyCacheRenderingService::class );
	}

	/**
	 * Set the admin asset registrar.
	 *
	 * @internal Used by tests and future explicit asset bootstrap.
	 *
	 * @param callable $asset_registrar Admin asset registrar.
	 */
	public function set_asset_registrar( callable $asset_registrar ): void {
		$this->asset_registrar = $asset_registrar;
	}

	/**
	 * Get the URL of the Multi-currency settings tab.
	 *
	 * @return string
	 */
	public static function get_settings_page_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB_ID );
	}

	/**
	 * Tell whether the given admin screen is the Multi-currency settings tab.
	 *
	 * @param string|null $current_tab Current settings tab.
	 * @param string|null $screen_base Current screen base.
	 * @return bool
	 */
	public static function is_multi_currency_settings_page( ?string $current_tab, ?string $screen_base ): bool {
		return self::TAB_ID === $current_tab && self::SETTINGS_SCREEN_BASE === $screen_base;
	}

	/**
	 * Register multi-currency settings hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		add_filter( 'woocommerce_get_settings_pages', array( $this, 'handle_woocommerce_get_settings_pages' ) );
		add_action( 'admin_print_scripts', array( $this, 'handle_admin_print_scripts' ) );
		add_action( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $this, 'render_settings_container' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
	}

	/**
	 * Try cache rendering mode auto-detection for authorized admin requests.
	 *
	 * @since 11.2.0
	 * @internal
	 */
	public function handle_admin_init(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->cache_rendering_service->maybe_auto_enable_cache_rendering_mode();
	}

	/**
	 * Add the native multi-currency settings page, except on WP-CLI, WordPress.com jobs and upgrader requests.
	 *
	 * @param array<int,mixed> $settings_pages Existing settings pages.
	 * @return array<int,mixed>
	 */
	public function handle_woocommerce_get_settings_pages( array $settings_pages ): array {
		if ( Constants::is_true( 'WP_CLI' ) || Constants::is_true( 'WPCOM_JOBS' ) || did_action( 'upgrader_process_complete' ) > 0 ) {
			return $settings_pages;
		}

		// The page class extends WC_Settings_Page, which WooCommerce loads only for this filter.
		$settings_pages[] = new MultiCurrencySettingsPage(
			_x( 'Multi-currency', 'Settings tab label', 'woocommerce' ),
			true,
			array(
				array(
					'type' => self::SETTINGS_FIELD_TYPE,
				),
			)
		);

		return $settings_pages;
	}

	/**
	 * Render the React mount point for connected multi-currency settings.
	 *
	 * @internal
	 */
	public function render_settings_container(): void {
		$GLOBALS['hide_save_button'] = true;

		echo wp_kses_post(
			sprintf(
				'<div id="%s" class="wc-settings-prevent-change-event" aria-describedby="%s-description"></div>',
				esc_attr( self::SETTINGS_CONTAINER_ID ),
				esc_attr( self::SETTINGS_CONTAINER_ID )
			)
		);
	}

	/**
	 * Print the emoji detection script on the multi-currency settings page.
	 *
	 * @internal
	 */
	public function handle_admin_print_scripts(): void {
		if ( ! self::is_multi_currency_settings_page( $this->get_current_tab(), $this->get_current_screen_base() ) ) {
			return;
		}

		$this->legacy_proxy->call_function( 'print_emoji_detection_script' );
	}

	/**
	 * Enqueue multi-currency settings assets when the migrated bundle is available.
	 *
	 * @internal
	 */
	public function handle_admin_enqueue_scripts(): void {
		if ( self::TAB_ID !== $this->get_current_tab() || ! $this->is_settings_asset_available() ) {
			return;
		}

		$this->register_admin_assets();
	}

	/**
	 * Get the current WooCommerce settings tab.
	 *
	 * @return string|null
	 */
	private function get_current_tab(): ?string {
		global $current_tab;

		return is_string( $current_tab ) ? $current_tab : null;
	}

	/**
	 * Get the current admin screen base.
	 *
	 * @return string|null
	 */
	private function get_current_screen_base(): ?string {
		global $current_screen;

		if ( is_object( $current_screen ) && isset( $current_screen->base ) && is_string( $current_screen->base ) ) {
			return $current_screen->base;
		}

		return null;
	}

	/**
	 * Tell whether the migrated settings asset bundle exists.
	 *
	 * @return bool
	 */
	private function is_settings_asset_available(): bool {
		$script = self::ADMIN_ASSETS['script'];
		$style  = self::ADMIN_ASSETS['style'];

		return $this->legacy_proxy->call_function( 'file_exists', WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . 'wp-admin-scripts/' . $script['entry'] . '.asset.php' )
			&& $this->legacy_proxy->call_function( 'file_exists', WC_ADMIN_ABSPATH . WC_ADMIN_DIST_CSS_FOLDER . $style['entry'] . '/' . $style['file'] . '.asset.php' );
	}

	/**
	 * Register the admin script, and register and enqueue the admin stylesheet.
	 */
	private function register_admin_assets(): void {
		if ( null !== $this->asset_registrar ) {
			call_user_func( $this->asset_registrar, self::ADMIN_ASSETS );
			return;
		}

		WCAdminAssets::register_script( 'wp-admin-scripts', self::ADMIN_ASSETS['script']['entry'], true );

		$style_entry           = self::ADMIN_ASSETS['style']['entry'];
		$style_file            = self::ADMIN_ASSETS['style']['file'];
		$style_handle          = self::ADMIN_ASSETS['style']['handle'];
		$style_assets_filename = WCAdminAssets::get_script_asset_filename( $style_entry, $style_file );
		$style_assets          = require WC_ADMIN_ABSPATH
			. WC_ADMIN_DIST_CSS_FOLDER
			. $style_entry
			. '/'
			. $style_assets_filename;

		wp_enqueue_style(
			$style_handle,
			WCAdminAssets::get_url( $style_entry . '/' . $style_file, 'css' ),
			array(),
			WCAdminAssets::get_file_version( 'css', $style_assets['version'] )
		);
		wp_style_add_data( $style_handle, 'rtl', 'replace' );
	}
}
