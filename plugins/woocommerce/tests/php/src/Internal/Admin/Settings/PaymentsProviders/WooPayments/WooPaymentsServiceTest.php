<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\Jetpack\Connection\Manager as WPCOM_Connection_Manager;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\DefaultPaymentGateways;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Internal\Admin\Settings\Exceptions\ApiException;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsRestController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Admin\Settings\Mocks\FakePaymentGateway;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * WooPayments settings provider service test.
 *
 * @class WooPaymentsService
 */
class WooPaymentsServiceTest extends WC_Unit_Test_Case {

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

	private const PENDING_PAYMENT_METHODS_PROJECTION_OPTION = 'woocommerce_woopayments_pending_payment_method_projection';

	private const TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT = 'test_drive_account_settings_for_live_account';

	private const WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT = 'woopay_enabled_by_default';

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

		foreach ( $this->get_refresh_projection_callbacks() as $registered_callback ) {
			remove_action( 'woocommerce_payments_account_refreshed', $registered_callback['callback'], $registered_callback['priority'] );
		}

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$this->create_onboarding_adapter(),
			$this->create_legacy_runtime(),
			$this->create_unavailable_api_client(),
			$this->create_native_account_service()
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		remove_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_project_pending_onboarding_payment_methods' ) );
		delete_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION );
		delete_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );
		delete_transient( 'woopayments_referral_code' );
		delete_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		unset( $_GET['wcpay-connection-success'] );
		remove_all_filters( 'woocommerce_tracks_event_properties' );
		remove_all_filters( 'wcpay_tracks_event_properties' );
		remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->clear_rest_server();

		parent::tearDown();
	}

	/**
	 * Initialize the service under test with its native collaborators.
	 *
	 * The service resolves them on first use, so the test doubles are set on the instance after init, leaving other
	 * container consumers untouched.
	 *
	 * @param PaymentsProviders            $providers          The providers service.
	 * @param LegacyProxy                  $proxy              The legacy proxy.
	 * @param WooPaymentsOnboardingAdapter $onboarding_adapter The onboarding adapter.
	 * @param WooPaymentsLegacyRuntime     $legacy_runtime     The legacy runtime.
	 * @param WooPaymentsApiClient         $api_client         The native API client.
	 * @param WooPaymentsAccountService    $account_service    The native account service.
	 */
	private function init_sut( PaymentsProviders $providers, LegacyProxy $proxy, WooPaymentsOnboardingAdapter $onboarding_adapter, WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->sut->init( $providers, $proxy );

		$collaborators = array(
			'onboarding_adapter' => $onboarding_adapter,
			'legacy_runtime'     => $legacy_runtime,
			'api_client'         => $api_client,
			'account_service'    => $account_service,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $this->sut, $collaborator );
		}
	}

	/**
	 * @testdox Should expose a safe native account summary for the WooPayments settings surface.
	 */
	public function test_get_account_summary_returns_safe_native_account_state(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'             => 'acct_native_test',
					'test_publishable_key'   => 'pk_test_secret',
					'live_publishable_key'   => 'pk_live_secret',
					'payments_enabled'       => true,
					'details_submitted'      => true,
					'is_test_drive'          => true,
					'is_live'                => false,
					'is_documents_enabled'   => true,
					'has_submitted_vat_data' => true,
					'country'                => 'NL',
					'store_currencies'       => array(
						'default' => 'eur',
					),
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
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		// A connected store whose account fetch fails, so the summary reads the seeded cache.
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new WooPaymentsApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );

		$summary = $this->sut->get_account_summary();

		$this->assertSame( 'acct_native_test', $summary['account']['id'] );
		$this->assertSame( 'test', $summary['account']['mode'] );
		$this->assertSame( 'eur', $summary['account']['default_currency'] );
		$this->assertTrue( $summary['account']['connected'] );
		$this->assertTrue( $summary['account']['working'] );
		$this->assertTrue( $summary['account']['can_process_payments'] );
		$this->assertTrue( $summary['account']['test_mode'] );
		$this->assertTrue( $summary['account']['test_drive'] );
		$this->assertFalse( $summary['account']['sandbox'] );
		$this->assertFalse( $summary['account']['live'] );
		$this->assertSame(
			array(
				'enabled'                => true,
				'has_submitted_vat_data' => true,
				'country'                => 'NL',
			),
			$summary['documents']
		);
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $summary['urls']['overview_page'] );
		$this->assertStringNotContainsString( 'wcpay-connection-success', $summary['urls']['overview_page'] );
		$this->assertStringNotContainsString( 'wcpay-connection-error', $summary['urls']['overview_page'] );
		// Client 11.1.0 `sandbox-mode-switch-to-live-notice/modal/index.tsx:39-45`: Activate payments goes to the onboarding route.
		$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/onboarding' ), $summary['urls']['setup'] );
		$this->assertArrayNotHasKey( 'test_publishable_key', $summary['account'] );
		$this->assertArrayNotHasKey( 'live_publishable_key', $summary['account'] );
		$this->assertArrayNotHasKey( 'is_documents_enabled', $summary['account'] );
		$this->assertArrayNotHasKey( 'has_submitted_vat_data', $summary['account'] );
	}

	/**
	 * @testdox Should expose a safe no-account summary for stores that still need setup.
	 */
	public function test_get_account_summary_returns_no_account_state(): void {
		update_option( 'wcpay_account_data', array( 'data' => array() ) );

		$summary = $this->sut->get_account_summary();

		$this->assertSame( '', $summary['account']['id'] );
		$this->assertSame( 'live', $summary['account']['mode'] );
		$this->assertSame( 'usd', $summary['account']['default_currency'] );
		$this->assertFalse( $summary['account']['connected'] );
		$this->assertFalse( $summary['account']['working'] );
		$this->assertFalse( $summary['account']['can_process_payments'] );
		$this->assertFalse( $summary['account']['test_mode'] );
		$this->assertFalse( $summary['account']['test_drive'] );
		$this->assertFalse( $summary['account']['sandbox'] );
		$this->assertFalse( $summary['account']['live'] );
		$this->assertSame(
			array(
				'enabled'                => false,
				'has_submitted_vat_data' => false,
				'country'                => '',
			),
			$summary['documents']
		);
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $summary['urls']['overview_page'] );
		$this->assertStringNotContainsString( 'wcpay-connection-success', $summary['urls']['overview_page'] );
		$this->assertStringNotContainsString( 'wcpay-connection-error', $summary['urls']['overview_page'] );
		$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/onboarding' ), $summary['urls']['setup'] );
	}

	/**
	 * Test get onboarding details when the extension is NOT active.
	 */
	public function test_get_onboarding_details_throws_when_extension_not_active(): void {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		// Act.
		try {
			$this->sut->get_onboarding_details( $location, '/some/path' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test get onboarding details uses the onboarding adapter runtime availability.
	 */
	public function test_get_onboarding_details_uses_onboarding_adapter_runtime_availability(): void {
		$location = 'US';
		$gateway  = new FakePaymentGateway( WooPaymentsService::GATEWAY_ID );

		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return true;
				},
			)
		);

		$onboarding_adapter = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
					'get_onboarding_kyc_fallback_url',
					'get_overview_page_url',
				)
			)
			->getMock();
		$onboarding_adapter
			->method( 'is_onboarding_runtime_available' )
			->willReturn( true );
		$onboarding_adapter
			->method( 'get_payment_gateway' )
			->willReturn( $gateway );
		$onboarding_adapter
			->method( 'has_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'has_valid_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'has_working_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'has_test_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'has_sandbox_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'has_live_account' )
			->willReturn( false );
		$onboarding_adapter
			->method( 'get_onboarding_kyc_fallback_url' )
			->willReturn( 'https://example.com/native-kyc' );
		$onboarding_adapter
			->method( 'get_overview_page_url' )
			->willReturn( 'https://example.com/native-overview' );

		$this->sut = new WooPaymentsService();
		$this->init_sut( $this->mock_providers, $this->mockable_proxy, $onboarding_adapter, $this->create_legacy_runtime(), $this->create_unavailable_api_client(), $this->create_native_account_service() );

		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString(
			'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
			rawurldecode( $result['context']['urls']['overview_page'] )
		);
		$this->assertStringContainsString( 'wcpay-connection-success=1', $result['context']['urls']['overview_page'] );
		$this->assertStringNotContainsString( 'wcpay-connection-error', $result['context']['urls']['overview_page'] );
	}

	/**
	 * @testdox Native onboarding details should read the KYC fields through the account service cache, not plugin-owned onboarding REST routes.
	 *
	 * @return void
	 */
	public function test_get_onboarding_details_uses_native_fields_when_legacy_onboarding_route_is_unavailable(): void {
		$location    = 'US';
		$fields_data = array(
			'business_types'    => $this->get_mock_onboarding_fields_business_types(),
			'mccs_display_tree' => array(
				array(
					'id'    => 'most_popular',
					'type'  => 'group',
					'title' => 'Most popular',
					'items' => array(),
				),
			),
		);
		$api_client  = new class( $fields_data ) extends WooPaymentsApiClient {
			/**
			 * Native onboarding fields response.
			 *
			 * @var array
			 */
			private array $fields_data;

			/**
			 * Number of native fields reads.
			 *
			 * @var int
			 */
			public int $fields_calls = 0;

			/**
			 * Constructor.
			 *
			 * @param array $fields_data Native onboarding fields response.
			 */
			public function __construct( array $fields_data ) {
				$this->fields_data = $fields_data;
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
			 * Get fake onboarding fields data.
			 *
			 * @param string $locale User locale.
			 * @return array
			 */
			public function get_onboarding_fields_data( string $locale = '' ): array {
				unset( $locale );
				++$this->fields_calls;
				return $this->fields_data;
			}
		};
		$this->arrange_native_fields_onboarding( $api_client );

		$result                     = $this->sut->get_onboarding_details( $location, '/some/path' );
		$business_verification_step = $this->find_onboarding_step( $result['steps'], WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION );
		$second_result              = $this->sut->get_onboarding_details( $location, '/some/path' );

		$this->assertSame( 1, $api_client->fields_calls, 'Every WooCommerce admin screen builds these details; the fields must come from the cache after the first read.' );
		$this->assertSame( $business_verification_step, $this->find_onboarding_step( $second_result['steps'], WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION ) );
		$this->assertSame( $location, $business_verification_step['context']['fields']['location'] );
		$this->assertSame( 'en_US', $business_verification_step['context']['fields']['__locale'] );
		$this->assertSame(
			DefaultPaymentGateways::get_wcpay_countries(),
			array_keys( $business_verification_step['context']['fields']['available_countries'] )
		);
		$this->assertSame( $fields_data['business_types'], $business_verification_step['context']['fields']['business_types'] );
		$this->assertSame( array(), $business_verification_step['errors'] );
	}

	/**
	 * @testdox Native onboarding details should report the client's fields error when the fields cannot be fetched and nothing is cached.
	 */
	public function test_get_onboarding_details_reports_fields_error_when_native_fields_fetch_fails(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Whether fields reads fail.
			 *
			 * @var bool
			 */
			private bool $fail = true;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Fail onboarding fields reads.
			 *
			 * @param string $locale User locale.
			 * @return array
			 * @throws WooPaymentsApiException When reads fail.
			 */
			public function get_onboarding_fields_data( string $locale = '' ): array {
				unset( $locale );
				if ( $this->fail ) {
					throw new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_http_request_failed', 500 );
				}

				return array();
			}
		};
		$this->arrange_native_fields_onboarding( $api_client );

		$result                     = $this->sut->get_onboarding_details( 'US', '/some/path' );
		$business_verification_step = $this->find_onboarding_step( $result['steps'], WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION );

		$this->assertSame( array(), $business_verification_step['context']['fields'] );
		$this->assertSame(
			array(
				array(
					'code'    => 'fields_error',
					'message' => 'Failed to retrieve the onboarding fields.',
					'context' => array(),
				),
			),
			$business_verification_step['errors'],
			'Client 11.1.0 returns this error from the fields route when get_fields_data() returns null (class-wc-rest-payments-onboarding-controller.php:340-342).'
		);
	}

	/**
	 * Test onboarding details read the KYC fields from the plugin's route, not the native client, while the plugin runtime is loaded.
	 *
	 * @return void
	 */
	public function test_get_onboarding_details_uses_plugin_fields_route_when_plugin_runtime_is_loaded(): void {
		$location              = 'US';
		$plugin_business_types = $this->get_mock_onboarding_fields_business_types();
		$api_client            = new class() extends WooPaymentsApiClient {
			/**
			 * Number of native fields reads.
			 *
			 * @var int
			 */
			public int $fields_calls = 0;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Count native fields reads.
			 *
			 * @param string $locale User locale.
			 * @return array
			 */
			public function get_onboarding_fields_data( string $locale = '' ): array {
				unset( $locale );
				++$this->fields_calls;
				return array( 'business_types' => array() );
			}
		};
		$adapter               = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
					'get_onboarding_kyc_fallback_url',
					'get_overview_page_url',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( false );
		$adapter->method( 'has_valid_account' )->willReturn( false );
		$adapter->method( 'has_working_account' )->willReturn( false );
		$adapter->method( 'has_test_account' )->willReturn( false );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );
		$adapter->method( 'get_onboarding_kyc_fallback_url' )->willReturn( 'https://example.com/kyc' );
		$adapter->method( 'get_overview_page_url' )->willReturn( 'https://example.com/overview' );

		$this->mock_provider->method( 'is_onboarding_supported' )->willReturn( true );
		$this->mock_provider->method( 'is_onboarding_started' )->willReturn( false );
		$this->mock_provider->method( 'is_onboarding_completed' )->willReturn( false );
		$this->mock_provider->method( 'is_in_test_mode_onboarding' )->willReturn( true );
		$this->mock_provider->method( 'is_in_dev_mode' )->willReturn( false );
		$this->mock_provider->method( 'get_recommended_payment_methods' )->willReturn( array() );

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'is_connection_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( string $class_name ) {
					return 'WC_Payments' === $class_name;
				},
			)
		);

		$plugin_route_calls = 0;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'wc_payments_settings_url'           => function (): string {
						return 'https://example.com/payments-settings';
					},
					'get_wpcom_connection_authorization' => function (): array {
						return array(
							'success'      => true,
							'errors'       => array(),
							'color_scheme' => 'fresh',
							'url'          => 'https://wordpress.com/auth?query=some_query',
						);
					},
					'rest_endpoint_get_request'          => function ( string $endpoint ) use ( &$plugin_route_calls, $plugin_business_types ) {
						if ( '/wc/v3/payments/onboarding/fields' === $endpoint ) {
							++$plugin_route_calls;
							return array( 'data' => array( 'business_types' => $plugin_business_types ) );
						}

						throw new \Exception( esc_html( 'GET endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_legacy_runtime(),
			$api_client,
			$this->create_native_account_service()
		);

		$result                     = $this->sut->get_onboarding_details( $location, '/some/path' );
		$business_verification_step = $this->find_onboarding_step( $result['steps'], WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION );

		$this->assertSame( 0, $api_client->fields_calls, 'The native client must stay dormant while the plugin runtime is loaded.' );
		$this->assertSame( 1, $plugin_route_calls );
		$this->assertSame( $plugin_business_types, $business_verification_step['context']['fields']['business_types'] );
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
					'get_overview_page_url',
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
		$adapter->method( 'get_overview_page_url' )->willReturn( 'https://example.com/native-overview' );

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
			$this->create_legacy_runtime(),
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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
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
		$this->assertSame( 'yes', get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['enabled'], 'Session creation enabled the card gateway.' );
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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$controller = new WooPaymentsController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );

		$this->sut->get_onboarding_kyc_session( 'US' );
		$this->assertSame( 'yes' === $landed_woopay, (bool) get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ), 'Session creation stores the platform answer.' );

		$this->sut->finish_onboarding_kyc_session( 'US' );
		$this->run_woopayments_controller_admin_init( $controller );
		$this->assertSame( 'no', get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['platform_checkout'] ?? 'no', 'Neither finalization nor a page without the success flag turns WooPay on.' );

		$_GET['wcpay-connection-success'] = '1';
		$this->run_woopayments_controller_admin_init( $controller );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$controller = new WooPaymentsController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );
		update_option(
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);
		set_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		$_GET['wcpay-connection-success'] = '1';

		$this->run_woopayments_controller_admin_init( $controller );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

		$this->assertSame( 'no', $settings['platform_checkout'] );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ) );
		$this->assertNotContains( 'wcadmin_woopay_enabled', $recorder->events );
	}

	/**
	 * While the WooPayments plugin owns the runtime, its own maybe_activate_woopay() reads the answer, so native leaves it alone.
	 *
	 * @testdox The platform's WooPay default is left to the WooPayments plugin while native does not own onboarding.
	 */
	public function test_platform_woopay_default_is_left_to_the_plugin_when_native_does_not_own_onboarding(): void {
		$controller = new WooPaymentsController();
		$controller->init( $this->getMockBuilder( Payments::class )->getMock(), $this->sut );
		update_option(
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		set_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		$_GET['wcpay-connection-success'] = '1';

		$this->run_woopayments_controller_admin_init( $controller );

		$this->assertSame( 'no', get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['platform_checkout'] );
		$this->assertTrue( (bool) get_transient( self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT ) );
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
			'option_' . WooPaymentsSettingsService::SETTINGS_OPTION,
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
		remove_all_filters( 'option_' . WooPaymentsSettingsService::SETTINGS_OPTION );
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
	 * @testdox A referral link stores the normalized code, records the referral event and continues to onboarding.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` and
	 * `WC_Payments_Onboarding_Service::normalize_and_store_referral_code()` (50 chars, lowercased, 30 days).
	 */
	public function test_handle_onboarding_referral_stores_code_records_event_and_continues_to_onboarding(): void {
		$this->mock_woopayments_plugin_inactive();
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

		$referer                 = admin_url( 'admin.php?page=wc-admin' );
		$_SERVER['HTTP_REFERER'] = $referer;

		$url = $this->sut->handle_onboarding_referral( str_repeat( 'Ab', 30 ) );
		unset( $_SERVER['HTTP_REFERER'] );

		$this->assertSame( str_repeat( 'ab', 25 ), get_transient( 'woopayments_referral_code' ) );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) get_option( '_transient_timeout_woopayments_referral_code' ), 60, 'The code is kept for 30 days.' );
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding&from=REFERRAL' ), $url );
		$this->assertArrayHasKey( 'wcadmin_wcpay_account_referral', $recorded );
		$this->assertSame( str_repeat( 'ab', 25 ), $recorded['wcadmin_wcpay_account_referral']['referral_code'] );
		$this->assertSame( $referer, $recorded['wcadmin_wcpay_account_referral']['referrer'], 'The event records wp_get_referer() as `referrer`.' );
	}

	/**
	 * @testdox A referral link skips storing the code when the account is valid, the code is empty, or the plugin owns the flow.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` lines 897-911.
	 */
	public function test_handle_onboarding_referral_does_not_store_code_outside_native_onboarding(): void {
		$this->assertSame( '', $this->sut->handle_onboarding_referral( 'partner-abc' ), 'The active plugin handles its own referral links.' );

		$this->mock_woopayments_plugin_inactive();
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding' ), $this->sut->handle_onboarding_referral( '' ) );
		$this->assertFalse( get_transient( 'woopayments_referral_code' ) );

		$this->init_sut( $this->mock_providers, $this->mockable_proxy, $this->create_native_finalize_adapter(), $this->create_legacy_runtime(), $this->create_unavailable_api_client(), $this->create_native_account_service() );
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview' ), $this->sut->handle_onboarding_referral( 'partner-abc' ) );
		$this->assertFalse( get_transient( 'woopayments_referral_code' ) );
	}

	/**
	 * Mock the WooPayments plugin runtime as not loaded, so native owns onboarding.
	 */
	private function mock_woopayments_plugin_inactive(): void {
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					return ! $this->is_woopayments_class( $class_to_check ) && class_exists( (string) $class_to_check );
				},
			)
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
		$first_settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );
		$this->sut->finish_onboarding_kyc_session( 'US' );
		$second_settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
	}

	/**
	 * Finalize and later account refresh outcomes, with or without a heal marker left by an earlier session.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>|\Throwable>,1:bool}>
	 */
	public function provide_finalize_refresh_outcomes(): array {
		$account = $this->get_native_finalize_projection_account();

		return array(
			'finalize refresh succeeds'                 => array( array( $account, $account ), false ),
			'finalize refresh fails'                    => array( array( new \RuntimeException( 'Temporary account refresh failure.' ), $account ), false ),
			'earlier session left a heal marker'        => array( array( $account, $account ), true ),
			'earlier marker and finalize refresh fails' => array( array( new \RuntimeException( 'Temporary account refresh failure.' ), $account ), true ),
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
	 * @param bool                                      $stale_marker      Whether an earlier session left a heal marker.
	 */
	public function test_merchant_change_after_session_creation_survives_finalize_and_refresh( array $account_responses, bool $stale_marker ): void {
		$fixture = $this->arrange_native_finalize_projection(
			$account_responses,
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		if ( $stale_marker ) {
			update_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, 'US', false );
		}

		$this->sut->get_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );
		$this->assertSame( array( 'card', 'ideal' ), $settings['upe_enabled_payment_method_ids'], 'Session creation applies the picks.' );

		// The merchant turns iDEAL off; a settings save ends in this canonical write.
		$settings['upe_enabled_payment_method_ids'] = array( 'card' );
		$this->assertTrue( wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class )->persist( $settings )['persisted'] );

		$this->assertTrue( $this->sut->finish_onboarding_kyc_session( 'US' )['success'] );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'], 'Finalization keeps the change.' );

		$fixture['account_service']->refresh_account_data();

		$this->assertSame( array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'], 'A later account refresh keeps the change.' );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
	}

	/**
	 * Whether the heal runs in the finalization refresh or in the next account refresh.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>|\Throwable>,1:bool}>
	 */
	public function provide_heal_moments(): array {
		$account = $this->get_native_finalize_projection_account();

		return array(
			'finalize refresh succeeds' => array( array( $account, $account ), true ),
			'finalize refresh fails'    => array( array( new \RuntimeException( 'Temporary account refresh failure.' ), $account ), false ),
		);
	}

	/**
	 * The one silent failure of the session-time write: persist() reports the canonical write as not persisted and session
	 * creation still succeeds. Only then are the picks applied again, at finalization or at the next account refresh.
	 *
	 * @testdox A session-time pick write that did not persist is applied at finalization or at the next account refresh.
	 * @dataProvider provide_heal_moments
	 *
	 * @param array<int,array<string,mixed>|\Throwable> $account_responses  Account refresh results in request order.
	 * @param bool                                      $healed_at_finalize Whether the finalization refresh heals the write.
	 */
	public function test_refused_session_pick_write_is_healed_at_finalize_or_next_refresh( array $account_responses, bool $healed_at_finalize ): void {
		$fixture  = $this->arrange_native_finalize_projection(
			$account_responses,
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		$attempts = $this->refuse_native_settings_writes_enabling( 'ideal', 1 );

		$session = $this->sut->get_onboarding_kyc_session( 'US' );

		$this->assertSame( 'accs_secret_finalized_native', $session['clientSecret'], 'Session creation does not surface the refused write.' );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'US', get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION ) );
		$this->assertOptionNotAutoloaded( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION );

		$this->sut->finish_onboarding_kyc_session( 'US' );
		$this->assertSame( $healed_at_finalize ? array( 'card', 'ideal' ) : array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );

		$fixture['account_service']->refresh_account_data();

		$this->assertSame( 2, $attempts->count );
		$this->assertSame( array( 'card', 'ideal' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
	}

	/**
	 * Fresh accounts where a picked iDEAL is neither active nor fee-backed.
	 *
	 * `pending` is the Stripe capability status used by client 11.1.0 tests (tests/unit/admin/test-class-wc-rest-payments-settings-controller.php:434).
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_accounts_without_active_fee_backed_ideal(): array {
		return array(
			'pending capability' => array(
				array(
					'account_id'        => 'acct_finalized_native',
					'is_live'           => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
					'capabilities'      => array(
						'card_payments'  => 'active',
						'ideal_payments' => 'pending',
					),
					'fees'              => array(
						'card'  => array(),
						'ideal' => array(),
					),
				),
			),
			'no fee entry'       => array(
				array(
					'account_id'        => 'acct_finalized_native',
					'is_live'           => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
					'capabilities'      => array(
						'card_payments'  => 'active',
						'ideal_payments' => 'active',
					),
					'fees'              => array( 'card' => array() ),
				),
			),
		);
	}

	/**
	 * Client 11.1.0 update_enabled_payment_methods_ids() (includes/class-wc-payments-onboarding-service.php:1515-1537) enables
	 * every pick without checking capabilities or fees, so the heal of a refused session-time write does not check them either.
	 *
	 * @testdox The heal of a refused session-time pick write enables a picked method that is not active and fee-backed, like client 11.1.0.
	 * @dataProvider provide_accounts_without_active_fee_backed_ideal
	 *
	 * @param array<string,mixed> $fresh_account Fresh account data.
	 */
	public function test_heal_enables_picked_methods_without_capability_or_fee_check( array $fresh_account ): void {
		$this->arrange_native_finalize_projection(
			array( $fresh_account ),
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		$this->refuse_native_settings_writes_enabling( 'ideal', 1 );
		$this->sut->get_onboarding_kyc_session( 'US' );

		$this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertSame( array( 'card', 'ideal' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_ideal_settings' )['enabled'] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
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
		$this->assertSame( array( 'card', 'ideal', 'link' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
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
		set_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods', array( 'payment_methods' => array( array( 'id' => 'card' ) ) ), DAY_IN_SECONDS );
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
		$this->assertFalse( get_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' ) );
		$this->assertFalse( get_option( 'wcpay_onboarding_fields_data' ) );
	}

	/**
	 * @testdox A completed native hosted KYC return drops the cached recommended payment methods, like client 11.1.0 finalize_connection().
	 */
	public function test_finalize_native_hosted_kyc_connection_clears_recommended_payment_methods_cache(): void {
		set_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods', array( 'payment_methods' => array( array( 'id' => 'card' ) ) ), DAY_IN_SECONDS );

		$this->sut->finalize_native_hosted_kyc_connection( false, true );

		// Client 11.1.0 class-wc-payments-account.php:2428 runs cleanup_on_account_onboarded() on a completed hosted return.
		$this->assertFalse( get_transient( 'woocommerce_woocommerce_payments_recommended_payment_methods' ) );
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
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
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
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
		update_option( WooPaymentsSettingsService::SETTINGS_OPTION, array( 'enabled' => 'no' ) );
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
				'pre_update_option_' . WooPaymentsSettingsService::SETTINGS_OPTION,
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
			$this->assertStringContainsString( WooPaymentsSettingsService::SETTINGS_OPTION, (string) wp_json_encode( $logger->contexts ) );
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
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertSame( 'yes', get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['platform_checkout'] );
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
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

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
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'platform_checkout'              => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$response = $this->sut->finish_onboarding_kyc_session( 'US' );
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
		$this->assertFalse( get_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT ) );
		$this->assertSame( array( 'card', 'link' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'no', $settings['platform_checkout'] );

		$fixture['account_service']->refresh_account_data();

		$this->assertSame( array( 'card', 'link' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
	}

	/**
	 * @testdox A refused session-time pick write is logged when no heal marker can be stored either.
	 */
	public function test_refused_session_pick_write_is_logged_without_durable_marker(): void {
		$logger = RecordingWcLogger::install();
		$this->arrange_native_finalize_projection(
			array( $this->get_native_finalize_projection_account() ),
			array(
				'card'  => true,
				'ideal' => true,
			)
		);
		$this->mock_native_projection_marker_write_failure();
		$this->refuse_native_settings_writes_enabling( 'ideal' );

		$this->sut->get_onboarding_kyc_session( 'US' );
		$response = $this->sut->finish_onboarding_kyc_session( 'US' );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $logger->get_errors() );
		$this->assertStringContainsString( 'could not save the payment methods picked in onboarding', $logger->get_errors()[0][1] );
		$this->assertSame( 'woopayments', $logger->get_errors()[0][2] );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
		$this->assertSame( array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
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
		$this->assertSame( array( 'card' ), get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_ideal_settings', false ) );
		$this->assertFalse( get_option( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION, false ) );
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
			'pre_update_option_' . WooPaymentsSettingsService::SETTINGS_OPTION,
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
					'get_overview_page_url',
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
		$adapter->method( 'get_overview_page_url' )->willReturn( 'https://example.com/native-overview' );

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
			$this->create_legacy_runtime(),
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
			$this->create_legacy_runtime(),
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
	 * @testdox Onboarding actions use the legacy endpoint when native onboarding is unavailable.
	 */
	public function test_onboarding_test_account_init_uses_legacy_endpoint_when_native_onboarding_is_unavailable(): void {
		$native_call_count = 0;
		$legacy_endpoint   = null;
		$api_client        = new class( $native_call_count ) extends WooPaymentsApiClient {
			/**
			 * Native initialize call count.
			 *
			 * @var int
			 */
			private int $native_call_count;

			/**
			 * Constructor.
			 *
			 * @param int $native_call_count Native initialize call count.
			 */
			public function __construct( int &$native_call_count ) {
				$this->native_call_count = &$native_call_count;
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
			 * Count unexpected native onboarding init calls.
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
				unset( $live_account, $return_url, $site_data, $user_data, $account_data, $actioned_notes, $collect_payout_requirements, $referral_code );
				++$this->native_call_count;

				return array(
					'success' => true,
					'url'     => false,
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
		$adapter->method( 'is_native_onboarding_available' )->willReturn( false );
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
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) use ( &$legacy_endpoint ) {
						$legacy_endpoint = $endpoint;

						return array(
							'success' => true,
						);
					},
				),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_legacy_runtime(),
			$api_client,
			$this->create_native_account_service()
		);

		$result = $this->sut->onboarding_test_account_init( 'US' );

		$this->assertSame( array( 'success' => true ), $result );
		$this->assertSame( 0, $native_call_count );
		$this->assertSame( '/wc/v3/payments/onboarding/test_drive_account/init', $legacy_endpoint );
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
			WooPaymentsSettingsService::SETTINGS_OPTION,
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
			$this->create_legacy_runtime(),
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
			$this->create_legacy_runtime(),
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
		$account_service->init( $this->mockable_proxy );
		$this->init_adapter( $adapter, $this->create_legacy_runtime(), $provider, new NativeWooPaymentsGateway(), $account_service, wc_get_container()->get( NativePaymentsRuntimeArbiter::class ) );

		return $adapter;
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
	 * Mock both WooPayments runtime paths as unavailable.
	 */
	private function mock_woopayments_runtime_unavailable(): void {
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					if ( $this->is_woopayments_class( $class_to_check ) ) {
						return false;
					}

					return true;
				},
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$this->create_onboarding_adapter( false ),
			$this->create_legacy_runtime(),
			$this->create_unavailable_api_client(),
			$this->create_native_account_service()
		);
	}

	/**
	 * Arrange a connected store with the plugin runtime absent, so onboarding details read the KYC fields natively.
	 *
	 * @param WooPaymentsApiClient $api_client Native API client.
	 */
	private function arrange_native_fields_onboarding( WooPaymentsApiClient $api_client ): void {
		$adapter = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'is_onboarding_runtime_available',
					'get_payment_gateway',
					'has_account',
					'has_valid_account',
					'has_working_account',
					'has_test_account',
					'has_sandbox_account',
					'has_live_account',
					'get_onboarding_kyc_fallback_url',
					'get_overview_page_url',
				)
			)
			->getMock();

		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		$adapter->method( 'has_account' )->willReturn( false );
		$adapter->method( 'has_valid_account' )->willReturn( false );
		$adapter->method( 'has_working_account' )->willReturn( false );
		$adapter->method( 'has_test_account' )->willReturn( false );
		$adapter->method( 'has_sandbox_account' )->willReturn( false );
		$adapter->method( 'has_live_account' )->willReturn( false );
		$adapter->method( 'get_onboarding_kyc_fallback_url' )->willReturn( 'https://example.com/native-kyc' );
		$adapter->method( 'get_overview_page_url' )->willReturn( 'https://example.com/native-overview' );

		$this->mock_provider->method( 'is_onboarding_supported' )->willReturn( true );
		$this->mock_provider->method( 'is_onboarding_started' )->willReturn( false );
		$this->mock_provider->method( 'is_onboarding_completed' )->willReturn( false );
		$this->mock_provider->method( 'is_in_test_mode_onboarding' )->willReturn( true );
		$this->mock_provider->method( 'is_in_dev_mode' )->willReturn( false );
		$this->mock_provider->method( 'get_recommended_payment_methods' )->willReturn( array() );

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'is_connection_owner' )->willReturn( true );
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function () {
					return false;
				},
			)
		);

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'wc_payments_settings_url'           => function (): string {
						return 'https://example.com/payments-settings';
					},
					'get_wpcom_connection_authorization' => function (): array {
						return array(
							'success'      => true,
							'errors'       => array(),
							'color_scheme' => 'fresh',
							'url'          => 'https://wordpress.com/auth?query=some_query',
						);
					},
					'rest_endpoint_get_request'          => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/fields' === $endpoint ) {
							return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.' );
						}

						throw new \Exception( esc_html( 'GET endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_legacy_runtime(),
			$api_client,
			$this->create_native_account_service_with_api_client( $api_client )
		);
	}

	/**
	 * Create a native account service that uses the given native API client.
	 *
	 * @param WooPaymentsApiClient $api_client Native API client.
	 * @return WooPaymentsAccountService
	 */
	private function create_native_account_service_with_api_client( WooPaymentsApiClient $api_client ): WooPaymentsAccountService {
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
			 * Get the native API client.
			 *
			 * @return WooPaymentsApiClient
			 */
			protected function get_api_client(): ?WooPaymentsApiClient {
				return $this->api_client;
			}
		};
		$account_service->init( $this->mockable_proxy );

		return $account_service;
	}

	/**
	 * Create a native account service wired to this test's mockable legacy proxy.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_native_account_service(): WooPaymentsAccountService {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( $this->mockable_proxy );

		return $account_service;
	}

	/**
	 * Create a WooPayments legacy runtime wired to this test's mockable proxy.
	 *
	 * @return WooPaymentsLegacyRuntime
	 */
	private function create_legacy_runtime(): WooPaymentsLegacyRuntime {
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( $this->mockable_proxy );

		return $legacy_runtime;
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
	 * Tell whether a class check targets the WooPayments extension class.
	 *
	 * @param mixed $class_to_check Class name passed to class_exists().
	 * @return bool
	 */
	private function is_woopayments_class( $class_to_check ): bool {
		return 'WC_Payments' === ltrim( (string) $class_to_check, '\\' );
	}

	/**
	 * Find an onboarding step by ID.
	 *
	 * @param array[] $steps   Onboarding steps.
	 * @param string  $step_id Step ID.
	 * @return array
	 */
	private function find_onboarding_step( array $steps, string $step_id ): array {
		foreach ( $steps as $step ) {
			if ( isset( $step['id'] ) && $step_id === $step['id'] ) {
				return $step;
			}
		}

		$this->fail( 'Expected onboarding step not found: ' . $step_id );
	}

	/**
	 * @testdox Should resolve no native collaborator when initialized.
	 */
	public function test_init_resolves_no_native_collaborator(): void {
		$sut = new WooPaymentsService();
		$sut->init( $this->mock_providers, $this->mockable_proxy );

		foreach ( array( 'onboarding_adapter', 'legacy_runtime', 'api_client', 'account_service' ) as $property ) {
			$reflection = new \ReflectionProperty( WooPaymentsService::class, $property );
			$reflection->setAccessible( true );
			$this->assertNull( $reflection->getValue( $sut ), $property . ' must be resolved on first use, not when the service is initialized.' );
		}
	}

	/**
	 * @testdox Should centralize legacy WooPayments runtime access.
	 */
	public function test_legacy_runtime_access_is_centralized(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for admin-service boundary regression coverage.
		$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php' );

		$this->assertStringNotContainsString( "class_exists( 'WC_Payments_Onboarding_Service' )", $source, 'WooPaymentsService should reset onboarding mode through WooPaymentsLegacyRuntime.' );
		$this->assertStringNotContainsString( "class_exists( '\\WC_Payments_Utils' )", $source, 'WooPaymentsService should read supported countries through WooPaymentsLegacyRuntime.' );
		$this->assertStringNotContainsString( '\\WC_Payments_Utils::supported_countries', $source, 'WooPaymentsService should not call WooPayments utility statics directly.' );
	}

	/**
	 * Test get onboarding details when the extension is NOT at the right version.
	 */
	public function test_get_onboarding_details_throws_when_extension_at_wrong_version(): void {
		// Arrange.
		$location = 'US';

		// Arrange the version constant to NOT meet the minimum requirements for the native in-context onboarding.
		Constants::set_constant( 'WCPAY_VERSION_NUMBER', '1.0.0' );

		// Act.
		try {
			$this->sut->get_onboarding_details( $location, '/some/path' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_extension_version', $e->getErrorCode() );
		}
	}

	/**
	 * Test get onboarding details when the extension is active but the onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception If a request is not mocked.
	 */
	public function test_get_onboarding_details_throws_with_onboarding_locked(): void {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						// Return a current timestamp to simulate locked state.
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		// Act.
		try {
			$this->sut->get_onboarding_details( $location, '/some/path' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test get onboarding details works when onboarding lock has expired.
	 */
	public function test_get_onboarding_details_with_expired_onboarding_lock(): void {
		$location = 'US';

		// Arrange an expired onboarding lock (older than TTL).
		$expired_timestamp = $this->current_time - WooPaymentsService::NOX_ONBOARDING_LOCKED_TTL_SECONDS - 10;
		$clears            = 0;
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $expired_timestamp ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $expired_timestamp;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$clears ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name && 0 === $value ) {
						$clears++;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		// Assert that the method works (doesn't throw exception) because the lock is expired.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $clears, 'Expired onboarding lock should be cleared (self-healed).' );
	}

	/**
	 * Test get onboarding details with non-numeric lock value.
	 */
	public function test_get_onboarding_details_with_invalid_lock_value(): void {
		$location = 'US';

		// Arrange an invalid (non-numeric) onboarding lock value.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return 'invalid_value';
					}

					return $default_value;
				},
			)
		);

		// Act.
		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		// Assert that the method works (doesn't throw exception) because invalid lock is treated as unlocked.
		$this->assertIsArray( $result );
	}

	/**
	 * Test get onboarding details with lock at the TTL boundary.
	 */
	public function test_get_onboarding_details_with_lock_at_ttl_boundary(): void {
		$location    = 'US';
		$boundary_ts = $this->current_time - WooPaymentsService::NOX_ONBOARDING_LOCKED_TTL_SECONDS;

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $boundary_ts ) {
					return WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ? $boundary_ts : $default_value;
				},
			)
		);

		try {
			$this->sut->get_onboarding_details( $location, '/some/path' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test get onboarding details with a future lock timestamp.
	 */
	public function test_get_onboarding_details_with_future_lock_timestamp(): void {
		$location  = 'US';
		$future_ts = $this->current_time + 10;

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $future_ts ) {
					return WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ? $future_ts : $default_value;
				},
			)
		);

		try {
			$this->sut->get_onboarding_details( $location, '/some/path' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test get onboarding details with zero string lock value.
	 */
	public function test_get_onboarding_details_with_zero_string_lock_value(): void {
		$location = 'US';

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					return WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ? '0' : $default_value;
				},
			)
		);

		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get onboarding details with legacy `no` string lock value.
	 *
	 * In this case, the onboarding is considered unlocked.
	 */
	public function test_get_onboarding_details_with_no_string_lock_value(): void {
		$location = 'US';

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					return WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ? 'no' : $default_value;
				},
			)
		);

		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get onboarding details with legacy `yes` string lock value.
	 *
	 * In this case, the onboarding is considered unlocked.
	 */
	public function test_get_onboarding_details_with_yes_string_lock_value(): void {
		$location = 'US';

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					return WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ? 'yes' : $default_value;
				},
			)
		);

		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get onboarding details - general state.
	 */
	public function test_get_onboarding_details_general_state(): void {
		$location = 'US';

		// Arrange.
		$expected_state = array(
			'supported' => true,
			'started'   => true,
			'completed' => false,
			'test_mode' => true,
			'dev_mode'  => false,
		);
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_supported' )
			->with( $this->anything(), $location )
			->willReturn( $expected_state['supported'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_started' )
			->willReturn( $expected_state['started'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_completed' )
			->willReturn( $expected_state['completed'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_in_test_mode_onboarding' )
			->willReturn( $expected_state['test_mode'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_in_dev_mode' )
			->willReturn( $expected_state['dev_mode'] );
		$this->mock_provider
			->expects( $this->never() )
			->method( 'get_onboarding_not_supported_message' );

		// Act.
		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'state', $result );
		$this->assertSame( $expected_state, $result['state'] );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'not_supported', $result['messages'] );
		$this->assertNull( $result['messages']['not_supported'] );
		$this->assertArrayHasKey( 'context', $result );
		$this->assertStringContainsString(
			'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
			rawurldecode( $result['context']['urls']['overview_page'] )
		);
	}

	/**
	 * Test get onboarding details - general state when not supported.
	 */
	public function test_get_onboarding_details_general_state_not_supported(): void {
		$location                       = 'XX';
		$expected_not_supported_message = 'WooPayments is not supported in this location.';

		// Arrange.
		$expected_state = array(
			'supported' => false,
			'started'   => false,
			'completed' => false,
			'test_mode' => false,
			'dev_mode'  => false,
		);
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_supported' )
			->with( $this->anything(), $location )
			->willReturn( $expected_state['supported'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_started' )
			->willReturn( $expected_state['started'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_onboarding_completed' )
			->willReturn( $expected_state['completed'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_in_test_mode_onboarding' )
			->willReturn( $expected_state['test_mode'] );
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'is_in_dev_mode' )
			->willReturn( $expected_state['dev_mode'] );
		$this->mock_provider
			->expects( $this->once() )
			->method( 'get_onboarding_not_supported_message' )
			->willReturn( $expected_not_supported_message );

		// Act.
		$result = $this->sut->get_onboarding_details( $location, '/some/path' );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'state', $result );
		$this->assertSame( $expected_state, $result['state'] );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertSame(
			array(
				'not_supported' => $expected_not_supported_message,
			),
			$result['messages']
		);
		$this->assertArrayHasKey( 'context', $result );
		$this->assertStringContainsString(
			'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
			rawurldecode( $result['context']['urls']['overview_page'] )
		);
	}

	/**
	 * Test get onboarding details - steps.
	 *
	 * @dataProvider provider_get_onboarding_details_steps
	 *
	 * @param array $expected_step_statuses          The expected step statuses.
	 * @param array $steps_stored_profile            The data stored in the profile for the steps.
	 * @param array $recommended_pms                 The recommended payment methods.
	 * @param array $expected_pms_state              The expected payment methods state.
	 * @param array $wpcom_connection                The WPCOM connection state.
	 * @param array $expected_wpcom_connection_state The expected WPCOM connection state.
	 * @param array $account_state                   The account state.
	 *
	 * @return void
	 * @throws \Exception If a request is not mocked.
	 */
	public function test_get_onboarding_details_steps(
		array $expected_step_statuses,
		array $steps_stored_profile,
		array $recommended_pms,
		array $expected_pms_state,
		array $wpcom_connection,
		array $expected_wpcom_connection_state,
		array $account_state
	): void {
		$location = 'US';

		// Arrange.
		$rest_path            = '/rest/path/to/onboarding/';
		$raw_kyc_fallback_url = 'https://example.com/kyc_fallback';
		$kyc_fallback_url     = add_query_arg( 'wcpay-connection-error', '1', $raw_kyc_fallback_url );

		$wpcom_connection_return_url = 'https://example.com/payments-settings/return?wpcom_connection_return=1';

		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => $steps_stored_profile,
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						// Chain the responses to simulate the sequence of DB updates.
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;

						// Mimic the behavior of the original function.
						$previous = empty( $updated_stored_profiles ) ? $stored_profile : end( $updated_stored_profiles );
						if ( $value === $previous || maybe_serialize( $value ) === maybe_serialize( $previous ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		$expected_steps = array(
			// The payment methods step.
			array(
				'id'             => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				'path'           => trailingslashit( WooPaymentsService::ONBOARDING_PATH_BASE ) . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				'required_steps' => array(),
				'status'         => $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS ] ?? WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				'errors'         => array(),
				'actions'        => array(
					'start'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS . '/start' ),
					),
					'save'   => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS . '/save' ),
					),
					'finish' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS . '/finish' ),
					),
					'check'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS . '/check' ),
					),
					'clean'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS . '/clean' ),
					),
				),
				'context'        => array(
					'recommended_pms' => $recommended_pms,
					'pms_state'       => $expected_pms_state,
				),
			),
			// The WPCOM connection step.
			array(
				'id'             => WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				'path'           => trailingslashit( WooPaymentsService::ONBOARDING_PATH_BASE ) . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				'required_steps' => array(),
				'status'         => $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION ] ?? WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				'errors'         => array(),
				'context'        => array(
					'connection_state' => $expected_wpcom_connection_state,
				),
				'actions'        => array(
					'start' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION . '/start' ),
					),
					'auth'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REDIRECT,
						'href' => 'https://wordpress.com/auth?query=some_query',
					),
					'check' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION . '/check' ),
					),
					'clean' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION . '/clean' ),
					),
				),
			),
			// The test account step.
			array(
				'id'             => WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				'path'           => trailingslashit( WooPaymentsService::ONBOARDING_PATH_BASE ) . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				'required_steps' => array( WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION ),
				'status'         => $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ] ?? WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				'errors'         => array(),
				'context'        => array(),
				'actions'        => array(
					'start'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/start' ),
					),
					'init'   => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/init' ),
					),
					'finish' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/finish' ),
					),
					'check'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/check' ),
					),
					'clean'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/clean' ),
					),
					'reset'  => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/reset' ),
					),
				),
			),
			// The business verification step.
			array(
				'id'             => WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				'path'           => trailingslashit( WooPaymentsService::ONBOARDING_PATH_BASE ) . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				'required_steps' => array( WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION ),
				'status'         => $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION ] ?? WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				'errors'         => array(),
				'context'        => array(
					// Only with a working WPCOM connection do we include the fields.
					'fields'              => ( $wpcom_connection['is_store_connected'] && $wpcom_connection['has_connected_owner'] ) ? array(
						'business_types'      => $this->get_mock_onboarding_fields_business_types(),
						'mccs_display_tree'   => array(
							array(
								'id'    => 'most_popular',
								'type'  => 'group',
								'title' => 'Most popular',
								'items' => array(
									array(
										'id'       => 'most_popular__software_services',
										'type'     => 'mcc',
										'title'    => 'Software',
										'mcc'      => 7372,
										'keywords' => array(),
									),
									array(
										'id'       => 'most_popular__clothing_and_apparel',
										'type'     => 'mcc',
										'title'    => 'Clothing and accessories',
										'mcc'      => 5699,
										'keywords' => array(),
									),
								),
							),
							array(
								'id'    => 'food_and_drink',
								'type'  => 'group',
								'title' => 'Food and drink',
								'items' => array(
									array(
										'id'       => 'food_and_drink__other_food_and_dining',
										'type'     => 'mcc',
										'title'    => 'Other food and dining',
										'mcc'      => 5819,
										'keywords' => array(),
									),
								),
							),
							array(
								'id'    => 'home_furnishings_and_furniture',
								'type'  => 'group',
								'title' => 'Home, furniture and garden',
								'items' => array(
									array(
										'id'       => 'home_furnishings_and_furniture__other_home_furnishings_and_furniture',
										'type'     => 'mcc',
										'title'    => 'Other home furnishings and furniture',
										'mcc'      => 5719,
										'keywords' => array(),
									),
								),
							),
						),
						'industry_to_mcc'     => array(
							'clothing_and_accessories'  => 'retail__clothing_and_apparel',
							'health_and_beauty'         => 'retail__beauty_products',
							'food_and_drink'            => 'food_and_drink__other_food_and_dining',
							'home_furniture_and_garden' => 'retail__home_furnishings_and_furniture',
							'education_and_learning'    => 'education__other_educational_services',
							'electronics_and_computers' => 'digital_products__other_digital_goods',
						),
						'available_countries' => $this->get_woopayments_supported_countries(),
						'location'            => $location,
						'__locale'            => 'en_US',
					) : array(),
					'sub_steps'           => $steps_stored_profile[ WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION ]['sub_steps'] ?? array(),
					'self_assessment'     => $steps_stored_profile[ WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION ]['self_assessment'] ?? array(),
					'has_test_account'    => $account_state['has_account'] && $account_state['test_account'],
					'has_sandbox_account' => $account_state['has_account'] && $account_state['sandbox_account'],
				),
				'actions'        => array(
					'start'                => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/start' ),
					),
					'save'                 => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/save' ),
					),
					'kyc_session'          => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session' ),
					),
					'kyc_session_finish'   => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/kyc_session/finish' ),
					),
					'kyc_fallback'         => array(
						'type' => WooPaymentsService::ACTION_TYPE_REDIRECT,
						'href' => $kyc_fallback_url,
					),
					'finish'               => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/finish' ),
					),
					'check'                => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/check' ),
					),
					'clean'                => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/clean' ),
					),
					'test_account_disable' => array(
						'type' => WooPaymentsService::ACTION_TYPE_REST,
						'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/test_account/disable' ),
					),
				),
			),
		);

		// Arrange the payment methods step.
		$this->mock_provider
			->expects( $this->atLeastOnce() )
			->method( 'get_recommended_payment_methods' )
			->willReturn( $recommended_pms );

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( $wpcom_connection['is_store_connected'] );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( $wpcom_connection['has_connected_owner'] );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connection_owner' )
			->willReturn( $wpcom_connection['is_connection_owner'] );
		// If the status is completed, we expect only the general actions.
		if ( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED === $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION ] ) {
			$expected_steps[1]['actions'] = array(
				'check' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION . '/check' ),
				),
				'clean' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION . '/clean' ),
				),
			);
		}

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( $account_state['has_account'] );

		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( $account_state['has_valid_account'] );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => $account_state['test_account'] ?? false,
					'isLive'           => ! ( ( $account_state['test_account'] ?? false ) || ( $account_state['sandbox_account'] ?? false ) ),
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);

		// Arrange the test account step.
		// If the status is completed, we expect only the general actions.
		if ( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED === $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ] ) {
			$expected_steps[2]['actions'] = array(
				'check' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/check' ),
				),
				'clean' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/clean' ),
				),
				'reset' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT . '/reset' ),
				),
			);
		}

		// Arrange the business verification step.
		// If the status is completed, we expect only the general actions.
		if ( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED === $expected_step_statuses[ WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION ] ) {
			$expected_steps[3]['actions'] = array(
				'check' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/check' ),
				),
				'clean' => array(
					'type' => WooPaymentsService::ACTION_TYPE_REST,
					'href' => rest_url( $rest_path . 'step/' . WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION . '/clean' ),
				),
			);
		}

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class          => array(
					'wc_payments_settings_url'           => function ( ?string $path = null, array $query = array() ) use ( $wpcom_connection_return_url ) {
						if ( WooPaymentsService::ONBOARDING_PATH_BASE === $path && ! empty( $query['wpcom_connection_return'] ) ) {
							return $wpcom_connection_return_url;
						}

						return 'https://example.com/payments-settings';
					},
					'get_wpcom_connection_authorization' => function ( string $return_url ) use ( $expected_steps, $wpcom_connection_return_url ) {
						$result = array(
							'success'      => true,
							'errors'       => array(),
							'color_scheme' => 'fresh',
							'url'          => 'https://wordpress.com/auth?query=some_query',
						);

						if ( $wpcom_connection_return_url === $return_url && ! empty( $expected_steps[1]['actions']['auth']['href'] ) ) {
							$result['url'] = $expected_steps[1]['actions']['auth']['href'];
						}

						return $result;
					},
					'rest_endpoint_get_request'          => function ( string $endpoint ) use ( $expected_steps ) {
						if ( '/wc/v3/payments/onboarding/fields' === $endpoint ) {
							return array(
								'data' => $expected_steps[3]['context']['fields'],
							);
						}

						throw new \Exception( esc_html( 'GET endpoint response is not mocked: ' . $endpoint ) );
					},
				),
				'WC_Payments_Account' => array(
					'get_connect_url' => function () use ( $raw_kyc_fallback_url ) {
						return $raw_kyc_fallback_url;
					},
				),
			)
		);

		// Act.
		$result = $this->sut->get_onboarding_details( $location, $rest_path );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( count( $expected_steps ), $result['steps'] );
		$this->assertEquals( $expected_steps, $result['steps'] );
	}

	/**
	 * Data provider for test_get_onboarding_details_steps.
	 *
	 * @return array[]
	 */
	public function provider_get_onboarding_details_steps(): array {
		// Can't use the $this->current_time because providers are run before setUp.
		// Use the same value as in setUp().
		$current_time = self::TEST_EPOCH;

		$default_recommended_pms = array(
			array(
				'id'       => 'card',
				'enabled'  => false,
				'required' => true,
			),
			array(
				'id'       => 'apple_pay',
				'enabled'  => true,
				'required' => false,
			),
			array(
				'id'       => 'google_pay',
				'enabled'  => false,
				'required' => false,
			),
		);
		$expected_pms_state      = array(
			// Force enabled because it is required.
			'card'         => true,
			'apple_google' => true,
		);

		$default_wpcom_connection        = array(
			'is_store_connected'  => false,
			'has_connected_owner' => false,
			'is_connection_owner' => false,
		);
		$expected_wpcom_connection_state = array(
			'has_working_connection' => false,
			'is_store_connected'     => false,
			'has_connected_owner'    => false,
			'is_connection_owner'    => false,
		);

		$default_account_state = array(
			'has_account'       => false,
			'has_valid_account' => false,
			'test_account'      => false,
			'sandbox_account'   => false,
		);

		return array(
			'clean slate'             => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				// No profile steps stored details.
				array(),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses (started) - no WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses (completed) - no WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses (failed) - no WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					// The failed stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The failed stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses (failed) - working WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				$default_account_state,
			),
			'stored statuses (failed) - working WPCOM connection, test account' => array(
				array(
					// The PMs step is force-completed on valid accounts.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses (failed) - working WPCOM connection, live account' => array(
				array(
					// The PMs step is force-completed on valid accounts.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses (blocked) - no WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					// The blocked stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The blocked stored status is ignored due to missing dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses (blocked) - working WPCOM connection, no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				$default_account_state,
			),
			'stored statuses (blocked) - working WPCOM connection, test account' => array(
				array(
					// The PMs step is force-completed on valid accounts.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION      => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT          => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses (blocked) - working WPCOM connection, live account' => array(
				array(
					// The PMs step is force-completed on valid accounts.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED  => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses (completed) - no WPCOM connection, test account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses (completed) - no WPCOM connection, live account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses (completed with failed) - no WPCOM connection, test account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
					// The completed and failed stored statuses are ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed and failed stored statuses are ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses (completed with blocked) - no WPCOM connection, live account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
					// The completed and blocked stored statuses are ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored and blocked statuses are ignored due to unmet dependency (completed WPCOM connection).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses (mixed)' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// Forced due to the bad WPCOM connection status (requirements).
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// Forced due to the bad WPCOM connection status (requirements).
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
					// Nothing about the WPCOM connection.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION       => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'no stored statuses - WPCOM connection: store_connected, no connected owner' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge( $default_wpcom_connection, array( 'is_store_connected' => true ) ),
				array_merge( $expected_wpcom_connection_state, array( 'is_store_connected' => true ) ),
				$default_account_state,
			),
			'no stored statuses - WPCOM connection: store_connected, connected owner' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// A working connection with auto-complete the step if no declarative status was stored.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				$default_account_state,
			),
			'stored statuses ignored - WPCOM connection: store connected, connected owner' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// A working connection will overwrite the stored status.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				$default_account_state,
			),
			'stored statuses respected - WPCOM connection missing' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The stored status is respected.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				$default_wpcom_connection,
				$expected_wpcom_connection_state,
				$default_account_state,
			),
			'stored statuses ignored - WPCOM connection broken' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The stored status is ignored.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => false,
					)
				),
				array(
					'has_working_connection' => false,
					'is_store_connected'     => true,
					'has_connected_owner'    => false,
					'is_connection_owner'    => false,
				),
				$default_account_state,
			),
			'stored statuses ignored - Test account completed with requirements met' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is ignored.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT          => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses ignored - Test account not completed due to unmet requirements' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The completed stored status is ignored due to unmet requirements.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => false,
					)
				),
				array(
					'has_working_connection' => false,
					'is_store_connected'     => true,
					'has_connected_owner'    => false,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => true,
					)
				),
			),
			'stored statuses respected - Test account started with no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is respected.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => false,
						'has_valid_account' => false,
						'test_account'      => false,
					)
				),
			),
			'stored statuses respected - Test account step with live, invalid account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is respected.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => false,
						'test_account'      => false,
					)
				),
			),
			'stored statuses respected - Test account with live, valid account' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is respected.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses ignored - Business verification completed with requirements met' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses ignored - Business verification not completed due to unmet requirements' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The completed stored status is ignored due to unmet requirements.
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
					// The completed stored status is ignored due to unmet requirements.
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => false,
					)
				),
				array(
					'has_working_connection' => false,
					'is_store_connected'     => true,
					'has_connected_owner'    => false,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'stored statuses respected - Business verification started with no account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is respected.
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => false,
						'has_valid_account' => false,
						'test_account'      => false,
					)
				),
			),
			'stored statuses ignored - Business verification with live, valid account' => array(
				array(
					// Since we have an account, this step is completed.
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The connection is required to be completed.
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION   => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					// The stored status is ignored.
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				),
				array(
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
							WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
						),
					),
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION   => array(
						'statuses' => array(
							WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time,
						),
					),
				),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
					)
				),
			),
			'no stored statuses - working WPCOM connection, sandbox account' => array(
				array(
					WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS       => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION      => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT          => WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
					WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION => WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				),
				// no stored profile steps.
				array(),
				$default_recommended_pms,
				$expected_pms_state,
				array_merge(
					$default_wpcom_connection,
					array(
						'is_store_connected'  => true,
						'has_connected_owner' => true,
					)
				),
				array(
					'has_working_connection' => true,
					'is_store_connected'     => true,
					'has_connected_owner'    => true,
					'is_connection_owner'    => false,
				),
				array_merge(
					$default_account_state,
					array(
						'has_account'       => true,
						'has_valid_account' => true,
						'test_account'      => false,
						'sandbox_account'   => true,
					)
				),
			),
		);
	}

	/**
	 * Test is_valid_onboarding_step_id.
	 *
	 * @return void
	 */
	public function test_is_valid_onboarding_step_id() {
		$valid_steps = array(
			WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
			WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
			WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
		);

		foreach ( $valid_steps as $step ) {
			$this->assertTrue( $this->sut->is_valid_onboarding_step_id( $step ) );
		}

		$this->assertFalse( $this->sut->is_valid_onboarding_step_id( 'invalid_step' ) );
	}

	/**
	 * Test get_onboarding_step_status.
	 *
	 * @dataProvider provider_get_onboarding_step_status
	 *
	 * @param string $step_id              The step ID.
	 * @param string $expected_status      The expected status.
	 * @param array  $step_stored_statuses The stored statuses for the step.
	 * @param array  $wpcom_connection     The WPCOM connection state.
	 * @param array  $account_state        The account state.
	 *
	 * @return void
	 * @throws \Exception On invalid mocking.
	 */
	public function test_get_onboarding_step_status( string $step_id, string $expected_status, array $step_stored_statuses, array $wpcom_connection, array $account_state ) {
		$location = 'US';

		// Arrange.
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => $step_stored_statuses,
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						// Chain the responses to simulate the sequence of DB updates.
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;

						// Mimic the behavior of the original function.
						$previous = empty( $updated_stored_profiles ) ? $stored_profile : end( $updated_stored_profiles );
						if ( $value === $previous || maybe_serialize( $value ) === maybe_serialize( $previous ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( $wpcom_connection['is_store_connected'] );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( $wpcom_connection['has_connected_owner'] );

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( $account_state['has_account'] );

		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( $account_state['has_valid_account'] );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => $account_state['test_account'] ?? false,
					'isLive'           => ! ( ( $account_state['test_account'] ?? false ) || ( $account_state['sandbox_account'] ?? false ) ),
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);

		// Act.
		$result = $this->sut->get_onboarding_step_status( $step_id, $location );

		// Assert.
		$this->assertSame( $expected_status, $result );
	}

	/**
	 * @testdox Serialized NOX profile strings are ignored without instantiating objects.
	 */
	public function test_get_onboarding_step_status_ignores_serialized_profile_string(): void {
		NoxProfileObjectInstantiationProbe::$was_unserialized = false;

		$serialized_profile = maybe_serialize(
			array(
				'probe' => new NoxProfileObjectInstantiationProbe(),
			)
		);

		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $serialized_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $serialized_profile;
					}

					return $default_value;
				},
			)
		);

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, 'US' );

		$this->assertSame( WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED, $status );
		$this->assertFalse( NoxProfileObjectInstantiationProbe::$was_unserialized, 'Serialized NOX profile strings must not instantiate objects.' );
	}

	/**
	 * Data provider for test_get_onboarding_step_status.
	 *
	 * @return array[]
	 */
	public function provider_get_onboarding_step_status(): array {
		$current_time = 1234567890;

		return array(
			'payment_methods - clean slate'                => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored started'             => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored completed'           => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored failed'              => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored blocked'             => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored started and completed' => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored started and failed'  => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'payment_methods - stored started and blocked' => array(
				WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - clean slate'               => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - nothing stored with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - nothing stored with partial connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started with no connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored failed with no connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored failed with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored blocked with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored completed with no connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					// This will be ignored.
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored completed with partial connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored completed with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and completed with no connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and failed with no connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and failed with partial connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and completed with partial connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started, failed, and completed with partial connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					// This is ignored.
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started, blocked, and completed with partial connection data' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					// This is ignored.
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and failed with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and blocked with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started and completed with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'wpcom_connection - stored started, failed, and completed with working connection' => array(
				WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - clean slate'                   => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - nothing stored with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - nothing stored with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - nothing stored with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'test_account - nothing stored with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - nothing stored with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored failed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored failed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored failed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and failed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored failed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and failed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored blocked with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored blocked with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored blocked with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and blocked with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored blocked with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and blocked with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				// We trust the stored status since we can be in progress with switch to live.
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored failed and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				// We trust the stored status since we can be in progress with switch to live.
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored blocked and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				// We trust the stored status since we can be in progress with switch to live.
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored failed and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored blocked and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored failed and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored blocked and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'test_account - stored failed, blocked and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'test_account - stored completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored failed, blocked, and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored failed, blocked, and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored failed and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored blocked and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored failed and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored blocked and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				// We trust the completed stored status since we can be in progress with switch to live.
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				// We trust the completed stored status since we can be in progress with switch to live.
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started, failed, and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started, failed, and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'test_account - stored started and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'test_account - stored started, failed, and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			// A marker-less completed status with an invalid test account gets the skip marker
			// backfilled on read, so the step reports completed instead of trapping the merchant.
			'test_account - stored started and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			// The backfilled skip marker takes precedence over stale failed statuses too — a trapped
			// merchant cannot fix the test account, so surfacing the failure would still dead-end them.
			'test_account - stored started, failed, and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			// Same for stale blocked statuses: resolving a blocker cannot make the invalid test
			// account recoverable, so the backfilled completion still wins.
			'test_account - stored started, blocked, and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'test_account - stored started and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started, blocked, and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started, failed, and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'test_account - stored started with valid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'test_account - stored started, failed, and completed with valid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - clean slate'          => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - nothing stored with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - nothing stored with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - nothing stored with valid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - nothing stored with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - nothing stored with invalid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - nothing stored with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - nothing stored with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - nothing stored with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - nothing stored with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started with valid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started with valid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started with invalid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started with invalid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 10,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored failed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored blocked with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored completed with valid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored completed with valid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored completed with invalid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored completed with invalid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_NOT_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started and completed with valid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started and completed with valid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started and completed with invalid sandbox account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started and completed with invalid sandbox account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
					'sandbox_account'   => true,
				),
			),
			'business_verification - stored started and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, failed, and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, failed, and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, failed, and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, failed, and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, failed, and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with no account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with no account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => false,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with valid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, blocked, and completed with valid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, blocked, and completed with invalid test account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, blocked, and completed with invalid test account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => true,
				),
			),
			'business_verification - stored started, blocked, and completed with invalid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => false,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with invalid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => false,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with valid live account, unmet requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => false,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
			'business_verification - stored started, blocked, and completed with valid live account, met requirements' => array(
				WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION,
				WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
				array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $current_time - 10,
					WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED => $current_time - 5,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $current_time,
				),
				array(
					'is_store_connected'  => true,
					'has_connected_owner' => true,
				),
				array(
					'has_account'       => true,
					'has_valid_account' => true,
					'test_account'      => false,
				),
			),
		);
	}

	/**
	 * Test mark_onboarding_step_started throws exception when extension is not active.
	 */
	public function test_mark_onboarding_step_started_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test mark_onboarding_step_started throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_mark_onboarding_step_started_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						// Return a current timestamp to simulate locked state.
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that mark_onboarding_step_started throws an exception when an invalid step ID is provided.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_started_throws_on_invalid_step_id() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step ID' );

		$this->sut->mark_onboarding_step_started( 'invalid_step_id', $location );
	}

	/**
	 * Test that mark_onboarding_step_started throws an exception when the requirements are not met for the step.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_started_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		try {
			$this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_requirements_not_met', $e->getErrorCode() );
		}
	}

	/**
	 * Test that mark_onboarding_step_started does not overwrite the existing step status if the overwrite flag is false.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_started_does_not_overwrite() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$timestamp              = $this->current_time - 100;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, false );

		// Assert.
		$this->assertTrue( $result );
		$this->assertEquals( array(), $updated_stored_profile );
	}

	/**
	 * Test that mark_onboarding_step_started overwrites the existing step status if the overwrite flag is true.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_started_overwrites() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$timestamp              = $this->current_time - 100;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, true );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED ] );
		$this->assertNotSame( $timestamp, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED ] );
	}

	/**
	 * Test that mark_onboarding_step_started stores the step status to started with the current timestamp.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_started() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile         = array();
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_started( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, true );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED ] );
		$this->assertSame( $this->current_time, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED ] );
	}

	/**
	 * Test mark_onboarding_step_completed throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_mark_onboarding_step_completed_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->mark_onboarding_step_completed( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test mark_onboarding_step_completed throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_mark_onboarding_step_completed_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						// Return a current timestamp to simulate locked state.
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->mark_onboarding_step_completed( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that mark_onboarding_step_completed throws an exception when an invalid step ID is provided.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed_throws_on_invalid_step_id() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step ID' );

		$this->sut->mark_onboarding_step_completed( 'invalid_step_id', $location );
	}

	/**
	 * Test that mark_onboarding_step_completed throws an exception when the requirements are not met for the step.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		try {
			$this->sut->mark_onboarding_step_completed( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_requirements_not_met', $e->getErrorCode() );
		}
	}

	/**
	 * Test that mark_onboarding_step_completed throws an exception when the step is blocked.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed_throws_when_blocked() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED   => $this->current_time - 200,
								WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED    => $this->current_time - 150,
								WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED   => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 10,
							),
							'data'     => array(
								'error' => array( 'some error' => 'some error' ),
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		try {
			$this->sut->mark_onboarding_step_completed( $step_id, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_blocked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that mark_onboarding_step_completed does not overwrite the existing step status if the overwrite flag is false.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed_does_not_overwrite() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$timestamp              = $this->current_time - 100;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_completed( $step_id, $location, false );

		// Assert.
		$this->assertTrue( $result );
		$this->assertEquals( array(), $updated_stored_profile );
	}

	/**
	 * Test that mark_onboarding_step_completed overwrites the existing step status if the overwrite flag is true.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed_overwrites() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$timestamp              = $this->current_time - 100;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_completed( $step_id, $location, true );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ] );
		$this->assertNotSame( $timestamp, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ] );
	}

	/**
	 * Test that mark_onboarding_step_completed stores the step status to completed with the current timestamp.
	 *
	 * @return void
	 */
	public function test_mark_onboarding_step_completed() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile         = array();
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->mark_onboarding_step_completed( $step_id, $location, true );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ] );
		$this->assertSame( $this->current_time, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'][ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ] );
	}

	/**
	 * Test clean_onboarding_step_progress throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_clean_onboarding_step_progress_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->clean_onboarding_step_progress( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test clean_onboarding_step_progress throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_clean_onboarding_step_progress_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->clean_onboarding_step_progress( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that clean_onboarding_step_progress throws an exception when an invalid step ID is provided.
	 *
	 * @return void
	 */
	public function test_clean_onboarding_step_progress_throws_on_invalid_step_id() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step ID' );

		$this->sut->clean_onboarding_step_progress( 'invalid_step_id', $location );
	}

	/**
	 * Test clean_onboarding_step_progress when the requirements are not met for the step.
	 *
	 * @return void
	 */
	public function test_clean_onboarding_step_progress_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED   => $this->current_time - 200,
								WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED    => $this->current_time - 150,
								WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED   => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 10,
							),
							'data'     => array(
								'error' => array( 'some error' => 'some error' ),
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						// Update the stored profile to the latest set value.
						$stored_profile = $value;

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->clean_onboarding_step_progress( $step_id, $location );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'] );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'] );
	}

	/**
	 * Test clean_onboarding_step_progress when the step is blocked.
	 *
	 * @return void
	 */
	public function test_clean_onboarding_step_progress_when_blocked() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED   => $this->current_time - 200,
								WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED    => $this->current_time - 150,
								WooPaymentsService::ONBOARDING_STEP_STATUS_BLOCKED   => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 10,
							),
							'data'     => array(
								'error' => array( 'some error' => 'some error' ),
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						// Update the stored profile to the latest set value.
						$stored_profile = $value;

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->clean_onboarding_step_progress( $step_id, $location );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'] );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'] );
	}

	/**
	 * Test that clean_onboarding_step_progress clears the statuses and errors.
	 *
	 * @return void
	 */
	public function test_clean_onboarding_step_progress() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 200,
								WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED => $this->current_time - 150,
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 10,
							),
							'data'     => array(
								'error' => array( 'some error' => 'some error' ),
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						// Update the stored profile to the latest set value.
						$stored_profile = $value;

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->clean_onboarding_step_progress( $step_id, $location );

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['statuses'] );
		$this->assertEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'] );
	}

	/**
	 * Test onboarding_step_save throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_step_save_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->onboarding_step_save( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, array() );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test onboarding_step_save throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_step_save_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->onboarding_step_save( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, array() );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that onboarding_step_save throws an exception when an invalid step ID is provided.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save_throws_on_invalid_step_id() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step ID' );

		$this->sut->onboarding_step_save( 'invalid_step_id', $location, array() );
	}

	/**
	 * Test that onboarding_step_save throws an exception when receiving invalid data.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save_throws_on_invalid_data() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step data.' );

		$this->sut->onboarding_step_save( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS, $location, array() );
	}

	/**
	 * Test that onboarding_step_save throws an exception when attempting to save for step that doesn't support it.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save_throws_on_step_without_support() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		try {
			$this->sut->onboarding_step_save( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location, array() );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_action_not_supported', $e->getErrorCode() );
		}
	}

	/**
	 * Test that onboarding_step_save stores the step data.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$payment_methods        = array(
			'credit_card' => true,
			'paypal'      => false,
		);
		$stored_profile         = array();
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->onboarding_step_save(
			$step_id,
			$location,
			array(
				'payment_methods' => $payment_methods,
			)
		);

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
		$this->assertEquals( $payment_methods, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
	}

	/**
	 * Test that onboarding_step_save stores the step data.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save_overwrites() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$payment_methods        = array(
			'credit_card' => true,
			'paypal'      => false,
		);
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'data' => array(
								'payment_methods' => array(
									'credit_card' => false,
									'paypal'      => true,
								),
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->onboarding_step_save(
			$step_id,
			$location,
			array(
				'payment_methods' => $payment_methods,
			)
		);

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
		$this->assertEquals( $payment_methods, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
	}

	/**
	 * Test that onboarding_step_save stores the step data with existing profile data and keeps the existing data.
	 *
	 * @return void
	 */
	public function test_onboarding_step_save_with_existing_profile_data() {
		$location = 'US';

		// Arrange.
		$step_id                = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$payment_methods        = array(
			'credit_card' => true,
			'paypal'      => false,
		);
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 100,
							),
							'data'     => array(
								'some_data' => 'some_value',
							),
						),
					),
				),
			),
		);
		$expected_profile       = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 100,
							),
							'data'     => array(
								'some_data'       => 'some_value',
								'payment_methods' => $payment_methods,
							),
						),
					),
				),
			),
		);
		$updated_stored_profile = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profile = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->onboarding_step_save(
			$step_id,
			$location,
			array(
				'payment_methods' => $payment_methods,
			)
		);

		// Assert.
		$this->assertTrue( $result );
		$this->assertNotEquals( array(), $updated_stored_profile );
		$this->assertNotEmpty( $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
		$this->assertEquals( $payment_methods, $updated_stored_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['payment_methods'] );
		$this->assertEquals( $expected_profile, $updated_stored_profile );
	}

	/**
	 * Test onboarding_step_check throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_step_check_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->onboarding_step_check( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test onboarding_step_check throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_step_check_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->onboarding_step_check( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

			$this->fail( 'Expected ApiException not thrown' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that onboarding_step_check throws an exception when an invalid step ID is provided.
	 *
	 * @return void
	 */
	public function test_onboarding_step_check_throws_on_invalid_step_id() {
		$location = 'US';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid onboarding step ID' );

		$this->sut->onboarding_step_check( 'invalid_step_id', $location );
	}

	/**
	 * Test that onboarding_step_check throws an exception when the requirements are not met for the step.
	 *
	 * @return void
	 */
	public function test_onboarding_step_check_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Onboarding step requirements are not met.' );

		$this->sut->onboarding_step_check( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
	}

	/**
	 * Test that onboarding_step_check returns the correct status for a step.
	 *
	 * @return void
	 */
	public function test_onboarding_step_check() {
		$location = 'US';

		// Arrange.
		$step_id        = WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS;
		$stored_profile = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 100,
							),
						),
					),
				),
			),
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
			)
		);

		// Act.
		$result = $this->sut->onboarding_step_check( $step_id, $location );

		// Assert.
		$this->assertEquals(
			array(
				'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED,
				'error'  => array(),
			),
			$result
		);
	}

	/**
	 * Test that get_onboarding_recommended_payment_methods returns the correct recommended payment methods.
	 *
	 * @return void
	 */
	public function test_get_onboarding_recommended_payment_methods() {
		// Arrange.
		$country_code = 'US';
		$expected     = array(
			array(
				'id'       => 'credit_card',
				'enabled'  => true,
				'required' => true,
			),
			array(
				'id'       => 'affirm',
				'enabled'  => false,
				'required' => false,
			),
		);
		$this->mock_provider
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( $this->isInstanceOf( FakePaymentGateway::class ), $country_code )
			->willReturn( $expected );

		// Act.
		$result = $this->sut->get_onboarding_recommended_payment_methods( $country_code );

		// Assert.
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test onboarding_test_account_init with an existing test account.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_onboarding_test_account_init_with_existing_test_account_throws() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			// Make it invalid, for good measure.
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					// These two are mirror images of each other.
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);

		// Arrange the REST API requests.
		$requests_made = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'A test account is already set up.' );

		// Act.
		$result = $this->sut->onboarding_test_account_init( $location );

		$this->assertCount( 0, $requests_made );
	}

	/**
	 * Test onboarding_test_account_init with an existing live account.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_onboarding_test_account_init_with_existing_live_account_throws() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			// Make it invalid, for good measure.
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					// These two are mirror images of each other.
					'testDrive'        => false,
					'isLive'           => true,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);

		// Arrange the REST API requests.
		$requests_made = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'An account is already set up. Reset the onboarding first.' );

		// Act.
		$result = $this->sut->onboarding_test_account_init( $location );

		$this->assertCount( 0, $requests_made );
	}

	/**
	 * Test onboarding_test_account_init throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_test_account_init_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->onboarding_test_account_init( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test onboarding_test_account_init throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_onboarding_test_account_init_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( false );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		try {
			$this->sut->onboarding_test_account_init( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}

		// Assert.
		$this->assertCount( 0, $requests_made );
	}

	/**
	 * Test onboarding_test_account_init that throws an exception when the step requirements are not met.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it NOT working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		try {
			$this->sut->onboarding_test_account_init( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_requirements_not_met', $e->getErrorCode() );
		}
	}

	/**
	 * Test onboarding_test_account_init that throws an exception when the REST API call fails.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_throws_on_error_response() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$step_id                 = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made  = array();
		$expected_error = array(
			'code'    => 'error',
			'message' => 'Error message',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_error ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return new WP_Error( $expected_error['code'], $expected_error['message'] );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( $expected_error['message'] );

		// Act.
		$this->sut->onboarding_test_account_init( $location );
	}

	/**
	 * @testdox Test account init skips the step forward when the initialization error is non-recoverable.
	 *
	 * @dataProvider provider_onboarding_test_account_init_non_recoverable_error_data
	 *
	 * @param array $error_data The WP_Error data attached to the initialization error.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_skips_step_on_non_recoverable_error( array $error_data ) {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the REST API request to fail with a non-recoverable error.
		// The error shape mimics what Utils::rest_endpoint_post_request() produces when the
		// WooPayments extension surfaces the underlying error details in the REST error response.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( $error_data ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error(
								'woocommerce_settings_payments_rest_error',
								"REST request POST failed with: (bad_request) The statement descriptor matches a common term or website URL, and can't be used.",
								$error_data
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_test_account_non_recoverable_error',
				$e->getErrorCode(),
				'A dedicated error code should signal the non-recoverable failure to the client.'
			);
		}

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses, 'The step should be marked completed so onboarding can proceed.' );
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED, $statuses, 'The step should not be marked failed.' );
	}

	/**
	 * Data provider for the non-recoverable initialization error shapes.
	 *
	 * The shapes mimic what Utils::rest_endpoint_post_request() produces, depending on where
	 * the WooPayments extension surfaces the error details in the REST error response.
	 *
	 * @return array<string, array<array>>
	 */
	public function provider_onboarding_test_account_init_non_recoverable_error_data(): array {
		return array(
			'error type and code in nested data' => array(
				array(
					'code'    => 'bad_request',
					'message' => "The statement descriptor matches a common term or website URL, and can't be used.",
					'data'    => array(
						'status'     => 400,
						'error_type' => 'invalid_request_error',
						'error_code' => 'invalid_request_error',
					),
				),
			),
			'error code only in nested data'     => array(
				array(
					'code'    => 'bad_request',
					'message' => "The statement descriptor matches a common term or website URL, and can't be used.",
					'data'    => array(
						'status'     => 400,
						'error_code' => 'invalid_request_error',
					),
				),
			),
			'error type only in nested data'     => array(
				array(
					'code'    => 'bad_request',
					'message' => "The statement descriptor matches a common term or website URL, and can't be used.",
					'data'    => array(
						'status'     => 400,
						'error_type' => 'invalid_request_error',
					),
				),
			),
			'error type at top level'            => array(
				array(
					'error_type' => 'invalid_request_error',
				),
			),
			'error code at top level'            => array(
				array(
					'error_code' => 'invalid_request_error',
				),
			),
		);
	}

	/**
	 * @testdox Test account init treats filter-added error identifiers as non-recoverable.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_non_recoverable_errors_are_filterable() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the REST API request to fail with an error type that is only
		// non-recoverable because the filter below adds it to the list.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error(
								'woocommerce_settings_payments_rest_error',
								'REST request POST failed with: (bad_request) Some custom platform failure.',
								array(
									'code'    => 'bad_request',
									'message' => 'Some custom platform failure.',
									'data'    => array(
										'status'     => 400,
										'error_type' => 'custom_blocker_error',
									),
								)
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		add_filter(
			'woocommerce_woopayments_onboarding_test_account_non_recoverable_errors',
			function ( array $identifiers ) {
				$identifiers[] = 'custom_blocker_error';

				return $identifiers;
			}
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_test_account_non_recoverable_error',
				$e->getErrorCode(),
				'The filter-added error identifier should be treated as non-recoverable.'
			);
		}

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses, 'The step should be marked completed so onboarding can proceed.' );
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED, $statuses, 'The step should not be marked failed.' );
	}

	/**
	 * @testdox Native test account init skips the step forward when the platform error is non-recoverable.
	 *
	 * The platform reports a Stripe error body as `{"error":{"type":"invalid_request_error","code":...}}`, which the
	 * client API client turns into an exception whose error code is the body code (or the type when no code is set)
	 * and whose error type is the body type (client 11.1.0 `includes/wc-payment-api/class-wc-payments-api-client.php:2868-2871`).
	 *
	 * @dataProvider provider_native_non_recoverable_platform_errors
	 *
	 * @param string      $error_code        Platform error code.
	 * @param string      $error_type        Platform error type.
	 * @param string|null $filtered_code     Error code a site adds through the non-recoverable errors filter.
	 */
	public function test_native_onboarding_test_account_init_skips_step_on_non_recoverable_error( string $error_code, string $error_type, ?string $filtered_code ): void {
		if ( null !== $filtered_code ) {
			add_filter(
				'woocommerce_woopayments_onboarding_test_account_non_recoverable_errors',
				static function () use ( $filtered_code ) {
					return array( $filtered_code );
				}
			);
		}
		$api_client = new class( $error_code, $error_type ) extends WooPaymentsApiClient {
			/**
			 * Platform error code.
			 *
			 * @var string
			 */
			private string $error_code;

			/**
			 * Platform error type.
			 *
			 * @var string
			 */
			private string $error_type;

			/**
			 * Constructor.
			 *
			 * @param string $error_code Platform error code.
			 * @param string $error_type Platform error type.
			 */
			public function __construct( string $error_code, string $error_type ) {
				$this->error_code = $error_code;
				$this->error_type = $error_type;
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
			 * Reject the test-drive init like the platform does for a non-recoverable request.
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
			 * @throws WooPaymentsApiException When an error type is set.
			 */
			public function initialize_onboarding( bool $live_account, string $return_url, array $site_data = array(), array $user_data = array(), array $account_data = array(), array $actioned_notes = array(), bool $collect_payout_requirements = false, ?string $referral_code = null ): array {
				unset( $live_account, $return_url, $site_data, $user_data, $account_data, $actioned_notes, $collect_payout_requirements, $referral_code );
				if ( '' !== $this->error_type ) {
					throw new WooPaymentsApiException( "The statement descriptor matches a common term or website URL, and can't be used.", esc_html( $this->error_code ), 400, esc_html( $this->error_type ) );
				}

				return array();
			}
		};
		$adapter    = $this->getMockBuilder( WooPaymentsOnboardingAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_onboarding_runtime_available', 'is_native_onboarding_available', 'get_payment_gateway', 'has_account', 'has_valid_account', 'has_working_account', 'has_test_account', 'has_sandbox_account', 'has_live_account' ) )
			->getMock();
		$adapter->method( 'is_onboarding_runtime_available' )->willReturn( true );
		$adapter->method( 'is_native_onboarding_available' )->willReturn( true );
		$adapter->method( 'get_payment_gateway' )->willReturn( new FakePaymentGateway() );
		foreach ( array( 'has_account', 'has_valid_account', 'has_working_account', 'has_test_account', 'has_sandbox_account', 'has_live_account' ) as $method ) {
			$adapter->method( $method )->willReturn( false );
		}

		$this->mock_wpcom_connection_manager->method( 'is_connected' )->willReturn( true );
		$this->mock_wpcom_connection_manager->method( 'has_connected_owner' )->willReturn( true );
		update_option(
			WooPaymentsService::NOX_PROFILE_OPTION_KEY,
			array( 'onboarding' => array( 'US' => array( 'steps' => array( WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS => array( 'data' => array( 'payment_methods' => array( 'card' => true ) ) ) ) ) ) )
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'class_exists' => function ( $class_to_check ) {
					unset( $class_to_check );
					return false;
				},
			)
		);
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		$this->sut = new WooPaymentsService();
		$this->init_sut( $this->mock_providers, $this->mockable_proxy, $adapter, $this->create_legacy_runtime(), $api_client, $this->create_native_account_service() );

		try {
			$this->sut->onboarding_test_account_init( 'US' );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_test_account_non_recoverable_error', $e->getErrorCode() );
		}

		$statuses = get_option( WooPaymentsService::NOX_PROFILE_OPTION_KEY )['onboarding']['US']['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses, 'The step should be marked completed so onboarding can proceed.' );
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED, $statuses, 'The step should not be marked failed.' );
	}

	/**
	 * Non-recoverable platform errors, by where the identifier sits.
	 *
	 * @return array<string,array{string,string,string|null}>
	 */
	public function provider_native_non_recoverable_platform_errors(): array {
		return array(
			'type only, code from the type' => array( 'invalid_request_error', 'invalid_request_error', null ),
			'type with a Stripe error code' => array( 'parameter_invalid_string', 'invalid_request_error', null ),
			'code added through the filter' => array( 'parameter_invalid_string', 'api_error', 'parameter_invalid_string' ),
		);
	}

	/**
	 * @testdox Test account init marks the step failed when the response is malformed.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_throws_on_malformed_response() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the REST API request to return a response without a `success` entry.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return array( 'status' => 'ok' );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_client_api_error',
				$e->getErrorCode(),
				'A malformed response should keep the existing failure behavior.'
			);
		}

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED, $statuses, 'The step should be marked failed on a malformed response.' );
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses, 'The step should not be marked completed.' );
	}

	/**
	 * Test that error sanitization moves extra keys to context when storing a step error.
	 *
	 * When an error has keys beyond 'code', 'message', and 'context', those extra keys
	 * should be moved into the 'context' array.
	 *
	 * @return void
	 */
	public function test_error_sanitization_moves_extra_keys_to_context() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$step_id                 = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						// Return the latest updated profile if available.
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests to return a WP_Error with extra data.
		$error_data_with_extra_keys = array(
			'details'  => 'Some additional details',
			'trace'    => 'stack trace info',
			'response' => 'raw response data',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( $error_data_with_extra_keys ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error( 'test_error', 'Test error message', $error_data_with_extra_keys );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act - call the method which will trigger mark_onboarding_step_failed.
		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \Exception $e ) {
			// Expected exception, ignore it.
			unset( $e );
		}

		// Assert - verify the stored error has extra keys moved to context.
		$this->assertNotEmpty( $updated_stored_profiles, 'Profile should have been updated' );
		$final_profile = end( $updated_stored_profiles );

		$this->assertArrayHasKey( 'onboarding', $final_profile );
		$this->assertArrayHasKey( $location, $final_profile['onboarding'] );
		$this->assertArrayHasKey( 'steps', $final_profile['onboarding'][ $location ] );
		$this->assertArrayHasKey( $step_id, $final_profile['onboarding'][ $location ]['steps'] );
		$this->assertArrayHasKey( 'data', $final_profile['onboarding'][ $location ]['steps'][ $step_id ] );
		$this->assertArrayHasKey( 'error', $final_profile['onboarding'][ $location ]['steps'][ $step_id ]['data'] );

		$stored_error = $final_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'];

		// Verify the error structure has code, message, and context at the top level.
		$this->assertArrayHasKey( 'code', $stored_error );
		$this->assertArrayHasKey( 'message', $stored_error );
		$this->assertArrayHasKey( 'context', $stored_error );
		$this->assertSame( 'test_error', $stored_error['code'] );
		$this->assertSame( 'Test error message', $stored_error['message'] );

		// Verify the extra keys were moved to context.
		$this->assertIsArray( $stored_error['context'] );
		$this->assertNotEmpty( $stored_error['context'], 'Context should contain the extra keys' );
		$this->assertArrayHasKey( 'details', $stored_error['context'] );
		$this->assertArrayHasKey( 'trace', $stored_error['context'] );
		$this->assertArrayHasKey( 'response', $stored_error['context'] );
		$this->assertSame( 'Some additional details', $stored_error['context']['details'] );
		$this->assertSame( 'stack trace info', $stored_error['context']['trace'] );
		$this->assertSame( 'raw response data', $stored_error['context']['response'] );
	}

	/**
	 * Test that error sanitization merges extra keys with existing context.
	 *
	 * When an error has both a 'context' key and extra keys, the extra keys should be
	 * merged into the context with existing context values taking precedence.
	 *
	 * @return void
	 */
	public function test_error_sanitization_merges_extra_keys_with_existing_context() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$step_id                 = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests to return a WP_Error with context containing extra keys.
		// The error_data has a 'details' key that should be moved to context,
		// and a nested 'context' with 'existing_key'.
		$error_data = array(
			'details'         => 'Extra details',
			'context'         => array(
				'existing_key'    => 'existing value',
				'conflicting_key' => 'context value',
			),
			// This should be overwritten by context value.
			'conflicting_key' => 'extra key value',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( $error_data ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error( 'test_error', 'Test error message', $error_data );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \Exception $e ) {
			// Expected exception, ignore it.
			unset( $e );
		}

		// Assert.
		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$stored_error  = $final_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'];

		// Verify the error structure has code, message, and context at the top level.
		$this->assertArrayHasKey( 'code', $stored_error );
		$this->assertArrayHasKey( 'message', $stored_error );
		$this->assertArrayHasKey( 'context', $stored_error );
		$this->assertIsArray( $stored_error['context'] );
		$this->assertNotEmpty( $stored_error['context'], 'Context should contain the merged keys' );

		// Verify conflicting_key is NOT at the top level (it should only be in context).
		$this->assertArrayNotHasKey( 'conflicting_key', $stored_error, 'Extra keys should be moved to context, not kept at top level' );

		// Verify extra key was moved to context.
		$this->assertArrayHasKey( 'details', $stored_error['context'] );
		$this->assertSame( 'Extra details', $stored_error['context']['details'] );

		// Verify existing context key is preserved.
		$this->assertArrayHasKey( 'existing_key', $stored_error['context'] );
		$this->assertSame( 'existing value', $stored_error['context']['existing_key'] );

		// Verify that the context value takes precedence over the extra key value for conflicting keys.
		$this->assertArrayHasKey( 'conflicting_key', $stored_error['context'] );
		$this->assertSame( 'context value', $stored_error['context']['conflicting_key'] );
	}

	/**
	 * Test that error sanitization flattens nested context keys.
	 *
	 * When an error's context contains a nested 'context' key (e.g., from WP_Error data),
	 * the nested context should be flattened, with nested context values taking precedence.
	 *
	 * @return void
	 */
	public function test_error_sanitization_flattens_nested_context() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$step_id                 = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests to return a WP_Error with nested context.
		// The error_data has a nested 'context' key that should be flattened.
		$error_data = array(
			'top_level_key'   => 'top value',
			// Should be overwritten by nested context value.
			'conflicting_key' => 'top level value',
			'context'         => array(
				'nested_key'      => 'nested value',
				// Takes precedence.
				'conflicting_key' => 'nested context value',
			),
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( $error_data ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error( 'test_error', 'Test error message', $error_data );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected exception was not thrown' );
		} catch ( \Exception $e ) {
			// Exception expected from the mocked WP_Error response.
			// The actual error sanitization is verified in the assertions below.
			unset( $e );
		}

		// Assert.
		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$stored_error  = $final_profile['onboarding'][ $location ]['steps'][ $step_id ]['data']['error'];

		$this->assertArrayHasKey( 'context', $stored_error );
		$this->assertIsArray( $stored_error['context'] );

		// Verify the top-level key was preserved.
		$this->assertArrayHasKey( 'top_level_key', $stored_error['context'] );
		$this->assertSame( 'top value', $stored_error['context']['top_level_key'] );

		// Verify the nested context key was flattened into the main context.
		$this->assertArrayHasKey( 'nested_key', $stored_error['context'] );
		$this->assertSame( 'nested value', $stored_error['context']['nested_key'] );

		// Verify the nested 'context' key itself is no longer present.
		$this->assertArrayNotHasKey( 'context', $stored_error['context'] );

		// Verify that nested context value takes precedence over top-level value for conflicting keys.
		$this->assertArrayHasKey( 'conflicting_key', $stored_error['context'] );
		$this->assertSame( 'nested context value', $stored_error['context']['conflicting_key'] );
	}

	/**
	 * Test that step error standardization treats an error object with reserved keys as a single error.
	 *
	 * When a step's errors field directly contains an error object (with code/message/context keys)
	 * instead of a list of errors, it should be treated as a single error and wrapped in an array.
	 *
	 * @return void
	 */
	public function test_step_error_standardization_wraps_single_error_object() {
		$location = 'US';

		// Create step details with errors as a single error object (not a list).
		// This simulates an edge case where errors might be passed incorrectly.
		$step_details = array(
			'id'     => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
			// This is a single error object, NOT a list of errors.
			// It has 'code', 'message', and 'context' keys directly.
			'errors' => array(
				'code'    => 'test_error_code',
				'message' => 'Test error message',
				'context' => array(
					'some_key' => 'some_value',
				),
			),
		);

		// Use reflection to call the private standardize_onboarding_step_details method.
		$reflection = new \ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( 'standardize_onboarding_step_details' );
		$method->setAccessible( true );

		// Act.
		$result = $method->invoke( $this->sut, $step_details, $location, '/rest/path/' );

		// Assert.
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsArray( $result['errors'] );
		$this->assertCount( 1, $result['errors'] );

		// Verify the single error was properly standardized.
		$standardized_error = $result['errors'][0];
		$this->assertArrayHasKey( 'code', $standardized_error );
		$this->assertSame( 'test_error_code', $standardized_error['code'] );
		$this->assertArrayHasKey( 'message', $standardized_error );
		$this->assertSame( 'Test error message', $standardized_error['message'] );
		$this->assertArrayHasKey( 'context', $standardized_error );
		$this->assertArrayHasKey( 'some_key', $standardized_error['context'] );
		$this->assertSame( 'some_value', $standardized_error['context']['some_key'] );
	}

	/**
	 * Test that step error standardization treats errors with only 'code' key as a single error.
	 *
	 * An error object with just the 'code' key should be treated as a single error.
	 *
	 * @return void
	 */
	public function test_step_error_standardization_wraps_error_with_code_key() {
		$location = 'US';

		// Create step details with errors containing only a 'code' key.
		$step_details = array(
			'id'     => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
			// Has 'code' key directly - should be treated as single error.
			'errors' => array(
				'code' => 'error_with_only_code',
			),
		);

		// Use reflection to call the private method.
		$reflection = new \ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( 'standardize_onboarding_step_details' );
		$method->setAccessible( true );

		// Act.
		$result = $method->invoke( $this->sut, $step_details, $location, '/rest/path/' );

		// Assert.
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsArray( $result['errors'] );
		$this->assertCount( 1, $result['errors'] );

		$standardized_error = $result['errors'][0];
		$this->assertArrayHasKey( 'code', $standardized_error );
		$this->assertSame( 'error_with_only_code', $standardized_error['code'] );
		// Message should default to empty string when not provided.
		$this->assertArrayHasKey( 'message', $standardized_error );
		$this->assertSame( '', $standardized_error['message'], 'Message should default to empty string' );
		// Context should default to empty array when not provided.
		$this->assertArrayHasKey( 'context', $standardized_error );
		$this->assertSame( array(), $standardized_error['context'], 'Context should default to empty array' );
	}

	/**
	 * Test that step error standardization treats errors with only 'message' key as a single error.
	 *
	 * An error object with just the 'message' key should be treated as a single error.
	 *
	 * @return void
	 */
	public function test_step_error_standardization_wraps_error_with_message_key() {
		$location = 'US';

		// Create step details with errors containing only a 'message' key.
		$step_details = array(
			'id'     => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
			// Has 'message' key directly - should be treated as single error.
			'errors' => array(
				'message' => 'Error with only message',
			),
		);

		// Use reflection to call the private method.
		$reflection = new \ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( 'standardize_onboarding_step_details' );
		$method->setAccessible( true );

		// Act.
		$result = $method->invoke( $this->sut, $step_details, $location, '/rest/path/' );

		// Assert.
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsArray( $result['errors'] );
		$this->assertCount( 1, $result['errors'] );

		$standardized_error = $result['errors'][0];
		$this->assertSame( 'Error with only message', $standardized_error['message'] );
		// Code should default to 'general_error'.
		$this->assertArrayHasKey( 'code', $standardized_error );
		$this->assertSame( 'general_error', $standardized_error['code'] );
	}

	/**
	 * Test that step error standardization treats errors with only 'context' key as a single error.
	 *
	 * An error object with just the 'context' key should be treated as a single error.
	 *
	 * @return void
	 */
	public function test_step_error_standardization_wraps_error_with_context_key() {
		$location = 'US';

		// Create step details with errors containing only a 'context' key.
		$step_details = array(
			'id'     => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
			// Has 'context' key directly - should be treated as single error.
			'errors' => array(
				'context' => array(
					'detail' => 'Some context detail',
				),
			),
		);

		// Use reflection to call the private method.
		$reflection = new \ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( 'standardize_onboarding_step_details' );
		$method->setAccessible( true );

		// Act.
		$result = $method->invoke( $this->sut, $step_details, $location, '/rest/path/' );

		// Assert.
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsArray( $result['errors'] );
		$this->assertCount( 1, $result['errors'] );

		$standardized_error = $result['errors'][0];
		// Code should default to 'general_error'.
		$this->assertArrayHasKey( 'code', $standardized_error );
		$this->assertSame( 'general_error', $standardized_error['code'] );
		// Context should be preserved.
		$this->assertArrayHasKey( 'context', $standardized_error );
		$this->assertArrayHasKey( 'detail', $standardized_error['context'] );
		$this->assertSame( 'Some context detail', $standardized_error['context']['detail'] );
	}

	/**
	 * Test that step error standardization handles a proper list of errors correctly.
	 *
	 * When errors is already a list of error objects (without code/message/context at top level),
	 * it should NOT be wrapped again.
	 *
	 * @return void
	 */
	public function test_step_error_standardization_keeps_error_list_as_is() {
		$location = 'US';

		// Create step details with errors as a proper list of error objects.
		$step_details = array(
			'id'     => WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS,
			'status' => WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED,
			// This is a proper list of errors - should NOT be wrapped.
			'errors' => array(
				array(
					'code'    => 'first_error',
					'message' => 'First error message',
				),
				array(
					'code'    => 'second_error',
					'message' => 'Second error message',
				),
			),
		);

		// Use reflection to call the private method.
		$reflection = new \ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( 'standardize_onboarding_step_details' );
		$method->setAccessible( true );

		// Act.
		$result = $method->invoke( $this->sut, $step_details, $location, '/rest/path/' );

		// Assert.
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsArray( $result['errors'] );
		$this->assertCount( 2, $result['errors'] );

		// Verify both errors were preserved.
		$this->assertSame( 'first_error', $result['errors'][0]['code'] );
		$this->assertSame( 'First error message', $result['errors'][0]['message'] );
		$this->assertSame( 'second_error', $result['errors'][1]['code'] );
		$this->assertSame( 'Second error message', $result['errors'][1]['message'] );
	}

	/**
	 * @testdox Test account init skips the step forward when the extension reports the test account was not created.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_skips_step_when_test_account_not_created() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return array(
								'success' => false,
								'error'   => 'Error message',
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_test_account_non_recoverable_error',
				$e->getErrorCode(),
				'A dedicated error code should signal the non-recoverable failure to the client.'
			);
		}

		$this->assertCount( 1, $requests_made, 'The test account initialization request should be made exactly once.' );

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses, 'The step should be marked completed so onboarding can proceed.' );
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_FAILED, $statuses, 'The step should not be marked failed.' );
	}

	/**
	 * @testdox Test account step keeps reporting completed after being skipped forward even if an invalid test account exists.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_step_reports_completed_after_skip_despite_invalid_test_account() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the account state: no account before the initialization, and a test-drive
		// account that requires verification (i.e. is not valid) after the platform fell back to it.
		$account_connected = false;
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturnCallback(
				function () use ( &$account_connected ) {
					return $account_connected;
				}
			);
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'restricted',
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => false,
					'detailsSubmitted' => false,
				)
			);

		// Arrange the REST API requests.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$account_connected ) {
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							// The platform fell back to an account that requires verification.
							$account_connected = true;

							return array(
								'success' => false,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_test_account_non_recoverable_error',
				$e->getErrorCode(),
				'A dedicated error code should signal the non-recoverable failure to the client.'
			);
		}

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'The skipped step should keep reporting completed so the merchant is not routed back to it.'
		);
	}

	/**
	 * @testdox Test account step status backfills the skip marker for a merchant trapped with a marker-less completed step and an invalid test account.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_status_backfills_skip_marker_for_trapped_merchant() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile with the exact state a real trapped merchant carries: the REST
		// init handler marked the step started, the skip-forward from before the marker existed
		// recorded completed, and there is no skip marker.
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $this->current_time - 200,
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 100,
							),
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the trapped account state: the platform fell back to a test-drive account
		// that requires verification (i.e. is not valid).
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'restricted',
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => false,
					'detailsSubmitted' => false,
				)
			);

		// Arrange the REST API requests: determining the step status must not call the platform.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) {
						unset( $params );
						throw new \Exception( esc_html( 'The platform should not be called: ' . $endpoint ) );
					},
				),
			)
		);

		// The status read should backfill the skip marker and report completed, instead of
		// re-deriving started and routing the merchant back into the step.
		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'The trapped merchant should report completed so onboarding can move on.'
		);

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER, $statuses, 'The skip marker should be backfilled for a step completed without one.' );
		$this->assertSame(
			$this->current_time - 100,
			$statuses[ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ],
			'The stored completion timestamp should be preserved — the backfill is not a new completion.'
		);
		$this->assertSame(
			$this->current_time - 200,
			$statuses[ WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED ],
			'The stored started timestamp should be left untouched.'
		);

		// Subsequent reads are idempotent: still completed, no further writes.
		$writes_after_backfill = count( $updated_stored_profiles );

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'The healed step should keep reporting completed.'
		);
		$this->assertCount(
			$writes_after_backfill,
			$updated_stored_profiles,
			'A repeated status read should not write again.'
		);
	}

	/**
	 * @testdox A stale skip marker is cleared when the test account step completes through a non-skip path.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_non_skip_completion_clears_stale_skip_marker() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile with a step completed by being skipped forward.
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER => $this->current_time - 100,
							),
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange a connected test-drive account that becomes valid and working (the merchant
		// eventually got a genuine test account), then lapses back to invalid.
		$account_valid = true;
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturnCallback(
				function () use ( &$account_valid ) {
					return $account_valid;
				}
			);
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturnCallback(
				function () use ( &$account_valid ) {
					return array(
						'status'           => $account_valid ? 'complete' : 'restricted',
						'testDrive'        => true,
						'isLive'           => false,
						'paymentsEnabled'  => $account_valid,
						'detailsSubmitted' => $account_valid,
					);
				}
			);

		// The valid, working test account auto-completes the step through the non-skip path,
		// which should clear the stale skip marker.
		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $status );

		$this->assertNotEmpty( $updated_stored_profiles );
		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayNotHasKey( WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER, $statuses, 'A non-skip completion should clear a stale skip marker.' );
		$this->assertSame( $this->current_time - 100, $statuses[ WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED ], 'The stored completion timestamp should be preserved — marker maintenance is not a new completion.' );

		// If the account validity lapses again, the merchant is in a state they cannot recover
		// from on their own (retrying the initialization cannot succeed with an account connected),
		// so the skip marker is backfilled on read and the step keeps reporting completed.
		$account_valid = false;

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'A lapsed account validity should get the skip marker backfilled instead of trapping the merchant.'
		);

		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER, $statuses, 'The skip marker should be backfilled when the account validity lapses.' );
	}

	/**
	 * @testdox A warning is logged when the auto-completion of the test account step fails to persist.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_auto_completion_logs_warning_when_not_persisted() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange a logger that captures log calls.
		$logged_calls = array();
		$mock_logger  = $this->mock_capturing_logger( $logged_calls );

		// Arrange the NOX profile with a step completed by being skipped forward,
		// but make persisting the profile fail.
		$stored_profile = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER => $this->current_time - 100,
							),
						),
					),
				),
			),
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) {
					// Avoid parameter not used PHPCS errors.
					unset( $value );
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return false;
					}

					return true;
				},
				'wc_get_logger' => function () use ( $mock_logger ) {
					return $mock_logger;
				},
			)
		);

		// Arrange a connected, valid, and working test-drive account so the step auto-completes
		// through the non-skip path (which also tries to clear the stale skip marker).
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );

		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'The step should still report completed since the auto-completion is retried on every status read.'
		);
		$this->assertNotEmpty( $logged_calls, 'A persistence failure of the auto-completion should be logged.' );
		$this->assertSame( 'warning', $logged_calls[0]['level'] );
		$this->assertStringContainsString( 'Failed to store the test account onboarding step completion', $logged_calls[0]['message'] );
		$this->assertSame( 'settings-payments', $logged_calls[0]['context']['source'] );
	}

	/**
	 * @testdox Finishing the test account step preserves the skip marker while the fallback account is not valid.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_finish_preserves_skip_marker_with_invalid_account() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile with a step completed by being skipped forward.
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time - 100,
								WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER => $this->current_time - 100,
							),
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// Arrange the invalid fallback test-drive account the skip left behind.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'is_stripe_account_valid' )
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'restricted',
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => false,
					'detailsSubmitted' => false,
				)
			);

		// This is what the generic step finish action does.
		$result = $this->sut->mark_onboarding_step_completed( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertTrue( $result );

		$this->assertEmpty( $updated_stored_profiles, 'The stored statuses should not be re-written while the account cannot vouch for a genuine completion.' );

		$status = $this->sut->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT, $location );
		$this->assertSame(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$status,
			'The skip protection should survive a finish action on the already-skipped step.'
		);
	}

	/**
	 * @testdox Skipping the test account step again does not re-write the already skipped step statuses.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_skip_over_skip_is_idempotent() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_profile          = array();
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$updated_stored_profiles, $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;
					}

					return true;
				},
			)
		);

		// No account is ever created: the initialization fails with a non-recoverable error each time.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( false );

		// Arrange the REST API requests.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) {
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return new WP_Error(
								'woocommerce_settings_payments_rest_error',
								"REST request POST failed with: (bad_request) The statement descriptor matches a common term or website URL, and can't be used.",
								array(
									'code'    => 'bad_request',
									'message' => "The statement descriptor matches a common term or website URL, and can't be used.",
									'data'    => array(
										'status'     => 400,
										'error_type' => 'invalid_request_error',
									),
								)
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_test_account_non_recoverable_error', $e->getErrorCode() );
		}

		$writes_after_first_skip = count( $updated_stored_profiles );
		$this->assertGreaterThan( 0, $writes_after_first_skip );

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_test_account_non_recoverable_error', $e->getErrorCode() );
		}

		$this->assertCount( $writes_after_first_skip, $updated_stored_profiles, 'A repeated skip should not re-write the already skipped step statuses.' );

		$final_profile = end( $updated_stored_profiles );
		$statuses      = $final_profile['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]['statuses'];
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED, $statuses );
		$this->assertArrayHasKey( WooPaymentsService::ONBOARDING_STEP_SKIPPED_MARKER, $statuses );
	}

	/**
	 * @testdox Test account init falls back to the failure path when the skipped step completion fails to persist.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init_skip_step_falls_back_to_failure_when_completion_not_persisted() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange a logger that captures log calls.
		$logged_calls = array();
		$mock_logger  = $this->mock_capturing_logger( $logged_calls );

		// Arrange the NOX profile so that persisting the profile fails.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return array();
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) {
					// Avoid parameter not used PHPCS errors.
					unset( $value );
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return false;
					}

					return true;
				},
				'wc_get_logger' => function () use ( $mock_logger ) {
					return $mock_logger;
				},
			)
		);

		// Arrange the REST API request to report that the test account was not created.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) {
						// Avoid parameter not used PHPCS errors.
						unset( $params );
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return array( 'success' => false );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->onboarding_test_account_init( $location );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame(
				'woocommerce_woopayments_onboarding_client_api_error',
				$e->getErrorCode(),
				'Without the completion persisted, the client should see the regular failure (and retry) instead of being advanced.'
			);
		}

		$this->assertNotEmpty( $logged_calls, 'A persistence failure of the skipped step completion should be logged.' );
		$this->assertSame( 'error', $logged_calls[0]['level'] );
		$this->assertStringContainsString( 'Failed to record the test account onboarding step', $logged_calls[0]['message'] );
	}

	/**
	 * Test that onboarding lock mechanism works during test account initialization.
	 */
	public function test_onboarding_lock_mechanism_during_test_account_init(): void {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Track onboarding lock state changes.
		$lock_states  = array();
		$current_time = $this->current_time;

		// Arrange the NOX profile and lock tracking.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$lock_states ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return array();
					}
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return end( $lock_states ) !== false ? end( $lock_states ) : $default_value;
					}
					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$lock_states, $current_time ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						$lock_states[] = $value;
					}
					return true;
				},
			)
		);

		// Mock successful REST API response.
		$expected_response = array(
			'success' => true,
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) use ( $expected_response ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							return $expected_response;
						}
						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act - call the method that should set and clear the lock.
		$result = $this->sut->onboarding_test_account_init( $location );

		// Assert that the result is successful.
		$this->assertEquals( $expected_response, $result );

		// Assert that the lock was set and then cleared.
		$this->assertCount( 2, $lock_states, 'Expected exactly 2 lock state changes: set lock (timestamp) and clear lock (0)' );
		$this->assertSame( $current_time, $lock_states[0], 'First lock state should be current timestamp' );
		$this->assertSame( 0, $lock_states[1], 'Second lock state should be 0 (cleared)' );
	}

	/**
	 * Test that onboarding lock is cleared even when an exception occurs.
	 */
	public function test_onboarding_lock_cleared_on_exception(): void {
		$location = 'US';

		// Arrange the WPCOM connection.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Track onboarding lock state changes.
		$lock_states  = array();
		$current_time = $this->current_time;

		// Arrange the NOX profile and lock tracking.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$lock_states ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return array();
					}
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return end( $lock_states ) !== false ? end( $lock_states ) : $default_value;
					}
					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$lock_states, $current_time ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						$lock_states[] = $value;
					}
					return true;
				},
			)
		);

		// Mock REST API to throw an exception.
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							throw new \Exception( 'Simulated API failure' );
						}
						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act & Assert - expect the method to handle the exception and still clear the lock.
		try {
			$this->sut->onboarding_test_account_init( $location );

			$this->fail( 'Expected an exception to be re-thrown as WP_Error' );
		} catch ( \Throwable $e ) {
			// The exception handling should have cleared the lock even though an error occurred.
			$this->assertInstanceOf( \Exception::class, $e );
		}

		// Assert that the lock was set and then cleared despite the exception.
		$this->assertCount( 2, $lock_states, 'Expected exactly 2 lock state changes even with exception: set lock (timestamp) and clear lock (0)' );
		$this->assertSame( $current_time, $lock_states[0], 'First lock state should be current timestamp' );
		$this->assertSame( 0, $lock_states[1], 'Second lock state should be 0 (cleared) even after exception' );
	}

	/**
	 * Test onboarding_test_account_init.
	 *
	 * @return void
	 */
	public function test_onboarding_test_account_init() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$step_id                 = WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT;
		$started_timestamp       = $this->current_time - 100;
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $started_timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						// Chain the responses to simulate the sequence of DB updates.
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_response = array(
			'success' => true,
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/init' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		$result = $this->sut->onboarding_test_account_init( $location );

		// Assert.
		$this->assertEquals( $expected_response, $result );
		$this->assertCount( 1, $requests_made );
		$this->assertCount( 0, $updated_stored_profiles );
		// There is no automatic completion of the step due to its async nature.
	}

	/**
	 * @testdox Should preserve an internal request failure even when the account.deleted webhook clears the shared lock.
	 */
	public function test_disable_test_account_preserves_internal_lock_failure_after_account_delete(): void {
		$location = 'US';

		$account_is_connected = true;
		$account_status       = array(
			'status'           => 'complete',
			'testDrive'        => true,
			'isLive'           => false,
			'paymentsEnabled'  => true,
			'detailsSubmitted' => true,
		);
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturnCallback(
				function () use ( &$account_is_connected ) {
					return $account_is_connected;
				}
			);
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturnCallback(
				function () use ( &$account_status ) {
					return $account_status;
				}
			);

		$lock_value      = 0;
		$lock_changes    = array();
		$stored_profile  = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile, $stored_profile );

		$requests_made = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$account_status, &$lock_value, &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
							$requests_made[] = $params;
							$account_status  = array(
								'error' => true,
							);
							// Simulate the WooPayments account.deleted webhook deleting the shared lock
							// option (a delete_option call, which Core's update_option tracker does not see).
							$lock_value = null;

							// The internal test-drive disable endpoint surfaces every thrown exception as
							// HTTP 400 / bad_request (disable_test_drive_account in the WooPayments client),
							// so that is what rest_endpoint_post_request returns to Core here.
							return new WP_Error(
								'woocommerce_settings_payments_rest_error',
								'REST request POST /wc/v3/payments/onboarding/test_drive_account/disable failed with: (bad_request) Failed to disable the test-drive account.',
								array(
									'code'    => 'bad_request',
									'message' => 'Failed to disable the test-drive account.',
									'data'    => array(
										'status' => 400,
									),
								)
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->disable_test_account( $location, 'test-from', 'test-source' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_client_api_error', $e->getErrorCode() );
			$this->assertSame( \WP_Http::FAILED_DEPENDENCY, $e->getCode() );
		}

		$this->assertCount( 1, $requests_made, 'The internal test-drive disable endpoint should be called once.' );
		// Core never re-writes the lock after Phase 1, so the webhook's delete (modeled as null)
		// survives; the get_option mock resolves null via "$lock_value ?? $default_value", so a
		// webhook-deleted lock and a Core-cleared 0 are indistinguishable to is_onboarding_locked().
		$this->assertNull( $lock_value, 'The webhook-deleted onboarding lock should remain deleted after the internal request fails.' );
		$this->assertSame(
			array( $this->current_time, 0 ),
			$lock_changes,
			'Core should set and then clear its own preflight lock (two update_option writes); the webhook-triggered delete is not an update_option event.'
		);
		$this->assertSame(
			array(),
			$updated_profile,
			'NOX steps should not be marked completed when the internal disable request fails.'
		);
	}

	/**
	 * @testdox Should clear the onboarding lock before the internal disable call, even when that call throws an unexpected error.
	 */
	public function test_disable_test_account_clears_lock_before_internal_disable_even_when_it_throws(): void {
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
							throw new \Error( 'Simulated engine failure.' );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->disable_test_account( $location, 'test-from', 'test-source' );

			$this->fail( 'Expected Error not thrown.' );
		} catch ( \Error $e ) {
			// The internal call runs in Phase 2, which only catches Exception, so a fatal \Error
			// propagates by design. The lock must already be cleared by Phase 1's finally at this point.
			$this->assertSame( 0, $lock_value, 'The lock is cleared in Phase 1 before the internal call, so it is already cleared when a Phase 2 \Error propagates.' );
		}

		$this->assertSame( array( $this->current_time, 0 ), $lock_changes, 'Core sets then clears its own preflight lock; a propagating \Error adds no further lock writes.' );
	}

	/**
	 * @testdox Should preserve internal non-200 failures as client API errors and unlock.
	 */
	public function test_disable_test_account_preserves_internal_non_200_as_client_api_error_and_unlocks(): void {
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
							return new WP_Error(
								'woocommerce_settings_payments_rest_error',
								'Internal WooPayments bad request.',
								array(
									'code'    => 'wcpay_bad_request',
									'message' => 'Internal WooPayments bad request.',
									'data'    => array(
										'status' => 400,
									),
								)
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		try {
			$this->sut->disable_test_account( $location, 'test-from', 'test-source' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_client_api_error', $e->getErrorCode() );
			$this->assertSame( \WP_Http::FAILED_DEPENDENCY, $e->getCode() );
		}

		$this->assertSame( 0, $lock_value, 'The onboarding lock should be cleared after non-lock internal failures.' );
		$this->assertSame( array( $this->current_time, 0 ), $lock_changes );
		$this->assertSame( array(), $updated_profile, 'NOX steps should not be marked completed when the disable failed.' );
	}

	/**
	 * @testdox Should reject an active onboarding lock before calling internal test account disable.
	 */
	public function test_disable_test_account_rejects_active_onboarding_lock_before_internal_disable(): void {
		$location = 'US';

		$endpoint_calls = 0;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function () use ( &$endpoint_calls ) {
						$endpoint_calls++;

						return array(
							'success' => true,
						);
					},
				),
			)
		);

		$lock_value      = $this->current_time;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		try {
			$this->sut->disable_test_account( $location, 'test-from', 'test-source' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
			$this->assertSame( \WP_Http::CONFLICT, $e->getCode() );
		}

		$this->assertSame( 0, $endpoint_calls, 'The internal WooPayments endpoint should not be called while the lock is active.' );
		$this->assertSame( $this->current_time, $lock_value, 'The active lock should remain untouched.' );
		$this->assertSame( array(), $lock_changes, 'Core should not set or clear its own lock after rejecting an active lock.' );
	}

	/**
	 * @testdox Should self-heal an expired onboarding lock before disabling the test account.
	 */
	public function test_disable_test_account_self_heals_expired_onboarding_lock_before_internal_disable(): void {
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );

		$endpoint_calls              = 0;
		$disable_test_account_result = function ( string $endpoint ) use ( &$endpoint_calls ) {
			if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
				++$endpoint_calls;

				return new WP_Error(
					'woocommerce_settings_payments_rest_error',
					'Internal WooPayments bad request.',
					array(
						'code'    => 'wcpay_bad_request',
						'message' => 'Internal WooPayments bad request.',
						'data'    => array(
							'status' => 400,
						),
					)
				);
			}

			throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
		};
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => $disable_test_account_result,
				),
			)
		);

		$lock_value      = $this->current_time - WooPaymentsService::NOX_ONBOARDING_LOCKED_TTL_SECONDS - 1;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		try {
			$this->sut->disable_test_account( $location, 'test-from', 'test-source' );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_client_api_error', $e->getErrorCode() );
			$this->assertSame( \WP_Http::FAILED_DEPENDENCY, $e->getCode() );
		}

		$this->assertSame( 1, $endpoint_calls, 'The internal WooPayments endpoint should be called after the stale lock self-heals.' );
		$this->assertSame( 0, $lock_value, 'The onboarding lock should finish normalized to 0.' );
		$this->assertSame(
			array( 0, $this->current_time, 0 ),
			$lock_changes,
			'Core should clear the stale lock, acquire and clear its preflight lock.'
		);
	}

	/**
	 * @testdox Should disable a sandbox account via the onboarding reset endpoint and clear the lock around it.
	 */
	public function test_disable_test_account_uses_reset_endpoint_for_sandbox_account(): void {
		$location = 'US';

		// A connected account that is neither a test-drive nor a live account is a sandbox account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => false,
					'isLive'           => false,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$requested_endpoints = array();
		$requested_params    = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requested_endpoints, &$requested_params ) {
						$requested_endpoints[] = $endpoint;
						$requested_params[]    = $params;

						return array(
							'success' => true,
						);
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'Disabling a sandbox account should succeed.' );
		$this->assertSame(
			array( '/wc/v3/payments/onboarding/reset' ),
			$requested_endpoints,
			'A sandbox account should be disabled via the onboarding reset endpoint, not the test-drive disable endpoint.'
		);
		// The Phase 2 payload must carry the caller's "from" and the validated source so a regression that
		// routes correctly but drops or swaps these fails. "test-source" is not whitelisted, so the service
		// normalizes it to the default via validate_onboarding_source().
		$this->assertSame( 'test-from', $requested_params[0]['from'] ?? null, 'The reset request should forward the caller "from".' );
		$this->assertSame( WooPaymentsService::SESSION_ENTRY_DEFAULT, $requested_params[0]['source'] ?? null, 'The reset request should carry the validated onboarding source.' );
		$this->assertSame(
			array( $this->current_time, 0 ),
			$lock_changes,
			'Core should set its preflight lock and clear it before the Phase 2 reset call.'
		);
		$this->assertSame( 0, $lock_value, 'The onboarding lock should be cleared after the reset call.' );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT );
	}

	/**
	 * @testdox Should make no internal request and still succeed when there is no test or sandbox account to disable.
	 */
	public function test_disable_test_account_makes_no_request_when_no_test_or_sandbox_account(): void {
		$location = 'US';

		// No connected account means there is neither a test-drive nor a sandbox account to disable.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( false );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn( array() );
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$endpoint_calls = 0;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function () use ( &$endpoint_calls ) {
						++$endpoint_calls;

						return array(
							'success' => true,
						);
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'The no-op disable should return a success response.' );
		$this->assertSame( 0, $endpoint_calls, 'No internal WooPayments request should be made when there is no account to disable.' );
		$this->assertSame(
			array( $this->current_time, 0 ),
			$lock_changes,
			'Core should still set and clear its preflight lock even when the Phase 2 request is skipped.'
		);
		$this->assertSame( 0, $lock_value, 'The onboarding lock should be cleared after the no-op.' );
	}

	/**
	 * @testdox Should disable a test-drive account via the test-drive endpoint and mark steps completed on success.
	 */
	public function test_disable_test_account_marks_steps_completed_on_successful_test_drive_disable(): void {
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$requested_endpoints = array();
		$requested_params    = array();
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requested_endpoints, &$requested_params ) {
						$requested_endpoints[] = $endpoint;
						$requested_params[]    = $params;

						return array(
							'success' => true,
						);
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'Disabling a test-drive account should succeed.' );
		$this->assertSame(
			array( '/wc/v3/payments/onboarding/test_drive_account/disable' ),
			$requested_endpoints,
			'A test-drive account should be disabled via the test-drive disable endpoint, not the onboarding reset endpoint.'
		);
		// The Phase 2 payload must carry the caller's "from" and the validated source so a regression that
		// routes correctly but drops or swaps these fails. "test-source" is not whitelisted, so the service
		// normalizes it to the default via validate_onboarding_source().
		$this->assertSame( 'test-from', $requested_params[0]['from'] ?? null, 'The disable request should forward the caller "from".' );
		$this->assertSame( WooPaymentsService::SESSION_ENTRY_DEFAULT, $requested_params[0]['source'] ?? null, 'The disable request should carry the validated onboarding source.' );
		$this->assertSame(
			array( $this->current_time, 0 ),
			$lock_changes,
			'Core should set its preflight lock and clear it before the Phase 2 disable call.'
		);
		$this->assertSame( 0, $lock_value, 'The onboarding lock should be cleared after the disable call.' );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT );
	}

	/**
	 * @testdox Should not write the onboarding lock during or after the Phase 2 internal call.
	 */
	public function test_disable_test_account_does_not_write_onboarding_lock_during_phase_2(): void {
		// Guards the invariant that Phase 2 must not call clear_onboarding_lock(): because the lock
		// is a shared, token-less option, an unconditional Phase 2 clear would stomp a concurrent
		// request's freshly acquired lock. The proof is that no lock write happens once Phase 2 runs.
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		$lock_writes_at_phase_2 = null;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) use ( &$lock_changes, &$lock_writes_at_phase_2 ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
							// Snapshot the lock writes that happened before the internal call ran.
							$lock_writes_at_phase_2 = $lock_changes;

							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'Disabling the test-drive account should succeed.' );
		$this->assertSame(
			array( $this->current_time, 0 ),
			$lock_writes_at_phase_2,
			'By the time the Phase 2 internal call runs, Core should have already set and cleared its preflight lock.'
		);
		$this->assertSame(
			$lock_writes_at_phase_2,
			$lock_changes,
			'Phase 2 and the post-call step bookkeeping must not write the onboarding lock again; an extra write means an unconditional Phase 2 clear was re-introduced.'
		);
	}

	/**
	 * @testdox Should complete the post-disable bookkeeping for a test-drive account even if a concurrent request re-acquires the onboarding lock during the unlocked Phase 2 window.
	 */
	public function test_disable_test_account_completes_bookkeeping_when_lock_reacquired_during_phase_2_for_test_drive(): void {
		$location = 'US';

		$account_exists = true;
		$this->mock_account_state( $account_exists );
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		// Simulate a concurrent onboarding request acquiring the shared lock during the unlocked
		// Phase 2 window: the internal disable mutation commits, but by the time Core runs its
		// post-disable bookkeeping the lock is held again. Because the mutation has already
		// happened, the bookkeeping must not re-check the lock (which would throw onboarding_locked
		// after a successful mutation) and must not stomp the concurrent request's lock.
		$current_time = $this->current_time;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) use ( &$lock_value, $current_time ) {
						if ( '/wc/v3/payments/onboarding/test_drive_account/disable' === $endpoint ) {
							// A concurrent request grabs the lock after Core released it for Phase 2.
							$lock_value = $current_time;

							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'The disable should succeed even though the lock was re-acquired before the bookkeeping ran.' );
		// The committed mutation's bookkeeping must run to completion instead of throwing an onboarding-locked error.
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT );
		// Core must only set and release its own preflight lock; the post-disable bookkeeping must
		// not write the lock at all, so the concurrent request's lock is left intact (not stomped to 0).
		$this->assertSame(
			array( $current_time, 0 ),
			$lock_changes,
			'Only the preflight set and release should be written; the post-disable bookkeeping must not write the lock.'
		);
		$this->assertSame(
			$current_time,
			$lock_value,
			"The concurrent request's onboarding lock must remain intact after the bookkeeping completes."
		);
	}

	/**
	 * @testdox Should complete the post-reset bookkeeping for a sandbox account even if a concurrent request re-acquires the onboarding lock during the unlocked Phase 2 window.
	 */
	public function test_disable_test_account_completes_bookkeeping_when_lock_reacquired_during_phase_2_for_sandbox(): void {
		$location = 'US';

		// A connected account that is neither a test-drive nor a live account is a sandbox account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => false,
					'isLive'           => false,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);
		$this->mock_working_wpcom_connection();

		$lock_value      = 0;
		$lock_changes    = array();
		$updated_profile = array();
		$this->mock_disable_test_account_option_state( $lock_value, $lock_changes, $updated_profile );

		// Same race as the test-drive path, but for the sandbox reset endpoint.
		$current_time = $this->current_time;
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint ) use ( &$lock_value, $current_time ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							// A concurrent request grabs the lock after Core released it for Phase 2.
							$lock_value = $current_time;

							return array(
								'success' => true,
							);
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		$result = $this->sut->disable_test_account( $location, 'test-from', 'test-source' );

		$this->assertSame( array( 'success' => true ), $result, 'The reset should succeed even though the lock was re-acquired before the bookkeeping ran.' );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_PAYMENT_METHODS );
		$this->assert_onboarding_step_completed( $updated_profile, $location, WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT );
		$this->assertSame(
			array( $current_time, 0 ),
			$lock_changes,
			'Only the preflight set and release should be written; the post-reset bookkeeping must not write the lock.'
		);
		$this->assertSame(
			$current_time,
			$lock_value,
			"The concurrent request's onboarding lock must remain intact after the bookkeeping completes."
		);
	}

	/**
	 * Test get_onboarding_kyc_session throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_get_onboarding_kyc_session_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->get_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test get_onboarding_kyc_session throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_get_onboarding_kyc_session_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->get_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test get_onboarding_kyc_session throws exception when step requirements are not met.
	 *
	 * @return void
	 */
	public function test_get_onboarding_kyc_session_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it NOT working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		try {
			$this->sut->get_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_requirements_not_met', $e->getErrorCode() );
		}
	}

	/**
	 * Test that get_onboarding_kyc_session throws an exception when the REST API call fails.
	 *
	 * @return void
	 * @throws \Exception On GET request not mocked.
	 */
	public function test_get_onboarding_kyc_session_throws_on_error_response() {
		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made  = array();
		$expected_error = array(
			'code'    => 'error',
			'message' => 'Error message',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_error ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							$requests_made[] = $params;
							return new WP_Error( $expected_error['code'], $expected_error['message'] );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( $expected_error['message'] );

		// Act.
		$this->sut->get_onboarding_kyc_session( 'US' );
	}

	/**
	 * Test that get_onboarding_kyc_session throws an exception when the REST API call doesn't respond properly.
	 *
	 * @return void
	 * @throws \Exception On GET request not mocked.
	 */
	public function test_get_onboarding_kyc_session_throws_on_failure() {
		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made = array();
		// Not an array.
		$expected_response = '';
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		try {
			$this->sut->get_onboarding_kyc_session( 'US' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'woocommerce_woopayments_onboarding_client_api_error', $exception->getErrorCode() );
			$this->assertSame( 'Failed to get the KYC session data.', $exception->getMessage() );
		}
	}

	/**
	 * Test that get_onboarding_kyc_session uses stored data when no data is provided.
	 *
	 * @return void
	 * @throws \Exception On GET request not mocked.
	 */
	public function test_get_onboarding_kyc_session_uses_stored_data() {
		$step_id  = WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION;
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$self_assessment = array(
			'business_type' => 'company',
			'mcc'           => 1234,
		);
		$stored_profile  = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'data' => array(
								'self_assessment' => $self_assessment,
							),
						),
					),
				),
			),
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_payload  = array(
			'self_assessment' => $self_assessment,
			'capabilities'    => array(),
		);
		$expected_response = array(
			'clientSecret'   => 'secret',
			'expiresAt'      => $this->current_time + 1000,
			'accountId'      => 'id',
			'isLive'         => false,
			'accountCreated' => false,
			'publishableKey' => 'key',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		$result = $this->sut->get_onboarding_kyc_session( $location );

		// Assert.
		self::assertEquals( $expected_response + array( 'locale' => 'en_US' ), $result );
		self::assertCount( 1, $requests_made );
		self::assertEquals( $expected_payload, $requests_made[0] );
	}

	/**
	 * Test that get_onboarding_kyc_session uses received data, superseding the stored data.
	 *
	 * @return void
	 * @throws \Exception On GET request not mocked.
	 */
	public function test_get_onboarding_kyc_session_uses_received_data() {
		$step_id         = WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION;
		$location        = 'US';
		$self_assessment = array(
			'business_type' => 'individual',
			'mcc'           => 4567,
		);

		// Arrange the WPCOM connection.
		// Make it working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$stored_self_assessment = array(
			'business_type' => 'company',
			'mcc'           => 1234,
		);
		$stored_profile         = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'data' => array(
								'self_assessment' => $stored_self_assessment,
							),
						),
					),
				),
			),
		);
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) use ( $stored_profile ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_profile;
					}

					return $default_value;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_payload  = array(
			'self_assessment' => $self_assessment,
			'capabilities'    => array(),
		);
		$expected_response = array(
			'clientSecret'   => 'secret',
			'expiresAt'      => $this->current_time + 1000,
			'accountId'      => 'id',
			'isLive'         => false,
			'accountCreated' => false,
			'publishableKey' => 'key',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/kyc/session' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		$result = $this->sut->get_onboarding_kyc_session(
			$location,
			$self_assessment
		);

		// Assert.
		self::assertEquals( $expected_response + array( 'locale' => 'en_US' ), $result );
		self::assertCount( 1, $requests_made );
		self::assertEquals( $expected_payload, $requests_made[0] );
	}

	/**
	 * Test finish_onboarding_kyc_session throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_finish_onboarding_kyc_session_throws_when_extension_not_active() {
		$location = 'US';

		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->finish_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			self::assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test finish_onboarding_kyc_session throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_finish_onboarding_kyc_session_throws_with_onboarding_locked() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->finish_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			self::assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test finish_onboarding_kyc_session throws exception when step requirements are not met.
	 *
	 * @return void
	 */
	public function test_finish_onboarding_kyc_session_throws_on_unmet_requirements() {
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it NOT working.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		try {
			$this->sut->finish_onboarding_kyc_session( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_step_requirements_not_met', $e->getErrorCode() );
		}
	}

	/**
	 * Test that finish_onboarding_kyc_session throws an exception when the REST API call fails.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_finish_onboarding_kyc_session_throws_on_error_response() {
		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made  = array();
		$expected_error = array(
			'code'    => 'error',
			'message' => 'Error message',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_error ) {
						if ( '/wc/v3/payments/onboarding/kyc/finalize' === $endpoint ) {
							$requests_made[] = $params;
							return new WP_Error( $expected_error['code'], $expected_error['message'] );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( $expected_error['message'] );

		// Act.
		$this->sut->finish_onboarding_kyc_session( 'US' );
	}

	/**
	 * Test that finish_onboarding_kyc_session throws an exception when the REST API call doesn't respond properly.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_finish_onboarding_kyc_session_throws_on_failure() {
		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made = array();
		// Not an array.
		$expected_response = '';
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/kyc/finalize' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( esc_html__( 'Failed to finish the KYC session.', 'woocommerce' ) );

		// Act.
		$this->sut->finish_onboarding_kyc_session( 'US' );
	}

	/**
	 * Test finish_onboarding_kyc_session.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_finish_onboarding_kyc_session() {
		$step_id  = WooPaymentsService::ONBOARDING_STEP_BUSINESS_VERIFICATION;
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it working.
		// Without this, the step will not be marked as completed due to unmet step requirements.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the NOX profile.
		$started_timestamp       = $this->current_time - 100;
		$stored_profile          = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						$step_id => array(
							'statuses' => array(
								WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $started_timestamp,
							),
						),
					),
				),
			),
		);
		$updated_stored_profiles = array();
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						// Chain the responses to simulate the sequence of DB updates.
						return ! empty( $updated_stored_profiles ) ? end( $updated_stored_profiles ) : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( $stored_profile, &$updated_stored_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_stored_profiles[] = $value;

						// Mimic the behavior of the original function.
						if ( $value === $stored_profile || maybe_serialize( $value ) === maybe_serialize( $stored_profile ) ) {
							return false;
						}

						return true;
					}

					return true;
				},
			)
		);

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_payload  = array(
			'source' => WooPaymentsService::SESSION_ENTRY_DEFAULT,
			'from'   => WooPaymentsService::FROM_NOX_IN_CONTEXT,
		);
		$expected_response = array(
			'success'           => true,
			'details_submitted' => true,
			'account_id'        => 'id',
			'mode'              => 'test',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/kyc/finalize' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Act.
		$result = $this->sut->finish_onboarding_kyc_session( $location );

		// Assert.
		self::assertEquals( $expected_response, $result );
		self::assertCount( 1, $requests_made );
		self::assertEquals( $expected_payload, $requests_made[0] );
		// One is from the step status update and one is from the test account being marked as completed.
		$this->assertCount( 2, $updated_stored_profiles );
		// The step status should have been set to `completed`.
		$this->assertEquals(
			array(
				'statuses' => array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_STARTED => $started_timestamp,
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time,
				),
			),
			$updated_stored_profiles[1]['onboarding'][ $location ]['steps'][ $step_id ]
		);
		$this->assertEquals(
			array(
				'statuses' => array(
					WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED => $this->current_time,
				),
			),
			$updated_stored_profiles[1]['onboarding'][ $location ]['steps'][ WooPaymentsService::ONBOARDING_STEP_TEST_ACCOUNT ]
		);
	}

	/**
	 * Test onboarding_preload throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_preload_throws_with_onboarding_locked() {
		// Arrange.
		$location = 'US';

		// Arrange the WPCOM connection.
		// Make it not connected.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( false );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( false );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		// Act.
		try {
			$this->sut->onboarding_preload( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that onboarding_preload throws an exception when the WPCOM connection registration fails.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_onboarding_preload_throws_on_error_response() {
		// Arrange.
		$location = 'US';

		// Arrange the WPCOM connection.
		$expected_error = array(
			'code'    => 'error',
			'message' => 'Error message',
		);
		// Make it not connected.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( false );
		$this->mock_wpcom_connection_manager
			->expects( $this->once() )
			->method( 'try_registration' )
			->willReturn( new WP_Error( $expected_error['code'], $expected_error['message'] ) );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return 0;
					}

					return $default_value;
				},
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( $expected_error['message'] );

		// Act.
		$this->sut->onboarding_preload( $location );
	}

	/**
	 * Test onboarding_preload.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_onboarding_preload() {
		// Arrange.
		$location                           = 'US';
		$expected_wpcom_registration_result = false;

		// Arrange the WPCOM connection.
		// Make it not connected.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( false );
		$this->mock_wpcom_connection_manager
			->expects( $this->once() )
			->method( 'try_registration' )
			->willReturn( $expected_wpcom_registration_result );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return 0;
					}

					return $default_value;
				},
			)
		);

		// Act.
		$result = $this->sut->onboarding_preload( $location );

		// Assert.
		self::assertEquals(
			array(
				'success' => $expected_wpcom_registration_result,
			),
			$result
		);
	}

	/**
	 * Test reset_onboarding throws exception when extension is not active.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_reset_onboarding_throws_when_extension_not_active() {
		// Arrange.
		$location = 'US';
		$this->mock_woopayments_runtime_unavailable();

		try {
			$this->sut->reset_onboarding( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			self::assertEquals( 'woocommerce_woopayments_onboarding_extension_not_active', $e->getErrorCode() );
		}
	}

	/**
	 * Test reset_onboarding throws exception when onboarding is locked.
	 *
	 * @return void
	 * @throws \Exception When trying to mock uncallable user functions.
	 */
	public function test_reset_onboarding_throws_with_onboarding_locked() {
		// Arrange.
		$location = 'US';
		// Arrange the WPCOM connection.
		// Make it working since it is a dependency for the step.
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );

		// Arrange the onboarding locked DB option.
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $default_value = null ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $this->current_time;
					}

					return $default_value;
				},
			)
		);

		try {
			$this->sut->reset_onboarding( $location );

			$this->fail( 'Expected ApiException not thrown.' );
		} catch ( ApiException $e ) {
			$this->assertEquals( 'woocommerce_woopayments_onboarding_locked', $e->getErrorCode() );
		}
	}

	/**
	 * Test that reset_onboarding with a connected account throws an exception when the REST API call fails.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_reset_onboarding_with_account_throws_on_error_response() {
		// Arrange.
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made  = array();
		$expected_error = array(
			'code'    => 'error',
			'message' => 'Error message',
		);
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_error ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							$requests_made[] = $params;
							return new WP_Error( $expected_error['code'], $expected_error['message'] );
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( $expected_error['message'] );

		// Act.
		$this->sut->reset_onboarding( $location );
	}

	/**
	 * Test that reset_onboarding with a connected account throws an exception when the REST API call doesn't respond properly.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_reset_onboarding_with_account_throws_on_invalid_response() {
		// Arrange.
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made = array();
		// Not an array.
		$expected_response = '';
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( esc_html__( 'Failed to reset onboarding.', 'woocommerce' ) );

		// Act.
		$this->sut->reset_onboarding( $location );
	}

	/**
	 * Test that reset_onboarding with a connected account throws an exception when the REST API call doesn't succeed.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_reset_onboarding_with_account_throws_on_failure() {
		// Arrange.
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made = array();
		// Not an array.
		$expected_response = array( 'success' => false );
		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							$requests_made[] = $params;
							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Assert.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( esc_html__( 'Failed to reset onboarding.', 'woocommerce' ) );

		// Act.
		$this->sut->reset_onboarding( $location );
	}

	/**
	 * Test reset_onboarding with a connected account.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_reset_onboarding_with_account() {
		// Arrange.
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( true );

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_payload  = array(
			'from'   => WooPaymentsService::FROM_PAYMENT_SETTINGS,
			'source' => WooPaymentsService::SESSION_ENTRY_DEFAULT,
		);
		$expected_response = array(
			'success' => true,
		);

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made, $expected_response ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							$requests_made[] = $params;

							return $expected_response;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Arrange the DB options.
		$onboarding_lock_cleared = 0;
		$deleted_profiles        = 0;
		$this->mockable_proxy->register_function_mocks(
			array(
				'update_option' => function ( $option_name, $value ) use ( &$onboarding_lock_cleared ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name && 0 === $value ) {
						$onboarding_lock_cleared++;

						return true;
					}

					return true;
				},
				'delete_option' => function ( $option_name ) use ( &$deleted_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$deleted_profiles++;

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->reset_onboarding( $location );

		// Assert.
		self::assertEquals( $expected_response, $result );
		self::assertCount( 1, $requests_made );
		self::assertEquals( $expected_payload, $requests_made[0] );
		// The onboarding lock should be cleared.
		self::assertEquals( 1, $onboarding_lock_cleared );
		// The NOX profile option should be deleted.
		self::assertEquals( 1, $deleted_profiles );
	}

	/**
	 * Test reset_onboarding with no connected account.
	 *
	 * @return void
	 * @throws \Exception On POST request not mocked.
	 */
	public function test_reset_onboarding_without_account() {
		// Arrange.
		$location = 'US';

		// Arrange the account.
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( false );

		// Arrange the REST API requests.
		$requests_made     = array();
		$expected_response = array(
			'success' => true,
		);

		$this->mockable_proxy->register_static_mocks(
			array(
				Utils::class => array(
					'rest_endpoint_post_request' => function ( string $endpoint, array $params = array() ) use ( &$requests_made ) {
						if ( '/wc/v3/payments/onboarding/reset' === $endpoint ) {
							$requests_made[] = $params;

							return;
						}

						throw new \Exception( esc_html( 'POST endpoint response is not mocked: ' . $endpoint ) );
					},
				),
			)
		);

		// Arrange the DB options.
		$onboarding_lock_cleared = 0;
		$deleted_profiles        = 0;
		$this->mockable_proxy->register_function_mocks(
			array(
				'update_option' => function ( $option_name, $value ) use ( &$onboarding_lock_cleared ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name && 0 === $value ) {
						$onboarding_lock_cleared++;

						return true;
					}

					return true;
				},
				'delete_option' => function ( $option_name ) use ( &$deleted_profiles ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$deleted_profiles++;

						return true;
					}

					return true;
				},
			)
		);

		// Act.
		$result = $this->sut->reset_onboarding( $location );

		// Assert.
		self::assertEquals( $expected_response, $result );
		// No request should be made when there is no connected account.
		self::assertCount( 0, $requests_made );
		// The onboarding lock should be cleared.
		self::assertEquals( 1, $onboarding_lock_cleared );
		// The NOX profile option should be deleted.
		self::assertEquals( 1, $deleted_profiles );
	}

	/**
	 * Get the mock business types for onboarding fields.
	 *
	 * @return array[]
	 */
	private function get_mock_onboarding_fields_business_types(): array {
		return array(
			array(
				'key'   => 'US',
				'name'  => 'United States',
				'types' => array(
					array(
						'key'        => 'individual',
						'name'       => 'Individual',
						'structures' => array(),
					),
					array(
						'key'        => 'company',
						'name'       => 'Company',
						'structures' => array(
							array(
								'key'  => 'sole_proprietorship',
								'name' => 'Sole proprietorship',
							),
							array(
								'key'  => 'single_member_llc',
								'name' => 'Single-member LLC',
							),
							array(
								'key'  => 'multi_member_llc',
								'name' => 'Multi-member LLC',
							),
							array(
								'key'  => 'private_partnership',
								'name' => 'Private partnership',
							),
							array(
								'key'  => 'private_corporation',
								'name' => 'Private corporation',
							),
							array(
								'key'  => 'unincorporated_association',
								'name' => 'Unincorporated association',
							),
							array(
								'key'  => 'public_partnership',
								'name' => 'Public partnership',
							),
							array(
								'key'  => 'public_corporation',
								'name' => 'Public corporation',
							),
						),
					),
					array(
						'key'        => 'non_profit',
						'name'       => 'Non-profit',
						'structures' => array(
							array(
								'key'  => 'incorporated_non_profit',
								'name' => 'Incorporated non-profit',
							),
							array(
								'key'  => 'unincorporated_non_profit',
								'name' => 'Unincorporated non-profit',
							),
						),
					),
					array(
						'key'        => 'government_entity',
						'name'       => 'Government entity',
						'structures' => array(
							array(
								'key'  => 'governmental_unit',
								'name' => 'Governmental unit',
							),
							array(
								'key'  => 'government_instrumentality',
								'name' => 'Government instrumentality',
							),
							array(
								'key'  => 'tax_exempt_government_instrumentality',
								'name' => 'Tax exempt government instrumentality',
							),
						),
					),
				),
			),
		);
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
	 * Test get_onboarding_payment_methods_state falls back to OR logic when no explicit apple_google stored.
	 *
	 * @return void
	 */
	public function test_get_onboarding_payment_methods_state_fallback_or_logic() {
		$location        = 'US';
		$recommended_pms = array(
			array(
				'id'       => 'apple_pay',
				'enabled'  => true,
				'required' => false,
			),
			array(
				'id'       => 'google_pay',
				'enabled'  => false,
				'required' => false,
			),
		);

		// Create NOX profile data structure with apple_pay and google_pay but no apple_google key.
		$nox_profile_data = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						'payment_methods' => array(
							'data' => array(
								'payment_methods' => array(
									'apple_pay'  => 'false',
									'google_pay' => 'false',
									// No apple_google key - should fall back to OR logic.
								),
							),
						),
					),
				),
			),
		);

		$this->mock_nox_profile_option( $nox_profile_data );

		// Mock the provider to return recommended payment methods.
		$this->mock_provider
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( $this->isInstanceOf( FakePaymentGateway::class ), $location )
			->willReturn( $recommended_pms );

		$result = $this->invoke_private_method( 'get_onboarding_payment_methods_state', array( $location, null ) );

		$this->assertArrayHasKey( 'apple_google', $result );
		$this->assertTrue( $result['apple_google'], 'apple_google should be true when using OR fallback logic (apple_pay=true || google_pay=false)' );
	}

	/**
	 * Test get_onboarding_payment_methods_state uses recommended values when no stored state.
	 *
	 * @return void
	 */
	public function test_get_onboarding_payment_methods_state_uses_recommended_when_no_stored_state() {
		$location        = 'US';
		$recommended_pms = array(
			array(
				'id'       => 'apple_pay',
				'enabled'  => true,
				'required' => false,
			),
			array(
				'id'       => 'google_pay',
				'enabled'  => false,
				'required' => false,
			),
		);

		// Create empty NOX profile data structure (no stored state).
		$nox_profile_data = array();

		$this->mock_nox_profile_option( $nox_profile_data );

		$this->mock_provider
			->expects( $this->once() )
			->method( 'get_recommended_payment_methods' )
			->with( $this->isInstanceOf( FakePaymentGateway::class ), $location )
			->willReturn( $recommended_pms );

		$result = $this->invoke_private_method( 'get_onboarding_payment_methods_state', array( $location, null ) );

		$this->assertArrayHasKey( 'apple_google', $result );
		$this->assertTrue( $result['apple_google'], 'apple_google should be true when using recommended values with OR logic (true || false = true)' );
	}

	/**
	 * Test get_onboarding_payment_methods_state prefers explicit stored apple_google over OR logic.
	 *
	 * @return void
	 */
	public function test_get_onboarding_payment_methods_state_prefers_explicit_apple_google() {
		$location        = 'US';
		$recommended_pms = array(
			array(
				'id'       => 'apple_pay',
				'enabled'  => true,
				'required' => false,
			),
			array(
				'id'       => 'google_pay',
				'enabled'  => true,
				'required' => false,
			),
		);
		// Create NOX profile data structure with explicit apple_google override.
		$nox_profile_data = array(
			'onboarding' => array(
				$location => array(
					'steps' => array(
						'payment_methods' => array(
							'data' => array(
								'payment_methods' => array(
									'apple_google' => 'false',
								),
							),
						),
					),
				),
			),
		);

		$this->mock_nox_profile_option( $nox_profile_data );

		$result = $this->invoke_private_method( 'get_onboarding_payment_methods_state', array( $location, $recommended_pms ) );
		$this->assertArrayHasKey( 'apple_google', $result );
		$this->assertFalse( $result['apple_google'], 'Explicit stored apple_google should take precedence over OR/recommended.' );
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
	 * Refuse the native projection marker write.
	 */
	private function mock_native_projection_marker_write_failure(): void {
		$this->mockable_proxy->register_function_mocks(
			array(
				'update_option' => static function ( $option_name, $value, $autoload = null ) {
					if ( self::PENDING_PAYMENT_METHODS_PROJECTION_OPTION === $option_name ) {
						return false;
					}

					return update_option( $option_name, $value, $autoload );
				},
			)
		);
	}

	/**
	 * Get native payment-method projection callbacks registered on account refresh.
	 *
	 * @return array<int,array{callback:array,priority:int}>
	 */
	private function get_refresh_projection_callbacks(): array {
		global $wp_filter;

		$projection_callbacks = array();
		$hook                 = $wp_filter['woocommerce_payments_account_refreshed'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return $projection_callbacks;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered_callback ) {
				$callback = $registered_callback['function'];
				if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && $callback[0] instanceof WooPaymentsService && 'maybe_project_pending_onboarding_payment_methods' === $callback[1] ) {
					$projection_callbacks[] = array(
						'callback' => $callback,
						'priority' => $priority,
					);
				}
			}
		}

		return $projection_callbacks;
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
				$this->enabled_at_platform_call                                     = get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] ?? null;

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
				$this->enabled_at_platform_call                        = get_option( WooPaymentsSettingsService::SETTINGS_OPTION )['upe_enabled_payment_method_ids'] ?? null;

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
		update_option( WooPaymentsSettingsService::SETTINGS_OPTION, $settings );
		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => $wallets_enabled ) );
		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => $wallets_enabled ) );

		$recorder = $this->record_wcpay_tracks_event_names();

		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_legacy_runtime(),
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
		$account_service->init( $this->mockable_proxy );

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

		update_option( WooPaymentsSettingsService::SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
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

		remove_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_project_pending_onboarding_payment_methods' ) );
		$this->sut = new WooPaymentsService();
		$this->init_sut(
			$this->mock_providers,
			$this->mockable_proxy,
			$adapter,
			$this->create_legacy_runtime(),
			$api_client,
			$account_service
		);

		return array(
			'api_client'      => $api_client,
			'account_service' => $account_service,
		);
	}

	/**
	 * Mock a working WPCOM connection.
	 *
	 * Required for onboarding step completion that depends on the WPCOM connection step.
	 */
	private function mock_working_wpcom_connection(): void {
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'is_connected' )
			->willReturn( true );
		$this->mock_wpcom_connection_manager
			->expects( $this->any() )
			->method( 'has_connected_owner' )
			->willReturn( true );
	}

	/**
	 * Mock a WooPayments account state for test-drive disable tests.
	 *
	 * @param bool $account_exists Whether the account is currently connected.
	 */
	private function mock_account_state( bool $account_exists ): void {
		$this->mock_provider
			->expects( $this->any() )
			->method( 'is_account_connected' )
			->willReturn( $account_exists );
		$this->mock_account_service
			->expects( $this->any() )
			->method( 'get_account_status_data' )
			->willReturn(
				array(
					'status'           => 'complete',
					'testDrive'        => true,
					'isLive'           => false,
					'paymentsEnabled'  => true,
					'detailsSubmitted' => true,
				)
			);
	}

	/**
	 * Mock NOX lock and profile options for test-drive disable tests.
	 *
	 * @param mixed &$lock_value      The current lock value.
	 * @param array &$lock_changes    Recorded lock writes.
	 * @param array &$updated_profile The current updated NOX profile.
	 * @param array $stored_profile   The initial stored NOX profile.
	 */
	private function mock_disable_test_account_option_state( &$lock_value, array &$lock_changes, array &$updated_profile, array $stored_profile = array() ): void {
		// Track writes explicitly rather than inferring them from a non-empty profile, so that an
		// intentional write of an empty profile is distinct from "never written" and reads do not
		// silently fall back to the stored profile.
		$profile_written = false;
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option'    => function ( $option_name, $default_value = null ) use ( &$lock_value, &$updated_profile, &$profile_written, $stored_profile ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						return $lock_value ?? $default_value;
					}
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $profile_written ? $updated_profile : $stored_profile;
					}

					return $default_value;
				},
				'update_option' => function ( $option_name, $value ) use ( &$lock_value, &$lock_changes, &$updated_profile, &$profile_written ) {
					if ( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY === $option_name ) {
						$lock_value     = $value;
						$lock_changes[] = $value;

						return true;
					}
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						$updated_profile = $value;
						$profile_written = true;

						return true;
					}

					return true;
				},
			)
		);
	}

	/**
	 * Assert that an onboarding step is recorded as completed in the NOX profile.
	 *
	 * @param array  $profile  The full NOX profile option value.
	 * @param string $location The onboarding location (ISO 3166-1 alpha-2 country code).
	 * @param string $step_id  The onboarding step ID that should be completed.
	 */
	private function assert_onboarding_step_completed( array $profile, string $location, string $step_id ): void {
		$steps = $profile['onboarding'][ $location ]['steps'] ?? array();

		$this->assertArrayHasKey(
			$step_id,
			$steps,
			sprintf( 'The "%s" onboarding step should be recorded on a successful disable.', $step_id )
		);
		$this->assertArrayHasKey(
			WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED,
			$steps[ $step_id ]['statuses'] ?? array(),
			sprintf( 'The "%s" onboarding step should be marked completed on a successful disable.', $step_id )
		);
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
	 * Helper method to invoke private methods for testing.
	 *
	 * @param string $method_name The name of the private method.
	 * @param array  $args        The arguments to pass to the method.
	 * @return mixed The result of the method call.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->sut );
		if ( ! $reflection->hasMethod( $method_name ) ) {
			$this->fail( sprintf( 'Method %s not found on %s', $method_name, get_class( $this->sut ) ) );
		}
		$method = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->sut, $args );
	}

	/**
	 * Helper method to mock data entry in the NOX profile.
	 *
	 * @param array $stored_data The step data to mock.
	 * @return void
	 */
	private function mock_nox_profile_option( array $stored_data ): void {
		$this->mockable_proxy->register_function_mocks(
			array(
				'get_option' => function ( $option_name, $fallback = false ) use ( $stored_data ) {
					if ( WooPaymentsService::NOX_PROFILE_OPTION_KEY === $option_name ) {
						return $stored_data;
					}
					return $fallback;
				},
			)
		);
	}

	/**
	 * Helper method to create a logger test double that captures log calls.
	 *
	 * @param array $captured_calls The captured log calls, by reference. Each captured call is
	 *                              an array with 'level', 'message', and 'context' keys.
	 *
	 * @return object The logger test double.
	 */
	private function mock_capturing_logger( array &$captured_calls ): object {
		return new class( $captured_calls ) {
			/**
			 * The captured log calls.
			 *
			 * @var array
			 */
			public $calls;

			/**
			 * Constructor.
			 *
			 * @param array $calls The captured log calls, by reference.
			 */
			public function __construct( array &$calls ) {
				$this->calls = &$calls;
			}

			/**
			 * Capture a log call of any level (e.g. warning, error).
			 *
			 * @param string $name      The log level method being called.
			 * @param array  $arguments The log message and context.
			 *
			 * @return void
			 */
			public function __call( string $name, array $arguments ) {
				$this->calls[] = array(
					'level'   => $name,
					'message' => $arguments[0] ?? '',
					'context' => $arguments[1] ?? array(),
				);
			}
		};
	}

	/**
	 * Initialize an onboarding adapter with its native collaborators.
	 *
	 * The adapter resolves them on first use, so the test doubles are set on the instance after init.
	 *
	 * @param WooPaymentsOnboardingAdapter $adapter         The adapter.
	 * @param WooPaymentsLegacyRuntime     $legacy_runtime  The legacy runtime.
	 * @param WooPaymentsProvider          $provider        The native provider.
	 * @param NativeWooPaymentsGateway     $native_gateway  The native gateway.
	 * @param WooPaymentsAccountService    $account_service The native account service.
	 * @param NativePaymentsRuntimeArbiter $arbiter         The runtime arbiter.
	 */
	private function init_adapter( WooPaymentsOnboardingAdapter $adapter, WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsProvider $provider, NativeWooPaymentsGateway $native_gateway, WooPaymentsAccountService $account_service, NativePaymentsRuntimeArbiter $arbiter ): void {
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
