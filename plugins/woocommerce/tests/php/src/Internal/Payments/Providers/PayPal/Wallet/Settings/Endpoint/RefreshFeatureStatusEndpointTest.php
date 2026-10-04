<?php
/**
 * Tests for the refresh feature status endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerStatusFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\RefreshFeatureStatusEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\SellerTypeEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WP_REST_Request;

/**
 * What the refresh button does: at most once a minute it drops the cached seller status and the cached API tokens and
 * asks the seller type resolver to look the seller type up again. The throttle is a real transient.
 *
 * @group paypal-wallet
 */
class RefreshFeatureStatusEndpointTest extends WalletTestCase {

	private const CACHE_PREFIX      = 'ppcp-test-refresh-';
	private const THROTTLE          = self::CACHE_PREFIX . 'refresh_feature_status_timeout';
	private const STATUS_TRANSIENT  = 'ppcp-test-seller-' . PartnersEndpoint::SELLER_STATUS_CACHE_KEY;
	private const FAILURE_TRANSIENT = 'ppcp-test-failure-' . FailureRegistry::CACHE_KEY . '_' . FailureRegistry::SELLER_STATUS_KEY;
	private const COMMON_OPTION     = 'woocommerce-ppcp-data-common';
	private const CLEAR_STATUS_HOOK = 'woocommerce_paypal_payments_clear_apm_product_status';
	private const FLUSH_CACHE_HOOK  = 'woocommerce_paypal_payments_flush_api_cache';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockInterface
	 */
	private $logger;

	/**
	 * Start with no throttle, and have the test base delete the transients the code under test writes.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( self::THROTTLE, self::STATUS_TRANSIENT, self::FAILURE_TRANSIENT ) as $name ) {
			$this->set_wallet_transient( $name, '' );
			delete_transient( $name );
		}

		$this->logger = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
	}

	/**
	 * Build the endpoint over the given resolver.
	 *
	 * @param SellerTypeResolver $resolver          The seller type resolver.
	 * @param GeneralSettings    $general_settings  The general settings.
	 * @param PartnersEndpoint   $partners_endpoint The partners endpoint.
	 * @return RefreshFeatureStatusEndpoint
	 */
	private function make_endpoint( SellerTypeResolver $resolver, GeneralSettings $general_settings, PartnersEndpoint $partners_endpoint ): RefreshFeatureStatusEndpoint {
		return new RefreshFeatureStatusEndpoint(
			new Cache( self::CACHE_PREFIX ),
			$this->logger,
			$resolver,
			$general_settings,
			$partners_endpoint
		);
	}

	/**
	 * A resolver mock, so a case can state whether the lookup is asked for.
	 *
	 * @return SellerTypeResolver|MockInterface
	 */
	private function mock_resolver() {
		return $this->mock( SellerTypeResolver::class );
	}

	/**
	 * Post the refresh request to the handler.
	 *
	 * @param RefreshFeatureStatusEndpoint $endpoint The endpoint.
	 * @return array The response data.
	 */
	private function refresh( RefreshFeatureStatusEndpoint $endpoint ): array {
		return $endpoint->refresh_status( new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/refresh-features' ) )->get_data();
	}

	/**
	 * Given no request in the last minute, the refresh clears both caches, asks the resolver to look up the seller type
	 * once and starts the throttle.
	 *
	 * @testdox Should clear the caches and trigger the seller type resolution when no refresh ran in the last minute.
	 */
	public function test_refresh_triggers_seller_type_resolution(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array() );
		$general_settings  = new GeneralSettings( 'US', 'USD', false );
		$partners_endpoint = $this->mock( PartnersEndpoint::class );
		$cleared_status    = $this->spy_filter( self::CLEAR_STATUS_HOOK );
		$flushed_cache     = $this->spy_filter( self::FLUSH_CACHE_HOOK );
		$resolver          = $this->mock_resolver();
		$resolver->shouldReceive( 'resolve_unknown_seller_type' )
			->once()
			->with( $general_settings, $partners_endpoint, $this->logger );

		$before = time();
		$data   = $this->refresh( $this->make_endpoint( $resolver, $general_settings, $partners_endpoint ) );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Feature status refreshed successfully.', $data['data']['message'] );
		$this->assertCount( 1, $cleared_status, 'The seller status cache and failure registry should be cleared' );
		$this->assertCount( 1, $flushed_cache, 'The API caches should be flushed so a new token carries the current scopes' );
		$this->assertGreaterThanOrEqual( $before, get_transient( self::THROTTLE ), 'The throttle should start' );
	}

	/**
	 * Given a refresh in the last minute, the request is refused with the seconds left, no cache is cleared and the
	 * resolver is not asked, so no PayPal call is made.
	 *
	 * @testdox Should refuse the refresh and skip the resolution while the throttle is running.
	 */
	public function test_refresh_throttle_blocks_resolution(): void {
		$this->set_wallet_option( self::COMMON_OPTION, array() );
		$general_settings = new GeneralSettings( 'US', 'USD', false );
		$just_now         = time();
		$this->set_wallet_transient( self::THROTTLE, $just_now, 60 );
		$cleared_status = $this->spy_filter( self::CLEAR_STATUS_HOOK );
		$flushed_cache  = $this->spy_filter( self::FLUSH_CACHE_HOOK );
		$resolver       = $this->mock_resolver();
		$resolver->shouldNotReceive( 'resolve_unknown_seller_type' );

		$data = $this->refresh( $this->make_endpoint( $resolver, $general_settings, $this->mock( PartnersEndpoint::class ) ) );

		$this->assertFalse( $data['success'] );
		$this->assertMatchesRegularExpression( '/^Wait \d+ seconds before trying again\.$/', $data['message'] );
		$this->assertCount( 0, $cleared_status );
		$this->assertCount( 0, $flushed_cache );
		$this->assertSame( $just_now, get_transient( self::THROTTLE ), 'A refused request must not restart the throttle' );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * With the real resolver, settings and partners endpoint over a stubbed PayPal, a merchant who is connected with an
	 * unknown seller type is looked up again and the resolved business type is stored.
	 *
	 * @testdox Should store the seller type PayPal reports for a connected merchant whose seller type is unknown.
	 */
	public function test_refresh_stores_the_resolved_seller_type(): void {
		$this->set_wallet_option(
			self::COMMON_OPTION,
			array(
				'merchant_id'    => 'MERCHANT123',
				'merchant_email' => 'merchant@example.com',
				'client_id'      => 'client-id-value',
				'client_secret'  => 'client-secret-value',
				'seller_type'    => SellerTypeEnum::UNKNOWN,
			)
		);
		$this->stub_http(
			$this->http_response(
				200,
				(string) wp_json_encode(
					array(
						'country'      => 'DE',
						'products'     => array(),
						'capabilities' => array(
							array(
								'name'   => 'COMMERCIAL_ENTITY',
								'status' => 'ACTIVE',
							),
						),
					)
				)
			)
		);
		$general_settings  = new GeneralSettings( 'US', 'USD', false );
		$partners_endpoint = new PartnersEndpoint(
			'https://api.sandbox.paypal.com/',
			$this->make_bearer( false ),
			$this->logger,
			new SellerStatusFactory(),
			'partner-abc',
			'MERCHANT123',
			new FailureRegistry( new Cache( 'ppcp-test-failure-' ) ),
			new Cache( 'ppcp-test-seller-' )
		);

		$data = $this->refresh( $this->make_endpoint( new SellerTypeResolver(), $general_settings, $partners_endpoint ) );

		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $this->http_requests, 'The seller status should be requested once' );
		$stored = get_option( self::COMMON_OPTION );
		$this->assertSame( SellerTypeEnum::BUSINESS, $stored['seller_type'] );
		$this->assertSame( 'DE', $stored['merchant_country'], 'An empty merchant country is filled from the seller status' );
	}
}
