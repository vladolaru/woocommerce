<?php
/**
 * WooPaymentsOrderAdminActionsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Restores WooPayments authorization actions in the WooCommerce order workflow.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderAdminActionsController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter            Runtime owner arbiter.
	 * @param PaymentProcessingService     $processing_service Shared payment processing service.
	 * @param WooPaymentsProvider          $provider           WooPayments provider.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, PaymentProcessingService $processing_service, WooPaymentsProvider $provider ): void {
		$this->arbiter            = $arbiter;
		$this->processing_service = $processing_service;
		$this->provider           = $provider;
	}

	/**
	 * Register order workflow hooks when native owns the runtime.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_order_actions', array( $this, 'handle_woocommerce_order_actions' ) ) ) {
			add_filter( 'woocommerce_order_actions', array( $this, 'handle_woocommerce_order_actions' ) );
		}
		if ( false === has_action( 'woocommerce_order_action_capture_charge', array( $this, 'handle_woocommerce_order_action_capture_charge' ) ) ) {
			add_action( 'woocommerce_order_action_capture_charge', array( $this, 'handle_woocommerce_order_action_capture_charge' ) );
		}
		if ( false === has_action( 'woocommerce_order_action_cancel_authorization', array( $this, 'handle_woocommerce_order_action_cancel_authorization' ) ) ) {
			add_action( 'woocommerce_order_action_cancel_authorization', array( $this, 'handle_woocommerce_order_action_cancel_authorization' ) );
		}
		if ( false === has_action( 'woocommerce_order_status_completed', array( $this, 'handle_woocommerce_order_status_completed' ) ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'handle_woocommerce_order_status_completed' ), 10, 3 );
		}
		if ( false === has_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_woocommerce_order_status_cancelled' ) ) ) {
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_woocommerce_order_status_cancelled' ), 10, 3 );
		}
	}

	/**
	 * Add capture and cancel actions for an authorized WooPayments order.
	 *
	 * @internal
	 *
	 * @param array<string,string> $actions Existing order actions.
	 * @return array<string,string>
	 */
	public function handle_woocommerce_order_actions( array $actions ): array {
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
	 * @param WC_Order $order Order being captured.
	 */
	public function handle_woocommerce_order_action_capture_charge( WC_Order $order ): void {
		if ( $this->is_authorized_woopayments_order( $order, true ) ) {
			$this->run_operation( $order, 'capture' );
		}
	}

	/**
	 * Handle the manual cancel-authorization order action.
	 *
	 * @internal
	 *
	 * @param WC_Order $order Order whose authorization is being cancelled.
	 */
	public function handle_woocommerce_order_action_cancel_authorization( WC_Order $order ): void {
		if ( $this->is_authorized_woopayments_order( $order, true ) ) {
			$this->run_operation( $order, 'cancel' );
		}
	}

	/**
	 * Handle an order transition to completed.
	 *
	 * @internal
	 *
	 * @param int           $order_id         Order ID.
	 * @param WC_Order|null $order            Order object supplied by core.
	 * @param array<mixed>  $status_transition Status transition details.
	 */
	public function handle_woocommerce_order_status_completed( int $order_id, ?WC_Order $order = null, array $status_transition = array() ): void {
		unset( $status_transition );
		$order = $this->resolve_status_order( $order_id, $order );

		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, false ) ) {
			$this->run_operation( $order, 'capture' );
		}
	}

	/**
	 * Handle an order transition to cancelled.
	 *
	 * @internal
	 *
	 * @param int           $order_id         Order ID.
	 * @param WC_Order|null $order            Order object supplied by core.
	 * @param array<mixed>  $status_transition Status transition details.
	 */
	public function handle_woocommerce_order_status_cancelled( int $order_id, ?WC_Order $order = null, array $status_transition = array() ): void {
		unset( $status_transition );
		$order = $this->resolve_status_order( $order_id, $order );

		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order, false ) ) {
			$this->run_operation( $order, 'cancel' );
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
		$gateway_id = (string) $order->get_payment_method();
		$is_gateway = OrderPaymentStore::GATEWAY_ID === $gateway_id
			|| str_starts_with( $gateway_id, WooPaymentsPersistenceProfile::GATEWAY_ID_PREFIX );

		if ( ! $is_gateway || 'requires_capture' !== (string) $order->get_meta( '_intention_status', true ) ) {
			return false;
		}

		return ! $require_unpaid || ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
	}

	/**
	 * Delegate capture or cancel to the shared payment processing path.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 */
	private function run_operation( WC_Order $order, string $operation ): void {
		try {
			$outcome = 'capture' === $operation
				? $this->processing_service->capture(
					PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, (float) $order->get_total() ),
					$this->provider
				)
				: $this->processing_service->cancel(
					PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ),
					$this->provider
				);

			if ( $this->operation_failed_without_note( $outcome, $operation ) ) {
				$this->add_operation_failure_note( $order, $operation );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			$this->add_operation_failure_note( $order, $operation );
		}
	}

	/**
	 * Tell whether an operation failed without shared-path note evidence.
	 *
	 * @param PaymentOutcome $outcome   Operation outcome.
	 * @param string         $operation Capture or cancel.
	 * @return bool
	 */
	private function operation_failed_without_note( PaymentOutcome $outcome, string $operation ): bool {
		$expected_status = 'capture' === $operation ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_CANCELED;
		$data            = $outcome->get_data();

		return $expected_status !== $outcome->get_status()
			&& ( ! isset( $data[ PaymentOutcome::DATA_NOTE ] ) || '' === (string) $data[ PaymentOutcome::DATA_NOTE ] );
	}

	/**
	 * Add the WooPayments-compatible generic operation failure note.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 */
	private function add_operation_failure_note( WC_Order $order, string $operation ): void {
		$note = 'capture' === $operation
			? __( 'Capture authorization <strong>failed</strong> to complete.', 'woocommerce' )
			: __( 'Canceling authorization <strong>failed</strong> to complete.', 'woocommerce' );

		$order->add_order_note( wp_kses( $note, array( 'strong' => array() ) ) );
	}
}
