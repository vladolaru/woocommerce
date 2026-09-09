<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use WP_Error;
use WP_Http;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * WooPaymentsMerchantRestController API controller test.
 *
 * @class WooPaymentsMerchantRestController
 */
class WooPaymentsMerchantRestControllerTest extends WC_Unit_Test_Case {
	/**
	 * Endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = '/wc-admin/settings/payments/woopayments';

	/**
	 * @var WooPaymentsMerchantRestController
	 */
	protected WooPaymentsMerchantRestController $sut;

	/**
	 * @var MockObject|WooPaymentsService
	 */
	private $mock_woopayments_service;

	/**
	 * @var MockObject|WooPaymentsSettingsService
	 */
	private $mock_settings_service;

	/**
	 * @var MockObject|WooPaymentsPmPromotionsService
	 */
	private $mock_pm_promotions_service;

	/**
	 * @var MockObject|WooPaymentsOverviewService
	 */
	private $mock_overview_service;

	/**
	 * @var MockObject|NativePaymentsRuntimeArbiter
	 */
	private $mock_runtime_arbiter;

	/**
	 * @var MockObject|WooPaymentsAccountService
	 */
	private $mock_account_service;

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

		$this->mock_woopayments_service   = $this->getMockBuilder( WooPaymentsService::class )->getMock();
		$this->mock_settings_service      = $this->getMockBuilder( WooPaymentsSettingsService::class )->getMock();
		$this->mock_pm_promotions_service = $this->getMockBuilder( WooPaymentsPmPromotionsService::class )
			->onlyMethods( array( 'get_visible_promotions', 'activate_promotion', 'dismiss_promotion' ) )
			->getMock();
		$this->mock_overview_service      = $this->getMockBuilder( WooPaymentsOverviewService::class )
			->onlyMethods( array( 'get_overview' ) )
			->getMock();
		$this->mock_runtime_arbiter       = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$this->mock_runtime_arbiter
			->method( 'should_native_register' )
			->willReturn( true );
		$this->mock_account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country' ) )
			->getMock();
		$this->mock_account_service
			->method( 'get_account_country' )
			->willReturnCallback( fn() => $this->account_country );

		$this->sut = new WooPaymentsMerchantRestController();
		$this->sut->init( $this->mock_woopayments_service, $this->mock_settings_service, $this->mock_runtime_arbiter, $this->mock_pm_promotions_service, $this->mock_overview_service, $this->mock_account_service );
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
	 * @testdox Should return the native WooPayments account summary for users with permission.
	 */
	public function test_get_account_summary_by_manager() {
		$summary = array(
			'account' => array(
				'id'                   => 'acct_native_test',
				'mode'                 => 'test',
				'default_currency'     => 'usd',
				'connected'            => true,
				'working'              => true,
				'can_process_payments' => true,
				'test_mode'            => true,
				'test_drive'           => true,
				'sandbox'              => false,
				'live'                 => false,
			),
			'urls'    => array(
				'overview_page' => 'https://example.com/overview',
			),
		);

		$this->mock_woopayments_service
			->expects( $this->once() )
			->method( 'get_account_summary' )
			->willReturn( $summary );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/account' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $summary, $response->get_data() );
	}

	/**
	 * @testdox Should block the native WooPayments account summary for users without permission.
	 */
	public function test_get_account_summary_by_user_without_caps() {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => true,
		);
		add_filter( 'user_has_cap', $filter_callback );

		$this->mock_woopayments_service
			->expects( $this->never() )
			->method( 'get_account_summary' );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/account' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * @testdox Should return an account summary error when the service throws.
	 */
	public function test_get_account_summary_with_exception() {
		$this->mock_woopayments_service
			->expects( $this->once() )
			->method( 'get_account_summary' )
			->willThrowException( new \Exception( 'Account summary unavailable.' ) );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/account' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_woopayments_account_error', $response->get_data()['code'] );
		$this->assertSame( 'Account summary unavailable.', $response->get_data()['message'] );
	}

	/**
	 * @testdox Should return the native WooPayments Overview projection for users with permission.
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
			'show_update_details_task'              => true,
			'overview_tasks_visibility'             => array(
				'dismissed_todo_tasks'       => array( 'old-task' ),
				'deleted_todo_tasks'         => array(),
				'remind_me_later_todo_tasks' => array(),
			),
			'is_connection_success_modal_dismissed' => false,
			'wpcom_reconnect_url'                   => '',
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
	 * @testdox Should block the native WooPayments Overview projection for users without permission.
	 */
	public function test_get_overview_by_user_without_caps(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => true,
		);
		add_filter( 'user_has_cap', $filter_callback );

		$this->mock_overview_service
			->expects( $this->never() )
			->method( 'get_overview' );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/overview' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * @testdox Should return an Overview projection error when the service throws.
	 */
	public function test_get_overview_with_exception(): void {
		$this->mock_overview_service
			->expects( $this->once() )
			->method( 'get_overview' )
			->willThrowException( new \Exception( 'Overview unavailable.' ) );

		$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/overview' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_woopayments_overview_error', $response->get_data()['code'] );
		$this->assertSame( 'Overview unavailable.', $response->get_data()['message'] );
	}

	/**
	 * @testdox Should expose the legacy-compatible native WooPayments settings GET route.
	 */
	public function test_get_native_settings_contract_by_manager(): void {
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
	 * @testdox Should persist the native WooPayments settings contract through the legacy-compatible POST route.
	 */
	public function test_update_native_settings_contract_by_manager(): void {
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
	 * @testdox Should round-trip the fraud settings error sentinel through the native settings POST route.
	 */
	public function test_update_native_settings_accepts_fraud_error_sentinel(): void {
		$settings = array(
			'is_debug_log_enabled'               => true,
			'advanced_fraud_protection_settings' => 'error',
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return true === $params['is_debug_log_enabled']
							&& 'error' === $params['advanced_fraud_protection_settings'];
					}
				)
			)
			->willReturn( $settings );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'is_debug_log_enabled'               => true,
				'advanced_fraud_protection_settings' => 'error',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $settings, $response->get_data() );
	}

	/**
	 * @testdox Should reject unknown fraud settings strings before settings persistence.
	 */
	public function test_update_native_settings_rejects_unknown_fraud_settings_string(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'advanced_fraud_protection_settings' => 'not_a_ruleset',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'advanced_fraud_protection_settings', $response->get_data()['data']['params'] );
	}

	/**
	 * @testdox Should reject a structurally invalid advanced fraud ruleset before settings persistence.
	 */
	public function test_update_native_settings_rejects_invalid_advanced_fraud_ruleset(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'is_valid_fraud_ruleset' )
			->willReturn( false );
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => array( array( 'bogus' => true ) ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['advanced_fraud_protection_settings']['code'] );
	}

	/**
	 * @testdox Should accept a structurally valid advanced fraud ruleset.
	 */
	public function test_update_native_settings_accepts_valid_advanced_fraud_ruleset(): void {
		$ruleset = array(
			array(
				'key'     => 'international_ip_address',
				'outcome' => 'block',
				'check'   => array(
					'key'      => 'ip_country',
					'operator' => 'in',
					'value'    => 'US',
				),
			),
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'is_valid_fraud_ruleset' )
			->with( $ruleset )
			->willReturn( true );
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => $ruleset,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should not structurally validate the fraud ruleset when the protection level is not advanced.
	 */
	public function test_update_native_settings_skips_fraud_ruleset_validation_for_non_advanced_level(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'is_valid_fraud_ruleset' );
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => array( array( 'bogus' => true ) ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject invalid payment method IDs before settings persistence.
	 */
	public function test_update_native_settings_rejects_invalid_payment_method_ids(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'enabled_payment_method_ids' => array( 'card', 'not_a_payment_method' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'enabled_payment_method_ids', $response->get_data()['data']['params'] );
	}

	/**
	 * @testdox Should reject duplicate payment method IDs before settings persistence.
	 */
	public function test_update_native_settings_rejects_duplicate_payment_method_ids(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'enabled_payment_method_ids' => array( 'card', 'link', 'link' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'enabled_payment_method_ids', $response->get_data()['data']['params'] );
	}

	/**
	 * @testdox Should reject invalid statement descriptors before settings persistence.
	 * @dataProvider provider_invalid_statement_descriptors
	 *
	 * @param string $descriptor Invalid statement descriptor.
	 */
	public function test_update_native_settings_rejects_invalid_statement_descriptor( string $descriptor ): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_statement_descriptor' => $descriptor,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'account_statement_descriptor', $response->get_data()['data']['params'] );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_statement_descriptor']['code'] );
	}

	/**
	 * Invalid statement descriptor vectors, mirroring the WooPayments plugin's validator.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_invalid_statement_descriptors(): array {
		return array(
			'shorter than 5 characters'    => array( 'WOOP' ),
			'longer than 22 characters'    => array( 'WOOPAYMENTS STORE NAME XL' ),
			'no Latin letter'              => array( '1234567890' ),
			'contains asterisk'            => array( 'WOO*STORE' ),
			'contains double quote'        => array( 'WOO"STORE' ),
			'contains single quote'        => array( "WOO'STORE" ),
			'contains angle brackets'      => array( 'WOO<STORE>' ),
			'only whitespace when trimmed' => array( '      ' ),
		);
	}

	/**
	 * @testdox Should accept a valid statement descriptor and pass it through to settings persistence.
	 */
	public function test_update_native_settings_accepts_valid_statement_descriptor(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return 'WOO STORE' === $params['account_statement_descriptor'];
					}
				)
			)
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_statement_descriptor' => 'WOO STORE',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject an empty or malformed communications email before settings persistence.
	 * @dataProvider provider_invalid_communications_emails
	 *
	 * @param string $email Invalid communications email.
	 */
	public function test_update_native_settings_rejects_invalid_communications_email( string $email ): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_communications_email' => $email,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'account_communications_email', $response->get_data()['data']['params'] );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_communications_email']['code'] );
	}

	/**
	 * Invalid communications email vectors, mirroring the WooPayments plugin's validator.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_invalid_communications_emails(): array {
		return array(
			'empty string'      => array( '' ),
			'malformed address' => array( 'not-an-email' ),
			'missing domain'    => array( 'merchant@' ),
		);
	}

	/**
	 * @testdox Should accept a valid communications email and pass it through to settings persistence.
	 */
	public function test_update_native_settings_accepts_valid_communications_email(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return 'merchant@example.com' === $params['account_communications_email'];
					}
				)
			)
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_communications_email' => 'merchant@example.com',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should convert an unattributed account-update rejection into the legacy server_error body.
	 */
	public function test_update_native_settings_converts_account_update_rejection_to_server_error_body(): void {
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
	public function test_update_native_settings_passes_attributed_platform_rejection_through(): void {
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
	 * @testdox Should reject a malformed branding color but accept a valid or empty one.
	 */
	public function test_update_native_settings_validates_branding_colors(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_branding_primary_color' => '#eeeeee onload=alert(1)',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_branding_primary_color']['code'] );
	}

	/**
	 * @testdox Should accept a valid hex branding color and an empty secondary color.
	 */
	public function test_update_native_settings_accepts_valid_branding_colors(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_branding_primary_color'   => '#123ABC',
				'account_branding_secondary_color' => '',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject a business URL whose scheme is stripped entirely by esc_url_raw.
	 */
	public function test_update_native_settings_rejects_dangerous_business_url(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_url' => 'javascript:alert(document.cookie)',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_business_url']['code'] );
	}

	/**
	 * @testdox Should accept scheme-less and empty business URLs like the WooPayments plugin does.
	 */
	public function test_update_native_settings_accepts_lenient_business_urls(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return 'example.test/store' === $params['account_business_url'];
					}
				)
			)
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_url' => 'example.test/store',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject a malformed business support email but accept an empty one.
	 */
	public function test_update_native_settings_validates_business_support_email(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_email' => 'not-an-email',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_business_support_email']['code'] );
	}

	/**
	 * @testdox Should accept an empty business support email.
	 */
	public function test_update_native_settings_accepts_empty_business_support_email(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_email' => '',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject a malformed support phone before settings persistence.
	 */
	public function test_update_native_settings_rejects_malformed_support_phone(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_phone' => 'not a phone!',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_business_support_phone']['code'] );
	}

	/**
	 * @testdox Should accept an empty support phone on a non-Japanese account.
	 */
	public function test_update_native_settings_accepts_empty_support_phone_outside_japan(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_phone' => '',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject a support phone without the +81 prefix on a Japanese account.
	 * @dataProvider provider_invalid_japanese_support_phones
	 *
	 * @param string $phone Support phone rejected for a Japanese account.
	 */
	public function test_update_native_settings_rejects_non_japanese_support_phone_on_japanese_account( string $phone ): void {
		$this->account_country = 'JP';
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_phone' => $phone,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_business_support_phone']['code'] );
	}

	/**
	 * Support phone vectors a Japanese account must reject, mirroring the WooPayments plugin's validator.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_invalid_japanese_support_phones(): array {
		return array(
			'US number'    => array( '+12345678901' ),
			'empty string' => array( '' ),
		);
	}

	/**
	 * @testdox Should accept a +81 support phone on a Japanese account.
	 */
	public function test_update_native_settings_accepts_japanese_support_phone_on_japanese_account(): void {
		$this->account_country = 'JP';
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_phone' => '+81312345678',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should reject a support address carrying keys outside the allowlist.
	 */
	public function test_update_native_settings_rejects_support_address_with_unknown_key(): void {
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'update_settings' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_address' => array(
					'city'   => 'Portland',
					'planet' => 'Earth',
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_pattern', $response->get_data()['data']['details']['account_business_support_address']['code'] );
	}

	/**
	 * @testdox Should accept a support address restricted to the allowlisted keys.
	 */
	public function test_update_native_settings_accepts_allowlisted_support_address(): void {
		$address = array(
			'city'        => 'Portland',
			'country'     => 'US',
			'line1'       => '123 Main St',
			'line2'       => '',
			'postal_code' => '97201',
			'state'       => 'OR',
		);

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ) use ( $address ): bool {
						return $address === $params['account_business_support_address'];
					}
				)
			)
			->willReturn( array() );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'account_business_support_address' => $address,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @testdox Should expose visible native WooPayments payment method promotions.
	 */
	public function test_get_native_pm_promotions_by_manager(): void {
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
	 * @testdox Should activate native WooPayments payment method promotions.
	 */
	public function test_activate_native_pm_promotion_by_manager(): void {
		$this->mock_pm_promotions_service
			->expects( $this->once() )
			->method( 'activate_promotion' )
			->with( 'klarna-promo__spotlight' )
			->willReturn( true );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/pm-promotions/klarna-promo__spotlight/activate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	/**
	 * @testdox Should dismiss native WooPayments payment method promotions.
	 */
	public function test_dismiss_native_pm_promotion_by_manager(): void {
		$this->mock_pm_promotions_service
			->expects( $this->once() )
			->method( 'dismiss_promotion' )
			->with( 'klarna-promo__spotlight' )
			->willReturn( true );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/pm-promotions/klarna-promo__spotlight/dismiss' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	/**
	 * @testdox Should reject encoded path separators in promotion action routes.
	 */
	public function test_native_pm_promotion_routes_reject_encoded_path_separators(): void {
		$this->mock_pm_promotions_service
			->expects( $this->never() )
			->method( 'activate_promotion' );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/pm-promotions/klarna%2Fbad/activate' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should require payment gateway management permission for native payment method promotions.
	 */
	public function test_get_native_pm_promotions_requires_manager_permission(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->mock_pm_promotions_service
			->expects( $this->never() )
			->method( 'get_visible_promotions' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/pm-promotions' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
	}

	/**
	 * @testdox Should accept nullable monthly anchor and keyed support address objects.
	 */
	public function test_update_native_settings_accepts_nullable_anchor_and_keyed_address(): void {
		$settings = array( 'deposit_schedule_monthly_anchor' => null );

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'update_settings' )
			->with(
				$this->callback(
					static function ( array $params ): bool {
						return array_key_exists( 'deposit_schedule_monthly_anchor', $params )
							&& null === $params['deposit_schedule_monthly_anchor']
							&& array(
								'city'        => 'San Francisco',
								'country'     => 'US',
								'line1'       => '60 29th Street',
								'postal_code' => '94110',
							) === $params['account_business_support_address'];
					}
				)
			)
			->willReturn( $settings );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params(
			array(
				'deposit_schedule_monthly_anchor'  => null,
				'account_business_support_address' => array(
					'city'        => 'San Francisco',
					'country'     => 'US',
					'line1'       => '60 29th Street',
					'postal_code' => '94110',
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $settings, $response->get_data() );
	}

	/**
	 * @testdox Should not register native WooPayments settings routes while the standalone plugin owns runtime.
	 */
	public function test_native_settings_routes_are_not_registered_when_native_runtime_does_not_own_site(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$runtime_arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$runtime_arbiter
			->method( 'should_native_register' )
			->willReturn( false );

		$sut = new WooPaymentsMerchantRestController();
		$sut->init( $this->mock_woopayments_service, $this->mock_settings_service, $runtime_arbiter );
		$sut->register_routes( true );

		$routes = $this->server->get_routes();

		$this->assertArrayNotHasKey( '/wc/v3/payments/settings', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/pm-promotions', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/file', $routes );
		$this->assertArrayHasKey( self::ENDPOINT . '/account', $routes );
	}

	/**
	 * @testdox Should not register native WooPayments settings routes when no runtime arbiter is wired (fail closed).
	 */
	public function test_native_settings_routes_are_not_registered_without_runtime_arbiter(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$sut = new WooPaymentsMerchantRestController();
		$sut->init( $this->mock_woopayments_service, $this->mock_settings_service );
		$sut->register_routes( true );

		$routes = $this->server->get_routes();

		$this->assertArrayNotHasKey( '/wc/v3/payments/settings', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/pm-promotions', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/file', $routes );
		$this->assertArrayHasKey( self::ENDPOINT . '/account', $routes );
	}

	/**
	 * @testdox Should register native WooPayments settings routes when the runtime arbiter owns the site.
	 */
	public function test_native_settings_routes_are_registered_when_native_runtime_owns_site(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$runtime_arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$runtime_arbiter
			->method( 'should_native_register' )
			->willReturn( true );

		$sut = new WooPaymentsMerchantRestController();
		$sut->init( $this->mock_woopayments_service, $this->mock_settings_service, $runtime_arbiter );
		$sut->register_routes( true );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/settings', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/pm-promotions', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/pm-promotions/(?P<id>[^/]+)/activate', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/pm-promotions/(?P<id>[^/]+)/dismiss', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/pm-promotions/(?P<promotion_id>[^/]+)/activate', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/payments/pm-promotions/(?P<promotion_id>[^/]+)/dismiss', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/file', $routes );
		$this->assertArrayHasKey( self::ENDPOINT . '/account', $routes );
	}

	/**
	 * @testdox Should update only allowlisted native WooPayments settings options.
	 */
	public function test_update_native_settings_option_by_manager(): void {
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
	 * @testdox Should return option update errors from the native WooPayments settings service.
	 */
	public function test_update_native_settings_option_returns_service_error(): void {
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
	 * @testdox Should delegate native WooPayments settings file uploads.
	 */
	public function test_upload_native_settings_file_delegates_to_settings_service(): void {
		$this->mock_settings_service
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
	 * @testdox Should delegate native WooPayments settings file detail and content routes.
	 */
	public function test_get_native_settings_file_detail_and_content_routes_delegate_to_settings_service(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_settings_service
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
	 * @testdox Should serve public WooPayments business logo files without payment manager permissions.
	 */
	public function test_get_native_public_settings_file_serves_public_logo_without_permissions(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = static fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => false,
		);
		add_filter( 'user_has_cap', $filter_callback );

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_settings_service
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
	public function test_get_native_public_settings_file_normalizes_missing_file_errors(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_missing', false )
			->willReturn(
				new WP_Error(
					'resource_missing',
					'No such file.',
					array( 'status' => WP_Http::INTERNAL_SERVER_ERROR )
				)
			);
		$this->mock_settings_service
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
	public function test_get_native_public_settings_file_normalizes_content_errors(): void {
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file_contents' )
			->with( 'file_test_logo', false )
			->willReturn(
				new WP_Error(
					'provider_timeout',
					'Unable to retrieve file contents.',
					array( 'status' => WP_Http::BAD_GATEWAY )
				)
			);

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( WP_Http::INTERNAL_SERVER_ERROR, $response->get_status() );
		$this->assertSame( 'provider_timeout', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should block non-public WooPayments files without payment manager permissions.
	 */
	public function test_get_native_public_settings_file_blocks_private_files_without_permissions(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = static fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => false,
		);
		add_filter( 'user_has_cap', $filter_callback );

		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_private', false )
			->willReturn(
				array(
					'id'      => 'file_private',
					'purpose' => 'dispute_evidence',
				)
			);
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'get_file_contents' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_private' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cannot_view', $response->get_data()['code'] );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * @testdox Should re-evaluate file classification on a cache miss before serving to unauthorized callers.
	 */
	public function test_get_native_public_settings_file_rechecks_classification_on_cache_miss(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = static fn( $caps ) => array(
			'manage_woocommerce' => false,
			'install_plugins'    => false,
		);
		add_filter( 'user_has_cap', $filter_callback );

		// Ensure the classification cache misses so the handler must re-fetch the fresh purpose.
		delete_transient( 'woocommerce_native_woopayments_file_purpose_file_reclassified_0' );

		// The provider has reclassified this file from public to private since it was last served.
		$this->mock_settings_service
			->expects( $this->once() )
			->method( 'get_file' )
			->with( 'file_reclassified', false )
			->willReturn(
				array(
					'id'      => 'file_reclassified',
					'purpose' => 'dispute_evidence',
				)
			);
		$this->mock_settings_service
			->expects( $this->never() )
			->method( 'get_file_contents' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_reclassified' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cannot_view', $response->get_data()['code'] );

		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * @testdox Should cache the file classification with a short TTL so reclassifications propagate quickly.
	 */
	public function test_get_native_public_settings_file_caches_classification_with_short_ttl(): void {
		$reflection = new \ReflectionClassConstant( WooPaymentsMerchantRestController::class, 'FILE_PURPOSE_CACHE_TTL' );

		$this->assertSame( 5 * MINUTE_IN_SECONDS, $reflection->getValue() );
		$this->assertLessThan( DAY_IN_SECONDS, $reflection->getValue() );
	}

	/**
	 * @testdox Should register the public file REST serve filter at most once across repeated dispatches.
	 */
	public function test_get_native_public_settings_file_registers_serve_filter_once(): void {
		$this->mock_settings_service
			->method( 'get_file' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'id'      => 'file_test_logo',
					'purpose' => 'business_logo',
				)
			);
		$this->mock_settings_service
			->method( 'get_file_contents' )
			->with( 'file_test_logo', false )
			->willReturn(
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				)
			);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/file/file_test_logo' );
		$this->server->dispatch( $request );
		$this->server->dispatch( $request );

		$callback = array( $this->sut, 'serve_public_file_response' );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', $callback ) );
		$this->assertSame( 1, $this->count_filter_callbacks( 'rest_pre_serve_request', $callback ) );

		remove_filter( 'rest_pre_serve_request', $callback );
	}

	/**
	 * Count how many times a specific callback is registered on a hook.
	 *
	 * @param string $hook     The hook name.
	 * @param mixed  $callback The callback to count.
	 * @return int
	 */
	private function count_filter_callbacks( string $hook, $callback ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( $registered['function'] === $callback ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
