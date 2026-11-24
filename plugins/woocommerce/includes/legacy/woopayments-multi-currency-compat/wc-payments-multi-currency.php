<?php
/**
 * WooPayments Multi-Currency compatibility function.
 */

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionFacadeAccessor;
use WCPay\MultiCurrency\MultiCurrency;

/**
 * Get the legacy Multi-Currency instance for this request.
 *
 * @deprecated 11.4.0 No public replacement. Removed in WooCommerce 12.0.0.
 * @return MultiCurrency
 */
function WC_Payments_Multi_Currency() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- The extension's public name.
	return WooPaymentsExtensionFacadeAccessor::from_function();
}
