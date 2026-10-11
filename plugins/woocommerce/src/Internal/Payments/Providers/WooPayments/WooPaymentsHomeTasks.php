<?php
/**
 * WooPaymentsHomeTasks class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the native WooPayments tasks to the WC Home "Things to do next" list.
 *
 * Loads only for connected stores, on admin and REST requests, since WC Home reads its task lists over REST.
 * The tasks read the account and dispute caches only when the list is rendered.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsHomeTasks implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

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
	 * @param WooPaymentsRuntimeArbiter        $arbiter              Runtime owner arbiter.
	 * @param WooPaymentsAccountService        $account_service      WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService $dispute_data_service Service that owns the cached dispute data.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service, WooPaymentsAdminMenuBadgeService $dispute_data_service ): void {
		$this->arbiter              = $arbiter;
		$this->account_service      = $account_service;
		$this->dispute_data_service = $dispute_data_service;
	}

	/**
	 * Register the task hook.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		// WC admin builds the task lists at init priority 4 (`Features::load_features()`).
		if ( false === has_action( 'init', array( $this, 'add_tasks' ) ) ) {
			add_action( 'init', array( $this, 'add_tasks' ) );
		}
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

		TaskLists::add_task( 'extended', new WooPaymentsUpdateBusinessDetailsTask( $task_list, $this->account_service ) );
		TaskLists::add_task( 'extended', new WooPaymentsDisputesTask( $task_list, $this->account_service, $this->dispute_data_service ) );
		TaskLists::add_task( 'extended', new WooPaymentsGoLiveTask( $task_list, $this->account_service ) );
	}
}
