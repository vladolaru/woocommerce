<?php
/**
 * MultiCurrencyExplicitPriceController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceFormatter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency explicit price formatting hooks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyExplicitPriceController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * Persisted usage detector, read before any state is built.
	 *
	 * @var MultiCurrencyUsageDetector
	 */
	private MultiCurrencyUsageDetector $usage_detector;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter      $arbiter               Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 * @param MultiCurrencyUsageDetector       $usage_detector        Persisted usage detector.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyUsageDetector $usage_detector ): void {
		$this->arbiter               = $arbiter;
		$this->state_builder_factory = $state_builder_factory;
		$this->usage_detector        = $usage_detector;
	}

	/**
	 * Register explicit price formatting hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_filter( 'woocommerce_cart_total', array( $this, 'get_explicit_price' ), 100 );
		add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'get_explicit_price' ), 100, 2 );
		add_action( 'woocommerce_admin_order_totals_after_tax', array( $this, 'register_formatted_woocommerce_price_filter' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'unregister_formatted_woocommerce_price_filter' ) );
	}

	/**
	 * Add an explicit currency suffix to formatted order/cart prices.
	 *
	 * @internal
	 *
	 * @param string                  $price Formatted price.
	 * @param \WC_Abstract_Order|null $order Order object, when available.
	 * @return string
	 */
	public function get_explicit_price( string $price, ?\WC_Abstract_Order $order = null ): string {
		return MultiCurrencyExplicitPriceFormatter::get_explicit_price(
			$price,
			$order,
			$this->should_output_explicit_price(),
			get_woocommerce_currency()
		);
	}

	/**
	 * Register explicit wc_price() args while admin order totals render.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID passed by the action.
	 */
	public function register_formatted_woocommerce_price_filter( $order_id = null ): void {
		unset( $order_id );

		add_filter( 'wc_price_args', array( $this, 'get_explicit_price_args' ), 100 );
	}

	/**
	 * Unregister explicit wc_price() args after admin order totals render.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID passed by the action.
	 */
	public function unregister_formatted_woocommerce_price_filter( $order_id = null ): void {
		unset( $order_id );

		remove_filter( 'wc_price_args', array( $this, 'get_explicit_price_args' ), 100 );
	}

	/**
	 * Add explicit currency code formatting to wc_price() args.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $args Price formatting args.
	 * @return array<string,mixed>
	 */
	public function get_explicit_price_args( array $args ): array {
		return MultiCurrencyExplicitPriceFormatter::get_explicit_price_args(
			$args,
			$this->should_output_explicit_price()
		);
	}

	/**
	 * Tell whether explicit price formatting should be active.
	 *
	 * @return bool
	 */
	private function should_output_explicit_price(): bool {
		// The enabled-currencies option is read first, so a store without additional currencies builds no state per price.
		$default = false;
		if ( $this->usage_detector->has_configured_additional_currencies() ) {
			$default = $this->state_builder_factory->get_state_builder()->build()->has_additional_currencies_enabled();
		}

		return MultiCurrencyExplicitPriceFormatter::should_output_explicit_price( $default );
	}
}
