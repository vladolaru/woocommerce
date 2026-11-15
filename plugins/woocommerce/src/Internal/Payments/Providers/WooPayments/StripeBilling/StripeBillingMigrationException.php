<?php
/**
 * StripeBillingMigrationException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A skip or failure the Stripe Billing migrator throws itself. Its message is composed from the subscription ID, stored IDs
 * and the migrator's failure description, so the migration log prints it as it is; any other exception is described by its
 * class. A skip's message starts with "---- Skipping migration", which the migrator's reschedule check reads.
 *
 * @since 11.4.0
 * @internal
 */
final class StripeBillingMigrationException extends RuntimeException {
}
