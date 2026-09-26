<?php
/**
 * WooPaymentsIntentConfirmationException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use RuntimeException;

/**
 * Exception for expected native intent-confirmation business rejections.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsIntentConfirmationException extends RuntimeException {}
