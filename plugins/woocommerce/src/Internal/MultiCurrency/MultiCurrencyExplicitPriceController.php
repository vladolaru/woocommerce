<?php
/**
 * MultiCurrencyExplicitPriceController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter      $arbiter               Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory ): void {
		$this->arbiter               = $arbiter;
		$this->state_builder_factory = $state_builder_factory;
	}

	/**
	 * Register explicit price formatting hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		$manifest = MultiCurrencyExplicitPriceProjectionService::get_hook_manifest();
		foreach ( $manifest['filters'] as $filter ) {
			$callback = array( $this, (string) $filter['callback'] );
			if ( is_callable( $callback ) ) {
				$this->add_filter_once( (string) $filter['hook'], $callback, (int) $filter['priority'], (int) $filter['accepted_args'] );
			}
		}

		foreach ( $manifest['actions'] as $action ) {
			$callback = array( $this, (string) $action['callback'] );
			if ( is_callable( $callback ) ) {
				$this->add_action_once( (string) $action['hook'], $callback, (int) $action['priority'], (int) $action['accepted_args'] );
			}
		}
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
		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price(
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

		$this->add_filter_once( 'wc_price_args', array( $this, 'get_explicit_price_args' ), 100 );
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
		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price_args(
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
		try {
			return $this->state_builder_factory->create()->build()->has_additional_currencies_enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Register a filter only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_filter_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( false === has_filter( $hook, $callback ) ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
		}
	}

	/**
	 * Register an action only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_action_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( false === has_action( $hook, $callback ) ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}
