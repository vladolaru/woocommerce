<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsPaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEarlyFraudWarningEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockWithClaimHook;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
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

/**
 * Tests for the WooPaymentsEventIngestor class.
 */
class WooPaymentsEventIngestorTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	use ProviderTextLogAssertions;

	use WooPaymentsEventHandlerTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEventIngestor
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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_multi_currency_options = array(
			'_wcpay_feature_customer_multi_currency'  => get_option( '_wcpay_feature_customer_multi_currency', null ),
			'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', null ),
			'wcpay_multi_currency_exchange_rate_eur'  => get_option( 'wcpay_multi_currency_exchange_rate_eur', null ),
			'wcpay_multi_currency_manual_rate_eur'    => get_option( 'wcpay_multi_currency_manual_rate_eur', null ),
		);
		$this->sut                             = wc_get_container()->get( WooPaymentsEventIngestor::class );
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
		$this->assertSame( '11.11', $order->get_meta( '_wcpay_net', true ) );
		$this->assertSame( 'mobile_pos', $order->get_meta( '_wcpay_ipp_channel', true ) );
	}

	/**
	 * @testdox A $event_type delivery refused by a held order payment lock fails, and a later delivery applies it once the lock is free.
	 * The events are the ones the platform forwarded for a card checkout and a card decline (Fixtures/platform-payment-intent-events.json).
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
		// The order's payment method is the one the checkout used: the succeeded intent's, or the declined card's.
		$order->update_meta_data( '_payment_method_id', (string) ( $intent['last_payment_error']['payment_method']['id'] ?? $intent['payment_method'] ) );
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
	 * @testdox A log handler that throws while the received line is written does not stop the event from being applied.
	 */
	public function test_failing_received_line_does_not_stop_the_event(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$order = $this->create_woopayments_order();
		add_filter(
			'woocommerce_logging_class',
			static function () {
				return new class() extends RecordingWcLogger {
					/**
					 * Fail as a broken log handler does.
					 *
					 * @param string              $message Message.
					 * @param array<string,mixed> $context Context.
					 * @throws RuntimeException For a non-empty message.
					 */
					public function debug( $message, $context = array() ) {
						if ( '' !== (string) $message ) {
							throw new RuntimeException( 'Log handler failure.' );
						}
						parent::debug( $message, $context );
					}
				};
			}
		);

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array(), array( 'id' => 'evt_received_logger_fails' ) ) );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox A delivery hook that throws, logged by a logger that throws too, does not stop the event from being applied.
	 */
	public function test_failing_log_of_a_failing_delivery_hook_does_not_stop_the_event(): void {
		$order    = $this->create_woopayments_order();
		$throwing = static function (): void {
			throw new RuntimeException( 'Delivery hook callback failure.' );
		};
		add_action( 'woocommerce_payments_before_webhook_delivery', $throwing );
		add_filter(
			'woocommerce_logging_class',
			static function () {
				return new class() extends RecordingWcLogger {
					/**
					 * Fail as a broken log handler does.
					 *
					 * @param string              $message Message.
					 * @param array<string,mixed> $context Context.
					 * @throws RuntimeException Always, for a non-empty message.
					 */
					public function error( $message, $context = array() ) {
						if ( '' !== (string) $message ) {
							throw new RuntimeException( 'Log handler failure.' );
						}
						parent::error( $message, $context );
					}
				};
			}
		);

		$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order, array(), array( 'id' => 'evt_hook_and_logger_fail' ) ) );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
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
	 * @testdox payment_intent.succeeded writes the repaired token through to related subscriptions.
	 */
	public function test_payment_intent_succeeded_repair_syncs_related_subscriptions(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
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

		$subscription = wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$subscription->save();

		$GLOBALS['wcpay_test_renewal_order_ids']                = array( $order->get_id() );
		$GLOBALS['wcpay_test_order_subscription_relationships'] = array(
			$order->get_id() => array( 'renewal' => array( $subscription->get_id() ) ),
		);

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS['wcpay_test_order_subscription_relationships'] );
		}

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription->get_payment_tokens() ), 'WCS copies the subscription tokens into each new renewal order; a stale subscription charges the replaced card next renewal.' );
		$this->assertSame( 'pm_123', $subscription->get_meta( '_payment_method_id', true ) );
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
	 * @testdox payment_intent.succeeded notes the token change when repair replaces an attached token.
	 */
	public function test_payment_intent_succeeded_notes_token_change_on_repair(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
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

		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		try {
			$this->sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertContains( $new_token->get_id(), array_map( 'absint', $order->get_payment_tokens() ) );
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
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Fail the summary fetch as the platform does.
			 *
			 * @param string $dispute_id Dispute ID.
			 * @throws \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException Always.
			 */
			public function get_dispute_summary( string $dispute_id ): array {
				unset( $dispute_id );
				throw WooPaymentsEventIngestorTest::make_provider_error();
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
	 * @param bool                     $saved       Whether business details with other values are saved in the settings.
	 */
	public function test_payment_intent_succeeded_sends_ipp_receipt_email_for_card_present_charge( string $description, ?array $account, array $expected, bool $saved = true ): void {
		unset( $description );
		// Business details saved in the settings, with other values: the receipt must not show them.
		$saved_details = array(
			'account_business_name'            => 'Saved Store',
			'account_business_support_address' => array(
				'line1'       => '9 Saved Road',
				'line2'       => '',
				'city'        => 'Oakland',
				'state'       => 'CA',
				'postal_code' => '94601',
				'country'     => 'US',
			),
			'account_business_support_phone'   => '+1 555 0999',
			'account_business_support_email'   => 'saved@example.test',
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) + ( $saved ? $saved_details : array() ) );
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
	 * @return array<string,array{0:string,1:array<string,mixed>|null,2:array<string,mixed>,3?:bool}>
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
			'nothing saved'             => array(
				'nothing saved',
				array(
					'account_id' => 'acct_123',
					'is_live'    => true,
				),
				$empty,
				false,
			),
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
	 * @testdox payment_intent.payment_failed with a failed $payment_method_type payment method fails the order and records the intent.
	 *
	 * The card row is the event the platform forwarded for a declined card checkout (Fixtures/platform-payment-intent-events.json,
	 * card_declined_failed), whose last_payment_error carries the full PaymentMethod. No AU BECS or terminal decline was
	 * delivered locally, so those rows keep the fields the handler reads.
	 *
	 * @dataProvider provider_actionable_payment_failures
	 *
	 * @param string $order_payment_method_id `_payment_method_id` on the order, or '' for the recorded event's.
	 * @param string $payment_method_id       Failed payment method ID in the event.
	 * @param string $payment_method_type     Failed payment method type in the event.
	 * @param string $pair                    Recorded event, or '' for a built one.
	 */
	public function test_payment_intent_failed_marks_order_failed( string $order_payment_method_id, string $payment_method_id, string $payment_method_type, string $pair = '' ): void {
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
		$order->save();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( $event['data']['object']['id'], $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * Failures the order is failed for.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3?:string}>
	 */
	public function provider_actionable_payment_failures(): array {
		return array(
			'card'                                 => array( '', '', 'card', 'card_declined_failed' ),
			'AU BECS debit'                        => array( 'pm_123', 'pm_123', 'au_becs_debit' ),
			'card present, another payment method' => array( 'pm_current', 'pm_terminal', 'card_present' ),
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
	 * @testdox payment_intent.payment_failed adopts the exact German WooPayments extension catalog note.
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
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'pi_standard_failure', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_order_notes_matching( $order, $plugin_note ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Core-Zahlung' ) );
	}

	/**
	 * @testdox payment_intent.payment_failed adopts the exact German WooPayments extension terminal note with a mapped suffix.
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
		$this->assertSame( 'pi_terminal_failure', $order->get_meta( '_intent_id', true ) );
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
		$this->assertSame( array( $second_intent, 'requires_payment_method' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ) ) );
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
							'type' => 'klarna',
						),
					),
				),
				'',
			),
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

		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail the intent retrieval.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException Always.
			 */
			public function get_payment_intention( string $intent_id ): array {
				unset( $intent_id );
				throw new WooPaymentsApiException( 'boom', 'wcpay_server_error', 500 );
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
		} catch ( WooPaymentsApiException $exception ) {
			$thrown = $exception;
		}
		$this->assertInstanceOf( WooPaymentsApiException::class, $thrown );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * Create an ingestor whose API client returns a fixture intent for charge.expired.
	 *
	 * @param array<string,mixed>    $intent            Intent fixture merged over defaults.
	 * @param array<int,string>|null $requested_intents Receives the requested intent IDs.
	 * @return WooPaymentsEventIngestor
	 */
	private function create_charge_expired_ingestor( array $intent, ?array &$requested_intents = null ): WooPaymentsEventIngestor {
		$requested_intents = array();
		$api_client        = new class( $intent, $requested_intents ) extends WooPaymentsApiClient {
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
	 * @testdox A created dispute with a non-scalar ID fails before order or cache effects.
	 */
	public function test_dispute_created_with_non_scalar_id_fails_closed(): void {
		$order = $this->create_woopayments_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		$event       = $this->create_dispute_event( 'charge.dispute.created', 'needs_response', array( 'id' => array( 'not-scalar' ) ) );
		$event['id'] = 'evt_row_39_non_scalar_id';
		$this->seed_dispute_cache_options();

		$exception = null;
		try {
			$this->sut->process( $event );
		} catch ( InvalidArgumentException $caught ) {
			$exception = $caught;
		}

		$this->assertInstanceOf( InvalidArgumentException::class, $exception );
		$this->assertStringContainsString( 'Expected scalar dispute webhook property: id', $exception->getMessage() );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'Payment has been disputed' ) );
		$this->assertOrderLacksNoteContaining( $order, array( 'A payment inquiry has been raised' ) );
		foreach ( $this->get_dispute_cache_option_keys() as $key ) {
			$this->assertSame( array( 'stale' => true ), get_option( $key, false ), "Expected dispute cache option {$key} to remain after failed validation." );
		}
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
	 * @return WooPaymentsEventIngestor
	 */
	private function create_ingestor_without_the_stripe_billing_module(): WooPaymentsEventIngestor {
		$module = $this->getMockBuilder( WooPaymentsStripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		return $this->create_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends WooPaymentsApiClient {
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
	 * @testdox Succeeded and failed intents, refund and dispute events, and invoice.paid while Stripe Billing is loaded, are retried after a failure.
	 */
	public function test_retried_event_types(): void {
		$module = $this->getMockBuilder( WooPaymentsStripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded' ) )
			->getMock();
		$loaded = false;
		$module->method( 'is_loaded' )->willReturnCallback(
			static function () use ( &$loaded ): bool {
				return $loaded;
			}
		);
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		$retried = fn( string $type ): bool => $this->sut->is_retried_event( array( 'type' => $type ) );

		foreach ( array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded', 'charge.refund.updated', 'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' ) as $type ) {
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
		$module = $this->getMockBuilder( WooPaymentsStripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( true );
		$module->method( 'handle_invoice_event' )->willReturnCallback(
			static function ( array $event ) use ( &$calls ): void {
				$calls[] = 'module ' . $event['id'];
			}
		);
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );
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
	 * @testdox A $status charge.refund.updated deletes the linked local refund, fires the refund deleted hook and leaves a $order_status order $expected_status.
	 *
	 * Client 11.1.0 marks the order failed only when it is refunded (class-wc-payments-order-service.php:2002-2005).
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
		$this->assertSame( array( array( $refund_id, $order->get_id() ) ), $hook_calls );
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
	 * @testdox charge.refund.updated repairs status metadata when a failed-refund note already exists.
	 */
	public function test_charge_refund_updated_failed_reconciles_existing_note_without_returning_early(): void {
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
		$order->delete_meta_data( '_wcpay_refund_status' );
		$order->set_status( 'refunded' );
		$order->save();

		$this->sut->process( $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
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
		// WooPaymentsEventIngestor::is_webhook_mode_mismatch() silently skips the event.
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
	 * @testdox An event whose handler throws fires the before-delivery hook only and keeps the dispute caches.
	 */
	public function test_event_whose_handler_throws_skips_the_after_delivery_hook_and_the_dispute_cache_purge(): void {
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
		foreach ( $this->get_dispute_cache_option_keys() as $key ) {
			$this->assertSame( array( 'stale' => true ), get_option( $key, false ), "Expected dispute cache option {$key} to remain after the handler threw." );
		}
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
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'Direct bank transfer', $order->get_payment_method_title() );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * @testdox payment_intent.succeeded on an order already paid by another gateway is recorded once, writes only the charge ID, and logs one line.
	 *
	 * Client 11.1.0 writes the intent meta on any resolved order (class-wc-payments-webhook-processing-service.php:510-569)
	 * and then skips paid orders (class-wc-payments-order-service.php:2747-2764, :2863-2879). Here the order records the
	 * charge with one note, so the merchant can find and refund it, and keeps its payment data.
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
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$record_notes = $this->get_order_notes_containing( $order, 'does not pay this order' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'ch_123', $record_notes[0]->content );
		$this->assertStringContainsString( '12.34', $record_notes[0]->content );
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
		$this->assertNull( wc_get_container()->get( WooPaymentsEventOrderResolver::class )->find_order_by_charge_id( 'ch_unheld' ) );
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
		$sut = $this->create_ingestor( wc_get_container()->get( OrderPaymentLifecycleService::class ), wc_get_container()->get( WooPaymentsApiClient::class ) );

		$sut->process( $this->create_payment_intent_event( 'payment_intent.succeeded', $order ) );

		$this->assertTrue( $store->hook_ran, 'The other gateway must pay the order inside the claim.' );
		$order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertCount( 0, $this->get_order_notes_containing( $order, 'successfully charged' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order, 'does not pay this order' ) );
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
	 * Client 11.1.0 reads the order again before deciding it is unpaid (class-wc-payments-order-service.php:2863-2879), and
	 * another gateway's status change never takes the order payment lock.
	 *
	 * @testdox Applying a succeeded intent reads the order under the lock, so an order another gateway just marked paid is not paid again.
	 */
	public function test_succeeded_intent_reads_the_order_again_under_the_lock(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'bacs' );
		$order->set_status( 'on-hold' );
		$order->save();
		$stale = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stale );
		// The merchant confirms the bank transfer after the event resolved the order.
		$confirmed = wc_get_order( $order->get_id() );
		$confirmed->set_status( 'processing' );
		$confirmed->save();
		$payment_completions = did_action( 'woocommerce_payment_complete' );
		$event               = $this->create_payment_intent_event( 'payment_intent.succeeded', $order, $this->get_card_charge_overrides() );

		wc_get_container()->get( WooPaymentsPaymentIntentEventHandler::class )->apply_succeeded_payment_intent( $stale, $event['data']['object'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id(), 'An order paid in the meantime must not be marked paid again.' );
		$this->assertSame( $payment_completions, did_action( 'woocommerce_payment_complete' ) );
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
	 * @return WooPaymentsEventIngestor
	 */
	private function create_ingestor_with_notification_handler( WooPaymentsNotificationEventHandler $notification_event_handler ): WooPaymentsEventIngestor {
		// Match the test-mode notification events (livemode === false) so they are not skipped as a mode mismatch.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		return $this->build_event_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends WooPaymentsApiClient {},
			$this->create_dispute_event_handler( new class() extends WooPaymentsApiClient {} ),
			wc_get_container()->get( WooPaymentsRefundEventHandler::class ),
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
	 * @param OrderPaymentLifecycleService                  $lifecycle_service Order lifecycle service.
	 * @param WooPaymentsApiClient                          $api_client                       Native WooPayments API client.
	 * @param WooPaymentsEarlyFraudWarningEventHandler|null $early_fraud_warning_event_handler Optional early fraud warning handler.
	 * @return WooPaymentsEventIngestor
	 */
	private function create_ingestor( OrderPaymentLifecycleService $lifecycle_service, WooPaymentsApiClient $api_client, ?WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null ): WooPaymentsEventIngestor {
		return $this->build_event_ingestor(
			$lifecycle_service,
			$api_client,
			$this->create_dispute_event_handler( $api_client ),
			wc_get_container()->get( WooPaymentsRefundEventHandler::class ),
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
	 * @return WooPaymentsEarlyFraudWarningEventHandler
	 */
	private function create_recording_early_fraud_warning_handler( array &$sequence, bool $should_throw = false ): WooPaymentsEarlyFraudWarningEventHandler {
		$handler               = new class( $sequence ) extends WooPaymentsEarlyFraudWarningEventHandler {
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
			new class() extends WooPaymentsApiClient {},
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
			new class() extends WooPaymentsApiClient {}
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
			WooPaymentsApiClient::class,
			new class() extends WooPaymentsApiClient {
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
	 * local WPCOM forwarded) by pair key, ready to pass straight to `WooPaymentsEventIngestor::process()`.
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
	 * local WPCOM forwarded) by pair key, ready to pass straight to `WooPaymentsEventIngestor::process()`.
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
	 * @return WooPaymentsApiClient
	 */
	private function create_dispute_summary_api_client( array $summary = array() ): WooPaymentsApiClient {
		return new class( $summary ) extends WooPaymentsApiClient {
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
	 * the PaymentIntent's own field (Stripe API PaymentIntent object).
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
				'amount'         => 1234,
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
	 * Rebuild WooCommerce's cached email registry under the currently registered filters.
	 */
	private function reset_mailer_emails(): void {
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			WC()->mailer()->emails = array();
		}
	}
}
