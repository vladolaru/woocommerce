<?php
/**
 * WooPaymentsCutoverActionScheduler tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsCutoverActionScheduler.
 */
class WooPaymentsCutoverActionSchedulerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCutoverActionScheduler|null
	 */
	private ?WooPaymentsCutoverActionScheduler $sut = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( WooPaymentsCutoverActionScheduler::class ) ) {
			$this->sut = new WooPaymentsCutoverActionScheduler();
			$this->cleanup_actions();
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsCutoverActionScheduler ) {
			$this->cleanup_actions();
		}

		parent::tearDown();
	}

	/**
	 * @testdox Schedules one unique action with the canonical hook, group, generation, and attempt.
	 */
	public function test_schedule_persists_the_exact_action_identity_once(): void {
		$sut       = $this->require_sut();
		$timestamp = time() + HOUR_IN_SECONDS;

		$first_id  = $sut->schedule( $timestamp, 7, 3 );
		$second_id = $sut->schedule( $timestamp, 7, 3 );
		$action    = ActionScheduler::store()->fetch_action( $first_id );

		$this->assertGreaterThan( 0, $first_id, 'Scheduling should return the persisted Action Scheduler ID.' );
		$this->assertSame( $first_id, $second_id, 'Duplicate scheduling should return the existing action ID.' );
		$this->assertSame( 'woocommerce_woopayments_cutover_reconcile', $action->get_hook() );
		$this->assertSame( 'woocommerce_woopayments_cutover', $action->get_group() );
		$this->assertSame(
			array(
				'generation' => 7,
				'attempt'    => 3,
			),
			$action->get_args()
		);
		$this->assertSame( 1, $this->count_actions( 7, 3 ), 'The exact action identity must remain unique.' );
	}

	/**
	 * @testdox A running generation can schedule its next attempt because the canonical args change.
	 */
	public function test_schedule_allows_a_successor_for_a_running_attempt(): void {
		$sut        = $this->require_sut();
		$current_id = $sut->schedule( time(), 4, 1 );
		ActionScheduler::store()->log_execution( $current_id );

		$next_id = $sut->schedule( time() + MINUTE_IN_SECONDS, 4, 2 );

		$this->assertGreaterThan( 0, $next_id, 'A distinct monotonic attempt should not collide with the running action.' );
		$this->assertNotSame( $current_id, $next_id );
		$this->assertSame( 1, $this->count_actions( 4, 2 ) );
		ActionScheduler::store()->mark_complete( $current_id );
	}

	/**
	 * @testdox Finds and cancels only the exact generation and attempt.
	 */
	public function test_cancel_targets_only_the_exact_action_identity(): void {
		$sut       = $this->require_sut();
		$first_id  = $sut->schedule( time() + HOUR_IN_SECONDS, 9, 1 );
		$second_id = $sut->schedule( time() + HOUR_IN_SECONDS, 9, 2 );

		$this->assertSame( $first_id, $sut->get_scheduled_action_id( 9, 1 ) );
		$this->assertSame( $second_id, $sut->get_scheduled_action_id( 9, 2 ) );

		$sut->cancel( 9, 1 );

		$this->assertSame( 0, $sut->get_scheduled_action_id( 9, 1 ), 'The requested attempt should be canceled.' );
		$this->assertSame( $second_id, $sut->get_scheduled_action_id( 9, 2 ), 'A later attempt must remain scheduled.' );
	}

	/**
	 * Require the scheduler after the initial class-existence red assertion.
	 *
	 * @return WooPaymentsCutoverActionScheduler
	 */
	private function require_sut(): WooPaymentsCutoverActionScheduler {
		$this->assertTrue( class_exists( WooPaymentsCutoverActionScheduler::class ), 'The cutover Action Scheduler adapter has not been implemented yet.' );
		$this->assertInstanceOf( WooPaymentsCutoverActionScheduler::class, $this->sut, 'The cutover Action Scheduler adapter has not been implemented yet.' );

		return $this->sut;
	}

	/**
	 * Count pending or running actions with the exact identity.
	 *
	 * @param int $generation Job generation.
	 * @param int $attempt    Job attempt.
	 * @return int
	 */
	private function count_actions( int $generation, int $attempt ): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => 'woocommerce_woopayments_cutover_reconcile',
					'args'   => array(
						'generation' => $generation,
						'attempt'    => $attempt,
					),
					'group'  => 'woocommerce_woopayments_cutover',
					'status' => array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ),
				)
			)
		);
	}

	/**
	 * Remove every cutover action created by a test.
	 */
	private function cleanup_actions(): void {
		foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => 'woocommerce_woopayments_cutover_reconcile',
					'group'    => 'woocommerce_woopayments_cutover',
					'status'   => $status,
					'per_page' => -1,
				),
				'ids'
			);

			foreach ( $action_ids as $action_id ) {
				ActionScheduler::store()->cancel_action( $action_id );
			}
		}
	}
}
