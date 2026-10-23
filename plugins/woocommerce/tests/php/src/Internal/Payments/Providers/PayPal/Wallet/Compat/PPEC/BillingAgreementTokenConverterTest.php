<?php
/**
 * Tests for the billing agreement token converter.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\CustomerRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\BillingAgreementTokenConverter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Mockery\MockInterface;

/**
 * Turning the PayPal Express Checkout billing agreement of a subscriber into a vault token, over a stubbed PayPal, a real
 * user and the real customer repository (the stored PayPal customer IDs are user meta).
 *
 * @group paypal-wallet
 */
class BillingAgreementTokenConverterTest extends WalletTestCase {

	private const BILLING_AGREEMENT_ID = 'B-ABC123';
	private const TOKENS_URL           = 'https://api.sandbox.paypal.com/v3/vault/payment-tokens';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * The ID of the user whose agreement is converted.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Create the user and the logger.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->logger  = $this->mock( LoggerInterface::class );
	}

	/**
	 * The converter over a real tokens endpoint and the given repository.
	 *
	 * @param CustomerRepository|null $repository The repository, a real one when none is given.
	 * @return BillingAgreementTokenConverter
	 */
	private function create_converter( ?CustomerRepository $repository = null ): BillingAgreementTokenConverter {
		return new BillingAgreementTokenConverter(
			new PaymentMethodTokensEndpoint(
				'https://api.sandbox.paypal.com',
				$this->make_bearer( false ),
				$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
			),
			$repository ?? new CustomerRepository( 'wc-' ),
			$this->logger
		);
	}

	/**
	 * Answer the token request with a vault token, and the given PayPal customer when the answer names one.
	 *
	 * @param string|null $customer_id The customer ID in the answer, or null for an answer without a customer.
	 * @param string      $token_id    The ID of the vault token.
	 */
	private function stub_token_created( ?string $customer_id, string $token_id = 'vault-token-xyz' ): void {
		$body = array( 'id' => $token_id );
		if ( null !== $customer_id ) {
			$body['customer'] = array( 'id' => $customer_id );
		}

		$this->stub_http( $this->http_response( 201, (string) wp_json_encode( $body ) ) );
	}

	/**
	 * The JSON body of the one token request that was sent.
	 *
	 * @return array
	 */
	private function sent_request_body(): array {
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( self::TOKENS_URL, $this->http_requests[0]['url'] );
		$this->assertSame( 'POST', $this->http_requests[0]['request']['method'] );

		return json_decode( $this->http_requests[0]['request']['body'], true );
	}

	/**
	 * @testdox Should create a vault token for the billing agreement of a vaulted user and remember the customer PayPal answers with.
	 */
	public function test_successful_conversion(): void {
		update_user_meta( $this->user_id, 'ppcp_customer_id', 'customer_42' );
		$this->stub_token_created( 'paypal-customer-id' );
		$this->logger->shouldReceive( 'info' )->once();

		$result = $this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertSame( 'vault-token-xyz', $result );
		$body = $this->sent_request_body();
		$this->assertSame(
			array(
				'id'   => self::BILLING_AGREEMENT_ID,
				'type' => 'BILLING_AGREEMENT',
			),
			$body['payment_source']['token']
		);
		$this->assertSame( 'customer_42', $body['customer']['id'] );
		$this->assertSame( 'paypal-customer-id', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * @testdox Should still return the vault token when PayPal's answer names no customer, and store no customer ID.
	 */
	public function test_successful_conversion_without_customer_id(): void {
		update_user_meta( $this->user_id, 'ppcp_customer_id', 'customer_42' );
		$this->stub_token_created( null );
		$this->logger->shouldReceive( 'info' )->once();

		$result = $this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertSame( 'vault-token-xyz', $result );
		$this->assertSame( '', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * A user who was never vaulted has no PayPal customer ID. PayPal is called without one so that it assigns a
	 * customer, never with a local ID made up from the user ID, which the Vault API rejects.
	 *
	 * @testdox Should call PayPal without a customer ID for a user who was never vaulted, and store the customer PayPal assigns.
	 */
	public function test_non_vaulted_user_passes_empty_customer_id(): void {
		$this->stub_token_created( 'paypal-generated-id' );
		$this->logger->shouldReceive( 'info' )->once();

		$result = $this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertSame( 'vault-token-xyz', $result );
		$this->assertArrayNotHasKey( 'customer', $this->sent_request_body() );
		$this->assertSame( 'paypal-generated-id', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * The customer ID PayPal assigned last wins over the one stored first, or a second customer would be created for the
	 * same user and the tokens of the first would be orphaned.
	 *
	 * @testdox Should send the most recent customer ID PayPal assigned when the user has two.
	 */
	public function test_the_most_recent_paypal_customer_id_is_sent(): void {
		update_user_meta( $this->user_id, 'ppcp_customer_id', 'customer_42' );
		update_user_meta( $this->user_id, '_ppcp_target_customer_id', 'customer_latest' );
		$this->stub_token_created( 'customer_latest' );
		$this->logger->shouldReceive( 'info' )->once();

		$this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertSame( 'customer_latest', $this->sent_request_body()['customer']['id'] );
	}

	/**
	 * @testdox Should return null and log an error when PayPal cannot create the token.
	 */
	public function test_api_failure_returns_null(): void {
		update_user_meta( $this->user_id, 'ppcp_customer_id', 'customer_42' );
		$this->logger->shouldReceive( 'error' )->once()->with( \Mockery::pattern( '/Failed to convert Billing Agreement B-ABC123 to Vault v3 token for user \d+: Not able to create setup token\./' ) );

		$result = $this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertNull( $result );
		$this->assertSame( '', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * @testdox Should return null and log an error when something unexpected is thrown while the customer is looked up.
	 */
	public function test_runtime_error_returns_null(): void {
		$repository = $this->mock( CustomerRepository::class );
		$repository->shouldReceive( 'paypal_customer_id_for_user' )->with( $this->user_id )->andThrow( new Exception( 'Unexpected error' ) );
		$this->logger->shouldReceive( 'error' )->once()->with( \Mockery::pattern( '/Unexpected error/' ) );

		$result = $this->create_converter( $repository )->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertNull( $result );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should return null and log an error when PayPal's answer holds no token ID.
	 */
	public function test_an_answer_without_a_token_id_returns_null(): void {
		$this->stub_http( $this->http_response( 201, '{}' ) );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Vault token creation for Billing Agreement B-ABC123 returned no token ID.' );

		$result = $this->create_converter()->convert( self::BILLING_AGREEMENT_ID, $this->user_id );

		$this->assertNull( $result );
		$this->assertSame( '', get_user_meta( $this->user_id, '_ppcp_target_customer_id', true ) );
	}
}
