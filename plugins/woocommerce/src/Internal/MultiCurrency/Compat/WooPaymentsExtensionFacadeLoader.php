<?php
/**
 * WooPaymentsExtensionFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * While WooCommerce runs Multi-Currency, declares the WooPayments Multi-Currency compatibility names at `plugins_loaded`.
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
		if ( ! $this->arbiter->is_builtin_owner() || $this->arbiter->is_extension_activation_request() ) {
			return;
		}

		// The facades live outside every Composer-scanned tree, so only this loader can declare them.
		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/MultiCurrency.php';
		}

		if ( ! class_exists( 'WCPay\\MultiCurrency\\Compatibility', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/Compatibility.php';
		}

		if ( ! class_exists( 'WCPay\MultiCurrency\Utils', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/Utils.php';
		}

		if ( ! class_exists( 'WCPay\MultiCurrency\Exceptions\InvalidCurrencyException', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/InvalidCurrencyException.php';
		}

		if ( ! class_exists( 'WCPay\MultiCurrency\Exceptions\InvalidCurrencyRateException', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/InvalidCurrencyRateException.php';
		}

		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-multi-currency-compat/wc-payments-multi-currency.php';
		}
	}
}
