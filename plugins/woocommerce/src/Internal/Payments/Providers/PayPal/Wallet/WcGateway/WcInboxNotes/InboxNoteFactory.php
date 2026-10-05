<?php
/**
 * InboxNoteFactory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

/**
 * A factory for creating inbox notes.
 */
class InboxNoteFactory {

	/**
	 * Creates an inbox note.
	 *
	 * @param string                   $title      The title.
	 * @param string                   $content    The content.
	 * @param string                   $type       The type.
	 * @param string                   $name       The name.
	 * @param string                   $status     The status.
	 * @param bool                     $is_enabled Whether the note is enabled.
	 * @param InboxNoteActionInterface ...$actions The actions.
	 */
	public function create_note(
		string $title,
		string $content,
		string $type,
		string $name,
		string $status,
		bool $is_enabled,
		InboxNoteActionInterface ...$actions
	): InboxNoteInterface {
		return new InboxNote( $title, $content, $type, $name, $status, $is_enabled, ...$actions );
	}
}
