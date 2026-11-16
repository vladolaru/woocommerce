<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the EventOrderResolver class.
 *
 * Event objects carry the Stripe `metadata` the store attached at checkout: `order_id` and `order_key` on a
 * PaymentIntent and its charges (client 11.1.0 class-wc-payments-webhook-processing-service.php:968-1002;
 * https://docs.stripe.com/api/charges/object#charge_object-metadata).
 *
 * The intent lookup differs from client 11.1.0 in two places. When the order holding the intent fails the key check,
 * the order the metadata names is tried next, so an intent ID that another site's order holds does not hide this
 * site's order (the client returns no order, :1002-1012). The first charge is read from `charges.data[0]`, the list
 * shape the recorded webhook carries (`Fixtures/platform-3ds-challenge-results.json:63-65`) and the client's succeeded handler reads
 * (:500-502), where the client's order lookup reads `charges[0]` (:984-986).
 */
class EventOrderResolverTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var EventOrderResolver
	 */
	private EventOrderResolver $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new EventOrderResolver();
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
	 * @testdox A $lookup lookup given an event object whose metadata order key is $key_case returns the order: $expected.
	 * @testWith ["charge", "the order's key", true]
	 *           ["charge", "another key", false]
	 *           ["charge", "empty", true]
	 *           ["event intent", "the order's key", true]
	 *           ["event intent", "another key", false]
	 *           ["event intent", "empty", true]
	 *
	 * @param string $lookup   Lookup: by the charge ID, or by the event object's payment intent.
	 * @param string $key_case Which order key the event metadata carries.
	 * @param bool   $expected Whether the order is found.
	 */
	public function test_charge_and_event_intent_lookups_check_the_event_order_key( string $lookup, string $key_case, bool $expected ): void {
		$order        = $this->create_order(
			array(
				'_charge_id' => 'ch_keyed',
				'_intent_id' => 'pi_keyed',
			)
		);
		$keys         = array(
			"the order's key" => $order->get_order_key(),
			'another key'     => 'wc_order_another',
			'empty'           => '',
		);
		$event_object = array(
			'id'             => 'ch_keyed',
			'payment_intent' => 'pi_keyed',
			'metadata'       => array( 'order_key' => $keys[ $key_case ] ),
		);

		$found = 'charge' === $lookup ? $this->sut->find_order_by_charge_id( 'ch_keyed', $event_object ) : $this->sut->find_order_by_event_intent( $event_object );

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
	 * @testdox A $lookup lookup for $looked_up finds the order holding ch_Exact and pi_Exact only for the exact ID: $expected.
	 * @testWith ["charge", "ch_Exact", true]
	 *           ["charge", "ch_exact", false]
	 *           ["intent", "pi_Exact", true]
	 *           ["intent", "pi_exact", false]
	 *
	 * The meta lookup follows the table collation; core's download lookup requires an exact match of the stored value
	 * (WC_Customer_Download_Data_Store::get_downloads()).
	 *
	 * @param string $lookup    Which lookup runs: by charge ID or by the intent event's ID.
	 * @param string $looked_up The ID looked up.
	 * @param bool   $expected  Whether the order is found.
	 */
	public function test_payment_id_lookups_find_only_the_exact_stored_id( string $lookup, string $looked_up, bool $expected ): void {
		$order = $this->create_order(
			array(
				'_charge_id' => 'ch_Exact',
				'_intent_id' => 'pi_Exact',
			)
		);

		$found = 'charge' === $lookup ? $this->sut->find_order_by_charge_id( $looked_up ) : $this->sut->find_order_for_intent_event( array( 'id' => $looked_up ) );

		$this->assertSame( $expected ? $order->get_id() : null, $found instanceof WC_Order ? $found->get_id() : null );
	}

	/**
	 * @testdox A payment intent whose intent order has another key falls back to the order its metadata names.
	 *
	 * Client 11.1.0 returns no order here (class-wc-payments-webhook-processing-service.php:1002-1012); see the class docblock.
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
	 * The event's intent is also given as the stand-in for an empty transaction ID, as an intent event gives it; a set
	 * transaction ID and an unpaid order decide without it.
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
		$order = $this->create_order_in_state( $status, $has_paid_date, $payment_method, $transaction_id, array( '_intent_id' => $order_intent_id ) );

		$this->assertSame( $expected, $this->sut->is_own_payment( $order, $intent_id, $charge_id, $intent_id ) );
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
	 * @testdox A paid $status order (method $payment_method, empty transaction ID, _intent_id "$stored_intent_id", _charge_id "$stored_charge_id") owns an event standing in with intent "$stand_in_intent_id" and charge "$charge_id": $expected ($_dataName).
	 * @dataProvider stored_payment_provider
	 *
	 * On a paid order with an empty transaction ID, the stored WooPayments payment stands in for it: the payment method is a
	 * WooPayments gateway, the stand-in intent is the stored _intent_id, and a stored _charge_id is the event's charge. An
	 * empty value matches nothing. The client applies refunds and disputes to the order its _charge_id finds and intent
	 * events to the order its _intent_id finds, with none of the three checks (client 11.3.0
	 * class-wc-payments-webhook-processing-service.php:1135-1158, :1273-1282).
	 *
	 * @param string $status             Order status; the order has a paid date.
	 * @param string $payment_method     Order payment method.
	 * @param string $stored_intent_id   Order `_intent_id`, or '' for none.
	 * @param string $stored_charge_id   Order `_charge_id`, or '' for none.
	 * @param string $intent_id          The event's payment intent as the caller passes it, the stored one when the event names none.
	 * @param string $stand_in_intent_id The event's own payment intent, or '' when it may not stand in.
	 * @param string $charge_id          The event's charge ID.
	 * @param bool   $expected           Whether the event is the order's own payment.
	 */
	public function test_a_paid_order_without_transaction_id_owns_its_stored_payment( string $status, string $payment_method, string $stored_intent_id, string $stored_charge_id, string $intent_id, string $stand_in_intent_id, string $charge_id, bool $expected ): void {
		$order = $this->create_order_in_state(
			$status,
			true,
			$payment_method,
			'',
			array(
				'_intent_id' => $stored_intent_id,
				'_charge_id' => $stored_charge_id,
			)
		);

		$this->assertSame( $expected, $this->sut->is_own_payment( $order, $intent_id, $charge_id, $stand_in_intent_id ) );
	}

	/**
	 * Paid orders with an empty transaction ID and the event they meet, with whether the event is the order's own payment.
	 *
	 * @return array<string,array{string,string,string,string,string,string,string,bool}>
	 */
	public function stored_payment_provider(): array {
		$gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID;

		return array(
			'its stored payment and charge'                => array( OrderStatus::PROCESSING, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'paid, no transaction ID, no stored charge'    => array( OrderStatus::PROCESSING, $gateway_id, 'pi_a', '', 'pi_a', 'pi_a', 'ch_a', true ),
			'a split WooPayments gateway ID'               => array( OrderStatus::PROCESSING, 'woocommerce_payments_bancontact', 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'a paid date, on hold'                         => array( OrderStatus::ON_HOLD, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'a paid date, cancelled'                       => array( OrderStatus::CANCELLED, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'a paid date, refunded'                        => array( OrderStatus::REFUNDED, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'a paid date, pending'                         => array( OrderStatus::PENDING, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', true ),
			'paid another way'                             => array( OrderStatus::PROCESSING, 'cod', 'pi_a', 'ch_a', 'pi_a', 'pi_a', 'ch_a', false ),
			'an event without its own intent'              => array( OrderStatus::PROCESSING, $gateway_id, 'pi_a', 'ch_a', 'pi_a', '', 'ch_a', false ),
			'nothing stored'                               => array( OrderStatus::PROCESSING, $gateway_id, '', '', 'pi_a', 'pi_a', 'ch_a', false ),
			'nothing stored, an event without intent'      => array( OrderStatus::PROCESSING, $gateway_id, '', '', '', '', 'ch_a', false ),
			"another payment's charge beside another intent" => array( OrderStatus::PROCESSING, $gateway_id, 'pi_b', 'ch_c', 'pi_c', 'pi_c', 'ch_c', false ),
			"another payment's charge, no stored intent"   => array( OrderStatus::PROCESSING, $gateway_id, '', 'ch_c', 'pi_c', 'pi_c', 'ch_c', false ),
			'a stored charge that differs'                 => array( OrderStatus::PROCESSING, $gateway_id, 'pi_a', 'ch_old', 'pi_a', 'pi_a', 'ch_new', false ),
			'an event with no charge'                      => array( OrderStatus::PROCESSING, $gateway_id, 'pi_a', 'ch_a', 'pi_a', 'pi_a', '', false ),
			'marked paid by hand after its intent paid'    => array( OrderStatus::PROCESSING, $gateway_id, 'pi_e', '', 'pi_e', 'pi_e', 'ch_e', true ),
			'a copy or import carrying the stored payment' => array( OrderStatus::PROCESSING, $gateway_id, 'pi_x', 'ch_x', 'pi_x', 'pi_x', 'ch_x', true ),
			"a renewal copy carrying its parent's payment" => array( OrderStatus::PROCESSING, $gateway_id, 'pi_parent', 'ch_parent', 'pi_parent', 'pi_parent', 'ch_parent', true ),
		);
	}

	/**
	 * @testdox is_own_payment_for_event() reads the event's payment_intent field as $field_case: the stored payment stands in on an empty transaction ID ($owns_without_transaction_id), and the transaction ID compares with the event's intent or its stored fallback ($owns_with_transaction_id).
	 * @testWith ["an ID", "pi_a", true, true]
	 *           ["an expanded object", {"id": "pi_a"}, true, true]
	 *           ["missing", null, false, true]
	 *           ["empty", "", false, true]
	 *
	 * The refund, dispute and early fraud warning handlers pass the event object, so the stored intent the order's
	 * _intent_id gives when the event names none never stands in for an empty transaction ID, and still names the
	 * payment a set transaction ID is compared with. The field is a Stripe expandable `payment_intent`
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 *
	 * @param string            $field_case                  Field case.
	 * @param string|array|null $payment_intent              The event's `payment_intent` field.
	 * @param bool              $owns_without_transaction_id Expected on a paid order with an empty transaction ID storing pi_a and ch_a.
	 * @param bool              $owns_with_transaction_id    Expected on a paid order whose transaction ID and _intent_id are pi_a.
	 */
	public function test_own_payment_for_event_reads_the_event_intent( string $field_case, $payment_intent, bool $owns_without_transaction_id, bool $owns_with_transaction_id ): void {
		unset( $field_case );
		$stored       = array(
			'_intent_id' => 'pi_a',
			'_charge_id' => 'ch_a',
		);
		$event_object = array( 'id' => 'ch_a' );
		if ( null !== $payment_intent ) {
			$event_object['payment_intent'] = $payment_intent;
		}
		$without_transaction_id = $this->create_order_in_state( OrderStatus::PROCESSING, true, WooPaymentsPersistenceVocabulary::GATEWAY_ID, '', $stored );
		$with_transaction_id    = $this->create_order_in_state( OrderStatus::PROCESSING, true, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pi_a', $stored );

		$this->assertSame( $owns_without_transaction_id, $this->sut->is_own_payment_for_event( $without_transaction_id, $event_object, 'ch_a' ) );
		$this->assertSame( $owns_with_transaction_id, $this->sut->is_own_payment_for_event( $with_transaction_id, $event_object, 'ch_a' ) );
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
	 * Create an order with a status, a paid date or none, a payment method, a transaction ID and payment meta, read back.
	 *
	 * @param string               $status         Order status.
	 * @param bool                 $has_paid_date  Whether the order has a paid date.
	 * @param string               $payment_method Order payment method.
	 * @param string               $transaction_id Order transaction ID.
	 * @param array<string,string> $meta           Order meta; an empty value is not written.
	 * @return WC_Order
	 */
	private function create_order_in_state( string $status, bool $has_paid_date, string $payment_method, string $transaction_id, array $meta ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		$order->set_status( $status );
		$order->set_transaction_id( $transaction_id );
		foreach ( array_filter( $meta, static fn( string $value ): bool => '' !== $value ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->set_date_paid( $has_paid_date ? time() : null );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $has_paid_date, null !== $order->get_date_paid(), 'The fixture must have the paid date the case names.' );
		$this->assertSame( $status, $order->get_status(), 'The fixture must have the status the case names.' );

		return $order;
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
