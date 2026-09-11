<?php
/**
 * WooPaymentsPluginEvidenceMaintenanceRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers only WooPayments evidence discovery callbacks for queue workers.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPluginEvidenceMaintenanceRegistrar implements RegisterHooksInterface {

	/** Evidence discovery service. */
	private WooPaymentsPluginEvidenceDiscovery $discovery;

	/**
	 * Initialize the evidence-discovery service.
	 *
	 * @internal
	 *
	 * @param WooPaymentsPluginEvidenceDiscovery $discovery Evidence discovery service.
	 */
	final public function init( WooPaymentsPluginEvidenceDiscovery $discovery ): void {
		$this->discovery = $discovery;
	}

	/** Register only queue-maintenance callbacks. */
	public function register(): void {
		$this->discovery->register_maintenance_callbacks();
	}
}
