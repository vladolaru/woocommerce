<?php
/**
 * WooPaymentsTransactionsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsFraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransactionsListRequest;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the transactions list, summary, search, export and one transaction, and the fraud outcome lists with their
 * summary, search and export.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsTransactionsRestController extends RestApiControllerBase {

	private const FRAUD_OUTCOME_MAX_PAGE_SIZE = 100;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Order details service.
	 *
	 * @var WooPaymentsOrderDetailsService
	 */
	private WooPaymentsOrderDetailsService $order_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient           $api_client    WooPayments API client.
	 * @param WooPaymentsOrderDetailsService $order_service Order details service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsOrderDetailsService $order_service ): void {
		$this->api_client    = $api_client;
		$this->order_service = $order_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-transactions';
	}

	/**
	 * Register the transaction routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/transactions', $this->get_readable_route( 'get_transactions' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/download', $this->get_creatable_route( 'get_transactions_export' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/download/(?P<export_id>[^/\\\\%]+)', $this->get_readable_route( 'get_export_url' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/summary', $this->get_readable_route( 'get_transactions_summary' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/search', $this->get_readable_route( 'get_transactions_search_autocomplete' ) );

		register_rest_route( $this->route_namespace, '/payments/transactions/fraud-outcomes', $this->get_readable_route( 'get_fraud_outcome_transactions' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/fraud-outcomes/summary', $this->get_readable_route( 'get_fraud_outcome_transactions_summary' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/fraud-outcomes/search', $this->get_readable_route( 'get_fraud_outcome_transactions_search_autocomplete' ) );
		register_rest_route( $this->route_namespace, '/payments/transactions/fraud-outcomes/download', $this->get_readable_route( 'get_fraud_outcome_transactions_export' ) );
		register_rest_route( $this->route_namespace, '/payments/fraud_outcomes/(?P<id>\w+)/latest', $this->get_readable_route( 'get_latest_fraud_outcome' ) );

		register_rest_route( $this->route_namespace, '/payments/transactions/(?P<transaction_id>\w+)', $this->get_readable_route( 'get_transaction' ) );
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
	 * Get transactions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_transactions_list_response(
					$this->api_client->get_transactions( $this->get_filtered_transactions_list_params( $request ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get transactions summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions_summary( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->api_client->get_transactions_summary(
					$this->get_transactions_filters( $request ),
					$this->get_optional_string_param( $request, 'deposit_id' )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get transaction detail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transaction( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_transaction_detail_response(
					$this->api_client->get_transaction( (string) $request->get_param( 'transaction_id' ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Search transactions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions_search_autocomplete( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_transactions_search_autocomplete( (string) $request->get_param( 'search_term' ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get fraud outcome transactions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fraud_outcome_transactions( WP_REST_Request $request ) {
		try {
			$params       = $this->get_fraud_outcome_params( $request, 'wcpay_list_fraud_outcome_transactions_request' );
			$transactions = $this->get_formatted_fraud_outcome_transactions( $params );

			return new WP_REST_Response(
				array(
					'data' => WooPaymentsFraudOutcomeTransactionsListRequest::paginate_transactions( $transactions, $params ),
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get fraud outcome transactions summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fraud_outcome_transactions_summary( WP_REST_Request $request ) {
		try {
			$transactions = $this->get_formatted_fraud_outcome_transactions(
				$this->get_fraud_outcome_params( $request, 'wcpay_list_fraud_outcome_transactions_summary_request' )
			);

			return new WP_REST_Response( WooPaymentsFraudOutcomeTransactionsListRequest::summarize_transactions( $transactions ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get fraud outcome transactions search autocomplete results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fraud_outcome_transactions_search_autocomplete( WP_REST_Request $request ) {
		try {
			$params       = $this->get_fraud_outcome_params( $request, 'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' );
			$transactions = $this->get_formatted_fraud_outcome_transactions( $params );
			$search_term  = isset( $params['search_term'] ) && is_scalar( $params['search_term'] ) ? (string) $params['search_term'] : '';

			return new WP_REST_Response( WooPaymentsFraudOutcomeTransactionsListRequest::get_search_autocomplete( $transactions, $search_term ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get fraud outcome transactions export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fraud_outcome_transactions_export( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				array(
					'data' => $this->get_formatted_fraud_outcome_transactions(
						$this->get_fraud_outcome_params( $request, 'wcpay_get_fraud_outcome_transactions_export_request' )
					),
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get the latest fraud outcome for a payment intent ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_latest_fraud_outcome( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_latest_fraud_outcome( (string) $request->get_param( 'id' ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Initiate transactions export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions_export( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->api_client->start_transactions_export(
					$this->get_transactions_filters( $request ),
					(string) $request->get_param( 'user_email' ),
					$this->get_optional_string_param( $request, 'deposit_id' ),
					$this->get_optional_string_param( $request, 'locale' )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get export URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_export_url( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_transactions_export_url( (string) $request->get_param( 'export_id' ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
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
			'permission_callback' => array( $this, 'has_permission' ),
		);
	}

	/**
	 * Get the transactions list params after the `wcpay_list_transactions_request` filter ran, with order-number searches
	 * mapped to charge IDs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When a filter callback returns no request.
	 */
	private function get_filtered_transactions_list_params( WP_REST_Request $request ): array {
		$transactions_request = WooPaymentsTransactionsListRequest::from_rest_request( $request );

		$filtered_request = $transactions_request->get_filtered_request();

		return $this->order_service->map_transaction_search_params( $filtered_request->get_params() );
	}

	/**
	 * Get the summary and export filters, with order-number search terms mapped to charge IDs, as client 11.1.0's API
	 * client maps them for both (`includes/wc-payment-api/class-wc-payments-api-client.php:300,447`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private function get_transactions_filters( WP_REST_Request $request ): array {
		return $this->order_service->map_transaction_search_params( WooPaymentsTransactionsListRequest::filters_from_rest_request( $request ) );
	}

	/**
	 * Get formatted fraud outcome transactions.
	 *
	 * Every platform row is enriched, like client 11.1.0 `List_Fraud_Outcome_Transactions::format_response()`.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_formatted_fraud_outcome_transactions( array $params ): array {
		return WooPaymentsFraudOutcomeTransactionsListRequest::format_transactions(
			$this->api_client->get_fraud_outcomes( $params ),
			$params
		);
	}

	/**
	 * Get fraud outcome request params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $hook    The request filter to fire.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When a filter callback returns no request.
	 */
	private function get_fraud_outcome_params( WP_REST_Request $request, string $hook ): array {
		$fraud_outcome_request = WooPaymentsFraudOutcomeTransactionsListRequest::from_rest_request( $request );
		// A callback may send() the request it receives, which needs the hook it was filtered under.
		$fraud_outcome_request->assign_hook( $hook );

		$filtered_request = $fraud_outcome_request->get_filtered_request();

		$params = $filtered_request->get_params();

		$params = array_filter(
			$params,
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		$params['page']      = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$params['pagesize']  = isset( $params['pagesize'] ) ? max( 1, min( self::FRAUD_OUTCOME_MAX_PAGE_SIZE, (int) $params['pagesize'] ) ) : 25;
		$params['sort']      = isset( $params['sort'] ) ? (string) $params['sort'] : 'date';
		$params['direction'] = isset( $params['direction'] ) ? (string) $params['direction'] : 'desc';

		if ( isset( $params['search'] ) && ! is_array( $params['search'] ) ) {
			$params['search'] = array( $params['search'] );
		}

		return $params;
	}

	/**
	 * Get an optional string param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Param name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return string|null
	 */
	private function get_optional_string_param( WP_REST_Request $request, string $name ): ?string {
		$value = $request->get_param( $name );

		return null === $value ? null : (string) $value;
	}
}
