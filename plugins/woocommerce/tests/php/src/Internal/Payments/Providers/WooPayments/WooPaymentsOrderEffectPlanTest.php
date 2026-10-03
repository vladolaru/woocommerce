<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderEffectPlan class.
 */
class WooPaymentsOrderEffectPlanTest extends WC_Unit_Test_Case {

	/**
	 * @testdox PaymentIntent plans preserve provider facts and authorized token eligibility.
	 */
	public function test_payment_intent_plan_preserves_provider_facts(): void {
		$result = array(
			'id'     => 'pi_plan',
			'status' => 'succeeded',
		);

		$plan = WooPaymentsOrderEffectPlan::for_payment_intent( $result, true );

		$this->assertInstanceOf( ProviderOperationEffectPlan::class, $plan );
		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, $plan->get_type() );
		$this->assertSame( $result, $plan->get_provider_result() );
		$this->assertTrue( $plan->should_apply_token_effects() );
		$this->assertTrue( $plan->is_recurring() );
	}

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

	/**
	 * @testdox Successful SetupIntent plans apply token effects and retain setup metadata.
	 */
	public function test_setup_intent_plan_preserves_setup_metadata(): void {
		$result = array(
			'id'             => 'seti_plan',
			'status'         => 'succeeded',
			'payment_method' => 'pm_plan',
		);
		$meta   = array(
			'_wcpay_intent_currency' => 'USD',
			'_wcpay_mode'            => 'test',
		);

		$plan = WooPaymentsOrderEffectPlan::for_setup_intent( $result, true, $meta );

		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT, $plan->get_type() );
		$this->assertSame( $result, $plan->get_provider_result() );
		$this->assertSame( $meta, $plan->get_setup_meta() );
		$this->assertTrue( $plan->should_apply_token_effects() );
		$this->assertTrue( $plan->is_recurring() );
	}

	/**
	 * @testdox Capture plans carry provider facts without token or setup effects.
	 */
	public function test_capture_plan_carries_only_capture_facts(): void {
		$result = array(
			'id'     => 'pi_capture_plan',
			'status' => 'succeeded',
		);

		$plan = WooPaymentsOrderEffectPlan::for_capture( $result );

		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_CAPTURE, $plan->get_type() );
		$this->assertSame( $result, $plan->get_provider_result() );
		$this->assertFalse( $plan->should_apply_token_effects() );
		$this->assertFalse( $plan->is_recurring() );
		$this->assertSame( array(), $plan->get_setup_meta() );
	}

	/**
	 * @testdox Refund plans carry provider facts without token or setup effects.
	 */
	public function test_refund_plan_carries_only_refund_facts(): void {
		$result = array(
			'id'     => 're_plan',
			'status' => 'pending',
		);

		$plan = WooPaymentsOrderEffectPlan::for_refund( $result );

		$this->assertSame( WooPaymentsOrderEffectPlan::TYPE_REFUND, $plan->get_type() );
		$this->assertSame( $result, $plan->get_provider_result() );
		$this->assertFalse( $plan->should_apply_token_effects() );
		$this->assertFalse( $plan->is_recurring() );
		$this->assertSame( array(), $plan->get_setup_meta() );
	}
}
