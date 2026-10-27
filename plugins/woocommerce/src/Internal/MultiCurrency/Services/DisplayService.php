<?php
/**
 * DisplayService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;

/**
 * Gives the request's currency and display format, the store currencies, and the settings and public config the settings
 * screen and the async renderer read.
 *
 * @since 11.0.0
 * @internal
 */
class DisplayService {

	const RENDERING_MODE_SPEED      = 'speed';
	const RENDERING_MODE_CACHE      = 'cache';
	const RENDERING_MODE_OPTION     = 'wcpay_multi_currency_rendering_mode';
	const APPLY_CHARM_PRODUCTS_HOOK = 'wcpay_multi_currency_apply_charm_only_to_products';

	/**
	 * State builder.
	 *
	 * @var StateBuilder
	 */
	private StateBuilder $state_builder;

	/**
	 * Localization service.
	 *
	 * @var LocalizationInterface
	 */
	private LocalizationInterface $localization_service;

	/**
	 * Geolocation service.
	 *
	 * @var GeolocationService
	 */
	private GeolocationService $geolocation_service;

	/**
	 * Constructor.
	 *
	 * @param StateBuilder          $state_builder        State builder.
	 * @param LocalizationInterface $localization_service Localization service.
	 * @param GeolocationService    $geolocation_service  Geolocation service.
	 */
	public function __construct(
		StateBuilder $state_builder,
		LocalizationInterface $localization_service,
		GeolocationService $geolocation_service
	) {
		$this->state_builder        = $state_builder;
		$this->localization_service = $localization_service;
		$this->geolocation_service  = $geolocation_service;
	}

	/**
	 * Get the currency code for this request: the order's, the selected one, or the store currency when a filter asks.
	 *
	 * With nothing selected, the code earlier `woocommerce_currency` filters gave is kept, so a third-party currency switcher
	 * keeps working (client 11.2.0 `includes/multi-currency/FrontendCurrencies.php:208-229`).
	 *
	 * @param string|null $order_currency    Optional order currency override.
	 * @param string|null $filtered_currency Code earlier `woocommerce_currency` filters gave.
	 * @return string
	 */
	public function get_woocommerce_currency( ?string $order_currency = null, ?string $filtered_currency = null ): string {
		$state = $this->state_builder->build();

		/**
		 * Filters whether WooCommerce sees the store currency code instead of the selected one, as FedEx and UPS rate requests need.
		 *
		 * Asked on every lookup (client 11.1.0 `includes/multi-currency/FrontendCurrencies.php:191-199`).
		 *
		 * @param bool $should_return_store_currency Whether to return the store currency code.
		 *
		 * @since 11.0.0
		 */
		if ( (bool) apply_filters( 'wcpay_multi_currency_should_return_store_currency', false ) ) {
			return $state->get_default_currency()->get_code();
		}

		if ( is_string( $order_currency ) && '' !== $order_currency ) {
			return strtoupper( $order_currency );
		}

		$selected = $state->get_explicit_selected_currency();
		if ( null === $selected && is_string( $filtered_currency ) && '' !== $filtered_currency ) {
			return $filtered_currency;
		}

		return ( $selected ?? $state->get_default_currency() )->get_code();
	}

	/**
	 * Get the number of price decimals of this request's currency.
	 *
	 * @param int         $decimals       Original decimal count.
	 * @param string|null $order_currency Optional order currency override.
	 * @return int
	 */
	public function get_price_decimals( int $decimals, ?string $order_currency = null ): int {
		$state         = $this->state_builder->build();
		$currency_code = $this->get_currency_code( $state, $order_currency );

		if ( $currency_code !== $state->get_default_currency()->get_code() ) {
			return absint( $this->localization_service->get_currency_format( $currency_code )['num_decimals'] );
		}

		return $decimals;
	}

	/**
	 * Get the price decimal separator of this request's currency.
	 *
	 * @param string      $separator      Original separator.
	 * @param string|null $order_currency Optional order currency override.
	 * @return string
	 */
	public function get_price_decimal_separator( string $separator, ?string $order_currency = null ): string {
		$state         = $this->state_builder->build();
		$currency_code = $this->get_currency_code( $state, $order_currency );

		if ( $currency_code !== $state->get_default_currency()->get_code() ) {
			return (string) $this->localization_service->get_currency_format( $currency_code )['decimal_sep'];
		}

		return $separator;
	}

	/**
	 * Get the price thousand separator of this request's currency.
	 *
	 * @param string      $separator      Original separator.
	 * @param string|null $order_currency Optional order currency override.
	 * @return string
	 */
	public function get_price_thousand_separator( string $separator, ?string $order_currency = null ): string {
		$state         = $this->state_builder->build();
		$currency_code = $this->get_currency_code( $state, $order_currency );

		if ( $currency_code !== $state->get_default_currency()->get_code() ) {
			return (string) $this->localization_service->get_currency_format( $currency_code )['thousand_sep'];
		}

		return $separator;
	}

	/**
	 * Get the price format of this request's currency.
	 *
	 * @param string      $format         Original price format.
	 * @param string|null $order_currency Optional order currency override.
	 * @return string
	 */
	public function get_woocommerce_price_format( string $format, ?string $order_currency = null ): string {
		$state         = $this->state_builder->build();
		$currency_code = $this->get_currency_code( $state, $order_currency );

		if ( $currency_code === $state->get_default_currency()->get_code() ) {
			return $format;
		}

		return $this->currency_position_to_price_format(
			(string) $this->localization_service->get_currency_format( $currency_code )['currency_pos']
		);
	}

	/**
	 * Get the currency symbol position of this request's currency.
	 *
	 * @param string      $position       Original currency position.
	 * @param string|null $order_currency Optional order currency override.
	 * @return string
	 */
	public function get_woocommerce_currency_pos( string $position, ?string $order_currency = null ): string {
		$state         = $this->state_builder->build();
		$currency_code = $this->get_currency_code( $state, $order_currency );

		if ( $currency_code !== $state->get_default_currency()->get_code() ) {
			return (string) $this->localization_service->get_currency_format( $currency_code )['currency_pos'];
		}

		return $position;
	}

	/**
	 * Vary the cart hash by the selected currency and its rate.
	 *
	 * @param string $hash Original cart hash.
	 * @return string
	 */
	public function add_currency_to_cart_hash( string $hash ): string {
		$currency = $this->state_builder->build()->get_selected_currency();

		return md5( $hash . $currency->get_code() . $currency->get_rate() );
	}

	/**
	 * Get the store currencies (available, enabled, default), keyed as the settings screen reads them.
	 *
	 * @return array<string,mixed>
	 */
	public function get_store_currencies(): array {
		$state = $this->state_builder->build();

		return array(
			'available' => $state->get_available_currencies(),
			'enabled'   => $state->get_enabled_currencies(),
			'default'   => $state->get_default_currency(),
		);
	}

	/**
	 * Get one currency's stored settings.
	 *
	 * @param string $currency_code Currency code.
	 * @return array<string,mixed>
	 * @throws InvalidCurrencyException When the currency is not available.
	 */
	public function get_single_currency_settings( string $currency_code ): array {
		$currency_code = strtoupper( $currency_code );

		if ( ! array_key_exists( $currency_code, get_woocommerce_currencies() ) ) {
			throw new InvalidCurrencyException( esc_html( 'Invalid currency passed to get_single_currency_settings: ' . $currency_code ), 500 );
		}

		$currency_id = strtolower( $currency_code );

		return array(
			'exchange_rate_type' => get_option( StateBuilder::OPTION_PREFIX . '_exchange_rate_' . $currency_id, 'automatic' ),
			'manual_rate'        => get_option( StateBuilder::OPTION_PREFIX . '_manual_rate_' . $currency_id, null ),
			'price_rounding'     => get_option( StateBuilder::OPTION_PREFIX . '_price_rounding_' . $currency_id, null ),
			'price_charm'        => get_option( StateBuilder::OPTION_PREFIX . '_price_charm_' . $currency_id, null ),
		);
	}

	/**
	 * Get the store-level Multi-Currency settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		return array(
			StateBuilder::OPTION_PREFIX . '_enable_auto_currency' => $this->is_using_auto_currency_switching(),
			StateBuilder::OPTION_PREFIX . '_enable_storefront_switcher' => $this->is_using_storefront_switcher(),
			self::RENDERING_MODE_OPTION => $this->get_rendering_mode(),
			'site_theme'                => wp_get_theme()->get( 'Name' ),
			'date_format'               => esc_attr( get_option( 'date_format', 'F j, Y' ) ),
			'time_format'               => esc_attr( get_option( 'time_format', 'g:i a' ) ),
			'store_url'                 => esc_attr( $this->get_store_page_uri() ),
		);
	}

	/**
	 * Get the public config the async price renderer reads.
	 *
	 * @return array<string,mixed>
	 */
	public function get_public_config(): array {
		$state              = $this->state_builder->build();
		$enabled_currencies = $state->get_enabled_currencies();
		$default_currency   = $state->get_default_currency();
		$default_code       = $default_currency->get_code();
		$selected_code      = $this->get_public_config_selected_currency_code( $enabled_currencies, $default_code );
		$currencies_data    = array();

		foreach ( $enabled_currencies as $currency ) {
			$currencies_data[ $currency->get_code() ] = $this->get_public_config_currency_data( $currency, $default_code );
		}

		/**
		 * Filters whether charm pricing applies only to product prices.
		 *
		 * @param bool $apply_charm_only_to_products Whether charm pricing is product-only.
		 *
		 * @since 11.0.0
		 */
		$charm_only_products = (bool) apply_filters( self::APPLY_CHARM_PRODUCTS_HOOK, true );

		return array(
			'default_currency'    => $default_code,
			'selected_currency'   => $selected_code,
			'charm_only_products' => $charm_only_products,
			'currencies'          => $currencies_data,
		);
	}

	/**
	 * Tell whether cache-optimized rendering is active.
	 *
	 * @return bool
	 */
	public function is_cache_optimized_mode(): bool {
		return self::RENDERING_MODE_CACHE === $this->get_rendering_mode();
	}

	/**
	 * Get the selected currency or order override code.
	 *
	 * @param MultiCurrencyState $state          Multi-currency state.
	 * @param string|null        $order_currency Optional order currency override.
	 * @return string
	 */
	private function get_currency_code( MultiCurrencyState $state, ?string $order_currency = null ): string {
		return is_string( $order_currency ) && '' !== $order_currency
			? strtoupper( $order_currency )
			: $state->get_selected_currency()->get_code();
	}

	/**
	 * Convert a currency position value to a WooCommerce price format.
	 *
	 * @param string $currency_pos Currency position.
	 * @return string
	 */
	private function currency_position_to_price_format( string $currency_pos ): string {
		switch ( $currency_pos ) {
			case 'right':
				return '%2$s%1$s';
			case 'left_space':
				return '%1$s&nbsp;%2$s';
			case 'right_space':
				return '%2$s&nbsp;%1$s';
			case 'left':
			default:
				return '%1$s%2$s';
		}
	}

	/**
	 * Get the selected currency code for the public config without writes.
	 *
	 * @param array<string,MultiCurrencyCurrency> $enabled_currencies Enabled currencies.
	 * @param string                              $default_code       Default currency code.
	 * @return string
	 */
	private function get_public_config_selected_currency_code( array $enabled_currencies, string $default_code ): string {
		if ( $this->has_active_session() ) {
			$stored = WC()->session->get( StateBuilder::CURRENCY_STORAGE_KEY );
			$stored = is_string( $stored ) ? strtoupper( $stored ) : null;

			if ( $stored && isset( $enabled_currencies[ $stored ] ) ) {
				return $stored;
			}
		} elseif ( $this->is_using_auto_currency_switching() ) {
			$geo_currency = $this->geolocation_service->get_currency_by_customer_location();
			$geo_currency = is_string( $geo_currency ) ? strtoupper( $geo_currency ) : null;

			if ( $geo_currency && isset( $enabled_currencies[ $geo_currency ] ) ) {
				return $geo_currency;
			}
		}

		return $default_code;
	}

	/**
	 * Tell whether a cookie-backed WooCommerce session is already active.
	 *
	 * @return bool
	 */
	private function has_active_session(): bool {
		$session = WC()->session;

		// The session class is swappable (woocommerce_session_handler), and the WC_Session base class has no has_session().
		return is_object( $session )
			&& method_exists( $session, 'has_session' )
			&& $session->has_session();
	}

	/**
	 * Build public config data for a currency.
	 *
	 * @param MultiCurrencyCurrency $currency     Currency.
	 * @param string                $default_code Default currency code.
	 * @return array<string,mixed>
	 */
	private function get_public_config_currency_data( MultiCurrencyCurrency $currency, string $default_code ): array {
		$currency_code = $currency->get_code();

		if ( $currency_code === $default_code ) {
			$decimals     = wc_get_price_decimals();
			$decimal_sep  = wc_get_price_decimal_separator();
			$thousand_sep = wc_get_price_thousand_separator();
			$symbol_pos   = get_option( 'woocommerce_currency_pos' );
		} else {
			$format       = $this->localization_service->get_currency_format( $currency_code );
			$decimals     = absint( $format['num_decimals'] );
			$decimal_sep  = $format['decimal_sep'];
			$thousand_sep = $format['thousand_sep'];
			$symbol_pos   = $format['currency_pos'];
		}

		return array(
			'code'             => $currency_code,
			'symbol'           => get_woocommerce_currency_symbol( $currency_code ),
			'rate'             => $currency->get_rate(),
			'decimals'         => $decimals,
			'decimal_sep'      => $decimal_sep,
			'thousand_sep'     => $thousand_sep,
			'symbol_pos'       => $symbol_pos,
			'rounding'         => (float) $currency->get_rounding(),
			'charm'            => (float) $currency->get_charm(),
			// The canonical decimal strings the renderer computes with, so it rounds on the same digits as the server.
			'rate_decimal'     => $currency->get_rate_decimal(),
			'rounding_decimal' => $currency->get_rounding_decimal(),
		);
	}

	/**
	 * Tell whether automatic currency switching is enabled.
	 *
	 * @return bool
	 */
	private function is_using_auto_currency_switching(): bool {
		return 'yes' === get_option( StateBuilder::OPTION_PREFIX . '_enable_auto_currency', 'no' );
	}

	/**
	 * Tell whether the storefront switcher setting is enabled.
	 *
	 * @return bool
	 */
	private function is_using_storefront_switcher(): bool {
		return 'yes' === get_option( StateBuilder::OPTION_PREFIX . '_enable_storefront_switcher', 'no' );
	}

	/**
	 * Get the rendering mode option.
	 *
	 * @return string
	 */
	private function get_rendering_mode(): string {
		return (string) get_option( self::RENDERING_MODE_OPTION, self::RENDERING_MODE_SPEED );
	}

	/**
	 * Get the shop page URI as a string.
	 *
	 * @return string
	 */
	private function get_store_page_uri(): string {
		$store_url = get_page_uri( wc_get_page_id( 'shop' ) );

		return is_string( $store_url ) ? $store_url : '';
	}
}
