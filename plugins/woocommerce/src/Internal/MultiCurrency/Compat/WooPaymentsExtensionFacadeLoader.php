<?php
/**
 * WooPaymentsExtensionFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * While WooCommerce runs Multi-Currency, declares the WooPayments extension's `WCPay\MultiCurrency\MultiCurrency` and
 * `WCPay\MultiCurrency\Compatibility` classes at `plugins_loaded`, and converts the facade's prices; it records whether the facade converted a product price this
 * request, which the Deposits compatibility controller reads.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsExtensionFacadeLoader implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Price converter, built on first use.
	 *
	 * @var PriceConverter|null
	 */
	private ?PriceConverter $price_converter = null;

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

		// The facades live outside every Composer-scanned tree, so only this loader can declare them.
		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/MultiCurrency.php';
		}

		if ( ! class_exists( 'WCPay\\MultiCurrency\\Compatibility', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/Compatibility.php';
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
	 * @return PriceConverter
	 */
	private function get_price_converter(): PriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = wc_get_container()->get( ServiceFactory::class )->create_price_converter();
		}

		return $this->price_converter;
	}
}
