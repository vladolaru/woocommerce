<?php
/**
 * MultiCurrencyRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Decides which multi-currency runtime owns the price/currency pipeline.
 *
 * During the WooPayments merge, the standalone plugin still contains the legacy
 * multi-currency module. Core-native multi-currency must therefore use the same
 * ownership signal as native payments: plugin-mode keeps plugin multi-currency,
 * and native payments mode flips the price/currency pipeline to core.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyRuntimeArbiter {

	/**
	 * Owner value: the standalone WooPayments plugin owns multi-currency.
	 *
	 * @var string
	 */
	const OWNER_EXTENSION = 'extension';

	/**
	 * Owner value: WooCommerce core owns multi-currency.
	 *
	 * @var string
	 */
	const OWNER_BUILTIN = 'builtin';

	/**
	 * Owner value: no multi-currency runtime is active for this site.
	 *
	 * @var string
	 */
	const OWNER_NONE = 'none';

	/**
	 * Payments runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $payments_arbiter;

	/**
	 * Legacy proxy for mockable global calls.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $payments_arbiter Payments runtime owner arbiter.
	 * @param LegacyProxy                  $legacy_proxy Legacy proxy.
	 */
	final public function init( NativePaymentsRuntimeArbiter $payments_arbiter, LegacyProxy $legacy_proxy ): void {
		$this->payments_arbiter = $payments_arbiter;
		$this->legacy_proxy     = $legacy_proxy;
	}

	/**
	 * Tell whether the independent core multi-currency feature is enabled.
	 *
	 * @return bool True when the core feature is enabled.
	 */
	public function feature_is_enabled(): bool {
		// Ownership is decided before `init`; asking FeaturesController would build every feature definition and load translations too early.
		return 'yes' === get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
	}

	/**
	 * Tell whether the WooPayments extension owns payments, so its own Multi-Currency module may run.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_payments_extension_owner(): bool {
		return NativePaymentsRuntimeArbiter::OWNER_EXTENSION === $this->payments_arbiter->get_runtime_owner();
	}

	/**
	 * Tell whether the built-in WooPayments owns payments, so WooCommerce's Multi-Currency may run.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_payments_builtin_owner(): bool {
		return NativePaymentsRuntimeArbiter::OWNER_BUILTIN === $this->payments_arbiter->get_runtime_owner();
	}

	/**
	 * Get the multi-currency runtime owner for the current site.
	 *
	 * @return string One of self::OWNER_EXTENSION, self::OWNER_BUILTIN, self::OWNER_NONE.
	 */
	public function get_runtime_owner(): string {
		if ( $this->is_payments_extension_owner() && $this->is_plugin_multi_currency_enabled() ) {
			return self::OWNER_EXTENSION;
		}

		if ( $this->is_payments_builtin_owner() && $this->feature_is_enabled() ) {
			return self::OWNER_BUILTIN;
		}

		return self::OWNER_NONE;
	}

	/**
	 * Tell whether core multi-currency may register price/currency hooks.
	 *
	 * @return bool True when core owns multi-currency.
	 */
	public function should_core_register(): bool {
		return self::OWNER_BUILTIN === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the standalone plugin owns multi-currency.
	 *
	 * @return bool True when plugin multi-currency owns the price/currency pipeline.
	 */
	public function should_plugin_register(): bool {
		return self::OWNER_EXTENSION === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the plugin has customer multi-currency switched on.
	 *
	 * WooPayments defaults `_wcpay_feature_customer_multi_currency` to enabled and
	 * returns before loading its multi-currency module when the option is `0`.
	 * The core-owned runtime uses its independent WooCommerce feature setting.
	 *
	 * @return bool True when the multi-currency runtime may take ownership.
	 */
	private function is_plugin_multi_currency_enabled(): bool {
		return '1' === (string) $this->legacy_proxy->call_function( 'get_option', '_wcpay_feature_customer_multi_currency', '1' );
	}
}
