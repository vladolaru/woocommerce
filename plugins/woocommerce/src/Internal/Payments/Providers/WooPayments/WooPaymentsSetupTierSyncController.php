<?php
/**
 * WooPaymentsSetupTierSyncController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Keeps the built-in WooPayments setup tier current when the WooPayments extension writes its account cache.
 *
 * Registered only while the WooPayments extension owns payments.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsSetupTierSyncController implements RegisterHooksInterface {

	/**
	 * Register the account cache write hooks.
	 */
	public function register(): void {
		add_action( 'add_option_wcpay_account_data', array( $this, 'handle_add_option_wcpay_account_data' ), 10, 2 );
		add_action( 'update_option_wcpay_account_data', array( $this, 'handle_update_option_wcpay_account_data' ), 10, 2 );
	}

	/**
	 * Handle the add_option_wcpay_account_data hook.
	 *
	 * @internal
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Account cache the extension wrote.
	 */
	public function handle_add_option_wcpay_account_data( $option, $value ): void {
		unset( $option );
		$this->sync_setup_tier( $value );
	}

	/**
	 * Handle the update_option_wcpay_account_data hook.
	 *
	 * @internal
	 *
	 * @param mixed $old_value Previous account cache.
	 * @param mixed $value     Account cache the extension wrote.
	 */
	public function handle_update_option_wcpay_account_data( $old_value, $value ): void {
		unset( $old_value );
		$this->sync_setup_tier( $value );
	}

	/**
	 * Re-derive the stored setup tier from the account cache.
	 *
	 * The account service resolves here rather than at registration, since most requests never write the cache.
	 *
	 * @param mixed $account_cache Account cache the extension wrote.
	 */
	private function sync_setup_tier( $account_cache ): void {
		wc_get_container()->get( WooPaymentsAccountService::class )->sync_setup_tier_from_extension_account_cache( $account_cache );
	}
}
