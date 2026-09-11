<?php
/**
 * MultiCurrencyExplicitPriceProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Projects explicit currency suffixes for multi-currency price displays.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyExplicitPriceProjectionService {

	/**
	 * Project the explicit price hook/filter manifest.
	 *
	 * @return array{filters: array<int,array<string,mixed>>, actions: array<int,array<string,mixed>>}
	 *
	 * @since 11.0.0
	 */
	public static function get_hook_manifest(): array {
		return array(
			'filters' => array(
				array(
					'hook'          => 'woocommerce_cart_total',
					'callback'      => 'get_explicit_price',
					'priority'      => 100,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'woocommerce_get_formatted_order_total',
					'callback'      => 'get_explicit_price',
					'priority'      => 100,
					'accepted_args' => 2,
				),
			),
			'actions' => array(
				array(
					'hook'          => 'woocommerce_admin_order_totals_after_tax',
					'callback'      => 'register_formatted_woocommerce_price_filter',
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'woocommerce_admin_order_totals_after_total',
					'callback'      => 'unregister_formatted_woocommerce_price_filter',
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
		);
	}

	/**
	 * Project an explicit price string using either order or store currency.
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
	 * Project a formatted price string with currency suffix when needed.
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
	 * Project wc_price() args that render an explicit currency code.
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
