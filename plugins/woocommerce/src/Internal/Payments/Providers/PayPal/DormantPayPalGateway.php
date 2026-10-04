<?php
/**
 * DormantPayPalGateway class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use WC_Payment_Gateway;

/**
 * Placeholder gateway that stands in for the PayPal wallet while it is dormant.
 *
 * A dormant wallet is not built, so its gateway does not exist. This placeholder gives the Payments settings list
 * a "PayPal Wallet" row with a "Finish setup" button until a merchant connects. It shares the wallet gateway's ID
 * and so its stored settings, which the wallet owns: this class only reads them and never writes them.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class DormantPayPalGateway extends WC_Payment_Gateway {

	/**
	 * Set up the placeholder.
	 */
	public function __construct() {
		$this->id                 = 'ppcp-gateway';
		$this->method_title       = __( 'PayPal Wallet', 'woocommerce' );
		$this->method_description = __( 'Accept PayPal payments in your store.', 'woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array();

		$this->init_form_fields();
		$this->init_settings();
		// The shared settings may say yes, but a placeholder can never take a payment, and no reader should see otherwise.
		$this->settings['enabled'] = 'no';
		$this->enabled             = 'no';
	}

	/**
	 * The placeholder has no settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	/**
	 * Read an option, always answering "no" for the enabled flag whatever the shared settings option stores.
	 *
	 * @param string $key         Option key.
	 * @param mixed  $empty_value Value to return when the option is empty.
	 *
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		if ( 'enabled' === $key ) {
			return 'no';
		}

		return parent::get_option( $key, $empty_value );
	}

	/**
	 * Never write to the shared settings option, which the wallet owns.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value to set.
	 *
	 * @return bool Always false: nothing was saved.
	 */
	public function update_option( $key, $value = '' ) {
		unset( $key, $value );

		return false;
	}

	/**
	 * Never save posted settings into the shared settings option, which the wallet owns.
	 *
	 * @return bool Always false: nothing was saved.
	 */
	public function process_admin_options() {
		return false;
	}

	/**
	 * The placeholder always needs setup, so the Payments settings list offers "Finish setup" instead of an enable toggle.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		return true;
	}

	/**
	 * The placeholder is never offered at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		return false;
	}

	/**
	 * Fail a payment attempt: the wallet is not set up, so there is nothing to charge.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		unset( $order_id );
		wc_add_notice( __( 'PayPal Wallet is not set up yet. Choose another payment method.', 'woocommerce' ), 'error' );

		return array( 'result' => 'failure' );
	}

	/**
	 * Print a link to the wallet's settings, where the merchant connects their PayPal account.
	 *
	 * @return void
	 */
	public function admin_options() {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway' );

		echo '<p>' . esc_html__( 'Connect your PayPal account to start accepting payments.', 'woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Finish setup', 'woocommerce' ) . '</a></p>';
	}
}
