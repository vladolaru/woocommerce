<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderEffects class.
 */
class WooPaymentsOrderEffectsTest extends WC_Unit_Test_Case {

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
		update_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		update_option( 'woocommerce_currency', $this->original_currency );
		parent::tearDown();
	}

	/**
	 * @testdox Should omit completed card fraud meta when the provider omits fraud outcome.
	 */
	public function test_completed_charge_meta_omits_card_fraud_meta_without_provider_fraud_outcome(): void {
		$order  = $this->create_woopayments_order( '50.00' );
		$intent = array(
			'id'       => 'pi_native',
			'currency' => 'usd',
			'metadata' => array(),
		);
		$charge = array(
			'id'                     => 'ch_native',
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

		$meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge, $order, 'usd', new WooPaymentsOrderDataService() );

		$this->assertSame( '1.75', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $meta['_wcpay_net'] );
		$this->assertSame( 'txn_native', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertArrayNotHasKey( '_wcpay_fraud_outcome_status', $meta );
		$this->assertArrayNotHasKey( '_wcpay_fraud_meta_box_type', $meta );
	}

	/**
	 * @testdox Should mark completed non-card meta without defaulting fraud outcome when the provider omits fraud outcome.
	 */
	public function test_completed_charge_meta_marks_non_card_without_defaulting_fraud_outcome(): void {
		$order  = $this->create_woopayments_order( '65.00' );
		$intent = array(
			'id'       => 'pi_ideal',
			'currency' => 'eur',
			'metadata' => array(),
		);
		$charge = array(
			'id'                     => 'ch_ideal',
			'currency'               => 'eur',
			'amount'                 => 6500,
			'application_fee_amount' => 233,
			'balance_transaction'    => array( 'id' => 'txn_ideal' ),
			'outcome'                => array( 'risk_level' => 'normal' ),
			'payment_method_details' => array(
				'type'  => 'ideal',
				'ideal' => array(
					'bank'       => 'rabobank',
					'iban_last4' => '5264',
				),
			),
			'fee_breakdown_v1'       => array(
				'totals' => array(
					'fee' => array(
						'amount'   => 233,
						'currency' => 'eur',
					),
					'net' => array(
						'amount'   => 6267,
						'currency' => 'eur',
					),
				),
			),
		);

		$meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge, $order, 'usd', new WooPaymentsOrderDataService() );

		$this->assertSame( 'txn_ideal', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertSame( '2.33', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '62.67', $meta['_wcpay_net'] );
		$this->assertArrayNotHasKey( '_wcpay_fraud_outcome_status', $meta );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Should persist completed card fraud meta when the provider sends fraud outcome.
	 */
	public function test_completed_charge_meta_persists_card_fraud_meta_from_provider_fraud_outcome(): void {
		$order  = $this->create_woopayments_order( '50.00' );
		$intent = array(
			'id'       => 'pi_native',
			'currency' => 'usd',
			'metadata' => array(
				'fraud_outcome' => 'allow',
			),
		);
		$charge = array(
			'id'                     => 'ch_native',
			'currency'               => 'usd',
			'amount'                 => 5000,
			'application_fee_amount' => 218,
			'balance_transaction'    => array( 'id' => 'txn_native' ),
			'payment_method_details' => array( 'type' => 'card' ),
		);

		$meta = WooPaymentsOrderEffects::completed_charge_meta( $intent, $charge, $order, 'usd', new WooPaymentsOrderDataService() );

		$this->assertSame( 'allow', $meta['_wcpay_fraud_outcome_status'] );
		$this->assertSame( 'allow', $meta['_wcpay_fraud_meta_box_type'] );
	}

	/**
	 * @testdox Should derive completed capture meta from the latest charge.
	 */
	public function test_completed_capture_meta_uses_latest_charge_and_account_mode(): void {
		$order  = $this->create_woopayments_order( '50.00' );
		$intent = array(
			'id'       => 'pi_capture',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                  => 'ch_old',
						'balance_transaction' => array( 'id' => 'txn_old' ),
					),
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

		$meta = WooPaymentsOrderEffects::completed_capture_meta( $intent, $order, 'live', 'usd', new WooPaymentsOrderDataService() );

		$this->assertSame( 'usd', $meta['_wcpay_intent_currency'] );
		$this->assertSame( 'live', $meta['_wcpay_mode'] );
		$this->assertSame( 'ch_capture', $meta['_charge_id'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_transaction_id', $meta );
		$this->assertSame( '1.75', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $meta['_wcpay_net'] );
	}

	/**
	 * @testdox Should format card payment method titles.
	 */
	public function test_payment_method_title_formats_card_details(): void {
		$title = WooPaymentsOrderEffects::payment_method_title(
			array(
				'type' => 'card',
				'card' => array(
					'display_brand' => 'visa',
					'funding'       => 'credit',
				),
			)
		);

		$this->assertSame( 'Visa credit card', $title );
	}

	/**
	 * @testdox Should format non-card payment method titles.
	 */
	public function test_payment_method_title_formats_non_card_details(): void {
		$title = WooPaymentsOrderEffects::payment_method_title(
			array(
				'type'  => 'ideal',
				'ideal' => array(),
			)
		);

		$this->assertSame( 'iDEAL | Wero', $title );
	}

	/**
	 * @testdox Should format country-specific non-card payment method titles.
	 */
	public function test_payment_method_title_formats_country_specific_non_card_details(): void {
		$title = WooPaymentsOrderEffects::payment_method_title(
			array(
				'type'              => 'afterpay_clearpay',
				'afterpay_clearpay' => array(),
			),
			'US'
		);

		$this->assertSame( 'Cash App Afterpay', $title );
	}

	/**
	 * @testdox Should compose payment method display effects without mutating an order.
	 */
	public function test_composes_payment_method_display_effects(): void {
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
			),
			'US',
			'GB'
		);

		$this->assertSame( 'woocommerce_payments', $effects['payment_method_id'] );
		$this->assertSame( 'Visa credit card', $effects['payment_method_title'] );
		$this->assertSame( '4242', $effects['meta']['last4'] );
		$this->assertSame( 'visa', $effects['meta']['_card_brand'] );
		$this->assertSame(
			array(
				'type' => 'card',
				'card' => array(
					'brand'         => 'visa',
					'display_brand' => 'visa',
					'funding'       => 'credit',
					'last4'         => '4242',
				),
			),
			json_decode( $effects['meta']['_wcpay_payment_method_details'], true )
		);
	}

	/**
	 * @testdox Existing express identity takes precedence over provider wallet details.
	 */
	public function test_compose_payment_method_display_details_preserves_existing_express_identity(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
							'payment_method_details' => array(
								'type' => 'card',
								'card' => array(
									'wallet' => array( 'type' => 'google_pay' ),
								),
							),
						),
					),
				),
			),
			'US',
			'',
			'apple_pay'
		);

		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $effects['payment_method_id'] );
		$this->assertSame( 'Apple Pay (WooPayments)', $effects['payment_method_title'] );
		$this->assertSame( 'apple_pay', $effects['meta']['_wcpay_express_checkout_payment_method'] );
	}

	/**
	 * @testdox Top-level Amazon Pay details produce express identity and funding-card metadata.
	 */
	public function test_compose_payment_method_display_details_detects_top_level_amazon_pay(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
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
			)
		);

		$this->assertSame( OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay', $effects['payment_method_id'] );
		$this->assertSame( 'Amazon Pay (WooPayments)', $effects['payment_method_title'] );
		$this->assertSame( 'amazon_pay', $effects['meta']['_wcpay_express_checkout_payment_method'] );
		$this->assertSame( '4242', $effects['meta']['last4'] );
		$this->assertSame( 'visa', $effects['meta']['_card_brand'] );
	}

	/**
	 * @testdox Intent payment-method options finalize display identity when no charge is available.
	 */
	public function test_compose_payment_method_display_details_falls_back_to_intent_type(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'payment_method_options' => array(
					'klarna' => array(),
				),
			),
			'US'
		);

		$this->assertSame( OrderPaymentStore::GATEWAY_ID_PREFIX . 'klarna', $effects['payment_method_id'] );
		$this->assertSame( 'Klarna', $effects['payment_method_title'] );
		$this->assertSame( array(), $effects['meta'] );
	}

	/**
	 * @testdox Should compose country-specific payment method titles.
	 */
	public function test_compose_payment_method_display_details_uses_country_specific_titles(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
							'payment_method_details' => array(
								'type'              => 'afterpay_clearpay',
								'afterpay_clearpay' => array(
									'order_id' => 'afterpay_order_123',
								),
							),
						),
					),
				),
			),
			'US'
		);

		$this->assertSame( 'woocommerce_payments_afterpay_clearpay', $effects['payment_method_id'] );
		$this->assertSame( 'Cash App Afterpay', $effects['payment_method_title'] );
	}

	/**
	 * @testdox Should omit non-persisted SEPA debit details from payment method meta.
	 */
	public function test_compose_payment_method_display_details_omits_non_persisted_sepa_debit_details(): void {
		$effects = WooPaymentsOrderEffects::compose_payment_method_display_details(
			array(
				'charges' => array(
					'data' => array(
						array(
							'payment_method_details' => array(
								'type'       => 'sepa_debit',
								'sepa_debit' => array(
									'bank_code'           => '19043',
									'branch_code'         => '',
									'country'             => 'AT',
									'expected_debit_date' => '2026-07-09',
									'fingerprint'         => 'fingerprint_123',
									'last4'               => '3201',
									'mandate'             => 'mandate_123',
								),
							),
						),
					),
				),
			)
		);

		$payment_method_details = json_decode( $effects['meta']['_wcpay_payment_method_details'], true );

		$this->assertIsArray( $payment_method_details );
		$this->assertSame( 'sepa_debit', $payment_method_details['type'] );
		$this->assertSame( '3201', $payment_method_details['sepa_debit']['last4'] );
		$this->assertArrayNotHasKey( 'expected_debit_date', $payment_method_details['sepa_debit'] );
	}

	/**
	 * @testdox Should build capture success notes.
	 */
	public function test_capture_success_note_includes_transaction_details(): void {
		$order = $this->create_woopayments_order( '25.00' );

		$note = WooPaymentsOrderEffects::capture_success_note( $order, 'pi_capture', 'ch_capture', 'txn_capture' );

		$this->assertStringContainsString( 'successfully captured', $note );
		$this->assertStringContainsString( 'WooPayments', $note );
		$this->assertStringContainsString( 'pi_capture', $note );
	}

	/**
	 * @testdox Test-mode payment success notes preserve the canonical WooPayments charge copy and transaction URL.
	 */
	public function test_test_mode_payment_success_note_matches_reference_shape(): void {
		$order           = $this->create_woopayments_order( '25.00' );
		$transaction_url = WooPaymentsOrderEffects::transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' );

		$note = WooPaymentsOrderEffects::payment_success_note( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge', 'test' );

		$this->assertSame(
			sprintf(
				'A payment of %1$s USD was <strong>successfully charged</strong> using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).',
				wc_price( 25.00, array( 'currency' => 'USD' ) ),
				$transaction_url
			),
			$note
		);
		$this->assertStringNotContainsString( '0.000000payments', $note );
	}

	/**
	 * @testdox Successful refund notes match the WooPayments reference HTML and explicit-currency behavior.
	 */
	public function test_refund_note_matches_reference_shape_with_explicit_currency(): void {
		$order    = $this->create_woopayments_order( '50.00' );
		$context  = PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 4.25, 'Requested by customer' );
		$previous = get_option( 'wcpay_multi_currency_enabled_currencies', false );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		try {
			$note = WooPaymentsOrderEffects::refund_note( $context, 're_native', false );
		} finally {
			false === $previous
				? delete_option( 'wcpay_multi_currency_enabled_currencies' )
				: update_option( 'wcpay_multi_currency_enabled_currencies', $previous );
		}

		$this->assertSame(
			sprintf(
				'A refund of %s USD was successfully processed using WooPayments. Reason: Requested by customer. (<code>re_native</code>)',
				wc_price( 4.25, array( 'currency' => 'USD' ) )
			),
			$note
		);
	}

	/**
	 * Create a WooPayments order for effects tests.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
