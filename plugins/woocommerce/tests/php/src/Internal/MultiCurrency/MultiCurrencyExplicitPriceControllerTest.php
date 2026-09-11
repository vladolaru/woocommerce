<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyExplicitPriceController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyExplicitPriceController class.
 */
class MultiCurrencyExplicitPriceControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the explicit price controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_cart_total',
		'woocommerce_get_formatted_order_total',
		'woocommerce_admin_order_totals_after_tax',
		'woocommerce_admin_order_totals_after_total',
		'wc_price_args',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		parent::tear_down();
	}

	/**
	 * @testdox Should register explicit price hooks once when core owns runtime.
	 */
	public function test_registers_explicit_price_hooks_once_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, true );

		$sut->register();
		$sut->register();

		$this->assertSame( 100, has_filter( 'woocommerce_cart_total', array( $sut, 'get_explicit_price' ) ) );
		$this->assertSame( 100, has_filter( 'woocommerce_get_formatted_order_total', array( $sut, 'get_explicit_price' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_order_totals_after_tax', array( $sut, 'register_formatted_woocommerce_price_filter' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_order_totals_after_total', array( $sut, 'unregister_formatted_woocommerce_price_filter' ) ) );
	}

	/**
	 * @testdox Should not register explicit price hooks when plugin owns runtime.
	 */
	public function test_does_not_register_explicit_price_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, true );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_cart_total', array( $sut, 'get_explicit_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_formatted_order_total', array( $sut, 'get_explicit_price' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_order_totals_after_tax', array( $sut, 'register_formatted_woocommerce_price_filter' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_order_totals_after_total', array( $sut, 'unregister_formatted_woocommerce_price_filter' ) ) );
	}

	/**
	 * @testdox Should format explicit prices only when additional currencies are enabled.
	 */
	public function test_formats_explicit_prices_only_when_additional_currencies_are_enabled(): void {
		$multi_currency  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, true );
		$single_currency = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, false );
		$order           = $this->createMock( \WC_Order::class );
		$order->method( 'get_currency' )->willReturn( 'BRL' );

		$this->assertSame( 'R$ 5,90 BRL', $multi_currency->get_explicit_price( 'R$ 5,90', $order ) );
		$this->assertSame( '$10.30 USD', $multi_currency->get_explicit_price( '$10.30' ) );
		$this->assertSame( 'R$ 5,90', $single_currency->get_explicit_price( 'R$ 5,90', $order ) );
	}

	/**
	 * @testdox Should temporarily add explicit wc price args for admin order totals.
	 */
	public function test_temporarily_adds_explicit_wc_price_args_for_admin_order_totals(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, true );

		$sut->register_formatted_woocommerce_price_filter();

		$this->assertSame( 100, has_filter( 'wc_price_args', array( $sut, 'get_explicit_price_args' ) ) );
		$this->assertSame(
			array(
				'price_format' => '%1$s%2$s&nbsp;USD',
				'currency'     => 'USD',
			),
			$sut->get_explicit_price_args(
				array(
					'price_format' => '%1$s%2$s',
					'currency'     => 'USD',
				)
			)
		);

		$sut->unregister_formatted_woocommerce_price_filter();

		$this->assertFalse( has_filter( 'wc_price_args', array( $sut, 'get_explicit_price_args' ) ) );
	}

	/**
	 * Create an explicit price controller.
	 *
	 * @param string $owner                            Runtime owner.
	 * @param bool   $has_additional_currencies_enabled Whether state has additional enabled currencies.
	 * @return MultiCurrencyExplicitPriceController
	 */
	private function create_controller( string $owner, bool $has_additional_currencies_enabled ): MultiCurrencyExplicitPriceController {
		$controller = new MultiCurrencyExplicitPriceController();
		$controller->init(
			$this->create_arbiter( $owner ),
			$this->create_state_builder_factory( $has_additional_currencies_enabled )
		);

		return $controller;
	}

	/**
	 * Create a state builder factory.
	 *
	 * @param bool $has_additional_currencies_enabled Whether state has additional enabled currencies.
	 * @return MultiCurrencyStateBuilderFactory
	 */
	private function create_state_builder_factory( bool $has_additional_currencies_enabled ): MultiCurrencyStateBuilderFactory {
		$state = $this->createMock( MultiCurrencyState::class );
		$state->method( 'has_additional_currencies_enabled' )->willReturn( $has_additional_currencies_enabled );

		$builder = $this->getMockBuilder( MultiCurrencyStateBuilder::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'build' ) )
			->getMock();
		$builder->method( 'build' )->willReturn( $state );

		$factory = $this->getMockBuilder( MultiCurrencyStateBuilderFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create' ) )
			->getMock();
		$factory->method( 'create' )->willReturn( $builder );

		return $factory;
	}

	/**
	 * Create an arbiter with static ownership.
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
			 * Tell whether core multi-currency may register.
			 *
			 * @return bool
			 */
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_CORE === $this->owner;
			}
		};
	}
}
