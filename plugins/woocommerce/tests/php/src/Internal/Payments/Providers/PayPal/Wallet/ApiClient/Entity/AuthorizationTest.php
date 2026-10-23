<?php
/**
 * Tests for the authorization entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * A payment authorization: its ID and its status.
 *
 * @group paypal-wallet
 */
class AuthorizationTest extends WalletTestCase {

	/**
	 * @testdox Should hold the ID and the status it is given.
	 */
	public function test_id_and_status(): void {
		$authorization_status = $this->mock( AuthorizationStatus::class );
		$testee               = new Authorization( 'foo', $authorization_status, null );

		$this->assertSame( 'foo', $testee->id() );
		$this->assertSame( $authorization_status, $testee->status() );
	}

	/**
	 * @testdox Should write its ID and the name of its status into the array.
	 */
	public function test_to_array(): void {
		$authorization_status = $this->mock( AuthorizationStatus::class );
		$authorization_status->shouldReceive( 'name' )->once()->andReturn( 'CAPTURED' );

		$testee = new Authorization( 'foo', $authorization_status, null );

		$this->assertSame(
			array(
				'id'     => 'foo',
				'status' => 'CAPTURED',
			),
			$testee->to_array()
		);
	}
}
