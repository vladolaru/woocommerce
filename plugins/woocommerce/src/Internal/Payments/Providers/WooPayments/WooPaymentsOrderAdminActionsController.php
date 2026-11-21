<?php
/**
 * WooPaymentsOrderAdminActionsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Orders\UnpaidOrderTimeoutMarker;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Adds the WooPayments capture and cancel actions to the order screen, captures or cancels an authorization when its
 * order is completed or cancelled, checks the payment before core cancels an unpaid order, and shows a wallet order's
 * payment title in admin.
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
	 * WooPayments API client, which reads an intent whose stored status is not known to be settled.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private OrderPaymentLock $order_payment_lock;

	/**
	 * Order payment lifecycle service, which writes the intent status read on a status change.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Whether the unpaid-order reads stopped for the rest of core's hold-stock run, so no other order in it is read.
	 *
	 * @var bool
	 */
	private bool $unpaid_order_reads_stopped = false;

	/**
	 * Unpaid-order reads refused in a row in this run, by a refusal that hits the whole store.
	 *
	 * @var int
	 */
	private int $unpaid_order_failures_in_a_row = 0;

	/**
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param PaymentProcessingService            $processing_service          Shared payment processing service.
	 * @param WooPaymentsProvider                 $provider                    WooPayments provider.
	 * @param WooPaymentsFeeDetailsNoteController $fee_details_note_controller Fee details note controller.
	 * @param WooPaymentsActionSchedulerService   $scheduler                   Action Scheduler service.
	 * @param Client                              $api_client                  WooPayments API client.
	 * @param OrderPaymentLock                    $order_payment_lock          Order payment lock.
	 * @param OrderPaymentLifecycleService        $lifecycle_service           Order payment lifecycle service.
	 * @param WooPaymentsLogger                   $logger                      WooPayments logger.
	 */
	final public function init( PaymentProcessingService $processing_service, WooPaymentsProvider $provider, WooPaymentsFeeDetailsNoteController $fee_details_note_controller, WooPaymentsActionSchedulerService $scheduler, Client $api_client, OrderPaymentLock $order_payment_lock, OrderPaymentLifecycleService $lifecycle_service, WooPaymentsLogger $logger ): void {
		$this->processing_service          = $processing_service;
		$this->provider                    = $provider;
		$this->fee_details_note_controller = $fee_details_note_controller;
		$this->scheduler                   = $scheduler;
		$this->api_client                  = $api_client;
		$this->order_payment_lock          = $order_payment_lock;
		$this->lifecycle_service           = $lifecycle_service;
		$this->logger                      = $logger;
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
		add_action( self::RETRY_STATUS_CHANGE_OPERATION_ACTION, array( $this, 'handle_retry_status_change_operation' ), 10, 4 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'handle_woocommerce_cancel_unpaid_order' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_cancel_unpaid_orders', array( $this, 'handle_woocommerce_cancel_unpaid_orders' ), 9 );
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

		if ( ! $theorder instanceof WC_Order || ! $this->is_authorized_woopayments_order( $theorder ) ) {
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
		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order ) ) {
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
		if ( $order instanceof WC_Order && $this->is_authorized_woopayments_order( $order ) ) {
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
		$order  = $this->resolve_status_order( absint( $order_id ), $order instanceof WC_Order ? $order : null );
		$target = $order instanceof WC_Order ? $this->get_status_change_target( $order, PaymentProcessingService::OPERATION_CAPTURE ) : null;

		if ( $order instanceof WC_Order && null !== $target ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CAPTURE, true, 0, $target );
		}
	}

	/**
	 * Handle an order transition to cancelled.
	 *
	 * On core's hold-stock cancel of a WooPayments order, which the unpaid-order check judged, nothing is read, released
	 * or stored: that cancel never releases an authorization.
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
		if ( ! $order instanceof WC_Order || ( UnpaidOrderTimeoutMarker::is_cancelling( $order ) && WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) ) {
			return;
		}

		$target = $this->get_status_change_target( $order, PaymentProcessingService::OPERATION_CANCEL );
		if ( null !== $target ) {
			$this->run_operation( $order, PaymentProcessingService::OPERATION_CANCEL, true, 0, $target );
		}
	}

	/**
	 * Start the unpaid-order reads afresh at the start of each run of core's hold-stock job, which runs at priority 10.
	 *
	 * @internal
	 */
	public function handle_woocommerce_cancel_unpaid_orders(): void {
		$this->unpaid_order_reads_stopped     = false;
		$this->unpaid_order_failures_in_a_row = 0;
	}

	/**
	 * Keep an unpaid WooPayments order that core's hold-stock job would cancel while its payment may have been taken.
	 *
	 * An order with a paid date, or whose order payment lock is held, is kept with no read. An order storing an intent
	 * not canceled has it read. An order keeping a charge key, the record of a later attempt whose outcome is unknown,
	 * has the key's charge looked up when that read found no money of its own or nothing was read. Money that is the
	 * order's own, or a voucher of its own still payable, is applied as the attached-intent check applies it, so an
	 * authorization or a voucher goes On hold, while a disputed payment gets its note and the order is kept. With no
	 * money, the order is cancelled only if, read again, it is unchanged and its lock is free. An unreadable payment
	 * keeps the order until a day after core could first cancel it. Then, read again unchanged with its lock free, an
	 * order whose record shows a payment stays Pending with a note, once, and any other is cancelled with a note, once.
	 *
	 * @internal
	 *
	 * @param mixed $should_cancel Whether core cancels the order, as the earlier callbacks answered.
	 * @param mixed $order         Unpaid order.
	 * @return mixed The answer as it came, or false to keep the order.
	 */
	public function handle_woocommerce_cancel_unpaid_order( $should_cancel, $order = null ) {
		if ( ! $should_cancel || ! $order instanceof WC_Order || ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			return $should_cancel;
		}
		if ( null !== $order->get_date_paid() ) {
			return false;
		}

		$vocabulary = $this->provider->get_persistence_vocabulary();
		if ( $this->order_payment_lock->is_held( $order, $vocabulary ) ) {
			return false;
		}

		$state        = self::get_unpaid_payment_state( $order );
		$reads_intent = 0 === strpos( $state['intent_id'], 'pi_' ) && 'canceled' !== $state['intent_status'];
		if ( ! $reads_intent && '' === $state['charge_key'] ) {
			return $should_cancel;
		}

		$intent_id = $state['intent_id'];
		$intent    = $reads_intent ? $this->read_unpaid_order_intent( $order, $intent_id ) : array();
		if ( null !== $intent && '' !== $state['charge_key'] && ! self::is_unpaid_order_money( $intent, $order ) ) {
			$intent    = $this->find_unpaid_order_charge( $order );
			$intent_id = (string) ( $intent['id'] ?? '' );
		}
		if ( null === $intent ) {
			if ( self::is_within_unpaid_order_window( $order ) || ! $this->is_unpaid_order_unchanged_and_free( $order, $state, $vocabulary ) ) {
				return false;
			}
			$payment_id   = '' !== $state['intent_id'] ? $state['intent_id'] : $state['charge_key'];
			$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
			if ( WooPaymentsIntentCodec::holds_money( $state['intent_status'] ) ) {
				$note_service->add_note_once( $order, __( 'This order was not cancelled as unpaid because its record shows a payment that could not be checked with WooPayments. Check the payment in WooPayments before you cancel or fulfill this order.', 'woocommerce' ), 'unpaid_order_kept_with_payment|' . $payment_id );

				return false;
			}
			$note_service->add_note_once( $order, __( 'The payment for this order could not be checked for a day, so the order is cancelled as unpaid. Before treating it as unpaid, check whether WooPayments received a payment for it.', 'woocommerce' ), 'unpaid_order_cancelled_unchecked|' . $payment_id );

			return $should_cancel;
		}

		if ( self::is_unpaid_order_money( $intent, $order ) ) {
			$this->apply_unpaid_order_payment( $order, $intent_id, $intent );

			return false;
		}

		return $this->is_unpaid_order_unchanged_and_free( $order, $state, $vocabulary ) ? $should_cancel : false;
	}

	/**
	 * Tell whether an intent read or found for an unpaid order is the order's own and holds or may still take money.
	 *
	 * @param array<string,mixed> $intent The intent, or an empty array for none.
	 * @param WC_Order            $order  Unpaid order.
	 * @return bool
	 */
	private static function is_unpaid_order_money( array $intent, WC_Order $order ): bool {
		return WooPaymentsIntentCodec::intent_belongs_to_order( $intent, $order ) && self::holds_or_may_take_money( $intent );
	}

	/**
	 * Tell whether an unpaid order is still within a day after core's hold-stock job could first cancel it: its last change,
	 * or its creation when it has none, plus the hold-stock minutes core's job reads, plus a day.
	 *
	 * @param WC_Order $order Unpaid order.
	 * @return bool
	 */
	private static function is_within_unpaid_order_window( WC_Order $order ): bool {
		$changed = $order->get_date_modified() ?? $order->get_date_created();

		return null !== $changed && time() < $changed->getTimestamp() + absint( get_option( 'woocommerce_hold_stock_minutes', '60' ) ) * MINUTE_IN_SECONDS + DAY_IN_SECONDS;
	}

	/**
	 * Tell whether an unpaid order, read again from its data store, still has the payment values it had before its payment
	 * was read and its order payment lock is free. A re-read that throws, as for a deleted order, is logged and answers no.
	 *
	 * @param WC_Order                                                                                        $order      Unpaid order.
	 * @param array{status:string,intent_id:string,intent_status:string,charge_key:string,date_paid:int|null} $state      Its values before the read.
	 * @param ProviderPersistenceVocabularyInterface                                                          $vocabulary Persistence vocabulary.
	 * @return bool
	 */
	private function is_unpaid_order_unchanged_and_free( WC_Order $order, array $state, ProviderPersistenceVocabularyInterface $vocabulary ): bool {
		try {
			$fresh_order = $this->lifecycle_service->get_fresh_order_from_data_store( $order );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable( sprintf( 'Unpaid order #%d could not be read again before its cancel.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );

			return false;
		}

		return self::get_unpaid_payment_state( $fresh_order ) === $state && ! $this->order_payment_lock->is_held( $fresh_order, $vocabulary );
	}

	/**
	 * Tell whether an unpaid order's intent holds the shopper's money or, as an offline voucher awaiting payment, may still
	 * take it, as the charge lookup counts it (WooPaymentsChargeAmbiguityService::may_hold_or_take_money()).
	 *
	 * @param array<string,mixed> $intent The intent read.
	 * @return bool
	 */
	private static function holds_or_may_take_money( array $intent ): bool {
		return null !== WooPaymentsChargeAmbiguityService::find_intent_with_money( array( $intent ) )
			|| ( 'requires_action' === (string) ( $intent['status'] ?? '' ) && WooPaymentsIntentCodec::is_offline_payment_method_intent( $intent ) );
	}

	/**
	 * Get the values of an unpaid order that a payment changes, to tell whether the order changed while its intent was read.
	 *
	 * @param WC_Order $order Order.
	 * @return array{status:string,intent_id:string,intent_status:string,charge_key:string,date_paid:int|null}
	 */
	private static function get_unpaid_payment_state( WC_Order $order ): array {
		$date_paid = $order->get_date_paid();

		return array(
			'status'        => $order->get_status(),
			'intent_id'     => (string) $order->get_meta( '_intent_id', true ),
			'intent_status' => (string) $order->get_meta( '_intention_status', true ),
			'charge_key'    => (string) $order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ),
			'date_paid'     => null === $date_paid ? null : $date_paid->getTimestamp(),
		);
	}

	/**
	 * Read the intent an unpaid order stores, in the order's own mode, unless the reads stopped for the run.
	 *
	 * @param WC_Order $order     Unpaid order.
	 * @param string   $intent_id Intent the order stores.
	 * @return array<string,mixed>|null The intent, or null when it could not be read.
	 */
	private function read_unpaid_order_intent( WC_Order $order, string $intent_id ): ?array {
		if ( $this->unpaid_order_reads_stopped ) {
			return null;
		}

		try {
			$answer = $this->api_client->get_payment_intention_for_mode( $intent_id, WooPaymentsOrderMode::TEST === (string) $order->get_meta( '_wcpay_mode', true ) );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable( sprintf( 'The intent of unpaid order #%d could not be read before its cancel.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
			$this->note_unpaid_read_outcome( $exception );

			return null;
		}

		$intent = isset( $answer['status'] ) && is_string( $answer['status'] ) && '' !== $answer['status'] ? $answer : null;
		$this->note_unpaid_read_outcome( null, null !== $intent );

		return $intent;
	}

	/**
	 * Count an unpaid-order read toward stopping the reads for the run, by the kind of its answer.
	 *
	 * A transport error or a gateway timeout stops them at once. A refusal that hits the whole store (not authorized,
	 * forbidden, too many requests), another server error, a PHP failure and an answer with no status count. Any other
	 * answer, a not-found or a bad request about one intent included, starts the count again.
	 *
	 * @param Throwable|null $failure The read's failure, or null when it answered.
	 * @param bool           $usable  Whether the answer could be used, when it answered.
	 */
	private function note_unpaid_read_outcome( ?Throwable $failure, bool $usable = true ): void {
		$code      = $failure instanceof ApiException ? $failure->get_http_code() : 0;
		$transport = $failure instanceof ApiException ? (string) ( $failure->get_error_data()['transport_error_code'] ?? '' ) : '';
		if ( 504 === $code || in_array( $transport, array( 'http_request_failed', 'http_request_not_executed' ), true ) ) {
			$this->unpaid_order_reads_stopped = true;
			return;
		}

		$refused                              = null === $failure ? ! $usable : ApiException::leaves_the_answer_unknown( $failure );
		$this->unpaid_order_failures_in_a_row = $refused ? $this->unpaid_order_failures_in_a_row + 1 : 0;
		// Three in a row from different orders is the store being refused; one or two can be single orders' bad luck.
		$this->unpaid_order_reads_stopped = 3 <= $this->unpaid_order_failures_in_a_row;
	}

	/**
	 * Look up what the charge sent under an unpaid order's kept key did, when no stored intent shows money, with the
	 * lookup a refused retry of its checkout uses: the account's intents created from an hour before the key was kept (the
	 * order's creation for a key kept before that time was saved) to an hour after the later of that time and the order's
	 * last change, which every checkout attempt saves before it charges. Core picks the order by that change and nothing
	 * here saves it, so the window does not move between runs.
	 *
	 * Only an intent whose metadata names the order by ID and key can pay it. A key kept in another mode than the store's
	 * cannot be looked up, since the list is read in the store's mode. The lookup tells only whether it failed (a transport
	 * error or a server error, which stops the reads for the run at once) or could not check (any refusal, which never
	 * counts toward stopping them, so a store-wide refusal of the list costs one list read per such order and run).
	 *
	 * @param WC_Order $order Unpaid order.
	 * @return array<string,mixed>|null The order's own intent that holds money, an empty array when the lookup found none,
	 *                                  or null when it could not tell.
	 */
	private function find_unpaid_order_charge( WC_Order $order ): ?array {
		$mode = (string) $order->get_meta( '_wcpay_mode', true );
		if ( $this->unpaid_order_reads_stopped || ( '' !== $mode && wc_get_container()->get( WooPaymentsAccountService::class )->get_order_mode() !== $mode ) ) {
			return null;
		}

		$kept_at  = (int) $order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META, true );
		$created  = $order->get_date_created();
		$since    = 0 < $kept_at ? $kept_at : ( null === $created ? 0 : $created->getTimestamp() );
		$modified = $order->get_date_modified();
		$until    = max( $since, null === $modified ? 0 : $modified->getTimestamp() );
		try {
			$lookup = wc_get_container()->get( WooPaymentsChargeAmbiguityService::class )->find_order_intents( $order, array(), $since, $until );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable( sprintf( 'The charge of unpaid order #%d could not be looked up before its cancel.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
			$lookup = array(
				'status'  => WooPaymentsChargeAmbiguityService::LOOKUP_FAILED,
				'intents' => array(),
			);
		}

		if ( WooPaymentsChargeAmbiguityService::LOOKUP_FAILED === $lookup['status'] ) {
			$this->unpaid_order_reads_stopped = true;
		} elseif ( WooPaymentsChargeAmbiguityService::LOOKUP_DONE === $lookup['status'] ) {
			$this->note_unpaid_read_outcome( null );
		}
		if ( WooPaymentsChargeAmbiguityService::LOOKUP_DONE !== $lookup['status'] ) {
			return null;
		}

		return WooPaymentsChargeAmbiguityService::find_intent_with_money( $lookup['intents'] ) ?? array();
	}

	/**
	 * Apply the money an unpaid order's own intent holds, or note a disputed payment, as the attached-intent check does.
	 *
	 * A failure is logged and keeps the order: its money was found.
	 *
	 * @param WC_Order            $order     Unpaid order.
	 * @param string              $intent_id Intent the order stores.
	 * @param array<string,mixed> $intent    The intent read.
	 */
	private function apply_unpaid_order_payment( WC_Order $order, string $intent_id, array $intent ): void {
		try {
			if ( WooPaymentsIntentCodec::is_disputed( $intent ) ) {
				wc_get_container()->get( WooPaymentsDuplicatePaymentPreventionService::class )->add_disputed_intent_note_once( $order, $intent_id );
			} else {
				wc_get_container()->get( WooPaymentsIntentConfirmationService::class )->apply_attached_payment_intent( $order, $intent );
			}
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( sprintf( 'The payment found on unpaid order #%d could not be applied.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Run a status-change capture or cancel again, when its order is still completed or cancelled and still authorized.
	 *
	 * A retry of an operation on an intent the status change read reads the order's intent again, whatever the order now
	 * stores, so it never acts on the order's transaction ID.
	 *
	 * @internal
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $operation Capture or cancel.
	 * @param mixed $retry     Number of this retry, from 1.
	 * @param mixed $intent_id Intent the status change read, or empty when it acted on the stored authorization.
	 */
	public function handle_retry_status_change_operation( $order_id, $operation, $retry, $intent_id = '' ): void {
		$statuses = array(
			PaymentProcessingService::OPERATION_CAPTURE => OrderStatus::COMPLETED,
			PaymentProcessingService::OPERATION_CANCEL  => OrderStatus::CANCELLED,
		);
		if ( ! is_string( $operation ) || ! isset( $statuses[ $operation ] ) ) {
			return;
		}

		$order  = wc_get_order( absint( $order_id ) );
		$target = $order instanceof WC_Order && $order->has_status( $statuses[ $operation ] ) ? $this->get_status_change_target( $order, $operation, is_string( $intent_id ) && '' !== $intent_id ) : null;
		if ( $order instanceof WC_Order && null !== $target ) {
			$this->run_operation( $order, $operation, true, absint( $retry ), $target );
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
	 * Get what an order moved to Completed or Cancelled has to capture or cancel: its stored authorization, an intent read
	 * from the platform, or nothing.
	 *
	 * An order that stores no intent ID has nothing to do (client 11.3.0 class-wc-payments-order-service.php:2331, :2387).
	 * A stored requires_capture is the stored authorization, with no platform read, and a stored succeeded or canceled is
	 * nothing. Any other stored status, empty included, is read from the platform once, before any order payment lock:
	 * the operation acts on the read intent only when it awaits capture with an uncaptured succeeded charge. Otherwise the
	 * status read is stored. A failed read leaves the order in its new status with the operation's failure note. Either
	 * operation is checked against the order again under its own lock. Client 11.3.0
	 * class-wc-payments-order-service.php:2327-2370, :2381-2429.
	 *
	 * A capture is never sent for an order whose payment method is not a WooPayments one, since it would take money a
	 * second time. A cancel takes none, so it releases such an order's WooPayments authorization, as the client does.
	 *
	 * @param WC_Order $order         Order whose status changed.
	 * @param string   $operation     Capture or cancel.
	 * @param bool     $read_required Whether the platform is read whatever the stored status, for the retry of an
	 *                                operation on a read intent.
	 * @return string|null An empty string for the stored authorization, the read intent's ID, or null for nothing to do.
	 */
	private function get_status_change_target( WC_Order $order, string $operation, bool $read_required = false ): ?string {
		if ( PaymentProcessingService::OPERATION_CAPTURE === $operation && ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			return null;
		}

		$stored_status = (string) $order->get_meta( '_intention_status', true );
		$intent_id     = (string) $order->get_meta( '_intent_id', true );
		if ( '' === $intent_id ) {
			return null;
		}
		if ( ! $read_required && 'requires_capture' === $stored_status ) {
			return '';
		}
		if ( in_array( $stored_status, WooPaymentsPaymentOperationService::SETTLED_INTENT_STATUSES, true ) ) {
			return null;
		}

		try {
			$intent = $this->api_client->get_payment_intention( $intent_id );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable( sprintf( 'The intent of order #%d could not be read on its status change.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
			self::add_operation_failure_note( $order, $operation );
			return null;
		}

		$live_status = isset( $intent['status'] ) && is_string( $intent['status'] ) ? $intent['status'] : '';
		$charge      = WooPaymentsIntentCodec::latest_charge( $intent );
		if ( 'requires_capture' === $live_status && ! empty( $charge ) && empty( $charge['captured'] ) && 'succeeded' === ( $charge['status'] ?? '' ) ) {
			return $intent_id;
		}

		$this->store_intent_status_read( $order, $intent_id, $live_status );

		return null;
	}

	/**
	 * Store the intent status a status change read when it runs no operation, under the order payment lock, while the
	 * order read again under it still stores the read intent and no settled status.
	 *
	 * When another operation holds the lock nothing is stored: the stored status only tells the next status change to
	 * read the intent again.
	 *
	 * @param WC_Order $order       Order whose status changed.
	 * @param string   $intent_id   Intent that was read.
	 * @param string   $live_status Status the platform reported.
	 */
	private function store_intent_status_read( WC_Order $order, string $intent_id, string $live_status ): void {
		if ( '' === $live_status ) {
			return;
		}

		$vocabulary = $this->provider->get_persistence_vocabulary();
		$lock_token = $this->order_payment_lock->claim( $order, $vocabulary, $intent_id, 'payment status update' );
		if ( null === $lock_token ) {
			$this->order_payment_lock->log_refusal( $order, $vocabulary, 'payment status update' );
			return;
		}

		try {
			if ( '' === WooPaymentsPaymentOperationService::get_status_change_refusal( $this->lifecycle_service->get_fresh_order_from_data_store( $order ), $intent_id ) ) {
				$this->lifecycle_service->apply_meta_under_lock( $order, array( '_intention_status' => $live_status ), array() );
			}
		} finally {
			$this->order_payment_lock->release( $order, $vocabulary, $lock_token );
		}
	}

	/**
	 * Tell whether an unpaid order carries a capturable WooPayments authorization, for the order screen's actions.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_authorized_woopayments_order( WC_Order $order ): bool {
		if ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) || 'requires_capture' !== (string) $order->get_meta( '_intention_status', true ) ) {
			return false;
		}

		return ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
	}

	/**
	 * Delegate capture or cancel to the shared payment processing path.
	 *
	 * Client 11.1.0 captures or cancels on the status change with no lock (class-wc-payments-order-service.php:1793-1886).
	 * When the order payment lock refuses it here, it runs again after the current request, on the webhook retry
	 * cadence. The first refusal notes that the operation is pending, and the failure note is written only when the last
	 * retry is refused too.
	 *
	 * A status change acts on the intent it read, or else on the order's stored intent; the order screen's actions act on
	 * the transaction ID. The provider refuses a status change's operation, sending nothing, when the order read under
	 * the operation's lock no longer stores that intent (it is retried) or stores a settled status, or, for a capture, no
	 * longer pays through WooPayments (nothing is left to do).
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $operation        Capture or cancel.
	 * @param bool     $on_status_change Whether the order status change to completed or cancelled triggered the operation.
	 * @param int      $retry            Which retry of the status-change operation this run is, 0 for the status change itself.
	 * @param string   $read_intent_id   Intent the status change read, or empty for the stored authorization.
	 */
	private function run_operation( WC_Order $order, string $operation, bool $on_status_change = false, int $retry = 0, string $read_intent_id = '' ): void {
		$provider_data = array();
		if ( '' !== $read_intent_id ) {
			$provider_data[ WooPaymentsPaymentOperationService::PROVIDER_DATA_READ_INTENT_ID ] = $read_intent_id;
		} elseif ( $on_status_change ) {
			$provider_data[ WooPaymentsPaymentOperationService::PROVIDER_DATA_STORED_INTENT_ID ] = (string) $order->get_meta( '_intent_id', true );
		}
		// A refused status change is retried while a retry slot is left (schedule_retry()).
		$retried = array( PaymentOperationContext::PAYMENT_DATA_RETRIED_ON_LOCK_REFUSAL => $on_status_change && isset( WebhookReliabilityService::RETRY_DELAYS_SECONDS[ $retry ] ) );
		try {
			$outcome = PaymentProcessingService::OPERATION_CAPTURE === $operation
				? $this->processing_service->capture(
					PaymentOperationContext::for_capture(
						$order,
						WooPaymentsPersistenceVocabulary::GATEWAY_ID,
						(float) $order->get_total(),
						$on_status_change ? array_merge( $provider_data, array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ) ) : $provider_data
					)->with_payment_data( $retried ),
					$this->provider
				)
				: $this->processing_service->cancel(
					PaymentOperationContext::for_cancel(
						$order,
						WooPaymentsPersistenceVocabulary::GATEWAY_ID,
						$on_status_change ? array_merge( $provider_data, array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CANCEL_ON_STATUS_CHANGE => true ) ) : $provider_data
					)->with_payment_data( $retried ),
					$this->provider
				);

			$refusal = $outcome->get_data()[ WooPaymentsPaymentOperationService::DATA_STATUS_CHANGE_REFUSAL ] ?? '';
			if ( in_array( $refusal, array( WooPaymentsPaymentOperationService::REFUSAL_INTENT_SETTLED, WooPaymentsPaymentOperationService::REFUSAL_PAYMENT_METHOD_CHANGED ), true ) ) {
				return;
			}

			if ( $on_status_change && ( ! empty( $outcome->get_data()[ PaymentOutcome::DATA_ORDER_PAYMENT_LOCK_REFUSED ] ) || WooPaymentsPaymentOperationService::REFUSAL_INTENT_CHANGED === $refusal ) ) {
				// A status change refused again while its first retry is still queued adds no second pending note.
				$adds_pending_note = 0 === $retry && ! $this->is_first_retry_pending( $order, $operation, $read_intent_id );
				if ( $this->schedule_retry( $order, $operation, $retry, $read_intent_id ) ) {
					if ( $adds_pending_note ) {
						$this->add_operation_pending_note( $order, $operation );
					}
					return;
				}
			}

			if ( ! $on_status_change ) {
				self::add_edit_screen_error_for_a_target_of_another_order( $outcome );
			}

			if ( $this->operation_failed_without_note( $outcome, $operation ) ) {
				self::add_operation_failure_note( $order, $operation );
			}

			// Client 11.1.0 schedules the Fee details job after this capture even when its capture note already exists
			// (class-wc-payments-order-service.php:1862-1874); a job already pending for the order is not scheduled again.
			if ( $on_status_change && PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
				$intent_id = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) $order->get_meta( '_intent_id', true );
				$this->fee_details_note_controller->schedule( $order, $intent_id );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			self::add_operation_failure_note( $order, $operation );
		}
	}

	/**
	 * Schedule the next retry of a status-change operation the order payment lock refused, or that was refused because the
	 * order, read again under the lock, no longer stores the intent the status change acts on.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $operation      Capture or cancel.
	 * @param int      $retry          Which retry the refused run was, 0 for the status change itself.
	 * @param string   $read_intent_id Intent the status change read, or empty for the stored authorization.
	 * @return bool False when no retry is left.
	 */
	private function schedule_retry( WC_Order $order, string $operation, int $retry, string $read_intent_id ): bool {
		$delays = WebhookReliabilityService::RETRY_DELAYS_SECONDS;
		if ( ! isset( $delays[ $retry ] ) ) {
			return false;
		}

		$this->scheduler->schedule_job( self::RETRY_STATUS_CHANGE_OPERATION_ACTION, $this->get_retry_args( $order, $operation, $retry + 1, $read_intent_id ), time() + $delays[ $retry ] );

		return true;
	}

	/**
	 * Tell whether the first retry of a status-change operation is already queued for the order.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $operation      Capture or cancel.
	 * @param string   $read_intent_id Intent the status change read, or empty for the stored authorization.
	 * @return bool
	 */
	private function is_first_retry_pending( WC_Order $order, string $operation, string $read_intent_id ): bool {
		return did_action( 'action_scheduler_init' ) && as_has_scheduled_action( self::RETRY_STATUS_CHANGE_OPERATION_ACTION, $this->get_retry_args( $order, $operation, 1, $read_intent_id ), WooPaymentsActionSchedulerService::GROUP_ID );
	}

	/**
	 * Get the arguments of a status-change operation's retry job: the read intent is added only for an operation on one.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $operation      Capture or cancel.
	 * @param int      $retry          Retry number, from 1.
	 * @param string   $read_intent_id Intent the status change read, or empty for the stored authorization.
	 * @return array<string,int|string>
	 */
	private function get_retry_args( WC_Order $order, string $operation, int $retry, string $read_intent_id ): array {
		$args = array(
			'order_id'  => $order->get_id(),
			'operation' => $operation,
			'retry'     => $retry,
		);
		if ( '' !== $read_intent_id ) {
			$args['intent_id'] = $read_intent_id;
		}

		return $args;
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
	 * Show the error of an order screen capture or cancel that was not sent because its intent is not shown to be the
	 * order's own, on the order screen the save reloads (WC_Admin_Meta_Boxes stores it at shutdown and prints it on the
	 * next admin notices).
	 *
	 * @param PaymentOutcome $outcome Operation outcome.
	 */
	private static function add_edit_screen_error_for_a_target_of_another_order( PaymentOutcome $outcome ): void {
		$data = $outcome->get_data();
		if ( WooPaymentsPaymentOperationService::ERROR_OPERATION_TARGET_OF_ANOTHER_ORDER !== ( $data[ PaymentOutcome::DATA_ERROR_CODE ] ?? '' ) || ! is_admin() || ! class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			return;
		}

		\WC_Admin_Meta_Boxes::add_error( (string) ( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? '' ) );
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
	 * @since 11.2.0
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 */
	public static function add_operation_failure_note( WC_Order $order, string $operation ): void {
		$note = PaymentProcessingService::OPERATION_CAPTURE === $operation
			? __( 'Capture authorization <strong>failed</strong> to complete.', 'woocommerce' )
			: __( 'Canceling authorization <strong>failed</strong> to complete.', 'woocommerce' );

		$order->add_order_note( wp_kses( $note, array( 'strong' => array() ) ) );
	}
}
