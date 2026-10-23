<?php
/**
 * Tests for the purchase unit factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ShippingOption;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AmountFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ItemFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PaymentsFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Cart;
use WC_Order;
use WC_Session_Handler;

/**
 * Builds a purchase unit (the amount, the items, the shipping and the identifiers of one order) from an order, a cart
 * or a PayPal response. Shipping is dropped when the address is not complete enough for PayPal to accept it.
 *
 * Dropped cases of the extension's version: the five Level 2 and Level 3 processing cases
 * (test_wc_order_with_level_2_processing, test_wc_order_without_level_2_processing_when_not_eligible,
 * test_wc_order_with_level_3_processing, test_wc_order_with_both_level_2_and_level_3_processing and
 * test_wc_cart_with_level_2_processing), because the payment level helpers went with the card payments.
 *
 * The order, the cart, the customer and the session are the real WooCommerce ones. The amount, item, shipping and
 * payments factories are mocks, and the entities they hand back are real.
 *
 * @group paypal-wallet
 */
class PurchaseUnitFactoryTest extends WalletTestCase {

	/**
	 * A physical item of 42.50.
	 *
	 * @var Item
	 */
	private $item;

	/**
	 * The session and the customer the test replaced, put back on tear down.
	 *
	 * @var array{session: mixed, customer: mixed}
	 */
	private $original_state;

	/**
	 * Set the shop currency and give the test a cart, a customer and no session.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_option( 'woocommerce_currency', 'USD' );

		$this->original_state = array(
			'session'  => WC()->session,
			'customer' => WC()->customer,
		);
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		WC()->session = null;
		if ( ! WC()->customer ) {
			WC()->customer = new \WC_Customer( 0, true );
		}

		$this->item = new Item( 'name', new Money( 42.5, 'USD' ), 1, '', null, '', Item::PHYSICAL_GOODS );
	}

	/**
	 * Put the session and the customer back. The base class empties the cart.
	 */
	public function tearDown(): void {
		try {
			WC()->session  = $this->original_state['session'];
			WC()->customer = $this->original_state['customer'];
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A saved order.
	 *
	 * @return WC_Order
	 */
	private function make_wc_order(): WC_Order {
		return wc_create_order();
	}

	/**
	 * An address of the given parts.
	 *
	 * @param string $country_code The country code.
	 * @param string $postal_code  The postal code.
	 * @param string $line_1       The first address line.
	 * @param string $city         The city.
	 * @return Address
	 */
	private function make_address( string $country_code = 'DE', string $postal_code = '12345', string $line_1 = 'Berlin Street', string $city = 'Berlin' ): Address {
		return new Address( $country_code, $line_1, '', '', $city, $postal_code );
	}

	/**
	 * A shipping node around an address.
	 *
	 * @param Address|null     $address The address.
	 * @param ShippingOption[] $options The shipping options.
	 * @return Shipping
	 */
	private function make_shipping( ?Address $address, array $options = array() ): Shipping {
		return new Shipping( 'Jane Doe', $address, null, null, $options );
	}

	/**
	 * An amount factory that hands out the given amount for the given source.
	 *
	 * @param Amount $amount        The amount.
	 * @param string $source_method The method that is called, from_wc_order, from_wc_cart or from_paypal_response.
	 * @param mixed  $with          The argument it gets.
	 * @return AmountFactory
	 */
	private function make_amount_factory( Amount $amount, string $source_method, $with ): AmountFactory {
		$factory = $this->mock( AmountFactory::class );
		$factory->shouldReceive( $source_method )->with( $with )->andReturn( $amount );

		return $factory;
	}

	/**
	 * An item factory that hands out the given items for the given source.
	 *
	 * @param Item[] $items         The items.
	 * @param string $source_method The method that is called, from_wc_order or from_wc_cart.
	 * @param mixed  $with          The argument it gets.
	 * @return ItemFactory
	 */
	private function make_item_factory( array $items, string $source_method, $with ): ItemFactory {
		$factory = $this->mock( ItemFactory::class );
		$factory->shouldReceive( $source_method )->with( $with )->andReturn( $items );

		return $factory;
	}

	/**
	 * The factory under test.
	 *
	 * @param AmountFactory        $amount_factory   The amount factory.
	 * @param ItemFactory          $item_factory     The item factory.
	 * @param ShippingFactory      $shipping_factory The shipping factory.
	 * @param PaymentsFactory|null $payments_factory The payments factory.
	 * @param string               $prefix           The invoice ID prefix.
	 * @param string               $soft_descriptor  The soft descriptor.
	 * @return PurchaseUnitFactory
	 */
	private function make_testee( AmountFactory $amount_factory, ItemFactory $item_factory, ShippingFactory $shipping_factory, ?PaymentsFactory $payments_factory = null, string $prefix = 'WC-', string $soft_descriptor = '' ): PurchaseUnitFactory {
		return new PurchaseUnitFactory(
			$amount_factory,
			$item_factory,
			$shipping_factory,
			$payments_factory ?? $this->mock( PaymentsFactory::class ),
			$prefix,
			$soft_descriptor
		);
	}

	/**
	 * A testee for an order whose shipping factory returns the given shipping.
	 *
	 * @param WC_Order $wc_order The order.
	 * @param Amount   $amount   The amount.
	 * @param Shipping $shipping The shipping.
	 * @param Item[]   $items    The items.
	 * @param string   $prefix          The invoice ID prefix.
	 * @param string   $soft_descriptor The soft descriptor.
	 * @return PurchaseUnitFactory
	 */
	private function make_order_testee( WC_Order $wc_order, Amount $amount, Shipping $shipping, array $items, string $prefix = 'WC-', string $soft_descriptor = '' ): PurchaseUnitFactory {
		$shipping_factory = $this->mock( ShippingFactory::class );
		$shipping_factory->shouldReceive( 'from_wc_order' )->with( $wc_order )->andReturn( $shipping );

		return $this->make_testee(
			$this->make_amount_factory( $amount, 'from_wc_order', $wc_order ),
			$this->make_item_factory( $items, 'from_wc_order', $wc_order ),
			$shipping_factory,
			null,
			$prefix,
			$soft_descriptor
		);
	}

	/**
	 * A testee for the cart whose shipping factory returns the given shipping for the customer.
	 *
	 * @param WC_Cart       $wc_cart  The cart.
	 * @param Amount        $amount   The amount.
	 * @param Shipping|null $shipping The shipping, or null when the shipping factory must not be asked.
	 * @param Item[]        $items    The items.
	 * @return PurchaseUnitFactory
	 */
	private function make_cart_testee( WC_Cart $wc_cart, Amount $amount, ?Shipping $shipping, array $items ): PurchaseUnitFactory {
		$shipping_factory = $this->mock( ShippingFactory::class );
		if ( $shipping ) {
			$shipping_factory->expects( 'from_wc_customer' )->with( WC()->customer, false )->andReturn( $shipping );
		} else {
			$shipping_factory->shouldNotReceive( 'from_wc_customer' );
		}

		return $this->make_testee(
			$this->make_amount_factory( $amount, 'from_wc_cart', $wc_cart ),
			$this->make_item_factory( $items, 'from_wc_cart', $wc_cart ),
			$shipping_factory
		);
	}

	/**
	 * @testdox Should build the purchase unit from an order with its ID as the custom ID and the prefixed order number as the invoice ID.
	 */
	public function test_wc_order_default(): void {
		$wc_order = $this->make_wc_order();
		$amount   = new Amount( new Money( 42.5, 'USD' ) );
		$shipping = $this->make_shipping( $this->make_address() );

		$testee = $this->make_order_testee( $wc_order, $amount, $shipping, array( $this->item ) );

		$unit = $testee->from_wc_order( $wc_order );

		$this->assertInstanceOf( PurchaseUnit::class, $unit );
		$this->assertEquals( '', $unit->description() );
		$this->assertEquals( 'default', $unit->reference_id() );
		$this->assertEquals( $wc_order->get_id(), $unit->custom_id() );
		$this->assertEquals( '', $unit->soft_descriptor() );
		$this->assertEquals( 'WC-' . $wc_order->get_order_number(), $unit->invoice_id() );
		$this->assertEquals( array( $this->item ), $unit->items() );
		$this->assertEquals( $amount, $unit->amount() );
		$this->assertEquals( $shipping, $unit->shipping() );
	}

	/**
	 * @testdox Should leave the items with a negative amount out of the purchase unit of an order.
	 */
	public function test_wc_order_with_negative_fees(): void {
		$wc_order = $this->make_wc_order();
		$fee      = new Item( 'fee', new Money( 10.0, 'USD' ), 1, '', null, '', Item::DIGITAL_GOODS );
		$discount = new Item( 'discount', new Money( -5, 'USD' ), 1, '', null, '', Item::DIGITAL_GOODS );

		$testee = $this->make_order_testee(
			$wc_order,
			new Amount( new Money( 42.5, 'USD' ) ),
			$this->make_shipping( $this->make_address() ),
			array( $this->item, $fee, $discount )
		);

		$unit = $testee->from_wc_order( $wc_order );

		$this->assertInstanceOf( PurchaseUnit::class, $unit );
		$this->assertEquals( array( $this->item, $fee ), array_values( $unit->items() ) );
	}

	/**
	 * @testdox Should drop the shipping of an order for a given address that PayPal would reject.
	 *
	 * @dataProvider data_unusable_order_addresses
	 *
	 * @param Address $address The address of the order.
	 */
	public function test_wc_order_shipping_gets_dropped_for_unusable_address( Address $address ): void {
		$wc_order = $this->make_wc_order();

		$testee = $this->make_order_testee( $wc_order, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $address ), array( $this->item ) );

		$this->assertNull( $testee->from_wc_order( $wc_order )->shipping() );
	}

	/**
	 * The addresses that are not complete enough: no postal code (in a country that has them), no country code, no
	 * street and no city (in a country that has postal codes and in one that has none).
	 *
	 * Named after the extension's cases test_wc_order_shipping_gets_dropped_when_no_postal_code, _no_country_code,
	 * _no_address_line_1, _no_city and _no_city_in_postal_code_exempt_country.
	 *
	 * @return array<string, array<Address>>
	 */
	public function data_unusable_order_addresses(): array {
		return array(
			'no_postal_code'                        => array( $this->make_address( 'DE', '', 'Berlin Street', 'Berlin' ) ),
			'no_country_code'                       => array( $this->make_address( '', '12345', 'Berlin Street', 'Berlin' ) ),
			'no_address_line_1'                     => array( $this->make_address( 'DE', '12345', '', 'Berlin' ) ),
			'no_city'                               => array( $this->make_address( 'DE', '12345', 'Berlin Street', '' ) ),
			'no_city_in_postal_code_exempt_country' => array( $this->make_address( 'IE', '', 'Some Street', '' ) ),
		);
	}

	/**
	 * @testdox Should drop the shipping of an order when none of its items is a physical good.
	 */
	public function test_wc_order_shipping_gets_dropped_when_all_items_are_digital(): void {
		$wc_order = $this->make_wc_order();
		$digital  = new Item( 'ebook', new Money( 9.0, 'USD' ), 1, '', null, '', Item::DIGITAL_GOODS );

		$testee = $this->make_order_testee( $wc_order, new Amount( new Money( 9.0, 'USD' ) ), $this->make_shipping( $this->make_address() ), array( $digital ) );

		$this->assertNull( $testee->from_wc_order( $wc_order )->shipping() );
	}

	/**
	 * @testdox Should keep the shipping of an order for a country without postal codes when it has a city.
	 */
	public function test_wc_order_shipping_kept_when_ireland_has_city_no_postal_code(): void {
		$wc_order = $this->make_wc_order();
		$shipping = $this->make_shipping( $this->make_address( 'IE', '', 'Some Street', 'Dublin' ) );

		$testee = $this->make_order_testee( $wc_order, new Amount( new Money( 42.5, 'USD' ) ), $shipping, array( $this->item ) );

		$this->assertEquals( $shipping, $testee->from_wc_order( $wc_order )->shipping() );
	}

	/**
	 * @testdox Should prefix the invoice ID and clean the soft descriptor of an order.
	 */
	public function test_wc_order_uses_the_prefix_and_sanitizes_the_soft_descriptor(): void {
		$wc_order = $this->make_wc_order();

		$testee = $this->make_order_testee(
			$wc_order,
			new Amount( new Money( 42.5, 'USD' ) ),
			$this->make_shipping( $this->make_address() ),
			array( $this->item ),
			'SHOP-',
			'My Shop! &amp; Café *Online* store of long name'
		);

		$unit = $testee->from_wc_order( $wc_order );

		$this->assertSame( 'SHOP-' . $wc_order->get_order_number(), $unit->invoice_id() );
		// Everything outside letters, digits, space, star, dash and dot goes, and 22 characters stay.
		$this->assertSame( 'My Shop  Caf *Online* ', $unit->soft_descriptor() );
	}

	/**
	 * @testdox Should let the purchase unit of an order be replaced through the filter.
	 */
	public function test_wc_order_purchase_unit_can_be_replaced_through_a_filter(): void {
		$wc_order    = $this->make_wc_order();
		$replacement = new PurchaseUnit( new Amount( new Money( 1.0, 'USD' ) ), array(), null, 'replaced' );
		$calls       = $this->spy_filter( 'woocommerce_paypal_payments_purchase_unit_from_wc_order', $replacement );

		$testee = $this->make_order_testee( $wc_order, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $this->make_address() ), array( $this->item ) );

		$unit = $testee->from_wc_order( $wc_order );

		$this->assertSame( $replacement, $unit );
		$this->assertCount( 1, $calls );
		$this->assertInstanceOf( PurchaseUnit::class, $calls[0][0] );
		$this->assertSame( $wc_order, $calls[0][1] );
	}

	/**
	 * @testdox Should build the purchase unit from the cart with the shipping of the customer and no custom ID without a session.
	 */
	public function test_wc_cart_default(): void {
		$wc_cart  = WC()->cart;
		$amount   = new Amount( new Money( 42.5, 'USD' ) );
		$shipping = $this->make_shipping( $this->make_address() );

		$testee = $this->make_cart_testee( $wc_cart, $amount, $shipping, array( $this->item ) );

		$unit = $testee->from_wc_cart( $wc_cart );

		$this->assertInstanceOf( PurchaseUnit::class, $unit );
		$this->assertEquals( '', $unit->description() );
		$this->assertEquals( 'default', $unit->reference_id() );
		$this->assertEquals( '', $unit->custom_id() );
		$this->assertEquals( '', $unit->soft_descriptor() );
		$this->assertEquals( '', $unit->invoice_id() );
		$this->assertEquals( array( $this->item ), $unit->items() );
		$this->assertEquals( $amount, $unit->amount() );
		$this->assertEquals( $shipping, $unit->shipping() );
	}

	/**
	 * @testdox Should use the cart of the shop when it is not given one.
	 */
	public function test_wc_cart_defaults_to_the_cart_of_the_shop(): void {
		$wc_cart = WC()->cart;
		$testee  = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $this->make_address() ), array( $this->item ) );

		$unit = $testee->from_wc_cart();

		$this->assertEquals( array( $this->item ), $unit->items() );
	}

	/**
	 * @testdox Should name the custom ID of a cart after the customer of the session.
	 */
	public function test_wc_cart_custom_id_comes_from_the_session(): void {
		$session = $this->getMockBuilder( WC_Session_Handler::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_customer_unique_id' ) )
			->getMock();
		$session->method( 'get_customer_unique_id' )->willReturn( '77' );
		WC()->session = $session;
		$wc_cart      = WC()->cart;

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $this->make_address() ), array( $this->item ) );

		$unit = $testee->from_wc_cart( $wc_cart );

		$this->assertSame( 'pcp_customer_77', $unit->custom_id() );
	}

	/**
	 * @testdox Should drop the shipping of a cart when the shop has no customer.
	 */
	public function test_wc_cart_shipping_gets_dropped_when_no_customer(): void {
		WC()->customer = null;
		$wc_cart       = WC()->cart;

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), null, array( $this->item ) );

		$this->assertNull( $testee->from_wc_cart( $wc_cart )->shipping() );
	}

	/**
	 * @testdox Should drop the shipping of a cart for a customer address that PayPal would reject.
	 *
	 * @dataProvider data_unusable_cart_addresses
	 *
	 * @param Address $address The address of the customer.
	 */
	public function test_wc_cart_shipping_gets_dropped_for_unusable_address( Address $address ): void {
		$wc_cart = WC()->cart;

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $address ), array( $this->item ) );

		$this->assertNull( $testee->from_wc_cart( $wc_cart )->shipping() );
	}

	/**
	 * The addresses that are not complete enough, and no options to keep the shipping node for.
	 *
	 * Named after the extension's cases test_wc_cart_shipping_gets_dropped_when_no_country_code, _no_address_line_1 and
	 * _no_city.
	 *
	 * @return array<string, array<Address>>
	 */
	public function data_unusable_cart_addresses(): array {
		return array(
			'no_country_code'   => array( $this->make_address( '', '12345', 'Berlin Street', 'Berlin' ) ),
			'no_address_line_1' => array( $this->make_address( 'DE', '12345', '', 'Berlin' ) ),
			'no_city'           => array( $this->make_address( 'IE', '', 'Some Street', '' ) ),
		);
	}

	/**
	 * @testdox Should keep the shipping of a cart for a country without postal codes when it has a city.
	 */
	public function test_wc_cart_shipping_kept_when_ireland_has_city_no_postal_code(): void {
		$wc_cart  = WC()->cart;
		$shipping = $this->make_shipping( $this->make_address( 'IE', '', 'Some Street', 'Dublin' ) );

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $shipping, array( $this->item ) );

		$this->assertEquals( $shipping, $testee->from_wc_cart( $wc_cart )->shipping() );
	}

	/**
	 * Given a cart customer with an unusable shipping address whose shipping node carries shipping options, the
	 * shipping node is kept with a null address and the shipping name and options survive unchanged.
	 *
	 * @testdox Should keep the shipping node with its options and no address when the address of the cart is unusable.
	 */
	public function test_wc_cart_shipping_kept_with_null_address_when_unusable_address_has_options(): void {
		$wc_cart  = WC()->cart;
		$option   = new ShippingOption( 'flat_rate', 'Flat rate', true, new Money( 5.0, 'USD' ), ShippingOption::TYPE_SHIPPING );
		$shipping = $this->make_shipping( $this->make_address( 'DE', '12345', '', 'Berlin' ), array( $option ) );

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $shipping, array( $this->item ) );

		$result_shipping = $testee->from_wc_cart( $wc_cart )->shipping();

		$this->assertInstanceOf( Shipping::class, $result_shipping );
		$this->assertNull( $result_shipping->address() );
		$this->assertSame( array( $option ), $result_shipping->options() );
		$this->assertEquals( 'Jane Doe', $result_shipping->name() );
	}

	/**
	 * Given a cart customer with an unusable shipping address and no shipping options, the shipping node is dropped.
	 *
	 * @testdox Should drop the shipping node when the address of the cart is unusable and it has no options.
	 */
	public function test_wc_cart_shipping_dropped_when_unusable_address_has_no_options(): void {
		$wc_cart = WC()->cart;

		$testee = $this->make_cart_testee( $wc_cart, new Amount( new Money( 42.5, 'USD' ) ), $this->make_shipping( $this->make_address( 'DE', '12345', '', 'Berlin' ) ), array( $this->item ) );

		$this->assertNull( $testee->from_wc_cart( $wc_cart )->shipping() );
	}

	/**
	 * @testdox Should leave the customer's shipping out of a cart of digital goods and let a filter decide otherwise.
	 */
	public function test_wc_cart_shipping_needed_follows_the_items_and_the_filter(): void {
		$wc_cart = WC()->cart;
		$digital = new Item( 'ebook', new Money( 9.0, 'USD' ), 1, '', null, '', Item::DIGITAL_GOODS );
		$amount  = new Amount( new Money( 9.0, 'USD' ) );

		$digital_only = $this->make_cart_testee( $wc_cart, $amount, null, array( $digital ) );
		$this->assertNull( $digital_only->from_wc_cart( $wc_cart )->shipping() );

		$calls   = $this->spy_filter( 'woocommerce_paypal_payments_shipping_needed', true );
		$shipped = $this->make_cart_testee( $wc_cart, $amount, $this->make_shipping( $this->make_address() ), array( $digital ) );
		$unit    = $shipped->from_wc_cart( $wc_cart );

		$this->assertNotNull( $unit->shipping() );
		$this->assertCount( 1, $calls );
		$this->assertNull( $calls[0][0] );
	}

	/**
	 * @testdox Should build the purchase unit from a PayPal response.
	 */
	public function test_from_paypal_response_default(): void {
		$raw_item     = (object) array( 'items' => 1 );
		$raw_amount   = (object) array( 'amount' => 1 );
		$raw_shipping = (object) array( 'shipping' => 1 );

		$amount   = new Amount( new Money( 42.5, 'USD' ) );
		$shipping = $this->make_shipping( $this->make_address() );

		$shipping_factory = $this->mock( ShippingFactory::class );
		$shipping_factory->expects( 'from_paypal_response' )->with( $raw_shipping )->andReturn( $shipping );
		$item_factory = $this->mock( ItemFactory::class );
		$item_factory->expects( 'from_paypal_response' )->with( $raw_item )->andReturn( $this->item );

		$testee = $this->make_testee( $this->make_amount_factory( $amount, 'from_paypal_response', $raw_amount ), $item_factory, $shipping_factory );

		$response = (object) array(
			'reference_id'    => 'default',
			'description'     => 'description',
			'custom_id'       => 'customId',
			'invoice_id'      => 'invoiceId',
			'soft_descriptor' => 'softDescriptor',
			'amount'          => $raw_amount,
			'items'           => array( $raw_item ),
			'shipping'        => $raw_shipping,
		);

		$unit = $testee->from_paypal_response( $response );

		$this->assertInstanceOf( PurchaseUnit::class, $unit );
		$this->assertEquals( 'description', $unit->description() );
		$this->assertEquals( 'default', $unit->reference_id() );
		$this->assertEquals( 'customId', $unit->custom_id() );
		$this->assertEquals( 'softDescriptor', $unit->soft_descriptor() );
		$this->assertEquals( 'invoiceId', $unit->invoice_id() );
		$this->assertEquals( array( $this->item ), $unit->items() );
		$this->assertEquals( $amount, $unit->amount() );
		$this->assertEquals( $shipping, $unit->shipping() );
	}

	/**
	 * @testdox Should leave the shipping out when the PayPal response has none.
	 */
	public function test_from_paypal_response_shipping_is_null(): void {
		$raw_item     = (object) array( 'items' => 1 );
		$raw_amount   = (object) array( 'amount' => 1 );
		$item_factory = $this->mock( ItemFactory::class );
		$item_factory->expects( 'from_paypal_response' )->with( $raw_item )->andReturn( $this->item );

		$testee = $this->make_testee(
			$this->make_amount_factory( new Amount( new Money( 42.5, 'USD' ) ), 'from_paypal_response', $raw_amount ),
			$item_factory,
			$this->mock( ShippingFactory::class )
		);

		$response = (object) array(
			'reference_id' => 'default',
			'description'  => 'description',
			'amount'       => $raw_amount,
			'items'        => array( $raw_item ),
		);

		$unit = $testee->from_paypal_response( $response );

		$this->assertNull( $unit->shipping() );
	}

	/**
	 * @testdox Should fall back to the default reference ID when the PayPal response has none.
	 */
	public function test_from_paypal_response_uses_default_reference_id_as_fallback(): void {
		$raw_amount = (object) array( 'amount' => 1 );

		$testee = $this->make_testee(
			$this->make_amount_factory( new Amount( new Money( 42.5, 'USD' ) ), 'from_paypal_response', $raw_amount ),
			$this->mock( ItemFactory::class ),
			$this->mock( ShippingFactory::class )
		);

		$response = (object) array(
			'description' => 'description',
			'amount'      => $raw_amount,
			'items'       => array(),
		);

		$unit = $testee->from_paypal_response( $response );

		$this->assertInstanceOf( PurchaseUnit::class, $unit );
		$this->assertEquals( 'default', $unit->reference_id() );
	}

	/**
	 * @testdox Should attach the payments of the PayPal response.
	 */
	public function test_from_paypal_response_payments_get_appended(): void {
		$raw_item     = (object) array( 'items' => 1 );
		$raw_amount   = (object) array( 'amount' => 1 );
		$raw_shipping = (object) array( 'shipping' => 1 );
		$raw_payments = (object) array( 'payments' => 1 );

		$payments = $this->mock( Payments::class );

		$item_factory = $this->mock( ItemFactory::class );
		$item_factory->expects( 'from_paypal_response' )->with( $raw_item )->andReturn( $this->item );
		$shipping_factory = $this->mock( ShippingFactory::class );
		$shipping_factory->expects( 'from_paypal_response' )->with( $raw_shipping )->andReturn( $this->make_shipping( $this->make_address() ) );
		$payments_factory = $this->mock( PaymentsFactory::class );
		$payments_factory->expects( 'from_paypal_response' )->with( $raw_payments )->andReturn( $payments );

		$testee = $this->make_testee(
			$this->make_amount_factory( new Amount( new Money( 42.5, 'USD' ) ), 'from_paypal_response', $raw_amount ),
			$item_factory,
			$shipping_factory,
			$payments_factory
		);

		$response = (object) array(
			'reference_id' => 'default',
			'description'  => 'description',
			'amount'       => $raw_amount,
			'items'        => array( $raw_item ),
			'shipping'     => $raw_shipping,
			'payments'     => $raw_payments,
		);

		$unit = $testee->from_paypal_response( $response );

		$this->assertEquals( $payments, $unit->payments() );
	}

	/**
	 * @testdox Should leave the payments out when the PayPal response has none.
	 */
	public function test_from_paypal_response_payments_is_null(): void {
		$raw_item     = (object) array( 'items' => 1 );
		$raw_amount   = (object) array( 'amount' => 1 );
		$raw_shipping = (object) array( 'shipping' => 1 );

		$item_factory = $this->mock( ItemFactory::class );
		$item_factory->expects( 'from_paypal_response' )->with( $raw_item )->andReturn( $this->item );
		$shipping_factory = $this->mock( ShippingFactory::class );
		$shipping_factory->expects( 'from_paypal_response' )->with( $raw_shipping )->andReturn( $this->make_shipping( $this->make_address() ) );

		$testee = $this->make_testee(
			$this->make_amount_factory( new Amount( new Money( 42.5, 'USD' ) ), 'from_paypal_response', $raw_amount ),
			$item_factory,
			$shipping_factory
		);

		$response = (object) array(
			'reference_id' => 'default',
			'description'  => 'description',
			'amount'       => $raw_amount,
			'items'        => array( $raw_item ),
			'shipping'     => $raw_shipping,
		);

		$unit = $testee->from_paypal_response( $response );

		$this->assertNull( $unit->payments() );
	}
}
