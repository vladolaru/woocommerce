<?php
/**
 * A factory to create simple redirect task.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Tasks\SimpleRedirectTask;

/**
 * A factory to create simple redirect task.
 */
class SimpleRedirectTaskFactory implements SimpleRedirectTaskFactoryInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id           The id.
	 * @param string $title        The title.
	 * @param string $description  The description.
	 * @param string $redirect_url The redirect url.
	 */
	public function create_task( string $id, string $title, string $description, string $redirect_url ): SimpleRedirectTask {
		return new SimpleRedirectTask( $id, $title, $description, $redirect_url );
	}
}
