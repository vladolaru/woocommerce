<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity;

/**
 * Money value for the Store API.
 */
class Money {
	/**
	 * The amount as a string of minor units.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * The currency code.
	 *
	 * @var string
	 */
	private string $currency_code;

	/**
	 * The currency minor unit.
	 *
	 * @var int
	 */
	private int $currency_minor_unit;

	/**
	 * Money constructor.
	 *
	 * @param string $value The amount as a string of minor units.
	 * @param string $currency_code The currency code.
	 * @param int    $currency_minor_unit The number of decimals of the currency.
	 */
	public function __construct( string $value, string $currency_code, int $currency_minor_unit ) {
		$this->value               = $value;
		$this->currency_code       = $currency_code;
		$this->currency_minor_unit = $currency_minor_unit;
	}

	/**
	 * Returns the value.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Returns the currency code.
	 */
	public function currency_code(): string {
		return $this->currency_code;
	}

	/**
	 * The number of digits after ".". For most currencies it is 2.
	 */
	public function currency_minor_unit(): int {
		return $this->currency_minor_unit;
	}

	/**
	 * Converts to float, e.g. value=123, currency_minor_unit=2 --> 1.23.
	 */
	public function to_float(): float {
		return round( ( (int) $this->value ) / 10 ** $this->currency_minor_unit, $this->currency_minor_unit );
	}

	/**
	 * Returns the Money object for the PayPal API.
	 */
	public function to_paypal(): \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money {
		return new \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money(
			$this->to_float(),
			$this->currency_code
		);
	}
}
