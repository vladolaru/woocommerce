<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAdminNavigationController class.
 */
class WooPaymentsAdminNavigationControllerTest extends WC_Unit_Test_Case {

	/**
	 * Intercepted redirect location.
	 *
	 * @var string
	 */
	private string $intercepted_redirect = '';

	/**
	 * Original WooCommerce payment gateway collection.
	 *
	 * @var array<int|string,mixed>
	 */
	private array $original_payment_gateways;

	/**
	 * Onboarding redirects of the controllers created by the test, keyed by the controller's object ID.
	 *
	 * @var array<int,WooPaymentsOnboardingRedirect>
	 */
	private array $onboarding_redirects = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $submenu;

		foreach ( $this->onboarding_redirects as $onboarding_redirect ) {
			remove_action( 'admin_init', array( $onboarding_redirect, 'maybe_redirect' ), 16 );
		}
		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate admin menu assertions in this test.
		$submenu = array();
		wp_set_current_user( 0 );
		$this->intercepted_redirect = '';
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'woocommerce_multi_currency_js_settings' );
		remove_all_filters( 'wcpay_js_settings' );
		unset( $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['from'], $_SERVER['HTTP_REFERER'] );
		delete_option( 'wcpay_account_data' );
		delete_option( 'wcpay_onboarding_test_mode' );
		delete_option( 'wcpay_next_deposit_notice_dismissed' );
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country' ) as $store_option ) {
			delete_option( $store_option );
		}
		$this->reset_container_replacements();

		parent::tearDown();
	}

	/**
	 * @testdox Should register the WooPayments navigation after the Core Payments menu exists.
	 */
	public function test_registers_admin_menu_hook_after_core_payments_menu(): void {
		$sut = $this->create_controller( true );

		$sut->register();

		$this->assertSame( 70, has_action( 'admin_menu', array( $sut, 'add_menu_items' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $sut, 'redirect_payments_paths' ) ) );
		$this->assertSame( 10, has_filter( 'submenu_file', array( $sut, 'highlight_current_payments_submenu' ) ) );
		$this->assertSame( 10, has_action( 'adminmenu', array( $sut, 'open_payments_menu' ) ) );
		$this->assertSame( 10, has_filter( 'admin_title', array( $sut, 'set_admin_title' ) ) );

		remove_filter( 'submenu_file', array( $sut, 'highlight_current_payments_submenu' ) );
		remove_action( 'adminmenu', array( $sut, 'open_payments_menu' ) );
		remove_action( 'admin_menu', array( $sut, 'add_menu_items' ), 70 );
		remove_action( 'admin_init', array( $sut, 'redirect_payments_paths' ), 10 );
	}

	/**
	 * @testdox Should preload the admin route availability of the $_dataName account state.
	 * @dataProvider provider_admin_route_availability
	 *
	 * @param array<string,bool> $account_state   Account state overrides.
	 * @param bool|null          $gateway_enabled Expected gateway state, or null for a row that does not check it.
	 * @param string|null        $expected_state  Expected account state, or null for a row that does not check it.
	 * @param string[]           $allowed         Routes that must be allowed.
	 * @param string[]           $denied          Routes that must be denied.
	 */
	public function test_preloads_admin_route_availability_by_account_state( array $account_state, ?bool $gateway_enabled, ?string $expected_state, array $allowed, array $denied ): void {
		$availability = $this->preload_route_availability( $this->create_controller( true, $account_state ) );
		$routes       = $availability['allowedRoutes'];

		if ( null !== $gateway_enabled ) {
			$this->assertSame( $gateway_enabled, $availability['gatewayEnabled'] );
		}
		if ( null !== $expected_state ) {
			$this->assertSame( $expected_state, $availability['accountState'] );
		}
		foreach ( $allowed as $route ) {
			$this->assertTrue( $routes[ $route ], "{$route} should be allowed." );
		}
		foreach ( $denied as $route ) {
			$this->assertFalse( $routes[ $route ], "{$route} should be denied." );
		}
	}

	/**
	 * Account states and the admin routes each one allows and denies.
	 *
	 * @return array<string,array{0:array<string,bool>,1:bool|null,2:string|null,3:string[],4:string[]}>
	 */
	public function provider_admin_route_availability(): array {
		$optional_on = array(
			'is_reports_enabled'         => true,
			'is_documents_enabled'       => true,
			'is_card_present_eligible'   => true,
			'has_card_readers_available' => true,
			'has_previous_capital_loans' => true,
		);
		$all_routes  = array(
			'/woopayments/settings',
			'/woopayments/overview',
			'/woopayments/payouts',
			'/woopayments/payouts/details',
			'/woopayments/transactions',
			'/woopayments/transactions/details',
			'/woopayments/disputes',
			'/woopayments/disputes/details',
			'/woopayments/disputes/challenge',
			'/woopayments/reports',
			'/woopayments/card-readers',
			'/woopayments/loans',
			'/woopayments/documents',
			'/woopayments/settings/fraud-protection',
		);
		$reduced     = array(
			'/woopayments/settings',
			'/woopayments/overview',
			'/woopayments/transactions',
			'/woopayments/transactions/details',
			'/woopayments/disputes',
			'/woopayments/disputes/details',
			'/woopayments/disputes/challenge',
			'/woopayments/settings/fraud-protection',
		);
		$not_reduced = array(
			'/woopayments/onboarding',
			'/woopayments/payouts',
			'/woopayments/payouts/details',
			'/woopayments/reports',
			'/woopayments/card-readers',
			'/woopayments/loans',
			'/woopayments/documents',
		);
		$onboarding  = array( '/woopayments/settings', '/woopayments/onboarding', '/woopayments/settings/fraud-protection' );
		$not_ready   = array(
			'/woopayments/overview',
			'/woopayments/payouts',
			'/woopayments/transactions',
			'/woopayments/disputes',
			'/woopayments/reports',
			'/woopayments/card-readers',
			'/woopayments/loans',
			'/woopayments/documents',
		);

		return array(
			'full'             => array( $optional_on, true, 'full', $all_routes, array() ),
			'rejected'         => array( array_merge( array( 'is_account_rejected' => true ), $optional_on ), true, 'restricted', $reduced, $not_reduced ),
			'under review'     => array( array_merge( array( 'is_account_under_review' => true ), $optional_on ), true, 'restricted', $reduced, $not_reduced ),
			'gateway disabled' => array(
				array_merge(
					array(
						'is_gateway_enabled' => false,
						'has_valid_account_for_admin_navigation' => true,
					),
					$optional_on
				),
				false,
				'full',
				$all_routes,
				array( '/woopayments/onboarding' ),
			),
			'not connected'    => array(
				array_merge(
					array(
						'has_account'                   => false,
						'is_details_submitted'          => false,
						'has_valid_admin_account_state' => false,
					),
					$optional_on
				),
				true,
				'onboarding',
				$onboarding,
				$not_ready,
			),
			'details missing'  => array(
				array_merge(
					array(
						'has_account'                   => true,
						'is_details_submitted'          => false,
						'has_valid_admin_account_state' => false,
					),
					$optional_on
				),
				true,
				'onboarding',
				$onboarding,
				$not_ready,
			),
			'optional off'     => array(
				array(),
				null,
				null,
				array( '/woopayments/settings', '/woopayments/overview' ),
				array( '/woopayments/reports', '/woopayments/card-readers', '/woopayments/loans', '/woopayments/documents' ),
			),
		);
	}

	/**
	 * @testdox Should map WooPayments extension WC Admin transaction URLs to Settings Payments routes.
	 */
	public function test_maps_payments_transaction_details_path_to_route(): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page'           => 'wc-admin',
				'path'           => '%2Fpayments%2Ftransactions%2Fdetails',
				'id'             => 'pi_native',
				'transaction_id' => 'txn_native',
				'type'           => 'dispute',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/transactions/details', $url );
		$this->assertStringContainsString( 'id=pi_native', $url );
		$this->assertStringContainsString( 'transaction_id=txn_native', $url );
		$this->assertStringContainsString( 'type=dispute', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should open the Transactions view that matches a WooPayments extension Transactions tab link.
	 *
	 * Client 11.1.0 `client/transactions/index.tsx:32-43, :77-97` writes the open tab to the URL as
	 * `tab=transactions-page|uncaptured-page|blocked-page`; native reads `view=uncaptured|blocked`.
	 *
	 * @dataProvider provider_payments_transactions_tabs
	 *
	 * @param string      $transactions_tab The WooPayments extension's Transactions tab name.
	 * @param string|null $expected_view    Native Transactions view, or null for the default list.
	 */
	public function test_maps_payments_transactions_tab_path_to_view( string $transactions_tab, ?string $expected_view ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '/payments/transactions',
				'tab'  => $transactions_tab,
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( '/woopayments/transactions', $query['path'] );
		$this->assertSame( $expected_view, $query['view'] ?? null );
	}

	/**
	 * The WooPayments extension's Transactions tab names and the view each one opens.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public function provider_payments_transactions_tabs(): array {
		return array(
			'uncaptured' => array( 'uncaptured-page', 'uncaptured' ),
			'blocked'    => array( 'blocked-page', 'blocked' ),
			'all'        => array( 'transactions-page', null ),
			'unknown'    => array( 'review-page', null ),
		);
	}

	/**
	 * @testdox Should redirect every WooPayments extension WC Admin route to a route.
	 *
	 * Source: Fixtures/woopayments-admin-routes.json (client/index.js:145-361, includes/admin/class-wc-payments-admin.php:230-611).
	 *
	 * @dataProvider provider_payments_paths
	 *
	 * @param string $payments_path                WooPayments extension WC Admin route path.
	 * @param string $woopayments_extension_source Where the WooPayments extension defines the route (a citation).
	 */
	public function test_redirects_every_payments_path_to_a_route( string $payments_path, string $woopayments_extension_source ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( $payments_path ),
			)
		);

		$this->assertNotSame( '', $url, "Client route {$payments_path} ({$woopayments_extension_source}) has no native redirect." );
		$this->assertStringContainsString( 'path=/woopayments/', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * The WooPayments extension's WC Admin routes, read from the pinned fixture.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_payments_paths(): array {
		$path = dirname( __DIR__ ) . '/Fixtures/woopayments-admin-routes.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$fixture = json_decode( (string) file_get_contents( $path ), true );

		$routes = array();
		foreach ( $fixture['admin_routes'] as $route ) {
			$routes[ $route['path'] ] = array( $route['path'], $route['plugin_site'] );
		}

		return $routes;
	}

	/**
	 * @testdox Should pin all 20 WooPayments extension WC Admin routes in the fixture.
	 */
	public function test_admin_routes_fixture_holds_every_payments_path(): void {
		$routes = $this->provider_payments_paths();

		$this->assertCount( 20, $routes );
		$this->assertArrayNotHasKey( '/payments/accounts', $routes );
	}

	/**
	 * @testdox Should map the WooPayments extension setup URL $payments_path for $_dataName to $expected_route.
	 *
	 * @dataProvider provider_payments_setup_redirects
	 *
	 * @param string              $payments_path  WooPayments extension WC Admin route path.
	 * @param array<string,mixed> $account_state  Account state overrides.
	 * @param string              $expected_route Expected route path.
	 */
	public function test_maps_payments_setup_paths_by_account( string $payments_path, array $account_state, string $expected_route ): void {
		$sut = $this->create_controller( true, $account_state );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page'   => 'wc-admin',
				'path'   => rawurlencode( $payments_path ),
				'source' => 'legacy-bookmark',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=' . $expected_route, $url );
		$this->assertStringContainsString( 'source=legacy-bookmark', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * Setup paths, by account, and the route each one opens.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public function provider_payments_setup_redirects(): array {
		$no_account = array(
			'has_account'                            => false,
			'has_valid_account_for_admin_navigation' => false,
			'is_details_submitted'                   => false,
		);

		return array(
			'connect, no account'               => array( '/payments/connect', $no_account, '/woopayments/onboarding' ),
			'onboarding, no account'            => array( '/payments/onboarding', $no_account, '/woopayments/onboarding' ),
			'onboarding kyc, no account'        => array( '/payments/onboarding/kyc', $no_account, '/woopayments/onboarding' ),
			'connect, connected account'        => array( '/payments/connect', array(), '/woopayments/overview' ),
			'onboarding, connected account'     => array( '/payments/onboarding', array(), '/woopayments/overview' ),
			'onboarding kyc, connected account' => array( '/payments/onboarding/kyc', array(), '/woopayments/overview' ),
		);
	}

	/**
	 * @testdox Should map WooPayments extension settings URLs to provider settings routes.
	 *
	 * @dataProvider provider_gateway_settings_section_redirects
	 *
	 * @param string               $payments_path     WooPayments extension WC Admin route path.
	 * @param string               $expected_route    Expected route path.
	 * @param array<string,string> $expected_query    Expected query values.
	 * @param string|null          $expected_fragment Expected URL fragment.
	 */
	public function test_maps_gateway_settings_section_urls_to_provider_settings_routes( string $payments_path, string $expected_route, array $expected_query = array(), ?string $expected_fragment = null ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page'         => 'wc-admin',
				'path'         => rawurlencode( $payments_path ),
				'tab'          => 'legacy-tab',
				'existing_arg' => 'keep-me',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( $expected_route, $query['path'] );
		$this->assertSame( 'keep-me', $query['existing_arg'] );
		foreach ( $expected_query as $key => $value ) {
			$this->assertSame( $value, $query[ $key ] );
		}
		if ( null === $expected_fragment ) {
			$this->assertNull( $fragment );
		} else {
			$this->assertSame( $expected_fragment, $fragment );
			$this->assertArrayNotHasKey( 'section', $query );
		}
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @return array<string,array{legacy_path:string,native_path:string,expected_query?:array<string,string>,expected_fragment?:string}>
	 */
	public function provider_gateway_settings_section_redirects(): array {
		return array(
			'fraud protection'           => array(
				'payments_path'  => '/payments/fraud-protection',
				'expected_route' => '/woopayments/settings/fraud-protection',
			),
			'multi currency setup'       => array(
				'payments_path'     => '/payments/multi-currency-setup',
				'expected_route'    => '/woopayments/settings',
				'expected_query'    => array(),
				'expected_fragment' => 'advanced',
			),
			'additional payment methods' => array(
				'payments_path'     => '/payments/additional-payment-methods',
				'expected_route'    => '/woopayments/settings',
				'expected_query'    => array(),
				'expected_fragment' => 'payment-methods',
			),
		);
	}

	/**
	 * @testdox Should map the WooPayments extension WC Admin URL for $_dataName to the $expected_route route.
	 * @dataProvider provider_payments_paths_by_account_gate
	 *
	 * @param array<string,mixed>  $account_state  Account state overrides.
	 * @param array<string,string> $request        WC Admin query request.
	 * @param string               $expected_route Expected route path.
	 * @param string[]             $url_parts      Further strings the redirect URL must contain.
	 * @param array<string,string> $expected_query Further query values the redirect URL must carry.
	 */
	public function test_maps_payments_paths_by_account_gate( array $account_state, array $request, string $expected_route, array $url_parts, array $expected_query ): void {
		$sut = $this->create_controller( true, $account_state );

		$url = $sut->get_payments_path_redirect_url( $request );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=' . $expected_route, $url );
		$this->assertSame( $expected_route, $query['path'] );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
		foreach ( $url_parts as $url_part ) {
			$this->assertStringContainsString( $url_part, $url );
		}
		foreach ( $expected_query as $key => $value ) {
			$this->assertSame( $value, $query[ $key ] );
		}
	}

	/**
	 * WooPayments extension WC Admin paths, by the account gate that decides their route.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>,2:string,3:string[],4:array<string,string>}>
	 */
	public function provider_payments_paths_by_account_gate(): array {
		$under_review = array( 'is_account_under_review' => true );
		$no_account   = array(
			'has_account'                            => false,
			'has_valid_account_for_admin_navigation' => false,
			'is_details_submitted'                   => false,
		);

		return array(
			'documents, enabled'             => array(
				array( 'is_documents_enabled' => true ),
				array(
					'page'          => 'wc-admin',
					'path'          => '%2Fpayments%2Fdocuments',
					'document_id'   => 'doc_native',
					'document_type' => 'vat_invoice',
				),
				'/woopayments/documents',
				array( 'document_id=doc_native', 'document_type=vat_invoice' ),
				array(),
			),
			'documents, disabled'            => array( array( 'is_documents_enabled' => false ), $this->wc_admin_request( '%2Fpayments%2Fdocuments' ), '/woopayments/overview', array(), array() ),
			'reports, enabled'               => array(
				array( 'is_reports_enabled' => true ),
				array(
					'page'     => 'wc-admin',
					'path'     => '%2Fpayments%2Freports',
					'tab'      => 'fees',
					'view'     => 'fees',
					'currency' => 'USD',
				),
				'/woopayments/reports',
				array( 'currency=USD', 'view=fees' ),
				array(
					'tab'        => 'checkout',
					'report_tab' => 'fees',
				),
			),
			'reports, disabled'              => array( array( 'is_reports_enabled' => false ), $this->wc_admin_request( '%2Fpayments%2Freports' ), '/woopayments/overview', array(), array() ),
			'deposits, account under review' => array( $under_review, $this->wc_admin_request( '%2Fpayments%2Fdeposits' ), '/woopayments/overview', array(), array() ),
			'payouts, account under review'  => array( $under_review, $this->wc_admin_request( '%2Fpayments%2Fpayouts' ), '/woopayments/overview', array(), array() ),
			'transactions, no account'       => array( $no_account, $this->wc_admin_request( '%2Fpayments%2Ftransactions' ), '/woopayments/settings', array(), array() ),
			'card readers, available'        => array(
				array(
					'is_card_present_eligible'   => true,
					'has_card_readers_available' => true,
				),
				$this->wc_admin_request( '%2Fpayments%2Fcard-readers' ),
				'/woopayments/card-readers',
				array(),
				array(),
			),
			'card readers, unavailable'      => array(
				array(
					'is_card_present_eligible'   => true,
					'has_card_readers_available' => false,
				),
				$this->wc_admin_request( '%2Fpayments%2Fcard-readers' ),
				'/woopayments/overview',
				array(),
				array(),
			),
			'capital loans, available'       => array( array( 'has_previous_capital_loans' => true ), $this->wc_admin_request( '%2Fpayments%2Floans' ), '/woopayments/loans', array(), array() ),
			'capital loans, none'            => array( array( 'has_previous_capital_loans' => false ), $this->wc_admin_request( '%2Fpayments%2Floans' ), '/woopayments/overview', array(), array() ),
			'settings, gateway disabled'     => array( array( 'is_gateway_enabled' => false ), $this->wc_admin_request( '%2Fpayments%2Fsettings' ), '/woopayments/settings', array(), array() ),
			'transactions, gateway disabled' => array( array( 'is_gateway_enabled' => false ), $this->wc_admin_request( '%2Fpayments%2Ftransactions' ), '/woopayments/transactions', array(), array() ),
		);
	}

	/**
	 * Build a WC Admin request for a path.
	 *
	 * @param string $path Encoded WC Admin path.
	 * @return array<string,string>
	 */
	private function wc_admin_request( string $path ): array {
		return array(
			'page' => 'wc-admin',
			'path' => $path,
		);
	}

	/**
	 * @testdox Should map unknown WooPayments extension method sections to settings.
	 */
	public function test_maps_unknown_woopayments_method_section_to_settings(): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'woocommerce_payments_sofort',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/settings', $query['path'] );
	}

	/**
	 * @testdox Should redirect a registered split payment method section to settings.
	 */
	public function test_redirects_registered_split_method_section_to_settings(): void {
		$sut                                       = $this->create_controller( true );
		WC()->payment_gateways()->payment_gateways = array( 0 => $this->create_gateway( 'woocommerce_payments_klarna' ) );

		$url = $sut->get_payments_path_redirect_url(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'woocommerce_payments_klarna',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertArrayHasKey( 'woocommerce_payments_klarna', WC()->payment_gateways()->payment_gateways() );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/settings', $query['path'] ?? null );
	}

	/**
	 * @testdox Should not redirect WooPayments extension settings URLs during AJAX requests.
	 */
	public function test_does_not_redirect_payments_paths_during_ajax_requests(): void {
		$sut = $this->create_controller( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'woocommerce_payments_sofort',
		);
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			$sut->redirect_payments_paths();
			$this->assertTrue( wp_doing_ajax() );
		} finally {
			remove_all_filters( 'wp_doing_ajax' );
			remove_all_filters( 'wp_redirect' );
			$_GET = array();
		}
	}

	/**
	 * @testdox Should not redirect unrelated WC Admin paths.
	 */
	public function test_does_not_map_unrelated_wc_admin_paths(): void {
		$sut = $this->create_controller( true );

		$this->assertSame(
			'',
			$sut->get_payments_path_redirect_url(
				array(
					'page' => 'wc-admin',
					'path' => '/analytics/revenue',
				)
			)
		);
	}

	/**
	 * @testdox Should build the WooPayments navigation for $_dataName.
	 * @dataProvider provider_menu_items_by_account_state
	 *
	 * @param bool                $builtin_owner   Whether the built-in WooPayments owns payments.
	 * @param string              $role            Current user role.
	 * @param array<string,mixed> $account_state   Account state overrides.
	 * @param string[]            $expected_titles Expected menu item titles, in order.
	 */
	public function test_adds_menu_items_by_account_state( bool $builtin_owner, string $role, array $account_state, array $expected_titles ): void {
		$routes = array(
			'Overview'            => '/woopayments/overview',
			'Payouts'             => '/woopayments/payouts',
			'Transactions'        => '/woopayments/transactions',
			'Disputes'            => '/woopayments/disputes',
			'Reports'             => '/woopayments/reports',
			'Card Readers'        => '/woopayments/card-readers',
			'Capital Loans'       => '/woopayments/loans',
			'Documents'           => '/woopayments/documents',
			'Settings'            => '/woopayments/settings',
			'Onboarding'          => '/woopayments/onboarding',
			'Continue onboarding' => '/woopayments/onboarding',
		);
		wp_set_current_user( $this->factory->user->create( array( 'role' => $role ) ) );

		$sut = $this->create_controller( $builtin_owner, $account_state );
		$sut->add_menu_items();

		$items = $this->get_payments_submenu_items();

		$this->assertSame( $expected_titles, array_column( $items, 0 ) );
		$this->assertSame(
			array_map(
				fn( string $title ): string => $this->get_settings_url( $routes[ $title ] ),
				$expected_titles
			),
			array_column( $items, 2 )
		);
		$this->assertStringNotContainsString( 'wc-admin&path=/payments', implode( "\n", array_column( $items, 2 ) ) );
	}

	/**
	 * Owners, users and account states, and the WooPayments navigation each one gets.
	 *
	 * @return array<string,array{0:bool,1:string,2:array<string,mixed>,3:string[]}>
	 */
	public function provider_menu_items_by_account_state(): array {
		return array(
			'an extension-owned store'                => array( false, 'administrator', array(), array() ),
			'a user without manage_woocommerce'       => array( true, 'customer', array(), array() ),
			'a disabled gateway'                      => array( true, 'administrator', array( 'is_gateway_enabled' => false ), array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Settings' ) ),
			'a valid account with all optional pages' => array(
				true,
				'administrator',
				array(
					'is_card_present_eligible'      => true,
					'has_card_readers_available'    => true,
					'has_previous_capital_loans'    => true,
					'is_documents_enabled'          => false,
					'has_valid_admin_account_state' => true,
				),
				array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Card Readers', 'Capital Loans', 'Settings' ),
			),
			'Documents not eligible'                  => array( true, 'administrator', array( 'is_documents_enabled' => false ), array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Settings' ) ),
			'Documents eligible'                      => array( true, 'administrator', array( 'is_documents_enabled' => true ), array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Documents', 'Settings' ) ),
			'Reports enabled'                         => array( true, 'administrator', array( 'is_reports_enabled' => true ), array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Reports', 'Settings' ) ),
			'a rejected account'                      => array( true, 'administrator', array( 'is_account_rejected' => true ), array( 'Overview', 'Transactions', 'Disputes' ) ),
			'an under-review account'                 => array( true, 'administrator', array( 'is_account_under_review' => true ), array( 'Overview', 'Transactions', 'Disputes' ) ),
			'a store that is not connected'           => array(
				true,
				'administrator',
				array(
					'has_account'                   => false,
					'is_details_submitted'          => false,
					'has_valid_admin_account_state' => false,
				),
				array( 'Onboarding' ),
			),
			'an account with missing details'         => array(
				true,
				'administrator',
				array(
					'has_account'                   => true,
					'is_details_submitted'          => false,
					'has_valid_admin_account_state' => false,
				),
				array( 'Continue onboarding' ),
			),
			'a submitted account that cannot open the pages' => array( true, 'administrator', array( 'has_valid_admin_account_state' => false ), array() ),
		);
	}

	/**
	 * @testdox Should offer only onboarding when the WordPress.com connection is broken, even for a valid cached account.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:383-394`: the full menu needs `has_working_jetpack_connection()`
	 * and a valid Stripe account; otherwise the menu links to the connect page.
	 */
	public function test_offers_only_onboarding_without_a_working_connection(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true, array( 'has_valid_admin_account_state' => true ), array(), false );
		$sut->add_menu_items();

		$this->assertSame(
			array( 'Onboarding' ),
			array_map(
				static function ( array $item ): string {
					return wp_strip_all_tags( $item[0] );
				},
				$this->get_payments_submenu_items()
			)
		);
		$availability = $this->preload_route_availability( $sut );
		$this->assertSame( 'onboarding', $availability['accountState'] );
		$this->assertTrue( $availability['allowedRoutes']['/woopayments/onboarding'] );
		$this->assertFalse( $availability['allowedRoutes']['/woopayments/overview'] );
		$this->assertFalse( $availability['allowedRoutes']['/woopayments/payouts'] );
	}

	/**
	 * Client 11.1.0 registers its pages under the Payments menu (`class-wc-payments-admin.php:230-611`), so WC Admin
	 * opens Payments and marks the current page's item, detail pages marking their list.
	 *
	 * @testdox Should open the Payments menu and mark the $expected_title item on $path.
	 * @dataProvider provider_current_payments_menu_items
	 *
	 * @param string $path           Native WooPayments route path.
	 * @param string $expected_title Menu item that should be current.
	 */
	public function test_highlights_the_current_payments_menu_item( string $path, string $expected_title ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$sut = $this->create_controller( true, array(), array( 'disputes_awaiting_response' => 3 ) );
		$sut->add_menu_items();
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;

		$this->assertSame( $this->get_submenu_item_by_title( $expected_title )[2], $sut->highlight_current_payments_submenu( null ) );
		$this->assertStringStartsWith( '<script', $this->get_open_payments_menu_output( $sut ) );
	}

	/**
	 * Print what the controller adds after the admin menu.
	 *
	 * @param WooPaymentsAdminNavigationController $sut Controller under test.
	 * @return string
	 */
	private function get_open_payments_menu_output( WooPaymentsAdminNavigationController $sut ): string {
		ob_start();
		$sut->open_payments_menu();

		return (string) ob_get_clean();
	}

	/**
	 * Native routes and the menu item the client marks current for each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_current_payments_menu_items(): array {
		return array(
			'overview'            => array( '/woopayments/overview', 'Overview' ),
			'payouts'             => array( '/woopayments/payouts', 'Payouts' ),
			'payout details'      => array( '/woopayments/payouts/details', 'Payouts' ),
			'transactions'        => array( '/woopayments/transactions', 'Transactions' ),
			'transaction details' => array( '/woopayments/transactions/details', 'Transactions' ),
			'disputes (badge)'    => array( '/woopayments/disputes', 'Disputes' ),
			'dispute challenge'   => array( '/woopayments/disputes/challenge', 'Disputes' ),
		);
	}

	/**
	 * @testdox Should leave the admin menu highlight alone outside the WooPayments pages.
	 * @dataProvider provider_requests_outside_woopayments_pages
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_change_the_menu_highlight_outside_woopayments_pages( array $request ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$sut = $this->create_controller( true );
		$sut->add_menu_items();
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		$this->assertSame( 'wc-settings', $sut->highlight_current_payments_submenu( 'wc-settings' ) );
		$this->assertSame( '', $this->get_open_payments_menu_output( $sut ) );
	}

	/**
	 * Requests that are not WooPayments pages.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_requests_outside_woopayments_pages(): array {
		return array(
			'Payments providers list' => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'offline payment method'  => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/offline/bacs',
				),
			),
			'look-alike route'        => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/transactionsx',
				),
			),
			'other settings tab'      => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'general',
					'path' => '/woopayments/transactions',
				),
			),
			// Client 11.1.0 settings is a WooCommerce > Settings section (checked on :8082), so WooCommerce stays open.
			'WooPayments settings'    => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/settings',
				),
			),
			'fraud protection'        => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/settings/fraud-protection',
				),
			),
		);
	}

	/**
	 * Client 11.1.0 names its pages through WooCommerce admin's header (`%1$s &lsaquo; %2$s &#8212; WooCommerce`) and registers
	 * each detail page with its own title (`includes/admin/class-wc-payments-admin.php:567-593`).
	 *
	 * @testdox Should title $path as "$expected_page" in the browser tab on first paint.
	 * @dataProvider provider_woopayments_page_titles
	 *
	 * @param string $path          Native WooPayments route path.
	 * @param string $expected_page Expected page section of the title.
	 */
	public function test_sets_the_admin_title_for_woopayments_pages( string $path, string $expected_page ): void {
		update_option( 'blogname', 'Shop & Co' );
		$sut          = $this->create_controller( true );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;

		$this->assertSame( "{$expected_page} &lsaquo; Payments &lsaquo; Shop &amp; Co &#8212; WooCommerce", $sut->set_admin_title( 'WooCommerce settings' ) );
	}

	/**
	 * WooPayments routes and the page section of their first-paint title.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_woopayments_page_titles(): array {
		return array(
			'overview'            => array( '/woopayments/overview', 'Overview' ),
			'payout details'      => array( '/woopayments/payouts/details', 'Payout details &lsaquo; Payouts' ),
			'transaction details' => array( '/woopayments/transactions/details', 'Payment details &lsaquo; Transactions' ),
			'dispute details'     => array( '/woopayments/disputes/details', 'Dispute details &lsaquo; Disputes' ),
			'dispute challenge'   => array( '/woopayments/disputes/challenge', 'Challenge dispute &lsaquo; Disputes' ),
			'card readers'        => array( '/woopayments/card-readers', 'Card Readers' ),
		);
	}

	/**
	 * @testdox Should leave the admin title alone outside the WooPayments pages.
	 * @dataProvider provider_requests_outside_woopayments_pages
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_change_the_admin_title_outside_woopayments_pages( array $request ): void {
		$sut = $this->create_controller( true );
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		$this->assertSame( 'WooCommerce settings', $sut->set_admin_title( 'WooCommerce settings' ) );
	}

	/**
	 * @testdox Should show the $title menu item's badge and link for $_dataName.
	 * @dataProvider provider_menu_badges
	 *
	 * @param string            $title        Menu item title.
	 * @param array<string,int> $badge_counts Badge count overrides.
	 * @param int|null          $badge        Expected badge count, or null for a plain title.
	 * @param string|null       $exact_route  Route the link must equal exactly, or null for a row that checks parts of it.
	 * @param string[]          $url_parts    Strings the link must contain.
	 */
	public function test_adds_menu_badges( string $title, array $badge_counts, ?int $badge, ?string $exact_route, array $url_parts ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true, array(), $badge_counts );
		$sut->add_menu_items();

		$item = $this->get_submenu_item_by_title( $title );

		if ( null === $badge ) {
			$this->assertSame( $title, $item[0] );
		} else {
			$this->assertStringContainsString( "wcpay-menu-badge awaiting-mod count-{$badge}", $item[0] );
			$this->assertStringContainsString( "<span class=\"plugin-count\">{$badge}</span>", $item[0] );
		}
		if ( null !== $exact_route ) {
			$this->assertSame( $this->get_settings_url( $exact_route ), $item[2] );
		}
		foreach ( $url_parts as $url_part ) {
			$this->assertStringContainsString( $url_part, $item[2] );
		}
	}

	/**
	 * Badge counts and the Disputes and Transactions menu items they give.
	 *
	 * @return array<string,array{0:string,1:array<string,int>,2:int|null,3:string|null,4:string[]}>
	 */
	public function provider_menu_badges(): array {
		return array(
			'4 disputes awaiting a response' => array( 'Disputes', array( 'disputes_awaiting_response' => 4 ), 4, null, array( 'path=/woopayments/disputes', 'filter=awaiting_response' ) ),
			'no dispute awaiting a response' => array( 'Disputes', array(), null, '/woopayments/disputes', array() ),
			'3 uncaptured transactions'      => array( 'Transactions', array( 'uncaptured_transactions' => 3 ), 3, '/woopayments/transactions', array() ),
			'no uncaptured transaction'      => array( 'Transactions', array(), null, '/woopayments/transactions', array() ),
		);
	}

	/**
	 * @testdox Should register the onboarding redirect after the WooPayments extension's account redirects.
	 */
	public function test_registers_onboarding_redirect_at_the_woopayments_extension_admin_init_priority(): void {
		$sut = $this->create_controller( true );

		$sut->register();

		// Client 11.1.0 WC_Payments_Admin::init_hooks() hooks its child-page redirect on admin_init priority 16.
		$this->assertSame( 16, has_action( 'admin_init', array( $this->onboarding_redirects[ spl_object_id( $sut ) ], 'maybe_redirect' ) ) );

		remove_action( 'admin_menu', array( $sut, 'add_menu_items' ), 70 );
		remove_action( 'admin_init', array( $sut, 'redirect_payments_paths' ), 10 );
	}

	/**
	 * @testdox Should redirect WooPayments admin pages to onboarding when there is no account.
	 * @dataProvider provider_onboarding_redirect_paths
	 *
	 * @param string               $path           Requested native WooPayments route path.
	 * @param array<string,string> $expected_query Expected onboarding tracking query args.
	 */
	public function test_redirects_admin_pages_to_onboarding_without_an_account( string $path, array $expected_query ): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state() );

		$url = $this->run_onboarding_redirect( $sut, $path );

		$this->assert_onboarding_redirect( $url, $expected_query );
	}

	/**
	 * Native WooPayments admin paths that need a working connection and a valid account.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public function provider_onboarding_redirect_paths(): array {
		$settings_query = array(
			'from'   => 'WCADMIN_PAYMENT_SETTINGS',
			'source' => 'wcadmin-settings-page',
		);

		return array(
			'overview'                  => array(
				'/woopayments/overview',
				array(
					'from'   => 'WCPAY_OVERVIEW',
					'source' => 'unknown',
				),
			),
			'payouts'                   => array( '/woopayments/payouts', array() ),
			'payout details'            => array( '/woopayments/payouts/details', array() ),
			'transactions'              => array( '/woopayments/transactions', array() ),
			'transaction details'       => array( '/woopayments/transactions/details', array() ),
			'reports'                   => array( '/woopayments/reports', array() ),
			'disputes'                  => array( '/woopayments/disputes', array() ),
			'dispute details'           => array( '/woopayments/disputes/details', array() ),
			'dispute challenge'         => array( '/woopayments/disputes/challenge', array() ),
			'card readers'              => array( '/woopayments/card-readers', array() ),
			'loans'                     => array( '/woopayments/loans', array() ),
			'documents'                 => array( '/woopayments/documents', array() ),
			'settings'                  => array( '/woopayments/settings', $settings_query ),
			'express checkout settings' => array( '/woopayments/settings/express-checkout/payment_request', $settings_query ),
			'fraud protection settings' => array( '/woopayments/settings/fraud-protection', $settings_query ),
		);
	}

	/**
	 * @testdox Should match WooPayments admin paths by prefix, with an unanchored path regex.
	 */
	public function test_redirects_paths_that_start_with_a_guarded_path(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state() );

		// Client 11.1.0 matches `/^(paths)/` with no end anchor, so `/payments/overview-something` redirects too.
		$url = $this->run_onboarding_redirect( $sut, '/woopayments/overview-something' );

		$this->assert_onboarding_redirect(
			$url,
			array(
				'from'   => 'WCPAY_OVERVIEW',
				'source' => 'unknown',
			)
		);
	}

	/**
	 * @testdox Should send the referer-derived onboarding source with the Overview redirect.
	 * @dataProvider provider_overview_redirect_sources
	 *
	 * @param string               $referer         Referer URL.
	 * @param array<string,string> $extra_request   Extra query args on the Overview request.
	 * @param string               $expected_source Expected onboarding source.
	 */
	public function test_redirects_overview_to_onboarding_with_referer_source( string $referer, array $extra_request, string $expected_source ): void {
		$this->set_admin_user();
		$sut                     = $this->create_controller( true, $this->get_no_account_state() );
		$_SERVER['HTTP_REFERER'] = $referer;

		$url = $this->run_onboarding_redirect_for_request(
			$sut,
			array_merge(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/overview',
				),
				$extra_request
			)
		);

		// Client 11.1.0 WC_Payments_Account::maybe_redirect_from_overview_page() passes FROM_OVERVIEW_PAGE and get_source().
		$this->assert_onboarding_redirect(
			$url,
			array(
				'from'   => 'WCPAY_OVERVIEW',
				'source' => $expected_source,
			)
		);
	}

	/**
	 * Overview requests and the onboarding source the redirect should carry.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:string}>
	 */
	public function provider_overview_redirect_sources(): array {
		return array(
			'payments task referer'     => array( admin_url( 'admin.php?page=wc-admin&task=payments' ), array(), 'wcadmin-payment-task' ),
			'payouts page referer'      => array( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts' ), array(), 'wcpay-payouts-page' ),
			'payments settings referer' => array( admin_url( 'admin.php?page=wc-settings&tab=checkout' ), array(), 'wcadmin-settings-page' ),
			'from param over referer'   => array( admin_url( 'admin.php?page=wc-admin&task=payments' ), array( 'from' => 'WCPAY_PAYOUTS' ), 'wcpay-payouts-page' ),
		);
	}

	/**
	 * @testdox Should redirect to onboarding when the account is valid but the WordPress.com connection is not working.
	 */
	public function test_redirects_to_onboarding_without_a_working_connection(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, array(), array(), false );

		$url = $this->run_onboarding_redirect( $sut, '/woopayments/transactions' );

		$this->assert_onboarding_redirect( $url, array() );
	}

	/**
	 * @testdox Should redirect to onboarding when the connection works but the account is not valid.
	 * @dataProvider provider_invalid_account_states
	 *
	 * @param array<string,mixed> $account_state Account state overrides.
	 */
	public function test_redirects_to_onboarding_with_an_invalid_account( array $account_state ): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $account_state );

		$url = $this->run_onboarding_redirect( $sut, '/woopayments/disputes/details' );

		$this->assert_onboarding_redirect( $url, array() );
	}

	/**
	 * Accounts that fail the client's `is_stripe_account_valid()` check.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provider_invalid_account_states(): array {
		return array(
			'details not submitted'     => array(
				array(
					'is_details_submitted' => false,
					'has_valid_account_for_admin_navigation' => false,
				),
			),
			'card payments unrequested' => array(
				array(
					'has_valid_account_for_admin_navigation' => false,
				),
			),
		);
	}

	/**
	 * @testdox Should not redirect WooPayments admin pages with a valid account and a working connection.
	 * @dataProvider provider_onboarding_redirect_paths
	 *
	 * @param string $path Requested native WooPayments route path.
	 */
	public function test_does_not_redirect_with_a_valid_account_and_working_connection( string $path ): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, $path ) );
	}

	/**
	 * @testdox Should not redirect a test-drive account with a working connection, read from the platform account payload.
	 * @dataProvider provider_onboarding_redirect_paths
	 *
	 * @param string $path Requested native WooPayments route path.
	 */
	public function test_does_not_redirect_a_test_drive_account_with_working_connection( string $path ): void {
		$this->set_admin_user();
		// Test-drive onboarding turns on onboarding test mode, which a test-mode account needs to count as cached.
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		// Account fields as the platform returns them for a test-drive account created through NOX "Test payments".
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'           => 'acct_test_drive_1',
					'test_publishable_key' => 'pk_test_drive_1',
					'is_live'              => false,
					'is_test_drive'        => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'capabilities'         => array(
						'card_payments' => 'active',
					),
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		$account_service = new WooPaymentsAccountService();
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );

		$sut = $this->create_controller_with_account_service( $account_service, true );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, $path ) );
	}

	/**
	 * @testdox Should not redirect the onboarding route itself, so the redirect cannot loop.
	 */
	public function test_does_not_redirect_the_onboarding_route(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state(), array(), false );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, '/woopayments/onboarding' ) );
	}

	/**
	 * @testdox Should not redirect users without manage_woocommerce.
	 */
	public function test_does_not_redirect_to_onboarding_without_manage_woocommerce_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$sut = $this->create_controller( true, $this->get_no_account_state() );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, '/woopayments/overview' ) );
	}

	/**
	 * @testdox Should not redirect to onboarding during AJAX requests.
	 */
	public function test_does_not_redirect_to_onboarding_during_ajax_requests(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state() );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, '/woopayments/overview' ) );
	}

	/**
	 * @testdox Should not redirect requests outside the WooPayments admin routes.
	 * @dataProvider provider_unrelated_admin_requests
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_redirect_unrelated_admin_requests( array $request ): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state() );

		$this->assertSame( '', $this->run_onboarding_redirect_for_request( $sut, $request ) );
	}

	/**
	 * Requests that are not native WooPayments admin routes.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_unrelated_admin_requests(): array {
		return array(
			'payments settings main page'  => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'other payments settings path' => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/offline/bacs',
				),
			),
			'other settings tab'           => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'general',
					'path' => '/woopayments/overview',
				),
			),
			'wc-admin page with the path'  => array(
				array(
					'page' => 'wc-admin',
					'path' => '/woopayments/overview',
				),
			),
			'nested path not at the start' => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/other/woopayments/overview',
				),
			),
		);
	}

	/**
	 * Get the account state of a store with no WooPayments account.
	 *
	 * @return array<string,mixed>
	 */
	private function get_no_account_state(): array {
		return array(
			'has_account'                            => false,
			'is_details_submitted'                   => false,
			'has_valid_account_for_admin_navigation' => false,
		);
	}

	/**
	 * Log in as a store manager.
	 */
	private function set_admin_user(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Run the onboarding redirect for a native WooPayments route path.
	 *
	 * @param WooPaymentsAdminNavigationController $sut  Controller under test.
	 * @param string                               $path Native WooPayments route path.
	 * @return string The intercepted redirect location, or an empty string.
	 */
	private function run_onboarding_redirect( WooPaymentsAdminNavigationController $sut, string $path ): string {
		return $this->run_onboarding_redirect_for_request(
			$sut,
			array(
				'page' => 'wc-settings',
				'tab'  => 'checkout',
				'path' => $path,
			)
		);
	}

	/**
	 * Run the onboarding redirect for a query request.
	 *
	 * @param WooPaymentsAdminNavigationController $sut     Controller under test.
	 * @param array<string,string>                 $request Query request.
	 * @return string The intercepted redirect location, or an empty string.
	 */
	private function run_onboarding_redirect_for_request( WooPaymentsAdminNavigationController $sut, array $request ): string {
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 10, 1 );

		try {
			$this->onboarding_redirects[ spl_object_id( $sut ) ]->maybe_redirect();
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted', $exception->getMessage() );
		}

		return $this->intercepted_redirect;
	}

	/**
	 * Assert a redirect went to the native WooPayments onboarding route with exactly the expected query.
	 *
	 * The client 11.1.0 redirect drops its "complete your setup" notice, so the URL carries no notice argument.
	 *
	 * @param string               $url            Intercepted redirect location.
	 * @param array<string,string> $expected_query Expected onboarding tracking query args.
	 */
	private function assert_onboarding_redirect( string $url, array $expected_query ): void {
		$this->assertNotSame( '', $url, 'Expected a redirect to WooPayments onboarding.' );
		$this->assertSame( admin_url( 'admin.php' ), strtok( $url, '?' ) );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

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
	 * Create the controller under test.
	 *
	 * @param bool                $builtin_owner Whether the built-in WooPayments owns the runtime.
	 * @param array<string,mixed> $account_state Account state overrides.
	 * @param array<string,int>   $badge_counts  Badge count overrides.
	 * @param bool                $connected     Whether the WordPress.com connection works.
	 * @return WooPaymentsAdminNavigationController
	 */
	private function create_controller( bool $builtin_owner, array $account_state = array(), array $badge_counts = array(), bool $connected = true ): WooPaymentsAdminNavigationController {
		$class_name = WooPaymentsAdminNavigationController::class;
		$this->assertTrue( class_exists( $class_name ), 'WooPayments admin navigation controller should exist.' );

		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owner );

		$account_service = $this->create_account_service( $account_state );
		$badge_service   = $this->create_badge_service( $badge_counts );
		$api_client      = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		$controller          = new $class_name();
		$onboarding_redirect = new WooPaymentsOnboardingRedirect();
		$onboarding_redirect->init( $arbiter, $api_client, $account_service );
		$controller->init( $arbiter, $account_service, $badge_service, $onboarding_redirect );
		$this->onboarding_redirects[ spl_object_id( $controller ) ] = $onboarding_redirect;

		return $controller;
	}

	/**
	 * Create the controller under test with a given account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @param bool                      $connected       Whether the WordPress.com connection works.
	 * @return WooPaymentsAdminNavigationController
	 */
	private function create_controller_with_account_service( WooPaymentsAccountService $account_service, bool $connected ): WooPaymentsAdminNavigationController {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );

		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		// The account read needs the same connection: without one it returns no account, like the client.
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );

		$controller          = new WooPaymentsAdminNavigationController();
		$onboarding_redirect = new WooPaymentsOnboardingRedirect();
		$onboarding_redirect->init( $arbiter, $api_client, $account_service );
		$controller->init( $arbiter, $account_service, $this->create_badge_service(), $onboarding_redirect );
		$this->onboarding_redirects[ spl_object_id( $controller ) ] = $onboarding_redirect;

		return $controller;
	}

	/**
	 * Create a constructor-free gateway identity.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return WooPaymentsGateway
	 */
	private function create_gateway( string $gateway_id ): WooPaymentsGateway {
		$gateway     = $this->getMockBuilder( WooPaymentsGateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = $gateway_id;

		return $gateway;
	}

	/**
	 * Create a native account service test double.
	 *
	 * @param array<string,mixed> $overrides Account state overrides.
	 * @return WooPaymentsAccountService&MockObject
	 */
	private function create_account_service( array $overrides = array() ): WooPaymentsAccountService {
		$state = array_merge(
			array(
				'has_account'                            => true,
				'has_valid_account_for_admin_navigation' => true,
				'is_account_rejected'                    => false,
				'is_account_under_review'                => false,
				'is_details_submitted'                   => true,
				'is_gateway_enabled'                     => true,
				'is_card_present_eligible'               => false,
				'has_card_readers_available'             => false,
				'has_previous_capital_loans'             => false,
				'is_documents_enabled'                   => false,
				'is_reports_enabled'                     => false,
				'get_cached_account_data'                => array(),
				'get_account_id'                         => '',
				'is_test_mode_enabled'                   => false,
				'is_dev_mode_enabled'                    => false,
			),
			$overrides
		);

		if ( isset( $state['has_valid_admin_account_state'] ) ) {
			$state['has_valid_account_for_admin_navigation'] = $state['has_valid_admin_account_state'];
			unset( $state['has_valid_admin_account_state'] );
		}

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array_keys( $state ) )
			->getMock();

		foreach ( $state as $method => $value ) {
			$account_service->method( $method )->willReturn( $value );
		}

		return $account_service;
	}

	/**
	 * Create a native badge service test double.
	 *
	 * @param array<string,int> $overrides Badge count overrides, and `manual_capture` as 1 or 0.
	 * @return WooPaymentsAdminMenuBadgeService&MockObject
	 */
	private function create_badge_service( array $overrides = array() ): WooPaymentsAdminMenuBadgeService {
		$counts = array_merge(
			array(
				'disputes_awaiting_response' => 0,
				'uncaptured_transactions'    => 0,
				'manual_capture'             => 0,
			),
			$overrides
		);

		$badge_service = $this->getMockBuilder( WooPaymentsAdminMenuBadgeService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_disputes_awaiting_response_count', 'get_uncaptured_transactions_count', 'is_manual_capture_enabled' ) )
			->getMock();

		$badge_service->method( 'get_disputes_awaiting_response_count' )->willReturn( $counts['disputes_awaiting_response'] );
		$badge_service->method( 'get_uncaptured_transactions_count' )->willReturn( $counts['uncaptured_transactions'] );
		$badge_service->method( 'is_manual_capture_enabled' )->willReturn( 1 === $counts['manual_capture'] );

		return $badge_service;
	}

	/**
	 * Preload admin route availability on a Payments settings request.
	 *
	 * @param WooPaymentsAdminNavigationController $controller Controller under test.
	 * @return array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>}
	 */
	private function preload_route_availability( WooPaymentsAdminNavigationController $controller ): array {
		return $controller->get_admin_route_availability();
	}

	/**
	 * Get WooPayments submenu items from the Core Payments parent.
	 *
	 * @return array<int,array<int,string>>
	 */
	private function get_payments_submenu_items(): array {
		global $submenu;

		return array_values( $submenu[ $this->get_parent_slug() ] ?? array() );
	}

	/**
	 * Get a submenu item by its visible title prefix.
	 *
	 * @param string $title Menu title.
	 * @return array<int,string>
	 */
	private function get_submenu_item_by_title( string $title ): array {
		foreach ( $this->get_payments_submenu_items() as $item ) {
			if ( str_starts_with( $item[0], $title ) ) {
				return $item;
			}
		}

		$this->fail( sprintf( 'Expected submenu item "%s" to exist.', $title ) );
	}

	/**
	 * Get the Core Payments parent menu slug.
	 *
	 * @return string
	 */
	private function get_parent_slug(): string {
		return 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM;
	}

	/**
	 * Get a canonical native WooPayments settings route URL.
	 *
	 * @param string $path Native WooPayments settings route path.
	 * @return string
	 */
	private function get_settings_url( string $path ): string {
		return Utils::wc_payments_settings_url( $path, array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
	}

	/**
	 * Intercept redirects before exit terminates the test process.
	 *
	 * @param string $location Redirect location.
	 * @throws \RuntimeException To interrupt redirect handling.
	 */
	public function intercept_redirect( string $location ): void {
		$this->intercepted_redirect = esc_url_raw( $location );
		throw new \RuntimeException( 'wp_redirect intercepted' );
	}
}
