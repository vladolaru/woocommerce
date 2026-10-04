<?php
/**
 * Tests for the amount breakdown entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AmountBreakdown;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The parts an order total is made of: items, shipping, tax, handling, insurance and the two discounts. A part that is
 * not given is left out of the array.
 *
 * @group paypal-wallet
 */
class AmountBreakdownTest extends WalletTestCase {

	/**
	 * The breakdown parts in constructor order, each a Money mock whose array is a one-entry list named after the part.
	 *
	 * @param int $times How many times to_array() must be called, or null for any number of times.
	 * @return array<string, Money&MockInterface>
	 */
	private function make_parts( ?int $times ): array {
		$parts = array();
		foreach ( array( 'item_total', 'shipping', 'tax_total', 'handling', 'insurance', 'shipping_discount', 'discount' ) as $key ) {
			$money       = $this->mock( Money::class );
			$expectation = $money->shouldReceive( 'to_array' )->andReturn( array( $key ) );
			if ( null === $times ) {
				$expectation->zeroOrMoreTimes();
			} else {
				$expectation->times( $times );
			}
			$parts[ $key ] = $money;
		}

		return $parts;
	}

	/**
	 * @testdox Should hold every part and write each one into the array under its key.
	 */
	public function test(): void {
		$parts  = $this->make_parts( 1 );
		$testee = new AmountBreakdown( ...array_values( $parts ) );

		$this->assertSame( $parts['item_total'], $testee->item_total() );
		$this->assertSame( $parts['shipping'], $testee->shipping() );
		$this->assertSame( $parts['tax_total'], $testee->tax_total() );
		$this->assertSame( $parts['handling'], $testee->handling() );
		$this->assertSame( $parts['insurance'], $testee->insurance() );
		$this->assertSame( $parts['shipping_discount'], $testee->shipping_discount() );
		$this->assertSame( $parts['discount'], $testee->discount() );

		$expected = array(
			'item_total'        => array( 'item_total' ),
			'shipping'          => array( 'shipping' ),
			'tax_total'         => array( 'tax_total' ),
			'handling'          => array( 'handling' ),
			'insurance'         => array( 'insurance' ),
			'shipping_discount' => array( 'shipping_discount' ),
			'discount'          => array( 'discount' ),
		);

		$this->assertSame( $expected, $testee->to_array() );
	}

	/**
	 * Every breakdown part, as a key and the method that returns it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_drop_array_key_if_no_value_given(): array {
		return array(
			'item_total'        => array( 'item_total', 'item_total' ),
			'shipping'          => array( 'shipping', 'shipping' ),
			'tax_total'         => array( 'tax_total', 'tax_total' ),
			'handling'          => array( 'handling', 'handling' ),
			'insurance'         => array( 'insurance', 'insurance' ),
			'shipping_discount' => array( 'shipping_discount', 'shipping_discount' ),
			'discount'          => array( 'discount', 'discount' ),
		);
	}

	/**
	 * @testdox Should leave a part out of the array and return null for it when it is not given.
	 * @dataProvider data_drop_array_key_if_no_value_given
	 *
	 * @param string $key_missing The part that is not given.
	 * @param string $method_name The method that returns that part.
	 */
	public function test_drop_array_key_if_no_value_given( string $key_missing, string $method_name ): void {
		$items                 = $this->make_parts( null );
		$items[ $key_missing ] = null;

		$testee = new AmountBreakdown( ...array_values( $items ) );

		$this->assertArrayNotHasKey( $key_missing, $testee->to_array() );
		$this->assertNull( $testee->{$method_name}(), "$method_name should return null" );
	}
}
