<?php
/**
 * WooCommerceFedEx class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;

/**
 * Keeps WooCommerce FedEx Shipping's rate calculations in the store currency: product prices are not converted and the
 * store currency is returned while FedEx builds and processes its requests.
 *
 * @since 11.0.0
 * @internal
 */
class WooCommerceFedEx implements RegisterHooksInterface {

	private const FEDEX_SHIPPING_CALLS = array(
		'WC_Shipping_Fedex->set_settings',
		'WC_Shipping_Fedex->per_item_shipping',
		'WC_Shipping_Fedex->box_shipping',
		'WC_Shipping_Fedex->get_fedex_api_request',
		'WC_Shipping_Fedex->get_fedex_requests',
		'WC_Shipping_Fedex->process_result',
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
	 * Register FedEx compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_fedex_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_fedex_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_fedex_filters' ), 20 );
	}

	/**
	 * Register FedEx compatibility filters after supported runtimes have loaded.
	 *
	 * @internal
	 */
	public function register_fedex_filters(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_fedex_runtime_available() ) {
			return;
		}

		add_filter( StateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ) );
		add_filter( StateBuilder::FILTER_PREFIX . 'should_return_store_currency', array( $this, 'should_return_store_currency' ) );
	}

	/**
	 * Tell whether product prices should convert during FedEx calculations.
	 *
	 * @param bool $should_convert Existing product conversion decision.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert ): bool {
		return $should_convert && ! RequestContext::is_call_in_backtrace( self::FEDEX_SHIPPING_CALLS );
	}

	/**
	 * Tell whether Multi-Currency answers with the store currency, as it does inside FedEx calculations.
	 *
	 * @param bool $should_return Existing store-currency decision.
	 * @return bool
	 */
	public function should_return_store_currency( bool $should_return ): bool {
		return $should_return || RequestContext::is_call_in_backtrace( self::FEDEX_SHIPPING_CALLS );
	}

	/**
	 * Check if FedEx runtime is available.
	 *
	 * @return bool
	 */
	protected function is_fedex_runtime_available(): bool {
		return class_exists( 'WC_Shipping_Fedex_Init' );
	}
}
