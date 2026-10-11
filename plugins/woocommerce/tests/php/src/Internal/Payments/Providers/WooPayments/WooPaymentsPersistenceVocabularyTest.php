<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPersistenceVocabulary class.
 */
class WooPaymentsPersistenceVocabularyTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * @testdox Should preserve the WooPayments persistence vocabulary.
	 */
	public function test_preserves_woopayments_persistence_vocabulary(): void {
		$order = wc_create_order();

		$this->assertSame( 'woocommerce_payments', WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$this->assertSame( 'woocommerce_payments_', WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX );
		$this->assertSame( 'wcpay_processing_intent_' . $order->get_id(), $this->sut->get_order_lock_key( $order ) );
		$this->assertSame( '-1', $this->sut->get_lock_sentinel() );
		$this->assertSame( 300, $this->sut->get_lock_ttl_seconds() );
		$this->assertSame( '_wcpay_refund_id', $this->sut->get_processed_refund_link_meta_key() );
		$this->assertSame( '_wcpay_early_fraud_warning', WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_META_KEY );
		$this->assertSame( '_wc_woopayments_note_identity', $this->sut->get_note_identity_meta_key() );
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
				'_wcpay_fraud_ruleset_results',
				'_wcpay_early_fraud_warning',
				'_wcpay_intent_currency',
				'_wcpay_open_dispute_ids',
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
	 * @testdox Should tell that '$gateway_id' is a WooPayments gateway ID: $expected.
	 * @testWith ["woocommerce_payments", true]
	 *           ["woocommerce_payments_bancontact", true]
	 *           ["bacs", false]
	 *           ["woocommerce_paymentsx", false]
	 *           ["stripe_woocommerce_payments", false]
	 *           ["", false]
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param bool   $expected   Whether it is a WooPayments gateway ID.
	 */
	public function test_tells_woopayments_gateway_ids_apart( string $gateway_id, bool $expected ): void {
		$this->assertSame( $expected, WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $gateway_id ) );
	}
}
