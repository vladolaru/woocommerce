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
 * handlers. Runs daily through Action Scheduler while orders are held, on demand from WP-CLI, and once after the
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
	 * Run the reconcile from Action Scheduler.
	 *
	 * @internal
	 * @since 11.3.0
	 */
	public function handle_woocommerce_paypal_wallet_reconcile(): void {
		$this->run();
	}

	/**
	 * Settle every held order from its capture's status, then, while collecting, complete onboarding when the seller
	 * status is complete. Never throws: a failure is logged and reported in the summary.
	 *
	 * @since 11.3.0
	 *
	 * @return array{completed: int[], returned: int[], held: int[], unchanged: int[], failed: int[], onboarding: string}
	 *         The order IDs by outcome, and the onboarding outcome: `completed`, `incomplete`, `not_collecting` or `failed`.
	 */
	public function run(): array {
		$settled = array(
			HeldSettlement::OUTCOME_COMPLETED => array(),
			HeldSettlement::OUTCOME_RETURNED  => array(),
			HeldSettlement::OUTCOME_HELD      => array(),
			HeldSettlement::OUTCOME_UNCHANGED => array(),
		);
		$failed  = array();

		try {
			foreach ( $this->held_orders->all() as $listed ) {
				// The list was read up front; a webhook may have settled an order since, so read each one again.
				$order = wc_get_order( $listed->get_id() );
				if ( ! $order instanceof WC_Order || ! $this->held_orders->is_held( $order ) ) {
					$settled[ HeldSettlement::OUTCOME_UNCHANGED ][] = $listed->get_id();
					continue;
				}
				try {
					$read                  = $this->reader->read( $order );
					$outcome               = $this->settlement->apply( $order, $read['capture'], $read['create_time'] );
					$settled[ $outcome ][] = $order->get_id();
				} catch ( Throwable $throwable ) {
					$failed[] = $order->get_id();
					$this->log_failure( sprintf( 'WooCommerce order #%d', $order->get_id() ), $throwable );
				}
			}
		} finally {
			$this->context->reset();
		}

		$onboarding = $this->reconcile_onboarding();
		$this->maintain_schedule();

		return array(
			'completed'  => $settled[ HeldSettlement::OUTCOME_COMPLETED ],
			'returned'   => $settled[ HeldSettlement::OUTCOME_RETURNED ],
			'held'       => $settled[ HeldSettlement::OUTCOME_HELD ],
			'unchanged'  => $settled[ HeldSettlement::OUTCOME_UNCHANGED ],
			'failed'     => $failed,
			'onboarding' => $onboarding,
		);
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
	 * While collecting, complete the state when the seller status is complete.
	 *
	 * @return string `completed`, `incomplete`, `not_collecting` or `failed`.
	 */
	private function reconcile_onboarding(): string {
		if ( ! $this->state->is_collecting() ) {
			return 'not_collecting';
		}

		try {
			$status = $this->transport->seller_status( $this->state->tracking_id() );
			if ( ! $status->is_complete() ) {
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
