<?php
/**
 * The endpoint to get a PayPal order.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;

/**
 * Class GetOrderEndpoint
 */
class GetOrderEndpoint implements EndpointInterface {

	public const ENDPOINT = 'ppc-get-order';

	/**
	 * The request data.
	 *
	 * @var RequestData
	 */
	private RequestData $request_data;

	/**
	 * The PayPal order API endpoint.
	 *
	 * @var OrderEndpoint
	 */
	private OrderEndpoint $api_endpoint;
	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;
	/**
	 * The cart data storage.
	 *
	 * @var CartDataTransientStorage
	 */
	private CartDataTransientStorage $cart_data_storage;

	/**
	 * GetOrderEndpoint constructor.
	 *
	 * @param RequestData              $request_data      The request data.
	 * @param OrderEndpoint            $order_endpoint    The order endpoint.
	 * @param LoggerInterface          $logger            The logger.
	 * @param CartDataTransientStorage $cart_data_storage The cart data storage.
	 */
	public function __construct(
		RequestData $request_data,
		OrderEndpoint $order_endpoint,
		LoggerInterface $logger,
		CartDataTransientStorage $cart_data_storage
	) {
		$this->request_data      = $request_data;
		$this->api_endpoint      = $order_endpoint;
		$this->logger            = $logger;
		$this->cart_data_storage = $cart_data_storage;
	}

	/**
	 * Returns the nonce action of the endpoint.
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}
	/**
	 * Handles the request.
	 */
	public function handle_request(): void {
		try {
			$data     = $this->request_data->read_request( $this->nonce() );
			$order_id = $data['order_id'] ?? '';

			if ( empty( $order_id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Order ID is required', 'woocommerce' ),
					)
				);
			}

			$cart_data = $this->cart_data_storage->get_by_paypal_order_id( $order_id );

			if ( ! $cart_data ) {
				$this->logger->warning(
					sprintf(
						'Unauthorized GetOrder attempt for PayPal order %s. No CartData found.',
						$order_id
					)
				);

				wp_send_json_error(
					array(
						'message' => __( 'Invalid or expired order access', 'woocommerce' ),
					)
				);
			}

			$stored_user_id  = $cart_data->user_id();
			$current_user_id = get_current_user_id();

			$authorized = false;
			if ( 0 !== $stored_user_id && $current_user_id === $stored_user_id ) {
				$authorized = true;
			} elseif ( 0 === $stored_user_id && 0 === $current_user_id ) {
				$stored_session  = $cart_data->session_customer_id();
				$current_session = WC()->session ? (string) WC()->session->get_customer_id() : null;
				if ( null !== $stored_session && $current_session === $stored_session ) {
					$authorized = true;
				}
			}

			if ( ! $authorized ) {
				$this->logger->warning(
					sprintf(
						'Unauthorized GetOrder attempt for PayPal order %s. Session mismatch.',
						$order_id
					)
				);

				wp_send_json_error(
					array(
						'message' => __( 'Invalid or expired order access', 'woocommerce' ),
					)
				);
			}

			$order = $this->api_endpoint->order( $order_id );

			wp_send_json_success( $order->to_array() );
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( RuntimeException $error ) {
			$this->logger->error( 'Get order failed: ' . $error->getMessage() );

			wp_send_json_error(
				array(
					'name'    => $error instanceof PayPalApiException ? $error->name() : '',
					'message' => $error->getMessage(),
					'code'    => $error->getCode(),
					'details' => $error instanceof PayPalApiException ? $error->details() : array(),
				)
			);
		} catch ( Exception $exception ) {
			$this->logger->error( 'Get order failed: ' . $exception->getMessage() );

			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
				)
			);
		}
	}
}
