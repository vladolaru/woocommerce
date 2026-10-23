<?php
/**
 * Tests for the PayPal settings Blueprint importer.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint;

use Automattic\WooCommerce\Blueprint\StepProcessor;
use Automattic\WooCommerce\Blueprint\StepProcessorResult;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsImporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\SetPayPalSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\LocationStylingDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\PayLaterMessagingDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\DataSanitizer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * What an import of the PayPal settings step writes, over real options and the real Blueprint step processor: only the
 * wallet's own options, with the styling and Pay Later messaging locations rebuilt as the typed objects the models
 * load, and a settings-only import leaving a connected store connected.
 *
 * @group paypal-wallet
 */
class PayPalSettingsImporterTest extends WalletTestCase {

	private const COMMON     = 'woocommerce-ppcp-data-common';
	private const ONBOARDING = 'woocommerce-ppcp-data-onboarding';
	private const STYLING    = 'woocommerce-ppcp-data-styling';
	private const PAYLATER   = 'woocommerce-ppcp-data-paylater-messaging';

	/**
	 * Skip the whole class when the Blueprint package is not on this branch, and load the stored object classes.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! interface_exists( StepProcessor::class ) ) {
			$this->markTestSkipped( 'The Blueprint class ' . StepProcessor::class . ' is not available.' );
		}

		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';

		foreach ( array( self::COMMON, self::ONBOARDING, self::STYLING, self::PAYLATER ) as $name ) {
			$this->set_wallet_option( $name, 'placeholder' );
			delete_option( $name );
		}
	}

	/**
	 * Import the given options through the importer.
	 *
	 * @param array<string, mixed> $options Options to import.
	 * @return StepProcessorResult
	 */
	private function import( array $options ): StepProcessorResult {
		$schema = json_decode(
			(string) wp_json_encode(
				array(
					'step'    => SetPayPalSettings::get_step_name(),
					'options' => $options,
				)
			)
		);

		return ( new PayPalSettingsImporter( new DataSanitizer() ) )->process( $schema );
	}

	/**
	 * The importer owns the wallet's own options only; everything else in a Blueprint belongs to another step's
	 * processor.
	 *
	 * @testdox Should never write an option outside the allowlist.
	 */
	public function test_options_outside_the_allowlist_are_never_written(): void {
		$role_before     = get_option( 'default_role' );
		$register_before = get_option( 'users_can_register' );
		$name_before     = get_option( 'blogname' );

		$this->import(
			array(
				'users_can_register'           => 1,
				'default_role'                 => 'editor',
				'blogname'                     => 'Another name',
				'woocommerce-ppcp-data-common' => array( 'client_id' => '' ),
			)
		);

		$this->assertSame( $register_before, get_option( 'users_can_register' ) );
		$this->assertSame( $role_before, get_option( 'default_role' ) );
		$this->assertSame( $name_before, get_option( 'blogname' ) );
		$this->assertIsArray( get_option( self::COMMON ) );
	}

	/**
	 * Styling is stored as typed objects, so the plain arrays of a JSON payload have to be rebuilt before they are
	 * written, or the data model cannot load them back.
	 *
	 * @testdox Should rebuild the styling locations as typed objects.
	 */
	public function test_styling_locations_are_hydrated_into_dtos(): void {
		$this->import(
			array(
				self::STYLING => array(
					'cart'    => array( 'color' => 'gold' ),
					'product' => array( 'color' => 'blue' ),
				),
			)
		);

		$stored = get_option( self::STYLING );

		$this->assertInstanceOf( LocationStylingDTO::class, $stored['cart'] );
		$this->assertInstanceOf( LocationStylingDTO::class, $stored['product'] );
		$this->assertSame( 'cart', $stored['cart']->location );
		$this->assertSame( 'gold', $stored['cart']->color );
		$this->assertSame( 'product', $stored['product']->location );
		$this->assertSame( 'blue', $stored['product']->color );
	}

	/**
	 * @testdox Should rebuild the Pay Later messaging locations as typed objects.
	 */
	public function test_paylater_messaging_locations_are_hydrated(): void {
		$this->import(
			array(
				self::PAYLATER => array(
					'cart'     => array( 'enabled' => true ),
					'checkout' => array( 'enabled' => false ),
				),
			)
		);

		$stored = get_option( self::PAYLATER );

		$this->assertInstanceOf( PayLaterMessagingDTO::class, $stored['cart'] );
		$this->assertInstanceOf( PayLaterMessagingDTO::class, $stored['checkout'] );
		$this->assertTrue( $stored['cart']->enabled );
		$this->assertFalse( $stored['checkout']->enabled );
		$this->assertSame( 'checkout', $stored['checkout']->location );
	}

	/**
	 * A credentials-free export must import without errors, or the safe default is unusable.
	 *
	 * @testdox Should import a payload without credentials with no errors.
	 */
	public function test_a_credential_free_payload_imports_without_errors(): void {
		$result = $this->import(
			array(
				self::COMMON     => array(
					'client_id'      => '',
					'client_secret'  => '',
					'merchant_id'    => '',
					'merchant_email' => '',
				),
				self::ONBOARDING => array(
					'completed'  => false,
					'setup_done' => true,
				),
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array(), $result->get_messages( 'error' ) );
		$this->assertSame( '', get_option( self::COMMON )['client_id'] );
		$this->assertTrue( get_option( self::ONBOARDING )['setup_done'] );
	}

	/**
	 * Options are written whole, not merged, so a settings-only payload would otherwise blank the credentials of a store
	 * that is already connected.
	 *
	 * @testdox Should leave an existing connection alone when the payload carries none.
	 */
	public function test_a_settings_only_import_leaves_an_existing_connection_alone(): void {
		$this->set_wallet_option(
			self::COMMON,
			array(
				'client_id'          => 'TARGET-ID',
				'client_secret'      => 'TARGET-SECRET',
				'merchant_connected' => true,
			)
		);
		$this->set_wallet_option(
			self::ONBOARDING,
			array(
				'completed'  => true,
				'setup_done' => true,
			)
		);

		$this->import(
			array(
				self::COMMON     => array(
					'client_id'          => '',
					'client_secret'      => '',
					'merchant_connected' => false,
				),
				self::ONBOARDING => array(
					'completed'  => false,
					'setup_done' => true,
				),
			)
		);

		$common = get_option( self::COMMON );
		$this->assertSame( 'TARGET-ID', $common['client_id'] );
		$this->assertSame( 'TARGET-SECRET', $common['client_secret'] );
		$this->assertTrue( $common['merchant_connected'] );
		$this->assertTrue( get_option( self::ONBOARDING )['completed'] );
	}

	/**
	 * @testdox Should replace the existing connection when the payload carries its own.
	 */
	public function test_an_opt_in_import_replaces_the_existing_connection(): void {
		$this->set_wallet_option(
			self::COMMON,
			array(
				'client_id'     => 'TARGET-ID',
				'client_secret' => 'TARGET-SECRET',
			)
		);

		$this->import(
			array(
				self::COMMON => array(
					'client_id'     => 'SOURCE-ID',
					'client_secret' => 'SOURCE-SECRET',
				),
			)
		);

		$common = get_option( self::COMMON );
		$this->assertSame( 'SOURCE-ID', $common['client_id'] );
		$this->assertSame( 'SOURCE-SECRET', $common['client_secret'] );
	}

	/**
	 * @testdox Should reject a payload without options and write nothing.
	 */
	public function test_a_payload_without_options_is_rejected(): void {
		$importer = new PayPalSettingsImporter( new DataSanitizer() );
		$writes   = $this->spy_filter( 'pre_update_option_' . self::COMMON );

		$result = $importer->process( (object) array( 'step' => 'setPayPalSettings' ) );

		$this->assertFalse( $result->is_success() );
		$this->assertNotEmpty( $result->get_messages( 'error' ) );
		$this->assertCount( 0, $writes );
		$this->assertFalse( get_option( self::COMMON ) );
	}

	/**
	 * The option list still names the Fastlane option the extension stores, so a store that has one gets it back through
	 * an import.
	 *
	 * @testdox Should import the Fastlane option of the extension while the option list names it.
	 */
	public function test_the_fastlane_option_is_imported(): void {
		$this->set_wallet_option( 'woocommerce-ppcp-data-fastlane', 'placeholder' );
		delete_option( 'woocommerce-ppcp-data-fastlane' );

		$this->import( array( 'woocommerce-ppcp-data-fastlane' => array( 'fastlane_enabled' => true ) ) );

		$this->assertSame( array( 'fastlane_enabled' => true ), get_option( 'woocommerce-ppcp-data-fastlane' ) );
	}

	/**
	 * @testdox Should skip an option whose value is null with a warning, and report success.
	 */
	public function test_an_option_with_a_null_value_is_skipped_with_a_warning(): void {
		$result = $this->import(
			array(
				self::COMMON  => null,
				self::STYLING => array( 'cart' => array( 'color' => 'blue' ) ),
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( 'Skipped option with invalid value: ' . self::COMMON ), array_column( $result->get_messages( 'warn' ), 'message' ) );
		$this->assertFalse( get_option( self::COMMON ) );
		$this->assertInstanceOf( LocationStylingDTO::class, get_option( self::STYLING )['cart'] );
	}

	/**
	 * A user and the capabilities that decide whether importing is allowed: both are needed, not either one.
	 *
	 * @return array<string, array{0: string, 1: string[], 2: bool}>
	 */
	public function data_capability_combinations(): array {
		return array(
			'both capabilities'       => array( 'administrator', array(), true ),
			'only manage_woocommerce' => array( 'shop_manager', array(), false ),
			'only manage_options'     => array( 'subscriber', array( 'manage_options' ), false ),
			'neither'                 => array( 'subscriber', array(), false ),
		);
	}

	/**
	 * @testdox Should allow the import only for a user who can manage WooCommerce and manage options.
	 *
	 * @dataProvider data_capability_combinations
	 *
	 * @param string   $role       The role of the current user.
	 * @param string[] $extra_caps The capabilities added to the user on top of the role.
	 * @param bool     $expected   Expected result of the capability check.
	 */
	public function test_the_import_requires_both_capabilities( string $role, array $extra_caps, bool $expected ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		foreach ( $extra_caps as $capability ) {
			get_userdata( $user_id )->add_cap( $capability );
		}
		wp_set_current_user( $user_id );

		$importer = new PayPalSettingsImporter( new DataSanitizer() );

		$this->assertSame( $expected, $importer->check_step_capabilities( (object) array( 'step' => 'setPayPalSettings' ) ) );
	}
}
