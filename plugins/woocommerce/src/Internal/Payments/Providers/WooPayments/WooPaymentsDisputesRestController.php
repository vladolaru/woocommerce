<?php
/**
 * WooPaymentsDisputesRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsDisputesListRequest;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the disputes list, summary and export, one dispute with its order, and the evidence update and close; an
 * update or a close clears the dispute caches.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsDisputesRestController extends RestApiControllerBase {

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
	 * Database cache that holds the dispute counts and the active disputes.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient           $api_client            WooPayments API client.
	 * @param WooPaymentsOrderDetailsService $order_service         Order details service.
	 * @param WooPaymentsDatabaseCache       $database_cache        Database cache.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsOrderDetailsService $order_service, WooPaymentsDatabaseCache $database_cache ): void {
		$this->api_client     = $api_client;
		$this->order_service  = $order_service;
		$this->database_cache = $database_cache;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-disputes';
	}

	/**
	 * Register the dispute routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/disputes', $this->get_readable_route( 'get_disputes' ) );
		register_rest_route( $this->route_namespace, '/payments/disputes/download/(?P<export_id>[^/\\\\%]+)', $this->get_readable_route( 'get_export_url' ) );
		register_rest_route( $this->route_namespace, '/payments/disputes/summary', $this->get_readable_route( 'get_disputes_summary' ) );
		register_rest_route( $this->route_namespace, '/payments/disputes/download', $this->get_creatable_route( 'get_disputes_export' ) );
		register_rest_route(
			$this->route_namespace,
			'/payments/disputes/(?P<dispute_id>\w+)',
			array(
				$this->get_readable_route( 'get_dispute' ),
				$this->get_creatable_route( 'update_dispute' ),
			)
		);
		register_rest_route( $this->route_namespace, '/payments/disputes/(?P<dispute_id>\w+)/close', $this->get_creatable_route( 'close_dispute' ) );
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
	 * Get disputes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_disputes( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_disputes_list_response(
					$this->api_client->get_disputes( $this->get_filtered_disputes_list_params( $request ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get disputes summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_disputes_summary( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_disputes_summary( $this->get_disputes_filters( $request ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get dispute detail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_dispute( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_dispute_response(
					$this->api_client->get_dispute( (string) $request->get_param( 'dispute_id' ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Update dispute evidence.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_dispute( WP_REST_Request $request ) {
		$dispute_id  = (string) $request->get_param( 'dispute_id' );
		$submit      = wc_string_to_bool( $request->get_param( 'submit' ) );
		$log_context = $this->get_dispute_action_log_context( 'update', $dispute_id, $submit );

		$this->log_dispute_action( 'info', __( 'WooPayments dispute update requested.', 'woocommerce' ), $log_context );

		try {
			$evidence = $request->get_param( 'evidence' );
			$metadata = $request->get_param( 'metadata' );

			$response = $this->api_client->update_dispute(
				$dispute_id,
				is_array( $evidence ) ? $evidence : array(),
				$submit,
				is_array( $metadata ) ? $metadata : array()
			);
			// The client invalidates the dispute caches after the update, as after a close (class-wc-payments-api-client.php:722-724).
			$this->database_cache->delete_dispute_caches();

			$this->log_dispute_action( 'info', __( 'WooPayments dispute update completed.', 'woocommerce' ), $log_context );

			return new WP_REST_Response( $this->enrich_dispute_response_after_completed_action( $response, $log_context ) );
		} catch ( WooPaymentsApiException $exception ) {
			$this->log_dispute_action(
				'error',
				__( 'WooPayments dispute update failed.', 'woocommerce' ),
				array_merge(
					$log_context,
					array(
						'api_code'    => WooPaymentsLogger::get_loggable_error_code( $exception->get_error_code() ),
						'http_status' => $exception->get_http_code(),
					)
				)
			);

			return $exception->to_wp_error();
		}
	}

	/**
	 * Close dispute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function close_dispute( WP_REST_Request $request ) {
		$dispute_id  = (string) $request->get_param( 'dispute_id' );
		$log_context = $this->get_dispute_action_log_context( 'close', $dispute_id );

		$this->log_dispute_action( 'info', __( 'WooPayments dispute close requested.', 'woocommerce' ), $log_context );

		try {
			$response = $this->api_client->close_dispute( $dispute_id );
			$this->database_cache->delete_dispute_caches();

			$this->log_dispute_action( 'info', __( 'WooPayments dispute close completed.', 'woocommerce' ), $log_context );

			return new WP_REST_Response( $this->enrich_dispute_response_after_completed_action( $response, $log_context ) );
		} catch ( WooPaymentsApiException $exception ) {
			$this->log_dispute_action(
				'error',
				__( 'WooPayments dispute close failed.', 'woocommerce' ),
				array_merge(
					$log_context,
					array(
						'api_code'    => WooPaymentsLogger::get_loggable_error_code( $exception->get_error_code() ),
						'http_status' => $exception->get_http_code(),
					)
				)
			);

			return $exception->to_wp_error();
		}
	}

	/**
	 * Initiate disputes export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_disputes_export( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->api_client->start_disputes_export(
					$this->get_disputes_filters( $request ),
					(string) $request->get_param( 'user_email' ),
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
			return new WP_REST_Response( $this->api_client->get_disputes_export_url( (string) $request->get_param( 'export_id' ) ) );
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
	 * Get the disputes list params after the `wcpay_list_disputes_request` filter ran.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When a filter callback returns no request.
	 */
	private function get_filtered_disputes_list_params( WP_REST_Request $request ): array {
		$disputes_request = WooPaymentsDisputesListRequest::from_rest_request( $request );

		return $disputes_request->get_filtered_request()->get_params();
	}

	/**
	 * Get the summary and export filters: the list's filters, with no paging and no list request filter.
	 *
	 * Client 11.1.0 class-wc-rest-payments-disputes-controller.php:188-205.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private function get_disputes_filters( WP_REST_Request $request ): array {
		return array_filter(
			array(
				'match'           => $request->get_param( 'match' ),
				'currency_is'     => $request->get_param( 'store_currency_is' ),
				'created_before'  => $request->get_param( 'date_before' ),
				'created_after'   => $request->get_param( 'date_after' ),
				'created_between' => $request->get_param( 'date_between' ),
				'search'          => $request->get_param( 'search' ),
				'status_is'       => $request->get_param( 'status_is' ),
				'status_is_not'   => $request->get_param( 'status_is_not' ),
			),
			static function ( $filter ): bool {
				return null !== $filter;
			}
		);
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

	/**
	 * Get a safe dispute action log context.
	 *
	 * @param string    $action     Action name.
	 * @param string    $dispute_id Dispute ID.
	 * @param bool|null $submit     Whether evidence was submitted.
	 * @return array<string,mixed>
	 */
	private function get_dispute_action_log_context( string $action, string $dispute_id, ?bool $submit = null ): array {
		$context = array(
			'source'     => WooPaymentsLogger::SOURCE,
			'action'     => $action,
			'dispute_id' => $dispute_id,
			'user_id'    => get_current_user_id(),
		);

		if ( null !== $submit ) {
			$context['submit'] = $submit;
		}

		return $context;
	}

	/**
	 * Log a dispute action event.
	 *
	 * @param string              $level   Log level.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Log context.
	 */
	private function log_dispute_action( string $level, string $message, array $context ): void {
		wc_get_logger()->log( $level, $message, $context );
	}

	/**
	 * Add local order context after a platform mutation has already completed.
	 *
	 * @param array<string,mixed> $response    Platform response.
	 * @param array<string,mixed> $log_context Safe dispute action log context.
	 * @return array<string,mixed>
	 */
	private function enrich_dispute_response_after_completed_action( array $response, array $log_context ): array {
		try {
			return $this->order_service->enrich_dispute_response( $response );
		} catch ( Throwable $exception ) {
			$this->log_dispute_action(
				'warning',
				__( 'WooPayments dispute action completed, but local order context enrichment failed.', 'woocommerce' ),
				array_merge(
					$log_context,
					array(
						'exception' => get_class( $exception ),
					)
				)
			);

			if ( ! array_key_exists( 'order', $response ) ) {
				$response['order'] = null;
			}

			return $response;
		}
	}
}
