<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyUpsCompatibilityController;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyUpsCompatibilityController class.
 */
class MultiCurrencyUpsCompatibilityControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the UPS compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'plugins_loaded',
		'wcpay_multi_currency_should_return_store_currency',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should register UPS compatibility hooks for core runtime.
	 */
	public function test_registers_ups_compatibility_hooks_for_core_runtime(): void {
		$sut = $this->create_controller();

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_filter( 'wcpay_multi_currency_should_return_store_currency', array( $sut, 'should_return_store_currency' ) ) );
	}

	/**
	 * @testdox Should not register UPS hooks when runtime guards block.
	 */
	public function test_does_not_register_ups_hooks_when_guards_block(): void {
		$plugin_owned = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );
		$missing_ups  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, false );

		$plugin_owned->register();
		$missing_ups->register();

		$this->assert_ups_hooks_not_registered( $plugin_owned );
		$this->assert_ups_hooks_not_registered( $missing_ups );
	}

	/**
	 * @testdox Should defer UPS hook registration until plugins load.
	 */
	public function test_defers_ups_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, false );

		$sut->register();

		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_return_store_currency', array( $sut, 'should_return_store_currency' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_ups_filters' ) ) );

		$sut->set_ups_available( true );
		$sut->register_ups_filters();

		$this->assertSame( 10, has_filter( 'wcpay_multi_currency_should_return_store_currency', array( $sut, 'should_return_store_currency' ) ) );
	}

	/**
	 * @testdox Should force store currency during UPS shipping calculations.
	 */
	public function test_forces_store_currency_during_ups_shipping_calculations(): void {
		$sut = $this->create_controller();

		$this->assertTrue( $sut->should_return_store_currency( true ) );
		$this->assertFalse( $sut->should_return_store_currency( false ) );

		$sut->set_backtrace_calls( array( 'WC_Shipping_UPS->per_item_shipping' ) );
		$this->assertTrue( $sut->should_return_store_currency( false ) );

		$sut->set_backtrace_calls( array( 'WC_Shipping_UPS->box_shipping' ) );
		$this->assertTrue( $sut->should_return_store_currency( false ) );

		$sut->set_backtrace_calls( array( 'WC_Shipping_UPS->calculate_shipping' ) );
		$this->assertTrue( $sut->should_return_store_currency( false ) );
	}

	/**
	 * @testdox Should preserve backtrace matching semantics against a real call stack.
	 */
	public function test_is_call_in_backtrace_preserves_semantics_against_real_stack(): void {
		$sut = new class() extends MultiCurrencyUpsCompatibilityController {
			/**
			 * Expose the real is_call_in_backtrace() against the live stack.
			 *
			 * @param string[] $calls Expected call strings.
			 * @return bool
			 */
			public function matches_current_backtrace( array $calls ): bool {
				return $this->is_call_in_backtrace( $calls );
			}
		};

		$fixture = new class() {
			/**
			 * Ask the controller whether an instance-method call is on the stack.
			 *
			 * @param object $controller Controller exposing matches_current_backtrace().
			 * @return bool
			 */
			public function matches_instance_method_call( object $controller ): bool {
				return $controller->matches_current_backtrace( array( self::class . '->matches_instance_method_call' ) );
			}

			/**
			 * Ask the controller whether a static-method call is on the stack.
			 *
			 * @param object $controller Controller exposing matches_current_backtrace().
			 * @return bool
			 */
			public static function matches_static_method_call( object $controller ): bool {
				return $controller->matches_current_backtrace( array( self::class . '::matches_static_method_call' ) );
			}
		};

		// A stack that includes the expected call returns true for both instance and static frames.
		$this->assertTrue( $fixture->matches_instance_method_call( $sut ) );
		$this->assertTrue( $fixture::matches_static_method_call( $sut ) );

		// A stack that does not include the expected call returns false.
		$this->assertFalse( $sut->matches_current_backtrace( array( 'Nonexistent_Class->nonexistent_method' ) ) );
	}

	/**
	 * Assert UPS hooks are not registered for a controller.
	 *
	 * @param MultiCurrencyUpsCompatibilityController $sut The controller.
	 */
	private function assert_ups_hooks_not_registered( MultiCurrencyUpsCompatibilityController $sut ): void {
		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_return_store_currency', array( $sut, 'should_return_store_currency' ) ) );
	}

	/**
	 * Create a UPS compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner          Runtime owner.
	 * @param bool   $ups_available  Whether UPS runtime is available.
	 * @param bool   $plugins_loaded Whether plugins have loaded.
	 * @return MultiCurrencyUpsCompatibilityController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_CORE,
		bool $ups_available = true,
		bool $plugins_loaded = true
	): MultiCurrencyUpsCompatibilityController {
		$controller = new class( $ups_available, $plugins_loaded ) extends MultiCurrencyUpsCompatibilityController {
			/**
			 * Whether UPS runtime is available.
			 *
			 * @var bool
			 */
			private bool $ups_available;

			/**
			 * Whether plugins have loaded.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded;

			/**
			 * Backtrace summary calls.
			 *
			 * @var string[]
			 */
			private array $backtrace_calls = array();

			/**
			 * Constructor.
			 *
			 * @param bool $ups_available  Whether UPS runtime is available.
			 * @param bool $plugins_loaded Whether plugins have loaded.
			 */
			public function __construct( bool $ups_available, bool $plugins_loaded ) {
				$this->ups_available  = $ups_available;
				$this->plugins_loaded = $plugins_loaded;
			}

			/**
			 * Set whether UPS runtime is available.
			 *
			 * @param bool $ups_available Whether UPS runtime is available.
			 */
			public function set_ups_available( bool $ups_available ): void {
				$this->ups_available = $ups_available;
			}

			/**
			 * Set backtrace summary calls.
			 *
			 * @param string[] $backtrace_calls Backtrace summary calls.
			 */
			public function set_backtrace_calls( array $backtrace_calls ): void {
				$this->backtrace_calls = $backtrace_calls;
			}

			/**
			 * Check if UPS runtime is available.
			 *
			 * @return bool
			 */
			protected function is_ups_runtime_available(): bool {
				return $this->ups_available;
			}

			/**
			 * Check if plugins have loaded.
			 *
			 * @return bool
			 */
			protected function have_plugins_loaded(): bool {
				return $this->plugins_loaded;
			}

			/**
			 * Check if any expected call appears in the deterministic backtrace.
			 *
			 * @param string[] $calls Expected calls.
			 * @return bool
			 */
			protected function is_call_in_backtrace( array $calls ): bool {
				return array() !== array_intersect( $calls, $this->backtrace_calls );
			}
		};

		$controller->init( $this->create_arbiter( $owner ) );

		return $controller;
	}

	/**
	 * Create a runtime arbiter test double.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the multi-currency runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};
	}
}
