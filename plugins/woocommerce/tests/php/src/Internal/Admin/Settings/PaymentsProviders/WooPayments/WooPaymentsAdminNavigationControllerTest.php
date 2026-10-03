<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
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
	 * Controllers created by the test.
	 *
	 * @var WooPaymentsAdminNavigationController[]
	 */
	private array $controllers = array();

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

		foreach ( $this->controllers as $controller ) {
			remove_action( 'admin_init', array( $controller, 'maybe_redirect_to_onboarding' ), 16 );
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
		unset( $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['woopayments-vat-details-redirect'], $_GET['from'], $_SERVER['HTTP_REFERER'] );
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
		$sut->register();

		$this->assertSame( 70, has_action( 'admin_menu', array( $sut, 'add_menu_items' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $sut, 'redirect_legacy_payment_paths' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ) ) );
		$this->assertSame( 10, has_filter( 'submenu_file', array( $sut, 'highlight_current_payments_submenu' ) ) );
		$this->assertSame( 10, has_action( 'adminmenu', array( $sut, 'open_payments_menu' ) ) );

		remove_filter( 'submenu_file', array( $sut, 'highlight_current_payments_submenu' ) );
		remove_action( 'adminmenu', array( $sut, 'open_payments_menu' ) );
		remove_action( 'admin_menu', array( $sut, 'add_menu_items' ), 70 );
		remove_action( 'admin_init', array( $sut, 'redirect_legacy_payment_paths' ), 10 );
		remove_action( 'template_redirect', array( $sut, 'redirect_vat_details_request' ), 10 );
		remove_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ), 10 );
	}

	/**
	 * @testdox Should resolve every published admin route through the canonical client route inventory.
	 */
	public function test_all_published_admin_routes_are_registered(): void {
		$sut = $this->create_controller( true );

		$this->assertTrue( $sut->are_all_available_routes_registered() );
	}

	/**
	 * @testdox Should not register the WooPayments navigation hook when native runtime does not own payments.
	 */
	public function test_does_not_register_admin_menu_hook_when_native_runtime_does_not_own_payments(): void {
		$sut = $this->create_controller( false );

		$sut->register();

		$this->assertFalse( has_action( 'admin_menu', array( $sut, 'add_menu_items' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $sut, 'redirect_legacy_payment_paths' ) ) );
		$this->assertFalse( has_action( 'template_redirect', array( $sut, 'redirect_vat_details_request' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ) ) );
	}

	/**
	 * @testdox Should register the VAT details redirect bridge when native runtime owns payments.
	 */
	public function test_registers_vat_details_redirect_bridge_when_native_runtime_owns_payments(): void {
		$sut = $this->create_controller( true );

		$sut->register();

		$this->assertSame( 10, has_action( 'template_redirect', array( $sut, 'redirect_vat_details_request' ) ) );

		remove_action( 'admin_menu', array( $sut, 'add_menu_items' ), 70 );
		remove_action( 'admin_init', array( $sut, 'redirect_legacy_payment_paths' ), 10 );
		remove_action( 'template_redirect', array( $sut, 'redirect_vat_details_request' ), 10 );
		remove_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ), 10 );
	}

	/**
	 * @testdox Should build a native settings URL for legacy VAT details redirects.
	 */
	public function test_builds_native_settings_url_for_legacy_vat_details_redirects(): void {
		$sut = $this->create_controller( true );

		$this->assertTrue( method_exists( $sut, 'get_vat_details_redirect_url' ) );

		$url = $sut->get_vat_details_redirect_url(
			array(
				'woopayments-vat-details-redirect' => '1',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/settings', $query['path'] );
		$this->assertSame( 'true', $query['woopayments-vat-details-modal'] );
		$this->assertArrayNotHasKey( 'woopayments-vat-details-redirect', $query );
	}

	/**
	 * @testdox Should ignore requests without the legacy VAT details redirect query.
	 */
	public function test_ignores_requests_without_legacy_vat_details_redirect_query(): void {
		$sut = $this->create_controller( true );

		$this->assertTrue( method_exists( $sut, 'get_vat_details_redirect_url' ) );
		$this->assertSame( '', $sut->get_vat_details_redirect_url( array() ) );
	}

	/**
	 * @testdox Should redirect legacy VAT details requests to native provider settings.
	 */
	public function test_redirects_legacy_vat_details_requests_to_native_provider_settings(): void {
		$sut                                      = $this->create_controller( true );
		$_GET['woopayments-vat-details-redirect'] = '1';
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 10, 1 );

		try {
			$sut->redirect_vat_details_request();
			$this->fail( 'Expected the VAT details redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted', $exception->getMessage() );
		}

		$url = $this->intercepted_redirect;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/settings', $query['path'] );
		$this->assertSame( 'true', $query['woopayments-vat-details-modal'] );
		$this->assertArrayNotHasKey( 'woopayments-vat-details-redirect', $query );
	}

	/**
	 * @testdox Should not redirect legacy VAT details requests during AJAX requests.
	 */
	public function test_does_not_redirect_legacy_vat_details_requests_during_ajax_requests(): void {
		$sut                                      = $this->create_controller( true );
		$_GET['woopayments-vat-details-redirect'] = '1';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 10, 1 );

		try {
			$sut->redirect_vat_details_request();
		} catch ( \RuntimeException $exception ) {
			$this->fail( 'AJAX VAT details requests should not redirect: ' . $exception->getMessage() );
		}

		$this->assertSame( '', $this->intercepted_redirect, 'AJAX requests with legacy VAT details query should return without redirecting.' );
	}

	/**
	 * @testdox Should preload the Reports feature flag for native WooPayments routes on the Payments settings page.
	 */
	public function test_preloads_reports_feature_flag_on_payments_settings_request(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);

		$settings = $sut->preload_shared_settings(
			array(
				'woopaymentsSettings' => array(
					'featureFlags' => array(
						'existingFlag' => true,
					),
				),
			)
		);

		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
	}

	/**
	 * The money formatter's data is preloaded as client 11.1.0 `WC_Payments_Admin::get_js_settings()` localizes it: the store
	 * country, the zero-decimal currencies and each country's currency format from WooCommerce's locale data
	 * (`class-wc-payments-admin.php:937-964,1007,1023,1047`).
	 */
	public function test_preloads_the_currency_format_data(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		update_option( 'woocommerce_default_country', 'FR' );

		$settings = $this->create_controller( true )->preload_shared_settings( array() )['woopaymentsSettings'];

		$this->assertSame( 'FR', $settings['storeCountry'] );
		$this->assertContains( 'jpy', $settings['zeroDecimalCurrencies'] );
		$this->assertNotContains( 'usd', $settings['zeroDecimalCurrencies'] );
		$this->assertSame(
			array(
				'code'              => 'JPY',
				'symbol'            => '¥',
				'symbolPosition'    => 'left',
				'thousandSeparator' => ',',
				'decimalSeparator'  => '.',
				'precision'         => 0,
				'defaultLocale'     => array(
					'symbolPosition'    => 'left',
					'thousandSeparator' => ',',
					'decimalSeparator'  => '.',
				),
			),
			$settings['currencyData']['JP']
		);
	}

	/**
	 * @testdox Should preload the test and dev mode flags the test-mode notice reads.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1012-1014` (`devMode`, `testMode` in `wcpaySettings`).
	 *
	 * @testWith [true, false]
	 *           [true, true]
	 *           [false, false]
	 *
	 * @param bool $test_mode Whether WooPayments runs in test mode.
	 * @param bool $dev_mode  Whether WooPayments runs in dev mode.
	 */
	public function test_preloads_test_and_dev_mode_flags( bool $test_mode, bool $dev_mode ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'is_test_mode_enabled' => $test_mode,
				'is_dev_mode_enabled'  => $dev_mode,
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $test_mode, $settings['woopaymentsSettings']['testMode'] );
		$this->assertSame( $dev_mode, $settings['woopaymentsSettings']['devMode'] );
	}

	/**
	 * @testdox Should preload the email the list exports are sent to: the current user's, else the site admin email.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:930-935,1046` (`currentUserEmail`), which the
	 * transactions, disputes and payouts exports send as `user_email`.
	 */
	public function test_preloads_current_user_email_for_list_exports(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller( true );

		$this->assertSame( get_option( 'admin_email' ), $sut->preload_shared_settings( array() )['woopaymentsSettings']['currentUserEmail'] );

		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'merchant@example.test' ) ) );

		$this->assertSame( 'merchant@example.test', $sut->preload_shared_settings( array() )['woopaymentsSettings']['currentUserEmail'] );
	}

	/**
	 * @testdox Should preload whether manual capture is on, which shows the Uncaptured transactions tab.
	 *
	 * Source: plugin 11.1.0 `client/transactions/index.tsx:63-72` (`getIsManualCaptureEnabled`).
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $manual_capture Whether manual capture is on.
	 */
	public function test_preloads_manual_capture_flag( bool $manual_capture ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller( true, array(), array( 'manual_capture' => (int) $manual_capture ) );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $manual_capture, $settings['woopaymentsSettings']['isManualCaptureEnabled'] );
	}

	/**
	 * @testdox Should preload whether the payouts page schedule notice is dismissed (stored "$stored").
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1060` (`isNextDepositNoticeDismissed`) and
	 * `class-wc-payments-features.php:391`, which reads `'1' === get_option( 'wcpay_next_deposit_notice_dismissed', '0' )`.
	 *
	 * @testWith ["1", true]
	 *           ["0", false]
	 *           [null, false]
	 *
	 * @param string|null $stored   Stored option value, or null when the option is absent.
	 * @param bool        $expected Expected preloaded flag.
	 */
	public function test_preloads_next_payout_notice_dismissal( ?string $stored, bool $expected ): void {
		if ( null !== $stored ) {
			update_option( 'wcpay_next_deposit_notice_dismissed', $stored );
		}
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $expected, $settings['woopaymentsSettings']['isNextDepositNoticeDismissed'] );
	}

	/**
	 * @testdox Should preload the store address WooCommerce formats, for the dispute cover letter.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1074-1085` (`formattedStoreAddress`) and
	 * `disputes/new-evidence/cover-letter-generator.ts:35-50`.
	 */
	public function test_preloads_formatted_store_address(): void {
		update_option( 'woocommerce_store_address', '1 Market Street' );
		update_option( 'woocommerce_store_address_2', 'Suite 2' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94105' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			'1 Market Street, Suite 2, San Francisco, CA 94105',
			$settings['woopaymentsSettings']['formattedStoreAddress']
		);
	}

	/**
	 * @testdox Should preload that WooCommerce Subscriptions is inactive for the transactions list's Subscription # column.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1021` (`isSubscriptionsActive` in `wcpaySettings`).
	 */
	public function test_preloads_inactive_subscriptions_plugin_flag(): void {
		$this->assertFalse( class_exists( 'WC_Subscriptions' ), 'The unit environment must not load WooCommerce Subscriptions.' );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayHasKey( 'isSubscriptionsActive', $settings['woopaymentsSettings'] );
		$this->assertFalse( $settings['woopaymentsSettings']['isSubscriptionsActive'] );
	}

	/**
	 * @testdox Should preload the Apple Pay domain error for in-app navigation without clearing it on a list load.
	 */
	public function test_preloads_apple_pay_domain_error_without_clearing_it(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'              => 'yes',
				'apple_pay_domain_set' => 'no',
			)
		);
		update_option( 'wcpay_apple_pay_domain_error', 'Domain not verified, see https://example.com/help' );
		$sut = $this->create_controller( true, array(), array(), $this->create_apple_pay_domain_service( true ) );

		try {
			$settings = $sut->preload_shared_settings( array() );
		} finally {
			$stored_error = get_option( 'wcpay_apple_pay_domain_error' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
			delete_option( 'wcpay_apple_pay_domain_error' );
		}

		$this->assertSame(
			array(
				'error'   => 'Domain not verified, see <a href="https://example.com/help">https://example.com/help</a>',
				'errorId' => hash( 'sha256', 'Domain not verified, see https://example.com/help' ),
				'logsUrl' => admin_url( 'admin.php?page=wc-status&tab=logs' ),
			),
			$settings['woopaymentsSettings']['applePayDomainError']
		);
		$this->assertSame( 'Domain not verified, see https://example.com/help', $stored_error, 'A Payments list load must not clear an error nobody saw.' );
	}

	/**
	 * @testdox Should not preload an Apple Pay domain error when no domain verification failed.
	 */
	public function test_does_not_preload_apple_pay_domain_error_without_a_failure(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'              => 'yes',
				'apple_pay_domain_set' => 'yes',
			)
		);
		$sut = $this->create_controller( true, array(), array(), $this->create_apple_pay_domain_service( true ) );

		try {
			$settings = $sut->preload_shared_settings( array() );
		} finally {
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertArrayNotHasKey( 'applePayDomainError', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preload the account's loans for the transactions Loan filter, keeping only the loan strings.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1031` (`accountLoans` from `WC_Payments_Account::get_capital()`, every WooPayments page)
	 * and `client/transactions/filters/config.ts:555-571` (`<loan id>|<status>` strings).
	 */
	public function test_preloads_account_loans_for_the_transactions_loan_filter(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/woopayments/transactions';
		$sut          = $this->create_controller(
			true,
			array(
				'get_cached_account_data' => array(
					'capital' => array(
						'loans'           => array(
							3 => 'flxln_active|active',
							5 => array( 'not' => 'a loan' ),
							7 => 'flxln_paid|paid',
						),
						'has_active_loan' => true,
					),
				),
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'loans'              => array( 'flxln_active|active', 'flxln_paid|paid' ),
				'has_active_loan'    => true,
				'has_previous_loans' => false,
			),
			$settings['woopaymentsSettings']['accountLoans'],
			'The loans page requests the active loan summary only when has_active_loan, as the client mounts its card (capital/index.tsx:216).'
		);
	}

	/**
	 * @testdox Should preload the account's Documents facts so Documents and the VAT link need no account request.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::get_account_status_data()` (`isDocumentsEnabled`, `hasSubmittedVatData`, `country`,
	 * class-wc-payments-account.php:371-384), localized as `wcpaySettings.accountStatus`.
	 */
	public function test_preloads_the_account_documents_facts(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/woopayments/documents';
		$sut          = $this->create_controller(
			true,
			array(
				'is_documents_enabled'    => true,
				'get_cached_account_data' => array(
					'account_id'             => 'acct_documents',
					'country'                => 'CH',
					'has_submitted_vat_data' => true,
				),
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'enabled'                => true,
				'has_submitted_vat_data' => true,
				'country'                => 'CH',
			),
			$settings['woopaymentsSettings']['accountDocuments']
		);
	}

	/**
	 * @testdox Should preload no loans when the cached account has no Capital data.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::get_capital()` falls back to no loans and no active or previous loan.
	 */
	public function test_preloads_empty_account_loans_without_capital_data(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'get_cached_account_data' => array(
					'capital' => array( 'loans' => 'flxln_active|active' ),
				),
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'loans'              => array(),
				'has_active_loan'    => false,
				'has_previous_loans' => false,
			),
			$settings['woopaymentsSettings']['accountLoans']
		);
	}

	/**
	 * @testdox Should preload the Overview shell on the Overview route so the page renders without waiting for a request.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1020-1062` inlines the Overview's account data in `wcpaySettings`.
	 */
	public function test_preloads_overview_shell_on_the_overview_route(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/woopayments/overview';
		$shell        = array(
			'account'        => array( 'connected' => true ),
			'account_status' => array( 'status' => 'complete' ),
		);
		$this->replace_overview_service( $shell );
		$sut = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $shell, $settings['woopaymentsSettings']['overviewShell'] );
	}

	/**
	 * @testdox Should not build the Overview shell on route "$path".
	 *
	 * @testWith ["/woopayments/transactions"]
	 *           ["/woopayments/settings"]
	 *           ["/woopayments/overview/extra"]
	 *           [""]
	 *
	 * @param string $path The settings page route.
	 */
	public function test_does_not_preload_overview_shell_outside_the_overview_route( string $path ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;
		$this->replace_overview_service( null );
		$this->replace_woopayments_service(
			array(
				'account' => null,
				'urls'    => array(),
			)
		);
		$sut = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'overviewShell', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should not build the Overview shell when the account cannot open the Overview.
	 */
	public function test_does_not_preload_overview_shell_when_the_overview_route_is_not_allowed(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/woopayments/overview';
		$this->replace_overview_service( null );
		$sut = $this->create_controller( true, $this->get_no_account_state() );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'overviewShell', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should leave the Overview shell to the page request when building it fails.
	 */
	public function test_does_not_preload_overview_shell_when_building_it_fails(): void {
		$_GET['page']     = 'wc-settings';
		$_GET['tab']      = 'checkout';
		$_GET['path']     = '/woopayments/overview';
		$overview_service = $this->getMockBuilder( WooPaymentsOverviewService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_overview' ) )
			->getMock();
		$overview_service->method( 'get_overview' )->willThrowException( new \Exception( 'Overview failed.' ) );
		wc_get_container()->replace( WooPaymentsOverviewService::class, $overview_service );
		$sut = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'overviewShell', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preload the account mode for the test account notice on settings route "$path".
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin-settings.php:126-252` renders the test and sandbox account notices on the server.
	 *
	 * @testWith ["/woopayments/settings"]
	 *           ["/woopayments/settings/express-checkout/google_pay"]
	 *
	 * @param string $path The settings page route.
	 */
	public function test_preloads_account_mode_on_settings_routes( string $path ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;
		$this->replace_woopayments_service(
			array(
				'account'   => array(
					'id'         => 'acct_test',
					'connected'  => true,
					'live'       => false,
					'test_drive' => true,
					'sandbox'    => false,
				),
				'documents' => array( 'enabled' => false ),
				'urls'      => array(
					'overview_page' => 'https://example.com/overview',
					'setup'         => 'https://example.com/setup',
				),
			)
		);
		$sut = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'connected' => true,
				'live'      => false,
				'testDrive' => true,
				'sandbox'   => false,
				'setupUrl'  => 'https://example.com/setup',
			),
			$settings['woopaymentsSettings']['accountMode']
		);
	}

	/**
	 * @testdox Should not read the account mode on route "$path", which shows no test account notice.
	 *
	 * @testWith ["/woopayments/overview"]
	 *           ["/woopayments/settings/fraud-protection"]
	 *           [""]
	 *
	 * @param string $path The settings page route.
	 */
	public function test_does_not_preload_account_mode_outside_settings_routes( string $path ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;
		$this->replace_overview_service( array() );
		$this->replace_woopayments_service( null );
		$sut = $this->create_controller( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'accountMode', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preload trimmed Balance report identity from the WooPayments account.
	 */
	public function test_preloads_trimmed_balance_report_identity_from_account(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'get_cached_account_data' => array(
					'business_profile' => array(
						'name' => ' Native Merchant ',
					),
				),
				'get_account_id'          => ' acct_native_123 ',
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'businessName' => 'Native Merchant',
				'accountId'    => 'acct_native_123',
			),
			$settings['woopaymentsSettings']['balanceReportIdentity']
		);
	}

	/**
	 * @testdox Should fall back to the store name and an empty account ID for Balance report identity.
	 */
	public function test_preloads_balance_report_identity_with_store_name_and_empty_account_id_fallbacks(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		update_option( 'blogname', ' Native Store ' );
		$sut = $this->create_controller(
			true,
			array(
				'get_cached_account_data' => array(
					'business_profile' => array(
						'name' => ' ',
					),
				),
				'get_account_id'          => ' ',
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame(
			array(
				'businessName' => 'Native Store',
				'accountId'    => '',
			),
			$settings['woopaymentsSettings']['balanceReportIdentity']
		);
	}

	/**
	 * @testdox Should apply Core Multi-Currency settings before legacy WooPayments settings.
	 */
	public function test_preloads_core_multi_currency_settings_before_legacy_woopayments_settings(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$filter_calls = array();

		add_filter(
			'woocommerce_multi_currency_js_settings',
			static function ( array $settings ) use ( &$filter_calls ): array {
				$filter_calls[] = array(
					'hook'     => 'woocommerce_multi_currency_js_settings',
					'settings' => $settings,
				);
				return array_merge( $settings, array( 'multiCurrency' => array( 'enabled' => true ) ) );
			}
		);
		add_filter(
			'wcpay_js_settings',
			static function ( array $settings ) use ( &$filter_calls ): array {
				$filter_calls[] = array(
					'hook'     => 'wcpay_js_settings',
					'settings' => $settings,
				);
				return array_merge( $settings, array( 'legacyWooPayments' => true ) );
			}
		);

		$settings = $sut->preload_shared_settings(
			array(
				'preservedSharedSetting' => 'shared value',
				'woopaymentsSettings'    => array(
					'preservedProviderSetting' => 'provider value',
					'featureFlags'             => array(
						'existingFlag' => true,
					),
				),
			)
		);

		$this->assertSame(
			array(
				'woocommerce_multi_currency_js_settings',
				'wcpay_js_settings',
			),
			wp_list_pluck( $filter_calls, 'hook' )
		);
		$this->assertSame( 'provider value', $filter_calls[0]['settings']['preservedProviderSetting'] );
		$this->assertTrue( $filter_calls[0]['settings']['featureFlags']['reportsArea'] );
		$this->assertSame( array( 'enabled' => true ), $filter_calls[1]['settings']['multiCurrency'] );
		$this->assertSame( 'shared value', $settings['preservedSharedSetting'] );
		$this->assertSame( 'provider value', $settings['woopaymentsSettings']['preservedProviderSetting'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertSame( array( 'enabled' => true ), $settings['woopaymentsSettings']['multiCurrency'] );
		$this->assertTrue( $settings['woopaymentsSettings']['legacyWooPayments'] );
	}

	/**
	 * @testdox Should preserve native WooPayments settings when the Core filter returns an invalid value.
	 */
	public function test_preserves_native_woopayments_settings_when_core_filter_returns_invalid_value(): void {
		$_GET['page']    = 'wc-settings';
		$_GET['tab']     = 'checkout';
		$sut             = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$core_settings   = null;
		$legacy_settings = null;

		add_filter(
			'woocommerce_multi_currency_js_settings',
			static function ( array $settings ) use ( &$core_settings ) {
				$core_settings = $settings;

				return null;
			}
		);
		add_filter(
			'wcpay_js_settings',
			static function ( array $settings ) use ( &$legacy_settings ): array {
				$legacy_settings = $settings;

				return $settings;
			}
		);

		$settings = $sut->preload_shared_settings(
			array(
				'preservedSharedSetting' => 'shared value',
				'woopaymentsSettings'    => array(
					'preservedProviderSetting' => 'provider value',
					'featureFlags'             => array(
						'existingFlag' => true,
					),
				),
			)
		);

		$this->assertSame( $core_settings, $legacy_settings );
		$this->assertSame( $core_settings, $settings['woopaymentsSettings'] );
		$this->assertSame( 'shared value', $settings['preservedSharedSetting'] );
		$this->assertSame( 'provider value', $settings['woopaymentsSettings']['preservedProviderSetting'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertArrayHasKey( 'adminRouteAvailability', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preserve Core Multi-Currency settings when the legacy filter returns an invalid value.
	 */
	public function test_preserves_core_multi_currency_settings_when_legacy_filter_returns_invalid_value(): void {
		$_GET['page']    = 'wc-settings';
		$_GET['tab']     = 'checkout';
		$sut             = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$legacy_settings = null;

		add_filter(
			'woocommerce_multi_currency_js_settings',
			static function ( array $settings ): array {
				return array_merge( $settings, array( 'multiCurrency' => array( 'enabled' => true ) ) );
			}
		);
		add_filter(
			'wcpay_js_settings',
			static function ( array $settings ) use ( &$legacy_settings ) {
				$legacy_settings = $settings;

				return null;
			}
		);

		$settings = $sut->preload_shared_settings(
			array(
				'preservedSharedSetting' => 'shared value',
				'woopaymentsSettings'    => array(
					'preservedProviderSetting' => 'provider value',
					'featureFlags'             => array(
						'existingFlag' => true,
					),
				),
			)
		);

		$this->assertSame( $legacy_settings, $settings['woopaymentsSettings'] );
		$this->assertSame( 'shared value', $settings['preservedSharedSetting'] );
		$this->assertSame( 'provider value', $settings['woopaymentsSettings']['preservedProviderSetting'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertArrayHasKey( 'adminRouteAvailability', $settings['woopaymentsSettings'] );
		$this->assertSame( array( 'enabled' => true ), $settings['woopaymentsSettings']['multiCurrency'] );
	}

	/**
	 * @testdox Should preload full admin route availability for a valid native account.
	 */
	public function test_preloads_full_admin_route_availability_for_valid_native_account(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_reports_enabled'         => true,
				'is_documents_enabled'       => true,
				'is_card_present_eligible'   => true,
				'has_card_readers_available' => true,
				'has_previous_capital_loans' => true,
			)
		);

		$availability = $this->preload_route_availability( $sut );
		$routes       = $availability['allowedRoutes'];

		$this->assertTrue( $availability['gatewayEnabled'] );
		$this->assertSame( 'full', $availability['accountState'] );
		$this->assertTrue( $routes['/woopayments/settings'] );
		$this->assertTrue( $routes['/woopayments/overview'] );
		$this->assertTrue( $routes['/woopayments/payouts'] );
		$this->assertTrue( $routes['/woopayments/payouts/details'] );
		$this->assertTrue( $routes['/woopayments/transactions'] );
		$this->assertTrue( $routes['/woopayments/transactions/details'] );
		$this->assertTrue( $routes['/woopayments/disputes'] );
		$this->assertTrue( $routes['/woopayments/disputes/details'] );
		$this->assertTrue( $routes['/woopayments/disputes/challenge'] );
		$this->assertTrue( $routes['/woopayments/reports'] );
		$this->assertTrue( $routes['/woopayments/card-readers'] );
		$this->assertTrue( $routes['/woopayments/loans'] );
		$this->assertTrue( $routes['/woopayments/documents'] );
		$this->assertTrue( $routes['/woopayments/settings/fraud-protection'] );
	}

	/**
	 * @testdox Should preload reduced admin route availability for restricted native accounts.
	 * @dataProvider provider_restricted_account_states
	 *
	 * @param array<string,bool> $account_state Account state overrides.
	 */
	public function test_preloads_restricted_admin_route_availability( array $account_state ): void {
		$sut = $this->create_controller(
			true,
			array_merge(
				$account_state,
				array(
					'is_reports_enabled'         => true,
					'is_documents_enabled'       => true,
					'is_card_present_eligible'   => true,
					'has_card_readers_available' => true,
					'has_previous_capital_loans' => true,
				)
			)
		);

		$availability = $this->preload_route_availability( $sut );
		$routes       = $availability['allowedRoutes'];

		$this->assertTrue( $availability['gatewayEnabled'] );
		$this->assertSame( 'restricted', $availability['accountState'] );
		$this->assertTrue( $routes['/woopayments/settings'] );
		$this->assertTrue( $routes['/woopayments/overview'] );
		$this->assertTrue( $routes['/woopayments/transactions'] );
		$this->assertTrue( $routes['/woopayments/transactions/details'] );
		$this->assertTrue( $routes['/woopayments/disputes'] );
		$this->assertTrue( $routes['/woopayments/disputes/details'] );
		$this->assertTrue( $routes['/woopayments/disputes/challenge'] );
		$this->assertTrue( $routes['/woopayments/settings/fraud-protection'] );
		$this->assertFalse( $routes['/woopayments/onboarding'] );
		$this->assertFalse( $routes['/woopayments/payouts'] );
		$this->assertFalse( $routes['/woopayments/payouts/details'] );
		$this->assertFalse( $routes['/woopayments/reports'] );
		$this->assertFalse( $routes['/woopayments/card-readers'] );
		$this->assertFalse( $routes['/woopayments/loans'] );
		$this->assertFalse( $routes['/woopayments/documents'] );
	}

	/**
	 * @testdox Should preload account-state admin route availability when the gateway is disabled.
	 */
	public function test_preloads_account_state_admin_route_availability_when_gateway_is_disabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_gateway_enabled'                     => false,
				'is_reports_enabled'                     => true,
				'is_documents_enabled'                   => true,
				'is_card_present_eligible'               => true,
				'has_card_readers_available'             => true,
				'has_previous_capital_loans'             => true,
				'has_valid_account_for_admin_navigation' => true,
			)
		);

		$availability = $this->preload_route_availability( $sut );
		$routes       = $availability['allowedRoutes'];

		$this->assertFalse( $availability['gatewayEnabled'] );
		$this->assertSame( 'full', $availability['accountState'] );
		$this->assertTrue( $routes['/woopayments/settings'] );
		$this->assertTrue( $routes['/woopayments/overview'] );
		$this->assertTrue( $routes['/woopayments/payouts'] );
		$this->assertTrue( $routes['/woopayments/payouts/details'] );
		$this->assertTrue( $routes['/woopayments/transactions'] );
		$this->assertTrue( $routes['/woopayments/transactions/details'] );
		$this->assertTrue( $routes['/woopayments/disputes'] );
		$this->assertTrue( $routes['/woopayments/disputes/details'] );
		$this->assertTrue( $routes['/woopayments/disputes/challenge'] );
		$this->assertTrue( $routes['/woopayments/reports'] );
		$this->assertTrue( $routes['/woopayments/card-readers'] );
		$this->assertTrue( $routes['/woopayments/loans'] );
		$this->assertTrue( $routes['/woopayments/documents'] );
		$this->assertTrue( $routes['/woopayments/settings/fraud-protection'] );
		$this->assertFalse( $routes['/woopayments/onboarding'] );
	}

	/**
	 * @testdox Should preload onboarding-only admin route availability for accounts that are not ready.
	 * @dataProvider provider_not_ready_account_states
	 *
	 * @param array<string,bool> $account_state Account state overrides.
	 * @param string             $expected_title Expected menu item title.
	 */
	public function test_preloads_onboarding_only_admin_route_availability_for_accounts_that_are_not_ready( array $account_state, string $expected_title ): void {
		unset( $expected_title );

		$sut = $this->create_controller(
			true,
			array_merge(
				$account_state,
				array(
					'is_reports_enabled'         => true,
					'is_documents_enabled'       => true,
					'is_card_present_eligible'   => true,
					'has_card_readers_available' => true,
					'has_previous_capital_loans' => true,
				)
			)
		);

		$availability = $this->preload_route_availability( $sut );
		$routes       = $availability['allowedRoutes'];

		$this->assertTrue( $availability['gatewayEnabled'] );
		$this->assertSame( 'onboarding', $availability['accountState'] );
		$this->assertTrue( $routes['/woopayments/settings'] );
		$this->assertTrue( $routes['/woopayments/onboarding'] );
		$this->assertTrue( $routes['/woopayments/settings/fraud-protection'] );
		$this->assertFalse( $routes['/woopayments/overview'] );
		$this->assertFalse( $routes['/woopayments/payouts'] );
		$this->assertFalse( $routes['/woopayments/transactions'] );
		$this->assertFalse( $routes['/woopayments/disputes'] );
		$this->assertFalse( $routes['/woopayments/reports'] );
		$this->assertFalse( $routes['/woopayments/card-readers'] );
		$this->assertFalse( $routes['/woopayments/loans'] );
		$this->assertFalse( $routes['/woopayments/documents'] );
	}

	/**
	 * @testdox Should preload optional admin routes only when their account-service predicates are true.
	 */
	public function test_preloads_optional_admin_routes_only_when_available(): void {
		$sut = $this->create_controller( true );

		$routes = $this->preload_route_availability( $sut )['allowedRoutes'];

		$this->assertTrue( $routes['/woopayments/settings'] );
		$this->assertTrue( $routes['/woopayments/overview'] );
		$this->assertFalse( $routes['/woopayments/reports'] );
		$this->assertFalse( $routes['/woopayments/card-readers'] );
		$this->assertFalse( $routes['/woopayments/loans'] );
		$this->assertFalse( $routes['/woopayments/documents'] );
	}

	/**
	 * @testdox Should not preload WooPayments route flags outside the Payments settings page.
	 */
	public function test_does_not_preload_reports_feature_flag_outside_payments_settings_request(): void {
		$_GET['page'] = 'wc-admin';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( array(), $settings );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin transaction URLs to native Settings Payments routes.
	 */
	public function test_maps_legacy_payment_transaction_details_url_to_native_route(): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
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
	 * @testdox Should open the native Transactions view that matches a client 11.1.0 Transactions tab link.
	 *
	 * Client 11.1.0 `client/transactions/index.tsx:32-43, :77-97` writes the open tab to the URL as
	 * `tab=transactions-page|uncaptured-page|blocked-page`; native reads `view=uncaptured|blocked`.
	 *
	 * @dataProvider provider_client_transactions_tabs
	 *
	 * @param string      $client_tab    Client 11.1.0 Transactions tab name.
	 * @param string|null $expected_view Native Transactions view, or null for the default list.
	 */
	public function test_maps_legacy_payment_transactions_tab_url_to_native_view( string $client_tab, ?string $expected_view ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '/payments/transactions',
				'tab'  => $client_tab,
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( '/woopayments/transactions', $query['path'] );
		$this->assertSame( $expected_view, $query['view'] ?? null );
	}

	/**
	 * Client 11.1.0 Transactions tab names and the native view each one opens.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public function provider_client_transactions_tabs(): array {
		return array(
			'uncaptured' => array( 'uncaptured-page', 'uncaptured' ),
			'blocked'    => array( 'blocked-page', 'blocked' ),
			'all'        => array( 'transactions-page', null ),
			'unknown'    => array( 'review-page', null ),
		);
	}

	/**
	 * @testdox Should redirect every client 11.1.0 WC Admin route to a native route.
	 *
	 * Source: Fixtures/plugin-11.1.0-admin-routes.json (client/index.js:145-361, includes/admin/class-wc-payments-admin.php:230-611).
	 *
	 * @dataProvider provider_client_admin_routes
	 *
	 * @param string $legacy_path Client WC Admin route path.
	 * @param string $plugin_site Client source of the route.
	 */
	public function test_redirects_every_client_admin_route_to_a_native_route( string $legacy_path, string $plugin_site ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( $legacy_path ),
			)
		);

		$this->assertNotSame( '', $url, "Client route {$legacy_path} ({$plugin_site}) has no native redirect." );
		$this->assertStringContainsString( 'path=/woopayments/', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * Client 11.1.0 WC Admin routes, read from the pinned fixture.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_client_admin_routes(): array {
		$path = dirname( __DIR__, 4 ) . '/Payments/Providers/WooPayments/Fixtures/plugin-11.1.0-admin-routes.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$fixture = json_decode( (string) file_get_contents( $path ), true );

		$routes = array();
		foreach ( $fixture['admin_routes'] as $route ) {
			$routes[ $route['path'] ] = array( $route['path'], $route['plugin_site'] );
		}

		return $routes;
	}

	/**
	 * @testdox Should pin all 20 client 11.1.0 WC Admin routes in the fixture.
	 */
	public function test_client_admin_route_fixture_holds_every_client_route(): void {
		$routes = $this->provider_client_admin_routes();

		$this->assertCount( 20, $routes );
		$this->assertArrayNotHasKey( '/payments/accounts', $routes );
	}

	/**
	 * @testdox Should map legacy WooPayments setup URLs to native onboarding routes.
	 *
	 * @dataProvider provider_legacy_setup_redirects
	 *
	 * @param string $legacy_path Legacy WC Admin route path.
	 */
	public function test_maps_legacy_setup_urls_to_native_onboarding_routes( string $legacy_path ): void {
		$sut = $this->create_controller(
			true,
			array(
				'has_account'                            => false,
				'has_valid_account_for_admin_navigation' => false,
				'is_details_submitted'                   => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page'   => 'wc-admin',
				'path'   => rawurlencode( $legacy_path ),
				'source' => 'legacy-bookmark',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/onboarding', $url );
		$this->assertStringContainsString( 'source=legacy-bookmark', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @return array<string,array{legacy_path:string}>
	 */
	public function provider_legacy_setup_redirects(): array {
		return array(
			'connect'        => array( 'legacy_path' => '/payments/connect' ),
			'onboarding'     => array( 'legacy_path' => '/payments/onboarding' ),
			'onboarding kyc' => array( 'legacy_path' => '/payments/onboarding/kyc' ),
		);
	}

	/**
	 * @testdox Should map legacy WooPayments setup URLs to native overview routes for connected accounts.
	 *
	 * @dataProvider provider_legacy_setup_redirects
	 *
	 * @param string $legacy_path Legacy WC Admin route path.
	 */
	public function test_maps_legacy_setup_urls_to_native_overview_routes_for_connected_accounts( string $legacy_path ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page'   => 'wc-admin',
				'path'   => rawurlencode( $legacy_path ),
				'source' => 'legacy-bookmark',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/overview', $url );
		$this->assertStringContainsString( 'source=legacy-bookmark', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should map legacy WooPayments settings URLs to native provider settings routes.
	 *
	 * @dataProvider provider_legacy_settings_redirects
	 *
	 * @param string               $legacy_path       Legacy WC Admin route path.
	 * @param string               $native_path       Expected native route path.
	 * @param array<string,string> $expected_query    Expected query values.
	 * @param string|null          $expected_fragment Expected URL fragment.
	 */
	public function test_maps_legacy_settings_urls_to_native_provider_settings_routes( string $legacy_path, string $native_path, array $expected_query = array(), ?string $expected_fragment = null ): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page'         => 'wc-admin',
				'path'         => rawurlencode( $legacy_path ),
				'tab'          => 'legacy-tab',
				'existing_arg' => 'keep-me',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( $native_path, $query['path'] );
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
	public function provider_legacy_settings_redirects(): array {
		return array(
			'fraud protection'           => array(
				'legacy_path' => '/payments/fraud-protection',
				'native_path' => '/woopayments/settings/fraud-protection',
			),
			'multi currency setup'       => array(
				'legacy_path'       => '/payments/multi-currency-setup',
				'native_path'       => '/woopayments/settings',
				'expected_query'    => array(),
				'expected_fragment' => 'advanced',
			),
			'additional payment methods' => array(
				'legacy_path'       => '/payments/additional-payment-methods',
				'native_path'       => '/woopayments/settings',
				'expected_query'    => array(),
				'expected_fragment' => 'payment-methods',
			),
		);
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Documents URLs to the native Documents route when eligible.
	 */
	public function test_maps_legacy_payment_documents_url_to_native_route_when_documents_are_enabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_documents_enabled' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page'          => 'wc-admin',
				'path'          => '%2Fpayments%2Fdocuments',
				'document_id'   => 'doc_native',
				'document_type' => 'vat_invoice',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/documents', $url );
		$this->assertStringContainsString( 'document_id=doc_native', $url );
		$this->assertStringContainsString( 'document_type=vat_invoice', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Documents URLs to overview when Documents are not eligible.
	 */
	public function test_maps_legacy_payment_documents_url_to_overview_when_documents_are_disabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_documents_enabled' => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fdocuments',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Reports URLs to the native Reports route when Reports are enabled.
	 */
	public function test_maps_legacy_payment_reports_url_to_native_route_when_reports_are_enabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page'     => 'wc-admin',
				'path'     => '%2Fpayments%2Freports',
				'tab'      => 'fees',
				'view'     => 'fees',
				'currency' => 'USD',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/reports', $url );
		$this->assertStringContainsString( 'currency=USD', $url );
		$this->assertStringContainsString( 'view=fees', $url );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( 'fees', $query['report_tab'] );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Reports URLs to overview when Reports are disabled.
	 */
	public function test_maps_legacy_payment_reports_url_to_overview_when_reports_are_disabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Freports',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map legacy full-access WooPayments WC Admin URLs to overview for restricted accounts.
	 */
	public function test_maps_legacy_full_access_urls_to_overview_for_restricted_accounts(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_account_under_review' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fdeposits',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map denied legacy WooPayments URLs to the overview fallback when overview is allowed.
	 */
	public function test_maps_denied_legacy_payment_url_to_overview_fallback_when_available(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_account_under_review' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fpayouts',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map denied legacy WooPayments URLs to settings when overview is unavailable.
	 */
	public function test_maps_denied_legacy_payment_url_to_settings_fallback_when_overview_is_unavailable(): void {
		$sut = $this->create_controller(
			true,
			array(
				'has_account'                            => false,
				'has_valid_account_for_admin_navigation' => false,
				'is_details_submitted'                   => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Ftransactions',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertSame( '/woopayments/settings', $query['path'] );
	}

	/**
	 * @testdox Should map unknown legacy WooPayments method sections to native settings.
	 */
	public function test_maps_unknown_legacy_woopayments_method_section_to_native_settings(): void {
		$sut = $this->create_controller( true );

		$url = $sut->get_legacy_payment_path_redirect_url(
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
	 * @testdox Should redirect a registered split payment method section to native settings, as the client redirects every woocommerce_payments_ section.
	 */
	public function test_redirects_registered_split_method_section_to_native_settings(): void {
		$sut                                       = $this->create_controller( true );
		WC()->payment_gateways()->payment_gateways = array( 0 => $this->create_native_gateway( 'woocommerce_payments_klarna' ) );

		$url = $sut->get_legacy_payment_path_redirect_url(
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
	 * @testdox Should not redirect legacy WooPayments settings URLs during AJAX requests, as the client does not.
	 */
	public function test_does_not_redirect_legacy_paths_during_ajax_requests(): void {
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
			$sut->redirect_legacy_payment_paths();
			$this->assertTrue( wp_doing_ajax() );
		} finally {
			remove_all_filters( 'wp_doing_ajax' );
			remove_all_filters( 'wp_redirect' );
			$_GET = array();
		}
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Card Readers URLs to the native route when Card Readers are available.
	 */
	public function test_maps_legacy_payment_card_readers_url_to_native_route_when_card_readers_are_available(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_card_present_eligible'   => true,
				'has_card_readers_available' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fcard-readers',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/card-readers', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Card Readers URLs to overview when Card Readers are unavailable.
	 */
	public function test_maps_legacy_payment_card_readers_url_to_overview_when_card_readers_are_unavailable(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_card_present_eligible'   => true,
				'has_card_readers_available' => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fcard-readers',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Capital URLs to the native route when Capital is available.
	 */
	public function test_maps_legacy_payment_capital_url_to_native_route_when_capital_is_available(): void {
		$sut = $this->create_controller(
			true,
			array(
				'has_previous_capital_loans' => true,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Floans',
			)
		);

		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
		$this->assertStringContainsString( 'path=/woopayments/loans', $url );
		$this->assertStringNotContainsString( 'page=wc-admin', $url );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin Capital URLs to overview when Capital is unavailable.
	 */
	public function test_maps_legacy_payment_capital_url_to_overview_when_capital_is_unavailable(): void {
		$sut = $this->create_controller(
			true,
			array(
				'has_previous_capital_loans' => false,
			)
		);

		$url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Floans',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] );
	}

	/**
	 * @testdox Should map legacy WooPayments WC Admin paths by account state when the native gateway is disabled.
	 */
	public function test_maps_legacy_payment_paths_by_account_state_when_native_gateway_is_disabled(): void {
		$sut = $this->create_controller(
			true,
			array(
				'is_gateway_enabled' => false,
			)
		);

		$settings_url = $sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Fsettings',
			)
		);

		$this->assertStringContainsString( 'path=/woopayments/settings', $settings_url );
		$this->assertStringContainsString(
			'path=/woopayments/transactions',
			$sut->get_legacy_payment_path_redirect_url(
				array(
					'page' => 'wc-admin',
					'path' => '%2Fpayments%2Ftransactions',
				)
			)
		);
	}

	/**
	 * @testdox Should not redirect unrelated WC Admin paths.
	 */
	public function test_does_not_map_unrelated_wc_admin_paths(): void {
		$sut = $this->create_controller( true );

		$this->assertSame(
			'',
			$sut->get_legacy_payment_path_redirect_url(
				array(
					'page' => 'wc-admin',
					'path' => '/analytics/revenue',
				)
			)
		);
	}

	/**
	 * @testdox Should not add WooPayments navigation when native runtime does not own payments.
	 */
	public function test_does_not_add_menu_items_when_native_runtime_does_not_own_payments(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( false );
		$sut->add_menu_items();

		$this->assertSame( array(), $this->get_payments_submenu_items() );
	}

	/**
	 * @testdox Should not add WooPayments navigation for users without manage_woocommerce.
	 */
	public function test_does_not_add_menu_items_without_manage_woocommerce_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$sut = $this->create_controller( true );
		$sut->add_menu_items();

		$this->assertSame( array(), $this->get_payments_submenu_items() );
	}

	/**
	 * @testdox Should add account-state WooPayments navigation when the native gateway is disabled.
	 */
	public function test_adds_account_state_menu_items_when_native_gateway_is_disabled(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(
				'is_gateway_enabled' => false,
			)
		);
		$sut->add_menu_items();

		$this->assertSame(
			array(
				'Overview',
				'Payouts',
				'Transactions',
				'Disputes',
				'Settings',
			),
			array_map(
				static function ( array $item ): string {
					return wp_strip_all_tags( $item[0] );
				},
				$this->get_payments_submenu_items()
			)
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

		$sut = $this->create_controller( true, array( 'has_valid_admin_account_state' => true ), array(), null, false );
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
	 * @testdox Should add full WooPayments navigation for a valid native account.
	 */
	public function test_adds_full_menu_items_for_valid_native_account(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(
				'is_card_present_eligible'      => true,
				'has_card_readers_available'    => true,
				'has_previous_capital_loans'    => true,
				'is_documents_enabled'          => false,
				'has_valid_admin_account_state' => true,
			)
		);
		$sut->add_menu_items();

		$items = $this->get_payments_submenu_items();

		$this->assertSame(
			array(
				'Overview',
				'Payouts',
				'Transactions',
				'Disputes',
				'Card Readers',
				'Capital Loans',
				'Settings',
			),
			array_column( $items, 0 )
		);
		$this->assertSame(
			array(
				$this->get_settings_url( '/woopayments/overview' ),
				$this->get_settings_url( '/woopayments/payouts' ),
				$this->get_settings_url( '/woopayments/transactions' ),
				$this->get_settings_url( '/woopayments/disputes' ),
				$this->get_settings_url( '/woopayments/card-readers' ),
				$this->get_settings_url( '/woopayments/loans' ),
				$this->get_settings_url( '/woopayments/settings' ),
			),
			array_column( $items, 2 )
		);
		$this->assertStringNotContainsString( 'wc-admin&path=/payments', implode( "\n", array_column( $items, 2 ) ) );
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
		$this->assertStringContainsString( '( function () {var item = document.querySelector( "#adminmenu .wp-submenu li.current" );', $this->get_open_payments_menu_output( $sut ) );
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
	 * @testdox Should leave the admin menu highlight alone outside the native WooPayments pages.
	 * @dataProvider provider_requests_outside_native_payments_pages
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_change_the_menu_highlight_outside_native_payments_pages( array $request ): void {
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
	 * Requests that are not native WooPayments pages.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_requests_outside_native_payments_pages(): array {
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
	 * @testdox Should add a Disputes badge and direct the submenu to awaiting-response disputes.
	 */
	public function test_adds_disputes_badge_and_awaiting_response_filter(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(),
			array(
				'disputes_awaiting_response' => 4,
			)
		);
		$sut->add_menu_items();

		$disputes_item = $this->get_submenu_item_by_title( 'Disputes' );

		$this->assertStringContainsString( 'wcpay-menu-badge awaiting-mod count-4', $disputes_item[0] );
		$this->assertStringContainsString( '<span class="plugin-count">4</span>', $disputes_item[0] );
		$this->assertStringContainsString( 'path=/woopayments/disputes', $disputes_item[2] );
		$this->assertStringContainsString( 'filter=awaiting_response', $disputes_item[2] );
	}

	/**
	 * @testdox Should omit the Disputes badge and filter when there are no awaiting-response disputes.
	 */
	public function test_omits_disputes_badge_for_zero_count(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true );
		$sut->add_menu_items();

		$disputes_item = $this->get_submenu_item_by_title( 'Disputes' );

		$this->assertSame( 'Disputes', $disputes_item[0] );
		$this->assertSame( $this->get_settings_url( '/woopayments/disputes' ), $disputes_item[2] );
	}

	/**
	 * @testdox Should add a Transactions badge when uncaptured transactions exist.
	 */
	public function test_adds_transactions_badge_for_uncaptured_transactions(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(),
			array(
				'uncaptured_transactions' => 3,
			)
		);
		$sut->add_menu_items();

		$transactions_item = $this->get_submenu_item_by_title( 'Transactions' );

		$this->assertStringContainsString( 'wcpay-menu-badge awaiting-mod count-3', $transactions_item[0] );
		$this->assertStringContainsString( '<span class="plugin-count">3</span>', $transactions_item[0] );
		$this->assertSame( $this->get_settings_url( '/woopayments/transactions' ), $transactions_item[2] );
	}

	/**
	 * @testdox Should omit the Transactions badge when there are no uncaptured transactions.
	 */
	public function test_omits_transactions_badge_for_zero_count(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true );
		$sut->add_menu_items();

		$transactions_item = $this->get_submenu_item_by_title( 'Transactions' );

		$this->assertSame( 'Transactions', $transactions_item[0] );
		$this->assertSame( $this->get_settings_url( '/woopayments/transactions' ), $transactions_item[2] );
	}

	/**
	 * @testdox Should add Documents navigation only when Documents are eligible while Reports are disabled by default.
	 */
	public function test_adds_documents_menu_item_only_when_documents_are_enabled_and_reports_are_disabled_by_default(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(
				'is_documents_enabled' => false,
			)
		);
		$sut->add_menu_items();

		$titles = array_map(
			static function ( array $item ): string {
				return trim( wp_strip_all_tags( $item[0] ) );
			},
			$this->get_payments_submenu_items()
		);

		$this->assertNotContains( 'Reports', $titles );
		$this->assertNotContains( 'Documents', $titles );

		$sut = $this->create_controller(
			true,
			array(
				'is_documents_enabled' => true,
			)
		);
		$sut->add_menu_items();

		$titles         = array_map(
			static function ( array $item ): string {
				return trim( wp_strip_all_tags( $item[0] ) );
			},
			$this->get_payments_submenu_items()
		);
		$documents_item = $this->get_submenu_item_by_title( 'Documents' );

		$this->assertNotContains( 'Reports', $titles );
		$this->assertContains( 'Documents', $titles );
		$this->assertSame( $this->get_settings_url( '/woopayments/documents' ), $documents_item[2] );
	}

	/**
	 * @testdox Should add Reports after Disputes for a valid native account when Reports are enabled, as the client's menu shows it.
	 */
	public function test_adds_reports_menu_item_after_disputes_when_reports_are_enabled(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$sut->add_menu_items();

		$items = $this->get_payments_submenu_items();

		$this->assertSame(
			array(
				'Overview',
				'Payouts',
				'Transactions',
				'Disputes',
				'Reports',
				'Settings',
			),
			array_column( $items, 0 )
		);
		$this->assertSame( $this->get_settings_url( '/woopayments/reports' ), $this->get_submenu_item_by_title( 'Reports' )[2] );
	}

	/**
	 * @testdox Should add reduced WooPayments navigation for rejected and under-review native accounts.
	 * @dataProvider provider_restricted_account_states
	 *
	 * @param array<string,bool> $account_state Account state overrides.
	 */
	public function test_adds_reduced_menu_items_for_restricted_account_states( array $account_state ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true, $account_state );
		$sut->add_menu_items();

		$items = $this->get_payments_submenu_items();

		$this->assertSame(
			array(
				'Overview',
				'Transactions',
				'Disputes',
			),
			array_column( $items, 0 )
		);
		$this->assertSame(
			array(
				$this->get_settings_url( '/woopayments/overview' ),
				$this->get_settings_url( '/woopayments/transactions' ),
				$this->get_settings_url( '/woopayments/disputes' ),
			),
			array_column( $items, 2 )
		);
		$this->assertNotContains( 'Reports', array_column( $items, 0 ) );
	}

	/**
	 * Restricted account state provider.
	 *
	 * @return array<string,array{account_state:array<string,bool>}>
	 */
	public function provider_restricted_account_states(): array {
		return array(
			'rejected account'     => array(
				'account_state' => array(
					'is_account_rejected' => true,
				),
			),
			'under-review account' => array(
				'account_state' => array(
					'is_account_under_review' => true,
				),
			),
		);
	}

	/**
	 * @testdox Should add onboarding navigation for native accounts that are not ready.
	 * @dataProvider provider_not_ready_account_states
	 *
	 * @param array<string,bool> $account_state Account state overrides.
	 * @param string             $expected_title Expected menu item title.
	 */
	public function test_adds_onboarding_menu_items_for_accounts_that_are_not_ready( array $account_state, string $expected_title ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$sut = $this->create_controller( true, $account_state );
		$sut->add_menu_items();

		$items = $this->get_payments_submenu_items();

		$this->assertSame( array( $expected_title ), array_column( $items, 0 ) );
		$this->assertSame( array( $this->get_settings_url( '/woopayments/onboarding' ) ), array_column( $items, 2 ) );
	}

	/**
	 * Not-ready account state provider.
	 *
	 * @return array<string,array{account_state:array<string,bool>,expected_title:string}>
	 */
	public function provider_not_ready_account_states(): array {
		return array(
			'not connected'   => array(
				'account_state'  => array(
					'has_account'                   => false,
					'is_details_submitted'          => false,
					'has_valid_admin_account_state' => false,
				),
				'expected_title' => 'Onboarding',
			),
			'details missing' => array(
				'account_state'  => array(
					'has_account'                   => true,
					'is_details_submitted'          => false,
					'has_valid_admin_account_state' => false,
				),
				'expected_title' => 'Continue onboarding',
			),
		);
	}

	/**
	 * @testdox Should register the onboarding redirect after the plugin's account redirects, like client 11.1.0.
	 */
	public function test_registers_onboarding_redirect_at_client_admin_init_priority(): void {
		$sut = $this->create_controller( true );

		$sut->register();

		// Client 11.1.0 WC_Payments_Admin::init_hooks() hooks its child-page redirect on admin_init priority 16.
		$this->assertSame( 16, has_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ) ) );

		remove_action( 'admin_menu', array( $sut, 'add_menu_items' ), 70 );
		remove_action( 'admin_init', array( $sut, 'redirect_legacy_payment_paths' ), 10 );
		remove_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ), 16 );
		remove_action( 'template_redirect', array( $sut, 'redirect_vat_details_request' ), 10 );
		remove_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ), 10 );
	}

	/**
	 * @testdox Should not register the onboarding redirect when native runtime does not own payments.
	 */
	public function test_does_not_register_onboarding_redirect_when_native_runtime_does_not_own_payments(): void {
		$sut = $this->create_controller( false );

		$sut->register();

		$this->assertFalse( has_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
	}

	/**
	 * @testdox Should redirect native WooPayments admin pages to onboarding when there is no account.
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
	 * @testdox Should match native WooPayments admin paths by prefix, like the client's unanchored path regex.
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
	 * @testdox Should send the referer-derived onboarding source with the Overview redirect, like client 11.1.0.
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
			'native payouts referer'    => array( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts' ), array(), 'wcpay-payouts-page' ),
			'payments settings referer' => array( admin_url( 'admin.php?page=wc-settings&tab=checkout' ), array(), 'wcadmin-settings-page' ),
			'from param over referer'   => array( admin_url( 'admin.php?page=wc-admin&task=payments' ), array( 'from' => 'WCPAY_PAYOUTS' ), 'wcpay-payouts-page' ),
		);
	}

	/**
	 * @testdox Should redirect to onboarding when the account is valid but the WordPress.com connection is not working.
	 */
	public function test_redirects_to_onboarding_without_a_working_connection(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, array(), array(), null, false );

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
	 * @testdox Should not redirect native WooPayments admin pages with a valid account and a working connection.
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
		$account_service->init( new LegacyProxy() );

		$sut = $this->create_controller_with_account_service( $account_service, true );

		$this->assertSame( '', $this->run_onboarding_redirect( $sut, $path ) );
	}

	/**
	 * @testdox Should not redirect the onboarding route itself, so the redirect cannot loop.
	 */
	public function test_does_not_redirect_the_onboarding_route(): void {
		$this->set_admin_user();
		$sut = $this->create_controller( true, $this->get_no_account_state(), array(), null, false );

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
	 * @testdox Should not redirect requests outside the native WooPayments admin routes.
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
			$sut->maybe_redirect_to_onboarding();
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
	 * @param bool                                  $native_register          Whether native should own menu registration.
	 * @param array<string,mixed>                   $account_state            Account state overrides.
	 * @param array<string,int>                     $badge_counts             Badge count overrides.
	 * @param WooPaymentsApplePayDomainService|null $apple_pay_domain_service Optional Apple Pay domain service; defaults to one with no notice due.
	 * @param bool                                  $connected                Whether the WordPress.com connection works.
	 * @return WooPaymentsAdminNavigationController
	 */
	private function create_controller( bool $native_register, array $account_state = array(), array $badge_counts = array(), ?WooPaymentsApplePayDomainService $apple_pay_domain_service = null, bool $connected = true ): WooPaymentsAdminNavigationController {
		$class_name = WooPaymentsAdminNavigationController::class;
		$this->assertTrue( class_exists( $class_name ), 'WooPayments admin navigation controller should exist.' );

		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$account_service = $this->create_account_service( $account_state );
		$badge_service   = $this->create_badge_service( $badge_counts );
		$api_client      = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		$controller          = new $class_name();
		$onboarding_redirect = new WooPaymentsOnboardingRedirect();
		$onboarding_redirect->init( $arbiter, $api_client, $account_service );
		$controller->init( $arbiter, $account_service, $badge_service, $apple_pay_domain_service ?? $this->createMock( WooPaymentsApplePayDomainService::class ), $onboarding_redirect );
		$this->controllers[] = $controller;

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
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( $connected );
		// The account read needs the same connection: without one it returns no account, like the client.
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );

		$controller          = new WooPaymentsAdminNavigationController();
		$onboarding_redirect = new WooPaymentsOnboardingRedirect();
		$onboarding_redirect->init( $arbiter, $api_client, $account_service );
		$controller->init( $arbiter, $account_service, $this->create_badge_service(), $this->createMock( WooPaymentsApplePayDomainService::class ), $onboarding_redirect );
		$this->controllers[] = $controller;

		return $controller;
	}

	/**
	 * Create a constructor-free native gateway identity.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return NativeWooPaymentsGateway
	 */
	private function create_native_gateway( string $gateway_id ): NativeWooPaymentsGateway {
		$gateway     = $this->getMockBuilder( NativeWooPaymentsGateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = $gateway_id;

		return $gateway;
	}

	/**
	 * Replace the container's Overview service with one that returns the shell, or that must not be asked for it.
	 *
	 * @param array<string,mixed>|null $shell The shell, or null when building it must not happen.
	 * @return void
	 */
	private function replace_overview_service( ?array $shell ): void {
		$overview_service = $this->getMockBuilder( WooPaymentsOverviewService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_overview' ) )
			->getMock();
		if ( null === $shell ) {
			$overview_service->expects( $this->never() )->method( 'get_overview' );
		} else {
			$overview_service->method( 'get_overview' )->willReturn( $shell );
		}
		wc_get_container()->replace( WooPaymentsOverviewService::class, $overview_service );
	}

	/**
	 * Replace the container's WooPayments service with one that returns the account summary, or that must not be asked for it.
	 *
	 * @param array<string,mixed>|null $summary The account summary, or null when reading it must not happen.
	 * @return void
	 */
	private function replace_woopayments_service( ?array $summary ): void {
		$woopayments_service = $this->getMockBuilder( WooPaymentsService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_summary' ) )
			->getMock();
		if ( null === $summary ) {
			$woopayments_service->expects( $this->never() )->method( 'get_account_summary' );
		} else {
			$woopayments_service->method( 'get_account_summary' )->willReturn( $summary );
		}
		wc_get_container()->replace( WooPaymentsService::class, $woopayments_service );
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
	 * Create a real Apple Pay domain service with Apple Pay enabled.
	 *
	 * @param bool $live_account Whether the account is live.
	 * @return WooPaymentsApplePayDomainService
	 */
	private function create_apple_pay_domain_service( bool $live_account ): WooPaymentsApplePayDomainService {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_live_account', 'is_payment_request_method_enabled' ) )
			->getMock();
		$account_service->method( 'has_live_account' )->willReturn( $live_account );
		$account_service->method( 'is_payment_request_method_enabled' )->willReturn( true );

		$service = new WooPaymentsApplePayDomainService();
		$service->init(
			$arbiter,
			$this->createMock( WooPaymentsApiClient::class ),
			$account_service,
			$this->createMock( WooPaymentsActionSchedulerService::class )
		);

		return $service;
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
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';

		return $controller->preload_shared_settings( array() )['woopaymentsSettings']['adminRouteAvailability'];
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
