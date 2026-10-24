<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments onboarding redirect: which admin requests go to onboarding, in every store state.
 */
class WooPaymentsOnboardingRedirectTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['section'], $_GET['method'], $_GET['id'] );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		remove_all_filters( 'wp_redirect' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Client 11.1.0 sends these links to `redirect_to_nox_flow()` on a store without a working connection or a valid
	 * account: the settings section (`WC_Payments_Account::maybe_redirect_from_settings_page()`, reached from payment
	 * method sections through `WC_Payments_Admin_Settings::maybe_redirect_payment_method_settings()`), Overview
	 * (`maybe_redirect_from_overview_page()`), the onboarding wizard (`maybe_redirect_from_onboarding_wizard_page()`) and
	 * the Payments child pages (`WC_Payments_Admin::maybe_redirect_from_payments_admin_child_pages()`, no tracking args).
	 * The connect page has no native equivalent; native sends it to onboarding (inbox.md N-125).
	 *
	 * @testdox Should redirect $_dataName to onboarding in the disabled state.
	 * @dataProvider provider_requests_redirected_to_onboarding
	 *
	 * @param bool                 $connected      Whether the WordPress.com connection works.
	 * @param bool                 $valid_account  Whether the account is valid for admin navigation.
	 * @param array<string,string> $request        Admin request query.
	 * @param array<string,string> $expected_query Expected onboarding tracking query args.
	 */
	public function test_redirects_to_onboarding_in_the_disabled_state( bool $connected, bool $valid_account, array $request, array $expected_query ): void {
		$this->set_disabled_builtin_store();
		$sut = $this->create_redirect( $connected, $valid_account );

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
	}

	/**
	 * WooPayments extension links on a never-connected store, WooPayments routes without a connection or with an invalid
	 * account, and the onboarding tracking args added for each.
	 *
	 * @return array<string,array{0:bool,1:bool,2:array<string,string>,3:array<string,string>}>
	 */
	public function provider_requests_redirected_to_onboarding(): array {
		$settings_from = array(
			'from'   => 'WCADMIN_PAYMENT_SETTINGS',
			'source' => 'wcadmin-settings-page',
		);

		return array(
			'the extension settings section, never connected' => array(
				false,
				false,
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
				$settings_from,
			),
			'the extension settings section with the WooPay method, never connected' => array(
				false,
				false,
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
					'method'  => 'woopay',
				),
				$settings_from,
			),
			'an extension payment method section, never connected' => array(
				false,
				false,
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments_bancontact',
				),
				$settings_from,
			),
			'the extension overview link, never connected' => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/overview',
				),
				array(
					'from'   => 'WCPAY_OVERVIEW',
					'source' => 'unknown',
				),
			),
			'the extension onboarding wizard link, never connected' => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/onboarding',
				),
				array(
					'from'   => 'WCPAY_ONBOARDING_WIZARD',
					'source' => 'unknown',
				),
			),
			'the extension connect link, never connected'  => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/connect',
				),
				array(),
			),
			'the extension transactions link, never connected' => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/transactions',
				),
				array(),
			),
			'the extension transaction details link, never connected' => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/transactions/details',
					'id'   => 'pi_123',
				),
				array(),
			),
			'the extension payouts link, never connected'  => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/payouts',
				),
				array(),
			),
			'the extension disputes link, never connected' => array(
				false,
				false,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/disputes',
				),
				array(),
			),
			'the transaction details route without a connection' => array( false, false, $this->payments_route_request( '/woopayments/transactions/details' ), array() ),
			'the overview route without a connection'      => array(
				false,
				false,
				$this->payments_route_request( '/woopayments/overview' ),
				array(
					'from'   => 'WCPAY_OVERVIEW',
					'source' => 'unknown',
				),
			),
			'the settings route without a connection'      => array( false, false, $this->payments_route_request( '/woopayments/settings' ), $settings_from ),
			'the transactions route, connected with an invalid account' => array( true, false, $this->payments_route_request( '/woopayments/transactions' ), array() ),
		);
	}

	/**
	 * @testdox Should leave $_dataName alone.
	 * @dataProvider provider_requests_left_alone
	 *
	 * @param bool                 $disabled_tier   Whether the store is in the disabled setup tier.
	 * @param bool                 $connected       Whether the WordPress.com connection works.
	 * @param bool                 $valid_account   Whether the account is valid for admin navigation.
	 * @param bool                 $builtin_owner   Whether the built-in WooPayments owns the payments runtime.
	 * @param bool                 $extension_owner Whether the WooPayments extension owns the payments runtime.
	 * @param array<string,string> $request         Admin request query.
	 */
	public function test_leaves_requests_alone( bool $disabled_tier, bool $connected, bool $valid_account, bool $builtin_owner, bool $extension_owner, array $request ): void {
		if ( $disabled_tier ) {
			$this->set_disabled_builtin_store();
		} else {
			$this->set_admin_user();
		}
		$sut = $this->create_redirect( $connected, $valid_account, $builtin_owner, $extension_owner );

		$this->assertSame( '', $this->run_shell_redirect_for_request( $sut, $request ) );
	}

	/**
	 * Requests left alone: guarded requests a connected valid account opens, the onboarding route itself, requests on a
	 * store the built-in WooPayments does not own, and, while the WooPayments extension owns the runtime, its own settings
	 * section (the redirect target, so no loop), other routes, and its own WC Admin routes.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool,4:bool,5:array<string,string>}>
	 */
	public function provider_requests_left_alone(): array {
		return array(
			'the gateway settings section, connected with a valid account in the disabled state' => array(
				true,
				true,
				true,
				true,
				false,
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
			),
			'the onboarding route without a connection in the disabled state' => array( true, false, false, true, false, $this->payments_route_request( '/woopayments/onboarding' ) ),
			'the transactions route, connected with a valid account in the disabled state' => array( true, true, true, true, false, $this->payments_route_request( '/woopayments/transactions' ) ),
			'the transactions route while the built-in WooPayments does not own the runtime' => array( true, false, false, false, false, $this->payments_route_request( '/woopayments/transactions' ) ),
			'the settings route on a store no payments runtime owns' => array( false, false, false, false, false, $this->payments_route_request( '/woopayments/settings' ) ),
			'the WooPayments extension settings section while the extension owns the runtime' => array(
				false,
				false,
				false,
				false,
				true,
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
			),
			'a transactions route while the extension owns the runtime' => array( false, false, false, false, true, $this->payments_route_request( '/woopayments/transactions' ) ),
			'the WooPayments extension WC Admin settings route while the extension owns the runtime' => array(
				false,
				false,
				false,
				false,
				true,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/settings',
				),
			),
			'the WooPayments extension WC Admin deposits route while the extension owns the runtime' => array(
				false,
				false,
				false,
				false,
				true,
				array(
					'page' => 'wc-admin',
					'path' => '/payments/deposits',
				),
			),
		);
	}

	/**
	 * While the plugin owns the runtime the native routes are not served, so a bookmark or a stale link to a native
	 * settings route opens the plugin's settings page: the URL WooPayments::get_settings_url() gives there, built from
	 * client 11.1.0 `includes/admin/class-wc-payments-admin-settings.php:32-36` (page, tab, section).
	 *
	 * @testdox Should send settings routes to the WooPayments extension's settings page while it owns the runtime.
	 * @dataProvider provider_settings_paths
	 *
	 * @param string $path Native settings route.
	 */
	public function test_sends_settings_routes_to_the_woopayments_extension_settings_page_while_the_extension_owns_the_runtime( string $path ): void {
		$this->set_admin_user();
		$sut = $this->create_redirect( false, false, false, true );

		$location = $this->run_shell_redirect_for_request( $sut, $this->payments_route_request( $path ) );

		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments' ), $location );
	}

	/**
	 * Settings routes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_settings_paths(): array {
		return array(
			'settings'         => array( '/woopayments/settings' ),
			'express checkout' => array( '/woopayments/settings/express-checkout/woopay' ),
			'fraud protection' => array( '/woopayments/settings/fraud-protection' ),
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
	 * Mark the store as one the built-in WooPayments owns, in the disabled state, where the admin navigation controller is not loaded.
	 */
	private function set_disabled_builtin_store(): void {
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
	 * Build the Payments settings request for a WooPayments route path.
	 *
	 * @param string $path WooPayments route path.
	 * @return array<string,string>
	 */
	private function payments_route_request( string $path ): array {
		return array(
			'page' => 'wc-settings',
			'tab'  => 'checkout',
			'path' => $path,
		);
	}

	/**
	 * Run the onboarding redirect for an arbitrary admin request.
	 *
	 * @param WooPaymentsOnboardingRedirect $sut     Redirect under test.
	 * @param array<string,string>          $request Query request.
	 * @return string The intercepted redirect location, or an empty string.
	 */
	private function run_shell_redirect_for_request( WooPaymentsOnboardingRedirect $sut, array $request ): string {
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
			$sut->maybe_redirect();
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted', $exception->getMessage() );
		}

		return $location;
	}

	/**
	 * Create the shared onboarding redirect with test doubles.
	 *
	 * @param WooPaymentsApiClient $api_client      API client double.
	 * @param bool                 $valid_account   Whether the account is valid for admin navigation.
	 * @param bool                 $builtin_owner   Whether the built-in WooPayments owns the payments runtime.
	 * @param bool                 $extension_owner Whether the WooPayments extension owns the payments runtime.
	 * @return WooPaymentsOnboardingRedirect
	 */
	private function create_onboarding_redirect( WooPaymentsApiClient $api_client, bool $valid_account, bool $builtin_owner, bool $extension_owner = false ): WooPaymentsOnboardingRedirect {
		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owner );
		$arbiter->method( 'is_extension_owner' )->willReturn( $extension_owner );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->method( 'has_valid_account_for_admin_navigation' )->willReturn( $valid_account );

		$redirect = new WooPaymentsOnboardingRedirect();
		$redirect->init( $arbiter, $api_client, $account_service );

		return $redirect;
	}

	/**
	 * Create the onboarding redirect for a store state.
	 *
	 * @param bool $connected       Whether the WordPress.com connection works.
	 * @param bool $valid_account   Whether the account is valid for admin navigation.
	 * @param bool $builtin_owner   Whether the built-in WooPayments owns the payments runtime.
	 * @param bool $extension_owner Whether the WooPayments extension owns the payments runtime.
	 * @return WooPaymentsOnboardingRedirect
	 */
	private function create_redirect( bool $connected, bool $valid_account, bool $builtin_owner = true, bool $extension_owner = false ): WooPaymentsOnboardingRedirect {
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );

		return $this->create_onboarding_redirect( $api_client, $valid_account, $builtin_owner, $extension_owner );
	}
}
