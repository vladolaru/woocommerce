<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\Jetpack\Identity_Crisis;
use Automattic\Jetpack\Status\Cache as StatusCache;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NavigationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\SharedSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OnboardingRedirect;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\FixedTrackingInfoService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments admin pages' shared settings preload.
 */
class SharedSettingsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should add the WooPayments admin pages' data to the admin shared settings.
	 */
	public function test_registers_the_shared_settings_filter(): void {
		$sut = $this->create_shared_settings( true );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ) ) );

		remove_filter( 'woocommerce_admin_shared_settings', array( $sut, 'preload_shared_settings' ), 10 );
	}

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

		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate admin menu assertions in this test.
		$submenu = array();
		wp_set_current_user( 0 );
		$this->intercepted_redirect = '';
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'wcpay_js_settings' );
		unset( $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['section'], $_GET['from'], $_SERVER['HTTP_REFERER'] );
		delete_option( 'wcpay_account_data' );
		delete_option( 'wcpay_onboarding_test_mode' );
		delete_option( 'wcpay_next_deposit_notice_dismissed' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country' ) as $store_option ) {
			delete_option( $store_option );
		}
		$this->reset_container_replacements();

		parent::tearDown();
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

		$settings = $this->create_shared_settings( true )->preload_shared_settings( array() )['woopaymentsSettings'];

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
		$sut          = $this->create_shared_settings(
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
	 * @testdox Should preload the account's default currency, which the Balance report requests.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1053` (`accountDefaultCurrency` in `wcpaySettings`), read by
	 * `client/reports/balance/index.tsx:177`. The cached account's `store_currencies.default` follows the platform account
	 * payload (wpcom `class-accounts-controller.php:1151-1153`).
	 */
	public function test_preloads_account_default_currency_for_the_balance_report(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_shared_settings(
			true,
			array(
				'get_cached_account_data' => array(
					'store_currencies' => array(
						'default'   => 'PLN',
						'supported' => array( 'pln' ),
					),
				),
			)
		);

		$this->assertSame( 'pln', $sut->preload_shared_settings( array() )['woopaymentsSettings']['accountDefaultCurrency'] );
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
		$sut          = $this->create_shared_settings( true );

		$this->assertSame( get_option( 'admin_email' ), $sut->preload_shared_settings( array() )['woopaymentsSettings']['currentUserEmail'] );

		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'merchant@example.test' ) ) );

		$this->assertSame( 'merchant@example.test', $sut->preload_shared_settings( array() )['woopaymentsSettings']['currentUserEmail'] );
	}

	/**
	 * @testdox Should preload the REST root the settings page builds file URLs from.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1048` (`restUrl`, for stores on Plain permalinks), which
	 * `client/settings/express-checkout-settings/woopay-preview.js:428` concatenates for the store logo.
	 */
	public function test_preloads_rest_url_for_file_urls(): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_shared_settings( true );

		$this->assertSame( get_rest_url( null, '' ), $sut->preload_shared_settings( array() )['woopaymentsSettings']['restUrl'] );
	}

	/**
	 * Pages of the payments settings, the tracking info cache state, the platform requests expected and whether the page carries
	 * the tracking info.
	 *
	 * @return array<string,array{0:array<string,string>,1:string,2:int,3:bool}>
	 */
	public function provide_payments_settings_pages(): array {
		return array(
			'a WooPayments admin route, cold cache'       => array( array( 'path' => '/woopayments/transactions' ), 'cold', 1, true ),
			'the WooPayments settings route, cold cache'  => array( array( 'path' => '/woopayments/settings' ), 'cold', 1, true ),
			'the WooPayments section, cold cache'         => array( array( 'section' => 'woocommerce_payments' ), 'cold', 1, true ),
			'a WooPayments method section, cold cache'    => array( array( 'section' => 'woocommerce_payments_klarna' ), 'cold', 1, true ),
			'the bank transfer settings, cold cache'      => array( array( 'section' => 'bacs' ), 'cold', 0, false ),
			'the bank transfer settings, warm cache'      => array( array( 'section' => 'bacs' ), 'warm', 0, false ),
			'the payments settings main page, cold cache' => array( array(), 'cold', 0, false ),
			'the payments settings main page, warm cache' => array( array(), 'warm', 0, true ),
			'the payments settings main page, errored cache' => array( array(), 'errored', 0, false ),
		);
	}

	/**
	 * Client 11.1.0 localizes the cached platform tracking info only on its own pages (`class-wc-payments-admin.php:754`; the settings
	 * check `class-wc-payments-utils.php:653` requires a `woocommerce_payments` section), and its admin Tracks recorder adds
	 * `hosting_provider` from it (`class-wc-payments-admin.php:1066`, `client/tracks/index.ts:44`); a read fetches a missing entry.
	 * The payments settings main page opens the WooPayments settings without a reload (its Manage button), so it carries the info
	 * as cached, and never fetches it.
	 *
	 * @testdox Should read and preload the platform tracking info as the page needs: $_dataName.
	 * @dataProvider provide_payments_settings_pages
	 *
	 * @param array<string,string> $query          Extra query arguments of the payments settings request.
	 * @param string               $cache          The tracking info cache: cold, warm (fresh) or errored (a failed fetch kept old data).
	 * @param int                  $platform_calls Expected platform tracking info requests.
	 * @param bool                 $expect_preload Whether the page carries the tracking info.
	 */
	public function test_preloads_tracking_info_as_the_page_needs( array $query, string $cache, int $platform_calls, bool $expect_preload ): void {
		$_GET       = array_merge(
			array(
				'page' => 'wc-settings',
				'tab'  => 'checkout',
			),
			$query
		);
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Number of platform tracking info requests.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * The site is connected.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Answer the platform's tracking info (wpcom class-tracking-controller.php:233-239).
			 *
			 * @return array<string,mixed>
			 */
			public function get_tracking_info(): array {
				++$this->calls;

				return FixedTrackingInfoService::TRACKING_INFO;
			}
		};
		$cache_db   = new WooPaymentsDatabaseCache();
		$cache_db->init( new LegacyProxy() );
		$service = new WooPaymentsTrackingInfoService();
		$service->init( $api_client, $cache_db );
		wc_get_container()->replace( WooPaymentsTrackingInfoService::class, $service );
		delete_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );
		if ( 'cold' !== $cache ) {
			update_option(
				WooPaymentsDatabaseCache::TRACKING_INFO_KEY,
				array(
					'data'               => FixedTrackingInfoService::TRACKING_INFO,
					'fetched'            => time(),
					'errored'            => 'errored' === $cache,
					'consecutive_errors' => 'errored' === $cache ? 1 : 0,
				),
				false
			);
		}
		$sut = $this->create_shared_settings( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $platform_calls, $api_client->calls );
		if ( $expect_preload ) {
			$this->assertSame( FixedTrackingInfoService::TRACKING_INFO, $settings['woopaymentsSettings']['trackingInfo'] ?? null );
		} else {
			$this->assertArrayNotHasKey( 'trackingInfo', $settings['woopaymentsSettings'] );
		}
	}

	/**
	 * Outside the Payments settings pages nothing is preloaded, so nothing reads the tracking info.
	 *
	 * @testdox Should preload nothing and not read the tracking info outside the Payments settings page.
	 * @dataProvider provide_requests_outside_payments_settings
	 *
	 * @param array<string,string> $query         Query arguments of the admin request.
	 * @param array<string,mixed>  $account_state Account state overrides.
	 */
	public function test_does_not_preload_outside_payments_settings( array $query, array $account_state ): void {
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$service = FixedTrackingInfoService::install();
		$sut     = $this->create_shared_settings( true, $account_state );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( array(), $settings );
		$this->assertSame( 0, $service->reads );
	}

	/**
	 * Admin requests outside the Payments settings page.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,mixed>}>
	 */
	public function provide_requests_outside_payments_settings(): array {
		return array(
			'WooCommerce Admin with the checkout tab' => array(
				array(
					'page' => 'wc-admin',
					'tab'  => 'checkout',
				),
				array( 'is_reports_enabled' => true ),
			),
			'the orders page'                         => array( array( 'page' => 'wc-orders' ), array() ),
		);
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
		$sut          = $this->create_shared_settings( true, array(), array( 'manual_capture' => (int) $manual_capture ) );

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
		$sut          = $this->create_shared_settings( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $expected, $settings['woopaymentsSettings']['isNextDepositNoticeDismissed'] );
	}

	/**
	 * @testdox Should preload whether the store is in Safe Mode, which shows the Safe Mode notice on the Overview (in Safe Mode: $in_safe_mode).
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1026` (`isJetpackIdcActive`) and `components/jetpack-idc-notice/index.tsx`.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $in_safe_mode Whether the connection holds a Safe Mode error for the store's current URLs.
	 */
	public function test_preloads_safe_mode_flag( bool $in_safe_mode ): void {
		if ( $in_safe_mode && is_multisite() ) {
			$this->markTestSkipped( 'Jetpack does not handle Safe Mode on multisite by default (Identity_Crisis::should_handle_idc()).' );
		}
		// The test site's local domain would put Jetpack in offline mode, where Safe Mode never applies.
		add_filter( 'jetpack_offline_mode', '__return_false' );
		StatusCache::clear();
		\Jetpack_Options::update_option( 'id', 123 );
		\Jetpack_Options::update_option( 'blog_token', 'tokenkey.blogsecret' );
		( new JetpackConnectionManager() )->reset_connection_status();
		if ( $in_safe_mode ) {
			// The error the connection stores from a platform answer that reports a mismatched URL (class-identity-crisis.php:315-336).
			\Jetpack_Options::update_option(
				'sync_error_idc',
				Identity_Crisis::get_sync_error_idc_option(
					array(
						'error_code'    => 'jetpack_url_mismatch',
						'wpcom_siteurl' => 'https://production-store.example',
						'wpcom_home'    => 'https://production-store.example',
					)
				)
			);
		}
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_shared_settings( true );

		try {
			$settings = $sut->preload_shared_settings( array() );
		} finally {
			\Jetpack_Options::delete_option( array( 'id', 'blog_token', 'sync_error_idc' ) );
			delete_transient( 'jetpack_idc_local' );
			( new JetpackConnectionManager() )->reset_connection_status();
			StatusCache::clear();
		}

		$this->assertSame( $in_safe_mode, $settings['woopaymentsSettings']['isJetpackIdcActive'] );
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
		$sut          = $this->create_shared_settings( true );

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
		$sut          = $this->create_shared_settings( true );

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
		$sut = $this->create_shared_settings( true, array(), array(), $this->create_apple_pay_domain_service( true ) );

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
		$sut = $this->create_shared_settings( true, array(), array(), $this->create_apple_pay_domain_service( true ) );

		try {
			$settings = $sut->preload_shared_settings( array() );
		} finally {
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertArrayNotHasKey( 'applePayDomainError', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preload the account's loans for the transactions Loan filter, keeping only the loan strings, and no loans without Capital data.
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1031` (`accountLoans` from `WC_Payments_Account::get_capital()`, every WooPayments page)
	 * and `client/transactions/filters/config.ts:555-571` (`<loan id>|<status>` strings). `WC_Payments_Account::get_capital()` falls
	 * back to no loans and no active or previous loan.
	 *
	 * @dataProvider provide_account_loans_cases
	 *
	 * @param string|null         $path     The settings page route, or null for none.
	 * @param array<string,mixed> $capital  The cached account's Capital data.
	 * @param array<string,mixed> $expected The preloaded loans.
	 * @param string              $message  Failure message.
	 */
	public function test_preloads_account_loans_for_the_transactions_loan_filter( ?string $path, array $capital, array $expected, string $message ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		if ( null !== $path ) {
			$_GET['path'] = $path;
		}
		$sut = $this->create_shared_settings(
			true,
			array(
				'get_cached_account_data' => array(
					'capital' => $capital,
				),
			)
		);

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $expected, $settings['woopaymentsSettings']['accountLoans'], $message );
	}

	/**
	 * Cached Capital data and the loans it preloads.
	 *
	 * @return array<string,array{0:string|null,1:array<string,mixed>,2:array<string,mixed>,3:string}>
	 */
	public function provide_account_loans_cases(): array {
		return array(
			'loans with a non-string entry and an active loan' => array(
				'/woopayments/transactions',
				array(
					'loans'           => array(
						3 => 'flxln_active|active',
						5 => array( 'not' => 'a loan' ),
						7 => 'flxln_paid|paid',
					),
					'has_active_loan' => true,
				),
				array(
					'loans'              => array( 'flxln_active|active', 'flxln_paid|paid' ),
					'has_active_loan'    => true,
					'has_previous_loans' => false,
				),
				'The loans page requests the active loan summary only when has_active_loan, as the client mounts its card (capital/index.tsx:216).',
			),
			'loans that are not a list' => array(
				null,
				array( 'loans' => 'flxln_active|active' ),
				array(
					'loans'              => array(),
					'has_active_loan'    => false,
					'has_previous_loans' => false,
				),
				'',
			),
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
		$sut          = $this->create_shared_settings(
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
		$sut = $this->create_shared_settings( true );

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
		$this->replace_overview_service(
			null,
			array(
				'account' => null,
				'urls'    => array(),
			)
		);
		$sut = $this->create_shared_settings( true );

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
		$sut = $this->create_shared_settings( true, $this->get_no_account_state() );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'overviewShell', $settings['woopaymentsSettings'] );
	}

	/**
	 * Client 11.1.0 logs each failure to read its JS settings as a warning line, gated by its logging setting, and lets a PHP
	 * error fatal (`includes/admin/class-wc-payments-admin.php:973-988`, `src/Internal/Logger.php:64-90`). The page still
	 * loads here, so an error is logged whatever the setting. The exception's message stays out of the line.
	 *
	 * @testdox Should log the $label failure as a $expected_level line, and still leave the data to the page's request.
	 * @dataProvider provide_preload_failures
	 *
	 * @param string      $label            The failure.
	 * @param string      $path             The settings page route.
	 * @param string      $method           The Overview service method that fails.
	 * @param \Throwable  $throwable        What it throws.
	 * @param bool        $logging          Whether WooPayments logging is on.
	 * @param string|null $expected_level   The expected line's level, or null for no line.
	 */
	public function test_logs_preload_failures( string $label, string $path, string $method, \Throwable $throwable, bool $logging, ?string $expected_level ): void {
		unset( $label );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = $path;
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $logging ? 'yes' : 'no' ) );
		$overview_service = $this->getMockBuilder( OverviewService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_overview', 'get_account_summary' ) )
			->getMock();
		$overview_service->method( $method )->willThrowException( $throwable );
		wc_get_container()->replace( OverviewService::class, $overview_service );
		$logger = RecordingWcLogger::install();

		$settings = $this->create_shared_settings( true )->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'get_overview' === $method ? 'overviewShell' : 'accountMode', $settings['woopaymentsSettings'] );
		$lines = array();
		foreach ( $logger->lines as $index => $line ) {
			if ( 'woopayments' === $line[2] ) {
				$lines[] = array( $line[0], $logger->contexts[ $index ]['exception'] ?? null );
				$this->assertStringNotContainsString( $throwable->getMessage(), (string) wp_json_encode( $logger->contexts[ $index ] ) );
			}
		}
		$this->assertSame( null === $expected_level ? array() : array( array( $expected_level, get_class( $throwable ) ) ), $lines );
	}

	/**
	 * Preload failures and the line each one writes.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:\Throwable,4:bool,5:string|null}>
	 */
	public function provide_preload_failures(): array {
		return array(
			'Overview shell, logging on'        => array( 'Overview shell', '/woopayments/overview', 'get_overview', new \Exception( 'Probe failure text' ), true, 'warning' ),
			'Overview shell, logging off'       => array( 'Overview shell', '/woopayments/overview', 'get_overview', new \Exception( 'Probe failure text' ), false, null ),
			'Overview shell error, logging off' => array( 'Overview shell', '/woopayments/overview', 'get_overview', new \TypeError( 'Probe type error' ), false, 'error' ),
			'account mode, logging on'          => array( 'account mode', '/woopayments/settings', 'get_account_summary', new \Exception( 'Probe failure text' ), true, 'warning' ),
			'account mode, logging off'         => array( 'account mode', '/woopayments/settings', 'get_account_summary', new \Exception( 'Probe failure text' ), false, null ),
			'account mode error, logging off'   => array( 'account mode', '/woopayments/settings', 'get_account_summary', new \TypeError( 'Probe type error' ), false, 'error' ),
		);
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
		$this->replace_overview_service(
			null,
			array(
				'account'   => array(
					'connected'  => true,
					'live'       => false,
					'test_drive' => true,
					'sandbox'    => false,
				),
				'documents' => array( 'enabled' => false ),
				'urls'      => array(
					'setup' => 'https://example.com/setup',
				),
			)
		);
		$sut = $this->create_shared_settings( true );

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
		$sut = $this->create_shared_settings( true );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertArrayNotHasKey( 'accountMode', $settings['woopaymentsSettings'] );
	}

	/**
	 * @testdox Should preload the trimmed Balance report identity from the WooPayments account, falling back to the store name and an empty account ID.
	 * @dataProvider provide_balance_report_identity_cases
	 *
	 * @param string|null          $blogname      The site title to store, or null to leave it.
	 * @param array<string,mixed>  $account_state Account state overrides.
	 * @param array<string,string> $expected      The preloaded identity.
	 */
	public function test_preloads_balance_report_identity( ?string $blogname, array $account_state, array $expected ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		if ( null !== $blogname ) {
			update_option( 'blogname', $blogname );
		}
		$sut = $this->create_shared_settings( true, $account_state );

		$settings = $sut->preload_shared_settings( array() );

		$this->assertSame( $expected, $settings['woopaymentsSettings']['balanceReportIdentity'] );
	}

	/**
	 * Account identities and the Balance report identity they preload.
	 *
	 * @return array<string,array{0:string|null,1:array<string,mixed>,2:array<string,string>}>
	 */
	public function provide_balance_report_identity_cases(): array {
		return array(
			'padded business name and account ID' => array(
				null,
				array(
					'get_cached_account_data' => array(
						'business_profile' => array(
							'name' => ' Native Merchant ',
						),
					),
					'get_account_id'          => ' acct_native_123 ',
				),
				array(
					'businessName' => 'Native Merchant',
					'accountId'    => 'acct_native_123',
				),
			),
			'blank business name and account ID'  => array(
				' Native Store ',
				array(
					'get_cached_account_data' => array(
						'business_profile' => array(
							'name' => ' ',
						),
					),
					'get_account_id'          => ' ',
				),
				array(
					'businessName' => 'Native Store',
					'accountId'    => '',
				),
			),
		);
	}

	/**
	 * @testdox While WooCommerce runs Multi-Currency ($multi_currency), the pages' data carries its flag before wcpay_js_settings callbacks see it.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $multi_currency Whether WooCommerce runs Multi-Currency.
	 */
	public function test_preloads_the_multi_currency_flag_before_woopayments_extension_settings( bool $multi_currency ): void {
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$sut          = $this->create_shared_settings(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$arbiter      = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $multi_currency );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
		$filter_calls = array();

		add_filter(
			'wcpay_js_settings',
			static function ( array $settings ) use ( &$filter_calls ): array {
				$filter_calls[] = $settings;
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

		$this->assertCount( 1, $filter_calls );
		$this->assertSame( 'provider value', $filter_calls[0]['preservedProviderSetting'] );
		$this->assertTrue( $filter_calls[0]['featureFlags']['reportsArea'] );
		if ( $multi_currency ) {
			$this->assertTrue( $filter_calls[0]['isMultiCurrencyEnabled'] );
			$this->assertTrue( $settings['woopaymentsSettings']['isMultiCurrencyEnabled'] );
		} else {
			$this->assertArrayNotHasKey( 'isMultiCurrencyEnabled', $filter_calls[0] );
			$this->assertArrayNotHasKey( 'isMultiCurrencyEnabled', $settings['woopaymentsSettings'] );
		}
		$this->assertSame( 'shared value', $settings['preservedSharedSetting'] );
		$this->assertSame( 'provider value', $settings['woopaymentsSettings']['preservedProviderSetting'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertTrue( $settings['woopaymentsSettings']['legacyWooPayments'] );
	}

	/**
	 * @testdox Should keep the last valid settings when the wcpay_js_settings filter returns an invalid value.
	 */
	public function test_preserves_settings_when_the_settings_filter_returns_an_invalid_value(): void {
		$_GET['page']                   = 'wc-settings';
		$_GET['tab']                    = 'checkout';
		$sut                            = $this->create_shared_settings(
			true,
			array(
				'is_reports_enabled' => true,
			)
		);
		$woopayments_extension_settings = null;

		add_filter(
			'wcpay_js_settings',
			static function ( array $settings ) use ( &$woopayments_extension_settings ) {
				$woopayments_extension_settings = $settings;

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

		$this->assertSame( $woopayments_extension_settings, $settings['woopaymentsSettings'] );
		$this->assertSame( 'shared value', $settings['preservedSharedSetting'] );
		$this->assertSame( 'provider value', $settings['woopaymentsSettings']['preservedProviderSetting'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['existingFlag'] );
		$this->assertTrue( $settings['woopaymentsSettings']['featureFlags']['reportsArea'] );
		$this->assertArrayHasKey( 'adminRouteAvailability', $settings['woopaymentsSettings'] );
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
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_live_account', 'is_payment_request_method_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
		$account_service->method( 'has_live_account' )->willReturn( $live_account );
		$account_service->method( 'is_payment_request_method_enabled' )->willReturn( true );

		$service = new WooPaymentsApplePayDomainService();
		$service->init(
			$this->createMock( WooPaymentsApiClient::class ),
			$account_service
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
	 * Replace the container's Overview service with one that returns the shell and the account summary, or that must not be
	 * asked for them.
	 *
	 * @param array<string,mixed>|null $shell   The shell, or null when building it must not happen.
	 * @param array<string,mixed>|null $summary The account summary, or null when reading it must not happen.
	 * @return void
	 */
	private function replace_overview_service( ?array $shell, ?array $summary = null ): void {
		$overview_service = $this->getMockBuilder( OverviewService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_overview', 'get_account_summary' ) )
			->getMock();
		if ( null === $shell ) {
			$overview_service->expects( $this->never() )->method( 'get_overview' );
		} else {
			$overview_service->method( 'get_overview' )->willReturn( $shell );
		}
		if ( null === $summary ) {
			$overview_service->expects( $this->never() )->method( 'get_account_summary' );
		} else {
			$overview_service->method( 'get_account_summary' )->willReturn( $summary );
		}
		wc_get_container()->replace( OverviewService::class, $overview_service );
	}

	/**
	 * Create the shared settings preload over a navigation controller for an account state.
	 *
	 * @param bool                                  $builtin_owner            Whether the built-in WooPayments owns payments.
	 * @param array<string,mixed>                   $account_state            Account state overrides.
	 * @param array<string,int>                     $badge_counts             Badge count overrides.
	 * @param WooPaymentsApplePayDomainService|null $apple_pay_domain_service Optional Apple Pay domain service.
	 * @return SharedSettings
	 */
	private function create_shared_settings( bool $builtin_owner, array $account_state = array(), array $badge_counts = array(), ?WooPaymentsApplePayDomainService $apple_pay_domain_service = null ): SharedSettings {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owner );

		$account_service = $this->create_account_service( $account_state );
		$badge_service   = $this->create_badge_service( $badge_counts );
		$api_client      = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( true );
		$onboarding_redirect = new OnboardingRedirect();
		$onboarding_redirect->init( $arbiter, $api_client, $account_service );
		$navigation = new NavigationController();
		$navigation->init( $arbiter, $account_service, $badge_service, $onboarding_redirect );

		$shared_settings = new SharedSettings();
		$shared_settings->init( $account_service, $badge_service, $apple_pay_domain_service ?? $this->createMock( WooPaymentsApplePayDomainService::class ), $navigation );

		return $shared_settings;
	}
}
