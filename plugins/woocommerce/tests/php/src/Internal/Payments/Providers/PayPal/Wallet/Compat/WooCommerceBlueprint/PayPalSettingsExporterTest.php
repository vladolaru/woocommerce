<?php
/**
 * Tests for the PayPal settings Blueprint exporter.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint;

use Automattic\WooCommerce\Blueprint\Exporters\StepExporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\ConnectionDataSanitizer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsExporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\SetPayPalSettings;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * What a Blueprint export of the PayPal settings carries, over real stored options and the real Blueprint exporter
 * interface: no credentials by default, the credentials for the opt-in exporter, and never the legacy new merchant flag.
 *
 * @group paypal-wallet
 */
class PayPalSettingsExporterTest extends WalletTestCase {

	private const CREDENTIAL_KEYS = array(
		'client_id',
		'client_secret',
		'merchant_id',
		'merchant_email',
	);

	/**
	 * Skip the whole class when the Blueprint package is not on this branch, and store a connected merchant.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! interface_exists( StepExporter::class ) ) {
			$this->markTestSkipped( 'The Blueprint class ' . StepExporter::class . ' is not available.' );
		}

		$this->set_wallet_option(
			'woocommerce-ppcp-data-common',
			array(
				'client_id'          => 'client-id-value',
				'client_secret'      => 'client-secret-value',
				'merchant_id'        => 'MERCHANT123',
				'merchant_email'     => 'merchant@example.com',
				'merchant_country'   => 'US',
				'merchant_connected' => true,
			)
		);
		$this->set_wallet_option( 'woocommerce-ppcp-data-styling', array( 'cart' => array( 'shape' => 'pill' ) ) );
		$this->set_wallet_option( 'woocommerce-ppcp-is-new-merchant', 1 );
	}

	/**
	 * The options an exporter exports.
	 *
	 * @param bool $include_connection Whether the exporter carries the connection details.
	 * @return array<string, mixed>
	 */
	private function exported_options( bool $include_connection ): array {
		$exporter = new PayPalSettingsExporter( new ConnectionDataSanitizer(), $include_connection );

		return $exporter->export()->prepare_json_array()['options'];
	}

	/**
	 * @testdox Should carry no credentials in the default export.
	 */
	public function test_default_export_carries_no_credentials(): void {
		$common = $this->exported_options( false )['woocommerce-ppcp-data-common'];

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$this->assertSame( '', $common[ $key ], "'$key' was not cleared from the default export" );
		}
	}

	/**
	 * @testdox Should carry the credentials in the opt-in export.
	 */
	public function test_opt_in_export_carries_the_credentials(): void {
		$common = $this->exported_options( true )['woocommerce-ppcp-data-common'];

		$this->assertSame( 'client-id-value', $common['client_id'] );
		$this->assertSame( 'client-secret-value', $common['client_secret'] );
		$this->assertSame( 'MERCHANT123', $common['merchant_id'] );
		$this->assertSame( 'merchant@example.com', $common['merchant_email'] );
	}

	/**
	 * Stripping the connection details must not strip the settings the export exists to carry.
	 *
	 * @testdox Should still carry the settings in the default export.
	 */
	public function test_default_export_still_carries_the_settings(): void {
		$this->assertSame(
			array( 'cart' => array( 'shape' => 'pill' ) ),
			$this->exported_options( false )['woocommerce-ppcp-data-styling']
		);
	}

	/**
	 * The legacy flag was removed from the list of exported options: importing it as truthy makes the migration manager
	 * skip the legacy migrations on the target store for good.
	 *
	 * @testdox Should never export the legacy new merchant flag.
	 */
	public function test_legacy_new_merchant_flag_is_never_exported(): void {
		$this->assertArrayNotHasKey( 'woocommerce-ppcp-is-new-merchant', $this->exported_options( false ) );
		$this->assertArrayNotHasKey( 'woocommerce-ppcp-is-new-merchant', $this->exported_options( true ) );
	}

	/**
	 * What an instance exports is fixed when it is built, so the two registered exporters cannot swap behaviour at
	 * runtime.
	 *
	 * @testdox Should follow the constructor flag in the exported payload.
	 */
	public function test_the_exported_payload_follows_the_constructor_flag(): void {
		$default_common = $this->exported_options( false )['woocommerce-ppcp-data-common'];
		$opt_in_common  = $this->exported_options( true )['woocommerce-ppcp-data-common'];

		$this->assertSame( '', $default_common['client_secret'] );
		$this->assertSame( 'client-secret-value', $opt_in_common['client_secret'] );
	}

	/**
	 * The two exporters differ only by their selection identity. Asking for the plain step name must never select the
	 * exporter that carries the credentials.
	 *
	 * @testdox Should give the two exporters distinct selection identities.
	 */
	public function test_the_two_exporters_have_distinct_selection_identities(): void {
		$default   = new PayPalSettingsExporter( new ConnectionDataSanitizer(), false );
		$with_conn = new PayPalSettingsExporter( new ConnectionDataSanitizer(), true );

		$this->assertSame( 'paypalSettings', $default->get_alias() );
		$this->assertSame( 'paypalSettingsWithConnection', $with_conn->get_alias() );
		$this->assertNotSame( $default->get_alias(), $with_conn->get_alias() );
		$this->assertNotSame( $default->get_step_name(), $with_conn->get_step_name() );
		$this->assertNotSame( $default->get_description(), $with_conn->get_description() );
		$this->assertStringContainsString( 'client secret', $with_conn->get_description(), 'The opt-in export states the risk of carrying the credentials' );
	}

	/**
	 * Both exporters emit the same step, so the importer and the file format are unchanged by the opt-in.
	 *
	 * @testdox Should emit the same step from both exporters.
	 */
	public function test_both_exporters_emit_the_same_step(): void {
		$default_step = ( new PayPalSettingsExporter( new ConnectionDataSanitizer(), false ) )->export();
		$opt_in_step  = ( new PayPalSettingsExporter( new ConnectionDataSanitizer(), true ) )->export();

		$this->assertInstanceOf( SetPayPalSettings::class, $default_step );
		$this->assertInstanceOf( SetPayPalSettings::class, $opt_in_step );
		$this->assertSame( $default_step->prepare_json_array()['step'], $opt_in_step->prepare_json_array()['step'] );
	}

	/**
	 * The option list still names the Fastlane option the extension stores, so a store that has one carries it through an
	 * export and an import.
	 *
	 * @testdox Should carry the stored Fastlane option of the extension in the export.
	 */
	public function test_the_stored_fastlane_option_is_exported(): void {
		$this->set_wallet_option( 'woocommerce-ppcp-data-fastlane', array( 'fastlane_enabled' => true ) );

		$this->assertSame(
			array( 'fastlane_enabled' => true ),
			$this->exported_options( false )['woocommerce-ppcp-data-fastlane']
		);
	}

	/**
	 * A user and the capabilities that decide whether exporting is allowed: both are needed, not either one.
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
	 * Exporting requires both capabilities, not either one.
	 *
	 * @testdox Should allow the export only for a user who can manage WooCommerce and manage options.
	 *
	 * @dataProvider data_capability_combinations
	 *
	 * @param string   $role         The role of the current user.
	 * @param string[] $extra_caps   The capabilities added to the user on top of the role.
	 * @param bool     $expected     Expected result of the capability check.
	 */
	public function test_export_requires_both_capabilities( string $role, array $extra_caps, bool $expected ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		foreach ( $extra_caps as $capability ) {
			get_userdata( $user_id )->add_cap( $capability );
		}
		wp_set_current_user( $user_id );

		foreach ( array( false, true ) as $include_connection ) {
			$exporter = new PayPalSettingsExporter( new ConnectionDataSanitizer(), $include_connection );

			$this->assertSame( $expected, $exporter->check_step_capabilities() );
		}
	}
}
