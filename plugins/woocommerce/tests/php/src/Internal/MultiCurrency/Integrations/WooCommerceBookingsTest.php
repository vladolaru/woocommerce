<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommerceBookings;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\GeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceCalculator;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\CallStack;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RegisteredHooks;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RequestSignals;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;

/**
 * Tests for the WooCommerceBookings class.
 */
class WooCommerceBookingsTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the Bookings compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'plugins_loaded',
		'woocommerce_bookings_calculated_booking_cost',
		'woocommerce_product_get_block_cost',
		'woocommerce_product_get_cost',
		'woocommerce_product_get_display_cost',
		'woocommerce_product_booking_person_type_get_block_cost',
		'woocommerce_product_booking_person_type_get_cost',
		'woocommerce_product_get_resource_base_costs',
		'woocommerce_product_get_resource_block_costs',
		'wcpay_multi_currency_should_convert_product_price',
		'woocommerce_bookings_process_cost_rules_cost',
		'woocommerce_bookings_process_cost_rules_base_cost',
		'wp_ajax_wc_bookings_calculate_costs',
		'wp_ajax_nopriv_wc_bookings_calculate_costs',
		'wc_price_args',
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
	 * @testdox Should register exactly the Bookings hooks the request allows, once: $_dataName.
	 * @dataProvider provide_bookings_requests
	 *
	 * @param string                            $owner              Runtime owner.
	 * @param bool                              $bookings_available Whether Bookings is loaded.
	 * @param bool                              $is_admin           Whether this is an admin request.
	 * @param bool                              $is_ajax            Whether this is an Ajax request.
	 * @param array<string,array<string,int[]>> $expected           Every hook the controller registers.
	 */
	public function test_registers_the_bookings_hooks_the_request_allows( string $owner, bool $bookings_available, bool $is_admin, bool $is_ajax, array $expected ): void {
		$sut = $this->create_controller( $owner, $bookings_available, $is_admin, $is_ajax );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Requests and the Bookings hooks each registers: all of them on the storefront and on admin Ajax, none otherwise.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:bool,4:array<string,array<string,int[]>>}>
	 */
	public function provide_bookings_requests(): array {
		$hooks = array(
			'wcpay_multi_currency_should_convert_product_price' => array( 'should_convert_product_price' => array( 50, 2 ) ),
			'woocommerce_bookings_calculated_booking_cost' => array( 'adjust_amount_for_calculated_booking_cost' => array( 50, 1 ) ),
			'woocommerce_bookings_process_cost_rules_base_cost' => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_bookings_process_cost_rules_cost' => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_booking_person_type_get_block_cost' => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_booking_person_type_get_cost' => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_get_block_cost'           => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_get_cost'                 => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_get_display_cost'         => array( 'get_price' => array( 50, 1 ) ),
			'woocommerce_product_get_resource_base_costs'  => array( 'get_resource_prices' => array( 50, 1 ) ),
			'woocommerce_product_get_resource_block_costs' => array( 'get_resource_prices' => array( 50, 1 ) ),
			'wp_ajax_nopriv_wc_bookings_calculate_costs'   => array( 'add_wc_price_args_filter_for_ajax' => array( 9, 1 ) ),
			'wp_ajax_wc_bookings_calculate_costs'          => array( 'add_wc_price_args_filter_for_ajax' => array( 9, 1 ) ),
		);

		return array(
			'storefront'          => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false, $hooks ),
			'admin Ajax'          => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, true, $hooks ),
			'the extension owns'  => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, false, false, array() ),
			'Bookings not loaded' => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, array() ),
			'an admin page'       => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, false, array() ),
		);
	}

	/**
	 * @testdox Should defer Bookings hook registration until plugins load.
	 */
	public function test_defers_bookings_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, false );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_bookings_calculated_booking_cost', array( $sut, 'adjust_amount_for_calculated_booking_cost' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_bookings_filters' ) ) );

		$sut->set_bookings_available( true );
		$sut->register_bookings_filters();

		$this->assertSame( 50, has_filter( 'woocommerce_bookings_calculated_booking_cost', array( $sut, 'adjust_amount_for_calculated_booking_cost' ) ) );
	}

	/**
	 * @testdox Should round and charm calculated booking costs, already in the selected currency, outside cart add-to-cart.
	 */
	public function test_adjusts_calculated_booking_costs_outside_cart_add_to_cart(): void {
		$sut = $this->create_controller();

		// Bookings sums costs that get_price() already converted, so the client only rounds up to 0.50 and adds the -0.10 charm
		// (client 11.1.0 `includes/multi-currency/Compatibility/WooCommerceBookings.php:66-77`, `MultiCurrency.php:1632,1657-1683`).
		$this->assertSame( 8.4, $sut->adjust_amount_for_calculated_booking_cost( '8.21' ) );
		$this->assertSame( 9.9, $sut->adjust_amount_for_calculated_booking_cost( '10.00' ) );

		$this->assertSame( '10.00', CallStack::run( array( 'WC_Cart->add_to_cart' ), fn() => $sut->adjust_amount_for_calculated_booking_cost( '10.00' ) ) );
	}

	/**
	 * @testdox Should convert Bookings price hooks with context-specific price types.
	 */
	public function test_converts_bookings_price_hooks_with_context_specific_price_types(): void {
		$sut = $this->create_controller();

		$this->assertSame( 8.2, $sut->get_price( '10.00' ) );

		CallStack::run(
			array( 'WC_Product_Booking->get_price_html' ),
			function () use ( $sut ): void {
				$this->assertSame( 8.4, $sut->get_price( '10.00' ) );
				$this->assertSame( 0, $sut->get_price( 0 ) );
			}
		);

		$this->assertSame(
			'10.00',
			CallStack::run(
				array(
					'WC_Cart->add_to_cart',
					'WC_Bookings_Cost_Calculation::calculate_booking_cost',
				),
				fn() => $sut->get_price( '10.00' )
			)
		);
	}

	/**
	 * @testdox Should convert Bookings resource price arrays.
	 */
	public function test_converts_bookings_resource_price_arrays(): void {
		$sut = $this->create_controller();

		$this->assertSame(
			array(
				'room' => 8.2,
				'desk' => 16.4,
			),
			$sut->get_resource_prices(
				array(
					'room' => '10.00',
					'desk' => 20,
				)
			)
		);
		$this->assertSame( 'not-array', $sut->get_resource_prices( 'not-array' ) );
	}

	/**
	 * @testdox Should suppress default product conversion for Bookings price HTML.
	 */
	public function test_suppresses_default_product_conversion_for_bookings_price_html(): void {
		$sut = $this->create_controller();

		$this->assertTrue( $sut->should_convert_product_price( true, $this->create_product_type( 'booking' ) ) );

		CallStack::run(
			array( 'WC_Product_Booking->get_price_html' ),
			function () use ( $sut ): void {
				$this->assertFalse( $sut->should_convert_product_price( true, $this->create_product_type( 'booking' ) ) );
				$this->assertTrue( $sut->should_convert_product_price( true, $this->create_product_type( 'simple' ) ) );
				$this->assertFalse( $sut->should_convert_product_price( false, $this->create_product_type( 'booking' ) ) );
			}
		);
	}

	/**
	 * @testdox Should add selected-currency price args for Bookings Ajax calculations.
	 */
	public function test_adds_selected_currency_price_args_for_bookings_ajax_calculations(): void {
		$sut = $this->create_controller();

		$sut->add_wc_price_args_filter_for_ajax();

		$this->assertSame( 100, has_filter( 'wc_price_args', array( $sut, 'filter_wc_price_args' ) ) );
		$this->assertSame(
			array(
				'currency'           => 'GBP',
				'decimal_separator'  => ',',
				'thousand_separator' => '.',
				'decimals'           => 3,
				'price_format'       => '%2$s&nbsp;%1$s',
			),
			$sut->filter_wc_price_args(
				array(
					'currency'           => 'USD',
					'decimal_separator'  => '.',
					'thousand_separator' => ',',
					'decimals'           => 2,
					'price_format'       => '%1$s%2$s',
				)
			)
		);
	}

	/**
	 * Create a Bookings compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner              Runtime owner.
	 * @param bool   $bookings_available Whether Bookings runtime is available.
	 * @param bool   $is_admin           Whether this is an admin request.
	 * @param bool   $is_ajax            Whether this is an Ajax request.
	 * @param bool   $plugins_loaded     Whether plugins have loaded.
	 * @return WooCommerceBookings
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $bookings_available = true,
		bool $is_admin = false,
		bool $is_ajax = false,
		bool $plugins_loaded = true
	): WooCommerceBookings {
		$controller = new class( $bookings_available, $is_admin, $is_ajax, $plugins_loaded ) extends WooCommerceBookings {
			/**
			 * Whether Bookings runtime is available.
			 *
			 * @var bool
			 */
			private bool $bookings_available;

			/**
			 * Whether this is an admin request.
			 *
			 * @var bool
			 */
			private bool $is_admin;

			/**
			 * Whether this is an Ajax request.
			 *
			 * @var bool
			 */
			private bool $is_ajax;

			/**
			 * Whether plugins have loaded.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded;

			/**
			 * Constructor.
			 *
			 * @param bool $bookings_available Whether Bookings runtime is available.
			 * @param bool $is_admin           Whether this is an admin request.
			 * @param bool $is_ajax            Whether this is an Ajax request.
			 * @param bool $plugins_loaded     Whether plugins have loaded.
			 */
			public function __construct( bool $bookings_available, bool $is_admin, bool $is_ajax, bool $plugins_loaded ) {
				$this->bookings_available = $bookings_available;
				$this->is_admin           = $is_admin;
				$this->is_ajax            = $is_ajax;
				$this->plugins_loaded     = $plugins_loaded;
			}

			/**
			 * Set whether Bookings runtime is available.
			 *
			 * @param bool $bookings_available Whether Bookings runtime is available.
			 */
			public function set_bookings_available( bool $bookings_available ): void {
				$this->bookings_available = $bookings_available;
			}

			/**
			 * Check if Bookings runtime is available.
			 *
			 * @return bool
			 */
			protected function is_bookings_runtime_available(): bool {
				return $this->bookings_available;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array(
					'admin'          => $this->is_admin,
					'ajax'           => $this->is_ajax,
					'plugins_loaded' => $this->plugins_loaded,
				);
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with(
				$this,
				array(
					'create_price_converter' => $this->create_price_converter(),
					'create_display_service' => $this->create_display_service(),
				)
			),
			CallStack::request_context()
		);

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

	/**
	 * Create a price projection service.
	 *
	 * @return PriceConverter
	 */
	private function create_price_converter(): PriceConverter {
		$localization = $this->create_localization();

		return new PriceConverter(
			$this->create_state_builder( $this->create_state() ),
			new PriceCalculator( $localization )
		);
	}

	/**
	 * Create a frontend projection service.
	 *
	 * @return DisplayService
	 */
	private function create_display_service(): DisplayService {
		$localization = $this->create_localization();

		return new DisplayService(
			$this->create_state_builder( $this->create_state() ),
			$localization,
			new GeolocationService( $localization )
		);
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return StateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): StateBuilder {
		return new class( $state ) extends StateBuilder {
			/**
			 * Multi-currency state.
			 *
			 * @var MultiCurrencyState
			 */
			private MultiCurrencyState $state;

			/**
			 * Constructor.
			 *
			 * @param MultiCurrencyState $state Multi-currency state.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * Build a multi-currency state snapshot.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};
	}

	/**
	 * Create multi-currency state.
	 *
	 * @return MultiCurrencyState
	 */
	private function create_state(): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true );
		$gbp = $this->create_currency( 'GBP', 0.82, false, '0.50', -0.10 );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $gbp );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether this is the default currency.
	 * @param string $rounding   Rounding amount.
	 * @param float  $charm      Charm amount.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default, string $rounding = '0', float $charm = 0.0 ): MultiCurrencyCurrency {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
		$currency->set_rounding( $rounding );
		$currency->set_charm( $charm );

		return $currency;
	}

	/**
	 * Create a product of a given type, as an extension's product class reports it: WooCommerce Bookings 3.12.1's
	 * `WC_Product_Booking` extends `WC_Product` and its `get_type()` returns 'booking'
	 * (`includes/data-objects/class-wc-product-booking.php:15,220-222`).
	 *
	 * @param string $type Product type.
	 * @return \WC_Product
	 */
	private function create_product_type( string $type ): \WC_Product {
		return new class( $type ) extends \WC_Product_Simple {
			/**
			 * Product type, unset while the parent constructor picks the simple product data store.
			 *
			 * @var string|null
			 */
			private ?string $product_type = null;

			/**
			 * Constructor.
			 *
			 * @param string $product_type Product type.
			 */
			public function __construct( string $product_type ) {
				parent::__construct();
				$this->product_type = $product_type;
			}

			/**
			 * Get the product type.
			 *
			 * @return string
			 */
			public function get_type() {
				return $this->product_type ?? parent::get_type();
			}
		};
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				if ( 'GBP' === strtoupper( (string) $currency_code ) ) {
					return array(
						'currency_pos' => 'right_space',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'num_decimals' => 3,
					);
				}

				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}
}
