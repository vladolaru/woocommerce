<?php
/**
 * WooPaymentsLocaleUtils class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * WooPayments locale helpers for provider-boundary locale handling.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsLocaleUtils {

	/**
	 * Convert a WordPress locale to the closest Stripe.js-supported locale.
	 *
	 * Stripe.js supports only a subset of IETF language tags; when a country-specific
	 * locale is not supported the base language is used, and when nothing matches
	 * 'auto' is returned so Stripe.js falls back to the browser locale. Mirrors the
	 * reference client's convert_to_stripe_locale(), list copied from
	 * https://stripe.com/docs/js/appendix/supported_locales.
	 *
	 * @param string $locale WordPress locale.
	 * @return string Closest Stripe-supported locale, or 'auto' when none matches.
	 */
	public static function convert_to_stripe_locale( string $locale ): string {
		$supported_locales = array(
			'ar',
			'bg',
			'cs',
			'da',
			'de',
			'el',
			'en',
			'en-GB',
			'es',
			'es-419',
			'et',
			'fi',
			'fil',
			'fr',
			'fr-CA',
			'he',
			'hr',
			'hu',
			'id',
			'it',
			'ja',
			'ko',
			'lt',
			'lv',
			'ms',
			'mt',
			'nb',
			'nl',
			'pl',
			'pt',
			'pt-BR',
			'ro',
			'ru',
			'sk',
			'sl',
			'sv',
			'th',
			'tr',
			'vi',
			'zh',
			'zh-HK',
			'zh-TW',
		);

		$locale = str_replace( '_', '-', $locale );
		if ( in_array( $locale, $supported_locales, true ) ) {
			return $locale;
		}

		$language = strtok( $locale, '-' );

		return is_string( $language ) && in_array( $language, $supported_locales, true ) ? $language : 'auto';
	}
}
