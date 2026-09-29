<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the Overview shell `account_status.deposits_enabled` flag, which gates the connection-success modal.
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
