<?php
/**
 * MultiCurrencyFedExCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Registers native multi-currency FedEx compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyFedExCompatibilityController implements RegisterHooksInterface {

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
		if ( ! $this->arbiter->should_core_register() ) {
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
		if ( ! $this->arbiter->should_core_register() || ! $this->is_fedex_runtime_available() ) {
			return;
		}

		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ) );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_return_store_currency', array( $this, 'should_return_store_currency' ) );
	}

	/**
	 * Tell whether product prices should convert during FedEx calculations.
	 *
	 * @param bool $should_convert Existing product conversion decision.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert ): bool {
		return $should_convert && ! MultiCurrencyRequestContext::is_call_in_backtrace( self::FEDEX_SHIPPING_CALLS );
	}

	/**
	 * Tell whether native multi-currency should force store currency for FedEx.
	 *
	 * @param bool $should_return Existing store-currency decision.
	 * @return bool
	 */
	public function should_return_store_currency( bool $should_return ): bool {
		return $should_return || MultiCurrencyRequestContext::is_call_in_backtrace( self::FEDEX_SHIPPING_CALLS );
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
