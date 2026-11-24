<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ExtensionBoundRenewalPlugins;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecheckApiClient;
use WC_Unit_Test_Case;

/**
 * A first switch from the extension that leaves a low stored tier and no account row: the completion notice's own account
 * read finds the account and raises the tier, so a store that took payments on the extension takes them again.
 */
class FirstSwitchSetupTierTest extends WC_Unit_Test_Case {

	/**
	 * Drop the client replacement and the services the case resolved.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
		} finally {
			wc_get_container()->reset_all_resolved();
			parent::tearDown();
		}
	}

	/**
	 * @testdox Stored $stored and no account row after a first switch: the completion notice's read finds the account, raises the tier to active and says everything works.
	 * @testWith ["disabled"]
	 *           ["available"]
	 *
	 * @param string $stored The stored tier the switch left.
	 */
	public function test_the_completion_notice_read_raises_a_low_tier( string $stored ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		update_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION, '11.3.0' );
		update_option(
			StateStore::OPTION_NAME,
			array(
				'state'                  => StateStore::STATE_DONE,
				'informational_outcomes' => array(),
			)
		);
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored );
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		delete_option( 'wcpay_account_data' );
		$client         = new RecheckApiClient();
		$client->answer = static fn(): array => array(
			'account_id'           => 'acct_123',
			'is_live'              => true,
			'payments_enabled'     => true,
			'details_submitted'    => true,
			'live_publishable_key' => 'pk_live_123',
		);
		wc_get_container()->replace( Client::class, $client );
		wc_get_container()->reset_all_resolved();
		$tier = wc_get_container()->get( WooPaymentsSetupTier::class );
		$this->assertTrue( wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $tier->get_effective_tier() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job        = new class() extends ReconciliationJob {
			/**
			 * Answer the finished switch's record.
			 *
			 * @return array<string,mixed>|null
			 */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => StateStore::STATE_DONE,
					'informational_outcomes' => array(),
				);
			}
		};
		$controller = new CutoverController();
		$controller->init(
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			wc_get_container()->get( LegacyProxy::class ),
			wc_get_container()->get( PreflightService::class ),
			$job,
			wc_get_container()->get( ExtensionBoundRenewalPlugins::class ),
			wc_get_container()->get( WooPaymentsAccountService::class )
		);

		ob_start();
		$controller->output_admin_notices();
		$notice = (string) ob_get_clean();

		$tier->invalidate();
		$this->assertStringContainsString( 'Everything works as before.', $notice );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $tier->get_stored_tier() );
		$this->assertSame( 1, $client->foreground_gets );
		$this->assertSame( 0, $client->plain_gets );
	}
}
