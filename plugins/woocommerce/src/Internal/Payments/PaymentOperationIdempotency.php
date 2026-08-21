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
 * Two policies live here. Charges use a fresh key per payment attempt
 * (`mint_attempt_key()`): WooCommerce cannot tell whether a resubmitted checkout retries a
 * failed attempt or starts a genuinely new one, and a reused key would make the provider
 * replay the first attempt's cached failure instead of charging. Duplicate-charge protection
 * comes from the application-level guards, not the key. Refunds, captures, and cancels use
 * deterministic derived keys (`derive_key()`), where replay on retry is exactly the
 * protection wanted.
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
	 * Derive a deterministic idempotency key for an order-scoped provider operation.
	 *
	 * The optional `$instance` distinguishes otherwise-identical operations on the same
	 * order (e.g. two partial refunds of the same amount and reason). Without it, the
	 * provider would treat the second operation as a retry of the first and replay the
	 * cached response, silently dropping a real money movement. It is only folded into
	 * the key when provided, so keys for operations that do not need a per-instance
	 * dimension (such as charges) remain unchanged.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order    $order       Order being acted on.
	 * @param string      $provider_id Provider/gateway ID.
	 * @param string      $operation   Operation name.
	 * @param float|null  $amount      Operation amount.
	 * @param string      $currency    Operation currency.
	 * @param string      $reason      Operation reason.
	 * @param string|null $instance    Per-operation-instance discriminator (e.g. the refund ID).
	 * @return string
	 */
	public function derive_key( WC_Order $order, string $provider_id, string $operation, ?float $amount = null, string $currency = '', string $reason = '', ?string $instance = null ): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$parts   = array(
			'site'      => (string) $site_id,
			'order'     => (string) $order->get_id(),
			'provider'  => $provider_id,
			'operation' => $operation,
			'amount'    => null === $amount ? '' : wc_format_decimal( $amount, wc_get_price_decimals() ),
			'currency'  => strtoupper( '' === $currency ? (string) $order->get_currency() : $currency ),
			'reason'    => $reason,
		);

		if ( null !== $instance ) {
			$parts['instance'] = $instance;
		}

		$encoded = wp_json_encode( $parts );

		return 'wc_native_payments_' . md5( false === $encoded ? implode( '|', $parts ) : $encoded );
	}
}
