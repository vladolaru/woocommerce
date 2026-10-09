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
