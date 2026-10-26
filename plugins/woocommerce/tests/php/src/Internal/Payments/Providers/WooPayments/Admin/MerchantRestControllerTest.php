<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\MerchantRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use WP_Error;
use WP_Http;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * MerchantRestController API controller test.
 *
 * @class MerchantRestController
 */
class MerchantRestControllerTest extends WC_Unit_Test_Case {
	/**
	 * Endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = '/wc-admin/settings/payments/woopayments';

	/**
	 * @var MerchantRestController
	 */
	protected MerchantRestController $sut;

	/**
	 * @var MockObject|WooPaymentsSettingsService
	 */
	private $mock_settings_service;

	/**
	 * @var MockObject|WooPaymentsPmPromotionsService
	 */
	private $mock_pm_promotions_service;

	/**
	 * @var MockObject|OverviewService
	 */
	private $mock_overview_service;

	/**
	 * @var MockObject|WooPaymentsRuntimeArbiter
	 */
	private $mock_runtime_arbiter;

	/**
	 * @var MockObject|WooPaymentsAccountService
	 */
	private $mock_account_service;

	/**
	 * @var MockObject|Client
	 */
	private $mock_api_client;

	/**
	 * @var MockObject|NoticeService
	 */
	private $mock_notice_service;

	/**
	 * The ID of the store admin user.
	 *
	 * @var int
	 */
	protected $store_admin_id;

	/**
	 * REST server used by the controller tests.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Account country returned by the mocked account service.
	 *
	 * @var string
	 */
	private string $account_country = 'US';

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->store_admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->store_admin_id );

		$this->mock_settings_service      = $this->getMockBuilder( WooPaymentsSettingsService::class )->getMock();
		$this->mock_pm_promotions_service = $this->getMockBuilder( WooPaymentsPmPromotionsService::class )
			->onlyMethods( array( 'get_visible_promotions', 'activate_promotion', 'dismiss_promotion' ) )
			->getMock();
		$this->mock_overview_service      = $this->getMockBuilder( OverviewService::class )
			->onlyMethods( array( 'get_overview', 'get_account_summary' ) )
			->getMock();
		$this->mock_runtime_arbiter       = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$this->mock_runtime_arbiter
			->method( 'is_builtin_owner' )
			->willReturn( true );
		$this->mock_account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country' ) )
			->getMock();
		$this->mock_account_service
			->method( 'get_account_country' )
			->willReturnCallback( fn() => $this->account_country );

		$this->mock_api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'upload_file', 'get_file', 'get_file_contents' ) )
			->getMock();

		$this->mock_notice_service = $this->createMock( NoticeService::class );

		$this->sut = new MerchantRestController();
		$this->sut->init( $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $this->mock_notice_service, new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$this->server = $this->create_rest_server_with_routes(
			array(
				function () {
					$this->sut->register_routes( true );
				},
			),
			true
		);
	}

	/**
	 * Tear down the REST server.
	 */
	public function tearDown(): void {
		$this->clear_rest_server();
		parent::tearDown();
	}

	/**
	 * @testdox Should return the WooPayments account summary for users with permission.
	 */
	public function test_get_account_summary_by_manager() {
		$summary = array(
			'account' => array(
				'connected'  => true,
				'test_drive' => true,
				'sandbox'    => false,
				'live'       => false,
			),
			'urls'    => array(
				'setup' => 'https://example.com/setup',
			),
		);

		$this->mock_overview_service
			->expects( $this->once() )
			->method( 'get_account_summary' )
			->willReturn( $summary );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/account' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $summary, $response->get_data() );
	}

	/**
	 * @testdox Should let a manager record a settings notice action through the route.
	 */
	public function test_record_admin_notice_action_by_manager(): void {
		$notice_service = $this->createMock( NoticeService::class );
		$notice_service->expects( $this->once() )
			->method( 'record_action' )
			->with( 'test_to_live', 'shown', null )
			->willReturn( true );
		$this->sut->init( $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $notice_service, new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$this->sut->register_routes( true );

		$request  = new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/test_to_live/shown' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	/**
	 * @testdox The container-resolved merchant controller should expose the settings notice and Apple Pay notice shown routes.
	 */
	public function test_container_resolved_merchant_controller_registers_the_notice_routes(): void {
		$controller = wc_get_container()->get( MerchantRestController::class );
		$controller->register_routes( true );

		$notice_response   = $this->server->dispatch( new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/test_to_live/shown' ) );
		$apple_pay_request = new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/apple_pay_domain_error/shown' );
		$apple_pay_request->set_param( 'error_id', hash( 'sha256', 'Test error message' ) );
		$apple_pay_response = $this->server->dispatch( $apple_pay_request );

		$this->assertSame( 200, $notice_response->get_status() );
		$this->assertSame( array( 'success' => true ), $notice_response->get_data() );
		$this->assertSame( 200, $apple_pay_response->get_status() );
	}

	/**
	 * @testdox Should clear the Apple Pay domain error the React settings notice showed, when it is still stored.
	 */
	public function test_apple_pay_domain_error_shown_clears_the_matching_error(): void {
		update_option( 'wcpay_apple_pay_domain_error', 'Test error message' );

		try {
			$response     = $this->dispatch_apple_pay_domain_error_shown( hash( 'sha256', 'Test error message' ) );
			$stored_error = get_option( 'wcpay_apple_pay_domain_error' );
		} finally {
			delete_option( 'wcpay_apple_pay_domain_error' );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'success' => true,
				'cleared' => true,
			),
			$response->get_data()
		);
		$this->assertFalse( $stored_error );
	}

	/**
	 * @testdox Should keep an Apple Pay domain error that was replaced between the page load and the shown report.
	 */
	public function test_apple_pay_domain_error_shown_keeps_a_replaced_error(): void {
		update_option( 'wcpay_apple_pay_domain_error', 'Newer error from a retry' );

		try {
			$response     = $this->dispatch_apple_pay_domain_error_shown( hash( 'sha256', 'Test error message' ) );
			$stored_error = get_option( 'wcpay_apple_pay_domain_error' );
		} finally {
			delete_option( 'wcpay_apple_pay_domain_error' );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'success' => true,
				'cleared' => false,
			),
			$response->get_data()
		);
		$this->assertSame( 'Newer error from a retry', $stored_error, 'The newer error stays for the next display.' );
	}

	/**
	 * @testdox Should reject an Apple Pay shown report without a well-formed error identifier.
	 */
	public function test_apple_pay_domain_error_shown_requires_an_error_id(): void {
		update_option( 'wcpay_apple_pay_domain_error', 'Test error message' );

		try {
			$missing      = $this->dispatch_apple_pay_domain_error_shown( null );
			$malformed    = $this->dispatch_apple_pay_domain_error_shown( 'not-a-hash' );
			$stored_error = get_option( 'wcpay_apple_pay_domain_error' );
		} finally {
			delete_option( 'wcpay_apple_pay_domain_error' );
		}

		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 400, $malformed->get_status() );
		$this->assertSame( 'Test error message', $stored_error );
	}

	/**
	 * @testdox Should keep the Apple Pay domain error when a $_dataName reports it shown.
	 * @dataProvider provider_apple_pay_domain_error_shown_refusals
	 *
	 * @param string|null $role            Role of the reporting user, or null for a logged-out visitor.
	 * @param int         $expected_status Expected response status.
	 */
	public function test_apple_pay_domain_error_shown_refuses_users_without_manager_permission( ?string $role, int $expected_status ): void {
		update_option( 'wcpay_apple_pay_domain_error', 'Test error message' );
		wp_set_current_user( null === $role ? 0 : $this->factory->user->create( array( 'role' => $role ) ) );

		try {
			$response     = $this->dispatch_apple_pay_domain_error_shown( hash( 'sha256', 'Test error message' ) );
			$stored_error = get_option( 'wcpay_apple_pay_domain_error' );
		} finally {
			delete_option( 'wcpay_apple_pay_domain_error' );
		}

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( 'Test error message', $stored_error );
	}

	/**
	 * Users refused by the Apple Pay notice shown route.
	 *
	 * @return array<string,array{?string,int}>
	 */
	public function provider_apple_pay_domain_error_shown_refusals(): array {
		return array(
			'logged-out visitor'                       => array( null, 401 ),
			'user without store management permission' => array( 'subscriber', 403 ),
		);
	}

	/**
	 * Report the Apple Pay domain error as shown through a controller with the real Apple Pay service.
	 *
	 * @param string|null $error_id Error identifier to send, or null to omit it.
	 * @return \WP_REST_Response
	 */
	private function dispatch_apple_pay_domain_error_shown( ?string $error_id ): \WP_REST_Response {
		$this->sut->init( $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $this->createMock( NoticeService::class ), new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$this->sut->register_routes( true );

		$request = new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/apple_pay_domain_error/shown' );
		if ( null !== $error_id ) {
			$request->set_param( 'error_id', $error_id );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * @testdox Should reject unknown notice paths and non-integer stages before the notice service runs.
	 */
	public function test_record_admin_notice_action_rejects_invalid_request(): void {
		$notice_service = $this->createMock( NoticeService::class );
		$notice_service->expects( $this->never() )->method( 'record_action' );
		$this->sut->init( $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $notice_service, new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$this->sut->register_routes( true );

		$this->assertSame( 404, $this->server->dispatch( new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/unknown/shown' ) )->get_status() );
		$request = new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/post_kyc_activation/shown' );
		$request->set_param( 'stage', '7' );
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * @testdox Should preserve one marker when a notice action is repeated through REST.
	 * @dataProvider notice_action_markers
	 *
	 * @param string $action   Notice action.
	 * @param string $meta_key Preserved user-meta key.
	 */
	public function test_record_admin_notice_action_replays_are_idempotent( string $action, string $meta_key ): void {
		$now            = 1000;
		$notice_service = new NoticeService(
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$notice_service->init( $this->mock_account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		$this->sut->init( $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $notice_service, new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$this->sut->register_routes( true );

		$request = new WP_REST_Request( 'POST', self::ENDPOINT . '/admin-notices/test_to_live/' . $action );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$first_marker = get_user_meta( $this->store_admin_id, $meta_key, true );
		$now          = 2000;
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( $first_marker, get_user_meta( $this->store_admin_id, $meta_key, true ) );
		$this->assertCount( 1, get_user_meta( $this->store_admin_id, $meta_key, false ) );
	}

	/**
	 * Preserved user-meta markers for replayed test-to-live actions.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function notice_action_markers(): array {
		return array(
			'shown'   => array( 'shown', 'wcpay_test_to_live_notice_shown' ),
			'dismiss' => array( 'dismiss', 'wcpay_test_to_live_notice_dismissed' ),
			'snooze'  => array( 'snooze', 'wcpay_test_to_live_notice_snoozed' ),
		);
	}

	/**
	 * @testdox Should refuse the $_dataName read route to a user without payment gateway management permission.
	 * @dataProvider provider_read_route_refusals
	 *
	 * @param string      $route          Route path.
	 * @param string|null $role           Role to log in with, or null to keep the administrator with manage_woocommerce filtered off.
	 * @param string      $service        Property holding the service mock the route reads.
	 * @param string      $service_method Service method that must not run.
	 */
	public function test_read_routes_refuse_users_without_manager_permission( string $route, ?string $role, string $service, string $service_method ): void {
		if ( null === $role ) {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$filter_callback = fn( $caps ) => array(
				'manage_woocommerce' => false,
				'install_plugins'    => true,
			);
			add_filter( 'user_has_cap', $filter_callback );
		} else {
			wp_set_current_user( $this->factory->user->create( array( 'role' => $role ) ) );
		}

		$this->{$service}
			->expects( $this->never() )
			->method( $service_method );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
	}

	/**
	 * Read routes and the users they refuse.
	 *
	 * The settings row: client 11.1.0 class-wc-payments-rest-controller.php:63-64 requires `manage_woocommerce`, which the
	 * stock Editor role lacks. The error code differs from the client's generic `rest_forbidden` and is not asserted.
	 *
	 * @return array<string,array{string,?string,string,string}>
	 */
	public function provider_read_route_refusals(): array {
		return array(
			'account'                   => array( self::ENDPOINT . '/account', null, 'mock_overview_service', 'get_account_summary' ),
			'overview'                  => array( self::ENDPOINT . '/overview', null, 'mock_overview_service', 'get_overview' ),
			'payment method promotions' => array( '/wc/v3/payments/pm-promotions', 'subscriber', 'mock_pm_promotions_service', 'get_visible_promotions' ),
			'settings'                  => array( '/wc/v3/payments/settings', 'editor', 'mock_settings_service', 'get_settings' ),
		);
	}

	/**
	 * @testdox Should answer the $_dataName read route with an error when the service throws.
	 * @dataProvider provider_read_route_service_errors
	 *
	 * @param string $route          Route path.
	 * @param string $service_method Overview service method that throws.
	 * @param string $code           Expected error code.
	 * @param string $message        Exception message the error carries.
	 */
	public function test_read_routes_answer_service_errors( string $route, string $service_method, string $code, string $message ): void {
		$this->mock_overview_service
			->expects( $this->once() )
			->method( $service_method )
			->willThrowException( new \Exception( $message ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertSame( $message, $response->get_data()['message'] );
	}

	/**
	 * Read routes whose service throws.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function provider_read_route_service_errors(): array {
		return array(
			'account'  => array( self::ENDPOINT . '/account', 'get_account_summary', 'woocommerce_rest_woopayments_account_error', 'Account summary unavailable.' ),
			'overview' => array( self::ENDPOINT . '/overview', 'get_overview', 'woocommerce_rest_woopayments_overview_error', 'Overview unavailable.' ),
		);
	}

	/**
	 * @testdox Should return the WooPayments Overview fields for users with permission.
	 */
	public function test_get_overview_by_manager(): void {
		$overview = array(
			'account'                               => array(
				'id'        => 'acct_native_test',
				'connected' => true,
			),
			'account_status'                        => array(
				'status' => 'restricted_soon',
			),
			'overview_tasks_visibility'             => array(
				'dismissed_todo_tasks'       => array( 'old-task' ),
				'deleted_todo_tasks'         => array(),
				'remind_me_later_todo_tasks' => array(),
			),
			'is_connection_success_modal_dismissed' => false,
			'urls'                                  => array(
				'overview_page' => 'https://example.com/overview',
			),
		);

		$this->mock_overview_service
			->expects( $this->once() )
			->method( 'get_overview' )
			->willReturn( $overview );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/overview' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $overview, $response->get_data() );
	}

	/**
	 * @testdox Should expose the WooPayments settings GET route.
	 */
	public function test_get_settings_contract_by_manager(): void {
		$settings = array(
			'is_wcpay_enabled'           => true,
			'enabled_payment_method_ids' => array( 'card' ),
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_settings' )
			->willReturn( $settings );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $settings, $response->get_data() );
	}

	/**
	 * @testdox Should persist the WooPayments settings contract through the settings POST route.
	 */
	public function test_update_settings_contract_by_manager(): void {
		$settings = array(
			'is_wcpay_enabled'           => true,
			'enabled_payment_method_ids' => array( 'card', 'link' ),
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return true === $params['is_wcpay_enabled']
							&& array( 'card', 'link' ) === $params['enabled_payment_method_ids'];
					}
				)
			)
			->willReturn( $settings );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'is_wcpay_enabled'           => true,
				'enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $settings, $response->get_data() );
	}

	/**
	 * @testdox Should forward the accepted settings POST "$_dataName" to settings persistence unchanged.
	 * @dataProvider provider_accepted_settings_updates
	 *
	 * @param array      $body            Posted settings.
	 * @param string     $account_country Account country.
	 * @param array|null $answer          Settings the service answers and the route returns, or null for a row that answers an empty array and does not check the response.
	 */
	public function test_update_settings_forwards_accepted_settings( array $body, string $account_country, ?array $answer ): void {
		$this->account_country = $account_country;
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with( $this->identicalTo( $body ) )
			->willReturn( $answer ?? array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params( $body );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		if ( null !== $answer ) {
			$this->assertSame( $answer, $response->get_data() );
		}
	}

	/**
	 * Settings POST bodies the route accepts.
	 *
	 * @return array<string,array{array<string,mixed>,string,?array<string,mixed>}>
	 */
	public function provider_accepted_settings_updates(): array {
		$manual_capture = array(
			'is_manual_capture_enabled' => true,
			'is_woopay_enabled'         => false,
		);
		$fraud_sentinel = array(
			'is_debug_log_enabled'               => true,
			'advanced_fraud_protection_settings' => 'error',
		);

		return array(
			'manual capture and WooPay choices'       => array( $manual_capture, 'US', $manual_capture ),
			'fraud settings error sentinel'           => array( $fraud_sentinel, 'US', $fraud_sentinel ),
			'valid advanced fraud ruleset'            => array(
				array(
					'current_protection_level'           => 'advanced',
					'advanced_fraud_protection_settings' => array(
						array(
							'key'     => 'international_ip_address',
							'outcome' => 'block',
							'check'   => array(
								'key'      => 'ip_country',
								'operator' => 'in',
								'value'    => 'US',
							),
						),
					),
				),
				'US',
				null,
			),
			'invalid fraud ruleset at a non-advanced level' => array(
				array(
					'current_protection_level'           => 'standard',
					'advanced_fraud_protection_settings' => array( array( 'bogus' => true ) ),
				),
				'US',
				null,
			),
			'valid statement descriptor'              => array( array( 'account_statement_descriptor' => 'WOO STORE' ), 'US', null ),
			'valid communications email'              => array( array( 'account_communications_email' => 'merchant@example.com' ), 'US', null ),
			'valid hex branding color and empty secondary color' => array(
				array(
					'account_branding_primary_color'   => '#123ABC',
					'account_branding_secondary_color' => '',
				),
				'US',
				null,
			),
			'scheme-less business URL'                => array( array( 'account_business_url' => 'example.test/store' ), 'US', null ),
			'empty business support email'            => array( array( 'account_business_support_email' => '' ), 'US', null ),
			'empty support phone on a non-Japanese account' => array( array( 'account_business_support_phone' => '' ), 'US', null ),
			'+81 support phone on a Japanese account' => array( array( 'account_business_support_phone' => '+81312345678' ), 'JP', null ),
			'allowlisted support address'             => array(
				array(
					'account_business_support_address' => array(
						'city'        => 'Portland',
						'country'     => 'US',
						'line1'       => '123 Main St',
						'line2'       => '',
						'postal_code' => '97201',
						'state'       => 'OR',
					),
				),
				'US',
				null,
			),
			'nullable monthly anchor and keyed support address' => array(
				array(
					'deposit_schedule_monthly_anchor'  => null,
					'account_business_support_address' => array(
						'city'        => 'San Francisco',
						'country'     => 'US',
						'line1'       => '60 29th Street',
						'postal_code' => '94110',
					),
				),
				'US',
				array( 'deposit_schedule_monthly_anchor' => null ),
			),
		);
	}

	/**
	 * @testdox Should reject the settings POST "$_dataName" before settings persistence.
	 * @dataProvider provider_rejected_settings_updates
	 *
	 * @param array       $body            Posted settings.
	 * @param string      $account_country Account country.
	 * @param string      $field           Rejected field.
	 * @param string|null $code            Expected error code, or null for a row that does not check it.
	 * @param bool        $field_in_params Whether the row checks that the error lists the field in its params.
	 * @param string|null $detail_code     Expected error code in the field's details, or null for a row that does not check it.
	 */
	public function test_update_settings_rejects_invalid_settings( array $body, string $account_country, string $field, ?string $code, bool $field_in_params, ?string $detail_code ): void {
		$this->account_country = $account_country;
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params( $body );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		if ( null !== $code ) {
			$this->assertSame( $code, $response->get_data()['code'] );
		}
		if ( $field_in_params ) {
			$this->assertArrayHasKey( $field, $response->get_data()['data']['params'] );
		}
		if ( null !== $detail_code ) {
			$this->assertSame( $detail_code, $response->get_data()['data']['details'][ $field ]['code'] );
		}
	}

	/**
	 * Settings POST bodies the route rejects. The statement descriptor, communications email and Japanese support phone
	 * vectors mirror the WooPayments plugin's validators.
	 *
	 * @return array<string,array{array<string,mixed>,string,string,?string,bool,?string}>
	 */
	public function provider_rejected_settings_updates(): array {
		$descriptor = 'account_statement_descriptor';
		$email      = 'account_communications_email';
		$phone      = 'account_business_support_phone';

		return array(
			'unknown fraud settings string'                => array( array( 'advanced_fraud_protection_settings' => 'not_a_ruleset' ), 'US', 'advanced_fraud_protection_settings', 'rest_invalid_param', true, null ),
			'invalid advanced fraud ruleset'               => array(
				array(
					'current_protection_level'           => 'advanced',
					'advanced_fraud_protection_settings' => array( array( 'bogus' => true ) ),
				),
				'US',
				'advanced_fraud_protection_settings',
				'rest_invalid_param',
				false,
				'rest_invalid_pattern',
			),
			'invalid payment method ID'                    => array( array( 'enabled_payment_method_ids' => array( 'card', 'not_a_payment_method' ) ), 'US', 'enabled_payment_method_ids', 'rest_invalid_param', true, null ),
			'duplicate payment method IDs'                 => array( array( 'enabled_payment_method_ids' => array( 'card', 'link', 'link' ) ), 'US', 'enabled_payment_method_ids', 'rest_invalid_param', true, null ),
			'descriptor shorter than 5 characters'         => array( array( $descriptor => 'WOOP' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor longer than 22 characters'         => array( array( $descriptor => 'WOOPAYMENTS STORE NAME XL' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor without a Latin letter'            => array( array( $descriptor => '1234567890' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor with an asterisk'                  => array( array( $descriptor => 'WOO*STORE' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor with a double quote'               => array( array( $descriptor => 'WOO"STORE' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor with a single quote'               => array( array( $descriptor => "WOO'STORE" ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor with angle brackets'               => array( array( $descriptor => 'WOO<STORE>' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'descriptor of only whitespace'                => array( array( $descriptor => '      ' ), 'US', $descriptor, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'empty communications email'                   => array( array( $email => '' ), 'US', $email, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'malformed communications email'               => array( array( $email => 'not-an-email' ), 'US', $email, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'communications email without a domain'        => array( array( $email => 'merchant@' ), 'US', $email, 'rest_invalid_param', true, 'rest_invalid_pattern' ),
			'malformed branding color'                     => array( array( 'account_branding_primary_color' => '#eeeeee onload=alert(1)' ), 'US', 'account_branding_primary_color', null, false, 'rest_invalid_pattern' ),
			'business URL whose scheme esc_url_raw strips' => array( array( 'account_business_url' => 'javascript:alert(document.cookie)' ), 'US', 'account_business_url', null, false, 'rest_invalid_pattern' ),
			'malformed business support email'             => array( array( 'account_business_support_email' => 'not-an-email' ), 'US', 'account_business_support_email', null, false, 'rest_invalid_pattern' ),
			'malformed support phone'                      => array( array( $phone => 'not a phone!' ), 'US', $phone, 'rest_invalid_param', false, 'rest_invalid_pattern' ),
			'US support phone on a Japanese account'       => array( array( $phone => '+12345678901' ), 'JP', $phone, null, false, 'rest_invalid_pattern' ),
			'empty support phone on a Japanese account'    => array( array( $phone => '' ), 'JP', $phone, null, false, 'rest_invalid_pattern' ),
			'support address with an unknown key'          => array(
				array(
					'account_business_support_address' => array(
						'city'   => 'Portland',
						'planet' => 'Earth',
					),
				),
				'US',
				'account_business_support_address',
				null,
				false,
				'rest_invalid_pattern',
			),
		);
	}

	/**
	 * @testdox Should accept only the settings payment method IDs in the settings update route.
	 */
	public function test_settings_update_route_accepts_the_settings_payment_method_ids(): void {
		$handlers = $this->server->get_routes()['/wc/v3/payments/settings'];
		$update   = current( array_filter( $handlers, static fn( array $handler ): bool => ! empty( $handler['methods']['POST'] ) ) );
		$arg      = $update['args']['enabled_payment_method_ids'];

		$this->assertSame(
			array( 'alipay', 'amazon_pay', 'apple_pay', 'au_becs_debit', 'bancontact', 'card', 'eps', 'google_pay', 'grabpay', 'ideal', 'jcb', 'klarna', 'link', 'multibanco', 'p24', 'sepa_debit', 'wechat_pay', 'affirm', 'afterpay_clearpay' ),
			$arg['items']['enum']
		);
		$this->assertSame( 19, $arg['maxItems'] );
	}

	/**
	 * @testdox Should convert an unattributed account-update rejection into the WooPayments extension's server_error body.
	 */
	public function test_update_settings_converts_account_update_rejection_to_server_error_body(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn(
				new WP_Error(
					'woocommerce_woopayments_account_update_rejected',
					'Error: The account update was rejected.',
					array( 'status' => 400 )
				)
			);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_name' => 'New Name',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'server_error' => 'Error: The account update was rejected.' ), $response->get_data() );
	}

	/**
	 * @testdox Should pass a field-attributed platform rejection through as the standard error envelope.
	 */
	public function test_update_settings_passes_attributed_platform_rejection_through(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn(
				new WP_Error(
					'wcpay_server_error',
					'Invalid parameter(s): account_statement_descriptor',
					array(
						'status'  => 400,
						'params'  => array( 'account_statement_descriptor' => 'Error: Invalid statement descriptor.' ),
						'details' => array(
							'account_statement_descriptor' => array(
								'code'    => 'invalid_request_error',
								'message' => 'Error: Invalid statement descriptor.',
								'data'    => null,
							),
						),
					)
				)
			);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_statement_descriptor' => 'VALID STORE',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( 'Error: Invalid statement descriptor.', $response->get_data()['data']['details']['account_statement_descriptor']['message'] );
	}

	/**
	 * @testdox Should expose visible WooPayments payment method promotions.
	 */
	public function test_get_pm_promotions_by_manager(): void {
		$promotions = array(
			array(
				'id'             => 'klarna-promo__spotlight',
				'payment_method' => 'klarna',
				'type'           => 'spotlight',
			),
		);

		$this->mock_pm_promotions_service
			->expects( $this->once() )
			->method( 'get_visible_promotions' )
			->willReturn( $promotions );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/pm-promotions' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $promotions, $response->get_data() );
	}

	/**
	 * @testdox Should $action WooPayments payment method promotions.
	 * @testWith ["activate"]
	 *           ["dismiss"]
	 *
	 * @param string $action Promotion action: the route suffix, and the promotions service method without its `_promotion` suffix.
	 */
	public function test_pm_promotion_action_by_manager( string $action ): void {
		$this->mock_pm_promotions_service
			->expects( $this->once() )
			->method( $action . '_promotion' )
			->with( 'klarna-promo__spotlight' )
			->willReturn( true );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/pm-promotions/klarna-promo__spotlight/' . $action );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	/**
	 * @testdox Should reject encoded path separators in promotion action routes.
	 */
	public function test_pm_promotion_routes_reject_encoded_path_separators(): void {
		$this->mock_pm_promotions_service
			->expects( $this->never() )
			->method( 'activate_promotion' );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/pm-promotions/klarna%2Fbad/activate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Each write route has its own permission callback; a public callback copied onto one must fail here.
	 *
	 * @testdox Should refuse the $method $route write route to an anonymous visitor and to a subscriber without calling a service.
	 * @testWith ["POST", "/wc/v3/payments/settings"]
	 *           ["POST", "/wc/v3/payments/settings/is_woopay_enabled"]
	 *           ["POST", "/wc/v3/payments/file"]
	 *           ["POST", "/wc/v3/payments/pm-promotions/klarna-promo__spotlight/activate"]
	 *           ["POST", "/wc/v3/payments/pm-promotions/klarna-promo__spotlight/dismiss"]
	 *           ["POST", "/wc-admin/settings/payments/woopayments/admin-notices/test_to_live/shown"]
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 */
	public function test_write_routes_refuse_users_without_manager_permission( string $method, string $route ): void {
		foreach ( array( $this->mock_settings_service, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_notice_service ) as $service ) {
			$service->expects( $this->never() )->method( $this->anything() );
		}

		foreach ( array( 0, $this->factory->user->create( array( 'role' => 'subscriber' ) ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			$request = new WP_REST_Request( $method, $route );
			$request->set_body_params( array( 'value' => true ) );

			$this->assertSame( rest_authorization_required_code(), $this->server->dispatch( $request )->get_status(), "user $user_id" );
		}
	}

	/**
	 * @testdox Should register the settings routes only while the built-in WooPayments owns the runtime: $_dataName.
	 * @dataProvider provider_settings_route_registration
	 *
	 * @param bool     $builtin_owner Whether the built-in WooPayments owns the runtime.
	 * @param string[] $registered    Routes that must be registered.
	 * @param string[] $absent        Routes that must not be registered.
	 */
	public function test_settings_routes_follow_the_runtime_owner( bool $builtin_owner, array $registered, array $absent ): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$runtime_arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$runtime_arbiter
			->method( 'is_builtin_owner' )
			->willReturn( $builtin_owner );

		$sut = new MerchantRestController();
		$sut->init( $this->mock_settings_service, $runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service, $this->mock_notice_service, new WooPaymentsApplePayDomainService(), $this->mock_api_client );
		$sut->register_routes( true );

		$routes = $this->server->get_routes();

		foreach ( $registered as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
		foreach ( $absent as $route ) {
			$this->assertArrayNotHasKey( $route, $routes );
		}
	}

	/**
	 * Registered and absent routes by runtime owner.
	 *
	 * @return array<string,array{bool,string[],string[]}>
	 */
	public function provider_settings_route_registration(): array {
		return array(
			'the WooPayments extension owns the runtime' => array(
				false,
				array( self::ENDPOINT . '/account' ),
				array( '/wc/v3/payments/settings', '/wc/v3/payments/pm-promotions', '/wc/v3/payments/file' ),
			),
			'the built-in WooPayments owns the runtime'  => array(
				true,
				array(
					'/wc/v3/payments/settings',
					'/wc/v3/payments/pm-promotions',
					'/wc/v3/payments/pm-promotions/(?P<id>[^/]+)/activate',
					'/wc/v3/payments/pm-promotions/(?P<id>[^/]+)/dismiss',
					'/wc/v3/payments/file',
					self::ENDPOINT . '/account',
				),
				array(
					'/wc/v3/payments/pm-promotions/(?P<promotion_id>[^/]+)/activate',
					'/wc/v3/payments/pm-promotions/(?P<promotion_id>[^/]+)/dismiss',
				),
			),
		);
	}

	/**
	 * @testdox Should update only allowlisted WooPayments settings options.
	 */
	public function test_update_settings_option_by_manager(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_option' )
			->with( 'wcpay_fraud_protection_welcome_tour_dismissed', true )
			->willReturn( true );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings/wcpay_fraud_protection_welcome_tour_dismissed' );
		$request->set_body_params( array( 'value' => true ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	/**
	 * @testdox Should return option update errors from the WooPayments settings service.
	 */
	public function test_update_settings_option_returns_service_error(): void {
		$error = new WP_Error(
			'woocommerce_woopayments_invalid_settings_option',
			'Invalid option.',
			array( 'status' => 400 )
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_option' )
			->with( 'not_allowed', true )
			->willReturn( $error );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings/not_allowed' );
		$request->set_body_params( array( 'value' => true ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_woopayments_invalid_settings_option', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should upload WooPayments settings files through the API client.
	 */
	public function test_upload_settings_file_calls_the_api_client(): void {
		// The platform answers an upload with a file object (client 11.1.0 includes/admin/class-wc-rest-payments-files-controller.php:147), trimmed here to its id.
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'upload_file' )
			->with( $this->isInstanceOf( WP_REST_Request::class ) )
			->willReturn( array( 'id' => 'file_test_logo' ) );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/file' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => 'file_test_logo' ), $response->get_data() );
	}

	/**
	 * @testdox Should read WooPayments settings file details and contents through the API client.
	 */
	public function test_settings_file_detail_and_content_routes_call_the_api_client(): void {
		// File object and file contents shapes from client 11.1.0 includes/admin/class-wc-rest-payments-files-controller.php:147 and :174, trimmed.
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file_contents' )
			->with( 'file_test_logo', true )
			->willReturn(
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				)
			);

		$detail_request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo/details' );
		$detail_response = $this->server->dispatch( $detail_request );
		$content_request = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo/content' );
		$content_request->set_param( 'as_account', true );
		$content_response = $this->server->dispatch( $content_request );

		$this->assertSame( 200, $detail_response->get_status() );
		$this->assertSame( 'business_logo', $detail_response->get_data()['purpose'] );
		$this->assertSame( 200, $content_response->get_status() );
		$this->assertSame( 'image/png', $content_response->get_data()['content_type'] );
		$this->assertSame( 'TE9HTw==', $content_response->get_data()['file_content'] );
	}

	/**
	 * @testdox A platform failure "$code" with status $platform_status on $method $path answers $expected_status.
	 * @dataProvider file_route_failure_provider
	 *
	 * @param string $method          HTTP method.
	 * @param string $path            Route path.
	 * @param string $api_method      API client method the route calls.
	 * @param string $code            Platform error code.
	 * @param int    $platform_status Platform HTTP status.
	 * @param int    $expected_status Expected route status.
	 */
	public function test_settings_file_routes_answer_platform_failures_with_a_4xx_or_5xx( string $method, string $path, string $api_method, string $code, int $platform_status, int $expected_status ): void {
		$this->mock_api_client
			->expects( $this->once() )
			->method( $api_method )
			->willThrowException( new ApiException( 'Platform failure.', $code, $platform_status ) );

		$response = $this->server->dispatch( new WP_REST_Request( $method, $path ) );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertSame( 'Platform failure.', $response->get_data()['message'] );
	}

	/**
	 * Provide file route failures. Like the other admin routes, the routes keep a 400-599 status and answer 500 for any
	 * other, as an unreadable 200 answer (Client's wcpay_unparseable_or_null_body) or an upload whose body
	 * could not be encoded (status 0). With the status passed through, the logo upload read those as a success.
	 * The failures are synthetic: each row builds the exception with an error code the API client or the platform uses.
	 *
	 * @return array<string,array{string,string,string,string,int,int}>
	 */
	public function file_route_failure_provider(): array {
		return array(
			'details, missing file'         => array( 'GET', '/wc/v3/payments/file/file_missing/details', 'get_file', 'resource_missing', 404, 404 ),
			'details, unreadable answer'    => array( 'GET', '/wc/v3/payments/file/file_test_logo/details', 'get_file', 'wcpay_unparseable_or_null_body', 200, 500 ),
			'content, missing file'         => array( 'GET', '/wc/v3/payments/file/file_missing/content', 'get_file_contents', 'resource_missing', 404, 404 ),
			'content, unreadable answer'    => array( 'GET', '/wc/v3/payments/file/file_test_logo/content', 'get_file_contents', 'wcpay_unparseable_or_null_body', 200, 500 ),
			'upload, unreadable answer'     => array( 'POST', '/wc/v3/payments/file', 'upload_file', 'wcpay_evidence_file_upload_error', 200, 500 ),
			'upload, unencodable body'      => array( 'POST', '/wc/v3/payments/file', 'upload_file', 'wcpay_evidence_file_upload_error', 0, 500 ),
			'upload, platform server error' => array( 'POST', '/wc/v3/payments/file', 'upload_file', 'wcpay_evidence_file_upload_error', 502, 502 ),
		);
	}

	/**
	 * @testdox Should serve public WooPayments business logo files without payment manager permissions.
	 */
	public function test_get_public_settings_file_serves_public_logo_without_permissions(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = static fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => false,
		);
		add_filter( 'user_has_cap', $filter_callback );

		// File object and file contents shapes from client 11.1.0 includes/admin/class-wc-rest-payments-files-controller.php:147 and :174, trimmed.
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file_contents' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				)
			);

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'LOGO', $response->get_data() );
		$this->assertSame( 'image/png', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'inline', $response->get_headers()['Content-Disposition'] );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * @testdox Should normalize missing public WooPayments files to a not found response.
	 */
	public function test_get_public_settings_file_normalizes_missing_file_errors(): void {
		// Synthetic failure: the platform's resource_missing code with a 500, which client 11.1.0 also answers as 404 (includes/admin/class-wc-rest-payments-files-controller.php:199).
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_missing', false )
			->willThrowException( new ApiException( 'No such file.', 'resource_missing', WP_Http::INTERNAL_SERVER_ERROR ) );
		$this->mock_api_client
			->expects( $this->never() )
			->method( 'get_file_contents' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_missing' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( WP_Http::NOT_FOUND, $response->get_status() );
		$this->assertSame( 'resource_missing', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should normalize public WooPayments file content errors to an internal error response.
	 */
	public function test_get_public_settings_file_normalizes_content_errors(): void {
		// File object shape from client 11.1.0 includes/admin/class-wc-rest-payments-files-controller.php:147, trimmed; the contents failure is synthetic.
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file_contents' )
			->with( 'file_test_logo', false )
			->willThrowException( new ApiException( 'Unable to retrieve file contents.', 'provider_timeout', WP_Http::BAD_GATEWAY ) );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( WP_Http::INTERNAL_SERVER_ERROR, $response->get_status() );
		$this->assertSame( 'provider_timeout', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should block non-public WooPayments files without payment manager permissions.
	 */
	public function test_get_public_settings_file_blocks_private_files_without_permissions(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = static fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => false,
		);
		add_filter( 'user_has_cap', $filter_callback );

		// File object shape from client 11.1.0 includes/admin/class-wc-rest-payments-files-controller.php:147, trimmed.
		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_private', false )
			->willReturn(
				array(
					'id'      => 'file_private',
					'purpose' => 'dispute_evidence',
				)
			);
		$this->mock_api_client
			->expects( $this->never() )
			->method( 'get_file_contents' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_private' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cannot_view', $response->get_data()['code'] );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * Client 11.1.0 keeps a file's purpose for WC_Payments_File_Service::CACHE_PERIOD, a day
	 * (includes/class-wc-payments-file-service.php:22-23, read at includes/admin/class-wc-rest-payments-files-controller.php:103).
	 *
	 * @testdox Should keep a file's classification for a day after reading it from the platform.
	 */
	public function test_get_public_settings_file_keeps_the_classification_for_a_day(): void {
		// The platform answers the Stripe file object (wpcom class-files-controller.php:158-172 at d761f9d3d85), trimmed.
		$this->mock_api_client
			->method( 'get_file' )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		// The platform answers content_type and the base64 file_content (wpcom class-files-controller.php:198-202 at d761f9d3d85).
		$this->mock_api_client
			->method( 'get_file_contents' )
			->willReturn(
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				)
			);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo' ) );
		$expires  = (int) get_option( '_transient_timeout_wcpay_file_purpose_file_test_logo_0' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'business_logo', get_transient( 'wcpay_file_purpose_file_test_logo_0' ) );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $expires, 60 );
	}
}
