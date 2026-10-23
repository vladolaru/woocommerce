<?php
/**
 * Tests for the PayPal wallet payments endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AuthorizationFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\CaptureFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WP_Error;

/**
 * Payments endpoint: fetch, capture and reauthorize an authorization over a stubbed HTTP layer.
 *
 * @group paypal-wallet
 */
class PaymentsEndpointTest extends WalletTestCase {

	private const HOST = 'https://example.com/';

	/**
	 * The authorization factory mock.
	 *
	 * @var AuthorizationFactory|\Mockery\MockInterface
	 */
	private $authorization_factory;

	/**
	 * The capture factory mock.
	 *
	 * @var CaptureFactory|\Mockery\MockInterface
	 */
	private $capture_factory;

	/**
	 * Set up the shared collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->authorization_factory = $this->mock( AuthorizationFactory::class );
		$this->capture_factory       = $this->mock( CaptureFactory::class );
	}

	/**
	 * Build the endpoint under test.
	 *
	 * @return PaymentsEndpoint
	 */
	private function make_endpoint(): PaymentsEndpoint {
		return new PaymentsEndpoint(
			self::HOST,
			$this->make_bearer(),
			$this->authorization_factory,
			$this->capture_factory,
			new NullLogger()
		);
	}

	/**
	 * Assert the one request the endpoint sent: its URL, method and the headers every PayPal call carries.
	 *
	 * @param string $url    Expected URL.
	 * @param string $method Expected HTTP method.
	 */
	private function assert_single_request( string $url, string $method ): void {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );

		$request = $this->http_requests[0];
		$this->assertSame( $url, $request['url'] );
		$this->assertSame( $method, $request['request']['method'] );
		$this->assertSame( 'Bearer bearer', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['request']['headers']['Content-Type'] );
		$this->assertSame( 'return=representation', $request['request']['headers']['Prefer'] );
	}

	/**
	 * @testdox Should GET the authorization by its ID and return the authorization built from the response.
	 */
	public function test_authorization_fetches_by_id(): void {
		$authorization = $this->mock( Authorization::class );
		$this->authorization_factory->expects( 'from_paypal_response' )->andReturn( $authorization );
		$this->stub_http( $this->http_response( 200, '{"is_correct":true}' ) );

		$result = $this->make_endpoint()->authorization( 'somekindofid' );

		$this->assertSame( $authorization, $result );
		$this->assert_single_request( self::HOST . 'v2/payments/authorizations/somekindofid', 'GET' );
	}

	/**
	 * @testdox Should throw a runtime exception when fetching the authorization returns a status other than 200.
	 */
	public function test_authorization_throws_when_status_is_not_200(): void {
		$this->stub_http( $this->http_response( 500, '{"some_error":true}' ) );

		$this->expectException( RuntimeException::class );

		$this->make_endpoint()->authorization( 'somekindofid' );
	}

	/**
	 * @testdox Should throw a runtime exception, not fatal, when fetching the authorization fails at the transport level.
	 */
	public function test_authorization_throws_when_request_is_a_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$this->authorization_factory->shouldNotReceive( 'from_paypal_response' );

		$this->expectException( RuntimeException::class );

		$this->make_endpoint()->authorization( 'somekindofid' );
	}

	/**
	 * @testdox Should POST to the capture URL of the authorization and return the capture built from the response.
	 */
	public function test_capture_posts_to_capture_url(): void {
		$capture = $this->mock( Capture::class );
		$this->capture_factory->expects( 'from_paypal_response' )->andReturn( $capture );
		$this->stub_http( $this->http_response( 201, '{"is_correct":true}' ) );

		$result = $this->make_endpoint()->capture( 'somekindofid' );

		$this->assertSame( $capture, $result );
		$this->assert_single_request( self::HOST . 'v2/payments/authorizations/somekindofid/capture', 'POST' );
		$this->assertSame( '{"final_capture":true}', $this->http_requests[0]['request']['body'] );
	}

	/**
	 * @testdox Should throw a runtime exception when capturing the authorization returns a status other than 201.
	 */
	public function test_capture_throws_when_status_is_not_201(): void {
		$this->stub_http( $this->http_response( 500, '{"some_error":true}' ) );

		$this->expectException( RuntimeException::class );

		$this->make_endpoint()->capture( 'somekindofid' );
	}

	/**
	 * @testdox Should throw a runtime exception, not fatal, when capturing the authorization fails at the transport level.
	 */
	public function test_capture_throws_when_request_is_a_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$this->capture_factory->shouldNotReceive( 'from_paypal_response' );

		$this->expectException( RuntimeException::class );

		$this->make_endpoint()->capture( 'somekindofid' );
	}

	/**
	 * @testdox Should throw a runtime exception, not fatal, when reauthorizing the authorization fails at the transport level.
	 */
	public function test_reauthorize_throws_when_request_is_a_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->expectException( RuntimeException::class );

		$this->make_endpoint()->reauthorize( 'somekindofid' );
	}
}
