<?php
/**
 * MultiCurrencyBookingsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Registers native multi-currency Bookings compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyBookingsCompatibilityController implements RegisterHooksInterface {

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
	 * Projection service factory.
	 *
	 * @var MultiCurrencyProjectionServiceFactory
	 */
	private MultiCurrencyProjectionServiceFactory $projection_service_factory;

	/**
	 * Price projection service.
	 *
	 * @var MultiCurrencyPriceProjectionService|null
	 */
	private ?MultiCurrencyPriceProjectionService $price_projection_service = null;

	/**
	 * Frontend projection service.
	 *
	 * @var MultiCurrencyFrontendProjectionService|null
	 */
	private ?MultiCurrencyFrontendProjectionService $frontend_projection_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter           $arbiter                    Runtime owner arbiter.
	 * @param MultiCurrencyProjectionServiceFactory $projection_service_factory Projection service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyProjectionServiceFactory $projection_service_factory ): void {
		$this->arbiter                    = $arbiter;
		$this->projection_service_factory = $projection_service_factory;
	}

	/**
	 * Set the price projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyPriceProjectionService $price_projection_service Price projection service.
	 */
	public function set_price_projection_service( MultiCurrencyPriceProjectionService $price_projection_service ): void {
		$this->price_projection_service = $price_projection_service;
	}

	/**
	 * Set the frontend projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyFrontendProjectionService $frontend_projection_service Frontend projection service.
	 */
	public function set_frontend_projection_service( MultiCurrencyFrontendProjectionService $frontend_projection_service ): void {
		$this->frontend_projection_service = $frontend_projection_service;
	}

	/**
	 * Register Bookings compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
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
			! $this->arbiter->should_core_register()
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
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ), 50, 2 );
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
		if ( MultiCurrencyRequestContext::is_call_in_backtrace( self::CART_ADD_TO_CART_CALLS ) ) {
			return $costs;
		}

		return $this->get_price_projection_service()->adjust_amount_for_selected_currency( $costs );
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
				MultiCurrencyRequestContext::is_call_in_backtrace( self::CART_ADD_TO_CART_CALLS )
				&& MultiCurrencyRequestContext::is_call_in_backtrace( self::BOOKINGS_COST_CALCULATION_CALLS )
			)
		) {
			return $price;
		}

		return $this->get_price_projection_service()->get_price(
			$price,
			MultiCurrencyRequestContext::is_call_in_backtrace( self::BOOKING_PRICE_HTML_CALLS ) ? 'product' : 'exchange_rate'
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

		return ! MultiCurrencyRequestContext::is_call_in_backtrace( self::BOOKING_PRICE_HTML_CALLS );
	}

	/**
	 * Add selected-currency price args while Bookings Ajax calculates costs.
	 */
	public function add_wc_price_args_filter_for_ajax(): void {
		add_filter( 'wc_price_args', array( $this, 'filter_wc_price_args' ), 100 );
	}

	/**
	 * Project selected-currency wc_price args for Bookings Ajax.
	 *
	 * @param array<mixed> $args Existing wc_price args.
	 * @return array<mixed>
	 */
	public function filter_wc_price_args( array $args ): array {
		$frontend_projection_service = $this->get_frontend_projection_service();

		return wp_parse_args(
			array(
				'currency'           => $frontend_projection_service->get_woocommerce_currency(),
				'decimal_separator'  => $frontend_projection_service->get_price_decimal_separator( (string) ( $args['decimal_separator'] ?? wc_get_price_decimal_separator() ) ),
				'thousand_separator' => $frontend_projection_service->get_price_thousand_separator( (string) ( $args['thousand_separator'] ?? wc_get_price_thousand_separator() ) ),
				'decimals'           => $frontend_projection_service->get_price_decimals( absint( $args['decimals'] ?? wc_get_price_decimals() ) ),
				'price_format'       => $frontend_projection_service->get_woocommerce_price_format( (string) ( $args['price_format'] ?? get_woocommerce_price_format() ) ),
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
	 * Get the price projection service.
	 *
	 * @return MultiCurrencyPriceProjectionService
	 */
	private function get_price_projection_service(): MultiCurrencyPriceProjectionService {
		if ( null === $this->price_projection_service ) {
			$this->price_projection_service = $this->projection_service_factory->create_price_projection_service();
		}

		return $this->price_projection_service;
	}

	/**
	 * Get the frontend projection service.
	 *
	 * @return MultiCurrencyFrontendProjectionService
	 */
	private function get_frontend_projection_service(): MultiCurrencyFrontendProjectionService {
		if ( null === $this->frontend_projection_service ) {
			$this->frontend_projection_service = $this->projection_service_factory->create_frontend_projection_service();
		}

		return $this->frontend_projection_service;
	}
}
