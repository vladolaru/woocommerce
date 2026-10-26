<?php
/**
 * WCPay\MultiCurrency\MultiCurrency facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoteController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\CacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;

// phpcs:disable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- The WooPayments extension's file and class casing are the compatibility contract.
/**
 * Stands in for the WooPayments extension's Multi-Currency class while WooCommerce runs Multi-Currency, so Woo
 * extensions that call it get WooCommerce's answer.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 No public replacement. Scheduled for removal in WooCommerce 12.0.0.
 */
class MultiCurrency {
	// phpcs:enable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

	/**
	 * Session key holding the shopper's selected currency. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CURRENCY_SESSION_KEY = StateBuilder::CURRENCY_STORAGE_KEY;

	/**
	 * User meta key holding the customer's selected currency. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CURRENCY_META_KEY = StateBuilder::CURRENCY_STORAGE_KEY;

	/**
	 * Prefix of the Multi-Currency hook names. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const FILTER_PREFIX = StateBuilder::FILTER_PREFIX;

	/**
	 * Option holding the currencies customers have ordered in. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CUSTOMER_CURRENCIES_KEY = StateBuilder::CUSTOMER_CURRENCIES_KEY;

	/**
	 * Rendering mode that converts prices on the server. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_SPEED = DisplayService::RENDERING_MODE_SPEED;

	/**
	 * Rendering mode that converts prices in the browser, for cached pages. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_CACHE = DisplayService::RENDERING_MODE_CACHE;

	/**
	 * Option holding the rendering mode. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_OPTION = DisplayService::RENDERING_MODE_OPTION;

	/**
	 * Option set once the page-cache detection has run. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CACHE_AUTODETECT_DONE_OPTION = CacheRenderingService::AUTODETECT_DONE_OPTION;

	/**
	 * Option set when the merchant dismissed the cache-mode recommendation. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CACHE_RECOMMENDATION_DISMISSED_OPTION = CacheRenderingService::DISMISSED_OPTION;

	/**
	 * Request-local facade instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The compatibility facade get_compatibility() returns, built on first use.
	 *
	 * @var Compatibility|null
	 */
	private ?Compatibility $compatibility = null;

	/**
	 * Return the request's facade instance.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return self
	 */
	public static function instance(): self {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::instance', '11.0.0', '' );

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Convert a price to the selected currency; a product price is recorded for the Deposits controller.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed  $amount Price amount.
	 * @param string $type   Price type.
	 * @return float
	 */
	public function get_price( $amount, string $type ): float {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_price', '11.0.0', '' );

		return $this->get_loader()->get_price( $amount, $type );
	}

	/**
	 * Convert an amount between two currencies at their rates; an empty source currency is the store currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param float  $amount        Amount.
	 * @param string $to_currency   Target currency code.
	 * @param string $from_currency Source currency code.
	 * @return float
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion', '11.0.0', '' );

		if ( '' === $from_currency ) {
			$from_currency = $this->get_state()->get_default_currency()->get_code();
		}

		return $this->get_service_factory()->create_price_converter()->get_raw_conversion( $amount, $to_currency, $from_currency );
	}

	/**
	 * Get the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_selected_currency(): MultiCurrencyCurrency {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_selected_currency', '11.0.0', '' );

		return $this->get_state()->get_selected_currency();
	}

	/**
	 * Get the compatibility facade, whose predicates answer the questions other plugins ask Multi-Currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return Compatibility
	 */
	public function get_compatibility(): Compatibility {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_compatibility', '11.0.0', '' );

		if ( null === $this->compatibility ) {
			$this->compatibility = new Compatibility();
		}

		return $this->compatibility;
	}

	/**
	 * Get the store's default currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_default_currency(): MultiCurrencyCurrency {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_default_currency', '11.0.0', '' );

		return $this->get_state()->get_default_currency();
	}

	/**
	 * Get the enabled currencies, the store currency first, keyed by code.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,MultiCurrencyCurrency>
	 */
	public function get_enabled_currencies(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_enabled_currencies', '11.0.0', '' );

		return $this->get_state()->get_enabled_currencies();
	}

	/**
	 * Get the currencies the store can enable, keyed by code.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,MultiCurrencyCurrency>
	 */
	public function get_available_currencies(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_available_currencies', '11.0.0', '' );

		return $this->get_state()->get_available_currencies();
	}

	/**
	 * Tell whether a currency other than the store currency is enabled.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function has_additional_currencies_enabled(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::has_additional_currencies_enabled', '11.0.0', '' );

		return $this->get_state()->has_additional_currencies_enabled();
	}

	/**
	 * Get the available, enabled and default currencies.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,mixed>
	 */
	public function get_store_currencies(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_store_currencies', '11.0.0', '' );

		return $this->get_display_service()->get_store_currencies();
	}

	/**
	 * Get one available currency's stored rate type, manual rate, rounding and charm.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param string $currency_code Currency code.
	 * @return array<string,mixed>
	 * @throws InvalidCurrencyException When the currency is not available.
	 */
	public function get_single_currency_settings( string $currency_code ): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_single_currency_settings', '11.0.0', '' );

		if ( ! array_key_exists( strtoupper( $currency_code ), $this->get_state()->get_available_currencies() ) ) {
			throw new InvalidCurrencyException( esc_html( 'Invalid currency passed to get_single_currency_settings: ' . $currency_code ), 500 );
		}

		return $this->get_display_service()->get_single_currency_settings( $currency_code );
	}

	/**
	 * Get the store-level Multi-Currency settings the settings screen reads.
	 *
	 * Cache-optimized rendering is always available here, so `is_cache_optimized_feature_enabled` is true; the extension
	 * reads it from its feature flag, on by default (client 11.1.0 `includes/multi-currency/MultiCurrency.php:1352-1365`).
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_settings', '11.0.0', '' );

		$settings = (array) wc_get_container()->get( MultiCurrencyRestController::class )->get_settings()->get_data();

		return array_merge( $settings, array( 'is_cache_optimized_feature_enabled' => true ) );
	}

	/**
	 * Get the currencies, rates and formats the async price renderer reads.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,mixed>
	 */
	public function get_public_config(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_public_config', '11.0.0', '' );

		return $this->get_display_service()->get_public_config();
	}

	/**
	 * Get the rendering mode: 'speed' or 'cache'.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return string
	 */
	public function get_rendering_mode(): string {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_rendering_mode', '11.0.0', '' );

		return (string) get_option( DisplayService::RENDERING_MODE_OPTION, DisplayService::RENDERING_MODE_SPEED );
	}

	/**
	 * Tell whether prices are converted in the browser, for cached pages.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function is_cache_optimized_mode(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::is_cache_optimized_mode', '11.0.0', '' );

		return $this->get_display_service()->is_cache_optimized_mode();
	}

	/**
	 * Tell whether the merchant turned on automatic currency switching by the shopper's location.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function is_using_auto_currency_switching(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::is_using_auto_currency_switching', '11.0.0', '' );

		return 'yes' === get_option( StateBuilder::OPTION_PREFIX . '_enable_auto_currency', 'no' );
	}

	/**
	 * Tell whether the merchant turned on the Storefront theme's currency switcher.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function is_using_storefront_switcher(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::is_using_storefront_switcher', '11.0.0', '' );

		return 'yes' === get_option( StateBuilder::OPTION_PREFIX . '_enable_storefront_switcher', 'no' );
	}

	/**
	 * Tell whether a cookie-backed WooCommerce session is active.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function has_active_session(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::has_active_session', '11.0.0', '' );

		$session = WC()->session;

		// The session class is swappable (woocommerce_session_handler), and the WC_Session base class has no has_session().
		return is_object( $session ) && method_exists( $session, 'has_session' ) && $session->has_session();
	}

	/**
	 * Tell whether charm pricing applies only to product prices.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return mixed The filtered value, true by default.
	 */
	public function get_apply_charm_only_to_products() {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_apply_charm_only_to_products', '11.0.0', '' );

		/**
		 * Filters whether charm pricing applies only to product prices; shipping gets it too when false.
		 *
		 * @param bool $apply_charm_only_to_products Whether charm pricing is product-only.
		 *
		 * @since 11.0.0
		 */
		return apply_filters( DisplayService::APPLY_CHARM_PRODUCTS_HOOK, true );
	}

	/**
	 * Round an amount already in the selected currency, adding its charm unless asked not to.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed $amount              Amount in the selected currency.
	 * @param mixed $apply_charm_pricing Whether the selected currency's charm is added.
	 * @return float
	 */
	public function adjust_amount_for_selected_currency( $amount, $apply_charm_pricing = true ): float {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::adjust_amount_for_selected_currency', '11.0.0', '' );

		return $this->get_service_factory()->create_price_converter()->adjust_amount_for_selected_currency( $amount, (bool) $apply_charm_pricing );
	}

	/**
	 * Get the cached automatic rates ('currencies' and 'updated'), refreshed first as the request's currency state does.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_cached_currencies(): ?array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_cached_currencies', '11.0.0', '' );

		$this->get_state();
		$cached = ( new DatabaseCache() )->get( MultiCurrencyCacheInterface::CURRENCIES_KEY, true );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Get the codes of the currencies customers have ordered in.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return string[]
	 */
	public function get_all_customer_currencies(): array {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_all_customer_currencies', '11.0.0', '' );

		return $this->get_state()->get_customer_currencies();
	}

	/**
	 * Get a currency code in upper case when it is an available currency, or false.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed $currency_code Currency code.
	 * @return string|false
	 */
	public function validate_currency_code( $currency_code ) {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::validate_currency_code', '11.0.0', '' );

		$currency_code = strtoupper( (string) $currency_code );

		return array_key_exists( $currency_code, $this->get_state()->get_available_currencies() ) ? $currency_code : false;
	}

	/**
	 * Get the currency switcher widget's markup, as the theme template tag renders it.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param array $instance Widget instance settings.
	 * @param array $args     Widget arguments.
	 * @return string
	 */
	public function get_switcher_widget_markup( array $instance = array(), array $args = array() ): string {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_switcher_widget_markup', '11.0.0', '' );

		return wc_get_container()->get( MultiCurrencySwitcherWidgetController::class )->get_switcher_widget_markup( $instance, $args );
	}

	/**
	 * Tell whether the current admin screen is the Multi-currency settings tab.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function is_multi_currency_settings_page(): bool {
		global $current_tab, $current_screen;

		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::is_multi_currency_settings_page', '11.0.0', '' );

		$screen_base = is_object( $current_screen ) && isset( $current_screen->base ) && is_string( $current_screen->base ) ? $current_screen->base : null;

		return is_admin() && MultiCurrencySettingsController::is_multi_currency_settings_page( is_string( $current_tab ) ? $current_tab : null, $screen_base );
	}

	/**
	 * Delete the Multi-Currency inbox note, "Sell worldwide in multiple currencies".
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return void
	 */
	public static function remove_woo_admin_notes() {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::remove_woo_admin_notes', '11.0.0', '' );

		Notes::delete_notes_with_name( MultiCurrencyAdminNoteController::NOTE_NAME );
	}

	/**
	 * Get the request's Multi-Currency state.
	 *
	 * @return MultiCurrencyState
	 */
	private function get_state(): MultiCurrencyState {
		return wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build();
	}

	/**
	 * Get a display service over the request's state.
	 *
	 * @return DisplayService
	 */
	private function get_display_service(): DisplayService {
		return $this->get_service_factory()->create_display_service();
	}

	/**
	 * Get the Multi-Currency service factory.
	 *
	 * @return ServiceFactory
	 */
	private function get_service_factory(): ServiceFactory {
		return wc_get_container()->get( ServiceFactory::class );
	}

	/**
	 * Get the container-owned compatibility loader.
	 *
	 * @return WooPaymentsExtensionMultiCurrencyFacadeLoader
	 */
	private function get_loader(): WooPaymentsExtensionMultiCurrencyFacadeLoader {
		return wc_get_container()->get( WooPaymentsExtensionMultiCurrencyFacadeLoader::class );
	}
}
