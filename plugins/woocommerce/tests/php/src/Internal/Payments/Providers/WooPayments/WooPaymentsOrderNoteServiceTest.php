<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderNoteService class.
 */
class WooPaymentsOrderNoteServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Note-local identity deduplicates changed content without writing order metadata.
	 */
	public function test_note_local_identity_deduplicates_changed_content_without_order_metadata(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );

		$this->assertTrue( $sut->add_note_once( $order, 'Fee details in English', 'fee:charge:ch_123' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Uebersetzte Gebuehrendetails', 'fee:charge:ch_123' ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Fee details in English', $notes[0]->content );
		$this->assertNotSame( '', get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_fee_breakdown_note_ids', true ) );
	}

	/**
	 * @testdox Exact note content can retain multiple private provider identities.
	 */
	public function test_exact_content_can_retain_multiple_private_identities(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );

		$this->assertTrue( $sut->add_note_once( $order, 'Shared fee details', 'fee:charge:ch_1' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Shared fee details', 'fee:charge:ch_2' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated fee details', 'fee:charge:ch_1' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated fee details', 'fee:charge:ch_2' ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertCount( 2, get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', false ) );
	}
}
