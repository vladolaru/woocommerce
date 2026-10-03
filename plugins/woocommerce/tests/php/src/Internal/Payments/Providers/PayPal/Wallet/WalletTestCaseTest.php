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
}
