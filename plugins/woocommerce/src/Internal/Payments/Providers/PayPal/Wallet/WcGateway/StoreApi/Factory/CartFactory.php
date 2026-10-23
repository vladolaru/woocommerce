<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\Cart;

/**
 * Factory for the Store API cart.
 */
class CartFactory {
	/**
	 * The cart totals factory.
	 *
	 * @var CartTotalsFactory
	 */
	private CartTotalsFactory $cart_totals_factory;

	/**
	 * The shipping rates factory.
	 *
	 * @var ShippingRatesFactory
	 */
	private ShippingRatesFactory $shipping_rates_factory;

	/**
	 * CartFactory constructor.
	 *
	 * @param CartTotalsFactory    $cart_totals_factory   The cart totals factory.
	 * @param ShippingRatesFactory $shipping_rate_factory The shipping rate factory.
	 */
	public function __construct(
		CartTotalsFactory $cart_totals_factory,
		ShippingRatesFactory $shipping_rate_factory
	) {
		$this->cart_totals_factory    = $cart_totals_factory;
		$this->shipping_rates_factory = $shipping_rate_factory;
	}

	/**
	 * Creates the cart from the Store API response.
	 *
	 * @param array $obj The obj.
	 */
	public function from_response( array $obj ): Cart {
		return new Cart(
			$this->cart_totals_factory->from_response_obj( (array) ( $obj['totals'] ?? array() ) ),
			$this->shipping_rates_factory->from_response_obj( (array) ( $obj['shipping_rates'] ?? array() ) )
		);
	}
}
