<?php
/**
 * Registers the tasks inside the "Things to do next" WC section.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Registrar
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Registrar;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use RuntimeException;
use WP_Error;

/**
 * Registers the tasks inside the "Things to do next" WC section.
 */
class TaskRegistrar implements TaskRegistrarInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $list_id The list id.
	 * @param array  $tasks   The tasks.
	 * @throws RuntimeException If problem registering.
	 */
	public function register( string $list_id, array $tasks ): void {
		$task_lists = TaskLists::get_lists();
		if ( ! isset( $task_lists[ $list_id ] ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			$added_task = TaskLists::add_task( $list_id, $task );
			if ( $added_task instanceof WP_Error ) {
				throw new RuntimeException( $added_task->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is only logged, by WCGatewayModule::register_wc_tasks(); it is never printed, so escaping would only alter the log text.
			}
		}
	}
}
