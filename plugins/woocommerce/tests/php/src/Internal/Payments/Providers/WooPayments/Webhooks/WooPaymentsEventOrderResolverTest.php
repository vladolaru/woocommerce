<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsEventOrderResolver class.
 *
 * Event objects carry the Stripe `metadata` the store attached at checkout: `order_id` and `order_key` on a
 * PaymentIntent and its charges (client 11.1.0 class-wc-payments-webhook-processing-service.php:968-1002;
 * https://docs.stripe.com/api/charges/object#charge_object-metadata).
 */
class WooPaymentsEventOrderResolverTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEventOrderResolver
	 */
	private WooPaymentsEventOrderResolver $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsEventOrderResolver();
	}

	/**
	 * @testdox A charge ID finds the order whose charge it is, and an empty or unknown charge ID finds none.
	 */
	public function test_finds_the_order_by_charge_id(): void {
		$order = $this->create_order( array( '_charge_id' => 'ch_resolved' ) );

		$this->assertSame( $order->get_id(), $this->sut->find_order_by_charge_id( 'ch_resolved' )->get_id() );
		$this->assertNull( $this->sut->find_order_by_charge_id( 'ch_unknown' ) );
		$this->assertNull( $this->sut->find_order_by_charge_id( '' ) );
	}

	/**
	 * @testdox A charge lookup given an event object whose metadata order key is $key_case returns the order: $expected.
	 * @testWith ["the order's key", true]
	 *           ["another key", false]
	 *           ["empty", true]
	 *
	 * @param string $key_case Which order key the event metadata carries.
	 * @param bool   $expected Whether the order is found.
	 */
	public function test_charge_lookup_checks_the_event_order_key( string $key_case, bool $expected ): void {
		$order        = $this->create_order( array( '_charge_id' => 'ch_keyed' ) );
		$keys         = array(
			"the order's key" => $order->get_order_key(),
			'another key'     => 'wc_order_another',
			'empty'           => '',
		);
		$event_object = array(
			'id'       => 'ch_keyed',
			'metadata' => array( 'order_key' => $keys[ $key_case ] ),
		);

		$found = $this->sut->find_order_by_charge_id( 'ch_keyed', $event_object );

		$this->assertSame( $expected ? $order->get_id() : null, $found instanceof WC_Order ? $found->get_id() : null );
	}

	/**
	 * @testdox A payment intent finds the order whose intent it is.
	 */
	public function test_intent_event_finds_the_order_by_intent_id(): void {
		$order = $this->create_order( array( '_intent_id' => 'pi_resolved' ) );

		$found = $this->sut->find_order_for_intent_event( array( 'id' => 'pi_resolved' ) );

		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @testdox A payment intent whose intent order has another key falls back to the order its metadata names.
	 */
	public function test_intent_event_falls_back_to_metadata_when_the_intent_order_key_differs(): void {
		$this->create_order( array( '_intent_id' => 'pi_shared' ) );
		$named = $this->create_order();

		$found = $this->sut->find_order_for_intent_event(
			array(
				'id'       => 'pi_shared',
				'metadata' => array(
					'order_id'  => (string) $named->get_id(),
					'order_key' => $named->get_order_key(),
				),
			)
		);

		$this->assertSame( $named->get_id(), $found->get_id() );
	}

	/**
	 * @testdox A payment intent with no intent order finds the order its $source metadata names.
	 * @testWith ["intent"]
	 *           ["first charge"]
	 *
	 * @param string $source Where the metadata order ID is.
	 */
	public function test_intent_event_finds_the_order_its_metadata_names( string $source ): void {
		$order          = $this->create_order();
		$payment_intent = array(
			'id'       => 'pi_unrecorded',
			'metadata' => array( 'order_key' => $order->get_order_key() ),
		);
		if ( 'intent' === $source ) {
			$payment_intent['metadata']['order_id'] = (string) $order->get_id();
		} else {
			$payment_intent['charges']['data'][0]['metadata']['order_id'] = (string) $order->get_id();
		}

		$found = $this->sut->find_order_for_intent_event( $payment_intent );

		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @testdox A payment intent finds no order when its metadata names an order with another key, or names none.
	 */
	public function test_intent_event_finds_no_order_for_another_key_or_no_order_id(): void {
		$order = $this->create_order();

		$this->assertNull(
			$this->sut->find_order_for_intent_event(
				array(
					'id'       => 'pi_other_site',
					'metadata' => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => 'wc_order_another',
					),
				)
			)
		);
		$this->assertNull(
			$this->sut->find_order_for_intent_event(
				array(
					'id'       => 'pi_invoice',
					'invoice'  => 'in_123',
					'metadata' => array(),
				)
			)
		);
	}

	/**
	 * @testdox An event with intent "$intent_id" and charge "$charge_id" is the own payment of a $status order (paid date: $has_paid_date, method $payment_method, transaction ID "$transaction_id", _intent_id "$order_intent_id"): $expected.
	 * @dataProvider own_payment_provider
	 *
	 * @param string $status          Order status.
	 * @param bool   $has_paid_date   Whether the order has a paid date.
	 * @param string $payment_method  Order payment method.
	 * @param string $transaction_id  Order transaction ID.
	 * @param string $order_intent_id Order `_intent_id`.
	 * @param string $intent_id       The event's payment intent ID.
	 * @param string $charge_id       The event's charge ID.
	 * @param bool   $expected        Whether the event is the order's own payment.
	 */
	public function test_tells_whether_an_event_is_the_orders_own_payment( string $status, bool $has_paid_date, string $payment_method, string $transaction_id, string $order_intent_id, string $intent_id, string $charge_id, bool $expected ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		$order->set_status( $status );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( '_intent_id', $order_intent_id );
		$order->set_date_paid( $has_paid_date ? time() : null );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $has_paid_date, null !== $order->get_date_paid(), 'The fixture must have the paid date the case names.' );

		$this->assertSame( $expected, $this->sut->is_own_payment( $order, $intent_id, $charge_id ) );
	}

	/**
	 * Order states and event IDs, with whether the event is the order's own payment.
	 *
	 * @return array<string,array{string,bool,string,string,string,string,string,bool}>
	 */
	public function own_payment_provider(): array {
		return array(
			'paid, transaction ID is the intent'   => array( 'processing', true, 'bacs', 'pi_a', '', 'pi_a', 'ch_a', true ),
			'paid, transaction ID is the charge'   => array( 'processing', true, 'bacs', 'ch_a', '', 'pi_a', 'ch_a', true ),
			'paid, another transaction ID'         => array( 'processing', true, 'woocommerce_payments', 'pi_b', 'pi_a', 'pi_a', 'ch_a', false ),
			'paid, no transaction ID'              => array( 'processing', true, 'woocommerce_payments', '', 'pi_a', 'pi_a', 'ch_a', false ),
			'paid, no IDs at all'                  => array( 'processing', true, 'woocommerce_payments', '', '', '', '', false ),
			'paid status without a paid date'      => array( 'processing', false, 'woocommerce_payments', 'pi_a', 'pi_b', 'pi_b', 'ch_b', false ),
			'refunded, its own intent'             => array( 'refunded', true, 'woocommerce_payments', 'pi_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'refunded, another intent'             => array( 'refunded', true, 'woocommerce_payments', 'pi_a', 'pi_b', 'pi_b', 'ch_b', false ),
			'dispute hold, another intent'         => array( 'on-hold', true, 'woocommerce_payments', 'pi_a', 'pi_b', 'pi_b', 'ch_b', false ),
			'unpaid, recorded intent, other label' => array( 'pending', false, 'bacs', 'pi_a', 'pi_b', 'pi_b', 'ch_b', true ),
			'unpaid, WooPayments label'            => array( 'pending', false, 'woocommerce_payments_bancontact', 'pi_a', 'pi_b', 'pi_c', 'ch_c', true ),
			'unpaid, other intent, other label'    => array( 'on-hold', false, 'bacs', '', 'pi_b', 'pi_c', 'ch_c', false ),
			'unpaid, no recorded intent'           => array( 'pending', false, 'bacs', '', '', 'pi_c', 'ch_c', false ),
			'unpaid, event without intent'         => array( 'pending', false, 'bacs', '', '', '', 'ch_c', false ),
		);
	}

	/**
	 * @testdox The event's payment intent is read from its payment_intent field as $field_case, else from the order's _intent_id.
	 * @testWith ["an ID", "pi_event", "pi_event"]
	 *           ["an expanded object", {"id": "pi_event"}, "pi_event"]
	 *           ["missing", null, "pi_order"]
	 *           ["empty", "", "pi_order"]
	 *
	 * The field is a Stripe expandable `payment_intent` (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 *
	 * @param string            $field_case     Field case.
	 * @param string|array|null $payment_intent The event's `payment_intent` field.
	 * @param string            $expected       Expected payment intent ID.
	 */
	public function test_reads_the_event_intent_id( string $field_case, $payment_intent, string $expected ): void {
		unset( $field_case );
		$order        = $this->create_order( array( '_intent_id' => 'pi_order' ) );
		$event_object = array( 'id' => 'ch_event' );
		if ( null !== $payment_intent ) {
			$event_object['payment_intent'] = $payment_intent;
		}

		$this->assertSame( $expected, $this->sut->get_event_intent_id( $event_object, $order ) );
	}

	/**
	 * Create an order with payment meta.
	 *
	 * @param array<string,string> $meta Order meta.
	 * @return WC_Order
	 */
	private function create_order( array $meta = array() ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		return $order;
	}
}
