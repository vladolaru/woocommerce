<?php
/**
 * WooPaymentsEventIngestor class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use InvalidArgumentException;
use Throwable;
use WC_Order;
use WC_Payment_Token;

/**
 * Ingests WooPayments provider webhook events into native payment lifecycle effects.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsEventIngestor {

	/**
	 * Filter that reports whether the native WooPayments runtime is in live mode.
	 *
	 * @var string
	 */
	const FILTER_LIVE_MODE = 'woocommerce_woopayments_live_mode';

	/**
	 * Transient prefix for the per-event "already processed" idempotency marker.
	 *
	 * @var string
	 */
	private const PROCESSED_EVENT_TRANSIENT_PREFIX = 'wcpay_processed_event_';

	/**
	 * Object-cache key prefix for the per-event atomic in-flight processing claim.
	 *
	 * @var string
	 */
	private const CLAIMED_EVENT_CACHE_PREFIX = 'wcpay_claimed_event_';

	/**
	 * Object-cache group for the per-event atomic in-flight processing claim.
	 *
	 * @var string
	 */
	private const CLAIMED_EVENT_CACHE_GROUP = 'woopayments_events';

	/**
	 * TTL for the per-event "already processed" idempotency marker, in seconds.
	 *
	 * @var int
	 */
	private const PROCESSED_EVENT_TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Known WooPayments event types whose side effects still block native cutover.
	 *
	 * @var string[]
	 */
	const KNOWN_UNHANDLED_EVENT_TYPES = array();

	/**
	 * Retired Stripe Billing invoice event types.
	 *
	 * These are Bucket D events: native WooPayments must not implement their legacy engine.
	 * If one reaches native, cutover data-safety failed and the event must be alarmed instead
	 * of silently falling through as an ordinary no-op.
	 *
	 * @var string[]
	 */
	private const RETIRED_STRIPE_BILLING_INVOICE_EVENT_TYPES = array(
		'invoice.paid',
		'invoice.payment_failed',
		'invoice.upcoming',
	);

	/**
	 * Order lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Dispute event handler.
	 *
	 * @var WooPaymentsDisputeEventHandler
	 */
	private WooPaymentsDisputeEventHandler $dispute_event_handler;

	/**
	 * Refund event handler.
	 *
	 * @var WooPaymentsRefundEventHandler
	 */
	private WooPaymentsRefundEventHandler $refund_event_handler;

	/**
	 * Account event handler.
	 *
	 * @var WooPaymentsAccountEventHandler
	 */
	private WooPaymentsAccountEventHandler $account_event_handler;

	/**
	 * Notification event handler.
	 *
	 * @var WooPaymentsNotificationEventHandler
	 */
	private WooPaymentsNotificationEventHandler $notification_event_handler;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService|null
	 */
	private ?WooPaymentsOrderDataService $order_data_service = null;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier|null
	 */
	private ?WooPaymentsOrderEffectApplier $order_effect_applier = null;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $order_note_service = null;

	/**
	 * WooPayments admin menu badge service.
	 *
	 * @var WooPaymentsAdminMenuBadgeService|null
	 */
	private ?WooPaymentsAdminMenuBadgeService $admin_menu_badge_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLifecycleService          $lifecycle_service          Order lifecycle service.
	 * @param LegacyProxy                           $legacy_proxy               Legacy proxy.
	 * @param WooPaymentsLegacyRuntime              $legacy_runtime             WooPayments legacy runtime.
	 * @param WooPaymentsApiClient                  $api_client                 Native WooPayments API client.
	 * @param WooPaymentsDisputeEventHandler        $dispute_event_handler      Dispute event handler.
	 * @param WooPaymentsRefundEventHandler         $refund_event_handler       Refund event handler.
	 * @param WooPaymentsAccountEventHandler        $account_event_handler      Account event handler.
	 * @param WooPaymentsNotificationEventHandler   $notification_event_handler Notification event handler.
	 * @param WooPaymentsOrderDataService|null      $order_data_service         WooPayments order data service.
	 * @param WooPaymentsAccountService|null        $account_service            WooPayments account service.
	 * @param WooPaymentsOrderEffectApplier|null    $order_effect_applier       Optional order effect applier.
	 * @param WooPaymentsOrderNoteService|null      $order_note_service         Optional order note service.
	 * @param WooPaymentsAdminMenuBadgeService|null $admin_menu_badge_service Optional admin menu badge service.
	 */
	final public function init( OrderPaymentLifecycleService $lifecycle_service, LegacyProxy $legacy_proxy, WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsApiClient $api_client, WooPaymentsDisputeEventHandler $dispute_event_handler, WooPaymentsRefundEventHandler $refund_event_handler, WooPaymentsAccountEventHandler $account_event_handler, WooPaymentsNotificationEventHandler $notification_event_handler, ?WooPaymentsOrderDataService $order_data_service = null, ?WooPaymentsAccountService $account_service = null, ?WooPaymentsOrderEffectApplier $order_effect_applier = null, ?WooPaymentsOrderNoteService $order_note_service = null, ?WooPaymentsAdminMenuBadgeService $admin_menu_badge_service = null ): void {
		$this->lifecycle_service          = $lifecycle_service;
		$this->legacy_proxy               = $legacy_proxy;
		$this->legacy_runtime             = $legacy_runtime;
		$this->api_client                 = $api_client;
		$this->dispute_event_handler      = $dispute_event_handler;
		$this->refund_event_handler       = $refund_event_handler;
		$this->account_event_handler      = $account_event_handler;
		$this->notification_event_handler = $notification_event_handler;
		$this->order_data_service         = $order_data_service;
		$this->account_service            = $account_service;
		$this->order_effect_applier       = $order_effect_applier;
		$this->order_note_service         = $order_note_service;
		$this->admin_menu_badge_service   = $admin_menu_badge_service;
	}

	/**
	 * Process a WooPayments webhook event.
	 *
	 * Events carrying an ID are processed at most once within the marker TTL: the same event can be
	 * delivered repeatedly (Action Scheduler retries, provider re-delivery, the failed-event replay
	 * queue), and re-applying it would duplicate money-affecting side effects such as order-state
	 * transitions, refund metadata, and dispute updates.
	 *
	 * Concurrency is guarded in two layers. An atomic in-flight claim ({@see self::claim_event()}) blocks
	 * a second delivery that races the first before it can write the durable marker, closing the check-then-act
	 * window that previously spanned the whole dispatch. The durable "processed" transient is still written only
	 * after the event is handled successfully, so an event that throws is left unmarked (and its in-flight claim
	 * released) and can be retried. The durable transient also keeps deduplication working cross-request and
	 * after object-cache eviction.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws Throwable When dispatching the event fails (including an InvalidArgumentException for an invalid event shape); the in-flight claim is released first so the event can be retried.
	 */
	public function process( array $event ): void {
		$event_id = $this->get_event_id( $event );

		if ( '' !== $event_id && ! $this->claim_event( $event_id ) ) {
			return;
		}

		try {
			$this->dispatch( $event );
		} catch ( Throwable $exception ) {
			if ( '' !== $event_id ) {
				$this->release_event_claim( $event_id );
			}
			throw $exception;
		}

		if ( '' !== $event_id ) {
			$this->mark_event_processed( $event_id );
		}
	}

	/**
	 * Atomically claim an event for in-flight processing.
	 *
	 * Returns false when the event was already durably processed, or when a concurrent delivery already
	 * holds the in-flight claim. On a persistent object cache (Redis, Memcached) wp_cache_add() is atomic,
	 * so only one of two simultaneous re-deliveries wins the claim. Without a persistent object cache the
	 * claim is request-local and never blocks a concurrent request, but that does not regress below the
	 * prior behaviour: the durable transient marker still bounds duplicates exactly as it did before.
	 *
	 * @param string $event_id Event ID.
	 * @return bool True when the caller won the claim and should process the event.
	 */
	private function claim_event( string $event_id ): bool {
		if ( $this->is_event_already_processed( $event_id ) ) {
			return false;
		}

		return (bool) $this->legacy_proxy->call_function( 'wp_cache_add', $this->event_claim_key( $event_id ), 1, self::CLAIMED_EVENT_CACHE_GROUP, self::PROCESSED_EVENT_TRANSIENT_TTL );
	}

	/**
	 * Release a held in-flight processing claim so the event can be retried.
	 *
	 * @param string $event_id Event ID.
	 */
	private function release_event_claim( string $event_id ): void {
		$this->legacy_proxy->call_function( 'wp_cache_delete', $this->event_claim_key( $event_id ), self::CLAIMED_EVENT_CACHE_GROUP );
	}

	/**
	 * Get the object-cache key for an event's in-flight processing claim.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private function event_claim_key( string $event_id ): string {
		return self::CLAIMED_EVENT_CACHE_PREFIX . md5( $event_id );
	}

	/**
	 * Dispatch a WooPayments webhook event to the matching handler.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws InvalidArgumentException When the event shape is invalid.
	 */
	private function dispatch( array $event ): void {
		$event_type = $event['type'] ?? null;
		if ( ! is_string( $event_type ) || '' === $event_type ) {
			throw new InvalidArgumentException( 'WooPayments webhook event is missing a type.' );
		}

		if ( $this->is_retired_stripe_billing_invoice_event( $event_type ) ) {
			$this->run_delivery_hook( 'woocommerce_payments_before_webhook_delivery', $event_type, $event );
			$this->log_retired_stripe_billing_invoice_event( $event_type, $event );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->is_webhook_mode_mismatch( $event ) ) {
			return;
		}

		$this->run_delivery_hook( 'woocommerce_payments_before_webhook_delivery', $event_type, $event );

		if ( $this->notification_event_handler->is_supported_event( $event_type ) ) {
			$this->notification_event_handler->process( $event );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$event_object = $this->get_event_object( $event );
		if ( $this->dispute_event_handler->is_supported_event( $event_type ) ) {
			$this->dispute_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->refund_event_handler->is_supported_event( $event_type ) ) {
			$this->refund_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->account_event_handler->is_supported_event( $event_type ) ) {
			$this->account_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( ! $this->is_lifecycle_event_type( $event_type ) ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		// The plugin's canceled/amount_capturable_updated handlers are nothing but
		// this cache invalidation and never resolve an order, so it runs up front.
		if ( in_array( $event_type, array( 'payment_intent.canceled', 'payment_intent.amount_capturable_updated' ), true ) ) {
			$this->get_admin_menu_badge_service()->invalidate_authorization_summary_caches();
		}

		$order = $this->get_order_for_event_object( $event_type, $event_object );
		if ( ! $order instanceof WC_Order || ! $this->is_woopayments_order( $order ) ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$this->maybe_add_completed_fee_breakdown_note( $order, $event_type, $event_object );
		$this->maybe_apply_completed_payment_method_display_title( $order, $event_type, $event_object );

		$lifecycle_event = $this->build_lifecycle_event( $event_type, $event_object, $order );
		if ( null === $lifecycle_event ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$this->maybe_repair_recurring_order_token( $order, $event_type, $event_object );
		$this->lifecycle_service->apply( $order, $lifecycle_event, new WooPaymentsPersistenceProfile() );
		$this->maybe_send_ipp_receipt_email( $order, $event_type, $event_object );

		// Captures and expiries change what the uncaptured-transactions badge counts;
		// the plugin invalidates after the order effects land, and a failed apply
		// re-runs the whole delivery anyway.
		if ( in_array( $event_type, array( 'payment_intent.succeeded', 'charge.expired' ), true ) ) {
			$this->get_admin_menu_badge_service()->invalidate_authorization_summary_caches();
		}

		$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
	}

	/**
	 * Get the provider event ID from an event payload.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return string Event ID, or an empty string when the event has no usable ID.
	 */
	private function get_event_id( array $event ): string {
		$event_id = $event['id'] ?? null;

		return is_scalar( $event_id ) ? (string) $event_id : '';
	}

	/**
	 * Tell whether an event ID was already processed within the marker TTL.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	private function is_event_already_processed( string $event_id ): bool {
		return false !== $this->legacy_proxy->call_function( 'get_transient', $this->get_processed_event_transient_name( $event_id ) );
	}

	/**
	 * Record that an event ID was processed so later re-deliveries are skipped.
	 *
	 * @param string $event_id Event ID.
	 */
	private function mark_event_processed( string $event_id ): void {
		$this->legacy_proxy->call_function( 'set_transient', $this->get_processed_event_transient_name( $event_id ), 1, self::PROCESSED_EVENT_TRANSIENT_TTL );
	}

	/**
	 * Get the idempotency-marker transient name for an event ID.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private function get_processed_event_transient_name( string $event_id ): string {
		return self::PROCESSED_EVENT_TRANSIENT_PREFIX . md5( $event_id );
	}

	/**
	 * Get the provider object from an event payload.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the object shape is invalid.
	 */
	private function get_event_object( array $event ): array {
		$object = $event['data']['object'] ?? null;
		if ( ! is_array( $object ) ) {
			throw new InvalidArgumentException( 'WooPayments webhook event is missing an object.' );
		}

		return $object;
	}

	/**
	 * Resolve the order named by the event object.
	 *
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event_object Provider object.
	 * @return WC_Order|null
	 */
	private function get_order_for_event_object( string $event_type, array $event_object ): ?WC_Order {
		if ( 'charge.expired' === $event_type ) {
			return $this->get_order_by_payment_meta( '_charge_id', $this->get_object_id( $event_object ) );
		}

		$order = $this->get_order_by_payment_meta( '_intent_id', $this->get_object_id( $event_object ) );
		if ( $order instanceof WC_Order && $this->does_order_key_match_event_object( $order, $event_object ) ) {
			return $order;
		}

		return $this->get_order_from_event_object_metadata( $event_object );
	}

	/**
	 * Resolve the order named by provider metadata.
	 *
	 * The order key guard prevents cross-site order ID collisions from mutating
	 * another site's order when webhooks are delivered in multisite contexts.
	 *
	 * @param array<string,mixed> $event_object Provider object.
	 * @return WC_Order|null
	 */
	private function get_order_from_event_object_metadata( array $event_object ): ?WC_Order {
		$metadata = $event_object['metadata'] ?? null;
		if ( ! is_array( $metadata ) ) {
			return null;
		}

		$order_id  = isset( $metadata['order_id'] ) ? absint( $metadata['order_id'] ) : 0;
		$order_key = isset( $metadata['order_key'] ) ? (string) $metadata['order_key'] : '';
		if ( 0 === $order_id ) {
			$order_id = $this->get_order_id_from_first_charge_metadata( $event_object );
		}
		if ( 0 === $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( '' !== $order_key && $order_key !== $order->get_order_key() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Tell whether a found order matches the event order key when one is present.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object Provider object.
	 * @return bool
	 */
	private function does_order_key_match_event_object( WC_Order $order, array $event_object ): bool {
		$order_key = $event_object['metadata']['order_key'] ?? null;

		return ! is_string( $order_key ) || '' === $order_key || $order_key === $order->get_order_key();
	}

	/**
	 * Get the order ID from first-charge metadata.
	 *
	 * @param array<string,mixed> $event_object Provider object.
	 * @return int
	 */
	private function get_order_id_from_first_charge_metadata( array $event_object ): int {
		$order_id = $event_object['charges']['data'][0]['metadata']['order_id'] ?? 0;

		return absint( $order_id );
	}

	/**
	 * Get a WooPayments order by a preserved payment meta key.
	 *
	 * @param string $meta_key   Payment meta key.
	 * @param string $meta_value Payment meta value.
	 * @return WC_Order|null
	 */
	private function get_order_by_payment_meta( string $meta_key, string $meta_value ): ?WC_Order {
		if ( '' === $meta_value ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! is_array( $orders ) ) {
			return null;
		}

		return isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Tell whether a WooPayments event type is handled by the neutral lifecycle path.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	private function is_lifecycle_event_type( string $event_type ): bool {
		return in_array(
			$event_type,
			array(
				'payment_intent.succeeded',
				'payment_intent.payment_failed',
				'payment_intent.canceled',
				'payment_intent.amount_capturable_updated',
				'charge.expired',
			),
			true
		);
	}

	/**
	 * Add completed-payment fee details independently from the payment lifecycle note.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object Provider object.
	 */
	private function maybe_add_completed_fee_breakdown_note( WC_Order $order, string $event_type, array $event_object ): void {
		if ( 'payment_intent.succeeded' !== $event_type ) {
			return;
		}

		$charge_id = $this->get_charge_id_from_intent( $event_object );
		$this->get_order_data_service()->add_fee_breakdown_note(
			$order,
			$this->get_completed_fee_breakdown_note_from_intent( $event_object ),
			'' !== $charge_id ? 'charge:' . $charge_id : ''
		);
	}

	/**
	 * Apply charge-derived display title data before WooCommerce writes completion notes.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object Provider object.
	 */
	private function maybe_apply_completed_payment_method_display_title( WC_Order $order, string $event_type, array $event_object ): void {
		if ( 'payment_intent.succeeded' !== $event_type ) {
			return;
		}

		$this->get_order_effect_applier()->apply_payment_method_display_title(
			$order,
			$event_object,
			$this->get_account_service()->get_account_country()
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
	 * Build a neutral lifecycle event for a supported WooPayments webhook type.
	 *
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event_object Provider object.
	 * @param WC_Order            $order Order object.
	 * @return PaymentLifecycleEvent|null
	 */
	private function build_lifecycle_event( string $event_type, array $event_object, WC_Order $order ): ?PaymentLifecycleEvent {
		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				$charge         = $this->get_first_charge_from_intent( $event_object );
				$completed_note = $this->get_completed_payment_note_data_from_intent( $event_object, $order );
				$meta           = $this->without_empty_values(
					array(
						'_intent_id'             => $this->get_object_id( $event_object ),
						'_charge_id'             => $this->get_charge_id_from_intent( $event_object ),
						'_payment_method_id'     => $this->get_payment_method_id_from_intent( $event_object ),
						'_intention_status'      => isset( $event_object['status'] ) ? (string) $event_object['status'] : '',
						'_wcpay_intent_currency' => isset( $event_object['currency'] ) ? (string) $event_object['currency'] : '',
						'_stripe_mandate_id'     => $this->get_mandate_id_from_intent( $event_object ),
						'_wcpay_mode'            => $this->get_account_service()->get_mode(),
						'_wcpay_ipp_channel'     => $this->get_ipp_channel_from_intent( $event_object ),
					)
				);
				if ( ! empty( $charge ) ) {
					$settlement_meta = $this->get_order_data_service()->get_settlement_exchange_rate_order_meta(
						$order,
						$charge,
						$this->get_account_service()->get_account_default_currency()
					);
					$meta            = array_merge(
						$meta,
						WooPaymentsOrderEffects::completed_charge_meta(
							$event_object,
							$charge,
							$settlement_meta,
							false,
							$order->has_status( 'on-hold' )
						),
						WooPaymentsOrderEffects::completed_charge_payment_method_backfill_meta(
							$charge,
							$this->order_has_placeholder_payment_method_details( $order ),
							(string) $order->get_meta( '_wcpay_payment_transaction_id', true )
						)
					);
				}

				return new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_COMPLETED,
					$this->get_object_id( $event_object ),
					$meta,
					array(),
					$completed_note['note'],
					$completed_note['type'],
					$completed_note['equivalents']
				);

			case 'payment_intent.payment_failed':
				if ( ! $this->should_process_payment_failed_event( $event_object, $order ) ) {
					return null;
				}

				$last_payment_error  = isset( $event_object['last_payment_error'] ) && is_array( $event_object['last_payment_error'] ) ? $event_object['last_payment_error'] : array();
				$payment_method      = isset( $last_payment_error['payment_method'] ) && is_array( $last_payment_error['payment_method'] ) ? $last_payment_error['payment_method'] : array();
				$payment_method_type = isset( $payment_method['type'] ) && is_string( $payment_method['type'] ) ? $payment_method['type'] : '';
				$intent_id           = $this->get_object_id( $event_object );
				$charge_id           = $this->get_charge_id_from_intent( $event_object );
				$note_candidates     = 'card_present' === $payment_method_type
					? $this->get_order_note_service()->format_terminal_payment_failed_note_candidates( $order, $intent_id, $charge_id, $last_payment_error )
					: $this->get_order_note_service()->format_payment_failed_note_candidates( $order, $intent_id, $charge_id, $last_payment_error );

				return new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_FAILED,
					$intent_id,
					$this->without_empty_values(
						array(
							'_intent_id'        => $intent_id,
							'_intention_status' => isset( $event_object['status'] ) ? (string) $event_object['status'] : '',
						)
					),
					array(),
					$note_candidates[0],
					PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
					$note_candidates
				);

			case 'payment_intent.canceled':
			case 'payment_intent.amount_capturable_updated':
				return null;

			case 'charge.expired':
				$charge_id       = $this->get_object_id( $event_object );
				$intent_id       = isset( $event_object['payment_intent'] ) && is_string( $event_object['payment_intent'] )
					? $event_object['payment_intent']
					: (string) $order->get_meta( '_intent_id', true );
				$note_candidates = $this->get_order_note_service()->format_capture_expired_note_candidates( $intent_id, $charge_id );

				// The stored intention status still says requires_capture; only the live
				// intent knows its post-expiry status (canceled), and leaving the stale
				// value keeps capture/cancel actions offered against a dead intent. The
				// fetch is load-bearing: on failure the exception propagates so the
				// platform redelivers the event, matching the plugin.
				$expired_intent = $this->api_client->get_payment_intention( $intent_id );

				$meta = array(
					'_charge_id'        => $charge_id,
					'_intention_status' => isset( $expired_intent['status'] ) ? (string) $expired_intent['status'] : '',
				);
				if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
					$meta['_wcpay_fraud_meta_box_type'] = 'review_expired';
				}

				return new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
					$charge_id,
					$this->without_empty_values( $meta ),
					array(),
					$note_candidates[0],
					PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED,
					$note_candidates
				);
		}

		return null;
	}

	/**
	 * Tell whether a payment_intent.payment_failed event is actionable.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @param WC_Order            $order        Order resolved for the event.
	 * @return bool
	 */
	private function should_process_payment_failed_event( array $event_object, WC_Order $order ): bool {
		$last_payment_error  = $event_object['last_payment_error'] ?? null;
		$payment_method      = is_array( $last_payment_error ) ? ( $last_payment_error['payment_method'] ?? null ) : null;
		$payment_method_type = is_array( $payment_method ) && isset( $payment_method['type'] ) && is_string( $payment_method['type'] ) ? $payment_method['type'] : '';

		if ( ! in_array(
			$payment_method_type,
			array(
				'card',
				'card_present',
				'us_bank_account',
				'au_becs_debit',
				'wechat_pay',
			),
			true
		) ) {
			return false;
		}

		// A failure that names no payment method, or one belonging to a superseded
		// attempt (the shopper re-paid with another method), must not flip the order
		// to failed or overwrite its payment meta with the stale intent. Terminal
		// (card_present) intents are exempt: their payment method is created at the
		// reader and is never the one stored on the order.
		$payment_method_id = is_array( $payment_method ) && isset( $payment_method['id'] ) && is_string( $payment_method['id'] ) ? $payment_method['id'] : '';
		if ( '' === $payment_method_id ) {
			return false;
		}

		if ( 'card_present' !== $payment_method_type && $payment_method_id !== (string) $order->get_meta( '_payment_method_id', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get a provider object's ID.
	 *
	 * @param array<string,mixed> $event_object Provider object.
	 * @return string
	 */
	private function get_object_id( array $event_object ): string {
		return isset( $event_object['id'] ) ? (string) $event_object['id'] : '';
	}

	/**
	 * Get the first charge ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_charge_id_from_intent( array $event_object ): string {
		$charge_id = $event_object['charges']['data'][0]['id'] ?? '';

		return is_string( $charge_id ) ? $charge_id : '';
	}

	/**
	 * Get the payment method ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_payment_method_id_from_intent( array $event_object ): string {
		$payment_method_id = $event_object['charges']['data'][0]['payment_method'] ?? $event_object['payment_method'] ?? '';

		// An event created with an expanded payment_method carries the whole object.
		if ( is_array( $payment_method_id ) ) {
			$payment_method_id = $payment_method_id['id'] ?? '';
		}

		return is_string( $payment_method_id ) ? $payment_method_id : '';
	}

	/**
	 * Get the mandate ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_mandate_id_from_intent( array $event_object ): string {
		$mandate_id = $event_object['charges']['data'][0]['payment_method_details']['card']['mandate'] ?? '';

		return is_string( $mandate_id ) ? $mandate_id : '';
	}

	/**
	 * Get the IPP channel from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_ipp_channel_from_intent( array $event_object ): string {
		$ipp_channel      = $event_object['metadata']['ipp_channel'] ?? '';
		$allowed_channels = array( 'mobile_pos', 'mobile_store_management' );

		return is_string( $ipp_channel ) && in_array( $ipp_channel, $allowed_channels, true ) ? $ipp_channel : '';
	}

	/**
	 * Get the completed-payment lifecycle note and note type from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @param WC_Order            $order        Order object.
	 * @return array{note:string,type:string,equivalents:string[]}
	 */
	private function get_completed_payment_note_data_from_intent( array $event_object, WC_Order $order ): array {
		$charge          = $this->get_first_charge_from_intent( $event_object );
		$note_candidates = $this->get_order_note_service()->format_payment_success_note_candidates(
			$order,
			$this->get_object_id( $event_object ),
			$this->get_charge_id_from_intent( $event_object ),
			WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null )
		);

		return array(
			'note'        => $note_candidates[0],
			'type'        => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			'equivalents' => $note_candidates,
		);
	}

	/**
	 * Tell whether payment method details are still an empty placeholder.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_placeholder_payment_method_details( WC_Order $order ): bool {
		$details = $order->get_meta( '_wcpay_payment_method_details', true );
		if ( '' === $details || null === $details || array() === $details ) {
			return true;
		}

		return is_string( $details ) && array() === json_decode( $details, true );
	}

	/**
	 * Get the WooPayments order note service.
	 *
	 * @return WooPaymentsOrderNoteService
	 */
	private function get_order_note_service(): WooPaymentsOrderNoteService {
		if ( null === $this->order_note_service ) {
			$this->order_note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		}

		return $this->order_note_service;
	}

	/**
	 * Get the best available fee-breakdown note for a completed PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_completed_fee_breakdown_note_from_intent( array $event_object ): string {
		$fee_breakdown_note = $this->get_order_data_service()->get_fee_breakdown_note_from_intent( $event_object );
		if ( '' === $fee_breakdown_note || $this->get_order_data_service()->intent_needs_fee_breakdown_refresh( $event_object ) ) {
			$fresh_fee_breakdown_note = $this->get_fresh_fee_breakdown_note( $event_object );
			if ( '' !== $fresh_fee_breakdown_note ) {
				$fee_breakdown_note = $fresh_fee_breakdown_note;
			}
		}

		return $fee_breakdown_note;
	}

	/**
	 * Get the best available fee breakdown note from fresh provider reads.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_fresh_fee_breakdown_note( array $event_object ): string {
		$fallback_note = '';

		$latest_intent_note = $this->get_fee_breakdown_note_from_latest_intent( $event_object );
		if ( '' !== $latest_intent_note['note'] && ! $latest_intent_note['needs_refresh'] ) {
			return $latest_intent_note['note'];
		}
		$fallback_note = $latest_intent_note['note'];

		$timeline_note = $this->get_fee_breakdown_note_from_timeline( $event_object );
		if ( '' !== $timeline_note['note'] && ! $timeline_note['needs_refresh'] ) {
			return $timeline_note['note'];
		}

		return '' !== $fallback_note ? $fallback_note : $timeline_note['note'];
	}

	/**
	 * Get a fee breakdown note from a fresh PaymentIntent read.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return array{note:string,needs_refresh:bool}
	 */
	private function get_fee_breakdown_note_from_latest_intent( array $event_object ): array {
		$intent_id = $this->get_object_id( $event_object );
		if ( '' === $intent_id ) {
			return array(
				'note'          => '',
				'needs_refresh' => true,
			);
		}

		try {
			$latest_intent = $this->api_client->get_payment_intention( $intent_id );

			return array(
				'note'          => $this->get_order_data_service()->get_fee_breakdown_note_from_intent( $latest_intent ),
				'needs_refresh' => $this->get_order_data_service()->intent_needs_fee_breakdown_refresh( $latest_intent ),
			);
		} catch ( Throwable $exception ) {
			return array(
				'note'          => '',
				'needs_refresh' => true,
			);
		}
	}

	/**
	 * Get a fee breakdown note from the intent timeline.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return array{note:string,needs_refresh:bool}
	 */
	private function get_fee_breakdown_note_from_timeline( array $event_object ): array {
		$intent_id = $this->get_object_id( $event_object );
		if ( '' === $intent_id ) {
			return array(
				'note'          => '',
				'needs_refresh' => true,
			);
		}

		try {
			$timeline = $this->api_client->get_timeline( $intent_id );
		} catch ( Throwable $exception ) {
			return array(
				'note'          => '',
				'needs_refresh' => true,
			);
		}

		$events = isset( $timeline['data'] ) && is_array( $timeline['data'] ) ? $timeline['data'] : array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || 'captured' !== ( $event['type'] ?? null ) ) {
				continue;
			}

			return array(
				'note'          => $this->get_order_data_service()->get_fee_breakdown_note_from_timeline_event( $event ),
				'needs_refresh' => $this->get_order_data_service()->timeline_event_needs_fee_breakdown_refresh( $event ),
			);
		}

		return array(
			'note'          => '',
			'needs_refresh' => true,
		);
	}

	/**
	 * Remove empty string meta values.
	 *
	 * @param array<string,string> $values Raw values.
	 * @return array<string,string>
	 */
	private function without_empty_values( array $values ): array {
		return array_filter(
			$values,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Get the WooPayments admin menu badge service.
	 *
	 * @return WooPaymentsAdminMenuBadgeService
	 */
	private function get_admin_menu_badge_service(): WooPaymentsAdminMenuBadgeService {
		if ( null === $this->admin_menu_badge_service ) {
			$this->admin_menu_badge_service = wc_get_container()->get( WooPaymentsAdminMenuBadgeService::class );
		}

		return $this->admin_menu_badge_service;
	}

	/**
	 * Get the WooPayments order data service.
	 *
	 * @return WooPaymentsOrderDataService
	 */
	private function get_order_data_service(): WooPaymentsOrderDataService {
		if ( null === $this->order_data_service ) {
			$this->order_data_service = wc_get_container()->get( WooPaymentsOrderDataService::class );
		}

		return $this->order_data_service;
	}

	/**
	 * Send the IPP customer receipt email for card-present successful payments.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return void
	 */
	private function maybe_send_ipp_receipt_email( WC_Order $order, string $event_type, array $event_object ): void {
		if ( 'payment_intent.succeeded' !== $event_type ) {
			return;
		}

		$charge = $this->get_first_charge_from_intent( $event_object );
		if ( ! $this->is_ipp_receipt_charge( $charge ) ) {
			return;
		}

		$email = $this->get_ipp_receipt_email();
		if ( ! $email instanceof WooPaymentsIppReceiptEmail ) {
			return;
		}

		$email->trigger( $order, $this->get_ipp_receipt_merchant_settings(), $charge );
	}

	/**
	 * Get the first charge from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return array<string,mixed>
	 */
	private function get_first_charge_from_intent( array $event_object ): array {
		$charge = $event_object['charges']['data'][0] ?? array();

		return is_array( $charge ) ? $charge : array();
	}

	/**
	 * Tell whether a charge should send an IPP receipt email.
	 *
	 * @param array<string,mixed> $charge Charge payload.
	 * @return bool
	 */
	private function is_ipp_receipt_charge( array $charge ): bool {
		$type = $charge['payment_method_details']['type'] ?? '';

		return in_array( $type, array( 'card_present', 'interac_present' ), true );
	}

	/**
	 * Get the IPP receipt email from the WooCommerce mailer.
	 *
	 * @return WooPaymentsIppReceiptEmail|null
	 */
	private function get_ipp_receipt_email(): ?WooPaymentsIppReceiptEmail {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] ?? null;

		return $email instanceof WooPaymentsIppReceiptEmail ? $email : null;
	}

	/**
	 * Get merchant settings used by the IPP receipt email.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ipp_receipt_merchant_settings(): array {
		$account_service = $this->get_account_service();
		$support_address = $account_service->get_gateway_setting( 'account_business_support_address', array() );

		return array(
			'business_name' => (string) $account_service->get_gateway_setting( 'account_business_name', get_bloginfo( 'name' ) ),
			'support_info'  => array(
				'address' => is_array( $support_address ) ? $support_address : array(),
				'phone'   => (string) $account_service->get_gateway_setting( 'account_business_support_phone', '' ),
				'email'   => (string) $account_service->get_gateway_setting( 'account_business_support_email', get_option( 'admin_email', '' ) ),
			),
		);
	}

	/**
	 * Repair the payment token on an unpaid recurring order paid through a webhook.
	 *
	 * A recurring order can reach `payment_intent.succeeded` without a usable local
	 * token — WooPay checkouts and platform-side charges save the method at the
	 * provider, not locally. Without the repair, the next scheduled renewal finds no
	 * token and fails. Runs before the lifecycle apply on purpose: once this same
	 * event marks the order paid, the redelivery guard below would skip the repair.
	 *
	 * @param WC_Order            $order        Order the event resolved to.
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object Provider intent object.
	 */
	private function maybe_repair_recurring_order_token( WC_Order $order, string $event_type, array $event_object ): void {
		if ( 'payment_intent.succeeded' !== $event_type ) {
			return;
		}

		$payment_method_id = $this->get_payment_method_id_from_intent( $event_object );
		$intent_status     = isset( $event_object['status'] ) ? (string) $event_object['status'] : '';
		if ( '' === $payment_method_id || ! WooPaymentsIntentCodec::is_authorized_native_intent_status( $intent_status ) ) {
			return;
		}

		if ( ! $this->is_recurring_order( $order ) ) {
			return;
		}

		// A paid order already had its token saved at checkout, so this is a
		// redelivered event. Saving again could re-point sibling subscriptions to a
		// card the customer has since replaced.
		if ( $order->is_paid() ) {
			return;
		}

		$token_service  = wc_get_container()->get( WooPaymentsTokenService::class );
		$previous_token = $token_service->get_active_token_for_order( $order );

		try {
			$token = $token_service->get_or_create_token_for_user( $payment_method_id, (int) $order->get_customer_id() );
			if ( ! $token instanceof WC_Payment_Token ) {
				return;
			}

			$token_service->attach_token_to_order( $order, $token );

			// Write the restored token through to every related subscription: WCS
			// copies the subscription's tokens into each new renewal order, so a
			// subscription left pointing at the replaced card would charge it on
			// the next renewal.
			$token_service->sync_related_subscriptions_payment_token(
				$order,
				$token,
				$payment_method_id,
				(string) $order->get_meta( '_stripe_customer_id', true )
			);

			if ( $previous_token instanceof WC_Payment_Token && $previous_token->get_id() !== $token->get_id() ) {
				$note = sprintf(
					/* translators: 1: Previous payment token ID, 2: New payment token ID. */
					__( 'WooPayments updated the subscription payment method token from token #%1$d to #%2$d after receiving a successful renewal payment webhook.', 'woocommerce' ),
					$previous_token->get_id(),
					$token->get_id()
				);
				wc_get_logger()->info( $note, array( 'source' => 'woopayments-subscriptions' ) );
				$order->add_order_note( $note );
			}
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Error when saving payment method from webhook: ' . $exception->getMessage(),
				array(
					'source'   => 'woopayments-subscriptions',
					'order_id' => $order->get_id(),
				)
			);
			$order->add_order_note( __( 'Unable to save payment method for subscription. Please try again or use a different payment method.', 'woocommerce' ) );
		}
	}

	/**
	 * Tell whether an order belongs to a recurring payment.
	 *
	 * `wcs_order_contains_subscription()` deliberately excludes renewals, so both
	 * detectors participate, matching the extension's is_payment_recurring().
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_recurring_order( WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order->get_id() ) ) {
			return true;
		}

		return function_exists( 'wcs_order_contains_renewal' ) && (bool) wcs_order_contains_renewal( $order->get_id() );
	}

	/**
	 * Get the account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Tell whether the webhook livemode does not match native runtime mode.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return bool
	 */
	private function is_webhook_mode_mismatch( array $event ): bool {
		if ( ! array_key_exists( 'livemode', $event ) ) {
			return false;
		}

		return $this->is_native_live_mode() !== (bool) $event['livemode'];
	}

	/**
	 * Tell whether native WooPayments is in live mode.
	 *
	 * @return bool
	 */
	private function is_native_live_mode(): bool {
		$settings = $this->legacy_proxy->call_function( 'get_option', 'woocommerce_woocommerce_payments_settings', array() );
		$live     = ! is_array( $settings ) || 'yes' !== ( $settings['test_mode'] ?? 'no' );

		/**
		 * Filters whether native WooPayments webhook processing is in live mode.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $live Whether native WooPayments is in live mode.
		 */
		return (bool) apply_filters( self::FILTER_LIVE_MODE, $live );
	}

	/**
	 * Tell whether the event belongs to the retired Stripe Billing subscriptions engine.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	private function is_retired_stripe_billing_invoice_event( string $event_type ): bool {
		return in_array( $event_type, self::RETIRED_STRIPE_BILLING_INVOICE_EVENT_TYPES, true );
	}

	/**
	 * Log an alarm when retired Stripe Billing invoice traffic reaches native WooPayments.
	 *
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event      Event payload.
	 */
	private function log_retired_stripe_billing_invoice_event( string $event_type, array $event ): void {
		$logger = $this->legacy_runtime->get_logger();
		if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'error' ) ) ) {
			return;
		}

		$logger->error(
			sprintf(
				'Retired WooPayments Stripe Billing invoice event reached native webhook processing: %s',
				$event_type
			),
			array(
				'event_id' => is_scalar( $event['id'] ?? null ) ? (string) $event['id'] : '',
				'source'   => 'native-payments-webhook',
			)
		);
	}

	/**
	 * Run a WooPayments-compatible webhook delivery hook.
	 *
	 * @param string              $hook       Hook name.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event      Event payload.
	 */
	private function run_delivery_hook( string $hook, string $event_type, array $event ): void {
		try {
			$this->legacy_proxy->call_function( 'do_action', $hook, $event_type, $event );
		} catch ( Throwable $exception ) {
			$logger = $this->legacy_runtime->get_logger();
			if ( is_object( $logger ) && is_callable( array( $logger, 'error' ) ) ) {
				$logger->error(
					$exception->getMessage(),
					array(
						'source' => 'native-payments-webhook',
					)
				);
			}
		}
	}

	/**
	 * Tell whether an order belongs to WooPayments.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_woopayments_order( WC_Order $order ): bool {
		$payment_method = (string) $order->get_payment_method();

		return OrderPaymentStore::GATEWAY_ID === $payment_method || 0 === strpos( $payment_method, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}
}
