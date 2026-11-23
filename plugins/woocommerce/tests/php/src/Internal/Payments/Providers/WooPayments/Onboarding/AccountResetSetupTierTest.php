<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\OnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Pins that an account delete the platform refuses, or that fails, puts back the account cache row and the setup tier.
 *
 * The delete empties the account cache first, which lowers the stored tier to available, so nothing reads the account
 * while the platform deletes it. When the platform does not delete the account, the row comes back marked as never
 * fetched, so the first reader still fetches the account inside its request, and a fetch that fails serves the restored
 * account. The tier comes back as it was read; a store taking payments keeps its gateway, its webhook route and its
 * renewals. The NOX onboarding profile, which the reset route deletes whatever the answer, is not put back by this change.
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
			wc_get_container()->reset_all_resolved();
			parent::tearDown();
		}
	}

	/**
	 * The failures a reset can end in, each with both prior tiers.
	 *
	 * The two prior tiers are each row's control: a restore that wrote a constant would pass one and fail the other. The
	 * prior connected rows start from a row that holds two errored reads, so the restore must keep the error state too.
	 *
	 * @return array<string,array{string,\Closure,string,bool}>
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
			$rows[ $label . ', prior active' ]    = array( 'yes', $failure, WooPaymentsSetupTier::ACTIVE, false );
			$rows[ $label . ', prior connected' ] = array( 'no', $failure, WooPaymentsSetupTier::CONNECTED, true );
		}

		return $rows;
	}

	/**
	 * @testdox A reset the platform fails puts back the setup tier and the account cache row, marked as never fetched: $_dataName.
	 * @dataProvider provide_failed_deletes
	 *
	 * @param string   $gateway_enabled The gateway's enabled setting.
	 * @param \Closure $failure         Builds the exception the platform call throws.
	 * @param string   $prior_tier      The tier the store holds before the reset.
	 * @param bool     $errored_row     Whether the row before the reset holds two errored reads.
	 */
	public function test_a_reset_the_platform_fails_puts_back_the_tier_and_the_account_row( string $gateway_enabled, \Closure $failure, string $prior_tier, bool $errored_row ): void {
		$row_before = $this->store_has_account( $gateway_enabled, $prior_tier, $errored_row );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willThrowException( $failure() );

		$result = $this->sut->reset_onboarding();

		$this->assertInstanceOf( WP_Error::class, $result, 'The platform error is still answered.' );
		$this->assert_tier_is( $prior_tier );
		$this->assert_row_put_back( $row_before );
		$this->assertSame( $gateway_enabled, get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'The gateway setting is unchanged.' );
	}

	/**
	 * @testdox The tier goes back as it was read, not as the account and the gateway setting would give it.
	 */
	public function test_a_failed_reset_puts_back_the_tier_it_read(): void {
		$this->store_has_account( 'no', WooPaymentsSetupTier::CONNECTED );
		$this->tier->write_tier( WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willThrowException( new ApiException( 'Refused.', 'bad_request', 400 ) );

		$this->sut->reset_onboarding();

		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
	}

	/**
	 * @testdox When the restored row does not read back, it is deleted, so the next read fetches the account.
	 */
	public function test_a_restored_row_that_does_not_read_back_is_deleted(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willReturnCallback(
			function () {
				// From here on the account cache option keeps what it holds.
				add_filter( 'pre_update_option_' . self::ACCOUNT_CACHE_OPTION, array( $this, 'keep_old_value' ), 10, 2 );
				throw new ApiException( 'Refused.', 'bad_request', 400 );
			}
		);

		try {
			$this->sut->reset_onboarding();
		} finally {
			remove_filter( 'pre_update_option_' . self::ACCOUNT_CACHE_OPTION, array( $this, 'keep_old_value' ), 10 );
		}

		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ) );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
	}

	/**
	 * Keep an option's old value, as a failed write does.
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Old value.
	 * @return mixed
	 */
	public function keep_old_value( $value, $old_value ) {
		return $old_value;
	}

	/**
	 * @testdox After a refused reset, a failed account fetch serves the restored account and keeps the active tier, so checkout still offers the card gateway.
	 */
	public function test_after_a_refused_reset_a_failed_fetch_serves_the_restored_account(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willThrowException( new ApiException( 'Refused.', 'bad_request', 400 ) );
		$this->client->expects( $this->once() )->method( 'get_account' )->willThrowException( new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ) );

		$this->sut->reset_onboarding();

		$this->assertSame( $this->account(), $this->account_service->get_cached_account_data(), 'The failed fetch serves the restored account.' );
		$this->assertTrue( get_option( self::ACCOUNT_CACHE_OPTION )['errored'], 'The first reader did fetch the account.' );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		// The next request's checkout, with its services built from what is stored; a live account needs HTTPS there.
		update_option( 'woocommerce_force_ssl_checkout', 'yes' );
		wc_get_container()->reset_all_resolved();
		$gateway = wc_get_container()->get( WooPaymentsProvider::class )->get_gateway_for_method( 'card' );
		$this->assertNotNull( $gateway );
		$this->assertTrue( $gateway->is_available(), 'Checkout still offers the card gateway.' );
	}

	/**
	 * @testdox F4 control: after a timeout the platform did carry out, the restored row is marked as never fetched, so the first foreground account read refetches it, finds no account and lowers the tier to available in that request.
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
	 * @testdox F5: a 200 answer without success puts back the setup tier and the account row, with $prior_tier.
	 * @testWith ["yes", "active"]
	 *           ["no", "connected"]
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the reset.
	 */
	public function test_f5_a_200_answer_without_success_leaves_the_setup_tier_as_it_was( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willReturn( array( 'result' => 'error' ) );

		$row_before = get_option( self::ACCOUNT_CACHE_OPTION );

		$result = $this->sut->reset_onboarding();

		$this->assertFalse( $result['success'] );
		$this->assert_tier_is( $prior_tier );
		$this->assert_row_put_back( $row_before );
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
	 * @testdox F6: a test-drive account whose delete the platform refuses keeps its tier and its account row, and the payment methods saved for the live account stay saved, with $prior_tier.
	 * @testWith ["yes", "active"]
	 *           ["no", "connected"]
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the disable.
	 */
	public function test_f6_a_refused_test_account_disable_keeps_the_tier_and_the_saved_methods( string $gateway_enabled, string $prior_tier ): void {
		// A test-drive account is a sandbox account, valid to store while onboarding is in test mode.
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		$this->store_has_account( $gateway_enabled, $prior_tier, false, array_merge( $this->account(), array( 'is_live' => false ) ) );
		$this->client->expects( $this->once() )->method( 'delete_account' )->with( true )->willThrowException( new ApiException( 'Refused.', 'bad_request', 400 ) );

		$row_before = get_option( self::ACCOUNT_CACHE_OPTION );

		$result = $this->sut->disable_test_account( true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assert_tier_is( $prior_tier );
		$this->assert_row_put_back( $row_before );
		$saved = get_transient( self::TEST_DRIVE_SETTINGS_TRANSIENT );
		$this->assertIsArray( $saved, 'The restore leaves the methods saved before the call.' );
		$this->assertSame( array( 'card' ), $saved['enabled_payment_methods'] );
	}

	/**
	 * Put a connected account in the store, with the gateway on or off, check the tier it gives and get its cache row.
	 *
	 * @param string                   $gateway_enabled The gateway's enabled setting.
	 * @param string                   $expected_tier   The tier the account write must give: active or connected.
	 * @param bool                     $errored_row     Whether the row then holds two errored reads that kept the account.
	 * @param array<string,mixed>|null $account         The account to store, or null for the live account().
	 * @return array<string,mixed> The account cache row.
	 */
	private function store_has_account( string $gateway_enabled, string $expected_tier, bool $errored_row = false, ?array $account = null ): array {
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'                        => $gateway_enabled,
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cache_account_data( $account ?? $this->account() );
		$this->assert_tier_is( $expected_tier );
		if ( $errored_row ) {
			wc_get_container()->get( WooPaymentsDatabaseCache::class )->write(
				self::ACCOUNT_CACHE_OPTION,
				array(
					'data'               => $this->account(),
					'fetched'            => time() - 600,
					'errored'            => true,
					'consecutive_errors' => 2,
				)
			);
		}

		return get_option( self::ACCOUNT_CACHE_OPTION );
	}

	/**
	 * A connected live account, as the platform answers it.
	 *
	 * @return array<string,mixed>
	 */
	private function account(): array {
		return array(
			'account_id'           => 'acct_123',
			'is_live'              => true,
			'payments_enabled'     => true,
			'details_submitted'    => true,
			'live_publishable_key' => 'pk_live_123',
		);
	}

	/**
	 * Assert the account cache row came back with its account and error state as they were, marked as never fetched.
	 *
	 * @param array<string,mixed> $row_before The row before the reset.
	 */
	private function assert_row_put_back( array $row_before ): void {
		wp_cache_delete( self::ACCOUNT_CACHE_OPTION, 'options' );
		$row = get_option( self::ACCOUNT_CACHE_OPTION );
		$this->assertIsArray( $row, 'The account cache row is back.' );
		$this->assertSame( $row_before['data'], $row['data'], 'Its account.' );
		$this->assertSame( $row_before['errored'], $row['errored'], 'Its error state.' );
		$this->assertSame( $row_before['consecutive_errors'], $row['consecutive_errors'], 'Its error count.' );
		$this->assertSame( 0, $row['fetched'], 'Marked as never fetched.' );
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
