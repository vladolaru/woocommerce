<?php
/**
 * Represents a note that can be displayed in the WooCommerce inbox section.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

interface InboxNoteInterface {

	public function title(): string;
	public function content(): string;
	public function type(): string;
	public function name(): string;
	public function status(): string;
	public function is_enabled(): bool;

	/**
	 * @return InboxNoteActionInterface[]
	 */
	public function actions(): array;
}
