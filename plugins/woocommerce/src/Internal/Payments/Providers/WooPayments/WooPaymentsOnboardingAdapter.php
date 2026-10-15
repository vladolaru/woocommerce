<?php
/**
 * WooPaymentsOnboardingAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Exception;
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
	 * The extension's `WC_Payments_Account::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT`: the platform asked to turn WooPay on after KYC.
	 *
	 * Declared here because this class loads on every request, so reading the name loads no other file.
	 */
	public const WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT = 'woopay_enabled_by_default';

	/**
	 * The WooPayments Overview page's route in the Payments settings, declared here for the same reason.
	 */
	public const OVERVIEW_PATH = '/woopayments/overview';

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
	 * @var WooPaymentsGateway|null
	 */
	private ?WooPaymentsGateway $native_gateway = null;

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient|null
	 */
	private ?WooPaymentsApiClient $api_client = null;

	/**
	 * The onboarding built into WooCommerce.
	 *
	 * @var WooPaymentsOnboardingService|null
	 */
	private ?WooPaymentsOnboardingService $onboarding_service = null;

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
	 * Get the onboarding built into WooCommerce, when it runs this store's onboarding.
	 *
	 * It does while the platform connection is available, the WooPayments extension is not active and the built-in
	 * WooPayments owns the store. Otherwise the extension's onboarding routes answer.
	 *
	 * @return WooPaymentsOnboardingService|null
	 */
	public function get_builtin_onboarding(): ?WooPaymentsOnboardingService {
		if ( $this->get_api_client()->is_available() && ! $this->is_extension_active() && $this->is_native_onboarding_available() ) {
			return $this->get_onboarding_service();
		}

		return null;
	}

	/**
	 * Get the KYC fields from the platform, through the account service's cache.
	 *
	 * @param string $locale The user's locale.
	 * @return array|null The fields, or null while the extension is active or the platform connection is unavailable.
	 * @throws Exception When the platform answers no fields.
	 */
	public function get_onboarding_fields( string $locale ): ?array {
		if ( $this->is_extension_active() || ! $this->get_api_client()->is_available() ) {
			return null;
		}

		$fields = $this->get_native_account_service()->get_onboarding_fields_data( $locale );
		if ( null === $fields ) {
			// The extension's fields route answers the same message.
			throw new Exception( esc_html__( 'Failed to retrieve the onboarding fields.', 'woocommerce' ) );
		}

		return $fields;
	}

	/**
	 * Turn WooPay on when the platform asked for it during onboarding and the built-in onboarding runs.
	 *
	 * The stored answer is read first, so the landing page loads nothing more when there is none.
	 */
	public function maybe_activate_woopay_enabled_by_default(): void {
		if ( ! get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ) ) {
			return;
		}

		$onboarding = $this->get_builtin_onboarding();
		if ( null !== $onboarding ) {
			$onboarding->activate_woopay_enabled_by_default();
		}
	}

	/**
	 * Get the URL of the WooPayments Overview page in the Payments settings.
	 *
	 * @return string
	 */
	public function get_overview_page_url(): string {
		return Utils::wc_payments_settings_url( self::OVERVIEW_PATH );
	}

	/**
	 * Send a request for a guarded WooPayments admin page to onboarding when the store has no working connection and
	 * valid account, deciding once per request.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_onboarding(): void {
		wc_get_container()->get( WooPaymentsOnboardingRedirect::class )->maybe_redirect();
	}

	/**
	 * Drop the onboarding fields cache while the built-in WooPayments owns payments; the extension keeps its own.
	 *
	 * @return void
	 */
	public function clear_builtin_onboarding_fields_cache(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		$this->get_native_account_service()->clear_onboarding_fields_cache();
	}

	/**
	 * Get the WooPayments settings notice for the current user, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_settings_notice_for_current_user(): ?array {
		return wc_get_container()->get( WooPaymentsAdminNoticeService::class )->get_notice_for_current_user();
	}

	/**
	 * Store a partner's referral code with the built-in onboarding.
	 *
	 * A referral link can arrive before the store connects to the platform, so this does not ask whether the built-in
	 * onboarding runs; the caller has already checked that the extension is not active.
	 *
	 * @param string $referral_code The sanitized code from the referral link.
	 * @return string The stored code, or an empty string when there is none.
	 */
	public function store_referral_code( string $referral_code ): string {
		return $this->get_onboarding_service()->store_referral_code( $referral_code );
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
	 * @return WooPaymentsGateway
	 */
	private function get_native_gateway(): WooPaymentsGateway {
		if ( null === $this->native_gateway ) {
			$this->native_gateway = wc_get_container()->get( WooPaymentsGateway::class );
		}

		return $this->native_gateway;
	}

	/**
	 * Get the WooPayments API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		if ( null === $this->api_client ) {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		}

		return $this->api_client;
	}

	/**
	 * Get the onboarding built into WooCommerce.
	 *
	 * @return WooPaymentsOnboardingService
	 */
	private function get_onboarding_service(): WooPaymentsOnboardingService {
		if ( null === $this->onboarding_service ) {
			$this->onboarding_service = wc_get_container()->get( WooPaymentsOnboardingService::class );
		}

		return $this->onboarding_service;
	}
}
