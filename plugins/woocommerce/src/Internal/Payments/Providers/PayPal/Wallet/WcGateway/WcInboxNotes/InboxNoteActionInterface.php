<?php
/**
 * Represents an action that can be performed on a WooCommerce inbox note.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

interface InboxNoteActionInterface {

	/**
	 * Returns the name.
	 */
	public function name(): string;
	/**
	 * Returns the label.
	 */
	public function label(): string;
	/**
	 * Returns the url.
	 */
	public function url(): string;
	/**
	 * Returns the status.
	 */
	public function status(): string;
	/**
	 * Returns whether it is primary.
	 */
	public function is_primary(): bool;
}
