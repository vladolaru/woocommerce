<?php
/**
 * InvalidCurrencyRateException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Exceptions;

/**
 * Exception thrown when a currency rate cannot be used for conversion.
 *
 * @since 11.0.0
 * @internal
 */
class InvalidCurrencyRateException extends \RuntimeException {}
