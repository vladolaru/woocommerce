<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyProductAddOnsCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceCalculator;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyProductAddOnsCompatibilityController class.
 */
class MultiCurrencyProductAddOnsCompatibilityControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the Product Add-ons compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'plugins_loaded',
		'woocommerce_addons_add_cart_price_to_value',
		'woocommerce_addons_add_order_price_to_value',
		'woocommerce_product_addons_ajax_get_product_price_excluding_tax',
		'woocommerce_product_addons_ajax_get_product_price_including_tax',
		'woocommerce_product_addons_get_item_data',
		'woocommerce_product_addons_option_price_raw',
		'woocommerce_product_addons_order_line_item_meta',
		'woocommerce_product_addons_params',
		'woocommerce_product_addons_price_raw',
		'woocommerce_product_addons_update_product_price',
		'wcpay_multi_currency_should_convert_product_price',
		'wc_get_price_decimal_separator',
		'wc_get_price_decimals',
		'wc_get_price_thousand_separator',
	);

	/**
	 * Original POST data.
	 *
	 * @var array<mixed>
	 */
	private array $original_post = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Captures POST fixture state for restoration.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		$_POST = $this->original_post; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Restores POST fixture state.

		parent::tear_down();
	}

	/**
	 * @testdox Should register Product Add-ons frontend hooks for core runtime.
	 */
	public function test_registers_product_addons_frontend_hooks_for_core_runtime(): void {
		$sut = $this->create_controller();

		$sut->register();
		$sut->register();

		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_option_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_params', array( $sut, 'product_addons_params' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_get_item_data', array( $sut, 'get_item_data' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_update_product_price', array( $sut, 'update_product_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_order_line_item_meta', array( $sut, 'order_line_item_meta' ) ) );
		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_should_convert_product_price', array( $sut, 'should_convert_product_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_addons_ajax_get_product_price_including_tax', array( $sut, 'get_product_calculation_price' ) ) );
		$this->assertSame(
			array(
				'wcpay_multi_currency_should_convert_product_price' => array( 'should_convert_product_price' => array( 50, 2 ) ),
				'woocommerce_product_addons_get_item_data' => array( 'get_item_data' => array( 50, 3 ) ),
				'woocommerce_product_addons_option_price_raw' => array( 'get_addons_price' => array( 50, 2 ) ),
				'woocommerce_product_addons_order_line_item_meta' => array( 'order_line_item_meta' => array( 50, 4 ) ),
				'woocommerce_product_addons_params'        => array( 'product_addons_params' => array( 50, 1 ) ),
				'woocommerce_product_addons_price_raw'     => array( 'get_addons_price' => array( 50, 2 ) ),
				'woocommerce_product_addons_update_product_price' => array( 'update_product_price' => array( 50, 4 ) ),
			),
			RegisteredHooks::of( $sut )
		);
	}

	/**
	 * @testdox Should register Product Add-ons Ajax hooks for core runtime.
	 */
	public function test_registers_product_addons_ajax_hooks_for_core_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, true, true, false );

		$sut->register();
		$sut->register();

		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_ajax_get_product_price_including_tax', array( $sut, 'get_product_calculation_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_ajax_get_product_price_excluding_tax', array( $sut, 'get_product_calculation_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_addons_option_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_addons_update_product_price', array( $sut, 'update_product_price' ) ) );
		$this->assertSame(
			array(
				'woocommerce_product_addons_ajax_get_product_price_excluding_tax' => array( 'get_product_calculation_price' => array( 50, 3 ) ),
				'woocommerce_product_addons_ajax_get_product_price_including_tax' => array( 'get_product_calculation_price' => array( 50, 3 ) ),
			),
			RegisteredHooks::of( $sut )
		);
	}

	/**
	 * @testdox Should not register Product Add-ons hooks when runtime guards block.
	 */
	public function test_does_not_register_product_addons_hooks_when_guards_block(): void {
		$plugin_owned   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );
		$missing_addons = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false );
		$admin_non_ajax = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, true, false, false );
		$frontend_cron  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, false, false, true );

		$plugin_owned->register();
		$missing_addons->register();
		$admin_non_ajax->register();
		$frontend_cron->register();

		$this->assert_product_addons_hooks_not_registered( $plugin_owned );
		$this->assert_product_addons_hooks_not_registered( $missing_addons );
		$this->assert_product_addons_hooks_not_registered( $admin_non_ajax );
		$this->assert_product_addons_hooks_not_registered( $frontend_cron );
	}

	/**
	 * @testdox Should defer Product Add-ons hook registration until plugins load.
	 */
	public function test_defers_product_addons_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_product_addons_option_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_product_addons_hooks' ) ) );

		$sut->set_product_addons_available( true );
		$sut->register_product_addons_hooks();

		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_option_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_addons_update_product_price', array( $sut, 'update_product_price' ) ) );
	}

	/**
	 * @testdox Should convert raw add-on prices as product prices.
	 */
	public function test_converts_raw_addon_prices_as_product_prices(): void {
		$sut = $this->create_controller();

		$this->assertSame( 8.4, $sut->get_addons_price( '10.00', array( 'price_type' => 'flat_fee' ) ) );
		$this->assertSame( 8.4, $sut->get_addons_price( '10.00', array( 'price_type' => 'quantity_based' ) ) );
		$this->assertSame( 8.4, $sut->get_addons_price( '10.00', array() ) );
		$this->assertSame( '10.00', $sut->get_addons_price( '10.00', array( 'price_type' => 'percentage_based' ) ) );
	}

	/**
	 * @testdox Should use filtered currency formatting for Product Add-ons params.
	 */
	public function test_uses_filtered_currency_formatting_for_product_addons_params(): void {
		add_filter(
			'wc_get_price_decimals',
			static function () {
				return 0;
			},
			999
		);
		add_filter(
			'wc_get_price_decimal_separator',
			static function () {
				return ',';
			},
			999
		);
		add_filter(
			'wc_get_price_thousand_separator',
			static function () {
				return '.';
			},
			999
		);

		$params = $this->create_controller()->product_addons_params( array( 'existing' => 'value' ) );

		$this->assertSame(
			array(
				'existing'                     => 'value',
				'currency_format_num_decimals' => 0,
				'currency_format_decimal_sep'  => ',',
				'currency_format_thousand_sep' => '.',
			),
			$params
		);
	}

	/**
	 * @testdox Should suppress product price conversion after add-ons were converted.
	 */
	public function test_suppresses_product_price_conversion_after_addons_were_converted(): void {
		$sut     = $this->create_controller();
		$product = $this->create_product();
		$product->update_meta_data( '_wcpay_multi_currency_addons_converted', 1 );

		$this->assertFalse( $sut->should_convert_product_price( true, $product ) );
		$this->assertFalse( $sut->should_convert_product_price( false, $this->create_product() ) );
		$this->assertTrue( $sut->should_convert_product_price( true, $this->create_product() ) );
	}

	/**
	 * @testdox Should convert Ajax calculation unit prices.
	 */
	public function test_converts_ajax_calculation_unit_prices(): void {
		$sut     = $this->create_controller();
		$product = new \WC_Product_Simple();

		$this->assertSame( $this->converted_price( 5 ) * 3, $sut->get_product_calculation_price( 15.0, 3, $product ) );
		$this->assertSame( 15.0, $sut->get_product_calculation_price( 15.0, 0, $product ) );
	}

	/**
	 * @testdox Should update product prices from converted add-ons.
	 */
	public function test_updates_product_prices_from_converted_addons(): void {
		$sut     = $this->create_controller();
		$product = $this->create_product( 33 );

		$result = $sut->update_product_price(
			array(),
			array(
				'data'     => $product,
				'quantity' => 2,
				'addons'   => array(
					array(
						'price'      => 10,
						'price_type' => 'percentage_based',
						'field_type' => 'select',
						'value'      => 1,
					),
					array(
						'price'      => 10,
						'price_type' => 'flat_fee',
						'field_type' => 'input_multiplier',
						'value'      => 2,
					),
					// A select stores the chosen option's label as its value (Product Add-Ons 8.4.3
					// `includes/fields/class-wc-product-addons-field-list.php:117`); a numeric label must not split the price.
					array(
						'price'      => 5,
						'price_type' => 'quantity_based',
						'field_type' => 'select',
						'value'      => 2,
					),
					array(
						'price'      => 3,
						'price_type' => 'quantity_based',
						'field_type' => 'custom_price',
						'value'      => 1,
					),
				),
			),
			array(
				'price'         => 10,
				'regular_price' => 12,
				'sale_price'    => 9,
			),
			0
		);

		$converted_price         = $this->converted_price( 10 );
		$converted_regular_price = $this->converted_price( 12 );
		$converted_sale_price    = $this->converted_price( 9 );
		$flat_fee                = ( $this->converted_price( 5 ) * 2 ) / 2;
		$quantity_addon          = $this->converted_price( 5 );

		$this->assertSame( $converted_price + ( $converted_price * 0.10 ) + $flat_fee + $quantity_addon + 3, $result['price'] );
		$this->assertSame( $converted_regular_price + ( $converted_regular_price * 0.10 ) + $flat_fee + $quantity_addon + 3, $result['regular_price'] );
		$this->assertSame( $converted_sale_price + ( $converted_sale_price * 0.10 ) + $flat_fee + $quantity_addon + 3, $result['sale_price'] );
		$this->assertSame( $flat_fee, $result['addons_flat_fees_sum'] );
		$this->assertSame( 1, $product->get_meta( '_wcpay_multi_currency_addons_converted' ) );
	}

	/**
	 * @testdox Should use Smart Coupons credit amount when base price is empty.
	 */
	public function test_uses_smart_coupons_credit_amount_when_base_price_is_empty(): void {
		$sut                    = $this->create_controller();
		$product                = $this->create_product( 33 );
		$_POST['credit_called'] = array( 33 => '20.00' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Smart Coupons compatibility fixture.

		$result = $sut->update_product_price(
			array(),
			array(
				'data'     => $product,
				'quantity' => 1,
				'addons'   => array(),
			),
			array(
				'price'         => 0,
				'regular_price' => 0,
				'sale_price'    => 0,
			),
			0
		);

		$this->assertSame( 20.0, $result['price'] );
		$this->assertSame( 20.0, $result['regular_price'] );
		$this->assertSame( 20.0, $result['sale_price'] );
	}

	/**
	 * @testdox Should append converted flat fee cart item display prices.
	 */
	public function test_appends_converted_flat_fee_cart_item_display_prices(): void {
		$sut    = $this->create_controller();
		$result = $sut->get_item_data(
			array(),
			array(
				'name'       => 'Gift wrap',
				'value'      => '2',
				'price'      => 10,
				'price_type' => 'flat_fee',
				'field_type' => 'input_multiplier',
			),
			array(
				'data'       => $this->create_product(),
				'product_id' => 33,
			)
		);

		$this->assertSame(
			array(
				'name'    => 'Gift wrap',
				'value'   => '2 (+ formatted:' . ( $this->converted_price( 5 ) * 2 ) . ')',
				'display' => '',
			),
			$result
		);

		// Synthetic boundary case: a quantity of zero cannot split the price, so the whole price converts (client 11.1.0
		// `includes/multi-currency/Compatibility/WooCommerceProductAddOns.php:139-142` has no zero guard and would divide by zero).
		$this->assertSame(
			array(
				'name'    => 'Gift wrap',
				'value'   => '0 (+ formatted:' . $this->converted_price( 10 ) . ')',
				'display' => '',
			),
			$sut->get_item_data(
				array(),
				array(
					'name'       => 'Gift wrap',
					'value'      => '0',
					'price'      => 10,
					'price_type' => 'flat_fee',
					'field_type' => 'input_multiplier',
				),
				array(
					'data'       => $this->create_product(),
					'product_id' => 33,
				)
			)
		);
	}

	/**
	 * @testdox Should append percentage cart item display prices.
	 *
	 * The add-on and cart item shapes are the ones Product Add-Ons passes to its cart item data filter
	 * (Product Add-Ons 8.4.3 `includes/class-wc-product-addons-cart.php:1516-1581`), as WooPayments 11.1.0 reads them
	 * (`includes/multi-currency/Compatibility/WooCommerceProductAddOns.php:112-171`).
	 */
	public function test_appends_percentage_cart_item_display_prices(): void {
		add_filter( 'woocommerce_addons_add_cart_price_to_value', '__return_true' );
		// The cart's own price filter marks the output, so the value shows the price came through WC()->cart.
		add_filter(
			'woocommerce_cart_product_price',
			static function ( $price_html, $product ) {
				unset( $price_html );
				return 'cart:' . $product->get_price();
			},
			10,
			2
		);

		$sut     = $this->create_controller();
		$product = new \WC_Product_Simple();
		$sut->set_product( 33, $product );

		$result = $sut->get_item_data(
			array(),
			array(
				'name'       => 'Premium',
				'value'      => 'Yes',
				'price'      => 10,
				'price_type' => 'percentage_based',
				'field_type' => 'select',
			),
			array(
				'addons_price_before_calc' => 20,
				'data'                     => $product,
				'product_id'               => 33,
			)
		);

		$this->assertSame( 'Yes (cart:' . ( $this->converted_price( 20 ) * 0.10 ) . ')', $result['value'] );
		$this->assertSame( 1, $product->get_meta( '_wcpay_multi_currency_addons_converted' ) );
	}

	/**
	 * @testdox Should rewrite order line item meta with converted display prices.
	 */
	public function test_rewrites_order_line_item_meta_with_converted_display_prices(): void {
		add_filter( 'woocommerce_addons_add_order_price_to_value', '__return_true' );

		$sut     = $this->create_controller();
		$product = $this->create_product();
		$product->set_price( 20 );
		$item = new class( $product ) extends \WC_Order_Item_Product {
			/**
			 * Product the item was ordered for.
			 *
			 * @var \WC_Product
			 */
			private \WC_Product $ordered_product;

			/**
			 * Constructor.
			 *
			 * @param \WC_Product $ordered_product Product the item was ordered for.
			 */
			public function __construct( \WC_Product $ordered_product ) {
				parent::__construct();
				$this->ordered_product = $ordered_product;
			}

			/**
			 * Get the order item product, without a database read.
			 *
			 * @return \WC_Product
			 */
			public function get_product() {
				return $this->ordered_product;
			}
		};

		$result = $sut->order_line_item_meta(
			array(),
			array(
				'value'      => 'Premium',
				'price'      => 10,
				'price_type' => 'percentage_based',
				'field_type' => 'select',
			),
			$item,
			array( 'data' => $product )
		);

		$this->assertSame( 'Premium (formatted:2)', $result['value'] );
		$this->assertSame( $this->converted_price( 10 ), $result['raw_price'] );
	}

	/**
	 * Assert Product Add-ons hooks are not registered for a controller.
	 *
	 * @param MultiCurrencyProductAddOnsCompatibilityController $sut The controller.
	 */
	private function assert_product_addons_hooks_not_registered( MultiCurrencyProductAddOnsCompatibilityController $sut ): void {
		$this->assertFalse( has_filter( 'woocommerce_product_addons_option_price_raw', array( $sut, 'get_addons_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_addons_update_product_price', array( $sut, 'update_product_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_addons_ajax_get_product_price_including_tax', array( $sut, 'get_product_calculation_price' ) ) );
	}

	/**
	 * Create a Product Add-ons compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner                    Runtime owner.
	 * @param bool   $product_addons_available Whether Product Add-ons runtime is available.
	 * @param bool   $plugins_loaded           Whether plugins have loaded.
	 * @param bool   $is_admin                 Whether this is an admin request.
	 * @param bool   $is_ajax                  Whether this is an Ajax request.
	 * @param bool   $is_cron                  Whether this is a cron request.
	 * @return MultiCurrencyProductAddOnsCompatibilityController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $product_addons_available = true,
		bool $plugins_loaded = true,
		bool $is_admin = false,
		bool $is_ajax = false,
		bool $is_cron = false
	): MultiCurrencyProductAddOnsCompatibilityController {
		$controller = new class( $product_addons_available, $plugins_loaded, $is_admin, $is_ajax, $is_cron ) extends MultiCurrencyProductAddOnsCompatibilityController {
			/**
			 * Whether Product Add-ons runtime is available.
			 *
			 * @var bool
			 */
			private bool $product_addons_available;

			/**
			 * Whether plugins have loaded.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded;

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
			 * Whether this is a cron request.
			 *
			 * @var bool
			 */
			private bool $is_cron;

			/**
			 * Products keyed by ID.
			 *
			 * @var array<int,\WC_Product>
			 */
			private array $products = array();

			/**
			 * Constructor.
			 *
			 * @param bool $product_addons_available Whether Product Add-ons runtime is available.
			 * @param bool $plugins_loaded           Whether plugins have loaded.
			 * @param bool $is_admin                 Whether this is an admin request.
			 * @param bool $is_ajax                  Whether this is an Ajax request.
			 * @param bool $is_cron                  Whether this is a cron request.
			 */
			public function __construct( bool $product_addons_available, bool $plugins_loaded, bool $is_admin, bool $is_ajax, bool $is_cron ) {
				$this->product_addons_available = $product_addons_available;
				$this->plugins_loaded           = $plugins_loaded;
				$this->is_admin                 = $is_admin;
				$this->is_ajax                  = $is_ajax;
				$this->is_cron                  = $is_cron;
			}

			/**
			 * Set whether Product Add-ons runtime is available.
			 *
			 * @param bool $product_addons_available Whether Product Add-ons runtime is available.
			 */
			public function set_product_addons_available( bool $product_addons_available ): void {
				$this->product_addons_available = $product_addons_available;
			}

			/**
			 * Set a product lookup fixture.
			 *
			 * @param int         $product_id Product ID.
			 * @param \WC_Product $product    Product.
			 */
			public function set_product( int $product_id, \WC_Product $product ): void {
				$this->products[ $product_id ] = $product;
			}

			/**
			 * Check if Product Add-ons runtime is available.
			 *
			 * @return bool
			 */
			protected function is_product_addons_runtime_available(): bool {
				return $this->product_addons_available;
			}

			/**
			 * Get a product by ID.
			 *
			 * @param int $product_id Product ID.
			 * @return \WC_Product|null
			 */
			protected function get_product_by_id( int $product_id ): ?\WC_Product {
				return $this->products[ $product_id ] ?? null;
			}

			/**
			 * Format an add-on price.
			 *
			 * @param mixed $price   Price.
			 * @param mixed $product Product.
			 * @return string
			 */
			protected function format_addon_price( $price, $product ): string {
				unset( $product );

				return 'formatted:' . $price;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array(
					'plugins_loaded' => $this->plugins_loaded,
					'admin'          => $this->is_admin,
					'ajax'           => $this->is_ajax,
					'cron'           => $this->is_cron,
				);
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter() ) )
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
	 * @return MultiCurrencyPriceConverter
	 */
	private function create_price_converter(): MultiCurrencyPriceConverter {
		$localization = $this->create_localization();

		return new MultiCurrencyPriceConverter(
			$this->create_state_builder( $this->create_state() ),
			new MultiCurrencyPriceCalculator( $localization )
		);
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): MultiCurrencyStateBuilder {
		return new class( $state ) extends MultiCurrencyStateBuilder {
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
	 * Create a localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

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

	/**
	 * Convert a price through the same deterministic projection fixture.
	 *
	 * @param float|int $price Price.
	 * @return float
	 */
	private function converted_price( $price ): float {
		return $this->create_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Create an unsaved product.
	 *
	 * @param int $product_id Product ID.
	 * @return \WC_Product
	 */
	private function create_product( int $product_id = 0 ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_id( $product_id );

		return $product;
	}
}
