<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use RuntimeException;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderEffectApplier class.
 */
class WooPaymentsOrderEffectApplierTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_native_woopayments_related_subscriptions_for_order' );
		remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
		parent::tearDown();
	}

	/**
	 * @testdox Lifecycle enrichment composes PaymentIntent data without persisting order effects.
	 */
	public function test_lifecycle_enrichment_does_not_persist_payment_intent_effects(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$result  = array(
			'id'       => 'pi_read_only',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_read_only',
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_read_only', '', 'pm_read_only' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent( $result, false );

		$enriched = $this->create_applier()->enrich_outcome_for_lifecycle(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_read_only' ),
			$outcome,
			$plan
		);
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertArrayHasKey( PaymentOutcome::DATA_META, $enriched->get_data() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'Card', $reloaded->get_payment_method_title() );
		$this->assertSame( '', $reloaded->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox Lifecycle enrichment carries SetupIntent metadata without persisting it directly.
	 */
	public function test_lifecycle_enrichment_carries_setup_meta_without_persisting_it(): void {
		$order   = $this->create_woopayments_order( '0.00' );
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'seti_read_only', '', 'pm_read_only', 'cus_read_only' );
		$plan    = WooPaymentsOrderEffectPlan::for_setup_intent(
			array( 'id' => 'seti_read_only' ),
			false,
			array(
				'_wcpay_intent_currency' => 'USD',
				'_wcpay_mode'            => 'test',
			)
		);

		$enriched = $this->create_applier()->enrich_outcome_for_lifecycle(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_read_only' ),
			$outcome,
			$plan
		);
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'test', $enriched->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_mode'] );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_wcpay_mode', true ) );
	}

	/**
	 * @testdox PaymentIntent effects carry display metadata without mutating display details before lifecycle application.
	 */
	public function test_payment_intent_effects_persist_display_details(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$result  = array(
			'id'       => 'pi_display',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_display',
						'currency'               => 'usd',
						'amount'                 => 5000,
						'application_fee_amount' => 175,
						'balance_transaction'    => array( 'id' => 'txn_display' ),
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand'         => 'visa',
								'display_brand' => 'visa',
								'funding'       => 'credit',
								'last4'         => '4242',
							),
						),
					),
				),
			),
		);
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_display',
			'',
			'pm_display',
			'',
			array(
				PaymentOutcome::DATA_META => array( '_wcpay_payment_method_details' => '[]' ),
			)
		);
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent( $result, false );

		$applier         = $this->create_applier();
		$applied_outcome = $applier->apply( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_display' ), $outcome, $plan );
		$order           = wc_get_order( $order->get_id() );

		$this->assertNotSame( $outcome, $applied_outcome );
		$this->assertSame( '4242', $applied_outcome->get_data()[ PaymentOutcome::DATA_META ]['last4'] );
		$this->assertStringContainsString( '"last4":"4242"', $applied_outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_payment_method_details'] );
		$this->assertSame( '1.75', $applied_outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $applied_outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_net'] );
		$this->assertSame( 'txn_display', $applied_outcome->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_payment_transaction_id'] );
		$this->assertSame( $plan, $applied_outcome->get_effect_plan() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '', $order->get_meta( 'last4', true ) );
		$this->assertSame( '', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( 'WooPayments', $order->get_payment_method_title() );

		$applier->apply_payment_method_display_details( $order, $result );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '4242', $order->get_meta( 'last4', true ) );
		$this->assertSame( 'visa', $order->get_meta( '_card_brand', true ) );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
	}

	/**
	 * @testdox Authorized PaymentIntent effects retain charge and fraud metadata.
	 */
	public function test_authorized_payment_intent_effects_retain_charge_and_fraud_meta(): void {
		$order   = $this->create_woopayments_order();
		$result  = array(
			'id'       => 'pi_authorized',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'allow' ),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_authorized',
						'currency'               => 'usd',
						'amount'                 => 5000,
						'application_fee_amount' => 175,
						'outcome'                => array( 'risk_level' => 'normal' ),
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_authorized' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent( $result, false );

		$applied_outcome = $this->create_applier()->apply(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_authorized' ),
			$outcome,
			$plan
		);
		$meta            = $applied_outcome->get_data()[ PaymentOutcome::DATA_META ];

		$this->assertSame( 'allow', $meta['_wcpay_fraud_outcome_status'] );
		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertArrayNotHasKey( '_wcpay_transaction_fee', $meta );
		$this->assertArrayNotHasKey( '_wcpay_net', $meta );
	}

	/**
	 * @testdox A neutral PaymentIntent outcome is enriched before lifecycle persistence.
	 */
	public function test_neutral_payment_intent_outcome_is_enriched_before_lifecycle(): void {
		$order   = $this->create_woopayments_order( '25.00' );
		$result  = array(
			'id'       => 'pi_neutral',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                  => 'ch_neutral',
						'currency'            => 'usd',
						'amount'              => 2500,
						'balance_transaction' => array( 'id' => 'txn_neutral' ),
					),
				),
			),
		);
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_neutral', '', 'pm_neutral', 'cus_neutral' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent( $result, false );

		$applied = $this->create_applier()->apply(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_neutral' ),
			$outcome,
			$plan
		);
		$data    = $applied->get_data();
		$meta    = $data[ PaymentOutcome::DATA_META ];

		$this->assertSame( 'USD', $meta['_wcpay_intent_currency'] );
		$this->assertSame( 'live', $meta['_wcpay_mode'] );
		$this->assertSame( 'ch_neutral', $meta['_charge_id'] );
		$this->assertSame( 'txn_neutral', $meta['_wcpay_payment_transaction_id'] );
		$this->assertStringContainsString( 'successfully charged', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertSame( 'payment_success', $data[ PaymentOutcome::DATA_NOTE_TYPE ] );
	}

	/**
	 * @testdox Final payment identity is propagated to subscriptions created from the parent order.
	 */
	public function test_final_payment_method_display_details_propagate_to_related_subscriptions(): void {
		$title_filter_calls = 0;
		$order              = $this->create_woopayments_order();
		$subscription       = $this->create_woopayments_order();
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();

		add_filter(
			'woocommerce_native_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function ( string $suffix ) use ( &$title_filter_calls ): string {
				++$title_filter_calls;

				return $suffix;
			}
		);

		$result = array(
			'charges' => array(
				'data' => array(
					array(
						'payment_method_details' => array(
							'type'       => 'amazon_pay',
							'amazon_pay' => array(
								'funding' => array(
									'card' => array(
										'brand' => 'Visa',
										'last4' => '4242',
									),
								),
							),
						),
					),
				),
			),
		);

		$this->create_applier( $this->create_token_service() )->apply_payment_method_display_details( $order, $result );
		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay', $order->get_payment_method() );
		$this->assertSame( 'Amazon Pay (WooPayments)', $order->get_payment_method_title() );
		$this->assertSame( 1, $title_filter_calls, 'The suffix filter should run once when the display title is applied.' );
		$this->assertSame( $order->get_payment_method(), $subscription->get_payment_method() );
		$this->assertSame( $order->get_payment_method_title(), $subscription->get_payment_method_title() );
	}

	/**
	 * @testdox Requested token effects create and attach one token across repeated application.
	 */
	public function test_requested_token_effects_are_idempotent(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();

		$token_service = $this->create_token_service(
			array(
				'pm_new' => array(
					'id'   => 'pm_new',
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
		$context       = PaymentContext::for_checkout(
			$order,
			OrderPaymentStore::GATEWAY_ID,
			'pm_submitted',
			array( 'save_payment_method' => true )
		);
		$outcome       = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_new', '', 'pm_new', 'cus_new' );
		$plan          = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_new',
				'status'         => 'succeeded',
				'payment_method' => 'pm_new',
			),
			false
		);
		$applier       = $this->create_applier( $token_service );

		$first_result  = $applier->apply( $context, $outcome, $plan );
		$second_result = $applier->apply( $context, $first_result, $plan );
		$order         = wc_get_order( $order->get_id() );
		$tokens        = \WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );
		$token         = reset( $tokens );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $second_result->get_status() );
		$this->assertSame( 'pi_new', $second_result->get_provider_payment_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $tokens );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertSame( 'pm_new', $token->get_token() );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
	}

	/**
	 * @testdox Existing saved-token effects attach the selected token to the order.
	 */
	public function test_saved_token_effects_attach_selected_token(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_saved' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$context = PaymentContext::for_checkout(
			$order,
			OrderPaymentStore::GATEWAY_ID,
			'pm_saved',
			array( 'payment_token' => (string) $token->get_id() )
		);
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_saved', '', 'pm_saved', 'cus_saved' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_saved',
				'status'         => 'succeeded',
				'payment_method' => 'pm_saved',
			),
			false
		);

		$result = $this->create_applier( $this->create_token_service() )->apply( $context, $outcome, $plan );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $result->get_status() );
		$this->assertSame( 'pi_saved', $result->get_provider_payment_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( $token->get_id() ), array_values( $order->get_payment_tokens() ) );
	}

	/**
	 * @testdox Recurring token effects synchronize the token and provider metadata to related subscriptions.
	 */
	public function test_recurring_token_effects_synchronize_related_subscriptions(): void {
		$user_id      = $this->factory()->user->create();
		$order        = $this->create_woopayments_order();
		$subscription = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->save();

		add_filter(
			'woocommerce_native_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);

		$token_service = $this->create_token_service(
			array(
				'pm_recurring' => array(
					'id'   => 'pm_recurring',
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
		$outcome       = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_recurring', '', 'pm_recurring', 'cus_recurring' );
		$plan          = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_recurring',
				'status'         => 'succeeded',
				'payment_method' => 'pm_recurring',
			),
			true
		);

		$result       = $this->create_applier( $token_service )->apply( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_recurring' ), $outcome, $plan );
		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$tokens       = \WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );
		$token        = reset( $tokens );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $result->get_status() );
		$this->assertSame( 'pi_recurring', $result->get_provider_payment_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens() );
		$this->assertContains( $token->get_id(), $subscription->get_payment_tokens() );
		$this->assertSame( 'pm_recurring', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_recurring', $subscription->get_meta( '_stripe_customer_id', true ) );
	}

	/**
	 * @testdox Recurring token failures preserve provider identity in a failed replacement outcome.
	 */
	public function test_recurring_token_failure_preserves_provider_identity(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( 'pm_paid', $user_id )
			->willThrowException( new RuntimeException( 'Token storage failed.' ) );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_paid',
			'',
			'pm_paid',
			'cus_paid',
			array(
				PaymentOutcome::DATA_META      => array( '_charge_id' => 'ch_paid' ),
				PaymentOutcome::DATA_NOTE      => 'Successful payment note.',
				PaymentOutcome::DATA_NOTE_TYPE => 'payment_success',
			)
		);
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_paid',
				'status'         => 'succeeded',
				'payment_method' => 'pm_paid',
			),
			true
		);

		$result = $this->create_applier( $token_service )->apply(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_submitted' ),
			$outcome,
			$plan
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $result->get_status() );
		$this->assertSame( 'pi_paid', $result->get_provider_payment_id() );
		$this->assertSame( 'pm_paid', $result->get_payment_method_id() );
		$this->assertSame( 'cus_paid', $result->get_customer_id() );
		$this->assertSame( 'ch_paid', $result->get_data()[ PaymentOutcome::DATA_META ]['_charge_id'] );
		$this->assertSame( 'wcpay_recurring_token_save_failed', $result->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $result->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $result->get_data() );
	}

	/**
	 * @testdox Critical recurring token effects complete before fallible note rendering runs.
	 */
	public function test_recurring_token_effects_run_before_note_rendering(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();

		$sequence      = array();
		$token         = new WC_Payment_Token_CC();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user', 'attach_token_to_order', 'sync_related_subscriptions_payment_token' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'get_or_create_token_for_user' )
			->with( 'pm_ordered', $user_id )
			->willReturnCallback(
				static function () use ( &$sequence, $token ): WC_Payment_Token_CC {
					$sequence[] = 'token';
					return $token;
				}
			);
		$token_service->expects( $this->once() )
			->method( 'attach_token_to_order' )
			->with( $order, $token )
			->willReturnCallback(
				static function () use ( &$sequence ): bool {
					$sequence[] = 'attach';
					return true;
				}
			);
		$token_service->expects( $this->once() )
			->method( 'sync_related_subscriptions_payment_token' )
			->with( $order, $token, 'pm_ordered', 'cus_ordered' )
			->willReturnCallback(
				static function () use ( &$sequence ): void {
					$sequence[] = 'sync';
				}
			);
		$note_service = $this->getMockBuilder( WooPaymentsOrderNoteService::class )
			->onlyMethods( array( 'format_payment_success_note' ) )
			->getMock();
		$note_service->expects( $this->once() )
			->method( 'format_payment_success_note' )
			->willReturnCallback(
				static function () use ( &$sequence ): string {
					$sequence[] = 'render';
					throw new RuntimeException( 'Note rendering failed.' );
				}
			);
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_ordered', '', 'pm_ordered', 'cus_ordered' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_ordered',
				'status'         => 'succeeded',
				'payment_method' => 'pm_ordered',
			),
			true
		);

		try {
			$this->create_applier( $token_service, null, null, null, $note_service )->apply(
				PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_ordered' ),
				$outcome,
				$plan
			);
			$this->fail( 'Expected note rendering to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Note rendering failed.', $exception->getMessage() );
		}

		$this->assertSame( array( 'token', 'attach', 'sync', 'render' ), $sequence );
	}

	/**
	 * @testdox Saved-token attachment failures preserve provider identity for recurring payments.
	 */
	public function test_recurring_saved_token_attachment_failure_preserves_provider_identity(): void {
		$user_id = $this->factory()->user->create();
		$order   = $this->create_woopayments_order();
		$order->set_customer_id( $user_id );
		$order->save();
		$token = new WC_Payment_Token_CC();

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_valid_token_from_token_id', 'attach_token_to_order', 'sync_related_subscriptions_payment_token' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'get_valid_token_from_token_id' )
			->with( '17', $user_id )
			->willReturn( $token );
		$token_service->expects( $this->once() )
			->method( 'attach_token_to_order' )
			->with( $order, $token )
			->willThrowException( new RuntimeException( 'Token attachment failed.' ) );
		$token_service->expects( $this->never() )
			->method( 'sync_related_subscriptions_payment_token' );
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_saved_failure', '', 'pm_saved_failure', 'cus_saved_failure' );
		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'             => 'pi_saved_failure',
				'status'         => 'succeeded',
				'payment_method' => 'pm_saved_failure',
			),
			true
		);

		$result = $this->create_applier( $token_service )->apply(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_saved_failure',
				array( 'payment_token' => '17' )
			),
			$outcome,
			$plan
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $result->get_status() );
		$this->assertSame( 'pi_saved_failure', $result->get_provider_payment_id() );
		$this->assertSame( 'pm_saved_failure', $result->get_payment_method_id() );
		$this->assertSame( 'cus_saved_failure', $result->get_customer_id() );
		$this->assertSame( 'wcpay_recurring_token_save_failed', $result->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] );
	}

	/**
	 * @testdox SetupIntent effects persist provider references before customer authentication callbacks.
	 */
	public function test_setup_intent_effects_persist_provider_references(): void {
		$order   = $this->create_woopayments_order( '0.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'seti_effects',
			'#wcpay-confirm-si:1:secret:nonce',
			'pm_effects',
			'cus_effects'
		);
		$plan    = WooPaymentsOrderEffectPlan::for_setup_intent(
			array(
				'id'             => 'seti_effects',
				'status'         => 'requires_action',
				'payment_method' => 'pm_effects',
			),
			false,
			array(
				'_wcpay_intent_currency' => 'USD',
				'_wcpay_mode'            => 'test',
			)
		);

		$result = $this->create_applier()->apply( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_effects' ), $outcome, $plan );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'seti_effects', $order->get_transaction_id() );
		$this->assertSame( 'seti_effects', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_effects', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_effects', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'test', $order->get_meta( '_wcpay_mode', true ) );
	}

	/**
	 * @testdox Capture effects delegate successful fee details to the order data service.
	 */
	public function test_capture_effects_delegate_fee_details(): void {
		$original_currency = get_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_woopayments_order();
		$order->set_currency( 'GBP' );
		$order->save();
		$capture_result     = array(
			'id'       => 'pi_capture_effects',
			'status'   => 'succeeded',
			'currency' => 'gbp',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_capture_effects',
						'currency'               => 'gbp',
						'amount'                 => 5000,
						'application_fee_amount' => 175,
						'balance_transaction'    => array(
							'id'            => 'txn_capture_effects',
							'exchange_rate' => 1.33127,
						),
					),
				),
			),
		);
		$order_data_service = $this->getMockBuilder( WooPaymentsOrderDataService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'add_fee_breakdown_note_from_intent' ) )
			->getMock();
		$order_data_service->expects( $this->once() )
			->method( 'add_fee_breakdown_note_from_intent' )
			->with( $order, $capture_result, false )
			->willReturn( true );

		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_effects' );
		$plan    = WooPaymentsOrderEffectPlan::for_capture( $capture_result );
		try {
			$result = $this->create_applier( null, $order_data_service )->apply( PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ), $outcome, $plan );
		} finally {
			update_option( 'woocommerce_currency', $original_currency );
		}

		$this->assertNotSame( $outcome, $result );
		$this->assertSame( 'ch_capture_effects', $result->get_data()[ PaymentOutcome::DATA_META ]['_charge_id'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_transaction_id', $result->get_data()[ PaymentOutcome::DATA_META ] );
		$this->assertSame( '1.75', $result->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $result->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_net'] );
		$this->assertSame( 'live', $result->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_mode'] );
		$this->assertSame( '1.33127', $result->get_data()[ PaymentOutcome::DATA_META ]['_wcpay_multi_currency_stripe_exchange_rate'] );
	}

	/**
	 * @testdox Cancellation effects compose the successful authorization note and fee metadata cleanup.
	 */
	public function test_cancel_effects_compose_note_and_fee_meta_cleanup(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', 'ch_cancel_effects' );
		$order->save();
		$cancel_result = array(
			'id'     => 'pi_cancel_effects',
			'status' => 'canceled',
		);
		$outcome       = new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_cancel_effects' );
		$plan          = WooPaymentsOrderEffectPlan::for_cancel( $cancel_result );

		$result = $this->create_applier()->apply(
			PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ),
			$outcome,
			$plan
		);

		$this->assertNotSame( $outcome, $result );
		$this->assertSame(
			( new WooPaymentsOrderNoteService() )->format_capture_cancelled_note( 'pi_cancel_effects', 'ch_cancel_effects' ),
			$result->get_data()[ PaymentOutcome::DATA_NOTE ]
		);
		$this->assertSame( 'capture_canceled', $result->get_data()[ PaymentOutcome::DATA_NOTE_TYPE ] );
		$this->assertSame( array( '_wcpay_transaction_fee', '_wcpay_net' ), $result->get_data()[ PaymentOutcome::DATA_META_TO_DELETE ] );
	}

	/**
	 * @testdox Unsuccessful or mismatched cancellation effects preserve fee data and omit success effects.
	 *
	 * @dataProvider provide_unsuccessful_cancel_effects
	 *
	 * @param string $outcome_status  Neutral outcome status.
	 * @param string $provider_status Provider cancellation status.
	 */
	public function test_unsuccessful_cancel_effects_omit_success_data( string $outcome_status, string $provider_status ): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_transaction_fee', '1.25' );
		$order->update_meta_data( '_wcpay_net', '8.75' );
		$order->save();
		$outcome = new PaymentOutcome(
			$outcome_status,
			'pi_cancel_guard',
			'',
			'',
			'',
			array( PaymentOutcome::DATA_ERROR_MESSAGE => 'Cancellation rejected.' )
		);
		$plan    = WooPaymentsOrderEffectPlan::for_cancel(
			array(
				'id'     => 'pi_cancel_guard',
				'status' => $provider_status,
			)
		);

		$result = $this->create_applier()->apply(
			PaymentContext::for_cancel( $order, OrderPaymentStore::GATEWAY_ID ),
			$outcome,
			$plan
		);

		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META_TO_DELETE, $result->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $result->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $result->get_data() );
		$this->assertSame( '1.25', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '8.75', $order->get_meta( '_wcpay_net', true ) );
	}

	/**
	 * Provide unsuccessful and mismatched cancellation effect states.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function provide_unsuccessful_cancel_effects(): array {
		return array(
			'failed outcome and provider result' => array( PaymentOutcome::STATUS_FAILED, 'requires_capture' ),
			'canceled outcome with mismatched provider result' => array( PaymentOutcome::STATUS_CANCELED, 'requires_capture' ),
		);
	}

	/**
	 * @testdox Refund effects compose WooPayments-compatible metadata and notes after transport.
	 */
	public function test_refund_effects_compose_compatibility_data(): void {
		$order   = $this->create_woopayments_order();
		$result  = array(
			'id'                  => 're_effects',
			'status'              => 'pending',
			'balance_transaction' => array( 'id' => 'txn_refund_effects' ),
		);
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			're_effects',
			'',
			'',
			'',
			array(
				'refund_status'                 => 'pending',
				'refund_balance_transaction_id' => 'txn_refund_effects',
			)
		);
		$plan    = WooPaymentsOrderEffectPlan::for_refund( $result );

		$applied_outcome = $this->create_applier()->apply(
			PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ),
			$outcome,
			$plan
		);
		$data            = $applied_outcome->get_data();

		$this->assertSame( 'pending', $data[ PaymentOutcome::DATA_ORDER_META ]['_wcpay_refund_status'] );
		$this->assertSame( 're_effects', $data[ PaymentOutcome::DATA_REFUND_META ]['_wcpay_refund_id'] );
		$this->assertSame( 'txn_refund_effects', $data[ PaymentOutcome::DATA_REFUND_META ]['_wcpay_refund_transaction_id'] );
		$this->assertStringContainsString( 'is pending', $data[ PaymentOutcome::DATA_REFUND_NOTE ] );
		$this->assertStringContainsString( 'Adjustment', $data[ PaymentOutcome::DATA_REFUND_NOTE ] );
		$this->assertStringContainsString( 're_effects', $data[ PaymentOutcome::DATA_REFUND_NOTE ] );
		$this->assertSame( $plan, $applied_outcome->get_effect_plan() );
	}

	/**
	 * @testdox Refund effects use the injected note service after retaining provider identity.
	 */
	public function test_refund_uses_injected_note_service_and_retains_provider_identity_when_rendering_throws(): void {
		$order        = $this->create_woopayments_order();
		$result       = array(
			'id'     => 're_retained',
			'status' => 'succeeded',
		);
		$outcome      = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_retained', '', '', '', array( 'refund_status' => 'successful' ) );
		$plan         = WooPaymentsOrderEffectPlan::for_refund( $result );
		$note_service = $this->getMockBuilder( WooPaymentsOrderNoteService::class )
			->onlyMethods( array( 'format_created_refund_note' ) )
			->getMock();
		$note_service->expects( $this->once() )
			->method( 'format_created_refund_note' )
			->willThrowException( new RuntimeException( 'Local refund note formatting failed.' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Local refund note formatting failed.' );

		try {
			$this->create_applier( null, null, null, null, $note_service )->apply(
				PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 2.50, 'Adjustment' ),
				$outcome->with_effect_plan( $plan ),
				$plan
			);
		} finally {
			$this->assertSame( 're_retained', $outcome->get_provider_payment_id() );
			$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		}
	}

	/**
	 * Create an effect applier with focused dependencies.
	 *
	 * @param WooPaymentsTokenService|null     $token_service      Token service.
	 * @param WooPaymentsOrderDataService|null $order_data_service Order data service.
	 * @param WooPaymentsAccountService|null   $account_service    Account service.
	 * @param WooPaymentsLegacyRuntime|null    $legacy_runtime     Legacy runtime.
	 * @param WooPaymentsOrderNoteService|null $note_service       Order note service.
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function create_applier( ?WooPaymentsTokenService $token_service = null, ?WooPaymentsOrderDataService $order_data_service = null, ?WooPaymentsAccountService $account_service = null, ?WooPaymentsLegacyRuntime $legacy_runtime = null, ?WooPaymentsOrderNoteService $note_service = null ): WooPaymentsOrderEffectApplier {
		$token_service      = $token_service ?? $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->getMock();
		$order_data_service = $order_data_service ?? $this->getMockBuilder( WooPaymentsOrderDataService::class )
			->disableOriginalConstructor()
			->getMock();
		if ( null === $account_service ) {
			$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_account_country', 'get_account_default_currency', 'get_mode' ) )
				->getMock();
			$account_service->method( 'get_account_country' )->willReturn( 'US' );
			$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
			$account_service->method( 'get_mode' )->willReturn( 'live' );
		}
		if ( null === $legacy_runtime ) {
			$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_logger' ) )
				->getMock();
			$legacy_runtime->method( 'get_logger' )->willReturn( null );
		}

		$applier = new WooPaymentsOrderEffectApplier();
		$applier->init(
			$token_service,
			$order_data_service,
			$account_service,
			$legacy_runtime,
			$note_service ?? new WooPaymentsOrderNoteService(),
			new WooPaymentsPaymentMethodRegistry()
		);

		return $applier;
	}

	/**
	 * Create a token service with fake provider payment-method details.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment-method details keyed by provider ID.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service( array $payment_method_details = array() ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details ) extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Payment-method details keyed by provider ID.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $payment_method_details;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<string,mixed>> $payment_method_details Payment-method details keyed by provider ID.
			 */
			public function __construct( array $payment_method_details ) {
				$this->payment_method_details = $payment_method_details;
			}

			/**
			 * Get payment-method details.
			 *
			 * @param string $payment_method_id Provider payment-method ID.
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
	 * Create a WooPayments order.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total = '10.00' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
