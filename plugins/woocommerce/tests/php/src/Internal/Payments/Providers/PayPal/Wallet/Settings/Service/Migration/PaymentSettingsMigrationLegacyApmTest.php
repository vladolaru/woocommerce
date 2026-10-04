<?php
/**
 * Tests for the legacy payment settings migration of the local payment methods and Pay upon Invoice.
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
 * What the migration carries over from the extension's own Pay upon Invoice and OXXO gateway options. The two gateway
 * option names and the method IDs are part of the stored state, so the migration keeps reading them even though the wallet
 * has no such gateways. The IDs are literals here, so the test holds however the code names them.
 *
 * @group paypal-wallet
 */
class PaymentSettingsMigrationLegacyApmTest extends WalletTestCase {

	private const PUI_OPTION  = 'woocommerce_ppcp-pay-upon-invoice-gateway_settings';
	private const OXXO_OPTION = 'woocommerce_ppcp-oxxo-gateway_settings';

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
	private function migrate( array $settings = array() ): void {
		( new PaymentSettingsMigration( $settings, $this->payment_settings ) )->migrate();
	}

	/**
	 * @testdox Should switch on the Pay upon Invoice and OXXO method states for their enabled legacy gateway options (wallet, stored format).
	 */
	public function test_enabled_legacy_gateways_enable_the_stored_method_ids(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'enabled' => 'yes' ) );
		$this->set_wallet_option( self::OXXO_OPTION, array( 'enabled' => 'yes' ) );

		$this->migrate();

		$this->assertTrue( $this->toggled['ppcp-pay-upon-invoice-gateway'] ?? false );
		$this->assertTrue( $this->toggled['ppcp-oxxo-gateway'] ?? false );
	}

	/**
	 * @testdox Should leave the Pay upon Invoice and OXXO method states alone when their legacy gateway options are disabled or missing (wallet, stored format).
	 */
	public function test_disabled_or_missing_legacy_gateways_toggle_nothing(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'enabled' => 'no' ) );

		$this->migrate();

		$this->assertArrayNotHasKey( 'ppcp-pay-upon-invoice-gateway', $this->toggled->getArrayCopy() );
		$this->assertArrayNotHasKey( 'ppcp-oxxo-gateway', $this->toggled->getArrayCopy() );
	}

	/**
	 * @testdox Should copy the Pay upon Invoice brand name, logo URL and customer service instructions from the legacy gateway option (wallet, stored format).
	 */
	public function test_pay_upon_invoice_fields_are_copied(): void {
		$this->set_wallet_option(
			self::PUI_OPTION,
			array(
				'brand_name'                    => 'Acme',
				'logo_url'                      => 'https://example.com/logo.png',
				'customer_service_instructions' => 'Call us',
			)
		);
		$this->payment_settings->shouldReceive( 'set_pui_brand_name' )->once()->with( 'Acme' );
		$this->payment_settings->shouldReceive( 'set_pui_logo_url' )->once()->with( 'https://example.com/logo.png' );
		$this->payment_settings->shouldReceive( 'set_pui_customer_service_instructions' )->once()->with( 'Call us' );

		$this->migrate();
	}

	/**
	 * @testdox Should not touch the Pay upon Invoice fields when the legacy gateway option has no values (wallet, stored format).
	 */
	public function test_empty_pay_upon_invoice_fields_are_not_copied(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'brand_name' => '' ) );
		$this->payment_settings->shouldReceive( 'set_pui_brand_name' )->never();
		$this->payment_settings->shouldReceive( 'set_pui_logo_url' )->never();
		$this->payment_settings->shouldReceive( 'set_pui_customer_service_instructions' )->never();

		$this->migrate();
	}
}
