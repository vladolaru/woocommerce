<?php
/**
 * WooPaymentsCapitalRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the Capital active loan summary and loans, and sends the merchant to a fresh loan offer link, from the loan
 * offer route or from a `wcpay-loan-offer` admin link in an offer email or an expired offer.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCapitalRestController extends RestApiControllerBase {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param Client                    $api_client WooPayments API client.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, Client $api_client ): void {
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
	 * Register the Capital routes.
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
		} catch ( ApiException $exception ) {
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
		} catch ( ApiException $exception ) {
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
	 * Send a merchant who opened a `wcpay-loan-offer` admin link to a fresh loan offer link.
	 *
	 * Any `manage_woocommerce` admin is sent on, with no
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

		// The query flag only asks for a fresh Capital link; its value is ignored.
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
	 * Build the Payments Overview page URL.
	 *
	 * @param array<string,string> $query Query args.
	 * @return string
	 */
	private function get_overview_url( array $query = array() ): string {
		return Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH, $query );
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
				// The Tracks event client 11.1.0 records for this redirect (`includes/class-wc-payments-redirect-service.php:96`).
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_capital_view_offer_redirect' );
				return $url;
			}
		} catch ( ApiException $exception ) {
			wc_get_logger()->error(
				'Failed to build Capital loan offer redirect URL.',
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
		}

		return $this->get_loan_offer_error_url();
	}
}
