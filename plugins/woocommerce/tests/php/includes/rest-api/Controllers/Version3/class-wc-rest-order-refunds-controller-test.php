<?php

/**
 * Class WC_REST_Order_Refunds_Controller_Test.
 */

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Tests\Helpers\MetaDataAssertionTrait;

/**
 * Tests for the V3 Order Refunds REST API controller.
 */
class WC_REST_Order_Refunds_Controller_Test extends WC_REST_Unit_Test_Case {
	use MetaDataAssertionTrait;

	/**
	 * @testdox A nested V3 refund can be created, read, listed, and permanently deleted.
	 */
	public function test_nested_refund_lifecycle(): void {
		wp_set_current_user( 1 );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();
		$order_item = current( $order->get_items( 'line_item' ) );

		$collection = '/wc/v3/orders/' . $order->get_id() . '/refunds';
		$request    = new WP_REST_Request( 'POST', $collection );
		$request->set_body_params(
			array(
				'amount'      => '5.00',
				'reason'      => 'Damaged item refund',
				'api_refund'  => false,
				'api_restock' => false,
				'line_items'  => array(
					array(
						'id'           => $order_item->get_id(),
						'quantity'     => 1,
						'refund_total' => 5,
					),
				),
			)
		);
		$response = $this->server->dispatch( $request );
		$created  = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$refund_id = $created['id'];
		$this->assertIsInt( $refund_id );
		$this->assertSame( '5.00', $created['amount'] );
		$this->assertSame( 'Damaged item refund', $created['reason'] );
		$this->assertCount( 1, $created['line_items'] );
		$this->assertSame( $product->get_id(), $created['line_items'][0]['product_id'] );
		$this->assertSame( '-5.00', $created['line_items'][0]['total'] );
		$this->assertSame( $refund_id, current( wc_get_order( $order->get_id() )->get_refunds() )->get_id() );

		$item_path = $collection . '/' . $refund_id;
		$response  = $this->server->dispatch( new WP_REST_Request( 'GET', $item_path ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $refund_id, $response->get_data()['id'] );
		$this->assertSame( '5.00', $response->get_data()['amount'] );
		$this->assertSame( 'Damaged item refund', $response->get_data()['reason'] );
		$this->assertSame( $product->get_id(), $response->get_data()['line_items'][0]['product_id'] );

		$links = $response->get_links();
		$this->assertSame( rest_url( $item_path ), $links['self'][0]['href'] );
		$this->assertSame( rest_url( $collection ), $links['collection'][0]['href'] );
		$this->assertSame( rest_url( '/wc/v3/orders/' . $order->get_id() ), $links['up'][0]['href'] );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $collection ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $refund_id ), wp_list_pluck( $response->get_data(), 'id' ) );

		$request = new WP_REST_Request( 'DELETE', $item_path );
		$request->set_param( 'force', true );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $refund_id, $response->get_data()['id'] );
		$this->assertSame( 404, $this->server->dispatch( new WP_REST_Request( 'GET', $item_path ) )->get_status() );
		$this->assertFalse( wc_get_order( $refund_id ) );
		$this->assertSame( array(), wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Explicit item refunds reject amounts above the order line's paid total.
	 */
	public function test_explicit_refund_rejects_line_overage(): void {
		wp_set_current_user( 1 );
		$order   = WC_Helper_Order::create_order();
		$item_id = current( $order->get_items( 'line_item' ) )->get_id();

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_body_params(
			array(
				'amount'     => '3',
				'api_refund' => false,
				'line_items' => array(
					array(
						'id'           => $item_id,
						'quantity'     => 0,
						'refund_total' => 30000,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_refund_total_exceeds_line', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Explicit item refunds reject amounts exceeding the remaining balance after a partial refund.
	 */
	public function test_explicit_refund_rejects_overage_after_partial_refund(): void {
		wp_set_current_user( 1 );
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '100' );
		$product->save();
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();
		$item_id = current( $order->get_items( 'line_item' ) )->get_id();
		$url     = '/wc/v3/orders/' . $order->get_id() . '/refunds';

		foreach ( array( 50, 60 ) as $refund_total ) {
			$request = new WP_REST_Request( 'POST', $url );
			$request->set_body_params(
				array(
					'amount'     => (string) $refund_total,
					'api_refund' => false,
					'line_items' => array(
						array(
							'id'           => $item_id,
							'quantity'     => 0,
							'refund_total' => $refund_total,
						),
					),
				)
			);
			$response = $this->server->dispatch( $request );
			if ( 50 === $refund_total ) {
				$this->assertSame( 201, $response->get_status(), 'The first partial refund should succeed.' );
			}
		}

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_refund_total_exceeds_remaining', $response->get_data()['code'] );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Explicit item refunds reject allocations above their stated order refund amount.
	 */
	public function test_explicit_refund_rejects_amount_below_item_total(): void {
		wp_set_current_user( 1 );
		$order   = WC_Helper_Order::create_order();
		$item_id = current( $order->get_items( 'line_item' ) )->get_id();
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_body_params(
			array(
				'amount'     => '3',
				'api_refund' => false,
				'line_items' => array(
					array(
						'id'           => $item_id,
						'refund_total' => 4,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * Test if line, fees and shipping items are all included in refund response.
	 */
	public function test_items_response_fields() {
		wp_set_current_user( 1 );
		$order = WC_Helper_Order::create_order_with_fees_and_shipping();

		$product_item  = current( $order->get_items( 'line_item' ) );
		$fee_item      = current( $order->get_items( 'fee' ) );
		$shipping_item = current( $order->get_items( 'shipping' ) );

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'reason'     => 'testing',
				'line_items' => array(
					$product_item->get_id()  =>
						array(
							'qty'          => 1,
							'refund_total' => 1,
						),
					$fee_item->get_id()      =>
						array(
							'refund_total' => 10,
						),
					$shipping_item->get_id() =>
						array(
							'refund_total' => 20,
						),
				),
			)
		);

		$this->assertNotWPError( $refund );

		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() . '/refunds/' . $refund->get_id() );

		$response = $this->server->dispatch( $request );
		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertContains( 'line_items', array_keys( $data ) );
		$this->assertEquals( -1, $data['line_items'][0]['total'] );

		$this->assertContains( 'fee_lines', array_keys( $data ) );
		$this->assertEquals( -10, $data['fee_lines'][0]['total'] );

		$this->assertContains( 'shipping_lines', array_keys( $data ) );
		$this->assertEquals( -20, $data['shipping_lines'][0]['total'] );
	}

	/**
	 * @testdox Creating a refund with incomplete meta_data entries does not cause errors.
	 */
	public function test_create_refund_meta_data_with_incomplete_entries(): void {
		wp_set_current_user( 1 );
		$order = WC_Helper_Order::create_order();
		$order->set_status( 'completed' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'amount'     => '1.00',
					'api_refund' => false,
					'meta_data'  => $this->get_incomplete_meta_data_input(),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 201, $response->get_status() );

		$this->assert_incomplete_meta_data_handled_correctly( wc_get_order( $response->get_data()['id'] ) );
	}

	/**
	 * @testdox An amount-only V3 refund stores its total and the order's remaining amount in the order currency's decimals.
	 *
	 * @testWith ["JPY", 0, "USD", "5.25", "-5.25", "12.75", "0.25", "-0.25", "12.50"]
	 *           ["JPY", 0, "USD", "5.75", "-5.75", "12.25", "12.25", "-12.25", "0.00"]
	 *           ["USD", 2, "KWD", "5.125", "-5.125", "12.875", "0.125", "-0.125", "12.750"]
	 *           ["USD", 0, "USD", "5.25", "-5", "13", "2", "-2", "11"]
	 *
	 * @param string $store_currency   Store currency.
	 * @param int    $store_decimals   Store price decimals.
	 * @param string $order_currency   Order currency.
	 * @param string $first_amount     First requested refund amount.
	 * @param string $first_total      Expected total of the first refund.
	 * @param string $first_remaining  Expected remaining amount after the first refund.
	 * @param string $second_amount    Second requested refund amount.
	 * @param string $second_total     Expected total of the second refund.
	 * @param string $second_remaining Expected remaining amount after the second refund.
	 */
	public function test_create_refund_uses_order_currency_decimals( string $store_currency, int $store_decimals, string $order_currency, string $first_amount, string $first_total, string $first_remaining, string $second_amount, string $second_total, string $second_remaining ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		wp_set_current_user( 1 );

		$order = wc_create_order();
		$order->set_currency( $order_currency );
		$order->set_total( '18' );
		$order->set_status( OrderStatus::PROCESSING );
		$order->save();

		foreach ( array( array( $first_amount, $first_total, $first_remaining ), array( $second_amount, $second_total, $second_remaining ) ) as $step ) {
			list( $amount, $expected_total, $expected_remaining ) = $step;

			$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
			$request->set_body_params(
				array(
					'amount'     => $amount,
					'api_refund' => false,
				)
			);
			$response = $this->server->dispatch( $request );

			$this->assertSame( 201, $response->get_status(), "The {$amount} refund should be created." );
			$refund = wc_get_order( $response->get_data()['id'] );
			$this->assertSame( $amount, $refund->get_amount(), "The {$amount} refund should keep its amount." );
			$this->assertSame( $expected_total, $refund->get_total(), "The {$amount} refund should store its total in the order currency's decimals." );
			$this->assertSame( $expected_remaining, wc_get_order( $order->get_id() )->get_remaining_refund_amount(), "The remaining amount after the {$amount} refund should use the order currency's decimals." );
		}
	}

	/**
	 * @testdox Explicit item refunds compare their line total with the refund amount in the order currency's decimals.
	 *
	 * Expected results are the comparison done in the order currency (USD, two decimals): 5.25 of line items is more than
	 * an amount of 5, and 0.25 is more than 0; a 10.50 line is refunded in full at 10.50. A store-currency order keeps
	 * the store's precision, where that refund's total is stored as -11.
	 *
	 * @testWith ["JPY", 0, "USD", "5.25", "5", 400]
	 *           ["JPY", 0, "USD", "0.25", "0", 400]
	 *           ["JPY", 0, "USD", "5.25", "5.25", 201]
	 *           ["USD", 0, "USD", "5.25", "5", 201]
	 *           ["JPY", 0, "USD", "10.50", "10.50", 201, "10.50", "-10.50"]
	 *           ["USD", 0, "USD", "10.50", "10.50", 201, "10.50", "-11"]
	 *
	 * @param string $store_currency  Store currency.
	 * @param int    $store_decimals  Store price decimals.
	 * @param string $order_currency  Order currency.
	 * @param string $line_total      Requested refund_total of the line.
	 * @param string $amount          Requested refund amount.
	 * @param int    $expected_status Expected response status.
	 * @param string $original        Total of the order's one line.
	 * @param string $expected_total  Expected total of the stored refund, or empty to skip the stored values.
	 */
	public function test_explicit_refund_compares_line_items_with_amount_in_order_currency_decimals( string $store_currency, int $store_decimals, string $order_currency, string $line_total, string $amount, int $expected_status, string $original = '18', string $expected_total = '' ): void {
		$order = $this->create_order_with_one_line_in_currency( $store_currency, $store_decimals, $order_currency, $original );

		$response = $this->request_line_refund( $order, $line_total, $amount );

		$this->assertSame( $expected_status, $response->get_status() );
		if ( 400 === $expected_status ) {
			$this->assertSame( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		}
		$this->assertCount( 400 === $expected_status ? 0 : 1, wc_get_order( $order->get_id() )->get_refunds() );
		if ( '' !== $expected_total ) {
			$refund = current( wc_get_order( $order->get_id() )->get_refunds() );
			$this->assertSame( $amount, $refund->get_amount(), 'The refund should store the requested amount.' );
			$this->assertSame( $expected_total, $refund->get_total(), 'The refund total should use the order currency precision.' );
		}
	}

	/**
	 * @testdox Explicit item refunds check the line's remaining amount in the order currency's decimals.
	 *
	 * Expected results are the remaining amount in the order currency (USD, two decimals): after 17.75 of an 18.00 line,
	 * 0.25 is left and can be refunded, and then nothing is left. A store-currency order keeps the store's precision,
	 * where the 0.25 left rounds to 0.
	 *
	 * @testWith ["JPY", 0, "USD", [201, 201, 422]]
	 *           ["USD", 0, "USD", [201, 422, 422]]
	 *
	 * @param string $store_currency    Store currency.
	 * @param int    $store_decimals    Store price decimals.
	 * @param string $order_currency    Order currency.
	 * @param int[]  $expected_statuses Expected status of the 17.75, 0.25 and 0.25 refunds.
	 */
	public function test_explicit_refund_checks_line_remaining_in_order_currency_decimals( string $store_currency, int $store_decimals, string $order_currency, array $expected_statuses ): void {
		$order = $this->create_order_with_one_line_in_currency( $store_currency, $store_decimals, $order_currency );

		foreach ( array( '17.75', '0.25', '0.25' ) as $step => $line_total ) {
			$response = $this->request_line_refund( $order, $line_total, $line_total );

			$this->assertSame( $expected_statuses[ $step ], $response->get_status(), "Refund {$step} of {$line_total}." );
			if ( 422 === $expected_statuses[ $step ] ) {
				$this->assertSame( 'woocommerce_rest_line_item_already_refunded', $response->get_data()['code'] );
			}
		}
		$this->assertCount( count( array_keys( $expected_statuses, 201, true ) ), wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * Create a processing order with one line in the given currency, on a store with the given currency and decimals.
	 *
	 * @param string $store_currency Store currency.
	 * @param int    $store_decimals Store price decimals.
	 * @param string $order_currency Order currency.
	 * @param string $line_total     Total of the line, and of the order.
	 * @return WC_Order
	 */
	private function create_order_with_one_line_in_currency( string $store_currency, int $store_decimals, string $order_currency, string $line_total = '18' ): WC_Order {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		wp_set_current_user( 1 );

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'name'     => 'Item',
				'quantity' => 1,
				'subtotal' => $line_total,
				'total'    => $line_total,
			)
		);
		$order->add_item( $item );
		$order->set_currency( $order_currency );
		$order->set_total( $line_total );
		$order->set_status( OrderStatus::PROCESSING );
		$order->save();

		return $order;
	}

	/**
	 * Request a V3 refund of the order's only line.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $line_total Requested refund_total of the line.
	 * @param string   $amount     Requested refund amount.
	 * @return WP_REST_Response
	 */
	private function request_line_refund( WC_Order $order, string $line_total, string $amount ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_body_params(
			array(
				'amount'     => $amount,
				'api_refund' => false,
				'line_items' => array(
					array(
						'id'           => current( $order->get_items() )->get_id(),
						'refund_total' => $line_total,
					),
				),
			)
		);

		return $this->server->dispatch( $request );
	}

	/**
	 * @testdox Should report refunded_by as 0 when no user issued the refund.
	 *
	 * @see https://github.com/woocommerce/woocommerce/issues/36329
	 */
	public function test_refunded_by_is_zero_when_no_user_issued_the_refund(): void {
		wp_set_current_user( 1 );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		// A refund with no recorded user, as a gateway webhook or cron run produces.
		$refund = new WC_Order_Refund();
		$refund->set_amount( 5 );
		$refund->set_parent_id( $order->get_id() );
		$refund->set_refunded_by( 0 );
		$refund->save();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() . '/refunds/' . $refund->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			0,
			$response->get_data()['refunded_by'],
			'An unattributed refund reports 0 rather than the id of user 1.'
		);
	}
}
