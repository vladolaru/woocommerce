<?php
/**
 * Tests for the authorization factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AuthorizationFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\FraudProcessorResponseFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Builds an authorization from a PayPal response.
 *
 * @group paypal-wallet
 */
class AuthorizationFactoryTest extends WalletTestCase {

	/**
	 * @testdox Should build the authorization from a PayPal response.
	 */
	public function test_from_paypal_request_default(): void {
		$response = (object) array(
			'id'     => 'foo',
			'status' => 'CAPTURED',
		);

		$testee = new AuthorizationFactory( $this->mock( FraudProcessorResponseFactory::class ) );
		$result = $testee->from_paypal_response( $response );

		$this->assertInstanceOf( Authorization::class, $result );
		$this->assertEquals( 'foo', $result->id() );
		$this->assertInstanceOf( AuthorizationStatus::class, $result->status() );
		$this->assertEquals( 'CAPTURED', $result->status()->name() );
	}

	/**
	 * @testdox Should throw when the response has no ID.
	 */
	public function test_return_exception_id_is_missing(): void {
		$this->expectException( RuntimeException::class );

		$testee = new AuthorizationFactory( $this->mock( FraudProcessorResponseFactory::class ) );
		$testee->from_paypal_response( (object) array( 'status' => 'CAPTURED' ) );
	}

	/**
	 * @testdox Should throw when the response has no status.
	 */
	public function test_return_exception_status_is_missing(): void {
		$this->expectException( RuntimeException::class );

		$testee = new AuthorizationFactory( $this->mock( FraudProcessorResponseFactory::class ) );
		$testee->from_paypal_response( (object) array( 'id' => 'foo' ) );
	}
}
