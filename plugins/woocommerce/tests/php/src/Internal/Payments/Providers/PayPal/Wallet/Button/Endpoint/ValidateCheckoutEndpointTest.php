<?php
/**
 * Tests for the checkout validation endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\ValidateCheckoutEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\ValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Validation\CheckoutFormValidator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Mockery;
use Mockery\MockInterface;

/**
 * What the shopper is answered when the checkout form is validated before the PayPal sheet opens.
 *
 * The endpoint catches Throwable around its wp_send_json_*() calls, so the helper of the base class, which stops the
 * request with an Error, cannot serve it: the Error would be caught and answered a second time. The die handler here
 * returns instead, so each response is sent once, the way a request that ends in exit sends it.
 *
 * @group paypal-wallet
 */
class ValidateCheckoutEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The form validator mock.
	 *
	 * @var CheckoutFormValidator&MockInterface
	 */
	private $form_validator;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The System Under Test.
	 *
	 * @var ValidateCheckoutEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators and a session of its own.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data   = $this->mock( RequestData::class );
		$this->form_validator = $this->mock( CheckoutFormValidator::class );
		$this->logger         = $this->mock( LoggerInterface::class );

		$this->sut = new ValidateCheckoutEndpoint( $this->request_data, $this->form_validator, $this->logger );

		$this->use_own_wc_session();
	}

	/**
	 * Run the handler and return the one JSON response it sent.
	 *
	 * @return array The decoded response ("success" and "data").
	 */
	private function run_handler(): array {
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {};
			}
		);
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			$this->sut->handle_request();
		} finally {
			$body = (string) ob_get_clean();
		}

		$response = json_decode( $body, true );
		$this->assertIsArray( $response, 'The handler should send exactly one JSON response, got: ' . $body );

		return $response;
	}

	/**
	 * Make the request carry a form.
	 */
	private function stub_posted_form(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( ValidateCheckoutEndpoint::nonce() )->andReturn( array( 'form' => array( 'field1' => 'value' ) ) );
	}

	/**
	 * @testdox Should answer with success when the form validates.
	 */
	public function test_valid(): void {
		$this->stub_posted_form();
		$this->form_validator->shouldReceive( 'validate' )->once()->with( array( 'field1' => 'value' ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
	}

	/**
	 * @testdox Should answer with the message and the errors, and no refresh, when the form is invalid.
	 */
	public function test_invalid(): void {
		$exception = new ValidationException( array( 'Invalid value' ) );
		$this->stub_posted_form();
		$this->form_validator->shouldReceive( 'validate' )->once()->andThrow( $exception );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			array(
				'message' => $exception->getMessage(),
				'errors'  => array( 'Invalid value' ),
				'refresh' => false,
			),
			$response['data']
		);
	}

	/**
	 * @testdox Should ask for a refresh of the totals, once, when the form is invalid and the session flags the totals as stale.
	 */
	public function test_invalid_and_refresh(): void {
		$exception = new ValidationException( array( 'Invalid value' ) );
		$this->stub_posted_form();
		$this->form_validator->shouldReceive( 'validate' )->once()->andThrow( $exception );
		WC()->session->refresh_totals = true;

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			array(
				'message' => $exception->getMessage(),
				'errors'  => array( 'Invalid value' ),
				'refresh' => true,
			),
			$response['data']
		);
		$this->assertFalse( isset( WC()->session->refresh_totals ), 'The flag is consumed by the response' );
	}

	/**
	 * @testdox Should answer with the message and log the failure when the validation itself fails.
	 */
	public function test_failure(): void {
		$exception = new Exception( 'BOOM' );
		$this->stub_posted_form();
		$this->form_validator->shouldReceive( 'validate' )->once()->andThrow( $exception );
		$this->logger->shouldReceive( 'error' )->once()->with( Mockery::on( static fn ( $message ) => is_string( $message ) && 0 === strpos( $message, 'Form validation execution failed. BOOM ' ) ) );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'BOOM' ), $response['data'] );
	}

	/**
	 * @testdox Should answer with an error message and never validate when the nonce is invalid.
	 */
	public function test_nonce_failure_answers_an_error_without_validating(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->form_validator->shouldNotReceive( 'validate' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Could not validate nonce.' ), $response['data'] );
	}
}
