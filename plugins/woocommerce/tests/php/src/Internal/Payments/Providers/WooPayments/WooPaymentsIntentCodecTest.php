<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentMappingContext;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentCodec class.
 */
class WooPaymentsIntentCodecTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Confirmation redirects use the explicitly supplied nonce.
	 */
	public function test_confirmation_redirect_uses_explicit_nonce(): void {
		$this->assertSame(
			'#wcpay-confirm-si:42:seti_secret:explicit_nonce:ctoken_123',
			WooPaymentsIntentCodec::confirmation_redirect_for( 42, 'seti_secret', 'explicit_nonce', 'si', 'ctoken_123' )
		);
	}

	/**
	 * @testdox Native intent decoding contains no order-effect data.
	 */
	public function test_outcome_from_intention_contains_no_order_effect_data(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'             => 'pi_neutral',
				'status'         => 'succeeded',
				'customer'       => 'cus_neutral',
				'payment_method' => 'pm_neutral',
				'currency'       => 'usd',
				'charges'        => array(
					'data' => array(
						array(
							'id'                  => 'ch_neutral',
							'balance_transaction' => array( 'id' => 'txn_neutral' ),
						),
					),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_neutral', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_neutral', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_neutral', $outcome->get_customer_id() );
		$this->assertSame( 'ch_neutral', $data['charge_id'] );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_ORDER_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_NOTE, $data );
	}

	/**
	 * @testdox Asynchronous debit intents map to authorized outcomes without local effects.
	 */
	public function test_outcome_from_intention_maps_processing_intent_to_authorized_outcome(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'             => 'pi_sepa',
				'status'         => 'processing',
				'customer'       => 'cus_sepa',
				'payment_method' => 'pm_sepa',
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( array(), $outcome->get_data() );
	}

	/**
	 * @testdox Customer-action intents use the prebuilt boundary redirect.
	 */
	public function test_outcome_from_intention_uses_supplied_customer_action_redirect(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                   => 'pi_action',
				'status'               => 'requires_action',
				'client_secret'        => 'secret_action',
				'payment_method'       => 'pm_action',
				'payment_method_types' => array( 'wechat_pay' ),
			),
			WooPaymentsIntentMappingContext::for_native(
				42,
				'https://example.test/order-received/42',
				'pm_request',
				'cus_fallback',
				'#wcpay-confirm-pi:42:secret_action:explicit_nonce'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( '#wcpay-confirm-pi:42:secret_action:explicit_nonce', $outcome->get_redirect_url() );
		$this->assertSame( 'cus_fallback', $outcome->get_customer_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
	}

	/**
	 * @testdox Redirect next actions map to provider redirect outcomes.
	 */
	public function test_outcome_from_intention_maps_provider_redirect(): void {
		$clean_url_calls = 0;
		$clean_url       = static function ( string $url ) use ( &$clean_url_calls ): string {
			unset( $url );
			++$clean_url_calls;

			return 'https://filtered.example/should-not-run';
		};
		add_filter( 'clean_url', $clean_url );

		try {
			$outcome = WooPaymentsIntentCodec::outcome_from_intention(
				array(
					'id'          => 'pi_ideal',
					'status'      => 'requires_action',
					'next_action' => array(
						'type'            => 'redirect_to_url',
						'redirect_to_url' => array( 'url' => 'https://hooks.stripe.com/redirect/ideal' ),
					),
				),
				WooPaymentsIntentMappingContext::for_native(
					42,
					'https://example.test/order-received/42',
					'',
					'',
					'',
					'pi',
					'https://sanitized.example/redirect/ideal'
				),
			);
		} finally {
			remove_filter( 'clean_url', $clean_url );
		}

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'https://sanitized.example/redirect/ideal', $outcome->get_redirect_url() );
		$this->assertSame( 'https://sanitized.example/redirect/ideal', $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] );
		$this->assertSame( 0, $clean_url_calls, 'The pure codec must not dispatch URL filters.' );
	}

	/**
	 * @testdox Multibanco voucher intents use the explicitly supplied order-received URL.
	 */
	public function test_outcome_from_intention_uses_supplied_order_received_url_for_multibanco(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'          => 'pi_multibanco',
				'status'      => 'requires_action',
				'next_action' => array(
					'type'                       => 'multibanco_display_details',
					'multibanco_display_details' => array( 'reference' => '123 456 789' ),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'https://example.test/order-received/42', $outcome->get_redirect_url() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
	}

	/**
	 * @testdox The latest charge payment method precedes submitted credential fallback.
	 */
	public function test_outcome_from_intention_uses_charge_payment_method_before_credential_fallback(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'      => 'pi_charge_pm',
				'status'  => 'succeeded',
				'charges' => array(
					'data' => array( array( 'payment_method' => 'pm_from_charge' ) ),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, '', 'ctoken_submitted' )
		);

		$this->assertSame( 'pm_from_charge', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Legacy mapping uses the supplied snapshot without reloading an order.
	 */
	public function test_legacy_result_uses_supplied_snapshot_without_reloading_order(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_legacy_result(
			array(
				'result'   => 'success',
				'redirect' => 'https://example.test/order-received/42',
			),
			WooPaymentsIntentMappingContext::for_legacy(
				42,
				'https://example.test/order-received/42',
				10.0,
				'pi_manual',
				'pm_manual',
				'requires_capture'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'pi_manual', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_manual', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Failed refund mapping preserves raw provider error facts.
	 */
	public function test_failed_refund_mapping_preserves_raw_error_facts(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_refund_result(
			array(
				'id'             => 're_failed',
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 're_failed', $outcome->get_provider_payment_id() );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
	}
}
