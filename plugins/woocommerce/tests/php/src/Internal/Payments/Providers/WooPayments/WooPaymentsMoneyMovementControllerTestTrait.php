<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use WC_Order;

/**
 * Shared fixtures for native WooPayments money-movement REST controller tests.
 *
 * Used by the dedicated authorizations and disputes controller test classes, and by the
 * remaining transactions/payment-details coverage in {@see WooPaymentsMoneyMovementRestControllerTest}.
 */
trait WooPaymentsMoneyMovementControllerTestTrait {

	/**
	 * Create an order enrichment service.
	 *
	 * @return WooPaymentsOrderDetailsService
	 */
	private function create_order_service(): WooPaymentsOrderDetailsService {
		$service = new WooPaymentsOrderDetailsService();
		$service->init( new WooPaymentsOrderDataService() );

		return $service;
	}

	/**
	 * Create an order with WooPayments charge metadata.
	 *
	 * @param string $charge_id Charge ID.
	 * @param string $intent_id Intent ID.
	 * @return WC_Order
	 */
	private function create_order_with_charge( string $charge_id, string $intent_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_currency( 'USD' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_customer_ip_address( '127.0.0.1' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->save();

		return $order;
	}
}
