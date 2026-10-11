<?php
/**
 * Tests for the Pay with Crypto product status.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusCapability;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PWCProductStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionProperty;

/**
 * The merchant has Pay with Crypto when PayPal's seller status carries the CRYPTO_PYMTS capability as ACTIVE.
 *
 * @group paypal-wallet
 */
class PWCProductStatusTest extends WalletTestCase {

	/**
	 * Start without a seller status that an earlier test left on ProductStatus, which holds it in a static property.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->forget_the_cached_seller_status();
		delete_transient( 'woocommerce-ppcp-cache-product-status' );
	}

	/**
	 * Leave no seller status or cached answer behind.
	 */
	public function tearDown(): void {
		$this->forget_the_cached_seller_status();
		delete_transient( 'woocommerce-ppcp-cache-product-status' );

		parent::tearDown();
	}

	/**
	 * Clear the per-request seller status of ProductStatus.
	 */
	private function forget_the_cached_seller_status(): void {
		$property = new ReflectionProperty( ProductStatus::class, 'seller_status' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * A status over a partners endpoint that answers with the given capabilities.
	 *
	 * @param bool                     $is_connected Whether the merchant is connected.
	 * @param SellerStatusCapability[] $capabilities The capabilities of the seller status.
	 * @param int                      $asks         How often the endpoint is asked for the seller status.
	 * @return PWCProductStatus
	 */
	private function make_status( bool $is_connected, array $capabilities, int $asks = 1 ): PWCProductStatus {
		$partners_endpoint = $this->mock( PartnersEndpoint::class );
		$partners_endpoint->shouldReceive( 'seller_status' )->times( $asks )->andReturn( new SellerStatus( array(), $capabilities, 'US' ) );

		$failure_registry = $this->mock( FailureRegistry::class );
		$failure_registry->shouldReceive( 'has_failure_in_timeframe' )->andReturn( false );

		return new PWCProductStatus( $is_connected, $partners_endpoint, $failure_registry, new ProductStatusResultCache() );
	}

	/**
	 * @testdox Should name its cache key products_pwc_enabled and look for the CRYPTO_PYMTS capability.
	 */
	public function test_constants(): void {
		$this->assertSame( 'products_pwc_enabled', PWCProductStatus::KEY );
		$this->assertSame( 'CRYPTO_PYMTS', PWCProductStatus::CAPABILITY_NAME );
	}

	/**
	 * @testdox Should be active when the seller status carries the CRYPTO_PYMTS capability as ACTIVE.
	 */
	public function test_is_active_when_the_capability_is_active(): void {
		$status = $this->make_status(
			true,
			array(
				new SellerStatusCapability( 'PAYPAL_WALLET_VAULTING_ADVANCED', 'ACTIVE' ),
				new SellerStatusCapability( 'CRYPTO_PYMTS', 'ACTIVE' ),
			)
		);

		$this->assertTrue( $status->is_active() );
		$this->assertSame( 'yes', ( new ProductStatusResultCache() )->get( PWCProductStatus::KEY ), 'The answer is cached' );
	}

	/**
	 * @testdox Should not be active when the CRYPTO_PYMTS capability is not ACTIVE.
	 */
	public function test_is_not_active_when_the_capability_is_not_active(): void {
		$status = $this->make_status( true, array( new SellerStatusCapability( 'CRYPTO_PYMTS', 'DENIED' ) ) );

		$this->assertFalse( $status->is_active() );
		$this->assertSame( 'no', ( new ProductStatusResultCache() )->get( PWCProductStatus::KEY ), 'The answer is cached' );
	}

	/**
	 * @testdox Should not be active when only other capabilities are ACTIVE.
	 */
	public function test_is_not_active_without_the_capability(): void {
		$status = $this->make_status( true, array( new SellerStatusCapability( 'PAYPAL_WALLET_VAULTING_ADVANCED', 'ACTIVE' ) ) );

		$this->assertFalse( $status->is_active() );
	}

	/**
	 * @testdox Should not be active, and not ask PayPal, before the merchant is connected.
	 */
	public function test_is_not_active_before_onboarding(): void {
		$status = $this->make_status( false, array( new SellerStatusCapability( 'CRYPTO_PYMTS', 'ACTIVE' ) ), 0 );

		$this->assertFalse( $status->is_active() );
	}
}
