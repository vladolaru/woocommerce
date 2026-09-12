<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTestModeOrderEmailService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTestModeOrderEmailService class.
 */
class WooPaymentsTestModeOrderEmailServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->email_ids() as $email_id ) {
			remove_all_filters( "woocommerce_email_subject_{$email_id}" );
			remove_all_filters( "woocommerce_email_heading_{$email_id}" );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Test-mode orders receive subject and heading markers through the registered email filters.
	 */
	public function test_marks_test_mode_order_email_subjects_and_headings_through_wordpress_filters(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		$sut = new WooPaymentsTestModeOrderEmailService();

		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register();

		foreach ( $this->email_ids() as $email_id ) {
			$this->assertSame( 10, has_filter( "woocommerce_email_subject_{$email_id}", array( $sut, 'handle_email_subject' ) ) );
			$this->assertSame( 10, has_filter( "woocommerce_email_heading_{$email_id}", array( $sut, 'handle_email_heading' ) ) );
			$this->assertSame( '[Test] Subject', apply_filters( "woocommerce_email_subject_{$email_id}", 'Subject', $order ) );
			$this->assertSame( '[Test] Heading', apply_filters( "woocommerce_email_heading_{$email_id}", 'Heading', $order ) );
		}
	}

	/**
	 * @testdox Email values remain unchanged for live, legacy, malformed, and non-order filter inputs.
	 */
	public function test_leaves_ineligible_email_values_unchanged(): void {
		$live_order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $live_order );
		$live_order->update_meta_data( '_wcpay_mode', 'live' );
		$live_order->save();
		$sut = new WooPaymentsTestModeOrderEmailService();

		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register();

		$legacy_order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $legacy_order );

		$this->assertSame( 'Subject', apply_filters( 'woocommerce_email_subject_new_order', 'Subject', $live_order ) );
		$this->assertSame( 'Subject', apply_filters( 'woocommerce_email_subject_new_order', 'Subject', $legacy_order ) );
		$this->assertSame( 'Subject', apply_filters( 'woocommerce_email_subject_new_order', 'Subject', null ) );
		$this->assertSame( array( 'subject' ), apply_filters( 'woocommerce_email_subject_new_order', array( 'subject' ), $live_order ) );
	}

	/**
	 * @testdox Email filters stay unregistered while the native runtime is not the owner.
	 */
	public function test_does_not_register_email_filters_when_native_does_not_own_runtime(): void {
		$sut = new WooPaymentsTestModeOrderEmailService();

		$sut->init( new StaticNativeRuntimeArbiter( false ) );
		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_email_subject_new_order', array( $sut, 'handle_email_subject' ) ) );
	}

	/**
	 * Get the order-email filter suffixes covered by the service.
	 *
	 * @return string[]
	 */
	private function email_ids(): array {
		return array(
			'new_order',
			'failed_order',
			'cancelled_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_on_hold_order',
			'customer_invoice',
			'customer_invoice_paid',
		);
	}
}
