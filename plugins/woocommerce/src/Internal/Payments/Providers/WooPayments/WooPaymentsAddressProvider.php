<?php
/**
 * WooPaymentsAddressProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\AddressProvider\AbstractAutomatticAddressProvider;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Address_Provider;
use WP_Error;

/**
 * Native WooPayments address autocomplete provider.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAddressProvider extends AbstractAutomatticAddressProvider implements RegisterHooksInterface {

	/**
	 * Placeholder value used by the extension when token retrieval fails.
	 */
	private const INVALID_TOKEN = 'INVALID_TOKEN';

	/**
	 * Preserved extension cache option for address autocomplete JWTs.
	 */
	private const LEGACY_ADDRESS_AUTOCOMPLETE_JWT_OPTION = 'wcpay_address_autocomplete_jwt';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Whether the parent provider hooks have been initialized.
	 *
	 * @var bool
	 */
	private bool $provider_hooks_initialized = false;

	/**
	 * Create the address provider shell.
	 */
	public function __construct() {
		$this->id   = 'woocommerce_payments';
		$this->name = 'WooCommerce Payments';
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsApiClient         $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService    $account_service WooPayments account service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Register address provider hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_address_providers', array( $this, 'add_address_provider' ) ) ) {
			add_filter( 'woocommerce_address_providers', array( $this, 'add_address_provider' ) );
		}
	}

	/**
	 * Add the WooPayments address autocomplete provider when the account is eligible.
	 *
	 * @param array<int,mixed> $providers Existing address providers.
	 * @return array<int,mixed>
	 */
	public function add_address_provider( array $providers ): array {
		if (
			! $this->account_service->is_gateway_enabled() ||
			$this->account_service->is_account_rejected() ||
			$this->account_service->is_account_under_review()
		) {
			return $providers;
		}

		if ( $this->has_registered_provider( $providers ) ) {
			return $providers;
		}

		$this->name = __( 'WooCommerce Payments', 'woocommerce' );
		$this->initialize_provider_hooks();
		$providers[] = $this;

		return $providers;
	}

	/**
	 * Load address autocomplete scripts only when the WooCommerce setting is enabled.
	 */
	public function load_scripts(): void {
		if ( true === wc_string_to_bool( get_option( 'woocommerce_address_autocomplete_enabled', 'no' ) ) ) {
			parent::load_scripts();
		}
	}

	/**
	 * Get address service JWT token from the WooPayments server.
	 *
	 * @return string|WP_Error
	 */
	public function get_address_service_jwt() {
		if ( ! $this->account_service->has_account() ) {
			$this->clear_cached_jwt();

			return new WP_Error(
				'wcpay_address_service_error',
				__( 'Address autocomplete is unavailable because the payment account is not connected.', 'woocommerce' )
			);
		}

		try {
			$response = $this->api_client->get_address_autocomplete_token();
			$token    = $response['token'] ?? null;
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				'Unexpected error getting address service JWT: ' . $e->getMessage(),
				array( 'source' => 'woocommerce-woopayments' )
			);

			$token = null;
		}

		if ( ! is_string( $token ) || '' === $token || self::INVALID_TOKEN === $token ) {
			return new WP_Error(
				'wcpay_address_service_error',
				__( 'An unexpected error occurred while retrieving the address service token.', 'woocommerce' )
			);
		}

		return $token;
	}

	/**
	 * Tell whether the provider can send frontend telemetry data.
	 *
	 * @return bool
	 */
	public function can_telemetry() {
		return class_exists( '\WC_Site_Tracking' ) && \WC_Site_Tracking::is_tracking_enabled();
	}

	/**
	 * Initialize parent provider hooks once the provider is actually registered.
	 */
	private function initialize_provider_hooks(): void {
		if ( $this->provider_hooks_initialized ) {
			return;
		}

		parent::__construct();
		$this->provider_hooks_initialized = true;
	}

	/**
	 * Tell whether a WooPayments address provider is already registered.
	 *
	 * @param array<int,mixed> $providers Existing address providers.
	 * @return bool
	 */
	private function has_registered_provider( array $providers ): bool {
		foreach ( $providers as $provider ) {
			if ( $provider instanceof WC_Address_Provider && $this->id === $provider->id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear native and preserved extension JWT cache entries.
	 */
	private function clear_cached_jwt(): void {
		// @phpstan-ignore-next-line argument.type (the parent implementation accepts null to clear cached JWT data).
		$this->set_jwt( null );
		delete_option( self::LEGACY_ADDRESS_AUTOCOMPLETE_JWT_OPTION );
	}
}
