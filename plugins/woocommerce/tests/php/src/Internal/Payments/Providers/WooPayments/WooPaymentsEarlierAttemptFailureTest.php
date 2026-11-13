<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAjaxController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use WC_Helper_Product;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * What a failed payment attempt leaves of the order's other attempts.
 *
 * A shopper pays with a saved card, the first attempt is declined, and a second attempt with the same card is attached to
 * the order. The first attempt's payment_intent.payment_failed event then arrives. A later attempt can also fail at
 * checkout over an earlier attempt the order still stores. These tests drive the production wiring, with only the
 * platform's transport replaced by recorded responses, and assert the order's status, transaction ID, stored intent and
 * notes after every attempt.
 */
class WooPaymentsEarlierAttemptFailureTest extends WC_Unit_Test_Case {

	/**
	 * Fixture folder.
	 */
	private const FIXTURES = __DIR__ . '/Fixtures/';

	/**
	 * The saved card both attempts pay with: the payment method of the recorded saved-card 3D Secure intent
	 * (Fixtures/platform-intention-3ds-requires-action.json, pair saved_payment_method_requires_action).
	 */
	private const SAVED_CARD = 'pm_1Tt7AGBzWlxcwgpPXw5RqQtT';

	/**
	 * Transport the platform client sends through, answering with the queued recorded responses.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $transport;

	/**
	 * Called once while the next checkout charge is sent, or null.
	 *
	 * @var callable|null
	 */
	private $while_charging = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->replace_platform();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['theorder'] );
		as_unschedule_all_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * @testdox The failure of a declined earlier attempt, arriving once a newer attempt $_dataName, changes nothing, and the newer attempt then completes its payment.
	 *
	 * Expected values come from client 11.3.0, except the late failure's row, which is stronger than the client. The
	 * checkout decline writes the declined intent and its note, and no transaction ID (class-wc-payment-gateway-wcpay.php:1610-1614,
	 * :1690-1691); it also stores the declined intent's status. The newer attempt's attach writes its transaction ID and intent (class-wc-payments-order-service.php:1818-1837,
	 * :1875-1876), and its writer adds the started note or holds the order (:500-508). The client's failed handler would fail
	 * this order (class-wc-payments-webhook-processing-service.php:511-560); here an order that stores another intent is left
	 * as it is, as its authorization handler leaves it (:477-496). The order-status callback then completes the payment
	 * (class-wc-payment-gateway-wcpay.php:4611-4630), and a status change to Completed captures the stored authorization
	 * (class-wc-payments-order-service.php:2381-2429).
	 *
	 * Responses: the decline is REC-1's generic_decline (Fixtures/platform-intention-declines.json), its intent's payment
	 * method set to the saved card, its amount to the order's and its metadata to the order's, as checkout sets them. The newer
	 * attempt is the recorded saved-card 3D Secure intent, or the recorded manual-capture authorization and capture
	 * (Fixtures/platform-manual-capture.json), and the recorded completed 3D Secure challenge
	 * (Fixtures/platform-3ds-challenge-results.json, classic_checkout_challenge_completed), each with its payment method
	 * and metadata set the same way. The failure is the recorded card decline delivery
	 * (Fixtures/platform-payment-intent-events.json, card_declined_failed) carrying the declined intent as its object.
	 *
	 * @dataProvider provider_newer_attempts
	 *
	 * @param bool   $authorized      Whether the newer attempt is authorized for a later capture rather than awaiting authentication.
	 * @param string $waiting_status  Order status while the newer attempt waits.
	 * @param string $intention_status Intent status the order stores while the newer attempt waits.
	 */
	public function test_late_failure_of_an_earlier_attempt_changes_nothing( bool $authorized, string $waiting_status, string $intention_status ): void {
		$order   = $this->create_order();
		$earlier = $this->declined_intent( $order );
		$newer   = $authorized ? $this->recorded_authorization( $order ) : $this->recorded_intent( 'platform-intention-3ds-requires-action.json', 'saved_payment_method_requires_action', $order );

		$this->decline_first_attempt( $order, $earlier );
		$this->start_newer_attempt( $order, $newer );

		$this->assert_order( $order, $waiting_status, $newer['id'], $newer['id'], $intention_status );
		$notes_before = $this->get_note_contents( $order );
		$logger       = RecordingWcLogger::install();

		wc_get_container()->get( EventIngestor::class )->process( $this->failure_delivery( $earlier ) );

		$this->assert_order( $order, $waiting_status, $newer['id'], $newer['id'], $intention_status );
		$this->assertSame( $notes_before, $this->get_note_contents( $order ), 'The late failure adds no note.' );
		$this->assertSame( 1, $this->count_ignored_failure_lines( $logger, $order, $earlier['id'], $newer['id'] ) );

		if ( $authorized ) {
			$this->transport->responses = array( $this->respond( $this->recorded_intent( 'platform-manual-capture.json', 'capture_full_amount', $order ) ) );
			wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->register();
			$this->reload( $order )->update_status( OrderStatus::COMPLETED );

			$this->assert_order( $order, OrderStatus::COMPLETED, $newer['id'], $newer['id'], 'succeeded' );
			$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		} else {
			$this->transport->responses = array( $this->respond( $this->recorded_intent( 'platform-3ds-challenge-results.json', 'classic_checkout_challenge_completed', $order, $newer['id'] ) ) );

			$response = $this->send_order_status_callback( $order, $newer['id'] );

			$this->assertSame( 200, $response['status_code'] ?? null );
			$this->assert_order( $order, OrderStatus::PROCESSING, $newer['id'], $newer['id'], 'succeeded' );
			$this->assertSame( 1, $this->count_notes_containing( $order, 'was processed using WooPayments' ) );
		}
		$this->assertSame( 1, $this->count_notes_containing( $order, '<strong>failed</strong> to complete' ), "The earlier attempt's decline note stays the only failure note." );
		$this->assertSame( 0, $this->count_notes_containing( $order, '<strong>failed</strong> using' ) );
	}

	/**
	 * Newer attempts the order waits on when the earlier attempt's failure arrives.
	 *
	 * @return array<string,array{bool,string,string}>
	 */
	public function provider_newer_attempts(): array {
		return array(
			'awaits authentication' => array( false, OrderStatus::PENDING, 'requires_action' ),
			'is authorized'         => array( true, OrderStatus::ON_HOLD, 'requires_capture' ),
		);
	}

	/**
	 * @testdox The failure of a declined earlier attempt, refused while the newer attempt's checkout holds the order, changes nothing when its scheduled retry runs after the newer attempt is attached.
	 *
	 * The webhook route keeps a refused delivery and schedules its retry (WebhookReliabilityService::retry_failed_event()).
	 * Responses and expected values as in test_late_failure_of_an_earlier_attempt_changes_nothing().
	 */
	public function test_retried_failure_of_an_earlier_attempt_changes_nothing(): void {
		$order    = $this->create_order();
		$earlier  = $this->declined_intent( $order );
		$newer    = $this->recorded_intent( 'platform-intention-3ds-requires-action.json', 'saved_payment_method_requires_action', $order );
		$delivery = $this->failure_delivery( $earlier );
		$refusal  = null;

		$this->decline_first_attempt( $order, $earlier );
		$this->while_charging = static function () use ( $delivery, &$refusal ): void {
			$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
			$request->set_body_params( $delivery );
			$refusal = wc_get_container()->get( WebhookRestController::class )->handle_webhook( $request )->get_status();
		};
		$this->start_newer_attempt( $order, $newer );

		$this->assertSame( 500, $refusal, "The earlier attempt's failure is refused while the newer attempt's checkout holds the order." );
		$this->assert_order( $order, OrderStatus::PENDING, $newer['id'], $newer['id'], 'requires_action' );
		$notes_before = $this->get_note_contents( $order );
		$retries      = as_get_scheduled_actions(
			array(
				'hook'   => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
				'args'   => array( 'event_id' => $delivery['id'] ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $retries );
		$logger = RecordingWcLogger::install();
		if ( false === has_action( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ) ) {
			wc_get_container()->get( WebhookReliabilityService::class )->register();
		}

		\ActionScheduler::runner()->process_action( (int) reset( $retries ) );

		$this->assert_order( $order, OrderStatus::PENDING, $newer['id'], $newer['id'], 'requires_action' );
		$this->assertSame( $notes_before, $this->get_note_contents( $order ), 'The retried failure adds no note.' );
		$this->assertSame( 1, $this->count_ignored_failure_lines( $logger, $order, $earlier['id'], $newer['id'] ) );

		$this->transport->responses = array( $this->respond( $this->recorded_intent( 'platform-3ds-challenge-results.json', 'classic_checkout_challenge_completed', $order, $newer['id'] ) ) );

		$this->assertSame( 200, $this->send_order_status_callback( $order, $newer['id'] )['status_code'] ?? null );
		$this->assert_order( $order, OrderStatus::PROCESSING, $newer['id'], $newer['id'], 'succeeded' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was processed using WooPayments' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, '<strong>failed</strong> to complete' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, '<strong>failed</strong> using' ) );
	}

	/**
	 * @testdox When $_dataName, the order stores the status only with the intent it describes, and a status change to Completed acts on that pair.
	 *
	 * Client 11.3.0's checkout catch fails the order and stores the intent a decline names, and writes no intent status
	 * (class-wc-payment-gateway-wcpay.php:1604-1614), so a failure that names no intent leaves the order the intent and
	 * status an earlier attempt stored. A decline that names an intent stores requires_payment_method beside it, the status
	 * that intent has. The order screen offers capture and cancel only for a stored requires_capture
	 * (class-wc-payment-gateway-wcpay.php:4307), and a status change to Completed acts on the stored pair: nothing without
	 * a stored intent, the stored authorization when the order stores requires_capture, and otherwise a read of the stored
	 * intent (class-wc-payments-order-service.php:2327-2345, :2381-2429).
	 *
	 * Responses: the decline is REC-1's generic_decline (Fixtures/platform-intention-declines.json); the earlier attempt is
	 * the recorded saved-card 3D Secure intent (Fixtures/platform-intention-3ds-requires-action.json) or the recorded
	 * manual-capture authorization and capture (Fixtures/platform-manual-capture.json), each set to the order as checkout
	 * sets it. The platform failure is the platform's 502 with a top-level code, which names no intent (client 11.3.0
	 * includes/wc-payment-api/class-wc-payments-api-client.php:2880-2905); the transport failure is a WordPress HTTP error on
	 * every send of the charge and its retries; the extension error is an exception from a wcpay_metadata_from_order callback.
	 *
	 * @dataProvider provider_failed_attempts
	 *
	 * @param string $earlier          The earlier attempt the order stores: '', 'started' or 'authorized'.
	 * @param string $failure          How the attempt fails: 'decline', 'platform failure', 'transport failure' or 'extension error'.
	 * @param string $stored_intent    Which intent the order stores after the failure: '', 'earlier' or 'declined'.
	 * @param string $stored_status    Intent status the order stores after the failure, or '' for none at all.
	 * @param bool   $capture_actions  Whether the order screen offers capture and cancel after the failure.
	 * @param string $status_change    What the change to Completed does: 'nothing', 'reads' (one read of the stored intent) or 'captures'.
	 */
	public function test_failed_attempt_stores_the_status_only_with_the_intent_it_describes( string $earlier, string $failure, string $stored_intent, string $stored_status, bool $capture_actions, string $status_change ): void {
		$order    = $this->create_order();
		$declined = $this->declined_intent( $order );
		$attempts = array(
			''           => array(),
			'started'    => $this->recorded_intent( 'platform-intention-3ds-requires-action.json', 'saved_payment_method_requires_action', $order ),
			'authorized' => $this->recorded_authorization( $order ),
		);
		$intents  = array(
			''         => '',
			'earlier'  => $attempts[ $earlier ]['id'] ?? '',
			'declined' => $declined['id'],
		);
		if ( '' !== $earlier ) {
			$this->transport->responses = array( $this->respond( $attempts[ $earlier ] ) );
			$this->checkout( $order );
			$this->reload( $order )->update_status( OrderStatus::PENDING );
		}

		$this->fail_attempt( $order, $failure, $declined );

		$failed = $this->reload( $order );
		$this->assert_order( $failed, OrderStatus::FAILED, $intents['earlier'], $intents[ $stored_intent ], $stored_status );
		$this->assertSame( '' !== $stored_status, $failed->meta_exists( '_intention_status' ), 'The order stores an intent status only with the intent it describes.' );
		$this->assertSame( 1, $this->count_notes_containing( $failed, '<strong>failed</strong> to complete' ) );
		if ( 'decline' === $failure ) {
			$this->assertSame( 'allow', $failed->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		}

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->register();
		$GLOBALS['theorder'] = $failed;
		$actions             = apply_filters( 'woocommerce_order_actions', array(), $failed );
		$this->assertSame( $capture_actions, isset( $actions['capture_charge'], $actions['cancel_authorization'] ), 'The order screen offers capture and cancel only for a stored authorization.' );

		$answers                    = array(
			'nothing'  => array(),
			'reads'    => array( 'declined' === $stored_intent ? $declined : ( $attempts[ $earlier ] ?? array() ) ),
			'captures' => array( $this->recorded_intent( 'platform-manual-capture.json', 'capture_full_amount', $order ) ),
		);
		$this->transport->responses = array_map( fn( array $body ): array => $this->respond( $body ), $answers[ $status_change ] );
		$sent_before                = count( $this->transport->requests );

		$this->reload( $order )->update_status( OrderStatus::COMPLETED );

		$expected_requests = array(
			'nothing'  => array(),
			'reads'    => array( 'GET intentions/' . $intents[ $stored_intent ] ),
			'captures' => array( 'POST intentions/' . $intents['earlier'] . '/capture' ),
		);
		$this->assertSame( $expected_requests[ $status_change ], $this->intent_requests_since( $sent_before ) );
		$this->assert_order( $order, OrderStatus::COMPLETED, $intents['earlier'], $intents[ $stored_intent ], 'captures' === $status_change ? 'succeeded' : $stored_status );
		$this->assertSame( 'captures' === $status_change ? 1 : 0, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
	}

	/**
	 * Failed attempts, the earlier attempt the order stores, and what the order keeps.
	 *
	 * @return array<string,array{string,string,string,string,bool,string}>
	 */
	public function provider_failed_attempts(): array {
		return array(
			'a fresh order is declined'              => array( '', 'decline', 'declined', 'requires_payment_method', false, 'reads' ),
			'an authorized order is declined'        => array( 'authorized', 'decline', 'declined', 'requires_payment_method', false, 'reads' ),
			'a fresh order meets a platform failure' => array( '', 'platform failure', '', '', false, 'nothing' ),
			'a started authentication meets a platform failure' => array( 'started', 'platform failure', 'earlier', 'requires_action', false, 'reads' ),
			'a started authentication meets an extension error' => array( 'started', 'extension error', 'earlier', 'requires_action', false, 'reads' ),
			'an authorized order meets a transport failure' => array( 'authorized', 'transport failure', 'earlier', 'requires_capture', true, 'captures' ),
		);
	}

	/**
	 * @testdox A resubmission refused because $_dataName keeps the order's status and the intent and status an earlier attempt stored.
	 *
	 * The order stores a started 3D Secure attempt; a resubmission with the same card gets the platform's 502, so its
	 * outcome is unknown and its key is kept; the next resubmission, with a new card, is refused under the kept key and the
	 * order's intents are looked up. Neither refusal names an intent the order should store, so neither writes an intent
	 * status, as client 11.3.0's checkout catch writes none (class-wc-payment-gateway-wcpay.php:1604-1614). The order is
	 * Pending again before the last resubmission, as a Store API checkout leaves it.
	 *
	 * Responses: the earlier attempt is the recorded saved-card 3D Secure intent
	 * (Fixtures/platform-intention-3ds-requires-action.json); the platform failure is the platform's 502 with a top-level
	 * code; the refused key is Stripe's idempotency_error passed through (https://docs.stripe.com/api/idempotent_requests).
	 * The lookup fails with Stripe's api_error, or lists the recorded basic card payment
	 * (Fixtures/platform-intention-basic-card.json) set to the order, with its charge disputed.
	 *
	 * @dataProvider provider_refused_resubmissions
	 *
	 * @param bool $disputed Whether the lookup finds the earlier payment disputed, rather than failing.
	 */
	public function test_refused_resubmission_keeps_the_earlier_attempts_intent_and_status( bool $disputed ): void {
		$order   = $this->create_order();
		$started = $this->recorded_intent( 'platform-intention-3ds-requires-action.json', 'saved_payment_method_requires_action', $order );

		$this->transport->responses = array( $this->respond( $started ) );
		$this->checkout( $order );
		$this->fail_attempt( $order, 'platform failure', array() );
		$this->reload( $order )->update_status( OrderStatus::PENDING );

		$paid                                   = $this->recorded_intent( 'platform-intention-basic-card.json', 'basic_card_usd_create_and_confirm', $order );
		$paid['created']                        = time();
		$paid['charges']['data'][0]['disputed'] = true;
		$lookup                                 = $disputed
			? $this->respond(
				array(
					'object'   => 'list',
					'data'     => array( $paid ),
					'has_more' => false,
				)
			)
			: $this->respond(
				array(
					'error' => array(
						'type'    => 'api_error',
						'message' => 'An unknown error occurred',
					),
				),
				500
			);
		$this->transport->responses             = array(
			$this->respond(
				array(
					'error' => array(
						'type'    => 'idempotency_error',
						'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.',
					),
				),
				400
			),
			$lookup,
		);
		$sent_before                            = count( $this->transport->requests );

		$this->checkout( $order, 'pm_new_card' );

		$this->assertSame( array( 'POST intentions', 'GET intentions' ), $this->intent_requests_since( $sent_before ), 'The kept key is refused and the order\'s intents are looked up; the new card is not charged.' );
		$this->assert_order( $order, OrderStatus::PENDING, $started['id'], $started['id'], 'requires_action' );
		$this->assertSame( $disputed ? 1 : 0, $this->count_notes_containing( $order, 'is disputed' ) );
	}

	/**
	 * Lookups that refuse the resubmission.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provider_refused_resubmissions(): array {
		return array(
			'the lookup of the earlier request fails' => array( false ),
			'the earlier payment is disputed'         => array( true ),
		);
	}

	/**
	 * Run the charge callback once, as the platform client sends the next checkout charge.
	 */
	public function run_while_charging(): void {
		if ( null !== $this->while_charging ) {
			$callback             = $this->while_charging;
			$this->while_charging = null;
			$callback();
		}
	}

	/**
	 * Replace the platform client with one that sends through a fake transport, and let the built-in WooPayments own the store.
	 */
	private function replace_platform(): void {
		$account         = json_decode( (string) file_get_contents( self::FIXTURES . 'platform-test-drive-account.json' ), true )['account']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		$this->transport = new class( $this->respond( $account ) ) extends FakeHttpClient {
			/**
			 * The account response.
			 *
			 * @var array<string,mixed>
			 */
			private array $account;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account The account response.
			 */
			public function __construct( array $account ) {
				$this->account = $account;
			}

			/**
			 * Answer an intent request with the next queued response, and an account request with the recorded account.
			 *
			 * Other requests, such as the payment method details lookup, get no response.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           WPCOM path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Request body.
			 * @param int         $timeout        Request timeout.
			 * @param bool        $use_user_token Whether to sign with the connection-owner user token.
			 * @param bool        $blocking       Whether the request should block for the response.
			 * @return mixed
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				if ( false !== strpos( $path, '/wcpay/intentions' ) ) {
					return parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
				}

				return false !== strpos( $path, '/wcpay/accounts' ) ? $this->account : null;
			}
		};
		$test            = $this;
		$api             = new class( $test ) extends Client {
			/**
			 * The test case.
			 *
			 * @var WooPaymentsEarlierAttemptFailureTest
			 */
			private WooPaymentsEarlierAttemptFailureTest $test;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsEarlierAttemptFailureTest $test Test case.
			 */
			public function __construct( WooPaymentsEarlierAttemptFailureTest $test ) {
				$this->test = $test;
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
			 * Create the shopper's platform customer.
			 *
			 * @param array<string,mixed> $customer_data Customer data.
			 * @return string
			 */
			public function create_customer( array $customer_data ): string {
				unset( $customer_data );
				return 'cus_shopper';
			}

			/**
			 * Send a checkout charge, after the test's charge callback.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$this->test->run_while_charging();

				return parent::create_and_confirm_payment_intention( $request_data, $idempotency_key );
			}

			/**
			 * Retry a transport failure without waiting.
			 *
			 * @param int $backoff_microseconds Base retry backoff in microseconds.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		wc_get_container()->replace( Client::class, $api );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->reset_container_resolutions();
		$api->init( $this->transport, wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( TransportLog::class ) );
	}

	/**
	 * Run the first checkout attempt, which the platform declines, and check what it leaves.
	 *
	 * @param WC_Order            $order   Order.
	 * @param array<string,mixed> $earlier The declined intent.
	 */
	private function decline_first_attempt( WC_Order $order, array $earlier ): void {
		$decline = $this->recorded_entry( 'platform-intention-declines.json', 'generic_decline' );

		$decline['body']['error']['payment_intent'] = $earlier;

		$this->transport->responses = array( $this->respond( $decline['body'], $decline['http_status'] ) );

		$this->checkout( $order );

		$this->assert_order( $order, OrderStatus::FAILED, '', $earlier['id'], 'requires_payment_method' );
		$this->assertSame( 1, $this->count_notes_containing( $order, '<strong>failed</strong> to complete' ) );
	}

	/**
	 * Move the order back to Pending, as WooCommerce does before a new payment, and run the newer checkout attempt.
	 *
	 * @param WC_Order            $order Order.
	 * @param array<string,mixed> $newer The intent the platform returns.
	 */
	private function start_newer_attempt( WC_Order $order, array $newer ): void {
		$this->reload( $order )->update_status( OrderStatus::PENDING );
		$this->transport->responses = array( $this->respond( $newer ) );

		$this->checkout( $order );

		$started = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_payment_started_note_candidates( $order, $newer['id'] );
		$this->assertCount( 'requires_action' === $newer['status'] ? 1 : 0, array_intersect( $this->get_note_contents( $order ), $started ) );
	}

	/**
	 * Run a checkout attempt that fails, without a check of what it leaves.
	 *
	 * @param WC_Order            $order    Order.
	 * @param string              $failure  'decline', 'platform failure', 'transport failure' or 'extension error'.
	 * @param array<string,mixed> $declined The intent a decline names.
	 */
	private function fail_attempt( WC_Order $order, string $failure, array $declined ): void {
		$decline                                    = $this->recorded_entry( 'platform-intention-declines.json', 'generic_decline' );
		$decline['body']['error']['payment_intent'] = $declined;
		$answers                                    = array(
			'decline'           => array( $this->respond( $decline['body'], $decline['http_status'] ) ),
			'platform failure'  => array(
				$this->respond(
					array(
						'code'    => 'wcpay_request_failure',
						'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
						'data'    => array( 'status' => 502 ),
					),
					502
				),
			),
			'transport failure' => array_fill( 0, 4, new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
			'extension error'   => array(),
		);
		$this->transport->responses                 = $answers[ $failure ];
		$extension_error                            = static function () {
			throw new \Exception( 'An extension failed.' );
		};
		if ( 'extension error' === $failure ) {
			add_filter( 'wcpay_metadata_from_order', $extension_error );
		}

		$this->checkout( $order );

		remove_filter( 'wcpay_metadata_from_order', $extension_error );
	}

	/**
	 * Run a checkout of the order through the payment runtime and the WooPayments provider.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $payment_method Payment method the shopper pays with; the saved card by default.
	 */
	private function checkout( WC_Order $order, string $payment_method = self::SAVED_CARD ): void {
		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $this->reload( $order ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, $payment_method ),
			wc_get_container()->get( WooPaymentsProvider::class )
		);
	}

	/**
	 * List the intent requests sent since a count of requests, as their method and the path after `wcpay/`, without its query.
	 *
	 * @param int $sent_before Number of requests sent before.
	 * @return string[]
	 */
	private function intent_requests_since( int $sent_before ): array {
		return array_map(
			static fn( array $request ): string => $request['method'] . ' ' . preg_replace( '#^.*?/wcpay/|\?.*$#', '', (string) $request['path'] ),
			array_slice( $this->transport->requests, $sent_before )
		);
	}

	/**
	 * Send the order-status callback the checkout sends after the shopper authenticates.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent the shopper authenticated.
	 * @return array<string,mixed>
	 */
	private function send_order_status_callback( WC_Order $order, string $intent_id ): array {
		return wc_get_container()->get( WooPaymentsCheckoutAjaxController::class )->confirm_order_payment(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce_' . $order->get_id() ),
				'order_id'    => $order->get_id(),
				'intent_id'   => $intent_id,
			)
		);
	}

	/**
	 * Build the payment_intent.payment_failed delivery for an intent.
	 *
	 * @param array<string,mixed> $intent The failed intent.
	 * @return array<string,mixed>
	 */
	private function failure_delivery( array $intent ): array {
		$delivery                   = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'card_declined_failed' );
		$delivery['data']['object'] = $intent;

		return $delivery;
	}

	/**
	 * The intent of REC-1's generic_decline, with the saved card, the order's amount and the order's metadata.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function declined_intent( WC_Order $order ): array {
		$intent = $this->for_order( $this->recorded_entry( 'platform-intention-declines.json', 'generic_decline' )['body']['error']['payment_intent'], $order );
		$intent['last_payment_error']['payment_method']['id'] = self::SAVED_CARD;

		return $intent;
	}

	/**
	 * The recorded manual-capture authorization, with the saved card and the order's metadata.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function recorded_authorization( WC_Order $order ): array {
		return $this->recorded_intent( 'platform-manual-capture.json', 'authorize_manual_capture', $order );
	}

	/**
	 * A recorded intent with the saved card and the order's metadata, and another ID when given.
	 *
	 * @param string   $file      Fixture file.
	 * @param string   $pair      Entry pair.
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id ID to give the intent, or '' to keep the recorded one.
	 * @return array<string,mixed>
	 */
	private function recorded_intent( string $file, string $pair, WC_Order $order, string $intent_id = '' ): array {
		$intent = $this->for_order( $this->recorded_entry( $file, $pair )['body'], $order );
		if ( '' !== $intent_id ) {
			$intent['id'] = $intent_id;
		}

		return $intent;
	}

	/**
	 * Give an intent the saved card, the order's total and the order's metadata, as checkout creates it.
	 *
	 * @param array<string,mixed> $intent Recorded intent.
	 * @param WC_Order            $order  Order.
	 * @return array<string,mixed>
	 */
	private function for_order( array $intent, WC_Order $order ): array {
		$intent['payment_method'] = self::SAVED_CARD;
		$intent['amount']         = 1099;
		$intent['metadata']       = array(
			'order_id'  => (string) $order->get_id(),
			'order_key' => $order->get_order_key(),
		);

		return $intent;
	}

	/**
	 * Read a recorded platform response.
	 *
	 * @param string $file Fixture file.
	 * @param string $pair Entry pair.
	 * @return array{http_status:int,body:array<string,mixed>}
	 */
	private function recorded_entry( string $file, string $pair ): array {
		$recording = json_decode( (string) file_get_contents( self::FIXTURES . $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		foreach ( $recording['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return array(
					'http_status' => (int) $entry['response']['http_status'],
					'body'        => $entry['response']['body'],
				);
			}
		}

		$this->fail( "No recorded entry {$pair} in {$file}." );
	}

	/**
	 * Build a transport response.
	 *
	 * @param array<string,mixed> $body        Response body.
	 * @param int                 $http_status HTTP status.
	 * @return array<string,mixed>
	 */
	private function respond( array $body, int $http_status = 200 ): array {
		return array(
			'response' => array( 'code' => $http_status ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * Create an unpaid WooPayments order for one product, which needs processing once paid.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->add_product( WC_Helper_Product::create_simple_product(), 1 );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_billing_email( 'shopper@example.com' );
		$order->set_total( '10.99' );
		$order->save();

		return $order;
	}

	/**
	 * Assert the order's status, transaction ID, stored intent and stored intent status.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $status           Expected status.
	 * @param string   $transaction_id   Expected transaction ID.
	 * @param string   $intent_id        Expected `_intent_id`.
	 * @param string   $intention_status Expected `_intention_status`.
	 */
	private function assert_order( WC_Order $order, string $status, string $transaction_id, string $intent_id, string $intention_status ): void {
		$order = $this->reload( $order );
		$this->assertSame(
			array( $status, $transaction_id, $intent_id, $intention_status ),
			array( $order->get_status(), $order->get_transaction_id(), (string) $order->get_meta( '_intent_id', true ), (string) $order->get_meta( '_intention_status', true ) ),
			'Status, transaction ID, stored intent and stored intent status.'
		);
	}

	/**
	 * Count the warning lines that say a failure was not applied because the order waits on another payment.
	 *
	 * @param RecordingWcLogger $logger    Logger.
	 * @param WC_Order          $order     Order.
	 * @param string            $failed    The failed intent.
	 * @param string            $awaited   The intent the order waits on.
	 * @return int
	 */
	private function count_ignored_failure_lines( RecordingWcLogger $logger, WC_Order $order, string $failed, string $awaited ): int {
		$expected = sprintf( 'payment failure not applied: order %d waits on payment %s, not on the failed payment %s', $order->get_id(), $awaited, $failed );

		return count( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && $expected === $line[1] && 'woopayments' === $line[2] ) );
	}

	/**
	 * Get the contents of the order's notes.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function get_note_contents( WC_Order $order ): array {
		return array_values( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * Count the order's notes containing a fragment.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $fragment Fragment.
	 * @return int
	 */
	private function count_notes_containing( WC_Order $order, string $fragment ): int {
		return count( array_filter( $this->get_note_contents( $order ), static fn( string $note ): bool => false !== strpos( $note, $fragment ) ) );
	}

	/**
	 * Read the order again from the store.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order
	 */
	private function reload( WC_Order $order ): WC_Order {
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );

		return $reloaded;
	}
}
