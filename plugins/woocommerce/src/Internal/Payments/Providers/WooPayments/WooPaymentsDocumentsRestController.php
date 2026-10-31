<?php
/**
 * WooPaymentsDocumentsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the Documents list, summary and file downloads, and the VAT number check and save, only while the account has
 * Documents enabled.
 *
 * A download sends the platform's file and headers instead of JSON.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsDocumentsRestController extends RestApiControllerBase {

	private const LIST_QUERY_PARAMS = array(
		'page'         => true,
		'pagesize'     => true,
		'sort'         => true,
		'direction'    => true,
		'limit'        => true,
		'match'        => true,
		'date_before'  => true,
		'date_after'   => true,
		'date_between' => true,
		'type_is'      => true,
		'type_is_not'  => true,
	);

	private const SUMMARY_QUERY_PARAMS = array(
		'match'        => true,
		'date_before'  => true,
		'date_after'   => true,
		'date_between' => true,
		'type_is'      => true,
		'type_is_not'  => true,
	);

	/**
	 * Raw document response currently being served through rest_pre_serve_request.
	 *
	 * @var WP_REST_Response|null
	 */
	private ?WP_REST_Response $raw_document_response = null;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

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
	 * @param Client                    $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( Client $api_client, WooPaymentsAccountService $account_service ): void {
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-documents';
	}

	/**
	 * Register the Documents and VAT routes when the account has Documents enabled.
	 *
	 * WooCommerce calls this when REST initializes, where client 11.1.0 checks Documents too (class-wc-payments.php:2407).
	 */
	public function register_routes(): void {
		if ( ! $this->account_service->is_documents_enabled() ) {
			return;
		}

		$this->register_documents_routes();
	}

	/**
	 * Register the Documents and VAT routes.
	 */
	private function register_documents_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/documents', $this->get_readable_route( 'get_documents' ) );
		register_rest_route( $this->route_namespace, '/payments/documents/summary', $this->get_readable_route( 'get_documents_summary' ) );
		register_rest_route( $this->route_namespace, '/payments/documents/(?P<document_id>[\w-]+)', $this->get_readable_route( 'get_document' ) );
		register_rest_route( $this->route_namespace, '/payments/vat/(?P<vat_number>[\w\.\%]+)', $this->get_readable_route( 'validate_vat' ) );
		register_rest_route(
			$this->route_namespace,
			'/payments/vat',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'args'                => array(
					// The client's formats sanitize the company details (class-wc-rest-payments-vat-controller.php:43-58).
					'vat_number' => array(
						'type'     => 'string',
						'format'   => 'text-field',
						'required' => false,
					),
					'name'       => array(
						'type'     => 'string',
						'format'   => 'text-field',
						'required' => true,
					),
					'address'    => array(
						'type'     => 'string',
						'format'   => 'textarea-field',
						'required' => true,
					),
				),
				'callback'            => array( $this, 'save_vat_details' ),
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
	 * Get documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_documents( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_documents( $this->get_allowed_params( $request, self::LIST_QUERY_PARAMS ) ) );
		} catch ( ApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get documents summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_documents_summary( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->get_documents_summary( $this->get_allowed_params( $request, self::SUMMARY_QUERY_PARAMS ) ) );
		} catch ( ApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get a document download response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_document( WP_REST_Request $request ) {
		$document_id = (string) $request->get_param( 'document_id' );
		if ( '' === $document_id || ! preg_match( '/^[\w-]+$/', $document_id ) ) {
			return new WP_Error( 'wcpay_route_validation_failure', __( 'Route param validation failed.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		try {
			$raw_response = $this->api_client->get_document( $document_id );
		} catch ( ApiException $exception ) {
			// The merchant opened this URL in a browser window, so a failure answers an error page with the platform's status, not
			// JSON (client 11.1.0 `includes/admin/class-wc-rest-payments-documents-controller.php:91-98`).
			$message = sprintf(
				/* translators: %1: The document ID. %2: The error message. */
				esc_html__( 'There was an error accessing document %1$s. %2$s', 'woocommerce' ),
				$document_id,
				$exception->getMessage()
			);
			wp_die( esc_html( $message ), '', (int) $exception->get_http_code() );
		}

		$status              = (int) wp_remote_retrieve_response_code( $raw_response );
		$content_type        = $this->get_raw_response_header( $raw_response, 'content-type' );
		$content_disposition = $this->get_raw_response_header( $raw_response, 'content-disposition' );
		$body                = wp_remote_retrieve_body( $raw_response );
		// Invoices are private, so the answer is never cached, whatever the `rest_send_nocache_headers` filter says.
		$headers = array_filter( wp_get_nocache_headers() );

		if ( $status <= 0 ) {
			return new WP_Error(
				'wcpay_api_error',
				__( 'Unable to retrieve document.', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}

		if ( '' !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		}

		if ( '' !== $content_disposition ) {
			$headers['Content-Disposition'] = $content_disposition;
		}

		$this->record_document_download( $document_id );

		// A WP_REST_Response passes through rest_ensure_response() unchanged; a plain WP_HTTP_Response is rebuilt as a new
		// object, which the identity check in serve_raw_document_response() would never match.
		$response                    = new WP_REST_Response(
			$body,
			$status,
			$headers
		);
		$this->raw_document_response = $response;

		if ( false === has_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_document_response' ) ) ) {
			add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_document_response' ), 10, 2 );
		}

		return $response;
	}

	/**
	 * Serve raw document bytes without REST JSON serialization.
	 *
	 * @param mixed $served   Whether the response was already served.
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public function serve_raw_document_response( $served, $response ) {
		if ( ! $response instanceof WP_REST_Response || $response !== $this->raw_document_response ) {
			return $served;
		}

		$this->raw_document_response = null;
		$body                        = $response->get_data();

		if ( is_scalar( $body ) ) {
			echo (string) $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Document bytes are intentionally streamed as the response body.
		}

		return true;
	}

	/**
	 * Validate VAT number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_vat( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->api_client->validate_vat( sanitize_text_field( (string) $request->get_param( 'vat_number' ) ) ) );
		} catch ( ApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Save VAT details.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_vat_details( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->api_client->save_vat_details(
					$this->get_optional_string_param( $request, 'vat_number' ),
					(string) $request->get_param( 'name' ),
					(string) $request->get_param( 'address' )
				)
			);
		} catch ( ApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Build a readable REST route definition.
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
	 * Extract allowed params from the request without normalizing platform-facing names.
	 *
	 * @param WP_REST_Request    $request Request.
	 * @param array<string,bool> $allowed Allowed param map.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private function get_allowed_params( WP_REST_Request $request, array $allowed ): array {
		$query = array_intersect_key( $request->get_params(), $allowed );

		return array_filter(
			$query,
			static function ( $value ): bool {
				return null !== $value;
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
	 * Get a scalar header value from a raw WordPress HTTP response.
	 *
	 * @param array<string,mixed> $raw_response Raw HTTP response.
	 * @param string              $header       Header name.
	 * @return string
	 */
	private function get_raw_response_header( array $raw_response, string $header ): string {
		$value = wp_remote_retrieve_header( $raw_response, $header );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Record the `wcpay_document_downloaded` Tracks event.
	 *
	 * @param string $document_id Document ID.
	 */
	private function record_document_download( string $document_id ): void {
		WooPaymentsTracks::record_wcadmin_event(
			'wcpay_document_downloaded',
			array(
				'document_id' => $document_id,
				'mode'        => $this->account_service->is_test_mode_enabled() ? 'test' : 'live',
			)
		);
	}
}
