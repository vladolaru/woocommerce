<?php
/**
 * Tests for the v6 client token endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\SdkClientToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\ClientTokenEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use stdClass;
use Throwable;

/**
 * What the shopper is answered when a client token is requested: the token, or an error that never carries the detail
 * of the failure. The endpoint ends the request with a real wp_send_json_*(). The HTTP status codes of the errors (400 for
 * the nonce, 500 for a failed token) are not asserted: wp_send_json() only calls status_header() while headers_sent() is
 * false, and the runner has started output, so no filter or hook sees the code.
 *
 * @group paypal-wallet
 */
class ClientTokenEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The SDK client token generator mock.
	 *
	 * @var SdkClientToken&MockInterface
	 */
	private $sdk_client_token;

	/**
	 * The System Under Test.
	 *
	 * @var ClientTokenEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data     = $this->mock( RequestData::class );
		$this->logger           = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
		$this->sdk_client_token = $this->mock( SdkClientToken::class );

		$this->sut = new ClientTokenEndpoint( $this->request_data, $this->logger, $this->sdk_client_token );
	}

	/**
	 * @testdox Should answer with an error and never request a token when the nonce is invalid.
	 */
	public function test_invalid_nonce_answers_an_error_without_requesting_a_token(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( ClientTokenEndpoint::nonce() )->andThrow( new NonceValidationException( 'The nonce is invalid.' ) );
		$this->sdk_client_token->shouldNotReceive( 'sdk_client_token' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'The nonce is invalid.' ), $response['data'] );
	}

	/**
	 * @testdox Should answer with the generated client token for a validated request.
	 */
	public function test_valid_request_answers_with_the_generated_client_token(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( ClientTokenEndpoint::nonce() )->andReturn( array() );
		$this->sdk_client_token->shouldReceive( 'sdk_client_token' )->once()->andReturn( 'a-client-token' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'client_token' => 'a-client-token' ), $response['data'] );
	}

	/**
	 * @testdox Should answer with a generic error and log the detail when generating the token fails: $exception_class.
	 * @dataProvider token_generation_failure_provider
	 *
	 * @param string $exception_class         The exception the generator throws.
	 * @param string $expected_logged_detail  The detail the log entry must carry.
	 */
	public function test_token_generation_failure_answers_a_generic_message( string $exception_class, string $expected_logged_detail ): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( ClientTokenEndpoint::nonce() )->andReturn( array() );
		$this->sdk_client_token->shouldReceive( 'sdk_client_token' )->once()->andThrow( $this->build_failure( $exception_class, $expected_logged_detail ) );

		$this->logger->shouldReceive( 'error' )->once()->with(
			Mockery::on(
				static function ( $message ) use ( $expected_logged_detail ): bool {
					return is_string( $message ) && false !== strpos( $message, $expected_logged_detail );
				}
			)
		);

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Failed to generate client token.' ), $response['data'], 'The shopper never sees the detail' );
	}

	/**
	 * Failures of the generator.
	 *
	 * @return array
	 */
	public function token_generation_failure_provider(): array {
		return array(
			'a PayPal API error is hidden from the shopper' => array( PayPalApiException::class, 'Insufficient scope for client token.' ),
			'a runtime error is hidden from the shopper' => array( RuntimeException::class, 'Connection to PayPal timed out.' ),
		);
	}

	/**
	 * Build the exception the generator throws, carrying the given detail.
	 *
	 * @param string $exception_class The exception class.
	 * @param string $detail          The detail of the failure.
	 * @return Throwable
	 */
	private function build_failure( string $exception_class, string $detail ): Throwable {
		if ( PayPalApiException::class === $exception_class ) {
			$response          = new stdClass();
			$response->message = $detail;

			return new PayPalApiException( $response, 503 );
		}

		return new RuntimeException( $detail );
	}
}
