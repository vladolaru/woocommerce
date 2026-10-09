<?php
/**
 * WooPaymentsDisputeEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
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
	 * Meta key recording the charge's disputes that are still open.
	 *
	 * @var string
	 */
	public const OPEN_DISPUTE_IDS_META_KEY = '_wcpay_open_dispute_ids';

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

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
	 * Order payment store.
	 *
	 * @var OrderPaymentLock|null
	 */
	private ?OrderPaymentLock $order_payment_lock = null;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceVocabulary|null
	 */
	private ?WooPaymentsPersistenceVocabulary $persistence_vocabulary = null;

	/**
	 * Webhook event order resolver.
	 *
	 * @var WooPaymentsEventOrderResolver|null
	 */
	private ?WooPaymentsEventOrderResolver $event_order_resolver = null;

	/**
	 * Recorder of events on a charge that does not pay the order.
	 *
	 * @var WooPaymentsOtherChargeRecorder|null
	 */
	private ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null;

	/**
	 * Initialize the handler.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient                $api_client             Native WooPayments API client.
	 * @param WooPaymentsDisputeCacheService      $dispute_cache_service  Dispute cache service.
	 * @param WooPaymentsOrderNoteService         $order_note_service     WooPayments order note service.
	 * @param OrderPaymentLock                    $order_payment_lock     Order payment store.
	 * @param WooPaymentsPersistenceVocabulary    $persistence_vocabulary WooPayments persistence profile.
	 * @param WooPaymentsEventOrderResolver|null  $event_order_resolver   Webhook event order resolver.
	 * @param WooPaymentsOtherChargeRecorder|null $other_charge_recorder Recorder of events on another charge.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsDisputeCacheService $dispute_cache_service, ?WooPaymentsOrderNoteService $order_note_service = null, ?OrderPaymentLock $order_payment_lock = null, ?WooPaymentsPersistenceVocabulary $persistence_vocabulary = null, ?WooPaymentsEventOrderResolver $event_order_resolver = null, ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null ): void {
		$this->api_client             = $api_client;
		$this->dispute_cache_service  = $dispute_cache_service;
		$this->order_note_service     = $order_note_service;
		$this->order_payment_lock     = $order_payment_lock;
		$this->persistence_vocabulary = $persistence_vocabulary;
		$this->event_order_resolver   = $event_order_resolver;
		$this->other_charge_recorder  = $other_charge_recorder;
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
	 * @throws RuntimeException When the disputed charge resolves to no order.
	 */
	public function process( string $event_type, array $event_object ): void {
		$charge_id = $this->get_required_string( $event_object, 'charge' );
		$order     = $this->get_event_order_resolver()->find_order_by_charge_id( $charge_id );
		if ( ! $order instanceof WC_Order ) {
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

		$this->process_dispute_updated( $order, $event_object, $event_type, $charge_id, $balance_transaction_id );
		$this->dispute_cache_service->delete_dispute_caches();
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
		$dispute_id = isset( $event_object['id'] ) ? $this->get_required_string( $event_object, 'id' ) : '';
		$status     = $this->get_required_string( $event_object, 'status' );
		$is_inquiry = 0 === strpos( $status, 'warning_' );
		$amount     = $this->get_formatted_dispute_amount( $order, $this->get_required_int( $event_object, 'amount' ) );
		$reason     = $this->get_dispute_reason_description( $this->get_required_string( $event_object, 'reason' ) );
		$due_by     = $this->get_dispute_due_by_date( $this->get_required_int( $evidence, 'due_by' ) );
		$note       = $this->get_dispute_created_note( $charge_id, $amount, $reason, $due_by, $is_inquiry, $balance_transaction_id, $dispute_id );
		$note_type  = $is_inquiry ? 'created_inquiry' : 'created_dispute';

		$this->run_under_dispute_lock(
			$order,
			'charge.dispute.created',
			$event_object,
			$charge_id,
			function ( WC_Order $order ) use ( $note, $dispute_id, $status, $note_type, $charge_id, $amount, $reason, $due_by, $is_inquiry, $balance_transaction_id ): bool {
				return $this->add_dispute_order_note_once(
					$order,
					$note,
					$dispute_id,
					$status,
					$note_type,
					function () use ( $order, $dispute_id ): void {
						$this->add_open_dispute_id( $order, $dispute_id );
						$order->update_status( OrderStatus::ON_HOLD );
					},
					// Plugin versions predating the dispute-ID suffix wrote the bare note;
					// on a cutover store the replayed webhook must still match it.
					array( $this->get_dispute_created_note( $charge_id, $amount, $reason, $due_by, $is_inquiry, $balance_transaction_id ) )
				);
			}
		);
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
		$note       = $this->get_dispute_closed_note( $charge_id, $status, $is_inquiry, $balance_transaction_id, $dispute_id );
		$note_type  = $is_inquiry ? 'closed_inquiry' : 'closed_dispute';

		$this->run_under_dispute_lock(
			$order,
			'charge.dispute.closed',
			$event_object,
			$charge_id,
			fn( WC_Order $order ): bool => $this->apply_dispute_closed( $order, $event_object, $note, $dispute_id, $status, $note_type, $charge_id, $is_inquiry, $balance_transaction_id )
		);
	}

	/**
	 * Apply a dispute close to an order read under the order payment lock.
	 *
	 * @param WC_Order            $order                  Order read under the lock.
	 * @param array<string,mixed> $event_object           Dispute object of the event.
	 * @param string              $note                   Closed note.
	 * @param string              $dispute_id             Dispute ID.
	 * @param string              $status                 Dispute status.
	 * @param string              $note_type              Note type.
	 * @param string              $charge_id              Charge ID.
	 * @param bool                $is_inquiry             Whether the dispute is an inquiry.
	 * @param string              $balance_transaction_id Balance transaction ID.
	 * @return bool True when the close was applied.
	 */
	private function apply_dispute_closed( WC_Order $order, array $event_object, string $note, string $dispute_id, string $status, string $note_type, string $charge_id, bool $is_inquiry, string $balance_transaction_id ): bool {
		// A callback of its own, so removing it cannot remove a site's own '__return_false' on these emails.
		$disable_email = static fn(): bool => false;

		return $this->add_dispute_order_note_once(
			$order,
			$note,
			$dispute_id,
			$status,
			$note_type,
			function () use ( $order, $event_object, $status, $dispute_id, $charge_id, $disable_email ): void {
				add_filter( 'woocommerce_email_enabled_customer_completed_order', $disable_email );
				add_filter( 'woocommerce_email_enabled_customer_refunded_order', $disable_email );
				add_filter( 'woocommerce_email_enabled_customer_completed_renewal_order', $disable_email );

				$open_dispute_ids = $this->close_open_dispute_id( $order, $dispute_id );

				try {
					if ( 'lost' === $status ) {
						$this->create_dispute_lost_refund( $order, $this->get_lost_dispute_amount( $event_object, $dispute_id, $charge_id ), $charge_id, $dispute_id, $status );
					} elseif ( ! empty( $open_dispute_ids ) ) {
						// Another dispute on the same charge is still running its evidence
						// deadline, and the hold it put on the order has to outlive this one.
						// Leave the status alone rather than picking a new one: the sibling's
						// own close will resolve it.
						$order->add_order_note(
							sprintf(
								/* translators: %d: the number of disputes on this payment that are still open */
								_n(
									'The order was not marked as completed because %d other dispute on this payment is still open.',
									'The order was not marked as completed because %d other disputes on this payment are still open.',
									count( $open_dispute_ids ),
									'woocommerce'
								),
								count( $open_dispute_ids )
							)
						);
					} elseif ( $this->is_order_fully_refunded( $order ) ) {
						// Promoting a fully refunded order to completed would make Analytics
						// count it as revenue again. It still has to leave the dispute hold,
						// though: no other webhook will arrive to move it off on-hold.
						if ( ! $order->has_status( OrderStatus::REFUNDED ) ) {
							$order->update_status( OrderStatus::REFUNDED );
						}

						$order->add_order_note(
							__( 'The order was not marked as completed because it has already been fully refunded.', 'woocommerce' )
						);
					} else {
						$order->update_status( OrderStatus::COMPLETED );
					}
				} finally {
					remove_filter( 'woocommerce_email_enabled_customer_completed_order', $disable_email );
					remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $disable_email );
					remove_filter( 'woocommerce_email_enabled_customer_completed_renewal_order', $disable_email );
				}
			},
			// Plugin versions predating the dispute-ID suffix wrote the bare note;
			// on a cutover store the replayed webhook must still match it.
			array( $this->get_dispute_closed_note( $charge_id, $status, $is_inquiry, $balance_transaction_id ) )
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
		$dispute_id = isset( $event_object['id'] ) && is_scalar( $event_object['id'] ) ? (string) $event_object['id'] : '';
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

		$note = $this->append_dispute_id_to_note(
			sprintf(
				/* translators: %1: the dispute message, %2: the dispute details URL */
				__( '%1$s. See <a href="%2$s">dispute overview</a> for more details.', 'woocommerce' ),
				$message,
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			),
			$dispute_id
		);

		return $this->run_under_dispute_lock(
			$order,
			$event_type,
			$event_object,
			$charge_id,
			fn( WC_Order $order ): bool => $this->add_dispute_order_note_once( $order, $note, $dispute_id, $status, $note_type ),
			$message
		);
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
				$disputed      = WooPaymentsCurrencyUtils::amount_from_minor_units( $disputed_amount, $currency );
				$refund_amount = min( $refund_amount, $disputed );
				// Only a partial dispute clears the line items; a prior partial refund does not.
				$line_items = $disputed < (float) $order->get_total() ? array() : $line_items;
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
		// Logging is best-effort: a logger that cannot be obtained or written must not fail the event.
		try {
			wc_get_logger()->error(
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
		} catch ( Throwable $logger_exception ) {
			unset( $logger_exception );
		}
	}

	/**
	 * Get the amount a lost dispute refunds: the platform's dispute summary, or the event's own dispute when it fails.
	 *
	 * The summary's disputed_amount and currency are the Stripe dispute's amount and currency (platform
	 * class-dispute-service.php, the summary builder), which the closed event carries too. Client 11.1.0 refunds the
	 * order's whole remaining amount when the fetch fails; for a partial dispute that over-refunds.
	 *
	 * @param array<string,mixed> $event_object Dispute object of the event.
	 * @param string              $dispute_id   Dispute ID.
	 * @param string              $charge_id    Charge ID.
	 * @return array<string,mixed> Summary with disputed_amount and currency, or empty when neither source has them.
	 */
	private function get_lost_dispute_amount( array $event_object, string $dispute_id, string $charge_id ): array {
		$summary = $this->get_dispute_summary( $dispute_id, $charge_id );
		if ( ! empty( $summary['disputed_amount'] ) ) {
			return $summary;
		}

		$amount   = $event_object['amount'] ?? null;
		$currency = $event_object['currency'] ?? null;
		if ( ! is_int( $amount ) || $amount <= 0 || ! is_string( $currency ) || '' === $currency ) {
			return $summary;
		}

		return array(
			'disputed_amount' => $amount,
			'currency'        => strtoupper( $currency ),
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
			// Logging is best-effort: the event's own amount must still drive the local refund when the logger fails.
			try {
				wc_get_logger()->error(
					sprintf(
						'Failed to fetch dispute summary for dispute %1$s (charge %2$s).',
						$dispute_id,
						$charge_id
					),
					array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'native-payments-webhook' ) )
				);
			} catch ( Throwable $logger_exception ) {
				unset( $logger_exception );
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
		$price    = WooPaymentsCurrencyUtils::format_price_in_currency(
			WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency ),
			strtoupper( $currency )
		);

		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency(
			$price,
			strtoupper( $currency ),
			MultiCurrencyExplicitPriceProjectionService::should_output_explicit_admin_price()
		);
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
	 * @param string $dispute_id             Dispute ID, appended so a charge's several disputes each get a distinct note.
	 * @return string
	 */
	private function get_dispute_created_note( string $charge_id, string $amount, string $reason, string $due_by, bool $is_inquiry, string $balance_transaction_id = '', string $dispute_id = '' ): string {
		if ( $is_inquiry ) {
			$note = sprintf(
				/* translators: %1: the disputed amount and currency; %2: the dispute reason; %3 the deadline date for responding to the inquiry; %4 dispute details URL */
				__( 'A payment inquiry has been raised for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.', 'woocommerce' ),
				$amount,
				esc_html( $reason ),
				esc_html( $due_by ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		} else {
			$note = sprintf(
				/* translators: %1: the disputed amount and currency; %2: the dispute reason; %3 the deadline date for responding to dispute; %4 dispute details URL */
				__( 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.', 'woocommerce' ),
				$amount,
				esc_html( $reason ),
				esc_html( $due_by ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		}

		return $this->append_dispute_id_to_note( $note, $dispute_id );
	}

	/**
	 * Get content for a dispute closed order note.
	 *
	 * @param string $charge_id              Charge ID.
	 * @param string $status                 Dispute status.
	 * @param bool   $is_inquiry             Whether the dispute is an inquiry.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @param string $dispute_id             Dispute ID, appended so a charge's several disputes each get a distinct note.
	 * @return string
	 */
	private function get_dispute_closed_note( string $charge_id, string $status, bool $is_inquiry, string $balance_transaction_id = '', string $dispute_id = '' ): string {
		if ( $is_inquiry ) {
			$note = sprintf(
				/* translators: %1: the dispute status; %2: dispute details URL */
				__( 'Payment inquiry has been closed with status %1$s. See <a href="%2$s" target="_blank" rel="noopener noreferrer">payment status</a> for more details.', 'woocommerce' ),
				esc_html( $status ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		} else {
			$note = sprintf(
				/* translators: %1: the dispute status; %2: dispute details URL */
				__( 'Dispute has been closed with status %1$s. See <a href="%2$s" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.', 'woocommerce' ),
				esc_html( $status ),
				esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
			);
		}

		return $this->append_dispute_id_to_note( $note, $dispute_id );
	}

	/**
	 * Append the dispute ID to a dispute note.
	 *
	 * A charge can carry several disputes whose notes are otherwise byte-identical
	 * (same amount, reason and deadline, or same close status); without the dispute
	 * ID the note dedupe collapses them into one and the second dispute's side
	 * effects are skipped. A re-delivered webhook still de-dupes via its identity.
	 *
	 * @param string $note       Note content.
	 * @param string $dispute_id Provider dispute ID.
	 * @return string
	 */
	private function append_dispute_id_to_note( string $note, string $dispute_id ): string {
		if ( '' === $dispute_id ) {
			return $note;
		}

		return $note . ' ' . sprintf(
			/* translators: %s: the dispute ID */
			esc_html__( '(Dispute ID: %s)', 'woocommerce' ),
			esc_html( $dispute_id )
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
	 * @param string[] $equivalent_notes Equivalent note texts written by other implementations or versions.
	 * @return bool True when the note was added.
	 */
	private function add_dispute_order_note_once( WC_Order $order, string $note, string $dispute_id, string $event_status, string $note_type, ?callable $before_add = null, array $equivalent_notes = array() ): bool {
		return $this->get_order_note_service()->add_note_once(
			$order,
			$note,
			'dispute:' . $dispute_id . '|' . $event_status . '|' . $note_type,
			$equivalent_notes,
			$before_add
		);
	}

	/**
	 * Run a dispute change under the order payment lock, on the order read again after the claim.
	 *
	 * A concurrent delivery for the same order, such as a duplicate close or a sibling dispute's created event,
	 * writes under the same lock, so the change sees those writes instead of overwriting them from a stale read.
	 * Client 11.1.0 takes no lock in its dispute handlers; this is a recorded better-than-client row.
	 *
	 * The change runs only when the disputed charge is the order's own payment; a dispute on another charge is recorded
	 * instead, with one note and one warning line, and the order keeps its status.
	 *
	 * @param WC_Order                $order        Order the event resolved to.
	 * @param string                  $event_type   Event type.
	 * @param array<string,mixed>     $event_object Dispute object; its `payment_intent` names the disputed payment.
	 * @param string                  $charge_id    Disputed charge ID.
	 * @param callable(WC_Order):bool $change       Change to run on the order read under the lock.
	 * @param string                  $message      What the event says happened, for a dispute update on another charge.
	 * @return bool What the change returned, or false when the dispute was recorded.
	 * @throws OrderPaymentLockRefusedException When another operation holds the order payment lock; nothing has been written.
	 */
	private function run_under_dispute_lock( WC_Order $order, string $event_type, array $event_object, string $charge_id, callable $change, string $message = '' ): bool {
		$dispute_id = isset( $event_object['id'] ) && is_scalar( $event_object['id'] ) ? (string) $event_object['id'] : '';
		$lock_token = $this->claim_dispute_lock( $order, $dispute_id );

		try {
			$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
			$resolver    = $this->get_event_order_resolver();
			$intent_id   = $resolver->get_event_intent_id( $event_object, $fresh_order );
			if ( ! $resolver->is_own_payment( $fresh_order, $intent_id, $charge_id ) ) {
				$this->get_other_charge_recorder()->record(
					$fresh_order,
					$event_type,
					array(
						'object_id'   => $dispute_id,
						'status'      => isset( $event_object['status'] ) && is_scalar( $event_object['status'] ) ? (string) $event_object['status'] : '',
						'intent_id'   => $intent_id,
						'charge_id'   => $charge_id,
						'dispute_id'  => $dispute_id,
						'message'     => $message,
						'dispute_url' => $this->get_dispute_url( $charge_id ),
					)
				);
				return false;
			}

			return $change( $fresh_order );
		} finally {
			$this->get_order_payment_lock()->release( $order, $this->get_persistence_vocabulary(), $lock_token );
		}
	}

	/**
	 * Claim the shared order payment lock for a dispute webhook mutation.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $dispute_id Provider dispute ID.
	 * @return string Claim token to release the lock with.
	 * @throws OrderPaymentLockRefusedException When the order payment lock cannot be claimed; nothing has been written.
	 */
	private function claim_dispute_lock( WC_Order $order, string $dispute_id ): string {
		$lock_token = $this->get_order_payment_lock()->claim( $order, $this->get_persistence_vocabulary(), 'dispute_webhook_' . $dispute_id, 'dispute webhook' );
		if ( null === $lock_token ) {
			$this->get_order_payment_lock()->log_refusal( $order, $this->get_persistence_vocabulary(), 'dispute webhook' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built in the exception from an order ID and a fixed operation name, not HTML output.
			throw new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' );
		}

		return $lock_token;
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
		return $order->has_status( OrderStatus::REFUNDED )
			|| ( (float) $order->get_total() > 0 && (float) $order->get_remaining_refund_amount() <= 0 );
	}

	/**
	 * Read the IDs of the charge's disputes that have not closed yet.
	 *
	 * @param WC_Order $order The order the disputed charge belongs to.
	 * @return string[] The open dispute IDs, empty when none were ever recorded.
	 */
	private function get_open_dispute_ids( WC_Order $order ): array {
		$open_dispute_ids = $order->get_meta( self::OPEN_DISPUTE_IDS_META_KEY, true );

		return is_array( $open_dispute_ids ) ? array_values( $open_dispute_ids ) : array();
	}

	/**
	 * Record a dispute as open on the order. Does not save the order.
	 *
	 * @param WC_Order $order      The order the disputed charge belongs to.
	 * @param string   $dispute_id The ID of the dispute that was created.
	 */
	private function add_open_dispute_id( WC_Order $order, string $dispute_id ): void {
		if ( '' === $dispute_id ) {
			return;
		}

		$open_dispute_ids = $this->get_open_dispute_ids( $order );
		if ( in_array( $dispute_id, $open_dispute_ids, true ) ) {
			return;
		}

		$open_dispute_ids[] = $dispute_id;
		$order->update_meta_data( self::OPEN_DISPUTE_IDS_META_KEY, $open_dispute_ids );
	}

	/**
	 * Drop a dispute from the order's open list and report which disputes are left.
	 *
	 * @param WC_Order $order      The order the disputed charge belongs to.
	 * @param string   $dispute_id The ID of the dispute that closed.
	 * @return string[] The disputes still open on the charge.
	 */
	private function close_open_dispute_id( WC_Order $order, string $dispute_id ): array {
		// Without an ID there is no telling which of the charge's disputes just closed,
		// so leave the record untouched and report nothing open. Orders whose disputes
		// predate this bookkeeping keep the behaviour they had before.
		if ( '' === $dispute_id ) {
			return array();
		}

		$open_dispute_ids = $this->get_open_dispute_ids( $order );
		$remaining        = array_values( array_diff( $open_dispute_ids, array( $dispute_id ) ) );

		if ( $remaining === $open_dispute_ids ) {
			return $remaining;
		}

		if ( empty( $remaining ) ) {
			$order->delete_meta_data( self::OPEN_DISPUTE_IDS_META_KEY );
		} else {
			$order->update_meta_data( self::OPEN_DISPUTE_IDS_META_KEY, $remaining );
		}

		// Nothing further down the close path is guaranteed to save the order: the lost
		// branch refunds through a separate order instance and the sibling-open branch
		// changes no status at all.
		$order->save();

		return $remaining;
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
	 * Get the shared order payment store.
	 *
	 * @return OrderPaymentLock
	 */
	private function get_order_payment_lock(): OrderPaymentLock {
		if ( null === $this->order_payment_lock ) {
			$this->order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		}

		return $this->order_payment_lock;
	}

	/**
	 * Get the WooPayments persistence profile.
	 *
	 * @return WooPaymentsPersistenceVocabulary
	 */
	private function get_persistence_vocabulary(): WooPaymentsPersistenceVocabulary {
		if ( null === $this->persistence_vocabulary ) {
			$this->persistence_vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		}

		return $this->persistence_vocabulary;
	}

	/**
	 * Get the webhook event order resolver.
	 *
	 * @return WooPaymentsEventOrderResolver
	 */
	private function get_event_order_resolver(): WooPaymentsEventOrderResolver {
		if ( null === $this->event_order_resolver ) {
			$this->event_order_resolver = wc_get_container()->get( WooPaymentsEventOrderResolver::class );
		}

		return $this->event_order_resolver;
	}

	/**
	 * Get the recorder of events on a charge that does not pay the order.
	 *
	 * @return WooPaymentsOtherChargeRecorder
	 */
	private function get_other_charge_recorder(): WooPaymentsOtherChargeRecorder {
		if ( null === $this->other_charge_recorder ) {
			$this->other_charge_recorder = wc_get_container()->get( WooPaymentsOtherChargeRecorder::class );
		}

		return $this->other_charge_recorder;
	}
}
