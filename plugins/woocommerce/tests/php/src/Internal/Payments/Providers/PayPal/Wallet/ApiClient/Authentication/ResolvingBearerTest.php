<?php
/**
 * Tests for the resolving bearer.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ResolvingBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The bearer that follows the connection state: the placeholder token while onboarding, the PayPal token once
 * connected, decided on every call.
 *
 * @group paypal-wallet
 */
class ResolvingBearerTest extends WalletTestCase {

	/**
	 * Put a valid, unexpired token in the bearer transient, so the PayPal bearer answers from its cache and never
	 * sends a request.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_transient(
			'ppcp-test-resolving-' . PayPalBearer::CACHE_KEY,
			(string) wp_json_encode(
				array(
					'access_token' => 'paypal-token',
					'expires_in'   => 100,
					'created'      => time(),
				)
			)
		);
	}

	/**
	 * Build the resolver under test.
	 *
	 * @param ConnectionState $connection_state The connection state.
	 * @return ResolvingBearer
	 */
	private function make_sut( ConnectionState $connection_state ): ResolvingBearer {
		$host_resolver = $this->mock( ApiHostResolver::class );
		$host_resolver->shouldReceive( 'host' )->andReturn( 'https://example.com' );

		return new ResolvingBearer(
			$connection_state,
			new Cache( 'ppcp-test-resolving-' ),
			$host_resolver,
			'key',
			'secret',
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			null,
			$this->mock( TokenRateLimiter::class )
		);
	}

	/**
	 * Given a merchant who has not completed onboarding, the current bearer is the placeholder token, not a real
	 * PayPal token.
	 *
	 * @testdox Should return the placeholder token while the merchant is not connected.
	 */
	public function test_bearer_reflects_disconnected_state(): void {
		$sut = $this->make_sut( new ConnectionState( false, new Environment( false ) ) );

		$this->assertSame( 'token', $sut->bearer()->token() );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Given a connected merchant with a valid cached PayPal token, the current bearer is the cached token, not the
	 * placeholder.
	 *
	 * @testdox Should return the cached PayPal token once the merchant is connected.
	 */
	public function test_bearer_reflects_connected_state(): void {
		$sut = $this->make_sut( new ConnectionState( true, new Environment( false ) ) );

		$this->assertSame( 'paypal-token', $sut->bearer()->token() );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Given a resolver built before onboarding completes, a connection state that switches to connected mid-request (as
	 * ConnectionState::connect() does when onboarding finishes) shows in the next call on the same resolver.
	 *
	 * @testdox Should follow a connection state that changes mid-request without caching the earlier bearer.
	 */
	public function test_bearer_reflects_connection_state_changes_mid_request_without_caching(): void {
		$connection_state = new ConnectionState( false, new Environment( false ) );
		$sut              = $this->make_sut( $connection_state );

		$bearer_before_connect = $sut->bearer();

		$connection_state->connect( false );

		$bearer_after_connect = $sut->bearer();

		$this->assertSame( 'token', $bearer_before_connect->token() );
		$this->assertSame( 'paypal-token', $bearer_after_connect->token() );
		$this->assertNotSame( $bearer_before_connect->token(), $bearer_after_connect->token() );
	}
}
