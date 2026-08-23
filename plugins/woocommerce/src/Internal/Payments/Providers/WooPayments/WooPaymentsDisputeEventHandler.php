<?php
/**
 * WooPaymentsDisputeEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Handles WooPayments dispute webhook side effects for native WooPayments.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDisputeEventHandler {

	/**
	 * Prefix for legacy dispute order-note structural dedupe markers.
	 *
	 * @var string
	 */
	private const DISPUTE_NOTE_MARKER_PREFIX = '_wc_native_woopayments_dispute_note_';

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Dispute cache service.
	 *
	 * @var WooPaymentsDisputeCacheService
	 */
	private WooPaymentsDisputeCacheService $dispute_cache_service;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $order_note_service = null;

	/**
	 * Initialize the handler.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime       $legacy_runtime        WooPayments legacy runtime.
	 * @param WooPaymentsApiClient           $api_client            Native WooPayments API client.
	 * @param WooPaymentsDisputeCacheService $dispute_cache_service Dispute cache service.
	 * @param WooPaymentsOrderNoteService    $order_note_service    WooPayments order note service.
	 */
	final public function init( WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsApiClient $api_client, WooPaymentsDisputeCacheService $dispute_cache_service, ?WooPaymentsOrderNoteService $order_note_service = null ): void {
		$this->legacy_runtime        = $legacy_runtime;
		$this->api_client            = $api_client;
		$this->dispute_cache_service = $dispute_cache_service;
		$this->order_note_service    = $order_note_service;
	}

	/**
	 * Tell whether an event is a WooPayments dispute event.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	public function is_supported_event( string $event_type ): bool {
		return in_array(
			$event_type,
			array(
				'charge.dispute.closed',
				'charge.dispute.created',
				'charge.dispute.funds_reinstated',
				'charge.dispute.funds_withdrawn',
				'charge.dispute.updated',
			),
			true
		);
	}

	/**
	 * Process a provider dispute event.
	 *
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object Dispute object.
	 * @throws RuntimeException When the disputed order cannot be resolved.
	 */
	public function process( string $event_type, array $event_object ): void {
		$charge_id = $this->get_required_string( $event_object, 'charge' );
		$order     = $this->get_order_by_payment_meta( '_charge_id', $charge_id );
		if ( ! $order instanceof WC_Order || ! $this->is_woopayments_order( $order ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not find WooPayments order via disputed charge ID: %s', $charge_id ) ) );
		}
		$balance_transaction_id = (string) $order->get_meta( '_wcpay_payment_transaction_id', true );

		if ( 'charge.dispute.created' === $event_type ) {
			$this->process_dispute_created( $order, $event_object, $charge_id, $balance_transaction_id );
			$this->dispute_cache_service->delete_dispute_caches();
			return;
		}

		if ( 'charge.dispute.closed' === $event_type ) {
			$this->process_dispute_closed( $order, $event_object, $charge_id, $balance_transaction_id );
			$this->dispute_cache_service->delete_dispute_caches();
			return;
		}

		if ( $this->process_dispute_updated( $order, $event_object, $event_type, $charge_id, $balance_transaction_id ) ) {
			$this->dispute_cache_service->delete_dispute_caches();
		}
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
	 * Process a dispute created event.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object Dispute object.
	 * @param string              $charge_id              Charge ID.
	 * @param string              $balance_transaction_id Balance transaction ID.
	 */
	private function process_dispute_created( WC_Order $order, array $event_object, string $charge_id, string $balance_transaction_id ): void {
		$evidence   = $this->get_required_array( $event_object, 'evidence_details' );
		$dispute_id = $this->get_required_string( $event_object, 'id' );
		$status     = $this->get_required_string( $event_object, 'status' );
		$is_inquiry = 0 === strpos( $status, 'warning_' );
		$note       = $this->get_dispute_created_note(
			$charge_id,
			$this->get_formatted_dispute_amount( $order, $this->get_required_int( $event_object, 'amount' ) ),
			$this->get_dispute_reason_description( $this->get_required_string( $event_object, 'reason' ) ),
			$this->get_dispute_due_by_date( $this->get_required_int( $evidence, 'due_by' ) ),
			$is_inquiry,
			$balance_transaction_id
		);
		$note_type  = $is_inquiry ? 'created_inquiry' : 'created_dispute';

		if (
			! $this->add_dispute_order_note_once(
				$order,
				$note,
				$dispute_id,
				$status,
				$note_type,
				static function () use ( $order ): void {
					$order->update_status( 'on-hold' );
				}
			)
		) {
			return;
		}
	}

	/**
	 * Process a dispute closed event.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object Dispute object.
	 * @param string              $charge_id              Charge ID.
	 * @param string              $balance_transaction_id Balance transaction ID.
	 */
	private function process_dispute_closed( WC_Order $order, array $event_object, string $charge_id, string $balance_transaction_id ): void {
		$status     = $this->get_required_string( $event_object, 'status' );
		$dispute_id = $this->get_required_string( $event_object, 'id' );
		$is_inquiry = 0 === strpos( $status, 'warning_' );
		$note       = $this->get_dispute_closed_note( $charge_id, $status, $is_inquiry, $balance_transaction_id );
		$note_type  = $is_inquiry ? 'closed_inquiry' : 'closed_dispute';

		$this->add_dispute_order_note_once(
			$order,
			$note,
			$dispute_id,
			$status,
			$note_type,
			function () use ( $order, $status, $dispute_id, $charge_id ): void {
				add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
				add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
				add_filter( 'woocommerce_email_enabled_customer_completed_renewal_order', '__return_false' );

				try {
					if ( 'lost' === $status ) {
						$this->create_dispute_lost_refund( $order, $this->get_dispute_summary( $dispute_id, $charge_id ), $charge_id, $dispute_id, $status );
					} elseif ( $this->is_order_fully_refunded( $order ) ) {
						// Promoting a fully refunded order to completed would make Analytics
						// count it as revenue again. It still has to leave the dispute hold,
						// though: no other webhook will arrive to move it off on-hold.
						if ( ! $order->has_status( 'refunded' ) ) {
							$order->update_status( 'refunded' );
						}

						$order->add_order_note(
							__( 'The order was not marked as completed because it has already been fully refunded.', 'woocommerce' )
						);
					} else {
						$order->update_status( 'completed' );
					}
				} finally {
					remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
					remove_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
					remove_filter( 'woocommerce_email_enabled_customer_completed_renewal_order', '__return_false' );
				}
			}
		);
	}

	/**
	 * Process a dispute update event.
	 *
	 * @param WC_Order            $order                  Order object.
	 * @param array<string,mixed> $event_object           Dispute object.
	 * @param string              $event_type             Event type.
	 * @param string              $charge_id              Charge ID.
	 * @param string              $balance_transaction_id Balance transaction ID.
	 * @return bool True when a new update note was applied.
	 */
	private function process_dispute_updated( WC_Order $order, array $event_object, string $event_type, string $charge_id, string $balance_transaction_id ): bool {
		$dispute_id = $this->get_required_string( $event_object, 'id' );
		$status     = $this->get_required_string( $event_object, 'status' );

		switch ( $event_type ) {
			case 'charge.dispute.funds_withdrawn':
				$message   = __( 'Payment dispute and fees have been deducted from your next payout', 'woocommerce' );
				$note_type = 'funds_withdrawn';
				break;
			case 'charge.dispute.funds_reinstated':
				$message   = __( 'Payment dispute funds have been reinstated', 'woocommerce' );
				$note_type = 'funds_reinstated';
				break;
			default:
				$message   = __( 'Payment dispute has been updated', 'woocommerce' );
				$note_type = 'updated';
		}

		$note = sprintf(
			/* translators: %1: the dispute message, %2: the dispute details URL */
			__( '%1$s. See <a href="%2$s">dispute overview</a> for more details.', 'woocommerce' ),
			$message,
			esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
		);

		return $this->add_dispute_order_note_once( $order, $note, $dispute_id, $status, $note_type );
	}

	/**
	 * Create a local refund for a lost dispute.
	 *
	 * @param WC_Order            $order           Order object.
	 * @param array<string,mixed> $dispute_summary Dispute summary.
	 * @param string              $charge_id       Charge ID.
	 * @param string              $dispute_id      Dispute ID.
	 * @param string              $status          Dispute status.
	 * @throws RuntimeException When the local refund cannot be created.
	 */
	private function create_dispute_lost_refund( WC_Order $order, array $dispute_summary, string $charge_id, string $dispute_id, string $status ): void {
		$refund_amount = (float) $order->get_remaining_refund_amount();
		$line_items    = $order->get_items();

		if ( ! empty( $dispute_summary ) ) {
			$disputed_amount = isset( $dispute_summary['disputed_amount'] ) ? (int) $dispute_summary['disputed_amount'] : 0;
			if ( $disputed_amount > 0 ) {
				$currency      = isset( $dispute_summary['currency'] ) && is_string( $dispute_summary['currency'] ) ? $dispute_summary['currency'] : $order->get_currency();
				$refund_amount = min( $refund_amount, WooPaymentsCurrencyUtils::amount_from_minor_units( $disputed_amount, $currency ) );
				$order_total   = (float) $order->get_total();
				$line_items    = $refund_amount < $order_total ? array() : $line_items;
			}
		}

		$refund = wc_create_refund(
			array(
				'amount'         => $refund_amount,
				'reason'         => __( 'Dispute lost.', 'woocommerce' ),
				'order_id'       => $order->get_id(),
				'line_items'     => $line_items,
				'refund_payment' => false,
			)
		);

		if ( is_wp_error( $refund ) ) {
			$this->log_dispute_refund_failure( $order, $charge_id, $dispute_id, $status, $refund_amount, $refund->get_error_message() );
			throw new RuntimeException( esc_html( sprintf( 'Could not create local dispute refund for order %1$d and dispute %2$s: %3$s', $order->get_id(), $dispute_id, $refund->get_error_message() ) ) );
		}

		if ( ! $refund instanceof WC_Order_Refund ) {
			$this->log_dispute_refund_failure( $order, $charge_id, $dispute_id, $status, $refund_amount, 'wc_create_refund returned an unexpected value.' );
			throw new RuntimeException( esc_html( sprintf( 'Could not create local dispute refund for order %1$d and dispute %2$s.', $order->get_id(), $dispute_id ) ) );
		}
	}

	/**
	 * Log a failed dispute refund.
	 *
	 * @param WC_Order $order         Order object.
	 * @param string   $charge_id     Charge ID.
	 * @param string   $dispute_id    Dispute ID.
	 * @param string   $status        Dispute status.
	 * @param float    $refund_amount Refund amount.
	 * @param string   $error_message Error message.
	 */
	private function log_dispute_refund_failure( WC_Order $order, string $charge_id, string $dispute_id, string $status, float $refund_amount, string $error_message ): void {
		$logger = $this->legacy_runtime->get_logger();
		if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'error' ) ) ) {
			return;
		}

		$logger->error(
			sprintf(
				'Failed to create local refund for lost dispute %1$s on charge %2$s: %3$s',
				$dispute_id,
				$charge_id,
				$error_message
			),
			array(
				'source'         => 'native-payments-webhook',
				'order_id'       => $order->get_id(),
				'charge_id'      => $charge_id,
				'dispute_id'     => $dispute_id,
				'dispute_status' => $status,
				'refund_amount'  => $refund_amount,
			)
		);
	}

	/**
	 * Get dispute summary data.
	 *
	 * @param string $dispute_id Dispute ID.
	 * @param string $charge_id   Charge ID.
	 * @return array<string,mixed>
	 */
	private function get_dispute_summary( string $dispute_id, string $charge_id ): array {
		try {
			return $this->api_client->get_dispute_summary( $dispute_id );
		} catch ( Throwable $exception ) {
			$logger = $this->legacy_runtime->get_logger();
			if ( is_object( $logger ) && is_callable( array( $logger, 'error' ) ) ) {
				$logger->error(
					sprintf(
						'Failed to fetch dispute summary for dispute %1$s (charge %2$s): %3$s',
						$dispute_id,
						$charge_id,
						$exception->getMessage()
					),
					array(
						'source' => 'native-payments-webhook',
					)
				);
			}
		}

		return array();
	}

	/**
	 * Get formatted disputed amount.
	 *
	 * @param WC_Order $order  Order object.
	 * @param int      $amount Dispute amount.
	 * @return string
	 */
	private function get_formatted_dispute_amount( WC_Order $order, int $amount ): string {
		$currency = $order->get_currency();
		$price    = wc_price(
			WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency ),
			array(
				'currency' => strtoupper( $currency ),
			)
		);

		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency(
			$price,
			strtoupper( $currency ),
			$this->should_output_explicit_dispute_currency( $currency )
		);
	}

	/**
	 * Tell whether a dispute amount needs an explicit currency code.
	 *
	 * @param string $currency Currency code.
	 * @return bool
	 */
	private function should_output_explicit_dispute_currency( string $currency ): bool {
		if ( '' !== $currency && strtoupper( $currency ) !== strtoupper( get_woocommerce_currency() ) ) {
			return true;
		}

		$store_currency     = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$enabled_currencies = is_array( $enabled_currencies ) ? $enabled_currencies : array();
		$enabled_currencies = array_map(
			static fn( $currency_code ) => strtoupper( (string) $currency_code ),
			$enabled_currencies
		);

		return count( array_unique( array_merge( array( $store_currency ), $enabled_currencies ) ) ) > 1;
	}

	/**
	 * Get the dispute response due date.
	 *
	 * @param int $due_by Dispute response due timestamp.
	 * @return string
	 */
	private function get_dispute_due_by_date( int $due_by ): string {
		return date_i18n( wc_date_format(), $due_by );
	}

	/**
	 * Get content for a dispute created order note.
	 *
	 * @param string $charge_id              Charge ID.
	 * @param string $amount                 Formatted amount.
	 * @param string $reason                 Reason description.
	 * @param string $due_by                 Due date.
	 * @param bool   $is_inquiry             Whether the dispute is an inquiry.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	private function get_dispute_created_note( string $charge_id, string $amount, string $reason, string $due_by, bool $is_inquiry, string $balance_transaction_id = '' ): string {
		if ( $is_inquiry ) {
			return sprintf(
				/* translators: %1: the disputed amount and currency; %2: the dispute reason; %3 the deadline date for responding to the inquiry; %4 dispute details URL */
				__( 'A payment inquiry has been raised for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.', 'woocommerce' ),
				$amount,
				esc_html( $reason ),
				esc_html( $due_by ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		}

		return sprintf(
			/* translators: %1: the disputed amount and currency; %2: the dispute reason; %3 the deadline date for responding to dispute; %4 dispute details URL */
			__( 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.', 'woocommerce' ),
			$amount,
			esc_html( $reason ),
			esc_html( $due_by ),
			esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
		);
	}

	/**
	 * Get content for a dispute closed order note.
	 *
	 * @param string $charge_id              Charge ID.
	 * @param string $status                 Dispute status.
	 * @param bool   $is_inquiry             Whether the dispute is an inquiry.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	private function get_dispute_closed_note( string $charge_id, string $status, bool $is_inquiry, string $balance_transaction_id = '' ): string {
		if ( $is_inquiry ) {
			return sprintf(
				/* translators: %1: the dispute status; %2: dispute details URL */
				__( 'Payment inquiry has been closed with status %1$s. See <a href="%2$s" target="_blank" rel="noopener noreferrer">payment status</a> for more details.', 'woocommerce' ),
				esc_html( $status ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		}

		return sprintf(
			/* translators: %1: the dispute status; %2: dispute details URL */
			__( 'Dispute has been closed with status %1$s. See <a href="%2$s" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.', 'woocommerce' ),
			esc_html( $status ),
			esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
		);
	}

	/**
	 * Get the merchant-friendly dispute reason description.
	 *
	 * @param string $reason Dispute reason.
	 * @return string
	 */
	private function get_dispute_reason_description( string $reason ): string {
		$reasons = array(
			'bank_cannot_process'       => __( 'Bank cannot process', 'woocommerce' ),
			'check_returned'            => __( 'Check returned', 'woocommerce' ),
			'credit_not_processed'      => __( 'Credit not processed', 'woocommerce' ),
			'customer_initiated'        => __( 'Customer initiated', 'woocommerce' ),
			'debit_not_authorized'      => __( 'Debit not authorized', 'woocommerce' ),
			'duplicate'                 => __( 'Duplicate', 'woocommerce' ),
			'fraudulent'                => __( 'Transaction unauthorized', 'woocommerce' ),
			'incorrect_account_details' => __( 'Incorrect account details', 'woocommerce' ),
			'insufficient_funds'        => __( 'Insufficient funds', 'woocommerce' ),
			'product_not_received'      => __( 'Product not received', 'woocommerce' ),
			'product_unacceptable'      => __( 'Product unacceptable', 'woocommerce' ),
			'subscription_canceled'     => __( 'Subscription canceled', 'woocommerce' ),
			'unrecognized'              => __( 'Unrecognized', 'woocommerce' ),
			'noncompliant'              => __( 'Non-compliant', 'woocommerce' ),
			'general'                   => __( 'General', 'woocommerce' ),
		);

		return $reasons[ $reason ] ?? $reasons['general'];
	}

	/**
	 * Get the dispute details URL.
	 *
	 * @param string $charge_id              Charge ID.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	private function get_dispute_url( string $charge_id, string $balance_transaction_id = '' ): string {
		$params = array(
			'id' => $charge_id,
		);
		if ( '' !== $balance_transaction_id ) {
			$params['transaction_id'] = $balance_transaction_id;
		}

		return Utils::wc_payments_legacy_admin_url(
			rawurlencode( '/payments/transactions/details' ),
			$params
		);
	}

	/**
	 * Get a required string webhook property.
	 *
	 * @param array<string,mixed> $items Items to read from.
	 * @param string              $key   Property key.
	 * @return string
	 * @throws RuntimeException When the property is missing or invalid.
	 */
	private function get_required_string( array $items, string $key ): string {
		$value = $this->get_required_value( $items, $key );
		if ( ! is_scalar( $value ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Expected scalar dispute webhook property: %s', $key ) ) );
		}

		return (string) $value;
	}

	/**
	 * Get a required integer webhook property.
	 *
	 * @param array<string,mixed> $items Items to read from.
	 * @param string              $key   Property key.
	 * @return int
	 * @throws RuntimeException When the property is missing or invalid.
	 */
	private function get_required_int( array $items, string $key ): int {
		$value = $this->get_required_value( $items, $key );
		if ( ! is_numeric( $value ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Expected numeric dispute webhook property: %s', $key ) ) );
		}

		return (int) $value;
	}

	/**
	 * Get a required array webhook property.
	 *
	 * @param array<string,mixed> $items Items to read from.
	 * @param string              $key   Property key.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the property is missing or invalid.
	 */
	private function get_required_array( array $items, string $key ): array {
		$value = $this->get_required_value( $items, $key );
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Expected array dispute webhook property: %s', $key ) ) );
		}

		return $value;
	}

	/**
	 * Get a required webhook property.
	 *
	 * @param array<string,mixed> $items Items to read from.
	 * @param string              $key   Property key.
	 * @return mixed
	 * @throws RuntimeException When the property is missing.
	 */
	private function get_required_value( array $items, string $key ) {
		if ( ! isset( $items[ $key ] ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Dispute webhook property not found: %s', $key ) ) );
		}

		return $items[ $key ];
	}

	/**
	 * Add a dispute order note through the shared identity mechanism.
	 *
	 * @param WC_Order $order        Order object.
	 * @param string   $note         Note content.
	 * @param string   $dispute_id   Provider dispute ID.
	 * @param string   $event_status Provider dispute status.
	 * @param string   $note_type    Stable note type.
	 * @param callable $before_add   Side effects to apply only when the note is new.
	 * @return bool True when the note was added.
	 */
	private function add_dispute_order_note_once( WC_Order $order, string $note, string $dispute_id, string $event_status, string $note_type, ?callable $before_add = null ): bool {
		return $this->get_order_note_service()->add_note_once(
			$order,
			$note,
			'dispute:' . $dispute_id . '|' . $event_status . '|' . $note_type,
			array(),
			array( $this->get_dispute_note_marker_key( $dispute_id, $event_status, $note_type ) ),
			$before_add
		);
	}

	/**
	 * Tell whether the order has already been refunded in full.
	 *
	 * Two independent clauses: has_status() catches WooCommerce's standard
	 * fully-refunded transition, the remaining-amount check catches stores that
	 * redirect it via woocommerce_order_fully_refunded_status, plus over-refunds.
	 * The total clamp guards only the second clause, since on a zero-total order
	 * (free / 100% coupon) the remaining amount is trivially 0.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_order_fully_refunded( WC_Order $order ): bool {
		return $order->has_status( 'refunded' )
			|| ( (float) $order->get_total() > 0 && (float) $order->get_remaining_refund_amount() <= 0 );
	}

	/**
	 * Get the structural dedupe marker key for a dispute note.
	 *
	 * @param string $dispute_id   Provider dispute ID.
	 * @param string $event_status Provider dispute status.
	 * @param string $note_type    Stable note type.
	 * @return string
	 */
	private function get_dispute_note_marker_key( string $dispute_id, string $event_status, string $note_type ): string {
		return self::DISPUTE_NOTE_MARKER_PREFIX . md5( $dispute_id . '|' . $event_status . '|' . $note_type );
	}

	/**
	 * Get the shared WooPayments order note service.
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
