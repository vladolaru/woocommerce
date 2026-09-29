<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesTask;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHomeTasks;
use WC_Unit_Test_Case;

/**
 * Tests for the WC Home task that asks the merchant to respond to disputes due within a week.
 *
 * Expected values come from client 11.1.0 `includes/admin/tasks/class-wc-payments-task-disputes.php` and its
 * `tests/unit/admin/tasks/test-class-wc-payments-task-disputes.php`.
 */
class WooPaymentsDisputesTaskTest extends WC_Unit_Test_Case {

	private const CACHE_KEY = 'wcpay_active_dispute_cache';

	/**
	 * Fake platform API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private $api_client;

	/**
	 * Outbound HTTP requests seen during the test.
	 *
	 * @var int
	 */
	private int $outbound_requests = 0;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'timezone_string', 'UTC' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->api_client = $this->create_api_client();
		add_filter(
			'pre_http_request',
			function ( $pre ) {
				++$this->outbound_requests;
				return $pre;
			}
		);
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
					static fn( $task ): bool => ! $task instanceof WooPaymentsDisputesTask
				)
			);
		}

		parent::tearDown();
	}

	/**
	 * @testdox The task shows only while a dispute awaiting a response is due within 7 days.
	 * @dataProvider provide_visibility_cases
	 *
	 * @param array<int,array<string,mixed>>|null $disputes Cached disputes, or null for an errored empty cache.
	 * @param bool                                $expected Whether the task shows.
	 */
	public function test_visibility_follows_the_client_due_window( ?array $disputes, bool $expected ): void {
		$this->assertSame( $expected, $this->create_task( $disputes )->can_view() );
	}

	/**
	 * Visibility cases.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>|null,1:bool}>
	 */
	public function provide_visibility_cases(): array {
		return array(
			'no active disputes'                 => array( array(), false ),
			'errored cache without data'         => array( null, false ),
			'single dispute due in 9 days'       => array( array( $this->dispute( 2000, 'eur', '+9 days' ) ), false ),
			'dispute already past due'           => array( array( $this->dispute( 2000, 'eur', '-1 hour' ) ), false ),
			'dispute without a due date'         => array( array( $this->dispute( 2000, 'eur', null ) ), false ),
			'single dispute due in 6 days'       => array( array( $this->dispute( 2000, 'eur', '+6 days +1 hour' ) ), true ),
			'single dispute due in 7 whole days' => array( array( $this->dispute( 2000, 'eur', '+7 days +1 hour' ) ), true ),
			'dispute due in 23 hours'            => array( array( $this->dispute( 2000, 'eur', '+23 hours' ) ), true ),
			'one due soon and one due in 9 days' => array(
				array(
					$this->dispute( 2000, 'eur', '+3 days +1 hour' ),
					$this->dispute( 1000, 'eur', '+9 days' ),
				),
				true,
			),
		);
	}

	/**
	 * @testdox The task is hidden for users without manage_woocommerce and for accounts the client treats as invalid.
	 * @dataProvider provide_invalid_account_cases
	 *
	 * @param array<string,mixed> $account Cached account data.
	 * @param string              $role    Current user role.
	 */
	public function test_hidden_for_invalid_accounts_and_users( array $account, string $role ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => $role ) ) );
		$sut = $this->create_task( array( $this->dispute( 2000, 'eur', '+23 hours' ) ), $account );

		$this->assertFalse( $sut->can_view() );
	}

	/**
	 * Invalid account and user cases.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function provide_invalid_account_cases(): array {
		return array(
			'customer user'               => array( $this->account(), 'customer' ),
			'no account'                  => array( array(), 'administrator' ),
			'details not submitted'       => array( $this->account( array( 'details_submitted' => false ) ), 'administrator' ),
			'card payments unrequested'   => array( $this->account( array( 'capabilities' => array( 'card_payments' => 'unrequested' ) ) ), 'administrator' ),
			'no card payments capability' => array( $this->account( array( 'capabilities' => array() ) ), 'administrator' ),
		);
	}

	/**
	 * @testdox A single dispute due within 7 days names its amount and date and links to its transaction.
	 */
	public function test_single_dispute_within_7_days(): void {
		$sut = $this->create_task(
			array(
				$this->dispute( 2000, 'eur', '+6 days +1 hour', 'ch_single' ),
				$this->dispute( 1000, 'eur', '+9 days', 'ch_later' ),
			)
		);
		$due = strtotime( '+6 days +1 hour' );

		$this->assertSame( 'woocommerce_payments_disputes_task', $sut->get_id() );
		$this->assertSame( 'Respond to a dispute for 20,00 €', $sut->get_title() );
		$this->assertSame( 'By ' . gmdate( 'F j, Y', $due ) . ' – 6 days left to respond', $sut->get_additional_info() );
		$this->assertSame( '', $sut->get_content() );

		$query = $this->get_query_args( $sut->get_action_url() );
		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( '/woopayments/transactions/details', $query['path'] );
		$this->assertSame( 'ch_single', $query['id'] );
	}

	/**
	 * @testdox A single dispute due within a day says it is the last day and gives the due time in the store timezone.
	 */
	public function test_single_dispute_within_24_hours_uses_the_store_timezone(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$sut = $this->create_task( array( $this->dispute( 2000, 'eur', '+12 hours' ) ) );
		$due = strtotime( '+12 hours' );

		$this->assertSame( 'Respond to a dispute for 20,00 € – Last day', $sut->get_title() );
		$this->assertSame( 'Respond today by ' . gmdate( 'g:i a', $due + 9 * HOUR_IN_SECONDS ), $sut->get_additional_info() );
	}

	/**
	 * @testdox A dispute due in one whole day still counts as the last day, as the client compares whole days.
	 */
	public function test_dispute_due_in_one_whole_day_is_the_last_day(): void {
		$sut = $this->create_task( array( $this->dispute( 2000, 'eur', '+1 day +1 hour' ) ) );

		$this->assertSame( 'Respond to a dispute for 20,00 € – Last day', $sut->get_title() );
	}

	/**
	 * @testdox A zero-decimal currency amount is not divided by 100.
	 */
	public function test_zero_decimal_currency_amount(): void {
		$sut = $this->create_task( array( $this->dispute( 5000, 'jpy', '+3 days +1 hour' ) ) );

		$this->assertSame( 'Respond to a dispute for ¥5,000', $sut->get_title() );
	}

	/**
	 * @testdox Several due disputes in one currency show the count and total of every active dispute, and link to the disputes awaiting a response.
	 */
	public function test_multiple_disputes_within_7_days(): void {
		$sut = $this->create_task(
			array(
				$this->dispute( 2000, 'eur', '+6 days +1 hour' ),
				$this->dispute( 1234, 'eur', '+3 days +1 hour' ),
				$this->dispute( 1000, 'eur', '+9 days' ),
			)
		);

		$this->assertSame( 'Respond to 3 active disputes for a total of 42,34 €', $sut->get_title() );
		$this->assertSame( 'Last week to respond to 2 of the disputes', $sut->get_additional_info() );

		$query = $this->get_query_args( $sut->get_action_url() );
		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( 'checkout', $query['tab'] );
		$this->assertSame( '/woopayments/disputes', $query['path'] );
		$this->assertSame( 'awaiting_response', $query['filter'] );
		$this->assertArrayNotHasKey( 'id', $query );
	}

	/**
	 * @testdox Several disputes in more than one currency count every active dispute and name those due within a day.
	 */
	public function test_multiple_disputes_in_several_currencies_within_24_hours(): void {
		$sut = $this->create_task(
			array(
				$this->dispute( 2000, 'eur', '+23 hours' ),
				$this->dispute( 1234, 'usd', '+23 hours' ),
				$this->dispute( 500, 'usd', '+3 days +1 hour' ),
				$this->dispute( 1000, 'usd', '+9 days' ),
			)
		);

		$this->assertSame( 'Respond to 4 active disputes', $sut->get_title() );
		$this->assertSame( 'Final day to respond to 2 of the disputes', $sut->get_additional_info() );
	}

	/**
	 * @testdox The task never completes and cannot be dismissed.
	 */
	public function test_completion_and_dismissal(): void {
		$sut = $this->create_task( array( $this->dispute( 2000, 'eur', '+23 hours' ) ) );

		$this->assertFalse( $sut->is_complete() );
		$this->assertFalse( $sut->is_dismissable() );
		$this->assertSame( '', $sut->get_time() );
	}

	/**
	 * @testdox Rendering the task from a fresh cache makes no platform request.
	 */
	public function test_render_reads_the_cache_without_a_platform_request(): void {
		$json = $this->create_task( array( $this->dispute( 1234, 'usd', '+3 days +1 hour' ) ) )->get_json();

		$this->assertTrue( $json['canView'] );
		$this->assertSame( 'Respond to a dispute for $12.34', $json['title'] );
		$this->assertSame( 0, $this->api_client->dispute_list_calls, 'A fresh cache must not call the platform.' );
		$this->assertSame( 0, $this->outbound_requests, 'Rendering must not send any HTTP request.' );
	}

	/**
	 * @testdox A missing cache is filled once with the client's dispute query, sorted by due date, and reused afterwards.
	 */
	public function test_missing_cache_is_filled_once_with_the_client_query(): void {
		$this->api_client->disputes = array(
			$this->dispute( 1000, 'usd', '+5 days', 'ch_late' ),
			$this->dispute( 2000, 'usd', '+2 days', 'ch_soon' ),
		);

		$this->assertSame( 'Respond to 2 active disputes for a total of $30.00', $this->create_task( null, null, false )->get_title() );
		$this->assertSame( 1, $this->api_client->dispute_list_calls );
		$this->assertSame(
			array(
				'pagesize' => 50,
				'search'   => array( 'warning_needs_response', 'needs_response' ),
			),
			$this->api_client->last_filters
		);
		$this->assertSame( array( 'ch_soon', 'ch_late' ), array_column( get_option( self::CACHE_KEY )['data'], 'charge_id' ) );

		$this->create_task( null, null, false )->get_json();
		$this->assertSame( 1, $this->api_client->dispute_list_calls, 'A second render must reuse the cache.' );
	}

	/**
	 * @testdox The Home registrar adds the disputes task to the extended list.
	 */
	public function test_registrar_adds_the_task_to_the_extended_list(): void {
		$this->seed_cache( array( $this->dispute( 2000, 'eur', '+23 hours' ) ) );
		$account_service = $this->create_account_service( $this->account() );
		$arbiter         = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$sut = new WooPaymentsHomeTasks();
		$sut->init( $arbiter, $account_service, $this->create_dispute_data_service( $account_service ) );
		$sut->add_tasks();

		$task = TaskLists::get_list( 'extended' )->get_task( 'woocommerce_payments_disputes_task' );
		$this->assertInstanceOf( WooPaymentsDisputesTask::class, $task );
		$this->assertSame( 'Respond to a dispute for 20,00 € – Last day', $task->get_json()['title'] );
	}

	/**
	 * Build a cached dispute in the client's shape.
	 *
	 * @param int         $amount    Amount in minor units.
	 * @param string      $currency  Currency code.
	 * @param string|null $due_by    Relative due time, or null for none.
	 * @param string      $charge_id Charge ID.
	 * @return array<string,mixed>
	 */
	private function dispute( int $amount, string $currency, ?string $due_by, string $charge_id = 'ch_2' ): array {
		return array(
			'dispute_id' => 'dp_2',
			'charge_id'  => $charge_id,
			'amount'     => $amount,
			'currency'   => $currency,
			'status'     => 'needs_response',
			'due_by'     => null === $due_by ? null : gmdate( 'Y-m-d H:i:s', (int) strtotime( $due_by ) ),
		);
	}

	/**
	 * Build cached account data the client treats as valid.
	 *
	 * @param array<string,mixed> $extra Extra fields.
	 * @return array<string,mixed>
	 */
	private function account( array $extra = array() ): array {
		return array_merge(
			array(
				'account_id'        => 'acct_test',
				'details_submitted' => true,
				'capabilities'      => array( 'card_payments' => 'active' ),
			),
			$extra
		);
	}

	/**
	 * Write a fresh active-disputes cache entry.
	 *
	 * @param array<int,array<string,mixed>>|null $disputes Disputes, or null for an errored entry without data.
	 */
	private function seed_cache( ?array $disputes ): void {
		update_option(
			self::CACHE_KEY,
			array(
				'data'    => $disputes,
				'fetched' => time(),
				'errored' => null === $disputes,
			),
			false
		);
	}

	/**
	 * Create the task over the given cached disputes and account.
	 *
	 * @param array<int,array<string,mixed>>|null $disputes   Cached disputes.
	 * @param array<string,mixed>|null            $account    Cached account data; defaults to a valid account.
	 * @param bool                                $seed_cache Whether to write the disputes cache first.
	 * @return WooPaymentsDisputesTask
	 */
	private function create_task( ?array $disputes, ?array $account = null, bool $seed_cache = true ): WooPaymentsDisputesTask {
		if ( $seed_cache ) {
			$this->seed_cache( $disputes );
		}

		$account_service = $this->create_account_service( $account ?? $this->account() );

		return new WooPaymentsDisputesTask( new TaskList( array( 'id' => 'extended' ) ), $account_service, $this->create_dispute_data_service( $account_service ) );
	}

	/**
	 * Create the real dispute data service over the fake API client.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsAdminMenuBadgeService
	 */
	private function create_dispute_data_service( WooPaymentsAccountService $account_service ): WooPaymentsAdminMenuBadgeService {
		$service = new WooPaymentsAdminMenuBadgeService();
		$service->init( $account_service, $this->api_client );

		return $service;
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
	 * Create a fake API client that records dispute list requests.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {
			/**
			 * Disputes the platform returns.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $disputes = array();

			/**
			 * Dispute list call count.
			 *
			 * @var int
			 */
			public int $dispute_list_calls = 0;

			/**
			 * Filters of the last dispute list call.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_filters = array();

			/**
			 * Constructor.
			 */
			public function __construct() {
			}

			/**
			 * Return the fake dispute list.
			 *
			 * @param array<string,mixed> $filters Filters.
			 * @return array<string,mixed>
			 */
			public function get_disputes( array $filters = array() ): array {
				++$this->dispute_list_calls;
				$this->last_filters = $filters;

				return array( 'data' => $this->disputes );
			}
		};
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
