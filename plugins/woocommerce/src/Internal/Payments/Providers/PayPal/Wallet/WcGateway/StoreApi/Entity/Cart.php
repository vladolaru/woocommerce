<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\ShippingRate;

/**
 * Cart object for the Store API.
 */
class Cart {
	/**
	 * The totals.
	 *
	 * @var CartTotals
	 */
	private CartTotals $totals;

	/**
	 * The shipping rates of the cart.
	 *
	 * @var ShippingRate[]
	 */
	private array $shipping_rates;

	/**
	 * Cart constructor.
	 *
	 * @param CartTotals     $totals The cart totals.
	 * @param ShippingRate[] $shipping_rates The shipping rates.
	 */
	public function __construct( CartTotals $totals, array $shipping_rates ) {
		$this->totals         = $totals;
		$this->shipping_rates = $shipping_rates;
	}

	/**
	 * Returns the totals.
	 */
	public function totals(): CartTotals {
		return $this->totals;
	}

	/**
	 * Returns the shipping rates.
	 */
	public function shipping_rates(): array {
		return $this->shipping_rates;
	}
}
