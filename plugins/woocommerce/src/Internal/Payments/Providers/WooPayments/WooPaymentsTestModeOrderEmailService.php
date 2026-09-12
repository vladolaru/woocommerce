<?php
/**
 * WooPaymentsTestModeOrderEmailService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Marks WooPayments test-mode order emails using the persisted payment mode.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTestModeOrderEmailService implements RegisterHooksInterface {

	/**
	 * WooCommerce order email suffixes that receive a test-mode marker.
	 *
	 * @var string[]
	 */
	private const ORDER_EMAIL_IDS = array(
		'new_order',
		'failed_order',
		'cancelled_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
		'customer_invoice_paid',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register test-mode order email filters.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		foreach ( self::ORDER_EMAIL_IDS as $email_id ) {
			$subject_hook = "woocommerce_email_subject_{$email_id}";
			$heading_hook = "woocommerce_email_heading_{$email_id}";
			if ( false === has_filter( $subject_hook, array( $this, 'handle_email_subject' ) ) ) {
				add_filter( $subject_hook, array( $this, 'handle_email_subject' ), 10, 2 );
			}
			if ( false === has_filter( $heading_hook, array( $this, 'handle_email_heading' ) ) ) {
				add_filter( $heading_hook, array( $this, 'handle_email_heading' ), 10, 2 );
			}
		}
	}

	/**
	 * Handle a WooCommerce order email subject filter.
	 *
	 * @internal
	 *
	 * @param mixed $subject Filtered email subject.
	 * @param mixed $order   Order for the email.
	 * @return mixed
	 */
	public function handle_email_subject( $subject, $order ) {
		return $this->maybe_prepend_test_mode_marker( $subject, $order );
	}

	/**
	 * Handle a WooCommerce order email heading filter.
	 *
	 * @internal
	 *
	 * @param mixed $heading Filtered email heading.
	 * @param mixed $order   Order for the email.
	 * @return mixed
	 */
	public function handle_email_heading( $heading, $order ) {
		return $this->maybe_prepend_test_mode_marker( $heading, $order );
	}

	/**
	 * Prepend a marker when a filter concerns a persisted test-mode order.
	 *
	 * @param mixed $text  Filtered email subject or heading.
	 * @param mixed $order Order for the email.
	 * @return mixed
	 */
	private function maybe_prepend_test_mode_marker( $text, $order ) {
		if ( ! is_string( $text ) || ! $order instanceof WC_Order || 'test' !== $order->get_meta( '_wcpay_mode', true ) ) {
			return $text;
		}

		/* translators: %s: The original order email subject or heading. */
		return sprintf( __( '[Test] %s', 'woocommerce' ), $text );
	}
}
