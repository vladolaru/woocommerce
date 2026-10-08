<?php
/**
 * WooPaymentsOrderAdminActionsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Restores WooPayments authorization actions and wallet payment titles in the WooCommerce order workflow.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderAdminActionsController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

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
	 * @param WooPaymentsRuntimeArbiter $arbiter            Runtime owner arbiter.
	 * @param PaymentProcessingService  $processing_service Shared payment processing service.
	 * @param WooPaymentsProvider       $provider           WooPayments provider.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, PaymentProcessingService $processing_service, WooPaymentsProvider $provider ): void {
		$this->arbiter            = $arbiter;
		$this->processing_service = $processing_service;
		$this->provider           = $provider;
	}

	/**
	 * Register order workflow hooks when native owns the runtime.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
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
		if ( is_admin() && false === has_filter( 'woocommerce_gateway_title', array( $this, 'handle_woocommerce_gateway_title' ) ) ) {
			add_filter( 'woocommerce_gateway_title', array( $this, 'handle_woocommerce_gateway_title' ), 10, 2 );
		}
	}

	/**
	 * Show an Apple Pay or Google Pay order's payment title in place of the card gateway's title in admin.
	 *
	 * The order screen's "Payment via" line and payment method select read the gateway title, so a wallet order would read
	 * as a card order. Ported from client 11.1.0 `filter_gateway_title()` (class-wc-payments-express-checkout-button-handler.php:415-437),
	 * with the order found as its `get_current_order()` does (class-wc-payments-express-checkout-button-helper.php:520-533).
	 * Unlike the client, only a WooPayments order's title is used: the select lists every gateway, so another gateway's
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

		// "Payment Request" is the title of orders placed with the client's legacy payment request buttons.
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
			$this->run_operation( $order, 'capture' );
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
			$this->run_operation( $order, 'cancel' );
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
			$this->run_operation( $order, 'capture', true );
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
		$is_gateway = WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway_id
			|| str_starts_with( $gateway_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX );

		if ( ! $is_gateway || 'requires_capture' !== (string) $order->get_meta( '_intention_status', true ) ) {
			return false;
		}

		return ! $require_unpaid || ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
	}

	/**
	 * Delegate capture or cancel to the shared payment processing path.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $operation        Capture or cancel.
	 * @param bool     $on_status_change Whether the order status change to completed triggered the capture.
	 */
	private function run_operation( WC_Order $order, string $operation, bool $on_status_change = false ): void {
		try {
			$outcome = 'capture' === $operation
				? $this->processing_service->capture(
					PaymentOperationContext::for_capture(
						$order,
						WooPaymentsPersistenceVocabulary::GATEWAY_ID,
						(float) $order->get_total(),
						$on_status_change ? array( WooPaymentsProviderGatewayAdapter::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ) : array()
					),
					$this->provider
				)
				: $this->processing_service->cancel(
					PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
					$this->provider
				);

			if ( $this->operation_failed_without_note( $outcome, $operation ) ) {
				$this->add_operation_failure_note( $order, $operation );
			}

			// The client schedules the Fee details job after this capture even when its capture note already exists (class-wc-payments-order-service.php:1862-1874).
			if ( $on_status_change && PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
				$intent_id = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) $order->get_meta( '_intent_id', true );
				wc_get_container()->get( WooPaymentsFeeDetailsNoteScheduler::class )->schedule( $order, $intent_id );
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
