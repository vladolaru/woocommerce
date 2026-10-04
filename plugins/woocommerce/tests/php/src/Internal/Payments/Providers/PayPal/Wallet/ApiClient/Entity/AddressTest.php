<?php
/**
 * Tests for the address entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The postal address of a payer or a shipping destination, as PayPal names its parts.
 *
 * @group paypal-wallet
 */
class AddressTest extends WalletTestCase {

	/**
	 * An address with a distinct value in every part.
	 *
	 * @return Address
	 */
	private function make_address(): Address {
		return new Address( 'countryCode', 'addressLine1', 'addressLine2', 'adminArea1', 'adminArea2', 'postalCode' );
	}

	/**
	 * @testdox Should hold every part of the address it is given.
	 */
	public function test(): void {
		$testee = $this->make_address();

		$this->assertSame( 'countryCode', $testee->country_code() );
		$this->assertSame( 'addressLine1', $testee->address_line_1() );
		$this->assertSame( 'addressLine2', $testee->address_line_2() );
		$this->assertSame( 'adminArea1', $testee->admin_area_1() );
		$this->assertSame( 'adminArea2', $testee->admin_area_2() );
		$this->assertSame( 'postalCode', $testee->postal_code() );
	}

	/**
	 * @testdox Should write the address in the shape PayPal expects.
	 */
	public function test_to_array(): void {
		$expected = array(
			'country_code'   => 'countryCode',
			'address_line_1' => 'addressLine1',
			'address_line_2' => 'addressLine2',
			'admin_area_1'   => 'adminArea1',
			'admin_area_2'   => 'adminArea2',
			'postal_code'    => 'postalCode',
		);

		$this->assertSame( $expected, $this->make_address()->to_array() );
	}
}
