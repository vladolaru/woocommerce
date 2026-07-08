<?php
/**
 * Plain customer IPP receipt email.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/customer-ipp-receipt.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails\Plain
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: Customer first name. */
echo sprintf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: Order number. */
echo sprintf( esc_html__( 'This is the receipt for your order #%s:', 'woocommerce' ), esc_html( $order->get_order_number() ) ) . "\n\n";

/**
 * Outputs the store details section of the IPP receipt email.
 *
 * @since 11.0.0
 */
do_action( 'woocommerce_payments_email_ipp_receipt_store_details', $merchant_settings, $plain_text );

echo "\n----------------------------------------\n\n";

/**
 * Outputs the order details section of the email.
 *
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 *
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

/**
 * Outputs the compliance details section of the IPP receipt email.
 *
 * @since 11.0.0
 */
do_action( 'woocommerce_payments_email_ipp_receipt_compliance_details', $charge, $plain_text );

echo "\n\n----------------------------------------\n\n";

/**
 * Outputs the order meta data section of the email.
 *
 * @hooked WC_Emails::order_meta() Shows order meta data.
 *
 * @since 11.0.0
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

/**
 * Outputs the footer text of the email.
 *
 * @since 11.0.0
 */
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
