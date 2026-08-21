<?php
/**
 * WooPaymentsFraudService class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Anti-fraud services integration for native WooPayments.
 *
 * Owns the prepared fraud-services configuration served to checkout surfaces —
 * the raw account payload resolved through the platform's public-config
 * fallback, then prepared per service (test-mode beacon key swap, Sift user
 * and session identity) — so the browser-side fraud scripts receive the same
 * config the WooPayments plugin serves.
 *
 * Distinct from WooPaymentsFraudPreventionService, which owns the
 * card-testing prevention token.
 *
 * @internal
 */
class WooPaymentsFraudService {

	/**
	 * Filter applied to the whole prepared fraud-services config before it is served to clients.
	 */
	public const FILTER_FRAUD_SERVICES_CONFIG = 'woocommerce_woopayments_fraud_services_config';

	/**
	 * Filter applied to each prepared fraud-service config before it is served to clients.
	 *
	 * Returning null disables the service for the current request.
	 */
	public const FILTER_FRAUD_SERVICE_CONFIG = 'woocommerce_woopayments_fraud_service_config';

	/**
	 * Option holding the persisted random store ID used to derive Sift session IDs.
	 *
	 * Deliberately reuses the WooPayments plugin's option name: a store cut over
	 * from the plugin to the native integration must keep deriving the same
	 * session IDs, or Sift loses the continuity of every ongoing browsing session.
	 */
	private const SESSION_STORE_ID_OPTION = 'wcpay_session_store_id';

	/**
	 * Transient caching the platform's public fraud-services config.
	 */
	private const PUBLIC_CONFIG_TRANSIENT = 'woocommerce_woopayments_public_fraud_services';

	/**
	 * Sentinel cached when the public-config fetch fails, so checkout renders
	 * do not retry the platform on every request.
	 */
	private const PUBLIC_CONFIG_FAILURE_SENTINEL = 'fetch-failed';

	/**
	 * How long a failed public-config fetch suppresses retries.
	 */
	private const PUBLIC_CONFIG_FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the service.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService  $account_service  Account service.
	 * @param WooPaymentsCustomerService $customer_service Customer service.
	 * @param WooPaymentsApiClient       $api_client       Native WooPayments API client.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsCustomerService $customer_service, WooPaymentsApiClient $api_client ): void {
		$this->account_service  = $account_service;
		$this->customer_service = $customer_service;
		$this->api_client       = $api_client;
	}

	/**
	 * Get the prepared fraud-services config for browser consumption.
	 *
	 * Resolution order matches the WooPayments plugin: the merchant-specific
	 * account payload wins (an explicitly empty list is respected), the
	 * platform's cached public config is the fallback, and a bare `stripe`
	 * entry is the backward-compatible default.
	 *
	 * @return array<string,array<string,mixed>|null> Prepared config keyed by service ID; a null value means the service is disabled.
	 */
	public function get_fraud_services_config(): array {
		$account_data = $this->account_service->get_cached_account_data();

		$raw_config = null;
		if ( isset( $account_data['fraud_services'] ) && is_array( $account_data['fraud_services'] ) ) {
			$raw_config = $account_data['fraud_services'];
		}

		if ( null === $raw_config ) {
			$raw_config = $this->get_cached_public_config();
		}

		if ( null === $raw_config ) {
			$raw_config = array( 'stripe' => array() );
		}

		$services_config = array();
		foreach ( $raw_config as $service_id => $service_config ) {
			$service_id = (string) $service_id;
			if ( ! is_array( $service_config ) ) {
				$service_config = array();
			}

			$service_config = $this->prepare_service_config( $service_id, $service_config );

			/**
			 * Filters a single prepared fraud-service config before it is served to clients.
			 *
			 * @since 11.0.0
			 *
			 * @param array<string,mixed>|null $service_config Prepared service config, or null when the service should not be used.
			 * @param string                   $service_id     Fraud service identifier (e.g. 'sift').
			 */
			$services_config[ $service_id ] = apply_filters( self::FILTER_FRAUD_SERVICE_CONFIG, $service_config, $service_id );
		}

		/**
		 * Filters native WooPayments fraud-services config.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $services_config Prepared fraud-services config keyed by service ID.
		 */
		$services_config = apply_filters( self::FILTER_FRAUD_SERVICES_CONFIG, $services_config );

		return is_array( $services_config ) ? $services_config : array();
	}

	/**
	 * Prepare a single service's config.
	 *
	 * @param string              $service_id     Fraud service identifier.
	 * @param array<string,mixed> $service_config Raw service config.
	 * @return array<string,mixed> Prepared config.
	 */
	private function prepare_service_config( string $service_id, array $service_config ): array {
		if ( 'sift' === $service_id ) {
			return $this->prepare_sift_config( $service_config );
		}

		return $service_config;
	}

	/**
	 * Prepare the Sift config: pick the mode-appropriate beacon key and attach
	 * the shopper's Sift identity.
	 *
	 * @param array<string,mixed> $config Raw Sift config from the platform.
	 * @return array<string,mixed> Prepared config.
	 */
	private function prepare_sift_config( array $config ): array {
		// The platform returns both production and sandbox beacon keys; the
		// sandbox key must replace the production one whenever the store is in
		// test mode so test traffic never trains the production Sift account.
		if ( $this->account_service->is_test_mode_enabled() && isset( $config['sandbox_beacon_key'] ) ) {
			$config['beacon_key'] = $config['sandbox_beacon_key'];
		}
		unset( $config['sandbox_beacon_key'] );

		$config['user_id']    = $this->get_sift_user_id();
		$config['session_id'] = $this->get_sift_session_id();

		return $config;
	}

	/**
	 * Get the Sift user ID for the current request.
	 *
	 * @return string Sift user ID; empty string when none could be determined.
	 */
	private function get_sift_user_id(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		if ( is_admin() ) {
			// In the WP admin we deal with the merchant, not a shopper.
			return $this->account_service->get_account_id();
		}

		$customer_id = $this->customer_service->get_customer_id_by_user_id( get_current_user_id() );

		return null !== $customer_id ? $customer_id : '';
	}

	/**
	 * Get the Sift session ID for the current browsing session.
	 *
	 * @return string|null Session ID, or null when there is no valid session for the current process.
	 */
	public function get_sift_session_id(): ?string {
		if ( $this->user_just_logged_in() ) {
			return $this->get_cookie_session_id();
		}

		if ( WC()->session instanceof \WC_Session ) {
			return $this->generate_session_id( (string) WC()->session->get_customer_id() );
		}

		return null;
	}

	/**
	 * Tell whether the current user logged in during this request — their
	 * session cookie still carries the pre-login customer ID.
	 *
	 * @return bool
	 */
	private function user_just_logged_in(): bool {
		if ( ! get_current_user_id() ) {
			return false;
		}

		WC()->initialize_session();
		$session_handler = WC()->session;
		// Some session handlers (e.g. the Store API one) do not expose the cookie.
		if ( ! $session_handler || ! method_exists( $session_handler, 'get_session_cookie' ) ) {
			return false;
		}
		$cookie = $session_handler->get_session_cookie();
		if ( ! $cookie ) {
			return false;
		}

		return $session_handler->get_customer_id() !== $cookie[0];
	}

	/**
	 * Get the session ID carried by the session cookie — the ID used for the
	 * browsing session up to now, before any login rotated the customer ID.
	 *
	 * @return string|null Session ID, or null when unknown.
	 */
	private function get_cookie_session_id(): ?string {
		$session_handler = WC()->session;
		if ( ! $session_handler || ! method_exists( $session_handler, 'get_session_cookie' ) ) {
			return null;
		}
		$cookie = $session_handler->get_session_cookie();
		if ( ! $cookie || ! isset( $cookie[0] ) ) {
			return null;
		}

		return $this->generate_session_id( (string) $cookie[0] );
	}

	/**
	 * Derive a Sift session ID from the persisted store ID and a session customer ID.
	 *
	 * @param string $session_customer_id WooCommerce session customer ID.
	 * @return string
	 */
	private function generate_session_id( string $session_customer_id ): string {
		return $this->get_store_id() . '_' . $session_customer_id;
	}

	/**
	 * Get the persisted random store ID, generating it on first use.
	 *
	 * @return string
	 */
	private function get_store_id(): string {
		$store_id = get_option( self::SESSION_STORE_ID_OPTION, false );
		if ( ! is_string( $store_id ) || '' === $store_id ) {
			// 'st_' prefix plus alphanumerics only, within Sift's user_id charset.
			$store_id = 'st_' . wp_generate_password( 29, false, false );
			update_option( self::SESSION_STORE_ID_OPTION, $store_id );
		}

		return $store_id;
	}

	/**
	 * Get the platform's public (merchant-agnostic) fraud-services config, cached.
	 *
	 * @return array<string,mixed>|null Public config, or null when unavailable.
	 */
	private function get_cached_public_config(): ?array {
		$cached = get_transient( self::PUBLIC_CONFIG_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( self::PUBLIC_CONFIG_FAILURE_SENTINEL === $cached ) {
			return null;
		}

		$fetched = $this->api_client->fetch_public_fraud_services_config();
		if ( ! is_array( $fetched ) ) {
			set_transient( self::PUBLIC_CONFIG_TRANSIENT, self::PUBLIC_CONFIG_FAILURE_SENTINEL, self::PUBLIC_CONFIG_FAILURE_TTL );

			return null;
		}

		$fetched = $this->sanitize_config_strings( $fetched );
		set_transient( self::PUBLIC_CONFIG_TRANSIENT, $fetched, DAY_IN_SECONDS );

		return $fetched;
	}

	/**
	 * Recursively sanitize string values in a config array.
	 *
	 * Only strings are sanitized: the config carries no HTML, and non-string
	 * values (flags, numbers) must not be cast to strings.
	 *
	 * @param array<string,mixed> $config Config to sanitize.
	 * @return array<string,mixed>
	 */
	private function sanitize_config_strings( array $config ): array {
		foreach ( $config as $key => $value ) {
			if ( is_array( $value ) ) {
				$config[ $key ] = $this->sanitize_config_strings( $value );
			} elseif ( is_string( $value ) ) {
				$config[ $key ] = sanitize_text_field( $value );
			}
		}

		return $config;
	}
}
