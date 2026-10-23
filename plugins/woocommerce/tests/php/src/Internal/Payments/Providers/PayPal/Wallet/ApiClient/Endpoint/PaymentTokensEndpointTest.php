<?php
/**
 * Tests for the payment tokens endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_Error;

/**
 * The vault payment tokens endpoint: listing and deleting a customer's tokens over a stubbed HTTP layer. The fixtures
 * list venmo sources where the extension listed a card source: core's wallet does not process cards, and the endpoint
 * reads any source by name.
 *
 * @group paypal-wallet
 */
class PaymentTokensEndpointTest extends WalletTestCase {

	private const HOST = 'https://api.sandbox.paypal.com';

	/**
	 * The endpoint under test.
	 *
	 * @var PaymentTokensEndpoint
	 */
	private $endpoint;

	/**
	 * Build the endpoint under test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->endpoint = new PaymentTokensEndpoint(
			self::HOST,
			$this->make_bearer( false ),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * Answer the one request the test expects with the given response and anything else with an error, so a request to
	 * another URL or with another method shows up as a failed call.
	 *
	 * @param string         $method   The expected HTTP method.
	 * @param string         $url      The expected URL.
	 * @param array|WP_Error $response The answer.
	 */
	private function stub_vault_request( string $method, string $url, $response ): void {
		$this->stub_http(
			static function ( $request, $requested_url ) use ( $method, $url, $response ) {
				if ( $url !== $requested_url || $method !== $request['method'] ) {
					return new WP_Error( 'unexpected_request', $request['method'] . ' ' . $requested_url );
				}

				return $response;
			}
		);
	}

	/**
	 * Assert the one request the endpoint sent carried the bearer and the JSON content type.
	 */
	private function assert_single_authorized_request(): void {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );
		$this->assertSame( 'Bearer bearer', $this->http_requests[0]['request']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $this->http_requests[0]['request']['headers']['Content-Type'] );
	}

	/**
	 * A payment token as the vault API lists it.
	 *
	 * @param string $id             The token ID.
	 * @param array  $payment_source The payment source, by its name.
	 * @return array
	 */
	private function listed_token( string $id, array $payment_source ): array {
		return array(
			'id'             => $id,
			'payment_source' => $payment_source,
		);
	}

	/**
	 * @testdox Should delete a payment token with a DELETE request for its ID.
	 */
	public function test_delete_successfully_deletes_payment_token(): void {
		$this->stub_vault_request( 'DELETE', self::HOST . '/v3/vault/payment-tokens/tok_test_12345', $this->http_response( 204, '' ) );

		$this->endpoint->delete( 'tok_test_12345' );

		$this->assert_single_authorized_request();
	}

	/**
	 * @testdox Should throw a runtime exception with the transport error when deleting fails to connect.
	 */
	public function test_delete_throws_runtime_exception_on_request_failure(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Connection timeout' );

		$this->endpoint->delete( 'tok_test_12345' );
	}

	/**
	 * @testdox Should throw a PayPal API exception when PayPal refuses to delete the token.
	 */
	public function test_delete_throws_paypal_api_exception_on_api_error(): void {
		$error_body = (string) wp_json_encode(
			array(
				'name'     => 'RESOURCE_NOT_FOUND',
				'message'  => 'The specified resource does not exist.',
				'debug_id' => 'fake-debug-id',
			)
		);
		$this->stub_http( $this->http_response( 404, $error_body ) );

		try {
			$this->endpoint->delete( 'tok_test_12345' );
			$this->fail( 'A 404 should throw a PayPalApiException' );
		} catch ( PayPalApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertSame( 'RESOURCE_NOT_FOUND', $e->name() );
		}
	}

	/**
	 * @testdox Should list the payment tokens of a customer with the payment source of each.
	 */
	public function test_payment_tokens_for_customer_returns_tokens(): void {
		$body = (string) wp_json_encode(
			array(
				'payment_tokens' => array(
					$this->listed_token(
						'tok_venmo',
						array(
							'venmo' => array(
								'user_name' => '@fake-venmo-user',
								'payer_id'  => 'FAKEPAYERVENMO',
							),
						)
					),
					$this->listed_token(
						'tok_paypal',
						array(
							'paypal' => array(
								'email_address' => 'buyer@example.com',
								'payer_id'      => 'FAKEPAYER123',
							),
						)
					),
				),
			)
		);
		$this->stub_vault_request( 'GET', self::HOST . '/v3/vault/payment-tokens?customer_id=cust_12345', $this->http_response( 200, $body ) );

		$tokens = $this->endpoint->payment_tokens_for_customer( 'cust_12345' );

		$this->assertCount( 2, $tokens );
		$this->assertArrayHasKey( 'id', $tokens[0] );
		$this->assertArrayHasKey( 'payment_source', $tokens[0] );
		$this->assertSame( 'tok_venmo', $tokens[0]['id'] );
		$this->assertSame( 'venmo', $tokens[0]['payment_source']->name() );
		$this->assertSame( 'tok_paypal', $tokens[1]['id'] );
		$this->assertSame( 'paypal', $tokens[1]['payment_source']->name() );
		$this->assert_single_authorized_request();
	}

	/**
	 * @testdox Should return an empty array when the customer has no payment tokens.
	 */
	public function test_payment_tokens_for_customer_returns_empty_array_when_no_tokens(): void {
		$this->stub_vault_request(
			'GET',
			self::HOST . '/v3/vault/payment-tokens?customer_id=cust_12345',
			$this->http_response( 200, (string) wp_json_encode( array( 'payment_tokens' => array() ) ) )
		);

		$tokens = $this->endpoint->payment_tokens_for_customer( 'cust_12345' );

		$this->assertSame( array(), $tokens );
	}

	/**
	 * @testdox Should throw a runtime exception with the transport error when listing fails to connect.
	 */
	public function test_payment_tokens_for_customer_throws_runtime_exception_on_request_failure(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Network error' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Network error' );

		$this->endpoint->payment_tokens_for_customer( 'cust_12345' );
	}

	/**
	 * @testdox Should throw a PayPal API exception when PayPal refuses to list the tokens.
	 */
	public function test_payment_tokens_for_customer_throws_paypal_api_exception_on_api_error(): void {
		$error_body = (string) wp_json_encode(
			array(
				'name'    => 'PERMISSION_DENIED',
				'message' => 'You do not have permission to access this resource',
			)
		);
		$this->stub_http( $this->http_response( 403, $error_body ) );

		try {
			$this->endpoint->payment_tokens_for_customer( 'cust_12345' );
			$this->fail( 'A 403 should throw a PayPalApiException' );
		} catch ( PayPalApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'PERMISSION_DENIED', $e->name() );
		}
	}

	/**
	 * @testdox Should skip the tokens that have no payment source.
	 */
	public function test_payment_tokens_for_customer_skips_tokens_without_valid_payment_source(): void {
		$body = (string) wp_json_encode(
			array(
				'payment_tokens' => array(
					$this->listed_token( 'tok_valid', array( 'venmo' => array( 'user_name' => '@fake-venmo-user' ) ) ),
					$this->listed_token( 'tok_invalid', array() ),
					$this->listed_token( 'tok_valid_2', array( 'paypal' => array( 'email_address' => 'buyer@example.com' ) ) ),
				),
			)
		);
		$this->stub_http( $this->http_response( 200, $body ) );

		$tokens = $this->endpoint->payment_tokens_for_customer( 'cust_12345' );

		$this->assertCount( 2, $tokens );
		$this->assertSame( 'tok_valid', $tokens[0]['id'] );
		$this->assertSame( 'tok_valid_2', $tokens[1]['id'] );
	}
}
