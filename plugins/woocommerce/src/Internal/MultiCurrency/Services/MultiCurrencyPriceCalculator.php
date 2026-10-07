<?php
/**
 * MultiCurrencyPriceCalculator class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;

/**
 * Pure price conversion calculator for the native multi-currency runtime.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyPriceCalculator {

	/**
	 * Relative float noise tolerated when ceiling to a rounding step: about 45 units in the last place, far above the few that a
	 * multiplication and a division add, and far below any excess a stored rate can express.
	 */
	private const STEP_NOISE = 1e-14;

	/**
	 * Localization service.
	 *
	 * @var MultiCurrencyLocalizationInterface
	 */
	private MultiCurrencyLocalizationInterface $localization_service;

	/**
	 * Constructor.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization service.
	 */
	public function __construct( MultiCurrencyLocalizationInterface $localization_service ) {
		$this->localization_service = $localization_service;
	}

	/**
	 * Get the converted price.
	 *
	 * @param mixed                 $price                         Price to convert.
	 * @param string                $type                          Price type.
	 * @param MultiCurrencyCurrency $currency                      Target currency.
	 * @param bool                  $apply_charm_only_to_products Whether charm applies only to product prices.
	 * @return float
	 */
	public function get_price( $price, string $type, MultiCurrencyCurrency $currency, bool $apply_charm_only_to_products = true ): float {
		$supported_types = array( 'product', 'shipping', 'tax', 'coupon', 'exchange_rate' );

		if ( ! in_array( $type, $supported_types, true ) || $currency->get_is_default() ) {
			return (float) $price;
		}

		$converted_price = (float) $price * $currency->get_rate();

		if ( in_array( $type, array( 'tax', 'coupon', 'exchange_rate' ), true ) ) {
			return round( $converted_price, $this->get_currency_decimals( $currency ) );
		}

		$apply_charm_pricing = $apply_charm_only_to_products
			? 'product' === $type
			: in_array( $type, array( 'product', 'shipping' ), true );

		return $this->get_adjusted_price( $converted_price, $apply_charm_pricing, $currency );
	}

	/**
	 * Round an amount already in the given currency and add its charm, as the client's adjusted price.
	 *
	 * @param mixed                 $amount   Amount in the currency.
	 * @param MultiCurrencyCurrency $currency Currency whose rounding and charm apply.
	 * @return float
	 */
	public function get_adjusted_amount( $amount, MultiCurrencyCurrency $currency ): float {
		return $this->get_adjusted_price( (float) $amount, true, $currency );
	}

	/**
	 * Convert an amount between enabled currencies.
	 *
	 * @param float                               $amount             Amount to convert.
	 * @param string                              $to_currency        Target currency code.
	 * @param string                              $from_currency      Source currency code.
	 * @param array<string,MultiCurrencyCurrency> $enabled_currencies Enabled currencies keyed by code.
	 * @return float
	 *
	 * @throws InvalidCurrencyException When either currency is not enabled.
	 * @throws InvalidCurrencyRateException When the source currency rate is invalid.
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency, array $enabled_currencies ): float {
		$to_currency   = strtoupper( $to_currency );
		$from_currency = strtoupper( $from_currency );

		foreach ( array( $to_currency, $from_currency ) as $code ) {
			if ( ! isset( $enabled_currencies[ $code ] ) ) {
				throw new InvalidCurrencyException( esc_html( 'Currency is not enabled for conversion: ' . $code ) );
			}
		}

		$to_currency_rate   = $enabled_currencies[ $to_currency ]->get_rate();
		$from_currency_rate = $enabled_currencies[ $from_currency ]->get_rate();

		if ( 0 >= $from_currency_rate ) {
			throw new InvalidCurrencyRateException( esc_html( 'Invalid source currency rate: ' . $from_currency_rate ) );
		}

		return $amount * ( $to_currency_rate / $from_currency_rate );
	}

	/**
	 * Apply rounding and charm pricing.
	 *
	 * @param float                 $price               Converted price.
	 * @param bool                  $apply_charm_pricing Whether charm applies.
	 * @param MultiCurrencyCurrency $currency            Target currency.
	 * @return float
	 */
	private function get_adjusted_price( float $price, bool $apply_charm_pricing, MultiCurrencyCurrency $currency ): float {
		$rounding = (float) $currency->get_rounding();

		if ( 0.0 === $rounding ) {
			$price = round( $price, $this->get_currency_decimals( $currency ) );
		} else {
			$price = $this->ceil_price( $price, $rounding );
		}

		if ( $apply_charm_pricing ) {
			$price += $currency->get_charm();
		}

		return max( 0, $price );
	}

	/**
	 * Ceil a price to the next rounding step.
	 *
	 * @param float $price    Price.
	 * @param float $rounding Rounding step.
	 * @return float
	 */
	private function ceil_price( float $price, float $rounding ): float {
		if ( 0.0 === $rounding ) {
			return $price;
		}

		// The async renderer ceils on exact decimals; floats add a few units of the last place, so 3.00 at a 0.1 rate gives
		// 0.30000000000000004 / 0.1 = 3.0000000000000004 and a plain ceil charges 0.40 where 0.30 was shown (the client's float ceil has
		// the same split; includes/multi-currency/MultiCurrency.php:1695-1700). Treat a quotient within float noise of a whole step as
		// that step; any larger excess, however small, still takes the next step, as on the renderer.
		$quotient = $price / $rounding;
		$nearest  = round( $quotient );
		if ( abs( $quotient - $nearest ) <= max( 1.0, abs( $quotient ) ) * self::STEP_NOISE ) {
			$quotient = $nearest;
		}

		return ceil( $quotient ) * $rounding;
	}

	/**
	 * Get decimals for a currency.
	 *
	 * @param MultiCurrencyCurrency $currency Currency.
	 * @return int
	 */
	private function get_currency_decimals( MultiCurrencyCurrency $currency ): int {
		return absint( $this->localization_service->get_currency_format( $currency->get_code() )['num_decimals'] );
	}
}
