<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPersistenceProfile class.
 */
class WooPaymentsPersistenceProfileTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPersistenceProfile
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsPersistenceProfile();
	}

	/**
	 * @testdox Should preserve the WooPayments persistence vocabulary.
	 */
	public function test_preserves_woopayments_persistence_vocabulary(): void {
		$order = wc_create_order();

		$this->assertSame( 'woocommerce_payments', $this->sut->get_gateway_id() );
		$this->assertSame( 'woocommerce_payments_', $this->sut->get_gateway_id_prefix() );
		$this->assertSame( 'wcpay_processing_intent_' . $order->get_id(), $this->sut->get_order_lock_key( $order ) );
		$this->assertSame( '-1', $this->sut->get_lock_sentinel() );
		$this->assertSame( 300, $this->sut->get_lock_ttl_seconds() );
		$this->assertSame( '_wcpay_refund_id', $this->sut->get_processed_refund_link_meta_key() );
		$this->assertSame(
			array(
				'_intent_id',
				'_payment_method_id',
				'_charge_id',
				'_intention_status',
				'_charge_risk_level',
				'_stripe_customer_id',
				'_wcpay_fraud_meta_box_type',
				'_wcpay_fraud_outcome_status',
				'_wcpay_intent_currency',
				'_wcpay_refund_id',
				'_wcpay_refund_transaction_id',
				'_wcpay_refund_status',
				'_wcpay_transaction_fee',
				'_wcpay_mode',
				'_wcpay_payment_transaction_id',
				'_wcpay_multibanco_entity',
				'_wcpay_multibanco_reference',
				'_wcpay_multibanco_expiry',
				'_wcpay_multibanco_url',
				'_wcpay_payment_method_details',
				'_wcpay_ipp_channel',
				'_wcpay_net',
				'_stripe_mandate_id',
				'_wcpay_express_checkout_payment_method',
				'_wcpay_multi_currency_stripe_exchange_rate',
				'_wcpay_multi_currency_order_exchange_rate',
				'_wcpay_multi_currency_order_default_currency',
				'_wcpay_fraud_outcome_manual_entry',
				'is_woopay',
				'last4',
				'_card_brand',
			),
			$this->sut->get_preserved_payment_meta_keys()
		);
	}

	/**
	 * @testdox Should map payment outcome statuses to WooPayments intention statuses.
	 *
	 * @dataProvider outcome_status_provider
	 *
	 * @param string $outcome_status            Neutral outcome status.
	 * @param string $expected_intention_status WooPayments intention status.
	 */
	public function test_maps_outcome_statuses_to_woopayments_intention_statuses( string $outcome_status, string $expected_intention_status ): void {
		$outcome = new PaymentOutcome( $outcome_status );

		$this->assertSame(
			array( '_intention_status' => $expected_intention_status ),
			$this->sut->get_outcome_meta( $outcome )
		);
	}

	/**
	 * @testdox Should compose WooPayments outcome metadata exactly like the current lifecycle builder.
	 */
	public function test_composes_woopayments_outcome_meta_from_neutral_outcome(): void {
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_AUTHORIZED,
			'pi_123',
			'',
			'pm_123',
			'cus_123',
			array(
				'meta' => array(
					'_charge_id' => 'ch_123',
					'_wcpay_net' => 970.7,
				),
			)
		);

		$this->assertSame(
			array(
				'_charge_id'           => 'ch_123',
				'_intent_id'           => 'pi_123',
				'_intention_status'    => 'requires_capture',
				'_payment_method_id'   => 'pm_123',
				'_stripe_customer_id'  => 'cus_123',
				'_wcpay_net'           => '970.7',
			),
			$this->sut->get_outcome_meta( $outcome )
		);
	}

	/**
	 * @testdox Should preserve provider-supplied intention status metadata.
	 */
	public function test_preserves_provider_supplied_intention_status(): void {
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_123',
			'',
			'',
			'',
			array(
				'meta' => array(
					'_intention_status' => 'provider_supplied',
				),
			)
		);

		$this->assertSame(
			array(
				'_intent_id'        => 'pi_123',
				'_intention_status' => 'provider_supplied',
			),
			$this->sut->get_outcome_meta( $outcome )
		);
	}

	/**
	 * @testdox Should keep failed captures in the WooPayments requires-capture state.
	 */
	public function test_capture_failure_meta_keeps_requires_capture_status(): void {
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_123',
			'',
			'',
			'',
			array(
				'meta' => array(
					'_charge_id'        => 'ch_123',
					'_intention_status' => 'requires_payment_method',
				),
			)
		);

		$this->assertSame(
			array(
				'_charge_id'        => 'ch_123',
				'_intent_id'        => 'pi_123',
				'_intention_status' => 'requires_capture',
			),
			$this->sut->get_capture_failure_outcome_meta( $outcome )
		);
	}

	/**
	 * Data provider for neutral outcome status mappings.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function outcome_status_provider(): array {
		return array(
			'completed'                => array( PaymentOutcome::STATUS_COMPLETED, 'succeeded' ),
			'authorized'               => array( PaymentOutcome::STATUS_AUTHORIZED, 'requires_capture' ),
			'pending async'            => array( PaymentOutcome::STATUS_PENDING_ASYNC, 'processing' ),
			'requires redirect'        => array( PaymentOutcome::STATUS_REQUIRES_REDIRECT, 'requires_action' ),
			'requires customer action' => array( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, 'requires_action' ),
			'failed'                   => array( PaymentOutcome::STATUS_FAILED, 'requires_payment_method' ),
			'canceled'                 => array( PaymentOutcome::STATUS_CANCELED, 'canceled' ),
			'no external payment'      => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, 'succeeded' ),
		);
	}
}
