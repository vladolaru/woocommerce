<?php
/**
 * Tests for the payments entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Refund;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The authorizations, captures and refunds PayPal reports on a purchase unit.
 *
 * @group paypal-wallet
 */
class PaymentsTest extends WalletTestCase {

	/**
	 * @testdox Should hold the authorizations it is given.
	 */
	public function test_authorizations(): void {
		$authorizations = array( $this->mock( Authorization::class ) );

		$testee = new Payments( $authorizations, array() );

		$this->assertSame( $authorizations, $testee->authorizations() );
	}

	/**
	 * @testdox Should hold the captures it is given.
	 */
	public function test_captures(): void {
		$captures = array( $this->mock( Capture::class ) );

		$testee = new Payments( array(), $captures );

		$this->assertSame( $captures, $testee->captures() );
	}

	/**
	 * @testdox Should write the authorizations, captures and refunds into the array.
	 */
	public function test_to_array(): void {
		$authorization = $this->mock( Authorization::class );
		$authorization->shouldReceive( 'to_array' )->andReturn(
			array(
				'id'     => 'foo',
				'status' => 'CREATED',
			)
		);
		$capture = $this->mock( Capture::class );
		$capture->shouldReceive( 'to_array' )->andReturn(
			array(
				'id'     => 'capture',
				'status' => 'CREATED',
			)
		);
		$refund = $this->mock( Refund::class );
		$refund->shouldReceive( 'to_array' )->andReturn(
			array(
				'id'     => 'refund',
				'status' => 'CREATED',
			)
		);

		$testee = new Payments( array( $authorization ), array( $capture ), array( $refund ) );

		$this->assertSame(
			array(
				'authorizations' => array(
					array(
						'id'     => 'foo',
						'status' => 'CREATED',
					),
				),
				'captures'       => array(
					array(
						'id'     => 'capture',
						'status' => 'CREATED',
					),
				),
				'refunds'        => array(
					array(
						'id'     => 'refund',
						'status' => 'CREATED',
					),
				),
			),
			$testee->to_array()
		);
	}
}
