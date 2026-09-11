<?php
/**
 * WooPaymentsIppReceiptEmail class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WC_Email;
use WC_DateTime;
use WC_Order;

/**
 * Customer email sent after an in-person payment is completed.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsIppReceiptEmail extends WC_Email {

	/**
	 * Preserved WooPayments email registry key.
	 */
	public const EMAIL_CLASS_KEY = 'WC_Payments_Email_IPP_Receipt';

	/**
	 * Preserved sent marker meta key.
	 */
	private const SENT_META_KEY = '_new_receipt_email_sent';

	/**
	 * Merchant settings.
	 *
	 * @var array<string,mixed>
	 */
	public array $merchant_settings = array();

	/**
	 * Charge payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $charge = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'new_receipt';
		$this->customer_email = true;
		$this->title          = __( 'New receipt', 'woocommerce' );
		$this->description    = __( 'New receipt emails are sent to customers when a new order is paid for with a card reader.', 'woocommerce' );
		$this->email_group    = 'payments';
		$this->template_html  = 'emails/customer-ipp-receipt.php';
		$this->template_plain = 'emails/plain/customer-ipp-receipt.php';
		$this->plugin_id      = 'woocommerce_woocommerce_payments_';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	/**
	 * Register email hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'woocommerce_payments_email_ipp_receipt_store_details', array( $this, 'store_details' ), 10, 2 );
		add_action( 'woocommerce_payments_email_ipp_receipt_compliance_details', array( $this, 'compliance_details' ), 10, 2 );
		add_action( 'woocommerce_payments_email_ipp_receipt_notification', array( $this, 'trigger' ), 10, 3 );
		add_filter( 'woocommerce_email_preview_dummy_order', array( $this, 'get_preview_order' ), 10, 1 );
		add_filter( 'woocommerce_email_preview_dummy_address', array( $this, 'get_preview_address' ), 10, 1 );
		add_filter( 'woocommerce_email_preview_placeholders', array( $this, 'get_preview_placeholders' ), 10, 1 );
	}

	/**
	 * Get preview order data.
	 *
	 * @param mixed $order Dummy order.
	 * @return mixed
	 */
	public function get_preview_order( $order ) {
		if ( $order instanceof WC_Order ) {
			$order->set_payment_method_title( __( 'WooCommerce In-Person Payments', 'woocommerce' ) );
		}

		return $order;
	}

	/**
	 * Get preview address data.
	 *
	 * @param array<string,mixed> $address Address data.
	 * @return array<string,mixed>
	 */
	public function get_preview_address( array $address ): array {
		if ( ! empty( $address ) ) {
			return $address;
		}

		return array(
			'line1'       => '123 Sample Street',
			'line2'       => 'Suite 100',
			'city'        => 'Sample City',
			'state'       => 'ST',
			'postal_code' => '12345',
			'country'     => 'US',
		);
	}

	/**
	 * Get preview placeholders.
	 *
	 * @param array<string,mixed> $placeholders Placeholder values.
	 * @return array<string,mixed>
	 */
	public function get_preview_placeholders( array $placeholders ): array {
		$placeholders['{order_date}']   = wc_format_datetime( new WC_DateTime() );
		$placeholders['{order_number}'] = '42';

		return $placeholders;
	}

	/**
	 * Get default subject.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( 'Your {site_title} Receipt', 'woocommerce' );
	}

	/**
	 * Get default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( 'Your receipt for order: #{order_number}', 'woocommerce' );
	}

	/**
	 * Trigger the customer receipt email.
	 *
	 * @param WC_Order            $order             Order object.
	 * @param array<string,mixed> $merchant_settings Merchant settings.
	 * @param array<string,mixed> $charge            Charge payload.
	 * @return void
	 */
	public function trigger( WC_Order $order, array $merchant_settings, array $charge ): void {
		if ( 'mobile_pos' === $order->get_meta( '_wcpay_ipp_channel', true ) ) {
			return;
		}

		$this->setup_locale();

		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$date_created                         = $order->get_date_created();
		$this->placeholders['{order_date}']   = null === $date_created ? '' : wc_format_datetime( $date_created );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->merchant_settings              = $merchant_settings;
		$this->charge                         = $charge;

		if ( 'true' !== $order->get_meta( self::SENT_META_KEY, true ) && $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			$order->update_meta_data( self::SENT_META_KEY, 'true' );
			$order->save();
		}

		$this->restore_locale();
	}

	/**
	 * Get HTML content.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'merchant_settings'  => $this->merchant_settings,
				'charge'             => $this->charge,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			)
		);
	}

	/**
	 * Get plain text content.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'merchant_settings'  => $this->merchant_settings,
				'charge'             => $this->charge,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			)
		);
	}

	/**
	 * Output store details.
	 *
	 * @param array<string,mixed> $settings   Merchant settings.
	 * @param bool                $plain_text Whether the output is plain text.
	 * @return void
	 */
	public function store_details( array $settings, bool $plain_text ): void {
		$settings = $this->get_preview_merchant_settings( $settings );

		wc_get_template(
			$plain_text ? 'emails/plain/email-ipp-receipt-store-details.php' : 'emails/email-ipp-receipt-store-details.php',
			array(
				'business_name'   => (string) ( $settings['business_name'] ?? '' ),
				'support_address' => isset( $settings['support_info']['address'] ) && is_array( $settings['support_info']['address'] ) ? $settings['support_info']['address'] : array(),
				'support_phone'   => (string) ( $settings['support_info']['phone'] ?? '' ),
				'support_email'   => (string) ( $settings['support_info']['email'] ?? '' ),
			)
		);
	}

	/**
	 * Get preview merchant settings.
	 *
	 * @param array<string,mixed> $settings Merchant settings.
	 * @return array<string,mixed>
	 */
	public function get_preview_merchant_settings( array $settings ): array {
		if ( ! empty( $settings ) ) {
			return $settings;
		}

		return array(
			'business_name' => 'Sample Store',
			'support_info'  => array(
				'address' => array(
					'line1'       => '123 Sample Street',
					'line2'       => 'Suite 100',
					'city'        => 'Sample City',
					'state'       => 'ST',
					'postal_code' => '12345',
					'country'     => 'US',
				),
				'phone'   => '+1 (555) 123-4567',
				'email'   => 'support@samplestore.com',
			),
		);
	}

	/**
	 * Output compliance details.
	 *
	 * @param array<string,mixed> $charge     Charge payload.
	 * @param bool                $plain_text Whether the output is plain text.
	 * @return void
	 */
	public function compliance_details( array $charge, bool $plain_text ): void {
		$charge                 = $this->get_preview_charge( $charge );
		$payment_method_details = isset( $charge['payment_method_details']['card_present'] ) && is_array( $charge['payment_method_details']['card_present'] ) ? $charge['payment_method_details']['card_present'] : array();

		wc_get_template(
			$plain_text ? 'emails/plain/email-ipp-receipt-compliance-details.php' : 'emails/email-ipp-receipt-compliance-details.php',
			array(
				'payment_method_details'      => $payment_method_details,
				'payment_method_display_name' => WooPaymentsTerminalCardFormatter::get_terminal_card_display_name( $payment_method_details ),
				'receipt'                     => isset( $payment_method_details['receipt'] ) && is_array( $payment_method_details['receipt'] ) ? $payment_method_details['receipt'] : array(),
			)
		);
	}

	/**
	 * Get preview charge data.
	 *
	 * @param array<string,mixed> $charge Charge payload.
	 * @return array<string,mixed>
	 */
	public function get_preview_charge( array $charge ): array {
		if ( ! empty( $charge ) ) {
			return $charge;
		}

		return array(
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '4242',
					'receipt' => array(
						'application_preferred_name' => 'Sample App',
						'dedicated_file_name'        => 'Sample File',
						'account_type'               => 'credit',
					),
				),
			),
		);
	}

	/**
	 * Get default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content(): string {
		return __( 'Thanks for using {site_url}!', 'woocommerce' );
	}
}
