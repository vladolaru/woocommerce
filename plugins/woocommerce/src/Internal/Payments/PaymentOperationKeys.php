<?php
/**
 * PaymentOperationKeys class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Builds the keys for payment operations: a fresh idempotency key per charge or refund attempt, and a derived key per capture or cancel.
 *
 * A fresh key per attempt keeps a new attempt from replaying an earlier attempt's cached failure; the application-level
 * guards, not the key, prevent a duplicate charge. A derived key is only the order payment lock token and the log
 * correlation ID: capture and cancel requests carry a fresh key per call, so a retry after a failure reaches the provider.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentOperationKeys {

	/**
	 * Mint a fresh idempotency key for a single payment attempt.
	 *
	 * Minted once per attempt and carried through every transport-level retry within it, so
	 * one attempt can never double-charge while a new attempt is never poisoned by a previous
	 * one's cached response. A provider keeping an ambiguous attempt's key sends that key instead.
	 * Client 11.1.0 sends a fresh UUID v4 Idempotency-Key on each platform request (class-wc-payments-api-client.php:2690, 3114).
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
	public function derive_operation_key( WC_Order $order, string $provider_id, string $operation, ?float $amount = null, string $currency = '' ): string {
		$site_id = get_current_blog_id();
		$parts   = array(
			'site'      => (string) $site_id,
			'order'     => (string) $order->get_id(),
			'provider'  => $provider_id,
			'operation' => $operation,
			'amount'    => null === $amount ? '' : wc_format_decimal( $amount, wc_get_price_decimals() ),
			'currency'  => strtoupper( '' === $currency ? (string) $order->get_currency() : $currency ),
		);

		$encoded = wp_json_encode( $parts );

		return 'wc_order_payment_' . md5( false === $encoded ? implode( '|', $parts ) : $encoded );
	}
}
