<?php
/**
 * Tests for the address factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AddressFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Customer;
use WC_Order;

/**
 * Builds a PayPal address from a customer, an order or a PayPal response.
 *
 * @group paypal-wallet
 */
class AddressFactoryTest extends WalletTestCase {

	/**
	 * A customer or an order mock that answers the six address getters of the given type.
	 *
	 * @param string $class_name The class to mock.
	 * @param string $type       The address type, shipping or billing.
	 * @return \Mockery\MockInterface
	 */
	private function make_address_holder( string $class_name, string $type ) {
		$holder = $this->mock( $class_name );
		foreach ( array( 'country', 'address_1', 'address_2', 'state', 'city', 'postcode' ) as $field ) {
			$holder->expects( "get_{$type}_{$field}" )->andReturn( "{$type}_{$field}" );
		}

		return $holder;
	}

	/**
	 * Assert that an address holds the values make_address_holder() hands out.
	 *
	 * @param object $address The address entity.
	 * @param string $type    The address type, shipping or billing.
	 */
	private function assert_address_of_type( $address, string $type ): void {
		$this->assertEquals( "{$type}_country", $address->country_code() );
		$this->assertEquals( "{$type}_address_1", $address->address_line_1() );
		$this->assertEquals( "{$type}_address_2", $address->address_line_2() );
		$this->assertEquals( "{$type}_state", $address->admin_area_1() );
		$this->assertEquals( "{$type}_city", $address->admin_area_2() );
		$this->assertEquals( "{$type}_postcode", $address->postal_code() );
	}

	/**
	 * @testdox Should build the address from the shipping address of a customer.
	 */
	public function test_from_wc_customer(): void {
		$testee = new AddressFactory();

		$result = $testee->from_wc_customer( $this->make_address_holder( WC_Customer::class, 'shipping' ) );

		$this->assert_address_of_type( $result, 'shipping' );
	}

	/**
	 * @testdox Should build the address from the billing address of a customer when asked to.
	 */
	public function test_from_wc_customers_billing_address(): void {
		$testee = new AddressFactory();

		$result = $testee->from_wc_customer( $this->make_address_holder( WC_Customer::class, 'billing' ), 'billing' );

		$this->assert_address_of_type( $result, 'billing' );
	}

	/**
	 * @testdox Should build the address from the shipping address of an order.
	 */
	public function test_from_wc_order(): void {
		$testee = new AddressFactory();

		$result = $testee->from_wc_order( $this->make_address_holder( WC_Order::class, 'shipping' ) );

		$this->assert_address_of_type( $result, 'shipping' );
	}

	/**
	 * @testdox Should build the address from the billing address of an order when asked to.
	 */
	public function test_from_wc_order_billing_address(): void {
		$testee = new AddressFactory();

		$result = $testee->from_wc_order( $this->make_address_holder( WC_Order::class, 'billing' ), 'billing' );

		$this->assert_address_of_type( $result, 'billing' );
	}

	/**
	 * @testdox Should build the address from a PayPal response, with an empty string for every missing part.
	 *
	 * @dataProvider data_from_paypal_request
	 *
	 * @param object $data The address object PayPal sent.
	 */
	public function test_from_paypal_request( $data ): void {
		$testee = new AddressFactory();

		$result = $testee->from_paypal_response( $data );

		$this->assertEquals( $data->country_code, $result->country_code() );
		$this->assertEquals( $data->address_line_1 ?? '', $result->address_line_1() );
		$this->assertEquals( $data->address_line_2 ?? '', $result->address_line_2() );
		$this->assertEquals( $data->admin_area_1 ?? '', $result->admin_area_1() );
		$this->assertEquals( $data->admin_area_2 ?? '', $result->admin_area_2() );
		$this->assertEquals( $data->postal_code ?? '', $result->postal_code() );
	}

	/**
	 * The full address and the address with each optional part left out in turn.
	 *
	 * @return array<string, array<object>>
	 */
	public function data_from_paypal_request(): array {
		$full = array(
			'country_code'   => 'shipping_country',
			'address_line_1' => 'shipping_address_1',
			'address_line_2' => 'shipping_address_2',
			'admin_area_1'   => 'shipping_admin_area_1',
			'admin_area_2'   => 'shipping_admin_area_2',
			'postal_code'    => 'shipping_postcode',
		);

		$cases = array( 'default' => array( (object) $full ) );
		foreach ( array( 'admin_area_2', 'postal_code', 'admin_area_1', 'address_line_1', 'address_line_2' ) as $missing ) {
			$partial = $full;
			unset( $partial[ $missing ] );
			$cases[ "no_{$missing}" ] = array( (object) $partial );
		}

		return $cases;
	}

	/**
	 * PayPal sometimes serializes an empty address object as a JSON array (`[]`), which json_decode() turns into an empty
	 * PHP array rather than an stdClass. This once fataled with a TypeError because the method accepted only stdClass.
	 *
	 * @testdox Should return an empty address when PayPal sends an empty array for it.
	 */
	public function test_from_paypal_response_with_empty_array_returns_empty_address(): void {
		$testee = new AddressFactory();

		$result = $testee->from_paypal_response( array() );

		$this->assertEquals( '', $result->country_code() );
		$this->assertEquals( '', $result->address_line_1() );
		$this->assertEquals( '', $result->address_line_2() );
		$this->assertEquals( '', $result->admin_area_1() );
		$this->assertEquals( '', $result->admin_area_2() );
		$this->assertEquals( '', $result->postal_code() );
	}

	/**
	 * @testdox Should read a non-empty array as if it were an object.
	 */
	public function test_from_paypal_response_with_non_empty_array_is_cast_to_object(): void {
		$testee = new AddressFactory();

		$result = $testee->from_paypal_response(
			array(
				'country_code'   => 'shipping_country',
				'address_line_1' => 'shipping_address_1',
			)
		);

		$this->assertEquals( 'shipping_country', $result->country_code() );
		$this->assertEquals( 'shipping_address_1', $result->address_line_1() );
		$this->assertEquals( '', $result->postal_code() );
	}
}
