<?php
/**
 * WooPaymentsOrderNoteService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

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
