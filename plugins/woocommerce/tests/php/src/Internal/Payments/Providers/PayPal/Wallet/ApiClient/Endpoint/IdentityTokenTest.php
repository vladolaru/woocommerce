<?php
/**
 * Tests for the identity token endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\IdentityToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\CustomerRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use WP_Error;

/**
 * The identity token endpoint: a token for the shopper's vaulted PayPal or Venmo customer, over a stubbed HTTP layer.
 * The extension's card vaulting setting stub (save_card_details) is gone: core's wallet does not process cards.
 *
 * @group paypal-wallet
 */
class IdentityTokenTest extends WalletTestCase {

	private const HOST = 'https://example.com/';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * The settings mock.
	 *
	 * @var SettingsProvider|MockInterface
	 */
	private $settings;

	/**
	 * The customer repository mock.
	 *
	 * @var CustomerRepository|MockInterface
	 */
	private $customer_repository;

	/**
	 * Set up the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->logger              = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
		$this->settings            = $this->mock( SettingsProvider::class );
		$this->customer_repository = $this->mock( CustomerRepository::class );
	}

	/**
	 * Build the endpoint under test.
	 *
	 * @param string $subscription_mode The WooCommerce Subscriptions mode.
	 * @return IdentityToken
	 */
	private function make_sut( string $subscription_mode = SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_VAULTING ): IdentityToken {
		return new IdentityToken( self::HOST, $this->make_bearer(), $this->logger, $this->settings, $this->customer_repository, $subscription_mode );
	}

	/**
	 * Assert the one request the endpoint sent and return its arguments.
	 *
	 * @return array
	 */
	private function assert_single_token_request(): array {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );

		$request = $this->http_requests[0];
		$this->assertSame( self::HOST . 'v1/identity/generate-token', $request['url'] );
		$this->assertSame( 'POST', $request['request']['method'] );
		$this->assertSame( 'Bearer bearer', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['request']['headers']['Content-Type'] );

		return $request['request'];
	}

	/**
	 * Given a shopper with a vaulted PayPal or Venmo customer, the request carries the customer ID, the ID is stored on
	 * the user and the endpoint returns the token PayPal sent.
	 *
	 * @testdox Should send the vaulted customer ID, store it on the user and return the token.
	 */
	public function test_generate_for_customer_returns_token(): void {
		$user_id = self::factory()->user->create();
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->customer_repository->shouldReceive( 'customer_id_for_user' )->with( $user_id )->andReturn( 'prefix1' );
		$this->stub_http( $this->http_response( 200, '{"client_token":"fake-client-token", "expires_in":3600}' ) );

		$result = $this->make_sut()->generate_for_user( $user_id );

		$this->assertSame( 'fake-client-token', $result->token() );
		$request = $this->assert_single_token_request();
		$this->assertSame( '{"customer_id":"prefix1"}', $request['body'] );
		$this->assertSame( 'prefix1', get_user_meta( $user_id, 'ppcp_customer_id', true ) );
	}

	/**
	 * With PayPal and Venmo vaulting off and no vaulting subscriptions, the request carries no customer and nothing is
	 * stored on the user.
	 *
	 * @testdox Should send no customer ID and store nothing when vaulting is off.
	 */
	public function test_generate_for_user_without_vaulting_sends_no_customer(): void {
		$user_id = self::factory()->user->create();
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( false );
		$this->customer_repository->shouldNotReceive( 'customer_id_for_user' );
		$this->stub_http( $this->http_response( 200, '{"client_token":"fake-client-token", "expires_in":3600}' ) );

		$result = $this->make_sut( SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_DISABLED )->generate_for_user( $user_id );

		$this->assertSame( 'fake-client-token', $result->token() );
		$request = $this->assert_single_token_request();
		$this->assertEmpty( $request['body'] ?? null );
		$this->assertSame( '', get_user_meta( $user_id, 'ppcp_customer_id', true ) );
	}

	/**
	 * @testdox Should still vault the customer when only the subscriptions mode asks for vaulting.
	 */
	public function test_generate_for_user_vaults_for_the_vaulting_subscriptions_mode(): void {
		$user_id = self::factory()->user->create();
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( false );
		$this->customer_repository->shouldReceive( 'customer_id_for_user' )->with( $user_id )->andReturn( 'prefix1' );
		$this->stub_http( $this->http_response( 200, '{"client_token":"fake-client-token", "expires_in":3600}' ) );

		$this->make_sut( SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_VAULTING )->generate_for_user( $user_id );

		$request = $this->assert_single_token_request();
		$this->assertSame( '{"customer_id":"prefix1"}', $request['body'] );
		$this->assertSame( 'prefix1', get_user_meta( $user_id, 'ppcp_customer_id', true ) );
	}

	/**
	 * @testdox Should throw a runtime exception and log a warning when the request fails at the transport level.
	 */
	public function test_generate_for_customer_fails_because_wp_error(): void {
		$user_id = self::factory()->user->create();
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->customer_repository->shouldReceive( 'customer_id_for_user' )->andReturn( 'prefix1' );
		$this->logger->shouldReceive( 'log' )->once()->with( 'warning', Mockery::type( 'string' ), Mockery::type( 'array' ) );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->expectException( RuntimeException::class );
		try {
			$this->make_sut()->generate_for_user( $user_id );
		} finally {
			$this->assertSame( 'prefix1', get_user_meta( $user_id, 'ppcp_customer_id', true ), 'The customer ID is stored before the request is sent' );
		}
	}

	/**
	 * @testdox Should throw a PayPal API exception and log a warning when the response code is not 200.
	 */
	public function test_generate_for_customer_fails_because_response_code_is_not_200(): void {
		$user_id = self::factory()->user->create();
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->customer_repository->shouldReceive( 'customer_id_for_user' )->andReturn( 'prefix1' );
		$this->logger->shouldReceive( 'log' )->once()->with( 'warning', Mockery::type( 'string' ), Mockery::type( 'array' ) );
		$this->stub_http( $this->http_response( 500, '' ) );

		$this->expectException( PayPalApiException::class );
		$this->make_sut()->generate_for_user( $user_id );
	}
}
