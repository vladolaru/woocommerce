<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOutcomeMetadataMapper class.
 */
class WooPaymentsOutcomeMetadataMapperTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOutcomeMetadataMapper
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsOutcomeMetadataMapper();
	}

	/**
	 * @testdox Should map payment outcome statuses to WooPayments intention statuses, a failure's only with the intent it names: $_dataName.
	 *
	 * Client 11.3.0's checkout catch stores the intent a decline names and writes no intent status
	 * (class-wc-payment-gateway-wcpay.php:1604-1614), so a failure that names no intent maps no intention status.
	 *
	 * @dataProvider outcome_status_provider
	 *
	 * @param string               $outcome_status Neutral outcome status.
	 * @param string               $payment_id     Intent the outcome names, or ''.
	 * @param array<string,string> $expected_meta  WooPayments order meta.
	 */
	public function test_maps_outcome_statuses_to_woopayments_intention_statuses( string $outcome_status, string $payment_id, array $expected_meta ): void {
		$outcome = new PaymentOutcome( $outcome_status, $payment_id );

		$this->assertSame( $expected_meta, $this->sut->get_outcome_meta( $outcome ) );
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
					'_charge_id'          => 'ch_123',
					'_intent_id'          => 'pi_123',
					'_intention_status'   => 'requires_capture',
					'_payment_method_id'  => 'pm_123',
					'_stripe_customer_id' => 'cus_123',
					'_wcpay_net'          => '970.7',
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
	 * @testdox Should keep a failed capture or cancel whose effects composed no intention status in the requires-capture state.
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
					'_charge_id' => 'ch_123',
				),
			)
		);

		$this->assertSame(
			array(
				'_charge_id'        => 'ch_123',
				'_intent_id'        => 'pi_123',
				'_intention_status' => 'requires_capture',
			),
			$this->sut->get_failed_capture_or_cancel_outcome_meta( $outcome )
		);
	}

	/**
	 * Data provider for neutral outcome status mappings.
	 *
	 * @return array<string,array{string,string,array<string,string>}>
	 */
	public function outcome_status_provider(): array {
		return array(
			'completed'                => array( PaymentOutcome::STATUS_COMPLETED, '', array( '_intention_status' => 'succeeded' ) ),
			'authorized'               => array( PaymentOutcome::STATUS_AUTHORIZED, '', array( '_intention_status' => 'requires_capture' ) ),
			'pending async'            => array( PaymentOutcome::STATUS_PENDING_ASYNC, '', array( '_intention_status' => 'processing' ) ),
			'requires redirect'        => array( PaymentOutcome::STATUS_REQUIRES_REDIRECT, '', array( '_intention_status' => 'requires_action' ) ),
			'requires customer action' => array( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, '', array( '_intention_status' => 'requires_action' ) ),
			'failed, naming an intent' => array(
				PaymentOutcome::STATUS_FAILED,
				'pi_123',
				array(
					'_intent_id'        => 'pi_123',
					'_intention_status' => 'requires_payment_method',
				),
			),
			'failed, naming no intent' => array( PaymentOutcome::STATUS_FAILED, '', array() ),
			'canceled'                 => array( PaymentOutcome::STATUS_CANCELED, '', array( '_intention_status' => 'canceled' ) ),
			'no external payment'      => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, '', array( '_intention_status' => 'succeeded' ) ),
		);
	}
}
