<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments settings controller.
 */
class WooPaymentsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $_GET['woopayments-ref'], $_GET['page'], $_GET['tab'], $_GET['path'] );
		delete_transient( 'woopayments_referral_code' );
		delete_option( NativePaymentsState::OPTION_NAME );
		remove_all_filters( 'wp_redirect' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @testdox A `woopayments-ref` link from a store manager stores the sanitized code and redirects to the plugin's onboarding URL.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` (the `woopayments-ref` argument and
	 * `sanitize_text_field`) continuing with `WC_Payments_Redirect_Service::redirect_to_nox_flow( 'REFERRAL' )`.
	 */
	public function test_referral_link_redirects_store_managers_to_onboarding_with_the_referral_source(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['woopayments-ref'] = ' partner-<b>abc</b> ';
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
			}
		);
		// The URL `redirect_to_nox_flow( 'REFERRAL' )` builds in plugin 11.1.0.
		$expected = admin_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/onboarding',
					'from' => 'REFERRAL',
				),
				'admin.php'
			)
		);

		try {
			$this->create_controller( $this->create_native_onboarding_service() )->handle_referral_link();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: ' . $expected, $exception->getMessage() );
		}

		$this->assertSame( 'partner-abc', get_transient( 'woopayments_referral_code' ) );
	}

	/**
	 * @testdox A `woopayments-ref` link is ignored for users who cannot manage WooCommerce.
	 */
	public function test_referral_link_is_ignored_without_the_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$_GET['woopayments-ref'] = 'partner-abc';
		$service                 = $this->createMock( WooPaymentsService::class );
		$service->expects( $this->never() )->method( 'handle_onboarding_referral' );

		$this->create_controller( $service )->handle_referral_link();
	}

	/**
	 * @testdox Should register the onboarding redirect only on the Settings page load hook.
	 */
	public function test_registers_onboarding_redirect_on_the_settings_page_load_hook(): void {
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();

		$this->assertSame( 10, has_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ) ) );

		remove_action( 'admin_init', array( $sut, 'handle_returns_from_wpcom' ) );
		remove_action( 'admin_init', array( $sut, 'handle_referral_link' ), 13 );
		remove_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) );
	}

	/**
	 * @testdox Should redirect a native WooPayments page to onboarding in the disabled state without a connection.
	 * @dataProvider provider_disabled_state_redirects
	 *
	 * @param string               $path           Requested native WooPayments route path.
	 * @param array<string,string> $expected_query Expected onboarding tracking query args.
	 */
	public function test_redirects_to_onboarding_in_the_disabled_state_without_a_connection( string $path, array $expected_query ): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( false, false );

		$url = $this->run_shell_redirect( $sut, $path );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( admin_url( 'admin.php' ), strtok( $url, '?' ) );
		$this->assertSame(
			array_merge(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/onboarding',
				),
				$expected_query
			),
			$query
		);
		$this->assertSame( 1, $sut->resolutions );
	}

	/**
	 * Disabled-state requests and their onboarding tracking args.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public function provider_disabled_state_redirects(): array {
		return array(
			'transaction details' => array( '/woopayments/transactions/details', array() ),
			'overview'            => array(
				'/woopayments/overview',
				array(
					'from'   => 'WCPAY_OVERVIEW',
					'source' => 'unknown',
				),
			),
			'settings'            => array(
				'/woopayments/settings',
				array(
					'from'   => 'WCADMIN_PAYMENT_SETTINGS',
					'source' => 'wcadmin-settings-page',
				),
			),
		);
	}

	/**
	 * @testdox Should not redirect or resolve the redirect for the onboarding route in the disabled state.
	 */
	public function test_does_not_redirect_the_onboarding_route_in_the_disabled_state(): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( false, false );

		$this->assertSame( '', $this->run_shell_redirect( $sut, '/woopayments/onboarding' ) );
		$this->assertSame( 0, $sut->resolutions );
	}

	/**
	 * @testdox Should not redirect a connected store with a valid account in the disabled state.
	 */
	public function test_does_not_redirect_a_connected_valid_account_in_the_disabled_state(): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( true, true );

		$this->assertSame( '', $this->run_shell_redirect( $sut, '/woopayments/transactions' ) );
	}

	/**
	 * @testdox Should redirect a connected store with an invalid account in the disabled state, like the client.
	 */
	public function test_redirects_a_connected_invalid_account_in_the_disabled_state(): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( true, false );

		$this->assertStringContainsString( 'path=/woopayments/onboarding', $this->run_shell_redirect( $sut, '/woopayments/transactions' ) );
	}

	/**
	 * @testdox Should not redirect when the WooPayments plugin owns the payments runtime.
	 */
	public function test_does_not_redirect_when_native_does_not_own_the_runtime(): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( false, false, false );

		$this->assertSame( '', $this->run_shell_redirect( $sut, '/woopayments/transactions' ) );
	}

	/**
	 * @testdox Should not resolve the onboarding redirect on unrelated admin requests.
	 * @dataProvider provider_unrelated_requests
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_resolve_the_redirect_on_unrelated_requests( array $request ): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( false, false );
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		$sut->maybe_redirect_to_onboarding();

		$this->assertSame( 0, $sut->resolutions );
	}

	/**
	 * Requests that are not guarded native WooPayments routes.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_unrelated_requests(): array {
		return array(
			'Payments settings main page' => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'other settings tab'          => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'general',
					'path' => '/woopayments/overview',
				),
			),
			'offline payment method'      => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/offline/bacs',
				),
			),
		);
	}

	/**
	 * @testdox Should decide once per request, so the connected-tier hook and the Settings page hook do not both act.
	 */
	public function test_onboarding_redirect_decides_once_per_request(): void {
		$this->set_admin_user();
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/woopayments/transactions';
		$api_client   = $this->createMock( WooPaymentsApiClient::class );
		$api_client->expects( $this->once() )->method( 'is_available' )->willReturn( true );
		$redirect = $this->create_onboarding_redirect( $api_client, true, true );

		$redirect->maybe_redirect();
		$redirect->maybe_redirect();
	}

	/**
	 * Mark the store as a native-owned store in the disabled state, where the admin navigation controller is not loaded.
	 */
	private function set_disabled_native_store(): void {
		$this->set_admin_user();
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::DISABLED );
	}

	/**
	 * Log in as a store manager.
	 */
	private function set_admin_user(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Run the Settings page onboarding redirect for a native WooPayments route path.
	 *
	 * @param WooPaymentsController $sut  Controller under test.
	 * @param string                $path Native WooPayments route path.
	 * @return string The intercepted redirect location, or an empty string.
	 */
	private function run_shell_redirect( WooPaymentsController $sut, string $path ): string {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;
		$location     = '';
		add_filter(
			'wp_redirect',
			static function ( $redirect_location ) use ( &$location ) {
				$location = esc_url_raw( $redirect_location );
				throw new \RuntimeException( 'wp_redirect intercepted' );
			}
		);

		try {
			$sut->maybe_redirect_to_onboarding();
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted', $exception->getMessage() );
		}

		return $location;
	}

	/**
	 * Create the shared onboarding redirect with test doubles.
	 *
	 * @param WooPaymentsApiClient $api_client    API client double.
	 * @param bool                 $valid_account Whether the account is valid for admin navigation.
	 * @param bool                 $native_owner  Whether native owns the payments runtime.
	 * @return WooPaymentsOnboardingRedirect
	 */
	private function create_onboarding_redirect( WooPaymentsApiClient $api_client, bool $valid_account, bool $native_owner ): WooPaymentsOnboardingRedirect {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_owner );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->method( 'has_valid_account_for_admin_navigation' )->willReturn( $valid_account );

		$redirect = new WooPaymentsOnboardingRedirect();
		$redirect->init( $arbiter, $api_client, $account_service );

		return $redirect;
	}

	/**
	 * Create a controller whose onboarding redirect resolution is counted.
	 *
	 * @param bool $connected     Whether the WordPress.com connection works.
	 * @param bool $valid_account Whether the account is valid for admin navigation.
	 * @param bool $native_owner  Whether native owns the payments runtime.
	 * @return WooPaymentsController&object{resolutions:int}
	 */
	private function create_redirecting_controller( bool $connected, bool $valid_account, bool $native_owner = true ): WooPaymentsController {
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		$redirect = $this->create_onboarding_redirect( $api_client, $valid_account, $native_owner );

		$controller = new class( $redirect ) extends WooPaymentsController {
			/**
			 * Number of times the onboarding redirect was resolved.
			 *
			 * @var int
			 */
			public int $resolutions = 0;

			/**
			 * Shared onboarding redirect.
			 *
			 * @var WooPaymentsOnboardingRedirect
			 */
			private WooPaymentsOnboardingRedirect $redirect;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsOnboardingRedirect $redirect Shared onboarding redirect.
			 */
			public function __construct( WooPaymentsOnboardingRedirect $redirect ) {
				$this->redirect = $redirect;
			}

			/**
			 * Count and return the shared onboarding redirect.
			 *
			 * @return WooPaymentsOnboardingRedirect
			 */
			protected function get_onboarding_redirect(): WooPaymentsOnboardingRedirect {
				++$this->resolutions;

				return $this->redirect;
			}
		};
		$controller->init( $this->createMock( Payments::class ), $this->createMock( WooPaymentsService::class ) );

		return $controller;
	}

	/**
	 * Create a real WooPayments service for a store without an account, while native owns onboarding.
	 *
	 * @return WooPaymentsService
	 */
	private function create_native_onboarding_service(): WooPaymentsService {
		$legacy_runtime = $this->createMock( WooPaymentsLegacyRuntime::class );
		$legacy_runtime->method( 'is_loaded' )->willReturn( false );
		$onboarding_adapter = $this->createMock( WooPaymentsOnboardingAdapter::class );
		$onboarding_adapter->method( 'has_valid_account' )->willReturn( false );

		$service = new WooPaymentsService();
		$service->init(
			$this->createMock( PaymentsProviders::class ),
			$this->createMock( LegacyProxy::class ),
			$onboarding_adapter,
			$legacy_runtime,
			$this->createMock( WooPaymentsApiClient::class ),
			$this->createMock( WooPaymentsAccountService::class )
		);

		return $service;
	}

	/**
	 * Create the controller with a service double.
	 *
	 * @param WooPaymentsService $service WooPayments service double.
	 * @return WooPaymentsController
	 */
	private function create_controller( WooPaymentsService $service ): WooPaymentsController {
		$controller = new WooPaymentsController();
		$controller->init( $this->createMock( Payments::class ), $service );

		return $controller;
	}
}
