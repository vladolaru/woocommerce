<?php
declare( strict_types=1 );

use Automattic\WooCommerce\Enums\OrderStatus;

/**
 * Integration tests for the opt-in compute_totals mode of POST /wc/v3/orders/<order_id>/refunds,
 * including backward-compatibility lock-in tests for the default (unflagged) path.
 *
 * @group order-refunds-computed-totals
 */
class WC_REST_Order_Refunds_Computed_Totals_Test extends WC_REST_Unit_Test_Case {

	/**
	 * Shared admin user ID. Created once per class to avoid the wp_insert_user cost
	 * on every test.
	 *
	 * @var int
	 */
	protected static $user_id;

	/**
	 * Create the shared admin user once per class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$user_id = wp_insert_user(
			array(
				'user_login' => 'v3_totals_admin_' . wp_generate_password( 6, false ),
				'user_email' => 'v3_totals_admin_' . wp_generate_password( 6, false ) . '@example.com',
				'user_pass'  => 'password',
				'role'       => 'administrator',
			)
		);
		if ( is_wp_error( self::$user_id ) ) {
			self::fail( 'Could not create test admin user: ' . self::$user_id->get_error_message() );
		}
		self::$user_id = (int) self::$user_id;
	}

	/**
	 * Delete the shared admin user once per class.
	 */
	public static function tearDownAfterClass(): void {
		if ( self::$user_id ) {
			wp_delete_user( self::$user_id );
			self::$user_id = 0;
		}
		parent::tearDownAfterClass();
	}

	/**
	 * Setup our test server, endpoints, and user info.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::$user_id );
	}

	/**
	 * @testdox A quantity-only line item computes the refund amount server-side.
	 */
	public function test_quantity_only_computes_amount(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '25.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox A quantity-only line item on a taxed order computes a tax-inclusive amount with a correct tax split.
	 */
	public function test_quantity_only_computes_amount_with_tax(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '110.00', $response->get_data()['amount'], 'Computed amount should be tax-inclusive.' );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertEquals( '10.00', wc_format_decimal( abs( (float) $refunds[0]->get_total_tax() ), 2 ), 'The stored tax portion should be split out of the inclusive amount.' );
	}

	/**
	 * @testdox A partial quantity computes a proportional amount.
	 */
	public function test_multi_quantity_partial(): void {
		$order   = $this->create_order_with_product( 10.00, 5 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 2,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '20.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox Computed and explicit-amount line items can be mixed in one request.
	 */
	public function test_mixed_computed_and_explicit_lines(): void {
		$order = $this->create_order_with_product_and_fee( 30.00, 10.00 );
		$items = $order->get_items( array( 'line_item', 'fee' ) );

		$line_items = array();
		foreach ( $items as $item_id => $item ) {
			if ( $item instanceof WC_Order_Item_Fee ) {
				$line_items[] = array(
					'id'           => $item_id,
					'refund_total' => 10.00,
				);
			} else {
				$line_items[] = array(
					'id'       => $item_id,
					'quantity' => 1,
				);
			}
		}

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => $line_items,
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '40.00', $response->get_data()['amount'], 'Computed product line (30) plus explicit fee (10) should total 40.' );
	}

	/**
	 * @testdox In compute_totals mode a refund_total without refund_tax is treated as tax-inclusive.
	 */
	public function test_refund_total_without_refund_tax_is_tax_inclusive(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 55.00,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '55.00', $response->get_data()['amount'] );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertEquals( '5.00', wc_format_decimal( abs( (float) $refunds[0]->get_total_tax() ), 2 ), 'Half of the stored $10 tax should be split out of the inclusive 55.00.' );
	}

	/**
	 * @testdox In compute_totals mode an explicit refund_tax keeps tax-exclusive refund_total semantics.
	 */
	public function test_explicit_refund_tax_keeps_net_semantics(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 5.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '55.00', $response->get_data()['amount'], 'Net 50.00 plus explicit tax 5.00 should total 55.00.' );
	}

	/**
	 * @testdox Duplicate tax IDs within a line return 400 duplicate_tax_id and create no refund.
	 */
	public function test_duplicate_refund_tax_ids_return_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 5.00,
							),
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 5.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_duplicate_tax_id', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds(), 'A refund with inconsistent tax accounting must never be created.' );
	}

	/**
	 * @testdox refund_tax combined with an auto-computed refund_total returns 400, not 500.
	 */
	public function test_refund_tax_with_auto_computed_total_returns_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'         => $item_id,
						'quantity'   => 1,
						'refund_tax' => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 5.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A refund_tax entry referencing a tax id not on the line returns 400, not 500.
	 */
	public function test_refund_tax_unknown_tax_id_returns_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id + 999,
								'refund_total' => 5.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A refund_tax entry missing id or refund_total returns 400, not 500.
	 */
	public function test_refund_tax_missing_fields_returns_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array( 'id' => $tax_rate_id ),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A refund_tax total exceeding the remaining refundable tax returns 400, not 500.
	 */
	public function test_refund_tax_exceeding_remaining_tax_returns_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 50.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Auto-computing a refund against a line whose source quantity is zero returns 400, not 500.
	 */
	public function test_zero_source_quantity_auto_compute_returns_400(): void {
		// create_order_with_product() cannot build a zero-quantity line, so the order
		// is assembled here. The order total is non-zero so the order is not treated
		// as already fully refunded, which would fail earlier with a different error.
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'quantity' => 0,
				'subtotal' => 0,
				'total'    => 0,
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->set_total( 10.00 );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item->get_id(),
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertStringContainsString( 'source quantity is zero', $response->get_data()['message'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A quantity-form refund after a partial amount refund is clamped to the line's remaining refundable amount.
	 */
	public function test_quantity_refund_clamped_to_remaining(): void {
		$order   = $this->create_order_with_product( 50.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 30.00,
				'line_items' => array(
					$item_id => array(
						'qty'          => 0,
						'refund_total' => 30.00,
					),
				),
			)
		);

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 2,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '70.00', $response->get_data()['amount'], 'The 100.00 quantity amount should be clamped to the 70.00 remaining on the line.' );
	}

	/**
	 * @testdox An amount-only request works in compute_totals mode.
	 */
	public function test_amount_only_refund(): void {
		$order = $this->create_order_with_product( 50.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'amount'         => '10.00',
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '10.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox A quantity exceeding the refundable units returns 422 quantity_exceeds_refundable.
	 */
	public function test_over_quantity_returns_422(): void {
		$order   = $this->create_order_with_product( 10.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 3,
					),
				),
			)
		);

		$this->assertEquals( 422, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_quantity_exceeds_refundable', $response->get_data()['code'] );
	}

	/**
	 * @testdox A refund_total above the line total returns 422 refund_total_exceeds_line.
	 */
	public function test_refund_total_exceeds_line_returns_422(): void {
		$order   = $this->create_order_with_product( 50.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 60.00,
					),
				),
			)
		);

		$this->assertEquals( 422, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_refund_total_exceeds_line', $response->get_data()['code'] );
	}

	/**
	 * @testdox A non-refundable order status returns 422 order_not_refundable.
	 */
	public function test_non_refundable_status_returns_422(): void {
		$order   = $this->create_order_with_product( 10.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 422, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_order_not_refundable', $response->get_data()['code'] );
	}

	/**
	 * @testdox An amount below the computed line total returns 400 invalid_refund_amount.
	 */
	public function test_amount_below_computed_total_returns_400(): void {
		$order   = $this->create_order_with_product( 50.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'amount'         => '30.00',
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
	}

	/**
	 * @testdox An amount above the order's remaining refundable amount returns 422 refund_exceeds_remaining.
	 */
	public function test_amount_above_remaining_returns_422(): void {
		$order = $this->create_order_with_product( 50.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'amount'         => '80.00',
			)
		);

		$this->assertEquals( 422, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_refund_exceeds_remaining', $response->get_data()['code'] );
	}

	/**
	 * @testdox An explicit zero amount returns 400 invalid_refund_amount instead of falling back to the computed total.
	 */
	public function test_explicit_zero_amount_returns_400(): void {
		$order   = $this->create_order_with_product( 50.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'amount'         => '0.00',
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds(), 'No refund may be created for an explicit zero amount.' );
	}

	/**
	 * @testdox A compute_totals refund matches the totals of the preview endpoint for the same input.
	 */
	public function test_matches_preview(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$preview_request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds/preview' );
		$preview_request->set_body_params(
			array(
				'line_items' => array(
					array(
						'line_item_id' => $item_id,
						'quantity'     => 1,
					),
				),
			)
		);
		$preview_response = $this->server->dispatch( $preview_request );
		$this->assertEquals( 200, $preview_response->get_status() );

		$create_response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);
		$this->assertEquals( 201, $create_response->get_status() );

		$this->assertEquals(
			$preview_response->get_data()['total'],
			$create_response->get_data()['amount'],
			'Preview total and computed create amount must match for identical input.'
		);
	}

	/**
	 * @testdox Computed amounts round to a whole unit in a zero-decimal currency.
	 */
	public function test_zero_decimal_currency(): void {
		add_filter( 'wc_get_price_decimals', '__return_zero' );

		try {
			$order   = $this->create_order_with_product( 100.00, 1 );
			$item_id = $this->get_first_line_item_id( $order );

			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);

			$this->assertEquals( 201, $response->get_status() );
			$this->assertEquals( '100', $response->get_data()['amount'] );
		} finally {
			remove_filter( 'wc_get_price_decimals', '__return_zero' );
		}
	}

	/**
	 * @testdox BC: without compute_totals a quantity-only request still creates a 0.00 refund.
	 */
	public function test_unflagged_quantity_only_still_creates_zero_refund(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		// In production this request path emits a PHP warning (undefined refund_total
		// array key in wc_create_refund; an E_NOTICE on PHP 7.4) and continues.
		// PHPUnit converts it into an exception, which would make wc_create_refund
		// fail with a 500 that does not happen outside the test runner — mask both
		// error levels to test the real behavior.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- scoped mask so PHPUnit does not convert a production-only PHP warning into an exception.
		$error_reporting = error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE );
		try {
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'line_items' => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);
		} finally {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- restore the previous error_reporting level.
			error_reporting( $error_reporting );
		}

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '', $response->get_data()['amount'], 'The pre-existing ghost zero-amount refund behavior (amount serialized as an empty string) must be preserved when the flag is absent.' );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertEquals( 0.0, (float) $refunds[0]->get_amount() );
	}

	/**
	 * @testdox BC: compute_totals explicitly false behaves the same as an absent flag.
	 */
	public function test_compute_totals_false_same_as_absent(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		// See test_unflagged_quantity_only_still_creates_zero_refund for why the
		// PHP warning this legacy path emits must be masked under PHPUnit.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- scoped mask so PHPUnit does not convert a production-only PHP warning into an exception.
		$error_reporting = error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE );
		try {
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => false,
					'line_items'     => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);
		} finally {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- restore the previous error_reporting level.
			error_reporting( $error_reporting );
		}

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '', $response->get_data()['amount'] );
	}

	/**
	 * @testdox BC: without compute_totals a refund_total without refund_tax keeps tax-exclusive semantics.
	 */
	public function test_unflagged_refund_total_stays_tax_exclusive(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'line_items' => array(
					array(
						'id'           => $item_id,
						'refund_total' => 55.00,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '55.00', $response->get_data()['amount'] );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertEquals( 0.0, (float) $refunds[0]->get_total_tax(), 'Without the flag no tax may be split out of refund_total.' );
	}

	/**
	 * @testdox BC: without compute_totals an explicit refund_total plus refund_tax sums to the amount as before.
	 */
	public function test_unflagged_explicit_net_plus_tax_sums_amount(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'line_items' => array(
					array(
						'id'           => $item_id,
						'quantity'     => 1,
						'refund_total' => 100.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 10.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '110.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox A line with refund_tax but no refund_total is still checked against the refund amount and line sign.
	 */
	public function test_unflagged_tax_only_line_is_validated(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '3',
				'line_items' => array(
					array(
						'id'         => $item_id,
						'refund_tax' => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 50,
							),
						),
					),
				),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Refund amount cannot be less than the total of line items.', $response->get_data()['message'] );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '1',
				'line_items' => array(
					array(
						'id'         => $item_id,
						'quantity'   => 1,
						'refund_tax' => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => -40,
							),
						),
					),
				),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Refund tax total has the wrong sign for this line item.', $response->get_data()['message'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A tax-only line without refund_total creates a refund for the requested amount.
	 */
	public function test_unflagged_tax_only_line_creates_refund(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '4',
				'line_items' => array(
					array(
						'id'         => $item_id,
						'refund_tax' => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 4,
							),
						),
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '4.00', $response->get_data()['amount'] );
		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );
		$refund_items = array_values( $refunds[0]->get_items( 'line_item' ) );
		$this->assertCount( 1, $refund_items );
		$this->assertEquals( 0.0, (float) $refund_items[0]->get_total() );
		$this->assertEquals( -4.0, (float) $refunds[0]->get_total_tax() );
	}

	/**
	 * @testdox An omitted amount that cannot be calculated from every line requests an explicit amount.
	 */
	public function test_unflagged_missing_amount_with_incomplete_lines_is_required(): void {
		$order   = $this->create_order_with_product( 100.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );
		$fee     = new WC_Order_Item_Fee();
		$fee->set_total( 10 );
		$order->add_item( $fee );
		$order->set_total( 110 );
		$order->save();

		$line_items = array(
			array(
				'id'           => $item_id,
				'refund_total' => 20,
			),
			array(
				'id'       => $fee->get_id(),
				'quantity' => 1,
			),
		);
		$response   = $this->do_create_request( $order->get_id(), array( 'line_items' => $line_items ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertSame( 'Refund amount is required when it cannot be calculated from line items.', $response->get_data()['message'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '5',
				'line_items' => $line_items,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertSame( 'Refund amount cannot be less than the total of line items.', $response->get_data()['message'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Without compute_totals a net refund plus tax exceeding the line gross is rejected.
	 */
	public function test_unflagged_net_plus_tax_exceeding_line_gross_is_rejected(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'line_items' => array(
					array(
						'id'           => $item_id,
						'quantity'     => 1,
						'refund_total' => 105.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => 10.00,
							),
						),
					),
				),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_refund_total_exceeds_line', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A negative refund on a positive product cannot offset another product's refund.
	 */
	public function test_unflagged_negative_refund_on_positive_line_is_rejected(): void {
		$order   = $this->create_order_with_product( 100.00, 1 );
		$first   = $this->get_first_line_item_id( $order );
		$product = WC_Helper_Product::create_simple_product();
		$order->add_product(
			$product,
			1,
			array(
				'total'    => 100,
				'subtotal' => 100,
			)
		);
		$order->set_total( 200 );
		$order->save();
		$items  = $order->get_items( 'line_item' );
		$second = array_key_last( $items );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '3',
				'line_items' => array(
					array(
						'id'           => $first,
						'refund_total' => 100,
					),
					array(
						'id'           => $second,
						'refund_total' => -97,
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_refund_total', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A negative tax entry cannot offset a positive line refund, even if the tax rate is absent from the line.
	 * @dataProvider provide_lines_with_and_without_tax
	 *
	 * @param bool $taxed Whether the line has the requested tax rate.
	 */
	public function test_unflagged_opposite_sign_tax_is_rejected( bool $taxed ): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $taxed
			? $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 )
			: $this->create_order_with_product( 100.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '3',
				'line_items' => array(
					array(
						'id'           => $this->get_first_line_item_id( $order ),
						'refund_total' => 100,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id,
								'refund_total' => -97,
							),
						),
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_refund_amount', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * Provide taxed and untaxed lines for the explicit refund tax validation test.
	 *
	 * @return array
	 */
	public static function provide_lines_with_and_without_tax(): array {
		return array(
			'taxed line'   => array( true ),
			'untaxed line' => array( false ),
		);
	}

	/**
	 * @testdox A negative discount fee can be refunded alongside a positive product line.
	 */
	public function test_unflagged_negative_discount_fee_refund_succeeds(): void {
		$order = $this->create_order_with_product_and_fee( 100.00, -10.00 );
		$fee   = current( $order->get_items( 'fee' ) );
		$item  = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'line_items' => array(
					array(
						'id'           => $item,
						'refund_total' => 20,
					),
					array(
						'id'           => $fee->get_id(),
						'refund_total' => -5,
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '15.00', $response->get_data()['amount'] );
		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$fees    = $refunds[0]->get_items( 'fee' );
		$this->assertEquals( 5, current( $fees )->get_total() );
	}

	/**
	 * @testdox A line item belonging to another order cannot be used to validate a refund.
	 */
	public function test_unflagged_foreign_order_item_is_rejected(): void {
		$order   = $this->create_order_with_product( 20.00, 1 );
		$foreign = $this->create_order_with_product( 20.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '5',
				'line_items' => array(
					array(
						'id'           => $this->get_first_line_item_id( $foreign ),
						'refund_total' => 5,
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_line_item_not_found', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Explicit fee and shipping refunds respect each line's original total.
	 */
	public function test_unflagged_fee_and_shipping_limits(): void {
		$order = $this->create_order_with_product( 100.00, 1 );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_total( 10 );
		$order->add_item( $fee );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_total( 5 );
		$order->add_item( $shipping );
		$order->set_total( 115 );
		$order->save();

		foreach ( array( $fee, $shipping ) as $item ) {
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'amount'     => '20',
					'line_items' => array(
						array(
							'id'           => $item->get_id(),
							'refund_total' => 20,
						),
					),
				)
			);
			$this->assertSame( 422, $response->get_status() );
			$this->assertSame( 'woocommerce_rest_refund_total_exceeds_line', $response->get_data()['code'] );
		}

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'line_items' => array(
					array(
						'id'           => $fee->get_id(),
						'refund_total' => 5,
					),
					array(
						'id'           => $shipping->get_id(),
						'refund_total' => 2,
					),
				),
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '7.00', $response->get_data()['amount'] );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox An already fully refunded line is rejected even when another line remains refundable.
	 */
	public function test_unflagged_fully_refunded_line_is_rejected(): void {
		$order = $this->create_order_with_product_and_fee( 100.00, 10.00 );
		$fee   = current( $order->get_items( 'fee' ) );
		$first = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '10',
				'line_items' => array(
					array(
						'id'           => $fee->get_id(),
						'refund_total' => 10,
					),
				),
			)
		);
		$this->assertSame( 201, $first->get_status() );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'amount'     => '1',
				'line_items' => array(
					array(
						'id'           => $fee->get_id(),
						'refund_total' => 1,
					),
				),
			)
		);
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_line_item_already_refunded', $response->get_data()['code'] );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A full-line refund with higher-precision tax is accepted at currency precision.
	 */
	public function test_unflagged_high_precision_tax_full_refund(): void {
		$previous = get_option( 'woocommerce_tax_round_at_subtotal' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
		try {
			$rate_id  = $this->create_tax_rate( 8.333 );
			$order    = $this->create_order_with_product_and_tax( 10.00, 1, $rate_id, 0.8333 );
			$item_id  = $this->get_first_line_item_id( $order );
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'amount'     => '10.83',
					'line_items' => array(
						array(
							'id'           => $item_id,
							'refund_total' => 10,
							'refund_tax'   => array(
								array(
									'id'           => $rate_id,
									'refund_total' => 0.8333,
								),
							),
						),
					),
				)
			);
			$this->assertSame( 201, $response->get_status() );
			$this->assertSame( '10.83', $response->get_data()['amount'] );

			$partial_order = $this->create_order_with_product_and_tax( 10.00, 1, $rate_id, 0.8333 );
			$partial_id    = $this->get_first_line_item_id( $partial_order );
			$first         = $this->do_create_request(
				$partial_order->get_id(),
				array(
					'amount'     => '5',
					'line_items' => array(
						array(
							'id'           => $partial_id,
							'refund_total' => 5,
						),
					),
				)
			);
			$this->assertSame( 201, $first->get_status() );

			$remaining = $this->do_create_request(
				$partial_order->get_id(),
				array(
					'amount'     => '5.83',
					'line_items' => array(
						array(
							'id'           => $partial_id,
							'refund_total' => 5,
							'refund_tax'   => array(
								array(
									'id'           => $rate_id,
									'refund_total' => 0.8333,
								),
							),
						),
					),
				)
			);
			$this->assertSame( 201, $remaining->get_status() );
			$this->assertCount( 2, wc_get_order( $partial_order->get_id() )->get_refunds() );
		} finally {
			update_option( 'woocommerce_tax_round_at_subtotal', $previous );
		}
	}

	/**
	 * @testdox The compute_totals parameter is declared in the create schema with a false default.
	 */
	public function test_compute_totals_declared_in_schema(): void {
		$order = $this->create_order_with_product( 10.00, 1 );

		$request  = new WP_REST_Request( 'OPTIONS', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$response = $this->server->dispatch( $request );
		$schema   = $response->get_data()['schema'];

		$this->assertArrayHasKey( 'compute_totals', $schema['properties'] );
		$this->assertFalse( $schema['properties']['compute_totals']['default'] );
	}

	/**
	 * @testdox An array refund_total returns 400 invalid_refund_total instead of a TypeError.
	 */
	public function test_array_refund_total_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => array( 10.00 ),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_refund_total', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A non-numeric quantity returns 400 invalid_quantity.
	 */
	public function test_non_numeric_quantity_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 'two',
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_quantity', $response->get_data()['code'] );
	}

	/**
	 * @testdox A fractional quantity returns 400 invalid_quantity.
	 */
	public function test_fractional_quantity_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1.5,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_quantity', $response->get_data()['code'] );
	}

	/**
	 * @testdox Numeric-string quantity and refund_total values are accepted and cast.
	 */
	public function test_numeric_string_values_are_accepted(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => (string) $item_id,
						'quantity' => '2',
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '50.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox A non-array refund_tax returns 400 invalid_line_item.
	 */
	public function test_non_array_refund_tax_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 10.00,
						'refund_tax'   => 'nope',
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
	}

	/**
	 * @testdox A refund_tax entry with a non-numeric refund_total returns 400 invalid_line_item.
	 */
	public function test_malformed_refund_tax_entry_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 10.00,
						'refund_tax'   => array(
							array(
								'id'           => 1,
								'refund_total' => array( 5.00 ),
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
	}

	/**
	 * @testdox An array line item id returns 400 invalid_line_item.
	 */
	public function test_array_line_item_id_returns_400(): void {
		$order = $this->create_order_with_product( 25.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => array( 1 ),
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
	}

	/**
	 * @testdox A fractional line item id returns 400 invalid_line_item instead of refunding a truncated id.
	 */
	public function test_fractional_line_item_id_returns_400(): void {
		$order   = $this->create_order_with_product( 25.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id + 0.5,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds(), 'A fractional id must never be truncated into a refund of a different line.' );
	}

	/**
	 * @testdox A fractional refund_tax id returns 400 invalid_line_item instead of refunding a truncated tax bucket.
	 */
	public function test_fractional_refund_tax_id_returns_400(): void {
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$order       = $this->create_order_with_product_and_tax( 100.00, 1, $tax_rate_id, 10.00 );
		$item_id     = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'refund_total' => 50.00,
						'refund_tax'   => array(
							array(
								'id'           => $tax_rate_id + 0.5,
								'refund_total' => 5.00,
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A scalar line_items entry returns 400 invalid_line_item.
	 */
	public function test_scalar_line_items_entry_returns_400(): void {
		$order = $this->create_order_with_product( 25.00, 1 );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array( 'not-an-object' ),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
	}

	/**
	 * @testdox Repeating the same line item id in one compute_totals request is rejected.
	 */
	public function test_duplicate_line_item_ids_rejected(): void {
		$order   = $this->create_order_with_product( 10.00, 4 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
					array(
						'id'       => $item_id,
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_duplicate_line_item', $response->get_data()['code'] );
	}

	/**
	 * @testdox A compute_totals request against a nonexistent order id, or a refund id used as the order id, returns a 404.
	 */
	public function test_invalid_order_ids_return_404(): void {
		$body = array(
			'compute_totals' => true,
			'line_items'     => array(),
		);

		$response = $this->do_create_request( 999999999, $body );
		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_order_id', $response->get_data()['code'] );

		// A refund id resolves through wc_get_order to a WC_Order_Refund, which
		// must be rejected the same way as a missing order.
		$order  = $this->create_order_with_product( 10.00, 1 );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 5.00,
			)
		);
		$this->assertNotWPError( $refund );

		$response = $this->do_create_request( $refund->get_id(), $body );
		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_order_id', $response->get_data()['code'] );
	}

	/**
	 * @testdox The pre_insert filter receives internal-format line items and the resolved amount, matching the legacy path's request shape.
	 */
	public function test_pre_insert_filter_receives_internal_format(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$captured = null;
		$capture  = function ( $refund, $request, $creating ) use ( &$captured ) {
			$captured = array(
				'line_items' => $request['line_items'],
				'amount'     => $request['amount'],
				'creating'   => $creating,
			);
			return $refund;
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_refund_object', $capture, 10, 3 );

		try {
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_refund_object', $capture );
		}

		$this->assertEquals( 201, $response->get_status() );
		$this->assertNotNull( $captured, 'The pre_insert filter should run for compute_totals requests.' );
		$this->assertTrue( $captured['creating'] );
		$this->assertSame( '25', $captured['amount'], 'The resolved amount should be mirrored onto the request.' );
		$this->assertSame( array( $item_id ), array_keys( $captured['line_items'] ), 'line_items should be keyed by item id (internal format).' );
		$this->assertSame( 1, $captured['line_items'][ $item_id ]['qty'] );
		$this->assertEquals( 25.00, $captured['line_items'][ $item_id ]['refund_total'] );
	}

	/**
	 * @testdox A wc_create_refund failure returns the same error code and status with and without compute_totals.
	 */
	public function test_create_failure_code_and_status_match_legacy(): void {
		$order   = $this->create_order_with_product( 10.00, 1 );
		$item_id = $this->get_first_line_item_id( $order );

		$fail = function () {
			throw new Exception( 'Simulated create failure.' );
		};
		add_action( 'woocommerce_create_refund', $fail );

		try {
			$computed = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);

			$legacy = $this->do_create_request( $order->get_id(), array( 'amount' => '5.00' ) );
		} finally {
			remove_action( 'woocommerce_create_refund', $fail );
		}

		$this->assertEquals( 500, $computed->get_status() );
		$this->assertEquals( 'woocommerce_rest_cannot_create_order_refund', $computed->get_data()['code'] );
		$this->assertEquals( $legacy->get_status(), $computed->get_status(), 'Both paths should fail with the same HTTP status.' );
		$this->assertEquals( $legacy->get_data()['code'], $computed->get_data()['code'], 'Both paths should fail with the same error code.' );
	}

	/**
	 * @testdox A line item carrying both id and line_item_id is rejected as ambiguous.
	 */
	public function test_conflicting_line_identifiers_rejected(): void {
		$order   = $this->create_order_with_product( 10.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'id'           => $item_id,
						'line_item_id' => $item_id + 1,
						'quantity'     => 1,
					),
				),
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'woocommerce_rest_invalid_line_item', $response->get_data()['code'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds(), 'No refund should be created from an ambiguous line identifier.' );
	}

	/**
	 * @testdox A line item keyed by line_item_id alone is accepted, matching the preview payload shape.
	 */
	public function test_line_item_id_key_accepted(): void {
		$order   = $this->create_order_with_product( 25.00, 2 );
		$item_id = $this->get_first_line_item_id( $order );

		$response = $this->do_create_request(
			$order->get_id(),
			array(
				'compute_totals' => true,
				'line_items'     => array(
					array(
						'line_item_id' => $item_id,
						'quantity'     => 1,
					),
				),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '25.00', $response->get_data()['amount'] );
	}

	/**
	 * @testdox A compute_totals refund of an order in another currency is checked, recorded and refunded at that currency's decimals.
	 *
	 * Expected amounts are the requests in the order currency (USD, two decimals): 10.50 stays 10.50; the 0.25 left after
	 * 17.75 of an 18.00 line can be refunded by amount or by quantity, and a 0.40 amount above it is refused; a 17.50 line
	 * is refunded in full at 17.50, and a 10.50 line at 10.50; one of two 8.75 units is 8.75, also after the other unit was refunded at 8.50 (which
	 * is not its unit price, so the last unit is not topped up to the 9.00 left); a taxed 55.25 splits into 50.23 and
	 * 5.02 tax (10/110 of it); the 0.25 of tax left after 9.75 can be refunded. Store-currency orders keep the store's
	 * precision: 10.50 is 11, also as the full refund of a 10.50 line, and 0.25 of tax rounds to a zero refund.
	 *
	 * Past refunds count at their per-rate taxes, not the line tax core stores at the store's decimals: after one of two
	 * 49.50 units (45.00 + 4.50 tax) the last unit is 49.50, and after 9.75 of tax the explicit (compute_totals off)
	 * refund of the 100.25 left is accepted. The store-currency controls keep today's results: 49 for the last unit, and
	 * the 100.25 refund refused by wc_create_refund() because 100 is left at the store's precision.
	 *
	 * @testWith ["JPY", 0, "USD", 18, 1, false, {}, {"refund_total": "10.50"}, {}, 201, 10.5]
	 *           ["JPY", 0, "USD", 18, 1, false, {"qty": 0, "refund_total": "17.75"}, {"refund_total": "0.25"}, {}, 201, 0.25]
	 *           ["JPY", 0, "USD", 18, 1, false, {"qty": 0, "refund_total": "17.75"}, {"quantity": 1}, {}, 201, 0.25]
	 *           ["JPY", 0, "USD", 18, 1, false, {"qty": 0, "refund_total": "17.75"}, {"refund_total": "0.25"}, {"amount": "0.40"}, 422, "woocommerce_rest_refund_exceeds_remaining"]
	 *           ["JPY", 0, "USD", 17.5, 1, false, {}, {"refund_total": "17.50"}, {}, 201, 17.5]
	 *           ["JPY", 0, "USD", 8.75, 2, false, {}, {"quantity": 1}, {}, 201, 8.75]
	 *           ["JPY", 0, "USD", 8.75, 2, false, {"qty": 1, "refund_total": "8.50"}, {"quantity": 1}, {}, 201, 8.75]
	 *           ["JPY", 0, "USD", 100, 1, true, {}, {"refund_total": "55.25"}, {}, 201, 55.25, [-50.23, -5.02]]
	 *           ["JPY", 0, "USD", 100, 1, true, {"qty": 0, "refund_total": "0", "refund_tax": "9.75"}, {"refund_total": "0", "refund_tax": "0.25"}, {}, 201, 0.25, [0, -0.25]]
	 *           ["JPY", 0, "USD", 10.5, 1, false, {}, {"refund_total": "10.50"}, {}, 201, 10.5]
	 *           ["USD", 0, "USD", 18, 1, false, {}, {"refund_total": "10.50"}, {}, 201, 11]
	 *           ["USD", 0, "USD", 10.5, 1, false, {}, {"refund_total": "10.50"}, {}, 201, 11]
	 *           ["USD", 0, "USD", 100, 1, true, {"qty": 0, "refund_total": "0", "refund_tax": "9.75"}, {"refund_total": "0", "refund_tax": "0.25"}, {}, 400, "woocommerce_rest_invalid_refund_total"]
	 *           ["JPY", 0, "USD", 45, 2, true, {"qty": 1, "refund_total": "45", "refund_tax": "4.50"}, {"quantity": 1}, {}, 201, 49.5, [-45, -4.5]]
	 *           ["USD", 0, "USD", 45, 2, true, {"qty": 1, "refund_total": "45", "refund_tax": "4.50"}, {"quantity": 1}, {}, 201, 49]
	 *           ["JPY", 0, "USD", 100, 1, true, {"qty": 0, "refund_total": "0", "refund_tax": "9.75"}, {"refund_total": "100", "refund_tax": "0.25"}, {"compute_totals": false, "amount": "100.25"}, 201, 100.25, [-100, -0.25]]
	 *           ["USD", 0, "USD", 100, 1, true, {"qty": 0, "refund_total": "0", "refund_tax": "9.75"}, {"refund_total": "100", "refund_tax": "0.25"}, {"compute_totals": false, "amount": "100.25"}, 500, "woocommerce_rest_cannot_create_order_refund"]
	 *
	 * @param string       $store_currency  Store currency.
	 * @param int          $store_decimals  Store price decimals.
	 * @param string       $order_currency  Order currency.
	 * @param float        $unit_price      Net unit price of the order's one line.
	 * @param int          $units           Units on the line.
	 * @param bool         $taxed           Whether the line carries 10% tax on top.
	 * @param array        $prior           A refund of the line made before the request (qty, refund_total, optional refund_tax), or none.
	 * @param array        $line            Request fields of the line besides its id; a refund_tax is the amount for the line's one tax.
	 * @param array        $extra           Other request fields.
	 * @param int          $expected_status Expected response status.
	 * @param float|string $expected        Expected refund amount, or the error code of a refused request.
	 * @param array|null   $expected_split  Expected net and tax parts of the refund lines, for a taxed line.
	 */
	public function test_compute_totals_refund_uses_order_currency_decimals( string $store_currency, int $store_decimals, string $order_currency, float $unit_price, int $units, bool $taxed, array $prior, array $line, array $extra, int $expected_status, $expected, ?array $expected_split = null ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		$tax_rate_id = $taxed ? $this->create_tax_rate( 10.0 ) : 0;
		$order       = $taxed
			? $this->create_order_with_product_and_tax( $unit_price, $units, $tax_rate_id, $unit_price * $units / 10 )
			: $this->create_order_with_product( $unit_price, $units );
		$item_id     = $this->get_first_line_item_id( $order );
		$order->set_currency( $order_currency );
		$order->save();

		if ( $prior ) {
			$prior_tax = (float) ( $prior['refund_tax'] ?? 0 );
			$refund    = wc_create_refund(
				array(
					'order_id'   => $order->get_id(),
					'amount'     => (string) ( (float) $prior['refund_total'] + $prior_tax ),
					'line_items' => array(
						$item_id => array(
							'qty'          => $prior['qty'],
							'refund_total' => $prior['refund_total'],
							'refund_tax'   => $prior_tax ? array( $tax_rate_id => $prior_tax ) : array(),
						),
					),
				)
			);
			$this->assertNotWPError( $refund );
		}

		if ( isset( $line['refund_tax'] ) ) {
			$line['refund_tax'] = array(
				array(
					'id'           => $tax_rate_id,
					'refund_total' => $line['refund_tax'],
				),
			);
		}
		$response = $this->do_create_request(
			$order->get_id(),
			array_merge(
				array(
					'compute_totals' => true,
					'line_items'     => array( array_merge( array( 'id' => $item_id ), $line ) ),
				),
				$extra
			)
		);

		$this->assertSame( $expected_status, $response->get_status() );
		if ( is_string( $expected ) ) {
			$this->assertSame( $expected, $response->get_data()['code'] );
			return;
		}

		// wc_create_refund() asks the gateway for the refund's amount (wc_refund_payment( $order, $refund->get_amount() )).
		$amount = (float) $expected;
		$refund = wc_get_order( $response->get_data()['id'] );
		$this->assertSame( $amount, (float) $refund->get_amount(), 'The refund should record and refund the requested amount.' );
		$this->assertSame( -$amount, (float) $refund->get_total(), 'The refund total should match the amount.' );
		// The per-rate taxes are what the refund engine splits; the line's stored total_tax is rounded separately by
		// WC_Order_Item_Product::set_taxes() at the store's decimals, which this change does not touch.
		$net = 0.0;
		$tax = 0.0;
		foreach ( $refund->get_items() as $refund_item ) {
			$net += (float) $refund_item->get_total();
			$tax += (float) array_sum( $refund_item->get_taxes()['total'] ?? array() );
		}
		$this->assertSame( -$amount, round( $net + $tax, 2 ), 'The refund lines and their taxes should add up to the amount.' );
		if ( null !== $expected_split ) {
			$this->assertSame( (float) $expected_split[0], round( $net, 2 ), 'The refund lines should hold the net part of the split.' );
			$this->assertSame( (float) $expected_split[1], round( $tax, 2 ), 'The refund lines should hold the tax part of the split.' );
		}
	}

	/**
	 * @testdox A second compute_totals refund of a taxed line counts the first refund at its per-rate tax, in the preview, the refund and the gateway call.
	 *
	 * The order holds a 100.00 line with 10.00 tax and another 10.00 line. A first refund of 55.25 splits into 50.23 and
	 * 5.02 tax; core stores that refund line's total_tax at the store's decimals (5 on a whole-yen store) but keeps the
	 * 5.02 per rate. Refunding the line's one unit next leaves 110.00 - 55.25 = 54.75 in the order currency (USD, two
	 * decimals), not 54.77. A store-currency order keeps the store's precision: the first refund is 55, the second 55.
	 *
	 * @testWith ["JPY", 0, "USD", "54.75"]
	 *           ["USD", 0, "USD", "55"]
	 *
	 * @param string $store_currency Store currency.
	 * @param int    $store_decimals Store price decimals.
	 * @param string $order_currency Order currency.
	 * @param string $expected       Expected preview total, refund amount and gateway amount of the second refund.
	 */
	public function test_second_refund_counts_the_first_refund_at_its_per_rate_tax( string $store_currency, int $store_decimals, string $order_currency, string $expected ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );

		$gateway                   = new class() extends WC_Payment_Gateway {
			/**
			 * Amounts the refunds asked the gateway for.
			 *
			 * @var array<int, float>
			 */
			public array $refunded_amounts = array();

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id       = 'computed_totals_recording_gateway';
				$this->supports = array( 'refunds' );
			}

			/**
			 * Record the refund amount.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Refund amount.
			 * @param string     $reason   Refund reason.
			 * @return bool
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				unset( $order_id, $reason ); // Avoid parameter not used PHPCS errors.
				$this->refunded_amounts[] = (float) $amount;
				return true;
			}
		};
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;

			$order   = $this->create_order_with_product_and_tax( 100.00, 1, $this->create_tax_rate( 10.0 ), 10.00 );
			$item_id = $this->get_first_line_item_id( $order );
			$other   = new WC_Order_Item_Product();
			$other->set_props(
				array(
					'name'     => 'Other item',
					'quantity' => 1,
					'subtotal' => 10,
					'total'    => 10,
				)
			);
			$order->add_item( $other );
			$order->set_currency( $order_currency );
			$order->set_payment_method( $gateway->id );
			$order->set_total( 120 );
			$order->save();

			$first = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array(
						array(
							'id'           => $item_id,
							'refund_total' => '55.25',
						),
					),
				)
			);
			$this->assertSame( 201, $first->get_status(), 'The first refund should be created.' );

			$preview = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds/preview' );
			$preview->set_body_params(
				array(
					'line_items' => array(
						array(
							'line_item_id' => $item_id,
							'quantity'     => 1,
						),
					),
				)
			);
			$preview_response = $this->server->dispatch( $preview );
			$this->assertSame( 200, $preview_response->get_status() );
			$this->assertSame( $expected, $preview_response->get_data()['total'], 'The preview should show what is left of the line.' );

			$second = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'api_refund'     => true,
					'line_items'     => array(
						array(
							'id'       => $item_id,
							'quantity' => 1,
						),
					),
				)
			);
			$this->assertSame( 201, $second->get_status(), 'The second refund should be created.' );
			$this->assertSame( (float) $expected, (float) wc_get_order( $second->get_data()['id'] )->get_amount(), 'The second refund should record what is left of the line.' );
			$this->assertSame( array( (float) $expected ), $gateway->refunded_amounts, 'The gateway should be asked for what is left of the line.' );
		} finally {
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
		}
	}

	/**
	 * @testdox The refund engine reads a line's tax, and its past refunds' tax, at the per-rate value in the order currency.
	 *
	 * Core stores a line's total_tax rounded at the store's decimals, also on load, while the line keeps its per-rate
	 * taxes. Expected amounts are the line's own in the order currency (USD, two decimals): a 97.50 line with 9.75 tax
	 * refunds 107.25 for its unit, or 97.50 after the 9.75 of tax was refunded; a 44.60 line with 4.46 tax closes at
	 * 49.06 (explicit and preview), and one of its two units is 24.53; a 10.00 shipping line with 0.25 tax refunds 10.25; after 5.50 of an 11.00 shipping or
	 * fee line (0.50 of it tax), 5.50 is left; three units of a 31.00 line refund 10.33, 10.33 and the 10.34 left; a 10.07
	 * line taxed on two rates (0.6042 and 0.15105, charged as 0.60 and 0.15) refunds 10.82, not 10.83; lines taxed
	 * 4.874 or 4.875 on each of two rates (charged as 4.87 or 4.88 each) refund 107.22 for 97.48 and 107.26 for 97.50.
	 * Store-currency controls keep the store's precision and today's results.
	 *
	 * @testWith ["JPY", 0, "USD", "line_item", 97.5, 1, 9.75, [], {"quantity": 1}, "create", "107.25"]
	 *           ["USD", 0, "USD", "line_item", 97.5, 1, 9.75, [], {"quantity": 1}, "create", "108"]
	 *           ["JPY", 0, "USD", "line_item", 97.5, 1, 9.75, [{"refund_total": "0", "refund_tax": "9.75"}], {"quantity": 1}, "create", "97.50"]
	 *           ["USD", 0, "USD", "line_item", 97.5, 1, 9.75, [{"refund_total": "0", "refund_tax": "9.75"}], {"quantity": 1}, "create", "98"]
	 *           ["JPY", 0, "USD", "line_item", 44.6, 1, 4.46, [], {"refund_total": "44.60", "refund_tax": "4.46"}, "explicit", "49.06"]
	 *           ["USD", 0, "USD", "line_item", 44.6, 1, 4.46, [], {"refund_total": "44.60", "refund_tax": "4.46"}, "explicit", "49.06"]
	 *           ["JPY", 0, "USD", "line_item", 44.6, 2, 4.46, [], {"quantity": 1}, "create", "24.53"]
	 *           ["USD", 0, "USD", "line_item", 44.6, 2, 4.46, [], {"quantity": 1}, "create", "24"]
	 *           ["JPY", 0, "USD", "line_item", 44.6, 1, 4.46, [], {"refund_total": 49.06}, "preview", "49.06"]
	 *           ["USD", 0, "USD", "line_item", 44.6, 1, 4.46, [], {"refund_total": 49.06}, "preview", "49"]
	 *           ["JPY", 0, "USD", "shipping", 10, 1, 0.25, [], {"quantity": 1}, "create", "10.25"]
	 *           ["USD", 0, "USD", "shipping", 10, 1, 0.25, [], {"quantity": 1}, "create", "10"]
	 *           ["JPY", 0, "USD", "shipping", 10, 1, 1, [{"refund_total": "5.50"}], {"quantity": 1}, "create", "5.50"]
	 *           ["USD", 0, "USD", "shipping", 10, 1, 1, [{"refund_total": "5.50"}], {"quantity": 1}, "create", "5"]
	 *           ["JPY", 0, "USD", "fee", 10, 1, 1, [{"refund_total": "5.50"}], {"quantity": 1}, "create", "5.50"]
	 *           ["USD", 0, "USD", "fee", 10, 1, 1, [{"refund_total": "5.50"}], {"quantity": 1}, "create", "5"]
	 *           ["JPY", 0, "USD", "line_item", 30, 3, 1, [{"quantity": 1}, {"quantity": 1}], {"quantity": 1}, "create", "10.34"]
	 *           ["USD", 0, "USD", "line_item", 30, 3, 1, [{"quantity": 1}, {"quantity": 1}], {"quantity": 1}, "create", "11"]
	 *           ["JPY", 0, "USD", "line_item", 10.07, 1, [0.6042, 0.15105], [], {"quantity": 1}, "create", "10.82"]
	 *           ["USD", 2, "USD", "line_item", 10.07, 1, [0.6042, 0.15105], [], {"quantity": 1}, "create", "10.82"]
	 *           ["JPY", 0, "USD", "line_item", 97.48, 1, [4.874, 4.874], [], {"quantity": 1}, "create", "107.22"]
	 *           ["JPY", 0, "USD", "line_item", 97.5, 1, [4.875, 4.875], [], {"quantity": 1}, "create", "107.26"]
	 *
	 * @param string $store_currency Store currency.
	 * @param int    $store_decimals Store price decimals.
	 * @param string $order_currency Order currency.
	 * @param string $type           Type of the refunded line: line_item, shipping or fee.
	 * @param float  $net            Net total of the refunded line.
	 * @param int    $units          Units on the refunded line.
	 * @param mixed  $tax            Tax of the refunded line on one rate, or a list of the taxes on two rates.
	 * @param array  $prior          compute_totals refunds of the line made first, as request fields besides its id.
	 * @param array  $line           Request fields of the line besides its id; a refund_tax is the amount for its one rate.
	 * @param string $mode           create (compute_totals: its preview, the refund and the gateway amount), explicit (compute_totals off, amount = line gross) or preview.
	 * @param string $expected       Expected refund amount, or the preview total.
	 */
	public function test_refund_engine_reads_line_taxes_at_their_per_rate_value( string $store_currency, int $store_decimals, string $order_currency, string $type, float $net, int $units, $tax, array $prior, array $line, string $mode, string $expected ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		// Per-line tax rounding (the default): each rate is charged rounded, which the two-rate row depends on.
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		$tax_rate_id = $this->create_tax_rate( 10.0 );
		$rate_taxes  = is_array( $tax )
			? array(
				$tax_rate_id                  => $tax[0],
				$this->create_tax_rate( 1.5 ) => $tax[1],
			)
			: array( $tax_rate_id => $tax );
		$taxes       = array(
			'total'    => $rate_taxes,
			'subtotal' => $rate_taxes,
		);

		$order = wc_create_order();
		if ( 'shipping' === $type ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_props(
				array(
					'method_title' => 'Flat rate',
					'total'        => $net,
				)
			);
		} elseif ( 'fee' === $type ) {
			$item = new WC_Order_Item_Fee();
			$item->set_props(
				array(
					'name'       => 'Handling',
					'tax_status' => 'taxable',
					'total'      => $net,
				)
			);
		} else {
			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'name'     => 'Taxed item',
					'quantity' => $units,
					'subtotal' => $net,
					'total'    => $net,
				)
			);
		}
		$item->set_taxes( $taxes );
		$order->add_item( $item );
		$other = new WC_Order_Item_Product();
		$other->set_props(
			array(
				'name'     => 'Other item',
				'quantity' => 1,
				'subtotal' => 10,
				'total'    => 10,
			)
		);
		$order->add_item( $other );
		$order->set_currency( $order_currency );
		$order->set_total( $net + array_sum( $rate_taxes ) + 10 );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();
		$item_id = $item->get_id();

		$with_id = static function ( array $fields, string $id_key ) use ( $item_id, $tax_rate_id ): array {
			if ( isset( $fields['refund_tax'] ) ) {
				$fields['refund_tax'] = array(
					array(
						'id'           => $tax_rate_id,
						'refund_total' => $fields['refund_tax'],
					),
				);
			}
			return array_merge( array( $id_key => $item_id ), $fields );
		};

		foreach ( $prior as $prior_line ) {
			$response = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array( $with_id( $prior_line, 'id' ) ),
				)
			);
			$this->assertSame( 201, $response->get_status(), 'An earlier refund should be created.' );
		}

		if ( 'preview' === $mode ) {
			$response = $this->do_preview_request( $order->get_id(), array( $with_id( $line, 'line_item_id' ) ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $expected, $response->get_data()['total'] );
			return;
		}

		$body = array( 'line_items' => array( $with_id( $line, 'id' ) ) );
		if ( 'explicit' === $mode ) {
			$body['amount'] = (string) ( (float) $line['refund_total'] + (float) ( $line['refund_tax'] ?? 0 ) );
			$response       = $this->do_create_request( $order->get_id(), $body );
			$this->assertSame( 201, $response->get_status() );
			$this->assertSame( (float) $expected, (float) wc_get_order( $response->get_data()['id'] )->get_amount() );
			return;
		}

		// A compute_totals refund: the preview of the same line, the refund and the amount the gateway is asked for agree.
		$preview = $this->do_preview_request( $order->get_id(), array( $with_id( $line, 'line_item_id' ) ) );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( $expected, $preview->get_data()['total'], 'The preview should show the refund amount.' );

		$gateway                   = new class() extends WC_Payment_Gateway {
			/**
			 * Amounts the refunds asked the gateway for.
			 *
			 * @var array<int, float>
			 */
			public array $refunded_amounts = array();

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id       = 'computed_totals_line_tax_gateway';
				$this->supports = array( 'refunds' );
			}

			/**
			 * Record the refund amount.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Refund amount.
			 * @param string     $reason   Refund reason.
			 * @return bool
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				unset( $order_id, $reason ); // Avoid parameter not used PHPCS errors.
				$this->refunded_amounts[] = (float) $amount;
				return true;
			}
		};
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;
		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;

			$order = wc_get_order( $order->get_id() );
			$order->set_payment_method( $gateway->id );
			$order->save();

			$body['compute_totals'] = true;
			$body['api_refund']     = true;
			$response               = $this->do_create_request( $order->get_id(), $body );

			$this->assertSame( 201, $response->get_status() );
			$this->assertSame( (float) $expected, (float) wc_get_order( $response->get_data()['id'] )->get_amount(), 'The refund should record the amount.' );
			$this->assertSame( array( (float) $expected ), $gateway->refunded_amounts, 'The gateway should be asked for the amount.' );
		} finally {
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
		}
	}

	/**
	 * Send a refund preview request and return the response.
	 *
	 * @param int   $order_id   Order ID.
	 * @param array $line_items Line items.
	 * @return WP_REST_Response
	 */
	private function do_preview_request( int $order_id, array $line_items ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order_id . '/refunds/preview' );
		$request->set_body_params( array( 'line_items' => $line_items ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * @testdox A refund with explicit sub-cent taxes is counted at the taxes it paid back, in the next refund's preview, amount and gateway call.
	 *
	 * Line A is 50.00 with a tax on each of two rates, quantity 1, beside an untaxed line B; the order paid 60.00. A first
	 * refund of A sends 5.00 and an explicit tax of 0.054 (or 0.056) per rate, pays back 5.11 and stores those taxes as
	 * sent. The next refund of A counts that refund at 5.108 (or 5.112), as the line's own taxes were charged at 0.54 (or
	 * 0.56) each: one unit leaves 51.08 - 5.108 = 45.97, and a close of 51.12 - 5.112 = 46.01 is accepted. Store-currency
	 * controls keep today's results, where core stores the refund taxes rounded: 45.98, and the 46.01 close refused.
	 *
	 * @testWith ["JPY", 0, "USD", 0.54, 8.92, "0.054", {"quantity": 1}, 201, "45.97"]
	 *           ["USD", 2, "USD", 0.54, 8.92, "0.054", {"quantity": 1}, 201, "45.98"]
	 *           ["JPY", 0, "USD", 0.56, 8.88, "0.056", {"refund_total": "46.01"}, 201, "46.01"]
	 *           ["USD", 2, "USD", 0.56, 8.88, "0.056", {"refund_total": "46.01"}, 422, "woocommerce_rest_refund_total_exceeds_remaining"]
	 *
	 * @param string $store_currency  Store currency.
	 * @param int    $store_decimals  Store price decimals.
	 * @param string $order_currency  Order currency.
	 * @param float  $tax             Tax of line A on each of its two rates.
	 * @param float  $other           Net total of line B.
	 * @param string $refund_tax      Explicit tax per rate of the first refund, beside its 5.00.
	 * @param array  $line            Fields of the second request's line besides its id.
	 * @param int    $expected_status Expected status of the second refund.
	 * @param string $expected        Expected preview total, refund amount and gateway amount, or the refusal's code.
	 */
	public function test_explicit_tax_refund_is_counted_at_the_tax_it_paid_back( string $store_currency, int $store_decimals, string $order_currency, float $tax, float $other, string $refund_tax, array $line, int $expected_status, string $expected ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );
		// Per-line tax rounding (the default): each rate is charged rounded.
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		$rate_ids   = array( $this->create_tax_rate( 1.08 ), $this->create_tax_rate( 1.081 ) );
		$rate_taxes = array_fill_keys( $rate_ids, $tax );

		$gateway                   = new class() extends WC_Payment_Gateway {
			/**
			 * Amounts the refunds asked the gateway for.
			 *
			 * @var array<int, float>
			 */
			public array $refunded_amounts = array();

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id       = 'computed_totals_explicit_tax_gateway';
				$this->supports = array( 'refunds' );
			}

			/**
			 * Record the refund amount.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Refund amount.
			 * @param string     $reason   Refund reason.
			 * @return bool
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				unset( $order_id, $reason ); // Avoid parameter not used PHPCS errors.
				$this->refunded_amounts[] = (float) $amount;
				return true;
			}
		};
		$payment_gateway_registry  = WC_Payment_Gateways::instance();
		$original_payment_gateways = $payment_gateway_registry->payment_gateways;

		try {
			$payment_gateway_registry->payment_gateways[] = $gateway;

			$order  = wc_create_order();
			$line_a = new WC_Order_Item_Product();
			$line_a->set_props(
				array(
					'name'     => 'Line A',
					'quantity' => 1,
					'subtotal' => 50,
					'total'    => 50,
				)
			);
			$line_a->set_taxes(
				array(
					'total'    => $rate_taxes,
					'subtotal' => $rate_taxes,
				)
			);
			$order->add_item( $line_a );
			$line_b = new WC_Order_Item_Product();
			$line_b->set_props(
				array(
					'name'     => 'Line B',
					'quantity' => 1,
					'subtotal' => $other,
					'total'    => $other,
				)
			);
			$order->add_item( $line_b );
			$order->set_currency( $order_currency );
			$order->set_payment_method( $gateway->id );
			$order->set_total( '60.00' );
			$order->set_status( OrderStatus::COMPLETED );
			$order->save();

			$first = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'line_items'     => array(
						array(
							'id'           => $line_a->get_id(),
							'refund_total' => '5.00',
							'refund_tax'   => array(
								array(
									'id'           => $rate_ids[0],
									'refund_total' => $refund_tax,
								),
								array(
									'id'           => $rate_ids[1],
									'refund_total' => $refund_tax,
								),
							),
						),
					),
				)
			);
			$this->assertSame( 201, $first->get_status(), 'The first refund should be created.' );
			$this->assertSame( 5.11, (float) wc_get_order( $first->get_data()['id'] )->get_amount(), 'The first refund should pay back 5.11.' );

			$preview = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds/preview' );
			$preview->set_body_params( array( 'line_items' => array( array_merge( array( 'line_item_id' => $line_a->get_id() ), $line ) ) ) );
			$preview_response = $this->server->dispatch( $preview );

			$second = $this->do_create_request(
				$order->get_id(),
				array(
					'compute_totals' => true,
					'api_refund'     => true,
					'line_items'     => array( array_merge( array( 'id' => $line_a->get_id() ), $line ) ),
				)
			);

			if ( 201 !== $expected_status ) {
				$this->assertSame( $expected_status, $preview_response->get_status() );
				$this->assertSame( $expected, $preview_response->get_data()['code'] );
				$this->assertSame( $expected_status, $second->get_status() );
				$this->assertSame( $expected, $second->get_data()['code'] );
				$this->assertSame( array(), $gateway->refunded_amounts, 'The gateway should not be called for a refused refund.' );
				return;
			}

			$this->assertSame( 200, $preview_response->get_status() );
			$this->assertSame( $expected, $preview_response->get_data()['total'], 'The preview should show what is left of line A.' );
			$this->assertSame( 201, $second->get_status(), 'The second refund should be created.' );
			$this->assertSame( (float) $expected, (float) wc_get_order( $second->get_data()['id'] )->get_amount(), 'The second refund should record what is left of line A.' );
			$this->assertSame( array( (float) $expected ), $gateway->refunded_amounts, 'The gateway should be asked for what is left of line A.' );
		} finally {
			$payment_gateway_registry->payment_gateways = $original_payment_gateways;
		}
	}

	/**
	 * Create a completed order with a single product line item.
	 *
	 * @param float $unit_price Unit price.
	 * @param int   $quantity   Quantity.
	 * @return WC_Order
	 */
	private function create_order_with_product( float $unit_price, int $quantity ): WC_Order {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $unit_price );
		$product->save();

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => $quantity,
				'subtotal' => $unit_price * $quantity,
				'total'    => $unit_price * $quantity,
			)
		);
		$item->save();
		$order->add_item( $item );
		$order->set_total( $unit_price * $quantity );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		$product->delete( true );

		return $order;
	}

	/**
	 * Create a completed order with one product line item and one fee line.
	 *
	 * @param float $product_price Product price.
	 * @param float $fee_total     Fee total.
	 * @return WC_Order
	 */
	private function create_order_with_product_and_fee( float $product_price, float $fee_total ): WC_Order {
		$order = $this->create_order_with_product( $product_price, 1 );

		$fee = new WC_Order_Item_Fee();
		$fee->set_props(
			array(
				'name'  => 'Service fee',
				'total' => $fee_total,
			)
		);
		$fee->save();
		$order->add_item( $fee );
		$order->set_total( $product_price + $fee_total );
		$order->save();

		return $order;
	}

	/**
	 * Create an order with a product and tax.
	 *
	 * @param float $product_price Product price.
	 * @param int   $quantity      Quantity.
	 * @param int   $tax_rate_id   Tax rate ID.
	 * @param float $tax_amount    Tax amount.
	 * @return WC_Order
	 */
	private function create_order_with_product_and_tax( float $product_price, int $quantity, int $tax_rate_id, float $tax_amount ): WC_Order {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $product_price );
		$product->set_tax_status( 'taxable' );
		$product->save();

		$total = $product_price * $quantity;
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => $quantity,
				'subtotal' => $total,
				'total'    => $total,
			)
		);
		$item->set_taxes(
			array(
				'total'    => array( $tax_rate_id => $tax_amount ),
				'subtotal' => array( $tax_rate_id => $tax_amount ),
			)
		);
		$item->save();
		$order->add_item( $item );

		$tax_item = new WC_Order_Item_Tax();
		$tax_item->set_rate( $tax_rate_id );
		$tax_item->set_tax_total( $tax_amount );
		$tax_item->save();
		$order->add_item( $tax_item );

		$order->set_billing_country( 'US' );
		$order->set_total( $total + $tax_amount );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		$product->delete( true );

		return $order;
	}

	/**
	 * Create a tax rate.
	 *
	 * @param float $rate Tax rate percentage.
	 * @return int Tax rate ID.
	 */
	private function create_tax_rate( float $rate ): int {
		return WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => '',
				'tax_rate'          => number_format( $rate, 4 ),
				'tax_rate_name'     => 'Tax',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);
	}

	/**
	 * Get the first line item ID from an order.
	 *
	 * @param WC_Order $order Order instance.
	 * @return int Line item ID.
	 */
	private function get_first_line_item_id( WC_Order $order ): int {
		$items = $order->get_items( 'line_item' );
		$item  = reset( $items );
		return $item->get_id();
	}

	/**
	 * Send a refund creation request and return the response.
	 *
	 * api_refund is forced to false unless the test supplies it: the test orders
	 * have no payment gateway, so the v3 default of true would make wc_refund_payment
	 * fail and delete the refund.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $body     Request body parameters.
	 * @return WP_REST_Response
	 */
	private function do_create_request( int $order_id, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order_id . '/refunds' );
		$request->set_body_params( array_merge( array( 'api_refund' => false ), $body ) );
		return $this->server->dispatch( $request );
	}
}
