<?php
/**
 * Tests for the legacy Fastlane flag of the payment settings migration.
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
 * The legacy `axo_enabled` setting becomes the stored state of the Fastlane gateway (the option
 * `woocommerce_ppcp-axo-gateway_settings`, which the extension reads). Core keeps writing it, so a store that migrates
 * through core keeps its legacy Fastlane choice for the extension.
 *
 * @group paypal-wallet
 */
class PaymentSettingsMigrationFastlaneFlagTest extends WalletTestCase {

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
	 * @testdox Should switch the Fastlane method state on for the legacy axo_enabled flag.
	 */
	public function test_the_legacy_flag_enables_the_stored_method_id(): void {
		$this->migrate( array( 'axo_enabled' => true ) );

		$this->assertTrue( $this->toggled['ppcp-axo-gateway'] ?? false );
	}

	/**
	 * @testdox Should toggle nothing for the Fastlane method when the legacy flag is off or missing.
	 * @testWith [false]
	 *           [null]
	 *
	 * @param bool|null $flag The legacy flag, or null for a missing one.
	 */
	public function test_an_off_or_missing_legacy_flag_toggles_nothing( $flag ): void {
		$this->migrate( null === $flag ? array() : array( 'axo_enabled' => $flag ) );

		$this->assertArrayNotHasKey( 'ppcp-axo-gateway', $this->toggled->getArrayCopy() );
	}
}
