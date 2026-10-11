<?php
/**
 * WooPaymentsIntentConfirmationException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use RuntimeException;

/**
 * Thrown when an intent confirmation is refused for a business rule rather than a platform or code failure.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsIntentConfirmationException extends RuntimeException {}
