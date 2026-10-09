<?php
/**
 * OrderListeners class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use InvalidArgumentException;
use WC_Order;

/**
 * Keeps each order's PayPal calls on the app that created its PayPal order, while the platform serves the store.
 *
 * When the PayPal order ID is written to a WooCommerce order, the order is pinned to the app that signs the request's
 * calls. When a processor starts on an order, the order's pin is entered for its calls; an order not pinned yet leaves
 * the request to the transport's pick, which is the app that created it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class OrderListeners {

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The collecting state, for the store payee.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState   $connection_state The connection state reader.
	 * @param OrderAppContext   $context          The order app context.
	 * @param PlatformTransport $transport        The platform transport.
	 * @param CollectingState   $state            The collecting state.
	 * @param LoggerInterface   $logger           The logger.
	 */
	public function __construct( ConnectionState $connection_state, OrderAppContext $context, PlatformTransport $transport, CollectingState $state, LoggerInterface $logger ) {
		$this->connection_state = $connection_state;
		$this->context          = $context;
		$this->transport        = $transport;
		$this->state            = $state;
		$this->logger           = $logger;
	}

	/**
	 * Enter the order's pinned app before a processor's first PayPal call; leave an order that is not pinned to the pick.
	 *
	 * Every call replaces the context the previous order left, so orders processed one after another in a request each
	 * use their own app.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $wc_order The WooCommerce order.
	 */
	public function handle_woocommerce_paypal_wallet_order_context( $wc_order ): void {
		if ( ! $wc_order instanceof WC_Order || ! $this->connection_state->is_served_by_platform() ) {
			return;
		}

		if ( OrderPin::is_pinned( $wc_order ) ) {
			$this->context->enter_for_order( $wc_order );
			return;
		}

		$this->context->reset();
	}

	/**
	 * Pin the order to the app that signs the request's calls, the one that created the PayPal order.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $wc_order The WooCommerce order.
	 */
	public function handle_woocommerce_paypal_wallet_paypal_order_created( $wc_order ): void {
		if ( ! $wc_order instanceof WC_Order || ! $this->connection_state->is_served_by_platform() ) {
			return;
		}

		try {
			OrderPin::record( $wc_order, $this->context->for_call( $this->transport, $this->state->payee_email() ) );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			$this->logger->warning( sprintf( 'Could not pin WooCommerce order #%d to a PayPal wallet platform app: %s', $wc_order->get_id(), $exception->getMessage() ) );
		}
	}

	/**
	 * Leave the order context once the order processor is done, so later calls in the request go back to the pick.
	 *
	 * @since 11.3.0
	 */
	public function handle_woocommerce_paypal_payments_after_order_processor(): void {
		$this->context->reset();
	}
}
