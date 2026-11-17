<?php
/**
 * MultiCurrencyFrontendPricesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Converts storefront prices to the selected currency, and records the exchange-rate meta on new orders and their refunds.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyFrontendPricesController implements RegisterHooksInterface {

	public const FILTER_SHOULD_CONVERT_PRODUCT_PRICE          = 'wcpay_multi_currency_should_convert_product_price';
	private const FILTER_SHOULD_CONVERT_COUPON_AMOUNT         = 'wcpay_multi_currency_should_convert_coupon_amount';
	private const FILTER_SHOULD_CONVERT_COUPON_MIN_MAX_AMOUNT = 'wcpay_multi_currency_should_convert_coupon_min_max_amount';
	private const FILTER_SHOULD_CONVERT_SHIPPING_AMOUNT       = 'wcpay_multi_currency_should_convert_shipping_amount';
	private const STORE_API_COLLECTION_DATA_ROUTE             = '/wc/store/v1/products/collection-data';

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
	 * Request context.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * The flat-rate method whose cost arguments were last converted to the store currency.
	 *
	 * Static because the currency controller's decimals filter reads it.
	 *
	 * @var object|null
	 */
	private static ?object $store_currency_cost_method = null;

	/**
	 * The shipping method whose `[fee]` shortcode is being worked out, from the moment its fee() reads its attributes until
	 * the shortcode ends or the next cart calculation starts.
	 *
	 * Static because the currency controller's decimals filter reads it.
	 *
	 * @var \WC_Shipping_Method|null
	 */
	private static ?\WC_Shipping_Method $shipping_method_rendering_fee = null;

	/**
	 * The coupon an order is re-applying while it recalculates, whose amount converts to the order's currency.
	 *
	 * @var object|null
	 */
	private ?object $order_coupon = null;

	/**
	 * The currency the re-applied coupon's amount converts to.
	 *
	 * @var MultiCurrencyCurrency|null
	 */
	private ?MultiCurrencyCurrency $order_coupon_currency = null;

	/**
	 * Whether the re-applied coupon's amount is still in the store currency.
	 *
	 * @var bool
	 */
	private bool $order_coupon_needs_conversion = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param ServiceFactory              $service_factory Multi-Currency service factory.
	 * @param RequestContext              $request_context Request context.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		ServiceFactory $service_factory,
		RequestContext $request_context
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
		add_filter( 'wc_get_price_decimal_separator', array( $this, 'use_store_decimal_separator_during_shipping_fee' ), 990 );
		add_filter( 'shortcode_atts_fee', array( $this, 'start_shipping_fee_rendering' ) );
		add_filter( 'do_shortcode_tag', array( $this, 'end_shipping_fee_rendering' ), PHP_INT_MIN, 2 );
		// PHP_INT_MIN so the fee state is cleared before plugins that set item prices on the same action read a format.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reset_shipping_fee_rendering' ), PHP_INT_MIN );
		// PHP_INT_MAX so the marker is added last and survives a plugin that rebuilds the packages on this filter.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_currency_to_shipping_packages' ), PHP_INT_MAX );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'get_coupon_amount' ), 99, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'get_coupon_min_max_amount' ), 99, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'get_coupon_min_max_amount' ), 99, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'set_order_coupon' ), 99, 4 );
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
	 * True inside the `[fee]` shortcode of the method whose cost arguments were converted to the store currency, as client
	 * 11.3.0 `FrontendPrices::use_store_currency_decimals_during_shipping_fee()` (`includes/multi-currency/FrontendPrices.php:373-384`).
	 *
	 * @return bool
	 */
	public static function is_computing_store_currency_fee(): bool {
		return null !== self::$shipping_method_rendering_fee && self::$shipping_method_rendering_fee === self::$store_currency_cost_method;
	}

	/**
	 * Read a shipping method's `[fee]` numbers with the store's decimal separator while another currency is selected.
	 *
	 * The merchant types `percent`, `min_fee` and `max_fee` in the store's number format, and core's flat-rate fee() parses
	 * them with wc_get_price_decimal_separator(), which otherwise answers the selected currency's separator. As client 11.3.0
	 * (`includes/multi-currency/FrontendPrices.php:395-406`), whether or not the method's cost was converted.
	 *
	 * @internal
	 *
	 * @param mixed $separator The decimal separator earlier filters answered.
	 * @return mixed
	 */
	public function use_store_decimal_separator_during_shipping_fee( $separator ) {
		if ( null === self::$shipping_method_rendering_fee || ! $this->get_price_converter()->should_convert_between_selected_and_default_currency() ) {
			return $separator;
		}

		// wc_get_price_decimal_separator() falls back to a dot when the option is empty.
		return (string) get_option( 'woocommerce_price_decimal_sep' );
	}

	/**
	 * Note the shipping method whose `[fee]` shortcode is being worked out.
	 *
	 * Core's fee() reads its attributes first, so a shortcode another callback short-circuits never counts. A fee() override
	 * that skips shortcode_atts() keeps the selected currency's format; one that throws keeps the store's format until another
	 * `[fee]` ends or the next cart calculation starts (client 11.3.0 `includes/multi-currency/FrontendPrices.php:408-431`).
	 *
	 * @internal
	 *
	 * @param mixed $atts The `[fee]` attributes, returned unchanged.
	 * @return mixed
	 */
	public function start_shipping_fee_rendering( $atts ) {
		global $shortcode_tags;

		$callback = $shortcode_tags['fee'] ?? null;
		if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof \WC_Shipping_Method ) {
			self::$shipping_method_rendering_fee = $callback[0];
		}

		return $atts;
	}

	/**
	 * Note the end of a `[fee]` shortcode.
	 *
	 * @internal
	 *
	 * @param mixed $output The shortcode output, returned unchanged.
	 * @param mixed $tag    The shortcode tag.
	 * @return mixed
	 */
	public function end_shipping_fee_rendering( $output, $tag ) {
		if ( 'fee' === $tag ) {
			self::$shipping_method_rendering_fee = null;
		}

		return $output;
	}

	/**
	 * Clear the `[fee]` state before a cart calculation, so a fee() that threw and was caught leaves no store format on
	 * while the item totals are taken (client 11.3.0 `includes/multi-currency/FrontendPrices.php:449-459`).
	 *
	 * @internal
	 */
	public function reset_shipping_fee_rendering(): void {
		self::$shipping_method_rendering_fee = null;
		self::$store_currency_cost_method    = null;
	}

	/**
	 * Mark each shipping package with the selected currency, so core's package hash changes with it.
	 *
	 * Core reuses a package's cached rates while its hash is unchanged, and the rate was converted once when it was built.
	 * Store-currency packages stay unmarked, so they hash as where Multi-Currency does not run (client 11.3.0
	 * `includes/multi-currency/FrontendPrices.php:587-627`).
	 *
	 * @internal
	 *
	 * @param mixed $packages Shipping packages; anything but an array is returned untouched.
	 * @return mixed
	 */
	public function add_currency_to_shipping_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$marker = $this->get_price_converter()->get_selected_currency_shipping_marker();
		if ( null === $marker ) {
			return $packages;
		}

		foreach ( array_keys( $packages ) as $key ) {
			// An empty package stays empty, so core still discards it instead of rating it.
			if ( is_array( $packages[ $key ] ) && ! empty( $packages[ $key ] ) ) {
				$packages[ $key ]['wcpay_multi_currency'] = $marker;
			}
		}

		return $packages;
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

		// A coupon re-applied to an existing order converts to the order's currency, whatever is selected now.
		if ( null !== $this->order_coupon && $coupon === $this->order_coupon ) {
			return $this->order_coupon_needs_conversion
				? $this->get_price_converter()->get_price( $amount, 'coupon', $this->order_coupon_currency )
				: $amount;
		}

		return $this->get_price_converter()->get_price( $amount, 'coupon' );
	}

	/**
	 * Remember the coupon an order is about to re-apply while it recalculates, so its amount converts to the order's currency.
	 *
	 * As client 11.3.0 `FrontendPrices::set_order_coupon()` (`includes/multi-currency/FrontendPrices.php:531-566`): a REST
	 * request that is neither Store API nor a Store API batch keeps the selection, as it already prices orders in the store
	 * currency. A live coupon's amount is in the store currency. A deleted coupon is rebuilt from the order line: its
	 * `coupon_info` amount was converted at checkout, a legacy `coupon_data` amount was not, and a rebuild with no stored
	 * amount takes the order line's discount, already in the order's currency. Known gap, shared with the client
	 * (`FrontendPrices.php:553-555`): core's former `OrderCouponDataMigrator` wrote `coupon_info` from the raw `coupon_data`
	 * without converting it, and nothing marks such an entry apart from one written at checkout, so it is treated as
	 * converted.
	 *
	 * @internal
	 *
	 * @param mixed $coupon      Coupon about to be re-applied.
	 * @param mixed $code        Coupon code.
	 * @param mixed $coupon_item The order's coupon line.
	 * @param mixed $order       The order recalculating.
	 * @return mixed The coupon, unchanged.
	 */
	public function set_order_coupon( $coupon, $code, $coupon_item, $order ) {
		unset( $code );
		$this->order_coupon = null;

		if ( $this->request_context->should_register_rest_request_overrides() || ! is_object( $coupon ) || ! $order instanceof \WC_Abstract_Order ) {
			return $coupon;
		}

		// Cast as WC_Abstract_Order::get_temporary_coupon() does, which also takes object-valued meta.
		$legacy_data     = $coupon_item instanceof \WC_Order_Item_Coupon ? (array) $coupon_item->get_meta( 'coupon_data', true ) : array();
		$legacy_amount   = (float) ( $legacy_data['amount'] ?? 0 );
		$has_coupon_info = $coupon_item instanceof \WC_Order_Item_Coupon && (bool) $coupon_item->get_meta( 'coupon_info', true );

		$this->order_coupon                  = $coupon;
		$this->order_coupon_currency         = $this->get_price_converter()->get_currency_for_order( (string) $order->get_currency() );
		$this->order_coupon_needs_conversion = $coupon instanceof \WC_Coupon && ( ! $coupon->get_virtual() || ( ! $has_coupon_info && $legacy_amount > 0 ) );

		return $coupon;
	}

	/**
	 * Convert a coupon's minimum or maximum spend to the selected currency when conversion is allowed.
	 *
	 * A spend limit converts as a product price, so a limit equal to a product's price keeps matching it under charm pricing.
	 *
	 * @param mixed $amount Coupon minimum or maximum amount.
	 * @param mixed $coupon Coupon object.
	 * @return mixed
	 */
	public function get_coupon_min_max_amount( $amount, $coupon = null ) {
		if ( ! $amount || ! $this->should_convert_coupon_min_max_amount( $coupon ) ) {
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
	 * @return PriceConverter
	 */
	private function get_price_converter(): PriceConverter {
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
	 * Tell whether a coupon's minimum and maximum spend conversion should run.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	private function should_convert_coupon_min_max_amount( $coupon = null ): bool {
		if ( ! $coupon ) {
			return true;
		}

		/**
		 * Filters whether a coupon's minimum and maximum spend should be converted to the selected currency.
		 *
		 * @since 11.4.0
		 *
		 * @param bool  $should_convert Whether the minimum and maximum spend should be converted.
		 * @param mixed $coupon         Coupon object.
		 */
		return (bool) apply_filters( self::FILTER_SHOULD_CONVERT_COUPON_MIN_MAX_AMOUNT, true, $coupon );
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
