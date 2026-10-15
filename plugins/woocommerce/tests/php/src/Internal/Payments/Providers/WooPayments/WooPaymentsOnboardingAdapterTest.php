<?php
/**
 * WooPaymentsOnboardingAdapter tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Admin\Settings\Mocks\FakePaymentGateway;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments onboarding adapter used by native admin surfaces.
 */
class WooPaymentsOnboardingAdapterTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var WooPaymentsOnboardingAdapter
	 */
	private WooPaymentsOnboardingAdapter $adapter;

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
	 * Native gateway test double.
	 *
	 * @var WooPaymentsGateway
	 */
	private WooPaymentsGateway $native_gateway;

	/**
	 * Account service mock.
	 *
	 * @var object|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $account_service;

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $native_account_service;

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
		$this->native_gateway           = new WooPaymentsGateway();
		$this->account_service          = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'is_stripe_account_valid', 'get_account_status_data' ) )
			->getMock();
		$this->native_account_service   = new WooPaymentsAccountService();
		$database_cache                 = new WooPaymentsDatabaseCache();
		$database_cache->init( $this->legacy_proxy );
		$this->native_account_service->init( $this->legacy_proxy, $database_cache );

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

		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( $this->legacy_proxy );

		$this->adapter = new WooPaymentsOnboardingAdapter();
		$this->init_adapter( $this->adapter, $legacy_runtime, $this->provider, $this->native_gateway, $this->native_account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
	}

	/**
	 * Reset proxy mocks after each test.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_onboarding_test_mode' );
		remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
		$this->legacy_proxy->reset();

		parent::tearDown();
	}

	/**
	 * @testdox The legacy extension makes onboarding runtime available during the transition.
	 */
	public function test_runtime_available_when_legacy_extension_is_active(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_to_check ) => 'WC_Payments' === ltrim( (string) $class_to_check, '\\' ),
			)
		);
		$this->provider->method( 'can_process_payments' )->willReturn( false );

		self::assertTrue( $this->adapter->is_onboarding_runtime_available() );
	}

	/**
	 * @testdox A future native provider transport can make onboarding available without the plugin class.
	 */
	public function test_runtime_available_when_native_provider_can_process_without_plugin(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		$this->provider->method( 'can_process_payments' )->willReturn( true );

		self::assertTrue( $this->adapter->is_onboarding_runtime_available() );
	}

	/**
	 * @testdox Native onboarding is unavailable when the plugin is absent and the native runtime is disabled.
	 */
	public function test_runtime_unavailable_when_plugin_absent_and_native_runtime_disabled(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_false' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		$this->provider->method( 'can_process_payments' )->willReturn( true );

		self::assertFalse( $this->adapter->is_onboarding_runtime_available() );
	}

	/**
	 * @testdox Native onboarding runtime is available with transport even before the account can process payments.
	 */
	public function test_runtime_available_when_native_transport_exists_before_account_can_process(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		$this->provider->method( 'can_process_payments' )->willReturn( false );

		self::assertTrue( $this->adapter->is_onboarding_runtime_available() );
	}

	/**
	 * @testdox The injected native gateway is returned when native processing is available without the plugin.
	 */
	public function test_native_gateway_is_returned_when_native_provider_can_process_without_plugin(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		$this->provider->method( 'can_process_payments' )->willReturn( true );

		self::assertSame( $this->native_gateway, $this->adapter->get_payment_gateway() );
	}

	/**
	 * @testdox The injected native gateway is returned when onboarding is available before the account can process payments.
	 */
	public function test_native_gateway_is_returned_when_onboarding_is_available_before_account_can_process(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		$this->provider->method( 'can_process_payments' )->willReturn( false );

		self::assertSame( $this->native_gateway, $this->adapter->get_payment_gateway() );
	}

	/**
	 * @testdox Native gateway is not returned when native onboarding is unavailable.
	 */
	public function test_native_gateway_is_not_returned_when_native_onboarding_is_unavailable(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->provider->method( 'can_manage_onboarding' )->willReturn( false );
		$this->provider->method( 'can_process_payments' )->willReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'WooPayments gateway is not available.' );

		$this->adapter->get_payment_gateway();
	}

	/**
	 * @testdox Legacy gateway wins when both legacy and native runtimes are available.
	 */
	public function test_legacy_gateway_wins_when_both_legacy_and_native_are_available(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn( $class_to_check ) => 'WC_Payments' === ltrim( (string) $class_to_check, '\\' ),
			)
		);
		$this->provider->method( 'can_process_payments' )->willReturn( true );

		self::assertSame( $this->gateway, $this->adapter->get_payment_gateway() );
	}

	/**
	 * @testdox Account state fails closed when neither plugin nor native provider can operate.
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
	 * @testdox Legacy account state is normalized through the adapter.
	 */
	public function test_legacy_account_state_is_normalized(): void {
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
	 * @testdox Native account state is normalized from the preserved account cache.
	 */
	public function test_native_account_state_is_normalized_from_account_cache(): void {
		$this->legacy_proxy->register_function_mocks(
			array(
				'class_exists' => fn() => false,
			)
		);
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
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
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );

		self::assertTrue( $this->adapter->has_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_valid_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_working_account( $this->payment_gateway_provider ) );
		self::assertTrue( $this->adapter->has_test_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_sandbox_account( $this->payment_gateway_provider ) );
		self::assertFalse( $this->adapter->has_live_account( $this->payment_gateway_provider ) );
	}

	/**
	 * @testdox Legacy runtime seam supplies extension, gateway, account, and URL data.
	 */
	public function test_legacy_runtime_seam_supplies_extension_gateway_account_and_url_data(): void {
		$runtime = new WooPaymentsLegacyRuntime();
		$runtime->init(
			new LegacyRuntimeProxy(
				true,
				$this->gateway,
				$this->account_service,
				null,
				null,
				'https://example.com/runtime-connect'
			)
		);

		$adapter = new WooPaymentsOnboardingAdapter();
		$this->init_adapter( $adapter, $runtime, $this->provider, new WooPaymentsGateway(), $this->native_account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

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
	 * @testdox Onboarding adapter should resolve no native collaborator when initialized.
	 */
	public function test_init_resolves_no_native_collaborator(): void {
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( $this->legacy_proxy );
		$adapter = new WooPaymentsOnboardingAdapter();
		$adapter->init( $legacy_runtime, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		foreach ( array( 'provider', 'native_gateway', 'account_service', 'api_client', 'onboarding_service' ) as $property ) {
			$reflection = new \ReflectionProperty( WooPaymentsOnboardingAdapter::class, $property );
			$reflection->setAccessible( true );
			$this->assertNull( $reflection->getValue( $adapter ), $property . ' must be resolved on first use, not when the adapter is initialized.' );
		}
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
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, $builtin_owner ? '__return_true' : '__return_false' );
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
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, $builtin_owner ? '__return_true' : '__return_false' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->provider->method( 'can_manage_onboarding' )->willReturn( true );
		if ( $asked ) {
			set_transient( WooPaymentsOnboardingAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		}
		$onboarding = $this->createMock( WooPaymentsOnboardingService::class );
		$onboarding->expects( $expect_activate ? $this->once() : $this->never() )->method( 'activate_woopay_enabled_by_default' );
		wc_get_container()->replace( WooPaymentsOnboardingService::class, $onboarding );
		if ( $asked ) {
			$this->set_adapter_api_client( true );
		}

		$this->adapter->maybe_activate_woopay_enabled_by_default();

		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		delete_transient( WooPaymentsOnboardingAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		$reflection = new \ReflectionProperty( WooPaymentsOnboardingAdapter::class, 'api_client' );
		$reflection->setAccessible( true );
		// Without a stored answer the landing page resolves no API client.
		$this->assertSame( $asked, null !== $reflection->getValue( $this->adapter ) );
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
	 * Give the adapter an API client double.
	 *
	 * @param bool $available Whether the platform connection is available.
	 */
	private function set_adapter_api_client( bool $available ): void {
		$api_client = $this->createMock( WooPaymentsApiClient::class );
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
		$reflection = new \ReflectionProperty( WooPaymentsOnboardingAdapter::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( $this->adapter, $collaborator );
	}

	/**
	 * Initialize an onboarding adapter with its native collaborators.
	 *
	 * The adapter resolves them on first use, so the test doubles are set on the instance after init.
	 *
	 * @param WooPaymentsOnboardingAdapter $adapter         The adapter.
	 * @param WooPaymentsLegacyRuntime     $legacy_runtime  The legacy runtime.
	 * @param WooPaymentsProvider          $provider        The native provider.
	 * @param WooPaymentsGateway           $native_gateway  The native gateway.
	 * @param WooPaymentsAccountService    $account_service The native account service.
	 * @param WooPaymentsRuntimeArbiter    $arbiter         The runtime arbiter.
	 */
	private function init_adapter( WooPaymentsOnboardingAdapter $adapter, WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsProvider $provider, WooPaymentsGateway $native_gateway, WooPaymentsAccountService $account_service, WooPaymentsRuntimeArbiter $arbiter ): void {
		$adapter->init( $legacy_runtime, $arbiter );

		$collaborators = array(
			'provider'        => $provider,
			'native_gateway'  => $native_gateway,
			'account_service' => $account_service,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsOnboardingAdapter::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $adapter, $collaborator );
		}
	}
}
