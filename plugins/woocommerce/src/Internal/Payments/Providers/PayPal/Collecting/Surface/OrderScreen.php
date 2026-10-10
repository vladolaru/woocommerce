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
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The notice on the edit screen of a PayPal Wallet order that waits for setup, while the store still collects.
 *
 * It says what the merchant has to do: connect the wallet, or, once PayPal reports the account can receive payments,
 * confirm the email PayPal sent. The Refund button next to it is locked by the order items view through the core-owned
 * filter `woocommerce_paypal_wallet_refund_locked`. Each user can dismiss it; it comes back on a newer wallet order.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class OrderScreen {

	/**
	 * The dismissal surface slug.
	 *
	 * @since 11.3.0
	 */
	public const SURFACE = 'order-notice';

	/**
	 * The order data box greys its paragraphs; the notice keeps the admin's notice text color, as on other screens. The
	 * dismissible notice keeps the button's absolute position inside it; the button's own rule is WordPress's.
	 */
	private const NOTICE_STYLE = '#order_data .wc-paypal-wallet-order-notice p { color: inherit; } .wc-paypal-wallet-order-notice--dismissible { position: relative; padding-right: 38px; }';

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
	 * The dismissals.
	 *
	 * @var Dismissals
	 */
	private Dismissals $dismissals;

	/**
	 * Constructor.
	 *
	 * @param Options|null    $options     The option reader; the stored options by default.
	 * @param RefundLock|null $refund_lock The refund lock; one over the stored options by default.
	 * @param Dismissals|null $dismissals  The dismissals; the stored ones by default.
	 */
	public function __construct( ?Options $options = null, ?RefundLock $refund_lock = null, ?Dismissals $dismissals = null ) {
		$this->options     = $options ?? new Options();
		$this->connection  = new ConnectionState( $this->options );
		$this->refund_lock = $refund_lock ?? new RefundLock( $this->connection );
		$this->dismissals  = $dismissals ?? new Dismissals();
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
	 * Give the notice the admin's notice text color and its dismiss script on the order edit screen.
	 *
	 * Hooked to `admin_enqueue_scripts` after WooCommerce enqueues its admin styles.
	 *
	 * @since 11.3.0
	 */
	public function handle_admin_enqueue_scripts(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
			return;
		}

		if ( wp_style_is( 'woocommerce_admin_styles', 'enqueued' ) ) {
			wp_add_inline_style( 'woocommerce_admin_styles', self::NOTICE_STYLE );
		}
		if ( wp_script_is( 'wc-admin-order-meta-boxes', 'enqueued' ) ) {
			wp_add_inline_script( 'wc-admin-order-meta-boxes', $this->dismiss_script(), 'after' );
		}
	}

	/**
	 * The notice HTML for an order, or an empty string when the order does not wait for setup or the current user dismissed
	 * the notice on this order or a newer one.
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
		if ( $this->dismissals->is_dismissed( self::SURFACE, get_current_user_id(), $order->get_id() ) ) {
			return '';
		}

		// Not `is-dismissible`: WordPress's common.js would append a second dismiss button to it.
		$notice = $this->notice();
		$html   = '<div class="wc-paypal-wallet-order-notice wc-paypal-wallet-order-notice--dismissible notice notice-warning inline" data-dismiss-url="' . esc_url( $this->dismissals->dismiss_url( self::SURFACE, $order->get_id() ) ) . '"><p>' . esc_html( $notice['text'] ) . '</p>';
		if ( $notice['offers_setup'] ) {
			$html .= '<p><a class="button button-primary" href="' . esc_url( PayPalWalletBootstrap::get_settings_url() ) . '">' . esc_html__( 'Complete setup', 'woocommerce' ) . '</a></p>';
		}
		$html .= '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'woocommerce' ) . '</span></button>';

		return $html . '</div>';
	}

	/**
	 * The script that dismisses the notice: removes it, moves focus to the Status field, stores the dismissal, and puts
	 * the notice back when the store refused or the request failed.
	 *
	 * @return string
	 */
	private function dismiss_script(): string {
		$messages = wp_json_encode(
			array(
				'dismissed' => __( 'Notice dismissed.', 'woocommerce' ),
				'failed'    => __( 'The notice could not be dismissed.', 'woocommerce' ),
			)
		);

		return '( function () {'
			. 'var messages = ' . $messages . ';'
			. 'document.addEventListener( "click", function ( event ) {'
			. 'var button = event.target.closest( ".wc-paypal-wallet-order-notice--dismissible .notice-dismiss" );'
			. 'if ( ! button ) { return; }'
			. 'var notice = button.closest( ".wc-paypal-wallet-order-notice--dismissible" );'
			. 'var parent = notice.parentNode, next = notice.nextSibling, status = document.getElementById( "order_status" );'
			. 'parent.removeChild( notice );'
			. 'if ( status ) { status.focus(); }'
			. 'var speak = window.wp && window.wp.a11y && window.wp.a11y.speak ? window.wp.a11y.speak : function () {};'
			. 'window.fetch( notice.getAttribute( "data-dismiss-url" ), { method: "POST", credentials: "same-origin" } )'
			. '.then( function ( response ) { if ( ! response.ok ) { throw new Error( String( response.status ) ); } speak( messages.dismissed ); } )'
			. '.catch( function () { parent.insertBefore( notice, next ); button.focus(); speak( messages.failed, "assertive" ); } );'
			. '} );'
			. '} )();';
	}

	/**
	 * The text, and whether the merchant can act on it here: what the Plugins page says while the extension owns the
	 * wallet; else confirm the email when the last seller status reads receivable but unconfirmed; else connect the
	 * wallet, which the notice's "Complete setup" button starts.
	 *
	 * @return array{text: string, offers_setup: bool}
	 */
	private function notice(): array {
		$status = $this->options->seller_status();
		$payee  = $this->options->payee_email();

		// The extension owns the wallet: setup cannot complete from the store, so say what the Plugins page says.
		if ( '' !== $payee && RuntimeServices::extension_owns_wallet() ) {
			return array(
				/* translators: %s: the email address of the PayPal account the payment waits for. */
				'text'         => sprintf( __( 'The payment for this order is waiting for the PayPal account %s to be set up and confirmed.', 'woocommerce' ), $payee ),
				'offers_setup' => false,
			);
		}

		if ( '' !== $payee && ! empty( $status['payments_receivable'] ) && empty( $status['primary_email_confirmed'] ) ) {
			return array(
				/* translators: %s: the email address of the PayPal account the payment waits for. */
				'text'         => sprintf( __( 'Confirm the email PayPal sent to %s to release the payment.', 'woocommerce' ), $payee ),
				'offers_setup' => false,
			);
		}

		return array(
			'text'         => __( 'To receive the payment, connect PayPal Wallet to your store and complete the setup.', 'woocommerce' ),
			'offers_setup' => true,
		);
	}
}
