<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the OverviewService class.
 */
class OverviewServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var OverviewService
	 */
	private OverviewService $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new OverviewService();
		$this->sut->init( $this->create_account_service() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_dismissed_todo_tasks' );
		delete_option( 'woocommerce_deleted_todo_tasks' );
		delete_option( 'woocommerce_remind_me_later_todo_tasks' );
		delete_option( 'wcpay_connection_success_modal_dismissed' );
		delete_option( 'wcpay_onboarding_test_mode' );
		delete_option( 'wcpay_instant_deposits_previously_eligible' );
		delete_option( 'wcpay_instant_deposit_notice_dismissed' );

		parent::tearDown();
	}

	/**
	 * @testdox Should return safe Overview fields from the stored account snapshot.
	 */
	public function test_get_overview_returns_safe_fields(): void {
		$this->cache_account_data(
			array(
				'account_id'           => 'acct_native_test',
				'test_publishable_key' => 'pk_test_secret',
				'live_publishable_key' => 'pk_live_secret',
				'details_submitted'    => true,
				'payments_enabled'     => true,
				'deposits'             => array( 'status' => 'enabled' ),
				'is_live'              => false,
				'is_test_drive'        => true,
				'account_details'      => array(
					'account_status' => array(
						'text'             => 'Restricted soon',
						'background_color' => 'yellow',
					),
					'payout_status'  => array(
						'text'             => 'Payouts enabled',
						'background_color' => 'green',
					),
					'banner'         => array(
						'text'             => 'Action required',
						'background_color' => 'yellow',
					),
				),
				'fees'                 => array(
					'card' => array(
						'base'     => array(
							'percentage_rate' => 2.9,
							'fixed_rate'      => 0.3,
						),
						'discount' => array(
							array(
								'currency'        => 'usd',
								'percentage_rate' => 1.1,
								'fixed_rate'      => 0.15,
							),
						),
					),
					'link' => array(
						'discount' => array(),
					),
				),
				'capital'              => array(
					'has_active_loan' => true,
					'loans'           => array( 'flxln_paid|paid', 'flxln_123456|active', array( 'not-a-loan' ) ),
				),
				// The platform's account answer (wpcom class-accounts-controller.php:1129-1161 at d761f9d3d85): a top-level status and
				// current_deadline, and requirements with errors only, as client 11.1.0 reads them (includes/class-wc-payments-account.php:360-380).
				'status'               => 'restricted_soon',
				'current_deadline'     => 1781740800,
				'requirements'         => array(
					'errors' => array(
						array(
							'code'   => 'verification_document_missing_front',
							'reason' => 'Document front is missing.',
						),
					),
				),
				'account_link'         => 'https://connect.stripe.com/setup/s/acct_native_test',
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option( 'woocommerce_dismissed_todo_tasks', array( 'old-task' ) );
		update_option( 'woocommerce_deleted_todo_tasks', array( 'deleted-task' ) );
		update_option( 'woocommerce_remind_me_later_todo_tasks', array( 'later-task' => 1781740800000 ) );
		update_option( 'wcpay_connection_success_modal_dismissed', 'yes' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);
		$overview = $this->sut->get_overview();

		$this->assertSame( 'acct_native_test', $overview['account']['id'] );
		$this->assertSame( 'test', $overview['account']['mode'] );
		$this->assertTrue( $overview['account']['connected'] );
		$this->assertTrue( $overview['account']['working'] );
		$this->assertTrue( $overview['account']['details_submitted'] );
		$this->assertTrue( $overview['account']['test_mode'] );
		$this->assertTrue( $overview['account']['test_mode_onboarding'] );
		$this->assertTrue( $overview['account']['test_drive'] );
		$this->assertFalse( $overview['account']['sandbox'] );
		$this->assertFalse( $overview['account']['live'] );
		$this->assertSame( 'restricted_soon', $overview['account_status']['status'] );
		$this->assertSame( 1781740800, $overview['account_status']['current_deadline'] );
		$this->assertFalse( $overview['account_status']['past_due'] );
		// A test-drive account has no dashboard access (client 11.1.0 `class-wc-payments-account.php:382`), and a raw account field is never forwarded.
		$this->assertSame( '', $overview['account_status']['account_link'] );
		$this->assertSame( 'verification_document_missing_front', $overview['account_status']['requirements']['errors'][0]['code'] );
		$this->assertTrue( $overview['account_status']['details_submitted'] );
		$this->assertTrue( $overview['account_status']['payments_enabled'] );
		$this->assertTrue( $overview['account_status']['deposits_enabled'] );
		$this->assertSame( array( 'old-task' ), $overview['overview_tasks_visibility']['dismissed_todo_tasks'] );
		$this->assertSame( array( 'deleted-task' ), $overview['overview_tasks_visibility']['deleted_todo_tasks'] );
		$this->assertSame( array( 'later-task' => 1781740800000 ), $overview['overview_tasks_visibility']['remind_me_later_todo_tasks'] );
		$this->assertTrue( $overview['is_connection_success_modal_dismissed'] );
		$this->assertSame( 'Restricted soon', $overview['account_details']['account_status']['text'] );
		$this->assertSame( 'Payouts enabled', $overview['account_details']['payout_status']['text'] );
		$this->assertSame( 'Action required', $overview['account_details']['banner']['text'] );
		$this->assertSame(
			array(
				array(
					'payment_method' => 'card',
					'fee'            => array(
						'base'     => array(
							'percentage_rate' => 2.9,
							'fixed_rate'      => 0.3,
						),
						'discount' => array(
							array(
								'currency'        => 'usd',
								'percentage_rate' => 1.1,
								'fixed_rate'      => 0.15,
							),
						),
					),
				),
			),
			$overview['account_fees']
		);
		$this->assertTrue( $overview['account_loans']['has_active_loan'] );
		$this->assertSame( array( 'flxln_paid|paid', 'flxln_123456|active' ), $overview['account_loans']['loans'] );
		$this->assertFalse( $overview['instant_deposits_previously_eligible'] );
		$this->assertFalse( $overview['is_instant_deposit_notice_dismissed'] );
		$this->assertStringContainsString( 'path=/woopayments/overview', $overview['urls']['overview_page'] );
		$this->assertStringContainsString( 'path=/woopayments/onboarding', $overview['urls']['setup'] );
		$this->assertStringContainsString( 'path=/woopayments/settings', $overview['urls']['settings'] );
		$this->assertStringContainsString( 'path=/woopayments/onboarding', $overview['urls']['onboarding'] );
		$this->assertArrayNotHasKey( 'test_publishable_key', $overview['account'] );
		$this->assertArrayNotHasKey( 'live_publishable_key', $overview['account'] );
		$this->assertArrayNotHasKey( 'test_publishable_key', $overview['account_status'] );
		$this->assertArrayNotHasKey( 'live_publishable_key', $overview['account_status'] );
	}

	/**
	 * @testdox Should return fail-closed Overview fields when no account is connected.
	 */
	public function test_get_overview_returns_fail_closed_fields_without_account(): void {
		$this->cache_account_data( array() );

		$overview = $this->sut->get_overview();

		$this->assertSame( '', $overview['account']['id'] );
		$this->assertFalse( $overview['account']['connected'] );
		$this->assertFalse( $overview['account']['working'] );
		$this->assertFalse( $overview['account']['details_submitted'] );
		$this->assertSame( 'not_connected', $overview['account_status']['status'] );
		$this->assertNull( $overview['account_status']['current_deadline'] );
		$this->assertFalse( $overview['account_status']['past_due'] );
		$this->assertSame( '', $overview['account_status']['account_link'] );
		$this->assertSame( array(), $overview['account_status']['requirements']['errors'] );
		$this->assertNull( $overview['account_details'] );
		$this->assertSame( array(), $overview['account_fees'] );
		$this->assertFalse( $overview['account_loans']['has_active_loan'] );
		$this->assertSame( array(), $overview['account_loans']['loans'] );
	}

	/**
	 * @testdox Should link a non-test-drive account to the nonce-protected dashboard login and expose the instant payout eligibility and offer dismissal flags.
	 */
	public function test_get_overview_exposes_dashboard_login_link_and_instant_payout_flag(): void {
		$this->cache_account_data(
			array(
				'account_id'        => 'acct_live_test',
				'details_submitted' => true,
				'payments_enabled'  => true,
				'is_live'           => true,
				'is_test_drive'     => false,
			)
		);
		// Written by `WooPaymentsAccountMilestonesController` once the account is eligible, like the WooPayments extension's `handle_instant_deposits_inbox_note()`.
		update_option( 'wcpay_instant_deposits_previously_eligible', true );
		// Saved by the Overview when the merchant dismisses the instant payout offer.
		update_option( 'wcpay_instant_deposit_notice_dismissed', true );

		$overview = $this->sut->get_overview();
		$query    = array();
		wp_parse_str( (string) wp_parse_url( $overview['account_status']['account_link'], PHP_URL_QUERY ), $query );

		$this->assertSame( '/woopayments/overview', $query['path'] ?? null );
		$this->assertSame( '1', $query['wcpay-login'] ?? null );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'] ?? '', 'wcpay-login' ) );
		$this->assertTrue( $overview['instant_deposits_previously_eligible'] );
		$this->assertTrue( $overview['is_instant_deposit_notice_dismissed'] );
	}

	/**
	 * @testdox Should map cached account data to reference-compatible account statuses.
	 *
	 * @dataProvider account_status_data
	 *
	 * @param array  $account_data    Cached account data overrides.
	 * @param string $expected_status Expected account status.
	 */
	public function test_get_overview_maps_cached_account_statuses( array $account_data, string $expected_status ): void {
		$this->cache_account_data(
			array_merge(
				array(
					'account_id'        => 'acct_status_test',
					'details_submitted' => true,
					'payments_enabled'  => true,
					'is_live'           => true,
				),
				$account_data
			)
		);

		$overview = $this->sut->get_overview();

		$this->assertSame( $expected_status, $overview['account_status']['status'] );
		$this->assertSame( $account_data['details_submitted'] ?? true, $overview['account_status']['details_submitted'] );
		$this->assertSame( $account_data['details_submitted'] ?? true, $overview['account']['details_submitted'] );
	}

	/**
	 * Data provider for cached account status mapping.
	 *
	 * Each row holds fields the platform's account answer carries (wpcom class-accounts-controller.php:1129-1161 at d761f9d3d85:
	 * status, has_overdue_requirements), which client 11.1.0 reads (includes/class-wc-payments-account.php:360-380).
	 *
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public function account_status_data(): array {
		return array(
			'platform status'            => array(
				array(
					'status' => 'under_review',
				),
				'under_review',
			),
			'overdue requirements'       => array(
				array(
					'has_overdue_requirements' => true,
				),
				'restricted',
			),
			'complete connected account' => array(
				array(),
				'complete',
			),
			// No status, as in the partial record onboarding caches until its refetch succeeds; client 11.1.0 answers
			// error: true there (includes/class-wc-payments-account.php:360-365), a recorded note.
			'details not submitted'      => array(
				array(
					'details_submitted' => false,
					'payments_enabled'  => false,
				),
				'restricted',
			),
		);
	}

	/**
	 * Discounted fee rows follow the account's fee order and keep every enabled method, as client 11.1.0
	 * `client/overview/index.js:146-163` builds them; a method without a discount or not enabled is left out.
	 */
	public function test_get_overview_lists_discounted_fees_in_account_order_for_enabled_methods(): void {
		$discounted = static fn( float $rate ): array => array(
			'base'     => array( 'percentage_rate' => 2.9 ),
			'discount' => array( array( 'percentage_rate' => $rate ) ),
		);
		$this->cache_account_data(
			array(
				'account_id' => 'acct_native_test',
				'fees'       => array(
					'p24'        => $discounted( 0.5 ),
					'card'       => $discounted( 1.1 ),
					'bancontact' => $discounted( 0.7 ),
					// A method the platform added after this release: the client lists it once enabled.
					'new_method' => $discounted( 0.3 ),
					'sepa_debit' => array(
						'base'     => array( 'percentage_rate' => 0.8 ),
						'discount' => array(),
					),
				),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'sepa_debit', 'p24', 'new_method' ) ) );

		$overview = $this->sut->get_overview();

		$this->assertSame( array( 'p24', 'card', 'new_method' ), array_column( $overview['account_fees'], 'payment_method' ) );
	}

	/**
	 * @testdox Should expose a safe account summary for the WooPayments settings surface.
	 */
	public function test_get_account_summary_returns_safe_account_state(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'             => 'acct_native_test',
					'test_publishable_key'   => 'pk_test_secret',
					'live_publishable_key'   => 'pk_live_secret',
					'payments_enabled'       => true,
					'details_submitted'      => true,
					'is_test_drive'          => true,
					'is_live'                => false,
					'is_documents_enabled'   => true,
					'has_submitted_vat_data' => true,
					'country'                => 'NL',
					'store_currencies'       => array(
						'default' => 'eur',
					),
				),
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		// A connected store whose account fetch fails, so the summary reads the seeded cache.
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new ApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( Client::class, $connected_api_client );

		try {
			$summary = $this->sut->get_account_summary();
		} finally {
			wc_get_container()->reset_replacement( Client::class );
		}

		$this->assertTrue( $summary['account']['connected'] );
		$this->assertTrue( $summary['account']['test_drive'] );
		$this->assertFalse( $summary['account']['sandbox'] );
		$this->assertFalse( $summary['account']['live'] );
		$this->assertSame(
			array(
				'enabled'                => true,
				'has_submitted_vat_data' => true,
				'country'                => 'NL',
			),
			$summary['documents']
		);
		// Client 11.1.0 `sandbox-mode-switch-to-live-notice/modal/index.tsx:39-45`: Activate payments goes to the onboarding route.
		$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/onboarding' ), $summary['urls']['setup'] );
		$this->assertArrayNotHasKey( 'test_publishable_key', $summary['account'] );
		$this->assertArrayNotHasKey( 'live_publishable_key', $summary['account'] );
		$this->assertArrayNotHasKey( 'is_documents_enabled', $summary['account'] );
		$this->assertArrayNotHasKey( 'has_submitted_vat_data', $summary['account'] );
	}

	/**
	 * @testdox Should expose a safe no-account summary for stores that still need setup.
	 */
	public function test_get_account_summary_returns_no_account_state(): void {
		update_option( 'wcpay_account_data', array( 'data' => array() ) );

		$summary = $this->sut->get_account_summary();

		$this->assertFalse( $summary['account']['connected'] );
		$this->assertFalse( $summary['account']['test_drive'] );
		$this->assertFalse( $summary['account']['sandbox'] );
		$this->assertFalse( $summary['account']['live'] );
		$this->assertSame(
			array(
				'enabled'                => false,
				'has_submitted_vat_data' => false,
				'country'                => '',
			),
			$summary['documents']
		);
		$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/onboarding' ), $summary['urls']['setup'] );
	}

	/**
	 * Cache account data in the preserved WooPayments account cache wrapper.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 */
	private function cache_account_data( array $account_data ): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $account_data,
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
	}

	/**
	 * Create the native account service dependency.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$account_service = new WooPaymentsAccountService();
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( wc_get_container()->get( LegacyProxy::class ) );
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), $database_cache );

		return $account_service;
	}
}
