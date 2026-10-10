<?php
/**
 * Reconciler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\CaptureReader;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WC_Order;

/**
 * Settles held orders from PayPal's capture status and completes onboarding from the seller status, for the webhook
 * events the store missed or rejected, such as those the extension's endpoint turned away while it owned the wallet.
 *
 * Each held capture is read through the app its order is pinned to and goes through the same settlement as the webhook
 * handlers. A run settles at most BATCH_SIZE orders and queues an async continuation for the rest. Runs daily through
 * Action Scheduler while orders are held, on demand from WP-CLI and the panel's status check, and once after the
 * extension hands the wallet back.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class Reconciler {

	/**
	 * The Action Scheduler hook of the reconcile.
	 *
	 * @since 11.3.0
	 */
	public const HOOK = 'woocommerce_paypal_wallet_reconcile';

	/**
	 * The Action Scheduler group of the reconcile.
	 *
	 * @since 11.3.0
	 */
	public const GROUP = 'wc-paypal-wallet';

	/**
	 * The arguments of the run queued when the extension hands the wallet back to core: the first run, which checks
	 * onboarding. They differ from the daily action's (none) and from every continuation's (which carry `true`), so the
	 * hand-back run is not suppressed by the recurring action under a unique enqueue, and two hand-back runs are one.
	 *
	 * @since 11.3.0
	 */
	public const HAND_BACK_ARGS = array( 0, false );

	/**
	 * The most held orders one run settles: each costs a PayPal read, and a run can serve a REST request. The rest goes to
	 * an async continuation.
	 *
	 * @since 11.3.0
	 */
	public const BATCH_SIZE = 25;

	/**
	 * The transient that throttles the schedule check on admin screens.
	 *
	 * @since 11.3.0
	 */
	public const SCHEDULE_CHECK_TRANSIENT = 'wc_paypal_wallet_reconcile_schedule_check';

	/**
	 * The held orders.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The capture reader.
	 *
	 * @var CaptureReader
	 */
	private CaptureReader $reader;

	/**
	 * The held-order settlement.
	 *
	 * @var HeldSettlement
	 */
	private HeldSettlement $settlement;

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The order app context, left after the run.
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
	 * @param HeldOrders        $held_orders The held orders.
	 * @param CaptureReader     $reader      The capture reader.
	 * @param HeldSettlement    $settlement  The held-order settlement.
	 * @param CollectingState   $state       The collecting state.
	 * @param PlatformTransport $transport   The platform transport.
	 * @param OrderAppContext   $context     The order app context.
	 * @param LoggerInterface   $logger      The logger.
	 */
	public function __construct( HeldOrders $held_orders, CaptureReader $reader, HeldSettlement $settlement, CollectingState $state, PlatformTransport $transport, OrderAppContext $context, LoggerInterface $logger ) {
		$this->held_orders = $held_orders;
		$this->reader      = $reader;
		$this->settlement  = $settlement;
		$this->state       = $state;
		$this->transport   = $transport;
		$this->context     = $context;
		$this->logger      = $logger;
	}

	/**
	 * Run the reconcile from Action Scheduler: the daily run and the hand-back run start at the oldest held order and check
	 * onboarding; a continuation starts from the offset it was queued with and does not check onboarding again.
	 *
	 * @internal
	 * @since 11.3.0
	 *
	 * @param mixed $offset       How many held orders to skip; anything but a non-negative number reads as none.
	 * @param mixed $continuation Whether this run continues an earlier one.
	 * @return array The summary of run().
	 */
	public function handle_woocommerce_paypal_wallet_reconcile( $offset = 0, $continuation = false ): array {
		return $this->run( is_numeric( $offset ) ? max( 0, (int) $offset ) : 0, true !== $continuation );
	}

	/**
	 * Settle one batch of held orders from their captures' status, then, while collecting and when asked, complete
	 * onboarding when the seller status is complete. Never throws: a failure is logged and reported in the summary.
	 *
	 * @since 11.3.0
	 *
	 * @param int  $offset           How many held orders to skip, for a continuation.
	 * @param bool $check_onboarding Whether to check onboarding; a continuation does not.
	 * @return array{completed: int[], returned: int[], held: int[], unchanged: int[], failed: int[], onboarding: string}
	 *         The order IDs by outcome, and the onboarding outcome: `completed`, `incomplete`, `not_collecting`, `failed`
	 *         or `skipped`.
	 */
	public function run( int $offset = 0, bool $check_onboarding = true ): array {
		$summary               = $this->settle_held_orders( $offset );
		$summary['onboarding'] = $check_onboarding ? $this->reconcile_onboarding() : 'skipped';
		$this->maintain_schedule();

		return $summary;
	}

	/**
	 * Settle at most BATCH_SIZE held orders, oldest first from an offset, and queue an async continuation when more are
	 * held. The continuation skips the orders of this batch that stay held (still pending, or failed to read); orders that
	 * settled or left the list meanwhile are not counted, so no order is skipped. A batch that settled nothing and left
	 * nothing listed queues nothing, so a chain always ends; the daily run picks up the rest. Never throws.
	 *
	 * @since 11.3.0
	 *
	 * @param int $offset How many held orders to skip.
	 * @return array{completed: int[], returned: int[], held: int[], unchanged: int[], failed: int[]} The order IDs by outcome.
	 */
	public function settle_held_orders( int $offset = 0 ): array {
		$settled = array(
			HeldSettlement::OUTCOME_COMPLETED => array(),
			HeldSettlement::OUTCOME_RETURNED  => array(),
			HeldSettlement::OUTCOME_HELD      => array(),
			HeldSettlement::OUTCOME_UNCHANGED => array(),
		);
		$failed  = array();
		$left    = 0;
		$ids     = array();

		try {
			$ids  = $this->held_orders->ids( self::BATCH_SIZE + 1, $offset );
			$more = count( $ids ) > self::BATCH_SIZE;
			foreach ( array_slice( $ids, 0, self::BATCH_SIZE ) as $id ) {
				// The list was read up front; a webhook may have settled an order since, so read each one again.
				$order = wc_get_order( $id );
				if ( ! $order instanceof WC_Order || ! $this->held_orders->is_held( $order ) ) {
					$settled[ HeldSettlement::OUTCOME_UNCHANGED ][] = $id;
					continue;
				}
				try {
					$read                  = $this->reader->read( $order );
					$outcome               = $this->settlement->apply( $order, $read['capture'], $read['create_time'] );
					$settled[ $outcome ][] = $order->get_id();
					if ( HeldSettlement::OUTCOME_HELD === $outcome ) {
						++$left;
					}
				} catch ( Throwable $throwable ) {
					$failed[] = $order->get_id();
					++$left;
					$this->log_failure( sprintf( 'WooCommerce order #%d', $order->get_id() ), $throwable );
				}
			}
			$progress = $left + count( $settled[ HeldSettlement::OUTCOME_COMPLETED ] ) + count( $settled[ HeldSettlement::OUTCOME_RETURNED ] );
			if ( $more && $progress > 0 ) {
				$this->queue_continuation( $offset + $left );
			}
		} catch ( Throwable $throwable ) {
			$this->log_failure( 'the held orders', $throwable );
		} finally {
			$this->context->reset();
		}

		return array(
			'completed' => $settled[ HeldSettlement::OUTCOME_COMPLETED ],
			'returned'  => $settled[ HeldSettlement::OUTCOME_RETURNED ],
			'held'      => $settled[ HeldSettlement::OUTCOME_HELD ],
			'unchanged' => $settled[ HeldSettlement::OUTCOME_UNCHANGED ],
			'failed'    => $failed,
		);
	}

	/**
	 * Queue the reconcile of the next batch as an async action. Not unique: a continuation queued with the same offset
	 * by the one that is running would otherwise be refused, and settling twice changes nothing.
	 *
	 * @param int $offset How many held orders the continuation skips.
	 */
	private function queue_continuation( int $offset ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array( $offset, true ), self::GROUP );
		$this->logger->info( sprintf( 'PayPal wallet reconcile: more held orders than one run settles; continuing from offset %d.', $offset ) );
	}

	/**
	 * Keep the reconcile scheduled from an admin screen: at most once an hour, and never on an AJAX request.
	 *
	 * @internal
	 * @since 11.3.0
	 */
	public function handle_admin_init(): void {
		if ( wp_doing_ajax() || false !== get_transient( self::SCHEDULE_CHECK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::SCHEDULE_CHECK_TRANSIENT, time(), HOUR_IN_SECONDS );

		$this->maintain_schedule();
	}

	/**
	 * Keep the daily reconcile scheduled while orders are held, and unschedule it once none are.
	 *
	 * @since 11.3.0
	 */
	public function maintain_schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		try {
			$held      = $this->held_orders->count() > 0;
			$scheduled = false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( $held && ! $scheduled ) {
				as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
			} elseif ( ! $held && $scheduled ) {
				as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			}
		} catch ( Throwable $throwable ) {
			$this->log_failure( 'the schedule', $throwable );
		}
	}

	/**
	 * While collecting, complete the state when the seller status is complete. One PayPal call; reads no capture, so the
	 * status check can run on its own, before the held orders.
	 *
	 * @since 11.3.0
	 *
	 * @return string `completed`, `incomplete`, `not_collecting` or `failed`.
	 */
	public function reconcile_onboarding(): string {
		if ( ! $this->state->is_collecting() ) {
			return 'not_collecting';
		}

		try {
			$status = $this->transport->seller_status( $this->state->tracking_id() );
			if ( ! $status->is_complete() ) {
				// The order screen reads the last status to tell "set up" from "confirm the email"; not autoloaded.
				update_option(
					Options::SELLER_STATUS,
					array(
						'payments_receivable'     => $status->payments_receivable(),
						'primary_email_confirmed' => $status->primary_email_confirmed(),
						'checked_at'              => time(),
					),
					false
				);
				return 'incomplete';
			}
			$this->state->complete( $status->merchant_id() );
		} catch ( Throwable $throwable ) {
			$this->log_failure( 'the onboarding', $throwable );
			return 'failed';
		}

		return 'completed';
	}

	/**
	 * Log a failure: what failed and the class and message of the error.
	 *
	 * @param string    $subject   What failed.
	 * @param Throwable $throwable The failure.
	 */
	private function log_failure( string $subject, Throwable $throwable ): void {
		$this->logger->warning( sprintf( 'PayPal wallet reconcile failed for %s: %s: %s', $subject, get_class( $throwable ), $throwable->getMessage() ) );
	}
}
