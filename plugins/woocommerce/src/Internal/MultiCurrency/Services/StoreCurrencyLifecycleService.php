<?php
/**
 * StoreCurrencyLifecycleService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CacheInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Records the store currency, and when it changed clears the rates cache and lists the enabled manual-rate currencies
 * for the store currency notice.
 *
 * @since 11.0.0
 * @internal
 */
class StoreCurrencyLifecycleService {

	/**
	 * Option listing the manual-rate currencies the store currency notice names after the store currency changed.
	 */
	public const NOTICE_OPTION = 'wcpay_multi_currency_show_store_currency_changed_notice';

	/**
	 * Option holding the store currency Multi-Currency last saw, so a store currency change is noticed.
	 */
	public const STORE_CURRENCY_OPTION = StateBuilder::OPTION_PREFIX . '_store_currency';

	/**
	 * Multi-currency cache.
	 *
	 * @var CacheInterface
	 */
	private CacheInterface $cache;

	/**
	 * State builder.
	 *
	 * @var StateBuilder
	 */
	private StateBuilder $state_builder;

	/**
	 * Constructor.
	 *
	 * @param CacheInterface $cache         Multi-currency cache.
	 * @param StateBuilder   $state_builder State builder.
	 */
	public function __construct( CacheInterface $cache, StateBuilder $state_builder ) {
		$this->cache         = $cache;
		$this->state_builder = $state_builder;
	}

	/**
	 * Synchronize store-currency options and dependent cache state.
	 *
	 * @return bool True when the store currency changed.
	 *
	 * @since 11.0.0
	 */
	public function synchronize_store_currency(): bool {
		$store_currency = strtoupper( (string) get_option( 'woocommerce_currency' ) );

		if ( ! array_key_exists( $store_currency, (array) wc_get_container()->get( LegacyProxy::class )->call_function( 'get_woocommerce_currencies' ) ) ) {
			return false;
		}

		$last_known_currency = get_option( self::STORE_CURRENCY_OPTION, false );
		if ( ! $last_known_currency ) {
			update_option( self::STORE_CURRENCY_OPTION, $store_currency );
			return false;
		}

		if ( strtoupper( (string) $last_known_currency ) === $store_currency ) {
			return false;
		}

		update_option( self::STORE_CURRENCY_OPTION, $store_currency );
		$this->cache->delete( CacheInterface::CURRENCIES_KEY );
		$this->update_manual_rate_currencies_notice_option();

		return true;
	}

	/**
	 * Update the notice option with enabled manual-rate currency names.
	 */
	private function update_manual_rate_currencies_notice_option(): void {
		$manual_currencies = array();

		foreach ( $this->state_builder->build()->get_enabled_currencies() as $currency ) {
			$rate_type = get_option( StateBuilder::OPTION_PREFIX . '_exchange_rate_' . $currency->get_id(), false );
			if ( 'manual' === $rate_type ) {
				$manual_currencies[] = $currency->get_name();
			}
		}

		if ( ! empty( $manual_currencies ) ) {
			update_option( self::NOTICE_OPTION, $manual_currencies );
		}
	}
}
