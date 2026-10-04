<?php
/**
 * Tests for the payer factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AddressFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use stdClass;
use WC_Customer;
use WC_Order;

/**
 * Builds the payer from a customer, an order, a PayPal response or the checkout form. This is wallet code: the buyer's
 * PayPal account is the payer.
 *
 * @group paypal-wallet
 */
class PayerFactoryTest extends WalletTestCase {

	/**
	 * A customer or an order mock with the billing details of John Locke and the given phone number.
	 *
	 * @param string $class_name The class to mock, WC_Customer or WC_Order.
	 * @param string $phone      The billing phone number.
	 * @return MockInterface
	 */
	private function make_billing_holder( string $class_name, string $phone ): MockInterface {
		$holder = $this->mock( $class_name );
		$holder->shouldReceive( 'get_billing_phone' )->andReturn( $phone );
		$holder->shouldReceive( 'get_billing_email' )->andReturn( 'test@example.com' );
		$holder->shouldReceive( 'get_billing_last_name' )->andReturn( 'Locke' );
		$holder->shouldReceive( 'get_billing_first_name' )->andReturn( 'John' );

		return $holder;
	}

	/**
	 * A payer factory whose address factory returns the given address for the billing address of the holder.
	 *
	 * @param string        $method  The address factory method expected, from_wc_customer or from_wc_order.
	 * @param MockInterface $holder The customer or order.
	 * @param Address       $address The address handed back.
	 * @return PayerFactory
	 */
	private function make_testee( string $method, MockInterface $holder, Address $address ): PayerFactory {
		$address_factory = $this->mock( AddressFactory::class );
		$address_factory->expects( $method )->with( $holder, 'billing' )->andReturn( $address );

		return new PayerFactory( $address_factory );
	}

	/**
	 * @testdox Should build the payer from the billing details of a customer.
	 */
	public function test_from_wc_customer(): void {
		$address  = $this->mock( Address::class );
		$customer = $this->make_billing_holder( WC_Customer::class, '012345678901' );

		$result = $this->make_testee( 'from_wc_customer', $customer, $address )->from_customer( $customer );

		$this->assertEquals( 'test@example.com', $result->email_address() );
		$this->assertEquals( 'Locke', $result->name()->surname() );
		$this->assertEquals( 'John', $result->name()->given_name() );
		$this->assertEquals( $address, $result->address() );
		$this->assertEquals( '012345678901', $result->phone()->phone()->national_number() );
		$this->assertNull( $result->birthdate() );
		$this->assertEmpty( $result->payer_id() );
	}

	/**
	 * The phone number is only allowed to contain numbers. The billing phone of a customer can contain other
	 * characters, which need to get stripped.
	 *
	 * @testdox Should strip everything but digits from the phone number of a customer.
	 */
	public function test_from_wc_customer_strings_from_number_are_removed(): void {
		$customer = $this->make_billing_holder( WC_Customer::class, '012345678901abcdefg' );

		$result = $this->make_testee( 'from_wc_customer', $customer, $this->mock( Address::class ) )->from_customer( $customer );

		$this->assertEquals( '012345678901', $result->phone()->phone()->national_number() );
	}

	/**
	 * @testdox Should leave the phone out when the customer has no phone number.
	 */
	public function test_from_wc_customer_no_number(): void {
		$customer = $this->make_billing_holder( WC_Customer::class, '' );

		$result = $this->make_testee( 'from_wc_customer', $customer, $this->mock( Address::class ) )->from_customer( $customer );

		$this->assertNull( $result->phone() );
	}

	/**
	 * The phone number is not allowed to be longer than 14 characters, so a longer one gets cut.
	 *
	 * @testdox Should cut a phone number that is longer than 14 digits.
	 */
	public function test_from_wc_customer_too_long_number_gets_stripped(): void {
		$customer = $this->make_billing_holder( WC_Customer::class, '01234567890123456789' );

		$result = $this->make_testee( 'from_wc_customer', $customer, $this->mock( Address::class ) )->from_customer( $customer );

		$this->assertEquals( '01234567890123', $result->phone()->phone()->national_number() );
	}

	/**
	 * @testdox Should build the payer from the billing details of an order.
	 */
	public function test_from_wc_order_uses_billing_address(): void {
		$address = $this->mock( Address::class );
		$order   = $this->make_billing_holder( WC_Order::class, '012345678901' );

		$result = $this->make_testee( 'from_wc_order', $order, $address )->from_wc_order( $order );

		$this->assertEquals( 'test@example.com', $result->email_address() );
		$this->assertEquals( 'Locke', $result->name()->surname() );
		$this->assertEquals( 'John', $result->name()->given_name() );
		$this->assertEquals( $address, $result->address() );
		$this->assertEquals( '012345678901', $result->phone()->phone()->national_number() );
		$this->assertNull( $result->birthdate() );
		$this->assertEmpty( $result->payer_id() );
	}

	/**
	 * PayPal sometimes serializes an empty payer address as a JSON array (`[]`) instead of an object (`{}`). That once
	 * fataled with "AddressFactory::from_paypal_response(): Argument #1 ($data) must be of type stdClass, array given"
	 * because PayerFactory passed the decoded array straight through without normalizing it.
	 *
	 * @testdox Should not fatal when PayPal sends the payer address as an empty array.
	 */
	public function test_from_pay_pal_response_with_array_address_does_not_fatal(): void {
		$data = (object) array(
			'address'       => array(),
			'name'          => (object) array(
				'given_name' => 'given_name',
				'surname'    => 'surname',
			),
			'email_address' => 'email_address',
			'payer_id'      => 'payer_id',
		);

		$testee = new PayerFactory( new AddressFactory() );
		$payer  = $testee->from_paypal_response( $data );

		$this->assertInstanceOf( Address::class, $payer->address() );
		$this->assertEquals( '', $payer->address()->country_code() );
	}

	/**
	 * @testdox Should build the payer from a PayPal response, leaving out what the response does not have.
	 *
	 * @dataProvider data_for_test_from_pay_pal_response
	 *
	 * @param object $data The payer object PayPal sent.
	 */
	public function test_from_pay_pal_response( $data ): void {
		$address_factory = $this->mock( AddressFactory::class );
		$address_factory
			->expects( 'from_paypal_response' )
			->with( $data->address )
			->andReturn( $this->mock( Address::class ) );
		$testee = new PayerFactory( $address_factory );
		$payer  = $testee->from_paypal_response( $data );

		$this->assertEquals( $data->email_address, $payer->email_address() );
		$this->assertEquals( $data->payer_id, $payer->payer_id() );
		$this->assertEquals( $data->name->given_name, $payer->name()->given_name() );
		$this->assertEquals( $data->name->surname, $payer->name()->surname() );
		if ( isset( $data->phone->phone_number->national_number ) ) {
			$this->assertEquals( $data->phone->phone_type, $payer->phone()->type() );
			$this->assertEquals( $data->phone->phone_number->national_number, $payer->phone()->phone()->national_number() );
		} else {
			$this->assertNull( $payer->phone() );
		}
		$this->assertInstanceOf( Address::class, $payer->address() );
		if ( isset( $data->tax_info ) ) {
			$this->assertEquals( $data->tax_info->tax_id, $payer->tax_info()->tax_id() );
			$this->assertEquals( $data->tax_info->tax_id_type, $payer->tax_info()->type() );
		} else {
			$this->assertNull( $payer->tax_info() );
		}
		if ( isset( $data->birth_date ) ) {
			$this->assertEquals( $data->birth_date, $payer->birthdate()->format( 'Y-m-d' ) );
		} else {
			$this->assertNull( $payer->birthdate() );
		}
	}

	/**
	 * A full payer and the payer with the phone, the national number, the tax info, the address or the birth date in
	 * turn left out or emptied.
	 *
	 * @return array<string, array<object>>
	 */
	public function data_for_test_from_pay_pal_response(): array {
		$full = array(
			'address'       => new stdClass(),
			'name'          => (object) array(
				'given_name' => 'given_name',
				'surname'    => 'surname',
			),
			'phone'         => (object) array(
				'phone_type'   => 'HOME',
				'phone_number' => (object) array( 'national_number' => '1234567890' ),
			),
			'tax_info'      => (object) array(
				'tax_id'      => 'tax_id',
				'tax_id_type' => 'BR_CPF',
			),
			'birth_date'    => '1970-01-01',
			'email_address' => 'email_address',
			'payer_id'      => 'payer_id',
		);

		$without = static function ( array ...$keys ) use ( $full ): array {
			$data = $full;
			foreach ( $keys as $key_list ) {
				foreach ( $key_list as $key ) {
					unset( $data[ $key ] );
				}
			}
			return $data;
		};

		$null_national_number          = $full;
		$null_national_number['phone'] = (object) array(
			'phone_type'   => 'HOME',
			'phone_number' => (object) array( 'national_number' => null ),
		);

		$empty_array_address            = $without( array( 'phone', 'tax_info', 'birth_date' ) );
		$empty_array_address['address'] = array();

		return array(
			'default'                         => array( (object) $full ),
			'no_phone'                        => array( (object) $without( array( 'phone' ) ) ),
			'phone_with_null_national_number' => array( (object) $null_national_number ),
			'no_tax_info'                     => array( (object) $without( array( 'tax_info' ) ) ),
			'empty_array_address'             => array( (object) $empty_array_address ),
			'no_birth_date'                   => array( (object) $without( array( 'birth_date' ) ) ),
		);
	}
}
