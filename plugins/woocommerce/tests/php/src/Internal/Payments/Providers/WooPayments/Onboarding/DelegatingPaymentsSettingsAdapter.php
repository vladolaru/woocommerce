<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\OnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentsSettingsAdapter;
use WC_Payment_Gateway;

/**
 * An onboarding adapter for the Payments settings tests: account questions go to the test's adapter, and the adapter's own
 * onboarding methods run on the test's API client, account service, onboarding service and extension runtime.
 */
class DelegatingPaymentsSettingsAdapter extends WooPaymentsPaymentsSettingsAdapter {

	/**
	 * The test's adapter, which answers the account questions.
	 *
	 * @var WooPaymentsPaymentsSettingsAdapter
	 */
	private WooPaymentsPaymentsSettingsAdapter $inner;

	/**
	 * The test's extension runtime.
	 *
	 * @var WooPaymentsExtensionRuntime
	 */
	private WooPaymentsExtensionRuntime $test_extension_runtime;

	/**
	 * Constructor.
	 *
	 * @param WooPaymentsPaymentsSettingsAdapter $inner             The test's adapter.
	 * @param Client                             $api_client        The test's API client.
	 * @param WooPaymentsExtensionRuntime        $extension_runtime The test's extension runtime.
	 * @param WooPaymentsAccountService          $account_service   The test's account service.
	 * @param OnboardingService                  $onboarding        The test's onboarding service.
	 */
	public function __construct( WooPaymentsPaymentsSettingsAdapter $inner, Client $api_client, WooPaymentsExtensionRuntime $extension_runtime, WooPaymentsAccountService $account_service, OnboardingService $onboarding ) {
		$this->inner                  = $inner;
		$this->test_extension_runtime = $extension_runtime;

		$collaborators = array(
			'api_client'         => $api_client,
			'account_service'    => $account_service,
			'onboarding_service' => $onboarding,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsPaymentsSettingsAdapter::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $this, $collaborator );
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
	public function is_builtin_onboarding_available(): bool {
		return $this->inner->is_builtin_onboarding_available();
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
