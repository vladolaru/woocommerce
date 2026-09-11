<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyUsageDetector class.
 */
class MultiCurrencyUsageDetectorTest extends WC_Unit_Test_Case {

	/**
	 * Original WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $original_wpdb;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->original_wpdb = $wpdb;
		update_option( 'woocommerce_currency', 'USD' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( 'wc_mc_has_orders' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
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
	 * @testdox Should query posts order metadata when HPOS is disabled.
	 */
	public function test_has_foreign_currency_orders_queries_posts_table(): void {
		$database = $this->replace_database( '1' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		$this->assertTrue( $detector->has_foreign_currency_orders() );
		$this->assertStringContainsString( 'postmeta', $database->queries[0] );
	}

	/**
	 * @testdox Should query HPOS order metadata when HPOS is enabled.
	 */
	public function test_has_foreign_currency_orders_queries_hpos_table(): void {
		$database = $this->replace_database( '1' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => true );

		$this->assertTrue( $detector->has_foreign_currency_orders() );
		$this->assertStringContainsString( 'wc_orders_meta', $database->queries[0] );
	}

	/**
	 * @testdox Should reuse request and transient cache values unless a fresh check is requested.
	 */
	public function test_has_foreign_currency_orders_reuses_caches_and_fresh_bypasses_them(): void {
		$database = $this->replace_database( '1' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		$this->assertTrue( $detector->has_foreign_currency_orders() );
		$this->assertTrue( $detector->has_foreign_currency_orders() );
		$this->assertSame( 1, count( $database->queries ), 'The request memo should avoid a second query.' );
		$this->assertSame( '1', get_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT ) );

		$this->assertTrue( $detector->has_foreign_currency_orders( true ) );
		$this->assertSame( 2, count( $database->queries ), 'A fresh check must bypass the request memo and transient.' );

		$cached_detector = new MultiCurrencyUsageDetector();
		$cached_detector->set_hpos_enabled_resolver( static fn(): bool => false );
		$this->assertTrue( $cached_detector->has_foreign_currency_orders() );
		$this->assertSame( 2, count( $database->queries ), 'A new detector should reuse the one-hour transient.' );
	}

	/**
	 * @testdox Should invalidate cached foreign order detection after the first multi-currency order.
	 */
	public function test_invalidate_foreign_currency_orders_cache_clears_request_and_transient_cache(): void {
		$database = $this->replace_database( '1' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		$detector->has_foreign_currency_orders();
		$detector->invalidate_foreign_currency_orders_cache();

		$this->assertFalse( get_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT ) );
		$this->assertTrue( $detector->has_foreign_currency_orders() );
		$this->assertSame( 2, count( $database->queries ), 'Invalidation must also clear the request memo.' );
	}

	/**
	 * @testdox Should throw when the foreign order existence query fails.
	 */
	public function test_has_foreign_currency_orders_throws_for_database_errors(): void {
		$database = $this->replace_database( null, 'Table unavailable' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Table unavailable' );

		$detector->has_foreign_currency_orders();
	}

	/**
	 * Replace the database object with a recording existence-query double.
	 *
	 * @param string|null $result Query result.
	 * @param string      $error  Database error.
	 * @return object{prefix:string,postmeta:string,last_error:string,queries:array<int,string>,get_var:callable}
	 */
	private function replace_database( ?string $result, string $error = '' ): object {
		global $wpdb;

		$wpdb = new class( $this->original_wpdb, $result, $error ) {
			/** @var \wpdb */
			private $database;

			/** @var string */
			public $prefix = 'wp_';

			/** @var string */
			public $postmeta = 'wp_postmeta';

			/** @var string */
			public $last_error;

			/** @var array<int,string> */
			public $queries = array();

			/** @var string|null */
			private $result;

			/**
			 * Initialize the recording database double.
			 *
			 * @param \wpdb       $database Database object to proxy for WordPress option reads.
			 * @param string|null $result   Query result.
			 * @param string      $error    Database error.
			 */
			public function __construct( \wpdb $database, ?string $result, string $error ) {
				$this->database   = $database;
				$this->result     = $result;
				$this->last_error = $error;
			}

			/**
			 * Record and return an existence query result.
			 *
			 * @param string $query Query.
			 * @return string|null
			 */
			public function get_var( string $query ): ?string {
				$this->queries[] = $query;

				return $this->result;
			}

			/**
			 * Proxy WordPress option reads to the real database object.
			 *
			 * @param string $query Query.
			 * @return array<int,object>|null
			 */
			public function get_results( string $query ): ?array {
				return $this->database->get_results( $query );
			}

			/**
			 * Proxy methods used outside the order existence query.
			 *
			 * @param string       $method Method name.
			 * @param array<mixed> $args   Method arguments.
			 * @return mixed
			 */
			public function __call( string $method, array $args ) {
				return $this->database->{$method}( ...$args );
			}

			/**
			 * Proxy WordPress database properties needed by option reads.
			 *
			 * @param string $property Property name.
			 * @return mixed
			 */
			public function __get( string $property ) {
				return $this->database->{$property};
			}

			/**
			 * Proxy WordPress database property writes needed by option reads.
			 *
			 * @param string $property Property name.
			 * @param mixed  $value    Property value.
			 */
			public function __set( string $property, $value ): void {
				$this->database->{$property} = $value;
			}
		};

		return $wpdb;
	}
}
