<?php
/**
 * InboxNoteAction.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

/**
 * An action that can be performed on a WooCommerce inbox note.
 */
class InboxNoteAction implements InboxNoteActionInterface {


	/**
	 * The name.
	 *
	 * @var string
	 */
	protected string $name;
	/**
	 * The label.
	 *
	 * @var string
	 */
	protected string $label;
	/**
	 * The url.
	 *
	 * @var string
	 */
	protected string $url;
	/**
	 * The status.
	 *
	 * @var string
	 */
	protected string $status;
	/**
	 * Whether it is primary.
	 *
	 * @var bool
	 */
	protected bool $is_primary;

	/**
	 * InboxNoteAction constructor.
	 *
	 * @param string $name       The name.
	 * @param string $label      The label.
	 * @param string $url        The url.
	 * @param string $status     The status.
	 * @param bool   $is_primary Whether is primary.
	 */
	public function __construct( string $name, string $label, string $url, string $status, bool $is_primary ) {
		$this->name       = $name;
		$this->label      = $label;
		$this->url        = $url;
		$this->status     = $status;
		$this->is_primary = $is_primary;
	}

	/**
	 * Returns the name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Returns the url.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Returns the status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns whether it is primary.
	 */
	public function is_primary(): bool {
		return $this->is_primary;
	}
}
