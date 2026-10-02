<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the PayPalWalletBootstrap class.
 */
class PayPalWalletBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalWalletBootstrap
	 */
	private $sut;

	/**
	 * Build the SUT with an arbiter stub that answers as instructed.
	 *
	 * @param bool $native_owns Whether the arbiter should say native owns the site.
	 */
	private function build_sut( bool $native_owns ): void {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );

		$this->sut = new PayPalWalletBootstrap();
		$this->sut->init( $arbiter );
	}

	/**
	 * @testdox Should hook plugins_loaded at priority 10 when registered.
	 */
	public function test_register_hooks_plugins_loaded(): void {
		$this->build_sut( false );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'plugins_loaded', array( $this->sut, 'maybe_boot' ) ) );
	}

	/**
	 * @testdox Should not boot and not add trimming filters when the extension owns the site.
	 */
	public function test_does_not_boot_when_extension_owns(): void {
		$this->build_sut( false );
		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules' ), 'No trimming filter must be added while dormant' );
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.applepay_enabled' ) );
	}

	/**
	 * @testdox Should drop the non-wallet modules and keep the wallet ones when filtering the module list.
	 */
	public function test_module_filter_drops_only_non_wallet_modules(): void {
		$this->build_sut( true );
		$kept    = new \stdClass();
		$dropped = $this->getMockBuilder( \stdClass::class )->setMockClassName( 'WooCommerce_PayPalCommerce_OrderTracking_OrderTrackingModule_Mock' )->getMock();

		$modules = array( $kept, $dropped );
		$result  = $this->sut->filter_modules( $modules, array( get_class( $dropped ) ) );

		$this->assertSame( array( $kept ), $result );
	}

	/**
	 * Booting sets PPCP container process state, so this test is tagged to allow isolated runs.
	 *
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot the vendored container once when native owns the site, registering the PayPal gateway.
	 */
	public function test_boots_vendored_extension_when_native_owns(): void {
		if ( ! file_exists( PayPalWalletBootstrap::VENDORED_DIR . '/vendor/autoload.php' ) ) {
			$this->markTestSkipped( 'Vendored extension is not present.' );
		}
		$this->build_sut( true );

		$this->sut->maybe_boot();
		$this->sut->maybe_boot(); // Second call must be a no-op.

		$this->assertTrue( $this->sut->is_booted() );
		$this->assertTrue( class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) );
		$container = \WooCommerce\PayPalCommerce\PPCP::container();
		$this->assertTrue( $container->has( 'wcgateway.paypal-gateway' ), 'The vendored gateway service must exist' );
		$this->assertTrue( $container->has( 'axo.eligibility.check' ), 'Stubbed services must be registered' );
		$this->assertFalse( $container->get( 'axo.eligibility.check' )(), 'Stubbed eligibility must be false' );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_modules', array( $this->sut, 'filter_modules' ) ), 'The module filter must be in place' );
		$this->assertSame( 10, has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.applepay_enabled', '__return_false' ), 'Feature flags must be forced off' );
	}
}
