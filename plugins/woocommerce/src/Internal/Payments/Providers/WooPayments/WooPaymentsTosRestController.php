<?php
/**
 * WooPaymentsTosRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments Terms of Service REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTosRestController implements RegisterHooksInterface {

	private const NAMESPACE = 'wc/v3';

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const ONBOARDING_STRIPE_CONNECTED_OPTION = '_wcpay_onboarding_stripe_connected';

	private const RESULT_SUCCESS = 'success';

	private const RESULT_BAD_REQUEST = 'bad_request';

	private const RESULT_ERROR = 'error';

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
	 * Register REST hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'rest_api_init', array( $this, 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}
	}

	/**
	 * Register WooPayments-compatible ToS routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/payments/tos', $this->get_creatable_route( 'handle_tos' ) );
		register_rest_route( self::NAMESPACE, '/payments/tos/reactivate', $this->get_creatable_route( 'reactivate' ) );
		register_rest_route( self::NAMESPACE, '/payments/tos/stripe_track_connected', $this->get_creatable_route( 'remove_stripe_connect_track' ) );
	}

	/**
	 * Check route permissions.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Record ToS acceptance or decline.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_tos( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		try {
			if ( ! array_key_exists( 'accept', $body ) ) {
				return $this->result_response( self::RESULT_BAD_REQUEST, 400 );
			}

			if ( (bool) $body['accept'] ) {
				$this->handle_tos_accepted();
			} else {
				$this->set_gateway_enabled( false );
			}
		} catch ( Throwable $exception ) {
			$this->log_exception( 'Failed to handle WooPayments Terms of Service request.', $exception );

			return $this->result_response( self::RESULT_ERROR, 500 );
		}

		return $this->result_response( self::RESULT_SUCCESS );
	}

	/**
	 * Re-enable the gateway after a ToS decline.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function reactivate( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		try {
			$this->set_gateway_enabled( true );
		} catch ( Throwable $exception ) {
			$this->log_exception( 'Failed to reactivate WooPayments after Terms of Service decline.', $exception );

			return $this->result_response( self::RESULT_ERROR, 500 );
		}

		return $this->result_response( self::RESULT_SUCCESS );
	}

	/**
	 * Clear Stripe connection tracking after it has been recorded.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function remove_stripe_connect_track( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		delete_option( self::ONBOARDING_STRIPE_CONNECTED_OPTION );

		return $this->result_response( self::RESULT_SUCCESS );
	}

	/**
	 * Process ToS acceptance.
	 */
	private function handle_tos_accepted(): void {
		$this->set_gateway_enabled( true );

		$current_user = wp_get_current_user();
		$this->api_client->add_account_tos_agreement( 'settings-popup', (string) $current_user->user_login );
		$this->account_service->refresh_account_data();
	}

	/**
	 * Persist the native WooPayments gateway enabled state.
	 *
	 * @param bool $enabled Whether the gateway should be enabled.
	 */
	private function set_gateway_enabled( bool $enabled ): void {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['enabled'] = $enabled ? 'yes' : 'no';

		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Get a creatable route definition.
	 *
	 * @param string $callback Callback method.
	 * @return array<string,mixed>
	 */
	private function get_creatable_route( string $callback ): array {
		return array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'check_permission' ),
		);
	}

	/**
	 * Build a result response.
	 *
	 * @param string $result Result code.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function result_response( string $result, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( array( 'result' => $result ), $status );
	}

	/**
	 * Log a ToS route exception.
	 *
	 * @param string    $message   Log message.
	 * @param Throwable $exception Exception.
	 */
	private function log_exception( string $message, Throwable $exception ): void {
		wc_get_logger()->error(
			$message . ' ' . $exception->getMessage(),
			array( 'source' => 'woopayments-tos' )
		);
	}
}
