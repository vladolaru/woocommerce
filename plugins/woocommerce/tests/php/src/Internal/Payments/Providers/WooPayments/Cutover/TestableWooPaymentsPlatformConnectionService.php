<?php
/**
 * TestableWooPaymentsPlatformConnectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPlatformConnectionService;

/**
 * Testable WooPayments platform connection service.
 */
class TestableWooPaymentsPlatformConnectionService extends WooPaymentsPlatformConnectionService {

	/**
	 * Jetpack connection manager.
	 *
	 * @var JetpackConnectionManager|null
	 */
	public ?JetpackConnectionManager $manager = null;

	/**
	 * Get the test Jetpack connection manager.
	 *
	 * @return JetpackConnectionManager|null
	 */
	protected function get_connection_manager(): ?JetpackConnectionManager {
		return $this->manager;
	}
}
