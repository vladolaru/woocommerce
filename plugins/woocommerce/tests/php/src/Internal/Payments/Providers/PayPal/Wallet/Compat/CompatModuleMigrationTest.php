<?php
/**
 * Tests for the capture-on-status-change migration of the compatibility module (ported from the extension's
 * CompatModuleMigrationTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The migration copies the legacy `capture_on_status_change` setting into the payment settings once, on the update hook.
 * All four cases are wallet cases.
 *
 * @group paypal-wallet
 */
class CompatModuleMigrationTest extends WalletTestCase {

	private const HOOK           = 'woocommerce_paypal_payments_gateway_migrate_on_update';
	private const LEGACY_OPTION  = 'woocommerce-ppcp-settings';
	private const PAYMENT_OPTION = 'woocommerce-ppcp-data-payment';

	/**
	 * Anonymous subclass that exposes the protected migration.
	 *
	 * @var object
	 */
	private $testee;

	/**
	 * Start from no listener on the update hook and no stored settings.
	 */
	public function setUp(): void {
		parent::setUp();

		remove_all_actions( self::HOOK );
		delete_option( self::LEGACY_OPTION );
		delete_option( self::PAYMENT_OPTION );

		$this->testee = new class() extends CompatModule {
			/**
			 * Register the migration on the update hook.
			 */
			public function run_migration(): void {
				$this->migrate_capture_on_status_change();
			}
		};
	}

	/**
	 * Delete the options the migration may have written.
	 */
	public function tearDown(): void {
		delete_option( self::LEGACY_OPTION );
		delete_option( self::PAYMENT_OPTION );

		parent::tearDown();
	}

	/**
	 * Cases: legacy value, payment settings before, payment settings expected after (null: nothing may be written).
	 *
	 * @return array<string, array{array, array, array|null}>
	 */
	public function data_migration_cases(): array {
		return array(
			'legacy setting absent' => array( array(), array(), null ),
			'already migrated'      => array(
				array( 'capture_on_status_change' => false ),
				array( 'capture_on_status_change' => true ),
				null,
			),
			'migrates false'        => array(
				array( 'capture_on_status_change' => false ),
				array(),
				array( 'capture_on_status_change' => false ),
			),
			'migrates true'         => array(
				array( 'capture_on_status_change' => true ),
				array(),
				array( 'capture_on_status_change' => true ),
			),
		);
	}

	/**
	 * @testdox Should copy the legacy capture setting once, and write nothing when it is absent or already migrated (wallet).
	 * @dataProvider data_migration_cases
	 *
	 * @param array      $legacy_settings  The legacy settings option.
	 * @param array      $payment_settings The payment settings option before the migration.
	 * @param array|null $expected_update  The payment settings after, or null when no write may happen.
	 */
	public function test_migration( array $legacy_settings, array $payment_settings, ?array $expected_update ): void {
		// Both options are stored, even when empty, as the extension's stubs return arrays: an option that does not exist
		// at all makes the migration read `array( false )` and write that stray entry back with the migrated value.
		update_option( self::LEGACY_OPTION, $legacy_settings );
		update_option( self::PAYMENT_OPTION, $payment_settings );

		$this->testee->run_migration();
		$this->assertNotFalse( has_action( self::HOOK ), 'The migration waits for the update hook' );
		do_action( self::HOOK );

		if ( null === $expected_update ) {
			$this->assertSame( $payment_settings, get_option( self::PAYMENT_OPTION ), 'Nothing is written' );
			return;
		}

		$this->assertSame( $expected_update, get_option( self::PAYMENT_OPTION ) );
	}
}
