<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderEffects class.
 */
class WooPaymentsOrderEffectsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Completed charge metadata accepts explicit settlement metadata without a service.
	 */
	public function test_completed_charge_meta_accepts_explicit_settlement_meta_without_a_service(): void {
		$intent = array(
			'currency' => 'usd',
			'metadata' => array(),
		);
		$charge = array(
			'currency'               => 'usd',
			'amount'                 => 5000,
			'application_fee_amount' => 218,
			'balance_transaction'    => array( 'id' => 'txn_native' ),
			'outcome'                => array( 'risk_level' => 'normal' ),
			'payment_method_details' => array( 'type' => 'card' ),
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
		);

		$meta = WooPaymentsOrderEffects::completed_charge_meta(
			$intent,
			$charge,
			array( '_wcpay_multi_currency_stripe_exchange_rate' => '1.33127' )
		);

		$this->assertSame( '1.75', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $meta['_wcpay_net'] );
		$this->assertSame( 'txn_native', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertSame( '1.33127', $meta['_wcpay_multi_currency_stripe_exchange_rate'] );
		$this->assertArrayNotHasKey( '_wcpay_fraud_outcome_status', $meta );
	}

	/**
	 * @testdox Completed non-card metadata marks the fraud box without inventing an outcome.
	 */
	public function test_completed_charge_meta_marks_non_card_without_defaulting_fraud_outcome(): void {
		$intent = array(
			'currency' => 'eur',
			'metadata' => array(),
		);
		$charge = array(
			'currency'               => 'eur',
			'amount'                 => 6500,
			'application_fee_amount' => 233,
			'balance_transaction'    => array( 'id' => 'txn_ideal' ),
			'outcome'                => array( 'risk_level' => 'normal' ),
			'payment_method_details' => array( 'type' => 'ideal' ),
		);

		$meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge );

		$this->assertSame( 'txn_ideal', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertArrayNotHasKey( '_wcpay_fraud_outcome_status', $meta );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox PaymentIntent metadata projects base, financial, and provider facts from explicit inputs.
	 */
	public function test_payment_intent_meta_projects_completed_effects(): void {
		$intent = array(
			'id'       => 'pi_native',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                  => 'ch_native',
						'currency'            => 'usd',
						'amount'              => 2500,
						'balance_transaction' => array( 'id' => 'txn_native' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::payment_intent_meta( $intent, 'USD', 'test' );

		$this->assertSame( 'USD', $meta['_wcpay_intent_currency'] );
		$this->assertSame( 'test', $meta['_wcpay_mode'] );
		$this->assertSame( 'ch_native', $meta['_charge_id'] );
		$this->assertSame( 'txn_native', $meta['_wcpay_payment_transaction_id'] );
	}

	/**
	 * @testdox Started PaymentIntent metadata preserves non-card classification.
	 */
	public function test_payment_intent_meta_projects_started_non_card_effects(): void {
		$meta = WooPaymentsOrderEffects::payment_intent_meta(
			array(
				'status'               => 'requires_action',
				'payment_method_types' => array( 'wechat_pay' ),
			),
			'USD',
			'live'
		);

		$this->assertSame( 'requires_action', $meta['_intention_status'] );
		$this->assertSame( '', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Completed capture metadata uses the latest charge and explicit settlement facts.
	 */
	public function test_completed_capture_meta_uses_latest_charge_and_explicit_inputs(): void {
		$intent = array(
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_old' ),
					array(
						'id'                  => 'ch_capture',
						'currency'            => 'usd',
						'amount'              => 5000,
						'balance_transaction' => array( 'id' => 'txn_capture' ),
						'fee_breakdown_v1'    => array(
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

		$meta = WooPaymentsOrderEffects::completed_capture_meta(
			$intent,
			'USD',
			'live',
			array( '_wcpay_multi_currency_stripe_exchange_rate' => '1.25' )
		);

		$this->assertSame( 'usd', $meta['_wcpay_intent_currency'] );
		$this->assertSame( 'live', $meta['_wcpay_mode'] );
		$this->assertSame( 'ch_capture', $meta['_charge_id'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_transaction_id', $meta );
		$this->assertSame( '1.75', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $meta['_wcpay_net'] );
		$this->assertSame( '1.25', $meta['_wcpay_multi_currency_stripe_exchange_rate'] );
	}

	/**
	 * @testdox Completed charge metadata stamps review_allowed when the order was held for review.
	 */
	public function test_completed_charge_meta_stamps_review_allowed_when_held_for_review(): void {
		$intent = array(
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'allow' ),
		);
		$charge = array(
			'currency'               => 'usd',
			'payment_method_details' => array( 'type' => 'card' ),
		);

		$held_meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge, array(), true, true );
		$this->assertSame( 'allow', $held_meta['_wcpay_fraud_outcome_status'] );
		$this->assertSame( 'review_allowed', $held_meta['_wcpay_fraud_meta_box_type'] );

		$plain_meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge );
		$this->assertSame( 'allow', $plain_meta['_wcpay_fraud_outcome_status'] );
		$this->assertSame( 'allow', $plain_meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Completed capture metadata threads the held-for-review flag to the fraud meta box.
	 */
	public function test_completed_capture_meta_stamps_review_allowed_when_held_for_review(): void {
		$intent = array(
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'allow' ),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_review_capture',
						'currency'               => 'usd',
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::completed_capture_meta( $intent, 'USD', 'live', array(), true );
		$this->assertSame( 'review_allowed', $meta['_wcpay_fraud_meta_box_type'] );

		$plain_meta = WooPaymentsOrderEffects::completed_capture_meta( $intent, 'USD', 'live' );
		$this->assertSame( 'allow', $plain_meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Pre-capture intents with a review outcome stamp the review fraud meta box.
	 */
	public function test_payment_intent_meta_stamps_review_box_when_holding_for_review(): void {
		$intent = array(
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'review' ),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_review_hold',
						'currency'               => 'usd',
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::payment_intent_meta( $intent, 'USD', 'live' );

		$this->assertSame( 'review', $meta['_wcpay_fraud_outcome_status'] );
		$this->assertSame( 'review', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Pre-capture review intents persist their nonempty ruleset evidence.
	 */
	public function test_payment_intent_meta_persists_nonempty_review_ruleset_only(): void {
		$intent = array(
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'fraud_outcome'         => 'review',
				'fraud_ruleset_results' => '{"avs_verification":"review","new_platform_rule":"block"}',
			),
			'charges'  => array(
				'data' => array(
					array(
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::payment_intent_meta( $intent, 'USD', 'live' );

		$this->assertSame( '{"avs_verification":"review","new_platform_rule":"block"}', $meta['_wcpay_fraud_ruleset_results'] );
	}

	/**
	 * @testdox Pre-capture intents with an allow outcome keep the allow fraud meta box.
	 */
	public function test_payment_intent_meta_keeps_allow_box_for_allowed_authorizations(): void {
		$intent = array(
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'allow' ),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_allow_hold',
						'currency'               => 'usd',
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::payment_intent_meta( $intent, 'USD', 'live' );

		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Completed intents with a review outcome do not stamp the review box.
	 */
	public function test_payment_intent_meta_does_not_stamp_review_box_on_completion(): void {
		$intent = array(
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'review' ),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_review_done',
						'currency'               => 'usd',
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::payment_intent_meta( $intent, 'USD', 'live' );

		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Non-card charges stay not_card even when held for review.
	 */
	public function test_fraud_outcome_meta_keeps_not_card_for_held_non_card_charges(): void {
		$intent = array(
			'currency' => 'usd',
			'metadata' => array( 'fraud_outcome' => 'allow' ),
		);
		$charge = array(
			'currency'               => 'usd',
			'payment_method_details' => array( 'type' => 'sepa_debit' ),
		);

		$meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge, array(), true, true );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Display projection returns identity and metadata without dispatching title filters.
	 */
	public function test_display_projection_does_not_dispatch_the_title_suffix_filter(): void {
		$filter_calls = 0;
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function ( string $suffix ) use ( &$filter_calls ): string {
				++$filter_calls;

				return $suffix;
			}
		);

		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
							'payment_method_details' => array(
								'type' => 'card',
								'card' => array(
									'brand' => 'visa',
									'last4' => '4242',
								),
							),
						),
					),
				),
			),
			'apple_pay'
		);

		$this->assertSame( 0, $filter_calls );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $effects['payment_method_id'] );
		$this->assertSame( 'card', $effects['payment_method_type'] );
		$this->assertSame( 'apple_pay', $effects['express_checkout_type'] );
		$this->assertSame( 'apple_pay', $effects['meta']['_wcpay_express_checkout_payment_method'] );
		$this->assertArrayNotHasKey( 'payment_method_title', $effects );
	}

	/**
	 * @testdox Display projection stores card details without rendering a title.
	 */
	public function test_display_projection_composes_card_metadata(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
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
			)
		);

		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $effects['payment_method_id'] );
		$this->assertSame( '4242', $effects['meta']['last4'] );
		$this->assertSame( 'visa', $effects['meta']['_card_brand'] );
		$this->assertStringContainsString( '"last4":"4242"', $effects['meta']['_wcpay_payment_method_details'] );
	}

	/**
	 * @testdox SetupIntent card projection requires the provider's exact raw card type.
	 */
	public function test_setup_intent_display_projection_requires_exact_raw_card_type(): void {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details(
			array(
				'type' => 'Card',
				'card' => array(
					'brand'    => 'visa',
					'last4'    => '4242',
					'customer' => 'cus_must_not_be_cached',
				),
			)
		);

		$this->assertSame( array(), $effects );
	}

	/**
	 * @testdox SetupIntent card projection rejects incoherent card identity details.
	 * @dataProvider incoherent_setup_intent_card_details_data
	 *
	 * @param array<string,mixed> $payment_method Incoherent provider payment-method details.
	 */
	public function test_setup_intent_card_display_projection_rejects_incoherent_details( array $payment_method ): void {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details( $payment_method );

		$this->assertSame( array(), $effects );
	}

	/**
	 * Provide malformed card identity provider responses.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function incoherent_setup_intent_card_details_data(): array {
		$valid = array(
			'type' => 'card',
			'card' => array(
				'brand'   => 'visa',
				'network' => 'visa',
				'funding' => 'credit',
				'last4'   => '4242',
			),
		);

		return array(
			'brand only'                   => array(
				array(
					'type' => 'card',
					'card' => array( 'brand' => 'visa' ),
				),
			),
			'missing last4'                => array(
				array(
					'type' => 'card',
					'card' => array(
						'brand'   => 'visa',
						'network' => 'visa',
						'funding' => 'credit',
					),
				),
			),
			'empty last4'                  => array( array_replace_recursive( $valid, array( 'card' => array( 'last4' => '' ) ) ) ),
			'array last4'                  => array( array_replace_recursive( $valid, array( 'card' => array( 'last4' => array( '4242' ) ) ) ) ),
			'missing funding'              => array( array_replace_recursive( $valid, array( 'card' => array( 'funding' => null ) ) ) ),
			'unsupported funding'          => array( array_replace_recursive( $valid, array( 'card' => array( 'funding' => 'charge' ) ) ) ),
			'array funding'                => array( array_replace_recursive( $valid, array( 'card' => array( 'funding' => array( 'credit' ) ) ) ) ),
			'empty selected title source'  => array( array_replace_recursive( $valid, array( 'card' => array( 'display_brand' => '' ) ) ) ),
			'array selected title source'  => array( array_replace_recursive( $valid, array( 'card' => array( 'display_brand' => array( 'visa' ) ) ) ) ),
			'non-array networks available' => array(
				array_replace_recursive(
					$valid,
					array(
						'card' => array(
							'network'  => null,
							'networks' => array( 'available' => 'visa' ),
						),
					)
				),
			),
		);
	}

	/**
	 * @testdox SetupIntent card projection caches only its normalized type and card subtree.
	 */
	public function test_setup_intent_card_display_projection_omits_top_level_provider_data_from_cache(): void {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details(
			array(
				'id'              => 'pm_card_identity',
				'type'            => 'card',
				'customer'        => 'cus_must_not_be_cached',
				'billing_details' => array(
					'email' => 'must-not-be-cached@example.test',
				),
				'card'            => array(
					'brand'   => 'visa',
					'network' => 'visa',
					'funding' => 'credit',
					'last4'   => '4242',
				),
			)
		);
		$details = json_decode( $effects['meta']['_wcpay_payment_method_details'], true );

		$this->assertSame( '4242', $effects['meta']['last4'] );
		$this->assertIsArray( $details );
		$this->assertSame( 'card', $details['type'] );
		$this->assertSame( '4242', $details['card']['last4'] );
		$this->assertArrayNotHasKey( 'customer', $details );
		$this->assertArrayNotHasKey( 'billing_details', $details );
	}

	/**
	 * @testdox SetupIntent Link projection does not cache provider payment-method details.
	 */
	public function test_setup_intent_link_display_projection_avoids_payment_method_details_cache(): void {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details(
			array(
				'id'              => 'pm_link_identity',
				'type'            => 'link',
				'customer'        => 'cus_must_not_be_cached',
				'billing_details' => array(
					'email' => 'must-not-be-cached@example.test',
				),
			)
		);

		$this->assertSame( 'link', $effects['payment_method_type'] );
		$this->assertSame( array( 'type' => 'link' ), $effects['payment_method_details'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_method_details', $effects['meta'] );
	}

	/**
	 * @testdox Wrapped Link card projection uses Link without retaining card details.
	 */
	public function test_setup_intent_wrapped_link_card_display_projection_uses_plain_link_title(): void {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details(
			array(
				'type' => 'card',
				'card' => array(
					'brand'  => 'visa',
					'last4'  => '4242',
					'wallet' => array( 'type' => 'link' ),
				),
			),
			'apple_pay'
		);

		$this->assertSame( '', $effects['express_checkout_type'] );
		$this->assertSame( array(), $effects['meta'] );
		$this->assertSame(
			array(
				'type' => 'card',
				'card' => array( 'wallet' => array( 'type' => 'link' ) ),
			),
			$effects['payment_method_details']
		);
	}

	/**
	 * @testdox SEPA metadata omits provider-only expected debit dates.
	 */
	public function test_display_projection_omits_non_persisted_sepa_details(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
							'payment_method_details' => array(
								'type'       => 'sepa_debit',
								'sepa_debit' => array(
									'expected_debit_date' => '2026-07-09',
									'last4'               => '3201',
								),
							),
						),
					),
				),
			)
		);
		$details = json_decode( $effects['meta']['_wcpay_payment_method_details'], true );

		$this->assertIsArray( $details );
		$this->assertSame( '3201', $details['sepa_debit']['last4'] );
		$this->assertArrayNotHasKey( 'expected_debit_date', $details['sepa_debit'] );
	}

	/**
	 * @testdox Refund projection accepts a separately rendered note without container resolution.
	 */
	public function test_refund_projection_accepts_rendered_note_separately_without_container_resolution(): void {
		$effects = WooPaymentsOrderEffects::compose_refund_effect_data(
			array(
				'id'                  => 're_native',
				'status'              => 'pending',
				'balance_transaction' => array( 'id' => 'txn_refund' ),
			),
			'Already rendered refund note.'
		);

		$this->assertSame( 'pending', $effects[ PaymentOutcome::DATA_ORDER_META ]['_wcpay_refund_status'] );
		$this->assertSame( 're_native', $effects[ PaymentOutcome::DATA_REFUND_META ]['_wcpay_refund_id'] );
		$this->assertSame( 'txn_refund', $effects[ PaymentOutcome::DATA_REFUND_META ]['_wcpay_refund_transaction_id'] );
		$this->assertSame( 'Already rendered refund note.', $effects[ PaymentOutcome::DATA_REFUND_NOTE ] );
	}
}
