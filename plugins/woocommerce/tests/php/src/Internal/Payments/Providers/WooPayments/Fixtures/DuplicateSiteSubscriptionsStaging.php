<?php
/**
 * WooCommerce Subscriptions staging fixture.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Stands in for WCS_Staging on a site WooCommerce Subscriptions considers a staging copy.
 */
final class DuplicateSiteSubscriptionsStaging {
	/**
	 * Tell whether the site is a staging copy.
	 *
	 * @return bool
	 */
	public static function is_duplicate_site(): bool {
		return true;
	}
}
