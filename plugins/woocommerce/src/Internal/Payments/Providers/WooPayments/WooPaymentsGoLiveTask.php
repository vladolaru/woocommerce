<?php
/**
 * WooPaymentsGoLiveTask class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;

defined( 'ABSPATH' ) || exit;

/**
 * The WC Home "Things to do next" task that asks a connected test-mode WooPayments store to activate live payments.
 *
 * Ports client 11.1.0 `getGoLiveTask()` (`client/overview/task-list/tasks/go-live-task.tsx`), which the client adds only
 * to the WC Home list through `woocommerce_admin_onboarding_task_list` (`client/index.js:391-405`, `showGoLiveTask: true`)
 * when `isAccountConnected && testModeOnboarding` (`tasks.tsx:76-79`). A click opens the live payments modal; the
 * `woopayments/home-tasks/go-live-task.tsx` fill in the WC admin client handles it.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsGoLiveTask extends Task {

	/**
	 * Task ID, shared with the client.
	 */
	public const ID = 'go-live-payments';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Constructor.
	 *
	 * @param TaskList|null             $task_list       Parent task list.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	public function __construct( $task_list, WooPaymentsAccountService $account_service ) {
		parent::__construct( $task_list );
		$this->account_service = $account_service;
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Activate payments', 'woocommerce' );
	}

	/**
	 * Content.
	 *
	 * @return string
	 */
	public function get_content() {
		return '';
	}

	/**
	 * Time.
	 *
	 * @return string
	 */
	public function get_time() {
		return __( '10 minutes', 'woocommerce' );
	}

	/**
	 * The task shows while the store has a cached account and onboards in test mode.
	 *
	 * The client reads `has_account_data()` (a cached account ID, no refresh) and `is_test_mode_onboarding()`
	 * (`class-wc-payments-admin.php:979,1027`).
	 *
	 * @return bool
	 */
	public function can_view() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		if ( empty( $this->account_service->get_preserved_account_data_snapshot()['account_id'] ) ) {
			return false;
		}

		return $this->account_service->is_test_mode_onboarding_enabled();
	}

	/**
	 * The client task is never complete and cannot be dismissed or snoozed.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return false;
	}
}
