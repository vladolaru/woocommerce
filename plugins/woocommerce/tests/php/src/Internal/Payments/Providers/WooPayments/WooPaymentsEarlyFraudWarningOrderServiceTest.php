<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlyFraudWarningOrderService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlyFraudWarningsRestController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the refund side of the list of orders with an actionable early fraud warning.
 *
 * Expected values come from client 11.3.0 (includes/class-wc-payments-order-service.php:342, :1770-1777).
 */
class WooPaymentsEarlyFraudWarningOrderServiceTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEarlyFraudWarningOrderService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$cache = new WooPaymentsDatabaseCache();
		$cache->init( new LegacyProxy() );
		$this->sut = new WooPaymentsEarlyFraudWarningOrderService();
		$this->sut->init( $cache );
		// WooPaymentsAdminRestRouteRegistrar hands the refund over in production; its test covers that path.
		add_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_order_refunded' ) );
		$controller = new WooPaymentsEarlyFraudWarningsRestController();
		$controller->init( $account, $cache, $this->sut );
		$controller->register_routes();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * @testdox A refund of an order holding a warning drops both cached lists, so the next read leaves the fully refunded order out.
	 */
	public function test_refund_of_a_flagged_order_drops_both_lists(): void {
		$flagged = $this->create_order( true );
		$this->assertSame( array( $flagged->get_id() ), $this->get_listed_ids() );
		update_option( 'wcpay_early_fraud_warning_orders_cache', array( 'data' => array() ), false );

		wc_create_refund(
			array(
				'order_id' => $flagged->get_id(),
				'amount'   => 10,
			)
		);

		$this->assertFalse( get_option( 'wcpay_test_early_fraud_warning_orders_cache' ) );
		$this->assertFalse( get_option( 'wcpay_early_fraud_warning_orders_cache' ) );
		$this->assertSame( array(), $this->get_listed_ids() );
	}

	/**
	 * @testdox A refund of an order without a warning keeps both cached lists.
	 */
	public function test_refund_of_an_order_without_a_warning_keeps_both_lists(): void {
		$flagged = $this->create_order( true );
		$this->get_listed_ids();
		update_option( 'wcpay_early_fraud_warning_orders_cache', array( 'data' => array() ), false );
		$other = $this->create_order( false );

		wc_create_refund(
			array(
				'order_id' => $other->get_id(),
				'amount'   => 10,
			)
		);

		$this->assertSame( array( $flagged->get_id() ), wp_list_pluck( get_option( 'wcpay_test_early_fraud_warning_orders_cache' )['data'], 'order_id' ) );
		$this->assertSame( array( 'data' => array() ), get_option( 'wcpay_early_fraud_warning_orders_cache' ) );
	}

	/**
	 * GET the route and return the listed order IDs.
	 *
	 * @return array<int,int>
	 */
	private function get_listed_ids(): array {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/early_fraud_warnings/active' ) );
		$this->assertSame( 200, $response->get_status() );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'order_id' ) );
	}

	/**
	 * Create a $10.00 test-mode order, with or without an actionable warning.
	 *
	 * @param bool $flagged Whether the order holds a warning.
	 * @return WC_Order
	 */
	private function create_order( bool $flagged ): WC_Order {
		$order = wc_create_order();
		$order->set_total( 10 );
		$order->update_meta_data( '_charge_id', 'ch_' . $order->get_id() );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		if ( $flagged ) {
			$order->update_meta_data(
				'_wcpay_early_fraud_warning',
				array(
					'efw_id'         => 'issfr_' . $order->get_id(),
					'efw_actionable' => true,
					'efw_type'       => 'made_with_stolen_card',
					'created'        => 1792314010,
				)
			);
			$order->update_meta_data( '_wcpay_early_fraud_warning_actionable', 1792314010 );
		}
		$order->save();

		return $order;
	}
}
