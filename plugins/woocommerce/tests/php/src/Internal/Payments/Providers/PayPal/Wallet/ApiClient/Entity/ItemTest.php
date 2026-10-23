<?php
/**
 * Tests for the item entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * A line item of an order.
 *
 * @group paypal-wallet
 */
class ItemTest extends WalletTestCase {

	/**
	 * @testdox Should hold every value it is given.
	 */
	public function test(): void {
		$unit_amount = $this->mock( Money::class );
		$tax         = $this->mock( Money::class );
		$testee      = new Item( 'name', $unit_amount, 1, 'description', $tax, 'sku', 'PHYSICAL_GOODS' );

		$this->assertSame( 'name', $testee->name() );
		$this->assertSame( $unit_amount, $testee->unit_amount() );
		$this->assertSame( 1, $testee->quantity() );
		$this->assertSame( 'description', $testee->description() );
		$this->assertSame( $tax, $testee->tax() );
		$this->assertSame( 'sku', $testee->sku() );
		$this->assertSame( 'PHYSICAL_GOODS', $testee->category() );
	}

	/**
	 * @testdox Should hold the digital goods category.
	 */
	public function test_digital_goods_category(): void {
		$testee = new Item( 'name', $this->mock( Money::class ), 1, 'description', $this->mock( Money::class ), 'sku', 'DIGITAL_GOODS' );

		$this->assertSame( 'DIGITAL_GOODS', $testee->category() );
	}

	/**
	 * @testdox Should write every value, its prices and its URLs included, into the array.
	 */
	public function test_to_array(): void {
		$unit_amount = $this->mock( Money::class );
		$unit_amount->shouldReceive( 'to_array' )->once()->andReturn( array( 1 ) );
		$tax = $this->mock( Money::class );
		$tax->shouldReceive( 'to_array' )->once()->andReturn( array( 2 ) );

		$image_url = 'https://example.com/wp-content/uploads/2023/06/beanie-2.jpg';

		$testee = new Item( 'name', $unit_amount, 1, 'description', $tax, 'sku', 'PHYSICAL_GOODS', 'url', $image_url );

		$expected = array(
			'name'        => 'name',
			'unit_amount' => array( 1 ),
			'quantity'    => 1,
			'description' => 'description',
			'sku'         => 'sku',
			'category'    => 'PHYSICAL_GOODS',
			'url'         => 'url',
			'image_url'   => $image_url,
			'tax'         => array( 2 ),
		);

		$this->assertSame( $expected, $testee->to_array() );
	}
}
