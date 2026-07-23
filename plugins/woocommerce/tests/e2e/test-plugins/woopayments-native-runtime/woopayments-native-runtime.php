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
