<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFraudRulesetService class.
 */
class WooPaymentsFraudRulesetServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsFraudRulesetService
	 */
	private $sut;

	/**
	 * Recording platform client.
	 *
	 * @var RecordingSettingsApiClient
	 */
	private RecordingSettingsApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new WooPaymentsApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );
		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy(), $database_cache );

		$this->api_client = new RecordingSettingsApiClient();
		$this->sut        = new WooPaymentsFraudRulesetService();
		$this->sut->init( $account_service, $this->api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Client 11.1.0 reads a rule through the getter that fetches a missing ruleset, or creates the Basic ruleset on the
	 * platform when it has none, once per request (includes/class-wc-payment-gateway-wcpay.php:3703-3716, :3762-3813).
	 *
	 * @testdox The AVS rule is active: $expected, with $label; the platform is asked $fetches time(s), and a second read on a connected store asks nothing more.
	 * @dataProvider provide_rule_reads
	 *
	 * @param string                   $label         Case.
	 * @param bool                     $connected     Whether the store has an account.
	 * @param array<int,mixed>|null    $cached        Cached ruleset, or null for none.
	 * @param array<string,mixed>|null $platform      Platform answer, or null for a failure.
	 * @param string|null              $failure_code  Platform failure code.
	 * @param bool                     $expected      Whether the AVS rule is active.
	 * @param int                      $fetches       Platform fetches.
	 * @param array<int,mixed>|null    $basic_saved   Ruleset saved to the platform, or null for none.
	 */
	public function test_is_fraud_rule_active_reads_the_ruleset_fetching_or_creating_it_when_none_is_cached( string $label, bool $connected, ?array $cached, ?array $platform, ?string $failure_code, bool $expected, int $fetches, ?array $basic_saved ): void {
		unset( $label );
		if ( $connected ) {
			$this->connect();
		}
		if ( null !== $cached ) {
			set_transient( 'wcpay_fraud_protection_settings', $cached, DAY_IN_SECONDS );
		}
		$this->api_client->latest_fraud_ruleset_response = $platform;
		if ( null !== $failure_code ) {
			$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform failure.', $failure_code, 'wcpay_fraud_ruleset_not_found' === $failure_code ? 404 : 500 );
		}

		$this->assertSame( $expected, $this->sut->is_fraud_rule_active( 'avs_verification' ) );
		$this->assertSame( $fetches, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( $basic_saved, $this->api_client->last_fraud_ruleset );

		if ( $connected ) {
			$this->sut->get_current_protection_level();

			$this->assertSame( $fetches, $this->api_client->latest_fraud_ruleset_requests, 'A second read in the same request asks the platform nothing more.' );
		}

		// Client 11.1.0 saves the Basic ruleset once, after the not-found answer (includes/class-wc-payment-gateway-wcpay.php:3800-3805).
		$this->assertSame( null === $basic_saved ? 0 : 1, $this->api_client->fraud_ruleset_saves, 'Both reads save the Basic ruleset at most once.' );
	}

	/**
	 * Rule reads: store state, platform answer and the expected outcome.
	 *
	 * @return array<string,array{0:string,1:bool,2:array<int,mixed>|null,3:array<string,mixed>|null,4:string|null,5:bool,6:int,7:array<int,mixed>|null}>
	 */
	public function provide_rule_reads(): array {
		$avs = array( self::avs_rule() );
		$ip  = array( self::international_ip_rule() );

		return array(
			'cached with AVS'    => array( 'a cached ruleset holding the rule', true, $avs, null, null, true, 0, null ),
			'cached without AVS' => array( 'a cached ruleset without the rule', true, $ip, null, null, false, 0, null ),
			'fetched with AVS'   => array( 'no cached ruleset and a platform ruleset holding the rule', true, null, array( 'ruleset_config' => $avs ), null, true, 1, null ),
			'platform error'     => array( 'no cached ruleset and a platform error', true, null, null, 'wcpay_server_error', false, 1, null ),
			'platform has none'  => array( 'no cached ruleset and none on the platform', true, null, null, 'wcpay_fraud_ruleset_not_found', false, 1, array() ),
			'no account'         => array( 'no account', false, null, array( 'ruleset_config' => $avs ), null, false, 0, null ),
		);
	}

	/**
	 * Get the AVS rule as client 11.1.0's tests store it (tests/unit/test-class-wc-payment-gateway-wcpay.php:4374-4386).
	 *
	 * @return array<string,mixed>
	 */
	private static function avs_rule(): array {
		return array(
			'key'     => 'avs_verification',
			'outcome' => 'block',
			'check'   => array(
				'key'      => 'avs_mismatch',
				'operator' => 'equals',
				'value'    => true,
			),
		);
	}

	/**
	 * Get an international IP address rule: client 11.1.0 checks ip_country against the selling locations
	 * (includes/fraud-prevention/class-fraud-risk-tools.php:150-160, tests/unit/fraud-prevention/test-class-fraud-risk-tools.php:44-52).
	 *
	 * @return array<string,mixed>
	 */
	private static function international_ip_rule(): array {
		return array(
			'key'     => 'international_ip_address',
			'outcome' => 'block',
			'check'   => array(
				'key'      => 'ip_country',
				'operator' => 'in',
				'value'    => 'us',
			),
		);
	}

	/**
	 * Store a connected account (the client's database cache record, includes/class-database-cache.php:313-320).
	 */
	private function connect(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id' => 'acct_fraud_test',
					'is_live'    => true,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
	}
}
