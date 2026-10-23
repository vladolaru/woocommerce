<?php
/**
 * Tests for the partners endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerStatusFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use WP_Error;

/**
 * The seller status: fetched from PayPal over a stubbed HTTP layer, kept in a real transient for ten minutes, and
 * guarded by a real failure registry that stops a failing account from being polled.
 *
 * @group paypal-wallet
 */
class PartnersEndpointTest extends WalletTestCase {

	private const HOST                 = 'https://api.sandbox.paypal.com/';
	private const PARTNER_ID           = 'partner-abc';
	private const MERCHANT_ID          = 'merchant-xyz';
	private const STATUS_URL           = 'https://api.sandbox.paypal.com/v1/customer/partners/partner-abc/merchant-integrations/merchant-xyz';
	private const STATUS_TRANSIENT     = 'ppcp-test-seller-' . PartnersEndpoint::SELLER_STATUS_CACHE_KEY;
	private const FAILURE_TRANSIENT    = 'ppcp-test-failure-' . FailureRegistry::CACHE_KEY . '_' . FailureRegistry::SELLER_STATUS_KEY;
	private const SELLER_STATUS_FILTER = 'woocommerce_paypal_payments_seller_status';
	private const FALLBACK_FILTER      = 'woocommerce_paypal_payments_seller_status_fallback';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * The failure registry, over a real cache.
	 *
	 * @var FailureRegistry
	 */
	private $failure_registry;

	/**
	 * Set up the collaborators and claim the two transients the endpoint writes, so the test leaves none behind.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( self::STATUS_TRANSIENT, self::FAILURE_TRANSIENT ) as $name ) {
			$this->set_wallet_transient( $name, '' );
			delete_transient( $name );
		}

		$this->logger           = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
		$this->failure_registry = new FailureRegistry( new Cache( 'ppcp-test-failure-' ) );
	}

	/**
	 * Build the endpoint under test.
	 *
	 * @return PartnersEndpoint
	 */
	private function make_endpoint(): PartnersEndpoint {
		return new PartnersEndpoint(
			self::HOST,
			$this->make_bearer( false ),
			$this->logger,
			new SellerStatusFactory(),
			self::PARTNER_ID,
			self::MERCHANT_ID,
			$this->failure_registry,
			new Cache( 'ppcp-test-seller-' )
		);
	}

	/**
	 * Answer the merchant-integrations GET with the given response and everything else with an error. The error makes
	 * a request to another URL or with another method show up as a failed fetch.
	 *
	 * @param array|WP_Error $response The answer to the status request.
	 */
	private function stub_merchant_integrations( $response ): void {
		$this->stub_http(
			static function ( $request, $url ) use ( $response ) {
				if ( self::STATUS_URL !== $url || 'GET' !== $request['method'] ) {
					return new WP_Error( 'unexpected_request', $request['method'] . ' ' . $url );
				}

				return $response;
			}
		);
	}

	/**
	 * Assert the one request the endpoint sent: its URL, method and headers.
	 */
	private function assert_single_status_request(): void {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );

		$request = $this->http_requests[0];
		$this->assertSame( self::STATUS_URL, $request['url'] );
		$this->assertSame( 'GET', $request['request']['method'] );
		$this->assertSame( 'Bearer bearer', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['request']['headers']['Content-Type'] );
	}

	/**
	 * A status answer from PayPal for a merchant in the United Kingdom.
	 *
	 * @return string
	 */
	private function status_body(): string {
		return (string) wp_json_encode(
			array(
				'country'      => 'GB',
				'products'     => array(
					array(
						'name'           => 'PPCP_STANDARD',
						'vetting_status' => 'SUBSCRIBED',
						'capabilities'   => array( 'PAYPAL_WALLET_VAULTING_ADVANCED' ),
					),
				),
				'capabilities' => array(
					array(
						'name'   => 'PAYPAL_WALLET_VAULTING_ADVANCED',
						'status' => 'ACTIVE',
					),
				),
			)
		);
	}

	/**
	 * Register a fallback status for failed fetches.
	 *
	 * @return SellerStatus The fallback.
	 */
	private function with_fallback(): SellerStatus {
		$fallback = new SellerStatus( array(), array(), 'US' );
		add_filter(
			self::FALLBACK_FILTER,
			static function () use ( $fallback ) {
				return $fallback;
			}
		);

		return $fallback;
	}

	/**
	 * Given a seller status in the transient, seller_status() returns it immediately, sends no request and passes it
	 * through the seller status filter.
	 *
	 * @testdox Should return the cached seller status without an HTTP call.
	 */
	public function test_seller_status_returns_cached_value_without_http_call(): void {
		$cached = new SellerStatus( array(), array(), 'DE' );
		$this->set_wallet_transient( self::STATUS_TRANSIENT, $cached );
		$filter_calls = $this->spy_filter( self::SELLER_STATUS_FILTER );

		$result = $this->make_endpoint()->seller_status();

		$this->assertEquals( $cached, $result );
		$this->assertCount( 0, $this->http_requests );
		$this->assertCount( 1, $filter_calls, 'The status goes through the seller status filter' );
		$this->assertEquals( $cached, $filter_calls[0][0] );
	}

	/**
	 * Given an empty cache, seller_status() queries PayPal, stores the status for the cache TTL under the cache key,
	 * clears the failure registry and returns the status.
	 *
	 * @testdox Should fetch and store the seller status on a cache miss.
	 */
	public function test_seller_status_on_cache_miss_fetches_and_stores_result(): void {
		// A failure older than the back-off window must not block the fetch; a recent one would.
		$this->set_wallet_transient( self::FAILURE_TRANSIENT, time() - PartnersEndpoint::SELLER_STATUS_FAILURE_BACKOFF - 1 );
		$this->stub_merchant_integrations( $this->http_response( 200, $this->status_body() ) );

		$result = $this->make_endpoint()->seller_status();

		$this->assertSame( 'GB', $result->country() );
		$this->assertSame( 'PPCP_STANDARD', $result->products()[0]->name() );
		$this->assertSame( 'PAYPAL_WALLET_VAULTING_ADVANCED', $result->capabilities()[0]->name() );
		$this->assert_single_status_request();
		$this->assertEquals( $result, get_transient( self::STATUS_TRANSIENT ) );
		$this->assertEqualsWithDelta(
			time() + PartnersEndpoint::SELLER_STATUS_CACHE_TTL,
			(int) get_option( '_transient_timeout_' . self::STATUS_TRANSIENT ),
			5,
			'The status is cached for the cache TTL'
		);
		$this->assertFalse( get_transient( self::FAILURE_TRANSIENT ), 'A successful fetch clears the failure' );
	}

	/**
	 * Given an empty cache and a PayPal answer other than 200, seller_status() registers the failure, stores nothing
	 * and throws a PayPal API exception.
	 *
	 * @testdox Should register the failure and throw when the API answers with an error status.
	 */
	public function test_seller_status_on_api_error_registers_failure_and_throws(): void {
		$this->stub_merchant_integrations( $this->http_response( 500, '{"name":"INTERNAL_SERVER_ERROR"}' ) );

		try {
			$this->make_endpoint()->seller_status();
			$this->fail( 'A 500 should throw a PayPalApiException' );
		} catch ( PayPalApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		$this->assert_single_status_request();
		$this->assertTrue( $this->failure_registry->has_failure_in_timeframe( FailureRegistry::SELLER_STATUS_KEY, 60 ) );
		$this->assertFalse( get_transient( self::STATUS_TRANSIENT ), 'A failed fetch caches nothing' );
	}

	/**
	 * Given an empty cache and a request that fails at the transport level (a timeout, DNS), seller_status()
	 * registers the failure, stores nothing and throws a runtime exception.
	 *
	 * @testdox Should register the failure and throw when the request fails at the transport level.
	 */
	public function test_seller_status_on_transport_error_registers_failure_and_throws(): void {
		$this->stub_merchant_integrations( new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		$this->expectException( RuntimeException::class );
		try {
			$this->make_endpoint()->seller_status();
		} finally {
			$this->assertTrue( $this->failure_registry->has_failure_in_timeframe( FailureRegistry::SELLER_STATUS_KEY, 60 ) );
			$this->assertFalse( get_transient( self::STATUS_TRANSIENT ), 'A failed fetch caches nothing' );
		}
	}

	/**
	 * Given a failed fetch and a fallback from the fallback filter, seller_status() uses the fallback instead of
	 * throwing, stores it and logs the failure.
	 *
	 * @testdox Should use the fallback filter's status when the API fails.
	 */
	public function test_seller_status_on_api_error_uses_fallback_filter(): void {
		$fallback = $this->with_fallback();
		$this->logger->shouldReceive( 'log' )->once()->with( 'info', 'Seller status API failed, using configured fallback.', Mockery::type( 'array' ) );
		$this->stub_merchant_integrations( $this->http_response( 500, '{}' ) );

		$result = $this->make_endpoint()->seller_status();

		$this->assertSame( $fallback, $result );
		$this->assert_single_status_request();
		$this->assertTrue( $this->failure_registry->has_failure_in_timeframe( FailureRegistry::SELLER_STATUS_KEY, 60 ) );
		$this->assertEquals( $fallback, get_transient( self::STATUS_TRANSIENT ), 'The fallback is cached' );
	}

	/**
	 * Given a failure registered inside the back-off window and no fallback, seller_status() sends no request, adds no
	 * failure and throws a runtime exception.
	 *
	 * @testdox Should back off without a request when a recent failure is registered.
	 */
	public function test_seller_status_backs_off_when_recent_failure_registered(): void {
		$failed_at = time() - 100;
		$this->set_wallet_transient( self::FAILURE_TRANSIENT, $failed_at );

		$this->expectException( RuntimeException::class );
		try {
			$this->make_endpoint()->seller_status();
		} finally {
			$this->assertCount( 0, $this->http_requests );
			$this->assertSame( $failed_at, get_transient( self::FAILURE_TRANSIENT ), 'No additional failure is registered' );
			$this->assertFalse( get_transient( self::STATUS_TRANSIENT ) );
		}
	}

	/**
	 * Given a failure inside the back-off window and a fallback from the filter, seller_status() sends no request and
	 * returns the fallback and caches it.
	 *
	 * @testdox Should use the fallback while backing off.
	 */
	public function test_seller_status_backoff_uses_fallback_when_configured(): void {
		$failed_at = time() - 100;
		$this->set_wallet_transient( self::FAILURE_TRANSIENT, $failed_at );
		$fallback = $this->with_fallback();

		$result = $this->make_endpoint()->seller_status();

		$this->assertSame( $fallback, $result );
		$this->assertCount( 0, $this->http_requests );
		$this->assertSame( $failed_at, get_transient( self::FAILURE_TRANSIENT ), 'No additional failure is registered' );
		$this->assertEquals( $fallback, get_transient( self::STATUS_TRANSIENT ), 'The fallback is cached' );
	}

	/**
	 * A back-off equal to or shorter than the WP-Cron period (which defaults to roughly the cache TTL) would lapse
	 * before the next cron run, so a persistently failing merchant account would be polled on every run instead of
	 * being throttled.
	 *
	 * @testdox Should keep the failure back-off window strictly longer than the success cache TTL.
	 */
	public function test_failure_backoff_is_longer_than_success_cache_ttl(): void {
		$this->assertGreaterThan(
			PartnersEndpoint::SELLER_STATUS_CACHE_TTL,
			PartnersEndpoint::SELLER_STATUS_FAILURE_BACKOFF
		);
	}

	/**
	 * @testdox Should delete the cached seller status when the cache is cleared.
	 */
	public function test_clear_seller_status_cache_deletes_cache_entry(): void {
		$this->set_wallet_transient( self::STATUS_TRANSIENT, new SellerStatus( array(), array(), 'DE' ) );

		$this->make_endpoint()->clear_seller_status_cache();

		$this->assertFalse( get_transient( self::STATUS_TRANSIENT ) );
	}
}
