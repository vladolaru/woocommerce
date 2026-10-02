<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\LateLoadedSubscriptions;
use WC_Unit_Test_Case;

/**
 * Load condition of the Stripe Billing module (client 11.1.0 `includes/class-wc-payments-features.php:306-318`).
 *
 * Cases that need WooCommerce Subscriptions run in a separate process, since its class cannot be unloaded.
 */
class WooPaymentsStripeBillingModuleTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should not load without WooCommerce Subscriptions, even with the toggle on.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_load_without_subscriptions(): void {
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( true );

		$this->assertFalse( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * @testdox Should load with WooCommerce Subscriptions while the toggle is off, without enabling Stripe Billing.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loads_with_subscriptions_while_the_toggle_is_off(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );

		$sut = $this->register_module( true );

		$this->assertTrue( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * @testdox Should enable Stripe Billing when WooCommerce Subscriptions is active and the toggle is on.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enables_stripe_billing_with_subscriptions_and_the_toggle_on(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( true );

		$this->assertTrue( $sut->is_loaded() );
		$this->assertTrue( $sut->is_stripe_billing_enabled() );

		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );
		$this->assertFalse( $sut->is_stripe_billing_enabled(), 'Turning the toggle off must stop new Stripe Billing subscriptions in the same request.' );
	}

	/**
	 * @testdox Should not load while the WooPayments plugin owns payments.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_load_while_the_plugin_owns_payments(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( false );

		$this->assertFalse( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * Build and register the module with the given ownership decision.
	 *
	 * @param bool $native_owns Whether native owns payments.
	 * @return WooPaymentsStripeBillingModule
	 */
	private function register_module( bool $native_owns ): WooPaymentsStripeBillingModule {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );

		$sut = new WooPaymentsStripeBillingModule();
		$sut->init( $arbiter );
		$sut->register();

		return $sut;
	}

	/**
	 * Load a WooCommerce Subscriptions stand-in.
	 */
	private function load_subscriptions(): void {
		require_once __DIR__ . '/../Fixtures/LateLoadedSubscriptions.php';
		class_alias( LateLoadedSubscriptions::class, 'WC_Subscriptions' );
	}
}
