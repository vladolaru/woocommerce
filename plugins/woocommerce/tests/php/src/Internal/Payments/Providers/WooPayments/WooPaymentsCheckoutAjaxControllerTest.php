<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAjaxController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCheckoutAjaxController class.
 */
class WooPaymentsCheckoutAjaxControllerTest extends WC_Unit_Test_Case {

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_currency = (string) get_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'wp_ajax_update_order_status' );
		remove_all_actions( 'wp_ajax_nopriv_update_order_status' );
		remove_all_actions( 'wp_ajax_create_setup_intent' );
		remove_all_filters( 'woocommerce_woopayments_is_recurring_payment' );
		remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
		remove_all_filters( 'woocommerce_payment_token_class' );
		unset( $GLOBALS['wcpay_test_order_subscription_relationships'], $GLOBALS['wcpay_test_renewal_order_ids'] );
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'reset' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
		}
		wp_set_current_user( 0 );
		update_option( 'woocommerce_currency', $this->original_currency );
		parent::tearDown();
	}

	/**
	 * @testdox Should register AJAX callbacks even when transport readiness is still deferred.
	 */
	public function test_registers_callbacks_when_transport_is_unavailable_at_boot(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return false;
			}
		};
		$sut        = $this->create_controller( $api_client );

		$sut->register();

		$this->assertSame( 10, has_action( 'wp_ajax_update_order_status', array( $sut, 'handle_update_order_status' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_nopriv_update_order_status', array( $sut, 'handle_update_order_status' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_create_setup_intent', array( $sut, 'handle_create_setup_intent' ) ) );
	}

	/**
	 * @testdox Shared intent confirmation should apply the native payment lifecycle directly.
	 */
	public function test_confirm_intent_for_order_applies_native_payment_lifecycle(): void {
		$order = $this->create_woopayments_order( '50.00' );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				return array(
					'id'             => $intent_id,
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 5000,
					'customer'       => 'cus_shared',
					'payment_method' => 'pm_shared',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'             => 'ch_shared',
								'payment_method' => 'pm_shared',
							),
						),
					),
				);
			}
		};
		$sut        = $this->create_controller( $api_client );

		$sut->confirm_intent_for_order( $order, 'pi_shared', false );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pi_shared', $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_shared', $reloaded->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Order-status callback should complete a zero-total order from the native SetupIntent.
	 */
	public function test_update_order_status_completes_zero_total_setup_intent(): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_native' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_native',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'return_url', $response );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'seti_native', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_native', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_native', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assert_order_has_no_note_containing( $order, 'A payment of' );
		$this->assert_order_has_no_note_containing( $order, 'A test payment of' );
	}

	/**
	 * @testdox Zero-total SetupIntent callbacks preserve supported SEPA title and token identity.
	 */
	public function test_update_order_status_preserves_zero_total_setup_intent_sepa_title(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$order = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_currency( 'EUR' );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID . '_sepa_debit' );
		$order->update_meta_data( '_intent_id', 'seti_sepa_title' );
		$order->save();
		$api_client      = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve the succeeded SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				return array(
					'id'             => $setup_intent_id,
					'status'         => 'succeeded',
					'customer'       => 'cus_sepa',
					'payment_method' => 'pm_sepa',
				);
			}
		};
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( array( 'card', 'sepa_debit' ) );
		$account_service->method( 'get_account_country' )->willReturn( 'DE' );
		$token_service   = $this->create_token_service(
			array(
				'pm_sepa' => array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array(
						'last4' => '6789',
					),
				),
			),
			$account_service
		);
		$token_class_map = new WooPaymentsTokenClassMapController();
		$token_class_map->init( new StaticNativeRuntimeArbiter( true ) );
		$token_class_map->register();
		$sut = $this->create_controller( $api_client, null, $token_service, $account_service );
		add_filter( 'woocommerce_woopayments_is_recurring_payment', '__return_true' );
		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_sepa_title',
			)
		);
		$order    = wc_get_order( $order->get_id() );
		$tokens   = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID . '_sepa_debit' ) );

		$this->assertSame( 200, $response['status_code'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID . '_sepa_debit', $order->get_payment_method() );
		$this->assertSame( 'SEPA Direct Debit', $order->get_payment_method_title() );
		$this->assertSame( 'pm_sepa', $order->get_meta( '_payment_method_id', true ) );
		$this->assertCount( 1, $tokens );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $tokens[0] );
		$this->assertSame( 'pm_sepa', $tokens[0]->get_token() );
		$this->assertSame( array( $tokens[0]->get_id() ), array_values( $order->get_payment_tokens() ) );
	}

	/**
	 * @testdox Zero-total SetupIntent callbacks expose card identity before lifecycle hooks.
	 */
	public function test_update_order_status_exposes_zero_total_setup_intent_card_identity_before_lifecycle(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'Card' );
		$order->update_meta_data( '_intent_id', 'seti_card_identity' );
		$order->save();
		$subscription = $this->create_woopayments_order( '0.00' );
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);

		$api_client           = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve the confirmed SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_card_identity' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_card_identity',
					'status'         => 'succeeded',
					'customer'       => 'cus_card_identity',
					'payment_method' => 'pm_card_identity',
				);
			}
		};
		$token_service        = $this->create_token_service(
			array(
				'pm_card_identity' => array(
					'id'   => 'pm_card_identity',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'funding'   => 'credit',
						'last4'     => '4242',
						'network'   => 'visa',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);
		$sut                  = $this->create_controller( $api_client, null, $token_service );
		$observed_identity    = array();
		$record_payment_state = function ( int $order_id ) use ( $order, $subscription, &$observed_identity ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$observed_order        = wc_get_order( $order_id );
			$observed_subscription = wc_get_order( $subscription->get_id() );
			$observed_identity     = array(
				'title'              => $observed_order instanceof WC_Order ? $observed_order->get_payment_method_title() : '',
				'last4'              => $observed_order instanceof WC_Order ? $observed_order->get_meta( 'last4', true ) : '',
				'brand'              => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_card_brand', true ) : '',
				'subscription_title' => $observed_subscription instanceof WC_Order ? $observed_subscription->get_payment_method_title() : '',
			);
		};
		add_action( 'woocommerce_payment_complete', $record_payment_state, 1, 1 );

		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'seti_card_identity',
					'should_save_payment_method' => 'true',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $record_payment_state, 1 );
		}

		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'Visa credit card', $observed_identity['title'] ?? '' );
		$this->assertSame( '4242', $observed_identity['last4'] ?? '' );
		$this->assertSame( 'visa', $observed_identity['brand'] ?? '' );
		$this->assertSame( 'Visa credit card', $observed_identity['subscription_title'] ?? '' );
	}

	/**
	 * @testdox Saved recurring credentials complete once with generic identity when display details are unavailable.
	 * @dataProvider unavailable_saved_setup_intent_display_details_data
	 *
	 * @param bool $throw_details_lookup Whether the display-details lookup throws.
	 */
	public function test_saved_recurring_setup_intent_uses_generic_identity_when_display_details_are_unavailable( bool $throw_details_lookup ): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$token        = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_saved_unavailable' );
		$order        = $this->create_woopayments_order( '0.00' );
		$subscription = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'Visa credit card' );
		$order->update_meta_data( '_intent_id', 'seti_saved_unavailable' );
		$order->update_meta_data( '_payment_method_id', 'pm_saved_unavailable' );
		$order->update_meta_data( 'last4', '9999' );
		$order->update_meta_data( '_card_brand', 'mastercard' );
		$order->update_meta_data( '_wcpay_payment_method_details', '{"type":"card","card":{"last4":"9999"}}' );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'Visa credit card' );
		$subscription->save();
		$details_reads  = new \ArrayObject( array( 0 ) );
		$api_client     = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve the succeeded SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				return array(
					'id'             => $setup_intent_id,
					'status'         => 'succeeded',
					'customer'       => 'cus_saved_unavailable',
					'payment_method' => 'pm_saved_unavailable',
				);
			}
		};
		$sut            = $this->create_controller( $api_client, null, $this->create_token_service( array(), null, $details_reads, $throw_details_lookup ) );
		$lifecycle_runs = 0;
		$observer       = static function ( int $order_id ) use ( $order, &$lifecycle_runs ): void {
			if ( $order->get_id() === $order_id ) {
				++$lifecycle_runs;
			}
		};
		add_filter( 'woocommerce_woopayments_is_recurring_payment', '__return_true' );
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);
		add_action( 'woocommerce_payment_complete', $observer, 1 );

		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'    => $order->get_id(),
					'intent_id'   => 'seti_saved_unavailable',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer, 1 );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 1, $lifecycle_runs );
		$this->assertSame( 'Card', $order->get_payment_method_title() );
		$this->assertSame( 'Card', $subscription->get_payment_method_title() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertSame( array( $token->get_id() ), array_values( $subscription->get_payment_tokens() ) );
		$this->assertSame( 1, $details_reads[0] );
	}

	/**
	 * Provide unavailable display-details lookup modes.
	 *
	 * @return array<string,array{bool}>
	 */
	public function unavailable_saved_setup_intent_display_details_data(): array {
		return array(
			'empty response' => array( false ),
			'thrown lookup'  => array( true ),
		);
	}

	/**
	 * @testdox Sequential SetupIntent callbacks complete once and reuse valid same-method display details.
	 */
	public function test_sequential_setup_intent_callbacks_complete_once_and_reuse_same_method_display_details(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$token        = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_callback_replay' );
		$order        = $this->create_woopayments_order( '0.00' );
		$subscription = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'Card' );
		$order->update_meta_data( '_intent_id', 'seti_callback_replay' );
		$order->update_meta_data( '_payment_method_id', 'pm_callback_replay' );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();
		$details        = array(
			'id'   => 'pm_callback_replay',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'network'   => 'visa',
				'funding'   => 'credit',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$details_reads  = new \ArrayObject( array( 0 ) );
		$api_client     = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve the succeeded SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				return array(
					'id'             => $setup_intent_id,
					'status'         => 'succeeded',
					'customer'       => 'cus_callback_replay',
					'payment_method' => 'pm_callback_replay',
				);
			}
		};
		$sut            = $this->create_controller( $api_client, null, $this->create_token_service( array( 'pm_callback_replay' => $details ), null, $details_reads ) );
		$lifecycle_runs = 0;
		$observer       = static function ( int $order_id ) use ( $order, &$lifecycle_runs ): void {
			if ( $order->get_id() === $order_id ) {
				++$lifecycle_runs;
			}
		};
		add_filter( 'woocommerce_woopayments_is_recurring_payment', '__return_true' );
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);
		add_action( 'woocommerce_payment_complete', $observer, 1 );
		$request = array(
			'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
			'order_id'    => $order->get_id(),
			'intent_id'   => 'seti_callback_replay',
		);

		try {
			$first_response  = $sut->get_update_order_status_response( $request );
			$second_response = $sut->get_update_order_status_response( $request );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer, 1 );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 200, $first_response['status_code'] );
		$this->assertSame( 200, $second_response['status_code'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 1, $lifecycle_runs );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
		$this->assertSame( 'Visa credit card', $subscription->get_payment_method_title() );
		$this->assertSame( '4242', $order->get_meta( 'last4', true ) );
		$this->assertSame( 'visa', $order->get_meta( '_card_brand', true ) );
		$this->assertSame(
			array(
				'type' => 'card',
				'card' => $details['card'],
			),
			json_decode( (string) $order->get_meta( '_wcpay_payment_method_details', true ), true )
		);
		$this->assertSame( '', $order->get_meta( '_wcpay_raw_payment_method_details', true ) );
		$this->assertSame( '', $order->get_meta( 'id', true ) );
		$this->assertSame( '', $order->get_meta( 'customer', true ) );
		$this->assertSame( '', $subscription->get_meta( 'last4', true ) );
		$this->assertSame( '', $subscription->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_raw_payment_method_details', true ) );
		$this->assertSame( 'pm_callback_replay', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'pm_callback_replay', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_callback_replay', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cus_callback_replay', $subscription->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'seti_callback_replay', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertSame( array( $token->get_id() ), array_values( $subscription->get_payment_tokens() ) );
		$this->assertSame( 1, $details_reads[0] );
	}

	/**
	 * @testdox Failed intent lifecycle construction does not persist a generic title before lifecycle ownership.
	 */
	public function test_failed_intent_does_not_persist_effects_before_lifecycle_application(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->set_payment_method_title( 'Card' );
		$order->update_meta_data( '_intent_id', 'pi_failed' );
		$order->save();

		$api_client        = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return a failed PaymentIntent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				return array(
					'id'                 => $intent_id,
					'status'             => 'requires_payment_method',
					'last_payment_error' => array( 'code' => 'card_declined' ),
				);
			}
		};
		$lifecycle_service = $this->getMockBuilder( OrderPaymentLifecycleService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'apply' ) )
			->getMock();
		$lifecycle_service->expects( $this->once() )
			->method( 'apply' )
			->willThrowException( new \RuntimeException( 'Lifecycle unavailable.' ) );

		$response = $this->create_controller( $api_client, null, null, null, $lifecycle_service )
			->get_update_order_status_response(
				array(
					'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'    => $order->get_id(),
					'intent_id'   => 'pi_failed',
				)
			);
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 500, $response['status_code'] );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'Card', $reloaded->get_payment_method_title() );
	}

	/**
	 * @testdox An unrelated runtime exception with code 409 should retain the generic 500 response.
	 */
	public function test_unrelated_runtime_exception_with_409_code_returns_generic_error(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->update_meta_data( '_intent_id', 'pi_runtime_conflict' );
		$order->save();

		$api_client        = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return an authorized PaymentIntent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				return array(
					'id'     => $intent_id,
					'status' => 'succeeded',
				);
			}
		};
		$lifecycle_service = $this->getMockBuilder( OrderPaymentLifecycleService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'apply' ) )
			->getMock();
		$lifecycle_service->expects( $this->once() )
			->method( 'apply' )
			->willThrowException( new \RuntimeException( 'Unrelated lifecycle conflict.', 409 ) );

		$response = $this->create_controller( $api_client, null, null, null, $lifecycle_service )
			->get_update_order_status_response(
				array(
					'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'    => $order->get_id(),
					'intent_id'   => 'pi_runtime_conflict',
				)
			);

		$this->assertSame( 500, $response['status_code'] );
		$this->assertSame(
			__( "We're not able to process this payment. Please try again later.", 'woocommerce' ),
			$response['error']['message']
		);
	}

	/**
	 * @testdox SetupIntent lifecycle construction leaves setup metadata to the lifecycle owner.
	 */
	public function test_setup_intent_does_not_persist_meta_before_lifecycle_application(): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_read_only' );
		$order->save();

		$api_client        = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return a completed SetupIntent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $intent_id ): array {
				return array(
					'id'             => $intent_id,
					'status'         => 'succeeded',
					'customer'       => 'cus_read_only',
					'payment_method' => 'pm_read_only',
				);
			}
		};
		$lifecycle_service = $this->getMockBuilder( OrderPaymentLifecycleService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'apply' ) )
			->getMock();
		$lifecycle_service->expects( $this->once() )
			->method( 'apply' )
			->willThrowException( new \RuntimeException( 'Lifecycle unavailable.' ) );

		$response = $this->create_controller( $api_client, null, null, null, $lifecycle_service )
			->get_update_order_status_response(
				array(
					'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'    => $order->get_id(),
					'intent_id'   => 'seti_read_only',
				)
			);
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 500, $response['status_code'] );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_wcpay_mode', true ) );
	}

	/**
	 * @testdox Order-status callback should use the Core-owned account service for lifecycle mode metadata.
	 */
	public function test_update_order_status_uses_account_service_mode_for_lifecycle_meta(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->update_meta_data( '_intent_id', 'pi_native' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 5000,
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_native',
								'payment_method'         => 'pm_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
										'network' => 'visa',
									),
								),
								'balance_transaction'    => array( 'id' => 'txn_native' ),
								'outcome'                => array( 'risk_level' => 'normal' ),
								'amount'                 => 5000,
								'currency'               => 'usd',
								'application_fee_amount' => 218,
								'fee_breakdown_v1'       => array(
									'totals' => array(
										'fee' => array(
											'amount'   => 175,
											'currency' => 'usd',
										),
										'net' => array(
											'amount'   => 4825,
											'currency' => 'usd',
										),
									),
								),
							),
						),
					),
				);
			}
		};
		$sut        = $this->create_controller( $api_client, null, null, $this->create_account_service( true ) );

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'pi_native',
			)
		);
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'test', $order->get_meta( '_wcpay_mode', true ) );
		$this->assertSame( '1.75', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '48.25', $order->get_meta( '_wcpay_net', true ) );
		$this->assertFalse( $order->meta_exists( '_wcpay_fraud_outcome_status' ) );
		$this->assertFalse( $order->meta_exists( '_wcpay_fraud_meta_box_type' ) );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
		$this->assertSame( '4242', $order->get_meta( 'last4', true ) );
		$this->assertSame( 'visa', $order->get_meta( '_card_brand', true ) );
		$this->assertStringContainsString( '"last4":"4242"', (string) $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assert_order_has_note_containing( $order, 'A test payment of' );
		$this->assert_order_has_note_containing( $order, 'was processed using WooPayments in <strong>test mode</strong>' );
		$this->assert_order_has_note_containing( $order, 'No real funds were collected' );
		$this->assert_order_has_no_note_containing( $order, 'was <strong>successfully charged</strong> using WooPayments' );
		$this->assert_order_has_note_containing( $order, 'pi_native' );
		$this->assert_order_has_note_containing( $order, 'page=wc-admin' );
		$this->assert_order_has_note_containing( $order, 'id=pi_native' );
		$this->assert_order_has_no_note_containing( $order, '/woopayments/transactions/details' );
		$this->assert_order_has_no_note_containing( $order, 'transaction_id=txn_native' );
	}

	/**
	 * @testdox Order-status callback should preserve non-card payment method titles.
	 */
	public function test_update_order_status_preserves_non_card_payment_method_title(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->update_meta_data( '_intent_id', 'pi_klarna' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_klarna' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_klarna',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 5000,
					'customer'       => 'cus_native',
					'payment_method' => 'pm_klarna',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_klarna',
								'payment_method'         => 'pm_klarna',
								'payment_method_details' => array(
									'type'   => 'klarna',
									'klarna' => array(),
								),
							),
						),
					),
				);
			}
		};
		$sut        = $this->create_controller( $api_client );

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'pi_klarna',
			)
		);
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'Klarna', $order->get_payment_method_title() );
		$this->assertStringContainsString( '"type":"klarna"', (string) $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertFalse( $order->meta_exists( '_wcpay_fraud_outcome_status' ) );
		$this->assertSame( 'not_card', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
	}

	/**
	 * @testdox Order-status callback should persist settlement exchange-rate meta for converted-currency native charges.
	 */
	public function test_update_order_status_persists_settlement_exchange_rate_meta_for_converted_currency_charge(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_woopayments_order( '40.00' );
		$order->set_currency( 'GBP' );
		$order->update_meta_data( '_intent_id', 'pi_converted' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_converted' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_converted',
					'status'         => 'succeeded',
					'currency'       => 'gbp',
					'amount'         => 4000,
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                  => 'ch_converted',
								'payment_method'      => 'pm_native',
								'balance_transaction' => array(
									'id'            => 'txn_converted',
									'exchange_rate' => 1.33127,
								),
								'amount'              => 4000,
								'currency'            => 'gbp',
							),
						),
					),
				);
			}
		};
		$sut        = $this->create_controller( $api_client, null, null, $this->create_account_service( true, 'usd' ) );

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'pi_converted',
			)
		);
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( '1.33127', $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
	}

	/**
	 * @testdox Order-status callback should reject mismatched intent IDs before transport reads.
	 */
	public function test_update_order_status_rejects_mismatched_intent_id(): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_expected' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double must fail if the transport is called.
			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				unset( $setup_intent_id );
				throw new \RuntimeException( 'Intent mismatch should not call the transport.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_other',
			)
		);

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 409, $response['status_code'] );
	}

	/**
	 * @testdox Order-status callback should reject requests without a valid nonce before mutating the order.
	 * @dataProvider provider_update_order_status_invalid_nonce
	 *
	 * @param array<string,mixed> $nonce_overrides Request overrides carrying the missing or invalid nonce.
	 */
	public function test_update_order_status_rejects_request_without_valid_nonce( array $nonce_overrides ): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double must fail if the transport is called.
			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				unset( $setup_intent_id );
				throw new \RuntimeException( 'A request without a valid nonce must not reach the transport.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut        = $this->create_controller( $api_client );

		$response = $sut->get_update_order_status_response(
			array_merge(
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => 'seti_native',
				),
				$nonce_overrides
			)
		);
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertArrayNotHasKey( 'return_url', $response );
		$this->assertSame( 403, $response['status_code'] );
		$this->assertNotSame( 'completed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'A request without a valid nonce must not mutate the order.' );
	}

	/**
	 * Data provider for order-status callbacks that omit or corrupt the AJAX nonce.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provider_update_order_status_invalid_nonce(): array {
		return array(
			'missing nonce' => array( array() ),
			'invalid nonce' => array( array( '_ajax_nonce' => 'not-a-valid-nonce' ) ),
		);
	}

	/**
	 * @testdox Order-status callback should reject an authenticated user acting on another customer's order.
	 */
	public function test_update_order_status_rejects_cross_customer_order_access(): void {
		$owner_id    = $this->factory()->user->create();
		$attacker_id = $this->factory()->user->create();

		$order = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $owner_id );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		wp_set_current_user( $attacker_id );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double must fail if the transport is called.
			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				unset( $setup_intent_id );
				throw new \RuntimeException( 'Cross-customer access should not call the transport.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_native',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertArrayNotHasKey( 'return_url', $response );
		$this->assertSame( 403, $response['status_code'] );
		$this->assertNotSame( 'completed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'A cross-customer caller must not mutate the order.' );
	}

	/**
	 * @testdox Order-status callback should allow the owning customer to complete their own order.
	 */
	public function test_update_order_status_allows_owner_to_complete_own_order(): void {
		$owner_id = $this->factory()->user->create();

		$order = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $owner_id );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		wp_set_current_user( $owner_id );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_native' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_native',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'return_url', $response );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox Order-status callback should allow a guest to complete an unowned order via the nopriv flow.
	 */
	public function test_update_order_status_allows_guest_to_complete_unowned_order(): void {
		wp_set_current_user( 0 );

		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_native' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_native',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'return_url', $response );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox Order-status callback should allow an authenticated user to complete a guest order they do not own.
	 */
	public function test_update_order_status_allows_authenticated_user_on_guest_order(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_native' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'seti_native',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'return_url', $response );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox Order-status callback should preserve the payment-started surface for customer-action intents.
	 */
	public function test_update_order_status_preserves_payment_started_surface_for_customer_action_intent(): void {
		$order = $this->create_woopayments_order( '65.00' );
		$order->update_meta_data( '_intent_id', 'pi_requires_action' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_requires_action' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'                   => 'pi_requires_action',
					'status'               => 'requires_action',
					'currency'             => 'usd',
					'customer'             => 'cus_native',
					'payment_method'       => 'pm_native',
					'payment_method_types' => array( 'wechat_pay' ),
				);
			}
		};
		$sut        = $this->create_controller( $api_client, null, null, $this->create_account_service( true ) );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'pi_requires_action',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 409, $response['status_code'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_requires_action', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pm_native', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_native', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'USD', $order->get_meta( '_wcpay_intent_currency', true ) );
		$this->assertSame( 'test', $order->get_meta( '_wcpay_mode', true ) );
		$this->assertFalse( $order->meta_exists( '_wcpay_payment_method_details' ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$this->assertSame( 'not_card', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assert_order_has_note_containing( $order, 'A payment of' );
		$this->assert_order_has_note_containing( $order, 'was <strong>started</strong> using WooPayments' );
		$this->assert_order_has_note_containing( $order, 'pi_requires_action' );
	}

	/**
	 * @testdox Order-status callback should reject non-authorized intent statuses after syncing the order.
	 */
	public function test_update_order_status_rejects_non_authorized_intent_status(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->update_meta_data( '_intent_id', 'pi_requires_payment_method' );
		$order->save();

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_requires_payment_method' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_requires_payment_method',
					'status'         => 'requires_payment_method',
					'currency'       => 'usd',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$sut        = $this->create_controller( $api_client );
		$response   = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => 'pi_requires_payment_method',
			)
		);
		$order      = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertArrayNotHasKey( 'return_url', $response );
		$this->assertSame( 409, $response['status_code'] );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox Order-status callback should save requested cards before completing the order.
	 */
	public function test_update_order_status_saves_requested_card_token_before_completing_order(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'WooPayments' );
		$order->update_meta_data( '_intent_id', 'pi_native' );
		$order->save();

		$api_client    = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_native',
								'payment_method'         => 'pm_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
										'network' => 'visa',
									),
								),
							),
						),
					),
				);
			}
		};
		$token_service = $this->create_token_service(
			array(
				'pm_native' => array(
					'id'   => 'pm_native',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);
		$sut           = $this->create_controller( $api_client, null, $token_service );

		$status_at_token_attach          = '';
		$payment_complete_observations   = array();
		$payment_complete_observer_count = 0;
		$record_order_status             = function ( int $order_id ) use ( &$status_at_token_attach ): void {
			$order = wc_get_order( $order_id );

			$status_at_token_attach = $order instanceof WC_Order ? $order->get_status() : '';
		};
		$record_payment_complete         = function ( int $order_id ) use ( $order, $user_id, &$payment_complete_observations, &$payment_complete_observer_count ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$observed_order = wc_get_order( $order_id );
			$tokens         = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );
			$token          = $tokens[0] ?? null;

			++$payment_complete_observer_count;
			$payment_complete_observations = array(
				'gateway'        => $observed_order instanceof WC_Order ? $observed_order->get_payment_method() : '',
				'title'          => $observed_order instanceof WC_Order ? $observed_order->get_payment_method_title() : '',
				'last4'          => $observed_order instanceof WC_Order ? $observed_order->get_meta( 'last4', true ) : '',
				'brand'          => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_card_brand', true ) : '',
				'details'        => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_wcpay_payment_method_details', true ) : '',
				'raw_details'    => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_wcpay_raw_payment_method_details', true ) : '',
				'payment_method' => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_payment_method_id', true ) : '',
				'tokens'         => $tokens,
				'token'          => $token,
			);
		};
		add_action( 'woocommerce_payment_token_added_to_order', $record_order_status, 10, 1 );
		add_action( 'woocommerce_payment_complete', $record_payment_complete, 1, 1 );

		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'pi_native',
					'should_save_payment_method' => 'true',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_token_added_to_order', $record_order_status, 10 );
			remove_action( 'woocommerce_payment_complete', $record_payment_complete, 1 );
		}

		$order  = wc_get_order( $order->get_id() );
		$tokens = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );
		$token  = $tokens[0] ?? null;

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertNotSame( 'completed', $status_at_token_attach, 'The token must be attached before the lifecycle service completes the order.' );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertSame( 'pm_native', $token->get_token() );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens() );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertSame( 1, $payment_complete_observer_count );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $payment_complete_observations['gateway'] );
		$this->assertSame( 'Visa credit card', $payment_complete_observations['title'] );
		$this->assertSame( '4242', $payment_complete_observations['last4'] );
		$this->assertSame( 'visa', $payment_complete_observations['brand'] );
		$this->assertStringContainsString( '"last4":"4242"', (string) $payment_complete_observations['details'] );
		$this->assertSame( '', $payment_complete_observations['raw_details'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['payment_method'] );
		$this->assertCount( 1, $payment_complete_observations['tokens'] );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $payment_complete_observations['token'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['token']->get_token() );
		$this->assertSame( 'visa', $payment_complete_observations['token']->get_card_type() );
		$this->assertSame( '4242', $payment_complete_observations['token']->get_last4() );

		add_action( 'woocommerce_payment_complete', $record_payment_complete, 1, 1 );
		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'pi_native',
					'should_save_payment_method' => 'true',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $record_payment_complete, 1 );
		}

		$order        = wc_get_order( $order->get_id() );
		$tokens       = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );
		$active_token = $order instanceof WC_Order ? $token_service->get_active_token_for_order( $order ) : null;

		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 1, $payment_complete_observer_count );
		$this->assertCount( 1, $tokens );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $active_token );
		$this->assertSame( 'pm_native', $active_token->get_token() );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
	}

	/**
	 * @testdox Order-status callbacks keep excluded charge card shapes on the established post-lifecycle display path.
	 * Card-network precedence follows the pinned WooPayments 11.1.0 CardDefinition; HTTP 200 for a malformed display brand is the N-104 correctness decision.
	 *
	 * @dataProvider excluded_charge_card_shapes
	 *
	 * @param array<string,mixed>|null $charge                  PaymentIntent charge data, if present.
	 * @param string                   $expected_observed_title Expected title at the lifecycle boundary.
	 * @param int                      $expected_status_code    Expected response status code.
	 * @param string|null              $expected_final_title    Expected final title when the selected brand is unusable.
	 */
	public function test_update_order_status_keeps_excluded_charge_card_shapes_on_post_lifecycle_display_path( ?array $charge, string $expected_observed_title, int $expected_status_code, ?string $expected_final_title = null ): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'WooPayments' );
		$order->update_meta_data( '_intent_id', 'pi_excluded_shape' );
		$order->save();

		$api_client              = new class( $charge ) extends WooPaymentsApiClient {
			/**
			 * Charge data returned with the PaymentIntent.
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $charge;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|null $charge Charge data returned with the PaymentIntent.
			 */
			public function __construct( ?array $charge ) {
				$this->charge = $charge;
			}

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_excluded_shape' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				$intent = array(
					'id'                   => 'pi_excluded_shape',
					'status'               => 'succeeded',
					'currency'             => 'usd',
					'customer'             => 'cus_native',
					'payment_method'       => 'pm_native',
					'payment_method_types' => array( 'card' ),
				);
				if ( null !== $this->charge ) {
					$intent['charges'] = array(
						'total_count' => 1,
						'data'        => array( $this->charge ),
					);
				}

				return $intent;
			}
		};
		$token_service           = $this->create_token_service(
			array(
				'pm_native' => array(
					'id'   => 'pm_native',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);
		$sut                     = $this->create_controller( $api_client, null, $token_service );
		$observed_title          = '';
		$observer_count          = 0;
		$record_payment_complete = function ( int $order_id ) use ( $order, &$observed_title, &$observer_count ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$observed_order = wc_get_order( $order_id );
			++$observer_count;
			$observed_title = $observed_order instanceof WC_Order ? $observed_order->get_payment_method_title() : '';
		};
		add_action( 'woocommerce_payment_complete', $record_payment_complete, 1, 1 );

		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'pi_excluded_shape',
					'should_save_payment_method' => 'true',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $record_payment_complete, 1 );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status_code, $response['status_code'] );
		$this->assertSame( 1, $observer_count );
		$this->assertSame( $expected_observed_title, $observed_title );
		if ( 200 === $expected_status_code ) {
			$this->assertNotSame( 'WooPayments', $order->get_payment_method_title() );
		}
		if ( null !== $expected_final_title ) {
			$this->assertSame( $expected_final_title, $order->get_payment_method_title() );
		}
	}

	/**
	 * Provide charge card shapes excluded from the pre-lifecycle identity projection.
	 *
	 * @return array<string,array{array<string,mixed>|null,string,int}>
	 */
	public function excluded_charge_card_shapes(): array {
		return array(
			'missing charge details'                  => array( null, 'WooPayments', 200 ),
			'partial card details'                    => array(
				array(
					'id'                     => 'ch_partial',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array( 'last4' => '4242' ),
					),
				),
				'WooPayments',
				200,
			),
			'missing card funding'                    => array(
				array(
					'id'                     => 'ch_missing_funding',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'   => 'visa',
							'last4'   => '4242',
							'network' => 'visa',
						),
					),
				),
				'WooPayments',
				200,
			),
			'link wrapped card'                       => array(
				array(
					'id'                     => 'ch_link',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'   => 'visa',
							'funding' => 'credit',
							'last4'   => '4242',
							'network' => 'visa',
							'wallet'  => array( 'type' => 'link' ),
						),
					),
				),
				'WooPayments',
				200,
			),
			'card present'                            => array(
				array(
					'id'                     => 'ch_card_present',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type'         => 'card_present',
						'card_present' => array( 'last4' => '4242' ),
					),
				),
				'WooPayments',
				200,
			),
			'capitalized card type'                   => array(
				array(
					'id'                     => 'ch_capitalized_card_type',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'Card',
						'card' => array(
							'brand'   => 'visa',
							'funding' => 'credit',
							'last4'   => '4242',
							'network' => 'visa',
						),
					),
				),
				'WooPayments',
				200,
			),
			'uppercase card type'                     => array(
				array(
					'id'                     => 'ch_uppercase_card_type',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'CARD',
						'card' => array(
							'brand'   => 'visa',
							'funding' => 'credit',
							'last4'   => '4242',
							'network' => 'visa',
						),
					),
				),
				'WooPayments',
				200,
			),
			'spaced card type'                        => array(
				array(
					'id'                     => 'ch_spaced_card_type',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'ca rd',
						'card' => array(
							'brand'   => 'visa',
							'funding' => 'credit',
							'last4'   => '4242',
							'network' => 'visa',
						),
					),
				),
				'WooPayments',
				200,
			),
			'whitespace card type'                    => array(
				array(
					'id'                     => 'ch_whitespace_card_type',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => ' card ',
						'card' => array(
							'brand'   => 'visa',
							'funding' => 'credit',
							'last4'   => '4242',
							'network' => 'visa',
						),
					),
				),
				'WooPayments',
				200,
			),
			'empty display brand shadows network'     => array(
				array(
					'id'                     => 'ch_empty_display_brand',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'         => 'visa',
							'display_brand' => '',
							'funding'       => 'credit',
							'last4'         => '4242',
							'network'       => 'visa',
						),
					),
				),
				'WooPayments',
				200,
				'Credit / Debit Cards',
			),
			'malformed display brand shadows network' => array(
				array(
					'id'                     => 'ch_malformed_display_brand',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'         => 'visa',
							'display_brand' => array( 'visa' ),
							'funding'       => 'credit',
							'last4'         => '4242',
							'network'       => 'visa',
						),
					),
				),
				'WooPayments',
				200,
				'Credit / Debit Cards',
			),
			'non-array available network'             => array(
				array(
					'id'                     => 'ch_string_available',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'    => 'visa',
							'funding'  => 'credit',
							'last4'    => '4242',
							'networks' => array( 'available' => 'visa' ),
						),
					),
				),
				'WooPayments',
				200,
			),
			'valid available network'                 => array(
				array(
					'id'                     => 'ch_available_network',
					'payment_method'         => 'pm_native',
					'payment_method_details' => array(
						'type' => 'card',
						'card' => array(
							'brand'    => 'visa',
							'funding'  => 'credit',
							'last4'    => '4242',
							'networks' => array( 'available' => array( 'visa' ) ),
						),
					),
				),
				'Visa credit card',
				200,
			),
		);
	}

	/**
	 * @testdox Order-status callback should save requested non-card tokens through type-aware creation.
	 */
	public function test_update_order_status_saves_requested_non_card_token(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $user_id );
		$order->set_currency( 'EUR' );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID . '_sepa_debit' );
		$order->update_meta_data( '_intent_id', 'pi_sepa' );
		$order->save();

		$api_client      = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_sepa' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_sepa',
					'status'         => 'succeeded',
					'currency'       => 'eur',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_sepa',
				);
			}
		};
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )
			->with( 'upe_enabled_payment_method_ids', array( 'card' ) )
			->willReturn( array( 'card', 'sepa_debit' ) );
		$token_service   = $this->create_token_service(
			array(
				'pm_sepa' => array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array(
						'last4' => '6789',
					),
				),
			),
			$account_service
		);
		$sut             = $this->create_controller( $api_client, null, $token_service );
		$token_class_map = new WooPaymentsTokenClassMapController();
		$token_class_map->init( new StaticNativeRuntimeArbiter( true ) );
		$token_class_map->register();

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'                   => $order->get_id(),
				'intent_id'                  => 'pi_sepa',
				'should_save_payment_method' => 'true',
			)
		);
		$order    = wc_get_order( $order->get_id() );
		$tokens   = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID . '_sepa_debit' ) );
		$token    = $tokens[0] ?? null;

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $token );
		$this->assertSame( 'pm_sepa', $token->get_token() );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens() );
	}

	/**
	 * @testdox Authenticated renewal callbacks save and synchronize a new card despite an unchecked save preference.
	 *
	 * @dataProvider renewal_order_statuses
	 *
	 * @param string $renewal_status Renewal order status.
	 */
	public function test_update_order_status_saves_and_syncs_new_card_for_failed_or_pending_renewal( string $renewal_status ): void {
		$this->ensure_wcs_order_contains_renewal_double();
		$this->ensure_wcs_subscriptions_for_order_double();
		$user_id      = $this->factory()->user->create();
		$order        = $this->create_woopayments_order( '10.00' );
		$subscription = $this->create_woopayments_order( '10.00' );
		$unrelated    = $this->create_woopayments_order( '10.00' );
		wp_set_current_user( $user_id );

		$order->set_customer_id( $user_id );
		$order->set_status( $renewal_status );
		$order->set_payment_method_title( 'WooPayments' );
		$order->update_meta_data( '_intent_id', 'pi_native' );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'WooPayments' );
		$subscription->update_meta_data( '_payment_method_id', 'pm_old' );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_old' );
		$subscription->save();
		$old_token = new WC_Payment_Token_CC();
		$old_token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$old_token->set_token( 'pm_old' );
		$old_token->set_user_id( $user_id );
		$old_token->set_card_type( 'mastercard' );
		$old_token->set_last4( '1111' );
		$old_token->set_expiry_month( 1 );
		$old_token->set_expiry_year( 2030 );
		$old_token->save();
		$order->add_payment_token( $old_token );
		$order->update_meta_data( '_payment_method_id', 'pm_old' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_old' );
		$order->save();
		$subscription->add_payment_token( $old_token );
		$subscription->save();
		$unrelated->set_customer_id( $user_id );
		$unrelated->set_payment_method( 'cheque' );
		$unrelated->set_payment_method_title( 'Check payments' );
		$unrelated->update_meta_data( '_payment_method_id', 'pm_unrelated' );
		$unrelated->update_meta_data( '_stripe_customer_id', 'cus_unrelated' );
		$unrelated->save();
		$GLOBALS['wcpay_test_renewal_order_ids']                = array( $order->get_id() );
		$GLOBALS['wcpay_test_order_subscription_relationships'] = array(
			$order->get_id() => array( 'renewal' => array( $subscription->get_id() ) ),
		);

		$api_client                      = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_native',
								'payment_method'         => 'pm_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
										'network' => 'visa',
									),
								),
							),
						),
					),
				);
			}
		};
		$token_service                   = $this->create_token_service(
			array(
				'pm_native' => array(
					'id'   => 'pm_native',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);
		$sut                             = $this->create_controller( $api_client, null, $token_service );
		$payment_complete_observations   = array();
		$payment_complete_observer_count = 0;
		$record_payment_complete         = function ( int $order_id ) use ( $order, $subscription, $token_service, &$payment_complete_observations, &$payment_complete_observer_count ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$observed_order            = wc_get_order( $order_id );
			$observed_subscription     = wc_get_order( $subscription->get_id() );
			$active_order_token        = $observed_order instanceof WC_Order ? $token_service->get_active_token_for_order( $observed_order ) : null;
			$active_subscription_token = $observed_subscription instanceof WC_Order ? $token_service->get_active_token_for_order( $observed_subscription ) : null;

			++$payment_complete_observer_count;
			$payment_complete_observations = array(
				'gateway'                        => $observed_order instanceof WC_Order ? $observed_order->get_payment_method() : '',
				'title'                          => $observed_order instanceof WC_Order ? $observed_order->get_payment_method_title() : '',
				'last4'                          => $observed_order instanceof WC_Order ? $observed_order->get_meta( 'last4', true ) : '',
				'brand'                          => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_card_brand', true ) : '',
				'details'                        => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_wcpay_payment_method_details', true ) : '',
				'raw_details'                    => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_wcpay_raw_payment_method_details', true ) : '',
				'payment_method'                 => $observed_order instanceof WC_Order ? $observed_order->get_meta( '_payment_method_id', true ) : '',
				'token'                          => $active_order_token,
				'subscription_token'             => $observed_subscription instanceof WC_Order ? $observed_subscription->get_payment_tokens() : array(),
				'active_subscription_token'      => $active_subscription_token,
				'subscription_method'            => $observed_subscription instanceof WC_Order ? $observed_subscription->get_payment_method() : '',
				'subscription_title'             => $observed_subscription instanceof WC_Order ? $observed_subscription->get_payment_method_title() : '',
				'subscription_payment_method_id' => $observed_subscription instanceof WC_Order ? $observed_subscription->get_meta( '_payment_method_id', true ) : '',
				'subscription_customer_id'       => $observed_subscription instanceof WC_Order ? $observed_subscription->get_meta( '_stripe_customer_id', true ) : '',
			);
		};
		add_action( 'woocommerce_payment_complete', $record_payment_complete, 1, 1 );

		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'pi_native',
					'should_save_payment_method' => 'false',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $record_payment_complete, 1 );
		}
		$order  = wc_get_order( $order->get_id() );
		$tokens = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );
		$token  = $order instanceof WC_Order ? $token_service->get_active_token_for_order( $order ) : null;

		$subscription = wc_get_order( $subscription->get_id() );
		$unrelated    = wc_get_order( $unrelated->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertInstanceOf( WC_Order::class, $unrelated );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertCount( 2, $tokens );
		$this->assertSame( $user_id, $token->get_user_id() );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens(), 'Saved cards should be linked to the parent order.' );
		$this->assertSame( array( $old_token->get_id(), $token->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertContains( $token->get_id(), $subscription->get_payment_tokens(), 'Saved cards should be linked to the renewal subscription.' );
		$this->assertContains( $old_token->get_id(), $order->get_payment_tokens(), 'Historical cards should remain linked to the renewal order.' );
		$this->assertContains( $old_token->get_id(), $subscription->get_payment_tokens(), 'Historical cards should remain linked to the renewal subscription.' );
		$this->assertSame( 'pm_native', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_native', $subscription->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $subscription->get_payment_method() );
		$this->assertSame( 'Visa credit card', $subscription->get_payment_method_title() );
		$this->assertSame( 1, $payment_complete_observer_count );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $payment_complete_observations['gateway'] );
		$this->assertSame( 'Visa credit card', $payment_complete_observations['title'] );
		$this->assertSame( '4242', $payment_complete_observations['last4'] );
		$this->assertSame( 'visa', $payment_complete_observations['brand'] );
		$this->assertStringContainsString( '"last4":"4242"', (string) $payment_complete_observations['details'] );
		$this->assertSame( '', $payment_complete_observations['raw_details'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['payment_method'] );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $payment_complete_observations['token'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['token']->get_token() );
		$this->assertSame( array( $old_token->get_id(), $token->get_id() ), array_values( $payment_complete_observations['subscription_token'] ) );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $payment_complete_observations['active_subscription_token'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['active_subscription_token']->get_token() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $payment_complete_observations['subscription_method'] );
		$this->assertSame( 'Visa credit card', $payment_complete_observations['subscription_title'] );
		$this->assertSame( 'pm_native', $payment_complete_observations['subscription_payment_method_id'] );
		$this->assertSame( 'cus_native', $payment_complete_observations['subscription_customer_id'] );
		$this->assertSame( array(), $unrelated->get_payment_tokens() );
		$this->assertSame( 'pm_unrelated', $unrelated->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_unrelated', $unrelated->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cheque', $unrelated->get_payment_method() );
		$this->assertSame( 'Check payments', $unrelated->get_payment_method_title() );

		add_action( 'woocommerce_payment_complete', $record_payment_complete, 1, 1 );
		try {
			$response = $sut->get_update_order_status_response(
				array(
					'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
					'order_id'                   => $order->get_id(),
					'intent_id'                  => 'pi_native',
					'should_save_payment_method' => 'false',
				)
			);
		} finally {
			remove_action( 'woocommerce_payment_complete', $record_payment_complete, 1 );
		}
		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$tokens       = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );

		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 1, $payment_complete_observer_count );
		$this->assertCount( 2, $tokens );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( $old_token->get_id(), $token->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertSame( array( $old_token->get_id(), $token->get_id() ), array_values( $subscription->get_payment_tokens() ) );
	}

	/**
	 * Provide failed and pending renewal statuses.
	 *
	 * @return array<string,array{string}>
	 */
	public function renewal_order_statuses(): array {
		return array(
			'failed renewal'  => array( 'failed' ),
			'pending renewal' => array( 'pending' ),
		);
	}

	/**
	 * @testdox Order-status callback should update WC Subscriptions after SCA change-payment confirmation.
	 */
	public function test_update_order_status_updates_subscription_payment_method_after_change_payment_confirmation(): void {
		$this->ensure_wcs_change_payment_gateway_double();
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->update_meta_data( '_intent_id', 'seti_native' );
		$order->update_meta_data( '_delayed_update_payment_method_all', OrderPaymentStore::GATEWAY_ID );
		$order->save();

		$api_client    = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a SetupIntent.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				if ( 'seti_native' !== $setup_intent_id ) {
					throw new \RuntimeException( 'Unexpected setup intent ID.' );
				}

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$token_service = $this->create_token_service(
			array(
				'pm_native' => array(
					'id'   => 'pm_native',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);
		$sut           = $this->create_controller( $api_client, null, $token_service );

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'                   => $order->get_id(),
				'intent_id'                  => 'seti_native',
				'should_save_payment_method' => 'false',
				'is_changing_payment'        => 'true',
			)
		);
		$order    = wc_get_order( $order->get_id() );
		$tokens   = array_values( WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID ) );
		$token    = $tokens[0] ?? null;

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens() );
		$this->assertSame(
			array(
				array(
					'order_id'   => $order->get_id(),
					'gateway_id' => OrderPaymentStore::GATEWAY_ID,
				),
			),
			\WC_Subscriptions_Change_Payment_Gateway::$updated_payment_methods
		);
		$this->assertSame(
			array(
				array(
					'order_id'   => $order->get_id(),
					'gateway_id' => OrderPaymentStore::GATEWAY_ID,
				),
			),
			\WC_Subscriptions_Change_Payment_Gateway::$updated_all_payment_methods
		);
	}

	/**
	 * @testdox Order-status callback should block recurring orders when token saving fails.
	 */
	public function test_update_order_status_blocks_recurring_order_when_token_save_fails(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'WooPayments' );
		$order->update_meta_data( '_intent_id', 'pi_native' );
		$order->save();

		$api_client    = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Retrieve a PaymentIntent.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected payment intent ID.' );
				}

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_native',
								'payment_method'         => 'pm_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
										'network' => 'visa',
									),
								),
							),
						),
					),
				);
			}
		};
		$token_service = new class() extends WooPaymentsTokenService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double must fail token saving.
			/**
			 * Fail token creation.
			 *
			 * @param string $payment_method_id Provider payment method ID.
			 * @param int    $user_id           User ID.
			 * @return WC_Payment_Token|null
			 */
			public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token {
				unset( $payment_method_id, $user_id );

				throw new \RuntimeException( 'Token save failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut           = $this->create_controller( $api_client, null, $token_service );

		add_filter( 'woocommerce_woopayments_is_recurring_payment', '__return_true' );

		$response = $sut->get_update_order_status_response(
			array(
				'_ajax_nonce'                => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'                   => $order->get_id(),
				'intent_id'                  => 'pi_native',
				'should_save_payment_method' => 'false',
			)
		);
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 409, $response['status_code'] );
		$this->assertSame( 'Unable to save payment method for subscription. Please try again or use a different payment method.', $response['error']['message'] );
		$this->assertNotSame( 'completed', $order->get_status() );
		$this->assertSame( 'WooPayments', $order->get_payment_method_title() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'Recurring orders should not be completed when their required token cannot be saved.' );
	}

	/**
	 * @testdox Setup-intent callback should return the native SetupIntent response envelope.
	 */
	public function test_create_setup_intent_returns_native_response_envelope(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a SetupIntent.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				if ( 'cus_user' !== $request_data['customer']
					|| 'pm_card' !== $request_data['payment_method']
					|| array( 'card' ) !== $request_data['payment_method_types']
					|| '' === $idempotency_key ) {
					throw new \RuntimeException( 'Unexpected setup intent payload.' );
				}

				return array(
					'id'            => 'seti_user',
					'status'        => 'succeeded',
					'client_secret' => 'seti_user_secret_abc',
				);
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_user' )
			->with( $user_id )
			->willReturn( 'cus_user' );

		$sut      = $this->create_controller( $api_client, $customer_service );
		$response = $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method' => 'pm_card',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame(
			array(
				'id'            => 'seti_user',
				'status'        => 'succeeded',
				'client_secret' => 'seti_user_secret_abc',
			),
			$response['data']
		);
		$this->assertFalse(
			\WC_Rate_Limiter::retried_too_soon( 'add_payment_method_' . $user_id ),
			'The AJAX setup phase must leave the WooCommerce form-handler rate limit available.'
		);
	}

	/**
	 * @testdox Setup-intent card declines return the localized shopper message, create the customer before the transport call, and leave no payment method saved.
	 *
	 * The response body and HTTP status are the real decline envelope local WPCOM returned for each Stripe
	 * test card, recorded in `Fixtures/rec-2-setup-intent-declines.json` (REC-2). All five recorded errors
	 * are `card_error` and carry a `setup_intent` object (never `payment_intent`); the client's decline-code
	 * lookup at `class-wc-payments-utils.php:801-817`, catalog `:852-866` (11.1.0) does not read either
	 * intent object, only `code` and `decline_code`. The HTTP status this endpoint returns is deliberately
	 * not asserted: native always answers 502 regardless of the platform's status
	 * (`WooPaymentsCheckoutAjaxController.php:387-396`), while the client passes the platform's own code
	 * through and maps 402 to 400 (`utils:878-891`); that divergence is finding F1 and stays unpinned.
	 *
	 * @dataProvider recorded_setup_intent_decline_data
	 *
	 * @param string $pair             REC-2 fixture pair key.
	 * @param string $expected_message Expected localized shopper-facing message.
	 */
	public function test_create_setup_intent_localizes_card_decline_api_errors( string $pair, string $expected_message ): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$recorded = $this->load_recorded_setup_intent_decline_entry( $pair );
		// The recorded platform text equals the English catalog, so replace it to prove the catalog, not the platform, supplies the message.
		$recorded['error']['message'] = 'Platform decline text that must not reach the shopper.';
		$http_client                  = new FakeWooPaymentsHttpClient();
		$http_client->response        = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array( 'error' => $recorded['error'] ) ),
		);
		$account_service              = $this->create_account_service( false );
		$api_client                   = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_user' )
			->with( $user_id )
			->willReturnCallback(
				function () use ( $http_client ) {
					$this->assertSame( 0, $http_client->request_count, 'The customer must be created before the SetupIntent request is sent.' );

					return 'cus_rec2';
				}
			);

		$sut      = $this->create_controller( $api_client, $customer_service, null, $account_service );
		$response = $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method' => 'pm_declined',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( $expected_message, $response['data']['error']['message'] );
		$this->assertSame( 1, $http_client->request_count );
		global $wpdb;
		// Count rows directly: the token data store only returns gateways registered in this request, and the test runtime registers none.
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d", $user_id ) ),
			"$pair must leave no saved payment method row for the shopper."
		);
	}

	/**
	 * REC-2 recorded SetupIntent decline entries, one row per Stripe test card pair.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function recorded_setup_intent_decline_data(): array {
		return array(
			'generic_decline (card_declined)'    => array( 'generic_decline', 'Error: Your card was declined.' ),
			'incorrect_cvc'                      => array( 'incorrect_cvc', "Error: Your card's security code is incorrect." ),
			'expired_card'                       => array( 'expired_card', 'Error: Your card has expired.' ),
			'insufficient_funds (card_declined)' => array( 'insufficient_funds', 'Error: Your card has insufficient funds.' ),
			'processing_error'                   => array( 'processing_error', 'Error: An error occurred while processing your card. Try again in a little bit.' ),
		);
	}

	/**
	 * Load one recorded REC-2 SetupIntent decline entry's HTTP status and `error` object by pair key.
	 *
	 * @param string $pair REC-2 fixture pair key.
	 * @return array{http_status:int,error:array<string,mixed>}
	 */
	private function load_recorded_setup_intent_decline_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-2-setup-intent-declines.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status' => (int) $entry['response']['http_status'],
					'error'       => $entry['response']['body']['error'],
				);
			}
		}

		$this->fail( "REC-2 fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Setup-intent callback refuses a request inside the add-payment-method cooldown without ever calling the provider.
	 *
	 * The cooldown key (`add_payment_method_<user_id>`) is the same one WooCommerce's core My Account
	 * add-payment-method form checks (`includes/class-wc-form-handler.php:608-628`); the AJAX SetupIntent
	 * path must refuse before creating anything server-side, matching the plugin's check-before-create
	 * ordering and message at `gw:4589-4593` (11.1.0). The HTTP status is not asserted: native returns 429
	 * (`WooPaymentsCheckoutAjaxController.php:356-357`) while the client throws an `Add_Payment_Method_Exception`
	 * that the same 402-to-400, default-400 mapping resolves to 400 (`utils:878-891`); finding F1, unpinned.
	 */
	public function test_create_setup_intent_refuses_inside_add_payment_method_rate_limit_without_provider_call(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		\WC_Rate_Limiter::set_rate_limit( 'add_payment_method_' . $user_id, 20 );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * SetupIntent creation requests this fake received.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $created_setup_intents = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a SetupIntent.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->created_setup_intents[] = $request_data;

				return array(
					'id'     => 'seti_should_not_exist',
					'status' => 'succeeded',
				);
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->expects( $this->never() )->method( 'get_or_create_customer_id_for_user' );

		$sut      = $this->create_controller( $api_client, $customer_service );
		$response = $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method' => 'pm_card',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'You cannot add a new payment method so soon after the previous one. Please try again later.',
			$response['data']['error']['message']
		);
		$this->assertSame( array(), $api_client->created_setup_intents, 'A rate-limited request must never create a SetupIntent.' );
	}

	/**
	 * @testdox Setup-intent card-testing prevention preserves the platform's actionable message.
	 */
	public function test_create_setup_intent_preserves_card_testing_prevention_message(): void {
		$platform_message = "Error: We're not able to add this payment method. Please try again later.";
		$response         = $this->get_create_setup_intent_api_error_response(
			new WooPaymentsApiException(
				$platform_message,
				'wcpay_card_testing_prevention',
				400,
				''
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 502, $response['status_code'] );
		$this->assertSame( $platform_message, $response['data']['error']['message'] );
	}

	/**
	 * @testdox Setup-intent transport failures should redact technical messages.
	 */
	public function test_create_setup_intent_redacts_non_card_api_errors(): void {
		$technical_message = 'Upstream socket credentials debug detail.';
		$response          = $this->get_create_setup_intent_api_error_response(
			new WooPaymentsApiException(
				$technical_message,
				'api_connection_error',
				503,
				'api_error'
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 502, $response['status_code'] );
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			$response['data']['error']['message']
		);
		$this->assertStringNotContainsString( $technical_message, wp_json_encode( $response ) );
	}

	/**
	 * @testdox Setup-intent callback should derive payment method types from the submitted WooPayments gateway ID.
	 */
	public function test_create_setup_intent_derives_payment_method_type_from_submitted_gateway_id(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Last request data.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_request_data = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a SetupIntent.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );

				$this->last_request_data = $request_data;

				return array(
					'id'            => 'seti_sepa',
					'status'        => 'succeeded',
					'client_secret' => 'seti_sepa_secret_abc',
				);
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_user' )
			->with( $user_id )
			->willReturn( 'cus_user' );

		$sut = $this->create_controller( $api_client, $customer_service );

		$sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'payment_method'       => OrderPaymentStore::GATEWAY_ID . '_sepa_debit',
				'wcpay-payment-method' => 'pm_sepa_debit',
			)
		);

		$this->assertSame( array( 'sepa_debit' ), $api_client->last_request_data['payment_method_types'] );
	}

	/**
	 * Get a setup-intent response for a native API failure.
	 *
	 * @param WooPaymentsApiException $exception API exception to throw.
	 * @return array<string,mixed>
	 */
	private function get_create_setup_intent_api_error_response( WooPaymentsApiException $exception ): array {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$api_client = new class( $exception ) extends WooPaymentsApiClient {
			/**
			 * API exception to throw.
			 *
			 * @var WooPaymentsApiException
			 */
			private WooPaymentsApiException $exception;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiException $exception API exception to throw.
			 */
			public function __construct( WooPaymentsApiException $exception ) {
				$this->exception = $exception;
			}

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			// phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Fail SetupIntent creation.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @throws WooPaymentsApiException Always.
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				throw $this->exception;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_user' )->willReturn( 'cus_user' );

		$sut = $this->create_controller( $api_client, $customer_service );

		return $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method' => 'pm_card',
			)
		);
	}

	/**
	 * @testdox Create-setup-intent requests with an invalid fraud-prevention token are rejected before any SetupIntent exists.
	 */
	public function test_create_setup_intent_rejects_invalid_fraud_prevention_token_before_creating(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		WC()->initialize_session();

		// Seed through the container's account service: it memoizes the account
		// cache per instance, and the controller's fraud service reads through
		// that same shared instance.
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id'                       => 'acct_fraud_check',
				'is_live'                          => true,
				'card_testing_protection_eligible' => true,
			)
		);

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * SetupIntent creation requests this fake received.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $created_setup_intents = array();

			/**
			 * Create and confirm a SetupIntent.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				// Avoid parameter not used PHPCS errors.
				unset( $idempotency_key );
				$this->created_setup_intents[] = $request_data;

				return array(
					'id'     => 'seti_should_not_exist',
					'status' => 'succeeded',
				);
			}
		};

		$fraud_prevention_service = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService::class );
		$this->assertTrue( $fraud_prevention_service->has_session(), 'Precondition: the fraud service must see the WooCommerce session.' );
		$this->assertTrue( $fraud_prevention_service->is_enabled(), 'Precondition: card-testing protection must read as eligible.' );

		$sut      = $this->create_controller( $api_client );
		$response = $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'                  => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method'         => 'pm_card',
				'wcpay-fraud-prevention-token' => 'wrong-token',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 400, $response['status_code'] );
		$this->assertSame( array(), $api_client->created_setup_intents, 'A fraud-failed request must never create a SetupIntent.' );

		// The container's account service memoizes the account cache per
		// instance; reset the shared instance to a non-eligible payload so the
		// fraud gate disarms for the rest of the suite, then drop the option.
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id' => 'acct_fraud_check',
				'is_live'    => true,
			)
		);
		delete_option( 'wcpay_account_data' );
		wp_set_current_user( 0 );
	}

	/**
	 * @testdox Add-payment-method setup intents carry the buyer-fingerprinting risk metadata.
	 */
	public function test_create_setup_intent_attaches_fingerprint_metadata(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Captured setup-intention payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $captured = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a SetupIntent.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				// Avoid parameter not used PHPCS errors.
				unset( $idempotency_key );
				$this->captured[] = $request_data;

				return array(
					'id'            => 'seti_fp',
					'status'        => 'succeeded',
					'client_secret' => 'seti_fp_secret',
				);
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_user' )->willReturn( 'cus_fp' );

		$sut      = $this->create_controller( $api_client, $customer_service );
		$response = $sut->get_create_setup_intent_response(
			array(
				'_ajax_nonce'          => wp_create_nonce( 'wcpay_create_setup_intent_nonce' ),
				'wcpay-payment-method' => 'pm_card',
				'wcpay-fingerprint'    => 'device_fp_addpm',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $api_client->captured );
		$metadata = $api_client->captured[0]['metadata'];
		$this->assertSame( 'device_fp_addpm', $metadata['fraud_prevention_data_shopper_ua_hash'] );
		$this->assertSame( hash( 'sha512', \WC_Geolocation::get_ip_address() ), $metadata['fraud_prevention_data_shopper_ip_hash'] );
		$this->assertTrue( $metadata['fraud_prevention_data_available'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Create a checkout AJAX controller.
	 *
	 * @param WooPaymentsApiClient              $api_client       API client.
	 * @param WooPaymentsCustomerService|null   $customer_service Customer service.
	 * @param WooPaymentsTokenService|null      $token_service    Token service.
	 * @param WooPaymentsAccountService|null    $account_service  Account service.
	 * @param OrderPaymentLifecycleService|null $lifecycle_service Lifecycle service.
	 * @return WooPaymentsCheckoutAjaxController
	 */
	private function create_controller( WooPaymentsApiClient $api_client, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsAccountService $account_service = null, ?OrderPaymentLifecycleService $lifecycle_service = null ): WooPaymentsCheckoutAjaxController {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( true );

		if ( null === $customer_service ) {
			$customer_service = $this->createMock( WooPaymentsCustomerService::class );
		}

		if ( null === $token_service ) {
			$token_service = $this->create_token_service();
		}

		if ( null === $account_service ) {
			$account_service = $this->create_account_service( false );
		}
		$order_data_service = new WooPaymentsOrderDataService();
		$registry           = new WooPaymentsPaymentMethodRegistry();
		$effect_applier     = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			$order_data_service,
			$account_service,
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new WooPaymentsOrderNoteService(),
			$registry
		);

		$sut = new WooPaymentsCheckoutAjaxController();
		$sut->init(
			$arbiter,
			$api_client,
			$customer_service,
			$lifecycle_service ?? wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$token_service,
			$account_service,
			$order_data_service,
			$registry,
			$effect_applier
		);

		return $sut;
	}

	/**
	 * Create a WooPayments account service mock.
	 *
	 * @param bool   $test_mode                Whether WooPayments should run in test mode.
	 * @param string $account_default_currency Account default currency.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, string $account_default_currency = 'usd' ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'is_test_mode_enabled', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_default_currency' )->willReturn( $account_default_currency );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		return $account_service;
	}

	/**
	 * Create a token service test double.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
	 * @param WooPaymentsAccountService|null    $account_service        Optional account service.
	 * @param \ArrayObject<int,int>|null        $details_reads          Optional payment-method detail read counter.
	 * @param bool                              $throw_details_lookup   Whether details lookup throws.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service( array $payment_method_details = array(), ?WooPaymentsAccountService $account_service = null, ?\ArrayObject $details_reads = null, bool $throw_details_lookup = false ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details, $details_reads, $throw_details_lookup ) extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Payment method details keyed by ID.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $payment_method_details;

			/** @var \ArrayObject<int,int>|null */
			private ?\ArrayObject $details_reads;

			/** @var bool */
			private bool $throw_details_lookup;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
			 * @param \ArrayObject<int,int>|null        $details_reads Payment-method detail read counter.
			 * @param bool                              $throw_details_lookup Whether details lookup throws.
			 */
			public function __construct( array $payment_method_details, ?\ArrayObject $details_reads, bool $throw_details_lookup ) {
				$this->payment_method_details = $payment_method_details;
				$this->details_reads          = $details_reads;
				$this->throw_details_lookup   = $throw_details_lookup;
			}

			/**
			 * Get payment method details.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				if ( $this->details_reads instanceof \ArrayObject ) {
					++$this->details_reads[0];
				}
				if ( $this->throw_details_lookup ) {
					throw new \RuntimeException( 'Display details unavailable.' );
				}

				return $this->payment_method_details[ $payment_method_id ] ?? array();
			}
		};

		$sut = new WooPaymentsTokenService();
		$sut->init( $details_service, new StaticNativeRuntimeArbiter( true ), null, null, $account_service );

		return $sut;
	}

	/**
	 * Create a persisted WooPayments card token for a test customer.
	 *
	 * @param int    $user_id Payment token owner.
	 * @param string $gateway_id Gateway ID.
	 * @param string $provider_token Provider payment-method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $gateway_id, string $provider_token ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_token( $provider_token );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Assert that an order has a note containing the expected content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assert_order_has_note_containing( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( false !== strpos( (string) $note->content, $expected ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "Missing order note containing: {$expected}" );
	}

	/**
	 * Assert that an order has no note containing the expected content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assert_order_has_no_note_containing( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( false !== strpos( (string) $note->content, $expected ) ) {
				$this->fail( "Unexpected order note containing: {$expected}" );
			}
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Create a WooPayments test order.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Ensure a WCS subscriptions-for-order double with WCS relationship defaults exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_subscriptions_for_order_double(): void {
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public order lookup contract.
		eval( 'namespace { function wcs_get_subscriptions_for_order( $order_id, $args = array() ) { $order_id = is_object( $order_id ) && method_exists( $order_id, "get_id" ) ? $order_id->get_id() : absint( $order_id ); $order_types = $args["order_type"] ?? array( "parent", "switch" ); $order_types = is_array( $order_types ) ? $order_types : array( $order_types ); $relationships = $GLOBALS["wcpay_test_order_subscription_relationships"][ $order_id ] ?? array(); $ids = array(); foreach ( $order_types as $order_type ) { $ids = array_merge( $ids, $relationships[ $order_type ] ?? array() ); } return array_values( array_filter( array_map( "wc_get_order", array_unique( array_map( "absint", $ids ) ) ) ) ); } }' );
	}

	/**
	 * Ensure a renewal-order detector double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_order_contains_renewal_double(): void {
		if ( function_exists( 'wcs_order_contains_renewal' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public renewal detector.
		eval( 'namespace { function wcs_order_contains_renewal( $order ) { $order_id = is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : absint( $order ); return in_array( $order_id, $GLOBALS["wcpay_test_renewal_order_ids"] ?? array(), true ); } }' );
	}

	/**
	 * Ensure a minimal WC Subscriptions change-payment gateway double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_change_payment_gateway_double(): void {
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- The production class is optional; tests need a process-local stand-in.
		eval(
			<<<'PHP'
			namespace {
			class WC_Subscriptions_Change_Payment_Gateway {
				public static $updated_payment_methods = array();
				public static $updated_all_payment_methods = array();
				public static $will_update_all_payment_methods = true;

				public static function reset() {
					self::$updated_payment_methods = array();
					self::$updated_all_payment_methods = array();
					self::$will_update_all_payment_methods = true;
				}

				public static function update_payment_method( $order, $gateway_id ) {
					self::$updated_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
				}

				public static function will_subscription_update_all_payment_methods( $order ) {
					unset( $order );
					return self::$will_update_all_payment_methods;
				}

				public static function update_all_payment_methods_from_subscription( $order, $gateway_id ) {
					self::$updated_all_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
					return true;
				}
			}
			}
PHP
		);
	}
}
