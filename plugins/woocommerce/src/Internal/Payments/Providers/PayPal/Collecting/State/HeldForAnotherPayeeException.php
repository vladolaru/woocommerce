<?php
/**
 * HeldForAnotherPayeeException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

use RuntimeException;

/**
 * The collecting state cannot start for a payee, because PayPal is holding orders paid to another one.
 *
 * Its message is written for the merchant, so the REST endpoint and the CLI command show it as it is.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class HeldForAnotherPayeeException extends RuntimeException {

	/**
	 * The message, in English. The REST endpoint shows a translation of the same text.
	 *
	 * @since 11.3.0
	 */
	public const MESSAGE = 'PayPal is holding payments for another email on this store. Wait until they are released or returned before changing the PayPal email.';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( self::MESSAGE );
	}
}
