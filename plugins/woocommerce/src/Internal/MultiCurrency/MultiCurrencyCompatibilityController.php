<?php
/**
 * MultiCurrencyCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\Admin\Notes\NewSalesRecord;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;

/**
 * Registers native multi-currency compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyCompatibilityController implements RegisterHooksInterface {

	/**
	 * WooCommerce's best sales day note methods whose order totals are converted to the store currency. Client 11.1.0
	 * (`includes/multi-currency/Compatibility.php:263-264`) names `Automattic\WooCommerce\Admin\Notes\NewSalesRecord`,
	 * which no frame carries, so its conversion never runs.
	 */
	private const NEW_SALES_RECORD_BACKTRACE_CALLS = array(
		NewSalesRecord::class . '::sum_sales_for_date',
		NewSalesRecord::class . '::possibly_add_note',
	);

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
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

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
	 * Register compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		if ( Constants::is_defined( 'DOING_CRON' ) ) {
			add_filter( 'woocommerce_admin_sales_record_milestone_enabled', array( $this, 'attach_order_modifier' ) );
		}
	}

	/**
	 * Check whether the selected currency should be overridden.
	 *
	 * @return mixed Three-letter currency code or false.
	 */
	public function override_selected_currency() {
		/**
		 * Filters the native multi-currency selected currency override.
		 *
		 * @param mixed $currency Currency code or false.
		 *
		 * @since 11.0.0
		 */
		return apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'override_selected_currency', false );
	}

	/**
	 * Deprecated widget-hiding compatibility method.
	 *
	 * @return bool Whether currency switchers should be hidden.
	 */
	public function should_hide_widgets(): bool {
		wc_deprecated_function( __FUNCTION__, '6.5.0', 'MultiCurrencyCompatibilityController::should_disable_currency_switching' );

		return $this->should_disable_currency_switching();
	}

	/**
	 * Tell whether currency switching should be disabled.
	 *
	 * @return bool
	 */
	public function should_disable_currency_switching(): bool {
		$should_disable = array_key_exists( 'pay_for_order', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only compatibility flag matching WooPayments behavior.

		if ( has_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_hide_widgets' ) ) {
			wc_deprecated_hook( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_hide_widgets', '6.5.0', MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_disable_currency_switching' );
			/**
			 * Filters whether native multi-currency widgets should be hidden.
			 *
			 * @param bool $should_disable Whether currency switching should be disabled.
			 *
			 * @since 11.0.0
			 */
			$should_disable = (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_hide_widgets', $should_disable );
		}

		/**
		 * Filters whether native multi-currency switching should be disabled.
		 *
		 * @param bool $should_disable Whether currency switching should be disabled.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_disable_currency_switching', $should_disable );
	}

	/**
	 * Tell whether coupon amounts should be converted.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	public function should_convert_coupon_amount( $coupon = null ): bool {
		if ( ! $coupon ) {
			return true;
		}

		/**
		 * Filters whether a coupon amount should be converted.
		 *
		 * @param bool  $should_convert Whether the coupon amount should be converted.
		 * @param mixed $coupon         Coupon object.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_coupon_amount', true, $coupon );
	}

	/**
	 * Tell whether product prices should be converted.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	public function should_convert_product_price( $product = null ): bool {
		if ( ! $product ) {
			return true;
		}

		/**
		 * Filters whether a product price should be converted.
		 *
		 * @param bool  $should_convert Whether the product price should be converted.
		 * @param mixed $product        Product object.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', true, $product );
	}

	/**
	 * Tell whether the store currency should be returned.
	 *
	 * @return bool
	 */
	public function should_return_store_currency(): bool {
		/**
		 * Filters whether native multi-currency should return the store currency.
		 *
		 * @param bool $should_return Whether to return the store currency.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_return_store_currency', false );
	}

	/**
	 * Attach the order query modifier for sales-record calculations.
	 *
	 * @param mixed $enabled Existing enabled value.
	 * @return mixed
	 */
	public function attach_order_modifier( $enabled ) {
		add_filter( 'woocommerce_order_query', array( $this, 'convert_order_prices' ), 10, 2 );

		return $enabled;
	}

	/**
	 * Convert sales-record order totals back to the default store currency.
	 *
	 * The order query inside the note's sum first primes the orders' refund caches through a refund query; that query is
	 * left alone, so the conversion runs on, and detaches after, the orders the note sums.
	 *
	 * @param mixed $results    Order query results.
	 * @param mixed $query_vars Order query variables.
	 * @return mixed
	 */
	public function convert_order_prices( $results, $query_vars = array() ) {
		if (
			! is_array( $results )
			|| ( is_array( $query_vars ) && 'shop_order_refund' === ( $query_vars['type'] ?? null ) )
			|| ! MultiCurrencyRequestContext::is_call_in_backtrace( self::NEW_SALES_RECORD_BACKTRACE_CALLS )
		) {
			return $results;
		}

		$default_currency_code = $this->get_state_builder()->build()->get_default_currency()->get_code();

		foreach ( $results as $order ) {
			if ( ! $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund ) {
				continue;
			}

			$exchange_rate = $order->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE, true );
			if (
				$default_currency_code === $order->get_currency() ||
				! is_numeric( $exchange_rate ) ||
				0 >= (float) $exchange_rate ||
				$order->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY, true ) !== $default_currency_code
			) {
				continue;
			}

			$order->set_total( wc_format_decimal( (float) $order->get_total() * ( 1 / (float) $exchange_rate ), wc_get_price_decimals() ) );
		}

		remove_filter( 'woocommerce_order_query', array( $this, 'convert_order_prices' ) );

		return $results;
	}

	/**
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->create();
		}

		return $this->state_builder;
	}
}
