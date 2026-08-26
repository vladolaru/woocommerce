<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_Order;
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
						'user_agent' => 'WooCommerce Payments/10.8.0; ' . get_bloginfo( 'url' ),
					),
				),
			),
			$api_client->last_request_data['mandate_data'] ?? null
		);
	}

	/**
	 * @testdox Charge should send an order return URL for split redirect gateway methods.
	 */
	public function test_charge_sends_return_url_for_split_redirect_gateway_methods(): void {
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
					'id'             => 'pi_ideal',
					'status'         => 'requires_action',
					'client_secret'  => 'secret_ideal',
					'customer'       => 'cus_native',
					'payment_method' => 'pm_ideal',
					'currency'       => 'eur',
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

		$sut = $this->create_adapter( $gateway, $api_client, $customer_service );
		$sut->charge(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID_PREFIX . 'ideal',
				'pm_ideal'
			),
			'key_charge'
		);

		$this->assertSame( array( 'ideal' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertArrayHasKey( 'return_url', $api_client->last_request_data );
		$this->assertStringStartsWith( $order->get_checkout_order_received_url(), $api_client->last_request_data['return_url'] );

		$query_args = array();
		parse_str( (string) wp_parse_url( (string) $api_client->last_request_data['return_url'], PHP_URL_QUERY ), $query_args );

		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $query_args['wc_payment_method'] ?? '' );
		$this->assertSame( 1, wp_verify_nonce( $query_args['_wpnonce'] ?? '', 'wcpay_process_redirect_order_nonce' ) );
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
		$this->assertNull( $outcome->get_effect_plan() );
		$this->assertSame( '', $gateway->last_idempotency_key, 'The legacy gateway must not be consulted after a native transport failure.' );
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
