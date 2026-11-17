<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the MultiCurrencyFrontendPricesController class.
 */
class MultiCurrencyFrontendPricesControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the frontend prices controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_product_get_price',
		'woocommerce_product_get_regular_price',
		'woocommerce_product_get_sale_price',
		'woocommerce_product_variation_get_price',
		'woocommerce_product_variation_get_regular_price',
		'woocommerce_product_variation_get_sale_price',
		'woocommerce_variation_prices_price',
		'woocommerce_variation_prices_regular_price',
		'woocommerce_variation_prices_sale_price',
		'woocommerce_get_variation_prices_hash',
		'woocommerce_shipping_zone_shipping_methods',
		'woocommerce_shipping_method_add_rate_args',
		'woocommerce_evaluate_shipping_cost_args',
		'woocommerce_coupon_get_amount',
		'woocommerce_coupon_get_minimum_amount',
		'woocommerce_coupon_get_maximum_amount',
		'woocommerce_order_recalculate_coupons_coupon_object',
		'woocommerce_new_order',
		'woocommerce_order_refunded',
		'rest_post_dispatch',
		'query_loop_block_query_vars',
		'wcpay_multi_currency_should_convert_product_price',
		'wcpay_multi_currency_should_convert_coupon_amount',
		'wcpay_multi_currency_should_convert_coupon_min_max_amount',
		'wcpay_multi_currency_should_convert_shipping_amount',
		'wc_get_price_decimal_separator',
		'shortcode_atts_fee',
		'do_shortcode_tag',
		'woocommerce_before_calculate_totals',
		'woocommerce_cart_shipping_packages',
	);

	/**
	 * The customer's address before a real cart was calculated, restored after the case.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $customer_address = null;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}
		remove_all_filters( 'wcpay_multi_currency_override_selected_currency' );
		// A fee that threw leaves its shortcode registered, and the fee state is static.
		remove_shortcode( 'fee' );
		( new MultiCurrencyFrontendPricesController() )->reset_shipping_fee_rendering();
		if ( null !== $this->customer_address ) {
			WC()->cart->empty_cart();
			WC()->session->__unset( 'chosen_shipping_methods' );
			WC()->session->__unset( 'shipping_for_package_0' );
			WC()->customer->set_props( $this->customer_address );
		}
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @testdox Should register exactly the frontend price hooks the request allows, once: $_dataName.
	 * @dataProvider provide_frontend_price_hooks_requests
	 *
	 * @param string                            $owner          Runtime owner.
	 * @param bool                              $frontend_hooks Whether the request context lets frontend hooks register.
	 * @param array<string,array<string,int[]>> $expected       Every hook the controller registers.
	 */
	public function test_registers_the_frontend_price_hooks_the_request_allows( string $owner, bool $frontend_hooks, array $expected ): void {
		$sut = $this->create_controller( $owner, true, $this->create_request_context( $frontend_hooks ) );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Requests and the hooks each registers: the price, coupon and order hooks under core in a frontend context, nothing otherwise.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_frontend_price_hooks_requests(): array {
		return array(
			'core owns'                 => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				true,
				array(
					'do_shortcode_tag'                    => array( 'end_shipping_fee_rendering' => array( PHP_INT_MIN, 2 ) ),
					'query_loop_block_query_vars'         => array( 'maybe_modify_price_ranges_query_var' => array( 10, 3 ) ),
					'rest_post_dispatch'                  => array( 'maybe_modify_price_ranges_rest_response' => array( 10, 3 ) ),
					'shortcode_atts_fee'                  => array( 'start_shipping_fee_rendering' => array( 10, 1 ) ),
					'wc_get_price_decimal_separator'      => array( 'use_store_decimal_separator_during_shipping_fee' => array( 990, 1 ) ),
					'woocommerce_before_calculate_totals' => array( 'reset_shipping_fee_rendering' => array( PHP_INT_MIN, 1 ) ),
					'woocommerce_cart_shipping_packages'  => array( 'add_currency_to_shipping_packages' => array( PHP_INT_MAX, 1 ) ),
					'woocommerce_coupon_get_amount'       => array( 'get_coupon_amount' => array( 99, 2 ) ),
					'woocommerce_coupon_get_maximum_amount' => array( 'get_coupon_min_max_amount' => array( 99, 2 ) ),
					'woocommerce_coupon_get_minimum_amount' => array( 'get_coupon_min_max_amount' => array( 99, 2 ) ),
					'woocommerce_evaluate_shipping_cost_args' => array( 'convert_shipping_cost_args_to_store_currency' => array( 99, 3 ) ),
					'woocommerce_get_variation_prices_hash' => array( 'add_exchange_rate_to_variation_prices_hash' => array( 99, 1 ) ),
					'woocommerce_new_order'               => array( 'add_order_meta' => array( 99, 2 ) ),
					'woocommerce_order_recalculate_coupons_coupon_object' => array( 'set_order_coupon' => array( 99, 4 ) ),
					'woocommerce_product_get_price'       => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_product_get_regular_price' => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_product_get_sale_price'  => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_product_variation_get_price' => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_product_variation_get_regular_price' => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_product_variation_get_sale_price' => array( 'get_product_price_string' => array( 99, 2 ) ),
					'woocommerce_shipping_method_add_rate_args' => array( 'convert_shipping_method_rate_cost' => array( 99, 2 ) ),
					'woocommerce_shipping_zone_shipping_methods' => array( 'convert_free_shipping_method_min_amount' => array( 99, 1 ) ),
					'woocommerce_variation_prices_price'  => array( 'get_product_price_string' => array( 99, 1 ) ),
					'woocommerce_variation_prices_regular_price' => array( 'get_product_price_string' => array( 99, 1 ) ),
					'woocommerce_variation_prices_sale_price' => array( 'get_product_price_string' => array( 99, 1 ) ),
				),
			),
			'the extension owns'        => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, array() ),
			'nobody owns'               => array( MultiCurrencyRuntimeArbiter::OWNER_NONE, true, array() ),
			'a blocked request context' => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, array() ),
		);
	}

	/**
	 * @testdox Should pass each price to the projection service with its price type.
	 */
	public function test_passes_each_price_to_the_price_converter_with_its_type(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( '20', $sut->get_product_price_string( '10.00', (object) array() ) );
		$this->assertSame( array( 'cost' => 6.0 ), $sut->convert_shipping_method_rate_cost( array( 'cost' => '2.00' ) ) );
		$this->assertSame(
			array(
				'cost' => array(
					'base' => 6.0,
					'fee'  => 9.0,
				),
			),
			$sut->convert_shipping_method_rate_cost(
				array(
					'cost' => array(
						'base' => '2.00',
						'fee'  => '3.00',
					),
				)
			)
		);
		$this->assertSame( 20.0, $sut->get_coupon_amount( '5.00', $this->create_coupon() ) );
		$this->assertSame( '5.00', $sut->get_coupon_amount( '5.00', $this->create_coupon( true ) ), 'A percentage coupon amount is a percentage, never converted.' );
		$this->assertSame( 20.0, $sut->get_coupon_min_max_amount( '10.00' ) );
	}

	/**
	 * @testdox Should convert a real product, flat shipping rate and coupons with the client's rounding.
	 */
	public function test_converts_real_prices_with_rounding_and_charm(): void {
		// EUR at 0.91 with a 0.50 rounding step and a -0.01 charm, as the settings page saves them.
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.91' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.50' );
		update_option( 'wcpay_multi_currency_price_charm_eur', '-0.01' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'EUR' );

		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			wc_get_container()->get( ServiceFactory::class ),
			wc_get_container()->get( RequestContext::class )
		);
		$sut->register();

		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '10.00' ) );

		$shipping_method = new \WC_Shipping_Flat_Rate( 0 );
		$shipping_method->add_rate(
			array(
				'id'    => 'flat_rate',
				'label' => 'Flat rate',
				'cost'  => '5.00',
			)
		);

		$fixed_coupon = new \WC_Coupon();
		$fixed_coupon->set_discount_type( 'fixed_cart' );
		$fixed_coupon->set_amount( '3.01' );
		$fixed_coupon->set_minimum_amount( '20.00' );

		$percent_coupon = new \WC_Coupon();
		$percent_coupon->set_discount_type( 'percent' );
		$percent_coupon->set_amount( '10' );

		// Client 11.1.0 MultiCurrency::get_price() and get_adjusted_price() (includes/multi-currency/MultiCurrency.php:941-967, 1657-1684):
		// products and shipping ceil to the rounding step, only products take the charm, fixed coupons round to the currency decimals.
		$this->assertSame( '9.49', $product->get_price(), '10.00 x 0.91 = 9.10, ceiled to 9.50, minus the 0.01 charm.' );
		$this->assertEquals( 5.0, $shipping_method->rates['flat_rate']->get_cost(), '5.00 x 0.91 = 4.55, ceiled to 5.00, no charm on shipping.' );
		$this->assertEquals( 2.74, $fixed_coupon->get_amount(), '3.01 x 0.91 = 2.7391, rounded to two decimals.' );
		$this->assertEquals( 18.49, $fixed_coupon->get_minimum_amount(), 'A coupon minimum is treated as a product price (client FrontendPrices.php:329-337).' );
		$this->assertEquals( 10, $percent_coupon->get_amount(), 'A percent coupon keeps its percentage.' );
	}

	/**
	 * @testdox Should convert a variable product's range before rounding it to the selected currency's decimals: JPY rounding $rounding.
	 *
	 * The prices are a recorded store run's: a PLN store, JPY at a manual 35, variations at 10.37 and 20.37 PLN. Core rounds
	 * the range's prices with `wc_get_price_decimals()`, which is the selected currency's 0 on the storefront. The run showed
	 * ¥350 - ¥700 (no rounding) and ¥400 - ¥700 (the default step of 100) while the variations and the cart charged
	 * ¥363 and ¥713, and ¥400 and ¥800.
	 *
	 * @testWith ["0.00", "363", "713"]
	 *           [null, "400", "800"]
	 *
	 * @param string|null $rounding JPY rounding step, null for the default.
	 * @param string      $small    Range price of the 10.37 PLN variation.
	 * @param string      $large    Range price of the 20.37 PLN variation.
	 */
	public function test_converts_a_variable_range_before_rounding_it_to_the_selected_currency_decimals( ?string $rounding, string $small, string $large ): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '35' );
		if ( null !== $rounding ) {
			update_option( 'wcpay_multi_currency_price_rounding_jpy', $rounding );
		}
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'JPY' );
		$this->register_storefront_controllers();

		list( $product, $variation_ids ) = $this->create_variable_product( array( '10.37', '20.37' ) );
		$prices                          = $product->get_variation_prices( true );

		$this->assertSame( array_combine( $variation_ids, array( $small, $large ) ), $prices['price'] );
		$this->assertSame( array_combine( $variation_ids, array( $small, $large ) ), $prices['regular_price'] );
		$this->assertSame( $small, wc_get_product( $variation_ids[0] )->get_price(), 'The variation charges the range price.' );
		$this->assertSame( $large, wc_get_product( $variation_ids[1] )->get_price(), 'The variation charges the range price.' );
	}

	/**
	 * @testdox Should keep a variation's sale in the range in the selected currency.
	 *
	 * Core treats a variation as on sale only while its sale price equals its active price, so all three convert alike.
	 */
	public function test_keeps_a_variation_sale_in_the_range_in_the_selected_currency(): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '35' );
		update_option( 'wcpay_multi_currency_price_rounding_jpy', '0.00' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'JPY' );
		$this->register_storefront_controllers();

		list( $product, $variation_ids ) = $this->create_variable_product( array( '20.37' ) );
		$variation                       = wc_get_product( $variation_ids[0] );
		$variation->set_sale_price( '10.37' );
		$variation->save();
		\WC_Product_Variable::sync( $product->get_id() );
		$prices = wc_get_product( $product->get_id() )->get_variation_prices( true );

		$this->assertSame( array( $variation_ids[0] => '363' ), $prices['price'] );
		$this->assertSame( array( $variation_ids[0] => '713' ), $prices['regular_price'] );
		$this->assertSame( array( $variation_ids[0] => '363' ), $prices['sale_price'] );
	}

	/**
	 * @testdox Should round a variable product's range to the rounding step before adding tax, as the simple product and the cart do.
	 *
	 * The prices are a recorded store run's: a PLN store, EUR at a manual 0.9 with the default 1.00 step, 20% tax shown
	 * including tax, a 12 PLN variation and a 12 PLN simple product. The run showed the range at 13,00 EUR (tax added first:
	 * 14.40 x 0.9 = 12.96, up to 13.00) and the simple product at 13,20 EUR (12 x 0.9 = 10.80, up to 11.00, plus 2.20 tax),
	 * which the cart charged for either.
	 */
	public function test_rounds_a_variable_range_before_adding_tax(): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'EUR' );
		$this->register_storefront_controllers();

		list( $product, $variation_ids ) = $this->create_variable_product( array( '12', '12' ) );
		$simple                          = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '12' ) );

		$this->assertSame( array_combine( $variation_ids, array( '13.20', '13.20' ) ), $product->get_variation_prices( true )['price'] );
		$this->assertSame( 13.2, wc_get_price_to_display( $simple ), 'The simple product shows the same price.' );
	}

	/**
	 * @testdox Should not show a variable range cached for another currency or older conversion settings.
	 *
	 * Core caches the range's prices across requests under the variation price hash, and each step below reloads the product
	 * and reads that cache. EUR at 0.22 or 0.30 and GBP at 0.19, all with the default 1.00 step, convert 1 PLN to the same
	 * 1.00; then only the rounding step changes, then only the charm; then JPY takes EUR's rate, step and charm and differs
	 * only in its decimals. Last, with no rounding, JPY rounds the conversion to its own 0 decimals while an EUR order page
	 * formats it with EUR's 2, the same decimals an EUR shopper's range uses. So the hash must name the currency, its rate,
	 * its rounding step and its charm.
	 */
	public function test_does_not_show_a_variable_range_cached_for_another_currency_or_rate(): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'EUR', 'GBP', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.22' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.19' );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '0.30' );
		update_option( 'wcpay_multi_currency_price_rounding_jpy', '0.50' );
		update_option( 'wcpay_multi_currency_price_charm_jpy', '-0.01' );
		$selected = 'EUR';
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			function () use ( &$selected ) {
				return $selected;
			}
		);
		$currencies                      = $this->register_storefront_controllers();
		list( $product, $variation_ids ) = $this->create_variable_product( array( '10.37', '20.37' ) );

		$this->assertSame( array_combine( $variation_ids, array( '3.00', '5.00' ) ), $product->get_variation_prices( true )['price'], 'EUR at 0.22: 2.28 and 4.48, up to 3 and 5.' );

		$selected = 'GBP';
		$this->assertSame( array_combine( $variation_ids, array( '2.00', '4.00' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'GBP at 0.19: 1.97 and 3.87, up to 2 and 4.' );

		$selected = 'EUR';
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.30' );
		$this->assertSame( array_combine( $variation_ids, array( '4.00', '7.00' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'EUR at 0.30: 3.11 and 6.11, up to 4 and 7.' );

		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.50' );
		$this->assertSame( array_combine( $variation_ids, array( '3.50', '6.50' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'Only the step changes to 0.50: up to 3.50 and 6.50.' );

		update_option( 'wcpay_multi_currency_price_charm_eur', '-0.01' );
		$this->assertSame( array_combine( $variation_ids, array( '3.49', '6.49' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'Only the charm changes to -0.01: 3.49 and 6.49.' );

		$selected = 'JPY';
		$this->assertSame( array_combine( $variation_ids, array( '3', '6' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'JPY with EUR\'s rate, step and charm: 3.49 and 6.49 shown with no decimals.' );

		foreach ( array( 'eur', 'jpy' ) as $code ) {
			update_option( 'wcpay_multi_currency_price_rounding_' . $code, '0.00' );
			update_option( 'wcpay_multi_currency_price_charm_' . $code, '0' );
		}
		$on_eur_order_page = $currencies->format_in_order_currency(
			'EUR',
			fn() => implode( '|', wc_get_product( $product->get_id() )->get_variation_prices( true )['price'] )
		);
		$this->assertSame( '3.00|6.00', $on_eur_order_page, 'JPY with no rounding converts to 3 and 6, shown with the EUR order\'s decimals.' );

		$selected = 'EUR';
		$this->assertSame( array_combine( $variation_ids, array( '3.11', '6.11' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'], 'EUR at the same rate with no rounding: 3.11 and 6.11, with the same two decimals.' );
	}

	/**
	 * @testdox Should not show a variable range cached while an order page formatted prices in the order's currency.
	 *
	 * The thank-you and view-order pages format prices in the order's currency for the rest of the request, so a range built
	 * there, by a cross-sell for example, rounds to a JPY order's decimals while it converts to the shopper's EUR.
	 */
	public function test_does_not_show_a_variable_range_cached_with_an_order_currency_decimals(): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'EUR', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.00' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'EUR' );
		$currencies                      = $this->register_storefront_controllers();
		list( $product, $variation_ids ) = $this->create_variable_product( array( '10.37', '20.37' ) );

		$on_order_page = $currencies->format_in_order_currency(
			'JPY',
			fn() => implode( '|', $product->get_variation_prices( true )['price'] )
		);
		$this->assertSame( '9|18', $on_order_page, 'On the JPY order page 9.33 and 18.33 EUR round to no decimals.' );

		$this->assertSame( array_combine( $variation_ids, array( '9.33', '18.33' ) ), wc_get_product( $product->get_id() )->get_variation_prices( true )['price'] );
	}

	/**
	 * @testdox Should skip shipping cost conversion when the shipping method filter returns false.
	 */
	public function test_skips_shipping_cost_conversion_when_shipping_method_filter_returns_false(): void {
		$sut             = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$shipping_method = new \WC_Shipping_Flat_Rate( 0 );

		$sut->register();

		$this->assertSame(
			array( 'cost' => 6.0 ),
			apply_filters( 'woocommerce_shipping_method_add_rate_args', array( 'cost' => '2.00' ), $shipping_method )
		);

		add_filter(
			'wcpay_multi_currency_should_convert_shipping_amount',
			function ( $should_convert, $method ) use ( $shipping_method ) {
				$this->assertTrue( $should_convert );
				$this->assertSame( $shipping_method, $method );

				return false;
			},
			10,
			2
		);

		$this->assertSame(
			array( 'cost' => '2.00' ),
			apply_filters( 'woocommerce_shipping_method_add_rate_args', array( 'cost' => '2.00' ), $shipping_method )
		);
	}

	/**
	 * @testdox Should evaluate a flat-rate cost formula on the store-currency package cost and convert the rate once: $cost.
	 *
	 * The amounts are a recorded store run's: PLN store, EUR at a manual 0.22 with no rounding, a 2000 PLN product shown as
	 * a 440.00 EUR cart line. Core substitutes the package's converted contents cost into `[cost]` and `[fee]` and compares
	 * `min_fee`/`max_fee`, typed in store units, with it; the evaluated rate is then converted on `woocommerce_shipping_method_add_rate_args`.
	 *
	 * @dataProvider provider_flat_rate_cost_formulas
	 *
	 * @param string $cost           Flat-rate cost as the merchant types it, in store units.
	 * @param bool   $convert        Whether the shipping method lets Multi-Currency convert its cost.
	 * @param float  $expected_rate  Rate the shopper pays, in EUR.
	 */
	public function test_evaluates_flat_rate_cost_formulas_in_the_store_currency( string $cost, bool $convert, float $expected_rate ): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.22' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.00' );
		update_option(
			'woocommerce_flat_rate_7_settings',
			array(
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => $cost,
				'type'       => 'class',
			)
		);
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'EUR' );
		if ( ! $convert ) {
			add_filter( 'wcpay_multi_currency_should_convert_shipping_amount', '__return_false' );
		}

		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			wc_get_container()->get( ServiceFactory::class ),
			wc_get_container()->get( RequestContext::class )
		);
		$sut->register();

		$shipping_method = new \WC_Shipping_Flat_Rate( 7 );
		$shipping_method->calculate_shipping(
			array(
				'contents'      => array(),
				'contents_cost' => 440.0,
			)
		);

		$this->assertArrayHasKey( 'flat_rate:7', $shipping_method->rates );
		$this->assertSame( $expected_rate, (float) $shipping_method->rates['flat_rate:7']->get_cost() );
	}

	/**
	 * Flat-rate cost formulas with the rate a recorded store run expects.
	 *
	 * @return array<string,array{0:string,1:bool,2:float}>
	 */
	public function provider_flat_rate_cost_formulas(): array {
		return array(
			'percent fee: (10 + 10% of 2000) x 0.22'       => array( '10 + [fee percent="10"]', true, 46.2 ),
			'cost formula: 10% of 2000 x 0.22'             => array( '[cost] * 0.1', true, 44.0 ),
			'minimum fee below the fee: (10 + 200) x 0.22' => array( '10 + [fee percent="10" min_fee="100"]', true, 46.2 ),
			'maximum fee caps the fee: (10 + 100) x 0.22'  => array( '10 + [fee percent="10" max_fee="100"]', true, 24.2 ),
			'plain rate: 10 x 0.22'                        => array( '10', true, 2.2 ),
			'method keeps its cost: 10% of 440.00 EUR'     => array( '[cost] * 0.1', false, 44.0 ),
		);
	}

	/**
	 * @testdox Should round a store-currency percent fee to at least the store's decimals: $store_currency store, $selected_currency selected, $cost.
	 *
	 * Core's `fee()` rounds the percent fee with `wc_get_price_decimals()`, which is the selected currency's on the storefront.
	 * Once the formula works in store units, the fee needs the store's decimals: 10% of a ¥1,556 cart on a USD store at 150
	 * is $1.04, so ¥156, not $1 and ¥150. A selected currency with more decimals than the store keeps them: 10% of a $13.44
	 * cart on a JPY store at 0.0067 is ¥200.60, so $1.34, not ¥201 and $1.35. A fee the method keeps in the selected
	 * currency keeps the selected decimals.
	 *
	 * @testWith ["USD", "2", "JPY", "150", "[fee percent=\"10\"]", 1556.0, true, "156"]
	 *           ["JPY", "0", "USD", "0.0067", "[fee percent=\"10\"]", 13.47, true, "1.35"]
	 *           ["JPY", "0", "USD", "0.0067", "[fee percent=\"10\"]", 13.44, true, "1.34"]
	 *           ["USD", "2", "JPY", "150", "[fee percent=\"10\"] * 10", 1553.0, false, "1550"]
	 *
	 * @param string $store_currency    Store currency.
	 * @param string $store_decimals    Store price decimals.
	 * @param string $selected_currency Currency the shopper selected.
	 * @param string $rate              Manual rate of the selected currency, with no rounding.
	 * @param string $cost              Flat-rate cost as the merchant types it.
	 * @param float  $contents_cost     Cart contents cost, in the selected currency.
	 * @param bool   $convert           Whether the shipping method lets Multi-Currency convert its cost.
	 * @param string $expected_cost     Rate cost the shopper pays.
	 */
	public function test_rounds_a_store_currency_percent_fee_to_the_store_decimals( string $store_currency, string $store_decimals, string $selected_currency, string $rate, string $cost, float $contents_cost, bool $convert, string $expected_cost ): void {
		$code = strtolower( $selected_currency );
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( $store_currency, $selected_currency ) );
		update_option( 'wcpay_multi_currency_exchange_rate_' . $code, 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_' . $code, $rate );
		update_option( 'wcpay_multi_currency_price_rounding_' . $code, '0.00' );
		update_option(
			'woocommerce_flat_rate_7_settings',
			array(
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => $cost,
				'type'       => 'class',
			)
		);
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => $selected_currency );
		if ( ! $convert ) {
			add_filter( 'wcpay_multi_currency_should_convert_shipping_amount', '__return_false' );
		}
		$this->register_storefront_controllers();

		$shipping_method = new \WC_Shipping_Flat_Rate( 7 );
		$shipping_method->calculate_shipping(
			array(
				'contents'      => array(),
				'contents_cost' => $contents_cost,
			)
		);

		$this->assertSame( $expected_cost, $shipping_method->rates['flat_rate:7']->get_cost() );
		$this->assertSame( 'JPY' === $selected_currency ? 0 : 2, wc_get_price_decimals(), 'Outside the fee the selected decimals apply.' );
	}

	/**
	 * @testdox Should round a later fee the same method keeps in the selected currency to the selected decimals.
	 */
	public function test_rounds_a_later_selected_currency_fee_of_the_same_method_to_the_selected_decimals(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		update_option( 'wcpay_multi_currency_price_rounding_jpy', '0.00' );
		update_option(
			'woocommerce_flat_rate_7_settings',
			array(
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => '[fee percent="10"] * 10',
				'type'       => 'class',
			)
		);
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'JPY' );
		$this->register_storefront_controllers();
		$shipping_method = new \WC_Shipping_Flat_Rate( 7 );
		$package         = array(
			'contents'      => array(),
			'contents_cost' => 1553.0,
		);

		$shipping_method->calculate_shipping( $package );
		$this->assertSame( '1560', $shipping_method->rates['flat_rate:7']->get_cost(), '10% of $10.3533 is $1.04, times 10 is $10.40, ¥1,560.' );

		add_filter( 'wcpay_multi_currency_should_convert_shipping_amount', '__return_false' );
		$shipping_method->rates = array();
		$shipping_method->calculate_shipping( $package );
		$this->assertSame( '1550', $shipping_method->rates['flat_rate:7']->get_cost(), '10% of ¥1,553 is ¥155, times 10 is ¥1,550.' );
	}

	/**
	 * @testdox Should read a shipping fee's numbers in the store's number format while another currency is selected: $_dataName.
	 *
	 * A USD store with a comma decimal separator and dot thousands, GBP at a manual 0.8 with no rounding, the cart contents in
	 * GBP. Expected values from client 11.3.0: 2.5% of $1000 is $25, so 20.00 GBP
	 * (`tests/unit/multi-currency/test-class-multi-currency-shipping-formula-conversion.php:234-240`); a method that keeps its
	 * cost in GBP reads the same separator (`test-class-frontend-prices.php:506-512`), so 2.5% of 800 GBP is 20.00 GBP; 10% of
	 * $225 is $22.50, under the $22.90 cap, so 18.00 GBP (shipping-formula test `:247-253`).
	 *
	 * @dataProvider provide_fee_numbers_in_the_store_format
	 *
	 * @param string $cost          Flat-rate cost as the merchant types it.
	 * @param float  $contents_cost Cart contents cost, in GBP.
	 * @param bool   $convert       Whether the shipping method lets Multi-Currency convert its cost.
	 * @param float  $expected      Rate the shopper pays, in GBP.
	 */
	public function test_reads_shipping_fee_numbers_in_the_store_number_format( string $cost, float $contents_cost, bool $convert, float $expected ): void {
		$this->select_gbp_on_a_comma_decimal_store();
		update_option(
			'woocommerce_flat_rate_7_settings',
			array(
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => $cost,
				'type'       => 'class',
			)
		);
		if ( ! $convert ) {
			add_filter( 'wcpay_multi_currency_should_convert_shipping_amount', '__return_false' );
		}
		$this->register_storefront_controllers();

		$shipping_method = new \WC_Shipping_Flat_Rate( 7 );
		$shipping_method->calculate_shipping(
			array(
				'contents'      => array(),
				'contents_cost' => $contents_cost,
			)
		);

		$this->assertSame( $expected, (float) $shipping_method->rates['flat_rate:7']->get_cost() );
	}

	/**
	 * Fee formulas typed in the store's number format, with the rate the client charges.
	 *
	 * @return array<string,array{0:string,1:float,2:bool,3:float}>
	 */
	public function provide_fee_numbers_in_the_store_format(): array {
		return array(
			'a percent with a comma'                   => array( '[fee percent="2,5"]', 800.0, true, 20.0 ),
			'a percent with a comma, cost kept in GBP' => array( '[fee percent="2,5"]', 800.0, false, 20.0 ),
			'a maximum fee with a comma above the fee' => array( '[fee percent="10" max_fee="22,9"]', 180.0, true, 18.0 ),
		);
	}

	/**
	 * @testdox Should answer the store's decimal separator only inside a shipping method's fee, and only for another currency: $_dataName.
	 *
	 * As client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php:487-519`, with the same stand-in for the
	 * selected currency's dot separator that the currency filters would otherwise give.
	 *
	 * @testWith ["GBP", true, [","], "a shipping method's fee in GBP"]
	 *           ["USD", true, ["."], "a shipping method's fee in the store currency"]
	 *           ["GBP", false, ["."], "a fee no shipping method registered"]
	 *
	 * @param string   $selected        Selected currency.
	 * @param bool     $shipping_method Whether a shipping method registered the fee.
	 * @param string[] $expected        Separators read inside each fee.
	 * @param string   $label           Case label.
	 */
	public function test_answers_the_store_separator_only_inside_a_shipping_method_fee( string $selected, bool $shipping_method, array $expected, string $label ): void {
		unset( $label );
		$this->select_gbp_on_a_comma_decimal_store();
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => $selected );
		add_filter( 'wc_get_price_decimal_separator', fn() => '.', 900 );
		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ), wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ) );
		$sut->register();
		$method = $this->create_fee_reading_method();
		$seen   = array();

		add_shortcode(
			'fee',
			$shipping_method ? array( $method, 'fee' ) : function ( $atts ) use ( &$seen ) {
				shortcode_atts( array(), $atts, 'fee' );
				$seen[] = wc_get_price_decimal_separator();
				return '';
			}
		);
		do_shortcode( '[fee percent="2,5"]' );
		remove_shortcode( 'fee' );

		$this->assertSame( $expected, $shipping_method ? $method->seen : $seen );
		$this->assertSame( '.', wc_get_price_decimal_separator(), 'Outside the fee the selected currency\'s separator applies.' );
	}

	/**
	 * @testdox Should take the next cart calculation in the selected currency's format after a fee that threw was caught.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency-shipping-formula-conversion.php:338-385`: a USD store
	 * with a comma separator, JPY at 150, a $1000 item with a 10% fee; after a fee() override throws and its caller catches it,
	 * the next calculation totals 150,000 JPY of items plus 15,000 JPY of shipping, and a plugin that sets item prices when
	 * the calculation starts reads JPY's dot. The client's row removes the `fee` shortcode by hand after the catch; a real
	 * caught throw leaves it registered to the throwing method, so this row leaves it there, and the tear-down removes it.
	 */
	public function test_takes_the_next_cart_calculation_in_the_selected_format_after_a_fee_that_threw(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'JPY' );
		$this->register_storefront_controllers();
		$this->assertSame( 15000.0, $this->get_cart_shipping_total( '[fee percent="10"]', '1000' ) );
		$method = new class() extends \WC_Shipping_Flat_Rate {
			/**
			 * Read the attributes as core's fee() does, then fail.
			 *
			 * @param array $atts Attributes.
			 * @throws \RuntimeException Always.
			 */
			public function fee( $atts ) {
				shortcode_atts( array( 'percent' => '' ), $atts, 'fee' );
				throw new \RuntimeException( 'The fee could not be worked out.' );
			}

			/**
			 * Evaluate a cost formula.
			 *
			 * @param string $formula Cost formula.
			 * @param float  $cost    Package cost.
			 * @return string
			 */
			public function evaluate( string $formula, float $cost ) {
				return $this->evaluate_cost(
					$formula,
					array(
						'qty'  => 1,
						'cost' => $cost,
					)
				);
			}
		};

		try {
			$method->evaluate( '[fee percent="10"]', 15000 );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$separator_seen_by_pricing_plugins = null;
		add_action(
			'woocommerce_before_calculate_totals',
			function () use ( &$separator_seen_by_pricing_plugins ) {
				$separator_seen_by_pricing_plugins = wc_get_price_decimal_separator();
			}
		);
		WC()->session->__unset( 'shipping_for_package_0' );
		WC()->cart->calculate_totals();

		$this->assertSame( array( 165000.0, '.' ), array( (float) WC()->cart->get_total( 'edit' ), $separator_seen_by_pricing_plugins ) );
	}

	/**
	 * @testdox Should mark each shipping package with the selected currency in the extension's spelling: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php:120-147`: EUR at 0.9, rounding 0.25 and charm
	 * -0.01 is `EUR:0.9:0.25:-0.01:1`, with `0` last when charm also applies to shipping. A currency with no stored rounding
	 * or charm carries the client's defaults as its getters return them (`includes/multi-currency/MultiCurrency.php:2322-2325`,
	 * `includes/multi-currency/Currency.php:65-67`, `:121-123`): rounding `1.00` and charm `0`.
	 *
	 * @dataProvider provide_shipping_package_markers
	 *
	 * @param array<string,string> $options      Multi-Currency options of the selected currency.
	 * @param bool                 $charm_only   Whether charm applies to products only.
	 * @param string               $selected     Selected currency.
	 * @param string               $marker       Expected marker.
	 */
	public function test_marks_each_shipping_package_with_the_selected_currency( array $options, bool $charm_only, string $selected, string $marker ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', $selected ) );
		foreach ( $options as $option => $value ) {
			update_option( $option, $value );
		}
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => $selected );
		add_filter( 'wcpay_multi_currency_apply_charm_only_to_products', fn() => $charm_only );
		$this->create_real_controller()->register();

		$packages = apply_filters(
			'woocommerce_cart_shipping_packages',
			array(
				0 => array( 'contents' => array( 'a' ) ),
				1 => array( 'contents' => array( 'b' ) ),
			)
		);

		$this->assertSame(
			array(
				0 => array(
					'contents'             => array( 'a' ),
					'wcpay_multi_currency' => $marker,
				),
				1 => array(
					'contents'             => array( 'b' ),
					'wcpay_multi_currency' => $marker,
				),
			),
			$packages
		);
	}

	/**
	 * Selected currencies with the marker the client builds for them.
	 *
	 * @return array<string,array{0:array<string,string>,1:bool,2:string,3:string}>
	 */
	public function provide_shipping_package_markers(): array {
		$eur = array(
			'wcpay_multi_currency_exchange_rate_eur'  => 'manual',
			'wcpay_multi_currency_manual_rate_eur'    => '0.9',
			'wcpay_multi_currency_price_rounding_eur' => '0.25',
			'wcpay_multi_currency_price_charm_eur'    => '-0.01',
		);

		return array(
			'charm on products only'      => array( $eur, true, 'EUR', 'EUR:0.9:0.25:-0.01:1' ),
			'charm on shipping too'       => array( $eur, false, 'EUR', 'EUR:0.9:0.25:-0.01:0' ),
			'no stored rounding or charm' => array(
				array(
					'wcpay_multi_currency_exchange_rate_gbp' => 'manual',
					'wcpay_multi_currency_manual_rate_gbp' => '0.8',
				),
				true,
				'GBP',
				'GBP:0.8:1.00:0:1',
			),
		);
	}

	/**
	 * @testdox Should leave the shipping packages untouched where it adds no marker: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php:149-182`: anything but an array, a package that
	 * is not an array and an empty package are left as they are; in the store currency the packages stay identical, so they
	 * hash as on a store without Multi-Currency.
	 *
	 * @dataProvider provide_unmarked_shipping_packages
	 *
	 * @param string $selected Selected currency.
	 * @param mixed  $packages Packages the filter receives.
	 * @param mixed  $expected Packages the filter returns.
	 */
	public function test_leaves_the_shipping_packages_untouched_where_it_adds_no_marker( string $selected, $packages, $expected ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => $selected );
		$this->create_real_controller()->register();

		$this->assertSame( $expected, apply_filters( 'woocommerce_cart_shipping_packages', $packages ) );
	}

	/**
	 * Packages the filter leaves untouched.
	 *
	 * @return array<string,array{0:string,1:mixed,2:mixed}>
	 */
	public function provide_unmarked_shipping_packages(): array {
		$marked = array(
			'contents'             => array( 'a' ),
			'wcpay_multi_currency' => 'EUR:0.9:1.00:0:1',
		);

		return array(
			'not an array'                   => array( 'EUR', 'not-an-array', 'not-an-array' ),
			'a package that is not an array' => array( 'EUR', array( array( 'contents' => array( 'a' ) ), 'skip-me' ), array( $marked, 'skip-me' ) ),
			'an empty package'               => array( 'EUR', array( array() ), array( array() ) ),
			'the store currency'             => array( 'USD', array( array( 'contents' => array( 'a' ) ) ), array( array( 'contents' => array( 'a' ) ) ) ),
		);
	}

	/**
	 * @testdox Should build a fresh shipping rate when a signed-in shopper switches currency on the account page after the cart loaded.
	 *
	 * The account page saves the default currency on `template_redirect`, after the cart has loaded, through the persistence
	 * service, which recalculates at once. A zero-priced shippable line hashes alike in both currencies, so without the
	 * marker the 9.00 rate built in EUR at 0.9 was reused as 9.00 USD. The extension marks the package
	 * (client 11.3.0 `includes/multi-currency/FrontendPrices.php:587-627`), so its rate is built again: the 10.00 flat rate.
	 */
	public function test_builds_a_fresh_shipping_rate_after_an_account_page_currency_switch(): void {
		global $wp_actions;
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.00' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->register_storefront_controllers();
		$persistence = wc_get_container()->get( ServiceFactory::class )->create_selected_currency_persistence_service();
		$loaded      = $wp_actions['woocommerce_cart_loaded_from_session'] ?? null;
		// The cart has loaded on both requests, so each switch recalculates at once.
		$wp_actions['woocommerce_cart_loaded_from_session'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.

		try {
			$persistence->update_selected_currency( 'EUR' );
			$this->assertSame( 9.0, $this->get_cart_shipping_total( '10', '0' ), 'The rate is built in EUR.' );

			$persistence->update_selected_currency( 'USD' );
		} finally {
			$wp_actions['woocommerce_cart_loaded_from_session'] = $loaded; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the count read above.
		}

		$this->assertSame( array( 'USD', 10.0 ), array( get_woocommerce_currency(), (float) WC()->cart->get_shipping_total() ) );
	}

	/**
	 * @testdox Should respect conversion guards for product and coupon prices.
	 */
	public function test_respects_conversion_guards_for_product_and_coupon_prices(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		add_filter( 'wcpay_multi_currency_should_convert_product_price', '__return_false' );
		add_filter( 'wcpay_multi_currency_should_convert_coupon_amount', '__return_false' );

		$this->assertSame( '10.00', $sut->get_product_price_string( '10.00', (object) array() ) );
		$this->assertSame( '0', $sut->get_product_price_string( 0, (object) array() ) );
		$this->assertSame( '5.00', $sut->get_coupon_amount( '5.00', $this->create_coupon() ) );
		$this->assertSame( '5.00', $sut->get_coupon_amount( '5.00', $this->create_coupon( true ) ) );
		$this->assertSame( 0, $sut->get_coupon_min_max_amount( 0 ) );
	}

	/**
	 * @testdox Should convert a coupon's spend limit only when the spend limit filter allows it: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php:931-949` and `includes/multi-currency/Compatibility.php:178-193`:
	 * a filter answering false keeps `'5.0'` and receives the coupon; a true answer converts the limit as a product price (the
	 * client's mock answers 12.5; this fixture converts products by 2.0); without a coupon the filter is not asked.
	 *
	 * @dataProvider provide_coupon_spend_limit_filter_answers
	 *
	 * @param bool         $with_coupon Whether the getter passes a coupon.
	 * @param bool         $answer      The filter's answer.
	 * @param string|float $expected    Expected spend limit; a string is the unconverted limit, a number the converted one.
	 * @param bool         $asked       Whether the filter is asked.
	 */
	public function test_converts_a_coupon_spend_limit_only_when_the_filter_allows( bool $with_coupon, bool $answer, $expected, bool $asked ): void {
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$coupon   = $with_coupon ? $this->create_coupon() : null;
		$received = array();
		add_filter(
			'wcpay_multi_currency_should_convert_coupon_min_max_amount',
			static function ( $should_convert, $filtered_coupon ) use ( $answer, &$received ) {
				unset( $should_convert );
				$received[] = $filtered_coupon;

				return $answer;
			},
			10,
			2
		);

		$this->assertSame( $expected, $sut->get_coupon_min_max_amount( '5.0', $coupon ) );
		$this->assertSame( $asked ? array( $coupon ) : array(), $received, 'The filter receives the coupon.' );
	}

	/**
	 * Spend limit filter answers: with or without a coupon, the answer, the expected limit, and whether the filter is asked.
	 *
	 * @return array<string,array{0:bool,1:bool,2:string|float,3:bool}>
	 */
	public function provide_coupon_spend_limit_filter_answers(): array {
		return array(
			'the filter keeps the limit'         => array( true, false, '5.0', true ),
			'the filter converts the limit'      => array( true, true, 10.0, true ),
			'no coupon, the filter is not asked' => array( false, false, 10.0, false ),
		);
	}

	/**
	 * @testdox Should convert a coupon an order re-applies in the order's currency: $_dataName.
	 * @dataProvider provide_reapplied_coupons
	 *
	 * A USD store with JPY at 150 and EUR at 0.9 enabled, EUR selected, and a JPY order re-applying its coupon. Rows from
	 * client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php`: a live coupon (`:646-688`), a coupon rebuilt
	 * with no stored amount (`:732-751`), one rebuilt from legacy `coupon_data` as an array or an object (`:753-799`), one
	 * rebuilt from `coupon_info` (`includes/multi-currency/FrontendPrices.php:546-563`), another coupon (`:806-820`), a
	 * standard REST order write (`:822-851`) and the Store API (`:853-888`); a percent coupon and the should-convert filter
	 * are asked first, as before.
	 *
	 * @param string              $kind     Coupon: 'live', 'rebuilt' (virtual), 'percent', or 'another' read after a live one.
	 * @param array<string,mixed> $meta     Coupon line meta.
	 * @param string              $path     Request path.
	 * @param bool                $convert  What the should-convert filter answers.
	 * @param string              $amount   Amount the coupon reads.
	 * @param string|float        $expected Amount after the filter.
	 */
	public function test_converts_a_reapplied_coupon_in_the_order_currency( string $kind, array $meta, string $path, bool $convert, string $amount, $expected ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'JPY', 'EUR' ) );
		foreach ( array(
			'jpy' => '150',
			'eur' => '0.9',
		) as $code => $rate ) {
			update_option( 'wcpay_multi_currency_exchange_rate_' . $code, 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_' . $code, $rate );
		}
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR' );
		if ( ! $convert ) {
			add_filter( 'wcpay_multi_currency_should_convert_coupon_amount', '__return_false' );
		}
		$order = wc_create_order();
		$order->set_currency( 'JPY' );
		$coupon = $this->create_coupon( 'percent' === $kind );
		$coupon->set_virtual( 'rebuilt' === $kind );
		$coupon_item = new \WC_Order_Item_Coupon();
		foreach ( $meta as $key => $value ) {
			$coupon_item->add_meta_data( $key, $value );
		}
		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ), wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ) );
		$original_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Restored below.

		try {
			$_SERVER['REQUEST_URI'] = $path;

			$this->assertSame( $coupon, $sut->set_order_coupon( $coupon, 'code', $coupon_item, $order ) );
			$read = 'another' === $kind ? $this->create_coupon() : $coupon;
			$this->assertSame( $expected, $sut->get_coupon_amount( $amount, $read ) );
		} finally {
			if ( null === $original_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_uri;
			}
		}
	}

	/** @return array<string,array{string,array<string,mixed>,string,bool,string,string|float}> */
	public static function provide_reapplied_coupons(): array {
		$legacy = array(
			'id'            => 0,
			'code'          => 'legacy5',
			'discount_type' => 'fixed_cart',
			'amount'        => 5,
		);
		$info   = array(
			'coupon_info' => '[0,"legacy5","fixed_cart",750]',
			'coupon_data' => $legacy,
		);

		return array(
			'a live coupon'                          => array( 'live', array(), '/', true, '10', 1500.0 ),
			'a coupon rebuilt with no stored amount' => array( 'rebuilt', array(), '/', true, '1500', '1500' ),
			'rebuilt from legacy data, an array'     => array( 'rebuilt', array( 'coupon_data' => $legacy ), '/', true, '5', 750.0 ),
			'rebuilt from legacy data, an object'    => array( 'rebuilt', array( 'coupon_data' => (object) $legacy ), '/', true, '5', 750.0 ),
			'rebuilt from coupon info'               => array( 'rebuilt', $info, '/', true, '750', '750' ),
			'another coupon'                         => array( 'another', array(), '/', true, '10', 9.0 ),
			'a standard REST order write'            => array( 'live', array(), '/wp-json/wc/v3/orders', true, '10', 9.0 ),
			'the Store API'                          => array( 'live', array(), '/wp-json/wc/store/v1/checkout/1', true, '10', 1500.0 ),
			'a percent coupon'                       => array( 'percent', array(), '/', true, '10', '10' ),
			'the should-convert filter off'          => array( 'live', array(), '/', false, '10', '10' ),
		);
	}

	/**
	 * @testdox Should take a fixed coupon re-applied to a USD order in dollars while JPY is selected.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-frontend-prices.php:890-925`: four 10.00 lines and 10.00 shipping
	 * with a saved 5.00 coupon give a 5.00 discount and a 45.00 total, where the selection's rate took 750 off.
	 */
	public function test_takes_a_reapplied_coupon_in_the_order_currency_while_another_is_selected(): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'take5' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5 );
		$coupon->save();
		$order       = \WC_Helper_Order::create_order();
		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->set_code( 'take5' );
		$order->add_item( $coupon_item );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'JPY' );
		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ), wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ) );
		$sut->register();

		$order->recalculate_coupons();

		$this->assertSame( 'USD', $order->get_currency() );
		$this->assertEquals( 5, $order->get_discount_total() );
		$this->assertEquals( 45, $order->get_total() );
	}

	/**
	 * @testdox Should leave the Multi-Currency meta of an order Subscriptions creates to the subscription's copy.
	 *
	 * WooCommerce Subscriptions 9.3.0 creates renewal orders, the early renewal modal's included, with `created_via`
	 * `subscription` in the browsing currency, then copies the subscription's currency and meta onto them
	 * (`includes/core/wcs-order-functions.php:236-242`). A recorded store run stamped a store-currency renewal with the
	 * browsing currency's rate.
	 */
	public function test_leaves_the_meta_of_an_order_subscriptions_creates_to_the_subscription(): void {
		$sut   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$order = wc_create_order( array( 'created_via' => 'subscription' ) );
		$order->set_currency( 'GBP' );
		$order->save();

		$sut->add_order_meta( $order->get_id(), $order );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( '', $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
	}

	/**
	 * @testdox Should store the order's exchange rate at full precision, so Analytics counts the order at that rate: $store_currency store, $order_currency at $rate.
	 *
	 * The rates and amounts are a recorded store run's orders. Analytics divides the order's net, shipping and tax by the
	 * stored rate when no Stripe rate exists; the run stored 0.01 and 0.92 and counted net 1400 and 2007.61.
	 *
	 * @testWith ["JPY", "0", "USD", "0.0066667", 14.0, 4.0, 2100.0, 600.0]
	 *           ["PLN", "2", "EUR", "0.9234", 1847.0, 462.0, 2000.22, 500.32]
	 *
	 * @param string $store_currency    Store currency.
	 * @param string $store_decimals    Store price decimals.
	 * @param string $order_currency    Currency the shopper selected and the order is in.
	 * @param string $rate              Manual rate of the order currency.
	 * @param float  $net_total         Order net total, in the order currency.
	 * @param float  $shipping_total    Order shipping total, in the order currency.
	 * @param float  $expected_net      Net total Analytics counts, in the store currency.
	 * @param float  $expected_shipping Shipping total Analytics counts, in the store currency.
	 */
	public function test_stores_the_order_exchange_rate_at_full_precision( string $store_currency, string $store_decimals, string $order_currency, string $rate, float $net_total, float $shipping_total, float $expected_net, float $expected_shipping ): void {
		$code = strtolower( $order_currency );
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( $store_currency, $order_currency ) );
		update_option( 'wcpay_multi_currency_exchange_rate_' . $code, 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_' . $code, $rate );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => $order_currency );

		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			wc_get_container()->get( ServiceFactory::class ),
			wc_get_container()->get( RequestContext::class )
		);
		$order = wc_create_order();
		$order->set_currency( $order_currency );
		$order->save();

		$sut->add_order_meta( $order->get_id(), $order );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $rate, $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
		$this->assertSame( $store_currency, $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );

		$stats = wc_get_container()->get( ServiceFactory::class )->create_analytics_service()->convert_order_stats_data(
			array(
				'net_total'      => $net_total,
				'shipping_total' => $shipping_total,
				'tax_total'      => 0.0,
				'total_sales'    => $net_total + $shipping_total,
			),
			$order
		);

		$this->assertSame( $expected_net, $stats['net_total'] );
		$this->assertSame( $expected_shipping, $stats['shipping_total'] );
	}

	/**
	 * @testdox Should convert free shipping minimums and persist projected order meta.
	 */
	public function test_converts_free_shipping_minimums_and_persists_order_meta(): void {
		$sut               = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$free_shipping     = (object) array(
			'id'         => 'free_shipping',
			'min_amount' => '10.00',
		);
		$flat_rate         = (object) array(
			'id'         => 'flat_rate',
			'min_amount' => '10.00',
		);
		$converted_methods = $sut->convert_free_shipping_method_min_amount( array( $free_shipping, $flat_rate ) );
		$order             = wc_create_order();
		$order_id          = $order->get_id();
		$order->set_currency( 'GBP' );
		$order->save();

		$sut->add_order_meta( $order_id, $order );
		$order = wc_get_order( $order_id );

		$this->assertSame( 20.0, $converted_methods[0]->min_amount );
		$this->assertSame( '10.00', $converted_methods[1]->min_amount );
		$this->assertSame( '0.82', $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
		$this->assertSame( 'USD', $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
	}

	/**
	 * @testdox Should persist projected multi-currency refund meta.
	 */
	public function test_persists_exchange_rate_refund_meta(): void {
		$sut   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$order = wc_create_order();
		$order->set_currency( 'GBP' );
		$order->set_total( 100 );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 10,
				'reason'   => 'Multi-currency refund meta test',
			)
		);

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$sut->add_refund_meta( $order->get_id(), $refund->get_id() );

		$refund = wc_get_order( $refund->get_id() );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( '0.82', $refund->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
		$this->assertSame( 'USD', $refund->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
		$this->assertSame( '1.25', $refund->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
	}

	/**
	 * @testdox Should skip refund meta when no projected candidates exist.
	 */
	public function test_skips_refund_meta_when_no_candidates_exist(): void {
		$sut   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( 100 );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 10,
				'reason'   => 'Default currency refund meta test',
			)
		);

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$sut->add_refund_meta( $order->get_id(), $refund->get_id() );
		$sut->add_refund_meta( 0, $refund->get_id() );
		$sut->add_refund_meta( $order->get_id(), 0 );

		$refund = wc_get_order( $refund->get_id() );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
		$this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
		$this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
	}

	/**
	 * @testdox Should convert Store API and query-loop price ranges.
	 */
	public function test_converts_store_api_and_query_loop_price_ranges(): void {
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$response = new WP_REST_Response(
			array(
				'price_range' => (object) array(
					'min_price' => '10.00',
					'max_price' => '12.00',
				),
			)
		);
		$request  = new WP_REST_Request( 'GET', '/wc/store/v1/products/collection-data' );
		$query    = array(
			'post_type'  => 'product',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'     => '_price',
					'value'   => '8.2',
					'compare' => '>=',
				),
				array(
					'key'     => '_price',
					'value'   => '8.21',
					'compare' => '<=',
				),
			),
		);

		$response = $sut->maybe_modify_price_ranges_rest_response( $response, null, $request );
		$query    = $sut->maybe_modify_price_ranges_query_var( $query, null, 1 );

		$data = $response->get_data();
		$this->assertSame( '20', $data['price_range']->min_price );
		$this->assertSame( '24', $data['price_range']->max_price );
		$this->assertSame( 'floor-8.2', $query['meta_query'][0]['value'] );
		$this->assertSame( 'ceil-8.21', $query['meta_query'][1]['value'] );
	}

	/**
	 * Create a frontend prices controller with a static runtime owner.
	 *
	 * @param string              $owner                  Runtime owner.
	 * @param bool                $should_convert_queries Whether query price filters should project.
	 * @param RequestContext|null $request_context Request context.
	 * @return MultiCurrencyFrontendPricesController
	 */
	private function create_controller(
		string $owner,
		bool $should_convert_queries = true,
		?RequestContext $request_context = null
	): MultiCurrencyFrontendPricesController {
		$controller = new MultiCurrencyFrontendPricesController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter( $should_convert_queries ) ) ),
			$request_context ?? wc_get_container()->get( RequestContext::class )
		);

		return $controller;
	}

	/**
	 * Create a static multi-currency runtime arbiter.
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
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register price/currency hooks.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}

	/**
	 * Create a deterministic price projection service.
	 *
	 * @param bool $should_convert_queries Whether query price filters should project.
	 * @return PriceConverter
	 */
	private function create_price_converter( bool $should_convert_queries ): PriceConverter {
		return new class( $should_convert_queries ) extends PriceConverter {
			/**
			 * Whether query price filters should project.
			 *
			 * @var bool
			 */
			private bool $should_convert_queries;

			/**
			 * Constructor.
			 *
			 * @param bool $should_convert_queries Whether query price filters should project.
			 */
			public function __construct( bool $should_convert_queries ) {
				$this->should_convert_queries = $should_convert_queries;
			}

			/**
			 * Project a converted price for the selected currency.
			 *
			 * @param mixed                      $price    Price.
			 * @param string                     $type     Price type.
			 * @param MultiCurrencyCurrency|null $currency Currency to convert to.
			 * @return float
			 */
			public function get_price( $price, string $type, ?MultiCurrencyCurrency $currency = null ): float {
				unset( $currency );
				$multipliers = array(
					'product'       => 2.0,
					'shipping'      => 3.0,
					'coupon'        => 4.0,
					'exchange_rate' => 0.5,
				);

				return (float) $price * ( $multipliers[ $type ] ?? 1.0 );
			}

			/**
			 * Project order meta candidates for a selected-currency order.
			 *
			 * @param string $order_currency Order currency code.
			 * @return array<string,float|string>
			 */
			public function get_order_meta_candidates( string $order_currency ): array {
				if ( 'GBP' !== strtoupper( $order_currency ) ) {
					return array();
				}

				return array(
					'_wcpay_multi_currency_order_exchange_rate'    => 0.82,
					'_wcpay_multi_currency_order_default_currency' => 'USD',
				);
			}

			/**
			 * Project refund meta candidates for a selected-currency order.
			 *
			 * @param \WC_Order $order Order.
			 * @return array<string,float|string>
			 */
			public function get_refund_meta_candidates( \WC_Order $order ): array {
				if ( 'GBP' !== strtoupper( $order->get_currency() ) ) {
					return array();
				}

				return array(
					'_wcpay_multi_currency_order_exchange_rate'    => 0.82,
					'_wcpay_multi_currency_order_default_currency' => 'USD',
					'_wcpay_multi_currency_stripe_exchange_rate'   => 1.25,
				);
			}

			/**
			 * Tell whether query price filters should be projected.
			 *
			 * @return bool
			 */
			public function should_convert_between_selected_and_default_currency(): bool {
				return $this->should_convert_queries;
			}

			/**
			 * Project a selected-currency price-filter value to default currency.
			 *
			 * @param mixed  $amount  Query amount.
			 * @param string $compare Query comparison operator.
			 * @return string
			 */
			public function get_price_filter_query_value( $amount, string $compare ): string {
				return '<=' === $compare ? 'ceil-' . (string) $amount : 'floor-' . (string) $amount;
			}
		};
	}

	/**
	 * Create a request context test double.
	 *
	 * @param bool $should_register_frontend_hooks Whether frontend hooks should register.
	 * @return RequestContext
	 */
	private function create_request_context( bool $should_register_frontend_hooks ): RequestContext {
		return new class( $should_register_frontend_hooks ) extends RequestContext {
			/**
			 * Whether frontend hooks should register.
			 *
			 * @var bool
			 */
			private bool $should_register_frontend_hooks;

			/**
			 * Constructor.
			 *
			 * @param bool $should_register_frontend_hooks Whether frontend hooks should register.
			 */
			public function __construct( bool $should_register_frontend_hooks ) {
				$this->should_register_frontend_hooks = $should_register_frontend_hooks;
			}

			/**
			 * Tell whether frontend hooks should register.
			 *
			 * @return bool
			 */
			public function should_register_frontend_hooks(): bool {
				return $this->should_register_frontend_hooks;
			}
		};
	}

	/**
	 * Register the price and currency controllers on the real services, as a storefront request does.
	 *
	 * @return MultiCurrencyFrontendCurrenciesController The registered currency controller.
	 */
	private function register_storefront_controllers(): MultiCurrencyFrontendCurrenciesController {
		$arbiter    = $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$currencies = new MultiCurrencyFrontendCurrenciesController();
		foreach ( array( new MultiCurrencyFrontendPricesController(), $currencies ) as $controller ) {
			$controller->init( $arbiter, wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ), wc_get_container()->get( OrderContextService::class ) );
			$controller->register();
		}

		return $currencies;
	}

	/**
	 * Create a prices controller on the real services, owned by the built-in module.
	 *
	 * @return MultiCurrencyFrontendPricesController
	 */
	private function create_real_controller(): MultiCurrencyFrontendPricesController {
		$sut = new MultiCurrencyFrontendPricesController();
		$sut->init( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ), wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ) );

		return $sut;
	}

	/**
	 * Select GBP, at a manual 0.8 with no rounding, on a USD store that writes numbers with a comma decimal separator.
	 */
	private function select_gbp_on_a_comma_decimal_store(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		update_option( 'wcpay_multi_currency_price_rounding_gbp', '0.00' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', fn() => 'GBP' );
	}

	/**
	 * Create a flat rate whose fee() reads its attributes as core's does, then records the decimal separator it sees.
	 *
	 * @return \WC_Shipping_Flat_Rate
	 */
	private function create_fee_reading_method(): \WC_Shipping_Flat_Rate {
		return new class() extends \WC_Shipping_Flat_Rate {
			/**
			 * Separators seen inside each fee.
			 *
			 * @var string[]
			 */
			public array $seen = array();

			/**
			 * Record the separator inside the fee.
			 *
			 * @param array $atts Attributes.
			 * @return string
			 */
			public function fee( $atts ) {
				shortcode_atts( array(), $atts, 'fee' );
				$this->seen[] = wc_get_price_decimal_separator();
				return '';
			}
		};
	}

	/**
	 * Calculate a real cart holding one shippable product, shipped at a rest-of-world flat rate, and return its shipping total.
	 *
	 * @param string $cost  Flat-rate cost as the merchant types it.
	 * @param string $price Product price, in the store currency.
	 * @return float
	 */
	private function get_cart_shipping_total( string $cost, string $price ): float {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_tax_status( 'none' );
		$product->set_virtual( false );
		$product->save();
		$zone        = \WC_Shipping_Zones::get_zone( 0 );
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		$zone->save();
		update_option(
			\WC_Shipping_Zones::get_shipping_method( $instance_id )->get_instance_option_key(),
			array(
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => $cost,
			)
		);
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		$this->customer_address = $this->customer_address ?? array(
			'shipping_country' => WC()->customer->get_shipping_country(),
			'shipping_state'   => WC()->customer->get_shipping_state(),
			'billing_country'  => WC()->customer->get_billing_country(),
		);
		WC()->customer->set_props(
			array(
				'shipping_country' => 'US',
				'shipping_state'   => 'NY',
				'billing_country'  => 'US',
			)
		);

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $instance_id ) );
		WC()->cart->calculate_totals();

		return (float) WC()->cart->get_shipping_total();
	}

	/**
	 * Create a variable product with one variation per price.
	 *
	 * @param string[] $prices Variation regular prices.
	 * @return array{0:\WC_Product_Variable,1:int[]} The product and its variation IDs, in price order.
	 */
	private function create_variable_product( array $prices ): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'L' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product = new \WC_Product_Variable();
		$product->set_name( 'Probe variable widget' );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$variation_ids = array();
		foreach ( array_values( $prices ) as $index => $price ) {
			$variation_ids[] = \WC_Helper_Product::create_product_variation_object( $product->get_id(), '', $price, array( 'size' => 0 === $index ? 'S' : 'L' ) )->get_id();
		}
		\WC_Product_Variable::sync( $product->get_id() );

		return array( wc_get_product( $product->get_id() ), $variation_ids );
	}

	/**
	 * Create an unsaved coupon.
	 *
	 * @param bool $is_percent Whether this is a percentage coupon.
	 * @return \WC_Coupon
	 */
	private function create_coupon( bool $is_percent = false ): \WC_Coupon {
		$coupon = new \WC_Coupon();
		$coupon->set_discount_type( $is_percent ? 'percent' : 'fixed_cart' );

		return $coupon;
	}
}
