<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the Overview shell's payout fields: `account_status.deposits_enabled`, which gates the connection-success modal,
 * and `account_status.deposits`, which the payouts card reads as client 11.1.0 reads `wcpaySettings.accountStatus.deposits`.
 */
class WooPaymentsOverviewServiceDepositsStatusTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOverviewService
	 */
	private WooPaymentsOverviewService $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$account_service = new WooPaymentsAccountService();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );

		$this->sut = new WooPaymentsOverviewService();
		$this->sut->init( $account_service );
	}

	/**
	 * @testdox Should report payouts enabled from the cached account's deposits status, as the client's Overview reads it.
	 */
	public function test_reports_deposits_enabled_from_the_recorded_cached_account_shape(): void {
		$this->cache_account_data( $this->get_recorded_account_data() );

		$overview = $this->sut->get_overview();

		$this->assertTrue( $overview['account_status']['deposits_enabled'], 'A cached account with deposits.status "enabled" has payouts enabled.' );
	}

	/**
	 * @testdox Should report payouts disabled when the deposits status is "$status".
	 *
	 * @testWith ["disabled"]
	 *           ["blocked"]
	 *           [""]
	 *
	 * @param string $status Platform deposits status.
	 */
	public function test_reports_deposits_disabled_for_other_statuses( string $status ): void {
		$account_data                       = $this->get_recorded_account_data();
		$account_data['deposits']['status'] = $status;
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		$this->assertFalse( $overview['account_status']['deposits_enabled'] );
	}

	/**
	 * @testdox Should ignore top-level deposits_enabled and payouts_enabled keys, which the client never reads.
	 */
	public function test_ignores_top_level_enabled_keys(): void {
		$account_data = $this->get_recorded_account_data();
		unset( $account_data['deposits'] );
		$account_data['deposits_enabled'] = true;
		$account_data['payouts_enabled']  = true;
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		$this->assertFalse( $overview['account_status']['deposits_enabled'] );
	}

	/**
	 * @testdox Should pass the cached account's payout restriction, waiting period and minimum payout amounts to the payouts card.
	 */
	public function test_projects_the_cached_deposits_fields_the_payouts_card_reads(): void {
		$account_data = $this->get_recorded_account_data();
		// Local WPCOM `class-accounts-controller.php:1128` fills this from `Currency::get_minimum_scheduled_deposit_amounts()`, keyed by lowercase currency.
		$account_data['deposits']['minimum_scheduled_deposit_amounts'] = array(
			'usd' => 500,
			'eur' => 100,
		);
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		// Client 11.1.0 `class-wc-payments-account.php:378` passes the cached `deposits` object as `accountStatus.deposits`.
		$this->assertSame(
			array(
				'restrictions'                      => 'deposits_unrestricted',
				'completed_waiting_period'          => true,
				'minimum_scheduled_deposit_amounts' => array(
					'usd' => 500,
					'eur' => 100,
				),
			),
			$overview['account_status']['deposits']
		);
	}

	/**
	 * @testdox Should pass the "$restrictions" payout restriction and an unfinished waiting period through unchanged.
	 *
	 * @testWith ["schedule_restricted"]
	 *           ["deposits_blocked"]
	 *
	 * @param string $restrictions Platform payout restriction.
	 */
	public function test_projects_restricted_payouts_and_unfinished_waiting_period( string $restrictions ): void {
		$account_data                             = $this->get_recorded_account_data();
		$account_data['deposits']['restrictions'] = $restrictions;
		$account_data['deposits']['completed_waiting_period'] = false;
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		$this->assertSame( $restrictions, $overview['account_status']['deposits']['restrictions'] );
		$this->assertFalse( $overview['account_status']['deposits']['completed_waiting_period'] );
	}

	/**
	 * @testdox Should send empty payout fields when the cached account has no deposits object, as the client's `deposits ?? []` does.
	 */
	public function test_projects_empty_deposits_fields_without_a_deposits_object(): void {
		$account_data = $this->get_recorded_account_data();
		unset( $account_data['deposits'] );
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		$this->assertSame(
			array(
				'restrictions'                      => '',
				'completed_waiting_period'          => false,
				'minimum_scheduled_deposit_amounts' => array(),
			),
			$overview['account_status']['deposits']
		);
	}

	/**
	 * @testdox Should keep the bank account details and non-numeric minimum amounts out of the payout fields.
	 */
	public function test_keeps_bank_details_and_invalid_amounts_out_of_the_deposits_fields(): void {
		$account_data = $this->get_recorded_account_data();
		$account_data['deposits']['minimum_scheduled_deposit_amounts'] = array(
			'usd' => 500,
			'eur' => array( 'nested' ),
			'gbp' => 'not-a-number',
		);
		$this->cache_account_data( $account_data );

		$overview = $this->sut->get_overview();

		$this->assertArrayNotHasKey( 'default_external_accounts', $overview['account_status']['deposits'] );
		$this->assertSame( array( 'usd' => 500 ), $overview['account_status']['deposits']['minimum_scheduled_deposit_amounts'] );
	}

	/**
	 * Get the account fields recorded from the :8889 `wcpay_account_data` cache (2026-09-30).
	 *
	 * The cache carries `deposits.status` and no `deposits_enabled` or `payouts_enabled` key.
	 *
	 * @return array<string,mixed>
	 */
	private function get_recorded_account_data(): array {
		return array(
			'account_id'               => 'acct_recorded',
			'status'                   => 'complete',
			'payments_enabled'         => true,
			'details_submitted'        => true,
			'is_live'                  => false,
			'is_test_drive'            => true,
			'test_publishable_key'     => 'pk_test_recorded',
			'current_deadline'         => null,
			'has_overdue_requirements' => false,
			'deposits'                 => array(
				'status'                    => 'enabled',
				'restrictions'              => 'deposits_unrestricted',
				'interval'                  => 'daily',
				'weekly_anchor'             => '',
				'monthly_anchor'            => null,
				'delay_days'                => 2,
				'completed_waiting_period'  => true,
				'default_external_accounts' => array(
					array(
						'object'    => 'bank_account',
						'bank_name' => 'STRIPE TEST BANK',
						'last4'     => '6789',
						'currency'  => 'usd',
						'country'   => 'US',
						'status'    => 'new',
					),
				),
			),
		);
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
}
