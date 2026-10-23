<?php
/**
 * Tests for the payments factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Refund;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AuthorizationFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\CaptureFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PaymentsFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\RefundFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Builds the payments of an order (authorizations, captures, refunds) from a PayPal response.
 *
 * @group paypal-wallet
 */
class PaymentsFactoryTest extends WalletTestCase {

	/**
	 * @testdox Should build the authorizations, captures and refunds from a PayPal response.
	 */
	public function test_from_paypal_response(): void {
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

		$authorizations_factory = $this->mock( AuthorizationFactory::class );
		$authorizations_factory->shouldReceive( 'from_paypal_response' )->andReturn( $authorization );
		$capture_factory = $this->mock( CaptureFactory::class );
		$capture_factory->shouldReceive( 'from_paypal_response' )->andReturn( $capture );
		$refund_factory = $this->mock( RefundFactory::class );
		$refund_factory->shouldReceive( 'from_paypal_response' )->andReturn( $refund );

		$response = (object) array(
			'authorizations' => array(
				(object) array(
					'id'     => 'foo',
					'status' => 'CREATED',
				),
			),
			'captures'       => array(
				(object) array(
					'id'     => 'capture',
					'status' => 'CREATED',
				),
			),
			'refunds'        => array(
				(object) array(
					'id'     => 'refund',
					'status' => 'CREATED',
				),
			),
		);

		$testee = new PaymentsFactory( $authorizations_factory, $capture_factory, $refund_factory );
		$result = $testee->from_paypal_response( $response );

		$this->assertInstanceOf( Payments::class, $result );

		$expected_to_array = array(
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
		);
		$this->assertEquals( $expected_to_array, $result->to_array() );
	}
}
