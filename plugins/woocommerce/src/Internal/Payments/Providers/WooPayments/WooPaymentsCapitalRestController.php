<?php
/**
 * WooPaymentsCapitalRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments Capital REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCapitalRestController extends RestApiControllerBase {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param WooPaymentsApiClient      $api_client Native WooPayments API client.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client ): void {
		$this->arbiter    = $arbiter;
		$this->api_client = $api_client;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-capital';
	}

	/**
	 * Register WooPayments-compatible Capital routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/capital/active_loan_summary', $this->get_readable_route( 'get_active_loan_summary' ) );
		register_rest_route( $this->route_namespace, '/payments/capital/loans', $this->get_readable_route( 'get_loans' ) );
		register_rest_route( $this->route_namespace, '/payments/capital/loan_offer', $this->get_readable_route( 'get_loan_offer' ) );
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
	 * Get active loan summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_active_loan_summary( WP_REST_Request $request ) {
		unset( $request );

		try {
			return new WP_REST_Response( $this->api_client->get_capital_active_loan_summary() );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get Capital loans.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_loans( WP_REST_Request $request ) {
		unset( $request );

		try {
			return new WP_REST_Response( $this->api_client->get_capital_loans() );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Create and redirect to a Capital loan offer link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_loan_offer( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->redirect_response( $this->get_loan_offer_redirect_url() );
	}

	/**
	 * Redirect legacy Capital offer links to a fresh provider loan offer link.
	 *
	 * Like the plugin's `maybe_redirect_by_get_param()` (11.1.0), any `manage_woocommerce` admin is sent on, with no
	 * Capital eligibility check: a merchant's first offer email comes before any loan, and the platform refuses an
	 * ineligible offer, which lands on the overview error notice.
	 *
	 * @return void
	 */
	public function redirect_loan_offer_request(): void {
		if (
			wp_doing_ajax()
			|| ! current_user_can( 'manage_woocommerce' )
			|| ! $this->arbiter->is_builtin_owner()
		) {
			return;
		}

		// This mirrors the legacy automatic email/expired-link redirect. The GET flag only indicates that we should create a fresh Capital link.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcpay-loan-offer'] ) ) {
			return;
		}

		wp_safe_redirect( $this->get_loan_offer_redirect_url() );
		exit;
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

	/**
	 * Build the native WooPayments overview URL.
	 *
	 * @param array<string,string> $query Query args.
	 * @return string
	 */
	private function get_overview_url( array $query = array() ): string {
		return Utils::wc_payments_settings_url( WooPaymentsOnboardingAdapter::OVERVIEW_PATH, $query );
	}

	/**
	 * Build the refresh URL used by expired loan offer links.
	 *
	 * @return string
	 */
	private function get_loan_offer_refresh_url(): string {
		return add_query_arg(
			array(
				'wcpay-loan-offer' => '',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the loan offer failure URL.
	 *
	 * @return string
	 */
	private function get_loan_offer_error_url(): string {
		return $this->get_overview_url(
			array(
				'wcpay-loan-offer-error' => '1',
			)
		);
	}

	/**
	 * Build a testable REST redirect response.
	 *
	 * @param string $url Redirect URL.
	 * @return WP_REST_Response
	 */
	private function redirect_response( string $url ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );

		return $response;
	}

	/**
	 * Build the redirect URL for the Capital loan offer flow.
	 *
	 * @return string
	 */
	private function get_loan_offer_redirect_url(): string {
		try {
			$capital_link = $this->api_client->create_capital_link(
				$this->get_overview_url(),
				$this->get_loan_offer_refresh_url()
			);
			$url          = isset( $capital_link['url'] ) ? (string) $capital_link['url'] : '';

			if ( '' !== $url ) {
				// Plugin 11.1.0 `WC_Payments_Redirect_Service::redirect_to_capital_view_offer_page()`.
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_capital_view_offer_redirect' );
				return $url;
			}
		} catch ( WooPaymentsApiException $exception ) {
			wc_get_logger()->error(
				'Failed to build Capital loan offer redirect URL.',
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'woopayments-capital' ) )
			);
		}

		return $this->get_loan_offer_error_url();
	}
}
