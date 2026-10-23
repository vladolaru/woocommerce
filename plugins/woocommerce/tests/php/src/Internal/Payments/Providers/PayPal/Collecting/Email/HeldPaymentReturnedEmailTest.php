<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\HeldPaymentReturnedEmail;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the admin email sent when PayPal returns or denies a held payment.
 *
 * @group paypal-wallet
 */
class HeldPaymentReturnedEmailTest extends WalletTestCase {
	use CapturesMail;
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var HeldPaymentReturnedEmail
	 */
	private $sut;

	/**
	 * Build the email and catch what it sends.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->capture_mail();
		$this->sut = new HeldPaymentReturnedEmail();
	}

	/**
	 * @testdox Should be the wc_paypal_wallet_held_payment_returned email: enabled, to the admin email.
	 */
	public function test_describes_itself(): void {
		$this->assertSame( 'wc_paypal_wallet_held_payment_returned', $this->sut->id );
		$this->assertTrue( $this->sut->is_enabled() );
		$this->assertSame( get_option( 'admin_email' ), $this->sut->get_recipient() );
		$this->assertSame( 'PayPal did not release a held payment', $this->sut->get_heading() );
	}

	/**
	 * @testdox Should say PayPal returned the payment because setup was not completed in time, for a refunded capture.
	 */
	public function test_says_the_payment_was_returned(): void {
		$order       = $this->cancelled_order();
		$this->mails = array(); // Holding and cancelling the order sent core's own emails.

		$this->sut->trigger( $order, 'refunded' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'PayPal did not release the payment for order #' . $order->get_order_number(), $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'PayPal returned the payment for order #' . $order->get_order_number() . ' to the customer because PayPal Wallet setup was not completed in time. The order was cancelled and its stock restored.', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'View order', $this->mails[0]['message'] );
	}

	/**
	 * @testdox Should say PayPal denied the payment for a declined or failed capture.
	 * @testWith ["declined"]
	 *           ["failed"]
	 *
	 * @param string $reason The reason.
	 */
	public function test_says_the_payment_was_denied( string $reason ): void {
		$order       = $this->cancelled_order();
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		$this->sut->trigger( $order, $reason );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'PayPal denied the payment for order #' . $order->get_order_number() . ', so the order was cancelled and its stock restored.', $this->mails[0]['message'] );
	}

	/**
	 * @testdox Should send the plain-text variant when the email type is plain.
	 */
	public function test_sends_plain_text(): void {
		$this->set_wallet_option( 'woocommerce_wc_paypal_wallet_held_payment_returned_settings', array( 'email_type' => 'plain' ) );
		$order       = $this->cancelled_order();
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		( new HeldPaymentReturnedEmail() )->trigger( $order, 'refunded' );

		$this->assertCount( 1, $this->mails );
		$message = preg_replace( '/\s+/', ' ', $this->mails[0]['message'] ); // The plain text is wrapped at 70 columns.
		$this->assertStringNotContainsString( '<p', $message );
		$this->assertStringContainsString( 'PayPal returned the payment for order #' . $order->get_order_number() . ' to the customer because PayPal Wallet setup was not completed in time. The order was cancelled and its stock restored.', $message );
		$this->assertStringContainsString( 'View order: ', $message );
	}

	/**
	 * A held order that settlement cancelled, as it does when PayPal returns or denies the payment of an on-hold order.
	 *
	 * @return \WC_Order
	 */
	private function cancelled_order(): \WC_Order {
		$order = $this->held_order();
		$order->update_status( 'cancelled' );

		return $order;
	}

	/**
	 * @testdox Should say the order kept its status and stock when the merchant had moved it off hold: $reason.
	 * @testWith ["refunded", "PayPal returned the payment for order #%s to the customer because PayPal Wallet setup was not completed in time. The order was no longer on hold, so its status and stock were not changed."]
	 *           ["declined", "PayPal denied the payment for order #%s. The order was no longer on hold, so its status and stock were not changed."]
	 *
	 * @param string $reason   The reason.
	 * @param string $expected The sentence, with the order number as %s.
	 */
	public function test_says_the_order_kept_its_status_when_it_was_moved_off_hold( string $reason, string $expected ): void {
		$order = $this->held_order();
		$order->update_status( 'processing' );
		$this->mails = array();

		$this->sut->trigger( $order, $reason );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( sprintf( $expected, $order->get_order_number() ), $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'cancelled', $this->mails[0]['message'] );
	}

	/**
	 * @testdox Should send nothing when disabled or given something that is not an order.
	 */
	public function test_sends_nothing_when_disabled_or_without_an_order(): void {
		$this->set_wallet_option( 'woocommerce_wc_paypal_wallet_held_payment_returned_settings', array( 'enabled' => 'no' ) );
		$order       = $this->held_order();
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		( new HeldPaymentReturnedEmail() )->trigger( $order, 'refunded' );
		$this->sut->trigger( null, 'refunded' );

		$this->assertSame( array(), $this->mails );
	}
}
