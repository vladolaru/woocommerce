<?php
/**
 * Tests for the PayPal bearer.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WP_Error;

/**
 * The bearer asks PayPal for a client-credentials token over a stubbed HTTP layer, keeps it in a real transient and
 * stays quiet while the rate limiter (a mock) holds a cool-down.
 *
 * @group paypal-wallet
 */
class PayPalBearerTest extends WalletTestCase {

	private const HOST            = 'https://example.com';
	private const TOKEN_URL       = 'https://example.com/v1/oauth2/token?grant_type=client_credentials';
	private const CACHE_PREFIX    = 'ppcp-test-';
	private const CACHE_TRANSIENT = self::CACHE_PREFIX . PayPalBearer::CACHE_KEY;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * The rate limiter mock.
	 *
	 * @var TokenRateLimiter|MockInterface
	 */
	private $rate_limiter;

	/**
	 * Set up the collaborators and claim the bearer transient, so the test leaves none behind.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->logger       = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
		$this->rate_limiter = $this->mock( TokenRateLimiter::class );
		$this->set_wallet_transient( self::CACHE_TRANSIENT, '' );
	}

	/**
	 * Build the bearer under test.
	 *
	 * @param string $client_id     The client ID the settings hold.
	 * @param string $client_secret The client secret the settings hold.
	 * @return PayPalBearer
	 */
	private function make_sut( string $client_id = 'key', string $client_secret = 'secret' ): PayPalBearer {
		$settings = $this->mock( SettingsProvider::class );
		$settings->shouldReceive( 'merchant_data' )->andReturn( new MerchantConnectionDTO( true, $client_id, $client_secret, '123' ) );

		return new PayPalBearer( new Cache( self::CACHE_PREFIX ), self::HOST, 'key', 'secret', $this->logger, $settings, $this->rate_limiter );
	}

	/**
	 * The JSON of a token PayPal just issued.
	 *
	 * @return string
	 */
	private function fresh_token_json(): string {
		return (string) wp_json_encode(
			array(
				'access_token' => 'fake-access-token',
				'expires_in'   => 100,
				'created'      => time(),
			)
		);
	}

	/**
	 * Put a token in the bearer transient.
	 *
	 * @param int $created When the token was created, as a Unix timestamp.
	 */
	private function seed_cached_token( int $created ): void {
		$this->set_wallet_transient(
			self::CACHE_TRANSIENT,
			(string) wp_json_encode(
				array(
					'access_token' => 'cached-access-token',
					'expires_in'   => 100,
					'created'      => $created,
				)
			)
		);
	}

	/**
	 * The Authorization header the token request must carry.
	 *
	 * @param string $client_id     The client ID.
	 * @param string $client_secret The client secret.
	 * @return string
	 */
	private function basic_authorization( string $client_id = 'key', string $client_secret = 'secret' ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
	}

	/**
	 * Assert the request at the given position was the token request with the client credentials.
	 *
	 * @param int $index Position in the recorded requests.
	 */
	private function assert_token_request( int $index = 0 ): void {
		$request = $this->http_requests[ $index ];

		$this->assertSame( self::TOKEN_URL, $request['url'] );
		$this->assertSame( 'POST', $request['request']['method'] );
		$this->assertSame( $this->basic_authorization(), $request['request']['headers']['Authorization'] );
	}

	/**
	 * Record the context of every warning the bearer logs.
	 *
	 * @return ArrayObject One entry per warning, holding its context.
	 */
	private function capture_warning_contexts(): ArrayObject {
		$contexts = new ArrayObject();
		$this->logger->shouldReceive( 'warning' )->andReturnUsing(
			static function ( $message, $context ) use ( $contexts ) {
				unset( $message );
				$contexts[] = $context;
			}
		);

		return $contexts;
	}

	/**
	 * Ask for the bearer and expect it to fail.
	 *
	 * @param PayPalBearer $sut The bearer.
	 * @return string The message of the exception.
	 */
	private function assert_bearer_fails( PayPalBearer $sut ): string {
		try {
			$sut->bearer();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The bearer should have thrown a RuntimeException' );
	}

	/**
	 * @testdox Should replace an expired cached token with a new one from PayPal and cache it.
	 */
	public function test_expired_cached_token_is_replaced_by_a_new_one(): void {
		$this->seed_cached_token( 100 );
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'bearer' );
		$this->stub_http( $this->http_response( 200, $this->fresh_token_json() ) );

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'fake-access-token', $token->token() );
		$this->assertTrue( $token->is_valid() );
		$this->assertCount( 1, $this->http_requests );
		$this->assert_token_request();
		$this->assertSame( 'fake-access-token', Token::from_json( (string) get_transient( self::CACHE_TRANSIENT ) )->token() );
	}

	/**
	 * @testdox Should request a token from PayPal when none is cached.
	 */
	public function test_no_token_cached(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'bearer' );
		$this->stub_http( $this->http_response( 200, $this->fresh_token_json() ) );

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'fake-access-token', $token->token() );
		$this->assertTrue( $token->is_valid() );
		$this->assertCount( 1, $this->http_requests );
		$this->assert_token_request();
		$this->assertSame( 'fake-access-token', Token::from_json( (string) get_transient( self::CACHE_TRANSIENT ) )->token() );
	}

	/**
	 * @testdox Should discard an unparsable cached token and request a new one.
	 */
	public function test_corrupt_cached_token_is_discarded(): void {
		$this->set_wallet_transient( self::CACHE_TRANSIENT, '{"access_token":"cached-access-token"}' );
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'bearer' );
		$this->stub_http( $this->http_response( 200, $this->fresh_token_json() ) );

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'fake-access-token', $token->token() );
		$this->assertCount( 1, $this->http_requests );
	}

	/**
	 * @testdox Should return a still valid cached token without any request or rate limiter call.
	 */
	public function test_cached_token_is_still_valid(): void {
		$this->seed_cached_token( time() );
		$this->logger->shouldNotReceive( 'warning' );

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'cached-access-token', $token->token() );
		$this->assertTrue( $token->is_valid() );
		$this->assertCount( 0, $this->http_requests, 'A valid cached token must never touch the network' );
	}

	/**
	 * @testdox Should throw and register a failure with status 0 when the request fails at the transport level, after one retry.
	 */
	public function test_exception_thrown_on_error(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'bearer', 0, \Mockery::type( WP_Error::class ) );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$message = $this->assert_bearer_fails( $this->make_sut() );

		$this->assertSame( 'Could not create token.', $message );

		$this->assertCount( 2, $this->http_requests, 'A connection error is retried once' );
		$this->assert_token_request( 1 );
	}

	/**
	 * @testdox Should throw and register the status code when PayPal answers with a status other than 200.
	 */
	public function test_exception_thrown_because_of_http_status_code(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'bearer', 500, \Mockery::type( 'array' ) );
		$this->stub_http( $this->http_response( 500, $this->fresh_token_json() ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $this->http_requests );
		$this->assert_token_request();
	}

	/**
	 * @testdox Should not send a request while the rate limiter holds a cool-down.
	 */
	public function test_no_request_while_in_cooldown(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->with( 'bearer' )->andReturn( 120 );

		$message = $this->assert_bearer_fails( $this->make_sut() );

		$this->assertStringContainsString( '120 more seconds', $message );

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * A failing refresh of an expired but cached token must request a token only once. The previous implementation
	 * called newBearer() in both the try and the catch, which sent two requests per call.
	 *
	 * @testdox Should send one request, not two, when refreshing an expired cached token fails.
	 */
	public function test_no_double_fetch(): void {
		$this->seed_cached_token( time() - 1000 );
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' )->once()->with( 'bearer', 500, \Mockery::type( 'array' ) );
		$this->stub_http( $this->http_response( 500, '{}' ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $this->http_requests, 'Exactly one HTTP request despite the failure' );
	}

	/**
	 * @testdox Should throw without a request or a rate limiter call when the client ID and secret are empty.
	 */
	public function test_empty_credentials_short_circuit(): void {
		$message = $this->assert_bearer_fails( $this->make_sut( '', '' ) );

		$this->assertStringContainsString( 'without a client ID and secret', $message );

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should clear the cool-down after PayPal issued a token.
	 */
	public function test_success_clears_cooldown(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'bearer' );
		$this->stub_http( $this->http_response( 200, $this->fresh_token_json() ) );

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'fake-access-token', $token->token() );
	}

	/**
	 * @testdox Should not log the Authorization value when the request fails at the transport level.
	 */
	public function test_logged_context_does_not_contain_authorization_value_on_wp_error(): void {
		$contexts = $this->capture_warning_contexts();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $contexts );
		$authorization = $contexts[0]['args']['headers']['Authorization'] ?? null;
		$this->assertSame( '[REDACTED]', $authorization );
		$this->assertStringNotContainsString( substr( $this->basic_authorization(), strlen( 'Basic ' ) ), (string) $authorization );
	}

	/**
	 * @testdox Should replace the Authorization header with a redacted placeholder in the logged context of a non-2xx answer.
	 */
	public function test_non_2xx_logged_context_replaces_authorization_with_redacted_placeholder(): void {
		$contexts = $this->capture_warning_contexts();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' );
		$this->stub_http( $this->http_response( 500, '' ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $contexts );
		$this->assertSame( '[REDACTED]', $contexts[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'POST', $contexts[0]['args']['method'] );
	}

	/**
	 * @testdox Should keep the base64 credentials out of the logs when the token request is rate limited.
	 */
	public function test_base64_credentials_absent_from_logs_on_various_token_request_failures(): void {
		$contexts = $this->capture_warning_contexts();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' );
		$this->stub_http( $this->http_response( 429, '' ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $contexts );
		$authorization = $contexts[0]['args']['headers']['Authorization'] ?? null;
		$this->assertStringNotContainsString( substr( $this->basic_authorization(), strlen( 'Basic ' ) ), (string) $authorization );
	}

	/**
	 * @testdox Should redact every credential-bearing header before logging.
	 */
	public function test_all_credential_bearing_header_values_are_redacted_before_logging(): void {
		$contexts = $this->capture_warning_contexts();
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'register_failure' );
		$this->stub_http( $this->http_response( 503, '' ) );

		$this->assert_bearer_fails( $this->make_sut() );

		$this->assertCount( 1, $contexts );
		$redacted = 0;
		foreach ( array_keys( $contexts[0]['args']['headers'] ?? array() ) as $header_name ) {
			if ( preg_match( '/authorization|signature/i', $header_name ) ) {
				$this->assertSame( '[REDACTED]', $contexts[0]['args']['headers'][ $header_name ] );
				++$redacted;
			}
		}
		$this->assertGreaterThan( 0, $redacted, 'At least the Authorization header is credential-bearing' );
	}

	/**
	 * @testdox Should retry once on a connection error without arming the cool-down when the retry succeeds.
	 */
	public function test_retries_once_on_connection_error(): void {
		$this->rate_limiter->shouldReceive( 'retry_after_seconds' )->andReturn( null );
		$this->rate_limiter->shouldReceive( 'clear' )->once()->with( 'bearer' );
		// A blip that recovers on retry must not arm the cool-down.
		$this->rate_limiter->shouldNotReceive( 'register_failure' );
		$answers = array(
			new WP_Error( 'http_request_failed', 'blip' ),
			$this->http_response( 200, $this->fresh_token_json() ),
		);
		$this->stub_http(
			static function () use ( &$answers ) {
				return array_shift( $answers );
			}
		);

		$token = $this->make_sut()->bearer();

		$this->assertSame( 'fake-access-token', $token->token() );
		$this->assertCount( 2, $this->http_requests, 'The failed attempt and the retry' );
		$this->assert_token_request( 0 );
		$this->assert_token_request( 1 );
	}
}
