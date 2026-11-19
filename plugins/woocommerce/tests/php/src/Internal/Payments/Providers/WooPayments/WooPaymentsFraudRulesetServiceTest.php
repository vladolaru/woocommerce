<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;

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

		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new ApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( Client::class, $connected_api_client );
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
			$this->api_client->latest_fraud_ruleset_exception = new ApiException( 'Platform failure.', $failure_code, 'wcpay_fraud_ruleset_not_found' === $failure_code ? 404 : 500 );
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
	 * A limit below 1 as the platform reads it (`intval`), a value that is not a number, or a minimum above the maximum
	 * makes the platform's purchase price or item count check fire for every order, or for none while it shows as set.
	 *
	 * @testdox Should refuse an advanced ruleset with $label, and send, cache and store nothing.
	 * @dataProvider provide_refused_limits
	 *
	 * @param string           $label   Case.
	 * @param array<int,mixed> $ruleset Submitted advanced ruleset.
	 * @param string           $message Expected refusal message.
	 */
	public function test_save_fraud_settings_refuses_a_limit_that_fires_for_every_order_or_none( string $label, array $ruleset, string $message ): void {
		unset( $label );
		$stored_ruleset = array( self::avs_rule() );
		set_transient( 'wcpay_fraud_protection_settings', $stored_ruleset, DAY_IN_SECONDS );
		update_option( 'current_protection_level', 'standard' );

		$result = $this->sut->save_fraud_settings(
			array(
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => $ruleset,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( array( 'advanced_fraud_protection_settings' => $message ), $data['params'] );
		$this->assertSame( 'rest_invalid_pattern', $data['details']['advanced_fraud_protection_settings']['code'] );
		$this->assertSame( $message, $data['details']['advanced_fraud_protection_settings']['message'] );
		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves, 'A refused ruleset is not sent to the platform.' );
		$this->assertSame( $stored_ruleset, get_transient( 'wcpay_fraud_protection_settings' ), 'A refused ruleset is not cached.' );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ), 'A refused ruleset leaves the protection level.' );
	}

	/**
	 * Refused advanced rulesets: case, ruleset and message.
	 *
	 * @return array<string,array{0:string,1:array<int,mixed>,2:string}>
	 */
	public function provide_refused_limits(): array {
		$price_minimum  = 'The minimum purchase price must be a number greater than zero.';
		$price_maximum  = 'The maximum purchase price must be a number greater than zero.';
		$price_inverted = 'Maximum purchase price must be greater than the minimum purchase price.';
		$items_minimum  = 'The minimum items per order must be at least 1.';
		$items_maximum  = 'The maximum items per order must be at least 1.';
		$items_inverted = 'Maximum item count must be greater than the minimum item count.';

		return array(
			'negative price maximum'                     => array( 'a negative price maximum', array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '-10000|USD' ) ) ), $price_maximum ),
			'negative price minimum in a list'           => array( 'a negative price minimum in an "or" list', array( self::rule( 'purchase_price_threshold', self::any_of( self::limit( 'order_total', 'less_than', '-10000|USD' ), self::limit( 'order_total', 'greater_than', '50000|USD' ) ) ) ), $price_minimum ),
			'price minimum above the maximum'            => array( 'a price minimum above the maximum', array( self::rule( 'purchase_price_threshold', self::any_of( self::limit( 'order_total', 'less_than', '50000|USD' ), self::limit( 'order_total', 'greater_than', '10000|USD' ) ) ) ), $price_inverted ),
			'zero price maximum'                         => array( 'a zero price maximum', array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '0|USD' ) ) ), $price_maximum ),
			'price maximum that is not a number'         => array( 'a price maximum that is not a number', array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '100x|USD' ) ) ), $price_maximum ),
			'empty price maximum'                        => array( 'an empty price maximum', array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '' ) ) ), $price_maximum ),
			'negative price maximum under the item rule' => array( 'a negative price maximum under the item count rule', array( self::rule( 'order_items_threshold', self::limit( 'order_total', 'greater_than', '-100|USD' ) ) ), $price_maximum ),
			'negative price maximum under another rule'  => array( 'a negative price maximum under the address mismatch rule', array( self::avs_rule(), self::rule( 'address_mismatch', self::limit( 'order_total', 'greater_than', '-100|USD' ) ) ), $price_maximum ),
			'price maximum below one minor unit'         => array( 'a price maximum of half a minor unit', array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '0.5|USD' ) ) ), $price_maximum ),
			'negative item maximum'                      => array( 'a negative item maximum', array( self::rule( 'order_items_threshold', self::limit( 'item_count', 'greater_than', -5 ) ) ), $items_maximum ),
			'item minimum above the maximum'             => array( 'an item minimum above the maximum', array( self::rule( 'order_items_threshold', self::any_of( self::limit( 'item_count', 'less_than', 10 ), self::limit( 'item_count', 'greater_than', 3 ) ) ) ), $items_inverted ),
			'zero item minimum'                          => array( 'a zero item minimum', array( self::rule( 'order_items_threshold', self::any_of( self::limit( 'item_count', 'less_than', 0 ), self::limit( 'item_count', 'greater_than', 10 ) ) ) ), $items_minimum ),
		);
	}

	/**
	 * Equal limits are allowed, as the extension allows them: a store with one price can mean them. A minimum and maximum in
	 * different currencies are not compared, since the platform converts each one.
	 *
	 * @testdox Should save an advanced ruleset with $label.
	 * @dataProvider provide_saved_limits
	 *
	 * @param string           $label   Case.
	 * @param array<int,mixed> $ruleset Submitted advanced ruleset.
	 */
	public function test_save_fraud_settings_saves_limits_above_zero( string $label, array $ruleset ): void {
		unset( $label );

		$result = $this->sut->save_fraud_settings(
			array(
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => $ruleset,
			)
		);

		$this->assertNull( $result );
		$this->assertSame( 1, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $ruleset, $this->api_client->last_fraud_ruleset );
		$this->assertSame( $ruleset, get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
	}

	/**
	 * Saved advanced rulesets: case and ruleset.
	 *
	 * @return array<string,array{0:string,1:array<int,mixed>}>
	 */
	public function provide_saved_limits(): array {
		return array(
			'price range'                          => array( 'a price range', array( self::rule( 'purchase_price_threshold', self::any_of( self::limit( 'order_total', 'less_than', '1999|USD' ), self::limit( 'order_total', 'greater_than', '50000|USD' ) ) ) ) ),
			'equal price limits'                   => array( 'equal price limits', array( self::rule( 'purchase_price_threshold', self::any_of( self::limit( 'order_total', 'less_than', '5000|USD' ), self::limit( 'order_total', 'greater_than', '5000|USD' ) ) ) ) ),
			'price limits in different currencies' => array( 'a price minimum in EUR above a maximum in USD', array( self::rule( 'purchase_price_threshold', self::any_of( self::limit( 'order_total', 'less_than', '20000|EUR' ), self::limit( 'order_total', 'greater_than', '10000|USD' ) ) ) ) ),
		);
	}

	/**
	 * @testdox Should save the $level preset, whose limits are above zero.
	 * @testWith ["standard"]
	 *           ["high"]
	 *
	 * @param string $level Protection level.
	 */
	public function test_save_fraud_settings_saves_a_preset( string $level ): void {
		$result = $this->sut->save_fraud_settings(
			array(
				'current_protection_level'           => $level,
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$this->assertNull( $result );
		$this->assertSame( 1, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $level, get_option( 'current_protection_level' ) );
	}

	/**
	 * The page shows a stored negative limit with its warning, so the read keeps it; only a save refuses it.
	 *
	 * @testdox Should cache and serve a platform ruleset that holds a negative price maximum.
	 */
	public function test_platform_ruleset_with_a_negative_limit_is_still_cached_on_read(): void {
		$this->connect();
		$ruleset = array( self::rule( 'purchase_price_threshold', self::limit( 'order_total', 'greater_than', '-10000|USD' ) ) );
		$this->api_client->latest_fraud_ruleset_response = array( 'ruleset_config' => $ruleset );

		$this->assertSame( $ruleset, $this->sut->get_advanced_fraud_protection_settings() );
		$this->assertSame( $ruleset, get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * Get a blocking rule.
	 *
	 * @param string              $key   Rule key.
	 * @param array<string,mixed> $check Rule check.
	 * @return array<string,mixed>
	 */
	private static function rule( string $key, array $check ): array {
		return array(
			'key'     => $key,
			'outcome' => 'block',
			'check'   => $check,
		);
	}

	/**
	 * Get a single limit check.
	 *
	 * @param string     $key      Check key.
	 * @param string     $operator Check operator.
	 * @param string|int $value    Check value.
	 * @return array<string,mixed>
	 */
	private static function limit( string $key, string $operator, $value ): array {
		return array(
			'key'      => $key,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Get an "or" list of checks, as the settings page writes a range.
	 *
	 * @param array<string,mixed> ...$checks Checks.
	 * @return array<string,mixed>
	 */
	private static function any_of( array ...$checks ): array {
		return array(
			'operator' => 'or',
			'checks'   => $checks,
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
