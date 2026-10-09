<?php
/**
 * HeldCapture class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatusDetails;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use DateTimeZone;
use WC_DateTime;
use WC_Order;

/**
 * Records a capture PayPal holds, and the first wallet order of a collecting store.
 *
 * PayPal answers a capture for a payee without a PayPal account as pending. The order is then held: PayPal keeps the
 * money until setup is complete and returns it to the buyer if that takes more than 30 days. The first wallet order of a
 * collecting store, held or paid at once, binds the payee and claims the first-order slot.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class HeldCapture {

	/**
	 * The order meta that holds the time PayPal began to hold the capture, as a UTC timestamp.
	 *
	 * @since 11.3.0
	 */
	public const HELD_AT_META_KEY = '_wc_paypal_wallet_held_at';

	/**
	 * The order meta that holds the ID of the held capture.
	 *
	 * @since 11.3.0
	 */
	public const CAPTURE_ID_META_KEY = '_wc_paypal_wallet_capture_id';

	/**
	 * The status-details reason PayPal gives when the payee has no PayPal account to receive the capture.
	 *
	 * @since 11.3.0
	 */
	public const REASON_PAYEE_SETUP_PENDING = 'PAYEE_SETUP_PENDING';

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The logger, or null to log nothing.
	 *
	 * @var LoggerInterface|null
	 */
	private ?LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param CollectingState      $state  The collecting state.
	 * @param LoggerInterface|null $logger The logger.
	 */
	public function __construct( CollectingState $state, ?LoggerInterface $logger = null ) {
		$this->state  = $state;
		$this->logger = $logger;
	}

	/**
	 * Record a held capture on its order, then bind the payee and claim the first order.
	 *
	 * Runs inside the wallet's capture handling, before it puts the order on hold. A pending capture with any other
	 * reason is left to the wallet.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $wc_order The WooCommerce order.
	 * @param mixed $capture  The PayPal capture, pending.
	 */
	public function handle_woocommerce_paypal_wallet_capture_pending( $wc_order, $capture ): void {
		if ( ! $wc_order instanceof WC_Order || ! $capture instanceof Capture ) {
			return;
		}

		// The bookkeeping must never abort the checkout or the wallet's own on-hold transition that follows.
		try {
			// The capture entity does not carry PayPal's create time, and the capture was made in this request.
			if ( $this->record( $wc_order, $capture, time() ) ) {
				$this->claim_first_order( $wc_order );
			}
		} catch ( Throwable $throwable ) {
			$this->log_failure( $wc_order, $throwable );
		}
	}

	/**
	 * Write the held meta and the deadline note on an order whose capture PayPal holds.
	 *
	 * Safe to repeat for the same capture: the wallet's capture handling can run again for it. A repeat adds no note and
	 * never moves the deadline later; it only moves held_at earlier, to a better time. A different capture replaces the
	 * record.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $wc_order The order.
	 * @param Capture  $capture  The pending capture.
	 * @param int      $held_at  When PayPal began to hold the capture, as a UTC timestamp.
	 * @return bool Whether the capture is a held one and was recorded.
	 */
	public function record( WC_Order $wc_order, Capture $capture, int $held_at ): bool {
		$details = $capture->status()->details();
		if ( null === $details || ! self::is_held_reason( $details ) ) {
			return false;
		}

		$stored_id = (string) $wc_order->get_meta( self::CAPTURE_ID_META_KEY, true );
		if ( '' !== $stored_id && $stored_id === $capture->id() ) {
			$stored_at = (int) $wc_order->get_meta( self::HELD_AT_META_KEY, true );
			if ( $stored_at <= 0 || $held_at < $stored_at ) {
				$wc_order->update_meta_data( self::HELD_AT_META_KEY, (string) $held_at );
				$wc_order->save();
			}

			return true;
		}

		$wc_order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, $details->reason() );
		$wc_order->update_meta_data( self::HELD_AT_META_KEY, (string) $held_at );
		$wc_order->update_meta_data( self::CAPTURE_ID_META_KEY, $capture->id() );
		$wc_order->save();

		$deadline = new WC_DateTime( '@' . ( $held_at + HeldOrders::HOLD_PERIOD ) );
		$deadline->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		$wc_order->add_order_note(
			sprintf(
				/* translators: 1: the reason PayPal gave for holding the payment, 2: the date by which setup must be complete. */
				__( 'Payment held by PayPal until PayPal Wallet setup is completed (%1$s). The payment is returned to the customer if setup is not completed by %2$s.', 'woocommerce' ),
				$details->reason(),
				wc_format_datetime( $deadline )
			)
		);

		return true;
	}

	/**
	 * Bind the payee and claim the first order when a wallet order of a collecting store is paid at once.
	 *
	 * An order that was held and completes later finds the slot taken, so the first-order action does not fire twice.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order_id The order ID.
	 */
	public function handle_woocommerce_payment_complete( $order_id ): void {
		// Cheap check first: the option is autoloaded, and only a collecting store can claim.
		if ( ! is_numeric( $order_id ) || ! $this->state->is_collecting() ) {
			return;
		}

		try {
			$wc_order = wc_get_order( (int) $order_id );
			if ( $wc_order instanceof WC_Order && OrderPin::is_wallet_order( $wc_order ) ) {
				$this->claim_first_order( $wc_order );
			}
		} catch ( Throwable $throwable ) {
			$this->log_failure( null, $throwable );
		}
	}

	/**
	 * Whether a status-details reason means PayPal holds the payment for a payee that has no account yet.
	 *
	 * @param CaptureStatusDetails $details The status details.
	 * @return bool
	 */
	private static function is_held_reason( CaptureStatusDetails $details ): bool {
		return $details->is( CaptureStatusDetails::UNILATERAL ) || $details->is( self::REASON_PAYEE_SETUP_PENDING );
	}

	/**
	 * While the store collects: bind the payee, then claim the first order and tell the store when this order won it.
	 *
	 * @param WC_Order $wc_order The wallet order.
	 */
	private function claim_first_order( WC_Order $wc_order ): void {
		if ( ! $this->state->is_collecting() ) {
			return;
		}

		$this->state->bind_payee();
		if ( ! $this->state->claim_first_order( $wc_order->get_id() ) ) {
			return;
		}

		/**
		 * Fires once, for the first wallet order of a store that collects PayPal payments before the merchant has a PayPal account.
		 *
		 * The order may be held by PayPal or paid at once.
		 *
		 * @since 11.3.0
		 *
		 * @param \WC_Order       $wc_order The first order.
		 * @param CollectingState $state    The collecting state.
		 */
		do_action( 'woocommerce_paypal_wallet_first_order', $wc_order, $this->state );
	}

	/**
	 * Log a failure of the collecting bookkeeping: the class and message of the error, never a request or a credential.
	 *
	 * @param WC_Order|null $wc_order  The order, when known.
	 * @param Throwable     $throwable The failure.
	 */
	private function log_failure( ?WC_Order $wc_order, Throwable $throwable ): void {
		if ( null === $this->logger ) {
			return;
		}

		$this->logger->warning(
			sprintf(
				'PayPal wallet held-capture bookkeeping failed%s: %s: %s',
				null === $wc_order ? '' : sprintf( ' for WooCommerce order #%d', $wc_order->get_id() ),
				get_class( $throwable ),
				$throwable->getMessage()
			)
		);
	}
}
