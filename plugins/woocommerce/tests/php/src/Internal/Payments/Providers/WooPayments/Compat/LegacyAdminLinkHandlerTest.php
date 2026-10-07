<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Connection\Manager;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\LegacyAdminLinkHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the native legacy admin link handler.
 */
class LegacyAdminLinkHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var LegacyAdminLinkHandler
	 */
	private LegacyAdminLinkHandler $sut;

	/**
	 * Recording API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Capital controller double.
	 *
	 * @var WooPaymentsCapitalRestController&MockObject
	 */
	private WooPaymentsCapitalRestController $capital;

	/**
	 * Account service double.
	 *
	 * @var WooPaymentsAccountService&MockObject
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Tracking info double.
	 *
	 * @var WooPaymentsTrackingInfoService&MockObject
	 */
	private WooPaymentsTrackingInfoService $tracking_info;

	/**
	 * Jetpack connection manager in place before the test replaced it.
	 *
	 * @var mixed
	 */
	private $previous_jetpack_manager = null;

	/**
	 * Whether the test replaced the Jetpack connection manager.
	 *
	 * @var bool
	 */
	private bool $jetpack_manager_replaced = false;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client      = $this->create_api_client();
		$this->capital         = $this->getMockBuilder( WooPaymentsCapitalRestController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'redirect_loan_offer_request' ) )
			->getMock();
		$this->account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_account', 'is_details_submitted', 'clear_cache', 'is_test_mode_enabled' ) )
			->getMock();
		$this->account_service->method( 'has_account' )->willReturn( true );
		$this->tracking_info = $this->getMockBuilder( WooPaymentsTrackingInfoService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_tracking_info' ) )
			->getMock();
		$this->sut           = $this->create_handler( true );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'admin_init', array( $this->sut, 'handle_request' ) );
		remove_action( 'admin_init', array( $this->sut, 'handle_login_request' ) );
		remove_action( 'admin_init', array( $this->sut, 'handle_kyc_reminder_return' ), 9 );
		remove_action( 'admin_init', array( $this->sut, 'handle_hosted_kyc_return' ), 9 );
		remove_action( 'admin_init', array( $this->sut, 'handle_reconnect_wpcom_request' ) );
		remove_all_filters( 'jetpack_use_iframe_authorization_flow' );
		if ( $this->jetpack_manager_replaced ) {
			$this->set_jetpack_manager( $this->previous_jetpack_manager );
			$this->jetpack_manager_replaced = false;
		}
		remove_action( 'admin_init', array( $this->sut, 'handle_loan_offer_request' ), 12 );
		remove_all_filters( 'allowed_redirect_hosts' );
		remove_all_filters( 'woocommerce_tracks_event_properties' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_doing_ajax' );
		delete_transient( 'wcpay_stripe_onboarding_state' );
		unset( $_GET['wcpay-loan-offer'], $_GET['wcpay-link-handler'], $_GET['type'], $_GET['return_url'], $_GET['nested'], $_GET['page'], $_GET['path'], $_GET['wcpay-connect-redirect'], $_GET['wcpay-login'], $_GET['wcpay-reconnect-wpcom'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['from'], $_GET['wcpay-state'], $_GET['wcpay-mode'], $_GET['wcpay-account-id'], $_GET['wcpay-connection-error'], $_SERVER['HTTP_REFERER'] );

		parent::tearDown();
	}

	/**
	 * @testdox A Capital offer email link reaches the loan-offer redirect at admin_init on an admin page, and other admin pages do not.
	 */
	public function test_loan_offer_link_redirects_at_admin_init(): void {
		$this->capital->expects( $this->once() )->method( 'redirect_loan_offer_request' );
		$this->sut->register();

		$this->run_admin_init();
		$_GET['wcpay-loan-offer'] = '';
		$this->run_admin_init();
	}

	/**
	 * Run the admin_init callbacks the handler registered, in priority order.
	 */
	private function run_admin_init(): void {
		global $wp_filter;
		$callbacks = $wp_filter['admin_init']->callbacks;
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $this->sut === $callback['function'][0] ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}

	/**
	 * @testdox The legacy admin link hook is registered only while native owns the runtime.
	 */
	public function test_registers_only_when_native_owns_runtime(): void {
		$this->sut->register();

		$this->assertNotFalse( has_action( 'admin_init', array( $this->sut, 'handle_request' ) ) );

		remove_action( 'admin_init', array( $this->sut, 'handle_request' ) );
		$this->sut = $this->create_handler( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'handle_request' ) ) );
	}

	/**
	 * @testdox The Overview "Edit details" link is handled at admin_init only while native owns the runtime.
	 */
	public function test_registers_login_handler_only_when_native_owns_runtime(): void {
		$this->sut->register();
		$this->assertNotFalse( has_action( 'admin_init', array( $this->sut, 'handle_login_request' ) ) );

		remove_action( 'admin_init', array( $this->sut, 'handle_login_request' ) );
		$this->sut = $this->create_handler( false );
		$this->sut->register();
		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'handle_login_request' ) ) );
	}

	/**
	 * @testdox The dashboard login link clears the account cache and redirects to the platform login URL that returns to Overview.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-account.php:1276-1297` and `class-wc-payments-redirect-service.php:275-285`.
	 */
	public function test_login_link_redirects_to_dashboard(): void {
		$this->account_service->method( 'is_details_submitted' )->willReturn( true );
		$this->account_service->expects( $this->once() )->method( 'clear_cache' );
		$this->api_client->login_response = array( 'url' => 'https://connect.stripe.com/express/login_test' );
		$this->set_login_request( wp_create_nonce( 'wcpay-login' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_redirect_host' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_login_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: https://connect.stripe.com/express/login_test', $exception->getMessage() );
		}

		$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/overview' ), $this->api_client->login_redirect_url );
		$this->assertSame( array(), $this->api_client->last_args );
	}

	/**
	 * @testdox The dashboard login link sends an account with unsubmitted details to the KYC account link instead.
	 */
	public function test_login_link_sends_unsubmitted_account_to_kyc_link(): void {
		$this->account_service->method( 'is_details_submitted' )->willReturn( false );
		$this->account_service->expects( $this->never() )->method( 'clear_cache' );
		$this->api_client->response = array(
			'url'   => 'https://connect.stripe.com/setup/kyc',
			'state' => 'state_kyc',
		);
		$this->set_login_request( wp_create_nonce( 'wcpay-login' ) );
		$_GET['from'] = 'WCPAY_ACCOUNT_DETAILS';
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_redirect_host' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_login_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: https://connect.stripe.com/setup/kyc', $exception->getMessage() );
		}

		$this->assertSame(
			array(
				'from' => 'WCPAY_ACCOUNT_DETAILS',
				'type' => 'complete_kyc_link',
			),
			$this->api_client->last_args
		);
		$this->assertNull( $this->api_client->login_redirect_url );
		$this->assertSame( 'state_kyc', get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * @testdox A failed dashboard login lands on Overview with the login error notice.
	 */
	public function test_login_link_failure_redirects_to_overview_error(): void {
		$this->account_service->method( 'is_details_submitted' )->willReturn( true );
		$this->api_client->login_exception = new WooPaymentsApiException( 'Login unavailable.', 'login_unavailable', 503 );
		$this->set_login_request( wp_create_nonce( 'wcpay-login' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_login_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $caught ) {
			$location = rawurldecode( $caught->getMessage() );
			$this->assertStringContainsString( 'path=/woopayments/overview', $location );
			$this->assertStringContainsString( 'wcpay-login-error=1', $location );
		}
	}

	/**
	 * @testdox The dashboard login link refuses a request without a valid nonce before calling the platform.
	 */
	public function test_login_link_requires_valid_nonce(): void {
		$this->set_login_request( 'invalid' );

		try {
			$this->sut->handle_login_request();
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( \WPDieException $exception ) {
			unset( $exception );
		}

		$this->assertNull( $this->api_client->login_redirect_url );
		$this->assertSame( array(), $this->api_client->last_args );
	}

	/**
	 * Simulate a click on the Overview dashboard login link.
	 *
	 * @param string $nonce Request nonce.
	 */
	private function set_login_request( string $nonce ): void {
		$_GET['wcpay-login']  = '1';
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	/**
	 * @testdox The WordPress.com reconnect link is handled at admin_init only while native owns the runtime.
	 */
	public function test_registers_reconnect_handler_only_when_native_owns_runtime(): void {
		$this->sut->register();
		$this->assertNotFalse( has_action( 'admin_init', array( $this->sut, 'handle_reconnect_wpcom_request' ) ) );

		remove_action( 'admin_init', array( $this->sut, 'handle_reconnect_wpcom_request' ) );
		$this->sut = $this->create_handler( false );
		$this->sut->register();
		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'handle_reconnect_wpcom_request' ) ) );
	}

	/**
	 * @testdox The reconnect link records the connection start and redirects a registered site to the Jetpack authorization flow.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-account.php:1300-1318,1983-1993` and `class-wc-payments-http.php:186-212`.
	 */
	public function test_reconnect_link_redirects_to_jetpack_authorization(): void {
		$manager = $this->replace_jetpack_manager( true );
		$manager->expects( $this->never() )->method( 'try_registration' );
		$manager->expects( $this->once() )
			->method( 'get_authorization_url' )
			->with( null, Utils::wc_payments_settings_url( '/woopayments/settings' ) )
			->willReturn( 'https://jetpack.wordpress.com/jetpack.authorize/1/?client_id=1' );
		$recorded = $this->record_tracks_events();
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		$_GET['from'] = 'WCPAY_OVERVIEW';
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( array( $this->sut, 'handle_reconnect_wpcom_request' ) );

		$this->assertStringStartsWith( 'https://jetpack.wordpress.com/jetpack.authorize/1/', $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'woocommerce-core-profiler', $query['from'] );
		$this->assertSame( 'woocommerce', $query['plugin_name'] );
		$this->assertSame( 'production', $query['calypso_env'] );
		$this->assertFalse( apply_filters( 'jetpack_use_iframe_authorization_flow', true ) );
		$this->assertArrayHasKey( 'wcadmin_wcpay_account_connect_wpcom_connection_start', $recorded->events );
		$this->assertTrue( $recorded->events['wcadmin_wcpay_account_connect_wpcom_connection_start']['is_reconnect'] );
		$this->assertSame( 'WCPAY_OVERVIEW', $recorded->events['wcadmin_wcpay_account_connect_wpcom_connection_start']['from'] );
	}

	/**
	 * @testdox The reconnect connection-start event carries the plugin's default properties plus the cached platform tracking info.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-account.php:1307-1315` (event props), `:3156-3169,3203-3220` (tracks_event()
	 * merges get_tracking_info() last), and the platform's `tracking/info` response (`hosting_provider`).
	 */
	public function test_reconnect_event_merges_platform_tracking_info(): void {
		$manager = $this->replace_jetpack_manager( true );
		$manager->method( 'get_authorization_url' )->willReturn( 'https://jetpack.wordpress.com/jetpack.authorize/1/' );
		$this->tracking_info->expects( $this->once() )->method( 'get_tracking_info' )->willReturn(
			array(
				'hosting_provider' => 'fixture-host-7',
				'wcpay_version'    => 'platform-override',
			)
		);
		$this->account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$recorded = $this->record_tracks_events();
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		$_GET['from'] = 'WCPAY_OVERVIEW';
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->run_and_get_redirect( array( $this->sut, 'handle_reconnect_wpcom_request' ) );

		$properties = $recorded->events['wcadmin_wcpay_account_connect_wpcom_connection_start'];
		$this->assertSame( 'fixture-host-7', $properties['hosting_provider'] );
		// The plugin merges the tracking info after its defaults, so a platform key wins.
		$this->assertSame( 'platform-override', $properties['wcpay_version'] );
		$this->assertTrue( $properties['is_reconnect'] );
		$this->assertSame( 'WCPAY_OVERVIEW', $properties['from'] );
		$this->assertTrue( $properties['is_test_mode'] );
		$this->assertFalse( $properties['jetpack_connected'] );
		$this->assertSame( WC()->countries->get_base_country(), $properties['woo_country_code'] );
	}

	/**
	 * @testdox Without platform tracking info the reconnect event keeps the plugin's default properties only.
	 */
	public function test_reconnect_event_without_tracking_info(): void {
		$manager = $this->replace_jetpack_manager( true );
		$manager->method( 'get_authorization_url' )->willReturn( 'https://jetpack.wordpress.com/jetpack.authorize/1/' );
		$this->tracking_info->method( 'get_tracking_info' )->willReturn( null );
		$recorded = $this->record_tracks_events();
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->run_and_get_redirect( array( $this->sut, 'handle_reconnect_wpcom_request' ) );

		$properties = $recorded->events['wcadmin_wcpay_account_connect_wpcom_connection_start'];
		$this->assertArrayNotHasKey( 'hosting_provider', $properties );
		$this->assertSame( WooPaymentsClientVersion::VERSION, $properties['wcpay_version'] );
	}

	/**
	 * @testdox The reconnect link registers an unregistered site before redirecting to the authorization flow.
	 */
	public function test_reconnect_link_registers_site_first(): void {
		$manager = $this->replace_jetpack_manager( false );
		$manager->expects( $this->once() )->method( 'try_registration' )->willReturn( true );
		$manager->method( 'get_authorization_url' )->willReturn( 'https://jetpack.wordpress.com/jetpack.authorize/1/' );
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( array( $this->sut, 'handle_reconnect_wpcom_request' ) );

		$this->assertStringStartsWith( 'https://jetpack.wordpress.com/jetpack.authorize/1/', $location );
	}

	/**
	 * @testdox A failed site registration lands on Overview with the link error notice instead of the plugin's fatal.
	 */
	public function test_reconnect_link_registration_failure_lands_on_overview_error(): void {
		$manager = $this->replace_jetpack_manager( false );
		$manager->method( 'try_registration' )->willReturn( new \WP_Error( 'register_failed', 'Registration failed.' ) );
		$manager->expects( $this->never() )->method( 'get_authorization_url' );
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = rawurldecode( $this->run_and_get_redirect( array( $this->sut, 'handle_reconnect_wpcom_request' ) ) );

		$this->assertStringContainsString( 'page=wc-settings&tab=checkout&path=/woopayments/overview', $location );
		$this->assertStringContainsString( 'wcpay-server-link-error=1', $location );
	}

	/**
	 * @testdox The reconnect link refuses a request without a valid nonce before touching the connection.
	 */
	public function test_reconnect_link_requires_valid_nonce(): void {
		$manager = $this->replace_jetpack_manager( true );
		$manager->expects( $this->never() )->method( 'get_authorization_url' );
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-login' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->expectException( \WPDieException::class );
		$this->sut->handle_reconnect_wpcom_request();
	}

	/**
	 * @testdox The reconnect link does nothing for users who cannot manage WooCommerce.
	 */
	public function test_reconnect_link_requires_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$manager = $this->replace_jetpack_manager( true );
		$manager->expects( $this->never() )->method( 'get_authorization_url' );
		$this->set_reconnect_request( wp_create_nonce( 'wcpay-reconnect-wpcom' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->sut->handle_reconnect_wpcom_request();
	}

	/**
	 * Simulate a click on the reconnect link built by the plugin's `get_wpcom_reconnect_url()`.
	 *
	 * @param string $nonce Request nonce.
	 */
	private function set_reconnect_request( string $nonce ): void {
		$_GET['wcpay-reconnect-wpcom'] = '1';
		$_GET['_wpnonce']              = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
	}

	/**
	 * Run a handler and return the intercepted redirect target.
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function run_and_get_redirect( callable $handler ): string {
		try {
			$handler();
		} catch ( \RuntimeException $exception ) {
			return substr( $exception->getMessage(), strlen( 'wp_redirect intercepted: ' ) );
		}

		$this->fail( 'Expected the redirect to be intercepted.' );
	}

	/**
	 * Replace the Jetpack connection manager with a double.
	 *
	 * @param bool $is_connected Whether the site is registered.
	 * @return Manager&MockObject
	 */
	private function replace_jetpack_manager( bool $is_connected ): Manager {
		$manager = $this->getMockBuilder( Manager::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_connected', 'has_connected_owner', 'try_registration', 'get_authorization_url' ) )
			->getMock();
		$manager->method( 'is_connected' )->willReturn( $is_connected );
		$manager->method( 'has_connected_owner' )->willReturn( false );

		$property = new \ReflectionProperty( JetpackConnection::class, 'manager' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$this->previous_jetpack_manager = $property->getValue();
		$this->jetpack_manager_replaced = true;
		$this->set_jetpack_manager( $manager );

		return $manager;
	}

	/**
	 * Set the Jetpack connection manager.
	 *
	 * @param mixed $manager Manager.
	 */
	private function set_jetpack_manager( $manager ): void {
		$property = new \ReflectionProperty( JetpackConnection::class, 'manager' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $manager );
	}

	/**
	 * @testdox The legacy admin link forwards sanitized query arguments and redirects to the returned provider URL.
	 */
	public function test_forwards_query_arguments_and_redirects_to_provider_url(): void {
		$this->api_client->response = array(
			'url'   => 'https://connect.stripe.com/setup/session',
			'state' => 'state_test',
		);
		$_GET                       = array(
			'wcpay-link-handler' => '',
			'type'               => 'complete_kyc_link',
			'return_url'         => 'https:\/\/example.com\/return',
			'nested'             => array( '<b>value</b>' ),
		);
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_redirect_host' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: https://connect.stripe.com/setup/session', $exception->getMessage() );
		}

		$this->assertSame(
			array(
				'type'       => 'complete_kyc_link',
				'return_url' => 'https://example.com/return',
				'nested'     => array( 'value' ),
			),
			$this->api_client->last_args
		);
		$this->assertSame( 'state_test', get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * @testdox Link failures redirect to the native overview error notice.
	 * @dataProvider provider_failed_link_responses
	 *
	 * @param array<string,mixed>          $response  API response.
	 * @param WooPaymentsApiException|null $exception Optional API exception.
	 */
	public function test_redirects_to_overview_error_when_link_creation_fails( array $response, ?WooPaymentsApiException $exception ): void {
		$this->api_client->response  = $response;
		$this->api_client->exception = $exception;
		$_GET['wcpay-link-handler']  = '';
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $caught ) {
			$location = rawurldecode( $caught->getMessage() );
			$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $location );
			$this->assertStringContainsString( 'wcpay-server-link-error=1', $location );
		}

		$this->assertFalse( get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * Failed link responses.
	 *
	 * @return array<string,array{response:array<string,mixed>,exception:WooPaymentsApiException|null}>
	 */
	public function provider_failed_link_responses(): array {
		return array(
			'missing URL'   => array(
				'response'  => array( 'state' => 'state_without_url' ),
				'exception' => null,
			),
			'API exception' => array(
				'response'  => array(),
				'exception' => new WooPaymentsApiException( 'Link unavailable.', 'link_unavailable', 503 ),
			),
		);
	}

	/**
	 * @testdox The handler ignores requests without the capability, flag, or native ownership.
	 * @dataProvider provider_ignored_requests
	 *
	 * @param bool $native_owner Whether native owns the runtime.
	 * @param bool $has_flag     Whether the request has the handler flag.
	 * @param bool $has_access   Whether the current user has admin access.
	 */
	public function test_ignores_ineligible_requests( bool $native_owner, bool $has_flag, bool $has_access ): void {
		$this->sut = $this->create_handler( $native_owner );
		if ( $has_flag ) {
			$_GET['wcpay-link-handler'] = '';
		}
		if ( ! $has_access ) {
			wp_set_current_user( 0 );
		}

		$this->sut->handle_request();

		$this->assertSame( array(), $this->api_client->last_args );
	}

	/**
	 * Ignored request states.
	 *
	 * @return array<string,array{native_owner:bool,has_flag:bool,has_access:bool}>
	 */
	public function provider_ignored_requests(): array {
		return array(
			'plugin owns runtime' => array(
				'native_owner' => false,
				'has_flag'     => true,
				'has_access'   => true,
			),
			'missing flag'        => array(
				'native_owner' => true,
				'has_flag'     => false,
				'has_access'   => true,
			),
			'missing capability'  => array(
				'native_owner' => true,
				'has_flag'     => true,
				'has_access'   => false,
			),
		);
	}

	/**
	 * @testdox A KYC reminder email link records the merchant return and redirects to the native route for the legacy connect page.
	 * @dataProvider provider_kyc_reminder_links
	 *
	 * Source: platform `class-onboarding-reminder-email.php:63` and `class-onboarding-reminder-followup-email.php:45` mint
	 * `admin.php?page=wc-admin&path=/payments/connect&wcpay-connect-redirect=<initial|second|1-4>`; plugin 11.1.0
	 * `WC_Payments_Account::maybe_redirect_by_get_param()` lines 776-809 records the offset and description.
	 *
	 * @param string $reminder    The `wcpay-connect-redirect` value.
	 * @param int    $offset      Expected offset in days.
	 * @param string $description Expected description.
	 */
	public function test_kyc_reminder_link_records_return_and_redirects_to_native_route( string $reminder, int $offset, string $description ): void {
		$recorded = $this->record_tracks_events();
		$_GET     = array(
			'page'                   => 'wc-admin',
			'path'                   => '/payments/connect',
			'wcpay-connect-redirect' => $reminder,
		);
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_kyc_reminder_return();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			// Plugin 11.1.0 continues with `redirect_to_wcpay_connect( 'WCPAY_KYC_REMINDER' )`, which adds `from=WCPAY_KYC_REMINDER`.
			$this->assertSame( 'wp_redirect intercepted: ' . admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding&from=WCPAY_KYC_REMINDER' ), $exception->getMessage() );
		}

		$this->assertSame( $offset, $recorded->events['wcadmin_wcpay_kyc_reminder_merchant_returned']['offset'] ?? null );
		$this->assertSame( $description, $recorded->events['wcadmin_wcpay_kyc_reminder_merchant_returned']['description'] ?? null );
	}

	/**
	 * KYC reminder link values and their Tracks properties.
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function provider_kyc_reminder_links(): array {
		return array(
			'initial'  => array( 'initial', 1, 'initial' ),
			'second'   => array( 'second', 3, 'second' ),
			'weekly 2' => array( '2', 14, 'weekly-2' ),
			'unknown'  => array( '9', 0, 'weekly-0' ),
		);
	}

	/**
	 * @testdox A KYC reminder link falls back to the overview with the plugin's `from=WCPAY_KYC_REMINDER` when no native route resolves.
	 */
	public function test_kyc_reminder_link_falls_back_to_overview_with_from(): void {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( true );
		$navigation = $this->createMock( WooPaymentsAdminNavigationController::class );
		$navigation->method( 'get_legacy_payment_path_redirect_url' )->willReturn( '' );
		$handler = new LegacyAdminLinkHandler();
		$handler->init( $arbiter, $this->api_client, $navigation, $this->capital, $this->account_service, $this->tracking_info );
		$_GET = array(
			'page'                   => 'wc-admin',
			'path'                   => '/payments/connect',
			'wcpay-connect-redirect' => 'initial',
		);
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->expectExceptionMessage( 'wp_redirect intercepted: ' . admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview&from=WCPAY_KYC_REMINDER' ) );

		$handler->handle_kyc_reminder_return();
	}

	/**
	 * @testdox A KYC reminder link is ignored off the legacy connect page, without the capability, or when the plugin owns the runtime.
	 */
	public function test_kyc_reminder_link_ignores_ineligible_requests(): void {
		$recorded = $this->record_tracks_events();
		$_GET     = array(
			'page'                   => 'wc-admin',
			'path'                   => '/payments/overview',
			'wcpay-connect-redirect' => 'initial',
		);
		$this->sut->handle_kyc_reminder_return();

		$_GET['path'] = '/payments/connect';
		$this->create_handler( false )->handle_kyc_reminder_return();

		wp_set_current_user( 0 );
		$this->sut->handle_kyc_reminder_return();

		$this->assertSame( array(), $recorded->events );
	}

	/**
	 * @testdox A KYC reminder link is ignored during an AJAX request, as in plugin 11.1.0 `maybe_redirect_by_get_param()` line 769.
	 */
	public function test_kyc_reminder_link_ignores_ajax_requests(): void {
		$recorded = $this->record_tracks_events();
		$_GET     = array(
			'page'                   => 'wc-admin',
			'path'                   => '/payments/connect',
			'wcpay-connect-redirect' => 'initial',
		);
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->sut->handle_kyc_reminder_return();

		$this->assertSame( array(), $recorded->events );
	}

	/**
	 * @testdox The KYC reminder hook runs before the legacy route redirect at the default priority.
	 */
	public function test_registers_kyc_reminder_hook_before_legacy_route_redirects(): void {
		$this->sut->register();

		$this->assertSame( 9, has_action( 'admin_init', array( $this->sut, 'handle_kyc_reminder_return' ) ) );
	}

	/**
	 * @testdox The hosted KYC return handler runs before the legacy route redirect, and only while native owns the runtime.
	 */
	public function test_registers_hosted_kyc_return_hook_before_legacy_route_redirects(): void {
		$this->sut->register();
		$this->assertSame( 9, has_action( 'admin_init', array( $this->sut, 'handle_hosted_kyc_return' ) ) );

		remove_action( 'admin_init', array( $this->sut, 'handle_hosted_kyc_return' ), 9 );
		$this->sut = $this->create_handler( false );
		$this->sut->register();
		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'handle_hosted_kyc_return' ) ) );
	}

	/**
	 * Plugin 11.1.0 `maybe_handle_onboarding()` (class-wc-payments-account.php:1866-1880) hands a `wcpay-state` return to
	 * `finalize_connection()` (:2354-2437), which enables the gateway in the returned mode, restores the test-drive methods,
	 * and lands on Overview. The return URL is the platform's (`class-links-controller.php:126`, `class-onboarding-redirect-controller.php:612-635`).
	 *
	 * @testdox A hosted KYC return with the stored state enables the gateway in the returned mode and lands on Overview, like plugin 11.1.0.
	 */
	public function test_hosted_kyc_return_finalizes_connection_and_redirects_to_overview(): void {
		$this->account_service->expects( $this->once() )->method( 'clear_cache' );
		set_transient( 'wcpay_stripe_onboarding_state', 'state_kyc', DAY_IN_SECONDS );
		set_transient( 'test_drive_account_settings_for_live_account', array( 'enabled_payment_methods' => array( 'card', 'link' ) ), HOUR_IN_SECONDS );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'no',
				'test_mode'                      => 'yes',
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		delete_option( 'wcpay_kyc_submitted_date' );
		$_GET = $this->get_hosted_kyc_return_query( 'state_kyc', 'live' );
		$this->sut->register();
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( fn() => $this->run_admin_init() );
		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertSame(
			Utils::wc_payments_settings_url(
				'/woopayments/overview',
				array(
					'from'                     => 'STRIPE',
					'source'                   => 'unknown',
					'wcpay-connection-success' => '1',
				)
			),
			$location
		);
		$this->assertSame( 'yes', $settings['enabled'] );
		$this->assertSame( 'no', $settings['test_mode'] );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertFalse( get_transient( 'wcpay_stripe_onboarding_state' ) );
		$this->assertFalse( get_transient( 'test_drive_account_settings_for_live_account' ) );
		$this->assertSame( array( 'is_existing_stripe_account' => false ), get_option( '_wcpay_onboarding_stripe_connected' ) );
		$this->assertNotFalse( get_option( 'wcpay_kyc_submitted_date', false ) );
	}

	/**
	 * Plugin 11.1.0 `finalize_connection()` (class-wc-payments-account.php:2417-2424): a return with `wcpay-connection-error`
	 * still enables the gateway, then sends the merchant back to the connect page to finish KYC. The onboarding data cleanup
	 * (:2427-2428) runs only after that branch, so the recommended payment methods stay cached for the resumed onboarding.
	 *
	 * @testdox A hosted KYC return that left KYC early enables the gateway in test mode and sends the merchant back to onboarding, like plugin 11.1.0.
	 */
	public function test_hosted_kyc_return_with_connection_error_redirects_to_onboarding(): void {
		$this->account_service->expects( $this->once() )->method( 'clear_cache' );
		set_transient( 'wcpay_stripe_onboarding_state', 'state_kyc', DAY_IN_SECONDS );
		set_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods', array( 'payment_methods' => array( array( 'id' => 'card' ) ) ), DAY_IN_SECONDS );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'no' ) );
		$_GET = array( 'wcpay-connection-error' => '1' ) + $this->get_hosted_kyc_return_query( 'state_kyc', 'test' );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( fn() => $this->sut->handle_hosted_kyc_return() );
		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertSame(
			Utils::wc_payments_settings_url(
				'/woopayments/onboarding',
				array(
					'from'                   => 'STRIPE',
					'source'                 => 'unknown',
					'wcpay-connection-error' => '1',
				)
			),
			$location
		);
		$this->assertSame( 'yes', $settings['enabled'] );
		$this->assertSame( 'yes', $settings['test_mode'] );
		$this->assertFalse( get_transient( 'wcpay_stripe_onboarding_state' ) );
		$this->assertNotFalse( get_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' ) );
	}

	/**
	 * Plugin 11.1.0 `finalize_connection()` (class-wc-payments-account.php:2355-2368) rejects a state it did not store.
	 *
	 * @testdox A hosted KYC return with an unknown state changes nothing and sends the merchant back to onboarding, like plugin 11.1.0.
	 */
	public function test_hosted_kyc_return_with_unknown_state_redirects_to_onboarding(): void {
		$this->account_service->expects( $this->never() )->method( 'clear_cache' );
		set_transient( 'wcpay_stripe_onboarding_state', 'state_kyc', DAY_IN_SECONDS );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'no' ) );
		$_GET = $this->get_hosted_kyc_return_query( 'state_forged', 'live' );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( fn() => $this->sut->handle_hosted_kyc_return() );

		$this->assertSame(
			Utils::wc_payments_settings_url(
				'/woopayments/onboarding',
				array(
					'from'   => '',
					'source' => 'unknown',
				)
			),
			$location
		);
		$this->assertSame( array( 'enabled' => 'no' ), get_option( 'woocommerce_woocommerce_payments_settings' ) );
		$this->assertSame( 'state_kyc', get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * Referers for a hosted KYC return, with the `from` plugin 11.1.0 `WC_Payments_Onboarding_Service::get_from()` derives.
	 *
	 * The referer rows are the client's own (tests/unit/test-class-wc-payments-onboarding-service.php:671-680).
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>}>
	 */
	public function provide_hosted_kyc_return_referers(): array {
		return array(
			'Stripe referer'          => array( 'STRIPE', 'http://something.stripe.com/something', array() ),
			'WordPress.com referer'   => array( 'WPCOM', 'http://public-api.wordpress.com/something', array() ),
			'from param wins over it' => array( 'WCPAY_OVERVIEW', 'http://something.stripe.com/something', array( 'from' => 'WCPAY_OVERVIEW' ) ),
			'unrecognized referer'    => array( '', 'https://example.org/elsewhere', array() ),
		);
	}

	/**
	 * Plugin 11.1.0 `maybe_handle_onboarding()` (class-wc-payments-account.php:1267) takes `from` from `get_from()`, which falls
	 * back to the referer (class-wc-payments-onboarding-service.php:1203-1240), and `finalize_connection()` carries it to the
	 * connect page when the state is unknown (:2358-2366).
	 *
	 * @testdox A hosted KYC return with an unknown state carries the referer-derived from to onboarding, like plugin 11.1.0.
	 * @dataProvider provide_hosted_kyc_return_referers
	 *
	 * @param string               $expected_from Expected `from` on the landing.
	 * @param string               $referer       Request referer.
	 * @param array<string,string> $extra_query   Extra query arguments.
	 */
	public function test_hosted_kyc_return_with_unknown_state_derives_from_like_the_client( string $expected_from, string $referer, array $extra_query ): void {
		set_transient( 'wcpay_stripe_onboarding_state', 'state_kyc', DAY_IN_SECONDS );
		$_GET                    = $extra_query + $this->get_hosted_kyc_return_query( 'state_forged', 'live' );
		$_SERVER['HTTP_REFERER'] = $referer;
		$this->sut->register();
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$location = $this->run_and_get_redirect( fn() => $this->run_admin_init() );

		$this->assertSame(
			Utils::wc_payments_settings_url(
				'/woopayments/onboarding',
				array(
					'from'   => $expected_from,
					'source' => 'unknown',
				)
			),
			$location
		);
	}

	/**
	 * @testdox A hosted KYC return is ignored during an AJAX request, without the capability, or when the plugin owns the runtime.
	 */
	public function test_hosted_kyc_return_ignores_ineligible_requests(): void {
		$this->account_service->expects( $this->never() )->method( 'clear_cache' );
		set_transient( 'wcpay_stripe_onboarding_state', 'state_kyc', DAY_IN_SECONDS );
		$_GET = $this->get_hosted_kyc_return_query( 'state_kyc', 'live' );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		$this->create_handler( false )->handle_hosted_kyc_return();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->sut->handle_hosted_kyc_return();
		remove_all_filters( 'wp_doing_ajax' );
		wp_set_current_user( 0 );
		$this->sut->handle_hosted_kyc_return();

		$this->assertSame( 'state_kyc', get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * Build the platform's hosted KYC return query (`class-onboarding-redirect-controller.php:612-635`).
	 *
	 * @param string $state Returned state secret.
	 * @param string $mode  Returned account mode.
	 * @return array<string,string>
	 */
	private function get_hosted_kyc_return_query( string $state, string $mode ): array {
		return array(
			'page'             => 'wc-admin',
			'path'             => '/payments/overview',
			'wcpay-state'      => $state,
			'wcpay-account-id' => 'acct_finalized_native',
			'wcpay-mode'       => $mode,
		);
	}

	/**
	 * Record Tracks events by name.
	 *
	 * @return \stdClass Holder whose `events` property maps event names to properties.
	 */
	private function record_tracks_events(): \stdClass {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded         = new \stdClass();
		$recorded->events = array();
		add_filter(
			'woocommerce_tracks_event_properties',
			static function ( $properties, $event_name ) use ( $recorded ) {
				$recorded->events[ $event_name ] = $properties;
				return $properties;
			},
			10,
			2
		);

		return $recorded;
	}

	/**
	 * Add the Stripe redirect host for redirect tests.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_stripe_redirect_host( array $hosts ): array {
		$hosts[] = 'connect.stripe.com';

		return $hosts;
	}

	/**
	 * Intercept redirects so production exit paths do not stop the test runner.
	 *
	 * @param string $location Redirect target.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect( string $location ): void {
		throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
	}

	/**
	 * Create a native legacy admin link handler.
	 *
	 * @param bool $native_register Whether native should own hook registration.
	 * @return LegacyAdminLinkHandler
	 */
	private function create_handler( bool $native_register ): LegacyAdminLinkHandler {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$navigation = $this->getMockBuilder( WooPaymentsAdminNavigationController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_legacy_payment_path_redirect_url' ) )
			->getMock();
		// Like the real controller, resolve the legacy connect page and forward the other scalar query arguments.
		$navigation->method( 'get_legacy_payment_path_redirect_url' )->willReturnCallback(
			static function ( array $request ): string {
				if ( 'wc-admin' !== ( $request['page'] ?? '' ) || '/payments/connect' !== ( $request['path'] ?? '' ) ) {
					return '';
				}
				unset( $request['page'], $request['path'] );

				return Utils::wc_payments_settings_url( '/woopayments/onboarding', $request );
			}
		);

		$handler = new LegacyAdminLinkHandler();
		$handler->init( $arbiter, $this->api_client, $navigation, $this->capital, $this->account_service, $this->tracking_info );

		return $handler;
	}

	/**
	 * Create a recording API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {

			/** @var array<string,mixed> */
			public array $response = array();

			/** @var WooPaymentsApiException|null */
			public ?WooPaymentsApiException $exception = null;

			/** @var array<string,mixed> */
			public array $last_args = array();

			/** @var array<string,mixed> */
			public array $login_response = array();

			/** @var WooPaymentsApiException|null */
			public ?WooPaymentsApiException $login_exception = null;

			/** @var string|null */
			public ?string $login_redirect_url = null;

			/**
			 * Create a dashboard login link.
			 *
			 * @param string $redirect_url Return URL.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured.
			 */
			public function create_login_link( string $redirect_url ): array {
				$this->login_redirect_url = $redirect_url;
				if ( null !== $this->login_exception ) {
					throw $this->login_exception;
				}

				return $this->login_response;
			}

			/**
			 * Create an account link.
			 *
			 * @param array<string,mixed> $args Account-link arguments.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured.
			 */
			public function create_account_link( array $args ): array {
				$this->last_args = $args;
				if ( null !== $this->exception ) {
					throw $this->exception;
				}

				return $this->response;
			}
		};
	}
}
