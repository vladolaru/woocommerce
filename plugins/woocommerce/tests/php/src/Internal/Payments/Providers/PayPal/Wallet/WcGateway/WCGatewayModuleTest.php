<?php
/**
 * Tests for the product status clearing that the gateway module registers.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\DccApplies;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\FeesRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ApmCapabilityStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PWCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The listener WCGatewayModule::run() registers on woocommerce_paypal_payments_clear_apm_product_status clears the
 * cached product statuses, including the APM capability that Pay Later messaging reads.
 *
 * @group paypal-wallet
 */
class WCGatewayModuleTest extends WalletTestCase {

	/**
	 * The result cache shared by the three statuses, as in the real container.
	 *
	 * @var ProductStatusResultCache
	 */
	private ProductStatusResultCache $status_cache;

	/**
	 * Register the module's listeners over a container that only knows the three product statuses.
	 *
	 * Registration is lazy: run() only adds hooks, and the closures ask the container when they fire.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_transient( 'woocommerce-ppcp-cache-product-status' );

		$this->status_cache = new ProductStatusResultCache();

		$services = array(
			'wcgateway.helper.dcc-product-status' => $this->make_status( DCCProductStatus::class ),
			'wcgateway.pwc-product-status'        => $this->make_status( PWCProductStatus::class ),
			'wcgateway.apm-capability-status'     => $this->make_status( ApmCapabilityStatus::class ),
			// run() reads the fees renderer eagerly, while registering the order totals hook.
			'wcgateway.admin.fees-renderer'       => $this->mock( FeesRenderer::class ),
		);

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->andReturnUsing(
			static function ( $id ) use ( $services ) {
				return isset( $services[ $id ] );
			}
		);
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( $id ) use ( $services ) {
				return $services[ $id ];
			}
		);

		( new WCGatewayModule() )->run( $container );
	}

	/**
	 * Remove the cached statuses the test stored.
	 */
	public function tearDown(): void {
		delete_transient( 'woocommerce-ppcp-cache-product-status' );

		parent::tearDown();
	}

	/**
	 * A product status over the shared result cache. The store is not connected, so the status never calls PayPal.
	 *
	 * @param string $class_name The status class (a ProductStatus subclass).
	 * @return ProductStatus
	 */
	private function make_status( string $class_name ): ProductStatus {
		$arguments = array(
			false,
			$this->mock( PartnersEndpoint::class ),
			$this->mock( FailureRegistry::class ),
			$this->status_cache,
		);

		if ( DCCProductStatus::class === $class_name ) {
			$arguments[] = $this->mock( DccApplies::class );
		}

		return new $class_name( ...$arguments );
	}

	/**
	 * The cached answer for a key, read through a fresh cache so that only what reached storage counts.
	 *
	 * @param string $key The result-cache key.
	 * @return string
	 */
	private function stored_answer( string $key ): string {
		return ( new ProductStatusResultCache() )->get( $key );
	}

	/**
	 * @testdox Should clear the cached APM capability together with the other product statuses (wallet).
	 */
	public function test_clear_hook_clears_the_cached_apm_capability(): void {
		$this->status_cache->set( ApmCapabilityStatus::KEY, 'yes' );
		$this->status_cache->set( DCCProductStatus::KEY, 'yes' );
		$this->status_cache->set( PWCProductStatus::KEY, 'yes' );
		$this->assertSame( 'yes', $this->stored_answer( ApmCapabilityStatus::KEY ), 'The APM capability answer should be stored before the hook fires' );

		do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );

		$this->assertSame( '', $this->stored_answer( ApmCapabilityStatus::KEY ), 'The APM capability answer should be gone' );
		$this->assertSame( '', $this->stored_answer( DCCProductStatus::KEY ), 'The DCC status should be gone' );
		$this->assertSame( '', $this->stored_answer( PWCProductStatus::KEY ), 'The PWC status should be gone' );
	}
}
