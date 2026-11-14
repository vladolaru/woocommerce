<?php
/**
 * TrackerDataBuilder class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;

/**
 * Builds the Multi-Currency part of the WooCommerce tracker payload: the enabled currencies with their rate type,
 * rounding and charm, and the counts and totals of orders with an exchange rate, by currency and gateway.
 *
 * @since 11.0.0
 * @internal
 */
class TrackerDataBuilder {

	public const TRACKER_KEY = 'wcpay_multi_currency';

	/**
	 * State builder.
	 *
	 * @var StateBuilder
	 */
	private StateBuilder $state_builder;

	/**
	 * Constructor.
	 *
	 * @param StateBuilder $state_builder State builder.
	 */
	public function __construct( StateBuilder $state_builder ) {
		$this->state_builder = $state_builder;
	}

	/**
	 * Add the Multi-Currency data to the tracker payload, with the counts and totals of Multi-Currency orders by currency and gateway.
	 *
	 * @param array<string,mixed> $data            Existing tracker data.
	 * @param bool                $is_hpos_enabled Whether HPOS order storage is enabled.
	 * @return array<string,mixed>
	 */
	public function build( array $data, bool $is_hpos_enabled ): array {
		$state = $this->state_builder->build();

		$data[ self::TRACKER_KEY ] = array(
			'enabled_currencies' => $this->get_enabled_currencies_data( $state ),
			'default_currency'   => $this->get_currency_data_array( $state->get_default_currency(), $state->get_default_currency() ),
			'order_counts'       => $this->get_order_counts( $is_hpos_enabled ),
		);

		return $data;
	}

	/**
	 * Count and total the completed, processing and refunded orders that recorded an exchange rate.
	 *
	 * @param bool $is_hpos_enabled Whether HPOS order storage is enabled.
	 * @return array<string,mixed>
	 */
	private function get_order_counts( bool $is_hpos_enabled ): array {
		global $wpdb;

		$query = $is_hpos_enabled ? $this->get_hpos_order_count_query() : $this->get_posts_order_count_query();
		$rows  = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$currencies  = array();
		$total_count = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$currency = $row->currency;

			$current_counts = $currencies[ $currency ]['counts'] ?? 0;
			$current_totals = $currencies[ $currency ]['totals'] ?? 0;

			$currencies[ $currency ]['counts'] = $current_counts + $row->counts;
			$currencies[ $currency ]['totals'] = $current_totals + $row->totals;

			// Only an order with no stored payment method has a NULL gateway; the buckets keep the query's own strings.
			$currencies[ $currency ]['gateways'][ $row->gateway ?? 'unknown' ] = array(
				'counts' => $row->counts,
				'totals' => $row->totals,
			);

			$total_count += $row->counts;
		}

		return array(
			'counts'     => $total_count,
			'currencies' => $currencies,
		);
	}

	/**
	 * Build the HPOS order-count query.
	 *
	 * @return string
	 */
	private function get_hpos_order_count_query(): string {
		global $wpdb;

		return "
			SELECT
				gateway, currency, SUM(total) AS totals, COUNT(order_id) AS counts
			FROM (
				SELECT
					orders.id AS order_id, orders.payment_method as gateway, orders.total_amount as total, orders.currency as currency
				FROM
					{$wpdb->prefix}wc_orders orders
				INNER JOIN
					{$wpdb->prefix}wc_orders_meta mc_meta ON mc_meta.order_id = orders.id
					AND mc_meta.meta_key = '_wcpay_multi_currency_order_exchange_rate'
				WHERE orders.type = 'shop_order'
					AND orders.status in ( 'wc-completed', 'wc-processing', 'wc-refunded' )
				GROUP BY orders.id
			) order_gateways
			GROUP BY currency, gateway
		";
	}

	/**
	 * Build the order-count query for orders stored in the posts table.
	 *
	 * @return string
	 */
	private function get_posts_order_count_query(): string {
		global $wpdb;

		return "
			SELECT
				gateway, currency, SUM(total) AS totals, COUNT(order_id) AS counts
			FROM (
				SELECT
					orders.id AS order_id,
					MAX(CASE WHEN order_meta.meta_key = '_payment_method' THEN order_meta.meta_value END) gateway,
					MAX(CASE WHEN order_meta.meta_key = '_order_total' THEN order_meta.meta_value END) total,
					MAX(CASE WHEN order_meta.meta_key = '_order_currency' THEN order_meta.meta_value END) currency
				FROM
					{$wpdb->prefix}posts orders
				LEFT JOIN
					{$wpdb->postmeta} order_meta ON order_meta.post_id = orders.id
				INNER JOIN
					{$wpdb->postmeta} mc_meta ON mc_meta.post_id = orders.id
					AND mc_meta.meta_key = '_wcpay_multi_currency_order_exchange_rate'
				WHERE orders.post_type = 'shop_order'
					AND orders.post_status in ( 'wc-completed', 'wc-processing', 'wc-refunded' )
					AND order_meta.meta_key in ( '_payment_method', '_order_total', '_order_currency' )
				GROUP BY orders.id
			) order_gateways
			GROUP BY currency, gateway
		";
	}

	/**
	 * Get enabled non-default currency data.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_enabled_currencies_data( MultiCurrencyState $state ): array {
		$enabled_currencies = $state->get_enabled_currencies();
		$default_currency   = $state->get_default_currency();
		unset( $enabled_currencies[ $default_currency->get_code() ] );

		$rate_option_keys = array_map(
			static function ( MultiCurrencyCurrency $currency ): string {
				return 'wcpay_multi_currency_exchange_rate_' . $currency->get_id();
			},
			$enabled_currencies
		);

		if ( array() !== $rate_option_keys ) {
			// Prime caches to reduce future queries.
			wp_prime_option_caches( $rate_option_keys );
		}

		$enabled_data = array();
		foreach ( $enabled_currencies as $currency ) {
			$enabled_data[ $currency->get_code() ] = $this->get_currency_data_array( $currency, $default_currency );
		}

		return $enabled_data;
	}

	/**
	 * Get tracker data for a currency.
	 *
	 * @param MultiCurrencyCurrency $currency         Currency.
	 * @param MultiCurrencyCurrency $default_currency Default currency.
	 * @return array<string,mixed>
	 */
	private function get_currency_data_array( MultiCurrencyCurrency $currency, MultiCurrencyCurrency $default_currency ): array {
		$data = array(
			'code' => $currency->get_code(),
			'name' => html_entity_decode( $currency->get_name(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
		);

		if ( $currency->get_code() === $default_currency->get_code() ) {
			return $data;
		}

		return array_merge(
			$data,
			array(
				'is_zero_decimal' => $currency->get_is_zero_decimal(),
				'rate_type'       => $this->get_rate_type_label( $currency ),
				'price_rounding'  => $this->get_price_rounding_label( $currency ),
				'price_charm'     => $this->get_price_charm_label( $currency ),
			)
		);
	}

	/**
	 * Get the rate type label.
	 *
	 * @param MultiCurrencyCurrency $currency Currency.
	 * @return string
	 */
	private function get_rate_type_label( MultiCurrencyCurrency $currency ): string {
		$rate_type = (string) get_option( 'wcpay_multi_currency_exchange_rate_' . $currency->get_id(), 'automatic' );

		return 'automatic' === $rate_type ? 'automatic (default)' : $rate_type;
	}

	/**
	 * Get the rounding label.
	 *
	 * @param MultiCurrencyCurrency $currency Currency.
	 * @return string
	 */
	private function get_price_rounding_label( MultiCurrencyCurrency $currency ): string {
		$rounding = $currency->get_rounding();

		return $currency->get_default_rounding() === $rounding ? $rounding . ' (default)' : $rounding;
	}

	/**
	 * Get the charm label.
	 *
	 * @param MultiCurrencyCurrency $currency Currency.
	 * @return float|string
	 */
	private function get_price_charm_label( MultiCurrencyCurrency $currency ) {
		$charm = $currency->get_charm();

		return 0.0 === $charm ? '0.00 (default)' : $charm;
	}
}
