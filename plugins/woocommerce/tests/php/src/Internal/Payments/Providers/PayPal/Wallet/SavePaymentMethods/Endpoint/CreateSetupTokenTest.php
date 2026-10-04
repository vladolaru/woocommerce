<?php
/**
 * Tests for the setup token endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreateSetupToken;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use stdClass;

/**
 * What the endpoint asks PayPal for: a PayPal payment source whatever the payment method, with the customer ID the user
 * meta holds.
 *
 * @group paypal-wallet
 */
class CreateSetupTokenTest extends WalletTestCase {

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
	 * The System Under Test.
	 *
	 * @var CreateSetupToken
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

		$this->request_data    = $this->mock( RequestData::class );
		$this->tokens_endpoint = $this->mock( PaymentMethodTokensEndpoint::class );
		$this->sut             = new CreateSetupToken( $this->request_data, $this->tokens_endpoint );

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Payment methods the request can name.
	 *
	 * @return array
	 */
	public function payment_method_scenarios(): array {
		return array(
			'paypal payment method' => array( array( 'payment_method' => 'ppcp-gateway' ) ),
			'a request that still names the card gateway' => array(
				array(
					'payment_method'      => 'ppcp-credit-card-gateway',
					'verification_method' => 'SCA_ALWAYS',
				),
			),
		);
	}

	/**
	 * @testdox Should hand setup_tokens() a paypal payment source with usage_type and experience_context, whether the request names the PayPal or the card gateway.
	 * @dataProvider payment_method_scenarios
	 *
	 * @param array $request The request the reader returns.
	 */
	public function test_setup_tokens_receives_a_paypal_payment_source( array $request ): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( CreateSetupToken::ENDPOINT )->andReturn( $request );
		update_user_meta( $this->user_id, '_ppcp_target_customer_id', 'cust-abc123' );

		$captured_source = null;
		$this->tokens_endpoint->shouldReceive( 'setup_tokens' )->once()->withArgs(
			function ( PaymentSource $source, string $customer_id ) use ( &$captured_source ): bool {
				unset( $customer_id );
				$captured_source = $source;
				return true;
			}
		)->andReturn( new stdClass() );

		$this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertNotNull( $captured_source, 'setup_tokens() was not called' );
		$this->assertSame( 'paypal', $captured_source->name() );
		$props = $captured_source->properties();
		$this->assertSame( 'MERCHANT', $props->usage_type );
		$this->assertFalse( isset( $props->verification_method ), 'A PayPal source carries no verification_method' );
		$this->assertTrue( property_exists( $props, 'experience_context' ), 'experience_context property should be set' );
	}

	/**
	 * @testdox Should pass the customer ID from the _ppcp_target_customer_id user meta to setup_tokens().
	 */
	public function test_customer_id_comes_from_user_meta(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array( 'payment_method' => 'ppcp-gateway' ) );
		update_user_meta( $this->user_id, '_ppcp_target_customer_id', 'cust-abc123' );

		$captured_customer_id = null;
		$this->tokens_endpoint->shouldReceive( 'setup_tokens' )->once()->withArgs(
			function ( PaymentSource $source, string $customer_id ) use ( &$captured_customer_id ): bool {
				unset( $source );
				$captured_customer_id = $customer_id;
				return true;
			}
		)->andReturn( new stdClass() );

		$this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertSame( 'cust-abc123', $captured_customer_id );
	}

	/**
	 * The extension also asserted HTTP 400, which a real wp_send_json_error() hides here: WordPress skips the status
	 * header once the test runner has started output.
	 *
	 * @testdox Should answer with an error message and never call setup_tokens() when the nonce is invalid.
	 */
	public function test_nonce_failure_answers_with_an_error_message_and_never_calls_setup_tokens(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Invalid nonce.' ) );
		$this->tokens_endpoint->shouldNotReceive( 'setup_tokens' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertArrayHasKey( 'message', $response['data'] );
	}
}
