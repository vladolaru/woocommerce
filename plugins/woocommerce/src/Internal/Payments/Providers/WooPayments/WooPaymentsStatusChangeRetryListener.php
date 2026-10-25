<?php
/**
 * WooPaymentsStatusChangeRetryListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Answers a queued status-change capture or cancel retry that falls due after the built-in WooPayments stopped owning
 * payments, because the WooPayments extension was activated or the WooPayments feature was turned off.
 *
 * WooPaymentsOrderAdminActionsController queues the retry and runs it while the built-in WooPayments owns payments; this
 * listener then does nothing. Otherwise, while the order is still completed (capture) or cancelled (cancel), paid with
 * WooPayments and authorized, it writes the failure note client 11.1.0 writes when such a capture or cancel fails
 * (class-wc-payments-order-service.php:1827-1831, :1880-1884), so the merchant knows the authorization is still open.
 * It changes no status and calls no platform.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsStatusChangeRetryListener implements RegisterHooksInterface {

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Initialize the listener.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $runtime_arbiter Runtime ownership arbiter.
	 */
	final public function init( WooPaymentsRuntimeArbiter $runtime_arbiter ): void {
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Register the retry hook.
	 */
	public function register() {
		add_action( WooPaymentsOrderAdminActionsController::RETRY_STATUS_CHANGE_OPERATION_ACTION, array( $this, 'handle_retry_status_change_operation' ), 10, 3 );
	}

	/**
	 * Note that a retried status-change capture or cancel did not run, when the built-in WooPayments does not own payments.
	 *
	 * @internal
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $operation Capture or cancel.
	 * @param mixed $retry     Number of this retry, from 1.
	 */
	public function handle_retry_status_change_operation( $order_id, $operation = '', $retry = 0 ): void {
		unset( $retry );
		if ( $this->runtime_arbiter->is_builtin_owner() ) {
			return;
		}

		$statuses = array(
			PaymentProcessingService::OPERATION_CAPTURE => OrderStatus::COMPLETED,
			PaymentProcessingService::OPERATION_CANCEL  => OrderStatus::CANCELLED,
		);
		if ( ! is_string( $operation ) || ! isset( $statuses[ $operation ] ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if (
			! $order instanceof WC_Order
			|| ! $order->has_status( $statuses[ $operation ] )
			|| ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() )
			|| 'requires_capture' !== (string) $order->get_meta( '_intention_status', true )
		) {
			return;
		}

		WooPaymentsOrderAdminActionsController::add_operation_failure_note( $order, $operation );
	}
}
