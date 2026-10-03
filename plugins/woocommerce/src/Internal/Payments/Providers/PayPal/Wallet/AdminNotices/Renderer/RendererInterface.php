<?php
/**
 * The renderer interface.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer;

/**
 * Interface RendererInterface
 */
interface RendererInterface {

	/**
	 * Renders the messages.
	 *
	 * @return bool
	 */
	public function render(): bool;

	/**
	 * Enqueues common assets required for the admin notice behavior.
	 *
	 * @return void
	 */
	public function enqueue_admin(): void;
}
