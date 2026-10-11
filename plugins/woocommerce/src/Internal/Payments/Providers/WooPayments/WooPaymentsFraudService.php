<?php
/**
 * WooPaymentsFraudService class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Admin\PageController;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

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
class WooPaymentsFraudService implements RegisterHooksInterface {

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
	 * Session service.
	 *
	 * @var WooPaymentsSessionService
	 */
	private WooPaymentsSessionService $session_service;

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
	 * @param WooPaymentsSessionService  $session_service  Session service.
	 * @param WooPaymentsApiClient       $api_client       Native WooPayments API client.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsCustomerService $customer_service, WooPaymentsSessionService $session_service, WooPaymentsApiClient $api_client ): void {
		$this->account_service  = $account_service;
		$this->customer_service = $customer_service;
		$this->session_service  = $session_service;
		$this->api_client       = $api_client;
	}

	/**
	 * Register the login session-link callback and the admin Sift page tracker.
	 */
	public function register() {
		if ( false === has_action( 'init', array( $this, 'link_session_if_user_just_logged_in' ) ) ) {
			add_action( 'init', array( $this, 'link_session_if_user_just_logged_in' ) );
		}
		if ( false === has_action( 'admin_print_footer_scripts', array( $this, 'handle_admin_print_footer_scripts' ) ) ) {
			add_action( 'admin_print_footer_scripts', array( $this, 'handle_admin_print_footer_scripts' ) );
		}
	}

	/**
	 * Print the Sift page tracker on WooCommerce admin pages, with the merchant's account as the Sift user.
	 *
	 * Same script as client 11.1.0 (`includes/class-wc-payments-fraud-service.php:83`, `:207-243`), only when Sift is
	 * configured. The client skips its own dashboard pages here because their JS loads Sift; native has no such loader, so
	 * every WooCommerce admin page prints it. A test-mode store without a sandbox beacon key gets no tracker, so test
	 * traffic never reaches the production Sift account.
	 *
	 * @internal
	 */
	public function handle_admin_print_footer_scripts(): void {
		if ( ! PageController::is_admin_or_embed_page() ) {
			return;
		}

		// The config comes through public filters, so every printed field must be a string.
		$sift       = $this->get_fraud_services_config()['sift'] ?? null;
		$beacon_key = is_array( $sift ) ? ( $sift['beacon_key'] ?? null ) : null;
		$user_id    = is_array( $sift ) ? ( $sift['user_id'] ?? '' ) : '';
		$session_id = is_array( $sift ) ? ( $sift['session_id'] ?? '' ) : '';
		if ( ! is_string( $beacon_key ) || '' === $beacon_key || ! is_string( $user_id ) || ! is_string( $session_id ) ) {
			return;
		}
		?>
		<script type="text/javascript">
			var src = 'https://cdn.sift.com/s.js';

			var _sift = ( window._sift = window._sift || [] );
			_sift.push( [ '_setAccount', <?php echo wp_json_encode( $beacon_key ); ?> ] );
			_sift.push( [ '_setUserId', <?php echo wp_json_encode( $user_id ); ?> ] );
			_sift.push( [ '_setSessionId', <?php echo wp_json_encode( $session_id ); ?> ] );
			_sift.push( [ '_trackPageview' ] );

			if ( ! document.querySelector( '[src="' + src + '"]' ) ) {
				var script = document.createElement( 'script' );
				script.src = src;
				script.async = true;
				document.body.appendChild( script );
			}
		</script>
		<?php
	}

	/**
	 * Link the pre-login browsing session to the shopper's WooPayments customer right after they log in.
	 *
	 * Sift then sees the session before and after login as one shopper. Same checks and order as client 11.1.0
	 * (class-wc-payments-fraud-service.php:82, 150-197): no AJAX, WP-CLI or REST request, a connected store, a login during
	 * this request, Sift enabled and a stored customer; the cheap checks come first, so only those requests reach the platform.
	 *
	 * @internal
	 */
	public function link_session_if_user_just_logged_in(): void {
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// REST_REQUEST is not defined yet on init, so match the REST prefix in the request URI as the client does.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used for prefix matching.
		if ( '' !== $request_uri && false !== strpos( $request_uri, trailingslashit( rest_get_url_prefix() ) ) ) {
			return;
		}

		// A logged-out visitor cannot have just logged in; skip the connection check for them.
		if ( 0 === get_current_user_id() ) {
			return;
		}

		if ( ! $this->api_client->is_available() || ! $this->session_service->user_just_logged_in() ) {
			return;
		}

		$fraud_config = $this->get_fraud_services_config();
		if ( ! isset( $fraud_config['sift'] ) ) {
			return;
		}

		$customer_id = $this->customer_service->get_customer_id_by_user_id( get_current_user_id() );
		if ( null === $customer_id ) {
			return;
		}

		try {
			$this->api_client->link_session_to_customer( $this->session_service->get_sift_session_id(), $customer_id );
		} catch ( WooPaymentsApiException $exception ) {
			// Client 11.1.0 fraud-service:195 logs this at info level through its gated Logger, with the platform's message;
			// native logs its status and code instead.
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( '[Tracking] Error when linking session with user.', $exception, array(), 'info' );
		}
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
			$filtered_service_config = apply_filters( 'wcpay_prepare_fraud_config', $service_config, $service_id );

			$services_config[ $service_id ] = is_array( $filtered_service_config ) || null === $filtered_service_config ? $filtered_service_config : $service_config;
		}

		return $services_config;
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
		// When the platform sends no sandbox key, fail safe and ship no beacon
		// key at all — a deliberate divergence from the plugin, which falls
		// through to the production key in that config shape.
		if ( $this->account_service->is_test_mode_enabled() ) {
			if ( isset( $config['sandbox_beacon_key'] ) ) {
				$config['beacon_key'] = $config['sandbox_beacon_key'];
			} else {
				unset( $config['beacon_key'] );
			}
		}
		unset( $config['sandbox_beacon_key'] );

		$config['user_id']    = $this->get_sift_user_id();
		$config['session_id'] = $this->session_service->get_sift_session_id();

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
