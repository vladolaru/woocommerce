<?php
/**
 * Tests for the fraud processor response factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\FraudProcessorResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\FraudProcessorResponseFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Builds the fraud processor response from a PayPal response.
 *
 * @group paypal-wallet
 */
class FraudProcessorResponseFactoryTest extends WalletTestCase {

	/**
	 * @testdox Should read the AVS, CVV and response codes from a PayPal response.
	 */
	public function test_from_paypal_response_parses_all_fields(): void {
		$data = (object) array(
			'avs_code'      => 'Y',
			'cvv_code'      => 'M',
			'response_code' => '9500',
		);

		$testee = new FraudProcessorResponseFactory();
		$result = $testee->from_paypal_response( $data );

		$this->assertInstanceOf( FraudProcessorResponse::class, $result );
		$this->assertSame( 'Y', $result->avs_code() );
		$this->assertSame( 'M', $result->cvv_code() );
		$this->assertSame( '9500', $result->response_code() );
	}

	/**
	 * @testdox Should hold an empty response code when the response has none.
	 */
	public function test_from_paypal_response_with_missing_response_code(): void {
		$data = (object) array(
			'avs_code' => 'Y',
			'cvv_code' => 'M',
		);

		$testee = new FraudProcessorResponseFactory();
		$result = $testee->from_paypal_response( $data );

		$this->assertSame( '', $result->response_code() );
	}

	/**
	 * @testdox Should hold an empty response code when the response code is an empty string.
	 */
	public function test_from_paypal_response_with_empty_response_code(): void {
		$data = (object) array(
			'avs_code'      => 'Y',
			'cvv_code'      => 'M',
			'response_code' => '',
		);

		$testee = new FraudProcessorResponseFactory();
		$result = $testee->from_paypal_response( $data );

		// The factory turns the empty string into null, which the entity stores as an empty string.
		$this->assertSame( '', $result->response_code() );
	}
}
