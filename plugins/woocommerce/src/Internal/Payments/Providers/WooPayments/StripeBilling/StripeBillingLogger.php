<?php
/**
 * StripeBillingLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the module's log lines only when WooPayments debug logging is on or in dev mode, as client 11.1.0's `Logger::log()` does.
 *
 * The gate and source live in the shared provider logger.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingLogger extends WooPaymentsLogger {
}
