<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentConfirmationService class.
 */
class WooPaymentsIntentConfirmationServiceTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

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
	 * @testdox Saving the payment method keeps the order's saved customer on the order and the related subscription when the intent's customer is not a string.
	 *
	 * A `wcpay_api_request_response` callback can hand back any value for `customer`; a coerced `true` must not replace the
	 * order's valid customer ID on the subscriptions the saved token is synced to.
	 */
	public function test_saving_the_payment_method_ignores_a_non_string_intent_customer(): void {
		$order = $this->create_woopayments_order( '10.99' );
		$order->set_customer_id( self::factory()->user->create() );
		$order->update_meta_data( '_stripe_customer_id', 'cus_saved' );
		$order->save();
		$subscription = $this->create_woopayments_order( '10.99' );

		$intent             = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' )['body'];
		$intent['customer'] = true;
		$token_service      = $this->create_token_service_with_saved_token_and_subscription( $subscription );
		$sut                = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), $token_service, $this->create_account_service( false ) );

		$sut->confirm_fetched_intent_for_order( $order, $intent, true );

		$this->assertSame( 'cus_saved', wc_get_order( $subscription->get_id() )->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cus_saved', wc_get_order( $order->get_id() )->get_meta( '_stripe_customer_id', true ) );
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
			$account_service    = $test->create_account_service( true );
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
	 * @testdox The order-status callback on an order $_dataName attaches nothing and applies no event, writes the card's display details once under the claim, saves the card, and logs only when the order does not already record the intent in its status.
	 *
	 * The callback leaves the payment to its webhook, as the redirect return does on these statuses
	 * (WooPaymentsRedirectReturnController). Client 11.3.0's callback attaches whatever the order's status
	 * (class-wc-payment-gateway-wcpay.php:4663, :4771). The intent is REC-3DS-2's completed challenge
	 * (`Fixtures/platform-3ds-challenge-results.json`, pair `classic_checkout_challenge_completed`).
	 *
	 * @dataProvider orders_the_callback_leaves_to_the_webhook
	 *
	 * @param string $status         Order status when the callback arrives.
	 * @param string $transaction_id Transaction ID the order stores: the intent's, a merchant's reference, or another one.
	 * @param string $stored_status  Stored intent status.
	 * @param string $stored_charge  Stored charge.
	 * @param bool   $logs           Whether the refusal is logged.
	 * @param string $intent_status  The confirmed intent's status: the recorded payment's, or an authorization's.
	 */
	public function test_callback_leaves_an_order_in_a_paid_status_or_on_hold_to_the_webhook( string $status, string $transaction_id, string $stored_status, string $stored_charge, bool $logs, string $intent_status = 'succeeded' ): void {
		$intent           = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' )['body'];
		$intent['status'] = $intent_status;
		$order            = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'intent' === $transaction_id ? (string) $intent['id'] : $transaction_id, (string) $intent['id'] );
		$order->set_customer_id( self::factory()->user->create() );
		$order->set_status( $status );
		$order->update_meta_data( '_intention_status', $stored_status );
		$order->update_meta_data( '_charge_id', $stored_charge );
		$order->save();
		$stored_txn      = $order->get_transaction_id();
		$displays        = array();
		$account_service = $this->create_account_service( false );
		$token_service   = $this->create_token_service_with_saved_token_and_subscription( $this->create_woopayments_order( '10.99' ) );
		$sut             = $this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), $token_service, $account_service );
		$this->replace_effect_applier(
			$sut,
			$account_service,
			$token_service,
			function ( WC_Order $displayed_order ) use ( &$displays, $intent ): void {
				$displays[] = $this->is_order_payment_lock_held_for( $displayed_order, new WooPaymentsPersistenceVocabulary(), (string) $intent['id'] );
			}
		);
		$logger     = RecordingWcLogger::install();
		$notes      = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$completion = did_action( 'woocommerce_pre_payment_complete' );

		$sut->confirm_fetched_intent_for_order( $order, $intent, true );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $status, $reloaded->get_status() );
		$this->assertSame( $stored_txn, $reloaded->get_transaction_id(), 'Nothing is attached.' );
		$this->assertSame( $stored_status, $reloaded->get_meta( '_intention_status', true ) );
		$this->assertSame( $stored_charge, $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( $completion, did_action( 'woocommerce_pre_payment_complete' ), 'No event is applied.' );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertFalse( as_has_scheduled_action( WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION ) );
		$this->assertSame( array( true ), $displays, 'The display details are written once, under the claim.' );
		$this->assertSame( '3220', $reloaded->get_meta( 'last4', true ) );
		$this->assertNotSame( '', $reloaded->get_meta( '_card_brand', true ) );
		$this->assertCount( 1, $reloaded->get_payment_tokens(), 'The card is saved and attached.' );
		$lines = array_values( array_filter( $logger->contexts, static fn( array $context ): bool => 'callback_refused_on_paid_order' === ( $context['reason'] ?? '' ) ) );
		$this->assertCount( $logs ? 1 : 0, $lines );
		if ( $logs ) {
			$this->assertSame( $order->get_id(), $lines[0]['order_id'] ?? null );
			$this->assertSame( $intent['id'], $lines[0]['intent_id'] ?? null );
			$this->assertSame( $stored_status, $lines[0]['stored_intent_status'] ?? null );
			$this->assertSame( $status, $lines[0]['order_status'] ?? null );
			$this->assertSame( 'order-status callback', $lines[0]['entry_point'] ?? null );
		}
	}

	/**
	 * Orders the callback leaves to the webhook: hand-marked over the open attempt, and the ordinary order the webhook paid first.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:bool,5?:string}>
	 */
	public function orders_the_callback_leaves_to_the_webhook(): array {
		return array(
			'Processing, marked paid by hand'            => array( OrderStatus::PROCESSING, 'intent', 'requires_action', '', true ),
			'Completed, marked by hand'                  => array( OrderStatus::COMPLETED, 'intent', 'requires_action', '', true ),
			'On hold, set by hand'                       => array( OrderStatus::ON_HOLD, 'intent', 'requires_action', '', true ),
			"Processing, the merchant's reference typed" => array( OrderStatus::PROCESSING, 'bank ref 7', 'requires_action', '', true ),
			'Processing, an empty transaction ID'        => array( OrderStatus::PROCESSING, '', 'requires_action', '', true ),
			'Processing, paid first by its webhook'      => array( OrderStatus::PROCESSING, 'intent', 'succeeded', 'ch_3UJjK4BzWlxcwgpP0EgZv2kl', false ),
			'On hold, authorized first by its webhook (manual capture)' => array( OrderStatus::ON_HOLD, 'intent', 'requires_capture', 'ch_3UJjK4BzWlxcwgpP0EgZv2kl', false, 'requires_capture' ),
		);
	}

	/**
	 * @testdox The order-status callback confirming a $0 order's setup intent on an order $_dataName goes on as before: it saves the payment method and applies the event, with no refusal line.
	 *
	 * A setup intent has no webhook, so on a $0 order the callback is the only writer; the refusal covers only an intent the
	 * callback attaches. Client 11.3.0 records the setup intent's payment method whatever it then does
	 * (class-wc-payment-gateway-wcpay.php:4708-4715) and completes the order only while it is not paid (:4665-4700).
	 *
	 * @dataProvider orders_a_setup_intent_callback_meets
	 *
	 * @param string $status    Order status when the callback arrives.
	 * @param bool   $completes Whether the event completes the order.
	 */
	public function test_callback_confirming_a_setup_intent_is_not_left_to_a_webhook( string $status, bool $completes ): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->update_meta_data( '_intent_id', 'seti_held' );
		$order->set_status( $status );
		$order->save();
		$intent      = array(
			'id'             => 'seti_held',
			'object'         => 'setup_intent',
			'status'         => 'succeeded',
			'customer'       => 'cus_held',
			'payment_method' => 'pm_held',
			'metadata'       => array( 'order_id' => (string) $order->get_id() ),
		);
		$logger      = RecordingWcLogger::install();
		$completions = did_action( 'woocommerce_pre_payment_complete' );

		$this->create_intent_confirmation_service( wc_get_container()->get( Client::class ), null, $this->create_account_service( false ) )
			->confirm_fetched_intent_for_order( $order, $intent, false, false, true );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pm_held', $reloaded->get_meta( '_payment_method_id', true ), 'The payment method is saved.' );
		$this->assertSame( $completes ? 1 : 0, did_action( 'woocommerce_pre_payment_complete' ) - $completions );
		$this->assertTrue( $reloaded->has_status( wc_get_is_paid_statuses() ), 'The order ends in a paid status.' );
		$this->assertCount( 0, array_filter( $logger->contexts, static fn( array $context ): bool => 'callback_refused_on_paid_order' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * Orders a $0 setup intent's callback meets in the three statuses, with whether its event completes the order.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function orders_a_setup_intent_callback_meets(): array {
		return array(
			'On hold, set while the challenge was open' => array( OrderStatus::ON_HOLD, true ),
			'Processing'                                => array( OrderStatus::PROCESSING, false ),
			'Completed'                                 => array( OrderStatus::COMPLETED, false ),
		);
	}

	/**
	 * @testdox The order-status callback on a Pending order still attaches the intent and completes the order.
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
		$this->assertSame( 'ch_3UJjK4BzWlxcwgpP0EgZv2kl', $reloaded->get_meta( '_charge_id', true ) );
		$this->assertSame( '3220', $reloaded->get_meta( 'last4', true ) );
		$this->assertCount( 1, array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( string $note ): bool => false !== strpos( $note, 'successfully charged' ) ) );
		$this->assertCount( 0, array_filter( $logger->contexts, static fn( array $context ): bool => 'callback_refused_on_paid_order' === ( $context['reason'] ?? '' ) ) );
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
			 * @param WC_Order $order Order.
			 * @return WC_Order[]
			 */
			public function get_related_subscriptions_for_order( WC_Order $order ): array {
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
	 * @param bool $test_mode Whether WooPayments should run in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'is_test_mode_enabled', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

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
