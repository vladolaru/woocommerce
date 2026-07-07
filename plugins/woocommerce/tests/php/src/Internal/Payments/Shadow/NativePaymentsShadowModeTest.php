<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Shadow;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Shadow\NativePaymentsShadowMode;
use Automattic\WooCommerce\Internal\Payments\Shadow\PaymentSurfaceDiffer;
use Automattic\WooCommerce\Internal\Payments\Shadow\ShadowComparison;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the NativePaymentsShadowMode class.
 */
class NativePaymentsShadowModeTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var NativePaymentsShadowMode
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( NativePaymentsShadowMode::class );
		$this->remove_shadow_hooks();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_shadow_hooks();
		remove_all_filters( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED );
		remove_all_filters( NativePaymentsShadowMode::FILTER_LOG_FULL_SURFACES );
		remove_all_filters( NativePaymentsShadowMode::FILTER_ALLOW_LIVE_READS );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		$this->reset_legacy_proxy_mocks();
		parent::tearDown();
	}

	/**
	 * Remove shadow hooks for this SUT.
	 */
	private function remove_shadow_hooks(): void {
		remove_action( 'woocommerce_payment_complete', array( $this->sut, 'handle_woocommerce_payment_complete' ), 100 );
		remove_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ), 100 );
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $active Whether the WooPayments plugin should appear active.
	 */
	private function fake_plugin( bool $active ): void {
		$entry = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $active, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $active ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $active;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);
	}

	/**
	 * @testdox Shadow mode registers no hooks by default.
	 */
	public function test_registers_no_hooks_by_default(): void {
		$this->fake_plugin( true );

		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_payment_complete', array( $this->sut, 'handle_woocommerce_payment_complete' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ) ) );
	}

	/**
	 * @testdox Shadow mode hooks only when explicitly enabled while the plugin owns the runtime.
	 */
	public function test_registers_hooks_only_when_enabled_and_plugin_owns_runtime(): void {
		$this->fake_plugin( true );
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );

		$this->sut->register();

		$this->assertSame( 100, has_action( 'woocommerce_payment_complete', array( $this->sut, 'handle_woocommerce_payment_complete' ) ) );
		$this->assertSame( 100, has_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ) ) );
	}

	/**
	 * @testdox Shadow mode does not hook after the native runtime takes ownership.
	 */
	public function test_does_not_register_hooks_when_native_owns_runtime(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );

		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_payment_complete', array( $this->sut, 'handle_woocommerce_payment_complete' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ) ) );
	}

	/**
	 * @testdox Shadow comparisons are logged out-of-band without mutating order payment state.
	 */
	public function test_records_same_store_shadow_comparison_without_mutating_order_payment_state(): void {
		$logger = new class() {
			/**
			 * Logged debug entries.
			 *
			 * @var array
			 */
			public $entries = array();

			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				$this->entries[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'requires_capture' ) );
		$sut        = $this->create_shadow_mode( $api_client );

		$before = wc_get_order( $order->get_id() )->get_meta( '_intent_id', true );

		$comparison = $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$after = wc_get_order( $order->get_id() )->get_meta( '_intent_id', true );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertSame( $order->get_id(), $comparison->get_order_id() );
		$this->assertSame( 'unit_test', $comparison->get_trigger() );
		$this->assertSame( array(), $comparison->get_diff() );
		$this->assertSame( $comparison->get_actual(), $comparison->get_native_computed() );
		$this->assertSame( $before, $after );
		$this->assertSame( 1, $api_client->reads );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( NativePaymentsShadowMode::LOG_SOURCE, $logger->entries[0]['context']['source'] );
		$this->assertStringContainsString( '"trigger":"unit_test"', $logger->entries[0]['message'] );

		$payload = json_decode( $logger->entries[0]['message'], true );

		$this->assertIsArray( $payload );
		$this->assertSame( 'unit_test', $payload['trigger'] );
		$this->assertSame( ShadowComparison::COMPARISON_TYPE_NATIVE_PROJECTION, $payload['comparison_type'] );
		$this->assertTrue( $payload['independent_native_computation'] );
		$this->assertFalse( $payload['has_diff'] );
		$this->assertArrayHasKey( 'actual_hash', $payload );
		$this->assertArrayHasKey( 'native_computed_hash', $payload );
		$this->assertArrayNotHasKey( 'actual', $payload );
		$this->assertArrayNotHasKey( 'native_computed', $payload );
	}

	/**
	 * @testdox Full shadow surfaces are logged only when the diagnostic filter is enabled.
	 */
	public function test_full_shadow_surfaces_are_logged_only_when_diagnostic_filter_is_enabled(): void {
		add_filter( NativePaymentsShadowMode::FILTER_LOG_FULL_SURFACES, '__return_true' );

		$logger = new class() {
			/**
			 * Logged debug entries.
			 *
			 * @var array
			 */
			public $entries = array();

			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				$this->entries[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'requires_capture' ) );
		$sut        = $this->create_shadow_mode( $api_client );

		$sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$payload = json_decode( $logger->entries[0]['message'], true );

		$this->assertIsArray( $payload );
		$this->assertSame( ShadowComparison::COMPARISON_TYPE_NATIVE_PROJECTION, $payload['comparison_type'] );
		$this->assertTrue( $payload['independent_native_computation'] );
		$this->assertSame( 'pi_shadow', $payload['actual']['meta']['_intent_id'] );
		$this->assertSame( 'pi_shadow', $payload['native_computed']['meta']['_intent_id'] );
	}

	/**
	 * @testdox Shadow mode ignores non-WooPayments orders.
	 */
	public function test_shadow_mode_ignores_non_woopayments_orders(): void {
		$logger = new class() {
			/**
			 * Logged debug entries.
			 *
			 * @var array
			 */
			public $entries = array();

			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				$this->entries[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order = wc_create_order();
		$order->set_payment_method( 'cheque' );
		$order->save();

		$this->assertNull( $this->sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' ) );

		$this->sut->handle_woocommerce_payment_complete( $order->get_id() );

		$this->assertSame( array(), $logger->entries );
	}

	/**
	 * @testdox Native projection fetches the provider intent and reuses the first payment surface read.
	 */
	public function test_native_projection_fetches_intent_and_reuses_first_payment_surface_projection(): void {
		$logger = new class() {
			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$store = new class() extends OrderPaymentStore {
			/**
			 * Number of read_payment_surface calls.
			 *
			 * @var int
			 */
			public $reads = 0;

			/**
			 * Read a stable, HPOS-safe projection of an order's payment surface.
			 *
			 * @param \WC_Order $order Order to project.
			 * @return array<string,mixed>
			 */
			public function read_payment_surface( \WC_Order $order ): array {
				++$this->reads;

				return parent::read_payment_surface( $order );
			}
		};

		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'requires_capture' ) );
		$sut        = $this->create_shadow_mode( $api_client, $store );
		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );

		$sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$this->assertSame( 1, $store->reads );
		$this->assertSame( 1, $api_client->reads );
	}

	/**
	 * @testdox Native projection reports a diff when plugin-written intent meta disagrees with the fetched provider intent.
	 */
	public function test_native_projection_reports_diff_when_plugin_written_intent_meta_disagrees_with_provider_intent(): void {
		$logger = new class() {
			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'completed' );
		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'succeeded' ) );
		$sut        = $this->create_shadow_mode( $api_client );

		$comparison = $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertArrayHasKey( 'meta._intention_status', $comparison->get_diff() );
		$this->assertSame( 'succeeded', $comparison->get_diff()['meta._intention_status']['expected'] );
		$this->assertSame( 'requires_capture', $comparison->get_diff()['meta._intention_status']['actual'] );
		$this->assertNotSame( $comparison->get_actual(), $comparison->get_native_computed() );
	}

	/**
	 * @testdox Shadow projection skips live provider reads unless explicitly opted in.
	 */
	public function test_native_projection_skips_live_provider_reads_unless_explicitly_enabled(): void {
		$logger = new class() {
			/**
			 * Logged debug entries.
			 *
			 * @var array
			 */
			public $entries = array();

			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				$this->entries[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};

		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'succeeded' ) );
		$sut        = $this->create_shadow_mode( $api_client, null, false );

		$this->assertNull( $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' ) );
		$this->assertSame( 0, $api_client->reads );
		$this->assertSame( array(), $logger->entries );

		add_filter( NativePaymentsShadowMode::FILTER_ALLOW_LIVE_READS, '__return_true' );

		$api_client_with_live_opt_in = $this->create_recording_api_client( $this->create_payment_intent_response( 'succeeded' ) );
		$sut_with_live_opt_in        = $this->create_shadow_mode( $api_client_with_live_opt_in, null, false );

		$this->assertInstanceOf( ShadowComparison::class, $sut_with_live_opt_in->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' ) );
		$this->assertSame( 1, $api_client_with_live_opt_in->reads );
		$this->assertCount( 1, $logger->entries );
	}

	/**
	 * Create a shadow-mode instance with deterministic provider dependencies.
	 *
	 * @param WooPaymentsApiClient   $api_client   API client.
	 * @param OrderPaymentStore|null $store       Optional payment store.
	 * @param bool                   $test_mode    Whether the account is in test mode.
	 * @return NativePaymentsShadowMode
	 */
	private function create_shadow_mode( WooPaymentsApiClient $api_client, ?OrderPaymentStore $store = null, bool $test_mode = true ): NativePaymentsShadowMode {
		$sut = new NativePaymentsShadowMode();
		$sut->init(
			wc_get_container()->get( NativePaymentsRuntimeArbiter::class ),
			$store ?? wc_get_container()->get( OrderPaymentStore::class ),
			new PaymentSurfaceDiffer(),
			wc_get_container()->get( LegacyProxy::class ),
			$api_client,
			new WooPaymentsPersistenceProfile(),
			$this->create_account_service( $test_mode ),
			new WooPaymentsOrderDataService()
		);

		return $sut;
	}

	/**
	 * Create an account service mock for shadow projection tests.
	 *
	 * @param bool $test_mode Whether the account is in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_account_default_currency' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );

		return $account_service;
	}

	/**
	 * Create a recording WooPayments API client.
	 *
	 * @param array<string,mixed> $intent_response PaymentIntent response.
	 * @return WooPaymentsApiClient
	 */
	private function create_recording_api_client( array $intent_response ): WooPaymentsApiClient {
		return new class( $intent_response ) extends WooPaymentsApiClient {
			/**
			 * Number of provider intent reads.
			 *
			 * @var int
			 */
			public int $reads = 0;

			/**
			 * PaymentIntent response.
			 *
			 * @var array<string,mixed>
			 */
			private array $intent_response;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $intent_response PaymentIntent response.
			 */
			public function __construct( array $intent_response ) {
				$this->intent_response = $intent_response;
			}

			/**
			 * Tell whether the API client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a WooPayments PaymentIntent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				++$this->reads;

				return $this->intent_response;
			}
		};
	}

	/**
	 * Create a persisted WooPayments order surface for projection tests.
	 *
	 * @param string $intention_status Persisted intention status.
	 * @param string $order_status     WooCommerce order status.
	 * @return WC_Order
	 */
	private function create_projected_woopayments_order( string $intention_status, string $order_status ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '50.00' );
		$order->set_transaction_id( 'pi_shadow' );
		$order->set_status( $order_status );
		$order->update_meta_data( '_intent_id', 'pi_shadow' );
		$order->update_meta_data( '_payment_method_id', 'pm_shadow' );
		$order->update_meta_data( '_charge_id', 'ch_shadow' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_shadow' );
		$order->update_meta_data( '_intention_status', $intention_status );
		$order->update_meta_data( '_wcpay_intent_currency', 'usd' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', 'txn_shadow' );
		$order->save();

		return $order;
	}

	/**
	 * Create a WooPayments PaymentIntent response.
	 *
	 * @param string $status Intent status.
	 * @return array<string,mixed>
	 */
	private function create_payment_intent_response( string $status ): array {
		return array(
			'id'             => 'pi_shadow',
			'status'         => $status,
			'client_secret'  => 'secret_shadow',
			'customer'       => 'cus_shadow',
			'payment_method' => 'pm_shadow',
			'currency'       => 'usd',
			'charges'        => array(
				'data' => array(
					array(
						'id'                  => 'ch_shadow',
						'payment_method'      => 'pm_shadow',
						'balance_transaction' => array( 'id' => 'txn_shadow' ),
					),
				),
			),
		);
	}
}
