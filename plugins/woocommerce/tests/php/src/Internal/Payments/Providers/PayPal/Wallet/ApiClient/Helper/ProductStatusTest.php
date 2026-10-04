<?php
/**
 * Tests for the product status helper.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The base of the seller product statuses: not active before onboarding, the cached answer first, then the PayPal
 * answer once, kept in memory. The PayPal check is a stub that answers what the test says. Most cases run over a
 * mocked result cache; the last two use the real cache, and so the real transient it writes.
 *
 * The extension's case for the APM capability clear is not repeated here: WCGatewayModuleTest pins that hook.
 *
 * @group paypal-wallet
 */
class ProductStatusTest extends WalletTestCase {

	private const TRANSIENT = 'woocommerce-ppcp-cache-product-status';

	/**
	 * The partners endpoint mock.
	 *
	 * @var PartnersEndpoint&MockInterface
	 */
	private $partners_endpoint;

	/**
	 * The failure registry mock.
	 *
	 * @var FailureRegistry&MockInterface
	 */
	private $api_failure_registry;

	/**
	 * The result cache mock.
	 *
	 * @var ProductStatusResultCache&MockInterface
	 */
	private $result_cache;

	/**
	 * Build the collaborators as mocks.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->partners_endpoint    = $this->mock( PartnersEndpoint::class );
		$this->api_failure_registry = $this->mock( FailureRegistry::class );
		$this->result_cache         = $this->mock( ProductStatusResultCache::class );
	}

	/**
	 * A product status whose PayPal check answers what its "api_result" property says and asks for no seller status.
	 *
	 * @param bool                     $is_connected Whether the merchant is connected.
	 * @param ProductStatusResultCache $result_cache The result cache, the mock when none is given.
	 * @return ProductStatus
	 */
	private function create_test_product_status( bool $is_connected = true, ?ProductStatusResultCache $result_cache = null ): ProductStatus {
		return new class( $is_connected, $this->partners_endpoint, $this->api_failure_registry, $result_cache ?? $this->result_cache, $this->mock( SellerStatus::class ) ) extends ProductStatus {

			public const KEY = 'test_product';

			/**
			 * What the sample PayPal check answers. The tests set it.
			 *
			 * @var bool
			 */
			public bool $api_result = false;

			/**
			 * The seller status handed to the check.
			 *
			 * @var SellerStatus
			 */
			private SellerStatus $dummy_seller_status;

			/**
			 * Build the status over the collaborators and the dummy seller status.
			 *
			 * @param bool                     $is_connected         Whether the merchant is connected.
			 * @param PartnersEndpoint         $partners_endpoint    The partners endpoint.
			 * @param FailureRegistry          $api_failure_registry The failure registry.
			 * @param ProductStatusResultCache $result_cache         The result cache.
			 * @param SellerStatus             $seller_status        The dummy seller status.
			 */
			public function __construct( bool $is_connected, PartnersEndpoint $partners_endpoint, FailureRegistry $api_failure_registry, ProductStatusResultCache $result_cache, SellerStatus $seller_status ) {
				parent::__construct( $is_connected, $partners_endpoint, $api_failure_registry, $result_cache );
				$this->dummy_seller_status = $seller_status;
			}

			/**
			 * The sample PayPal check.
			 *
			 * @param SellerStatus $seller_status The seller status.
			 * @return bool
			 */
			protected function check_api_response( SellerStatus $seller_status ): bool {
				return $this->api_result;
			}

			/**
			 * A seller status without asking PayPal.
			 *
			 * @return SellerStatus
			 */
			protected function get_seller_status_object(): SellerStatus {
				return $this->dummy_seller_status;
			}
		};
	}

	/**
	 * @testdox Should not be active before the merchant is onboarded, whatever the cache says.
	 */
	public function test_is_active_returns_false_when_not_onboarded(): void {
		$testee = $this->create_test_product_status( false );

		// The cache must not be asked before onboarding.
		$this->result_cache->shouldReceive( 'get' )->never();

		$this->assertFalse( $testee->is_active() );
	}

	/**
	 * @testdox Should use the cached answer and not call the PayPal check.
	 */
	public function test_local_state_skips_api_check(): void {
		// The cache returns "yes", so check_local_state() returns true.
		$this->result_cache->shouldReceive( 'get' )->with( 'test_product' )->andReturn( 'yes' );

		// PartnersEndpoint should never be called when local state is available.
		$this->partners_endpoint->shouldNotReceive( 'seller_status' );

		$testee = $this->create_test_product_status();

		$this->assertTrue( $testee->is_active() );
	}

	/**
	 * What the cache holds and the local state it makes.
	 *
	 * @return array<string, array{cache_value: string, expected_result: bool|null}>
	 */
	public function data_check_local_state(): array {
		return array(
			'cache_has_yes'  => array(
				'cache_value'     => 'yes',
				'expected_result' => true,
			),
			'cache_has_no'   => array(
				'cache_value'     => 'no',
				'expected_result' => false,
			),
			'cache_is_empty' => array(
				'cache_value'     => '',
				'expected_result' => null,
			),
		);
	}

	/**
	 * @testdox Should turn the cached value into true, false or no local state.
	 * @dataProvider data_check_local_state
	 *
	 * @param string    $cache_value     What the cache holds.
	 * @param bool|null $expected_result The local state.
	 */
	public function test_check_local_state_returns_expected_value( string $cache_value, ?bool $expected_result ): void {
		$this->result_cache->shouldReceive( 'get' )->andReturn( $cache_value );

		$testee = $this->create_test_product_status();

		$this->assertSame( $expected_result, $testee->check_local_state() );
	}

	/**
	 * What the PayPal check answers.
	 *
	 * @return array<string, array{api_response: bool}>
	 */
	public function data_verify_api_result_cache(): array {
		return array(
			'api_returns_true'  => array( 'api_response' => true ),
			'api_returns_false' => array( 'api_response' => false ),
		);
	}

	/**
	 * @testdox Should ask PayPal once and then answer from memory.
	 * @dataProvider data_verify_api_result_cache
	 *
	 * @param bool $api_response What the PayPal check answers.
	 */
	public function test_is_active_calls_api_once_then_caches_in_memory( bool $api_response ): void {
		$this->result_cache->shouldReceive( 'get' )->andReturn( '' );
		$this->result_cache->shouldReceive( 'set' )->once();

		$testee             = $this->create_test_product_status();
		$testee->api_result = $api_response;

		// First call: triggers the check and keeps the result in memory.
		$this->assertSame( $api_response, $testee->is_active(), 'The API result was not used' );

		// Change the answer. It must not matter for the next call, which comes from memory.
		$testee->api_result = ! $api_response;

		// Second call: served from memory, the check is not run again.
		$this->assertSame( $api_response, $testee->is_active(), 'The cached result should be returned, but the API was queried again' );
	}

	/**
	 * Added for the real transient: with the real cache, the answer of PayPal is stored and the next status reads it.
	 *
	 * @testdox Should store the PayPal answer in the transient, where another status object finds it without asking PayPal.
	 */
	public function test_is_active_stores_the_api_result_in_the_transient(): void {
		$this->set_wallet_transient( self::TRANSIENT, array() );
		$cache = new ProductStatusResultCache();

		$first             = $this->create_test_product_status( true, $cache );
		$first->api_result = true;
		$this->assertTrue( $first->is_active() );

		$this->assertSame( 'yes', ( new ProductStatusResultCache() )->get( 'test_product' ), 'The answer should be in the transient' );

		$second             = $this->create_test_product_status( true, new ProductStatusResultCache() );
		$second->api_result = false;
		$this->assertTrue( $second->is_active(), 'The stored answer should win over what PayPal would say now' );
	}

	/**
	 * Added for the real transient: clearing a status removes its answer from the transient, so PayPal is asked again.
	 *
	 * @testdox Should remove its answer from the transient when cleared, and ask PayPal again.
	 */
	public function test_clear_removes_the_stored_answer_from_the_transient(): void {
		$this->set_wallet_transient( self::TRANSIENT, array() );

		$testee             = $this->create_test_product_status( true, new ProductStatusResultCache() );
		$testee->api_result = true;
		$this->assertTrue( $testee->is_active() );

		$testee->clear();
		$this->assertSame( '', ( new ProductStatusResultCache() )->get( 'test_product' ), 'The answer should be gone from the transient' );

		$testee->api_result = false;
		$this->assertFalse( $testee->is_active(), 'PayPal should be asked again after a clear' );
	}
}
