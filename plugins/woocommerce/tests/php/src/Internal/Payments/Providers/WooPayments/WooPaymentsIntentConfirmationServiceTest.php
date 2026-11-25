<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentConfirmationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\SubscriptionPaymentIdsOnSave;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentConfirmationService class.
 */
class WooPaymentsIntentConfirmationServiceTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;
	use UnresolvedCheckoutChargeTestTrait;

	/**
	 * The recorded completed 3DS intent of REC-3DS-2 (`Fixtures/platform-3ds-challenge-results.json`, `classic_checkout_challenge_completed`).
	 */
	private const RECORDED_INTENT = 'pi_3UJjK4BzWlxcwgpP0xWpBE77';

	/**
	 * Its charge.
	 */
	private const RECORDED_CHARGE = 'ch_3UJjK4BzWlxcwgpP0EgZv2kl';

	/**
	 * @testdox Confirming an intent for an order fetches it once and saves its intent and payment method IDs on the order.
	 *
	 * The intent is REC-3DS-2's real completed
	 * challenge (`Fixtures/platform-3ds-challenge-results.json`, pair `classic_checkout_challenge_completed`):
	 * a classic checkout that finished a hosted 3DS challenge, obtained via one API-only GET of the
	 * resulting PaymentIntent through native transport after a real Playwright run. Its metadata names this order, as an
	 * intent created for the order does.
	 */
	public function test_confirm_intent_for_order_fetches_the_intent_once_and_saves_intent_and_payment_method_ids(): void {
		$order = $this->create_woopayments_order( '10.99' );

		$recorded              = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array_merge( $recorded['body'], array( 'metadata' => array( 'order_id' => (string) $order->get_id() ) ) ) ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$sut = $this->create_intent_confirmation_service( $api_client, null, $account_service );

		$sut->confirm_intent_for_order( $order, (string) $recorded['body']['id'], false );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $recorded['body']['id'], $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( $recorded['body']['payment_method'], $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertStringContainsString( 'intentions/' . rawurlencode( (string) $recorded['body']['id'] ), $http_client->last_path );
	}

	/**
	 * @testdox A card change updates the payment method row inserted while its SetupIntent was being fetched.
	 */
	public function test_card_change_updates_a_payment_method_inserted_during_confirmation(): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->read_meta_data( true );
		$intent             = $this->load_recorded_intent( 'platform-3ds-challenge-results.json', 'my_account_setup_intent_challenge_completed' );
		$intent['metadata'] = array( 'order_id' => (string) $order->get_id() );
		$api_client         = $this->createMock( Client::class );
		$api_client->expects( $this->once() )->method( 'get_setup_intention' )->with( $intent['id'] )->willReturnCallback(
			static function () use ( $order, $intent ): array {
				$other = new WC_Order( $order->get_id() );
				$other->update_meta_data( '_payment_method_id', 'pm_other' );
				$other->save_meta_data();
				return $intent;
			}
		);
		$sut = $this->create_intent_confirmation_service( $api_client, null, $this->create_account_service( false ) );

		// Later confirmation steps can repair a duplicate. Inspect the confirmation object's first save.
		$first_save_ids = null;
		add_action(
			'woocommerce_update_order',
			static function ( $order_id, $updated_order ) use ( $order, &$first_save_ids ): void {
				if ( $updated_order === $order && null === $first_save_ids ) {
					$stored = new WC_Order( $order_id );
					$stored->read_meta_data( true );
					$first_save_ids = array_values( wp_list_pluck( $stored->get_meta( '_payment_method_id', false ), 'value' ) );
				}
			},
			1,
			2
		);

		$sut->confirm_intent_for_order( $order, $intent['id'], false, true );

		$this->assertSame( array( $intent['payment_method'] ), $first_save_ids );
	}

	/**
	 * @testdox Confirming a paid intent for an order reduces its stock once.
	 *
	 * Client 11.3.0 leaves stock reduction to WooCommerce core on the order-status callback
	 * (class-wc-payment-gateway-wcpay.php:4775, :2520-2523). The intent is the same recording as the test above.
	 */
	public function test_confirm_intent_for_order_reduces_stock_once(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
		$order = $this->create_woopayments_order( '10.99' );
		$order->add_product( $product, 3 );
		$order->set_total( '10.99' );
		$order->save();

		$recorded              = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( array_merge( $recorded['body'], array( 'metadata' => array( 'order_id' => (string) $order->get_id() ) ) ) ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$sut = $this->create_intent_confirmation_service( $api_client, null, $account_service );

		$sut->confirm_intent_for_order( $order, (string) $recorded['body']['id'], false );

		$this->assertSame( $recorded['body']['id'], wc_get_order( $order->get_id() )->get_meta( '_intent_id', true ), 'The confirm applied the intent.' );
		$this->assertSame( 7, wc_get_product( $product->get_id() )->get_stock_quantity() );
	}

	/**
	 * @testdox Saving the payment method keeps the order's saved customer when the intent's customer is not a string, and the related subscription takes that customer at its next save.
	 *
	 * A `wcpay_api_request_response` callback can hand back any value for `customer`; a coerced `true` must not replace the
	 * order's valid customer ID on the order, from which the subscription takes it when saved.
	 */
	public function test_saving_the_payment_method_ignores_a_non_string_intent_customer(): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->set_customer_id( self::factory()->user->create() );
		$order->update_meta_data( '_stripe_customer_id', 'cus_saved' );
		$order->save();
		$subscription = $this->create_woopayments_order( '10.99' );
		$subscription->set_parent_id( $order->get_id() );
		$subscription->save();

		$intent             = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' )['body'];
		$intent['customer'] = true;
		$token_service      = $this->create_token_service_with_saved_token_and_subscription( $subscription );
		$sut                = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), $token_service, $this->create_account_service( false ) );

		$sut->confirm_fetched_intent_for_order( $order, $intent, true );

		$this->assertSame( '', wc_get_order( $subscription->get_id() )->get_meta( '_stripe_customer_id', true ), 'The payment writes no customer on the subscription.' );
		$this->assertSame( 'cus_saved', wc_get_order( $order->get_id() )->get_meta( '_stripe_customer_id', true ) );

		SubscriptionPaymentIdsOnSave::register( $this );
		wc_get_order( $subscription->get_id() )->save();

		$this->assertSame( 'cus_saved', wc_get_order( $subscription->get_id() )->get_meta( '_stripe_customer_id', true ), "The subscription's next save gives it the order's valid customer, never the intent's." );
	}

	/**
	 * @testdox The $caller attach leaves the stored charge $expected_charge and the risk level $expected_risk_level.
	 *
	 * The order stores an earlier attempt pi_earlier, declined or succeeded, with its charge ch_earlier and that charge's risk
	 * level. Client
	 * 11.3.0's attach sets the charge to the attached intent's latest charge, null for an intent with none, which deletes it
	 * (class-wc-payments-order-service.php:1829-1830, :1873-1884, :1137-1141), and skips a null risk level
	 * (:1833-1834, :1168-1171); a stored succeeded status without the override returns before any write (:1818-1822). The
	 * intents are recorded platform responses: REC-3DS-1's new-card requires_action
	 * (Fixtures/platform-intention-3ds-requires-action.json) and REC-3DS-2's failed and completed challenges and the My
	 * Account SetupIntent (Fixtures/platform-3ds-challenge-results.json).
	 *
	 * @dataProvider provider_attaches_and_the_stored_charge
	 *
	 * @param string   $caller              The attach's caller.
	 * @param string   $stored_status       Stored intent status of the earlier attempt: requires_payment_method or succeeded.
	 * @param callable $attach              Attaches the recorded intent to the order through the caller.
	 * @param string   $expected_charge     Stored charge after the attach; 'removed' for none.
	 * @param string   $expected_intent     Stored intent after the attach: 'attached' or 'earlier'.
	 * @param string   $expected_risk_level Stored risk level after the attach.
	 */
	public function test_an_attach_keeps_only_the_attached_attempts_charge( string $caller, string $stored_status, callable $attach, string $expected_charge, string $expected_intent, string $expected_risk_level ): void {
		unset( $caller );
		$order = 'succeeded' === $stored_status
			? StoredPaymentRecords::payment( OrderStatus::PENDING, 'pi_earlier', 'ch_earlier', 'elevated' )
			: StoredPaymentRecords::declined_attempt_with_charge( 'elevated' );

		$intent_id = $attach( $this, $order );
		$reloaded  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'removed' === $expected_charge ? '' : $expected_charge, $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( 'attached' === $expected_intent ? $intent_id : 'pi_earlier', $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( 'attached' === $expected_intent ? $intent_id : 'pi_earlier', $reloaded->get_transaction_id() );
		$this->assertSame( $expected_risk_level, $reloaded->get_meta( '_charge_risk_level', true ) );
	}

	/**
	 * Attaches of a recorded intent through each caller, onto an order storing an earlier attempt's charge.
	 *
	 * @return array<string,array{0:string,1:string,2:callable,3:string,4:string,5:string}>
	 */
	public function provider_attaches_and_the_stored_charge(): array {
		$checkout        = static function ( string $fixture, string $pair, string $outcome_status, bool $payment_method_change ): callable {
			return static function ( self $test, WC_Order $order ) use ( $fixture, $pair, $outcome_status, $payment_method_change ): string {
				$intent  = $test->load_recorded_intent( $fixture, $pair );
				$plan    = 0 === strpos( (string) $intent['id'], 'seti_' )
					? WooPaymentsOrderEffectPlan::for_setup_intent( $intent, false, array( '_wcpay_intent_currency' => 'USD' ) )
					: WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false );
				$context = PaymentOperationContext::for_checkout(
					$order,
					WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					(string) $intent['payment_method'],
					array(),
					$payment_method_change ? array( WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true ) : array()
				);
				$test->create_effect_applier( $test->create_account_service( true ) )->attach_charge_intent(
					$context,
					( new PaymentOutcome( $outcome_status, (string) $intent['id'], '', (string) $intent['payment_method'] ) )->with_effect_plan( $plan )
				);

				return (string) $intent['id'];
			};
		};
		$redirect_return = static function ( self $test, WC_Order $order ): string {
			$intent             = $test->load_recorded_intent( 'platform-3ds-challenge-results.json', 'blocks_challenge_failed' );
			$intent['metadata'] = array( 'order_id' => (string) $order->get_id() );
			// The redirect return confirms only the intent the order stores, so checkout stored this attempt first.
			$order->update_meta_data( '_intent_id', (string) $intent['id'] );
			$order->save();
			$account_service = $test->create_account_service( true );
			$test->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $account_service )
				->confirm_fetched_intent_for_order( $order, $intent, false, true );

			return (string) $intent['id'];
		};

		return array(
			'checkout of a 3DS attempt'                  => array(
				'checkout',
				'requires_payment_method',
				$checkout( 'platform-intention-3ds-requires-action.json', 'new_card_requires_action', PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, false ),
				'removed',
				'attached',
				'elevated',
			),
			'redirect return of a failed 3DS challenge'  => array( 'redirect return', 'requires_payment_method', $redirect_return, 'removed', 'attached', 'elevated' ),
			'checkout over a stored success'             => array(
				'checkout',
				'succeeded',
				$checkout( 'platform-intention-3ds-requires-action.json', 'new_card_requires_action', PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, false ),
				'ch_earlier',
				'earlier',
				'elevated',
			),
			'payment method change with a SetupIntent'   => array(
				'subscription payment method change',
				'succeeded',
				$checkout( 'platform-3ds-challenge-results.json', 'my_account_setup_intent_challenge_completed', PaymentOutcome::STATUS_COMPLETED, true ),
				'removed',
				'attached',
				'elevated',
			),
			'checkout of an attempt that has its charge' => array(
				'checkout',
				'requires_payment_method',
				$checkout( 'platform-3ds-challenge-results.json', 'classic_checkout_challenge_completed', PaymentOutcome::STATUS_COMPLETED, false ),
				'ch_3UJjK4BzWlxcwgpP0EgZv2kl',
				'attached',
				'normal',
			),
		);
	}

	/**
	 * @testdox U-O1: an own payment on an order marked paid by hand is attached by the callback, with its display details once under the claim and the paid-order note; its webhook then adds no second note.
	 *
	 * The callback asks the webhook's own-payment test (EventOrderResolver::is_own_payment()): the order's transaction ID
	 * names the payment, so it is the order's own and the callback goes on as client 11.3.0's does
	 * (class-wc-payment-gateway-wcpay.php:4663, :4771). The intent is REC-3DS-2's completed challenge.
	 */
	public function test_callback_attaches_an_own_payment_on_a_paid_order_with_the_paid_order_note(): void {
		$order           = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, self::RECORDED_INTENT, self::RECORDED_INTENT );
		$intent          = $this->own_rule_intent( $order );
		$displays        = array();
		$account_service = $this->create_account_service( false );
		$token_service   = $this->create_token_service();
		$sut             = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), $token_service, $account_service );
		$this->replace_effect_applier(
			$sut,
			$account_service,
			$token_service,
			function ( WC_Order $displayed_order ) use ( &$displays ): void {
				$displays[] = $this->is_order_payment_lock_held_for( $displayed_order, new WooPaymentsPersistenceVocabulary(), self::RECORDED_INTENT );
			}
		);
		$logger = RecordingWcLogger::install();

		$sut->confirm_fetched_intent_for_order( $order, $intent, false, false, true );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( OrderStatus::PROCESSING, $reloaded->get_status() );
		$this->assertSame( self::RECORDED_INTENT, $reloaded->get_transaction_id() );
		$this->assertSame( self::RECORDED_CHARGE, $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $reloaded->get_meta( '_intention_status', true ) );
		$this->assertSame( '3220', $reloaded->get_meta( 'last4', true ) );
		$this->assertSame( array( true ), $displays, 'The display details are written once, under the claim.' );
		$paid_notes = $this->get_paid_order_notes( $reloaded );
		$this->assertCount( 1, $paid_notes );
		$this->assertStringContainsString( 'transaction ID names that payment', $paid_notes[0] );
		$this->assertCount( 0, $this->get_not_own_lines( $logger ) );

		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'payment_intent.succeeded', $intent );

		$this->assertCount( 1, $this->get_paid_order_notes( $reloaded ), 'The webhook finds the note by its identity.' );
	}

	/**
	 * @testdox U-O2: a payment over a reference the merchant typed on a paid order is left alone on $entry: nothing attached, no event, no display details, one line; its webhook then records it as another charge.
	 *
	 * The webhook's own-payment test calls it another charge (EventOrderResolver::is_own_payment(): a paid order's transaction
	 * ID names neither the intent nor its charge). Client 11.3.0's callback attaches it over the reference
	 * (class-wc-payment-gateway-wcpay.php:4663).
	 *
	 * @testWith ["callback"]
	 *           ["redirect return"]
	 *
	 * @param string $entry The entry point.
	 */
	public function test_confirmation_leaves_a_payment_over_a_typed_reference_alone( string $entry ): void {
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'bank ref 1', self::RECORDED_INTENT );
		$order->set_payment_method_title( 'Bank transfer' );
		$order->save();
		$intent = $this->own_rule_intent( $order );
		$logger = RecordingWcLogger::install();
		$notes  = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$this->confirm_through( $entry, $order, $intent );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'bank ref 1', $reloaded->get_transaction_id() );
		$this->assertSame( self::RECORDED_INTENT, $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $reloaded->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( 'last4', true ), 'No display details, under the claim or after it.' );
		$this->assertSame( 'Bank transfer', $reloaded->get_payment_method_title() );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'No event.' );
		$lines = $this->get_not_own_lines( $logger );
		$this->assertCount( 1, $lines );
		$this->assertSame( $order->get_id(), $lines[0]['order_id'] ?? null );
		$this->assertSame( self::RECORDED_INTENT, $lines[0]['intent_id'] ?? null );
		$this->assertSame( OrderStatus::PROCESSING, $lines[0]['order_status'] ?? null );
		$this->assertSame( 'callback' === $entry ? 'order-status callback' : 'redirect return', $lines[0]['entry_point'] ?? null );
		$this->assertStringNotContainsString( 'bank ref 1', implode( ' ', array_column( $logger->lines, 1 ) ), 'The typed value is not in a logged message.' );
		$this->assertStringNotContainsString( 'bank ref 1', (string) wp_json_encode( $logger->contexts ), 'The typed value is not in a logged context.' );

		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'payment_intent.succeeded', $intent );

		$this->assertCount( 1, array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( string $note ): bool => false !== strpos( $note, 'does not pay this order' ) ) );
	}

	/**
	 * @testdox U-O3: on a paid order whose transaction ID was cleared, $_dataName.
	 *
	 * The stand-in is the webhook's: a succeeded payment intent stands in for the empty transaction ID on an order in a paid
	 * status, when the order stores no charge or this payment's first charge (EventOrderResolver::is_own_payment()).
	 *
	 * @dataProvider cleared_transaction_ids
	 *
	 * @param string $status        Order status.
	 * @param string $stored_charge The order's stored charge.
	 * @param bool   $second_entry  Whether the intent carries a second charge entry after its first.
	 * @param bool   $own           Whether the payment is the order's own.
	 */
	public function test_callback_on_a_cleared_transaction_id_follows_the_webhooks_stand_in( string $status, string $stored_charge, bool $second_entry, bool $own ): void {
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, '', self::RECORDED_INTENT );
		$order->set_status( $status );
		$order->update_meta_data( '_charge_id', $stored_charge );
		$order->save();
		$this->assertNotNull( wc_get_order( $order->get_id() )->get_date_paid(), 'The order keeps its paid date.' );
		$intent = $this->own_rule_intent( $order );
		if ( $second_entry ) {
			$intent['charges']['data'][] = array( 'id' => 'ch_second' );
		}
		$logger = RecordingWcLogger::install();

		$this->confirm_through( 'callback', $order, $intent );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $own ? self::RECORDED_INTENT : '', $reloaded->get_transaction_id() );
		$this->assertSame( $own ? 'succeeded' : 'requires_action', $reloaded->get_meta( '_intention_status', true ) );
		$this->assertCount( $own ? 0 : 1, $this->get_not_own_lines( $logger ) );
		$this->assertCount( $own && OrderStatus::PROCESSING === $status ? 1 : 0, $this->get_paid_order_notes( $reloaded ) );
	}

	/**
	 * Cleared transaction IDs, with whether the payment is the order's own.
	 *
	 * @return array<string,array{string,string,bool,bool}>
	 */
	public function cleared_transaction_ids(): array {
		return array(
			'no stored charge: own, the attach fills the transaction ID' => array( OrderStatus::PROCESSING, '', false, true ),
			"the payment's first charge stored, on a two-charge intent: own" => array( OrderStatus::PROCESSING, self::RECORDED_CHARGE, true, true ),
			"another payment's charge stored: not own" => array( OrderStatus::PROCESSING, 'ch_other', false, false ),
			'Pending with a paid date: not own, no stand-in off a paid status' => array( OrderStatus::PENDING, '', false, false ),
		);
	}

	/**
	 * @testdox U-O4: with manual capture, an authorization on an order $_dataName.
	 *
	 * The authorization's webhook writes nothing outside Pending and Failed (PaymentIntentEventHandler), so the callback is
	 * where it is recorded when it is the order's own; an authorization never stands in for an empty transaction ID.
	 *
	 * @dataProvider authorizations_on_orders_set_by_hand
	 *
	 * @param string $status         Order status the merchant set.
	 * @param string $transaction_id The order's transaction ID: `intent` for the attempt's, or ''.
	 * @param bool   $own            Whether the authorization is the order's own.
	 */
	public function test_callback_records_an_own_authorization_on_an_order_set_by_hand( string $status, string $transaction_id, bool $own ): void {
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'intent' === $transaction_id ? self::RECORDED_INTENT : '', self::RECORDED_INTENT );
		$order->set_status( $status );
		$order->save();
		$intent = $this->own_rule_intent( $order, 'requires_capture' );
		$logger = RecordingWcLogger::install();

		$this->confirm_through( 'callback', $order, $intent );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $status, $reloaded->get_status() );
		$this->assertSame( $own ? 'requires_capture' : 'requires_action', $reloaded->get_meta( '_intention_status', true ), 'Capture and cancel read the recorded authorization.' );
		$this->assertSame( $own ? self::RECORDED_CHARGE : '', $reloaded->get_meta( '_charge_id', true ) );
		$this->assertCount( $own ? 0 : 1, $this->get_not_own_lines( $logger ) );
		$this->assertCount( 0, $this->get_paid_order_notes( $reloaded ), 'An authorization gets no paid-order note.' );
	}

	/**
	 * Orders set by hand during the challenge, with whether the authorization is their own.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function authorizations_on_orders_set_by_hand(): array {
		return array(
			'On hold, the transaction ID untouched: recorded'                 => array( OrderStatus::ON_HOLD, 'intent', true ),
			'Processing, the transaction ID untouched: recorded, skipped'     => array( OrderStatus::PROCESSING, 'intent', true ),
			'Processing, the transaction ID cleared: not own, no stand-in'    => array( OrderStatus::PROCESSING, '', false ),
		);
	}

	/**
	 * @testdox U-O5: a $0 order's setup intent on $_dataName.
	 *
	 * No webhook handles a setup intent, so the confirmation is its only writer; it never stands in for an empty transaction
	 * ID. Client 11.3.0 records its payment method and completes an unpaid order (class-wc-payment-gateway-wcpay.php:4665-4715).
	 *
	 * @dataProvider setup_intents_on_orders_set_by_hand
	 *
	 * @param string $entry          The entry point.
	 * @param string $status         Order status.
	 * @param string $transaction_id The order's transaction ID.
	 * @param bool   $own            Whether the setup intent is the order's own.
	 */
	public function test_confirmation_of_a_setup_intent_follows_the_own_payment_test( string $entry, string $status, string $transaction_id, bool $own ): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_held' );
		$order->set_transaction_id( $transaction_id );
		$order->set_status( $status );
		$order->save();
		$intent = array(
			'id'             => 'seti_held',
			'object'         => 'setup_intent',
			'status'         => 'succeeded',
			'customer'       => 'cus_held',
			'payment_method' => 'pm_held',
			'metadata'       => array( 'order_id' => (string) $order->get_id() ),
		);
		$logger = RecordingWcLogger::install();

		$this->confirm_through( $entry, $order, $intent );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		if ( $own ) {
			$this->assertTrue( $reloaded->has_status( wc_get_is_paid_statuses() ), 'An own setup intent completes the On hold order.' );
		} else {
			$this->assertSame( $status, $reloaded->get_status() );
		}
		if ( 'callback' === $entry ) {
			$this->assertSame( $own ? 'pm_held' : '', $reloaded->get_meta( '_payment_method_id', true ), 'The callback saves the payment method of its own setup intent.' );
		} else {
			$this->assertSame( $own ? 'seti_held' : $transaction_id, $reloaded->get_transaction_id(), 'The redirect return attaches its own setup intent.' );
		}
		$this->assertCount( $own ? 0 : 1, $this->get_not_own_lines( $logger ) );
	}

	/**
	 * Setup intents on orders set by hand, with whether each is the order's own.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public function setup_intents_on_orders_set_by_hand(): array {
		return array(
			'the callback, an order set On hold' => array( 'callback', OrderStatus::ON_HOLD, '', true ),
			"the redirect return's race, an order set On hold" => array( 'redirect return', OrderStatus::ON_HOLD, '', true ),
			'the callback, a paid order with a typed reference' => array( 'callback', OrderStatus::PROCESSING, 'bank ref 5', false ),
			'the callback, a paid order with no transaction ID: a setup intent never stands in' => array( 'callback', OrderStatus::PROCESSING, '', false ),
		);
	}

	/**
	 * @testdox U-O6: on a paid status a plugin adds, a payment the transaction ID names is attached with the paid-order note, and one over a typed reference is left alone: $_dataName.
	 *
	 * @testWith ["the transaction ID names the payment", "intent", true]
	 *           ["a typed reference", "bank ref 6", false]
	 *
	 * @param string $label          Row label.
	 * @param string $transaction_id The order's transaction ID: `intent` for the payment's.
	 * @param bool   $own            Whether the payment is the order's own.
	 */
	public function test_callback_on_a_plugins_paid_status_follows_the_own_payment_test( string $label, string $transaction_id, bool $own ): void {
		unset( $label );
		add_filter( 'wc_order_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'wc-shipped' => 'Shipped' ) ) );
		add_filter( 'woocommerce_order_is_paid_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'shipped' ) ) );
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'intent' === $transaction_id ? self::RECORDED_INTENT : $transaction_id, self::RECORDED_INTENT );
		$order->set_status( 'shipped' );
		$order->save();
		$logger = RecordingWcLogger::install();

		$this->confirm_through( 'callback', $order, $this->own_rule_intent( $order ) );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'shipped', $reloaded->get_status() );
		$this->assertSame( $own ? self::RECORDED_INTENT : $transaction_id, $reloaded->get_transaction_id() );
		$this->assertCount( $own ? 1 : 0, $this->get_paid_order_notes( $reloaded ) );
		$this->assertCount( $own ? 0 : 1, $this->get_not_own_lines( $logger ) );
	}

	/**
	 * @testdox U-O7: when another operation holds the order payment lock, the fallback writes the charge details of an own payment and nothing for one over a typed reference: $_dataName.
	 *
	 * @testWith ["own, the transaction ID untouched", "intent", true]
	 *           ["not own, a typed reference", "bank ref 7", false]
	 *
	 * @param string $label          Row label.
	 * @param string $transaction_id The order's transaction ID: `intent` for the payment's.
	 * @param bool   $own            Whether the payment is the order's own.
	 */
	public function test_claim_refused_fallback_follows_the_own_payment_test( string $label, string $transaction_id, bool $own ): void {
		unset( $label );
		$order      = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'intent' === $transaction_id ? self::RECORDED_INTENT : $transaction_id, self::RECORDED_INTENT );
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $lock->claim( $order, $vocabulary, 'pi_another_operation', 'test' );
		$this->assertNotNull( $token );
		$logger = RecordingWcLogger::install();

		try {
			$this->confirm_through( 'callback', $order, $this->own_rule_intent( $order ) );
		} finally {
			$lock->release( $order, $vocabulary, (string) $token );
		}

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $own ? self::RECORDED_CHARGE : '', $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( $own, '' !== $reloaded->get_meta( '_charge_risk_level', true ) );
		$this->assertSame( $own ? '3220' : '', $reloaded->get_meta( 'last4', true ), 'Display details after the claim only for an own payment.' );
		$this->assertCount( $own ? 0 : 1, $this->get_not_own_lines( $logger ) );
	}

	/**
	 * @testdox U-O8: the callback after its webhook recorded the payment, $_dataName, is the order's own: no paid-order note and no line.
	 *
	 * @testWith ["a payment (automatic capture)", "succeeded"]
	 *           ["an authorization (manual capture)", "requires_capture"]
	 *
	 * @param string $label         Row label.
	 * @param string $intent_status The intent's status.
	 */
	public function test_callback_after_its_webhook_is_own_and_quiet( string $label, string $intent_status ): void {
		unset( $label );
		$order   = 'succeeded' === $intent_status
			? StoredPaymentRecords::payment( OrderStatus::PROCESSING, self::RECORDED_INTENT, self::RECORDED_CHARGE )
			: StoredPaymentRecords::authorization( self::RECORDED_INTENT, self::RECORDED_CHARGE );
		$written = new PaymentLifecycleEvent( 'succeeded' === $intent_status ? PaymentLifecycleEvent::STATUS_COMPLETED : PaymentLifecycleEvent::STATUS_AUTHORIZED, self::RECORDED_INTENT, array(), array(), 'Recorded by the webhook.', 'succeeded' === $intent_status ? PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS : PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED );
		$order->add_order_note( 'Recorded by the webhook.', 0, false, array( WooPaymentsPersistenceVocabulary::NOTE_IDENTITY_META_KEY => hash( 'sha256', $written->get_note_identity() ) ) );
		$status = $order->get_status();
		$logger = RecordingWcLogger::install();

		$this->confirm_through( 'callback', $order, $this->own_rule_intent( $order, $intent_status ) );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $status, $reloaded->get_status() );
		$this->assertSame( self::RECORDED_INTENT, $reloaded->get_transaction_id() );
		$this->assertSame( '3220', $reloaded->get_meta( 'last4', true ) );
		$this->assertCount( 0, $this->get_paid_order_notes( $reloaded ) );
		$this->assertCount( 0, $this->get_not_own_lines( $logger ) );
	}

	/**
	 * @testdox U-O9: the order-status callback on a Pending order attaches the intent and completes the order.
	 */
	public function test_callback_on_a_pending_order_attaches_and_applies(): void {
		$intent = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' )['body'];
		$order  = $this->create_woopayments_order( '10.99' );
		$order->set_transaction_id( (string) $intent['id'] );
		$order->update_meta_data( '_intent_id', (string) $intent['id'] );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->save();
		$logger = RecordingWcLogger::install();

		$this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false ) )
			->confirm_fetched_intent_for_order( $order, $intent, false );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( 'succeeded', $reloaded->get_meta( '_intention_status', true ) );
		$this->assertSame( self::RECORDED_CHARGE, $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( '3220', $reloaded->get_meta( 'last4', true ) );
		$this->assertCount( 1, array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( string $note ): bool => false !== strpos( $note, 'successfully charged' ) ) );
		$this->assertCount( 0, $this->get_not_own_lines( $logger ) );
	}

	/**
	 * @testdox The completed-order email and the saved order show the payment method title for a one-off payment.
	 *
	 * @dataProvider tokenless_payment_title_data
	 * @param string              $country        Account country.
	 * @param array<string,mixed> $details        Charge payment method details.
	 * @param string              $express_marker Express type already stored on the order.
	 * @param string              $expected_title Title at the first customer notification.
	 */
	public function test_tokenless_wallet_title_at_customer_notification( string $country, array $details, string $express_marker, string $expected_title ): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, self::RECORDED_INTENT );
		if ( '' !== $express_marker ) {
			$order->update_meta_data( WooPaymentsPersistenceVocabulary::EXPRESS_CHECKOUT_PAYMENT_METHOD_META_KEY, $express_marker );
		}
		$order->save();
		$intent = $this->own_rule_intent( $order );
		if ( $details ) {
			$intent['charges']['data'][0]['payment_method_details'] = $details;
		} else {
			unset( $intent['charges']['data'][0]['payment_method_details'] );
		}

		$email_titles = array();
		WC()->mailer();
		add_action(
			'woocommerce_order_status_completed_notification',
			static function ( $order_id ) use ( &$email_titles ) {
				$email_titles[] = wc_get_order( $order_id )->get_payment_method_title();
			}
		);
		$this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false, $country ) )
			->confirm_fetched_intent_for_order( $order, $intent, false );

		$this->assertSame( array( $expected_title ), $email_titles );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( $expected_title, $reloaded->get_payment_method_title() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $reloaded->get_payment_method() );
		$this->assertEmpty( $reloaded->get_payment_tokens() );
	}

	/**
	 * Charge details and any express marker retained from checkout.
	 *
	 * @return array<string,array{string,array<string,mixed>,string,string}>
	 */
	public static function tokenless_payment_title_data(): array {
		$card = array(
			'type' => WooPaymentsPaymentMethodType::CARD,
			'card' => array(
				'network' => 'visa',
				'funding' => 'credit',
				'last4'   => '4242',
			),
		);

		$link_wallet                   = $card;
		$link_wallet['card']['wallet'] = array( 'type' => WooPaymentsPaymentMethodType::LINK );
		$apple_pay                     = $card;
		$apple_pay['card']['wallet']   = array( 'type' => WooPaymentsPaymentMethodType::APPLE_PAY );
		$google_pay                    = $card;
		$google_pay['card']['wallet']  = array( 'type' => WooPaymentsPaymentMethodType::GOOGLE_PAY );

		return array(
			'US Link wallet'              => array( 'US', $link_wallet, '', 'Link' ),
			'GB Link wallet'              => array( 'GB', $link_wallet, '', 'Onelink' ),
			'top-level Link'              => array( 'GB', array( 'type' => WooPaymentsPaymentMethodType::LINK ), '', 'Onelink' ),
			'Apple Pay wallet'            => array( 'GB', $apple_pay, '', 'Apple Pay (WooPayments)' ),
			'plain card after Link'       => array( 'GB', $card, WooPaymentsPaymentMethodType::LINK, 'Visa credit card' ),
			'Link marker without details' => array( 'US', array(), WooPaymentsPaymentMethodType::LINK, 'Link' ),
			'Apple Pay marker on card'    => array( 'GB', $card, WooPaymentsPaymentMethodType::APPLE_PAY, 'Apple Pay (WooPayments)' ),
			'Google Pay with marker'      => array( 'GB', $google_pay, WooPaymentsPaymentMethodType::GOOGLE_PAY, 'Google Pay (WooPayments)' ),
		);
	}

	/**
	 * @testdox A $intent_status intent confirmed by the $entry leaves the order $order_status and preserves the unresolved checkout charge.
	 *
	 * @testWith ["3DS return", "succeeded", "completed"]
	 *           ["redirect return", "succeeded", "completed"]
	 *           ["3DS return", "requires_capture", "on-hold"]
	 *           ["redirect return", "requires_capture", "on-hold"]
	 *
	 * @param string $entry         `3DS return` or `redirect return`.
	 * @param string $intent_status Intent status the return reads.
	 * @param string $order_status  Order status after the confirmation.
	 */
	public function test_a_confirmed_payment_preserves_the_unresolved_checkout_charge( string $entry, string $intent_status, string $order_status ): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->set_transaction_id( self::RECORDED_INTENT );
		$order->update_meta_data( '_intent_id', self::RECORDED_INTENT );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$expected = $this->store_unresolved_checkout_charge( $order );
		$sut      = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false ) );

		$sut->confirm_fetched_intent_for_order( $order, $this->own_rule_intent( $order, $intent_status ), false, 'redirect return' === $entry );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $order_status, $reloaded->get_status() );
		$this->assert_unresolved_checkout_charge( $expected, $reloaded );
	}

	/**
	 * @testdox Applying an attached intent pays the order and preserves the unresolved checkout charge.
	 */
	public function test_attached_payment_preserves_the_unresolved_checkout_charge(): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->update_meta_data( '_intent_id', self::RECORDED_INTENT );
		$expected = $this->store_unresolved_checkout_charge( $order );
		$sut      = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false ) );

		$sut->apply_attached_payment_intent( $order, $this->own_rule_intent( $order ) );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'completed', $reloaded->get_status() );
		$this->assert_unresolved_checkout_charge( $expected, $reloaded );
	}

	/**
	 * The recorded completed 3DS intent, with its status and metadata naming the order.
	 *
	 * @param WC_Order $order  Order the intent pays.
	 * @param string   $status Intent status.
	 * @return array<string,mixed>
	 */
	private function own_rule_intent( WC_Order $order, string $status = 'succeeded' ): array {
		$intent             = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' )['body'];
		$intent['status']   = $status;
		$intent['metadata'] = array(
			'order_id'  => (string) $order->get_id(),
			'order_key' => $order->get_order_key(),
		);

		return $intent;
	}

	/**
	 * Confirm an intent through an entry point, as the order-status callback or the redirect return calls the service.
	 *
	 * @param string              $entry  `callback` or `redirect return`.
	 * @param WC_Order            $order  Order.
	 * @param array<string,mixed> $intent Intent.
	 */
	private function confirm_through( string $entry, WC_Order $order, array $intent ): void {
		$sut = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false ) );
		if ( 'callback' === $entry ) {
			$sut->confirm_fetched_intent_for_order( $order, $intent, false, false, true );
		} else {
			$sut->confirm_fetched_intent_for_order( $order, $intent, false, true );
		}
	}

	/**
	 * The contexts of the lines of a payment the confirmation left alone.
	 *
	 * @param RecordingWcLogger $logger Logger.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_not_own_lines( RecordingWcLogger $logger ): array {
		return array_values( array_filter( $logger->contexts, static fn( array $context ): bool => 'confirmation_left_payment_not_own' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * The order's paid-order notes.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function get_paid_order_notes( WC_Order $order ): array {
		return array_values( array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( string $note ): bool => false !== strpos( $note, 'already had a paid status' ) ) );
	}

	/**
	 * Give the service an effect applier that reports each display details write.
	 *
	 * @param WooPaymentsIntentConfirmationService $sut             Service under test.
	 * @param WooPaymentsAccountService            $account_service Account service.
	 * @param WooPaymentsTokenService              $token_service   Token service.
	 * @param callable                             $on_display      Gets the order each display write is for.
	 */
	private function replace_effect_applier( WooPaymentsIntentConfirmationService $sut, WooPaymentsAccountService $account_service, WooPaymentsTokenService $token_service, callable $on_display ): void {
		$effect_applier             = new class() extends WooPaymentsOrderEffectApplier {
			/**
			 * Gets the order each display write is for.
			 *
			 * @var callable|null
			 */
			public $on_display = null;

			/**
			 * Report the write, then write.
			 *
			 * @param WC_Order            $order           Order being updated.
			 * @param array<string,mixed> $result          Intent response.
			 * @param string              $account_country Account country.
			 */
			public function apply_payment_method_display_details( WC_Order $order, array $result, string $account_country = '' ): void {
				( $this->on_display )( $order );
				parent::apply_payment_method_display_details( $order, $result, $account_country );
			}
		};
		$effect_applier->on_display = $on_display;
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService::class )
		);
		$sut->init( wc_get_container()->get( Client::class ), wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class ), $token_service, $account_service, $effect_applier );
	}

	/**
	 * Create the intent confirmation service with real collaborators over the given API client.
	 *
	 * @param Client                       $api_client      API client.
	 * @param WooPaymentsTokenService|null $token_service   Token service, or null for one whose details lookup finds nothing.
	 * @param WooPaymentsAccountService    $account_service Account service.
	 * @return WooPaymentsIntentConfirmationService
	 */
	private function create_intent_confirmation_service( Client $api_client, ?WooPaymentsTokenService $token_service, WooPaymentsAccountService $account_service ): WooPaymentsIntentConfirmationService {
		$token_service = $token_service ?? $this->create_token_service();

		$service = new WooPaymentsIntentConfirmationService();
		$service->init(
			$api_client,
			wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class ),
			$token_service,
			$account_service,
			$this->create_effect_applier( $account_service, $token_service )
		);

		return $service;
	}

	/**
	 * Create the order effect applier with real collaborators.
	 *
	 * @param WooPaymentsAccountService    $account_service Account service.
	 * @param WooPaymentsTokenService|null $token_service   Token service, or null for one whose details lookup finds nothing.
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function create_effect_applier( WooPaymentsAccountService $account_service, ?WooPaymentsTokenService $token_service = null ): WooPaymentsOrderEffectApplier {
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service ?? $this->create_token_service(),
			new WooPaymentsOrderDataService(),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService::class )
		);

		return $effect_applier;
	}

	/**
	 * Create a token service whose payment method details lookup finds nothing.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service(): WooPaymentsTokenService {
		$sut = new WooPaymentsTokenService();
		$sut->init( $this->create_empty_details_service(), wc_get_container()->get( Client::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ), wc_get_container()->get( PaymentMethodRegistry::class ) );

		return $sut;
	}

	/**
	 * Create a payment method details service whose lookup finds nothing.
	 *
	 * @return WooPaymentsPaymentMethodDetailsService
	 */
	private function create_empty_details_service(): WooPaymentsPaymentMethodDetailsService {
		return new class() extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Get payment method details.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				return array();
			}
		};
	}

	/**
	 * Create a token service that saves the intent's payment method as a card token and syncs it to one related subscription.
	 *
	 * @param WC_Order $subscription Subscription stand-in the order renews.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service_with_saved_token_and_subscription( WC_Order $subscription ): WooPaymentsTokenService {
		$token_service               = new class() extends WooPaymentsTokenService {
			/** @var WC_Order|null */
			public ?WC_Order $subscription = null;

			/**
			 * Save the payment method as a card token for the user.
			 *
			 * @param string $payment_method_id Provider payment method ID.
			 * @param int    $user_id           User ID.
			 * @return \WC_Payment_Token
			 */
			public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?\WC_Payment_Token {
				$token = new \WC_Payment_Token_CC();
				$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
				$token->set_user_id( $user_id );
				$token->set_token( $payment_method_id );
				$token->set_card_type( 'visa' );
				$token->set_last4( '4242' );
				$token->set_expiry_month( '12' );
				$token->set_expiry_year( '2030' );
				$token->save();

				return $token;
			}

			/**
			 * Return the one related subscription.
			 *
			 * @param WC_Order $order       Order.
			 * @param string[] $order_types Order types.
			 * @return WC_Order[]
			 */
			public function get_related_subscriptions_for_order( WC_Order $order, array $order_types = array( 'parent', 'switch' ) ): array {
				return null === $this->subscription ? array() : array( $this->subscription );
			}
		};
		$token_service->subscription = $subscription;
		$token_service->init( $this->create_empty_details_service(), wc_get_container()->get( Client::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ), wc_get_container()->get( PaymentMethodRegistry::class ) );

		return $token_service;
	}

	/**
	 * Create a WooPayments account service mock.
	 *
	 * @param bool   $test_mode Whether WooPayments should run in test mode.
	 * @param string $country    Connected account country.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, string $country = 'US' ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'is_test_mode_enabled', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( $country );

		return $account_service;
	}

	/**
	 * Load one recorded intent response body by fixture and pair key.
	 *
	 * @param string $fixture Fixture file name.
	 * @param string $pair    Fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_intent( string $fixture, string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$decoded = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/' . $fixture ), true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "Fixture '$fixture' has no entry for pair '$pair'." );
	}

	/**
	 * Load one recorded 3DS challenge entry's response body by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{body:array<string,mixed>}
	 */
	private function load_recorded_manual_3ds_entry( string $pair ): array {
		return array( 'body' => $this->load_recorded_intent( 'platform-3ds-challenge-results.json', $pair ) );
	}

	/**
	 * Create a WooPayments test order.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
