<?php
/**
 * Tests for the checkout address preset from the PayPal order.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerName;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Phone;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PhoneWithType;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout\CheckoutPayPalAddressPreset;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;

/**
 * The checkout form of a shopper who returns from PayPal is filled with the address and the name PayPal has on file,
 * unless the shopper typed their own value. The PayPal order and the saved checkout form are Mockery doubles of the
 * session handler; the values in them are plain data.
 *
 * @group paypal-wallet
 */
class CheckoutPayPalAddressPresetTest extends WalletTestCase {

	/**
	 * The session handler double.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The preset under test.
	 *
	 * @var CheckoutPayPalAddressPreset
	 */
	private CheckoutPayPalAddressPreset $testee;

	/**
	 * Build the preset over a session handler whose saved checkout form is empty until a test says otherwise.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_handler = $this->mock( SessionHandler::class );
		$this->session_handler->shouldReceive( 'checkout_form' )->byDefault()->andReturn( array() );
		$this->testee = new CheckoutPayPalAddressPreset( $this->session_handler );
	}

	/**
	 * @testdox Should fill the checkout field from the shipping address and the payer of the PayPal order.
	 *
	 * @dataProvider data_filter_checkout_field
	 *
	 * @param string      $field_id The field.
	 * @param string|null $expected The value the preset supplies.
	 */
	public function test_filter_checkout_field( string $field_id, ?string $expected ): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_with_address() );

		$this->assertSame( $expected, $this->testee->filter_checkout_field( null, $field_id ) );
	}

	/**
	 * The fields of an order whose purchase unit has a shipping address.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_filter_checkout_field(): array {
		return array(
			'Test billing_address_1'  => array( 'billing_address_1', 'Unter den Linden 1' ),
			'Test billing_address_2'  => array( 'billing_address_2', '2. Stock Hinterhaus' ),
			'Test billing_postcode'   => array( 'billing_postcode', '10117' ),
			'Test billing_country'    => array( 'billing_country', 'DE' ),
			'Test billing_city'       => array( 'billing_city', 'Berlin' ),
			'Test billing_state'      => array( 'billing_state', 'BE' ),
			'Test billing_last_name'  => array( 'billing_last_name', 'Doe' ),
			'Test billing_first_name' => array( 'billing_first_name', 'John' ),
			'Test billing_email'      => array( 'billing_email', 'mail@domain.tld' ),
			'Test billing_phone'      => array( 'billing_phone', '+4912345678' ),
		);
	}

	/**
	 * @testdox Should fill only the payer fields, and leave the address fields empty, when the order has no shipping address.
	 *
	 * @dataProvider data_filter_checkout_field_no_address
	 *
	 * @param string      $field_id The field.
	 * @param string|null $expected The value the preset supplies.
	 */
	public function test_filter_checkout_field_no_address( string $field_id, ?string $expected ): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_without_address() );

		$this->assertSame( $expected, $this->testee->filter_checkout_field( null, $field_id ) );
	}

	/**
	 * The fields of an order whose shipping has no address.
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public function data_filter_checkout_field_no_address(): array {
		return array(
			'Test billing_address_1'  => array( 'billing_address_1', null ),
			'Test billing_address_2'  => array( 'billing_address_2', null ),
			'Test billing_postcode'   => array( 'billing_postcode', null ),
			'Test billing_country'    => array( 'billing_country', null ),
			'Test billing_city'       => array( 'billing_city', null ),
			'Test billing_state'      => array( 'billing_state', null ),
			'Test billing_last_name'  => array( 'billing_last_name', 'Doe' ),
			'Test billing_first_name' => array( 'billing_first_name', 'John' ),
			'Test billing_email'      => array( 'billing_email', 'mail@domain.tld' ),
			'Test billing_phone'      => array( 'billing_phone', '+4912345678' ),
		);
	}

	/**
	 * GIVEN a shopper who typed their own first name into the checkout form
	 * WHEN the checkout page renders again after returning from PayPal
	 * THEN the shopper's own entry is returned instead of the name on their PayPal account.
	 *
	 * @testdox Should prefer the name the shopper entered over the PayPal name after the return from PayPal.
	 */
	public function test_filter_checkout_field_prefers_shopper_entered_name_over_pay_pal_name_after_return_from_pay_pal(): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_with_payer_name( 'John', 'Doe' ) );
		$this->session_handler->shouldReceive( 'checkout_form' )->andReturn( array( 'billing_first_name' => 'Narek' ) );

		$this->assertSame( 'Narek', $this->testee->filter_checkout_field( null, 'billing_first_name' ) );
	}

	/**
	 * GIVEN the shopper's saved checkout form has an empty value for a field
	 * WHEN the field is resolved
	 * THEN the PayPal preset is used, because a blank string is not an answer.
	 *
	 * @testdox Should fall back to the PayPal preset when the saved value is blank.
	 */
	public function test_filter_checkout_field_falls_back_to_preset_when_saved_value_is_blank(): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_with_payer_name( 'John', 'Doe' ) );
		$this->session_handler->shouldReceive( 'checkout_form' )->andReturn( array( 'billing_first_name' => '' ) );

		$this->assertSame( 'John', $this->testee->filter_checkout_field( null, 'billing_first_name' ) );
	}

	/**
	 * GIVEN an express checkout started from a product or cart page, where no checkout form was ever saved
	 * WHEN a billing field is resolved
	 * THEN the PayPal preset supplies the value, because there is nothing saved to prefer over it.
	 *
	 * @testdox Should fall back to the PayPal preset when no checkout form was ever saved.
	 */
	public function test_filter_checkout_field_falls_back_to_preset_when_no_checkout_form_was_ever_saved(): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_with_payer_name( 'John', 'Doe' ) );
		$this->session_handler->shouldReceive( 'checkout_form' )->andReturn( array() );

		$this->assertSame( 'John', $this->testee->filter_checkout_field( null, 'billing_first_name' ) );
	}

	/**
	 * GIVEN a saved checkout form value that is not a string
	 * WHEN the field is resolved
	 * THEN the PayPal preset is used rather than returning the non-string value.
	 *
	 * @testdox Should fall back to the PayPal preset when the saved value is not a string.
	 *
	 * @dataProvider data_non_string_saved_checkout_value
	 *
	 * @param mixed $saved_value The saved value.
	 */
	public function test_filter_checkout_field_falls_back_to_preset_when_saved_value_is_not_a_string( $saved_value ): void {
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->make_order_with_payer_name( 'John', 'Doe' ) );
		$this->session_handler->shouldReceive( 'checkout_form' )->andReturn( array( 'billing_first_name' => $saved_value ) );

		$this->assertSame( 'John', $this->testee->filter_checkout_field( null, 'billing_first_name' ) );
	}

	/**
	 * Saved values that are not strings.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_non_string_saved_checkout_value(): array {
		return array(
			'array value'   => array( array( 'unexpected' ) ),
			'boolean value' => array( true ),
			'integer value' => array( 123 ),
		);
	}

	/**
	 * GIVEN a non-string field ID
	 * WHEN the field is resolved
	 * THEN the default value is returned untouched, without consulting the saved form or the PayPal preset.
	 *
	 * @testdox Should return the default value untouched when the field ID is not a string.
	 */
	public function test_filter_checkout_field_returns_default_value_when_field_id_is_not_a_string(): void {
		$this->session_handler->shouldReceive( 'order' )->never();
		$this->session_handler->shouldReceive( 'checkout_form' )->never();

		$this->assertSame( 'fallback', $this->testee->filter_checkout_field( 'fallback', 123 ) );
	}

	/**
	 * @testdox Should read the shipping of the first purchase unit that has one, once per order.
	 */
	public function test_read_shipping_from_order(): void {
		$shipping           = $this->mock( Shipping::class );
		$purchase_unit      = $this->mock( PurchaseUnit::class );
		$purchase_unit_last = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'shipping' )->once()->andReturn( $shipping );
		$purchase_unit_last->shouldReceive( 'shipping' )->never();

		$order = $this->mock_returning( Order::class, array( 'id' => 'whatever' ) );
		$order->shouldReceive( 'purchase_units' )
			->once()
			->andReturn(
				array(
					$this->mock_returning( PurchaseUnit::class, array( 'shipping' => null ) ),
					$purchase_unit,
					$purchase_unit_last,
				)
			);
		$this->session_handler->shouldReceive( 'order' )->andReturn( $order );

		$method = ( new ReflectionClass( $this->testee ) )->getMethod( 'read_shipping_from_order' );
		$method->setAccessible( true );

		$this->assertSame( $shipping, $method->invoke( $this->testee ) );
		$this->assertSame( $shipping, $method->invoke( $this->testee ), 'The second read should come from the cache' );
	}

	/**
	 * A Mockery mock of an entity that returns the given values by method name.
	 *
	 * @param string $class_name The class.
	 * @param array  $methods    The return values by method name.
	 * @return MockInterface
	 */
	private function mock_returning( string $class_name, array $methods ): MockInterface {
		return Mockery::mock( $class_name, $methods );
	}

	/**
	 * A PayPal payer with a name, an email address and a phone number.
	 *
	 * @return Payer
	 */
	private function make_payer(): Payer {
		return $this->mock_returning(
			Payer::class,
			array(
				'name'          => $this->mock_returning(
					PayerName::class,
					array(
						'given_name' => 'John',
						'surname'    => 'Doe',
					)
				),
				'email_address' => 'mail@domain.tld',
				'phone'         => $this->mock_returning(
					PhoneWithType::class,
					array( 'phone' => $this->mock_returning( Phone::class, array( 'national_number' => '+4912345678' ) ) )
				),
			)
		);
	}

	/**
	 * A PayPal order whose purchase unit ships to a German address, for a payer with a name, email and phone.
	 *
	 * @return Order
	 */
	private function make_order_with_address(): Order {
		$address = $this->mock_returning(
			Address::class,
			array(
				'address_line_1' => 'Unter den Linden 1',
				'address_line_2' => '2. Stock Hinterhaus',
				'postal_code'    => '10117',
				'country_code'   => 'DE',
				'admin_area_1'   => 'BE',
				'admin_area_2'   => 'Berlin',
			)
		);

		return $this->make_order_with_shipping( $this->mock_returning( Shipping::class, array( 'address' => $address ) ) );
	}

	/**
	 * A PayPal order whose shipping has no address, for a payer with a name, email and phone.
	 *
	 * @return Order
	 */
	private function make_order_without_address(): Order {
		return $this->make_order_with_shipping( $this->mock_returning( Shipping::class, array( 'address' => null ) ) );
	}

	/**
	 * A PayPal order with one purchase unit with the given shipping, for the standard payer.
	 *
	 * @param Shipping $shipping The shipping.
	 * @return Order
	 */
	private function make_order_with_shipping( Shipping $shipping ): Order {
		return $this->mock_returning(
			Order::class,
			array(
				'id'             => 'abc123def',
				'purchase_units' => array( $this->mock_returning( PurchaseUnit::class, array( 'shipping' => $shipping ) ) ),
				'payer'          => $this->make_payer(),
			)
		);
	}

	/**
	 * A PayPal order whose payer has the given name and no shipping address, for the tests that only care about the
	 * precedence of the shopper's own form over the preset.
	 *
	 * @param string $given_name The given name.
	 * @param string $surname    The surname.
	 * @return Order
	 */
	private function make_order_with_payer_name( string $given_name, string $surname ): Order {
		return $this->mock_returning(
			Order::class,
			array(
				'id'             => 'order-with-payer-name',
				'purchase_units' => array(),
				'payer'          => $this->mock_returning(
					Payer::class,
					array(
						'name' => $this->mock_returning(
							PayerName::class,
							array(
								'given_name' => $given_name,
								'surname'    => $surname,
							)
						),
					)
				),
			)
		);
	}
}
