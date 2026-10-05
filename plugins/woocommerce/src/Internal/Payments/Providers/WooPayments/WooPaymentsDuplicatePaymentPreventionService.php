<?php
/**
 * WooPaymentsDuplicatePaymentPreventionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use Throwable;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

/**
 * Prevents duplicate WooPayments charges for the same checkout session.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDuplicatePaymentPreventionService implements RegisterHooksInterface {

	/**
	 * Session key used by the standalone WooPayments extension for the currently processing order.
	 */
	public const SESSION_KEY_PROCESSING_ORDER = 'wcpay_processing_order';

	/**
	 * Return URL flag for redirecting to a previous paid order.
	 */
	public const FLAG_PREVIOUS_ORDER_PAID = 'wcpay_paid_for_previous_order';

	/**
	 * Return URL flag for redirecting after a successful attached intent.
	 */
	public const FLAG_PREVIOUS_SUCCESSFUL_INTENT = 'wcpay_previous_successful_intent';

	/**
	 * Error code of the refusal for an order whose attached payment is disputed.
	 */
	public const ERROR_DISPUTED_INTENT = 'duplicate_payment_disputed';

	/**
	 * Order meta holding the attached PaymentIntent the dispute note was written for.
	 *
	 * Keyed by the intent, so a copy of the meta on another order (Subscriptions copies order meta) never hides that order's
	 * note: its attached intent differs.
	 */
	private const DISPUTED_INTENT_NOTED_META = '_wcpay_disputed_intent_noted';

	/**
	 * Payment methods paid offline after checkout, through a voucher.
	 *
	 * Mirrors the plugin's Payment_Method::OFFLINE_PAYMENT_METHODS (client 11.1.0 `includes/constants/class-payment-method.php:61-63`).
	 */
	private const OFFLINE_PAYMENT_METHODS = array( 'multibanco' );

	/**
	 * WooCommerce session.
	 *
	 * @var \WC_Session|null
	 */
	private ?\WC_Session $session;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter|null
	 */
	private ?NativePaymentsRuntimeArbiter $arbiter = null;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier|null
	 */
	private ?WooPaymentsOrderEffectApplier $order_effect_applier = null;

	/**
	 * Constructor.
	 *
	 * @param \WC_Session|null $session Optional WooCommerce session.
	 */
	public function __construct( ?\WC_Session $session = null ) {
		$this->session = $session;
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient               $api_client         Native WooPayments API client.
	 * @param OrderPaymentLifecycleService       $lifecycle_service Native order payment lifecycle service.
	 * @param WooPaymentsOrderDataService        $order_data_service WooPayments order data service.
	 * @param NativePaymentsRuntimeArbiter|null  $arbiter            Optional runtime owner arbiter.
	 * @param WooPaymentsOrderEffectApplier|null $order_effect_applier Optional order effect applier.
	 */
	final public function init(
		WooPaymentsApiClient $api_client,
		OrderPaymentLifecycleService $lifecycle_service,
		WooPaymentsOrderDataService $order_data_service,
		?NativePaymentsRuntimeArbiter $arbiter = null,
		?WooPaymentsOrderEffectApplier $order_effect_applier = null
	): void {
		$this->api_client           = $api_client;
		$this->lifecycle_service    = $lifecycle_service;
		$this->order_data_service   = $order_data_service;
		$this->arbiter              = $arbiter;
		$this->order_effect_applier = $order_effect_applier;
	}

	/**
	 * Register session cleanup after WooCommerce completes a payment and when the shopper lands on the order-received page.
	 *
	 * @internal
	 */
	public function register() {
		if ( ! $this->get_runtime_arbiter()->should_native_register() ) {
			return;
		}

		add_action( 'woocommerce_payment_complete', array( $this, 'handle_woocommerce_payment_complete' ) );
		// Priority 21 runs right after core's wc_clear_cart_after_payment(), as in client 11.1.0 (src/Internal/Service/DuplicatePaymentPreventionService.php:66-69).
		add_action( 'template_redirect', array( $this, 'clear_session_processing_order_after_landing_order_received_page' ), 21 );
	}

	/**
	 * Clear the order from duplicate-payment session tracking once the shopper reaches its order-received page.
	 *
	 * Client 11.1.0 src/Internal/Service/DuplicatePaymentPreventionService.php:100-107.
	 *
	 * @internal
	 */
	public function clear_session_processing_order_after_landing_order_received_page(): void {
		global $wp;

		if ( is_order_received_page() && isset( $wp->query_vars['order-received'] ) ) {
			$this->remove_session_processing_order( absint( $wp->query_vars['order-received'] ) );
		}
	}

	/**
	 * Clear an order from duplicate-payment session tracking when its checkout ended with an offline voucher.
	 *
	 * The client clears it when the intent succeeded, or is `requires_action` for an offline payment method (gw:2021,
	 * 2147-2148, `Payment_Information::is_offline_payment_method()`). A succeeded intent is cleared through
	 * `woocommerce_payment_complete`.
	 *
	 * @param int            $order_id          Order ID.
	 * @param string         $payment_method_id Payment method of the gateway that took the payment, such as `multibanco`.
	 * @param PaymentOutcome $outcome           Checkout outcome.
	 */
	public function maybe_remove_session_processing_order_for_offline_voucher( int $order_id, string $payment_method_id, PaymentOutcome $outcome ): void {
		$data = $outcome->get_data();
		$meta = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] ) ? $data[ PaymentOutcome::DATA_META ] : array();

		if ( 'requires_action' === ( $meta['_intention_status'] ?? '' ) && in_array( $payment_method_id, self::OFFLINE_PAYMENT_METHODS, true ) ) {
			$this->remove_session_processing_order( $order_id );
		}
	}

	/**
	 * Clear a completed order from duplicate-payment session tracking.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Completed order ID.
	 */
	public function handle_woocommerce_payment_complete( $order_id ): void {
		$this->remove_session_processing_order( absint( $order_id ) );
	}

	/**
	 * Redirect to a paid session order when the current order duplicates the same cart content.
	 *
	 * On a match it deletes the current pending order, adds a note about it to the paid session order,
	 * clears the session marker and returns a success redirect to the paid order.
	 *
	 * @param WC_Order           $current_order Current order.
	 * @param WC_Payment_Gateway $gateway       Gateway used to build the return URL.
	 * @return array<string,string>|null Success redirect on a match, null otherwise.
	 */
	public function check_against_session_processing_order( WC_Order $current_order, WC_Payment_Gateway $gateway ): ?array {
		$session_order_id = $this->get_session_processing_order();
		if ( null === $session_order_id ) {
			return null;
		}

		$session_order = wc_get_order( $session_order_id );
		if ( ! $session_order instanceof WC_Order ) {
			return null;
		}

		if ( $current_order->get_cart_hash() !== $session_order->get_cart_hash() ) {
			return null;
		}

		if ( ! $session_order->has_status( wc_get_is_paid_statuses() ) ) {
			return null;
		}

		if ( ! $current_order->has_status( wc_get_is_pending_statuses() ) ) {
			return null;
		}

		if ( $session_order->get_id() === $current_order->get_id() ) {
			return null;
		}

		if ( $session_order->get_customer_id() !== $current_order->get_customer_id() ) {
			return null;
		}

		$session_order->add_order_note(
			sprintf(
				/* translators: %d: Order ID. */
				__( 'WooCommerce Payments: detected and deleted order ID %d, which has duplicate cart content with this order.', 'woocommerce' ),
				$current_order->get_id()
			)
		);
		$current_order->delete();

		$this->remove_session_processing_order( $session_order_id );

		return $this->success_redirect( $gateway, $session_order, self::FLAG_PREVIOUS_ORDER_PAID );
	}

	/**
	 * Store the current processing order ID in the session.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function maybe_update_session_processing_order( int $order_id ): void {
		$session = $this->get_session();
		if ( $session instanceof \WC_Session ) {
			$session->set( self::SESSION_KEY_PROCESSING_ORDER, $order_id );
		}
	}

	/**
	 * Remove the processing order ID when it matches the supplied order ID.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function remove_session_processing_order( int $order_id ): void {
		$session_order_id = $this->get_session_processing_order();
		$session          = $this->get_session();
		if ( $order_id === $session_order_id && $session instanceof \WC_Session ) {
			$session->set( self::SESSION_KEY_PROCESSING_ORDER, null );
		}
	}

	/**
	 * Redirect when the current order already has an authorized PaymentIntent attached.
	 *
	 * A failed intent fetch lets checkout go on, as on the client, except for a PHP Error, which refuses checkout.
	 *
	 * @param WC_Order           $order   Current order.
	 * @param WC_Payment_Gateway $gateway Gateway used to build the return URL.
	 * @return array<string,string>|WP_Error|null
	 */
	public function check_payment_intent_attached_to_order_succeeded( WC_Order $order, WC_Payment_Gateway $gateway ) {
		$intent_id = (string) $order->get_meta( '_intent_id', true );
		if ( '' === $intent_id || 0 !== strpos( $intent_id, 'pi_' ) ) {
			return null;
		}

		try {
			$intent = $this->get_api_client()->get_payment_intention( $intent_id );
		} catch ( Throwable $exception ) {
			// Client 11.1.0 duplicate-payment-prevention-service:100-101 logs this through its gated Logger.
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable(
				'Failed to fetch attached native WooPayments payment intent: ' . $exception->getMessage(),
				$exception,
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => $intent_id,
				)
			);

			// Decided divergence (monitor ruling 2026-10-04 (1)): client dpps:100 catches only Exception, so a PHP Error
			// fatals there and nothing is charged. Native keeps that outcome without the fatal instead of charging again.
			if ( ! $exception instanceof Exception ) {
				return new WP_Error( 'duplicate_payment_check_failed', __( "We're not able to process this payment. Please try again later.", 'woocommerce' ) );
			}

			return null;
		}

		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		if ( ! WooPaymentsIntentCodec::is_authorized_native_intent_status( $status ) ) {
			return null;
		}

		if ( ! $this->intent_belongs_to_order( $intent, $order ) ) {
			return null;
		}

		// A full refund or a dispute leaves the intent `succeeded`; client 11.1.0 reads only the status
		// (class-duplicate-payment-prevention-service.php:105-107) and completes the order from it. A fully refunded intent is
		// no payment of the order, so checkout charges again. A disputed one is refused: the money may still come back.
		if ( WooPaymentsIntentCodec::is_fully_refunded( $intent ) ) {
			return null;
		}

		if ( WooPaymentsIntentCodec::is_disputed( $intent ) ) {
			$this->add_disputed_intent_note_once( $order, $intent_id );

			return new WP_Error( self::ERROR_DISPUTED_INTENT, __( "This order's payment is under review. Please contact the store.", 'woocommerce' ) );
		}

		if ( 'succeeded' === $status ) {
			$this->remove_session_processing_order( $order->get_id() );
		}

		$amount_error = $this->get_amount_mismatch_error( $intent, $order );
		if ( $amount_error instanceof WP_Error ) {
			return $amount_error;
		}

		$this->apply_attached_intent_lifecycle( $intent, $order );

		return $this->success_redirect( $gateway, $order, self::FLAG_PREVIOUS_SUCCESSFUL_INTENT );
	}

	/**
	 * Tell the merchant, once for each intent, that the order's attached payment is disputed.
	 *
	 * Every submit for the order is refused while the dispute stands, so the note is written only when the order has no
	 * note yet for this intent.
	 *
	 * @param WC_Order $order     Order being paid.
	 * @param string   $intent_id The attached PaymentIntent.
	 */
	private function add_disputed_intent_note_once( WC_Order $order, string $intent_id ): void {
		if ( $intent_id === (string) $order->get_meta( self::DISPUTED_INTENT_NOTED_META, true ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: PaymentIntent ID. */
				__( 'The payment attached to this order (%s) is disputed, so the customer was not charged again. Check the dispute in WooPayments before the customer pays for this order.', 'woocommerce' ),
				$intent_id
			)
		);
		$order->update_meta_data( self::DISPUTED_INTENT_NOTED_META, $intent_id );
		$order->save_meta_data();
	}

	/**
	 * Stop a second payment for an order whose stored status is already paid.
	 *
	 * Last-resort guard for a resubmission that reuses the same order.
	 * `check_payment_intent_attached_to_order_succeeded()` covers that case with a richer
	 * response, but it needs `_intent_id` to have been written and the intent lookup to
	 * succeed, and returns silently otherwise — including on an API timeout, which is when
	 * shoppers resubmit. This reads the stored order status instead, so it holds when the
	 * platform does not respond.
	 *
	 * The gateway passes true for every validated payment-method change, to a new method or a saved token, as the client
	 * skips the check for any change (client 11.1.0 `includes/class-duplicate-payment-prevention-service.php:226`,
	 * `includes/compat/subscriptions/trait-wc-payments-subscriptions-utilities.php:38-43`): a store can filter a
	 * subscription status into the paid statuses.
	 *
	 * @param WC_Order           $order                  Current order in process_payment.
	 * @param WC_Payment_Gateway $gateway                Gateway used to build the return URL.
	 * @param bool               $is_subscription_change Whether this request changes a subscription's payment method.
	 * @return array<string,string>|null A successful response when the order was already paid, null if not.
	 */
	public function check_order_already_paid( WC_Order $order, WC_Payment_Gateway $gateway, bool $is_subscription_change = false ): ?array {
		// A subscription payment-method change re-runs payment processing against an entity
		// that was already paid once, so it must not be treated as a duplicate.
		if ( $is_subscription_change ) {
			return null;
		}

		// The instance loaded by process_payment() may be stale, so the stored status decides.
		$status = $this->get_stored_order_status( $order->get_id() ) ?? $order->get_status();

		if ( ! in_array( $status, wc_get_is_paid_statuses(), true ) ) {
			return null;
		}

		// A store can declare one of its paid statuses still payable — deposit and
		// partial-payment extensions do exactly that, collecting the balance through
		// pay-for-order. Defer to that declaration rather than blocking a payment the store
		// expects. `WC_Order::needs_payment()` cannot answer this: it reads the in-memory
		// status, which is the stale value this guard exists to look past.
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Documented in includes/class-wc-order.php.
		$payable_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( OrderStatus::PENDING, OrderStatus::FAILED ), $order );

		if ( in_array( $status, (array) $payable_statuses, true ) ) {
			return null;
		}

		/**
		 * Filters whether a payment for an already-paid order should be prevented.
		 *
		 * Escape hatch for a flow that legitimately re-runs payment against an entity that
		 * was already paid, and cannot say so through the order's status — the subscription
		 * payment-method change exempted above is one such flow. Returning false lets the
		 * payment through, so only do it for a specific flow you recognise: a blanket false
		 * restores the double-charge this guard exists to stop.
		 *
		 * Kept under the WooPayments plugin's filter name: extensions already hook it.
		 *
		 * @since 11.0.0
		 *
		 * @param bool     $should_prevent Whether to stop the payment. Default true.
		 * @param WC_Order $order          The order about to be paid a second time.
		 * @param string   $status         The order's stored status.
		 */
		if ( ! apply_filters( 'wcpay_should_prevent_payment_for_paid_order', true, $order, $status ) ) {
			return null;
		}

		return $this->prevent_payment_for_paid_order( $order, $gateway );
	}

	/**
	 * Answer a checkout for an order that was already paid, without charging.
	 *
	 * Notes the prevented payment on the order and sends the shopper to the order-received page. The processing
	 * service returns the same answer when another request paid the order while this one waited for its lock.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order           $order   Order that was already paid.
	 * @param WC_Payment_Gateway $gateway Gateway used to build the return URL.
	 * @return array<string,string>
	 */
	public function prevent_payment_for_paid_order( WC_Order $order, WC_Payment_Gateway $gateway ): array {
		$order->add_order_note(
			__( 'WooPayments: detected and prevented a second payment for this order, which had already been paid.', 'woocommerce' )
		);

		$this->remove_session_processing_order( $order->get_id() );

		return $this->success_redirect( $gateway, $order, self::FLAG_PREVIOUS_SUCCESSFUL_INTENT );
	}

	/**
	 * Read an order's status straight from its storage table.
	 *
	 * `wc_get_order()` can serve a cached instance whose status predates the payment this
	 * guard is looking for, so the read bypasses object caching on purpose.
	 *
	 * @param int $order_id Order ID.
	 * @return string|null The unprefixed stored status, or null when the order row is missing.
	 */
	private function get_stored_order_status( int $order_id ): ?string {
		global $wpdb;

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders_table = OrderUtil::get_table_for_orders();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached by design; the table name comes from OrderUtil, not from input.
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$orders_table} WHERE id = %d", $order_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached by design.
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
		}

		if ( null === $status ) {
			return null;
		}

		return OrderUtil::remove_status_prefix( (string) $status );
	}

	/**
	 * Tell whether intent metadata points to the supplied order.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @param WC_Order            $order  Order.
	 * @return bool
	 */
	private function intent_belongs_to_order( array $intent, WC_Order $order ): bool {
		$metadata                     = is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : array();
		$intent_meta_order_id_raw     = $metadata['order_id'] ?? '';
		$intent_meta_order_id         = is_numeric( $intent_meta_order_id_raw ) ? (int) $intent_meta_order_id_raw : 0;
		$intent_meta_order_number_raw = $metadata['order_number'] ?? '';
		$intent_meta_order_number     = is_numeric( $intent_meta_order_number_raw ) ? (int) $intent_meta_order_number_raw : 0;
		$paid_on_woopay               = filter_var( $metadata['paid_on_woopay'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$is_woopay_order              = $order->get_id() === $intent_meta_order_number;

		return ( $paid_on_woopay && $is_woopay_order ) || $intent_meta_order_id === $order->get_id();
	}

	/**
	 * Get an amount mismatch error when the intent amount differs from the order total.
	 *
	 * Also used when a charge refused under a kept key finds that the earlier request paid the order; that caller also
	 * compares the currency, since the order may have been reused in another currency. The duplicate-payment guards
	 * compare the amount only, as client 11.1.0 does.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $intent           Intent response.
	 * @param WC_Order            $order            Order.
	 * @param bool                $compare_currency Whether an intent in another currency than the order's is a mismatch too.
	 * @return WP_Error|null
	 */
	public function get_amount_mismatch_error( array $intent, WC_Order $order, bool $compare_currency = false ): ?WP_Error {
		$charged_amount       = isset( $intent['amount'] ) && is_numeric( $intent['amount'] ) ? (int) $intent['amount'] : 0;
		$order_total_in_cents = $this->get_order_data_service()->prepare_amount( (float) $order->get_total(), (string) $order->get_currency() );
		$charged_currency     = (string) $order->get_currency();
		$intent_currency      = isset( $intent['currency'] ) && is_string( $intent['currency'] ) ? strtoupper( $intent['currency'] ) : '';
		if ( $compare_currency && strtoupper( $charged_currency ) !== $intent_currency ) {
			$charged_currency = $intent_currency;
		} elseif ( $order_total_in_cents === $charged_amount ) {
			return null;
		}

		return new WP_Error(
			'duplicate_payment_amount_mismatch',
			sprintf(
				/* translators: 1: charged amount, 2: current order total. */
				__( 'This order was already paid for %1$s, but the order total has since changed to %2$s, so we prevented an overpayment. Please create a new order for any additional items.', 'woocommerce' ),
				wc_price( WooPaymentsCurrencyUtils::amount_from_minor_units( $charged_amount, $charged_currency ), array( 'currency' => $charged_currency ) ),
				wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) )
			)
		);
	}

	/**
	 * Apply the attached intent to the order lifecycle.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @param WC_Order            $order  Order.
	 * @return void
	 */
	private function apply_attached_intent_lifecycle( array $intent, WC_Order $order ): void {
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$outcome               = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			WooPaymentsIntentMappingContext::for_native(
				$order->get_id(),
				$order->get_checkout_order_received_url(),
				(string) $order->get_meta( '_payment_method_id', true ),
				(string) $order->get_meta( '_stripe_customer_id', true ),
				'',
				'pi',
				$provider_redirect_url
			)
		);
		$outcome               = $this->get_order_effect_applier()->enrich_outcome_for_lifecycle(
			PaymentContext::for_checkout( $order, (string) $order->get_payment_method(), $outcome->get_payment_method_id() ),
			$outcome,
			WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )->without_fee_meta()
		);

		$data             = $outcome->get_data();
		$note             = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE ]
			? $data[ PaymentOutcome::DATA_NOTE ]
			: null;
		$note_type        = isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			? $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			: null;
		$note_equivalents = isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) && is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] )
			? $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]
			: array();
		$profile          = new WooPaymentsPersistenceProfile();

		$this->get_lifecycle_service()->apply(
			$order,
			new PaymentLifecycleEvent(
				self::get_lifecycle_status( $outcome ),
				'' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : null,
				$profile->get_outcome_meta( $outcome ),
				array(),
				$note,
				$note_type,
				$note_equivalents
			),
			$profile
		);
	}

	/**
	 * Get the WooPayments order effect applier.
	 *
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function get_order_effect_applier(): WooPaymentsOrderEffectApplier {
		if ( null === $this->order_effect_applier ) {
			$this->order_effect_applier = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		}

		return $this->order_effect_applier;
	}

	/**
	 * Map a provider outcome status to an order lifecycle status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private static function get_lifecycle_status( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return PaymentLifecycleEvent::STATUS_COMPLETED;

			case PaymentOutcome::STATUS_AUTHORIZED:
				return PaymentLifecycleEvent::STATUS_AUTHORIZED;

			case PaymentOutcome::STATUS_FAILED:
				return PaymentLifecycleEvent::STATUS_FAILED;

			case PaymentOutcome::STATUS_CANCELED:
				return PaymentLifecycleEvent::STATUS_CANCELED;

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return PaymentLifecycleEvent::STATUS_STARTED;
		}

		return PaymentLifecycleEvent::STATUS_FAILED;
	}

	/**
	 * Build a successful checkout redirect result.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway used to build the return URL.
	 * @param WC_Order           $order   Order.
	 * @param string             $flag    Query-string flag.
	 * @return array<string,string>
	 */
	private function success_redirect( WC_Payment_Gateway $gateway, WC_Order $order, string $flag ): array {
		return array(
			'result'   => 'success',
			'redirect' => add_query_arg( $flag, 'yes', $gateway->get_return_url( $order ) ),
		);
	}

	/**
	 * Get the processing order ID for the current session.
	 *
	 * @return int|null
	 */
	private function get_session_processing_order(): ?int {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return null;
		}

		$value = $session->get( self::SESSION_KEY_PROCESSING_ORDER );

		return null === $value ? null : absint( $value );
	}

	/**
	 * Get the runtime owner arbiter.
	 *
	 * @return NativePaymentsRuntimeArbiter
	 */
	private function get_runtime_arbiter(): NativePaymentsRuntimeArbiter {
		if ( ! $this->arbiter instanceof NativePaymentsRuntimeArbiter ) {
			$this->arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		}

		return $this->arbiter;
	}

	/**
	 * Get the native WooPayments API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		if ( ! isset( $this->api_client ) ) {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		}

		return $this->api_client;
	}

	/**
	 * Get the native order payment lifecycle service.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function get_lifecycle_service(): OrderPaymentLifecycleService {
		if ( ! isset( $this->lifecycle_service ) ) {
			$this->lifecycle_service = wc_get_container()->get( OrderPaymentLifecycleService::class );
		}

		return $this->lifecycle_service;
	}

	/**
	 * Get the WooPayments order data service.
	 *
	 * @return WooPaymentsOrderDataService
	 */
	private function get_order_data_service(): WooPaymentsOrderDataService {
		if ( ! isset( $this->order_data_service ) ) {
			$this->order_data_service = wc_get_container()->get( WooPaymentsOrderDataService::class );
		}

		return $this->order_data_service;
	}

	/**
	 * Get the current WooCommerce session.
	 *
	 * @return \WC_Session|null
	 */
	private function get_session(): ?\WC_Session {
		if ( $this->session instanceof \WC_Session ) {
			return $this->session;
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && $woocommerce->session instanceof \WC_Session ) {
			$this->session = $woocommerce->session;
		}

		return $this->session instanceof \WC_Session ? $this->session : null;
	}
}
