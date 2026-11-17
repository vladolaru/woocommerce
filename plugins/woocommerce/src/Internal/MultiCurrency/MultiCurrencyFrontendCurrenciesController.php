<?php
/**
 * MultiCurrencyFrontendCurrenciesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Answers the currency and its format on storefront requests: the selected currency, or an order's on the order pages.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyFrontendCurrenciesController implements RegisterHooksInterface {

	/**
	 * Most backtrace frames read to find the order constructor below a decimals read, as client 11.3.0
	 * (`includes/multi-currency/FrontendCurrencies.php:20-28`): the constructor sits a short, fixed distance below the read.
	 */
	private const ORDER_READ_BACKTRACE_DEPTH = 40;

	/**
	 * The call a live order recalculation keeps on the backtrace; the method is declared on the abstract order.
	 */
	private const ORDER_CALCULATE_TOTALS_CALL = 'WC_Abstract_Order->calculate_totals';

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
	 * Display service, built on first use.
	 *
	 * @var DisplayService|null
	 */
	private ?DisplayService $display_service = null;

	/**
	 * Request context.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * Order context service.
	 *
	 * @var OrderContextService
	 */
	private OrderContextService $order_context_service;

	/**
	 * Order currency override for order-context formatting.
	 *
	 * @var string|null
	 */
	private ?string $order_currency = null;

	/**
	 * Whether the WooCommerce currency filter is running, so a nested read gets the stored currency.
	 *
	 * @var bool
	 */
	private bool $is_converting_woocommerce_currency = false;

	/**
	 * Whether init_order_currency() is reading an order, so a call nested in that read returns early.
	 *
	 * @var bool
	 */
	private bool $initializing_order_currency = false;

	/**
	 * Whether an order may be loading from the data store, set right before the order factory reads one.
	 *
	 * @var bool
	 */
	private bool $maybe_loading_order = false;

	/**
	 * ID of the order the page's query vars name whose currency is memoized, 0 for none.
	 *
	 * @var int
	 */
	private int $query_var_order_id = 0;

	/**
	 * Currency of the order the page's query vars name, read only while an order loads.
	 *
	 * Kept apart from the order currency above, which formats every later price in the request once set.
	 *
	 * @var string|null
	 */
	private ?string $query_var_order_currency = null;

	/**
	 * Currencies of the orders recalculating their totals, innermost last.
	 *
	 * A stack, so a nested recalculation of another order does not drop the outer order's currency when it ends.
	 *
	 * @var array<int,string|null>
	 */
	private array $calculated_order_currencies = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter               Runtime owner arbiter.
	 * @param ServiceFactory              $service_factory       Multi-Currency service factory.
	 * @param RequestContext              $request_context       Request context.
	 * @param OrderContextService         $order_context_service Order context service.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		ServiceFactory $service_factory,
		RequestContext $request_context,
		OrderContextService $order_context_service
	): void {
		$this->arbiter               = $arbiter;
		$this->service_factory       = $service_factory;
		$this->request_context       = $request_context;
		$this->order_context_service = $order_context_service;
	}

	/**
	 * Register frontend currency hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->request_context->should_register_frontend_hooks() ) {
			add_filter( 'woocommerce_currency', array( $this, 'get_woocommerce_currency' ), 900 );
			add_filter( 'wc_get_price_decimals', array( $this, 'get_price_decimals' ), 900 );
			add_filter( 'wc_get_price_decimal_separator', array( $this, 'get_price_decimal_separator' ), 900 );
			add_filter( 'wc_get_price_thousand_separator', array( $this, 'get_price_thousand_separator' ), 900 );
			add_filter( 'woocommerce_price_format', array( $this, 'get_woocommerce_price_format' ), 900 );
			add_filter( 'option_woocommerce_currency_pos', array( $this, 'get_woocommerce_currency_pos' ), 900 );
			add_filter( 'woocommerce_order_get_total', array( $this, 'maybe_init_order_currency_from_order_total_prop' ), 900, 2 );
			add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'maybe_clear_order_currency_after_formatted_order_total' ), 900, 4 );
			add_filter( 'woocommerce_order_class', array( $this, 'flag_order_loading' ), 900 );
			// Pushed before calculate_totals() reads the decimals, popped after every other listener has read them.
			add_action( 'woocommerce_order_before_calculate_totals', array( $this, 'init_calculated_order_currency' ), 10, 2 );
			add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'clear_calculated_order_currency' ), 900 );

			add_action( 'before_woocommerce_pay', array( $this, 'init_order_currency_from_query_vars' ) );
			add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'enqueue_blocks_totals_script' ) );
			add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_blocks_totals_script' ) );
		}

		add_filter( 'woocommerce_thankyou_order_id', array( $this, 'init_order_currency' ) );
		// @phpstan-ignore return.void (the same method is the woocommerce_thankyou_order_id filter's callback; the action discards its return)
		add_action( 'woocommerce_account_view-order_endpoint', array( $this, 'init_order_currency' ), 9 );
		add_filter( 'woocommerce_cart_hash', array( $this, 'add_currency_to_cart_hash' ), 900 );
		add_filter( 'woocommerce_shipping_method_add_rate_args', array( $this, 'fix_price_decimals_for_shipping_rates' ), 900, 2 );
	}

	/**
	 * Load the script that shows the currency code after the Cart and Checkout block totals.
	 *
	 * The client registers the same totals filter from its Blocks checkout script (client 11.1.0 `client/checkout/blocks/index.js:159-175`).
	 *
	 * @internal
	 */
	public function enqueue_blocks_totals_script(): void {
		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		wp_enqueue_script(
			'wc-multi-currency-blocks-totals',
			plugins_url( 'assets/js/frontend/multi-currency-blocks-totals' . $suffix . '.js', WC_PLUGIN_FILE ),
			array( 'wc-blocks-checkout' ),
			Constants::get_constant( 'WC_VERSION' ),
			array( 'in_footer' => true )
		);
	}

	/**
	 * Get the currency code for this request: the order's or the selected currency, else the code earlier filters gave.
	 *
	 * @param mixed $currency Existing currency.
	 * @return string
	 */
	public function get_woocommerce_currency( $currency = null ): string {
		if ( $this->is_converting_woocommerce_currency ) {
			return is_scalar( $currency ) && '' !== (string) $currency ? (string) $currency : (string) get_option( 'woocommerce_currency' );
		}

		$this->is_converting_woocommerce_currency = true;

		try {
			return $this->get_display_service()->get_woocommerce_currency( $this->order_currency, is_string( $currency ) ? $currency : null );
		} finally {
			$this->is_converting_woocommerce_currency = false;
		}
	}

	/**
	 * Get the price decimal count of this request's currency.
	 *
	 * @param mixed $decimals Existing decimal count.
	 * @return int
	 */
	public function get_price_decimals( $decimals ): int {
		// An order read from the data store keeps the precision its amounts were stored with.
		if ( $this->is_loading_order() ) {
			return $this->get_display_service()->get_loading_order_price_decimals( absint( $decimals ), $this->order_currency ?? $this->get_query_var_order_currency() );
		}

		// The Analytics import rounds amounts converted to the store currency, which keep the store's decimals.
		if ( doing_filter( 'woocommerce_analytics_update_order_stats_data' ) ) {
			return absint( $decimals );
		}

		$currency_decimals = $this->get_display_service()->get_price_decimals( absint( $decimals ), $this->get_formatting_currency() );

		// A flat-rate percent fee in store units keeps the store's decimals, or the shopper's currency's when it has more.
		if ( MultiCurrencyFrontendPricesController::is_computing_store_currency_fee() ) {
			return max( absint( $decimals ), $currency_decimals );
		}

		return $currency_decimals;
	}

	/**
	 * Get the price decimal separator of this request's currency.
	 *
	 * @param mixed $separator Existing decimal separator.
	 * @return string
	 */
	public function get_price_decimal_separator( $separator ): string {
		return $this->get_display_service()->get_price_decimal_separator( (string) $separator, $this->get_formatting_currency() );
	}

	/**
	 * Get the price thousand separator of this request's currency.
	 *
	 * @param mixed $separator Existing thousand separator.
	 * @return string
	 */
	public function get_price_thousand_separator( $separator ): string {
		return $this->get_display_service()->get_price_thousand_separator( (string) $separator, $this->get_formatting_currency() );
	}

	/**
	 * Get the price format of this request's currency.
	 *
	 * @param mixed $format Existing price format.
	 * @return string
	 */
	public function get_woocommerce_price_format( $format ): string {
		return $this->get_display_service()->get_woocommerce_price_format( (string) $format, $this->get_formatting_currency() );
	}

	/**
	 * Get the currency symbol position of this request's currency.
	 *
	 * @param mixed $position Existing currency position.
	 * @return string
	 */
	public function get_woocommerce_currency_pos( $position ): string {
		return $this->get_display_service()->get_woocommerce_currency_pos( (string) $position, $this->get_formatting_currency() );
	}

	/**
	 * Vary the cart hash by the selected currency and its rate.
	 *
	 * @param mixed $hash Existing cart hash.
	 * @return string
	 */
	public function add_currency_to_cart_hash( $hash ): string {
		return $this->get_display_service()->add_currency_to_cart_hash( (string) $hash );
	}

	/**
	 * Initialize order-context currency from WooCommerce order query vars.
	 */
	public function init_order_currency_from_query_vars(): void {
		$order_id = $this->order_context_service->get_order_id_from_query_vars();
		if ( null !== $order_id ) {
			$this->init_order_currency( $order_id );
		}
	}

	/**
	 * Record the currency of the order whose totals are being recalculated, so its amounts round in its own currency.
	 *
	 * A calculation that throws before its after hook leaves its entry behind, so the stack is first cut to the live
	 * calculate_totals() frames other than this one; that also drops a stale entry left at the bottom (client 11.3.0
	 * `includes/multi-currency/FrontendCurrencies.php:565-590`).
	 *
	 * @internal
	 *
	 * @param mixed $and_taxes Whether taxes are calculated too.
	 * @param mixed $order     Order recalculating its totals.
	 */
	public function init_calculated_order_currency( $and_taxes, $order ): void {
		unset( $and_taxes );

		$valid_existing = max( 0, $this->request_context->get_backtrace_call_count( self::ORDER_CALCULATE_TOTALS_CALL ) - 1 );
		if ( count( $this->calculated_order_currencies ) > $valid_existing ) {
			$this->calculated_order_currencies = array_slice( $this->calculated_order_currencies, 0, $valid_existing );
		}

		$this->calculated_order_currencies[] = $order instanceof \WC_Order && $order->get_currency() ? $order->get_currency() : null;
	}

	/**
	 * Drop the innermost recalculating order's currency once its totals are calculated.
	 *
	 * @internal
	 */
	public function clear_calculated_order_currency(): void {
		array_pop( $this->calculated_order_currencies );
	}

	/**
	 * Flag that an order may be loading: `WC_Order_Factory` asks for the order class right before it reads the order.
	 *
	 * @internal
	 *
	 * @param mixed $classname Order class name.
	 * @return mixed The class name, unchanged.
	 */
	public function flag_order_loading( $classname ) {
		$this->maybe_loading_order = true;

		return $classname;
	}

	/**
	 * Switch formatting to the order's currency when the order context asks for it; the total is returned unchanged.
	 *
	 * @param mixed $total Order total.
	 * @param mixed $order Order object.
	 * @return mixed
	 */
	public function maybe_init_order_currency_from_order_total_prop( $total, $order ) {
		if ( $this->order_context_service->should_use_order_currency() ) {
			$this->init_order_currency( $order );
		}

		return $total;
	}

	/**
	 * Clear order-context currency after formatted order totals.
	 *
	 * @param mixed $formatted_total  Formatted total.
	 * @param mixed $order            Order object.
	 * @param mixed $tax_display      Tax display.
	 * @param mixed $display_refunded Whether refunded values are displayed.
	 * @return mixed
	 */
	public function maybe_clear_order_currency_after_formatted_order_total( $formatted_total, $order, $tax_display, $display_refunded ) {
		unset( $order, $tax_display, $display_refunded );

		if ( null !== $this->order_currency && $this->order_context_service->should_use_order_currency() ) {
			$this->order_currency = null;
		}

		return $formatted_total;
	}

	/**
	 * Initialize order-context currency from an order object or ID.
	 *
	 * @param mixed $order_id Order object or ID.
	 * @return mixed
	 */
	public function init_order_currency( $order_id ) {
		if ( null !== $this->order_currency || $this->initializing_order_currency ) {
			return $order_id;
		}

		$order = $order_id instanceof \WC_Order ? $order_id : $this->read_order( $order_id );

		if ( $order ) {
			$this->order_currency = $order->get_currency();

			return $order->get_id();
		}

		$this->order_currency = $this->get_display_service()->get_woocommerce_currency();

		return $order_id;
	}

	/**
	 * Run a callback with prices formatted in an order's currency instead of the visitor's selected one.
	 *
	 * Background and webhook requests can run as a user whose selection differs from the order's currency.
	 *
	 * @since 11.2.0
	 *
	 * @param string   $currency Order currency code.
	 * @param callable $callback Callback that formats prices.
	 * @phpstan-param callable(): string $callback
	 * @return string The callback's result.
	 */
	public function format_in_order_currency( string $currency, callable $callback ): string {
		$previous             = $this->order_currency;
		$this->order_currency = strtoupper( $currency );
		try {
			return $callback();
		} finally {
			$this->order_currency = $previous;
		}
	}

	/**
	 * Round shipping rates to the decimals of the currency their cost is in.
	 *
	 * Core rounds the rate cost with `price_decimals` after computing its taxes. On storefront requests the cost is in the
	 * selected currency, whose decimals `wc_get_price_decimals()` returns there; elsewhere it returns the store's.
	 *
	 * @param mixed $args   Shipping rate args.
	 * @param mixed $method Shipping method.
	 * @return mixed
	 */
	public function fix_price_decimals_for_shipping_rates( $args, $method ) {
		unset( $method );

		if ( ! is_array( $args ) ) {
			return $args;
		}

		$args['price_decimals'] = wc_get_price_decimals();

		return $args;
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

	/**
	 * Get the currency that formats this read, or null for the selected currency.
	 *
	 * The currency of the order recalculating its totals, else the order currency set for an order page, else the currency of the order the page names while an order-confirmation
	 * block renders it (client 11.3.0 `includes/multi-currency/FrontendCurrencies.php:620-624`, `:685-689`). Other prices
	 * on the page keep the selected currency.
	 *
	 * @return string|null
	 */
	private function get_formatting_currency(): ?string {
		$calculated_order_currency = $this->get_calculated_order_currency();
		if ( null !== $calculated_order_currency ) {
			return $calculated_order_currency;
		}

		if ( null !== $this->order_currency ) {
			return $this->order_currency;
		}

		return $this->order_context_service->is_rendering_order_confirmation() ? $this->get_query_var_order_currency() : null;
	}

	/**
	 * Get the currency of the innermost order whose totals are being recalculated, or null outside a recalculation.
	 *
	 * Entries beyond the live calculate_totals() frames belong to calculations that ended without their after hook, so
	 * they are dropped first (client 11.3.0 `includes/multi-currency/FrontendCurrencies.php:606-618`).
	 *
	 * @return string|null
	 */
	private function get_calculated_order_currency(): ?string {
		if ( array() === $this->calculated_order_currencies ) {
			return null;
		}

		$active_calculations = $this->request_context->get_backtrace_call_count( self::ORDER_CALCULATE_TOTALS_CALL );
		if ( count( $this->calculated_order_currencies ) > $active_calculations ) {
			$this->calculated_order_currencies = array_slice( $this->calculated_order_currencies, 0, $active_calculations );
		}

		$currency = end( $this->calculated_order_currencies );

		return is_string( $currency ) ? $currency : null;
	}

	/**
	 * Read an order with the format filters off, as their callbacks can call init_order_currency() again.
	 *
	 * The decimals filter stays on: the read sets the order's amounts and the order factory caches the order, so a read at
	 * the store's decimals would hand a rounded copy to every later read in the request (client 11.3.0
	 * `includes/multi-currency/FrontendCurrencies.php:427-456`). A call nested in the read returns early.
	 *
	 * @param mixed $order_id Order ID.
	 * @return \WC_Order|\WC_Order_Refund|false
	 */
	private function read_order( $order_id ) {
		$removed_filters                   = $this->remove_frontend_currency_format_filters();
		$this->initializing_order_currency = true;
		try {
			return wc_get_order( $order_id );
		} finally {
			$this->initializing_order_currency = false;
			$this->restore_frontend_currency_format_filters( $removed_filters );
		}
	}

	/**
	 * Get the currency of the order the page's query vars name, read once per order and request.
	 *
	 * @return string|null The currency, or null when the page names no order, it cannot be read, or an order read is running.
	 */
	private function get_query_var_order_currency(): ?string {
		$order_id = $this->order_context_service->get_order_id_from_query_vars();
		if ( null === $order_id || $this->initializing_order_currency ) {
			return null;
		}

		if ( $order_id !== $this->query_var_order_id ) {
			$order                          = $this->read_order( $order_id );
			$this->query_var_order_id       = $order_id;
			$this->query_var_order_currency = $order ? $order->get_currency() : null;
		}

		return $this->query_var_order_currency;
	}

	/**
	 * Tell whether an order is being read from the data store.
	 *
	 * No hook marks the end of a factory read, so the flag holds until a read finds the factory gone from the backtrace. An
	 * order woken from a persistent object cache is read again by its constructor without the factory, so the constructor
	 * is looked for within the innermost frames (client 11.3.0 `includes/multi-currency/FrontendCurrencies.php:702-715`).
	 *
	 * @return bool
	 */
	private function is_loading_order(): bool {
		if ( $this->maybe_loading_order ) {
			$this->maybe_loading_order = $this->request_context->is_call_in_backtrace( array( 'WC_Order_Factory::get_order', 'WC_Order_Factory::get_orders' ) );
			if ( $this->maybe_loading_order ) {
				return true;
			}
		}

		return $this->request_context->is_call_in_backtrace_within( array( 'WC_Abstract_Order->__construct' ), self::ORDER_READ_BACKTRACE_DEPTH );
	}

	/**
	 * Remove frontend currency format filters during order lookup.
	 *
	 * @return array<string,int|false> Removed filter priorities keyed by hook.
	 */
	private function remove_frontend_currency_format_filters(): array {
		$removed_filters = array();

		foreach ( $this->get_frontend_currency_format_filters() as $hook => $callback ) {
			$priority                 = has_filter( $hook, $callback );
			$removed_filters[ $hook ] = $priority;

			if ( false !== $priority ) {
				remove_filter( $hook, $callback, $priority );
			}
		}

		return $removed_filters;
	}

	/**
	 * Restore frontend currency format filters removed during order lookup.
	 *
	 * @param array<string,int|false> $removed_filters Removed filter priorities keyed by hook.
	 */
	private function restore_frontend_currency_format_filters( array $removed_filters ): void {
		foreach ( $this->get_frontend_currency_format_filters() as $hook => $callback ) {
			$priority = $removed_filters[ $hook ] ?? false;

			if ( false !== $priority ) {
				add_filter( $hook, $callback, $priority );
			}
		}
	}

	/**
	 * Get frontend currency format callbacks.
	 *
	 * @return array<string,callable>
	 */
	private function get_frontend_currency_format_filters(): array {
		return array(
			'woocommerce_price_format'        => array( $this, 'get_woocommerce_price_format' ),
			'wc_get_price_thousand_separator' => array( $this, 'get_price_thousand_separator' ),
			'wc_get_price_decimal_separator'  => array( $this, 'get_price_decimal_separator' ),
		);
	}
}
