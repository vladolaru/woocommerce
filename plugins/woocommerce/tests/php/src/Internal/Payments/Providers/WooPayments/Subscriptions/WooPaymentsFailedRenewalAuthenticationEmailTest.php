<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedRenewalAuthenticationEmail;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFailedRenewalAuthenticationEmail class.
 */
class WooPaymentsFailedRenewalAuthenticationEmailTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should make the retry rule's admin email class resolvable by its extension-era global name.
	 */
	public function test_set_store_owner_custom_email_makes_named_class_resolvable(): void {
		// The retry flow runs with the mailer initialized; the email parent classes load with it.
		WC()->mailer();
		$order = wc_create_order();
		$email = new WooPaymentsFailedRenewalAuthenticationEmail();
		// The filter only applies to the email's current order.
		$email->object = $order;

		$rule = $email->set_store_owner_custom_email(
			array( 'email_template_admin' => 'WCS_Email_Payment_Retry' ),
			1,
			$order->get_id()
		);

		$this->assertSame( 'WC_Payments_Email_Failed_Authentication_Retry', $rule['email_template_admin'] );
		$this->assertTrue(
			class_exists( $rule['email_template_admin'] ),
			'Subscriptions instantiates the retry email by this global name; an unresolvable class silently skips the email.'
		);
		$this->assertInstanceOf( WooPaymentsFailedAuthenticationRetryEmail::class, new \WC_Payments_Email_Failed_Authentication_Retry() );
	}

	/**
	 * @testdox Should leave the retry rule alone for an unrelated order.
	 */
	public function test_set_store_owner_custom_email_ignores_unrelated_orders(): void {
		$order = wc_create_order();
		$email = new WooPaymentsFailedRenewalAuthenticationEmail();
		$email->object = $order;

		$rule = $email->set_store_owner_custom_email(
			array( 'email_template_admin' => 'WCS_Email_Payment_Retry' ),
			1,
			$order->get_id() + 1
		);

		$this->assertSame( 'WCS_Email_Payment_Retry', $rule['email_template_admin'] );
	}
}
