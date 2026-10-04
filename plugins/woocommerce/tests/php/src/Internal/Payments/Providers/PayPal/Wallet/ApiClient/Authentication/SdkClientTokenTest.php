<?php
/**
 * Tests for the SDK client token.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ClientCredentials;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\SdkClientToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use WP_Error;

/**
 * The SDK client token: requested from PayPal over a stubbed HTTP layer, cached per site domain in a real transient and
 * guarded by the rate limiter (a mock).
 *
 * @group paypal-wallet
 */
class SdkClientTokenTest extends WalletTestCase {

	private const DOMAIN          = 'shop.example.com';
	private const HOST            = 'https://example.com';
	private const CACHE_PREFIX    = 'ppcp-test-sdk-';
	private const CACHE_TRANSIENT = self::CACHE_PREFIX . SdkClientToken::CACHE_KEY . '-' . self::DOMAIN;

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
	 * @var SdkClientToken
	 */
	private $sut;

	/**
	 * Point the site at a known domain, claim the token transient and build the object under test.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter(
			'home_url',
			static function () {
				return 'https://' . self::DOMAIN;
			}
		);
		$this->set_wallet_transient( self::CACHE_TRANSIENT, '' );
		delete_transient( self::CACHE_TRANSIENT );

		$this->credentials  = $this->mock( ClientCredentials::class );
		$this->rate_limiter = $this->mock( TokenRateLimiter::class );
		$this->sut          = new SdkClientToken(
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
	 * The body of a token answer.
	 *
	 * @param int $expires_in The lifetime PayPal reports, in seconds.
	 * @return string
	 */
	private function token_body( int $expires_in ): string {
		return (string) wp_json_encode(
			array(
				'access_token' => 'fake-client-token',
				'expires_in'   => $expires_in,
			)
		);
	}

	/**
	 * Assert the request at the given position was the client token request with the stored credentials.
	 *
	 * @param int $index Position in the recorded requests.
	 */
	private function assert_client_token_request( int $index = 0 ): void {
		$request = $this->http_requests[ $index ];

		$this->assertSame(
			self::HOST . '/v1/oauth2/token?grant_type=client_credentials&response_type=client_token&intent=sdk_init&domains[]=' . self::DOMAIN,
			$request['url']
		);
		$this->assertSame( 'POST', $request['request']['method'] );
		$this->assertSame( 'Basic fake-credentials', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $request['request']['headers']['Content-Type'] );
	}

	/**
	 * @testdox Should return the cached token without any request.
	 */
	public function test_cached_token_returned_without_request(): void {
		$this->set_wallet_transient( self::CACHE_TRANSIENT, 'cached-token' );

		$this->assertSame( 'cached-token', $this->sut->sdk_client_token() );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should throw without a request when the client ID or secret is missing.
	 */
	public function test_empty_credentials_short_circuit(): void {
		$this->credentials->shouldReceive( 'is_empty' )->andReturn( true );

		$this->expectException( RuntimeException::class );
		try {
			$this->sut->sdk_client_token();
		} finally {
			$this->assertCount( 0, $this->http_requests );
		}
	}

	/**
	 * @testdox Should throw without a request while the rate limiter holds a cool-down.
	 */
	public function test_blocked_returns_fast_without_request(): void {
		$this->credentials->shouldReceive( 'is_empty' )->andReturn( false );
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->with( 'sdk-client-token' )->andReturn( 60 );

		$this->expectException( RuntimeException::class );
		try {
			$this->sut->sdk_client_token();
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
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'sdk-client-token', 429, Mockery::type( 'array' ) );
		$this->stub_http( $this->http_response( 429, '{"error":"rate"}' ) );

		try {
			$this->sut->sdk_client_token();
			$this->fail( 'A 429 should throw a PayPalApiException' );
		} catch ( PayPalApiException $e ) {
			$this->assertSame( 429, $e->getCode() );
		}

		$this->assertCount( 1, $this->http_requests, 'A status code is not retried' );
		$this->assert_client_token_request();
		$this->assertFalse( get_transient( self::CACHE_TRANSIENT ), 'A failed request caches nothing' );
	}

	/**
	 * Given a successful token response from PayPal, a long-lived token is cached 30 seconds short of its reported
	 * lifetime and a short-lived one (150 seconds or less) is cached unchanged.
	 *
	 * @testdox Should cache the token for an adjusted lifetime.
	 *
	 * @dataProvider data_cached_lifetime
	 *
	 * @param int $expires_in               The lifetime PayPal reports.
	 * @param int $expected_cached_lifetime The lifetime the token is cached for.
	 */
	public function test_caches_token_for_adjusted_lifetime( int $expires_in, int $expected_cached_lifetime ): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'sdk-client-token' );
		$this->stub_http( $this->http_response( 200, $this->token_body( $expires_in ) ) );

		$token = $this->sut->sdk_client_token();

		$this->assertSame( 'fake-client-token', $token );
		$this->assertSame( 'fake-client-token', get_transient( self::CACHE_TRANSIENT ) );
		$this->assertEqualsWithDelta(
			time() + $expected_cached_lifetime,
			(int) get_option( '_transient_timeout_' . self::CACHE_TRANSIENT ),
			5,
			'The transient should expire after the adjusted lifetime'
		);
		$this->assert_client_token_request();
	}

	/**
	 * The lifetimes PayPal reports and the lifetimes the token is cached for.
	 *
	 * @return array<string, array>
	 */
	public function data_cached_lifetime(): array {
		return array(
			'long-lived token cached 30 seconds short of its reported lifetime' => array( 3600, 3570 ),
			'short-lived token at the 150 second boundary cached unchanged'     => array( 150, 150 ),
			'short-lived token below the boundary cached unchanged'             => array( 60, 60 ),
		);
	}

	/**
	 * @testdox Should send the domain without a leading www and cache the token under it.
	 */
	public function test_domain_loses_its_leading_www(): void {
		add_filter(
			'home_url',
			static function () {
				return 'https://www.' . self::DOMAIN;
			}
		);
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once();
		$this->stub_http( $this->http_response( 200, $this->token_body( 3600 ) ) );

		$this->assertSame( 'fake-client-token', $this->sut->sdk_client_token() );

		$this->assert_client_token_request();
		$this->assertSame( 'fake-client-token', get_transient( self::CACHE_TRANSIENT ) );
	}

	/**
	 * @testdox Should retry once on a connection error without arming the cool-down when the retry succeeds.
	 */
	public function test_retries_once_on_connection_error(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'sdk-client-token' );
		// A blip that recovers on retry must not arm the cool-down.
		$this->rate_limiter->shouldNotReceive( 'register_failure' );
		$answers = array(
			new WP_Error( 'http_request_failed', 'blip' ),
			$this->http_response( 200, $this->token_body( 3600 ) ),
		);
		$this->stub_http(
			static function () use ( &$answers ) {
				return array_shift( $answers );
			}
		);

		$this->assertSame( 'fake-client-token', $this->sut->sdk_client_token() );

		$this->assertCount( 2, $this->http_requests, 'The failed attempt and the retry' );
		$this->assertEqualsWithDelta( time() + 3570, (int) get_option( '_transient_timeout_' . self::CACHE_TRANSIENT ), 5 );
	}

	/**
	 * @testdox Should register a failure with status 0 and throw when both attempts fail at the transport level.
	 */
	public function test_registers_failure_when_the_retry_fails_too(): void {
		$this->with_credentials();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'sdk-client-token', 0, Mockery::type( WP_Error::class ) );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Connection timed out' );
		try {
			$this->sut->sdk_client_token();
		} finally {
			$this->assertCount( 2, $this->http_requests );
		}
	}
}
