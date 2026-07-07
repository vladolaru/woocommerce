<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIppReceiptEmail class.
 */
class WooPaymentsIppReceiptEmailTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_store_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_compliance_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_notification' );
		remove_all_filters( 'woocommerce_email_preview_dummy_order' );
		remove_all_filters( 'woocommerce_email_preview_dummy_address' );
		remove_all_filters( 'woocommerce_email_preview_placeholders' );
		delete_option( 'woocommerce_woocommerce_payments_new_receipt_settings' );
		parent::tearDown();
	}

	/**
	 * @testdox Receipt email trigger sends once and records the preserved sent marker.
	 */
	public function test_trigger_sends_once_and_records_sent_marker(): void {
		$email = $this->create_recording_email();
		$email->init_hooks();
		$order = $this->create_receipt_order();

		$email->trigger( $order, $this->get_merchant_settings(), $this->get_card_present_charge() );
		$email->trigger( $order, $this->get_merchant_settings(), $this->get_card_present_charge() );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $email->send_count, 'The receipt email should not be sent again after the preserved sent marker is written.' );
		$this->assertSame( 'true', $order->get_meta( '_new_receipt_email_sent', true ) );
	}

	/**
	 * @testdox Receipt email renders HTML content with extension-compatible receipt sections.
	 */
	public function test_receipt_email_renders_extension_compatible_html_content(): void {
		$email = $this->create_prepared_email();

		$html = $email->get_content_html();

		$this->assertStringContainsString( 'Hi Ada,', $html );
		$this->assertStringContainsString( 'This is the receipt for your order #', $html );
		$this->assertStringContainsString( 'Store Details', $html );
		$this->assertStringContainsString( 'Reader Store', $html );
		$this->assertStringContainsString( '123 Sample Street', $html );
		$this->assertStringContainsString( 'support@example.test', $html );
		$this->assertStringContainsString( 'Payment Method', $html );
		$this->assertStringContainsString( 'Visa - 4242', $html );
		$this->assertStringContainsString( 'Application Name', $html );
		$this->assertStringContainsString( 'Visa credit', $html );
		$this->assertStringContainsString( 'AID', $html );
		$this->assertStringContainsString( 'A0000000031010', $html );
		$this->assertStringContainsString( 'Account Type', $html );
		$this->assertStringContainsString( 'Credit', $html );
	}

	/**
	 * @testdox Receipt email renders plain text content with extension-compatible receipt sections.
	 */
	public function test_receipt_email_renders_extension_compatible_plain_content(): void {
		$email = $this->create_prepared_email();

		$plain = $email->get_content_plain();

		$this->assertStringContainsString( 'Hi Ada,', $plain );
		$this->assertStringContainsString( 'This is the receipt for your order #', $plain );
		$this->assertStringContainsString( 'Reader Store', $plain );
		$this->assertStringContainsString( '123 Sample Street', $plain );
		$this->assertStringContainsString( 'support@example.test', $plain );
		$this->assertStringContainsString( 'Payment Method:', $plain );
		$this->assertStringContainsString( 'Visa - 4242', $plain );
		$this->assertStringContainsString( 'Application Name:', $plain );
		$this->assertStringContainsString( 'Visa credit', $plain );
		$this->assertStringContainsString( 'AID:', $plain );
		$this->assertStringContainsString( 'A0000000031010', $plain );
		$this->assertStringContainsString( 'Account Type:', $plain );
		$this->assertStringContainsString( 'Credit', $plain );
	}

	/**
	 * Create an email test double that records sends.
	 *
	 * @return object
	 */
	private function create_recording_email(): object {
		$this->assertTrue( class_exists( WooPaymentsIppReceiptEmail::class ), 'Native IPP receipt email class should exist.' );

		return new class() extends WooPaymentsIppReceiptEmail {
			/**
			 * Number of send attempts.
			 *
			 * @var int
			 */
			public int $send_count = 0;

			/**
			 * Record the send without invoking wp_mail.
			 *
			 * @param string $to          Recipient.
			 * @param string $subject     Subject.
			 * @param string $message     Message.
			 * @param string $headers     Headers.
			 * @param array  $attachments Attachments.
			 * @return bool
			 */
			public function send( $to, $subject, $message, $headers, $attachments ) {
				unset( $to, $subject, $message, $headers, $attachments );
				++$this->send_count;
				return true;
			}
		};
	}

	/**
	 * Create an email instance prepared for content rendering.
	 *
	 * @return object
	 */
	private function create_prepared_email(): object {
		$email = $this->create_recording_email();
		$email->init_hooks();
		$email->trigger( $this->create_receipt_order(), $this->get_merchant_settings(), $this->get_card_present_charge() );

		return $email;
	}

	/**
	 * Create an order for receipt email tests.
	 *
	 * @return WC_Order
	 */
	private function create_receipt_order(): WC_Order {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_email( 'ada@example.test' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Get merchant receipt settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_merchant_settings(): array {
		return array(
			'business_name' => 'Reader Store',
			'support_info'  => array(
				'address' => array(
					'line1'       => '123 Sample Street',
					'line2'       => 'Suite 100',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94107',
					'country'     => 'US',
				),
				'phone'   => '+1 555 0100',
				'email'   => 'support@example.test',
			),
		);
	}

	/**
	 * Get a card-present charge fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function get_card_present_charge(): array {
		return array(
			'id'                     => 'ch_card_present',
			'amount_captured'        => 1000,
			'currency'               => 'usd',
			'payment_method_details' => array(
				'type'         => 'card_present',
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '4242',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);
	}
}
