<?php
/**
 * WooPayments Multi-Currency request helper.
 */

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsCompatibilityNotice;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;

/**
 * Supplies the extension's request check for marketplace integrations.
 *
 * @deprecated 11.4.0 No public replacement. Removed in WooCommerce 12.0.0.
 */
class Utils {

	use WooPaymentsCompatibilityNotice;

	/**
	 * Tell whether an admin page issued a WooCommerce admin REST request.
	 *
	 * @deprecated 11.4.0 No public replacement. Removed in WooCommerce 12.0.0.
	 */
	public static function is_admin_api_request(): bool {
		self::warn_compatibility_method( __METHOD__ );
		return wc_get_container()->get( RequestContext::class )->is_admin_api_request();
	}
}
