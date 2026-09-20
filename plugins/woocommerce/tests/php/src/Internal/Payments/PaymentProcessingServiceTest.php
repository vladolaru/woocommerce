<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentExceptionPolicy;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationIdempotency;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use RuntimeException;
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentProcessingService class.
 */
class PaymentProcessingServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentProcessingService
	 */
	private $sut;

	/**
	 * Order payment store.
	 *
	 * @var OrderPaymentStore
	 */
	private $store;

	/**
	 * Payment operation idempotency service.
	 *
	 * @var PaymentOperationIdempotency
	 */
	private $idempotency;

	/**
	 * Persistence profile used by the recording WooPayments provider.
	 *
	 * @var ProviderPersistenceProfile
	 */
	private ProviderPersistenceProfile $persistence_profile;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                 = wc_get_container()->get( PaymentProcessingService::class );
		$this->store               = wc_get_container()->get( OrderPaymentStore::class );
		$this->idempotency         = wc_get_container()->get( PaymentOperationIdempotency::class );
		$this->persistence_profile = new RecordingProviderPersistenceProfile( OrderPaymentStore::GATEWAY_ID );
	}

	/**
	 * @testdox Should call the provider with a per-attempt key and complete the order for completed outcomes.
	 */
	public function test_process_checkout_completes_order_for_completed_outcome(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'pi_test',
				'',
				'pm_test',
				'cus_test',
				array(
					'meta' => array(
						'_charge_id'        => 'ch_test',
						'_intention_status' => 'succeeded',
					),
				)
			)
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'pm_test', $result['payment_method'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$provider->last_idempotency_key,
			'The provider must receive a fresh per-attempt key, not a derived one.'
		);
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_test', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_test', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'ch_test', $order->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox A failed checkout attempt can be followed by a fresh successful attempt.
	 */
	public function test_failed_checkout_attempt_can_be_followed_by_a_fresh_successful_attempt(): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$failed_outcome         = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_failed',
			'',
			'pm_failed',
			'',
			array(
				'meta' => array(
					'_intention_status' => 'requires_payment_method',
				),
			)
		);
		$success_outcome        = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_succeeded',
			'',
			'pm_succeeded',
			'',
			array(
				'meta' => array(
					'_charge_id'        => 'ch_succeeded',
					'_intention_status' => 'succeeded',
				),
			)
		);
		$provider               = new class( $failed_outcome, $success_outcome ) extends RecordingProvider {
			/**
			 * Outcomes returned in call order.
			 *
			 * @var PaymentOutcome[]
			 */
			private array $outcomes;

			/**
			 * Attempt keys received by the provider.
			 *
			 * @var string[]
			 */
			public array $idempotency_keys = array();

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome ...$outcomes Outcomes returned in call order.
			 */
			public function __construct( PaymentOutcome ...$outcomes ) {
				parent::__construct( $outcomes[0] );
				$this->outcomes = $outcomes;
			}

			/**
			 * Charge an order through the provider.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Per-attempt idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				$this->idempotency_keys[]   = $idempotency_key;
				$this->last_idempotency_key = $idempotency_key;
				$outcome                    = $this->outcomes[ $this->charge_calls ];
				++$this->charge_calls;

				return $outcome;
			}
		};
		$payment_complete_calls = 0;
		$observer               = static function ( int $order_id ) use ( $order, &$payment_complete_calls ): void {
			if ( $order->get_id() === $order_id ) {
				++$payment_complete_calls;
			}
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$first_result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_failed' ), $provider );
			$this->assertFalse( $this->store->is_order_payment_locked( $order, $this->persistence_profile, $provider->idempotency_keys[0] ), 'The failed attempt must release the order payment lock.' );

			$second_result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_succeeded' ), $provider );
			$this->assertFalse( $this->store->is_order_payment_locked( $order, $this->persistence_profile, $provider->idempotency_keys[1] ), 'The successful attempt must release the order payment lock.' );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 2, $provider->charge_calls );
		$this->assertCount( 2, $provider->idempotency_keys );
		foreach ( $provider->idempotency_keys as $idempotency_key ) {
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$idempotency_key,
				'Every checkout attempt must receive a nonempty UUID-v4 key.'
			);
		}
		$this->assertNotSame( $provider->idempotency_keys[0], $provider->idempotency_keys[1], 'A retry must not reuse the failed attempt key.' );
		$this->assertSame( 'failure', $first_result['result'] );
		$this->assertSame( 'success', $second_result['result'] );
		$this->assertSame( 1, $payment_complete_calls, 'The two attempts must apply exactly one paid effect.' );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pi_succeeded', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_succeeded', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'ch_succeeded', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox A failed outcome flagged to preserve the order status records meta and note without failing the order.
	 */
	public function test_process_checkout_preserves_order_status_for_flagged_failed_outcome(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_blocked_test',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE => 'wcpay_blocked_by_fraud_rule',
					PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
					PaymentOutcome::DATA_META       => array(
						'_wcpay_fraud_outcome_status' => 'block',
						'_intention_status'           => 'canceled',
					),
					PaymentOutcome::DATA_NOTE       => 'A payment was blocked by risk filters.',
				)
			)
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failure', $result['result'], 'The shopper-facing checkout result must still be a failure.' );
		$this->assertSame( 'pending', $order->get_status(), 'A blocked payment must not fail the order; the merchant decides whether to cancel.' );
		$this->assertSame( 'block', $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_blocked_test', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', (string) $order->get_transaction_id(), 'A blocked attempt must not claim the order transaction id.' );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertContains( 'A payment was blocked by risk filters.', wp_list_pluck( $notes, 'content' ) );
	}

	/**
	 * @testdox A preserve-status failed outcome for an already-paid order is skipped by the late-failure guard.
	 */
	public function test_preserve_status_failed_outcome_does_not_overwrite_paid_order(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->payment_complete( 'pi_paid_first' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid() );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_blocked_late',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE => 'wcpay_blocked_by_fraud_rule',
					PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
					PaymentOutcome::DATA_META       => array(
						'_wcpay_fraud_outcome_status' => 'block',
						'_intention_status'           => 'canceled',
					),
					PaymentOutcome::DATA_NOTE       => 'A payment was blocked by risk filters.',
				)
			)
		);

		$this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid(), 'A late blocked outcome must not disturb a paid order.' );
		$this->assertSame( '', (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ), 'Block meta must not overwrite a paid order.' );
		$this->assertNotSame( 'canceled', (string) $order->get_meta( '_intention_status', true ) );
		$this->assertNotContains( 'A payment was blocked by risk filters.', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * @testdox Provider throwables emit one structured operation log with idempotency correlation.
	 * @dataProvider provider_failure_operations
	 *
	 * @param string $operation Provider operation.
	 */
	public function test_provider_throwable_emits_one_structured_operation_log( string $operation ): void {
		$order     = $this->create_woopayments_order( '10.00' );
		$exception = new class( 'Provider transport failed.' ) extends RuntimeException {
			/**
			 * Get the provider error code.
			 *
			 * @return string
			 */
			public function get_error_code(): string {
				return 'provider_transport_failure';
			}
		};

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_FAILED ), $exception ) extends RecordingProvider {
			private \Throwable $exception;

			public function __construct( PaymentOutcome $outcome, \Throwable $exception ) {
				parent::__construct( $outcome );
				$this->exception = $exception;
			}

			public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				// Recorded so the test can prove the log correlates to the exact key the
				// provider received — charge keys are minted per attempt, not derivable.
				$this->last_idempotency_key = $idempotency_key;
				throw $this->exception;
			}

			public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				throw $this->exception;
			}

			public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				throw $this->exception;
			}

			public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				throw $this->exception;
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$logger = $this->create_fake_logger();
		$sut    = new PaymentProcessingService( $logger );
		$sut->init(
			$this->store,
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->idempotency,
			wc_get_container()->get( PaymentExceptionPolicy::class )
		);

		switch ( $operation ) {
			case 'charge':
				$outcome                  = $sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_failure' ), $provider );
				$expected_idempotency_key = $provider->last_idempotency_key;
				$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $expected_idempotency_key );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
				break;

			case 'refund':
				$result                   = $sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.5, 'Adjustment' ), $provider );
				$expected_idempotency_key = $this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 2.5, 'USD', 'Adjustment' );
				$this->assertWPError( $result );
				break;

			case 'capture':
				$outcome                  = $sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, 4.25 ), $provider );
				$expected_idempotency_key = $this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 4.25, 'USD' );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
				break;

			default:
				$outcome                  = $sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
				$expected_idempotency_key = $this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'cancel', 10.0, 'USD' );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		}

		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'Native payment provider operation threw an exception.', $logger->error_calls[0]['message'] );
		$this->assertSame(
			array(
				'source'              => 'woopayments-payments',
				'operation'           => $operation,
				'order_id'            => $order->get_id(),
				'idempotency_key'     => $expected_idempotency_key,
				'exception_class'     => get_class( $exception ),
				'exception_message'   => 'Provider transport failed.',
				'provider_error_code' => 'provider_transport_failure',
			),
			$logger->error_calls[0]['context']
		);
	}

	/**
	 * Provider operations that normalize throwables.
	 *
	 * @return array<string,array{string}>
	 */
	public static function provider_failure_operations(): array {
		return array(
			'charge'  => array( 'charge' ),
			'refund'  => array( 'refund' ),
			'capture' => array( 'capture' ),
			'cancel'  => array( 'cancel' ),
		);
	}

	/**
	 * @testdox Should return a redirect result without completing the order for redirect outcomes.
	 */
	public function test_process_checkout_returns_redirect_without_completing_order(): void {
		$order    = $this->create_woopayments_order( '15.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_REQUIRES_REDIRECT,
				'pi_redirect',
				'https://example.test/redirect',
				'pm_redirect',
				'',
				array(
					'meta' => array(
						'_intention_status' => 'requires_action',
					),
				)
			)
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_redirect' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/redirect', $result['redirect'] );
		$this->assertSame( 'pm_redirect', $result['payment_method'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_redirect', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox Should return the checkout outcome while applying lifecycle changes.
	 */
	public function test_process_checkout_outcome_returns_outcome_after_lifecycle_application(): void {
		$order   = $this->create_woopayments_order( '15.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:1:secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'meta' => array(
					'_charge_id'             => 'ch_requires_action',
					'_wcpay_intent_currency' => 'usd',
				),
			)
		);

		$provider = new RecordingProvider( $outcome );
		$result   = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_requires_action' ), $provider );
		$order    = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_requires_action', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pm_requires_action', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_requires_action', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'ch_requires_action', $order->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox Should persist checkout outcome metadata from the provider mapper without a legacy profile.
	 */
	public function test_process_checkout_outcome_uses_provider_outcome_metadata_mapper(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$outcome  = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'remote_payment_123',
			'',
			'remote_method_123',
			'remote_customer_123'
		);
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOutcomeMetadataMapper {

			/**
			 * Get the provider/gateway ID.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'offline_redirect_provider';
			}

			/**
			 * Get the provider persistence profile.
			 *
			 * @return ProviderPersistenceVocabulary
			 */
			public function get_persistence_profile(): ProviderPersistenceVocabulary {
				return new class() implements ProviderPersistenceVocabulary {

					/**
					 * Get the provider gateway ID.
					 *
					 * @return string
					 */
					public function get_gateway_id(): string {
						return 'offline_redirect_provider';
					}

					/**
					 * Get the provider gateway ID prefix.
					 *
					 * @return string
					 */
					public function get_gateway_id_prefix(): string {
						return 'offline_redirect_provider_';
					}

					/**
					 * Get the order payment lock key.
					 *
					 * @param WC_Order $order Order object.
					 * @return string
					 */
					public function get_order_lock_key( WC_Order $order ): string {
						return 'offline_provider_processing_' . $order->get_id();
					}

					/**
					 * Get the lock sentinel value.
					 *
					 * @return string
					 */
					public function get_lock_sentinel(): string {
						return '-1';
					}

					/**
					 * Get the lock time-to-live in seconds.
					 *
					 * @return int
					 */
					public function get_lock_ttl_seconds(): int {
						return 300;
					}

					/**
					 * Get the processed refund link meta key.
					 *
					 * @return string
					 */
					public function get_processed_refund_link_meta_key(): string {
						return '_offline_provider_refund_id';
					}

					/**
					 * Get preserved order/refund meta keys.
					 *
					 * @return string[]
					 */
					public function get_preserved_payment_meta_keys(): array {
						return array( '_offline_intent_id', '_offline_method_id', '_offline_customer_id', '_offline_status' );
					}

				};
			}

			/**
			 * Map a neutral outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				return array(
					'_offline_customer_id' => $outcome->get_customer_id(),
					'_offline_intent_id'   => $outcome->get_provider_payment_id(),
					'_offline_method_id'   => $outcome->get_payment_method_id(),
					'_offline_status'      => 'offline-' . $outcome->get_status(),
				);
			}

			/**
			 * Map a failed capture outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array {
				return array( '_offline_status' => 'offline-capture-failed' );
			}
		};

		$this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, 'offline_redirect_provider', 'remote_method_123' ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'remote_payment_123', $order->get_meta( '_offline_intent_id', true ) );
		$this->assertSame( 'remote_method_123', $order->get_meta( '_offline_method_id', true ) );
		$this->assertSame( 'remote_customer_123', $order->get_meta( '_offline_customer_id', true ) );
		$this->assertSame( 'offline-completed', $order->get_meta( '_offline_status', true ) );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ), 'WooPayments intent meta must not be written for providers with their own profile vocabulary.' );
	}

	/**
	 * @testdox A successful charge whose lifecycle application throws must stay successful, persist the payment reference, and log.
	 */
	public function test_process_checkout_outcome_keeps_order_reconcilable_when_apply_throws(): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_post_charge',
			'',
			'pm_post_charge',
			'cus_post_charge'
		);

		$sut         = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider    = new RecordingProvider( $outcome );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$result = $sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_post_charge' ), $provider );

		remove_all_filters( 'woocommerce_logging_class' );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $result, 'A post-charge apply failure must not downgrade a successful outcome.' );
		$this->assertTrue( $result->is_successful(), 'A successful charge must remain successful even when lifecycle application fails.' );
		$this->assertSame( 'pi_post_charge', $order->get_transaction_id(), 'The provider payment reference must be persisted so the charge stays reconcilable.' );

		$this->assertCount( 1, $fake_logger->error_calls, 'A post-charge apply failure must be logged at error level.' );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'native-payments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'pi_post_charge', $context['payment_reference'] );
	}

	/**
	 * @testdox A failed charge whose lifecycle application throws must rethrow rather than swallow the failure.
	 */
	public function test_process_checkout_outcome_rethrows_when_apply_throws_for_failed_outcome(): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array( 'error_message' => 'Declined.' )
		);

		$sut      = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider = new RecordingProvider( $outcome );

		$this->expectException( RuntimeException::class );

		$sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_failed' ), $provider );
	}

	/**
	 * @testdox A post-charge apply failure must not overwrite a transaction reference the order already carries.
	 */
	public function test_process_checkout_outcome_does_not_overwrite_existing_transaction_reference(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_existing_reference' );
		$order->save();

		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_post_charge', '', 'pm_post_charge' );

		$sut      = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider = new RecordingProvider( $outcome );

		$result = $sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_post_charge' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $result->is_successful() );
		$this->assertSame( 'pi_existing_reference', $order->get_transaction_id(), 'An existing transaction reference must be preserved.' );
	}

	/**
	 * @testdox Provider effects are applied before payment completion hooks run.
	 */
	public function test_process_checkout_outcome_applies_provider_effects_before_lifecycle(): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$outcome         = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_effect_ordering', '', 'pm_effect_ordering' );
		$provider        = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplier {
			/**
			 * Apply provider effects before the generic lifecycle.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				$context->get_order()->update_meta_data( '_provider_effect_operation', $operation );
				$context->get_order()->save_meta_data();

				return $outcome;
			}
		};
		$observed_effect = '';
		$observer        = static function ( int $order_id ) use ( &$observed_effect ): void {
			$completed_order = wc_get_order( $order_id );
			$observed_effect = $completed_order instanceof WC_Order
				? (string) $completed_order->get_meta( '_provider_effect_operation', true )
				: '';
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_effect_ordering' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$this->assertSame( $outcome, $result );
		$this->assertSame( 'charge', $observed_effect );
	}

	/**
	 * @testdox Providers can opt into a separate post-lifecycle effect contract without changing the existing effect interface.
	 */
	public function test_post_lifecycle_effect_contract_is_available(): void {
		$this->assertTrue( interface_exists( ProviderPostLifecycleEffectApplier::class ) );
	}

	/**
	 * @testdox Optional post-lifecycle provider effects run after payment completion hooks.
	 */
	public function test_process_checkout_outcome_applies_post_lifecycle_provider_effects_after_lifecycle(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$sequence = new \ArrayObject();
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_post_lifecycle', '', 'pm_post_lifecycle' );
		$provider = new class( $outcome, $sequence ) extends RecordingProvider implements ProviderOperationEffectApplier, ProviderPostLifecycleEffectApplier {
			/**
			 * Observed lifecycle sequence.
			 *
			 * @var \ArrayObject<int,string>
			 */
			private \ArrayObject $sequence;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome  Provider outcome.
			 * @param \ArrayObject   $sequence Observed lifecycle sequence.
			 */
			public function __construct( PaymentOutcome $outcome, \ArrayObject $sequence ) {
				parent::__construct( $outcome );
				$this->sequence = $sequence;
			}

			/**
			 * Record the pre-lifecycle provider effect.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				$this->sequence[] = 'pre:' . $operation;
				$context->get_order()->set_payment_method_title( 'WooPayments' );
				$context->get_order()->save();

				return $outcome;
			}

			/**
			 * Record and apply the post-lifecycle provider effect.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 */
			public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $outcome );
				$this->sequence[] = 'post:' . $operation;
				$context->get_order()->set_payment_method_title( 'Visa credit card' );
				$context->get_order()->save();
			}
		};
		$observer = static function ( int $order_id ) use ( $sequence ): void {
			$completed_order = wc_get_order( $order_id );
			$sequence[]      = 'lifecycle:' . ( $completed_order instanceof WC_Order ? $completed_order->get_payment_method_title() : '' );
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_post_lifecycle' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result );
		$this->assertSame( array( 'pre:charge', 'lifecycle:WooPayments', 'post:charge' ), $sequence->getArrayCopy() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
	}

	/**
	 * @testdox A completed PaymentIntent exposes its card title to synchronous lifecycle observers for direct checkout and scheduled renewal.
	 * @dataProvider completed_payment_intent_context_data
	 *
	 * @param bool $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 */
	public function test_completed_payment_intent_exposes_card_title_to_synchronous_lifecycle_observers( bool $scheduled_subscription_payment ): void {
		$order                 = $this->create_woopayments_order( '10.00' );
		$unrelated_order       = $this->create_woopayments_order( '10.00' );
		$subscription          = $this->create_woopayments_order( '10.00' );
		$result                = $this->completed_link_payment_intent_result();
		$sequence              = new \ArrayObject();
		$provider              = $this->completed_payment_intent_provider( $result, $sequence );
		$context               = $this->completed_payment_intent_context( $order, $scheduled_subscription_payment );
		$filter_calls          = 0;
		$credential_sync_calls = 0;
		$display_sync_calls    = 0;
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function ( string $suffix ) use ( &$filter_calls, $sequence ): string {
				++$filter_calls;
				$sequence[] = 'suffix';
				return $suffix;
			}
		);
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription, &$credential_sync_calls, &$display_sync_calls, $sequence ): array {
				if ( $order->get_id() !== $filtered_order->get_id() ) {
					return $subscriptions;
				}

				if ( 'Link (WooPayments)' === $filtered_order->get_payment_method_title() ) {
					++$display_sync_calls;
					$sequence[] = 'display-sync';
				} else {
					++$credential_sync_calls;
					$sequence[] = 'credential-sync';
				}
				return array( $subscription );
			},
			10,
			2
		);
		$observed = array();
		$observer = static function ( int $order_id ) use ( $order, &$observed, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded = wc_get_order( $order_id );
			if ( ! $reloaded instanceof WC_Order ) {
				return;
			}

			$observed[] = array(
				'id'                   => $reloaded->get_id(),
				'payment_method'       => $reloaded->get_payment_method(),
				'payment_method_title' => $reloaded->get_payment_method_title(),
				'last4'                => $reloaded->get_meta( 'last4', true ),
				'card_brand'           => $reloaded->get_meta( '_card_brand', true ),
			);
			$sequence[] = 'lifecycle:' . $reloaded->get_payment_method_title();
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
			remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
			remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
		}

		$order           = wc_get_order( $order->get_id() );
		$unrelated_order = wc_get_order( $unrelated_order->get_id() );

		$this->assertSame(
			array(
				array(
					'id'                   => $order instanceof WC_Order ? $order->get_id() : 0,
					'payment_method'       => OrderPaymentStore::GATEWAY_ID,
					'payment_method_title' => 'Link (WooPayments)',
					'last4'                => '',
					'card_brand'           => '',
				),
			),
			$observed
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'Link (WooPayments)', $order->get_payment_method_title() );
		$this->assertSame( 1, $filter_calls );
		$this->assertSame( $scheduled_subscription_payment ? 1 : 0, $credential_sync_calls );
		$this->assertSame( 1, $display_sync_calls );
		$this->assertSame(
			$scheduled_subscription_payment
				? array( 'transport:scheduled', 'credential-sync', 'suffix', 'display-sync', 'lifecycle:Link (WooPayments)', 'finalization' )
				: array( 'transport:direct', 'suffix', 'display-sync', 'lifecycle:Link (WooPayments)', 'finalization' ),
			$sequence->getArrayCopy()
		);
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 'Link (WooPayments)', $subscription->get_payment_method_title() );
		$this->assertInstanceOf( WC_Order::class, $unrelated_order );
		$this->assertSame( 'pending', $unrelated_order->get_status() );
		$this->assertSame( '', $unrelated_order->get_payment_method_title() );
	}

	/**
	 * Provide direct-checkout and scheduled-renewal contexts for the synchronous observer regression.
	 *
	 * @return array<string,array{bool}>
	 */
	public function completed_payment_intent_context_data(): array {
		return array(
			'direct checkout'   => array( false ),
			'scheduled renewal' => array( true ),
		);
	}

	/**
	 * @testdox A completed card PaymentIntent exposes its Visa title and card metadata to synchronous lifecycle observers for direct checkout and scheduled renewal.
	 * @dataProvider completed_payment_intent_context_data
	 *
	 * @param bool $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 */
	public function test_completed_card_payment_intent_exposes_card_title_and_metadata_to_synchronous_lifecycle_observers( bool $scheduled_subscription_payment ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_payment_method( 'cheque' );
		$order->save();
		$sequence = new \ArrayObject();
		$provider = $this->completed_payment_intent_provider( $this->completed_card_payment_intent_result(), $sequence );
		$context  = $this->completed_payment_intent_context( $order, $scheduled_subscription_payment );
		$observed = array();
		$observer = static function ( int $order_id ) use ( $order, &$observed, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded = wc_get_order( $order_id );
			if ( ! $reloaded instanceof WC_Order ) {
				return;
			}

			$observed[] = array(
				'payment_method'       => $reloaded->get_payment_method(),
				'payment_method_title' => $reloaded->get_payment_method_title(),
				'last4'                => $reloaded->get_meta( 'last4', true ),
				'card_brand'           => $reloaded->get_meta( '_card_brand', true ),
			);
			$sequence[] = 'lifecycle:' . $reloaded->get_payment_method_title();
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				array(
					'payment_method'       => OrderPaymentStore::GATEWAY_ID,
					'payment_method_title' => 'Visa credit card',
					'last4'                => '4242',
					'card_brand'           => 'visa',
				),
			),
			$observed
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
		$this->assertSame( '4242', $order->get_meta( 'last4', true ) );
		$this->assertSame( 'visa', $order->get_meta( '_card_brand', true ) );
		$this->assertSame(
			$scheduled_subscription_payment
				? array( 'transport:scheduled', 'lifecycle:Visa credit card', 'finalization' )
				: array( 'transport:direct', 'lifecycle:Visa credit card', 'finalization' ),
			$sequence->getArrayCopy()
		);
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox Completed express PaymentIntents ignore malformed title suffix filter returns through the payment lifecycle.
	 * @dataProvider malformed_payment_request_suffix_data
	 *
	 * @param mixed $suffix Filter return value.
	 */
	public function test_completed_express_payment_intents_ignore_malformed_title_suffix_filter_returns_through_payment_lifecycle( $suffix ): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$sequence        = new \ArrayObject();
		$provider        = $this->completed_payment_intent_provider( $this->completed_link_payment_intent_result(), $sequence );
		$filter_calls    = 0;
		$lifecycle_calls = 0;
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function () use ( $suffix, &$filter_calls, $sequence ) {
				++$filter_calls;
				$sequence[] = 'suffix';
				return $suffix;
			}
		);
		$observer = static function ( int $order_id ) use ( $order, &$lifecycle_calls, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			++$lifecycle_calls;
			$reloaded   = wc_get_order( $order_id );
			$sequence[] = 'lifecycle:' . ( $reloaded instanceof WC_Order ? $reloaded->get_payment_method_title() : '' );
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_malformed_suffix' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
			remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 1, $filter_calls );
		$this->assertSame( 1, $lifecycle_calls );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'Link (WooPayments)', $order->get_payment_method_title() );
		$this->assertSame( array( 'transport:direct', 'suffix', 'lifecycle:Link (WooPayments)', 'finalization' ), $sequence->getArrayCopy() );
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * Provide malformed public suffix filter returns.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function malformed_payment_request_suffix_data(): array {
		return array(
			'array'                 => array( array( 'unexpected' ) ),
			'non-stringable object' => array( new \stdClass() ),
		);
	}

	/**
	 * @testdox Successful charges stay reconcilable when provider effect application throws.
	 */
	public function test_process_checkout_outcome_keeps_provider_success_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_effect_failure', '', 'pm_effect_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplier {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after the remote payment succeeded.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_effect_failure' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $result );
		$this->assertSame( 'pi_effect_failure', $order->get_transaction_id() );
	}

	/**
	 * @testdox Referenced customer-action outcomes stay reconcilable when provider effect application throws.
	 */
	public function test_process_checkout_outcome_keeps_referenced_customer_action_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_action_effect_failure',
			'#wcpay-confirm-pi:1:secret:nonce',
			'pm_action_effect_failure'
		);
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplier {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after a referenced provider response.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider display effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_action_effect_failure' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $result );
		$this->assertSame( 'pi_action_effect_failure', $order->get_transaction_id() );
		$this->assertSame( 'pi_action_effect_failure', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_action_effect_failure', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Recovery profile mapping failures cannot replace a referenced provider outcome.
	 */
	public function test_process_checkout_outcome_keeps_provider_result_when_recovery_profile_mapping_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_profile_recovery_failure', '', 'pm_profile_recovery_failure' );
		$profile  = new class( OrderPaymentStore::GATEWAY_ID ) extends RecordingProviderPersistenceProfile {
			/**
			 * Number of recovery mapping calls.
			 *
			 * @var int
			 */
			public int $outcome_meta_calls = 0;

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail recovery metadata mapping.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 * @throws RuntimeException Always.
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );
				++$this->outcome_meta_calls;
				throw new RuntimeException( 'Recovery metadata mapping failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$provider = new class( $outcome, $profile ) extends RecordingProvider implements ProviderOperationEffectApplier {
			/**
			 * Persistence profile.
			 *
			 * @var ProviderPersistenceProfile
			 */
			private ProviderPersistenceProfile $profile;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome             $outcome Provider outcome.
			 * @param ProviderPersistenceProfile $profile Persistence profile.
			 */
			public function __construct( PaymentOutcome $outcome, ProviderPersistenceProfile $profile ) {
				parent::__construct( $outcome );
				$this->profile = $profile;
			}

			/**
			 * Get the provider persistence profile.
			 *
			 * @return ProviderPersistenceProfile
			 */
			public function get_persistence_profile(): ProviderPersistenceProfile {
				return $this->profile;
			}

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after the remote payment succeeded.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_profile_recovery_failure' ), $provider );

		$this->assertSame( $outcome, $result );
		$this->assertSame( 1, $profile->outcome_meta_calls );
	}

	/**
	 * @testdox Recovery logging failures cannot replace a referenced provider outcome.
	 */
	public function test_process_checkout_outcome_keeps_provider_result_when_recovery_logging_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_logger_recovery_failure', '', 'pm_logger_recovery_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplier {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after the remote payment succeeded.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$logger   = $this->create_throwing_logger();
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		try {
			$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_logger_recovery_failure' ), $provider );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
		}

		$this->assertSame( $outcome, $result );
	}

	/**
	 * @testdox Successful captures stay reconcilable when provider effect application throws.
	 */
	public function test_capture_keeps_provider_success_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_effect_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplier {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after the remote capture succeeded.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider capture effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $result );
		$this->assertSame( 'pi_capture_effect_failure', $order->get_transaction_id() );
	}

	/**
	 * @testdox Should preserve a provider supplied empty checkout redirect.
	 */
	public function test_process_checkout_preserves_empty_checkout_redirect_override(): void {
		$order    = $this->create_woopayments_order( '15.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_PENDING_ASYNC,
				'',
				'',
				'',
				'',
				array( 'checkout_redirect' => '' )
			)
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID ), $provider );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '', $result['redirect'] );
	}

	/**
	 * @testdox Should not call the provider while an order operation is locked.
	 */
	public function test_process_checkout_returns_failure_when_order_operation_is_locked(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$key   = $this->idempotency->mint_attempt_key();
		$this->store->lock_order_payment( $order, $this->persistence_profile, $key );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( 'failure', $result['result'], 'WooCommerce recognizes failure, not fail; an unrecognized value costs the shopper the decline message.' );
		$this->assertSame( 0, $provider->charge_calls );
		$this->store->unlock_order_payment( $order, $this->persistence_profile );
	}

	/**
	 * @testdox Should not call the provider while any order operation is locked.
	 */
	public function test_process_checkout_returns_failure_when_any_order_operation_is_locked(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->store->lock_order_payment( $order, $this->persistence_profile, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( 'failure', $result['result'], 'WooCommerce recognizes failure, not fail; an unrecognized value costs the shopper the decline message.' );
		$this->assertSame( 0, $provider->charge_calls );
		$this->store->unlock_order_payment( $order, $this->persistence_profile );
	}

	/**
	 * @testdox Should complete zero-total checkout without calling the provider.
	 */
	public function test_process_checkout_completes_zero_total_without_provider_call(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_should_not_be_used' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 0, $provider->charge_calls );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox Should return true for zero-amount refunds without calling the provider.
	 */
	public function test_process_refund_zero_amount_returns_true_without_provider_call(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) );

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 0.00 ), $provider );

		$this->assertTrue( $result );
		$this->assertSame( 0, $provider->refund_calls );
	}

	/**
	 * @testdox Should return true when the provider refund succeeds.
	 */
	public function test_process_refund_returns_true_when_provider_succeeds(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) );

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result );
		$this->assertSame( 1, $provider->refund_calls );
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 2.50, 'USD', 'Adjustment' ),
			$provider->last_idempotency_key
		);
	}

	/**
	 * @testdox A successful refund should retain its provider identity when deferred local effects throw.
	 */
	public function test_process_refund_keeps_provider_identity_when_effect_application_throws(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_effect_failure' ) ) extends RecordingProvider implements ProviderOperationEffectApplier {
			/**
			 * Applied operation names.
			 *
			 * @var string[]
			 */
			public array $effect_operations = array();

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail local effect application after the provider refund succeeded.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome );
				$this->effect_operations[] = $operation;
				throw new RuntimeException( 'Local refund effect failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result, 'A local effect failure must not report the completed provider refund as failed.' );
		$this->assertSame( array( 'refund' ), $provider->effect_operations );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 're_effect_failure', $refund->get_meta( '_wcpay_refund_id', true ), 'The exact refund row must retain the provider refund identity.' );
		$this->assertSame( '', $order->get_transaction_id(), 'A refund ID must not replace the parent payment transaction ID.' );
	}

	/**
	 * @testdox Two equal-amount partial refunds must reach the provider with distinct idempotency keys.
	 */
	public function test_two_equal_amount_partial_refunds_use_distinct_idempotency_keys(): void {
		$order = $this->create_woopayments_order( '10.00' );

		$first_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $first_refund );

		// The provider links the processed refund via `_wcpay_refund_id`, mirroring the live
		// synchronous and webhook paths. Resolution must skip that linked refund so the second
		// refund instance resolves to its own row and not the first one.
		$provider     = new RecordingProvider( $this->successful_refund_outcome( 're_first' ) );
		$first_result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$first_key    = $provider->last_idempotency_key;

		$second_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $second_refund );

		$second_provider = new RecordingProvider( $this->successful_refund_outcome( 're_second' ) );
		$second_result   = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $second_provider );
		$second_key      = $second_provider->last_idempotency_key;

		$this->assertTrue( $first_result );
		$this->assertTrue( $second_result );
		$this->assertSame( 1, $provider->refund_calls, 'The first refund must reach the provider.' );
		$this->assertSame( 1, $second_provider->refund_calls, 'The second refund must reach the provider.' );
		$this->assertNotSame(
			$first_key,
			$second_key,
			'Two distinct refunds of the same amount and reason must use different idempotency keys so the provider does not replay the first refund.'
		);
	}

	/**
	 * @testdox Refund-instance resolution must run while the order payment lock is held.
	 */
	public function test_refund_instance_resolution_runs_under_the_order_lock(): void {
		$order = $this->create_woopayments_order( '10.00' );

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$sut = $this->build_lock_observing_sut();

		$provider = new RecordingProvider( $this->successful_refund_outcome( 're_locked' ) );
		$result   = $sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result );
		$this->assertTrue(
			$sut->lock_held_during_resolution,
			'Refund-instance resolution must happen under the order payment lock so concurrent equal refunds serialize and each resolves a distinct instance.'
		);
	}

	/**
	 * @testdox Refund resolution must skip an already-processed equal refund even when it sorts after the fresh one.
	 */
	public function test_refund_resolution_prefers_unprocessed_refund_over_linked_one(): void {
		$order = $this->create_woopayments_order( '10.00' );

		// The fresh, unprocessed refund this operation is meant to push to the provider.
		$fresh_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh_refund );

		// An equal-amount, equal-reason refund created later that the provider has ALREADY processed
		// (it carries `_wcpay_refund_id`). Because it sorts after the fresh refund, ordering-based
		// resolution would wrongly latch onto it and reuse its key. Meta-based exclusion must skip it.
		$already_processed = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $already_processed );
		$already_processed->update_meta_data( '_wcpay_refund_id', 're_already_done' );
		$already_processed->save_meta_data();

		$ref    = new \ReflectionClass( $this->sut );
		$method = $ref->getMethod( 'resolve_refund_instance_id' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $this->sut, wc_get_order( $order->get_id() ), 2.50, 'Adjustment' );

		$this->assertSame(
			(string) $fresh_refund->get_id(),
			$resolved,
			'Resolution must return the fresh unprocessed refund, not the already-processed one whose key would replay.'
		);
	}

	/**
	 * @testdox Reprocessing the same refund instance must collapse to one idempotency key.
	 */
	public function test_reprocessing_same_refund_instance_reuses_idempotency_key(): void {
		$order = $this->create_woopayments_order( '10.00' );

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		// A refund whose provider call failed leaves the row unlinked (no `_wcpay_refund_id`), so a
		// legitimate retry of that same instance must resolve to the same row and derive the same key.
		$failing_provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array(
					'error_code'    => 'temporary_error',
					'error_message' => 'Temporary provider error.',
				)
			)
		);

		$first_result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $failing_provider );
		$first_key    = $failing_provider->last_idempotency_key;

		$retry_provider = new RecordingProvider( $this->successful_refund_outcome( 're_retry' ) );
		$retry_result   = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $retry_provider );
		$retry_key      = $retry_provider->last_idempotency_key;

		$this->assertWPError( $first_result );
		$this->assertTrue( $retry_result );
		$this->assertSame( 1, $failing_provider->refund_calls );
		$this->assertSame( 1, $retry_provider->refund_calls );
		$this->assertSame(
			$first_key,
			$retry_key,
			'A retry of the same refund instance must reuse the idempotency key so the provider can recognize and dedupe the retry.'
		);
	}

	/**
	 * @testdox Should persist provider refund metadata on the matching WC refund.
	 */
	public function test_process_refund_persists_provider_refund_metadata(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);

		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_native',
				'',
				'',
				'',
				array(
					'order_meta'  => array(
						'_wcpay_refund_status' => 'successful',
					),
					'refund_meta' => array(
						'_wcpay_refund_id'             => 're_native',
						'_wcpay_refund_transaction_id' => 'txn_refund',
					),
					'refund_note' => 'A refund of $2.50 was successfully processed using WooPayments. Reason: Adjustment. (<code>re_native</code>)',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 're_native', $refund->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'txn_refund', $refund->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertOrderHasNoteContaining( $order, 'A refund of' );
		$this->assertOrderHasNoteContaining( $order, 'was successfully processed using WooPayments' );
		$this->assertOrderHasNoteContaining( $order, 'Adjustment' );
		$this->assertOrderHasNoteContaining( $order, 're_native' );
	}

	/**
	 * @testdox A refund outcome adopts an equivalent note and backfills its provider-neutral structural identity.
	 */
	public function test_process_refund_adopts_equivalent_note_and_backfills_structural_identity(): void {
		$identity    = 'refund:re_structural:created_successful';
		$native_note = 'A refund of $10.99 was successfully processed. (<code>re_structural</code>)';
		$locale_note = 'A refund of 10,99 $ was successfully processed. (<code>re_structural</code>)';
		$order       = $this->create_woopayments_order( '10.99' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 10.99,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$order->add_order_note( $locale_note );

		$effect_data = array(
			PaymentOutcome::DATA_REFUND_NOTE             => $native_note,
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY    => $identity,
			PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS => array( $native_note, $locale_note ),
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY => '_test_provider_note_identity',
		);
		$provider    = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_structural', '', '', '', $effect_data ) );

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 10.99 ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertTrue( $result );
		$this->assertSame( 1, $provider->refund_calls, 'The structural note reconciliation must not issue a second provider refund.' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 're_structural' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $locale_note, $refund_notes[0]->content );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $refund_notes[0]->id, '_test_provider_note_identity', true ) );
	}

	/**
	 * @testdox Mixed equivalent-note values are filtered while valid structural identity remains usable.
	 */
	public function test_process_refund_filters_mixed_equivalent_notes_before_structural_adoption(): void {
		$identity    = 'refund:re_mixed_equivalents:created_successful';
		$native_note = 'Native refund note. (<code>re_mixed_equivalents</code>)';
		$locale_note = 'Localized refund note. (<code>re_mixed_equivalents</code>)';
		$order       = $this->create_woopayments_order( '3.25' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 3.25,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$order->add_order_note( $locale_note );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_mixed_equivalents',
				'',
				'',
				'',
				array(
					'refund_note'                   => $native_note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( $native_note, 42, array( '_arbitrary_comment_meta' => 'injected' ), $locale_note ),
					'refund_note_identity_meta_key' => '_test_provider_note_identity',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.25 ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 're_mixed_equivalents' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $locale_note, $refund_notes[0]->content );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $refund_notes[0]->id, '_test_provider_note_identity', true ) );
		$this->assertSame( '', get_comment_meta( $refund_notes[0]->id, '_arbitrary_comment_meta', true ) );
	}

	/**
	 * @testdox Numeric equivalent-note values are not coerced into persisted-note content matches.
	 */
	public function test_process_refund_does_not_coerce_numeric_equivalent_note_candidates(): void {
		$identity    = 'refund:re_numeric_equivalent:created_successful';
		$native_note = 'Native refund note. (<code>re_numeric_equivalent</code>)';
		$order       = $this->create_woopayments_order( '3.50' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 3.50,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$coercion_trap_note_id = $order->add_order_note( '42' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_numeric_equivalent',
				'',
				'',
				'',
				array(
					'refund_note'                   => $native_note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( 42 ),
					'refund_note_identity_meta_key' => '_test_provider_note_identity',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 3.50 ), $provider );

		$this->assertTrue( $result );
		$native_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => $native_note === $note->content
			)
		);
		$this->assertCount( 1, $native_notes );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $native_notes[0]->id, '_test_provider_note_identity', true ) );
		$this->assertSame( array(), get_comment_meta( $coercion_trap_note_id ) );
	}

	/**
	 * @testdox Malformed structural refund-note values fall back without creating comment metadata.
	 * @dataProvider malformed_refund_note_identity_data
	 *
	 * @param mixed $identity          Structural note identity.
	 * @param mixed $identity_meta_key Structural note identity meta key.
	 */
	public function test_process_refund_does_not_coerce_malformed_structural_note_values( $identity, $identity_meta_key ): void {
		$note   = 'Existing exact refund note. (<code>re_malformed_structure</code>)';
		$order  = $this->create_woopayments_order( '2.75' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.75,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id = $order->add_order_note( $note );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_malformed_structure',
				'',
				'',
				'',
				array(
					'refund_note'                   => $note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( $note ),
					'refund_note_identity_meta_key' => $identity_meta_key,
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.75 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * Provide malformed structural refund-note values.
	 *
	 * @return array<string,array{mixed,mixed}>
	 */
	public function malformed_refund_note_identity_data(): array {
		return array(
			'empty identity'             => array( '', '_test_provider_note_identity' ),
			'array identity'             => array( array( 'refund:re_malformed_structure:created_successful' ), '_test_provider_note_identity' ),
			'scalar non-string identity' => array( 42, '_test_provider_note_identity' ),
			'empty meta key'             => array( 'refund:re_malformed_structure:created_successful', '' ),
			'array meta key'             => array( 'refund:re_malformed_structure:created_successful', array( '_arbitrary_comment_meta' ) ),
			'scalar non-string meta key' => array( 'refund:re_malformed_structure:created_successful', true ),
		);
	}

	/**
	 * @testdox Missing or malformed refund-note equivalents retain exact-content fallback without adding identity metadata.
	 * @dataProvider malformed_refund_note_equivalents_data
	 *
	 * @param bool  $include_equivalents Whether to include the equivalents field.
	 * @param mixed $equivalent_notes    Equivalent-note field value.
	 */
	public function test_process_refund_falls_back_when_equivalent_notes_are_absent_or_malformed( bool $include_equivalents, $equivalent_notes ): void {
		$note        = 'Existing exact partial-structure refund note. (<code>re_partial_structure</code>)';
		$order       = $this->create_woopayments_order( '2.25' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.25,
				'refund_payment' => false,
			)
		);
		$effect_data = array(
			PaymentOutcome::DATA_REFUND_NOTE          => $note,
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY => 'refund:re_partial_structure:created_successful',
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY => '_test_provider_note_identity',
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id = $order->add_order_note( $note );

		if ( $include_equivalents ) {
			$effect_data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ] = $equivalent_notes;
		}
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_partial_structure', '', '', '', $effect_data ) );

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.25 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * Provide absent and malformed refund-note equivalents.
	 *
	 * @return array<string,array{bool,mixed}>
	 */
	public function malformed_refund_note_equivalents_data(): array {
		return array(
			'absent equivalents'    => array( false, null ),
			'non-array equivalents' => array( true, 'not-an-array' ),
		);
	}

	/**
	 * @testdox A legacy refund-note-only outcome retains exact-content deduplication without adding identity metadata.
	 */
	public function test_process_refund_legacy_note_only_outcome_retains_exact_content_deduplication(): void {
		$note   = 'Legacy exact refund note. (<code>re_legacy_note</code>)';
		$order  = $this->create_woopayments_order( '1.50' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 1.50,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id  = $order->add_order_note( $note );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_legacy_note',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_REFUND_NOTE => $note )
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 1.50 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * @testdox An AJAX-formatted refund amount must resolve to the exact local refund despite decimal scale differences.
	 */
	public function test_process_refund_matches_ajax_formatted_refund_amount(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '2.50',
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_ajax_amount',
				'',
				'',
				'',
				array(
					'order_meta'  => array( '_wcpay_refund_status' => 'successful' ),
					'refund_meta' => array(
						'_wcpay_refund_id'             => 're_ajax_amount',
						'_wcpay_refund_transaction_id' => 'txn_ajax_amount',
					),
					'refund_note' => 'A refund of $2.50 was successfully processed using WooPayments. Reason: Adjustment. (<code>re_ajax_amount</code>)',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 2.50, 'USD', 'Adjustment', (string) $refund->get_id() ),
			$provider->last_idempotency_key,
			'The provider key must bind to the exact AJAX-created local refund regardless of decimal string scale.'
		);
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 're_ajax_amount', $refund->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'txn_ajax_amount', $refund->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertOrderHasNoteContaining( $order, 're_ajax_amount' );
	}

	/**
	 * @testdox Refund matching must not round distinct extra-precision amounts into the same candidate.
	 */
	public function test_process_refund_does_not_collapse_distinct_extra_precision_amounts(): void {
		$order        = $this->create_woopayments_order( '10.00' );
		$close_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '2.501',
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$exact_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '2.504',
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);

		$this->assertInstanceOf( WC_Order_Refund::class, $close_refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $exact_refund );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_extra_precision',
				'',
				'',
				'',
				array(
					'refund_meta' => array(
						'_wcpay_refund_id' => 're_extra_precision',
					),
				)
			)
		);

		$result       = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.504, 'Adjustment' ), $provider );
		$close_refund = wc_get_order( $close_refund->get_id() );
		$exact_refund = wc_get_order( $exact_refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $close_refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $exact_refund );
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 2.504, 'USD', 'Adjustment', (string) $exact_refund->get_id() ),
			$provider->last_idempotency_key,
			'The provider key must bind to the exact extra-precision refund rather than a rounded neighbor.'
		);
		$this->assertSame( '', $close_refund->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 're_extra_precision', $exact_refund->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox An invalid exact refund target after provider success must enter reconciliation instead of failing silently.
	 */
	public function test_process_refund_logs_reconciliation_when_resolved_refund_disappears_after_provider_success(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new class( $this->successful_refund_outcome( 're_missing_target' ), $refund->get_id() ) extends RecordingProvider implements ProviderOperationEffectApplier {
			/**
			 * Exact local refund ID to remove after provider transport.
			 *
			 * @var int
			 */
			private int $refund_id;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome   Successful provider outcome.
			 * @param int            $refund_id Exact local refund ID.
			 */
			public function __construct( PaymentOutcome $outcome, int $refund_id ) {
				parent::__construct( $outcome );
				$this->refund_id = $refund_id;
			}

			/**
			 * Remove the exact local target after provider transport, before generic effects are applied.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				$refund = wc_get_order( $this->refund_id );
				if ( $refund instanceof WC_Order_Refund ) {
					$refund->delete( true );
				}

				return $outcome;
			}
		};

		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		remove_all_filters( 'woocommerce_logging_class' );

		$this->assertTrue( $result, 'Provider success must remain successful while the local failure is made reconcilable.' );
		$this->assertCount( 1, $fake_logger->error_calls, 'The invalid post-provider target must emit one reconciliation error log.' );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'refund', $context['operation'] );
		$this->assertSame( 're_missing_target', $context['payment_reference'] );
		$this->assertFalse( $context['reconciliation_persisted'] );
	}

	/**
	 * @testdox Should match the first unlinked refund using provider supplied meta keys.
	 */
	public function test_process_refund_skips_refunds_linked_by_provider_meta_keys(): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$unlinked_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$linked_refund   = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);

		$this->assertInstanceOf( WC_Order_Refund::class, $unlinked_refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $linked_refund );

		$linked_refund->update_meta_data( '_provider_refund_id', 're_existing' );
		$linked_refund->save_meta_data();

		$provider = new class(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_native',
				'',
				'',
				'',
				array(
					'refund_meta' => array(
						'_provider_refund_id' => 're_native',
					),
				)
			)
		) extends RecordingProvider {
			/**
			 * Get the provider persistence profile.
			 *
			 * @return ProviderPersistenceProfile
			 */
			public function get_persistence_profile(): ProviderPersistenceProfile {
				return new class( $this->get_id() ) extends RecordingProviderPersistenceProfile {
					/**
					 * Get the processed refund link meta key.
					 *
					 * @return string
					 */
					public function get_processed_refund_link_meta_key(): string {
						return '_provider_refund_id';
					}
				};
			}
		};

		$result          = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$unlinked_refund = wc_get_order( $unlinked_refund->get_id() );
		$linked_refund   = wc_get_order( $linked_refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $unlinked_refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $linked_refund );
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 2.50, 'USD', 'Adjustment', (string) $unlinked_refund->get_id() ),
			$provider->last_idempotency_key
		);
		$this->assertSame( 're_native', $unlinked_refund->get_meta( '_provider_refund_id', true ) );
		$this->assertSame( 're_existing', $linked_refund->get_meta( '_provider_refund_id', true ) );
	}

	/**
	 * @testdox Should preserve provider refund error codes.
	 */
	public function test_process_refund_preserves_provider_error_code(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array(
					'error_code'    => 'uncaptured-payment',
					'error_message' => 'This payment is not captured yet.',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertWPError( $result );
		$this->assertSame( 'uncaptured-payment', $result->get_error_code() );
		$this->assertSame( 'This payment is not captured yet.', $result->get_error_message() );
	}

	/**
	 * @testdox Capture and cancel should use the shared order claim.
	 */
	public function test_capture_and_cancel_use_shared_order_claim(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->store->lock_order_payment( $order, $this->persistence_profile, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$capture_outcome = $this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$cancel_outcome  = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $capture_outcome->get_status() );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $cancel_outcome->get_status() );
		$this->assertSame( 0, $provider->capture_calls );
		$this->assertSame( 0, $provider->cancel_calls );

		$this->store->unlock_order_payment( $order, $this->persistence_profile );
	}

	/**
	 * @testdox Capture idempotency should include the context amount when present.
	 */
	public function test_capture_idempotency_uses_context_amount(): void {
		$order = $this->create_woopayments_order( '10.00' );

		$first_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_first' ) );
		$this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, 4.00 ), $first_provider );
		$first_key = $first_provider->last_idempotency_key;

		$second_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_second' ) );
		$this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, 5.00 ), $second_provider );
		$second_key = $second_provider->last_idempotency_key;

		$retry_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_retry' ) );
		$this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, 4.00 ), $retry_provider );
		$retry_key = $retry_provider->last_idempotency_key;

		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 4.00, 'USD' ),
			$first_key
		);
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 5.00, 'USD' ),
			$second_key
		);
		$this->assertNotSame( $first_key, $second_key );
		$this->assertSame( $first_key, $retry_key );
	}

	/**
	 * @testdox Failed captures prefer provider outcome mapping over the legacy profile fallback.
	 */
	public function test_capture_failure_uses_provider_outcome_metadata_mapper(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_mapper_capture_failure' );
		$order->save();

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_mapper_capture_failure' ) ) extends RecordingProvider implements ProviderOutcomeMetadataMapper {
			/**
			 * Map a neutral outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );

				return array( '_mapper_capture_state' => 'mapped' );
			}

			/**
			 * Map a failed capture outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );

				return array( '_mapper_capture_state' => 'authorization-active' );
			}
		};

		$this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'authorization-active', $order->get_meta( '_mapper_capture_state', true ) );
	}

	/**
	 * @testdox Failed captures should leave authorized orders on hold.
	 */
	public function test_capture_failure_preserves_authorized_order_status(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_capture' );
		$order->update_meta_data( '_intent_id', 'pi_capture' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_capture',
				'',
				'',
				'',
				array( 'note' => 'Capture failed note.' )
			)
		);

		$outcome = $this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 1, $provider->capture_calls );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNoteContaining( $order, 'Capture failed note.' );
	}

	/**
	 * @testdox An expired-authorization capture failure moves the order to failed with the expired note.
	 */
	public function test_capture_expired_authorization_fails_the_order(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_capture_expired' );
		$order->update_meta_data( '_intent_id', 'pi_capture_expired' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_capture_expired',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_NOTE      => 'Payment authorization has <strong>expired</strong>.',
					PaymentOutcome::DATA_NOTE_TYPE => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED,
					PaymentOutcome::DATA_META      => array( '_intention_status' => 'canceled' ),
				)
			)
		);

		$outcome = $this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'failed', $order->get_status(), 'An expired authorization must fail the order like the charge.expired webhook.' );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNoteContaining( $order, 'expired' );
	}

	/**
	 * @testdox Failed authorization cancellations should preserve the order status and authorization state.
	 */
	public function test_cancel_failure_preserves_authorized_order_status(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_cancel' );
		$order->update_meta_data( '_intent_id', 'pi_cancel' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
		$order->update_meta_data( '_wcpay_net', '9.35' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_cancel',
				'',
				'',
				'',
				array( 'note' => 'Cancellation failed note.' )
			)
		);

		$outcome = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 1, $provider->cancel_calls );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '0.65', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '9.35', $order->get_meta( '_wcpay_net', true ) );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META_TO_DELETE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertOrderHasNoteContaining( $order, 'Cancellation failed note.' );
	}

	/**
	 * @testdox Should support non-Stripe redirect providers through neutral outcomes.
	 */
	public function test_process_checkout_supports_non_stripe_redirect_provider(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$provider = new NonStripeProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_REQUIRES_REDIRECT,
				'remote_payment_123',
				'https://offline-provider.example/pay/123'
			)
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://offline-provider.example/pay/123', $result['redirect'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'remote_payment_123', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox Should support non-Stripe asynchronous providers without card data.
	 */
	public function test_process_checkout_supports_non_stripe_pending_async_provider(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$provider = new NonStripeProvider( new PaymentOutcome( PaymentOutcome::STATUS_PENDING_ASYNC, 'remote_pending_123' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'processing', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Should apply canceled provider outcomes to the canceled order lifecycle.
	 */
	public function test_cancel_applies_canceled_lifecycle_state(): void {
		$order = $this->create_woopayments_order( '12.00' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
		$order->update_meta_data( '_wcpay_net', '11.35' );
		$order->save();
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_CANCELED,
				'pi_canceled',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_META_TO_DELETE => array( '_wcpay_transaction_fee', '_wcpay_net' ),
					PaymentOutcome::DATA_NOTE           => 'Authorization cancellation success note.',
					PaymentOutcome::DATA_NOTE_TYPE      => 'capture_canceled',
				)
			)
		);

		$outcome = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_canceled', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertOrderHasNoteContaining( $order, 'Authorization cancellation success note.' );
	}

	/**
	 * @testdox A German plugin-era cancellation note is not duplicated by the native cancellation lifecycle.
	 */
	public function test_cancel_deduplicates_german_plugin_note_through_native_effects(): void {
		$translation_filter = static function ( string $translation, string $text, string $domain ): string {
			if ( 'woocommerce-payments' !== $domain ) {
				return $translation;
			}

			return 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).' === $text
				? 'Die Zahlungsautorisierung wurde erfolgreich <strong>storniert</strong> (<a>%1$s</a>).'
				: $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );
		switch_to_locale( 'de_DE' );

		try {
			$order = $this->create_woopayments_order( '12.00' );
			$order->set_status( 'on-hold' );
			$order->update_meta_data( '_charge_id', 'ch_canceled_de' );
			$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
			$order->update_meta_data( '_wcpay_net', '11.35' );
			$order->save();
			$note_service    = wc_get_container()->get( WooPaymentsOrderNoteService::class );
			$transaction_url = $note_service->transaction_url( 'pi_canceled_de', 'ch_canceled_de' );
			$plugin_note     = sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1$s: transaction ID, %2$s: transaction URL. */
					__( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce-payments' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The fixture emulates the legacy plugin catalog.
					array(
						'strong' => '<strong>',
						'a'      => '<a href="%2$s" target="_blank" rel="noopener noreferrer">',
					)
				),
				'pi_canceled_de',
				$transaction_url
			);
			$order->add_order_note( $plugin_note );

			$provider_outcome = new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_canceled_de' );
			$effect_applier   = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
			$provider         = new class( $provider_outcome, $effect_applier ) extends RecordingProvider implements ProviderOperationEffectApplier {
				/**
				 * WooPayments effect applier.
				 *
				 * @var WooPaymentsOrderEffectApplier
				 */
				private WooPaymentsOrderEffectApplier $effect_applier;

				/**
				 * Constructor.
				 *
				 * @param PaymentOutcome                $outcome        Provider outcome.
				 * @param WooPaymentsOrderEffectApplier $effect_applier WooPayments effect applier.
				 */
				public function __construct( PaymentOutcome $outcome, WooPaymentsOrderEffectApplier $effect_applier ) {
					parent::__construct( $outcome );
					$this->effect_applier = $effect_applier;
				}

				/**
				 * Apply the real WooPayments cancellation effect plan.
				 *
				 * @param PaymentContext $context   Payment context.
				 * @param PaymentOutcome $outcome   Provider outcome.
				 * @param string         $operation Operation name.
				 * @return PaymentOutcome
				 */
				public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
					$this->assert_cancel_operation( $operation );

					return $this->effect_applier->apply(
						$context,
						$outcome,
						WooPaymentsOrderEffectPlan::for_cancel(
							array(
								'id'     => 'pi_canceled_de',
								'status' => 'canceled',
							)
						)
					);
				}

				/**
				 * Guard the fixture against use outside cancellation.
				 *
				 * @param string $operation Operation name.
				 */
				private function assert_cancel_operation( string $operation ): void {
					if ( 'cancel' !== $operation ) {
						throw new RuntimeException( 'Unexpected provider operation in cancellation fixture.' );
					}
				}
			};

			$outcome = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
			$order   = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$matching_notes = array_values(
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_canceled_de' )
				)
			);
			$marker_key     = '_wc_native_payments_note_' . md5( 'pi_canceled_de|canceled|capture_canceled' );

			$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
			$this->assertSame( 'cancelled', $order->get_status() );
			$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
			$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
			$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
			$this->assertCount( 1, $matching_notes );
			$this->assertSame( $plugin_note, $matching_notes[0]->content );
			$this->assertSame( '', $order->get_meta( $marker_key, true ), 'A plugin-owned note must not acquire a native lifecycle marker.' );
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
			restore_current_locale();
		}
	}

	/**
	 * @testdox Replayed held-for-review intents repair rule evidence without duplicating equivalent notes.
	 */
	public function test_review_intent_replay_repairs_rule_evidence_and_deduplicates_content_sensitive_notes(): void {
		$translation_filter = static function ( string $translation, string $text, string $domain ): string {
			if ( 'woocommerce-payments' !== $domain ) {
				return $translation;
			}

			return '&#x26D4; A payment of %1$s was <strong>held for review</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.' === $text
				? '&#x26D4; Eine Zahlung von %1$s wurde von den folgenden Risikofiltern <strong>zur Überprüfung zurückgehalten</strong>:<br>%2$s<br><br><a>Weitere Details anzeigen</a>.'
				: $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );
		switch_to_locale( 'de_DE' );

		try {
			$order          = $this->create_woopayments_order( '12.00' );
			$note_service   = wc_get_container()->get( WooPaymentsOrderNoteService::class );
			$initial_rules  = array( 'avs_verification' => 'review' );
			$plugin_note    = $note_service->format_fraud_held_for_review_note_candidates( $order, 'pi_review_replay', 'ch_review_replay', $initial_rules )[1];
			$initial_result = '{"avs_verification":"review"}';
			$order->add_order_note( $plugin_note );

			$this->sut->process_checkout(
				PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $initial_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'on-hold', $order->get_status() );
			$this->assertSame( $initial_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				1,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'The seeded WooPayments-catalog rendering must satisfy the equivalent note identity.'
			);

			$this->sut->process_checkout(
				PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $initial_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( $initial_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				1,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'An identical replay must not add another held-for-review note.'
			);

			$order->update_meta_data( '_wcpay_fraud_ruleset_results', 'stale evidence' );
			$order->save();
			$changed_rules  = array(
				'avs_verification' => 'review',
				'address_mismatch' => 'block',
			);
			$changed_result = '{"avs_verification":"review","address_mismatch":"block"}';
			$this->sut->process_checkout(
				PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $changed_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( $changed_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				2,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'Changed filter evidence must produce a distinct content-sensitive held-for-review note.'
			);
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
			restore_current_locale();
		}
	}

	/**
	 * @testdox Should support zero-total checkout without a provider-specific payment operation.
	 */
	public function test_process_checkout_supports_non_stripe_zero_total_provider_without_charge(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new NonStripeProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'remote_should_not_be_used' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 0, $provider->charge_calls );
	}

	/**
	 * @testdox Should call setup-capable providers for zero-total checkout when a payment credential is present.
	 */
	public function test_process_checkout_calls_setup_capable_provider_for_zero_total_checkout_with_credential(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'seti_zero',
				'',
				'pm_zero',
				'cus_zero',
				array(
					'meta' => array(
						'_intention_status' => 'succeeded',
					),
				)
			),
			array( CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP )
		);

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_zero' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'seti_zero', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_zero', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Should call setup-capable providers for zero-total checkout when a saved payment token is present.
	 */
	public function test_process_checkout_calls_setup_capable_provider_for_zero_total_checkout_with_saved_token(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'seti_saved',
				'',
				'pm_saved',
				'cus_saved',
				array(
					'meta' => array(
						'_intention_status' => 'succeeded',
					),
				)
			),
			array( CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP )
		);

		$result = $this->sut->process_checkout(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'',
				array( 'payment_token' => '123' )
			),
			$provider
		);
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'seti_saved', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_saved', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox A zero-total recurring SetupIntent exposes actual card identity before lifecycle for new and saved cards.
	 * @dataProvider zero_total_recurring_card_context_data
	 *
	 * @param bool $use_saved_token Whether the checkout uses an existing saved token.
	 */
	public function test_zero_total_recurring_setup_intent_exposes_card_identity_to_synchronous_lifecycle_observers( bool $use_saved_token ): void {
		$user_id      = self::factory()->user->create();
		$order        = $this->create_woopayments_order( '0.00' );
		$subscription = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();
		$details       = array(
			'id'   => 'pm_free_trial',
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
		$details_reads = new \ArrayObject( array( 0 ) );
		$token         = null;
		if ( $use_saved_token ) {
			$token = new WC_Payment_Token_CC();
			$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
			$token->set_user_id( $user_id );
			$token->set_token( 'pm_free_trial' );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( '2030' );
			$token->save();
			$order->add_payment_token( $token );
			$order->save();
		}
		$provider = $this->completed_setup_intent_provider( $details, $details_reads );
		$context  = PaymentContext::for_checkout(
			$order,
			OrderPaymentStore::GATEWAY_ID,
			$use_saved_token ? '' : 'pm_free_trial',
			$use_saved_token ? array( 'payment_token' => (string) $token->get_id() ) : array(),
			array( 'recurring_payment' => true )
		);
		$observed = array();
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);
		$capture_observer_state    = static function ( int $order_id, string $hook ) use ( $order, $subscription, &$observed ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded_order        = wc_get_order( $order_id );
			$reloaded_subscription = wc_get_order( $subscription->get_id() );
			if ( ! $reloaded_order instanceof WC_Order || ! $reloaded_subscription instanceof WC_Order ) {
				return;
			}

			$order_token_ids        = $reloaded_order->get_payment_tokens();
			$subscription_token_ids = $reloaded_subscription->get_payment_tokens();
			$order_active_token_id  = end( $order_token_ids );
			$order_active_token     = false === $order_active_token_id ? null : \WC_Payment_Tokens::get( (int) $order_active_token_id );
			$observed[]             = array(
				'hook'                        => $hook,
				'order_gateway'               => $reloaded_order->get_payment_method(),
				'order_title'                 => $reloaded_order->get_payment_method_title(),
				'order_last4'                 => $reloaded_order->get_meta( 'last4', true ),
				'order_card_brand'            => $reloaded_order->get_meta( '_card_brand', true ),
				'order_details'               => json_decode( (string) $reloaded_order->get_meta( '_wcpay_payment_method_details', true ), true ),
				'order_raw_details'           => $reloaded_order->get_meta( '_wcpay_raw_payment_method_details', true ),
				'order_payment_method'        => $reloaded_order->get_meta( '_payment_method_id', true ),
				'order_customer'              => $reloaded_order->get_meta( '_stripe_customer_id', true ),
				'order_token_ids'             => $order_token_ids,
				'active_token_id'             => $order_active_token instanceof \WC_Payment_Token ? $order_active_token->get_id() : 0,
				'active_token_provider'       => $order_active_token instanceof \WC_Payment_Token ? $order_active_token->get_token() : '',
				'subscription_gateway'        => $reloaded_subscription->get_payment_method(),
				'subscription_title'          => $reloaded_subscription->get_payment_method_title(),
				'subscription_tokens'         => $subscription_token_ids,
				'subscription_payment_method' => $reloaded_subscription->get_meta( '_payment_method_id', true ),
				'subscription_customer'       => $reloaded_subscription->get_meta( '_stripe_customer_id', true ),
				'subscription_last4'          => $reloaded_subscription->get_meta( 'last4', true ),
				'subscription_card_brand'     => $reloaded_subscription->get_meta( '_card_brand', true ),
				'subscription_details'        => $reloaded_subscription->get_meta( '_wcpay_payment_method_details', true ),
				'subscription_raw_details'    => $reloaded_subscription->get_meta( '_wcpay_raw_payment_method_details', true ),
			);
		};
		$status_observer           = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'status' );
		};
		$payment_complete_observer = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'payment_complete' );
		};
		add_action( 'woocommerce_order_status_completed', $status_observer, 1 );
		add_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_order_status_completed', $status_observer, 1 );
			remove_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
			remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$expected_token_ids = $order->get_payment_tokens();
		$expected_token_id  = end( $expected_token_ids );
		$this->assertSame(
			array(
				array(
					'hook'                        => 'status',
					'order_gateway'               => OrderPaymentStore::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_raw_details'           => '',
					'order_payment_method'        => 'pm_free_trial',
					'order_customer'              => 'cus_free_trial',
					'order_token_ids'             => $expected_token_ids,
					'active_token_id'             => $expected_token_id,
					'active_token_provider'       => 'pm_free_trial',
					'subscription_gateway'        => OrderPaymentStore::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_tokens'         => $expected_token_ids,
					'subscription_payment_method' => 'pm_free_trial',
					'subscription_customer'       => 'cus_free_trial',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
					'subscription_raw_details'    => '',
				),
				array(
					'hook'                        => 'payment_complete',
					'order_gateway'               => OrderPaymentStore::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_raw_details'           => '',
					'order_payment_method'        => 'pm_free_trial',
					'order_customer'              => 'cus_free_trial',
					'order_token_ids'             => $expected_token_ids,
					'active_token_id'             => $expected_token_id,
					'active_token_provider'       => 'pm_free_trial',
					'subscription_gateway'        => OrderPaymentStore::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_tokens'         => $expected_token_ids,
					'subscription_payment_method' => 'pm_free_trial',
					'subscription_customer'       => 'cus_free_trial',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
					'subscription_raw_details'    => '',
				),
			),
			$observed
		);
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'pm_free_trial', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_free_trial', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'seti_free_trial', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'Visa credit card', $subscription->get_payment_method_title() );
		$this->assertSame( 'pm_free_trial', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_free_trial', $subscription->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( '', $subscription->get_meta( 'last4', true ) );
		$this->assertSame( '', $subscription->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( 1, $details_reads[0] );
		$this->assertCount( 1, $order->get_payment_tokens() );
		$this->assertCount( 1, $subscription->get_payment_tokens() );
		$this->assertStringContainsString( '"type":"card"', (string) $order->get_meta( '_wcpay_payment_method_details', true ) );
	}

	/**
	 * Provide new-card and existing-saved-card zero-total SetupIntent contexts.
	 *
	 * @return array<string,array{bool}>
	 */
	public function zero_total_recurring_card_context_data(): array {
		return array(
			'new card'            => array( false ),
			'existing saved card' => array( true ),
		);
	}

	/**
	 * Build a successful provider refund outcome that links the WC refund via `_wcpay_refund_id`.
	 *
	 * This mirrors the live WooPayments synchronous and webhook paths, both of which stamp the
	 * processed `WC_Order_Refund` with `_wcpay_refund_id`. Tests rely on that link so refund
	 * instance resolution can tell a processed refund apart from a fresh, unprocessed one.
	 *
	 * @param string $provider_refund_id Provider refund ID to link.
	 * @return PaymentOutcome
	 */
	private function successful_refund_outcome( string $provider_refund_id ): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			$provider_refund_id,
			'',
			'',
			'',
			array(
				'order_meta'  => array( '_wcpay_refund_status' => 'successful' ),
				'refund_meta' => array( '_wcpay_refund_id' => $provider_refund_id ),
			)
		);
	}

	/**
	 * Build a checkout context for a completed PaymentIntent lifecycle test.
	 *
	 * Scheduled contexts use the same saved-token marker and order-attached token routing as native renewals.
	 *
	 * @param WC_Order $order                        Payment order.
	 * @param bool     $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 * @return PaymentContext
	 */
	private function completed_payment_intent_context( WC_Order $order, bool $scheduled_subscription_payment ): PaymentContext {
		if ( ! $scheduled_subscription_payment ) {
			return PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_lifecycle_title' );
		}

		$user_id = self::factory()->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_scheduled_renewal' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $token );
		$order->save();

		return PaymentContext::for_checkout(
			$order,
			OrderPaymentStore::GATEWAY_ID,
			'pm_lifecycle_title',
			array(
				'payment_token'       => (string) $token->get_id(),
				'save_payment_method' => false,
			),
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $token->get_display_name(),
			)
		);
	}

	/**
	 * Build a real WooPayments SetupIntent provider with a counted payment-method detail seam.
	 *
	 * @param array<string,mixed>   $payment_method_details Canonical provider payment-method details.
	 * @param \ArrayObject<int,int> $details_reads Payment-method detail read counter.
	 * @return WooPaymentsProvider
	 */
	private function completed_setup_intent_provider( array $payment_method_details, $details_reads ): WooPaymentsProvider {
		$details_service = new class( $payment_method_details, $details_reads ) extends WooPaymentsPaymentMethodDetailsService {
			/** @var array<string,mixed> */
			private array $payment_method_details;

			/** @var \ArrayObject<int,int> */
			private \ArrayObject $details_reads;

			/**
			 * @param array<string,mixed>   $payment_method_details Canonical provider payment-method details.
			 * @param \ArrayObject<int,int> $details_reads Payment-method detail read counter.
			 */
			public function __construct( array $payment_method_details, $details_reads ) {
				$this->payment_method_details = $payment_method_details;
				$this->details_reads          = $details_reads;
			}

			/**
			 * @param string $payment_method_id Provider payment-method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				++$this->details_reads[0];

				return 'pm_free_trial' === $payment_method_id ? $this->payment_method_details : array();
			}
		};
		$token_service   = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticNativeRuntimeArbiter( true ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_logger' ) )
			->getMock();
		$legacy_runtime->method( 'get_logger' )->willReturn( null );
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			$legacy_runtime,
			new WooPaymentsOrderNoteService(),
			new WooPaymentsPaymentMethodRegistry()
		);
		$api_client       = new class() extends \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient {
			/**
			 * Tell the adapter to use its native SetupIntent transport.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the isolated successful SetupIntent transport response.
			 *
			 * @param array<string,mixed> $request_data SetupIntent request data.
			 * @param string              $idempotency_key Request idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'seti_free_trial',
					'status'         => 'succeeded',
					'customer'       => 'cus_free_trial',
					'payment_method' => 'pm_free_trial',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_free_trial' );
		$adapter = new WooPaymentsProviderGatewayAdapter();
		$adapter->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			new WooPaymentsOrderDataService(),
			new WooPaymentsOrderNoteService(),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService::class )
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
			$effect_applier
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider that returns a held-for-review PaymentIntent response.
	 *
	 * The adapter isolates only its remote transport; the production provider, effect applier, and payment lifecycle remain in use.
	 *
	 * @param array<string,string> $ruleset_results Fired fraud-rule results.
	 * @return WooPaymentsProvider
	 */
	private function review_payment_intent_provider( array $ruleset_results ): WooPaymentsProvider {
		$adapter  = new class( $ruleset_results ) extends WooPaymentsProviderGatewayAdapter {
			/**
			 * Fired fraud-rule results returned by the isolated transport.
			 *
			 * @var array<string,string>
			 */
			private array $ruleset_results;

			/**
			 * Constructor.
			 *
			 * @param array<string,string> $ruleset_results Fired fraud-rule results.
			 */
			public function __construct( array $ruleset_results ) {
				$this->ruleset_results = $ruleset_results;
			}

			/**
			 * Return a review PaymentIntent without making a remote transport request.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Charge idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				$result = array(
					'id'       => 'pi_review_replay',
					'status'   => 'requires_capture',
					'currency' => 'usd',
					'metadata' => array(
						'fraud_outcome'         => 'review',
						'fraud_ruleset_results' => wp_json_encode( $this->ruleset_results ),
					),
					'charges'  => array(
						'data' => array(
							array(
								'id'                     => 'ch_review_replay',
								'currency'               => 'usd',
								'payment_method_details' => array( 'type' => 'card' ),
							),
						),
					),
				);

				return ( new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_review_replay', '', 'pm_review_replay' ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $result, false ) );
			}
		};
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient::class ),
			wc_get_container()->get( WooPaymentsAccountService::class ),
			null,
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider with only the remote charge transport isolated.
	 *
	 * @param array<string,mixed> $result   Expanded completed PaymentIntent response.
	 * @param \ArrayObject        $sequence Lifecycle sequence recorder.
	 * @return WooPaymentsProvider
	 */
	private function completed_payment_intent_provider( array $result, \ArrayObject $sequence ): WooPaymentsProvider {
		$adapter  = new class( $result, $sequence ) extends WooPaymentsProviderGatewayAdapter {
			/**
			 * Expanded PaymentIntent transport result.
			 *
			 * @var array<string,mixed>
			 */
			private array $result;

			/**
			 * Lifecycle sequence recorder.
			 *
			 * @var \ArrayObject<int,string>
			 */
			private \ArrayObject $sequence;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $result   Expanded completed PaymentIntent response.
			 * @param \ArrayObject        $sequence Lifecycle sequence recorder.
			 */
			public function __construct( array $result, \ArrayObject $sequence ) {
				$this->result   = $result;
				$this->sequence = $sequence;
			}

			/**
			 * Return a completed PaymentIntent without making a remote transport request.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Charge idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				$is_recurring = true === ( $context->get_provider_data()['scheduled_subscription_payment'] ?? false );
				if ( $is_recurring && ! \WC_Payment_Tokens::get( (int) ( $context->get_payment_data()['payment_token'] ?? 0 ) ) instanceof WC_Payment_Token_CC ) {
					throw new RuntimeException( 'Scheduled renewal fixture requires its persisted saved card.' );
				}

				$context->get_order()->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $idempotency_key );
				$context->get_order()->save_meta_data();
				$this->sequence[] = $is_recurring ? 'transport:scheduled' : 'transport:direct';

				return ( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_lifecycle_title', '', 'pm_lifecycle_title' ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $this->result, $is_recurring ) );
			}

			/**
			 * Finalize the stored charge idempotency key after the payment lifecycle.
			 *
			 * @param WC_Order       $order   Payment order.
			 * @param PaymentOutcome $outcome Completed charge outcome.
			 */
			public function finalize_charge_idempotency_key( WC_Order $order, PaymentOutcome $outcome ): void {
				parent::finalize_charge_idempotency_key( $order, $outcome );
				$this->sequence[] = 'finalization';
			}
		};
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class ),
			null,
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build the expanded completed card PaymentIntent returned by the provider for card-title lifecycle tests.
	 *
	 * @return array<string,mixed>
	 */
	private function completed_card_payment_intent_result(): array {
		return array(
			'id'       => 'pi_lifecycle_title',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_lifecycle_title',
						'currency'               => 'usd',
						'amount'                 => 1000,
						'application_fee_amount' => 35,
						'balance_transaction'    => array( 'id' => 'txn_lifecycle_title' ),
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand'         => 'visa',
								'display_brand' => 'visa',
								'last4'         => '4242',
								'funding'       => 'credit',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Build the expanded completed Link PaymentIntent returned by the provider for title-suffix lifecycle tests.
	 *
	 * @return array<string,mixed>
	 */
	private function completed_link_payment_intent_result(): array {
		return array(
			'id'       => 'pi_lifecycle_title',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_lifecycle_title',
						'currency'               => 'usd',
						'amount'                 => 1000,
						'application_fee_amount' => 35,
						'balance_transaction'    => array( 'id' => 'txn_lifecycle_title' ),
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array( 'wallet' => array( 'type' => 'link' ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * Create a WooPayments order for processing tests.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Assert that an order has a note containing the expected text.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assertOrderHasNoteContaining( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, $expected ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "Missing order note containing: {$expected}" );
	}

	/**
	 * Build a PaymentProcessingService wired to a specific lifecycle service.
	 *
	 * @param OrderPaymentLifecycleService $lifecycle_service Lifecycle service to inject.
	 * @return PaymentProcessingService
	 */
	private function build_sut_with_lifecycle( OrderPaymentLifecycleService $lifecycle_service ): PaymentProcessingService {
		$sut = new PaymentProcessingService();
		$sut->init(
			$this->store,
			$lifecycle_service,
			$this->idempotency,
			wc_get_container()->get( PaymentExceptionPolicy::class )
		);

		return $sut;
	}

	/**
	 * Create a lifecycle service that always throws when applying an outcome.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function create_throwing_lifecycle_service(): OrderPaymentLifecycleService {
		$lifecycle = new class() extends OrderPaymentLifecycleService {
			/**
			 * Always throw to simulate a lifecycle application failure after a successful charge.
			 *
			 * @param WC_Order                      $order               Order object.
			 * @param PaymentLifecycleEvent         $event               Lifecycle event.
			 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
			 * @throws RuntimeException Always, to drive the post-charge failure path.
			 */
			public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabulary $persistence_profile ): void {
				// Avoid parameter not used PHPCS errors.
				unset( $order, $event, $persistence_profile );
				throw new RuntimeException( 'Simulated lifecycle failure after a successful charge.' );
			}
		};
		$lifecycle->init( $this->store );

		return $lifecycle;
	}

	/**
	 * Build a PaymentProcessingService that records whether the order payment lock is held during refund resolution.
	 *
	 * @return PaymentProcessingService
	 */
	private function build_lock_observing_sut(): PaymentProcessingService {
		$store                    = $this->store;
		$sut                      = new class() extends PaymentProcessingService {
			/**
			 * Whether the order payment lock was held when refund-instance resolution ran.
			 *
			 * @var bool
			 */
			public bool $lock_held_during_resolution = false;

			/**
			 * Order payment store used to observe the lock.
			 *
			 * @var OrderPaymentStore
			 */
			public OrderPaymentStore $observed_store;

			/**
			 * Persistence profile used by the observed provider.
			 *
			 * @var ProviderPersistenceProfile
			 */
			public ProviderPersistenceProfile $persistence_profile;

			/**
			 * Record whether the order payment lock is held when refund-instance resolution runs.
			 *
			 * @param WC_Order $order  Parent order.
			 * @param float    $amount Refund amount.
			 * @param string   $reason Refund reason.
			 * @return string|null
			 */
			protected function resolve_refund_instance_id( WC_Order $order, float $amount, string $reason ): ?string {
				// A held order lock rejects a fresh claim from any other operation, so a failed probe
				// claim proves resolution is running under the lock regardless of the value it was
				// claimed with. Release the probe again if it unexpectedly succeeds so the spy never
				// perturbs the order lock state the real refund relies on.
				$probe_claimed                     = $this->observed_store->claim_order_payment_lock( $order, $this->persistence_profile, 'probe' );
				$this->lock_held_during_resolution = ! $probe_claimed;
				if ( $probe_claimed ) {
					$this->observed_store->unlock_order_payment( $order, $this->persistence_profile );
				}

				return parent::resolve_refund_instance_id( $order, $amount, $reason );
			}
		};
		$sut->observed_store      = $store;
		$sut->persistence_profile = $this->persistence_profile;
		$sut->init(
			$store,
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->idempotency,
			wc_get_container()->get( PaymentExceptionPolicy::class )
		);

		return $sut;
	}

	/**
	 * Create a fake WC logger that records error calls, injected via the woocommerce_logging_class filter.
	 *
	 * @return object Fake logger that tracks error calls.
	 */
	private function create_fake_logger(): object {
		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		return new class() implements \WC_Logger_Interface {
			public array $error_calls = array();

			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				unset( $handle, $message, $level ); // Avoid parameter not used PHPCS errors.
				return true;
			}

			public function log( $level, $message, $context = array() ) {
				unset( $level, $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function emergency( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function alert( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function critical( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function notice( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function debug( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function info( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function warning( $message, $context = array() ) {
				unset( $message, $context ); // Avoid parameter not used PHPCS errors.
			}

			public function error( $message, $context = array() ) {
				$this->error_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
	}

	/**
	 * Create a fake WC logger whose error method throws.
	 *
	 * @return object Throwing fake logger.
	 */
	private function create_throwing_logger(): object {
		$logger = $this->create_fake_logger();

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		return new class( $logger ) implements \WC_Logger_Interface {
			private object $logger;

			public function __construct( object $logger ) {
				$this->logger = $logger;
			}

			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				return $this->logger->add( $handle, $message, $level );
			}

			public function log( $level, $message, $context = array() ) {
				$this->logger->log( $level, $message, $context );
			}

			public function emergency( $message, $context = array() ) {
				$this->logger->emergency( $message, $context );
			}

			public function alert( $message, $context = array() ) {
				$this->logger->alert( $message, $context );
			}

			public function critical( $message, $context = array() ) {
				$this->logger->critical( $message, $context );
			}

			public function notice( $message, $context = array() ) {
				$this->logger->notice( $message, $context );
			}

			public function debug( $message, $context = array() ) {
				$this->logger->debug( $message, $context );
			}

			public function info( $message, $context = array() ) {
				$this->logger->info( $message, $context );
			}

			public function warning( $message, $context = array() ) {
				$this->logger->warning( $message, $context );
			}

			public function error( $message, $context = array() ) {
				unset( $message, $context );
				throw new RuntimeException( 'Recovery logger failed.' );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
	}
}
