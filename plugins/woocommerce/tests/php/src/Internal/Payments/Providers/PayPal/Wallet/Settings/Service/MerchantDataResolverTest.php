<?php
/**
 * Tests for the merchant data resolver.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PartnersEndpointFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerStatusFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\MerchantDataResolver;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;

/**
 * The merchant's PayPal country: read from the seller status over a stubbed PayPal, stored in the shared settings
 * option, and retried through Action Scheduler (a real, pending action) up to three times when PayPal does not answer.
 *
 * @group paypal-wallet
 */
class MerchantDataResolverTest extends WalletTestCase {

	private const COMMON_OPTION     = 'woocommerce-ppcp-data-common';
	private const STATUS_URL        = 'https://api.sandbox.paypal.com/v1/customer/partners/partner-abc/merchant-integrations/MID';
	private const STATUS_TRANSIENT  = 'ppcp-test-seller-' . PartnersEndpoint::SELLER_STATUS_CACHE_KEY;
	private const FAILURE_TRANSIENT = 'ppcp-test-failure-' . FailureRegistry::CACHE_KEY . '_' . FailureRegistry::SELLER_STATUS_KEY;

	/**
	 * The logger of the resolver.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * Start with no pending retry and no cached seller status, and have the test base delete the transients the
	 * partners endpoint writes.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( self::STATUS_TRANSIENT, self::FAILURE_TRANSIENT ) as $name ) {
			$this->set_wallet_transient( $name, '' );
			delete_transient( $name );
		}
		as_unschedule_all_actions( MerchantDataResolver::RETRY_HOOK );

		$this->logger = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
	}

	/**
	 * Remove the retries the test scheduled.
	 */
	public function tearDown(): void {
		try {
			as_unschedule_all_actions( MerchantDataResolver::RETRY_HOOK );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Store a merchant that is connected, with the given country.
	 *
	 * @param string $country The merchant country PayPal reported earlier, or empty when it did not.
	 * @return GeneralSettings
	 */
	private function connected_settings( string $country ): GeneralSettings {
		$this->set_wallet_option(
			self::COMMON_OPTION,
			array(
				'merchant_id'      => 'MID',
				'merchant_email'   => 'merchant@example.com',
				'client_id'        => 'cid',
				'client_secret'    => 'secret',
				'merchant_country' => $country,
			)
		);

		return new GeneralSettings( 'US', 'USD', false );
	}

	/**
	 * A resolver whose factory hands out a real partners endpoint, over the stubbed PayPal.
	 *
	 * @param GeneralSettings $settings The general settings.
	 * @return MerchantDataResolver
	 */
	private function create_resolver( GeneralSettings $settings ): MerchantDataResolver {
		$endpoint = new PartnersEndpoint(
			'https://api.sandbox.paypal.com/',
			$this->make_bearer( false ),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			new SellerStatusFactory(),
			'partner-abc',
			'MID',
			new FailureRegistry( new Cache( 'ppcp-test-failure-' ) ),
			new Cache( 'ppcp-test-seller-' )
		);
		$factory  = $this->mock( PartnersEndpointFactory::class );
		$factory->shouldReceive( 'create' )->andReturn( $endpoint );

		return new MerchantDataResolver( $settings, $factory, $this->logger );
	}

	/**
	 * Answer the seller status request with the given country, or with the given status code and no country.
	 *
	 * @param string $country     The country in the answer.
	 * @param int    $status_code The HTTP status of the answer.
	 */
	private function stub_seller_status( string $country, int $status_code = 200 ): void {
		$body = 200 === $status_code
			? array(
				'country'      => $country,
				'products'     => array(),
				'capabilities' => array(),
			)
			: array( 'name' => 'NOT_AUTHORIZED' );

		$this->stub_http(
			$this->http_response( $status_code, (string) wp_json_encode( $body ) )
		);
	}

	/**
	 * The country in the stored shared settings.
	 *
	 * @return string
	 */
	private function stored_country(): string {
		$stored = get_option( self::COMMON_OPTION );
		$this->assertIsArray( $stored );

		return (string) $stored['merchant_country'];
	}

	/**
	 * The time of the pending retry for the given attempt, or false when none is pending.
	 *
	 * @param int $attempt The attempt number.
	 * @return int|bool
	 */
	private function pending_retry( int $attempt ) {
		return as_next_scheduled_action( MerchantDataResolver::RETRY_HOOK, array( 'attempt' => $attempt ) );
	}

	/**
	 * @testdox Should not need a resolution when the merchant is not connected.
	 */
	public function test_needs_resolution_is_false_when_merchant_is_not_connected(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array() );

		$this->assertFalse( $this->create_resolver( new GeneralSettings( 'US', 'USD', false ) )->needs_resolution() );
	}

	/**
	 * @testdox Should not need a resolution when the country of the connected merchant is already known.
	 */
	public function test_needs_resolution_is_false_when_country_already_known(): void {
		$this->assertFalse( $this->create_resolver( $this->connected_settings( 'DE' ) )->needs_resolution() );
	}

	/**
	 * @testdox Should need a resolution when the connected merchant has no country yet.
	 */
	public function test_needs_resolution_is_true_when_country_is_empty(): void {
		$this->assertTrue( $this->create_resolver( $this->connected_settings( '' ) )->needs_resolution() );
	}

	/**
	 * Given a merchant that is not connected, ensure_country_resolved() skips the API call, stores nothing and
	 * schedules no retry.
	 *
	 * @testdox Should do nothing for a merchant who is not connected.
	 */
	public function test_ensure_country_resolved_does_nothing_when_merchant_is_not_connected(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array() );
		$resolver = $this->create_resolver( new GeneralSettings( 'US', 'USD', false ) );
		$writes   = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$resolver->ensure_country_resolved();

		$this->assertCount( 0, $this->http_requests );
		$this->assertCount( 0, $writes, 'Nothing should be stored' );
		$this->assertFalse( $this->pending_retry( 1 ) );
	}

	/**
	 * Given a connected merchant with no country, when the seller status returns one, it is stored in the shared
	 * settings and no retry is scheduled.
	 *
	 * @testdox Should store the country the seller status returns and schedule no retry.
	 */
	public function test_ensure_country_resolved_persists_country_on_success(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( 'DE' );

		$resolver->ensure_country_resolved();

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( self::STATUS_URL, $this->http_requests[0]['url'] );
		$this->assertSame( 'DE', $this->stored_country() );
		$this->assertFalse( $this->pending_retry( 1 ) );
	}

	/**
	 * @testdox Should store nothing and schedule the first retry when the seller status has no country.
	 */
	public function test_ensure_country_resolved_schedules_retry_when_country_is_empty(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( '' );
		$writes = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$resolver->ensure_country_resolved();

		$this->assertCount( 0, $writes );
		$this->assertSame( '', $this->stored_country() );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, $this->pending_retry( 1 ), 5, 'The first retry should run in 15 minutes' );
	}

	/**
	 * Given a connected merchant with no country, when the seller status call fails, the failure is logged, nothing is
	 * stored and the first retry is scheduled.
	 *
	 * @testdox Should log the failure, store nothing and schedule the first retry when the seller status call fails.
	 */
	public function test_ensure_country_resolved_schedules_retry_when_api_call_throws(): void {
		$this->logger = $this->mock( LoggerInterface::class );
		$this->logger->shouldReceive( 'warning' )
			->once()
			->with( Mockery::pattern( '/Could not resolve merchant country/' ) );
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( '', 403 );
		$writes = $this->spy_filter( 'pre_update_option_' . self::COMMON_OPTION );

		$resolver->ensure_country_resolved();

		$this->assertCount( 0, $writes );
		$this->assertSame( '', $this->stored_country() );
		$this->assertNotFalse( $this->pending_retry( 1 ) );
	}

	/**
	 * @testdox Should skip the API call on a retry when the merchant is not connected.
	 */
	public function test_handle_retry_does_nothing_when_merchant_is_not_connected(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array() );
		$resolver = $this->create_resolver( new GeneralSettings( 'US', 'USD', false ) );

		$resolver->handle_retry( 2 );

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should store the country and schedule no further retry when a scheduled retry resolves it.
	 */
	public function test_handle_retry_persists_country_on_success(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( 'DE' );

		$resolver->handle_retry( 2 );

		$this->assertSame( 'DE', $this->stored_country() );
		$this->assertFalse( $this->pending_retry( 3 ) );
	}

	/**
	 * @testdox Should schedule the next attempt when the retry of the second attempt fails.
	 */
	public function test_handle_retry_schedules_next_attempt_on_failure(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( '' );

		$resolver->handle_retry( 2 );

		$this->assertEqualsWithDelta( time() + 3 * 15 * MINUTE_IN_SECONDS, $this->pending_retry( 3 ), 5, 'The delay grows by 15 minutes per attempt' );
	}

	/**
	 * Given a retry at the last allowed attempt that still fails, no further retry is scheduled, so the merchant is
	 * not retried forever.
	 *
	 * @testdox Should not schedule a retry beyond the maximum number of attempts.
	 */
	public function test_handle_retry_does_not_reschedule_beyond_max_attempts(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( '' );

		$resolver->handle_retry( 3 );

		$this->assertFalse( $this->pending_retry( 4 ) );
		$this->assertCount( 1, $this->http_requests, 'The last attempt still asks PayPal' );
	}

	/**
	 * Given a retry for the same attempt that is already pending in Action Scheduler, a second failed resolution does
	 * not schedule a duplicate.
	 *
	 * @testdox Should not schedule a duplicate when the retry of that attempt is already pending.
	 */
	public function test_ensure_country_resolved_does_not_duplicate_pending_retry(): void {
		$resolver = $this->create_resolver( $this->connected_settings( '' ) );
		$this->stub_seller_status( '' );

		$resolver->ensure_country_resolved();
		delete_transient( self::STATUS_TRANSIENT );
		$resolver->ensure_country_resolved();

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => MerchantDataResolver::RETRY_HOOK,
				'args'   => array( 'attempt' => 1 ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $pending, 'Both resolutions failed, so only one retry may be pending' );
		$this->assertCount( 2, $this->http_requests );
	}
}
