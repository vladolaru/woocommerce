<?php
/**
 * Tests for the money entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * A value in a currency.
 *
 * @group paypal-wallet
 */
class MoneyTest extends WalletTestCase {

	/**
	 * @testdox Should hold its value and currency and write them into the array.
	 */
	public function test(): void {
		$testee = new Money( 1.10, 'currencyCode' );

		$this->assertSame( 1.10, $testee->value() );
		$this->assertSame( 'currencyCode', $testee->currency_code() );
		$this->assertSame(
			array(
				'currency_code' => 'currencyCode',
				'value'         => '1.10',
			),
			$testee->to_array()
		);
	}
}
