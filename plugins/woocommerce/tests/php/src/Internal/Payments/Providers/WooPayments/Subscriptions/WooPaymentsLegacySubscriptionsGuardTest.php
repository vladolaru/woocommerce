<?php
/**
 * WooPaymentsLegacySubscriptionsGuard tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\LateLoadedSubscriptions;
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

		$this->assertTrue( $this->create_guard( $wpdb )->is_bundled_stripe_billing_store() );
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

		$this->assertTrue( $this->create_guard( $wpdb )->is_bundled_stripe_billing_store() );
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

		$this->assertFalse( $this->create_guard( $wpdb )->is_bundled_stripe_billing_store() );
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

		$this->assertTrue( $this->create_guard( $wpdb )->is_bundled_stripe_billing_store() );
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

		$this->assertTrue( $this->create_guard( $wpdb )->is_bundled_stripe_billing_store() );
	}

	/**
	 * @testdox With WooCommerce Subscriptions active the store is never bundled, whatever its Stripe Billing data or bundled flag (spec section 7).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_store_with_woocommerce_subscriptions_active_is_not_bundled(): void {
		require_once __DIR__ . '/../Fixtures/LateLoadedSubscriptions.php';
		class_alias( LateLoadedSubscriptions::class, 'WC_Subscriptions' );
		register_post_type( 'shop_subscription' );
		$subscription_id = wp_insert_post(
			array(
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
			)
		);
		update_post_meta( $subscription_id, '_wcpay_subscription_id', 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );
		update_option( '_wcpay_feature_subscriptions', '1' );

		$this->assertFalse( ( new WooPaymentsLegacySubscriptionsGuard() )->is_bundled_stripe_billing_store() );
	}

	/**
	 * @testdox Without WooCommerce Subscriptions the store is bundled while a subscription is still Stripe-billed.
	 */
	public function test_a_store_without_woocommerce_subscriptions_and_a_stripe_billed_subscription_is_bundled(): void {
		register_post_type( 'shop_subscription' );
		$subscription_id = wp_insert_post(
			array(
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-cancelled',
			)
		);
		update_post_meta( $subscription_id, '_wcpay_subscription_id', 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );

		$this->assertTrue( ( new WooPaymentsLegacySubscriptionsGuard() )->is_bundled_stripe_billing_store() );
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
