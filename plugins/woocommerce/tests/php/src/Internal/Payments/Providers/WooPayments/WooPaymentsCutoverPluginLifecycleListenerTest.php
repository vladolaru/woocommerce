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
		remove_action( 'activate_plugin', array( $this->sut, 'guard_woopayments_activation' ) );
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
	 * @testdox A network-wide WooPayments deactivation reaches the controller as network-wide.
	 */
	public function test_network_deactivation_reaches_the_controller_as_network_wide(): void {
		$this->controller->expects( $this->once() )
			->method( 'handle_plugin_deactivated' )
			->with( NativePaymentsRuntimeArbiter::PLUGIN_FILE, true );

		do_action( 'deactivated_plugin', NativePaymentsRuntimeArbiter::PLUGIN_FILE, true );
	}

	/**
	 * @testdox A caller firing the lifecycle hooks with only the plugin path reaches the controller as a site-only change.
	 */
	public function test_one_argument_lifecycle_hooks_reach_the_controller_as_site_only(): void {
		$this->controller->expects( $this->once() )
			->method( 'handle_plugin_activated' )
			->with( NativePaymentsRuntimeArbiter::PLUGIN_FILE, false );
		$this->controller->expects( $this->once() )
			->method( 'handle_plugin_deactivated' )
			->with( NativePaymentsRuntimeArbiter::PLUGIN_FILE, false );

		do_action( 'activated_plugin', NativePaymentsRuntimeArbiter::PLUGIN_FILE ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fires the core hook with one argument, as some callers do.
		do_action( 'deactivated_plugin', NativePaymentsRuntimeArbiter::PLUGIN_FILE ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fires the core hook with one argument, as some callers do.
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
	 * @testdox Activating WooPayments from its own folder or a renamed one reaches the controller's guard, as the arbiter detects either.
	 * @dataProvider woopayments_plugin_files
	 *
	 * @param string $plugin_file Plugin file being activated.
	 */
	public function test_woopayments_activation_reaches_the_guard( string $plugin_file ): void {
		$this->controller->expects( $this->once() )->method( 'guard_woopayments_activation' );

		do_action( 'activate_plugin', $plugin_file, false );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function woopayments_plugin_files(): array {
		return array(
			'canonical folder' => array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ),
			'renamed folder'   => array( 'renamed-wcpay/woocommerce-payments.php' ),
		);
	}

	/**
	 * @testdox Activating another plugin does not reach the guard.
	 */
	public function test_other_plugin_activation_skips_the_guard(): void {
		$this->controller->expects( $this->never() )->method( 'guard_woopayments_activation' );

		do_action( 'activate_plugin', 'woocommerce-payments-dev-tools/woocommerce-payments-dev-tools.php', false );
		do_action( 'activate_plugin', 'woocommerce-payments.php-helper/helper.php', false );
	}

	/**
	 * @testdox A malformed plugin value on the activation hook is ignored without reaching the guard or failing.
	 */
	public function test_malformed_activation_value_skips_the_guard(): void {
		$this->controller->expects( $this->never() )->method( 'guard_woopayments_activation' );

		// Called directly: core's own activate_plugin callbacks (Packages.php) do not accept a malformed value either.
		$this->sut->guard_woopayments_activation( array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
	}

	/**
	 * @testdox The listener registers on the generic activation hook and the two plugin lifecycle hooks.
	 */
	public function test_registers_the_lifecycle_hooks(): void {
		$this->assertSame( 10, has_action( 'activate_plugin', array( $this->sut, 'guard_woopayments_activation' ) ) );
		$this->assertSame( 10, has_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ) ) );
		$this->assertSame( 10, has_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ) ) );
	}
}
