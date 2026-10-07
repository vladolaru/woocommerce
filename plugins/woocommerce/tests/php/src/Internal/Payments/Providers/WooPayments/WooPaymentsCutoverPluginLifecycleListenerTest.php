<?php
/**
 * WooPaymentsCutoverPluginLifecycleListenerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPluginLifecycleListener;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the plugin lifecycle hooks the cutover listens to on every request class.
 */
class WooPaymentsCutoverPluginLifecycleListenerTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var WooPaymentsCutoverPluginLifecycleListener
	 */
	private WooPaymentsCutoverPluginLifecycleListener $sut;

	/**
	 * Cutover controller double, resolved by the listener from the container.
	 *
	 * @var WooPaymentsCutoverController&MockObject
	 */
	private $controller;

	/**
	 * Set up the listener with a controller double in the container.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->controller = $this->createMock( WooPaymentsCutoverController::class );
		wc_get_container()->replace( WooPaymentsCutoverController::class, $this->controller );
		$this->sut = new WooPaymentsCutoverPluginLifecycleListener();
		$this->sut->register();
	}

	/**
	 * Remove the listener's hooks and the container replacement.
	 */
	public function tearDown(): void {
		remove_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) );
		remove_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ), 10 );
		remove_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ), 10 );
		wc_get_container()->reset_replacement( WooPaymentsCutoverController::class );
		parent::tearDown();
	}

	/**
	 * @testdox A WooPayments deactivation in any request reaches the controller, which queues the reconciliation.
	 */
	public function test_deactivation_reaches_the_controller(): void {
		$this->controller->expects( $this->once() )
			->method( 'handle_plugin_deactivated' )
			->with( NativePaymentsRuntimeArbiter::PLUGIN_FILE, false );

		do_action( 'deactivated_plugin', NativePaymentsRuntimeArbiter::PLUGIN_FILE, false );
	}

	/**
	 * @testdox A WooPayments activation in any request reaches the controller, which records a rollback.
	 */
	public function test_activation_reaches_the_controller(): void {
		$this->controller->expects( $this->once() )
			->method( 'handle_plugin_activated' )
			->with( NativePaymentsRuntimeArbiter::PLUGIN_FILE, true );

		do_action( 'activated_plugin', NativePaymentsRuntimeArbiter::PLUGIN_FILE, true );
	}

	/**
	 * @testdox The WooPayments activation hook reaches the controller's guard.
	 */
	public function test_activation_hook_reaches_the_guard(): void {
		$this->controller->expects( $this->once() )->method( 'guard_woopayments_activation' );

		do_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE );
	}

	/**
	 * @testdox The listener registers on WordPress's exact WooPayments activation hook and the two plugin lifecycle hooks.
	 */
	public function test_registers_the_lifecycle_hooks(): void {
		$this->assertSame( 10, has_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) ) );
		$this->assertSame( 10, has_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ) ) );
		$this->assertSame( 10, has_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ) ) );
	}
}
