<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

/**
 * Points and Rewards manager test double used through the extension's global class name.
 */
class FakeWooPayPointsRewardsManager {

	/**
	 * Points keyed by user ID.
	 *
	 * @var array<int,int>
	 */
	public static array $points = array();

	/**
	 * Get a user's available points.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_users_points( int $user_id ): int {
		return self::$points[ $user_id ] ?? 0;
	}
}
