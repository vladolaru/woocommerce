<?php
/**
 * DormantPayPalGateway class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\GatewaySwitch;
use RuntimeException;
use WC_Payment_Gateway;

/**
 * Placeholder gateway that stands in for the PayPal wallet while it is dormant.
 *
 * A dormant wallet is not built, so its gateway does not exist. This placeholder gives the Payments settings list
 * a "PayPal Wallet" row with a setup button until a merchant connects. It shares the wallet gateway's ID
 * and so its stored settings, which the wallet owns: this class reads them and saves nothing itself; the one write,
 * turning the gateway on for a store the platform serves, goes through GatewaySwitch.
 *
 * A store the platform serves (collecting, or platform connected) whose merchant turned the gateway off is dormant too.
 * Its row offers Enable instead of the setup button, and enabling turns the wallet gateway on, so the next request
 * serves the store again: collecting for the same payee, or platform connected (K8, Rulings 165 and 166).
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
	 * Never write to the shared settings option, which the wallet owns, except to turn the gateway back on for a store the
	 * platform serves. That write is WooCommerce's enable toggle; it goes through the wallet gateway's own settings.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value to set.
	 *
	 * @return bool Whether the gateway was turned on; false for every other write, which saves nothing.
	 */
	public function update_option( $key, $value = '' ) {
		if ( 'enabled' !== $key || 'yes' !== $value || ! $this->can_be_turned_back_on() ) {
			return false;
		}

		try {
			( new GatewaySwitch() )->turn_on();
		} catch ( RuntimeException $failure ) {
			// GatewaySwitch logged the cause; the list reloads and still shows the gateway off.
			unset( $failure );
			return false;
		}

		return true;
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
	 * The placeholder needs setup, so the Payments settings list offers a setup button instead of an enable toggle,
	 * unless the gateway can be turned back on.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		return ! $this->can_be_turned_back_on();
	}

	/**
	 * Whether the platform serves the store (a collecting payee or a platform merchant ID is kept), so turning the gateway
	 * on serves it again. The placeholder stands in only while such a store's gateway is off. Reads only.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function can_be_turned_back_on(): bool {
		return ( new ConnectionState() )->is_served_by_platform();
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
		$url = PayPalWalletBootstrap::get_settings_url();

		echo '<p>' . esc_html__( 'Connect your PayPal account to start accepting payments.', 'woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Finish setup', 'woocommerce' ) . '</a></p>';
	}
}
