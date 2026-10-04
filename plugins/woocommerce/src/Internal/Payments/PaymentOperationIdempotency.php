<?php
/**
 * PaymentOperationIdempotency class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Builds idempotency keys for native payment operations.
 *
 * Two policies live here. Charges and refunds use a fresh key per call
 * (`mint_attempt_key()`): WooCommerce cannot tell whether a resubmitted checkout or refund
 * retries a failed attempt or starts a genuinely new one, and a reused key would make the
 * provider replay the first attempt's cached failure. Duplicate-charge protection comes from
 * the application-level guards, not the key. Captures and cancels derive a key
 * (`derive_key()`), but only as the order payment lock token and log correlation ID: their
 * provider requests carry a fresh key per call, so a retry after a failed capture reaches the
 * provider instead of replaying the stored failure.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class PaymentOperationIdempotency {

	/**
	 * Mint a fresh idempotency key for a single payment attempt.
	 *
	 * Minted once per attempt and carried through every transport-level retry within it, so
	 * one attempt can never double-charge while a new attempt is never poisoned by a previous
	 * one's cached response. Matches the platform-proven client, which sends a UUID v4 per
	 * request family.
	 *
	 * @since 11.0.0
	 *
	 * @return string
	 */
	public function mint_attempt_key(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Derive a deterministic key for an order-scoped capture or cancel.
	 *
	 * Charges and refunds never use a derived key; they use mint_attempt_key().
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order   $order       Order being acted on.
	 * @param string     $provider_id Provider/gateway ID.
	 * @param string     $operation   Operation name.
	 * @param float|null $amount      Operation amount.
	 * @param string     $currency    Operation currency.
	 * @return string
	 */
	public function derive_key( WC_Order $order, string $provider_id, string $operation, ?float $amount = null, string $currency = '' ): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$parts   = array(
			'site'      => (string) $site_id,
			'order'     => (string) $order->get_id(),
			'provider'  => $provider_id,
			'operation' => $operation,
			'amount'    => null === $amount ? '' : wc_format_decimal( $amount, wc_get_price_decimals() ),
			'currency'  => strtoupper( '' === $currency ? (string) $order->get_currency() : $currency ),
		);

		$encoded = wp_json_encode( $parts );

		return 'wc_native_payments_' . md5( false === $encoded ? implode( '|', $parts ) : $encoded );
	}
}
