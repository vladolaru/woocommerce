<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\OnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Pins that an account delete the platform refuses, or that fails, leaves the setup tier as it was.
 *
 * The delete empties the account cache first, which lowers the stored tier to available, so nothing reads the account
 * while the platform deletes it. When the platform does not delete the account the tier goes back; a store taking payments
 * keeps its gateway, its webhook route and its renewals.
 */
class AccountResetSetupTierTest extends WC_Unit_Test_Case {

	private const ACCOUNT_CACHE_OPTION = 'wcpay_account_data';

	private const TEST_DRIVE_SETTINGS_TRANSIENT = 'test_drive_account_settings_for_live_account';

	/**
	 * The platform client double both the onboarding service and the account service use.
	 *
	 * @var Client|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $client;

	/**
	 * The account service the container hands out, with the setup tier and runtime arbiter wired in.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * The setup tier store.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $tier;

	/**
	 * The onboarding service under test.
	 *
	 * @var OnboardingService
	 */
	private OnboardingService $sut;

	/**
	 * Set up a store whose built-in WooPayments is on, with a platform client that answers as connected.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );

		$this->client = $this->createMock( Client::class );
		$this->client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $this->client );

		$container = wc_get_container();
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->tier            = $container->get( WooPaymentsSetupTier::class );
		$this->account_service = $container->get( WooPaymentsAccountService::class );
		$this->tier->invalidate();

		$this->sut = new OnboardingService();
		$this->sut->init( $container->get( LegacyProxy::class ), new PaymentMethodRegistry(), $this->client, $this->account_service );
	}

	/**
	 * Drop the container replacement and the memoized owner and tier the tests changed.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
		} finally {
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			$this->tier->invalidate();
			parent::tearDown();
		}
	}

	/**
	 * The failures a reset can end in, each with both prior tiers.
	 *
	 * The two prior tiers are each row's control: a restore that wrote a constant would pass one and fail the other.
	 *
	 * @return array<string,array{string,\Closure,string}>
	 */
	public static function provide_failed_deletes(): array {
		$failures = array(
			// The platform refuses to delete a live account that completed onboarding.
			'F1 refusal'                  => static fn() => new ApiException( 'Unable to delete live accounts which have completed onboarding.', 'bad_request', 400, 'invalid_request_error' ),
			// A 5xx answer.
			'F2 platform error'           => static fn() => new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ),
			// The transport failed, as Client::request_decoded() throws it.
			'F3 transport failure'        => static fn() => new ApiException( 'Http request failed. Reason: Could not resolve host', 'wcpay_http_request_failed', 500, '', '', array( 'transport_error_code' => 'http_request_failed' ) ),
			// The connection to WordPress.com was lost: another code, the same branch.
			'F3 control: connection lost' => static fn() => new ApiException( 'Site is not connected to WordPress.com.', 'wcpay_wpcom_not_connected', 409 ),
			// The call timed out (cURL error 28), and the platform may have carried the delete out.
			'F4 timeout'                  => static fn() => new ApiException( 'Http request failed. Reason: cURL error 28: Operation timed out', 'wcpay_http_request_failed', 500, '', '', array( 'transport_error_code' => 'http_request_failed' ) ),
		);

		$rows = array();
		foreach ( $failures as $label => $failure ) {
			$rows[ $label . ', prior active' ]    = array( 'yes', $failure, WooPaymentsSetupTier::ACTIVE );
			$rows[ $label . ', prior connected' ] = array( 'no', $failure, WooPaymentsSetupTier::CONNECTED );
		}

		return $rows;
	}

	/**
	 * @testdox A reset the platform fails leaves the setup tier as it was and the account cache emptied for the next read: $_dataName.
	 * @dataProvider provide_failed_deletes
	 *
	 * @param string   $gateway_enabled The gateway's enabled setting.
	 * @param \Closure $failure         Builds the exception the platform call throws.
	 * @param string   $prior_tier      The tier the store holds before the reset.
	 */
	public function test_a_reset_the_platform_fails_leaves_the_setup_tier_as_it_was( string $gateway_enabled, \Closure $failure, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willThrowException( $failure() );

		$result = $this->sut->reset_onboarding();

		$this->assertInstanceOf( WP_Error::class, $result, 'The platform error is still answered.' );
		$this->assert_tier_is( $prior_tier );
		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ), 'The next read fetches the account.' );
		$this->assertSame( $gateway_enabled, get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'The gateway setting is unchanged.' );
	}

	/**
	 * @testdox F4 control: after a timeout the platform did carry out, the next account read finds no account and the tier falls to available at once.
	 */
	public function test_f4_after_a_timeout_the_platform_carried_out_the_next_read_lowers_the_tier(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willThrowException( new ApiException( 'Http request failed. Reason: cURL error 28: Operation timed out', 'wcpay_http_request_failed', 500 ) );
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );

		$this->sut->reset_onboarding();
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );

		$this->assertSame( array(), $this->account_service->get_cached_account_data() );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->tier->get_effective_tier(), 'A deleted account is not offered as connected.' );
	}

	/**
	 * @testdox F5: a 200 answer without success leaves the setup tier as it was, with $prior_tier.
	 * @testWith ["yes", "active"]
	 *           ["no", "connected"]
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the reset.
	 */
	public function test_f5_a_200_answer_without_success_leaves_the_setup_tier_as_it_was( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willReturn( array( 'result' => 'error' ) );

		$result = $this->sut->reset_onboarding();

		$this->assertFalse( $result['success'] );
		$this->assert_tier_is( $prior_tier );
		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ) );
		$this->assertSame( $gateway_enabled, get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'] );
	}

	/**
	 * @testdox F5 control: a 200 answer with success still ends at available, with the gateway off, the account cache empty and the store reset.
	 */
	public function test_f5_control_a_deleted_account_still_ends_at_available(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willReturn( array( 'result' => 'success' ) );

		$result = $this->sut->reset_onboarding();

		$this->assertTrue( $result['success'] );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( 'no', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'] );
		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ), 'The reset empties the cache for the next read.' );
		$this->assertSame( 'no', get_option( 'wcpay_onboarding_test_mode' ) );
	}

	/**
	 * @testdox F6: a test-drive account whose delete the platform refuses keeps its tier, and the payment methods saved for the live account stay saved, with $prior_tier.
	 * @testWith ["yes", "active"]
	 *           ["no", "connected"]
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the disable.
	 */
	public function test_f6_a_refused_test_account_disable_keeps_the_tier_and_the_saved_methods( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->client->expects( $this->once() )->method( 'delete_account' )->with( true )->willThrowException( new ApiException( 'Refused.', 'bad_request', 400 ) );

		$result = $this->sut->disable_test_account( true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assert_tier_is( $prior_tier );
		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ) );
		$saved = get_transient( self::TEST_DRIVE_SETTINGS_TRANSIENT );
		$this->assertIsArray( $saved, 'The restore leaves the methods saved before the call.' );
		$this->assertSame( array( 'card' ), $saved['enabled_payment_methods'] );
	}

	/**
	 * Put a connected account in the store, with the gateway on or off, and check the tier it gives.
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $expected_tier   The tier the account write must give: active or connected.
	 */
	private function store_has_account( string $gateway_enabled, string $expected_tier ): void {
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'                        => $gateway_enabled,
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cache_account_data(
			array(
				'account_id'        => 'acct_123',
				'is_live'           => true,
				'payments_enabled'  => true,
				'details_submitted' => true,
			)
		);
		$this->assert_tier_is( $expected_tier );
	}

	/**
	 * Assert the stored tier, read past the request memo.
	 *
	 * @param string $expected The expected tier.
	 */
	private function assert_tier_is( string $expected ): void {
		$this->tier->invalidate();
		wp_cache_delete( WooPaymentsSetupTier::OPTION_NAME, 'options' );
		$this->assertSame( $expected, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'Stored tier.' );
		$this->assertSame( $expected, $this->tier->get_stored_tier(), 'Tier the store reads.' );
	}
}
