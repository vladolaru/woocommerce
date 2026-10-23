<?php
/**
 * Tests for the settings migration manager.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\MigrationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\PaymentSettingsMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\SettingsMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\SettingsTabMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\StylingSettingsMigration;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Mockery\MockInterface;

/**
 * The order of the legacy settings migrations and what the manager records: the done marker (a real option), the
 * onboarding progress of a connected merchant (a real stored profile) and the cleared product status caches.
 *
 * The migrations themselves are mocks here, each has its own tests.
 *
 * @group paypal-wallet
 */
class MigrationManagerTest extends WalletTestCase {

	private const MARKER            = MigrationManager::OPTION_NAME_MIGRATION_IS_DONE;
	private const NEW_MERCHANT      = 'woocommerce-ppcp-is-new-merchant';
	private const ONBOARDING_OPTION = 'woocommerce-ppcp-data-onboarding';
	private const CLEAR_STATUS_HOOK = 'woocommerce_paypal_payments_clear_apm_product_status';

	/**
	 * The migrations, by name.
	 *
	 * @var array<string, MockInterface>
	 */
	private $migrations = array();

	/**
	 * Start with no done marker and no stored onboarding profile, and mock the migrations as no-ops.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( self::NEW_MERCHANT );
		$this->set_wallet_option( self::ONBOARDING_OPTION, array() );
		// Claim the marker the manager writes, so the test base deletes it again.
		$this->set_wallet_option( self::MARKER, false );
		delete_option( self::MARKER );

		$this->migrations = array(
			'general' => $this->mock( SettingsMigration::class ),
			'tab'     => $this->mock( SettingsTabMigration::class ),
			'styling' => $this->mock( StylingSettingsMigration::class ),
			'payment' => $this->mock( PaymentSettingsMigration::class ),
		);
		$this->migrations['general']->shouldReceive( 'migrate' )->byDefault();
		$this->migrations['general']->shouldReceive( 'is_merchant_connected' )->andReturn( false )->byDefault();
		foreach ( array( 'tab', 'styling', 'payment' ) as $name ) {
			$this->migrations[ $name ]->shouldReceive( 'migrate' )->byDefault();
		}
	}

	/**
	 * Build the manager over the mocked migrations.
	 *
	 * @param LoggerInterface|null $logger The logger, an ignoring mock when none is given.
	 * @return MigrationManager
	 */
	private function create_manager( ?LoggerInterface $logger = null ): MigrationManager {
		return new MigrationManager(
			$this->migrations['general'],
			$this->migrations['tab'],
			$this->migrations['styling'],
			$this->migrations['payment'],
			$this->create_profile(),
			$logger ?? $this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * A profile over the stored onboarding option as it is now.
	 *
	 * @return OnboardingProfile
	 */
	private function create_profile(): OnboardingProfile {
		return new OnboardingProfile( true, false, false, false, true, false, true );
	}

	/**
	 * The onboarding progress and the migration outcome for each combination of a failing general migration and a
	 * connected merchant.
	 *
	 * @return array<string, array{general_migration_throws: bool, merchant_connected: bool, expected_completed: bool, expect_migration_done_written: bool}>
	 */
	public function data_migration_outcomes(): array {
		return array(
			'general migration throws: onboarding untouched, no done marker' => array(
				'general_migration_throws'      => true,
				'merchant_connected'            => false,
				'expected_completed'            => false,
				'expect_migration_done_written' => false,
			),
			'migration succeeds but merchant not connected: not completed'   => array(
				'general_migration_throws'      => false,
				'merchant_connected'            => false,
				'expected_completed'            => false,
				'expect_migration_done_written' => true,
			),
			'happy path: merchant connected, onboarding completed'           => array(
				'general_migration_throws'      => false,
				'merchant_connected'            => true,
				'expected_completed'            => true,
				'expect_migration_done_written' => true,
			),
		);
	}

	/**
	 * Given a general settings migration that throws or succeeds and a merchant that is connected or not, the
	 * onboarding profile is completed only when the migration succeeds and the merchant is connected, and the done marker
	 * is written only when the migration does not abort early.
	 *
	 * @testdox Should complete the onboarding only for a connected merchant after a migration that succeeded, and write the done marker unless the migration aborted.
	 *
	 * @dataProvider data_migration_outcomes
	 *
	 * @param bool $general_migration_throws      Whether the general settings migration throws.
	 * @param bool $merchant_connected            Whether the migration reports a connected merchant.
	 * @param bool $expected_completed            Whether the stored onboarding profile ends up completed.
	 * @param bool $expect_migration_done_written Whether the done marker is written.
	 */
	public function test_set_completed_reflects_migration_outcome( bool $general_migration_throws, bool $merchant_connected, bool $expected_completed, bool $expect_migration_done_written ): void {
		$general = $this->migrations['general'];
		if ( $general_migration_throws ) {
			$general->shouldReceive( 'migrate' )->once()->andThrow( new Exception( 'API call failed' ) );
		} else {
			$general->shouldReceive( 'is_merchant_connected' )->andReturn( $merchant_connected );
		}

		$this->create_manager()->migrate();

		$profile = $this->create_profile();
		$this->assertSame( $expected_completed, $profile->get_completed() );
		$this->assertSame( $expected_completed, $profile->is_gateways_refreshed() );
		$this->assertSame( $expected_completed, $profile->is_gateways_synced() );
		if ( $expect_migration_done_written ) {
			$this->assertTrue( get_option( self::MARKER ), 'The done marker must be written when the migration completes' );
		} else {
			$this->assertFalse( get_option( self::MARKER ), 'The done marker must not be written when the migration aborts' );
		}
	}

	/**
	 * @testdox Should abort before the other migrations and log it when the general settings migration throws, so the next page load retries.
	 */
	public function test_an_aborted_general_migration_runs_nothing_else_and_logs_a_warning(): void {
		$this->migrations['general']->shouldReceive( 'migrate' )->once()->andThrow( new Exception( 'API call failed', 7 ) );
		foreach ( array( 'tab', 'styling', 'payment' ) as $name ) {
			$this->migrations[ $name ]->shouldNotReceive( 'migrate' );
		}
		$logger = $this->mock( LoggerInterface::class );
		$logger->shouldReceive( 'warning' )
			->once()
			->with(
				'Settings migration aborted: seller status API call failed. Will retry on next page load.',
				array(
					'error_message' => 'API call failed',
					'error_code'    => 7,
				)
			);
		$cleared = $this->spy_filter( self::CLEAR_STATUS_HOOK );

		$this->create_manager( $logger )->migrate();

		$this->assertCount( 0, $cleared );
	}

	/**
	 * @testdox Should run the tab, styling and payment migrations in that order after the general one, and clear the product status caches when done.
	 */
	public function test_runs_the_migrations_in_order_and_clears_the_product_status_caches(): void {
		$order = array();
		foreach ( $this->migrations as $name => $migration ) {
			$migration->shouldReceive( 'migrate' )->once()->andReturnUsing(
				static function () use ( &$order, $name ) {
					$order[] = $name;
				}
			);
		}
		$cleared = $this->spy_filter( self::CLEAR_STATUS_HOOK );

		$this->create_manager()->migrate();

		$this->assertSame( array( 'general', 'tab', 'styling', 'payment' ), $order );
		$this->assertCount( 1, $cleared );
		$this->assertTrue( get_option( self::MARKER ) );
	}

	/**
	 * @testdox Should log a failed tab, styling or payment migration and still run the rest and finish.
	 */
	public function test_a_failing_later_migration_is_logged_and_the_rest_still_run(): void {
		$this->migrations['tab']->shouldReceive( 'migrate' )->once()->andThrow( new Exception( 'Tab failed' ) );
		$this->migrations['styling']->shouldReceive( 'migrate' )->once();
		$this->migrations['payment']->shouldReceive( 'migrate' )->once();
		$logger = $this->mock( LoggerInterface::class );
		$logger->shouldReceive( 'warning' )
			->once()
			->with( "Settings migration failed for 'settings_tab' during transition to new UI", \Mockery::type( 'array' ) );

		$this->create_manager( $logger )->migrate();

		$this->assertTrue( get_option( self::MARKER ), 'A failed later migration must not keep the migration from finishing' );
	}

	/**
	 * A merchant who never had the legacy UI has no legacy settings to convert: the migration is marked done and
	 * stops, without calling any migration.
	 *
	 * @testdox Should mark the migration done without running any migration for a new merchant.
	 */
	public function test_a_new_merchant_is_marked_done_without_migrating(): void {
		$this->set_wallet_option( self::NEW_MERCHANT, 1 );
		foreach ( $this->migrations as $migration ) {
			$migration->shouldNotReceive( 'migrate' );
		}
		$cleared = $this->spy_filter( self::CLEAR_STATUS_HOOK );

		$this->create_manager()->migrate();

		$this->assertTrue( get_option( self::MARKER ) );
		$this->assertCount( 0, $cleared );
	}
}
