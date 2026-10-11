<?php
/**
 * WooPaymentsWooCommerceUpdateListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayExtensionSync;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Runs the WooPayments duties of a WooCommerce update for a connected store.
 *
 * The client runs them on its own update action (client 11.1.0 `includes/class-wc-payments-account.php:143,147`,
 * `includes/woopay/class-woopay-scheduler.php:50`). Native ships inside WooCommerce, so a WooCommerce update is that
 * event; it finishes on the init of any request, and the services are resolved only when it fires.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooCommerceUpdateListener implements RegisterHooksInterface {

	/**
	 * Register the WooCommerce update hook.
	 */
	public function register() {
		add_action( 'woocommerce_updated', array( $this, 'handle_woocommerce_updated' ) );
	}

	/**
	 * Refresh the account, queue a store setup sync and drop the retired WooPay schedule.
	 *
	 * @internal
	 */
	public function handle_woocommerce_updated(): void {
		$container = wc_get_container();
		$container->get( WooPaymentsAccountService::class )->clear_cache();
		$container->get( WooPaymentsOperationalQueueService::class )->queue_store_setup_sync();
		$container->get( WooPaymentsWooPayExtensionSync::class )->remove_legacy_schedule_action_name_on_update();
	}
}
