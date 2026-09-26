<?php
/**
 * WooPaymentsHookArityProbeReached file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

/**
 * Thrown by `WooPaymentsPluginHookArityContractTest`'s stopper callback the instant the probed
 * hook fires, so the probe never runs past the point being measured (no downstream fixtures, no
 * real HTTP, no order side effects beyond what already happened up to the hook).
 *
 * @since 11.2.0
 */
class WooPaymentsHookArityProbeReached extends \Exception {

}
