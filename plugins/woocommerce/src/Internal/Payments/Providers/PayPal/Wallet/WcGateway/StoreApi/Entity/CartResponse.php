<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity;

/**
 * CartResponse for the Store API.
 */
class CartResponse {
	/**
	 * The cart.
	 *
	 * @var Cart
	 */
	private Cart $cart;

	/**
	 * The cart token.
	 *
	 * @var string
	 */
	private string $cart_token;

	/**
	 * CartResponse constructor.
	 *
	 * @param Cart   $cart       The cart.
	 * @param string $cart_token The cart token.
	 */
	public function __construct( Cart $cart, string $cart_token ) {
		$this->cart       = $cart;
		$this->cart_token = $cart_token;
	}

	/**
	 * Returns the cart.
	 */
	public function cart(): Cart {
		return $this->cart;
	}

	/**
	 * The token required for the API requests (except Get Cart).
	 */
	public function cart_token(): string {
		return $this->cart_token;
	}
}
