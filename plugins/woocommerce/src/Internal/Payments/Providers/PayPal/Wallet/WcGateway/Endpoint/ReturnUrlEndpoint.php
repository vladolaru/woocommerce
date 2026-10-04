<?php
/**
 * Controls the endpoint for customers returning from PayPal.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;

/**
 * Class ReturnUrlEndpoint
 */
class ReturnUrlEndpoint {

	const ENDPOINT = 'ppc-return-url';

	/**
	 * The PayPal Gateway.
	 *
	 * @var PayPalGateway
	 */
	private $gateway;

	/**
	 * The Order Endpoint.
	 *
	 * @var OrderEndpoint
	 */
	private $order_endpoint;

	/**
	 * The session handler
	 *
	 * @var SessionHandler
	 */
	protected $session_handler;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * ReturnUrlEndpoint constructor.
	 *
	 * @param PayPalGateway   $gateway         The PayPal Gateway.
	 * @param OrderEndpoint   $order_endpoint  The Order Endpoint.
	 * @param SessionHandler  $session_handler The session handler.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		PayPalGateway $gateway,
		OrderEndpoint $order_endpoint,
		SessionHandler $session_handler,
		LoggerInterface $logger
	) {
		$this->gateway         = $gateway;
		$this->order_endpoint  = $order_endpoint;
		$this->session_handler = $session_handler;
		$this->logger          = $logger;
	}

	/**
	 * Handles the incoming request.
	 */
	public function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['token'] ) ) {
			wc_add_notice( __( 'Payment session expired. Please try placing your order again.', 'woocommerce' ), 'error' );
			wp_safe_redirect( $this->get_checkout_url_with_error() );
			exit();
		}
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		try {
			$order = $this->order_endpoint->order( $token );
		} catch ( Exception $exception ) {
			$this->logger->warning( "Return URL endpoint failed to fetch order $token: " . $exception->getMessage() );
			wc_add_notice( __( 'Could not retrieve payment information. Please try again.', 'woocommerce' ), 'error' );
			wp_safe_redirect( $this->get_checkout_url_with_error() );
			exit();
		}

		// Replace session order for approved/completed orders.
		if ( $order->status()->is( OrderStatus::APPROVED )
			|| $order->status()->is( OrderStatus::COMPLETED )
		) {
			$this->session_handler->replace_order( $order );
		}

		$wc_order_id = (int) $order->purchase_units()[0]->custom_id();
		if ( ! $wc_order_id ) {
			// We cannot finish processing here without WC order, but at least go into the continuation mode.
			if ( $order->status()->is( OrderStatus::APPROVED )
				|| $order->status()->is( OrderStatus::COMPLETED )
			) {
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->logger->warning( "Return URL endpoint $token: no WC order ID." );
			wc_add_notice( __( 'Order information is missing. Please try placing your order again.', 'woocommerce' ), 'error' );
			wp_safe_redirect( $this->get_checkout_url_with_error() );
			exit();
		}

		$wc_order = wc_get_order( $wc_order_id );
		if ( ! ( $wc_order instanceof \WC_Order ) ) {
			$this->logger->warning( "Return URL endpoint $token: WC order $wc_order_id not found." );

			wc_add_notice( __( 'Order not found. Please try placing your order again.', 'woocommerce' ), 'error' );
			wp_safe_redirect( $this->get_checkout_url_with_error() );
			exit();
		}

		$payment_gateway = $this->get_payment_gateway( $wc_order->get_payment_method() );
		if ( ! $payment_gateway ) {
			wc_add_notice( __( 'Payment gateway is unavailable. Please try again or contact support.', 'woocommerce' ), 'error' );
			wp_safe_redirect( $this->get_checkout_url_with_error() );
			exit();
		}

		$success = $payment_gateway->process_payment( $wc_order_id );

		if ( isset( $success['result'] ) && 'success' === $success['result'] ) {
			add_filter(
				'allowed_redirect_hosts',
				function ( $allowed_hosts ): array {
					$allowed_hosts[] = 'www.paypal.com';
					$allowed_hosts[] = 'www.sandbox.paypal.com';
					return (array) $allowed_hosts;
				}
			);
			wp_safe_redirect( $success['redirect'] );
			exit();
		}

		wc_add_notice( __( 'Payment processing failed. Please try again or contact support.', 'woocommerce' ), 'error' );
		wp_safe_redirect( $this->get_checkout_url_with_error() );
		exit();
	}

	/**
	 * Get checkout URL with additional error parameters.
	 *
	 * Applies the 'ppcp_return_url_error_args' filter to allow external modules to add error parameters.
	 *
	 * @return string Checkout URL with error query arguments, if any.
	 */
	private function get_checkout_url_with_error(): string {
		$url  = wc_get_checkout_url();
		$args = apply_filters( 'ppcp_return_url_error_args', array(), $this );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	/**
	 * Gets the appropriate payment gateway for the given payment method.
	 *
	 * @param string $payment_method The payment method ID.
	 * @return \WC_Payment_Gateway|null
	 */
	private function get_payment_gateway( string $payment_method ) {

		// For regular PayPal payments, use the injected gateway.
		if ( $payment_method === $this->gateway->id ) {
			return $this->gateway;
		}

		// For other payment methods, get the gateway from WooCommerce.
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( isset( $available_gateways[ $payment_method ] ) ) {
			return $available_gateways[ $payment_method ];
		}

		// Returning with an approved order puts the checkout in continuation mode,
		// where DisableGateways offers PayPal alone - so the gateway that sent the
		// buyer to PayPal is missing from the list above on the way back.
		$registered_gateways = WC()->payment_gateways->payment_gateways();

		return $registered_gateways[ $payment_method ] ?? null;
	}
}
