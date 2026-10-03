<?php
/**
 * A factory to create simple redirect task.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory
 */

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Tasks\SimpleRedirectTask;

/**
 * A factory to create simple redirect task.
 */
class SimpleRedirectTaskFactory implements SimpleRedirectTaskFactoryInterface {

	/**
	 * {@inheritDoc}
	 */
	public function create_task( string $id, string $title, string $description, string $redirect_url ): SimpleRedirectTask {
		return new SimpleRedirectTask( $id, $title, $description, $redirect_url );
	}
}
