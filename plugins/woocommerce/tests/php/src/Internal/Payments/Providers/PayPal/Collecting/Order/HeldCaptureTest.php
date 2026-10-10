<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatusDetails;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ArrayObject;
use WC_Order;

/**
 * Tests for the listeners that record a held capture and claim the first order of a collecting store.
 *
 * @group paypal-wallet
 */
class HeldCaptureTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * The calls of the first-order action: each is the order and the state it was fired with.
	 *
	 * @var ArrayObject
	 */
	private ArrayObject $first_orders;

	/**
	 * Spy on the first-order action.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->first_orders = new ArrayObject();
		$spy                = $this->first_orders;
		add_action(
			'woocommerce_paypal_wallet_first_order',
			static function ( $order, $state ) use ( $spy ) {
				$spy->append( array( $order, $state ) );
			},
			10,
			2
		);
	}

	/**
	 * Boot the wallet with the fake transport, so the collecting module registers its listeners.
	 */
	private function boot(): void {
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * Put the store in the collecting state.
	 *
	 * @param bool $bound Whether the payee is already bound.
	 */
	private function set_collecting( bool $bound = false ): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => $bound,
			)
		);
	}

	/**
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'tracking_id' => 'abc',
				'payee_email' => 'connected@example.com',
				'environment' => 'sandbox',
			)
		);
	}

	/**
	 * A wallet order with a PayPal order ID, pinned to an app.
	 *
	 * @param string $payment_method The payment method.
	 * @param bool   $pinned         Whether the order carries the pin.
	 * @return WC_Order
	 */
	private function wallet_order( string $payment_method = PayPalGateway::ID, bool $pinned = true ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-HELD-' . wp_generate_password( 6, false ) );
		if ( $pinned ) {
			OrderPin::record( $order, PlatformTransport::APP_MERCHANT_APP );
		}
		$order->save();

		return $order;
	}

	/**
	 * A capture of the given status and reason.
	 *
	 * @param string      $status The status.
	 * @param string|null $reason The status-details reason, null for none.
	 * @param string      $id     The capture ID.
	 * @return Capture
	 */
	private function capture( string $status, ?string $reason, string $id = 'CAPTURE-HELD-1' ): Capture {
		return new Capture(
			$id,
			new CaptureStatus( $status, null === $reason ? null : new CaptureStatusDetails( $reason ) ),
			new Amount( new Money( 10.0, 'USD' ) ),
			true,
			'',
			'',
			'',
			null,
			null
		);
	}

	/**
	 * The text of the order's notes.
	 *
	 * @param WC_Order $order The order.
	 * @return string[]
	 */
	private function notes( WC_Order $order ): array {
		return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
	}

	/**
	 * The held-payment notes of an order.
	 *
	 * @param WC_Order $order The order.
	 * @return string[]
	 */
	private function held_notes( WC_Order $order ): array {
		return array_values(
			array_filter(
				$this->notes( $order ),
				static function ( string $note ): bool {
					return 0 === strpos( $note, 'Payment held by PayPal' );
				}
			)
		);
	}

	/**
	 * @testdox Should record the held meta, the capture ID and the deadline note on a held capture, bind the payee and claim the first order.
	 * @testWith ["UNILATERAL"]
	 *           ["PAYEE_SETUP_PENDING"]
	 *
	 * @param string $reason The status-details reason.
	 */
	public function test_a_held_capture_is_recorded( string $reason ): void {
		$this->set_collecting();
		$this->boot();
		$order  = $this->wallet_order();
		$before = time();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, $reason ) );

		$saved   = wc_get_order( $order->get_id() );
		$held_at = (int) $saved->get_meta( HeldCapture::HELD_AT_META_KEY, true );
		$this->assertSame( $reason, $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( 'CAPTURE-HELD-1', $saved->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) );
		$this->assertGreaterThanOrEqual( $before, $held_at );
		$this->assertLessThanOrEqual( time(), $held_at );

		$this->assertCount( 1, $this->held_notes( $saved ), 'One note names the reason and a deadline' );
		$this->assertStringContainsString( "($reason)", $this->held_notes( $saved )[0] );

		$this->assertSame( (string) $order->get_id(), (string) get_option( Options::FIRST_ORDER ), 'The first-order slot was claimed' );
		$this->assertTrue( (bool) get_option( Options::COLLECTING )['payee_bound'], 'The payee is bound' );
		$this->assertCount( 1, $this->first_orders );
		$this->assertSame( $order->get_id(), $this->first_orders[0][0]->get_id() );
		$this->assertInstanceOf( CollectingState::class, $this->first_orders[0][1] );
	}

	/**
	 * @testdox Should record the payee the capture was paid to on the held order, so a later entry of the collecting state can tell whose orders are held.
	 */
	public function test_a_held_capture_records_its_payee(): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );

		$this->assertSame( 'payee@example.com', wc_get_order( $order->get_id() )->get_meta( HeldCapture::PAYEE_META_KEY, true ) );
	}

	/**
	 * @testdox Should record the payee lowercase, so the comparison does not depend on the database collation.
	 */
	public function test_a_held_capture_records_its_payee_lowercase(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'Payee@Example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );

		$this->assertSame( 'payee@example.com', wc_get_order( $order->get_id() )->get_meta( HeldCapture::PAYEE_META_KEY, true ) );
	}

	/**
	 * @testdox Should record a held capture with the held-at time it is given, and refuse one that is not held.
	 */
	public function test_record_uses_the_given_held_at(): void {
		$sut     = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$order   = $this->wallet_order();
		$held_at = (int) strtotime( '2026-10-09T11:01:18Z' );

		$this->assertTrue( $sut->record( $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ), $held_at ) );
		$this->assertFalse( $sut->record( $this->wallet_order(), $this->capture( CaptureStatus::PENDING, 'ECHECK' ), $held_at ) );

		$this->assertSame( (string) $held_at, wc_get_order( $order->get_id() )->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
	}

	/**
	 * @testdox Should name the deadline in the site timezone: 30 days after a held-at near a day boundary reads as the next day in Auckland.
	 */
	public function test_the_note_names_the_deadline_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		update_option( 'date_format', 'F j, Y' );
		$sut   = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$order = $this->wallet_order();

		// 2026-10-09 11:30 UTC + 30 days is 2026-11-08 11:30 UTC, which is 2026-11-09 00:30 in Auckland (UTC+13).
		$sut->record( $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ), (int) strtotime( '2026-10-09T11:30:00Z' ) );

		$this->assertSame(
			array( 'Payment held by PayPal until PayPal Wallet setup is completed (UNILATERAL). The payment is returned to the customer if setup is not completed by November 9, 2026.' ),
			$this->held_notes( wc_get_order( $order->get_id() ) )
		);
	}

	/**
	 * @testdox Should add one note and keep held-at when the seam runs again for the same capture, and only ever move held-at earlier.
	 */
	public function test_a_repeated_capture_is_idempotent(): void {
		$this->set_collecting();
		$this->boot();
		$order   = $this->wallet_order();
		$capture = $this->capture( CaptureStatus::PENDING, 'UNILATERAL' );
		$sut     = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$sut->record( $order, $capture, 1000000 );

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $capture );
		$after_repeat = wc_get_order( $order->get_id() );
		$this->assertSame( '1000000', $after_repeat->get_meta( HeldCapture::HELD_AT_META_KEY, true ), 'A later time never moves the deadline later' );
		$this->assertCount( 1, $this->held_notes( $after_repeat ), 'The repeat adds no note' );
		$this->assertCount( 1, $this->first_orders, 'The claim path stays harmless' );

		$this->assertTrue( $sut->record( $after_repeat, $capture, 500000 ), 'A repeat still reports a held capture' );
		$after_earlier = wc_get_order( $order->get_id() );
		$this->assertSame( '500000', $after_earlier->get_meta( HeldCapture::HELD_AT_META_KEY, true ), 'An earlier time moves held-at earlier' );
		$this->assertCount( 1, $this->held_notes( $after_earlier ) );

		$sut->record( $after_earlier, $this->capture( CaptureStatus::PENDING, 'UNILATERAL', 'CAPTURE-OTHER' ), 9000000 );
		$replaced = wc_get_order( $order->get_id() );
		$this->assertSame( 'CAPTURE-OTHER', $replaced->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ), 'A different capture replaces the record' );
		$this->assertCount( 2, $this->held_notes( $replaced ) );
	}

	/**
	 * @testdox Should write held-at when the same capture has none stored.
	 */
	public function test_a_repeat_fills_in_a_missing_held_at(): void {
		$sut     = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$order   = $this->wallet_order();
		$capture = $this->capture( CaptureStatus::PENDING, 'UNILATERAL' );
		$sut->record( $order, $capture, 1000000 );
		$order->delete_meta_data( HeldCapture::HELD_AT_META_KEY );
		$order->save();

		$sut->record( wc_get_order( $order->get_id() ), $capture, 2000000 );

		$this->assertSame( '2000000', wc_get_order( $order->get_id() )->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
	}

	/**
	 * @testdox Should swallow and log a failure of the bookkeeping, so the checkout and the wallet's own transition go on.
	 */
	public function test_a_failure_in_the_bookkeeping_is_swallowed_and_logged(): void {
		$this->set_collecting();
		$logger = new RecordingLogger();
		$sut    = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), $logger );
		add_action(
			'woocommerce_paypal_wallet_first_order',
			static function () {
				throw new \RuntimeException( 'listener failed' );
			}
		);
		$order = $this->wallet_order();

		$sut->handle_woocommerce_paypal_wallet_capture_pending( $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );

		$this->assertSame( 'UNILATERAL', wc_get_order( $order->get_id() )->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ), 'The held record is kept' );
		$this->assertCount( 1, $logger->records );
		$this->assertSame( 'warning', $logger->records[0]['level'] );
		$this->assertStringContainsString( 'listener failed', $logger->records[0]['message'] );
		$this->assertStringContainsString( '#' . $order->get_id(), $logger->records[0]['message'] );
	}

	/**
	 * @testdox Should swallow a failure while claiming on payment complete.
	 */
	public function test_a_failure_on_payment_complete_is_swallowed(): void {
		$this->set_collecting();
		$logger = new RecordingLogger();
		$sut    = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), $logger );
		add_action(
			'woocommerce_paypal_wallet_first_order',
			static function () {
				throw new \RuntimeException( 'listener failed' );
			}
		);
		$order = $this->wallet_order();

		$sut->handle_woocommerce_payment_complete( $order->get_id() );

		$this->assertCount( 1, $logger->records );
	}

	/**
	 * @testdox Should not load the order on payment complete unless the store is collecting.
	 * @testWith ["collecting", 1]
	 *           ["platform_connected", 0]
	 *
	 * @param string $state The store state.
	 * @param int    $loads How many times the order is loaded.
	 */
	public function test_payment_complete_loads_the_order_only_while_collecting( string $state, int $loads ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} else {
			$this->set_platform_connected();
		}
		$order = $this->wallet_order();
		$sut   = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$calls = 0;
		$count = static function ( $class_name ) use ( &$calls ) {
			++$calls;
			return $class_name;
		};
		add_filter( 'woocommerce_order_class', $count );

		$sut->handle_woocommerce_payment_complete( $order->get_id() );

		remove_filter( 'woocommerce_order_class', $count );
		$this->assertSame( $loads, $calls );
	}

	/**
	 * @testdox Should fire the first-order action once across two held orders, and still record the second one.
	 */
	public function test_the_first_order_fires_once_across_two_orders(): void {
		$this->set_collecting();
		$this->boot();
		$first  = $this->wallet_order();
		$second = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $first, $this->capture( CaptureStatus::PENDING, 'UNILATERAL', 'CAPTURE-1' ) );
		do_action( 'woocommerce_paypal_wallet_capture_pending', $second, $this->capture( CaptureStatus::PENDING, 'UNILATERAL', 'CAPTURE-2' ) );

		$this->assertCount( 1, $this->first_orders );
		$this->assertSame( $first->get_id(), $this->first_orders[0][0]->get_id() );
		$this->assertSame( 'CAPTURE-2', wc_get_order( $second->get_id() )->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ), 'The second order is still recorded' );
		$this->assertSame( (string) $first->get_id(), (string) get_option( Options::FIRST_ORDER ) );
	}

	/**
	 * @testdox Should do nothing for a pending capture whose reason is not a held one, or has none.
	 * @testWith ["ECHECK"]
	 *           ["PENDING_REVIEW"]
	 *           [null]
	 *
	 * @param string|null $reason The status-details reason.
	 */
	public function test_a_pending_capture_for_another_reason_is_left_alone( ?string $reason ): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, $reason ) );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( array(), $this->notes( $saved ) );
		$this->assertFalse( get_option( Options::FIRST_ORDER ) );
		$this->assertFalse( (bool) get_option( Options::COLLECTING )['payee_bound'] );
		$this->assertCount( 0, $this->first_orders );
	}

	/**
	 * @testdox Should ignore values that are not an order and a capture.
	 */
	public function test_values_that_are_not_an_order_and_a_capture_are_ignored(): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', 'not an order', $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );
		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, 'not a capture' );
		do_action( 'woocommerce_paypal_wallet_capture_pending', null, null );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertCount( 0, $this->first_orders );
	}

	/**
	 * @testdox Should not listen when the platform does not serve the store.
	 */
	public function test_store_not_served_by_the_platform_is_left_alone(): void {
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );
		$order->payment_complete();

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertFalse( get_option( Options::FIRST_ORDER ) );
		$this->assertCount( 0, $this->first_orders );
	}

	/**
	 * @testdox Should record a held capture on a platform-connected store, without binding a payee or claiming a first order.
	 */
	public function test_a_held_capture_on_a_platform_connected_store_claims_nothing(): void {
		$this->set_platform_connected();
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );

		$this->assertSame( 'UNILATERAL', wc_get_order( $order->get_id() )->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertFalse( get_option( Options::FIRST_ORDER ) );
		$this->assertCount( 0, $this->first_orders );
	}

	/**
	 * @testdox Should claim the first order when a wallet order completes at once on a collecting store, with no held meta.
	 */
	public function test_a_completed_wallet_order_claims_the_first_order(): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order();

		$order->payment_complete();

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ), 'No held meta' );
		$this->assertSame( '', $saved->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
		$this->assertTrue( (bool) get_option( Options::COLLECTING )['payee_bound'] );
		$this->assertCount( 1, $this->first_orders );
		$this->assertSame( $order->get_id(), $this->first_orders[0][0]->get_id() );
	}

	/**
	 * @testdox Should claim on completion an order with a PayPal order ID but no pin, and leave other orders alone.
	 * @testWith ["ppcp-gateway", false, true]
	 *           ["ppcp-gateway", true, true]
	 *           ["bacs", false, false]
	 *           ["bacs", true, false]
	 *
	 * @param string $payment_method The payment method.
	 * @param bool   $pinned         Whether the order is pinned.
	 * @param bool   $claims         Whether the order claims the first-order slot.
	 */
	public function test_which_completed_orders_claim( string $payment_method, bool $pinned, bool $claims ): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order( $payment_method, $pinned );

		$order->payment_complete();

		$this->assertCount( $claims ? 1 : 0, $this->first_orders );
		$this->assertEquals( $claims ? $order->get_id() : false, get_option( Options::FIRST_ORDER ) ); // phpcs:ignore WordPress.PHP.StrictComparisons -- The option reads as a string or an int.
	}

	/**
	 * @testdox Should not claim a completed wallet order with no PayPal order ID or pin.
	 */
	public function test_a_completed_order_with_no_paypal_trace_does_not_claim(): void {
		$this->set_collecting();
		$this->boot();
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->save();

		$order->payment_complete();

		$this->assertCount( 0, $this->first_orders );
	}

	/**
	 * @testdox Should not claim on completion once the store is no longer collecting.
	 */
	public function test_a_completed_order_on_a_platform_connected_store_does_not_claim(): void {
		$this->set_platform_connected();
		$this->boot();
		$order = $this->wallet_order();

		$order->payment_complete();

		$this->assertCount( 0, $this->first_orders );
		$this->assertFalse( get_option( Options::FIRST_ORDER ) );
	}

	/**
	 * @testdox Should fire the first-order action once when a held order later completes.
	 */
	public function test_a_held_order_that_later_completes_does_not_fire_twice(): void {
		$this->set_collecting();
		$this->boot();
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $this->capture( CaptureStatus::PENDING, 'UNILATERAL' ) );
		wc_get_order( $order->get_id() )->payment_complete();

		$this->assertCount( 1, $this->first_orders );
	}

	/**
	 * @testdox Should fire the first-order action once when two orders complete.
	 */
	public function test_two_completed_orders_fire_once(): void {
		$this->set_collecting();
		$this->boot();

		$this->wallet_order()->payment_complete();
		$this->wallet_order()->payment_complete();

		$this->assertCount( 1, $this->first_orders );
	}

	/**
	 * @testdox Should ignore a payment-complete for an order that does not exist.
	 */
	public function test_payment_complete_of_a_missing_order_is_ignored(): void {
		$this->set_collecting();
		$this->boot();

		$listener = new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$listener->handle_woocommerce_payment_complete( 999999999 );
		$listener->handle_woocommerce_payment_complete( 'nope' );

		$this->assertCount( 0, $this->first_orders );
	}
}
