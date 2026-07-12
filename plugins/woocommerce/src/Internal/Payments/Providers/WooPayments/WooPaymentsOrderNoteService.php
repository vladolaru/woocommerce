<?php
/**
 * WooPaymentsOrderNoteService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use WC_Order;

/**
 * Formats and deduplicates WooPayments order notes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderNoteService {

	/**
	 * Private identity metadata stored on WooPayments order-note comments.
	 *
	 * @var string
	 */
	private const NOTE_IDENTITY_META_KEY = '_wc_woopayments_note_identity';

	/**
	 * Build a WooPayments-compatible payment-success note.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	public function format_payment_success_note( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '' ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, $balance_transaction_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: charged amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$this->format_order_amount( $order ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a WooPayments-compatible payment-authorization note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @return string
	 */
	public function format_payment_authorized_note( WC_Order $order, string $intent_id, string $charge_id ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$this->format_order_amount( $order ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a WooPayments-compatible payment-started note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @return string
	 */
	public function format_payment_started_note( WC_Order $order, string $intent_id ): string {
		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: started amount, %2$s: WooPayments, %3$s: payment intent ID. */
				__( 'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'code'   => '<code>',
				)
			),
			$this->format_order_amount( $order ),
			'WooPayments',
			$intent_id
		);
	}

	/**
	 * Build a WooPayments-compatible capture-success note.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	public function format_capture_success_note( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '' ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, $balance_transaction_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: captured amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$this->format_order_amount( $order ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a WooPayments-compatible authorization-cancellation note.
	 *
	 * @param string $intent_id Payment intent ID.
	 * @param string $charge_id Charge ID.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function format_capture_cancelled_note( string $intent_id, string $charge_id ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: transaction ID, %2$s: transaction URL. */
				__( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%2$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a WooPayments-compatible capture-failure note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @param string   $message   Failure message.
	 * @return string
	 */
	public function format_capture_failed_note( WC_Order $order, string $intent_id, string $charge_id, string $message ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, (string) $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$note            = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$this->format_order_amount( $order ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);

		return '' === $message ? $note : $note . ' ' . $message;
	}

	/**
	 * Build a localized provider refund failure message.
	 *
	 * @param string $provider_status Provider refund status.
	 * @param string $failure_reason  Provider failure reason.
	 * @return string
	 */
	public function format_refund_failure_message( string $provider_status, string $failure_reason ): string {
		return sprintf(
			/* translators: %1$s: refund status, %2$s: failure reason. */
			__( 'The refund returned status "%1$s". Reason: %2$s', 'woocommerce' ),
			$provider_status,
			'' !== $failure_reason ? $failure_reason : __( 'No reason provided.', 'woocommerce' )
		);
	}

	/**
	 * Get the WooPayments transaction details URL.
	 *
	 * @param string $intent_id              Payment intent ID.
	 * @param string $charge_id              Charge ID.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	public function transaction_url( string $intent_id, string $charge_id, string $balance_transaction_id = '' ): string {
		if ( '' === $intent_id && '' === $charge_id && '' === $balance_transaction_id ) {
			return '';
		}

		if ( false !== strpos( $intent_id, 'seti_' ) ) {
			return '';
		}

		return Utils::wc_payments_legacy_admin_url(
			'/payments/transactions/details',
			array( 'id' => '' !== $intent_id ? $intent_id : $charge_id )
		);
	}

	/**
	 * Build a WooPayments-compatible created-refund note.
	 *
	 * @param WC_Order $order      Order object.
	 * @param float    $amount     Refunded amount.
	 * @param string   $currency   Refund currency.
	 * @param string   $refund_id  Provider refund ID.
	 * @param string   $reason     Refund reason.
	 * @param bool     $is_pending Whether the provider refund is pending.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function format_created_refund_note( WC_Order $order, float $amount, string $currency, string $refund_id, string $reason, bool $is_pending ): string {
		$formatted_price = $this->format_refund_amount( $order, $amount, $currency );
		$status_text     = $is_pending
			? sprintf(
				'<a href="https://woocommerce.com/document/woopayments/managing-money/#pending-refunds" target="_blank" rel="noopener noreferrer">%1$s</a>',
				__( 'is pending', 'woocommerce' )
			)
			: __( 'was successfully processed', 'woocommerce' );

		if ( '' === $reason ) {
			return sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: provider refund ID, %4$s: refund status. */
					__( 'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).', 'woocommerce' ),
					array( 'code' => '<code>' )
				),
				$formatted_price,
				'WooPayments',
				$refund_id,
				$status_text
			);
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: refund reason, %4$s: provider refund ID, %5$s: refund status. */
				__( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)', 'woocommerce' ),
				array( 'code' => '<code>' )
			),
			$formatted_price,
			'WooPayments',
			$reason,
			$refund_id,
			$status_text
		);
	}

	/**
	 * Add an order note unless its content or private identity already exists.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $note     Note content.
	 * @param string   $identity Stable private note identity.
	 * @return bool True when the note was added.
	 *
	 * @since 11.0.0
	 */
	public function add_note_once( WC_Order $order, string $note, string $identity = '' ): bool {
		if ( '' === $note ) {
			return false;
		}

		$identity_hash         = '' === $identity ? '' : hash( 'sha256', $identity );
		$content_match_note_id = 0;
		$notes                 = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		foreach ( $notes as $order_note ) {
			$note_identities = get_comment_meta( $order_note->id, self::NOTE_IDENTITY_META_KEY, false );
			if ( '' !== $identity_hash && in_array( $identity_hash, $note_identities, true ) ) {
				return false;
			}

			if ( $note === $order_note->content ) {
				$content_match_note_id = $order_note->id;
			}
		}

		if ( 0 < $content_match_note_id ) {
			if ( '' !== $identity_hash ) {
				add_comment_meta( $content_match_note_id, self::NOTE_IDENTITY_META_KEY, $identity_hash );
			}

			return false;
		}

		$meta_data = '' === $identity_hash
			? array()
			: array( self::NOTE_IDENTITY_META_KEY => $identity_hash );

		return 0 < (int) $order->add_order_note( $note, 0, false, $meta_data );
	}

	/**
	 * Format a refund amount with WooPayments explicit-currency behavior.
	 *
	 * @param WC_Order $order    Order object.
	 * @param float    $amount   Refund amount.
	 * @param string   $currency Refund currency.
	 * @return string
	 */
	private function format_refund_amount( WC_Order $order, float $amount, string $currency ): string {
		$currency        = strtoupper( '' !== $currency ? $currency : $order->get_currency() );
		$formatted_price = wc_price( $amount, array( 'currency' => $currency ) );
		$formatter       = array( 'WC_Payments_Explicit_Price_Formatter', 'get_explicit_price' );

		if ( class_exists( 'WC_Payments_Explicit_Price_Formatter' ) && is_callable( $formatter ) ) {
			return (string) call_user_func( $formatter, $formatted_price, $order );
		}

		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency(
			$formatted_price,
			strtoupper( $order->get_currency() ),
			$this->should_output_native_explicit_price()
		);
	}

	/**
	 * Format an order total using the canonical WooPayments note shape.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function format_order_amount( WC_Order $order ): string {
		return wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
	}

	/**
	 * Tell whether native notes should include explicit currency suffixes.
	 *
	 * @return bool
	 */
	private function should_output_native_explicit_price(): bool {
		$store_currency     = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$enabled_currencies = is_array( $enabled_currencies ) ? $enabled_currencies : array();
		$enabled_currencies = array_map(
			static fn( $currency_code ) => strtoupper( (string) $currency_code ),
			$enabled_currencies
		);

		return count( array_unique( array_merge( array( $store_currency ), $enabled_currencies ) ) ) > 1;
	}
}
