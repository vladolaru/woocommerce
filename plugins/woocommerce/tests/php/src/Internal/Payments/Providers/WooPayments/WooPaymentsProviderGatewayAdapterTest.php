<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
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
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			$outcome->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null,
			'A blocked shopper must not be told which field tripped the block.'
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
	 * @testdox Charge should recreate and retry when the native transport reports a missing customer.
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
	 * @testdox Validated subscription changes recreate a missing remote customer and retry the same native intent idempotently.
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
		$this->assertSame( 'key_recovery_real', $api_client->intent_requests[1]['idempotency_key'] );
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
		$account_service = $this->create_account_service(
			false,
			array(
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
		$account_service = $this->create_account_service(
			false,
			array(
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
		$account_service = $this->create_account_service(
			false,
			array(
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
	 * @testdox Charge should resolve saved WooCommerce token IDs before native transport.
	 */
	public function test_charge_resolves_saved_payment_token_before_native_transport(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_card_token( $user_id, 'pm_saved' );
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
				if ( 'pm_saved' !== $request_data['payment_method'] || 'key_charge' !== $idempotency_key ) {
					throw new \RuntimeException( 'Saved token was not resolved before the native charge request.' );
				}

				return array(
					'id'             => 'pi_saved',
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

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
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
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pm_saved', $outcome->get_payment_method_id() );
		$this->assertContains( $saved_token->get_id(), $order->get_payment_tokens(), 'Existing saved tokens should be linked to the paid order.' );
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
					'email' => 'subscription-buyer@example.com',
					'name'  => 'Subscription Buyer',
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
	 * @testdox Zero-total scheduled Link renewals should omit online mandate acceptance at transport.
	 */
	public function test_zero_total_scheduled_link_renewal_omits_online_mandate_at_transport(): void {
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

			$this->assertSame( 'key_zero_renewal', $api_client->last_idempotency_key );
			$this->assertSame( 'cus_native', $api_client->last_request_data['customer'] );
			$this->assertSame( 'pm_link', $api_client->last_request_data['payment_method'] );
			$this->assertSame( array( 'card', 'link' ), $api_client->last_request_data['payment_method_types'] );
			$this->assertArrayNotHasKey( 'mandate_data', $api_client->last_request_data );
			$this->assertInstanceOf( WooPaymentsPaymentType::class, $api_client->last_request_data['metadata']['payment_type'] );
			$this->assertSame( 'recurring', (string) $api_client->last_request_data['metadata']['payment_type'] );
			$this->assertSame( 'renewal', $api_client->last_request_data['metadata']['subscription_payment'] );
			$this->assertSame( 'regular_subscription', $api_client->last_request_data['metadata']['payment_context'] );
			$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
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
		$this->assertCount( 2, $expected_note_candidates );
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
	 */
	public function test_native_charge_returns_display_effect_plan_without_mutating_order(): void {
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
					'id'             => 'pi_action',
					'status'         => 'requires_action',
					'client_secret'  => 'secret_action',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_native',
					'currency'       => 'usd',
					'charges'        => array(
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

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service );
		$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( 'ch_native', $outcome->get_data()['charge_id'] ?? null );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertStringStartsWith( '#wcpay-confirm-pi:' . $order->get_id() . ':secret_action:', $outcome->get_redirect_url() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( '', $order->get_payment_method_title() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 0, $gateway->processed_order_id );
	}

	/**
	 * @testdox Charge should prefer native SetupIntent transport for zero-total card checkout.
	 */
	public function test_charge_prefers_native_setup_intent_for_zero_total_checkout(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_card_token( $user_id, 'pm_zero' );
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
				if ( 'cus_native' !== $request_data['customer']
					|| 'pm_zero' !== $request_data['payment_method']
					|| array( 'card' ) !== $request_data['payment_method_types']
					|| 'key_setup' !== $idempotency_key ) {
					throw new \RuntimeException( 'Unexpected native setup intent request payload.' );
				}

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
		$this->assertSame( 'seti_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_native', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_native', $outcome->get_customer_id() );
		$this->assertSame( 0, $gateway->processed_order_id );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( '', $order->get_meta( '_payment_method_id', true ) );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 'live', $outcome->get_effect_plan()->get_setup_meta()['_wcpay_mode'] );
	}

	/**
	 * @testdox Zero-total checkout requiring SetupIntent customer action returns the `si` confirmation redirect, not `pi`.
	 *
	 * Free-trial subscription signup and other zero-total checkouts create a native SetupIntent
	 * (`setup_intent_via_native_transport`), not a PaymentIntent. When that SetupIntent comes back
	 * `requires_action` (a 3DS challenge on the saved card), the frontend confirmation hash must read
	 * `#wcpay-confirm-si:...`, matching client 11.1.0's own `$payment_needed ? 'pi' : 'si'` branch
	 * (`gw:2111`, `class-wc-payment-gateway-wcpay.php`): a SetupIntent has no payment to confirm, so the
	 * frontend must call `stripe.confirmSetup()`, not `confirmPayment()`. Only the codec unit test
	 * (`WooPaymentsIntentCodecTest::test_confirmation_redirect_uses_explicit_nonce`) and the effect-plan
	 * test (`WooPaymentsOrderEffectApplierTest::test_setup_intent_effects_persist_provider_references`)
	 * covered pieces of this before; neither goes through the adapter's own intent-type wiring.
	 */
	public function test_zero_total_setup_intent_requiring_action_returns_si_confirmation_redirect(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
		$saved_token      = $this->create_card_token( $user_id, 'pm_zero_action' );
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
					'id'            => 'seti_zero_action',
					'status'        => 'requires_action',
					'client_secret' => 'seti_zero_action_secret',
					'customer'      => 'cus_zero_action',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $saved_token );
		$order->save();
		$token_service = $this->create_single_resolution_token_service( $saved_token, $user_id, 'pm_zero_action' );

		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_zero_action' );

		$sut     = $this->create_adapter( $gateway, $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $saved_token->get_id() )
			),
			'key_setup_action'
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertStringStartsWith( '#wcpay-confirm-si:' . $order->get_id() . ':seti_zero_action_secret:', $outcome->get_redirect_url() );
		$this->assertStringNotContainsString( '#wcpay-confirm-pi:', $outcome->get_redirect_url() );
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
				array(),
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
		$this->assertSame( 'key_capture', $gateway->last_idempotency_key );
		$this->assertContains(
			$outcome->get_data()[ PaymentOutcome::DATA_NOTE ],
			$outcome->get_data()[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]
		);
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key, 'The legacy gateway must not be consulted after a native transport failure.' );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( 'key_cancel', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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
		$this->assertSame( '', $gateway->last_idempotency_key );
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

		$sut = new WooPaymentsProviderGatewayAdapter();
		$sut->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			$request_builder,
			$account_service,
			$order_data_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			$settings_service
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
		$token_service->init( $details_service, new StaticNativeRuntimeArbiter( true ) );

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
