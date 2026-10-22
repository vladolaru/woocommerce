<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

/**
 * Unwinds a WooCommerce method once CallStack ran its callback inside the method's hook. An Error, so the method's own
 * `catch ( Exception )` does not stop it.
 */
final class CallStackStop extends \Error {
}
