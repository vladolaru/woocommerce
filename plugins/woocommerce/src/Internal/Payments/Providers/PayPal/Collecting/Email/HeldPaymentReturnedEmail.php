<?php
/**
 * HeldPaymentReturnedEmail class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Enums\OrderStatus;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The admin email sent when PayPal returns, or denies, a payment it held for an order because setup was not completed.
 *
 * A theme overrides its templates through `woocommerce/emails/paypal-wallet-held-payment-returned.php`.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class HeldPaymentReturnedEmail extends AbstractWalletEmail {

	/**
	 * The email ID.
	 *
	 * @since 11.3.0
	 */
	public const ID = 'wc_paypal_wallet_held_payment_returned';

	/**
	 * The capture status that ended the hold: `refunded`, `declined` or `failed`.
	 *
	 * @var string
	 */
	private string $reason = 'refunded';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = self::ID;
		$this->title          = __( 'PayPal Wallet: held payment not released', 'woocommerce' );
		$this->description    = __( 'Notifies admins when PayPal returns or denies a payment it held for an order because PayPal Wallet setup was not completed.', 'woocommerce' );
		$this->template_html  = 'emails/paypal-wallet-held-payment-returned.php';
		$this->template_plain = 'emails/plain/paypal-wallet-held-payment-returned.php';

		parent::__construct();
	}

	/**
	 * Get email subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'PayPal did not release the payment for order #{order_number}', 'woocommerce' );
	}

	/**
	 * Get email heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'PayPal did not release a held payment', 'woocommerce' );
	}

	/**
	 * Send the email for an order whose held payment PayPal returned or denied.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order  The order: cancelled, or left as it was when the merchant had moved it off hold.
	 * @param mixed $reason The capture status that ended the hold: `refunded`, `declined` or `failed`.
	 */
	public function trigger( $order = null, $reason = 'refunded' ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->setup_locale();
		$this->bind_order( $order );
		$this->reason = is_string( $reason ) ? $reason : 'refunded';

		$this->send_notification();

		$this->restore_locale();
	}

	/**
	 * The variables the templates read.
	 *
	 * @param bool $plain_text Whether the plain-text template is rendered.
	 * @return array
	 */
	protected function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'returned'           => 'refunded' === $this->reason,
			'cancelled'          => $this->object instanceof WC_Order && $this->object->has_status( OrderStatus::CANCELLED ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
