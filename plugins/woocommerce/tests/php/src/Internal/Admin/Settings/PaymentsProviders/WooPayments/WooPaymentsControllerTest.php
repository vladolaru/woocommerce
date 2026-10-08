<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
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
		wc_get_container()->reset_all_replacements();
		unset( $_GET['woopayments-ref'], $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['section'], $_GET['method'], $_GET['id'], $_GET['wcpay-connection-success'] );
		delete_transient( 'woopayments_referral_code' );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
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
	 * @testdox A WooCommerce update drops the onboarding fields cache in every native state, and leaves the plugin's own cache alone.
	 *
	 * @testWith [true, 1]
	 *           [false, 0]
	 *
	 * @param bool $native_owner Whether native owns the runtime.
	 * @param int  $clears       Expected cache clears.
	 */
	public function test_woocommerce_update_clears_the_native_onboarding_fields_cache( bool $native_owner, int $clears ): void {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_owner );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->exactly( $clears ) )->method( 'clear_onboarding_fields_cache' );
		wc_get_container()->replace( NativePaymentsRuntimeArbiter::class, $arbiter );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();
		$registered = has_action( 'woocommerce_updated', array( $sut, 'clear_native_onboarding_fields_cache' ) );
		remove_action( 'woocommerce_updated', array( $sut, 'clear_native_onboarding_fields_cache' ) );

		// Called directly: the controller core registered at boot listens to the same hook.
		$sut->clear_native_onboarding_fields_cache();

		$this->assertSame( 10, $registered );
	}

	/**
	 * @testdox Should register the onboarding redirect only on the Settings page load hook.
	 */
	public function test_registers_onboarding_redirect_on_the_settings_page_load_hook(): void {
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();

		$this->assertSame( 10, has_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
		$this->assertSame( 10, has_action( 'load-woocommerce_page_wc-admin', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ) ) );

		remove_action( 'admin_init', array( $sut, 'handle_returns_from_wpcom' ) );
		remove_action( 'admin_init', array( $sut, 'handle_referral_link' ), 13 );
		remove_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) );
		remove_action( 'load-woocommerce_page_wc-admin', array( $sut, 'maybe_redirect_to_onboarding' ) );
	}

	/**
	 * Client 11.1.0 sends these links to `redirect_to_nox_flow()` on a store without a working connection or a valid
	 * account: the settings section (`WC_Payments_Account::maybe_redirect_from_settings_page()`, reached from payment
	 * method sections through `WC_Payments_Admin_Settings::maybe_redirect_payment_method_settings()`), Overview
	 * (`maybe_redirect_from_overview_page()`), the onboarding wizard (`maybe_redirect_from_onboarding_wizard_page()`) and
	 * the Payments child pages (`WC_Payments_Admin::maybe_redirect_from_payments_admin_child_pages()`, no tracking args).
	 * The connect page has no native equivalent; native sends it to onboarding (inbox.md N-125).
	 *
	 * @testdox Should redirect the legacy WooPayments link $request to onboarding on a never-connected store.
	 * @dataProvider provider_legacy_links_on_a_never_connected_store
	 *
	 * @param array<string,string> $request        Legacy link query.
	 * @param array<string,string> $expected_query Expected onboarding tracking query args.
	 */
	public function test_redirects_legacy_links_to_onboarding_on_a_never_connected_store( array $request, array $expected_query ): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( false, false );

		$url = $this->run_shell_redirect_for_request( $sut, $request );

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
	 * Legacy WooPayments links and the onboarding tracking args client 11.1.0 adds for each.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>}>
	 */
	public function provider_legacy_links_on_a_never_connected_store(): array {
		$settings_from = array(
			'from'   => 'WCADMIN_PAYMENT_SETTINGS',
			'source' => 'wcadmin-settings-page',
		);

		return array(
			'settings section'                => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
				$settings_from,
			),
			'settings section, WooPay method' => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
					'method'  => 'woopay',
				),
				$settings_from,
			),
			'payment method section'          => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments_bancontact',
				),
				$settings_from,
			),
			'overview'                        => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/overview',
				),
				array(
					'from'   => 'WCPAY_OVERVIEW',
					'source' => 'unknown',
				),
			),
			'onboarding wizard'               => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/onboarding',
				),
				array(
					'from'   => 'WCPAY_ONBOARDING_WIZARD',
					'source' => 'unknown',
				),
			),
			'connect'                         => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/connect',
				),
				array(),
			),
			'transactions'                    => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/transactions',
				),
				array(),
			),
			'transaction details'             => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/transactions/details',
					'id'   => 'pi_123',
				),
				array(),
			),
			'payouts'                         => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/payouts',
				),
				array(),
			),
			'disputes'                        => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/disputes',
				),
				array(),
			),
		);
	}

	/**
	 * @testdox Should leave the legacy settings section alone for a connected store with a valid account.
	 */
	public function test_does_not_redirect_the_legacy_settings_section_for_a_valid_account(): void {
		$this->set_disabled_native_store();
		$sut = $this->create_redirecting_controller( true, true );

		$url = $this->run_shell_redirect_for_request(
			$sut,
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'woocommerce_payments',
			)
		);

		$this->assertSame( '', $url );
		$this->assertSame( 1, $sut->resolutions );
	}

	/**
	 * Client 11.1.0 hooks maybe_activate_woopay() on admin_init at the default priority and acts only on a page that
	 * carries `wcpay-connection-success` (includes/class-wc-payments-account.php:121, 2231-2234).
	 *
	 * @testdox Should check the platform's WooPay default on admin_init only on the page that carries the connection success flag.
	 */
	public function test_checks_platform_woopay_default_only_on_the_connection_success_page(): void {
		$service = $this->createMock( WooPaymentsService::class );
		$service->expects( $this->once() )->method( 'maybe_activate_woopay_enabled_by_default' );
		$sut = $this->create_controller( $service );

		$sut->register();
		$registered_priority = has_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'admin_init', array( $sut, 'handle_returns_from_wpcom' ) );
		remove_action( 'admin_init', array( $sut, 'handle_referral_link' ), 13 );
		remove_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) );

		$sut->maybe_activate_woopay();
		$_GET['wcpay-connection-success'] = '1';
		$sut->maybe_activate_woopay();

		$this->assertSame( 10, $registered_priority );
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
	 * While the plugin owns the runtime the native routes are not served, so a bookmark or a stale link to a native
	 * settings route opens the plugin's settings page: the URL WooPayments::get_settings_url() gives there, built from
	 * client 11.1.0 `includes/admin/class-wc-payments-admin-settings.php:32-36` (page, tab, section).
	 *
	 * @testdox Should send native settings routes to the plugin's settings page while the plugin owns the runtime.
	 * @dataProvider provider_native_settings_paths
	 *
	 * @param string $path Native settings route.
	 */
	public function test_sends_native_settings_routes_to_the_plugin_settings_page_while_the_plugin_owns_the_runtime( string $path ): void {
		$this->set_admin_user();
		$sut = $this->create_redirecting_controller( false, false, false, true );

		$location = $this->run_shell_redirect( $sut, $path );

		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments' ), $location );
	}

	/**
	 * Native settings routes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_native_settings_paths(): array {
		return array(
			'settings'         => array( '/woopayments/settings' ),
			'express checkout' => array( '/woopayments/settings/express-checkout/woopay' ),
			'fraud protection' => array( '/woopayments/settings/fraud-protection' ),
		);
	}

	/**
	 * @testdox Should leave $_dataName alone while the plugin owns the runtime.
	 * @dataProvider provider_requests_left_alone_on_plugin_stores
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_leaves_requests_alone_while_the_plugin_owns_the_runtime( array $request ): void {
		$this->set_admin_user();
		$sut = $this->create_redirecting_controller( false, false, false, true );

		$this->assertSame( '', $this->run_shell_redirect_for_request( $sut, $request ) );
	}

	/**
	 * Requests a plugin-owned store keeps: the plugin's own settings section (the redirect target, so no loop), other
	 * native routes, and the plugin's own WC Admin routes.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_requests_left_alone_on_plugin_stores(): array {
		return array(
			'the plugin settings section'        => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
			),
			'a native transactions route'        => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/transactions',
				),
			),
			'the plugin WC Admin settings route' => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/settings',
				),
			),
			'the plugin WC Admin deposits route' => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/deposits',
				),
			),
		);
	}

	/**
	 * @testdox Should not redirect a native settings route on a store no payments runtime owns.
	 */
	public function test_does_not_redirect_native_settings_without_a_runtime_owner(): void {
		$this->set_admin_user();
		$sut = $this->create_redirecting_controller( false, false, false, false );

		$this->assertSame( '', $this->run_shell_redirect( $sut, '/woopayments/settings' ) );
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
			'Payments settings main page'  => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'other settings tab'           => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'general',
					'path' => '/woopayments/overview',
				),
			),
			'offline payment method'       => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/offline/bacs',
				),
			),
			'other gateway section'        => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'bacs',
				),
			),
			'WC Home'                      => array(
				array(
					'page' => 'wc-admin',
				),
			),
			'wc-admin analytics'           => array(
				array(
					'page' => 'wc-admin',
					'path' => '/analytics/overview',
				),
			),
			'unknown legacy payments path' => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/unknown',
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
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::DISABLED );
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
	 * Run the onboarding redirect for an arbitrary admin request.
	 *
	 * @param WooPaymentsController $sut     Controller under test.
	 * @param array<string,string>  $request Query request.
	 * @return string The intercepted redirect location, or an empty string.
	 */
	private function run_shell_redirect_for_request( WooPaymentsController $sut, array $request ): string {
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$location = '';
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
	 * @param bool                 $plugin_owner  Whether the WooPayments plugin owns the payments runtime.
	 * @return WooPaymentsOnboardingRedirect
	 */
	private function create_onboarding_redirect( WooPaymentsApiClient $api_client, bool $valid_account, bool $native_owner, bool $plugin_owner = false ): WooPaymentsOnboardingRedirect {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_owner );
		$arbiter->method( 'is_plugin_runtime_active' )->willReturn( $plugin_owner );
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
	 * @param bool $plugin_owner  Whether the WooPayments plugin owns the payments runtime.
	 * @return WooPaymentsController&object{resolutions:int}
	 */
	private function create_redirecting_controller( bool $connected, bool $valid_account, bool $native_owner = true, bool $plugin_owner = false ): WooPaymentsController {
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		$redirect = $this->create_onboarding_redirect( $api_client, $valid_account, $native_owner, $plugin_owner );

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
		$service->init( $this->createMock( PaymentsProviders::class ), $this->createMock( LegacyProxy::class ) );

		// The service resolves its native collaborators on first use; set the doubles on the instance.
		$collaborators = array(
			'onboarding_adapter' => $onboarding_adapter,
			'legacy_runtime'     => $legacy_runtime,
			'api_client'         => $this->createMock( WooPaymentsApiClient::class ),
			'account_service'    => $this->createMock( WooPaymentsAccountService::class ),
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}

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
