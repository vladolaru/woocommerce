<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Helper_Order;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCanceledAuthorizationFeeRemediationService class.
 */
class WooPaymentsCanceledAuthorizationFeeRemediationServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->cleanup_state();
		$this->sut = $this->create_service();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_hooks( $this->sut );
		$this->cleanup_state();
		parent::tearDown();
	}

	/**
	 * @testdox Preserved remediation hooks are registered when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_preserved_actions_when_builtin_owns_runtime(): void {
		$this->sut->register();

		$this->assertSame( 10, has_action( WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK, array( $this->sut, 'process_batch' ) ) );
		$this->assertSame( 10, has_action( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, array( $this->sut, 'process_batch_dry_run' ) ) );
		$this->assertSame( 10, has_action( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_AFFECTED_ORDERS_HOOK, array( $this->sut, 'check_and_cache_affected_orders' ) ) );
	}

	/**
	 * Client 11.3.0's query matches the canceled intent status, the order type, the creation date and the order status
	 * (includes/migrations/class-wc-payments-remediate-canceled-auth-fees.php:314-341 and :364-391). The built-in
	 * selection also requires an order that was never paid: no paid date, a WooPayments payment method, and the stored
	 * intent as the transaction ID; and, once a cutover record makes the built-in WooPayments the owner, an order created
	 * before that record was last written.
	 *
	 * @testdox Affected-order lookup: $_dataName.
	 * @dataProvider provider_affected_order_fixtures
	 *
	 * @param array<string,mixed> $fixture  Order fixture arguments.
	 * @param bool                $affected Whether the order is affected.
	 * @param bool                $hpos     Whether orders are stored in the HPOS tables.
	 */
	public function test_affected_order_lookup( array $fixture, bool $affected, bool $hpos ): void {
		// Keep both order stores in step, so switching the authoritative store back afterwards is allowed.
		add_filter( 'pre_option_' . DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, static fn(): string => 'yes' );
		$hpos_was_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		$record_before    = get_option( StateStore::OPTION_NAME, null );
		OrderHelper::toggle_cot_feature_and_usage( $hpos );
		try {
			if ( isset( $fixture['cutover_record'] ) ) {
				update_option( StateStore::OPTION_NAME, $fixture['cutover_record'] );
			}
			$order = $this->create_order_fixture( $fixture );

			$order_ids = array_map(
				static function ( WC_Order $affected_order ): int {
					return $affected_order->get_id();
				},
				$this->sut->get_affected_orders( 10 )
			);

			$this->assertSame( $affected ? array( $order->get_id() ) : array(), $order_ids );
			$this->assertSame( $affected, $this->sut->has_affected_orders() );
		} finally {
			OrderHelper::toggle_cot_feature_and_usage( $hpos_was_enabled );
			if ( null === $record_before ) {
				delete_option( StateStore::OPTION_NAME );
			} else {
				update_option( StateStore::OPTION_NAME, $record_before );
			}
		}
	}

	/**
	 * Order fixtures, whether each is affected, and the order storage, each on both storages unless named.
	 *
	 * @return array<string,array{array<string,mixed>,bool,bool}>
	 */
	public function provider_affected_order_fixtures(): array {
		$before_order = strtotime( '2023-04-15 00:00:00 UTC' );
		$after_order  = strtotime( '2023-06-01 00:00:00 UTC' );
		$both         = array(
			'a Refunded canceled authorization without fee metadata' => array(
				array(
					'status'           => 'refunded',
					'with_fee_meta'    => false,
					'with_refund_meta' => false,
				),
				true,
			),
			'a Cancelled canceled authorization with fee metadata' => array( array( 'status' => 'cancelled' ), true ),
			'a per-method WooPayments gateway'       => array( array( 'payment_method' => 'woocommerce_payments_sepa_debit' ), true ),
			'a paid order'                           => array( array( 'date_paid' => '2023-05-01 12:00:00' ), false ),
			'another gateway'                        => array( array( 'payment_method' => 'bacs' ), false ),
			'a transaction ID other than the stored intent' => array( array( 'transaction_id' => 'pi_other' ), false ),
			'no transaction ID and no stored intent' => array(
				array(
					'transaction_id' => '',
					'intent_id'      => '',
				),
				false,
			),
			'a clean order without an intent'        => array(
				array(
					'status'           => 'processing',
					'intent_status'    => null,
					'with_fee_meta'    => false,
					'with_refund_meta' => false,
				),
				false,
			),
			'an intent that is not canceled'         => array( array( 'intent_status' => 'requires_capture' ), false ),
			'created before the bug date'            => array( array( 'date_created' => '2023-03-31' ), false ),
			'no cutover record'                      => array( array( 'cutover_record' => StateStore::ABSENT_RECORD ), true ),
			'a done switch last written before the order was created, started after it' => array(
				array( 'cutover_record' => $this->build_cutover_record( StateStore::STATE_DONE, $after_order, $before_order ) ),
				false,
			),
			'a done switch last written after the order was created' => array(
				array( 'cutover_record' => $this->build_cutover_record( StateStore::STATE_DONE, $before_order, $after_order ) ),
				true,
			),
			'a switch before finalize last written before the order was created' => array(
				array( 'cutover_record' => $this->build_cutover_record( StateStore::STATE_PENDING, $before_order, $before_order ) ),
				true,
			),
		);

		$rows = array();
		foreach ( $both as $name => $row ) {
			$rows[ $name . ', HPOS' ]  = array( $row[0], $row[1], true );
			$rows[ $name . ', posts' ] = array( $row[0], $row[1], false );
		}
		$rows['an empty _date_paid with a legacy _paid_date, posts']            = array( array( 'legacy_paid_date' => '2023-05-01 12:00:00' ), false, false );
		$rows['an empty _transaction_id row and an empty stored intent, posts'] = array(
			array(
				'transaction_id'         => '',
				'intent_id'              => '',
				'empty_transaction_meta' => true,
			),
			false,
			false,
		);
		$rows['a zero paid date, HPOS'] = array( array( 'zero_paid_date' => true ), true, true );

		return $rows;
	}

	/**
	 * @testdox Remediation deletes only WooPayments refunds, removes stale metadata, and records a detailed note.
	 */
	public function test_remediate_order_deletes_only_woopayments_refunds_and_removes_fee_metadata(): void {
		$order         = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$wcpay_refund  = $this->create_refund( $order, 'WooPayments refund', 're_wcpay' );
		$manual_refund = $this->create_refund( $order, 'Manual refund' );
		$order->set_status( 'refunded' );
		$order->save();

		$result = $this->sut->remediate_order( wc_get_order( $order->get_id() ) );
		$order  = wc_get_order( $order->get_id() );
		$notes  = $this->get_order_notes_content( $order );

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertFalse( (bool) wc_get_order( $wcpay_refund->get_id() ) );
		$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $manual_refund->get_id() ) );
		$this->assertStringContainsString( 'Removed incorrect data from canceled authorization', $notes );
		$this->assertStringContainsString( 'No actual payment or refund occurred.', $notes );
	}

	/**
	 * Client 11.1.0 deletes the stats row through core's DataStore::delete_order() before it deletes the refund
	 * (includes/migrations/class-wc-payments-remediate-canceled-auth-fees.php:707-718,820-841), so core fires
	 * woocommerce_analytics_delete_order_stats while the refund is still stored. The timing assertion fails when the row is
	 * deleted after the refund.
	 *
	 * @testdox Remediation deletes the stale WooCommerce Analytics stats row for deleted WooPayments refunds, through core, before the refund.
	 */
	public function test_remediate_order_deletes_refund_order_stats(): void {
		$order  = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$refund = $this->create_refund( $order, 'WooPayments refund', 're_wcpay' );

		$this->create_order_stats_row( $refund->get_id(), $order->get_id() );

		$this->assertSame( 1, $this->get_order_stats_row_count( $refund->get_id() ) );
		$stats_deletions = array();
		add_action(
			'woocommerce_analytics_delete_order_stats',
			static function ( $order_id ) use ( &$stats_deletions ): void {
				$stats_deletions[] = array( (int) $order_id, wc_get_order( $order_id ) instanceof WC_Order_Refund );
			}
		);

		$this->sut->remediate_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( 0, $this->get_order_stats_row_count( $refund->get_id() ) );
		$this->assertSame( array( $refund->get_id(), true ), $stats_deletions[0] ?? null, 'The first stats deletion runs while the refund is still stored.' );
	}

	/**
	 * Client 11.1.0 logs a failed stats deletion and goes on with the order (includes/migrations/
	 * class-wc-payments-remediate-canceled-auth-fees.php:826-840). Fails when the stats deletion is not caught.
	 *
	 * @testdox Remediation logs an Analytics callback that throws while it deletes a refund's stats and still remediates the order.
	 */
	public function test_remediate_order_goes_on_when_an_analytics_callback_throws(): void {
		$order  = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$refund = $this->create_refund( $order, 'WooPayments refund', 're_wcpay' );
		$this->create_order_stats_row( $refund->get_id(), $order->get_id() );
		$thrown = false;
		add_action(
			'woocommerce_analytics_delete_order_stats',
			static function () use ( &$thrown ): void {
				if ( ! $thrown ) {
					$thrown = true;
					throw new \Exception( 'Analytics callback failed.' );
				}
			}
		);
		$logger = RecordingWcLogger::install();

		$result = $this->sut->remediate_order( wc_get_order( $order->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $result );
		$this->assertFalse( (bool) wc_get_order( $refund->get_id() ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertContains(
			array( 'warning', sprintf( 'Failed to delete stats for order %d: Analytics callback failed.', $refund->get_id() ), 'wcpay-fee-remediation' ),
			$logger->lines
		);
	}

	/**
	 * @testdox Remediation aborts without cleanup when refund deletion is vetoed.
	 */
	public function test_remediate_order_aborts_when_refund_deletion_is_vetoed(): void {
		$order  = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$refund = $this->create_refund( $order, 'WooPayments refund', 're_wcpay' );
		$filter = static function ( $check, $data_object ) use ( $refund ) {
			if ( $data_object instanceof WC_Order_Refund && $refund->get_id() === $data_object->get_id() ) {
				return false;
			}

			return $check;
		};

		add_filter( 'woocommerce_pre_delete_order_refund', $filter, 10, 2 );
		try {
			$result = $this->sut->remediate_order( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'woocommerce_pre_delete_order_refund', $filter, 10 );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertFalse( $result );
		$this->assertSame( '1.50', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '48.50', $order->get_meta( '_wcpay_net', true ) );
		$this->assertCount( 1, $order->get_refunds() );
	}

	/**
	 * @testdox A live remediation batch counts an order that fails as an error, remediates the other order and moves the cursor past both ($_dataName).
	 * @testWith ["failed order first", true]
	 *           ["failed order last", false]
	 *
	 * Client 11.1.0 counts the error and advances the cursor after every order
	 * (includes/migrations/class-wc-payments-remediate-canceled-auth-fees.php:463-478).
	 *
	 * @param string $label        Case label.
	 * @param bool   $failed_first Whether the order that fails comes first in the batch.
	 */
	public function test_process_batch_moves_past_an_order_that_fails( string $label, bool $failed_first ): void {
		unset( $label );
		$first        = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$second       = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$failed_order = $failed_first ? $first : $second;
		$good_order   = $failed_first ? $second : $first;
		$refund       = $this->create_refund( $failed_order, 'WooPayments refund', 're_wcpay' );
		$filter       = static function ( $check, $data_object ) use ( $refund ) {
			if ( $data_object instanceof WC_Order_Refund && $refund->get_id() === $data_object->get_id() ) {
				return false;
			}

			return $check;
		};

		add_filter( 'woocommerce_pre_delete_order_refund', $filter, 10, 2 );
		try {
			$this->sut->process_batch();
		} finally {
			remove_filter( 'woocommerce_pre_delete_order_refund', $filter, 10 );
		}

		$stats = $this->sut->get_stats();

		$this->assertSame( 2, $stats['processed'] );
		$this->assertSame( 1, $stats['remediated'] );
		$this->assertSame( 1, $stats['errors'] );
		$this->assertSame( $second->get_id(), $this->sut->get_last_order_id() );
		$this->assertSame( '', wc_get_order( $good_order->get_id() )->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '1.50', wc_get_order( $failed_order->get_id() )->get_meta( '_wcpay_transaction_fee', true ) );
	}

	/**
	 * @testdox Dry-run remediation leaves orders, refunds, metadata, and notes untouched.
	 */
	public function test_remediate_order_dry_run_does_not_mutate_data(): void {
		$order  = $this->create_order_fixture( array( 'status' => 'cancelled' ) );
		$refund = $this->create_refund( $order, 'WooPayments refund', 're_wcpay' );
		$order->set_status( 'refunded' );
		$order->save();

		$this->sut->remediate_order( wc_get_order( $order->get_id() ), true );

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( '1.50', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '48.50', $order->get_meta( '_wcpay_net', true ) );
		$this->assertSame( 're_order', $order->get_meta( '_wcpay_refund_id', true ) );
		$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
		$this->assertStringNotContainsString( 'Removed incorrect data from canceled authorization', $this->get_order_notes_content( $order ) );
	}

	/**
	 * @testdox Processing a live batch updates stats and reschedules the next batch in the hyphenated woocommerce-payments group.
	 */
	public function test_process_batch_reschedules_next_batch_in_the_hyphenated_group(): void {
		$this->create_order_fixture( array( 'status' => 'cancelled' ) );

		$this->sut->process_batch();

		$stats = $this->sut->get_stats();
		$this->assertSame( 1, $stats['processed'] );
		$this->assertSame( 1, $stats['remediated'] );
		$this->assertNotFalse(
			as_has_scheduled_action(
				WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK,
				array(),
				WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID
			)
		);
	}

	/**
	 * @testdox Processing an empty follow-up batch marks remediation complete and cleans processing options.
	 */
	public function test_process_batch_marks_complete_when_no_affected_orders_remain(): void {
		$this->create_order_fixture( array( 'status' => 'cancelled' ) );

		$this->sut->process_batch();
		$this->sut->process_batch();

		$this->assertSame( 'completed', get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATUS_OPTION_KEY ) );
		$this->assertSame( 0, $this->sut->get_last_order_id() );
		$this->assertSame( WooPaymentsCanceledAuthorizationFeeRemediationService::INITIAL_BATCH_SIZE, $this->sut->get_batch_size() );
	}

	/**
	 * @testdox The affected-orders check caches when remediation work remains.
	 */
	public function test_check_and_cache_affected_orders_updates_state_option_for_affected_store(): void {
		$this->create_order_fixture( array( 'status' => 'cancelled' ) );

		$this->sut->check_and_cache_affected_orders();

		$this->assertSame( 'has_affected_orders', get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY ) );
	}

	/**
	 * @testdox The affected-orders check caches when no remediation work remains.
	 */
	public function test_check_and_cache_affected_orders_updates_state_option_for_clean_store(): void {
		$this->sut->check_and_cache_affected_orders();

		$this->assertSame( 'no_affected_orders', get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY ) );
	}

	/**
	 * Create a remediation service.
	 *
	 * @return WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private function create_service(): WooPaymentsCanceledAuthorizationFeeRemediationService {
		return new WooPaymentsCanceledAuthorizationFeeRemediationService();
	}

	/**
	 * Create an order fixture.
	 *
	 * @param array<string,mixed> $args Fixture arguments.
	 * @return WC_Order
	 */
	private function create_order_fixture( array $args = array() ): WC_Order {
		$args = wp_parse_args(
			$args,
			array(
				'date_created'           => '2023-05-01',
				'date_paid'              => null,
				'empty_transaction_meta' => false,
				'intent_id'              => 'pi_defect',
				'intent_status'          => 'canceled',
				'legacy_paid_date'       => null,
				'payment_method'         => 'woocommerce_payments',
				'status'                 => 'cancelled',
				'transaction_id'         => 'pi_defect',
				'with_fee_meta'          => true,
				'with_refund_meta'       => true,
				'zero_paid_date'         => false,
			)
		);

		$order = WC_Helper_Order::create_order();
		$order->set_date_created( (string) $args['date_created'] );
		$order->set_payment_method( (string) $args['payment_method'] );
		$order->set_transaction_id( (string) $args['transaction_id'] );
		$order->set_status( (string) $args['status'] );
		$order->update_meta_data( '_intent_id', (string) $args['intent_id'] );

		if ( null !== $args['date_paid'] ) {
			$order->set_date_paid( (string) $args['date_paid'] );
		}

		if ( null !== $args['intent_status'] ) {
			$order->update_meta_data( '_intention_status', (string) $args['intent_status'] );
		}

		if ( (bool) $args['with_fee_meta'] ) {
			$order->update_meta_data( '_wcpay_transaction_fee', '1.50' );
			$order->update_meta_data( '_wcpay_net', '48.50' );
		}

		if ( (bool) $args['with_refund_meta'] ) {
			$order->update_meta_data( '_wcpay_refund_id', 're_order' );
			$order->update_meta_data( '_wcpay_refund_status', 'succeeded' );
		}

		$order->save();

		// Stored forms that current order saves do not write (the posts store deletes a meta row whose value is empty), as
		// an older save or an import can leave them.
		if ( (bool) $args['empty_transaction_meta'] ) {
			update_post_meta( $order->get_id(), '_transaction_id', '' );
		}
		if ( null !== $args['legacy_paid_date'] ) {
			update_post_meta( $order->get_id(), '_paid_date', (string) $args['legacy_paid_date'] );
		}
		if ( (bool) $args['zero_paid_date'] ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'wc_order_operational_data', array( 'date_paid_gmt' => '0000-00-00 00:00:00' ), array( 'order_id' => $order->get_id() ) );
		}

		return $order;
	}

	/**
	 * Build a valid cutover record.
	 *
	 * @param string $state      Record state.
	 * @param int    $started_at When the switch started.
	 * @param int    $updated_at When the record was last written.
	 * @return array<string,mixed>
	 */
	private function build_cutover_record( string $state, int $started_at, int $updated_at ): array {
		return array(
			'schema_version'         => StateStore::SCHEMA_VERSION,
			'generation'             => 1,
			'revision'               => 2,
			'state'                  => $state,
			'started_at'             => $started_at,
			'updated_at'             => $updated_at,
			'attempt'                => 1,
			'action_id'              => 0,
			'current_step'           => StateStore::STATE_DONE === $state ? 'done' : 'awaiting_merchant_start',
			'step_log'               => array(),
			'deferred_codes'         => array(),
			'informational_outcomes' => array(),
			'next_attempt_at'        => null,
			'lease_token'            => null,
			'lease_expires_at'       => null,
			'request_origin_token'   => null,
		);
	}

	/**
	 * Create a refund fixture for an order.
	 *
	 * @param WC_Order $order           Parent order.
	 * @param string   $reason          Refund reason.
	 * @param string   $wcpay_refund_id Optional WooPayments refund ID.
	 * @return WC_Order_Refund
	 */
	private function create_refund( WC_Order $order, string $reason, string $wcpay_refund_id = '' ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 5,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);

		if ( ! $refund instanceof WC_Order_Refund ) {
			$this->fail( 'Expected refund fixture creation to return a WC_Order_Refund.' );
		}

		if ( '' !== $wcpay_refund_id ) {
			$refund->update_meta_data( '_wcpay_refund_id', $wcpay_refund_id );
			$refund->save_meta_data();
		}

		return $refund;
	}

	/**
	 * Create an analytics order stats fixture.
	 *
	 * @param int $order_id  Order or refund ID.
	 * @param int $parent_id Parent order ID.
	 */
	private function create_order_stats_row( int $order_id, int $parent_id = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wc_order_stats',
			array(
				'order_id'         => $order_id,
				'parent_id'        => $parent_id,
				'date_created'     => '2023-05-01 00:00:00',
				'date_created_gmt' => '2023-05-01 00:00:00',
				'date_paid'        => '2023-05-01 00:00:00',
				'date_completed'   => '2023-05-01 00:00:00',
				'num_items_sold'   => 0,
				'total_sales'      => -5,
				'tax_total'        => 0,
				'shipping_total'   => 0,
				'net_total'        => -5,
				'status'           => 'wc-completed',
				'customer_id'      => 0,
			)
		);
	}

	/**
	 * Count analytics stats rows for an order or refund ID.
	 *
	 * @param int $order_id Order or refund ID.
	 * @return int
	 */
	private function get_order_stats_row_count( int $order_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats WHERE order_id = %d",
				$order_id
			)
		);
	}

	/**
	 * Get all order note content for assertions.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Notes content.
	 */
	private function get_order_notes_content( WC_Order $order ): string {
		$content = array();
		$notes   = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		foreach ( $notes as $note ) {
			$content[] = (string) $note->content;
		}

		return implode( "\n", $content );
	}

	/**
	 * Remove hooks registered by a service instance.
	 *
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $service Service instance.
	 */
	private function remove_hooks( WooPaymentsCanceledAuthorizationFeeRemediationService $service ): void {
		remove_action( WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK, array( $service, 'process_batch' ) );
		remove_action( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, array( $service, 'process_batch_dry_run' ) );
		remove_action( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_AFFECTED_ORDERS_HOOK, array( $service, 'check_and_cache_affected_orders' ) );
	}

	/**
	 * Clean state shared by remediation tests.
	 */
	private function cleanup_state(): void {
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATUS_OPTION_KEY );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::LAST_ORDER_ID_OPTION_KEY );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::BATCH_SIZE_OPTION_KEY );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATS_OPTION_KEY );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_OPTION_KEY );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK, null, WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
			as_unschedule_all_actions( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, null, WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
			as_unschedule_all_actions( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_AFFECTED_ORDERS_HOOK, null, WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
		}
	}
}
