<?php
/**
 * Tests for the amount entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AmountBreakdown;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * An amount: a currency and a value, with an optional breakdown.
 *
 * @group paypal-wallet
 */
class AmountTest extends WalletTestCase {

	/**
	 * @testdox Should take its currency and value from the money it wraps.
	 */
	public function test(): void {
		$money = $this->mock( Money::class );
		$money->shouldReceive( 'currency_code' )->andReturn( 'currencyCode' );
		$money->shouldReceive( 'value' )->andReturn( 1.10 );
		$testee = new Amount( $money );

		$this->assertSame( 'currencyCode', $testee->currency_code() );
		$this->assertSame( 1.10, $testee->value() );
	}

	/**
	 * @testdox Should write no breakdown when it has none.
	 */
	public function test_breakdown_is_null(): void {
		$testee = new Amount( new Money( 1.10, 'currencyCode' ) );

		$this->assertNull( $testee->breakdown() );
		$this->assertSame(
			array(
				'currency_code' => 'currencyCode',
				'value'         => '1.10',
			),
			$testee->to_array()
		);
	}

	/**
	 * @testdox Should write the breakdown it holds into the array.
	 */
	public function test_breakdown(): void {
		$breakdown = $this->mock( AmountBreakdown::class );
		$breakdown->shouldReceive( 'to_array' )->andReturn( array( 1 ) );
		$testee = new Amount( new Money( 1.10, 'currencyCode' ), $breakdown );

		$this->assertSame( $breakdown, $testee->breakdown() );
		$this->assertSame(
			array(
				'currency_code' => 'currencyCode',
				'value'         => '1.10',
				'breakdown'     => array( 1 ),
			),
			$testee->to_array()
		);
	}
}
