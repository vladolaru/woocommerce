<?php
/**
 * Tests for the payment method tokens checker.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Service\PaymentMethodTokensChecker;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WP_Error;

/**
 * Whether a PayPal customer has a saved PayPal account in the vault. The checker runs over the real payment tokens
 * endpoint and a stubbed HTTP layer, so the list of tokens, a missing customer and every failure are PayPal answers.
 *
 * @group paypal-wallet
 */
class PaymentMethodTokensCheckerTest extends WalletTestCase {

	private const TOKENS_URL = 'https://api.sandbox.paypal.com/v3/vault/payment-tokens?customer_id=abc123';

	/**
	 * The checker under test.
	 *
	 * @var PaymentMethodTokensChecker
	 */
	private PaymentMethodTokensChecker $checker;

	/**
	 * Build the checker over the real endpoint.
	 */
	public function setUp(): void {
		parent::setUp();

		$endpoint      = new PaymentTokensEndpoint( 'https://api.sandbox.paypal.com', $this->make_bearer( false ), new NullLogger() );
		$this->checker = new PaymentMethodTokensChecker( $endpoint );
	}

	/**
	 * Answer the token list request with the given payment sources, one token each.
	 *
	 * @param string[] $source_names The names of the payment sources, for example "paypal" or "venmo".
	 */
	private function stub_token_list( array $source_names ): void {
		$tokens = array();
		foreach ( $source_names as $index => $name ) {
			$tokens[] = array(
				'id'             => 'token-' . $index,
				'payment_source' => array( $name => array( 'email_address' => 'buyer@example.com' ) ),
			);
		}

		$this->stub_http( $this->http_response( 200, (string) wp_json_encode( array( 'payment_tokens' => $tokens ) ) ) );
	}

	/**
	 * @testdox Should report no PayPal token when PayPal answers that the customer was not found.
	 */
	public function test_handle_exception_when_resource_not_found(): void {
		$this->stub_http( $this->http_response( 404, (string) wp_json_encode( array( 'name' => 'RESOURCE_NOT_FOUND' ) ) ) );

		$this->assertFalse( $this->checker->has_paypal_payment_token( 'abc123' ) );
	}

	/**
	 * @testdox Should report no PayPal token when the request to PayPal fails.
	 */
	public function test_handle_runtime_exception_when_network_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		$this->assertFalse( $this->checker->has_paypal_payment_token( 'abc123' ) );
	}

	/**
	 * @testdox Should report a PayPal token when the customer has one, and ask PayPal about that customer.
	 */
	public function test_reports_a_paypal_token(): void {
		$this->stub_token_list( array( 'paypal' ) );

		$this->assertTrue( $this->checker->has_paypal_payment_token( 'abc123' ) );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( self::TOKENS_URL, $this->http_requests[0]['url'] );
	}

	/**
	 * @testdox Should find the PayPal token among the other tokens of the customer.
	 */
	public function test_finds_the_paypal_token_among_other_tokens(): void {
		$this->stub_token_list( array( 'venmo', 'paypal' ) );

		$this->assertTrue( $this->checker->has_paypal_payment_token( 'abc123' ) );
	}

	/**
	 * @testdox Should report no PayPal token when the customer only has other tokens, or none.
	 *
	 * @dataProvider data_no_paypal_token
	 *
	 * @param string[] $source_names The payment sources the customer has.
	 */
	public function test_reports_no_paypal_token_without_one( array $source_names ): void {
		$this->stub_token_list( $source_names );

		$this->assertFalse( $this->checker->has_paypal_payment_token( 'abc123' ) );
	}

	/**
	 * Token lists without a PayPal token.
	 *
	 * @return array<string, array{string[]}>
	 */
	public function data_no_paypal_token(): array {
		return array(
			'a Venmo token only' => array( array( 'venmo' ) ),
			'no token'           => array( array() ),
		);
	}

	/**
	 * @testdox Should report no PayPal token without asking PayPal when the customer ID is empty.
	 */
	public function test_reports_no_paypal_token_without_a_customer_id(): void {
		$this->stub_token_list( array( 'paypal' ) );

		$this->assertFalse( $this->checker->has_paypal_payment_token( '' ) );
		$this->assertSame( array(), $this->http_requests, 'PayPal should not be asked about a customer without an ID' );
	}
}
