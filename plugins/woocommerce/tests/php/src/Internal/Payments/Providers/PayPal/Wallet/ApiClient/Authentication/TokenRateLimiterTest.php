<?php
/**
 * Tests for the token rate limiter.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WP_Error;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * The circuit breaker for token requests: the cool-down state lives in a real transient and the failures it is told
 * about are real WordPress responses.
 *
 * @group paypal-wallet
 */
class TokenRateLimiterTest extends WalletTestCase {

	private const CACHE_PREFIX = 'ppcp-test-limiter-';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * The rate limiter under test.
	 *
	 * @var TokenRateLimiter
	 */
	private $sut;

	/**
	 * Set up the limiter over a real cache and claim the state transient of every scope the tests use.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->logger = $this->mock( LoggerInterface::class );
		$this->sut    = new TokenRateLimiter( new Cache( self::CACHE_PREFIX ), $this->logger );

		foreach ( array( 'bearer', 'sdk-client-token', 'id-token' ) as $scope ) {
			// Claim the transient for cleanup and leave it unset.
			$this->set_wallet_transient( $this->state_transient( $scope ), array() );
			delete_transient( $this->state_transient( $scope ) );
		}
	}

	/**
	 * The name of the transient that holds the cool-down state of a scope.
	 *
	 * @param string $scope The token-type key.
	 * @return string
	 */
	private function state_transient( string $scope ): string {
		return self::CACHE_PREFIX . $scope . TokenRateLimiter::STATE_KEY_SUFFIX;
	}

	/**
	 * Put a cool-down state in the transient of a scope.
	 *
	 * @param string $scope    The token-type key.
	 * @param int    $retry_at When requests are allowed again, as a Unix timestamp.
	 * @param int    $count    The consecutive failure count.
	 * @param string $reason   The failure reason.
	 */
	private function seed_state( string $scope, int $retry_at, int $count, string $reason ): void {
		$this->set_wallet_transient(
			$this->state_transient( $scope ),
			array(
				'retry_at' => $retry_at,
				'count'    => $count,
				'reason'   => $reason,
			)
		);
	}

	/**
	 * A failed answer from the token endpoint.
	 *
	 * @param string $body    The body.
	 * @param array  $headers The response headers, by lowercase name.
	 * @return array
	 */
	private function failed_response( string $body = '', array $headers = array() ): array {
		$response            = $this->http_response( 400, $body );
		$response['headers'] = new CaseInsensitiveDictionary( $headers );

		return $response;
	}

	/**
	 * The state the limiter stored for a scope.
	 *
	 * @param string $scope The token-type key.
	 * @return array
	 */
	private function stored_state( string $scope ): array {
		$state = get_transient( $this->state_transient( $scope ) );
		$this->assertIsArray( $state, 'The failure should store a cool-down state' );

		return $state;
	}

	/**
	 * @testdox Should allow a token request when there is no cool-down state.
	 */
	public function test_not_blocked_when_no_state(): void {
		$this->assertNull( $this->sut->retry_after_seconds( 'bearer' ) );
		$this->assertFalse( $this->sut->is_blocked( 'bearer' ) );
	}

	/**
	 * @testdox Should report the seconds left while a cool-down is running.
	 */
	public function test_blocked_within_cooldown(): void {
		$this->seed_state( 'bearer', time() + 120, 1, 'rate_limited_backoff' );

		$remaining = $this->sut->retry_after_seconds( 'bearer' );

		$this->assertNotNull( $remaining );
		$this->assertGreaterThan( 0, $remaining );
		$this->assertLessThanOrEqual( 120, $remaining );
		$this->assertTrue( $this->sut->is_blocked( 'bearer' ) );
	}

	/**
	 * @testdox Should allow a token request again once the cool-down has expired.
	 */
	public function test_not_blocked_after_cooldown_expires(): void {
		$this->seed_state( 'bearer', time() - 1, 1, 'rate_limited_backoff' );

		$this->assertNull( $this->sut->retry_after_seconds( 'bearer' ) );
	}

	/**
	 * @testdox Should keep the cool-down of one scope away from the other scopes.
	 */
	public function test_cooldown_is_per_scope(): void {
		$this->seed_state( 'bearer', time() + 120, 1, 'server_error' );

		$this->assertTrue( $this->sut->is_blocked( 'bearer' ) );
		$this->assertFalse( $this->sut->is_blocked( 'id-token' ) );
	}

	/**
	 * @testdox Should use the numeric Retry-After of a 429 as the cool-down.
	 */
	public function test_register_failure_429_with_numeric_retry_after(): void {
		$this->logger->shouldReceive( 'warning' )->once();

		$cooldown = $this->sut->register_failure( 'bearer', 429, $this->failed_response( '{"error":"RATE_LIMIT_REACHED"}', array( 'retry-after' => '90' ) ) );

		$this->assertSame( 90, $cooldown );
		$state = $this->stored_state( 'bearer' );
		$this->assertSame( 'rate_limited_retry_after', $state['reason'] );
		$this->assertSame( 1, $state['count'] );
		$this->assertEqualsWithDelta( time() + 90, $state['retry_at'], 2 );
	}

	/**
	 * @testdox Should use the HTTP-date Retry-After of a 429 as the cool-down.
	 */
	public function test_register_failure_429_with_http_date_retry_after(): void {
		$this->logger->shouldReceive( 'warning' );
		$future = gmdate( 'D, d M Y H:i:s', time() + 30 ) . ' GMT';

		$cooldown = $this->sut->register_failure( 'bearer', 429, $this->failed_response( '', array( 'retry-after' => $future ) ) );

		$this->assertGreaterThanOrEqual( 25, $cooldown );
		$this->assertLessThanOrEqual( 31, $cooldown );
		$this->assertSame( 'rate_limited_retry_after', $this->stored_state( 'bearer' )['reason'] );
	}

	/**
	 * @testdox Should back off exponentially when a 429 carries no Retry-After.
	 */
	public function test_register_failure_429_without_retry_after_uses_backoff(): void {
		$this->logger->shouldReceive( 'warning' );

		$cooldown = $this->sut->register_failure( 'bearer', 429, $this->failed_response() );

		// The first backoff is a base of 30 seconds plus a jitter of 0 to 7.
		$this->assertGreaterThanOrEqual( 30, $cooldown );
		$this->assertLessThanOrEqual( 38, $cooldown );
		$this->assertSame( 'rate_limited_backoff', $this->stored_state( 'bearer' )['reason'] );
	}

	/**
	 * @testdox Should fall back to the backoff when the Retry-After value cannot be parsed.
	 */
	public function test_unparseable_retry_after_falls_back_to_backoff(): void {
		$this->logger->shouldReceive( 'warning' );

		$cooldown = $this->sut->register_failure( 'bearer', 429, $this->failed_response( '', array( 'retry-after' => 'not-a-date' ) ) );

		$this->assertGreaterThanOrEqual( 30, $cooldown );
		$this->assertLessThanOrEqual( 38, $cooldown );
		$this->assertSame( 'rate_limited_backoff', $this->stored_state( 'bearer' )['reason'] );
	}

	/**
	 * @testdox Should grow the backoff with the number of earlier failures.
	 */
	public function test_backoff_grows_with_failure_count(): void {
		$this->seed_state( 'bearer', time() - 1, 2, 'rate_limited_backoff' );
		$this->logger->shouldReceive( 'warning' );

		$cooldown = $this->sut->register_failure( 'bearer', 429, $this->failed_response() );

		// The third backoff is a base of 30 * 2^2 = 120 seconds plus a jitter of 0 to 30.
		$this->assertGreaterThanOrEqual( 120, $cooldown );
		$this->assertLessThanOrEqual( 151, $cooldown );
		$this->assertSame( 3, $this->stored_state( 'bearer' )['count'] );
	}

	/**
	 * @testdox Should cap the backoff at 900 seconds.
	 */
	public function test_backoff_caps_at_max(): void {
		$this->seed_state( 'bearer', time() - 1, 30, 'server_error' );
		$this->logger->shouldNotReceive( 'warning' );

		$cooldown = $this->sut->register_failure( 'bearer', 500, $this->failed_response() );

		$this->assertSame( 900, $cooldown );
	}

	/**
	 * The limiter treats every 401 as rejected credentials and pauses token requests for 30 minutes, whatever PayPal
	 * says in the body. That includes the 401 PayPal sends for an invalid_domain error (see the next test); it is kept
	 * as today's behaviour.
	 *
	 * @testdox Should treat a 401 as rejected credentials and pause for 30 minutes.
	 */
	public function test_register_failure_401_uses_dead_credentials_cooldown(): void {
		$this->logger->shouldReceive( 'warning' )->once();

		$cooldown = $this->sut->register_failure( 'bearer', 401, $this->failed_response() );

		$this->assertSame( 1800, $cooldown );
		$this->assertSame( 'dead_credentials', $this->stored_state( 'bearer' )['reason'] );
	}

	/**
	 * Defect kept as it is today: PayPal answers a client token request for a domain it rejects (a .localhost host)
	 * with a 401 and the error invalid_domain, and the limiter treats every 401 as rejected credentials, so it pauses
	 * token requests of the scope for 30 minutes. The credentials were fine; the limiter should leave invalid_domain
	 * out of the rejected-credentials case.
	 *
	 * @testdox Should pause for 30 minutes on a 401 invalid_domain, as it does for rejected credentials (a defect kept as it is today).
	 */
	public function test_register_failure_401_invalid_domain_counts_as_dead_credentials(): void {
		$this->logger->shouldReceive( 'warning' )->once();
		$body = '{"error":"invalid_domain","error_description":"Domain format not valid"}';

		$cooldown = $this->sut->register_failure( 'sdk-client-token', 401, $this->failed_response( $body ) );

		$this->assertSame( 1800, $cooldown );
		$this->assertSame( 'dead_credentials', $this->stored_state( 'sdk-client-token' )['reason'] );
	}

	/**
	 * @testdox Should treat an invalid_client body as rejected credentials whatever the status code.
	 */
	public function test_register_failure_invalid_client_body_uses_dead_credentials(): void {
		$this->logger->shouldReceive( 'warning' )->once();

		$cooldown = $this->sut->register_failure( 'sdk-client-token', 400, $this->failed_response( '{"error":"invalid_client"}' ) );

		$this->assertSame( 1800, $cooldown );
	}

	/**
	 * @testdox Should back off, without a warning, when the request failed at the transport level.
	 */
	public function test_register_failure_network_uses_backoff(): void {
		$this->logger->shouldNotReceive( 'warning' );

		$cooldown = $this->sut->register_failure( 'id-token', 0, new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->assertGreaterThanOrEqual( 30, $cooldown );
		$this->assertLessThanOrEqual( 38, $cooldown );
		$this->assertSame( 'network_error', $this->stored_state( 'id-token' )['reason'] );
	}

	/**
	 * @testdox Should delete the cool-down state of the scope when cleared.
	 */
	public function test_clear_deletes_state(): void {
		$this->seed_state( 'bearer', time() + 120, 1, 'server_error' );
		$this->seed_state( 'id-token', time() + 120, 1, 'server_error' );

		$this->sut->clear( 'bearer' );

		$this->assertFalse( get_transient( $this->state_transient( 'bearer' ) ) );
		$this->assertFalse( $this->sut->is_blocked( 'bearer' ) );
		$this->assertTrue( $this->sut->is_blocked( 'id-token' ), 'Clearing one scope leaves the others' );
	}
}
