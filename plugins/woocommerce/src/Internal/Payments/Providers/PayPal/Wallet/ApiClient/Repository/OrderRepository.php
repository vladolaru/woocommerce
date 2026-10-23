<?php
/**
 * PayPal order repository.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository;

use WC_Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;

/**
 * Class OrderRepository
 */
class OrderRepository {

	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint
	 */
	protected $order_endpoint;

	/**
	 * OrderRepository constructor.
	 *
	 * @param OrderEndpoint $order_endpoint The order endpoint.
	 */
	public function __construct( OrderEndpoint $order_endpoint ) {
		$this->order_endpoint = $order_endpoint;
	}

	/**
	 * Gets a PayPal order for the given WooCommerce order.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @return Order The PayPal order.
	 * @throws RuntimeException When there is a problem getting the PayPal order.
	 */
	public function for_wc_order( WC_Order $wc_order ): Order {
		$paypal_order_id = $wc_order->get_meta( PayPalGateway::ORDER_ID_META_KEY );
		if ( ! $paypal_order_id ) {
			throw new RuntimeException( 'PayPal order ID not found in meta.' );
		}

		return $this->order_endpoint->order( $paypal_order_id );
	}
}
