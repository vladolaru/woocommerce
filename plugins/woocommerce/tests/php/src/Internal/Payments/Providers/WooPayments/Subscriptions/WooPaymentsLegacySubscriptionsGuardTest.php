<?php
/**
 * WooPaymentsLegacySubscriptionsGuard tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments legacy subscriptions guard.
 */
class WooPaymentsLegacySubscriptionsGuardTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Legacy subscription markers block cutover when identifier placeholders are unavailable.
	 */
	public function test_blocks_cutover_without_querying_when_identifier_placeholders_are_unavailable(): void {
		$wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();
		$wpdb->expects( $this->once() )
			->method( 'has_cap' )
			->with( 'identifier_placeholders' )
			->willReturn( false );
		$wpdb->expects( $this->never() )->method( 'prepare' );
		$wpdb->expects( $this->never() )->method( 'get_var' );

		$this->assertTrue( $this->create_guard( $wpdb )->has_legacy_stripe_billing_subscription_markers() );
	}

	/**
	 * @testdox Legacy subscription markers use identifier placeholders when the database supports them.
	 */
	public function test_queries_with_identifier_placeholders_when_supported(): void {
		$wpdb           = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->expects( $this->once() )
			->method( 'has_cap' )
			->with( 'identifier_placeholders' )
			->willReturn( true );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->expects( $this->exactly( 3 ) )
			->method( 'get_var' )
			->willReturn( 'wp_wc_orders', 'wp_wc_orders_meta', '1' );

		$this->assertTrue( $this->create_guard( $wpdb )->has_legacy_stripe_billing_subscription_markers() );
	}

	/**
	 * @testdox Missing HPOS tables skip only the HPOS scan and continue with the CPT scan.
	 */
	public function test_skips_hpos_scan_when_hpos_tables_are_missing(): void {
		$wpdb           = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->expects( $this->exactly( 2 ) )
			->method( 'has_cap' )
			->with( 'identifier_placeholders' )
			->willReturn( true );
		$wpdb->expects( $this->once() )
			->method( 'esc_like' )
			->with( 'wp_wc_orders' )
			->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->expects( $this->exactly( 2 ) )
			->method( 'get_var' )
			->willReturnCallback(
				function ( string $sql ): ?string {
					static $queries = 0;
					++$queries;
					if ( 1 === $queries ) {
						$this->assertStringContainsString( 'SHOW TABLES LIKE', $sql );
					} else {
						$this->assertStringContainsString( 'FROM %i AS posts', $sql );
					}

					return null;
				}
			);

		$this->assertFalse( $this->create_guard( $wpdb )->has_legacy_stripe_billing_subscription_markers() );
	}

	/**
	 * @testdox An HPOS table availability query error blocks cutover.
	 */
	public function test_blocks_cutover_when_hpos_table_availability_query_fails(): void {
		$wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();
		$wpdb->expects( $this->once() )
			->method( 'has_cap' )
			->with( 'identifier_placeholders' )
			->willReturn( true );
		$wpdb->expects( $this->once() )
			->method( 'esc_like' )
			->with( 'wp_wc_orders' )
			->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturnCallback(
				function ( string $sql ) use ( $wpdb ): ?string {
					$this->assertStringContainsString( 'SHOW TABLES LIKE', $sql );
					$wpdb->last_error = 'database unavailable';

					return null;
				}
			);

		$this->assertTrue( $this->create_guard( $wpdb )->has_legacy_stripe_billing_subscription_markers() );
	}

	/**
	 * @testdox An HPOS marker query error blocks cutover.
	 */
	public function test_blocks_cutover_when_hpos_marker_query_fails(): void {
		$wpdb = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'get_var', 'esc_like' ) )
			->getMock();
		$wpdb->expects( $this->once() )
			->method( 'has_cap' )
			->with( 'identifier_placeholders' )
			->willReturn( true );
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->expects( $this->exactly( 3 ) )
			->method( 'get_var' )
			->willReturnCallback(
				function ( string $sql ) use ( $wpdb ): ?string {
					static $queries = 0;
					++$queries;
					if ( $queries < 3 ) {
						$this->assertStringContainsString( 'SHOW TABLES LIKE', $sql );

						return 1 === $queries ? 'wp_wc_orders' : 'wp_wc_orders_meta';
					}

					$this->assertStringContainsString( 'FROM %i AS orders', $sql );
					$wpdb->last_error = 'database unavailable';

					return null;
				}
			);

		$this->assertTrue( $this->create_guard( $wpdb )->has_legacy_stripe_billing_subscription_markers() );
	}

	/**
	 * Create a legacy subscriptions guard with a controlled database abstraction.
	 *
	 * @param \wpdb $database WordPress database access abstraction.
	 * @return WooPaymentsLegacySubscriptionsGuard
	 */
	private function create_guard( \wpdb $database ): WooPaymentsLegacySubscriptionsGuard {
		return new class( $database ) extends WooPaymentsLegacySubscriptionsGuard {
			/**
			 * WordPress database access abstraction.
			 *
			 * @var \wpdb
			 */
			private \wpdb $database;

			/**
			 * @param \wpdb $database WordPress database access abstraction.
			 */
			public function __construct( \wpdb $database ) {
				$this->database = $database;
			}

			/**
			 * Get the controlled database abstraction.
			 *
			 * @return \wpdb WordPress database access abstraction.
			 */
			protected function get_database(): \wpdb {
				return $this->database;
			}
		};
	}
}
