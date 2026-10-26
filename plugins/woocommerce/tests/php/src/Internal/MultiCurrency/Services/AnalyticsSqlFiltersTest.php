<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsSqlFilters;
use WC_Unit_Test_Case;

/**
 * Tests for the AnalyticsSqlFilters class.
 */
class AnalyticsSqlFiltersTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project select clauses for legacy order storage.
	 */
	public function test_extends_select_clauses_for_posts_order_storage(): void {
		$sut = new AnalyticsSqlFilters();

		$result = $sut->extend_select_clauses(
			array(
				', SUM(product_net_revenue) AS product_net_revenue',
				', discount_amount',
			),
			'products_stats_total',
			false
		);

		$this->assertStringContainsString(
			'CASE WHEN wcpay_multicurrency_default_currency_meta.meta_value IS NOT NULL',
			$result[0]
		);
		$this->assertStringContainsString(
			'ROUND(product_net_revenue * wcpay_multicurrency_stripe_exchange_rate_meta.meta_value, 2)',
			$result[0]
		);
		$this->assertContains(
			', wcpay_multicurrency_currency_meta.meta_value AS order_currency',
			$result
		);
		$this->assertContains(
			', wcpay_multicurrency_default_currency_meta.meta_value AS order_default_currency',
			$result
		);
		$this->assertContains(
			', wcpay_multicurrency_exchange_rate_meta.meta_value AS exchange_rate',
			$result
		);
		$this->assertContains(
			', wcpay_multicurrency_stripe_exchange_rate_meta.meta_value AS stripe_exchange_rate',
			$result
		);
	}

	/**
	 * @testdox Should project select clauses for HPOS order storage.
	 */
	public function test_extends_select_clauses_for_hpos_order_storage(): void {
		$sut = new AnalyticsSqlFilters();

		$result = $sut->extend_select_clauses(
			array( ', SUM(discount_amount) AS discount_amount' ),
			'orders_stats_total',
			true
		);

		$this->assertContains(
			', wcpay_multicurrency_order_currency.currency AS order_currency',
			$result
		);
		$this->assertContains(
			', wcpay_multicurrency_default_currency_meta.meta_value AS order_default_currency',
			$result
		);
	}

	/**
	 * @testdox Should project join clauses for each order storage.
	 * @dataProvider join_clauses_provider
	 *
	 * @param bool     $hpos           Whether orders are stored in the HPOS tables.
	 * @param string[] $expected_joins Joins the result must contain.
	 */
	public function test_extends_join_clauses_for_order_storage( bool $hpos, array $expected_joins ): void {
		global $wpdb;

		$sut = new AnalyticsSqlFilters();

		$result = $sut->extend_join_clauses(
			array( "INNER JOIN {$wpdb->prefix}wc_order_stats ON 1 = 1" ),
			'orders_stats_total',
			$hpos
		);

		foreach ( $expected_joins as $expected_join ) {
			$this->assertContains( $expected_join, $result );
		}
	}

	/** @return array<string,array{bool,string[]}> */
	public static function join_clauses_provider(): array {
		global $wpdb;

		return array(
			'posts' => array(
				false,
				array(
					"LEFT JOIN {$wpdb->postmeta} wcpay_multicurrency_currency_meta ON {$wpdb->prefix}wc_order_stats.order_id = wcpay_multicurrency_currency_meta.post_id AND wcpay_multicurrency_currency_meta.meta_key = '_order_currency'",
					"LEFT JOIN {$wpdb->postmeta} wcpay_multicurrency_exchange_rate_meta ON {$wpdb->prefix}wc_order_stats.order_id = wcpay_multicurrency_exchange_rate_meta.post_id AND wcpay_multicurrency_exchange_rate_meta.meta_key = '_wcpay_multi_currency_order_exchange_rate'",
				),
			),
			'hpos'  => array(
				true,
				array(
					"LEFT JOIN {$wpdb->prefix}wc_orders wcpay_multicurrency_order_currency ON {$wpdb->prefix}wc_order_stats.order_id = wcpay_multicurrency_order_currency.id",
					"LEFT JOIN {$wpdb->prefix}wc_orders_meta wcpay_multicurrency_default_currency_meta ON {$wpdb->prefix}wc_order_stats.order_id = wcpay_multicurrency_default_currency_meta.order_id AND wcpay_multicurrency_default_currency_meta.meta_key = '_wcpay_multi_currency_order_default_currency'",
				),
			),
		);
	}

	/**
	 * @testdox Should project currency where clauses for legacy order storage.
	 */
	public function test_extends_currency_where_clauses_for_posts_order_storage(): void {
		$sut = new AnalyticsSqlFilters();

		$result = $sut->extend_where_clauses(
			array( 'AND status != "trash"' ),
			array(
				'currency_is'     => array( 'GBP', 'JPY' ),
				'currency_is_not' => array( 'AUD' ),
				'currency'        => 'EUR',
			),
			false
		);

		$this->assertContains(
			"AND wcpay_multicurrency_currency_meta.meta_value IN ('GBP', 'JPY')",
			$result
		);
		$this->assertContains(
			"AND wcpay_multicurrency_currency_meta.meta_value NOT IN ('AUD')",
			$result
		);
		$this->assertContains(
			"AND wcpay_multicurrency_currency_meta.meta_value = 'EUR'",
			$result
		);
	}

	/**
	 * @testdox Should escape currency values in prepared where clauses.
	 */
	public function test_escapes_currency_values_in_where_clauses(): void {
		$sut = new AnalyticsSqlFilters();

		$result = $sut->extend_where_clauses(
			array(),
			array(
				'currency_is' => array( "GBP' OR '1'='1" ),
				'currency'    => "EUR' OR '1'='1",
			),
			false
		);

		$this->assertCount( 2, $result, 'Both an IN-list and a single-currency clause should be produced.' );

		foreach ( $result as $clause ) {
			$this->assertStringStartsWith( 'AND wcpay_multicurrency_currency_meta.meta_value', $clause );
			$this->assertStringNotContainsString( "OR '1'='1'", $clause, 'The injected quote must be escaped so it cannot break out of the string literal.' );
		}
	}

	/**
	 * @testdox Should project selected currency order select clauses.
	 */
	public function test_extends_order_select_clauses_with_exchange_rates(): void {
		global $wpdb;

		$sut       = new AnalyticsSqlFilters();
		$net_total = "{$wpdb->prefix}wc_order_stats.net_total";
		$decimals  = wc_get_price_decimals();

		$result = $sut->extend_order_select_clauses(
			array( "$net_total," )
		);

		$this->assertSame(
			"CASE WHEN wcpay_multicurrency_stripe_exchange_rate_meta.meta_value IS NOT NULL THEN ROUND($net_total / wcpay_multicurrency_stripe_exchange_rate_meta.meta_value, $decimals) ELSE ROUND($net_total * wcpay_multicurrency_exchange_rate_meta.meta_value, $decimals) END as net_total,",
			$result[0]
		);
	}
}
