<?php
/**
 * Tests for the general settings model.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\InstallationPathEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\SellerTypeEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\MigrationManager;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The merchant connection the model stores and reads, over real options: the seller type, the branded experience, the
 * connection details, and the request-time reader that answers "is a merchant connected" without building the model.
 *
 * The reader's second source is the legacy (pre 4.0) settings option, which counts only until the settings migration
 * has run. The wallet shell reads the same options on its side, see PayPalWalletBootstrapTest.
 *
 * @group paypal-wallet
 */
class GeneralSettingsTest extends WalletTestCase {

	private const OPTION        = 'woocommerce-ppcp-data-common';
	private const LEGACY_OPTION = 'woocommerce-ppcp-settings';

	/**
	 * Start from a store with no stored connection and no finished settings migration.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( self::OPTION );
		delete_option( self::LEGACY_OPTION );
		delete_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE );
	}

	/**
	 * The stored settings of a connected merchant.
	 *
	 * @return array<string, mixed>
	 */
	private function connected_data(): array {
		return array(
			'merchant_id'      => 'MERCHANT123',
			'merchant_email'   => 'merchant@example.com',
			'client_id'        => 'client-id-value',
			'client_secret'    => 'client-secret-value',
			'sandbox_merchant' => false,
		);
	}

	/**
	 * The legacy settings of a merchant who connected before the settings migration existed.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_data(): array {
		return array(
			'client_id'     => 'legacy-client-id',
			'client_secret' => 'legacy-client-secret',
			'merchant_id'   => 'LEGACYMERCHANT',
			'sandbox_on'    => true,
		);
	}

	/**
	 * A model over the shared option holding only the given keys, so every other setting holds its declared default.
	 *
	 * @param array  $data    The stored keys.
	 * @param string $country The store country.
	 * @return GeneralSettings
	 */
	private function make_settings( array $data, string $country = 'US' ): GeneralSettings {
		$this->set_wallet_option( self::OPTION, $data );

		return new GeneralSettings( $country, 'USD', false );
	}

	/**
	 * What the wallet reads as the connection, with nothing it must not write.
	 *
	 * @return array{connected: bool, sandbox: bool}
	 */
	private function read_connection(): array {
		return GeneralSettings::read_connection_from_options();
	}

	/**
	 * The three seller types and what each one answers to the business and the casual question.
	 *
	 * @return array<string, array{seller_type: string, is_business: bool, is_casual: bool}>
	 */
	public function data_seller_types(): array {
		return array(
			'business seller: confirmed business account' => array(
				'seller_type' => SellerTypeEnum::BUSINESS,
				'is_business' => true,
				'is_casual'   => false,
			),
			'personal seller: casual individual account'  => array(
				'seller_type' => SellerTypeEnum::PERSONAL,
				'is_business' => false,
				'is_casual'   => true,
			),
			'unknown seller type: treated as casual to prevent retry loop' => array(
				'seller_type' => SellerTypeEnum::UNKNOWN,
				'is_business' => false,
				'is_casual'   => true,
			),
		);
	}

	/**
	 * Given a merchant whose seller type is stored, each question answers for that type, and an unknown type counts as
	 * casual, so an unresolvable type does not start a seller type detection retry loop.
	 *
	 * @testdox Should classify the merchant as business or casual from the stored seller type.
	 *
	 * @dataProvider data_seller_types
	 *
	 * @param string $seller_type The stored seller type.
	 * @param bool   $is_business The expected answer of is_business_seller().
	 * @param bool   $is_casual   The expected answer of is_casual_seller().
	 */
	public function test_seller_type_classification_matches_stored_value( string $seller_type, bool $is_business, bool $is_casual ): void {
		$settings = $this->make_settings( array( 'seller_type' => $seller_type ) );

		$this->assertSame( $is_business, $settings->is_business_seller(), "is_business_seller() for seller_type '$seller_type'" );
		$this->assertSame( $is_casual, $settings->is_casual_seller(), "is_casual_seller() for seller_type '$seller_type'" );
	}

	/**
	 * Installation paths and store countries, and whether the wallet then shows only PayPal's own brand.
	 *
	 * @return array<string, array{installation_path: string, country: string, expected: bool}>
	 */
	public function data_own_brand_only(): array {
		return array(
			'core-profiler path + WCPay country → branded-only'         => array(
				'installation_path' => InstallationPathEnum::CORE_PROFILER,
				'country'           => 'US',
				'expected'          => true,
			),
			'payment-settings path + WCPay country → branded-only'      => array(
				'installation_path' => InstallationPathEnum::PAYMENT_SETTINGS,
				'country'           => 'US',
				'expected'          => true,
			),
			'direct path + WCPay country → not branded-only'            => array(
				'installation_path' => InstallationPathEnum::DIRECT,
				'country'           => 'US',
				'expected'          => false,
			),
			'core-profiler path + non-WCPay country → not branded-only' => array(
				'installation_path' => InstallationPathEnum::CORE_PROFILER,
				'country'           => 'IN',
				'expected'          => false,
			),
		);
	}

	/**
	 * Given a store installed through a path in a country, own_brand_only() is true only for the branded paths in a
	 * country WooPayments serves. The wallet's capability flags depend on it: they are set to false while it is true.
	 *
	 * @testdox Should show only the own brand for a branded installation path in a WooPayments country.
	 *
	 * @dataProvider data_own_brand_only
	 *
	 * @param string $installation_path The stored installation path.
	 * @param string $country           The store country.
	 * @param bool   $expected          The expected answer of own_brand_only().
	 */
	public function test_own_brand_only_reflects_installation_path_and_country( string $installation_path, string $country, bool $expected ): void {
		$settings = $this->make_settings( array( 'wc_installation_path' => $installation_path ), $country );

		$this->assertSame( $expected, $settings->own_brand_only(), "own_brand_only() for path '$installation_path' and country '$country'" );
	}

	/**
	 * @testdox Should report the store country and the branded flag among the read-only woo settings.
	 */
	public function test_woo_settings_carry_the_country_and_the_branded_flag(): void {
		$settings = $this->make_settings( array( 'wc_installation_path' => InstallationPathEnum::CORE_PROFILER ), 'DE' );

		$woo_settings = $settings->get_woo_settings();

		$this->assertSame( 'DE', $woo_settings['country'] );
		$this->assertSame( 'USD', $woo_settings['currency'] );
		$this->assertTrue( $woo_settings['own_brand_only'] );
	}

	/**
	 * The four keys that make a merchant connected. The stored flag is not one of them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_connection_keys(): array {
		return array(
			'merchant_email' => array( 'merchant_email' ),
			'merchant_id'    => array( 'merchant_id' ),
			'client_id'      => array( 'client_id' ),
			'client_secret'  => array( 'client_secret' ),
		);
	}

	/**
	 * @testdox Should count a merchant as connected only when the email, merchant ID, client ID and client secret are all set.
	 *
	 * @dataProvider data_connection_keys
	 *
	 * @param string $missing The key that is left out.
	 */
	public function test_connected_needs_every_credential( string $missing ): void {
		$data = $this->connected_data();
		$this->assertTrue( GeneralSettings::is_connected_in( $data ) );

		unset( $data[ $missing ] );
		$data['merchant_connected'] = true;

		$this->assertFalse( GeneralSettings::is_connected_in( $data ), 'The stored merchant_connected flag must not count' );
		$this->assertFalse( $this->make_settings( $data )->is_merchant_connected() );
	}

	/**
	 * @testdox Should read the connection from the shared option, with the sandbox flag of the merchant.
	 */
	public function test_reader_answers_from_the_shared_option(): void {
		$this->set_wallet_option( self::OPTION, array_merge( $this->connected_data(), array( 'sandbox_merchant' => true ) ) );

		$this->assertSame(
			array(
				'connected' => true,
				'sandbox'   => true,
			),
			$this->read_connection()
		);

		$this->set_wallet_option( self::OPTION, $this->connected_data() );

		$this->assertSame(
			array(
				'connected' => true,
				'sandbox'   => false,
			),
			$this->read_connection()
		);
	}

	/**
	 * A merchant who connected before the migration has the legacy credentials and no shared option yet. The reader says
	 * connected, so the wallet boots and the migration gets to run.
	 *
	 * @testdox Should read a merchant connected only through the legacy settings as connected, with the legacy sandbox flag.
	 */
	public function test_reader_falls_back_to_the_legacy_settings(): void {
		$this->set_wallet_option( self::LEGACY_OPTION, $this->legacy_data() );

		$this->assertSame(
			array(
				'connected' => true,
				'sandbox'   => true,
			),
			$this->read_connection()
		);

		$this->set_wallet_option( self::LEGACY_OPTION, array_merge( $this->legacy_data(), array( 'sandbox_on' => false ) ) );

		$this->assertFalse( $this->read_connection()['sandbox'] );
	}

	/**
	 * @testdox Should ignore the legacy settings once the migration is done, whatever the shared option holds.
	 */
	public function test_reader_ignores_the_legacy_settings_once_the_migration_is_done(): void {
		$this->set_wallet_option( self::LEGACY_OPTION, $this->legacy_data() );
		$this->set_wallet_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE, '1' );

		$this->assertFalse( $this->read_connection()['connected'], 'No shared option and a finished migration: not connected' );

		$this->set_wallet_option( self::OPTION, array( 'client_id' => 'only-a-client-id' ) );

		$this->assertFalse( $this->read_connection()['connected'], 'A shared option that lacks credentials does not fall back to the legacy settings' );
	}

	/**
	 * The migration stores true, which the next request reads back as the string "1", and the reader compares with that
	 * string. Any other value of the marker leaves the legacy settings in play.
	 *
	 * @testdox Should treat only the stored string "1" of the migration marker as done.
	 */
	public function test_reader_checks_the_migration_marker_as_the_string_one(): void {
		$this->set_wallet_option( self::LEGACY_OPTION, $this->legacy_data() );

		foreach ( array( '0', '', 'yes' ) as $not_done ) {
			$this->set_wallet_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE, $not_done );
			$this->assertTrue( $this->read_connection()['connected'], "A marker of '$not_done' is not a finished migration" );
		}

		$this->set_wallet_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE, true );
		wp_cache_flush(); // The next request reads the marker from the database, where true is stored as "1".
		$this->assertSame( '1', get_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE ), 'Precondition: the marker reads back as "1"' );
		$this->assertFalse( $this->read_connection()['connected'] );
	}

	/**
	 * The three credentials the legacy migration requires.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_legacy_credentials(): array {
		return array(
			'client_id'     => array( 'client_id' ),
			'client_secret' => array( 'client_secret' ),
			'merchant_id'   => array( 'merchant_id' ),
		);
	}

	/**
	 * @testdox Should not count the legacy settings as a connection when one credential the migration needs is missing.
	 *
	 * @dataProvider data_legacy_credentials
	 *
	 * @param string $missing The legacy key that is left out.
	 */
	public function test_reader_needs_every_legacy_credential( string $missing ): void {
		$legacy = $this->legacy_data();
		unset( $legacy[ $missing ] );
		$this->set_wallet_option( self::LEGACY_OPTION, $legacy );

		$this->assertFalse( $this->read_connection()['connected'] );
	}

	/**
	 * @testdox Should not read the legacy settings when the shared option already says connected.
	 */
	public function test_reader_does_not_consult_the_legacy_settings_when_connected(): void {
		$this->set_wallet_option( self::OPTION, $this->connected_data() );
		$legacy_reads = $this->spy_filter( 'pre_option_' . self::LEGACY_OPTION, array() );

		$this->assertTrue( $this->read_connection()['connected'] );
		$this->assertCount( 0, $legacy_reads, 'The legacy option is a second source for a store the shared option calls not connected' );

		$this->set_wallet_option( self::OPTION, array() );

		$this->assertFalse( $this->read_connection()['connected'] );
		$this->assertCount( 1, $legacy_reads, 'The legacy option is consulted when the shared one says not connected' );
	}

	/**
	 * @testdox Should keep the sandbox flag of an unconnected shared option, and answer not connected for a store with no settings.
	 */
	public function test_reader_for_an_unconnected_store(): void {
		$this->assertSame(
			array(
				'connected' => false,
				'sandbox'   => false,
			),
			$this->read_connection()
		);

		$this->set_wallet_option( self::OPTION, array( 'sandbox_merchant' => true ) );
		$this->assertSame(
			array(
				'connected' => false,
				'sandbox'   => true,
			),
			$this->read_connection()
		);
	}

	/**
	 * @testdox Should read options that do not hold an array as no settings.
	 */
	public function test_reader_tolerates_options_that_are_not_arrays(): void {
		$this->set_wallet_option( self::OPTION, 'not-an-array' );
		$this->set_wallet_option( self::LEGACY_OPTION, 'not-an-array' );

		$this->assertSame(
			array(
				'connected' => false,
				'sandbox'   => false,
			),
			$this->read_connection()
		);
	}

	/**
	 * @testdox Should write nothing when it reads the connection.
	 */
	public function test_reader_writes_nothing(): void {
		$this->set_wallet_option( self::LEGACY_OPTION, $this->legacy_data() );
		$added   = $this->spy_filter( 'added_option' );
		$updated = $this->spy_filter( 'updated_option' );
		$deleted = $this->spy_filter( 'deleted_option' );

		$this->read_connection();

		$this->assertCount( 0, $added );
		$this->assertCount( 0, $updated );
		$this->assertCount( 0, $deleted );
		$this->assertFalse( get_option( self::OPTION ), 'The reader must not create the shared option from the legacy settings' );
	}

	/**
	 * @testdox Should store the merchant connection sanitized, read it back as a DTO, and flag the merchant as connected.
	 */
	public function test_merchant_data_round_trips_through_a_save(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$settings = new GeneralSettings( 'US', 'USD', false );
		$this->assertFalse( $settings->is_merchant_connected() );

		$settings->set_merchant_data(
			new MerchantConnectionDTO( true, ' client-id-value ', 'client-secret-value', 'MERCHANT123', 'merchant@example.com', 'DE', SellerTypeEnum::BUSINESS )
		);
		$settings->save();

		$reloaded = new GeneralSettings( 'US', 'USD', false );
		$data     = $reloaded->get_merchant_data();

		$this->assertTrue( $reloaded->is_merchant_connected() );
		$this->assertTrue( $reloaded->is_sandbox_merchant() );
		$this->assertSame( 'client-id-value', $data->client_id );
		$this->assertSame( 'client-secret-value', $data->client_secret );
		$this->assertSame( 'MERCHANT123', $reloaded->get_merchant_id() );
		$this->assertSame( 'merchant@example.com', $reloaded->get_merchant_email() );
		$this->assertSame( 'DE', $data->merchant_country );
		$this->assertTrue( $reloaded->is_business_seller() );
	}

	/**
	 * @testdox Should reset every connection detail to the disconnected state.
	 */
	public function test_reset_merchant_data_disconnects(): void {
		$settings = $this->make_settings( array_merge( $this->connected_data(), array( 'seller_type' => SellerTypeEnum::BUSINESS ) ) );
		$this->assertTrue( $settings->is_merchant_connected() );

		$settings->reset_merchant_data();

		$data = $settings->get_merchant_data();
		$this->assertFalse( $settings->is_merchant_connected() );
		$this->assertSame( '', $data->client_id );
		$this->assertSame( '', $data->merchant_id );
		$this->assertSame( SellerTypeEnum::UNKNOWN, $data->seller_type );
	}

	/**
	 * @testdox Should fall back to the store country for the merchant country until PayPal reports one, without hiding the gap in the DTO.
	 */
	public function test_merchant_country_falls_back_to_the_store_country(): void {
		$settings = $this->make_settings( array( 'merchant_country' => '' ), 'FR' );

		$this->assertSame( 'FR', $settings->get_merchant_country() );
		$this->assertSame( '', $settings->get_merchant_data()->merchant_country, 'The DTO keeps the raw value, empty when PayPal reported none' );

		$settings = $this->make_settings( array( 'merchant_country' => 'DE' ), 'FR' );

		$this->assertSame( 'DE', $settings->get_merchant_country() );
	}

	/**
	 * @testdox Should set the installation path once, ignore an invalid path, and reset it only for an allowed reason.
	 */
	public function test_installation_path_is_set_once_and_reset_for_an_allowed_reason_only(): void {
		$settings = $this->make_settings( array() );

		$settings->set_installation_path( 'not-a-path' );
		$this->assertSame( '', $settings->to_array()['wc_installation_path'], 'An invalid path is ignored' );

		$settings->set_installation_path( InstallationPathEnum::CORE_PROFILER );
		$settings->set_installation_path( InstallationPathEnum::DIRECT );
		$this->assertSame( InstallationPathEnum::CORE_PROFILER, $settings->get_installation_path(), 'The path cannot be changed once it is set' );

		$this->assertFalse( $settings->reset_installation_path( 'because' ) );
		$this->assertSame( InstallationPathEnum::CORE_PROFILER, $settings->get_installation_path() );

		$this->assertTrue( $settings->reset_installation_path( 'plugin_uninstall' ) );
		$this->assertSame( '', $settings->to_array()['wc_installation_path'] );
	}
}
