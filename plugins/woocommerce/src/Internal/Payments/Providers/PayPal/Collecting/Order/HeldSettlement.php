<?php
/**
 * HeldSettlement class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use WP_REST_Request;

/**
 * The two ways a held order ends, shared by the webhook handlers and the reconcile so each has one path.
 *
 * Completed: the wallet's own capture-completed handler moves the order on, with its note, and then the held meta is
 * released. Returned: PayPal gave the money back to the buyer, so the order is cancelled with its stock restored, never
 * refunded (a WooCommerce refund would ask the merchant to refund through the gateway, money PayPal already returned).
 *
 * Each settlement runs under a per-order lock, on the order read again inside the lock, so concurrent webhook deliveries
 * or a webhook racing the reconcile settle an order once; a caller holding a stale copy gets `unchanged`.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class HeldSettlement {

	/**
	 * The order meta that records the capture PayPal returned, so a later delivery of the same event changes nothing.
	 *
	 * @since 11.3.0
	 */
	public const RETURNED_META_KEY = '_wc_paypal_wallet_held_returned';

	/**
	 * The prefix of the per-order settlement lock, an option row holding the time it was taken.
	 *
	 * @since 11.3.0
	 */
	public const LOCK_OPTION_PREFIX = 'wc_paypal_wallet_settle_';

	/**
	 * How long a settlement lock holds before another request may take it over: longer than a settlement's PayPal call.
	 *
	 * @since 11.3.0
	 */
	public const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The outcome of a capture that completed.
	 *
	 * @since 11.3.0
	 */
	public const OUTCOME_COMPLETED = 'completed';

	/**
	 * The outcome of a capture PayPal returned to the buyer.
	 *
	 * @since 11.3.0
	 */
	public const OUTCOME_RETURNED = 'returned';

	/**
	 * The outcome of a capture PayPal still holds.
	 *
	 * @since 11.3.0
	 */
	public const OUTCOME_HELD = 'held';

	/**
	 * The outcome of a capture whose status settles nothing, of an order no longer held, or of an order another request
	 * is settling.
	 *
	 * @since 11.3.0
	 */
	public const OUTCOME_UNCHANGED = 'unchanged';

	/**
	 * The capture statuses that mean PayPal returned the held money.
	 */
	private const RETURNED_STATUSES = array(
		CaptureStatus::REFUNDED,
		CaptureStatus::DECLINED,
		CaptureStatus::FAILED,
	);

	/**
	 * The held orders.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The held-capture bookkeeping, for a capture PayPal still holds.
	 *
	 * @var HeldCapture
	 */
	private HeldCapture $held_capture;

	/**
	 * The wallet's PAYMENT.CAPTURE.COMPLETED handler.
	 *
	 * @var RequestHandler
	 */
	private RequestHandler $capture_completed;

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param HeldOrders      $held_orders       The held orders.
	 * @param HeldCapture     $held_capture      The held-capture bookkeeping.
	 * @param RequestHandler  $capture_completed The wallet's PAYMENT.CAPTURE.COMPLETED handler.
	 * @param OrderAppContext $context           The order app context.
	 * @param LoggerInterface $logger            The logger.
	 */
	public function __construct( HeldOrders $held_orders, HeldCapture $held_capture, RequestHandler $capture_completed, OrderAppContext $context, LoggerInterface $logger ) {
		$this->held_orders       = $held_orders;
		$this->held_capture      = $held_capture;
		$this->capture_completed = $capture_completed;
		$this->context           = $context;
		$this->logger            = $logger;
	}

	/**
	 * Settle a held order from a capture read from PayPal.
	 *
	 * A partially refunded capture is completed: PayPal can refund only money the payee received, so the payment was
	 * claimed and part of it refunded afterwards. The order completes once, with a note, and is not read again.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order       The held order.
	 * @param Capture  $capture     The capture.
	 * @param int      $create_time PayPal's create time of the capture, as a UTC timestamp, or 0 when unknown.
	 * @return string One of the OUTCOME_ constants.
	 *
	 * @throws RuntimeException When the wallet's handler cannot complete the order; the order stays held.
	 */
	public function apply( WC_Order $order, Capture $capture, int $create_time ): string {
		$status = $capture->status();
		if ( $status->is( CaptureStatus::COMPLETED ) ) {
			return $this->complete( $order );
		}
		if ( $status->is( CaptureStatus::PARTIALLY_REFUNDED ) ) {
			return $this->complete( $order, null, __( 'PayPal reports this payment as partially refunded. Record the refunded amount in WooCommerce if it is not shown yet.', 'woocommerce' ) );
		}
		if ( in_array( $status->name(), self::RETURNED_STATUSES, true ) ) {
			return $this->return_to_customer( $order, strtolower( $status->name() ) );
		}
		if ( ! $status->is( CaptureStatus::PENDING ) ) {
			return self::OUTCOME_UNCHANGED;
		}

		// Still held: PayPal's create time can only move the deadline earlier, to the time PayPal began to hold it.
		$outcome = $this->with_lock(
			$order,
			function ( WC_Order $fresh ) use ( $capture, $create_time ): string {
				if ( ! $this->held_orders->is_held( $fresh ) ) {
					return self::OUTCOME_UNCHANGED;
				}
				if ( $create_time > 0 ) {
					$this->held_capture->record( $fresh, $capture, $create_time );
				}

				return self::OUTCOME_HELD;
			}
		);

		return $outcome ?? self::OUTCOME_UNCHANGED;
	}

	/**
	 * Complete a held order through the wallet's own capture-completed handler, then release its held meta.
	 *
	 * The handler finds the order by the event's custom ID, so the request it gets names this order. Without a request,
	 * as in the reconcile, one is built from the order. The held meta is released only once the handler reports success,
	 * so an order it could not complete stays held for the reconcile.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order             $order   The order.
	 * @param WP_REST_Request|null $request The webhook request, or null to build one.
	 * @param string               $note    A note added once the order completes, or an empty string for none.
	 * @phpstan-param WP_REST_Request<array<string, mixed>>|null $request
	 * @return string `completed`, or `unchanged` when the order is no longer held or another request is settling it.
	 *
	 * @throws RuntimeException When the wallet's handler reports a failure.
	 */
	public function complete( WC_Order $order, ?WP_REST_Request $request = null, string $note = '' ): string {
		$outcome = $this->with_lock(
			$order,
			function ( WC_Order $fresh ) use ( $request, $note ): string {
				if ( ! $this->held_orders->is_held( $fresh ) ) {
					return self::OUTCOME_UNCHANGED;
				}

				$response = $this->run_capture_completed( $fresh, $request );
				$data     = $response->get_data();
				if ( ! is_array( $data ) || empty( $data['success'] ) ) {
					$reason = is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : 'no reason given';
					throw new RuntimeException( esc_html( 'The wallet could not complete the held order: ' . $reason ) );
				}

				// The wallet's handler saved its own copy of the order; release on a copy read after it.
				$completed = $this->reload( $fresh->get_id() ) ?? $fresh;
				if ( '' !== $note ) {
					$completed->add_order_note( $note );
				}
				$this->held_orders->release( $completed );

				return self::OUTCOME_COMPLETED;
			}
		);

		return $outcome ?? self::OUTCOME_UNCHANGED;
	}

	/**
	 * Cancel an order whose held payment PayPal returned to the buyer or denied: restore its stock, note why, release the
	 * held meta and tell the store. An order no longer held, or already returned, is left alone.
	 *
	 * The stock is restored before the status changes. The cancelled transition restores stock too, from a fresh copy of
	 * the order, and finds every line already restored, so nothing is counted twice.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $reason The capture status that returned it, lowercase: `refunded`, `declined` or `failed`.
	 * @return string `returned`, or `unchanged` when the order is no longer held or another request is settling it.
	 */
	public function return_to_customer( WC_Order $order, string $reason = 'refunded' ): string {
		$outcome = $this->with_lock(
			$order,
			function ( WC_Order $fresh ) use ( $reason ): string {
				if ( $this->is_returned( $fresh ) || ! $this->held_orders->is_held( $fresh ) ) {
					return self::OUTCOME_UNCHANGED;
				}

				$capture_id = CaptureReader::capture_id( $fresh );
				wc_increase_stock_levels( $fresh );
				$fresh->add_order_note(
					'refunded' === $reason
						? __( 'PayPal returned the held payment to the customer because setup was not completed', 'woocommerce' )
						: __( 'PayPal denied the held payment, so the order was cancelled', 'woocommerce' )
				);
				$fresh->update_meta_data( self::RETURNED_META_KEY, '' !== $capture_id ? $capture_id : 'yes' );
				$this->held_orders->release( $fresh );
				$fresh->update_status( OrderStatus::CANCELLED );

				/**
				 * Fires when PayPal returned or denied the payment it held for a wallet order, because the merchant did
				 * not complete PayPal Wallet setup in time or PayPal declined the capture. The order is cancelled and its
				 * stock restored.
				 *
				 * @since 11.3.0
				 *
				 * @param \WC_Order $wc_order The cancelled order.
				 * @param string    $reason   The capture status, lowercase: `refunded` (returned to the customer),
				 *                            `declined` or `failed` (denied).
				 */
				do_action( 'woocommerce_paypal_wallet_held_payment_returned', $fresh, $reason );

				return self::OUTCOME_RETURNED;
			}
		);

		return $outcome ?? self::OUTCOME_UNCHANGED;
	}

	/**
	 * Whether PayPal already returned an order's held payment.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function is_returned( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::RETURNED_META_KEY, true );
	}

	/**
	 * Run the wallet's capture-completed handler for an order, inside the order's pinned app, and restore the app context.
	 *
	 * @param WC_Order             $order   The order, read inside the lock.
	 * @param WP_REST_Request|null $request The webhook request, or null to build one.
	 * @phpstan-param WP_REST_Request<array<string, mixed>>|null $request
	 * @return \WP_REST_Response
	 */
	private function run_capture_completed( WC_Order $order, ?WP_REST_Request $request ): \WP_REST_Response {
		$resource_data = null === $request ? array() : $request['resource'];
		$resource_data = is_array( $resource_data ) ? $resource_data : array();

		$resource_data['custom_id'] = (string) $order->get_id();
		if ( ! isset( $resource_data['id'] ) ) {
			$resource_data['id'] = CaptureReader::capture_id( $order );
		}
		$paypal_order_id = (string) $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true );
		if ( '' !== $paypal_order_id && ! isset( $resource_data['supplementary_data']['related_ids']['order_id'] ) ) {
			$resource_data['supplementary_data']['related_ids']['order_id'] = $paypal_order_id;
		}

		/**
		 * The request the wallet's handler gets.
		 *
		 * @var WP_REST_Request<array<string, mixed>> $completion
		 */
		$completion = null === $request ? new WP_REST_Request( 'POST' ) : clone $request;
		$completion->set_param( 'event_type', 'PAYMENT.CAPTURE.COMPLETED' );
		$completion->set_param( 'resource', $resource_data );
		if ( null === $request ) {
			$completion->set_param( 'id', 'wc-paypal-wallet-reconcile-' . $order->get_id() );
		}

		// The wallet's handler reads the PayPal order for its transaction ID, which only the order's app can do.
		$was_entered = $this->context->is_entered();
		$previous    = $this->context->current();
		$this->context->enter_for_order( $order );
		try {
			return $this->capture_completed->handle_request( $completion );
		} finally {
			if ( $was_entered ) {
				$this->context->enter( $previous );
			} else {
				$this->context->reset();
			}
		}
	}

	/**
	 * Run a settlement under the order's lock, on the order read again inside the lock.
	 *
	 * The lock is an option row inserted with INSERT IGNORE, so only one request creates it; a lock older than LOCK_TTL is
	 * taken over with a conditional update, so a request that died does not block the order for good.
	 *
	 * @param WC_Order $order  The order.
	 * @param callable $settle Receives the fresh order and returns an outcome.
	 * @return string|null The outcome, or null when another request holds the lock or the order is gone.
	 */
	private function with_lock( WC_Order $order, callable $settle ): ?string {
		global $wpdb;

		$order_id = $order->get_id();
		$key      = self::LOCK_OPTION_PREFIX . $order_id;
		$now      = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock needs a single INSERT IGNORE / conditional UPDATE; the options API's add_option() upserts and is not atomic, and the row must not be cached.
		$taken = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) $now ) );
		if ( ! $taken ) {
			$taken = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) $now, $key, $now - self::LOCK_TTL ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $taken ) {
			$this->logger->info( sprintf( 'WooCommerce order #%d is being settled by another request; left alone.', $order_id ) );
			return null;
		}

		try {
			$fresh = $this->reload( $order_id );

			return null === $fresh ? null : $settle( $fresh );
		} finally {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the lock row written above.
		}
	}

	/**
	 * Read an order again, past this request's order caches, so a settlement sees what another request saved.
	 *
	 * @param int $order_id The order ID.
	 * @return WC_Order|null
	 */
	private function reload( int $order_id ): ?WC_Order {
		wc_get_container()->get( OrderCache::class )->remove( $order_id );
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}
}
