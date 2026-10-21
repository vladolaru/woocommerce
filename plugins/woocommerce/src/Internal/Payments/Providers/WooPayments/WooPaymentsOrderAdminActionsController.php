<?php
/**
 * WooPaymentsOrderAdminActionsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Adds the WooPayments capture and cancel actions to the order screen, captures or cancels an authorization when its
 * order is completed or cancelled, and shows a wallet order's payment title in admin.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsOrderAdminActionsController implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook that runs a status-change capture or cancel again after the order payment lock refused it.
	 *
	 * @since 11.2.0
	 * @var string
	 */
	public const RETRY_STATUS_CHANGE_OPERATION_ACTION = 'woocommerce_woopayments_retry_status_change_operation';

	/**
	 * Shared payment processing service.
	 *
	 * @var PaymentProcessingService
	 */
	private PaymentProcessingService $processing_service;

	/**
	 * WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Fee details note controller.
	 *
	 * @var WooPaymentsFeeDetailsNoteController
	 */
	private WooPaymentsFeeDetailsNoteController $fee_details_note_controller;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param PaymentProcessingService            $processing_service          Shared payment processing service.
	 * @param WooPaymentsProvider                 $provider                    WooPayments provider.
	 * @param WooPaymentsFeeDetailsNoteController $fee_details_note_controller Fee details note controller.
	 * @param WooPaymentsActionSchedulerService   $scheduler                   Action Scheduler service.
	 */
	final public function init( PaymentProcessingService $processing_service, WooPaymentsProvider $provider, WooPaymentsFeeDetailsNoteController $fee_details_note_controller, WooPaymentsActionSchedulerService $scheduler ): void {
		$this->processing_service          = $processing_service;
		$this->provider                    = $provider;
		$this->fee_details_note_controller = $fee_details_note_controller;
		$this->scheduler                   = $scheduler;
	}

	/**
	 * Register the order screen and status hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_order_actions', array( $this, 'handle_woocommerce_order_actions' ) );
		add_action( 'woocommerce_order_action_capture_charge', array( $this, 'handle_woocommerce_order_action_capture_charge' ) );
		add_action( 'woocommerce_order_action_cancel_authorization', array( $this, 'handle_woocommerce_order_action_cancel_authorization' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_woocommerce_order_status_completed' ), 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_woocommerce_order_status_cancelled' ), 10, 3 );
		add_action( self::RETRY_STATUS_CHANGE_OPERATION_ACTION, array( $this, 'handle_retry_status_change_operation' ), 10, 3 );
		if ( is_admin() ) {
			add_filter( 'woocommerce_gateway_title', array( $this, 'handle_woocommerce_gateway_title' ), 10, 2 );
		}
	}

	/**
	 * Show an Apple Pay or Google Pay order's payment title in place of the card gateway's title in admin.
	 *
	 * The order screen's "Payment via" line and payment method select read the gateway title, so a wallet order would read
	 * as a card order. Client 11.1.0 does the same in `filter_gateway_title()` (class-wc-payments-express-checkout-button-handler.php:415-437),
	 * finding the order as its `get_current_order()` does (class-wc-payments-express-checkout-button-helper.php:520-533).
	 * Unlike client 11.1.0, only a WooPayments order's title is used: the select lists every gateway, so another gateway's
	 * Apple Pay order would relabel the WooPayments option.
	 *
	 * @internal
	 *
	 * @param mixed $title      Gateway title.
	 * @param mixed $gateway_id Gateway ID.
	 * @return mixed
	 */
	public function handle_woocommerce_gateway_title( $title, $gateway_id = '' ) {
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID !== $gateway_id || ! is_admin() ) {
			return $title;
		}

		global $theorder, $post;
		$order = $theorder instanceof WC_Order ? $theorder : ( $post instanceof \WP_Post ? wc_get_order( $post->ID ) : null );
		if ( ! $order instanceof WC_Order || WooPaymentsPersistenceVocabulary::GATEWAY_ID !== $order->get_payment_method() ) {
			return $title;
		}

		// The title comes through the woocommerce_order_get_payment_method_title filter, which may return anything.
		$method_title = $order->get_payment_method_title();
		if ( ! is_string( $method_title ) ) {
			return $title;
		}

		// "Payment Request" is the title of orders placed with client 11.1.0's earlier payment request buttons
		// (class-wc-payments-express-checkout-button-handler.php:427).
		foreach ( array( 'Apple Pay', 'Google Pay', 'Payment Request' ) as $wallet_title ) {
			if ( 0 === strpos( $method_title, $wallet_title ) ) {
				return $method_title;
			}
		}

		return $title;
	}

	/**
	 * Add capture and cancel actions for an authorized WooPayments order.
	 *
	 * @internal
	 *
	 * @param mixed $actions Existing order actions.
	 * @return mixed
	 */
	public function handle_woocommerce_order_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		global $theorder;

		if ( ! $theorder instanceof WC_Order || ! $this->is_authorized_woopayments_order( $theorder, true ) ) {
			return $actions;
		}

		return array_merge(
			array(
				'capture_charge'       => __( 'Capture charge', 'woocommerce' ),
				'cancel_authorization' => __( 'Cancel authorization', 'woocommerce' ),
			),
			$actions
		);
	}

	/**
	 * Handle the manual capture order action.
	 *
	 * @internal
	 *
	 * @param mixed $order Order being captured.
	 */
	public function handle_woocommerce_order_action_capture_charge( $order ): void {
		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, true ) ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CAPTURE );
		}
	}

	/**
	 * Handle the manual cancel-authorization order action.
	 *
	 * @internal
	 *
	 * @param mixed $order Order whose authorization is being cancelled.
	 */
	public function handle_woocommerce_order_action_cancel_authorization( $order ): void {
		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, true ) ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CANCEL );
		}
	}

	/**
	 * Handle an order transition to completed.
	 *
	 * @internal
	 *
	 * @param mixed $order_id          Order ID.
	 * @param mixed $order             Order object supplied by core.
	 * @param mixed $status_transition Status transition details.
	 */
	public function handle_woocommerce_order_status_completed( $order_id, $order = null, $status_transition = array() ): void {
		unset( $status_transition );
		$order = $this->resolve_status_order( absint( $order_id ), $order instanceof WC_Order ? $order : null );

		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, false ) ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CAPTURE, true );
		}
	}

	/**
	 * Handle an order transition to cancelled.
	 *
	 * @internal
	 *
	 * @param mixed $order_id          Order ID.
	 * @param mixed $order             Order object supplied by core.
	 * @param mixed $status_transition Status transition details.
	 */
	public function handle_woocommerce_order_status_cancelled( $order_id, $order = null, $status_transition = array() ): void {
		unset( $status_transition );
		$order = $this->resolve_status_order( absint( $order_id ), $order instanceof WC_Order ? $order : null );

		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, false ) ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CANCEL, true );
		}
	}

	/**
	 * Run a status-change capture or cancel again, when its order is still completed or cancelled and still authorized.
	 *
	 * @internal
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $operation Capture or cancel.
	 * @param mixed $retry     Number of this retry, from 1.
	 */
	public function handle_retry_status_change_operation( $order_id, $operation, $retry ): void {
		$statuses = array(
			PaymentProcessingService::OPERATION_CAPTURE => OrderStatus::COMPLETED,
			PaymentProcessingService::OPERATION_CANCEL  => OrderStatus::CANCELLED,
		);
		if ( ! is_string( $operation ) || ! isset( $statuses[ $operation ] ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order && $order->has_status( $statuses[ $operation ] ) && $this->is_authorized_woopayments_order( $order, false ) ) {
			$this->run_operation( $order, $operation, true, absint( $retry ) );
		}
	}

	/**
	 * Resolve the status-hook order without replacing a matching live object.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object supplied by core.
	 * @return WC_Order|null
	 */
	private function resolve_status_order( int $order_id, ?WC_Order $order ): ?WC_Order {
		if ( $order instanceof WC_Order && $order_id === $order->get_id() ) {
			return $order;
		}

		$resolved_order = wc_get_order( $order_id );

		return $resolved_order instanceof WC_Order ? $resolved_order : null;
	}

	/**
	 * Tell whether an order carries a capturable WooPayments authorization.
	 *
	 * @param WC_Order $order          Order.
	 * @param bool     $require_unpaid Whether paid statuses are ineligible.
	 * @return bool
	 */
	private function is_authorized_woopayments_order( WC_Order $order, bool $require_unpaid ): bool {
		if ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) || 'requires_capture' !== (string) $order->get_meta( '_intention_status', true ) ) {
			return false;
		}

		return ! $require_unpaid || ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
	}

	/**
	 * Delegate capture or cancel to the shared payment processing path.
	 *
	 * Client 11.1.0 captures or cancels on the status change with no lock (class-wc-payments-order-service.php:1793-1886).
	 * When the order payment lock refuses it here, it runs again after the current request, on the webhook retry
	 * cadence. The first refusal notes that the operation is pending, and the failure note is written only when the last
	 * retry is refused too.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $operation        Capture or cancel.
	 * @param bool     $on_status_change Whether the order status change to completed or cancelled triggered the operation.
	 * @param int      $retry            Which retry of the status-change operation this run is, 0 for the status change itself.
	 */
	private function run_operation( WC_Order $order, string $operation, bool $on_status_change = false, int $retry = 0 ): void {
		try {
			$outcome = PaymentProcessingService::OPERATION_CAPTURE === $operation
				? $this->processing_service->capture(
					PaymentOperationContext::for_capture(
						$order,
						WooPaymentsPersistenceVocabulary::GATEWAY_ID,
						(float) $order->get_total(),
						$on_status_change ? array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ) : array()
					),
					$this->provider
				)
				: $this->processing_service->cancel(
					PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
					$this->provider
				);

			if ( $on_status_change && ! empty( $outcome->get_data()[ PaymentOutcome::DATA_ORDER_PAYMENT_LOCK_REFUSED ] ) && $this->schedule_retry( $order, $operation, $retry ) ) {
				if ( 0 === $retry ) {
					$this->add_operation_pending_note( $order, $operation );
				}
				return;
			}

			if ( $this->operation_failed_without_note( $outcome, $operation ) ) {
				$this->add_operation_failure_note( $order, $operation );
			}

			// Client 11.1.0 schedules the Fee details job after this capture even when its capture note already exists
			// (class-wc-payments-order-service.php:1862-1874); a job already pending for the order is not scheduled again.
			if ( $on_status_change && PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
				$intent_id = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) $order->get_meta( '_intent_id', true );
				$this->fee_details_note_controller->schedule( $order, $intent_id );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			$this->add_operation_failure_note( $order, $operation );
		}
	}

	/**
	 * Schedule the next retry of a status-change operation the order payment lock refused.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 * @param int      $retry     Which retry the refused run was, 0 for the status change itself.
	 * @return bool False when no retry is left.
	 */
	private function schedule_retry( WC_Order $order, string $operation, int $retry ): bool {
		$delays = WooPaymentsWebhookReliabilityService::RETRY_DELAYS_SECONDS;
		if ( ! isset( $delays[ $retry ] ) ) {
			return false;
		}

		$this->scheduler->schedule_job(
			self::RETRY_STATUS_CHANGE_OPERATION_ACTION,
			array(
				'order_id'  => $order->get_id(),
				'operation' => $operation,
				'retry'     => $retry + 1,
			),
			time() + $delays[ $retry ]
		);

		return true;
	}

	/**
	 * Tell whether an operation failed and its outcome carries no note to add.
	 *
	 * @param PaymentOutcome $outcome   Operation outcome.
	 * @param string         $operation Capture or cancel.
	 * @return bool
	 */
	private function operation_failed_without_note( PaymentOutcome $outcome, string $operation ): bool {
		$expected_status = PaymentProcessingService::OPERATION_CAPTURE === $operation ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_CANCELED;
		$data            = $outcome->get_data();

		return $expected_status !== $outcome->get_status()
			&& ( ! isset( $data[ PaymentOutcome::DATA_NOTE ] ) || '' === (string) $data[ PaymentOutcome::DATA_NOTE ] );
	}

	/**
	 * Add the note that a refused status-change capture or cancel will be retried.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 */
	private function add_operation_pending_note( WC_Order $order, string $operation ): void {
		$order->add_order_note(
			PaymentProcessingService::OPERATION_CAPTURE === $operation
				? __( 'The payment capture is pending and will be retried automatically.', 'woocommerce' )
				: __( 'Canceling the payment authorization is pending and will be retried automatically.', 'woocommerce' )
		);
	}

	/**
	 * Add the generic capture or cancel failure note.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 */
	private function add_operation_failure_note( WC_Order $order, string $operation ): void {
		$note = PaymentProcessingService::OPERATION_CAPTURE === $operation
			? __( 'Capture authorization <strong>failed</strong> to complete.', 'woocommerce' )
			: __( 'Canceling authorization <strong>failed</strong> to complete.', 'woocommerce' );

		$order->add_order_note( wp_kses( $note, array( 'strong' => array() ) ) );
	}
}
