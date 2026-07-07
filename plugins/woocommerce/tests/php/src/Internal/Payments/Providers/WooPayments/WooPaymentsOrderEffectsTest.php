<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

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
	 * @testdox Should derive completed charge meta from fee breakdowns and card fraud data.
	 */
	public function test_completed_charge_meta_uses_fee_breakdown_and_card_fraud_defaults(): void {
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
		$this->assertSame( 'txn_capture', $meta['_wcpay_payment_transaction_id'] );
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
