<?php
/**
 * InboxNote.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

/**
 * A note that can be displayed in the WooCommerce inbox section.
 */
class InboxNote implements InboxNoteInterface {

	/**
	 * The title.
	 *
	 * @var string
	 */
	protected string $title;
	/**
	 * The content.
	 *
	 * @var string
	 */
	protected string $content;
	/**
	 * The type.
	 *
	 * @var string
	 */
	protected string $type;
	/**
	 * The name.
	 *
	 * @var string
	 */
	protected string $name;
	/**
	 * The status.
	 *
	 * @var string
	 */
	protected string $status;
	/**
	 * Whether the note is enabled.
	 *
	 * @var bool
	 */
	protected bool $is_enabled;

	/**
	 * The actions of the note.
	 *
	 * @var InboxNoteActionInterface[]
	 */
	protected array $actions;

	/**
	 * InboxNote constructor.
	 *
	 * @param string                   $title      The title.
	 * @param string                   $content    The content.
	 * @param string                   $type       The type.
	 * @param string                   $name       The name.
	 * @param string                   $status     The status.
	 * @param bool                     $is_enabled Whether the note is enabled.
	 * @param InboxNoteActionInterface ...$actions The actions.
	 */
	public function __construct(
		string $title,
		string $content,
		string $type,
		string $name,
		string $status,
		bool $is_enabled,
		InboxNoteActionInterface ...$actions
	) {
		$this->title      = $title;
		$this->content    = $content;
		$this->type       = $type;
		$this->name       = $name;
		$this->status     = $status;
		$this->is_enabled = $is_enabled;
		$this->actions    = $actions;
	}

	/**
	 * Returns the title.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Returns the content.
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Returns the type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns whether it is enabled.
	 */
	public function is_enabled(): bool {
		return $this->is_enabled;
	}

	/**
	 * Returns the actions of the note.
	 *
	 * @return InboxNoteActionInterface[]
	 */
	public function actions(): array {
		return $this->actions;
	}
}
