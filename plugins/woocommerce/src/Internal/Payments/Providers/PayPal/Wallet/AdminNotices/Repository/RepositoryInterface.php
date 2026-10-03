<?php
/**
 * The repository interface.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Entity\Message;

/**
 * Interface RepositoryInterface
 */
interface RepositoryInterface {


	/**
	 * Returns the current messages.
	 *
	 * @return Message[]
	 */
	public function current_message(): array;
}
