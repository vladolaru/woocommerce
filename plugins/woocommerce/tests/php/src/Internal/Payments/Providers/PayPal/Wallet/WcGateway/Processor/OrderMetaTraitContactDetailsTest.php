<?php
/**
 * Tests for the contact details the order meta trait copies from a PayPal order onto the WooCommerce order.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Phone;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderMetaTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionMethod;
use WC_Order;

/**
 * With the Contact Module, the buyer can give PayPal an email address and a phone number that differ from the ones on
 * the WooCommerce order. The trait keeps both: the number PayPal reports goes into the contact meta and the number the
 * order had goes into the original meta, so the merchant sees where each came from.
 *
 * Runs over a real WooCommerce order and the real PayPal order entities.
 *
 * @group paypal-wallet
 */
class OrderMetaTraitContactDetailsTest extends WalletTestCase {

	/**
	 * A WooCommerce order with the billing email and phone the shop collected at checkout.
	 *
	 * @param string $billing_email The billing email.
	 * @param string $billing_phone The billing phone.
	 * @return WC_Order
	 */
	private function wc_order_billed_to( string $billing_email, string $billing_phone ): WC_Order {
		$wc_order = wc_create_order();
		$wc_order->set_billing_email( $billing_email );
		$wc_order->set_billing_phone( $billing_phone );

		return $wc_order;
	}

	/**
	 * A PayPal order whose purchase unit carries the buyer's contact details in its shipping information.
	 *
	 * @param string|null $contact_email The contact email, if the buyer gave one.
	 * @param string|null $contact_phone The contact phone number, if the buyer gave one.
	 * @param bool        $with_shipping Whether the purchase unit has shipping information at all.
	 * @return Order
	 */
	private function paypal_order_with_contact( ?string $contact_email, ?string $contact_phone, bool $with_shipping = true ): Order {
		$shipping = $with_shipping
			? new Shipping( 'Jane Buyer', null, $contact_email, null === $contact_phone ? null : new Phone( $contact_phone ) )
			: null;

		return new Order(
			'PAYPAL-ORDER-1',
			array( new PurchaseUnit( new Amount( new Money( 10.0, 'USD' ) ), array(), $shipping ) ),
			new OrderStatus( OrderStatus::CREATED )
		);
	}

	/**
	 * Call the trait's private add_contact_details_to_wc_order() on a throwaway object that uses the trait.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param Order    $order    The PayPal order.
	 */
	private function add_contact_details( WC_Order $wc_order, Order $order ): void {
		$fixture = new class() {
			use OrderMetaTrait;
		};

		$method = new ReflectionMethod( $fixture, 'add_contact_details_to_wc_order' );
		$method->setAccessible( true );
		$method->invoke( $fixture, $wc_order, $order );
	}

	/**
	 * The contact and original meta the trait writes, in one array.
	 *
	 * @param WC_Order $wc_order The order.
	 * @return array<string, mixed>
	 */
	private function contact_meta( WC_Order $wc_order ): array {
		$keys = array(
			PayPalGateway::CONTACT_EMAIL_META_KEY,
			PayPalGateway::ORIGINAL_EMAIL_META_KEY,
			PayPalGateway::CONTACT_PHONE_META_KEY,
			PayPalGateway::ORIGINAL_PHONE_META_KEY,
		);

		$meta = array();
		foreach ( $keys as $key ) {
			$meta[ $key ] = $wc_order->get_meta( $key );
		}

		return $meta;
	}

	/**
	 * Record each firing of the contacts-added action.
	 *
	 * @return ArrayObject One entry per firing, holding the WooCommerce order and the PayPal order.
	 */
	private function watch_contacts_added(): ArrayObject {
		$calls = new ArrayObject();
		add_action(
			'woocommerce_paypal_payments_contacts_added',
			static function ( $wc_order, $paypal_order ) use ( $calls ) {
				$calls[] = array( $wc_order, $paypal_order );
			},
			10,
			2
		);

		return $calls;
	}

	/**
	 * @testdox Should keep the contact email PayPal reports and the billing email the order had, and announce the contact once.
	 */
	public function test_a_different_contact_email_is_kept_next_to_the_billing_email(): void {
		$wc_order = $this->wc_order_billed_to( 'billing@example.com', '' );
		$order    = $this->paypal_order_with_contact( 'contact@example.com', null );
		$calls    = $this->watch_contacts_added();

		$this->add_contact_details( $wc_order, $order );

		$this->assertSame(
			array(
				PayPalGateway::CONTACT_EMAIL_META_KEY  => 'contact@example.com',
				PayPalGateway::ORIGINAL_EMAIL_META_KEY => 'billing@example.com',
				PayPalGateway::CONTACT_PHONE_META_KEY  => '',
				PayPalGateway::ORIGINAL_PHONE_META_KEY => '',
			),
			$this->contact_meta( $wc_order )
		);
		$this->assertCount( 1, $calls, 'The contacts-added action should fire once' );
		$this->assertSame( $wc_order, $calls[0][0] );
		$this->assertSame( $order, $calls[0][1] );
	}

	/**
	 * @testdox Should keep the contact phone number PayPal reports and the billing phone the order had, and announce the contact once.
	 */
	public function test_a_different_contact_phone_is_kept_next_to_the_billing_phone(): void {
		$wc_order = $this->wc_order_billed_to( '', '5551110000' );
		$calls    = $this->watch_contacts_added();

		$this->add_contact_details( $wc_order, $this->paypal_order_with_contact( null, '5552220000' ) );

		$this->assertSame(
			array(
				PayPalGateway::CONTACT_EMAIL_META_KEY  => '',
				PayPalGateway::ORIGINAL_EMAIL_META_KEY => '',
				PayPalGateway::CONTACT_PHONE_META_KEY  => '5552220000',
				PayPalGateway::ORIGINAL_PHONE_META_KEY => '5551110000',
			),
			$this->contact_meta( $wc_order )
		);
		$this->assertCount( 1, $calls, 'The contacts-added action should fire once' );
	}

	/**
	 * @testdox Should keep both the contact email and the contact phone and announce them in one action.
	 */
	public function test_a_different_email_and_phone_are_both_kept_and_announced_once(): void {
		$wc_order = $this->wc_order_billed_to( 'billing@example.com', '5551110000' );
		$calls    = $this->watch_contacts_added();

		$this->add_contact_details( $wc_order, $this->paypal_order_with_contact( 'contact@example.com', '5552220000' ) );

		$this->assertSame(
			array(
				PayPalGateway::CONTACT_EMAIL_META_KEY  => 'contact@example.com',
				PayPalGateway::ORIGINAL_EMAIL_META_KEY => 'billing@example.com',
				PayPalGateway::CONTACT_PHONE_META_KEY  => '5552220000',
				PayPalGateway::ORIGINAL_PHONE_META_KEY => '5551110000',
			),
			$this->contact_meta( $wc_order )
		);
		$this->assertCount( 1, $calls, 'Both contacts should be announced in one action' );
	}

	/**
	 * @testdox Should write nothing and announce nothing when $reason.
	 *
	 * @dataProvider data_contact_that_changes_nothing
	 *
	 * @param string      $reason        Why nothing is written.
	 * @param string      $billing_email The billing email of the order.
	 * @param string      $billing_phone The billing phone of the order.
	 * @param string|null $contact_email The contact email PayPal reports.
	 * @param string|null $contact_phone The contact phone PayPal reports.
	 * @param bool        $with_shipping Whether PayPal reports shipping information.
	 */
	public function test_contact_that_adds_nothing_to_the_order_is_not_kept(
		string $reason,
		string $billing_email,
		string $billing_phone,
		?string $contact_email,
		?string $contact_phone,
		bool $with_shipping
	): void {
		$wc_order = $this->wc_order_billed_to( $billing_email, $billing_phone );
		$calls    = $this->watch_contacts_added();

		$this->add_contact_details( $wc_order, $this->paypal_order_with_contact( $contact_email, $contact_phone, $with_shipping ) );

		$this->assertSame(
			array(
				PayPalGateway::CONTACT_EMAIL_META_KEY  => '',
				PayPalGateway::ORIGINAL_EMAIL_META_KEY => '',
				PayPalGateway::CONTACT_PHONE_META_KEY  => '',
				PayPalGateway::ORIGINAL_PHONE_META_KEY => '',
			),
			$this->contact_meta( $wc_order ),
			$reason
		);
		$this->assertCount( 0, $calls, $reason );
	}

	/**
	 * Contact details that leave the order as it is.
	 *
	 * @return array<string, array{string, string, string, string|null, string|null, bool}>
	 */
	public function data_contact_that_changes_nothing(): array {
		return array(
			'PayPal reports no shipping information' => array( 'PayPal reports no shipping information', 'billing@example.com', '5551110000', null, null, false ),
			'the contact email is the billing email' => array( 'the contact email is the billing email', 'billing@example.com', '', 'billing@example.com', null, true ),
			'the contact phone is the billing phone' => array( 'the contact phone is the billing phone', '', '5551110000', null, '5551110000', true ),
			'the contact email is not a valid email' => array( 'the contact email is not a valid email', 'billing@example.com', '', 'not-an-email', null, true ),
			'the order has no billing email'         => array( 'the order has no billing email', '', '', 'contact@example.com', null, true ),
			'the order has no billing phone'         => array( 'the order has no billing phone', '', '', null, '5552220000', true ),
		);
	}
}
