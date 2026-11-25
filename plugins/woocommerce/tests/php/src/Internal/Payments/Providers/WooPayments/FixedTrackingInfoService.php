<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;

/**
 * Tracking info service double answering a fixed tracking info, installed in the container for event family tests.
 */
class FixedTrackingInfoService extends WooPaymentsTrackingInfoService {

	/**
	 * The platform's tracking info answer (wpcom class-tracking-controller.php:233-239, get_tracking_info()).
	 */
	public const TRACKING_INFO = array( 'hosting_provider' => 'fixture-host' );

	/**
	 * Number of tracking info reads.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * Replace the container's tracking info service with a new double; reset with reset_all_replacements().
	 *
	 * @return self
	 */
	public static function install(): self {
		$service = new self();
		wc_get_container()->replace( WooPaymentsTrackingInfoService::class, $service );

		return $service;
	}

	/**
	 * Answer the fixed tracking info.
	 *
	 * @return array<string,mixed>
	 */
	public function get_tracking_info(): ?array {
		++$this->reads;

		return self::TRACKING_INFO;
	}
}
