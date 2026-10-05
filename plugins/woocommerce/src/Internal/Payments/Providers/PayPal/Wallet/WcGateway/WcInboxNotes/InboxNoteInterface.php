<?php
/**
 * Represents a note that can be displayed in the WooCommerce inbox section.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

interface InboxNoteInterface {

	/**
	 * Returns the title.
	 */
	public function title(): string;
	/**
	 * Returns the content.
	 */
	public function content(): string;
	/**
	 * Returns the type.
	 */
	public function type(): string;
	/**
	 * Returns the name.
	 */
	public function name(): string;
	/**
	 * Returns the status.
	 */
	public function status(): string;
	/**
	 * Returns whether it is enabled.
	 */
	public function is_enabled(): bool;

	/**
	 * Returns the actions of the note.
	 *
	 * @return InboxNoteActionInterface[]
	 */
	public function actions(): array;
}
