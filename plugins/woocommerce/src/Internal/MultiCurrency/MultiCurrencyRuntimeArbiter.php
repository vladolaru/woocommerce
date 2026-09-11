<?php
/**
 * MultiCurrencyRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
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
	 * Multi-currency feature identifier.
	 *
	 * @deprecated 11.2.0 Use MultiCurrencyFeatureController::FEATURE_ID instead.
	 *
	 * @var string
	 */
	public const FEATURE_ID = MultiCurrencyFeatureController::FEATURE_ID;

	/**
	 * Option storing whether the multi-currency feature is enabled.
	 *
	 * @deprecated 11.2.0 Use MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION instead.
	 *
	 * @var string
	 */
	public const FEATURE_ENABLE_OPTION = MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION;

	/**
	 * Owner value: the standalone WooPayments plugin owns multi-currency.
	 *
	 * @var string
	 */
	const OWNER_PLUGIN = 'plugin';

	/**
	 * Owner value: WooCommerce core owns multi-currency.
	 *
	 * @var string
	 */
	const OWNER_CORE = 'core';

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
	 * Core feature controller.
	 *
	 * @var FeaturesController
	 */
	private FeaturesController $features_controller;

	/**
	 * Multi-Currency feature definition controller.
	 *
	 * @var MultiCurrencyFeatureController
	 */
	private MultiCurrencyFeatureController $feature_controller;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter   $payments_arbiter Payments runtime owner arbiter.
	 * @param LegacyProxy                    $legacy_proxy Legacy proxy.
	 * @param FeaturesController             $features_controller Core feature controller.
	 * @param MultiCurrencyFeatureController $feature_controller Multi-Currency feature definition controller.
	 */
	final public function init( NativePaymentsRuntimeArbiter $payments_arbiter, LegacyProxy $legacy_proxy, FeaturesController $features_controller, MultiCurrencyFeatureController $feature_controller ): void {
		$this->payments_arbiter    = $payments_arbiter;
		$this->legacy_proxy        = $legacy_proxy;
		$this->features_controller = $features_controller;
		$this->feature_controller  = $feature_controller;
	}

	/**
	 * Add the multi-currency feature definition.
	 *
	 * @param FeaturesController $features_controller Feature controller receiving the definition.
	 *
	 * @internal For exclusive usage of WooCommerce core, backwards compatibility not guaranteed.
	 */
	public function add_feature_definition( FeaturesController $features_controller ): void {
		$this->feature_controller->add_feature_definition( $features_controller );
	}

	/**
	 * Tell whether the independent core multi-currency feature is enabled.
	 *
	 * @return bool True when the core feature is enabled.
	 */
	public function feature_is_enabled(): bool {
		return $this->features_controller->feature_is_enabled( self::FEATURE_ID );
	}

	/**
	 * Get the multi-currency runtime owner for the current site.
	 *
	 * @return string One of self::OWNER_PLUGIN, self::OWNER_CORE, self::OWNER_NONE.
	 */
	public function get_runtime_owner(): string {
		$payments_owner = $this->payments_arbiter->get_runtime_owner();

		if ( NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $payments_owner && $this->is_plugin_multi_currency_enabled() ) {
			return self::OWNER_PLUGIN;
		}

		if ( NativePaymentsRuntimeArbiter::OWNER_NATIVE === $payments_owner && $this->feature_is_enabled() ) {
			return self::OWNER_CORE;
		}

		return self::OWNER_NONE;
	}

	/**
	 * Tell whether core multi-currency may register price/currency hooks.
	 *
	 * @return bool True when core owns multi-currency.
	 */
	public function should_core_register(): bool {
		return self::OWNER_CORE === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the standalone plugin owns multi-currency.
	 *
	 * @return bool True when plugin multi-currency owns the price/currency pipeline.
	 */
	public function should_plugin_register(): bool {
		return self::OWNER_PLUGIN === $this->get_runtime_owner();
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
