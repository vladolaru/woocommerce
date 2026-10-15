<?php
/**
 * WooPaymentsAddressProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\AddressProvider\AbstractAutomatticAddressProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Address_Provider;
use WP_Error;

/**
 * Offers WooPayments address autocomplete to WooCommerce's address fields while the gateway is on and the account is
 * neither rejected nor under review, with a token the platform issues.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAddressProvider extends AbstractAutomatticAddressProvider implements RegisterHooksInterface {

	/**
	 * A value never accepted as a token.
	 */
	private const INVALID_TOKEN = 'INVALID_TOKEN';

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
	 * @param WooPaymentsApiClient      $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Register address provider hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_address_providers', array( $this, 'add_address_provider' ) );
	}

	/**
	 * Add the WooPayments address autocomplete provider when the account is eligible.
	 *
	 * @param mixed $providers Existing address providers.
	 * @return mixed
	 */
	public function add_address_provider( $providers ) {
		if ( ! is_array( $providers ) ) {
			return $providers;
		}

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
		if ( ! $this->account_service->has_account_or_is_connection_indeterminate() ) {
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
			// Client 11.1.0 logs the platform's message here when logging is on (`includes/class-wc-payments-address-provider.php:102`);
			// only its status and code are logged.
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Unexpected error getting address service JWT.', $e );

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
	 * Clear the cached token: the address provider's own cache and the `wcpay_address_autocomplete_jwt` option the
	 * WooPayments extension's cache wrote.
	 */
	private function clear_cached_jwt(): void {
		// @phpstan-ignore-next-line argument.type (the parent implementation accepts null to clear cached JWT data).
		$this->set_jwt( null );
		delete_option( WooPaymentsDatabaseCache::ADDRESS_AUTOCOMPLETE_JWT_KEY );
	}
}
