<?php
/**
 * WooPayments idempotency keys.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Derives a header- and metadata-safe key from the caller's exact bytes.
 *
 * @internal
 * @since 11.4.0
 */
final class WooPaymentsIdempotencyKey {
	/** Payment intent creation. */
	public const OPERATION_INTENT = 'intent';

	/** Direct charge refund. */
	public const OPERATION_REFUND = 'refund';

	/**
	 * Keep the empty value so the transport generates a fresh key.
	 *
	 * @since 11.4.0
	 * @param string $caller_key Key supplied by the caller.
	 * @param string $operation  Payment operation.
	 * @return string
	 */
	public static function for_platform( string $caller_key, string $operation ): string {
		return '' === $caller_key ? '' : 'wc-' . $operation . '-' . hash( 'sha256', $caller_key );
	}
}
