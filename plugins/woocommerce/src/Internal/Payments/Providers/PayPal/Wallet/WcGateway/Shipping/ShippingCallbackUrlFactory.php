<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Shipping;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\ShippingCallbackEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Endpoint\CartEndpoint;

/**
 * URL generation for the server-side shipping callback.
 */
class ShippingCallbackUrlFactory {
	/**
	 * The cart endpoint.
	 *
	 * @var CartEndpoint
	 */
	private CartEndpoint $cart_endpoint;

	/**
	 * The shipping callback endpoint.
	 *
	 * @var ShippingCallbackEndpoint
	 */
	private ShippingCallbackEndpoint $shipping_callback_endpoint;

	/**
	 * ShippingCallbackUrlFactory constructor.
	 *
	 * @param CartEndpoint             $cart_endpoint              The cart endpoint.
	 * @param ShippingCallbackEndpoint $shipping_callback_endpoint The shipping callback endpoint.
	 */
	public function __construct( CartEndpoint $cart_endpoint, ShippingCallbackEndpoint $shipping_callback_endpoint ) {
		$this->cart_endpoint              = $cart_endpoint;
		$this->shipping_callback_endpoint = $shipping_callback_endpoint;
	}

	/**
	 * Creates the callback URL.
	 */
	public function create(): string {
		$cart_response = $this->cart_endpoint->get_cart();

		$url = $this->shipping_callback_endpoint->url();
		$url = add_query_arg( 'cart_token', $cart_response->cart_token(), $url );

		return $url;
	}
}
