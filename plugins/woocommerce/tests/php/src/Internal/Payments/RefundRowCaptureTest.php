<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for RefundRowCapture.
 */
class RefundRowCaptureTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A refund WooCommerce creates to refund through the gateway is handed to that order's refund call of its amount, once, with the ID it got when saved.
	 *
	 * wc_create_refund() fires `woocommerce_create_refund` before it saves the refund again (includes/wc-order-functions.php:672-674);
	 * this case starts from an unsaved refund, as a direct caller of the hook may.
	 */
	public function test_hands_over_the_gateway_refund_once_with_its_saved_id(): void {
		$order  = $this->create_order();
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '4.25' );
		$sut = $this->create_capture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );
		$refund->save();

		$this->assertSame( $refund->get_id(), $sut->consume( $order, 4.25 ) );
		$this->assertNull( $sut->consume( $order, 4.25 ), 'It is handed over once.' );
	}

	/**
	 * @testdox Nothing is handed over for $refund_kind; a gateway refund stays kept for its own call.
	 * @dataProvider refunds_not_handed_over_data
	 *
	 * Codex review 205 R2 and monitor ruling 2026-10-10 17:25: a call takes only the refund kept for it, so a call that
	 * does not match leaves the kept refund for the call it belongs to (this replaces the forgetting that review 204 F5
	 * pinned while a call took the innermost refund regardless).
	 *
	 * @param string $refund_kind    What differs.
	 * @param bool   $refund_payment Whether the refund goes through the gateway.
	 * @param bool   $other_order    Whether the refund belongs to another order.
	 * @param float  $amount         The refund call's amount.
	 */
	public function test_hands_nothing_over_when_the_refund_does_not_match( string $refund_kind, bool $refund_payment, bool $other_order, float $amount ): void {
		unset( $refund_kind );
		$order  = $this->create_order();
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $other_order ? $this->create_order()->get_id() : $order->get_id() );
		$refund->set_amount( '4.25' );
		$refund->save();
		$sut = $this->create_capture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => $refund_payment ) );

		$this->assertNull( $sut->consume( $order, $amount ) );
		$this->assertSame( $refund_payment ? $refund->get_id() : null, $sut->consume( wc_get_order( $refund->get_parent_id() ), 4.25 ), 'A gateway refund stays kept for its own call.' );
	}

	/**
	 * Refunds that are not this call's.
	 *
	 * @return array<string,array{string,bool,bool,float}>
	 */
	public function refunds_not_handed_over_data(): array {
		return array(
			'a manual refund' => array( 'a manual refund', false, false, 4.25 ),
			'another order'   => array( 'another order', true, true, 4.25 ),
			'another amount'  => array( 'another amount', true, false, 4.00 ),
		);
	}

	/**
	 * @testdox A manual refund created while a gateway refund is kept leaves it kept for that refund's call.
	 *
	 * Codex review 204 F3: a callback on the gateway refund's `woocommerce_create_refund` may create a manual refund
	 * before WooCommerce refunds through the gateway.
	 */
	public function test_a_manual_refund_leaves_the_kept_gateway_refund(): void {
		$order   = $this->create_order();
		$gateway = $this->create_refund( $order, '4.25' );
		$sut     = $this->create_capture();

		$sut->handle_create_refund( $gateway, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $this->create_refund( $order, '1.00' ), array( 'refund_payment' => false ) );

		$this->assertSame( $gateway->get_id(), $sut->consume( $order, 4.25 ) );
	}

	/**
	 * @testdox A gateway refund created inside another one's call is handed to its own call first, then the outer one to its call.
	 *
	 * Codex review 204 F3 and monitor ruling 2026-10-10 16:30: each wc_create_refund() call keeps its own refund, and a
	 * refund call takes the one kept for the innermost call still running.
	 */
	public function test_nested_gateway_refunds_are_handed_to_their_own_calls(): void {
		$outer_order = $this->create_order();
		$inner_order = $this->create_order();
		$outer       = $this->create_refund( $outer_order, '4.25' );
		$inner       = $this->create_refund( $inner_order, '3.00' );
		$sut         = $this->create_capture();

		$sut->handle_create_refund( $outer, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $inner, array( 'refund_payment' => true ) );

		$this->assertSame( $inner->get_id(), $sut->consume( $inner_order, 3.00 ), 'The inner call takes its own refund.' );
		$this->assertSame( $outer->get_id(), $sut->consume( $outer_order, 4.25 ), 'The outer call takes its own refund.' );
		$this->assertNull( $sut->consume( $outer_order, 4.25 ) );
	}

	/**
	 * @testdox A refund call takes its own refund past a newer one kept for a call that has not consumed it.
	 *
	 * Codex review 205 R2 and monitor ruling 2026-10-10 17:25: a call takes the refund whose order and amount match it,
	 * not the innermost one regardless.
	 */
	public function test_a_call_takes_its_own_refund_past_a_newer_one(): void {
		$outer_order = $this->create_order();
		$inner_order = $this->create_order();
		$outer       = $this->create_refund( $outer_order, '4.25' );
		$inner       = $this->create_refund( $inner_order, '3.00' );
		$sut         = $this->create_capture();

		$sut->handle_create_refund( $outer, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $inner, array( 'refund_payment' => true ) );

		$this->assertSame( $outer->get_id(), $sut->consume( $outer_order, 4.25 ), 'The outer call takes its own refund.' );
		$this->assertSame( $inner->get_id(), $sut->consume( $inner_order, 3.00 ), 'The newer refund stays kept for its own call.' );
	}

	/**
	 * @testdox Of two kept refunds of the same order and amount, a call takes the newer one first: the inner call's.
	 *
	 * Codex review 205 R2: WooCommerce refunds through the gateway before its call returns, so an inner call's refund
	 * call comes before the outer one's.
	 */
	public function test_a_call_takes_the_newest_matching_refund(): void {
		$order = $this->create_order();
		$outer = $this->create_refund( $order, '4.25' );
		$inner = $this->create_refund( $order, '4.25' );
		$sut   = $this->create_capture();

		$sut->handle_create_refund( $outer, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $inner, array( 'refund_payment' => true ) );

		$this->assertSame( $inner->get_id(), $sut->consume( $order, 4.25 ) );
		$this->assertSame( $outer->get_id(), $sut->consume( $order, 4.25 ) );
	}

	/**
	 * @testdox A refund through another plugin's gateway is not kept.
	 *
	 * Codex review 205 R2: no refund call of the runtime ever takes it, so keeping it only left it in the way.
	 */
	public function test_keeps_only_refunds_through_the_runtime_gateways(): void {
		$order = $this->create_order();
		$order->set_payment_method( 'other_plugin_gateway' );
		$order->save();
		$refund = $this->create_refund( $order, '4.25' );
		$sut    = $this->create_capture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );

		$this->assertNull( $sut->consume( $order, 4.25 ) );
	}

	/**
	 * @testdox A kept refund is forgotten once WooCommerce $event it, so no later call takes it.
	 * @dataProvider refund_lifetime_end_data
	 *
	 * Codex review 205 R2 and monitor ruling 2026-10-10 17:25: the refund's wc_create_refund() call has returned, so its
	 * gateway call can no longer come.
	 *
	 * @param string $event How the refund's call ended.
	 */
	public function test_forgets_a_refund_whose_call_has_ended( string $event ): void {
		$order  = $this->create_order();
		$refund = $this->create_refund( $order, '4.25' );
		$sut    = $this->create_capture();
		$sut->register();
		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );
		$refund_id = $refund->get_id();

		if ( 'created' === $event ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as wc_create_refund() does.
			do_action( 'woocommerce_refund_created', $refund_id, array( 'refund_payment' => true ) );
		} else {
			// Through another object, as code deleting a row by its ID does; the kept object keeps its ID.
			wc_get_order( $refund_id )->delete( true );
		}

		$this->assertNull( $sut->consume( $order, 4.25 ) );
	}

	/**
	 * How a refund's wc_create_refund() call ends.
	 *
	 * @return array<string,array{string}>
	 */
	public function refund_lifetime_end_data(): array {
		return array(
			'created' => array( 'created' ),
			'deleted' => array( 'deleted' ),
		);
	}

	/**
	 * @testdox With prices shown without decimals, a kept 4.25 refund is handed to a refund call of $amount: $handed_over.
	 * @dataProvider undisplayed_decimals_data
	 *
	 * Codex review 204 F4: the amounts were compared at the display precision, so 4.25 and 4.00 both read "4" and a
	 * call for another amount took the row.
	 *
	 * @param float $amount      The refund call's amount.
	 * @param bool  $handed_over Whether the kept refund is handed over.
	 */
	public function test_compares_amounts_past_the_display_precision( float $amount, bool $handed_over ): void {
		add_filter( 'wc_get_price_decimals', static fn(): int => 0 );
		$order  = $this->create_order();
		$refund = $this->create_refund( $order, '4.25' );
		$sut    = $this->create_capture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );

		$this->assertSame( $handed_over ? $refund->get_id() : null, $sut->consume( $order, $amount ) );
	}

	/**
	 * Refund call amounts against a kept 4.25 refund.
	 *
	 * @return array<string,array{float,bool}>
	 */
	public function undisplayed_decimals_data(): array {
		return array(
			'the same amount'                  => array( 4.25, true ),
			'an amount that displays the same' => array( 4.00, false ),
		);
	}

	/**
	 * @testdox A refund kept on one site is never handed to a refund call on another site of the network, and stays kept for its own call on its site.
	 *
	 * Codex review 204 F4: order and refund IDs can repeat across a network's sites, so a call after switch_to_blog()
	 * could take another site's row. The site is simulated through the global get_current_blog_id() reads, since the test
	 * suite runs single-site. Codex review 205 R3 asked to pin the other site's call as forgetting the refund; R2 (monitor
	 * ruling 2026-10-10 17:25) supersedes that premise: a call that does not match leaves the refund for its own call.
	 */
	public function test_hands_nothing_over_on_another_site(): void {
		$order  = $this->create_order();
		$refund = $this->create_refund( $order, '4.25' );
		$sut    = $this->create_capture();
		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );
		$blog_id = $GLOBALS['blog_id'];

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates switch_to_blog(), unavailable single-site.
		$GLOBALS['blog_id'] = (int) $blog_id + 1;
		try {
			$handed_over = $sut->consume( $order, 4.25 );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the simulated switch.
			$GLOBALS['blog_id'] = $blog_id;
		}

		$this->assertNull( $handed_over );
		$this->assertSame( $refund->get_id(), $sut->consume( $order, 4.25 ), 'Back on its site, the refund is still kept for its own call.' );
	}

	/**
	 * @testdox A refund recorded without the gateway is marked as a manual record when WooCommerce creates it; a refund through the gateway is not.
	 *
	 * Codex review 205 R1 and monitor ruling 2026-10-10 17:40: only a row carrying the mark is ever taken for the
	 * merchant's manual record of an earlier refund, so a row still inside a datastore write, which has no mark yet, never is.
	 */
	public function test_marks_only_refunds_recorded_without_the_gateway(): void {
		$sut    = $this->create_capture();
		$marked = array();
		$kinds  = array(
			'manual'  => false,
			'gateway' => true,
		);

		foreach ( $kinds as $kind => $refund_payment ) {
			$refund = new WC_Order_Refund();
			$refund->set_parent_id( $this->create_order()->get_id() );
			$refund->set_amount( '4.25' );
			$refund->save();

			$sut->handle_create_refund( $refund, array( 'refund_payment' => $refund_payment ) );
			$refund->save();

			$marked[ $kind ] = wc_get_order( $refund->get_id() )->get_meta( RefundRowCapture::MANUAL_REFUND_META, true );
		}

		$this->assertSame( 'yes', $marked['manual'] );
		$this->assertSame( '', $marked['gateway'] );
	}

	/**
	 * @testdox A refund recorded manually through $entry is stored with the manual record mark.
	 * @dataProvider manual_refund_entries_data
	 *
	 * Monitor ruling 2026-10-10 17:40: both of WooCommerce's merchant entry points create the row through
	 * wc_create_refund() without refund_payment, whose save after `woocommerce_create_refund` (includes/wc-order-functions.php:674)
	 * stores the mark.
	 *
	 * @param string $entry The entry point.
	 */
	public function test_marks_a_refund_recorded_manually( string $entry ): void {
		$sut = $this->create_capture();
		$sut->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = $this->create_order();

		$refund_id = 'rest' === $entry ? $this->refund_through_rest( $order ) : $this->refund_through_ajax( $order );

		$this->assertGreaterThan( 0, $refund_id );
		$this->assertSame( 'yes', wc_get_order( $refund_id )->get_meta( RefundRowCapture::MANUAL_REFUND_META, true ) );
	}

	/**
	 * WooCommerce's manual refund entry points.
	 *
	 * @return array<string,array{string}>
	 */
	public function manual_refund_entries_data(): array {
		return array(
			'Refund manually on the order screen' => array( 'ajax' ),
			'a REST refund with api_refund false' => array( 'rest' ),
		);
	}

	/**
	 * Refund 1.00 of an order through the REST API without the gateway.
	 *
	 * @param WC_Order $order Order.
	 * @return int The refund ID.
	 */
	private function refund_through_rest( WC_Order $order ): int {
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_body_params(
			array(
				'amount'     => '1.00',
				'api_refund' => false,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Refund 1.00 of an order with "Refund manually" on the order screen (WC_AJAX::refund_line_items()).
	 *
	 * @param WC_Order $order Order.
	 * @return int The refund ID.
	 */
	private function refund_through_ajax( WC_Order $order ): int {
		$_POST['order_id']        = (string) $order->get_id();
		$_POST['refund_amount']   = '1.00';
		$_POST['refunded_amount'] = '0.00';
		$_POST['api_refund']      = 'false';
		$_REQUEST['security']     = wp_create_nonce( 'order-item' );
		$die_handler              = static function () {
			return static function () {
				throw new \RuntimeException( 'ajax-die' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );
		add_filter( 'wp_doing_ajax', '__return_true' );
		// The handler opens a buffer of its own, which the die handler leaves open.
		$level = ob_get_level();
		ob_start();
		try {
			\WC_AJAX::refund_line_items();
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'ajax-die', $exception->getMessage() );
		} finally {
			$json = '';
			while ( ob_get_level() > $level ) {
				$json = (string) ob_get_clean() . $json;
			}
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			unset( $_POST['order_id'], $_POST['refund_amount'], $_POST['refunded_amount'], $_POST['api_refund'], $_REQUEST['security'] );
		}
		$this->assertTrue( json_decode( $json, true )['success'] ?? false, 'The manual refund must succeed: ' . $json );
		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );

		return $refunds[0]->get_id();
	}

	/**
	 * Create a capture whose controller owns the `runtime_gateway` gateway the test orders are paid with.
	 *
	 * @return RefundRowCapture
	 */
	private function create_capture(): RefundRowCapture {
		$controller = $this->createMock( ProviderGatewaysController::class );
		$controller->method( 'owns_gateway' )->willReturnCallback( static fn( string $gateway_id ): bool => 'runtime_gateway' === $gateway_id );
		$capture = new RefundRowCapture();
		$capture->init( $controller );

		return $capture;
	}

	/**
	 * Create a saved refund of an order.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param string   $amount Amount.
	 * @return WC_Order_Refund
	 */
	private function create_refund( WC_Order $order, string $amount ): WC_Order_Refund {
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( $amount );
		$refund->save();

		return $refund;
	}

	/**
	 * Create a saved order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'runtime_gateway' );
		$order->set_total( '10.00' );
		$order->save();

		return $order;
	}
}
