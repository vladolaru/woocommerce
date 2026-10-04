<?php
/**
 * Tests for the legacy styling settings migration.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\StylingSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\StylingSettingsMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The legacy button settings become per-location styling, and the list of enabled payment methods of a location keeps the
 * IDs of the extension's methods: the stored styling format carries them, so the mapping must not change when
 * Apple Pay or Google Pay code changes. The IDs are literals here, so the test holds when the code reads them from GatewayIds.
 *
 * @group paypal-wallet
 */
class StylingSettingsMigrationTest extends WalletTestCase {

	/**
	 * The styling settings mock.
	 *
	 * @var StylingSettings&MockInterface
	 */
	private $styling_settings;

	/**
	 * The location styles the migration saved.
	 *
	 * @var array<string, object>
	 */
	private array $saved = array();

	/**
	 * Capture what the migration hands to the model.
	 */
	public function setUp(): void {
		parent::setUp();
		// The DTO keeps the extension's class name and is aliased into the wallet namespace by this loader at boot.
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';

		$this->styling_settings = $this->mock( StylingSettings::class );
		$this->styling_settings->shouldReceive( 'from_array' )->andReturnUsing(
			function ( array $styles ): void {
				$this->saved = $styles;
			}
		);
		$this->styling_settings->shouldReceive( 'save' );
	}

	/**
	 * Run the migration over the given legacy settings.
	 *
	 * @param array $settings The legacy settings, merged over the minimum that makes the migration run.
	 */
	private function migrate( array $settings ): void {
		( new StylingSettingsMigration( array_merge( array( 'smart_button_locations' => array( 'cart', 'product' ) ), $settings ), $this->styling_settings ) )->migrate();
	}

	/**
	 * @testdox Should list PayPal, and Venmo unless it is disabled, for every location.
	 */
	public function test_lists_paypal_and_venmo(): void {
		$this->migrate( array( 'disable_funding' => array() ) );

		$this->assertEqualsCanonicalizing( array( 'cart', 'classic_checkout', 'express_checkout', 'mini_cart', 'product' ), array_keys( $this->saved ) );
		$this->assertSame( array( PayPalGateway::ID, 'venmo' ), $this->saved['cart']->methods );
	}

	/**
	 * @testdox Should leave Venmo out when the legacy settings disable it.
	 */
	public function test_leaves_venmo_out_when_disabled(): void {
		$this->migrate( array( 'disable_funding' => array( 'venmo' ) ) );

		$this->assertSame( array( PayPalGateway::ID ), $this->saved['cart']->methods );
	}

	/**
	 * @testdox Should carry the Apple Pay and Google Pay IDs of the legacy button flags into the stored methods.
	 */
	public function test_carries_the_legacy_wallet_flags_as_stored_method_ids(): void {
		$this->migrate(
			array(
				'disable_funding'          => array( 'venmo' ),
				'applepay_button_enabled'  => true,
				'googlepay_button_enabled' => true,
			)
		);

		$this->assertSame( array( PayPalGateway::ID, 'ppcp-applepay', 'ppcp-googlepay' ), $this->saved['cart']->methods );
	}
}
