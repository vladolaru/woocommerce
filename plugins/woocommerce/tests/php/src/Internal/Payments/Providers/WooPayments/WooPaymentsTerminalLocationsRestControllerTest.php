<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalLocationsRestController;
use WC_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the WooPaymentsTerminalLocationsRestController class.
 */
class WooPaymentsTerminalLocationsRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsTerminalLocationsRestController
	 */
	private $sut;

	/**
	 * Recording API client.
	 *
	 * @var RecordingTerminalApiClient
	 */
	private RecordingTerminalApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new RecordingTerminalApiClient();
		$this->sut        = $this->create_controller();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( 'wcpay_store_terminal_locations' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_transient( 'wcpay_store_terminal_locations' );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Terminal location creation does not forward metadata.
	 */
	public function test_create_terminal_location_does_not_forward_metadata(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/terminal/locations' );
		$request->set_param( 'display_name', 'Warehouse' );
		$request->set_param( 'address', array( 'country' => 'US' ) );
		$request->set_param( 'metadata', array( 'ignored' => 'yes' ) );

		$response = $this->sut->create_terminal_location( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), $this->api_client->last_created_location['metadata'] );
	}

	/**
	 * @testdox An empty cached location list is treated as a miss and re-fetched.
	 */
	public function test_get_terminal_locations_refetches_when_cached_list_is_empty(): void {
		set_transient( 'wcpay_store_terminal_locations', array() );
		$this->api_client->terminal_locations_response = array(
			'data' => array(
				array(
					'id'           => 'tml_new',
					'display_name' => 'Warehouse',
					'address'      => array( 'country' => 'US' ),
					'livemode'     => false,
				),
			),
		);

		$response = $this->sut->get_terminal_locations( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'tml_new', $response->get_data()[0]['id'] );
	}

	/**
	 * @testdox Store-location lookup creates a terminal location from the WooCommerce base address when none exists.
	 */
	public function test_get_or_create_store_location_creates_location_from_store_address(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );
		update_option( 'woocommerce_store_address_2', '' );

		$response = $this->sut->get_or_create_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'tml_created', $data['id'] );
		$this->assertSame( $this->get_site_location_name(), $this->api_client->last_created_location['display_name'] );
		$this->assertSame( 'US', $this->api_client->last_created_location['address']['country'] );
		$this->assertSame( 'CA', $this->api_client->last_created_location['address']['state'] );
		$this->assertSame( '123 Main St', $this->api_client->last_created_location['address']['line1'] );
		$this->assertSame( array(), $this->api_client->last_created_location['metadata'], 'The auto-created store location must carry no metadata, like the reference client.' );
	}

	/**
	 * @testdox Store-location address honors the woocommerce_countries_base_* filters.
	 */
	public function test_get_or_create_store_location_address_honors_base_address_filters(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_address_2', 'Floor 2' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );

		add_filter( 'woocommerce_countries_base_address', fn() => '456 Warehouse Rd' );
		add_filter( 'woocommerce_countries_base_address_2', fn() => 'Dock 9' );
		add_filter( 'woocommerce_countries_base_city', fn() => 'Oakland' );
		add_filter( 'woocommerce_countries_base_postcode', fn() => '94607' );

		try {
			$response = $this->sut->get_or_create_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );
		} finally {
			remove_all_filters( 'woocommerce_countries_base_address' );
			remove_all_filters( 'woocommerce_countries_base_address_2' );
			remove_all_filters( 'woocommerce_countries_base_city' );
			remove_all_filters( 'woocommerce_countries_base_postcode' );
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$created_address = $this->api_client->last_created_location['address'];
		$this->assertSame( '456 Warehouse Rd', $created_address['line1'] );
		$this->assertSame( 'Dock 9', $created_address['line2'] );
		$this->assertSame( 'Oakland', $created_address['city'] );
		$this->assertSame( '94607', $created_address['postal_code'] );
	}

	/**
	 * @testdox Store-location lookup reuses hostname-named locations created by the WooPayments extension.
	 */
	public function test_get_or_create_store_location_reuses_matching_hostname_location(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );
		update_option( 'woocommerce_store_address_2', '' );

		$this->api_client->terminal_locations_response = array(
			'data' => array(
				array(
					'id'           => 'tml_existing',
					'display_name' => $this->get_site_location_name(),
					'address'      => array(
						'country'     => 'US',
						'state'       => 'CA',
						'city'        => 'San Francisco',
						'postal_code' => '94107',
						'line1'       => '123 Main St',
					),
					'livemode'     => false,
				),
			),
		);

		$response = $this->sut->get_or_create_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'tml_existing', $response->get_data()['id'] );
		$this->assertSame( array(), $this->api_client->last_created_location );
	}

	/**
	 * @testdox Terminal location lookup falls back to the direct location endpoint on cache misses.
	 */
	public function test_get_terminal_location_falls_back_to_direct_lookup_on_cache_miss(): void {
		$this->api_client->terminal_locations_response = array( 'data' => array() );
		$this->api_client->terminal_location_response  = array(
			'id'           => 'tml_direct',
			'display_name' => 'Direct',
			'address'      => array(
				'country' => 'US',
				'line1'   => '456 Market',
			),
			'livemode'     => false,
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/tml_direct' );
		$request->set_param( 'location_id', 'tml_direct' );

		$response = $this->sut->get_terminal_location( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'tml_direct', $response->get_data()['id'] );
		$this->assertSame( array( 'tml_direct' ), $this->api_client->terminal_location_calls );
	}

	/**
	 * @testdox A Puerto Rico store's location is created in the US with the state PR.
	 *
	 * Client 11.1.0 treats Puerto Rico as a US state (includes/admin/class-wc-rest-payments-terminal-locations-controller.php:125-129).
	 */
	public function test_get_or_create_store_location_creates_a_puerto_rico_location_in_the_us(): void {
		update_option( 'woocommerce_default_country', 'PR' );
		update_option( 'woocommerce_store_address', '1 Calle Fortaleza' );
		update_option( 'woocommerce_store_city', 'San Juan' );
		update_option( 'woocommerce_store_postcode', '00901' );
		update_option( 'woocommerce_store_address_2', '' );

		$this->sut->get_or_create_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );

		$this->assertSame( 'US', $this->api_client->last_created_location['address']['country'] ?? null );
		$this->assertSame( 'PR', $this->api_client->last_created_location['address']['state'] ?? null );
	}

	/**
	 * @testdox The location list read after a location is deleted comes from the platform, not the cache.
	 *
	 * Client 11.1.0 reloads its stored locations from the platform after a delete
	 * (includes/admin/class-wc-rest-payments-terminal-locations-controller.php:200-207, :328-334).
	 */
	public function test_deleting_a_location_refreshes_the_location_list(): void {
		set_transient(
			'wcpay_store_terminal_locations',
			array(
				array(
					'id'           => 'tml_deleted',
					'display_name' => 'Old counter',
					'address'      => array( 'country' => 'US' ),
					'livemode'     => false,
				),
			),
			DAY_IN_SECONDS
		);
		$this->api_client->terminal_locations_response = array();
		$request                                       = new WP_REST_Request( 'DELETE', '/wc/v3/payments/terminal/locations/tml_deleted' );
		$request->set_param( 'location_id', 'tml_deleted' );

		$this->sut->delete_terminal_location( $request );
		$response = $this->sut->get_terminal_locations( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations' ) );

		$this->assertSame( array( 'tml_deleted' ), $this->api_client->deleted_locations );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * Create the controller over the recording API client.
	 *
	 * @return WooPaymentsTerminalLocationsRestController
	 */
	private function create_controller(): WooPaymentsTerminalLocationsRestController {
		$controller = new WooPaymentsTerminalLocationsRestController();
		$controller->init( $this->api_client );

		return $controller;
	}

	/**
	 * The mobile routes answer a failure as the extension's controllers do, so the apps read the same status and code.
	 *
	 * Faked failures are WooPaymentsApiException( message, code, status ), the shape the API client builds from a platform
	 * error body and its HTTP status; status 0 is the API client's encode failure (wcpay_client_unable_to_encode_json),
	 * which carries no HTTP status. A status of 0 reaches the app as HTTP 200, because WordPress's status_header( 0 )
	 * sends no status line; the answer's own status is asserted here.
	 *
	 * @testdox Mobile route failure "$scenario" answers status $expected_status with code $expected_code.
	 * @dataProvider provide_mobile_route_failures
	 *
	 * @param string $scenario        Scenario key.
	 * @param int    $expected_status Expected answer status.
	 * @param string $expected_code   Expected error code.
	 */
	public function test_mobile_route_failures_answer_as_the_extension_does( string $scenario, int $expected_status, string $expected_code ): void {
		$missing         = new WooPaymentsApiException( 'No such object.', 'resource_missing', 404 );
		$refused_request = new WooPaymentsApiException( 'Invalid request.', 'invalid_request_error', 400 );
		$request         = new WP_REST_Request( 'POST', '/wc/v3/payments/test' );

		switch ( $scenario ) {
			case 'store location, incomplete store address':
				update_option( 'woocommerce_store_address', '' );
				update_option( 'woocommerce_store_city', '' );
				update_option( 'woocommerce_store_postcode', '' );
				$response = $this->sut->get_or_create_store_location( $request );
				break;
			case 'store location, platform refuses the address':
				update_option( 'woocommerce_default_country', 'US:CA' );
				update_option( 'woocommerce_store_address', '123 Main St' );
				update_option( 'woocommerce_store_city', 'San Francisco' );
				update_option( 'woocommerce_store_postcode', '94107' );
				$this->api_client->method_exceptions['create_terminal_location'] = $refused_request;
				$response = $this->sut->get_or_create_store_location( $request );
				break;
			case 'terminal locations, platform 404':
				$this->api_client->method_exceptions['get_terminal_locations'] = $missing;
				$response = $this->sut->get_terminal_locations( $request );
				break;
			default:
				$this->fail( "Unknown scenario {$scenario}." );
		}

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( $expected_code, $response->get_error_code() );
		$this->assertSame( $expected_status, rest_convert_error_to_response( $response )->get_status() );
	}

	/**
	 * Mobile route failures and the extension's answers (class-wc-rest-payments-orders-controller.php,
	 * class-wc-rest-payments-reader-controller.php, class-wc-rest-payments-terminal-locations-controller.php,
	 * class-wc-rest-payments-connection-tokens-controller.php).
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function provide_mobile_route_failures(): array {
		$cases = array(
			array( 'store location, incomplete store address', 500, 'store_address_is_incomplete' ),
			array( 'store location, platform refuses the address', 500, 'store_address_is_incomplete' ),
			array( 'terminal locations, platform 404', 500, 'resource_missing' ),
		);

		return array_combine( array_column( $cases, 0 ), $cases );
	}


	/**
	 * Get the current test site's hostname.
	 *
	 * @return string
	 */
	private function get_site_location_name(): string {
		return str_replace( array( 'https://', 'http://' ), '', get_site_url() );
	}
}
