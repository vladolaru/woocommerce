<?php
/**
 * WooPaymentsMobileRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use Throwable;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments mobile and In-Person Payments REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsMobileRestController extends RestApiControllerBase {

	private const STORE_READERS_TRANSIENT_KEY = 'wcpay_store_terminal_readers';

	private const STORE_LOCATIONS_TRANSIENT_KEY = 'wcpay_store_terminal_locations';

	private const READER_CACHE_TTL = 2 * HOUR_IN_SECONDS;

	private const LOCATION_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

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
	 * @param WooPaymentsApiClient      $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-mobile';
	}

	/**
	 * Register WooPayments-compatible mobile and IPP routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->route_namespace,
			'/payments/readers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_readers' ),
					'permission_callback' => array( $this, 'has_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_reader' ),
					'permission_callback' => array( $this, 'has_permission' ),
					'args'                => array(
						'location'          => array(
							'type'     => 'string',
							'required' => true,
						),
						'registration_code' => array(
							'type'     => 'string',
							'required' => true,
						),
						'label'             => array(
							'type' => 'string',
						),
						'metadata'          => array(
							'type' => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/readers/charges/(?P<transaction_id>\w+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_reader_charge_summary' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/readers/receipts/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_print_receipt' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/readers/receipts/(?P<payment_intent_id>\w+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'generate_print_receipt' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/terminal/locations/store',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_store_location' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/terminal/locations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_terminal_locations' ),
					'permission_callback' => array( $this, 'has_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_terminal_location' ),
					'permission_callback' => array( $this, 'has_permission' ),
					'args'                => array(
						'display_name' => array(
							'type'     => 'string',
							'required' => true,
						),
						'address'      => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/terminal/locations/(?P<location_id>\w+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_terminal_location' ),
					'permission_callback' => array( $this, 'has_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_terminal_location' ),
					'permission_callback' => array( $this, 'has_permission' ),
					'args'                => array(
						'display_name' => array(
							'type'     => 'string',
							'required' => false,
						),
						'address'      => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_terminal_location' ),
					'permission_callback' => array( $this, 'has_permission' ),
				),
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
	 * Get terminal readers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_readers( WP_REST_Request $request ) {
		unset( $request );

		$cached = get_transient( self::STORE_READERS_TRANSIENT_KEY );
		// An empty list is a cache miss, matching the plugin's falsy check: a reader
		// registered elsewhere must not stay invisible for the full TTL.
		if ( is_array( $cached ) && array() !== $cached ) {
			return new WP_REST_Response( $cached );
		}

		try {
			$readers = array_map(
				array( $this, 'extract_reader' ),
				WooPaymentsTerminalListResponses::items( $this->api_client->get_terminal_readers() )
			);
			$summary = WooPaymentsTerminalListResponses::items( $this->api_client->get_readers_charge_summary( gmdate( 'Y-m-d' ) ) );
			$readers = $this->annotate_active_readers( $readers, $summary );

			set_transient( self::STORE_READERS_TRANSIENT_KEY, $readers, self::READER_CACHE_TTL );

			return new WP_REST_Response( $readers );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Register a terminal reader.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_reader( WP_REST_Request $request ) {
		$location          = $this->get_request_string( $request, 'location' );
		$registration_code = $this->get_request_string( $request, 'registration_code' );

		$label        = $this->get_request_string( $request, 'label' );
		$raw_metadata = $request->get_param( 'metadata' );
		$metadata     = is_array( $raw_metadata ) ? $raw_metadata : null;

		try {
			$reader = $this->api_client->register_terminal_reader(
				$location,
				$registration_code,
				'' === $label ? null : $label,
				$metadata
			);
			delete_transient( self::STORE_READERS_TRANSIENT_KEY );
			// Pairing a reader can change account-derived state (capabilities, IPP
			// flags); the plugin eagerly refreshes the account cache here.
			$this->account_service->refresh_account_data();

			return new WP_REST_Response( $this->extract_reader( $reader ) );
		} catch ( WooPaymentsApiException $exception ) {
			// The client answers the platform's status, 0 included (class-wc-rest-payments-reader-controller.php:206-213).
			return new WP_Error( $exception->get_error_code(), $exception->getMessage(), array( 'status' => $exception->get_http_code() ) );
		}
	}

	/**
	 * Get reader charge summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_reader_charge_summary( WP_REST_Request $request ) {
		try {
			$transaction_id = $this->get_request_string( $request, 'transaction_id' );
			$transaction    = $this->api_client->get_transaction( $transaction_id );
			if ( empty( $transaction ) ) {
				return new WP_REST_Response( array() );
			}

			$created     = isset( $transaction['created'] ) ? (int) $transaction['created'] : 0;
			$charge_date = 0 < $created ? gmdate( 'Y-m-d', $created ) : gmdate( 'Y-m-d' );

			return new WP_REST_Response(
				$this->api_client->get_readers_charge_summary(
					$charge_date,
					$transaction_id
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			// The client's own code, with no status (class-wc-rest-payments-reader-controller.php:165-167).
			return new WP_Error( 'wcpay_get_summary', $exception->getMessage() );
		}
	}

	/**
	 * Generate a preview receipt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function preview_print_receipt( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();

		return new WP_REST_Response(
			array(
				'html_content' => $this->render_receipt_html( $this->get_preview_receipt_data( $params ) ),
			)
		);
	}

	/**
	 * Generate a receipt for a payment intent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 * @throws \RuntimeException When receipt data cannot be generated before the error response is built.
	 */
	public function generate_print_receipt( WP_REST_Request $request ) {
		$intent_id = $this->get_request_string( $request, 'payment_intent_id' );

		try {
			$intent = $this->api_client->get_payment_intention( $intent_id );
			if ( 'succeeded' !== (string) ( $intent['status'] ?? '' ) ) {
				throw new \RuntimeException( __( 'Invalid payment intent', 'woocommerce' ) );
			}

			$charge = WooPaymentsIntentCodec::latest_charge( $intent );
			if ( isset( $charge['id'] ) ) {
				$charge = array_merge( $charge, $this->api_client->get_charge( (string) $charge['id'] ) );
			}

			$order = $this->get_receipt_order( $intent, $charge );
			if ( ! $order instanceof WC_Order ) {
				throw new \RuntimeException( __( 'Order not found', 'woocommerce' ) );
			}

			$receipt_data                  = $this->get_receipt_data( $intent, $charge, $order );
			$receipt_data['branding_logo'] = $this->get_receipt_branding_logo();

			return new WP_REST_Response(
				array(
					'html_content' => $this->render_receipt_html( $receipt_data ),
				)
			);
		} catch ( Throwable $exception ) {
			// The client answers a platform failure's status, 0 included (class-wc-rest-payments-reader-controller.php:316-318).
			$status = $exception instanceof WooPaymentsApiException ? $exception->get_http_code() : 500;

			return new WP_Error( 'generate_print_receipt_error', $exception->getMessage(), array( 'status' => $status ) );
		}
	}

	/**
	 * Get the platform terminal location for the store address, creating one on the platform when none matches.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_store_location( WP_REST_Request $request ) {
		unset( $request );

		$address = $this->get_store_terminal_address();
		if ( empty( $address['country'] ) || empty( $address['city'] ) || empty( $address['postal_code'] ) || empty( $address['line1'] ) ) {
			// No status, as the apps read only the code (class-wc-rest-payments-terminal-locations-controller.php:135-149).
			return new WP_Error( 'store_address_is_incomplete', admin_url( 'admin.php?page=wc-settings&tab=general' ) );
		}

		try {
			$locations     = $this->get_cached_terminal_locations();
			$display_names = $this->get_store_location_display_names();
			foreach ( $locations as $location ) {
				if ( $this->location_matches_store( $location, $display_names, $address ) ) {
					return new WP_REST_Response( $this->extract_location( $location ) );
				}
			}

			$location = $this->api_client->create_terminal_location(
				$display_names[0],
				$address
			);
			delete_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );

			return new WP_REST_Response( $this->extract_location( $location ) );
		} catch ( WooPaymentsApiException $exception ) {
			if ( 'invalid_request_error' === $exception->get_error_code() ) {
				return new WP_Error( 'store_address_is_incomplete', admin_url( 'admin.php?page=wc-settings&tab=general' ) );
			}

			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Get terminal locations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_terminal_locations( WP_REST_Request $request ) {
		unset( $request );

		try {
			return new WP_REST_Response( array_map( array( $this, 'extract_location' ), $this->get_cached_terminal_locations() ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Get one terminal location by ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_terminal_location( WP_REST_Request $request ) {
		$location_id = $this->get_request_string( $request, 'location_id' );

		try {
			foreach ( $this->get_cached_terminal_locations() as $location ) {
				if ( (string) ( $location['id'] ?? '' ) === $location_id ) {
					return new WP_REST_Response( $this->extract_location( $location ) );
				}
			}

			$location = $this->api_client->get_terminal_location( $location_id );
			delete_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );

			return new WP_REST_Response( $this->extract_location( $location ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Create a terminal location.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_terminal_location( WP_REST_Request $request ) {
		$address      = $request->get_param( 'address' );
		$display_name = $this->get_request_string( $request, 'display_name' );

		try {
			// No metadata is forwarded on location create, matching the plugin.
			$location = $this->api_client->create_terminal_location(
				$display_name,
				is_array( $address ) ? $address : array()
			);
			delete_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );

			return new WP_REST_Response( $this->extract_location( $location ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Update a terminal location.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_terminal_location( WP_REST_Request $request ) {
		$address      = $request->get_param( 'address' );
		$display_name = $request->get_param( 'display_name' );

		try {
			$location = $this->api_client->update_terminal_location(
				$this->get_request_string( $request, 'location_id' ),
				is_string( $display_name ) ? $display_name : null,
				is_array( $address ) ? $address : null
			);
			delete_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );

			return new WP_REST_Response( $this->extract_location( $location ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Delete a terminal location.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_terminal_location( WP_REST_Request $request ) {
		try {
			$result = $this->api_client->delete_terminal_location( $this->get_request_string( $request, 'location_id' ) );
			delete_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );

			return new WP_REST_Response( $result );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Get cached terminal locations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_cached_terminal_locations(): array {
		$cached = get_transient( self::STORE_LOCATIONS_TRANSIENT_KEY );
		// An empty list is a cache miss, matching the plugin's falsy check.
		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$locations = array_map( array( $this, 'extract_location' ), WooPaymentsTerminalListResponses::items( $this->api_client->get_terminal_locations() ) );
		set_transient( self::STORE_LOCATIONS_TRANSIENT_KEY, $locations, self::LOCATION_CACHE_TTL );

		return $locations;
	}

	/**
	 * Extract a reader response.
	 *
	 * @param array<string,mixed> $reader Reader.
	 * @return array<string,mixed>
	 */
	private function extract_reader( array $reader ): array {
		return array_intersect_key(
			$reader,
			array_flip(
				array(
					'id',
					'livemode',
					'device_type',
					'label',
					'location',
					'metadata',
					'status',
					'is_active',
				)
			)
		);
	}

	/**
	 * Extract a location response.
	 *
	 * @param array<string,mixed> $location Location.
	 * @return array<string,mixed>
	 */
	private function extract_location( array $location ): array {
		return array_intersect_key(
			$location,
			array_flip(
				array(
					'id',
					'address',
					'display_name',
					'livemode',
				)
			)
		);
	}

	/**
	 * Add active-reader flags from charge summary data.
	 *
	 * @param array<int,array<string,mixed>> $readers Reader list.
	 * @param array<int,array<string,mixed>> $summary Charge summary.
	 * @return array<int,array<string,mixed>>
	 */
	private function annotate_active_readers( array $readers, array $summary ): array {
		$active_reader_ids = array();
		foreach ( $summary as $item ) {
			if ( isset( $item['reader_id'] ) && 'active' === (string) ( $item['status'] ?? '' ) ) {
				$active_reader_ids[] = (string) $item['reader_id'];
			}
		}

		foreach ( $readers as &$reader ) {
			$reader['is_active'] = isset( $reader['id'] ) && in_array( (string) $reader['id'], $active_reader_ids, true );
		}

		return $readers;
	}

	/**
	 * Build a terminal address from WooCommerce store settings.
	 *
	 * @return array<string,string>
	 */
	private function get_store_terminal_address(): array {
		$country = WC()->countries->get_base_country();
		$state   = WC()->countries->get_base_state();

		if ( 'PR' === $country ) {
			$country = 'US';
			$state   = 'PR';
		}

		return array_filter(
			array(
				'country'     => $country,
				'state'       => $state,
				'city'        => WC()->countries->get_base_city(),
				'postal_code' => WC()->countries->get_base_postcode(),
				'line1'       => WC()->countries->get_base_address(),
				'line2'       => WC()->countries->get_base_address_2(),
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Get store location display names that may have been used by prior clients.
	 *
	 * @return string[]
	 */
	private function get_store_location_display_names(): array {
		$site_name = str_replace( array( 'https://', 'http://' ), '', get_site_url() );
		$host      = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$host      = is_string( $host ) ? trim( $host ) : '';
		$blog_name = trim( get_bloginfo( 'name' ) );
		$names     = array_values( array_unique( array_filter( array( $site_name, $blog_name, $host ) ) ) );

		return empty( $names ) ? array( 'Store' ) : $names;
	}

	/**
	 * Tell whether a location matches store data.
	 *
	 * @param array<string,mixed>  $location     Location.
	 * @param string[]             $display_names Store display names.
	 * @param array<string,string> $address      Store address.
	 * @return bool
	 */
	private function location_matches_store( array $location, array $display_names, array $address ): bool {
		if ( ! in_array( (string) ( $location['display_name'] ?? '' ), $display_names, true ) ) {
			return false;
		}

		$location_address = isset( $location['address'] ) && is_array( $location['address'] ) ? $location['address'] : array();
		foreach ( $address as $key => $value ) {
			if ( (string) ( $location_address[ $key ] ?? '' ) !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get receipt data from request settings for preview rendering.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>
	 */
	private function get_preview_receipt_data( array $params ): array {
		$settings = $this->get_receipt_settings_from_params( $params );

		return array_merge(
			$settings,
			array(
				'order_id'            => '42',
				'currency'            => 'USD',
				'subtotal'            => 0,
				'total'               => 0,
				'total_fees'          => 0,
				'shipping_tax'        => 0,
				'line_items'          => array(
					array(
						'name'     => 'Sample',
						'quantity' => 1,
						'subtotal' => 0,
						'product'  => array(
							'id'            => 'sample',
							'price'         => 0,
							'regular_price' => 1,
						),
					),
					array(
						'name'     => 'Sample',
						'quantity' => 1,
						'subtotal' => 0,
						'product'  => array(
							'id'            => 'sample',
							'price'         => 0,
							'regular_price' => 1,
						),
					),
				),
				'coupon_lines'        => array(
					array(
						'code'        => 'DISCOUNT',
						'description' => 'sample',
						'discount'    => 0,
					),
				),
				'tax_lines'           => array(
					array(
						'rate_percent'       => 0,
						'tax_total'          => 0,
						'shipping_tax_total' => 0,
					),
				),
				'amount_captured'     => 0,
				'brand'               => 'Sample',
				'last4'               => '0000',
				'payment_method_name' => 'Sample',
				'receipt'             => array(
					'application_preferred_name' => 'Sample, Receipts preview',
					'dedicated_file_name'        => '0000',
					'account_type'               => 'Sample',
				),
			)
		);
	}

	/**
	 * Get the preview receipt's merchant details: each from the request when it is given, else from the connected account.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>
	 */
	private function get_receipt_settings_from_params( array $params ): array {
		$support_address = ! empty( $params['accountBusinessSupportAddress'] ) && is_array( $params['accountBusinessSupportAddress'] )
			? $params['accountBusinessSupportAddress']
			: $this->account_service->get_business_support_address();

		return array(
			'business_name'   => $this->get_receipt_string( $params, 'accountBusinessName', $this->account_service->get_business_name() ),
			'support_address' => $this->get_receipt_support_address( $support_address ),
			'support_phone'   => $this->get_receipt_string( $params, 'accountBusinessSupportPhone', $this->account_service->get_business_support_phone() ),
			'support_email'   => $this->get_receipt_string( $params, 'accountBusinessSupportEmail', $this->account_service->get_business_support_email() ),
		);
	}

	/**
	 * Get the printed receipt's merchant details from the connected account; an account without a support address prints none.
	 *
	 * @return array<string,mixed>
	 */
	private function get_receipt_merchant_settings(): array {
		$support_address = $this->account_service->get_business_support_address();

		return array(
			'business_name'   => $this->account_service->get_business_name(),
			'support_address' => array() === $support_address ? array() : $this->get_receipt_support_address( $support_address ),
			'support_phone'   => $this->account_service->get_business_support_phone(),
			'support_email'   => $this->account_service->get_business_support_email(),
		);
	}

	/**
	 * Get a non-empty receipt string from the request params, else the fallback.
	 *
	 * @param array<string,mixed> $params   Request params.
	 * @param string              $key      Param key.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private function get_receipt_string( array $params, string $key, string $fallback ): string {
		return ! empty( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? (string) $params[ $key ] : $fallback;
	}

	/**
	 * Get the six address lines the receipt template prints, '' for a line the address lacks.
	 *
	 * @param array<string,mixed> $address Support address.
	 * @return array<string,string>
	 */
	private function get_receipt_support_address( array $address ): array {
		$receipt_address = array();
		foreach ( array( 'line1', 'line2', 'city', 'state', 'postal_code', 'country' ) as $key ) {
			$receipt_address[ $key ] = isset( $address[ $key ] ) && is_scalar( $address[ $key ] ) ? (string) $address[ $key ] : '';
		}

		return $receipt_address;
	}

	/**
	 * Get the receipt's WooCommerce order from intent or charge metadata.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @param array<string,mixed> $charge Charge.
	 * @return WC_Order|null
	 */
	private function get_receipt_order( array $intent, array $charge ): ?WC_Order {
		$order_id = isset( $charge['order']['number'] ) ? (int) $charge['order']['number'] : 0;
		if ( 0 >= $order_id ) {
			$charge_metadata = isset( $charge['metadata'] ) && is_array( $charge['metadata'] ) ? $charge['metadata'] : array();
			$intent_metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
			$order_id        = isset( $charge_metadata['order_id'] ) ? (int) $charge_metadata['order_id'] : (int) ( $intent_metadata['order_id'] ?? 0 );
		}

		$order = 0 < $order_id ? wc_get_order( $order_id ) : false;

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Get receipt data from intent, charge, and order.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @param array<string,mixed> $charge Charge.
	 * @param WC_Order            $order  Order.
	 * @return array<string,mixed>
	 */
	private function get_receipt_data( array $intent, array $charge, WC_Order $order ): array {
		$payment_method_details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] ) ? $charge['payment_method_details'] : array();
		$card_present           = isset( $payment_method_details['card_present'] ) && is_array( $payment_method_details['card_present'] ) ? $payment_method_details['card_present'] : array();
		$receipt                = isset( $card_present['receipt'] ) && is_array( $card_present['receipt'] ) ? $card_present['receipt'] : array();

		return array_merge(
			$this->get_receipt_merchant_settings(),
			array(
				'order_id'            => $order->get_id(),
				'currency'            => $order->get_currency(),
				'subtotal'            => (float) $order->get_subtotal(),
				'total'               => (float) $order->get_total(),
				'total_fees'          => (float) $order->get_total_fees(),
				'shipping_tax'        => 0 < count( $order->get_shipping_methods() ) ? (float) $order->get_shipping_total() : 0,
				'line_items'          => $this->get_receipt_line_items( $order ),
				'coupon_lines'        => $this->get_receipt_coupon_lines( $order ),
				'tax_lines'           => $this->get_receipt_tax_lines( $order ),
				'amount_captured'     => isset( $charge['amount_captured'] ) ? WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $charge['amount_captured'], (string) ( $charge['currency'] ?? $intent['currency'] ?? $order->get_currency() ) ) : WooPaymentsCurrencyUtils::amount_from_minor_units( (int) ( $intent['amount'] ?? 0 ), (string) ( $intent['currency'] ?? $order->get_currency() ) ),
				'brand'               => isset( $card_present['brand'] ) ? (string) $card_present['brand'] : '',
				'last4'               => isset( $card_present['last4'] ) ? (string) $card_present['last4'] : '',
				'payment_method_name' => WooPaymentsTerminalCardFormatter::get_terminal_card_display_name( $card_present ),
				'receipt'             => array(
					'application_preferred_name' => (string) ( $receipt['application_preferred_name'] ?? '' ),
					'dedicated_file_name'        => (string) ( $receipt['dedicated_file_name'] ?? '' ),
					'account_type'               => (string) ( $receipt['account_type'] ?? '' ),
				),
			)
		);
	}

	/**
	 * Get receipt line items.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_receipt_line_items( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product       = $item->get_product();
			$quantity      = max( 1, (int) $item->get_quantity() );
			$line_subtotal = (float) $item->get_subtotal();
			$price         = $product instanceof \WC_Product && '' !== $product->get_price() ? (float) $product->get_price() : ( $quantity > 0 ? $line_subtotal / $quantity : $line_subtotal );
			$regular_price = $product instanceof \WC_Product && '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : $price;

			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $quantity,
				'subtotal' => $line_subtotal,
				'product'  => array(
					'id'            => $product instanceof \WC_Product ? $product->get_id() : $item->get_product_id(),
					'price'         => $price,
					'regular_price' => $regular_price,
				),
			);
		}

		return $items;
	}

	/**
	 * Get receipt coupon lines.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_receipt_coupon_lines( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon ) {
			if ( ! $coupon instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$items[] = array(
				'code'        => $coupon->get_code(),
				'description' => '',
				'discount'    => (float) $coupon->get_discount(),
			);
		}

		return $items;
	}

	/**
	 * Get receipt tax lines.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_receipt_tax_lines( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! $tax instanceof \WC_Order_Item_Tax ) {
				continue;
			}

			$items[] = array(
				'rate_percent'       => (float) $tax->get_rate_percent(),
				'tax_total'          => (float) $tax->get_tax_total(),
				'shipping_tax_total' => (float) $tax->get_shipping_tax_total(),
			);
		}

		return $items;
	}

	/**
	 * Render the print receipt through the theme-overridable WooCommerce template.
	 *
	 * The template and its variable contract match the WooPayments plugin's
	 * html-in-person-payment-receipt.php, so theme overrides written against
	 * the plugin (including legally required receipt text) keep applying.
	 *
	 * @param array<string,mixed> $data Receipt data.
	 * @return string
	 */
	private function render_receipt_html( array $data ): string {
		$receipt         = isset( $data['receipt'] ) && is_array( $data['receipt'] ) ? $data['receipt'] : array();
		$support_address = isset( $data['support_address'] ) && is_array( $data['support_address'] ) ? $data['support_address'] : array();

		return wc_get_template_html(
			'html-in-person-payment-receipt.php',
			array(
				'amount_captured'             => (float) ( $data['amount_captured'] ?? 0 ),
				'branding_logo'               => isset( $data['branding_logo'] ) && is_array( $data['branding_logo'] ) ? $data['branding_logo'] : array(),
				'business_name'               => (string) ( $data['business_name'] ?? '' ),
				'coupon_lines'                => $this->get_receipt_list( $data, 'coupon_lines' ),
				'line_items'                  => $this->get_receipt_list( $data, 'line_items' ),
				'order'                       => array(
					'id'           => (string) ( $data['order_id'] ?? '' ),
					'currency'     => (string) ( $data['currency'] ?? get_woocommerce_currency() ),
					'subtotal'     => (float) ( $data['subtotal'] ?? 0 ),
					'total'        => (float) ( $data['total'] ?? 0 ),
					'total_fees'   => (float) ( $data['total_fees'] ?? 0 ),
					'shipping_tax' => (float) ( $data['shipping_tax'] ?? 0 ),
				),
				'payment_method_details'      => array( 'last4' => (string) ( $data['last4'] ?? '' ) ),
				'payment_method_display_name' => (string) ( $data['payment_method_name'] ?? strtoupper( (string) ( $data['brand'] ?? '' ) ) ),
				'receipt'                     => array(
					'application_preferred_name' => (string) ( $receipt['application_preferred_name'] ?? '' ),
					'dedicated_file_name'        => (string) ( $receipt['dedicated_file_name'] ?? '' ),
					'account_type'               => (string) ( $receipt['account_type'] ?? '' ),
				),
				'support_address'             => $support_address,
				'support_email'               => (string) ( $data['support_email'] ?? '' ),
				'support_phone'               => (string) ( $data['support_phone'] ?? '' ),
				'tax_lines'                   => $this->get_receipt_list( $data, 'tax_lines' ),
			)
		);
	}

	/**
	 * Get the account branding logo contents for the printed receipt.
	 *
	 * Fetched platform-side (as_account false) like the reference client; a
	 * fetch failure propagates to the caller's error envelope.
	 *
	 * @return array<string,mixed> File contents payload, or an empty array when no logo is configured.
	 */
	private function get_receipt_branding_logo(): array {
		$branding_logo = $this->account_service->get_branding_logo();
		if ( '' === $branding_logo ) {
			return array();
		}

		return $this->api_client->get_file_contents( $branding_logo, false );
	}

	/**
	 * Get a normalized receipt list from data.
	 *
	 * @param array<string,mixed> $data Receipt data.
	 * @param string              $key  List key.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_receipt_list( array $data, string $key ): array {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}

		return WooPaymentsTerminalListResponses::array_items( $data[ $key ] );
	}

	/**
	 * Get a string parameter from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @param string          $key     Parameter key.
	 * @return string
	 */
	private function get_request_string( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
