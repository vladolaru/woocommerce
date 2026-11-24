<?php
/**
 * WCPay\Core\Mode facade.
 */

declare( strict_types = 1 );

namespace WCPay\Core;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

/**
 * Exposes the built-in account's mode to integrations that use the WooPayments extension's readers.
 *
 * @since 11.4.0
 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
 */
class Mode {

	/**
	 * Report whether payments use live mode.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public function is_live(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return ! wc_get_container()->get( WooPaymentsAccountService::class )->is_test_mode_enabled();
	}

	/**
	 * Report whether payments use test mode.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public function is_test(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_test_mode_enabled();
	}

	/**
	 * Report whether onboarding uses test mode.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public function is_test_mode_onboarding(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_test_mode_onboarding_enabled();
	}

	/**
	 * Report whether development mode is enabled.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public function is_dev(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_dev_mode_enabled();
	}

	/**
	 * List the triggers that enabled development mode.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return string[]
	 */
	public function get_dev_mode_triggers(): array {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->get_dev_mode_triggers();
	}
}
