<?php
/**
 * WooPaymentsExtensionMultiCurrencyFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * While WooCommerce runs Multi-Currency, declares the WooPayments extension's `WCPay\MultiCurrency\MultiCurrency` class
 * at `plugins_loaded` and answers its members; it records whether the facade converted a product price this request,
 * which the Deposits compatibility controller reads.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsExtensionMultiCurrencyFacadeLoader implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Price converter, built on first use.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

	/**
	 * The request's state builder, shared by the facade's conversions and state reads.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * Whether a facade call converted a product price during this request.
	 *
	 * @var bool
	 */
	private bool $converted_product_price = false;

	/**
	 * Initialize the loader.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Schedule the facade declaration after every active plugin file has loaded.
	 */
	public function register() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->load();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'load' ), 0 );
	}

	/**
	 * Declare the facade class while WooCommerce runs Multi-Currency.
	 */
	public function load(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ) ) {
			// The facade lives outside every Composer-scanned tree, so only this loader can declare it.
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/MultiCurrency.php';
		}
	}

	/**
	 * Convert a price to the selected currency, and record a converted product price.
	 *
	 * @param mixed  $amount Price amount.
	 * @param string $type   Price type.
	 * @return float
	 */
	public function get_price( $amount, string $type ): float {
		$converted_amount = $this->get_price_converter()->get_price( $amount, $type );

		if ( 'product' === $type ) {
			$this->converted_product_price = true;
		}

		return $converted_amount;
	}

	/**
	 * Convert an amount between two currencies; an empty source currency is the store currency.
	 *
	 * @param float  $amount        Amount.
	 * @param string $to_currency   Target currency code.
	 * @param string $from_currency Source currency code.
	 * @return float
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency ): float {
		if ( '' === $from_currency ) {
			$from_currency = $this->get_default_currency()->get_code();
		}

		return $this->get_price_converter()->get_raw_conversion( $amount, $to_currency, $from_currency );
	}

	/**
	 * Get the selected currency from the request's Multi-Currency state.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_selected_currency(): MultiCurrencyCurrency {
		return $this->get_state_builder()->build()->get_selected_currency();
	}

	/**
	 * Get the store's default currency from the request's Multi-Currency state.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_default_currency(): MultiCurrencyCurrency {
		return $this->get_state_builder()->build()->get_default_currency();
	}

	/**
	 * Tell whether the facade already converted a product price in this request.
	 *
	 * @return bool
	 */
	public function did_convert_product_price(): bool {
		return $this->converted_product_price;
	}

	/**
	 * Get the price converter.
	 *
	 * @return MultiCurrencyPriceConverter
	 */
	private function get_price_converter(): MultiCurrencyPriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = wc_get_container()->get( MultiCurrencyServiceFactory::class )->create_price_converter();
		}

		return $this->price_converter;
	}

	/**
	 * Get the request's state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->get_state_builder();
		}

		return $this->state_builder;
	}
}
