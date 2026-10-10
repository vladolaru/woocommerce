<?php
/**
 * Admin PayPal Wallet held payment returned email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/paypal-wallet-held-payment-returned.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails\HTML
 * @version 11.3.0
 *
 * @var WC_Order $order              The order.
 * @var bool     $returned           Whether PayPal returned the payment to the customer, rather than denying it.
 * @var bool     $cancelled          Whether the order is cancelled; false when the merchant had moved it off hold, so it kept its status.
 * @var string   $email_heading      The heading.
 * @var string   $additional_content The text the merchant set below the main content.
 * @var WC_Email $email              The email.
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/** This action is documented in templates/emails/admin-new-order.php */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( empty( $cancelled ) ) {
	printf(
		/* translators: %s: order number */
		$returned ? esc_html__( 'PayPal returned the payment for order #%s to the customer because PayPal Wallet setup was not completed in time. The order was no longer on hold, so its status and stock were not changed.', 'woocommerce' ) : esc_html__( 'PayPal denied the payment for order #%s. The order was no longer on hold, so its status and stock were not changed.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	);
} elseif ( $returned ) {
	printf(
		/* translators: %s: order number */
		esc_html__( 'PayPal returned the payment for order #%s to the customer because PayPal Wallet setup was not completed in time. The order was cancelled and its stock restored.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	);
} else {
	printf(
		/* translators: %s: order number */
		esc_html__( 'PayPal denied the payment for order #%s, so the order was cancelled and its stock restored.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	);
}
?>
</p>
<p><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'View order', 'woocommerce' ); ?></a></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/** This action is documented in templates/emails/admin-new-order.php */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
