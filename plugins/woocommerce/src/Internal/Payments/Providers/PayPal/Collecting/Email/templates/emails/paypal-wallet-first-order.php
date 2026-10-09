<?php
/**
 * Admin PayPal Wallet first order email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/paypal-wallet-first-order.php.
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
 * @var WC_Order $order              The first order paid with PayPal Wallet.
 * @var string   $email_heading      The heading.
 * @var string   $additional_content The text the merchant set below the main content.
 * @var string   $settings_url       Where the merchant finishes the setup.
 * @var string   $deadline           The date PayPal returns a held payment, or an empty string when none is held.
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
	printf(
		/* translators: %s: order number */
		esc_html__( 'A customer placed order #%s and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', 'woocommerce' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>
<?php if ( '' !== $deadline ) : ?>
<p>
	<?php
	printf(
		/* translators: %s: date */
		esc_html__( 'PayPal returns the payment to the customer on %s if setup is not completed.', 'woocommerce' ),
		esc_html( $deadline )
	);
	?>
</p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Complete setup', 'woocommerce' ); ?></a></p>
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
