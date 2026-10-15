<?php
/**
 * WooPaymentsTerminalLocationsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the terminal location routes the WooCommerce mobile apps call: the store's location, created from the store
 * address when none matches, and the account's locations. The extension serves them from
 * includes/admin/class-wc-rest-payments-terminal-locations-controller.php.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTerminalLocationsRestController extends RestApiControllerBase {

	private const STORE_LOCATIONS_TRANSIENT_KEY = 'wcpay_store_terminal_locations';

	private const LOCATION_CACHE_TTL = DAY_IN_SECONDS;

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
	 * @param WooPaymentsApiClient $api_client WooPayments API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-terminal-locations';
	}

	/**
	 * Register the terminal location routes.
	 */
	public function register_routes(): void {
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
