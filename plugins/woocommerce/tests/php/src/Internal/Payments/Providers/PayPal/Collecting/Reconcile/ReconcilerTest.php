<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Reconcile;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook\WebhookFixtures;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the reconcile that settles held orders from PayPal's capture status and completes onboarding from the seller
 * status, for the events the store missed.
 *
 * @group paypal-wallet
 */
class ReconcilerTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * The fake transport.
	 *
	 * @var FakePlatformTransport
	 */
	private FakePlatformTransport $transport;

	/**
	 * Boot the store over a fake transport and return the reconciler.
	 *
	 * @param SellerStatus|null $seller_status The seller status the transport answers, or null for an incomplete one.
	 * @return Reconciler
	 */
	private function sut( ?SellerStatus $seller_status = null ): Reconciler {
		$this->transport = new FakePlatformTransport( null === $seller_status ? array() : array( 'seller_status' => $seller_status ) );

		return $this->boot_container( array( new TransportBindingModule( $this->transport ) ) )->get( 'collecting.reconciler' );
	}

	/**
	 * Whether the daily reconcile is scheduled.
	 *
	 * @return bool
	 */
	private function is_scheduled(): bool {
		return false !== as_next_scheduled_action( Reconciler::HOOK, array(), Reconciler::GROUP );
	}

	/**
	 * @testdox Should complete the held order PayPal completed, keep the pending one held with PayPal's create time, and complete onboarding.
	 */
	public function test_run_settles_held_orders_and_completes_onboarding(): void {
		$this->set_collecting();
		$sut       = $this->sut( new SellerStatus( 'M-SELLER', true, true, true ) );
		$completed = $this->wallet_order( '1' );
		$pending   = $this->wallet_order( '2' );
		$this->stub_captures(
			array(
				'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'COMPLETED' ),
				'CAPTURE-2' => $this->capture_json( 'CAPTURE-2', 'PENDING', 'UNILATERAL', '2026-10-01T10:00:00Z' ),
			)
		);

		$summary = $sut->run();

		$this->assertSame( array( $completed->get_id() ), $summary['completed'] );
		$this->assertSame( array( $pending->get_id() ), $summary['held'] );
		$this->assertSame( array(), $summary['returned'] );
		$this->assertSame( array(), $summary['failed'] );
		$this->assertSame( 'completed', $summary['onboarding'] );

		$this->assertSame( 'processing', wc_get_order( $completed->get_id() )->get_status() );
		$this->assertFalse( $this->has_held_meta( $completed ) );
		$this->assertSame( 'on-hold', wc_get_order( $pending->get_id() )->get_status() );
		$this->assertTrue( $this->has_held_meta( $pending ) );
		$this->assertSame( (string) strtotime( '2026-10-01T10:00:00Z' ), wc_get_order( $pending->get_id() )->get_meta( HeldCapture::HELD_AT_META_KEY, true ), 'The deadline counts from PayPal\'s create time' );

		$this->assertSame( array( array( 'TRACK-OURS' ) ), $this->transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'M-SELLER', get_option( Options::PLATFORM )['merchant_id'] ?? null );
	}

	/**
	 * @testdox Should cancel with a restock the held order whose capture PayPal returned.
	 */
	public function test_run_returns_a_refunded_capture(): void {
		$this->set_collecting();
		$sut   = $this->sut();
		$order = $this->wallet_order( '1' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'REFUNDED' ) ) );

		$summary = $sut->run();

		$this->assertSame( array( $order->get_id() ), $summary['returned'] );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 'incomplete', $summary['onboarding'] );
		$this->assertFalse( get_option( Options::PLATFORM ) );
	}

	/**
	 * @testdox Should release a held order the merchant cancelled by hand once PayPal completes its capture, leaving it cancelled and its refunds unlocked.
	 */
	public function test_run_releases_a_held_order_cancelled_by_hand(): void {
		$this->set_platform_connected();
		$sut   = $this->sut();
		$order = $this->wallet_order( '1' );
		$order->update_status( 'cancelled' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'COMPLETED' ) ) );

		$summary = $sut->run();

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( array( $order->get_id() ), $summary['completed'] );
		$this->assertSame( 'cancelled', $saved->get_status() );
		$this->assertFalse( $this->has_held_meta( $saved ) );
		$this->assertFalse( ( new RefundLock( new ConnectionState() ) )->base_locked( $saved ), 'The refund lock lets go once the meta is released' );
	}

	/**
	 * @testdox Should re-read a merchant-app order through the merchant app on a store that is now platform connected.
	 */
	public function test_run_reads_through_the_pin_not_the_current_state(): void {
		$this->set_platform_connected();
		$sut   = $this->sut();
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'PENDING', 'UNILATERAL' ) ) );

		$summary = $sut->run();

		$reads = $this->capture_reads();
		$this->assertCount( 1, $reads );
		$this->assertSame( 'https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1', $reads[0]['url'] );
		$this->assertSame( 'Bearer token-merchant_app', $reads[0]['request']['headers']['Authorization'] );
		$this->assertSame( array( $order->get_id() ), $summary['held'] );
		$this->assertSame( 'not_collecting', $summary['onboarding'] );
		$this->assertSame( array(), $this->transport->calls_to( 'seller_status' ) );
	}

	/**
	 * @testdox Should leave an order whose capture cannot be read held and report it.
	 */
	public function test_run_reports_a_failed_read(): void {
		$this->set_collecting();
		$sut   = $this->sut();
		$order = $this->wallet_order( '1' );
		$this->stub_captures( array() );

		$summary = $sut->run();

		$this->assertSame( array( $order->get_id() ), $summary['failed'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertTrue( $this->has_held_meta( $order ) );
	}

	/**
	 * @testdox Should keep the daily reconcile scheduled while orders are held and unschedule it once none are.
	 */
	public function test_run_maintains_the_schedule(): void {
		$this->set_collecting();
		$sut   = $this->sut();
		$order = $this->wallet_order( '1' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'PENDING', 'UNILATERAL' ) ) );

		$sut->run();
		$this->assertTrue( $this->is_scheduled(), 'Scheduled while the order is held' );

		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'COMPLETED' ) ) );
		$sut->run();
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertFalse( $this->is_scheduled(), 'Unscheduled once nothing is held' );
	}

	/**
	 * Hold one more order than a reconcile settles in one run, each with a capture PayPal reports with a status.
	 *
	 * @param string $status The capture status PayPal reports for every order.
	 * @return int[] The order IDs, oldest first.
	 */
	private function hold_more_than_a_batch( string $status ): array {
		$ids      = array();
		$captures = array();
		for ( $i = 1; $i <= Reconciler::BATCH_SIZE + 1; $i++ ) {
			$order = $this->wallet_order( (string) $i );
			$order->set_date_created( 1000 + $i );
			$order->save();
			$ids[]                       = $order->get_id();
			$captures[ 'CAPTURE-' . $i ] = $this->capture_json( 'CAPTURE-' . $i, $status, 'PENDING' === $status ? 'UNILATERAL' : null );
		}
		$this->stub_captures( $captures );

		return $ids;
	}

	/**
	 * Whether a continuation of the reconcile is queued from an offset.
	 *
	 * @param int $offset The offset.
	 * @return bool
	 */
	private function continuation_queued( int $offset ): bool {
		return false !== as_next_scheduled_action( Reconciler::HOOK, array( $offset, true ), Reconciler::GROUP );
	}

	/**
	 * Remove every queued run of the reconcile, so no action is left for the next test.
	 */
	private function unschedule_all(): void {
		as_unschedule_all_actions( Reconciler::HOOK, null, Reconciler::GROUP );
	}

	/**
	 * @testdox Should settle at most one batch of held orders per run and queue a continuation after the orders that stay held.
	 */
	public function test_run_settles_one_batch_and_queues_the_rest(): void {
		$this->set_collecting();
		$sut = $this->sut();
		$ids = $this->hold_more_than_a_batch( 'PENDING' );

		$summary = $sut->run();

		$this->assertCount( Reconciler::BATCH_SIZE, $this->capture_reads() );
		$this->assertSame( array_slice( $ids, 0, Reconciler::BATCH_SIZE ), $summary['held'] );
		$this->assertTrue( $this->continuation_queued( Reconciler::BATCH_SIZE ), 'The orders that stay held are skipped by the next run' );

		$summary = $sut->handle_woocommerce_paypal_wallet_reconcile( Reconciler::BATCH_SIZE, true );

		$this->assertSame( array( end( $ids ) ), $summary['held'] );
		$this->assertCount( Reconciler::BATCH_SIZE + 1, $this->capture_reads() );
		$this->assertFalse( $this->continuation_queued( 2 * Reconciler::BATCH_SIZE ), 'Nothing is left' );
		$this->unschedule_all();
	}

	/**
	 * @testdox Should queue the continuation from the same offset when the batch's orders were settled and left the list.
	 */
	public function test_run_continues_from_the_orders_still_listed(): void {
		$this->set_collecting();
		$sut = $this->sut();
		$ids = $this->hold_more_than_a_batch( 'COMPLETED' );

		$summary = $sut->run();

		$this->assertCount( Reconciler::BATCH_SIZE, $summary['completed'] );
		$this->assertTrue( $this->continuation_queued( 0 ) );

		$summary = $sut->handle_woocommerce_paypal_wallet_reconcile( 0, true );

		$this->assertSame( array( end( $ids ) ), $summary['completed'] );
		$this->assertSame( 0, $this->container_held_count() );
		$this->unschedule_all();
	}

	/**
	 * @testdox Should not count an order another request released during the batch as still listed, so the continuation skips no order.
	 */
	public function test_continuation_offset_leaves_out_an_order_released_meanwhile(): void {
		$this->set_collecting();
		$sut      = $this->sut();
		$ids      = array();
		$captures = array();
		for ( $i = 1; $i <= Reconciler::BATCH_SIZE + 2; $i++ ) {
			$order = $this->wallet_order( (string) $i );
			$order->set_date_created( 1000 + $i );
			$order->save();
			$ids[]                       = $order->get_id();
			$captures[ 'CAPTURE-' . $i ] = $this->capture_json( 'CAPTURE-' . $i, 2 === $i ? 'COMPLETED' : 'PENDING', 2 === $i ? null : 'UNILATERAL' );
		}
		$released = $ids[1];
		$this->stub_http(
			function ( $request, $url ) use ( $captures, $released ) {
				unset( $request );
				$capture_id = basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
				if ( ! isset( $captures[ $capture_id ] ) ) {
					return new \WP_Error( 'unrouted', "Unrouted $url" );
				}
				if ( 'CAPTURE-2' === $capture_id ) {
					// Another request settles this order while the reconcile reads its capture.
					( new HeldOrders() )->release( wc_get_order( $released ) );
				}
				return $this->http_response( 200, $captures[ $capture_id ] );
			}
		);

		$summary = $sut->run();

		$this->assertSame( array( $released ), $summary['unchanged'] );
		$this->assertTrue( $this->continuation_queued( Reconciler::BATCH_SIZE - 1 ), 'The 24 orders that stay held are skipped, not 25' );
		$this->assertFalse( $this->continuation_queued( Reconciler::BATCH_SIZE ) );
		$this->unschedule_all();
	}

	/**
	 * @testdox Should queue no continuation when a batch settles nothing and leaves nothing listed, so a chain always ends.
	 */
	public function test_no_continuation_without_progress(): void {
		$this->set_collecting();
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$missing   = new class() extends HeldOrders {
			/**
			 * More IDs than a batch, of orders that do not exist.
			 *
			 * @param int $limit  The most IDs.
			 * @param int $offset Ignored.
			 * @return int[]
			 */
			public function ids( int $limit, int $offset = 0 ): array {
				unset( $offset );
				return range( 9900001, 9900000 + $limit );
			}

			/**
			 * None held.
			 *
			 * @return int
			 */
			public function count(): int {
				return 0;
			}
		};
		$sut       = new Reconciler(
			$missing,
			$container->get( 'collecting.capture-reader' ),
			$container->get( 'collecting.held-settlement' ),
			$container->get( 'collecting.state' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);

		$summary = $sut->handle_woocommerce_paypal_wallet_reconcile( 0, true );

		$this->assertCount( Reconciler::BATCH_SIZE, $summary['unchanged'] );
		$this->assertFalse( as_has_scheduled_action( Reconciler::HOOK, null, Reconciler::GROUP ), 'No continuation is queued' );
	}

	/**
	 * @testdox Should check onboarding on the daily run but not again on its continuations.
	 */
	public function test_continuations_skip_the_onboarding_check(): void {
		$this->set_collecting();
		$sut = $this->sut( new SellerStatus( '', true, false, true ) );

		$continued = $sut->handle_woocommerce_paypal_wallet_reconcile( Reconciler::BATCH_SIZE, true );
		$this->assertSame( array(), $this->transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'skipped', $continued['onboarding'] );

		$daily = $sut->handle_woocommerce_paypal_wallet_reconcile();
		$this->assertCount( 1, $this->transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'incomplete', $daily['onboarding'] );
		delete_option( Options::SELLER_STATUS );
	}

	/**
	 * @testdox Should read the hand-back run's arguments as a first run that checks onboarding, like the daily run.
	 */
	public function test_hand_back_run_checks_onboarding(): void {
		$this->set_collecting();
		$sut = $this->sut( new SellerStatus( '', true, false, true ) );

		$summary = $sut->handle_woocommerce_paypal_wallet_reconcile( ...Reconciler::HAND_BACK_ARGS );

		$this->assertCount( 1, $this->transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'incomplete', $summary['onboarding'] );
		delete_option( Options::SELLER_STATUS );
	}

	/**
	 * The number of held orders left.
	 *
	 * @return int
	 */
	private function container_held_count(): int {
		return ( new HeldOrders() )->count();
	}

	/**
	 * @testdox Should check onboarding on its own, without reading any capture.
	 */
	public function test_reconcile_onboarding_reads_no_capture(): void {
		$this->set_collecting();
		$sut = $this->sut( new SellerStatus( 'M-SELLER', true, true, true ) );
		$this->wallet_order( '1' );
		$this->stub_captures( array() );

		$this->assertSame( 'completed', $sut->reconcile_onboarding() );

		$this->assertSame( array(), $this->capture_reads() );
		$this->assertSame( 'M-SELLER', get_option( Options::PLATFORM )['merchant_id'] ?? null );
	}

	/**
	 * @testdox Should check the schedule from an admin screen at most once an hour, and never on an AJAX request.
	 */
	public function test_admin_schedule_check_is_throttled(): void {
		$this->set_collecting();
		$sut = $this->sut();
		$this->wallet_order( '1' );
		$this->set_wallet_transient( Reconciler::SCHEDULE_CHECK_TRANSIENT, 'claimed' );
		delete_transient( Reconciler::SCHEDULE_CHECK_TRANSIENT );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$sut->handle_admin_init();
		$this->assertFalse( $this->is_scheduled(), 'Not on an AJAX request' );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$sut->handle_admin_init();
		$this->assertTrue( $this->is_scheduled(), 'The first admin request schedules' );

		as_unschedule_all_actions( Reconciler::HOOK, array(), Reconciler::GROUP );
		$sut->handle_admin_init();
		$this->assertFalse( $this->is_scheduled(), 'The next admin request within the hour does not check' );

		$sut->maintain_schedule();
		$this->assertTrue( $this->is_scheduled(), 'The unthrottled check still schedules' );
	}

	/**
	 * @testdox Should leave alone an order a webhook settled after the reconcile listed it.
	 */
	public function test_run_skips_an_order_settled_meanwhile(): void {
		$this->set_collecting();
		$this->transport   = new FakePlatformTransport();
		$container         = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) );
		$sut               = $container->get( 'collecting.reconciler' );
		$orders            = array(
			'CAPTURE-1' => $this->wallet_order( '1' ),
			'CAPTURE-2' => $this->wallet_order( '2' ),
		);
		$settled_meanwhile = null;
		$this->stub_http(
			function ( $request, $url ) use ( $container, $orders, &$settled_meanwhile ) {
				unset( $request );
				foreach ( $orders as $capture_id => $order ) {
					if ( false === strpos( $url, '/v2/payments/captures/' . $capture_id ) ) {
						continue;
					}
					if ( null === $settled_meanwhile ) {
						// A webhook completes the other order while the reconcile reads this one.
						$settled_meanwhile = 'CAPTURE-1' === $capture_id ? $orders['CAPTURE-2'] : $orders['CAPTURE-1'];
						$container->get( 'collecting.held-settlement' )->complete( $settled_meanwhile );
					}
					return $this->http_response( 200, $this->capture_json( $capture_id, 'COMPLETED' ) );
				}
				return new \WP_Error( 'unrouted', "Unrouted $url" );
			}
		);

		$summary = $sut->run();

		$this->assertCount( 1, $summary['completed'] );
		$this->assertSame( array( $settled_meanwhile->get_id() ), $summary['unchanged'] );
		$this->assertCount( 1, $this->capture_reads(), 'The order settled meanwhile is not read again' );
		foreach ( $orders as $order ) {
			$this->assertSame( 1, $this->count_notes( $order, 'Payment successfully captured.' ) );
		}
	}

	/**
	 * The autoload flag of a stored option.
	 *
	 * @param string $name The option name.
	 * @return string|null The flag, or null when the row does not exist.
	 */
	private function autoload_flag( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @testdox Should cache the last seller status in a non-autoloaded option while onboarding is incomplete.
	 */
	public function test_run_caches_an_incomplete_seller_status(): void {
		$this->set_collecting();
		$sut = $this->sut( new SellerStatus( '', true, false, true ) );

		$sut->run();

		$cached = get_option( Options::SELLER_STATUS );
		$this->assertSame( array( 'payments_receivable', 'primary_email_confirmed', 'checked_at' ), array_keys( $cached ) );
		$this->assertTrue( $cached['payments_receivable'] );
		$this->assertFalse( $cached['primary_email_confirmed'] );
		$this->assertEqualsWithDelta( time(), $cached['checked_at'], 5 );
		$this->assertContains( $this->autoload_flag( Options::SELLER_STATUS ), array( 'no', 'off' ), 'Not autoloaded' );
		$this->assertSame( $cached, ( new Options() )->seller_status() );
		delete_option( Options::SELLER_STATUS );
	}

	/**
	 * @testdox Should delete the cached seller status once onboarding completes.
	 */
	public function test_run_deletes_the_cached_seller_status_when_the_state_completes(): void {
		$this->set_collecting();
		$this->set_wallet_option(
			Options::SELLER_STATUS,
			array(
				'payments_receivable'     => true,
				'primary_email_confirmed' => false,
				'checked_at'              => 1,
			)
		);
		$sut = $this->sut( new SellerStatus( 'M-SELLER', true, true, true ) );

		$sut->run();

		$this->assertFalse( get_option( Options::SELLER_STATUS ) );
		$this->assertSame( 'M-SELLER', get_option( Options::PLATFORM )['merchant_id'] ?? null );
	}

	/**
	 * @testdox Should keep the cached seller status when the seller status cannot be read.
	 */
	public function test_run_keeps_the_cache_on_a_failed_read(): void {
		$this->set_collecting();
		$cache = array(
			'payments_receivable'     => true,
			'primary_email_confirmed' => false,
			'checked_at'              => 1,
		);
		$this->set_wallet_option( Options::SELLER_STATUS, $cache );
		$this->transport = new FakePlatformTransport( array( 'seller_status' => new RuntimeException( 'offline' ) ) );
		$sut             = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) )->get( 'collecting.reconciler' );

		$summary = $sut->run();

		$this->assertSame( 'failed', $summary['onboarding'] );
		$this->assertSame( $cache, get_option( Options::SELLER_STATUS ) );
	}

	/**
	 * @testdox Should read and cache nothing when the store is not collecting.
	 */
	public function test_run_caches_nothing_off_collecting(): void {
		$this->set_platform_connected();
		$sut = $this->sut( new SellerStatus( '', true, false, true ) );

		$sut->run();

		$this->assertFalse( get_option( Options::SELLER_STATUS ) );
	}
}
