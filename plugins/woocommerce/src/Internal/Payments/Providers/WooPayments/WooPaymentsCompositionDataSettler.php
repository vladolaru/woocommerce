<?php
/**
 * WooPaymentsCompositionDataSettler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Settles the cached data an order's payment effects are composed from, then runs the order's read and the composition
 * with cache refreshes stopped.
 *
 * Composition reads the account, its default currency and country, and order notes format amounts from the
 * Multi-Currency state. Both are cached and can be refreshed from the platform when due. Settling them before the order
 * is read under its claim moves any such refresh ahead of the read, so the payment is decided on the order as it is
 * after the last platform call.
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
	 * Read the account, refreshing it when due, and build the Multi-Currency state, then run the segment that reads the
	 * order and composes and writes its effects with cache refreshes stopped.
	 *
	 * @since 11.4.0
	 *
	 * @param callable $segment Reads the order, then composes and writes its effects.
	 * @return mixed What the segment returns.
	 */
	public function run_settled( callable $segment ) {
		$this->account_service->get_cached_account_data();
		WooPaymentsCurrencyUtils::prepare_explicit_order_price();

		return $this->database_cache->run_without_refresh( $segment );
	}
}
