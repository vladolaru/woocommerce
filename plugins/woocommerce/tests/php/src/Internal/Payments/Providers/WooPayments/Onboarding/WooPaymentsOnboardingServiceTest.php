<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\Jetpack\Connection\Manager as WPCOM_Connection_Manager;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsRestController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Admin\Settings\Mocks\FakePaymentGateway;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for the WooPayments onboarding built into WooCommerce.
 *
 * Most cases drive it through the Payments settings service and its REST routes, as the settings page does.
 *
 * @class WooPaymentsOnboardingService
 */
class WooPaymentsOnboardingServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * @var WooPaymentsService
	 */
	protected WooPaymentsService $sut;

	/**
	 * @var PaymentsProviders|MockObject
	 */
	protected $mock_providers;

	/**
	 * @var PaymentGateway|MockObject
	 */
	protected $mock_provider;

	/**
	 * @var MockableLegacyProxy|MockObject
	 */
	protected $mockable_proxy;

	/**
	 * @var WPCOM_Connection_Manager|MockObject
	 */
	protected $mock_wpcom_connection_manager;

	/**
	 * @var MockObject
	 */
	protected $mock_account_service;

	/**
	 * The ID of the store admin user.
	 *
	 * @var int
	 */
	protected $store_admin_id;

	/**
	 * The current time in seconds.
	 *
	 * Use it instead of time() to avoid using the real time in tests.
	 *
	 * @var int
	 */
	protected int $current_time;

	private const TEST_EPOCH = 1234567890;

	private const TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT = 'test_drive_account_settings_for_live_account';

	private const WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT = 'woopay_enabled_by_default';

	/**
	 * The onboarding service, for the tests that call it directly.
	 *
	 * @var WooPaymentsOnboardingService
	 */
	protected WooPaymentsOnboardingService $onboarding_service;


	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->store_admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->store_admin_id );

		$this->current_time = self::TEST_EPOCH;

		$this->mock_providers = $this->getMockBuilder( PaymentsProviders::class )
									->disableOriginalConstructor()
									->onlyMethods(
										array(
											'get_payment_gateway_provider_instance',
										)
									)
									->getMock();

			$this->mock_provider = $this->getMockBuilder( PaymentGateway::class )
										->disableOriginalConstructor()
										->getMock();
			$this->mock_provider
				->method( 'get_onboarding_url' )
				->willReturn( 'https://example.com/woopayments/onboarding' );

		$this->mock_providers
			->expects( $this->any() )
			->method( 'get_payment_gateway_provider_instance' )
			->willReturn( $this->mock_provider );

		$this->mock_wpcom_connection_manager = $this->getMockBuilder( WPCOM_Connection_Manager::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_connected',
					'has_connected_owner',
					'is_connection_owner',
					'try_registration',
				)
			)
			->getMock();

		$this->mockable_proxy = wc_get_container()->get( LegacyProxy::class );

		$this->mockable_proxy->register_class_mocks(
			array(
				WPCOM_Connection_Manager::class => $this->mock_wpcom_connection_manager,
			)
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				// Mock the current time.
				'time'         => function () {
					return $this->current_time;
				},
				// Everything is callable by default.
				'is_callable'  => function () {
					return true;
				},
				'class_exists' => function ( $class_to_check ) {
					// By default, the WooPayments extension is mocked as active.
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return true;
					}

					return false;
				},
			),
		);

		// Arrange the version constant to meet the minimum requirements for the native in-context onboarding.
		Constants::set_constant( 'WCPAY_VERSION_NUMBER', WooPaymentsService::EXTENSION_MINIMUM_VERSION );

		$this->mock_account_service = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'is_stripe_account_valid', 'get_account_status_data' ) )
			->getMock();

		$this->mockable_proxy->register_static_mocks(
			array(
				'WC_Payments'         => array(
					'get_gateway'         => function () {
						return new FakePaymentGateway();
					},
					'get_account_service' => function () {
						return $this->mock_account_service;
					},
				),
				'WC_Payments_Account' => array(
					'get_connect_url'       => function () {
						return 'https://example.com/kyc_fallback';
					},
					'get_overview_page_url' => function () {
						return 'https://example.com/overview_page?from=' . WooPaymentsService::FROM_NOX_IN_CONTEXT;
					},
				),
				'\WC_Payments_Utils'  => array(
					'supported_countries' => function () {
						return $this->get_woopayments_supported_countries();
					},
				),
				Utils::class          => array(
					'wc_payments_settings_url'           => function ( ?string $path = null, array $query = array() ) {
						$url = 'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout';

						if ( ! empty( $path ) ) {
							$url .= '&path=' . $path;
						}

						if ( ! empty( $query ) ) {
							$url .= '&' . http_build_query( $query );
						}

						return $url;
					},
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
					'get_wpcom_connection_authorization' => function ( string $return_url ) {
						unset( $return_url );
						return array(
							'success'      => true,
							'errors'       => array(),
							'color_scheme' => 'fresh',
							'url'          => 'https://wordpress.com/auth?query=some_query',
						);
					},
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					'rest_endpoint_get_request'          => function ( string $endpoint, array $params = array() ) {
						unset( $params );
						if ( '/wc/v3/payments/onboarding/fields' === $endpoint ) {
							return array(
								'data' => array(),
							);
						}

						throw new \Exception( esc_html( 'GET endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			),
		);

		$this->onboarding_service = new WooPaymentsOnboardingService();
		$this->onboarding_service->init( $this->mockable_proxy );

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$this->create_onboarding_adapter(),
			$this->create_extension_runtime(),
			$this->create_unavailable_api_client(),
			$this->create_native_account_service()
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		delete_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );
		delete_transient( 'woopayments_referral_code' );
		delete_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		unset( $_GET['wcpay-connection-success'] );
		remove_all_filters( 'woocommerce_tracks_event_properties' );
		remove_all_filters( 'wcpay_tracks_event_properties' );
		remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->clear_rest_server();

		parent::tearDown();
	}

	/**
	 * Initialize the service under test with its collaborators.
	 *
	 * The service resolves them on first use, so the test doubles are set on the instance after init, leaving other
	 * container consumers untouched. The adapter it gets answers the account questions through the test's adapter and
	 * runs the built-in onboarding through an onboarding service built on the same doubles.
	 *
	 * @param PaymentsProviders            $providers          The providers service.
	 * @param LegacyProxy                  $proxy              The legacy proxy.
	 * @param WooPaymentsOnboardingAdapter $onboarding_adapter The onboarding adapter.
	 * @param WooPaymentsExtensionRuntime  $extension_runtime     The extension runtime.
	 * @param WooPaymentsApiClient         $api_client         The API client.
	 * @param WooPaymentsAccountService    $account_service    The account service.
	 */
	private function init_sut( PaymentsProviders $providers, LegacyProxy $proxy, WooPaymentsOnboardingAdapter $onboarding_adapter, WooPaymentsExtensionRuntime $extension_runtime, WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->sut->init( $providers, $proxy );

		$onboarding_service = new WooPaymentsOnboardingService();
		$onboarding_service->init( $proxy );
		$onboarding_collaborators = array(
			'api_client'      => $api_client,
			'account_service' => $account_service,
		);
		foreach ( $onboarding_collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsOnboardingService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $onboarding_service, $collaborator );
		}

		$collaborators = array(
			'onboarding_adapter' => new DelegatingOnboardingAdapter( $onboarding_adapter, $api_client, $extension_runtime, $account_service, $onboarding_service ),
			'extension_runtime'  => $extension_runtime,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $this->sut, $collaborator );
		}
	}

	/**
	 * Test native embedded KYC sessions do not depend on plugin-owned onboarding REST routes.
	 *
	 * @return void
	 */
	public function test_get_onboarding_kyc_session_uses_native_api_when_legacy_onboarding_route_is_unavailable(): void {
		$location      = 'US';
		$captured_call = array();
		$api_client    = new class( $captured_call ) extends WooPaymentsApiClient {
			/**
			 * Captured KYC session request payload.
			 *
			 * @var array
			 */
			private array $captured_call;

			/**
			 * Constructor.
			 *
			 * @param array $captured_call Captured KYC session request payload.
			 */
			public function __construct( array &$captured_call ) {
				$this->captured_call = &$captured_call;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Capture an embedded KYC request and return a fake session.
			 *
			 * @param bool        $live_account   Whether the session is for a live account.
			 * @param array       $site_data      Site data.
			 * @param array       $user_data      User data.
			 * @param array       $account_data   Account data.
			 * @param array       $actioned_notes Actioned notes.
			 * @param string|null $referral_code  Referral code.
			 * @return array
			 */
			public function initialize_onboarding_embedded_kyc( bool $live_account, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), ?string $referral_code = null ): array {
				$this->captured_call = array(
					'live_account'   => $live_account,
					'site_data'      => $site_data,
					'user_data'      => $user_data,
					'account_data'   => $account_data,
					'actioned_notes' => $actioned_notes,
					'referral_code'  => $referral_code,
				);

				return array(
					'clientSecret'   => 'accs_secret_native',
					'expiresAt'      => 1234567999,
					'accountId'      => 'acct_native',
					'isLive'         => true,
					'accountCreated' => true,
					'publishableKey' => 'pk_live_native',
				);
			}
		};
		$adapter       = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
					'get_onboarding_kyc_fallback_url',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( false );
		$adapter->method( 'has_valid_account' )->willReturn( false );
		$adapter->method( 'has_working_account' )->willReturn( false );
		$adapter->method( 'has_test_account' )->willReturn( false );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );
		$adapter->method( 'get_onboarding_kyc_fallback_url' )->willReturn( 'https://example.com/native-kyc' );
		$this->mock_provider->method( 'is_in_dev_mode' )->willReturn( false );
		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array(
				'onboarding' => array(
					'US' => array(
						'steps' => array(
							WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
								'data' => array(
									'payment_methods' => array(
										'card' => true,
									),
								),
							),
						),
					),
				),
			)
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return false;
				},
			)
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.' );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_stale',
					'payments_enabled'  => true,
					'details_submitted' => true,
				),
			)
		);
		// Plugin 11.1.0 `WC_Payments_Onboarding_Service::init_embedded_kyc()` passes the stored referral code (line 380).
		set_transient( 'woopayments_referral_code', 'partner-abc', DAY_IN_SECONDS );

		$result = $this->sut->get_onboarding_kyc_session(
			$location,
			array(
				'business_type' => 'individual',
				'country'       => 'US',
			)
		);

		$this->assertSame( 'accs_secret_native', $result['clientSecret'] );
		$this->assertSame( 'pk_live_native', $result['publishableKey'] );
		$this->assertSame( 'en_US', $result['locale'] );
		$this->assertTrue( $result['isLive'] );
		$this->assertTrue( $captured_call['live_account'] );
		$this->assertSame( 'card_payments', array_key_first( $captured_call['account_data']['capabilities'] ) );
		$this->assertSame( 'partner-abc', $captured_call['referral_code'] );
		$cached = get_option( 'wcpay_account_data' );
		$this->assertIsArray( $cached );
		$this->assertSame( 'acct_native', $cached['data']['account_id'] );
		$this->assertSame( 'pk_live_native', $cached['data']['live_publishable_key'] );
		$this->assertTrue( $cached['data']['is_live'] );
		$this->assertFalse( $cached['data']['details_submitted'] );
	}

	/**
	 * The NOX routes that apply the payment-method picks, like client 11.1.0 create_embedded_kyc_session()
	 * (includes/class-wc-payments-onboarding-service.php:357-371) and init_test_drive_account() (:776-786).
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_nox_pick_routes(): array {
		return array(
			'embedded KYC session creation' => array( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ),
			'test-drive account init'       => array( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/init' ),
		);
	}

	/**
	 * The NOX pick routes with the enabled methods stored when each calls the platform: session creation applies the picks
	 * before the call (:369-371), test-drive init after the platform created the account (:776-786).
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function provide_nox_pick_routes_with_platform_call_state(): array {
		return array(
			'embedded KYC session creation' => array( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session', array( 'card', 'ideal' ) ),
			'test-drive account init'       => array( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/init', array( 'card' ) ),
		);
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() (includes/class-wc-payments-onboarding-service.php:1515-1571) enables
	 * the picked methods, turns WooPay on from the `woopay` pick (:1545-1548, update_is_woopay_enabled() records `woopay_enabled`)
	 * and enables the Apple Pay and Google Pay gateways from the `apple_google` pick (:1553-1562).
	 *
	 * @testdox The NOX route applies the WooPay and Apple Pay / Google Pay picks turned on, like client 11.1.0.
	 * @dataProvider provide_nox_pick_routes_with_platform_call_state
	 *
	 * @param string   $route_suffix             Route below the onboarding step base.
	 * @param string[] $enabled_at_platform_call Enabled payment methods stored when the platform is called.
	 */
	public function test_nox_route_applies_woopay_and_wallet_picks_turned_on( string $route_suffix, array $enabled_at_platform_call ): void {
		$fixture = $this->arrange_native_nox_picks(
			array(
				'card'         => true,
				'ideal'        => true,
				'woopay'       => true,
				'apple_google' => true,
			),
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			'no'
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( $route_suffix ) );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'card', 'ideal' ), $settings['upe_enabled_payment_method_ids'], 'Placeholders are never stored as payment methods.' );
		$this->assertSame( 'yes', $settings['platform_checkout'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertContains( 'wcadmin_woopay_enabled', $fixture['recorder']->events );
		$this->assertSame( $enabled_at_platform_call, $fixture['api_client']->enabled_at_platform_call, 'The picks are stored at the moment the client stores them.' );
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() turns WooPay off when the `woopay` pick is off (:1549-1551; the disable
	 * stamps `platform_checkout_last_disable_date` and records `woopay_disabled`, class-wc-payment-gateway-wcpay.php:3147-3165)
	 * and disables the Apple Pay and Google Pay gateways when `apple_google` is off (:1563-1570).
	 *
	 * @testdox The NOX route applies the WooPay and Apple Pay / Google Pay picks turned off, like client 11.1.0.
	 * @dataProvider provide_nox_pick_routes
	 *
	 * @param string $route_suffix Route below the onboarding step base.
	 */
	public function test_nox_route_applies_woopay_and_wallet_picks_turned_off( string $route_suffix ): void {
		$fixture = $this->arrange_native_nox_picks(
			array(
				'card'         => true,
				'woopay'       => false,
				'apple_google' => false,
			),
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			'yes'
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( $route_suffix ) );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $settings['platform_checkout_last_disable_date'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertContains( 'wcadmin_woopay_disabled', $fixture['recorder']->events );
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() enables the gateway of every method in the merged enabled list
	 * (includes/class-wc-payments-onboarding-service.php:1532-1538). For `card` that gateway is the main WooPayments gateway
	 * (class-wc-payments.php:631), so creating the embedded KYC session turns the card gateway on before any KYC.
	 *
	 * @testdox Creating the embedded KYC session enables the card gateway when card is enabled, like client 11.1.0.
	 */
	public function test_kyc_session_creation_enables_card_gateway_like_client(): void {
		$fixture = $this->arrange_native_nox_picks(
			array(
				'card'  => true,
				'ideal' => true,
			),
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			'no'
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ) );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'yes', $settings['enabled'], 'The card gateway is enabled with the card payment method.' );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'], 'The picked split gateway is enabled.' );
	}

	/**
	 * Enabling the card gateway at session creation must not load the active tier while no account is cached:
	 * the tier only follows the gateway once an account exists.
	 *
	 * @testdox A store that leaves onboarding before an account exists stays in the available tier after the card gateway is enabled.
	 */
	public function test_kyc_session_without_an_account_keeps_the_store_out_of_the_active_tier(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$state = wc_get_container()->get( WooPaymentsSetupTier::class );
		$state->invalidate();
		$this->assertTrue( $state->write_tier( WooPaymentsSetupTier::AVAILABLE ), 'The store starts native-owned without an account.' );
		$fixture = $this->arrange_native_nox_picks(
			array( 'card' => true ),
			array( 'upe_enabled_payment_method_ids' => array( 'card' ) ),
			'no'
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['enabled'], 'Session creation enabled the card gateway.' );
		$this->assertFalse( wc_get_container()->get( WooPaymentsAccountService::class )->has_account(), 'The platform created no account yet.' );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $state->get_effective_tier(), 'Without an account the store stays below the active tier.' );
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() only enables gateways of methods in the merged list (:1532-1538),
	 * so the card gateway stays as it was when card is neither stored nor picked.
	 *
	 * @testdox Creating the embedded KYC session leaves the card gateway alone when card is not enabled, like client 11.1.0.
	 */
	public function test_kyc_session_creation_leaves_card_gateway_without_card(): void {
		$fixture = $this->arrange_native_nox_picks(
			array(
				'ideal' => true,
			),
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array( 'ideal' ),
			),
			'no'
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ) );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ideal' ), $settings['upe_enabled_payment_method_ids'], 'Card stays out of the enabled methods.' );
		$this->assertSame( 'no', $settings['enabled'], 'The card gateway is not enabled without the card payment method.' );
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() keeps WooPay off when Link is enabled, even when WooPay is picked (:1540-1548).
	 *
	 * @testdox A NOX WooPay pick leaves WooPay off when Link is picked too, like client 11.1.0.
	 */
	public function test_nox_woopay_pick_leaves_woopay_off_when_link_is_picked(): void {
		$fixture = $this->arrange_native_nox_picks(
			array(
				'card'   => true,
				'link'   => true,
				'woopay' => true,
			),
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			'no'
		);

		$fixture['server']->dispatch( $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ) );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertNotContains( 'wcadmin_woopay_enabled', $fixture['recorder']->events );
	}

	/**
	 * Platform `woopay_enabled_by_default` answers and the WooPay state the merchant lands on after KYC.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function provide_platform_woopay_defaults(): array {
		return array(
			'platform asks for WooPay'         => array( array( 'woopay_enabled_by_default' => true ), 'yes' ),
			'platform does not ask for WooPay' => array( array( 'woopay_enabled_by_default' => false ), 'no' ),
			'platform sends no flag'           => array( array(), 'no' ),
		);
	}

	/**
	 * Client 11.1.0 create_embedded_kyc_session() stores the platform's `woopay_enabled_by_default` answer for a day
	 * (includes/class-wc-payments-onboarding-service.php:396-401). finalize_embedded_connection() leaves WooPay alone
	 * (includes/class-wc-payments-account.php:2296-2345). maybe_activate_woopay() runs on admin_init (:121) and, on the
	 * page that carries `wcpay-connection-success`, turns WooPay on through update_is_woopay_enabled() and forgets the
	 * answer (:2231-2247).
	 *
	 * @testdox The platform's WooPay default turns WooPay on when the merchant lands after KYC, like client 11.1.0.
	 * @dataProvider provide_platform_woopay_defaults
	 *
	 * @param array<string,mixed> $session_extras Platform session fields.
	 * @param string              $landed_woopay  Stored WooPay setting after the landing page.
	 */
	public function test_platform_woopay_default_turns_woopay_on_after_kyc_like_client( array $session_extras, string $landed_woopay ): void {
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account() ),
			array( 'card' => true ),
			null,
			$session_extras
		);
		$recorder   = $this->record_wcpay_tracks_event_names();
		$controller = $this->create_trunk_controller();

		$this->sut->get_onboarding_kyc_session( 'US' );
		$this->assertSame( 'yes' === $landed_woopay, (bool) get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ), 'Session creation stores the platform answer.' );

		$this->sut->finish_onboarding_kyc_session( 'US' );
		$this->run_woopayments_controller_admin_init( $controller );
		$this->assertSame( 'no', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['platform_checkout'] ?? 'no', 'Neither finalization nor a page without the success flag turns WooPay on.' );

		$_GET['wcpay-connection-success'] = '1';
		$this->run_woopayments_controller_admin_init( $controller );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( $landed_woopay, $settings['platform_checkout'] ?? 'no' );
		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertFalse( (bool) get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ), 'A used answer is forgotten; a negative one stays falsy, as in the client.' );
		$this->assertSame( 'yes' === $landed_woopay, in_array( 'wcadmin_woopay_enabled', $recorder->events, true ) );
	}

	/**
	 * Client 11.1.0 maybe_activate_woopay() keeps WooPay off when Link is enabled and still forgets the answer
	 * (includes/class-wc-payments-account.php:2238-2245; client test test_maybe_activate_woopay_does_not_enable_when_link_is_enabled).
	 *
	 * @testdox The platform's WooPay default leaves WooPay off when Link is enabled, like client 11.1.0.
	 */
	public function test_platform_woopay_default_leaves_woopay_off_when_link_is_enabled(): void {
		$this->arrange_native_finalize_projection( array( $this->get_native_finalize_projection_account() ), array( 'card' => true ) );
		$recorder   = $this->record_wcpay_tracks_event_names();
		$controller = $this->create_trunk_controller();
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);
		set_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		$_GET['wcpay-connection-success'] = '1';

		$this->run_woopayments_controller_admin_init( $controller );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ) );
		$this->assertNotContains( 'wcadmin_woopay_enabled', $recorder->events );
	}

	/**
	 * NOX picks at test-drive init and whether the platform's WooPay default is stored.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provide_test_drive_picks_for_woopay_default(): array {
		return array(
			'no picks'  => array( array(), true ),
			'NOX picks' => array(
				array(
					'card'   => true,
					'woopay' => true,
				),
				false,
			),
		);
	}

	/**
	 * Client 11.1.0 init_test_drive_account() stores the platform's WooPay default only when should_enable_woopay() agrees
	 * (includes/class-wc-payments-onboarding-service.php:763-772). That helper returns the platform answer when no picks
	 * were sent, and otherwise reads only a `woopay_payments` pick (:291-302), which NOX picks never carry.
	 *
	 * @testdox Test-drive init stores the platform's WooPay default only when no picks were sent, like client 11.1.0.
	 * @dataProvider provide_test_drive_picks_for_woopay_default
	 *
	 * @param array<string,mixed> $picks         Persisted NOX payment-method picks.
	 * @param bool                $default_saved Whether the platform answer is stored.
	 */
	public function test_test_drive_init_stores_platform_woopay_default_like_client( array $picks, bool $default_saved ): void {
		$fixture = $this->arrange_native_nox_picks(
			$picks,
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			'no',
			array( 'woopay_enabled_by_default' => true )
		);

		$response = $fixture['server']->dispatch( $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/init' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $default_saved, (bool) get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ) );
	}

	/**
	 * Client 11.1.0 WC_Payments_Onboarding_Service::get_actioned_notes() (includes/class-wc-payments-onboarding-service.php:1019-1060)
	 * feeds test-drive init (:760), the embedded session (:356) and finalize (includes/admin/class-wc-rest-payments-onboarding-controller.php:278).
	 * The platform picks the accepted promotion only from these note names.
	 *
	 * @testdox The $call onboarding request sends only actioned, not-deleted wcpay-promo note names, like client 11.1.0.
	 * @dataProvider provide_actioned_notes_onboarding_calls
	 *
	 * @param string $call API client method the route calls.
	 */
	public function test_onboarding_request_sends_actioned_promo_notes_like_client( string $call ): void {
		$this->create_admin_note( 'wcpay-promo-accepted', Note::E_WC_ADMIN_NOTE_ACTIONED );
		$this->create_admin_note( 'wcpay-promo-not-accepted', Note::E_WC_ADMIN_NOTE_UNACTIONED );
		$this->create_admin_note( 'wcpay-promo-deleted', Note::E_WC_ADMIN_NOTE_ACTIONED, 0, true );
		$this->create_admin_note( 'wcpay-other-actioned', Note::E_WC_ADMIN_NOTE_ACTIONED );
		$this->create_admin_note( 'woocommerce-wcpay-promo-actioned', Note::E_WC_ADMIN_NOTE_ACTIONED );

		$this->assertSame( array( 'wcpay-promo-accepted' ), $this->dispatch_onboarding_route_for_actioned_notes( $call ) );
		$this->assertFalse( has_filter( 'woocommerce_note_where_clauses' ), 'The promo name clause does not leak into later note queries.' );
	}

	/**
	 * Client 11.1.0 get_actioned_notes() asks the note store for 10 notes in its default order, newest `date_created` first.
	 *
	 * @testdox The onboarding request sends the 10 newest actioned wcpay-promo notes, newest first, like client 11.1.0.
	 */
	public function test_onboarding_request_sends_ten_newest_actioned_promo_notes_like_client(): void {
		// Created out of date order, so the note ID order differs from the date order.
		foreach ( array( 7, 2, 11, 0, 5, 9, 1, 10, 4, 8, 3, 6 ) as $hours_after_start ) {
			$this->create_admin_note( sprintf( 'wcpay-promo-%02d', $hours_after_start ), Note::E_WC_ADMIN_NOTE_ACTIONED, $hours_after_start );
		}

		$this->assertSame(
			array( 'wcpay-promo-11', 'wcpay-promo-10', 'wcpay-promo-09', 'wcpay-promo-08', 'wcpay-promo-07', 'wcpay-promo-06', 'wcpay-promo-05', 'wcpay-promo-04', 'wcpay-promo-03', 'wcpay-promo-02' ),
			$this->dispatch_onboarding_route_for_actioned_notes( 'initialize_onboarding_embedded_kyc' )
		);
	}

	/**
	 * Client 11.1.0 get_actioned_notes() logs a note store failure and returns no notes, so onboarding goes on (:1027-1034).
	 *
	 * @testdox The $call onboarding request goes on with no notes and logs an error when the note store fails, like client 11.1.0.
	 * @dataProvider provide_actioned_notes_onboarding_calls
	 *
	 * @param string $call API client method the route calls.
	 */
	public function test_onboarding_request_goes_on_without_notes_when_note_store_fails( string $call ): void {
		$this->create_admin_note( 'wcpay-promo-accepted', Note::E_WC_ADMIN_NOTE_ACTIONED );
		add_filter(
			'woocommerce_data_stores',
			static function ( $stores ) {
				$stores['admin-note'] = 'WC_Missing_Admin_Note_Data_Store';
				return $stores;
			}
		);
		// The client logs this through its Logger, written only with debug logging on.
		add_filter(
			'option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			static fn( $settings ) => array_merge( (array) $settings, array( 'enable_logging' => 'yes' ) )
		);
		$logger = RecordingWcLogger::install();

		$this->assertSame( array(), $this->dispatch_onboarding_route_for_actioned_notes( $call ) );
		$note_store_errors = array_filter(
			$logger->get_errors(),
			static fn( array $line ): bool => 'Native WooPayments could not read the actioned promotion notes for onboarding.' === $line[1]
		);
		$this->assertCount( 1, $note_store_errors, 'The note store failure is logged once.' );

		// With debug logging off, the same exception is not logged, as the client's Logger::error() is gated.
		remove_all_filters( 'option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		$logger = RecordingWcLogger::install();
		$this->assertSame( array(), $this->dispatch_onboarding_route_for_actioned_notes( $call ) );
		$this->assertSame( array(), $logger->get_errors(), 'An exception reading the notes follows the logging setting.' );
	}

	/**
	 * The onboarding routes that send actioned notes to the platform.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_actioned_notes_onboarding_calls(): array {
		return array(
			'test-drive init'       => array( 'initialize_onboarding' ),
			'embedded KYC session'  => array( 'initialize_onboarding_embedded_kyc' ),
			'embedded KYC finalize' => array( 'finalize_onboarding_embedded_kyc' ),
		);
	}

	/**
	 * Client 11.1.0 finalize_embedded_connection() (includes/class-wc-payments-account.php:2296-2345) applies no NOX picks:
	 * create_embedded_kyc_session() already applied them (includes/class-wc-payments-onboarding-service.php:357-371).
	 *
	 * @testdox Native KYC finalization applies no NOX picks and returns the same response each time, like client 11.1.0.
	 */
	public function test_finish_native_onboarding_kyc_session_applies_no_picks_like_client(): void {
		$fresh_account  = $this->get_native_finalize_projection_account();
		$fixture        = $this->arrange_native_finalize_projection(
			array( $fresh_account, $fresh_account ),
			array(
				'card'         => true,
				'ideal'        => true,
				'woopay'       => true,
				'apple_google' => true,
			)
		);
		$first_response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$first_settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		$this->sut->finish_onboarding_kyc_session( 'US' );
		$second_settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame(
			array(
				'success'           => true,
				'details_submitted' => true,
				'account_id'        => 'acct_finalized_native',
				'mode'              => 'live',
				'params'            => array(
					'promo'                    => '',
					'from'                     => WooPaymentsService::FROM_NOX_IN_CONTEXT,
					'source'                   => WooPaymentsService::SESSION_ENTRY_DEFAULT,
					'wcpay-connection-success' => '1',
				),
			),
			$first_response
		);
		$this->assertSame( 2, $fixture['api_client']->account_requests );
		$this->assertSame( array( 'card' ), $first_settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $first_settings['platform_checkout'] ?? 'no' );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_ideal_settings', false ) );
		$this->assertSame( $first_settings, $second_settings );
	}

	/**
	 * Finalize and later account refresh outcomes.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>|\Throwable>}>
	 */
	public function provide_finalize_refresh_outcomes(): array {
		$account = $this->get_native_finalize_projection_account();

		return array(
			'finalize refresh succeeds' => array( array( $account, $account ) ),
			'finalize refresh fails'    => array( array( new \RuntimeException( 'Temporary account refresh failure.' ), $account ) ),
		);
	}

	/**
	 * Client 11.1.0 applies the NOX picks when the embedded session is created (includes/class-wc-payments-onboarding-service.php:357-371)
	 * and finalize_embedded_connection() applies none (includes/class-wc-payments-account.php:2296-2345), so a picked method the
	 * merchant turns off in between stays off.
	 *
	 * @testdox A picked method the merchant turns off after session creation stays off through finalization and a later account refresh, like client 11.1.0.
	 * @dataProvider provide_finalize_refresh_outcomes
	 *
	 * @param array<int,array<string,mixed>|\Throwable> $account_responses Account refresh results in request order.
	 */
	public function test_merchant_change_after_session_creation_survives_finalize_and_refresh( array $account_responses ): void {
		$fixture = $this->arrange_native_finalize_projection(
			$account_responses,
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		$this->sut->get_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		$this->assertSame( array( 'card', 'ideal' ), $settings['upe_enabled_payment_method_ids'], 'Session creation applies the picks.' );

		// The merchant turns iDEAL off; a settings save ends in this canonical write.
		$settings['upe_enabled_payment_method_ids'] = array( 'card' );
		$this->assertTrue( wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class )->persist( $settings )['persisted'] );

		$this->assertTrue( $this->sut->finish_onboarding_kyc_session( 'US' )['success'] );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'], 'Finalization keeps the change.' );

		$fixture['account_service']->refresh_account_data();

		$this->assertSame( array( 'card' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'], 'A later account refresh keeps the change.' );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
	}

	/**
	 * Client 11.1.0 finalize_embedded_connection() restores the test-drive methods and runs no settings save that could drop them.
	 *
	 * @testdox Native KYC finalization keeps a restored test-drive method without a fee entry next to the picks applied at session creation.
	 */
	public function test_finish_native_onboarding_kyc_session_keeps_restored_test_drive_method_without_fee_entry(): void {
		$fresh_account = array(
			'account_id'        => 'acct_finalized_native',
			'is_live'           => true,
			'payments_enabled'  => true,
			'details_submitted' => true,
			'capabilities'      => array(
				'card_payments'  => 'active',
				'ideal_payments' => 'active',
				'link_payments'  => 'pending',
			),
			'fees'              => array(
				'card'  => array(),
				'ideal' => array(),
			),
		);
		$this->arrange_native_finalize_projection(
			array( $fresh_account ),
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array( 'link_payments' => array( 'requested' => 'true' ) ),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			HOUR_IN_SECONDS
		);
		$this->sut->get_onboarding_kyc_session( 'US' );

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'card', 'ideal', 'link' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
	}

	/**
	 * @testdox Native KYC finalization flags a new Stripe connection for the wcpay_stripe_connected Tracks event, like client 11.1.0 finalize_embedded_connection().
	 */
	public function test_finish_native_onboarding_kyc_session_flags_new_stripe_connection_for_tracks(): void {
		update_option( '_wcpay_onboarding_stripe_connected', array( 'is_existing_stripe_account' => true ), false );
		$fresh_account = array(
			'account_id'        => 'acct_finalized_native',
			'is_live'           => true,
			'payments_enabled'  => true,
			'details_submitted' => true,
			'capabilities'      => array( 'card_payments' => 'active' ),
			'fees'              => array( 'card' => array() ),
		);
		$this->arrange_native_finalize_projection( array( $fresh_account ), array( 'card' => true ) );

		$this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertSame( array( 'is_existing_stripe_account' => false ), get_option( '_wcpay_onboarding_stripe_connected' ) );
		$this->assertOptionNotAutoloaded( '_wcpay_onboarding_stripe_connected' );
	}

	/**
	 * @testdox Native embedded KYC finalization drops the cached recommended payment methods, like client 11.1.0 cleanup_on_account_onboarded().
	 */
	public function test_finish_native_onboarding_kyc_session_clears_recommended_payment_methods_cache(): void {
		$this->seed_recommended_payment_methods_cache( array( 'payment_methods' => array( array( 'id' => 'card' ) ) ) );
		update_option( 'wcpay_onboarding_fields_data', array( 'data' => array( '__locale' => 'en_US' ) ) );
		$fresh_account = array(
			'account_id'        => 'acct_finalized_native',
			'is_live'           => true,
			'payments_enabled'  => true,
			'details_submitted' => true,
			'capabilities'      => array( 'card_payments' => 'active' ),
			'fees'              => array( 'card' => array() ),
		);
		$this->arrange_native_finalize_projection( array( $fresh_account ), array( 'card' => true ) );

		$this->sut->finish_onboarding_kyc_session( 'US' );

		// Client 11.1.0 class-wc-payments-onboarding-service.php:980-985, called from finalize_embedded_connection() (class-wc-payments-account.php:2336).
		$this->assertFalse( get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY ) );
		$this->assertFalse( get_option( 'wcpay_onboarding_fields_data' ) );
	}

	/**
	 * @testdox A completed native hosted KYC return drops the cached recommended payment methods, like client 11.1.0 finalize_connection().
	 */
	public function test_finalize_native_hosted_kyc_connection_clears_recommended_payment_methods_cache(): void {
		$this->seed_recommended_payment_methods_cache( array( 'payment_methods' => array( array( 'id' => 'card' ) ) ) );

		$this->onboarding_service->finish_hosted_kyc_connection( false, true );

		// Client 11.1.0 class-wc-payments-account.php:2428 runs cleanup_on_account_onboarded() on a completed hosted return.
		$this->assertFalse( get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY ) );
	}

	/**
	 * Provider finalize responses for both embedded KYC modes.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:string}>
	 */
	public function provide_native_finalize_modes(): array {
		return array(
			// Native's live finalize fixture, the default of arrange_native_finalize_projection().
			'live mode' => array(
				array(
					'success'           => true,
					'details_submitted' => true,
					'account_id'        => 'acct_finalized_native',
					'mode'              => 'live',
				),
				true,
				'',
			),
			// Client 11.1.0 test_finalize_embedded_kyc() fixture (tests/unit/test-class-wc-payments-onboarding-service.php:236-242).
			'test mode' => array(
				array(
					'success'           => true,
					'account_id'        => 'acc_id',
					'details_submitted' => true,
					'mode'              => 'test',
					'promotion_id'      => 'promotion_id',
				),
				false,
				'promotion_id',
			),
		);
	}

	/**
	 * Client 11.1.0 finalize_embedded_connection() (includes/class-wc-payments-account.php:2296-2345) enables the
	 * gateway, sets its mode, stamps a live KYC submission once, and returns the connection-success redirect params.
	 *
	 * @testdox Native KYC finalization through the REST route enables the gateway in the finalized mode and activates the native tier, like client 11.1.0.
	 * @dataProvider provide_native_finalize_modes
	 *
	 * @param array<string,mixed> $finalize_response Provider finalize response.
	 * @param bool                $is_live           Whether the finalized account is live.
	 * @param string              $promo             Expected promo redirect param.
	 */
	public function test_native_kyc_finalize_route_enables_gateway_like_client( array $finalize_response, bool $is_live, string $promo ): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$state = wc_get_container()->get( WooPaymentsSetupTier::class );
		$state->invalidate();
		$this->assertTrue( $state->write_tier( WooPaymentsSetupTier::CONNECTED ), 'The store starts native-owned and connected.' );
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account( array( 'is_live' => $is_live ) ) ),
			array( 'card' => true ),
			$finalize_response
		);
		$controller = new WooPaymentsRestController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );
		$server  = $this->create_rest_server_with_routes(
			array(
				static function () use ( $controller ) {
					$controller->register_routes( true );
				},
			),
			true
		);
		$request = new WP_REST_Request( 'POST', '/wc-admin/settings/payments/woopayments/onboarding/step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session/finish' );
		$request->set_param( 'location', 'US' );
		$request->set_param( 'source', WooPaymentsService::SESSION_ENTRY_LYS );

		$response = $server->dispatch( $request );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'yes', $settings['enabled'] );
		$this->assertSame( $is_live ? 'no' : 'yes', $settings['test_mode'] );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $state->get_effective_tier() );
		$this->assertSame(
			array(
				'promo'                    => $promo,
				'from'                     => WooPaymentsService::FROM_NOX_IN_CONTEXT,
				'source'                   => WooPaymentsService::SESSION_ENTRY_LYS,
				'wcpay-connection-success' => '1',
			),
			$response->get_data()['params']
		);
		$this->assertSame( $is_live ? self::TEST_EPOCH : false, get_option( 'wcpay_kyc_submitted_date', false ) );
		if ( $is_live ) {
			$this->assertOptionNotAutoloaded( 'wcpay_kyc_submitted_date' );
		}
	}

	/**
	 * @testdox Native live KYC finalization never overwrites an existing KYC submission date, like client 11.1.0.
	 */
	public function test_native_kyc_finalize_keeps_existing_kyc_submitted_date(): void {
		update_option( 'wcpay_kyc_submitted_date', self::TEST_EPOCH - DAY_IN_SECONDS, false );
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account() ),
			array( 'card' => true )
		);

		$this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertSame( self::TEST_EPOCH - DAY_IN_SECONDS, (int) get_option( 'wcpay_kyc_submitted_date' ) );
	}

	/**
	 * Client 11.1.0 ignores both writes (class-wc-payments-account.php:2272, :2288, :2306-2307); native logs them, and keeps
	 * the Test Drive selection when restoring it did not persist.
	 *
	 * @testdox Native KYC finalization logs a gateway enable or Test Drive restore that did not persist and still answers success.
	 * @testWith ["gateway enable"]
	 *           ["test drive restore"]
	 *
	 * @param string $refused_write Settings write the store refuses.
	 */
	public function test_native_kyc_finalize_logs_settings_writes_that_did_not_persist( string $refused_write ): void {
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'enabled' => 'no' ) );
		$this->arrange_native_finalize_projection( array( $this->get_native_finalize_projection_account() ), array( 'card' => true ) );
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array( 'link_payments' => array( 'requested' => 'true' ) ),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			HOUR_IN_SECONDS
		);
		if ( 'gateway enable' === $refused_write ) {
			add_filter(
				'pre_update_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
				static fn( $value, $old_value ) => is_array( $value ) && 'yes' === ( $value['enabled'] ?? 'no' ) ? $old_value : $value,
				10,
				2
			);
		} else {
			$this->refuse_native_settings_writes_enabling( 'link' );
		}
		$logger = RecordingWcLogger::install();

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertTrue( $response['success'], 'The platform finalization cannot be replayed, so the answer stays a success.' );
		$messages = array_column( $logger->get_errors(), 1 );
		if ( 'gateway enable' === $refused_write ) {
			$this->assertContains( 'Native WooPayments could not enable the gateway after KYC finalization.', $messages );
			$this->assertStringContainsString( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, (string) wp_json_encode( $logger->contexts ) );
		} else {
			$this->assertSame( array( 'Native WooPayments could not restore the Test Drive payment methods after KYC finalization.' ), $messages );
			$this->assertNotFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ), 'The Test Drive selection is not deleted with the failed write.' );
		}
	}

	/**
	 * @testdox Native KYC finalization restores test-drive payment methods and keeps Link mutually exclusive with WooPay.
	 */
	public function test_finish_native_onboarding_kyc_session_restores_test_drive_payment_methods(): void {
		$fresh_account = $this->get_native_finalize_projection_account(
			array(
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array(
					'card' => array(),
					'link' => array(),
				),
			)
		);
		$this->arrange_native_finalize_projection( array( $fresh_account ), array( 'card' => true ) );
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array(
					'card_payments' => array( 'requested' => 'true' ),
					'link_payments' => array( 'requested' => 'true' ),
				),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			HOUR_IN_SECONDS
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
	}

	/**
	 * Client 11.1.0 restore_test_drive_enabled_payment_methods() (includes/class-wc-payments-account.php:2258-2289) merges
	 * the saved test-drive methods back without checking the new live account's capabilities.
	 *
	 * @testdox Native KYC finalization restores test-drive payment methods whose live capabilities are still pending, like client 11.1.0.
	 */
	public function test_finish_native_onboarding_kyc_session_restores_test_drive_payment_methods_with_pending_capabilities(): void {
		$fresh_account = $this->get_native_finalize_projection_account(
			array(
				'capabilities' => array(
					'card_payments'  => 'active',
					'ideal_payments' => 'pending',
					'link_payments'  => 'pending',
				),
				'fees'         => array(
					'card'  => array(),
					'ideal' => array(),
					'link'  => array(),
				),
			)
		);
		$this->arrange_native_finalize_projection( array( $fresh_account ), array( 'card' => true ) );
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array(
					'card_payments'  => array( 'requested' => 'true' ),
					'ideal_payments' => array( 'requested' => 'true' ),
					'link_payments'  => array( 'requested' => 'true' ),
				),
				'enabled_payment_methods' => array( 'card', 'ideal', 'link' ),
			),
			HOUR_IN_SECONDS
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'card', 'ideal', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertSame( array( 'card', 'ideal', 'link' ), get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
	}

	/**
	 * @testdox Native KYC finalization leaves WooPay enabled when no restored method conflicts with it.
	 */
	public function test_finish_native_onboarding_kyc_session_preserves_woopay_without_link(): void {
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account() ),
			array( 'card' => true )
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['platform_checkout'] );
	}

	/**
	 * @testdox Native KYC finalization ignores and consumes malformed test-drive payment-method state.
	 */
	public function test_finish_native_onboarding_kyc_session_ignores_malformed_test_drive_payment_methods(): void {
		$this->arrange_native_finalize_projection(
			array(
				$this->get_native_finalize_projection_account(
					array(
						'capabilities' => array( 'link_payments' => 'active' ),
						'fees'         => array( 'link' => array() ),
					)
				),
			),
			array( 'card' => true )
		);
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'enabled_payment_methods' => array( '', array( 'link' ), 'not-a-payment-method', 'l!i@n#k' ),
			),
			HOUR_IN_SECONDS
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', $settings['platform_checkout'] );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
	}

	/**
	 * Client 11.1.0 finalize_embedded_connection() restores the test-drive methods before, and independently of, any account refresh.
	 *
	 * @testdox Native KYC finalization restores test-drive methods even when the account refresh fails.
	 */
	public function test_finish_native_onboarding_kyc_session_restores_test_drive_payment_methods_despite_refresh_failure(): void {
		$fresh_account = $this->get_native_finalize_projection_account(
			array(
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array(
					'card' => array(),
					'link' => array(),
				),
			)
		);
		$fixture       = $this->arrange_native_finalize_projection(
			array(
				new \RuntimeException( 'Temporary account refresh failure.' ),
				$fresh_account,
			),
			array( 'card' => true )
		);
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array( 'link_payments' => array( 'requested' => 'true' ) ),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			HOUR_IN_SECONDS
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );

		$fixture['account_service']->refresh_account_data();

		$this->assertSame( array( 'card', 'link' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
	}

	/**
	 * Client 11.1.0 applies the NOX picks once, when the embedded session is created
	 * (includes/class-wc-payments-onboarding-service.php:357-371), and neither finalization nor an account refresh applies them again.
	 *
	 * @testdox A session-time pick write that did not persist is logged once and not applied again at finalization or at the next account refresh.
	 * @dataProvider provide_finalize_refresh_outcomes
	 *
	 * @param array<int,array<string,mixed>|\Throwable> $account_responses Account refresh results in request order.
	 */
	public function test_refused_session_pick_write_is_logged_once_and_not_applied_again( array $account_responses ): void {
		$logger   = RecordingWcLogger::install();
		$fixture  = $this->arrange_native_finalize_projection(
			$account_responses,
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		$attempts = $this->refuse_native_settings_writes_enabling( 'ideal', 1 );

		$session = $this->sut->get_onboarding_kyc_session( 'US' );
		$this->sut->finish_onboarding_kyc_session( 'US' );
		$fixture['account_service']->refresh_account_data();

		$this->assertSame( 'accs_secret_finalized_native', $session['clientSecret'], 'Session creation does not surface the refused write.' );
		$this->assertSame( 1, $attempts->count, 'The picks are written once.' );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_ideal_settings', false ) );
		$this->assertCount( 1, $logger->get_errors() );
		$this->assertStringContainsString( 'could not save the payment methods picked in onboarding', $logger->get_errors()[0][1] );
		$this->assertSame( 'woopayments', $logger->get_errors()[0][2] );
	}

	/**
	 * @testdox Native KYC finalization ignores malformed NOX selections without updating settings.
	 */
	public function test_finish_native_onboarding_kyc_session_ignores_malformed_payment_method_selections(): void {
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account() ),
			array(
				'ideal'      => array( 'malformed' ),
				'bancontact' => (object) array( 'malformed' => true ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_ideal_settings', false ) );
	}

	/**
	 * Test live native KYC switches the gateway out of test mode when replacing a test-drive account.
	 *
	 * @return void
	 */
	public function test_live_native_kyc_switches_gateway_out_of_test_mode_after_test_drive(): void {
		$location      = 'US';
		$captured_call = array();
		$api_client    = new class( $captured_call ) extends WooPaymentsApiClient {
			/**
			 * Captured KYC session request payload.
			 *
			 * @var array
			 */
			private array $captured_call;

			/**
			 * Constructor.
			 *
			 * @param array $captured_call Captured KYC session request payload.
			 */
			public function __construct( array &$captured_call ) {
				$this->captured_call = &$captured_call;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Capture an embedded KYC request and return a ready live account session.
			 *
			 * @param bool        $live_account   Whether the session is for a live account.
			 * @param array       $site_data      Site data.
			 * @param array       $user_data      User data.
			 * @param array       $account_data   Account data.
			 * @param array       $actioned_notes Actioned notes.
			 * @param string|null $referral_code  Referral code.
			 * @return array
			 */
			public function initialize_onboarding_embedded_kyc( bool $live_account, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), ?string $referral_code = null ): array {
				$this->captured_call = array(
					'live_account'   => $live_account,
					'site_data'      => $site_data,
					'user_data'      => $user_data,
					'account_data'   => $account_data,
					'actioned_notes' => $actioned_notes,
					'referral_code'  => $referral_code,
				);

				return array(
					'clientSecret'     => 'accs_secret_live',
					'expiresAt'        => 1234567999,
					'accountId'        => 'acct_live_native',
					'isLive'           => true,
					'accountCreated'   => true,
					'publishableKey'   => 'pk_live_native',
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				);
			}
		};
		$adapter       = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
					'get_onboarding_kyc_fallback_url',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( false );
		$adapter->method( 'has_valid_account' )->willReturn( false );
		$adapter->method( 'has_working_account' )->willReturn( false );
		$adapter->method( 'has_test_account' )->willReturn( false );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );
		$adapter->method( 'get_onboarding_kyc_fallback_url' )->willReturn( 'https://example.com/native-kyc' );
		$this->mock_provider->method( 'is_in_dev_mode' )->willReturn( false );
		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array(
				'onboarding' => array(
					'US' => array(
						'steps' => array(
							WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
								'data' => array(
									'payment_methods' => array(
										'card' => true,
									),
								),
							),
						),
					),
				),
			)
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return false;
				},
			)
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.' );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// The account read needs the same connection: without one it returns no account, like the client.
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'           => 'acct_test_drive',
					'test_publishable_key' => 'pk_test_stale',
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'is_test_drive'        => true,
					'is_live'              => false,
				),
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => array( 'link_payments' => array( 'requested' => 'true' ) ),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			HOUR_IN_SECONDS
		);

		$result          = $this->sut->get_onboarding_kyc_session(
			$location,
			array(
				'business_type' => 'individual',
				'country'       => 'US',
			)
		);
		$settings        = get_option( 'woocommerce_woocommerce_payments_settings' );
		$account_service = $this->create_native_account_service();

		$this->assertSame( 'accs_secret_live', $result['clientSecret'] );
		$this->assertTrue( $captured_call['live_account'] );
		$this->assertSame( array( 'requested' => 'true' ), $captured_call['account_data']['capabilities']['link_payments'] );
		$this->assertNotFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
		$this->assertIsArray( $settings );
		$this->assertSame( 'no', $settings['test_mode'] );
		$this->assertSame( 'pk_live_native', $account_service->get_publishable_key() );
		$this->assertTrue( $account_service->can_process_payments() );
	}

	/**
	 * Test native test-drive initialization writes a usable account cache from the platform response.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_uses_native_api_and_writes_usable_account_cache(): void {
		$captured_call = array();
		$api_client    = new class( $captured_call ) extends WooPaymentsApiClient {
			/**
			 * Captured onboarding init payload.
			 *
			 * @var array
			 */
			private array $captured_call;

			/**
			 * Constructor.
			 *
			 * @param array $captured_call Captured onboarding init payload.
			 */
			public function __construct( array &$captured_call ) {
				$this->captured_call = &$captured_call;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Capture a native onboarding init request and return a completed test-drive account.
			 *
			 * @param bool        $live_account   Whether the account is live.
			 * @param string      $return_url     Return URL for the onboarding flow.
			 * @param array       $site_data      Site data.
			 * @param array       $user_data      User data.
			 * @param array       $account_data   Account data.
			 * @param array       $actioned_notes Actioned notes.
			 * @param bool        $collect_payout_requirements Whether to collect payout requirements.
			 * @param string|null $referral_code  Referral code.
			 * @return array
			 */
			public function initialize_onboarding( bool $live_account, string $return_url, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), bool $collect_payout_requirements = false, ?string $referral_code = null ): array {
				$this->captured_call = array(
					'live_account'                => $live_account,
					'return_url'                  => $return_url,
					'site_data'                   => $site_data,
					'user_data'                   => $user_data,
					'account_data'                => $account_data,
					'actioned_notes'              => $actioned_notes,
					'collect_payout_requirements' => $collect_payout_requirements,
					'referral_code'               => $referral_code,
				);

				return array(
					'url'               => false,
					'account_id'        => 'acct_native_test',
					'is_live'           => false,
					'publishable_key'   => 'pk_test_native',
					'payments_enabled'  => true,
					'details_submitted' => true,
				);
			}
		};
		$adapter       = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( false );
		$adapter->method( 'has_valid_account' )->willReturn( false );
		$adapter->method( 'has_working_account' )->willReturn( false );
		$adapter->method( 'has_test_account' )->willReturn( false );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array(
				'onboarding' => array(
					'US' => array(
						'steps' => array(
							WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
								'data' => array(
									'payment_methods' => array(
										'card' => true,
									),
								),
							),
						),
					),
				),
			)
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return false;
				},
			)
		);

		// The account read needs the same connection: without one it returns no account, like the client.
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);

		$result          = $this->sut->onboarding_test_account_init( 'US' );
		$cached          = get_option( 'wcpay_account_data' );
		$account_service = $this->create_native_account_service();

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $captured_call['live_account'] );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $captured_call['return_url'] );
		$this->assertStringNotContainsString( 'wcpay-connection-success', $captured_call['return_url'] );
		$this->assertStringNotContainsString( 'wcpay-connection-error', $captured_call['return_url'] );
		$this->assertSame( 'card_payments', array_key_first( $captured_call['account_data']['capabilities'] ) );
		$this->assertIsArray( $cached );
		$this->assertSame( 'acct_native_test', $cached['data']['account_id'] );
		$this->assertSame( 'pk_test_native', $cached['data']['test_publishable_key'] );
		$this->assertFalse( $cached['data']['is_live'] );
		$this->assertTrue( $cached['data']['is_test_drive'] );
		$this->assertTrue( $cached['data']['payments_enabled'] );
		$this->assertTrue( $cached['data']['details_submitted'] );
		$this->assertSame( 0, $cached['fetched'], 'The client clears its account cache after test-drive init (class-wc-payments-onboarding-service.php:796), so the next read fetches the full account; the partial record must not pass as fresh.' );
		$this->assertTrue( $account_service->can_process_payments() );
	}

	/**
	 * @testdox Native test-drive disable captures enabled payment methods before account deletion.
	 */
	public function test_disable_test_account_captures_native_test_drive_payment_methods_before_delete(): void {
		$snapshot_at_delete = false;
		$api_client         = new class( $snapshot_at_delete ) extends WooPaymentsApiClient {
			/**
			 * Snapshot observed when account deletion begins.
			 *
			 * @var mixed
			 */
			private $snapshot_at_delete;

			/**
			 * Constructor.
			 *
			 * @param mixed $snapshot_at_delete Snapshot observed when account deletion begins.
			 */
			public function __construct( &$snapshot_at_delete ) {
				$this->snapshot_at_delete = &$snapshot_at_delete;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Observe the transition snapshot before returning a successful deletion.
			 *
			 * @param bool $test_mode Whether to delete a test-mode account.
			 * @return array<string,string>
			 */
			public function delete_account( bool $test_mode = false ): array {
				if ( ! $test_mode ) {
					throw new \LogicException( 'Expected a test-mode account deletion.' );
				}

				$this->snapshot_at_delete = get_transient( 'test_drive_account_settings_for_live_account' );

				return array( 'result' => 'success' );
			}
		};

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					return ! $this->is_woopayments_class( $class_to_check );
				},
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_test_drive',
					'payments_enabled'  => true,
					'details_submitted' => true,
					'is_test_drive'     => true,
					'is_live'           => false,
				),
			)
		);
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link', 'link', '', array( 'invalid' ) ),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$this->create_native_finalize_adapter(),
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);

		$response = $this->sut->disable_test_account( 'US' );

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array(
				'capabilities'            => array(
					'card_payments' => array( 'requested' => 'true' ),
					'link_payments' => array( 'requested' => 'true' ),
				),
				'enabled_payment_methods' => array( 'card', 'link' ),
			),
			$snapshot_at_delete
		);
	}

	/**
	 * Test native onboarding reset immediately clears stale account readiness.
	 *
	 * @return void
	 */
	public function test_reset_onboarding_uses_native_api_and_overwrites_stale_account_cache(): void {
		$deleted_test_mode = null;
		$api_client        = new class( $deleted_test_mode ) extends WooPaymentsApiClient {
			/**
			 * Captured deleted account mode.
			 *
			 * @var bool|null
			 */
			private ?bool $deleted_test_mode;

			/**
			 * Constructor.
			 *
			 * @param bool|null $deleted_test_mode Captured deleted account mode.
			 */
			public function __construct( ?bool &$deleted_test_mode ) {
				$this->deleted_test_mode = &$deleted_test_mode;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Capture account delete calls.
			 *
			 * @param bool $test_mode Whether to delete a test-mode account.
			 * @return array
			 */
			public function delete_account( bool $test_mode = false ): array {
				$this->deleted_test_mode = $test_mode;

				return array(
					'result' => 'success',
				);
			}
		};
		$adapter           = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( true );
		$adapter->method( 'has_valid_account' )->willReturn( true );
		$adapter->method( 'has_working_account' )->willReturn( true );
		$adapter->method( 'has_test_account' )->willReturn( true );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return false;
				},
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_stale',
					'payments_enabled'  => true,
					'details_submitted' => true,
					'is_test_drive'     => true,
					'is_live'           => false,
				),
			)
		);
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array( 'enabled_payment_methods' => array( 'card', 'link' ) ),
			HOUR_IN_SECONDS
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);

		$result = $this->sut->reset_onboarding( 'US' );
		$cached = get_option( 'wcpay_account_data' );

		$this->assertSame( true, $result['success'] );
		$this->assertTrue( $deleted_test_mode );
		$this->assertIsArray( $cached );
		$this->assertSame( array(), $cached['data'] );
		$this->assertSame( 'no', get_option( 'wcpay_onboarding_test_mode' ) );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
	}

	/**
	 * @testdox Native test-drive payment-method snapshots remain scoped to the active multisite blog.
	 * @group ms-required
	 */
	public function test_native_test_drive_payment_method_snapshot_is_site_scoped(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This assertion requires the multisite test configuration.' );
		}

		$main_site_id = get_current_blog_id();
		$subsite_id   = self::factory()->blog->create();
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array( 'enabled_payment_methods' => array( 'card' ) ),
			HOUR_IN_SECONDS
		);

		switch_to_blog( $subsite_id );
		try {
			set_transient(
				self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
				array( 'enabled_payment_methods' => array( 'card', 'link' ) ),
				HOUR_IN_SECONDS
			);
			$this->assertSame( array( 'card', 'link' ), $this->invoke_private_method( 'get_test_drive_enabled_payment_method_ids' ) );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $main_site_id, get_current_blog_id() );
		$this->assertSame( array( 'card' ), $this->invoke_private_method( 'get_test_drive_enabled_payment_method_ids' ) );
	}

	/**
	 * @testdox Should autoload the onboarding test-mode option when persisting it, since storefront renders read it.
	 */
	public function test_set_native_onboarding_test_mode_autoloads_option(): void {
		delete_option( 'wcpay_onboarding_test_mode' );

		$this->invoke_private_method( 'set_native_onboarding_test_mode', array( true ) );

		$this->assertSame( 'yes', get_option( 'wcpay_onboarding_test_mode' ) );
		$this->assertArrayHasKey( 'wcpay_onboarding_test_mode', wp_load_alloptions( true ), 'Client 11.1.0 autoloads it (onboarding service :1086).' );
	}

	/**
	 * @testdox Should not autoload the Stripe-connected onboarding marker when persisting it.
	 */
	public function test_update_native_gateway_settings_after_test_account_init_does_not_autoload_stripe_connected_marker(): void {
		delete_option( '_wcpay_onboarding_stripe_connected' );

		$this->invoke_private_method(
			'update_native_gateway_settings_after_test_account_init',
			array( array( 'card_payments' => true ), array( 'is_live' => false ) )
		);

		$this->assertIsArray( get_option( '_wcpay_onboarding_stripe_connected' ) );
		$this->assertOptionNotAutoloaded( '_wcpay_onboarding_stripe_connected' );
	}

	/**
	 * Create a WooPayments extension runtime wired to this test's mockable proxy.
	 *
	 * @return WooPaymentsExtensionRuntime
	 */
	private function create_extension_runtime(): WooPaymentsExtensionRuntime {
		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( $this->mockable_proxy );

		return $extension_runtime;
	}

	/**
	 * Create a native account service wired to this test's mockable legacy proxy.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_native_account_service(): WooPaymentsAccountService {
		$account_service = new WooPaymentsAccountService();
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( $this->mockable_proxy );
		$account_service->init( $this->mockable_proxy, $database_cache );

		return $account_service;
	}

	/**
	 * Create an adapter exposing the native finalization path.
	 *
	 * @return WooPaymentsOnboardingAdapter|MockObject
	 */
	private function create_native_finalize_adapter() {
		$adapter = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
				)
			)
			->getMock();
		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( true );
		$adapter->method( 'has_valid_account' )->willReturn( true );
		$adapter->method( 'has_working_account' )->willReturn( true );
		$adapter->method( 'has_test_account' )->willReturn( true );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );

		return $adapter;
	}

	/**
	 * Create an onboarding adapter wired to this test's mockable legacy proxy.
	 *
	 * @param bool $native_onboarding_available Whether native onboarding is available.
	 * @return WooPaymentsOnboardingAdapter
	 */
	private function create_onboarding_adapter( bool $native_onboarding_available = true ): WooPaymentsOnboardingAdapter {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'can_manage_onboarding' ) )
			->getMock();
		$provider
			->method( 'can_process_payments' )
			->willReturn( false );
		$provider
			->method( 'can_manage_onboarding' )
			->willReturn( $native_onboarding_available );

		$adapter         = new WooPaymentsOnboardingAdapter();
		$account_service = new WooPaymentsAccountService();
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( $this->mockable_proxy );
		$account_service->init( $this->mockable_proxy, $database_cache );
		$this->init_adapter( $adapter, $this->create_extension_runtime(), $provider, new WooPaymentsGateway(), $account_service, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		return $adapter;
	}

	/**
	 * Create an unavailable native API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_unavailable_api_client(): WooPaymentsApiClient {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();

		$api_client->method( 'is_available' )->willReturn( false );

		return $api_client;
	}

	/**
	 * Get the mock WooPayments supported countries.
	 *
	 * @return string[]
	 */
	private function get_woopayments_supported_countries(): array {
		return array(
			'AE' => 'United Arab Emirates',
			'AT' => 'Austria',
			'AU' => 'Australia',
			'BE' => 'Belgium',
			'BG' => 'Bulgaria',
			'CA' => 'Canada',
			'CH' => 'Switzerland',
			'CY' => 'Cyprus',
			'CZ' => 'Czech Republic',
			'DE' => 'Germany',
			'DK' => 'Denmark',
			'EE' => 'Estonia',
			'FI' => 'Finland',
			'ES' => 'Spain',
			'FR' => 'France',
			'HR' => 'Croatia',
			'JP' => 'Japan',
			'LU' => 'Luxembourg',
			'GB' => 'United Kingdom (UK)',
			'GR' => 'Greece',
			'HK' => 'Hong Kong',
			'HU' => 'Hungary',
			'IE' => 'Ireland',
			'IT' => 'Italy',
			'LT' => 'Lithuania',
			'LV' => 'Latvia',
			'MT' => 'Malta',
			'NL' => 'Netherlands',
			'NO' => 'Norway',
			'NZ' => 'New Zealand',
			'PL' => 'Poland',
			'PT' => 'Portugal',
			'RO' => 'Romania',
			'SE' => 'Sweden',
			'SI' => 'Slovenia',
			'SK' => 'Slovakia',
			'SG' => 'Singapore',
			'US' => 'United States (US)',
			'PR' => 'Puerto Rico',
		);
	}

	/**
	 * Initialize an onboarding adapter with its native collaborators.
	 *
	 * The adapter resolves them on first use, so the test doubles are set on the instance after init.
	 *
	 * @param WooPaymentsOnboardingAdapter $adapter         The adapter.
	 * @param WooPaymentsExtensionRuntime  $extension_runtime  The extension runtime.
	 * @param WooPaymentsProvider          $provider        The native provider.
	 * @param WooPaymentsGateway           $native_gateway  The native gateway.
	 * @param WooPaymentsAccountService    $account_service The native account service.
	 * @param WooPaymentsRuntimeArbiter    $arbiter         The runtime arbiter.
	 */
	private function init_adapter( WooPaymentsOnboardingAdapter $adapter, WooPaymentsExtensionRuntime $extension_runtime, WooPaymentsProvider $provider, WooPaymentsGateway $native_gateway, WooPaymentsAccountService $account_service, WooPaymentsRuntimeArbiter $arbiter ): void {
		$adapter->init( $extension_runtime, $arbiter );

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

	/**
	 * Tell whether a class check targets the WooPayments extension class.
	 *
	 * @param mixed $class_to_check Class name passed to class_exists().
	 * @return bool
	 */
	private function is_woopayments_class( $class_to_check ): bool {
		return 'WC_Payments' === ltrim( (string) $class_to_check, '\\' );
	}

	/**
	 * Run the admin_init callbacks the WooPayments settings controller registers, as an admin page load does.
	 *
	 * @param WooPaymentsController $controller Controller under test.
	 */
	private function run_woopayments_controller_admin_init( WooPaymentsController $controller ): void {
		global $wp_filter;

		$controller->register();
		remove_action( 'load-woocommerce_page_wc-settings', array( $controller, 'maybe_redirect_to_onboarding' ) );
		$callbacks = array();
		foreach ( $wp_filter['admin_init']->callbacks as $priority => $registered_callbacks ) {
			foreach ( $registered_callbacks as $registered_callback ) {
				$callback = $registered_callback['function'];
				if ( is_array( $callback ) && $controller === $callback[0] ) {
					$callbacks[] = $callback;
					remove_action( 'admin_init', $callback, $priority );
				}
			}
		}

		foreach ( $callbacks as $callback ) {
			call_user_func( $callback );
		}
	}

	/**
	 * Arrange the public native finalize path with configurable account refresh results.
	 *
	 * @param array<int,array<string,mixed>|\Throwable> $account_responses Account refresh results in request order.
	 * @param array<string,mixed>                       $selected_methods  Persisted NOX payment-method selections.
	 * @param array<string,mixed>|null                  $finalize_response Optional provider finalize response; defaults to a live-mode success.
	 * @param array<string,mixed>                       $session_extras    Extra fields the platform adds to the embedded KYC session.
	 * @return array{api_client:WooPaymentsApiClient,account_service:WooPaymentsAccountService}
	 */
	private function arrange_native_finalize_projection(
		array $account_responses,
		array $selected_methods,
		?array $finalize_response = null,
		array $session_extras = array()
	): array {
		$api_client      = new class( $account_responses, $finalize_response, $session_extras ) extends WooPaymentsApiClient {
			/**
			 * Account refresh results in request order.
			 *
			 * @var array<int,array<string,mixed>|\Throwable>
			 */
			private array $account_responses;

			/**
			 * Provider finalize response, or null for the default live-mode success.
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $finalize_response;

			/**
			 * Number of account refresh requests.
			 *
			 * @var int
			 */
			public int $account_requests = 0;

			/**
			 * Actioned notes each platform call received, keyed by API client method.
			 *
			 * @var array<string,array>
			 */
			public array $actioned_notes_by_call = array();

			/**
			 * Extra fields the platform adds to the embedded KYC session.
			 *
			 * @var array<string,mixed>
			 */
			private array $session_extras;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>|\Throwable> $account_responses Account refresh results.
			 * @param array<string,mixed>|null                  $finalize_response Provider finalize response.
			 * @param array<string,mixed>                       $session_extras    Extra embedded KYC session fields.
			 */
			public function __construct( array $account_responses, ?array $finalize_response, array $session_extras ) {
				$this->account_responses = $account_responses;
				$this->finalize_response = $finalize_response;
				$this->session_extras    = $session_extras;
			}

			/**
			 * Return an embedded KYC session for the existing account.
			 *
			 * @param bool        $live_account   Whether the session is for a live account.
			 * @param array       $site_data      Site data.
			 * @param array       $user_data      User data.
			 * @param array       $account_data   Account data.
			 * @param array       $actioned_notes Actioned notes.
			 * @param string|null $referral_code  Referral code.
			 * @return array
			 */
			public function initialize_onboarding_embedded_kyc( bool $live_account, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), ?string $referral_code = null ): array {
				unset( $live_account, $site_data, $user_data, $account_data, $actioned_notes, $referral_code );

				return array_merge(
					array(
						'clientSecret'   => 'accs_secret_finalized_native',
						'expiresAt'      => 1234567999,
						'accountId'      => 'acct_finalized_native',
						'isLive'         => true,
						'accountCreated' => false,
						'publishableKey' => 'pk_live_finalized_native',
					),
					$this->session_extras
				);
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the exact provider finalize response shape.
			 *
			 * @param string   $locale         User locale.
			 * @param string   $source         Onboarding source.
			 * @param string[] $actioned_notes Actioned notes.
			 * @return array<string,mixed>
			 */
			public function finalize_onboarding_embedded_kyc( string $locale, string $source, array $actioned_notes = array() ): array {
				unset( $locale, $source );
				$this->actioned_notes_by_call['finalize_onboarding_embedded_kyc'] = $actioned_notes;

				return $this->finalize_response ?? array(
					'success'           => true,
					'details_submitted' => true,
					'account_id'        => 'acct_finalized_native',
					'mode'              => 'live',
				);
			}

			/**
			 * Return or throw the next configured account refresh result.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 * @throws \Throwable Configured refresh failure.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				unset( $woocommerce_store_id );
				++$this->account_requests;
				$response = array_shift( $this->account_responses );

				if ( $response instanceof \Throwable ) {
					throw $response;
				}

				if ( ! is_array( $response ) ) {
					throw new \LogicException( 'No account refresh response was configured.' );
				}

				return $response;
			}

			/**
			 * Avoid an unrelated fraud ruleset API request while reading settings.
			 *
			 * @return array<string,mixed>
			 */
			public function get_latest_fraud_ruleset(): array {
				return array();
			}
		};
		$account_service = new class( $api_client ) extends WooPaymentsAccountService {
			/**
			 * Native API client.
			 *
			 * @var WooPaymentsApiClient
			 */
			private WooPaymentsApiClient $api_client;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiClient $api_client Native API client.
			 */
			public function __construct( WooPaymentsApiClient $api_client ) {
				$this->api_client = $api_client;
			}

			/**
			 * Get the native API client used by account refreshes.
			 *
			 * @return WooPaymentsApiClient
			 */
			protected function get_api_client(): ?WooPaymentsApiClient {
				return $this->api_client;
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( $this->mockable_proxy );
		$account_service->init( $this->mockable_proxy, $database_cache );

		$adapter = $this->create_native_finalize_adapter();

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					return ! $this->is_woopayments_class( $class_to_check );
				},
			)
		);

		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'           => 'acct_finalized_native',
					'live_publishable_key' => 'pk_live_finalized_native',
					'is_live'              => true,
				),
				'fetched'            => $this->current_time,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			'no'
		);
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array(
				'onboarding' => array(
					'US' => array(
						'steps' => array(
							WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
								'data' => array( 'payment_methods' => $selected_methods ),
							),
							WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
								'statuses' => array(
									WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 100,
								),
							),
						),
					),
				),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$account_service
		);

		return array(
			'api_client'      => $api_client,
			'account_service' => $account_service,
		);
	}

	/**
	 * Arrange the native NOX routes that create a KYC session or a test-drive account for a store without an account.
	 *
	 * @param array<string,mixed> $picks             Persisted NOX payment-method picks.
	 * @param array<string,mixed> $settings          Stored gateway settings.
	 * @param string              $wallets_enabled   Stored Apple Pay and Google Pay gateway `enabled` value.
	 * @param array<string,mixed> $platform_response Extra fields the platform adds to its session and test-drive responses.
	 * @return array{server:\WP_REST_Server,api_client:WooPaymentsApiClient,recorder:object}
	 */
	private function arrange_native_nox_picks( array $picks, array $settings, string $wallets_enabled, array $platform_response = array() ): array {
		$api_client = new class( $platform_response ) extends WooPaymentsApiClient {
			/**
			 * Enabled payment method IDs stored when the platform was called.
			 *
			 * @var mixed
			 */
			public $enabled_at_platform_call = null;

			/**
			 * Actioned notes each platform call received, keyed by API client method.
			 *
			 * @var array<string,array>
			 */
			public array $actioned_notes_by_call = array();

			/**
			 * Extra fields the platform adds to its responses.
			 *
			 * @var array<string,mixed>
			 */
			private array $platform_response;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $platform_response Extra response fields.
			 */
			public function __construct( array $platform_response ) {
				$this->platform_response = $platform_response;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return a session for an account that is not created yet.
			 *
			 * @param bool        $live_account   Whether the session is for a live account.
			 * @param array       $site_data      Site data.
			 * @param array       $user_data      User data.
			 * @param array       $account_data   Account data.
			 * @param array       $actioned_notes Actioned notes.
			 * @param string|null $referral_code  Referral code.
			 * @return array
			 */
			public function initialize_onboarding_embedded_kyc( bool $live_account, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), ?string $referral_code = null ): array {
				unset( $live_account, $site_data, $user_data, $account_data, $referral_code );
				$this->actioned_notes_by_call['initialize_onboarding_embedded_kyc'] = $actioned_notes;
				$this->enabled_at_platform_call                                     = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] ?? null;

				return array_merge(
					array(
						'clientSecret'   => 'accs_secret_native',
						'expiresAt'      => 1234567999,
						'accountId'      => 'acct_native',
						'isLive'         => true,
						'accountCreated' => false,
						'publishableKey' => 'pk_live_native',
					),
					$this->platform_response
				);
			}

			/**
			 * Return a created test-drive account.
			 *
			 * @param bool        $live_account                Whether the account is live.
			 * @param string      $return_url                  Return URL.
			 * @param array       $site_data                   Site data.
			 * @param array       $user_data                   User data.
			 * @param array       $account_data                Account data.
			 * @param array       $actioned_notes              Actioned notes.
			 * @param bool        $collect_payout_requirements Whether to collect payout requirements.
			 * @param string|null $referral_code               Referral code.
			 * @return array
			 */
			public function initialize_onboarding( bool $live_account, string $return_url, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), bool $collect_payout_requirements = false, ?string $referral_code = null ): array {
				unset( $live_account, $return_url, $site_data, $user_data, $account_data, $collect_payout_requirements, $referral_code );
				$this->actioned_notes_by_call['initialize_onboarding'] = $actioned_notes;
				$this->enabled_at_platform_call                        = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] ?? null;

				return array_merge(
					array(
						'url'               => false,
						'account_id'        => 'acct_native_test',
						'is_live'           => false,
						'publishable_key'   => 'pk_test_native',
						'payments_enabled'  => true,
						'details_submitted' => true,
					),
					$this->platform_response
				);
			}
		};
		$adapter    = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'is_native_onboarding_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
				)
			)
			->getMock();
		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		foreach ( array( 'has_account', 'has_valid_account', 'has_working_account', 'has_test_account', 'has_sandbox_account', 'has_live_account' ) as $method ) {
			$adapter->method( $method )->willReturn( false );
		}

		$this->mock_provider->method( 'is_in_dev_mode' )->willReturn( false );
		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					return ! $this->is_woopayments_class( $class_to_check );
				},
			)
		);
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array(
				'onboarding' => array(
					'US' => array(
						'steps' => array(
							WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
								'data' => array( 'payment_methods' => $picks ),
							),
						),
					),
				),
			)
		);
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => $wallets_enabled ) );
		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => $wallets_enabled ) );

		$recorder = $this->record_wcpay_tracks_event_names();

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_extension_runtime(),
			$api_client,
			$this->create_native_account_service()
		);
		$controller = new WooPaymentsRestController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );
		$server = $this->create_rest_server_with_routes(
			array(
				static function () use ( $controller ) {
					$controller->register_routes( true );
				},
			),
			true
		);

		return array(
			'server'     => $server,
			'api_client' => $api_client,
			'recorder'   => $recorder,
		);
	}

	/**
	 * Assert that a WordPress option is not flagged for autoload.
	 *
	 * Reads the raw autoload column to stay robust across WordPress versions:
	 * pre-6.6 stores 'no' while 6.6+ stores 'off'/'auto-off' for non-autoloaded options.
	 *
	 * @param string $option_name The option name to inspect.
	 * @return void
	 */
	private function assertOptionNotAutoloaded( string $option_name ): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		$this->assertNotNull( $autoload, sprintf( 'Option %s was not persisted.', $option_name ) );
		$this->assertNotContains(
			$autoload,
			array( 'yes', 'on', 'auto', 'auto-on' ),
			sprintf( 'Option %s should not be autoloaded, got autoload value "%s".', $option_name, $autoload )
		);
	}

	/**
	 * Save an admin note.
	 *
	 * @param string $name              Note name.
	 * @param string $status            Note status.
	 * @param int    $hours_after_start Hours after a fixed start to set as the creation date.
	 * @param bool   $is_deleted        Whether the note is soft-deleted.
	 */
	private function create_admin_note( string $name, string $status, int $hours_after_start = 0, bool $is_deleted = false ): void {
		$note = new Note();
		$note->set_name( $name );
		$note->set_title( $name );
		$note->set_content( $name );
		$note->set_status( $status );
		$note->set_is_deleted( $is_deleted );
		$note->save();
		// The note store stamps the current time on create, so the creation date is set on update.
		$note->set_date_created( self::TEST_EPOCH - DAY_IN_SECONDS + $hours_after_start * HOUR_IN_SECONDS );
		$note->save();
	}

	/**
	 * Create a NOX onboarding step request for the US location.
	 *
	 * @param string $route_suffix Route below the onboarding step base.
	 * @return WP_REST_Request
	 */
	private function create_nox_pick_request( string $route_suffix ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc-admin/settings/payments/woopayments/onboarding/step/' . $route_suffix );
		$request->set_param( 'location', 'US' );

		return $request;
	}

	/**
	 * Dispatch the real onboarding route that makes an API client call, and return the actioned notes the call received.
	 *
	 * @param string $call API client method: initialize_onboarding, initialize_onboarding_embedded_kyc or finalize_onboarding_embedded_kyc.
	 * @return array
	 */
	private function dispatch_onboarding_route_for_actioned_notes( string $call ): array {
		if ( 'finalize_onboarding_embedded_kyc' === $call ) {
			$fixture    = $this->arrange_native_finalize_projection( array( $this->get_native_finalize_projection_account() ), array( 'card' => true ) );
			$controller = new WooPaymentsRestController();
			$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );
			$server  = $this->create_rest_server_with_routes(
				array(
					static function () use ( $controller ) {
						$controller->register_routes( true );
					},
				),
				true
			);
			$request = $this->create_nox_pick_request( WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session/finish' );
			$request->set_param( 'source', WooPaymentsService::SESSION_ENTRY_LYS );
		} else {
			$fixture = $this->arrange_native_nox_picks( array( 'card' => true ), array( 'upe_enabled_payment_method_ids' => array( 'card' ) ), 'no' );
			$server  = $fixture['server'];
			$request = $this->create_nox_pick_request(
				'initialize_onboarding' === $call
					? WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/init'
					: WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session'
			);
		}

		$response = $server->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The onboarding route succeeds.' );
		$this->assertArrayHasKey( $call, $fixture['api_client']->actioned_notes_by_call, 'The route reaches the platform call.' );

		return $fixture['api_client']->actioned_notes_by_call[ $call ];
	}

	/**
	 * Get a fresh account payload for native finalize projection tests.
	 *
	 * @param array<string,mixed> $overrides Account data overrides.
	 * @return array<string,mixed>
	 */
	private function get_native_finalize_projection_account( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'account_id'        => 'acct_finalized_native',
				'is_live'           => true,
				'payments_enabled'  => true,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments'  => 'active',
					'ideal_payments' => 'active',
				),
				'fees'              => array(
					'card'  => array(),
					'ideal' => array(),
				),
			),
			$overrides
		);
	}

	/**
	 * Record the names of the WooPayments Tracks events sent through the WooPay recorder.
	 *
	 * @return object Recorder with the event names in its `events` property.
	 */
	private function record_wcpay_tracks_event_names(): object {
		$recorder = new class() {
			/**
			 * Recorded Tracks event names.
			 *
			 * @var string[]
			 */
			public array $events = array();

			/**
			 * Record the event name and pass the properties through.
			 *
			 * @param mixed  $properties Event properties.
			 * @param string $event_name Event name.
			 * @return mixed
			 */
			public function record( $properties, $event_name ) {
				$this->events[] = $event_name;

				return $properties;
			}
		};
		add_filter( 'wcpay_tracks_event_properties', array( $recorder, 'record' ), 10, 2 );

		return $recorder;
	}

	/**
	 * Refuse canonical settings writes that enable a payment method, so the synchronizer reports the write as not persisted.
	 *
	 * @param string          $payment_method_id Payment method whose enabling write fails.
	 * @param int|null        $failures          Number of writes to fail, or null to fail all of them.
	 * @param \Throwable|null $exception         Exception to throw instead of refusing the write.
	 * @return \stdClass Attempt counter, in its `count` property.
	 */
	private function refuse_native_settings_writes_enabling( string $payment_method_id, ?int $failures = null, ?\Throwable $exception = null ): \stdClass {
		$attempts        = new \stdClass();
		$attempts->count = 0;
		add_filter(
			'pre_update_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			static function ( $value, $old_value ) use ( $payment_method_id, $failures, $exception, $attempts ) {
				if ( ! is_array( $value ) || ! in_array( $payment_method_id, (array) ( $value['upe_enabled_payment_method_ids'] ?? array() ), true ) ) {
					return $value;
				}

				++$attempts->count;
				if ( null !== $failures && $attempts->count > $failures ) {
					return $value;
				}
				if ( null !== $exception ) {
					throw $exception;
				}

				return $old_value;
			},
			10,
			2
		);

		return $attempts;
	}

	/**
	 * Each onboarding action with the platform call it makes and the message it gives a platform error that has none.
	 *
	 * @return array<string,array{0:string,1:array,2:string,3:string}>
	 */
	public function provide_onboarding_actions_and_fallback_messages(): array {
		return array(
			'test account'       => array( 'init_test_account', array( 'US', array() ), 'initialize_onboarding', 'An unexpected error happened while initializing the test account.' ),
			'KYC session'        => array( 'create_kyc_session', array( false, array(), array() ), 'initialize_onboarding_embedded_kyc', 'An unexpected error happened while creating the KYC session.' ),
			'KYC finish'         => array( 'finish_kyc_session', array( 'settings_payments' ), 'finalize_onboarding_embedded_kyc', 'An unexpected error happened while finalizing the KYC session.' ),
			'reset'              => array( 'reset_onboarding', array(), 'delete_account', 'An unexpected error happened while resetting onboarding.' ),
			'disable test drive' => array( 'disable_test_account', array( true ), 'delete_account', 'An unexpected error happened while disabling the test account.' ),
			'disable sandbox'    => array( 'disable_test_account', array( false ), 'delete_account', 'An unexpected error happened while disabling the test account.' ),
		);
	}

	/**
	 * @testdox A platform error becomes a WP_Error with the platform's code and type, and the action's message when the platform gave none: $_dataName.
	 * @dataProvider provide_onboarding_actions_and_fallback_messages
	 *
	 * @param string $method        Onboarding action.
	 * @param array  $args          Its arguments.
	 * @param string $platform_call The platform call that fails.
	 * @param string $fallback      The message the action gives.
	 */
	public function test_platform_errors_become_wp_errors_with_the_action_message( string $method, array $args, string $platform_call, string $fallback ): void {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', $platform_call ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( $platform_call )->willThrowException( new WooPaymentsApiException( '', 'wcpay_platform_error', 400, 'invalid_request_error' ) );
		$this->set_onboarding_collaborators( $api_client );

		$result = $this->onboarding_service->{$method}( ...$args );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpay_platform_error', $result->get_error_code() );
		$this->assertSame( $fallback, $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['code'] );
		$this->assertSame( 'invalid_request_error', $result->get_error_data()['error_type'] );
	}

	/**
	 * @testdox Resetting onboarding deletes the account in the onboarding mode the store is in.
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $test_mode_option   The stored onboarding test mode.
	 * @param bool   $expected_test_mode The mode the platform is asked to delete the account in.
	 */
	public function test_reset_deletes_the_account_in_the_onboarding_mode( string $test_mode_option, bool $expected_test_mode ): void {
		update_option( 'wcpay_onboarding_test_mode', $test_mode_option );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'delete_account' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		// The success body client 11.1.0 reads after delete_account() (includes/class-wc-payments-onboarding-service.php:831-832).
		$api_client->expects( $this->once() )->method( 'delete_account' )->with( $expected_test_mode )->willReturn( array( 'result' => 'success' ) );
		$this->set_onboarding_collaborators( $api_client );

		$result = $this->onboarding_service->reset_onboarding();

		$this->assertTrue( $result['success'] );
	}

	/**
	 * @testdox Disabling a test-drive account saves its payment methods and deletes it in test mode; a sandbox account is deleted in the onboarding mode with nothing saved.
	 * @testWith [true, true]
	 *           [false, false]
	 *
	 * @param bool $is_test_drive      Whether the account is a test-drive account.
	 * @param bool $expected_test_mode The mode the platform is asked to delete the account in.
	 */
	public function test_disable_test_account_saves_only_a_test_drive_account( bool $is_test_drive, bool $expected_test_mode ): void {
		update_option( 'wcpay_onboarding_test_mode', 'no' );
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'delete_account' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		// The success body client 11.1.0 reads after delete_account() (includes/class-wc-payments-onboarding-service.php:831-832).
		$api_client->expects( $this->once() )->method( 'delete_account' )->with( $expected_test_mode )->willReturn( array( 'result' => 'success' ) );
		$this->set_onboarding_collaborators( $api_client );

		$result = $this->onboarding_service->disable_test_account( $is_test_drive );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $is_test_drive, is_array( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) ) );
	}

	/**
	 * @testdox A referral code is stored cut to 50 characters, lowercased and trimmed for 30 days, and the referral is recorded.
	 *
	 * Source: the extension's `WC_Payments_Onboarding_Service::normalize_and_store_referral_code()` and the
	 * `wcpay_account_referral` event `WC_Payments_Account::maybe_redirect_onboarding_referral()` records.
	 */
	public function test_store_referral_code_stores_the_normalized_code_and_records_the_referral(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		add_filter(
			'woocommerce_tracks_event_properties',
			function ( $properties, $event_name ) use ( &$recorded ) {
				$recorded[ $event_name ] = $properties;
				return $properties;
			},
			10,
			2
		);

		$stored = $this->onboarding_service->store_referral_code( ' ' . str_repeat( 'Ab', 30 ) );

		$this->assertSame( str_repeat( 'ab', 24 ) . 'a', $stored );
		$this->assertSame( $stored, get_transient( 'woopayments_referral_code' ) );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) get_option( '_transient_timeout_woopayments_referral_code' ), 60 );
		$this->assertSame( $stored, $recorded['wcadmin_wcpay_account_referral']['referral_code'] );
	}

	/**
	 * @testdox An empty referral code stores nothing and records nothing.
	 */
	public function test_store_referral_code_ignores_an_empty_code(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		add_filter(
			'woocommerce_tracks_event_properties',
			function ( $properties, $event_name ) use ( &$recorded ) {
				$recorded[ $event_name ] = $properties;
				return $properties;
			},
			10,
			2
		);

		$this->assertSame( '', $this->onboarding_service->store_referral_code( '   ' ) );
		$this->assertFalse( get_transient( 'woopayments_referral_code' ) );
		$this->assertArrayNotHasKey( 'wcadmin_wcpay_account_referral', $recorded );
	}

	/**
	 * @testdox Should expose recommended payment methods for the settings provider list.
	 */
	public function test_get_recommended_payment_methods_delegates_to_the_api_client(): void {
		$this->seed_recommended_payment_methods_cache( null );
		// Synthetic recommendations in the platform's shape (id, title, type, priority), as in client 11.1.0 tests/unit/test-class-wc-payments-account.php:3661-3739.
		$api_client = $this->create_recommendations_api_client(
			1,
			array(
				array(
					'id'    => 'card',
					'title' => 'Cards',
				),
				array(
					'id'       => 'link',
					'title'    => 'Link',
					'type'     => 'available',
					'priority' => '7',
				),
				array(
					'title' => 'Invalid recommendation',
				),
			)
		);
		$this->set_onboarding_collaborators( $api_client );

		$result = $this->onboarding_service->get_recommended_payment_methods( 'GB' );

		$this->assertCount( 2, $result );
		$this->assertSame( 'card', $result[0]['id'] );
		$this->assertTrue( $result[0]['enabled'] );
		$this->assertSame( 0, $result[0]['priority'] );
		$this->assertSame( 'link', $result[1]['id'] );
		$this->assertFalse( $result[1]['enabled'] );
		$this->assertSame( 7, $result[1]['priority'] );
	}

	/**
	 * @testdox The platform's recommendations are stored as client 11.1.0 stores them: the raw answer with its country and locale, in a non-autoloaded cache option.
	 *
	 * Source: client 11.1.0 `includes/class-wc-payments-onboarding-service.php:185-217`, `includes/class-database-cache.php:23`.
	 */
	public function test_recommended_payment_methods_are_stored_in_the_shared_cache_option(): void {
		$this->seed_recommended_payment_methods_cache( null );
		$answer = array(
			array(
				'id'    => 'card',
				'title' => 'Cards',
			),
			array(
				'id'    => 'ideal',
				'title' => 'iDEAL',
				'type'  => 'available',
			),
		);
		$this->set_onboarding_collaborators( $this->create_recommendations_api_client( 1, $answer ) );

		$this->onboarding_service->get_recommended_payment_methods( 'gb' );

		$stored = get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY );
		$this->assertSame(
			array(
				'payment_methods' => $answer,
				'__locale'        => 'en_US',
				'__country_code'  => 'GB',
			),
			$stored['data']
		);
		$this->assertFalse( $stored['errored'] );
		$this->assertOptionNotAutoloaded( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY );
	}

	/**
	 * @testdox A stored answer for the same country and locale, as client 11.1.0 writes it, is served without asking the platform.
	 */
	public function test_recommended_payment_methods_come_from_a_stored_entry(): void {
		$this->seed_recommended_payment_methods_cache(
			array(
				'payment_methods' => array(
					array(
						'id'    => 'stored',
						'title' => 'Stored',
						'type'  => 'available',
					),
				),
				'__locale'        => 'en_US',
				'__country_code'  => 'GB',
			)
		);
		$this->set_onboarding_collaborators( $this->create_recommendations_api_client( 0, array() ) );

		$result = $this->onboarding_service->get_recommended_payment_methods( 'GB' );

		$this->assertSame( 'stored', $result[0]['id'] );
		$this->assertFalse( $result[0]['enabled'] );
		$this->assertSame( 0, $result[0]['priority'] );
	}

	/**
	 * @testdox A stored answer for another country or locale is fetched again.
	 * @testWith ["GB", "de_DE"]
	 *           ["FR", "en_US"]
	 *
	 * @param string $cached_country Country of the stored answer.
	 * @param string $cached_locale  Locale of the stored answer.
	 */
	public function test_get_recommended_payment_methods_refetches_for_another_country_or_locale( string $cached_country, string $cached_locale ): void {
		$this->seed_recommended_payment_methods_cache(
			array(
				'payment_methods' => array(
					array(
						'id'    => 'stale',
						'title' => 'Stale',
					),
				),
				'__locale'        => $cached_locale,
				'__country_code'  => $cached_country,
			)
		);
		$this->set_onboarding_collaborators(
			$this->create_recommendations_api_client(
				1,
				array(
					array(
						'id'    => 'card',
						'title' => 'Cards',
					),
				)
			)
		);

		$result = $this->onboarding_service->get_recommended_payment_methods( 'GB' );

		$this->assertSame( 'card', $result[0]['id'] );
	}

	/**
	 * @testdox A platform error keeps the last good answer and waits out the cache lifetime before asking again, as client 11.1.0's database cache does.
	 */
	public function test_recommended_payment_methods_keep_the_last_good_answer_after_a_platform_error(): void {
		$this->seed_recommended_payment_methods_cache(
			array(
				'payment_methods' => array(
					array(
						'id'    => 'card',
						'title' => 'Cards',
					),
				),
				'__locale'        => 'en_US',
				'__country_code'  => 'GB',
			),
			$this->current_time - 2 * DAY_IN_SECONDS
		);
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client->expects( $this->once() )->method( 'get_recommended_payment_methods' )->willThrowException( self::make_provider_error() );
		$this->set_onboarding_collaborators( $api_client );

		$first  = $this->onboarding_service->get_recommended_payment_methods( 'GB' );
		$second = $this->onboarding_service->get_recommended_payment_methods( 'GB' );

		$this->assertSame( 'card', $first[0]['id'] );
		$this->assertSame( $first, $second );
		$this->assertTrue( get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY )['errored'] );
	}

	/**
	 * @testdox An empty answer is stored like any other, so the next request does not ask the platform again.
	 */
	public function test_recommended_payment_methods_store_an_empty_answer(): void {
		$this->seed_recommended_payment_methods_cache( null );
		$this->set_onboarding_collaborators( $this->create_recommendations_api_client( 1, array() ) );

		$this->assertSame( array(), $this->onboarding_service->get_recommended_payment_methods( 'GB' ) );
		$this->assertSame( array(), $this->onboarding_service->get_recommended_payment_methods( 'GB' ) );
		$stored = get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY );
		$this->assertSame( array(), $stored['data']['payment_methods'] );
		$this->assertFalse( $stored['errored'] );
	}

	/**
	 * @testdox A country WooPayments does not support ($country) gets no recommendations and no platform call.
	 *
	 * Source: client 11.1.0 `includes/class-wc-payments-account.php:698-702` and its test
	 * `test_get_recommended_payment_methods_unsupported_country` (`tests/unit/test-class-wc-payments-account.php:3657-3659`).
	 *
	 * @dataProvider provide_unsupported_countries
	 *
	 * @param string $country Business location country code.
	 */
	public function test_recommended_payment_methods_skip_the_platform_for_an_unsupported_country( string $country ): void {
		$this->seed_recommended_payment_methods_cache( null );
		$this->set_onboarding_collaborators( $this->create_recommendations_api_client( 0, array() ) );

		$this->assertSame( array(), $this->onboarding_service->get_recommended_payment_methods( $country ) );
		$this->assertFalse( get_option( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY ), 'Nothing is cached for an unsupported country.' );
	}

	/**
	 * @testdox A supported country gets its recommendations even when a woocommerce_countries filter removes it from the store's country list.
	 *
	 * Source: client 11.1.0 checks its fixed list (`includes/class-wc-payments-account.php:684-686,698-702`, `includes/class-wc-payments-utils.php:263-305`).
	 */
	public function test_recommended_payment_methods_ignore_a_filtered_store_country_list(): void {
		$this->seed_recommended_payment_methods_cache( null );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( 'US', 'en_US' )
			// Synthetic recommendation with the two fields client 11.1.0 validates (includes/class-wc-payments-account.php:708-715: id and title).
			->willReturn(
				array(
					array(
						'id'    => 'card',
						'title' => 'Cards',
					),
				)
			);
		$this->set_onboarding_collaborators( $api_client );
		$countries      = WC()->countries;
		WC()->countries = new \WC_Countries();
		add_filter(
			'woocommerce_countries',
			static function ( array $all ): array {
				unset( $all['US'] );
				return $all;
			}
		);

		try {
			$result = $this->onboarding_service->get_recommended_payment_methods( 'US' );
		} finally {
			WC()->countries = $countries;
		}

		$this->assertSame( array( 'card' ), array_column( $result, 'id' ) );
	}

	/**
	 * Provide country codes WooPayments does not support.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_unsupported_countries(): array {
		return array(
			'an unassigned code'   => array( 'XZ' ),
			'a real store country' => array( 'BR' ),
			'an empty code'        => array( '' ),
		);
	}

	/**
	 * @testdox A platform error fetching the recommended payment methods is logged with its status and code, never its message.
	 */
	public function test_recommended_payment_methods_fetch_log_leaves_out_platform_text(): void {
		$this->seed_recommended_payment_methods_cache( null );
		self::enable_woopayments_debug_logging();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client->method( 'get_recommended_payment_methods' )->willThrowException( self::make_provider_error() );
		$this->set_onboarding_collaborators( $api_client );
		$logger = RecordingWcLogger::install();

		$this->assertSame( array(), $this->onboarding_service->get_recommended_payment_methods( 'GB' ) );

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Create an API client double that answers the recommended payment methods for GB in en_US.
	 *
	 * @param int                            $calls  How many times the platform must be asked.
	 * @param array<int,array<string,mixed>> $answer The platform's answer.
	 * @return WooPaymentsApiClient&MockObject
	 */
	private function create_recommendations_api_client( int $calls, array $answer ): WooPaymentsApiClient {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_recommended_payment_methods' ) )
			->getMock();
		$api_client
			->expects( $this->exactly( $calls ) )
			->method( 'get_recommended_payment_methods' )
			->with( 'GB', 'en_US' )
			->willReturn( $answer );

		return $api_client;
	}

	/**
	 * Store the recommended payment methods cache entry, or remove it.
	 *
	 * @param array<string,mixed>|null $data    The cached data, or null for no entry.
	 * @param int|null                 $fetched When the data was fetched; the test's current time by default.
	 */
	private function seed_recommended_payment_methods_cache( ?array $data, ?int $fetched = null ): void {
		wc_get_container()->get( WooPaymentsDatabaseCache::class )->delete( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY );
		if ( null !== $data ) {
			update_option(
				WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY,
				array(
					'data'    => $data,
					'fetched' => $fetched ?? $this->current_time,
					'errored' => false,
				),
				false
			);
		}
	}

	/**
	 * Create trunk's WooPayments controller over this test's settings service, reaching this test's adapter.
	 *
	 * @return WooPaymentsController
	 */
	private function create_trunk_controller(): WooPaymentsController {
		$reflection = new \ReflectionProperty( WooPaymentsService::class, 'onboarding_adapter' );
		$reflection->setAccessible( true );
		wc_get_container()->replace( WooPaymentsOnboardingAdapter::class, $reflection->getValue( $this->sut ) );
		$controller = new WooPaymentsController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );

		return $controller;
	}

	/**
	 * Give the onboarding service an API client double and an account service on this test's proxy.
	 *
	 * @param WooPaymentsApiClient $api_client The API client double.
	 */
	private function set_onboarding_collaborators( WooPaymentsApiClient $api_client ): void {
		$collaborators = array(
			'api_client'      => $api_client,
			'account_service' => $this->create_native_account_service(),
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsOnboardingService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $this->onboarding_service, $collaborator );
		}
	}

	/**
	 * Helper method to invoke private methods for testing.
	 *
	 * @param string $method_name The name of the private method.
	 * @param array  $args        The arguments to pass to the method.
	 * @return mixed The result of the method call.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->onboarding_service );
		if ( ! $reflection->hasMethod( $method_name ) ) {
			$this->fail( sprintf( 'Method %s not found on %s', $method_name, get_class( $this->onboarding_service ) ) );
		}
		$method = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->onboarding_service, $args );
	}
}
