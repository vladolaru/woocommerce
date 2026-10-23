<?php
/**
 * MultiCurrencyPreOrdersCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
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
	 * Register Pre-Orders compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
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
		if ( ! $this->arbiter->should_core_register() || ! $this->is_pre_orders_runtime_available() ) {
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

		$args['amount'] = $this->get_price_projection_service()->get_price( $args['amount'], 'product' );

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
	 * @return MultiCurrencyPriceProjectionService
	 */
	private function get_price_projection_service(): MultiCurrencyPriceProjectionService {
		if ( null === $this->price_projection_service ) {
			$this->price_projection_service = $this->projection_service_factory->create_price_projection_service();
		}

		return $this->price_projection_service;
	}
}
