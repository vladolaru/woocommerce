<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommerceUPS;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\CallStack;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RegisteredHooks;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RequestSignals;

/**
 * Tests for the WooCommerceUPS class.
 */
class WooCommerceUPSTest extends WC_Unit_Test_Case {

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
	 * @testdox Should register exactly the UPS hooks the store allows, once: $_dataName.
	 * @dataProvider provide_ups_stores
	 *
	 * @param string                            $owner         Runtime owner.
	 * @param bool                              $ups_available Whether UPS is loaded.
	 * @param array<string,array<string,int[]>> $expected      Every hook the controller registers.
	 */
	public function test_registers_the_ups_hooks_the_store_allows( string $owner, bool $ups_available, array $expected ): void {
		$sut = $this->create_controller( $owner, $ups_available );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Stores and the UPS hooks each registers: the store-currency filter under core with UPS loaded, none otherwise.
	 *
	 * @return array<string,array{0:string,1:bool,2:array<string,array<string,int[]>>}>
	 */
	public function provide_ups_stores(): array {
		return array(
			'UPS loaded under core' => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, array( 'wcpay_multi_currency_should_return_store_currency' => array( 'should_return_store_currency' => array( 10, 1 ) ) ) ),
			'the extension owns'    => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, array() ),
			'UPS not loaded'        => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, array() ),
		);
	}

	/**
	 * @testdox Should defer UPS hook registration until plugins load.
	 */
	public function test_defers_ups_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );

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

		$this->assertTrue( CallStack::run( array( 'WC_Shipping_UPS->per_item_shipping' ), fn() => $sut->should_return_store_currency( false ) ) );
		$this->assertTrue( CallStack::run( array( 'WC_Shipping_UPS->box_shipping' ), fn() => $sut->should_return_store_currency( false ) ) );
		$this->assertTrue( CallStack::run( array( 'WC_Shipping_UPS->calculate_shipping' ), fn() => $sut->should_return_store_currency( false ) ) );
	}

	/**
	 * Create a UPS compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner          Runtime owner.
	 * @param bool   $ups_available  Whether UPS runtime is available.
	 * @param bool   $plugins_loaded Whether plugins have loaded.
	 * @return WooCommerceUPS
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $ups_available = true,
		bool $plugins_loaded = true
	): WooCommerceUPS {
		$controller = new class( $ups_available, $plugins_loaded ) extends WooCommerceUPS {
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
			 * Check if UPS runtime is available.
			 *
			 * @return bool
			 */
			protected function is_ups_runtime_available(): bool {
				return $this->ups_available;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array( 'plugins_loaded' => $this->plugins_loaded );
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$controller->init( $this->create_arbiter( $owner ), CallStack::request_context() );

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
