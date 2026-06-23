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
use RuntimeException;
use WC_Order;
use WC_Order_Refund;
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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut         = wc_get_container()->get( PaymentProcessingService::class );
		$this->store       = wc_get_container()->get( OrderPaymentStore::class );
		$this->idempotency = wc_get_container()->get( PaymentOperationIdempotency::class );
	}

	/**
	 * @testdox Should call the provider with a deterministic key and complete the order for completed outcomes.
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
		$this->assertSame(
			$this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 10.00, 'USD' ),
			$provider->last_idempotency_key,
			'The provider must receive the deterministic operation key.'
		);
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_test', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_test', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'ch_test', $order->get_meta( '_charge_id', true ) );
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
		$key   = $this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 10.00, 'USD' );
		$this->store->lock_order_payment( $order, $key );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( 0, $provider->charge_calls );
		$this->store->unlock_order_payment( $order );
	}

	/**
	 * @testdox Should not call the provider while any order operation is locked.
	 */
	public function test_process_checkout_returns_failure_when_any_order_operation_is_locked(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->store->lock_order_payment( $order, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( 0, $provider->charge_calls );
		$this->store->unlock_order_payment( $order );
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

		$provider = new RecordingProvider(
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
		);

		$result          = $this->sut->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$unlinked_refund = wc_get_order( $unlinked_refund->get_id() );
		$linked_refund   = wc_get_order( $linked_refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $unlinked_refund );
		$this->assertInstanceOf( WC_Order_Refund::class, $linked_refund );
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
		$this->store->lock_order_payment( $order, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$capture_outcome = $this->sut->capture( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$cancel_outcome  = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $capture_outcome->get_status() );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $cancel_outcome->get_status() );
		$this->assertSame( 0, $provider->capture_calls );
		$this->assertSame( 0, $provider->cancel_calls );

		$this->store->unlock_order_payment( $order );
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
		$order    = $this->create_woopayments_order( '12.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_canceled' ) );

		$outcome = $this->sut->cancel( PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_canceled', $order->get_meta( '_intent_id', true ) );
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
			 * @param WC_Order              $order Order object.
			 * @param PaymentLifecycleEvent $event Lifecycle event.
			 * @throws RuntimeException Always, to drive the post-charge failure path.
			 */
			public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event ): void {
				// Avoid parameter not used PHPCS errors.
				unset( $order, $event );
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
		$store               = $this->store;
		$sut                 = new class() extends PaymentProcessingService {
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
				$probe_claimed                     = $this->observed_store->claim_order_payment_lock( $order, 'probe' );
				$this->lock_held_during_resolution = ! $probe_claimed;
				if ( $probe_claimed ) {
					$this->observed_store->unlock_order_payment( $order );
				}

				return parent::resolve_refund_instance_id( $order, $amount, $reason );
			}
		};
		$sut->observed_store = $store;
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
}
