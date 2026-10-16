<?php
/**
 * WooPaymentsAccountSessionRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the WooPayments account data and the embedded account session on `payments/accounts` and
 * `payments/accounts/session` to the WooPayments admin pages and the mobile apps.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAccountSessionRestController extends RestApiControllerBase {

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
	 * @param WooPaymentsEmbeddedAccountSessionService $session_service Embedded account session service.
	 * @param WooPaymentsAccountService                $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsEmbeddedAccountSessionService $session_service, WooPaymentsAccountService $account_service ): void {
		$this->session_service = $session_service;
		$this->account_service = $account_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-account-session';
	}

	/**
	 * Register the account and account session routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/accounts', $this->get_readable_route( 'get_account_data' ) );

		// GET although it creates a session: the WooPayments Overview page and the mobile apps call it with GET, so the
		// method changes only after both do.
		register_rest_route( $this->route_namespace, '/payments/accounts/session', $this->get_readable_route( 'create_embedded_account_session' ) );
	}

	/**
	 * Check route permissions.
	 *
	 * @return bool
	 */
	public function has_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get the cached account data, or a no-account answer built from the store's country and currency, with the test-mode
	 * flags added.
	 *
	 * This route always answers `card_present_eligible` false (client 11.1.0
	 * `includes/admin/class-wc-rest-payments-accounts-controller.php:60,83`); the Card Readers menu reads the account's own flag.
	 *
	 * @return WP_REST_Response
	 */
	public function get_account_data(): WP_REST_Response {
		$account = $this->account_service->get_cached_account_data();
		if ( array() === $account ) {
			$default_currency = get_woocommerce_currency();
			$status           = $this->account_service->is_onboarding_disabled() ? 'ONBOARDING_DISABLED' : 'NOACCOUNT';
			$account          = array(
				'card_present_eligible'    => false,
				'country'                  => WC()->countries->get_base_country(),
				'current_deadline'         => null,
				'has_overdue_requirements' => false,
				'has_pending_requirements' => false,
				'statement_descriptor'     => '',
				'status'                   => $status,
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
			// Platform failures are answered inside the session service; anything else it throws lands here and goes through
			// log_throwable(), which writes its class and code, never its message.
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Failed to create embedded account session.', $exception );

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
			'permission_callback' => array( $this, 'has_permission' ),
		);
	}
}
