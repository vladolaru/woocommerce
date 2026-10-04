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
}
