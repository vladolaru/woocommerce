<?php
/**
 * Tests for the PayPal wallet WooCommerce order creator.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\WooCommerceOrderCreator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Error;
use ReflectionMethod;
use WC_Customer;
use WC_Helper_Product;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;

/**
 * Building the WooCommerce order from an approved PayPal order and the cart: line items, fees, addresses, the cart
 * hash and the clean-up when a build step fails. Runs over real WooCommerce orders, items, customers and hooks.
 *
 * @group paypal-wallet
 */
class WooCommerceOrderCreatorTest extends WalletTestCase {

	/**
	 * The customer the test replaced on the WooCommerce instance, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_customer;

	/**
	 * Remember the customer the tests may replace.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_customer = WC()->customer;
	}

	/**
	 * Restore the customer.
	 */
	public function tearDown(): void {
		try {
			WC()->customer = $this->original_customer;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The woocommerce_paypal_payments_order_creator_get_shipping filter lets another module supply the shipping.
	 *
	 * @testdox Should let the order-creator get-shipping filter override the shipping, passing it the PayPal order and the PayPal data.
	 */
	public function test_filter_can_override_return_value_of_get_shipping(): void {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array() );

		$filter_shipping = $this->mock( Shipping::class );
		$paypal_data     = array( 'key' => 'value' );
		$received        = array();

		add_filter(
			'woocommerce_paypal_payments_order_creator_get_shipping',
			function ( $shipping, $paypal_order, $data ) use ( $filter_shipping, &$received ) {
				$received = array( $shipping, $paypal_order, $data );
				return $filter_shipping;
			},
			10,
			3
		);

		$method = new ReflectionMethod( WooCommerceOrderCreator::class, 'get_shipping' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->create_order_creator(), $order, $paypal_data );

		$this->assertSame( $filter_shipping, $result );
		$this->assertNull( $received[0], 'The filter should start from the shipping built from the order, here none' );
		$this->assertSame( $order, $received[1] );
		$this->assertSame( $paypal_data, $received[2] );
	}

	/**
	 * Each cart item becomes an order line item through the WooCommerce Core checkout filter and action, so plugins can
	 * swap or augment the item. The item the filter returns is the one added to the order.
	 *
	 * The extension also ran this with an item class that lacks set_order() (WooCommerce before 10.9, which falls back
	 * to set_order_id()). Core's WC_Order_Item always declares set_order(), so that branch cannot run here.
	 *
	 * @testdox Should create the line item through the core checkout filter, fire the core action and add the filtered item.
	 */
	public function test_configure_line_items_uses_core_checkout_filter_and_action_for_each_cart_item(): void {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( 'Test product' );
		$product->save();

		$cart_item_key = 'abc123';
		$cart_item     = array(
			'product_id'    => $product->get_id(),
			'variation_id'  => 0,
			'quantity'      => 2,
			'variation'     => array(),
			'line_subtotal' => '20',
			'line_total'    => '20',
		);
		$cart_data     = new CartData( array( $cart_item_key => $cart_item ), array(), false, 0, 'cart-hash' );
		$wc_order      = wc_create_order();

		$filter_args = array();
		add_filter(
			'woocommerce_checkout_create_order_line_item_object',
			function ( $item, $key, $data, $order ) use ( &$filter_args ) {
				$filter_args = array( $item, $key, $data, $order );

				$filtered = new WC_Order_Item_Product();
				$filtered->add_meta_data( '_filtered_marker', 'yes', true );
				return $filtered;
			},
			10,
			4
		);

		$subtotal_args = array();
		add_filter(
			'woocommerce_paypal_payments_shipping_callback_cart_line_item_total',
			function ( $subtotal, $data ) use ( &$subtotal_args ) {
				$subtotal_args = array( $subtotal, $data );
				return $subtotal;
			},
			10,
			2
		);

		$action_args = array();
		add_action(
			'woocommerce_checkout_create_order_line_item',
			function ( $item, $key, $data, $order ) use ( &$action_args ) {
				$action_args = array( $item, $key, $data, $order );
			},
			10,
			4
		);

		$subscription_helper = $this->mock( SubscriptionHelper::class );
		$subscription_helper->shouldReceive( 'plugin_is_active' )->once()->andReturn( false );

		$method = new ReflectionMethod( WooCommerceOrderCreator::class, 'configure_line_items' );
		$method->setAccessible( true );
		$method->invoke( $this->create_order_creator( $subscription_helper ), $wc_order, $cart_data, null, null );

		$this->assertInstanceOf( WC_Order_Item_Product::class, $filter_args[0], 'The filter should get a fresh product line item' );
		$this->assertSame( $cart_item_key, $filter_args[1] );
		$this->assertSame( $cart_item, $filter_args[2] );
		$this->assertSame( $wc_order, $filter_args[3] );
		$this->assertSame( array( '20', $cart_item ), $subtotal_args );

		$items = array_values( $wc_order->get_items() );
		$this->assertCount( 1, $items );
		$item = $items[0];
		$this->assertSame( 'yes', $item->get_meta( '_filtered_marker' ), 'The item the filter returned should be the one added' );
		$this->assertSame( $product->get_id(), $item->get_product_id() );
		$this->assertSame( 2, $item->get_quantity() );
		$this->assertSame( 'Test product', $item->get_name() );
		$this->assertSame( '20', $item->get_subtotal() );
		$this->assertSame( '20', $item->get_total() );
		$this->assertSame( '0', $item->get_total_tax() );
		$this->assertSame( $wc_order->get_id(), $item->get_order_id() );

		$this->assertSame( $item, $action_args[0], 'The core action should receive the item added to the order' );
		$this->assertSame( $cart_item_key, $action_args[1] );
		$this->assertSame( $cart_item, $action_args[2] );
		$this->assertSame( $wc_order, $action_args[3] );
	}

	/**
	 * Before the fix, cart fees were dropped entirely and the order total silently diverged from what the buyer approved
	 * in the wallet sheet.
	 *
	 * @testdox Should add a fee item carrying the fee's name, amount and total, and fire the core fee action with the fee as an object.
	 */
	public function test_configure_fees_adds_order_item_for_a_cart_fee(): void {
		$fee_key = 'negative12Fee';
		$fee     = $this->cart_fee( $fee_key, 'Loyalty discount', '-12' );

		$action_args = array();
		add_action(
			'woocommerce_checkout_create_order_fee_item',
			function ( $item, $key, $fee_object, $order ) use ( &$action_args ) {
				$action_args = array( $item, $key, $fee_object, $order );
			},
			10,
			4
		);

		$wc_order = wc_create_order();
		$this->invoke_configure_fees( $wc_order, array( $fee_key => $fee ) );

		$fees = array_values( $wc_order->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertInstanceOf( WC_Order_Item_Fee::class, $fees[0] );
		$this->assertSame( 'Loyalty discount', $fees[0]->get_name() );
		$this->assertSame( '-12', $fees[0]->get_amount() );
		$this->assertSame( '-12', $fees[0]->get_total() );

		$this->assertSame( $fees[0], $action_args[0] );
		$this->assertSame( $fee_key, $action_args[1] );
		$this->assertIsObject( $action_args[2], 'The fee should be passed as an object, not the array' );
		$this->assertSame( 'Loyalty discount', $action_args[2]->name );
		$this->assertSame( '-12', $action_args[2]->amount );
		$this->assertSame( $wc_order, $action_args[3] );
	}

	/**
	 * Untaxed fees must say so explicitly: totals are recalculated once the order is assembled, so a fee item would
	 * otherwise default to taxable and pick up tax the cart never charged.
	 *
	 * @testdox Should mirror the cart fee's tax status and class on the fee item: $expected_tax_status, "$expected_tax_class".
	 *
	 * @dataProvider fee_taxability_provider
	 *
	 * @param bool   $taxable             Whether the cart fee is taxable.
	 * @param string $tax_class           The cart fee's tax class.
	 * @param string $expected_tax_status The expected fee item tax status.
	 * @param string $expected_tax_class  The expected fee item tax class.
	 */
	public function test_configure_fees_sets_tax_status_and_class_from_cart_fee(
		bool $taxable,
		string $tax_class,
		string $expected_tax_status,
		string $expected_tax_class
	): void {
		$fee              = $this->cart_fee( 'surchargeFee', 'Card surcharge', '5' );
		$fee['taxable']   = $taxable;
		$fee['tax_class'] = $tax_class;

		$wc_order = wc_create_order();
		$this->invoke_configure_fees( $wc_order, array( 'surchargeFee' => $fee ) );

		$fees = array_values( $wc_order->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertSame( $expected_tax_status, $fees[0]->get_tax_status() );
		$this->assertSame( $expected_tax_class, $fees[0]->get_tax_class() );
	}

	/**
	 * Fee taxability cases.
	 *
	 * @return array<string, array>
	 */
	public function fee_taxability_provider(): array {
		return array(
			'non-taxable fee is marked tax-exempt and loses its tax class' => array( false, 'reduced-rate', 'none', '' ),
			'taxable fee keeps its taxable status and tax class'           => array( true, 'reduced-rate', 'taxable', 'reduced-rate' ),
		);
	}

	/**
	 * @testdox Should add no fee item when the cart has no fees.
	 */
	public function test_configure_fees_adds_no_items_when_cart_has_no_fees(): void {
		$wc_order = wc_create_order();
		$this->invoke_configure_fees( $wc_order, array() );

		$this->assertCount( 0, $wc_order->get_fees() );
	}

	/**
	 * @testdox Should add one fee item per cart fee, each with its own total.
	 */
	public function test_configure_fees_adds_an_item_for_every_cart_fee(): void {
		$wc_order = wc_create_order();
		$this->invoke_configure_fees(
			$wc_order,
			array(
				'cardSurcharge'   => $this->cart_fee( 'cardSurcharge', 'Card surcharge', '3' ),
				'loyaltyDiscount' => $this->cart_fee( 'loyaltyDiscount', 'Loyalty discount', '-12' ),
			)
		);

		$totals = array_map(
			function ( $fee ) {
				return $fee->get_total();
			},
			array_values( $wc_order->get_fees() )
		);
		$this->assertSame( array( '3', '-12' ), $totals );
	}

	/**
	 * Downstream gates, like the order approval guards, compare the order's cart hash with the hash captured
	 * at approval time.
	 *
	 * @testdox Should set the WC order's cart hash from the cart data and fire the order-created-from-cart action.
	 */
	public function test_create_from_paypal_order_sets_cart_hash_from_cart_data(): void {
		WC()->customer = null;
		$cart_data     = new CartData( array(), array(), false, 0, 'expected-cart-hash' );

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'payer' )->andReturn( null );
		$order->shouldReceive( 'purchase_units' )->andReturn( array() );

		$created = array();
		add_action(
			'woocommerce_paypal_payments_woocommerce_order_created_from_cart',
			function ( $wc_order, $data ) use ( &$created ) {
				$created[] = array( $wc_order, $data );
			},
			10,
			2
		);

		$session_handler = $this->mock( SessionHandler::class );
		$session_handler->shouldReceive( 'funding_source' )->once()->andReturn( null );

		$result = $this->create_order_creator( null, $session_handler )->create_from_paypal_order( $order, $cart_data );

		$this->assertInstanceOf( WC_Order::class, $result );
		$saved = wc_get_order( $result->get_id() );
		$this->assertSame( 'expected-cart-hash', $saved->get_cart_hash() );
		$this->assertSame( PayPalGateway::ID, $saved->get_payment_method() );
		$this->assertCount( 1, $created, 'The order-created action should fire once' );
		$this->assertSame( $result, $created[0][0] );
		$this->assertSame( $cart_data, $created[0][1] );
	}

	/**
	 * A build step that raises a PHP Error (for example a method missing on the running WooCommerce version) must not be
	 * wrapped: callers surface exception messages to the buyer, and an Error's message is internal.
	 *
	 * @testdox Should delete the half-built order and rethrow the very same Error, without firing the order-created action.
	 */
	public function test_create_from_paypal_order_rethrows_error_unchanged_and_deletes_order(): void {
		WC()->customer = null;
		$cart_data     = new CartData( array(), array(), false, 0, 'cart-hash' );

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'payer' )->andReturn( null );
		$order->shouldReceive( 'purchase_units' )->andReturn( array() );

		$boom            = new Error( 'boom' );
		$session_handler = $this->mock( SessionHandler::class );
		$session_handler->shouldReceive( 'funding_source' )->once()->andThrow( $boom );

		$new_order_ids = array();
		add_action(
			'woocommerce_new_order',
			function ( $order_id ) use ( &$new_order_ids ) {
				$new_order_ids[] = $order_id;
			}
		);
		$created_before = did_action( 'woocommerce_paypal_payments_woocommerce_order_created_from_cart' );

		try {
			$this->create_order_creator( null, $session_handler )->create_from_paypal_order( $order, $cart_data );
			$this->fail( 'Expected the Error to propagate.' );
		} catch ( Error $caught ) {
			$this->assertSame( $boom, $caught );
		}

		$this->assertCount( 1, $new_order_ids, 'One WC order should have been created' );
		$this->assertFalse( wc_get_order( $new_order_ids[0] ), 'The half-built order should be deleted' );
		$this->assertSame( $created_before, did_action( 'woocommerce_paypal_payments_woocommerce_order_created_from_cart' ) );
	}

	/**
	 * With shipping disabled PayPal can return a payer with only an email. Without the fallback the order and any
	 * subscription would be left with an empty billing address even though the customer has one saved.
	 *
	 * @testdox Should fall back to the logged-in customer's saved billing address when the PayPal payer has no address, and set no shipping address.
	 */
	public function test_configure_addresses_falls_back_to_customer_billing_when_payer_has_no_address(): void {
		$payer = $this->mock( Payer::class );
		$payer->shouldReceive( 'address' )->andReturn( null );
		$payer->shouldReceive( 'name' )->andReturn( null );
		$payer->shouldReceive( 'phone' )->andReturn( null );
		$payer->shouldReceive( 'email_address' )->andReturn( 'payer@example.com' );

		WC()->customer = $this->create_customer_with_saved_billing();

		$wc_order = wc_create_order();
		$this->invoke_configure_addresses( $wc_order, $payer );

		$this->assertSame( 'john@example.com', $wc_order->get_billing_email() );
		$this->assertSame( 'John', $wc_order->get_billing_first_name() );
		$this->assertSame( 'Doe', $wc_order->get_billing_last_name() );
		$this->assertSame( '123 Main St', $wc_order->get_billing_address_1() );
		$this->assertSame( '', $wc_order->get_billing_address_2() );
		$this->assertSame( 'NYC', $wc_order->get_billing_city() );
		$this->assertSame( 'NY', $wc_order->get_billing_state() );
		$this->assertSame( '10001', $wc_order->get_billing_postcode() );
		$this->assertSame( 'US', $wc_order->get_billing_country() );
		$this->assertSame( '5551234', $wc_order->get_billing_phone() );
		$this->assertSame( '', $wc_order->get_shipping_address_1(), 'No shipping address should be set' );
		$this->assertSame( '', $wc_order->get_shipping_country(), 'No shipping address should be set' );
	}

	/**
	 * @testdox Should set the billing address from the PayPal payer's address, not the customer's saved one, when the payer has one.
	 */
	public function test_configure_addresses_keeps_payer_billing_address_when_present(): void {
		$address = $this->mock( Address::class );
		$address->shouldReceive( 'address_line_1' )->andReturn( '456 Payer Ave' );
		$address->shouldReceive( 'address_line_2' )->andReturn( '' );
		$address->shouldReceive( 'admin_area_2' )->andReturn( 'LA' );
		$address->shouldReceive( 'admin_area_1' )->andReturn( 'CA' );
		$address->shouldReceive( 'postal_code' )->andReturn( '90001' );
		$address->shouldReceive( 'country_code' )->andReturn( 'US' );

		$payer = $this->mock( Payer::class );
		$payer->shouldReceive( 'address' )->andReturn( $address );
		$payer->shouldReceive( 'name' )->andReturn( null );
		$payer->shouldReceive( 'phone' )->andReturn( null );
		$payer->shouldReceive( 'email_address' )->andReturn( 'payer@example.com' );

		$customer = $this->create_customer_with_saved_billing();
		$customer->set_email( '' );
		WC()->customer = $customer;

		$wc_order = wc_create_order();
		$this->invoke_configure_addresses( $wc_order, $payer );

		$this->assertSame( 'payer@example.com', $wc_order->get_billing_email() );
		$this->assertSame( '', $wc_order->get_billing_first_name() );
		$this->assertSame( '', $wc_order->get_billing_last_name() );
		$this->assertSame( '456 Payer Ave', $wc_order->get_billing_address_1() );
		$this->assertSame( 'LA', $wc_order->get_billing_city() );
		$this->assertSame( 'CA', $wc_order->get_billing_state() );
		$this->assertSame( '90001', $wc_order->get_billing_postcode() );
		$this->assertSame( 'US', $wc_order->get_billing_country() );
		$this->assertSame( '', $wc_order->get_billing_phone() );
		$this->assertSame( '', $wc_order->get_shipping_address_1(), 'No shipping address should be set' );
	}

	/**
	 * Build a WooCommerceOrderCreator with all collaborators mocked, so tests override only the dependency that matters.
	 *
	 * @param SubscriptionHelper|null $subscription_helper The subscription helper, if the test needs its own.
	 * @param SessionHandler|null     $session_handler     The PayPal session handler, if the test needs its own.
	 * @return WooCommerceOrderCreator
	 */
	private function create_order_creator( ?SubscriptionHelper $subscription_helper = null, ?SessionHandler $session_handler = null ): WooCommerceOrderCreator {
		return new WooCommerceOrderCreator(
			$this->mock( FundingSourceRenderer::class ),
			$session_handler ?? $this->mock( SessionHandler::class ),
			$subscription_helper ?? $this->mock( SubscriptionHelper::class ),
			$this->mock( CartDataFactory::class ),
			$this->mock( ShippingFactory::class ),
			$this->mock( PayerFactory::class )
		);
	}

	/**
	 * A cart fee in the shape the cart data carries.
	 *
	 * @param string $id     The fee ID.
	 * @param string $name   The fee name.
	 * @param string $amount The fee amount.
	 * @return array
	 */
	private function cart_fee( string $id, string $name, string $amount ): array {
		return array(
			'id'        => $id,
			'name'      => $name,
			'taxable'   => false,
			'tax_class' => '',
			'amount'    => $amount,
			'total'     => $amount,
			'tax'       => 0,
			'tax_data'  => array(),
		);
	}

	/**
	 * Call the protected configure_fees() on the order with the given cart fees.
	 *
	 * @param WC_Order $wc_order The order.
	 * @param array    $fees     The cart fees by key.
	 */
	private function invoke_configure_fees( WC_Order $wc_order, array $fees ): void {
		$cart_data = new CartData( array(), array(), false, 0, 'cart-hash', $fees );

		$method = new ReflectionMethod( WooCommerceOrderCreator::class, 'configure_fees' );
		$method->setAccessible( true );
		$method->invoke( $this->create_order_creator(), $wc_order, $cart_data );
	}

	/**
	 * Call the protected configure_addresses() for an order that needs no shipping and has no PayPal shipping.
	 *
	 * @param WC_Order $wc_order The order.
	 * @param Payer    $payer    The PayPal payer.
	 */
	private function invoke_configure_addresses( WC_Order $wc_order, Payer $payer ): void {
		$method = new ReflectionMethod( WooCommerceOrderCreator::class, 'configure_addresses' );
		$method->setAccessible( true );
		$method->invoke( $this->create_order_creator(), $wc_order, $payer, null, false );
	}

	/**
	 * A guest customer object with an account email and a saved billing address.
	 *
	 * @return WC_Customer
	 */
	private function create_customer_with_saved_billing(): WC_Customer {
		$customer = new WC_Customer( 0 );
		$customer->set_email( 'john@example.com' );
		$customer->set_billing_email( 'john@example.com' );
		$customer->set_billing_first_name( 'John' );
		$customer->set_billing_last_name( 'Doe' );
		$customer->set_billing_address_1( '123 Main St' );
		$customer->set_billing_address_2( '' );
		$customer->set_billing_city( 'NYC' );
		$customer->set_billing_state( 'NY' );
		$customer->set_billing_postcode( '10001' );
		$customer->set_billing_country( 'US' );
		$customer->set_billing_phone( '5551234' );

		return $customer;
	}
}
