<?php
/**
 * Tests for the order factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use DateTime;
use stdClass;
use WC_Order;

/**
 * Builds an order from a WooCommerce order or from a PayPal response.
 *
 * @group paypal-wallet
 */
class OrderFactoryTest extends WalletTestCase {

	/**
	 * The format PayPal's timestamps are written in.
	 */
	private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:sO';

	/**
	 * @testdox Should keep the PayPal order and swap in the purchase unit of the WooCommerce order.
	 */
	public function test_from_wc_order(): void {
		$create_time = new DateTime();
		$update_time = new DateTime();
		$payer       = $this->mock( Payer::class );
		$status      = $this->mock( OrderStatus::class );
		$order       = $this->mock( Order::class );
		$order->expects( 'id' )->andReturn( 'id' );
		$order->expects( 'status' )->andReturn( $status );
		$order->expects( 'payer' )->andReturn( $payer );
		$order->expects( 'intent' )->andReturn( 'intent' );
		$order->expects( 'create_time' )->andReturn( $create_time );
		$order->expects( 'update_time' )->andReturn( $update_time );
		$order->expects( 'payment_source' )->andReturnNull();
		$order->expects( 'links' )->andReturnNull();
		$wc_order              = $this->mock( WC_Order::class );
		$purchase_unit_factory = $this->mock( PurchaseUnitFactory::class );
		$purchase_unit         = $this->mock( PurchaseUnit::class );
		$purchase_unit_factory->expects( 'from_wc_order' )->with( $wc_order )->andReturn( $purchase_unit );
		$payer_factory = $this->mock( PayerFactory::class );

		$testee = new OrderFactory( $purchase_unit_factory, $payer_factory );
		$result = $testee->from_wc_order( $wc_order, $order );

		$this->assertEquals( $purchase_unit, current( $result->purchase_units() ) );
	}

	/**
	 * @testdox Should build the order from a PayPal response, leaving out what the response does not have.
	 *
	 * @dataProvider data_for_test_from_paypal_response_test
	 *
	 * @param object $order_data The order object PayPal sent.
	 */
	public function test_from_paypal_response( $order_data ): void {
		$purchase_unit_factory = $this->mock( PurchaseUnitFactory::class );
		if ( count( $order_data->purchase_units ) ) {
			$purchase_unit_factory
				->expects( 'from_paypal_response' )
				->times( count( $order_data->purchase_units ) )
				->andReturn( $this->mock( PurchaseUnit::class ) );
		}
		$payer_factory = $this->mock( PayerFactory::class );
		if ( isset( $order_data->payer ) ) {
			$payer_factory
				->expects( 'from_paypal_response' )
				->andReturn( $this->mock( Payer::class ) );
		}

		$testee = new OrderFactory( $purchase_unit_factory, $payer_factory );
		$order  = $testee->from_paypal_response( $order_data );

		$this->assertCount( count( $order_data->purchase_units ), $order->purchase_units() );
		$this->assertEquals( $order_data->id, $order->id() );
		$this->assertEquals( $order_data->status, $order->status()->name() );
		$this->assertEquals( $order_data->intent, $order->intent() );
		if ( ! isset( $order_data->create_time ) ) {
			$this->assertNull( $order->create_time() );
		} else {
			$this->assertEquals( $order_data->create_time, $order->create_time()->format( self::TIMESTAMP_FORMAT ) );
		}
		if ( ! isset( $order_data->payer ) ) {
			$this->assertNull( $order->payer() );
		} else {
			$this->assertInstanceOf( Payer::class, $order->payer() );
		}
		if ( ! isset( $order_data->update_time ) ) {
			$this->assertNull( $order->update_time() );
		} else {
			$this->assertEquals( $order_data->update_time, $order->update_time()->format( self::TIMESTAMP_FORMAT ) );
		}
		if ( isset( $order_data->links ) ) {
			$this->assertEquals( $order_data->links, $order->links() );
		} else {
			$this->assertNull( $order->links() );
		}
	}

	/**
	 * A full order and the order with the timestamps, the payer and the links in turn left out or added.
	 *
	 * @return array<string, array<object>>
	 */
	public function data_for_test_from_paypal_response_test(): array {
		$full = array(
			'id'             => 'id',
			'purchase_units' => array( new stdClass(), new stdClass() ),
			'status'         => OrderStatus::APPROVED,
			'intent'         => 'CAPTURE',
			'create_time'    => '2005-08-15T15:52:01+0000',
			'update_time'    => '2005-09-15T15:52:01+0000',
			'payer'          => new stdClass(),
		);

		$without = static function ( string $key ) use ( $full ): array {
			$data = $full;
			unset( $data[ $key ] );
			return array( (object) $data );
		};

		return array(
			'default'        => array( (object) $full ),
			'no_update_time' => $without( 'update_time' ),
			'no_create_time' => $without( 'create_time' ),
			'no_payer'       => $without( 'payer' ),
			'with_links'     => array(
				(object) array_merge(
					$full,
					array(
						'status' => OrderStatus::PAYER_ACTION_REQUIRED,
						'links'  => array(
							(object) array(
								'rel'  => 'payer-action',
								'href' => 'https://example.com/3ds',
							),
						),
					)
				),
			),
		);
	}

	/**
	 * @testdox Should throw when the PayPal response has no ID or an empty status.
	 *
	 * @dataProvider data_for_test_from_paypal_response_exceptions_test
	 *
	 * @param object $order_data The malformed order object.
	 */
	public function test_from_paypal_response_exceptions( $order_data ): void {
		$testee = new OrderFactory( $this->mock( PurchaseUnitFactory::class ), $this->mock( PayerFactory::class ) );

		$this->expectException( RuntimeException::class );
		$testee->from_paypal_response( $order_data );
	}

	/**
	 * The malformed responses. The extension also had a case each for no purchase units, purchase units that are not an
	 * array and no intent, but all three only threw because their status was an empty string, so they are one data set
	 * here. The factory does not check those fields: see test_from_paypal_response_is_lenient_about_purchase_units_and_intent().
	 *
	 * @return array<string, array<object>>
	 */
	public function data_for_test_from_paypal_response_exceptions_test(): array {
		return array(
			'no_id'        => array(
				(object) array(
					'purchase_units' => array(),
					'status'         => '',
					'intent'         => '',
				),
			),
			'empty_status' => array(
				(object) array(
					'id'             => 'id',
					'purchase_units' => array(),
					'status'         => '',
					'intent'         => 'CAPTURE',
				),
			),
		);
	}

	/**
	 * Today the factory does not validate the purchase units or the intent: a response without purchase units, or with
	 * a value that is not an array, has none, and a response without an intent is a CAPTURE order. The extension's
	 * tests never reached these branches because their malformed responses also had an empty status.
	 *
	 * @testdox Should read a response without purchase units or intent as an order with no purchase units and the CAPTURE intent.
	 */
	public function test_from_paypal_response_is_lenient_about_purchase_units_and_intent(): void {
		$testee = new OrderFactory( $this->mock( PurchaseUnitFactory::class ), $this->mock( PayerFactory::class ) );

		$without_units = $testee->from_paypal_response(
			(object) array(
				'id'     => 'id',
				'status' => OrderStatus::APPROVED,
			)
		);
		$not_an_array  = $testee->from_paypal_response(
			(object) array(
				'id'             => 'id',
				'purchase_units' => 1,
				'status'         => OrderStatus::APPROVED,
			)
		);

		$this->assertSame( array(), $without_units->purchase_units() );
		$this->assertSame( array(), $not_an_array->purchase_units() );
		$this->assertSame( 'CAPTURE', $without_units->intent() );
	}
}
