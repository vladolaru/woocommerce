<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Shadow;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
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
		remove_all_filters( 'nonce_user_logged_out' );
		remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
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
	 * @testdox Shadow projection composes plan display metadata without applying order effects.
	 */
	public function test_shadow_projection_uses_plan_display_metadata_without_writes(): void {
		$nonce_filter_calls = 0;
		$title_filter_calls = 0;
		add_filter(
			'nonce_user_logged_out',
			static function ( int $user_id ) use ( &$nonce_filter_calls ): int {
				++$nonce_filter_calls;

				return $user_id;
			}
		);
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function ( string $suffix ) use ( &$title_filter_calls ): string {
				++$title_filter_calls;

				return $suffix;
			}
		);
		$logger = new class() {
			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				unset( $message, $context );
			}
		};
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => static function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order = $this->create_projected_woopayments_order( 'requires_action', 'pending' );
		$order->update_meta_data( '_wcpay_express_checkout_payment_method', 'apple_pay' );
		$order->save();
		$intent = $this->create_payment_intent_response( 'requires_action' );
		$intent['charges']['data'][0]['payment_method_details'] = array(
			'type' => 'card',
			'card' => array(
				'brand'         => 'visa',
				'display_brand' => 'visa',
				'funding'       => 'credit',
				'last4'         => '4242',
			),
		);
		$notes_before = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$comparison  = $this->create_shadow_mode( $this->create_recording_api_client( $intent ) )
			->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );
		$reloaded    = wc_get_order( $order->get_id() );
		$notes_after = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertSame( '4242', $comparison->get_native_computed()['meta']['last4'] );
		$this->assertSame( 'visa', $comparison->get_native_computed()['meta']['_card_brand'] );
		$this->assertStringContainsString( '"last4":"4242"', $comparison->get_native_computed()['meta']['_wcpay_payment_method_details'] );
		$this->assertArrayHasKey( 'meta.last4', $comparison->get_diff() );
		$this->assertArrayHasKey( 'meta._wcpay_express_checkout_payment_method', $comparison->get_diff() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', $reloaded->get_meta( 'last4', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertEmpty( $reloaded->get_payment_tokens() );
		$this->assertCount( count( $notes_before ), $notes_after );
		$this->assertSame( 0, $nonce_filter_calls, 'Shadow projection should not create customer-action nonces.' );
		$this->assertSame( 0, $title_filter_calls, 'Shadow projection should not render express titles.' );
	}

	/**
	 * @testdox Shadow projection derives matching express identity from provider wallet facts.
	 */
	public function test_shadow_projection_derives_express_identity_from_provider_facts(): void {
		$logger = new class() {
			/**
			 * Record a debug log entry.
			 *
			 * @param string $message Log message.
			 * @param array  $context Log context.
			 */
			public function debug( string $message, array $context = array() ): void {
				unset( $message, $context );
			}
		};
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => static function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order = $this->create_projected_woopayments_order( 'requires_action', 'pending' );
		$order->update_meta_data( '_wcpay_express_checkout_payment_method', 'apple_pay' );
		$order->save();
		$intent = $this->create_payment_intent_response( 'requires_action' );
		$intent['charges']['data'][0]['payment_method_details'] = array(
			'type' => 'card',
			'card' => array(
				'wallet' => array( 'type' => 'apple_pay' ),
			),
		);

		$comparison = $this->create_shadow_mode( $this->create_recording_api_client( $intent ) )
			->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertSame( 'apple_pay', $comparison->get_native_computed()['meta']['_wcpay_express_checkout_payment_method'] );
		$this->assertArrayNotHasKey( 'meta._wcpay_express_checkout_payment_method', $comparison->get_diff() );
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
			 * @param \WC_Order                  $order               Order to project.
			 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
			 * @return array<string,mixed>
			 */
			public function read_payment_surface( \WC_Order $order, ProviderPersistenceProfile $persistence_profile ): array {
				++$this->reads;

				return parent::read_payment_surface( $order, $persistence_profile );
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
	 * @testdox Refund shadow projections derive charge financial meta independently from provider data.
	 */
	public function test_refund_shadow_projection_preserves_plugin_persisted_charge_financial_meta(): void {
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

		$order = $this->create_projected_woopayments_order( 'succeeded', 'processing' );
		$order->update_meta_data( '_wcpay_transaction_fee', '1.75' );
		$order->update_meta_data( '_wcpay_net', '48.25' );
		$order->save();

		$intent = $this->create_payment_intent_response( 'succeeded' );
		$intent['charges']['data'][0]['fee_breakdown_v1'] = array(
			'totals' => array(
				'fee' => array(
					'amount'   => 175,
					'currency' => 'usd',
				),
				'net' => array(
					'amount'   => 2325,
					'currency' => 'usd',
				),
			),
		);

		$api_client = $this->create_recording_api_client( $intent );
		$sut        = $this->create_shadow_mode( $api_client );

		$comparison = $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'woocommerce_order_refunded' );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertArrayNotHasKey( 'meta._wcpay_transaction_fee', $comparison->get_diff() );
		$this->assertArrayHasKey( 'meta._wcpay_net', $comparison->get_diff() );
		$this->assertSame( '23.25', $comparison->get_diff()['meta._wcpay_net']['expected'] );
		$this->assertSame( '48.25', $comparison->get_diff()['meta._wcpay_net']['actual'] );
		$this->assertSame( '1.75', $comparison->get_native_computed()['meta']['_wcpay_transaction_fee'] );
		$this->assertSame( '23.25', $comparison->get_native_computed()['meta']['_wcpay_net'] );
	}

	/**
	 * @testdox Shadow projection reports persisted outcome meta that provider data does not produce.
	 */
	public function test_native_projection_does_not_inherit_unprojected_actual_meta(): void {
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
				'wc_get_logger' => static function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$order->update_meta_data( '_wcpay_fraud_outcome_status', 'allow' );
		$order->save();
		$sut = $this->create_shadow_mode( $this->create_recording_api_client( $this->create_payment_intent_response( 'requires_capture' ) ) );

		$comparison = $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' );

		$this->assertInstanceOf( ShadowComparison::class, $comparison );
		$this->assertArrayHasKey( 'meta._wcpay_fraud_outcome_status', $comparison->get_diff() );
		$this->assertNull( $comparison->get_diff()['meta._wcpay_fraud_outcome_status']['expected'] );
		$this->assertSame( 'allow', $comparison->get_diff()['meta._wcpay_fraud_outcome_status']['actual'] );
	}

	/**
	 * @testdox Shadow projection reads a historical test order from test mode after the store switches to live mode.
	 */
	public function test_native_projection_uses_preserved_order_mode_for_provider_read(): void {
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
				'wc_get_logger' => static function () use ( $logger ) {
					return $logger;
				},
			)
		);

		$order      = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$api_client = $this->create_recording_api_client( $this->create_payment_intent_response( 'requires_capture' ) );
		$sut        = $this->create_shadow_mode( $api_client, null, false );

		$this->assertInstanceOf( ShadowComparison::class, $sut->record_shadow_for_order( wc_get_order( $order->get_id() ), 'unit_test' ) );
		$this->assertSame( array( true ), $api_client->test_modes );
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

		$order = $this->create_projected_woopayments_order( 'requires_capture', 'on-hold' );
		$order->update_meta_data( '_wcpay_mode', 'live' );
		$order->save();
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
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

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
			 * Explicit test modes used for historical intent reads.
			 *
			 * @var bool[]
			 */
			public array $test_modes = array();

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

			/**
			 * Retrieve a WooPayments PaymentIntent in an explicit account mode.
			 *
			 * @param string $intent_id Intent ID.
			 * @param bool   $test_mode Whether to read test-mode data.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention_for_mode( string $intent_id, bool $test_mode ): array {
				$this->test_modes[] = $test_mode;

				return $this->get_payment_intention( $intent_id );
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
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'not_card' );
		$order->update_meta_data( '_wcpay_intent_currency', 'USD' );
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
