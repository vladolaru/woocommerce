<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsPaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingProvider;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;
use WP_Error;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\RecordingWooPaySessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\RecordingWooPayAppearanceStore;

/**
 * Tests for the WooPaymentsPaymentOperationService class.
 */
class WooPaymentsPaymentOperationServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Charge refunded in `Fixtures/platform-refund-list.json`.
	 */
	private const F458_CHARGE = 'ch_3UOv4vBzWlxcwgpP0ALlMGAw';

	/**
	 * Key, and marker, of the recorded 5.55 refund.
	 */
	private const F458_KEY_555 = '8dfc0ff3-4161-4bde-97c7-2e909facf280';

	/**
	 * Key, and marker, of the recorded 11.11 refund.
	 */
	private const F458_KEY_1111 = '366fa04f-cf23-4be7-bab2-e622ae2696a5';

	/**
	 * The recorded 5.55 refund.
	 */
	private const F458_REFUND_555 = 're_3UOv4vBzWlxcwgpP0Mxsx0Nk';

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Whether this test put a runtime refund row capture in the container.
	 *
	 * @var bool|null
	 */
	private ?bool $runtime_capture_installed = null;

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
		remove_all_filters( 'woocommerce_payment_token_class' );
		remove_all_filters( 'wcpay_metadata_from_order' );
		delete_option( 'woocommerce_tax_based_on' );
		delete_option( 'woocommerce_calc_taxes' );
		update_option( 'woocommerce_currency', $this->original_currency );
		$this->reset_container_replacements();
		unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS['wcpay_test_subscription_ids'], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		// Guest customer creation stores the customer in the shared session; leave none for later classes.
		if ( WC()->session ) {
			WC()->session->set( 'wcpay_customer_id', null );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Charge should send a positive-amount payment through the platform API.
	 */
	public function test_charge_sends_a_positive_amount_payment_through_the_platform_api(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$api_client       = new class() extends Client {
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

				// Shape: the platform's create-and-confirm answer in Fixtures/platform-intention-basic-card.json (pair
				// basic_card_usd_create_and_confirm: captured, fee 62, no fee_breakdown_v1), trimmed to the fields read here.
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
								'application_fee_amount' => 62,
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

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_native', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_native', $outcome->get_customer_id() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_payment_method_title() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertArrayNotHasKey( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertOrderDoesNotHaveNoteStartingWith( $order, '<strong>Fee details:</strong>' );
	}

	/**
	 * @testdox A 0.50 total checked against a cached platform minimum of $minimum cents gives $_dataName.
	 *
	 * @dataProvider provider_cached_platform_minimums
	 *
	 * @param int         $minimum         Cached platform minimum in minor units.
	 * @param string      $status          Expected outcome status.
	 * @param string|null $error_code      Expected error code, or null for none.
	 * @param string|null $shopper_message Expected shopper message, or null for none.
	 * @param int         $calls           Expected customer lookups and intent requests.
	 */
	public function test_charge_checks_the_total_against_the_cached_platform_minimum( int $minimum, string $status, ?string $error_code, ?string $shopper_message, int $calls ): void {
		delete_transient( 'wcpay_minimum_amount_usd' );
		set_transient( 'wcpay_minimum_amount_usd', $minimum, DAY_IN_SECONDS );

		$order            = $this->create_woopayments_order( '0.50' );
		$api_client       = $this->create_answering_charge_api_client(
			array(
				'id'     => 'pi_min_ok',
				'status' => 'succeeded',
			)
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->exactly( $calls ) )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();

		$this->assertSame( $status, $outcome->get_status() );
		$this->assertSame( $error_code, $data[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( $shopper_message, $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
		$this->assertCount( $calls, $api_client->requests, 'A sub-minimum charge must not reach the platform.' );

		delete_transient( 'wcpay_minimum_amount_usd' );
	}

	/**
	 * Cached minimums above and equal to the 0.50 total. The answer is the recorded basic_card_usd_create_and_confirm
	 * (Fixtures/platform-intention-basic-card.json) reduced to its ID and status.
	 *
	 * @return array<string,array{0:int,1:string,2:string|null,3:string|null,4:int}>
	 */
	public function provider_cached_platform_minimums(): array {
		return array(
			'a failure before the API call' => array( 100, PaymentOutcome::STATUS_FAILED, 'amount_too_small', 'The selected payment method requires a total amount of at least $1.00.', 0 ),
			'one cent below the minimum'    => array( 51, PaymentOutcome::STATUS_FAILED, 'amount_too_small', 'The selected payment method requires a total amount of at least $0.51.', 0 ),
			'a charge that reaches the API' => array( 50, PaymentOutcome::STATUS_COMPLETED, null, null, 1 ),
		);
	}

	/**
	 * @testdox Charge should reuse a persisted key after an ambiguous transport failure.
	 */
	public function test_charge_reuses_persisted_key_after_ambiguous_transport_failure(): void {
		$order            = $this->create_woopayments_order();
		$order_id         = $order->get_id();
		$api_client       = new class( $order_id ) extends Client {
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
				if ( ! $fresh_order instanceof WC_Order || $idempotency_key !== $fresh_order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) ) {
					throw new \RuntimeException( 'Charge key was not persisted before dispatch.' );
				}
				if ( 1 === count( $this->keys ) ) {
					throw new ApiException( 'Transport failed.', 'http_request_failed' );
				}
				return array(
					'id'     => 'pi_reused',
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_reused' );
		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );

		$ambiguous_outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_reused' ), 'key_a' );
		$sut->finalize_charge_idempotency_key( $order, $ambiguous_outcome );
		$sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_reused' ), 'key_b' );

		$this->assertSame( array( 'key_a', 'key_a' ), $api_client->keys );
	}

	/**
	 * @testdox Charge should retire its key on a definitive failure even when applying the outcome later fails.
	 *
	 * The post-lifecycle retirement never runs when applying the outcome throws (PaymentProcessingService rethrows before
	 * it), so a decline must retire the key as it is classified; otherwise the next attempt sends the old key and gets the
	 * stored decline back (area 2a #18). No finalize_charge_idempotency_key() call here models the failed apply.
	 */
	public function test_charge_retires_key_on_definitive_failure_without_lifecycle(): void {
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends Client {
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
					throw new ApiException( 'Declined.', 'card_declined', 402, 'card_error' );
				}
				return array(
					'id'     => 'pi_after_decline',
					'status' => 'succeeded',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_after_decline' );
		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );

		$sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_after_decline' ), 'key_declined' );
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( '', $fresh_order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );

		$sut->charge( PaymentOperationContext::for_checkout( $fresh_order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_after_decline' ), 'key_next' );

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
	public function test_charge_warns_when_unresolved_key_replay_is_refused( string $key_source, bool $warns ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order = $this->create_woopayments_order();
		if ( 'a kept key' === $key_source ) {
			$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_kept' );
			$order->save_meta_data();
		}
		$http_client           = new FakeHttpClient();
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
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_conflict' );
		$sut    = $this->create_operation_service( $api_client, $customer_service, null, $account_service );
		$logger = RecordingWcLogger::install();

		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new_card' ), 'key_fresh' );

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
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
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
	public function test_charge_keeps_its_key_when_the_failure_may_have_charged( string $failure ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
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
		$api_client             = new class() extends Client {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_kept_key' );
		$sut = $this->create_operation_service( $api_client, $customer_service, null, $account_service );

		$failed = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_kept_key' ), 'key_first' );
		$sut->finalize_charge_idempotency_key( $order, $failed );
		$attempts = $http_client->request_count;
		$sut->charge( PaymentOperationContext::for_checkout( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_kept_key' ), 'key_resubmit' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $failed->get_status() );
		$this->assertArrayNotHasKey( '_wcpay_definitive_charge_failure', $failed->get_data() );
		$this->assertSame( count( $first ), $attempts );
		$this->assertSame( array( 'key_first' ), array_values( array_unique( array_map( static fn( array $request ): string => $request['headers']['Idempotency-Key'] ?? '', $http_client->requests ) ) ), 'Every request, the resubmit included, must carry the kept key.' );
	}

	/**
	 * @testdox Charge should use the caller key for a different order.
	 */
	public function test_charge_uses_a_different_order_key(): void {
		$first_order      = $this->create_woopayments_order();
		$second_order     = $this->create_woopayments_order();
		$api_client       = new class() extends Client {
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
		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );

		$sut->charge( PaymentOperationContext::for_checkout( $first_order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_different_order' ), 'key_a' );
		$sut->charge( PaymentOperationContext::for_checkout( $second_order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_different_order' ), 'key_c' );

		$this->assertSame( array( 'key_a', 'key_c' ), $api_client->keys );
	}

	/**
	 * @testdox A failure outside the intent dispatch ($_dataName) keeps the ambiguous charge key.
	 *
	 * @dataProvider provider_failures_outside_the_intent_dispatch
	 *
	 * @param string   $total          Order total.
	 * @param int|null $cached_minimum Cached platform minimum in minor units, or null for none.
	 * @param int      $customer_calls Expected customer lookups.
	 */
	public function test_failure_outside_the_intent_dispatch_keeps_the_ambiguous_charge_key( string $total, ?int $cached_minimum, int $customer_calls ): void {
		if ( null !== $cached_minimum ) {
			set_transient( 'wcpay_minimum_amount_usd', $cached_minimum, DAY_IN_SECONDS );
		}
		$order = $this->create_woopayments_order( $total );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_ambiguous_charge' );
		$order->save_meta_data();
		$api_client       = new class() extends Client {
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
			 * @throws ApiException Always.
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				throw new ApiException( 'SetupIntent declined.', 'card_declined', 402, 'card_error' );
			}

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
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->expects( $this->exactly( $customer_calls ) )->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_outside_dispatch' );
		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );

		try {
			$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_outside_dispatch' ), 'key_attempt' );
			$sut->finalize_charge_idempotency_key( $order, $outcome );
			$fresh_order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $fresh_order );
			$this->assertSame( 'key_ambiguous_charge', $fresh_order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		} finally {
			delete_transient( 'wcpay_minimum_amount_usd' );
		}
	}

	/**
	 * Failures before or beside the PaymentIntent dispatch: a declined SetupIntent on a zero total, and a total below the
	 * cached platform minimum, refused before the customer lookup.
	 *
	 * @return array<string,array{0:string,1:int|null,2:int}>
	 */
	public function provider_failures_outside_the_intent_dispatch(): array {
		return array(
			'setup intent declined'    => array( '0.00', null, 1 ),
			'below the cached minimum' => array( '0.50', 100, 0 ),
		);
	}

	/**
	 * @testdox An ambiguous charge failure records the order, the customer it was sent with and the time beside the unresolved key; a definitive one records nothing.
	 *
	 * The record is what tells a later refused resubmit to look up what this request did. A 502 is the platform's own
	 * answer when its Stripe call failed (wpcom `wcpay/core/exceptions/class-platform-failure-exception.php:30`), so the
	 * charge may have gone through.
	 */
	public function test_ambiguous_charge_failure_records_the_ambiguity_beside_the_unresolved_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::stripe_card_declined() );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );

		$before = time();
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$fresh  = wc_get_order( $order->get_id() );
		$record = $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertIsArray( $record );
		$this->assertSame( $order->get_id(), $record['order_id'] );
		$this->assertSame( array( 'cus_sent' ), $record['customers'] );
		$this->assertGreaterThanOrEqual( $before, $record['failed_at'] );

		$other = $this->create_woopayments_order();
		$this->charge_attempt( $sut, $other, 'pm_declined', 'key_declined' );
		$other = wc_get_order( $other->get_id() );

		$this->assertSame( '', $other->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ), 'A definitive failure must leave no record.' );
		$this->assertSame( '', $other->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox A later ambiguous answer under the unresolved key merges into the record: every customer sent, the earliest failure time and the note flag stay.
	 *
	 * Replacing the record would point the lookup at the later customer and move the account window past the earlier
	 * intent (review 45 F1).
	 */
	public function test_later_ambiguous_answer_merges_into_the_record(): void {
		$order    = $this->create_woopayments_order();
		$earliest = time() - 1000;
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_first' );
		$order->update_meta_data(
			WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META,
			array(
				'order_id'           => $order->get_id(),
				'customers'          => array( 'cus_a' ),
				'failed_at'          => $earliest,
				'last_failed_at'     => $earliest,
				'cannot_check_noted' => true,
			)
		);
		$order->save_meta_data();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::platform_bad_gateway() );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_b' );
		$before                 = time();

		$this->charge_attempt( $sut, $order, 'pm_new', 'key_second' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_other', 'key_third' );
		$record = wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$this->assertIsArray( $record );
		$this->assertSame( $order->get_id(), $record['order_id'] ?? null );
		$this->assertSame( array( 'cus_a', 'cus_b' ), $record['customers'] ?? null, 'Every customer sent under the key, each once.' );
		$this->assertSame( $earliest, $record['failed_at'] ?? null, 'The earliest failure time stays.' );
		$this->assertGreaterThanOrEqual( $before, $record['last_failed_at'] ?? 0, 'The latest failure time moves on.' );
		$this->assertTrue( $record['cannot_check_noted'] ?? false, 'The merchant was already told; the flag stays.' );
	}

	/**
	 * @testdox A later ambiguous answer never moves the latest failure time backwards, even when the store clock stepped back.
	 *
	 * The latest failure time ends the account lookup window; lowering it could leave out the intent of the request it
	 * recorded (review 49 F7).
	 */
	public function test_later_ambiguous_answer_keeps_a_later_recorded_failure_time(): void {
		$order          = $this->create_woopayments_order();
		$last_failed_at = time() + 5000;
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_first' );
		$order->update_meta_data(
			WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META,
			array(
				'order_id'           => $order->get_id(),
				'customers'          => array( 'cus_a' ),
				'failed_at'          => time() - 1000,
				'last_failed_at'     => $last_failed_at,
				'cannot_check_noted' => false,
			)
		);
		$order->save_meta_data();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway() );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_a' );

		$this->charge_attempt( $sut, $order, 'pm_new', 'key_second' );
		$record = wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( array( 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$this->assertSame( $last_failed_at, $record['last_failed_at'] ?? null );
	}

	/**
	 * @testdox When the unresolved key was sent with more than one customer, the lookup reads the account's intents from the first failure, so the earlier payment pays the order and the new card is not charged.
	 *
	 * A customer missing at Stripe is recreated when a charge is refused for it (send_charge_request()), so a resubmit
	 * under the kept key can go out with a new customer and fail ambiguously again. The earlier request's
	 * intent belongs to the first customer, so the new customer's complete list proves nothing about it (review 45 F1).
	 * The first failure is recorded well before the lookup, so the window must start from the record, not the clock
	 * (review 47 F1). The second request may be the one Stripe ran under the key, so the window ends after its failure
	 * (review 47 F2).
	 */
	public function test_unresolved_key_sent_with_two_customers_looks_up_the_account_list_from_the_first_failure(): void {
		$order     = $this->create_woopayments_order();
		$failed_at = time() - 1000;
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_first' );
		$order->update_meta_data(
			WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META,
			array(
				'order_id'       => $order->get_id(),
				'customers'      => array( 'cus_a' ),
				'failed_at'      => $failed_at,
				'last_failed_at' => $failed_at,
			)
		);
		$order->save_meta_data();
		$http_client            = self::create_routed_http_client(
			array(
				'customer=cus_b'   => self::intent_list( array() ),
				'created%5Bgte%5D' => self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 1000, array( 'customer' => 'cus_a' ) ) ) ),
			)
		);
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_b' );
		$before                 = time();
		$this->charge_attempt( $sut, $order, 'pm_second', 'key_second' );
		$last_failed_at = (int) ( wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['last_failed_at'] ?? 0 );
		$this->assertGreaterThanOrEqual( $before, $last_failed_at, 'The second failure is the latest.' );
		$this->assertLessThanOrEqual( time(), $last_failed_at );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );

		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id(), 'The earlier payment pays the order.' );
		$this->assertSame( 'cus_a', $outcome->get_customer_id() );
		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', self::account_list_trail( $failed_at, $last_failed_at ) ),
			self::request_trail( $http_client ),
			'The account list runs from the first recorded failure to the latest, and the new card is not charged.'
		);
	}

	/**
	 * @testdox When the unresolved key was sent with two customers and a response filter empties the earlier payment's customer, the order keeps its customer and nothing more is charged.
	 *
	 * Either request under the kept key may be the one Stripe ran: the order holds cus_b, the customer of the second request,
	 * and the account list finds the payment it made. A `wcpay_api_request_response` callback empties the intent's customer;
	 * an empty customer names none, so the outcome writes no customer and the order keeps cus_b.
	 */
	public function test_earlier_payment_with_an_empty_filtered_customer_keeps_the_order_customer(): void {
		$order          = $this->create_woopayments_order();
		$failed_at      = time() - 1000;
		$last_failed_at = time() - 500;
		$order->update_meta_data( '_stripe_customer_id', 'cus_b' );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'key_first' );
		$order->update_meta_data(
			WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META,
			array(
				'order_id'       => $order->get_id(),
				'customers'      => array( 'cus_a', 'cus_b' ),
				'failed_at'      => $failed_at,
				'last_failed_at' => $last_failed_at,
			)
		);
		$order->save();
		$http_client            = self::create_routed_http_client(
			array(
				'created%5Bgte%5D' => self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 1000, array( 'customer' => 'cus_b' ) ) ) ),
			)
		);
		$http_client->responses = array( self::stripe_idempotency_error( 'key_first' ) );
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( is_array( $body ) && 'list' === ( $body['object'] ?? '' ) ) {
					foreach ( array_keys( $body['data'] ) as $index ) {
						$body['data'][ $index ]['customer'] = '';
					}
					$response['body'] = wp_json_encode( $body );
				}

				return $response;
			}
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_b' );
		$provider = $this->create_provider_over_fake_transport( $http_client, $this->create_account_service( false ), $customer_service );

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ),
			$provider
		);

		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id(), 'The earlier payment pays the order.' );
		$this->assertSame( 'cus_b', wc_get_order( $order->get_id() )->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame(
			array( 'POST intentions key_first', self::account_list_trail( $failed_at, $last_failed_at ) ),
			self::request_trail( $http_client ),
			'Only the refused kept key and the account list reach the platform; the new card is not charged.'
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
			$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, $kept_key );
		}
		if ( array() !== $record ) {
			$record['order_id'] = $record['order_id'] ?? $order->get_id();
			$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, $record );
		}
		$order->save_meta_data();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::stripe_idempotency_error( $expected_key ) );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );

		$outcome = $this->charge_attempt( $sut, $order, 'pm_new', 'key_attempt', $provider_data );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions ' . $expected_key ), self::request_trail( $http_client ) );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'idempotency_error', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * Idempotency refusals outside the trigger.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}>
	 */
	public function provide_idempotency_refusals_without_lookup(): array {
		$record = array(
			'customers'      => array( 'cus_sent' ),
			'failed_at'      => time(),
			'last_failed_at' => time(),
		);

		return array(
			'the attempt sends its own fresh key'      => array( '', array(), array(), 'key_attempt' ),
			'the kept key has no ambiguity record'     => array( 'key_kept', array(), array(), 'key_kept' ),
			'the payment is a scheduled renewal'       => array( 'key_kept', $record, array( 'scheduled_subscription_payment' => true ), 'key_kept' ),
			'the ambiguity record names another order' => array( 'key_kept', array_merge( $record, array( 'order_id' => 987654 ) ), array(), 'key_attempt' ),
			'the ambiguity record has no customer to look up' => array( 'key_kept', array_merge( $record, array( 'customers' => array( '' ) ) ), array(), 'key_kept' ),
		);
	}

	/**
	 * @testdox After a definitive failure retired the key, the next attempt sends a fresh key, so an idempotency refusal on it looks nothing up.
	 */
	public function test_attempt_after_a_definitive_failure_does_not_look_up(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), self::stripe_card_declined(), self::stripe_idempotency_error( 'key_third' ) );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );

		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_first', 'key_second' );
		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_third' ), self::request_trail( $http_client ) );
	}

	/**
	 * @testdox An ambiguous answer ($_dataName) under the unresolved key looks nothing up and keeps the key and the record.
	 *
	 * A 409 or a server error means the earlier request may still be running, so its intent may not be listable yet
	 * (Stripe's docs promise read-after-write consistency for List, https://docs.stripe.com/search, but say nothing about
	 * an intent whose create-and-confirm has not returned): the ambiguity classification wins over the error type, and the
	 * "key is retired" warning is not written because nothing is retired (review 44 F1).
	 *
	 * @dataProvider provide_ambiguous_idempotency_answers
	 *
	 * @param int    $status HTTP status of the answer.
	 * @param string $type   Stripe error type of the answer.
	 * @param string $code   Stripe error code of the answer.
	 */
	public function test_ambiguous_answer_with_idempotency_type_under_the_unresolved_key_does_not_look_up( int $status, string $type, string $code ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::http_json(
				$status,
				array(
					'error' => array(
						'type'    => $type,
						'code'    => $code,
						'message' => 'There is currently another in-progress request using this Idempotent Key: key_first. Please try again later.',
					),
				)
			),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ), 'No intents list may be read while the earlier request may still run.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame( array(), self::warning_lines( $logger ) );
	}

	/**
	 * Ambiguous answers: Stripe's 409 in-use conflict with either error type, and a server error with the idempotency type.
	 *
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public function provide_ambiguous_idempotency_answers(): array {
		return array(
			'409 conflict'                            => array( 409, 'idempotency_error', 'idempotency_key_in_use' ),
			'502 error'                               => array( 502, 'idempotency_error', 'idempotency_error' ),
			'409 in-use with an invalid request type' => array( 409, 'invalid_request_error', 'idempotency_key_in_use' ),
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
		$http_client            = new FakeHttpClient();
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
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
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
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
		$this->assertContains( "The earlier payment attempt for this order went through, so WooPayments did not take payment from the customer's new payment method.", self::note_texts( $order ) );
		$this->assert_one_lookup_warning( $logger, $order, 'key_first', array( 'pi_earlier' ) );
	}

	/**
	 * @testdox An earlier payment for another amount than the order total fails the order with the overpayment notice and keeps the key, since money moved.
	 */
	public function test_earlier_payment_for_another_amount_refuses_and_keeps_the_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 800 ) ) ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'duplicate_payment_amount_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertStringContainsString( 'so we prevented an overpayment', (string) ( $outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? '' ) );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
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
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( self::order_intent( $order, 'pi_earlier', 'succeeded', 1000, array( 'currency' => 'eur' ) ) ) ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'duplicate_payment_amount_mismatch', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertStringContainsString( '&euro;', (string) ( $outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? '' ), 'The paid amount shows in the intent\'s currency.' );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox When the earlier request left $_dataName for the order, the key and record are retired and the new card is charged once under the attempt key.
	 *
	 * The list is read-after-write consistent for a finished create (https://docs.stripe.com/search, Step 0 check 2) and
	 * every money call the earlier request could make creates an intent carrying the order's id and key (Step 0 check 3),
	 * so a successful lookup without such an intent holding money proves the earlier request took nothing. Stripe sends the
	 * 400 `idempotency_error` only once it holds a finished result for the key (https://docs.stripe.com/api/idempotent_requests;
	 * Step 0 check 1), so the lookup comes before anything else is sent.
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
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array_map( static fn( array $intent ): array => self::order_intent( $order, ...$intent ), $intents ) ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
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
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
		$this->assertSame( $adds_note, in_array( 'An earlier payment attempt for this order did not go through.', self::note_texts( $order ), true ) );
		$this->assert_one_lookup_warning( $logger, $order, 'key_first' );
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
			'an empty list'           => array( array(), false ),
		);
	}

	/**
	 * @testdox A full page without the order's intent whose oldest intent is $_dataName proves no intent: $proves_none.
	 *
	 * The list is newest first, so a full page (`has_more`) that reaches back past the earlier request's window holds every
	 * intent created since; the earlier request was sent at most one request timeout plus its transport retries before the
	 * failure was recorded, and the window is 300 s (review 44 F6). A page that stops inside the window may have left the
	 * earlier intent for the next page, so it proves nothing and the account's list around the failure settles it (review
	 * 67 F3); a complete one without the order lets the new card be charged.
	 *
	 * @dataProvider provide_full_pages_against_the_window
	 *
	 * @param int  $oldest_age  Seconds between the page's oldest intent and the recorded failure.
	 * @param bool $proves_none Whether the page proves the order has no intent, so the new card is charged.
	 */
	public function test_full_page_proves_no_intent_only_when_it_reaches_back_past_the_window( int $oldest_age, bool $proves_none ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway() );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at              = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['failed_at'];
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
		);
		if ( ! $proves_none ) {
			$http_client->responses[] = self::intent_list( array() );
		}
		$http_client->responses[] = self::succeeded_charge( 'pi_new_card', 'pm_new' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );

		$this->assertSame(
			array_merge(
				array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100' ),
				$proves_none ? array() : array( self::account_list_trail( $failed_at ) ),
				array( 'POST intentions key_second' )
			),
			self::request_trail( $http_client )
		);
		$this->assertSame( 'pi_new_card', $outcome->get_provider_payment_id() );
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
	 * A refunded PaymentIntent keeps its `succeeded` status, so a payment of the order that was given back (a refund and a
	 * reopened order, for example) must not complete the order again (review 44 F4). Under the platform's pinned
	 * Stripe-Version 2020-08-27 (wpcom `wcpay/utils/class-config.php:414-425`) a listed intent carries its charges with
	 * `refunded`, `amount_refunded` and `disputed`. An intent that was fully refunded counts as one without money, so the
	 * new card is charged, whether or not it was disputed first, as on the attached-intent guard; a partly refunded one
	 * still holds money and pays the order.
	 *
	 * @dataProvider provide_given_back_charges
	 *
	 * @param array<string,mixed> $charge      The intent's charge.
	 * @param bool                $holds_money Whether the order is paid from the earlier intent.
	 */
	public function test_earlier_intent_that_gave_its_money_back_does_not_pay_the_order( array $charge, bool $holds_money ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
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
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
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
			'refunded after a dispute'      => array(
				array(
					'amount'          => 1000,
					'amount_refunded' => 1000,
					'refunded'        => true,
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
	 * @testdox An earlier intent whose payment is disputed refuses every attempt like the attached-intent guard: the under-review notice, no charge, the order status, key and record kept, and one note.
	 *
	 * A disputed PaymentIntent keeps its `succeeded` status and its money may still come back, so charging the new card
	 * could take the order's money twice (review 67 F8, monitor ruling 2026-10-05). The listed intent carries its charges
	 * with `disputed` under the platform's pinned Stripe-Version 2020-08-27 (wpcom `wcpay/utils/class-config.php:414-425`;
	 * Charge object https://docs.stripe.com/api/charges/object). Each attempt runs under the order payment lock, as checkout
	 * holds it (PaymentProcessingService::process_checkout_outcome()), so the note is written under that lock.
	 */
	public function test_earlier_intent_whose_payment_is_disputed_refuses_every_attempt_with_one_note(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order           = $this->create_woopayments_order();
		$disputed_intent = self::order_intent(
			$order,
			'pi_earlier',
			'succeeded',
			1000,
			array(
				'charges' => array(
					'data' => array(
						array(
							'id'              => 'ch_earlier',
							'amount'          => 1000,
							'amount_refunded' => 0,
							'refunded'        => false,
							'disputed'        => true,
						),
					),
				),
			)
		);

		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( $disputed_intent ) ),
			self::stripe_idempotency_error( 'key_first' ),
			self::intent_list( array( $disputed_intent ) ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger     = RecordingWcLogger::install();
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		$refused    = array();
		foreach ( array( 'key_second', 'key_third' ) as $attempt_key ) {
			$token = $store->claim( $order, $vocabulary, $attempt_key, 'checkout' );
			$this->assertIsString( $token );
			try {
				$refused[] = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', $attempt_key );
			} finally {
				$store->release( $order, $vocabulary, $token );
			}
			$this->assert_one_lookup_warning( $logger, $order, 'key_first', array( 'pi_earlier' ) );
		}
		$kept = wc_get_order( $order->get_id() );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100' ),
			self::request_trail( $http_client ),
			'The new card must never be charged.'
		);
		foreach ( $refused as $outcome ) {
			$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
			$this->assertSame( WooPaymentsDuplicatePaymentPreventionService::ERROR_DISPUTED_INTENT, $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
			$this->assertSame( "This order's payment is under review. Please contact the store.", $outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
			$this->assertTrue( $outcome->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? null );
			$this->assertNull( $outcome->get_effect_plan() );
		}
		$this->assertSame( 'pending', $kept->get_status() );
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame(
			array( 'The payment attached to this order (pi_earlier) is disputed, so the customer was not charged again. Check the dispute in WooPayments before the customer pays for this order.' ),
			self::note_texts_containing( $kept, 'is disputed' ),
			'One note per intent, not one per attempt.'
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
		$http_client            = new FakeHttpClient();
		$http_client->responses = array_merge(
			array( self::platform_bad_gateway(), self::stripe_idempotency_error( 'key_first' ) ),
			$lookup_responses,
			array(
				self::stripe_idempotency_error( 'key_first' ),
				self::intent_list( array() ),
				self::succeeded_charge( 'pi_new_card', 'pm_new' ),
			)
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['failed_at'];
		$logger    = RecordingWcLogger::install();

		$refused = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$kept    = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $refused->get_status() );
		$this->assertSame( 'wcpay_charge_lookup_failed', $refused->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertTrue( $refused->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? null );
		$this->assertArrayNotHasKey( WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY, $refused->get_data(), 'The generic notice shows when the outcome has no shopper message.' );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $refused->get_data() );
		$this->assertNull( $refused->get_effect_plan() );
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame( array(), self::note_texts_containing( $kept, 'could not be checked' ), 'A lookup that may pass adds no merchant note.' );
		$this->assert_one_lookup_warning( $logger, $order, 'key_first' );

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
			'customer missing, then a transport error on the account list' => array(
				array( self::stripe_no_such_customer(), new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
			),
			'customer missing, then a server error on the account list' => array(
				array( self::stripe_no_such_customer(), self::platform_bad_gateway() ),
			),
		);
	}

	/**
	 * @testdox When the customer's intents cannot settle it ($_dataName), the account's intents since the failure do: the earlier payment pays the order.
	 *
	 * A deleted customer keeps its PaymentIntents, so the account's list, filtered to intents created from 3600 s before
	 * the recorded failure and matched on the order id and key, still shows the earlier request's intent (monitor ruling
	 * B). The platform forwards `created` to Stripe's list unchanged (wpcom `wcpay/class-intentions-controller.php:198-210`).
	 * An answer without a list falls back too, so a changed answer shape cannot refuse every attempt without the merchant
	 * note (review 45 F5). So does a full customer page that stops inside the 300 s window: the customer's list has no time
	 * bound and only gets newer, so a later attempt could never read further (review 67 F3).
	 *
	 * @dataProvider provide_customer_lists_that_fall_back
	 *
	 * @param callable $build_customer_answer Builds the transport answer to the customer's intents list.
	 */
	public function test_customer_list_refused_falls_back_to_the_account_list( callable $build_customer_answer ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			$build_customer_answer(),
			self::intent_list(
				array(
					self::order_intent( $this->create_woopayments_order(), 'pi_other_order', 'succeeded' ),
					self::order_intent( $order, 'pi_earlier', 'succeeded' ),
				)
			),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['failed_at'];

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
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * Customer-list answers that send the lookup to the account's list.
	 *
	 * Each answer is built when the test runs: PHPUnit runs data providers when it builds the suite, minutes before a late
	 * test in a whole-plugin run, so a `created` time stamped there can be older than the 300 s window when the test runs.
	 *
	 * @return array<string,array{0:callable():array<string,mixed>}>
	 */
	public function provide_customer_lists_that_fall_back(): array {
		return array(
			'a deleted customer'     => array( static fn(): array => self::stripe_no_such_customer() ),
			'an answer with no list' => array( static fn(): array => self::list_answer_without_data() ),
			'a full page of newer intents with more to read' => array(
				static fn(): array => self::intent_list(
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
		);
	}

	/**
	 * @testdox A complete account list without the order's intent proves the earlier request took nothing: the key and record are retired and the new card is charged once.
	 *
	 * The account list holds every intent created since the window start, newest first, and `has_more` is false only when
	 * no other intent is left in the window (recorded on a local WPCOM platform: `has_more` false once the window holds no
	 * more intents, true while it does), so a complete page without the order's id and key proves no intent exists
	 * (review 45 F3).
	 */
	public function test_complete_account_list_without_the_order_charges_the_new_card(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			self::intent_list( array( self::order_intent( $this->create_woopayments_order(), 'pi_other_order', 'succeeded' ) ) ),
			self::succeeded_charge( 'pi_new_card', 'pm_new' ),
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$failed_at = (int) wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['failed_at'];

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );
		$body    = json_decode( (string) $http_client->requests[4]['body'], true );

		$this->assertSame(
			array( 'POST intentions key_first', 'POST intentions key_first', 'GET intentions?test_mode=0&customer=cus_sent&limit=100', self::account_list_trail( $failed_at ), 'POST intentions key_second' ),
			self::request_trail( $http_client )
		);
		$this->assertSame( 'pm_new', $body['payment_method'] ?? null );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_new_card', $outcome->get_provider_payment_id() );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
		$this->assertSame( array(), self::note_texts_containing( $fresh, 'could not be checked' ) );
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
	 * @param callable $build_account_answer Builds the transport answer to the account's intents list.
	 */
	public function test_lookup_that_cannot_check_refuses_every_attempt_with_one_note( callable $build_account_answer ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$account_answer         = $build_account_answer();
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::platform_bad_gateway(),
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			$account_answer,
			self::stripe_idempotency_error( 'key_first' ),
			self::stripe_no_such_customer(),
			$account_answer,
		);
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );
		$logger = RecordingWcLogger::install();

		$first = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$this->assert_one_lookup_warning( $logger, $order, 'key_first' );
		$second = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_third' );
		$this->assert_one_lookup_warning( $logger, $order, 'key_first' );
		$kept = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first', 'POST intentions key_first' ), array_values( array_filter( self::request_trail( $http_client ), static fn( string $line ): bool => 0 === strpos( $line, 'POST' ) ) ), 'Only the first send and the two refused kept-key sends; the new card is never charged.' );
		$this->assertCount( 7, $http_client->requests );
		foreach ( array( $first, $second ) as $refused ) {
			$this->assertSame( PaymentOutcome::STATUS_FAILED, $refused->get_status() );
			$this->assertSame( 'wcpay_charge_lookup_failed', $refused->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
			$this->assertTrue( $refused->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? null );
			$this->assertArrayNotHasKey( WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY, $refused->get_data() );
			$this->assertNull( $refused->get_effect_plan() );
		}
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
		$this->assertSame(
			array( "The earlier payment attempt for this order could not be checked, so WooPayments did not take payment from the customer's new payment method. Please check for this payment in WooPayments before the customer tries again." ),
			self::note_texts_containing( $kept, 'could not be checked' ),
			'One note per order, not one per attempt.'
		);
	}

	/**
	 * Account-list answers that cannot settle the earlier request.
	 *
	 * Each answer is built when the test runs, so the full page's intent is created inside the window around the failure
	 * however long after the suite was built the test runs.
	 *
	 * @return array<string,array{0:callable():array<string,mixed>}>
	 */
	public function provide_account_lists_that_cannot_settle(): array {
		return array(
			'the account answer has no list'        => array( static fn(): array => self::list_answer_without_data() ),
			'the account list refused'              => array(
				static fn(): array => self::http_json(
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
				static fn(): array => self::intent_list(
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
	public function test_platform_refusal_under_the_unresolved_key_keeps_key_and_record(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
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
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$blocked = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$kept    = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $blocked->get_status() );
		$this->assertSame( 'wcpay_blocked_by_fraud_rule', $blocked->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'key_first', $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );

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
	public function test_missing_customer_under_the_unresolved_key_is_not_recreated_while_the_record_exists(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
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
		$customer_service       = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order', 'replace_missing_customer_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_sent' );
		$customer_service->expects( $this->never() )->method( 'replace_missing_customer_for_order' );
		$sut = $this->create_timeout_operation_service( $http_client, 'cus_sent', $customer_service );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$outcome = $this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_new', 'key_second' );
		$fresh   = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ), 'The new card must not be charged under a recovery key.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( array( 'cus_sent' ), $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true )['customers'] ?? null );
	}

	/**
	 * @testdox Without an ambiguity record, a refusal the platform made itself retires the key as before.
	 */
	public function test_platform_refusal_without_ambiguity_record_retires_the_key(): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
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
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );

		$outcome = $this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$this->assertSame( 'wcpay_card_testing_prevention', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox While the ambiguity record exists, a Stripe $_dataName under the unresolved key retires the key and the record: $retires.
	 *
	 * Only a card error or a success proves Stripe processed this request under the key, fresh or as the stored result of
	 * the earlier request, so either settles what the earlier request did. Stripe answers a 429 and most
	 * parameter-validation 400s before its idempotency layer and stores neither (https://docs.stripe.com/error-low-level),
	 * so they say nothing about the earlier request: the key and the record stay for the next attempt's lookup.
	 *
	 * @dataProvider provide_stripe_answers_under_the_unresolved_key
	 *
	 * @param array<string,mixed> $answer  Stripe's answer, passed through by the platform.
	 * @param bool                $retires Whether the key and the record are retired.
	 */
	public function test_stripe_answer_under_the_unresolved_key_retires_only_a_settled_request( array $answer, bool $retires ): void {
		$order                  = $this->create_woopayments_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::platform_bad_gateway(), $answer );
		$sut                    = $this->create_timeout_operation_service( $http_client, 'cus_sent' );
		$this->charge_attempt( $sut, $order, 'pm_first', 'key_first' );

		$this->charge_attempt( $sut, wc_get_order( $order->get_id() ), 'pm_first', 'key_second' );
		$fresh = wc_get_order( $order->get_id() );

		$this->assertSame( array( 'POST intentions key_first', 'POST intentions key_first' ), self::request_trail( $http_client ) );
		$record = $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true );

		$this->assertSame( $retires ? '' : 'key_first', $fresh->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( $retires ? '' : array( 'cus_sent' ), is_array( $record ) ? $record['customers'] : $record );
	}

	/**
	 * Stripe's answers to a charge sent under the kept key.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provide_stripe_answers_under_the_unresolved_key(): array {
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
	 * @param FakeHttpClient                  $http_client      Platform answers.
	 * @param string                          $customer_id      Customer the charges are sent with.
	 * @param WooPaymentsCustomerService|null $customer_service Customer service to use instead of one that only returns the customer.
	 * @return WooPaymentsPaymentOperationService
	 */
	private function create_timeout_operation_service( FakeHttpClient $http_client, string $customer_id, ?WooPaymentsCustomerService $customer_service = null ): WooPaymentsPaymentOperationService {
		$account_service = $this->create_account_service( false );
		$api_client      = new class() extends Client {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		if ( null === $customer_service ) {
			$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
			$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( $customer_id );
		}

		return $this->create_operation_service( $api_client, $customer_service, null, $account_service );
	}

	/**
	 * Run one checkout charge the way the processing service does: charge, then the post-lifecycle key step.
	 *
	 * @param WooPaymentsPaymentOperationService $sut           Adapter.
	 * @param WC_Order                           $order         Order as the attempt loads it.
	 * @param string                             $pm            Payment method the shopper submits.
	 * @param string                             $attempt_key   Key minted for the attempt.
	 * @param array<string,mixed>                $provider_data Provider data.
	 * @param array<string,mixed>                $payment_data  Payment data.
	 * @return PaymentOutcome
	 */
	private function charge_attempt( WooPaymentsPaymentOperationService $sut, WC_Order $order, string $pm, string $attempt_key, array $provider_data = array(), array $payment_data = array() ): PaymentOutcome {
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $pm, $payment_data, $provider_data ), $attempt_key );
		$sut->finalize_charge_idempotency_key( $order, $outcome );

		return $outcome;
	}

	/**
	 * Build a fake platform that answers a request whose path contains a route's needle with that route's answer, and any
	 * other request from the queue.
	 *
	 * @param array<string,array<string,mixed>> $routes Answers by path needle.
	 * @return FakeHttpClient
	 */
	private static function create_routed_http_client( array $routes ): FakeHttpClient {
		$http_client         = new class() extends FakeHttpClient {
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
	 * @param FakeHttpClient $http_client Recorded transport.
	 * @return string[]
	 */
	private static function request_trail( FakeHttpClient $http_client ): array {
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
	 * Shape: Stripe's error object with type idempotency_error (https://docs.stripe.com/api/errors,
	 * https://docs.stripe.com/api/idempotent_requests), read by the client's error-envelope parser
	 * (WooPayments 11.1.0 includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871); the platform proxy returns Stripe's status and body
	 * unchanged (wpcom `wcpay/class-base-controller.php:476-491`).
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
	 * Shape: Stripe's error object (https://docs.stripe.com/api/errors: type, code, param, message; resource_missing in
	 * https://docs.stripe.com/error-codes) with Stripe's 404, read by the client's error-envelope parser
	 * (WooPayments 11.1.0 includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871: error.code, error.message, error.type,
	 * error.param); the platform's list route returns Stripe's status and body unchanged (wpcom
	 * `wcpay/class-intentions-controller.php:371-373` through `wcpay/class-base-controller.php:476-491`).
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
	 * Shape: Stripe's error object with type api_error and a 5xx status (https://docs.stripe.com/api/errors), read by the
	 * client's error-envelope parser (WooPayments 11.1.0 includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871: with no
	 * error.code, error.type becomes the code); the platform proxy returns Stripe's status and body unchanged (wpcom
	 * `wcpay/class-base-controller.php:476-491`).
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
	 * The window starts 3600 s before the first recorded failure: the store's clock and Stripe's `created` may disagree,
	 * and a wider window only turns a charge into "cannot check" (review 45 F2). It ends 3600 s after the latest recorded
	 * failure, for the same clock difference, and because a later request under the key may be the one Stripe ran (review
	 * 47 F2). The platform honours both ends ("created[lte] live check (2026-10-05)"). The customer list keeps its 300 s
	 * window.
	 *
	 * @param int      $failed_at      Unix time of the first recorded ambiguous failure.
	 * @param int|null $last_failed_at Unix time of the latest recorded ambiguous failure; the first when only one was.
	 * @return string
	 */
	private static function account_list_trail( int $failed_at, ?int $last_failed_at = null ): string {
		return 'GET intentions?test_mode=0&created%5Bgte%5D=' . ( $failed_at - 3600 ) . '&created%5Blte%5D=' . ( ( $last_failed_at ?? $failed_at ) + 3600 ) . '&limit=100';
	}

	/**
	 * Stripe's card decline, passed through by the platform.
	 *
	 * Same status and error fields as the recorded decline in `Fixtures/platform-intention-declines.json`, pair
	 * `generic_decline`.
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
	 * The fields the store reads from the recorded answer in `Fixtures/platform-intention-basic-card.json`, pair
	 * `basic_card_usd_create_and_confirm` (a 200 PaymentIntent with id, status succeeded, amount, currency, customer and
	 * payment_method; https://docs.stripe.com/api/payment_intents/object).
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
	 * The platform's intents list.
	 *
	 * Shape: Stripe's List PaymentIntents answer (https://docs.stripe.com/api/payment_intents/list: object list, url,
	 * has_more, data), which the platform's GET intentions route (wpcom `wcpay/class-intentions-controller.php:198-210`)
	 * returns unchanged (`list_intentions()`, :371-373, through `wcpay/class-base-controller.php:476-491`).
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
	 * A 200 list answer without a data list.
	 *
	 * Defensive input, not a documented or recorded answer: Stripe's list always carries data
	 * (https://docs.stripe.com/api/payment_intents/list, Returns). It proves a malformed answer is never read as "no
	 * earlier intent".
	 *
	 * @return array<string,mixed>
	 */
	private static function list_answer_without_data(): array {
		return self::http_json( 200, array( 'object' => 'list' ) );
	}

	/**
	 * An intent the store's charge path created for an order, with the metadata it sends.
	 *
	 * Shape: Stripe's PaymentIntent object (https://docs.stripe.com/api/payment_intents/object), with the order_id,
	 * order_key and order_number metadata WooPaymentsIntentRequestBuilder::metadata_from_order() sends; Stripe returns
	 * metadata values as strings (https://docs.stripe.com/api/metadata).
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
	 * Assert the attempt logged one woopayments warning that names the order and the kept key, then clear the log.
	 *
	 * @param RecordingWcLogger $logger    Logger.
	 * @param WC_Order          $order     Order being paid.
	 * @param string            $key       Kept charge key.
	 * @param string[]          $fragments Other text the line must contain, such as the earlier intent's ID.
	 */
	private function assert_one_lookup_warning( RecordingWcLogger $logger, WC_Order $order, string $key, array $fragments = array() ): void {
		$warnings         = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 'woopayments' === $line[2] ) );
		$logger->lines    = array();
		$logger->contexts = array();

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '#' . $order->get_id(), $warnings[0][1] );
		$this->assertStringContainsString( $key, $warnings[0][1] );
		foreach ( $fragments as $fragment ) {
			$this->assertStringContainsString( $fragment, $warnings[0][1] );
		}
	}

	/**
	 * @testdox Charge declines without a card error keep the failed note but no fraud meta box type.
	 */
	public function test_charge_decline_without_card_error_writes_note_without_fraud_meta(): void {
		$order      = $this->create_woopayments_order( '25.00' );
		$api_client = new class() extends Client {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new ApiException( 'Error: Upstream provider unavailable.', 'api_connection_error', 502, 'api_error' );
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

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertStringContainsString( 'Error: Upstream provider unavailable', $data[ PaymentOutcome::DATA_NOTE ] ?? '' );
		$this->assertArrayNotHasKey( '_wcpay_fraud_meta_box_type', $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array() );
	}

	/**
	 * @testdox Charge blocked by fraud rules records the block state without failing the order.
	 */
	public function test_charge_blocked_by_fraud_rules_records_block_state(): void {
		$order      = $this->create_woopayments_order( '25.00' );
		$api_client = new class() extends Client {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new ApiException(
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

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();
		$meta    = $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertTrue( $data[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? false, 'A fraud block must not fail the order; the merchant decides whether to cancel.' );
		$this->assertSame( 'block', $meta['_wcpay_fraud_outcome_status'] ?? null );
		$this->assertSame( 'block', $meta['_wcpay_fraud_meta_box_type'] ?? null );
		$this->assertSame( wp_json_encode( array( 'international_ip_address' => 'block' ) ), $meta['_wcpay_fraud_ruleset_results'] ?? null );
		$this->assertSame( 'canceled', $meta['_intention_status'] ?? null );
		$this->assertSame( 'pi_blocked_test', $outcome->get_provider_payment_id() );
		$this->assertStringContainsString( '<strong>blocked</strong> by the following risk filters', $data[ PaymentOutcome::DATA_NOTE ] ?? '' );
		// Client 11.1.0 test_process_payment_marks_order_as_blocked_for_fraud: the shopper notice equals the thrown message.
		$this->assertSame( 'Error: Transaction blocked by fraud rules.', $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
	}

	/**
	 * @testdox Charge treats an incorrect_zip decline as a fraud block only while the AVS rule is enabled.
	 */
	public function test_charge_treats_incorrect_zip_as_block_only_with_avs_rule_enabled(): void {
		delete_transient( 'wcpay_fraud_protection_settings' );
		set_transient( 'wcpay_fraud_protection_settings', array( array( 'key' => 'avs_verification' ) ), DAY_IN_SECONDS );

		$make_api_client = function () {
			return new class() extends Client {
				// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
				/**
				 * Create and confirm a payment intention.
				 *
				 * @param array<string,mixed> $request_data Request data.
				 * @param string              $idempotency_key Idempotency key.
				 * @return array<string,mixed>
				 */
				public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
					throw new ApiException(
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
		$sut     = $this->create_operation_service( $make_api_client(), $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$meta    = $outcome->get_data()[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array();

		$this->assertSame( 'block', $meta['_wcpay_fraud_meta_box_type'] ?? null, 'With the AVS rule enabled, an incorrect_zip decline is an AVS block.' );
		$this->assertSame( wp_json_encode( array( 'avs_verification' => 'block' ) ), $meta['_wcpay_fraud_ruleset_results'] ?? null );
		// Client 11.1.0 utils.php:799 with the fraud flag (gw:1426): no postal-code hint, the platform message shows
		// (client test_process_payment_marks_order_as_blocked_for_fraud_avs_mismatch asserts the thrown message).
		$this->assertSame(
			'Error: Your postal code failed validation.',
			$outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null,
			'An AVS-blocked shopper sees the platform message, not the postal-code hint.'
		);

		delete_transient( 'wcpay_fraud_protection_settings' );

		$order   = $this->create_woopayments_order( '25.00' );
		$sut     = $this->create_operation_service( $make_api_client(), $customer_service, null, $this->create_account_service( true ) );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$data    = $outcome->get_data();
		$meta    = $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array();

		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] ?? null, 'Without the AVS rule, an incorrect_zip decline is an ordinary card error.' );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_PRESERVE_ORDER_STATUS, $data );
	}

	/**
	 * Client 11.1.0 reads the AVS rule through the getter that fetches a missing ruleset from the platform
	 * (includes/class-wc-payment-gateway-wcpay.php:3685-3731) and fetches at most once per request (:3765-3772).
	 *
	 * @testdox With no cached fraud ruleset, an incorrect_zip decline fetches it once ($label) and a later settings read fetches nothing.
	 * @testWith ["the platform ruleset has the AVS rule", true, "block"]
	 *           ["the platform answers with an error", false, "allow"]
	 *
	 * @param string $label            Case.
	 * @param bool   $platform_answers Whether the platform answers with a ruleset holding the AVS rule, rather than failing.
	 * @param string $expected_box     Expected fraud meta box type.
	 */
	public function test_charge_fetches_a_missing_fraud_ruleset_once_before_classifying_incorrect_zip( string $label, bool $platform_answers, string $expected_box ): void {
		unset( $label );
		delete_transient( 'wcpay_fraud_protection_settings' );
		$fraud_api_client = new RecordingSettingsApiClient();
		if ( $platform_answers ) {
			$fraud_api_client->latest_fraud_ruleset_response = array(
				// The AVS rule as client 11.1.0's tests store it (tests/unit/test-class-wc-payment-gateway-wcpay.php:4374-4386).
				'ruleset_config' => array(
					array(
						'key'     => 'avs_verification',
						'outcome' => 'block',
						'check'   => array(
							'key'      => 'avs_mismatch',
							'operator' => 'equals',
							'value'    => true,
						),
					),
				),
			);
		} else {
			$fraud_api_client->latest_fraud_ruleset_exception = new ApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );
		}
		list( $settings_service, $fraud_ruleset_service ) = $this->create_fraud_reading_services( $fraud_api_client );

		$charge_api_client = new class() extends Client {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				throw new ApiException( 'Error: Your postal code failed validation.', 'incorrect_zip', 402, 'card_error', '', array(), 'pi_avs_test' );
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
		$customer_service  = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_fraud_test' );

		$sut     = $this->create_operation_service( $charge_api_client, $customer_service, null, $this->create_account_service( true ), null, $fraud_ruleset_service );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $this->create_woopayments_order( '25.00' ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$meta    = $outcome->get_data()[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array();

		$this->assertSame( $expected_box, $meta['_wcpay_fraud_meta_box_type'] ?? null );
		$this->assertSame( 1, $fraud_api_client->latest_fraud_ruleset_requests, 'The decline fetches the missing ruleset once.' );

		$settings_service->get_settings();

		$this->assertSame( 1, $fraud_api_client->latest_fraud_ruleset_requests, 'The settings read in the same request fetches nothing more.' );
	}

	/**
	 * @testdox Charge should flag platform-created payment methods for WooPayments.
	 */
	public function test_charge_flags_platform_created_payment_methods_for_wcpay(): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$api_client       = new class() extends Client {
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

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox With manual capture enabled, a $_dataName charge sends the $capture_method capture method.
	 *
	 * The answers are reduced from the recorded authorize_manual_capture (Fixtures/platform-manual-capture.json:
	 * requires_capture, charge not captured) and renewal_off_session (Fixtures/platform-intention-subscription.json:
	 * succeeded, charge captured).
	 *
	 * @dataProvider provider_capture_methods_with_manual_capture_enabled
	 *
	 * @param array<string,mixed> $provider_data  Checkout provider data.
	 * @param string              $intent_status  Status of the intent the platform answers.
	 * @param bool                $captured       Whether the answered charge is captured.
	 * @param string              $capture_method Expected capture method sent.
	 * @param string              $outcome_status Expected outcome status.
	 */
	public function test_charge_capture_method_with_manual_capture_enabled( array $provider_data, string $intent_status, bool $captured, string $capture_method, string $outcome_status ): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$api_client       = $this->create_answering_charge_api_client(
			array(
				'id'             => 'pi_capture_method',
				'status'         => $intent_status,
				'customer'       => 'cus_capture_method',
				'payment_method' => 'pm_capture_method',
				'currency'       => 'usd',
				'charges'        => array(
					'total_count' => 1,
					'data'        => array(
						array(
							'id'       => 'ch_capture_method',
							'captured' => $captured,
						),
					),
				),
			)
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_capture_method' );

		$sut     = $this->create_operation_service(
			$api_client,
			$customer_service,
			null,
			$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
		);
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_capture_method', array(), $provider_data ),
			'key_charge'
		);

		$this->assertSame( $capture_method, $api_client->requests[0]['capture_method'] ?? null );
		$this->assertSame( $outcome_status, $outcome->get_status() );
	}

	/**
	 * Checkout and scheduled renewal charges with manual capture enabled: a renewal stays automatic.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:bool,3:string,4:string}>
	 */
	public function provider_capture_methods_with_manual_capture_enabled(): array {
		return array(
			'checkout'          => array( array(), 'requires_capture', false, 'manual', PaymentOutcome::STATUS_AUTHORIZED ),
			'scheduled renewal' => array( array( 'scheduled_subscription_payment' => true ), 'succeeded', true, 'automatic', PaymentOutcome::STATUS_COMPLETED ),
		);
	}

	/**
	 * @testdox A processing card charge with $_dataName gets the pre-debit approval note only when the bank asks for approval.
	 *
	 * @dataProvider provider_customer_notification_answers
	 *
	 * @param string              $currency  Currency of the answered intent.
	 * @param array<string,mixed> $card      The intent's `processing.card` field.
	 * @param int[]               $deadlines Approval deadlines the expected notes name.
	 */
	public function test_charge_adds_the_pre_debit_approval_note_only_when_approval_is_requested( string $currency, array $card, array $deadlines ): void {
		$order            = $this->create_woopayments_order( '50.00' );
		$api_client       = $this->create_answering_charge_api_client(
			array(
				'id'             => 'pi_notification',
				'status'         => 'processing',
				'customer'       => 'cus_notification',
				'payment_method' => 'pm_notification',
				'currency'       => $currency,
				'processing'     => array( 'card' => $card ),
			)
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_notification' );

		$sut = $this->create_operation_service( $api_client, $customer_service );
		$sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_notification' ), 'key_notification' );

		$expected_notes = array_map(
			static fn( int $completes_at ): string => sprintf(
				'The customer must authorize this payment via a notification sent to them by the bank which issued their card. The authorization must be completed before %1$s at %2$s, when the charge will be attempted.',
				wp_date( get_option( 'date_format', 'F j, Y' ), $completes_at, wp_timezone() ),
				wp_date( get_option( 'time_format', 'g:i a' ), $completes_at, wp_timezone() )
			),
			$deadlines
		);
		$this->assertSame( $expected_notes, self::note_texts_containing( $order, 'must authorize this payment via a notification' ) );
	}

	/**
	 * Processing card answers with and without a requested approval. No recording holds a processing card intent; the
	 * answer carries `processing.card.customer_notification`, the fields client 11.1.0 reads for this note
	 * (includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:1336-1352).
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:int[]}>
	 */
	public function provider_customer_notification_answers(): array {
		return array(
			'an approval requested' => array(
				'inr',
				array(
					'customer_notification' => array(
						'approval_requested' => true,
						'completes_at'       => 1893510000,
					),
				),
				array( 1893510000 ),
			),
			'no approval requested' => array( 'usd', array(), array() ),
		);
	}

	/**
	 * @testdox Charge should recreate and retry under a derived key, kept on the order, when the transport reports a missing customer.
	 *
	 * The retry sends another customer, and Stripe refuses a reused idempotency key with a different body, so the retry
	 * gets its own key; the order keeps it so an ambiguous failure of the retry replays the retry (area 2a #15).
	 */
	public function test_charge_retries_after_missing_customer_by_recreating_customer(): void {
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends Client {
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

					throw new ApiException( 'No such customer: customer', 'resource_missing', 404 );
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
			->onlyMethods( array( 'get_or_create_customer_id_for_order', 'replace_missing_customer_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_missing' );
		$customer_service->expects( $this->once() )
			->method( 'replace_missing_customer_for_order' )
			->with( $this->isInstanceOf( WC_Order::class ), 'cus_missing' )
			->willReturn( 'cus_recreated' );

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_recreated', $outcome->get_customer_id() );
		$this->assertSame( array( 'key_charge', 'key_charge:customer-recovery' ), $api_client->keys );
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( 'key_charge:customer-recovery', $fresh_order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @dataProvider provider_subscription_change_transport_provider
	 * @testdox Validated subscription changes reuse an existing customer without a provider update for both intent transports.
	 *
	 * @param string $total Order total selecting PaymentIntent or SetupIntent transport.
	 * @param string $expected_intent Expected intent type.
	 */
	public function test_validated_subscription_change_uses_real_customer_service_without_update( string $total, string $expected_intent ): void {
		$order      = $this->create_woopayments_order( $total );
		$api_client = $this->create_recording_customer_intent_api_client();

		$order->update_meta_data( '_stripe_customer_id', 'cus_on_the_subscription' );
		$order->save();
		WC()->session->set( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY, 'cus_change' );

		$sut     = $this->create_operation_service(
			$api_client,
			$this->create_real_customer_service( $api_client )
		);
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox Ordinary checkout refreshes an existing customer from the order at shutdown, after its intent.
	 */
	public function test_ordinary_charge_updates_existing_customer_with_real_customer_service(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->create_recording_customer_intent_api_client();

		$order->set_billing_email( 'subject@example.com' );
		$order->save();
		WC()->session->set( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY, 'cus_ordinary' );
		$customer_service = $this->create_real_customer_service( $api_client );

		$outcome = $this->create_operation_service( $api_client, $customer_service )->charge(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_ordinary' ),
			'key_ordinary_real'
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( array(), $api_client->updated_customers, 'The refresh waits for shutdown.' );
		$customer_service->refresh_queued_customers();
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
	 * @testdox Validated subscription changes recreate a missing remote customer and retry the intent under a derived key.
	 *
	 * @param string $total Order total selecting PaymentIntent or SetupIntent transport.
	 * @param string $expected_intent Expected intent type.
	 */
	public function test_validated_subscription_change_recovers_missing_remote_customer( string $total, string $expected_intent ): void {
		$order      = $this->create_woopayments_order( $total );
		$api_client = $this->create_recording_customer_intent_api_client( true );
		$user_id    = self::factory()->user->create( array( 'role' => 'customer' ) );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_missing' );
		$order->set_customer_id( $user_id );
		$order->save();

		$outcome = $this->create_operation_service( $api_client, $this->create_real_customer_service( $api_client ) )->charge(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_change', array(), array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true ) ),
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
		$this->assertSame( 'cus_created', get_user_option( '_wcpay_customer_id_live', $user_id ), "The user's own missing customer is replaced." );
	}

	/**
	 * @testdox Charge sends only the server-allowed express checkout payment method types when $_dataName.
	 *
	 * The answer is the recorded basic_card_usd_create_and_confirm (Fixtures/platform-intention-basic-card.json) reduced
	 * to its intent fields, with no charge: the rows check only the request.
	 *
	 * @dataProvider provider_express_payment_method_types
	 *
	 * @param bool                $test_mode     Whether WooPayments runs in test mode.
	 * @param array<string,mixed> $settings      Gateway settings.
	 * @param array<string,mixed> $account_data  Cached account data.
	 * @param string              $currency      Order currency.
	 * @param array<string,mixed> $options       Store options set before the order is created.
	 * @param array<string,mixed> $provider_data Checkout provider data.
	 * @param string[]            $expected      Payment method types sent.
	 */
	public function test_charge_sends_the_allowed_express_payment_method_types( bool $test_mode, array $settings, array $account_data, string $currency, array $options, array $provider_data, array $expected ): void {
		foreach ( $options as $option => $value ) {
			update_option( $option, $value );
		}
		$order = $this->create_woopayments_order( '50.00' );
		$order->set_currency( $currency );
		$order->save();
		$api_client       = $this->create_answering_charge_api_client(
			array(
				'id'             => 'pi_express',
				'status'         => 'succeeded',
				'client_secret'  => 'secret_express',
				'customer'       => 'cus_native',
				'payment_method' => 'pm_express',
				'currency'       => strtolower( $currency ),
				'charges'        => array(
					'total_count' => 0,
					'data'        => array(),
				),
			)
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( $test_mode, $settings, $account_data ) );
		$sut->charge(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'ctoken_express', array(), $provider_data ),
			'key_charge'
		);

		$this->assertSame( $expected, $api_client->requests[0]['payment_method_types'] );
		$this->assertSame( 'ctoken_express', $api_client->requests[0]['confirmation_token'] );
		$this->assertArrayNotHasKey( 'payment_method', $api_client->requests[0] );
	}

	/**
	 * Submitted express types against the account, the order currency and the express context.
	 *
	 * The Amazon Pay type needs an available gateway, as the express button does, so the rows that keep it turn the
	 * gateway on in test mode.
	 *
	 * @return array<string,array{0:bool,1:array<string,mixed>,2:array<string,mixed>,3:string,4:array<string,mixed>,5:array<string,mixed>,6:string[]}>
	 */
	public function provider_express_payment_method_types(): array {
		$enabled_settings = array(
			'enabled'                           => 'yes',
			'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
			'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
		);
		$tokens_on        = array( 'ece_confirmation_tokens_disabled' => false );
		$card_and_amazon  = array( WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY => array( 'card', 'amazon_pay' ) );

		return array(
			'an unknown type is submitted'            => array( true, $enabled_settings, $tokens_on, 'USD', array(), array( WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY => array( 'card', 'amazon_pay', 'unknown_method' ) ), array( 'card', 'amazon_pay' ) ),
			'the account cannot take Amazon Pay'      => array(
				false,
				array(
					'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
					'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
				),
				array_merge( $tokens_on, array( 'capabilities' => array( 'amazon_pay_payments' => 'inactive' ) ) ),
				'USD',
				array(),
				$card_and_amazon,
				array( 'card' ),
			),
			'the order currency rules out Amazon Pay' => array( true, $enabled_settings, array_merge( array( 'country' => 'US' ), $tokens_on ), 'EUR', array(), $card_and_amazon, array( 'card' ) ),
			'the checkout context settings rule out Amazon Pay' => array(
				false,
				array(
					'express_checkout_cart_methods'     => array( 'payment_request', 'amazon_pay' ),
					'express_checkout_checkout_methods' => array( 'payment_request' ),
					'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
				),
				$tokens_on,
				'USD',
				array(),
				array_merge( $card_and_amazon, array( WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY => 'checkout' ) ),
				array( 'card' ),
			),
			'the pay-for-order context keeps both'    => array(
				true,
				$enabled_settings,
				$tokens_on,
				'USD',
				array(
					'woocommerce_tax_based_on' => 'billing',
					'woocommerce_calc_taxes'   => 'yes',
				),
				array_merge( $card_and_amazon, array( WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY => 'pay_for_order' ) ),
				array( 'card', 'amazon_pay' ),
			),
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

		$api_client       = new class() extends Client {
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

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . $method,
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

		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $query_args['wc_payment_method'] ?? '' );
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
	 * @testdox A single card checkout without save matches the WooPayments extension's request shape.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:1781-1782` (amount/currency),
	 * `:1821-1831` (capture_method, customer, payment_method), `:1870-1874` (`setup_future_usage`
	 * is set only when the shopper requested saving, never for a plain single-use charge), and
	 * `:2578-2585` (`payment_method_types` is `['card']` alone with Link disabled, the fixture's default;
	 * it becomes `['card','link']` only when Link is enabled).
	 */
	public function test_single_card_checkout_without_save_matches_11_1_request_shape(): void {
		$order            = $this->create_woopayments_order( '10.99' );
		$api_client       = new class() extends Client {
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

		$sut = $this->create_operation_service( $api_client, $customer_service );
		$sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox Redirect mapping sanitizes a provider URL exactly once at the runtime boundary.
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

		$api_client       = new class( $provider_url ) extends Client {
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
			$outcome = $this->create_operation_service( $api_client, $customer_service )
				->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_card' ), 'key_charge' );
		} finally {
			remove_filter( 'clean_url', $clean_url, 10 );
		}

		$this->assertSame( 1, $filter_calls );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'https://rewritten.example/authorize', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox Charge should resolve saved WooCommerce token IDs before the transport call.
	 *
	 * T.3 Task 4: RECORD swap. The response is now REC-3DS-1's saved-PM
	 * variant (`Fixtures/platform-intention-3ds-requires-action.json`, pair
	 * `saved_payment_method_requires_action`): an on-session PaymentIntent for an already-attached
	 * 3DS-required card, real from the local platform. The token-resolution guard this test exists
	 * for moves to the sent request body (`pm_saved`, the resolved token, not a raw context
	 * credential) rather than a hand-written response validator, following the fake-transport
	 * pattern {@see self::test_charge_decline_envelope_maps_each_card_code} established.
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2070-2072` attaches an
	 * already-saved token to the order (`add_token_to_order()`) unconditionally, before the
	 * `$needs_frontend_confirmation` check for a `requires_action`/`requires_confirmation` status
	 * at `:2081` — the saved-token attachment does not wait to see whether the intent needs a
	 * challenge.
	 */
	public function test_charge_resolves_saved_payment_token_before_transport(): void {
		$user_id               = $this->factory()->user->create();
		$order                 = $this->create_woopayments_order();
		$saved_token           = $this->create_card_token( $user_id, 'pm_saved' );
		$recorded              = $this->load_recorded_intent_entry( 'platform-intention-3ds-requires-action.json', 'saved_payment_method_requires_action' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
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

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		$sut     = $this->create_operation_service( $api_client, $customer_service, $token_service, $account_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox Saved subscription orders compose the pinned recurring request shape through the payment operation service.
	 *
	 * @dataProvider subscription_checkout_composition_provider
	 *
	 * @param string $created_via                  Saved order creation source.
	 * @param bool   $is_renewal                   Whether the order is a scheduled renewal.
	 * @param string $expected_payment_type        Expected pinned payment type.
	 * @param string $expected_subscription_payment Expected pinned subscription payment classification.
	 */
	public function test_subscription_checkout_composition_matches_11_1_request_shape( string $created_via, bool $is_renewal, string $expected_payment_type, string $expected_subscription_payment ): void {
		WooCommerceSubscriptionsDoubles::load_order_detector();
		WooCommerceSubscriptionsDoubles::load_renewal_detector();

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

		$api_client       = new class() extends Client {
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
			$outcome = $this->create_operation_service( $api_client, $customer_service, $token_service )->charge(
				PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $payment_method, $payment_data, $provider_data ),
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
		$saved_token            = $this->create_card_token( $user_id, 'pm_saved' );
		$metadata_payment_types = array();
		$api_client             = new class() extends Client {
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

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$user_id          = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order            = $this->create_woopayments_order( '9.99' );
		$saved_token      = $this->create_card_token( $user_id, 'pm_plugin_subscription' );
		$api_client       = new class() extends Client {
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

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		// Oracle: WooPayments 11.1.0 OrderService::get_payment_metadata() labels a customer-present renewal as recurring/renewal, and the read-only :8082 provider family records it without off_session.
		$sut     = $this->create_operation_service(
			$api_client,
			$customer_service,
			$this->create_single_resolution_token_service( $saved_token, $user_id, 'pm_plugin_subscription' ),
			$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
		);
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
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
			new PaymentMethodRegistry()
		);
		$request = $request_builder->charge_request_data(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit', 'pm_sepa' ),
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
	 * @testdox A zero-total scheduled Link renewal completes without any intent, as the WooPayments extension's scheduled payment does.
	 *
	 * Client scheduled_subscription_payment() builds its payment information from the saved token (subtrait:424-425), so gw:1688
	 * skips the intent: no transport, so no mandate question either.
	 */
	public function test_zero_total_scheduled_link_renewal_completes_without_intent(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$saved_token      = $this->create_link_token( $user_id, 'pm_link' );
		$api_client       = new class() extends Client {
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
			$sut     = $this->create_operation_service( $api_client, $customer_service, $token_service );
			$outcome = $sut->charge(
				PaymentOperationContext::for_checkout(
					$order,
					WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox A scheduled renewal failure ($_dataName) gets the unusable saved payment method note only when the error names one, without changing other outcome data.
	 *
	 * Only the exact error code, or one of the complete phrases in any letter case, names one.
	 *
	 * @dataProvider scheduled_renewal_failure_data
	 *
	 * @param string $error_code Provider error code.
	 * @param string $message    Provider error message.
	 * @param bool   $normalizes Whether the failure names an unusable saved payment method.
	 */
	public function test_scheduled_renewal_failure_normalizes_unusable_saved_payment_method( string $error_code, string $message, bool $normalizes ): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$token            = $this->create_card_token( $user_id, 'pm_unusable_method' );
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
		$api_client       = new class( $expected_result ) extends Client {
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

		$outcome                  = $this->create_operation_service( $api_client, $customer_service )->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
		$base      = array(
			PaymentOutcome::DATA_ERROR_CODE    => $error_code,
			PaymentOutcome::DATA_ERROR_MESSAGE => $message,
			WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => $expected_shopper_message,
		);
		$note_keys = array(
			PaymentOutcome::DATA_NOTE             => $expected_note_candidates[0],
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $expected_note_candidates,
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
		);
		$this->assertSame( $base + ( $normalizes ? $note_keys : array() ), $data );
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
	 * Scheduled renewal failures, each with whether it names an unusable saved payment method.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public static function scheduled_renewal_failure_data(): array {
		return array(
			'exact code'                             => array( 'payment_method_no_longer_available', 'Raw provider diagnostic.', true ),
			'must-save phrase'                       => array( '', 'Before retry, MUST SAVE THIS paymentmethod TO A CUSTOMER.', true ),
			'no-such phrase'                         => array( '', 'Upstream: no such paymentmethod: pm_123.', true ),
			'detached phrase'                        => array( '', 'This card is DETACHED FROM A customer.', true ),
			'reuse phrase'                           => array( '', 'This payment method MAY NOT BE USED AGAIN.', true ),
			'exact error code'                       => array( 'payment_method_no_longer_available', 'Unrelated provider diagnostic.', true ),
			'wrong-case error code'                  => array( 'Payment_Method_No_Longer_Available', 'Unrelated provider diagnostic.', false ),
			'must-save phrase with surrounding text' => array( '', 'Upstream says you MUST SAVE THIS paymentmethod TO A CUSTOMER before reuse.', true ),
			'no-such-payment-method phrase'          => array( '', 'prefix: no such paymentmethod: pm_123 suffix', true ),
			'detached phrase with surrounding text'  => array( '', 'The payment method was DETACHED FROM A customer by the platform.', true ),
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
		$saved_token      = $this->create_sepa_token( $user_id, 'pm_sepa_saved' );
		$api_client       = new class() extends Client {
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

				// Reduced fixture of a just-confirmed off-session SEPA Direct Debit PaymentIntent. SEPA is a delayed
				// notification method: once submitted the intent is `processing` and reaches `succeeded` later
				// (Stripe docs, https://docs.stripe.com/payments/sepa-debit/accept-a-payment, "Confirm the
				// PaymentIntent succeeded"). The platform returns the intent with a `charges` list, as recorded
				// for a card in Fixtures/platform-intention-basic-card.json; WooPayments 11.1.0 reads its total_count and
				// data (includes/wc-payment-api/class-wc-payments-api-client.php:2403). The list is left empty
				// and amount, created and metadata are omitted.
				return array(
					'id'             => 'pi_sepa_renewal',
					'status'         => 'processing',
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

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
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

		// A processing intent is the on-hold outcome until Stripe settles the debit (WooPaymentsIntentCodec).
		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( array( 'sepa_debit' ), $api_client->last_request_data['payment_method_types'] );
		$this->assertTrue( $api_client->last_request_data['off_session'] );
	}

	/**
	 * @testdox Charge should plan requested token persistence without creating tokens during transport.
	 */
	public function test_charge_plans_requested_token_persistence_without_writes(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends Client {
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

		$sut     = $this->create_operation_service( $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'pm_request',
				array( 'save_payment_method' => true )
			),
			'key_charge'
		);
		$order   = wc_get_order( $order->get_id() );
		$tokens  = \WC_Payment_Tokens::get_customer_tokens( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID );

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
	 * @testdox Recurring charge should retain the provider outcome until planned token effects run.
	 */
	public function test_recurring_charge_returns_provider_outcome_with_required_token_plan(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order();
		$api_client       = new class() extends Client {
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
			 * @param string $payment_method_id              Provider payment method ID.
			 * @param int    $user_id                        User ID.
			 * @param bool   $include_existing_token_details Whether to retrieve details for an existing token.
			 * @return array{token:\WC_Payment_Token|null,payment_method_details:array<string,mixed>}
			 */
			public function get_or_create_token_and_details_for_user( string $payment_method_id, int $user_id, bool $include_existing_token_details = false ): array {
				unset( $payment_method_id, $user_id, $include_existing_token_details );

				throw new \RuntimeException( 'Token save failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$order->set_customer_id( $user_id );
		$order->save();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_operation_service( $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	}

	/**
	 * @testdox Charge decline envelopes recorded from the local platform (REC-1) map each card code to its outcome.
	 *
	 * The response status and body are the real decline envelope local WPCOM returned for each Stripe test
	 * card, recorded in `Fixtures/platform-intention-declines.json` (REC-1). Every field the client reads
	 * at `class-wc-payments-api-client.php:2853-2872` (11.1.0) exists at the path it reads there, including
	 * a `decline_code` equal to `code` for three of the five pairs and a full `payment_intent` in
	 * `requires_payment_method` status. All five recorded errors are `card_error`, so all five must mark the
	 * fraud meta box `allow` (`gw:1372`). The merchant note carries the platform's `seller_message` only when
	 * the top-level code is `card_declined` (client `api:2910-2914`); the other three codes carry none.
	 * Every recorded decline is a real PaymentIntent dispatch failure with an unambiguous transport error,
	 * so the failed charge outcome must mark it definitive (`_wcpay_definitive_charge_failure`) and retire
	 * the charge idempotency key, giving a shopper retry a fresh key rather than replaying the declined one.
	 * This is native-only idempotency bookkeeping with no client 11.1.0 parity claim: the plugin has no
	 * equivalent per-charge idempotency-key retirement step.
	 * `test_charge_retires_key_on_definitive_failure_without_lifecycle` proves the retirement mechanics
	 * with a synthetic decline; this asserts the classification itself against a real recorded envelope.
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
	public function test_charge_decline_envelope_maps_each_card_code( string $pair, string $expected_error_code, string $expected_message, string $expected_shopper, string $expected_intent_id, string $expected_seller_message, bool $mutate_raw_message = false ): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$account_service = $this->create_account_service( false );
		$recorded        = $this->load_recorded_decline_entry( $pair );

		if ( $mutate_raw_message ) {
			$sentinel                     = 'sentinel raw transport diagnostic, never a shopper-facing catalog string';
			$recorded['error']['message'] = $sentinel;
			$expected_message             = 'Error: ' . $sentinel;
		}

		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array( 'error' => $recorded['error'] ) ),
		);

		$api_client = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_rec1' );

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_rec1' ), 'key_rec1' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $expected_error_code, $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( $expected_message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $expected_shopper, $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
		$this->assertSame( $expected_intent_id, $outcome->get_provider_payment_id(), 'The declined PaymentIntent id must survive onto the failed outcome.' );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertSame( 'allow', ( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ?? array() )['_wcpay_fraud_meta_box_type'] ?? null, "$pair is a card_error decline, so the fraud meta box must show allow." );
		$this->assertTrue( $data['_wcpay_definitive_charge_failure'] ?? false, "$pair's real decline must be classified as definitive so a retry gets a fresh idempotency key." );
		if ( '' !== $expected_seller_message ) {
			$this->assertStringContainsString( esc_html( rtrim( $expected_message, '.' ) ) . '. ' . $expected_seller_message, $data[ PaymentOutcome::DATA_NOTE ] ?? '', "$pair's merchant note must carry the raw message, then the platform's seller_message." );
		} else {
			$recorded_seller_message = esc_html( rtrim( (string) ( $recorded['error']['payment_intent']['charges']['data'][0]['outcome']['seller_message'] ?? '' ), '.' ) );
			$this->assertNotSame( '', $recorded_seller_message, "The $pair recording must carry a seller_message for this check to mean anything." );
			$this->assertStringNotContainsString( $recorded_seller_message, $data[ PaymentOutcome::DATA_NOTE ] ?? '', "$pair is not card_declined, so the merchant note must not carry the platform's seller_message." );
		}
		$this->assertContains( $data[ PaymentOutcome::DATA_NOTE ] ?? null, $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ?? array(), "$pair's note must be one of its own equivalents." );
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
	 * Load one recorded decline entry's HTTP status and `error` object by pair key, from REC-1 (PaymentIntents) or REC-2 (SetupIntents).
	 *
	 * @param string $pair    Fixture pair key.
	 * @param string $fixture Fixture file name.
	 * @return array{http_status:int,error:array<string,mixed>}
	 */
	private function load_recorded_decline_entry( string $pair, string $fixture = 'platform-intention-declines.json' ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/' . $fixture );
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
	 * @testdox A card decline over a fake transport fails the order exactly once with the recorded intent identity.
	 *
	 * T.3 Task 2: joins the two halves T.1 proved separately — the adapter's own
	 * decline-envelope mapping ({@see self::test_charge_decline_envelope_maps_each_card_code})
	 * and {@see \Automattic\WooCommerce\Tests\Internal\Payments\PaymentProcessingServiceTest::test_woopayments_card_decline_fails_order_with_intent_note_and_allow_meta}'s
	 * hand-mirrored outcome — by running the recorded REC-1 decline envelope through the real
	 * {@see PaymentProcessingService::process_checkout_outcome}, real {@see WooPaymentsProvider}, this adapter,
	 * the real {@see Client}, and the real {@see WooPaymentsOrderEffectApplier} against a
	 * FAKEHTTP transport (`Fixtures/platform-intention-declines.json`).
	 *
	 * @dataProvider recorded_checkout_decline_envelope_data
	 *
	 * @param string $pair                REC-1 fixture pair key.
	 * @param string $expected_intent_id  Recorded declined PaymentIntent ID.
	 */
	public function test_card_decline_over_fake_transport_fails_order_once_with_recorded_intent( string $pair, string $expected_intent_id ): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$account_service = $this->create_account_service( false );
		$recorded        = $this->load_recorded_decline_entry( $pair );

		$http_client           = new FakeHttpClient();
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

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_rec1_checkout' ),
			$provider
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $expected_intent_id, $outcome->get_provider_payment_id() );
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
	 * @testdox A card declined at checkout or on a $0 checkout that saves it ($_dataName) leaves its payment method, never a confirmation token, and its customer on the failed order, and Sift order tracking queues an order with a payment method.
	 *
	 * Client 11.1.0 writes both before the charge or the $0 SetupIntent, the payment method only when it is not a
	 * confirmation token (class-wc-payment-gateway-wcpay.php:1672-1677, before :1924-2000), so its declined order keeps
	 * them, and its order-tracking hook queues any card-gateway order that has a payment method (:577, :4485-4524). The
	 * decline is REC-1's recorded generic_decline envelope (Fixtures/platform-intention-declines.json), or REC-2's for the
	 * $0 rows (Fixtures/platform-setup-intent-declines.json, the platform's 402 for 4000 0000 0000 0002 on setup_intents).
	 * Both are reused for the confirmation-token rows as a model, not a recording: the decline carries the intent, whose
	 * last_payment_error or last_setup_error payment_method is the PaymentMethod the request used
	 * (https://docs.stripe.com/api/payment_intents/object#payment_intent_object-last_payment_error). The account's fraud_services carries Sift in the shape client 11.1.0
	 * reads (class-wc-payments-fraud-service.php:99-100).
	 *
	 * @testWith ["the first request", false, "cus_rec1_checkout", false, false]
	 *           ["the request retried for a missing customer", true, "cus_rec1_replaced", false, false]
	 *           ["a confirmation token, the first request", false, "cus_rec1_checkout", true, false]
	 *           ["a confirmation token, the request retried for a missing customer", true, "cus_rec1_replaced", true, false]
	 *           ["a $0 checkout saving a card, the first request", false, "cus_rec1_checkout", false, true]
	 *           ["a $0 checkout saving a card, the request retried for a missing customer", true, "cus_rec1_replaced", false, true]
	 *           ["a $0 checkout saving a card with a confirmation token", false, "cus_rec1_checkout", true, true]
	 *
	 * @param string $label                   Which request is declined.
	 * @param bool   $after_missing_customer  Whether the platform first answers that the customer is missing.
	 * @param string $customer_id             Customer the declined request was sent with.
	 * @param bool   $with_confirmation_token Whether the checkout sent a confirmation token instead of a payment method.
	 * @param bool   $zero_total_saving_card  Whether the order is $0 and saves the card, so a SetupIntent is sent.
	 */
	public function test_declined_checkout_keeps_payment_method_and_customer_for_sift_tracking( string $label, bool $after_missing_customer, string $customer_id, bool $with_confirmation_token, bool $zero_total_saving_card ): void {
		unset( $label );
		$order           = $this->create_woopayments_order( $zero_total_saving_card ? '0.00' : '10.00' );
		$account_service = $this->create_account_service( false, array(), array( 'fraud_services' => array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) ) );
		$recorded        = $zero_total_saving_card ? $this->load_recorded_decline_entry( 'generic_decline', 'platform-setup-intent-declines.json' ) : $this->load_recorded_decline_entry( 'generic_decline' );
		$payment_method  = (string) ( $zero_total_saving_card ? $recorded['error']['setup_intent']['last_setup_error'] : $recorded['error']['payment_intent']['last_payment_error'] )['payment_method']['id'];
		$provider        = $this->create_provider_declining_checkout( $recorded, $account_service, $after_missing_customer );
		$scheduler       = new RecordingActionSchedulerService();
		$fraud_service   = new WooPaymentsFraudService();
		$fraud_service->init( $account_service, $this->createMock( WooPaymentsCustomerService::class ), $this->createMock( WooPaymentsSessionService::class ), $this->createMock( Client::class ) );
		$tracking = new WooPaymentsFraudOrderTrackingService();
		$tracking->init( $scheduler, $this->createMock( Client::class ), $account_service, $fraud_service );
		add_action( 'woocommerce_update_order', array( $tracking, 'handle_woocommerce_update_order' ), 10, 2 );

		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				$with_confirmation_token ? 'ctoken_rec1_express' : $payment_method,
				$zero_total_saving_card ? array(
					'payment_token'       => 'new',
					'save_payment_method' => true,
				) : array()
			),
			$provider
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( array( $with_confirmation_token ? '' : $payment_method, $customer_id ), array( $order->get_meta( '_payment_method_id', true ), $order->get_meta( '_stripe_customer_id', true ) ) );
		$this->assertSame(
			! $with_confirmation_token,
			in_array(
				array( 'wcpay_track_new_order', array( 'order_id' => $order->get_id() ) ),
				array_map( static fn( array $job ): array => array( $job['hook'], $job['args'] ), $scheduler->scheduled_jobs ),
				true
			),
			'Only a declined order with a payment method is queued for Sift.'
		);
	}

	/**
	 * @testdox The payment_intent.payment_failed webhook for a card declined at checkout leaves the failed order with its one failure note.
	 *
	 * Client 11.1.0's mark_payment_failed() adds nothing to an order that is already failed (class-wc-payments-order-service.php:469-473).
	 * The event object is the PaymentIntent of REC-1's recorded generic_decline envelope (Fixtures/platform-intention-declines.json),
	 * the object Stripe sends with payment_intent.payment_failed.
	 */
	public function test_payment_failed_webhook_after_a_checkout_decline_adds_no_second_failure_note(): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$account_service = $this->create_account_service( false );
		$recorded        = $this->load_recorded_decline_entry( 'generic_decline' );
		$intent          = $recorded['error']['payment_intent'];
		$provider        = $this->create_provider_declining_checkout( $recorded, $account_service );
		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, (string) $intent['last_payment_error']['payment_method']['id'] ),
			$provider
		);

		wc_get_container()->get( WooPaymentsPaymentIntentEventHandler::class )->process( 'payment_intent.payment_failed', $intent );

		$order        = wc_get_order( $order->get_id() );
		$failed_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => str_contains( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertCount( 1, $failed_notes );
	}

	/**
	 * Build the real provider over a transport that answers a recorded REC-1 decline, for a payer whose customer is cus_rec1_checkout.
	 *
	 * @param array{http_status:int,error:array<string,mixed>} $recorded               Recorded decline entry.
	 * @param WooPaymentsAccountService                        $account_service        Account service.
	 * @param bool                                             $after_missing_customer Whether the platform first answers that the customer is missing, so the decline answers the retry with cus_rec1_replaced.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_declining_checkout( array $recorded, WooPaymentsAccountService $account_service, bool $after_missing_customer = false ): WooPaymentsProvider {
		$decline     = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array( 'error' => $recorded['error'] ) ),
		);
		$http_client = new FakeHttpClient();
		if ( $after_missing_customer ) {
			$http_client->responses = array( self::stripe_no_such_customer(), $decline );
		} else {
			$http_client->response = $decline;
		}
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order', 'replace_missing_customer_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_rec1_checkout' );
		$customer_service->method( 'replace_missing_customer_for_order' )->willReturn( 'cus_rec1_replaced' );

		return $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );
	}

	/**
	 * @testdox An Afterpay checkout with no usable shipping or billing address fails the order with the WooPayments extension's notice and note, and sends nothing.
	 *
	 * Client 11.1.0 throws Invalid_Address_Exception before any request (class-wc-payment-gateway-wcpay.php:5333-5340); the
	 * process_payment() catch fails the order, writes "A payment of %1$s <strong>failed</strong> to complete with the
	 * following message: <code>%2$s</code>." with the message's final period trimmed (:1352-1394), and shows the message
	 * itself to the shopper through get_filtered_error_message() (:1418; class-wc-payments-utils.php:769-770) (review 36 F5).
	 */
	public function test_afterpay_checkout_without_a_usable_address_fails_with_the_woopayments_extension_notice_and_note(): void {
		$order = $this->create_woopayments_order( '80.00' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'afterpay_clearpay' );
		$order->set_currency( 'USD' );
		$order->save();
		$http_client      = new FakeHttpClient();
		$account_service  = $this->create_account_service( false );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_afterpay' );
		$provider   = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );
		$definition = ( new PaymentMethodRegistry() )->get( 'afterpay_clearpay' );
		$this->assertNotNull( $definition );
		$gateway = new WooPaymentsGateway( $definition );
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
	 * @testdox A checkout of an order whose attached payment is disputed is refused with the shopper notice, keeps the order pending and charges nothing, when $_dataName.
	 *
	 * The gateway runs with the real duplicate-payment guard and the real provider over one recording transport. The guard
	 * refuses a disputed attached intent with ERROR_DISPUTED_INTENT; the gateway shows the refusal and fails the order only
	 * for an amount mismatch (WooPaymentsGateway::process_order_payment()). An order whose intent status says
	 * succeeded is not answered as paid: the order is unpaid and the shopper must see why.
	 *
	 * @testWith ["the payment was never applied to the order", "requires_action"]
	 *           ["the merchant set the paid order back to pending", "succeeded"]
	 *
	 * @param string $label            Case description.
	 * @param string $intention_status The order's `_intention_status`.
	 */
	public function test_checkout_of_an_order_whose_attached_payment_is_disputed_charges_nothing( string $label, string $intention_status ): void {
		unset( $label );
		$order = $this->create_order_left_by_a_completed_challenge();
		$order->update_meta_data( '_intention_status', $intention_status );
		$order->save();
		$disputed_charge = array(
			'disputed' => true,
			'dispute'  => 'dp_1UJjK4BzWlxcwgpPDisputed',
		);

		$http_client              = new FakeHttpClient();
		$http_client->responses[] = self::http_json( 200, $this->attached_intent_given_back( $order, $disputed_charge ) );
		$gateway                  = $this->create_gateway_with_real_duplicate_guard( $http_client );

		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( array( "This order's payment is under review. Please contact the store." ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( 'pending', $order->get_status(), 'A disputed attached payment must not fail the order.' );
		$this->assertSame( array( 'GET intentions/' . self::CHALLENGE_COMPLETED_INTENT_ID ), self::platform_calls( $http_client ), 'Only the attached intent may be read; nothing may be charged.' );
		$notes = array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), array_values( array_filter( $notes, static fn( string $note ): bool => str_contains( $note, 'to Failed' ) ) ), 'No failed status change may be noted.' );
		$this->assertSame( array(), array_values( array_filter( $notes, static fn( string $note ): bool => str_starts_with( $note, 'Payment succeeded' ) ) ), 'No payment succeeded in this request.' );
	}

	/**
	 * @testdox A checkout of an order whose attached payment is disputed is still refused, and charges nothing, when the dispute note fails and $_dataName.
	 *
	 * The note is told apart from the refusal: re-reading the order for it throws here, an Exception or a PHP Error, and the
	 * refusal must still reach the shopper rather than the gateway's catch, which answers as paid for a succeeded intent and
	 * fails the order otherwise, and which a PHP Error passes through.
	 *
	 * @testWith ["the payment was never applied to the order", "requires_action", "RuntimeException"]
	 *           ["the merchant set the paid order back to pending", "succeeded", "RuntimeException"]
	 *           ["the payment was never applied to the order and the read fails with a PHP Error", "requires_action", "TypeError"]
	 *           ["the merchant set the paid order back to pending and the read fails with a PHP Error", "succeeded", "TypeError"]
	 *
	 * @param string $label            Case description.
	 * @param string $intention_status The order's `_intention_status`.
	 * @param string $thrown           Class the order read throws.
	 */
	public function test_checkout_keeps_the_dispute_refusal_when_its_note_fails( string $label, string $intention_status, string $thrown ): void {
		unset( $label );
		$order = $this->create_order_left_by_a_completed_challenge();
		$order->update_meta_data( '_intention_status', $intention_status );
		$order->save();
		$disputed_charge = array(
			'disputed' => true,
			'dispute'  => 'dp_1UJjK4BzWlxcwgpPDisputed',
		);
		$failing_reads   = new class( $thrown ) extends OrderPaymentLifecycleService {
			/**
			 * Class the order read throws.
			 *
			 * @var string
			 */
			private string $thrown;

			/**
			 * Set the class the order read throws.
			 *
			 * @param string $thrown Class the order read throws.
			 */
			public function __construct( string $thrown ) {
				$this->thrown = $thrown;
			}

			/**
			 * Fail the order read the dispute note makes.
			 *
			 * @param WC_Order $order Order.
			 * @throws \Throwable Always.
			 */
			public function get_fresh_order_from_data_store( WC_Order $order ): WC_Order {
				unset( $order );
				throw new $this->thrown( 'Order read failed.' );
			}
		};

		$http_client              = new FakeHttpClient();
		$http_client->responses[] = self::http_json( 200, $this->attached_intent_given_back( $order, $disputed_charge ) );
		$gateway                  = $this->create_gateway_with_real_duplicate_guard( $http_client, $failing_reads );
		self::enable_woopayments_debug_logging();
		$logger = RecordingWcLogger::install();

		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertSame( array( "This order's payment is under review. Please contact the store." ), array_column( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( 'pending', $order->get_status(), 'The order status must not change.' );
		$this->assertSame( array( 'GET intentions/' . self::CHALLENGE_COMPLETED_INTENT_ID ), self::platform_calls( $http_client ), 'Only the attached intent may be read; nothing may be charged.' );
		$context = $this->get_logged_context( $logger, 'Failed to note the disputed payment attached to the order.' );
		$this->assertSame( array( $order->get_id(), self::CHALLENGE_COMPLETED_INTENT_ID, $thrown ), array( $context['order_id'], $context['intent_id'], $context['exception'] ) );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		$token      = $store->claim( $order, $vocabulary, 'refund_key', 'refund' );
		$this->assertIsString( $token, 'The note must release the order payment lock it took.' );
		$store->release( $order, $vocabulary, $token );
	}

	/**
	 * @testdox A checkout of a payable order whose attached payment was fully refunded charges the shopper again and pays the order with the new intent.
	 *
	 * The old intent keeps `succeeded` after the refund. The new create-and-confirm is answered with the recorded successful
	 * card payment (`Fixtures/platform-intention-basic-card.json`, pair `basic_card_usd_create_and_confirm`, POST intentions).
	 */
	public function test_checkout_of_a_payable_order_whose_attached_payment_was_refunded_charges_again(): void {
		$order           = $this->create_order_left_by_a_completed_challenge();
		$new_charge      = $this->load_recorded_intent_entry( 'platform-intention-basic-card.json', 'basic_card_usd_create_and_confirm' );
		$refunded_charge = array(
			'refunded'        => true,
			'amount_refunded' => 1099,
		);

		$http_client              = new FakeHttpClient();
		$http_client->responses[] = self::http_json( 200, $this->attached_intent_given_back( $order, $refunded_charge ) );
		$http_client->responses[] = self::http_json( $new_charge['http_status'], $new_charge['body'] );
		$gateway                  = $this->create_gateway_with_real_duplicate_guard( $http_client );

		$_POST['wcpay-payment-method'] = 'pm_card_visa';

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( array( 'GET intentions/' . self::CHALLENGE_COMPLETED_INTENT_ID, 'POST intentions' ), self::platform_calls( $http_client ), 'The refunded payment must be followed by one new charge.' );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'pi_3UJhO2BzWlxcwgpP1BndTguC', $order->get_meta( '_intent_id', true ), 'The order must be paid by the new intent.' );
		$this->assertSame( array(), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * PaymentIntent of the recorded 3DS checkout whose challenge the shopper completed (`Fixtures/platform-3ds-challenge-results.json`).
	 */
	private const CHALLENGE_COMPLETED_INTENT_ID = 'pi_3UJjK4BzWlxcwgpP0xWpBE77';

	/**
	 * Create the order a 3DS checkout leaves when the shopper completed the challenge but the return never reached the store.
	 *
	 * Still pending, holding the intent and the `requires_action` status the checkout answer recorded, for the recorded
	 * USD 10.99 charge.
	 *
	 * @return WC_Order
	 */
	private function create_order_left_by_a_completed_challenge(): WC_Order {
		$order = $this->create_woopayments_order( '10.99' );
		$order->set_currency( 'USD' );
		$order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$order->set_total( '10.99' );
		$order->update_meta_data( '_intent_id', self::CHALLENGE_COMPLETED_INTENT_ID );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->save();

		return $order;
	}

	/**
	 * The platform's GET intentions/{id} answer for the order's attached intent after its charge was given back.
	 *
	 * The recorded answer for the completed challenge (`Fixtures/platform-3ds-challenge-results.json`, pair
	 * `classic_checkout_challenge_completed`: status `succeeded`, one charge in `charges.data`), its metadata pointed at this
	 * order, with the charge fields Stripe sets on a dispute (`disputed`, `dispute`) or a full refund (`refunded`,
	 * `amount_refunded`; Stripe API reference, "The Charge object"). The intent keeps `succeeded` either way.
	 *
	 * @param WC_Order            $order         Order the intent is attached to.
	 * @param array<string,mixed> $charge_fields Charge fields after the money was given back.
	 * @return array<string,mixed>
	 */
	private function attached_intent_given_back( WC_Order $order, array $charge_fields ): array {
		$intent                             = $this->load_recorded_intent_entry( 'platform-3ds-challenge-results.json', 'classic_checkout_challenge_completed' )['body'];
		$intent['metadata']['order_id']     = (string) $order->get_id();
		$intent['metadata']['order_number'] = (string) $order->get_order_number();
		$intent['charges']['data'][0]       = array_merge( $intent['charges']['data'][0], $charge_fields );
		$this->assertSame( self::CHALLENGE_COMPLETED_INTENT_ID, $intent['id'] );
		$this->assertSame( 'succeeded', $intent['status'] );

		return $intent;
	}

	/**
	 * Build the card gateway with the real duplicate-payment guard, the real processing service and the real provider, all
	 * sending their platform requests through one recording transport.
	 *
	 * @param FakeHttpClient                    $http_client     Recording transport.
	 * @param OrderPaymentLifecycleService|null $guard_lifecycle The guard's lifecycle service; the container's when null.
	 * @return WooPaymentsGateway
	 */
	private function create_gateway_with_real_duplicate_guard( FakeHttpClient $http_client, ?OrderPaymentLifecycleService $guard_lifecycle = null ): WooPaymentsGateway {
		$account_service  = $this->create_account_service( false );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_UsIeTbmGHPc9jY' );
		$provider   = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service );
		$api_client = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$guard = new WooPaymentsDuplicatePaymentPreventionService();
		$guard->init(
			$api_client,
			$guard_lifecycle ?? wc_get_container()->get( OrderPaymentLifecycleService::class )
		);

		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider, null, null, null, null, null, null, null, $guard );

		return $gateway;
	}

	/**
	 * Get the platform requests as "METHOD path", the path relative to the site's WooPayments root, without its query.
	 *
	 * @param FakeHttpClient $http_client Recording transport.
	 * @return string[]
	 */
	private static function platform_calls( FakeHttpClient $http_client ): array {
		return array_map(
			static fn( array $request ): string => $request['method'] . ' ' . preg_replace( '#^/sites/\d+/wcpay/|\?.*$#', '', (string) $request['path'] ),
			$http_client->requests
		);
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
	 * @testdox Charge decoding should plan card display effects without mutating the order.
	 *
	 * T.3 Task 4: RECORD swap. The response is now REC-3DS-1's new-card
	 * variant (`Fixtures/platform-intention-3ds-requires-action.json`, pair `new_card_requires_action`), real
	 * from the local platform. A real `requires_action` PaymentIntent carries no charge yet
	 * (`charges.total_count 0`): unlike the earlier hand-written stub, which put a charge on the
	 * requires_action intent, this outcome carries no `charge_id` at all —
	 * `WooPaymentsIntentCodec::outcome_from_intention()` only sets that key when `latest_charge()`
	 * finds one (`WooPaymentsIntentCodec.php:47-49`).
	 */
	public function test_charge_returns_display_effect_plan_without_mutating_order(): void {
		$order                 = $this->create_woopayments_order();
		$recorded              = $this->load_recorded_intent_entry( 'platform-intention-3ds-requires-action.json', 'new_card_requires_action' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_request' ), 'key_charge' );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertArrayNotHasKey( 'charge_id', $outcome->get_data(), 'REC-3DS-1 has no charge yet on a requires_action intent.' );
		$this->assertArrayNotHasKey( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertStringStartsWith( '#wcpay-confirm-pi:' . $order->get_id() . ':' . $recorded['body']['client_secret'] . ':', $outcome->get_redirect_url() );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( '', $order->get_payment_method_title() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $outcome->get_effect_plan()->get_type() );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Charge uses the intent WooPay already confirmed instead of creating a second one.
	 */
	public function test_charge_uses_the_woopay_intent_instead_of_creating_one(): void {
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
	 * @testdox Charge refuses a WooPay intent whose metadata names another order, with the WooPayments extension's message.
	 */
	public function test_charge_refuses_a_woopay_intent_confirmed_for_another_order(): void {
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
			$outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null,
			'Client 11.1.0 throws Order_ID_Mismatch_Exception with this message and shows it to the shopper.'
		);
		$this->assertStringContainsString( 'WooPayMeta: intent_meta_order_id: ' . $other_id, (string) ( $outcome->get_data()[ PaymentOutcome::DATA_NOTE ] ?? '' ), 'Client 11.1.0 records the refusal in the failed-payment order note.' );
	}

	/**
	 * @testdox A $_dataName fails without creating an intent when the WooPay intent cannot be read.
	 *
	 * Client 11.1.0 lets the Get_Intention or Get_Setup_Intention failure fail the payment and never falls back to a new
	 * intent (class-wc-payment-gateway-wcpay.php:1792-1812, 1926-1932).
	 *
	 * @dataProvider provider_unreadable_woopay_intents
	 *
	 * @param string   $total          Order total.
	 * @param string   $intent_id      Intent id WooPay sent.
	 * @param string[] $payment_reads  Expected PaymentIntent reads.
	 * @param string[] $setup_reads    Expected SetupIntent reads.
	 */
	public function test_unreadable_woopay_intent_fails_without_creating_one( string $total, string $intent_id, array $payment_reads, array $setup_reads ): void {
		$order      = $this->create_woopayments_order( $total );
		$api_client = $this->create_woopay_intent_api_client(
			array(),
			array(),
			new ApiException( "No such intent: '$intent_id'", 'resource_missing', 404, 'invalid_request_error' )
		);

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, $intent_id );

		$this->assertSame( $payment_reads, $api_client->payment_intent_reads );
		$this->assertSame( $setup_reads, $api_client->setup_intent_reads );
		$this->assertSame( 0, $api_client->creates );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'resource_missing', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
	}

	/**
	 * A charge reads the WooPay PaymentIntent; a zero-total checkout reads the WooPay SetupIntent.
	 *
	 * @return array<string,array{0:string,1:string,2:string[],3:string[]}>
	 */
	public function provider_unreadable_woopay_intents(): array {
		return array(
			'charge'              => array( '10.99', 'pi_missing', array( 'pi_missing' ), array() ),
			'zero-total checkout' => array( '0.00', 'seti_missing', array(), array( 'seti_missing' ) ),
		);
	}

	/**
	 * @testdox Charge refuses a malformed WooPay intent id before any request, with the WooPayments extension's message.
	 */
	public function test_charge_refuses_a_malformed_woopay_intent_id_before_reading_it(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client( array( 'id' => 'abc123' ) );

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, 'abc123' );

		$this->assertSame( array(), $api_client->payment_intent_reads, 'Client 11.1.0 Get_Intention validates the id before sending (class-request.php:669-708).' );
		$this->assertSame( 0, $api_client->creates );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'wcpay_core_invalid_request_parameter_stripe_id', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'abc123 is not a valid Stripe identifier', $outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
	}

	/**
	 * @testdox Charge treats a WooPay intent id of "0" as absent, as the client's empty() check does.
	 */
	public function test_charge_treats_a_zero_woopay_intent_id_as_absent(): void {
		$order      = $this->create_woopayments_order( '10.99' );
		$api_client = $this->create_woopay_intent_api_client( array() );

		$outcome = $this->charge_with_woopay_intent( $order, $api_client, '0' );

		$this->assertSame( array(), $api_client->payment_intent_reads );
		$this->assertSame( 1, $api_client->creates, 'Client 11.1.0 guards the fetch with ! empty() (class-wc-payment-gateway-wcpay.php:1797-1801) and creates the intent instead.' );
		$this->assertSame( 'pi_second', $outcome->get_provider_payment_id() );
	}

	/**
	 * @testdox Zero-total checkout uses the setup intent WooPay already confirmed instead of creating one.
	 */
	public function test_setup_intent_uses_the_woopay_setup_intent_instead_of_creating_one(): void {
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
	 * @testdox Zero-total checkout refuses a WooPay setup intent whose metadata names another order.
	 */
	public function test_setup_intent_refuses_a_woopay_setup_intent_for_another_order(): void {
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
		$this->assertSame( 'We\'re not able to process this payment. Please try again later.', $outcome->get_data()[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
	}

	/**
	 * @testdox Charge confirms a zero-total checkout paid with a saved card without any intent, as client 11.1.0 does.
	 *
	 * Client gw:1688 skips the intent when the order needs no payment and no new payment method is saved; a saved token is never saved again.
	 */
	public function test_charge_confirms_zero_total_checkout_with_saved_card_without_intent(): void {
		$user_id          = $this->factory()->user->create();
		$order            = $this->create_woopayments_order( '0.00' );
		$saved_token      = $this->create_card_token( $user_id, 'pm_zero' );
		$api_client       = new class() extends Client {
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

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		$sut     = $this->create_operation_service( $api_client, $customer_service, $token_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * (`set_up_payment_method_for_zero_total`), not a PaymentIntent. When that SetupIntent comes back
	 * `requires_action` (a 3DS challenge on the new card the free trial saves; a saved card gets no intent at $0, client
	 * gw:1688), the frontend confirmation hash must read
	 * `#wcpay-confirm-si:...`, matching client 11.1.0's own `$payment_needed ? 'pi' : 'si'` branch
	 * (`gw:2111`, `class-wc-payment-gateway-wcpay.php`): a SetupIntent has no payment to confirm, so the
	 * frontend must call `stripe.confirmSetup()`, not `confirmPayment()`. Only the codec unit test
	 * (`WooPaymentsIntentCodecTest::test_confirmation_redirect_uses_explicit_nonce`) and the effect-plan
	 * test (`WooPaymentsOrderEffectApplierTest::test_setup_intent_effects_persist_provider_references`)
	 * covered pieces of this before; neither goes through the adapter's own intent-type wiring.
	 *
	 * T.3 Task 4: RECORD swap. The response is now REC-3DS-4's recorded envelope
	 * (`Fixtures/platform-setup-intent-requires-action.json`, pair `setup_intent_requires_action`), the
	 * same real My Account add-payment-method `requires_action` SetupIntent REC-2 recorded its
	 * declines from.
	 */
	public function test_zero_total_setup_intent_requiring_action_returns_si_confirmation_redirect(): void {
		$user_id               = $this->factory()->user->create();
		$order                 = $this->create_woopayments_order( '0.00' );
		$recorded              = $this->load_recorded_intent_entry( 'platform-setup-intent-requires-action.json', 'setup_intent_requires_action' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$order->set_customer_id( $user_id );
		$order->save();

		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( (string) $recorded['body']['customer'] );

		$sut     = $this->create_operation_service( $api_client, $customer_service, null, $account_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @testdox Zero-total card checkout should flag platform-created payment methods for WooPayments.
	 */
	public function test_zero_total_charge_flags_platform_created_payment_methods_for_wcpay(): void {
		$order            = $this->create_woopayments_order( '0.00' );
		$api_client       = new class() extends Client {
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

		$sut     = $this->create_operation_service( $api_client, $customer_service );
		$outcome = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
		$api_client       = new class() extends Client {
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

		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $order->get_id() => array( 'renewal' => array( $subscription->get_id() ) ) );

		$customer_service->expects( $this->once() )
			->method( 'get_or_create_customer_id_for_order' )
			->willReturn( 'cus_native' );

		$sut          = $this->create_operation_service( $api_client, $customer_service, $token_service );
		$outcome      = $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'pm_zero',
				array( 'save_payment_method' => true ),
				array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true )
			),
			'key_setup'
		);
		$order        = wc_get_order( $order->get_id() );
		$tokens       = \WC_Payment_Tokens::get_customer_tokens( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
	 * @testdox Refund should go through the platform API.
	 */
	public function test_refund_goes_through_the_platform_api(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
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

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' );

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
	}

	/**
	 * @testdox A refund over a fake transport persists the provider refund identity, status, and exactly one note.
	 *
	 * End to end through the production wiring: this test calls the real
	 * {@see PaymentProcessingService::process_refund} directly (no gateway
	 * boundary in between), which calls the real {@see WooPaymentsProvider},
	 * this adapter, the real {@see Client}, and the real
	 * {@see WooPaymentsOrderEffectApplier} against a FAKEHTTP transport queued
	 * with the exact refund response REC-5a R-a recorded from local WPCOM
	 * (`Fixtures/platform-refunds.json`). The request body sent over that
	 * transport is compared to the recorded request. The recording settles F9:
	 * the platform's refund `balance_transaction` is a bare string id, not the
	 * expanded object some native sync fixtures use, and
	 * {@see \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec::balance_transaction_id()}
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
	public function test_refund_over_fake_transport_persists_refund_identity_status_and_one_note( string $pair, string $currency, string $amount, int $amount_minor, string $charge_id, string $refund_id, string $reason, ?string $expected_wire_reason ): void {
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
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( true );
		$provider              = $this->create_provider_over_fake_transport( $http_client, $account_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, (float) $amount, $reason ),
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
		$this->assertSame( $http_client->last_headers['Idempotency-Key'] ?? '', $sent['metadata']['refund_attempt'] ?? null, "The $pair request must carry its key as the refund_attempt metadata." );

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
	public function test_refund_calls_send_distinct_idempotency_keys_over_fake_transport(): void {
		$order = $this->create_woopayments_order();
		$order->set_currency( 'USD' );
		$order->update_meta_data( '_charge_id', 'ch_refund_keys' );
		$order->save();
		$manual_refund = $this->create_local_refund_row( $order );
		$manual_refund->set_date_created( time() - DAY_IN_SECONDS );
		$manual_refund->save();

		$http_client            = new FakeHttpClient();
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
		$context                = PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' );

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
	 * @testdox A refund call after every transport try timed out keeps its key, and the same-amount retry links the refund the lost call made, with no second request.
	 *
	 * Register row 458: the platform refunded but the store's request timed out, WooCommerce deleted the refund row, and
	 * the refund webhook was refused by the lock the call held. Before the fix the retry sent a second refund under a
	 * fresh key. The lookup reads the charge's refunds (`Fixtures/platform-refund-list.json`, the 5.55 refund carrying
	 * the lost call's key as its marker, which the recording shows is always the key it was sent under).
	 *
	 * @dataProvider ambiguous_refund_answer_data
	 *
	 * @param array<int,mixed> $answers   Transport answers to the first call.
	 * @param int              $post_count POST attempts the first call makes.
	 */
	public function test_refund_retry_after_an_ambiguous_answer_links_the_earlier_refund_without_a_second_request( array $answers, int $post_count ): void {
		$order                  = $this->create_refund_hold_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = $answers;
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $first_result ) = $this->run_refund( $provider, $order, 5.55 );

		$held_key               = (string) ( $http_client->requests[0]['headers']['Idempotency-Key'] ?? '' );
		$hold                   = self::refund_hold_of( $order );
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list_with_marker( self::F458_REFUND_555, $held_key ) ) );

		list( $second_result, $second_row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertWPError( $first_result );
		$this->assertNotSame( '', $held_key );
		$this->assertIsArray( $hold, 'An ambiguous answer must keep the key on the order.' );
		$this->assertSame( $held_key, $hold['key'] ?? null );
		$this->assertSame( 555, $hold['amount'] ?? null );
		$this->assertTrue( $second_result );
		$this->assertSame( array_merge( array_fill( 0, $post_count, 'POST refunds ' . $held_key ), array( self::refund_list_trail() ) ), self::request_trail( $http_client ), 'The retry must read the charge\'s refunds and send no second refund.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $second_row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ), 'Linking the earlier refund settles the hold.' );
	}

	/**
	 * Ambiguous answers to a refund request: a timeout on every transport try (three retries), and a server error.
	 *
	 * @return array<string,array{array<int,mixed>,int}>
	 */
	public function ambiguous_refund_answer_data(): array {
		$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 70001 milliseconds' );

		return array(
			'timeout on every try' => array( array( $timeout, $timeout, $timeout, $timeout ), 4 ),
			'server error'         => array( array( self::stripe_api_error( 500 ) ), 1 ),
		);
	}

	/**
	 * @testdox A same-amount retry inside 300 s of the ambiguous answer, with nothing listed yet, resends the earlier request byte for byte under the held key.
	 *
	 * The earlier request may still be running, so a new key could refund twice; the same key makes Stripe run it once
	 * or replay it (`Fixtures/platform-refund-errors.json`, pair `reused_key_identical_params_replays_refund`). The retry
	 * carries another reason, which must not reach the body.
	 */
	public function test_refund_retry_inside_the_held_key_window_resends_the_earlier_request_under_its_key(): void {
		$order                  = $this->create_refund_hold_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::stripe_api_error( 503 ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		$this->run_refund( $provider, $order, 5.55 );
		$held_key               = (string) ( $http_client->requests[0]['headers']['Idempotency-Key'] ?? '' );
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_held_key_retry', $held_key, 555 ) ),
		);

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55, 'Second try' );

		$this->assertTrue( $result );
		$this->assertSame( array( 'POST refunds ' . $held_key, self::refund_list_trail(), 'POST refunds ' . $held_key ), self::request_trail( $http_client ) );
		$this->assertSame( $http_client->requests[0]['body'], $http_client->requests[2]['body'], 'The held-key retry must resend the earlier body byte for byte.' );
		$this->assertSame( 're_f458_held_key_retry', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox When the line for a settled earlier refund fails to log ($_dataName), the hold stays and the next call links that refund with no second refund.
	 *
	 * The line is written whatever the logging setting, so a log callback that throws ends the call as a failure and
	 * WooCommerce deletes the refund row. The refund went through at Stripe, so a hold deleted before the line would let
	 * the next call send a second refund under a fresh key.
	 *
	 * @dataProvider provide_settled_refund_branches
	 *
	 * @param string $branch How the earlier refund is settled.
	 */
	public function test_refund_hold_stays_when_the_settled_line_fails_to_log( string $branch ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		$order                  = $this->create_refund_hold_order();
		$held_key               = 'link' === $branch ? self::F458_KEY_555 : 'key_f458_unlisted';
		$listed                 = self::http_json( 200, $this->recorded_refund_list_with_marker( self::F458_REFUND_555, $held_key ) );
		$http_client            = new FakeHttpClient();
		$http_client->responses = 'link' === $branch
			? array( $listed )
			: array( self::http_json( 200, $this->recorded_refund_list() ), self::http_json( 200, $this->recorded_refund_answer( self::F458_REFUND_555, $held_key, 555 ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );
		$this->seed_refund_hold( $order, array( 'key' => $held_key ) );
		$throws = 0;
		$filter = static function ( $message ) use ( &$throws ) {
			if ( false !== strpos( (string) $message, 'is settled and its record is deleted' ) ) {
				++$throws;
				throw new \RuntimeException( 'Log write failed.' );
			}

			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter );

		list( $failed ) = $this->run_refund( $provider, $order, 5.55 );
		remove_filter( 'woocommerce_logger_log_message', $filter );
		$kept                   = self::refund_hold_of( $order );
		$http_client->responses = array( $listed );
		list( $linked, $row )   = $this->run_refund( $provider, $order, 5.55 );

		$this->assertSame( 1, $throws, 'The settled line was written once.' );
		$this->assertWPError( $failed );
		$this->assertSame( $held_key, is_array( $kept ) ? ( $kept['key'] ?? null ) : $kept, 'The hold must outlive a failing log line.' );
		$this->assertTrue( $linked );
		$this->assertSame( 'link' === $branch ? array( self::refund_list_trail(), self::refund_list_trail() ) : array( self::refund_list_trail(), 'POST refunds ' . $held_key, self::refund_list_trail() ), self::request_trail( $http_client ), 'The next call must read the refunds and send no second refund.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Ways an earlier refund is settled with its money already moved: a listed refund for the same amount, and a held-key
	 * retry that returns a refund.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_settled_refund_branches(): array {
		return array(
			'linked from the refund list' => array( 'link' ),
			'held-key retry'              => array( 'held' ),
		);
	}

	/**
	 * @testdox Inside 300 s with nothing listed, a call for another amount is refused with no request and the hold stays.
	 */
	public function test_refund_for_another_amount_inside_the_held_key_window_is_refused_as_settling(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold( $order, array( 'key' => 'key_f458_unlisted' ) );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_SETTLING, $result->get_error_code() );
		$this->assertSame( 'An earlier refund attempt for this order is still being confirmed. No new refund was sent. Please try again in a few minutes.', $result->get_error_message() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox When the charge's refunds cannot be read, the refund is refused with no request, the hold and the refund status stay, and the next call with a good read proceeds.
	 *
	 * Driven through the gateway, which must not record the refusal as a failed refund: no failure note, and the order's
	 * refund status stays as it was.
	 *
	 * @dataProvider failed_refund_lookup_data
	 *
	 * @param mixed $answer Platform answer to the list read.
	 */
	public function test_refund_lookup_failure_refuses_and_keeps_the_hold( $answer ): void {
		$order = $this->create_refund_hold_order();
		$order->update_meta_data( '_wcpay_refund_status', 'successful' );
		$order->save();
		$hold                   = $this->seed_refund_hold( $order );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( $answer );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_gateway_refund( $provider, $order, 5.55 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_CHECK_FAILED, $result->get_error_code() );
		$this->assertSame( 'An earlier refund attempt for this order may have gone through, and it could not be checked just now. No new refund was sent. Please try again in a few minutes.', $result->get_error_message() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund may be sent while the earlier attempt is unknown.' );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( array(), self::note_texts_containing( $order, 'failed to complete' ) );

		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );

		list( $next_result, $row ) = $this->run_gateway_refund( $provider, $order, 5.55 );

		$this->assertTrue( $next_result );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Failed reads of the charge's refunds: a transport failure (a GET is never retried), an error status (the recorded
	 * refund error envelope, `Fixtures/platform-refund-errors.json`), and an answer with no list.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function failed_refund_lookup_data(): array {
		$error = $this->load_recorded_refund_error( 'refund_exceeds_charge' );

		return array(
			'transport failure' => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 70001 milliseconds' ) ),
			'error status'      => array(
				array(
					'response' => array( 'code' => $error['response']['http_status'] ),
					'headers'  => array( 'content-type' => $error['response']['content_type'] ),
					'body'     => $error['response']['raw_body'],
				),
			),
			'no list'           => array( self::http_json( 200, array( 'object' => 'list' ) ) ),
		);
	}

	/**
	 * @testdox An incomplete page of the charge's refunds without the earlier attempt refuses the refund and keeps the hold.
	 *
	 * The recorded limit-2 page (`list_limit_2_has_more`) leaves out the 11.11 refund, whose key the hold names.
	 */
	public function test_refund_lookup_on_an_incomplete_page_refuses_and_keeps_the_hold(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold(
			$order,
			array(
				'key'    => self::F458_KEY_1111,
				'amount' => 1111,
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list( 'list_limit_2_has_more' ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 11.11 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_CHECK_FAILED, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox When $_dataName, the hold is cleared and the call lists the charge's refunds, then sends under a fresh key.
	 *
	 * After 300 s with nothing listed the earlier request cannot still be running. A hold that found its refund is settled
	 * by linking it or by its webhook row (monitor ruling 2026-10-10 11:05).
	 *
	 * @dataProvider unsuccessful_earlier_refund_data
	 *
	 * @param array<string,mixed> $overrides    Hold fields: a hold that found its refund reaches the refund by its ID too.
	 * @param array<string,mixed> $first_refund Fields to change on the first listed refund, the earlier 5.55 attempt.
	 * @param callable            $setup        Called with the test and the order after the hold is stored.
	 * @param float               $amount       Amount of this call.
	 * @param string              $held_key     Key the hold names, which the call must not send under.
	 */
	public function test_refund_whose_earlier_attempt_failed_clears_the_hold_and_sends( array $overrides, array $first_refund, callable $setup, float $amount, string $held_key ): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold( $order, $overrides );
		$setup( $this, $order );
		$list                   = $this->recorded_refund_list();
		$list['data'][0]        = array_merge( $list['data'][0], $first_refund );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $list ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_fresh', 'key_f458_not_read', (int) round( $amount * 100 ) ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, $amount );
		$trail          = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertCount( 2, $trail );
		$this->assertSame( self::refund_list_trail(), $trail[0] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertNotSame( 'POST refunds ' . $held_key, $trail[1], 'The call must send under its own key.' );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Earlier attempts that settle with no money to account for: a failed or canceled refund, nothing listed after the
	 * 300 s window, and a found refund the webhook already recorded.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:callable,3:float,4:string}>
	 */
	public function unsuccessful_earlier_refund_data(): array {
		$no_setup = static function (): void {};

		return array(
			'the earlier refund failed'                => array( array(), array( 'status' => 'failed' ), $no_setup, 2.00, self::F458_KEY_555 ),
			'the found earlier refund was canceled'    => array( self::found_hold_record(), array( 'status' => 'canceled' ), $no_setup, 2.00, self::F458_KEY_555 ),
			'nothing is listed after the 300 s window' => array(
				array(
					'key'            => 'key_f458_unlisted',
					'failed_at'      => time() - 400,
					'last_failed_at' => time() - 400,
				),
				array(),
				$no_setup,
				5.55,
				'key_f458_unlisted',
			),
			'a webhook row recorded the found refund'  => array(
				self::found_hold_record(),
				array(),
				static function ( self $test, WC_Order $order ): void {
					$test->link_local_refund_row( $order, 5.55, self::F458_REFUND_555 );
				},
				2.00,
				self::F458_KEY_555,
			),
		);
	}

	/**
	 * @testdox An earlier refund found for another amount is refused with one note until a call for its amount links it, and the call after that sends fresh.
	 *
	 * Monitor ruling 2026-10-10 11:05 on row 4: the hold keeps the refund it found, so a merchant who then refunds that
	 * amount records it instead of refunding it again.
	 */
	public function test_earlier_refund_for_another_amount_is_refused_until_a_call_for_its_amount_links_it(): void {
		$order                  = $this->create_refund_hold_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_next_partial', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );
		$this->seed_refund_hold( $order );
		$expected_message = 'The earlier refund of $5.55 went through but is not yet recorded on this order. Refund $5.55 first to record it; no money is sent for that step.';

		list( $first ) = $this->run_refund( $provider, $order, 2.00 );
		$hold          = self::refund_hold_of( $order );

		list( $second ) = $this->run_refund( $provider, $order, 3.00 );
		$notes          = self::note_texts_containing( $order, self::F458_REFUND_555 );

		list( $third, $linked_row ) = $this->run_refund( $provider, $order, 5.55 );
		$trail_before_fresh         = self::request_trail( $http_client );

		list( $fourth ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $first );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $first->get_error_code() );
		$this->assertSame( $expected_message, $first->get_error_message() );
		$this->assertSame( self::F458_REFUND_555, $hold['found_refund_id'] ?? null, 'The hold must keep the refund it found.' );
		$this->assertSame( 555, $hold['found_amount'] ?? null );
		$this->assertWPError( $second );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $second->get_error_code() );
		$this->assertCount( 1, $notes, 'The merchant gets one note, not one per refusal.' );
		$this->assertStringContainsString( '5.55', $notes[0] );
		$this->assertTrue( $third );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $linked_row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( array( self::refund_list_trail(), self::refund_list_trail(), self::refund_list_trail() ), $trail_before_fresh, 'No refund may be sent until the earlier one is recorded.' );
		$this->assertTrue( $fourth );
		$this->assertCount( 4, self::request_trail( $http_client ) );
		$this->assertStringStartsWith( 'POST refunds ', self::request_trail( $http_client )[3], 'Once recorded, a later partial refund sends with no lookup.' );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox The refund carrying the held key decides on its own amount, even when the hold recorded another one.
	 *
	 * Monitor ruling 2026-10-10 11:05: the marker is unique to the attempt, so it wins over the hold's amount.
	 */
	public function test_marker_match_decides_on_the_refunds_own_amount(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold( $order, array( 'amount' => 600 ) );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox An earlier refund the webhook already recorded refuses a same-amount call within 15 minutes, keeping the refund status, and a call after that sends fresh.
	 *
	 * Driven through the gateway: the refusal must not overwrite the recorded refund's `successful` status.
	 */
	public function test_earlier_refund_already_recorded_refuses_a_same_amount_call_soon_after_and_sends_later(): void {
		$order = $this->create_refund_hold_order();
		$this->link_local_refund_row( $order, 5.55, self::F458_REFUND_555 );
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_wcpay_refund_status', 'successful' );
		$order->save();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 120,
				'last_failed_at' => time() - 120,
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_deliberate_second', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $soon ) = $this->run_gateway_refund( $provider, $order, 5.55 );
		$status_after = wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true );
		$hold_after   = self::refund_hold_of( $order );
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		list( $later, $later_row ) = $this->run_gateway_refund( $provider, $order, 5.55 );
		$trail                     = self::request_trail( $http_client );

		$this->assertWPError( $soon );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_ALREADY_RECORDED, $soon->get_error_code() );
		$this->assertSame( 'The earlier refund of $5.55 went through and is recorded on the order. No new refund was sent. Reload the order to see it.', $soon->get_error_message() );
		$this->assertSame( 'successful', $status_after );
		$this->assertSame( '', $hold_after );
		$this->assertSame( array(), self::note_texts_containing( $order, 'failed to complete' ) );
		$this->assertTrue( $later );
		$this->assertSame( array( self::refund_list_trail(), self::refund_list_trail() ), array_slice( $trail, 0, 2 ) );
		$this->assertStringStartsWith( 'POST refunds ', $trail[2] ?? '' );
		$this->assertNotSame( 'POST refunds ' . self::F458_KEY_555, $trail[2] ?? '' );
		$this->assertSame( 're_f458_deliberate_second', wc_get_order( $later_row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox After the order was told to refund the found amount, a same-amount call whose refund the webhook recorded meanwhile is refused at any time, once, and the next call sends.
	 *
	 * Opus review F1 and monitor ruling 2026-10-10 13:20: the row 4 note told the merchant no money is sent for that
	 * refund, so the late webhook row must not turn it into a second refund after the 15-minute window.
	 */
	public function test_found_refund_recorded_by_a_late_webhook_refuses_the_same_amount_once_whatever_the_time(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => time() - 1000,
					'last_failed_at' => time() - 1000,
				)
			)
		);
		$this->link_local_refund_row( $order, 5.55, self::F458_REFUND_555 );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_f1', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $refused ) = $this->run_refund( $provider, $order, 5.55 );
		$hold_after      = self::refund_hold_of( $order );

		list( $next, $next_row ) = $this->run_refund( $provider, $order, 5.55 );
		$trail                   = self::request_trail( $http_client );

		$this->assertWPError( $refused );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_ALREADY_RECORDED, $refused->get_error_code() );
		$this->assertSame( '', $hold_after, 'The refusal is made once: it clears the hold.' );
		$this->assertTrue( $next );
		$this->assertCount( 2, $trail );
		$this->assertSame( self::refund_list_trail(), $trail[0], 'The refused call sends no refund.' );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( 're_f458_after_f1', wc_get_order( $next_row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox A manual refund of the found amount recorded after the failure is linked to the found refund and clears the hold, so a same-amount refund then sends fresh.
	 *
	 * Monitor ruling 2026-10-10 13:20: the manual row is the merchant recording the found refund, so a deliberate second
	 * refund of that amount through WooPayments moves money and the books match it. The row is linked after the lookup
	 * (final review D1), once the found refund is known to have gone through and not to be recorded already.
	 */
	public function test_manual_refund_of_the_found_amount_records_the_found_refund(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => time() - 1000,
					'last_failed_at' => time() - 1000,
				)
			)
		);
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_second_555', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );
		$trail                = self::request_trail( $http_client );

		$this->assertSame( self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row records the found refund.' );
		$this->assertSame( '', self::refund_hold_of( $order ) );
		$this->assertTrue( $result );
		$this->assertCount( 2, $trail );
		$this->assertSame( self::refund_list_trail(), $trail[0] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( 're_f458_second_555', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox A manual row is never linked to a found refund that is already recorded or that $found_state, and the hold settles through the lookup instead.
	 *
	 * Final review D1: the manual-row link used to run before the lookup, so it could claim a found refund a late webhook
	 * row records, or one that moved no money. The lookup decides first: a recorded refund for another amount clears the
	 * hold (row 6) and a failed or canceled one clears it (row 2); the manual row stays the merchant's own record.
	 *
	 * @dataProvider found_refunds_a_manual_row_must_not_claim_data
	 *
	 * @param string $found_state   What happened to the found refund.
	 * @param string $status        Its status in the list.
	 * @param bool   $webhook_row   Whether a webhook row records it.
	 */
	public function test_manual_row_never_claims_a_recorded_or_unsuccessful_found_refund( string $found_state, string $status, bool $webhook_row ): void {
		unset( $found_state );
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => time() - 1000,
					'last_failed_at' => time() - 1000,
				)
			)
		);
		if ( $webhook_row ) {
			$this->link_local_refund_row( $order, 5.55, self::F458_REFUND_555 );
		}
		$manual_row                = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$list                      = $this->recorded_refund_list();
		$list['data'][0]['status'] = $status;
		$http_client               = new FakeHttpClient();
		$http_client->responses    = array(
			self::http_json( 200, $list ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_d1', 'key_f458_not_read', 200 ) ),
		);
		$provider                  = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );
		$trail                = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row must not claim the found refund.' );
		$this->assertCount( 2, $trail );
		$this->assertSame( self::refund_list_trail(), $trail[0] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( 're_f458_after_d1', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Found refunds a manual row must not claim.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function found_refunds_a_manual_row_must_not_claim_data(): array {
		return array(
			'a webhook row records' => array( 'a webhook row records', 'succeeded', true ),
			'failed'                => array( 'failed', 'failed', false ),
		);
	}

	/**
	 * @testdox When the order can no longer refund the found amount, a manual row recording the found refund is linked before the hold clears, unless the found refund is already recorded: $order_state.
	 *
	 * Monitor ruling 2026-10-10 14:10 (L1): the usual reason the order cannot refund the found amount is that the merchant
	 * recorded it with Refund manually; clearing the hold without linking that row left the found refund unrecorded, so its
	 * late webhook would record it a second time. A found refund a webhook row already records is not claimed again (D1).
	 *
	 * @dataProvider manual_record_before_the_backstop_data
	 *
	 * @param string $order_state    What the order holds.
	 * @param bool   $webhook_row    Whether a webhook row already records the found refund.
	 * @param string $found_currency Currency of the found refund; a row in the order's currency records only a refund in it.
	 */
	public function test_backstop_links_the_manual_record_before_clearing( string $order_state, bool $webhook_row, string $found_currency = 'usd' ): void {
		unset( $order_state );
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'       => time() - 1000,
				'last_failed_at'  => time() - 1000,
				'found_refund_id' => self::F458_REFUND_555,
				'found_amount'    => 555,
				'found_currency'  => $found_currency,
			)
		);
		$this->link_local_refund_row( $order, $webhook_row ? 29.45 : 35.00, 're_f458_other_refund' );
		if ( $webhook_row ) {
			$this->link_local_refund_row( $order, 5.55, self::F458_REFUND_555 );
		}
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_l1', 'key_f458_not_read', 200 ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );
		$trail                = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertCount( 1, $trail, 'The backstop clears the hold with no lookup.' );
		$this->assertStringStartsWith( 'POST refunds ', $trail[0] );
		$this->assertSame( 're_f458_after_l1', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( $webhook_row || 'usd' !== $found_currency ? '' : self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Orders that can no longer refund the found amount.
	 *
	 * @return array<string,array{0:string,1:bool,2?:string}>
	 */
	public function manual_record_before_the_backstop_data(): array {
		return array(
			'a manual record only'                     => array( 'a manual record only', false ),
			'a manual record and a webhook row for it' => array( 'a manual record and a webhook row for it', true ),
			'a found refund in another currency'       => array( 'a found refund in another currency', false, 'eur' ),
		);
	}

	/**
	 * @testdox A found amount exactly equal to what the order can still refund keeps the hold, so a refund of that amount links the found refund with no request.
	 *
	 * Final review T1: the backstop clears the hold only when the found amount is more than the order can still refund;
	 * at equality the merchant can still follow the note, and clearing would send that refund a second time.
	 */
	public function test_found_amount_equal_to_the_remaining_refundable_amount_keeps_the_hold(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			self::found_hold_record()
		);
		$this->link_local_refund_row( $order, 37.66, 're_f458_other_refund' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund may be sent.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A held-key resend that is answered ambiguously again keeps the key and the first failure time, moves the latest failure time on, and the next call resends under the same key.
	 *
	 * Final review T3: closing the 300-second window from the first failure could let the next call send a fresh key
	 * while the resend may still be running.
	 */
	public function test_held_key_resend_answered_ambiguously_again_moves_the_latest_failure_time(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold(
			$order,
			array(
				'key'            => 'key_f458_unlisted',
				'failed_at'      => time() - 200,
				'last_failed_at' => time() - 200,
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::stripe_api_error( 503 ),
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_second_resend', 'key_f458_unlisted', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );
		$before                 = time();

		list( $first ) = $this->run_refund( $provider, $order, 5.55 );
		$after_first   = self::refund_hold_of( $order );

		list( $second, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertWPError( $first );
		$this->assertIsArray( $after_first );
		$this->assertSame( 'key_f458_unlisted', $after_first['key'] ?? null );
		$this->assertSame( $hold['failed_at'], $after_first['failed_at'] ?? null, 'The first failure time stays.' );
		$this->assertGreaterThanOrEqual( $before, $after_first['last_failed_at'] ?? 0, 'The latest failure time moves on.' );
		$this->assertTrue( $second );
		$this->assertSame(
			array( self::refund_list_trail(), 'POST refunds key_f458_unlisted', self::refund_list_trail(), 'POST refunds key_f458_unlisted' ),
			self::request_trail( $http_client )
		);
		$this->assertSame( 're_f458_second_resend', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox A local row that is not the merchant recording the found refund leaves the hold, and the call is refused as row 4: $row_kind.
	 *
	 * Monitor ruling 2026-10-10 13:20: only a manual row (not refunded through the gateway, with no provider refund ID)
	 * of exactly the found amount, created after the failure, records the found refund.
	 *
	 * @dataProvider rows_that_do_not_record_the_found_refund_data
	 *
	 * @param string $row_kind      Which row.
	 * @param float  $amount        Row amount.
	 * @param int    $age           Seconds before the failure the row was created, or 0 for after it.
	 * @param string $provider_link `_wcpay_refund_id` on the row, as a webhook row carries it.
	 * @param bool   $through_gateway Whether the row was refunded through the gateway (`refunded_payment`).
	 */
	public function test_rows_that_do_not_record_the_found_refund_leave_the_hold( string $row_kind, float $amount, int $age, string $provider_link, bool $through_gateway = false ): void {
		unset( $row_kind );
		$failed_at = time() - 1000;
		$order     = $this->create_refund_hold_order();
		$hold      = $this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => $failed_at,
					'last_failed_at' => $failed_at,
				)
			)
		);
		$row       = $this->create_refund_row( $order, $amount, 'Recorded locally' );
		if ( 0 < $age ) {
			$row->set_date_created( $failed_at - $age );
		}
		if ( '' !== $provider_link ) {
			$row->update_meta_data( '_wcpay_refund_id', $provider_link );
		}
		$row->set_refunded_payment( $through_gateway );
		$row->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
		$this->assertSame( $provider_link, wc_get_order( $row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The row must keep its provider link, or none.' );
	}

	/**
	 * Local rows that do not record the found refund.
	 *
	 * @return array<string,array{0:string,1:float,2:int,3:string,4?:bool}>
	 */
	public function rows_that_do_not_record_the_found_refund_data(): array {
		return array(
			'manual row of another amount'       => array( 'manual row of another amount', 4.00, 0, '' ),
			'manual row from before the failure' => array( 'manual row from before the failure', 5.55, 100, '' ),
			'webhook row of the found amount'    => array( 'webhook row of the found amount', 5.55, 0, 're_f458_webhook_row' ),
			'gateway row with no provider link'  => array( 'gateway row with no provider link', 5.55, 0, '', true ),
		);
	}

	/**
	 * @testdox A manual record of the earlier refund made before any lookup takes the link, so a deliberate same-amount refund sends fresh and the order's refunds match the money.
	 *
	 * Monitor rulings 2026-10-10 13:20 and 13:17 (the review's row 3 variant): the merchant recorded the earlier refund
	 * with Refund manually, so linking it to this call's row instead would show the amount refunded twice while the
	 * customer got it once.
	 */
	public function test_manual_record_made_before_any_lookup_lets_a_same_amount_refund_send(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_deliberate_555', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );
		$trail                = self::request_trail( $http_client );
		$order                = wc_get_order( $order->get_id() );

		$this->assertTrue( $result );
		$this->assertCount( 2, $trail );
		$this->assertSame( self::refund_list_trail(), $trail[0] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertNotSame( 'POST refunds ' . self::F458_KEY_555, $trail[1], 'The deliberate refund sends under its own key.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row records the earlier refund.' );
		$this->assertSame( 're_f458_deliberate_555', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
		$linked = array_map( static fn( WC_Order_Refund $refund ): string => (string) $refund->get_meta( '_wcpay_refund_id', true ), $order->get_refunds() );
		sort( $linked );
		$this->assertSame( array( self::F458_REFUND_555, 're_f458_deliberate_555' ), $linked, 'Each refund row records one provider refund.' );
		$this->assertSame( 11.10, (float) $order->get_total_refunded(), 'The order shows the 11.10 the customer got back.' );
	}

	/**
	 * @testdox A manual record of the earlier refund also settles a call for another amount, which then sends instead of being refused.
	 *
	 * Monitor ruling 2026-10-10 13:17: the check runs on any lookup match that is not recorded, not only a same-amount one.
	 */
	public function test_manual_record_settles_a_call_for_another_amount(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold( $order );
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_partial_200', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertTrue( $result );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 're_f458_partial_200', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
		$this->assertSame( array(), self::note_texts_containing( $order, 'is not yet recorded on this order' ), 'No row 4 note: the refund is recorded.' );
	}

	/**
	 * @testdox When several manual rows could record the earlier refund, the oldest takes the link.
	 *
	 * Monitor ruling 2026-10-10 13:17.
	 */
	public function test_oldest_manual_row_records_the_earlier_refund(): void {
		$failed_at = time() - 1000;
		$order     = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => $failed_at,
				'last_failed_at' => $failed_at,
			)
		);
		$older = $this->create_refund_row( $order, 5.55, 'Recorded first' );
		$older->set_date_created( $failed_at + 10 );
		$older->save();
		$newer                  = $this->create_refund_row( $order, 5.55, 'Recorded again' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_two_rows', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertTrue( $result );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $older->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $newer->get_id() )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox An unrecorded earlier refund in another currency than the order's is not taken for this call's amount, nor for a manual row of that amount.
	 *
	 * The same-amount check compares the currency too: 5.55 USD is not 5.55 EUR, so the call is refused as row 4 instead
	 * of being answered with the earlier refund, and a manual EUR 5.55 row does not record it.
	 */
	public function test_earlier_refund_in_another_currency_is_not_linked(): void {
		$order = $this->create_refund_hold_order();
		$order->set_currency( 'EUR' );
		$order->save();
		$this->seed_refund_hold(
			$order,
			array(
				'currency'       => 'eur',
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		// A manual EUR 5.55 row is not a record of a USD 5.55 refund (Codex review 201 M4).
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded locally' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'A row in the order currency cannot record a refund in another.' );
		$this->assertSame( 'usd', self::refund_hold_of( $order )['found_currency'] ?? null, 'The hold keeps the found refund\'s own currency for the backstop.' );
	}

	/**
	 * @testdox A hold whose found refund is missing from a complete list of the charge's refunds refuses the call, keeps the hold and logs the refund it looked for.
	 *
	 * A hold that found its refund never reaches the time rules: only linking it or its webhook row settles it.
	 */
	public function test_hold_whose_found_refund_is_not_listed_refuses_and_keeps_the_hold(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'key'             => 'key_f458_located',
					'failed_at'       => time() - 1000,
					'last_failed_at'  => time() - 1000,
					'found_refund_id' => 're_f458_located',
				)
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );
		$logger                 = RecordingWcLogger::install();

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_CHECK_FAILED, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
		$this->assertNotEmpty( array_filter( self::warning_lines( $logger ), static fn( string $line ): bool => false !== strpos( $line, 're_f458_located' ) ), 'The warning must name the refund the hold found.' );
	}

	/**
	 * @testdox A hold that found its refund looks it up by its ID as well as by the marker.
	 *
	 * Orchestrator ruling 2026-10-10 on point 2: the found refund is looked up by ID too, so a listed found refund is
	 * never mistaken for a missing one.
	 */
	public function test_found_refund_is_matched_by_its_id_as_well_as_by_the_marker(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'key' => 'key_f458_located',
				)
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A definitive refund failure holds no key, so the next refund of any amount sends with no lookup.
	 *
	 * The recorded error envelope (`Fixtures/platform-refund-errors.json`, pair `refund_exceeds_charge`) is Stripe's
	 * refusal passed through as `400 wcpay_bad_request`.
	 */
	public function test_definitive_refund_failure_holds_no_key(): void {
		$order                  = $this->create_refund_hold_order();
		$error                  = $this->load_recorded_refund_error( 'refund_exceeds_charge' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			array(
				'response' => array( 'code' => $error['response']['http_status'] ),
				'headers'  => array( 'content-type' => $error['response']['content_type'] ),
				'body'     => $error['response']['raw_body'],
			),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_definitive', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $first ) = $this->run_refund( $provider, $order, 5.55 );
		$hold          = self::refund_hold_of( $order );

		list( $second ) = $this->run_refund( $provider, $order, 2.00 );
		$trail          = self::request_trail( $http_client );

		$this->assertWPError( $first );
		$this->assertSame( '', $hold );
		$this->assertTrue( $second );
		$this->assertCount( 2, $trail, 'The next refund must make no lookup.' );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertNotSame( $trail[0], $trail[1] );
	}

	/**
	 * @testdox A hold that names another order or charge is deleted with no lookup.
	 *
	 * Monitor ruling 2026-10-10 13:45 removed the 24-hour expiry this test also pinned: an old hold is now looked up
	 * (test_unrecorded_refund_older_than_24_hours_is_found_and_linked).
	 *
	 * @dataProvider stale_refund_hold_data
	 *
	 * @param array<string,mixed> $overrides Hold fields that make it stale; an order_id of -1 means another order.
	 */
	public function test_stale_or_copied_refund_hold_is_deleted_without_a_lookup( array $overrides ): void {
		$order = $this->create_refund_hold_order();
		if ( -1 === ( $overrides['order_id'] ?? null ) ) {
			$overrides['order_id'] = $this->create_woopayments_order()->get_id();
		}
		$this->seed_refund_hold( $order, $overrides );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_stale', 'key_f458_not_read', 555 ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 5.55 );
		$trail          = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertCount( 1, $trail );
		$this->assertStringStartsWith( 'POST refunds ', $trail[0] );
		$this->assertNotSame( 'POST refunds ' . self::F458_KEY_555, $trail[0] );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Stale or copied holds.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function stale_refund_hold_data(): array {
		return array(
			'another order'  => array( array( 'order_id' => -1 ) ),
			'another charge' => array( array( 'charge_id' => 'ch_f458_other' ) ),
		);
	}

	/**
	 * @testdox A hold that found its refund is honoured past 24 hours.
	 *
	 * Monitor ruling 2026-10-10 11:05: the 24-hour expiry protects nothing once the refund is found, and dropping the
	 * hold would let the merchant refund that amount again.
	 */
	public function test_refund_hold_that_found_its_refund_never_expires(): void {
		$order                  = $this->create_refund_hold_order();
		$hold                   = $this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => time() - 2 * DAY_IN_SECONDS,
					'last_failed_at' => time() - 2 * DAY_IN_SECONDS,
				)
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox An older same-amount refund on the charge without the marker is never taken for the earlier attempt.
	 *
	 * The recorded 7.77 refund (`Fixtures/platform-refund-list.json`) was sent without a marker, like a refund made in
	 * the Stripe dashboard. Inside the window the same-amount call resends under the held key instead.
	 */
	public function test_same_amount_refund_without_the_marker_is_not_taken_for_the_attempt(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'key'     => 'key_f458_unlisted',
				'amount'  => 777,
				'request' => array(
					'charge' => self::F458_CHARGE,
					'amount' => 777,
					'reason' => 'requested_by_customer',
					'source' => 'woocommerce_core',
				),
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_retry_777', 'key_f458_unlisted', 777 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 7.77 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail(), 'POST refunds key_f458_unlisted' ), self::request_trail( $http_client ) );
		$this->assertSame( 're_f458_retry_777', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox A held-key retry that gets back a refund already recorded on the order is refused as already recorded.
	 */
	public function test_held_key_retry_returning_a_recorded_refund_is_refused(): void {
		$order = $this->create_refund_hold_order();
		$this->link_local_refund_row( $order, 5.55, 're_f458_replayed' );
		$this->seed_refund_hold( $order, array( 'key' => 'key_f458_unlisted' ) );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_replayed', 'key_f458_unlisted', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_ALREADY_RECORDED, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail(), 'POST refunds key_f458_unlisted' ), self::request_trail( $http_client ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A refund answered with requires_action is recorded on the order as a pending refund, not as a failure.
	 *
	 * Monitor ruling 2026-10-10 13:35 (U1): a requires_action refund awaits the customer's bank details and has moved no
	 * money; it is a live refund, recorded and settled by charge.refund.updated like a pending one. Client 11.1.0 records
	 * every refund its request returns (class-wc-payment-gateway-wcpay.php:3003-3009). The answer is HAND-BUILT
	 * (`Fixtures/stripe-docs-requires-action-refund.json`) from Stripe's refund object
	 * (https://docs.stripe.com/api/refunds/object) and https://docs.stripe.com/refunds#requires-action, on a recorded
	 * Multibanco refund: no WooPayments payment method reaches requires_action today.
	 */
	public function test_requires_action_refund_answer_is_recorded_as_pending(): void {
		$answer = $this->load_requires_action_refund();
		$order  = $this->create_woopayments_order( '10.00' );
		$order->set_currency( 'EUR' );
		$order->update_meta_data( '_charge_id', (string) $answer['charge'] );
		$order->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $answer ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 3.00 );

		$this->assertTrue( $result, 'The refund is live: it must not be reported as failed.' );
		$this->assertSame( (string) $answer['id'], wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$notes = self::note_texts_containing( $order, (string) $answer['id'] );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'is pending', $notes[0] );
	}

	/**
	 * @testdox An unrecorded earlier refund found in requires_action is linked to the same-amount call as a pending refund.
	 *
	 * Monitor ruling 2026-10-10 13:35: in the hold a requires_action refund follows rows 3 to 6 like a pending one. The
	 * status is HAND-BUILT on the recorded list (Stripe refund object, https://docs.stripe.com/api/refunds/object).
	 */
	public function test_found_requires_action_refund_is_linked_as_pending(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold( $order );
		$list                      = $this->recorded_refund_list();
		$list['data'][0]['status'] = 'requires_action';
		$http_client               = new FakeHttpClient();
		$http_client->responses    = array( self::http_json( 200, $list ) );
		$provider                  = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox An Interac in-person refund recorded from the mobile app (details on the order: $details_on_order, a newer reader refund of the amount: $newer_match, the older one already recorded: $older_recorded) links the oldest succeeded reader refund the order does not record, with no refund request and no failure.
	 *
	 * The WooCommerce mobile app refunds an Interac payment on the card reader, then records it through the order refunds
	 * REST route, which runs the gateway refund. Client 11.1.0 lists the charge's refunds and links the oldest succeeded one
	 * of the same amount (class-wc-payment-gateway-wcpay.php:2944-2968), reading the payment method type from the payment
	 * method (:3031-3049). A reader refund the order already records is passed over, so the next one of the amount is
	 * linked. Stripe refuses an online Interac refund: in-person refunds are mandatory for Interac in Canada,
	 * and such a payment cannot be refunded through the API or the Dashboard (https://docs.stripe.com/terminal/features/refunds,
	 * https://docs.stripe.com/terminal/payments/regional?integration-country=CA). The list is the recorded one
	 * (`Fixtures/platform-refund-list.json`); the Interac details are HAND-BUILT from Stripe's charge object
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_method_details-interac_present), and the fetched
	 * payment method is HAND-BUILT with the two fields of Stripe's PaymentMethod object the refund reads, its ID and type
	 * (https://docs.stripe.com/api/payment_methods/object).
	 *
	 * @dataProvider interac_payment_method_sources
	 *
	 * @param bool $details_on_order Whether the order holds its payment method details; else only its payment method ID.
	 * @param bool $newer_match      Whether a newer reader refund of the same amount is listed too (HAND-BUILT amount).
	 * @param bool $older_recorded   Whether the order already records the older reader refund of the amount.
	 */
	public function test_interac_in_person_refund_links_the_refund_made_on_the_card_reader( bool $details_on_order, bool $newer_match, bool $older_recorded ): void {
		$order       = $this->create_refund_hold_order();
		$http_client = new FakeHttpClient();
		if ( $details_on_order ) {
			$order->update_meta_data( '_wcpay_payment_method_details', (string) wp_json_encode( self::interac_present_details() ) );
		} else {
			$order->update_meta_data( '_payment_method_id', 'pm_interac_reader' );
			$http_client->responses[] = self::http_json(
				200,
				array(
					'id'   => 'pm_interac_reader',
					'type' => 'interac_present',
				)
			);
		}
		$order->save();
		if ( $older_recorded ) {
			$this->link_local_refund_row( $order, 7.77, 're_3UOv4vBzWlxcwgpP0AwrnrcH' );
		}
		$list = $this->recorded_refund_list();
		if ( $newer_match ) {
			$list['data'][0]['amount'] = 777;
		}
		$http_client->responses[] = self::http_json( 200, $list );
		$provider                 = $this->create_refund_hold_provider( $http_client );
		$expected_refund_id       = $older_recorded ? 're_3UOv4vBzWlxcwgpP0Mxsx0Nk' : 're_3UOv4vBzWlxcwgpP0AwrnrcH';

		list( $result, $row ) = $this->run_gateway_refund( $provider, $order, 7.77 );

		$this->assertTrue( $result );
		$trail = self::request_trail( $http_client );
		$this->assertCount( $details_on_order ? 1 : 2, $trail, 'No refund is sent.' );
		$this->assertSame( self::refund_list_trail(), end( $trail ) );
		if ( ! $details_on_order ) {
			$this->assertStringStartsWith( 'GET payment_methods/pm_interac_reader', $trail[0] );
		}
		$this->assertSame( $expected_refund_id, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		if ( $older_recorded ) {
			$linked_ids = array_map(
				static fn( $refund ): string => (string) $refund->get_meta( '_wcpay_refund_id', true ),
				wc_get_order( $order->get_id() )->get_refunds()
			);
			$this->assertEqualsCanonicalizing( array( 're_3UOv4vBzWlxcwgpP0AwrnrcH', $expected_refund_id ), $linked_ids, 'The older reader refund keeps its row.' );
		}
		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( array(), self::note_texts_containing( $order, 'failed to complete' ) );
	}

	/**
	 * Where the refund reads the Interac payment method type from, and which reader refunds of the amount the order records.
	 *
	 * @return array<string,array{bool,bool,bool}>
	 */
	public function interac_payment_method_sources(): array {
		return array(
			'payment method details on the order'      => array( true, false, false ),
			'payment method fetched by its ID'         => array( false, false, false ),
			'two reader refunds of the amount'         => array( true, true, false ),
			'older reader refund already on the order' => array( true, true, true ),
		);
	}

	/**
	 * @testdox An Interac in-person refund whose charge refunds cannot be read fails and is noted on the order, as any refund that fails.
	 *
	 * Client 11.1.0 catches the failed list read like a failed refund: a failure note and the failed refund status
	 * (class-wc-payment-gateway-wcpay.php:2972-2999).
	 */
	public function test_interac_refund_whose_charge_refunds_cannot_be_read_fails_and_is_noted(): void {
		$order = $this->create_refund_hold_order();
		$order->update_meta_data( '_wcpay_payment_method_details', (string) wp_json_encode( self::interac_present_details() ) );
		$order->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::stripe_api_error( 404 ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_gateway_refund( $provider, $order, 7.77 );

		$this->assertWPError( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertCount( 1, self::note_texts_containing( $order, 'failed to complete' ) );
	}

	/**
	 * @testdox An Interac in-person refund with no unrecorded reader refund of its amount ($label) is refused with "$expected_message", sends nothing and records no failure.
	 *
	 * Client 11.1.0 answers wcpay_edit_order_refund_not_possible with no failure note or status
	 * (class-wc-payment-gateway-wcpay.php:2962-2967). A reader refund the order already records is not linked a second time;
	 * client 11.1.0 links it to the new row too (class-wc-payment-gateway-wcpay.php:2953-2968), after which the refund
	 * webhook adds the reader refund's own row again. That refusal says the refund is already recorded, so the merchant is
	 * not sent back to the reader to refund the amount again (the delayed charge.refunded recorded it before the app's call).
	 *
	 * @dataProvider interac_refunds_without_a_reader_refund
	 *
	 * @param string $label            Case label.
	 * @param float  $amount           Refund amount.
	 * @param bool   $already_recorded Whether the order already records the reader refund of that amount.
	 * @param string $listed_status    Status of the listed reader refund of 7.77.
	 * @param string $expected_message Refusal message.
	 */
	public function test_interac_in_person_refund_without_its_reader_refund_is_refused( string $label, float $amount, bool $already_recorded, string $listed_status, string $expected_message ): void {
		unset( $label );
		$order = $this->create_refund_hold_order();
		$order->update_meta_data( '_wcpay_payment_method_details', (string) wp_json_encode( self::interac_present_details() ) );
		$order->save();
		if ( $already_recorded ) {
			$this->link_local_refund_row( $order, $amount, 're_3UOv4vBzWlxcwgpP0AwrnrcH' );
		}
		$list                      = $this->recorded_refund_list();
		$list['data'][1]['status'] = $listed_status;
		$http_client               = new FakeHttpClient();
		$http_client->responses    = array( self::http_json( 200, $list ) );
		$provider                  = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_gateway_refund( $provider, $order, $amount );

		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_edit_order_refund_not_possible', $result->get_error_code() );
		$this->assertSame( $expected_message, $result->get_error_message() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( array(), self::note_texts_containing( $order, 'failed to complete' ) );
	}

	/**
	 * Interac refunds the card reader made no unrecorded refund for.
	 *
	 * The status of a failed reader refund is HAND-BUILT on the recorded list (https://docs.stripe.com/api/refunds/object).
	 *
	 * @return array<string,array{string,float,bool,string,string}>
	 */
	public function interac_refunds_without_a_reader_refund(): array {
		$same_application = 'You shall refund this payment in the same application where the payment was made.';

		return array(
			'no reader refund of that amount'     => array( 'no reader refund of that amount', 3.00, false, 'succeeded', $same_application ),
			'reader refund already on the order'  => array( 'reader refund already on the order', 7.77, true, 'succeeded', 'This refund is already recorded on the order.' ),
			'reader refund of that amount failed' => array( 'reader refund of that amount failed', 7.77, false, 'failed', $same_application ),
		);
	}

	/**
	 * @testdox A refund whose payment method type cannot be fetched is sent to the platform, which answers it.
	 *
	 * Client 11.1.0 fails the refund when that fetch fails (class-wc-payment-gateway-wcpay.php:2943-2970, :3031-3049);
	 * sending it refunds a card payment, and Stripe refuses an online Interac refund (https://docs.stripe.com/terminal/features/refunds),
	 * so no money moves twice. The
	 * refund answer is the recorded one (`Fixtures/platform-refund-errors.json`, pair
	 * `reused_key_identical_params_replays_refund`).
	 */
	public function test_refund_whose_payment_method_type_cannot_be_fetched_is_sent(): void {
		$order = $this->create_refund_hold_order();
		$order->update_meta_data( '_payment_method_id', 'pm_unknown_type' );
		$order->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::stripe_api_error( 404 ), self::http_json( 200, $this->recorded_refund_answer( 're_after_type_fetch_failure', '', 300 ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_gateway_refund( $provider, $order, 3.00 );

		$this->assertTrue( $result );
		$trail = self::request_trail( $http_client );
		$this->assertCount( 2, $trail );
		$this->assertStringStartsWith( 'GET payment_methods/pm_unknown_type', $trail[0] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( 're_after_type_fetch_failure', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * Interac in-person payment method details, HAND-BUILT from Stripe's charge object
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_method_details-interac_present).
	 *
	 * @return array<string,mixed>
	 */
	private static function interac_present_details(): array {
		return array(
			'type'            => 'interac_present',
			'interac_present' => array(
				'brand'       => 'interac',
				'last4'       => '9969',
				'funding'     => 'debit',
				'network'     => 'interac',
				'read_method' => 'contact_emv',
				'receipt'     => array(
					'account_type'               => 'checking',
					'application_preferred_name' => 'Interac',
					'dedicated_file_name'        => 'A0000002771010',
				),
			),
		);
	}

	/**
	 * Load the hand-built requires_action refund answer.
	 *
	 * @return array<string,mixed>
	 */
	private function load_requires_action_refund(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$decoded = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/stripe-docs-requires-action-refund.json' ), true );
		$this->assertTrue( $decoded['_meta']['hand_built'] ?? false, 'The fixture is hand-built and says so.' );

		return $decoded['entries'][0]['response']['body'];
	}

	/**
	 * @testdox A refund request that loaded the order before another request saved a hold reads the hold under the lock and looks the earlier refund up.
	 *
	 * Codex review 201 H1 and monitor ruling 2026-10-10 13:45: a request that waited on the lock holds an order object read
	 * before the earlier request's ambiguous answer was saved; the runtime reads the order again under the lock, as for a
	 * charge, so the second request links the earlier refund instead of sending a second one.
	 */
	public function test_request_that_waited_on_the_lock_reads_the_hold_and_looks_up(): void {
		$order  = $this->create_refund_hold_order();
		$loaded = wc_get_order( $order->get_id() );
		$this->assertSame( self::F458_CHARGE, $loaded->get_meta( '_charge_id', true ), 'The waiting request has read the order meta.' );
		$this->seed_refund_hold( $order );
		$this->assertSame( '', $loaded->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, true ), 'Its order object does not see the hold.' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55, 'requested_by_customer', $loaded );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'The earlier refund is looked up, not sent again.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox The refund runs on a fresh copy of the order, so the caller's unsaved changes stay on its own object.
	 *
	 * Codex review 202 finding 1: reading the caller's object again in place discarded its unsaved property and meta changes
	 * for any provider. The runtime reads a copy under the lock instead, which still sees what another request saved.
	 */
	public function test_refund_keeps_the_callers_unsaved_changes_and_runs_on_a_fresh_copy(): void {
		$order  = $this->create_refund_hold_order();
		$loaded = wc_get_order( $order->get_id() );
		$loaded->get_meta( '_charge_id' );
		$loaded->set_customer_note( 'Unsaved note' );
		$loaded->update_meta_data( '_f458_unsaved', 'kept' );
		$elsewhere = wc_get_order( $order->get_id() );
		$elsewhere->update_meta_data( '_f458_saved_elsewhere', 'seen' );
		$elsewhere->save_meta_data();
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_f458_generic' ) ) extends RecordingProvider {
			/**
			 * Order object the provider was handed.
			 *
			 * @var WC_Order|null
			 */
			public ?WC_Order $seen = null;

			/**
			 * Refund row ID the provider was handed.
			 *
			 * @var mixed
			 */
			public $seen_refund_id = null;

			/**
			 * Record the order and the row, then refund.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				$this->seen           = $context->get_order();
				$this->seen_refund_id = $context->get_payment_data()[ PaymentOperationContext::PAYMENT_DATA_REFUND_ID ] ?? null;

				return parent::refund( $context, $idempotency_key );
			}
		};
		$row      = $this->create_refund_row( $order, 2.00, 'Adjustment' );
		$this->announce_gateway_refund( $row );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.00, 'Adjustment' ),
			$provider
		);

		$this->assertTrue( $result );
		$this->assertSame( 'Unsaved note', $loaded->get_customer_note(), 'The caller keeps its unsaved property.' );
		$this->assertSame( 'kept', $loaded->get_meta( '_f458_unsaved', true ), 'The caller keeps its unsaved meta.' );
		$this->assertInstanceOf( WC_Order::class, $provider->seen );
		$this->assertNotSame( $loaded, $provider->seen, 'The refund runs on a copy.' );
		$this->assertSame( 'seen', $provider->seen->get_meta( '_f458_saved_elsewhere', true ), 'The copy sees what another request saved.' );
		$this->assertSame( 1, $provider->refund_calls );
		$this->assertSame( $row->get_id(), $provider->seen_refund_id, 'The provider gets the row WooCommerce is refunding.' );
	}

	/**
	 * @testdox A refund row saved by another request after this call's row and before the lock does not take this call's place: this call links its own row and sends nothing twice.
	 *
	 * Register row 456 and monitor ruling 2026-10-10 14:10: the runtime takes this call's row from WooCommerce's
	 * `woocommerce_create_refund` (includes/wc-order-functions.php:672-676) instead of the newest row under the lock.
	 * Taking the newest row made the other request's row this call's own, and this call's 5.55 row look like a manual
	 * record of the earlier 5.55 refund, so 5.55 was sent again and linked to the other row.
	 */
	public function test_refund_row_saved_before_the_lock_does_not_take_this_calls_place(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$own_row                = $this->create_refund_row( $order, 5.55, 'requested_by_customer' );
		$other_row              = $this->create_refund_row( $order, 1.00, 'Another refund in flight' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_second_555_send', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );
		$this->announce_gateway_refund( $own_row );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 5.55, 'requested_by_customer' ),
			$provider
		);

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund may be sent again.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $own_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'This call links its own row.' );
		$this->assertSame( '', wc_get_order( $other_row->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox With no row handed over by WooCommerce (a direct wc_refund_payment() caller), no manual record is taken: an earlier refund for another amount is refused as before.
	 *
	 * Monitor ruling 2026-10-10 14:10: without this call's own row, the order's other rows cannot be told apart from it.
	 */
	public function test_without_a_handed_over_row_no_manual_record_is_taken(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00, 'requested_by_customer', null, false );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'No manual record is taken.' );
	}

	/**
	 * @testdox A refund row ID already in the caller's refund context is never handed to the provider: with no row from WooCommerce, no manual record is taken.
	 *
	 * Codex review 204 F2 and monitor ruling 2026-10-10 16:30: the runtime always sets the row ID it validated, else 0.
	 * Kept from a reused context, an earlier row's ID made the provider treat that row as this call's own, take the
	 * merchant's 5.55 record for the earlier refund, clear the hold and send 2.00.
	 */
	public function test_stale_refund_row_id_in_the_context_is_never_handed_over(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$earlier_row = $this->create_refund_row( $order, 1.00, 'An earlier refund in this request' );
		$manual_row  = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$this->create_refund_row( $order, 2.00, 'requested_by_customer' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_stale_key_200', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );
		$context                = PaymentOperationContext::for_refund( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.00, 'requested_by_customer' )
			->with_payment_data( array( PaymentOperationContext::PAYMENT_DATA_REFUND_ID => $earlier_row->get_id() ) );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund( $context, $provider );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund is sent.' );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'No manual record is taken.' );
	}

	/**
	 * @testdox A manual row saved by another request after the runtime handed this call's row over still records the earlier refund, and this call's row links its own new refund.
	 *
	 * Codex review 202 finding 2: the pin interleaves at provider entry, after the runtime took this call's row and before
	 * the hold decides, so it fails when the runtime stops handing the row over or the provider stops reading it, and when
	 * the newest row is taken instead (the newer manual row would become this call's own).
	 */
	public function test_row_handed_over_at_provider_entry_decides_this_calls_own_row(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$manual_row             = null;
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_handoff_200', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider(
			$http_client,
			function () use ( $order, &$manual_row ): void {
				$manual_row = $this->create_refund_row( $order, 5.55, 'Recorded by another request' );
			}
		);

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );
		$trail                = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $manual_row );
		$this->assertGreaterThan( $row, $manual_row->get_id(), 'The manual row is the newer one.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row records the earlier refund.' );
		$this->assertCount( 2, $trail, 'One lookup and one refund: nothing is sent twice.' );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( $http_client->requests[1]['headers']['Idempotency-Key'] ?? '', json_decode( (string) $http_client->requests[1]['body'], true )['metadata']['refund_attempt'] ?? null, 'This call\'s refund carries its own key as its marker.' );
		$this->assertSame( 're_f458_handoff_200', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ), 'This call\'s row links its own refund.' );
	}

	/**
	 * @testdox A refund through wc_create_refund() answered synchronously as $status is a failed refund: it errors, no refund row is kept and the order records the failure.
	 *
	 * Codex review 202 finding 3 and the recorded improvement on client 11.1.0 (which records any returned refund,
	 * class-wc-payment-gateway-wcpay.php:3003-3009): a refund that failed or was canceled moved no money. Final review 3
	 * (T2): driven through core's wc_create_refund() so the row assertions read WooCommerce's own delete, not a helper's.
	 *
	 * @testWith ["failed"]
	 *           ["canceled"]
	 *
	 * @param string $status Refund status the platform answers with.
	 */
	public function test_synchronously_unsuccessful_refund_fails_and_keeps_no_row( string $status ): void {
		$order                  = $this->create_refund_hold_order();
		$answer                 = $this->recorded_refund_answer( 're_f458_sync_' . $status, 'key_f458_not_read', 200 );
		$answer['status']       = $status;
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $answer ) );
		$handed_over            = null;
		$provider               = $this->create_refund_hold_provider(
			$http_client,
			static function ( PaymentOperationContext $context ) use ( &$handed_over ): void {
				$handed_over = $context->get_payment_data()[ PaymentOperationContext::PAYMENT_DATA_REFUND_ID ] ?? null;
			}
		);

		$result = $this->create_refund_through_the_gateway( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertIsInt( $handed_over, 'The refund call got the row wc_create_refund() saved.' );
		$this->assertFalse( wc_get_order( $handed_over ), 'WooCommerce deletes the row of a failed refund.' );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 'The refund returned status "' . $status . '". Reason: No reason provided.', $result->get_error_message() );
	}

	/**
	 * @testdox A refund that fails after a refund status was saved elsewhere leaves the order one refund status, and it reads failed.
	 *
	 * Review F-458 final 3 (G1): the gateway loads the order before the refund call, which runs on a fresh copy, so the
	 * gateway's object never sees a status saved meanwhile. Writing the failure through it added a second
	 * `_wcpay_refund_status` row, and reads kept returning the earlier `successful`.
	 */
	public function test_refund_failure_status_replaces_a_status_saved_during_the_call(): void {
		$order                  = $this->create_refund_hold_order();
		$error                  = $this->load_recorded_refund_error( 'refund_exceeds_charge' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			array(
				'response' => array( 'code' => $error['response']['http_status'] ),
				'headers'  => array( 'content-type' => $error['response']['content_type'] ),
				'body'     => $error['response']['raw_body'],
			),
		);
		$provider               = $this->create_refund_hold_provider(
			$http_client,
			static function () use ( $order ): void {
				// Another request records an earlier refund of the order as successful while this call is in flight.
				$elsewhere = wc_get_order( $order->get_id() );
				$elsewhere->update_meta_data( '_wcpay_refund_status', 'successful' );
				$elsewhere->save_meta_data();
			}
		);

		list( $result ) = $this->run_gateway_refund( $provider, $order, 5.55 );
		$stored         = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( wc_get_order( $order->get_id() ) );

		$this->assertWPError( $result );
		$this->assertCount( 1, $stored->get_meta( '_wcpay_refund_status', false ), 'The order keeps one refund status.' );
		$this->assertSame( 'failed', $stored->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox Another request's gateway refund row of the earlier amount, still in flight, is never taken for the merchant's manual record: this call links the earlier refund and sends nothing.
	 *
	 * Monitor rulings 2026-10-10 15:05 (R1b) and 17:40 (Codex review 205 R1): only a row marked as the merchant's manual
	 * record is taken for one, and a gateway refund row is never marked. Taken, the in-flight 5.55 row passed for a manual
	 * record of the earlier 5.55 refund, and this call sent 5.55 again.
	 */
	public function test_in_flight_gateway_row_is_never_taken_for_a_manual_record(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$in_flight              = $this->create_in_flight_gateway_row( $order, 5.55 );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_r1b_again', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund may be sent again.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $in_flight->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The in-flight row is not a manual record.' );
	}

	/**
	 * @testdox A gateway refund row is never taken for a manual record $moment: no refund is sent again and the earlier refund stays linked.
	 * @dataProvider gateway_row_first_save_moments_data
	 *
	 * Codex review 204 F1 and monitor ruling 2026-10-10 16:30: wc_create_refund() first saves the row inside update_taxes()
	 * (includes/wc-order-functions.php:657, abstracts/abstract-wc-order.php:2455) and fires the hook only at :672. Another
	 * request's refund that ran right after that first save took the 5.55 row for the merchant's record of the
	 * earlier 5.55 refund and sent 2.00; the 5.55 request then sent 5.55 again and its own refund replaced the link.
	 * Codex review 205 R1 and monitor ruling 2026-10-10 17:40: inside that first save, both refund datastores write the
	 * amount before the custom meta (`woocommerce_order_refund_object_updated_props`), so only a positive manual mark can
	 * tell a manual record apart.
	 *
	 * @param string $moment Case label.
	 * @param string $hook   The hook the other request runs on.
	 * @param bool   $hpos   Whether orders are stored in the HPOS tables.
	 */
	public function test_gateway_row_is_never_taken_for_a_manual_record_while_it_is_first_saved( string $moment, string $hook, bool $hpos ): void {
		unset( $moment );
		// Keep both order stores in step, so switching the authoritative store back afterwards is allowed.
		add_filter( 'pre_option_' . DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, static fn(): string => 'yes' );
		$hpos_was_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		OrderHelper::toggle_cot_feature_and_usage( $hpos );
		try {
			$this->assert_gateway_row_is_never_taken_for_a_manual_record_on( $hook );
		} finally {
			OrderHelper::toggle_cot_feature_and_usage( $hpos_was_enabled );
		}
	}

	/**
	 * Moments of a gateway refund row's first save.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function gateway_row_first_save_moments_data(): array {
		return array(
			'after its first save'                     => array( 'after its first save', 'woocommerce_after_order_refund_object_save', true ),
			'inside its first datastore write (HPOS)'  => array( 'inside its first datastore write (HPOS)', 'woocommerce_order_refund_object_updated_props', true ),
			'inside its first datastore write (posts)' => array( 'inside its first datastore write (posts)', 'woocommerce_order_refund_object_updated_props', false ),
		);
	}

	/**
	 * Run another request's 2.00 refund on a hook the 5.55 gateway refund row's first save fires, and assert neither
	 * request sends a refund again.
	 *
	 * @param string $hook Hook fired during the row's first save.
	 */
	private function assert_gateway_row_is_never_taken_for_a_manual_record_on( string $hook ): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$other_client            = new FakeHttpClient();
		$other_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_first_save_200', 'key_f458_not_read', 200 ) ),
		);
		$other_provider          = $this->create_refund_hold_provider( $other_client );
		$other_result            = null;
		$interleave              = function ( $refund ) use ( &$interleave, &$other_result, $other_provider, $order, $hook ): void {
			if ( ! $refund instanceof WC_Order_Refund || $order->get_id() !== $refund->get_parent_id() ) {
				return;
			}
			remove_action( $hook, $interleave );
			// Another request refunds 2.00 during the 5.55 row's first save.
			list( $other_result ) = $this->run_refund( $other_provider, $order, 2.00 );
		};
		add_action( $hook, $interleave );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_first_save_555', 'key_f458_not_read', 555 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		$refund = $this->create_refund_through_the_gateway( $provider, $order, 5.55 );
		remove_action( $hook, $interleave );

		$this->assertWPError( $other_result, 'The 2.00 refund is refused while the earlier 5.55 refund is unrecorded.' );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $other_result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $other_client ), 'The 2.00 request sends nothing.' );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'The 5.55 request sends nothing again.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The 5.55 row records the earlier refund.' );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A refund row saved after the failure without the manual record mark is never linked to the earlier refund.
	 *
	 * Codex review 205 R1 and monitor ruling 2026-10-10 17:40: a row whose creation never fired `woocommerce_create_refund`
	 * (code that saves rows itself, or a row still inside its datastore write) is not known to be the merchant's record,
	 * so the earlier refund stays held.
	 */
	public function test_unmarked_row_after_the_failure_is_never_linked(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$unmarked = new WC_Order_Refund();
		$unmarked->set_parent_id( $order->get_id() );
		$unmarked->set_amount( '5.55' );
		$unmarked->set_total( -5.55 );
		$unmarked->set_reason( 'Saved without wc_create_refund()' );
		$unmarked->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_unmarked_200', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No refund is sent.' );
		$this->assertSame( '', wc_get_order( $unmarked->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The unmarked row is not linked.' );
	}

	/**
	 * @testdox A manual refund a callback creates inside a gateway refund's woocommerce_create_refund keeps the gateway refund's own row: the provider gets it and the refund is linked to it.
	 *
	 * Codex review 204 F3 and monitor ruling 2026-10-10 16:30: the nested wc_create_refund() fired the hook again and
	 * cleared the gateway refund's row, so the call fell back to the newest row and linked the new refund to the manual one.
	 */
	public function test_nested_manual_refund_keeps_the_gateway_refunds_own_row(): void {
		$order                  = $this->create_refund_hold_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_answer( 're_f458_nested_200', 'key_f458_not_read', 200 ) ) );
		$handed_over            = null;
		$provider               = $this->create_refund_hold_provider(
			$http_client,
			static function ( PaymentOperationContext $context ) use ( &$handed_over ): void {
				$handed_over = $context->get_payment_data()[ PaymentOperationContext::PAYMENT_DATA_REFUND_ID ] ?? null;
			}
		);
		$manual_row             = null;
		$nested                 = function ( $refund, $args ) use ( &$nested, &$manual_row, $order ): void {
			if ( ! is_array( $args ) || empty( $args['refund_payment'] ) ) {
				return;
			}
			remove_action( 'woocommerce_create_refund', $nested, 20 );
			unset( $refund );
			$manual_row = $this->create_refund_row( $order, 1.00, 'Recorded by a callback' );
		};
		add_action( 'woocommerce_create_refund', $nested, 20, 2 );

		$refund = $this->create_refund_through_the_gateway( $provider, $order, 2.00 );
		remove_action( 'woocommerce_create_refund', $nested, 20 );

		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $manual_row );
		$this->assertSame( $refund->get_id(), $handed_over, 'The provider gets the gateway refund\'s own row.' );
		$this->assertSame( 're_f458_nested_200', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row is not linked.' );
	}

	/**
	 * @testdox After an inner refund through $inner_kind ends without its refund running, a manual row on the outer order still leaves the outer gateway refund its own row.
	 * @dataProvider unconsumed_inner_refund_data
	 *
	 * Codex review 205 R2 and monitor ruling 2026-10-10 17:25: the inner call's refund stayed kept on top after its call
	 * returned, so the outer call took it, rejected it and fell back to the newest row, the manual one.
	 *
	 * @param string $inner_kind Which gateway the inner refund goes through.
	 */
	public function test_inner_call_that_ends_unconsumed_leaves_the_outer_call_its_own_row( string $inner_kind ): void {
		$order                  = $this->create_refund_hold_order();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_answer( 're_f458_r2_outer_200', 'key_f458_not_read', 200 ) ) );
		$handed_over            = null;
		$provider               = $this->create_refund_hold_provider(
			$http_client,
			static function ( PaymentOperationContext $context ) use ( &$handed_over ): void {
				$handed_over = $context->get_payment_data()[ PaymentOperationContext::PAYMENT_DATA_REFUND_ID ] ?? null;
			}
		);
		if ( 'built-in' === $inner_kind ) {
			$inner_order = $this->create_refund_hold_order();
			$inner_order->update_meta_data( '_intention_status', 'requires_capture' );
		} else {
			$inner_order = wc_create_order();
			$inner_order->set_payment_method( 'other_plugin_gateway' );
			$inner_order->set_total( '10.00' );
		}
		$inner_order->save();
		$inner_result = null;
		$manual_row   = null;
		$nested       = function ( $refund, $args ) use ( &$nested, &$inner_result, &$manual_row, $order, $inner_order ): void {
			if ( ! $refund instanceof WC_Order_Refund || $order->get_id() !== $refund->get_parent_id() || ! is_array( $args ) || empty( $args['refund_payment'] ) ) {
				return;
			}
			remove_action( 'woocommerce_create_refund', $nested, 20 );
			$inner_result = wc_create_refund(
				array(
					'order_id'       => $inner_order->get_id(),
					'amount'         => 3.00,
					'refund_payment' => true,
				)
			);
			$manual_row   = $this->create_refund_row( $order, 1.00, 'Recorded by a callback' );
		};
		add_action( 'woocommerce_create_refund', $nested, 20, 2 );

		$refund = $this->create_refund_through_the_gateway( $provider, $order, 2.00 );
		remove_action( 'woocommerce_create_refund', $nested, 20 );

		$this->assertWPError( $inner_result, 'The inner refund ends without its refund running.' );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $manual_row );
		$this->assertSame( $refund->get_id(), $handed_over, 'The provider gets the outer refund\'s own row.' );
		$this->assertSame( 're_f458_r2_outer_200', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The manual row is not linked.' );
	}

	/**
	 * Inner refunds whose calls return without the refund running.
	 *
	 * @return array<string,array{string}>
	 */
	public function unconsumed_inner_refund_data(): array {
		return array(
			'another plugin\'s gateway (missing here)' => array( 'another plugin' ),
			'the built-in gateway, refused before the refund runs' => array( 'built-in' ),
		);
	}

	/**
	 * @testdox A refund the built-in gateway refuses before the refund runs ($refusal) forgets its kept row.
	 * @dataProvider refusals_before_the_refund_runs_data
	 *
	 * Codex review 205 R2 and monitor ruling 2026-10-10 17:25: the gateway returns before PaymentProcessingService
	 * takes the row (WooPaymentsGateway::process_refund()), so the row must not stay for a later call. Codex review
	 * 206 F2: the invalid-amount refusal is pinned on its own.
	 *
	 * @param string $refusal Why the gateway refuses.
	 */
	public function test_refund_refused_before_it_runs_forgets_its_row( string $refusal ): void {
		$order = 'no charge' === $refusal ? $this->create_woopayments_order( '43.21' ) : $this->create_refund_hold_order();
		if ( 'uncaptured' === $refusal ) {
			$order->update_meta_data( '_intention_status', 'requires_capture' );
			$order->save();
		}
		$row = $this->create_refund_row( $order, 2.00, 'requested_by_customer' );
		$this->announce_gateway_refund( $row );
		if ( 'invalid amount' === $refusal ) {
			// The order total drops below the kept row's amount before the gateway call.
			$order = wc_get_order( $order->get_id() );
			$order->set_total( '1.00' );
			$order->save();
		}
		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $this->create_refund_hold_provider( new FakeHttpClient() ) );

		$result = $gateway->process_refund( $order->get_id(), 2.00, 'requested_by_customer' );

		$codes = array(
			'uncaptured'     => 'uncaptured-payment',
			'invalid amount' => 'invalid-amount',
			'no charge'      => 'order_payment_refund_missing_charge',
		);
		$this->assertWPError( $result );
		$this->assertSame( $codes[ $refusal ], $result->get_error_code() );
		$this->assertNull( $this->use_runtime_refund_capture()->consume( wc_get_order( $order->get_id() ), 2.00 ), 'No later call takes the row.' );
	}

	/**
	 * Refusals the built-in gateway makes before the refund runs.
	 *
	 * @return array<string,array{string}>
	 */
	public function refusals_before_the_refund_runs_data(): array {
		return array(
			'an uncaptured payment'           => array( 'uncaptured' ),
			'an amount above the order total' => array( 'invalid amount' ),
			'no charge to refund'             => array( 'no charge' ),
		);
	}

	/**
	 * @testdox Another request's gateway refund row still in flight does not count as refunded, so the backstop keeps a found hold.
	 *
	 * Monitor ruling 2026-10-10 15:05 (R1b): the in-flight refund may still fail; counting it could only clear the hold early.
	 */
	public function test_in_flight_gateway_row_does_not_count_for_the_backstop(): void {
		$order = $this->create_refund_hold_order();
		$hold  = $this->seed_refund_hold(
			$order,
			self::found_hold_record()
		);
		$this->create_in_flight_gateway_row( $order, 40.00 );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A refund row ($_dataName) counts as refunded, so a found hold the order can no longer refund is cleared with a warning and the call sends with no lookup.
	 * @dataProvider completed_gateway_row_shapes_data
	 *
	 * Review C10a (F1): every completed native refund leaves a row with refunded_payment set and a provider refund ID, and
	 * no manual record mark. The backstop counts it through refunded_payment (monitor rulings 2026-10-10 15:05, 17:40);
	 * skipping it would count none of the order's native refunds and block every later refund. Codex review 207: the
	 * unlinked shape pins refunded_payment on its own, since the provider link also counts a row. A row linked by
	 * `_wcpay_refund_id` records a provider refund, money that has moved, so rows a refund webhook linked before the manual
	 * record mark existed keep counting (orchestrator ruling on Codex review 206 F1). Opus review F2: the merchant recorded
	 * the earlier refund some other way, so the hold would block every later refund.
	 *
	 * @param callable $create_row Called with the test and the order to create the 40.00 refund row.
	 */
	public function test_completed_gateway_refund_counts_for_the_backstop( callable $create_row ): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			self::found_hold_record()
		);
		$create_row( $this, $order );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_backstop', 'key_f458_not_read', 200 ) ) );
		$provider               = $this->create_refund_hold_provider( $http_client );
		$logger                 = RecordingWcLogger::install();

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );
		$trail                = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertCount( 1, $trail, 'The backstop clears the hold with no lookup.' );
		$this->assertStringStartsWith( 'POST refunds ', $trail[0] );
		$this->assertSame( 're_f458_after_backstop', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
		$this->assertNotEmpty( array_filter( self::warning_lines( $logger ), static fn( string $line ): bool => false !== strpos( $line, 'can no longer refund' ) ) );
	}

	/**
	 * Refund rows of 40.00 that count against the order's remaining refundable amount.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function completed_gateway_row_shapes_data(): array {
		return array(
			'refunded and linked'                      => array(
				static function ( self $test, WC_Order $order ): void {
					$test->create_completed_gateway_row( $order, 40.00, 're_f458_completed_gateway_refund' );
				},
			),
			'refunded, with no link'                   => array(
				static function ( self $test, WC_Order $order ): void {
					$test->create_completed_gateway_row( $order, 40.00, '' );
				},
			),
			'row the refund webhook linked'            => array(
				static function ( self $test, WC_Order $order ): void {
					$test->link_local_refund_row( $order, 40.00, 're_f458_other_refund' );
				},
			),
			'unmarked row linked to a provider refund' => array(
				static function ( self $test, WC_Order $order ): void {
					$linked = $test->create_unmarked_row( $order, 40.00, time() );
					$linked->update_meta_data( '_wcpay_refund_id', 're_f458_linked_before_the_mark' );
					$linked->save_meta_data();
				},
			),
		);
	}

	/**
	 * @testdox Another request's unmarked, unlinked gateway row created before the failure and still in flight does not count as refunded: the found hold stays and no refund is sent.
	 *
	 * Codex review 206 F1: wc_create_refund() saves its row before the gateway call (includes/wc-order-functions.php:657,676),
	 * so a row older than the hold's failure can belong to a request paused before its gateway call. Counted, its 40.00
	 * cleared the 5.55 hold with no lookup; when that request then failed, a 5.55 retry refunded the earlier refund twice.
	 */
	public function test_older_in_flight_row_does_not_count_for_the_backstop(): void {
		$order = $this->create_refund_hold_order();
		$hold  = $this->seed_refund_hold(
			$order,
			self::found_hold_record(
				array(
					'failed_at'      => time() - 1000,
					'last_failed_at' => time() - 1000,
				)
			)
		);
		$this->create_unmarked_row( $order, 40.00, time() - 2000 );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_older_in_flight_200', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00 );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'The hold is looked up and no refund is sent.' );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * Create another request's gateway refund row as wc_create_refund() saves it before that request's gateway call returns.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Amount.
	 * @return WC_Order_Refund
	 */
	private function create_in_flight_gateway_row( WC_Order $order, float $amount ): WC_Order_Refund {
		$row = new WC_Order_Refund();
		$row->set_parent_id( $order->get_id() );
		$row->set_amount( (string) $amount );
		$row->set_total( -1 * $amount );
		$row->set_reason( 'Another refund in flight' );
		$this->use_runtime_refund_capture()->handle_create_refund( $row, array( 'refund_payment' => true ) );
		$row->save();

		return $row;
	}

	/**
	 * @testdox With no row handed over by WooCommerce, the remaining-refundable backstop does not clear a found hold.
	 *
	 * Monitor ruling 2026-10-10 14:10: without this call's own row its amount cannot be told apart from the order's other
	 * refunds, so the backstop does not run and the found refund still decides.
	 */
	public function test_without_a_handed_over_row_the_backstop_does_not_clear_a_found_hold(): void {
		$order = $this->create_refund_hold_order();
		$hold  = $this->seed_refund_hold(
			$order,
			self::found_hold_record()
		);
		$this->link_local_refund_row( $order, 40.00, 're_f458_other_refund' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result ) = $this->run_refund( $provider, $order, 2.00, 'requested_by_customer', null, false );

		$this->assertWPError( $result );
		$this->assertSame( WooPaymentsRefundAmbiguityService::REFUSAL_EARLIER_FOUND, $result->get_error_code() );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( $hold, self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A manual refund saved by another request during the lookup does not make this call's own row look like a manual record.
	 *
	 * Codex review 201 H2: this call's row is the one the runtime read under the lock before the provider call. Taking the
	 * newest row after the lookup instead would exclude the new manual row, take this call's own 5.55 row for a manual
	 * record of the earlier 5.55 refund, and send 5.55 again.
	 */
	public function test_manual_row_saved_during_the_lookup_does_not_shift_this_calls_row(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$test        = $this;
		$late_row    = null;
		$http_client = new class() extends FakeHttpClient {
			/**
			 * Called before each answer.
			 *
			 * @var callable|null
			 */
			public $before_answer = null;

			/**
			 * Run the callback, then answer from the queue.
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
				if ( is_callable( $this->before_answer ) ) {
					call_user_func( $this->before_answer, $method );
				}

				return parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
			}
		};

		$http_client->before_answer = static function ( string $method ) use ( $test, $order, &$late_row ): void {
			if ( 'GET' === $method && null === $late_row ) {
				$late_row = wc_create_refund(
					array(
						'order_id'       => $order->get_id(),
						'amount'         => 1.00,
						'reason'         => 'Recorded by another request',
						'refund_payment' => false,
					)
				);
				$test->assertInstanceOf( WC_Order_Refund::class, $late_row );
			}
		};
		$http_client->responses     = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider                   = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $late_row );
		$this->assertGreaterThan( $row, $late_row->get_id(), 'The other request saved the newer row.' );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ), 'No second refund may be sent.' );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', wc_get_order( $late_row->get_id() )->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox An unrecorded earlier refund whose hold is older than 24 hours is found and linked, not refunded again.
	 *
	 * Codex review 201 H3 and monitor ruling 2026-10-10 13:45: Stripe forgetting the key after 24 hours does not settle what
	 * the earlier request did, so the hold never expires and the lookup decides.
	 */
	public function test_unrecorded_refund_older_than_24_hours_is_found_and_linked(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'failed_at'      => time() - 2 * DAY_IN_SECONDS,
				'last_failed_at' => time() - 2 * DAY_IN_SECONDS,
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( self::http_json( 200, $this->recorded_refund_list() ) );
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 5.55 );

		$this->assertTrue( $result );
		$this->assertSame( array( self::refund_list_trail() ), self::request_trail( $http_client ) );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * @testdox A manual row matching the found refund's own amount records it even when the hold recorded another amount.
	 *
	 * Codex review 201 M5 and monitor ruling 2026-10-10 11:05: the refund carrying the marker decides on its own amount, so
	 * the manual record is matched on 5.55, not on the 6.00 the hold holds.
	 */
	public function test_manual_row_matches_the_found_refunds_own_amount(): void {
		$order = $this->create_refund_hold_order();
		$this->seed_refund_hold(
			$order,
			array(
				'amount'         => 600,
				'failed_at'      => time() - 1000,
				'last_failed_at' => time() - 1000,
			)
		);
		$manual_row             = $this->create_refund_row( $order, 5.55, 'Recorded from the Stripe dashboard' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json( 200, $this->recorded_refund_list() ),
			self::http_json( 200, $this->recorded_refund_answer( 're_f458_after_m5', 'key_f458_not_read', 200 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		list( $result, $row ) = $this->run_refund( $provider, $order, 2.00 );
		$trail                = self::request_trail( $http_client );

		$this->assertTrue( $result );
		$this->assertSame( self::F458_REFUND_555, wc_get_order( $manual_row->get_id() )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertCount( 2, $trail );
		$this->assertStringStartsWith( 'POST refunds ', $trail[1] );
		$this->assertSame( 're_f458_after_m5', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', self::refund_hold_of( $order ) );
	}

	/**
	 * Create an order paid by the charge recorded in `Fixtures/platform-refund-list.json`.
	 *
	 * @return WC_Order
	 */
	private function create_refund_hold_order(): WC_Order {
		$order = $this->create_woopayments_order( '43.21' );
		$order->set_currency( 'USD' );
		$order->update_meta_data( '_charge_id', self::F458_CHARGE );
		$order->save();

		return $order;
	}

	/**
	 * Build the real provider over a fake transport, with no backoff between transport retries.
	 *
	 * @param FakeHttpClient $http_client   Platform answers.
	 * @param callable|null  $before_refund Called with the refund context when the runtime enters the provider.
	 * @return WooPaymentsProvider
	 */
	private function create_refund_hold_provider( FakeHttpClient $http_client, ?callable $before_refund = null ): WooPaymentsProvider {
		$account_service = $this->create_account_service( true );
		$api_client      = new class() extends Client {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );

		$provider                = new class() extends WooPaymentsProvider {
			/**
			 * Called with the refund context before the provider refunds.
			 *
			 * @var callable|null
			 */
			public $before_refund = null;

			/**
			 * Run the hook, then refund.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				if ( is_callable( $this->before_refund ) ) {
					call_user_func( $this->before_refund, $context );
				}

				return parent::refund( $context, $idempotency_key );
			}
		};
		$provider->before_refund = $before_refund;
		$provider->init( $this->create_operation_service( $api_client, null, null, $account_service ), $api_client, $account_service, new PaymentMethodRegistry(), wc_get_container()->get( WooPaymentsOrderEffectApplier::class ) );

		return $provider;
	}

	/**
	 * Run one merchant refund as WooCommerce does: save the refund row, refund through the processing service, and delete
	 * the row when the refund fails (`includes/wc-order-functions.php:675-680`).
	 *
	 * @param WooPaymentsProvider $provider Provider.
	 * @param WC_Order            $order    Order.
	 * @param float               $amount   Refund amount.
	 * @param string              $reason   Refund reason.
	 * @param WC_Order|null       $loaded   Order object as the refunding request loaded it; read fresh when null.
	 * @param bool                $through_core Whether the row is refunded the way wc_create_refund() does, which hands it
	 *                                          to the runtime through `woocommerce_create_refund`; false for a direct caller.
	 * @return array{0:true|WP_Error,1:int} The result and the refund row ID.
	 */
	private function run_refund( WooPaymentsProvider $provider, WC_Order $order, float $amount, string $reason = 'requested_by_customer', ?WC_Order $loaded = null, bool $through_core = true ): array {
		$row = $this->create_refund_row( $order, $amount, $reason );
		if ( $through_core ) {
			$this->announce_gateway_refund( $row );
		}
		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( $loaded ?? wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, $amount, $reason ),
			$provider
		);
		if ( is_wp_error( $result ) ) {
			$row->delete( true );
		}

		return array( $result, $row->get_id() );
	}

	/**
	 * Run one merchant refund through the gateway, as WooCommerce does.
	 *
	 * @param WooPaymentsProvider $provider Provider.
	 * @param WC_Order            $order    Order.
	 * @param float               $amount   Refund amount.
	 * @return array{0:bool|WP_Error,1:int} The result and the refund row ID.
	 */
	private function run_gateway_refund( WooPaymentsProvider $provider, WC_Order $order, float $amount ): array {
		$row = $this->create_refund_row( $order, $amount, 'requested_by_customer' );
		$this->announce_gateway_refund( $row );
		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$result = $gateway->process_refund( $order->get_id(), $amount, 'requested_by_customer' );
		if ( is_wp_error( $result ) ) {
			$row->delete( true );
		}

		return array( $result, $row->get_id() );
	}

	/**
	 * Refund through core's wc_create_refund() with the native gateway as the order's gateway in WooCommerce's gateway
	 * list, so WooCommerce fires `woocommerce_create_refund`, saves the row, refunds it and deletes it when that fails.
	 *
	 * @param WooPaymentsProvider $provider Provider.
	 * @param WC_Order            $order    Order.
	 * @param float               $amount   Refund amount.
	 * @return WC_Order_Refund|WP_Error What wc_create_refund() returned.
	 */
	private function create_refund_through_the_gateway( WooPaymentsProvider $provider, WC_Order $order, float $amount ) {
		$gateway = new WooPaymentsGateway();
		$gateway->init( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->use_runtime_refund_capture()->register();
		$gateways                                  = WC()->payment_gateways()->payment_gateways;
		WC()->payment_gateways()->payment_gateways = array( $gateway );
		try {
			return wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => 'requested_by_customer',
					'refund_payment' => true,
				)
			);
		} finally {
			WC()->payment_gateways()->payment_gateways = $gateways;
		}
	}

	/**
	 * Create a refund row the way code that never fires `woocommerce_create_refund` saves it: no manual record mark.
	 *
	 * @param WC_Order $order      Parent order.
	 * @param float    $amount     Amount.
	 * @param int      $created_at Unix time the row was created.
	 * @return WC_Order_Refund
	 */
	private function create_unmarked_row( WC_Order $order, float $amount, int $created_at ): WC_Order_Refund {
		$row = new WC_Order_Refund();
		$row->set_parent_id( $order->get_id() );
		$row->set_amount( (string) $amount );
		$row->set_total( -1 * $amount );
		$row->set_reason( 'Saved without woocommerce_create_refund' );
		$row->set_date_created( $created_at );
		$row->save();

		return $row;
	}

	/**
	 * Create a refund row as a completed refund through the runtime's gateway leaves it: refunded through the gateway,
	 * linked to its provider refund unless the ID is empty, and not marked as a manual record.
	 *
	 * @param WC_Order $order     Parent order.
	 * @param float    $amount    Amount.
	 * @param string   $refund_id Provider refund ID; empty for an unlinked row.
	 * @return WC_Order_Refund
	 */
	private function create_completed_gateway_row( WC_Order $order, float $amount, string $refund_id ): WC_Order_Refund {
		$row = $this->create_in_flight_gateway_row( $order, $amount );
		$row->set_refunded_payment( true );
		if ( '' !== $refund_id ) {
			$row->update_meta_data( '_wcpay_refund_id', $refund_id );
		}
		$row->save();

		return $row;
	}

	/**
	 * Fire `woocommerce_create_refund` for a row as wc_create_refund() does before it refunds it through the gateway
	 * (includes/wc-order-functions.php:672-676), with the runtime's capture listening as it does where the gateway loads.
	 *
	 * @param WC_Order_Refund $row Refund row.
	 */
	private function announce_gateway_refund( WC_Order_Refund $row ): void {
		$this->use_runtime_refund_capture()->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as wc_create_refund() does.
		do_action( 'woocommerce_create_refund', $row, array( 'refund_payment' => true ) );
	}

	/**
	 * Put a refund row capture in the container whose provider owns the WooPayments card gateway, as on an active store.
	 *
	 * @return RefundRowCapture
	 */
	private function use_runtime_refund_capture(): RefundRowCapture {
		$capture = wc_get_container()->get( RefundRowCapture::class );
		if ( ! isset( $this->runtime_capture_installed ) ) {
			$controller = $this->createMock( ProviderGatewaysController::class );
			$controller->method( 'owns_gateway' )->willReturnCallback( static fn( string $gateway_id ): bool => WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway_id );
			$capture = new RefundRowCapture();
			$capture->init( $controller );
			wc_get_container()->replace( RefundRowCapture::class, $capture );
			$this->runtime_capture_installed = true;
		}

		return $capture;
	}

	/**
	 * Create a refund row without refunding through a gateway.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Amount.
	 * @param string   $reason Reason.
	 * @return WC_Order_Refund
	 */
	private function create_refund_row( WC_Order $order, float $amount, string $reason ): WC_Order_Refund {
		// As on a store where the runtime loads, the capture listens and marks the row as a manual record.
		$this->use_runtime_refund_capture()->register();
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		return $refund;
	}

	/**
	 * Create a refund row linked to a provider refund, as the refund webhook records it.
	 *
	 * @param WC_Order $order     Parent order.
	 * @param float    $amount    Amount.
	 * @param string   $refund_id Provider refund ID.
	 */
	private function link_local_refund_row( WC_Order $order, float $amount, string $refund_id ): void {
		$refund = $this->create_refund_row( $order, $amount, '' );
		$refund->update_meta_data( '_wcpay_refund_id', $refund_id );
		$refund->save_meta_data();
	}

	/**
	 * Store a refund hold on the order: by default the recorded 5.55 refund's key, failing 60 s ago.
	 *
	 * @param WC_Order            $order     Order.
	 * @param array<string,mixed> $overrides Fields to change.
	 * @return array<string,mixed> The stored hold.
	 */
	private function seed_refund_hold( WC_Order $order, array $overrides = array() ): array {
		$now  = time();
		$hold = array_merge(
			array(
				'order_id'        => $order->get_id(),
				'charge_id'       => self::F458_CHARGE,
				'key'             => self::F458_KEY_555,
				'amount'          => 555,
				'currency'        => 'usd',
				'request'         => array(
					'charge' => self::F458_CHARGE,
					'amount' => 555,
					'reason' => 'requested_by_customer',
					'source' => 'woocommerce_core',
				),
				'wc_refund_id'    => 0,
				'failed_at'       => $now - 60,
				'last_failed_at'  => $now - 60,
				'found_refund_id' => '',
				'found_amount'    => 0,
			),
			$overrides
		);

		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, $hold );
		$order->save_meta_data();

		return $hold;
	}

	/**
	 * The fields of a refund hold that holds a found refund, as refuse_earlier_found() writes them together
	 * (WooPaymentsRefundAmbiguityService.php:402-408): by default the recorded 5.55 refund in USD.
	 *
	 * @param array<string,mixed> $fields Fields to add or change.
	 * @return array<string,mixed>
	 */
	private static function found_hold_record( array $fields = array() ): array {
		return array_merge(
			array(
				'found_refund_id' => self::F458_REFUND_555,
				'found_amount'    => 555,
				'found_currency'  => 'usd',
			),
			$fields
		);
	}

	/**
	 * Get the refund hold stored on an order, read fresh.
	 *
	 * @param WC_Order $order Order.
	 * @return mixed The hold, or '' when there is none.
	 */
	private static function refund_hold_of( WC_Order $order ) {
		return wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, true );
	}

	/**
	 * The charge's refund list read, as request_trail() prints it.
	 *
	 * @return string
	 */
	private static function refund_list_trail(): string {
		return 'GET refunds?test_mode=1&charge=' . self::F458_CHARGE . '&limit=100';
	}

	/**
	 * Get a recorded refund list body (`Fixtures/platform-refund-list.json`).
	 *
	 * @param string $pair Fixture pair key.
	 * @return array<string,mixed>
	 */
	private function recorded_refund_list( string $pair = 'list_limit_100_complete' ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$decoded = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-refund-list.json' ), true );
		foreach ( $decoded['entries'] ?? array() as $entry ) {
			if ( ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "REC F458 (a) fixture has no entry for pair '$pair'." );
	}

	/**
	 * Get the recorded refund list with one refund's marker set to the key a test call sent under.
	 *
	 * The recording shows every marked refund carries the key it was sent under, and a test call mints its own key.
	 *
	 * @param string $refund_id Recorded refund whose marker changes.
	 * @param string $key       Key the call sent.
	 * @return array<string,mixed>
	 */
	private function recorded_refund_list_with_marker( string $refund_id, string $key ): array {
		$list = $this->recorded_refund_list();
		foreach ( $list['data'] as $index => $refund ) {
			if ( $refund_id === $refund['id'] ) {
				$list['data'][ $index ]['metadata']['refund_attempt'] = $key;
			}
		}

		return $list;
	}

	/**
	 * Get a refund answer in the recorded POST shape (`Fixtures/platform-refund-errors.json`, pair
	 * `reused_key_identical_params_replays_refund`), with its ID, marker and amount set for the test.
	 *
	 * @param string $refund_id Refund ID.
	 * @param string $key       Marker.
	 * @param int    $amount    Amount in minor units.
	 * @return array<string,mixed>
	 */
	private function recorded_refund_answer( string $refund_id, string $key, int $amount ): array {
		$answer                               = $this->load_recorded_refund_error( 'reused_key_identical_params_replays_refund' )['response']['body'];
		$answer['id']                         = $refund_id;
		$answer['amount']                     = $amount;
		$answer['metadata']['refund_attempt'] = $key;

		return $answer;
	}

	/**
	 * Load one recorded F458 (b) or (c) entry (`Fixtures/platform-refund-errors.json`).
	 *
	 * @param string $pair Fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_refund_error( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$decoded = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-refund-errors.json' ), true );
		foreach ( $decoded['entries'] ?? array() as $entry ) {
			if ( ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry;
			}
		}

		$this->fail( "REC F458 (b)/(c) fixture has no entry for pair '$pair'." );
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
		$fixture = file_get_contents( __DIR__ . '/Fixtures/platform-refunds.json' );
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
	 * `Fixtures/platform-*.json` recording.
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
	 * @testdox A card checkout over a fake transport pays the order exactly once with the recorded intent identity.
	 *
	 * T.3 Task 2: joins the request-shape half
	 * ({@see self::test_single_card_checkout_without_save_matches_11_1_request_shape}) and the
	 * order-completion half ({@see \Automattic\WooCommerce\Tests\Internal\Payments\PaymentProcessingServiceTest::test_process_checkout_completes_order_for_completed_outcome})
	 * by running a real recorded PaymentIntent response through the full production stack:
	 * {@see PaymentProcessingService::process_checkout_outcome} → the real {@see WooPaymentsProvider} → this
	 * adapter → the real {@see Client} → a FAKEHTTP transport queued with REC-BC
	 * (`Fixtures/platform-intention-basic-card.json`, USD, no currency conversion) and REC-3
	 * (`Fixtures/platform-eur-charge.json`, EUR charge on a USD account), which also joins the
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
	public function test_card_checkout_over_fake_transport_pays_order_once_with_recorded_intent( string $fixture, string $pair, string $currency, string $amount, int $amount_minor, string $customer_id, string $intent_id, string $charge_id, string $transaction_id, ?string $exchange_rate ): void {
		$order = $this->create_woopayments_order( $amount );
		$order->set_currency( $currency );
		// A physical line item makes the order need processing, matching the recorded checkout's
		// real product and the provider smoke's expected post-payment status.
		$order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$order->set_total( $amount );
		$order->save();

		$recorded              = $this->load_recorded_intent_entry( $fixture, $pair );
		$http_client           = new FakeHttpClient();
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

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_card_visa' ),
			$provider
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( $intent_id, $outcome->get_provider_payment_id() );
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
				'platform-intention-basic-card.json',
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
				'platform-eur-charge.json',
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
	 * @testdox A card checkout with save over a fake transport creates exactly one token from the recorded payment method.
	 *
	 * T.3 Task 2: the save path from a real recorded response to exactly one token
	 * row, joining {@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplierTest::test_requested_token_effects_are_idempotent}'s
	 * hand-built outcome with a real REC-SUB step-1 signup response
	 * (`Fixtures/platform-intention-subscription.json`, pair `signup_initial_setup_future_usage`) run through the
	 * full production stack, including the real {@see \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService}.
	 */
	public function test_card_checkout_with_save_over_fake_transport_creates_one_token_from_recorded_payment_method(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order( '10.99' );
		$order->set_customer_id( $user_id );
		$order->save();

		$recorded              = $this->load_recorded_intent_entry( 'platform-intention-subscription.json', 'signup_initial_setup_future_usage' );
		$http_client           = new FakeHttpClient();
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
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class )
		);
		$provider = $this->create_provider_over_fake_transport( $http_client, $account_service, $customer_service, $token_service, $order_effect_applier );

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_card_visa', array( 'save_payment_method' => true ) ),
			$provider
		);

		$order  = wc_get_order( $order->get_id() );
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token  = reset( $tokens );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( $recorded['body']['id'], $outcome->get_provider_payment_id() );
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
	 * T.3 Task 2: joins
	 * {@see self::test_scheduled_subscription_charge_uses_merchant_initiated_recurring_request_shape}'s
	 * merchant-initiated request shape with a real off-session REC-SUB step-2 renewal response
	 * (`Fixtures/platform-intention-subscription.json`, pair `renewal_off_session`), built the same way that test
	 * builds its scheduled-renewal context, run through the full production stack against a FAKEHTTP
	 * transport, and asserts the renewal order actually ends up paid (`completed`,
	 * `OrderPaymentLifecycleService::apply_status_transition()`) with exactly one `successfully
	 * charged` note — not just that the outcome is reported successful. REC-SUB step 2 sends no
	 * `mandate` and no `payment_method_update_data`, so the wire
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

		$recorded              = $this->load_recorded_intent_entry( 'platform-intention-subscription.json', 'renewal_off_session' );
		$http_client           = new FakeHttpClient();
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

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_3UJhOUBzWlxcwgpP0FGWIQQW', $outcome->get_provider_payment_id() );
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
	 * @testdox A checkout with a saved card keeps the valid customer on the order and its subscription when a response filter returns a non-string customer.
	 *
	 * A `wcpay_api_request_response` callback can return any value for `customer`; the outcome falls back to the customer the
	 * request was sent for instead of casting `true` to '1', which the order meta and the subscription sync would then store.
	 * The response is the recorded REC-SUB succeeded intent (`Fixtures/platform-intention-subscription.json`, pair
	 * `renewal_off_session`).
	 */
	public function test_saved_card_checkout_keeps_the_valid_customer_when_a_filter_returns_a_non_string_customer(): void {
		$user_id     = $this->factory()->user->create();
		$order       = $this->create_woopayments_order( '10.99' );
		$saved_token = $this->create_card_token( $user_id, 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$order->set_customer_id( $user_id );
		$order->update_meta_data( '_stripe_customer_id', 'cus_UsIeTbmGHPc9jY' );
		$order->save();
		$subscription = $this->create_woopayments_order( '10.99' );
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $order->get_id() => array( 'parent' => array( $subscription->get_id() ) ) );

		$recorded              = $this->load_recorded_intent_entry( 'platform-intention-subscription.json', 'renewal_off_session' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) {
				$body             = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$body['customer'] = true;
				$response['body'] = wp_json_encode( $body );

				return $response;
			}
		);
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_UsIeTbmGHPc9jY' );
		$provider = $this->create_provider_over_fake_transport( $http_client, $this->create_account_service( false ), $customer_service );

		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );
		$outcome = wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'',
				array(
					'payment_token'       => (string) $saved_token->get_id(),
					'save_payment_method' => false,
				)
			),
			$provider
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'cus_UsIeTbmGHPc9jY', wc_get_order( $order->get_id() )->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cus_UsIeTbmGHPc9jY', wc_get_order( $subscription->get_id() )->get_meta( '_stripe_customer_id', true ) );
	}

	/**
	 * Build a real WooPayments provider whose only isolated seam is the raw HTTP transport.
	 *
	 * The production provider, adapter, API client and order-effect applier all
	 * remain in use, following the pattern
	 * `PaymentProcessingServiceTest::review_payment_intent_provider` established.
	 *
	 * @param FakeHttpClient                     $http_client           Fake raw transport queued with a recorded response.
	 * @param WooPaymentsAccountService          $account_service       WooPayments account service.
	 * @param WooPaymentsCustomerService|null    $customer_service      Customer service; a bare mock (no configured methods) when omitted, matching the refund path's needs.
	 * @param WooPaymentsTokenService|null       $token_service         Token service; the default fake-details token service when omitted.
	 * @param WooPaymentsOrderEffectApplier|null $order_effect_applier  Order effect applier; the container's real singleton when omitted. Pass a locally built one wired to `$token_service` when a test needs its own fake payment-method details for token creation, since the container's singleton resolves its own token service independently of this method's `$token_service` argument.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_over_fake_transport( FakeHttpClient $http_client, WooPaymentsAccountService $account_service, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsOrderEffectApplier $order_effect_applier = null ): WooPaymentsProvider {
		$api_client = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );

		$payment_operations = $this->create_operation_service( $api_client, $customer_service, $token_service, $account_service );

		$provider = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			$api_client,
			$account_service,
			new PaymentMethodRegistry(),
			$order_effect_applier ?? wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * @testdox A pending redirect-method refund over a fake transport becomes successful on a `charge.refund.updated` succeeded webhook.
	 *
	 * K4: the real-event
	 * proof that a pending redirect-method refund becomes successful, deleted
	 * from the browser suite with R4-R7. The first leg runs end to end through
	 * the same production wiring as
	 * {@see self::test_refund_over_fake_transport_persists_refund_identity_status_and_one_note},
	 * fed the recorded Afterpay refund response REC-5a R-a returns while
	 * `pending` (`Fixtures/platform-refunds.json`, pair
	 * `afterpay_clearpay_full_refund_pending`); the second leg processes the
	 * exact `charge.refund.updated` succeeded body REC-5a R-c recorded for
	 * that same refund (`Fixtures/platform-refund-updated-events.json`, pair
	 * `afterpay_clearpay_refund_updated_succeeded`) through the real
	 * {@see WooPaymentsRefundEventHandler}. Client parity: the pending and
	 * succeeded legs render distinct note text (`os:2633-2638`) and the
	 * webhook leg's own note-once identity is distinct from the pending
	 * leg's (`wh:355-359`), so the claim is two notes total, not one.
	 */
	public function test_refund_over_fake_transport_stays_pending_until_webhook_confirms_succeeded(): void {
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
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( true );
		$provider              = $this->create_provider_over_fake_transport( $http_client, $account_service );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 100.00, $reason ),
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
		$fixture = file_get_contents( __DIR__ . '/Fixtures/platform-refund-updated-events.json' );
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
	 * @testdox Capture of $_dataName sends the intent and amount to the platform API and completes.
	 *
	 * @dataProvider provider_capture_requests
	 *
	 * @param string              $transaction_id Order transaction ID.
	 * @param array<string,mixed> $meta           Order meta.
	 * @param float|null          $amount         Capture amount, or null for the order total.
	 * @param string              $intent_id      Intent ID expected in the request.
	 * @param int                 $minor_amount   Amount expected in the request, in minor units.
	 */
	public function test_capture_sends_the_intent_and_amount_to_the_platform_api( string $transaction_id, array $meta, ?float $amount, string $intent_id, int $minor_amount ): void {
		$order      = $this->create_woopayments_order( '10.00' );
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
			->getMock();

		$order->set_transaction_id( $transaction_id );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->with(
				$intent_id,
				$minor_amount,
				$this->callback(
					static function ( array $metadata ): bool {
						return isset( $metadata['order_id'], $metadata['order_key'], $metadata['payment_type'] );
					}
				),
				array()
			)
			->willReturn(
				array(
					'id'     => $intent_id,
					'status' => 'succeeded',
				)
			);

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $amount ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
	}

	/**
	 * Capture requests: the order total by the transaction ID, a context amount, and the intent meta as a fallback. The
	 * answer is the recorded capture_full_amount (Fixtures/platform-manual-capture.json) reduced to its ID and status.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:float|null,3:string,4:int}>
	 */
	public function provider_capture_requests(): array {
		return array(
			'the order total'                          => array( 'pi_capture', array(), null, 'pi_capture', 1000 ),
			'a context amount'                         => array( 'pi_capture_partial', array(), 4.25, 'pi_capture_partial', 425 ),
			'the order total found by the intent meta' => array( '', array( '_intent_id' => 'pi_capture_meta' ), null, 'pi_capture_meta', 1000 ),
		);
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
		$api_client = $this->getMockBuilder( Client::class )
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

		$outcome = $this->create_operation_service( $api_client )
			->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, null, $provider_data ), 'key_capture_fee' );

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
			'capture on status change to completed' => array( array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ), false ),
		);
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
		$api_client = $this->getMockBuilder( Client::class )
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
			$this->create_operation_service( $api_client )
				->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $amount ), 'key_capture_level3' );
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
	 * @testdox Capture should return fee details as a plan without writing order notes.
	 */
	public function test_capture_returns_fee_effect_plan_without_writing_notes(): void {
		$order      = $this->create_woopayments_order( '50.00' );
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention' ) )
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

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY, $outcome->get_data() );
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
	 * @testdox Capture defers settlement exchange-rate metadata to its effect plan.
	 *
	 * A GBP order on a USD account and store. The answer keeps the capture fields of the recorded capture answer
	 * (Fixtures/platform-manual-capture.json, capture_full_amount), whose charge expands its balance transaction with
	 * `exchange_rate`; client 11.1.0 stores that rate for Multi-Currency reporting only
	 * (includes/class-wc-payment-gateway-wcpay.php:2764-2771, :2800-2803).
	 */
	public function test_capture_defers_settlement_exchange_rate_meta_to_effect_plan(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_woopayments_order( '40.00' );
		$order->set_currency( 'GBP' );
		$order->set_transaction_id( 'pi_capture_converted' );
		$order->save();

		$api_client = $this->getMockBuilder( Client::class )
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
							),
						),
					),
				)
			);

		$sut     = $this->create_operation_service(
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
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY, $outcome->get_data() );
		$this->assertSame( 1.33127, $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['balance_transaction']['exchange_rate'] );
	}

	/**
	 * @testdox A capture the platform leaves uncaptured fails with the platform's message and the intent ID, and returns no order meta or note.
	 */
	public function test_failed_capture_returns_the_platform_error_without_order_meta_or_note(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
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

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' );
		$data    = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_capture', $outcome->get_provider_payment_id() );
		$this->assertArrayNotHasKey( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $data );
		$this->assertSame( 'The authorization could not be captured.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
	}

	/**
	 * @testdox A failed cancel re-fetches the intent and treats an already-canceled authorization as canceled.
	 */
	public function test_cancel_exception_self_heals_when_intent_is_already_canceled(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_healed' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->willThrowException( new ApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
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

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status(), 'A transport failure on an intent the provider already canceled is a completed cancel, as in the plugin.' );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'cancel', $plan->get_type() );
		$this->assertSame( 'ch_healed', $plan->get_provider_result()['charges']['data'][0]['id'], 'The effect plan must carry the re-fetched intent.' );
	}

	/**
	 * @testdox A failed cancel stays failed when the re-fetched intent is not canceled.
	 */
	public function test_cancel_exception_stays_failed_when_intent_is_not_canceled(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_live' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->willThrowException( new ApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
		$api_client->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_cancel_live' )
			->willReturn(
				array(
					'id'     => 'pi_cancel_live',
					'status' => 'requires_capture',
				)
			);

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'cancel', $plan->get_type() );
		$this->assertSame( 'requires_capture', $plan->get_provider_result()['status'], 'The re-read status rides on the plan so the applier can record it.' );
	}

	/**
	 * @testdox A failed cancel whose re-fetch also fails keeps the plain failed outcome.
	 */
	public function test_cancel_exception_without_refetch_keeps_plain_failure(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_cancel_dark' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'cancel_intention' )
			->willThrowException( new ApiException( 'Cancel failed.', 'wcpay_cancel_error', 402 ) );
		$api_client->method( 'get_payment_intention' )
			->willThrowException( new ApiException( 'Fetch failed.', 'wcpay_fetch_error', 500 ) );

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertNull( $outcome->get_effect_plan() );
		$this->assertSame( 'Cancel failed.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ], 'The original cancel error stays the actionable one.' );
	}

	/**
	 * @testdox A failed cancel whose re-fetched intent already succeeded records it as succeeded, so the captured payment can be refunded.
	 *
	 * The platform's capture answer is lost, so the order still records requires_capture, and the merchant cancels the
	 * authorization before the payment_intent.succeeded webhook applies. Client 11.1.0 saves the re-fetched status after a
	 * failed cancel (includes/class-wc-payment-gateway-wcpay.php:4083-4092, :4129), and refuses a refund only while the
	 * status is requires_capture (:2917-2924). The cancel refusal is the platform's 400 envelope as recorded for refunds
	 * (`Fixtures/platform-refund-errors.json`) with Stripe's message for a PaymentIntent that cannot be canceled in its status,
	 * HAND-BUILT (https://docs.stripe.com/error-codes/payment-intent-unexpected-state); the re-fetched intent is the
	 * recorded capture_full_amount (`Fixtures/platform-manual-capture.json`) reduced to its ID and status, and the refund
	 * answer the recorded one (`Fixtures/platform-refund-errors.json`, pair `reused_key_identical_params_replays_refund`).
	 */
	public function test_failed_cancel_of_a_captured_intent_records_succeeded_and_the_payment_can_be_refunded(): void {
		$order = $this->create_refund_hold_order();
		$order->set_transaction_id( 'pi_cancel_after_capture' );
		$order->update_meta_data( '_intent_id', 'pi_cancel_after_capture' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );
		$captured               = $this->load_recorded_intent_entry( 'platform-manual-capture.json', 'capture_full_amount' )['body'];
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			self::http_json(
				400,
				array(
					'code'    => 'wcpay_bad_request',
					'message' => 'This PaymentIntent could not be canceled because it has a status of succeeded.',
					'data'    => array( 'status' => 400 ),
				)
			),
			self::http_json(
				200,
				array(
					'id'     => 'pi_cancel_after_capture',
					'status' => $captured['status'],
				)
			),
			self::http_json( 200, $this->recorded_refund_answer( 're_after_failed_cancel', '', 500 ) ),
		);
		$provider               = $this->create_refund_hold_provider( $http_client );

		$outcome = wc_get_container()->get( PaymentProcessingService::class )->cancel( PaymentOperationContext::for_cancel( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );

		list( $result, $row ) = $this->run_gateway_refund( $provider, wc_get_order( $order->get_id() ), 5.00 );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertTrue( $result, 'A captured payment is refunded, not refused as not captured yet.' );
		$trail = self::request_trail( $http_client );
		$this->assertCount( 3, $trail );
		$this->assertStringStartsWith( 'POST intentions/pi_cancel_after_capture/cancel', $trail[0] );
		$this->assertStringStartsWith( 'GET intentions/pi_cancel_after_capture', $trail[1] );
		$this->assertStringStartsWith( 'POST refunds ', $trail[2] );
		$this->assertSame( 're_after_failed_cancel', wc_get_order( $row )->get_meta( '_wcpay_refund_id', true ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 1, self::note_texts_containing( $order, 'Canceling authorization <strong>failed</strong>' ) );
	}

	/**
	 * @testdox A failed capture re-fetches the intent and flags an expired authorization.
	 */
	public function test_capture_exception_detects_expired_authorization_via_refetch(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_expired' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'capture_intention' )
			->willThrowException( new ApiException( 'Capture failed.', 'wcpay_capture_error', 402 ) );
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

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CAPTURE_EXPIRED, $plan->get_type(), 'Expiry must be declared on the plan at the re-fetch site.' );
		$this->assertSame( 'canceled', $plan->get_provider_result()['status'], 'The effect plan must carry the re-fetched canceled intent so the applier composes the expired effects.' );
	}

	/**
	 * @testdox A failed capture keeps the plain failure effects and the capture error when the re-fetch $_dataName.
	 *
	 * @dataProvider provider_capture_failures_kept_plain
	 *
	 * @param callable $refetch Answers the intent re-fetch, or throws.
	 */
	public function test_capture_exception_keeps_plain_failure_effects( callable $refetch ): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'capture_intention', 'get_payment_intention' ) )
			->getMock();

		$order->set_transaction_id( 'pi_capture_failed' );
		$order->save();

		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'capture_intention' )
			->willThrowException( new ApiException( 'Capture failed.', 'wcpay_capture_error', 402 ) );
		$api_client->method( 'get_payment_intention' )->willReturnCallback( $refetch );

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$plan = $outcome->get_effect_plan();
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $plan );
		$this->assertSame( 'failed', $plan->get_provider_result()['status'] );
		$this->assertSame( 'Capture failed.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ], 'The original capture error must survive the re-fetch.' );
	}

	/**
	 * Re-fetches that show no expired authorization: one that fails, and one that finds the intent still capturable. The
	 * capturable answer is the recorded authorize_manual_capture (Fixtures/platform-manual-capture.json) reduced to its ID
	 * and status. On these rows native reads only the re-fetched status, which client 11.1.0 also uses to decide expiry
	 * (includes/class-wc-payment-gateway-wcpay.php:4019-4021). The client also reads the re-fetched charge and ID for its
	 * note and answer (:4035-4036, :4056); these rows do not cover that.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function provider_capture_failures_kept_plain(): array {
		return array(
			'itself fails'                      => array(
				static function (): array {
					throw new ApiException( 'Fetch failed.', 'wcpay_fetch_error', 500 );
				},
			),
			'finds the intent still capturable' => array(
				static fn( string $intent_id ): array => array(
					'id'     => $intent_id,
					'status' => 'requires_capture',
				),
			),
		);
	}

	/**
	 * @testdox Cancel by $_dataName goes through the platform API and plans the cancel effects.
	 *
	 * @dataProvider provider_cancel_intent_sources
	 *
	 * @param string              $transaction_id Order transaction ID.
	 * @param array<string,mixed> $meta           Order meta.
	 * @param string              $intent_id      Intent ID expected in the request.
	 * @param array<string,mixed> $answer         Platform answer.
	 * @param string|null         $charge_id      Charge ID the plan carries, or null for none.
	 */
	public function test_cancel_sends_the_order_intent_to_the_platform_api( string $transaction_id, array $meta, string $intent_id, array $answer, ?string $charge_id ): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'cancel_intention' ) )
			->getMock();

		$order->set_transaction_id( $transaction_id );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		$api_client->expects( $this->once() )
			->method( 'is_available' )
			->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'cancel_intention' )
			->with( $intent_id )
			->willReturn( $answer );

		$sut     = $this->create_operation_service( $api_client );
		$outcome = $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' );

		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( 'cancel', $outcome->get_effect_plan()->get_type() );
		$this->assertSame( $charge_id, $outcome->get_effect_plan()->get_provider_result()['charges']['data'][0]['id'] ?? null );
	}

	/**
	 * Where cancel finds the intent: the transaction ID, or the intent meta when the transaction ID is missing. No cancel
	 * answer is recorded: each is a PaymentIntent reduced to its ID, the canceled status and, on the first row, the charge
	 * ID the cancel plan carries. Client 11.1.0 reads the status of the cancel answer
	 * (includes/class-wc-payment-gateway-wcpay.php:4076-4079, :4101-4102).
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:array<string,mixed>,4:string|null}>
	 */
	public function provider_cancel_intent_sources(): array {
		return array(
			'the transaction ID' => array(
				'pi_cancel',
				array(),
				'pi_cancel',
				array(
					'id'      => 'pi_cancel',
					'status'  => 'canceled',
					'charges' => array(
						'data' => array(
							array( 'id' => 'ch_cancel' ),
						),
					),
				),
				'ch_cancel',
			),
			'the intent meta'    => array(
				'',
				array( '_intent_id' => 'pi_cancel_meta' ),
				'pi_cancel_meta',
				array(
					'id'     => 'pi_cancel_meta',
					'status' => 'canceled',
				),
				null,
			),
		);
	}

	/**
	 * @testdox Failed cancellation results retain diagnostics without success effects.
	 */
	public function test_failed_cancel_retains_plan_without_success_effect_data(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
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

		$outcome = $this->create_operation_service( $api_client )->cancel(
			PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			'key_cancel_failed'
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'Cancellation rejected.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertInstanceOf( WooPaymentsOrderEffectPlan::class, $outcome->get_effect_plan() );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CANCEL, $outcome->get_effect_plan()->get_type() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META_TO_DELETE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
	}

	/**
	 * @testdox Each operation fails closed when the platform API client is unavailable.
	 */
	public function test_operations_fail_closed_without_the_api_client(): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_unavailable' );
		$order->update_meta_data( '_charge_id', 'ch_unavailable' );
		$order->save();
		$sut = $this->create_operation_service();

		$outcomes = array(
			'charge'  => $sut->charge( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_charge' ),
			'refund'  => $sut->refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' ),
			'capture' => $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' ),
			'cancel'  => $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' ),
		);

		foreach ( $outcomes as $operation => $outcome ) {
			$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status(), "The $operation must fail." );
			$this->assertSame( 'wcpay_gateway_unavailable', $outcome->get_data()['error_code'], "The $operation must report the unavailable gateway." );
			$this->assertSame( $operation, $outcome->get_data()['operation'], "The $operation must name itself." );
		}
	}

	/**
	 * @testdox Refund, capture and cancel fail closed without a platform call when the order holds no charge or intent ID.
	 */
	public function test_operations_fail_closed_without_a_charge_or_intent_id(): void {
		$order      = $this->create_woopayments_order();
		$api_client = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'refund_charge', 'capture_intention', 'cancel_intention' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->never() )->method( 'refund_charge' );
		$api_client->expects( $this->never() )->method( 'capture_intention' );
		$api_client->expects( $this->never() )->method( 'cancel_intention' );
		$sut = $this->create_operation_service( $api_client );

		$outcomes = array(
			'refund'  => $sut->refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 3.50, 'Adjustment' ), 'key_refund' ),
			'capture' => $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_capture' ),
			'cancel'  => $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'key_cancel' ),
		);

		foreach ( $outcomes as $operation => $outcome ) {
			$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status(), "The $operation must fail." );
			$this->assertSame( 'wcpay_gateway_unavailable', $outcome->get_data()['error_code'], "The $operation must report the unavailable gateway." );
			$this->assertSame( $operation, $outcome->get_data()['operation'], "The $operation must name itself." );
		}
	}

	/**
	 * Charge an order through native transport with a WooPay intent in the checkout context.
	 *
	 * @param WC_Order $order            Order to charge.
	 * @param Client   $api_client       Transport double.
	 * @param string   $woopay_intent_id Intent id WooPay sent.
	 * @return PaymentOutcome
	 */
	private function charge_with_woopay_intent( WC_Order $order, Client $api_client, string $woopay_intent_id ): PaymentOutcome {
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_native' );

		$sut = $this->create_operation_service( $api_client, $customer_service, null, $this->create_account_service( true ) );

		return $sut->charge(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
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
	 * @param array<string,mixed> $payment_intent PaymentIntent the read returns.
	 * @param array<string,mixed> $setup_intent   SetupIntent the read returns.
	 * @param ApiException|null   $read_failure   Failure every read throws instead.
	 * @return Client
	 */
	private function create_woopay_intent_api_client( array $payment_intent, array $setup_intent = array(), ?ApiException $read_failure = null ): Client {
		return new class( $payment_intent, $setup_intent, $read_failure ) extends Client {
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
			 * @var ApiException|null
			 */
			private ?ApiException $read_failure;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $payment_intent PaymentIntent the read returns.
			 * @param array<string,mixed> $setup_intent   SetupIntent the read returns.
			 * @param ApiException|null   $read_failure   Failure every read throws.
			 */
			public function __construct( array $payment_intent, array $setup_intent, ?ApiException $read_failure ) {
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
			 * @throws ApiException When the double is set to fail.
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
			 * @throws ApiException When the double is set to fail.
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
	 * Create the adapter, with an unavailable API client unless one is given.
	 *
	 * @param Client|null                         $api_client WooPayments API client.
	 * @param WooPaymentsCustomerService|null     $customer_service WooPayments customer service.
	 * @param WooPaymentsTokenService|null        $token_service WooPayments token service.
	 * @param WooPaymentsAccountService|null      $account_service WooPayments account service.
	 * @param WooPaymentsOrderDataService|null    $order_data_service WooPayments order data service.
	 * @param WooPaymentsFraudRulesetService|null $fraud_ruleset_service Fraud ruleset service.
	 * @return WooPaymentsPaymentOperationService
	 */
	private function create_operation_service( ?Client $api_client = null, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsAccountService $account_service = null, ?WooPaymentsOrderDataService $order_data_service = null, ?WooPaymentsFraudRulesetService $fraud_ruleset_service = null ): WooPaymentsPaymentOperationService {
		$api_client = $api_client ?? $this->getMockBuilder( Client::class )
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
		$request_builder->init( $account_service, $order_data_service, $token_service, new PaymentMethodRegistry() );

		if ( null === $fraud_ruleset_service ) {
			$fraud_ruleset_service = $this->getMockBuilder( WooPaymentsFraudRulesetService::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'is_fraud_rule_active' ) )
				->getMock();
			$fraud_ruleset_service->method( 'is_fraud_rule_active' )->willReturnCallback(
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
		$ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$refund_ambiguity_service = new WooPaymentsRefundAmbiguityService();
		$refund_ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );

		$sut = new WooPaymentsPaymentOperationService();
		$sut->init(
			$api_client,
			$customer_service,
			$request_builder,
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			$fraud_ruleset_service,
			$ambiguity_service,
			$refund_ambiguity_service,
			wc_get_container()->get( WooPaymentsLogger::class ),
			wc_get_container()->get( WooPaymentsLevel3Service::class )
		);

		return $sut;
	}

	/**
	 * Create a real customer service backed by the recording native API client.
	 *
	 * @param Client $api_client Recording native API client.
	 * @return WooPaymentsCustomerService
	 */
	private function create_real_customer_service( Client $api_client ): WooPaymentsCustomerService {
		$service = new WooPaymentsCustomerService();
		$service->init( $api_client, $this->create_account_service( false ), new WooPaymentsSessionService() );

		return $service;
	}

	/**
	 * Create a native API client that records each PaymentIntent request and answers it with the given intent.
	 *
	 * @param array<string,mixed> $answer PaymentIntent every request returns.
	 * @return Client
	 */
	private function create_answering_charge_api_client( array $answer ): Client {
		return new class( $answer ) extends Client {
			/** @var array<int,array<string,mixed>> */
			public array $requests = array();

			/** @var array<string,mixed> */
			private array $answer;

			/**
			 * @param array<string,mixed> $answer PaymentIntent every request returns.
			 */
			public function __construct( array $answer ) {
				$this->answer = $answer;
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
			 * Record a PaymentIntent request.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->requests[] = $request_data;

				return $this->answer;
			}
		};
	}

	/**
	 * Create a native API client that records customer and intent operations.
	 *
	 * @param bool $missing_customer_on_first_intent Whether the first intent should report a missing customer.
	 * @return Client
	 */
	private function create_recording_customer_intent_api_client( bool $missing_customer_on_first_intent = false ): Client {
		return new class( $missing_customer_on_first_intent ) extends Client {
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
					throw new ApiException( 'No such customer.', 'resource_missing', 404 );
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
					throw new ApiException( 'No such customer.', 'resource_missing', 404 );
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
	 * Build the settings service and the fraud ruleset service it reads, over a connected account and the given platform client.
	 *
	 * @param RecordingSettingsApiClient $api_client Platform client the fraud ruleset is read through.
	 * @return array{0:WooPaymentsSettingsService,1:WooPaymentsFraudRulesetService}
	 */
	private function create_fraud_reading_services( RecordingSettingsApiClient $api_client ): array {
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'           => 'acct_fraud_test',
					'is_live'              => true,
					'test_publishable_key' => 'pk_test_fraud',
					'live_publishable_key' => 'pk_live_fraud',
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new ApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy(), $database_cache );

		$fraud_ruleset_service = new WooPaymentsFraudRulesetService();
		$fraud_ruleset_service->init( $account_service, $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$duplicate_gateway_methods_service = new WooPaymentsDuplicateGatewayMethodsService();
		$duplicate_gateway_methods_service->init( $account_service, wc_get_container()->get( WooPaymentsLogger::class ) );
		$settings_service = new WooPaymentsSettingsService();
		$settings_service->init( $account_service, $api_client, new RecordingPmPromotionsService(), wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class ), new PaymentMethodRegistry(), wc_get_container()->get( WooPaymentsStripeBillingModule::class ), $fraud_ruleset_service, $duplicate_gateway_methods_service, $this->create_woopay_eligibility( $account_service ), new RecordingWooPayAppearanceStore( new RecordingWooPaySessionService() ), wc_get_container()->get( WooPaymentsFrontendTrackingController::class ), wc_get_container()->get( WooPaymentsLogger::class ), new RecordingStoreSetupReportController() );

		return array( $settings_service, $fraud_ruleset_service );
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
		// A connected account with a status and payments enabled, as recorded in Fixtures/platform-test-drive-account.json `account`.
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array_merge(
				array(
					'country'          => 'US',
					'status'           => 'complete',
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
		$token_service->init( $details_service, wc_get_container()->get( Client::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ), wc_get_container()->get( PaymentMethodRegistry::class ) );

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
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
		$controller->register();
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
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Create the WooPay eligibility rules over an account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsWooPayEligibility
	 */
	private function create_woopay_eligibility( WooPaymentsAccountService $account_service ): WooPaymentsWooPayEligibility {
		$woopay_eligibility = new WooPaymentsWooPayEligibility();
		$woopay_eligibility->init( $account_service );

		return $woopay_eligibility;
	}
}
