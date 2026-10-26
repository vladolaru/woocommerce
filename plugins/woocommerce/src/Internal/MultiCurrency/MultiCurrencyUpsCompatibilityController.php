<?php
/**
 * MultiCurrencyUpsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;

/**
 * Keeps WooCommerce UPS Shipping's rate calculations in the store currency: the store currency is returned while UPS
 * calculates shipping.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyUpsCompatibilityController implements RegisterHooksInterface {

	private const UPS_SHIPPING_CALLS = array(
		'WC_Shipping_UPS->per_item_shipping',
		'WC_Shipping_UPS->box_shipping',
		'WC_Shipping_UPS->calculate_shipping',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register UPS compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_ups_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_ups_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_ups_filters' ), 20 );
	}

	/**
	 * Register UPS compatibility filters after supported runtimes have loaded.
	 *
	 * @internal
	 */
	public function register_ups_filters(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_ups_runtime_available() ) {
			return;
		}

		add_filter( StateBuilder::FILTER_PREFIX . 'should_return_store_currency', array( $this, 'should_return_store_currency' ) );
	}

	/**
	 * Tell whether Multi-Currency answers with the store currency, as it does inside UPS calculations.
	 *
	 * @param bool $should_return Existing store-currency decision.
	 * @return bool
	 */
	public function should_return_store_currency( bool $should_return ): bool {
		return $should_return || RequestContext::is_call_in_backtrace( self::UPS_SHIPPING_CALLS );
	}

	/**
	 * Check if UPS runtime is available.
	 *
	 * @return bool
	 */
	protected function is_ups_runtime_available(): bool {
		return class_exists( 'WC_Shipping_UPS_Init' );
	}
}
