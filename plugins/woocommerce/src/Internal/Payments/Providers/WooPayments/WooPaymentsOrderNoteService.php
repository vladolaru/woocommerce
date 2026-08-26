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
	 * FROD (Future Refunds or Disputes) balances are unavailable in these account countries.
	 */
	private const FROD_UNSUPPORTED_COUNTRIES = array( 'HK', 'SG', 'AE' );
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
		return $this->format_payment_success_note_for_domain( $order, $intent_id, $charge_id, $balance_transaction_id, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a payment-success note.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_payment_success_note_candidates( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '' ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_payment_success_note_for_domain( $order, $intent_id, $charge_id, $balance_transaction_id, $text_domain, $formatted_amount )
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
		return $this->format_payment_authorized_note_for_domain( $order, $intent_id, $charge_id, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a payment-authorization note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_payment_authorized_note_candidates( WC_Order $order, string $intent_id, string $charge_id ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_payment_authorized_note_for_domain( $order, $intent_id, $charge_id, $text_domain, $formatted_amount )
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
		return $this->format_payment_started_note_for_domain( $order, $intent_id, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a payment-started note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_payment_started_note_candidates( WC_Order $order, string $intent_id ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_payment_started_note_for_domain( $order, $intent_id, $text_domain, $formatted_amount )
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
		return $this->format_capture_success_note_for_domain( $order, $intent_id, $charge_id, $balance_transaction_id, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a capture-success note.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_capture_success_note_candidates( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '' ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_capture_success_note_for_domain( $order, $intent_id, $charge_id, $balance_transaction_id, $text_domain, $formatted_amount )
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
		return $this->format_capture_cancelled_note_for_domain( $intent_id, $charge_id, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of an authorization-cancellation note.
	 *
	 * @param string $intent_id Payment intent ID.
	 * @param string $charge_id Charge ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_capture_cancelled_note_candidates( string $intent_id, string $charge_id ): array {
		return $this->unique_note_candidates(
			$this->format_capture_cancelled_note_for_domain( $intent_id, $charge_id, 'woocommerce' ),
			$this->format_capture_cancelled_note_for_domain( $intent_id, $charge_id, 'woocommerce-payments' )
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
		return $this->format_capture_failed_note_for_domain( $order, $intent_id, $charge_id, $message, 'woocommerce' );
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a capture-failure note.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @param string   $message   Failure message.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_capture_failed_note_candidates( WC_Order $order, string $intent_id, string $charge_id, string $message ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_capture_failed_note_for_domain( $order, $intent_id, $charge_id, $message, $text_domain, $formatted_amount )
		);
	}

	/**
	 * Build the renderings of a failed authorization-cancel note.
	 *
	 * Mirrors the plugin's cancel_authorization() failure notes: the provider's
	 * message is quoted when there is one, the generic copy otherwise.
	 *
	 * @param string $message Failure message from the provider, if any.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_cancel_failed_note_candidates( string $message ): array {
		if ( '' === $message ) {
			return array( WooPaymentsHtmlUtils::escape_interpolated_html( __( 'Canceling authorization <strong>failed</strong> to complete.', 'woocommerce' ), array( 'strong' => '<strong>' ) ) );
		}

		return array(
			sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1$s: error message */
					__( 'Canceling authorization <strong>failed</strong> to complete with the following message: <code>%1$s</code>.', 'woocommerce' ),
					array(
						'strong' => '<strong>',
						'code'   => '<code>',
					)
				),
				esc_html( $message )
			),
		);
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a fraud-blocked payment note.
	 *
	 * Mirrors the plugin's blocked-payment note: fired risk filters render as a
	 * bullet list with a link to the blocked transaction, and a block without
	 * ruleset results falls back to the generic blocked copy.
	 *
	 * @param WC_Order             $order           Order object.
	 * @param string               $intent_id       Blocked payment intent ID, when the block carried one.
	 * @param array<string,string> $ruleset_results Fired fraud-rule results, keyed by rule.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_fraud_blocked_note_candidates( WC_Order $order, string $intent_id, array $ruleset_results ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_fraud_blocked_note_for_domain( $order, $intent_id, $ruleset_results, $text_domain, $formatted_amount )
		);
	}

	/**
	 * Build the blocked-transaction details URL for a fraud-blocked payment note.
	 *
	 * Links the note to the specific blocked attempt: the intent id when the
	 * block carried one, otherwise the order id (rule-engine blocks fire before
	 * an intent exists).
	 *
	 * @param string $intent_id Blocked payment intent ID.
	 * @param string $order_id  Order ID fallback.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function blocked_transaction_url( string $intent_id, string $order_id ): string {
		if ( '' === $intent_id && '' === $order_id ) {
			return '';
		}

		if ( false !== strpos( $intent_id, 'seti_' ) ) {
			return '';
		}

		return Utils::wc_payments_legacy_admin_url(
			'/payments/transactions/details',
			array(
				'id'        => '' !== $intent_id ? $intent_id : $order_id,
				'status_is' => 'block',
				'type_is'   => 'order_note',
			)
		);
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a synchronous checkout payment-failure note.
	 *
	 * Mirrors the plugin's gateway decline note: the raw diagnostics (plus the
	 * card_declined seller message when the charge outcome carried one) render
	 * inside the message code block, and an incorrect_zip card error swaps in
	 * the postal-code guidance instead of the raw diagnostics.
	 *
	 * @param WC_Order $order            Order object.
	 * @param string   $message          Provider error message.
	 * @param string   $merchant_message Merchant-facing seller message, when present.
	 * @param string   $error_type       Provider error type.
	 * @param string   $error_code       Provider error code.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_checkout_payment_failed_note_candidates( WC_Order $order, string $message, string $merchant_message, string $error_type, string $error_code ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_checkout_payment_failed_note_for_domain( $message, $merchant_message, $error_type, $error_code, $text_domain, $formatted_amount )
		);
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a payment-failure note.
	 *
	 * @param WC_Order            $order              Order object.
	 * @param string              $intent_id          Payment intent ID.
	 * @param string              $charge_id          Charge ID.
	 * @param array<string,mixed> $last_payment_error Provider error details.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_payment_failed_note_candidates( WC_Order $order, string $intent_id, string $charge_id, array $last_payment_error ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_payment_failed_note_for_domain( $order, $intent_id, $charge_id, $last_payment_error, $text_domain, $formatted_amount )
		);
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a terminal payment-failure note.
	 *
	 * @param WC_Order            $order              Order object.
	 * @param string              $intent_id          Payment intent ID.
	 * @param string              $charge_id          Charge ID.
	 * @param array<string,mixed> $last_payment_error Provider error details.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_terminal_payment_failed_note_candidates( WC_Order $order, string $intent_id, string $charge_id, array $last_payment_error ): array {
		return $this->format_amount_note_candidates(
			$order,
			fn( string $text_domain, string $formatted_amount ): string => $this->format_terminal_payment_failed_note_for_domain( $order, $intent_id, $charge_id, $last_payment_error, $text_domain, $formatted_amount )
		);
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of an authorization-expiry note.
	 *
	 * @param string $intent_id Payment intent ID.
	 * @param string $charge_id Charge ID.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_capture_expired_note_candidates( string $intent_id, string $charge_id ): array {
		return $this->unique_note_candidates(
			$this->format_capture_expired_note_for_domain( $intent_id, $charge_id, 'woocommerce' ),
			$this->format_capture_expired_note_for_domain( $intent_id, $charge_id, 'woocommerce-payments' )
		);
	}

	/**
	 * Build a payment-success rendering from one known catalog.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @param string   $text_domain            Translation catalog to render.
	 * @param ?string  $formatted_amount       Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_payment_success_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, $balance_transaction_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: charged amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: charged amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a payment-authorization rendering from one known catalog.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @param string   $text_domain Translation catalog to render.
	 * @param ?string  $formatted_amount Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_payment_authorized_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build a payment-started rendering from one known catalog.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $text_domain Translation catalog to render.
	 * @param ?string  $formatted_amount Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_payment_started_note_for_domain( WC_Order $order, string $intent_id, string $text_domain, ?string $formatted_amount = null ): string {
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: started amount, %2$s: WooPayments, %3$s: payment intent ID. */
			$note_format = __( 'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: started amount, %2$s: WooPayments, %3$s: payment intent ID. */
			$note_format = __( 'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'code'   => '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$intent_id
		);
	}

	/**
	 * Build a capture-success rendering from one known catalog.
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @param string   $text_domain            Translation catalog to render.
	 * @param ?string  $formatted_amount       Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_capture_success_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, $balance_transaction_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: captured amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: captured amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Build an authorization-cancellation rendering from one known catalog.
	 *
	 * @param string $intent_id Payment intent ID.
	 * @param string $charge_id Charge ID.
	 * @param string $text_domain Translation catalog to render.
	 * @return string
	 */
	private function format_capture_cancelled_note_for_domain( string $intent_id, string $charge_id, string $text_domain ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: transaction ID, %2$s: transaction URL. */
			$note_format = __( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: transaction ID, %2$s: transaction URL. */
			$note_format = __( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
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
	 * Build a capture-failure rendering from one known catalog.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @param string   $message   Failure message.
	 * @param string   $text_domain Translation catalog to render.
	 * @param ?string  $formatted_amount Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_capture_failed_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, string $message, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id, (string) $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).', 'woocommerce' );
		}
		$note = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);

		// The provider error message is inert text in the note, matching the
		// plugin's esc_html() before mark_payment_capture_failed.
		return '' === $message ? $note : $note . ' ' . esc_html( $message );
	}

	/**
	 * Build a fraud-blocked payment rendering from one known catalog.
	 *
	 * @param WC_Order             $order            Order object.
	 * @param string               $intent_id        Blocked payment intent ID.
	 * @param array<string,string> $ruleset_results  Fired fraud-rule results, keyed by rule.
	 * @param string               $text_domain      Translation catalog to render.
	 * @param string               $formatted_amount Preformatted order amount.
	 * @return string
	 */
	private function format_fraud_blocked_note_for_domain( WC_Order $order, string $intent_id, array $ruleset_results, string $text_domain, string $formatted_amount ): string {
		$transaction_url = $this->blocked_transaction_url( $intent_id, (string) $order->get_id() );
		$labels          = $this->get_ruleset_result_labels( $ruleset_results, $text_domain );

		if ( array() !== $labels ) {
			$rules_list = '&#8226; ' . implode( '<br>&#8226; ', array_map( 'esc_html', $labels ) );

			if ( 'woocommerce-payments' === $text_domain ) {
				/* translators: %1$s: the blocked amount, %2$s: the list of risk filters that blocked the payment. */
				$note_format = __( '&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
			} else {
				/* translators: %1$s: the blocked amount, %2$s: the list of risk filters that blocked the payment. */
				$note_format = __( '&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.', 'woocommerce' );
			}

			return sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					$note_format,
					array(
						'strong' => '<strong>',
						'br'     => '<br>',
						'a'      => '' !== $transaction_url ? '<a href="%3$s" target="_blank" rel="noopener noreferrer">' : '<code>',
					)
				),
				$formatted_amount,
				$rules_list,
				$transaction_url
			);
		}

		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: the blocked amount. */
			$note_format = __( '&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by one or more risk filters.<br><br><a>View more details</a>.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: the blocked amount. */
			$note_format = __( '&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by one or more risk filters.<br><br><a>View more details</a>.', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'br'     => '<br>',
					'a'      => '' !== $transaction_url ? '<a href="%2$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount,
			$transaction_url
		);
	}

	/**
	 * Map fired fraud-rule results to the plugin's merchant-facing filter labels.
	 *
	 * @param array<string,string> $ruleset_results Fired fraud-rule results, keyed by rule.
	 * @param string               $text_domain     Translation catalog to render.
	 * @return string[]
	 */
	private function get_ruleset_result_labels( array $ruleset_results, string $text_domain ): array {
		if ( 'woocommerce-payments' === $text_domain ) {
			// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
			$mapping = array(
				'review' => array(
					'avs_verification'         => __( 'Place in review if the AVS verification fails', 'woocommerce-payments' ),
					'address_mismatch'         => __( 'Place in review if the shipping address country differs from the billing address country', 'woocommerce-payments' ),
					'international_ip_address' => __( 'Place in review if the country resolved from customer IP is not listed in your selling countries', 'woocommerce-payments' ),
					'ip_address_mismatch'      => __( 'Place in review if the order originates from a country different from the shipping address country', 'woocommerce-payments' ),
					'order_items_threshold'    => __( 'Place in review if the items count is not in your defined range', 'woocommerce-payments' ),
					'purchase_price_threshold' => __( 'Place in review if the purchase price is not in your defined range', 'woocommerce-payments' ),
				),
				'block'  => array(
					'avs_verification'         => __( 'Block if the AVS verification fails', 'woocommerce-payments' ),
					'address_mismatch'         => __( 'Block if the shipping address differs from the billing address', 'woocommerce-payments' ),
					'international_ip_address' => __( 'Block if the country resolved from customer IP is not listed in your selling countries', 'woocommerce-payments' ),
					'ip_address_mismatch'      => __( 'Block if the order originates from a country different from the shipping address country', 'woocommerce-payments' ),
					'order_items_threshold'    => __( 'Block if the items count is not in your defined range', 'woocommerce-payments' ),
					'purchase_price_threshold' => __( 'Block if the purchase price is not in your defined range', 'woocommerce-payments' ),
				),
			);
			// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
		} else {
			$mapping = array(
				'review' => array(
					'avs_verification'         => __( 'Place in review if the AVS verification fails', 'woocommerce' ),
					'address_mismatch'         => __( 'Place in review if the shipping address country differs from the billing address country', 'woocommerce' ),
					'international_ip_address' => __( 'Place in review if the country resolved from customer IP is not listed in your selling countries', 'woocommerce' ),
					'ip_address_mismatch'      => __( 'Place in review if the order originates from a country different from the shipping address country', 'woocommerce' ),
					'order_items_threshold'    => __( 'Place in review if the items count is not in your defined range', 'woocommerce' ),
					'purchase_price_threshold' => __( 'Place in review if the purchase price is not in your defined range', 'woocommerce' ),
				),
				'block'  => array(
					'avs_verification'         => __( 'Block if the AVS verification fails', 'woocommerce' ),
					'address_mismatch'         => __( 'Block if the shipping address differs from the billing address', 'woocommerce' ),
					'international_ip_address' => __( 'Block if the country resolved from customer IP is not listed in your selling countries', 'woocommerce' ),
					'ip_address_mismatch'      => __( 'Block if the order originates from a country different from the shipping address country', 'woocommerce' ),
					'order_items_threshold'    => __( 'Block if the items count is not in your defined range', 'woocommerce' ),
					'purchase_price_threshold' => __( 'Block if the purchase price is not in your defined range', 'woocommerce' ),
				),
			);
		}

		$labels = array();
		foreach ( $ruleset_results as $key => $outcome ) {
			if ( ! is_string( $key ) || ! is_string( $outcome ) || 'allow' === $outcome ) {
				continue;
			}

			$labels[] = $mapping[ $outcome ][ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
		}

		return $labels;
	}

	/**
	 * Build a synchronous checkout payment-failure rendering from one known catalog.
	 *
	 * @param string $message          Provider error message.
	 * @param string $merchant_message Merchant-facing seller message, when present.
	 * @param string $error_type       Provider error type.
	 * @param string $error_code       Provider error code.
	 * @param string $text_domain      Translation catalog to render.
	 * @param string $formatted_amount Preformatted order amount.
	 * @return string
	 */
	private function format_checkout_payment_failed_note_for_domain( string $message, string $merchant_message, string $error_type, string $error_code, string $text_domain, string $formatted_amount ): string {
		$error_details = esc_html( rtrim( $message, '.' ) );
		if ( '' !== $merchant_message ) {
			$error_details = $error_details . '. ' . esc_html( rtrim( $merchant_message, '.' ) );
		}

		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: the failed payment amount, %2$s: error message. */
			$note_format = __( 'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: the failed payment amount, %2$s: error message. */
			$note_format = __( 'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.', 'woocommerce' );
		}

		if ( 'card_error' === $error_type && 'incorrect_zip' === $error_code ) {
			if ( 'woocommerce-payments' === $text_domain ) {
				/* translators: %1$s: the failed payment amount, %2$s: error message. */
				$note_format = __( 'A payment of %1$s <strong>failed</strong>. %2$s', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.

				$error_details = __( 'We couldn’t verify the postal code in the billing address. If the issue persists, suggest the customer to reach out to the card issuing bank.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
			} else {
				/* translators: %1$s: the failed payment amount, %2$s: error message. */
				$note_format = __( 'A payment of %1$s <strong>failed</strong>. %2$s', 'woocommerce' );

				$error_details = __( 'We couldn’t verify the postal code in the billing address. If the issue persists, suggest the customer to reach out to the card issuing bank.', 'woocommerce' );
			}
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'code'   => '<code>',
				)
			),
			$formatted_amount,
			$error_details
		);
	}

	/**
	 * Build a payment-failure rendering from one known catalog.
	 *
	 * @param WC_Order            $order              Order object.
	 * @param string              $intent_id          Payment intent ID.
	 * @param string              $charge_id          Charge ID.
	 * @param array<string,mixed> $last_payment_error Provider error details.
	 * @param string              $text_domain        Translation catalog to render.
	 * @param ?string             $formatted_amount   Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_payment_failed_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, array $last_payment_error, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: order amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: order amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' );
		}

		$note = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);

		return $note . ' ' . $this->format_payment_failure_message_for_domain( $last_payment_error, $text_domain );
	}

	/**
	 * Build a terminal payment-failure rendering from one known catalog.
	 *
	 * @param WC_Order            $order              Order object.
	 * @param string              $intent_id          Payment intent ID.
	 * @param string              $charge_id          Charge ID.
	 * @param array<string,mixed> $last_payment_error Provider error details.
	 * @param string              $text_domain        Translation catalog to render.
	 * @param ?string             $formatted_amount   Preformatted order amount, when supplied.
	 * @return string
	 */
	private function format_terminal_payment_failed_note_for_domain( WC_Order $order, string $intent_id, string $charge_id, array $last_payment_error, string $text_domain, ?string $formatted_amount = null ): string {
		$transaction_id  = $intent_id;
		$transaction_url = $this->transaction_url( '', $charge_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: order amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: order amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
			$note_format = __( 'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)', 'woocommerce' );
		}

		$note = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount ?? $this->format_order_amount_for_domain( $order, $text_domain ),
			'WooPayments',
			$transaction_id,
			$transaction_url
		);

		return $note . ' ' . $this->format_payment_failure_message_for_domain( $last_payment_error, $text_domain );
	}

	/**
	 * Build an authorization-expiry rendering from one known catalog.
	 *
	 * @param string $intent_id   Payment intent ID.
	 * @param string $charge_id   Charge ID.
	 * @param string $text_domain Translation catalog to render.
	 * @return string
	 */
	private function format_capture_expired_note_for_domain( string $intent_id, string $charge_id, string $text_domain ): string {
		$transaction_id  = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url = $this->transaction_url( $intent_id, $charge_id );
		if ( 'woocommerce-payments' === $text_domain ) {
			/* translators: %1$s: transaction ID, %2$s: transaction URL. */
			$note_format = __( 'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		} else {
			/* translators: %1$s: transaction ID, %2$s: transaction URL. */
			$note_format = __( 'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).', 'woocommerce' );
		}

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				$note_format,
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
	 * Build the WooPayments webhook failure suffix from one known catalog.
	 *
	 * @param array<string,mixed> $last_payment_error Provider error details.
	 * @param string              $text_domain        Translation catalog to render.
	 * @return string
	 */
	private function format_payment_failure_message_for_domain( array $last_payment_error, string $text_domain ): string {
		$code         = isset( $last_payment_error['code'] ) && is_string( $last_payment_error['code'] ) ? $last_payment_error['code'] : '';
		$decline_code = isset( $last_payment_error['decline_code'] ) && is_string( $last_payment_error['decline_code'] ) ? $last_payment_error['decline_code'] : '';
		$message      = isset( $last_payment_error['message'] ) && is_string( $last_payment_error['message'] ) ? $last_payment_error['message'] : '';

		if ( 'woocommerce-payments' === $text_domain ) {
			switch ( $code ) {
				case 'account_closed':
					return __( "The customer's bank account has been closed.", 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'debit_not_authorized':
					return __( 'The customer has notified their bank that this payment was unauthorized.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'insufficient_funds':
					return __( "The customer's account has insufficient funds to cover this payment.", 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'no_account':
					return __( "The customer's bank account could not be located.", 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'payment_method_microdeposit_failed':
					return __( 'Microdeposit transfers failed. Please check the account, institution and transit numbers.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'payment_method_microdeposit_verification_attempts_exceeded':
					return __( 'You have exceeded the number of allowed verification attempts.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'payment_intent_mandate_invalid':
					return __( 'The mandate used for this renewal payment is invalid. You may need to bring the customer back to your store and ask them to resubmit their payment information.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
				case 'card_declined':
					if ( 'debit_notification_undelivered' === $decline_code ) {
						return __( "The customer's bank could not send pre-debit notification for the payment.", 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
					}
					if ( 'transaction_not_approved' === $decline_code ) {
						return __( 'For recurring payment greater than mandate amount or INR 15000, payment was not approved by the card holder.', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
					}
					break;
			}

			/* translators: %s: provider error message. */
			return sprintf( __( 'With the following message: <code>%s</code>', 'woocommerce-payments' ), $message ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
		}

		switch ( $code ) {
			case 'account_closed':
				return __( "The customer's bank account has been closed.", 'woocommerce' );
			case 'debit_not_authorized':
				return __( 'The customer has notified their bank that this payment was unauthorized.', 'woocommerce' );
			case 'insufficient_funds':
				return __( "The customer's account has insufficient funds to cover this payment.", 'woocommerce' );
			case 'no_account':
				return __( "The customer's bank account could not be located.", 'woocommerce' );
			case 'payment_method_microdeposit_failed':
				return __( 'Microdeposit transfers failed. Please check the account, institution and transit numbers.', 'woocommerce' );
			case 'payment_method_microdeposit_verification_attempts_exceeded':
				return __( 'You have exceeded the number of allowed verification attempts.', 'woocommerce' );
			case 'payment_intent_mandate_invalid':
				return __( 'The mandate used for this renewal payment is invalid. You may need to bring the customer back to your store and ask them to resubmit their payment information.', 'woocommerce' );
			case 'card_declined':
				if ( 'debit_notification_undelivered' === $decline_code ) {
					return __( "The customer's bank could not send pre-debit notification for the payment.", 'woocommerce' );
				}
				if ( 'transaction_not_approved' === $decline_code ) {
					return __( 'For recurring payment greater than mandate amount or INR 15000, payment was not approved by the card holder.', 'woocommerce' );
				}
				break;
		}

		/* translators: %s: provider error message. */
		return sprintf( __( 'With the following message: <code>%s</code>', 'woocommerce' ), $message );
	}

	/**
	 * Build the order note for a refund that failed for insufficient WooPayments balance.
	 *
	 * The generic failure line would bury the actionable guidance: this note tells the
	 * merchant how to fund the refund, pointing at the FROD balance where the account
	 * country supports one.
	 *
	 * @param WC_Order $order           Order object.
	 * @param float    $amount          Refund amount.
	 * @param string   $currency        Refund currency.
	 * @param string   $account_country Connected account country.
	 * @return string
	 */
	public function format_insufficient_balance_refund_note( WC_Order $order, float $amount, string $currency, string $account_country ): string {
		$currency         = strtoupper( '' !== $currency ? $currency : $order->get_currency() );
		$formatted_amount = wc_price( $amount, array( 'currency' => $currency ) );

		if ( in_array( strtoupper( $account_country ), self::FROD_UNSUPPORTED_COUNTRIES, true ) ) {
			$note = sprintf(
				/* translators: %1$s: Formatted refund amount. */
				__( 'Refund of %1$s <strong>failed</strong> due to insufficient funds in your WooPayments balance.', 'woocommerce' ),
				$formatted_amount
			);
		} else {
			$learn_more_url = 'https://woocommerce.com/document/woopayments/fees/preventing-negative-balances/#adding-funds';
			$note           = sprintf(
				/* translators: 1: Formatted refund amount, 2: Learn more URL. */
				__( 'Refund of %1$s <strong>failed</strong> due to insufficient funds in your WooPayments balance. To prevent delays in refunding customers, please consider adding funds to your Future Refunds or Disputes (FROD) balance. <a href="%2$s" target="_blank" rel="noopener noreferrer">Learn more</a>.', 'woocommerce' ),
				$formatted_amount,
				esc_url( $learn_more_url )
			);
		}

		return wp_kses_post( $note );
	}

	/**
	 * Build the order note for a synchronous refund attempt that failed.
	 *
	 * @param WC_Order $order         Order object.
	 * @param float    $amount        Refund amount.
	 * @param string   $currency      Refund currency.
	 * @param string   $error_message Failure message.
	 * @return string
	 */
	public function format_refund_failure_note( WC_Order $order, float $amount, string $currency, string $error_message ): string {
		return sprintf(
			/* translators: %1$s: the refund amount, %2$s: error message. */
			__( 'A refund of %1$s failed to complete: %2$s', 'woocommerce' ),
			$this->format_refund_amount( $order, $amount, $currency ),
			$error_message
		);
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
		return $this->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending )[0];
	}

	/**
	 * Build exact Core- and plugin-catalog renderings of a created-refund note.
	 *
	 * @param WC_Order $order      Order object.
	 * @param float    $amount     Refunded amount.
	 * @param string   $currency   Refund currency.
	 * @param string   $refund_id  Provider refund ID.
	 * @param string   $reason     Refund reason.
	 * @param bool     $is_pending Whether the provider refund is pending.
	 * @return string[] Exact equivalent renderings, with the native Core rendering first.
	 *
	 * @since 11.0.0
	 */
	public function format_created_refund_note_candidates( WC_Order $order, float $amount, string $currency, string $refund_id, string $reason, bool $is_pending ): array {
		$notes = array( $this->format_created_refund_note_for_domain( $order, $amount, $currency, $refund_id, $reason, $is_pending, 'woocommerce' ) );

		foreach ( $this->format_plugin_amount_candidates( $order, $amount, $currency ) as $formatted_amount ) {
			$notes[] = $this->format_created_refund_note_for_domain( $order, $amount, $currency, $refund_id, $reason, $is_pending, 'woocommerce-payments', $formatted_amount );
		}

		return $this->unique_note_candidates( ...$notes );
	}

	/**
	 * Build exact amount-bearing note candidates without initializing plugin state.
	 *
	 * @param WC_Order                       $order     Order object.
	 * @param callable(string,string):string $formatter Note formatter receiving text domain and amount.
	 * @return string[] Unique exact renderings, with the native rendering first.
	 */
	private function format_amount_note_candidates( WC_Order $order, callable $formatter ): array {
		$notes = array( $formatter( 'woocommerce', $this->format_order_amount( $order ) ) );

		foreach ( $this->format_plugin_amount_candidates( $order, (float) $order->get_total(), $order->get_currency() ) as $formatted_amount ) {
			$notes[] = $formatter( 'woocommerce-payments', $formatted_amount );
		}

		return $this->unique_note_candidates( ...$notes );
	}

	/**
	 * Collapse identical Core and plugin catalog renderings.
	 *
	 * @param string ...$notes Exact note renderings, native first.
	 * @return string[] Unique exact renderings, with the native rendering first.
	 */
	private function unique_note_candidates( string ...$notes ): array {
		return array_values( array_unique( $notes ) );
	}

	/**
	 * Build a created-refund note from one known translation catalog.
	 *
	 * @param WC_Order $order       Order object.
	 * @param float    $amount      Refunded amount.
	 * @param string   $currency    Refund currency.
	 * @param string   $refund_id   Provider refund ID.
	 * @param string   $reason      Refund reason.
	 * @param bool     $is_pending  Whether the provider refund is pending.
	 * @param string   $text_domain Translation catalog to render.
	 * @param ?string  $formatted_amount Preformatted refund amount, when supplied.
	 * @return string
	 */
	private function format_created_refund_note_for_domain( WC_Order $order, float $amount, string $currency, string $refund_id, string $reason, bool $is_pending, string $text_domain, ?string $formatted_amount = null ): string {
		$formatted_price = $formatted_amount ?? $this->format_refund_amount( $order, $amount, $currency );
		if ( 'woocommerce-payments' === $text_domain ) {
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Intentionally render the legacy plugin catalog for cross-cutover deduplication.
			$status_text = $is_pending ? __( 'is pending', 'woocommerce-payments' ) : __( 'was successfully processed', 'woocommerce-payments' );
			if ( '' === $reason ) {
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: provider refund ID, %4$s: refund status. */
				$note_format = __( 'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
			} else {
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: refund reason, %4$s: provider refund ID, %5$s: refund status. */
				$note_format = __( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Legacy plugin catalog compatibility.
			}
		} else {
			$status_text = $is_pending ? __( 'is pending', 'woocommerce' ) : __( 'was successfully processed', 'woocommerce' );
			if ( '' === $reason ) {
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: provider refund ID, %4$s: refund status. */
				$note_format = __( 'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).', 'woocommerce' );
			} else {
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: refund reason, %4$s: provider refund ID, %5$s: refund status. */
				$note_format = __( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)', 'woocommerce' );
			}
		}

		if ( $is_pending ) {
			$status_text = sprintf(
				'<a href="https://woocommerce.com/document/woopayments/managing-money/#pending-refunds" target="_blank" rel="noopener noreferrer">%1$s</a>',
				$status_text
			);
		}

		if ( '' === $reason ) {
			return sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: provider refund ID, %4$s: refund status. */
					$note_format,
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
				$note_format,
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
	 * @param WC_Order      $order              Order object.
	 * @param string        $note               Note content.
	 * @param string        $identity           Stable private note identity.
	 * @param string[]      $equivalent_notes   Exact catalog renderings equivalent to the native note.
	 * @param string[]      $legacy_marker_keys Legacy order-meta marker keys that identify the same note.
	 * @param callable|null $before_add         Side effects to apply only when the note is new.
	 * @return bool True when the note was added.
	 *
	 * @since 11.0.0
	 */
	public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), array $legacy_marker_keys = array(), ?callable $before_add = null ): bool {
		if ( '' === $note ) {
			return false;
		}

		$equivalent_notes      = array_values(
			array_unique(
				array_merge(
					array( $note ),
					array_filter( $equivalent_notes, static fn( string $candidate ): bool => '' !== $candidate )
				)
			)
		);
		$identity_hash         = '' === $identity ? '' : hash( 'sha256', $identity );
		$content_match_note_id = 0;
		$has_legacy_marker     = false;
		foreach ( $legacy_marker_keys as $legacy_marker_key ) {
			if ( '' !== $legacy_marker_key && 'yes' === $order->get_meta( $legacy_marker_key, true ) ) {
				$has_legacy_marker = true;
				break;
			}
		}

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		foreach ( $notes as $order_note ) {
			$note_identities = get_comment_meta( $order_note->id, self::NOTE_IDENTITY_META_KEY, false );
			if ( '' !== $identity_hash && in_array( $identity_hash, $note_identities, true ) ) {
				return false;
			}

			if ( in_array( (string) $order_note->content, $equivalent_notes, true ) ) {
				$content_match_note_id = $order_note->id;
			}
		}

		if ( 0 < $content_match_note_id ) {
			if ( '' !== $identity_hash ) {
				add_comment_meta( $content_match_note_id, self::NOTE_IDENTITY_META_KEY, $identity_hash );
			}

			return false;
		}

		if ( $has_legacy_marker ) {
			return false;
		}

		if ( null !== $before_add ) {
			$before_add();
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
			$this->has_configured_additional_currency()
		);
	}

	/**
	 * Format the finite set of historical WooPayments amount variants.
	 *
	 * Loaded WooPayments code remains the canonical source. Without it, configured
	 * additional currencies leave runtime readiness ambiguous, so both exact legacy
	 * variants are returned rather than initializing state from order-note rendering.
	 *
	 * @param WC_Order $order    Order object.
	 * @param float    $amount   Amount to format.
	 * @param string   $currency Amount currency.
	 * @return string[] Plugin-compatible formatted order amounts.
	 */
	private function format_plugin_amount_candidates( WC_Order $order, float $amount, string $currency ): array {
		$currency        = strtoupper( '' !== $currency ? $currency : $order->get_currency() );
		$formatted_price = wc_price( $amount, array( 'currency' => $currency ) );
		$formatter       = array( 'WC_Payments_Explicit_Price_Formatter', 'get_explicit_price' );

		if ( class_exists( 'WC_Payments_Explicit_Price_Formatter' ) && is_callable( $formatter ) ) {
			return array( (string) call_user_func( $formatter, $formatted_price, $order ) );
		}

		if (
			'1' !== (string) get_option( '_wcpay_feature_customer_multi_currency', '1' ) ||
			! $this->has_configured_additional_currency()
		) {
			return array( $formatted_price );
		}

		return array_values(
			array_unique(
				array(
					$formatted_price,
					MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency( $formatted_price, strtoupper( $order->get_currency() ), true ),
				)
			)
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
	 * Format an order total for one known catalog.
	 *
	 * @param WC_Order $order       Order object.
	 * @param string   $text_domain Translation catalog to render.
	 * @return string
	 */
	private function format_order_amount_for_domain( WC_Order $order, string $text_domain ): string {
		if ( 'woocommerce-payments' === $text_domain ) {
			return $this->format_refund_amount( $order, (float) $order->get_total(), $order->get_currency() );
		}

		return $this->format_order_amount( $order );
	}

	/**
	 * Tell whether an additional currency is configured in plugin options.
	 *
	 * @return bool
	 */
	private function has_configured_additional_currency(): bool {
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
