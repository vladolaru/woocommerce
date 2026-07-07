<?php
/**
 * Plain IPP receipt store details.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/email-ipp-receipt-store-details.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails\Plain
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

echo "==========\n\n";

echo esc_html( $business_name ) . "\n\n";

echo "==========\n\n";

if ( ! empty( $support_address ) && is_array( $support_address ) ) {
	echo esc_html( (string) ( $support_address['line1'] ?? '' ) ) . "\n";
	echo esc_html( (string) ( $support_address['line2'] ?? '' ) ) . "\n";
	echo esc_html( implode( ' ', array( (string) ( $support_address['city'] ?? '' ), (string) ( $support_address['state'] ?? '' ), (string) ( $support_address['postal_code'] ?? '' ), (string) ( $support_address['country'] ?? '' ) ) ) ) . "\n";
	echo esc_html( trim( implode( ' ', array( $support_phone, $support_email ) ) ) ) . "\n";
	echo esc_html( gmdate( 'Y-m-d H:iA' ) ) . "\n";
}
