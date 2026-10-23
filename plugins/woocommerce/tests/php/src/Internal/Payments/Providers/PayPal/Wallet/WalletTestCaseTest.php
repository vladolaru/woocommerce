<?php
/**
 * Tests for the network guard and the HTTP stubbing of WalletTestCase.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet;

use WC_Customer;
use WC_Tax;
use WC_Session_Handler;
use WP_Block_Type_Registry;
use WP_Error;

/**
 * The wallet test base never lets a request reach the network.
 *
 * @group paypal-wallet
 */
class WalletTestCaseTest extends WalletTestCase {

	/**
	 * What the tearDown of the write test deleted, in order.
	 *
	 * @var string[]
	 */
	private static array $deleted = array();

	/**
	 * What the earlier tests of the tax rate, customer and session pairs left to check: the ID of the rate, the original
	 * and the changed address, the original session and the session the test set.
	 *
	 * @var array<string, mixed>
	 */
	private static array $remembered = array();

	/**
	 * @testdox Should answer an unstubbed request with a WP_Error instead of sending it.
	 */
	public function test_unstubbed_request_yields_wp_error(): void {
		$response = wp_remote_get( 'https://api-m.paypal.com/v1/unstubbed' );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'unstubbed_http', $response->get_error_code() );
		$this->assertSame( 'Unstubbed request to https://api-m.paypal.com/v1/unstubbed', $response->get_error_message() );
		$this->assertCount( 1, $this->http_requests, 'The request should still be recorded' );
	}

	/**
	 * @testdox Should let a callable stub key responses by URL.
	 */
	public function test_callable_stub_receives_request_and_url(): void {
		$this->stub_http(
			function ( $request, $url ) {
				return false !== strpos( $url, '/first' ) ? $this->http_response( 200, 'one' ) : $this->http_response( 404, 'none' );
			}
		);

		$first  = wp_remote_get( 'https://example.com/first' );
		$second = wp_remote_get( 'https://example.com/second' );

		$this->assertSame( 'one', wp_remote_retrieve_body( $first ) );
		$this->assertSame( 404, wp_remote_retrieve_response_code( $second ) );
		$this->assertCount( 2, $this->http_requests );
	}

	/**
	 * Hooks added here fire during this test's own tearDown, which deletes what the test wrote.
	 *
	 * @testdox Should write the option and the transient it is given.
	 */
	public function test_set_wallet_option_and_transient_write_the_values(): void {
		self::$deleted = array();
		add_action(
			'delete_option_wallet_test_case_option',
			static function () {
				self::$deleted[] = 'option';
			}
		);
		add_action(
			'delete_transient_wallet_test_case_transient',
			static function () {
				self::$deleted[] = 'transient';
			}
		);

		$this->set_wallet_option( 'wallet_test_case_option', 'kept' );
		$this->set_wallet_transient( 'wallet_test_case_transient', array( 'kept' ), HOUR_IN_SECONDS );

		$this->assertSame( 'kept', get_option( 'wallet_test_case_option' ) );
		$this->assertSame( array( 'kept' ), get_transient( 'wallet_test_case_transient' ) );
	}

	/**
	 * Runs after the test above, so its tearDown has run.
	 *
	 * @testdox Should delete the option and the transient of the previous test on tearDown.
	 * @depends test_set_wallet_option_and_transient_write_the_values
	 */
	public function test_set_wallet_option_and_transient_are_deleted_on_tear_down(): void {
		$this->assertSame( array( 'option', 'transient' ), self::$deleted );
	}

	/**
	 * Hooks added here fire during this test's own tearDown, which deletes what the test added.
	 *
	 * @testdox Should add a flat tax rate that applies to every country.
	 */
	public function test_insert_flat_tax_rate_adds_the_rate(): void {
		self::$remembered['deleted_rates'] = array();
		add_action(
			'woocommerce_tax_rate_deleted',
			static function ( $rate_id ) {
				self::$remembered['deleted_rates'][] = (int) $rate_id;
			}
		);

		$rate_id = $this->insert_flat_tax_rate( '7.5000' );

		self::$remembered['rate_id'] = $rate_id;
		$rate                        = WC_Tax::_get_tax_rate( $rate_id ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->assertSame( '7.5000', $rate['tax_rate'] );
		$this->assertSame( '', $rate['tax_rate_country'] );
		$this->assertSame( '', $rate['tax_rate_class'] );
		$this->assertSame( '1', $rate['tax_rate_shipping'] );
	}

	/**
	 * Runs after the test above, so its tearDown has run.
	 *
	 * @testdox Should delete the tax rate of the previous test on tearDown.
	 * @depends test_insert_flat_tax_rate_adds_the_rate
	 */
	public function test_flat_tax_rate_is_deleted_on_tear_down(): void {
		$this->assertSame( array( self::$remembered['rate_id'] ), self::$remembered['deleted_rates'] );
	}

	/**
	 * @testdox Should leave a customer with all seven shipping fields changed.
	 */
	public function test_customer_address_is_changed_by_the_test(): void {
		$customer = WC()->customer;
		$this->assertInstanceOf( WC_Customer::class, $customer );

		self::$remembered['address'] = array(
			$customer->get_shipping_country( 'edit' ),
			$customer->get_shipping_state( 'edit' ),
			$customer->get_shipping_postcode( 'edit' ),
			$customer->get_shipping_city( 'edit' ),
			$customer->get_shipping_address_1( 'edit' ),
			$customer->get_shipping_first_name( 'edit' ),
			$customer->get_shipping_last_name( 'edit' ),
		);

		$customer->set_shipping_country( 'DE' );
		$customer->set_shipping_state( 'BE' );
		$customer->set_shipping_postcode( '10115' );
		$customer->set_shipping_city( 'Berlin' );
		$customer->set_shipping_address_1( 'Berlin Street 1' );
		$customer->set_shipping_first_name( 'Jane' );
		$customer->set_shipping_last_name( 'Doe' );

		$this->assertSame( 'DE', $customer->get_shipping_country( 'edit' ) );
	}

	/**
	 * Runs after the test above, so its tearDown has run.
	 *
	 * @testdox Should put the shipping address of the customer back on tearDown.
	 * @depends test_customer_address_is_changed_by_the_test
	 */
	public function test_customer_address_is_restored_on_tear_down(): void {
		$customer = WC()->customer;

		$this->assertSame(
			self::$remembered['address'],
			array(
				$customer->get_shipping_country( 'edit' ),
				$customer->get_shipping_state( 'edit' ),
				$customer->get_shipping_postcode( 'edit' ),
				$customer->get_shipping_city( 'edit' ),
				$customer->get_shipping_address_1( 'edit' ),
				$customer->get_shipping_first_name( 'edit' ),
				$customer->get_shipping_last_name( 'edit' ),
			)
		);
		$this->assertNotSame( 'DE', $customer->get_shipping_country( 'edit' ) );
	}

	/**
	 * @testdox Should give WooCommerce the session it is handed, or a new real one.
	 */
	public function test_use_own_wc_session_replaces_the_session(): void {
		self::$remembered['session_before'] = WC()->session;

		$this->use_own_wc_session();
		$own = WC()->session;
		$this->assertInstanceOf( WC_Session_Handler::class, $own );
		$this->assertNotSame( self::$remembered['session_before'], $own );

		$given = new WC_Session_Handler();
		$this->use_own_wc_session( $given );
		$this->assertSame( $given, WC()->session );
	}

	/**
	 * Runs after the test above, so its tearDown has run.
	 *
	 * @testdox Should put the session WooCommerce had back on tearDown.
	 * @depends test_use_own_wc_session_replaces_the_session
	 */
	public function test_wc_session_is_restored_on_tear_down(): void {
		$this->assertSame( self::$remembered['session_before'], WC()->session );
	}

	/**
	 * @testdox Should register a block type for the test and render it with the attributes it is given.
	 */
	public function test_register_block_for_test_registers_a_renderable_block(): void {
		$this->register_block_for_test(
			'wallet-test/greeting',
			array(
				'render_callback' => static function ( array $attributes ): string {
					return 'Hello ' . ( $attributes['name'] ?? 'nobody' );
				},
			)
		);

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'wallet-test/greeting' ) );
		$this->assertSame( 'Hello Sam', $this->render_block_for_test( 'wallet-test/greeting', array( 'name' => 'Sam' ) ) );
		$this->assertSame( 'Hello nobody', $this->render_block_for_test( 'wallet-test/greeting' ) );
	}

	/**
	 * Runs after the test above, so its tearDown has run.
	 *
	 * @testdox Should unregister the block type on tearDown and leave the blocks of core alone.
	 * @depends test_register_block_for_test_registers_a_renderable_block
	 */
	public function test_block_type_is_unregistered_on_tear_down(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertFalse( $registry->is_registered( 'wallet-test/greeting' ) );
		$this->assertTrue( $registry->is_registered( 'core/paragraph' ), 'A block of core stays registered' );
	}

	/**
	 * @testdox Should switch to the theme it is given for the test.
	 */
	public function test_use_theme_switches_the_theme(): void {
		$this->use_theme( 'twentytwentyfour' );
		$this->assertTrue( wp_is_block_theme(), 'The block theme is active' );

		$this->use_theme( 'storefront' );
		$this->assertFalse( wp_is_block_theme(), 'The classic theme is active' );
	}

	/**
	 * @testdox Should switch back to the theme the shop had when the theme is restored, and do nothing when no theme was switched.
	 */
	public function test_restore_theme_switches_back(): void {
		$before    = get_stylesheet();
		$was_block = wp_is_block_theme();
		$this->restore_theme();
		$this->assertSame( $before, get_stylesheet(), 'Nothing was switched, so nothing changes' );

		$this->use_theme( 'twentytwentyfour' );
		$this->assertSame( 'twentytwentyfour', get_stylesheet() );

		$this->restore_theme();

		$this->assertSame( $before, get_stylesheet(), 'The theme of the shop is back right away' );
		$this->assertSame( $was_block, wp_is_block_theme(), 'The kind of theme is back too' );
	}

	/**
	 * @testdox Should serve the v6 ownership service and the given services from a container, and none when the service is absent.
	 */
	public function test_container_with_v6_ownership_serves_the_ownership_and_the_given_services(): void {
		$service = new \stdClass();

		$owning = $this->container_with_v6_ownership( true, array( 'some.service' => $service ) );
		$this->assertTrue( $owning->has( 'sdk-v6.owns-current-page' ) );
		$this->assertTrue( $owning->get( 'sdk-v6.owns-current-page' )() );
		$this->assertSame( $service, $owning->get( 'some.service' ) );

		$not_owning = $this->container_with_v6_ownership( false );
		$this->assertTrue( $not_owning->has( 'sdk-v6.owns-current-page' ) );
		$this->assertFalse( $not_owning->get( 'sdk-v6.owns-current-page' )() );

		$absent = $this->container_with_v6_ownership( null, array( 'some.service' => $service ) );
		$this->assertFalse( $absent->has( 'sdk-v6.owns-current-page' ) );
		$this->assertSame( $service, $absent->get( 'some.service' ) );
	}
}
