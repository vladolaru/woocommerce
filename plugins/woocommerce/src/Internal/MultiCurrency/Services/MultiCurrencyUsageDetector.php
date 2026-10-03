<?php
/**
 * MultiCurrencyUsageDetector class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Detects persisted Multi-Currency usage that must be preserved.
 *
 * @since 11.2.0
 * @internal
 */
final class MultiCurrencyUsageDetector {

	/** Transient caching whether the store has Multi-Currency orders. */
	public const HAS_MC_ORDERS_TRANSIENT = 'wc_mc_has_orders';

	/**
	 * Request-level memo for the Multi-Currency orders existence check.
	 *
	 * @var bool|null
	 */
	private ?bool $has_foreign_currency_orders_memo = null;

	/**
	 * HPOS enabled resolver.
	 *
	 * @var callable|null
	 */
	private $hpos_enabled_resolver = null;

	/**
	 * Tell whether non-default currencies are configured.
	 *
	 * @since 11.2.0
	 *
	 * @return bool True when preserved configured currency data exists.
	 */
	public function has_additional_enabled_currencies(): bool {
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$store_currency     = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );

		if ( ! is_array( $enabled_currencies ) ) {
			return false;
		}

		foreach ( $enabled_currencies as $currency ) {
			if ( ! is_scalar( $currency ) ) {
				continue;
			}

			$currency = strtoupper( trim( (string) $currency ) );
			if ( '' !== $currency && $store_currency !== $currency ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether an order has persisted Multi-Currency data.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $fresh Whether to bypass request and transient caches.
	 * @return bool True when persisted Multi-Currency order data exists.
	 * @throws \RuntimeException When the order metadata query fails.
	 */
	public function has_foreign_currency_orders( bool $fresh = false ): bool {
		if ( ! $fresh && null !== $this->has_foreign_currency_orders_memo ) {
			return $this->has_foreign_currency_orders_memo;
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::HAS_MC_ORDERS_TRANSIENT );
			if ( false !== $cached ) {
				$this->has_foreign_currency_orders_memo = ( '1' === $cached );

				return $this->has_foreign_currency_orders_memo;
			}
		}

		global $wpdb;

		if ( $this->is_hpos_enabled() ) {
			$result = $wpdb->get_var(
				"SELECT EXISTS(
					SELECT 1
					FROM {$wpdb->prefix}wc_orders_meta
					WHERE meta_key = '_wcpay_multi_currency_order_exchange_rate'
					LIMIT 1)
				AS count;"
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names are provided by $wpdb.
		} else {
			$result = $wpdb->get_var(
				"SELECT EXISTS(
					SELECT 1
					FROM {$wpdb->postmeta}
					WHERE meta_key = '_wcpay_multi_currency_order_exchange_rate'
					LIMIT 1)
				AS count;"
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names are provided by $wpdb.
		}

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html( $wpdb->last_error ) );
		}

		$this->has_foreign_currency_orders_memo = ( 1 === (int) $result );
		set_transient( self::HAS_MC_ORDERS_TRANSIENT, $this->has_foreign_currency_orders_memo ? '1' : '0', HOUR_IN_SECONDS );

		return $this->has_foreign_currency_orders_memo;
	}

	/**
	 * Invalidate cached Multi-Currency order detection.
	 *
	 * @since 11.2.0
	 */
	public function invalidate_foreign_currency_orders_cache(): void {
		$this->has_foreign_currency_orders_memo = null;
		delete_transient( self::HAS_MC_ORDERS_TRANSIENT );
	}

	/**
	 * Set the HPOS enabled resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $resolver Resolver returning true when HPOS is enabled.
	 */
	public function set_hpos_enabled_resolver( callable $resolver ): void {
		$this->hpos_enabled_resolver = $resolver;
	}

	/**
	 * Tell whether HPOS order storage is enabled.
	 *
	 * @return bool True when HPOS is enabled.
	 */
	private function is_hpos_enabled(): bool {
		if ( null !== $this->hpos_enabled_resolver ) {
			return (bool) call_user_func( $this->hpos_enabled_resolver );
		}

		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
