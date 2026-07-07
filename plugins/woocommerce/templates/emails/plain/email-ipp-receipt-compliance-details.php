<?php
/**
 * Plain IPP receipt compliance details.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/email-ipp-receipt-compliance-details.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails\Plain
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

$payment_method_display_name = $payment_method_display_name ?? '';

echo esc_html( sprintf( "%s:\t%s", __( 'Payment Method', 'woocommerce' ), sprintf( '%s - %s', $payment_method_display_name, $payment_method_details['last4'] ?? '' ) ) ) . "\n";

echo esc_html( sprintf( "%s:\t%s", __( 'Application Name', 'woocommerce' ), ucfirst( (string) ( $receipt['application_preferred_name'] ?? '' ) ) ) ) . "\n";

echo esc_html( sprintf( "%s:\t%s", __( 'AID', 'woocommerce' ), ucfirst( (string) ( $receipt['dedicated_file_name'] ?? '' ) ) ) ) . "\n";

echo esc_html( sprintf( "%s:\t%s", __( 'Account Type', 'woocommerce' ), ucfirst( (string) ( $receipt['account_type'] ?? '' ) ) ) ) . "\n";
