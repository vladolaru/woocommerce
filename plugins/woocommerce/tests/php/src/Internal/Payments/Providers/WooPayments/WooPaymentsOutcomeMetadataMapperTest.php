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
	 * @testdox A failed outcome that names no intent writes no intention status, so the stored status still describes the stored intent.
	 *
	 * Client 11.3.0's checkout catch writes no status (class-wc-payment-gateway-wcpay.php:1552-1733).
	 */
	public function test_failure_naming_no_intent_writes_no_intention_status(): void {
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array( PaymentOutcome::DATA_ERROR_CODE => 'wcpay_gateway_unavailable' ) );

		$this->assertSame( array(), $this->sut->get_outcome_meta( $outcome ) );
	}

	/**
	 * @testdox A failed outcome naming its intent still writes requires_payment_method beside it.
	 */
	public function test_failure_naming_its_intent_writes_requires_payment_method(): void {
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_declined' );

		$this->assertSame(
			array(
				'_intent_id'        => 'pi_declined',
				'_intention_status' => 'requires_payment_method',
			),
			$this->sut->get_outcome_meta( $outcome )
		);
	}

	/**
	 * @testdox A failure naming no intent still writes the status it sets itself, and a failed capture naming none stays requires_capture.
	 */
	public function test_failure_naming_no_intent_keeps_an_explicit_status(): void {
		$blocked = new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array( 'meta' => array( '_intention_status' => 'canceled' ) ) );
		$capture = new PaymentOutcome( PaymentOutcome::STATUS_FAILED );

		$this->assertSame( array( '_intention_status' => 'canceled' ), $this->sut->get_outcome_meta( $blocked ) );
		$this->assertSame( array( '_intention_status' => 'requires_capture' ), $this->sut->get_failed_capture_or_cancel_outcome_meta( $capture ) );
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
			'canceled'                 => array( PaymentOutcome::STATUS_CANCELED, 'canceled' ),
			'no external payment'      => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, 'succeeded' ),
		);
	}
}
