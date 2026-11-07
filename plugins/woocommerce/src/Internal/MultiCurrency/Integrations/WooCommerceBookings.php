<?php
/**
 * WooCommerceBookings class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;

/**
 * Converts WooCommerce Bookings costs, and the prices its cost Ajax formats, to the selected currency.
 *
 * @since 11.0.0
 * @internal
 */
class WooCommerceBookings implements RegisterHooksInterface {

	private const CART_ADD_TO_CART_CALLS = array(
		'WC_Cart->add_to_cart',
	);

	private const BOOKINGS_COST_CALCULATION_CALLS = array(
		'WC_Bookings_Cost_Calculation::calculate_booking_cost',
	);

	private const BOOKING_PRICE_HTML_CALLS = array(
		'WC_Product_Booking->get_price_html',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var ServiceFactory
	 */
	private ServiceFactory $service_factory;

	/**
	 * Price converter, built on first use.
	 *
	 * @var PriceConverter|null
	 */
	private ?PriceConverter $price_converter = null;

	/**
	 * Display service, built on first use.
	 *
	 * @var DisplayService|null
	 */
	private ?DisplayService $display_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param ServiceFactory              $service_factory Multi-Currency service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, ServiceFactory $service_factory ): void {
		$this->arbiter         = $arbiter;
		$this->service_factory = $service_factory;
	}

	/**
	 * Register Bookings compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_bookings_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_bookings_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_bookings_filters' ), 20 );
	}

	/**
	 * Register Bookings compatibility filters after supported runtimes have loaded.
	 *
	 * Bookings prices convert on the storefront and in Ajax requests, not in wp-admin.
	 *
	 * @internal
	 */
	public function register_bookings_filters(): void {
		if (
			! $this->arbiter->is_builtin_owner()
			|| ! $this->is_bookings_runtime_available()
			|| ( is_admin() && ! wp_doing_ajax() )
		) {
			return;
		}

		add_filter( 'woocommerce_bookings_calculated_booking_cost', array( $this, 'adjust_amount_for_calculated_booking_cost' ), 50 );
		add_filter( 'woocommerce_product_get_block_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_product_get_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_product_get_display_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_product_booking_person_type_get_block_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_product_booking_person_type_get_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_product_get_resource_base_costs', array( $this, 'get_resource_prices' ), 50 );
		add_filter( 'woocommerce_product_get_resource_block_costs', array( $this, 'get_resource_prices' ), 50 );
		add_filter( StateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ), 50, 2 );
		add_filter( 'woocommerce_bookings_process_cost_rules_cost', array( $this, 'get_price' ), 50 );
		add_filter( 'woocommerce_bookings_process_cost_rules_base_cost', array( $this, 'get_price' ), 50 );
		add_action( 'wp_ajax_wc_bookings_calculate_costs', array( $this, 'add_wc_price_args_filter_for_ajax' ), 9 );
		add_action( 'wp_ajax_nopriv_wc_bookings_calculate_costs', array( $this, 'add_wc_price_args_filter_for_ajax' ), 9 );
	}

	/**
	 * Round and charm a calculated booking cost, whose parts get_price() already converted to the selected currency.
	 *
	 * @param mixed $costs Calculated booking costs.
	 * @return mixed
	 */
	public function adjust_amount_for_calculated_booking_cost( $costs ) {
		if ( RequestContext::is_call_in_backtrace( self::CART_ADD_TO_CART_CALLS ) ) {
			return $costs;
		}

		return $this->get_price_converter()->adjust_amount_for_selected_currency( $costs );
	}

	/**
	 * Convert a Bookings price for the selected currency.
	 *
	 * @param mixed $price Bookings price.
	 * @return mixed
	 */
	public function get_price( $price ) {
		if (
			! $price
			|| (
				RequestContext::is_call_in_backtrace( self::CART_ADD_TO_CART_CALLS )
				&& RequestContext::is_call_in_backtrace( self::BOOKINGS_COST_CALCULATION_CALLS )
			)
		) {
			return $price;
		}

		return $this->get_price_converter()->get_price(
			$price,
			RequestContext::is_call_in_backtrace( self::BOOKING_PRICE_HTML_CALLS ) ? 'product' : 'exchange_rate'
		);
	}

	/**
	 * Convert Bookings resource price arrays for the selected currency.
	 *
	 * @param mixed $prices Resource prices.
	 * @return mixed
	 */
	public function get_resource_prices( $prices ) {
		if ( ! is_array( $prices ) ) {
			return $prices;
		}

		foreach ( $prices as $key => $price ) {
			$prices[ $key ] = $this->get_price( $price );
		}

		return $prices;
	}

	/**
	 * Tell whether default product-price conversion should run for Bookings.
	 *
	 * @param bool  $should_convert Existing product conversion decision.
	 * @param mixed $product        Product object.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert, $product ): bool {
		if ( ! $should_convert || ! $product instanceof \WC_Product || 'booking' !== $product->get_type() ) {
			return $should_convert;
		}

		return ! RequestContext::is_call_in_backtrace( self::BOOKING_PRICE_HTML_CALLS );
	}

	/**
	 * Add selected-currency price args while Bookings Ajax calculates costs.
	 */
	public function add_wc_price_args_filter_for_ajax(): void {
		add_filter( 'wc_price_args', array( $this, 'filter_wc_price_args' ), 100 );
	}

	/**
	 * Set the selected currency and its format on the wc_price args of the Bookings cost Ajax.
	 *
	 * @param array<mixed> $args Existing wc_price args.
	 * @return array<mixed>
	 */
	public function filter_wc_price_args( array $args ): array {
		$display_service = $this->get_display_service();

		return wp_parse_args(
			array(
				'currency'           => $display_service->get_woocommerce_currency(),
				'decimal_separator'  => $display_service->get_price_decimal_separator( (string) ( $args['decimal_separator'] ?? wc_get_price_decimal_separator() ) ),
				'thousand_separator' => $display_service->get_price_thousand_separator( (string) ( $args['thousand_separator'] ?? wc_get_price_thousand_separator() ) ),
				'decimals'           => $display_service->get_price_decimals( absint( $args['decimals'] ?? wc_get_price_decimals() ) ),
				'price_format'       => $display_service->get_woocommerce_price_format( (string) ( $args['price_format'] ?? get_woocommerce_price_format() ) ),
			),
			$args
		);
	}

	/**
	 * Check if Bookings runtime is available.
	 *
	 * @return bool
	 */
	protected function is_bookings_runtime_available(): bool {
		return class_exists( 'WC_Bookings' );
	}

	/**
	 * Get the price converter.
	 *
	 * @return PriceConverter
	 */
	private function get_price_converter(): PriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = $this->service_factory->create_price_converter();
		}

		return $this->price_converter;
	}

	/**
	 * Get the display service.
	 *
	 * @return DisplayService
	 */
	private function get_display_service(): DisplayService {
		if ( null === $this->display_service ) {
			$this->display_service = $this->service_factory->create_display_service();
		}

		return $this->display_service;
	}
}
