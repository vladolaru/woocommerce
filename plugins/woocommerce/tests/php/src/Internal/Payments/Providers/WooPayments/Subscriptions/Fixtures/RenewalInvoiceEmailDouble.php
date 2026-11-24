<?php
/**
 * WooCommerce Subscriptions' Customer Renewal Invoice stand-in.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures;

use WC_Email;
use WC_Order;

/**
 * Stands in for WooCommerce Subscriptions 9.4.1 `WCS_Email_Customer_Renewal_Invoice`
 * (includes/core/emails/class-wcs-email-customer-renewal-invoice.php:48-117): the same two listeners and the same
 * trigger, which sends to the order's billing email only while the email is enabled. Its subject and body are short
 * test texts; the body carries the order's pay link.
 */
class RenewalInvoiceEmailDouble extends WC_Email {

	/**
	 * Set the email up and attach its two listeners, as Subscriptions does.
	 */
	public function __construct() {
		$this->id             = 'customer_renewal_invoice';
		$this->title          = 'Customer Renewal Invoice';
		$this->customer_email = true;
		$this->placeholders   = array( '{order_number}' => '' );

		add_action( 'woocommerce_generated_manual_renewal_order_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_renewal_notification', array( $this, 'trigger' ) );

		parent::__construct();
	}

	/**
	 * Get the default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return 'Invoice for renewal order {order_number}';
	}

	/**
	 * Get the default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return 'Invoice for renewal order {order_number}';
	}

	/**
	 * Send the invoice for a renewal order.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $order    Order, read by ID when not given.
	 * @return void
	 */
	public function trigger( $order_id, $order = null ): void {
		if ( $order_id && ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * Get the HTML body: the order's pay link.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return $this->object instanceof WC_Order ? 'Pay for this renewal: ' . $this->object->get_checkout_payment_url() : '';
	}

	/**
	 * Get the plain body: the order's pay link.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return $this->get_content_html();
	}
}
