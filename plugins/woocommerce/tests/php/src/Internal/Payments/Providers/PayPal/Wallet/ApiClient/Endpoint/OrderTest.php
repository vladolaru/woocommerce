<?php
/**
 * Tests for the order entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use DateTime;

/**
 * The order entity (Order, which sits in Entity/; the extension filed its test under Endpoint/ and the file keeps that
 * place): what it holds and how it is written into an array.
 *
 * @group paypal-wallet
 */
class OrderTest extends WalletTestCase {

	private const DATE_FORMAT = 'Y-m-d\TH:i:sO';

	/**
	 * @testdox Should hold what it is given and write it into the array.
	 */
	public function test_order(): void {
		$id          = 'id';
		$create_time = new DateTime();
		$update_time = new DateTime();
		$unit        = $this->mock( PurchaseUnit::class );
		$unit->shouldReceive( 'to_array' )->once()->andReturn( array( 1 ) );
		$status = $this->mock( OrderStatus::class );
		$status->shouldReceive( 'name' )->once()->andReturn( 'CREATED' );
		$payer = $this->mock( Payer::class );
		$payer->shouldReceive( 'to_array' )->once()->andReturn( array( 'payer' ) );
		$intent         = 'AUTHORIZE';
		$payment_source = $this->mock( PaymentSource::class );

		$testee = new Order( $id, array( $unit ), $status, $payment_source, $payer, $intent, $create_time, $update_time );

		$this->assertSame( $id, $testee->id() );
		$this->assertSame( $create_time, $testee->create_time() );
		$this->assertSame( $update_time, $testee->update_time() );
		$this->assertSame( array( $unit ), $testee->purchase_units() );
		$this->assertSame( $payer, $testee->payer() );
		$this->assertSame( $intent, $testee->intent() );
		$this->assertSame( $status, $testee->status() );
		$this->assertSame( $payment_source, $testee->payment_source() );
		$this->assertSame(
			array(
				'id'             => $id,
				'intent'         => $intent,
				'status'         => 'CREATED',
				'purchase_units' => array( array( 1 ) ),
				'create_time'    => $create_time->format( self::DATE_FORMAT ),
				'payer'          => array( 'payer' ),
				'update_time'    => $update_time->format( self::DATE_FORMAT ),
			),
			$testee->to_array()
		);
	}

	/**
	 * @testdox Should default to the capture intent and leave out the payer and the dates when it has none.
	 */
	public function test_order_no_dates_or_payer(): void {
		$unit = $this->mock( PurchaseUnit::class );
		$unit->shouldReceive( 'to_array' )->once()->andReturn( array( 1 ) );
		$status = $this->mock( OrderStatus::class );
		$status->shouldReceive( 'name' )->once()->andReturn( 'CREATED' );

		$testee = new Order( 'id', array( $unit ), $status );

		$this->assertNull( $testee->create_time() );
		$this->assertNull( $testee->update_time() );
		$this->assertNull( $testee->payer() );
		$this->assertSame( 'CAPTURE', $testee->intent() );

		$array = $testee->to_array();
		$this->assertArrayNotHasKey( 'payer', $array );
		$this->assertArrayNotHasKey( 'create_time', $array );
		$this->assertArrayNotHasKey( 'update_time', $array );
	}
}
