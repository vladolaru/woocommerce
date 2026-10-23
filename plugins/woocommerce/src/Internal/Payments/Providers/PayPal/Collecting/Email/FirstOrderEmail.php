<?php
/**
 * FirstOrderEmail class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use DateTimeZone;
use WC_DateTime;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The admin email sent once, when the first order paid with PayPal Wallet arrives before the merchant has set it up.
 *
 * A theme overrides its templates through `woocommerce/emails/paypal-wallet-first-order.php`.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class FirstOrderEmail extends AbstractWalletEmail {

	/**
	 * The email ID.
	 *
	 * @since 11.3.0
	 */
	public const ID = 'wc_paypal_wallet_first_order';

	/**
	 * When PayPal returns the payment of the order if setup is not completed, as a UTC timestamp, or null when the
	 * payment is not held.
	 *
	 * @var int|null
	 */
	private ?int $deadline = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = self::ID;
		$this->title          = __( 'PayPal Wallet: order waiting for setup', 'woocommerce' );
		$this->description    = __( 'Notifies admins, once, when an order paid with PayPal Wallet arrives before PayPal Wallet setup is complete.', 'woocommerce' );
		$this->template_html  = 'emails/paypal-wallet-first-order.php';
		$this->template_plain = 'emails/plain/paypal-wallet-first-order.php';

		parent::__construct();
	}

	/**
	 * Get email subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'An order is waiting: set up PayPal Wallet to receive it', 'woocommerce' );
	}

	/**
	 * Get email heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'An order is waiting for PayPal Wallet setup', 'woocommerce' );
	}

	/**
	 * Send the email for the first wallet order.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order The first order.
	 */
	public function trigger( $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->setup_locale();
		$this->bind_order( $order );
		$this->deadline = $this->deadline_of( $order );

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
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'settings_url'       => PayPalWalletBootstrap::get_settings_url(),
			'deadline'           => null === $this->deadline ? '' : $this->format_deadline( $this->deadline ),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * The deadline of an order whose payment PayPal holds, or null when the payment is not held.
	 *
	 * The order may not be on hold yet when the first-order action fires, so the held-capture meta decides.
	 *
	 * @param WC_Order $order The order.
	 * @return int|null
	 */
	private function deadline_of( WC_Order $order ): ?int {
		if ( empty( $order->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) ) ) {
			return null;
		}

		return ( new HeldOrders() )->deadline_for( $order );
	}

	/**
	 * A deadline as a date in the site's time zone.
	 *
	 * @param int $deadline A UTC timestamp.
	 * @return string
	 */
	private function format_deadline( int $deadline ): string {
		$date = new WC_DateTime( '@' . $deadline );
		$date->setTimezone( new DateTimeZone( wc_timezone_string() ) );

		return wc_format_datetime( $date );
	}
}
