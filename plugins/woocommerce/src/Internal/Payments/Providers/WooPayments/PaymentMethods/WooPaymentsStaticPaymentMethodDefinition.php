<?php
/**
 * WooPaymentsStaticPaymentMethodDefinition class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods;

/**
 * Data-backed WooPayments payment method definition.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native WooPayments settings runtime.
 */
class WooPaymentsStaticPaymentMethodDefinition implements WooPaymentsPaymentMethodDefinition {

	private const COUNTRY_MODE_STATIC = 'static';

	private const COUNTRY_MODE_DOMESTIC_ONLY = 'domestic_only';

	private const COUNTRY_MODE_KLARNA = 'klarna';

	/**
	 * Definition config.
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * Create a data-backed payment method definition.
	 *
	 * @param array<string,mixed> $config Definition config.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Get the internal payment method ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->get_config_string( 'id' );
	}

	/**
	 * Get duplicate-detection keywords for the payment method.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return $this->get_string_array_config( 'keywords' );
	}

	/**
	 * Get the Stripe capability/payment method key.
	 *
	 * @return string
	 */
	public function get_stripe_id(): string {
		return $this->get_config_string( 'stripe_id' );
	}

	/**
	 * Get the Stripe PaymentMethod type.
	 *
	 * @return string
	 */
	public function get_stripe_payment_method_type(): string {
		return $this->get_config_string( 'stripe_payment_method_type' );
	}

	/**
	 * Get the customer-facing title.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_title( ?string $account_country = null ): string {
		return $this->get_country_specific_string( 'title', 'titles_by_country', $account_country );
	}

	/**
	 * Get a dynamic title based on Stripe charge details.
	 *
	 * @param string              $_account_country Merchant account country.
	 * @param array<string,mixed> $payment_details  Stripe payment method details.
	 * @return string|null
	 */
	public function get_title_from_charge_details( string $_account_country, array $payment_details ): ?string {
		if ( 'card' !== $this->get_id() || ! isset( $payment_details['card'] ) || ! is_array( $payment_details['card'] ) ) {
			return null;
		}

		$details       = $payment_details['card'];
		$funding_types = array(
			'credit'  => 'credit',
			'debit'   => 'debit',
			'prepaid' => 'prepaid',
			'unknown' => 'unknown',
		);
		$funding_key   = isset( $details['funding'] ) && is_string( $details['funding'] ) ? $details['funding'] : 'unknown';
		$funding       = $funding_types[ $funding_key ] ?? $funding_types['unknown'];
		$card_network  = $this->get_card_network( $details );

		return sprintf(
			// Translators: %1$s card brand, %2$s card funding (prepaid, credit, etc.).
			__( '%1$s %2$s card', 'woocommerce' ),
			ucwords( $card_network ),
			$funding
		);
	}

	/**
	 * Get the settings-page label.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_settings_label( ?string $account_country = null ): string {
		if ( isset( $this->config['settings_label'] ) ) {
			return $this->get_config_string( 'settings_label' );
		}

		return $this->get_title( $account_country );
	}

	/**
	 * Get the customer-facing description.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_description( ?string $account_country = null ): string {
		return $this->get_country_specific_string( 'description', 'descriptions_by_country', $account_country );
	}

	/**
	 * Get supported currencies.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string[]
	 */
	public function get_supported_currencies( ?string $account_country = null ): array {
		$account_country = $this->normalize_code( $account_country );

		if ( null !== $account_country ) {
			$currencies_by_country = $this->get_array_config( 'currencies_by_country' );
			if ( isset( $currencies_by_country[ $account_country ] ) && is_array( $currencies_by_country[ $account_country ] ) ) {
				return $this->normalize_code_list( $currencies_by_country[ $account_country ] );
			}

			foreach ( $this->get_array_config( 'currency_country_groups' ) as $group ) {
				if ( ! is_array( $group ) || ! isset( $group['countries'], $group['currencies'] ) || ! is_array( $group['countries'] ) || ! is_array( $group['currencies'] ) ) {
					continue;
				}

				if ( in_array( $account_country, $this->normalize_code_list( $group['countries'] ), true ) ) {
					return $this->normalize_code_list( $group['currencies'] );
				}
			}

			if ( isset( $this->config['fallback_currencies'] ) && is_array( $this->config['fallback_currencies'] ) ) {
				return $this->normalize_code_list( $this->config['fallback_currencies'] );
			}
		}

		return $this->get_string_array_config( 'currencies' );
	}

	/**
	 * Get supported countries.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string[]
	 */
	public function get_supported_countries( ?string $account_country = null ): array {
		$country_mode    = $this->get_config_string( 'country_mode', self::COUNTRY_MODE_STATIC );
		$account_country = $this->normalize_code( $account_country );
		$countries       = $this->get_string_array_config( 'countries' );

		if ( self::COUNTRY_MODE_DOMESTIC_ONLY === $country_mode ) {
			if ( null !== $account_country && in_array( $account_country, $countries, true ) ) {
				return array( $account_country );
			}

			return $countries;
		}

		if ( self::COUNTRY_MODE_KLARNA === $country_mode ) {
			return $this->get_klarna_supported_countries( $account_country );
		}

		return $countries;
	}

	/**
	 * Get payment method capabilities.
	 *
	 * @return string[]
	 */
	public function get_capabilities(): array {
		return $this->get_string_array_config( 'capabilities' );
	}

	/**
	 * Get the light icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_icon_asset_path( ?string $account_country = null ): string {
		return $this->get_country_specific_string( 'icon', 'icons_by_country', $account_country );
	}

	/**
	 * Get the dark icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_dark_icon_asset_path( ?string $account_country = null ): string {
		if ( isset( $this->config['dark_icons_by_country'] ) || isset( $this->config['dark_icon'] ) ) {
			return $this->get_country_specific_string( 'dark_icon', 'dark_icons_by_country', $account_country );
		}

		return $this->get_icon_asset_path( $account_country );
	}

	/**
	 * Get the settings icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_settings_icon_asset_path( ?string $account_country = null ): string {
		if ( isset( $this->config['settings_icons_by_country'] ) || isset( $this->config['settings_icon'] ) ) {
			return $this->get_country_specific_string( 'settings_icon', 'settings_icons_by_country', $account_country );
		}

		return $this->get_icon_asset_path( $account_country );
	}

	/**
	 * Get currency/country amount limits in minor units.
	 *
	 * @return array<string,array<string,array{min:int,max:int}>>
	 */
	public function get_limits_per_currency(): array {
		$limits = $this->get_array_config( 'limits' );

		/**
		 * Limits config is constructed locally and shaped by tests.
		 *
		 * @var array<string,array<string,array{min:int,max:int}>> $limits
		 */
		return $limits;
	}

	/**
	 * Tell whether the method is available for a merchant country and checkout currency.
	 *
	 * @param string $currency        Checkout currency.
	 * @param string $account_country Merchant account country.
	 * @return bool
	 */
	public function is_available_for( string $currency, string $account_country ): bool {
		$currency        = strtoupper( $currency );
		$account_country = strtoupper( $account_country );

		$supported_currencies = $this->get_supported_currencies( $account_country );
		if ( ! empty( $supported_currencies ) && ! in_array( $currency, $supported_currencies, true ) ) {
			return false;
		}

		$supported_countries = $this->get_supported_countries( $account_country );
		if ( ! empty( $supported_countries ) && ! in_array( $account_country, $supported_countries, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the minimum amount for a currency/country pair.
	 *
	 * @param string $currency Currency code.
	 * @param string $country  Country code.
	 * @return int|null
	 */
	public function get_minimum_amount( string $currency, string $country ): ?int {
		return $this->get_amount_limit( $currency, $country, 'min' );
	}

	/**
	 * Get the maximum amount for a currency/country pair.
	 *
	 * @param string $currency Currency code.
	 * @param string $country  Country code.
	 * @return int|null
	 */
	public function get_maximum_amount( string $currency, string $country ): ?int {
		return $this->get_amount_limit( $currency, $country, 'max' );
	}

	/**
	 * Get a country-specific string value from the definition config.
	 *
	 * @param string      $default_key     Default config key.
	 * @param string      $country_map_key Country map config key.
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	private function get_country_specific_string( string $default_key, string $country_map_key, ?string $account_country = null ): string {
		$account_country = $this->normalize_code( $account_country );
		$country_map     = $this->get_array_config( $country_map_key );

		if ( null !== $account_country && isset( $country_map[ $account_country ] ) && is_string( $country_map[ $account_country ] ) ) {
			return $country_map[ $account_country ];
		}

		return $this->get_config_string( $default_key );
	}

	/**
	 * Get Klarna supported countries for the provided merchant country.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string[]
	 */
	private function get_klarna_supported_countries( ?string $account_country ): array {
		$all_supported_countries = $this->get_string_array_config( 'countries' );

		if ( null === $account_country ) {
			return $all_supported_countries;
		}

		$eea_extended_countries = $this->get_klarna_eea_extended_countries();

		if ( ! in_array( $account_country, $eea_extended_countries, true ) ) {
			if ( in_array( $account_country, $all_supported_countries, true ) ) {
				return array( $account_country );
			}

			return $all_supported_countries;
		}

		$store_currency = strtoupper( get_woocommerce_currency() );
		$limits         = $this->get_limits_per_currency();

		if ( ! isset( $limits[ $store_currency ] ) ) {
			return array( 'NONE_SUPPORTED' );
		}

		return array_values( array_intersect( $eea_extended_countries, array_keys( $limits[ $store_currency ] ) ) );
	}

	/**
	 * Get EEA countries plus Switzerland and the United Kingdom in extension order.
	 *
	 * @return string[]
	 */
	private function get_klarna_eea_extended_countries(): array {
		return array(
			'AT',
			'BE',
			'BG',
			'HR',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GR',
			'HU',
			'IE',
			'IS',
			'IT',
			'LV',
			'LI',
			'LT',
			'LU',
			'MT',
			'NO',
			'NL',
			'PL',
			'PT',
			'RO',
			'SK',
			'SI',
			'ES',
			'SE',
			'CH',
			'GB',
		);
	}

	/**
	 * Get an amount limit from the definition config.
	 *
	 * @param string $currency Currency code.
	 * @param string $country  Country code.
	 * @param string $key      Limit key.
	 * @return int|null
	 */
	private function get_amount_limit( string $currency, string $country, string $key ): ?int {
		$currency = strtoupper( $currency );
		$country  = strtoupper( $country );
		$limits   = $this->get_limits_per_currency();

		return $limits[ $currency ][ $country ][ $key ] ?? null;
	}

	/**
	 * Get the card network from payment details.
	 *
	 * @param array<string,mixed> $details Payment method card details.
	 * @return string
	 */
	private function get_card_network( array $details ): string {
		$network = $details['display_brand'] ?? $details['network'] ?? null;

		if ( ! is_string( $network ) && isset( $details['networks'] ) && is_array( $details['networks'] ) ) {
			$preferred = $details['networks']['preferred'] ?? null;
			$available = $details['networks']['available'] ?? null;

			if ( is_string( $preferred ) ) {
				$network = $preferred;
			} elseif ( is_array( $available ) && isset( $available[0] ) && is_string( $available[0] ) ) {
				$network = $available[0];
			}
		}

		if ( ! is_string( $network ) || '' === $network ) {
			$network = 'card';
		}

		return str_replace( '_', ' ', $network );
	}

	/**
	 * Get a string config value.
	 *
	 * @param string $key      Config key.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_config_string( string $key, string $fallback = '' ): string {
		$value = $this->config[ $key ] ?? $fallback;

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Get an array config value.
	 *
	 * @param string $key Config key.
	 * @return array<mixed>
	 */
	private function get_array_config( string $key ): array {
		$value = $this->config[ $key ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Get a string-list config value.
	 *
	 * @param string $key Config key.
	 * @return string[]
	 */
	private function get_string_array_config( string $key ): array {
		return $this->normalize_code_list( $this->get_array_config( $key ), false );
	}

	/**
	 * Normalize a country or currency code.
	 *
	 * @param string|null $code Code to normalize.
	 * @return string|null
	 */
	private function normalize_code( ?string $code ): ?string {
		if ( null === $code || '' === trim( $code ) ) {
			return null;
		}

		return strtoupper( $code );
	}

	/**
	 * Normalize a list of strings.
	 *
	 * @param array<mixed> $values     Values to normalize.
	 * @param bool         $upper_case Whether to uppercase string values.
	 * @return string[]
	 */
	private function normalize_code_list( array $values, bool $upper_case = true ): array {
		$normalized = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$normalized[] = $upper_case ? strtoupper( $value ) : $value;
		}

		return $normalized;
	}
}
