<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentCodec class.
 */
class WooPaymentsIntentCodecTest extends WC_Unit_Test_Case {

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
	 * @testdox Should pass a legacy-compatible payment type object to the metadata filter.
	 */
	public function test_metadata_from_order_passes_legacy_compatible_payment_type_object_to_filter(): void {
		$order            = $this->create_woopayments_order( '25.00' );
		$captured_payment = null;

		add_filter(
			'wcpay_metadata_from_order',
			static function ( array $metadata, WC_Order $filtered_order, $payment_type ) use ( &$captured_payment, $order ): array {
				if ( $order->get_id() === $filtered_order->get_id() ) {
					$captured_payment = $payment_type;
				}

				return $metadata;
			},
			10,
			3
		);

		$metadata = WooPaymentsIntentCodec::metadata_from_order( $order, 'recurring', 'renewal' );

		$this->assertIsObject( $captured_payment );
		$this->assertTrue( is_a( $captured_payment, 'WCPay\\Constants\\Payment_Type' ), 'Payment type should satisfy the legacy WooPayments class name.' );
		$this->assertSame( 'recurring', (string) $captured_payment );
		$this->assertSame( 'recurring', $captured_payment->get_value() );
		$this->assertTrue( $captured_payment->equals( WooPaymentsPaymentType::recurring() ) );
		$this->assertSame( $captured_payment, $metadata['payment_type'], 'Metadata should preserve the same legacy-compatible payment type object.' );
	}

	/**
	 * @testdox Should map a succeeded native payment intent to a completed outcome.
	 */
	public function test_outcome_from_intention_maps_succeeded_payment_intent_to_completed_outcome(): void {
		$order  = $this->create_woopayments_order( '50.00' );
		$result = array(
			'id'             => 'pi_native',
			'status'         => 'succeeded',
			'client_secret'  => 'secret_native',
			'customer'       => 'cus_native',
			'payment_method' => 'pm_native',
			'currency'       => 'usd',
			'charges'        => array(
				'data' => array(
					array(
						'id'                  => 'ch_native',
						'payment_method'      => 'pm_native',
						'balance_transaction' => array( 'id' => 'txn_native' ),
						'outcome'             => array( 'risk_level' => 'normal' ),
					),
				),
			),
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'payment_credential'   => 'pm_request',
				'fallback_customer_id' => 'cus_fallback',
				'account_mode'         => 'test',
				'completed_meta'       => array(
					'_wcpay_transaction_fee' => '1.75',
					'_wcpay_net'             => '48.25',
				),
			)
		);
		$data    = $outcome->get_data();
		$meta    = $data[ PaymentOutcome::DATA_META ];

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_native', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_native', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_native', $outcome->get_customer_id() );
		$this->assertSame( 'USD', $meta['_wcpay_intent_currency'] );
		$this->assertSame( 'test', $meta['_wcpay_mode'] );
		$this->assertSame( 'ch_native', $meta['_charge_id'] );
		$this->assertSame( 'txn_native', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'normal', $meta['_charge_risk_level'] );
		$this->assertSame( '1.75', $meta['_wcpay_transaction_fee'] );
		$this->assertSame( '48.25', $meta['_wcpay_net'] );
		$this->assertStringContainsString( 'successfully charged', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertStringContainsString( 'pi_native', $data[ PaymentOutcome::DATA_NOTE ] );
	}

	/**
	 * @testdox Should map asynchronous WooPayments debit intents to authorized outcomes.
	 */
	public function test_outcome_from_intention_maps_processing_payment_intent_to_authorized_outcome(): void {
		$order  = $this->create_woopayments_order( '65.00' );
		$result = array(
			'id'             => 'pi_sepa',
			'status'         => 'processing',
			'customer'       => 'cus_sepa',
			'payment_method' => 'pm_sepa',
			'currency'       => 'eur',
			'charges'        => array(
				'data' => array(
					array(
						'id'                  => 'py_sepa',
						'payment_method'      => 'pm_sepa',
						'balance_transaction' => array( 'id' => 'txn_sepa' ),
					),
				),
			),
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'account_mode' => 'test',
			)
		);
		$data    = $outcome->get_data();
		$meta    = $data[ PaymentOutcome::DATA_META ];

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'processing', $meta['_intention_status'] );
		$this->assertStringContainsString( 'authorized</strong> using WooPayments', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertSame( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED, $data[ PaymentOutcome::DATA_NOTE_TYPE ] );
	}

	/**
	 * @testdox Should build lifecycle events from WooPayments intent outcomes.
	 */
	public function test_lifecycle_event_from_intention_preserves_processing_authorization_status(): void {
		$order  = $this->create_woopayments_order( '65.00' );
		$result = array(
			'id'             => 'pi_sepa',
			'status'         => 'processing',
			'customer'       => 'cus_sepa',
			'payment_method' => 'pm_sepa',
			'currency'       => 'eur',
			'charges'        => array(
				'data' => array(
					array(
						'id'             => 'py_sepa',
						'payment_method' => 'pm_sepa',
					),
				),
			),
		);

		$event = WooPaymentsIntentCodec::lifecycle_event_from_intention(
			$result,
			$order,
			array(
				'account_mode' => 'test',
			)
		);

		$this->assertSame( PaymentLifecycleEvent::STATUS_AUTHORIZED, $event->get_status() );
		$this->assertSame( 'pi_sepa', $event->get_payment_reference() );
		$this->assertSame( 'processing', $event->get_meta_to_update()['_intention_status'] );
		$this->assertSame( '', $event->get_meta_to_update()['_wcpay_payment_transaction_id'] );
		$this->assertSame( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED, $event->get_note_type() );
	}

	/**
	 * @testdox Should build WooPayments confirmation hashes for customer-action intents.
	 */
	public function test_outcome_from_intention_builds_confirmation_redirect_for_customer_action(): void {
		$order  = $this->create_woopayments_order( '25.00' );
		$result = array(
			'id'                   => 'pi_action',
			'status'               => 'requires_action',
			'client_secret'        => 'secret_action',
			'payment_method'       => 'pm_action',
			'payment_method_types' => array( 'wechat_pay' ),
			'customer'             => 'cus_action',
			'currency'             => 'usd',
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'payment_credential'   => 'pm_request',
				'fallback_customer_id' => 'cus_fallback',
				'account_mode'         => 'live',
			)
		);

		$data = $outcome->get_data();
		$meta = $data[ PaymentOutcome::DATA_META ];

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( 'pi_action', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_action', $outcome->get_payment_method_id() );
		$this->assertStringStartsWith( '#wcpay-confirm-pi:' . $order->get_id() . ':secret_action:', $outcome->get_redirect_url() );
		$this->assertSame( 'requires_action', $meta['_intention_status'] );
		$this->assertSame( 'USD', $meta['_wcpay_intent_currency'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_method_details', $meta );
		$this->assertSame( '', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
		$this->assertStringContainsString( 'started', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertStringContainsString( 'pi_action', $data[ PaymentOutcome::DATA_NOTE ] );
		$this->assertSame( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED, $data[ PaymentOutcome::DATA_NOTE_TYPE ] );
	}

	/**
	 * @testdox Should map redirect next actions to redirect outcomes for every redirect and BNPL method family.
	 *
	 * @dataProvider redirect_payment_method_provider
	 *
	 * @param string $payment_method_type Stripe payment method type.
	 */
	public function test_outcome_from_intention_maps_redirect_next_action_to_redirect_outcome( string $payment_method_type ): void {
		$order        = $this->create_woopayments_order( '25.00' );
		$redirect_url = 'https://hooks.stripe.com/redirect/authenticate/src_' . $payment_method_type;
		$result       = array(
			'id'             => 'pi_' . $payment_method_type,
			'status'         => 'requires_action',
			'client_secret'  => 'secret_' . $payment_method_type,
			'payment_method' => array(
				'id'   => 'pm_' . $payment_method_type,
				'type' => $payment_method_type,
			),
			'customer'       => 'cus_' . $payment_method_type,
			'currency'       => 'eur',
			'next_action'    => array(
				'type'            => 'redirect_to_url',
				'redirect_to_url' => array(
					'url' => $redirect_url,
				),
			),
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'payment_credential'   => 'pm_request',
				'fallback_customer_id' => 'cus_fallback',
				'account_mode'         => 'live',
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'pi_' . $payment_method_type, $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_' . $payment_method_type, $outcome->get_payment_method_id() );
		$this->assertSame( $redirect_url, $outcome->get_redirect_url() );
		$this->assertSame( $redirect_url, $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] );
		$this->assertStringContainsString( 'started', $outcome->get_data()[ PaymentOutcome::DATA_NOTE ] );
		$this->assertStringContainsString( 'pi_' . $payment_method_type, $outcome->get_data()[ PaymentOutcome::DATA_NOTE ] );
		$this->assertSame( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED, $outcome->get_data()[ PaymentOutcome::DATA_NOTE_TYPE ] );
	}

	/**
	 * Redirect and BNPL payment method families handled by the extension's shared next-action branch.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function redirect_payment_method_provider(): array {
		return array(
			'iDEAL'             => array( 'ideal' ),
			'Bancontact'        => array( 'bancontact' ),
			'EPS'               => array( 'eps' ),
			'Przelewy24'        => array( 'p24' ),
			'Multibanco'        => array( 'multibanco' ),
			'GrabPay'           => array( 'grabpay' ),
			'WeChat Pay'        => array( 'wechat_pay' ),
			'Alipay'            => array( 'alipay' ),
			'Klarna'            => array( 'klarna' ),
			'Affirm'            => array( 'affirm' ),
			'Afterpay/Clearpay' => array( 'afterpay_clearpay' ),
		);
	}

	/**
	 * @testdox Should preserve Multibanco voucher details on redirect outcomes.
	 */
	public function test_outcome_from_intention_preserves_multibanco_voucher_details(): void {
		$order  = $this->create_woopayments_order( '25.00' );
		$result = array(
			'id'             => 'pi_multibanco',
			'status'         => 'requires_action',
			'client_secret'  => 'secret_multibanco',
			'payment_method' => array(
				'id'   => 'pm_multibanco',
				'type' => 'multibanco',
			),
			'customer'       => 'cus_multibanco',
			'currency'       => 'eur',
			'next_action'    => array(
				'type'                       => 'multibanco_display_details',
				'multibanco_display_details' => array(
					'reference'          => '123 456 789',
					'entity'             => '12345',
					'hosted_voucher_url' => 'https://pay.stripe.com/multibanco/voucher',
					'expires_at'         => 1893456000,
				),
			),
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'payment_credential'   => 'pm_request',
				'fallback_customer_id' => 'cus_fallback',
				'account_mode'         => 'live',
			)
		);
		$meta    = $outcome->get_data()[ PaymentOutcome::DATA_META ];

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( $order->get_checkout_order_received_url(), $outcome->get_redirect_url() );
		$this->assertSame( $order->get_checkout_order_received_url(), $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] );
		$this->assertSame( 'requires_action', $meta['_intention_status'] );
		$this->assertSame( 'USD', $meta['_wcpay_intent_currency'] );
		$this->assertArrayNotHasKey( '_wcpay_payment_method_details', $meta );
		$this->assertSame( '', $meta['_wcpay_payment_transaction_id'] );
		$this->assertSame( 'not_card', $meta['_wcpay_fraud_meta_box_type'] );
		$this->assertSame( '123 456 789', $meta['_wcpay_multibanco_reference'] );
		$this->assertSame( '12345', $meta['_wcpay_multibanco_entity'] );
		$this->assertSame( 'https://pay.stripe.com/multibanco/voucher', $meta['_wcpay_multibanco_url'] );
		$this->assertSame( '1893456000', $meta['_wcpay_multibanco_expiry'] );
		$this->assertStringContainsString( 'started', $outcome->get_data()[ PaymentOutcome::DATA_NOTE ] );
		$this->assertSame( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED, $outcome->get_data()[ PaymentOutcome::DATA_NOTE_TYPE ] );
	}

	/**
	 * @testdox Should use the latest charge payment method before confirmation-token credential fallback.
	 */
	public function test_outcome_from_intention_uses_charge_payment_method_before_confirmation_token_fallback(): void {
		$order  = $this->create_woopayments_order( '25.00' );
		$result = array(
			'id'            => 'pi_charge_pm',
			'status'        => 'succeeded',
			'client_secret' => 'secret_charge_pm',
			'customer'      => 'cus_charge_pm',
			'currency'      => 'usd',
			'charges'       => array(
				'data' => array(
					array(
						'id'                  => 'ch_charge_pm',
						'payment_method'      => 'pm_from_charge',
						'balance_transaction' => array( 'id' => 'txn_charge_pm' ),
					),
				),
			),
		);

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$order,
			array(
				'payment_credential'   => 'ctoken_submitted',
				'fallback_customer_id' => 'cus_fallback',
				'account_mode'         => 'live',
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pm_from_charge', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Should map legacy manual-capture results from persisted order intent meta.
	 */
	public function test_outcome_from_legacy_result_maps_manual_capture_meta_to_authorized_outcome(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->update_meta_data( '_intent_id', 'pi_manual' );
		$order->update_meta_data( '_payment_method_id', 'pm_manual' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		$outcome = WooPaymentsIntentCodec::outcome_from_legacy_result(
			array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			),
			$order
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'pi_manual', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_manual', $outcome->get_payment_method_id() );
		$this->assertSame( $order->get_checkout_order_received_url(), $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] );
	}

	/**
	 * Create a WooPayments order for codec tests.
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
