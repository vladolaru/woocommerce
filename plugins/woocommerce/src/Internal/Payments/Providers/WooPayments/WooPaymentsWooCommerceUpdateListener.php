<?php
/**
 * WooPaymentsWooCommerceUpdateListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\ExtensionSync;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Runs the WooPayments duties of a WooCommerce update for a connected store.
 *
 * The client runs them on its own update action (client 11.1.0 `includes/class-wc-payments-account.php:143,147`,
 * `includes/woopay/class-woopay-scheduler.php:50`). The built-in WooPayments updates with WooCommerce, so a WooCommerce
 * update is that event; it finishes on the init of any request, and the services are resolved only when it fires.
 *
 * @since 11.2.0
 * @internal
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
	 * A failed fetch keeps the saved account data. Client 11.3.0 includes/class-wc-payments-account.php:153-154.
	 *
	 * @since 11.4.0
	 *
	 * @internal
	 */
	public function handle_woocommerce_updated(): void {
		$container = wc_get_container();
		$container->get( WooPaymentsAccountService::class )->refresh_account_data();
		$container->get( WooPaymentsStoreSetupReportController::class )->queue_store_setup_sync();
		$container->get( ExtensionSync::class )->remove_retired_validation_schedule();
	}
}
