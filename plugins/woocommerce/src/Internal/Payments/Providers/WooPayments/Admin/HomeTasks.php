<?php
/**
 * HomeTasks class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the WooPayments tasks (update business details, respond to disputes, activate payments) to the WC Home "Things
 * to do next" list.
 *
 * Loads only for connected stores, on admin and REST requests, since WC Home reads its task lists over REST.
 * The tasks read the account and dispute caches only when the list is rendered.
 *
 * @since 11.2.0
 * @internal
 */
class HomeTasks implements RegisterHooksInterface {

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Service that owns the cached dispute data.
	 *
	 * @var WooPaymentsAdminMenuBadgeService
	 */
	private WooPaymentsAdminMenuBadgeService $dispute_data_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService        $account_service      WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService $dispute_data_service Service that owns the cached dispute data.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsAdminMenuBadgeService $dispute_data_service ): void {
		$this->account_service      = $account_service;
		$this->dispute_data_service = $dispute_data_service;
	}

	/**
	 * Register the task hook.
	 */
	public function register() {
		// WC admin builds the task lists at init priority 4 (`Features::load_features()`).
		add_action( 'init', array( $this, 'add_tasks' ) );
	}

	/**
	 * Add the WooPayments tasks to the extended task list.
	 *
	 * @internal
	 */
	public function add_tasks(): void {
		$task_list = TaskLists::get_list( 'extended' );
		if ( null === $task_list ) {
			return;
		}

		TaskLists::add_task( 'extended', new UpdateBusinessDetailsTask( $task_list, $this->account_service ) );
		TaskLists::add_task( 'extended', new DisputesTask( $task_list, $this->account_service, $this->dispute_data_service ) );
		TaskLists::add_task( 'extended', new GoLiveTask( $task_list, $this->account_service ) );
	}
}
