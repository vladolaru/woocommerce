<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EarlyFraudWarningEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAmountMismatchHold;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedTransactionRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalPaymentsRestController;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockWithClaimHook;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\StorefrontMultiCurrencyTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\FailedOrderCardRender;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsEventHandlerTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\UncachedOrderWriter;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for the EventIngestor class.
 */
class EventIngestorTest extends WC_Unit_Test_Case {
	use FailedOrderCardRender;


	use OrderPaymentLockTestTrait;

	use StorefrontMultiCurrencyTestTrait;

	use ProviderTextLogAssertions;

	use WooPaymentsEventHandlerTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var EventIngestor
	 */
	private $sut;

	/**
	 * Last refund charge ID created by the refundable order fixture.
	 *
	 * @var string
	 */
	private string $last_refund_charge_id = 'ch_123';

	/**
	 * Email class filters registered by receipt tests.
	 *
	 * @var callable[]
	 */
	private array $email_class_filters = array();

	/**
	 * Test-only gettext replacements.
	 *
	 * @var array<string,string>
	 */
	private array $gettext_replacements = array();

	/**
	 * Test-only gettext replacements keyed by text domain and source text.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $gettext_domain_replacements = array();

	/**
	 * Original multi-currency option values restored after each test.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_multi_currency_options = array();

	/**
	 * The mailer's email objects before the test, put back after it.
	 *
	 * @var array<string,\WC_Email>
	 */
	private array $original_mailer_emails = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_mailer_emails          = WC()->mailer()->emails;
		$this->original_multi_currency_options = array(
			'_wcpay_feature_customer_multi_currency'  => get_option( '_wcpay_feature_customer_multi_currency', null ),
			'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', null ),
			'wcpay_multi_currency_exchange_rate_eur'  => get_option( 'wcpay_multi_currency_exchange_rate_eur', null ),
			'wcpay_multi_currency_manual_rate_eur'    => get_option( 'wcpay_multi_currency_manual_rate_eur', null ),
		);
		$this->sut                             = wc_get_container()->get( EventIngestor::class );
		$this->last_refund_charge_id           = 'ch_123';
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'woocommerce_payments_before_webhook_delivery' );
		remove_all_actions( 'woocommerce_payments_after_webhook_delivery' );
		foreach ( $this->email_class_filters as $filter ) {
			remove_filter( 'woocommerce_email_classes', $filter );
		}
		remove_filter( 'gettext', array( $this, 'translate_woocommerce_test_string' ), 10 );
		restore_current_locale();
		$this->email_class_filters         = array();
		$this->gettext_replacements        = array();
		$this->gettext_domain_replacements = array();
		$this->reset_mailer_emails();
		$this->delete_dispute_cache_options();
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_onboarding_test_mode' );
		delete_option( '_wcpay_onboarding_stripe_connected' );
		delete_option( 'woocommerce_woopayments_nox_profile' );
		delete_option( 'woocommerce_woopayments_nox_onboarding_locked' );
		foreach ( $this->original_multi_currency_options as $option_name => $option_value ) {
			if ( null === $option_value ) {
				delete_option( $option_name );
			} else {
				update_option( $option_name, $option_value );
			}
		}
		$this->original_multi_currency_options = array();
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox payment_intent.succeeded on a subscription writes the payment meta and no payment lifecycle.
	 *
	 * Client 11.3.0 writes the intent, charge, payment method and currency meta (class-wc-payments-webhook-processing-service.php:643-700)
	 * and then never completes a subscription from an intent (class-wc-payments-order-service.php:466-472).
	 */
	public function test_payment_intent_succeeded_completes_nothing_on_a_subscription(): void {
		$order = $this->create_woopayments_order();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( $order->get_id() );
		$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertCount( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox payment_intent.succeeded for a Stripe Billing invoice writes the payment meta and leaves the order to its invoice: $_dataName.
	 *
	 * Client 11.3.0 writes the intent, charge, payment method and currency meta for every order it resolves
	 * (class-wc-payments-webhook-processing-service.php:643-700) and, for an intent with an invoice, skips the token repair
	 * and the status update (:711-717), pinned by its test_payment_intent_successful_for_invoice_does_not_complete_order().
	 *
	 * @dataProvider provider_orders_of_an_invoice_intent
	 *
	 * @param bool $paid Whether the invoice already paid the order.
	 */
	public function test_payment_intent_succeeded_for_an_invoice_leaves_the_order_to_the_invoice( bool $paid ): void {
		$order = $this->create_woopayments_order();
		if ( $paid ) {
			$order->payment_complete( 'pi_123' );
		}
		$status_before = $order->get_status();
		$notes_before  = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'invoice' => 'in_123' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status_before, $order->get_status() );
		$this->assertEquals( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'pm_123', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'usd', $order->get_meta( '_wcpay_intent_currency', true ) );
	}

	/**
	 * Orders an invoice intent resolves to.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function provider_orders_of_an_invoice_intent(): array {
		return array(
			'renewal that needs payment' => array( false ),
			'renewal its invoice paid'   => array( true ),
		);
	}

	/**
	 * @testdox payment_intent.succeeded for a Stripe Billing invoice leaves a renewal its invoice held on hold, with no note.
	 *
	 * Client 11.3.0 class-wc-payments-webhook-processing-service.php:711-717: the invoice's intent never reaches the status
	 * update, so a renewal invoice.paid held (class-wc-payments-subscriptions-event-handler.php:163-172) stays held.
	 */
	public function test_payment_intent_succeeded_for_an_invoice_leaves_a_held_renewal_held(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$notes_before = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'invoice' => 'in_123' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertEquals( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox payment_intent.succeeded for a payment that differs from the order total holds the order with one note, and its payment stays recorded: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-webhook-processing-service.php:716 → class-wc-payments-order-service.php:486-487,
	 * :2095-2139: on-hold, the note once, the intent status stored, no completion; the transaction and charge stay on the
	 * order, so it can be refunded.
	 *
	 * @dataProvider provider_payments_that_differ_from_the_order
	 *
	 * @param array<string,mixed> $overrides Intent overrides.
	 */
	public function test_payment_intent_succeeded_that_differs_from_the_order_total_holds_the_order( array $overrides ): void {
		$order = $this->create_woopayments_order();
		$event = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, $overrides );

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertSame( 'pi_123', $order->get_transaction_id() );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertFalse( as_has_scheduled_action( WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION, null, 'woocommerce_payments' ) );
	}

	/**
	 * @testdox payment_intent.succeeded for a USD 10.99 order on a JPY store completes it: no hold is decided on a rounded total.
	 *
	 * A webhook has no shopper, so the store's zero decimals are in force when the event is applied. Under the order payment
	 * lock the order is read again from its data store into the same object, and the amount comparison reads its total
	 * (WooPaymentsAmountMismatchHold::decide_hold()). The intent is the order's own 1099 cents.
	 */
	public function test_payment_intent_succeeded_on_a_store_with_fewer_decimals_compares_the_stored_total(): void {
		$order = $this->create_saved_usd_order( '10.99' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->save();
		$this->register_storefront_multi_currency( 'JPY', array( 'USD' => '0.0067' ) );
		$this->assertSame( 0, wc_get_price_decimals(), 'Precondition: the store currency, JPY, is in force.' );

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'amount' => 1099 ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '10.99', $order->get_total() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'successfully charged' ) );
	}

	/**
	 * @testdox A held order whose total was edited to match its payment completes on the next delivery, with one success note.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:483-491: an on-hold order is not paid, so a succeeded intent for its
	 * total and currency reaches mark_payment_completed() (:2032-2046) and completes it.
	 */
	public function test_payment_intent_succeeded_completes_a_held_order_once_its_total_matches(): void {
		$order = $this->create_woopayments_order();
		$event = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'amount' => 1234 ) );
		$this->sut->process( $event );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$order->set_total( '12.34' );
		$order->save();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		// An order with no items to ship goes to completed, as in test_payment_intent_succeeded_completes_order().
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertTrue( $order->is_paid() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
		$this->assertTrue( as_has_scheduled_action( WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION, null, 'woocommerce_payments' ) );
	}

	/**
	 * @testdox The hold of a payment that differs from the order total is logged only when it is applied: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:475 (nothing on a paid order) and :2117-2131 (the line once, with
	 * the note).
	 *
	 * @testWith ["pending", 1]
	 *           ["processing", 0]
	 *
	 * @param string $order_status   Order status before the deliveries: an order the hold applies to, or a paid one.
	 * @param int    $expected_lines Hold log lines after two deliveries.
	 */
	public function test_hold_is_logged_only_when_applied( string $order_status, int $expected_lines ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order = $this->create_woopayments_order();
		$order->set_status( $order_status );
		$order->set_transaction_id( 'pi_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->save();
		$logger = RecordingWcLogger::install();
		$event  = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'amount' => 1234 ) );

		$this->sut->process( $event );
		$this->sut->process( $event );

		$lines = array_values( array_filter( array_map( 'strval', array_column( $logger->lines, 1 ) ), static fn( string $line ): bool => false !== strpos( $line, 'put on hold: intent' ) ) );
		$this->assertCount( $expected_lines, $lines );
	}

	/**
	 * Payments that differ from the 10.00 USD order.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function provider_payments_that_differ_from_the_order(): array {
		return array(
			'another amount'   => array( array( 'amount' => 1234 ) ),
			'another currency' => array( array( 'currency' => 'eur' ) ),
		);
	}

	/**
	 * @testdox payment_intent.succeeded completes the order.
	 */
	public function test_payment_intent_succeeded_completes_order(): void {
		$order = $this->create_woopayments_order();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_123', $order->get_transaction_id() );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'pm_123', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		// Plugin 11.1.0 stores the webhook intent's currency as sent (class-wc-payments-webhook-processing-service.php:497,514).
		$this->assertSame( 'usd', $order->get_meta( '_wcpay_intent_currency', true ) );
		// A live account stores plugin 11.1.0 `Order_Mode::PRODUCTION` (class-order-mode.php:21).
		$this->assertSame( 'prod', $order->get_meta( '_wcpay_mode', true ) );
		$this->assertSame( 'mandate_123', $order->get_meta( '_stripe_mandate_id', true ) );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '8.77', $order->get_meta( '_wcpay_net', true ) );
		$this->assertSame( 'mobile_pos', $order->get_meta( '_wcpay_ipp_channel', true ) );
	}

	/**
	 * @testdox A $event_type delivery refused by a held order payment lock fails, and a later delivery applies it once the lock is free.
	 * The events are the ones the platform forwarded for a card checkout, a card decline and a manual-capture authorization (Fixtures/platform-payment-intent-events.json).
	 *
	 * @dataProvider lock_refused_lifecycle_events
	 *
	 * @param string $pair            Recorded payment intent event.
	 * @param string $expected_status Order status once the event applies.
	 */
	public function test_lifecycle_event_refused_by_the_order_payment_lock_is_not_acknowledged( string $pair, string $expected_status ): void {
		// The recorded events are test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_payment_intent_event( $pair, $order );
		$intent = $event['data']['object'];
		// The order's payment method is the one the checkout used: the succeeded intent's, or the declined card's; its total
		// is the recorded checkout's.
		$order->update_meta_data( '_payment_method_id', (string) ( $intent['last_payment_error']['payment_method']['id'] ?? $intent['payment_method'] ) );
		$order->set_total( '10.99' );
		$order->save();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		// A checkout that died after the platform captured keeps the lock until its TTL runs out.
		$this->hold_order_payment_lock( $order, $vocabulary, (string) $intent['id'] );

		$refusal = null;
		try {
			$this->sut->process( $event );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$refusal = $exception;
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$this->assertInstanceOf( OrderPaymentLockRefusedException::class, $refusal, 'A refused delivery must fail, so the store retries it instead of acknowledging it.' );
		$this->assertSame( $order->get_id(), $refusal->get_order_id() );
		$refused_order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $refused_order->get_status() );
		$this->assertSame( '', $refused_order->get_meta( '_intent_id', true ), 'A refused delivery writes no payment meta.' );

		$this->sut->process( $event );

		$this->assertSame( $expected_status, wc_get_order( $order->get_id() )->get_status(), 'A later delivery applies the refused event.' );
	}

	/** @return array<string,array{string,string}> */
	public static function lock_refused_lifecycle_events(): array {
		return array(
			'succeeded'      => array( 'card_checkout_succeeded', 'completed' ),
			'payment failed' => array( 'card_declined_failed', 'failed' ),
			'authorized'     => array( 'manual_capture_amount_capturable_updated', 'on-hold' ),
		);
	}

	/**
	 * @testdox A test-mode event reaches the order when the store runs in test mode through wcpay_test_mode while its gateway setting says live.
	 */
	public function test_webhook_mode_follows_the_full_test_mode_check(): void {
		$order = $this->create_paid_woopayments_order( 'ch_3UJbTlBzWlxcwgpP0vNaexjT', 'pi_3UJbTlBzWlxcwgpP0FQ4MWQE' );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		add_filter( 'wcpay_test_mode', '__return_true' );

		try {
			// Recorded in test mode: livemode is false.
			$this->sut->process( $this->load_recorded_dispute_created_event( 'accept_case_created' ) );
		} finally {
			remove_filter( 'wcpay_test_mode', '__return_true' );
		}

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox An event whose mode differs from the store's is skipped before any hook, with one error line naming it, as with the WooPayments extension.
	 */
	public function test_webhook_mode_mismatch_logs_the_event(): void {
		$order = $this->create_woopayments_order();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$logger     = RecordingWcLogger::install();
		$hook_calls = array();
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			function () use ( &$hook_calls ): void {
				$hook_calls[] = 'before';
			}
		);

		// The mismatch check reads only the envelope's livemode and id (client 11.1.0
		// class-wc-payments-webhook-processing-service.php:268-290); the PaymentIntent body is never reached.
		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(),
			array(
				'id'       => 'evt_mode_mismatch',
				'livemode' => true,
			)
		);
		$this->sut->process( $event );

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), $hook_calls );
		$this->assertSame( array( array( 'error', 'Webhook event mode did not match the gateway mode (event ID: evt_mode_mismatch)', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * @testdox With $label, a received event writes $expected 'WEBHOOK RECEIVED' debug line, with its body redacted.
	 * @testWith ["transport logging on", "yes", 1]
	 *           ["transport logging off", "no", 0]
	 *
	 * @param string $label          Case label.
	 * @param string $enable_logging Gateway enable_logging setting.
	 * @param int    $expected       Expected received lines.
	 */
	public function test_received_event_is_logged_only_under_the_transport_log_gate( string $label, string $enable_logging, int $expected ): void {
		unset( $label );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $enable_logging ) );
		$logger = RecordingWcLogger::install();
		$order  = $this->create_woopayments_order();
		// A PaymentIntent carries its client_secret (Stripe API PaymentIntent object); the client redacts it from this line
		// (class-wc-payments-webhook-processing-service.php:155-160, WC_Payments_API_Client::API_KEYS_TO_REDACT).
		$event = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'client_secret' => 'pi_123_secret_abc' ), array( 'id' => 'evt_received_line' ) );

		$this->sut->process( $event );

		$received = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'debug' === $line[0] && 'WEBHOOK RECEIVED: payment_intent.succeeded evt_received_line' === $line[1] ) );
		$this->assertCount( $expected, $received );
		if ( 1 === $expected ) {
			$body = $logger->contexts[ $received[0] ]['body'];
			$this->assertSame( 'pi_123', $body['data']['object']['id'] );
			$this->assertSame( '(redacted)', $body['data']['object']['client_secret'] );
		}
	}

	/**
	 * @testdox The received line carries the request context of every WooPayments line, with the request path only and no referrer.
	 *
	 * Client 11.1.0 writes it through its gated logger (includes/class-wc-payments-webhook-processing-service.php:155-160,
	 * includes/class-logger.php:150-153), which merges Logger_Context::get_context() into every line
	 * (src/Internal/Logger.php:64-69). Native leaves out the referrer and the query string, as on its other lines.
	 */
	public function test_received_line_carries_the_request_context(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = RecordingWcLogger::install();
		$order  = $this->create_woopayments_order();
		$event  = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array(), array( 'id' => 'evt_received_context' ) );

		$server                  = $_SERVER;
		$_SERVER['REQUEST_URI']  = '/wp-json/wc/v3/payments/webhook?trace=1';
		$_SERVER['HTTP_REFERER'] = 'https://shop.example.test/?key=wc_order_context';

		try {
			$this->sut->process( $event );
		} finally {
			$_SERVER = $server;
		}

		$received = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'WEBHOOK RECEIVED: payment_intent.succeeded evt_received_context' === $line[1] ) );
		$this->assertCount( 1, $received );
		$context = $logger->contexts[ $received[0] ];
		$this->assertSame( 'debug', $logger->lines[ $received[0] ][0] );
		$this->assertSame( '/wp-json/wc/v3/payments/webhook', $context['REQUEST_URI'] ?? null );
		$this->assertArrayHasKey( 'WOOPAYMENTS_MODE', $context );
		$this->assertArrayHasKey( 'WP_USER', $context );
		$this->assertArrayNotHasKey( 'HTTP_REFERER', $context );
		$this->assertSame( 'woopayments', $context['source'] );
	}

	/**
	 * @testdox The received line redacts the personal data a recorded invoice event carries.
	 */
	public function test_received_line_redacts_personal_data_of_a_recorded_invoice_event(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = RecordingWcLogger::install();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/platform-invoice-events.json' ), true );
		$event     = $recording['entries'][0]['body'];
		// The recorded invoice left these empty; fill them as a real customer's invoice carries them.
		$event['data']['object'] = array_merge(
			$event['data']['object'],
			array(
				'customer_phone'   => '+15555550100',
				'customer_email'   => 'shopper@example.com',
				// An invoice's customer_tax_ids entries are {type, value} (Stripe API Invoice object, customer_tax_ids).
				'customer_tax_ids' => array(
					array(
						'type'  => 'eu_vat',
						'value' => 'DE123456789',
					),
				),
				'metadata'         => array(
					'order_id'              => '123',
					'delivery_instructions' => 'Leave with Jane Doe, call +15555550100',
				),
				// custom_fields entries are {name, value} (Stripe API Invoice object, custom_fields).
				'custom_fields'    => array(
					array(
						'name'  => 'Contact',
						'value' => 'Jane Doe',
					),
				),
				'footer'           => 'Thanks, Jane Doe',
				'description'      => 'Renewal for Jane Doe, 1 Main Street',
				'customer_address' => array(
					'line1'       => '1 Main Street',
					'postal_code' => '10001',
				),
			)
		);

		try {
			$this->sut->process( $event );
		} catch ( \Throwable $exception ) {
			// The line is written before the event is handled; how the handling ends does not matter here.
			unset( $exception );
		}

		$received = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'debug' === $line[0] && 0 === strpos( $line[1], 'WEBHOOK RECEIVED: ' ) ) );
		$this->assertCount( 1, $received );
		$invoice = $logger->contexts[ $received[0] ]['body']['data']['object'];
		foreach ( array( 'customer_phone', 'customer_email', 'customer_name', 'customer_tax_ids', 'description' ) as $key ) {
			$this->assertSame( '(redacted)', $invoice[ $key ], $key );
		}
		$this->assertSame( '(redacted)', $invoice['customer_address']['line1'] );
		$this->assertSame( '(redacted)', $invoice['customer_address']['postal_code'] );
		$this->assertSame( '(redacted)', $invoice['custom_fields'] );
		$this->assertSame( '(redacted)', $invoice['footer'] );
		$this->assertSame( '(redacted)', $invoice['metadata']['delivery_instructions'] );
		$this->assertSame( '123', $invoice['metadata']['order_id'], 'The order reference support needs stays.' );
	}

	/**
	 * @testdox The received line redacts a dispute's evidence and issuer evidence, which carry the shopper's details and free text.
	 *
	 * REC-5b R-e `winning_evidence_closed_won` (`Fixtures/platform-dispute-closed-events.json`) is the exact dispute event body local
	 * WPCOM forwarded; its `evidence` object holds billing_address, customer_email_address and customer_name, and its
	 * `issuer_evidence` list holds the issuer's text_evidence.
	 */
	public function test_received_line_redacts_dispute_evidence(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enable_logging' => 'yes',
				'test_mode'      => 'yes',
			)
		);
		$logger = RecordingWcLogger::install();
		$event  = $this->load_recorded_dispute_closed_event( 'winning_evidence_closed_won' );
		$this->assertNotEmpty( $event['data']['object']['evidence']['billing_address'], 'The recording carries evidence to redact.' );
		// The recorded charge had no Woo order, so the order here is a fixture matched to the recorded charge ID.
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', $event['data']['object']['charge'] );
		$order->save();

		$this->sut->process( $event );

		$received = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'WEBHOOK RECEIVED: charge.dispute.closed ' ) ) );
		$this->assertCount( 1, $received );
		$dispute = $logger->contexts[ $received[0] ]['body']['data']['object'];
		$this->assertSame( '(redacted)', $dispute['evidence'] );
		$this->assertNotEmpty( $event['data']['object']['issuer_evidence'], 'The recording carries issuer evidence to redact.' );
		$this->assertSame( '(redacted)', $dispute['issuer_evidence'] );
		$this->assertSame( $event['data']['object']['id'], $dispute['id'], 'The dispute ID support needs stays.' );
		$this->assertSame( $event['data']['object']['amount'], $dispute['amount'] );
		$this->assertSame( $event['data']['object']['status'], $dispute['status'] );
		$this->assertSame( $event['data']['object']['evidence_details'], $dispute['evidence_details'], 'The due date and counts stay.' );
	}

	/**
	 * @testdox The received line redacts a PaymentIntent's receipt email and every metadata value but the listed references and flags with token values.
	 */
	public function test_received_line_redacts_a_payment_intent_receipt_email(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = RecordingWcLogger::install();
		$order  = $this->create_woopayments_order();
		// receipt_email is a PaymentIntent field (Stripe API PaymentIntent object).
		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'receipt_email' => 'shopper@example.com',
				'metadata'      => array(
					'gift_message'     => 'Happy birthday, Jane',
					'phone_number'     => '+15555550100',
					'tax_id'           => 'DE123456789',
					'reference_number' => 'Leave with Jane Doe',
					'session_id'       => 'ordinary-session-token',
					'customer_id'      => 'Jane Doe',
					// Malformed on purpose: metadata values are strings, so a nested array must not get through.
					'subscription_id'  => array( 'nested' => 'value' ),
					// Secret-shaped tokens under kept keys are still redacted as any logged string is.
					'order_number'     => 'sk_live_123456789',
					'payment_type'     => 'pi_123_secret_abc',
				),
			),
			array( 'id' => 'evt_receipt_email' )
		);

		$this->sut->process( $event );

		$received = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'WEBHOOK RECEIVED: payment_intent.succeeded evt_receipt_email' === $line[1] ) );
		$this->assertCount( 1, $received );
		$intent = $logger->contexts[ $received[0] ]['body']['data']['object'];
		$this->assertSame( '(redacted)', $intent['receipt_email'] );
		foreach ( array( 'gift_message', 'phone_number', 'tax_id', 'reference_number', 'session_id', 'customer_id', 'subscription_id', 'order_number', 'payment_type' ) as $key ) {
			$this->assertSame( '(redacted)', $intent['metadata'][ $key ], $key );
		}
		$this->assertSame( (string) $order->get_id(), $intent['metadata']['order_id'], 'The order reference support needs stays.' );
		$this->assertSame( 'mobile_pos', $intent['metadata']['ipp_channel'] );
	}

	/**
	 * @testdox payment_intent.succeeded repairs the payment token on an unpaid recurring order.
	 */
	public function test_payment_intent_succeeded_repairs_token_for_recurring_unpaid_order(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$customer_id = self::factory()->user->create();

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_token( 'pm_123' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$order = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->save();

		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $order->get_payment_tokens() ), 'The webhook must restore the token so the next scheduled renewal can charge.' );
	}

	/**
	 * @testdox payment_intent.succeeded decides on the order read after the token repair, and still writes the payment meta: $_dataName.
	 *
	 * Client 11.3.0 writes the payment meta, then repairs the token, then updates the order from the intent
	 * (class-wc-payments-webhook-processing-service.php:642-701, :715, :716). The token repair can call the platform, so the
	 * order is read again after it: a payment that leaves the order paid changes nothing (class-wc-payments-order-service.php:475),
	 * and an open dispute keeps the completion from changing the order.
	 *
	 * @testWith ["paid during the token repair", "paid"]
	 *           ["an open dispute during the token repair", "dispute"]
	 *
	 * @param string $label  What happens during the token repair.
	 * @param string $change `paid` or `dispute`.
	 */
	public function test_payment_intent_succeeded_decides_on_the_order_read_after_the_token_repair( string $label, string $change ): void {
		unset( $label );
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$customer_id = self::factory()->user->create();
		$order       = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->save();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_active_token_for_order', 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willReturnCallback(
			static function () use ( $order, $change ): ?\WC_Payment_Token {
				$other = wc_get_order( $order->get_id() );
				if ( 'paid' === $change ) {
					$other->set_status( OrderStatus::PROCESSING );
				} else {
					$other->update_meta_data( DisputeEventHandler::OPEN_DISPUTE_IDS_META_KEY, array( 'dp_token_repair' ) );
				}
				$other->save();

				return null;
			}
		);
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$event = $this->create_payment_intent_event( 'payment_intent.succeeded', $order );

		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );
		try {
			$this->sut->process( $event );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'paid' === $change ? OrderStatus::PROCESSING : OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), 'No success note.' );
		$this->assertSame( (string) $event['data']['object']['id'], $order->get_meta( '_intent_id', true ), 'The payment meta is written.' );
	}

	/**
	 * @testdox payment_intent.succeeded writes the repaired token through to the subscriptions of a parent order, not a renewal's ($relation).
	 *
	 * Client 11.3.0 copies it to the parent's and switch's subscriptions only (class-wc-payments-webhook-processing-service.php:745-760,
	 * trait-wc-payment-gateway-wcpay-subscriptions.php:718-730); a paid failed renewal moves its subscription through
	 * Subscriptions' failed-renewal hook instead.
	 *
	 * @testWith ["parent", true]
	 *           ["renewal", false]
	 *
	 * @param string $relation     The order's relation to the subscription.
	 * @param bool   $expect_token Whether the subscription takes the repaired token.
	 */
	public function test_payment_intent_succeeded_repair_syncs_only_a_parents_subscriptions( string $relation, bool $expect_token ): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$customer_id = self::factory()->user->create();

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_token( 'pm_123' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$order = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->update_meta_data( '_stripe_customer_id', 'cus_123' );
		$order->save();

		$subscription = wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$subscription->save();

		$GLOBALS['wcpay_test_renewal_order_ids']                = 'renewal' === $relation ? array( $order->get_id() ) : array();
		$GLOBALS['wcpay_test_order_subscription_relationships'] = array(
			$order->get_id() => array( $relation => array( $subscription->get_id() ) ),
		);

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'customer' => 'cus_123' ) ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS['wcpay_test_order_subscription_relationships'] );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $order->get_payment_tokens() ), 'The order itself takes the repaired token.' );
		if ( $expect_token ) {
			$this->assertContains( $token->get_id(), array_map( 'absint', $subscription->get_payment_tokens() ), 'WCS copies the subscription tokens into each new renewal order; a stale subscription charges the replaced card next renewal.' );
			$this->assertSame( '', $subscription->get_meta( '_payment_method_id', true ), 'The repair writes no payment method ID on the subscription; its save does.' );
			$this->assertSame( '', $subscription->get_meta( '_stripe_customer_id', true ), 'The repair writes no customer on the subscription; its save does.' );
		} else {
			$this->assertSame( '', $subscription->get_meta( '_stripe_customer_id', true ), 'A renewal\'s subscription takes no customer.' );
			$this->assertSame( array(), $subscription->get_payment_tokens() );
			$this->assertSame( '', $subscription->get_meta( '_payment_method_id', true ) );
		}
	}

	/**
	 * @testdox payment_intent.succeeded notes the subscription's move to the repaired card only when the subscription takes it ($relation).
	 *
	 * The note tells the merchant the subscription now renews on the new card. The token copy reaches the subscriptions a
	 * parent or switch order pays for; a repaired renewal's subscription keeps its card, so its next renewal can still charge
	 * the old one. Client 11.3.0 writes the note on a renewal too (class-wc-payments-webhook-processing-service.php:785-810).
	 *
	 * @testWith ["parent", true]
	 *           ["switch", true]
	 *           ["renewal", false]
	 *
	 * @param string $relation           The order's relation to the subscription.
	 * @param bool   $subscription_moves Whether the subscription takes the repaired card.
	 */
	public function test_payment_intent_succeeded_repair_notes_a_card_change_only_where_the_subscription_takes_it( string $relation, bool $subscription_moves ): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$customer_id = self::factory()->user->create();
		$tokens      = array();
		foreach ( array( 'pm_old', 'pm_123' ) as $payment_method_id ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			$token->set_user_id( $customer_id );
			$token->set_token( $payment_method_id );
			$token->set_card_type( 'visa' );
			$token->set_last4( 'pm_old' === $payment_method_id ? '1111' : '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( '2030' );
			$token->save();
			$tokens[] = $token;
		}
		list( $old_token, $new_token ) = $tokens;

		$order = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->add_payment_token( $old_token );
		$order->save();

		$subscription = wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$subscription->add_payment_token( $old_token );
		$subscription->save();

		$GLOBALS['wcpay_test_renewal_order_ids']                = 'renewal' === $relation ? array( $order->get_id() ) : array();
		$GLOBALS['wcpay_test_order_subscription_relationships'] = array(
			$order->get_id() => array( $relation => array( $subscription->get_id() ) ),
		);

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS['wcpay_test_order_subscription_relationships'] );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$tokens       = $subscription instanceof WC_Order ? array_map( 'absint', $subscription->get_payment_tokens() ) : array();
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $subscription_moves ? $new_token->get_id() : $old_token->get_id(), absint( end( $tokens ) ), 'The subscription renews on the card it ends with.' );
		$this->assertSame(
			$subscription_moves ? array( sprintf( 'WooPayments updated the subscription payment method token from token #%1$d to #%2$d after receiving a successful renewal payment webhook.', $old_token->get_id(), $new_token->get_id() ) ) : array(),
			array_map( static fn( $note ): string => (string) $note->content, $this->get_order_notes_containing( $order, 'updated the subscription payment method token' ) ),
			'The note says the subscription moved only when it did.'
		);
	}

	/**
	 * @testdox payment_intent.succeeded reads the payment method ID out of an expanded payment_method object.
	 */
	public function test_payment_intent_succeeded_extracts_expanded_payment_method_id(): void {
		$order = $this->create_woopayments_order();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'payment_method' => array( 'id' => 'pm_expanded' ),
					'charges'        => array( 'data' => array( array( 'payment_method' => array( 'id' => 'pm_expanded' ) ) ) ),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pm_expanded', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox payment_intent.succeeded does not re-save the token on an already-paid recurring order.
	 */
	public function test_payment_intent_succeeded_skips_token_repair_for_paid_order(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$customer_id = self::factory()->user->create();

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_token( 'pm_123' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$order = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->save();

		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertNotContains( $token->get_id(), array_map( 'absint', $order->get_payment_tokens() ), 'A paid order already saved its token at checkout; a redelivered event must not re-point it.' );
	}

	/**
	 * @testdox payment_intent.succeeded notes the token change when repair replaces a parent order's attached token, which its subscription takes.
	 */
	public function test_payment_intent_succeeded_notes_token_change_on_repair(): void {
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$customer_id = self::factory()->user->create();

		$old_token = new \WC_Payment_Token_CC();
		$old_token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$old_token->set_user_id( $customer_id );
		$old_token->set_token( 'pm_old' );
		$old_token->set_card_type( 'visa' );
		$old_token->set_last4( '1111' );
		$old_token->set_expiry_month( '11' );
		$old_token->set_expiry_year( '2029' );
		$old_token->save();

		$new_token = new \WC_Payment_Token_CC();
		$new_token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$new_token->set_user_id( $customer_id );
		$new_token->set_token( 'pm_123' );
		$new_token->set_card_type( 'visa' );
		$new_token->set_last4( '4242' );
		$new_token->set_expiry_month( '12' );
		$new_token->set_expiry_year( '2030' );
		$new_token->save();

		$order = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->add_payment_token( $old_token );
		$order->save();

		$subscription = wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$subscription->add_payment_token( $old_token );
		$subscription->save();

		$GLOBALS['wcpay_test_order_subscription_relationships'] = array( $order->get_id() => array( 'parent' => array( $subscription->get_id() ) ) );

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_order_subscription_relationships'] );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertContains( $new_token->get_id(), array_map( 'absint', $order->get_payment_tokens() ) );
		$subscription_tokens = array_map( 'absint', $subscription->get_payment_tokens() );
		$this->assertSame( $new_token->get_id(), end( $subscription_tokens ), 'The subscription takes the repaired card.' );
		$notes = implode( ' | ', array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
		$this->assertStringContainsString( 'updated the subscription payment method token', $notes );
	}

	/**
	 * @testdox A platform error saving a renewal's payment method from the webhook is logged with its status and code, never its message.
	 */
	public function test_webhook_token_save_failure_log_leaves_out_platform_text(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$customer_id = self::factory()->user->create();
		$order       = $this->create_woopayments_order();
		$order->set_customer_id( $customer_id );
		$order->save();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_active_token_for_order', 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willThrowException( self::make_provider_error() );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$logger = RecordingWcLogger::install();

		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );
		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
		}

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing', $order->get_id() ), array( $context['http_status'], $context['error_code'], $context['order_id'] ) );
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error fetching a lost dispute's summary is logged with its status and code, never its message.
	 */
	public function test_dispute_summary_fetch_failure_log_leaves_out_platform_text(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_total( '50.00' );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_3UJbTlBzWlxcwgpP0vNaexjT' );
		$order->save();
		$api_client = new class() extends Client {
			/**
			 * Fail the summary fetch as the platform does.
			 *
			 * @param string $dispute_id Dispute ID.
			 * @throws \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException Always.
			 */
			public function get_dispute_summary( string $dispute_id ): array {
				unset( $dispute_id );
				throw EventIngestorTest::make_provider_error();
			}
		};

		$sut    = $this->create_ingestor( wc_get_container()->get( OrderPaymentLifecycleService::class ), $api_client );
		$logger = RecordingWcLogger::install();

		$sut->process( $this->load_recorded_dispute_closed_event( 'accept_closed_lost' ) );

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox payment_intent.succeeded records the structural payment-success note identity.
	 */
	public function test_payment_intent_succeeded_records_structural_payment_complete_note_identity(): void {
		$order = $this->create_woopayments_order();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$order           = wc_get_order( $order->get_id() );
		$notes           = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_identities = array_map(
			static fn( $note ): string => (string) get_comment_meta( $note->id, '_wc_woopayments_note_identity', true ),
			$notes
		);

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertContains( hash( 'sha256', 'payment_lifecycle:pi_123|completed|payment_success' ), $note_identities );
	}

	/**
	 * @testdox payment_intent.succeeded records the Core test-mode note from the persisted order mode.
	 */
	public function test_payment_intent_succeeded_uses_persisted_test_mode_for_its_completion_note(): void {
		$this->install_test_translations_for_domain(
			'woocommerce',
			array(
				'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.' => 'Core test payment %1$s using %2$s (<a>%3$s</a>).',
			)
		);
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertOrderHasNoteContaining( $order, array( 'Core test payment', 'pi_123' ) );
	}

	/**
	 * @testdox A replay de-duplicates a persisted test-mode completion note from the WooPayments extension catalog.
	 */
	public function test_payment_intent_succeeded_replay_deduplicates_persisted_test_mode_woopayments_extension_catalog_note(): void {
		$this->install_test_translations_for_domain(
			'woocommerce-payments',
			array(
				'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.' => 'Plugin test payment %1$s using %2$s (<a>%3$s</a>).',
			)
		);
		$order = $this->create_woopayments_order();
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		$transaction_url = wc_get_container()->get( WooPaymentsOrderNoteService::class )->transaction_url( 'pi_123', 'ch_123' );
		$plugin_note     = sprintf( 'Plugin test payment %1$s using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_123</a>).', wc_price( 10.00, array( 'currency' => 'USD' ) ), $transaction_url );
		$live_note       = sprintf( 'A payment of %1$s was <strong>successfully charged</strong> using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_123</a>).', wc_price( 10.00, array( 'currency' => 'USD' ) ), $transaction_url );
		$order->add_order_note( $plugin_note );

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array(), array( 'id' => 'evt_replay_test_mode' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertSame( 0, $this->count_order_notes_matching( $order, $live_note ) );
	}

	/**
	 * @testdox payment_intent.succeeded sends an IPP receipt email for card-present charges with the account's business details ($description).
	 * @dataProvider ipp_receipt_merchant_details_provider
	 *
	 * @param string                   $description Case.
	 * @param array<string,mixed>|null $account     Cached account data, or null for no cached account.
	 * @param array<string,mixed>      $expected    Expected receipt merchant settings.
	 */
	public function test_payment_intent_succeeded_sends_ipp_receipt_email_for_card_present_charge( string $description, ?array $account, array $expected ): void {
		unset( $description );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		if ( null !== $account ) {
			$this->cache_woopayments_account( $account );
		}
		$order = $this->create_woopayments_order();
		$order->set_billing_email( 'ada@example.test' );
		$order->save();
		$email = $this->create_recording_ipp_receipt_email();
		$this->register_ipp_receipt_email( $email );
		$card_present_charge = $this->create_card_present_charge();
		$event               = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'metadata' => array(
					'ipp_channel' => 'mobile_store_management',
				),
			)
		);
		// The reader's charge as recorded, not merged into the built charge's card fields.
		$event['data']['object']['charges']['data'] = array( $card_present_charge );

		$this->sut->process( $event );

		$this->assertCount( 1, $email->triggered, 'A card-present successful payment should trigger one receipt email.' );
		$this->assertSame( $order->get_id(), $email->triggered[0]['order']->get_id() );
		$this->assertSame( $expected, $email->triggered[0]['merchant_settings'] );
		$this->assertSame( $card_present_charge['id'], $email->triggered[0]['charge']['id'] );
		$this->assertSame( 'card_present', $email->triggered[0]['charge']['payment_method_details']['type'] );
	}

	/**
	 * Provide receipt merchant detail rows: case, cached account, expected merchant settings.
	 *
	 * Expected values follow client includes/class-wc-payments-webhook-processing-service.php:587-593: the account's
	 * business profile, '' or [] without it (includes/class-wc-payment-gateway-wcpay.php:3318-3404).
	 * Accounts keep the shape recorded in ../Fixtures/platform-test-drive-account.json `account.business_profile` (:597-603),
	 * with varied values; `support_address: []` is as recorded.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>|null,2:array<string,mixed>}>
	 */
	public function ipp_receipt_merchant_details_provider(): array {
		$address = array(
			'city'        => 'San Francisco',
			'country'     => 'US',
			'line1'       => '123 Sample Street',
			'line2'       => 'Suite 100',
			'postal_code' => '94107',
			'state'       => 'CA',
		);
		$account = array(
			'account_id'       => 'acct_123',
			'is_live'          => true,
			'business_profile' => array(
				'name'            => 'Reader Store',
				'url'             => 'https://reader.example.test',
				'support_address' => $address,
				'support_email'   => 'support@example.test',
				'support_phone'   => '+1 555 0100',
			),
		);
		$empty   = array(
			'business_name' => '',
			'support_info'  => array(
				'address' => array(),
				'phone'   => '',
				'email'   => '',
			),
		);

		$no_address                                        = $account;
		$no_address['business_profile']['support_address'] = array();

		return array(
			'account profile'           => array(
				'account profile',
				$account,
				array(
					'business_name' => 'Reader Store',
					'support_info'  => array(
						'address' => $address,
						'phone'   => '+1 555 0100',
						'email'   => 'support@example.test',
					),
				),
			),
			'empty support address'     => array(
				'empty support address',
				$no_address,
				array(
					'business_name' => 'Reader Store',
					'support_info'  => array(
						'address' => array(),
						'phone'   => '+1 555 0100',
						'email'   => 'support@example.test',
					),
				),
			),
			'account without a profile' => array(
				'account without a profile',
				array(
					'account_id' => 'acct_123',
					'is_live'    => true,
				),
				$empty,
			),
			'no account'                => array( 'no account', null, $empty ),
		);
	}

	/**
	 * @testdox payment_intent.succeeded does not send an IPP receipt email for non-card-present charges.
	 */
	public function test_payment_intent_succeeded_does_not_send_ipp_receipt_email_for_non_card_present_charge(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$email = $this->create_recording_ipp_receipt_email();
		$this->register_ipp_receipt_email( $email );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'charges' => array(
						'data' => array(
							array(
								'id'                     => 'ch_card',
								'payment_method'         => 'pm_123',
								'application_fee_amount' => 123,
								'payment_method_details' => array(
									'type' => 'card',
									'card' => array(
										'mandate' => 'mandate_123',
									),
								),
							),
						),
					),
				)
			)
		);

		$this->assertCount( 0, $email->triggered, 'Only card-present payments should trigger IPP receipt emails.' );
	}

	/**
	 * @testdox After a receipt test tears down, the mailer's registry holds its original email objects, the ones the restored hooks call.
	 */
	public function test_teardown_puts_back_the_mailer_email_objects_the_hooks_call(): void {
		global $wp_filter;
		$original = WC()->mailer()->get_emails()['WC_Email_Customer_On_Hold_Order'];
		$this->register_ipp_receipt_email( $this->create_recording_ipp_receipt_email() );

		$this->tearDown();
		$this->setUp();

		$registered = WC()->mailer()->get_emails()['WC_Email_Customer_On_Hold_Order'] ?? null;
		$this->assertSame( $original, $registered, 'The registry should hold the original on-hold email object, not a rebuilt one.' );
		$hook = $wp_filter['woocommerce_order_status_pending_to_on-hold_notification'] ?? null;
		foreach ( ( $hook ? $hook->callbacks : array() ) as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \WC_Email_Customer_On_Hold_Order ) {
					$this->assertSame( $registered, $callback['function'][0], 'The hooked on-hold trigger should belong to the registry object.' );
				}
			}
		}
	}

	/**
	 * @testdox payment_intent.succeeded does not add a generic completion note to an already paid order.
	 */
	public function test_payment_intent_succeeded_does_not_add_generic_completion_note_to_paid_order(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method_title( 'Visa credit card' );
		$order->set_transaction_id( 'pi_123' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();
		$order->add_order_note( 'A test payment of $10.00 USD was processed using WooPayments in <strong>test mode</strong> (<a>pi_123</a>). No real funds were collected.' );

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$order_id = $order->get_id();
		$order    = wc_get_order( $order->get_id() );
		$notes    = array_map(
			static fn( $note ): string => (string) $note->content,
			wc_get_order_notes( array( 'order_id' => $order_id ) )
		);

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertNotContains( 'Payment complete.', $notes );
	}

	/**
	 * A succeeded event for an order that checkout already completed still writes the client's meta: the intent, charge
	 * and payment method IDs, the event's lowercase currency, the mandate, and the envelope's fee and net
	 * (class-wc-payments-webhook-processing-service.php:510-569, written before any status logic).
	 */
	public function test_payment_intent_succeeded_after_checkout_still_writes_the_charge_meta(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->save();
		$event = $this->create_payment_intent_event( 'payment_intent.succeeded', $order );
		$this->sut->process( $event );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		// Checkout-time values the event must replace.
		$order->update_meta_data( '_wcpay_transaction_fee', '0.75' );
		$order->update_meta_data( '_wcpay_net', '11.59' );
		$order->update_meta_data( '_wcpay_intent_currency', 'EUR' );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'currency' => 'eur',
					'amount'   => 1234,
					'charges'  => array(
						'data' => array(
							array(
								'id'                     => 'ch_replayed',
								'payment_method'         => 'pm_replayed',
								'application_fee_amount' => 75,
								'payment_method_details' => array( 'card' => array( 'mandate' => 'mandate_replayed' ) ),
								// The platform's envelope as recorded on a card checkout (Fixtures/platform-payment-intent-events.json).
								'fee_breakdown_v1'       => $this->recorded_fee_envelope( 'card_checkout_succeeded' ),
							),
						),
					),
				),
				array( 'id' => 'evt_replayed' )
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_replayed', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'pm_replayed', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'eur', $order->get_meta( '_wcpay_intent_currency', true ) );
		$this->assertSame( 'mandate_replayed', $order->get_meta( '_stripe_mandate_id', true ) );
		$this->assertSame( '0.62', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '10.37', $order->get_meta( '_wcpay_net', true ) );
	}

	/**
	 * @testdox payment_intent.succeeded preserves checkout-time non-card order details.
	 */
	public function test_payment_intent_succeeded_preserves_checkout_time_non_card_order_details(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order                           = $this->create_woopayments_order();
		$checkout_payment_method_details = wp_json_encode(
			array(
				'sepa_debit' => array(
					'bank_code'   => '19043',
					'branch_code' => '',
					'country'     => 'AT',
					'fingerprint' => 'checkout_fingerprint',
					'last4'       => '3201',
					'mandate'     => 'checkout_mandate',
				),
				'type'       => 'sepa_debit',
			)
		);
		$this->assertIsString( $checkout_payment_method_details );

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '65.00' );
		$product->set_price( '65.00' );
		$product->save();
		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => 65.00,
				'total'    => 65.00,
			)
		);
		$order->set_currency( 'EUR' );
		$order->set_total( '65.00' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit' );
		$order->set_payment_method_title( 'SEPA Direct Debit' );
		$order->update_meta_data( '_wcpay_payment_method_details', $checkout_payment_method_details );
		$order->update_meta_data( '_wcpay_payment_transaction_id', '' );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'currency'       => 'eur',
					'amount'         => 6500,
					'payment_method' => 'pm_sepa',
					'metadata'       => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
					),
					'charges'        => array(
						'data' => array(
							array(
								'id'                     => 'py_sepa',
								'payment_method'         => 'pm_sepa',
								'currency'               => 'eur',
								'amount'                 => 6500,
								'application_fee_amount' => 233,
								'balance_transaction'    => array( 'id' => 'txn_sepa' ),
								'outcome'                => array( 'risk_level' => 'normal' ),
								'payment_method_details' => array(
									'type'       => 'sepa_debit',
									'sepa_debit' => array(
										'bank_code'   => '19043',
										'branch_code' => '',
										'country'     => 'AT',
										'expected_debit_date' => '2026-07-09',
										'fingerprint' => 'webhook_fingerprint',
										'last4'       => '3201',
										'mandate'     => 'webhook_mandate',
									),
								),
								// The platform's EUR envelope as recorded on a Bancontact checkout (Fixtures/platform-payment-intent-events.json).
								'fee_breakdown_v1'       => $this->recorded_fee_envelope( 'bancontact_checkout_succeeded' ),
							),
						),
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit', $order->get_payment_method() );
		$this->assertSame( 'SEPA Direct Debit', $order->get_payment_method_title() );
		$this->assertSame( 'py_sepa', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$this->assertSame( 'normal', $order->get_meta( '_charge_risk_level', true ) );
		$this->assertFalse( $order->meta_exists( '_wcpay_fraud_outcome_status' ) );
		$this->assertSame( 'not_card', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( '0.69', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '10.3', $order->get_meta( '_wcpay_net', true ) );
		$this->assertSame( $checkout_payment_method_details, $order->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertOrderHasNoteContaining( $order, array( 'A payment of', 'successfully charged', 'WooPayments', 'pi_123' ) );
		$this->assertOrderHasNote( $order, 'Payment via SEPA Direct Debit (pi_123).' );
		$this->assertOrderHasNoNoteContaining( $order, 'Fee details' );
		$this->assertTrue( $this->is_fee_details_job_pending( $order->get_id(), 'pi_123', true ) );
	}

	/**
	 * @testdox payment_intent.succeeded keeps a split-method order's checkout title, whatever title the charge would compose.
	 *
	 * Client 11.1.0 writes the payment method title at checkout and on the redirect return, never from this webhook
	 * (includes/class-wc-payments-webhook-processing-service.php process_webhook_payment_intent_succeeded()).
	 */
	public function test_payment_intent_succeeded_keeps_the_split_method_checkout_title(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->set_woopayments_account_country( 'US' );
		$order = $this->create_woopayments_order();
		$order->set_billing_country( 'US' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'afterpay_clearpay' );
		$order->set_payment_method_title( 'Afterpay' );
		$order->set_total( '65.00' );
		$order->update_meta_data(
			'_wcpay_payment_method_details',
			wp_json_encode(
				array(
					'type'              => 'afterpay_clearpay',
					'afterpay_clearpay' => array(
						'order_id'  => 'checkout_afterpay_order',
						'reference' => null,
					),
				)
			)
		);
		$order->save();

		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'currency'       => 'usd',
				'amount'         => 6500,
				'payment_method' => 'pm_afterpay',
				'charges'        => array(
					'data' => array(
						array(
							'id'                     => 'py_afterpay',
							'payment_method'         => 'pm_afterpay',
							'currency'               => 'usd',
							'amount'                 => 6500,
							'application_fee_amount' => 420,
							'balance_transaction'    => array( 'id' => 'txn_afterpay' ),
							'outcome'                => array( 'risk_level' => 'normal' ),
							'payment_method_details' => array(
								'type'              => 'afterpay_clearpay',
								'afterpay_clearpay' => array(
									'order_id'  => 'webhook_afterpay_order',
									'reference' => null,
								),
							),
							'fee_breakdown_v1'       => array(
								'totals' => array(
									'fee' => array(
										'amount'   => 420,
										'currency' => 'usd',
									),
									'net' => array(
										'amount'   => 6080,
										'currency' => 'usd',
									),
								),
							),
						),
					),
				),
			)
		);
		unset( $event['data']['object']['charges']['data'][0]['payment_method_details']['card'] );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'afterpay_clearpay', $order->get_payment_method() );
		$this->assertSame( 'Afterpay', $order->get_payment_method_title() );
		$this->assertOrderHasNote( $order, 'Payment via Afterpay (pi_123).' );
		$this->assertSame(
			wp_json_encode(
				array(
					'type'              => 'afterpay_clearpay',
					'afterpay_clearpay' => array(
						'order_id'  => 'checkout_afterpay_order',
						'reference' => null,
					),
				)
			),
			$order->get_meta( '_wcpay_payment_method_details', true )
		);
	}

	/**
	 * @testdox payment_intent.succeeded leaves the payment method and title of a $_dataName as they are, on the order and its subscription.
	 *
	 * Client 11.1.0 never sets the method or title from this webhook (includes/class-wc-payments-webhook-processing-service.php
	 * process_webhook_payment_intent_succeeded()); the in-person capture set woocommerce_payments and the in-person title
	 * (includes/admin/class-wc-rest-payments-orders-controller.php:213-214). The subscription stand-in is an order the
	 * subscriptions double relates to the parent; it must keep the WooPayments gateway, the one renewals charge through.
	 *
	 * @dataProvider provide_orders_paid_before_the_succeeded_event
	 *
	 * @param string $charge_type Charge the event carries: card_present, interac_present or card.
	 * @param string $title       Title the order was given before the event.
	 * @param bool   $is_woopay   Whether the order was paid with WooPay.
	 */
	public function test_payment_intent_succeeded_keeps_the_payment_method_and_title( string $charge_type, string $title, bool $is_woopay ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->set_woopayments_account_country( 'US' );
		$order = $this->create_woopayments_order();
		$order->set_payment_method_title( $title );
		if ( $is_woopay ) {
			$order->add_meta_data( 'is_woopay', '1', true );
		}
		$order->save();
		$subscription = $this->create_woopayments_order();
		$subscription->set_payment_method_title( $title );
		$subscription->save();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $order->get_id() => array( 'parent' => array( $subscription->get_id() ) ) );

		try {
			$this->sut->process( 'card' === $charge_type ? $this->create_recorded_card_succeeded_event( $order ) : $this->create_recorded_in_person_succeeded_event( $order, $charge_type ) );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertTrue( $order->is_paid(), 'The event completes the payment.' );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( $title, $order->get_payment_method_title() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $subscription->get_payment_method() );
		$this->assertSame( $title, $subscription->get_payment_method_title() );
	}

	/**
	 * Orders paid before their payment_intent.succeeded event: charge type, the order's title, WooPay.
	 *
	 * The two card rows titled "Visa credit card" are guards: that is also the title the recorded card charge composes, so
	 * they pass whether or not the webhook writes it. The generic "Card" row is the one a rewrite changes.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function provide_orders_paid_before_the_succeeded_event(): array {
		return array(
			'in-person card_present order'           => array( 'card_present', 'WooCommerce In-Person Payments', false ),
			'in-person interac_present order'        => array( 'interac_present', 'WooCommerce In-Person Payments', false ),
			'card order with the generic Card title' => array( 'card', 'Card', false ),
			'card order (guard)'                     => array( 'card', 'Visa credit card', false ),
			'WooPay order (guard)'                   => array( 'card', 'Visa credit card', true ),
		);
	}

	/**
	 * Build the recorded in-person payment_intent.succeeded event for an order.
	 *
	 * The card_present event is the one the platform received for a simulated reader payment
	 * (Fixtures/platform-card-present-succeeded-event.json). No interac_present payment can be taken locally, so that row
	 * derives from the card_present event: the charge's payment_method_details.type becomes interac_present, its
	 * card_present details move to an interac_present key, and the intent's payment_method_types becomes
	 * array( 'interac_present' ); nothing else changes. For both, the intent amounts are set to the order's 10.00.
	 *
	 * @param WC_Order $order       Order the event pays.
	 * @param string   $charge_type card_present or interac_present.
	 * @return array<string,mixed>
	 */
	private function create_recorded_in_person_succeeded_event( WC_Order $order, string $charge_type ): array {
		$recording = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-card-present-succeeded-event.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$event     = $recording['events'][0];
		$event['data']['object']['metadata']['order_id']  = (string) $order->get_id();
		$event['data']['object']['metadata']['order_key'] = $order->get_order_key();
		$event['data']['object']['amount']                = 1000;
		$event['data']['object']['amount_received']       = 1000;
		if ( 'interac_present' === $charge_type ) {
			$details                    = $event['data']['object']['charges']['data'][0]['payment_method_details'];
			$details['interac_present'] = $details['card_present'];
			$details['type']            = 'interac_present';
			unset( $details['card_present'] );

			$event['data']['object']['charges']['data'][0]['payment_method_details'] = $details;
			$event['data']['object']['payment_method_types']                         = array( 'interac_present' );
		}

		return $event;
	}

	/**
	 * The payment_intent.succeeded the platform forwarded for an online card checkout (4242, visa credit), for an order.
	 *
	 * Fixtures/platform-payment-intent-events.json, pair card_checkout_succeeded; the metadata names the order and the intent
	 * amounts are set to its 10.00.
	 *
	 * @param WC_Order $order Order the event pays.
	 * @return array<string,mixed>
	 */
	private function create_recorded_card_succeeded_event( WC_Order $order ): array {
		$event                                      = $this->recorded_payment_intent_event( 'card_checkout_succeeded', $order );
		$event['data']['object']['amount']          = 1000;
		$event['data']['object']['amount_received'] = 1000;

		return $event;
	}

	/**
	 * A recorded payment intent event (Fixtures/platform-payment-intent-events.json) whose metadata names the order.
	 *
	 * The run's own order is gone and the logged order key was redacted, so the order ID and key are the test's.
	 *
	 * @param string   $pair  Recorded entry.
	 * @param WC_Order $order Order the event names.
	 * @return array<string,mixed>
	 */
	private function recorded_payment_intent_event( string $pair, WC_Order $order ): array {
		$event = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', $pair );
		$event['data']['object']['metadata']['order_id']  = (string) $order->get_id();
		$event['data']['object']['metadata']['order_key'] = $order->get_order_key();

		return $event;
	}

	/**
	 * The fee_breakdown_v1 envelope of a recorded payment_intent.succeeded charge.
	 *
	 * @param string $pair Recorded entry.
	 * @return array<string,mixed>
	 */
	private function recorded_fee_envelope( string $pair ): array {
		return RecordedPlatformEvents::body( 'platform-payment-intent-events.json', $pair )['data']['object']['charges']['data'][0]['fee_breakdown_v1'];
	}

	/**
	 * @testdox payment_intent.succeeded backfills completed non-card details when checkout stored placeholders.
	 */
	public function test_payment_intent_succeeded_backfills_completed_non_card_details_when_checkout_stored_placeholders(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'ideal' );
		$order->set_payment_method_title( 'iDEAL | Wero' );
		$order->update_meta_data( '_wcpay_payment_method_details', '[]' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', '' );
		$order->save();

		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'currency'       => 'eur',
				'amount'         => 6500,
				'payment_method' => 'pm_ideal',
				'charges'        => array(
					'data' => array(
						array(
							'id'                     => 'py_ideal',
							'payment_method'         => 'pm_ideal',
							'currency'               => 'eur',
							'amount'                 => 6500,
							'application_fee_amount' => 233,
							'balance_transaction'    => array( 'id' => 'txn_ideal' ),
							'outcome'                => array( 'risk_level' => 'normal' ),
							'payment_method_details' => array(
								'type'  => 'ideal',
								'ideal' => array(
									'bank'           => 'rabobank',
									'bic'            => 'RABONL2U',
									'iban_last4'     => '5264',
									'transaction_id' => 'txn_method',
									'verified_name'  => 'John Smith',
								),
							),
							'fee_breakdown_v1'       => array(
								'totals' => array(
									'fee' => array(
										'amount'   => 233,
										'currency' => 'eur',
									),
									'net' => array(
										'amount'   => 6267,
										'currency' => 'eur',
									),
								),
							),
						),
					),
				),
			)
		);
		unset( $event['data']['object']['charges']['data'][0]['payment_method_details']['card'] );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'txn_ideal', $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$this->assertSame(
			wp_json_encode(
				array(
					'type'  => 'ideal',
					'ideal' => array(
						'bank'           => 'rabobank',
						'bic'            => 'RABONL2U',
						'iban_last4'     => '5264',
						'transaction_id' => 'txn_method',
						'verified_name'  => 'John Smith',
					),
				)
			),
			$order->get_meta( '_wcpay_payment_method_details', true )
		);
	}

	/**
	 * @testdox payment_intent.succeeded can resolve an order from existing intent meta.
	 */
	public function test_payment_intent_succeeded_resolves_order_from_existing_intent_meta(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'metadata' => array(
						'order_id'  => null,
						'order_key' => null,
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox payment_intent.succeeded with an allow outcome stamps $expected_box_type on a $order_status order.
	 *
	 * An order held on-hold for review gets review_allowed; any other keeps the plain allow stamp.
	 *
	 * @dataProvider provider_succeeded_intent_fraud_meta_box
	 *
	 * @param string $order_status      Order status before the event.
	 * @param string $expected_box_type Fraud meta box type after the event.
	 */
	public function test_payment_intent_succeeded_stamps_the_fraud_meta_box_by_order_status( string $order_status, string $expected_box_type ): void {
		$order = $this->create_woopayments_order();
		$order->set_status( $order_status );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'metadata' => array( 'fraud_outcome' => 'allow' ),
					'charges'  => array(
						'data' => array(
							array(
								'payment_method_details' => array( 'type' => 'card' ),
							),
						),
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_box_type, $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( 'allow', $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
	}

	/**
	 * Order statuses and the fraud meta box a succeeded allow outcome leaves.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_succeeded_intent_fraud_meta_box(): array {
		return array(
			'on hold for review' => array( 'on-hold', 'review_allowed' ),
			'pending'            => array( 'pending', 'allow' ),
		);
	}

	/**
	 * @testdox Only an actionable, non-terminal decline stores its card before the Failed email.
	 *
	 * @testWith ["charge"]
	 *           ["no charge"]
	 *           ["terminal"]
	 *           ["another intent"]
	 *           ["already paid"]
	 *           ["already failed"]
	 * @param string $scenario The kind of decline delivered.
	 */
	public function test_failure_event_stores_only_the_declined_card( string $scenario ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_payment_intent_event( 'card_declined_failed', $order );
		$intent = &$event['data']['object'];
		$pm     = $intent['last_payment_error']['payment_method'];
		$order->update_meta_data( '_payment_method_id', $pm['id'] );
		$order->update_meta_data( '_intent_id', 'another intent' === $scenario ? 'pi_other' : $intent['id'] );
		if ( 'no charge' === $scenario ) {
			$intent['charges']['data'] = array();
		} elseif ( 'terminal' === $scenario ) {
			$intent['last_payment_error']['payment_method']['type'] = 'card_present';
		} elseif ( 'already paid' === $scenario ) {
			$order->set_status( OrderStatus::PROCESSING );
		} elseif ( 'already failed' === $scenario ) {
			$order->set_status( OrderStatus::FAILED );
		}
		$order->save();
		$render = $this->observe_failed_order_card();
		$this->sut->process( $event );
		$order = new WC_Order( $order->get_id() );
		$this->assertFalse( $order->meta_exists( '_charge_id' ) );
		if ( in_array( $scenario, array( 'charge', 'no charge' ), true ) ) {
			$expected = 'charge' === $scenario ? $intent['charges']['data'][0]['payment_method_details'] : array(
				'type' => 'card',
				'card' => $pm['card'],
			);
			$this->assertSame( 0, $render->fetches, 'The failure must supply the card before the email renders.' );
			$this->assertSame( array( $expected['card']['last4'] ), $render->last4 );
			$this->assertEquals( $expected, json_decode( $order->get_meta( '_wcpay_payment_method_details', true ), true ) );
		} else {
			$this->assertSame( '', $order->get_meta( '_wcpay_payment_method_details', true ) );
		}
	}

	/**
	 * @testdox A later intent paid by webhook replaces the earlier declined card, including on redelivery after a refusal.
	 *
	 * @testWith [false]
	 *           [true]
	 * @param bool $retry Whether another holder refuses the first delivery.
	 */
	public function test_success_event_replaces_another_intents_declined_card( bool $retry ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->set_status( OrderStatus::FAILED );
		$order->update_meta_data( '_intent_id', 'pi_earlier' );
		$order->update_meta_data( '_wcpay_payment_method_details', '{"type":"card","card":{"last4":"0002","brand":"visa"}}' );
		$order->save();
		$event = $this->recorded_payment_intent_event( 'card_checkout_succeeded', $order );
		if ( $retry ) {
			$vocabulary = new WooPaymentsPersistenceVocabulary();
			$this->hold_order_payment_lock( $order, $vocabulary, 'pi_earlier' );
			try {
				$this->sut->process( $event );
				$this->fail( 'The first delivery should be refused.' );
			} catch ( OrderPaymentLockRefusedException $refusal ) {
				$this->assertSame( $order->get_id(), $refusal->get_order_id() );
			} finally {
				$this->clear_order_payment_lock( $order, $vocabulary );
			}
		}
		$this->sut->process( $event );
		$order = new WC_Order( $order->get_id() );
		$this->assertSame( $event['data']['object']['id'], $order->get_meta( '_intent_id', true ) );
		$this->assertEquals( $event['data']['object']['charges']['data'][0]['payment_method_details'], json_decode( $order->get_meta( '_wcpay_payment_method_details', true ), true ) );
	}

	/**
	 * @testdox payment_intent.payment_failed for $_dataName fails the order once, with one note, and leaves its transaction ID and stored intent as they are.
	 *
	 * Client 11.3.0's failed writers store the status, the note and the intent status, never the intent ID
	 * (class-wc-payments-order-service.php:545-560, :956-976), and a redelivered failure adds nothing once its note is on the
	 * order (:550-554); a terminal decline's note is linked to its charge (:2139-2141). The card row is the event the platform
	 * forwarded for a declined card checkout (Fixtures/platform-payment-intent-events.json, card_declined_failed), whose
	 * last_payment_error carries the full PaymentMethod. No AU BECS or terminal decline was delivered locally, so those rows
	 * keep the fields the handler reads.
	 *
	 * @dataProvider provider_actionable_payment_failures
	 *
	 * @param string $order_payment_method_id `_payment_method_id` on the order, or '' for the recorded event's.
	 * @param string $payment_method_id       Failed payment method ID in the event.
	 * @param string $payment_method_type     Failed payment method type in the event.
	 * @param string $pair                    Recorded event, or '' for a built one.
	 * @param string $stored_intent           `_intent_id` and transaction ID on the order before the event: '', the event's intent (`event`), or another intent.
	 */
	public function test_payment_intent_failed_marks_order_failed( string $order_payment_method_id, string $payment_method_id, string $payment_method_type, string $pair, string $stored_intent ): void {
		// The recorded events are test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$event = '' !== $pair
			? $this->recorded_payment_intent_event( $pair, $order )
			: $this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => $payment_method_id,
							'type' => $payment_method_type,
						),
					),
				)
			);
		$order->update_meta_data( '_payment_method_id', '' !== $order_payment_method_id ? $order_payment_method_id : (string) $event['data']['object']['last_payment_error']['payment_method']['id'] );
		$stored_intent = 'event' === $stored_intent ? (string) $event['data']['object']['id'] : $stored_intent;
		$order->set_transaction_id( $stored_intent );
		$order->update_meta_data( '_intent_id', $stored_intent );
		$order->save();

		foreach ( array( 'delivery', 'redelivery' ) as $delivery ) {
			$event['id'] = "evt_payment_failed_{$delivery}";
			$this->sut->process( $event );

			$order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame(
				array( 'failed', $stored_intent, $stored_intent, 'requires_payment_method', 1 ),
				array( $order->get_status(), $order->get_transaction_id(), $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), count( $this->get_order_notes_containing( $order, '<strong>failed</strong>' ) ) ),
				"Status, transaction ID, stored intent, intent status and failure notes after the $delivery."
			);
		}
	}

	/**
	 * Failures the order is failed for.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function provider_actionable_payment_failures(): array {
		return array(
			'a card, no payment stored'              => array( '', '', 'card', 'card_declined_failed', '' ),
			'a card, the payment the order waits on' => array( '', '', 'card', 'card_declined_failed', 'event' ),
			'AU BECS debit, no payment stored'       => array( 'pm_123', 'pm_123', 'au_becs_debit', '', '' ),
			'a terminal, another payment method'     => array( 'pm_current', 'pm_terminal', 'card_present', '', '' ),
			'a terminal, while an earlier online payment is stored' => array( 'pm_current', 'pm_terminal', 'card_present', '', 'pi_online_attempt' ),
		);
	}

	/**
	 * @testdox payment_intent.payment_failed writes the platform's error message into the note as text, not markup.
	 */
	public function test_payment_intent_failed_note_escapes_the_platform_message(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'status'             => 'requires_payment_method',
					// last_payment_error carries the platform's free-text message (Stripe API PaymentIntent object).
					'last_payment_error' => array(
						'code'           => 'card_declined',
						'message'        => 'Declined <b>by issuer</b> & "flagged"',
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'card',
						),
					),
				),
				array( 'id' => 'evt_escaped_failure_note' )
			)
		);

		$notes  = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$failed = array_values( array_filter( $notes, static fn( $note ): bool => false !== strpos( $note->content, 'With the following message' ) ) );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'Declined &lt;b&gt;by issuer&lt;/b&gt;', $failed[0]->content );
		$this->assertStringNotContainsString( '<b>by issuer</b>', $failed[0]->content );
	}

	/**
	 * @testdox payment_intent.payment_failed adds the current-locale translated failure note.
	 */
	public function test_payment_intent_failed_adds_translated_failure_note(): void {
		$this->install_woocommerce_test_translations(
			array(
				'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).' => 'Eine Zahlung von %1$s ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>).',
				'With the following message: <code>%s</code>'                        => 'Mit der folgenden Meldung: <code>%s</code>',
			)
		);
		switch_to_locale( 'de_DE' );

		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();
		$note_service  = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class );
		$expected_note = sprintf(
			'Eine Zahlung von %1$s ist mit WooPayments <strong>fehlgeschlagen</strong> (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_123</a>). Mit der folgenden Meldung: <code>Issuer unavailable.</code>',
			wc_price( 10.00, array( 'currency' => $order->get_currency() ) ),
			$note_service->transaction_url( 'pi_123', 'ch_123' )
		);

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'message'        => 'Issuer unavailable.',
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'card',
						),
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertOrderHasNote( $order, $expected_note );
	}

	/**
	 * @testdox payment_intent.payment_failed adopts the exact German WooPayments extension catalog note, and changes nothing more once that note is on the order.
	 *
	 * Client 11.3.0's failed writer returns once its note is on the order (class-wc-payments-order-service.php:550-554).
	 */
	public function test_payment_intent_failed_deduplicates_german_woopayments_extension_note(): void {
		$this->install_test_translations_for_domain(
			'woocommerce',
			array(
				'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).' => 'Core-Zahlung %1$s ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>).',
				'With the following message: <code>%s</code>'                        => 'Core-Meldung: <code>%s</code>',
			)
		);
		$this->install_test_translations_for_domain(
			'woocommerce-payments',
			array(
				'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).' => 'Plugin-Zahlung %1$s ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>).',
				'With the following message: <code>%s</code>'                        => 'Plugin-Meldung: <code>%s</code>',
			)
		);
		switch_to_locale( 'de_DE' );
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_payment_method_id', 'pm_standard_failure' );
		$order->save();
		$note_service = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class );
		$plugin_note  = sprintf(
			'Plugin-Zahlung %1$s ist mit WooPayments <strong>fehlgeschlagen</strong> (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_standard_failure</a>). Plugin-Meldung: <code>Issuer unavailable.</code>',
			wc_price( 10.00, array( 'currency' => $order->get_currency() ) ),
			$note_service->transaction_url( 'pi_standard_failure', 'ch_standard_failure' )
		);
		$order->add_order_note( $plugin_note );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'id'                 => 'pi_standard_failure',
					'status'             => 'requires_payment_method',
					'charges'            => array( 'data' => array( array( 'id' => 'ch_standard_failure' ) ) ),
					'last_payment_error' => array(
						'message'        => 'Issuer unavailable.',
						'payment_method' => array(
							'id'   => 'pm_standard_failure',
							'type' => 'card',
						),
					),
				),
				array( 'id' => 'evt_standard_failure' )
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Core-Zahlung' ) );
	}

	/**
	 * @testdox payment_intent.payment_failed adopts the exact German WooPayments extension terminal note with a mapped suffix, and fails the order.
	 *
	 * Client 11.3.0's terminal failure writer sets the order Failed before its note check (class-wc-payments-order-service.php:961-968).
	 */
	public function test_terminal_payment_intent_failed_deduplicates_german_woopayments_extension_note(): void {
		$this->install_test_translations_for_domain(
			'woocommerce',
			array(
				'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)' => 'Core-Terminalzahlung %1$s ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>)',
				"The customer's account has insufficient funds to cover this payment."       => 'Core-Konto hat nicht genuegend Guthaben.',
			)
		);
		$this->install_test_translations_for_domain(
			'woocommerce-payments',
			array(
				'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)' => 'Plugin-Terminalzahlung %1$s ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>)',
				"The customer's account has insufficient funds to cover this payment."       => 'Plugin-Konto hat nicht genuegend Guthaben.',
			)
		);
		switch_to_locale( 'de_DE' );

		$order        = $this->create_woopayments_order();
		$note_service = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class );
		$plugin_note  = sprintf(
			'Plugin-Terminalzahlung %1$s ist mit WooPayments <strong>fehlgeschlagen</strong> (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_terminal_failure</a>) Plugin-Konto hat nicht genuegend Guthaben.',
			wc_price( 10.00, array( 'currency' => $order->get_currency() ) ),
			$note_service->transaction_url( '', 'ch_terminal_failure' )
		);
		$order->add_order_note( $plugin_note );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'id'                 => 'pi_terminal_failure',
					'status'             => 'requires_payment_method',
					'charges'            => array( 'data' => array( array( 'id' => 'ch_terminal_failure' ) ) ),
					'last_payment_error' => array(
						'code'           => 'insufficient_funds',
						'payment_method' => array(
							'id'   => 'pm_terminal_failure',
							'type' => 'card_present',
						),
					),
				),
				array( 'id' => 'evt_terminal_failure' )
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Core-Terminalzahlung' ) );
	}

	/**
	 * @testdox A terminal decline on an order already failed is recorded once and notified once: $_dataName.
	 *
	 * Client 11.1.0 records every terminal decline (class-wc-payments-order-service.php:779-800): a note linked to its charge,
	 * since a reader retries on the same intent (:2139-2141), the intent status, and, on an order already failed, the two
	 * failed-order notifications WooCommerce does not fire for an unchanged status (:795-799). Each event object is the
	 * PaymentIntent Stripe sends with payment_intent.payment_failed (https://docs.stripe.com/api/payment_intents/object,
	 * last_payment_error), with the charges list client 11.1.0 reads the failed charge from
	 * (class-wc-payments-webhook-processing-service.php:446); each replay is a new delivery of the same intent.
	 *
	 * @dataProvider provider_terminal_declines_on_a_failed_order
	 *
	 * @param string $first_intent  Intent of a first decline that fails the pending order, or '' for an order already failed.
	 * @param string $second_intent Intent of the decline on the failed order.
	 */
	public function test_terminal_decline_on_a_failed_order_is_recorded_and_notified_once( string $first_intent, string $second_intent ): void {
		$order    = $this->create_woopayments_order();
		$declines = array();
		if ( '' === $first_intent ) {
			$order->set_status( OrderStatus::FAILED );
			$order->save();
		} else {
			$declines['ch_terminal_1'] = array( $first_intent, 'card_declined' );
		}
		$declines['ch_terminal_2'] = array( $second_intent, 'insufficient_funds' );

		foreach ( array( 'delivery', 'replay' ) as $delivery ) {
			foreach ( $declines as $charge_id => list( $intent_id, $code ) ) {
				$event = $this->create_payment_intent_event(
					'payment_intent.payment_failed',
					$order,
					array(
						'id'                 => $intent_id,
						'status'             => 'requires_payment_method',
						'charges'            => array( 'data' => array( array( 'id' => $charge_id ) ) ),
						'last_payment_error' => array(
							'code'           => $code,
							'payment_method' => array(
								'id'   => 'pm_' . $charge_id,
								'type' => 'card_present',
							),
						),
					),
					array( 'id' => "evt_{$charge_id}_{$delivery}" )
				);

				$this->assertSame(
					'delivery' === $delivery ? array( 1, 1 ) : array( 0, 0 ),
					$this->count_failed_order_notifications( fn() => $this->sut->process( $event ) ),
					"The $delivery of the $charge_id decline fires each failed-order notification this often."
				);
			}
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( array( '', 'requires_payment_method' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ) ) );
		foreach ( array_keys( $declines ) as $charge_id ) {
			$notes = array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 'A terminal payment of' ) && str_contains( $note->content, $charge_id )
			);
			$this->assertCount( 1, $notes, "The $charge_id decline has one note." );
		}
	}

	/**
	 * An order already failed, a second decline on a new intent, and a second decline on the same intent, as a reader retries.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_terminal_declines_on_a_failed_order(): array {
		return array(
			'an order already failed'             => array( '', 'pi_terminal_2' ),
			'a second decline on a new intent'    => array( 'pi_terminal_1', 'pi_terminal_2' ),
			'a second decline on the same intent' => array( 'pi_terminal_1', 'pi_terminal_1' ),
		);
	}

	/**
	 * Count the failed-order notifications fired while a callback runs.
	 *
	 * @param callable $callback Callback to run.
	 * @return array{int,int} The admin (pending to failed) and the customer (failed) notification counts.
	 */
	private function count_failed_order_notifications( callable $callback ): array {
		$admin    = did_action( 'woocommerce_order_status_pending_to_failed_notification' );
		$customer = did_action( 'woocommerce_order_status_failed_notification' );
		$callback();

		return array(
			did_action( 'woocommerce_order_status_pending_to_failed_notification' ) - $admin,
			did_action( 'woocommerce_order_status_failed_notification' ) - $customer,
		);
	}

	/**
	 * @testdox payment_intent.payment_failed leaves the order untouched when the failure is not one it acts on.
	 *
	 * @dataProvider provider_ignored_payment_failures
	 *
	 * @param array<string,string> $order_meta         Meta on the order before the event.
	 * @param array<string,mixed>  $intent             Intent fields of the event.
	 * @param string               $expected_intent_id `_intent_id` after the event.
	 */
	public function test_payment_intent_failed_ignores_failures_it_does_not_act_on( array $order_meta, array $intent, string $expected_intent_id ): void {
		$order = $this->create_woopayments_order();
		foreach ( $order_meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.payment_failed', $order, array_merge( array( 'status' => 'requires_payment_method' ), $intent ) ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( $expected_intent_id, $order->get_meta( '_intent_id', true ) );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Failures the order is not failed for.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,mixed>,2:string}>
	 */
	public function provider_ignored_payment_failures(): array {
		return array(
			'non-actionable payment method'        => array(
				array(),
				array(
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'ideal',
						),
					),
				),
				'',
			),
			'Klarna, no payment method ID'         => array( array(), array( 'last_payment_error' => array( 'payment_method' => array( 'type' => 'klarna' ) ) ), '' ),
			'BACS debit'                           => array(
				array(),
				array(
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'bacs_debit',
						),
					),
				),
				'',
			),
			'no payment method ID'                 => array( array(), array( 'last_payment_error' => array( 'payment_method' => array( 'type' => 'card' ) ) ), '' ),
			'superseded payment method and intent' => array(
				array(
					'_payment_method_id' => 'pm_current',
					'_intent_id'         => 'pi_current',
				),
				array(
					'id'                 => 'pi_stale',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_stale',
							'type' => 'card',
						),
					),
				),
				'pi_current',
			),
		);
	}

	/**
	 * @testdox payment_intent.payment_failed for a buy now, pay later payment method adds the declined-payment note once and changes nothing else: $_dataName.
	 *
	 * Client 11.3.0 adds the declined-payment note, and writes nothing else, when the failed payment method is an Affirm,
	 * Afterpay or Klarna one, is the order's stored payment method, and the order is not in a paid status; Failed orders are
	 * noted too, and the same text already on the order stops it (class-wc-payments-webhook-processing-service.php:532-534,
	 * :577-606; class-wc-payments-order-service.php:609-611, :620-656, :987-1000). Each row uses the error Stripe returned
	 * for a Klarna checkout cancelled on Klarna's test page.
	 *
	 * @dataProvider provider_buy_now_pay_later_failures
	 *
	 * @param string $payment_method_type   Failed payment method type in the event.
	 * @param string $status                Order status before the event.
	 * @param string $stored_payment_method `_payment_method_id` on the order.
	 * @param bool   $noted                 Whether the failure is noted.
	 */
	public function test_buy_now_pay_later_failure_is_noted_once_without_other_changes( string $payment_method_type, string $status, string $stored_payment_method, bool $noted ): void {
		$order          = $this->create_buy_now_pay_later_order( $payment_method_type, $stored_payment_method );
		$transaction_id = in_array( $status, wc_get_is_paid_statuses(), true ) ? 'pi_bnpl_attempt' : '';
		$order->set_transaction_id( $transaction_id );
		$order->set_status( $status );
		$order->save();
		$notes_before = $this->get_order_note_contents( $order );
		$event        = $this->create_buy_now_pay_later_failure_event( $order, $payment_method_type );

		foreach ( array( 'delivery', 'redelivery' ) as $delivery ) {
			$event['id']   = "evt_bnpl_failed_{$delivery}";
			$notifications = $this->count_failed_order_notifications( fn() => $this->sut->process( $event ) );

			$order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame(
				array( $status, $transaction_id, 'pi_bnpl_attempt', 'requires_action', $noted ? array( $this->get_buy_now_pay_later_declined_note( $order ) ) : array(), array( 0, 0 ) ),
				array( $order->get_status(), $order->get_transaction_id(), $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $this->get_notes_added_since( $order, $notes_before ), $notifications ),
				"Status, transaction ID, stored intent, intent status, added notes and failed-order notifications after the $delivery."
			);
		}
	}

	/**
	 * Buy now, pay later failures, the order they reach and whether they are noted.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public function provider_buy_now_pay_later_failures(): array {
		return array(
			'Affirm, Pending'                          => array( 'affirm', OrderStatus::PENDING, 'pm_bnpl_attempt', true ),
			'Afterpay, Pending'                        => array( 'afterpay_clearpay', OrderStatus::PENDING, 'pm_bnpl_attempt', true ),
			'Klarna, Pending'                          => array( 'klarna', OrderStatus::PENDING, 'pm_bnpl_attempt', true ),
			'Klarna, Failed'                           => array( 'klarna', OrderStatus::FAILED, 'pm_bnpl_attempt', true ),
			'Klarna, On hold'                          => array( 'klarna', OrderStatus::ON_HOLD, 'pm_bnpl_attempt', true ),
			'Klarna, Processing, paid by this payment' => array( 'klarna', OrderStatus::PROCESSING, 'pm_bnpl_attempt', false ),
			'Klarna, Completed, paid by this payment'  => array( 'klarna', OrderStatus::COMPLETED, 'pm_bnpl_attempt', false ),
			'Klarna, another payment method stored'    => array( 'klarna', OrderStatus::PENDING, 'pm_newer_attempt', false ),
			'Klarna, no payment method stored'         => array( 'klarna', OrderStatus::PENDING, '', false ),
		);
	}

	/**
	 * @testdox A buy now, pay later failure on an order whose payment is another one is recorded with one note and changes nothing else: $_dataName.
	 *
	 * An event on a payment that is not the order's is recorded only, and a failure on an order another payment method
	 * holds is not the order's payment. Client 11.3.0 writes nothing on a paid order, and notes an unpaid one held by
	 * another payment method (class-wc-payments-webhook-processing-service.php:585-606).
	 *
	 * @dataProvider provider_buy_now_pay_later_failures_of_another_payment
	 *
	 * @param string $gateway        The order's payment method.
	 * @param string $status         The order's status.
	 * @param string $transaction_id The order's transaction ID.
	 */
	public function test_buy_now_pay_later_failure_of_another_payment_is_recorded( string $gateway, string $status, string $transaction_id ): void {
		$order = $this->create_buy_now_pay_later_order( 'klarna', 'pm_bnpl_attempt' );
		$order->set_payment_method( $gateway );
		$order->set_transaction_id( $transaction_id );
		$order->set_status( $status );
		$order->save();
		$notes_before = $this->get_order_note_contents( $order );
		$event        = $this->create_buy_now_pay_later_failure_event( $order, 'klarna' );
		$record_note  = 'A WooPayments payment attempt (<a href="' . esc_url( $this->get_transaction_details_url( 'pi_bnpl_attempt' ) ) . '" target="_blank" rel="noopener noreferrer">pi_bnpl_attempt</a>) that does not pay this order failed.';

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame(
			array( $status, $transaction_id, 'pi_bnpl_attempt', 'requires_action', array( $record_note ) ),
			array( $order->get_status(), $order->get_transaction_id(), $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $this->get_notes_added_since( $order, $notes_before ) )
		);
	}

	/**
	 * Orders whose payment is not the failed attempt.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provider_buy_now_pay_later_failures_of_another_payment(): array {
		return array(
			'paid by card after the attempt'   => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, OrderStatus::PROCESSING, 'pi_card_payment' ),
			'a bank transfer holds it On hold' => array( 'bacs', OrderStatus::ON_HOLD, '' ),
		);
	}

	/**
	 * @testdox A buy now, pay later failure refused by a held order payment lock fails the delivery and adds its note on a later delivery.
	 */
	public function test_buy_now_pay_later_failure_refused_by_the_order_payment_lock_is_noted_later(): void {
		$order        = $this->create_buy_now_pay_later_order( 'klarna', 'pm_bnpl_attempt' );
		$notes_before = $this->get_order_note_contents( $order );
		$event        = $this->create_buy_now_pay_later_failure_event( $order, 'klarna' );
		$vocabulary   = new WooPaymentsPersistenceVocabulary();
		// The shopper's return from Klarna holds the lock while it fails the order.
		$this->hold_order_payment_lock( $order, $vocabulary, 'pi_bnpl_attempt' );

		$refusal = null;
		try {
			$this->sut->process( $event );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$refusal = $exception;
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$this->assertInstanceOf( OrderPaymentLockRefusedException::class, $refusal, 'A refused delivery must fail, so the store retries it instead of acknowledging it.' );
		$this->assertSame( array(), $this->get_notes_added_since( $order, $notes_before ), 'A refused delivery adds no note.' );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( array( $this->get_buy_now_pay_later_declined_note( $order ) ), $this->get_notes_added_since( $order, $notes_before ), 'A later delivery adds the note.' );
	}

	/**
	 * @testdox A buy now, pay later failure whose declined-payment note the WooPayments extension wrote in German adds no second note.
	 *
	 * Client 11.3.0 adds the note only when the same text is not on the order (class-wc-payments-webhook-processing-service.php:601-603).
	 */
	public function test_buy_now_pay_later_failure_recognises_the_german_woopayments_extension_note(): void {
		$translations = array(
			'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).' => '%1$s-Zahlung ist mit %2$s <strong>fehlgeschlagen</strong> (<a>%3$s</a>).',
			'With the following message: <code>%1$s</code>. Decline code: <code>%2$s</code>.' => 'Meldung: <code>%1$s</code>. Ablehnungscode: <code>%2$s</code>.',
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">More information</a>' => '<a href="%1$s" target="_blank" rel="noopener noreferrer">Mehr erfahren</a>',
		);
		$this->install_test_translations_for_domain( 'woocommerce', array_map( static fn( string $text ): string => 'Core-' . $text, $translations ) );
		$this->install_test_translations_for_domain( 'woocommerce-payments', array_map( static fn( string $text ): string => 'Plugin-' . $text, $translations ) );
		switch_to_locale( 'de_DE' );
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$order       = $this->create_buy_now_pay_later_order( 'klarna', 'pm_bnpl_attempt' );
		$plugin_note = 'Plugin-' . wc_price( 10.00, array( 'currency' => $order->get_currency() ) ) . '-Zahlung ist mit WooPayments <strong>fehlgeschlagen</strong> (<a href="' . $this->get_transaction_details_url( 'pi_bnpl_attempt' ) . '" target="_blank" rel="noopener noreferrer">pi_bnpl_attempt</a>). Plugin-Meldung: <code>Customer cancelled checkout on Klarna</code>. Ablehnungscode: <code>klarna_payment_declined</code>. Plugin-<a href="https://stripe.com/docs/error-codes/payment-method-customer-decline" target="_blank" rel="noopener noreferrer">Mehr erfahren</a>';
		$order->add_order_note( $plugin_note );
		$notes_before = $this->get_order_note_contents( $order );

		$this->sut->process( $this->create_buy_now_pay_later_failure_event( $order, 'klarna' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertSame( array(), $this->get_notes_added_since( $order, $notes_before ) );
	}

	/**
	 * Create a WooPayments order a buy now, pay later checkout started: its split gateway, the attempt's intent and payment
	 * method, and the intent status the started payment stores.
	 *
	 * @param string $payment_method_type   Buy now, pay later payment method type.
	 * @param string $stored_payment_method `_payment_method_id` on the order.
	 * @return WC_Order
	 */
	private function create_buy_now_pay_later_order( string $payment_method_type, string $stored_payment_method ): WC_Order {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . $payment_method_type );
		$order->update_meta_data( '_payment_method_id', $stored_payment_method );
		$order->update_meta_data( '_intent_id', 'pi_bnpl_attempt' );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->save();

		return $order;
	}

	/**
	 * Create the payment_intent.payment_failed event of a buy now, pay later attempt.
	 *
	 * The error is the one Stripe returned when a shopper closed Klarna's test page: its code, decline code, message and
	 * documentation link, on the attempt's payment method.
	 *
	 * @param WC_Order $order               Order of the attempt.
	 * @param string   $payment_method_type Failed payment method type.
	 * @return array<string,mixed>
	 */
	private function create_buy_now_pay_later_failure_event( WC_Order $order, string $payment_method_type ): array {
		return $this->create_payment_intent_event(
			'payment_intent.payment_failed',
			$order,
			array(
				'id'                 => 'pi_bnpl_attempt',
				'status'             => 'requires_payment_method',
				'last_payment_error' => array(
					'code'           => 'payment_intent_authentication_failure',
					'decline_code'   => 'klarna_payment_declined',
					'doc_url'        => 'https://stripe.com/docs/error-codes/payment-method-customer-decline',
					'message'        => 'Customer cancelled checkout on Klarna',
					'payment_method' => array(
						'id'   => 'pm_bnpl_attempt',
						'type' => $payment_method_type,
					),
				),
			),
			array( 'id' => 'evt_bnpl_failed' )
		);
	}

	/**
	 * The declined-payment note client 11.3.0 writes for the buy now, pay later failure event, on the order's total
	 * (class-wc-payments-order-service.php:609-611, :620-656, :2646-2667).
	 *
	 * @param WC_Order $order Order of the attempt.
	 * @return string
	 */
	private function get_buy_now_pay_later_declined_note( WC_Order $order ): string {
		return 'A payment of ' . wc_price( 10.00, array( 'currency' => $order->get_currency() ) ) . ' <strong>failed</strong> using WooPayments (<a href="' . $this->get_transaction_details_url( 'pi_bnpl_attempt' ) . '" target="_blank" rel="noopener noreferrer">pi_bnpl_attempt</a>). With the following message: <code>Customer cancelled checkout on Klarna</code>. Decline code: <code>klarna_payment_declined</code>. <a href="https://stripe.com/docs/error-codes/payment-method-customer-decline" target="_blank" rel="noopener noreferrer">More information</a>';
	}

	/**
	 * The WooPayments transaction details URL of a payment.
	 *
	 * @param string $payment_id Payment intent or charge ID.
	 * @return string
	 */
	private function get_transaction_details_url( string $payment_id ): string {
		return add_query_arg(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( '/payments/transactions/details' ),
				'id'   => $payment_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get the contents of every note on the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string[]
	 */
	private function get_order_note_contents( WC_Order $order ): array {
		return array_map(
			static fn( $note ): string => (string) $note->content,
			wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'type'     => 'any',
				)
			)
		);
	}

	/**
	 * Get the contents of the notes added to the order since an earlier read of its notes.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string[] $before Note contents read earlier.
	 * @return string[]
	 */
	private function get_notes_added_since( WC_Order $order, array $before ): array {
		$added = $this->get_order_note_contents( $order );
		foreach ( $before as $content ) {
			$index = array_search( $content, $added, true );
			if ( false !== $index ) {
				unset( $added[ $index ] );
			}
		}

		return array_values( $added );
	}

	/**
	 * @testdox payment_intent.canceled is a successful order no-op.
	 *
	 * The event is the canceled authorization the platform forwarded (Fixtures/platform-payment-intent-events.json), naming the order.
	 */
	public function test_payment_intent_canceled_is_successful_order_noop(): void {
		// The recorded events are test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_transaction_fee', '100' );
		$order->update_meta_data( '_wcpay_net', '900' );
		$order->save();

		$this->sut->process( $this->recorded_payment_intent_event( 'in_person_canceled', $order ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '100', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '900', $order->get_meta( '_wcpay_net', true ) );
	}

	/**
	 * @testdox An authorization puts a $_dataName order on hold once, with its payment recorded.
	 *
	 * The event is the authorization the platform forwarded for a manual-capture card checkout
	 * (Fixtures/platform-payment-intent-events.json). Client 11.3.0 attaches the intent and holds a pending or failed order
	 * whose stored intent is empty or the event's (class-wc-payments-webhook-processing-service.php:473-500): the
	 * authorized note, or the held-for-review note on a review outcome (class-wc-payments-order-service.php:492-498,
	 * :2719, :2897). The recorded authorization carries allow; the review row's outcome is set by the test, not recorded,
	 * to reach the client's held-for-review branch (:492-498).
	 *
	 * @dataProvider provider_orders_an_authorization_holds
	 *
	 * @param string $status            Order status when the authorization arrives.
	 * @param bool   $stores_the_intent Whether the order already stores the authorized intent.
	 * @param string $fraud_outcome     Fraud outcome the intent carries.
	 * @param string $note_fragment     Text of the note the hold adds.
	 */
	public function test_authorization_holds_the_order_it_pays( string $status, bool $stores_the_intent, string $fraud_outcome, string $note_fragment ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_authorization_event( $order, $fraud_outcome );
		$intent = $event['data']['object'];
		$order->set_status( $status );
		if ( $stores_the_intent ) {
			$order->update_meta_data( '_intent_id', $intent['id'] );
		}
		$order->save();
		$this->seed_authorization_summary_caches();

		$this->sut->process( $event );
		$this->assertAuthorizationSummaryCachesCleared( 'payment_intent.amount_capturable_updated' );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( $intent['id'], $order->get_transaction_id() );
		$this->assertSame( $intent['id'], $order->get_meta( '_intent_id', true ) );
		$this->assertSame( $intent['charges']['data'][0]['id'], $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $fraud_outcome, $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, $note_fragment ), 'A second delivery adds no second note.' );
	}

	/** @return array<string,array{string,bool,string,string}> */
	public function provider_orders_an_authorization_holds(): array {
		return array(
			'pending, waiting on this payment' => array( OrderStatus::PENDING, true, 'allow', 'was <strong>authorized</strong>' ),
			'failed, waiting on this payment'  => array( OrderStatus::FAILED, true, 'allow', 'was <strong>authorized</strong>' ),
			'pending, no payment stored yet'   => array( OrderStatus::PENDING, false, 'allow', 'was <strong>authorized</strong>' ),
			'pending, held for a fraud review' => array( OrderStatus::PENDING, true, 'review', 'was <strong>held for review</strong>' ),
		);
	}

	/**
	 * @testdox An authorization of another payment than the $status order waits on adds one note naming both, and changes nothing else.
	 *
	 * Client 11.3.0 adds this note and leaves the order (class-wc-payments-webhook-processing-service.php:477-496).
	 *
	 * @testWith ["pending"]
	 *           ["failed"]
	 *
	 * @param string $status Order status when the authorization arrives.
	 */
	public function test_authorization_of_another_payment_leaves_the_order_waiting( string $status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_authorization_event( $order );
		$intent = $event['data']['object'];
		$order->set_status( $status );
		$order->update_meta_data( '_intent_id', 'pi_current_attempt' );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->save();

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( 'pi_current_attempt', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame(
			1,
			$this->count_order_notes_matching(
				$order,
				sprintf(
					'A WooPayments payment <code>%1$s</code> was authorized, but this order is waiting on payment <code>%2$s</code>, so the order was not updated. Unless the order is later updated with payment <code>%1$s</code>, its authorization is not captured and expires on its own.',
					$intent['id'],
					'pi_current_attempt'
				)
			),
			'The note is added once, however often the event is delivered.'
		);
	}

	/**
	 * @testdox An authorization delivered again after its authorization expired leaves the failed order failed, with no second note.
	 *
	 * Client 11.3.0's authorization writer returns before any write once its note is on the order
	 * (class-wc-payments-order-service.php:2146-2151), so the expiry's failed status stands (:699-716).
	 */
	public function test_authorization_delivered_again_after_its_expiry_leaves_the_order_failed(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_authorization_event( $order );
		$intent = $event['data']['object'];
		$order->update_meta_data( '_intent_id', $intent['id'] );
		$order->save();
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );

		$sut->process( $event );
		$this->assertSame( OrderStatus::ON_HOLD, wc_get_order( $order->get_id() )->get_status() );
		$sut->process(
			array(
				'id'   => 'evt_authorization_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => $intent['charges']['data'][0]['id'],
						'payment_intent' => $intent['id'],
					),
				),
			)
		);
		$this->assertSame( OrderStatus::FAILED, wc_get_order( $order->get_id() )->get_status() );
		$sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was <strong>authorized</strong>' ) );
	}

	/**
	 * @testdox An authorization changes nothing on $_dataName, and clears the authorization caches.
	 *
	 * Client 11.3.0 returns before any order change for these (class-wc-payments-webhook-processing-service.php:442-451,
	 * :473-475).
	 *
	 * @dataProvider provider_authorizations_that_change_nothing
	 *
	 * @param string $status        Order status when the authorization arrives.
	 * @param string $charge_type   The charge's payment method type.
	 * @param string $intent_status The intent's status.
	 */
	public function test_authorization_changes_nothing( string $status, string $charge_type, string $intent_status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->register_custom_paid_status();
		$order            = $this->create_woopayments_order();
		$event            = $this->recorded_authorization_event( $order );
		$intent           = &$event['data']['object'];
		$intent['status'] = $intent_status;
		$intent['charges']['data'][0]['payment_method_details']['type'] = $charge_type;
		// The checkout that created the intent stored it as the order's payment and transaction ID.
		$order->set_status( $status );
		$order->set_transaction_id( $intent['id'] );
		$order->update_meta_data( '_intent_id', $intent['id'] );
		$order->save();
		$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->seed_authorization_summary_caches();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertAuthorizationSummaryCachesCleared( 'payment_intent.amount_capturable_updated' );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/** @return array<string,array{string,string,string}> */
	public function provider_authorizations_that_change_nothing(): array {
		return array(
			'an order already on hold'       => array( OrderStatus::ON_HOLD, 'card', 'requires_capture' ),
			'a cancelled order'              => array( OrderStatus::CANCELLED, 'card', 'requires_capture' ),
			'an order already paid'          => array( OrderStatus::PROCESSING, 'card', 'requires_capture' ),
			'an order already Completed'     => array( OrderStatus::COMPLETED, 'card', 'requires_capture' ),
			'a paid status a plugin adds'    => array( 'shipped', 'card', 'requires_capture' ),
			'an in-person card payment'      => array( OrderStatus::PENDING, 'card_present', 'requires_capture' ),
			'an in-person Interac payment'   => array( OrderStatus::PENDING, 'interac_present', 'requires_capture' ),
			'an intent not awaiting capture' => array( OrderStatus::PENDING, 'card', 'succeeded' ),
		);
	}

	/**
	 * @testdox An authorization on an unpaid order of another payment method is recorded with one note and changes nothing else, whether the order stores the intent: $stores_the_intent.
	 *
	 * An authorization holds only an order paid with WooPayments. Client 11.3.0 leaves an order another payment method
	 * took over to its superseded-attempt check (class-wc-payments-webhook-processing-service.php:464-471); without a
	 * stored intent it puts such an order on hold (:477-500).
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $stores_the_intent Whether the order stores the authorized intent, from an earlier WooPayments attempt.
	 */
	public function test_authorization_on_an_order_of_another_payment_method_is_recorded( bool $stores_the_intent ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_woopayments_order();
		$event  = $this->recorded_authorization_event( $order );
		$intent = $event['data']['object'];
		$order->set_payment_method( 'bacs' );
		if ( $stores_the_intent ) {
			$order->update_meta_data( '_intent_id', $intent['id'] );
		}
		$order->save();

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( $stores_the_intent ? $intent['id'] : '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_transaction_id() );
		$notes = $this->get_order_notes_containing( $order, 'that does not pay this order was authorized' );
		$this->assertCount( 1, $notes, 'The record is added once, however often the event is delivered.' );
		$this->assertStringContainsString( $intent['id'], (string) $notes[0]->content );
	}

	/**
	 * @testdox A payment failure of the attempt an order of another payment method stores is recorded with one note and leaves the order as it is: $_dataName.
	 *
	 * Classic order-pay saves the gateway the shopper picks as the order's payment method (plugins/woocommerce/includes/class-wc-form-handler.php:794-795)
	 * and leaves the abandoned WooPayments attempt stored on the order. Client 11.3.0's failed handler checks only the
	 * failed payment method (class-wc-payments-webhook-processing-service.php:548) and fails such an order unless it is
	 * Processing or Completed (class-wc-payments-order-service.php:545-560). Here it is recorded as a payment that does not
	 * pay the order, as an authorization on such an order is.
	 *
	 * @dataProvider provider_orders_another_payment_method_holds
	 *
	 * @param string $gateway        The order's payment method.
	 * @param string $status         The order's status after that payment method took it.
	 * @param string $transaction_id The order's transaction ID: the attempt's own from its checkout, or none.
	 */
	public function test_failure_of_the_attempt_an_order_of_another_payment_method_stores_is_recorded( string $gateway, string $status, string $transaction_id ): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( $gateway );
		$order->set_status( $status );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();
		$notes  = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$logger = RecordingWcLogger::install();
		$event  = $this->create_payment_intent_event( 'payment_intent.payment_failed', $order, $this->get_card_failure_overrides() );

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( $gateway, $order->get_payment_method() );
		$this->assertSame( $transaction_id, $order->get_transaction_id() );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( $notes + 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'The record note only, once however often the event is delivered.' );
		$record_notes = $this->get_order_notes_containing( $order, 'that does not pay this order failed' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'pi_123', $record_notes[0]->content );
		$this->assertCount( 2, array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 0 === strpos( $line[1], 'other charge recorded: order ' . $order->get_id() . ', payment_intent.payment_failed' ) ), 'One warning line per delivery.' );
	}

	/**
	 * Orders another payment method holds while they store an abandoned WooPayments attempt.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provider_orders_another_payment_method_holds(): array {
		return array(
			'bank transfer holds it On hold'             => array( 'bacs', OrderStatus::ON_HOLD, 'pi_123' ),
			'a cheque holds it On hold'                  => array( 'cheque', OrderStatus::ON_HOLD, 'pi_123' ),
			'cash on delivery made it Processing'        => array( 'cod', OrderStatus::PROCESSING, 'pi_123' ),
			'another gateway still waits on its payment' => array( 'stripe', OrderStatus::PENDING, '' ),
		);
	}

	/**
	 * @testdox A payment failure of the attempt an order still paid with a WooPayments gateway stores fails the order once, as before: $gateway.
	 *
	 * Client 11.3.0 fails the order with one failure note (class-wc-payments-webhook-processing-service.php:556-559,
	 * class-wc-payments-order-service.php:545-560); a split gateway's ID names the payment method after the card gateway's
	 * (class-wc-payment-gateway-wcpay.php:3071-3077).
	 *
	 * @testWith ["woocommerce_payments"]
	 *           ["woocommerce_payments_link"]
	 *
	 * @param string $gateway The order's WooPayments gateway.
	 */
	public function test_failure_of_the_attempt_an_order_still_paid_with_woopayments_stores_fails_it( string $gateway ): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( $gateway );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_intention_status', 'requires_action' );
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();
		$notes = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$event = $this->create_payment_intent_event( 'payment_intent.payment_failed', $order, $this->get_card_failure_overrides() );

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( $notes + 2, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'The failure note and the status change note, once.' );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, '<strong>failed</strong> using WooPayments' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * Intent overrides for a card payment's failure, as payment_intent.payment_failed carries it.
	 *
	 * @return array<string,mixed>
	 */
	private function get_card_failure_overrides(): array {
		return array(
			'status'             => 'requires_payment_method',
			'last_payment_error' => array(
				'code'           => 'card_declined',
				'message'        => 'Your card was declined.',
				'payment_method' => array(
					'id'   => 'pm_123',
					'type' => 'card',
				),
			),
		);
	}

	/**
	 * @testdox An order an authorization put on hold, captured from the store for less than was authorized, keeps one capture note and gets no success or hold note when the succeeded payment arrives.
	 *
	 * Client 11.3.0 completes an order whose stored intent status is requires_capture through its capture, whatever the
	 * captured amount (class-wc-payments-order-service.php:484-485, :2774), and adds no success note once the capture note
	 * is on the order (:2040-2046).
	 */
	public function test_order_held_by_an_authorization_completes_on_a_partial_capture(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event = $this->recorded_authorization_event( $order );
		$this->sut->process( $event );
		$this->assertSame( OrderStatus::ON_HOLD, wc_get_order( $order->get_id() )->get_status() );

		$captured                                   = $event['data']['object'];
		$captured['status']                         = 'succeeded';
		$captured['amount_capturable']              = 0;
		$captured['amount_received']                = 800;
		$captured['charges']['data'][0]['captured'] = true;
		$captured['charges']['data'][0]['amount_captured'] = 800;
		$platform = new class( $event['data']['object'], $captured ) extends Client {
			/**
			 * Intent before the capture.
			 *
			 * @var array<string,mixed>
			 */
			private array $authorized;

			/**
			 * Intent after the capture.
			 *
			 * @var array<string,mixed>
			 */
			private array $captured;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $authorized Intent before the capture.
			 * @param array<string,mixed> $captured   Intent after the capture.
			 */
			public function __construct( array $authorized, array $captured ) {
				$this->authorized = $authorized;
				$this->captured   = $captured;
			}

			/**
			 * Report the platform as reachable.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Answer an intent read with the authorized intent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				unset( $intent_id );
				return $this->authorized;
			}

			/**
			 * Answer a capture with the captured intent.
			 *
			 * @param string              $intent_id         Intent ID.
			 * @param int                 $amount_to_capture Amount.
			 * @param array<string,mixed> $metadata          Metadata.
			 * @param array<string,mixed> $level3            Level 3 data.
			 * @return array<string,mixed>
			 */
			public function capture_intention( string $intent_id, int $amount_to_capture, array $metadata = array(), array $level3 = array() ): array {
				unset( $intent_id, $amount_to_capture, $metadata, $level3 );
				return $this->captured;
			}
		};
		$held     = wc_get_order( $order->get_id() );
		$held->set_total( '8.00' );
		$held->save();
		wc_get_container()->replace( Client::class, $platform );
		$this->reset_container_resolutions();
		try {
			wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_woocommerce_order_action_capture_charge( wc_get_order( $order->get_id() ) );
			$event['id']             = 'evt_partial_capture_succeeded';
			$event['type']           = 'payment_intent.succeeded';
			$event['data']['object'] = $captured;
			wc_get_container()->get( EventIngestor::class )->process( $event );
		} finally {
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assert_no_payment_success_note( $order );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'but the order total is' ) );
	}

	/**
	 * @testdox An order an authorization put on hold completes as a capture when the payment succeeds while the order still stores the authorization, captured for less than was authorized, with one capture note and no success or hold note.
	 *
	 * The capture was made outside the store, so the succeeded payment is the first the order hears of it. Client 11.3.0
	 * completes an order whose stored intent status is requires_capture with its capture writer, whatever the captured
	 * amount: the capture note, the fraud box from the stored fraud outcome, and the Fee details job
	 * (class-wc-payments-order-service.php:484-485, :2193-2219).
	 */
	public function test_order_held_by_an_authorization_completes_as_a_capture_on_a_succeeded_payment(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event = $this->recorded_authorization_event( $order );
		$this->sut->process( $event );
		$held = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $held->get_status() );
		$held->set_total( '8.00' );
		$held->save();

		$captured                                   = $event['data']['object'];
		$captured['status']                         = 'succeeded';
		$captured['amount_capturable']              = 0;
		$captured['amount_received']                = 800;
		$captured['charges']['data'][0]['captured'] = true;
		$captured['charges']['data'][0]['amount_captured'] = 800;
		$event['id']                                       = 'evt_capture_outside_the_store';
		$event['type']                                     = 'payment_intent.succeeded';
		$event['data']['object']                           = $captured;
		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $captured['id'], $order->get_transaction_id() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'allow', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assert_no_payment_success_note( $order );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'but the order total is' ) );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
					'args'   => array(
						'order_id'     => $order->get_id(),
						'intent_id'    => $captured['id'],
						'is_test_mode' => true,
					),
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
	}

	/**
	 * @testdox A succeeded payment on $_dataName completes the order as a $completion, with the refused-capture line: $condition.
	 *
	 * Client 11.3.0 completes an order that stores requires_capture with its capture writer, whatever intent the order
	 * stores and whatever its transaction ID names (class-wc-payments-order-service.php:484-489, :2193-2219), and logs
	 * nothing about it. The built-in store completes it as a capture only when the order stored the arriving intent before
	 * the event and its transaction ID names it. When the order still stores requires_capture at the decision and either
	 * fails, the payment completes the order as a payment, its amount checked, with one warning naming the condition,
	 * whatever the logging setting. On an order storing another intent, the payment's whole record is written first, so the
	 * order no longer stores requires_capture: a payment, with the line of that record instead. The event is the recorded
	 * authorization (Fixtures/platform-payment-intent-events.json) captured in full.
	 *
	 * @dataProvider provider_capture_choices_over_a_stored_authorization
	 *
	 * @param string $stored_intent  The intent the order stores before the event: '' for none, 'arriving' for the event's.
	 * @param string $transaction_id The order's transaction ID: 'arriving' for the event's intent.
	 * @param bool   $logging        Whether WooPayments logging is on.
	 * @param string $condition      The condition the line names, or '' for no line.
	 * @param string $completion     `capture` or `payment`.
	 */
	public function test_capture_choice_over_a_stored_authorization_logs_a_refused_capture( string $stored_intent, string $transaction_id, bool $logging, string $condition, string $completion ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		if ( $logging ) {
			self::enable_woopayments_debug_logging();
		} else {
			add_filter( 'wcpay_dev_mode', '__return_false' );
		}
		$event          = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'manual_capture_amount_capturable_updated' );
		$intent_id      = (string) $event['data']['object']['id'];
		$charge_id      = (string) $event['data']['object']['charges']['data'][0]['id'];
		$transaction_id = 'arriving' === $transaction_id ? $intent_id : $transaction_id;
		if ( 'arriving' !== $stored_intent ) {
			$order = StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::ON_HOLD, $transaction_id, $stored_intent );
		} elseif ( $intent_id === $transaction_id ) {
			$order = StoredPaymentRecords::authorization( $intent_id, $charge_id );
		} else {
			$order = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( $transaction_id, $intent_id, 'requires_capture', OrderStatus::ON_HOLD, $charge_id );
		}
		$event  = $this->recorded_capture_event( $order );
		$logger = RecordingWcLogger::install();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $intent_id, $order->get_transaction_id() );
		$this->assertSame( $intent_id, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 'capture' === $completion ? 1 : 0, $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertCount( 'payment' === $completion ? 1 : 0, array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) );
		$lines = $this->get_refused_capture_lines( $logger );
		if ( '' === $condition ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$message = sprintf(
			'Payment %1$s on order #%2$d was not treated as the capture of an authorization: the order stores requires_capture, but %3$s.',
			$intent_id,
			$order->get_id(),
			'stored_intent' === $condition ? 'the payment is not the intent the order stored before this event' : 'the transaction ID does not name it'
		);
		$context = $this->get_logged_context( $logger, $message );
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'warning', 'woopayments' ), array( $lines[0][0], $lines[0][2] ) );
		$this->assertSame(
			array(
				'order_id'         => $order->get_id(),
				'intent_id'        => $intent_id,
				'stored_intent_id' => '' === $stored_intent ? 'none' : $intent_id,
				'transaction_id'   => $transaction_id,
				'condition'        => $condition,
				'reason'           => 'success_not_a_capture_of_the_stored_authorization',
			),
			array_intersect_key( $context, array_flip( array( 'order_id', 'intent_id', 'stored_intent_id', 'transaction_id', 'condition', 'reason' ) ) )
		);
		$this->assertSame( array(), array_values( array_filter( $logger->lines, static fn( array $line ): bool => false !== strpos( $line[1], 'over the stored intent' ) ) ), 'Never beside the line of a whole record written over another attempt.' );
	}

	/**
	 * Stored intents and transaction IDs beside a stored authorization, and the logging setting.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:string,4:string}>
	 */
	public function provider_capture_choices_over_a_stored_authorization(): array {
		return array(
			'an order storing another intent'              => array( 'pi_anotherAttempt', 'arriving', true, '', 'payment' ),
			'an order storing no intent'                   => array( '', 'arriving', true, 'stored_intent', 'payment' ),
			'an order storing no intent, with logging off' => array( '', 'arriving', false, 'stored_intent', 'payment' ),
			"an order storing the arriving intent beside another attempt's transaction ID" => array( 'arriving', 'pi_earlier', true, 'transaction_id', 'payment' ),
			'an order storing the arriving intent'         => array( 'arriving', 'arriving', true, '', 'capture' ),
		);
	}

	/**
	 * Get the lines of a succeeded payment not treated as the capture of the order's stored authorization.
	 *
	 * @param RecordingWcLogger $logger Recording logger.
	 * @return array<int,array<int,mixed>>
	 */
	private function get_refused_capture_lines( RecordingWcLogger $logger ): array {
		return array_values( array_filter( $logger->lines, static fn( array $line ): bool => false !== strpos( (string) $line[1], 'was not treated as the capture of an authorization' ) ) );
	}

	/**
	 * @testdox A succeeded payment on an order already paid, storing another intent's authorization status, stores its succeeded status first, so no capture is chosen and no refused-capture line is written.
	 *
	 * The order's transaction ID names the arriving payment, so the event is the order's own. On an order in a paid status
	 * the webhook stores the payment's succeeded status with its charge, before the capture choice reads the stored
	 * status, and the lifecycle then skips the success, since the webhook leaves a paid order as it is (client 11.3.0
	 * class-wc-payments-webhook-processing-service.php:642-716, class-wc-payments-order-service.php:475).
	 */
	public function test_capture_choice_on_an_already_paid_order_is_not_made(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$event      = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'manual_capture_amount_capturable_updated' );
		$intent_id  = (string) $event['data']['object']['id'];
		$order      = StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::PROCESSING, $intent_id, 'pi_anotherAttempt' );
		$date_paid  = $order->get_date_paid();
		$notes      = array(
			'order_id' => $order->get_id(),
			'type'     => 'any',
		);
		$note_count = count( wc_get_order_notes( $notes ) );
		$event      = $this->recorded_capture_event( $order );
		$logger     = RecordingWcLogger::install();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( $date_paid ? $date_paid->getTimestamp() : null, $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : null );
		$this->assertSame( $intent_id, $order->get_transaction_id() );
		$this->assertCount( $note_count + 1, wc_get_order_notes( $notes ), 'The skipped event adds only the paid-order note.' );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'already had a paid status' ) );
		$this->assertSame( 'order_paid', $this->get_logged_context( $logger, 'Payment event skipped: the order is already paid, so the event does not change it.' )['reason'] ?? '' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( array(), $this->get_refused_capture_lines( $logger ) );
	}

	/**
	 * @testdox A succeeded payment its caller holds, on an order storing requires_capture, is held with the caller's note and no capture note: $_dataName.
	 *
	 * Stripe Billing's paid invoice passes its hold note when the invoice does not match the renewal; the hold is decided
	 * there, so the order is not completed as a capture even when the payment is the authorization the order recorded, and
	 * no capture choice is logged. The payment's meta is a held payment's: on an order already On hold the fraud box shows
	 * the review as allowed, as client 11.3.0's success writer stamps it (class-wc-payments-order-service.php:2056-2060),
	 * not the capture writer's stamp from the stored outcome. The order keeps requires_capture at the decision: it stores
	 * the arriving intent, or none.
	 *
	 * @testWith ["the authorization the order recorded", "arriving", "review_allowed"]
	 *           ["no intent stored, the transaction ID naming the payment", "", "allow"]
	 *
	 * @param string $case_name     Case name.
	 * @param string $stored_intent `arriving` for the arriving intent and its charge, or '' for no intent.
	 * @param string $fraud_box     The fraud meta box type the payment writes.
	 */
	public function test_succeeded_payment_held_by_its_caller_logs_no_capture_choice( string $case_name, string $stored_intent, string $fraud_box ): void {
		unset( $case_name );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		self::enable_woopayments_debug_logging();
		$event     = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'manual_capture_amount_capturable_updated' );
		$intent_id = (string) $event['data']['object']['id'];
		$charge_id = (string) $event['data']['object']['charges']['data'][0]['id'];
		$order     = 'arriving' === $stored_intent
			? StoredPaymentRecords::authorization( $intent_id, $charge_id )
			: StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::PENDING, $intent_id, '', '' );
		$intent    = $this->recorded_capture_event( $order )['data']['object'];
		$logger    = RecordingWcLogger::install();

		wc_get_container()->get( PaymentIntentEventHandler::class )->apply_succeeded_payment_intent_under_lock( $order, $intent, array( 'The invoice was paid with a different amount.' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'The invoice was paid with a different amount.' ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'but the order total is' ) );
		$this->assertSame( $fraud_box, $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( array(), array_filter( $logger->lines, static fn( array $line ): bool => false !== strpos( $line[1], 'capture of an authorization' ) ) );
	}

	/**
	 * @testdox A succeeded payment on $_dataName is $expected_completion, delivered twice, with the refused-capture line: $condition.
	 *
	 * Completed as the capture of an authorization, with the capture note and no amount check, only when the order stores
	 * requires_capture, the payment is the intent the order stored before the event, and the transaction ID names it. Any
	 * other success is a payment: completed with its success note when it pays the total, held with the amount note when
	 * it does not. A payment that meets requires_capture at its decision writes one line naming the condition that failed;
	 * a payment whose whole record was written over another attempt meets its own succeeded status instead, and never
	 * writes both lines. Client 11.3.0 completes every order storing requires_capture as a capture, amount not checked
	 * (class-wc-payments-order-service.php:484-488). The order total is 10.99.
	 *
	 * @dataProvider provider_successes_over_a_stored_authorization
	 *
	 * @param string $shape               The order before the event.
	 * @param string $intent_id           The arriving intent; its charge is `ch_` and the same suffix.
	 * @param int    $amount              The payment's amount in minor units.
	 * @param string $expected_completion `a capture`, `a payment` or `held`.
	 * @param string $condition           The condition the refused-capture line names, `none` for no line, or `another attempt` for the line of a whole record written over another attempt instead.
	 */
	public function test_success_over_a_stored_authorization_is_a_capture_only_of_the_recorded_authorization( string $shape, string $intent_id, int $amount, string $expected_completion, string $condition ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order  = $this->create_order_storing_an_authorization( $shape );
		$logger = RecordingWcLogger::install();
		$event  = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'id'      => $intent_id,
				'amount'  => $amount,
				'charges' => array( 'data' => array( array( 'id' => str_replace( 'pi_', 'ch_', $intent_id ) ) ) ),
			)
		);

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame(
			array(
				'held' === $expected_completion ? OrderStatus::ON_HOLD : OrderStatus::COMPLETED,
				'a capture' === $expected_completion ? 1 : 0,
				'a payment' === $expected_completion ? 1 : 0,
				'held' === $expected_completion ? 1 : 0,
			),
			array(
				$order->get_status(),
				count( $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) ),
				count( array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) ),
				count( $this->get_order_notes_containing( $order, 'but the order total is' ) ),
			),
			'Order status, capture notes, payment notes, amount notes.'
		);
		$this->assertSame( array( $intent_id, 'succeeded' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ) ) );
		$this->assertSame(
			array(
				in_array( $condition, array( 'none', 'another attempt' ), true ) ? array() : array( $condition ),
				'another attempt' === $condition ? 1 : 0,
			),
			array(
				array_map( fn( array $line ): string => (string) ( $this->get_logged_context( $logger, (string) $line[1] )['condition'] ?? '' ), $this->get_refused_capture_lines( $logger ) ),
				count( array_filter( $logger->lines, static fn( array $line ): bool => false !== strpos( (string) $line[1], 'over the stored intent' ) ) ),
			),
			'The refused-capture lines by condition, and the lines of a whole record written over another attempt.'
		);
	}

	/**
	 * Orders storing requires_capture, the success that arrives, what it is and the line it writes.
	 *
	 * @return array<string,array{string,string,int,string,string}>
	 */
	public function provider_successes_over_a_stored_authorization(): array {
		return array(
			'the recorded authorization, captured in full' => array( 'authorization', 'pi_A', 1099, 'a capture', 'none' ),
			'the recorded authorization, captured for less than an edited total' => array( 'authorization', 'pi_A', 800, 'a capture', 'none' ),
			'an authorization whose transaction ID names its charge' => array( 'charge transaction ID', 'pi_A', 800, 'a capture', 'none' ),
			'an authorization with no transaction ID, another amount' => array( 'no transaction ID', 'pi_A', 800, 'held', 'transaction_id' ),
			'an authorization with a retyped transaction ID, the total' => array( 'retyped transaction ID', 'pi_A', 1099, 'a payment', 'transaction_id' ),
			'an already mixed record, its intent for another amount' => array( 'already mixed', 'pi_B', 1500, 'held', 'transaction_id' ),
			'an already mixed record, its intent for the total' => array( 'already mixed', 'pi_B', 1099, 'a payment', 'transaction_id' ),
			'a Pending order with a paid date storing another attempt, the earlier attempt for another amount' => array( 'H2 with a paid date', 'pi_A', 1500, 'held', 'another attempt' ),
			'a Failed order with a paid date storing a declined retry, the genuine capture for less than an edited total' => array( 'declined retry with a paid date', 'pi_A', 800, 'held', 'another attempt' ),
			"a payment's whole record written over another attempt, redelivered for another amount" => array( 'whole record over another attempt', 'pi_B', 1500, 'held', 'none' ),
			"a payment's whole record on a Failed order whose transaction ID names it, redelivered for another amount" => array( 'whole record named by the transaction ID', 'pi_B', 1500, 'held', 'none' ),
			'no intent stored, the transaction ID naming the payment' => array( 'no intent stored', 'pi_B', 1500, 'held', 'stored_intent' ),
			"an authorization over a kept payment, the authorization's capture" => array( 'authorization over a kept payment', 'pi_B', 800, 'held', 'another attempt' ),
			'an authorization over a kept payment with no transaction ID, the kept payment again' => array( 'authorization over a kept payment without a transaction ID', 'pi_K', 1500, 'held', 'transaction_id' ),
			"the duplicate check's authorization with another attempt's transaction ID" => array( "authorization with another attempt's transaction ID", 'pi_B', 1500, 'held', 'transaction_id' ),
		);
	}

	/**
	 * Create a WooPayments order storing requires_capture, from StoredPaymentRecords.
	 *
	 * @param string $shape `authorization` (pi_A, On hold); `charge transaction ID` (its transaction ID names ch_A); `no
	 *                      transaction ID`; `retyped transaction ID`; `already mixed` (pi_B's intent and charge beside pi_A's
	 *                      authorization and transaction ID, written by a success's run stopped after its first write);
	 *                      `H2 with a paid date` (a Pending order whose transaction ID names an earlier attempt pi_A and which
	 *                      stores the authorization pi_B); `declined retry with a paid date` (the extension's declined retry
	 *                      pi_B stored over authorization pi_A on a Failed order); `whole record over another attempt` (pi_B
	 *                      stored with succeeded beside pi_A's transaction ID, as a success over another attempt writes it); `whole record named by the
	 *                      transaction ID` (the same on a Failed order whose transaction ID names pi_B); `no intent stored`
	 *                      (requires_capture beside a transaction ID naming pi_B); `authorization over a kept payment` (the
	 *                      extension's authorization pi_B recorded over kept payment pi_K, written with pi_K's values);
	 *                      `authorization over a kept payment without a transaction ID` (the same, pi_K held by the
	 *                      extension with no transaction ID); `authorization with another attempt's transaction ID`
	 *                      (authorization pi_B the extension's duplicate check recorded beside pi_A's transaction ID).
	 * @return WC_Order
	 */
	private function create_order_storing_an_authorization( string $shape ): WC_Order {
		switch ( $shape ) {
			case 'authorization':
				return StoredPaymentRecords::authorization( 'pi_A', 'ch_A' );
			case 'charge transaction ID':
				return StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'ch_A', 'pi_A' );
			case 'no transaction ID':
				return StoredPaymentRecords::authorization_without_transaction_id( 'pi_A' );
			case 'retyped transaction ID':
				return StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'typed-reference', 'pi_A' );
			case 'already mixed':
				return StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::ON_HOLD, 'pi_A', 'pi_B', 'ch_B' );
			case 'H2 with a paid date':
				return StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::PENDING, 'pi_A', 'pi_B', 'ch_B', true );
			case 'declined retry with a paid date':
				return StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::FAILED, 'pi_A', 'pi_B', 'ch_A', true );
			case 'whole record over another attempt':
				return StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_A', 'pi_B', 'succeeded', OrderStatus::ON_HOLD, 'ch_B' );
			case 'whole record named by the transaction ID':
				return StoredPaymentRecords::payment( OrderStatus::FAILED, 'pi_B', 'ch_B' );
			case 'no intent stored':
				return StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::PENDING, 'pi_B', '', '' );
			case 'authorization over a kept payment':
				return StoredPaymentRecords::authorization( 'pi_K', 'ch_K' );
			case 'authorization over a kept payment without a transaction ID':
				return StoredPaymentRecords::authorization_without_transaction_id( 'pi_K', 'requires_capture', OrderStatus::ON_HOLD, 'ch_K' );
			case "authorization with another attempt's transaction ID":
				return StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_A', 'pi_B', 'requires_capture', OrderStatus::ON_HOLD, 'ch_A' );
		}

		$this->fail( 'Unknown shape: ' . $shape );
	}

	/**
	 * @testdox A succeeded payment stopped before its decision leaves the record it wrote in one save: $_dataName.
	 *
	 * The run stops after the payment's meta is saved and before the order's status is decided. On an order without a paid
	 * status that stores another attempt, a paid date or a paid-status filter included, the payment's whole record is
	 * written, its succeeded status included, and the transaction ID is left to the decision; a line names the status it
	 * replaced. An empty or equal stored intent, and an order with a paid status, are written as before, the paid status
	 * with the succeeded status. A success with no charge removes the stored one.
	 * Client 11.3.0 writes the IDs, the currency, the mandate, the fee and the net and no status, and keeps an earlier
	 * charge when the success has none (class-wc-payments-webhook-processing-service.php:642-701).
	 *
	 * @dataProvider provider_successes_stopped_before_their_decision
	 *
	 * @param string      $shape           The order before the event.
	 * @param bool        $has_charge      Whether the success names its charge.
	 * @param string[]    $expected        Stored intent, charge, intent status and transaction ID, and the order status, after the run.
	 * @param string|null $replaced_status The stored status the line names, or null for no line.
	 */
	public function test_success_stopped_before_its_decision_leaves_the_record_it_wrote( string $shape, bool $has_charge, array $expected, ?string $replaced_status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order  = $this->create_order_in_shape( $shape );
		$event  = $this->create_success_of_attempt_b( $order, $has_charge );
		$saved  = $this->record_saved_payment_values( $order );
		$logger = RecordingWcLogger::install();

		$this->process_success_stopped_before_its_decision( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected, array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_charge_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id(), $order->get_status() ) );
		$this->assertSame( array( 'pm_123', 'usd' ), array( $order->get_meta( '_payment_method_id', true ), $order->get_meta( '_wcpay_intent_currency', true ) ) );
		if ( $has_charge ) {
			$this->assertSame( array( 'mandate_123', '1.23' ), array( $order->get_meta( '_stripe_mandate_id', true ), $order->get_meta( '_wcpay_transaction_fee', true ) ), "The charge's mandate and fee." );
		}
		$this->assertSame( array( array_merge( array_slice( $expected, 0, 3 ), $has_charge ? array( 'pm_123', '1.23', '9.76' ) : array( 'pm_123', '', '' ) ) ), $saved->values_after_the_first_change(), 'The payment values, its payment method, fee and net included, reach the store in one save, with no state between.' );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, $replaced_status );
	}

	/**
	 * Orders a success of attempt pi_B meets, with what the run stopped before its decision leaves.
	 *
	 * @return array<string,array{0:string,1:bool,2:string[],3:string|null}>
	 */
	public function provider_successes_stopped_before_their_decision(): array {
		return array(
			'nothing stored'                               => array( 'nothing stored', true, array( 'pi_B', 'ch_B', '', '', OrderStatus::PENDING ), null ),
			'the arriving attempt stored, waiting for its customer' => array( 'B waiting', true, array( 'pi_B', 'ch_B', 'requires_action', '', OrderStatus::PENDING ), null ),
			'no intent stored, a kept charge'              => array( 'kept charge', true, array( 'pi_B', 'ch_B', '', '', OrderStatus::PENDING ), null ),
			'a declined attempt stored on a Failed order'  => array( 'row 12', true, array( 'pi_B', 'ch_B', 'succeeded', 'pi_B', OrderStatus::FAILED ), 'requires_payment_method' ),
			'an authorization stored on an On hold order'  => array( 'authorization', true, array( 'pi_B', 'ch_B', 'succeeded', 'pi_A', OrderStatus::ON_HOLD ), 'requires_capture' ),
			'another attempt stored on a Processing order' => array( 'paid', true, array( 'pi_B', 'ch_B', 'succeeded', 'pi_B', OrderStatus::PROCESSING ), null ),
			'another attempt stored on a Cancelled order with a paid date' => array( 'paid date', true, array( 'pi_B', 'ch_B', 'succeeded', 'pi_B', OrderStatus::CANCELLED ), 'requires_payment_method' ),
			'another attempt stored on an On hold order a filter counts as paid' => array( 'filter paid', true, array( 'pi_B', 'ch_B', 'succeeded', 'pi_B', OrderStatus::ON_HOLD ), 'requires_payment_method' ),
			'no intent stored, a kept charge, a success with no charge' => array( 'kept charge', false, array( 'pi_B', '', '', '', OrderStatus::PENDING ), null ),
			'a declined attempt stored, a success with no charge' => array( 'row 12', false, array( 'pi_B', '', 'succeeded', 'pi_B', OrderStatus::FAILED ), 'requires_payment_method' ),
			'another attempt stored with no status'        => array( 'A without status', true, array( 'pi_B', 'ch_B', 'succeeded', '', OrderStatus::PENDING ), 'none' ),
		);
	}

	/**
	 * @testdox A success of another attempt on $_dataName is decided as a payment, with its amount checked: $expected_status.
	 *
	 * Delivered twice. Its whole record is written first, so the order is completed, or held when the amount differs from
	 * the total, as a payment, not as the capture of the stored authorization, with one line naming the status it
	 * replaced and no refused-capture line. Client 11.3.0 captures the stored authorization, its amount not checked
	 * (class-wc-payments-order-service.php:484-488).
	 *
	 * @dataProvider provider_successes_of_another_attempt_decided_as_payments
	 *
	 * @param string $shape           The order before the event.
	 * @param int    $amount          The success's amount in minor units; the order total is 10.99.
	 * @param string $expected_status Order status after the success.
	 * @param string $replaced_status The stored status the line names.
	 */
	public function test_success_of_another_attempt_is_decided_as_a_payment( string $shape, int $amount, string $expected_status, string $replaced_status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order  = $this->create_order_in_shape( $shape );
		$event  = $this->create_success_of_attempt_b( $order, true, $amount );
		$logger = RecordingWcLogger::install();

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( $expected_status, 'pi_B', 'pi_B', 'ch_B', 'succeeded' ), array( $order->get_status(), $order->get_transaction_id(), $order->get_meta( '_intent_id', true ), $order->get_meta( '_charge_id', true ), $order->get_meta( '_intention_status', true ) ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertCount( OrderStatus::ON_HOLD === $expected_status ? 0 : 1, array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) );
		$this->assertCount( OrderStatus::ON_HOLD === $expected_status ? 1 : 0, $this->get_order_notes_containing( $order, 'but the order total is' ) );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, $replaced_status );
		$this->assertSame( array(), $this->get_refused_capture_lines( $logger ) );
	}

	/**
	 * Unpaid orders storing another attempt, with the success's amount and the decision.
	 *
	 * @return array<string,array{string,int,string,string}>
	 */
	public function provider_successes_of_another_attempt_decided_as_payments(): array {
		return array(
			'a Failed order storing a declined attempt, the total paid' => array( 'row 12', 1099, OrderStatus::COMPLETED, 'requires_payment_method' ),
			'an On hold order storing an authorization, another amount' => array( 'authorization', 1500, OrderStatus::ON_HOLD, 'requires_capture' ),
			'an On hold order storing an authorization, the total paid' => array( 'authorization', 1099, OrderStatus::COMPLETED, 'requires_capture' ),
		);
	}

	/**
	 * @testdox A success of another attempt whose one save fails writes no line of a whole record, and is decided on what the order stores: held on its amount, with the refused-capture line naming the stored intent.
	 *
	 * The save's hook throws, and core keeps the exception as the order's last save error and goes on, so nothing of the
	 * payment is stored. The decision reads the order again, finds the authorization pi_A, and checks the payment's amount.
	 */
	public function test_success_of_another_attempt_whose_save_fails_logs_only_the_refused_capture(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order  = $this->create_order_in_shape( 'authorization' );
		$event  = $this->create_success_of_attempt_b( $order, true, 1500 );
		$logger = RecordingWcLogger::install();
		$failed = 0;
		$fail   = static function ( $saved ) use ( $order, &$failed ): void {
			if ( 0 === $failed && $saved instanceof WC_Order && $saved->get_id() === $order->get_id() && 'pi_B' === (string) $saved->get_meta( '_intent_id', true ) ) {
				++$failed;
				throw new \Exception( 'Save failed.' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $fail, PHP_INT_MAX );

		try {
			$this->sut->process( $event );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $fail, PHP_INT_MAX );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( 1, 1 ), array( $failed, count( array_filter( $logger->get_errors(), static fn( array $line ): bool => 'Error saving order ID ' . $order->get_id() . '.' === $line[1] ) ) ), 'The first save storing pi_B failed, and core logged it.' );
		$this->assertSame( array( OrderStatus::ON_HOLD, 0, 0, 1 ), array( $order->get_status(), count( $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) ), count( array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) ), count( $this->get_order_notes_containing( $order, 'but the order total is' ) ) ), 'Held on its amount, not completed as a capture.' );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, null );
		$lines = $this->get_refused_capture_lines( $logger );
		$this->assertCount( 1, $lines );
		$this->assertSame(
			array(
				'order_id'         => $order->get_id(),
				'intent_id'        => 'pi_B',
				'stored_intent_id' => 'pi_A',
				'transaction_id'   => 'pi_A',
				'condition'        => 'stored_intent',
			),
			array_intersect_key( $this->get_logged_context( $logger, (string) $lines[0][1] ), array_flip( array( 'order_id', 'intent_id', 'stored_intent_id', 'transaction_id', 'condition' ) ) )
		);
	}

	/**
	 * @testdox A success of another attempt stopped before its decision is decided on its redelivery as a run that was not stopped decides it.
	 *
	 * The redelivery finds the attempt stored, so it writes as for the order's own attempt, then completes the order with
	 * its payment note and transaction ID. The line was written once, by the stopped run.
	 */
	public function test_success_of_another_attempt_stopped_before_its_decision_is_decided_on_its_redelivery(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order  = $this->create_order_in_shape( 'row 12' );
		$event  = $this->create_success_of_attempt_b( $order );
		$logger = RecordingWcLogger::install();
		$this->process_success_stopped_before_its_decision( $event );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( OrderStatus::COMPLETED, 'pi_B', 'succeeded' ), array( $order->get_status(), $order->get_transaction_id(), $order->get_meta( '_intention_status', true ) ) );
		$this->assertCount( 1, array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, 'requires_payment_method' );
	}

	/**
	 * @testdox A Stripe Billing invoice's payment intent on $_dataName storing another attempt writes nothing, and its invoice records the payment.
	 *
	 * The order is found by the charge's metadata. The invoice's own invoice.paid records the payment; a stored succeeded
	 * status written here would make that record skip its write and its transaction ID. An order with a paid date or a
	 * paid-status filter but no paid status is one the lifecycle applies, so it takes the same answer.
	 *
	 * @dataProvider provider_orders_an_invoice_intent_meets
	 *
	 * @param string   $shape    The order before the event.
	 * @param string[] $expected Stored intent, charge, intent status, transaction ID, order status and payment method, after the event.
	 */
	public function test_invoice_intent_success_on_an_order_storing_another_attempt_writes_nothing( string $shape, array $expected ): void {
		$order                              = 'pending' === $shape ? StoredPaymentRecords::declined_attempt_with_charge( '', 'pi_A', 'ch_A' ) : $this->create_order_in_shape( $shape );
		$event                              = $this->create_success_of_attempt_b( $order );
		$event['data']['object']['invoice'] = 'in_B';
		$event['data']['object']['charges']['data'][0]['metadata'] = $event['data']['object']['metadata'];
		$event['data']['object']['metadata']                       = array();
		$logger = RecordingWcLogger::install();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected, array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_charge_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id(), $order->get_status(), $order->get_meta( '_payment_method_id', true ) ) );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, null );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'does not pay this order' ), 'The event is the order\'s own, not another charge.' );
	}

	/**
	 * Orders without a paid status that store another attempt, with their unchanged values.
	 *
	 * @return array<string,array{string,string[]}>
	 */
	public function provider_orders_an_invoice_intent_meets(): array {
		return array(
			'a Pending order'                       => array( 'pending', array( 'pi_A', 'ch_A', 'requires_payment_method', 'pi_A', OrderStatus::PENDING, '' ) ),
			'a Cancelled order with a paid date'    => array( 'paid date', array( 'pi_A', 'ch_B', 'requires_payment_method', 'pi_B', OrderStatus::CANCELLED, '' ) ),
			'an On hold order a filter counts paid' => array( 'filter paid', array( 'pi_A', '', 'requires_payment_method', 'pi_B', OrderStatus::ON_HOLD, '' ) ),
		);
	}

	/**
	 * @testdox A success of the attempt the transaction ID names, on a Pending order with a paid date storing another attempt's authorization, stopped before its decision, is amount-checked on its redelivery: held with the amount note, no capture note.
	 *
	 * The order was paid once, so it keeps its paid date, and stores another attempt's requires_capture beside the
	 * transaction ID of the arriving payment. The stopped run writes the payment's whole record with its succeeded status,
	 * so the redelivery decides it as a payment, not as the capture of that authorization. Client 11.3.0 completes it as a
	 * capture, amount not checked (class-wc-payments-order-service.php:484-485).
	 */
	public function test_redelivery_after_a_stopped_success_on_a_pending_order_with_a_paid_date_is_amount_checked(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order = StoredPaymentRecords::authorization_status_beside_another_intent( OrderStatus::PENDING, 'pi_B', 'pi_A', 'ch_A' );
		$order->set_date_paid( time() - HOUR_IN_SECONDS );
		$order->save();
		$event  = $this->create_success_of_attempt_b( $order, true, 1500 );
		$logger = RecordingWcLogger::install();
		$this->process_success_stopped_before_its_decision( $event );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( OrderStatus::ON_HOLD, 'pi_B', 'succeeded' ), array( $order->get_status(), $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ) ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'but the order total is' ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assert_success_recorded_over_another_attempt( $logger, $order, 'requires_capture' );
		$this->assertSame( array(), $this->get_refused_capture_lines( $logger ) );
	}

	/**
	 * @testdox After a success of another attempt stopped before its decision, a retried checkout $_dataName.
	 *
	 * The stored intent is the attempt that succeeded, so the duplicate check reads it and completes the order with it,
	 * without a new charge. When the read fails, the stored succeeded status refuses the checkout instead of charging again.
	 * Client 11.3.0 logs a failed read and goes on to charge (class-duplicate-payment-prevention-service.php:101-109).
	 *
	 * @testWith ["completes the order with the stored payment", false]
	 *           ["is refused when the stored payment cannot be read", true]
	 *
	 * @param string $name       Case name.
	 * @param bool   $read_fails Whether the read of the stored intent fails.
	 */
	public function test_retried_checkout_after_a_stopped_success_of_another_attempt( string $name, bool $read_fails ): void {
		unset( $name );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_order_in_shape( 'row 12' );
		$event = $this->create_success_of_attempt_b( $order );
		$this->process_success_stopped_before_its_decision( $event );
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_payment_intention' ) )->getMock();
		if ( $read_fails ) {
			$api_client->method( 'get_payment_intention' )->willThrowException( new ApiException( 'Read failed.', 'api_error', 500 ) );
		} else {
			$api_client->method( 'get_payment_intention' )->willReturn( $event['data']['object'] );
		}
		$service = new WooPaymentsDuplicatePaymentPreventionService( new \WC_Session_Handler() );
		$service->init( $api_client, wc_get_container()->get( OrderPaymentLifecycleService::class ) );

		$result = $service->check_payment_intent_attached_to_order_succeeded( wc_get_order( $order->get_id() ), new WooPaymentsGateway() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		if ( $read_fails ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( WooPaymentsDuplicatePaymentPreventionService::ERROR_CHECK_FAILED, $result->get_error_code() );
			$this->assertSame( OrderStatus::FAILED, $order->get_status() );
			return;
		}
		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( array( OrderStatus::COMPLETED, 'pi_B' ), array( $order->get_status(), $order->get_transaction_id() ) );
	}

	/**
	 * @testdox After a success of another attempt stopped before its decision on an authorized order, completing the order sends no capture.
	 *
	 * The stored status is succeeded, so the status-change listener finds no authorization to capture
	 * (WooPaymentsOrderAdminActionsController get_status_change_target()); the authorization the transaction ID names is
	 * left to expire. The platform client is connected, so a capture the listener started would reach it.
	 */
	public function test_completing_an_order_after_a_stopped_success_of_another_attempt_sends_no_capture(): void {
		$order = $this->create_order_in_shape( 'authorization' );
		$event = $this->create_success_of_attempt_b( $order );
		$this->process_success_stopped_before_its_decision( $event );
		$captures = 0;
		$platform = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'is_available', 'capture_intention' ) )->getMock();
		$platform->method( 'is_available' )->willReturn( true );
		$platform->method( 'capture_intention' )->willReturnCallback(
			static function () use ( &$captures, $event ): array {
				++$captures;
				return $event['data']['object'];
			}
		);
		wc_get_container()->replace( Client::class, $platform );
		$this->reset_container_resolutions();
		try {
			wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->register();
			wc_get_order( $order->get_id() )->update_status( OrderStatus::COMPLETED );
		} finally {
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertSame( 0, $captures, 'No capture sent to the platform.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox After a success of another attempt stopped before its decision, the in-person capture of that payment is refused with 409 and nothing is sent.
	 *
	 * The route refuses an order whose stored status is succeeded (WooPaymentsTerminalPaymentsRestController
	 * get_uncapturable_order_error()), as it does whenever the webhook completed the order first; the redelivery records the
	 * payment.
	 */
	public function test_in_person_capture_after_a_stopped_success_of_another_attempt_is_refused(): void {
		$order = $this->create_order_in_shape( 'row 12' );
		$this->process_success_stopped_before_its_decision( $this->create_success_of_attempt_b( $order ) );
		$platform_requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $response ) use ( &$platform_requests ) {
				++$platform_requests;
				return $response;
			}
		);
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_B' );

		$response = wc_get_container()->get( WooPaymentsTerminalPaymentsRestController::class )->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( array( 'wcpay_payment_uncapturable', 409 ), array( $response->get_error_code(), $response->get_error_data()['status'] ?? null ) );
		$this->assertSame( 0, $platform_requests );
		$this->assertSame( OrderStatus::FAILED, wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox After a success of another attempt stopped before its decision, a shopper's retry while the order payment lock is held is answered as paid, with no new charge.
	 *
	 * The duplicate check reads the stored payment, which succeeded and belongs to the order, and sends the shopper to the
	 * order received page without charging again; its completion is left to the webhook's redelivery, which holds the order
	 * payment lock. Client 11.3.0 answers the same way (class-duplicate-payment-prevention-service.php:149-157).
	 */
	public function test_shopper_retry_refused_by_the_lock_after_a_stopped_success_of_another_attempt_is_answered_as_paid(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_order_in_shape( 'row 12' );
		$event = $this->create_success_of_attempt_b( $order );
		$this->process_success_stopped_before_its_decision( $event );
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_payment_intention' ) )->getMock();
		$api_client->method( 'get_payment_intention' )->willReturn( $event['data']['object'] );
		$session                   = new \WC_Session_Handler();
		$duplicate_payment_service = new WooPaymentsDuplicatePaymentPreventionService( $session );
		$duplicate_payment_service->init( $api_client, wc_get_container()->get( OrderPaymentLifecycleService::class ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_cached_account_data' ) )->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'card_testing_protection_eligible' => false ) );
		$fraud_prevention_service = new WooPaymentsFraudPreventionService( $session );
		$fraud_prevention_service->init( $account_service );
		$processing_service = new RecordingPaymentProcessingService();
		$gateway            = new WooPaymentsGateway();
		$gateway->init( $processing_service, new WooPaymentsProvider(), null, null, null, null, null, $fraud_prevention_service, new WooPaymentsFailedTransactionRateLimiter( $session ), $duplicate_payment_service );
		$_POST['wcpay-payment-method'] = 'pm_card_visa';
		$this->hold_order_payment_lock( $order, new WooPaymentsPersistenceVocabulary(), 'pi_B' );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
			$this->clear_order_payment_lock( $order, new WooPaymentsPersistenceVocabulary() );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( 0, $processing_service->checkout_attempt_count, 'No new charge.' );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] ?? '' );
		$this->assertSame( array( OrderStatus::FAILED, 'succeeded' ), array( $order->get_status(), $order->get_meta( '_intention_status', true ) ), 'The redelivery completes the order.' );
	}

	/**
	 * Create a WooPayments order in one of the shapes the success of attempt pi_B meets, from StoredPaymentRecords.
	 *
	 * @param string $shape `nothing stored`; `B waiting` (pi_B stored, waiting for its customer); `kept charge` (no intent
	 *                      stored, the listed attempt pi_A's charge kept by a takeover cleanup); `row 12` (a Failed order
	 *                      storing the declined attempt pi_A, its transaction ID pi_B); `authorization` (pi_A authorized,
	 *                      On hold); `paid` (Processing, paid by pi_B, storing the declined retry pi_A); `paid date` (the
	 *                      same order cancelled, its paid date kept); `filter paid` (an On hold order storing pi_A, its
	 *                      transaction ID pi_B, which a filter counts as paid); `A without status` (a Pending order storing
	 *                      pi_A with no status).
	 * @return WC_Order
	 */
	private function create_order_in_shape( string $shape ): WC_Order {
		switch ( $shape ) {
			case 'nothing stored':
				return StoredPaymentRecords::authorization_without_transaction_id( '', '', OrderStatus::PENDING, '' );
			case 'B waiting':
				return StoredPaymentRecords::authorization_without_transaction_id( 'pi_B', 'requires_action', OrderStatus::PENDING, '' );
			case 'kept charge':
				return StoredPaymentRecords::after_a_takeover_cleanup( array( 'pi_A' ), time() - HOUR_IN_SECONDS, OrderStatus::PENDING, '', 'ch_A', WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			case 'row 12':
				return StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_B', 'pi_A', 'requires_payment_method', OrderStatus::FAILED, '' );
			case 'A without status':
				return StoredPaymentRecords::authorization_without_transaction_id( 'pi_A', '', OrderStatus::PENDING, '' );
			case 'authorization':
				return StoredPaymentRecords::authorization();
			case 'paid':
				return StoredPaymentRecords::payment_beside_a_declined_retry( 'pi_B', 'pi_A', 'ch_B' );
			case 'paid date':
				$order = StoredPaymentRecords::payment_beside_a_declined_retry( 'pi_B', 'pi_A', 'ch_B' );
				$order->set_status( OrderStatus::CANCELLED );
				$order->save();
				$this->assertNotNull( $order->get_date_paid() );
				return $order;
			case 'filter paid':
				$order = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_B', 'pi_A', 'requires_payment_method', OrderStatus::ON_HOLD, '' );
				add_filter( 'woocommerce_order_is_paid', static fn( $is_paid, $paid_order ): bool => $paid_order instanceof WC_Order && $paid_order->get_id() === $order->get_id() ? true : (bool) $is_paid, 10, 2 );
				return $order;
		}

		$this->fail( 'Unknown shape: ' . $shape );
	}

	/**
	 * A payment_intent.succeeded of attempt pi_B, with its charge ch_B, for the order the metadata names.
	 *
	 * @param WC_Order $order      Order the intent's metadata names.
	 * @param bool     $has_charge Whether the intent names its charge; Stripe's `charges.data[0]`.
	 * @param int      $amount     Amount in minor units.
	 * @return array<string,mixed>
	 */
	private function create_success_of_attempt_b( WC_Order $order, bool $has_charge = true, int $amount = 1099 ): array {
		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'id'      => 'pi_B',
				'amount'  => $amount,
				'charges' => array( 'data' => array( array( 'id' => 'ch_B' ) ) ),
			)
		);
		if ( ! $has_charge ) {
			$event['data']['object']['charges']['data'] = array();
		}

		return $event;
	}

	/**
	 * Process a succeeded payment whose run stops after its meta is saved, where the amount-mismatch hold is decided, before
	 * the order's status.
	 *
	 * @param array<string,mixed> $event The payment_intent.succeeded event.
	 */
	private function process_success_stopped_before_its_decision( array $event ): void {
		wc_get_container()->replace(
			WooPaymentsAmountMismatchHold::class,
			new class() extends WooPaymentsAmountMismatchHold {
				/**
				 * Stop the run.
				 *
				 * @param WC_Order            $order            Order.
				 * @param array<string,mixed> $intent           Intent.
				 * @param string|null         $stored_intent_id The intent the order stored before the event.
				 * @throws RuntimeException Always.
				 */
				public function decide_hold( WC_Order $order, array $intent, ?string $stored_intent_id = null ): ?array {
					throw new RuntimeException( 'The run stopped before its decision.' );
				}
			}
		);
		$stopped = null;
		try {
			$this->sut->process( $event );
		} catch ( RuntimeException $exception ) {
			$stopped = $exception;
		} finally {
			$this->reset_container_replacements();
		}
		$this->assertInstanceOf( RuntimeException::class, $stopped );
		$this->assertSame( 'The run stopped before its decision.', $stopped->getMessage() );
	}

	/**
	 * Record the stored intent, charge, intent status, payment method, fee and net each save of the order writes.
	 *
	 * @param WC_Order $order Order.
	 * @return object A recorder whose values_after_the_first_change() lists the saved values once each changes, from the first value that differs from the order's before the event.
	 */
	private function record_saved_payment_values( WC_Order $order ): object {
		$recorder = new class( $order ) {
			/**
			 * Values of each save.
			 *
			 * @var array<int,string[]>
			 */
			public array $saves = array();

			/**
			 * Values before the event.
			 *
			 * @var string[]
			 */
			private array $before;

			/**
			 * Order ID.
			 *
			 * @var int
			 */
			private int $order_id;

			/**
			 * Read the order's values before the event.
			 *
			 * @param WC_Order $order Order.
			 */
			public function __construct( WC_Order $order ) {
				$this->order_id = $order->get_id();
				$this->before   = self::values( $order );
			}

			/**
			 * Record one save.
			 *
			 * @param mixed $saved Saved object.
			 */
			public function record( $saved ): void {
				if ( $saved instanceof WC_Order && $saved->get_id() === $this->order_id ) {
					$this->saves[] = self::values( $saved );
				}
			}

			/**
			 * The saved values, once each changes, leaving out saves of the values before the event.
			 *
			 * @return array<int,string[]>
			 */
			public function values_after_the_first_change(): array {
				$changes = array();
				foreach ( $this->saves as $values ) {
					if ( $this->before !== $values && end( $changes ) !== $values ) {
						$changes[] = $values;
					}
				}

				return $changes;
			}

			/**
			 * Stored intent, charge, intent status, payment method, fee and net.
			 *
			 * @param WC_Order $order Order.
			 * @return string[]
			 */
			private static function values( WC_Order $order ): array {
				return array_map(
					static fn( string $key ): string => (string) $order->get_meta( $key, true ),
					array( '_intent_id', '_charge_id', '_intention_status', '_payment_method_id', '_wcpay_transaction_fee', '_wcpay_net' )
				);
			}
		};
		add_action( 'woocommerce_before_order_object_save', array( $recorder, 'record' ) );

		return $recorder;
	}

	/**
	 * Assert the line of a success recorded over another attempt's stored intent: one, naming pi_A and the status it
	 * replaced, or none.
	 *
	 * @param RecordingWcLogger $logger          Recording logger.
	 * @param WC_Order          $order           Order.
	 * @param string|null       $replaced_status The stored status the line names, or null for no line.
	 */
	private function assert_success_recorded_over_another_attempt( RecordingWcLogger $logger, WC_Order $order, ?string $replaced_status ): void {
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => false !== strpos( $line[1], 'over the stored intent' ) ) );
		if ( null === $replaced_status ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$message = sprintf( 'Payment pi_B was recorded on order #%1$d over the stored intent pi_A, whose stored status was %2$s.', $order->get_id(), $replaced_status );
		$context = $this->get_logged_context( $logger, $message );
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'warning', 'woopayments' ), array( $lines[0][0], $lines[0][2] ) );
		$this->assertSame(
			array(
				'order_id'         => $order->get_id(),
				'intent_id'        => 'pi_B',
				'stored_intent_id' => 'pi_A',
				'replaced_status'  => $replaced_status,
				'reason'           => 'success_recorded_over_another_attempt',
			),
			array_intersect_key( $context, array_flip( array( 'order_id', 'intent_id', 'stored_intent_id', 'replaced_status', 'reason' ) ) )
		);
	}

	/**
	 * The recorded authorization (Fixtures/platform-payment-intent-events.json) as its payment_intent.succeeded once
	 * captured in full.
	 *
	 * @param WC_Order $order Order the event names.
	 * @return array<string,mixed>
	 */
	private function recorded_capture_event( WC_Order $order ): array {
		$event                                        = $this->recorded_authorization_event( $order );
		$event['id']                                  = 'evt_capture_of_the_authorization';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = 1099;
		$event['data']['object']['charges']['data'][0]['captured']        = true;
		$event['data']['object']['charges']['data'][0]['amount_captured'] = 1099;

		return $event;
	}

	/**
	 * @testdox An authorization and its capture by the succeeded payment stamp the fraud box from the outcome: $_dataName.
	 *
	 * The events are the authorization the platform forwarded for a manual-capture card checkout
	 * (Fixtures/platform-payment-intent-events.json) and its capture; the Amazon Pay rows give the charge Stripe's
	 * `amazon_pay` payment method details and the review rows set the outcome, which no recording carries. Client 11.3.0
	 * stamps `allow` for an allowed authorization and `review` for one held for review (class-wc-payments-order-service.php:2153-2156,
	 * :2264), then `review_allowed` on the capture when the stored outcome is review and `allow` otherwise (:2209-2213),
	 * for every payment method.
	 *
	 * @dataProvider provider_fraud_box_of_an_authorization_and_its_capture
	 *
	 * @param string $payment_method_type Payment method type of the charge.
	 * @param string $fraud_outcome       Fraud outcome the intent carries.
	 * @param string $authorized_box      Fraud meta box type after the authorization.
	 * @param string $captured_box        Fraud meta box type after the capture.
	 */
	public function test_authorization_and_its_capture_stamp_the_fraud_box_from_the_outcome( string $payment_method_type, string $fraud_outcome, string $authorized_box, string $captured_box ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$event = $this->recorded_authorization_event( $order, $fraud_outcome );
		if ( 'amazon_pay' === $payment_method_type ) {
			$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay' );
			$order->save();
			$event['data']['object']['payment_method_types']                         = array( 'amazon_pay' );
			$event['data']['object']['payment_method_options']                       = array( 'amazon_pay' => array() );
			$event['data']['object']['charges']['data'][0]['payment_method_details'] = array(
				'type'       => 'amazon_pay',
				'amazon_pay' => array(
					'funding' => array(
						'type' => 'card',
						'card' => array(
							'brand'   => 'visa',
							'country' => 'US',
							'funding' => 'credit',
							'last4'   => '4242',
						),
					),
				),
			);
		}

		$this->sut->process( $event );
		$held = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $held->get_status() );
		$this->assertSame( $authorized_box, $held->get_meta( '_wcpay_fraud_meta_box_type', true ), 'After the authorization.' );

		$event['id']                                  = 'evt_capture_of_the_authorization';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = $event['data']['object']['amount'];
		$event['data']['object']['charges']['data'][0]['captured'] = true;
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( $captured_box, $order->get_meta( '_wcpay_fraud_meta_box_type', true ), 'After the capture.' );
		$this->assertSame( $fraud_outcome, $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
	}

	/** @return array<string,array{string,string,string,string}> */
	public function provider_fraud_box_of_an_authorization_and_its_capture(): array {
		return array(
			'card, allowed'               => array( 'card', 'allow', 'allow', 'allow' ),
			'card, held for review'       => array( 'card', 'review', 'review', 'review_allowed' ),
			'Amazon Pay, allowed'         => array( 'amazon_pay', 'allow', 'allow', 'allow' ),
			'Amazon Pay, held for review' => array( 'amazon_pay', 'review', 'review', 'review_allowed' ),
		);
	}

	/**
	 * @testdox A capture completed by the succeeded payment writes the captured charge's application fee, zero included.
	 *
	 * Client 11.3.0's capture writer attaches the captured charge's fee; with no fee envelope it writes the application
	 * fee whenever the charge carries one, zero included (class-wc-payments-order-service.php:2215, :2275-2316).
	 */
	public function test_capture_by_the_succeeded_payment_writes_a_zero_application_fee(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event = $this->recorded_authorization_event( $order );
		$this->sut->process( $event );
		$held = wc_get_order( $order->get_id() );
		$held->update_meta_data( '_wcpay_transaction_fee', '1.23' );
		$held->save();

		$charge                           = $event['data']['object']['charges']['data'][0];
		$charge['captured']               = true;
		$charge['application_fee_amount'] = 0;
		unset( $charge['fee_breakdown_v1'] );
		$event['id']                                  = 'evt_capture_with_no_fee';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = 1099;
		$event['data']['object']['charges']['data']   = array( $charge );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '0', $order->get_meta( '_wcpay_transaction_fee', true ) );
	}

	/**
	 * @testdox A payment captured outside the store and delivered again leaves the order as it is: $_dataName.
	 *
	 * Client 11.3.0's success writer returns before any write once the capture note is on the order, so the payment is
	 * not completed again (class-wc-payments-order-service.php:2040-2046); its capture writer did the completion
	 * (:484-485, :2193-2219).
	 *
	 * @dataProvider provider_orders_a_captured_payment_is_delivered_again_to
	 *
	 * @param string $status_after_capture          Status the order is moved to after the capture, or empty to leave it.
	 * @param bool   $capture_note_in_other_wording Whether the store's language changed after the capture note was written.
	 */
	public function test_succeeded_payment_delivered_again_after_its_capture_leaves_the_order( string $status_after_capture, bool $capture_note_in_other_wording = false ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		if ( $capture_note_in_other_wording ) {
			$this->install_test_translations_for_domain(
				'woocommerce',
				array( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).' => 'Eine Zahlung von %1$s wurde mit %2$s <strong>erfolgreich erfasst</strong> (<a>%3$s</a>).' )
			);
		}
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event = $this->recorded_authorization_event( $order );
		$this->sut->process( $event );
		$event['id']                                  = 'evt_capture_outside_the_store';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = 1099;
		$event['data']['object']['charges']['data'][0]['captured'] = true;
		$this->sut->process( $event );
		$captured = wc_get_order( $order->get_id() );
		$this->assertTrue( $captured->is_paid() );
		$this->gettext_domain_replacements = array();
		if ( '' !== $status_after_capture ) {
			$captured->update_status( $status_after_capture );
		}
		$status      = wc_get_order( $order->get_id() )->get_status();
		$notes       = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$completions = did_action( 'woocommerce_payment_complete' ) + did_action( 'woocommerce_payment_complete_order_status_' . $status );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) + did_action( 'woocommerce_payment_complete_order_status_' . $status ), 'The payment is not completed again.' );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, $capture_note_in_other_wording ? '<strong>erfolgreich erfasst</strong>' : 'was <strong>successfully captured</strong>' ) );
		$this->assert_no_payment_success_note( $order );
	}

	/** @return array<string,array{0:string,1?:bool}> */
	public function provider_orders_a_captured_payment_is_delivered_again_to(): array {
		return array(
			'the order is left as it is'      => array( '' ),
			'the order is moved to Failed'    => array( OrderStatus::FAILED ),
			'the order is moved to Cancelled' => array( OrderStatus::CANCELLED ),
			'the order is moved to Failed, its capture note in another language' => array( OrderStatus::FAILED, true ),
		);
	}

	/**
	 * @testdox A succeeded payment on a failed order whose capture note the WooPayments extension wrote in its own wording leaves the order as it is.
	 *
	 * Client 11.3.0's success writer returns before any write once the capture note is on the order, in either text
	 * domain's wording (class-wc-payments-order-service.php:2040-2046).
	 */
	public function test_succeeded_payment_leaves_an_order_with_the_extensions_capture_note(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->install_test_translations_for_domain(
			'woocommerce-payments',
			array( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).' => 'Eine Zahlung von %1$s wurde mit %2$s <strong>erfolgreich erfasst</strong> (<a>%3$s</a>).' )
		);
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event                                    = $this->recorded_authorization_event( $order );
		$intent                                   = $event['data']['object'];
		$intent['status']                         = 'succeeded';
		$intent['charges']['data'][0]['captured'] = true;
		$event['type']                            = 'payment_intent.succeeded';
		$event['data']['object']                  = $intent;
		$extension_note                           = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_capture_success_note_candidates( $order, $intent['id'], $intent['charges']['data'][0]['id'], 'txn_3URpVCBzWlxcwgpP1SQjpoe4' );
		$this->assertCount( 2, $extension_note, 'The extension wording differs from WooCommerce\'s.' );
		$order->set_transaction_id( $intent['id'] );
		$order->update_meta_data( '_intent_id', $intent['id'] );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->set_status( OrderStatus::FAILED );
		$order->save();
		$order->add_order_note( $extension_note[1] );
		$completions = did_action( 'woocommerce_payment_complete' );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assert_no_payment_success_note( $order );
	}

	/**
	 * @testdox A succeeded payment delivered to an order held again after its payment note leaves the order as it is.
	 *
	 * The payment's success note counts as its capture already applied, so an order that stores `requires_capture` again
	 * after it was paid is not completed a second time. Client 11.3.0's capture writer checks only its own note
	 * (class-wc-payments-order-service.php:2197-2199).
	 */
	public function test_succeeded_payment_on_an_order_held_again_after_its_payment_leaves_the_order(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$order->save();
		$event                                        = $this->recorded_authorization_event( $order );
		$event['id']                                  = 'evt_paid_before_its_capture';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = 1099;
		$event['data']['object']['charges']['data'][0]['captured'] = true;
		$this->sut->process( $event );
		$held = wc_get_order( $order->get_id() );
		$this->assertTrue( $held->is_paid() );
		$held->update_meta_data( '_intention_status', 'requires_capture' );
		$held->set_status( OrderStatus::ON_HOLD );
		$held->save();
		$completions = did_action( 'woocommerce_pre_payment_complete' );
		$event['id'] = 'evt_paid_again';

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( $completions, did_action( 'woocommerce_pre_payment_complete' ), 'The payment is not completed again.' );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertCount( 1, array_merge( $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ), $this->get_order_notes_containing( $order, 'A test payment of' ) ) );
	}

	/**
	 * @testdox A succeeded payment on an order already paid by it, with neither payment note, writes only the payment meta, its succeeded status and the paid-order note: $_dataName.
	 *
	 * Client 11.3.0's webhook writes the payment meta, then updates the order from the intent only while it is not paid
	 * (class-wc-payments-webhook-processing-service.php:642-716, class-wc-payments-order-service.php:475, :3398-3410); it
	 * stores no status there. The built-in stores the succeeded status with the charge, so the two describe one payment.
	 *
	 * @dataProvider provider_paid_orders_a_succeeded_payment_is_delivered_to
	 *
	 * @param string $status                  The order's paid status.
	 * @param bool   $stored_requires_capture Whether the order still stores `requires_capture`, so the capture writer would run.
	 */
	public function test_succeeded_payment_on_a_paid_order_writes_only_the_payment_meta( string $status, bool $stored_requires_capture ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->register_custom_paid_status();
		$order = $this->create_woopayments_order();
		$order->set_total( '10.99' );
		$event                                        = $this->recorded_authorization_event( $order );
		$event['id']                                  = 'evt_paid_order';
		$event['type']                                = 'payment_intent.succeeded';
		$event['data']['object']['status']            = 'succeeded';
		$event['data']['object']['amount_capturable'] = 0;
		$event['data']['object']['amount_received']   = 1099;
		$event['data']['object']['charges']['data'][0]['captured'] = true;
		$intent_id = (string) $event['data']['object']['id'];
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		if ( $stored_requires_capture ) {
			$order->update_meta_data( '_intention_status', 'requires_capture' );
		}
		$order->set_status( $status );
		$order->save();
		$notes       = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$completions = $this->count_completion_hooks( $status );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( $completions, $this->count_completion_hooks( $status ), 'No completion hook fires.' );
		$this->assertSame( (string) $event['data']['object']['charges']['data'][0]['id'], $order->get_meta( '_charge_id', true ), 'The payment meta is written.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( $notes + 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'already had a paid status' ) );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public function provider_paid_orders_a_succeeded_payment_is_delivered_to(): array {
		return array(
			'processing'                         => array( OrderStatus::PROCESSING, false ),
			'completed'                          => array( OrderStatus::COMPLETED, false ),
			'a paid status a plugin adds'        => array( 'shipped', false ),
			'processing, the capture still open' => array( OrderStatus::PROCESSING, true ),
		);
	}

	/**
	 * Register `shipped` as an order status WooCommerce counts as paid, as an order status plugin does.
	 */
	private function register_custom_paid_status(): void {
		add_filter( 'wc_order_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'wc-shipped' => 'Shipped' ) ) );
		add_filter( 'woocommerce_order_is_paid_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'shipped' ) ) );
	}

	/**
	 * Count the hooks WooCommerce fires when a payment completes on an order in a status.
	 *
	 * @param string $status Order status.
	 * @return int
	 */
	private function count_completion_hooks( string $status ): int {
		return did_action( 'woocommerce_pre_payment_complete' ) + did_action( 'woocommerce_payment_complete' ) + did_action( 'woocommerce_payment_complete_order_status_' . $status );
	}

	/**
	 * Assert the order has no payment success note, in live or test mode wording.
	 *
	 * @param WC_Order $order Order.
	 */
	private function assert_no_payment_success_note( WC_Order $order ): void {
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'was <strong>successfully charged</strong>' ) );
		$this->assertSame( array(), $this->get_order_notes_containing( $order, 'A test payment of' ) );
	}

	/**
	 * The authorization the platform forwarded for a manual-capture card checkout (Fixtures/platform-payment-intent-events.json).
	 *
	 * The run's order is gone and the logged order key was redacted, so the order ID and key are the test's. The recorded
	 * fraud outcome is allow; review is the outcome client 11.3.0 holds for review (class-wc-payments-order-service.php:492-498).
	 *
	 * @param WC_Order $order         Order the event names.
	 * @param string   $fraud_outcome Fraud outcome the intent carries.
	 * @return array<string,mixed>
	 */
	private function recorded_authorization_event( WC_Order $order, string $fraud_outcome = 'allow' ): array {
		$event = $this->recorded_payment_intent_event( 'manual_capture_amount_capturable_updated', $order );
		$event['data']['object']['metadata']['fraud_outcome'] = $fraud_outcome;

		return $event;
	}

	/**
	 * @testdox Authorization-summary caches are invalidated by the four capture-affecting webhook events.
	 */
	public function test_webhook_events_invalidate_authorization_summary_caches(): void {
		$delivered = array();
		add_action(
			'woocommerce_payments_after_webhook_delivery',
			function ( string $event_type, array $event_body ) use ( &$delivered ): void {
				$delivered[] = array( $event_type, $event_body['id'] );
			},
			10,
			2
		);

		// payment_intent.canceled invalidates even when no order resolves, like the plugin. The recorded events
		// (Fixtures/platform-payment-intent-events.json) lose their order metadata: the run's orders are gone. They are
		// test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_authorization_summary_caches();
		$canceled = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'in_person_canceled' );
		unset( $canceled['data']['object']['metadata']['order_id'], $canceled['data']['object']['metadata']['order_key'] );
		$this->sut->process( $canceled );
		$this->assertAuthorizationSummaryCachesCleared( 'payment_intent.canceled' );
		$this->assertSame( array( array( 'payment_intent.canceled', $canceled['id'] ) ), $delivered, 'The cache-only event completes its delivery.' );

		// payment_intent.amount_capturable_updated does the same.
		$this->seed_authorization_summary_caches();
		$capturable = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'manual_capture_amount_capturable_updated' );
		unset( $capturable['data']['object']['metadata']['order_id'], $capturable['data']['object']['metadata']['order_key'] );
		$this->sut->process( $capturable );
		$this->assertAuthorizationSummaryCachesCleared( 'payment_intent.amount_capturable_updated' );
		$this->assertSame( array( 'payment_intent.amount_capturable_updated', $capturable['id'] ), $delivered[1] ?? null, 'The cache-only event completes its delivery.' );

		// payment_intent.succeeded invalidates after the order effects apply.
		$order = $this->create_woopayments_order();
		$this->seed_authorization_summary_caches();
		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		$this->assertAuthorizationSummaryCachesCleared( 'payment_intent.succeeded' );

		// charge.expired invalidates after the order effects apply.
		$expired_order = $this->create_woopayments_order();
		$expired_order->update_meta_data( '_charge_id', 'ch_cache_expired' );
		$expired_order->update_meta_data( '_intent_id', 'pi_cache_expired' );
		$expired_order->save();
		$this->seed_authorization_summary_caches();
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );
		$sut->process(
			array(
				'id'   => 'evt_cache_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id' => 'ch_cache_expired',
					),
				),
			)
		);
		$this->assertAuthorizationSummaryCachesCleared( 'charge.expired' );
	}

	/**
	 * @testdox Unrelated webhook events leave the authorization-summary caches alone.
	 */
	public function test_unrelated_webhook_events_keep_authorization_summary_caches(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();
		$this->seed_authorization_summary_caches();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'card',
						),
					),
				)
			)
		);

		$this->assertNotFalse( get_option( 'wcpay_authorization_summary_cache', false ) );
		$this->assertNotFalse( get_option( 'wcpay_test_authorization_summary_cache', false ) );
	}

	/**
	 * Seed both authorization-summary cache options.
	 */
	private function seed_authorization_summary_caches(): void {
		$wrapper = array(
			'data'    => array( 'count' => 4 ),
			'fetched' => time(),
			'errored' => false,
		);
		update_option( 'wcpay_authorization_summary_cache', $wrapper, false );
		update_option( 'wcpay_test_authorization_summary_cache', $wrapper, false );
	}

	/**
	 * Assert both authorization-summary cache options are gone.
	 *
	 * @param string $event_type Event type under test, for the failure message.
	 */
	private function assertAuthorizationSummaryCachesCleared( string $event_type ): void {
		$this->assertFalse( get_option( 'wcpay_authorization_summary_cache', false ), $event_type . ' should clear the live cache' );
		$this->assertFalse( get_option( 'wcpay_test_authorization_summary_cache', false ), $event_type . ' should clear the test-mode cache' );
	}

	/**
	 * @testdox charge.expired fails the unpaid order, stores the fetched intent status and asks for the right intent.
	 *
	 * An authorization leaves the order on hold with the intent as its transaction ID. The event's `payment_intent` is the
	 * Stripe Charge field (https://docs.stripe.com/api/charges/object#charge_object-payment_intent), which client 11.1.0 reads
	 * before the order's `_intent_id` (class-wc-payments-webhook-processing-service.php:394).
	 *
	 * @dataProvider provider_charge_expired_fails_the_unpaid_order
	 *
	 * @param string              $order_status      Order status before the event.
	 * @param string              $transaction_id    Order transaction ID.
	 * @param array<string,mixed> $order_meta        Meta on the order before the event.
	 * @param array<string,mixed> $charge            Charge object of the event.
	 * @param string              $expected_intent   Intent ID fetched.
	 */
	public function test_charge_expired_fails_the_unpaid_order( string $order_status, string $transaction_id, array $order_meta, array $charge, string $expected_intent ): void {
		$order = $this->create_woopayments_order();
		$order->set_status( $order_status );
		$order->set_transaction_id( $transaction_id );
		foreach ( $order_meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		$this->assertNull( $order->get_date_paid() );
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => $charge,
				),
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( $charge['id'], $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( array( $expected_intent ), $requested_intents );
	}

	/**
	 * Unpaid orders a charge expiry fails.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>,3:array<string,mixed>,4:string}>
	 */
	public function provider_charge_expired_fails_the_unpaid_order(): array {
		return array(
			'pending, intent from the order'         => array(
				'pending',
				'',
				array(
					'_charge_id' => 'ch_expired',
					'_intent_id' => 'pi_expired',
				),
				array( 'id' => 'ch_expired' ),
				'pi_expired',
			),
			'pending capture, intent from the event' => array(
				'pending',
				'',
				array(
					'_charge_id'        => 'ch_expired_status',
					'_intention_status' => 'requires_capture',
				),
				array(
					'id'             => 'ch_expired_status',
					'payment_intent' => 'pi_expired_status',
				),
				'pi_expired_status',
			),
			'authorized, on hold'                    => array(
				'on-hold',
				'pi_authorized',
				array(
					'_intent_id'        => 'pi_authorized',
					'_charge_id'        => 'ch_authorized',
					'_intention_status' => 'requires_capture',
				),
				array(
					'id'             => 'ch_authorized',
					'payment_intent' => 'pi_authorized',
				),
				'pi_authorized',
			),
		);
	}

	/**
	 * @testdox charge.expired on a cancelled order another payment method took over fails it only while the order is unpaid.
	 *
	 * The order keeps the WooPayments authorization the takeover found holding money. Unpaid, the event is the order's
	 * own payment, and the lifecycle moves the cancelled order to failed. Paid by a card or wallet, whose payment the
	 * transaction ID names, the event is another charge and the order keeps its status.
	 *
	 * @dataProvider provider_charge_expired_on_a_cancelled_order_another_payment_method_took_over
	 *
	 * @param string $payment_method  Payment method that took the order over.
	 * @param string $transaction_id  Order transaction ID after the takeover.
	 * @param bool   $paid            Whether the other payment method paid the order.
	 * @param string $expected_status Order status after the event.
	 * @param string $expected_intent Stored intent status after the event.
	 * @param int    $expired_notes   Number of authorization-expired notes after the event.
	 */
	public function test_charge_expired_fails_a_cancelled_order_another_payment_method_took_over( string $payment_method, string $transaction_id, bool $paid, string $expected_status, string $expected_intent, int $expired_notes ): void {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_total( '10.00' );
		$order->set_status( OrderStatus::CANCELLED );
		$order->save();
		$order->set_transaction_id( $transaction_id );
		if ( $paid ) {
			$order->set_date_paid( time() );
		}
		$order->update_meta_data( '_intent_id', 'pi_kept_authorization' );
		$order->update_meta_data( '_charge_id', 'ch_kept_authorization' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$expired_note = sprintf(
			'Payment authorization has <strong>expired</strong> (<a href="%1$s" target="_blank" rel="noopener noreferrer">pi_kept_authorization</a>).',
			$note_service->transaction_url( 'pi_kept_authorization', 'ch_kept_authorization' )
		);
		$sut          = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );

		$sut->process(
			array(
				'id'   => 'evt_kept_authorization_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_kept_authorization',
						'payment_intent' => 'pi_kept_authorization',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( $expected_intent, $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $payment_method, $order->get_payment_method() );
		$this->assertSame( $transaction_id, $order->get_transaction_id() );
		$this->assertSame( $expired_notes, $this->count_order_notes_matching( $order, $expired_note ) );
	}

	/**
	 * Cancelled orders another payment method took over, and what an expiry of the kept authorization leaves.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:string,4:string,5:int}>
	 */
	public function provider_charge_expired_on_a_cancelled_order_another_payment_method_took_over(): array {
		return array(
			'check payments, unpaid' => array( 'cheque', 'pi_kept_authorization', false, 'failed', 'canceled', 1 ),
			'card gateway, paid'     => array( 'stripe', 'ch_other_gateway_payment', true, 'cancelled', 'requires_capture', 0 ),
		);
	}

	/**
	 * @testdox charge.expired adds the current-locale translated authorization-expired note.
	 */
	public function test_charge_expired_adds_translated_authorization_expired_note(): void {
		$this->install_woocommerce_test_translations(
			array(
				'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).' => 'Die Zahlungsautorisierung ist <strong>abgelaufen</strong> (<a>%1$s</a>).',
			)
		);
		switch_to_locale( 'de_DE' );

		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', 'ch_expired' );
		$order->update_meta_data( '_intent_id', 'pi_expired_fallback' );
		$order->save();
		$note_service  = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class );
		$expected_note = sprintf(
			'Die Zahlungsautorisierung ist <strong>abgelaufen</strong> (<a href="%1$s" target="_blank" rel="noopener noreferrer">pi_expired_fallback</a>).',
			$note_service->transaction_url( 'pi_expired_fallback', 'ch_expired' )
		);

		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );

		$sut->process(
			array(
				'id'   => 'evt_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id' => 'ch_expired',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertOrderHasNote( $order, $expected_note );
	}

	/**
	 * @testdox charge.expired adopts the exact German WooPayments extension catalog note.
	 */
	public function test_charge_expired_deduplicates_german_woopayments_extension_note(): void {
		$this->install_test_translations_for_domain(
			'woocommerce',
			array( 'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).' => 'Core-Autorisierung ist <strong>abgelaufen</strong> (<a>%1$s</a>).' )
		);
		$this->install_test_translations_for_domain(
			'woocommerce-payments',
			array( 'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).' => 'Plugin-Autorisierung ist <strong>abgelaufen</strong> (<a>%1$s</a>).' )
		);
		switch_to_locale( 'de_DE' );

		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', 'ch_expired_plugin_note' );
		$order->save();
		$note_service = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class );
		$plugin_note  = sprintf(
			'Plugin-Autorisierung ist <strong>abgelaufen</strong> (<a href="%1$s" target="_blank" rel="noopener noreferrer">pi_expired_event</a>).',
			$note_service->transaction_url( 'pi_expired_event', 'ch_expired_plugin_note' )
		);
		$order->add_order_note( $plugin_note );

		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );

		$sut->process(
			array(
				'id'   => 'evt_expired_plugin_note',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_expired_plugin_note',
						'payment_intent' => 'pi_expired_event',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'ch_expired_plugin_note', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Core-Autorisierung' ) );
	}

	/**
	 * @testdox charge.expired stamps review_expired on a fraud-reviewed order and leaves any other fraud meta box alone.
	 *
	 * @dataProvider provider_charge_expired_fraud_meta_box
	 *
	 * @param string $meta_key          Fraud meta the order carries before the event.
	 * @param string $meta_value        Its value.
	 * @param string $expected_box_type Fraud meta box type after the event.
	 */
	public function test_charge_expired_stamps_the_fraud_meta_box( string $meta_key, string $meta_value, string $expected_box_type ): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', 'ch_expired_review' );
		$order->update_meta_data( '_intent_id', 'pi_expired_review' );
		$order->update_meta_data( $meta_key, $meta_value );
		$order->save();

		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_expired_review',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id' => 'ch_expired_review',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_box_type, $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( array( 'pi_expired_review' ), $requested_intents );
	}

	/**
	 * Fraud states before a charge expiry and the fraud meta box after it.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provider_charge_expired_fraud_meta_box(): array {
		return array(
			'held for review' => array( '_wcpay_fraud_outcome_status', 'review', 'review_expired' ),
			'not reviewed'    => array( '_wcpay_fraud_meta_box_type', 'allow', 'allow' ),
		);
	}

	/**
	 * @testdox charge.expired propagates an intent fetch failure so the event is redelivered.
	 */
	public function test_charge_expired_propagates_intent_fetch_failure(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', 'ch_expired_error' );
		$order->update_meta_data( '_intent_id', 'pi_expired_error' );
		$order->save();

		$api_client = new class() extends Client {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail the intent retrieval.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 * @throws ApiException Always.
			 */
			public function get_payment_intention( string $intent_id ): array {
				unset( $intent_id );
				throw new ApiException( 'boom', 'wcpay_server_error', 500 );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$api_client
		);

		$event = array(
			'id'   => 'evt_expired_error',
			'type' => 'charge.expired',
			'data' => array(
				'object' => array(
					'id' => 'ch_expired_error',
				),
			),
		);

		$thrown = null;
		try {
			$sut->process( $event );
		} catch ( ApiException $exception ) {
			$thrown = $exception;
		}
		$this->assertInstanceOf( ApiException::class, $thrown );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * Create an ingestor whose API client returns a fixture intent for charge.expired.
	 *
	 * @param array<string,mixed>    $intent            Intent fixture merged over defaults.
	 * @param array<int,string>|null $requested_intents Receives the requested intent IDs.
	 * @return EventIngestor
	 */
	private function create_charge_expired_ingestor( array $intent, ?array &$requested_intents = null ): EventIngestor {
		$requested_intents = array();
		$api_client        = new class( $intent, $requested_intents ) extends Client {
			/**
			 * Intent fixture.
			 *
			 * @var array<string,mixed>
			 */
			private array $intent;

			/**
			 * Requested intent IDs.
			 *
			 * @var array<int,string>
			 */
			private $requested_intents;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $intent            Intent fixture.
			 * @param array<int,string>   $requested_intents Requested-intent recorder.
			 */
			public function __construct( array $intent, array &$requested_intents ) {
				$this->intent            = $intent;
				$this->requested_intents = &$requested_intents;
			}

			/**
			 * Retrieve the fixture intent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				$this->requested_intents[] = $intent_id;

				return array_replace_recursive(
					array(
						'id'     => $intent_id,
						'status' => 'canceled',
					),
					$this->intent
				);
			}
		};

		return $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$api_client
		);
	}

	/**
	 * @testdox charge.dispute.created resolves the order by charge ID and places it on hold.
	 *
	 * Fed the REC-DC `charge.dispute.created` webhook body
	 * (`Fixtures/platform-dispute-created-events.json`, pair `accept_case_created`) forwarded from local
	 * WPCOM, instead of the hand-built `create_dispute_event()` envelope. The order's `_charge_id`
	 * meta is set to REC-DC's own recorded charge id so the event resolves to this order exactly as
	 * a real webhook would, and the live-mode filter is forced to match REC-DC's own `livemode: false`
	 * (the recording was captured in Stripe test mode; `create_dispute_event()`'s fixtures omit
	 * `livemode` entirely, which skips this check rather than agreeing with it). The amount (5000
	 * minor units) coincides with the prior hand-built fixture, so the `$50.00` assertion is
	 * unchanged; the due-by date is REC-DC's own recorded `evidence_details.due_by` (1791071999 UTC =
	 * 2026-10-03).
	 */
	public function test_dispute_created_marks_order_on_hold(): void {
		$order = $this->create_paid_woopayments_order( 'ch_3UJbTlBzWlxcwgpP0vNaexjT', 'pi_3UJbTlBzWlxcwgpP0FQ4MWQE' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', 'txn_123' );
		$order->save();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$this->sut->process( $this->load_recorded_dispute_created_event( 'accept_case_created' ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertOrderHasNoteContaining(
			$order,
			array(
				'Payment has been disputed for',
				'&#36;</span>50.00',
				'with reason &quot;Transaction unauthorized&quot;',
				'<a href="' . $this->get_expected_dispute_url( 'ch_3UJbTlBzWlxcwgpP0vNaexjT' ) . '" target="_blank" rel="noopener noreferrer">Response due by October 3, 2026</a>.',
			)
		);
		$this->assertSame( '', $order->get_meta( '_dispute_id', true ) );
	}

	/**
	 * @testdox A $event_type event deletes the stale dispute caches.
	 *
	 * @dataProvider provider_dispute_events_clear_caches
	 *
	 * @param string $event_type     Dispute event type.
	 * @param string $dispute_status Dispute status in the event.
	 */
	public function test_dispute_events_delete_dispute_caches( string $event_type, string $dispute_status ): void {
		$this->create_paid_woopayments_order( 'ch_123', 'pi_123' );
		$this->seed_dispute_cache_options();

		$this->sut->process( $this->create_dispute_event( $event_type, $dispute_status ) );

		$this->assert_dispute_cache_options_deleted();
	}

	/**
	 * @testdox A dispute event whose order is $order_case deletes the dispute caches, keeps its refusal and leaves the order unchanged.
	 *
	 * The caches mirror the platform's dispute list, which the event reports a change to whatever the store does with it.
	 * Client 11.3.0 deletes them only after the event is processed (class-wc-payments-webhook-processing-service.php:872-873, :918-919, :986-988),
	 * so there a dispute with no order leaves the dispute badge and the response-deadline task stale for up to a day.
	 *
	 * @dataProvider provider_refused_dispute_events
	 *
	 * @param string              $order_case       The order the dispute meets.
	 * @param callable            $prepare          Creates the order and returns it with the cleanup to run after the dispute.
	 * @param string              $expected_refusal Exception class the dispute is refused with.
	 * @param array<string,mixed> $dispute          Fields that replace the dispute object's own.
	 * @param string              $expected_message Text the refusal's message contains, when given.
	 */
	public function test_a_refused_dispute_event_deletes_the_dispute_caches( string $order_case, callable $prepare, string $expected_refusal, array $dispute = array(), string $expected_message = '' ): void {
		unset( $order_case );
		$this->seed_dispute_cache_options();
		list( $order, $cleanup ) = $prepare( $this );
		$order                   = wc_get_order( $order->get_id() );
		$status                  = $order->get_status();
		$charge_id               = (string) $order->get_meta( '_charge_id', true );
		$notes                   = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$refusal = null;
		try {
			$this->sut->process( $this->create_dispute_event( 'charge.dispute.created', 'needs_response', $dispute ) );
		} catch ( \Throwable $exception ) {
			$refusal = $exception;
		} finally {
			$cleanup();
		}

		$this->assertInstanceOf( $expected_refusal, $refusal );
		if ( '' !== $expected_message ) {
			$this->assertStringContainsString( $expected_message, $refusal->getMessage() );
		}
		$this->assert_dispute_cache_options_deleted();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( $charge_id, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Dispute events refused before they change the order.
	 *
	 * @return array<string,array{0:string,1:callable,2:string,3?:array<string,mixed>,4?:string}>
	 */
	public function provider_refused_dispute_events(): array {
		return array(
			'not found: another method paid it and the disputed charge is a late WooPayments success' => array(
				'not found',
				static function ( self $test ): array {
					$order = $test->create_woopayments_order();
					$order->set_payment_method( 'bacs' );
					$order->set_payment_method_title( 'Direct bank transfer' );
					$order->set_status( 'processing' );
					$order->save();
					$test->sut->process( $test->create_payment_intent_event( 'payment_intent.succeeded', $order, $test->get_card_charge_overrides() ) );

					return array( $order, static function (): void {} );
				},
				InvalidArgumentException::class,
				array(),
				'Could not find WooPayments order via disputed charge ID',
			),
			'found: its processing refused by a held order payment lock' => array(
				'found but refused',
				static function ( self $test ): array {
					$order      = $test->create_paid_woopayments_order( 'ch_123', 'pi_123' );
					$vocabulary = new WooPaymentsPersistenceVocabulary();
					$test->hold_order_payment_lock( $order, $vocabulary, 'pi_lock_holder' );

					return array(
						$order,
						static function () use ( $test, $order, $vocabulary ): void {
							$test->clear_order_payment_lock( $order, $vocabulary );
						},
					);
				},
				OrderPaymentLockRefusedException::class,
			),
			'not named, by a malformed charge field: refused by the field check' => array(
				'not named, by a malformed charge field',
				static function ( self $test ): array {
					return array( $test->create_paid_woopayments_order( 'ch_123', 'pi_123' ), static function (): void {} );
				},
				InvalidArgumentException::class,
				array( 'charge' => array( 'not-scalar' ) ),
				'Expected scalar dispute webhook property: charge',
			),
			'not found, with a malformed later field: refused as not found' => array(
				'not found, with a malformed field',
				static function ( self $test ): array {
					$order = $test->create_paid_woopayments_order( 'ch_other', 'pi_other' );

					return array( $order, static function (): void {} );
				},
				InvalidArgumentException::class,
				array( 'id' => array( 'not-scalar' ) ),
				'Could not find WooPayments order via disputed charge ID',
			),
			'looked up by a query that throws: the lookup failure is kept' => array(
				'looked up by a query that throws',
				static function ( self $test ): array {
					$order       = $test->create_paid_woopayments_order( 'ch_123', 'pi_123' );
					$break_query = static function ( array $args ): array {
						if ( '_charge_id' === ( $args['meta_key'] ?? null ) ) {
							throw new RuntimeException( 'Order query failed.' );
						}

						return $args;
					};
					add_filter( 'woocommerce_order_query_args', $break_query );

					return array(
						$order,
						static function () use ( $break_query ): void {
							remove_filter( 'woocommerce_order_query_args', $break_query );
						},
					);
				},
				RuntimeException::class,
				array(),
				'Order query failed.',
			),
			'found, with a malformed later field: refused by the field check' => array(
				'found, with a malformed field',
				static function ( self $test ): array {
					return array( $test->create_paid_woopayments_order( 'ch_123', 'pi_123' ), static function (): void {} );
				},
				InvalidArgumentException::class,
				array( 'id' => array( 'not-scalar' ) ),
				'Expected scalar dispute webhook property: id',
			),
		);
	}

	/**
	 * Dispute events that clear the dispute caches.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_dispute_events_clear_caches(): array {
		return array(
			'created' => array( 'charge.dispute.created', 'needs_response' ),
			'updated' => array( 'charge.dispute.updated', 'needs_response' ),
			'closed'  => array( 'charge.dispute.closed', 'won' ),
		);
	}

	/**
	 * @testdox charge.dispute.created uses inquiry wording for warning dispute statuses.
	 */
	public function test_dispute_created_uses_inquiry_note_for_warning_status(): void {
		$order = $this->create_paid_woopayments_order( 'ch_123', 'pi_123' );

		$this->sut->process( $this->create_dispute_event( 'charge.dispute.created', 'warning_needs_response' ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertOrderHasNoteContaining(
			$order,
			array(
				'A payment inquiry has been raised for',
				'&#36;</span>50.00',
				'with reason &quot;Transaction unauthorized&quot;',
				'Response due by July 1, 2026',
				'path=%2Fpayments%2Ftransactions%2Fdetails',
				'id=ch_123',
			)
		);
	}

	/**
	 * @testdox A created dispute without an ID records one bare note and clears caches on replay.
	 */
	public function test_dispute_created_without_id_adds_bare_note_and_invalidates_caches_on_replay(): void {
		$order = $this->create_paid_woopayments_order( 'ch_123', 'pi_123' );

		$missing_id_event       = $this->create_dispute_event( 'charge.dispute.created', 'needs_response' );
		$missing_id_event['id'] = 'evt_row_39_missing_id';
		unset( $missing_id_event['data']['object']['id'] );
		$null_id_event                         = $missing_id_event;
		$null_id_event['id']                   = 'evt_row_39_null_id';
		$null_id_event['data']['object']['id'] = null;

		$this->sut->process( $missing_id_event );
		$this->seed_dispute_cache_options();
		$this->sut->process( $null_id_event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'Payment has been disputed for' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Payment has been disputed for', 'Dispute ID' ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assert_dispute_cache_options_deleted();
	}

	/**
	 * @testdox charge.dispute.updated adds the reference dispute update note without changing order status.
	 *
	 * The funds withdrawn row is the event the platform forwarded (Fixtures/platform-charge-dispute-events.json), on the
	 * order paid by its charge. The platform does not forward charge.dispute.updated, and no funds_reinstated was delivered
	 * locally, so those rows keep the fields the handler reads.
	 *
	 * @dataProvider dispute_update_event_provider
	 *
	 * @param string $event_type Event type.
	 * @param string $message    Expected message.
	 * @param string $pair       Recorded event, or '' for a built one.
	 */
	public function test_dispute_updates_add_notes_without_changing_status( string $event_type, string $message, string $pair = '' ): void {
		if ( '' !== $pair ) {
			// The recorded events are test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
			update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		}
		$event   = '' !== $pair ? RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', $pair ) : $this->create_dispute_event( $event_type, 'needs_response' );
		$dispute = $event['data']['object'];
		$order   = $this->create_paid_woopayments_order( $dispute['charge'], $dispute['payment_intent'] ?? 'pi_123' );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertOrderHasNote( $order, $message . '. See <a href="' . $this->get_expected_dispute_update_url( $dispute['charge'] ) . '">dispute overview</a> for more details. (Dispute ID: ' . $dispute['id'] . ')' );
	}

	/**
	 * @testdox charge.dispute.updated de-duplicates update notes structurally across locales.
	 */
	public function test_dispute_updated_dedupes_notes_across_locale_renderings(): void {
		$order = $this->create_paid_woopayments_order( 'ch_123', 'pi_123' );
		$event = $this->create_dispute_event( 'charge.dispute.updated', 'needs_response' );

		$event['id'] = 'evt_dispute_updated_locale_dedupe_1';
		$this->sut->process( $event );

		$this->install_woocommerce_test_translations(
			array(
				'Payment dispute has been updated' => 'Zahlungsdisput wurde aktualisiert',
				'%1$s. See <a href="%2$s">dispute overview</a> for more details.' => '%1$s. Weitere Details in der <a href="%2$s">Disputuebersicht</a>.',
			)
		);

		$event['id'] = 'evt_dispute_updated_locale_dedupe_2';
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertOrderHasNoteContaining( $order, array( 'Payment dispute has been updated', 'dispute overview' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Zahlungsdisput wurde aktualisiert', 'Disputuebersicht' ) );
	}

	/**
	 * @testdox Distinct disputes on one charge each record their own funds-withdrawn note.
	 *
	 * Both events are funds_withdrawn deliveries the platform forwarded (Fixtures/platform-charge-dispute-events.json); the
	 * second dispute is moved onto the first one's charge, the case this test is about.
	 */
	public function test_dispute_updates_record_a_note_per_dispute(): void {
		// The recorded events are test-mode deliveries (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$first_event  = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', 'dispute_funds_withdrawn' );
		$second_event = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', 'second_dispute_funds_withdrawn' );
		$first        = $first_event['data']['object'];

		$second_event['data']['object']['charge']         = $first['charge'];
		$second_event['data']['object']['payment_intent'] = $first['payment_intent'];

		$second = $second_event['data']['object'];
		$order  = $this->create_paid_woopayments_order( $first['charge'], $first['payment_intent'] );

		$replay_event       = $first_event;
		$first_event['id']  = 'evt_row_38_update_first';
		$second_event['id'] = 'evt_row_38_update_second';
		$replay_event['id'] = 'evt_row_38_update_replay';

		$this->sut->process( $first_event );
		$this->sut->process( $second_event );
		$this->sut->process( $replay_event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertCount( 2, $this->get_order_notes_containing( $order, 'Payment dispute and fees have been deducted from your next payout' ) );
		$this->assertOrderHasNoteContaining( $order, array( 'Payment dispute and fees have been deducted from your next payout', '(Dispute ID: ' . $first['id'] . ')' ) );
		$this->assertOrderHasNoteContaining( $order, array( 'Payment dispute and fees have been deducted from your next payout', '(Dispute ID: ' . $second['id'] . ')' ) );
	}

	/**
	 * @testdox A duplicate dispute update keeps one note and still clears stale dispute caches.
	 */
	public function test_dispute_update_redelivery_invalidates_caches_without_duplicate_note(): void {
		$order = $this->create_paid_woopayments_order( 'ch_123', 'pi_123' );

		$event = $this->create_dispute_event( 'charge.dispute.updated', 'needs_response', array( 'id' => 'dp_cache_replay' ) );

		$this->sut->process( $event );
		$this->seed_dispute_cache_options();
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'Payment dispute has been updated' ) );
		$this->assert_dispute_cache_options_deleted();
	}

	/**
	 * @testdox A dispute update without an ID records the bare note and clears stale caches.
	 */
	public function test_dispute_update_without_id_adds_bare_note_and_invalidates_caches(): void {
		$order = $this->create_paid_woopayments_order( 'ch_123', 'pi_123' );

		$event       = $this->create_dispute_event( 'charge.dispute.updated', 'needs_response' );
		$event['id'] = 'evt_row_38_missing_id';
		unset( $event['data']['object']['id'] );
		$this->seed_dispute_cache_options();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'Payment dispute has been updated' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Payment dispute has been updated', 'Dispute ID' ) );
		$this->assert_dispute_cache_options_deleted();
	}

	/**
	 * Provide dispute update events.
	 *
	 * @return array<string,array{0:string,1:string,2?:string}>
	 */
	public function dispute_update_event_provider(): array {
		return array(
			'updated'          => array( 'charge.dispute.updated', 'Payment dispute has been updated' ),
			'funds withdrawn'  => array( 'charge.dispute.funds_withdrawn', 'Payment dispute and fees have been deducted from your next payout', 'dispute_funds_withdrawn' ),
			'funds reinstated' => array( 'charge.dispute.funds_reinstated', 'Payment dispute funds have been reinstated' ),
		);
	}

	/**
	 * @testdox charge.dispute.closed completes the order for won disputes.
	 */
	public function test_dispute_closed_won_completes_order(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client()
		);

		$sut->process( $this->create_dispute_event( 'charge.dispute.closed', 'won' ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertOrderHasNote( $order, 'Dispute has been closed with status won. See <a href="' . $this->get_expected_dispute_url( 'ch_123' ) . '" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details. (Dispute ID: du_123)' );
		// Client os:632/os:692: only a `lost` status creates a local refund.
		$this->assertCount( 0, $order->get_refunds(), 'A won dispute must never create a local refund.' );
	}

	/**
	 * @testdox charge.dispute.closed for a lost dispute, delivered twice, creates one capped local refund.
	 *
	 * The summary is the recorded `accept_summary_after_close` response (`Fixtures/platform-disputes.json`) with a 5.00
	 * disputed amount, so the dispute covers part of the order.
	 */
	public function test_dispute_closed_lost_creates_local_refund(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client( array_replace( $this->load_recorded_dispute_summary( 'accept_summary_after_close' ), array( 'disputed_amount' => 500 ) ) )
		);

		$event = $this->create_dispute_event( 'charge.dispute.closed', 'lost' );

		$sut->process( $event );
		$sut->process( $event );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order instanceof WC_Order ? $order->get_refunds() : array();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $refunds );
		$this->assertSame( '-5.00', $refunds[0]->get_total() );
		$this->assertSame( 'Dispute lost.', $refunds[0]->get_reason() );
		$this->assertSame( '', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertOrderHasNote( $order, 'Dispute has been closed with status lost. See <a href="' . $this->get_expected_dispute_url( 'ch_123' ) . '" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details. (Dispute ID: du_123)' );
	}

	/**
	 * @testdox charge.dispute.closed lost for the full disputed amount refunds the order total with line items.
	 *
	 * Client `os:632-661`: when the disputed amount is not less than the order
	 * total, the refund amount is `min(remaining, disputed)` and the order's line
	 * items are kept intact; only a partial dispute clears them. REC-5b R-e
	 * `accept_closed_lost` (`Fixtures/platform-dispute-closed-events.json`) is a real
	 * full-amount lost dispute on a $50.00 charge, fed to the ingestor unchanged;
	 * REC-5b R-d `accept_summary_after_close` (`Fixtures/platform-disputes.json`) is
	 * the matching recorded `get_dispute_summary()` response. Neither recorded
	 * charge had a Woo order (R-e's own store observation), so the order here is a
	 * fixture matched to the recorded charge ID, not the recording itself.
	 */
	public function test_dispute_closed_lost_for_full_disputed_amount_refunds_order_total_with_line_items(): void {
		// The recorded event carries a real `livemode: false`; native's own
		// ingestor drops it as a test/live mismatch unless test mode is on.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$order   = $this->create_woopayments_order();
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '50.00' );
		$product->set_price( '50.00' );
		$product->save();
		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => 50.00,
				'total'    => 50.00,
			)
		);
		$order->set_total( '50.00' );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_3UJbTlBzWlxcwgpP0vNaexjT' );
		$order->save();

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client( $this->load_recorded_dispute_summary( 'accept_summary_after_close' ) )
		);

		$sut->process( $this->load_recorded_dispute_closed_event( 'accept_closed_lost' ) );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order instanceof WC_Order ? $order->get_refunds() : array();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $refunds );
		$this->assertSame( '-50.00', $refunds[0]->get_total() );
		$this->assertSame( 'Dispute lost.', $refunds[0]->get_reason() );
		$this->assertCount( 1, $refunds[0]->get_items(), 'A full-amount dispute must refund the order line items, unlike a partial one.' );
	}

	/**
	 * @testdox charge.dispute.closed lost for the full charge after a partial refund refunds the remainder with line items and no restock.
	 *
	 * Client `os:632-661`: the refund amount is `min(remaining, disputed)`, but the
	 * line items are cleared only when the disputed amount itself is below the order
	 * total. A 50.00 dispute on a 50.00 order that already had 10.00 refunded is a
	 * full dispute, so the 40.00 refund keeps its line items. The client passes no
	 * `restock_items`, so the refund never asks to restock.
	 */
	public function test_dispute_closed_lost_after_partial_refund_keeps_line_items(): void {
		$order = $this->create_refundable_woopayments_order( '50.00' );
		$order->set_status( 'on-hold' );
		$order->save();
		$this->create_local_refund( $order, 10.0, 'Partial refund' );

		$restock_requests = 0;
		$count_restock    = function ( $can_restock ) use ( &$restock_requests ) {
			++$restock_requests;
			return $can_restock;
		};
		add_filter( 'woocommerce_can_restock_refunded_items', $count_restock );

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client(
				array(
					'disputed_amount' => 5000,
					'currency'        => 'usd',
				)
			)
		);

		$sut->process( $this->create_dispute_event( 'charge.dispute.closed', 'lost', array( 'charge' => $this->last_refund_charge_id ) ) );
		remove_filter( 'woocommerce_can_restock_refunded_items', $count_restock );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order instanceof WC_Order ? $order->get_refunds() : array();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 2, $refunds );
		$dispute_refunds = array_values(
			array_filter(
				$refunds,
				static function ( WC_Order_Refund $refund ): bool {
					return 'Dispute lost.' === $refund->get_reason();
				}
			)
		);
		$this->assertCount( 1, $dispute_refunds );
		$this->assertSame( '-40.00', $dispute_refunds[0]->get_total() );
		$this->assertCount( 1, $dispute_refunds[0]->get_items(), 'A full-amount dispute keeps the line items even after a partial refund.' );
		$this->assertSame( 0, $restock_requests, 'The lost-dispute refund must not ask to restock items.' );
	}

	/**
	 * @testdox Distinct lost disputes on one charge each create their own refund and note.
	 */
	public function test_dispute_closed_lost_creates_refund_for_each_distinct_dispute(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client(
				array(
					'disputed_amount' => 500,
					'currency'        => 'usd',
				)
			)
		);

		$first_event        = $this->create_dispute_event( 'charge.dispute.closed', 'lost', array( 'id' => 'dp_lost_first' ) );
		$second_event       = $this->create_dispute_event( 'charge.dispute.closed', 'lost', array( 'id' => 'dp_lost_second' ) );
		$replay_event       = $first_event;
		$first_event['id']  = 'evt_row_38_lost_first';
		$second_event['id'] = 'evt_row_38_lost_second';
		$replay_event['id'] = 'evt_row_38_lost_replay';

		$sut->process( $first_event );
		$sut->process( $second_event );
		$sut->process( $replay_event );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order instanceof WC_Order ? $order->get_refunds() : array();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 2, $refunds );
		$this->assertEqualsCanonicalizing( array( -5.0, -5.0 ), array_map( static fn( WC_Order_Refund $refund ): float => (float) $refund->get_total(), $refunds ) );
		$this->assertCount( 2, $this->get_order_notes_containing( $order, 'Dispute has been closed with status lost' ) );
		$this->assertOrderHasNoteContaining( $order, array( 'Dispute has been closed with status lost', '(Dispute ID: dp_lost_first)' ) );
		$this->assertOrderHasNoteContaining( $order, array( 'Dispute has been closed with status lost', '(Dispute ID: dp_lost_second)' ) );
	}

	/**
	 * @testdox charge.dispute.closed lost fails closed when the local refund cannot be created.
	 */
	public function test_dispute_closed_lost_fails_closed_when_local_refund_creation_fails(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();
		$refund_blocker = static function (): void {
			throw new Exception( 'Refund creation blocked.' );
		};

		$sut = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$this->create_dispute_summary_api_client(
				array(
					'disputed_amount' => 500,
					'currency'        => 'usd',
				)
			)
		);

		add_action( 'woocommerce_create_refund', $refund_blocker );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger    = RecordingWcLogger::install();
		$exception = null;
		try {
			$sut->process( $this->create_dispute_event( 'charge.dispute.closed', 'lost' ) );
		} catch ( RuntimeException $caught ) {
			$exception = $caught;
		} finally {
			remove_action( 'woocommerce_create_refund', $refund_blocker );
		}

		$this->assertInstanceOf( RuntimeException::class, $exception, 'Expected lost dispute processing to fail when local refund creation fails.' );
		$this->assertStringContainsString( 'Could not create local dispute refund', $exception->getMessage() );
		// Written with logging off: the IDs and wc_create_refund()'s fixed code, never the WP_Error's message.
		$context = $this->get_logged_context( $logger, 'Failed to create local refund for lost dispute du_123 on charge ch_123.' );
		$this->assertSame(
			array( $order->get_id(), 'ch_123', 'du_123', 'error' ),
			array( $context['order_id'] ?? null, $context['charge_id'] ?? '', $context['dispute_id'] ?? '', $context['error_code'] ?? '' )
		);
		$this->assertStringNotContainsString( 'Refund creation blocked', (string) wp_json_encode( $context ) );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order instanceof WC_Order ? $order->get_refunds() : array();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 0, $refunds );
		$this->assertOrderLacksNoteContaining( $order, array( 'Dispute has been closed with status lost' ) );
	}

	/**
	 * @testdox charge.dispute.closed fails closed when required dispute fields are missing.
	 *
	 * @dataProvider malformed_closed_dispute_event_provider
	 *
	 * @param string $missing_field Missing field.
	 */
	public function test_dispute_closed_fails_closed_when_required_fields_are_missing( string $missing_field ): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();
		$event = $this->create_dispute_event( 'charge.dispute.closed', 'won' );
		unset( $event['data']['object'][ $missing_field ] );

		$exception = null;
		try {
			$this->sut->process( $event );
		} catch ( InvalidArgumentException $caught ) {
			$exception = $caught;
		}

		$this->assertInstanceOf( InvalidArgumentException::class, $exception, 'Expected malformed dispute closed event to be refused.' );
		$this->assertStringContainsString( $missing_field, $exception->getMessage() );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertOrderLacksNoteContaining( $order, array( 'Dispute has been closed' ) );
	}

	/**
	 * Provide malformed closed dispute event fields.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function malformed_closed_dispute_event_provider(): array {
		return array(
			'missing status' => array( 'status' ),
			'missing id'     => array( 'id' ),
		);
	}

	/**
	 * @testdox charge.dispute.created fails closed when required dispute fields are missing.
	 *
	 * @dataProvider malformed_created_dispute_event_provider
	 *
	 * @param string[] $missing_path Missing field path under the dispute object.
	 * @param string   $missing_key  Missing field name.
	 */
	public function test_dispute_created_fails_closed_when_required_fields_are_missing( array $missing_path, string $missing_key ): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();
		$event = $this->create_dispute_event( 'charge.dispute.created', 'needs_response' );
		$this->unset_dispute_object_path( $event, $missing_path );

		$exception = null;
		try {
			$this->sut->process( $event );
		} catch ( InvalidArgumentException $caught ) {
			$exception = $caught;
		}

		$this->assertInstanceOf( InvalidArgumentException::class, $exception, 'Expected malformed dispute created event to be refused.' );
		$this->assertStringContainsString( $missing_key, $exception->getMessage() );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertOrderLacksNoteContaining( $order, array( 'Payment has been disputed' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'A payment inquiry has been raised' ) );
	}

	/**
	 * Provide malformed created dispute event fields.
	 *
	 * @return array<string,array{0:string[],1:string}>
	 */
	public function malformed_created_dispute_event_provider(): array {
		return array(
			'missing status'           => array( array( 'status' ), 'status' ),
			'missing reason'           => array( array( 'reason' ), 'reason' ),
			'missing amount'           => array( array( 'amount' ), 'amount' ),
			'missing evidence details' => array( array( 'evidence_details' ), 'evidence_details' ),
			'missing due by'           => array( array( 'evidence_details', 'due_by' ), 'due_by' ),
		);
	}

	/**
	 * @testdox charge.dispute.closed with no matching order is refused.
	 */
	public function test_dispute_closed_without_matching_charge_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->sut->process( $this->create_dispute_event( 'charge.dispute.closed', 'won' ) );
	}

	/**
	 * @testdox Without the Stripe Billing module, $event_type is refused with the WooPayments extension's reason, after the before-delivery hook only, on every delivery, and is not logged here.
	 *
	 * Client 11.1.0 without WooCommerce Subscriptions: the event handler's subscription lookup finds nothing and throws
	 * `Invalid_Webhook_Data_Exception` (`class-wc-payments-subscriptions-event-handler.php:79,138,233`), so the
	 * after-delivery hook never fires (`class-wc-payments-webhook-processing-service.php:175,241,254`) and the webhook
	 * answers 400 (`class-wc-rest-payments-webhook-controller.php:81-83`).
	 *
	 * @dataProvider provider_invoice_events_refused_without_the_module
	 *
	 * @param string $event_type Invoice event type.
	 * @param string $reason     The client's refusal reason.
	 */
	public function test_invoice_event_without_the_stripe_billing_module_is_refused_like_the_woopayments_extension( string $event_type, string $reason ): void {
		$logger = RecordingWcLogger::install();
		$sut    = $this->create_ingestor_without_the_stripe_billing_module();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$hook_calls = array();
		foreach ( array( 'before', 'after' ) as $moment ) {
			add_action(
				"woocommerce_payments_{$moment}_webhook_delivery",
				static function ( string $hook_event_type ) use ( &$hook_calls, $moment ): void {
					$hook_calls[] = "$moment $hook_event_type";
				}
			);
		}
		$event = array(
			'id'       => 'evt_invoice_without_module',
			'type'     => $event_type,
			'livemode' => false,
			'data'     => array( 'object' => array( 'id' => 'in_123' ) ),
		);

		$refusals = array();
		for ( $delivery = 0; $delivery < 2; $delivery++ ) {
			try {
				$sut->process( $event );
			} catch ( \InvalidArgumentException $exception ) {
				$refusals[] = $exception->getMessage();
			}
		}

		$this->assertSame( array( $reason, $reason ), $refusals, 'Each delivery is refused.' );
		$this->assertSame( array( "before $event_type", "before $event_type" ), $hook_calls );
		// As on the client, the webhook route or the failed-event job that catches the refusal writes its one log line.
		$this->assertSame( array(), $logger->get_errors() );
	}

	/**
	 * Invoice event types and the client's refusal reasons when WooCommerce Subscriptions is not active.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_invoice_events_refused_without_the_module(): array {
		return array(
			'invoice.paid'           => array( 'invoice.paid', 'Cannot find subscription for the incoming "invoice.paid" event.' ),
			'invoice.payment_failed' => array( 'invoice.payment_failed', 'Cannot find subscription for the incoming "invoice.payment_failed" event.' ),
			'invoice.upcoming'       => array( 'invoice.upcoming', 'Cannot find subscription to handle the "invoice.upcoming" event.' ),
		);
	}

	/**
	 * @testdox Without the Stripe Billing module, an invoice event in the other mode is dropped by the mode check first, as with the WooPayments extension.
	 */
	public function test_invoice_event_without_the_stripe_billing_module_is_dropped_on_mode_mismatch(): void {
		$logger = RecordingWcLogger::install();
		$sut    = $this->create_ingestor_without_the_stripe_billing_module();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$hook_calls = 0;
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			static function () use ( &$hook_calls ): void {
				++$hook_calls;
			}
		);

		$sut->process(
			array(
				'id'       => 'evt_invoice_without_module_live',
				'type'     => 'invoice.paid',
				'livemode' => true,
				'data'     => array( 'object' => array( 'id' => 'in_123' ) ),
			)
		);

		// Client 11.1.0 `class-wc-payments-webhook-processing-service.php:162-164`: the mode check runs before any hook or handler.
		$this->assertSame( 0, $hook_calls );
		$this->assertSame( array( array( 'error', 'Webhook event mode did not match the gateway mode (event ID: evt_invoice_without_module_live)', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * Create an ingestor with the Stripe Billing module not loaded.
	 *
	 * @return EventIngestor
	 */
	private function create_ingestor_without_the_stripe_billing_module(): EventIngestor {
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( StripeBillingModule::class, $module );

		return $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends Client {
				/**
				 * Retrieve a WooPayments PaymentIntent.
				 *
				 * @param string $intent_id Intent ID.
				 * @return array<string,mixed>
				 */
				public function get_payment_intention( string $intent_id ): array {
					return array();
				}
			}
		);
	}

	/**
	 * @testdox charge.refunded creates a full local refund with WooPayments metadata.
	 */
	public function test_charge_refunded_creates_full_local_refund_with_wcpay_metadata(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order  = $this->create_refundable_woopayments_order( '10.00' );
		$event  = $this->create_charge_refunded_event( $order, 1000, 1000, 'succeeded' );
		$refund = $event['data']['object']['refunds']['data'][0];

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertInstanceOf( WC_Order_Refund::class, $refunds[0] );
		$this->assertSame( '-10.00', $refunds[0]->get_total() );
		$this->assertCount( 1, $refunds[0]->get_items(), 'Full external refunds should preserve refunded line items.' );
		$this->assertSame( 'requested_by_customer', $refunds[0]->get_reason() );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( $refund['id'], $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( $refund['balance_transaction'], $refunds[0]->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertOrderHasNoteContaining( $order, array( 'A refund of', 'was successfully processed using WooPayments', 'requested_by_customer', $refund['id'] ) );
	}

	/**
	 * @testdox charge.refunded creates a pending partial local refund.
	 */
	public function test_charge_refunded_creates_pending_partial_refund(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order     = $this->create_refundable_woopayments_order( '10.00' );
		$event     = $this->create_charge_refunded_event( $order, 1000, 400, 'pending' );
		$refund_id = $event['data']['object']['refunds']['data'][0]['id'];

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertInstanceOf( WC_Order_Refund::class, $refunds[0] );
		$this->assertSame( '-4.00', $refunds[0]->get_total() );
		$this->assertCount( 0, $refunds[0]->get_items(), 'Partial external refunds should not synthesize line-item refunds.' );
		$this->assertSame( 'pending', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( $refund_id, $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertOrderHasNoteContaining( $order, array( 'A refund of', 'is pending', 'WooPayments', $refund_id ) );
	}

	/**
	 * @testdox Succeeded, failed and authorized intents, refund and dispute events, and invoice.paid while Stripe Billing is loaded, are retried after a failure.
	 */
	public function test_retried_event_types(): void {
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded' ) )
			->getMock();
		$loaded = false;
		$module->method( 'is_loaded' )->willReturnCallback(
			static function () use ( &$loaded ): bool {
				return $loaded;
			}
		);
		wc_get_container()->replace( StripeBillingModule::class, $module );

		$retried = fn( string $type ): bool => $this->sut->is_retried_event( array( 'type' => $type ) );

		foreach ( array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.amount_capturable_updated', 'charge.refunded', 'charge.refund.updated', 'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' ) as $type ) {
			$this->assertTrue( $retried( $type ), "$type must be retried." );
		}
		foreach ( array( 'charge.expired', 'radar.early_fraud_warning.created', 'invoice.paid' ) as $type ) {
			$this->assertFalse( $retried( $type ), "$type must get one attempt." );
		}

		$loaded = true;
		$this->assertTrue( $retried( 'invoice.paid' ), 'invoice.paid is retried while Stripe Billing is loaded.' );
		foreach ( array( 'invoice.payment_failed', 'invoice.upcoming' ) as $type ) {
			$this->assertFalse( $retried( $type ), "$type must get one attempt: its handler is not safe to run twice." );
		}
	}

	/**
	 * @testdox While the Stripe Billing module is loaded, invoice events go to it after the mode check, between the delivery hooks.
	 */
	public function test_invoice_events_go_to_the_stripe_billing_module_after_the_mode_check(): void {
		$calls  = array();
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( true );
		$module->method( 'handle_invoice_event' )->willReturnCallback(
			static function ( array $event ) use ( &$calls ): void {
				$calls[] = 'module ' . $event['id'];
			}
		);
		wc_get_container()->replace( StripeBillingModule::class, $module );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		foreach ( array( 'before', 'after' ) as $moment ) {
			add_action(
				"woocommerce_payments_{$moment}_webhook_delivery",
				static function ( string $event_type, array $event ) use ( &$calls, $moment ): void {
					$calls[] = "$moment {$event['id']}";
				},
				10,
				2
			);
		}

		foreach ( array( 'invoice.paid', 'invoice.payment_failed', 'invoice.upcoming', 'invoice.paid' ) as $index => $event_type ) {
			$this->sut->process(
				array(
					'id'       => "evt_rec63_invoice_$index",
					'type'     => $event_type,
					'livemode' => 3 === $index,
					'data'     => array( 'object' => array( 'id' => 'in_rec63_invoice' ) ),
				)
			);
		}

		$this->assertSame(
			array(
				'before evt_rec63_invoice_0',
				'module evt_rec63_invoice_0',
				'after evt_rec63_invoice_0',
				'before evt_rec63_invoice_1',
				'module evt_rec63_invoice_1',
				'after evt_rec63_invoice_1',
				'before evt_rec63_invoice_2',
				'module evt_rec63_invoice_2',
				'after evt_rec63_invoice_2',
			),
			$calls,
			'A live event reaching a test-mode store is dropped by the mode check, as any event.'
		);
	}

	/**
	 * @testdox A succeeded intent that carries an invoice and no order ID is left to its invoice event, as with the WooPayments extension.
	 *
	 * Client 11.1.0 `class-wc-payments-webhook-processing-service.php:968-993`. The recorded renewal intent
	 * (`Fixtures/platform-invoice-events.json`, `payment_intent_succeeded_with_invoice`) reaches the store about one
	 * second before its `invoice.paid`, when the renewal order may already exist unpaid.
	 */
	public function test_succeeded_intent_with_an_invoice_and_no_order_id_is_left_to_the_invoice_event(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/platform-invoice-events.json' ), true );
		$event     = array_values( array_filter( $recording['supporting_entries'], static fn( array $entry ) => 'payment_intent_succeeded_with_invoice' === $entry['pair'] ) )[0]['body'];

		$renewal_order = $this->create_woopayments_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', $event['data']['object']['invoice'] );
		$renewal_order->save();
		$delivered = array();
		add_action(
			'woocommerce_payments_after_webhook_delivery',
			static function ( string $event_type ) use ( &$delivered ): void {
				$delivered[] = $event_type;
			}
		);

		$this->sut->process( $event );

		$renewal_order = wc_get_order( $renewal_order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $renewal_order );
		$this->assertSame( 'pending', $renewal_order->get_status(), 'Only invoice.paid completes the renewal order.' );
		$this->assertSame( '', $renewal_order->get_meta( '_intent_id', true ) );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $renewal_order->get_id() ) ) );
		$this->assertSame( array( 'payment_intent.succeeded' ), $delivered );
	}

	/**
	 * @testdox charge.refunded accepts split-UPE WooPayments gateway IDs.
	 */
	public function test_charge_refunded_accepts_split_upe_gateway_ids(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_refundable_woopayments_order( '10.00' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit' );
		$order->save();

		$this->sut->process( $this->create_charge_refunded_event( $order, 1000, 400, 'succeeded' ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox charge.refunded ignores canceled authorizations.
	 *
	 * Stripe reports a released authorization as a refunded, uncaptured charge; the event is the one the platform forwarded
	 * for an in-person authorization canceled before capture.
	 */
	public function test_charge_refunded_ignores_uncaptured_charges(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_refundable_woopayments_order( '10.00' );

		$this->sut->process( $this->create_charge_refunded_event( $order, 1000, 1000, 'succeeded', 'in_person_authorization_refunded' ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds(), 'Uncaptured charges should not create local refunds.' );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox charge.refunded uses the shared order payment lock.
	 */
	public function test_charge_refunded_fails_closed_when_order_payment_is_locked(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order      = $this->create_refundable_woopayments_order( '10.00' );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->hold_order_payment_lock( $order, $vocabulary, 'existing_operation' );

		try {
			$this->sut->process( $this->create_charge_refunded_event( $order, 1000, 400, 'succeeded' ) );
			$this->fail( 'Expected locked order refund webhook to fail closed.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$this->assertSame( $order->get_id(), $exception->get_order_id() );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox A $status charge.refund.updated deletes the linked local refund, fires no refund deleted hook and leaves a $order_status order $expected_status.
	 *
	 * Client 11.1.0 deletes the refund and fires nothing (class-wc-payments-order-service.php:1965-1966), and marks the order
	 * failed only when it is refunded (:2002-2005). The hook assertion fails when the handler fires woocommerce_refund_deleted.
	 *
	 * @dataProvider provider_refund_update_failure_deletes_the_linked_refund
	 *
	 * @param string   $status          Refund status in the event.
	 * @param string   $failure_reason  Refund failure reason in the event, '' for none.
	 * @param string[] $note_fragments  Fragments of the note.
	 * @param string   $order_status    Order status before the event.
	 * @param string   $expected_status Order status after the event.
	 */
	public function test_refund_update_failure_deletes_the_linked_refund( string $status, string $failure_reason, array $note_fragments, string $order_status, string $expected_status ): void {
		$order     = $this->create_refundable_woopayments_order( '10.00' );
		$refund    = $this->create_local_refund( $order, 4.00, 'Existing refund' );
		$refund_id = $refund->get_id();
		$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
		$refund->save_meta_data();
		$order->set_status( $order_status );
		$order->save();
		$hook_calls              = array();
		$refund_deleted_callback = function ( int $deleted_refund_id, int $deleted_order_id ) use ( &$hook_calls ): void {
			$hook_calls[] = array( $deleted_refund_id, $deleted_order_id );
		};
		add_action( 'woocommerce_refund_deleted', $refund_deleted_callback, 10, 2 );

		try {
			$this->sut->process(
				$this->create_refund_updated_event(
					array_filter(
						array(
							'status'         => $status,
							'failure_reason' => $failure_reason,
						)
					)
				)
			);
		} finally {
			remove_action( 'woocommerce_refund_deleted', $refund_deleted_callback, 10 );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertFalse( wc_get_order( $refund_id ), 'The matched local refund object should be deleted after the provider marks it ' . $status . '.' );
		$this->assertNull( get_post( $refund_id ), 'The matched local refund post should be deleted after the provider marks it ' . $status . '.' );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertOrderHasNoteContaining( $order, $note_fragments );
		$this->assertSame( array(), $hook_calls, 'WooCommerce fires woocommerce_refund_deleted only from its own refund deletion.' );
	}

	/**
	 * Refund updates that undo a linked local refund.
	 *
	 * @return array<string,array{0:string,1:string,2:string[],3:string,4:string}>
	 */
	public function provider_refund_update_failure_deletes_the_linked_refund(): array {
		$failed = array( 'A refund of', 'unsuccessful', 'WooPayments', 're_123', 'lost or stolen' );

		return array(
			'failed, lost or stolen card'                 => array( 'failed', 'lost_or_stolen_card', $failed, 'refunded', 'failed' ),
			'canceled'                                    => array( 'canceled', '', array( 'A refund of', 'cancelled', 'WooPayments', 're_123' ), 'refunded', 'failed' ),
			'failed partial refund on a processing order' => array( 'failed', 'lost_or_stolen_card', $failed, 'processing', 'processing' ),
		);
	}

	/**
	 * @testdox A replay of a noted failure preserves a manual refund and the current stored refund status.
	 *
	 * @testWith [""]
	 *           ["pending"]
	 *           ["successful"]
	 *           ["failed"]
	 * @param string $refund_status Stored refund status when the event arrives again.
	 */
	public function test_noted_refund_failure_preserves_a_manual_refund( string $refund_status ): void {
		$order = $this->create_refundable_woopayments_order( '10.00' );
		$event = $this->create_refund_updated_event(
			array(
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		if ( '' === $refund_status ) {
			$order->delete_meta_data( '_wcpay_refund_status' );
		} else {
			$order->update_meta_data( '_wcpay_refund_status', $refund_status );
		}
		$order->set_status( 'refunded' );
		$order->save();
		$notifications = did_action( 'woocommerce_order_status_failed_notification' );
		$saves         = did_action( 'woocommerce_after_order_object_save' );
		$rows          = array_map( static fn( $refund ) => $refund->get_id(), $order->get_refunds() );
		$notes         = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( $refund_status, $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( $rows, array_map( static fn( $refund ) => $refund->get_id(), $order->get_refunds() ) );
		$this->assertEquals( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( $saves, did_action( 'woocommerce_after_order_object_save' ) );
		$this->assertSame( $notifications, did_action( 'woocommerce_order_status_failed_notification' ) );
	}

	/**
	 * @testdox charge.refund.updated de-duplicates failed-refund notes structurally across locales.
	 */
	public function test_charge_refund_updated_failed_dedupes_notes_across_locale_renderings(): void {
		$order = $this->create_refundable_woopayments_order( '10.00' );
		$event = $this->create_refund_updated_event(
			array(
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$event['id'] = 'evt_refund_updated_locale_dedupe_1';
		$this->sut->process( $event );

		$this->install_woocommerce_test_translations(
			array(
				'A refund of %1$s was <strong>%2$s</strong> using %3$s (<code>%4$s</code>)%5$s' => 'Eine Rueckerstattung von %1$s war <strong>%2$s</strong> mit %3$s (<code>%4$s</code>)%5$s',
				'unsuccessful' => 'nicht erfolgreich',
				'The card used for the original payment has been reported lost or stolen.' => 'Die fuer die urspruengliche Zahlung verwendete Karte wurde als verloren oder gestohlen gemeldet.',
			)
		);

		$event['id'] = 'evt_refund_updated_locale_dedupe_2';
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertOrderHasNoteContaining( $order, array( 'A refund of', 'unsuccessful', 'lost or stolen' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Eine Rueckerstattung', 'nicht erfolgreich' ) );
	}

	/**
	 * @testdox charge.refund.updated fails closed when the order payment lock is already held.
	 */
	public function test_charge_refund_updated_fails_closed_when_order_payment_is_locked(): void {
		$order  = $this->create_refundable_woopayments_order( '10.00' );
		$refund = $this->create_local_refund( $order, 4.00, 'Existing refund' );
		$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
		$refund->save_meta_data();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->hold_order_payment_lock( $order, $vocabulary, 'existing_operation' );

		try {
			$this->sut->process(
				$this->create_refund_updated_event(
					array(
						'status'         => 'failed',
						'failure_reason' => 'lost_or_stolen_card',
					)
				)
			);
			$this->fail( 'Expected locked order refund update webhook to fail closed.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$this->assertSame( $order->get_id(), $exception->get_order_id() );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox charge.refund.updated records a refund failed for insufficient funds with the note for the account country.
	 *
	 * Client 11.1.0 reads the account country, else US, for this note (includes/class-wc-payments-order-service.php:3066-3074;
	 * includes/class-wc-payments-account.php:2731-2734), so a Hong Kong store without an account country gets the FROD note.
	 *
	 * @dataProvider provider_insufficient_funds_refund_note
	 *
	 * @param string $account_country WooPayments account country, '' for none.
	 * @param string $store_country   Store country, '' to leave the test store's.
	 * @param int    $frod_notes      Notes naming the FROD balance: 1 where FROD is supported, else 0.
	 */
	public function test_charge_refund_updated_records_insufficient_funds_with_the_account_country_note( string $account_country, string $store_country, int $frod_notes ): void {
		$order = $this->create_refundable_woopayments_order( '10.00' );
		$this->set_woopayments_account_country( $account_country );
		if ( '' !== $store_country ) {
			update_option( 'woocommerce_default_country', $store_country );
		}

		$this->sut->process(
			$this->create_refund_updated_event(
				array(
					'status'         => 'failed',
					'failure_reason' => 'insufficient_funds',
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
		$refund_notes = array_values(
			array_filter(
				$this->get_order_notes_containing( $order, 'insufficient funds in your WooPayments balance' ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'Refund of' ) && false !== strpos( (string) $note->content, 'failed' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $frod_notes, substr_count( (string) $refund_notes[0]->content, 'Future Refunds or Disputes (FROD) balance' ), 'The FROD advice belongs to the refund note.' );
		$this->assertCount( $frod_notes, $this->get_order_notes_containing( $order, 'Future Refunds or Disputes (FROD) balance' ) );
	}

	/**
	 * Account and store countries and whether the insufficient-funds note names the FROD balance.
	 *
	 * @return array<string,array{0:string,1:string,2:int}>
	 */
	public function provider_insufficient_funds_refund_note(): array {
		return array(
			'US account'                     => array( 'US', '', 1 ),
			'HK account, FROD not supported' => array( 'HK', '', 0 ),
			'no account country, HK store'   => array( '', 'HK', 1 ),
		);
	}

	/**
	 * @testdox charge.refund.updated refuses the status spelling cancelled and leaves the refund, the order and its refund status alone.
	 *
	 * Client 11.1.0 accepts only `canceled` (`includes/constants/class-refund-status.php:23`) and throws "Invalid refund
	 * update status" for anything else (`includes/class-wc-payments-webhook-processing-service.php:344-361`).
	 */
	public function test_charge_refund_updated_refuses_the_cancelled_spelling(): void {
		$order  = $this->create_refundable_woopayments_order( '10.00' );
		$refund = $this->create_local_refund( $order, 4.00, 'Existing refund' );
		$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
		$refund->save_meta_data();
		$order->set_status( 'refunded' );
		$order->save();
		$get_refund_ids = static fn( WC_Order $refunded_order ): array => array_map( static fn( $local_refund ) => $local_refund->get_id(), $refunded_order->get_refunds() );
		$refund_ids     = $get_refund_ids( wc_get_order( $order->get_id() ) );

		$failure = '';
		try {
			$this->sut->process( $this->create_refund_updated_event( array( 'status' => 'cancelled' ) ) );
		} catch ( InvalidArgumentException $exception ) {
			$failure = $exception->getMessage();
		}

		$this->assertSame( 'Invalid refund update status: cancelled', $failure, 'The cancelled spelling must fail the delivery.' );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertContains( $refund->get_id(), $refund_ids );
		$this->assertSame( $refund_ids, $get_refund_ids( $order ), 'No local refund may be deleted or added.' );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox charge.refund.updated writes success metadata only for matched refunds.
	 *
	 * Fed by REC-5a R-c (`Fixtures/platform-refund-updated-events.json`, pair
	 * `afterpay_clearpay_refund_updated_succeeded`), the primary redirect-method
	 * `charge.refund.updated` event local WPCOM forwarded for this recording.
	 * The recording settles F9: the refund's `balance_transaction` is a bare
	 * string id, not the expanded object some native sync fixtures use.
	 */
	public function test_charge_refund_updated_succeeded_updates_matched_refund(): void {
		// The recorded envelope carries the platform's own `livemode: false`; match it, or
		// EventIngestor::is_webhook_mode_mismatch() silently skips the event.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$envelope        = $this->load_recorded_refund_updated_event( 'afterpay_clearpay_refund_updated_succeeded' );
		$recorded_refund = $envelope['data']['object'];
		$order           = $this->create_refundable_woopayments_order( '100.00' );
		$order->set_transaction_id( (string) $recorded_refund['payment_intent'] );
		$order->update_meta_data( '_intent_id', (string) $recorded_refund['payment_intent'] );
		$order->update_meta_data( '_charge_id', (string) $recorded_refund['charge'] );
		$order->save();
		$refund = $this->create_local_refund( $order, 100.00, 'Existing refund' );
		$refund->update_meta_data( '_wcpay_refund_id', (string) $recorded_refund['id'] );
		$refund->save_meta_data();

		$this->sut->process( $envelope );

		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( (string) $recorded_refund['balance_transaction'], $refund->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertOrderHasNoteContaining( $order, array( 'A refund of', 'was successfully processed using WooPayments', (string) $recorded_refund['id'] ) );
	}

	/**
	 * @testdox charge.refund.updated ignores succeeded updates without matched refunds.
	 */
	public function test_charge_refund_updated_succeeded_without_matched_refund_is_noop(): void {
		$order = $this->create_refundable_woopayments_order( '10.00' );

		$this->sut->process( $this->create_refund_updated_event( array( 'status' => 'succeeded' ) ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'A refund of', 're_123' ) );
	}

	/**
	 * @testdox An unknown event type is a successful no-op: the order is untouched and the delivery hooks fire.
	 */
	public function test_delivery_hooks_fire_for_successful_noop_events(): void {
		$hook_calls = array();
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			function ( string $event_type, array $event_body ) use ( &$hook_calls ): void {
				$hook_calls[] = array( 'before', $event_type, $event_body['id'] );
			},
			10,
			2
		);
		add_action(
			'woocommerce_payments_after_webhook_delivery',
			function ( string $event_type, array $event_body ) use ( &$hook_calls ): void {
				$hook_calls[] = array( 'after', $event_type, $event_body['id'] );
			},
			10,
			2
		);

		$order = $this->create_woopayments_order();

		$this->sut->process( $this->create_payment_intent_event( 'customer.created', $order ) );

		$this->assertSame(
			array(
				array( 'before', 'customer.created', 'evt_123' ),
				array( 'after', 'customer.created', 'evt_123' ),
			),
			$hook_calls
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * The recorded dispute close, with only its dispute ID replaced by one the handler refuses, makes the handler throw after
	 * the order is found.
	 *
	 * @testdox An event whose handler refuses a malformed field fires the before-delivery hook only and deletes the dispute caches.
	 */
	public function test_event_whose_handler_throws_skips_the_after_delivery_hook_and_deletes_the_dispute_caches(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event                         = $this->load_recorded_dispute_closed_event( 'accept_closed_lost' );
		$event['data']['object']['id'] = array( 'not-scalar' );
		$order                         = $this->create_woopayments_order();
		$order->update_meta_data( '_charge_id', $event['data']['object']['charge'] );
		$order->save();
		$this->seed_dispute_cache_options();
		$hook_calls = array();
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			function ( string $event_type ) use ( &$hook_calls ): void {
				$hook_calls[] = array( 'before', $event_type );
			}
		);
		add_action(
			'woocommerce_payments_after_webhook_delivery',
			function ( string $event_type ) use ( &$hook_calls ): void {
				$hook_calls[] = array( 'after', $event_type );
			}
		);

		$exception = null;
		try {
			$this->sut->process( $event );
		} catch ( InvalidArgumentException $caught ) {
			$exception = $caught;
		}

		$this->assertInstanceOf( InvalidArgumentException::class, $exception );
		$this->assertSame( array( array( 'before', 'charge.dispute.closed' ) ), $hook_calls );
		$this->assert_dispute_cache_options_deleted();
	}

	/**
	 * @testdox A delivery hook callback that throws $_dataName is logged at error level by the platform's status and code, never a message.
	 *
	 * A callback can call the platform and let its error out, directly or wrapped, so the message is not native text. A
	 * failing callback on either hook fails neither the event nor the other hook.
	 *
	 * @dataProvider delivery_hook_platform_failures
	 *
	 * @param bool   $wrapped      Whether the callback wraps the platform error in its own exception.
	 * @param string $failing_hook The hook whose callback throws.
	 */
	public function test_delivery_hook_failure_log_leaves_out_platform_text( bool $wrapped, string $failing_hook ): void {
		$platform_error = self::make_provider_error();
		$thrown         = $wrapped ? new RuntimeException( 'Sync failed: ' . $platform_error->getMessage(), 0, $platform_error ) : $platform_error;
		$hook_calls     = array();
		foreach ( array( 'before', 'after' ) as $position ) {
			$hook = "woocommerce_payments_{$position}_webhook_delivery";
			add_action(
				$hook,
				static function ( string $event_type ) use ( $thrown, &$hook_calls, $position, $hook, $failing_hook ): void {
					$hook_calls[] = array( $position, $event_type );
					if ( $hook === $failing_hook ) {
						throw $thrown;
					}
				}
			);
		}
		$logger = RecordingWcLogger::install();

		$this->sut->process( $this->create_payment_intent_event( 'customer.created', $this->create_woopayments_order() ) );

		$this->assertSame( array( array( 'before', 'customer.created' ), array( 'after', 'customer.created' ) ), $hook_calls, 'The failure stops neither the event nor the next hook.' );
		$context = $this->get_logged_context( $logger, 'A WooPayments webhook delivery hook callback failed.' );
		$this->assertSame( array( get_class( $thrown ), 404, 'resource_missing' ), array( $context['exception'], $context['http_status'], $context['error_code'] ) );
		$this->assertSame( array( $failing_hook, 'woopayments' ), array( $context['hook'], $context['source'] ) );
		$this->assertSame(
			array( 'error' ),
			array_values( array_column( array_filter( $logger->lines, static fn( array $line ): bool => 'A WooPayments webhook delivery hook callback failed.' === $line[1] ), 0 ) )
		);
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Platform errors a delivery hook callback can throw, and the hook that throws.
	 *
	 * @return array<string,array{0:bool,1:string}>
	 */
	public function delivery_hook_platform_failures(): array {
		return array(
			'a platform error'                     => array( false, 'woocommerce_payments_before_webhook_delivery' ),
			'its own exception wrapping one'       => array( true, 'woocommerce_payments_before_webhook_delivery' ),
			'a platform error from the after hook' => array( false, 'woocommerce_payments_after_webhook_delivery' ),
		);
	}

	/**
	 * @testdox Mismatched order keys do not mutate the named order.
	 */
	public function test_mismatched_order_key_does_not_mutate_order(): void {
		$order = $this->create_woopayments_order();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'metadata' => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => 'wc_order_wrong_key',
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox Malformed events throw an invalid argument exception.
	 */
	public function test_malformed_event_throws_invalid_argument_exception(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->sut->process(
			array(
				'id'   => 'evt_bad',
				'data' => array(),
			)
		);
	}

	/**
	 * @testdox An event $_dataName is logged as received and handled on every delivery, as client 11.1.0 does.
	 * @testWith ["with an ID", "evt_delivered_twice"]
	 *           ["without an ID", ""]
	 *
	 * Client 11.1.0 `class-wc-payments-webhook-processing-service.php:145-160` writes the received line for each delivery
	 * and keeps no record of handled events.
	 *
	 * @param string $label    Case label.
	 * @param string $event_id Event ID, or '' for none.
	 */
	public function test_every_delivery_of_an_event_is_handled( string $label, string $event_id ): void {
		unset( $label );
		$handler = $this->create_recording_notification_handler();
		$sut     = $this->create_ingestor_with_notification_handler( $handler );
		$event   = $this->create_notification_event( $event_id );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'test_mode'      => 'yes',
				'enable_logging' => 'yes',
			)
		);
		$logger = RecordingWcLogger::install();

		$sut->process( $event );
		$sut->process( $event );

		$this->assertSame( array( $event, $event ), $handler->processed_events );
		$received = array_filter( $logger->lines, static fn( array $line ): bool => 'WEBHOOK RECEIVED: wcpay.notification ' . $event_id === $line[1] );
		$this->assertCount( 2, $received );
	}

	/**
	 * @testdox payment_intent.succeeded found only by its metadata on an unpaid order of another gateway is recorded and does not pay the order.
	 *
	 * The order never recorded the intent in `_intent_id`, so the intent is another charge on it. Client 11.1.0 pays such an
	 * order (class-wc-payments-webhook-processing-service.php:974-1000, :582); a payment pays an order only when it is the
	 * payment the order waits for.
	 */
	public function test_succeeded_intent_found_by_metadata_on_an_unpaid_order_of_another_gateway_is_recorded(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct bank transfer' );
		$order->set_status( 'on-hold' );
		$order->save();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, $this->get_card_charge_overrides() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ), 'A charge that is not the order\'s payment is not stored on it.' );
		$this->assertSame( 'Direct bank transfer', $order->get_payment_method_title() );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * @testdox payment_intent.succeeded on an order already paid by another gateway is recorded once, writes no payment meta, and logs one line.
	 *
	 * Client 11.1.0 writes the intent meta on any resolved order (class-wc-payments-webhook-processing-service.php:510-569)
	 * and then skips paid orders (class-wc-payments-order-service.php:2747-2764, :2863-2879). Here the order records the
	 * charge in one note, which links the charge's transaction page, and keeps its payment data: the stored charge stays the
	 * order's own payment's.
	 */
	public function test_succeeded_intent_on_an_order_paid_by_another_gateway_is_recorded_once_without_paying_it_again(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct bank transfer' );
		$order->set_status( 'processing' );
		$order->save();
		$logger = RecordingWcLogger::install();
		$event  = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, $this->get_card_charge_overrides() );

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id(), 'An order paid by another gateway must not be marked paid again.' );
		$this->assertSame( 'bacs', $order->get_payment_method() );
		$this->assertSame( 'Direct bank transfer', $order->get_payment_method_title() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ), 'A recorded intent must not become the order\'s intent.' );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ), 'A recorded charge must not become the order\'s charge.' );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$record_notes = $this->get_order_notes_containing( $order, 'does not pay this order' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'ch_123', $record_notes[0]->content );
		$this->assertStringContainsString( '10.00', $record_notes[0]->content );
		$record_lines = array_keys(
			array_filter(
				$logger->lines,
				static fn( array $line ): bool => 'warning' === $line[0] && 'other charge recorded: order ' . $order->get_id() . ', payment_intent.succeeded, charge ch_123, order payment method bacs' === $line[1]
			)
		);
		$this->assertCount( 2, $record_lines, 'Each delivery logs one line.' );
		$context = $logger->contexts[ $record_lines[0] ];
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'payment_intent.succeeded', $context['event_type'] );
		$this->assertSame( 'ch_123', $context['charge_id'] );
		$this->assertSame( 'bacs', $context['payment_method'] );
	}

	/**
	 * @testdox A second succeeded intent on an order WooPayments already paid is recorded and leaves the order's payment data alone.
	 */
	public function test_second_succeeded_intent_on_an_order_woopayments_paid_is_recorded(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_first' );
		$order->update_meta_data( '_intent_id', 'pi_first' );
		$order->update_meta_data( '_charge_id', 'ch_first' );
		$order->save();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array( 'id' => 'pi_second' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'pi_first', $order->get_transaction_id() );
		$this->assertSame( 'pi_first', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_first', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$record_notes = $this->get_order_notes_containing( $order, 'does not pay this order' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'ch_123', $record_notes[0]->content );
	}

	/**
	 * @testdox A succeeded intent of the stored payment on a paid order with no transaction ID, in $_dataName, is its own payment: no other-charge note, and the lifecycle leaves the order as it is on each delivery.
	 * @dataProvider stored_payment_success_on_a_paid_status
	 *
	 * The stored WooPayments payment stands in for the empty transaction ID only on an order in a paid status by the
	 * lifecycle's own test, which counts a status a plugin adds through woocommerce_order_is_paid_statuses. The lifecycle
	 * skips the success there, so the transaction ID stays empty. Client 11.3.0 finds the order by its _intent_id and leaves
	 * a paid order alone (class-wc-payments-webhook-processing-service.php:1135-1158; class-wc-payments-order-service.php:466-489).
	 *
	 * @param string $status Order status, a paid one.
	 */
	public function test_stored_payment_success_on_a_paid_status_without_transaction_id_is_its_own( string $status ): void {
		add_filter( 'wc_order_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'wc-shipped' => 'Shipped' ) ) );
		add_filter( 'woocommerce_order_is_paid_statuses', static fn( array $statuses ): array => array_merge( $statuses, array( 'shipped' ) ) );
		$order  = StoredPaymentRecords::paid_without_transaction_id( $status, 'pi_a', 'ch_a' );
		$event  = $this->create_stored_payment_success_event( $order, 1099 );
		$logger = RecordingWcLogger::install();

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id(), 'A success the lifecycle skips writes no transaction ID.' );
		$this->assertSame( 'pi_a', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_a', $order->get_meta( '_charge_id', true ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$skips = array_filter( $logger->contexts, static fn( array $context ): bool => 'order_paid' === ( $context['reason'] ?? '' ) );
		$this->assertCount( 2, $skips, 'Each delivery reaches the lifecycle, which leaves the paid order as it is.' );
		$paid_notes = $this->get_order_notes_containing( $order, 'already had a paid status' );
		$this->assertCount( 1, $paid_notes, 'The first delivery notes the payment that reached the paid order; the second adds nothing.' );
		$this->assertStringNotContainsString( 'transaction ID names that payment', $paid_notes[0]->content, 'The empty transaction ID names no payment.' );
	}

	/**
	 * @testdox The webhook's stand-in for an empty transaction ID on an order with a paid date and its stored payment pi_A, ch_A: $_dataName.
	 *
	 * Only a success, and only on an order in a paid status by the payment lifecycle's test, stands in for the empty
	 * transaction ID (EventOrderResolver::get_stand_in_intent_id()); every other event is recorded as another charge. The
	 * answers are the same before and after the condition moved to the resolver, which the order-status callback also asks.
	 *
	 * @dataProvider stand_in_cases_of_the_webhook
	 *
	 * @param string              $event_type Event type.
	 * @param string              $status     Order status.
	 * @param array<string,mixed> $overrides  Intent overrides.
	 * @param bool                $own        Whether the event is the order's own payment.
	 */
	public function test_webhook_stand_in_cases_on_an_order_without_transaction_id( string $event_type, string $status, array $overrides, bool $own ): void {
		$order = StoredPaymentRecords::paid_without_transaction_id( OrderStatus::PROCESSING, 'pi_A', 'ch_A' );
		$order->set_status( $status );
		$order->save();
		$logger = RecordingWcLogger::install();

		$this->sut->process(
			$this->create_payment_intent_event(
				$event_type,
				$order,
				array_replace_recursive(
					array(
						'id'      => 'pi_A',
						'amount'  => 1099,
						'charges' => array( 'data' => array( array( 'id' => 'ch_A' ) ) ),
					),
					$overrides
				)
			)
		);

		$recorded = array_filter( array_column( $logger->lines, 1 ), static fn( string $line ): bool => 0 === strpos( $line, 'other charge recorded:' ) );
		$this->assertCount( $own ? 0 : 1, $recorded );
	}

	/**
	 * The webhook's stand-in cases: event type, order status and intent, with whether the event is the order's own.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,bool}>
	 */
	public function stand_in_cases_of_the_webhook(): array {
		return array(
			'a success on a paid status: stands in'       => array( 'payment_intent.succeeded', OrderStatus::PROCESSING, array(), true ),
			'a success on a paid date with Cancelled: does not' => array( 'payment_intent.succeeded', OrderStatus::CANCELLED, array(), false ),
			'an authorization on a paid status: does not' => array( 'payment_intent.amount_capturable_updated', OrderStatus::PROCESSING, array( 'status' => 'requires_capture' ), false ),
			'a failure on a paid status: does not'        => array( 'payment_intent.payment_failed', OrderStatus::PROCESSING, $this->get_card_failure_overrides(), false ),
		);
	}

	/**
	 * Paid statuses of an order with no transaction ID.
	 *
	 * @return array<string,array{string}>
	 */
	public function stored_payment_success_on_a_paid_status(): array {
		return array(
			'Processing'                     => array( OrderStatus::PROCESSING ),
			'a status a plugin adds as paid' => array( 'shipped' ),
		);
	}

	/**
	 * @testdox A redelivered succeeded intent of the stored payment on an order with a paid date and no transaction ID, $_dataName, is recorded as another charge and keeps the status.
	 * @dataProvider stored_payment_success_on_a_status_that_is_not_paid
	 *
	 * A success stands in for the empty transaction ID only on a paid status by the lifecycle's own test, not the order's
	 * is_paid(), which a filter can widen, and not a paid date: on a status the merchant moved the order to, owning it would
	 * let the success complete the order or hold it for an amount that no longer matches.
	 *
	 * @param string $status        Status the merchant moved the paid order to.
	 * @param int    $intent_amount The intent's amount in minor units; the order total is 10.99.
	 * @param bool   $filter_paid   Whether a woocommerce_order_is_paid filter answers true for the order.
	 */
	public function test_redelivered_stored_payment_success_on_a_status_that_is_not_paid_is_recorded( string $status, int $intent_amount, bool $filter_paid ): void {
		$order = StoredPaymentRecords::paid_without_transaction_id( OrderStatus::PROCESSING, 'pi_a', 'ch_a' );
		$order->set_status( $status );
		$order->save();
		$this->assertNotNull( $order->get_date_paid(), 'The moved order keeps its paid date.' );
		if ( $filter_paid ) {
			add_filter( 'woocommerce_order_is_paid', static fn( $is_paid, $paid_order ): bool => $paid_order instanceof WC_Order && $paid_order->get_id() === $order->get_id() ? true : (bool) $is_paid, 10, 2 );
		}
		$completions = did_action( 'woocommerce_pre_payment_complete' );

		$this->sut->process( $this->create_stored_payment_success_event( $order, $intent_amount ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 0, did_action( 'woocommerce_pre_payment_complete' ) - $completions );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'put <strong>on hold</strong>' ) );
	}

	/**
	 * Statuses a merchant moves a paid order to, with the redelivered intent's amount.
	 *
	 * @return array<string,array{string,int,bool}>
	 */
	public function stored_payment_success_on_a_status_that_is_not_paid(): array {
		return array(
			'Cancelled, the amount equal to the total' => array( OrderStatus::CANCELLED, 1099, false ),
			'Refunded, the amount different from the total' => array( OrderStatus::REFUNDED, 1500, false ),
			'On hold, which a filter counts as paid'   => array( OrderStatus::ON_HOLD, 1099, true ),
		);
	}

	/**
	 * @testdox The webhook's own success on an order in a paid status stores the succeeded status with its charge, $_dataName.
	 * @dataProvider own_success_on_a_hand_paid_order
	 *
	 * The lifecycle skips the success on a paid order before it writes the event's meta, so without this the order would
	 * keep the attempt's requires_action status beside the payment's charge, fee and net. Client 11.3.0's webhook stores the
	 * charge and no status, then leaves a paid order as it is (class-wc-payments-webhook-processing-service.php:642-701,
	 * :716). A success that is not the order's own is recorded as another charge and stores nothing of its payment.
	 *
	 * @param string $payment_method Payment method the merchant left or chose.
	 * @param string $transaction_id Transaction ID the order stores.
	 * @param string $expected_status Stored intent status after the success.
	 * @param string $expected_charge Stored charge after the success.
	 */
	public function test_own_success_on_a_paid_order_stores_succeeded_with_its_charge( string $payment_method, string $transaction_id, string $expected_status, string $expected_charge ): void {
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt( $payment_method, $transaction_id );

		$this->sut->process( $this->create_hand_paid_attempt_success_event( $order ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( $transaction_id, $order->get_transaction_id() );
		$this->assertSame( $expected_status, $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $expected_charge, $order->get_meta( '_charge_id', true ) );
	}

	/**
	 * Hand-paid orders over an open attempt, with what the attempt's success leaves stored.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function own_success_on_a_hand_paid_order(): array {
		return array(
			'method unchanged, own by its transaction ID' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pi_A', 'succeeded', 'ch_A' ),
			'method Other, own by its transaction ID'     => array( 'other', 'pi_A', 'succeeded', 'ch_A' ),
			'empty transaction ID, the stored payment stands in' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, '', 'succeeded', 'ch_A' ),
			'transaction ID naming another attempt'       => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pi_other', 'requires_action', '' ),
		);
	}

	/**
	 * @testdox The webhook's success on an On hold authorization that a filter counts as paid is left to the lifecycle, which records it as the capture.
	 *
	 * Only a status in wc_get_is_paid_statuses() is paid for the lifecycle, so it applies the success here; storing succeeded
	 * first would turn the capture of the stored authorization into a plain success (client 11.3.0
	 * class-wc-payments-order-service.php:484-489).
	 */
	public function test_own_success_on_a_status_a_filter_counts_as_paid_is_left_to_the_lifecycle(): void {
		$order = StoredPaymentRecords::authorization();
		add_filter( 'woocommerce_order_is_paid', static fn( $is_paid, $paid_order ): bool => $paid_order instanceof WC_Order && $paid_order->get_id() === $order->get_id() ? true : (bool) $is_paid, 10, 2 );

		$this->sut->process( $this->create_hand_paid_attempt_success_event( $order ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->has_status( wc_get_is_paid_statuses() ), 'The lifecycle completes the order.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'successfully captured' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
	}

	/**
	 * @testdox A success on a paid order that the webhook does not report from its own status keeps the stored status: $_dataName.
	 * @dataProvider successes_that_do_not_report_the_intent_status
	 *
	 * A Stripe Billing invoice's intent is settled by its invoice and writes only its payment meta (client 11.3.0
	 * class-wc-payments-webhook-processing-service.php:711-717), and Stripe Billing's paid renewal records its payment through
	 * its own handler, which reads the stored status (StripeBillingEventHandler::apply_paid_invoice_under_lock()).
	 *
	 * @param string $path Which success arrives.
	 */
	public function test_success_that_does_not_report_the_intent_status_keeps_the_stored_status( string $path ): void {
		$order = StoredPaymentRecords::paid_by_hand_over_an_open_attempt();
		$event = $this->create_hand_paid_attempt_success_event( $order );

		if ( 'invoice' === $path ) {
			$event['data']['object']['invoice'] = 'in_A';
			$this->sut->process( $event );
		} else {
			wc_get_container()->get( PaymentIntentEventHandler::class )->apply_succeeded_payment_intent_under_lock( $order, $event['data']['object'], array( 'The renewal invoice collected another amount.' ) );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'ch_A', $order->get_meta( '_charge_id', true ), 'The payment meta is written.' );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		// The held paid renewal is skipped on the paid order with the paid-order note; the invoice's intent never reaches the lifecycle.
		$this->assertCount( 'invoice' === $path ? 0 : 1, $this->get_order_notes_containing( $order, 'already had a paid status' ) );
	}

	/**
	 * Successes on a paid order that do not come from the webhook's own success.
	 *
	 * @return array<string,array{string}>
	 */
	public function successes_that_do_not_report_the_intent_status(): array {
		return array(
			"a Stripe Billing invoice's intent" => array( 'invoice' ),
			"Stripe Billing's paid renewal with an amount hold" => array( 'stripe billing' ),
		);
	}

	/**
	 * @testdox The webhook's success on an order in a paid status with none of the payment's notes adds the paid-order note only for an online payment the order owns: $_dataName.
	 * @dataProvider successes_on_a_paid_order_without_the_payments_notes
	 *
	 * Client 11.3.0 leaves a paid order with no note (class-wc-payments-webhook-processing-service.php:716,
	 * class-wc-payments-order-service.php:3292-3294). A payment taken in person, by a card reader or Interac, gets no note:
	 * the merchant took it at the counter; an Interac sale for another amount than the order's total, which the in-person
	 * route completed, stays Completed with no note. A success that is not the order's own is another charge's note.
	 *
	 * @param string $shape          `hand-paid` (a 3DS attempt marked paid by hand) or `paid` (a payment the route recorded).
	 * @param string $status         Order status.
	 * @param string $transaction_id Transaction ID the order stores.
	 * @param string $charge_type    The payment's charge type.
	 * @param int    $amount         The intent's amount in minor units; the order total is 10.99.
	 * @param bool   $noted          Whether the paid-order note is written.
	 * @param bool   $sentence_three Whether it says the transaction ID names the payment.
	 */
	public function test_webhook_success_on_a_paid_order_notes_only_an_online_payment_it_owns( string $shape, string $status, string $transaction_id, string $charge_type, int $amount, bool $noted, bool $sentence_three ): void {
		$order = 'paid' === $shape
			? StoredPaymentRecords::payment( $status, 'pi_A', 'ch_A' )
			: StoredPaymentRecords::paid_by_hand_over_an_open_attempt( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $transaction_id );
		$event = $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'id'      => 'pi_A',
				'amount'  => $amount,
				'charges' => array(
					'data' => array(
						array(
							'id'                     => 'ch_A',
							'payment_method_details' => array( 'type' => $charge_type ),
						),
					),
				),
			)
		);

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status, $order->get_status() );
		$notes = $this->get_order_notes_containing( $order, 'already had a paid status' );
		$this->assertCount( $noted ? 1 : 0, $notes );
		if ( $noted ) {
			$this->assertSame( $sentence_three, str_contains( $notes[0]->content, 'transaction ID names that payment' ) );
		}
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
	}

	/**
	 * Successes on a paid order that holds none of the payment's notes.
	 *
	 * @return array<string,array{string,string,string,string,int,bool,bool}>
	 */
	public function successes_on_a_paid_order_without_the_payments_notes(): array {
		return array(
			'an online card payment, own by its transaction ID' => array( 'hand-paid', OrderStatus::PROCESSING, 'pi_A', 'card', 1099, true, true ),
			'an online card payment, an empty transaction ID' => array( 'hand-paid', OrderStatus::PROCESSING, '', 'card', 1099, true, false ),
			'an online card payment, another attempt in the transaction ID' => array( 'hand-paid', OrderStatus::PROCESSING, 'pi_other', 'card', 1099, false, false ),
			'an Interac sale'    => array( 'hand-paid', OrderStatus::PROCESSING, 'pi_A', 'interac_present', 1099, false, false ),
			'a card reader sale' => array( 'hand-paid', OrderStatus::PROCESSING, 'pi_A', 'card_present', 1099, false, false ),
			'an Interac sale for another amount, Completed by the in-person route' => array( 'paid', OrderStatus::COMPLETED, 'pi_A', 'interac_present', 1500, false, false ),
		);
	}

	/**
	 * A payment_intent.succeeded of the order's stored attempt pi_A, with its charge ch_A, for the order's total.
	 *
	 * @param WC_Order $order Order the intent pays.
	 * @return array<string,mixed>
	 */
	private function create_hand_paid_attempt_success_event( WC_Order $order ): array {
		return $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'id'      => 'pi_A',
				'amount'  => 1099,
				'charges' => array( 'data' => array( array( 'id' => 'ch_A' ) ) ),
			)
		);
	}

	/**
	 * @testdox A succeeded intent of another attempt on a paid order with no transaction ID and no stored charge is recorded, and a refund of its charge adds no refund row.
	 *
	 * Only the stored intent stands in for the empty transaction ID: the other attempt's success writes nothing of its own
	 * payment, so its charge never becomes the order's, and its refund, found by the charge's metadata, is recorded. The
	 * charge is a Stripe Charge with its `payment_intent`, `metadata` and refunds list (https://docs.stripe.com/api/charges/object).
	 */
	public function test_another_attempts_success_on_an_order_without_transaction_id_and_stored_charge_is_recorded(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = StoredPaymentRecords::paid_without_transaction_id( OrderStatus::PROCESSING, 'pi_a', '' );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'id'      => 'pi_c',
					'charges' => array( 'data' => array( array( 'id' => 'ch_c' ) ) ),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertSame( 'pi_a', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_transaction_id() );

		$refund_event                           = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', 'card_charge_refunded_in_full' );
		$charge                                 = &$refund_event['data']['object'];
		$charge['id']                           = 'ch_c';
		$charge['payment_intent']               = 'pi_c';
		$charge['metadata']['order_id']         = (string) $order->get_id();
		$charge['metadata']['order_key']        = $order->get_order_key();
		$charge['amount']                       = 1099;
		$charge['refunds']['data'][0]['charge'] = 'ch_c';
		$charge['refunds']['data'][0]['amount'] = 500;
		$charge['refunds']['data'][0]['status'] = 'succeeded';
		unset( $charge );

		$this->sut->process( $refund_event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'No refund was added to the order.' ) );
	}

	/**
	 * @testdox A late payment_intent.payment_failed of a refused attempt stored on an order marked paid by hand, then put on hold, with no transaction ID, is recorded and does not fail the order.
	 *
	 * A failure never stands in for the empty transaction ID: it is no evidence that the stored intent paid the order. The
	 * event is a Stripe PaymentIntent with its `last_payment_error.payment_method`
	 * (https://docs.stripe.com/api/payment_intents/object#payment_intent_object-last_payment_error).
	 */
	public function test_late_failure_of_the_stored_attempt_on_an_order_without_transaction_id_is_recorded(): void {
		$order = StoredPaymentRecords::authorization_without_transaction_id( 'pi_declined', 'requires_payment_method', OrderStatus::PENDING, '' );
		$order->update_meta_data( '_payment_method_id', 'pm_declined' );
		$order->set_status( OrderStatus::PROCESSING );
		$order->set_date_paid( time() );
		$order->save();
		$order->set_status( OrderStatus::ON_HOLD );
		$order->save();
		$this->assertNotNull( $order->get_date_paid(), 'The order marked paid by hand keeps its paid date.' );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'id'                 => 'pi_declined',
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_declined',
							'type' => 'card',
						),
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'that does not pay this order failed' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, '<strong>failed</strong>' ) );
	}

	/**
	 * @testdox charge.expired on the stored charge of a paid order with no transaction ID is recorded as today and reads nothing from the platform.
	 *
	 * An expiry never stands in for the empty transaction ID: an own expiry reads the intent from the platform. The event's
	 * `payment_intent` is the Stripe Charge field (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 */
	public function test_expired_stored_charge_on_an_order_without_transaction_id_is_recorded_without_a_platform_read(): void {
		$order = StoredPaymentRecords::paid_without_transaction_id( OrderStatus::PROCESSING, 'pi_a', 'ch_a' );
		$sut   = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_stored_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_a',
						'payment_intent' => 'pi_a',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( array(), $requested_intents );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'which does not pay this order, expired' ) );
	}

	/**
	 * @testdox payment_intent.amount_capturable_updated for the stored intent of a Pending order with a paid date and no transaction ID is recorded and does not hold the order.
	 *
	 * An authorization never stands in for the empty transaction ID: it is no evidence that the stored intent paid the order.
	 * The event is a Stripe PaymentIntent awaiting capture (https://docs.stripe.com/api/payment_intents/object#payment_intent_object-status).
	 */
	public function test_authorization_of_the_stored_intent_on_an_order_without_transaction_id_is_recorded(): void {
		$order = StoredPaymentRecords::paid_without_transaction_id( OrderStatus::PROCESSING, 'pi_a', '' );
		$order->set_status( OrderStatus::PENDING );
		$order->save();
		$this->assertNotNull( $order->get_date_paid(), 'The moved order keeps its paid date.' );

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.amount_capturable_updated',
				$order,
				array(
					'id'      => 'pi_a',
					'status'  => 'requires_capture',
					'amount'  => 1099,
					'charges' => array( 'data' => array( array( 'id' => 'ch_a' ) ) ),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'that does not pay this order was authorized' ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'was <strong>authorized</strong>' ) );
	}

	/**
	 * @testdox payment_intent.payment_failed for an intent another gateway's paid order never recorded adds one note and changes nothing else.
	 */
	public function test_failed_intent_on_another_charge_adds_one_note(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'cod' );
		$order->set_status( 'processing' );
		$order->save();

		$this->sut->process(
			$this->create_payment_intent_event(
				'payment_intent.payment_failed',
				$order,
				array(
					'id'                 => 'pi_other',
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_other',
							'type' => 'card',
						),
					),
				)
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$record_notes = $this->get_order_notes_containing( $order, 'that does not pay this order failed' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'pi_other', $record_notes[0]->content );
	}

	/**
	 * @testdox charge.expired on a charge another gateway's paid order recorded adds one note, fetches nothing and changes nothing else.
	 *
	 * The event's `payment_intent` is the Stripe Charge field (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 */
	public function test_expired_charge_on_another_charge_adds_one_note(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'cod' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', 'ch_other' );
		$order->save();
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_other_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_other',
						'payment_intent' => 'pi_other',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( array(), $requested_intents );
		$record_notes = $this->get_order_notes_containing( $order, 'which does not pay this order, expired' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'ch_other', $record_notes[0]->content );
	}

	/**
	 * @testdox charge.expired on a charge no order holds is recorded on the order its metadata names, even one waiting for its WooPayments payment, and fetches nothing.
	 *
	 * The Charge object carries the store's `metadata` with `order_id` and `order_key`
	 * (https://docs.stripe.com/api/charges/object#charge_object-metadata). An order found this way is never paid or failed by the event.
	 */
	public function test_expired_charge_found_by_metadata_is_recorded(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_unheld_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_unheld',
						'payment_intent' => 'pi_unheld',
						'metadata'       => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => $order->get_order_key(),
						),
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array(), $requested_intents );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'which does not pay this order, expired' ) );
	}

	/**
	 * @testdox charge.expired found by charge metadata fails the authorization when the order saved the charge as its payment before the event claimed the lock.
	 *
	 * The Charge object carries `payment_intent` and the store's `metadata`
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_intent). The checkout writes straight to the
	 * database, leaving this request's caches as they were, so the decision must read the order from the data store.
	 */
	public function test_expired_charge_found_by_metadata_applies_when_the_order_saved_the_charge_before_the_claim(): void {
		UncachedOrderWriter::enable_hpos_data_caching();
		$order = $this->create_woopayments_order();
		$store = new OrderPaymentLockWithClaimHook(
			static function ( WC_Order $order ): void {
				UncachedOrderWriter::write(
					$order->get_id(),
					array(
						'status'         => 'on-hold',
						'transaction_id' => 'pi_late',
					),
					array(
						'_intent_id'        => 'pi_late',
						'_charge_id'        => 'ch_late',
						'_intention_status' => 'requires_capture',
					)
				);
			}
		);
		wc_get_container()->replace( OrderPaymentLock::class, $store );
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_late_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_late',
						'payment_intent' => 'pi_late',
						'metadata'       => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => $order->get_order_key(),
						),
					),
				),
			)
		);

		$this->assertTrue( $store->hook_ran, 'The authorization must be saved inside the claim.' );
		$order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( array( 'pi_late' ), $requested_intents );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'which does not pay this order' ) );
	}

	/**
	 * @testdox charge.expired recorded on an order found by charge metadata leaves the order without that charge ID, so a later event on the charge does not resolve to it.
	 *
	 * The Charge object carries `payment_intent` and the store's `metadata`
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 */
	public function test_expired_charge_found_by_metadata_does_not_link_the_charge(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$sut = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) );

		$sut->process(
			array(
				'id'   => 'evt_unheld_expired_link',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_unheld',
						'payment_intent' => 'pi_unheld',
						'metadata'       => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => $order->get_order_key(),
						),
					),
				),
			)
		);

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_charge_id', true ) );
		$this->assertNull( wc_get_container()->get( EventOrderResolver::class )->find_order_by_charge_id( 'ch_unheld' ) );
	}

	/**
	 * @testdox payment_intent.succeeded is decided on the order read under the lock, so an order another gateway paid just before the claim records the intent instead of being paid again.
	 *
	 * The other gateway writes straight to the database, leaving this request's caches as they were, so the decision must
	 * read the order from the data store.
	 */
	public function test_succeeded_intent_is_decided_on_the_order_read_under_the_lock(): void {
		UncachedOrderWriter::enable_hpos_data_caching();
		$order = $this->create_woopayments_order();
		$store = new OrderPaymentLockWithClaimHook(
			static function ( WC_Order $order ): void {
				UncachedOrderWriter::write(
					$order->get_id(),
					array(
						'status'         => 'processing',
						'payment_method' => 'bacs',
						'date_paid'      => time(),
					)
				);
			}
		);
		wc_get_container()->replace( OrderPaymentLock::class, $store );
		$sut = $this->create_ingestor( wc_get_container()->get( OrderPaymentLifecycleService::class ), wc_get_container()->get( Client::class ) );

		$sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$this->assertTrue( $store->hook_ran, 'The other gateway must pay the order inside the claim.' );
		$order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * @testdox An authorization is decided on the order read under the lock, so an order another payment method took over just before the claim is recorded instead of put on hold.
	 *
	 * The other payment method writes straight to the database, leaving this request's caches as they were, so the
	 * decision must read the order from the data store.
	 */
	public function test_authorization_is_decided_on_the_order_read_under_the_lock(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		UncachedOrderWriter::enable_hpos_data_caching();
		$order = $this->create_woopayments_order();
		$event = $this->recorded_authorization_event( $order );
		$order->update_meta_data( '_intent_id', $event['data']['object']['id'] );
		$order->save();
		$store = new OrderPaymentLockWithClaimHook(
			static function ( WC_Order $order ): void {
				UncachedOrderWriter::write( $order->get_id(), array( 'payment_method' => 'bacs' ) );
			}
		);
		wc_get_container()->replace( OrderPaymentLock::class, $store );
		$sut = $this->create_ingestor( wc_get_container()->get( OrderPaymentLifecycleService::class ), wc_get_container()->get( Client::class ) );

		$sut->process( $event );

		$this->assertTrue( $store->hook_ran, 'The other payment method must take the order over inside the claim.' );
		$order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'was <strong>authorized</strong>' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'that does not pay this order was authorized' ) );
	}

	/**
	 * @testdox A $event_type delivery refused by a held order payment lock logs one refusal line.
	 * @dataProvider refusal_line_events
	 *
	 * @param string      $event_type         Event type.
	 * @param string|null $expected_reference Payment reference in the line's context, or null for none.
	 * @param string|null $expected_lifecycle Lifecycle status in the line's context, or null for none.
	 * @param string|null $expected_reason    Reason in the line's context, or null for none.
	 */
	public function test_refused_payment_event_logs_one_refusal_line( string $event_type, ?string $expected_reference, ?string $expected_lifecycle, ?string $expected_reason ): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->save();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->hold_order_payment_lock( $order, $vocabulary, 'pi_lock_holder' );
		$logger = RecordingWcLogger::install();
		$event  = 'charge.expired' === $event_type
			? array(
				'id'   => 'evt_refused_expired',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_123',
						'payment_intent' => 'pi_123',
					),
				),
			)
			: $this->create_payment_intent_event(
				$event_type,
				$order,
				'payment_intent.payment_failed' === $event_type ? array(
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'payment_method' => array(
							'id'   => 'pm_123',
							'type' => 'card',
						),
					),
				) : array()
			);
		$sut    = 'charge.expired' === $event_type ? $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ) ) : $this->sut;

		try {
			$sut->process( $event );
			$this->fail( 'The delivery must be refused while another operation holds the order payment lock.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$this->assertSame( $order->get_id(), $exception->get_order_id() );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$refusal_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 0 === strpos( $line[1], 'order payment lock refused: order ' . $order->get_id() . ', refused payment status update, held by ' ) ) );
		$this->assertCount( 1, $refusal_lines );
		$context = $logger->contexts[ $refusal_lines[0] ];
		$this->assertSame( 'order-payments', $context['source'] );
		$this->assertSame( 'payment status update', $context['refused_operation'] );
		$this->assertSame( $expected_reference, $context['payment_reference'] ?? null );
		$this->assertSame( $expected_lifecycle, $context['event_type'] ?? null );
		$this->assertSame( $expected_reason, $context['reason'] ?? null );
	}

	/** @return array<string,array{string,?string,?string,?string}> */
	public static function refusal_line_events(): array {
		return array(
			'succeeded'      => array( 'payment_intent.succeeded', null, null, null ),
			'payment failed' => array( 'payment_intent.payment_failed', 'pi_123', 'failed', 'order_locked' ),
			'charge expired' => array( 'charge.expired', 'ch_123', 'capture_expired', 'order_locked' ),
		);
	}

	/**
	 * @testdox A $event_type event for an unpaid order's current attempt applies while its transaction ID still names an abandoned attempt.
	 * @dataProvider current_attempt_events
	 *
	 * The runtime writes the transaction ID on the first attempt that needs 3D Secure or a redirect and leaves it there,
	 * while each attempt writes its intent to `_intent_id`.
	 *
	 * @param string $event_type             Payment intent event type.
	 * @param string $expected_status        Order status once the event applies.
	 * @param string $expected_transaction_id Order transaction ID once the event applies.
	 */
	public function test_event_for_the_current_attempt_applies_while_the_transaction_id_names_an_abandoned_attempt( string $event_type, string $expected_status, string $expected_transaction_id ): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_abandoned' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save();
		$failed_overrides = array(
			'status'             => 'requires_payment_method',
			'last_payment_error' => array(
				'payment_method' => array(
					'id'   => 'pm_123',
					'type' => 'card',
				),
			),
		);

		$this->sut->process( $this->create_payment_intent_event( $event_type, $order, 'payment_intent.payment_failed' === $event_type ? $failed_overrides : array() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( $expected_transaction_id, $order->get_transaction_id() );
	}

	/** @return array<string,array{string,string,string}> */
	public static function current_attempt_events(): array {
		return array(
			'succeeded'      => array( 'payment_intent.succeeded', 'completed', 'pi_123' ),
			'payment failed' => array( 'payment_intent.payment_failed', 'failed', 'pi_abandoned' ),
		);
	}

	/**
	 * @testdox payment_intent.succeeded for the intent an unpaid order recorded pays it after the shopper switched to another gateway.
	 *
	 * Classic checkout resumes a pending or failed order, meta included, when the shopper pays again with another gateway
	 * (WC_Checkout::create_order(), includes/class-wc-checkout.php:413-433), so a late success of the abandoned card intent
	 * still names the order through `_intent_id`. Client 11.1.0 resolves the order by `_intent_id`
	 * (class-wc-payments-webhook-processing-service.php:974-1000) and completes it through update_order_status_from_intent() (:582).
	 */
	public function test_succeeded_intent_the_order_recorded_pays_it_after_a_gateway_switch(): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct bank transfer' );
		$order->set_status( 'on-hold' );
		$order->save();

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, $this->get_card_charge_overrides() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertNotNull( $order->get_date_paid() );
		$this->assertSame( 'pi_123', $order->get_transaction_id() );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'bacs', $order->get_payment_method() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'successfully charged' ) );
	}

	/**
	 * Intent overrides giving the first charge a card payment method, so the event names a WooPayments display title.
	 *
	 * Stripe API Charge `payment_method_details` with its `type` (read by client 11.1.0 at
	 * class-wc-payments-webhook-processing-service.php:584) and the card's `brand` and `last4`.
	 *
	 * @return array<string,mixed>
	 */
	private function get_card_charge_overrides(): array {
		return array(
			'charges' => array(
				'data' => array(
					array(
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand' => 'visa',
								'last4' => '4242',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @testdox A success or an authorization of an intent the takeover listed leaves the order alone before ownership, with one note however often it is delivered: $_dataName.
	 * @dataProvider provide_skipped_superseded_attempts
	 *
	 * Client 11.3.0 asks its superseded-attempt predicate in the success and the authorization handlers before anything
	 * they write (class-wc-payments-webhook-processing-service.php:464-470, :632-640) and notes the skip (:1112-1121); the
	 * built-in asks it under the claim, before ownership, so the success's prelude never rewrites the stored payment. The
	 * wording says the customer checked out with another payment method. The client notes every delivery; the built-in
	 * writes the note once per event type and intent, as its other-charge note. The authorization is the recorded
	 * manual_capture_amount_capturable_updated event (Fixtures/platform-payment-intent-events.json). The success note asks
	 * the merchant to check the payment, so it links the payment to its transaction page, escaped in the address and the
	 * text; the authorization note names it plainly.
	 *
	 * @param string $kind          Which event, `success` or `authorization`.
	 * @param string $order_status  Order status.
	 * @param string $method        The order's payment method.
	 * @param string $expected_note The note, with %1$s for the intent, %2$s for the charge, %3$s for the intent's
	 *                              transaction page and %4$s for the store's admin.php address.
	 * @param string $success_id    The intent of the success event.
	 */
	public function test_listed_attempt_event_is_skipped( string $kind, string $order_status, string $method, string $expected_note, string $success_id ): void {
		// The recorded authorization is a test-mode event, which the ingestor applies only in test mode.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order     = StoredPaymentRecords::after_a_takeover_cleanup( array(), time(), $order_status, '', 'ch_A', $method );
		$event     = 'success' === $kind
			? $this->create_payment_intent_event(
				'payment_intent.succeeded',
				$order,
				array(
					'id'      => $success_id,
					'charges' => array( 'data' => array( array( 'id' => 'ch_late' ) ) ),
				)
			)
			: $this->recorded_authorization_event( $order );
		$intent_id = (string) $event['data']['object']['id'];
		$charge_id = (string) $event['data']['object']['charges']['data'][0]['id'];
		$order->set_transaction_id( 'success' === $kind ? $intent_id : '' );
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, array( $intent_id ) );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.62' );
		$order->update_meta_data( '_wcpay_net', '10.37' );
		$order->save();

		$this->sut->process( $event );
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $order_status, $order->get_status() );
		$this->assertSame(
			array( '', 'ch_A', '0.62', '10.37' ),
			array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_charge_id', true ), $order->get_meta( '_wcpay_transaction_fee', true ), $order->get_meta( '_wcpay_net', true ) ),
			'Nothing of the payment is written.'
		);
		$transaction_page = admin_url( 'admin.php' ) . '?page=wc-admin&#038;path=%2Fpayments%2Ftransactions%2Fdetails&#038;id=' . $intent_id;
		$this->assertCount( 1, $this->get_order_notes_containing( $order, sprintf( $expected_note, $intent_id, $charge_id, $transaction_page, admin_url( 'admin.php' ) ) ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * Skipped events and the orders they arrive on.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function provide_skipped_superseded_attempts(): array {
		return array(
			'a success on a paid order whose transaction ID names it' => array( 'success', OrderStatus::PROCESSING, 'bacs', 'A WooPayments payment <a href="%3$s" target="_blank" rel="noopener noreferrer">%1$s</a> (charge <code>%2$s</code>) succeeded after the customer checked out with another payment method, so the order was not updated. Check whether the payment needs a refund.', 'pi_A' ),
			'a success with a hostile payment ID' => array( 'success', OrderStatus::PROCESSING, 'bacs', 'A WooPayments payment <a href="%4$s?page=wc-admin&#038;path=%%2Fpayments%%2Ftransactions%%2Fdetails&#038;id=pi_A&#039;bx/b" target="_blank" rel="noopener noreferrer">pi_A&#039;&quot;&gt;&lt;b&gt;x&lt;/b&gt;</a> (charge <code>%2$s</code>) succeeded after the customer checked out with another payment method, so the order was not updated. Check whether the payment needs a refund.', 'pi_A\'"><b>x</b>' ),
			'an authorization on a Pending WooPayments order' => array( 'authorization', OrderStatus::PENDING, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'A WooPayments payment <code>%1$s</code> was authorized after the customer checked out with another payment method, so the order was not updated. The authorization was not captured and expires on its own.', '' ),
		);
	}

	/**
	 * @testdox A success of an intent created before the takeover time is skipped and listed while no mode is stored, and goes to ownership once a later attempt stored one: $_dataName.
	 * @dataProvider provide_takeover_time_catches
	 *
	 * Client 11.3.0 catches an older attempt the order no longer named by its creation time against the takeover time,
	 * and stops once a new WooPayments attempt writes the mode back (class-wc-payments-order-service.php:1355-1358,
	 * :1372-1380); the built-in also lists the caught intent, so its later events are told apart by the list. The success
	 * is the recorded card_checkout_succeeded event (Fixtures/platform-payment-intent-events.json), whose `created` is its
	 * intent's creation time.
	 *
	 * @param string $mode    The mode a later attempt stored, or '' for none.
	 * @param bool   $skipped Whether the success is skipped.
	 */
	public function test_success_caught_by_the_takeover_time( string $mode, bool $skipped ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event = $this->create_recorded_card_succeeded_event( wc_create_order() );
		$order = StoredPaymentRecords::after_a_takeover_cleanup( array( 'pi_other' ), (int) $event['data']['object']['created'] + 60, OrderStatus::PENDING, '', '', 'bacs', '', $mode );
		$event = $this->create_recorded_card_succeeded_event( $order );

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( $skipped ? array( 'pi_other', (string) $event['data']['object']['id'] ) : array( 'pi_other' ), $order->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertCount( $skipped ? 1 : 0, $this->get_order_notes_containing( $order, 'succeeded after the customer checked out with another payment method' ) );
		$this->assertCount( $skipped ? 0 : 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * Stored modes when an older attempt's success arrives.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provide_takeover_time_catches(): array {
		return array(
			'no mode'                         => array( '', true ),
			'a later attempt stored its mode' => array( 'test', false ),
		);
	}

	/**
	 * @testdox An in-person success after a takeover cleanup completes the order: $_dataName.
	 * @dataProvider provide_in_person_after_a_takeover
	 *
	 * The in-person route sets the WooPayments method and stores its intent before the capture, and the mode only after it
	 * (WooPaymentsTerminalPaymentsRestController), so a webhook of a payment whose route failed in between meets no mode.
	 * Client 11.3.0's predicate reads only the list, the mode and the time (class-wc-payments-order-service.php:1366-1380),
	 * so at the clock edge it skips such a payment; the built-in never skips the intent the order stores, and keeps the
	 * client's time comparison for any other. The event is the recorded card_present success
	 * (Fixtures/platform-card-present-succeeded-event.json). This store has no simulated reader, so no live row covers it.
	 *
	 * @param int  $time_offset   Seconds the takeover time is after the intent's creation; negative for before it.
	 * @param bool $stores_intent Whether the order stores the in-person intent and names it in its transaction ID.
	 */
	public function test_in_person_success_after_a_takeover_completes_the_order( int $time_offset, bool $stores_intent ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event     = $this->create_recorded_in_person_succeeded_event( wc_create_order(), 'card_present' );
		$intent_id = (string) $event['data']['object']['id'];
		$order     = StoredPaymentRecords::after_a_takeover_cleanup( array( 'pi_old' ), (int) $event['data']['object']['created'] + $time_offset, $stores_intent ? OrderStatus::ON_HOLD : OrderStatus::PENDING, $stores_intent ? $intent_id : '', '', WooPaymentsPersistenceVocabulary::GATEWAY_ID, $stores_intent ? $intent_id : '' );

		$event = $this->create_recorded_in_person_succeeded_event( $order, 'card_present' );
		// The stored-record orders total 10.99.
		$event['data']['object']['amount']          = 1099;
		$event['data']['object']['amount_received'] = 1099;

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status(), 'The payment completes the order, which has nothing to ship.' );
		$this->assertSame( array( 'pi_old' ), $order->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'after the customer checked out with another payment method' ) );
	}

	/**
	 * In-person payments after a takeover.
	 *
	 * @return array<string,array{int,bool}>
	 */
	public function provide_in_person_after_a_takeover(): array {
		return array(
			'an intent created after the takeover, not yet stored' => array( -60, false ),
			'an intent created before the takeover, stored by the route' => array( 60, true ),
		);
	}

	/**
	 * @testdox An Interac success that arrives before its in-person route, at the takeover's clock edge, is skipped and listed; once the route stores it, its redelivery is not skipped.
	 *
	 * The first delivery's note is the recorded residual: the route then records the payment. The redelivery reaches the
	 * list, which yields to the intent the order now stores. The event derives from the recorded card_present success as
	 * create_recorded_in_person_succeeded_event() describes.
	 */
	public function test_interac_success_before_its_route_is_skipped_until_the_route_stores_it(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event = $this->create_recorded_in_person_succeeded_event( wc_create_order(), 'interac_present' );
		$order = StoredPaymentRecords::after_a_takeover_cleanup( array(), (int) $event['data']['object']['created'] + 60 );
		$event = $this->create_recorded_in_person_succeeded_event( $order, 'interac_present' );
		// The stored-record orders total 10.99.
		$event['data']['object']['amount']          = 1099;
		$event['data']['object']['amount_received'] = 1099;

		$this->sut->process( $event );

		$intent_id = (string) $event['data']['object']['id'];
		$order     = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( array( $intent_id ), $order->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'succeeded after the customer checked out with another payment method' ) );

		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->save();
		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'succeeded after the customer checked out with another payment method' ), 'The stored intent is not skipped.' );
	}

	/**
	 * @testdox An expiry of a listed intent's kept charge, on an order a later WooPayments attempt made its own again, is recorded as another charge before ownership, with no platform read.
	 *
	 * The expiry asks the takeover's list at the intent-event call site, as the success, the failure and the authorization
	 * do there. Its producer: an authorization left under a stale status is cleaned up with no cancel and keeps its charge,
	 * a WooPayments retry ends in a raised decline that stores its own intent, and the hold expires about seven days later.
	 */
	public function test_expiry_of_a_listed_attempts_kept_charge_is_another_charge(): void {
		$order = StoredPaymentRecords::after_a_takeover_cleanup( array( 'pi_A' ), time() - DAY_IN_SECONDS, OrderStatus::FAILED, '', 'ch_A', WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pi_B', 'test' );
		$sut   = $this->create_charge_expired_ingestor( array( 'status' => 'canceled' ), $requested_intents );

		$sut->process(
			array(
				'id'   => 'evt_expired_listed',
				'type' => 'charge.expired',
				'data' => array(
					'object' => array(
						'id'             => 'ch_A',
						'payment_intent' => 'pi_A',
					),
				),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::FAILED, $order->get_status() );
		$this->assertSame( array(), $requested_intents );
		$this->assertSame( 'pi_B', $order->get_meta( '_intent_id', true ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'which does not pay this order, expired' ) );
	}

	/**
	 * Build a payment_intent.succeeded event for the order's stored intent and charge.
	 *
	 * @param WC_Order $order         Order whose `_intent_id` and `_charge_id` the intent and its charge carry.
	 * @param int      $intent_amount Intent amount in minor units.
	 * @return array<string,mixed>
	 */
	private function create_stored_payment_success_event( WC_Order $order, int $intent_amount ): array {
		return $this->create_payment_intent_event(
			'payment_intent.succeeded',
			$order,
			array(
				'id'      => (string) $order->get_meta( '_intent_id', true ),
				'amount'  => $intent_amount,
				'charges' => array( 'data' => array( array( 'id' => (string) $order->get_meta( '_charge_id', true ) ) ) ),
			)
		);
	}

	/**
	 * Create a WooPayments-paid order for webhook tests.
	 *
	 * @return WC_Order
	 */
	private function create_woopayments_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create an order WooPayments paid with an intent and its charge, as a WooPayments payment leaves it.
	 *
	 * @param string $charge_id Charge ID.
	 * @param string $intent_id Payment intent ID, the order's transaction ID.
	 * @return WC_Order
	 */
	private function create_paid_woopayments_order( string $charge_id, string $intent_id ): WC_Order {
		$order = $this->create_woopayments_order();
		$order->set_status( 'processing' );
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		return $order;
	}

	/**
	 * Build a notification event handler that records each processed event.
	 *
	 * @return WooPaymentsNotificationEventHandler
	 */
	private function create_recording_notification_handler(): WooPaymentsNotificationEventHandler {
		return new class() extends WooPaymentsNotificationEventHandler {
			/**
			 * Recorded processed events.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $processed_events = array();

			/**
			 * Record a notification event.
			 *
			 * @param array<string,mixed> $event Event payload.
			 * @return void
			 */
			public function process( array $event ): void {
				$this->processed_events[] = $event;
			}
		};
	}

	/**
	 * Build an ingestor with a specific notification event handler injected.
	 *
	 * @param WooPaymentsNotificationEventHandler $notification_event_handler Notification handler.
	 * @return EventIngestor
	 */
	private function create_ingestor_with_notification_handler( WooPaymentsNotificationEventHandler $notification_event_handler ): EventIngestor {
		// Match the test-mode notification events (livemode === false) so they are not skipped as a mode mismatch.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		return $this->build_event_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends Client {},
			$this->create_dispute_event_handler( new class() extends Client {} ),
			wc_get_container()->get( RefundEventHandler::class ),
			wc_get_container()->get( WooPaymentsAccountEventHandler::class ),
			$notification_event_handler
		);
	}

	/**
	 * The platform's promotion start note (Fixtures/platform-notification-events.json), with an event ID when one is given.
	 *
	 * The platform sends wcpay.notification without an ID; these rows give it one only to exercise the ingestor's ID rules.
	 *
	 * @param string $event_id Event ID, or '' for none.
	 * @return array<string,mixed>
	 */
	private function create_notification_event( string $event_id ): array {
		$event = RecordedPlatformEvents::body( 'platform-notification-events.json', 'promotion_start_note' );
		if ( '' !== $event_id ) {
			$event['id'] = $event_id;
		}

		return $event;
	}

	/**
	 * Build an ingestor from the core collaborators, wiring its event handlers from the container
	 * and the supplied API client.
	 *
	 * @param OrderPaymentLifecycleService       $lifecycle_service Order lifecycle service.
	 * @param Client                             $api_client                       Native WooPayments API client.
	 * @param EarlyFraudWarningEventHandler|null $early_fraud_warning_event_handler Optional early fraud warning handler.
	 * @return EventIngestor
	 */
	private function create_ingestor( OrderPaymentLifecycleService $lifecycle_service, Client $api_client, ?EarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null ): EventIngestor {
		return $this->build_event_ingestor(
			$lifecycle_service,
			$api_client,
			$this->create_dispute_event_handler( $api_client ),
			wc_get_container()->get( RefundEventHandler::class ),
			wc_get_container()->get( WooPaymentsAccountEventHandler::class ),
			$this->create_notification_event_handler(),
			$early_fraud_warning_event_handler
		);
	}

	/**
	 * Build an early fraud warning handler that records routed objects.
	 *
	 * @param string[] $sequence     Shared delivery sequence.
	 * @param bool     $should_throw Whether processing should throw.
	 * @return EarlyFraudWarningEventHandler
	 */
	private function create_recording_early_fraud_warning_handler( array &$sequence, bool $should_throw = false ): EarlyFraudWarningEventHandler {
		$handler               = new class( $sequence ) extends EarlyFraudWarningEventHandler {
			/**
			 * Shared delivery sequence.
			 *
			 * @var string[]
			 */
			private $sequence;

			/**
			 * Routed event objects.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>}>
			 */
			public array $processed_events = array();

			/**
			 * Whether processing should throw.
			 *
			 * @var bool
			 */
			public bool $should_throw = false;

			/**
			 * Set the shared delivery sequence.
			 *
			 * @param string[] $sequence Shared delivery sequence.
			 */
			public function __construct( array &$sequence ) {
				$this->sequence =& $sequence;
			}

			/**
			 * Tell whether this fake supports the supplied event.
			 *
			 * @param string $event_type Provider event type.
			 * @return bool
			 */
			public function is_supported_event( string $event_type ): bool {
				return in_array( $event_type, array( 'radar.early_fraud_warning.created', 'radar.early_fraud_warning.updated' ), true );
			}

			/**
			 * Record an early fraud warning event object.
			 *
			 * @param string              $event_type   Provider event type.
			 * @param array<string,mixed> $event_object Provider event object.
			 * @throws RuntimeException When configured to fail.
			 */
			public function process( string $event_type, array $event_object ): void {
				$this->sequence[]         = 'handler';
				$this->processed_events[] = array( $event_type, $event_object );
				if ( $this->should_throw ) {
					throw new RuntimeException( 'Recorded early fraud warning failure.' );
				}
			}
		};
		$handler->should_throw = $should_throw;

		return $handler;
	}

	/**
	 * An early fraud warning event the platform forwarded (Fixtures/platform-early-fraud-warning-events.json).
	 *
	 * @param string $event_type Event type.
	 * @return array<string,mixed>
	 */
	private function create_early_fraud_warning_event( string $event_type = 'radar.early_fraud_warning.created' ): array {
		return RecordedPlatformEvents::body( 'platform-early-fraud-warning-events.json', 'radar.early_fraud_warning.updated' === $event_type ? 'early_fraud_warning_updated' : 'early_fraud_warning_created' );
	}

	/**
	 * @testdox Should route $event_type to the early fraud warning handler and preserve delivery hook order and arguments around it.
	 *
	 * @testWith ["radar.early_fraud_warning.created"]
	 *           ["radar.early_fraud_warning.updated"]
	 *
	 * @param string $event_type Early fraud warning event type.
	 */
	public function test_early_fraud_warning_preserves_delivery_hook_order_and_arguments( string $event_type ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$sequence   = array();
		$handler    = $this->create_recording_early_fraud_warning_handler( $sequence );
		$sut        = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends Client {},
			$handler
		);
		$event      = $this->create_early_fraud_warning_event( $event_type );
		$hook_calls = array();
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			static function ( string $event_type, array $event_body ) use ( &$sequence, &$hook_calls ): void {
				$sequence[]   = 'before';
				$hook_calls[] = array( $event_type, $event_body );
			},
			10,
			2
		);
		add_action(
			'woocommerce_payments_after_webhook_delivery',
			static function ( string $event_type, array $event_body ) use ( &$sequence, &$hook_calls ): void {
				$sequence[]   = 'after';
				$hook_calls[] = array( $event_type, $event_body );
			},
			10,
			2
		);

		$sut->process( $event );

		$this->assertSame( array( 'before', 'handler', 'after' ), $sequence );
		$this->assertSame(
			array(
				array( $event_type, $event ),
				array( $event_type, $event ),
			),
			$hook_calls
		);
		$this->assertSame( array( array( $event_type, $event['data']['object'] ) ), $handler->processed_events );
	}

	/**
	 * @testdox Should skip early fraud warning lookup and hooks when the webhook mode mismatches.
	 */
	public function test_early_fraud_warning_mode_mismatch_skips_lazy_handler_and_order_lookup(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$sut        = $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends Client {}
		);
		$hook_calls = array();
		add_action(
			'woocommerce_payments_before_webhook_delivery',
			static function () use ( &$hook_calls ): void {
				$hook_calls[] = 'before';
			}
		);
		$event             = $this->create_early_fraud_warning_event();
		$event['livemode'] = true;

		$logger = RecordingWcLogger::install();

		$sut->process( $event );

		$this->assertSame( array(), $hook_calls );
		$this->assertSame( array( array( 'error', 'Webhook event mode did not match the gateway mode (event ID: ' . $event['id'] . ')', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * Create a WooPayments order with a captured charge for refund webhook tests, paid by its intent as a WooPayments payment leaves it.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_refundable_woopayments_order( string $total ): WC_Order {
		$order   = $this->create_woopayments_order();
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $total );
		$product->set_price( $total );
		$product->save();
		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => (float) $total,
				'total'    => (float) $total,
			)
		);
		$charge_id = 'ch_' . $order->get_id();
		$order->set_total( $total );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_' . $order->get_id() );
		$order->update_meta_data( '_intent_id', 'pi_' . $order->get_id() );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();
		$this->last_refund_charge_id = $charge_id;

		return $order;
	}

	/**
	 * Create a local refund fixture.
	 *
	 * @param WC_Order $order  Order object.
	 * @param float    $amount Refund amount.
	 * @param string   $reason Refund reason.
	 * @return WC_Order_Refund
	 */
	private function create_local_refund( WC_Order $order, float $amount, string $reason ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);

		if ( ! $refund instanceof WC_Order_Refund ) {
			$this->fail( 'Expected local refund creation to return a WC_Order_Refund.' );
		}

		return $refund;
	}

	/**
	 * Cache a fresh live account with this country, readable through the account service while the platform is connected.
	 *
	 * @param string $country Account country, or '' for account data without one.
	 */
	private function set_woopayments_account_country( string $country ): void {
		// Account fields as recorded in ../Fixtures/platform-test-drive-account.json `account` (`account_id`, `is_live`, `country`).
		// Varied: `is_live` is true, so the account service accepts the cache without turning on onboarding test mode
		// (WooPaymentsAccountService::is_valid_cached_account()), and `country` is left out for account data without one.
		$data = array(
			'account_id' => 'acct_123',
			'is_live'    => true,
		);
		if ( '' !== $country ) {
			$data['country'] = $country;
		}

		$this->cache_woopayments_account( $data );
	}

	/**
	 * Cache a fresh account, readable through the account service while the platform is connected.
	 *
	 * @param array<string,mixed> $data Account data; `is_live` true makes the account service accept it as it is.
	 */
	private function cache_woopayments_account( array $data ): void {
		// The account service keeps the cache it already read in this request.
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		// The option envelope client 11.1.0 Database_Cache writes (class-database-cache.php:377-382; an entry without
		// consecutive_errors reads as zero, :367).
		update_option(
			'wcpay_account_data',
			array(
				'data'    => $data,
				'fetched' => time(),
				'errored' => false,
			)
		);
		wc_get_container()->replace(
			Client::class,
			new class() extends Client {
				/**
				 * Report the platform as connected, so the account service reads the cached account.
				 *
				 * @return bool
				 */
				public function is_available(): bool {
					return true;
				}
			}
		);
	}

	/**
	 * Assert that an order has a note with the expected exact content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assertOrderHasNote( WC_Order $order, string $expected ): void {
		$count = 0;
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( $expected === $note->content ) {
				++$count;
			}
		}

		$this->assertGreaterThan( 0, $count, "Missing order note: {$expected}" );
	}

	/**
	 * Count order notes with exact content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 * @return int
	 */
	private function count_order_notes_matching( WC_Order $order, string $expected ): int {
		$count = 0;
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( $expected === (string) $note->content ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Install test-only WooCommerce translations.
	 *
	 * @param array<string,string> $replacements Source text to translated text.
	 */
	private function install_woocommerce_test_translations( array $replacements ): void {
		$this->gettext_replacements = $replacements;
		add_filter( 'gettext', array( $this, 'translate_woocommerce_test_string' ), 10, 3 );
	}

	/**
	 * Install test-only translations for one text domain.
	 *
	 * @param string               $domain       Text domain.
	 * @param array<string,string> $replacements Source text to translated text.
	 */
	private function install_test_translations_for_domain( string $domain, array $replacements ): void {
		$this->gettext_domain_replacements[ $domain ] = $replacements;
		add_filter( 'gettext', array( $this, 'translate_woocommerce_test_string' ), 10, 3 );
	}

	/**
	 * Translate a WooCommerce string for tests.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_woocommerce_test_string( string $translation, string $text, string $domain ): string {
		if ( isset( $this->gettext_domain_replacements[ $domain ][ $text ] ) ) {
			return $this->gettext_domain_replacements[ $domain ][ $text ];
		}

		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		return $this->gettext_replacements[ $text ] ?? $translation;
	}

	/**
	 * Assert that no order note contains a fragment.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $fragment Fragment that must not appear.
	 */
	private function assertOrderHasNoNoteContaining( WC_Order $order, string $fragment ): void {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$this->assertStringNotContainsString( $fragment, $note->content );
		}
	}

	/**
	 * Tell whether the Fee details job is pending with the client's arguments.
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $intent_id    Payment intent ID.
	 * @param bool   $is_test_mode Expected test mode argument.
	 * @return bool
	 */
	private function is_fee_details_job_pending( int $order_id, string $intent_id, bool $is_test_mode ): bool {
		return as_has_scheduled_action(
			WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
			array(
				'order_id'     => $order_id,
				'intent_id'    => $intent_id,
				'is_test_mode' => $is_test_mode,
			),
			'woocommerce_payments'
		);
	}

	/**
	 * Assert that an order has a note containing all expected fragments.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string[] $fragments Expected note fragments.
	 */
	private function assertOrderHasNoteContaining( WC_Order $order, array $fragments ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			$content = (string) $note->content;
			foreach ( $fragments as $fragment ) {
				if ( false === strpos( $content, $fragment ) ) {
					continue 2;
				}
			}

			$this->addToAssertionCount( 1 );
			return;
		}

		$this->fail( 'Missing order note containing: ' . implode( ', ', $fragments ) . '. Notes: ' . wp_json_encode( wp_list_pluck( $notes, 'content' ) ) );
	}

	/**
	 * Assert that an order does not have a note containing all expected fragments.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string[] $fragments Expected note fragments.
	 */
	private function assertOrderLacksNoteContaining( WC_Order $order, array $fragments ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			$content = (string) $note->content;
			foreach ( $fragments as $fragment ) {
				if ( false === strpos( $content, $fragment ) ) {
					continue 2;
				}
			}

			$this->fail( 'Unexpected order note containing: ' . implode( ', ', $fragments ) . '. Notes: ' . wp_json_encode( wp_list_pluck( $notes, 'content' ) ) );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Get the order notes containing a text fragment.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $fragment Required note fragment.
	 * @return object[]
	 */
	private function get_order_notes_containing( WC_Order $order, string $fragment ): array {
		return array_values(
			array_filter(
				wc_get_order_notes(
					array(
						'order_id' => $order->get_id(),
						'type'     => 'any',
					)
				),
				static fn( $note ): bool => false !== strpos( (string) $note->content, $fragment )
			)
		);
	}

	/**
	 * Seed stale dispute cache options.
	 */
	private function seed_dispute_cache_options(): void {
		foreach ( $this->get_dispute_cache_option_keys() as $key ) {
			update_option( $key, array( 'stale' => true ), false );
		}
	}

	/**
	 * Assert that dispute cache options were deleted.
	 */
	private function assert_dispute_cache_options_deleted(): void {
		foreach ( $this->get_dispute_cache_option_keys() as $key ) {
			$this->assertFalse( get_option( $key, false ), "Expected dispute cache option {$key} to be deleted." );
		}
	}

	/**
	 * Delete dispute cache options.
	 */
	private function delete_dispute_cache_options(): void {
		foreach ( $this->get_dispute_cache_option_keys() as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Get the reference WooPayments dispute cache option keys.
	 *
	 * @return string[]
	 */
	private function get_dispute_cache_option_keys(): array {
		return array(
			'wcpay_dispute_status_counts_cache',
			'wcpay_test_dispute_status_counts_cache',
			'wcpay_active_dispute_cache',
		);
	}

	/**
	 * Unset a nested field under the dispute event object.
	 *
	 * @param array<string,mixed> $event Dispute event.
	 * @param string[]            $path  Field path under data.object.
	 */
	private function unset_dispute_object_path( array &$event, array $path ): void {
		$target = &$event['data']['object'];
		$last   = array_pop( $path );
		foreach ( $path as $segment ) {
			$target = &$target[ $segment ];
		}

		unset( $target[ $last ] );
	}

	/**
	 * Create a dispute event shaped as the platform forwards one.
	 *
	 * The object keeps the fields of the recorded `charge.dispute.closed` body `accept_closed_lost`
	 * (`Fixtures/platform-dispute-closed-events.json`) that the dispute handler reads: id, charge, amount 5000, reason
	 * `fraudulent` and `evidence_details.due_by`. Intentional overrides: short placeholder IDs, the status the caller
	 * passes, a fixed due date, and no currency, livemode or account, so a lost close takes its amount from the summary.
	 *
	 * @param string              $type      Event type.
	 * @param string              $status    Dispute status.
	 * @param array<string,mixed> $overrides Object overrides.
	 * @return array<string,mixed>
	 */
	private function create_dispute_event( string $type, string $status, array $overrides = array() ): array {
		$object = array_replace_recursive(
			array(
				'id'               => 'du_123',
				'charge'           => 'ch_123',
				'amount'           => 5000,
				'reason'           => 'fraudulent',
				'status'           => $status,
				'evidence_details' => array(
					'due_by' => strtotime( '2026-07-01 00:00:00 UTC' ),
				),
			),
			$overrides
		);

		return array(
			'id'   => 'evt_dispute',
			'type' => $type,
			'data' => array(
				'object' => $object,
			),
		);
	}

	/**
	 * Load one recorded REC-DC `charge.dispute.created` webhook body by fixture pair key.
	 *
	 * @param string $pair REC-DC fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_dispute_created_event( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-dispute-created-events.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body'];
			}
		}

		$this->fail( "REC-DC fixture has no entry for pair '$pair'." );
	}

	/**
	 * A charge.refunded event the platform forwarded (Fixtures/platform-charge-dispute-events.json), for an order.
	 *
	 * The recorded charge, its intent and its order metadata are pointed at the order; the charge amount and the latest
	 * refund's amount and status are the test's.
	 *
	 * @param WC_Order $order         Order object.
	 * @param int      $charge_amount Charge amount in provider minor units.
	 * @param int      $refund_amount Refund amount in provider minor units.
	 * @param string   $refund_status Provider refund status.
	 * @param string   $pair          Recorded entry.
	 * @return array<string,mixed>
	 */
	private function create_charge_refunded_event( WC_Order $order, int $charge_amount, int $refund_amount, string $refund_status, string $pair = 'card_charge_refunded_in_full' ): array {
		$event     = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', $pair );
		$charge    = &$event['data']['object'];
		$charge_id = (string) $order->get_meta( '_charge_id', true );

		$charge['id']                           = $charge_id;
		$charge['payment_intent']               = (string) $order->get_meta( '_intent_id', true );
		$charge['metadata']['order_id']         = (string) $order->get_id();
		$charge['metadata']['order_key']        = $order->get_order_key();
		$charge['amount']                       = $charge_amount;
		$charge['refunds']['data'][0]['charge'] = $charge_id;
		$charge['refunds']['data'][0]['amount'] = $refund_amount;
		$charge['refunds']['data'][0]['status'] = $refund_status;
		unset( $charge );

		return $event;
	}

	/**
	 * Create a charge.refund.updated event.
	 *
	 * The envelope and the refund fields read are the ones client 11.1.0 reads (includes/class-wc-payments-webhook-processing-service.php:184-185, 300-309).
	 * A test that overrides the status with 'cancelled' sends a deliberately malformed status.
	 *
	 * @param array<string,mixed> $overrides Refund object overrides.
	 * @return array<string,mixed>
	 */
	private function create_refund_updated_event( array $overrides = array() ): array {
		$object = array_replace_recursive(
			array(
				'id'       => 're_123',
				'charge'   => $this->last_refund_charge_id,
				'amount'   => 400,
				'currency' => 'usd',
				'status'   => 'failed',
			),
			$overrides
		);

		return array(
			'id'   => 'evt_refund_updated',
			'type' => 'charge.refund.updated',
			'data' => array(
				'object' => $object,
			),
		);
	}

	/**
	 * Load a REC-5a R-c recorded `charge.refund.updated` event envelope (the exact body
	 * local WPCOM forwarded) by pair key, ready to pass straight to `EventIngestor::process()`.
	 *
	 * @param string $pair REC-5a R-c fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_refund_updated_event( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-refund-updated-events.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body'];
			}
		}

		$this->fail( "REC-5a R-c fixture has no entry for pair '$pair'." );
	}

	/**
	 * Load a REC-5b R-e recorded `charge.dispute.closed` event envelope (the exact body
	 * local WPCOM forwarded) by pair key, ready to pass straight to `EventIngestor::process()`.
	 *
	 * @param string $pair REC-5b R-e fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_dispute_closed_event( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-dispute-closed-events.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body'];
			}
		}

		$this->fail( "REC-5b R-e fixture has no entry for pair '$pair'." );
	}

	/**
	 * Load a REC-5b R-d recorded `get_dispute_summary()` response by pair key.
	 *
	 * @param string $pair REC-5b R-d fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_dispute_summary( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-disputes.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "REC-5b R-d fixture has no entry for pair '$pair'." );
	}

	/**
	 * Create an API client whose dispute summary read answers with the given summary.
	 *
	 * Callers pass the recorded `accept_summary_after_close` response (`Fixtures/platform-disputes.json`), whole or with
	 * the fields they change, or a summary cut to the fields the lost-dispute refund reads: disputed_amount and currency.
	 *
	 * @param array<string,mixed> $summary Dispute summary.
	 * @return Client
	 */
	private function create_dispute_summary_api_client( array $summary = array() ): Client {
		return new class( $summary ) extends Client {
			/**
			 * Dispute summary response.
			 *
			 * @var array<string,mixed>
			 */
			private array $summary;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $summary Dispute summary response.
			 */
			public function __construct( array $summary ) {
				$this->summary = $summary;
			}

			/**
			 * Retrieve a WooPayments dispute summary.
			 *
			 * @param string $dispute_id Dispute ID.
			 * @return array<string,mixed>
			 */
			public function get_dispute_summary( string $dispute_id ): array {
				return $this->summary;
			}
		};
	}

	/**
	 * Get the dispute details URL as the WooPayments extension puts it in its created and closed dispute notes: unescaped,
	 * with the charge ID only.
	 *
	 * Client 11.1.0 class-wc-payments-order-service.php:2681-2690 (compose_dispute_url()).
	 *
	 * @param string $charge_id Charge ID.
	 * @return string
	 */
	private function get_expected_dispute_url( string $charge_id ): string {
		return add_query_arg(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( '/payments/transactions/details' ),
				'id'   => $charge_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get the dispute details URL as the WooPayments extension puts it in its dispute update notes: unescaped, with the
	 * charge ID only (add_query_arg() re-encodes the path it is given, so the link reads path=%2Fpayments%2F…).
	 *
	 * Client 11.1.0 class-wc-payments-webhook-processing-service.php:825-832.
	 *
	 * @param string $charge_id Charge ID.
	 * @return string
	 */
	private function get_expected_dispute_update_url( string $charge_id ): string {
		return add_query_arg( array( 'id' => $charge_id ), admin_url( 'admin.php?page=wc-admin&path=/payments/transactions/details' ) );
	}

	/**
	 * Create a payment-intent-shaped event.
	 *
	 * The envelope (id, type, data.object) and the PaymentIntent fields are the ones client 11.1.0 reads:
	 * class-wc-payments-webhook-processing-service.php:145-160 (envelope), :494-519 (id, currency, amount,
	 * payment_method, charges.data[0] with its payment_method_details.card.mandate), :542 (application_fee_amount),
	 * :574-577 (metadata.ipp_channel) and :968-1002 (metadata.order_id and order_key for the order lookup); status is
	 * the PaymentIntent's own field (Stripe API PaymentIntent object). The amount is the order total of
	 * create_woopayments_order(), 10.00 USD.
	 *
	 * @param string              $type      Event type.
	 * @param WC_Order            $order     Order object.
	 * @param array<string,mixed> $overrides Object overrides.
	 * @param array<string,mixed> $event_overrides Event overrides.
	 * @return array<string,mixed>
	 */
	private function create_payment_intent_event( string $type, WC_Order $order, array $overrides = array(), array $event_overrides = array() ): array {
		$object = array_replace_recursive(
			array(
				'id'             => 'pi_123',
				'status'         => 'succeeded',
				'currency'       => 'usd',
				'amount'         => 1000,
				'payment_method' => 'pm_123',
				'metadata'       => array(
					'order_id'    => (string) $order->get_id(),
					'order_key'   => $order->get_order_key(),
					'ipp_channel' => 'mobile_pos',
				),
				'charges'        => array(
					'data' => array(
						array(
							'id'                     => 'ch_123',
							'payment_method'         => 'pm_123',
							'application_fee_amount' => 123,
							'payment_method_details' => array(
								'card' => array(
									'mandate' => 'mandate_123',
								),
							),
						),
					),
				),
			),
			$overrides
		);

		return array_replace_recursive(
			array(
				'id'   => 'evt_123',
				'type' => $type,
				'data' => array(
					'object' => $object,
				),
			),
			$event_overrides
		);
	}

	/**
	 * The charge of the payment_intent.succeeded the platform received for a simulated reader payment
	 * (Fixtures/platform-card-present-succeeded-event.json).
	 *
	 * @return array<string,mixed>
	 */
	private function create_card_present_charge(): array {
		$recording = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-card-present-succeeded-event.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		return $recording['events'][0]['data']['object']['charges']['data'][0];
	}

	/**
	 * Create a receipt email test double.
	 *
	 * @return object
	 */
	private function create_recording_ipp_receipt_email(): object {
		return new class() extends WooPaymentsIppReceiptEmail {
			/**
			 * Triggered receipt email payloads.
			 *
			 * @var array<int,array{order:WC_Order,merchant_settings:array<string,mixed>,charge:array<string,mixed>}>
			 */
			public array $triggered = array();

			/**
			 * Record the receipt email payload.
			 *
			 * @param WC_Order            $order             Order object.
			 * @param array<string,mixed> $merchant_settings Merchant settings.
			 * @param array<string,mixed> $charge            Charge payload.
			 */
			public function trigger( WC_Order $order, array $merchant_settings, array $charge ): void {
				$this->triggered[] = array(
					'order'             => $order,
					'merchant_settings' => $merchant_settings,
					'charge'            => $charge,
				);
			}
		};
	}

	/**
	 * Register a receipt email test double in the WooCommerce mailer.
	 *
	 * @param object $email Email object.
	 */
	private function register_ipp_receipt_email( object $email ): void {
		$filter = static function ( array $emails ) use ( $email ): array {
			$emails[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] = $email;
			return $emails;
		};

		$this->email_class_filters[] = $filter;
		add_filter( 'woocommerce_email_classes', $filter );
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			WC()->mailer()->emails[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] = $email;
		}
	}

	/**
	 * Put the mailer's own email objects back, replacing the receipt doubles the test registered.
	 *
	 * Rebuilding them with init() would leave the registry on new objects whose triggers the
	 * parent teardown's hook restore does not hook, while the hooks keep the original ones.
	 */
	private function reset_mailer_emails(): void {
		WC()->mailer()->emails = $this->original_mailer_emails;
	}
}
