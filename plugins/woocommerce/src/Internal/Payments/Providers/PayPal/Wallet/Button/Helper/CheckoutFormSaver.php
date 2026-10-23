<?php
/**
 * Saves the form data to the WC customer and session.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper;

use WC_Checkout;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;

/**
 * Class CheckoutFormSaver
 */
class CheckoutFormSaver extends WC_Checkout {
	/**
	 * The Session handler.
	 *
	 * @var SessionHandler
	 */
	private $session_handler;

	/**
	 * CheckoutFormSaver constructor.
	 *
	 * @param SessionHandler $session_handler The session handler.
	 */
	public function __construct(
		SessionHandler $session_handler
	) {
		$this->session_handler = $session_handler;
	}

	/**
	 * Saves the form data to the WC customer and session.
	 *
	 * @param array $data The form data.
	 * @return void
	 */
	public function save( array $data ) {
		foreach ( $data as $key => $value ) {
			$_POST[ $key ] = $value;
		}
		$data = $this->get_posted_data();

		$this->update_session( $data );

		$this->session_handler->replace_checkout_form( $data );
	}
}
