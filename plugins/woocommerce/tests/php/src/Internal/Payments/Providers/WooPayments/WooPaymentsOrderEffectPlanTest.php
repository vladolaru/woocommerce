<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderEffectPlan class.
 */
class WooPaymentsOrderEffectPlanTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Customer-action PaymentIntent plans do not apply token effects before authorization.
	 */
	public function test_customer_action_payment_intent_plan_defers_token_effects(): void {
		$plan = WooPaymentsOrderEffectPlan::for_payment_intent(
			array(
				'id'     => 'pi_action',
				'status' => 'requires_action',
			),
			false
		);

		$this->assertFalse( $plan->should_apply_token_effects() );
		$this->assertFalse( $plan->is_recurring() );
	}
}
