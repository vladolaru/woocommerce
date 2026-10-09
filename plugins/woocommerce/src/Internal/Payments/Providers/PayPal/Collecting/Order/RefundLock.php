<?php
/**
 * RefundLock class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Order;

/**
 * Whether a wallet order can be refunded yet.
 *
 * PayPal refuses every refund until the payee has an account and the store is connected, so the store refuses it first:
 * for an order PayPal holds, and for any wallet order while the store still collects.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class RefundLock {

	/**
	 * The order meta that marks a capture PayPal holds until setup is complete.
	 *
	 * @since 11.3.0
	 */
	public const HELD_CAPTURE_META_KEY = '_wc_paypal_wallet_held_capture';

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState $connection_state The connection state reader.
	 */
	public function __construct( ConnectionState $connection_state ) {
		$this->connection_state = $connection_state;
	}

	/**
	 * Whether refunds of an order are locked: PayPal holds its capture, or it is a wallet order and the store still collects.
	 *
	 * A wallet order is one pinned to an app, or one paid through the PayPal gateway with a PayPal order ID: some paths
	 * leave an order unpinned, such as a capture already completed before processing, or a pin that failed.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function is_locked( WC_Order $order ): bool {
		$held   = ! empty( $order->get_meta( self::HELD_CAPTURE_META_KEY, true ) );
		$locked = $held || ( $this->is_wallet_order( $order ) && ConnectionState::COLLECTING === $this->connection_state->resolve() );

		/**
		 * Filters whether refunds of a PayPal wallet order are locked until PayPal Wallet setup is complete.
		 *
		 * @since 11.3.0
		 *
		 * @param bool      $locked Whether refunds are locked.
		 * @param \WC_Order $order  The order.
		 */
		$filtered = apply_filters( 'woocommerce_paypal_wallet_refund_locked', $locked, $order );

		return is_bool( $filtered ) ? $filtered : $locked;
	}

	/**
	 * Whether an order was paid with the PayPal wallet: pinned to an app, or paid through its gateway with a PayPal order ID.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	private function is_wallet_order( WC_Order $order ): bool {
		if ( OrderPin::is_pinned( $order ) ) {
			return true;
		}

		return PayPalGateway::ID === $order->get_payment_method() && ! empty( $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) );
	}

	/**
	 * The message a locked refund is refused with.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public static function message(): string {
		return __( 'Refunds are available once PayPal Wallet setup is complete', 'woocommerce' );
	}
}
