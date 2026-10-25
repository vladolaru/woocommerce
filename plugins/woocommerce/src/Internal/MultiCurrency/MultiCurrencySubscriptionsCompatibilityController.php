<?php
/**
 * MultiCurrencySubscriptionsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;

/**
 * Keeps WooCommerce Subscriptions renewal, resubscribe and switch carts in the subscription's currency, converting only
 * the amounts Subscriptions has not already stored in it.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencySubscriptionsCompatibilityController implements RegisterHooksInterface {

	private const SUBSCRIPTION_TYPES = array( 'renewal', 'resubscribe', 'switch' );

	/**
	 * Cart-lifecycle hooks that invalidate the per-request subscription cart-type cache.
	 *
	 * Covers loading the cart from session (so an early null lookup is not served after the
	 * cart populates), whole-cart recalculation/emptying, and individual item add/remove/restore.
	 */
	private const SUBSCRIPTION_TYPE_CACHE_INVALIDATION_HOOKS = array(
		'woocommerce_cart_loaded_from_session',
		'woocommerce_cart_updated',
		'woocommerce_cart_emptied',
		'woocommerce_add_to_cart',
		'woocommerce_cart_item_removed',
		'woocommerce_cart_item_restored',
	);

	private const SUBSCRIPTION_PERCENT_COUPON_TYPES = array(
		'recurring_percent',
		'sign_up_fee_percent',
		'renewal_percent',
	);

	private const SUBSCRIPTION_RECURRING_COUPON_TYPES = array(
		'recurring_fee',
		'recurring_percent',
		'renewal_fee',
		'renewal_percent',
	);

	/**
	 * Coupon types Subscriptions gives the discount of the order a renewal or initial payment cart pays, in that order's currency.
	 */
	private const ORDER_DISCOUNT_COUPON_TYPES = array(
		'renewal_cart',
		'initial_cart',
	);

	private const PRODUCT_PRICE_CALCULATION_CALLS = array(
		'WC_Cart_Totals->calculate_item_totals',
		'WC_Cart->get_product_subtotal',
		'wc_get_price_excluding_tax',
		'wc_get_price_including_tax',
	);

	private const RENEWAL_SETUP_CALLS = array(
		'WCS_Cart_Renewal->setup_cart',
	);

	private const EARLY_RENEWAL_SETUP_CALLS = array(
		'WCS_Cart_Early_Renewal->setup_cart',
	);

	private const APPLY_COUPON_CALLS = array(
		'WC_Discounts->apply_coupon',
	);

	private const SUBSCRIPTION_PRICE_SETUP_CALLS = array(
		'WC_Subscriptions_Cart::set_subscription_prices_for_calculation',
	);

	private const SUBSCRIPTION_PRODUCT_SIGNUP_FEE_CALLS = array(
		'WC_Subscriptions_Product::get_sign_up_fee',
	);

	private const SUBSCRIPTION_PRODUCT_ACTIVE_PRICE_CALLS = array(
		'WC_Subscriptions_Product::get_price',
	);

	private const CART_CALCULATE_TOTALS_CALLS = array(
		'WC_Cart->calculate_totals',
	);

	private const SWITCH_APPORTION_SIGNUP_FEE_CALLS = array(
		'WCS_Switch_Totals_Calculator->apportion_sign_up_fees',
	);

	private const MY_ACCOUNT_SUBSCRIPTIONS_TEMPLATE_CALLS = array(
		'WCS_Template_Loader::get_my_subscriptions',
		'WCS_Template_Loader::get_my_subscriptions ',
	);

	private const FORMATTED_SUBSCRIPTION_TOTAL_CALLS = array(
		'WC_Subscription->get_formatted_order_total',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Proxy for global function calls that tests can replace.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Price converter, built on first use.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * Previously observed subscription switch cart item key.
	 *
	 * @var string
	 */
	private string $switch_cart_item = '';

	/**
	 * Current My Account subscription being formatted.
	 *
	 * @var object|null
	 */
	private ?object $current_my_account_subscription = null;

	/**
	 * Whether selected-currency override lookups are already running.
	 *
	 * @var bool
	 */
	private bool $running_override_selected_currency_filters = false;

	/**
	 * Per-request cache of subscription cart items keyed by cart type.
	 *
	 * Depends only on cart/session state, which is stable within a request until the
	 * cart mutates. Invalidated by the cart-lifecycle hooks registered in
	 * register_subscription_filters().
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $subscription_type_cache = array();

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter      $arbiter               Runtime owner arbiter.
	 * @param LegacyProxy                      $legacy_proxy          Proxy for global function calls.
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 * @param MultiCurrencyServiceFactory      $service_factory       Multi-Currency service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyServiceFactory $service_factory ): void {
		$this->arbiter               = $arbiter;
		$this->legacy_proxy          = $legacy_proxy;
		$this->state_builder_factory = $state_builder_factory;
		$this->service_factory       = $service_factory;
	}

	/**
	 * Register Subscriptions compatibility hooks.
	 */
	public function register() {
		if (
			! $this->arbiter->is_builtin_owner()
			|| is_admin()
			|| Constants::is_defined( 'DOING_CRON' )
		) {
			return;
		}

		if ( $this->is_subscriptions_runtime_available() || 0 < did_action( 'plugins_loaded' ) ) {
			$this->register_subscription_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_subscription_filters' ), 20 );
	}

	/**
	 * Register Subscriptions compatibility filters after supported runtimes have loaded.
	 *
	 * The cart-lifecycle hooks fire whenever the cart membership can change within a request, so the
	 * subscription cart-type cache is only served while the cart is stable.
	 *
	 * @internal
	 */
	public function register_subscription_filters(): void {
		if (
			! $this->arbiter->is_builtin_owner()
			|| is_admin()
			|| Constants::is_defined( 'DOING_CRON' )
			|| ! $this->is_subscriptions_runtime_available()
		) {
			return;
		}

		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'override_selected_currency', array( $this, 'override_selected_currency' ), 50 );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_disable_currency_switching', array( $this, 'should_disable_currency_switching' ), 50 );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ), 50, 2 );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_coupon_amount', array( $this, 'should_convert_coupon_amount' ), 50, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'get_subscription_session_coupon_spend_limit' ), 100, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'get_subscription_session_coupon_spend_limit' ), 100, 2 );
		add_filter( 'woocommerce_subscriptions_product_price', array( $this, 'get_subscription_product_price' ), 50, 2 );
		add_filter( 'woocommerce_subscriptions_product_sale_price', array( $this, 'get_stored_price_for_active_price' ), 50, 2 );
		add_filter( 'woocommerce_subscriptions_product_regular_price', array( $this, 'get_stored_price_for_active_price' ), 50, 2 );
		add_filter( 'woocommerce_product_get__subscription_sign_up_fee', array( $this, 'get_subscription_product_signup_fee' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get__subscription_sign_up_fee', array( $this, 'get_subscription_product_signup_fee' ), 50, 2 );
		add_filter( 'woocommerce_subscription_price_string_details', array( $this, 'maybe_set_current_my_account_subscription' ), 50, 2 );
		add_filter( 'woocommerce_get_formatted_subscription_total', array( $this, 'maybe_clear_current_my_account_subscription' ), 50, 2 );
		add_filter( 'wc_price', array( $this, 'maybe_get_explicit_format_for_subscription_total' ), 50 );
		add_filter( 'option_woocommerce_subscriptions_multiple_purchase', array( $this, 'maybe_disable_mixed_cart' ), 50 );

		foreach ( self::SUBSCRIPTION_TYPE_CACHE_INVALIDATION_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'clear_subscription_type_cache' ) );
		}
	}

	/**
	 * Override selected currency from renewal, resubscribe, or switch subscription context.
	 *
	 * @param mixed $selected_currency Existing selected currency decision.
	 * @return mixed
	 */
	public function override_selected_currency( $selected_currency ) {
		if ( $selected_currency || $this->running_override_selected_currency_filters ) {
			return $selected_currency;
		}

		$currency_code = $this->get_subscription_currency( $this->current_my_account_subscription );
		if ( null !== $currency_code ) {
			return $this->mark_order_currency( $currency_code );
		}

		foreach ( self::SUBSCRIPTION_TYPES as $type ) {
			$cart_item = $this->get_subscription_type_from_cart( $type );
			if ( null === $cart_item ) {
				continue;
			}

			$subscription                                     = null;
			$this->running_override_selected_currency_filters = true;

			try {
				$subscription = $this->get_subscription_from_cart_item( $cart_item, $type );
			} finally {
				$this->running_override_selected_currency_filters = false;
			}

			$currency_code = $this->get_subscription_currency( $subscription );

			return null === $currency_code ? $selected_currency : $this->mark_order_currency( $currency_code );
		}

		$currency_code = $this->get_subscription_currency( $this->get_subscription_from_switch_request() );

		return null === $currency_code ? $selected_currency : $this->mark_order_currency( $currency_code );
	}

	/**
	 * Mark a subscription's currency as an existing order's currency, which holds even after the merchant disabled it.
	 *
	 * @param string $currency_code Subscription currency code.
	 * @return string
	 */
	private function mark_order_currency( string $currency_code ): string {
		MultiCurrencyStateBuilder::set_order_currency_override( $currency_code );

		return $currency_code;
	}

	/**
	 * Tell whether the cart, or a verified switch request, holds a subscription in a currency other than the store currency.
	 *
	 * Reads the cached cart items, loads a subscription only for a subscription cart item (its currency is on the subscription),
	 * compares with the stored store currency without building the currency state, and starts no session.
	 *
	 * @return bool
	 */
	public function has_subscription_in_another_currency(): bool {
		if ( ! $this->is_subscriptions_runtime_available() ) {
			return false;
		}

		$currency_codes = array( $this->get_subscription_currency( $this->get_subscription_from_switch_request() ) );
		foreach ( self::SUBSCRIPTION_TYPES as $type ) {
			foreach ( $this->get_subscription_cart_items( $type ) as $cart_item ) {
				$currency_codes[] = $this->get_subscription_currency( $this->get_subscription_from_cart_item( $cart_item, $type ) );
			}
		}

		$currency_codes = array_filter( $currency_codes );

		return array() !== $currency_codes && array() !== array_diff( $currency_codes, array( strtoupper( trim( (string) get_option( 'woocommerce_currency' ) ) ) ) );
	}

	/**
	 * Tell whether currency switching should be disabled for subscription contexts.
	 *
	 * @param bool $should_disable Existing switching-disable decision.
	 * @return bool
	 */
	public function should_disable_currency_switching( bool $should_disable ): bool {
		return $should_disable || $this->has_subscription_cart_context() || null !== $this->get_subscription_from_switch_request();
	}

	/**
	 * Tell whether product price conversion should run for subscription contexts.
	 *
	 * @param bool  $should_convert Existing product conversion decision.
	 * @param mixed $product        Product object.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert, $product ): bool {
		if ( ! $should_convert ) {
			return false;
		}

		// Renewal carts and resubscribed items already hold the subscription's prices while totals calculate; a renewal
		// cart converts while Subscriptions sets it up, so the shopper lands at checkout with the converted price. Other
		// products in a resubscribe cart convert.
		$has_renewal = null !== $this->get_subscription_type_from_cart( 'renewal' );
		if ( $has_renewal && MultiCurrencyRequestContext::is_call_in_backtrace( self::RENEWAL_SETUP_CALLS ) ) {
			return true;
		}

		return ! (
			( $has_renewal || $this->is_resubscribed_product( $product ) )
			&& MultiCurrencyRequestContext::is_call_in_backtrace( self::PRODUCT_PRICE_CALCULATION_CALLS )
		);
	}

	/**
	 * Tell whether coupon amount conversion should run for subscription contexts.
	 *
	 * @param bool  $should_convert Existing coupon conversion decision.
	 * @param mixed $coupon         Coupon object.
	 * @return bool
	 */
	public function should_convert_coupon_amount( bool $should_convert, $coupon ): bool {
		$discount_type = strtolower( trim( $this->get_coupon_discount_type( $coupon ) ) );

		if (
			! $should_convert
			|| in_array( $discount_type, self::SUBSCRIPTION_PERCENT_COUPON_TYPES, true )
			|| in_array( $discount_type, self::ORDER_DISCOUNT_COUPON_TYPES, true )
			|| $this->is_subscription_session_coupon( $coupon )
		) {
			return false;
		}

		// In a renewal cart a recurring coupon already holds its converted amount when applied; an early renewal's setup reads the original amount.
		return ! (
			null !== $this->get_subscription_type_from_cart( 'renewal' )
			&& ! MultiCurrencyRequestContext::is_call_in_backtrace( self::EARLY_RENEWAL_SETUP_CALLS )
			&& MultiCurrencyRequestContext::is_call_in_backtrace( self::APPLY_COUPON_CALLS )
			&& in_array( $discount_type, self::SUBSCRIPTION_RECURRING_COUPON_TYPES, true )
		);
	}

	/**
	 * Keep the minimum or maximum spend Subscriptions stored for a coupon it rebuilds from the session.
	 *
	 * Subscriptions stored the converted spend limit while it set the cart up, so the stored value is returned in place of the
	 * frontend price conversion's second pass.
	 *
	 * @param mixed $amount Coupon minimum or maximum spend.
	 * @param mixed $coupon Coupon object.
	 * @return mixed
	 */
	public function get_subscription_session_coupon_spend_limit( $amount, $coupon ) {
		if ( ! $this->is_subscription_session_coupon( $coupon ) ) {
			return $amount;
		}

		return 'woocommerce_coupon_get_minimum_amount' === current_filter() ? $coupon->get_minimum_amount( 'edit' ) : $coupon->get_maximum_amount( 'edit' );
	}

	/**
	 * Convert direct Subscriptions product prices when Subscriptions guards allow it.
	 *
	 * @param mixed $price   Subscription product price.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function get_subscription_product_price( $price, $product ) {
		if ( ! $price || ! $this->should_convert_product_price( true, $product ) ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Give Subscriptions' active price lookup the stored sale or regular price.
	 *
	 * The lookup compares the sale price with the stored subscription price and passes the price it picks through
	 * `woocommerce_subscriptions_product_price`, which converts it (Subscriptions 9.3.0
	 * `includes/core/class-wc-subscriptions-product.php:553-606`), so it must compare and pick stored prices. In the store
	 * currency nothing converts, and the prices pass through as other filters made them.
	 *
	 * @param mixed $price   Sale or regular price.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function get_stored_price_for_active_price( $price, $product ) {
		if (
			! $product instanceof \WC_Product
			|| ! $this->get_price_converter()->should_convert_between_selected_and_default_currency()
			|| ! MultiCurrencyRequestContext::is_call_in_backtrace( self::SUBSCRIPTION_PRODUCT_ACTIVE_PRICE_CALLS )
		) {
			return $price;
		}

		return 'woocommerce_subscriptions_product_sale_price' === current_filter() ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
	}

	/**
	 * Convert subscription sign-up fees when switch/proration guards allow it.
	 *
	 * @param mixed $price   Subscription sign-up fee.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function get_subscription_product_signup_fee( $price, $product ) {
		$cart_item = $this->get_subscription_type_from_cart( 'switch' );
		if ( null !== $cart_item ) {
			$previous_switch_cart_item = $this->switch_cart_item;
			$this->switch_cart_item    = $this->get_switch_cart_item_key( $cart_item );

			// A switch reads its product's sign-up fee several times while it prorates, and not every read converts: once
			// Subscriptions has rewritten the fee from converted reads, the later reads already hold the selected currency.
			if (
				$price
				&& $this->get_product_id( $product ) === $this->get_cart_item_product_id( $cart_item )
				&& $this->has_rewritten_subscription_signup_fee( $product )
				&& (
					MultiCurrencyRequestContext::is_call_in_backtrace( self::SUBSCRIPTION_PRICE_SETUP_CALLS )
					|| $this->is_subscription_switch_proration_context( $this->switch_cart_item, $previous_switch_cart_item )
					|| $this->has_changed_subscription_signup_fee_meta( $product, $this->switch_cart_item, $previous_switch_cart_item )
				)
			) {
				return $price;
			}
		}

		if ( ! $price ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Track the current My Account subscription while Subscriptions formats totals.
	 *
	 * @param array<mixed> $subscription_details Subscription price string details.
	 * @param mixed        $subscription         Subscription object.
	 * @return array<mixed>
	 */
	public function maybe_set_current_my_account_subscription( $subscription_details, $subscription ): array {
		if (
			(
				MultiCurrencyRequestContext::is_call_in_backtrace( self::MY_ACCOUNT_SUBSCRIPTIONS_TEMPLATE_CALLS )
				|| MultiCurrencyRequestContext::is_call_in_backtrace( self::FORMATTED_SUBSCRIPTION_TOTAL_CALLS )
			)
			&& null !== $this->get_subscription_currency( $subscription )
		) {
			$this->current_my_account_subscription = $subscription;
		}

		return $subscription_details;
	}

	/**
	 * Clear the current My Account subscription after the formatted total is done.
	 *
	 * @param mixed $formatted    Formatted subscription total.
	 * @param mixed $subscription Subscription object.
	 * @return string
	 */
	public function maybe_clear_current_my_account_subscription( $formatted, $subscription ): string {
		unset( $subscription );

		if ( null !== $this->get_subscription_currency( $this->current_my_account_subscription ) ) {
			$this->current_my_account_subscription = null;
		}

		return (string) $formatted;
	}

	/**
	 * Append explicit currency code to My Account subscription totals when needed.
	 *
	 * @param mixed $html_price Price HTML.
	 * @return string
	 */
	public function maybe_get_explicit_format_for_subscription_total( $html_price ): string {
		$html_price    = (string) $html_price;
		$currency_code = $this->get_subscription_currency( $this->current_my_account_subscription );
		if ( null === $currency_code || ! $this->get_state_builder()->build()->has_additional_currencies_enabled() ) {
			return $html_price;
		}

		$price_to_check = html_entity_decode( wp_strip_all_tags( $html_price ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		return false === strpos( $price_to_check, $currency_code ) ? $html_price . ' ' . $currency_code : $html_price;
	}

	/**
	 * Disable mixed subscription purchases while a switch cart item is present.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function maybe_disable_mixed_cart( $value ) {
		return null !== $this->get_subscription_type_from_cart( 'switch' ) ? 'no' : $value;
	}

	/**
	 * Tell whether WooCommerce Subscriptions is available.
	 *
	 * @return bool
	 */
	protected function is_subscriptions_runtime_available(): bool {
		return class_exists( 'WC_Subscriptions' );
	}

	/**
	 * Get the first cart item of a specific Subscriptions cart type.
	 *
	 * @param string $type Subscription cart type.
	 * @return array<string,mixed>|null
	 */
	protected function get_subscription_type_from_cart( string $type ): ?array {
		return $this->get_subscription_cart_items( $type )[0] ?? null;
	}

	/**
	 * Get the cart items of a specific Subscriptions cart type.
	 *
	 * Cached per request: the lookup only reads cart/session state, which is stable until the
	 * cart mutates, and the invalidation hooks registered in register_subscription_filters()
	 * clear the cache on every cart change. This keeps the hot price/cart path from traversing
	 * the whole cart and session on every filter invocation.
	 *
	 * @param string $type Subscription cart type.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_subscription_cart_items( string $type ): array {
		if ( ! array_key_exists( $type, $this->subscription_type_cache ) ) {
			$this->subscription_type_cache[ $type ] = $this->resolve_subscription_cart_items( $type );
		}

		return $this->subscription_type_cache[ $type ];
	}

	/**
	 * Clear the per-request subscription cart-type cache.
	 *
	 * Registered against the cart-lifecycle hooks so a cart change is always reflected by the
	 * next lookup. Public so the hook system can invoke it.
	 *
	 * @internal
	 */
	public function clear_subscription_type_cache(): void {
		$this->subscription_type_cache = array();
	}

	/**
	 * Resolve the cart items of a Subscriptions cart type from the cart, or from the session cart while the cart is not loaded.
	 *
	 * @param string $type Subscription cart type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function resolve_subscription_cart_items( string $type ): array {
		if ( ! in_array( $type, self::SUBSCRIPTION_TYPES, true ) ) {
			return array();
		}

		$woocommerce      = WC();
		$subscription_key = 'subscription_' . $type;
		$cart             = $woocommerce->cart;
		$session          = $woocommerce->session;
		$sources          = array(
			$cart instanceof \WC_Cart ? $cart->cart_contents : null,
			$session instanceof \WC_Session ? $session->get( 'cart' ) : null,
		);

		foreach ( $sources as $cart_items ) {
			$items = is_array( $cart_items ) ? array_values( array_filter( $cart_items, static fn( $cart_item ) => is_array( $cart_item ) && isset( $cart_item[ $subscription_key ] ) ) ) : array();
			if ( array() !== $items ) {
				return $items;
			}
		}

		return array();
	}

	/**
	 * Tell whether the product is the product of a resubscribe cart item.
	 *
	 * Totals read a cart line through its own product object, so a loaded cart item is matched by that object; a session cart
	 * item holds only ids.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	private function is_resubscribed_product( $product ): bool {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		foreach ( $this->get_subscription_cart_items( 'resubscribe' ) as $cart_item ) {
			if ( isset( $cart_item['data'] ) ? $cart_item['data'] === $product : $this->get_cart_item_product_id( $cart_item ) === $product->get_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a subscription object from a subscription cart item.
	 *
	 * @param array<string,mixed> $cart_item Subscription cart item.
	 * @param string              $type      Subscription cart type.
	 * @return object|null
	 */
	protected function get_subscription_from_cart_item( array $cart_item, string $type ): ?object {
		$subscription_data = $cart_item[ 'subscription_' . $type ] ?? null;

		if ( ! is_array( $subscription_data ) || ! isset( $subscription_data['subscription_id'] ) ) {
			return null;
		}

		return $this->get_subscription_by_id( $subscription_data['subscription_id'] );
	}

	/**
	 * Get a verified switch request subscription.
	 *
	 * @return object|null
	 */
	protected function get_subscription_from_switch_request(): ?object {
		if ( ! isset( $_GET['_wcsnonce'] ) || ! isset( $_GET['switch-subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		$nonce = wp_unslash( $_GET['_wcsnonce'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $nonce ) || ! $this->legacy_proxy->call_function( 'wp_verify_nonce', sanitize_key( (string) $nonce ), 'wcs_switch_request' ) ) {
			return null;
		}

		$switch_subscription_id = wp_unslash( $_GET['switch-subscription'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		if ( ! is_numeric( $switch_subscription_id ) ) {
			return null;
		}

		$subscription                                     = null;
		$this->running_override_selected_currency_filters = true;

		try {
			$subscription = $this->get_subscription_by_id( absint( $switch_subscription_id ) );
		} finally {
			$this->running_override_selected_currency_filters = false;
		}

		// The customer id comes through a getter filter, so it is cast before the strict comparison.
		return $subscription instanceof \WC_Order && absint( $subscription->get_customer_id() ) === get_current_user_id() ? $subscription : null;
	}

	/**
	 * Tell whether switch proration totals are reading a repeated sign-up fee.
	 *
	 * @param string $current_switch_cart_item  Current switch cart item key.
	 * @param string $previous_switch_cart_item Previously observed switch cart item key.
	 * @return bool
	 */
	private function is_subscription_switch_proration_context( string $current_switch_cart_item, string $previous_switch_cart_item ): bool {
		return '' !== $current_switch_cart_item
			&& $current_switch_cart_item === $previous_switch_cart_item
			&& MultiCurrencyRequestContext::is_call_in_backtrace( self::SUBSCRIPTION_PRODUCT_SIGNUP_FEE_CALLS )
			&& MultiCurrencyRequestContext::is_call_in_backtrace( self::CART_CALCULATE_TOTALS_CALLS )
			&& ! MultiCurrencyRequestContext::is_call_in_backtrace( self::SWITCH_APPORTION_SIGNUP_FEE_CALLS );
	}

	/**
	 * Tell whether a repeated switch sign-up fee was already written to product meta.
	 *
	 * @param mixed  $product                   Product object.
	 * @param string $current_switch_cart_item  Current switch cart item key.
	 * @param string $previous_switch_cart_item Previously observed switch cart item key.
	 * @return bool
	 */
	private function has_changed_subscription_signup_fee_meta( $product, string $current_switch_cart_item, string $previous_switch_cart_item ): bool {
		if ( '' === $current_switch_cart_item || $current_switch_cart_item !== $previous_switch_cart_item || ! $product instanceof \WC_Product ) {
			return false;
		}

		return $this->has_unsaved_subscription_signup_fee_change( $product );
	}

	/**
	 * Tell whether Subscriptions rewrote the product's sign-up fee in this request.
	 *
	 * The switch totals calculator writes a dropped fee, or the fee plus the prorated upgrade cost together with the prorated
	 * fee, from converted reads (Subscriptions 9.3.0 `includes/switching/class-wcs-switch-totals-calculator.php:306-333,510-530`).
	 * Under the full fee without proration it writes nothing, and the meta still holds the store-currency fee.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	private function has_rewritten_subscription_signup_fee( $product ): bool {
		return $product instanceof \WC_Product
			&& ( $product->meta_exists( '_subscription_sign_up_fee_prorated' ) || $this->has_unsaved_subscription_signup_fee_change( $product ) );
	}

	/**
	 * Tell whether the product's sign-up fee meta holds a change not yet saved.
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	private function has_unsaved_subscription_signup_fee_change( \WC_Product $product ): bool {
		foreach ( $product->get_meta_data() as $meta ) {
			if ( $meta instanceof \WC_Meta_Data && '_subscription_sign_up_fee' === $meta->get_data()['key'] && ! empty( $meta->get_changes() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether any subscription cart item is present.
	 *
	 * @return bool
	 */
	private function has_subscription_cart_context(): bool {
		foreach ( self::SUBSCRIPTION_TYPES as $type ) {
			if ( null !== $this->get_subscription_type_from_cart( $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a subscription object by ID when Subscriptions functions are available.
	 *
	 * @param mixed $subscription_id Subscription ID.
	 * @return object|null
	 */
	private function get_subscription_by_id( $subscription_id ): ?object {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		$subscription = $this->legacy_proxy->call_function( 'wcs_get_subscription', $subscription_id );

		return is_object( $subscription ) ? $subscription : null;
	}

	/**
	 * Get a subscription currency code.
	 *
	 * @param mixed $subscription Subscription, which extends WC_Order.
	 * @return string|null
	 */
	private function get_subscription_currency( $subscription ): ?string {
		if ( ! $subscription instanceof \WC_Order ) {
			return null;
		}

		$currency_code = $subscription->get_currency();

		return is_scalar( $currency_code ) && '' !== trim( (string) $currency_code ) ? strtoupper( trim( (string) $currency_code ) ) : null;
	}

	/**
	 * Get a coupon discount type.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return string
	 */
	private function get_coupon_discount_type( $coupon ): string {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return '';
		}

		$discount_type = $coupon->get_discount_type();

		return is_scalar( $discount_type ) ? (string) $discount_type : '';
	}

	/**
	 * Tell whether Subscriptions rebuilt the coupon from the properties it stored in the session while it set up the cart.
	 *
	 * Subscriptions stores each coupon's amount and spend limits as read while the cart is set up, already converted, and
	 * serves them back for that code as a virtual coupon (Subscriptions 9.3.0 `includes/core/class-wcs-cart-renewal.php:1041-1077,1108-1166`).
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	private function is_subscription_session_coupon( $coupon ): bool {
		if ( ! $coupon instanceof \WC_Coupon || ! $coupon->get_virtual( 'edit' ) || 0 !== $coupon->get_id() ) {
			return false;
		}

		$session         = WC()->session;
		$renewal_coupons = $session instanceof \WC_Session ? $session->get( 'wcs_renewal_coupons' ) : null;
		if ( ! is_array( $renewal_coupons ) ) {
			return false;
		}

		$code = $coupon->get_code( 'edit' );
		foreach ( $renewal_coupons as $order_coupons ) {
			if ( is_array( $order_coupons ) && isset( $order_coupons[ $code ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the product ID.
	 *
	 * @param mixed $product Product object.
	 * @return int
	 */
	private function get_product_id( $product ): int {
		return $product instanceof \WC_Product ? $product->get_id() : 0;
	}

	/**
	 * Get the product or variation ID from a cart item.
	 *
	 * @param array<string,mixed> $cart_item Cart item.
	 * @return int
	 */
	private function get_cart_item_product_id( array $cart_item ): int {
		$variation_id = absint( $cart_item['variation_id'] ?? 0 );

		return 0 < $variation_id ? $variation_id : absint( $cart_item['product_id'] ?? 0 );
	}

	/**
	 * Get the switch cart item key.
	 *
	 * @param array<string,mixed> $cart_item Switch cart item.
	 * @return string
	 */
	private function get_switch_cart_item_key( array $cart_item ): string {
		return is_scalar( $cart_item['key'] ?? null ) ? (string) $cart_item['key'] : '';
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
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->get_state_builder();
		}

		return $this->state_builder;
	}
}
