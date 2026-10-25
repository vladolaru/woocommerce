<?php
/**
 * MultiCurrencyPriceConverter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;

/**
 * Converts prices and amounts between the store currency and the selected currency, and gives the exchange-rate meta
 * an order or a refund records.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyPriceConverter {

	public const META_KEY_ORDER_EXCHANGE_RATE    = '_wcpay_multi_currency_order_exchange_rate';
	public const META_KEY_ORDER_DEFAULT_CURRENCY = '_wcpay_multi_currency_order_default_currency';
	public const META_KEY_STRIPE_EXCHANGE_RATE   = '_wcpay_multi_currency_stripe_exchange_rate';

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder
	 */
	private MultiCurrencyStateBuilder $state_builder;

	/**
	 * Price calculator.
	 *
	 * @var MultiCurrencyPriceCalculator
	 */
	private MultiCurrencyPriceCalculator $price_calculator;

	/**
	 * Constructor.
	 *
	 * @param MultiCurrencyStateBuilder    $state_builder     State builder.
	 * @param MultiCurrencyPriceCalculator $price_calculator Price calculator.
	 */
	public function __construct( MultiCurrencyStateBuilder $state_builder, MultiCurrencyPriceCalculator $price_calculator ) {
		$this->state_builder    = $state_builder;
		$this->price_calculator = $price_calculator;
	}

	/**
	 * Convert a price to the selected currency.
	 *
	 * @param mixed  $price Price.
	 * @param string $type  Price type.
	 * @return float
	 */
	public function get_price( $price, string $type ): float {
		$currency                     = $this->state_builder->build()->get_selected_currency();
		$apply_charm_only_to_products = true;

		// The client asks only when a product or shipping price is converted (client 11.1.0 `includes/multi-currency/MultiCurrency.php:943-967`).
		if ( ! $currency->get_is_default() && in_array( $type, array( 'product', 'shipping' ), true ) ) {
			/**
			 * Filters whether charm pricing applies only to product prices; shipping gets it too when false.
			 *
			 * @param bool $apply_charm_only_to_products Whether charm pricing is product-only.
			 *
			 * @since 11.0.0
			 */
			$apply_charm_only_to_products = (bool) apply_filters( MultiCurrencyDisplayService::APPLY_CHARM_PRODUCTS_HOOK, true );
		}

		return $this->price_calculator->get_price( $price, $type, $currency, $apply_charm_only_to_products );
	}

	/**
	 * Round an amount already in the selected currency and add its charm (client 11.1.0 `MultiCurrency::adjust_amount_for_selected_currency()`).
	 *
	 * @param mixed $amount Amount in the selected currency.
	 * @return float
	 */
	public function adjust_amount_for_selected_currency( $amount ): float {
		return $this->price_calculator->get_adjusted_amount( $amount, $this->state_builder->build()->get_selected_currency() );
	}

	/**
	 * Convert an amount between enabled currencies, or from or to the selected one, without rounding or charm.
	 *
	 * @param float  $amount        Amount.
	 * @param string $to_currency   Target currency code.
	 * @param string $from_currency Source currency code.
	 * @return float
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency ): float {
		return $this->price_calculator->get_raw_conversion(
			$amount,
			$to_currency,
			$from_currency,
			$this->get_conversion_currencies( $this->state_builder->build() )
		);
	}

	/**
	 * Get what converting a product price to the selected currency depends on: its code, rate, rounding step and charm.
	 *
	 * @return array{0:string,1:string,2:string,3:float}
	 */
	public function get_selected_currency_conversion_key(): array {
		$currency = $this->state_builder->build()->get_selected_currency();

		return array( $currency->get_code(), $currency->get_rate_decimal(), $currency->get_rounding_decimal(), $currency->get_charm() );
	}

	/**
	 * Convert a selected-currency amount back to the store currency at the raw rate, with no rounding.
	 *
	 * @param float $amount Amount in the selected currency.
	 * @return float
	 */
	public function get_default_currency_amount( float $amount ): float {
		$state = $this->state_builder->build();

		if ( $state->get_selected_currency()->get_is_default() ) {
			return $amount;
		}

		return $this->price_calculator->get_raw_conversion(
			$amount,
			$state->get_default_currency()->get_code(),
			$state->get_selected_currency()->get_code(),
			$this->get_conversion_currencies( $state )
		);
	}

	/**
	 * Tell whether selected and default currencies differ.
	 *
	 * @return bool
	 */
	public function should_convert_between_selected_and_default_currency(): bool {
		$state = $this->state_builder->build();

		return $state->get_default_currency()->get_code() !== $state->get_selected_currency()->get_code();
	}

	/**
	 * Convert a price-filter bound from the selected currency to the store currency, rounded so the filter does not narrow.
	 *
	 * @param mixed  $amount  Price-filter amount.
	 * @param string $compare Price-filter comparison operator.
	 * @return string
	 */
	public function get_price_filter_query_value( $amount, string $compare ): string {
		$converted_amount  = $this->get_default_currency_amount( (float) $amount );
		$precision_epsilon = 0.000000001;

		return '<=' === $compare
			? (string) ceil( $converted_amount - $precision_epsilon )
			: (string) floor( $converted_amount + $precision_epsilon );
	}

	/**
	 * Get the currencies a raw conversion may use: the enabled ones and the selected one.
	 *
	 * A subscription cart keeps its subscription's currency after the merchant disabled it, so the selected currency can be
	 * missing from the enabled list.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return array<string,MultiCurrencyCurrency>
	 */
	private function get_conversion_currencies( MultiCurrencyState $state ): array {
		$selected = $state->get_selected_currency();

		return $state->get_enabled_currencies() + array( $selected->get_code() => $selected );
	}

	/**
	 * Get the exchange-rate meta to save on an order in a currency other than the store currency.
	 *
	 * The selected currency's rate is stored as it is, unrounded: Analytics divides the order's amounts by it.
	 *
	 * @param string $order_currency Order currency code.
	 * @return array<string,string>
	 */
	public function get_order_meta_candidates( string $order_currency ): array {
		$state            = $this->state_builder->build();
		$default_currency = $state->get_default_currency();

		if ( $default_currency->get_code() === strtoupper( $order_currency ) ) {
			return array();
		}

		return array(
			self::META_KEY_ORDER_EXCHANGE_RATE    => $state->get_selected_currency()->get_rate_decimal(),
			self::META_KEY_ORDER_DEFAULT_CURRENCY => $default_currency->get_code(),
		);
	}

	/**
	 * Get the exchange-rate meta a refund copies from its order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public function get_refund_meta_candidates( \WC_Order $order ): array {
		$state            = $this->state_builder->build();
		$default_currency = $state->get_default_currency();

		if ( $default_currency->get_code() === strtoupper( $order->get_currency() ) ) {
			return array();
		}

		$meta = array(
			self::META_KEY_ORDER_EXCHANGE_RATE    => $order->get_meta( self::META_KEY_ORDER_EXCHANGE_RATE, true ),
			self::META_KEY_ORDER_DEFAULT_CURRENCY => $order->get_meta( self::META_KEY_ORDER_DEFAULT_CURRENCY, true ),
		);

		$stripe_exchange_rate = $order->get_meta( self::META_KEY_STRIPE_EXCHANGE_RATE, true );
		if ( $stripe_exchange_rate ) {
			$meta[ self::META_KEY_STRIPE_EXCHANGE_RATE ] = $stripe_exchange_rate;
		}

		return $meta;
	}
}
