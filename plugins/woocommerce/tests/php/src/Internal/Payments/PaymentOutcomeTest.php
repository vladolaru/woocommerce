<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentOutcome class.
 */
class PaymentOutcomeTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Only completed, authorized and no-external-payment outcomes are successful ($_dataName).
	 *
	 * @dataProvider status_cases
	 *
	 * @param string $status     Outcome status.
	 * @param bool   $successful Whether the status counts as successful.
	 */
	public function test_successful_statuses_are_explicit( string $status, bool $successful ): void {
		$this->assertSame( $successful, ( new PaymentOutcome( $status ) )->is_successful() );
	}

	/**
	 * Every outcome status, with whether it counts as successful.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function status_cases(): array {
		return array(
			'completed'                => array( PaymentOutcome::STATUS_COMPLETED, true ),
			'authorized'               => array( PaymentOutcome::STATUS_AUTHORIZED, true ),
			'no external payment'      => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, true ),
			'pending async'            => array( PaymentOutcome::STATUS_PENDING_ASYNC, false ),
			'requires redirect'        => array( PaymentOutcome::STATUS_REQUIRES_REDIRECT, false ),
			'requires customer action' => array( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, false ),
			'failed'                   => array( PaymentOutcome::STATUS_FAILED, false ),
			'canceled'                 => array( PaymentOutcome::STATUS_CANCELED, false ),
		);
	}

	/**
	 * @testdox with_effect_plan() returns a copy that holds the plan and leaves the original outcome unchanged.
	 */
	public function test_with_effect_plan_returns_a_copy_that_holds_the_plan(): void {
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_effect_plan',
			'https://example.com/return',
			'pm_effect_plan',
			'cus_effect_plan',
			array( 'meta' => array( '_charge_id' => 'ch_effect_plan' ) )
		);
		$plan    = new class() {
		};

		$planned_outcome = $outcome->with_effect_plan( $plan );

		$this->assertNotSame( $outcome, $planned_outcome );
		$this->assertNull( $outcome->get_effect_plan() );
		$this->assertSame( $plan, $planned_outcome->get_effect_plan() );
		$this->assertSame( $outcome->get_status(), $planned_outcome->get_status() );
		$this->assertSame( $outcome->get_provider_payment_id(), $planned_outcome->get_provider_payment_id() );
		$this->assertSame( $outcome->get_redirect_url(), $planned_outcome->get_redirect_url() );
		$this->assertSame( $outcome->get_payment_method_id(), $planned_outcome->get_payment_method_id() );
		$this->assertSame( $outcome->get_customer_id(), $planned_outcome->get_customer_id() );
		$this->assertSame( $outcome->get_data(), $planned_outcome->get_data() );
	}

	/**
	 * @testdox Unknown statuses fail closed.
	 */
	public function test_unknown_statuses_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );

		new PaymentOutcome( 'unknown-status' );
	}
}
