<?php
/**
 * WooPaymentsPaymentsSettingsAdapter tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OnboardingRedirect;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentsSettingsAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use Automattic\WooCommerce\Tests\Internal\Admin\Settings\Mocks\FakePaymentGateway;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments onboarding adapter used by native admin surfaces.
 */
class WooPaymentsPaymentsSettingsAdapterTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var WooPaymentsPaymentsSettingsAdapter
	 */
	private WooPaymentsPaymentsSettingsAdapter $adapter;

	/**
	 * Mockable legacy proxy.
	 *
	 * @var MockableLegacyProxy
	 */
	private MockableLegacyProxy $legacy_proxy;

	/**
	 * WooPayments provider mock.
	 *
	 * @var WooPaymentsProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $provider;

	/**
	 * Admin payment gateway provider.
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $payment_gateway_provider;

	/**
	 * Legacy gateway test double.
	 *
	 * @var FakePaymentGateway
	 */
	private FakePaymentGateway $gateway;

	/**
	 * Built-in gateway test double.
	 *
	 * @var WooPaymentsGateway
	 */
	private WooPaymentsGateway $builtin_gateway;

	/**
	 * Account service mock.
	 *
	 * @var object|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $account_service;

	/**
	 * Built-in WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $builtin_account_service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->legacy_proxy = wc_get_container()->get( LegacyProxy::class );
		$this->legacy_proxy->reset();

		$this->provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'can_manage_onboarding' ) )
			->getMock();

		$this->payment_gateway_provider = new PaymentGateway( $this->legacy_proxy );
		$this->gateway                  = new FakePaymentGateway( 'woocommerce_payments' );
		$this->builtin_gateway          = new WooPaymentsGateway();
		$this->account_service          = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'is_stripe_account_valid', 'get_account_status_data' ) )
			->getMock();
		$this->builtin_account_service  = new WooPaymentsAccountService();
		$database_cache                 = new WooPaymentsDatabaseCache();
		$database_cache->init( $this->legacy_proxy );
		$this->builtin_account_service->init( $this->legacy_proxy, $database_cache );

		$this->legacy_proxy->register_static_mocks(
			array(
				'WC_Payments'         => array(
					'get_gateway'         => fn() => $this->gateway,
					'get_account_service' => fn() => $this->account_service,
				),
				'WC_Payments_Account' => array(
					'get_connect_url'       => fn() => 'https://example.com/kyc',
					'get_overview_page_url' => fn() => 'https://example.com/overview',
				),
			)
		);

		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( $this->legacy_proxy );

		$this->adapter = new WooPaymentsPaymentsSettingsAdapter();
		$this->init_adapter( $this->adapter, $extension_runtime, $this->provider, $this->builtin_gateway, $this->builtin_account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
	}

	/**
	 * Reset proxy mocks after each test.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_onboarding_test_mode' );
		$this->legacy_proxy->reset();

		parent::tearDown();
	}

	/**
	 * @testdox The onboarding runtime is available while the WooPayments extension is active or the built-in WooPayments can manage onboarding: $_dataName.
	 * @dataProvider provide_onboarding_runtimes
	 *
	 * @param bool      $extension_active Whether the WooPayments extension class exists.
	 * @param bool|null $builtin_enabled  The built-in enablement filter answer, or null when not filtered.
	 * @param bool|null $can_manage       Whether the provider can manage onboarding, or null when not stubbed.
	 * @param bool      $can_process      Whether the provider can process payments.
	 * @param bool      $expected         Whether the onboarding runtime is available.
	 */
	public function test_onboarding_runtime_availability( bool $extension_active, ?bool $builtin_enabled, ?bool $can_manage, bool $can_process, bool $expected ): void {
		$this->arrange_runtimes( $extension_active, $builtin_enabled, $can_manage, $can_process );

		self::assertSame( $expected, $this->adapter->is_onboarding_runtime_available() );
	}

	/**
	 * Runtime states with whether onboarding is available.
	 *
	 * @return array<string,array{bool,bool|null,bool|null,bool,bool}>
	 */
	public function provide_onboarding_runtimes(): array {
		return array(
			'extension active'  => array( true, null, null, false, true ),
			'built-in owner, can manage, before it can process' => array( false, true, true, false, true ),
			'built-in disabled' => array( false, false, true, true, false ),
		);
	}

	/**
	 * @testdox The WooPayments extension's gateway wins while it is active, otherwise the injected built-in gateway while built-in onboarding is available: $_dataName.
	 * @dataProvider provide_payment_gateway_runtimes
	 *
	 * @param bool        $extension_active Whether the WooPayments extension class exists.
	 * @param bool|null   $builtin_enabled  The built-in enablement filter answer, or null when not filtered.
	 * @param bool|null   $can_manage       Whether the provider can manage onboarding, or null when not stubbed.
	 * @param bool        $can_process      Whether the provider can process payments.
	 * @param string|null $expected_gateway 'extension' or 'builtin', or null when no gateway is available.
	 */
	public function test_get_payment_gateway( bool $extension_active, ?bool $builtin_enabled, ?bool $can_manage, bool $can_process, ?string $expected_gateway ): void {
		$this->arrange_runtimes( $extension_active, $builtin_enabled, $can_manage, $can_process );

		if ( null === $expected_gateway ) {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'WooPayments gateway is not available.' );
		}

		$gateway = $this->adapter->get_payment_gateway();

		self::assertSame( 'extension' === $expected_gateway ? $this->gateway : $this->builtin_gateway, $gateway );
	}

	/**
	 * Runtime states with the gateway the adapter returns.
	 *
	 * @return array<string,array{bool,bool|null,bool|null,bool,string|null}>
	 */
	public function provide_payment_gateway_runtimes(): array {
		return array(
			'built-in available before the account can process' => array( false, true, true, false, 'builtin' ),
			'built-in onboarding unavailable' => array( false, true, false, false, null ),
			'extension active while both runtimes are available' => array( true, null, null, true, 'extension' ),
		);
	}

	/**
	 * @testdox Account state fails closed when neither the WooPayments extension nor the built-in provider can operate.
	 */
	public function test_account_state_is_fail_closed_when_no_runtime_is_available(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		$this->provider->method( 'can_process_payments' )->willReturn( false );

		self::assertFalse( $this->adapter->has_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_valid_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_working_account( $this->payment_gateway_provider ) );
	}

	/**
	 * @testdox The WooPayments extension's account state is normalized through the adapter.
	 */
	public function test_woopayments_extension_account_state_is_normalized(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_to_check ) => 'WC_Payments' === ltrim( (string) $class_to_check, '\\' ),
			)
		);
		$this->provider->method( 'can_process_payments' )->willReturn( false );
		$this->account_service
			->method( 'is_stripe_account_valid' )
			->willReturn( true );
		$this->account_service
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'paymentsEnabled' => true,
					'testDrive'       => true,
					'isLive'          => false,
				)
			);

		self::assertTrue( $this->adapter->has_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_valid_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_working_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_test_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_sandbox_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_live_account( $this->payment_gateway_provider ) );
	}

	/**
	 * @testdox Built-in account state is normalized from the stored account cache.
	 */
	public function test_builtin_account_state_is_normalized_from_account_cache(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->provider->method( 'can_process_payments' )->willReturn( true );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'           => 'acct_123',
					'test_publishable_key' => 'pk_test_123',
					'is_live'              => false,
					'is_test_drive'        => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );

		self::assertTrue( $this->adapter->has_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_valid_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_working_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_test_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_sandbox_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_live_account( $this->payment_gateway_provider ) );
	}

	/**
	 * @testdox Extension runtime seam supplies extension, gateway, account, and URL data.
	 */
	public function test_extension_runtime_seam_supplies_extension_gateway_account_and_url_data(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => 'WC_Payments' === ltrim( (string) $class_name, '\\' ) || class_exists( $class_name ),
				'is_callable'  => fn( $target ) => '\WC_Payments_Account::get_connect_url' === $target || is_callable( $target ),
			)
		);
		$this->legacy_proxy->register_static_mocks(
			array(
				'WC_Payments_Account' => array(
					'get_connect_url' => fn() => 'https://example.com/runtime-connect',
				),
			)
		);
		$runtime = new WooPaymentsExtensionRuntime();
		$runtime->init( $this->legacy_proxy );

		$adapter = new WooPaymentsPaymentsSettingsAdapter();
		$this->init_adapter( $adapter, $runtime, $this->provider, new WooPaymentsGateway(), $this->builtin_account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		$this->provider->method( 'can_process_payments' )->willReturn( false );
		$this->account_service
			->method( 'is_stripe_account_valid' )
			->willReturn( true );
		$this->account_service
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'paymentsEnabled' => true,
					'testDrive'       => false,
					'isLive'          => true,
				)
			);

		self::assertTrue( $adapter->is_extension_active() );
		self::assertSame( $this->gateway, $adapter->get_payment_gateway() );
		self::assertTrue( $adapter->has_valid_account( $this->payment_gateway_provider ) );
		self::assertTrue( $adapter->has_working_account( $this->payment_gateway_provider ) );
		self::assertTrue( $adapter->has_live_account( $this->payment_gateway_provider ) );
		self::assertSame( 'https://example.com/runtime-connect', $adapter->get_onboarding_kyc_fallback_url( $this->payment_gateway_provider ) );
	}

	/**
	 * @testdox The settings adapter should resolve no built-in collaborator when initialized, only on first use.
	 */
	public function test_init_resolves_no_builtin_collaborator(): void {
		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( $this->legacy_proxy );
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$adapter = new WooPaymentsPaymentsSettingsAdapter();

		$on_init = $this->get_container_requests_during( fn() => $adapter->init( $extension_runtime, $arbiter ) );
		$on_use  = $this->get_container_requests_during( fn() => $adapter->store_referral_code( 'partner-code' ) );

		$this->assertSame( array(), $on_init, 'The adapter must not ask the container for a collaborator when it is initialized.' );
		$this->assertContains( WooPaymentsOnboardingService::class, $on_use, 'The adapter must ask the container for the onboarding service when it first needs it.' );
	}

	/**
	 * The built-in onboarding runs only while the platform connection is available and the built-in WooPayments owns
	 * the store. The extension check between them has no case: while the extension is active it owns the store, so the
	 * ownership condition already fails.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool}>
	 */
	public function provide_builtin_onboarding_conditions(): array {
		return array(
			'all hold'                              => array( true, true, true, true ),
			'platform connection unavailable'       => array( false, true, true, false ),
			'built-in WooPayments does not own'     => array( true, false, true, false ),
			'the provider cannot manage onboarding' => array( true, true, false, false ),
		);
	}

	/**
	 * @testdox The built-in onboarding is offered only when every condition holds: $_dataName.
	 * @dataProvider provide_builtin_onboarding_conditions
	 *
	 * @param bool $api_available    Whether the platform connection is available.
	 * @param bool $builtin_owner    Whether the built-in WooPayments owns the store.
	 * @param bool $can_manage       Whether the provider can manage onboarding.
	 * @param bool $expect_onboarding Whether the built-in onboarding is expected.
	 */
	public function test_builtin_onboarding_runs_only_when_its_conditions_hold( bool $api_available, bool $builtin_owner, bool $can_manage, bool $expect_onboarding ): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $builtin_owner ? 'yes' : 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->provider->method( 'can_manage_onboarding' )->willReturn( $can_manage );
		$this->set_adapter_api_client( $api_available );
		$onboarding = $this->createMock( WooPaymentsOnboardingService::class );
		wc_get_container()->replace( WooPaymentsOnboardingService::class, $onboarding );

		$result = $this->adapter->get_builtin_onboarding();

		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		if ( $expect_onboarding ) {
			$this->assertSame( $onboarding, $result );
		} else {
			$this->assertNull( $result );
		}
	}

	/**
	 * @testdox The KYC fields come from the platform unless the extension is active or the platform connection is unavailable.
	 * @testWith [true, true, "extension"]
	 *           [false, false, "unavailable"]
	 *           [false, true, "fields"]
	 *
	 * @param bool   $extension_active Whether the WooPayments extension is loaded.
	 * @param bool   $api_available    Whether the platform connection is available.
	 * @param string $expected         The expected answer.
	 */
	public function test_onboarding_fields_come_from_the_platform_only_without_the_extension( bool $extension_active, bool $api_available, string $expected ): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => $extension_active && 'WC_Payments' === ltrim( (string) $class_name, '\\' ),
			)
		);
		$this->set_adapter_api_client( $api_available );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		// Synthetic fields answer: only its pass-through is checked (client 11.1.0's business types fixture is tests/unit/test-class-wc-payments-onboarding-service.php:50).
		$account_service->method( 'get_onboarding_fields_data' )->with( 'de_DE' )->willReturn( array( 'business_types' => array() ) );
		$this->set_adapter_property( 'account_service', $account_service );

		$fields = $this->adapter->get_onboarding_fields( 'de_DE' );

		$this->assertSame( 'fields' === $expected ? array( 'business_types' => array() ) : null, $fields );
	}

	/**
	 * @testdox The KYC fields read fails with the extension's message when the platform answers none.
	 */
	public function test_onboarding_fields_fail_when_the_platform_answers_none(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		$this->set_adapter_api_client( true );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->method( 'get_onboarding_fields_data' )->willReturn( null );
		$this->set_adapter_property( 'account_service', $account_service );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Failed to retrieve the onboarding fields.' );

		$this->adapter->get_onboarding_fields( 'en_US' );
	}

	/**
	 * @testdox WooPay is turned on after KYC only when the platform asked for it and the built-in onboarding runs.
	 * @testWith [false, true, false]
	 *           [true, false, false]
	 *           [true, true, true]
	 *
	 * @param bool $asked           Whether the platform's answer is stored.
	 * @param bool $builtin_owner   Whether the built-in WooPayments owns the store.
	 * @param bool $expect_activate Whether WooPay is expected to be turned on.
	 */
	public function test_woopay_default_is_applied_only_when_asked_and_built_in( bool $asked, bool $builtin_owner, bool $expect_activate ): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $builtin_owner ? 'yes' : 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		if ( $asked ) {
			set_transient( WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		}
		$onboarding = $this->createMock( WooPaymentsOnboardingService::class );
		$onboarding->expects( $expect_activate ? $this->once() : $this->never() )->method( 'activate_woopay_enabled_by_default' );
		wc_get_container()->replace( WooPaymentsOnboardingService::class, $onboarding );
		if ( $asked ) {
			$this->set_adapter_api_client( true );
		}

		$this->adapter->maybe_activate_woopay_enabled_by_default();

		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		delete_transient( WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		$reflection = new \ReflectionProperty( WooPaymentsPaymentsSettingsAdapter::class, 'api_client' );
		$reflection->setAccessible( true );
		// Without a stored answer the landing page resolves no API client.
		$this->assertSame( $asked, null !== $reflection->getValue( $this->adapter ) );
	}

	/**
	 * @testdox The WooCommerce update clears the onboarding fields cache only while the built-in WooPayments owns payments.
	 *
	 * @testWith [true, 1]
	 *           [false, 0]
	 *
	 * @param bool $builtin_owner Whether the built-in WooPayments owns payments.
	 * @param int  $clears        Expected cache clears.
	 */
	public function test_onboarding_fields_cache_is_cleared_only_for_the_builtin_owner( bool $builtin_owner, int $clears ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $builtin_owner ? 'yes' : 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->exactly( $clears ) )->method( 'clear_onboarding_fields_cache' );
		$this->set_adapter_property( 'account_service', $account_service );

		$this->adapter->clear_builtin_onboarding_fields_cache();

		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * @testdox The onboarding redirect request reaches the container's onboarding redirect.
	 */
	public function test_onboarding_redirect_request_reaches_the_onboarding_redirect(): void {
		$redirect = $this->createMock( OnboardingRedirect::class );
		$redirect->expects( $this->once() )->method( 'maybe_redirect' );
		wc_get_container()->replace( OnboardingRedirect::class, $redirect );

		$this->adapter->maybe_redirect_to_onboarding();
	}

	/**
	 * @testdox The settings notice is the notice service's answer for the current user.
	 */
	public function test_settings_notice_comes_from_the_notice_service(): void {
		$notice  = array( 'id' => 'test_to_live' );
		$service = $this->createMock( NoticeService::class );
		$service->expects( $this->once() )->method( 'refresh_notice_for_current_user' )->willReturn( $notice );
		wc_get_container()->replace( NoticeService::class, $service );

		$this->assertSame( $notice, $this->adapter->refresh_settings_notice_for_current_user() );
	}

	/**
	 * @testdox A partner's referral code is stored before the store connects to the platform.
	 */
	public function test_referral_code_is_stored_without_a_platform_connection(): void {
		$this->set_adapter_api_client( false );

		$stored = $this->adapter->store_referral_code( '  Partner-CODE ' );

		$this->assertSame( 'partner-code', $stored );
		$this->assertSame( 'partner-code', get_transient( 'woopayments_referral_code' ) );
	}

	/**
	 * Arrange which runtimes can run onboarding.
	 *
	 * @param bool      $extension_active Whether the WooPayments extension class exists.
	 * @param bool|null $builtin_enabled  The built-in enablement filter answer, or null when not filtered.
	 * @param bool|null $can_manage       Whether the provider can manage onboarding, or null when not stubbed.
	 * @param bool      $can_process      Whether the provider can process payments.
	 */
	private function arrange_runtimes( bool $extension_active, ?bool $builtin_enabled, ?bool $can_manage, bool $can_process ): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_to_check ) => $extension_active && 'WC_Payments' === ltrim( (string) $class_to_check, '\\' ),
			)
		);
		if ( null !== $builtin_enabled ) {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $builtin_enabled ? 'yes' : 'no' );
		}
		if ( null !== $can_manage ) {
			$this->provider->method( 'can_manage_onboarding' )->willReturn( $can_manage );
		}
		$this->provider->method( 'can_process_payments' )->willReturn( $can_process );
	}

	/**
	 * Give the adapter an API client double.
	 *
	 * @param bool $available Whether the platform connection is available.
	 */
	private function set_adapter_api_client( bool $available ): void {
		$api_client = $this->createMock( Client::class );
		$api_client->method( 'is_available' )->willReturn( $available );
		$this->set_adapter_property( 'api_client', $api_client );
	}

	/**
	 * Set one of the adapter's lazily resolved collaborators.
	 *
	 * @param string $property     The property.
	 * @param object $collaborator The collaborator.
	 */
	private function set_adapter_property( string $property, object $collaborator ): void {
		$reflection = new \ReflectionProperty( WooPaymentsPaymentsSettingsAdapter::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( $this->adapter, $collaborator );
	}

	/**
	 * Initialize an onboarding adapter with its native collaborators.
	 *
	 * The adapter resolves them on first use, so the test doubles are set on the instance after init.
	 *
	 * @param WooPaymentsPaymentsSettingsAdapter $adapter           The adapter.
	 * @param WooPaymentsExtensionRuntime        $extension_runtime The extension runtime.
	 * @param WooPaymentsProvider                $provider          The native provider.
	 * @param WooPaymentsGateway                 $builtin_gateway   The built-in gateway.
	 * @param WooPaymentsAccountService          $account_service   The native account service.
	 * @param WooPaymentsRuntimeArbiter          $arbiter           The runtime arbiter.
	 */
	private function init_adapter( WooPaymentsPaymentsSettingsAdapter $adapter, WooPaymentsExtensionRuntime $extension_runtime, WooPaymentsProvider $provider, WooPaymentsGateway $builtin_gateway, WooPaymentsAccountService $account_service, WooPaymentsRuntimeArbiter $arbiter ): void {
		$adapter->init( $extension_runtime, $arbiter );

		$collaborators = array(
			'provider'        => $provider,
			'builtin_gateway' => $builtin_gateway,
			'account_service' => $account_service,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsPaymentsSettingsAdapter::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $adapter, $collaborator );
		}
	}

	/**
	 * Run a callback while recording every class the global container is asked for.
	 *
	 * @param callable $callback Code to run.
	 * @return string[] Class names requested from the container, in order.
	 */
	private function get_container_requests_during( callable $callback ): array {
		$container = wc_get_container();
		$requested = array();
		$recorder  = $this->getMockBuilder( TestingContainer::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get' ) )
			->getMock();
		$recorder->method( 'get' )->willReturnCallback(
			static function ( string $class_name ) use ( $container, &$requested ) {
				$requested[] = $class_name;
				return $container->get( $class_name );
			}
		);

		$GLOBALS['wc_container'] = $recorder;
		try {
			$callback();
		} finally {
			$GLOBALS['wc_container'] = $container;
		}

		return $requested;
	}
}
