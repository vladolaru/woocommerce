<?php
/**
 * RefundLock class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
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
	 * A wallet order is decided by OrderPin::is_wallet_order(). Filterable; see base_locked() for the answer before the filter.
	 * The module's callback on the filter re-checks base_locked() and must never call is_locked(), which would recurse.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function is_locked( WC_Order $order ): bool {
		$locked = $this->base_locked( $order );

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
	 * Whether refunds of an order are locked before the filter: PayPal holds its capture, or it is a wallet order and the
	 * store still collects. The order screen's Refund button asks the filter with false, so the module answers it from here.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function base_locked( WC_Order $order ): bool {
		$held = ! empty( $order->get_meta( self::HELD_CAPTURE_META_KEY, true ) );

		return $held || ( OrderPin::is_wallet_order( $order ) && ConnectionState::COLLECTING === $this->connection_state->resolve() );
	}

	/**
	 * Lock the order screen's Refund button: the callback of the module on `woocommerce_paypal_wallet_refund_locked`.
	 *
	 * The order items view applies the filter to false and nothing else answered it, so this answers it from the base
	 * predicate. It keeps a lock another callback already set, never calls is_locked() (which applies the filter again),
	 * and runs before the callbacks at the default priority, so those can still unlock.
	 *
	 * @internal
	 * @since 11.3.0
	 *
	 * @param mixed $locked Whether refunds are locked so far.
	 * @param mixed $order  The order.
	 * @return mixed The lock, or the value unchanged when it is not a bool or the order is not an order.
	 */
	public function handle_woocommerce_paypal_wallet_refund_locked( $locked, $order = null ) {
		if ( ! is_bool( $locked ) || ! $order instanceof WC_Order ) {
			return $locked;
		}

		return true === $locked ? true : $this->base_locked( $order );
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
