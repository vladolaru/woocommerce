<?php
/**
 * Tests for the data client ID endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\IdentityToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\DataClientIdEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use stdClass;

/**
 * The identity token the endpoint hands to the browser for the current user, and the errors it answers with. The
 * endpoint ends the request with a real wp_send_json(); the HTTP status code of the nonce error is not asserted,
 * because WordPress skips the status header once the test runner has started output.
 *
 * @group paypal-wallet
 */
class DataClientIdEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The identity token generator mock.
	 *
	 * @var IdentityToken&MockInterface
	 */
	private $identity_token;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The System Under Test.
	 *
	 * @var DataClientIdEndpoint
	 */
	private $sut;

	/**
	 * The ID of the logged-in user.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Build the endpoint over a logged-in user.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data   = $this->mock( RequestData::class );
		$this->identity_token = $this->mock( IdentityToken::class );
		$this->logger         = $this->mock( LoggerInterface::class );

		$this->sut = new DataClientIdEndpoint( $this->request_data, $this->identity_token, $this->logger );

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
	}

	/**
	 * @testdox Should answer with the identity token, its expiration and the current user.
	 */
	public function test_handle_request_success(): void {
		$token = $this->mock( Token::class );
		$token->shouldReceive( 'token' )->andReturn( 'token' );
		$token->shouldReceive( 'expiration_timestamp' )->andReturn( 3600 );
		$this->request_data->shouldReceive( 'read_request' )->once()->with( DataClientIdEndpoint::nonce() )->andReturn( array() );
		$this->identity_token->shouldReceive( 'generate_for_user' )->once()->with( $this->user_id )->andReturn( $token );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertSame(
			array(
				'token'      => 'token',
				'expiration' => 3600,
				'user'       => $this->user_id,
			),
			$response
		);
	}

	/**
	 * @testdox Should answer with an error carrying the message, and log it, when the token cannot be generated.
	 */
	public function test_handle_request_fails(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array() );
		$this->identity_token->shouldReceive( 'generate_for_user' )->once()->with( $this->user_id )->andThrow( new RuntimeException( 'No identity token.', 7 ) );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Client ID retrieval failed: No identity token.' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			array(
				'name'    => '',
				'message' => 'No identity token.',
				'code'    => 7,
				'details' => array(),
			),
			$response['data']
		);
	}

	/**
	 * @testdox Should answer with the name and the details of a PayPal API error.
	 */
	public function test_handle_request_fails_with_the_details_of_a_paypal_api_error(): void {
		$api_response          = new stdClass();
		$api_response->name    = 'INVALID_REQUEST';
		$api_response->message = 'Request is not well-formed.';
		$api_response->details = array( (object) array( 'issue' => 'MISSING_FIELD' ) );
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array() );
		$this->identity_token->shouldReceive( 'generate_for_user' )->once()->andThrow( new PayPalApiException( $api_response, 400 ) );
		$this->logger->shouldReceive( 'error' )->once();

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'INVALID_REQUEST', $response['data']['name'] );
		$this->assertSame( 400, $response['data']['code'] );
		$this->assertSame( 'MISSING_FIELD', $response['data']['details'][0]['issue'] );
	}

	/**
	 * @testdox Should answer with an error message and never generate a token when the nonce is invalid.
	 */
	public function test_handle_request_answers_a_nonce_failure_without_generating_a_token(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->identity_token->shouldNotReceive( 'generate_for_user' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Could not validate nonce.' ), $response['data'] );
	}
}
