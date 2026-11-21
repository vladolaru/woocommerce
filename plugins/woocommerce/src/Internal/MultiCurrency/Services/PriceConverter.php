<?php
/**
 * PriceConverter class file.
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
class PriceConverter {

	public const META_KEY_ORDER_EXCHANGE_RATE    = '_wcpay_multi_currency_order_exchange_rate';
	public const META_KEY_ORDER_DEFAULT_CURRENCY = '_wcpay_multi_currency_order_default_currency';
	public const META_KEY_STRIPE_EXCHANGE_RATE   = '_wcpay_multi_currency_stripe_exchange_rate';

	/**
	 * State builder.
	 *
	 * @var StateBuilder
	 */
	private StateBuilder $state_builder;

	/**
	 * Price calculator.
	 *
	 * @var PriceCalculator
	 */
	private PriceCalculator $price_calculator;

	/**
	 * Constructor.
	 *
	 * @param StateBuilder    $state_builder     State builder.
	 * @param PriceCalculator $price_calculator Price calculator.
	 */
	public function __construct( StateBuilder $state_builder, PriceCalculator $price_calculator ) {
		$this->state_builder    = $state_builder;
		$this->price_calculator = $price_calculator;
	}

	/**
	 * Convert a price to the selected currency, or to the given one (client 11.3.0 `MultiCurrency::get_price()`,
	 * `includes/multi-currency/MultiCurrency.php:1215-1251`).
	 *
	 * @param mixed                      $price    Price.
	 * @param string                     $type     Price type.
	 * @param MultiCurrencyCurrency|null $currency Currency to convert to; the selected one when null.
	 * @return float
	 */
	public function get_price( $price, string $type, ?MultiCurrencyCurrency $currency = null ): float {
		$currency                     = $currency ?? $this->state_builder->build()->get_selected_currency();
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
			$apply_charm_only_to_products = (bool) apply_filters( DisplayService::APPLY_CHARM_PRODUCTS_HOOK, true );
		}

		return $this->price_calculator->get_price( $price, $type, $currency, $apply_charm_only_to_products );
	}

	/**
	 * Get the currency an existing order's amounts convert to: the enabled one, which carries the merchant's manual rate as
	 * at checkout, else the available one, which has only the automatic rate (a currency disabled since checkout), else the
	 * store currency (client 11.3.0 `includes/multi-currency/FrontendPrices.php:540-545`).
	 *
	 * @param string $order_currency Order currency code.
	 * @return MultiCurrencyCurrency
	 */
	public function get_currency_for_order( string $order_currency ): MultiCurrencyCurrency {
		$state          = $this->state_builder->build();
		$order_currency = strtoupper( $order_currency );

		return $state->get_enabled_currencies()[ $order_currency ]
			?? $state->get_available_currencies()[ $order_currency ]
			?? $state->get_default_currency();
	}

	/**
	 * Round an amount already in the selected currency and add its charm unless asked not to (client 11.1.0
	 * `MultiCurrency::adjust_amount_for_selected_currency()`).
	 *
	 * @param mixed $amount              Amount in the selected currency.
	 * @param bool  $apply_charm_pricing Whether the selected currency's charm is added.
	 * @return float
	 */
	public function adjust_amount_for_selected_currency( $amount, bool $apply_charm_pricing = true ): float {
		return $this->price_calculator->get_adjusted_amount( $amount, $this->state_builder->build()->get_selected_currency(), $apply_charm_pricing );
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
	 * Get the marker a shipping package carries for the selected currency, or null when the store currency is selected.
	 *
	 * The code, rate, rounding step, charm and whether charm applies to products only, joined with colons from the currency's
	 * typed getters, byte for byte as client 11.3.0 `FrontendPrices::add_currency_to_shipping_packages()` builds it
	 * (`includes/multi-currency/FrontendPrices.php:596-627`), so each runtime reuses rates the other cached.
	 *
	 * @return string|null
	 */
	public function get_selected_currency_shipping_marker(): ?string {
		$state    = $this->state_builder->build();
		$currency = $state->get_selected_currency();
		if ( $currency->get_code() === $state->get_default_currency()->get_code() ) {
			return null;
		}

		/**
		 * Filters whether charm pricing applies only to product prices; shipping gets it too when false.
		 *
		 * @param bool $apply_charm_only_to_products Whether charm pricing is product-only.
		 *
		 * @since 11.0.0
		 */
		$apply_charm_only_to_products = (bool) apply_filters( DisplayService::APPLY_CHARM_PRODUCTS_HOOK, true );

		return implode(
			':',
			array(
				$currency->get_code(),
				$currency->get_rate(),
				$currency->get_rounding(),
				$currency->get_charm(),
				$apply_charm_only_to_products ? '1' : '0',
			)
		);
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
	 * The rate is the selected currency's, or the order currency's own when it is enabled but not selected, as on a REST
	 * order; it is stored as it is, unrounded: Analytics divides the order's amounts by it. An order in a third-party
	 * switcher's currency gets none, since Multi-Currency did not convert it (client 11.2.0 `FrontendPrices.php:363-389`).
	 *
	 * @param string $order_currency Order currency code.
	 * @return array<string,string>
	 */
	public function get_order_meta_candidates( string $order_currency ): array {
		$state            = $this->state_builder->build();
		$default_currency = $state->get_default_currency();
		$order_currency   = strtoupper( $order_currency );

		if ( $default_currency->get_code() === $order_currency ) {
			return array();
		}

		$currency = $state->get_selected_currency();
		if ( $currency->get_code() !== $order_currency ) {
			if ( strtoupper( get_woocommerce_currency() ) === $order_currency ) {
				return array();
			}

			$currency = $state->get_enabled_currencies()[ $order_currency ] ?? $currency;
		}

		return array(
			self::META_KEY_ORDER_EXCHANGE_RATE    => $currency->get_rate_decimal(),
			self::META_KEY_ORDER_DEFAULT_CURRENCY => $default_currency->get_code(),
		);
	}

	/**
	 * Get the exchange-rate meta a refund copies from its order: none when the order has no order rate, since Multi-Currency
	 * did not convert it (client 11.2.0 `includes/multi-currency/MultiCurrency.php:1139-1146`).
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

		$order_exchange_rate = $order->get_meta( self::META_KEY_ORDER_EXCHANGE_RATE, true );
		if ( ! $order_exchange_rate ) {
			return array();
		}

		$meta = array(
			self::META_KEY_ORDER_EXCHANGE_RATE    => $order_exchange_rate,
			self::META_KEY_ORDER_DEFAULT_CURRENCY => $order->get_meta( self::META_KEY_ORDER_DEFAULT_CURRENCY, true ),
		);

		$stripe_exchange_rate = $order->get_meta( self::META_KEY_STRIPE_EXCHANGE_RATE, true );
		if ( $stripe_exchange_rate ) {
			$meta[ self::META_KEY_STRIPE_EXCHANGE_RATE ] = $stripe_exchange_rate;
		}

		return $meta;
	}
}
