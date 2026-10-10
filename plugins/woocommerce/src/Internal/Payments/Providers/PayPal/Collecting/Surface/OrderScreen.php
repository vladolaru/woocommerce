<?php
/**
 * OrderScreen class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The notice on the edit screen of a PayPal Wallet order that waits for setup, while the store still collects.
 *
 * It says what the merchant has to do: connect the wallet, or, once PayPal reports the account can receive payments,
 * confirm the email PayPal sent. The Refund button next to it is locked by the order items view through the core-owned
 * filter `woocommerce_paypal_wallet_refund_locked`.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class OrderScreen {

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The refund lock, which decides which orders wait for setup.
	 *
	 * @var RefundLock
	 */
	private RefundLock $refund_lock;

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection;

	/**
	 * Constructor.
	 *
	 * @param Options|null    $options     The option reader; the stored options by default.
	 * @param RefundLock|null $refund_lock The refund lock; one over the stored options by default.
	 */
	public function __construct( ?Options $options = null, ?RefundLock $refund_lock = null ) {
		$this->options     = $options ?? new Options();
		$this->connection  = new ConnectionState( $this->options );
		$this->refund_lock = $refund_lock ?? new RefundLock( $this->connection );
	}

	/**
	 * Print the notice under the order's payment information.
	 *
	 * Hooked to `woocommerce_admin_order_data_after_payment_info`.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order The order.
	 */
	public function handle_woocommerce_admin_order_data_after_payment_info( $order = null ): void {
		$html = $this->notice_html( $order );
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in notice_html().
		}
	}

	/**
	 * The notice HTML for an order, or an empty string when the order does not wait for setup.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order The order.
	 * @return string
	 */
	public function notice_html( $order ): string {
		// The setup copy is for a store that still collects; a held order of a connected store waits for PayPal, not the merchant.
		if ( ! $order instanceof WC_Order || ConnectionState::COLLECTING !== $this->connection->resolve() || ! $this->refund_lock->base_locked( $order ) ) {
			return '';
		}

		return '<div class="wc-paypal-wallet-order-notice notice notice-warning inline"><p>' . esc_html( $this->text() ) . '</p></div>';
	}

	/**
	 * The text: what the Plugins page says while the extension owns the wallet; else confirm the email when the last seller
	 * status reads receivable but unconfirmed, else connect the wallet.
	 *
	 * @return string
	 */
	private function text(): string {
		$status = $this->options->seller_status();
		$payee  = $this->options->payee_email();

		// The extension owns the wallet: setup cannot complete from the store, so say what the Plugins page says.
		if ( '' !== $payee && RuntimeServices::extension_owns_wallet() ) {
			/* translators: %s: the email address of the PayPal account the payment waits for. */
			return sprintf( __( 'The payment for this order is waiting for the PayPal account %s to be set up and confirmed.', 'woocommerce' ), $payee );
		}

		if ( '' !== $payee && ! empty( $status['payments_receivable'] ) && empty( $status['primary_email_confirmed'] ) ) {
			/* translators: %s: the email address of the PayPal account the payment waits for. */
			return sprintf( __( 'Confirm the email PayPal sent to %s to release the payment.', 'woocommerce' ), $payee );
		}

		return __( 'To receive the payment, connect PayPal Wallet to your store and complete the setup.', 'woocommerce' );
	}
}
