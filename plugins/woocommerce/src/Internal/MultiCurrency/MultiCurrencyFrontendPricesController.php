<?php
/**
 * MultiCurrencyFrontendPricesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Converts storefront prices to the selected currency, and records the exchange-rate meta on new orders and their refunds.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyFrontendPricesController implements RegisterHooksInterface {

	public const FILTER_SHOULD_CONVERT_PRODUCT_PRICE    = 'wcpay_multi_currency_should_convert_product_price';
	private const FILTER_SHOULD_CONVERT_COUPON_AMOUNT   = 'wcpay_multi_currency_should_convert_coupon_amount';
	private const FILTER_SHOULD_CONVERT_SHIPPING_AMOUNT = 'wcpay_multi_currency_should_convert_shipping_amount';
	private const STORE_API_COLLECTION_DATA_ROUTE       = '/wc/store/v1/products/collection-data';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Price converter, built on first use.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

	/**
	 * Request context.
	 *
	 * @var MultiCurrencyRequestContext
	 */
	private MultiCurrencyRequestContext $request_context;

	/**
	 * The flat-rate method whose cost arguments were last converted to the store currency.
	 *
	 * Static because the currency controller's decimals filter reads it.
	 *
	 * @var object|null
	 */
	private static ?object $store_currency_cost_method = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param MultiCurrencyServiceFactory $service_factory Multi-Currency service factory.
	 * @param MultiCurrencyRequestContext $request_context Request context.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		MultiCurrencyServiceFactory $service_factory,
		MultiCurrencyRequestContext $request_context
	): void {
		$this->arbiter         = $arbiter;
		$this->service_factory = $service_factory;
		$this->request_context = $request_context;
	}

	/**
	 * Register frontend price hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->request_context->should_register_frontend_hooks() ) {
			return;
		}

		add_filter( 'woocommerce_product_get_price', array( $this, 'get_product_price_string' ), 99, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'get_product_price_string' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'get_product_price_string' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'get_product_price_string' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'get_product_price_string' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'get_product_price_string' ), 99, 2 );
		// Each range price converts before core adds tax and rounds it, as a variation's own price does.
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'get_product_price_string' ), 99 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'get_product_price_string' ), 99 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'get_product_price_string' ), 99 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'add_exchange_rate_to_variation_prices_hash' ), 99 );
		add_filter( 'woocommerce_shipping_zone_shipping_methods', array( $this, 'convert_free_shipping_method_min_amount' ), 99 );
		add_filter( 'woocommerce_shipping_method_add_rate_args', array( $this, 'convert_shipping_method_rate_cost' ), 99, 2 );
		add_filter( 'woocommerce_evaluate_shipping_cost_args', array( $this, 'convert_shipping_cost_args_to_store_currency' ), 99, 3 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'get_coupon_amount' ), 99, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'get_coupon_min_max_amount' ), 99 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'get_coupon_min_max_amount' ), 99 );
		add_action( 'woocommerce_new_order', array( $this, 'add_order_meta' ), 99, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'maybe_modify_price_ranges_rest_response' ), 10, 3 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'maybe_modify_price_ranges_query_var' ), 10, 3 );
	}

	/**
	 * Convert the product price to the selected currency when conversion is allowed.
	 *
	 * @param mixed $price   Product price.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function get_product_price( $price, $product = null ) {
		if ( ! $price || ! $this->should_convert_product_price( $product ) ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Convert the product price to the selected currency, as a string.
	 *
	 * @param mixed $price   Product price.
	 * @param mixed $product Product object.
	 * @return string
	 */
	public function get_product_price_string( $price, $product = null ): string {
		return (string) $this->get_product_price( $price, $product );
	}

	/**
	 * Add the selected currency's conversion settings to the variation price hash.
	 *
	 * Core caches the range's converted prices under this hash, so it varies with everything the conversion depends on, and
	 * with the decimals core rounds them to, which follow an order's currency on that order's pages.
	 *
	 * @param mixed $prices_hash Variation prices hash.
	 * @return mixed
	 */
	public function add_exchange_rate_to_variation_prices_hash( $prices_hash ) {
		if ( ! is_array( $prices_hash ) ) {
			return $prices_hash;
		}

		$prices_hash[] = $this->get_price_converter()->get_selected_currency_conversion_key();
		$prices_hash[] = wc_get_price_decimals();

		return $prices_hash;
	}

	/**
	 * Convert a shipping rate's cost to the selected currency when it is added.
	 *
	 * @param mixed $args            Shipping rate args.
	 * @param mixed $shipping_method Shipping method object.
	 * @return mixed
	 */
	public function convert_shipping_method_rate_cost( $args, $shipping_method = null ) {
		if ( ! is_array( $args ) || ! isset( $args['cost'] ) ) {
			return $args;
		}

		if ( ! $this->should_convert_shipping_amount( $shipping_method ) ) {
			return $args;
		}

		if ( is_array( $args['cost'] ) ) {
			$args['cost'] = array_map(
				function ( $cost ) {
					return $this->get_price_converter()->get_price( $cost, 'shipping' );
				},
				$args['cost']
			);
		} else {
			$args['cost'] = $this->get_price_converter()->get_price( $args['cost'], 'shipping' );
		}

		return $args;
	}

	/**
	 * Give flat-rate cost formulas the package cost in the store currency.
	 *
	 * Merchants type formulas in store units and the evaluated rate is converted once when it is added, so `[cost]`, `[fee]`
	 * and its `min_fee`/`max_fee` must work on the store-currency cost, not on the cart's converted contents cost.
	 *
	 * @param mixed $args            Cost arguments, with the package cost under `cost`.
	 * @param mixed $sum             Cost formula.
	 * @param mixed $shipping_method Shipping method object.
	 * @return mixed
	 */
	public function convert_shipping_cost_args_to_store_currency( $args, $sum = null, $shipping_method = null ) {
		unset( $sum );
		self::$store_currency_cost_method = null;

		if ( ! is_array( $args ) || ! isset( $args['cost'] ) || ! is_numeric( $args['cost'] ) || ! $this->should_convert_shipping_amount( $shipping_method ) ) {
			return $args;
		}

		$args['cost']                     = $this->get_price_converter()->get_default_currency_amount( (float) $args['cost'] );
		self::$store_currency_cost_method = is_object( $shipping_method ) ? $shipping_method : null;

		return $args;
	}

	/**
	 * Tell whether core is computing a percent fee in store units, so the fee keeps at least the store's decimals.
	 *
	 * Core's flat rate registers the `[fee]` shortcode for the method only while it evaluates that method's cost, and its
	 * `fee()` is the only decimals reader in that window. The memory clears at the next cost evaluation or on the first
	 * read after the window closes.
	 *
	 * @return bool
	 */
	public static function is_computing_store_currency_fee(): bool {
		global $shortcode_tags;

		if ( null !== self::$store_currency_cost_method && shortcode_exists( 'fee' ) && array( self::$store_currency_cost_method, 'fee' ) === $shortcode_tags['fee'] ) {
			return true;
		}

		self::$store_currency_cost_method = null;

		return false;
	}

	/**
	 * Convert a fixed coupon amount to the selected currency when conversion is allowed.
	 *
	 * @param mixed $amount Coupon amount.
	 * @param mixed $coupon Coupon object.
	 * @return mixed
	 */
	public function get_coupon_amount( $amount, $coupon ) {
		if ( ! $amount || $this->is_percentage_coupon( $coupon ) || ! $this->should_convert_coupon_amount( $coupon ) ) {
			return $amount;
		}

		return $this->get_price_converter()->get_price( $amount, 'coupon' );
	}

	/**
	 * Convert a coupon's minimum or maximum spend to the selected currency.
	 *
	 * @param mixed $amount Coupon minimum or maximum amount.
	 * @return mixed
	 */
	public function get_coupon_min_max_amount( $amount ) {
		if ( ! $amount ) {
			return $amount;
		}

		return $this->get_price_converter()->get_price( $amount, 'product' );
	}

	/**
	 * Convert free shipping minimum amounts to the selected currency.
	 *
	 * @param mixed $methods Shipping methods.
	 * @return mixed
	 */
	public function convert_free_shipping_method_min_amount( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}

		foreach ( $methods as $method ) {
			if (
				is_object( $method ) &&
				isset( $method->id, $method->min_amount ) &&
				'free_shipping' === $method->id &&
				! empty( $method->min_amount )
			) {
				$method->min_amount = $this->get_price_converter()->get_price( $method->min_amount, 'product' );
			}
		}

		return $methods;
	}

	/**
	 * Save the selected currency's exchange-rate meta on a new order.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $order    Order object.
	 */
	public function add_order_meta( $order_id, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( absint( $order_id ) );
		}

		// WooCommerce Subscriptions creates its renewal orders in the browsing currency and then copies the subscription's
		// currency and Multi-Currency meta onto them, so the browsing currency's rate does not belong on them.
		if ( ! $order instanceof \WC_Order || 'subscription' === $order->get_created_via() ) {
			return;
		}

		$meta_candidates = $this->get_price_converter()->get_order_meta_candidates( $order->get_currency() );
		if ( empty( $meta_candidates ) ) {
			return;
		}

		foreach ( $meta_candidates as $meta_key => $meta_value ) {
			$order->update_meta_data( $meta_key, (string) $meta_value );
		}

		$order->save_meta_data();
	}

	/**
	 * Copy the order's exchange-rate meta to a new refund.
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $refund_id Refund ID.
	 */
	public function add_refund_meta( $order_id, $refund_id ): void {
		$order  = wc_get_order( absint( $order_id ) );
		$refund = wc_get_order( absint( $refund_id ) );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$meta_candidates = $this->get_price_converter()->get_refund_meta_candidates( $order );
		if ( empty( $meta_candidates ) ) {
			return;
		}

		foreach ( $meta_candidates as $meta_key => $meta_value ) {
			$refund->update_meta_data( $meta_key, (string) $meta_value );
		}

		$refund->save_meta_data();
	}

	/**
	 * Convert the price range of the Store API collection-data response to the selected currency.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function maybe_modify_price_ranges_rest_response( $response, $server, $request ) {
		unset( $server );

		if (
			! $response instanceof \WP_REST_Response ||
			! $request instanceof \WP_REST_Request ||
			self::STORE_API_COLLECTION_DATA_ROUTE !== $request->get_route()
		) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['price_range'] ) || ! is_object( $data['price_range'] ) ) {
			return $response;
		}

		foreach ( array( 'min_price', 'max_price' ) as $field ) {
			if ( property_exists( $data['price_range'], $field ) && is_numeric( $data['price_range']->$field ) ) {
				$data['price_range']->$field = (string) $this->get_price_converter()->get_price( $data['price_range']->$field, 'product' );
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Convert the query loop's price filter to the store currency the stored prices are in.
	 *
	 * @param mixed $query Query vars.
	 * @param mixed $block Block instance.
	 * @param mixed $page  Page number.
	 * @return mixed
	 */
	public function maybe_modify_price_ranges_query_var( $query, $block, $page ) {
		unset( $block, $page );

		if (
			! is_array( $query ) ||
			'product' !== ( $query['post_type'] ?? null ) ||
			empty( $query['meta_query'] ) ||
			! is_array( $query['meta_query'] ) ||
			! $this->get_price_converter()->should_convert_between_selected_and_default_currency()
		) {
			return $query;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$query['meta_query'] = $this->convert_meta_query_price_filters( $query['meta_query'] );

		return $query;
	}

	/**
	 * Get the price converter.
	 *
	 * @return MultiCurrencyPriceConverter
	 */
	private function get_price_converter(): MultiCurrencyPriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = $this->service_factory->create_price_converter();
		}

		return $this->price_converter;
	}

	/**
	 * Convert the `_price` bounds of a meta query, recursing into nested queries.
	 *
	 * @param array<mixed> $meta_query Meta query.
	 * @param int          $depth      Current recursion depth.
	 * @return array<mixed>
	 */
	private function convert_meta_query_price_filters( array $meta_query, int $depth = 0 ): array {
		if ( 4 < $depth ) {
			return $meta_query;
		}

		foreach ( $meta_query as $key => $query ) {
			if ( ! is_array( $query ) ) {
				continue;
			}

			if (
				'_price' === ( $query['key'] ?? null ) &&
				isset( $query['value'], $query['compare'] ) &&
				is_numeric( $query['value'] ) &&
				in_array( $query['compare'], array( '<=', '>=' ), true )
			) {
				$meta_query[ $key ]['value'] = $this->get_price_converter()->get_price_filter_query_value(
					$query['value'],
					$query['compare']
				);
				continue;
			}

			$meta_query[ $key ] = $this->convert_meta_query_price_filters( $query, $depth + 1 );
		}

		return $meta_query;
	}

	/**
	 * Tell whether product price conversion should run.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	private function should_convert_product_price( $product = null ): bool {
		if ( ! $product ) {
			return true;
		}

		/**
		 * Filters whether a product price should be converted.
		 *
		 * @param bool  $should_convert Whether the product price should be converted.
		 * @param mixed $product        Product object.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( self::FILTER_SHOULD_CONVERT_PRODUCT_PRICE, true, $product );
	}

	/**
	 * Tell whether shipping amount conversion should run.
	 *
	 * @param mixed $shipping_method Shipping method object.
	 * @return bool
	 */
	private function should_convert_shipping_amount( $shipping_method = null ): bool {
		if ( ! $shipping_method ) {
			return true;
		}

		/**
		 * Filters whether a shipping amount should be converted.
		 *
		 * @since 11.2.0
		 *
		 * @param bool  $should_convert Whether the shipping amount should be converted.
		 * @param mixed $shipping_method Shipping method object.
		 */
		return (bool) apply_filters( self::FILTER_SHOULD_CONVERT_SHIPPING_AMOUNT, true, $shipping_method );
	}

	/**
	 * Tell whether coupon amount conversion should run.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	private function should_convert_coupon_amount( $coupon ): bool {
		/**
		 * Filters whether a coupon amount should be converted.
		 *
		 * @param bool  $should_convert Whether the coupon amount should be converted.
		 * @param mixed $coupon         Coupon object.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( self::FILTER_SHOULD_CONVERT_COUPON_AMOUNT, true, $coupon );
	}

	/**
	 * Tell whether the coupon is percentage-based.
	 *
	 * @param mixed $coupon The coupon woocommerce_coupon_get_amount passes, a WC_Coupon.
	 * @return bool
	 */
	private function is_percentage_coupon( $coupon ): bool {
		return $coupon instanceof \WC_Coupon && $coupon->is_type( array( 'percent' ) );
	}
}
