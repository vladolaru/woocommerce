<?php
/**
 * GeolocationNotice class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Builds the same currency notice and dismissal ID for server and async rendering.
 *
 * @since 11.4.0
 * @internal
 */
final class GeolocationNotice {

	public const FILTER_OVERRIDE_NOTICE_COUNTRY       = 'wcpay_multi_currency_override_notice_country';
	public const FILTER_OVERRIDE_NOTICE_CURRENCY_NAME = 'wcpay_multi_currency_override_notice_currency_name';

	/**
	 * Get the notice for a matching non-store currency, or for the settings preview.
	 *
	 * @param GeolocationService $geolocation           Customer location.
	 * @param string             $current_currency_code Selected currency.
	 * @param string             $store_currency_code   Store currency.
	 * @param bool               $preview               Whether the settings preview forces the notice.
	 * @return array{message:string,id:string,country_name:string,currency_name:string}|null
	 */
	public static function get_notice( GeolocationService $geolocation, string $current_currency_code, string $store_currency_code, bool $preview = false ): ?array {
		if ( ! $preview && ( $store_currency_code === $current_currency_code || strtoupper( (string) $geolocation->get_currency_by_customer_location() ) !== $current_currency_code ) ) {
			return null;
		}

		$country_code  = $geolocation->get_country_by_customer_location();
		$countries     = WC()->countries->get_countries();
		$currencies    = get_woocommerce_currencies();
		$country_name  = $countries[ $country_code ] ?? $country_code;
		$currency_name = $currencies[ $current_currency_code ] ?? $current_currency_code;

		/**
		 * Filters the country name shown in the geolocation currency notice.
		 *
		 * @param string $country_name Country name.
		 *
		 * @since 11.0.0
		 */
		$country_name = apply_filters( self::FILTER_OVERRIDE_NOTICE_COUNTRY, $country_name );

		/**
		 * Filters the currency name shown in the geolocation currency notice.
		 *
		 * @param string $currency_name Current currency name.
		 *
		 * @since 11.0.0
		 */
		$currency_name = apply_filters( self::FILTER_OVERRIDE_NOTICE_CURRENCY_NAME, $currency_name );

		$country_name  = is_scalar( $country_name ) ? (string) $country_name : '';
		$currency_name = is_scalar( $currency_name ) ? (string) $currency_name : '';
		$message       = self::get_message( esc_html( $country_name ), esc_html( $currency_name ), $store_currency_code );

		return array(
			'message'       => $message,
			'id'            => md5( $message ) . '2',
			'country_name'  => html_entity_decode( $country_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
			'currency_name' => html_entity_decode( $currency_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
		);
	}

	/**
	 * Format the sentence with escaped names or the cached page's empty slots.
	 *
	 * @param string $country_name        Country text or template slot.
	 * @param string $currency_name       Currency text or template slot.
	 * @param string $store_currency_code Store currency.
	 * @return string
	 */
	public static function get_message( string $country_name, string $currency_name, string $store_currency_code ): string {
		$currencies          = get_woocommerce_currencies();
		$store_currency_name = $currencies[ $store_currency_code ] ?? $store_currency_code;

		return sprintf(
			/* translators: %1$s: User country. %2$s: Selected currency name. %3$s: Store currency name. %4$s: Link to switch currency. */
			__( 'We noticed you\'re visiting from %1$s. We\'ve updated our prices to %2$s for your shopping convenience. <a href="%4$s">Use %3$s instead.</a>', 'woocommerce' ),
			$country_name,
			$currency_name,
			esc_html( $store_currency_name ),
			esc_url( '?currency=' . $store_currency_code )
		);
	}
}
