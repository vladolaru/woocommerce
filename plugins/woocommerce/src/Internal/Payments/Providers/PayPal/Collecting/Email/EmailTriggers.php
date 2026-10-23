<?php
/**
 * EmailTriggers class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WC_Order;

/**
 * Sends the two admin emails from the collecting module's actions.
 *
 * Both actions are fired by the collecting module's own listeners, so they fire only while core owns the wallet and the
 * module booted. The emails are found in the mailer by class, where OwnerIndependent::register_emails() put them.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class EmailTriggers {

	/**
	 * The logger, or null for none.
	 *
	 * @var LoggerInterface|null
	 */
	private ?LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Receives the failure of a send.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Send the first-order email.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order The first order.
	 * @param mixed $state The collecting state.
	 */
	public function handle_woocommerce_paypal_wallet_first_order( $order = null, $state = null ): void {
		unset( $state );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->guarded(
			static function ( $email ) use ( $order ): void {
				if ( $email instanceof FirstOrderEmail ) {
					$email->trigger( $order );
				}
			},
			FirstOrderEmail::class
		);
	}

	/**
	 * Send the returned-payment email.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order  The cancelled order.
	 * @param mixed $reason The capture status that ended the hold.
	 */
	public function handle_woocommerce_paypal_wallet_held_payment_returned( $order = null, $reason = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->guarded(
			static function ( $email ) use ( $order, $reason ): void {
				if ( $email instanceof HeldPaymentReturnedEmail ) {
					$email->trigger( $order, $reason );
				}
			},
			HeldPaymentReturnedEmail::class
		);
	}

	/**
	 * Run a send on the mailer's email of a class. A failure is logged and never reaches the code that fired the action.
	 *
	 * @param callable $send  Receives the email, or null when the mailer has none of that class.
	 * @param string   $email_class The email class.
	 */
	private function guarded( callable $send, string $email_class ): void {
		try {
			$emails = WC()->mailer()->get_emails();
			$send( $emails[ $email_class ] ?? null );
		} catch ( Throwable $throwable ) {
			if ( null !== $this->logger ) {
				$this->logger->warning( sprintf( 'PayPal wallet email failed: %s: %s', get_class( $throwable ), $throwable->getMessage() ) );
			}
		}
	}
}
