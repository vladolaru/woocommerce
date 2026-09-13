<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyUsageDetector class.
 */
class MultiCurrencyUsageDetectorTest extends WC_Unit_Test_Case {

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
	 * @testdox Should protect normalized scalar configured currencies, including unknown historical codes.
	 */
	public function test_has_additional_enabled_currencies_normalizes_scalar_entries(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( ' eur ', '', 'USD', 'OLD', array( 'GBP' ) ) );

		$this->assertTrue(
			( new MultiCurrencyUsageDetector() )->has_additional_enabled_currencies(),
			'A non-default scalar currency must protect merchant data even when it is no longer in the currency catalog.'
		);
	}

	/**
	 * @testdox Should ignore empty and store-currency configured entries.
	 */
	public function test_has_additional_enabled_currencies_ignores_empty_and_store_currency_entries(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( '', ' ', ' usd ', null, array( 'EUR' ) ) );

		$this->assertFalse(
			( new MultiCurrencyUsageDetector() )->has_additional_enabled_currencies(),
			'Empty, non-scalar, and store-currency entries must not make the feature look used.'
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
			$this->assertTrue( ( new MultiCurrencyUsageDetector() )->has_additional_enabled_currencies() );
		} finally {
			remove_filter( 'woocommerce_currency', $currency_filter, 1 );
		}
	}

	/**
	 * @testdox Should query posts order metadata when HPOS is disabled.
	 */
	public function test_has_foreign_currency_orders_queries_posts_table(): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		try {
			$this->assertTrue( $detector->has_foreign_currency_orders() );
			$this->assertStringContainsString( 'postmeta', $queries[0] );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should query HPOS order metadata when HPOS is enabled.
	 */
	public function test_has_foreign_currency_orders_queries_hpos_table(): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => true );

		try {
			$this->assertTrue( $detector->has_foreign_currency_orders() );
			$this->assertStringContainsString( 'wc_orders_meta', $queries[0] );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should detect persisted order metadata in posts storage.
	 */
	public function test_has_foreign_currency_orders_detects_real_posts_metadata(): void {
		$order_id = $this->factory->post->create( array( 'post_type' => 'shop_order' ) );
		add_post_meta( $order_id, '_wcpay_multi_currency_order_exchange_rate', '0.9' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		$this->assertTrue( $detector->has_foreign_currency_orders( true ) );
	}

	/**
	 * @testdox Should detect persisted order metadata in HPOS storage.
	 */
	public function test_has_foreign_currency_orders_detects_real_hpos_metadata(): void {
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
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => true );

		$this->assertTrue( $detector->has_foreign_currency_orders( true ) );
	}

	/**
	 * @testdox Should reuse request and transient cache values unless a fresh check is requested.
	 */
	public function test_has_foreign_currency_orders_reuses_caches_and_fresh_bypasses_them(): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		try {
			$this->assertTrue( $detector->has_foreign_currency_orders() );
			$this->assertTrue( $detector->has_foreign_currency_orders() );
			$this->assertSame( 1, count( $queries ), 'The request memo should avoid a second query.' );
			$this->assertSame( '1', get_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT ) );

			$this->assertTrue( $detector->has_foreign_currency_orders( true ) );
			$this->assertSame( 2, count( $queries ), 'A fresh check must bypass the request memo and transient.' );

			$cached_detector = new MultiCurrencyUsageDetector();
			$cached_detector->set_hpos_enabled_resolver( static fn(): bool => false );
			$this->assertTrue( $cached_detector->has_foreign_currency_orders() );
			$this->assertSame( 2, count( $queries ), 'A new detector should reuse the one-hour transient.' );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should invalidate cached foreign order detection after the first multi-currency order.
	 */
	public function test_invalidate_foreign_currency_orders_cache_clears_request_and_transient_cache(): void {
		$queries      = array();
		$query_filter = $this->add_existence_query_filter( $queries );
		$detector     = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		try {
			$detector->has_foreign_currency_orders();
			$detector->invalidate_foreign_currency_orders_cache();

			$this->assertFalse( get_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT ) );
			$this->assertTrue( $detector->has_foreign_currency_orders() );
			$this->assertSame( 2, count( $queries ), 'Invalidation must also clear the request memo.' );
		} finally {
			remove_filter( 'query', $query_filter );
		}
	}

	/**
	 * @testdox Should throw when the foreign order existence query fails.
	 */
	public function test_has_foreign_currency_orders_throws_for_database_errors(): void {
		global $wpdb;

		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );
		$original_postmeta = $wpdb->postmeta;
		$suppress_errors   = $wpdb->suppress_errors( true );
		$wpdb->postmeta    = "{$wpdb->prefix}missing_multi_currency_order_meta";

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'missing_multi_currency_order_meta' );

		try {
			$detector->has_foreign_currency_orders();
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
