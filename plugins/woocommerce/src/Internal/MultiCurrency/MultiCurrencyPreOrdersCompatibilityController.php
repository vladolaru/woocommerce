<?php
/**
 * MultiCurrencyPreOrdersCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency Pre-Orders compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyPreOrdersCompatibilityController implements RegisterHooksInterface {

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
	 * Price projection service.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param MultiCurrencyServiceFactory $service_factory Multi-Currency service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyServiceFactory $service_factory ): void {
		$this->arbiter         = $arbiter;
		$this->service_factory = $service_factory;
	}

	/**
	 * Register Pre-Orders compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_pre_orders_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_pre_orders_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_pre_orders_filters' ), 20 );
	}

	/**
	 * Register Pre-Orders compatibility filters after supported runtimes have loaded.
	 *
	 * @internal
	 */
	public function register_pre_orders_filters(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_pre_orders_runtime_available() ) {
			return;
		}

		add_filter( 'wc_pre_orders_fee', array( $this, 'convert_pre_orders_fee' ) );
	}

	/**
	 * Convert Pre-Orders fee args for the selected currency.
	 *
	 * @param array<mixed> $args Pre-Orders fee args.
	 * @return array<mixed>
	 */
	public function convert_pre_orders_fee( array $args ): array {
		if ( ! array_key_exists( 'amount', $args ) ) {
			return $args;
		}

		$args['amount'] = $this->get_price_converter()->get_price( $args['amount'], 'product' );

		return $args;
	}

	/**
	 * Check if Pre-Orders runtime is available.
	 *
	 * @return bool
	 */
	protected function is_pre_orders_runtime_available(): bool {
		return class_exists( 'WC_Pre_Orders' );
	}

	/**
	 * Get the price projection service.
	 *
	 * @return MultiCurrencyPriceConverter
	 */
	private function get_price_converter(): MultiCurrencyPriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = $this->service_factory->create_price_converter();
		}

		return $this->price_converter;
	}
}
