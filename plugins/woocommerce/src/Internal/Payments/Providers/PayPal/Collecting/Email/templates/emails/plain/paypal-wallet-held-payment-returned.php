<?php
/**
 * Admin PayPal Wallet held payment returned email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/paypal-wallet-held-payment-returned.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails\Plain
 * @version 11.3.0
 *
 * @var WC_Order $order              The order.
 * @var bool     $returned           Whether PayPal returned the payment to the customer, rather than denying it.
 * @var bool     $cancelled          Whether the order is cancelled; false when the merchant had moved it off hold, so it kept its status.
 * @var string   $email_heading      The heading.
 * @var string   $additional_content The text the merchant set below the main content.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( empty( $cancelled ) ) {
	echo sprintf(
		/* translators: %s: order number */
		$returned ? esc_html__( 'PayPal returned the payment for order #%s to the customer because PayPal Wallet setup was not completed in time. The order was no longer on hold, so its status and stock were not changed.', 'woocommerce' ) : esc_html__( 'PayPal denied the payment for order #%s. The order was no longer on hold, so its status and stock were not changed.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	) . "\n\n";
} elseif ( $returned ) {
	echo sprintf(
		/* translators: %s: order number */
		esc_html__( 'PayPal returned the payment for order #%s to the customer because PayPal Wallet setup was not completed in time. The order was cancelled and its stock restored.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	) . "\n\n";
} else {
	echo sprintf(
		/* translators: %s: order number */
		esc_html__( 'PayPal denied the payment for order #%s, so the order was cancelled and its stock restored.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	) . "\n\n";
}

echo esc_html__( 'View order', 'woocommerce' ) . ': ' . esc_url_raw( $order->get_edit_order_url() ) . "\n\n";

echo "\n----------------------------------------\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

/**
 * Filter the email footer text.
 *
 * @since 3.7.0
 */
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
