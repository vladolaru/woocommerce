<?php
/**
 * WooPaymentsEarlyFraudWarningsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the Overview's list of payments whose early fraud warning still needs action.
 *
 * The list has its own route, read when the Overview mounts, so its order query runs only there and a warning resolved
 * since the last read is not listed after a reload (client 11.3.0
 * includes/admin/class-wc-rest-payments-early-fraud-warnings-controller.php).
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsEarlyFraudWarningsRestController extends RestApiControllerBase {

	/**
	 * Account service, which knows the account's mode.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Cache holding one list per mode.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Service that builds the list.
	 *
	 * @var WooPaymentsEarlyFraudWarningOrderService
	 */
	private WooPaymentsEarlyFraudWarningOrderService $order_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService                $account_service Account service.
	 * @param WooPaymentsDatabaseCache                 $database_cache  Cache holding one list per mode.
	 * @param WooPaymentsEarlyFraudWarningOrderService $order_service   Service that builds the list.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsDatabaseCache $database_cache, WooPaymentsEarlyFraudWarningOrderService $order_service ): void {
		$this->account_service = $account_service;
		$this->database_cache  = $database_cache;
		$this->order_service   = $order_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-early-fraud-warnings';
	}

	/**
	 * Register the early fraud warning route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->route_namespace,
			'/payments/early_fraud_warnings/active',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_active_early_fraud_warnings' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);
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
	 * Get the orders of the account's mode whose early fraud warning still needs action, from the mode's cached list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_active_early_fraud_warnings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		// One mode read picks both the key and the rows, so a list is never stored under the other mode's key.
		$is_test_mode = $this->account_service->is_test_mode_enabled();
		$key          = $is_test_mode
			? WooPaymentsDatabaseCache::EARLY_FRAUD_WARNING_ORDERS_KEY_TEST_MODE
			: WooPaymentsDatabaseCache::EARLY_FRAUD_WARNING_ORDERS_KEY;
		$list         = $this->database_cache->get_or_add(
			$key,
			fn(): array => $this->order_service->get_actionable_orders( $is_test_mode ),
			'is_array'
		);

		return new WP_REST_Response( is_array( $list ) ? $list : array() );
	}
}
