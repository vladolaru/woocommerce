<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the WooPaymentsProviderGatewayAdapter class.
 */
class WooPaymentsProviderGatewayAdapterTest extends WC_Unit_Test_Case {

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
		remove_all_filters( 'woocommerce_woopayments_is_recurring_payment' );
		remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
		remove_all_filters( 'woocommerce_payment_token_class' );
		remove_all_filters( 'wcpay_metadata_from_order' );
		delete_option( 'woocommerce_tax_based_on' );
		delete_option( 'woocommerce_calc_taxes' );
		update_option( 'woocommerce_currency', $this->original_currency );
		unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS['wcpay_test_subscription_ids'] );
		// Guest customer creation stores the customer in the shared session; leave none for later classes.
		if ( WC()->session ) {
			WC()->session->set( 'wcpay_customer_id', null );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Charge should normalize legacy confirmation redirects to customer-action outcomes.
	 */
	public function test_charge_normalizes_confirmation_redirect_to_customer_action(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array(
				'result'         => 'success',
				'redirect'       => '#wcpay-confirm-pi:123:secret:nonce',
				'payment_method' => 'pm_123',
			)
		);
		$sut     = $this->create_adapter( $gateway );

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_123' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( '#wcpay-confirm-pi:123:secret:nonce', $outcome->get_redirect_url() );
		$this->assertSame( 'pm_123', $outcome->get_payment_method_id() );
		$this->assertSame( $order->get_id(), $gateway->processed_order_id );
		$this->assertSame( 'key_charge', $gateway->last_idempotency_key );
	}

	/**
	 * @testdox Charge should normalize legacy offsite redirects to redirect outcomes.
	 */
	public function test_charge_normalizes_offsite_redirect_to_redirect_outcome(): void {
		$order = $this->create_woopayments_order();
		$sut   = $this->create_adapter(
			new RecordingLegacyGateway(
				array(
					'result'   => 'success',
					'redirect' => 'https://example.test/redirect',
				)
			)
		);

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'https://example.test/redirect', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox Charge should preserve manual-capture outcomes written by the legacy gateway.
	 */
	public function test_charge_preserves_manual_capture_outcome_from_order_meta(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			)
		);

		$gateway->intent_id_to_write         = 'pi_manual';
		$gateway->intention_status_to_write  = 'requires_capture';
		$gateway->payment_method_id_to_write = 'pm_manual';

		$sut = $this->create_adapter( $gateway );

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_manual' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'pi_manual', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_manual', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Charge should preserve pending successful legacy responses without completing the order.
	 */
	public function test_charge_preserves_pending_success_without_order_completion(): void {
		$order = $this->create_woopayments_order();
		$sut   = $this->create_adapter(
			new RecordingLegacyGateway(
				array(
					'result'   => 'success',
					'redirect' => '',
				)
			)
		);

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_PENDING_ASYNC, $outcome->get_status() );
		$this->assertArrayHasKey( 'checkout_redirect', $outcome->get_data() );
		$this->assertSame( '', $outcome->get_data()['checkout_redirect'] );
	}

	/**
	 * @testdox Charge should normalize legacy failures to failed outcomes.
	 */
	public function test_charge_normalizes_failure_to_failed_outcome(): void {
		$order = $this->create_woopayments_order();
		$sut   = $this->create_adapter(
			new RecordingLegacyGateway(
				array(
					'result' => 'fail',
				)
			)
		);

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'legacy_process_payment_failed', $outcome->get_data()['error_code'] );
	}

	/**
	 * @testdox Charge should prefer the native positive-amount transport before the legacy gateway bridge.
	 */
	public function test_charge_prefers_native_positive_amount_transport_when_available(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 5000 !== $request_data['amount']
					|| 'usd' !== $request_data['currency']
					|| 'cus_native' !== $request_data['customer']
					|| 'pm_request' !== $request_data['payment_method']
					|| array( 'card' ) !== $request_data['payment_method_types']
					|| 'key_charge' !== $idempotency_key ) {
					throw new \RuntimeException( 'Unexpected native charge request payload.' );
				}

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_native',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
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

			/**
			 * Retrieve a payment intention.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected native intent read.' );
				}

				return array(
					'id'      => 'pi_native',
					'status'  => 'succeeded',
					'charges' => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
										'network' => 'visa',
									),
								),
								'fee_breakdown_v1'       => array(
									'totals'  => array(
										'fee'         => array(
											'amount'   => 293,
											'currency' => 'usd',
										),
										'tax'         => array(
											'amount'   => 0,
											'currency' => 'usd',
										),
										'net'         => array(
											'amount'   => 6422,
											'currency' => 'usd',
										),
										'capture_net' => array(
											'amount'   => 6422,
											'currency' => 'usd',
										),
										'gross'       => array(
											'amount'   => 6715,
											'currency' => 'usd',
										),
									),
									'fx'      => array(
										'from_currency' => 'gbp',
										'to_currency'   => 'usd',
										'from_amount'   => 5000,
										'to_amount'     => 6715,
									),
									'sources' => array(
										'balance_transaction_exchange_rate' => 1.3428,
									),
								),
							),
						),
					),
				);
			}

			/**
			 * Retrieve a payment timeline.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 */
			public function get_timeline( string $intent_id ): array {
				if ( 'pi_native' !== $intent_id ) {
					throw new \RuntimeException( 'Unexpected native timeline read.' );
				}

				return array(
					'data' => array(
						array(
							'type'             => 'captured',
							'fee_breakdown_v1' => array(
								'rows'   => array(
									array(
										'key'      => 'base',
										'kind'     => 'fee',
										'amount'   => 175,
										'currency' => 'usd',
										'rate'     => array(
											'percentage' => 0.029,
											'fixed'      => 30,
											'fixed_currency' => 'usd',
										),
									),
								),
								'totals' => array(
									'fee'         => array(
										'amount'   => 175,
										'currency' => 'usd',
										'rate'     => array(
											'percentage' => 0.029,
											'fixed'      => 30,
											'fixed_currency' => 'usd',
										),
									),
									'tax'         => array(
										'amount'   => 0,
										'currency' => 'usd',
									),
									'net'         => array(
										'amount'   => 4825,
										'currency' => 'usd',
									),
									'capture_net' => array(
										'amount'   => 4825,
										'currency' => 'usd',
									),
								),
							),
						),
					),
				);
			}

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->with( $this->isInstanceOf( WC_Order::class ) )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_native', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_native', $outcome->get_customer_id() );
		$this->assertSame( 0, $gateway->processed_order_id );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_payment_method_title() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertSame( 175, $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['fee_breakdown_v1']['totals']['fee']['amount'] );
		$this->assertOrderDoesNotHaveNoteStartingWith( $order, '<strong>Fee details:</strong>' );
	}

	/**
	 * @testdox Native charge fails before the API call when the order total is below the cached platform minimum.
	 */
	public function test_charge_fails_preflight_below_cached_platform_minimum(): void {
		delete_transient( 'wcpay_minimum_amount_usd' );
		set_transient( 'wcpay_minimum_amount_usd', 100, DAY_IN_SECONDS );

		$order      = $this->create_woopayments_order( '0.50' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new \RuntimeException( 'A sub-minimum charge must not reach the platform.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->never() )
			->method( 'get_or_create_customer_id_for_order' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'amount_too_small', $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'The selected payment method requires a total amount of at least $1.00.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
		$this->assertSame( 0, $gateway->processed_order_id );

		delete_transient( 'wcpay_minimum_amount_usd' );
	}

	/**
	 * @testdox Native charge proceeds to the API when the order total meets the cached platform minimum.
	 */
	public function test_charge_proceeds_when_total_meets_cached_platform_minimum(): void {
		delete_transient( 'wcpay_minimum_amount_usd' );
		set_transient( 'wcpay_minimum_amount_usd', 50, DAY_IN_SECONDS );

		$order      = $this->create_woopayments_order( '0.50' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				return array(
					'id'     => 'pi_min_ok',
					'status' => 'succeeded',
				);
			}

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );

		delete_transient( 'wcpay_minimum_amount_usd' );
	}

	/**
	 * @testdox Native charge should reuse a persisted key after an ambiguous transport failure.
	 */
	public function test_native_charge_reuses_persisted_key_after_ambiguous_transport_failure(): void {
		$order            = $this->create_woopayments_order();
		$order_id         = $order->get_id();
		$api_client       = new class( $order_id ) extends WooPaymentsApiClient {
			/** @var int */
			private int $order_id;
			/** @var string[] */
			public array $keys = array();

			/**
			 * Initialize the recording client.
			 *
			 * @param int $order_id Order ID.
			 */
			public function __construct( int $order_id ) {
				$this->order_id = $order_id;
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$fresh_order  = wc_get_order( $this->order_id );
				$this->keys[] = $idempotency_key;
				if ( ! $fresh_order instanceof WC_Order || $idempotency_key !== $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) ) {
					throw new \RuntimeException( 'Charge key was not persisted before dispatch.' );
				}
				if ( 1 === count( $this->keys ) ) {
					throw new WooPaymentsApiException( 'Transport failed.', 'http_request_failed' );
				}
				return array(
					'id'     => 'pi_reused',
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_reused' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		$ambiguous_outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_reused' ), 'key_a' );
		$sut->finalize_charge_idempotency_key( $order, $ambiguous_outcome );
		$sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_reused' ), 'key_b' );

		$this->assertSame( array( 'key_a', 'key_a' ), $api_client->keys );
	}

	/**
	 * @testdox Native charge should retire its key after a definitive provider outcome.
	 */
	public function test_native_charge_retires_key_after_definitive_outcome(): void {
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var string[] */
			public array $keys = array();
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->keys[] = $idempotency_key;
				if ( 1 === count( $this->keys ) ) {
					throw new WooPaymentsApiException( 'Declined.', 'card_declined', 402, 'card_error' );
				}
				return array(
					'id'     => 'pi_new_key',
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_new_key' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		$declined_outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_new_key' ), 'key_d' );
		$sut->finalize_charge_idempotency_key( $order, $declined_outcome );
		$success_outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_new_key' ), 'key_e' );
		$sut->finalize_charge_idempotency_key( $order, $success_outcome );
		$fresh_order = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'key_d', 'key_e' ), $api_client->keys );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( '', $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox Native charge should retire its key on a definitive failure even when applying the outcome later fails.
	 *
	 * The post-lifecycle retirement never runs when applying the outcome throws (PaymentProcessingService rethrows before
	 * it), so a decline must retire the key as it is classified; otherwise the next attempt sends the old key and gets the
	 * stored decline back (area 2a #18). No finalize_charge_idempotency_key() call here models the failed apply.
	 */
	public function test_native_charge_retires_key_on_definitive_failure_without_lifecycle(): void {
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var string[] */
			public array $keys = array();
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->keys[] = $idempotency_key;
				if ( 1 === count( $this->keys ) ) {
					throw new WooPaymentsApiException( 'Declined.', 'card_declined', 402, 'card_error' );
				}
				return array(
					'id'     => 'pi_after_decline',
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_after_decline' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		$sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_after_decline' ), 'key_declined' );
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( '', $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );

		$sut->charge( PaymentContext::for_checkout( $fresh_order, OrderPaymentStore::GATEWAY_ID, 'pm_after_decline' ), 'key_next' );

		$this->assertSame( array( 'key_declined', 'key_next' ), $api_client->keys );
	}

	/**
	 * @testdox An idempotency conflict on a charge with $key_source and no ambiguity record warns whatever the logging setting: $warns.
	 *
	 * A kept key with no record of an ambiguous failure under it (a request cut off mid-send) gets no lookup: a retry with a
	 * new card sends a different body under it, which Stripe refuses with an idempotency_error. The refusal is definitive,
	 * so the key is retired and the next attempt charges under a fresh key, as every client attempt does
	 * (`class-wc-payments-api-client.php:2690`). The first request may have charged, so an always-on warning names the
	 * order and the key and says the key is retired (area 2a #7, ruling (a); unit 2a-9a; units timeout and timeout-b: the
	 * wording names the missing record and a record without a customer). A conflict on a fresh key is not a replay and gets no warning. The response is what the platform sends:
	 * it proxies the intention request and returns Stripe's status and error body unchanged (wpcom
	 * `wcpay/class-base-controller.php:476-490`), and Stripe answers a body mismatch with a 400 whose error type is
	 * `idempotency_error` and no code.
	 *
	 * @testWith ["a kept key", true]
	 *           ["a fresh key", false]
	 *
	 * @param string $key_source Whether the order kept a key from an earlier ambiguous attempt.
	 * @param bool   $warns      Whether the warning is written.
	 */
	public function test_native_charge_warns_when_kept_key_replay_is_refused( string $key_source, bool $warns ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order = $this->create_woopayments_order();
		if ( 'a kept key' === $key_source ) {
			$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, 'key_kept' );
			$order->save_meta_data();
		}
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 400 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'type'    => 'idempotency_error',
						'message' => "Keys for idempotent requests can only be used with the same parameters they were first used with. Try using a key other than 'key_kept' if you meant to execute a different request.",
					),
				)
			),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_conflict' );
		$sut    = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $account_service );
		$logger = RecordingWcLogger::install();

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_new_card' ), 'key_fresh' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$warnings = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && str_contains( $line[1], 'charge idempotency key' ) ) );
		if ( ! $warns ) {
			$this->assertSame( array(), $warnings );
			return;
		}
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'woopayments', $logger->lines[ $warnings[0] ][2] );
		$this->assertSame(
			'The charge idempotency key key_kept kept on order #' . $order->get_id() . ' was refused because the new payment request differs from the earlier one. The order has no record of an ambiguous failure under this key, the record names no customer to look up, or the payment is a scheduled renewal, so nothing looks up what the earlier request did: the key is retired and the next payment attempt for this order charges under a fresh key, with no protection against a charge the earlier request may have made.',
			$logger->lines[ $warnings[0] ][1]
		);
		// The line says the key is retired, so the code must have retired it.
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( $order->get_id(), $logger->contexts[ $warnings[0] ]['order_id'] ?? null );
		$this->assertSame( 'key_kept', $logger->contexts[ $warnings[0] ]['idempotency_key'] ?? null );
	}

	/**
	 * @testdox After $failure the order keeps its charge key, and the shopper's resubmit sends that key again.
	 *
	 * The platform proxies the intention request to Stripe and passes Stripe's status and error body through unchanged
	 * (wpcom `wcpay/class-base-controller.php:476-490`). A connection reset makes the transport retry the same key while
	 * the first request is still running, and Stripe answers 409 `idempotency_key_in_use`; a 5xx with a readable body can
	 * also follow a processed request. Neither is a definitive failure, so the key is kept and the resubmit replays the
	 * first request instead of charging under a fresh key. Client 11.1.0 has the same transport retry under one key
	 * (`class-wc-payments-api-client.php:2690`, `:2711-2769`) and keeps no key, so its resubmit can charge twice.
	 *
	 * @testWith ["a connection reset and an in-flight key conflict on the retry"]
	 *           ["a server error with a readable body"]
	 *
	 * @param string $failure How the first attempt fails.
	 */
	public function test_native_charge_keeps_its_key_when_the_failure_may_have_charged( string $failure ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$json                   = array( 'content-type' => 'application/json; charset=UTF-8' );
		$first                  = 'a server error with a readable body' === $failure
			? array(
				array(
					'response' => array( 'code' => 500 ),
					'headers'  => $json,
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'type'    => 'api_error',
								'message' => 'An unknown error occurred',
							),
						)
					),
				),
			)
			: array(
				new WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' ),
				array(
					'response' => array( 'code' => 409 ),
					'headers'  => $json,
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'code'    => 'idempotency_key_in_use',
								'type'    => 'invalid_request_error',
								'message' => 'There is currently another in-progress request using this Idempotent Key (that probably means you submitted twice, and the other request is still going through): key_first. Please try again later.',
							),
						)
					),
				),
			);
		$http_client->responses = array_merge(
			$first,
			array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => $json,
					'body'     => wp_json_encode(
						array(
							'id'     => 'pi_replayed',
							'status' => 'succeeded',
						)
					),
				),
			)
		);
		$account_service        = $this->create_account_service( false );
		$api_client             = new class() extends WooPaymentsApiClient {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_kept_key' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $account_service );

		$failed = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_kept_key' ), 'key_first' );
		$sut->finalize_charge_idempotency_key( $order, $failed );
		$attempts = $http_client->request_count;
		$sut->charge( PaymentContext::for_checkout( wc_get_order( $order->get_id() ), OrderPaymentStore::GATEWAY_ID, 'pm_kept_key' ), 'key_resubmit' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $failed->get_status() );
		$this->assertArrayNotHasKey( '_wcpay_definitive_charge_failure', $failed->get_data() );
		$this->assertSame( count( $first ), $attempts );
		$this->assertSame( array( 'key_first' ), array_values( array_unique( array_map( static fn( array $request ): string => $request['headers']['Idempotency-Key'] ?? '', $http_client->requests ) ) ), 'Every request, the resubmit included, must carry the kept key.' );
	}

	/**
	 * @testdox Native charge should use the caller key for a different order.
	 */
	public function test_native_charge_uses_a_different_order_key(): void {
		$first_order      = $this->create_woopayments_order();
		$second_order     = $this->create_woopayments_order();
		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var string[] */
			public array $keys = array();
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->keys[] = $idempotency_key;
				return array(
					'id'     => 'pi_' . count( $this->keys ),
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_different_order' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		$sut->charge( PaymentContext::for_checkout( $first_order, OrderPaymentStore::GATEWAY_ID, 'pm_different_order' ), 'key_a' );
		$sut->charge( PaymentContext::for_checkout( $second_order, OrderPaymentStore::GATEWAY_ID, 'pm_different_order' ), 'key_c' );

		$this->assertSame( array( 'key_a', 'key_c' ), $api_client->keys );
	}

	/**
	 * @testdox Native SetupIntent failure should retain an ambiguous charge key.
	 */
	public function test_native_setup_intent_failure_retains_ambiguous_charge_key(): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, 'key_ambiguous_charge' );
		$order->save_meta_data();
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a setup intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException Always.
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				throw new WooPaymentsApiException( 'SetupIntent declined.', 'card_declined', 402, 'card_error' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_setup_failure' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_setup_failure' ), 'key_setup' );
		$sut->finalize_charge_idempotency_key( $order, $outcome );
		$fresh_order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( 'key_ambiguous_charge', $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox Native pre-dispatch failure should retain an ambiguous charge key.
	 */
	public function test_native_pre_dispatch_failure_retains_ambiguous_charge_key(): void {
		set_transient( 'wcpay_minimum_amount_usd', 100, DAY_IN_SECONDS );
		$order = $this->create_woopayments_order( '0.50' );
		$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, 'key_ambiguous_charge' );
		$order->save_meta_data();
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->expects( $this->never() )->method( 'get_or_create_customer_id_for_order' );
		$sut = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $this->create_account_service( true ) );

		try {
			$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_pre_dispatch_failure' ), 'key_pre_dispatch' );
			$sut->finalize_charge_idempotency_key( $order, $outcome );
			$fresh_order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $fresh_order );
			$this->assertSame( 'key_ambiguous_charge', $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		} finally {
			delete_transient( 'wcpay_minimum_amount_usd' );
		}
	}

	/**
	 * @testdox An ambiguous charge failure records the order, the customer it was sent with and the time beside the kept key; a definitive one records nothing.
	 *
	 * The record is what tells a later refused resubmit to look up what this request did (data/t62-ambiguous-timeout-hold.md
	 * section 4.1). A 502 is the platform's own answer when its Stripe call failed (wpcom
	 * `wcpay/core/exceptions/class-platform-failure-exception.php:30`), so the charge may have gone through.
	 */
	public function test_ambiguous_charge_failure_records_the_ambiguity_beside_the_kept_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::stripe_card_declined() );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$before = time();
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$fresh  = wc_get_order( $order->get_id() );
		$record = $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertIsArray( $record );
		$this->assertSame( $order->get_id(), $record['order_id'] );
		$this->assertSame( array( 'cus_sent' ), $record['customers'] );
		$this->assertGreaterThanOrEqual( $before, $record['failed_at'] );

		$other = $this->create_woopayments_order();
		$this->charge_attempt( $sut, $other, 'pm_declined', 'key_declined' );
		$other = wc_get_order( $other->get_id() );

		$this->assertSame( '', $other->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ), 'A definitive failure must leave no record.' );
		$this->assertSame( '', $other->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox A later ambiguous answer under the kept key merges into the record: every customer sent, the earliest failure time and the note flag stay.
	 *
	 * Replacing the record would point the lookup at the later customer and move the account window past the earlier
	 * intent (review 45 F1). A record in the earlier single-customer shape is read as a list of one.
	 */
	public function test_later_ambiguous_answer_merges_into_the_record(): void {
		$order    = $this->create_woopayments_order();
		$earliest = time() - 1000;
		$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, 'key_first' );
		$order->update_meta_data(
			WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META,
			array(
				'order_id'           => $order->get_id(),
				'customer'           => 'cus_a',
				'failed_at'          => $earliest,
				'cannot_check_noted' => true,
			)
		);
		$order->save_meta_data();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::platform_bad_gateway() );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_b' );

		$this->charge_attempt( $sut, $order, 'pm_new', 'key_second' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_other', 'key_third' );
		$record = wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$this->assertIsArray( $record );
		$this->assertSame( $order->get_id(), $record['order_id'] ?? null );
		$this->assertSame( array( 'cus_a', 'cus_b' ), $record['customers'] ?? null, 'Every customer sent under the key, each once.' );
		$this->assertSame( $earliest, $record['failed_at'] ?? null, 'The earliest failure time stays.' );
		$this->assertTrue( $record['cannot_check_noted'] ?? false, 'The merchant was already told; the flag stays.' );
	}

	/**
	 * @testdox When the kept key was sent with more than one customer, the lookup reads the account's intents from the first failure, so the earlier payment pays the order and the new card is not charged.
	 *
	 * Native recreates a deleted customer before every charge (`WooPaymentsCustomerService::update_customer_for_order()`),
	 * so a resubmit under the kept key can go out with a new customer and fail ambiguously again. The earlier request's
	 * intent belongs to the first customer, so the new customer's complete list proves nothing about it (review 45 F1).
	 */
	public function test_kept_key_sent_with_two_customers_looks_up_the_account_list_from_the_first_failure(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = self::create_routed_http_client(
			array(
				'customer=cus_b'   => self::intent_list( array() ),
				'created%5Bgte%5D' => self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 1000, array( 'customer' => 'cus_a' ) ) ) ),
			)
		);
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$customer_service       = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturnOnConsecutiveCalls( 'cus_a', 'cus_b', 'cus_b' );
		$sut = $this->create_timeout_adapter( $http_client, '', $customer_service );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['failed_at'];
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_second', 'key_second' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );

		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id(), 'The earlier payment pays the order.' );
		$this->assertSame( 'cus_a', $outcome->get_customer_id() );
		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_first', self::account_list_trail( $failed_at ) ),
			self::request_trail( $http_client ),
			'The new card must not be charged.'
		);
	}

	/**
	 * @testdox A new-card resubmit refused under the key kept after an ambiguous failure lists the customer's intents before anything else is sent.
	 *
	 * Stripe answers a reused key with different parameters with a 400 `idempotency_error` only once it holds a finished
	 * result for the key (https://docs.stripe.com/api/idempotent_requests; Step 0 check 1), and the platform passes that
	 * answer through unchanged (wpcom `wcpay/class-base-controller.php:476-490`). So the earlier request finished, and its
	 * intents can be listed by the customer it was sent with.
	 */
	public function test_kept_key_refusal_after_ambiguity_lists_the_customer_intents_first(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array() ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100', 'POST intentions key_second' ),
			self::request_trail( $http_client )
		);
	}

	/**
	 * @testdox An idempotency refusal does not look anything up when $_dataName.
	 *
	 * Every other path keeps today's behaviour: the refusal is a definitive failure, the key and any record are retired,
	 * and no intents list is read.
	 *
	 * @dataProvider provide_idempotency_refusals_without_lookup
	 *
	 * @param string              $kept_key      Key kept on the order before the attempt, or '' for none.
	 * @param array<string,mixed> $record        Ambiguity record kept on the order before the attempt, or none.
	 * @param array<string,mixed> $provider_data Provider data of the attempt.
	 * @param string              $expected_key  Key the attempt sends.
	 */
	public function test_idempotency_refusal_without_lookup( string $kept_key, array $record, array $provider_data, string $expected_key ): void {
		$order = $this->create_woopayments_order();
		if ( '' !== $kept_key ) {
			$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, $kept_key );
		}
		if ( array() !== $record ) {
			$record['order_id'] = $record['order_id'] ?? $order->get_id();
			$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, $record );
		}
		$order->save_meta_data();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::stripe_idempotency_error( $expected_key ) );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$outcome = $this->charge_attempt( $sut, $order, 'pm_new', 'key_attempt', $provider_data );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions ' . $expected_key ), self::request_trail( $http_client ) );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'idempotency_error', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * Idempotency refusals outside the trigger.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}>
	 */
	public function provide_idempotency_refusals_without_lookup(): array {
		$record = array(
			'customer'  => 'cus_sent',
			'failed_at' => time(),
		);

		return array(
			'the attempt sends its own fresh key'      => array( '', array(), array(), 'key_attempt' ),
			'the kept key has no ambiguity record'     => array( 'key_kept', array(), array(), 'key_kept' ),
			'the payment is a scheduled renewal'       => array( 'key_kept', $record, array( 'scheduled_subscription_payment' => true ), 'key_kept' ),
			'the ambiguity record names another order' => array( 'key_kept', array_merge( $record, array( 'order_id' => 987654 ) ), array(), 'key_attempt' ),
			'the ambiguity record has no customer to look up' => array( 'key_kept', array_merge( $record, array( 'customer' => '' ) ), array(), 'key_kept' ),
		);
	}

	/**
	 * @testdox After a definitive failure retired the key, the next attempt sends a fresh key, so an idempotency refusal on it looks nothing up.
	 */
	public function test_attempt_after_a_definitive_failure_does_not_look_up(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::stripe_card_declined(), self::stripe_idempotency_error( 'key_third' ) );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_first', 'key_second' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_third' ), self::request_trail( $http_client ) );
	}

	/**
	 * @testdox A 409 idempotency_key_in_use under the kept key means the earlier request still runs: no lookup, key and record kept.
	 */
	public function test_in_use_conflict_under_the_kept_key_keeps_everything_without_lookup(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::http_json(
				409,
				array(
					'error' => array(
						'code'    => 'idempotency_key_in_use',
						'type'    => 'invalid_request_error',
						'message' => 'There is currently another in-progress request using this Idempotent Key (that probably means you submitted twice, and the other request is still going through): key_first. Please try again later.',
					),
				)
			),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox An idempotency_error type on an ambiguous answer ($_dataName) under the kept key looks nothing up and keeps the key and the record.
	 *
	 * A 409 or a server error means the earlier request may still be running, so its intent may not be listable yet
	 * (data/t62-ambiguous-timeout-hold.md, Step 0 check 2): the ambiguity classification wins over the error type, and the
	 * "key is retired" warning is not written because nothing is retired (review 44 F1).
	 *
	 * @dataProvider provide_ambiguous_idempotency_answers
	 *
	 * @param int $status HTTP status of the answer.
	 */
	public function test_ambiguous_answer_with_idempotency_type_under_the_kept_key_does_not_look_up( int $status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::http_json(
				$status,
				array(
					'error' => array(
						'type'    => 'idempotency_error',
						'code'    => 409 === $status ? 'idempotency_key_in_use' : 'idempotency_error',
						'message' => 'There is currently another in-progress request using this Idempotent Key: key_first. Please try again later.',
					),
				)
			),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ), 'No intents list may be read while the earlier request may still run.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame( array(), self::warning_lines( $logger ) );
	}

	/**
	 * Ambiguous answers that carry Stripe's idempotency_error type.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function provide_ambiguous_idempotency_answers(): array {
		return array(
			'409 conflict' => array( 409 ),
			'502 error'    => array( 502 ),
		);
	}

	/**
	 * @testdox When the earlier request's intent $status, the order is paid from it, the new card is not charged and the shopper is told so.
	 *
	 * The intent is the earlier request's late answer, so it is mapped with its own payment method and the customer it was
	 * sent with, never the new card, and no token is saved or attached for the new card. Applying a PaymentIntent outcome
	 * retires the key and the record.
	 *
	 * @testWith ["succeeded", "completed"]
	 *           ["requires_capture", "authorized"]
	 *           ["processing", "authorized"]
	 *
	 * @param string $status         Status of the earlier request's intent.
	 * @param string $outcome_status Expected outcome status.
	 */
	public function test_earlier_payment_found_pays_the_order_without_charging_the_new_card( string $status, string $outcome_status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list(
				array(
					self::order_intent( $this->create_woopayments_order(), 'pi_other_order', 'succeeded' ),
					self::order_intent( $order, 'pi_earlier', $status ),
				)
			),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second', array(), array( 'save_payment_method' => true ) );
		$fresh   = wc_get_order( $order->get_id() );
		$plan    = $outcome->get_effect_plan();

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100' ), self::request_trail( $http_client ), 'The new card must not be charged.' );
		$this->assertSame( $outcome_status, $outcome->get_status() );
		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_earlier', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_sent', $outcome->get_customer_id() );
		$this->assertSame( add_query_arg( 'wcpay_previous_successful_intent', 'yes', $order->get_checkout_order_received_url() ), $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] ?? null );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $plan->get_type() );
		$this->assertSame( 'pi_earlier', $plan->get_provider_result()['id'] ?? null );
		$this->assertFalse( $plan->should_apply_token_effects(), 'No token may be saved or attached for the new card.' );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
		$this->assertContains( "The earlier payment attempt for this order went through, so the customer's new payment was not taken.", self::note_texts( $order ) );
		$this->assertSame(
			array( 'The charge idempotency key key_first kept on order #' . $order->get_id() . ' was refused because the new payment request differs from the earlier one. The earlier request created PaymentIntent pi_earlier, which took the payment, so the order is paid from it and the new payment method is not charged.' ),
			self::warning_lines( $logger )
		);
	}

	/**
	 * @testdox An earlier payment for another amount than the order total fails the order with the overpayment notice and keeps the key, since money moved.
	 */
	public function test_earlier_payment_for_another_amount_refuses_and_keeps_the_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 800 ) ) ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'duplicate_payment_amount_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertStringContainsString( 'so we prevented an overpayment', (string) ( $outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? '' ) );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox An earlier payment in another currency than the order's refuses like an amount mismatch, even when the amounts match.
	 *
	 * Classic checkout reuses the session's order on a matching cart hash and rewrites its currency and total
	 * (`class-wc-checkout.php:413-436`), so a shopper who switched currency after the timeout can bring the order back with
	 * a total whose minor units equal the paid intent's (review 44 F5). The duplicate-payment guards compare amounts only, as
	 * client 11.1.0 does; this lookup also compares the currency.
	 */
	public function test_earlier_payment_in_another_currency_refuses_and_keeps_the_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 1000, array( 'currency' => 'eur' ) ) ) ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'duplicate_payment_amount_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertStringContainsString( '&euro;', (string) ( $outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? '' ), 'The paid amount shows in the intent\'s currency.' );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox When the earlier request left $_dataName for the order, the key and record are retired and the new card is charged once under the attempt key.
	 *
	 * The list is read-after-write consistent for a finished create (https://docs.stripe.com/search, Step 0 check 2) and
	 * every money call the earlier request could make creates an intent carrying the order's id and key (Step 0 check 3),
	 * so a successful lookup without such an intent holding money proves the earlier request took nothing.
	 *
	 * @dataProvider provide_earlier_requests_without_money
	 *
	 * @param array<int,array<string,mixed>> $intents       Intents listed for the customer, built from the order.
	 * @param bool                           $adds_note     Whether the merchant gets the "did not go through" note.
	 */
	public function test_no_earlier_payment_charges_the_new_card_now( array $intents, bool $adds_note ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array_map( static fn( array $intent ): array => self::order_intent( $order, ...$intent ), $intents ) ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );
		$body    = json_decode( (string) $http_client->requests[3]['body'], true );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100', 'POST intentions key_second' ),
			self::request_trail( $http_client )
		);
		$this->assertSame( 'pm_new', $body['payment_method'] ?? null );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_new_card', $outcome->get_provider_payment_id() );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
		$this->assertSame( $adds_note, in_array( 'An earlier payment attempt for this order did not go through.', self::note_texts( $order ), true ) );
		$this->assertSame(
			array( 'The charge idempotency key key_first kept on order #' . $order->get_id() . ' was refused because the new payment request differs from the earlier one. The order has ' . ( $adds_note ? 2 : 0 ) . ' PaymentIntent(s) from it and none took the payment, so the key is retired and the new payment method is charged now under a fresh key.' ),
			self::warning_lines( $logger )
		);
	}

	/**
	 * Earlier requests that took no money, as intents listed for the customer.
	 *
	 * Each intent is `[ id, status, amount, overrides ]` for order_intent(); the order is the one charged.
	 *
	 * @return array<string,array{0:array<int,array<int,mixed>>,1:bool}>
	 */
	public function provide_earlier_requests_without_money(): array {
		return array(
			'intents without money'   => array(
				array(
					array( 'pi_declined', 'requires_payment_method' ),
					array( 'pi_canceled', 'canceled' ),
				),
				true,
			),
			'no intent (others only)' => array(
				array(
					array( 'pi_other_order', 'succeeded', 1000, array( 'metadata' => array( 'order_id' => '987654' ) ) ),
					array( 'pi_wrong_key', 'succeeded', 1000, array( 'metadata' => array( 'order_key' => 'wc_order_not_this_one' ) ) ),
					array( 'pi_billing', 'succeeded', 1000, array( 'metadata' => array() ) ),
				),
				false,
			),
		);
	}

	/**
	 * @testdox A full page without the order's intent whose oldest intent is $_dataName proves no intent: $proves_none.
	 *
	 * The list is newest first, so a full page (`has_more`) that reaches back past the earlier request's window holds every
	 * intent created since; the earlier request was sent at most one request timeout plus its transport retries before the
	 * failure was recorded, and the window is 300 s (review 44 F6). A page that stops inside the window may have left the
	 * earlier intent for the next page, so it proves nothing and the attempt is refused.
	 *
	 * @dataProvider provide_full_pages_against_the_window
	 *
	 * @param int  $oldest_age  Seconds between the page's oldest intent and the recorded failure.
	 * @param bool $proves_none Whether the page proves the order has no intent, so the new card is charged.
	 */
	public function test_full_page_proves_no_intent_only_when_it_reaches_back_past_the_window( int $oldest_age, bool $proves_none ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::platform_bad_gateway() );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at              = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['failed_at'];
		$http_client->responses = array(
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list(
				array(
					self::order_intent( $order, 'pi_other_newer', 'succeeded', 1000, array( 'metadata' => array( 'order_id' => '987654' ) ) ),
					self::order_intent(
						$order,
						'pi_other_oldest',
						'succeeded',
						1000,
						array(
							'metadata' => array( 'order_id' => '987655' ),
							'created'  => $failed_at - $oldest_age,
						)
					),
				),
				true
			),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );

		if ( $proves_none ) {
			$this->assertSame( 'pi_new_card', $outcome->get_provider_payment_id() );
			$this->assertSame( 'POST intentions key_second', self::request_trail( $http_client )[3] ?? null );
			return;
		}
		$this->assertSame( 'wcpay_charge_lookup_failed', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
	}

	/**
	 * Ages of a full page's oldest intent against the 300 s window.
	 *
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function provide_full_pages_against_the_window(): array {
		return array(
			'301 s before the failure (past the window)' => array( 301, true ),
			'300 s before the failure (the window edge)' => array( 300, false ),
			'1 s before the failure'                     => array( 1, false ),
		);
	}

	/**
	 * @testdox An earlier succeeded intent whose charge was $_dataName holds money: $holds_money.
	 *
	 * A refunded or disputed PaymentIntent keeps its `succeeded` status, so a payment of the order that was given back (a
	 * refund and a reopened order, for example) must not complete the order again (review 44 F4). Under the platform's
	 * pinned Stripe-Version 2020-08-27 (wpcom `wcpay/utils/class-config.php:414-425`) a listed intent carries its charges
	 * with `refunded`, `amount_refunded` and `disputed`. An intent that gave its money back counts as one without money, so
	 * the new card is charged; a partly refunded one still holds money and pays the order.
	 *
	 * @dataProvider provide_given_back_charges
	 *
	 * @param array<string,mixed> $charge      The intent's charge.
	 * @param bool                $holds_money Whether the order is paid from the earlier intent.
	 */
	public function test_earlier_intent_that_gave_its_money_back_does_not_pay_the_order( array $charge, bool $holds_money ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list(
				array(
					self::order_intent(
						$order,
						'pi_earlier',
						'succeeded',
						1000,
						array( 'charges' => array( 'data' => array( array_merge( array( 'id' => 'ch_earlier' ), $charge ) ) ) )
					),
				)
			),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );

		$this->assertSame( $holds_money ? 'pi_earlier' : 'pi_new_card', $outcome->get_provider_payment_id() );
		$this->assertSame( $holds_money ? 3 : 4, count( $http_client->requests ), $holds_money ? 'The new card must not be charged.' : 'The new card is charged once.' );
	}

	/**
	 * Charges of an earlier succeeded intent.
	 *
	 * Each field is pinned on its own; Stripe sets `refunded` together with `amount_refunded` reaching the amount.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provide_given_back_charges(): array {
		return array(
			'flagged refunded'              => array(
				array(
					'amount'   => 1000,
					'refunded' => true,
				),
				false,
			),
			'refunded for its whole amount' => array(
				array(
					'amount'          => 1000,
					'amount_refunded' => 1000,
					'refunded'        => false,
				),
				false,
			),
			'disputed'                      => array(
				array(
					'amount'          => 1000,
					'amount_refunded' => 0,
					'refunded'        => false,
					'disputed'        => true,
				),
				false,
			),
			'partly refunded'               => array(
				array(
					'amount'          => 1000,
					'amount_refunded' => 300,
					'refunded'        => false,
					'disputed'        => false,
				),
				true,
			),
		);
	}

	/**
	 * @testdox A failed lookup ($_dataName) refuses the attempt with the generic notice, keeps the order status, the key and the record, and the next attempt looks again.
	 *
	 * An error proves nothing about money, so nothing is charged: the failure is not definitive, not a decline, and keeps
	 * the order status, so the shopper stays on checkout with the cart. A transport failure or a server error may pass,
	 * so it adds no merchant note. A customer list refused for good falls back to the account's list (monitor ruling B).
	 *
	 * @dataProvider provide_failed_lookups
	 *
	 * @param array<int,mixed> $lookup_responses Transport answers to the intents lists, customer list first.
	 */
	public function test_failed_lookup_refuses_and_the_next_attempt_looks_again( array $lookup_responses ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array_merge(
			array( self::platform_bad_gateway(), self::stripe_idempotency_error( 'key_first' ) ),
			$lookup_responses,
			array(
				self::stripe_idempotency_error( 'key_first' ),
				self::intent_list( array() ),
				self::succeeded_charge( 'pi_new_card', 'pm_new' ),
			)
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['failed_at'];
		$logger    = RecordingWcLogger::install();

		$refused = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$kept    = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $refused->get_status() );
		$this->assertSame( 'wcpay_charge_lookup_failed', $refused->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertTrue( $refused->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? null );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE, $refused->get_data(), 'The generic notice shows when the outcome has no shopper message.' );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $refused->get_data() );
		$this->assertNull( $refused->get_effect_plan() );
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame( array(), self::note_texts_containing( $kept, 'could not be checked' ), 'A lookup that may pass adds no merchant note.' );
		$this->assertSame(
			array( 'The charge idempotency key key_first kept on order #' . $order->get_id() . ' was refused because the new payment request differs from the earlier one, and the PaymentIntents of the order could not be listed to learn whether the earlier request took the payment. This payment attempt is refused without a charge; the key is kept and the next attempt looks again.' ),
			self::warning_lines( $logger )
		);

		$charged = $this->charge_attempt( $sut, $kept, 'pm_new', 'key_third' );

		$this->assertSame(
			array_merge(
				array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100' ),
				2 === count( $lookup_responses ) ? array( self::account_list_trail( $failed_at ) ) : array(),
				array(
					'POST intentions key_first',
					'GET intentions?test_mode=0&customer=cus_sent&limit=100',
					'POST intentions key_third',
				)
			),
			self::request_trail( $http_client )
		);
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $charged->get_status() );
	}

	/**
	 * Lookups that fail but may pass on a later attempt.
	 *
	 * @return array<string,array{0:array<int,mixed>}>
	 */
	public function provide_failed_lookups(): array {
		return array(
			'transport error'                   => array( array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ) ),
			'server error on the customer list' => array( array( self::stripe_api_error( 500 ) ) ),
			'no list in the answer'             => array( array( self::http_json( 200, array( 'object' => 'list' ) ) ) ),
			'a full page of newer intents with more to read' => array(
				array(
					self::intent_list(
						array(
							array(
								'id'       => 'pi_newer',
								'status'   => 'succeeded',
								'created'  => time(),
								'metadata' => array(),
							),
						),
						true
					),
				),
			),
			'customer missing, then a transport error on the account list' => array(
				array( self::stripe_no_such_customer(), new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
			),
			'customer missing, then a server error on the account list' => array(
				array( self::stripe_no_such_customer(), self::platform_bad_gateway() ),
			),
		);
	}

	/**
	 * @testdox When the customer's intents cannot be listed for good, the account's intents since the failure settle it: the earlier payment pays the order.
	 *
	 * A deleted customer keeps its PaymentIntents, so the account's list, filtered to intents created from 300 s before
	 * the recorded failure and matched on the order id and key, still shows the earlier request's intent (monitor ruling
	 * B). The platform forwards `created` to Stripe's list unchanged (wpcom `wcpay/class-intentions-controller.php:198-210`).
	 */
	public function test_customer_list_refused_falls_back_to_the_account_list(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			self::intent_list(
				array(
					self::order_intent( $this->create_woopayments_order(), 'pi_other_order', 'succeeded' ),
					self::order_intent( $order, 'pi_earlier', 'succeeded' ),
				)
			),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['failed_at'];

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100', self::account_list_trail( $failed_at ) ),
			self::request_trail( $http_client ),
			'The new card must not be charged.'
		);
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id() );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', (string) ( $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] ?? '' ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * @testdox When neither list can settle the earlier request ($_dataName), every attempt is refused without a charge and the merchant gets one note.
	 *
	 * Monitor ruling B: the order is not charged while the earlier request cannot be checked. A definitive refusal, or an
	 * account page that cannot be proven complete (the window only grows), cannot change on a later attempt, so the
	 * merchant is told once to check the payment in WooPayments. The block is per order.
	 *
	 * @dataProvider provide_account_lists_that_cannot_settle
	 *
	 * @param array<string,mixed> $account_answer Transport answer to the account's intents list.
	 */
	public function test_lookup_that_cannot_check_refuses_every_attempt_with_one_note( array $account_answer ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			$account_answer,
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			$account_answer,
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$first  = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$second = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );
		$kept   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_first' ), array_values( array_filter( self::request_trail( $http_client ), static fn( string $line ): bool => 0 === strpos( $line, 'POST' ) ) ), 'Only the first send and the two refused kept-key sends; the new card is never charged.' );
		$this->assertCount( 7, $http_client->requests );
		foreach ( array( $first, $second ) as $refused ) {
			$this->assertSame( PaymentOutcome::STATUS_FAILED, $refused->get_status() );
			$this->assertSame( 'wcpay_charge_lookup_failed', $refused->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
			$this->assertTrue( $refused->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? null );
			$this->assertArrayNotHasKey( PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE, $refused->get_data() );
			$this->assertNull( $refused->get_effect_plan() );
		}
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame(
			array( "The earlier payment attempt for this order could not be checked, so the customer's new payment was not taken. Please check for this payment in WooPayments before the customer tries again." ),
			self::note_texts_containing( $kept, 'could not be checked' ),
			'One note per order, not one per attempt.'
		);
		$line = 'The charge idempotency key key_first kept on order #' . $order->get_id() . " was refused because the new payment request differs from the earlier one, and neither the customer's nor the account's PaymentIntents could be listed to learn whether the earlier request took the payment. This payment attempt is refused without a charge; the key is kept, every attempt on this order is refused the same way until a list can be read, and an order note asks the merchant to check the payment.";
		$this->assertSame( array( $line, $line ), self::warning_lines( $logger ) );
	}

	/**
	 * Account-list answers that cannot settle the earlier request.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_account_lists_that_cannot_settle(): array {
		return array(
			'the account list refused'              => array(
				self::http_json(
					400,
					array(
						'error' => array(
							'type'    => 'invalid_request_error',
							'code'    => 'parameter_unknown',
							'param'   => 'created[gte]',
							'message' => 'Received unknown parameter: created[gte]',
						),
					)
				),
			),
			'a full account page without the order' => array(
				self::intent_list(
					array(
						array(
							'id'       => 'pi_other_shopper',
							'status'   => 'succeeded',
							'created'  => time(),
							'metadata' => array( 'order_id' => '987654' ),
						),
					),
					true
				),
			),
		);
	}

	/**
	 * @testdox While the ambiguity record exists, a refusal the platform made itself keeps the key and the record, and the next attempt reaches the lookup.
	 *
	 * The platform's pre-charge refusals never reach Stripe, so they say nothing about the earlier request under the key.
	 * They arrive as WordPress REST errors with a top-level code and no Stripe error object (wpcom
	 * `wcpay/core/exceptions/class-rest-exception.php:54-64`, `class-fraud-rule-exception.php:27`,
	 * `class-api-request-dispatcher.php:156-205`; `wp-includes/rest-api.php:3553-3557`).
	 */
	public function test_platform_refusal_under_the_kept_key_keeps_key_and_record(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::http_json(
				403,
				array(
					'code'    => 'wcpay_blocked_by_fraud_rule',
					'message' => "There's a problem with this payment. Please try again or use a different payment method.",
					'data'    => array( 'status' => 403 ),
				)
			),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded' ) ) ),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$blocked = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$kept    = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $blocked->get_status() );
		$this->assertSame( 'wcpay_blocked_by_fraud_rule', $blocked->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );

		$paid = $this->charge_attempt( $sut, $kept, 'pm_other', 'key_third' );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100' ),
			self::request_trail( $http_client )
		);
		$this->assertSame( 'pi_earlier', $paid->get_provider_payment_id() );
	}

	/**
	 * @testdox While the ambiguity record exists, a missing customer is not recreated: no charge under a recovery key, and the key and the record stay.
	 *
	 * The recovery would send the new card under `K:customer-recovery`, a key Stripe never saw, with no lookup of what the
	 * earlier request under K did (review 44 F2). The answer is the platform's own: its fraud-rule check reads the customer
	 * before the Stripe charge (wpcom `wcpay/class-intentions-controller.php:1322-1328`) and turns Stripe's missing-customer
	 * error into a REST error with a top-level code (`stripe/class-stripe-client.php:878-908`, `:920-940`).
	 */
	public function test_missing_customer_under_the_kept_key_is_not_recreated_while_the_record_exists(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::http_json(
				404,
				array(
					'code'    => 'resource_missing',
					'message' => 'Invalid request error: resource_missing (customer id)',
					'data'    => array( 'status' => 404 ),
				)
			),
			self::succeeded_charge( 'pi_recovered', 'pm_new' ),
		);
		$customer_service       = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order', 'recreate_customer_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_sent' );
		$customer_service->expects( $this->never() )->method( 'recreate_customer_for_order' );
		$sut = $this->create_timeout_adapter( $http_client, 'cus_sent', $customer_service );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ), 'The new card must not be charged under a recovery key.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox Without an ambiguity record, a refusal the platform made itself retires the key as before.
	 */
	public function test_platform_refusal_without_ambiguity_record_retires_the_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			self::http_json(
				400,
				array(
					'code'    => 'wcpay_card_testing_prevention',
					'message' => "We're not able to process this purchase. Please try again later.",
					'data'    => array( 'status' => 400 ),
				)
			),
		);
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );

		$outcome = $this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$this->assertSame( 'wcpay_card_testing_prevention', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox While the ambiguity record exists, a Stripe $_dataName under the kept key retires the key and the record: $retires.
	 *
	 * Monitor ruling A (data/t62-ambiguous-timeout-hold.md, decision on the implementation): only a card error or a
	 * success proves Stripe processed this request under the key, fresh or as the stored result of the earlier request,
	 * so either settles what the earlier request did. Stripe answers a 429 and most parameter-validation 400s before its
	 * idempotency layer and stores neither (https://docs.stripe.com/error-low-level), so they say nothing about the
	 * earlier request: the key and the record stay for the next attempt's lookup.
	 *
	 * @dataProvider provide_stripe_answers_under_the_kept_key
	 *
	 * @param array<string,mixed> $answer  Stripe's answer, passed through by the platform.
	 * @param bool                $retires Whether the key and the record are retired.
	 */
	public function test_stripe_answer_under_the_kept_key_retires_only_a_settled_request( array $answer, bool $retires ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), $answer );
		$sut                    = $this->create_timeout_adapter( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_first', 'key_second' );
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$record = $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( $retires ? '' : 'key_first', $fresh->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( $retires ? '' : array( 'cus_sent' ), is_array( $record ) ? $record['customers'] : $record );
	}

	/**
	 * Stripe's answers to a charge sent under the kept key.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provide_stripe_answers_under_the_kept_key(): array {
		return array(
			'card decline'                     => array( self::stripe_card_declined(), true ),
			'PaymentIntent answer'             => array( self::succeeded_charge( 'pi_replayed', 'pm_first' ), true ),
			'rate limit (429)'                 => array(
				self::http_json(
					429,
					array(
						'error' => array(
							'type'    => 'invalid_request_error',
							'code'    => 'rate_limit',
							'message' => 'Too many requests hit the API too quickly. We recommend an exponential backoff of your requests.',
						),
					)
				),
				false,
			),
			'parameter validation error (400)' => array(
				self::http_json(
					400,
					array(
						'error' => array(
							'type'    => 'invalid_request_error',
							'code'    => 'parameter_invalid_integer',
							'param'   => 'amount',
							'message' => 'Invalid integer: 10.5',
						),
					)
				),
				false,
			),
		);
	}

	/**
	 * Build an adapter on the real API client, with only the platform's HTTP answers faked.
	 *
	 * @param FakeWooPaymentsHttpClient       $http_client      Platform answers.
	 * @param string                          $customer_id      Customer the charges are sent with.
	 * @param WooPaymentsCustomerService|null $customer_service Customer service to use instead of one that only returns the customer.
	 * @return WooPaymentsProviderGatewayAdapter
	 */
	private function create_timeout_adapter( FakeWooPaymentsHttpClient $http_client, string $customer_id, ?WooPaymentsCustomerService $customer_service = null ): WooPaymentsProviderGatewayAdapter {
		$account_service = $this->create_account_service( false );
		$api_client      = new class() extends WooPaymentsApiClient {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service );
		if ( null === $customer_service ) {
			$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
			$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( $customer_id );
		}

		return $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, null, $account_service );
	}

	/**
	 * Run one checkout charge the way the processing service does: charge, then the post-lifecycle key step.
	 *
	 * @param WooPaymentsProviderGatewayAdapter $sut           Adapter.
	 * @param WC_Order                          $order         Order as the attempt loads it.
	 * @param string                            $pm            Payment method the shopper submits.
	 * @param string                            $attempt_key   Key minted for the attempt.
	 * @param array<string,mixed>               $provider_data Provider data.
	 * @param array<string,mixed>               $payment_data  Payment data.
	 * @return PaymentOutcome
	 */
	private function charge_attempt( WooPaymentsProviderGatewayAdapter $sut, WC_Order $order, string $pm, string $attempt_key, array $provider_data = array(), array $payment_data = array() ): PaymentOutcome {
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, $pm, $payment_data, $provider_data ), $attempt_key );
		$sut->finalize_charge_idempotency_key( $order, $outcome );

		return $outcome;
	}

	/**
	 * Build a fake platform that answers a request whose path contains a route's needle with that route's answer, and any
	 * other request from the queue.
	 *
	 * @param array<string,array<string,mixed>> $routes Answers by path needle.
	 * @return FakeWooPaymentsHttpClient
	 */
	private static function create_routed_http_client( array $routes ): FakeWooPaymentsHttpClient {
		$http_client         = new class() extends FakeWooPaymentsHttpClient {
			/**
			 * Answers by path needle.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			public array $routes = array();

			/**
			 * Answer a routed path from its route, any other from the queue.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           WPCOM path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Request body.
			 * @param int         $timeout        Request timeout.
			 * @param bool        $use_user_token Whether to sign with the connection-owner user token.
			 * @param bool        $blocking       Whether the request should block for the response.
			 * @return mixed
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				foreach ( $this->routes as $needle => $answer ) {
					if ( false !== strpos( $path, $needle ) ) {
						array_unshift( $this->responses, $answer );
						break;
					}
				}

				return parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
			}
		};
		$http_client->routes = $routes;

		return $http_client;
	}

	/**
	 * Get the platform requests as "METHOD path key", the path relative to the site's WooPayments root.
	 *
	 * @param FakeWooPaymentsHttpClient $http_client Recorded transport.
	 * @return string[]
	 */
	private static function request_trail( FakeWooPaymentsHttpClient $http_client ): array {
		return array_map(
			static fn( array $request ): string => trim( $request['method'] . ' ' . preg_replace( '#^/sites/\d+/wcpay/#', '', (string) $request['path'] ) . ' ' . ( $request['headers']['Idempotency-Key'] ?? '' ) ),
			$http_client->requests
		);
	}

	/**
	 * Build a JSON transport response.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded body.
	 * @return array<string,mixed>
	 */
	private static function http_json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * The platform's 502 when its own Stripe call failed (wpcom `class-platform-failure-exception.php:30`).
	 *
	 * @return array<string,mixed>
	 */
	private static function platform_bad_gateway(): array {
		return self::http_json(
			502,
			array(
				'code'    => 'wcpay_request_failure',
				'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
				'data'    => array( 'status' => 502 ),
			)
		);
	}

	/**
	 * Stripe's answer to a reused key with different parameters, passed through by the platform.
	 *
	 * @param string $key Refused key.
	 * @return array<string,mixed>
	 */
	private static function stripe_idempotency_error( string $key ): array {
		return self::http_json(
			400,
			array(
				'error' => array(
					'type'    => 'idempotency_error',
					'message' => "Keys for idempotent requests can only be used with the same parameters they were first used with. Try using a key other than '$key' if you meant to execute a different request.",
				),
			)
		);
	}

	/**
	 * Stripe's answer for a deleted customer, passed through by the platform.
	 *
	 * @return array<string,mixed>
	 */
	private static function stripe_no_such_customer(): array {
		return self::http_json(
			404,
			array(
				'error' => array(
					'type'    => 'invalid_request_error',
					'code'    => 'resource_missing',
					'param'   => 'customer',
					'message' => "No such customer: 'cus_sent'",
				),
			)
		);
	}

	/**
	 * Stripe's server error, passed through by the platform.
	 *
	 * @param int $code HTTP status.
	 * @return array<string,mixed>
	 */
	private static function stripe_api_error( int $code ): array {
		return self::http_json(
			$code,
			array(
				'error' => array(
					'type'    => 'api_error',
					'message' => 'An unknown error occurred',
				),
			)
		);
	}

	/**
	 * The account-wide intents list request, as request_trail() prints it.
	 *
	 * @param int $failed_at Unix time of the recorded ambiguous failure.
	 * @return string
	 */
	private static function account_list_trail( int $failed_at ): string {
		return 'GET intentions?test_mode=0&created%5Bgte%5D=' . ( $failed_at - 300 ) . '&limit=100';
	}

	/**
	 * Stripe's card decline, passed through by the platform.
	 *
	 * @return array<string,mixed>
	 */
	private static function stripe_card_declined(): array {
		return self::http_json(
			402,
			array(
				'error' => array(
					'type'         => 'card_error',
					'code'         => 'card_declined',
					'decline_code' => 'generic_decline',
					'message'      => 'Your card was declined.',
				),
			)
		);
	}

	/**
	 * A succeeded create-and-confirm answer.
	 *
	 * @param string $intent_id Intent ID.
	 * @param string $pm        Payment method charged.
	 * @return array<string,mixed>
	 */
	private static function succeeded_charge( string $intent_id, string $pm ): array {
		return self::http_json(
			200,
			array(
				'id'             => $intent_id,
				'status'         => 'succeeded',
				'amount'         => 1000,
				'currency'       => 'usd',
				'customer'       => 'cus_sent',
				'payment_method' => $pm,
			)
		);
	}

	/**
	 * The platform's intents list (Stripe's list object, Step 0 check 4).
	 *
	 * @param array<int,array<string,mixed>> $intents  Intents, newest first.
	 * @param bool                           $has_more Whether more pages exist.
	 * @return array<string,mixed>
	 */
	private static function intent_list( array $intents, bool $has_more = false ): array {
		return self::http_json(
			200,
			array(
				'object'   => 'list',
				'data'     => $intents,
				'has_more' => $has_more,
				'url'      => '/v1/payment_intents',
			)
		);
	}

	/**
	 * An intent the store's charge path created for an order, with the metadata it sends.
	 *
	 * @param WC_Order            $order     Order.
	 * @param string              $intent_id Intent ID.
	 * @param string              $status    Intent status.
	 * @param int                 $amount    Amount in minor units.
	 * @param array<string,mixed> $overrides Fields to replace; a metadata override merges into the order metadata.
	 * @return array<string,mixed>
	 */
	private static function order_intent( WC_Order $order, string $intent_id, string $status, int $amount = 1000, array $overrides = array() ): array {
		$metadata = array_merge(
			array(
				'order_id'     => (string) $order->get_id(),
				'order_key'    => $order->get_order_key(),
				'order_number' => (string) $order->get_order_number(),
			),
			$overrides['metadata'] ?? array()
		);
		if ( array_key_exists( 'metadata', $overrides ) && array() === $overrides['metadata'] ) {
			$metadata = array();
		}
		unset( $overrides['metadata'] );

		return array_merge(
			array(
				'id'             => $intent_id,
				'object'         => 'payment_intent',
				'status'         => $status,
				'amount'         => $amount,
				'currency'       => 'usd',
				'created'        => time() - 60,
				'customer'       => 'cus_sent',
				'payment_method' => 'pm_earlier',
				'metadata'       => $metadata,
			),
			$overrides
		);
	}

	/**
	 * Get the texts of an order's notes.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private static function note_texts( WC_Order $order ): array {
		return array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Get the texts of an order's notes that contain a phrase.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $phrase Phrase.
	 * @return string[]
	 */
	private static function note_texts_containing( WC_Order $order, string $phrase ): array {
		return array_values( array_filter( self::note_texts( $order ), static fn( string $text ): bool => false !== strpos( $text, $phrase ) ) );
	}

	/**
	 * Get the warning lines a recording logger received.
	 *
	 * @param RecordingWcLogger $logger Logger.
	 * @return string[]
	 */
	private static function warning_lines( RecordingWcLogger $logger ): array {
		return array_values( array_map( static fn( array $line ): string => $line[1], array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ) );
	}

	/**
	 * @testdox Native charge declines write the payment-failed order note with the seller message and allow fraud meta.
	 */
	public function test_charge_decline_composes_failed_note_and_allow_fraud_meta(): void {
		$order      = $this->create_woopayments_order( '25.00' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new WooPaymentsApiException(
					'Error: Your card was declined.',
					'card_declined',
					402,
					'card_error',
					'do_not_honor',
					array(),
					'pi_declined_test',
					'The bank did not return any further details with this decline.'
				);
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_declined_test', $outcome->get_provider_payment_id() );
		$this->assertArrayHasKey( PaymentOutcome::DATA_NOTE, $data );
		$this->assertStringContainsString( '<strong>failed</strong> to complete with the following message:', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertStringContainsString( 'Error: Your card was declined. The bank did not return any further details with this decline', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertNotEmpty( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ?? array() );
		$this->assertSame( 'allow', ( $data[ PaymentOutcome::DATA_META ] ?? array() )['_wcpay_fraud_meta_box_type'] ?? null, 'A card error means fraud checks passed; the meta box must show allow.' );
	}

	/**
	 * @testdox Native charge declines without a card error keep the failed note but no fraud meta box type.
	 */
	public function test_charge_decline_without_card_error_writes_note_without_fraud_meta(): void {
		$order      = $this->create_woopayments_order( '25.00' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new WooPaymentsApiException( 'Error: Upstream provider unavailable.', 'api_connection_error', 502, 'api_error' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertStringContainsString( 'Error: Upstream provider unavailable', $data[ PaymentOutcome::DATA_NOTE ] ?? '' );
		$this->assertArrayNotHasKey( '_wcpay_fraud_meta_box_type', $data[ PaymentOutcome::DATA_META ] ?? array() );
	}

	/**
	 * @testdox Native charge blocked by fraud rules records the block state without failing the order.
	 */
	public function test_charge_blocked_by_fraud_rules_records_block_state(): void {
		$order      = $this->create_woopayments_order( '25.00' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new WooPaymentsApiException(
					'Error: Transaction blocked by fraud rules.',
					'wcpay_blocked_by_fraud_rule',
					402,
					'',
					'',
					array( 'ruleset_results' => array( 'international_ip_address' => 'block' ) ),
					'pi_blocked_test'
				);
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();
		$meta    = $data[ PaymentOutcome::DATA_META ] ?? array();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertTrue( $data[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? false, 'A fraud block must not fail the order; the merchant decides whether to cancel.' );
		$this->assertSame( 'block', $meta['_wcpay_fraud_outcome_status'] ?? null );
		$this->assertSame( 'block', $meta['_wcpay_fraud_meta_box_type'] ?? null );
		$this->assertSame( wp_json_encode( array( 'international_ip_address' => 'block' ) ), $meta['_wcpay_fraud_ruleset_results'] ?? null );
		$this->assertSame( 'canceled', $meta['_intention_status'] ?? null );
		$this->assertSame( 'pi_blocked_test', $outcome->get_provider_payment_id() );
		$this->assertStringContainsString( '<strong>blocked</strong> by the following risk filters', $data[ PaymentOutcome::DATA_NOTE ] ?? '' );
		// Client 11.1.0 test_process_payment_marks_order_as_blocked_for_fraud: the shopper notice equals the thrown message.
		$this->assertSame( 'Error: Transaction blocked by fraud rules.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * @testdox Native charge treats an incorrect_zip decline as a fraud block only while the AVS rule is enabled.
	 */
	public function test_charge_treats_incorrect_zip_as_block_only_with_avs_rule_enabled(): void {
		delete_transient( 'wcpay_fraud_protection_settings' );
		set_transient( 'wcpay_fraud_protection_settings', array( array( 'key' => 'avs_verification' ) ), DAY_IN_SECONDS );

		$make_api_client = function () {
			return new class() extends WooPaymentsApiClient {
				// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
				/**
				 * Create and confirm a payment intention.
				 *
				 * @param array<string,mixed> $request_data Request data.
				 * @param string              $idempotency_key Idempotency key.
				 * @return array<string,mixed>
				 */
				public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
					throw new WooPaymentsApiException(
						'Error: Your postal code failed validation.',
						'incorrect_zip',
						402,
						'card_error',
						'',
						array(),
						'pi_avs_test'
					);
				}
				// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

				/**
				 * Tell whether the transport is available.
				 *
				 * @return bool
				 */
				public function is_available(): bool {
					return true;
				}
			};
		};

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$order   = $this->create_woopayments_order( '25.00' );
		$gateway = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$sut     = $this->create_adapter( $gateway, $make_api_client(), $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$meta    = $outcome->get_data()[ PaymentOutcome::DATA_META ] ?? array();

		$this->assertSame( 'block', $meta['_wcpay_fraud_meta_box_type'] ?? null, 'With the AVS rule enabled, an incorrect_zip decline is an AVS block.' );
		$this->assertSame( wp_json_encode( array( 'avs_verification' => 'block' ) ), $meta['_wcpay_fraud_ruleset_results'] ?? null );
		// Client 11.1.0 utils.php:799 with the fraud flag (gw:1426): no postal-code hint, the platform message shows
		// (client test_process_payment_marks_order_as_blocked_for_fraud_avs_mismatch asserts the thrown message).
		$this->assertSame(
			'Error: Your postal code failed validation.',
			$outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null,
			'An AVS-blocked shopper sees the platform message, not the postal-code hint.'
		);

		delete_transient( 'wcpay_fraud_protection_settings' );

		$order   = $this->create_woopayments_order( '25.00' );
		$sut     = $this->create_adapter( $gateway, $make_api_client(), $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();
		$meta    = $data[ PaymentOutcome::DATA_META ] ?? array();

		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] ?? null, 'Without the AVS rule, an incorrect_zip decline is an ordinary card error.' );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_PRESERVE_ORDER_STATUS, $data );
	}

	/**
	 * @testdox Native charge defers settlement exchange-rate metadata to its effect plan.
	 */
	public function test_native_charge_defers_settlement_exchange_rate_meta_to_effect_plan(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_woopayments_order( '40.00' );
		$order->set_currency( 'GBP' );
		$order->save();

		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 4000 !== $request_data['amount'] || 'gbp' !== $request_data['currency'] || 'key_charge' !== $idempotency_key ) {
					throw new \RuntimeException( 'Unexpected converted-currency charge request payload.' );
				}

				return array(
					'id'             => 'pi_converted',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_converted',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'gbp',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_converted',
								'payment_method'         => 'pm_native',
								'balance_transaction'    => array(
									'id'            => 'txn_converted',
									'exchange_rate' => 1.33127,
								),
								'amount'                 => 4000,
								'currency'               => 'gbp',
								'application_fee_amount' => 156,
								'fee_breakdown_v1'       => array(
									'totals' => array(
										'fee' => array(
											'amount'   => 156,
											'currency' => 'usd',
											'rate'     => array(
												'percentage' => 0.039,
												'fixed' => 30,
												'fixed_currency' => 'usd',
											),
										),
										'net' => array(
											'amount'   => 5170,
											'currency' => 'usd',
										),
									),
								),
							),
						),
					),
				);
			}

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			$customer_service,
			null,
			$this->create_account_service(
				true,
				array(),
				array(
					'store_currencies' => array(
						'default' => 'usd',
					),
				)
			)
		);
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertSame( 1.33127, $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['balance_transaction']['exchange_rate'] );
	}

	/**
	 * @testdox Charge should flag platform-created payment methods for WCPay.
	 */
	public function test_charge_flags_platform_created_payment_methods_for_wcpay(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_platform',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_platform',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_connected',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_platform',
				array(),
				array( 'is_platform_payment_method' => true )
			),
			'key_charge'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pm_platform', $api_client->last_request_data['payment_method'] );
		$this->assertTrue( $api_client->last_request_data['is_platform_payment_method'] );
	}

	/**
	 * @testdox Charge should send manual capture mode to native payment intents when enabled.
	 */
	public function test_charge_sends_manual_capture_method_to_native_payment_intents_when_enabled(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_manual_native',
					'status'         => 'requires_capture',
					'customer'       => 'cus_manual_native',
					'payment_method' => 'pm_manual_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'       => 'ch_manual_native',
								'captured' => false,
							),
						),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_manual_native' );

		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			$customer_service,
			null,
			$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
		);
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_manual_native' ), 'key_charge' );

		$this->assertSame( 'manual', $api_client->last_request_data['capture_method'] ?? null );
		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
	}

	/**
	 * @testdox Charge should add the pre-debit approval note when the intent reports a customer notification.
	 */
	public function test_charge_adds_customer_notification_note_for_pre_debit_approval(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$completes_at     = 1893510000;
		$api_client       = new class( $completes_at ) extends WooPaymentsApiClient {
			/**
			 * Notification deadline.
			 *
			 * @var int
			 */
			private int $completes_at;

			/**
			 * Constructor.
			 *
			 * @param int $completes_at Notification deadline.
			 */
			public function __construct( int $completes_at ) {
				$this->completes_at = $completes_at;
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'pi_notification',
					'status'         => 'processing',
					'customer'       => 'cus_notification',
					'payment_method' => 'pm_notification',
					'currency'       => 'inr',
					'processing'     => array(
						'card' => array(
							'customer_notification' => array(
								'approval_requested' => true,
								'completes_at'       => $this->completes_at,
							),
						),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_notification' );

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service );
		$sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_notification' ), 'key_notification' );

		$expected_note = sprintf(
			'The customer must authorize this payment via a notification sent to them by the bank which issued their card. The authorization must be completed before %1$s at %2$s, when the charge will be attempted.',
			wp_date( get_option( 'date_format', 'F j, Y' ), $completes_at, wp_timezone() ),
			wp_date( get_option( 'time_format', 'g:i a' ), $completes_at, wp_timezone() )
		);
		$notes         = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$matching      = array_filter(
			$notes,
			static function ( $note ) use ( $expected_note ): bool {
				return $expected_note === (string) $note->content;
			}
		);
		$this->assertCount( 1, $matching );
	}

	/**
	 * @testdox Charge should not add the pre-debit approval note without a requested approval.
	 */
	public function test_charge_skips_customer_notification_note_without_approval(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'pi_no_notification',
					'status'         => 'processing',
					'customer'       => 'cus_no_notification',
					'payment_method' => 'pm_no_notification',
					'currency'       => 'usd',
					'processing'     => array(
						'card' => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_no_notification' );

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service );
		$sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_no_notification' ), 'key_no_notification' );

		$notes    = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$matching = array_filter(
			$notes,
			static function ( $note ): bool {
				return false !== strpos( (string) $note->content, 'must authorize this payment via a notification' );
			}
		);
		$this->assertCount( 0, $matching );
	}

	/**
	 * @testdox Scheduled renewal charges should stay automatic when manual capture is enabled.
	 */
	public function test_charge_keeps_scheduled_renewal_capture_method_automatic_when_manual_capture_is_enabled(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_renewal_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_renewal_native',
					'payment_method' => 'pm_renewal_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'       => 'ch_renewal_native',
								'captured' => true,
							),
						),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_renewal_native' );

		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			$customer_service,
			null,
			$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
		);
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_renewal_native',
				array(),
				array( 'scheduled_subscription_payment' => true )
			),
			'key_charge'
		);

		$this->assertSame( 'automatic', $api_client->last_request_data['capture_method'] ?? null );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
	}

	/**
	 * @testdox Native charge decoding defers account mode metadata to effect application.
	 */
	public function test_native_charge_defers_account_mode_to_effect_application(): void {
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$account_service  = $this->create_account_service( true );

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
	}

	/**
	 * @testdox Charge should recreate and retry under a derived key, kept on the order, when the native transport reports a missing customer.
	 *
	 * The retry sends another customer, and Stripe refuses a reused idempotency key with a different body, so the retry
	 * gets its own key; the order keeps it so an ambiguous failure of the retry replays the retry (area 2a #15).
	 */
	public function test_charge_retries_after_missing_customer_by_recreating_customer(): void {
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Number of attempts.
			 *
			 * @var int
			 */
			private int $attempt = 0;

			/**
			 * Idempotency keys sent.
			 *
			 * @var string[]
			 */
			public array $keys = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->keys[] = $idempotency_key;
				++$this->attempt;

				if ( 1 === $this->attempt ) {
					if ( 'cus_missing' !== $request_data['customer'] ) {
						throw new \RuntimeException( 'First attempt must use the original customer.' );
					}

					throw new WooPaymentsApiException( 'No such customer: customer', 'resource_missing', 404 );
				}

				if ( 'cus_recreated' !== $request_data['customer'] ) {
					throw new \RuntimeException( 'Second attempt must use the recreated customer.' );
				}

				return array(
					'id'             => 'pi_retry',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_retry',
					'customer'       => 'cus_recreated',
					'payment_method' => 'pm_retry',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order', 'recreate_customer_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_missing' );
		$customer_service->expects( $this->once() )
			->method( 'recreate_customer_for_order' )
			->with( $this->isInstanceOf( WC_Order::class ) )
			->willReturn( 'cus_recreated' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_recreated', $outcome->get_customer_id() );
		$this->assertSame( 0, $gateway->processed_order_id );
		$this->assertSame( array( 'key_charge', 'key_charge:customer-recovery' ), $api_client->keys );
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( 'key_charge:customer-recovery', $fresh_order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox Charge should resolve a validated subscription change without updating the existing customer.
	 */
	public function test_charge_uses_the_subscription_change_customer_resolver_for_validated_context(): void {
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 'cus_change' !== $request_data['customer'] || 'key_change' !== $idempotency_key ) {
					throw new \RuntimeException( 'Validated changes must use the no-update customer resolver.' );
				}

				return array(
					'id'             => 'pi_change',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_change',
					'customer'       => 'cus_change',
					'payment_method' => 'pm_change',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order', 'get_or_create_customer_id_for_subscription_payment_method_change' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_subscription_payment_method_change' )
			->with( $this->isInstanceOf( WC_Order::class ) )
			->willReturn( 'cus_change' );
		$customer_service->expects( $this->never() )
			->method( 'get_or_create_customer_id_for_order' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_change',
				array(),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true )
			),
			'key_change'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_change', $outcome->get_customer_id() );
	}

	/**
	 * @dataProvider provider_subscription_change_transport_provider
	 * @testdox Validated subscription changes reuse an existing customer without a provider update for both native intent transports.
	 *
	 * @param string $total Order total selecting PaymentIntent or SetupIntent transport.
	 * @param string $expected_intent Expected intent type.
	 */
	public function test_validated_subscription_change_uses_real_customer_service_without_update( string $total, string $expected_intent ): void {
		$order      = $this->create_woopayments_order( $total );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = $this->create_recording_customer_intent_api_client();

		$order->update_meta_data( '_stripe_customer_id', 'cus_change' );
		$order->save();

		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			$this->create_real_customer_service( $api_client )
		);
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_change',
				array(),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true )
			),
			'key_change_real'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_change', $outcome->get_customer_id() );
		$this->assertSame( array(), $api_client->updated_customers );
		$this->assertSame( array(), $api_client->created_customers );
		$this->assertCount( 1, $api_client->intent_requests );
		$this->assertSame( $expected_intent, $api_client->intent_requests[0]['type'] );
		$this->assertSame( 'cus_change', $api_client->intent_requests[0]['request_data']['customer'] );
		$this->assertSame( 'key_change_real', $api_client->intent_requests[0]['idempotency_key'] );
	}

	/**
	 * @testdox Ordinary native checkout updates an existing customer before creating its intent.
	 */
	public function test_ordinary_native_charge_updates_existing_customer_with_real_customer_service(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = $this->create_recording_customer_intent_api_client();

		$order->set_billing_email( 'subject@example.com' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_ordinary' );
		$order->save();

		$outcome = $this->create_adapter( $gateway, $api_client, $this->create_real_customer_service( $api_client ) )->charge(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_ordinary' ),
			'key_ordinary_real'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertCount( 1, $api_client->updated_customers );
		$this->assertSame( 'cus_ordinary', $api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'subject@example.com', $api_client->updated_customers[0]['customer_data']['email'] );
		$this->assertSame( array(), $api_client->created_customers );
		$this->assertCount( 1, $api_client->intent_requests );
	}

	/**
	 * Provider cases for native PaymentIntent and SetupIntent subscription-change transport.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_subscription_change_transport_provider(): array {
		return array(
			'payment intent' => array( '10.00', 'payment' ),
			'setup intent'   => array( '0.00', 'setup' ),
		);
	}

	/**
	 * @dataProvider provider_subscription_change_transport_provider
	 * @testdox Validated subscription changes recreate a missing remote customer and retry the native intent under a derived key.
	 *
	 * @param string $total Order total selecting PaymentIntent or SetupIntent transport.
	 * @param string $expected_intent Expected intent type.
	 */
	public function test_validated_subscription_change_recovers_missing_remote_customer( string $total, string $expected_intent ): void {
		$order      = $this->create_woopayments_order( $total );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client = $this->create_recording_customer_intent_api_client( true );

		$order->update_meta_data( '_stripe_customer_id', 'cus_missing' );
		$order->save();

		$outcome = $this->create_adapter( $gateway, $api_client, $this->create_real_customer_service( $api_client ) )->charge(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_change', array(), array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true ) ),
			'key_recovery_real'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_created', $outcome->get_customer_id() );
		$this->assertSame( array(), $api_client->updated_customers );
		$this->assertCount( 1, $api_client->created_customers );
		$this->assertCount( 2, $api_client->intent_requests );
		$this->assertSame( $expected_intent, $api_client->intent_requests[0]['type'] );
		$this->assertSame( 'cus_missing', $api_client->intent_requests[0]['request_data']['customer'] );
		$this->assertSame( 'cus_created', $api_client->intent_requests[1]['request_data']['customer'] );
		$this->assertSame( 'key_recovery_real', $api_client->intent_requests[0]['idempotency_key'] );
		$this->assertSame( 'key_recovery_real:customer-recovery', $api_client->intent_requests[1]['idempotency_key'], 'The retry sends another customer, so it needs its own key (area 2a #15).' );
	}

	/**
	 * @testdox Charge should preserve server-allowed express checkout payment method types.
	 */
	public function test_charge_preserves_allowed_express_payment_method_types(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return $this->successful_charge_response();
			}

			/**
			 * Create a successful charge response.
			 *
			 * @return array<string,mixed>
			 */
			private function successful_charge_response(): array {
				return array(
					'id'             => 'pi_express',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_express',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_express',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_express',
								'payment_method'         => 'pm_express',
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
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );
		// The gateway is on in test mode: the Amazon Pay type needs an available gateway, as the express button does.
		$account_service = $this->create_account_service(
			true,
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array( 'express_payment_method_types' => array( 'card', 'amazon_pay', 'unknown_method' ) )
			),
			'key_charge'
		);

		$this->assertSame( array( 'card', 'amazon_pay' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertSame( 'ctoken_express', $api_client->last_request_data['confirmation_token'] );
		$this->assertArrayNotHasKey( 'payment_method', $api_client->last_request_data );
	}

	/**
	 * @testdox Charge should fail closed when submitted express checkout method types are not server-allowed.
	 */
	public function test_charge_rejects_unallowed_express_payment_method_types(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_card',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_card',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_card',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );
		$account_service = $this->create_account_service(
			false,
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array( 'express_payment_method_types' => array( 'card', 'amazon_pay' ) )
			),
			'key_charge'
		);

		$this->assertSame( array( 'card' ), $api_client->last_request_data['payment_method_types'] );
	}

	/**
	 * @testdox Charge should derive split gateway payment method types from the selected gateway ID.
	 */
	public function test_charge_derives_split_gateway_payment_method_type_from_gateway_id(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->set_currency( 'EUR' );
		$order->save();

		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_sepa',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_sepa',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_sepa',
					'currency'       => 'eur',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit',
				'pm_sepa'
			),
			'key_charge'
		);

		$this->assertSame( array( 'sepa_debit' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertSame(
			array(
				'customer_acceptance' => array(
					'type'   => 'online',
					'online' => array(
						'ip_address' => \WC_Geolocation::get_ip_address(),
						'user_agent' => 'WooCommerce Payments/11.1.0; ' . get_bloginfo( 'url' ),
					),
				),
			),
			$api_client->last_request_data['mandate_data'] ?? null
		);
	}

	/**
	 * @testdox Charge should send the exact method, amount, currency, capture mode and order return URL for split redirect gateway methods, and return a provider-redirect outcome.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1781-1782` (amount/currency),
	 * `:1819` and `:1835` (`payment_method_types`, via `get_payment_method_types()` and
	 * `set_payment_methods()`), `:1821-1831` (`capture_method`), `:2561-2575`
	 * (`get_payment_methods_from_gateway_id`, the split-gateway derivation `get_payment_method_types()`
	 * calls), `:1852-1866` (`upe_needs_redirection()` sets `return_url` for any single non-card
	 * method), and `:2094-2097`: a `redirect_to_url` next action on a confirmed intent responds with
	 * `result: success` and `redirect` set to that URL directly, which is the outcome
	 * `STATUS_REQUIRES_REDIRECT` mirrors. The order's `pending` status and empty `_charge_id` for this
	 * shape are owned by `PaymentProcessingServiceTest::test_process_checkout_returns_redirect_without_completing_order`.
	 *
	 * @dataProvider split_redirect_method_provider
	 *
	 * @param string $method   Split gateway payment method ID.
	 * @param string $total    Order total.
	 * @param int    $minor    Expected minor-unit amount.
	 * @param string $currency Order currency.
	 */
	public function test_charge_sends_split_redirect_method_request( string $method, string $total, int $minor, string $currency ): void {
		$order = $this->create_woopayments_order( $total );
		$order->set_currency( $currency );
		// Afterpay needs a usable shipping address (client 11.1.0 `class-wc-payment-gateway-wcpay.php:5333-5341`).
		$order->set_shipping_address_1( '2 Navy Way' );
		$order->set_shipping_city( 'Arlington' );
		$order->set_shipping_state( 'VA' );
		$order->set_shipping_postcode( '22202' );
		$order->set_shipping_country( 'US' );
		$order->save();

		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_redirect',
					'status'         => 'requires_action',
					'client_secret'  => 'secret_redirect',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_redirect',
					'currency'       => strtolower( (string) $request_data['currency'] ),
					'next_action'    => array(
						'type'            => 'redirect_to_url',
						'redirect_to_url' => array(
							'url' => 'https://pm-redirects.stripe.com/authorize/acct_test/pa_nonce',
						),
					),
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID_PREFIX . $method,
				'pm_' . $method
			),
			'key_charge'
		);

		$this->assertSame( array( $method ), $api_client->last_request_data['payment_method_types'] );
		$this->assertSame( $minor, $api_client->last_request_data['amount'] );
		$this->assertSame( strtolower( $currency ), $api_client->last_request_data['currency'] );
		$this->assertSame( 'automatic', $api_client->last_request_data['capture_method'] );
		$this->assertArrayHasKey( 'return_url', $api_client->last_request_data );
		$this->assertStringStartsWith( $order->get_checkout_order_received_url(), $api_client->last_request_data['return_url'] );

		$query_args = array();
		parse_str( (string) wp_parse_url( (string) $api_client->last_request_data['return_url'], PHP_URL_QUERY ), $query_args );

		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $query_args['wc_payment_method'] ?? '' );
		$this->assertSame( 1, wp_verify_nonce( $query_args['_wpnonce'] ?? '', 'wcpay_process_redirect_order_nonce' ) );

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status(), "$method's requires_action intent with a redirect_to_url next action must map to a direct provider redirect." );
		$this->assertSame( 'https://pm-redirects.stripe.com/authorize/acct_test/pa_nonce', $outcome->get_redirect_url() );
	}

	/**
	 * Split redirect method request fixtures.
	 *
	 * @return array<string,array{0:string,1:string,2:int,3:string}>
	 */
	public function split_redirect_method_provider(): array {
		return array(
			'iDEAL EUR'                 => array( 'ideal', '50.00', 5000, 'EUR' ),
			'Alipay USD'                => array( 'alipay', '12.00', 1200, 'USD' ),
			'Affirm USD'                => array( 'affirm', '100.00', 10000, 'USD' ),
			'Cash App Afterpay USD'     => array( 'afterpay_clearpay', '100.00', 10000, 'USD' ),
			'Bancontact EUR'            => array( 'bancontact', '12.34', 1234, 'EUR' ),
			'Klarna USD, never returns' => array( 'klarna', '100.00', 10000, 'USD' ),
		);
	}

	/**
	 * @testdox A single card checkout without save matches the 11.1.0 request shape.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1781-1782` (amount/currency),
	 * `:1821-1831` (capture_method, customer, payment_method), `:1870-1874` (`setup_future_usage`
	 * is set only when the shopper requested saving, never for a plain single-use charge), and
	 * `:2578-2585` (`payment_method_types` is `['card']` alone with Link disabled, the fixture's default;
	 * it becomes `['card','link']` only when Link is enabled).
	 */
	public function test_single_card_checkout_without_save_matches_11_1_request_shape(): void {
		$order            = $this->create_woopayments_order( '10.99' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_basic_card',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_basic_card',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                     => 'ch_basic_card',
								'payment_method'         => 'pm_native',
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'brand'   => 'visa',
										'funding' => 'credit',
										'last4'   => '4242',
									),
								),
							),
						),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_card'
			),
			'key_charge'
		);

		$this->assertSame( 1099, $api_client->last_request_data['amount'] );
		$this->assertSame( 'usd', $api_client->last_request_data['currency'] );
		$this->assertSame( 'automatic', $api_client->last_request_data['capture_method'] );
		$this->assertSame( array( 'card' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertSame( 'cus_native', $api_client->last_request_data['customer'] );
		$this->assertSame( 'pm_card', $api_client->last_request_data['payment_method'] );
		$this->assertArrayNotHasKey( 'setup_future_usage', $api_client->last_request_data );
	}

	/**
	 * @testdox Native redirect mapping sanitizes a provider URL exactly once at the runtime boundary.
	 */
	public function test_charge_sanitizes_provider_redirect_exactly_once(): void {
		$order        = $this->create_woopayments_order( '50.00' );
		$provider_url = 'https://pm-redirects.stripe.com/authorize/acct_test/pa_single';
		$filter_calls = 0;
		$clean_url    = static function ( string $sanitized_url, string $original_url ) use ( &$filter_calls, $provider_url ): string {
			if ( $provider_url !== $original_url ) {
				return $sanitized_url;
			}

			++$filter_calls;

			return 1 === $filter_calls ? 'https://rewritten.example/authorize' : '';
		};
		add_filter( 'clean_url', $clean_url, 10, 2 );

		$api_client       = new class( $provider_url ) extends WooPaymentsApiClient {
			/**
			 * Provider redirect URL.
			 *
			 * @var string
			 */
			private string $provider_url;

			/**
			 * Constructor.
			 *
			 * @param string $provider_url Provider redirect URL.
			 */
			public function __construct( string $provider_url ) {
				$this->provider_url = $provider_url;
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
			 * Return a provider redirect response.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'          => 'pi_redirect_once',
					'status'      => 'requires_action',
					'next_action' => array(
						'type'            => 'redirect_to_url',
						'redirect_to_url' => array( 'url' => $this->provider_url ),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		try {
			$outcome = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service )
				->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card' ), 'key_charge' );
		} finally {
			remove_filter( 'clean_url', $clean_url, 10 );
		}

		$this->assertSame( 1, $filter_calls );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'https://rewritten.example/authorize', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox Charge should validate express checkout method types against the order currency.
	 */
	public function test_charge_validates_express_payment_method_types_against_order_currency(): void {
		$order = $this->create_woopayments_order( '50.00' );
		$order->set_currency( 'EUR' );
		$order->save();

		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_currency',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_currency',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_currency',
					'currency'       => 'eur',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );
		// The gateway is on in test mode: the Amazon Pay type needs an available gateway, as the express button does.
		$account_service = $this->create_account_service(
			true,
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'country'                          => 'US',
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array( 'express_payment_method_types' => array( 'card', 'amazon_pay' ) )
			),
			'key_charge'
		);

		$this->assertSame( array( 'card' ), $api_client->last_request_data['payment_method_types'] );
	}

	/**
	 * @testdox Charge should validate express checkout method types against checkout-context settings.
	 */
	public function test_charge_validates_express_payment_method_types_against_checkout_context_settings(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_checkout_context',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_checkout_context',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_checkout_context',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );
		$account_service = $this->create_account_service(
			false,
			array(
				'express_checkout_cart_methods'     => array( 'payment_request', 'amazon_pay' ),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array(
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY    => array( 'card', 'amazon_pay' ),
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY => 'checkout',
				)
			),
			'key_charge'
		);

		$this->assertSame( array( 'card' ), $api_client->last_request_data['payment_method_types'] );
	}

	/**
	 * @testdox Charge should preserve pay-for-order context while validating express checkout method types.
	 */
	public function test_charge_preserves_pay_for_order_context_for_express_payment_method_types(): void {
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$order            = $this->create_woopayments_order( '50.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_order_pay_context',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_order_pay_context',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_order_pay_context',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );
		// The gateway is on in test mode: the Amazon Pay type needs an available gateway, as the express button does.
		$account_service = $this->create_account_service(
			true,
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array(
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY    => array( 'card', 'amazon_pay' ),
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY => 'pay_for_order',
				)
			),
			'key_charge'
		);

		$this->assertSame( array( 'card', 'amazon_pay' ), $api_client->last_request_data['payment_method_types'] );
	}

	/**
	 * @testdox Charge should accept an express method a callback adds to the context, as the button offers it.
	 */
	public function test_charge_accepts_an_express_method_the_enabled_methods_filter_adds(): void {
		add_filter(
			'woocommerce_woopayments_express_checkout_enabled_methods',
			static function ( array $methods, string $context ): array {
				return 'checkout' === $context ? array_merge( $methods, array( 'amazon_pay' ) ) : $methods;
			},
			10,
			2
		);

		$this->assertSame(
			array( 'card', 'amazon_pay' ),
			$this->charge_express_payment_method_types( array( 'payment_request' ), array( 'card', 'amazon_pay' ), 'checkout' )
		);
	}

	/**
	 * @testdox Charge should refuse an express method a callback removes from the context, as the button hides it.
	 */
	public function test_charge_refuses_an_express_method_the_enabled_methods_filter_removes(): void {
		add_filter(
			'woocommerce_woopayments_express_checkout_enabled_methods',
			static fn( array $methods ): array => array_values( array_diff( $methods, array( 'amazon_pay' ) ) )
		);

		$this->assertSame(
			array( 'card' ),
			$this->charge_express_payment_method_types( array( 'payment_request', 'amazon_pay' ), array( 'amazon_pay' ), 'checkout' )
		);
	}

	/**
	 * Charge an express checkout payment and return the payment method types sent to the platform.
	 *
	 * The gateway is enabled in test mode, with Amazon Pay switched on and available.
	 *
	 * @param array<int,string> $checkout_methods Express methods configured for the checkout location.
	 * @param array<int,string> $submitted_types  Payment method types the express script submits.
	 * @param string            $context          Submitted express checkout context.
	 * @return array<int,string>
	 */
	private function charge_express_payment_method_types( array $checkout_methods, array $submitted_types, string $context ): array {
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_express_types',
					'status'         => 'succeeded',
					'client_secret'  => 'secret_express_types',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_express_types',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );
		$account_service = $this->create_account_service(
			true,
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => $checkout_methods,
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$sut = $this->create_adapter( new RecordingLegacyGateway( array( 'result' => 'success' ) ), $api_client, $customer_service, null, $account_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$this->create_woopayments_order( '50.00' ),
				OrderPaymentStore::GATEWAY_ID,
				'ctoken_express',
				array(),
				array(
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY    => $submitted_types,
					WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY => $context,
				)
			),
			'key_charge'
		);

		return $api_client->last_request_data['payment_method_types'];
	}

	/**
	 * @testdox Charge should resolve saved WooCommerce token IDs before native transport.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. The response is now REC-3DS-1's saved-PM
	 * variant (`Fixtures/rec-t3-3ds-requires-action.json`, pair
	 * `saved_payment_method_requires_action`): an on-session PaymentIntent for an already-attached
	 * 3DS-required card, real from the local platform. The token-resolution guard this test exists
	 * for moves to the sent request body (`pm_saved`, the resolved token, not a raw context
	 * credential) rather than a hand-written response validator, following the fake-transport
	 * pattern {@see self::test_native_charge_decline_envelope_maps_each_card_code} established.
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2070-2072` attaches an
	 * already-saved token to the order (`add_token_to_order()`) unconditionally, before the
	 * `$needs_frontend_confirmation` check for a `requires_action`/`requires_confirmation` status
	 * at `:2081` — the saved-token attachment does not wait to see whether the intent needs a
	 * challenge.
	 */
	public function test_charge_resolves_saved_payment_token_before_native_transport(): void {
		$user_id               = $this->factory()->user->create();
		$order                 = $this->create_woopayments_order();
		$gateway               = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token           = $this->create_card_token( $user_id, 'pm_saved' );
		$recorded              = $this->load_recorded_intent_entry( 'rec-t3-3ds-requires-action.json', 'saved_payment_method_requires_action' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$order->set_customer_id( $user_id );
		$order->add_payment_token( $saved_token );
		$order->save();
		$token_service = $this->create_single_resolution_token_service( $saved_token, $user_id, 'pm_saved' );

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service, $account_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $saved_token->get_id() )
			),
			'key_charge'
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status(), 'A 3DS-required saved card must surface as requires-customer-action, not a synthetic success.' );
		$this->assertSame( $recorded['body']['payment_method'], $outcome->get_payment_method_id(), "The outcome's payment method must come from REC-3DS-1's recorded response." );
		$this->assertContains( $saved_token->get_id(), $order->get_payment_tokens(), 'Existing saved tokens should be linked to the order regardless of outcome.' );

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( 'pm_saved', $sent['payment_method'] ?? null, 'The resolved saved token, not a raw context credential, must reach native transport.' );
		$this->assertSame( 'key_charge', $http_client->last_headers['Idempotency-Key'] ?? null );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * Matches WooPayments 11.1.0 WCPay\Internal\Service\OrderService::get_payment_metadata(), WC_Payments_Subscriptions_Utilities::is_payment_recurring(), and WC_Payment_Gateway_WCPay::process_payment_for_order(). Store API initial and renewal classifications were observed in read-only pinned-client runtime captures; classic initial is source-derived because no paired reference order exists.
	 *
	 * @testdox Saved subscription orders compose the pinned recurring request shape through the native adapter.
	 *
	 * @dataProvider subscription_checkout_composition_provider
	 *
	 * @param string $created_via                  Saved order creation source.
	 * @param bool   $is_renewal                   Whether the order is a scheduled renewal.
	 * @param string $expected_payment_type        Expected pinned payment type.
	 * @param string $expected_subscription_payment Expected pinned subscription payment classification.
	 */
	public function test_subscription_checkout_composition_matches_11_1_request_shape( string $created_via, bool $is_renewal, string $expected_payment_type, string $expected_subscription_payment ): void {
		$this->ensure_wcs_order_subscription_detector_double();
		$this->ensure_wcs_order_renewal_detector_double();

		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_woopayments_order( '12.34' );
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->set_billing_first_name( 'Subscription' );
		$order->set_billing_last_name( 'Buyer' );
		$order->set_billing_email( 'subscription-buyer@example.com' );
		$order->set_created_via( $created_via );
		$order->save();

		$GLOBALS['wcpay_test_subscription_ids']  = $is_renewal ? array() : array( $order->get_id() );
		$GLOBALS['wcpay_test_renewal_order_ids'] = $is_renewal ? array( $order->get_id() ) : array();

		$payment_data     = array( 'save_payment_method' => false );
		$provider_data    = array( 'fingerprint' => 'subscription_fixture_fingerprint' );
		$payment_method   = 'pm_subscription_composition';
		$token_service    = null;
		$expected_request = array(
			'amount'               => 1234,
			'capture_method'       => 'automatic',
			'currency'             => 'usd',
			'customer'             => 'cus_subscription_composition',
			'description'          => sprintf( 'Online Payment for Order #%s for %s', $order->get_order_number(), str_replace( array( 'https://', 'http://' ), '', get_site_url() ) ),
			'payment_method_types' => array( 'card' ),
			'payment_method'       => 'pm_subscription_composition',
			'setup_future_usage'   => 'off_session',
		);

		if ( $is_renewal ) {
			$saved_token = $this->create_card_token( $user_id, $payment_method );
			$order->add_payment_token( $saved_token );
			$order->save();
			$payment_data   = array(
				'payment_token'       => (string) $saved_token->get_id(),
				'save_payment_method' => false,
			);
			$provider_data  = array(
				'scheduled_subscription_payment' => true,
				'fingerprint'                    => 'subscription_fixture_fingerprint',
			);
			$payment_method = '';
			$token_service  = $this->create_single_order_resolution_token_service( $saved_token, $order, 'pm_subscription_composition', 'card' );

			unset( $expected_request['setup_future_usage'] );
			$expected_request['off_session']                = true;
			$expected_request['payment_method_update_data'] = array(
				'billing_details' => array(
					// Client 11.1.0 sends every billing field the checkout shows, empty ones included, and drops an
					// empty country (class-wc-payments-order-service.php:1455-1488).
					'address' => array(
						'line1'       => '',
						'line2'       => '',
						'city'        => '',
						'state'       => '',
						'postal_code' => '',
					),
					'phone'   => '',
					'email'   => 'subscription-buyer@example.com',
					'name'    => 'Subscription Buyer',
				),
			);
		}

		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var array<string,mixed> */
			public array $last_request_data = array();

			/** @var string */
			public string $last_idempotency_key = '';

			/**
			 * Tell whether native transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Record the composed PaymentIntent request.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->last_request_data    = $request_data;
				$this->last_idempotency_key = $idempotency_key;

				return array(
					'id'             => 'pi_subscription_composition',
					'status'         => 'succeeded',
					'customer'       => 'cus_subscription_composition',
					'payment_method' => 'pm_subscription_composition',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_subscription_composition' );

		$geolocate_filter = static fn() => 'US';
		add_filter( 'woocommerce_geolocate_ip', $geolocate_filter );
		try {
			$outcome = $this->create_adapter( new RecordingLegacyGateway(), $api_client, $customer_service, $token_service )->charge(
				PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, $payment_method, $payment_data, $provider_data ),
				'key_subscription_composition'
			);
		} finally {
			remove_filter( 'woocommerce_geolocate_ip', $geolocate_filter );
		}

		$request          = $api_client->last_request_data;
		$request_metadata = $request['metadata'];
		unset( $request['metadata'] );
		$expected_metadata = array(
			'customer_name'                         => 'Subscription Buyer',
			'customer_email'                        => 'subscription-buyer@example.com',
			'site_url'                              => esc_url( get_site_url() ),
			'order_id'                              => $order->get_id(),
			'order_number'                          => $order->get_order_number(),
			'order_key'                             => $order->get_order_key(),
			'payment_type'                          => WooPaymentsPaymentType::recurring(),
			'checkout_type'                         => $created_via,
			'client_version'                        => WooPaymentsClientVersion::VERSION,
			'subscription_payment'                  => $expected_subscription_payment,
			'payment_context'                       => 'regular_subscription',
			'fraud_prevention_data_shopper_ip_hash' => hash( 'sha512', \WC_Geolocation::get_ip_address() ),
			'fraud_prevention_data_shopper_ua_hash' => 'subscription_fixture_fingerprint',
			'fraud_prevention_data_ip_country'      => 'US',
			'fraud_prevention_data_cart_contents'   => 0,
			'fraud_prevention_data_available'       => true,
		);

		$this->assertSame( 'key_subscription_composition', $api_client->last_idempotency_key );
		$this->assertSame( $expected_request, $request );
		$this->assertSame( $expected_payment_type, (string) $request_metadata['payment_type'] );
		$this->assertSame( $expected_metadata, $request_metadata );
		$this->assertArrayNotHasKey( 'mandate', $api_client->last_request_data );
		$this->assertArrayNotHasKey( 'mandate_data', $api_client->last_request_data );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertTrue( $outcome->get_effect_plan()->should_apply_token_effects() );
		$this->assertTrue( $outcome->get_effect_plan()->is_recurring(), 'Initial subscription requests must plan recurring token persistence even when the shopper did not opt in separately.' );
	}

	/**
	 * Provide saved subscription order scenarios from the pinned source/runtime evidence.
	 *
	 * @return array<string,array{string,bool,string,string}>
	 */
	public function subscription_checkout_composition_provider(): array {
		return array(
			'observed Store API initial'     => array( 'store-api', false, 'recurring', 'initial' ),
			'source-derived classic initial' => array( 'checkout', false, 'recurring', 'initial' ),
			'observed scheduled renewal'     => array( 'subscription', true, 'recurring', 'renewal' ),
		);
	}

	/**
	 * @testdox Scheduled subscription charges should use merchant-initiated recurring request shape.
	 */
	public function test_scheduled_subscription_charge_uses_merchant_initiated_recurring_request_shape(): void {
		$user_id                = $this->factory()->user->create();
		$order                  = $this->create_woopayments_order();
		$gateway                = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token            = $this->create_card_token( $user_id, 'pm_saved' );
		$metadata_payment_types = array();
		$api_client             = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 'pm_saved' !== $request_data['payment_method'] || 'key_renewal' !== $idempotency_key ) {
					throw new \RuntimeException( 'Saved token was not resolved before the native renewal request.' );
				}

				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_renewal',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_saved',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service       = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$metadata_filter        = static function ( array $metadata, WC_Order $filtered_order, $payment_type ) use ( &$metadata_payment_types, $order ): array {
			if ( $order->get_id() === $filtered_order->get_id() ) {
				$metadata_payment_types[] = array(
					'string_value'     => (string) $payment_type,
					'equals_recurring' => $payment_type->equals( WooPaymentsPaymentType::recurring() ),
					'equals_single'    => $payment_type->equals( WooPaymentsPaymentType::single() ),
				);
			}

			return $metadata;
		};

		$order->set_customer_id( $user_id );
		$order->add_payment_token( $saved_token );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		add_filter( 'wcpay_metadata_from_order', $metadata_filter, 10, 3 );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array(
					'payment_token'       => (string) $saved_token->get_id(),
					'save_payment_method' => false,
				),
				array(
					'scheduled_subscription_payment' => true,
					'renewal_mandate'                => 'mandate_native',
				)
			),
			'key_renewal'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertTrue( $api_client->last_request_data['off_session'] );
		$this->assertSame( 'mandate_native', $api_client->last_request_data['mandate'] );
		$this->assertInstanceOf( WooPaymentsPaymentType::class, $api_client->last_request_data['metadata']['payment_type'] );
		$this->assertSame( 'recurring', (string) $api_client->last_request_data['metadata']['payment_type'] );
		$this->assertSame( 'renewal', $api_client->last_request_data['metadata']['subscription_payment'] );
		$this->assertSame( 'regular_subscription', $api_client->last_request_data['metadata']['payment_context'] );
		$this->assertSame(
			array(
				array(
					'string_value'     => 'recurring',
					'equals_recurring' => true,
					'equals_single'    => false,
				),
			),
			$metadata_payment_types
		);
		$this->assertArrayNotHasKey( 'setup_future_usage', $api_client->last_request_data );
	}

	/**
	 * @testdox Customer-present early renewals should send recurring renewal metadata without off-session semantics.
	 */
	public function test_customer_present_early_renewal_uses_recurring_renewal_request_shape(): void {
		$this->ensure_wcs_order_renewal_detector_double();
		$user_id          = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order            = $this->create_woopayments_order( '9.99' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_card_token( $user_id, 'pm_plugin_subscription' );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 'pm_plugin_subscription' !== $request_data['payment_method'] || 'key_early_renewal' !== $idempotency_key ) {
					throw new \RuntimeException( 'Historical saved token was not resolved for the customer-present early renewal.' );
				}

				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_plugin_subscription_renewal',
					'status'         => 'succeeded',
					'customer'       => 'cus_plugin_subscription',
					'payment_method' => 'pm_plugin_subscription',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $saved_token );
		$order->update_meta_data( '_subscription_renewal', 4321 );
		$order->save();
		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_plugin_subscription' );

		// Oracle: WooPayments 11.1.0 OrderService::get_payment_metadata() labels a customer-present renewal as recurring/renewal, and the read-only :8082 provider family records it without off_session.
		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			$customer_service,
			$this->create_single_resolution_token_service( $saved_token, $user_id, 'pm_plugin_subscription' ),
			$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
		);
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array(
					'payment_token'       => (string) $saved_token->get_id(),
					'save_payment_method' => false,
				)
			),
			'key_early_renewal'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 999, $api_client->last_request_data['amount'] );
		$this->assertSame( 'usd', $api_client->last_request_data['currency'] );
		$this->assertSame( 'cus_plugin_subscription', $api_client->last_request_data['customer'] );
		$this->assertInstanceOf( WooPaymentsPaymentType::class, $api_client->last_request_data['metadata']['payment_type'] );
		$this->assertSame( 'recurring', (string) $api_client->last_request_data['metadata']['payment_type'] );
		$this->assertSame( 'renewal', $api_client->last_request_data['metadata']['subscription_payment'] );
		$this->assertSame( 'regular_subscription', $api_client->last_request_data['metadata']['payment_context'] );
		$this->assertSame( 'manual', $api_client->last_request_data['capture_method'] );
		$this->assertSame( 'off_session', $api_client->last_request_data['setup_future_usage'] );
		$this->assertArrayNotHasKey( 'off_session', $api_client->last_request_data );
	}

	/**
	 * @testdox Customer-present early renewals retain online mandate data for methods that require it.
	 */
	public function test_customer_present_early_renewal_retains_online_mandate_data(): void {
		$this->ensure_wcs_order_renewal_detector_double();
		$order = $this->create_woopayments_order( '9.99' );
		$order->set_currency( 'EUR' );
		$order->set_customer_ip_address( '203.0.113.7' );
		$order->save();
		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$this->create_account_service( false ),
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);
		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit', 'pm_sepa' ),
			'pm_sepa',
			'cus_plugin_subscription',
			false
		);

		$this->assertSame( 'renewal', $request['metadata']['subscription_payment'] );
		$this->assertSame( array( 'sepa_debit' ), $request['payment_method_types'] );
		$this->assertSame( '203.0.113.7', $request['mandate_data']['customer_acceptance']['online']['ip_address'] );
		$this->assertArrayNotHasKey( 'off_session', $request );
	}

	/**
	 * @testdox A zero-total scheduled Link renewal completes without any intent, as the client's scheduled payment does.
	 *
	 * Client scheduled_subscription_payment() builds its payment information from the saved token (subtrait:424-425), so gw:1688
	 * skips the intent: no transport, so no mandate question either.
	 */
	public function test_zero_total_scheduled_link_renewal_completes_without_intent(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_link_token( $user_id, 'pm_link' );
		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var array<string,mixed> */
			public array $last_request_data = array();

			/** @var string */
			public string $last_idempotency_key = '';

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a setup intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				$this->last_request_data    = $request_data;
				$this->last_idempotency_key = $idempotency_key;

				return array(
					'id'             => 'seti_renewal',
					'status'         => 'succeeded',
					'client_secret'  => 'seti_renewal_secret_abc',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_link',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$order->set_customer_id( $user_id );
		$order->set_customer_ip_address( '203.0.113.8' );
		$order->add_payment_token( $saved_token );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$token_service = $this->create_single_order_resolution_token_service( $saved_token, $order, 'pm_link', 'link' );
		$server_keys   = array( 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		$server_state  = array();
		foreach ( $server_keys as $server_key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The test restores raw superglobal state exactly.
			$server_value                = $_SERVER[ $server_key ] ?? null;
			$server_state[ $server_key ] = array(
				'present' => array_key_exists( $server_key, $_SERVER ),
				'value'   => $server_value,
			);
			unset( $_SERVER[ $server_key ] );
		}

		try {
			$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
			$outcome = $sut->charge(
				PaymentContext::for_checkout(
					$order,
					OrderPaymentStore::GATEWAY_ID,
					'',
					array( 'payment_token' => (string) $saved_token->get_id() ),
					array( 'scheduled_subscription_payment' => true )
				),
				'key_zero_renewal'
			);

			$this->assertSame( '', $api_client->last_idempotency_key, 'No SetupIntent request.' );
			$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
			$this->assertSame( '', $outcome->get_provider_payment_id() );
			$this->assertSame( 'pm_link', $outcome->get_payment_method_id() );
			$this->assertSame( 'cus_native', $outcome->get_customer_id() );
			$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT, $outcome->get_effect_plan()->get_type() );
			$this->assertSame( $saved_token->get_id(), $outcome->get_effect_plan()->get_provider_result()['token_id'] );
		} finally {
			foreach ( $server_state as $server_key => $state ) {
				if ( $state['present'] ) {
					$_SERVER[ $server_key ] = $state['value'];
				} else {
					unset( $_SERVER[ $server_key ] );
				}
			}
		}
	}

	/**
	 * @testdox Scheduled renewal failures normalize unusable saved payment methods without changing other outcome data.
	 *
	 * @dataProvider unusable_saved_method_transport_failure_data
	 *
	 * @param string $error_code Provider error code.
	 * @param string $message Provider error message.
	 */
	public function test_scheduled_renewal_failure_normalizes_unusable_saved_payment_method( string $error_code, string $message ): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$token            = $this->create_card_token( $user_id, 'pm_unusable_method' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$expected_result  = array(
			'id'                 => 'pi_unusable_method',
			'status'             => 'requires_payment_method',
			'customer'           => 'cus_renewal',
			'payment_method'     => 'pm_unusable_method',
			'last_payment_error' => array(
				'code'    => $error_code,
				'message' => $message,
			),
		);
		$api_client       = new class( $expected_result ) extends WooPaymentsApiClient {
			/**
			 * Provider result.
			 *
			 * @var array<string,mixed>
			 */
			private array $result;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $result Provider result.
			 */
			public function __construct( array $result ) {
				$this->result = $result;
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return $this->result;
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_renewal' );
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $token );
		$order->save();

		$outcome                  = $this->create_adapter( $gateway, $api_client, $customer_service )->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $token->get_id() ),
				array(
					'scheduled_subscription_payment'    => true,
					'saved_payment_method_display_name' => $token->get_display_name(),
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true,
				)
			),
			'key_unusable_method'
		);
		$data                     = $outcome->get_data();
		$expected_note_candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_unusable_saved_payment_method_note_candidates( $order, $token->get_display_name() );
		$expected_shopper_message = WooPaymentsErrorMessages::get_shopper_message( '', $error_code, '', $message );
		$effect_plan              = $outcome->get_effect_plan();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_unusable_method', $outcome->get_provider_payment_id() );
		$this->assertSame( '', $outcome->get_redirect_url() );
		$this->assertSame( 'pm_unusable_method', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_renewal', $outcome->get_customer_id() );
		$this->assertNotEmpty( $expected_note_candidates );
		$this->assertSame(
			array(
				PaymentOutcome::DATA_ERROR_CODE            => $error_code,
				PaymentOutcome::DATA_ERROR_MESSAGE         => $message,
				PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => $expected_shopper_message,
				PaymentOutcome::DATA_NOTE                  => $expected_note_candidates[0],
				PaymentOutcome::DATA_NOTE_EQUIVALENTS      => $expected_note_candidates,
				PaymentOutcome::DATA_NOTE_TYPE             => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
			),
			$data
		);
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $effect_plan );
		if ( ! $effect_plan instanceof WooPaymentsOrderEffectPlan ) {
			return;
		}
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $effect_plan->get_type() );
		$this->assertSame( $expected_result, $effect_plan->get_provider_result() );
		$this->assertTrue( $effect_plan->is_recurring() );
		$this->assertFalse( $effect_plan->should_apply_token_effects() );
	}

	/**
	 * Provide native returned-failure shapes that must receive the safe renewal note.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function unusable_saved_method_transport_failure_data(): array {
		return array(
			'exact code'       => array( 'payment_method_no_longer_available', 'Raw provider diagnostic.' ),
			'must-save phrase' => array( '', 'Before retry, MUST SAVE THIS paymentmethod TO A CUSTOMER.' ),
			'no-such phrase'   => array( '', 'Upstream: no such paymentmethod: pm_123.' ),
			'detached phrase'  => array( '', 'This card is DETACHED FROM A customer.' ),
			'reuse phrase'     => array( '', 'This payment method MAY NOT BE USED AGAIN.' ),
		);
	}

	/**
	 * @testdox Unusable saved method classification accepts only the approved structured code and complete phrases.
	 *
	 * @dataProvider unusable_saved_method_failure_data
	 *
	 * @param string $error_code Provider error code.
	 * @param string $message Provider error message.
	 * @param bool   $expected Whether the failure is an unusable saved method.
	 */
	public function test_unusable_saved_method_failure_classification( string $error_code, string $message, bool $expected ): void {
		$sut    = $this->create_adapter( new RecordingLegacyGateway() );
		$method = new \ReflectionMethod( WooPaymentsProviderGatewayAdapter::class, 'is_unusable_saved_payment_method_failure' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( $sut, $error_code, $message ) );
	}

	/**
	 * Provide strict unusable saved-method classifier cases.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public static function unusable_saved_method_failure_data(): array {
		return array(
			'exact error code'                       => array( 'payment_method_no_longer_available', 'Unrelated provider diagnostic.', true ),
			'wrong-case error code'                  => array( 'Payment_Method_No_Longer_Available', 'Unrelated provider diagnostic.', false ),
			'must-save phrase with surrounding text' => array( '', 'Upstream says you MUST SAVE THIS paymentmethod TO A CUSTOMER before reuse.', true ),
			'no-such-payment-method phrase'          => array( '', 'prefix: no such paymentmethod: pm_123 suffix', true ),
			'detached phrase'                        => array( '', 'The payment method was DETACHED FROM A customer by the platform.', true ),
			'may-not-reuse phrase'                   => array( '', 'This saved credential MAY NOT BE USED AGAIN after detachment.', true ),
			'partial must-save fragment'             => array( '', 'must save this PaymentMethod', false ),
			'partial no-such fragment'               => array( '', 'No such Payment', false ),
			'generic resource missing'               => array( 'resource_missing', 'No such resource: res_123', false ),
			'no such customer'                       => array( 'resource_missing', 'No such customer: cus_123', false ),
			'card decline'                           => array( 'card_declined', 'Your card was declined.', false ),
			'authentication required'                => array( 'authentication_required', 'Authentication is required.', false ),
			'unrelated API failure'                  => array( 'api_error', 'The upstream service is unavailable.', false ),
		);
	}

	/**
	 * @testdox Scheduled subscription charges should derive payment method types from saved SEPA tokens.
	 */
	public function test_scheduled_subscription_charge_uses_saved_sepa_token_payment_method_type(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_sepa_token( $user_id, 'pm_sepa_saved' );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				if ( 'pm_sepa_saved' !== $request_data['payment_method'] || 'key_sepa_renewal' !== $idempotency_key ) {
					throw new \RuntimeException( 'SEPA saved token was not resolved before the native renewal request.' );
				}

				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_sepa_renewal',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_sepa_saved',
					'currency'       => 'eur',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$this->register_token_class_map();

		$order->set_customer_id( $user_id );
		$order->set_currency( 'EUR' );
		$order->add_payment_token( $saved_token );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				'woocommerce_payments_sepa_debit',
				'',
				array(
					'payment_token'       => (string) $saved_token->get_id(),
					'save_payment_method' => false,
				),
				array(
					'scheduled_subscription_payment' => true,
				)
			),
			'key_sepa_renewal'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( array( 'sepa_debit' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertTrue( $api_client->last_request_data['off_session'] );
	}

	/**
	 * @testdox Native charge should plan requested token persistence without creating tokens during transport.
	 */
	public function test_native_charge_plans_requested_token_persistence_without_writes(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$token_service    = $this->create_token_service(
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

		$order->set_customer_id( $user_id );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_request',
				array( 'save_payment_method' => true )
			),
			'key_charge'
		);
		$order   = wc_get_order( $order->get_id() );
		$tokens  = \WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'off_session', $api_client->last_request_data['setup_future_usage'] );
		$this->assertEmpty( $tokens );
		$this->assertEmpty( $order->get_payment_tokens() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertTrue( $outcome->get_effect_plan()->should_apply_token_effects() );
		$this->assertFalse( $outcome->get_effect_plan()->is_recurring() );
	}

	/**
	 * @testdox Native recurring charge should retain the provider outcome until planned token effects run.
	 */
	public function test_native_recurring_charge_returns_provider_outcome_with_required_token_plan(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'pi_native',
					'status'         => 'succeeded',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$token_service    = new class() extends WooPaymentsTokenService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double must fail token saving.
			/**
			 * Fail token creation.
			 *
			 * @param string $payment_method_id Provider payment method ID.
			 * @param int    $user_id           User ID.
			 * @return WC_Payment_Token_CC|null
			 */
			public function get_or_create_card_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token_CC {
				unset( $payment_method_id, $user_id );

				throw new \RuntimeException( 'Token save failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$order->set_customer_id( $user_id );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_request',
				array( 'save_payment_method' => false ),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true )
			),
			'key_charge'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'off_session', $api_client->last_request_data['setup_future_usage'] );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertTrue( $outcome->get_effect_plan()->should_apply_token_effects() );
		$this->assertTrue( $outcome->get_effect_plan()->is_recurring() );
		$this->assertSame( 0, $gateway->processed_order_id );
	}

	/**
	 * @testdox Native charge failures expose localized structured card declines separately from raw diagnostics.
	 */
	public function test_native_charge_failure_maps_structured_card_decline_to_shopper_message(): void {
		$order                 = $this->create_woopayments_order( '50.00' );
		$gateway               = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$account_service       = $this->create_account_service( false );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 402 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'type'         => 'card_error',
						'code'         => 'card_declined',
						'decline_code' => 'insufficient_funds',
						'message'      => 'Provider diagnostic for request req_private.',
					),
				)
			),
		);

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_declined' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_declined' ), 'key_declined' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'card_declined', $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'Error: Provider diagnostic for request req_private.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( 'Error: Your card has insufficient funds.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Native charge decline envelopes recorded from the local platform (REC-1) map each card code to its outcome.
	 *
	 * The response status and body are the real decline envelope local WPCOM returned for each Stripe test
	 * card, recorded in `Fixtures/rec-1-intention-declines.json` (REC-1). Every field the client reads
	 * at `class-wc-payments-api-client.php:2853-2872` (11.1.0) exists at the path it reads there, including
	 * a `decline_code` equal to `code` for three of the five pairs and a full `payment_intent` in
	 * `requires_payment_method` status. All five recorded errors are `card_error`, so all five must mark the
	 * fraud meta box `allow` (`gw:1372`). The merchant note carries the platform's `seller_message` only when
	 * the top-level code is `card_declined` (client `api:2910-2914`); the other three codes carry none.
	 * Every recorded decline is a real PaymentIntent dispatch failure with an unambiguous transport error,
	 * so `WooPaymentsProviderGatewayAdapter::finalize_charge_idempotency_key()` must classify it as
	 * definitive and retire the charge idempotency key (`_wcpay_definitive_charge_failure`), giving a
	 * shopper retry a fresh key rather than replaying the declined one. This is native-only idempotency
	 * bookkeeping with no client 11.1.0 parity claim: the plugin has no equivalent per-charge
	 * idempotency-key retirement step. `:627` (`test_native_charge_retires_key_after_definitive_outcome`)
	 * proves the retirement mechanics with a synthetic failure; this asserts the classification itself
	 * against a real recorded envelope.
	 *
	 * The `, raw message replaced with a sentinel` rows swap the recorded `error.message` for a value that
	 * is never in the shopper-message catalog. `WooPaymentsErrorMessages::get_shopper_message()` (utils
	 * `get_filtered_error_message` at 11.1.0) selects the shopper string from `decline_code`/`error_code`
	 * alone and never reads the raw platform message for a `card_error`, so the shopper message must stay
	 * the exact catalog string while the wrapped transport message reflects the sentinel. This catches a
	 * bug that returns the raw exception message as the shopper message, which every unmutated row above
	 * would miss because the recorded message already equals the catalog text.
	 *
	 * @dataProvider recorded_decline_envelope_data
	 *
	 * @param string $pair                          REC-1 fixture pair key.
	 * @param string $expected_error_code            Expected provider error code.
	 * @param string $expected_message               Expected wrapped transport error message; recomputed from the sentinel when `$mutate_raw_message` is true.
	 * @param string $expected_shopper               Expected shopper-facing message.
	 * @param string $expected_intent_id             Expected declined PaymentIntent ID.
	 * @param string $expected_seller_message        Expected `seller_message` fragment in the merchant note, or '' when the code carries none.
	 * @param bool   $mutate_raw_message             When true, replace the recorded `error.message` with a sentinel absent from any shopper-message catalog entry.
	 */
	public function test_native_charge_decline_envelope_maps_each_card_code( string $pair, string $expected_error_code, string $expected_message, string $expected_shopper, string $expected_intent_id, string $expected_seller_message, bool $mutate_raw_message = false ): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$gateway         = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$account_service = $this->create_account_service( false );
		$recorded        = $this->load_recorded_decline_entry( $pair );

		if ( $mutate_raw_message ) {
			$sentinel                     = 'sentinel raw transport diagnostic, never a shopper-facing catalog string';
			$recorded['error']['message'] = $sentinel;
			$expected_message             = 'Error: ' . $sentinel;
		}

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array( 'error' => $recorded['error'] ) ),
		);

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_rec1' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_rec1' ), 'key_rec1' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $expected_error_code, $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( $expected_message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $expected_shopper, $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
		$this->assertSame( $expected_intent_id, $outcome->get_provider_payment_id(), 'The declined PaymentIntent id must survive onto the failed outcome.' );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertSame( 'allow', ( $data[ PaymentOutcome::DATA_META ] ?? array() )['_wcpay_fraud_meta_box_type'] ?? null, "$pair is a card_error decline, so the fraud meta box must show allow." );
		$this->assertTrue( $data['_wcpay_definitive_charge_failure'] ?? false, "$pair's real decline must be classified as definitive so a retry gets a fresh idempotency key." );
		if ( '' !== $expected_seller_message ) {
			$this->assertStringContainsString( $expected_seller_message, $data[ PaymentOutcome::DATA_NOTE ] ?? '', "$pair's merchant note must carry the platform's seller_message." );
		} else {
			$recorded_seller_message = esc_html( rtrim( (string) ( $recorded['error']['payment_intent']['charges']['data'][0]['outcome']['seller_message'] ?? '' ), '.' ) );
			$this->assertNotSame( '', $recorded_seller_message, "The $pair recording must carry a seller_message for this check to mean anything." );
			$this->assertStringNotContainsString( $recorded_seller_message, $data[ PaymentOutcome::DATA_NOTE ] ?? '', "$pair is not card_declined, so the merchant note must not carry the platform's seller_message." );
		}
	}

	/**
	 * REC-1 recorded decline envelopes, one row per Stripe test card pair, plus a
	 * raw-message-mutated sibling row per pair (see `$mutate_raw_message` on the test method).
	 *
	 * @return array<string,array{string,string,string,string,string,string,bool}>
	 */
	public function recorded_decline_envelope_data(): array {
		$base = array(
			'generic_decline (card_declined)'    => array( 'generic_decline', 'card_declined', 'Error: Your card was declined.', 'Error: Your card was declined.', 'pi_3UJTiNBzWlxcwgpP0GauBpTM', 'The bank did not return any further details with this decline' ),
			'expired_card'                       => array( 'expired_card', 'expired_card', 'Error: Your card has expired.', 'Error: Your card has expired.', 'pi_3UJTirBzWlxcwgpP1t1J6e0x', '' ),
			'insufficient_funds (card_declined)' => array( 'insufficient_funds', 'card_declined', 'Error: Your card has insufficient funds.', 'Error: Your card has insufficient funds.', 'pi_3UJTivBzWlxcwgpP1bPJniIM', 'The bank returned the decline code `insufficient_funds`' ),
			'incorrect_cvc'                      => array( 'incorrect_cvc', 'incorrect_cvc', "Error: Your card's security code is incorrect.", "Error: Your card's security code is incorrect.", 'pi_3UJTizBzWlxcwgpP1uk4AvqD', '' ),
			'processing_error'                   => array( 'processing_error', 'processing_error', 'Error: An error occurred while processing your card. Try again in a little bit.', 'Error: An error occurred while processing your card. Try again in a little bit.', 'pi_3UJTj2BzWlxcwgpP1ildtn5J', '' ),
		);

		$data = array();
		foreach ( $base as $key => $row ) {
			$data[ $key ] = $row;
			$data[ $key . ', raw message replaced with a sentinel' ] = array_merge( $row, array( true ) );
		}

		return $data;
	}

	/**
	 * Load one recorded REC-1 decline entry's HTTP status and `error` object by pair key.
	 *
	 * @param string $pair REC-1 fixture pair key.
	 * @return array{http_status:int,error:array<string,mixed>}
	 */
	private function load_recorded_decline_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-1-intention-declines.json' );
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

		$this->fail( "REC-1 fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox A native card decline over a fake transport fails the order exactly once with the recorded intent identity.
	 *
	 * T.3 Task 2 (`plan-task-t3.md`): joins the two halves T.1 proved separately — the adapter's own
	 * decline-envelope mapping ({@see self::test_native_charge_decline_envelope_maps_each_card_code})
	 * and {@see \Automattic\WooCommerce\Tests\Internal\Payments\PaymentProcessingServiceTest::test_woopayments_card_decline_fails_order_with_intent_note_and_allow_meta}'s
	 * hand-mirrored outcome — by running the recorded REC-1 decline envelope through the real
	 * {@see PaymentProcessingService::process_checkout}, real {@see WooPaymentsProvider}, this adapter,
	 * the real {@see WooPaymentsApiClient}, and the real {@see WooPaymentsOrderEffectApplier} against a
	 * FAKEHTTP transport (`Fixtures/rec-1-intention-declines.json`).
	 *
	 * @dataProvider recorded_checkout_decline_envelope_data
	 *
	 * @param string $pair                REC-1 fixture pair key.
	 * @param string $expected_intent_id  Recorded declined PaymentIntent ID.
	 */
	public function test_native_card_decline_over_fake_transport_fails_order_once_with_recorded_intent( string $pair, string $expected_intent_id ): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$account_service = $this->create_account_service( false );
		$recorded        = $this->load_recorded_decline_entry( $pair );

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array( 'error' => $recorded['error'] ) ),
		);
		$customer_service      = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_rec1_checkout' );

		$provider = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_checkout(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_rec1_checkout' ),
			$provider
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( $expected_intent_id, $order->get_meta( '_intent_id', true ), "The $pair declined PaymentIntent id must survive onto the order." );
		$this->assertSame( '', (string) $order->get_meta( '_charge_id', true ), "A declined $pair charge must leave no charge id." );
		$this->assertSame( 1, $http_client->request_count, "The $pair checkout must dispatch exactly one intentions request." );

		$failed_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, '<strong>failed</strong> to complete with the following message:' )
			)
		);
		$this->assertCount( 1, $failed_notes, "Exactly one failed-payment note should record the $pair decline." );
	}

	/**
	 * @testdox An Afterpay checkout with no usable shipping or billing address fails the order with the client's notice and note, and sends nothing.
	 *
	 * Client 11.1.0 throws Invalid_Address_Exception before any request (class-wc-payment-gateway-wcpay.php:5333-5340); the
	 * process_payment() catch fails the order, writes "A payment of %1$s <strong>failed</strong> to complete with the
	 * following message: <code>%2$s</code>." with the message's final period trimmed (:1352-1394), and shows the message
	 * itself to the shopper through get_filtered_error_message() (:1418; class-wc-payments-utils.php:769-770) (review 36 F5).
	 */
	public function test_afterpay_checkout_without_a_usable_address_fails_with_the_client_notice_and_note(): void {
		$order = $this->create_woopayments_order( '80.00' );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID_PREFIX . 'afterpay_clearpay' );
		$order->set_currency( 'USD' );
		$order->save();
		$http_client      = new FakeWooPaymentsHttpClient();
		$account_service  = $this->create_account_service( false );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_afterpay' );
		$provider   = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'afterpay_clearpay' );
		$this->assertNotNull( $definition );
		$gateway = new NativeWooPaymentsGateway( $definition );
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$_POST['wcpay-payment-method'] = 'pm_afterpay';

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 0, $http_client->request_count, 'The payment is refused before any request.' );
		$this->assertSame( array( 'A valid shipping address is required for Afterpay payments.' ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$notes = array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertContains( 'A payment of ' . wc_price( 80.00, array( 'currency' => 'USD' ) ) . ' <strong>failed</strong> to complete with the following message: <code>A valid shipping address is required for Afterpay payments</code>.', $notes );
	}

	/**
	 * REC-1 recorded decline pairs used by the checkout-over-fake-transport test, one card-error and one non-card-declined code.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function recorded_checkout_decline_envelope_data(): array {
		return array(
			'generic_decline (card_declined)' => array( 'generic_decline', 'pi_3UJTiNBzWlxcwgpP0GauBpTM' ),
			'processing_error'                => array( 'processing_error', 'pi_3UJTj2BzWlxcwgpP1ildtn5J' ),
		);
	}

	/**
	 * @testdox Native charge returns a referenced plan before settlement enrichment runs.
	 */
	public function test_native_charge_returns_referenced_plan_before_settlement_enrichment(): void {
		$order              = $this->create_woopayments_order();
		$gateway            = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client         = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'create_and_confirm_payment_intention' ) )
			->getMock();
		$customer_service   = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$order_data_service = $this->getMockBuilder( WooPaymentsOrderDataService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_settlement_exchange_rate_order_meta' ) )
			->getMock();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'create_and_confirm_payment_intention' )
			->willReturn(
				array(
					'id'             => 'pi_before_enrichment',
					'status'         => 'succeeded',
					'customer'       => 'cus_before_enrichment',
					'payment_method' => 'pm_before_enrichment',
					'currency'       => 'usd',
					'charges'        => array(
						'data' => array(),
					),
				)
			);
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_before_enrichment' );
		$order_data_service->expects( $this->never() )
			->method( 'get_settlement_exchange_rate_order_meta' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, null, $order_data_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_before_enrichment' ), 'key_before_enrichment' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_before_enrichment', $outcome->get_provider_payment_id() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
	}

	/**
	 * @testdox Native charge decoding should plan card display effects without mutating the order.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. The response is now REC-3DS-1's new-card
	 * variant (`Fixtures/rec-t3-3ds-requires-action.json`, pair `new_card_requires_action`), real
	 * from the local platform. A real `requires_action` PaymentIntent carries no charge yet
	 * (`charges.total_count 0`): unlike the earlier hand-written stub, which put a charge on the
	 * requires_action intent, this outcome carries no `charge_id` at all —
	 * `WooPaymentsIntentCodec::outcome_from_intention()` only sets that key when `latest_charge()`
	 * finds one (`WooPaymentsIntentCodec.php:47-49`).
	 */
	public function test_native_charge_returns_display_effect_plan_without_mutating_order(): void {
		$order                 = $this->create_woopayments_order();
		$gateway               = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$recorded              = $this->load_recorded_intent_entry( 'rec-t3-3ds-requires-action.json', 'new_card_requires_action' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertArrayNotHasKey( 'charge_id', $outcome->get_data(), 'REC-3DS-1 has no charge yet on a requires_action intent.' );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertStringStartsWith( '#wcpay-confirm-pi:' . $order->get_id() . ':' . $recorded['body']['client_secret'] . ':', $outcome->get_redirect_url() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( '', $order->get_payment_method_title() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 0, $gateway->processed_order_id );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Native charge uses the intent WooPay already confirmed instead of creating a second one.
	 */
	public function test_native_charge_uses_the_woopay_intent_instead_of_creating_one(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client(
			array(
				'id'             => 'pi_woopay',
				'status'         => 'succeeded',
				'customer'       => 'cus_platform_clone',
				'payment_method' => 'pm_merchant_clone',
				'currency'       => 'usd',
				'metadata'       => array( 'order_id' => (string) $order->get_id() ),
			)
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'pi_woopay' );

		$this->assertSame( array( 'pi_woopay' ), $api_client->payment_intent_reads );
		$this->assertSame( 0, $api_client->creates, 'Client 11.1.0 uses the WooPay intent and never creates a second one (class-wc-payment-gateway-wcpay.php:1792-1812).' );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_woopay', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_merchant_clone', $outcome->get_payment_method_id() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
	}

	/**
	 * @testdox Native charge refuses a WooPay intent whose metadata names another order, with the client's message.
	 */
	public function test_native_charge_refuses_a_woopay_intent_confirmed_for_another_order(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$other_id   = $order->get_id() + 1;
		$api_client = $this->create_woopay_intent_api_client(
			array(
				'id'             => 'pi_woopay',
				'status'         => 'succeeded',
				'payment_method' => 'pm_merchant_clone',
				'metadata'       => array( 'order_id' => (string) $other_id ),
			)
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'pi_woopay' );

		$this->assertSame( 0, $api_client->creates );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'order_id_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame(
			sprintf( 'We&#039;re not able to process this payment. Please try again later. WooPayMeta: intent_meta_order_id: %1$d, order_id: %2$d', $other_id, $order->get_id() ),
			$outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null,
			'Client 11.1.0 throws Order_ID_Mismatch_Exception with this message and shows it to the shopper.'
		);
		$this->assertStringContainsString( 'WooPayMeta: intent_meta_order_id: ' . $other_id, (string) ( $outcome->get_data()[ PaymentOutcome::DATA_NOTE ] ?? '' ), 'Client 11.1.0 records the refusal in the failed-payment order note.' );
	}

	/**
	 * @testdox Native charge fails without creating an intent when the WooPay intent cannot be read.
	 */
	public function test_native_charge_fails_when_the_woopay_intent_cannot_be_read(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client(
			array(),
			array(),
			new WooPaymentsApiException( 'No such payment_intent: \'pi_missing\'', 'resource_missing', 404, 'invalid_request_error' )
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'pi_missing' );

		$this->assertSame( array( 'pi_missing' ), $api_client->payment_intent_reads );
		$this->assertSame( 0, $api_client->creates, 'Client 11.1.0 lets the Get_Intention failure fail the payment; it never falls back to a new intent.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'resource_missing', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
	}

	/**
	 * @testdox Native charge refuses a malformed WooPay intent id before any request, with the client's message.
	 */
	public function test_native_charge_refuses_a_malformed_woopay_intent_id_before_reading_it(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client( array( 'id' => 'abc123' ) );

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'abc123' );

		$this->assertSame( array(), $api_client->payment_intent_reads, 'Client 11.1.0 Get_Intention validates the id before sending (class-request.php:669-708).' );
		$this->assertSame( 0, $api_client->creates );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'wcpay_core_invalid_request_parameter_stripe_id', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'abc123 is not a valid Stripe identifier', $outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * @testdox Native charge treats a WooPay intent id of "0" as absent, as the client's empty() check does.
	 */
	public function test_native_charge_treats_a_zero_woopay_intent_id_as_absent(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client( array() );

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, '0' );

		$this->assertSame( array(), $api_client->payment_intent_reads );
		$this->assertSame( 1, $api_client->creates, 'Client 11.1.0 guards the fetch with ! empty() (class-wc-payment-gateway-wcpay.php:1799) and creates the intent instead.' );
		$this->assertSame( 'pi_second', $outcome->get_provider_payment_id() );
	}

	/**
	 * @testdox Native zero-total checkout uses the setup intent WooPay already confirmed instead of creating one.
	 */
	public function test_native_setup_intent_uses_the_woopay_setup_intent_instead_of_creating_one(): void {
		$order      = $this->create_woopayments_order( '0.00' );
		$api_client = $this->create_woopay_intent_api_client(
			array(),
			array(
				'id'             => 'seti_woopay',
				'status'         => 'succeeded',
				'customer'       => 'cus_native',
				'payment_method' => 'pm_merchant_clone',
				'metadata'       => array( 'order_id' => (string) $order->get_id() ),
			)
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'seti_woopay' );

		$this->assertSame( array( 'seti_woopay' ), $api_client->setup_intent_reads );
		$this->assertSame( array(), $api_client->payment_intent_reads );
		$this->assertSame( 0, $api_client->creates, 'Client 11.1.0 uses the WooPay setup intent and never creates one (class-wc-payment-gateway-wcpay.php:1926-1942).' );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'seti_woopay', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_merchant_clone', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Native zero-total checkout fails without creating a setup intent when the WooPay setup intent cannot be read.
	 */
	public function test_native_setup_intent_fails_when_the_woopay_setup_intent_cannot_be_read(): void {
		$order      = $this->create_woopayments_order( '0.00' );
		$api_client = $this->create_woopay_intent_api_client(
			array(),
			array(),
			new WooPaymentsApiException( 'No such setupintent: \'seti_missing\'', 'resource_missing', 404, 'invalid_request_error' )
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'seti_missing' );

		$this->assertSame( array( 'seti_missing' ), $api_client->setup_intent_reads );
		$this->assertSame( 0, $api_client->creates, 'Client 11.1.0 lets the Get_Setup_Intention failure fail the payment (class-wc-payment-gateway-wcpay.php:1926-1932).' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'resource_missing', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
	}

	/**
	 * @testdox Native zero-total checkout refuses a WooPay setup intent whose metadata names another order.
	 */
	public function test_native_setup_intent_refuses_a_woopay_setup_intent_for_another_order(): void {
		$order      = $this->create_woopayments_order( '0.00' );
		$api_client = $this->create_woopay_intent_api_client(
			array(),
			array(
				'id'             => 'seti_woopay',
				'status'         => 'succeeded',
				'payment_method' => 'pm_merchant_clone',
				'metadata'       => array( 'order_id' => 'not-a-number' ),
			)
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'seti_woopay' );

		$this->assertSame( 0, $api_client->creates );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'order_id_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'We\'re not able to process this payment. Please try again later.', $outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * @testdox Charge confirms a zero-total checkout paid with a saved card without any intent, as client 11.1.0 does.
	 *
	 * Client gw:1688 skips the intent when the order needs no payment and no new payment method is saved; a saved token is never saved again.
	 */
	public function test_charge_confirms_zero_total_checkout_with_saved_card_without_intent(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_card_token( $user_id, 'pm_zero' );
		$api_client       = new class() extends WooPaymentsApiClient {
			/** @var int */
			public int $setup_intent_calls = 0;

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Count SetupIntent requests: the client makes none here.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );
				++$this->setup_intent_calls;

				return array();
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $saved_token );
		$order->save();
		$token_service = $this->create_single_resolution_token_service( $saved_token, $user_id, 'pm_zero' );

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->with( $this->isInstanceOf( WC_Order::class ) )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $saved_token->get_id() )
			),
			'key_setup'
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 0, $api_client->setup_intent_calls );
		$this->assertSame( '', $outcome->get_provider_payment_id(), 'No intent, so no transaction id (client gw:1702 payment_complete() without one).' );
		$this->assertSame( 'pm_zero', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_native', $outcome->get_customer_id() );
		$this->assertSame( 0, $gateway->processed_order_id );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( $saved_token->get_id(), $outcome->get_effect_plan()->get_provider_result()['token_id'] );
		// Client gw:1675-1679 writes the mode; it writes no intent currency on this branch.
		$this->assertSame( array( '_wcpay_mode' => 'prod' ), $outcome->get_effect_plan()->get_setup_meta() );
	}

	/**
	 * @testdox Zero-total checkout requiring SetupIntent customer action returns the `si` confirmation redirect, not `pi`.
	 *
	 * Free-trial subscription signup and other zero-total checkouts create a native SetupIntent
	 * (`setup_intent_via_native_transport`), not a PaymentIntent. When that SetupIntent comes back
	 * `requires_action` (a 3DS challenge on the new card the free trial saves; a saved card gets no intent at $0, client
	 * gw:1688), the frontend confirmation hash must read
	 * `#wcpay-confirm-si:...`, matching client 11.1.0's own `$payment_needed ? 'pi' : 'si'` branch
	 * (`gw:2111`, `class-wc-payment-gateway-wcpay.php`): a SetupIntent has no payment to confirm, so the
	 * frontend must call `stripe.confirmSetup()`, not `confirmPayment()`. Only the codec unit test
	 * (`WooPaymentsIntentCodecTest::test_confirmation_redirect_uses_explicit_nonce`) and the effect-plan
	 * test (`WooPaymentsOrderEffectApplierTest::test_setup_intent_effects_persist_provider_references`)
	 * covered pieces of this before; neither goes through the adapter's own intent-type wiring.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. The response is now REC-3DS-4's recorded envelope
	 * (`Fixtures/rec-t3-setup-intent-requires-action.json`, pair `setup_intent_requires_action`), the
	 * same real My Account add-payment-method `requires_action` SetupIntent REC-2 recorded its
	 * declines from.
	 */
	public function test_zero_total_setup_intent_requiring_action_returns_si_confirmation_redirect(): void {
		$user_id               = $this->factory()->user->create();
		$order                 = $this->create_woopayments_order( '0.00' );
		$gateway               = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$recorded              = $this->load_recorded_intent_entry( 'rec-t3-setup-intent-requires-action.json', 'setup_intent_requires_action' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$order->set_customer_id( $user_id );
		$order->save();

		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( (string) $recorded['body']['customer'] );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_zero_action',
				array(),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true )
			),
			'key_setup_action'
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertStringStartsWith( '#wcpay-confirm-si:' . $order->get_id() . ':' . $recorded['body']['client_secret'] . ':', $outcome->get_redirect_url() );
		$this->assertStringNotContainsString( '#wcpay-confirm-pi:', $outcome->get_redirect_url() );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Zero-total card checkout should flag platform-created payment methods for WCPay.
	 */
	public function test_zero_total_charge_flags_platform_created_payment_methods_for_wcpay(): void {
		$order            = $this->create_woopayments_order( '0.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
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
			 * Create and confirm a setup intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				return array(
					'id'             => 'seti_platform',
					'status'         => 'succeeded',
					'client_secret'  => 'seti_platform_secret_abc',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_connected',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_platform',
				// Client 11.1.0 creates a SetupIntent for a $0 order only when it saves the payment method (gw:1688).
				array( 'save_payment_method' => true ),
				array( 'is_platform_payment_method' => true )
			),
			'key_setup'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pm_platform', $api_client->last_request_data['payment_method'] );
		$this->assertTrue( $api_client->last_request_data['is_platform_payment_method'] );
	}

	/**
	 * @testdox Zero-total recurring transport should plan token synchronization without mutating subscriptions.
	 */
	public function test_zero_total_recurring_charge_plans_token_sync_without_writes(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$subscription     = $this->create_woopayments_order( '10.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a setup intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'seti_native',
					'status'         => 'succeeded',
					'client_secret'  => 'seti_native_secret_abc',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$token_service    = $this->create_token_service(
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

		$order->set_customer_id( $user_id );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->save();

		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut          = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
		$outcome      = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_zero',
				array( 'save_payment_method' => true ),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true )
			),
			'key_setup'
		);
		$order        = wc_get_order( $order->get_id() );
		$tokens       = \WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );
		$subscription = wc_get_order( $subscription->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertEmpty( $tokens );
		$this->assertEmpty( $order->get_payment_tokens() );
		$this->assertEmpty( $subscription->get_payment_tokens() );
		$this->assertSame( '', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $subscription->get_meta( '_stripe_customer_id', true ) );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertTrue( $outcome->get_effect_plan()->should_apply_token_effects() );
		$this->assertTrue( $outcome->get_effect_plan()->is_recurring() );
	}

	/**
	 * @testdox Refund should normalize legacy success and errors.
	 */
	public function test_refund_normalizes_legacy_success_and_errors(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$sut     = $this->create_adapter( $gateway );

		$success = $sut->refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' );

		$gateway->refund_result = new WP_Error( 'refund_failed', 'Refund failed.' );
		$failure                = $sut->refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $success->get_status() );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $failure->get_status() );
		$this->assertSame( 'refund_failed', $failure->get_data()['error_code'] );
		$this->assertSame( 3.50, $gateway->refund_amount );
		$this->assertSame( 'Adjustment', $gateway->refund_reason );
		$this->assertSame( 'key_refund', $gateway->last_idempotency_key );
	}

	/**
	 * @testdox Refund should prefer the native transport before the legacy gateway bridge.
	 */
	public function test_refund_prefers_native_transport_when_available(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'refund_charge' ) )
			->getMock();

		$order->update_meta_data( '_charge_id', 'ch_native' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'refund_charge' )
			->with( 'ch_native', 350, 'Adjustment', $this->isType( 'string' ), 'key_refund' )
			->willReturn(
				array(
					'id'                  => 're_native',
					'status'              => 'pending',
					'balance_transaction' => array( 'id' => 'txn_refund' ),
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 're_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pending', $outcome->get_data()['refund_status'] );
		$this->assertSame( 'txn_refund', $outcome->get_data()['refund_balance_transaction_id'] );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_ORDER_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_NOTE, $outcome->get_data() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_REFUND, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 're_native', $outcome->get_effect_plan()->get_provider_result()['id'] );
		$this->assertNull( $gateway->refund_amount );
	}

	/**
	 * @testdox A native refund should retain its provider identity before local note formatting runs.
	 */
	public function test_native_refund_retains_provider_identity_before_local_effects(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'refund_charge' ) )
			->getMock();

		$order->update_meta_data( '_charge_id', 'ch_native_effect_boundary' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'refund_charge' )->willReturn(
			array(
				'id'                  => 're_effect_boundary',
				'status'              => 'succeeded',
				'balance_transaction' => array( 'id' => 'txn_effect_boundary' ),
			)
		);

		$outcome = $this->create_adapter( $gateway, $api_client )->refund(
			PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50, 'Adjustment' ),
			'key_refund_effect_boundary'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 're_effect_boundary', $outcome->get_provider_payment_id() );
		$this->assertSame( 'txn_effect_boundary', $outcome->get_data()['refund_balance_transaction_id'] );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_NOTE, $outcome->get_data() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_REFUND, $outcome->get_effect_plan()->get_type() );
	}

	/**
	 * @testdox Refund should fail closed when the native transport returns a failed provider status.
	 */
	public function test_refund_fails_closed_for_failed_native_refund_status(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'refund_charge' ) )
			->getMock();

		$order->update_meta_data( '_charge_id', 'ch_native' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'refund_charge' )
			->with( 'ch_native', 350, 'Adjustment', $this->isType( 'string' ), 'key_refund' )
			->willReturn(
				array(
					'id'             => 're_failed',
					'status'         => 'failed',
					'failure_reason' => 'lost_or_stolen_card',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 're_failed', $outcome->get_provider_payment_id() );
		$this->assertSame( 'failed', $outcome->get_data()['refund_status'] );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()['error_code'] );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()['error_message'] );
		$this->assertArrayNotHasKey( 'refund_meta', $outcome->get_data() );
		$this->assertArrayNotHasKey( 'order_meta', $outcome->get_data() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertNull( $gateway->refund_amount );
	}

	/**
	 * @testdox A native refund over a fake transport persists the provider refund identity, status, and exactly one note.
	 *
	 * End to end through the production wiring: this test calls the real
	 * {@see PaymentProcessingService::process_refund} directly (no gateway
	 * boundary in between), which calls the real {@see WooPaymentsProvider},
	 * this adapter, the real {@see WooPaymentsApiClient}, and the real
	 * {@see WooPaymentsOrderEffectApplier} against a FAKEHTTP transport queued
	 * with the exact refund response REC-5a R-a recorded from local WPCOM
	 * (`Fixtures/rec-5a-refunds.json`). The request body sent over that
	 * transport is compared to the recorded request. The recording settles F9:
	 * the platform's refund `balance_transaction` is a bare string id, not the
	 * expanded object some native sync fixtures use, and
	 * {@see \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects::balance_transaction_id()}
	 * already accepts either shape.
	 *
	 * @dataProvider recorded_refund_envelope_data
	 *
	 * @param string      $pair                  REC-5a R-a fixture pair key.
	 * @param string      $currency              Order currency.
	 * @param string      $amount                Refund amount, matching the recorded charge total.
	 * @param int         $amount_minor          Refund amount in minor units, as sent on the wire.
	 * @param string      $charge_id             Recorded source charge ID.
	 * @param string      $refund_id             Recorded provider refund ID.
	 * @param string      $reason                Merchant-supplied refund reason.
	 * @param string|null $expected_wire_reason  Expected `reason` field on the wire: the enum value, or `null` for free text.
	 */
	public function test_native_refund_over_fake_transport_persists_refund_identity_status_and_one_note( string $pair, string $currency, string $amount, int $amount_minor, string $charge_id, string $refund_id, string $reason, ?string $expected_wire_reason ): void {
		$order = $this->create_woopayments_order( $amount );
		$order->set_currency( $currency );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => (float) $amount,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$recorded              = $this->load_recorded_refund_entry( $pair );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( true );
		$provider              = $this->create_provider_over_fake_transport( $http_client, $account_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, (float) $amount, $reason ),
			$provider
		);

		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( $refund_id, $refund->get_meta( '_wcpay_refund_id', true ), "The Woo refund must store the exact $pair provider refund id." );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 1, $http_client->request_count );
		// F9: the recorded refund's `balance_transaction` is a bare string id, not the
		// expanded object some native sync fixtures use.
		$this->assertSame( (string) $recorded['body']['balance_transaction'], $refund->get_meta( '_wcpay_refund_transaction_id', true ) );

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( $charge_id, $sent['charge'] ?? null, "The $pair request must target the exact recorded source charge." );
		$this->assertSame( $amount_minor, $sent['amount'] ?? null, "The $pair request must send the exact recorded minor-unit amount." );
		$this->assertArrayHasKey( 'reason', $sent );
		$this->assertSame( $expected_wire_reason, $sent['reason'], "The $pair request's enumerated reason must match the recording." );
		$this->assertSame( $reason, $sent['metadata']['merchant_refund_reason'] ?? null, "The $pair request must carry the merchant reason as metadata." );

		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, $refund_id )
			)
		);
		$this->assertCount( 1, $notes, "Exactly one note should reference the $pair refund." );
	}

	/**
	 * @testdox Each refund call sends its own Idempotency-Key, and a transport retry inside one call reuses it.
	 *
	 * Client 11.1.0 `process_refund()` sets no caller key on the refund request
	 * (class-wc-payment-gateway-wcpay.php:2970-2976), so `request()` mints a UUID for each call
	 * (class-wc-payments-api-client.php:2690) and its retry loop resends the same headers
	 * (class-wc-payments-api-client.php:2711-2771). The older manual refund of the same amount is the
	 * row the derived key used to bind to, which made the retry replay the first failure.
	 */
	public function test_native_refund_calls_send_distinct_idempotency_keys_over_fake_transport(): void {
		$order = $this->create_woopayments_order();
		$order->set_currency( 'USD' );
		$order->update_meta_data( '_charge_id', 'ch_refund_keys' );
		$order->save();
		$manual_refund = $this->create_local_refund_row( $order );
		$manual_refund->set_date_created( time() - DAY_IN_SECONDS );
		$manual_refund->save();

		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			$this->refund_transport_response(
				array(
					'id'             => 're_refund_keys_failed',
					'status'         => 'failed',
					'failure_reason' => 'lost_or_stolen_card',
				)
			),
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			$this->refund_transport_response(
				array(
					'id'                  => 're_refund_keys',
					'status'              => 'succeeded',
					'balance_transaction' => 'txn_refund_keys',
				)
			),
		);
		$provider               = $this->create_provider_over_fake_transport( $http_client, $this->create_account_service( true ) );
		$processing_service     = wc_get_container()->get( PaymentProcessingService::class );
		$context                = PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' );

		$failed_refund = $this->create_local_refund_row( $order );
		$first_result  = $processing_service->process_refund( $context, $provider );
		// WooCommerce deletes the refund row when the gateway refund fails (wc-order-functions.php:675-680).
		$failed_refund->delete( true );

		$refund        = $this->create_local_refund_row( $order );
		$second_result = $processing_service->process_refund( $context, $provider );

		$this->assertWPError( $first_result );
		$this->assertTrue( $second_result );
		$this->assertSame( 3, $http_client->request_count, 'The failed call sends one request; the second call sends one request and one transport retry.' );
		$first_key = $http_client->requests[0]['headers']['Idempotency-Key'] ?? '';
		$this->assertNotSame( '', $first_key );
		$this->assertNotSame( $first_key, $http_client->requests[1]['headers']['Idempotency-Key'] ?? '', 'Each refund call must send its own key, as the client does.' );
		$this->assertSame( $http_client->requests[1]['headers']['Idempotency-Key'] ?? '', $http_client->requests[2]['headers']['Idempotency-Key'] ?? '', 'A transport retry inside one call must resend the same key, as the client retry loop does.' );
		$this->assertSame( 're_refund_keys', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $manual_refund->get_id() )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * Create a local refund row of 2.50 without refunding through a gateway.
	 *
	 * @param WC_Order $order Parent order.
	 * @return WC_Order_Refund
	 */
	private function create_local_refund_row( WC_Order $order ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		return $refund;
	}

	/**
	 * Build a fake-transport refund response.
	 *
	 * @param array<string,mixed> $refund Refund fields that vary per case.
	 * @return array<string,mixed>
	 */
	private function refund_transport_response( array $refund ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array_merge(
					array(
						'object'   => 'refund',
						'amount'   => 250,
						'currency' => 'usd',
						'charge'   => 'ch_refund_keys',
					),
					$refund
				)
			),
		);
	}

	/**
	 * REC-5a R-a recorded refund envelopes, one row per currency (USD, EUR).
	 *
	 * @return array<string,array{string,string,string,int,string,string,string,string|null}>
	 */
	public function recorded_refund_envelope_data(): array {
		return array(
			'usd card, free-text reason' => array(
				'usd_card_full_refund_free_text_reason',
				'USD',
				'10.99',
				1099,
				'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
				're_3UJZZBBzWlxcwgpP0MauAsJ6',
				'REC-5a free-text reason: customer returned the item unopened',
				null,
			),
			'eur card'                   => array(
				'eur_card_full_refund',
				'EUR',
				'12.34',
				1234,
				'ch_3UJWs2BzWlxcwgpP1y9vRrWr',
				're_3UJWs2BzWlxcwgpP1MZ11w9h',
				'requested_by_customer',
				'requested_by_customer',
			),
		);
	}

	/**
	 * Load one recorded REC-5a R-a refund entry's HTTP status and response body by pair key.
	 *
	 * @param string $pair REC-5a R-a fixture pair key.
	 * @return array{http_status:int,content_type:string,body:array<string,mixed>}
	 */
	private function load_recorded_refund_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-5a-refunds.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status'  => (int) $entry['response']['http_status'],
					'content_type' => (string) $entry['response']['content_type'],
					'body'         => $entry['response']['body'],
				);
			}
		}

		$this->fail( "REC-5a R-a fixture has no entry for pair '$pair'." );
	}

	/**
	 * Load one recorded PaymentIntent entry's HTTP status, response body, and sent request body by
	 * fixture file and pair key.
	 *
	 * Shared by the T.3 Task 2 checkout-over-fake-transport tests, which each read a different
	 * `Fixtures/rec-t3-*.json` or `Fixtures/rec-3-eur-charge.json` recording.
	 *
	 * @param string $fixture Fixture file name under `Fixtures/`.
	 * @param string $pair    Fixture pair key.
	 * @return array{http_status:int,content_type:string,body:array<string,mixed>,request_body:array<string,mixed>}
	 */
	private function load_recorded_intent_entry( string $fixture, string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/' . $fixture );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status'  => (int) $entry['response']['http_status'],
					'content_type' => (string) $entry['response']['content_type'],
					'body'         => $entry['response']['body'],
					'request_body' => $entry['request']['body'],
				);
			}
		}

		$this->fail( "Fixture '$fixture' has no entry for pair '$pair'." );
	}

	/**
	 * @testdox A native card checkout over a fake transport pays the order exactly once with the recorded intent identity.
	 *
	 * T.3 Task 2 (`plan-task-t3.md`): joins the request-shape half
	 * ({@see self::test_single_card_checkout_without_save_matches_11_1_request_shape}) and the
	 * order-completion half ({@see \Automattic\WooCommerce\Tests\Internal\Payments\PaymentProcessingServiceTest::test_process_checkout_completes_order_for_completed_outcome})
	 * by running a real recorded PaymentIntent response through the full production stack:
	 * {@see PaymentProcessingService::process_checkout} → the real {@see WooPaymentsProvider} → this
	 * adapter → the real {@see WooPaymentsApiClient} → a FAKEHTTP transport queued with REC-BC
	 * (`Fixtures/rec-t3-basic-card.json`, USD, no currency conversion) and REC-3
	 * (`Fixtures/rec-3-eur-charge.json`, EUR charge on a USD account), which also joins the
	 * multi-currency settlement-meta path end to end for a real converted checkout. The order carries
	 * one physical product line item, matching the plan's smoke and REC-BC/REC-3's real (non-virtual)
	 * checkouts, so `payment_complete()` needs processing and lands the order on `processing`
	 * (`OrderPaymentLifecycleService::apply_status_transition()`), not the bare zero-item order's
	 * `completed` that `test_process_checkout_completes_order_for_completed_outcome` exercises.
	 *
	 * @dataProvider recorded_card_checkout_envelope_data
	 *
	 * @param string      $fixture        Fixture file name under `Fixtures/`.
	 * @param string      $pair           Fixture pair key.
	 * @param string      $currency       Order currency.
	 * @param string      $amount         Order total, matching the recorded charge amount.
	 * @param int         $amount_minor   Recorded amount in minor units, as sent on the wire.
	 * @param string      $customer_id    Recorded provider customer ID.
	 * @param string      $intent_id      Recorded PaymentIntent ID.
	 * @param string      $charge_id      Recorded charge ID.
	 * @param string      $transaction_id Recorded balance-transaction ID.
	 * @param string|null $exchange_rate  Expected `_wcpay_multi_currency_stripe_exchange_rate` meta, or `null` when no conversion applies.
	 */
	public function test_native_card_checkout_over_fake_transport_pays_order_once_with_recorded_intent( string $fixture, string $pair, string $currency, string $amount, int $amount_minor, string $customer_id, string $intent_id, string $charge_id, string $transaction_id, ?string $exchange_rate ): void {
		$order = $this->create_woopayments_order( $amount );
		$order->set_currency( $currency );
		// A physical line item makes the order need processing, matching the recorded checkout's
		// real product and the provider smoke's expected post-payment status.
		$order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$order->set_total( $amount );
		$order->save();

		$recorded              = $this->load_recorded_intent_entry( $fixture, $pair );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$customer_service      = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( $customer_id );

		$provider = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_checkout(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card_visa' ),
			$provider
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), "The $pair checkout must pay the physical-product order exactly once." );
		$this->assertSame( 1, $http_client->request_count, "The $pair checkout must dispatch exactly one intentions request." );
		$this->assertSame( 'POST', $http_client->last_method, "The $pair checkout must dispatch a POST." );
		$this->assertStringEndsWith( 'intentions', $http_client->last_path, "The $pair checkout must target the intentions route." );
		$this->assertSame( $intent_id, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( $charge_id, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( $transaction_id, $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$this->assertSame( strtoupper( $currency ), $order->get_meta( '_wcpay_intent_currency', true ) );
		if ( null !== $exchange_rate ) {
			$this->assertSame( $exchange_rate, $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ), "The $pair converted checkout must persist REC-3's settlement exchange rate." );
		}

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( $amount_minor, $sent['amount'] ?? null, "The $pair request must send the exact recorded minor-unit amount." );
		$this->assertSame( strtolower( $currency ), $sent['currency'] ?? null );
		$this->assertSame( $customer_id, $sent['customer'] ?? null );
		$this->assertSame( $recorded['request_body']['payment_method'], $sent['payment_method'] ?? null, "The $pair request must send the recorded payment method." );
		$this->assertSame( $recorded['request_body']['capture_method'], $sent['capture_method'] ?? null, "The $pair request must send the recorded capture method." );

		$success_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 'successfully charged' )
			)
		);
		$this->assertCount( 1, $success_notes, "Exactly one success note (the client's `successfully charged` mark-paid wording) should reference the $pair payment." );
	}

	/**
	 * REC-BC (USD, no conversion) and REC-3 (EUR charge on a USD account) recorded checkout envelopes.
	 *
	 * @return array<string,array{string,string,string,string,int,string,string,string,string,string|null}>
	 */
	public function recorded_card_checkout_envelope_data(): array {
		return array(
			'usd basic card, no conversion'          => array(
				'rec-t3-basic-card.json',
				'basic_card_usd_create_and_confirm',
				'USD',
				'10.99',
				1099,
				'cus_UsIeTbmGHPc9jY',
				'pi_3UJhO2BzWlxcwgpP1BndTguC',
				'ch_3UJhO2BzWlxcwgpP1zmUXW90',
				'txn_3UJhO2BzWlxcwgpP1qAkBhRS',
				null,
			),
			'eur charge converted to usd settlement' => array(
				'rec-3-eur-charge.json',
				'eur_charge_create_and_confirm',
				'EUR',
				'12.34',
				1234,
				'cus_UsIeTbmGHPc9jY',
				'pi_3UJWs2BzWlxcwgpP10KzkjsT',
				'ch_3UJWs2BzWlxcwgpP1y9vRrWr',
				'txn_3UJWs2BzWlxcwgpP1MLoqLbF',
				'1.13905',
			),
		);
	}

	/**
	 * @testdox A native card checkout with save over a fake transport creates exactly one token from the recorded payment method.
	 *
	 * T.3 Task 2 (`plan-task-t3.md`): the save path from a real recorded response to exactly one token
	 * row, joining {@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplierTest::test_requested_token_effects_are_idempotent}'s
	 * hand-built outcome with a real REC-SUB step-1 signup response
	 * (`Fixtures/rec-t3-subscription.json`, pair `signup_initial_setup_future_usage`) run through the
	 * full production stack, including the real {@see \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService}.
	 */
	public function test_native_card_checkout_with_save_over_fake_transport_creates_one_token_from_recorded_payment_method(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order( '10.99' );
		$order->set_customer_id( $user_id );
		$order->save();

		$recorded              = $this->load_recorded_intent_entry( 'rec-t3-subscription.json', 'signup_initial_setup_future_usage' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$customer_service      = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_UsIeTbmGHPc9jY' );
		$token_service = $this->create_token_service(
			array(
				'pm_1UJhOFBzWlxcwgpPvcySvyc5' => array(
					'id'   => 'pm_1UJhOFBzWlxcwgpPvcySvyc5',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 9,
						'exp_year'  => 2027,
					),
				),
			)
		);

		// The container's real WooPaymentsOrderEffectApplier singleton resolves its own token
		// service independently of the one built above, so token creation is exercised through a
		// locally built applier wired to this test's fake-details token service instead.
		$order_effect_applier = new WooPaymentsOrderEffectApplier();
		$order_effect_applier->init(
			$token_service,
			wc_get_container()->get( WooPaymentsOrderDataService::class ),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);
		$provider = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service, $token_service, $order_effect_applier );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_checkout(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card_visa', array( 'save_payment_method' => true ) ),
			$provider
		);

		$order  = wc_get_order( $order->get_id() );
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );
		$token  = reset( $tokens );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $http_client->request_count );

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( 'off_session', $sent['setup_future_usage'] ?? null, 'A save request must ask for an off-session reusable PaymentMethod, matching REC-SUB.' );

		$this->assertCount( 1, $tokens, 'Exactly one token must be created from the recorded payment method.' );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertSame( 'pm_1UJhOFBzWlxcwgpPvcySvyc5', $token->get_token(), "The saved token must be the exact payment method REC-SUB's signup response returned." );
		$this->assertSame( 'visa', $token->get_card_type(), "The saved token's card brand must match REC-SUB's recorded payment method." );
		$this->assertSame( '4242', $token->get_last4(), "The saved token's last4 must match REC-SUB's recorded payment method." );
		$this->assertSame( '09', $token->get_expiry_month(), "The saved token's expiry month must match REC-SUB's recorded payment method, zero-padded as WC_Payment_Token_CC::set_expiry_month() normalizes it." );
		$this->assertSame( '2027', $token->get_expiry_year(), "The saved token's expiry year must match REC-SUB's recorded payment method." );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
	}

	/**
	 * @testdox A scheduled renewal charge over a fake transport pays the renewal order exactly once with the recorded off-session intent.
	 *
	 * T.3 Task 2 (`plan-task-t3.md`): joins
	 * {@see self::test_scheduled_subscription_charge_uses_merchant_initiated_recurring_request_shape}'s
	 * merchant-initiated request shape with a real off-session REC-SUB step-2 renewal response
	 * (`Fixtures/rec-t3-subscription.json`, pair `renewal_off_session`), built the same way that test
	 * builds its scheduled-renewal context, run through the full production stack against a FAKEHTTP
	 * transport, and asserts the renewal order actually ends up paid (`completed`,
	 * `OrderPaymentLifecycleService::apply_status_transition()`) with exactly one `successfully
	 * charged` note — not just that the outcome is reported successful. REC-SUB step 2 sends no
	 * `mandate` and no `payment_method_update_data` (`data/rec-t3-api-recordings.md`), so the wire
	 * comparison covers money, customer, payment method, and `off_session`; `metadata` is native's own
	 * order-derived payload (`WooPaymentsIntentRequestBuilder::metadata_from_order()`, filterable via
	 * `wcpay_metadata_from_order`), not the recording's placeholder `metadata` (`{rec: 'REC-SUB', ...}`,
	 * per the plan's documented recording-shape caveat), so it is not compared byte-for-byte here.
	 */
	public function test_scheduled_renewal_over_fake_transport_pays_renewal_order_with_recorded_intent(): void {
		$user_id     = $this->factory()->user->create();
		$order       = $this->create_woopayments_order( '10.99' );
		$saved_token = $this->create_card_token( $user_id, 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $saved_token );
		$order->save();

		$recorded              = $this->load_recorded_intent_entry( 'rec-t3-subscription.json', 'renewal_off_session' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$customer_service      = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_UsIeTbmGHPc9jY' );

		$provider = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_checkout(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array(
					'payment_token'       => (string) $saved_token->get_id(),
					'save_payment_method' => false,
				),
				array( 'scheduled_subscription_payment' => true )
			),
			$provider
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertSame( 'pi_3UJhOUBzWlxcwgpP0FGWIQQW', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_3UJhOUBzWlxcwgpP0xdwB1w8', $order->get_meta( '_charge_id', true ) );

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( 'pm_1UJhOFBzWlxcwgpPvcySvyc5', $sent['payment_method'] ?? null, 'The renewal must resolve and charge the saved token, matching REC-SUB step 2.' );
		$this->assertSame( 'cus_UsIeTbmGHPc9jY', $sent['customer'] ?? null );
		$this->assertSame( 1099, $sent['amount'] ?? null );
		$this->assertSame( 'usd', $sent['currency'] ?? null );
		$this->assertTrue( $sent['off_session'] ?? null, 'A scheduled renewal must send off_session true, matching REC-SUB step 2.' );
		$this->assertArrayNotHasKey( 'setup_future_usage', $sent, 'A scheduled renewal must not request a new setup_future_usage.' );
		$this->assertSame( 'completed', $order->get_status(), 'A succeeded off-session renewal must pay the renewal order exactly once (payment_complete(), OrderPaymentLifecycleService::apply_status_transition()).' );

		$success_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 'successfully charged' )
			)
		);
		$this->assertCount( 1, $success_notes, "Exactly one success note (the client's `successfully charged` mark-paid wording) should reference the renewal payment." );
	}

	/**
	 * Build a real WooPayments provider whose only isolated seam is the raw HTTP transport.
	 *
	 * The production provider, adapter, API client and order-effect applier all
	 * remain in use, following the pattern
	 * `PaymentProcessingServiceTest::review_payment_intent_provider` established.
	 *
	 * @param FakeWooPaymentsHttpClient          $http_client           Fake raw transport queued with a recorded response.
	 * @param WooPaymentsAccountService          $account_service       WooPayments account service.
	 * @param WooPaymentsCustomerService|null    $customer_service      Customer service; a bare mock (no configured methods) when omitted, matching the refund path's needs.
	 * @param WooPaymentsTokenService|null       $token_service         Token service; the default fake-details token service when omitted.
	 * @param WooPaymentsOrderEffectApplier|null $order_effect_applier  Order effect applier; the container's real singleton when omitted. Pass a locally built one wired to `$token_service` when a test needs its own fake payment-method details for token creation, since the container's singleton resolves its own token service independently of this method's `$token_service` argument.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_over_fake_transport( FakeWooPaymentsHttpClient $http_client, WooPaymentsAccountService $account_service, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsOrderEffectApplier $order_effect_applier = null ): WooPaymentsProvider {
		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );

		$adapter = $this->create_adapter( null, $api_client, $customer_service, $token_service, $account_service );

		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
			$order_effect_applier ?? wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * @testdox A pending redirect-method refund over a fake transport becomes successful on a `charge.refund.updated` succeeded webhook.
	 *
	 * K4 (`data/t1-provider-family-audit.md` risk K4, §4 Batch 5): the real-event
	 * proof that a pending redirect-method refund becomes successful, deleted
	 * from the browser suite with R4-R7. The first leg runs end to end through
	 * the same production wiring as
	 * {@see self::test_native_refund_over_fake_transport_persists_refund_identity_status_and_one_note},
	 * fed the recorded Afterpay refund response REC-5a R-a returns while
	 * `pending` (`Fixtures/rec-5a-refunds.json`, pair
	 * `afterpay_clearpay_full_refund_pending`); the second leg processes the
	 * exact `charge.refund.updated` succeeded body REC-5a R-c recorded for
	 * that same refund (`Fixtures/rec-5a-refund-updated-event.json`, pair
	 * `afterpay_clearpay_refund_updated_succeeded`) through the real
	 * {@see WooPaymentsRefundEventHandler}. Client parity: the pending and
	 * succeeded legs render distinct note text (`os:2633-2638`) and the
	 * webhook leg's own note-once identity is distinct from the pending
	 * leg's (`wh:355-359`), so the claim is two notes total, not one.
	 */
	public function test_native_refund_over_fake_transport_stays_pending_until_webhook_confirms_succeeded(): void {
		$charge_id = 'py_3UDz8PBzWlxcwgpP1m07cdHy';
		$refund_id = 'pyr_1UJZaGBzWlxcwgpPIeG7xqIJ';
		$reason    = 'requested_by_customer';
		$order     = $this->create_woopayments_order( '100.00' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 100.00,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$recorded              = $this->load_recorded_refund_entry( 'afterpay_clearpay_full_refund_pending' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( true );
		$provider              = $this->create_provider_over_fake_transport( $http_client, $account_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 100.00, $reason ),
			$provider
		);
		$this->assertTrue( $result );

		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( $refund_id, $refund->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'pending', $order->get_meta( '_wcpay_refund_status', true ), 'The synchronous leg must leave the redirect-method refund pending.' );
		$this->assertCount( 1, $this->notes_referencing( $order->get_id(), $refund_id ), 'The pending leg must journal exactly one note.' );

		$recorded_event = $this->load_recorded_refund_updated_event( 'afterpay_clearpay_refund_updated_succeeded' );
		wc_get_container()->get( WooPaymentsRefundEventHandler::class )->process( 'charge.refund.updated', $recorded_event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ), 'The charge.refund.updated succeeded webhook must confirm the refund.' );
		$this->assertCount( 2, $this->notes_referencing( $order->get_id(), $refund_id ), 'The pending note and the webhook-confirmed note must both be journaled.' );
	}

	/**
	 * Order notes referencing a given refund id.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $refund_id Provider refund ID.
	 * @return array<int,object>
	 */
	private function notes_referencing( int $order_id, string $refund_id ): array {
		return array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order_id ) ),
				static fn( $note ): bool => str_contains( $note->content, $refund_id )
			)
		);
	}

	/**
	 * Load a REC-5a R-c recorded `charge.refund.updated` event object by pair key.
	 *
	 * @param string $pair REC-5a R-c fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_refund_updated_event( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-5a-refund-updated-event.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body']['data']['object'];
			}
		}

		$this->fail( "REC-5a R-c fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Capture should normalize legacy capture statuses.
	 */
	public function test_capture_normalizes_legacy_capture_statuses(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array( 'result' => 'success' ),
			true,
			array(
				'status' => 'succeeded',
				'id'     => 'pi_captured',
			)
		);
		$sut     = $this->create_adapter( $gateway );

		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_captured', $outcome->get_provider_payment_id() );
		$this->assertSame( 1, $gateway->capture_calls );
		$this->assertSame( '', $gateway->last_idempotency_key, 'Legacy capture must not send the derived key: the client mints a fresh key per request.' );
		$this->assertContains(
			$outcome->get_data()[ PaymentOutcome::DATA_NOTE ],
			$outcome->get_data()[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]
		);
	}

	/**
	 * @testdox Legacy capture on a live account stores the client's live order mode.
	 *
	 * Plugin 11.1.0 stores `Order_Mode::PRODUCTION` (`prod`), not the account mode `live` (class-order-mode.php:21).
	 */
	public function test_legacy_capture_on_live_account_stores_prod_order_mode(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array( 'result' => 'success' ),
			true,
			array(
				'status'   => 'succeeded',
				'id'       => 'pi_captured_live',
				'currency' => 'usd',
				'charges'  => array( 'data' => array( array( 'id' => 'ch_captured_live' ) ) ),
			)
		);
		$sut     = $this->create_adapter( $gateway, null, null, null, $this->create_account_service( false ) );

		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture_live' );

		$this->assertSame( 'prod', $outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_mode'] );
		// Plugin 11.1.0 capture keeps the authorization's uppercase intent currency (class-wc-payments-api-payment-intention.php:93).
		$this->assertSame( 'USD', $outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_intent_currency'] );
	}

	/**
	 * @testdox Legacy capture failures carry exact note equivalents with the diagnostic.
	 */
	public function test_capture_legacy_failure_carries_note_equivalents(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array( 'result' => 'failure' ),
			true,
			array(
				'status'  => 'requires_capture',
				'id'      => 'pi_capture_failed',
				'message' => 'Provider diagnostic.',
			)
		);
		$sut     = $this->create_adapter( $gateway );

		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture_failed' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertNotEmpty( $outcome->get_data()[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] );
		foreach ( $outcome->get_data()[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] as $note_equivalent ) {
			$this->assertStringEndsWith( ' Provider diagnostic.', $note_equivalent );
		}
	}

	/**
	 * @testdox Capture should prefer the native transport before the legacy gateway bridge.
	 */
	public function test_capture_prefers_native_transport_when_available(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_capture' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture',
				1000,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'     => 'pi_capture',
					'status' => 'succeeded',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 0, $gateway->capture_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * A capture made because the order status changed to completed writes no fee meta, as on the client
	 * (class-wc-payments-order-service.php:1847-1886); other captures do (:1681).
	 *
	 * @dataProvider capture_fee_meta_cases
	 *
	 * @param array<string,mixed> $provider_data   Capture context provider data.
	 * @param bool                $writes_fee_meta Whether the capture plan writes fee meta.
	 */
	public function test_capture_plan_writes_fee_meta_unless_the_status_change_captured( array $provider_data, bool $writes_fee_meta ): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();
		$order->set_transaction_id( 'pi_capture_fee' );
		$order->save();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'capture_intention' )->willReturn(
			array(
				'id'     => 'pi_capture_fee',
				'status' => 'succeeded',
			)
		);

		$outcome = $this->create_adapter( new RecordingLegacyGateway( array( 'result' => 'success' ), true ), $api_client )
			->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, null, $provider_data ), 'key_capture_fee' );

		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( $writes_fee_meta, $plan->writes_fee_meta() );
	}

	/**
	 * Capture contexts and whether their plan writes fee meta.
	 *
	 * @return array<string,array{array<string,mixed>,bool}>
	 */
	public function capture_fee_meta_cases(): array {
		return array(
			'order action or REST capture'          => array( array(), true ),
			'capture on status change to completed' => array( array( WooPaymentsProviderGatewayAdapter::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ), false ),
		);
	}

	/**
	 * @testdox Capture should send the context amount to the native transport.
	 */
	public function test_capture_sends_context_amount_to_native_transport(): void {
		$order      = $this->create_woopayments_order( '10.00' );
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_capture_partial' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture_partial',
				425,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'     => 'pi_capture_partial',
					'status' => 'succeeded',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, 4.25 ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 0, $gateway->capture_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * The client always captures the order total (class-wc-payment-gateway-wcpay.php:3966, 3981) with Level 3 data from the
	 * order (:3984-3986), so it never meets a partial capture. A partial capture is native-only, and the order's line items
	 * do not describe the captured part, so it goes without Level 3 data (native decision, audit L2, N-315).
	 *
	 * @testdox A capture of $_dataName sends Level 3 data only when it captures the order total.
	 * @dataProvider capture_level3_cases
	 *
	 * @param float|null $amount       Capture amount, or null for the order total.
	 * @param bool       $sends_level3 Whether the order's Level 3 data is sent.
	 */
	public function test_capture_sends_level3_only_for_the_order_total( ?float $amount, bool $sends_level3 ): void {
		$order       = $this->create_woopayments_order( '10.00' );
		$level3_data = array(
			'merchant_reference' => (string) $order->get_id(),
			'line_items'         => array( array( 'product_description' => 'Hoodie' ) ),
		);
		$level3      = new class( $level3_data ) extends WooPaymentsLevel3Service {
			/**
			 * Level 3 data for every order.
			 *
			 * @var array<string,mixed>
			 */
			private array $data;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data Level 3 data for every order.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/**
			 * Get Level 3 data for an order.
			 *
			 * @param WC_Order $order Order.
			 * @return array<string,mixed>
			 */
			public function get_data_from_order( WC_Order $order ): array {
				unset( $order );
				return $this->data;
			}
		};
		wc_get_container()->replace( WooPaymentsLevel3Service::class, $level3 );
		$order->set_transaction_id( 'pi_capture_level3' );
		$order->save();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with( 'pi_capture_level3', $this->anything(), $this->anything(), $sends_level3 ? $level3_data : array() )
			->willReturn(
				array(
					'id'     => 'pi_capture_level3',
					'status' => 'succeeded',
				)
			);

		try {
			$this->create_adapter( new RecordingLegacyGateway( array( 'result' => 'success' ), true ), $api_client )
				->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, $amount ), 'key_capture_level3' );
		} finally {
			wc_get_container()->reset_replacement( WooPaymentsLevel3Service::class );
		}
	}

	/**
	 * Capture amounts and whether Level 3 data goes with them.
	 *
	 * @return array<string,array{?float,bool}>
	 */
	public function capture_level3_cases(): array {
		return array(
			'the order total'           => array( null, true ),
			'the order total, explicit' => array( 10.0, true ),
			'part of the order'         => array( 4.25, false ),
		);
	}

	/**
	 * @testdox Native capture should return fee details as a plan without writing order notes.
	 */
	public function test_native_capture_returns_fee_effect_plan_without_writing_notes(): void {
		$order              = $this->create_woopayments_order( '50.00' );
		$gateway            = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client         = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();
		$order_data_service = $this->getMockBuilder( WooPaymentsOrderDataService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_settlement_exchange_rate_order_meta' ) )
			->getMock();

		$order->set_transaction_id( 'pi_capture_notes' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture_notes',
				5000,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'       => 'pi_capture_notes',
					'status'   => 'succeeded',
					'currency' => 'usd',
					'charges'  => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                  => 'ch_capture_notes',
								'currency'            => 'usd',
								'balance_transaction' => array(
									'id' => 'txn_capture_notes',
								),
								'fee_breakdown_v1'    => array(
									'totals' => array(
										'fee' => array(
											'amount'   => 175,
											'currency' => 'usd',
											'rate'     => array(
												'percentage' => 2.9,
												'fixed' => 30,
											),
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
				)
			);
		$order_data_service->expects( $this->never() )
			->method( 'get_settlement_exchange_rate_order_meta' );

		$sut     = $this->create_adapter( $gateway, $api_client, null, null, null, $order_data_service );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CAPTURE, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 'ch_capture_notes', $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['id'] );

		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		$this->assertEmpty(
			array_filter(
				$notes,
				static fn( object $note ): bool => 0 === strpos( (string) $note->content, '<strong>Fee details:</strong>' )
			)
		);
	}

	/**
	 * @testdox Capture should preserve authorized payment metadata on native capture failures.
	 */
	public function test_capture_preserves_authorized_meta_for_native_failure(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_capture' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture',
				1000,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'      => 'pi_capture',
					'status'  => 'requires_capture',
					'message' => 'The authorization could not be captured.',
					'charges' => array(
						'total_count' => 1,
						'data'        => array(
							array( 'id' => 'ch_capture' ),
						),
					),
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_capture', $outcome->get_provider_payment_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $data );
		$this->assertSame( 'The authorization could not be captured.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( 0, $gateway->capture_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * @testdox A failed native cancel re-fetches the intent and treats an already-canceled authorization as canceled.
	 */
	public function test_cancel_exception_self_heals_when_intent_is_already_canceled(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_healed' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->willThrowException( new WooPaymentsApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
		$api_client->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_cancel_healed' )
			->willReturn(
				array(
					'id'      => 'pi_cancel_healed',
					'status'  => 'canceled',
					'charges' => array(
						'data' => array(
							array( 'id' => 'ch_healed' ),
						),
					),
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status(), 'A transport failure on an intent the provider already canceled is a completed cancel, as in the plugin.' );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'cancel', $plan->get_type() );
		$this->assertSame( 'ch_healed', $plan->get_provider_result()['charges']['data'][0]['id'], 'The effect plan must carry the re-fetched intent.' );
	}

	/**
	 * @testdox A failed native cancel stays failed when the re-fetched intent is not canceled.
	 */
	public function test_cancel_exception_stays_failed_when_intent_is_not_canceled(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_live' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->willThrowException( new WooPaymentsApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
		$api_client->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_cancel_live' )
			->willReturn(
				array(
					'id'     => 'pi_cancel_live',
					'status' => 'requires_capture',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'cancel', $plan->get_type() );
		$this->assertSame( 'requires_capture', $plan->get_provider_result()['status'], 'The re-read status rides on the plan so the applier can record it.' );
		$this->assertSame( 0, $gateway->cancel_calls, 'The legacy gateway must not be consulted after a native transport failure.' );
	}

	/**
	 * @testdox A failed native cancel whose re-fetch also fails keeps the plain failed outcome.
	 */
	public function test_cancel_exception_without_refetch_keeps_plain_failure(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_dark' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'cancel_intention' )
			->willThrowException( new WooPaymentsApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
		$api_client->method( 'get_payment_intention' )
			->willThrowException( new WooPaymentsApiException( 'Fetch failed.', 'wcpay_fetch_error', 500 ) );

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertNull( $outcome->get_effect_plan() );
		$this->assertSame( 'Cancel failed.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ], 'The original cancel error stays the actionable one.' );
	}

	/**
	 * @testdox A failed native capture re-fetches the intent and flags an expired authorization.
	 */
	public function test_capture_exception_detects_expired_authorization_via_refetch(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_expired' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->willThrowException( new WooPaymentsApiException( 'Capture failed.', 'wcpay_capture_error', 402 ) );
		$api_client->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_expired' )
			->willReturn(
				array(
					'id'      => 'pi_expired',
					'status'  => 'canceled',
					'charges' => array(
						'total_count' => 1,
						'data'        => array(
							array( 'id' => 'ch_expired' ),
						),
					),
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CAPTURE_EXPIRED, $plan->get_type(), 'Expiry must be declared on the plan at the re-fetch site.' );
		$this->assertSame( 'canceled', $plan->get_provider_result()['status'], 'The effect plan must carry the re-fetched canceled intent so the applier composes the expired effects.' );
	}

	/**
	 * @testdox A failed native capture keeps the plain failure effects when the re-fetch itself fails.
	 */
	public function test_capture_exception_keeps_failure_effects_when_refetch_fails(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_refetch_dead' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'capture_intention' )
			->willThrowException( new WooPaymentsApiException( 'Capture failed.', 'wcpay_capture_error', 402 ) );
		$api_client->method( 'get_payment_intention' )
			->willThrowException( new WooPaymentsApiException( 'Fetch failed.', 'wcpay_fetch_error', 500 ) );

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'failed', $plan->get_provider_result()['status'] );
		$this->assertSame( 'Capture failed.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ], 'The original capture error must survive a failed re-fetch.' );
	}

	/**
	 * @testdox A still-capturable intent on re-fetch keeps the plain failure effects.
	 */
	public function test_capture_exception_keeps_failure_effects_when_intent_is_still_capturable(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_still_live' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'capture_intention' )
			->willThrowException( new WooPaymentsApiException( 'Capture failed.', 'wcpay_capture_error', 402 ) );
		$api_client->method( 'get_payment_intention' )
			->willReturn(
				array(
					'id'     => 'pi_still_live',
					'status' => 'requires_capture',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'failed', $outcome->get_effect_plan()->get_provider_result()['status'] );
	}

	/**
	 * @testdox Native capture defers settlement exchange-rate metadata to its effect plan.
	 */
	public function test_native_capture_defers_settlement_exchange_rate_meta_to_effect_plan(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_woopayments_order( '40.00' );
		$order->set_currency( 'GBP' );
		$order->set_transaction_id( 'pi_capture_converted' );
		$order->save();

		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture_converted',
				4000,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'      => 'pi_capture_converted',
					'status'  => 'succeeded',
					'charges' => array(
						'total_count' => 1,
						'data'        => array(
							array(
								'id'                  => 'ch_capture_converted',
								'currency'            => 'gbp',
								'balance_transaction' => array(
									'id'            => 'txn_capture_converted',
									'exchange_rate' => 1.33127,
								),
								'fee_breakdown_v1'    => array(
									'totals' => array(
										'fee' => array(
											'amount'   => 156,
											'currency' => 'usd',
										),
										'net' => array(
											'amount'   => 5170,
											'currency' => 'usd',
										),
									),
								),
							),
						),
					),
				)
			);

		$sut     = $this->create_adapter(
			$gateway,
			$api_client,
			null,
			null,
			$this->create_account_service(
				true,
				array(),
				array(
					'store_currencies' => array(
						'default' => 'usd',
					),
				)
			)
		);
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertSame( 1.33127, $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['balance_transaction']['exchange_rate'] );
	}

	/**
	 * @testdox Capture should fall back to intent meta when the transaction id is missing.
	 */
	public function test_capture_uses_intent_meta_when_transaction_id_is_missing(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$order->update_meta_data( '_intent_id', 'pi_capture_meta' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				'pi_capture_meta',
				1000,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'     => 'pi_capture_meta',
					'status' => 'succeeded',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 0, $gateway->capture_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * @testdox Cancel should normalize legacy canceled authorizations.
	 */
	public function test_cancel_normalizes_legacy_canceled_authorization(): void {
		$order   = $this->create_woopayments_order();
		$gateway = new RecordingLegacyGateway(
			array( 'result' => 'success' ),
			true,
			array(),
			array(
				'status' => 'canceled',
				'id'     => 'pi_canceled',
			)
		);
		$sut     = $this->create_adapter( $gateway );

		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 'pi_canceled', $outcome->get_provider_payment_id() );
		$this->assertSame( 1, $gateway->cancel_calls );
		$this->assertSame( '', $gateway->last_idempotency_key, 'Legacy cancel must not send the derived key: the client mints a fresh key per request.' );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CANCEL, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 'pi_canceled', $outcome->get_effect_plan()->get_provider_result()['id'] );
	}

	/**
	 * @testdox Cancel should prefer the native transport before the legacy gateway bridge.
	 */
	public function test_cancel_prefers_native_transport_when_available(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->with( 'pi_cancel' )
			->willReturn(
				array(
					'id'      => 'pi_cancel',
					'status'  => 'canceled',
					'charges' => array(
						'data' => array(
							array( 'id' => 'ch_cancel' ),
						),
					),
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 0, $gateway->cancel_calls, 'The legacy gateway must not be consulted.' );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( 'cancel', $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 'ch_cancel', $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['id'] );
	}

	/**
	 * @testdox Failed native cancellation results retain diagnostics without success effects.
	 */
	public function test_failed_native_cancel_retains_plan_without_success_effect_data(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_failed' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->with( 'pi_cancel_failed' )
			->willReturn(
				array(
					'id'      => 'pi_cancel_failed',
					'status'  => 'requires_capture',
					'message' => 'Cancellation rejected.',
				)
			);

		$outcome = $this->create_adapter( $gateway, $api_client )->cancel(
			PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ),
			'key_cancel_failed'
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'Cancellation rejected.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CANCEL, $outcome->get_effect_plan()->get_type() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META_TO_DELETE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertSame( 0, $gateway->cancel_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * @testdox Cancel should fall back to intent meta when the transaction id is missing.
	 */
	public function test_cancel_uses_intent_meta_when_transaction_id_is_missing(): void {
		$order      = $this->create_woopayments_order();
		$gateway    = new RecordingLegacyGateway( array( 'result' => 'success' ), true );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention' ) )
			->getMock();

		$order->update_meta_data( '_intent_id', 'pi_cancel_meta' );
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->with( 'pi_cancel_meta' )
			->willReturn(
				array(
					'id'     => 'pi_cancel_meta',
					'status' => 'canceled',
				)
			);

		$sut     = $this->create_adapter( $gateway, $api_client );
		$outcome = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 0, $gateway->cancel_calls, 'The legacy gateway must not be consulted.' );
	}

	/**
	 * @testdox Operations should fail closed when no legacy gateway is available.
	 */
	public function test_operations_fail_closed_without_gateway(): void {
		$order = $this->create_woopayments_order();
		$sut   = $this->create_adapter( null );

		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'wcpay_gateway_unavailable', $outcome->get_data()['error_code'] );
	}

	/**
	 * @testdox Availability should reflect whether the legacy bridge has a gateway.
	 */
	public function test_availability_reflects_legacy_gateway_presence(): void {
		$this->assertTrue( $this->create_adapter( new RecordingLegacyGateway() )->is_available() );
		$this->assertFalse( $this->create_adapter( null )->is_available() );
	}

	/**
	 * @testdox Availability should preserve the legacy gateway availability check.
	 */
	public function test_availability_reflects_legacy_gateway_availability(): void {
		$gateway            = new RecordingLegacyGateway();
		$gateway->available = false;

		$this->assertFalse( $this->create_adapter( $gateway )->is_available() );
	}

	/**
	 * Ensure a minimal WooCommerce Subscriptions renewal-order detector exists.
	 */
	private function ensure_wcs_order_renewal_detector_double(): void {
		if ( function_exists( 'wcs_order_contains_renewal' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public renewal-order detector.
		eval( 'namespace { function wcs_order_contains_renewal( $order ) { $order_id = is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : absint( $order ); return in_array( $order_id, $GLOBALS["wcpay_test_renewal_order_ids"] ?? array(), true ); } }' );
	}

	/**
	 * Ensure a minimal WooCommerce Subscriptions order detector exists.
	 */
	private function ensure_wcs_order_subscription_detector_double(): void {
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public order detector.
		eval( 'namespace { function wcs_order_contains_subscription( $order, $order_type = array() ) { $order_id = is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : absint( $order ); return in_array( $order_id, $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );
	}

	/**
	 * Charge an order through native transport with a WooPay intent in the checkout context.
	 *
	 * @param WC_Order             $order            Order to charge.
	 * @param WooPaymentsApiClient $api_client       Transport double.
	 * @param string               $woopay_intent_id Intent id WooPay sent.
	 * @return PaymentOutcome
	 */
	private function charge_with_woopay_intent( WC_Order $order, WooPaymentsApiClient $api_client, string $woopay_intent_id ): PaymentOutcome {
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut = $this->create_adapter( new RecordingLegacyGateway( array( 'result' => 'success' ) ), $api_client, $customer_service, null, $this->create_account_service( true ) );

		return $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_platform',
				array(),
				array(
					'is_woopay' => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID => $woopay_intent_id,
				)
			),
			'key_woopay'
		);
	}

	/**
	 * Create a transport double that serves WooPay intents and counts intent creation.
	 *
	 * @param array<string,mixed>          $payment_intent PaymentIntent the read returns.
	 * @param array<string,mixed>          $setup_intent   SetupIntent the read returns.
	 * @param WooPaymentsApiException|null $read_failure   Failure every read throws instead.
	 * @return WooPaymentsApiClient
	 */
	private function create_woopay_intent_api_client( array $payment_intent, array $setup_intent = array(), ?WooPaymentsApiException $read_failure = null ): WooPaymentsApiClient {
		return new class( $payment_intent, $setup_intent, $read_failure ) extends WooPaymentsApiClient {
			/**
			 * PaymentIntent ids read.
			 *
			 * @var string[]
			 */
			public array $payment_intent_reads = array();

			/**
			 * SetupIntent ids read.
			 *
			 * @var string[]
			 */
			public array $setup_intent_reads = array();

			/**
			 * Intent creation count.
			 *
			 * @var int
			 */
			public int $creates = 0;

			/**
			 * PaymentIntent the read returns.
			 *
			 * @var array<string,mixed>
			 */
			private array $payment_intent;

			/**
			 * SetupIntent the read returns.
			 *
			 * @var array<string,mixed>
			 */
			private array $setup_intent;

			/**
			 * Failure every read throws.
			 *
			 * @var WooPaymentsApiException|null
			 */
			private ?WooPaymentsApiException $read_failure;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>          $payment_intent PaymentIntent the read returns.
			 * @param array<string,mixed>          $setup_intent   SetupIntent the read returns.
			 * @param WooPaymentsApiException|null $read_failure   Failure every read throws.
			 */
			public function __construct( array $payment_intent, array $setup_intent, ?WooPaymentsApiException $read_failure ) {
				$this->payment_intent = $payment_intent;
				$this->setup_intent   = $setup_intent;
				$this->read_failure   = $read_failure;
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
			 * Retrieve a payment intention.
			 *
			 * @param string $intent_id PaymentIntent ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When the double is set to fail.
			 */
			public function get_payment_intention( string $intent_id ): array {
				$this->payment_intent_reads[] = $intent_id;
				if ( null !== $this->read_failure ) {
					throw $this->read_failure;
				}

				return $this->payment_intent;
			}

			/**
			 * Retrieve a setup intention.
			 *
			 * @param string $setup_intent_id SetupIntent ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When the double is set to fail.
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				$this->setup_intent_reads[] = $setup_intent_id;
				if ( null !== $this->read_failure ) {
					throw $this->read_failure;
				}

				return $this->setup_intent;
			}

			/**
			 * Count a payment intention creation.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				++$this->creates;

				return array(
					'id'             => 'pi_second',
					'status'         => 'succeeded',
					'payment_method' => 'pm_merchant_clone',
				);
			}

			/**
			 * Count a setup intention creation.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				++$this->creates;

				return array(
					'id'             => 'seti_second',
					'status'         => 'succeeded',
					'payment_method' => 'pm_merchant_clone',
				);
			}
		};
	}

	/**
	 * Create adapter with a fake legacy gateway.
	 *
	 * @param RecordingLegacyGateway|null      $gateway Legacy gateway.
	 * @param WooPaymentsApiClient|null        $api_client Native API client.
	 * @param WooPaymentsCustomerService|null  $customer_service WooPayments customer service.
	 * @param WooPaymentsTokenService|null     $token_service WooPayments token service.
	 * @param WooPaymentsAccountService|null   $account_service WooPayments account service.
	 * @param WooPaymentsOrderDataService|null $order_data_service WooPayments order data service.
	 * @param WooPaymentsSettingsService|null  $settings_service Settings service.
	 * @return WooPaymentsProviderGatewayAdapter
	 */
	private function create_adapter( ?RecordingLegacyGateway $gateway, ?WooPaymentsApiClient $api_client = null, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsAccountService $account_service = null, ?WooPaymentsOrderDataService $order_data_service = null, ?WooPaymentsSettingsService $settings_service = null ): WooPaymentsProviderGatewayAdapter {
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyProxyWithGateway( $gateway ) );
		$api_client = $api_client ?? $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		if ( $api_client instanceof \PHPUnit\Framework\MockObject\MockObject ) {
			$api_client->method( 'is_available' )->willReturn( false );
		}
		$customer_service   = $customer_service ?? $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->getMock();
		$token_service      = $token_service ?? $this->create_token_service();
		$account_service    = $account_service ?? $this->create_account_service( false );
		$order_data_service = $order_data_service ?? new WooPaymentsOrderDataService();
		$request_builder    = new WooPaymentsIntentRequestBuilder();
		$request_builder->init( $account_service, $order_data_service, $token_service, new WooPaymentsPaymentMethodRegistry() );

		if ( null === $settings_service ) {
			$settings_service = $this->getMockBuilder( WooPaymentsSettingsService::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'is_fraud_rule_active' ) )
				->getMock();
			$settings_service->method( 'is_fraud_rule_active' )->willReturnCallback(
				static function ( string $rule_key ): bool {
					$ruleset = get_transient( 'wcpay_fraud_protection_settings' );
					if ( ! is_array( $ruleset ) ) {
						return false;
					}

					foreach ( $ruleset as $rule ) {
						if ( is_array( $rule ) && ( $rule['key'] ?? null ) === $rule_key ) {
							return true;
						}
					}

					return false;
				}
			);
		}

		$ambiguity_service = new WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client );

		$sut = new WooPaymentsProviderGatewayAdapter();
		$sut->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			$request_builder,
			$account_service,
			$order_data_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			$settings_service,
			$ambiguity_service
		);

		return $sut;
	}

	/**
	 * Create a real customer service backed by the recording native API client.
	 *
	 * @param WooPaymentsApiClient $api_client Recording native API client.
	 * @return WooPaymentsCustomerService
	 */
	private function create_real_customer_service( WooPaymentsApiClient $api_client ): WooPaymentsCustomerService {
		$service = new WooPaymentsCustomerService();
		$service->init( $api_client, $this->create_account_service( false ), new WooPaymentsSessionService(), new StaticNativeRuntimeArbiter( true ) );

		return $service;
	}

	/**
	 * Create a native API client that records customer and intent operations.
	 *
	 * @param bool $missing_customer_on_first_intent Whether the first intent should report a missing customer.
	 * @return WooPaymentsApiClient
	 */
	private function create_recording_customer_intent_api_client( bool $missing_customer_on_first_intent = false ): WooPaymentsApiClient {
		return new class( $missing_customer_on_first_intent ) extends WooPaymentsApiClient {
			/** @var array<int,array{customer_id:string,customer_data:array<string,mixed>}> */
			public array $updated_customers = array();

			/** @var array<int,array<string,mixed>> */
			public array $created_customers = array();

			/** @var array<int,array{type:string,request_data:array<string,mixed>,idempotency_key:string}> */
			public array $intent_requests = array();

			/** @var bool */
			private bool $missing_customer_on_first_intent;

			/**
			 * @param bool $missing_customer_on_first_intent Whether the first intent should report a missing customer.
			 */
			public function __construct( bool $missing_customer_on_first_intent ) {
				$this->missing_customer_on_first_intent = $missing_customer_on_first_intent;
			}

			/**
			 * Tell whether native transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Record a customer update.
			 *
			 * @param string              $customer_id Customer ID.
			 * @param array<string,mixed> $customer_data Customer data.
			 */
			public function update_customer( string $customer_id, array $customer_data = array() ): void {
				$this->updated_customers[] = array(
					'customer_id'   => $customer_id,
					'customer_data' => $customer_data,
				);
			}

			/**
			 * Record a customer creation.
			 *
			 * @param array<string,mixed> $customer_data Customer data.
			 * @return string
			 */
			public function create_customer( array $customer_data ): string {
				$this->created_customers[] = $customer_data;

				return 'cus_created';
			}

			/**
			 * Record a PaymentIntent attempt.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->intent_requests[] = array(
					'type'            => 'payment',
					'request_data'    => $request_data,
					'idempotency_key' => $idempotency_key,
				);
				if ( $this->missing_customer_on_first_intent && 1 === count( $this->intent_requests ) ) {
					throw new WooPaymentsApiException( 'No such customer.', 'resource_missing', 404 );
				}

				return array(
					'id'             => 'pi_change',
					'status'         => 'succeeded',
					'customer'       => $request_data['customer'],
					'payment_method' => 'pm_change',
					'currency'       => 'usd',
					'charges'        => array(
						'total_count' => 0,
						'data'        => array(),
					),
				);
			}

			/**
			 * Record a SetupIntent attempt.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				$this->intent_requests[] = array(
					'type'            => 'setup',
					'request_data'    => $request_data,
					'idempotency_key' => $idempotency_key,
				);
				if ( $this->missing_customer_on_first_intent && 1 === count( $this->intent_requests ) ) {
					throw new WooPaymentsApiException( 'No such customer.', 'resource_missing', 404 );
				}

				return array(
					'id'             => 'seti_change',
					'status'         => 'succeeded',
					'customer'       => $request_data['customer'],
					'payment_method' => 'pm_change',
				);
			}
		};
	}

	/**
	 * Create a WooPayments account service mock.
	 *
	 * @param bool                $test_mode    Whether WooPayments should run in test mode.
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, array $settings = array(), array $account_data = array() ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'get_cached_account_data', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback
		);
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array_merge(
				array(
					'country'          => 'US',
					'payments_enabled' => true,
					'capabilities'     => array(
						'amazon_pay_payments' => 'active',
					),
					'fees'             => array(
						'amazon_pay' => array(
							'base' => array(
								'currency' => 'usd',
							),
						),
					),
				),
				$account_data
			)
		);
		$store_currencies = is_array( $account_data['store_currencies'] ?? null ) ? $account_data['store_currencies'] : array();
		$account_service->method( 'get_account_default_currency' )->willReturn( (string) ( $store_currencies['default'] ?? 'usd' ) );
		$account_service->method( 'get_account_country' )->willReturn( strtoupper( (string) ( $account_data['country'] ?? 'US' ) ) );

		return $account_service;
	}

	/**
	 * Create a token service with fake payment method details.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service( array $payment_method_details = array() ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details ) extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Payment method details keyed by ID.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $payment_method_details;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
			 */
			public function __construct( array $payment_method_details ) {
				$this->payment_method_details = $payment_method_details;
			}

			/**
			 * Get payment method details.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				return $this->payment_method_details[ $payment_method_id ] ?? array();
			}
		};

		$token_service = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticNativeRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );

		return $token_service;
	}

	/**
	 * Create a token service that permits one saved-credential resolution before transport.
	 *
	 * @param WC_Payment_Token_CC $token             Saved WooCommerce token.
	 * @param int                 $user_id           Expected token owner.
	 * @param string              $payment_method_id Provider payment method ID.
	 * @return WooPaymentsTokenService
	 */
	private function create_single_resolution_token_service( WC_Payment_Token_CC $token, int $user_id, string $payment_method_id ): WooPaymentsTokenService {
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'resolve_payment_method_type_from_token_id', 'resolve_payment_method_id_from_token_id' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'resolve_payment_method_type_from_token_id' )
			->with( (string) $token->get_id(), $user_id )
			->willReturn( 'card' );
		$token_service->expects( $this->once() )
			->method( 'resolve_payment_method_id_from_token_id' )
			->with( (string) $token->get_id(), $user_id )
			->willReturn( $payment_method_id );

		return $token_service;
	}

	/**
	 * Create a token service that permits one order-scoped saved-credential resolution.
	 *
	 * @param WC_Payment_Token $token               Saved WooCommerce token.
	 * @param WC_Order         $order               Renewal order that owns the token.
	 * @param string           $payment_method_id   Provider payment method ID.
	 * @param string           $payment_method_type Provider payment method type.
	 * @return WooPaymentsTokenService
	 */
	private function create_single_order_resolution_token_service( WC_Payment_Token $token, WC_Order $order, string $payment_method_id, string $payment_method_type ): WooPaymentsTokenService {
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'resolve_payment_method_type_from_order_token_id', 'resolve_payment_method_id_from_order_token_id' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'resolve_payment_method_type_from_order_token_id' )
			->with( (string) $token->get_id(), $this->identicalTo( $order ) )
			->willReturn( $payment_method_type );
		$token_service->expects( $this->once() )
			->method( 'resolve_payment_method_id_from_order_token_id' )
			->with( (string) $token->get_id(), $this->identicalTo( $order ) )
			->willReturn( $payment_method_id );

		return $token_service;
	}

	/**
	 * Create a persisted WooPayments card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Create a persisted WooPayments Link token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WooPaymentsLinkToken
	 */
	private function create_link_token( int $user_id, string $payment_method_id ): WooPaymentsLinkToken {
		$token = new WooPaymentsLinkToken();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_email( 'buyer@example.com' );
		$token->save();

		return $token;
	}

	/**
	 * Create a persisted WooPayments SEPA token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WooPaymentsSepaToken
	 */
	private function create_sepa_token( int $user_id, string $payment_method_id ): WooPaymentsSepaToken {
		$token = new WooPaymentsSepaToken();
		$token->set_gateway_id( 'woocommerce_payments_sepa_debit' );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_last4( '6789' );
		$token->save();

		return $token;
	}

	/**
	 * Register the native WooPayments token class map for token-loading tests.
	 */
	private function register_token_class_map(): void {
		$controller = new WooPaymentsTokenClassMapController();
		$controller->init( new StaticNativeRuntimeArbiter( true ) );
		$controller->register();
	}

	/**
	 * Assert that an order has a note with the expected exact content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assertOrderHasNote( WC_Order $order, string $expected ): void {
		$count = 0;
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( $expected === $note->content ) {
				++$count;
			}
		}

		$this->assertGreaterThan( 0, $count, "Missing order note: {$expected}" );
	}

	/**
	 * Assert that an order does not have a note with the given prefix.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $prefix Note prefix.
	 */
	private function assertOrderDoesNotHaveNoteStartingWith( WC_Order $order, string $prefix ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			$this->assertStringStartsNotWith( $prefix, (string) $note->content );
		}
	}

	/**
	 * Create a WooPayments order for adapter tests.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total = '10.00' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
