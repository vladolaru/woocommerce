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
		$this->next_request();

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
	 * @testdox F4 control: after a timeout the platform did carry out, the restored row is marked as never fetched, so the first foreground account read refetches it and finds no account; the read keeps the active tier and checkout offers nothing.
	 */
	public function test_f4_after_a_timeout_the_platform_carried_out_the_next_read_keeps_the_tier(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		// A live account needs HTTPS at checkout; the card is offered before the reset.
		update_option( 'woocommerce_force_ssl_checkout', 'yes' );
		wc_get_container()->reset_all_resolved();
		$gateway = wc_get_container()->get( WooPaymentsProvider::class )->get_gateway_for_method( 'card' );
		$this->assertTrue( null !== $gateway && $gateway->is_available(), 'Checkout offers the card first.' );
		$this->client->method( 'delete_account' )->willThrowException( new ApiException( 'Http request failed. Reason: cURL error 28: Operation timed out', 'wcpay_http_request_failed', 500 ) );
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );

		$this->sut->reset_onboarding();
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->next_request();

		$this->assertSame( array(), $this->account_service->get_cached_account_data() );

		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		wc_get_container()->reset_all_resolved();
		$gateway = wc_get_container()->get( WooPaymentsProvider::class )->get_gateway_for_method( 'card' );
		$this->assertTrue( null === $gateway || ! $gateway->is_available(), 'Checkout offers nothing with no account.' );
	}

	/**
	 * @testdox K6: an empty account read on a store already at available writes no tier and keeps the cached options.
	 */
	public function test_k6_an_empty_read_at_available_writes_nothing(): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$this->tier->invalidate();
		wp_load_alloptions();
		$this->client->method( 'get_account' )->willReturn( array() );
		$writes = 0;
		$count  = static function ( $query ) use ( &$writes ) {
			if ( false !== strpos( $query, WooPaymentsSetupTier::OPTION_NAME ) && 0 !== stripos( ltrim( $query ), 'SELECT' ) ) {
				++$writes;
			}
			return $query;
		};
		add_filter( 'query', $count );

		try {
			$this->account_service->get_cached_account_data( true );
		} finally {
			remove_filter( 'query', $count );
		}

		$this->assertSame( 0, $writes, 'No tier write.' );
		$this->assertNotFalse( wp_cache_get( 'alloptions', 'options' ), 'The cached options stay.' );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * The delete refusals, each with what the one plain account read after it answers.
	 *
	 * @return array<string,array{ApiException,\Closure|null,int,bool}>
	 */
	public static function provide_delete_refusals(): array {
		$not_found = static fn() => new ApiException( 'No account.', 'wcpay_account_not_found', 401 );

		return array(
			'D2 not found, and the read answers empty'     => array( $not_found(), static fn() => array(), 1, true ),
			'D6 not found, and the read is refused as not found' => array(
				$not_found(),
				static function () {
					throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
				},
				1,
				false,
			),
			'D6 not found, and the read answers onboarding disabled' => array(
				$not_found(),
				static function () {
					throw new ApiException( 'Onboarding is disabled.', 'wcpay_on_boarding_disabled', 401 );
				},
				1,
				false,
			),
			'D3 not found, and the read finds the account' => array(
				$not_found(),
				static fn() => array(
					'account_id'           => 'acct_123',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'live_publishable_key' => 'pk_live_123',
				),
				1,
				false,
			),
			'D4 not found, and the read fails'             => array(
				$not_found(),
				static function () {
					throw new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 );
				},
				1,
				false,
			),
			'D5 another 401 refusal, with no read'         => array( new ApiException( 'Unauthorized.', 'wcpay_authorization_failure', 401 ), null, 0, false ),
			'D5 onboarding disabled, with no read'         => array( new ApiException( 'Disabled.', 'wcpay_on_boarding_disabled', 401 ), null, 0, false ),
		);
	}

	/**
	 * @testdox D2 to D6: a reset refused with $_dataName: the reset completes and keeps available only when the one account read in test mode answers empty; otherwise the tier and the row go back.
	 * @dataProvider provide_delete_refusals
	 *
	 * @param ApiException  $refusal    The delete's refusal.
	 * @param \Closure|null $read       What the plain account read answers or throws, or null for no read.
	 * @param int           $reads      How many plain account reads the reset sends.
	 * @param bool          $completes  Whether the reset completes.
	 */
	public function test_a_not_found_refusal_is_confirmed_by_one_account_read( ApiException $refusal, ?\Closure $read, int $reads, bool $completes ): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$row_before = get_option( self::ACCOUNT_CACHE_OPTION );
		$this->client->expects( $this->once() )->method( 'delete_account' )->willThrowException( $refusal );
		$plain = $this->client->expects( $this->exactly( $reads ) )->method( 'get_account_in_test_mode' );
		if ( null !== $read ) {
			$plain->willReturnCallback( $read );
		}

		$result = $this->sut->reset_onboarding();

		if ( $completes ) {
			$this->assertTrue( $result['success'] );
			$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
			$this->assertSame( 'no', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'The clean-up ran.' );
			$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ), 'The clean-up emptied the cache.' );
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->assert_row_put_back( $row_before );
		$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'] );
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
	 * The stores a reset finds with no account ID stored, and what the platform answers: the stored tier, the account row,
	 * the answer to the delete, the answer to the confirming read (null when none is expected), whether the reset
	 * completes and the tier it ends at.
	 *
	 * @return array<string,array{string|null,array<string,mixed>|null,\Closure,\Closure|null,bool,string|null}>
	 */
	public static function provide_stores_with_no_account_stored(): array {
		$empty     = array(
			'data'               => array(),
			'fetched'            => 1,
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		$deleted   = static fn() => array( 'result' => 'success' );
		$refused   = static function () {
			throw new ApiException( 'Unable to delete live accounts which have completed onboarding.', 'bad_request', 400, 'invalid_request_error' );
		};
		$not_found = static function () {
			throw new ApiException( 'No account.', 'wcpay_account_not_found', 401 );
		};
		$none      = static fn() => array();
		$disabled  = static function () {
			throw new ApiException( 'Onboarding is disabled.', 'wcpay_on_boarding_disabled', 401 );
		};

		return array(
			'G2 active with an empty row, deleted'         => array( WooPaymentsSetupTier::ACTIVE, $empty, $deleted, null, true, WooPaymentsSetupTier::AVAILABLE ),
			'G2 connected with no row, deleted'            => array( WooPaymentsSetupTier::CONNECTED, null, $deleted, null, true, WooPaymentsSetupTier::AVAILABLE ),
			'G2 active with an empty row, refused live'    => array( WooPaymentsSetupTier::ACTIVE, $empty, $refused, null, false, WooPaymentsSetupTier::ACTIVE ),
			'G3 available with an empty row, refused live' => array( WooPaymentsSetupTier::AVAILABLE, $empty, $refused, null, false, WooPaymentsSetupTier::AVAILABLE ),
			'G3 available with an empty row, no account'   => array( WooPaymentsSetupTier::AVAILABLE, $empty, $not_found, $none, true, WooPaymentsSetupTier::AVAILABLE ),
			'G3 disabled with no row, no account'          => array( WooPaymentsSetupTier::DISABLED, null, $not_found, $none, true, WooPaymentsSetupTier::DISABLED ),
			'G3 no stored tier and no row, no account'     => array( null, null, $not_found, $none, true, null ),
			'G3 disabled with no row, onboarding switched off' => array( WooPaymentsSetupTier::DISABLED, null, $not_found, $disabled, false, WooPaymentsSetupTier::DISABLED ),
		);
	}

	/**
	 * @testdox $_dataName: every reset asks the platform, and only its answer completes the reset.
	 * @dataProvider provide_stores_with_no_account_stored
	 *
	 * @param string|null              $tier      The stored tier, or null for none.
	 * @param array<string,mixed>|null $row       The account row, or null for none.
	 * @param \Closure                 $answer    The platform's answer to the delete.
	 * @param \Closure|null            $read      The answer to the confirming read, or null when none is expected.
	 * @param bool                     $completes Whether the reset completes.
	 * @param string|null              $expected  The stored tier after the reset, or null for none.
	 */
	public function test_a_reset_with_no_account_stored( ?string $tier, ?array $row, \Closure $answer, ?\Closure $read, bool $completes, ?string $expected ): void {
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'enabled' => 'yes' ) );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		if ( null !== $tier ) {
			update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
		}
		delete_option( self::ACCOUNT_CACHE_OPTION );
		if ( null !== $row ) {
			update_option( self::ACCOUNT_CACHE_OPTION, $row );
		}
		$this->tier->invalidate();
		$this->client->expects( $this->once() )->method( 'delete_account' )->willReturnCallback( $answer );
		$confirm = $this->client->expects( null === $read ? $this->never() : $this->once() )->method( 'get_account_in_test_mode' );
		if ( null !== $read ) {
			$confirm->willReturnCallback( $read );
		}

		$result = $this->sut->reset_onboarding();

		$this->tier->invalidate();
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame( $expected ?? false, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		if ( ! $completes ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'The store is unchanged.' );
			return;
		}
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'no', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'The clean-up ran.' );
		$this->assertFalse( get_option( self::ACCOUNT_CACHE_OPTION ) );
	}

	/**
	 * @testdox G9: the drop before the delete reads the tier the database holds now, so a tier raised since this request read it is lowered before the platform call.
	 */
	public function test_g9_the_drop_reads_the_database_tier(): void {
		global $wpdb;

		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->tier->write_tier( WooPaymentsSetupTier::AVAILABLE );
		$wpdb->update( $wpdb->options, array( 'option_value' => WooPaymentsSetupTier::ACTIVE ), array( 'option_name' => WooPaymentsSetupTier::OPTION_NAME ) );
		$tiers = array();
		$this->client->method( 'delete_account' )->willReturnCallback(
			static function () use ( $wpdb, &$tiers ): array {
				$tiers[] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WooPaymentsSetupTier::OPTION_NAME ) );
				return array( 'result' => 'error' );
			}
		);

		$this->sut->reset_onboarding();

		$this->assertSame( array( WooPaymentsSetupTier::AVAILABLE ), $tiers, 'Available in the database during the delete.' );
	}

	/**
	 * @testdox I6: a reset that succeeds ends at available although another request wrote connected to the database while the delete was in flight.
	 */
	public function test_i6_a_reset_ends_available_over_a_separate_writer(): void {
		global $wpdb;

		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willReturnCallback(
			static function () use ( $wpdb ): array {
				$wpdb->update( $wpdb->options, array( 'option_value' => WooPaymentsSetupTier::CONNECTED ), array( 'option_name' => WooPaymentsSetupTier::OPTION_NAME ) );
				return array( 'result' => 'success' );
			}
		);

		$this->assertTrue( $this->sut->reset_onboarding()['success'] );

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WooPaymentsSetupTier::OPTION_NAME ) ) );
	}

	/**
	 * @testdox G4: a reset whose lowered tier does not read back stops before the account row or the platform: the tier and the row stay.
	 */
	public function test_g4_a_drop_that_does_not_read_back_stops_the_reset(): void {
		$row_before = $this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->expects( $this->never() )->method( 'delete_account' );
		add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, array( $this, 'keep_old_value' ), 10, 2 );

		try {
			$result = $this->sut->reset_onboarding();
		} finally {
			remove_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, array( $this, 'keep_old_value' ), 10 );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'An unexpected error happened while resetting onboarding.', $result->get_error_message() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->assertSame( $row_before, get_option( self::ACCOUNT_CACHE_OPTION ), 'The row is untouched.' );
	}

	/**
	 * @testdox G5: an account read that publishes during a successful reset's clean-up does not leave the store raised.
	 */
	public function test_g5_a_read_published_during_the_clean_up_ends_at_available(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willReturn( array( 'result' => 'success' ) );
		$publish = function (): void {
			remove_all_actions( 'update_option_wcpay_onboarding_test_mode' );
			$this->account_service->cache_account_data( $this->account() );
		};
		// The clean-up writes the onboarding mode after it turns the gateway off.
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		add_action( 'update_option_wcpay_onboarding_test_mode', $publish );

		try {
			$result = $this->sut->reset_onboarding();
		} finally {
			remove_all_actions( 'update_option_wcpay_onboarding_test_mode' );
		}

		$this->assertTrue( $result['success'] );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * @testdox G8: once the reset has lowered the tier, an account read later in the same request serves what is stored without a fetch, so nothing raises the reset store again.
	 */
	public function test_g8_reads_after_the_drop_do_not_fetch(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'delete_account' )->willReturn( array( 'result' => 'success' ) );
		$this->client->expects( $this->never() )->method( 'get_account' );

		$this->assertTrue( $this->sut->reset_onboarding()['success'] );
		$this->assertSame( array(), $this->account_service->get_cached_account_data() );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * Start what the next request starts with: the services fresh, the reset's request-wide stop on account refreshes gone.
	 */
	private function next_request(): void {
		wc_get_container()->reset_all_resolved();
		$this->tier            = wc_get_container()->get( WooPaymentsSetupTier::class );
		$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
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
