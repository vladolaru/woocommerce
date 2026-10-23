<?php
/**
 * Tests for the payee repository.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\PayeeRepository;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The payee of an order: the merchant who gets paid.
 *
 * @group paypal-wallet
 */
class PayeeRepositoryTest extends WalletTestCase {

	/**
	 * @testdox Should build the payee from the merchant email and ID it is given.
	 */
	public function test_default(): void {
		$testee = new PayeeRepository( 'merchant_email', 'merchant_id' );

		$payee = $testee->payee();

		$this->assertSame( 'merchant_id', $payee->merchant_id() );
		$this->assertSame( 'merchant_email', $payee->email() );
	}
}
