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

/**
 * Registers native multi-currency settings hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencySettingsController implements RegisterHooksInterface {

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
	 * Admin request resolver.
	 *
	 * @var callable|null
	 */
	private $admin_request_resolver = null;

	/**
	 * Current WooCommerce settings tab resolver.
	 *
	 * @var callable|null
	 */
	private $current_tab_resolver = null;

	/**
	 * Current admin screen base resolver.
	 *
	 * @var callable|null
	 */
	private $current_screen_base_resolver = null;

	/**
	 * Admin asset availability resolver.
	 *
	 * @var callable|null
	 */
	private $asset_available_resolver = null;

	/**
	 * Admin asset registrar.
	 *
	 * @var callable|null
	 */
	private $asset_registrar = null;

	/**
	 * Admin asset enqueuer.
	 *
	 * @var callable|null
	 */
	private $asset_enqueuer = null;

	/**
	 * Emoji detection script printer.
	 *
	 * @var callable|null
	 */
	private $emoji_detection_script_printer = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter             $arbiter                 Runtime owner arbiter.
	 * @param MultiCurrencyCacheRenderingService|null $cache_rendering_service Cache rendering service.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, ?MultiCurrencyCacheRenderingService $cache_rendering_service = null ): void {
		$this->arbiter                 = $arbiter;
		$this->cache_rendering_service = $cache_rendering_service ?? wc_get_container()->get( MultiCurrencyCacheRenderingService::class );
	}

	/**
	 * Set the admin request resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $admin_request_resolver Admin request resolver.
	 */
	public function set_admin_request_resolver( callable $admin_request_resolver ): void {
		$this->admin_request_resolver = $admin_request_resolver;
	}

	/**
	 * Set the current settings tab resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $current_tab_resolver Current settings tab resolver.
	 */
	public function set_current_tab_resolver( callable $current_tab_resolver ): void {
		$this->current_tab_resolver = $current_tab_resolver;
	}

	/**
	 * Set the current screen base resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $current_screen_base_resolver Current screen base resolver.
	 */
	public function set_current_screen_base_resolver( callable $current_screen_base_resolver ): void {
		$this->current_screen_base_resolver = $current_screen_base_resolver;
	}

	/**
	 * Set the admin asset availability resolver.
	 *
	 * @internal Used by tests and future explicit asset bootstrap.
	 *
	 * @param callable $asset_available_resolver Admin asset availability resolver.
	 */
	public function set_asset_available_resolver( callable $asset_available_resolver ): void {
		$this->asset_available_resolver = $asset_available_resolver;
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
	 * Set the admin asset enqueuer.
	 *
	 * @internal Used by tests and future explicit asset bootstrap.
	 *
	 * @param callable $asset_enqueuer Admin asset enqueuer.
	 */
	public function set_asset_enqueuer( callable $asset_enqueuer ): void {
		$this->asset_enqueuer = $asset_enqueuer;
	}

	/**
	 * Set the emoji detection script printer.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $emoji_detection_script_printer Emoji detection script printer.
	 */
	public function set_emoji_detection_script_printer( callable $emoji_detection_script_printer ): void {
		$this->emoji_detection_script_printer = $emoji_detection_script_printer;
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
		if ( ! $this->is_admin_request() || ! current_user_can( 'manage_woocommerce' ) ) {
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

		$settings_pages[] = new MultiCurrencySettingsPage(
			MultiCurrencySettingsPage::TAB_ID,
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
		if ( ! $this->is_multi_currency_settings_page() ) {
			return;
		}

		if ( null !== $this->emoji_detection_script_printer ) {
			call_user_func( $this->emoji_detection_script_printer );
			return;
		}

		print_emoji_detection_script();
	}

	/**
	 * Enqueue multi-currency settings assets when the migrated bundle is available.
	 *
	 * @internal
	 */
	public function handle_admin_enqueue_scripts(): void {
		if ( MultiCurrencySettingsPage::TAB_ID !== $this->get_current_tab() || ! $this->is_settings_asset_available() ) {
			return;
		}

		$this->register_admin_assets();
		$this->enqueue_admin_asset( self::ADMIN_ASSETS['script']['handle'] );
		$this->enqueue_admin_asset( self::ADMIN_ASSETS['style']['handle'] );
	}

	/**
	 * Tell whether the current request is an admin request.
	 *
	 * @return bool
	 */
	private function is_admin_request(): bool {
		if ( null !== $this->admin_request_resolver ) {
			return (bool) call_user_func( $this->admin_request_resolver );
		}

		return is_admin();
	}

	/**
	 * Get the current WooCommerce settings tab.
	 *
	 * @return string|null
	 */
	private function get_current_tab(): ?string {
		if ( null !== $this->current_tab_resolver ) {
			$current_tab = call_user_func( $this->current_tab_resolver );

			return is_string( $current_tab ) ? $current_tab : null;
		}

		global $current_tab;

		return is_string( $current_tab ) ? $current_tab : null;
	}

	/**
	 * Get the current admin screen base.
	 *
	 * @return string|null
	 */
	private function get_current_screen_base(): ?string {
		if ( null !== $this->current_screen_base_resolver ) {
			$screen_base = call_user_func( $this->current_screen_base_resolver );

			return is_string( $screen_base ) ? $screen_base : null;
		}

		global $current_screen;

		if ( is_object( $current_screen ) && isset( $current_screen->base ) && is_string( $current_screen->base ) ) {
			return $current_screen->base;
		}

		return null;
	}

	/**
	 * Tell whether the current admin screen is the multi-currency settings page.
	 *
	 * @return bool
	 */
	private function is_multi_currency_settings_page(): bool {
		return MultiCurrencySettingsPage::is_multi_currency_settings_page(
			$this->is_admin_request(),
			$this->get_current_tab(),
			$this->get_current_screen_base()
		);
	}

	/**
	 * Tell whether the migrated settings asset bundle exists.
	 *
	 * @return bool
	 */
	private function is_settings_asset_available(): bool {
		if ( null !== $this->asset_available_resolver ) {
			return (bool) call_user_func( $this->asset_available_resolver );
		}

		$script = self::ADMIN_ASSETS['script'];
		$style  = self::ADMIN_ASSETS['style'];

		return file_exists( WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . 'wp-admin-scripts/' . $script['entry'] . '.asset.php' )
			&& file_exists( WC_ADMIN_ABSPATH . WC_ADMIN_DIST_CSS_FOLDER . $style['entry'] . '/' . $style['file'] . '.asset.php' );
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

	/**
	 * Enqueue an admin asset handle.
	 *
	 * @param string $handle Asset handle.
	 */
	private function enqueue_admin_asset( string $handle ): void {
		if ( null !== $this->asset_enqueuer ) {
			call_user_func( $this->asset_enqueuer, $handle );
			return;
		}

		// WCAdminAssets::register_script() enqueues production assets directly.
	}
}
