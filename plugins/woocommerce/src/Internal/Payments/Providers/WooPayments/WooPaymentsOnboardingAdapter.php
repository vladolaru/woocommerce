<?php
/**
 * WooPaymentsOnboardingAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Throwable;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges WooPayments onboarding/admin state to the native payments provider seam.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOnboardingAdapter {

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * WooPayments provider.
	 *
	 * @var WooPaymentsProvider|null
	 */
	private ?WooPaymentsProvider $provider = null;

	/**
	 * Native WooPayments gateway.
	 *
	 * @var NativeWooPaymentsGateway|null
	 */
	private ?NativeWooPaymentsGateway $native_gateway = null;

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * The native collaborators are resolved on first use, so a plugin-owned store's onboarding surfaces load only the plugin path.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime  $legacy_runtime WooPayments legacy runtime.
	 * @param WooPaymentsRuntimeArbiter $arbiter        Runtime ownership arbiter.
	 */
	final public function init( WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsRuntimeArbiter $arbiter ): void {
		$this->legacy_runtime = $legacy_runtime;
		$this->arbiter        = $arbiter;
	}

	/**
	 * Tell whether the standalone WooPayments extension is active.
	 *
	 * @return bool
	 */
	public function is_extension_active(): bool {
		return $this->get_legacy_runtime()->is_loaded();
	}

	/**
	 * Tell whether any WooPayments runtime can support onboarding/admin state.
	 *
	 * @return bool
	 */
	public function is_onboarding_runtime_available(): bool {
		return $this->is_extension_active() || $this->is_native_onboarding_available();
	}

	/**
	 * Tell whether the native WooPayments provider can manage onboarding/admin account operations.
	 *
	 * @return bool
	 */
	public function is_native_onboarding_available(): bool {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return false;
		}

		try {
			return $this->get_provider()->can_manage_onboarding();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the active WooPayments gateway.
	 *
	 * @return WC_Payment_Gateway
	 * @throws \RuntimeException When the WooPayments gateway is not available.
	 */
	public function get_payment_gateway(): WC_Payment_Gateway {
		if ( $this->is_extension_active() ) {
			$gateway = $this->get_legacy_runtime()->get_gateway();

			if ( $gateway instanceof WC_Payment_Gateway ) {
				return $gateway;
			}
		}

		if ( $this->is_native_onboarding_available() ) {
			return $this->get_native_gateway();
		}

		throw new \RuntimeException( 'WooPayments gateway is not available.' );
	}

	/**
	 * Determine if WooPayments has an account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_account( PaymentGateway $provider ): bool {
		if ( ! $this->is_onboarding_runtime_available() ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->has_account();
		}

		try {
			return $provider->is_account_connected( $this->get_payment_gateway() );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Determine if WooPayments has a valid, fully onboarded account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_valid_account( PaymentGateway $provider ): bool {
		if ( ! $this->has_account( $provider ) ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->can_process_payments();
		}

		$account_service = $this->get_account_service();

		return is_object( $account_service ) &&
			is_callable( array( $account_service, 'is_stripe_account_valid' ) ) &&
			(bool) $account_service->is_stripe_account_valid();
	}

	/**
	 * Determine if WooPayments has a working account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_working_account( PaymentGateway $provider ): bool {
		if ( ! $this->has_account( $provider ) ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->has_working_account();
		}

		$account_status = $this->get_account_status_data();

		return ! empty( $account_status['paymentsEnabled'] );
	}

	/**
	 * Determine if WooPayments has a test account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_test_account( PaymentGateway $provider ): bool {
		if ( ! $this->has_account( $provider ) ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->has_test_account();
		}

		$account_status = $this->get_account_status_data();

		return ! empty( $account_status['testDrive'] );
	}

	/**
	 * Determine if WooPayments has a sandbox account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_sandbox_account( PaymentGateway $provider ): bool {
		if ( ! $this->has_account( $provider ) ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->has_sandbox_account();
		}

		$account_status = $this->get_account_status_data();

		return empty( $account_status['isLive'] ) && empty( $account_status['testDrive'] );
	}

	/**
	 * Determine if WooPayments has a live account set up.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_live_account( PaymentGateway $provider ): bool {
		if ( ! $this->has_account( $provider ) ) {
			return false;
		}

		if ( ! $this->is_extension_active() ) {
			return $this->get_native_account_service()->has_live_account();
		}

		$account_status = $this->get_account_status_data();

		return ! empty( $account_status['isLive'] );
	}

	/**
	 * Get the fallback URL for the embedded KYC flow.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return string
	 */
	public function get_onboarding_kyc_fallback_url( PaymentGateway $provider ): string {
		$connect_url = $this->get_legacy_runtime()->get_account_connect_url( WooPaymentsService::FROM_NOX_IN_CONTEXT );
		if ( null !== $connect_url ) {
			return $connect_url;
		}

		return $provider->get_onboarding_url(
			$this->get_payment_gateway(),
			Utils::wc_payments_settings_url(
				WooPaymentsService::ONBOARDING_PATH_BASE,
				array( 'from' => WooPaymentsService::FROM_KYC )
			)
		);
	}

	/**
	 * Get the WooPayments Overview page URL.
	 *
	 * @return string
	 */
	public function get_overview_page_url(): string {
		$overview_url = $this->get_legacy_runtime()->get_account_overview_page_url();
		if ( null !== $overview_url ) {
			return add_query_arg(
				array(
					'from' => WooPaymentsService::FROM_NOX_IN_CONTEXT,
				),
				$overview_url
			);
		}

		return Utils::wc_payments_settings_url(
			WooPaymentsService::OVERVIEW_PATH,
			array(
				'from' => WooPaymentsService::FROM_NOX_IN_CONTEXT,
			)
		);
	}

	/**
	 * Get the WooPayments account service.
	 *
	 * @return object|null
	 */
	private function get_account_service(): ?object {
		return $this->get_legacy_runtime()->get_account_service();
	}

	/**
	 * Get account status data from the active WooPayments account service.
	 *
	 * @return array<string,mixed>
	 */
	private function get_account_status_data(): array {
		$account_service = $this->get_account_service();

		if ( ! is_object( $account_service ) || ! is_callable( array( $account_service, 'get_account_status_data' ) ) ) {
			return array();
		}

		$account_status = $account_service->get_account_status_data();

		return is_array( $account_status ) ? $account_status : array();
	}

	/**
	 * Get the WooPayments legacy runtime.
	 *
	 * @return WooPaymentsLegacyRuntime
	 */
	private function get_legacy_runtime(): WooPaymentsLegacyRuntime {
		return $this->legacy_runtime;
	}

	/**
	 * Get the native WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_native_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Get the WooPayments provider.
	 *
	 * @return WooPaymentsProvider
	 */
	private function get_provider(): WooPaymentsProvider {
		if ( null === $this->provider ) {
			$this->provider = wc_get_container()->get( WooPaymentsProvider::class );
		}

		return $this->provider;
	}

	/**
	 * Get the native WooPayments gateway.
	 *
	 * @return NativeWooPaymentsGateway
	 */
	private function get_native_gateway(): NativeWooPaymentsGateway {
		if ( null === $this->native_gateway ) {
			$this->native_gateway = wc_get_container()->get( NativeWooPaymentsGateway::class );
		}

		return $this->native_gateway;
	}
}
