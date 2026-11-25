<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService;
use WC_Unit_Test_Case;

/**
 * Tests the inspection of a pending merchant refund.
 */
class WooPaymentsRefundAmbiguityServiceTest extends WC_Unit_Test_Case {
	/**
	 * @testdox Only a record for the requested charge blocks a refund, and inspection preserves the record.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $valid Whether the stored request belongs to this charge.
	 */
	public function test_check_preserves_the_merchant_record( bool $valid ): void {
		$order  = wc_create_order();
		$record = array(
			'order_id'  => $order->get_id(),
			'charge_id' => 'ch_held',
			'key'       => 'held-key',
			'request'   => array( 'charge' => $valid ? 'ch_held' : 'ch_other' ),
		);
		$order->update_meta_data( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, $record );
		$order->save_meta_data();
		$sut = wc_get_container()->get( WooPaymentsRefundAmbiguityService::class );
		$this->assertSame( $valid, $sut->has_unsettled_attempt( $order, 'ch_held' ) );
		$this->assertSame( $record, wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, true ) );
	}
}
