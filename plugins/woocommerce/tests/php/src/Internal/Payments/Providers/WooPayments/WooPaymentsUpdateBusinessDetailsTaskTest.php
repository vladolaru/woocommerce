<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesTask;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGoLiveTask;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHomeTasks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsUpdateBusinessDetailsTask;
use WC_Unit_Test_Case;

/**
 * Tests for the WC Home task that asks a restricted WooPayments account to update its business details.
 *
 * Expected values come from client 11.1.0 `class-wc-payments-admin.php:1222-1231`, `class-wc-payments-account.php:350-388`
 * and `client/overview/task-list/tasks/update-business-details-task.tsx`.
 */
class WooPaymentsUpdateBusinessDetailsTaskTest extends WC_Unit_Test_Case {

	/**
	 * 5pm Oct 5, 2026 UTC.
	 */
	private const DEADLINE = 1791219600;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'timezone_string', 'UTC' );
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
					static fn( $task ): bool => ! $task instanceof WooPaymentsUpdateBusinessDetailsTask && ! $task instanceof WooPaymentsDisputesTask && ! $task instanceof WooPaymentsGoLiveTask
				)
			);
		}

		parent::tearDown();
	}

	/**
	 * @testdox The task shows only for restricted-soon accounts with a deadline and restricted accounts with overdue requirements.
	 * @dataProvider provide_visibility_cases
	 *
	 * @param array<string,mixed> $account  Cached account data.
	 * @param bool                $expected Whether the task shows.
	 */
	public function test_visibility_follows_the_client_conditions( array $account, bool $expected ): void {
		$this->assertSame( $expected, $this->create_task( $account )->can_view() );
	}

	/**
	 * Visibility cases.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function provide_visibility_cases(): array {
		return array(
			'restricted soon with a deadline'            => array( $this->account( 'restricted_soon', array( 'current_deadline' => self::DEADLINE ) ), true ),
			'restricted soon without a deadline'         => array( $this->account( 'restricted_soon' ), false ),
			'restricted with overdue requirements'       => array( $this->account( 'restricted', array( 'has_overdue_requirements' => true ) ), true ),
			'restricted without overdue requirements'    => array( $this->account( 'restricted', array( 'current_deadline' => self::DEADLINE ) ), false ),
			'complete'                                   => array( $this->account( 'complete', array( 'current_deadline' => self::DEADLINE ) ), false ),
			'status data error without payments_enabled' => array(
				array(
					'status'                   => 'restricted',
					'has_overdue_requirements' => true,
				),
				false,
			),
			'no account'                                 => array( array(), false ),
		);
	}

	/**
	 * @testdox The task is hidden from users who cannot manage WooCommerce.
	 */
	public function test_hidden_without_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$this->assertFalse( $this->create_task( $this->account( 'restricted', array( 'has_overdue_requirements' => true ) ) )->can_view() );
	}

	/**
	 * @testdox A restricted-soon account gets the update title, the deadline sentence and the login link with the client's attribution.
	 */
	public function test_restricted_soon_copy_and_login_link(): void {
		$task = $this->create_task( $this->account( 'restricted_soon', array( 'current_deadline' => self::DEADLINE ) ) );

		$this->assertSame( 'update-business-details', $task->get_id() );
		$this->assertSame( 'Update WooPayments business details', $task->get_title() );
		$this->assertSame( 'Update by 5pm Oct 5, 2026 to avoid a disruption in payouts.', $task->get_content() );
		$this->assertSame( 'Update', $task->get_action_label() );
		$this->assertFalse( $task->is_complete() );
		$this->assertFalse( $task->is_dismissable() );

		$query = $this->get_query_args( $task->get_action_url() );
		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( '/woopayments/overview', $query['path'] );
		$this->assertSame( '1', $query['wcpay-login'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'wcpay-login' ) );
		$this->assertSame( 'WCPAY_OVERVIEW', $query['from'] );
		$this->assertSame( 'wcpay-update-business-details-task', $query['source'] );
	}

	/**
	 * @testdox A single requirement error leads the restricted-soon content, and the ignored generic error does not count.
	 */
	public function test_single_error_prefixes_the_deadline_sentence(): void {
		$task = $this->create_task(
			$this->account(
				'restricted_soon',
				array(
					'current_deadline' => self::DEADLINE,
					'requirements'     => array(
						'errors' => array(
							array(
								'code'   => 'invalid_value_other',
								'reason' => 'Generic.',
							),
							array(
								'code'   => 'invalid_street_address',
								'reason' => 'The street could not be validated.',
							),
						),
					),
				)
			)
		);

		$this->assertSame( 'The street could not be validated. Update by 5pm Oct 5, 2026 to avoid a disruption in payouts.', $task->get_content() );
		$this->assertSame( 'Update', $task->get_action_label() );
	}

	/**
	 * @testdox Several requirement errors lead to "More details" on Overview, where the task opens the details modal.
	 */
	public function test_multiple_errors_go_to_overview(): void {
		$task = $this->create_task(
			$this->account(
				'restricted',
				array(
					'has_overdue_requirements' => true,
					'requirements'             => array(
						'errors' => array(
							array(
								'code'   => 'invalid_street_address',
								'reason' => 'Street.',
							),
							array(
								'code'   => 'verification_document_expired',
								'reason' => 'Expired.',
							),
						),
					),
				)
			)
		);

		$this->assertSame( 'More details', $task->get_action_label() );
		$this->assertSame( 'Payments and payouts are disabled for this account until missing business information is updated.', $task->get_content() );
		$query = $this->get_query_args( $task->get_action_url() );
		$this->assertSame( '/woopayments/overview', $query['path'] );
		$this->assertArrayNotHasKey( 'wcpay-login', $query );
	}

	/**
	 * @testdox A past-due account that never submitted its details gets the finish-setup task pointing at onboarding.
	 */
	public function test_unsubmitted_past_due_account_finishes_setup(): void {
		$task = $this->create_task(
			$this->account(
				'restricted',
				array(
					'has_overdue_requirements' => true,
					'details_submitted'        => false,
				)
			)
		);

		$this->assertSame( 'complete-setup', $task->get_id() );
		$this->assertSame( 'Finish setting up WooPayments', $task->get_title() );
		$this->assertSame( 'Payments and payouts are disabled for this account until setup is completed.', $task->get_content() );
		$this->assertSame( 'Finish setup', $task->get_action_label() );
		$query = $this->get_query_args( $task->get_action_url() );
		$this->assertSame( '/woopayments/onboarding', $query['path'] );
		$this->assertSame( 'wcpay-finish-setup-task', $query['source'] );
		$this->assertSame( 'WCPAY_OVERVIEW', $query['from'] );
	}

	/**
	 * @testdox The Home task hook registers only while native owns the runtime and adds the task to the extended list.
	 */
	public function test_registrar_adds_the_task_to_the_extended_list(): void {
		$account_service = $this->create_account_service( $this->account( 'restricted', array( 'has_overdue_requirements' => true ) ) );

		$dormant = $this->create_registrar( false, $account_service );
		$dormant->register();
		$this->assertFalse( has_action( 'init', array( $dormant, 'add_tasks' ) ) );

		$sut = $this->create_registrar( true, $account_service );
		$sut->register();
		$this->assertNotFalse( has_action( 'init', array( $sut, 'add_tasks' ) ) );
		remove_action( 'init', array( $sut, 'add_tasks' ) );

		$sut->add_tasks();
		$task = TaskLists::get_list( 'extended' )->get_task( 'update-business-details' );
		$this->assertInstanceOf( WooPaymentsUpdateBusinessDetailsTask::class, $task );
		$json = $task->get_json();
		$this->assertTrue( $json['canView'] );
		$this->assertSame( 'Update WooPayments business details', $json['title'] );
	}

	/**
	 * Build cached account data.
	 *
	 * @param string              $status Account status.
	 * @param array<string,mixed> $extra  Extra fields.
	 * @return array<string,mixed>
	 */
	private function account( string $status, array $extra = array() ): array {
		return array_merge(
			array(
				'account_id'        => 'acct_test',
				'status'            => $status,
				'payments_enabled'  => true,
				'details_submitted' => true,
			),
			$extra
		);
	}

	/**
	 * Create the task over the given account data.
	 *
	 * @param array<string,mixed> $account Cached account data.
	 * @return WooPaymentsUpdateBusinessDetailsTask
	 */
	private function create_task( array $account ): WooPaymentsUpdateBusinessDetailsTask {
		return new WooPaymentsUpdateBusinessDetailsTask( new TaskList( array( 'id' => 'extended' ) ), $this->create_account_service( $account ) );
	}

	/**
	 * Create an account service double.
	 *
	 * @param array<string,mixed> $account Cached account data.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $account ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_preserved_account_data_snapshot' ) )
			->getMock();
		$account_service->method( 'get_preserved_account_data_snapshot' )->willReturn( $account );

		return $account_service;
	}

	/**
	 * Create the Home task registrar.
	 *
	 * @param bool                      $native_register Whether native owns the runtime.
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsHomeTasks
	 */
	private function create_registrar( bool $native_register, WooPaymentsAccountService $account_service ): WooPaymentsHomeTasks {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$registrar = new WooPaymentsHomeTasks();
		$registrar->init( $arbiter, $account_service, $this->createMock( WooPaymentsAdminMenuBadgeService::class ) );

		return $registrar;
	}

	/**
	 * Parse a URL's query args.
	 *
	 * @param string $url URL.
	 * @return array<string,string>
	 */
	private function get_query_args( string $url ): array {
		$this->assertStringStartsWith( admin_url( 'admin.php?' ), $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}
}
