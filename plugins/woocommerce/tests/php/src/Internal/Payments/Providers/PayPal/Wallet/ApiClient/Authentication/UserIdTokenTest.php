<?php
/**
 * Tests for the user ID token.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ClientCredentials;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\UserIdToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use WC_Session_Handler;
use WP_Error;

/**
 * The user ID token: requested from PayPal over a stubbed HTTP layer, kept for a few seconds per shopper session in a
 * real transient and guarded by the rate limiter (a mock).
 *
 * @group paypal-wallet
 */
class UserIdTokenTest extends WalletTestCase {

	private const HOST         = 'https://example.com';
	private const CACHE_PREFIX = 'ppcp-test-id-';
	private const TOKEN_URL    = 'https://example.com/v1/oauth2/token?grant_type=client_credentials&response_type=id_token';

	/**
	 * The client credentials mock.
	 *
	 * @var ClientCredentials|MockInterface
	 */
	private $credentials;

	/**
	 * The rate limiter mock.
	 *
	 * @var TokenRateLimiter|MockInterface
	 */
	private $rate_limiter;

	/**
	 * The token object under test.
	 *
	 * @var UserIdToken
	 */
	private $sut;

	/**
	 * Give WooCommerce a session without a customer ID and build the object under test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->use_own_wc_session();

		$this->credentials  = $this->mock( ClientCredentials::class );
		$this->rate_limiter = $this->mock( TokenRateLimiter::class );
		$this->sut          = new UserIdToken(
			self::HOST,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			$this->credentials,
			new Cache( self::CACHE_PREFIX ),
			$this->rate_limiter
		);
	}

	/**
	 * Let the credentials be present.
	 */
	private function with_credentials(): void {
		$this->credentials->shouldReceive( 'is_empty' )->andReturn( false );
		$this->credentials->shouldReceive( 'credentials' )->andReturn( 'Basic fake-credentials' );
	}

	/**
	 * Give WooCommerce a session that belongs to a shopper.
	 *
	 * @param string $customer_id The customer ID of the session.
	 */
	private function use_session_of( string $customer_id ): void {
		$this->use_own_wc_session(
			new class( $customer_id ) extends WC_Session_Handler {
				/**
				 * A session with a fixed customer ID.
				 *
				 * @param string $customer_id The customer ID.
				 */
				public function __construct( string $customer_id ) {
					$this->_customer_id = $customer_id; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
				}
			}
		);
	}

	/**
	 * Assert the request at the given position was the ID token request with the stored credentials.
	 *
	 * @param string $url   The expected URL.
	 * @param int    $index Position in the recorded requests.
	 */
	private function assert_id_token_request( string $url = self::TOKEN_URL, int $index = 0 ): void {
		$request = $this->http_requests[ $index ];

		$this->assertSame( $url, $request['url'] );
		$this->assertSame( 'POST', $request['request']['method'] );
		$this->assertSame( 'Basic fake-credentials', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $request['request']['headers']['Content-Type'] );
	}

	/**
	 * @testdox Should throw without a request when the client ID or secret is missing.
	 */
	public function test_empty_credentials_short_circuit(): void {
		$this->credentials->shouldReceive( 'is_empty' )->andReturn( true );

		$this->expectException( RuntimeException::class );
		try {
			$this->sut->id_token();
		} finally {
			$this->assertCount( 0, $this->http_requests );
		}
	}

	/**
	 * @testdox Should throw without a request while the rate limiter holds a cool-down.
	 */
	public function test_blocked_returns_fast_without_request(): void {
		$this->credentials->shouldReceive( 'is_empty' )->andReturn( false );
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->with( 'id-token' )->andReturn( 60 );

		$this->expectException( RuntimeException::class );
		try {
			$this->sut->id_token();
		} finally {
			$this->assertCount( 0, $this->http_requests );
		}
	}

	/**
	 * @testdox Should register a failure with the status code and throw a PayPal API exception on a 429.
	 */
	public function test_429_registers_failure_and_throws(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'id-token', 429, Mockery::type( 'array' ) );
		$this->stub_http( $this->http_response( 429, '{"error":"rate"}' ) );

		try {
			$this->sut->id_token();
			$this->fail( 'A 429 should throw a PayPalApiException' );
		} catch ( PayPalApiException $e ) {
			$this->assertSame( 429, $e->getCode() );
		}

		$this->assertCount( 1, $this->http_requests, 'A status code is not retried' );
		$this->assert_id_token_request();
	}

	/**
	 * @testdox Should return the ID token PayPal issued and clear the cool-down.
	 */
	public function test_success(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'id-token' );
		$this->stub_http( $this->http_response( 200, '{"id_token":"fake-id-token"}' ) );

		$this->assertSame( 'fake-id-token', $this->sut->id_token() );

		$this->assertCount( 1, $this->http_requests );
		$this->assert_id_token_request();
	}

	/**
	 * @testdox Should add the vaulted customer to the request when a target customer ID is given.
	 */
	public function test_target_customer_id_is_added_to_the_request(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' );
		$this->stub_http( $this->http_response( 200, '{"id_token":"fake-id-token"}' ) );

		$this->assertSame( 'fake-id-token', $this->sut->id_token( 'fake-customer' ) );

		$this->assert_id_token_request( self::TOKEN_URL . '&target_customer_id=fake-customer' );
	}

	/**
	 * @testdox Should keep the token for the session of a shopper for a few seconds and serve it without a request.
	 */
	public function test_token_is_cached_for_the_session_customer(): void {
		$this->use_session_of( 'fake-session-customer' );
		$this->set_wallet_transient( self::CACHE_PREFIX . UserIdToken::CACHE_KEY . 'fake-session-customer', '' );
		delete_transient( self::CACHE_PREFIX . UserIdToken::CACHE_KEY . 'fake-session-customer' );
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once();
		$this->stub_http( $this->http_response( 200, '{"id_token":"fake-id-token"}' ) );

		$first  = $this->sut->id_token();
		$second = $this->sut->id_token();

		$this->assertSame( 'fake-id-token', $first );
		$this->assertSame( 'fake-id-token', $second );
		$this->assertCount( 1, $this->http_requests, 'The second call is served from the cache' );
		$this->assertEqualsWithDelta(
			time() + 5,
			(int) get_option( '_transient_timeout_' . self::CACHE_PREFIX . UserIdToken::CACHE_KEY . 'fake-session-customer' ),
			3,
			'The token is kept for 5 seconds'
		);
	}

	/**
	 * @testdox Should retry once on a connection error without arming the cool-down when the retry succeeds.
	 */
	public function test_retries_once_on_connection_error(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'id-token' );
		// A blip that recovers on retry must not arm the cool-down.
		$this->rate_limiter->shouldNotReceive( 'register_failure' );
		$answers = array(
			new WP_Error( 'http_request_failed', 'blip' ),
			$this->http_response( 200, '{"id_token":"fake-id-token"}' ),
		);
		$this->stub_http(
			static function () use ( &$answers ) {
				return array_shift( $answers );
			}
		);

		$this->assertSame( 'fake-id-token', $this->sut->id_token() );

		$this->assertCount( 2, $this->http_requests, 'The failed attempt and the retry' );
	}
}
