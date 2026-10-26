<?php
/**
 * PluginLifecycleListenerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PluginLifecycleListener;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the plugin lifecycle hooks the cutover listens to on every request class.
 */
class PluginLifecycleListenerTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var PluginLifecycleListener
	 */
	private PluginLifecycleListener $sut;

	/**
	 * Cutover controller double, resolved by the listener from the container.
	 *
	 * @var CutoverController&MockObject
	 */
	private $controller;

	/**
	 * Set up the listener with a controller double in the container.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->controller = $this->createMock( CutoverController::class );
		wc_get_container()->replace( CutoverController::class, $this->controller );
		$this->sut = new PluginLifecycleListener();
		$this->sut->register();
	}

	/**
	 * Remove the listener's hooks and the container replacement.
	 */
	public function tearDown(): void {
		remove_action( 'activate_plugin', array( $this->sut, 'guard_woopayments_activation' ) );
		remove_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ), 10 );
		remove_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ), 10 );
		wc_get_container()->reset_replacement( CutoverController::class );
		parent::tearDown();
	}

	/**
	 * @testdox $hook reaches the controller's $method ($_dataName).
	 * @dataProvider lifecycle_hook_provider
	 *
	 * @param string           $hook      Plugin lifecycle hook.
	 * @param array<int,mixed> $arguments Arguments the hook fires with.
	 * @param string           $method    Controller method that must be called.
	 * @param array<int,mixed> $expected  Arguments the controller method must receive.
	 */
	public function test_plugin_lifecycle_hook_reaches_the_controller( string $hook, array $arguments, string $method, array $expected ): void {
		$this->controller->expects( $this->once() )
			->method( $method )
			->with( ...$expected );

		do_action( $hook, ...$arguments ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fires core's plugin lifecycle hooks, one with a single argument as some callers do.
	}

	/**
	 * Plugin lifecycle hooks and what the controller receives.
	 *
	 * @return array<string,array{string,array<int,mixed>,string,array<int,mixed>}>
	 */
	public function lifecycle_hook_provider(): array {
		return array(
			'a WooPayments deactivation in any request' => array( 'deactivated_plugin', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false ), 'handle_plugin_deactivated', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ),
			'an activation hook fired with only the plugin path' => array( 'activated_plugin', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), 'handle_plugin_activated', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false ) ),
			'a network-wide WooPayments activation in any request' => array( 'activated_plugin', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, true ), 'handle_plugin_activated', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, true ) ),
		);
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
			'canonical folder' => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ),
			'renamed folder'   => array( 'renamed-wcpay/woocommerce-payments.php' ),
		);
	}

	/**
	 * @testdox A network-wide WooPayments activation does not reach the site guard: the per-site activation controller checks it site by site.
	 */
	public function test_network_wide_activation_skips_the_site_guard(): void {
		$this->controller->expects( $this->never() )->method( 'guard_woopayments_activation' );

		do_action( 'activate_plugin', WooPaymentsRuntimeArbiter::PLUGIN_FILE, true ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fires core's plugin activation hook as a network activation does.
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
		$this->sut->guard_woopayments_activation( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
	}
}
