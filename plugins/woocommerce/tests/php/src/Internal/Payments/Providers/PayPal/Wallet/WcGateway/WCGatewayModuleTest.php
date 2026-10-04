<?php
/**
 * Tests for the listeners and checks the gateway module registers: the product status clearing and the shopper session
 * check that keeps an order on PayPal.
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
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ApmCapabilityStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use ReflectionMethod;
use WC_Session_Handler;

/**
 * The listener WCGatewayModule::run() registers on woocommerce_paypal_payments_clear_apm_product_status clears the
 * cached product statuses, including the APM capability that Pay Later messaging reads. The module also decides, from
 * the shopper's session, whether an order that carries PayPal meta is still being paid with PayPal.
 *
 * The session cases use a real session handler. No case chooses the credit card gateway, which core no longer has: a
 * gateway ID with the "ppcp-" prefix covers the prefix rule.
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
			'wcgateway.apm-capability-status'     => $this->make_status( ApmCapabilityStatus::class ),
			// run() reads the fees renderer eagerly, while registering the order totals hook.
			'wcgateway.admin.fees-renderer'       => $this->mock( FeesRenderer::class ),
		);

		$container = $this->mock( ContainerInterface::class );
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
	 * A product status over the shared result cache. The first constructor argument is the connected flag: the store is
	 * not connected, so the status never calls PayPal, even when the DCC listener (priority 20) reads it after a clear.
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
	 * @testdox Should clear the cached APM capability together with the other product statuses.
	 */
	public function test_clear_hook_clears_the_cached_apm_capability(): void {
		$this->status_cache->set( ApmCapabilityStatus::KEY, 'yes' );
		$this->status_cache->set( DCCProductStatus::KEY, 'yes' );
		$this->assertSame( 'yes', $this->stored_answer( ApmCapabilityStatus::KEY ), 'The APM capability answer should be stored before the hook fires' );

		do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );

		$this->assertSame( '', $this->stored_answer( ApmCapabilityStatus::KEY ), 'The APM capability answer should be gone' );
		$this->assertSame( '', $this->stored_answer( DCCProductStatus::KEY ), 'The DCC status should be gone' );
	}

	/**
	 * @testdox Should leave a Pay with Crypto answer in the shared cache alone, as core no longer manages it.
	 */
	public function test_clear_hook_leaves_a_pay_with_crypto_answer_in_the_cache(): void {
		// The extension's cache key for Pay with Crypto: core has no class for it, so it stays a literal.
		$this->status_cache->set( 'products_pwc_enabled', 'yes' );
		$this->status_cache->set( DCCProductStatus::KEY, 'yes' );

		do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );

		$this->assertSame( '', $this->stored_answer( DCCProductStatus::KEY ), 'The DCC status should be gone' );
		$this->assertSame( 'yes', $this->stored_answer( 'products_pwc_enabled' ), 'An entry core does not own should stay' );
	}

	/**
	 * Give WooCommerce a session with the given chosen payment method.
	 *
	 * @param mixed $chosen_payment_method The value of the session's chosen_payment_method; null stores nothing, because
	 *                                     the session skips a write equal to its current value.
	 */
	private function use_session_with_chosen_payment_method( $chosen_payment_method ): void {
		$session = new WC_Session_Handler();
		$session->set( 'chosen_payment_method', $chosen_payment_method );
		$this->use_own_wc_session( $session );
	}

	/**
	 * Ask the module's private check whether the shopper is still paying with one of the wallet's gateways.
	 *
	 * @return bool
	 */
	private function invoke_shopper_is_paying_with_ppcp(): bool {
		$module = new WCGatewayModule();
		$method = new ReflectionMethod( $module, 'shopper_is_paying_with_ppcp' );
		$method->setAccessible( true );

		return $method->invoke( $module );
	}

	/**
	 * GIVEN the shopper's session still has a PayPal gateway id chosen
	 * WHEN shopper_is_paying_with_ppcp is called
	 * THEN it returns true
	 *
	 * @testdox Should report that the shopper pays with PayPal when the session holds a "ppcp-" gateway ID.
	 *
	 * @dataProvider data_ppcp_chosen_payment_method
	 *
	 * @param string $chosen_payment_method The chosen payment method.
	 */
	public function test_returns_true_for_ppcp_chosen_payment_method( string $chosen_payment_method ): void {
		$this->use_session_with_chosen_payment_method( $chosen_payment_method );

		$this->assertTrue( $this->invoke_shopper_is_paying_with_ppcp() );
	}

	/**
	 * Gateway IDs the wallet owns.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_ppcp_chosen_payment_method(): array {
		return array(
			'main PayPal gateway'              => array( PayPalGateway::ID ),
			'another gateway ID of the prefix' => array( 'ppcp-other-gateway' ),
		);
	}

	/**
	 * GIVEN the shopper's session says they chose Direct Bank Transfer after a failed PayPal attempt
	 * WHEN shopper_is_paying_with_ppcp is called
	 * THEN it returns false, so the order's payment method is not forced back to PayPal
	 *
	 * @testdox Should report that the shopper does not pay with PayPal when they chose another gateway after a failed attempt.
	 */
	public function test_returns_false_when_shopper_chose_non_ppcp_gateway(): void {
		$this->use_session_with_chosen_payment_method( 'bacs' );

		$this->assertFalse( $this->invoke_shopper_is_paying_with_ppcp() );
	}

	/**
	 * GIVEN the session holds a value that is not a chosen payment method string
	 * WHEN shopper_is_paying_with_ppcp is called
	 * THEN it returns false
	 *
	 * @testdox Should report that the shopper does not pay with PayPal when the session value is not a gateway ID.
	 *
	 * @dataProvider data_non_ppcp_session_value
	 *
	 * @param mixed $chosen_payment_method The session value.
	 */
	public function test_returns_false_for_non_ppcp_session_value( $chosen_payment_method ): void {
		$this->use_session_with_chosen_payment_method( $chosen_payment_method );

		$this->assertFalse( $this->invoke_shopper_is_paying_with_ppcp() );
	}

	/**
	 * Session values that are not a chosen gateway ID.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_non_ppcp_session_value(): array {
		return array(
			'empty string'     => array( '' ),
			'non-string array' => array( array( 'unexpected' ) ),
			'no value stored'  => array( null ),
		);
	}

	/**
	 * GIVEN there is no shopper session, for example on the admin order screen, in cron or in a webhook
	 * WHEN shopper_is_paying_with_ppcp is called
	 * THEN it returns false without touching the session
	 *
	 * @testdox Should report that the shopper does not pay with PayPal when there is no shopper session.
	 */
	public function test_returns_false_when_wc_session_is_missing(): void {
		// use_own_wc_session() registers the restore of the original session; the session itself is then removed.
		$this->use_own_wc_session();
		WC()->session = null;

		$this->assertFalse( $this->invoke_shopper_is_paying_with_ppcp() );
	}
}
