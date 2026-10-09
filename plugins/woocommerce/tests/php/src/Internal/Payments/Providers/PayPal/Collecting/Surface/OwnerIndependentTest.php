<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\FirstOrderEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\HeldPaymentReturnedEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\InboxNote;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\SetUpPayPalWalletTask;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the registration of the surfaces that work whoever owns the wallet.
 *
 * @group paypal-wallet
 */
class OwnerIndependentTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var OwnerIndependent
	 */
	private $sut;

	/**
	 * The SQL the test recorded.
	 *
	 * @var string[]
	 */
	private array $queries = array();

	/**
	 * The filter that records SQL, while one is attached.
	 *
	 * @var callable|null
	 */
	private $recorder = null;

	/**
	 * Make sure the Home task lists exist, and build the registrar.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( null === TaskLists::get_list( 'extended' ) ) {
			TaskLists::init_default_lists();
		}
		$this->remove_task();
		$this->sut = new OwnerIndependent();
	}

	/**
	 * Take the task and the note out again: the task lists are static and the notes table is not rolled back everywhere.
	 */
	public function tearDown(): void {
		try {
			$this->remove_task();
			InboxNote::possibly_delete_note();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Remove the setup task from the extended list.
	 */
	private function remove_task(): void {
		$list        = TaskLists::get_list( 'extended' );
		$list->tasks = array_values(
			array_filter(
				$list->tasks,
				static function ( $task ): bool {
					return ! $task instanceof SetUpPayPalWalletTask;
				}
			)
		);
	}

	/**
	 * The IDs of the extended list's tasks, in the order the list holds them.
	 *
	 * @return string[]
	 */
	private function extended_task_ids(): array {
		return array_map(
			static function ( $task ): string {
				return $task->get_id();
			},
			TaskLists::get_list( 'extended' )->tasks
		);
	}

	/**
	 * Record every SQL statement from now on.
	 */
	private function record_queries(): void {
		$this->queries  = array();
		$this->recorder = function ( $sql ) {
			$this->queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $this->recorder );
	}

	/**
	 * Stop recording SQL, so the assertions that follow are not recorded.
	 */
	private function stop_recording_queries(): void {
		remove_filter( 'query', $this->recorder );
		$this->recorder = null;
	}

	/**
	 * The notes saved under the note's name.
	 *
	 * @return int[]
	 */
	private function note_ids(): array {
		return Notes::load_data_store()->get_notes_with_name( InboxNote::NOTE_NAME );
	}

	/**
	 * @testdox Should hook the task, the note check and the emails.
	 */
	public function test_register_hooks_the_surfaces(): void {
		$this->set_first_order( 7 );

		$this->sut->register();

		$this->assertSame( 10, has_action( 'init', array( $this->sut, 'register_task' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'sync_note' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_paypal_wallet_first_order', array( $this->sut, 'handle_woocommerce_paypal_wallet_first_order' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_email_classes', array( $this->sut, 'register_emails' ) ) );
		$this->assertSame( 30, has_action( 'woocommerce_paypal_wallet_capture_pending', array( $this->sut, 'handle_woocommerce_paypal_wallet_capture_pending' ) ), 'After the collecting module records the held capture at 10' );
		foreach ( array( Options::PLATFORM, 'woocommerce-ppcp-data-common' ) as $option ) {
			$this->assertSame( 10, has_action( 'add_option_' . $option, array( $this->sut, 'handle_connection_change' ) ) );
			$this->assertSame( 10, has_action( 'update_option_' . $option, array( $this->sut, 'handle_connection_change' ) ) );
		}
	}

	/**
	 * @testdox Should attach no hook on a store that never had a wallet order, and hook once a collecting state, a platform connection or a first order exists.
	 * @testWith ["none", false]
	 *           ["collecting", true]
	 *           ["platform", true]
	 *           ["first_order", true]
	 *
	 * @param string $history  What the store has.
	 * @param bool   $attached Whether the surfaces hook.
	 */
	public function test_register_attaches_nothing_without_wallet_history( string $history, bool $attached ): void {
		if ( 'collecting' === $history ) {
			$this->set_collecting();
		} elseif ( 'platform' === $history ) {
			$this->set_platform_connected();
		} elseif ( 'first_order' === $history ) {
			$this->set_first_order( 7 );
		}

		$this->sut->register();

		$this->assertSame( $attached, (bool) has_action( 'init', array( $this->sut, 'register_task' ) ) );
		$this->assertSame( $attached, (bool) has_action( 'admin_init', array( $this->sut, 'sync_note' ) ) );
		$this->assertSame( $attached, (bool) has_action( 'woocommerce_paypal_wallet_first_order', array( $this->sut, 'handle_woocommerce_paypal_wallet_first_order' ) ) );
		$this->assertSame( $attached, (bool) has_filter( 'woocommerce_email_classes', array( $this->sut, 'register_emails' ) ) );
	}

	/**
	 * @testdox Should put the task first on the extended list when a first order exists, held order or not, and only once.
	 */
	public function test_registers_the_task_first_on_the_extended_list(): void {
		$this->set_first_order( 7 );
		$this->held_order();
		$before = $this->extended_task_ids();

		$this->sut->register_task();
		$this->sut->register_task();

		$this->assertSame( array_merge( array( 'wc-paypal-wallet-setup' ), $before ), $this->extended_task_ids() );
		$this->assertSame( 'extended', TaskLists::get_task( 'wc-paypal-wallet-setup' )->get_parent_id(), 'The task knows its list' );
	}

	/**
	 * @testdox Should register the task for a collecting or platform-connected store even before a first order is recorded.
	 * @testWith ["collecting"]
	 *           ["platform"]
	 *
	 * @param string $state The state.
	 */
	public function test_registers_the_task_for_a_store_with_wallet_state( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} else {
			$this->set_platform_connected();
		}

		$this->sut->register_task();

		$this->assertContains( 'wc-paypal-wallet-setup', $this->extended_task_ids() );
	}

	/**
	 * Whether a statement reads the order tables, the notes table or the options of the wallet's history.
	 *
	 * @param string $sql The statement.
	 * @return bool
	 */
	private function touches_orders_notes_or_history_options( string $sql ): bool {
		global $wpdb;
		$needles = array(
			$wpdb->posts,
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_admin_notes',
			Options::FIRST_ORDER,
			Options::COLLECTING,
			Options::PLATFORM,
		);
		foreach ( $needles as $needle ) {
			if ( str_contains( $sql, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run the registration and every callback it would hook, as the given kind of request would.
	 *
	 * The context only shapes the request the test set up; the callbacks are the same ones each request would run.
	 */
	private function run_registration_in_context(): void {
		$this->sut->register();
		$this->sut->register_task();
		$this->sut->sync_note();
		$this->sut->register_emails( array() );
		$this->sut->handle_woocommerce_paypal_wallet_first_order( null, null );
		$this->sut->handle_connection_change();
		$this->sut->handle_woocommerce_paypal_wallet_capture_pending( null, null );
	}

	/**
	 * @testdox Should attach nothing and run no query at all on a store that never had a wallet order: $context request.
	 * @testWith ["frontend"]
	 *           ["admin"]
	 *           ["rest"]
	 *           ["cron"]
	 *           ["ajax"]
	 *
	 * @param string $context The kind of request.
	 */
	public function test_costs_nothing_on_a_store_without_wallet_history( string $context ): void {
		$restore_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saving test state.
		if ( 'admin' === $context ) {
			$this->simulate_admin_request( array() );
		} elseif ( 'rest' === $context ) {
			$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wc/v3/orders'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Simulating a REST request.
		} elseif ( 'cron' === $context ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		} elseif ( 'ajax' === $context ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}
		wp_load_alloptions(); // WordPress loads the autoloaded options once per request, before any plugin code.
		$this->record_queries();

		try {
			$this->run_registration_in_context();
		} finally {
			$this->stop_recording_queries();
			if ( null === $restore_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $restore_uri; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restoring test state.
			}
		}

		$this->assertSame( array(), $this->queries, 'No query of any kind' );
		$this->assertFalse( has_action( 'init', array( $this->sut, 'register_task' ) ), 'Nothing is attached' );
		$this->assertNotContains( 'wc-paypal-wallet-setup', $this->extended_task_ids() );
		$this->assertSame( array(), $this->note_ids() );
	}

	/**
	 * @testdox Should register the task without querying orders, the notes table or the history options: the held-order query runs only when the task is read.
	 */
	public function test_registering_the_task_runs_no_order_query(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		wp_load_alloptions();
		$this->record_queries();

		$this->sut->register();
		$this->sut->register_task();
		$this->stop_recording_queries();

		$this->assertContains( 'wc-paypal-wallet-setup', $this->extended_task_ids() );
		foreach ( $this->queries as $sql ) {
			$this->assertFalse( $this->touches_orders_notes_or_history_options( $sql ), "Unexpected query: $sql" );
		}
	}

	/**
	 * @testdox Should read the held orders only when the task is read, and then with a query on the order tables.
	 */
	public function test_reading_the_task_does_query_the_orders(): void {
		$this->set_platform_connected();
		$this->set_first_order( 7 );
		$this->sut->register_task();
		$task = TaskLists::get_task( 'wc-paypal-wallet-setup' );
		wp_load_alloptions();
		$this->record_queries();

		$task->is_complete();
		$this->stop_recording_queries();

		$touching = array_filter( $this->queries, array( $this, 'touches_orders_notes_or_history_options' ) );
		$this->assertNotEmpty( $touching, 'The probe sees an order query when there is one, so the checks above can fail' );
	}

	/**
	 * @testdox Should add both emails to the list, keyed by class, and leave a non-array alone.
	 */
	public function test_registers_the_emails(): void {
		$this->set_first_order( 7 );

		$emails = $this->sut->register_emails( array( 'WC_Email_New_Order' => 'x' ) );

		$this->assertSame( array( 'WC_Email_New_Order', FirstOrderEmail::class, HeldPaymentReturnedEmail::class ), array_keys( $emails ) );
		$this->assertInstanceOf( FirstOrderEmail::class, $emails[ FirstOrderEmail::class ] );
		$this->assertInstanceOf( HeldPaymentReturnedEmail::class, $emails[ HeldPaymentReturnedEmail::class ] );
		$this->assertSame( 'oops', $this->sut->register_emails( 'oops' ) );
	}

	/**
	 * @testdox Should add the note on the first-order action, once, and not again when the action fires again.
	 */
	public function test_the_first_order_action_adds_the_note_once(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$order = $this->held_order();
		$state = new CollectingState( new Options(), new HeldOrders() );

		$this->sut->handle_woocommerce_paypal_wallet_first_order( $order, $state );
		$this->sut->handle_woocommerce_paypal_wallet_first_order( $order, $state );

		$this->assertCount( 1, $this->note_ids() );
	}

	/**
	 * @testdox Should add the note on admin_init for a store that has a first order and is not connected, and only once.
	 */
	public function test_admin_init_adds_the_note_once(): void {
		$this->set_first_order( 7 );

		$this->sut->sync_note();
		$this->sut->sync_note();

		$this->assertCount( 1, $this->note_ids() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox Should action the note on admin_init once the store is platform connected, whoever owns the wallet.
	 */
	public function test_admin_init_actions_the_note_when_setup_completes(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->sync_note();

		$this->set_platform_connected();
		$this->sut->sync_note();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
		$this->assertCount( 1, $this->note_ids(), 'Completion adds no second note' );
	}

	/**
	 * @testdox Should not add the note again after it was actioned and the store is connected.
	 */
	public function test_a_connected_store_gets_no_new_note(): void {
		$this->set_platform_connected();
		$this->set_first_order( 7 );

		$this->sut->sync_note();

		$this->assertSame( array(), $this->note_ids() );
	}

	/**
	 * @testdox Should leave the notes table alone on admin_init once the note is actioned, or while a note that was added waits.
	 */
	public function test_admin_init_runs_no_notes_query_once_settled(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->sync_note();
		$this->record_queries();

		$this->sut->sync_note();
		$this->stop_recording_queries();
		$this->assertSame( array(), $this->notes_queries(), 'A note that was added needs no query while collecting' );

		$this->set_platform_connected();
		$this->sut->sync_note();
		$this->assertSame( Options::NOTE_ACTIONED, ( new Options() )->note_state() );

		$this->record_queries();
		$this->sut->sync_note();
		$this->stop_recording_queries();

		$this->assertSame( array(), $this->notes_queries(), 'A connected store whose note is actioned runs no notes query' );
	}

	/**
	 * The recorded statements that read the notes table.
	 *
	 * @return string[]
	 */
	private function notes_queries(): array {
		global $wpdb;

		return array_values(
			array_filter(
				$this->queries,
				static function ( string $sql ) use ( $wpdb ): bool {
					return str_contains( $sql, $wpdb->prefix . 'wc_admin_notes' );
				}
			)
		);
	}

	/**
	 * @testdox Should skip AJAX requests, Heartbeat among them, on admin_init.
	 */
	public function test_admin_init_skips_ajax(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->record_queries();

		$this->sut->sync_note();
		$this->stop_recording_queries();

		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->note_ids() );
	}

	/**
	 * @testdox Should action the note when the platform option is written by complete(), without waiting for admin_init.
	 */
	public function test_completing_setup_actions_the_note(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->register();
		$this->sut->handle_woocommerce_paypal_wallet_first_order();
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );

		( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) )->complete( 'MERCHANT1' );

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
		$this->assertSame( Options::NOTE_ACTIONED, ( new Options() )->note_state() );
	}

	/**
	 * @testdox Should action the note when a first-party connection is saved.
	 */
	public function test_a_first_party_connection_actions_the_note(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->register();
		$this->sut->handle_woocommerce_paypal_wallet_first_order();

		$this->set_first_party_connected();

		$this->assertSame( Note::E_WC_ADMIN_NOTE_ACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
	}

	/**
	 * Dismiss the setup task through core's own dismissal.
	 *
	 * @return Task
	 */
	private function dismiss_the_task(): Task {
		$this->sut->register_task();
		$task = TaskLists::get_task( 'wc-paypal-wallet-setup' );
		$this->assertTrue( $task->dismiss() );
		$this->assertTrue( $task->is_dismissed() );

		return $task;
	}

	/**
	 * @testdox Should show a dismissed task again when PayPal holds a new order's payment while setup is open.
	 */
	public function test_a_new_held_order_brings_a_dismissed_task_back(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->register();
		$task = $this->dismiss_the_task();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $this->held_order(), null );

		$this->assertFalse( $task->is_dismissed() );
		$this->assertNotContains( 'wc-paypal-wallet-setup', (array) get_option( Task::DISMISSED_OPTION, array() ) );
	}

	/**
	 * @testdox Should leave the task dismissed for an order PayPal does not hold, for a first-party connected store and for something that is not an order.
	 * @testWith ["not_held"]
	 *           ["first_party"]
	 *           ["not_an_order"]
	 *
	 * @param string $scenario The scenario.
	 */
	public function test_the_dismissal_stays_when_nothing_new_is_waiting( string $scenario ): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sut->register();
		$task  = $this->dismiss_the_task();
		$order = wc_create_order();
		if ( 'first_party' === $scenario ) {
			$this->set_first_party_connected();
			$order = $this->held_order();
		} elseif ( 'not_an_order' === $scenario ) {
			$order = null;
		}

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, null );

		$this->assertTrue( $task->is_dismissed() );
	}

	/**
	 * @testdox Should log a failure of the first-order note and let the next listener run.
	 */
	public function test_a_failing_note_is_logged_and_does_not_stop_the_next_listener(): void {
		global $wpdb;
		$this->set_collecting();
		$this->set_first_order( 7 );
		$logger  = new RecordingLogger();
		$sut     = new OwnerIndependent( null, $logger );
		$reached = false;
		add_action( 'woocommerce_paypal_wallet_first_order', array( $sut, 'handle_woocommerce_paypal_wallet_first_order' ), 10, 2 );
		add_action(
			'woocommerce_paypal_wallet_first_order',
			static function () use ( &$reached ): void {
				$reached = true;
			},
			20
		);
		$thrower = static function ( $sql ) use ( $wpdb ) {
			if ( str_contains( (string) $sql, $wpdb->prefix . 'wc_admin_notes' ) ) {
				throw new \RuntimeException( 'notes are broken' );
			}

			return $sql;
		};
		add_filter( 'query', $thrower );

		do_action( 'woocommerce_paypal_wallet_first_order', null, null );

		remove_filter( 'query', $thrower );

		$this->assertTrue( $reached, 'The listener after the note still runs' );
		$this->assertCount( 1, $logger->records );
		$this->assertSame( 'warning', $logger->records[0]['level'] );
		$this->assertStringContainsString( 'notes are broken', $logger->records[0]['message'] );
	}
}
