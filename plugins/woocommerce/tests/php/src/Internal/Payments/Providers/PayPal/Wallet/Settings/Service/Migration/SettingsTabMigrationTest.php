<?php
/**
 * Tests for the settings tab migration.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PurchaseUnitSanitizer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\DataSanitizer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\SettingsTabMigration;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The legacy settings of the extension's tab, converted into the stored settings of the new model, over the real model
 * and its stored option.
 *
 * The stored option starts with a marker value in the keys under test, so a key the migration leaves alone can be told
 * from one it sets to its default.
 *
 * @group paypal-wallet
 */
class SettingsTabMigrationTest extends WalletTestCase {

	private const OPTION    = 'woocommerce-ppcp-data-settings';
	private const UNTOUCHED = 'untouched';

	/**
	 * Keys the cases look at, each holding the marker before the migration runs.
	 *
	 * @var string[]
	 */
	private const WATCHED_KEYS = array(
		'authorize_only',
		'capture_virtual_orders',
		'save_paypal_and_venmo',
		'save_card_details',
	);

	/**
	 * Store the marker in the watched keys.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_option( self::OPTION, array_fill_keys( self::WATCHED_KEYS, self::UNTOUCHED ) );
	}

	/**
	 * Run the migration over the given legacy settings and return the settings it stored.
	 *
	 * @param array $legacy_settings The legacy settings.
	 * @return array
	 */
	private function migrate( array $legacy_settings ): array {
		$model = new SettingsModel( new DataSanitizer(), 'wc-' );

		( new SettingsTabMigration( $legacy_settings, $model ) )->migrate();

		$stored = get_option( self::OPTION );
		$this->assertIsArray( $stored );

		return $stored;
	}

	/**
	 * Legacy intent and capture settings, and what the migration stores. A null marks a key the migration leaves alone.
	 *
	 * @return array<string, array{legacy_settings: array, expected_authorize: bool|null, expected_capture: bool|null}>
	 */
	public function data_capture_for_virtual_only_cases(): array {
		return array(
			'authorize + capture_for_virtual_only true'  => array(
				'legacy_settings'    => array(
					'intent'                   => 'authorize',
					'capture_for_virtual_only' => true,
				),
				'expected_authorize' => true,
				'expected_capture'   => true,
			),
			'authorize + capture_for_virtual_only false' => array(
				'legacy_settings'    => array(
					'intent'                   => 'authorize',
					'capture_for_virtual_only' => false,
				),
				'expected_authorize' => true,
				'expected_capture'   => false,
			),
			'capture intent without virtual-only flag'   => array(
				'legacy_settings'    => array( 'intent' => 'capture' ),
				'expected_authorize' => false,
				'expected_capture'   => null,
			),
			'capture + capture_for_virtual_only true'    => array(
				'legacy_settings'    => array(
					'intent'                   => 'capture',
					'capture_for_virtual_only' => true,
				),
				'expected_authorize' => false,
				'expected_capture'   => true,
			),
		);
	}

	/**
	 * @testdox Should store the authorize only and capture virtual orders settings that the legacy intent and virtual-only flag describe.
	 *
	 * @dataProvider data_capture_for_virtual_only_cases
	 *
	 * @param array     $legacy_settings    The legacy settings.
	 * @param bool|null $expected_authorize The stored authorize_only, or null when it is left alone.
	 * @param bool|null $expected_capture   The stored capture_virtual_orders, or null when it is left alone.
	 */
	public function test_capture_for_virtual_only_migration( array $legacy_settings, ?bool $expected_authorize, ?bool $expected_capture ): void {
		$stored = $this->migrate( $legacy_settings );

		$this->assertSame( $expected_authorize ?? self::UNTOUCHED, $stored['authorize_only'] );
		$this->assertSame( $expected_capture ?? self::UNTOUCHED, $stored['capture_virtual_orders'] );
	}

	/**
	 * Legacy values of a flag as the option has historically held them, and the bool they must be stored as. A null
	 * marks a legacy key that is missing, so the stored value is left alone.
	 *
	 * @return array<string, array{0: mixed, 1: bool|null}>
	 */
	public function data_legacy_flag_values(): array {
		return array(
			'legacy string yes coerces to true'  => array( 'yes', true ),
			'legacy string no coerces to false'  => array( 'no', false ),
			'legacy string 1 coerces to true'    => array( '1', true ),
			'legacy string 0 coerces to false'   => array( '0', false ),
			'empty string coerces to false'      => array( '', false ),
			'real boolean true passes through'   => array( true, true ),
			'real boolean false passes through'  => array( false, false ),
			'integer 1 coerces to true'          => array( 1, true ),
			'integer 0 coerces to false'         => array( 0, false ),
			'missing legacy key is not migrated' => array( '__missing__', null ),
		);
	}

	/**
	 * Legacy classic WooCommerce settings stored the vault flag as the string "yes" or "no", and the new slot is a typed
	 * bool. Without the coercion the migration either throws a TypeError, or stores "no" as true, which later breaks
	 * the typed getter of the model on PHP 8.
	 *
	 * @testdox Should store the legacy vault_enabled value as a real bool for the save PayPal and Venmo setting.
	 *
	 * @dataProvider data_legacy_flag_values
	 *
	 * @param mixed     $legacy_value The legacy vault_enabled value.
	 * @param bool|null $expected     The stored value, or null when the key is left alone.
	 */
	public function test_vault_enabled_migration( $legacy_value, ?bool $expected ): void {
		$legacy = '__missing__' === $legacy_value ? array() : array( 'vault_enabled' => $legacy_value );

		$stored = $this->migrate( $legacy );

		$this->assertSame( $expected ?? self::UNTOUCHED, $stored['save_paypal_and_venmo'] );
	}

	/**
	 * The same coercion for the card vault flag. The stored value is read by no code in core, but the stored format
	 * keeps it, and a stored string would otherwise abort the whole migration with an uncaught TypeError.
	 *
	 * @testdox Should store the legacy vault_enabled_dcc value as a real bool for the save card details setting.
	 *
	 * @dataProvider data_legacy_flag_values
	 *
	 * @param mixed     $legacy_value The legacy vault_enabled_dcc value.
	 * @param bool|null $expected     The stored value, or null when the key is left alone.
	 */
	public function test_vault_enabled_dcc_migration( $legacy_value, ?bool $expected ): void {
		$legacy = '__missing__' === $legacy_value ? array() : array( 'vault_enabled_dcc' => $legacy_value );

		$stored = $this->migrate( $legacy );

		$this->assertSame( $expected ?? self::UNTOUCHED, $stored['save_card_details'] );
	}

	/**
	 * @testdox Should convert the other legacy settings to the stored values of the new model.
	 */
	public function test_other_legacy_settings_are_converted(): void {
		$stored = $this->migrate(
			array(
				'prefix'                      => 'LEGACY-',
				'brand_name'                  => 'Acme',
				'subtotal_mismatch_behavior'  => PurchaseUnitSanitizer::MODE_EXTRA_LINE,
				'landing_page'                => ExperienceContext::LANDING_PAGE_GUEST_CHECKOUT,
				'blocks_final_review_enabled' => true,
				'3d_secure_contingency'       => 'SCA_ALWAYS',
				'logging_enabled'             => true,
				'stay_updated'                => false,
			)
		);

		$this->assertSame( 'LEGACY-', $stored['invoice_prefix'] );
		$this->assertSame( 'Acme', $stored['brand_name'] );
		$this->assertSame( 'correction', $stored['subtotal_adjustment'] );
		$this->assertSame( 'guest_checkout', $stored['landing_page'] );
		$this->assertFalse( $stored['enable_pay_now'], 'The final review flag is the opposite of Pay Now' );
		$this->assertSame( 'always-3d-secure', $stored['three_d_secure'] );
		$this->assertTrue( $stored['enable_logging'] );
		$this->assertFalse( $stored['stay_updated'] );
		$this->assertFalse( $stored['payment_level_processing'], 'Turning stay_updated off also turns payment level processing off' );
	}

	/**
	 * @testdox Should map any other subtotal behavior to no details and any other landing page to any.
	 */
	public function test_other_subtotal_behaviors_and_landing_pages_map_to_their_fallbacks(): void {
		$stored = $this->migrate(
			array(
				'subtotal_mismatch_behavior' => PurchaseUnitSanitizer::MODE_DITCH,
				'landing_page'               => ExperienceContext::LANDING_PAGE_NO_PREFERENCE,
			)
		);

		$this->assertSame( 'no_details', $stored['subtotal_adjustment'] );
		$this->assertSame( 'any', $stored['landing_page'] );
	}
}
