<?php
/**
 * Plugin Name: WooPayments native E2E runtime
 * Description: Fail-closed native WooPayments activation and authenticated diagnostics for E2E environments.
 *
 * @package woopayments-native-runtime
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Installs the early native-runtime filter and read-only E2E diagnostics.
 */
final class WooCommerce_WooPayments_Native_E2E_Runtime {

	/**
	 * Native runtime enablement filter.
	 *
	 * @var string
	 */
	private const NATIVE_ENABLED_FILTER = 'woocommerce_native_payments_enabled';

	/**
	 * Host-controlled native runtime kill-switch option.
	 *
	 * @var string
	 */
	private const KILL_SWITCH_OPTION = 'woocommerce_native_payments_killswitch';

	/**
	 * Diagnostic route namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'wc-native-payments-e2e/v1';

	/**
	 * Diagnostic route.
	 *
	 * @var string
	 */
	private const REST_ROUTE = '/status';

	/**
	 * Saved-card evidence route.
	 *
	 * @var string
	 */
	private const SAVED_CARD_REST_ROUTE = '/saved-card-evidence';

	/**
	 * Register bootstrap hooks immediately at mu-plugin load.
	 *
	 * @since 11.0.0
	 */
	public function register(): void {
		add_filter( self::NATIVE_ENABLED_FILTER, array( $this, 'handle_native_enabled' ), 0 );
		add_action( 'rest_api_init', array( $this, 'handle_rest_api_init' ) );
	}

	/**
	 * Enable native ownership only for the exact E2E opt-in and an inactive kill switch.
	 *
	 * @internal
	 *
	 * @param bool $enabled Existing runtime enablement.
	 * @return bool
	 */
	public function handle_native_enabled( bool $enabled ): bool {
		if ( ! defined( 'E2E_WOOPAYMENTS_NATIVE' ) || true !== E2E_WOOPAYMENTS_NATIVE ) {
			return $enabled;
		}

		return ! (bool) get_option( self::KILL_SWITCH_OPTION, false );
	}

	/**
	 * Register the authenticated, read-only runtime status route.
	 *
	 * @internal
	 */
	public function handle_rest_api_init(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::SAVED_CARD_REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_saved_card_evidence' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check whether the caller may inspect WooCommerce runtime diagnostics.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get redacted runtime readiness diagnostics.
	 *
	 * @return WP_REST_Response
	 *
	 * @since 11.0.0
	 */
	public function get_status(): WP_REST_Response {
		$status_data = $this->get_native_status_data();

		return rest_ensure_response(
			array(
				'bootstrap_load_phase'    => 'mu-plugin',
				'site_url'                => get_site_url(),
				'wpcom_blog_id'           => $this->get_wpcom_blog_id(),
				'runtime_owner'           => (string) ( $status_data['runtime_owner'] ?? 'none' ),
				'native_enabled'          => (bool) ( $status_data['native_enabled'] ?? false ),
				'kill_switch'             => (bool) get_option( self::KILL_SWITCH_OPTION, false ),
				'account_id'              => (string) ( $status_data['account_id'] ?? '' ),
				'account_connected'       => (bool) ( $status_data['account_connected'] ?? false ),
				'gateway_enabled'         => (bool) ( $status_data['gateway_enabled'] ?? false ),
				'test_mode'               => (bool) ( $status_data['test_mode'] ?? false ),
				'enabled_payment_methods' => $this->get_enabled_payment_methods( $status_data ),
				'last_webhook_fetch'      => (int) ( $status_data['last_webhook_fetch'] ?? 0 ),
				'callback_probe'          => array(
					'registered'    => false,
					'reachable'     => false,
					'wpcom_blog_id' => 0,
				),
			)
		);
	}

	/**
	 * Get strict, read-only saved-card evidence for the configured E2E customer.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_saved_card_evidence( WP_REST_Request $request ) {
		$customer_username = $request->get_param( 'customer_username' );
		if ( ! is_string( $customer_username ) || '' === $customer_username ) {
			return new WP_Error(
				'saved_card_customer_required',
				'An exact customer username is required.',
				array( 'status' => 400 )
			);
		}

		$customer = get_user_by( 'login', $customer_username );
		if ( false === $customer ) {
			return new WP_Error(
				'saved_card_customer_not_found',
				'The exact saved-card customer does not exist.',
				array( 'status' => 404 )
			);
		}

		$tokens         = WC_Payment_Tokens::get_tokens(
			array(
				'user_id'    => $customer->ID,
				'gateway_id' => 'woocommerce_payments',
				'limit'      => 100,
			)
		);
		$token_evidence = array();
		foreach ( $tokens as $token ) {
			if ( ! $token instanceof WC_Payment_Token ) {
				return new WP_Error(
					'saved_card_token_malformed',
					'WooCommerce returned malformed saved-card token evidence.',
					array( 'status' => 500 )
				);
			}
			if ( 'woocommerce_payments' !== $token->get_gateway_id() ) {
				continue;
			}

			$token_evidence[] = array(
				'token_id'          => $token->get_id(),
				'payment_method_id' => $token->get_token(),
				'is_default'        => $token->is_default(),
			);
		}
		usort(
			$token_evidence,
			static fn ( array $first, array $second ): int => $first['token_id'] <=> $second['token_id']
		);

		$response = array(
			'creation_ready' => ! WC_Rate_Limiter::retried_too_soon( 'add_payment_method_' . $customer->ID ),
			'tokens'         => $token_evidence,
		);

		$token_id          = $request->get_param( 'token_id' );
		$payment_method_id = $request->get_param( 'payment_method_id' );
		$has_token_id      = null !== $token_id;
		$has_payment_id    = null !== $payment_method_id;
		if ( $has_token_id !== $has_payment_id ) {
			return new WP_Error(
				'saved_card_identity_incomplete',
				'Both the token ID and payment-method ID are required for named evidence.',
				array( 'status' => 400 )
			);
		}
		if ( ! $has_token_id ) {
			return rest_ensure_response( $response );
		}

		if (
			! is_numeric( $token_id ) ||
			(int) $token_id <= 0 ||
			(string) (int) $token_id !== (string) $token_id ||
			! is_string( $payment_method_id ) ||
			'' === $payment_method_id
		) {
			return new WP_Error(
				'saved_card_identity_invalid',
				'Named saved-card evidence requires exact, well-formed identifiers.',
				array( 'status' => 400 )
			);
		}

		$matched_token = array_values(
			array_filter(
				$token_evidence,
				static fn ( array $candidate ): bool => (int) $token_id === $candidate['token_id']
			)
		);
		if (
			1 !== count( $matched_token ) ||
			$payment_method_id !== $matched_token[0]['payment_method_id']
		) {
			return new WP_Error(
				'saved_card_mapping_mismatch',
				'The exact local token and provider payment-method mapping was not found.',
				array( 'status' => 409 )
			);
		}
		if ( true !== $matched_token[0]['is_default'] ) {
			return new WP_Error(
				'saved_card_not_default',
				'The exact local token is not the default payment method.',
				array( 'status' => 409 )
			);
		}

		$status_data = $this->get_native_status_data();
		if ( 'native' !== ( $status_data['runtime_owner'] ?? null ) ) {
			return new WP_Error(
				'saved_card_native_runtime_required',
				'Provider saved-card evidence is available only from the native runtime owner.',
				array( 'status' => 409 )
			);
		}

		$customer_service_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCustomerService';
		$api_client_class       = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient';
		if (
			! function_exists( 'wc_get_container' ) ||
			! class_exists( $customer_service_class ) ||
			! class_exists( $api_client_class )
		) {
			return new WP_Error(
				'saved_card_provider_unavailable',
				'Native WooPayments provider evidence is unavailable.',
				array( 'status' => 502 )
			);
		}

		try {
			$customer_service     = wc_get_container()->get( $customer_service_class );
			$provider_customer_id = $customer_service->get_persisted_customer_id_by_user_id( $customer->ID );
			if ( ! is_string( $provider_customer_id ) || '' === $provider_customer_id ) {
				return new WP_Error(
					'saved_card_provider_customer_missing',
					'The customer has no exact WooPayments provider customer ID.',
					array( 'status' => 409 )
				);
			}

			$api_client        = wc_get_container()->get( $api_client_class );
			$provider_customer = $api_client->get_customer( $provider_customer_id );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return new WP_Error(
				'saved_card_provider_read_failed',
				'WooPayments provider saved-card evidence could not be read.',
				array( 'status' => 502 )
			);
		}

		$provider_default = $provider_customer['invoice_settings']['default_payment_method'] ?? null;
		if ( ! is_string( $provider_default ) || $provider_default !== $payment_method_id ) {
			return new WP_Error(
				'saved_card_provider_default_mismatch',
				'The exact provider payment method is not the customer default.',
				array( 'status' => 409 )
			);
		}

		$response['provider_customer_id']               = $provider_customer_id;
		$response['provider_default_payment_method_id'] = $provider_default;

		return rest_ensure_response( $response );
	}

	/**
	 * Get native status data without exposing credentials or raw provider payloads.
	 *
	 * @return array<string,mixed>
	 */
	private function get_native_status_data(): array {
		$status_report_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsStatusReport';

		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $status_report_class ) ) {
			return array();
		}

		try {
			$status_report = wc_get_container()->get( $status_report_class );
			$status_data   = $status_report->get_status_data();
		} catch ( Throwable $exception ) {
			unset( $exception );
			return array();
		}

		return is_array( $status_data ) ? $status_data : array();
	}

	/**
	 * Get the Jetpack/WPCOM blog ID.
	 *
	 * @return int
	 */
	private function get_wpcom_blog_id(): int {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return 0;
		}

		return (int) Jetpack_Options::get_option( 'id' );
	}

	/**
	 * Get a scalar-only list of enabled methods.
	 *
	 * @param array<string,mixed> $status_data Native status data.
	 * @return string[]
	 */
	private function get_enabled_payment_methods( array $status_data ): array {
		$methods = $status_data['enabled_payment_methods'] ?? array();
		if ( ! is_array( $methods ) ) {
			return array();
		}

		return array_values(
			array_map(
				'strval',
				array_filter( $methods, 'is_scalar' )
			)
		);
	}
}

( new WooCommerce_WooPayments_Native_E2E_Runtime() )->register();
