<?php
/**
 * Tests for the check of the merchant countries the v6 SDK is withheld from.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MerchantCountrySupport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * No country is withheld by default, and a filter can withdraw one.
 *
 * @group paypal-wallet
 */
class MerchantCountrySupportTest extends WalletTestCase {

	private const FILTER = 'woocommerce_paypal_payments_sdk_v6_unsupported_countries';

	/**
	 * @testdox Should support a merchant in $merchant_country when no third party filters the unsupported countries.
	 * @dataProvider merchant_country_provider
	 *
	 * @param string $merchant_country The merchant's country.
	 */
	public function test_no_country_is_withheld_by_default( string $merchant_country ): void {
		$testee = new MerchantCountrySupport( $merchant_country );

		$this->assertTrue( $testee->is_supported() );
	}

	/**
	 * Countries that are supported by default.
	 *
	 * @return array
	 */
	public function merchant_country_provider(): array {
		return array(
			'Mexico, the country this list used to withhold' => array( 'MX' ),
			'a country never withheld by this list' => array( 'US' ),
		);
	}

	/**
	 * @testdox Should withhold only the filtered-in country when a filter widens the list to Brazil: merchant in $merchant_country supported is $expected_supported.
	 * @dataProvider filtered_country_provider
	 *
	 * @param string $merchant_country  The merchant's country.
	 * @param bool   $expected_supported Whether the merchant is supported.
	 */
	public function test_filter_can_widen_list_to_withhold_another_country( string $merchant_country, bool $expected_supported ): void {
		add_filter(
			self::FILTER,
			static function () {
				return array( 'BR' );
			}
		);

		$testee = new MerchantCountrySupport( $merchant_country );

		$this->assertSame( $expected_supported, $testee->is_supported() );
	}

	/**
	 * Merchant countries with the answer when Brazil is withheld.
	 *
	 * @return array
	 */
	public function filtered_country_provider(): array {
		return array(
			'the filtered-in country is excluded'   => array( 'BR', false ),
			'an unfiltered country stays supported' => array( 'US', true ),
		);
	}

	/**
	 * @testdox Should hand the filter an empty list as its default and honour it when a callback returns it unchanged.
	 */
	public function test_filter_receives_and_honours_the_default_value(): void {
		$calls = $this->spy_filter( self::FILTER );

		$testee = new MerchantCountrySupport( 'MX' );

		$this->assertTrue( $testee->is_supported() );
		$this->assertCount( 1, $calls, 'The filter runs once' );
		$this->assertSame( array(), $calls[0][0], 'The filter receives an empty list as its default' );
	}
}
