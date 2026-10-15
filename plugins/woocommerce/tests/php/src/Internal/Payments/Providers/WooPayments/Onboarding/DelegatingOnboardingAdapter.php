<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Exception;
use WC_Payment_Gateway;

/**
 * An onboarding adapter for the Payments settings tests: account questions go to the test's adapter, and the built-in
 * onboarding is the test's onboarding service, chosen with the test's API client and extension runtime.
 */
class DelegatingOnboardingAdapter extends WooPaymentsOnboardingAdapter {

	/**
	 * The test's adapter, which answers the account questions.
	 *
	 * @var WooPaymentsOnboardingAdapter
	 */
	private WooPaymentsOnboardingAdapter $inner;

	/**
	 * The test's API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $test_api_client;

	/**
	 * The test's extension runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $test_extension_runtime;

	/**
	 * The test's account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $test_account_service;

	/**
	 * The test's onboarding service.
	 *
	 * @var WooPaymentsOnboardingService
	 */
	private WooPaymentsOnboardingService $test_onboarding;

	/**
	 * Constructor.
	 *
	 * @param WooPaymentsOnboardingAdapter $inner           The test's adapter.
	 * @param WooPaymentsApiClient         $api_client      The test's API client.
	 * @param WooPaymentsLegacyRuntime     $extension_runtime The test's extension runtime.
	 * @param WooPaymentsAccountService    $account_service The test's account service.
	 * @param WooPaymentsOnboardingService $onboarding      The test's onboarding service.
	 */
	public function __construct( WooPaymentsOnboardingAdapter $inner, WooPaymentsApiClient $api_client, WooPaymentsLegacyRuntime $extension_runtime, WooPaymentsAccountService $account_service, WooPaymentsOnboardingService $onboarding ) {
		$this->inner                  = $inner;
		$this->test_api_client        = $api_client;
		$this->test_extension_runtime = $extension_runtime;
		$this->test_account_service   = $account_service;
		$this->test_onboarding        = $onboarding;
	}

	/**
	 * The built-in onboarding runs when the API client is available, no extension is loaded and the test's adapter says
	 * the built-in WooPayments can onboard.
	 *
	 * @return WooPaymentsOnboardingService|null
	 */
	public function get_builtin_onboarding(): ?WooPaymentsOnboardingService {
		if ( $this->test_api_client->is_available() && ! $this->test_extension_runtime->is_loaded() && $this->inner->is_native_onboarding_available() ) {
			return $this->test_onboarding;
		}

		return null;
	}

	/**
	 * The KYC fields from the test's account service, unless an extension is loaded or the API client is unavailable.
	 *
	 * @param string $locale The user's locale.
	 * @return array|null
	 * @throws Exception When the account service answers no fields.
	 */
	public function get_onboarding_fields( string $locale ): ?array {
		if ( $this->test_extension_runtime->is_loaded() || ! $this->test_api_client->is_available() ) {
			return null;
		}

		$fields = $this->test_account_service->get_onboarding_fields_data( $locale );
		if ( null === $fields ) {
			throw new Exception( 'Failed to retrieve the onboarding fields.' );
		}

		return $fields;
	}

	/**
	 * Turn WooPay on through the test's onboarding service when the platform asked for it.
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
	 * Delegate.
	 *
	 * @return bool
	 */
	public function is_extension_active(): bool {
		return $this->test_extension_runtime->is_loaded();
	}

	/**
	 * Delegate.
	 *
	 * @return bool
	 */
	public function is_onboarding_runtime_available(): bool {
		return $this->inner->is_onboarding_runtime_available();
	}

	/**
	 * Delegate.
	 *
	 * @return bool
	 */
	public function is_native_onboarding_available(): bool {
		return $this->inner->is_native_onboarding_available();
	}

	/**
	 * Delegate.
	 *
	 * @return WC_Payment_Gateway
	 */
	public function get_payment_gateway(): WC_Payment_Gateway {
		return $this->inner->get_payment_gateway();
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_account( PaymentGateway $provider ): bool {
		return $this->inner->has_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_valid_account( PaymentGateway $provider ): bool {
		return $this->inner->has_valid_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_working_account( PaymentGateway $provider ): bool {
		return $this->inner->has_working_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_test_account( PaymentGateway $provider ): bool {
		return $this->inner->has_test_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_sandbox_account( PaymentGateway $provider ): bool {
		return $this->inner->has_sandbox_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return bool
	 */
	public function has_live_account( PaymentGateway $provider ): bool {
		return $this->inner->has_live_account( $provider );
	}

	/**
	 * Delegate.
	 *
	 * @param PaymentGateway $provider Admin payment gateway provider.
	 * @return string
	 */
	public function get_onboarding_kyc_fallback_url( PaymentGateway $provider ): string {
		return $this->inner->get_onboarding_kyc_fallback_url( $provider );
	}
}
