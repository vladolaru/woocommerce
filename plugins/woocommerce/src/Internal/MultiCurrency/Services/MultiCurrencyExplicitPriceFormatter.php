<?php
/**
 * MultiCurrencyExplicitPriceFormatter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;

/**
 * Appends the currency code to a formatted price where more than one currency is in use.
 *
 * Its methods are static, and WooPayments admin amounts call them too; should_output_explicit_admin_price() reads the
 * Multi-Currency state through the container.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyExplicitPriceFormatter {

	/**
	 * Determine whether formatted prices should include an explicit currency code.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $default_should_output Whether explicit currency output is enabled by default.
	 * @return bool Whether explicit currency output should be enabled.
	 */
	public static function should_output_explicit_price( bool $default_should_output ): bool {
		/**
		 * Filters whether formatted prices include an explicit currency code.
		 *
		 * @since 11.2.0
		 *
		 * @param bool $default Whether explicit currency output is enabled by default.
		 */
		return (bool) apply_filters( 'wcpay_multi_currency_should_output_explicit_price', $default_should_output );
	}

	/**
	 * Tell whether admin amounts should include an explicit currency code: WooCommerce runs Multi-Currency and more than
	 * one currency is enabled (client 11.1.0 `WC_Payments_Explicit_Price_Formatter::is_explicit_price_required()`).
	 *
	 * The enabled-currencies option is checked first, so the Multi-Currency state is only built when an additional
	 * currency is configured. The filter always runs.
	 *
	 * @since 11.2.0
	 *
	 * @return bool Whether admin amounts should include an explicit currency code.
	 */
	public static function should_output_explicit_admin_price(): bool {
		$container = wc_get_container();
		$default   = false;

		if (
			$container->get( MultiCurrencyRuntimeArbiter::class )->is_builtin_owner()
			&& $container->get( MultiCurrencyUsageDetector::class )->has_configured_additional_currencies()
		) {
			$default = $container->get( MultiCurrencyStateBuilderFactory::class )->get_state_builder()->build()->has_additional_currencies_enabled();
		}

		return self::should_output_explicit_price( $default );
	}

	/**
	 * Append the order's currency, or the store currency without an order, to a formatted price when explicit output is active.
	 *
	 * @param string                  $price                         Formatted price.
	 * @param \WC_Abstract_Order|null $order                         Order object, when available.
	 * @param bool                    $should_output_explicit_price Whether explicit price output is active.
	 * @param string                  $store_currency                Store currency code.
	 * @return string
	 */
	public static function get_explicit_price( string $price, ?\WC_Abstract_Order $order, bool $should_output_explicit_price, string $store_currency ): string {
		$currency_code = null === $order ? $store_currency : $order->get_currency();

		return self::get_explicit_price_with_currency( $price, $currency_code, $should_output_explicit_price );
	}

	/**
	 * Append a currency code to a formatted price when explicit output is active and the price does not show the code yet.
	 *
	 * @param string      $price                         Formatted price.
	 * @param string|null $currency_code                 Currency code.
	 * @param bool        $should_output_explicit_price Whether explicit price output is active.
	 * @return string
	 */
	public static function get_explicit_price_with_currency( string $price, ?string $currency_code, bool $should_output_explicit_price ): string {
		if ( ! $should_output_explicit_price || empty( $currency_code ) ) {
			return $price;
		}

		$currency_code  = trim( $currency_code );
		$price_to_check = html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		return false === strpos( $price_to_check, $currency_code )
			? $price . ' ' . $currency_code
			: $price;
	}

	/**
	 * Add the currency code to the wc_price() price format when explicit output is active.
	 *
	 * @param array<string,mixed> $args                         Price formatting args.
	 * @param bool                $should_output_explicit_price Whether explicit price output is active.
	 * @return array<string,mixed>
	 */
	public static function get_explicit_price_args( array $args, bool $should_output_explicit_price ): array {
		if (
			! $should_output_explicit_price
			|| ! is_scalar( $args['price_format'] ?? null )
			|| ! is_scalar( $args['currency'] ?? null )
		) {
			return $args;
		}

		$price_format = (string) $args['price_format'];
		$currency     = (string) $args['currency'];
		if ( false === strpos( $price_format, $currency ) ) {
			$args['price_format'] = sprintf( '%s&nbsp;%s', $price_format, $currency );
		}

		return $args;
	}
}
