<?php
/**
 * Admin PayPal Wallet first order email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/paypal-wallet-first-order.php.
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
 * @var WC_Order $order              The first order paid with PayPal Wallet.
 * @var string   $email_heading      The heading.
 * @var string   $additional_content The text the merchant set below the main content.
 * @var string   $settings_url       Where the merchant finishes the setup.
 * @var string   $deadline           The date PayPal returns a held payment, or an empty string when none is held.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo sprintf(
	/* translators: %s: order number */
	esc_html__( 'A customer placed order #%s and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', 'woocommerce' ),
	esc_html( $order->get_order_number() )
) . "\n\n";

if ( '' !== $deadline ) {
	echo sprintf(
		/* translators: %s: date */
		esc_html__( 'PayPal returns the payment to the customer on %s if setup is not completed.', 'woocommerce' ),
		esc_html( $deadline )
	) . "\n\n";
}

echo esc_html__( 'Complete setup', 'woocommerce' ) . ': ' . esc_url_raw( $settings_url ) . "\n\n";

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
