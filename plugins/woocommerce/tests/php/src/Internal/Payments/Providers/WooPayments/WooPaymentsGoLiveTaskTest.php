<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesTask;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGoLiveTask;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHomeTasks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsUpdateBusinessDetailsTask;
use WC_Unit_Test_Case;

/**
 * Tests for the WC Home task that asks a connected test-mode WooPayments store to activate live payments.
 *
 * Expected values come from client 11.1.0 `client/overview/task-list/tasks.tsx:76-79`, `tasks/go-live-task.tsx:46-57`,
 * `strings.tsx:289-292` and `class-wc-payments-admin.php:979,1027`.
 */
class WooPaymentsGoLiveTaskTest extends WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$extended = TaskLists::get_list( 'extended' );
		if ( null !== $extended ) {
			$extended->tasks = array_values(
				array_filter(
					$extended->tasks,
					static fn( $task ): bool => ! $task instanceof WooPaymentsGoLiveTask
						&& ! $task instanceof WooPaymentsUpdateBusinessDetailsTask
						&& ! $task instanceof WooPaymentsDisputesTask
				)
			);
		}

		parent::tearDown();
	}

	/**
	 * @testdox The task shows only for a connected account that onboards in test mode.
	 * @dataProvider provide_visibility_cases
	 *
	 * @param array<string,mixed> $account              Cached account data.
	 * @param bool                $test_mode_onboarding Whether WooPayments onboards in test mode.
	 * @param bool                $expected             Whether the task shows.
	 */
	public function test_visibility_follows_the_client_conditions( array $account, bool $test_mode_onboarding, bool $expected ): void {
		$this->assertSame( $expected, $this->create_task( $account, $test_mode_onboarding )->can_view() );
	}

	/**
	 * Visibility cases.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:bool}>
	 */
	public function provide_visibility_cases(): array {
		return array(
			'connected, test-mode onboarding'           => array( array( 'account_id' => 'acct_test' ), true, true ),
			'connected live account, test-mode onboard' => array(
				array(
					'account_id'    => 'acct_test',
					'is_live'       => true,
					'is_test_drive' => false,
				),
				true,
				true,
			),
			'connected, live onboarding'                => array( array( 'account_id' => 'acct_test' ), false, false ),
			'no cached account'                         => array( array(), true, false ),
			'cached account without an ID'              => array( array( 'account_id' => '' ), true, false ),
		);
	}

	/**
	 * @testdox The task is hidden from users who cannot manage WooCommerce.
	 */
	public function test_hidden_without_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$this->assertFalse( $this->create_task( array( 'account_id' => 'acct_test' ), true )->can_view() );
	}

	/**
	 * @testdox The task carries the client's ID, title and time, never completes, and cannot be dismissed or snoozed.
	 */
	public function test_copy_and_state_match_the_client(): void {
		$json = $this->create_task( array( 'account_id' => 'acct_test' ), true )->get_json();

		$this->assertSame( 'go-live-payments', $json['id'] );
		$this->assertSame( 'Activate payments', $json['title'] );
		$this->assertSame( '10 minutes', $json['time'] );
		$this->assertSame( '', $json['content'] );
		$this->assertSame( 3, $json['level'] );
		$this->assertNull( $json['actionUrl'] );
		$this->assertFalse( $json['isComplete'] );
		$this->assertFalse( $json['isDismissable'] );
		$this->assertFalse( $json['isSnoozeable'] );
	}

	/**
	 * @testdox The Home task registrar adds the go-live task to the extended list.
	 */
	public function test_registrar_adds_the_task_to_the_extended_list(): void {
		$sut = $this->create_registrar( $this->create_account_service( array( 'account_id' => 'acct_test' ), true ) );

		$sut->add_tasks();

		$task = TaskLists::get_list( 'extended' )->get_task( WooPaymentsGoLiveTask::ID );
		$this->assertInstanceOf( WooPaymentsGoLiveTask::class, $task );
		$this->assertTrue( $task->get_json()['canView'] );
	}

	/**
	 * Create the task over the given account data.
	 *
	 * @param array<string,mixed> $account              Cached account data.
	 * @param bool                $test_mode_onboarding Whether WooPayments onboards in test mode.
	 * @return WooPaymentsGoLiveTask
	 */
	private function create_task( array $account, bool $test_mode_onboarding ): WooPaymentsGoLiveTask {
		return new WooPaymentsGoLiveTask( new TaskList( array( 'id' => 'extended' ) ), $this->create_account_service( $account, $test_mode_onboarding ) );
	}

	/**
	 * Create an account service double.
	 *
	 * @param array<string,mixed> $account              Cached account data.
	 * @param bool                $test_mode_onboarding Whether WooPayments onboards in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $account, bool $test_mode_onboarding ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_preserved_account_data_snapshot', 'is_test_mode_onboarding_enabled' ) )
			->getMock();
		$account_service->method( 'get_preserved_account_data_snapshot' )->willReturn( $account );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode_onboarding );

		return $account_service;
	}

	/**
	 * Create the Home task registrar.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsHomeTasks
	 */
	private function create_registrar( WooPaymentsAccountService $account_service ): WooPaymentsHomeTasks {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );

		$registrar = new WooPaymentsHomeTasks();
		$registrar->init( $arbiter, $account_service, $this->createMock( WooPaymentsAdminMenuBadgeService::class ) );

		return $registrar;
	}
}
