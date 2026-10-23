<?php
/**
 * Tests for the seller receivable breakdown factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExchangeRateFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\MoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayeeFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PlatformFeeFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerReceivableBreakdownFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Builds the breakdown of what the seller receives from a capture response.
 *
 * @group paypal-wallet
 */
class SellerReceivableBreakdownFactoryTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SellerReceivableBreakdownFactory
	 */
	private $sut;

	/**
	 * Set up the factory with the real money, exchange rate, platform fee and payee factories.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new SellerReceivableBreakdownFactory(
			new MoneyFactory(),
			new ExchangeRateFactory(),
			new PlatformFeeFactory( new MoneyFactory(), new PayeeFactory() )
		);
	}

	/**
	 * @testdox Should write back every amount the PayPal response holds, and only those.
	 *
	 * @dataProvider data_for_test_from_paypal_response
	 *
	 * @param string $json            The breakdown as PayPal sends it.
	 * @param array  $expected_result The expected array.
	 */
	public function test_from_paypal_response( string $json, array $expected_result ): void {
		$result = $this->sut->from_paypal_response( json_decode( $json ) );

		$this->assertEquals( $expected_result, $result->to_array() );
	}

	/**
	 * A fee, a minimal breakdown, a currency exchange and platform fees.
	 *
	 * @return array<string, array>
	 */
	public function data_for_test_from_paypal_response(): array {
		return array(
			'fee'           => array(
				'{
					"gross_amount": { "currency_code": "USD", "value": "10.42" },
					"paypal_fee": { "currency_code": "USD", "value": "0.41" },
					"net_amount": { "currency_code": "USD", "value": "10.01" }
				}',
				array(
					'gross_amount' => array(
						'currency_code' => 'USD',
						'value'         => '10.42',
					),
					'paypal_fee'   => array(
						'currency_code' => 'USD',
						'value'         => '0.41',
					),
					'net_amount'   => array(
						'currency_code' => 'USD',
						'value'         => '10.01',
					),
				),
			),
			'min'           => array(
				'{
					"gross_amount": { "currency_code": "USD", "value": "10.42" }
				}',
				array(
					'gross_amount' => array(
						'currency_code' => 'USD',
						'value'         => '10.42',
					),
				),
			),
			'exchange'      => array(
				'{
					"gross_amount": { "value": "10.99", "currency_code": "USD" },
					"paypal_fee": { "value": "0.33", "currency_code": "USD" },
					"net_amount": { "value": "10.66", "currency_code": "USD" },
					"receivable_amount": { "currency_code": "CNY", "value": "59.26" },
					"paypal_fee_in_receivable_currency": { "currency_code": "CNY", "value": "1.13" },
					"exchange_rate": { "source_currency": "USD", "target_currency": "CNY", "value": "5.9483297432325" }
				}',
				array(
					'gross_amount'                      => array(
						'currency_code' => 'USD',
						'value'         => '10.99',
					),
					'paypal_fee'                        => array(
						'currency_code' => 'USD',
						'value'         => '0.33',
					),
					'net_amount'                        => array(
						'currency_code' => 'USD',
						'value'         => '10.66',
					),
					'receivable_amount'                 => array(
						'currency_code' => 'CNY',
						'value'         => '59.26',
					),
					'paypal_fee_in_receivable_currency' => array(
						'currency_code' => 'CNY',
						'value'         => '1.13',
					),
					'exchange_rate'                     => array(
						'source_currency' => 'USD',
						'target_currency' => 'CNY',
						'value'           => '5.9483297432325',
					),
				),
			),
			'platform_fees' => array(
				'{
					"gross_amount": { "currency_code": "USD", "value": "10.42" },
					"platform_fees": [
						{ "amount": { "currency_code": "USD", "value": "0.06" } },
						{
							"amount": { "currency_code": "USD", "value": "0.08" },
							"payee": { "email_address": "example@gmail.com" }
						}
					]
				}',
				array(
					'gross_amount'  => array(
						'currency_code' => 'USD',
						'value'         => '10.42',
					),
					'platform_fees' => array(
						array(
							'amount' => array(
								'currency_code' => 'USD',
								'value'         => '0.06',
							),
						),
						array(
							'amount' => array(
								'currency_code' => 'USD',
								'value'         => '0.08',
							),
							'payee'  => array(
								'email_address' => 'example@gmail.com',
							),
						),
					),
				),
			),
		);
	}
}
