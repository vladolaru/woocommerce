<?php
/**
 * Tests for the network guard and the HTTP stubbing of WalletTestCase.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet;

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
}
