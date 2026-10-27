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

		// The rates are chosen when above zero, else the amount is kept (client 11.2.0 #12114, `includes/multi-currency/Analytics.php:606-611`).
		$this->assertSame(
			"CASE WHEN wcpay_multicurrency_stripe_exchange_rate_meta.meta_value > 0 THEN ROUND($net_total / wcpay_multicurrency_stripe_exchange_rate_meta.meta_value, $decimals) WHEN wcpay_multicurrency_exchange_rate_meta.meta_value > 0 THEN ROUND($net_total * wcpay_multicurrency_exchange_rate_meta.meta_value, $decimals) ELSE $net_total END as net_total,",
			$result[0]
		);
	}

	/**
	 * @testdox Should convert report figures with the first saved rate above zero, or keep them: $_dataName.
	 *
	 * Rows from client 11.2.0 `tests/unit/multi-currency/test-class-analytics.php:658-696`: a stored net total of 63.05 shown
	 * in the order currency, and product revenue and a taxable amount of 54.13 shown in the store currency.
	 *
	 * @dataProvider report_rates_provider
	 *
	 * @param string $processor_rate Saved processor rate.
	 * @param string $order_rate     Saved order rate.
	 * @param float  $expected_order Net total in the order currency.
	 * @param float  $expected_store Product revenue and taxable amount in the store currency.
	 */
	public function test_converts_report_figures_with_the_first_usable_saved_rate( string $processor_rate, string $order_rate, float $expected_order, float $expected_store ): void {
		global $wpdb;

		$sut       = new AnalyticsSqlFilters();
		$net_total = "{$wpdb->prefix}wc_order_stats.net_total";
		$columns   = array(
			'wcpay_multicurrency_stripe_exchange_rate_meta.meta_value' => $wpdb->prepare( '%s', $processor_rate ),
			'wcpay_multicurrency_exchange_rate_meta.meta_value'        => $wpdb->prepare( '%s', $order_rate ),
			'wcpay_multicurrency_default_currency_meta.meta_value'     => "'USD'",
		);

		$order_sql   = strtr( $sut->extend_order_select_clauses( array( $net_total ) )[0], $columns + array( $net_total => '63.05' ) );
		$product_sql = strtr( $sut->extend_select_clauses( array( 'product_net_revenue' ), 'products_subquery', false )[0], $columns + array( 'product_net_revenue' => '54.13' ) );
		$taxable_sql = strtr( $sut->extend_select_clauses( array( 'SUM(taxable_amount)' ), 'taxes_subquery', false )[0], $columns + array( 'taxable_amount' => '54.13' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expressions built from literals above.
		$this->assertEquals( $expected_order, $wpdb->get_var( "SELECT {$order_sql}" ), 'Orders report net total' );
		$this->assertEquals( $expected_store, $wpdb->get_var( "SELECT {$product_sql}" ), 'Products report revenue' );
		$this->assertEquals( $expected_store, $wpdb->get_var( "SELECT {$taxable_sql}" ), 'Taxes report taxable amount' );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string,array{string,string,float,float}> */
	public static function report_rates_provider(): array {
		return array(
			'valid processor rate'    => array( '1.1648', '0.8585', 54.13, 63.05 ),
			'negative processor rate' => array( '-1', '0.8585', 54.13, 63.05 ),
			'zero processor rate'     => array( '0', '0.8585', 54.13, 63.05 ),
			'text processor rate'     => array( 'invalid', '0.8585', 54.13, 63.05 ),
			'empty processor rate'    => array( '', '0.8585', 54.13, 63.05 ),
			'neither rate usable'     => array( '-1', 'invalid', 63.05, 54.13 ),
		);
	}
}
