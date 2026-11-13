<?php
/**
 * WooPaymentsCompositionDataSettler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Settles the account before an order is read under its claim, then runs the read, the composition of the payment's
 * effects and their write with platform cache refreshes stopped.
 *
 * Inside the segment, no read through WooPaymentsDatabaseCache refreshes from the platform: the account, its default
 * currency and country included, is served as settled. Multi-Currency's own cache, which holds the currency rates, is
 * not covered. At checkout and on a completed capture the segment ends when the composed outcome is returned; the
 * payment lifecycle's write after it makes no cache-backed platform read.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsCompositionDataSettler {

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Database cache the account is read through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @param WooPaymentsDatabaseCache  $database_cache  Database cache.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsDatabaseCache $database_cache ): void {
		$this->account_service = $account_service;
		$this->database_cache  = $database_cache;
	}

	/**
	 * Read the account, refreshing it when due, then run the segment with platform cache refreshes stopped.
	 *
	 * @since 11.4.0
	 *
	 * @param callable $segment Reads the order, then composes and writes its effects.
	 * @return mixed What the segment returns.
	 */
	public function run_settled( callable $segment ) {
		$this->account_service->get_cached_account_data();

		return $this->database_cache->run_without_refresh( $segment );
	}
}
