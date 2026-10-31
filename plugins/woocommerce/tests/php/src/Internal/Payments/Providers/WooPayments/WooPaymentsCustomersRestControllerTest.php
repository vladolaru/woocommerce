<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomersRestController;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsCustomersRestController class.
 */
class WooPaymentsCustomersRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCustomersRestController
	 */
	private WooPaymentsCustomersRestController $sut;

	/**
	 * Recording customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * Recording account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->customer_service = $this->create_customer_service();
		$this->account_service  = $this->create_account_service();
		$this->sut              = $this->create_controller();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this->sut, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Customer payment methods route is registered under wc/v3 when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_route_when_builtin_owns_runtime(): void {
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsCustomersRestController::class, $this->sut );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/customers/(?P<customer_id>\w+)/payment_methods', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/customers/(?P<customer_id>\w+)/payment_methods'], WP_REST_Server::READABLE );
	}

	/**
	 * @testdox Customer payment methods route requires manage_woocommerce before calling the service.
	 */
	public function test_route_requires_manage_woocommerce(): void {
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/customers/cus_test/payment_methods' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->customer_service->requests );
	}

	/**
	 * @testdox Customer payment methods route fans out over enabled payment method types and returns the WooPayments extension's item shape.
	 */
	public function test_route_returns_payment_methods_for_enabled_types(): void {
		$this->account_service->gateway_settings         = array(
			'upe_enabled_payment_method_ids' => array( 'card', 'sepa_debit', 'link' ),
		);
		$this->customer_service->payment_methods_by_type = array(
			'card'       => array(
				array(
					'id'              => 'pm_card',
					'type'            => 'card',
					'billing_details' => array(
						'email' => 'card@example.com',
					),
					'card'            => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
						'funding'   => 'credit',
					),
				),
			),
			'sepa_debit' => array(
				array(
					'id'              => 'pm_sepa',
					'type'            => 'sepa_debit',
					'billing_details' => array(
						'email' => 'sepa@example.com',
					),
					'sepa_debit'      => array(
						'last4'     => '3000',
						'bank_code' => '123',
					),
				),
			),
			'link'       => array(
				array(
					'id'              => 'pm_link',
					'type'            => 'link',
					'billing_details' => array(
						'email' => 'link@example.com',
					),
					'link'            => array(
						'email'            => 'link@example.com',
						'persistent_token' => 'secret',
					),
				),
			),
		);
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/customers/cus_test/payment_methods' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'customer_id' => 'cus_test',
					'type'        => 'card',
				),
				array(
					'customer_id' => 'cus_test',
					'type'        => 'sepa_debit',
				),
				array(
					'customer_id' => 'cus_test',
					'type'        => 'link',
				),
			),
			$this->customer_service->requests
		);
		$this->assertSame(
			array(
				array(
					'id'              => 'pm_card',
					'type'            => 'card',
					'billing_details' => array(
						'email' => 'card@example.com',
					),
					'card'            => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
				array(
					'id'              => 'pm_sepa',
					'type'            => 'sepa_debit',
					'billing_details' => array(
						'email' => 'sepa@example.com',
					),
					'sepa_debit'      => array(
						'last4' => '3000',
					),
				),
				array(
					'id'              => 'pm_link',
					'type'            => 'link',
					'billing_details' => array(
						'email' => 'link@example.com',
					),
					'link'            => array(
						'email' => 'link@example.com',
					),
				),
			),
			$response->get_data()
		);
	}

	/**
	 * @testdox Customer payment methods route uses card as the default payment method type.
	 */
	public function test_route_uses_card_type_when_gateway_setting_is_missing(): void {
		$this->customer_service->payment_methods_by_type = array(
			'card' => array(
				array(
					'id'              => 'pm_card',
					'type'            => 'card',
					'billing_details' => array(),
					'card'            => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			),
		);
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/customers/cus_test/payment_methods' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'customer_id' => 'cus_test',
					'type'        => 'card',
				),
			),
			$this->customer_service->requests
		);
		$this->assertSame( 'pm_card', $response->get_data()[0]['id'] );
	}

	/**
	 * A platform error answers 403 with the success/data body, whatever the platform's status, as the extension's
	 * wp_send_json_error( $message, 403 ) does (class-wc-rest-payments-customer-controller.php:78-83).
	 *
	 * The faked failures are ApiException( message, code, status ), the shape the API client builds from a
	 * platform error body and its HTTP status.
	 *
	 * @testdox Customer payment methods route answers a platform $platform_status error with 403 and the stripped message.
	 * @testWith [404, "resource_missing"]
	 *           [403, "wcpay_forbidden"]
	 *
	 * @param int    $platform_status Platform HTTP status.
	 * @param string $platform_code   Platform error code.
	 */
	public function test_route_answers_platform_errors_with_403( int $platform_status, string $platform_code ): void {
		$this->account_service->gateway_settings = array(
			'upe_enabled_payment_method_ids' => array( 'card' ),
		);
		$this->customer_service->exception       = new ApiException( 'Forbidden <b>secret</b>.', $platform_code, $platform_status );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/customers/cus_test/payment_methods' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame(
			array(
				'success' => false,
				'data'    => 'Forbidden secret.',
			),
			$response->get_data()
		);
	}

	/**
	 * Create a native customers REST controller.
	 *
	 * @return WooPaymentsCustomersRestController
	 */
	private function create_controller(): WooPaymentsCustomersRestController {
		$controller = new WooPaymentsCustomersRestController();
		$controller->init( $this->customer_service, $this->account_service );

		return $controller;
	}

	/**
	 * Create a recording account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		return new class() extends WooPaymentsAccountService {
			/**
			 * Gateway settings.
			 *
			 * @var array<string,mixed>
			 */
			public array $gateway_settings = array();

			/**
			 * Get a gateway setting.
			 *
			 * @param string $key      Setting key.
			 * @param mixed  $fallback Fallback value.
			 * @return mixed
			 */
			public function get_gateway_setting( string $key, $fallback = null ) {
				return array_key_exists( $key, $this->gateway_settings ) ? $this->gateway_settings[ $key ] : $fallback;
			}
		};
	}

	/**
	 * Create a recording customer service.
	 *
	 * @return WooPaymentsCustomerService
	 */
	private function create_customer_service(): WooPaymentsCustomerService {
		return new class() extends WooPaymentsCustomerService {
			/**
			 * Payment methods keyed by type.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $payment_methods_by_type = array();

			/**
			 * Recorded requests.
			 *
			 * @var array<int,array{customer_id:string,type:string}>
			 */
			public array $requests = array();

			/**
			 * Optional exception thrown by the next call.
			 *
			 * @var ApiException|null
			 */
			public ?ApiException $exception = null;

			/**
			 * Retrieve payment methods for a customer.
			 *
			 * @param string $customer_id Customer ID.
			 * @param string $type        Payment method type.
			 * @return array<int,array<string,mixed>>
			 * @throws ApiException When configured.
			 */
			public function get_payment_methods_for_customer( string $customer_id, string $type = 'card' ): array {
				$this->requests[] = array(
					'customer_id' => $customer_id,
					'type'        => $type,
				);

				if ( null !== $this->exception ) {
					throw $this->exception;
				}

				return $this->payment_methods_by_type[ $type ] ?? array();
			}
		};
	}

	/**
	 * Assert a route handler supports a method.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 * @param string                         $method         Method constant.
	 */
	private function assertRouteHasMethod( array $route_handlers, string $method ): void {
		foreach ( $route_handlers as $handler ) {
			if ( isset( $handler['methods'][ $method ] ) && true === $handler['methods'][ $method ] ) {
				return;
			}
		}

		$this->fail( 'Route does not accept method ' . $method . '.' );
	}
}
