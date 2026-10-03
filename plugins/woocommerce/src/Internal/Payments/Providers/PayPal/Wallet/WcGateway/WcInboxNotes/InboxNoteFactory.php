<?php
/**
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

/**
 * A factory for creating inbox notes.
 */
class InboxNoteFactory {

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
