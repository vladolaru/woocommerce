<?php
/**
 * MultiCurrencyRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tells who runs Multi-Currency on this site: WooCommerce, the WooPayments extension, or neither.
 *
 * The extension carries its own Multi-Currency module, whose classes share names with the facades WooCommerce declares,
 * so the two never run together: while the extension is active its own flag decides, and otherwise the Multi-currency
 * feature does. WooCommerce's Multi-Currency needs no payments provider; without one it runs on manual rates.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyRuntimeArbiter {

	/**
	 * Owner value: the WooPayments extension runs Multi-Currency.
	 *
	 * @var string
	 */
	const OWNER_EXTENSION = 'extension';

	/**
	 * Owner value: WooCommerce runs Multi-Currency.
	 *
	 * @var string
	 */
	const OWNER_BUILTIN = 'builtin';

	/**
	 * Owner value: nothing runs Multi-Currency on this site.
	 *
	 * @var string
	 */
	const OWNER_NONE = 'none';

	/**
	 * Payments runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $payments_arbiter;

	/**
	 * Proxy for global function calls that tests can replace.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $payments_arbiter Payments runtime owner arbiter.
	 * @param LegacyProxy               $legacy_proxy Proxy for global function calls.
	 */
	final public function init( WooPaymentsRuntimeArbiter $payments_arbiter, LegacyProxy $legacy_proxy ): void {
		$this->payments_arbiter = $payments_arbiter;
		$this->legacy_proxy     = $legacy_proxy;
	}

	/**
	 * Tell whether the Multi-currency feature is enabled.
	 *
	 * @return bool True when the feature is enabled.
	 */
	public function feature_is_enabled(): bool {
		// Ownership is decided before `init`; asking FeaturesController would build every feature definition and load translations too early.
		return 'yes' === get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
	}

	/**
	 * Tell whether the WooPayments extension is the payments owner; its Multi-Currency flag then decides who runs Multi-Currency.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_payments_extension_owner(): bool {
		return WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $this->payments_arbiter->get_runtime_owner();
	}

	/**
	 * Tell whether the built-in WooPayments is the payments owner.
	 *
	 * Multi-Currency does not depend on it; the payments-owner sync reads it before it seeds the feature option.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_payments_builtin_owner(): bool {
		return WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $this->payments_arbiter->get_runtime_owner();
	}

	/**
	 * Get the Multi-Currency owner for the current site.
	 *
	 * @return string One of self::OWNER_EXTENSION, self::OWNER_BUILTIN, self::OWNER_NONE.
	 */
	public function get_runtime_owner(): string {
		if ( $this->is_payments_extension_owner() ) {
			return $this->is_extension_multi_currency_enabled() ? self::OWNER_EXTENSION : self::OWNER_NONE;
		}

		return $this->feature_is_enabled() ? self::OWNER_BUILTIN : self::OWNER_NONE;
	}

	/**
	 * Tell whether WooCommerce is the Multi-Currency owner, so the module registers its hooks.
	 *
	 * @return bool True when WooCommerce runs Multi-Currency.
	 */
	public function is_builtin_owner(): bool {
		return self::OWNER_BUILTIN === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the WooPayments extension is the Multi-Currency owner.
	 *
	 * @return bool True when the extension's Multi-Currency module runs.
	 */
	public function is_extension_owner(): bool {
		return self::OWNER_EXTENSION === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the WooPayments extension has its Multi-Currency flag on.
	 *
	 * The extension defaults `_wcpay_feature_customer_multi_currency` to on and does not load its Multi-Currency module
	 * when the option is `0`. WooCommerce's own Multi-Currency reads the Multi-currency feature instead.
	 *
	 * @return bool True when the flag is on.
	 */
	private function is_extension_multi_currency_enabled(): bool {
		return '1' === (string) $this->legacy_proxy->call_function( 'get_option', MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '1' );
	}
}
