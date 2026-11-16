<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlierAttemptCleanup;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use WC_Order;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the takeover cleanup: what the checkout of another payment method does to an earlier WooPayments attempt's
 * stored details.
 *
 * Expected values follow client 11.3.0's maybe_remove_payment_details_for_other_gateway() and its shutdown step
 * (includes/class-wc-payments-order-service.php:1270-1460) where the built-in matches it; the read of a stored paid status,
 * its notes and the wordings follow the decided design.
 */
class WooPaymentsEarlierAttemptCleanupTest extends WC_Unit_Test_Case {

	/**
	 * The five keys the cleanup removes (client 11.3.0 class-wc-payments-order-service.php:1313-1317).
	 */
	private const REMOVED_KEYS = array( '_stripe_customer_id', '_payment_method_id', '_intent_id', '_intention_status', '_wcpay_mode' );

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEarlierAttemptCleanup
	 */
	private $sut;

	/**
	 * Transport the platform client sends through.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $transport;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->transport           = new FakeHttpClient();
		$this->transport->response = self::platform_answer( 500, array( 'error' => array( 'code' => 'unexpected_request' ) ) );

		// The store runs in live mode, so a read in the order's test mode is told apart from the store's.
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_woocommerce_store_id' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( false );
		$account_service->method( 'get_woocommerce_store_id' )->willReturn( '' );
		$api_client = new Client();
		$api_client->init( $this->transport, $account_service, wc_get_container()->get( TransportLog::class ) );

		$this->sut = new class() extends WooPaymentsEarlierAttemptCleanup {
			/**
			 * No wait between lock claims in tests.
			 */
			protected function wait_before_lock_retry(): void {}
		};
		$this->sut->init(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			$api_client,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( WooPaymentsLogger::class )
		);
		$this->sut->register();
	}

	/**
	 * @testdox At the checkout of another payment method, an attempt waiting for authentication is listed, its five values removed and the takeover time stored; at shutdown its transaction ID is cleared and it is cancelled through the platform, with no fraud meta and no status change ($_dataName).
	 * @dataProvider provide_cancels
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:1290-1339 (list, delete, time, note) and :1420-1460 (clear, cancel,
	 * notes); the cancel goes straight to the platform, never through the operation service that would cancel the order and
	 * stamp the fraud meta. The wording says the customer checked out with another payment method, which is true for a
	 * bank transfer that has not been paid yet.
	 *
	 * @param array<string,mixed> $cancel_answer   The platform's answer to the cancel.
	 * @param bool                $lock_held       Whether a WooPayments operation holds the order payment lock at shutdown.
	 * @param string              $expected_note   The shutdown note.
	 * @param string              $expected_txn    The transaction ID after shutdown.
	 */
	public function test_takeover_of_an_attempt_waiting_for_authentication( array $cancel_answer, bool $lock_held, string $expected_note, string $expected_txn ): void {
		$order                     = StoredPaymentRecords::attempt_before_a_takeover( 'requires_action', 'pi_A', 'pi_A' );
		$this->transport->response = $cancel_answer;

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertSame( array( 'pi_A' ), $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertMatchesRegularExpression( '/^\d+$/', (string) $stored->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, true ) );
		$this->assertSame( 'pi_A', $stored->get_transaction_id(), 'The transaction ID waits for shutdown.' );
		$this->assertSame( 0, $this->transport->request_count, 'A requires_action status makes no read.' );
		$this->assertSame( 1, self::count_notes( $order, 'The payment details of an earlier WooPayments attempt (<code>pi_A</code>) were removed because the customer checked out with another payment method.' ) );
		$this->assertNotFalse( has_action( 'shutdown', array( $this->sut, 'finish_earlier_attempts' ) ) );

		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		$held_token = $lock_held ? $lock->claim( $order, $vocabulary, 'pi_other', 'refund' ) : null;
		try {
			$this->sut->finish_earlier_attempts();
		} finally {
			if ( null !== $held_token ) {
				$lock->release( $order, $vocabulary, $held_token );
			}
		}

		$finished = wc_get_order( $order->get_id() );
		$this->assertSame( $expected_txn, $finished->get_transaction_id() );
		$this->assertSame( array( 'POST /sites/123/wcpay/intentions/pi_A/cancel' ), $this->sent_requests() );
		$this->assertSame( 1, self::count_notes( $order, $expected_note ) );
		$this->assertSame( OrderStatus::PENDING, $finished->get_status() );
		$this->assertSame( '', (string) $finished->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( '', (string) $finished->get_meta( '_wcpay_fraud_outcome_status', true ) );
	}

	/**
	 * @return array<string,array{array<string,mixed>,bool,string,string}>
	 */
	public function provide_cancels(): array {
		$cancelled = self::platform_answer(
			200,
			array(
				'id'     => 'pi_A',
				'status' => 'canceled',
			)
		);
		$refused   = self::platform_answer(
			400,
			array(
				'error' => array(
					'code'    => 'payment_intent_unexpected_state',
					'message' => 'You cannot cancel this PaymentIntent because it has a status of succeeded.',
				),
			)
		);

		return array(
			'the cancel succeeds'                          => array( $cancelled, false, 'The earlier WooPayments payment <code>pi_A</code> was cancelled because the customer checked out with another payment method.', '' ),
			'the cancel is refused'                        => array( $refused, false, 'The earlier WooPayments payment <code>pi_A</code> could not be cancelled when the customer checked out with another payment method. Check that it was not charged.', '' ),
			'the lock is held at shutdown, cancel succeeds' => array( $cancelled, true, 'The earlier WooPayments payment <code>pi_A</code> was cancelled because the customer checked out with another payment method. The order&#039;s transaction ID was not cleared, because a WooPayments payment for this order was being recorded at that moment.', 'pi_A' ),
			'the lock is held at shutdown, cancel refused' => array( $refused, true, 'The earlier WooPayments payment <code>pi_A</code> could not be cancelled when the customer checked out with another payment method. Check that it was not charged. The order&#039;s transaction ID was not cleared, because a WooPayments payment for this order was being recorded at that moment.', 'pi_A' ),
		);
	}

	/**
	 * @testdox A takeover after a declined attempt removes its values and lists it, with no read and no cancel, and leaves a transaction ID that names an earlier attempt ($_dataName).
	 * @dataProvider provide_declined_takeovers
	 *
	 * A requires_payment_method status is not requires_action, so client 11.3.0 queues no cancel (:1294-1295), and a
	 * transaction ID that is not the stored intent is not cleared (:1296, :1420-1433). When an intent-less failure wrote that
	 * status over an open attempt, the built-in does not cancel it either; on the client the same sequence cancels, because
	 * its failed checkout writes no status. A transaction ID that names another payment intent is logged.
	 *
	 * @param string $transaction_id The order's transaction ID.
	 * @param int    $expected_logs  Lines with the reason that the transaction ID names another intent.
	 */
	public function test_takeover_after_a_decline( string $transaction_id, int $expected_logs ): void {
		$logger = RecordingWcLogger::install();
		$order  = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_B', $transaction_id );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$this->sut->finish_earlier_attempts();

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertSame( array( 'pi_B' ), $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertSame( $transaction_id, $stored->get_transaction_id() );
		$this->assertSame( array(), $this->sent_requests() );
		$this->assertFalse( has_action( 'shutdown', array( $this->sut, 'finish_earlier_attempts' ) ), 'Nothing is left for shutdown.' );
		$this->assertCount( $expected_logs, array_filter( $logger->contexts, static fn( array $context ): bool => 'takeover_transaction_id_names_another_intent' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * @return array<string,array{string,int}>
	 */
	public function provide_declined_takeovers(): array {
		return array(
			'no transaction ID'                           => array( '', 0 ),
			'the transaction ID names an earlier attempt' => array( 'pi_A0', 1 ),
		);
	}

	/**
	 * @testdox Only an order of another payment method that carries the mode and stores a paid status on a payment intent is read from the platform ($_dataName).
	 * @dataProvider provide_unread_orders
	 *
	 * Client 11.3.0 returns on a WooPayments method or an empty mode (:1276-1282); the built-in reads only a stored status in
	 * the authorized set on a `pi_`, and cleans up a setup intent, which never holds money, without a read.
	 *
	 * @param string $payment_method The order's payment method.
	 * @param string $mode           The stored mode.
	 * @param string $stored_status  The stored status.
	 * @param string $intent_id      The stored intent.
	 * @param bool   $cleaned        Whether the cleanup removes the values.
	 */
	public function test_read_guard( string $payment_method, string $mode, string $stored_status, string $intent_id, bool $cleaned ): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( $stored_status, $intent_id, '', '', OrderStatus::PENDING, $payment_method, $mode );
		$keys  = self::read_keys( $order );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 0, $this->transport->request_count );
		$this->assertSame( $cleaned ? array( '', '', '', '', '' ) : $keys, self::read_keys( $stored ) );
		$this->assertSame( $cleaned ? array( $intent_id ) : '', $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
	}

	/**
	 * @return array<string,array{string,string,string,string,bool}>
	 */
	public function provide_unread_orders(): array {
		return array(
			'a decline on another method'            => array( 'bacs', 'test', 'requires_payment_method', 'pi_A', true ),
			'no mode, a paid status'                 => array( 'bacs', '', 'succeeded', 'pi_A', false ),
			'a setup intent with a succeeded status' => array( 'bacs', 'test', 'succeeded', 'seti_S', true ),
			'a WooPayments payment method'           => array( 'woocommerce_payments', 'test', 'succeeded', 'pi_A', false ),
		);
	}

	/**
	 * @testdox A stored paid status on a payment intent is read once, in the order's mode within 5 seconds; a payment the read finds holding money is kept whole with one note in the state the read gives ($_dataName).
	 * @dataProvider provide_kept_payments
	 *
	 * Client 11.3.0 keeps everything on the stored status alone and says nothing (:1284-1288). The built-in reads the payment
	 * and keeps it when it holds money: no list entry, no removal, no transaction ID clear, no cancel and nothing for
	 * shutdown; the note says what the read found, and that a refund or dispute of it changes this order only while the
	 * transaction ID names it. A refunded or disputed payment still reads succeeded, so its charges decide that state. The
	 * answers are the recorded intents of Fixtures/platform-intention-basic-card.json (succeeded) and
	 * Fixtures/platform-manual-capture.json (requires_capture); the refunded and disputed ones set the recorded charge's
	 * `refunded` or `disputed` flag, a model of the platform's charge object, not a recording.
	 *
	 * @param array<string,mixed> $intent         The read's answer.
	 * @param string              $transaction_id The order's transaction ID.
	 * @param string              $expected_note  The note.
	 */
	public function test_kept_payment( array $intent, string $transaction_id, string $expected_note ): void {
		$intent_id                 = (string) $intent['id'];
		$order                     = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', $intent_id, $transaction_id, 'ch_A' );
		$keys                      = self::read_keys( $order );
		$this->transport->response = self::platform_answer( 200, $intent );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'GET /sites/123/wcpay/intentions/' . $intent_id . '?test_mode=1', 'GET /sites/123/wcpay/intentions/' . $intent_id . '?test_mode=1' ), $this->sent_requests(), 'One read per takeover, in the order\'s test mode though the store is live.' );
		$this->assertSame( array( 5, 5 ), array_column( $this->transport->requests, 'timeout' ) );
		$this->assertSame( $keys, self::read_keys( $stored ) );
		$this->assertSame( '', $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertSame( '', $stored->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, true ) );
		$this->assertSame( $transaction_id, $stored->get_transaction_id() );
		$this->assertFalse( has_action( 'shutdown', array( $this->sut, 'finish_earlier_attempts' ) ) );
		$this->assertSame( array( $expected_note ), self::takeover_notes( $order ), 'One note for the intent, however many takeovers.' );
	}

	/**
	 * @return array<string,array{array<string,mixed>,string,string}>
	 */
	public function provide_kept_payments(): array {
		$succeeded  = self::recorded_intent( 'platform-intention-basic-card.json', 'basic_card_usd_create_and_confirm' );
		$authorized = self::recorded_intent( 'platform-manual-capture.json', 'authorize_manual_capture' );
		$refunded   = $succeeded;
		$disputed   = $succeeded;

		$refunded['charges']['data'][0]['refunded'] = true;
		$disputed['charges']['data'][0]['disputed'] = true;

		$intent_id = (string) $succeeded['id'];
		$sentence  = ' This order&#039;s transaction ID names that payment at this moment, so a refund or a dispute of it changes this order.';
		$both      = ' If both payments end up made, one of them needs a refund.';
		$opening   = 'When the customer checked out with another payment method, the WooPayments payment <code>%s</code> recorded on this order ';

		return array(
			'succeeded, named by the transaction ID'      => array( $succeeded, $intent_id, sprintf( $opening, $intent_id ) . 'held money.' . $both . $sentence ),
			'succeeded, no transaction ID'                => array( $succeeded, '', sprintf( $opening, $intent_id ) . 'held money.' . $both ),
			'succeeded, the transaction ID names another' => array( $succeeded, 'pi_other', sprintf( $opening, $intent_id ) . 'held money.' . $both ),
			'authorized and not captured'                 => array( $authorized, (string) $authorized['id'], sprintf( $opening, (string) $authorized['id'] ) . 'was authorized and not captured.' . $both . $sentence ),
			'refunded in full'                            => array( $refunded, $intent_id, sprintf( $opening, $intent_id ) . 'had been refunded in full.' . $both . $sentence ),
			'disputed'                                    => array( $disputed, $intent_id, sprintf( $opening, $intent_id ) . 'was disputed.' . $both . $sentence ),
		);
	}

	/**
	 * @testdox A read of a stored paid status that fails keeps everything, logs it and adds one note that the payment could not be checked, however many takeovers ($_dataName).
	 * @dataProvider provide_failed_reads
	 *
	 * Client 11.3.0 makes no read and keeps the payment silently (:1284-1288); the built-in's failed read keeps it too, and
	 * takes the visible path without claiming the payment took money.
	 *
	 * @param mixed $answer The transport's answer to each read.
	 */
	public function test_failed_read_keeps_everything_with_one_note( $answer ): void {
		$logger                    = RecordingWcLogger::install();
		$order                     = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', 'pi_A', 'pi_A', 'ch_A' );
		$keys                      = self::read_keys( $order );
		$this->transport->response = $answer;

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 2, $this->transport->request_count, 'One read per takeover, with no retry.' );
		$this->assertSame( $keys, self::read_keys( $stored ) );
		$this->assertSame( '', $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertSame( 'pi_A', $stored->get_transaction_id() );
		$this->assertSame( array( 'The WooPayments payment <code>pi_A</code> could not be checked when the customer checked out with another payment method. Check whether it took money from the customer.' ), self::takeover_notes( $order ) );
		$this->assertCount( 2, array_filter( $logger->contexts, static fn( array $context ): bool => 'takeover_read_failed' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public function provide_failed_reads(): array {
		return array(
			'a platform error'    => array( self::platform_answer( 500, array( 'error' => array( 'code' => 'wcpay_server_error' ) ) ) ),
			'a transport timeout' => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' ) ),
		);
	}

	/**
	 * @testdox A read that finds the payment waiting for authentication runs the cleanup and cancels it at shutdown.
	 *
	 * The read's status, not the stored one, decides the cancel. The answer is the recorded intent of
	 * Fixtures/platform-intention-3ds-requires-action.json.
	 */
	public function test_read_finding_authentication_runs_the_cleanup_and_the_cancel(): void {
		$intent                     = self::recorded_intent( 'platform-intention-3ds-requires-action.json', 'new_card_requires_action' );
		$intent_id                  = (string) $intent['id'];
		$order                      = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', $intent_id, $intent_id );
		$this->transport->responses = array(
			self::platform_answer( 200, $intent ),
			self::platform_answer(
				200,
				array(
					'id'     => $intent_id,
					'status' => 'canceled',
				)
			),
		);

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$this->sut->finish_earlier_attempts();

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertSame( '', $stored->get_transaction_id() );
		$this->assertSame( array( 'GET /sites/123/wcpay/intentions/' . $intent_id . '?test_mode=1', 'POST /sites/123/wcpay/intentions/' . $intent_id . '/cancel' ), $this->sent_requests() );
	}

	/**
	 * @testdox When the stored intent changes between the read and the claim, the stored values decide with no second read, and the change is logged.
	 *
	 * The order is changed while the platform answers, as a webhook for another attempt would under its own claim.
	 */
	public function test_values_changed_after_the_read(): void {
		$logger                    = RecordingWcLogger::install();
		$order                     = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', 'pi_A', 'pi_A', 'ch_A' );
		$this->transport->response = self::platform_answer(
			200,
			array(
				'id'     => 'pi_A',
				'status' => 'succeeded',
			)
		);
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) use ( $order ) {
				$other = wc_get_order( $order->get_id() );
				$other->update_meta_data( '_intent_id', 'pi_B' );
				$other->update_meta_data( '_intention_status', 'requires_payment_method' );
				$other->save();

				return $response;
			}
		);

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 1, $this->transport->request_count );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertSame( array( 'pi_B' ), $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertSame( array(), self::takeover_notes( $order ), 'No kept-payment note for a read the claim no longer matches.' );
		$this->assertCount( 1, array_filter( $logger->contexts, static fn( array $context ): bool => 'takeover_values_changed' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * @testdox When a WooPayments operation holds the order payment lock at the checkout, nothing is removed, the refusal is logged and the order says where to check ($_dataName).
	 * @dataProvider provide_lock_refusals
	 *
	 * @param string $intent_id     The stored intent.
	 * @param string $expected_note The note.
	 */
	public function test_lock_refused_at_the_checkout( string $intent_id, string $expected_note ): void {
		$logger     = RecordingWcLogger::install();
		$order      = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', $intent_id );
		$keys       = self::read_keys( $order );
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		$token      = $lock->claim( $order, $vocabulary, 'pi_other', 'payment status update' );
		try {
			do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		} finally {
			$lock->release( $order, $vocabulary, (string) $token );
		}

		$this->assertSame( $keys, self::read_keys( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 1, self::count_notes( $order, $expected_note ) );
		$this->assertCount( 1, array_filter( $logger->contexts, static fn( array $context ): bool => 'takeover_cleanup_lock_refused' === ( $context['reason'] ?? '' ) && 'payment status update' === ( $context['holder_operation'] ?? '' ) ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function provide_lock_refusals(): array {
		return array(
			'with a stored intent'  => array( 'pi_A', 'The payment details of an earlier WooPayments attempt (<code>pi_A</code>) were not removed when the customer checked out with another payment method, because a WooPayments payment for this order was being recorded at that moment. Before fulfilling the order, check its payment details or the WooPayments transaction for <code>pi_A</code>.' ),
			'with no stored intent' => array( '', 'The payment details of an earlier WooPayments attempt were not removed when the customer checked out with another payment method, because a WooPayments payment for this order was being recorded at that moment. Before fulfilling the order, check its payment details or its WooPayments transactions.' ),
		);
	}

	/**
	 * @testdox A merchant's change of a WooPayments-paid order's payment method in admin runs no cleanup, so its payment stays the order's own.
	 *
	 * No checkout hook fires on an admin save, so the values stay and a refund of the payment, named by the transaction ID,
	 * is the order's own (EventOrderResolver::is_own_payment_for_event()).
	 */
	public function test_admin_payment_method_change_runs_no_cleanup(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', 'pi_A', 'pi_A', 'ch_A', OrderStatus::PROCESSING, WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$keys  = self::read_keys( $order );

		$order->set_payment_method( 'bacs' );
		$order->save();
		$order->update_status( OrderStatus::COMPLETED );

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( $keys, self::read_keys( $stored ) );
		$this->assertSame( 0, $this->transport->request_count );
		$this->assertTrue(
			wc_get_container()->get( EventOrderResolver::class )->is_own_payment_for_event(
				$stored,
				array(
					'id'             => 're_A',
					'payment_intent' => 'pi_A',
				),
				'ch_A'
			)
		);
	}

	/**
	 * @testdox The list and the takeover time are stored in client 11.3.0's forms, so its predicate reads them after a return to the WooPayments extension.
	 *
	 * The client appends string intent IDs to an array and stores `(string) time()` (:1304-1321); its predicate reads the
	 * list with is_array() and a strict in_array() and the time as an int (:1366-1380), copied below as the reader.
	 */
	public function test_list_and_time_in_the_clients_stored_forms(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_a' );
		$now   = time();

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$stored = wc_get_order( $order->get_id() );
		$list   = $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true );
		$time   = $stored->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, true );
		$this->assertSame( array( 'pi_a' ), $list );
		$this->assertIsString( $time );
		$this->assertMatchesRegularExpression( '/^\d+$/', $time );
		$this->assertLessThanOrEqual( 1, abs( (int) $time - $now ) );

		$client_predicate = static function ( WC_Order $order, string $intent_id, int $intent_created_at ): bool {
			$superseded_intent_ids = $order->get_meta( '_wcpay_superseded_intent_ids' );
			if ( is_array( $superseded_intent_ids ) && in_array( $intent_id, $superseded_intent_ids, true ) ) {
				return true;
			}
			if ( '' !== (string) $order->get_meta( '_wcpay_mode' ) ) {
				return false;
			}
			$taken_over_at = (int) $order->get_meta( '_wcpay_payment_details_removed' );

			return 0 < $taken_over_at && 0 < $intent_created_at && $intent_created_at < $taken_over_at;
		};
		$this->assertTrue( $client_predicate( $stored, 'pi_a', 0 ) );
		$this->assertTrue( $client_predicate( $stored, 'pi_older', (int) $time - 1 ) );
		$this->assertFalse( $client_predicate( $stored, 'pi_later', (int) $time + 1 ) );
	}

	/**
	 * @testdox The cleanup writes on a fresh copy: an unsaved prop and meta change on the checkout's object are not saved, the object keeps them, and no order save fires.
	 *
	 * On HPOS a meta change on an order with no pending changes saves the whole order (OrdersTableDataStore::after_meta_change()),
	 * which would write what the running checkout has not saved yet; the cleanup turns that save off as core's sync does.
	 */
	public function test_cleanup_saves_none_of_the_checkouts_unsaved_changes(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );
		// An order last modified before this request, as a reused order is, so HPOS's save after a meta change applies.
		$order->set_date_modified( time() - HOUR_IN_SECONDS );
		$order->save();
		$order->set_customer_note( 'unsaved note' );
		$order->update_meta_data( '_earlier_callback', 'unsaved' );
		$saves = did_action( 'woocommerce_update_order' );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertSame( $saves, did_action( 'woocommerce_update_order' ), 'The cleanup fires no order save.' );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertSame( array( 'pi_A' ), $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true ) );
		$this->assertSame( '', $stored->get_customer_note() );
		$this->assertSame( '', $stored->get_meta( '_earlier_callback', true ) );
		$this->assertSame( 'unsaved note', $order->get_customer_note() );
		$this->assertSame( 'unsaved', $order->get_meta( '_earlier_callback', true ) );
	}

	/**
	 * @testdox On the Store API hook, a callback at 100 and the payment step's listener read none of the five values from the object the hook passed or from a fresh load.
	 *
	 * WooCommerce Subscriptions creates the subscription at 100 from a fresh load (class-wc-subscriptions-checkout.php:24,
	 * :79, :191); the Store API payment step hands the route's own object to its listeners (CheckoutTrait::process_payment()).
	 */
	public function test_store_api_readers_see_the_cleaned_values(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );
		$read  = array();
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			static function ( $passed ) use ( &$read ) {
				$read['passed'] = self::read_keys( $passed );
				$read['loaded'] = self::read_keys( wc_get_order( $passed ) );
			},
			100
		);
		add_action(
			'woocommerce_rest_checkout_process_payment_with_context',
			static function ( $context ) use ( &$read ) {
				$read['context'] = self::read_keys( $context->order );
			}
		);

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$context = new PaymentContext();
		$context->set_order( $order );
		do_action( 'woocommerce_rest_checkout_process_payment_with_context', $context, new PaymentResult() );

		$empty = array( '', '', '', '', '' );
		$this->assertSame(
			array(
				'passed'  => $empty,
				'loaded'  => $empty,
				'context' => $empty,
			),
			$read
		);
	}

	/**
	 * @testdox On the classic checkout hook, a later callback that takes core's order object reads none of the five values.
	 */
	public function test_classic_hook_reader_sees_the_cleaned_values(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );
		$read  = null;
		add_action(
			'woocommerce_checkout_order_processed',
			static function ( $order_id, $posted_data, $passed ) use ( &$read ) {
				unset( $order_id, $posted_data );
				$read = self::read_keys( $passed );
			},
			20,
			3
		);

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$this->assertSame( array( '', '', '', '', '' ), $read );
	}

	/**
	 * @testdox When the checkout later saves the object the hook passed, its pending deletions write nothing back: the five values stay gone, the list and the time have one row each, and the checkout's change is saved.
	 */
	public function test_later_save_of_the_passed_object(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$order->set_customer_note( 'saved by the checkout' );
		$order->save();

		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( $stored ) );
		$this->assertCount( 1, $stored->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, false ) );
		$this->assertCount( 1, $stored->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, false ) );
		$this->assertSame( 'saved by the checkout', $stored->get_customer_note() );
	}

	/**
	 * @testdox On the classic pay page, the result of another gateway's successful payment cleans up the order once and comes back unchanged: $_dataName.
	 * @dataProvider provide_pay_page_results
	 *
	 * WC_Form_Handler::pay_action() fires the before-pay action, saves the chosen method, runs the gateway and, on success
	 * only, applies woocommerce_payment_successful_result before it redirects (class-wc-form-handler.php:761, :794-807).
	 * Client 11.3.0 hooks neither (class-wc-payments-order-service.php:345-346); the built-in cleans up there too. The
	 * callback never reads the result.
	 *
	 * @param mixed $result The gateway's result.
	 */
	public function test_pay_page_success_cleans_up_once_and_returns_the_result( $result ): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );

		do_action( 'woocommerce_before_pay_action', $order );
		$returned = apply_filters( 'woocommerce_payment_successful_result', $result, $order->get_id() );

		$this->assertSame( $result, $returned );
		$this->assertSame( array( '', '', '', '', '' ), self::read_keys( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 1, self::count_notes( $order, 'The payment details of an earlier WooPayments attempt (<code>pi_A</code>) were removed because the customer checked out with another payment method.' ) );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public function provide_pay_page_results(): array {
		return array(
			'a redirect result'            => array(
				array(
					'result'   => 'success',
					'redirect' => 'https://example.org/checkout/order-received/1/',
				),
			),
			'a value that is not an array' => array( 'unexpected' ),
		);
	}

	/**
	 * @testdox A pay page whose gateway fails or throws reaches no successful result, so nothing is cleaned up.
	 *
	 * The pay action fires woocommerce_after_pay_action instead (class-wc-form-handler.php:823).
	 */
	public function test_pay_page_failure_cleans_up_nothing(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'requires_payment_method', 'pi_A' );
		$keys  = self::read_keys( $order );

		do_action( 'woocommerce_before_pay_action', $order );
		do_action( 'woocommerce_after_pay_action', $order );

		$this->assertSame( $keys, self::read_keys( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * @testdox On the classic checkout, which applies the same result filter after its hook, only the hook cleans up.
	 *
	 * WC_Checkout::process_order_payment() applies woocommerce_payment_successful_result after the gateway's success
	 * (class-wc-checkout.php:1467) without the before-pay action.
	 */
	public function test_classic_checkout_result_makes_no_second_cleanup(): void {
		$order = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', 'pi_A', 'pi_A', 'ch_A' );
		$keys  = self::read_keys( $order );

		apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() );

		$this->assertSame( 0, $this->transport->request_count, 'The filter makes no read.' );
		$this->assertSame( $keys, self::read_keys( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( array(), self::takeover_notes( $order ) );
	}

	/**
	 * @testdox On the pay page, a WooPayments success applied while the other gateway's payment ran is kept, with one read and the takeover note.
	 *
	 * The stored intent's success completes the order as a WooPayments payment while the pay action runs the other gateway
	 * (the method is saved first, class-wc-form-handler.php:794-795); the filter then reads the stored payment and keeps it.
	 * The read answers the recorded intent of Fixtures/platform-intention-basic-card.json.
	 */
	public function test_pay_page_keeps_a_payment_that_succeeded_meanwhile(): void {
		$intent                    = self::recorded_intent( 'platform-intention-basic-card.json', 'basic_card_usd_create_and_confirm' );
		$intent_id                 = (string) $intent['id'];
		$order                     = StoredPaymentRecords::attempt_before_a_takeover( 'succeeded', $intent_id, $intent_id, 'ch_A', OrderStatus::PROCESSING );
		$keys                      = self::read_keys( $order );
		$this->transport->response = self::platform_answer( 200, $intent );

		do_action( 'woocommerce_before_pay_action', $order );
		apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() );

		$this->assertSame( 1, $this->transport->request_count );
		$this->assertSame( $keys, self::read_keys( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( OrderStatus::PROCESSING, wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, self::takeover_notes( $order ) );
	}

	/**
	 * Read the five keys the cleanup removes.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,string>
	 */
	private static function read_keys( WC_Order $order ): array {
		return array_map( static fn( string $key ): string => (string) $order->get_meta( $key, true ), self::REMOVED_KEYS );
	}

	/**
	 * Count the order's notes that read exactly as given.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  Note text.
	 * @return int
	 */
	private static function count_notes( WC_Order $order, string $note ): int {
		return count( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
	}

	/**
	 * Get the order's kept-payment and failed-read notes, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,string>
	 */
	private static function takeover_notes( WC_Order $order ): array {
		$notes = array_values(
			array_filter(
				array_map( static fn( $order_note ): string => (string) $order_note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
				static fn( string $content ): bool => 0 === strpos( $content, 'When the customer checked out' ) || 0 === strpos( $content, 'The WooPayments payment <code>' )
			)
		);

		return array_reverse( $notes );
	}

	/**
	 * Get each request sent to the platform as method and path.
	 *
	 * @return array<int,string>
	 */
	private function sent_requests(): array {
		return array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $this->transport->requests );
	}

	/**
	 * Build a platform answer for the fake transport.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded body.
	 * @return array<string,mixed>
	 */
	private static function platform_answer( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * Read a recorded platform intent: the response body of an entry in a fixture file.
	 *
	 * @param string $file Fixture file in Fixtures/.
	 * @param string $pair The entry's pair name.
	 * @return array<string,mixed>
	 */
	private static function recorded_intent( string $file, string $pair ): array {
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/' . $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		foreach ( $recording['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return $entry['response']['body'];
			}
		}

		return array();
	}
}
