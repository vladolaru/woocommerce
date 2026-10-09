<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Email;

/**
 * Catches the emails a test sends, instead of sending them.
 */
trait CapturesMail {

	/**
	 * The arguments wp_mail() was called with, one entry per email.
	 *
	 * @var array[]
	 */
	private array $mails = array();

	/**
	 * Short-circuit wp_mail() and record each call.
	 */
	private function capture_mail(): void {
		// WC_Emails adds its header and footer hooks when it is built, and the framework restores the hooks after every
		// test, so an earlier test can leave the shared instance without them.
		if ( ! has_action( 'woocommerce_email_header' ) ) {
			new \WC_Emails();
		}
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				unset( $short_circuit );
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}
}
