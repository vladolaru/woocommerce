<?php
/**
 * WooPaymentsAccountSessionRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments embedded account-session REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAccountSessionRestController implements RegisterHooksInterface {

	private const NAMESPACE = 'wc/v3';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Embedded account session service.
	 *
	 * @var WooPaymentsEmbeddedAccountSessionService
	 */
	private WooPaymentsEmbeddedAccountSessionService $session_service;

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
	 * @param NativePaymentsRuntimeArbiter             $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsEmbeddedAccountSessionService $session_service Embedded account session service.
	 * @param WooPaymentsAccountService                $account_service WooPayments account service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsEmbeddedAccountSessionService $session_service, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->session_service = $session_service;
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
	 * Register WooPayments-compatible account-session routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/payments/accounts', $this->get_readable_route( 'get_account_data' ) );

		// NOTE: Registered as READABLE (GET) deliberately for backward compatibility
		// with the WooPayments client and mobile app, which call this endpoint as GET.
		// Do not change to CREATABLE/POST without a coordinated client + mobile migration.
		// See review finding 3eece18f.
		register_rest_route( self::NAMESPACE, '/payments/accounts/session', $this->get_readable_route( 'create_embedded_account_session' ) );
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
	 * Get WooPayments account data.
	 *
	 * @return WP_REST_Response
	 */
	public function get_account_data(): WP_REST_Response {
		$account = $this->account_service->get_cached_account_data();
		if ( array() === $account ) {
			$default_currency = get_woocommerce_currency();
			$account          = array(
				'card_present_eligible'    => false,
				'country'                  => WC()->countries->get_base_country(),
				'current_deadline'         => null,
				'has_overdue_requirements' => false,
				'has_pending_requirements' => false,
				'statement_descriptor'     => '',
				'status'                   => 'NOACCOUNT',
				'store_currencies'         => array(
					'default'   => $default_currency,
					'supported' => array(
						$default_currency,
					),
				),
				'customer_currencies'      => array(
					'supported' => array(
						$default_currency,
					),
				),
			);
		}

		$account['card_present_eligible'] = false;
		$account['test_mode']             = $this->account_service->is_test_mode_enabled();
		$account['test_mode_onboarding']  = $this->account_service->is_test_mode_onboarding_enabled();

		return rest_ensure_response( $account );
	}

	/**
	 * Create an embedded account session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_embedded_account_session( WP_REST_Request $request ) {
		unset( $request );

		try {
			return new WP_REST_Response( $this->session_service->create_session() );
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Failed to create embedded account session: ' . $exception->getMessage(),
				array( 'source' => 'woopayments-account-session' )
			);

			return new WP_Error(
				'woocommerce_woopayments_account_session_error',
				__( 'Unable to create the WooPayments account session.', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get a readable route definition.
	 *
	 * @param string $callback Callback method.
	 * @return array<string,mixed>
	 */
	private function get_readable_route( string $callback ): array {
		return array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'check_permission' ),
		);
	}
}
