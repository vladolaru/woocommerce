<?php
/**
 * Tests for the payment token endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * Which WooCommerce token the endpoint creates for the vaulted payment source and what it answers.
 *
 * A card vault result creates no token any more: the card gateway and its token creation are gone.
 *
 * @group paypal-wallet
 */
class CreatePaymentTokenTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The payment method tokens endpoint mock.
	 *
	 * @var PaymentMethodTokensEndpoint&MockInterface
	 */
	private $tokens_endpoint;

	/**
	 * The WooCommerce payment tokens service mock.
	 *
	 * @var WooCommercePaymentTokens&MockInterface
	 */
	private $wc_payment_tokens;

	/**
	 * The System Under Test.
	 *
	 * @var CreatePaymentToken
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

		$this->request_data      = $this->mock( RequestData::class );
		$this->tokens_endpoint   = $this->mock( PaymentMethodTokensEndpoint::class );
		$this->wc_payment_tokens = $this->mock( WooCommercePaymentTokens::class );

		$this->sut = new CreatePaymentToken( $this->request_data, $this->tokens_endpoint, $this->wc_payment_tokens );

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
		update_user_meta( $this->user_id, '_ppcp_target_customer_id', 'cust-existing' );
	}

	/**
	 * A real captured exchange: a VISA card vault token response from the PayPal sandbox.
	 *
	 * @return \stdClass
	 */
	private function card_result_fixture(): \stdClass {
		return json_decode( '{"id":"72k37380cm2997353","customer":{"id":"LYraJLIEfz"},"payment_source":{"card":{"name":"Frictionless","last_digits":"9038","brand":"VISA","expiry":"2029-01","verification_status":"VERIFIED","verification":{"network_transaction_id":"841006768893514","three_d_secure":{"eci_flag":"FULLY_AUTHENTICATED_TRANSACTION","cavv":"AAABAWFlmQAAAABjRWWZEEFgFz+=","enrolled":"Y","pares_status":"Y","three_ds_version":"2.2.0"}},"authentication_result":{"three_d_secure":{"authentication_status":"Y","enrollment_status":"Y","authentication_id":"22674300069036246"}}}},"links":[{"href":"https://api.sandbox.paypal.com/v3/vault/payment-tokens/72k37380cm2997353","rel":"self","method":"GET"}]}' );
	}

	/**
	 * A minimal PayPal result that carries a paypal payment source.
	 *
	 * @param string $email The payer email.
	 * @return \stdClass
	 */
	private function paypal_result_fixture( string $email = 'buyer@paypal.com' ): \stdClass {
		return (object) array(
			'id'             => 'paypal-token-id-999',
			'customer'       => (object) array( 'id' => 'CustPayPal01' ),
			'payment_source' => (object) array(
				'paypal' => (object) array( 'email_address' => $email ),
			),
		);
	}

	/**
	 * @testdox Should create no WooCommerce token and still persist the customer ID for a card vault result.
	 */
	public function test_card_result_creates_no_token(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( CreatePaymentToken::ENDPOINT )->andReturn( array( 'vault_setup_token' => 'setup-tok-001' ) );
		$this->tokens_endpoint->shouldReceive( 'create_payment_token' )->once()->andReturn( $this->card_result_fixture() );
		$this->wc_payment_tokens->shouldNotReceive( 'create_payment_token_paypal' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['data'], 'No WC token was created, so the token ID is zero' );
		$this->assertSame( 'LYraJLIEfz', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * @testdox Should create a PayPal token for a PayPal vault result.
	 */
	public function test_paypal_success_calls_create_paypal_token(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array( 'vault_setup_token' => 'setup-tok-002' ) );
		$this->tokens_endpoint->shouldReceive( 'create_payment_token' )->once()->andReturn( $this->paypal_result_fixture( 'buyer@paypal.com' ) );
		$this->wc_payment_tokens->shouldReceive( 'create_payment_token_paypal' )->once()->with( $this->user_id, 'paypal-token-id-999', 'buyer@paypal.com' )->andReturn( 7777 );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertSame( 7777, $response['data'] );
		$this->assertSame( 'CustPayPal01', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * @testdox Should answer with an error message and persist no token when the nonce is invalid.
	 */
	public function test_nonce_failure_returns_error_and_no_token_persisted(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Invalid nonce.' ) );
		$this->tokens_endpoint->shouldNotReceive( 'create_payment_token' );
		$this->wc_payment_tokens->shouldNotReceive( 'create_payment_token_paypal' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertArrayHasKey( 'message', $response['data'] );
	}

	/**
	 * @testdox Should answer with a bare error and persist no token when PayPal's API fails.
	 */
	public function test_api_exception_sends_error_with_no_data(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array( 'vault_setup_token' => 'setup-tok-003' ) );
		$this->tokens_endpoint->shouldReceive( 'create_payment_token' )->once()->andThrow( new PayPalApiException() );
		$this->wc_payment_tokens->shouldNotReceive( 'create_payment_token_paypal' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertArrayNotHasKey( 'data', $response );
	}
}
