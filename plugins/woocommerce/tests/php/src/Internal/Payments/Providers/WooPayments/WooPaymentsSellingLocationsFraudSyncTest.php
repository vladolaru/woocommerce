<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSellingLocationsFraudSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsSellingLocationsFraudSync class.
 *
 * Rules use the shape of the client's Rule::to_array() and Check::to_array() (client 11.1.0
 * `includes/fraud-prevention/models/class-rule.php:147-153`, `class-check.php:205-225`).
 */
class WooPaymentsSellingLocationsFraudSyncTest extends WC_Unit_Test_Case {

	/**
	 * Selling-location options the tests change.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array( 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_all_except_countries' );

	/**
	 * Recording API client.
	 *
	 * @var RecordingSettingsApiClient
	 */
	private RecordingSettingsApiClient $api_client;

	/**
	 * System under test.
	 *
	 * @var WooPaymentsSellingLocationsFraudSync
	 */
	private WooPaymentsSellingLocationsFraudSync $sut;

	/**
	 * Set up a connected store on advanced protection with an all-countries rule.
	 */
	public function setUp(): void {
		parent::setUp();

		// The test bootstrap installs WooCommerce, which defines WC_INSTALLING for the whole run.
		Constants::set_constant( 'WC_INSTALLING', false );
		Constants::set_constant( 'WC_UPDATING', false );
		update_option( 'woocommerce_allowed_countries', 'all' );
		update_option( 'woocommerce_specific_allowed_countries', array() );
		update_option( 'woocommerce_all_except_countries', array() );

		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		set_transient( 'wcpay_fraud_protection_settings', $this->get_advanced_ruleset( 'in', '' ), DAY_IN_SECONDS );

		$account_api_client = $this->createMock( WooPaymentsApiClient::class );
		$account_api_client->method( 'is_available' )->willReturn( true );
		$account_api_client->method( 'get_account' )->willThrowException( new WooPaymentsApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		$this->use_settings_service( $account_api_client );

		$this->sut = new WooPaymentsSellingLocationsFraudSync();
		$this->sut->init( new StaticNativeRuntimeArbiter( true ) );
		$this->sut->register();
	}

	/**
	 * Remove the test's hooks and container replacements; the test transaction rolls back the options.
	 */
	public function tearDown(): void {
		foreach ( self::OPTIONS as $option ) {
			remove_action( 'add_option_' . $option, array( $this->sut, 'schedule_refresh' ) );
			remove_action( 'update_option_' . $option, array( $this->sut, 'schedule_refresh' ) );
		}
		remove_action( 'shutdown', array( $this->sut, 'refresh_fraud_rules' ) );
		wp_set_current_user( 0 );
		// The REST writer test builds the global REST server; later tests must get a fresh one.
		$GLOBALS['wp_rest_server'] = null;
		Constants::clear_single_constant( 'WC_INSTALLING' );
		Constants::clear_single_constant( 'WC_UPDATING' );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Should save the ruleset once for three selling-location writes in one request.
	 */
	public function test_three_option_writes_make_one_ruleset_save(): void {
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'FR', 'DE' ) );
		update_option( 'woocommerce_all_except_countries', array( 'US' ) );

		$this->assertSame( 10, has_action( 'shutdown', array( $this->sut, 'refresh_fraud_rules' ) ) );
		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves, 'The option hooks must only schedule the refresh.' );

		$this->sut->refresh_fraud_rules();

		$expected = $this->get_advanced_ruleset( 'not_in', 'fr|de' );
		$this->assertSame( 1, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $expected, $this->api_client->last_fraud_ruleset );
		$this->assertSame( $expected, get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should block the excluded countries when the store sells to all but some.
	 */
	public function test_all_except_selling_locations_rebuild_the_check(): void {
		update_option( 'woocommerce_all_except_countries', array( 'RU', 'BY' ) );
		update_option( 'woocommerce_allowed_countries', 'all_except' );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( $this->get_advanced_ruleset( 'in', 'ru|by' ), $this->api_client->last_fraud_ruleset );
	}

	/**
	 * @testdox Should not call the platform when the country check is unchanged.
	 */
	public function test_unchanged_check_makes_no_save(): void {
		update_option( 'woocommerce_specific_allowed_countries', array( 'FR' ) );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $this->get_advanced_ruleset( 'in', '' ), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should rebuild the check when only the country list of the current mode changes.
	 *
	 * @dataProvider provide_list_only_changes
	 *
	 * @param string $mode     Selling-locations mode.
	 * @param string $option   Country list option of that mode.
	 * @param string $operator Country check operator for that mode.
	 */
	public function test_list_only_change_rebuilds_the_check( string $mode, string $option, string $operator ): void {
		update_option( 'woocommerce_allowed_countries', $mode );
		update_option( $option, array( 'FR' ) );
		set_transient( 'wcpay_fraud_protection_settings', $this->get_advanced_ruleset( $operator, 'fr' ), DAY_IN_SECONDS );
		remove_action( 'shutdown', array( $this->sut, 'refresh_fraud_rules' ) );

		update_option( $option, array( 'FR', 'DE' ) );
		$this->assertSame( 10, has_action( 'shutdown', array( $this->sut, 'refresh_fraud_rules' ) ) );
		$this->sut->refresh_fraud_rules();

		$this->assertSame( 1, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $this->get_advanced_ruleset( $operator, 'fr|de' ), $this->api_client->last_fraud_ruleset );
	}

	/**
	 * Selling-location modes with their own country list.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provide_list_only_changes(): array {
		return array(
			'specific countries'     => array( 'specific', 'woocommerce_specific_allowed_countries', 'not_in' ),
			'all but some countries' => array( 'all_except', 'woocommerce_all_except_countries', 'in' ),
		);
	}

	/**
	 * @testdox Should give the rebuilt rule the review outcome when the review feature is on, as the client does.
	 */
	public function test_rebuilt_rule_uses_review_outcome_when_review_feature_is_on(): void {
		update_option( 'wcpay_frt_review_feature_active', '1' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'FR' ) );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( 'review', $this->api_client->last_fraud_ruleset[0]['outcome'] );
	}

	/**
	 * @testdox Should keep the cached ruleset and finish shutdown when the platform refuses the save.
	 */
	public function test_failed_save_keeps_cached_ruleset(): void {
		$this->api_client->save_fraud_ruleset_exception = new WooPaymentsApiException( 'Server error.', 'wcpay_server_error', 500 );
		update_option( 'woocommerce_allowed_countries', 'specific' );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( $this->get_advanced_ruleset( 'in', '' ), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should not call the platform without a connected account.
	 */
	public function test_disconnected_store_makes_no_platform_call(): void {
		$account_api_client = $this->createMock( WooPaymentsApiClient::class );
		$account_api_client->method( 'is_available' )->willReturn( false );
		$account_api_client->expects( $this->never() )->method( 'get_account' );
		$this->use_settings_service( $account_api_client );
		delete_option( 'wcpay_account_data' );
		delete_transient( 'wcpay_fraud_protection_settings' );
		update_option( 'woocommerce_allowed_countries', 'specific' );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( 0, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves );
	}

	/**
	 * @testdox Should leave preset rulesets alone when protection is not advanced.
	 */
	public function test_preset_protection_level_makes_no_save(): void {
		update_option( 'current_protection_level', 'standard' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'FR' ) );

		$this->sut->refresh_fraud_rules();

		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves );
	}

	/**
	 * @testdox Should ignore selling-location writes made while WooCommerce installs or updates.
	 *
	 * @testWith ["WC_INSTALLING"]
	 *           ["WC_UPDATING"]
	 *
	 * @param string $constant Constant WooCommerce defines for the install or update.
	 */
	public function test_writes_during_install_or_update_are_ignored( string $constant ): void {
		Constants::set_constant( $constant, true );
		update_option( 'woocommerce_allowed_countries', 'specific' );

		$this->assertFalse( has_action( 'shutdown', array( $this->sut, 'refresh_fraud_rules' ) ) );
	}

	/**
	 * @testdox Should refresh the rule after a selling-location change through the REST settings API.
	 */
	public function test_rest_settings_write_refreshes_the_rule(): void {
		update_option( 'woocommerce_specific_allowed_countries', array( 'FR' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'PUT', '/wc/v3/settings/general/woocommerce_allowed_countries' );
		$request->set_body_params( array( 'value' => 'specific' ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->sut->refresh_fraud_rules();

		$this->assertSame( $this->get_advanced_ruleset( 'not_in', 'fr' ), $this->api_client->last_fraud_ruleset );
	}

	/**
	 * @testdox Should register on admin, REST, cron and WP-CLI requests of connected and active stores.
	 */
	public function test_registers_on_every_settings_writer_tier(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'admin', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertContains( WooPaymentsSellingLocationsFraudSync::class, $matrix[ $state ][ $request ], "$state/$request" );
			}
		}
	}

	/**
	 * Get an advanced ruleset whose international IP rule has the given check.
	 *
	 * @param string $operator Country check operator.
	 * @param string $value    Lower-case pipe-separated countries.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_advanced_ruleset( string $operator, string $value ): array {
		return array(
			array(
				'key'     => 'international_ip_address',
				'outcome' => 'block',
				'check'   => array(
					'key'      => 'ip_country',
					'operator' => $operator,
					'value'    => $value,
				),
			),
			array(
				'key'     => 'order_items_threshold',
				'outcome' => 'review',
				'check'   => array(
					'key'      => 'item_count',
					'operator' => 'greater_than',
					'value'    => 10,
				),
			),
		);
	}

	/**
	 * Build the settings service the sync resolves, on an account service that reads through the given API client.
	 *
	 * @param WooPaymentsApiClient $account_api_client API client the account service uses.
	 */
	private function use_settings_service( WooPaymentsApiClient $account_api_client ): void {
		wc_get_container()->replace( WooPaymentsApiClient::class, $account_api_client );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );

		$this->api_client = new RecordingSettingsApiClient();
		$settings_service = new WooPaymentsSettingsService();
		$settings_service->init( $account_service, $this->api_client, new RecordingPmPromotionsService(), new RecordingWooPaySessionService() );
		wc_get_container()->replace( WooPaymentsSettingsService::class, $settings_service );
	}

	/**
	 * Store connected account data.
	 *
	 * The account cache envelope (`data`, `fetched`, `errored`) is the client's database cache record (client 11.1.0
	 * `includes/class-database-cache.php:313-320`, `:380-381`); `data` keeps only the account ID and publishable keys
	 * the client reads for a connected account (`includes/class-wc-payments-account.php:164-192`).
	 */
	private function set_connected_account_data(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'           => 'acct_native_test',
					'is_live'              => true,
					'test_publishable_key' => 'pk_test_native',
					'live_publishable_key' => 'pk_live_native',
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
	}
}
