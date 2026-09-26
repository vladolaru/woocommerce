<?php
/**
 * WooPaymentsNativeAccountAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

/**
 * Adapts the native WooPayments account service to the native multi-currency boundary.
 *
 * @since 11.0.0
 * @internal Transitional bridge while WooPayments provider transport is absorbed into core.
 */
class WooPaymentsNativeAccountAdapter implements MultiCurrencyAccountInterface {

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Native WooPayments account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Tell whether the rate provider account is connected.
	 *
	 * @param bool $on_error Value to return on provider errors.
	 * @return bool
	 */
	public function is_provider_connected( bool $on_error = false ): bool {
		try {
			return $this->account_service->has_account();
		} catch ( \Throwable $e ) {
			return $on_error;
		}
	}

	/**
	 * Tell whether the connected account is rejected.
	 *
	 * @return bool
	 */
	public function is_account_rejected(): bool {
		try {
			return $this->account_service->is_account_rejected();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get cached provider account data.
	 *
	 * @param bool $force_refresh Whether to force-refresh provider data.
	 * @return array<string,mixed>|bool
	 */
	public function get_cached_account_data( bool $force_refresh = false ) {
		try {
			return $this->account_service->get_cached_account_data( $force_refresh );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get account-supported customer currencies.
	 *
	 * @return string[]
	 */
	public function get_account_customer_supported_currencies(): array {
		try {
			return $this->account_service->get_customer_supported_currencies();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Get provider-supported countries.
	 *
	 * @return array<string,string>
	 */
	public function get_supported_countries(): array {
		try {
			return $this->account_service->get_supported_countries();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Get the provider onboarding URL.
	 *
	 * @return string
	 */
	public function get_provider_onboarding_page_url(): string {
		try {
			return Utils::wc_payments_settings_url( '/woopayments/onboarding' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
