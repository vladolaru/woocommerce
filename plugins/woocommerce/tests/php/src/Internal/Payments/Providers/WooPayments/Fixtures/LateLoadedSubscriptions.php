<?php
/**
 * Late-loaded WooCommerce Subscriptions fixture.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Supplies the Subscriptions version contract without defining its global class early.
 */
final class LateLoadedSubscriptions {
	/**
	 * Extension version.
	 *
	 * @var string
	 */
	public static $version = '7.5.0';
}
