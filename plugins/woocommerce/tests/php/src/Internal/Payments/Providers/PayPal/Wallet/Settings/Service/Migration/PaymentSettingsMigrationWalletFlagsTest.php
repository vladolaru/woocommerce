<?php
/**
 * Tests for the legacy payment settings migration of the wallet flags.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\PaymentSettingsMigration;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ArrayObject;
use Mockery\MockInterface;

/**
 * The legacy per-method enable flags become the payment method state, under the IDs of the extension's methods. Those IDs
 * are part of the stored state, so the mapping must not change when Apple Pay or Google Pay code changes. The IDs are
 * literals here, so the test holds when the code reads them from GatewayIds.
 *
 * @group paypal-wallet
 */
class PaymentSettingsMigrationWalletFlagsTest extends WalletTestCase {

	/**
	 * The payment settings mock.
	 *
	 * @var PaymentSettings&MockInterface
	 */
	private $payment_settings;

	/**
	 * Method ID to the last state it was toggled to.
	 *
	 * @var ArrayObject
	 */
	private $toggled;

	/**
	 * Record every toggle.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toggled          = new ArrayObject();
		$this->payment_settings = $this->mock( PaymentSettings::class );
		$this->payment_settings->shouldIgnoreMissing();
		$this->payment_settings->shouldReceive( 'toggle_method_state' )->andReturnUsing(
			function ( string $method_id, bool $enabled ): void {
				$this->toggled[ $method_id ] = $enabled;
			}
		);
	}

	/**
	 * Run the migration over the given legacy settings.
	 *
	 * @param array $settings The legacy settings.
	 */
	private function migrate( array $settings ): void {
		( new PaymentSettingsMigration( $settings, $this->payment_settings ) )->migrate();
	}

	/**
	 * @testdox Should switch on the Apple Pay and Google Pay method states for their legacy button flags.
	 */
	public function test_wallet_flags_enable_the_stored_method_ids(): void {
		$this->migrate(
			array(
				'disable_funding'          => array( 'venmo' ),
				'applepay_button_enabled'  => true,
				'googlepay_button_enabled' => true,
			)
		);

		$this->assertTrue( $this->toggled['ppcp-applepay'] ?? false );
		$this->assertTrue( $this->toggled['ppcp-googlepay'] ?? false );
	}

	/**
	 * @testdox Should leave the wallet method states alone when their legacy flags are off, and still enable Venmo and Pay Later when flagged.
	 */
	public function test_wallet_flags_off_toggle_nothing_for_the_wallets(): void {
		$this->migrate( array( 'pay_later_button_enabled' => true ) );

		$this->assertArrayNotHasKey( 'ppcp-applepay', $this->toggled->getArrayCopy() );
		$this->assertArrayNotHasKey( 'ppcp-googlepay', $this->toggled->getArrayCopy() );
		$this->assertTrue( $this->toggled['venmo'] ?? false );
		$this->assertTrue( $this->toggled['pay-later'] ?? false );
	}
}
