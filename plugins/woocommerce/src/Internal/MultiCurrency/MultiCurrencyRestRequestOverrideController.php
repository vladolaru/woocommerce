<?php
/**
 * MultiCurrencyRestRequestOverrideController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Registers native non-Store REST request-local currency override filters.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyRestRequestOverrideController implements RegisterHooksInterface {

	private const FILTER_SHOULD_RETURN_STORE_CURRENCY = 'wcpay_multi_currency_should_return_store_currency';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Request context.
	 *
	 * @var MultiCurrencyRequestContext
	 */
	private MultiCurrencyRequestContext $request_context;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param MultiCurrencyRequestContext $request_context Request context.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRequestContext $request_context ): void {
		$this->arbiter         = $arbiter;
		$this->request_context = $request_context;
	}

	/**
	 * Set the request context.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyRequestContext $request_context Request context.
	 */
	public function set_request_context( MultiCurrencyRequestContext $request_context ): void {
		$this->request_context = $request_context;
	}

	/**
	 * Register non-Store REST request-local override filters.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() || ! $this->request_context->should_register_rest_request_overrides() ) {
			return;
		}

		if ( isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( MultiCurrencyStateBuilder::FILTER_OVERRIDE_SELECTED_CURRENCY, array( $this, 'get_currency_from_query_param' ) );
			return;
		}

		add_filter( self::FILTER_SHOULD_RETURN_STORE_CURRENCY, '__return_true' );
		add_filter( MultiCurrencyFrontendPricesController::FILTER_SHOULD_CONVERT_PRODUCT_PRICE, '__return_false' );
		add_filter( MultiCurrencyStateBuilder::FILTER_OVERRIDE_SELECTED_CURRENCY, array( $this, 'get_store_currency_code' ) );
	}

	/**
	 * Get a request-local selected currency from the REST query parameter.
	 *
	 * @internal
	 *
	 * @param mixed $currency_code Existing override value.
	 * @return string|false Uppercase requested currency, or false when unavailable.
	 */
	public function get_currency_from_query_param( $currency_code = false ) {
		unset( $currency_code );

		if ( ! isset( $_GET['currency'] ) || ! is_scalar( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$currency_code = sanitize_text_field( (string) wp_unslash( $_GET['currency'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return strtoupper( trim( $currency_code ) );
	}

	/**
	 * Get the store default currency code for non-Store REST requests.
	 *
	 * @internal
	 *
	 * @param mixed $currency_code Existing override value.
	 * @return string
	 */
	public function get_store_currency_code( $currency_code = false ): string {
		unset( $currency_code );

		return strtoupper( get_woocommerce_currency() );
	}
}
