<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\UsageDetector;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the UsageDetector class.
 */
class UsageDetectorTest extends WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'USD' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( 'wc_mc_has_orders' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( 'wc_mc_has_orders' );
		parent::tear_down();
	}

	/**
	 * @testdox Should count normalized scalar configured currencies, including unknown historical codes, and ignore empty and store-currency entries: $_dataName.
	 * @dataProvider provide_configured_currency_lists
	 *
	 * @param array<int,mixed> $enabled  Stored enabled currency list.
	 * @param bool             $expected Whether an additional currency is configured.
	 */
	public function test_has_additional_enabled_currencies_counts_only_additional_scalar_entries( array $enabled, bool $expected ): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', $enabled );

		$this->assertSame( $expected, ( new UsageDetector() )->has_configured_additional_currencies() );
	}

	/**
	 * Stored lists: a non-default scalar currency protects merchant data even when it is no longer in the currency
	 * catalog; empty, non-scalar and store-currency entries do not make the feature look used.
	 *
	 * @return array<string,array{0:array<int,mixed>,1:bool}>
	 */
	public static function provide_configured_currency_lists(): array {
		return array(
			'scalar entries with an unknown code'       => array( array( ' eur ', '', 'USD', 'OLD', array( 'GBP' ) ), true ),
			'empty, non-scalar and store-currency only' => array( array( '', ' ', ' usd ', null, array( 'EUR' ) ), false ),
		);
	}

	/**
	 * @testdox Should use the configured store currency when the display currency is filtered.
	 */
	public function test_has_additional_enabled_currencies_uses_the_configured_currency_when_the_display_currency_is_filtered(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$currency_filter = static function ( string $currency ): string {
			unset( $currency );

			return 'EUR';
		};
		add_filter( 'woocommerce_currency', $currency_filter, 1 );

		try {
			$this->assertTrue( ( new UsageDetector() )->has_configured_additional_currencies() );
		} finally {
			remove_filter( 'woocommerce_currency', $currency_filter, 1 );
		}
	}

	/**
	 * @testdox Should query the order metadata of the order storage in use: $_dataName.
	 * @testWith ["no", "postmeta"]
	 *           ["yes", "wc_orders_meta"]
	 *
	 * @param string $hpos  The HPOS usage option.
	 * @param string $table Table the existence query reads.
	 */
	public function test_has_multi_currency_orders_queries_the_order_storage_in_use( string $hpos, string $table ): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new UsageDetector();
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => $hpos );

		try {
			$this->assertTrue( $detector->has_multi_currency_orders() );
			$this->assertStringContainsString( $table, $queries[0] );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should detect persisted order metadata in posts storage.
	 */
	public function test_has_multi_currency_orders_detects_real_posts_metadata(): void {
		$order_id = $this->factory->post->create( array( 'post_type' => 'shop_order' ) );
		add_post_meta( $order_id, '_wcpay_multi_currency_order_exchange_rate', '0.9' );
		$detector = new UsageDetector();
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );

		$this->assertTrue( $detector->has_multi_currency_orders( true ) );
	}

	/**
	 * @testdox Should detect persisted order metadata in HPOS storage.
	 */
	public function test_has_multi_currency_orders_detects_real_hpos_metadata(): void {
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'The real HPOS metadata test requires HPOS to be enabled.' );
		}

		$order = wc_create_order();
		wc_get_container()->get( OrdersTableDataStoreMeta::class )->add_meta(
			$order,
			new \WC_Meta_Data(
				array(
					'key'   => '_wcpay_multi_currency_order_exchange_rate',
					'value' => '0.9',
				)
			)
		);
		$detector = new UsageDetector();
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'yes' );

		$this->assertTrue( $detector->has_multi_currency_orders( true ) );
	}

	/**
	 * @testdox Should reuse request and transient cache values unless a fresh check is requested.
	 */
	public function test_has_multi_currency_orders_reuses_caches_and_fresh_bypasses_them(): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new UsageDetector();
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );

		try {
			$this->assertTrue( $detector->has_multi_currency_orders() );
			$this->assertTrue( $detector->has_multi_currency_orders() );
			$this->assertSame( 1, count( $queries ), 'The request memo should avoid a second query.' );
			$this->assertSame( '1', get_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT ) );

			$this->assertTrue( $detector->has_multi_currency_orders( true ) );
			$this->assertSame( 2, count( $queries ), 'A fresh check must bypass the request memo and transient.' );

			$cached_detector = new UsageDetector();
			add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );
			$this->assertTrue( $cached_detector->has_multi_currency_orders() );
			$this->assertSame( 2, count( $queries ), 'A new detector should reuse the one-hour transient.' );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should throw when the foreign order existence query fails.
	 */
	public function test_has_multi_currency_orders_throws_for_database_errors(): void {
		global $wpdb;

		$detector = new UsageDetector();
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );
		$original_postmeta = $wpdb->postmeta;
		$suppress_errors   = $wpdb->suppress_errors( true );
		$wpdb->postmeta    = "{$wpdb->prefix}missing_multi_currency_order_meta";

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'missing_multi_currency_order_meta' );

		try {
			$detector->has_multi_currency_orders();
		} finally {
			$wpdb->postmeta = $original_postmeta;
			$wpdb->suppress_errors( $suppress_errors );
		}
	}

	/**
	 * Record and replace only Multi-Currency existence queries.
	 *
	 * @param array<int,string> $queries Queries captured by reference.
	 * @return callable Query filter.
	 */
	private function add_existence_query_filter( array &$queries ): callable {
		$query_filter = static function ( $query ) use ( &$queries ) {
			if ( false === strpos( $query, '_wcpay_multi_currency_order_exchange_rate' ) ) {
				return $query;
			}

			$queries[] = $query;

			return 'SELECT 1 AS count';
		};
		add_filter( 'query', $query_filter );

		return $query_filter;
	}
}
