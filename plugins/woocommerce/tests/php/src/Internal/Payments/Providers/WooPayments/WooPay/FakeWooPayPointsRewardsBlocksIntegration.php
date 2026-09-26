<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Fake Points and Rewards checkout block integration.
 */
class FakeWooPayPointsRewardsBlocksIntegration implements IntegrationInterface {

	/**
	 * Get integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'points-and-rewards';
	}

	/**
	 * Initialize integration.
	 */
	public function initialize() {}

	/**
	 * Get script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array();
	}

	/**
	 * Get editor script handles.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Get script data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		return array( 'minimum_points_amount' => 100 );
	}
}
