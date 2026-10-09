<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\SellerStatus;
use WC_Unit_Test_Case;

/**
 * Tests for the seller status value object.
 *
 * @group paypal-wallet
 */
class SellerStatusTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should hand back the values it was built with.
	 */
	public function test_getters(): void {
		$sut = new SellerStatus( 'M1', true, false, true );

		$this->assertSame( 'M1', $sut->merchant_id() );
		$this->assertTrue( $sut->payments_receivable() );
		$this->assertFalse( $sut->primary_email_confirmed() );
		$this->assertTrue( $sut->consent_granted() );
	}

	/**
	 * @testdox Should be complete only with a merchant ID, receivable payments, a confirmed email and granted consent.
	 * @testWith ["M1", true, true, true, true]
	 *           ["", true, true, true, false]
	 *           ["M1", false, true, true, false]
	 *           ["M1", true, false, true, false]
	 *           ["M1", true, true, false, false]
	 *
	 * @param string $merchant_id             The merchant ID.
	 * @param bool   $payments_receivable     Whether payments are receivable.
	 * @param bool   $primary_email_confirmed Whether the primary email is confirmed.
	 * @param bool   $consent_granted         Whether consent is granted.
	 * @param bool   $expected                Whether the status is complete.
	 */
	public function test_is_complete( string $merchant_id, bool $payments_receivable, bool $primary_email_confirmed, bool $consent_granted, bool $expected ): void {
		$sut = new SellerStatus( $merchant_id, $payments_receivable, $primary_email_confirmed, $consent_granted );

		$this->assertSame( $expected, $sut->is_complete() );
	}
}
