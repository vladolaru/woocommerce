<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

/**
 * Affiliate for WooCommerce API test double used through the extension's global class name.
 */
class FakeWooPayAffiliateApi {

	/**
	 * Recorded track_conversion calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $conversions = array();

	/**
	 * Get the shared instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		return new self();
	}

	/**
	 * Record a conversion.
	 *
	 * @param int                 $order_id     Order ID.
	 * @param int                 $affiliate_id Affiliate ID.
	 * @param string              $used_coupon  Coupon code.
	 * @param array<string,mixed> $params       Extra parameters.
	 */
	public function track_conversion( $order_id, $affiliate_id, $used_coupon = '', $params = array() ): void {
		self::$conversions[] = array(
			'order_id'     => $order_id,
			'affiliate_id' => $affiliate_id,
			'used_coupon'  => $used_coupon,
			'params'       => $params,
		);
	}
}
