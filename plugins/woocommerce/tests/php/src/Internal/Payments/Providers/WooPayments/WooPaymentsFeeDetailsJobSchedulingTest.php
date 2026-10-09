<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAjaxController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRedirectReturnController;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * When the Fee details job is scheduled, through each way a payment completes.
 *
 * Client 11.1.0 schedules `wcpay_add_fee_breakdown_to_order_notes` once per completed payment and once per capture,
 * exactly when the payment's success or capture note is first written, and never when the same payment is reported
 * again (class-wc-payments-order-service.php:1565-1599, :1659-1668). The job adds its note every time it runs, so a
 * second job means a second "Fee details" note. These tests drive the production wiring with only the platform
 * replaced, and observe only the scheduled job, so they hold whichever class decides to schedule it.
 */
class WooPaymentsFeeDetailsJobSchedulingTest extends WC_Unit_Test_Case {

	/**
	 * The job's hook, as the client registers it.
	 */
	private const FEE_DETAILS_JOB = 'wcpay_add_fee_breakdown_to_order_notes';

	/**
	 * The payment intent the platform reports.
	 *
	 * @var array<string,mixed>
	 */
	private array $intent = array();

	/**
	 * Webhook events delivered so far, so each delivery is a new event that reports the same payment.
	 *
	 * @var int
	 */
	private int $webhook_deliveries = 0;

	/**
	 * Original query parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_get = array();

	/**
	 * Original request method, or null when it was not set.
	 *
	 * @var mixed
	 */
	private $original_request_method;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_get            = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture preserves globals for cleanup.
		$this->original_request_method = $GLOBALS['_SERVER']['REQUEST_METHOD'] ?? null;
		$this->replace_platform();
		as_unschedule_all_actions( self::FEE_DETAILS_JOB );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;
		unset( $wp->query_vars['order-received'] );
		set_query_var( 'order-received', '' );
		$_GET = $this->original_get;
		if ( null === $this->original_request_method ) {
			unset( $GLOBALS['_SERVER']['REQUEST_METHOD'] );
		} else {
			$GLOBALS['_SERVER']['REQUEST_METHOD'] = $this->original_request_method;
		}
		remove_all_filters( 'woocommerce_is_order_received_page' );
		remove_all_filters( 'woocommerce_woopayments_builtin_enabled' );
		as_unschedule_all_actions( self::FEE_DETAILS_JOB );
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * @testdox A completed checkout schedules the job once, and the payment's webhook after it schedules none.
	 */
	public function test_completed_checkout(): void {
		$order        = $this->create_order();
		$this->intent = $this->succeeded_intent( $order, 'pi_checkout', 'ch_checkout' );

		$this->checkout( $order );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'pi_checkout' ) ), $this->take_pending_jobs() );

		$this->deliver_succeeded_webhook();

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox A succeeded webhook schedules the job once, and another event reporting the same payment schedules none.
	 */
	public function test_webhook_success(): void {
		$order        = $this->create_order();
		$this->intent = $this->succeeded_intent( $order, 'pi_webhook', 'ch_webhook' );
		$order->update_meta_data( '_intent_id', 'pi_webhook' );
		$order->save();

		$this->deliver_succeeded_webhook();

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'pi_webhook' ) ), $this->take_pending_jobs() );

		$this->deliver_succeeded_webhook();

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox The order-status callback schedules the job once, and the callback sent again schedules none.
	 */
	public function test_order_status_callback(): void {
		$order        = $this->create_order();
		$this->intent = $this->succeeded_intent( $order, 'pi_callback', 'ch_callback' );
		$order->update_meta_data( '_intent_id', 'pi_callback' );
		$order->save();

		$this->assertSame( 200, $this->send_order_status_callback( $order )['status_code'] ?? null );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'pi_callback' ) ), $this->take_pending_jobs() );

		$this->send_order_status_callback( $order );

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox The order-status callback of a zero-total order completes it and schedules no job, as the client does.
	 *
	 * Client 11.1.0's callback completes a zero-total order itself with a plain note; its order service then sees a paid
	 * order and writes no success note, so it schedules no job (class-wc-payment-gateway-wcpay.php:4249-4276).
	 */
	public function test_order_status_callback_for_a_zero_total_order(): void {
		$order        = $this->create_zero_total_order( 'seti_callback' );
		$this->intent = $this->succeeded_setup_intent( 'seti_callback' );

		$this->assertSame( 200, $this->send_order_status_callback( $order )['status_code'] ?? null );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox A redirect return schedules the job once, and the same return loaded again, or the payment's webhook after it, schedules none.
	 */
	public function test_redirect_return(): void {
		$order        = $this->create_order();
		$this->intent = $this->succeeded_intent( $order, 'pi_redirect', 'ch_redirect' );
		$order->update_meta_data( '_intent_id', 'pi_redirect' );
		$order->save();

		$this->load_redirect_return( $order );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'pi_redirect' ) ), $this->take_pending_jobs() );

		$this->load_redirect_return( $order );
		$this->deliver_succeeded_webhook();

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox A redirect return of a zero-total order schedules the job once for its setup intent, as the client does, and the same return loaded again schedules none.
	 */
	public function test_redirect_return_for_a_setup_intent(): void {
		$order        = $this->create_zero_total_order( 'seti_redirect' );
		$this->intent = $this->succeeded_setup_intent( 'seti_redirect' );

		$this->load_redirect_return( $order );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'seti_redirect' ) ), $this->take_pending_jobs() );

		$this->load_redirect_return( $order );

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * @testdox A capture schedules the job once, and the payment's webhook after it schedules none.
	 */
	public function test_capture(): void {
		$order = $this->create_order();
		$order->set_status( 'on-hold' );
		$order->set_transaction_id( 'pi_capture' );
		$order->update_meta_data( '_intent_id', 'pi_capture' );
		$order->update_meta_data( '_charge_id', 'ch_capture' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$this->intent = $this->succeeded_intent( $order, 'pi_capture', 'ch_capture' );

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_woocommerce_order_action_capture_charge( $this->reload( $order ) );

		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( array( $this->job_args( $order, 'pi_capture' ) ), $this->take_pending_jobs() );

		$this->deliver_succeeded_webhook();

		$this->assertSame( array(), $this->take_pending_jobs() );
	}

	/**
	 * Replace the platform with one that reports $this->intent, and let the built-in WooPayments own the store.
	 */
	private function replace_platform(): void {
		$test = $this;
		$api  = new class( $test ) extends WooPaymentsApiClient {
			/**
			 * The test case holding the intent.
			 *
			 * @var WooPaymentsFeeDetailsJobSchedulingTest
			 */
			private WooPaymentsFeeDetailsJobSchedulingTest $test;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsFeeDetailsJobSchedulingTest $test Test case.
			 */
			public function __construct( WooPaymentsFeeDetailsJobSchedulingTest $test ) {
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
			 * Answer a checkout charge with the reported intent.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );
				return $this->test->get_reported_intent();
			}

			/**
			 * Answer an intent read with the reported intent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				unset( $intent_id );
				return $this->test->get_reported_intent();
			}

			/**
			 * Answer a setup intent read with the reported intent.
			 *
			 * @param string $setup_intent_id Setup intent ID.
			 * @return array<string,mixed>
			 */
			public function get_setup_intention( string $setup_intent_id ): array {
				unset( $setup_intent_id );
				return $this->test->get_reported_intent();
			}

			/**
			 * Answer a capture with the reported intent.
			 *
			 * @param string              $intent_id         Intent ID.
			 * @param int                 $amount_to_capture Amount.
			 * @param array<string,mixed> $metadata          Metadata.
			 * @param array<string,mixed> $level3            Level 3 data.
			 * @return array<string,mixed>
			 */
			public function capture_intention( string $intent_id, int $amount_to_capture, array $metadata = array(), array $level3 = array() ): array {
				unset( $intent_id, $amount_to_capture, $metadata, $level3 );
				return $this->test->get_reported_intent();
			}
		};
		wc_get_container()->replace( WooPaymentsApiClient::class, $api );
		add_filter( 'woocommerce_woopayments_builtin_enabled', '__return_true' );
		$this->reset_container_resolutions();
	}

	/**
	 * Get the payment intent the platform reports.
	 *
	 * @return array<string,mixed>
	 */
	public function get_reported_intent(): array {
		return $this->intent;
	}

	/**
	 * Run a checkout of the order through the payment runtime and the WooPayments provider.
	 *
	 * @param WC_Order $order Order.
	 */
	private function checkout( WC_Order $order ): void {
		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $this->reload( $order ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_card' ),
			wc_get_container()->get( WooPaymentsProvider::class )
		);
	}

	/**
	 * Deliver a payment_intent.succeeded event for the reported intent, as a new event each time.
	 */
	private function deliver_succeeded_webhook(): void {
		wc_get_container()->get( WooPaymentsEventIngestor::class )->process(
			array(
				'id'   => 'evt_' . $this->intent['id'] . '_' . ( ++$this->webhook_deliveries ),
				'type' => 'payment_intent.succeeded',
				'data' => array( 'object' => $this->intent ),
			)
		);
	}

	/**
	 * Send the order-status callback the checkout sends after the shopper authenticates.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function send_order_status_callback( WC_Order $order ): array {
		return wc_get_container()->get( WooPaymentsCheckoutAjaxController::class )->get_update_order_status_response(
			array(
				'_ajax_nonce' => wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				'order_id'    => $order->get_id(),
				'intent_id'   => $this->intent['id'],
			)
		);
	}

	/**
	 * Load the order-received page the shopper returns to after a redirect payment.
	 *
	 * @param WC_Order $order Order.
	 */
	private function load_redirect_return( WC_Order $order ): void {
		global $wp;
		$wp->query_vars['order-received'] = $order->get_id();
		set_query_var( 'order-received', $order->get_id() );
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		$intent_param                         = 'setup_intent' === $this->intent['object'] ? 'setup_intent' : 'payment_intent';
		$_GET                                 = array(
			'wc_payment_method'              => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'_wpnonce'                       => wp_create_nonce( 'wcpay_process_redirect_order_nonce' ),
			$intent_param                    => $this->intent['id'],
			$intent_param . '_client_secret' => $this->intent['id'] . '_secret_example',
			'key'                            => $order->get_order_key(),
		);
		$GLOBALS['_SERVER']['REQUEST_METHOD'] = 'GET';

		wc_get_container()->get( WooPaymentsRedirectReturnController::class )->handle_wp();
	}

	/**
	 * Create an unpaid WooPayments order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_billing_email( 'shopper@example.com' );
		$order->set_total( '50.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create a zero-total WooPayments order whose checkout created a setup intent.
	 *
	 * @param string $setup_intent_id Setup intent ID.
	 * @return WC_Order
	 */
	private function create_zero_total_order( string $setup_intent_id ): WC_Order {
		$order = $this->create_order();
		$order->set_total( '0.00' );
		$order->update_meta_data( '_intent_id', $setup_intent_id );
		$order->save();

		return $order;
	}

	/**
	 * Build a succeeded setup intent.
	 *
	 * @param string $setup_intent_id Setup intent ID.
	 * @return array<string,mixed>
	 */
	private function succeeded_setup_intent( string $setup_intent_id ): array {
		return array(
			'id'             => $setup_intent_id,
			'object'         => 'setup_intent',
			'status'         => 'succeeded',
			'customer'       => 'cus_shopper',
			'payment_method' => 'pm_card',
		);
	}

	/**
	 * Build a succeeded card payment intent for the order.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 * @param string   $charge_id Charge ID.
	 * @return array<string,mixed>
	 */
	private function succeeded_intent( WC_Order $order, string $intent_id, string $charge_id ): array {
		return array(
			'id'             => $intent_id,
			'object'         => 'payment_intent',
			'status'         => 'succeeded',
			'amount'         => 5000,
			'currency'       => 'usd',
			'customer'       => 'cus_shopper',
			'payment_method' => 'pm_card',
			'metadata'       => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			'charges'        => array(
				'total_count' => 1,
				'data'        => array(
					array(
						'id'                     => $charge_id,
						'amount'                 => 5000,
						'currency'               => 'usd',
						'captured'               => true,
						'status'                 => 'succeeded',
						'payment_method'         => 'pm_card',
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand'   => 'visa',
								'funding' => 'credit',
								'last4'   => '4242',
							),
						),
					),
				),
			),
		);
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

	/**
	 * The job's arguments for a payment of the order, as the client schedules it.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent the job reads.
	 * @return array<string,mixed>
	 */
	private function job_args( WC_Order $order, string $intent_id ): array {
		return array(
			'order_id'     => $order->get_id(),
			'intent_id'    => $intent_id,
			'is_test_mode' => false,
		);
	}

	/**
	 * Get the arguments of every pending Fee details job, then cancel them, so a later schedule is not deduplicated against them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function take_pending_jobs(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::FEE_DETAILS_JOB,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'group'    => 'woocommerce_payments',
				'per_page' => -1,
			)
		);
		as_unschedule_all_actions( self::FEE_DETAILS_JOB );

		return array_values( array_map( static fn( $action ): array => $action->get_args(), $actions ) );
	}
}
