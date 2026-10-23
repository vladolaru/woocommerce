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
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use InvalidArgumentException;
use WC_Order;

/**
 * Keeps each order's PayPal calls on the app that created its PayPal order, while the platform serves the store.
 *
 * When the PayPal order ID is written to a WooCommerce order, the order is pinned to the app that signs the request's
 * calls; a Pay with Crypto order, which the wallet's order meta code never sees, is pinned to the platform app by the
 * save that writes its PayPal order ID, once platform connected. When a processor starts on an order, the order's pin
 * is entered for its calls; an order not pinned yet leaves the request to the transport's pick, which is the app that
 * created it.
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
	 * Enter the edited order's pinned app on the order screen, before the wallet's void button fetches its PayPal order.
	 *
	 * The fetch runs on `admin_enqueue_scripts` with no order context of its own, so once the store is platform connected
	 * it would go through the platform app, which cannot see an order the merchant app created.
	 *
	 * @since 11.3.0
	 */
	public function handle_admin_enqueue_scripts(): void {
		global $theorder;
		if ( ! is_admin() || wp_doing_ajax() || ! isset( $theorder ) ) {
			return;
		}

		$this->handle_woocommerce_paypal_wallet_order_context( $theorder );
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
	 * Pin a Pay with Crypto order to the platform app in the save that writes its PayPal order ID, once platform connected.
	 *
	 * The crypto gateway writes the PayPal order ID and saves without the wallet's order meta code, so the paypal-order-created
	 * action never fires for it. AssertedRefundSigner creates the crypto order with the platform app, and a new PayPal
	 * order ID replaces an older pin, as a new PayPal button order does. The payment method is read first, so every other
	 * order save costs one comparison.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $wc_order The order being saved.
	 */
	public function handle_woocommerce_before_order_object_save( $wc_order ): void {
		if (
			! $wc_order instanceof WC_Order
			|| GatewayIds::PWC !== $wc_order->get_payment_method()
			|| ! self::writes_paypal_order_id( $wc_order )
			|| ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve()
		) {
			return;
		}

		OrderPin::record( $wc_order, PlatformTransport::APP_PLATFORM );
	}

	/**
	 * Whether the save in progress writes a PayPal order ID: one added since the order was read, or changed.
	 *
	 * @param WC_Order $wc_order The order being saved.
	 * @return bool
	 */
	private static function writes_paypal_order_id( WC_Order $wc_order ): bool {
		foreach ( $wc_order->get_meta_data() as $meta ) {
			if ( PayPalGateway::ORDER_ID_META_KEY === $meta->key && is_string( $meta->value ) && '' !== $meta->value && ( null === $meta->id || array() !== $meta->get_changes() ) ) {
				return true;
			}
		}

		return false;
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
