<?php
/**
 * WooPaymentsOrderAdminActionsController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Orders\UnpaidOrderTimeoutMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentConfirmationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusChangeRetryListener;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingProvider;
use RuntimeException;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsOrderAdminActionsController class.
 */
class WooPaymentsOrderAdminActionsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Action Scheduler hook of the status-change capture and cancel retry, by its value: a job scheduled under it must keep running.
	 *
	 * @var string
	 */
	private const RETRY_HOOK = 'woocommerce_woopayments_retry_status_change_operation';

	/**
	 * The recorded manual-capture authorization's intent (Fixtures/platform-manual-capture.json).
	 *
	 * @var string
	 */
	private const RECORDED_INTENT = 'pi_3UJhOgBzWlxcwgpP0AOgQE2z';

	/**
	 * The recorded manual-capture authorization's charge (Fixtures/platform-manual-capture.json, latest_charge).
	 *
	 * @var string
	 */
	private const RECORDED_CHARGE = 'ch_3UJhOgBzWlxcwgpP0NcK21bQ';

	/**
	 * Text every capture failure note carries, whatever the amount and the platform's message.
	 *
	 * @var string
	 */
	private const CAPTURE_FAILURE_NOTE = '<strong>failed</strong> to complete using WooPayments';

	/**
	 * Seconds from an unpaid order's last change to the end of its keeping window at a 60-minute hold: the hold plus a day.
	 *
	 * @var int
	 */
	private const UNPAID_WINDOW = HOUR_IN_SECONDS + DAY_IN_SECONDS;

	/**
	 * The note on an unpaid order cancelled at the end of its keeping window with its payment unchecked.
	 *
	 * @var string
	 */
	private const UNPAID_CANCEL_NOTE = 'The payment for this order could not be checked for a day, so the order is cancelled as unpaid. Before treating it as unpaid, check whether WooPayments received a payment for it.';

	/**
	 * The note on an unpaid order kept at the end of its keeping window because its record shows a payment.
	 *
	 * @var string
	 */
	private const UNPAID_KEPT_NOTE = 'This order was not cancelled as unpaid because its record shows a payment that could not be checked with WooPayments. Check the payment in WooPayments before you cancel or fulfill this order.';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderAdminActionsController|null
	 */
	private $sut;

	/**
	 * The retry listener a test registered.
	 *
	 * @var WooPaymentsStatusChangeRetryListener|null
	 */
	private $retry_listener;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->retry_listener instanceof WooPaymentsStatusChangeRetryListener ) {
			remove_action( self::RETRY_HOOK, array( $this->retry_listener, 'handle_retry_status_change_operation' ) );
		}
		// The ownership tests write the options these memoize; the next test reads them again.
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();

		if ( $this->sut instanceof WooPaymentsOrderAdminActionsController ) {
			remove_filter( 'woocommerce_order_actions', array( $this->sut, 'handle_woocommerce_order_actions' ) );
			remove_action( 'woocommerce_order_action_capture_charge', array( $this->sut, 'handle_woocommerce_order_action_capture_charge' ) );
			remove_action( 'woocommerce_order_action_cancel_authorization', array( $this->sut, 'handle_woocommerce_order_action_cancel_authorization' ) );
			remove_action( 'woocommerce_order_status_completed', array( $this->sut, 'handle_woocommerce_order_status_completed' ) );
			remove_action( 'woocommerce_order_status_cancelled', array( $this->sut, 'handle_woocommerce_order_status_cancelled' ) );
			remove_action( self::RETRY_HOOK, array( $this->sut, 'handle_retry_status_change_operation' ) );
			remove_filter( 'woocommerce_gateway_title', array( $this->sut, 'handle_woocommerce_gateway_title' ) );
		}

		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );

		global $theorder, $post;
		$theorder = null;
		$post     = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * @testdox Register should wire each order workflow hook.
	 */
	public function test_registers_order_workflow_hooks(): void {
		$this->sut = $this->create_controller();

		$this->sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_order_actions', array( $this->sut, 'handle_woocommerce_order_actions' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_action_capture_charge', array( $this->sut, 'handle_woocommerce_order_action_capture_charge' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_action_cancel_authorization', array( $this->sut, 'handle_woocommerce_order_action_cancel_authorization' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_completed', array( $this->sut, 'handle_woocommerce_order_status_completed' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_cancelled', array( $this->sut, 'handle_woocommerce_order_status_cancelled' ) ) );
	}

	/**
	 * @testdox Register should add the admin gateway title filter on admin requests only, as client 11.1.0 acts only there.
	 */
	public function test_registers_the_gateway_title_filter_in_admin_only(): void {
		$this->sut = $this->create_controller();
		$this->sut->register();
		$this->assertFalse( has_filter( 'woocommerce_gateway_title', array( $this->sut, 'handle_woocommerce_gateway_title' ) ) );

		set_current_screen( 'woocommerce_page_wc-orders' );
		$this->sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_gateway_title', array( $this->sut, 'handle_woocommerce_gateway_title' ) ) );
	}

	/**
	 * @testdox In admin, the card gateway's title for an order paid with "$method_title" is "$expected".
	 *
	 * Client 11.1.0 filter_gateway_title() (class-wc-payments-express-checkout-button-handler.php:415-437); core reads the
	 * filtered title for "Payment via" and the payment method select (class-wc-meta-box-order-data.php:331, :619).
	 *
	 * @testWith ["Apple Pay (WooPayments)", "Apple Pay (WooPayments)"]
	 *           ["Google Pay (WooPayments)", "Google Pay (WooPayments)"]
	 *           ["Payment Request (WooPayments)", "Payment Request (WooPayments)"]
	 *           ["Visa credit card", "Credit card / debit card"]
	 *           ["", "Credit card / debit card"]
	 *
	 * @param string $method_title Order payment method title.
	 * @param string $expected     Expected gateway title.
	 */
	public function test_admin_gateway_title_shows_the_wallet_order_title( string $method_title, string $expected ): void {
		$this->sut = $this->create_controller();
		set_current_screen( 'woocommerce_page_wc-orders' );
		$this->sut->register();
		global $theorder;
		$theorder = $this->create_order_with_payment_method_title( $method_title );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core's hook (abstract-wc-payment-gateway.php:384).
		$this->assertSame( $expected, apply_filters( 'woocommerce_gateway_title', 'Credit card / debit card', WooPaymentsPersistenceVocabulary::GATEWAY_ID ) );
	}

	/**
	 * @testdox In admin without $theorder, the order comes from the global post, as client 11.1.0's get_current_order().
	 */
	public function test_admin_gateway_title_reads_the_order_from_the_global_post(): void {
		$this->sut = $this->create_controller();
		set_current_screen( 'edit-shop_order' );
		$order = $this->create_order_with_payment_method_title( 'Google Pay (WooPayments)' );
		global $post;
		$post = new \WP_Post( (object) array( 'ID' => $order->get_id() ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( 'Google Pay (WooPayments)', $this->sut->handle_woocommerce_gateway_title( 'Credit card / debit card', WooPaymentsPersistenceVocabulary::GATEWAY_ID ) );
	}

	/**
	 * @testdox The gateway title stays as it is for other gateways and outside admin.
	 */
	public function test_gateway_title_unchanged_for_other_gateways_and_outside_admin(): void {
		$this->sut = $this->create_controller();
		global $theorder;
		$theorder = $this->create_order_with_payment_method_title( 'Apple Pay (WooPayments)' );

		$this->assertSame( 'Credit card / debit card', $this->sut->handle_woocommerce_gateway_title( 'Credit card / debit card', WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'Front end.' );

		set_current_screen( 'woocommerce_page_wc-orders' );
		$this->assertSame( 'Direct bank transfer', $this->sut->handle_woocommerce_gateway_title( 'Direct bank transfer', 'bacs' ) );
		$this->assertSame( 'iDEAL', $this->sut->handle_woocommerce_gateway_title( 'iDEAL', WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_ideal' ) );
	}

	/**
	 * @testdox In admin, another gateway's wallet order leaves the WooPayments title alone in the payment method select.
	 *
	 * Core lists every enabled gateway's title in the select (class-wc-meta-box-order-data.php:617-619); client 11.1.0 does
	 * not check the order's gateway, so this is a corrective departure.
	 */
	public function test_admin_gateway_title_ignores_another_gateways_wallet_order(): void {
		$this->sut = $this->create_controller();
		set_current_screen( 'woocommerce_page_wc-orders' );
		global $theorder;
		$theorder = $this->create_order_with_payment_method_title( 'Apple Pay (Stripe)', 'stripe' );

		$this->assertSame( 'Credit card / debit card', $this->sut->handle_woocommerce_gateway_title( 'Credit card / debit card', WooPaymentsPersistenceVocabulary::GATEWAY_ID ) );
	}

	/**
	 * @testdox In admin, a $label payment method title from the order getter filter leaves the gateway title alone.
	 *
	 * WC_Order::get_payment_method_title() returns what woocommerce_order_get_payment_method_title returns, unchecked
	 * (abstract-wc-data.php:953-964).
	 *
	 * @dataProvider malformed_method_title_provider
	 *
	 * @param string $label        Case label.
	 * @param mixed  $method_title Filtered payment method title.
	 */
	public function test_admin_gateway_title_ignores_a_malformed_method_title( string $label, $method_title ): void {
		unset( $label );
		$this->sut = $this->create_controller();
		set_current_screen( 'woocommerce_page_wc-orders' );
		global $theorder;
		$theorder = $this->create_order_with_payment_method_title( 'Apple Pay (WooPayments)' );
		$filter   = static fn() => $method_title;
		add_filter( 'woocommerce_order_get_payment_method_title', $filter );

		try {
			$this->assertSame( 'Credit card / debit card', $this->sut->handle_woocommerce_gateway_title( 'Credit card / debit card', WooPaymentsPersistenceVocabulary::GATEWAY_ID ) );
		} finally {
			remove_filter( 'woocommerce_order_get_payment_method_title', $filter );
		}
	}

	/**
	 * Malformed payment method titles an extension's filter could return.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function malformed_method_title_provider(): array {
		return array(
			'null'   => array( 'null', null ),
			'array'  => array( 'array', array( 'Apple Pay (WooPayments)' ) ),
			'object' => array( 'object', (object) array( 'title' => 'Apple Pay (WooPayments)' ) ),
		);
	}

	/**
	 * Create an order with a payment method title, paid with WooPayments unless another gateway is given.
	 *
	 * @param string $method_title Payment method title.
	 * @param string $gateway_id   Payment gateway ID.
	 * @return WC_Order
	 */
	private function create_order_with_payment_method_title( string $method_title, string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $gateway_id );
		$order->set_payment_method_title( $method_title );
		$order->save();

		return $order;
	}

	/**
	 * @testdox Authorized base and split-gateway orders should expose capture and cancel actions before existing actions.
	 * @dataProvider eligible_gateway_provider
	 *
	 * @param string $gateway_id Payment gateway ID.
	 */
	public function test_adds_actions_for_eligible_woopayments_gateway_orders( string $gateway_id ): void {
		global $theorder;
		$theorder  = $this->create_authorized_order( $gateway_id );
		$this->sut = $this->create_controller();

		$actions = $this->sut->handle_woocommerce_order_actions( array( 'email_order_details' => 'Email order details' ) );

		$this->assertSame(
			array(
				'capture_charge'       => 'Capture charge',
				'cancel_authorization' => 'Cancel authorization',
				'email_order_details'  => 'Email order details',
			),
			$actions
		);
	}

	/**
	 * Data provider for eligible WooPayments gateway IDs.
	 *
	 * @return array<string,array{string}>
	 */
	public static function eligible_gateway_provider(): array {
		return array(
			'base gateway'  => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			'split gateway' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_link' ),
		);
	}

	/**
	 * @testdox Ineligible orders should preserve existing actions without adding authorization actions.
	 * @dataProvider ineligible_order_provider
	 *
	 * @param string $gateway_id    Payment gateway ID.
	 * @param string $intent_status Intent status.
	 * @param string $order_status  Order status.
	 */
	public function test_preserves_actions_for_ineligible_orders( string $gateway_id, string $intent_status, string $order_status ): void {
		global $theorder;
		$theorder  = $this->create_authorized_order( $gateway_id, $intent_status, $order_status );
		$this->sut = $this->create_controller();
		$existing  = array( 'email_order_details' => 'Email order details' );

		$this->assertSame( $existing, $this->sut->handle_woocommerce_order_actions( $existing ) );
	}

	/**
	 * Data provider for ineligible orders.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function ineligible_order_provider(): array {
		return array(
			'other gateway'    => array( 'cod', 'requires_capture', 'on-hold' ),
			'already captured' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'succeeded', 'on-hold' ),
			'paid order'       => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', 'processing' ),
		);
	}

	/**
	 * @testdox A missing or invalid global order should preserve existing actions.
	 */
	public function test_preserves_actions_when_global_order_is_invalid(): void {
		global $theorder;
		$theorder  = new \stdClass();
		$this->sut = $this->create_controller();
		$existing  = array( 'email_order_details' => 'Email order details' );

		$this->assertSame( $existing, $this->sut->handle_woocommerce_order_actions( $existing ) );
	}

	/**
	 * A capture on the status change to completed schedules the Fee details job even when no new capture note is
	 * written; the order action leaves scheduling to the capture note (class-wc-payments-order-service.php:1668, :1862-1874).
	 * Only the status-change capture is flagged, as the client writes no fee meta on it (class-wc-payments-order-service.php:1847-1886).
	 *
	 * @testdox Capture triggers delegate the full order total, keep the shared success effects and add no failure note: $_dataName.
	 * @dataProvider capture_triggers_and_job_scheduling
	 *
	 * @param string             $order_status       Order status before the hook fires.
	 * @param string             $hook               Hook that triggers the capture.
	 * @param array<string,bool> $status_change_flag Expected status-change flag in the capture's provider data.
	 * @param bool               $schedules          Whether the job is scheduled without a new capture note.
	 */
	public function test_status_change_capture_schedules_the_fee_details_job( string $order_status, string $hook, array $status_change_flag, bool $schedules ): void {
		$order                = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$last_capture_context = null;
		$processing_service   = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willReturnCallback(
				static function ( PaymentOperationContext $context ) use ( &$last_capture_context ): PaymentOutcome {
					$last_capture_context = $context;
					$context->get_order()->add_order_note( 'Shared capture success note.' );
					$context->get_order()->update_meta_data( '_intention_status', 'succeeded' );
					$context->get_order()->save_meta_data();

					return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_status_capture' );
				}
			);
		$this->sut = $this->create_controller( $processing_service );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertInstanceOf( PaymentOperationContext::class, $last_capture_context );
		$this->assertSame( $order->get_id(), $last_capture_context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $last_capture_context->get_gateway_id() );
		$this->assertSame( (float) $order->get_total(), $last_capture_context->get_amount() );
		$this->assertSame( $status_change_flag, array_intersect_key( $last_capture_context->get_provider_data(), array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ) ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $order, 'Shared capture success note.' );
		$this->assertNotContains( 'Capture authorization <strong>failed</strong> to complete.', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertSame(
			$schedules,
			as_has_scheduled_action(
				WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				array(
					'order_id'     => $order->get_id(),
					'intent_id'    => 'pi_status_capture',
					'is_test_mode' => false,
				),
				'woocommerce_payments'
			)
		);
	}

	/**
	 * Capture triggers, the status-change flag each passes and whether each schedules the job by itself.
	 *
	 * @return array<string,array{string,string,array<string,bool>,bool}>
	 */
	public function capture_triggers_and_job_scheduling(): array {
		return array(
			'status change to completed' => array( 'completed', 'woocommerce_order_status_completed', array( WooPaymentsPaymentOperationService::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE => true ), true ),
			'order action'               => array( 'on-hold', 'woocommerce_order_action_capture_charge', array(), false ),
		);
	}

	/**
	 * @testdox Cancel triggers delegate through the shared cancel context with the base gateway: $_dataName.
	 * @dataProvider provider_cancel_triggers
	 *
	 * @param string $gateway_id   Order payment gateway ID.
	 * @param string $order_status Order status before the hook fires.
	 * @param string $hook         Hook that triggers the cancel.
	 */
	public function test_cancel_triggers_delegate_through_shared_processing( string $gateway_id, string $order_status, string $hook ): void {
		$order               = $this->create_authorized_order( $gateway_id, 'requires_capture', $order_status );
		$last_cancel_context = null;
		$processing_service  = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'cancel' )
			->willReturnCallback(
				static function ( PaymentOperationContext $context ) use ( &$last_cancel_context ): PaymentOutcome {
					$last_cancel_context = $context;

					return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_cancel' );
				}
			);
		$this->sut = $this->create_controller( $processing_service );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertInstanceOf( PaymentOperationContext::class, $last_cancel_context );
		$this->assertSame( $order->get_id(), $last_cancel_context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $last_cancel_context->get_gateway_id() );
	}

	/**
	 * Cancel triggers and the order each one acts on.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provider_cancel_triggers(): array {
		return array(
			'order action'                             => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'on-hold', 'woocommerce_order_action_cancel_authorization' ),
			'status change to cancelled, Link gateway' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_link', 'cancelled', 'woocommerce_order_status_cancelled' ),
		);
	}

	/**
	 * @testdox Saving Cancelled for a captured order persists one status change without provider action.
	 */
	public function test_order_data_save_cancels_captured_order_without_provider_action(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-order-data.php';

		$order              = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'succeeded', OrderStatus::PROCESSING );
		$recording_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_authorized' ) );
		$provider           = new class( $recording_provider ) extends WooPaymentsProvider {
			/** @var RecordingProvider */
			private RecordingProvider $recorder;

			/**
			 * @param RecordingProvider $recorder Recording provider.
			 */
			public function __construct( RecordingProvider $recorder ) {
				$this->recorder = $recorder;
			}

			/**
			 * @param PaymentOperationContext $context       Payment context.
			 * @param string                  $operation_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
				return $this->recorder->cancel( $context, $operation_key );
			}

			/**
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				return $this->recorder->refund( $context, $idempotency_key );
			}

			/**
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				return $outcome;
			}

			/**
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation.
			 */
			public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
			}
		};
		$this->sut          = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();
		$status_hook_count = did_action( 'woocommerce_order_status_cancelled' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Simulates the nonce-verified order meta box save.
		$original_post = $_POST;

		$_POST = array(
			'order_status'    => OrderInternalStatus::CANCELLED,
			'_payment_method' => $order->get_payment_method(),
			'customer_user'   => 0,
		);

		try {
			\WC_Meta_Box_Order_Data::save( $order->get_id() );
		} finally {
			$_POST = $original_post;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$stored_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stored_order );
		$this->assertSame( OrderStatus::CANCELLED, $stored_order->get_status() );
		$this->assertSame( $status_hook_count + 1, did_action( 'woocommerce_order_status_cancelled' ) );
		$this->assertSame( 0, $recording_provider->cancel_calls );
		$this->assertSame( 0, $recording_provider->refund_calls );
		$this->assertCount( 0, $stored_order->get_refunds() );
		$this->assertSame( 0.0, (float) $stored_order->get_total_refunded() );
		$status_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static function ( $note ): bool {
				return str_contains( (string) $note->content, 'Order status changed from Processing to Cancelled.' );
			}
		);
		$this->assertCount( 1, $status_notes );
	}

	/**
	 * @testdox A status change to Completed should capture nothing on a captured order or an order paid by another gateway.
	 */
	public function test_status_actions_ignore_ineligible_orders(): void {
		$captured_order     = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'succeeded', 'completed' );
		$foreign_order      = $this->create_authorized_order( 'cod', 'requires_capture', 'completed' );
		$processing_service = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->never() )->method( 'capture' );
		$processing_service->expects( $this->never() )->method( 'cancel' );
		$this->sut = $this->create_controller( $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_completed', $captured_order->get_id(), $captured_order, array() );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_completed', $foreign_order->get_id(), $foreign_order, array() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox A shared capture failure should leave an authorized order on hold and retain its failure note.
	 */
	public function test_capture_failure_preserves_authorized_order_and_failure_note(): void {
		$order     = $this->create_authorized_order();
		$provider  = $this->create_fixed_outcome_provider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_authorized',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_NOTE => 'Shared capture failure note.' )
			)
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'requires_capture', $reloaded->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $reloaded, 'Shared capture failure note.' );
	}

	/**
	 * @testdox An automatic cancel failure should preserve status, add the WooPayments extension's generic note, and not escape.
	 */
	public function test_automatic_cancel_failure_preserves_status_and_adds_generic_note(): void {
		$order     = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', 'cancelled' );
		$provider  = $this->create_fixed_outcome_provider( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_authorized' ) );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_cancelled', $order->get_id(), $order, array() );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'cancelled', $reloaded->get_status() );
		$this->assertSame( 'requires_capture', $reloaded->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $reloaded, 'Canceling authorization <strong>failed</strong> to complete.' );
	}

	/**
	 * @testdox Capture exceptions add the WooPayments extension's generic note without escaping the hook: $_dataName.
	 * @dataProvider provider_capture_exception_triggers
	 *
	 * @param string $order_status Order status before the hook fires.
	 * @param string $hook         Hook that triggers the capture.
	 */
	public function test_capture_exception_adds_generic_note_without_escaping( string $order_status, string $hook ): void {
		$order              = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$processing_service = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willThrowException( new RuntimeException( 'Simulated capture exception.' ) );
		$this->sut = $this->create_controller( $processing_service );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assert_order_has_note_containing( $order, 'Capture authorization <strong>failed</strong> to complete.' );
	}

	/**
	 * Capture triggers whose capture throws.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_capture_exception_triggers(): array {
		return array(
			'status change to completed' => array( 'completed', 'woocommerce_order_status_completed' ),
			'order action'               => array( 'on-hold', 'woocommerce_order_action_capture_charge' ),
		);
	}

	/**
	 * @testdox The capture-charge order action should send one capture request for the authorized total and mark the order processing.
	 *
	 * Joins the request-shape half
	 * ({@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationServiceTest::test_capture_sends_context_amount_to_transport})
	 * and the on-hold half ({@see \Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLifecycleServiceTest::test_authorized_event_moves_order_on_hold})
	 * by driving the real "Capture charge" order action through
	 * {@see WooPaymentsOrderAdminActionsController}, {@see PaymentProcessingService::capture},
	 * the real {@see WooPaymentsProvider}, {@see WooPaymentsPaymentOperationService} and
	 * {@see Client} over a fake transport queued with REC-CAP's recorded
	 * `capture_full_amount` response (`Fixtures/platform-manual-capture.json`): a 10.99 USD
	 * manual-capture authorization captured
	 * for its full amount. Client parity: the capture note is the exact `was
	 * <strong>successfully captured</strong> using WooPayments` wording plus the intent id
	 * (`os:2226-2236`).
	 *
	 * The no-repeat-POST assertion below is NOT a client-parity claim. The client 11.1.0
	 * only hides the "Capture charge" order action once the intention leaves
	 * `requires_capture` (`gw:3938`); its own `capture_charge()` handler (`gw:562`
	 * wires it to the hook, `gw:3964` is the handler body) carries no status guard of its
	 * own and would re-issue the capture request to Stripe if invoked directly. Native's
	 * eligibility guard sits inside the handler itself
	 * ({@see WooPaymentsOrderAdminActionsController::is_authorized_woopayments_order()},
	 * controller `:120`, `:198-207`), which is stricter than the client. This is a T.7
	 * note (native-only hardening, not a parity gap): the client's note-once dedup
	 * (`os:1663`) is what it relies on instead. Because the controller guard stops the
	 * repeated order action before any capture code runs, it cannot exercise that dedup;
	 * the actual client-parity claim is proven separately below by calling
	 * {@see PaymentProcessingService::capture()} directly a second time (bypassing the
	 * controller), which drives a genuine second capture through the same stack and
	 * proves the note stays at one.
	 */
	public function test_capture_charge_action_captures_authorized_total_once_and_marks_processing(): void {
		$order = $this->create_recorded_manual_capture_order();

		$recorded_capture      = $this->load_recorded_manual_capture_entry( 'capture_full_amount' );
		$http_client           = new FakeHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded_capture['http_status'] ),
			'headers'  => array( 'content-type' => $recorded_capture['content_type'] ),
			'body'     => wp_json_encode( $recorded_capture['body'] ),
		);

		$provider  = $this->create_provider_over_fake_transport( $http_client );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );

		$this->assertCount( 1, $http_client->requests, 'The capture action must dispatch exactly one transport request.' );
		$this->assertSame( 'POST', $http_client->requests[0]['method'] );
		$this->assertStringContainsString( 'intentions/pi_3UJhOgBzWlxcwgpP0AOgQE2z/capture', $http_client->requests[0]['path'] );

		$sent_body = json_decode( (string) $http_client->requests[0]['body'], true );
		$this->assertIsArray( $sent_body, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( 1099, $sent_body['amount_to_capture'] ?? null, 'The capture request must send the recorded full-total minor-unit amount.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status(), 'The captured order must move to processing.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );

		$capture_note_matches = static fn( $note ): bool =>
			str_contains( $note->content, 'was <strong>successfully captured</strong> using WooPayments' )
			&& str_contains( $note->content, 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );

		$capture_notes = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes, "Exactly one success note matching the client's `was <strong>successfully captured</strong> using WooPayments` wording (os:2226-2236) and the recorded intent id should reference the capture." );

		// A second invocation on the now-settled order sends no second POST. This is a
		// NATIVE-ONLY guard, not a client-parity claim (see the class docblock above):
		// the client 11.1.0 handler has no status check of its own and would call Stripe
		// again; only its order-actions list hides the action once the intention leaves
		// `requires_capture` (gw:3938). The client-parity claim for a repeated invocation
		// is instead its note-once dedup (os:1663), asserted below.
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );
		$this->assertCount( 1, $http_client->requests, 'A second capture invocation on an already-captured order must not repeat the POST (native-only guard, not client parity).' );

		$capture_notes_after_repeat = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes_after_repeat, "The capture note stays exactly one after the second invocation, matching the client's note-once dedup (os:1663)." );

		// The controller guard above stops the repeat before any capture code runs at
		// all, so it cannot exercise WooPaymentsOrderNoteService::add_note_once()'s
		// dedup. Call PaymentProcessingService::capture() directly, bypassing the
		// controller, to drive a genuine second capture through the same real
		// provider/adapter/API client stack (fed the same recorded response again),
		// and prove the client's note-once dedup (os:1663) for real.
		$second_outcome = wc_get_container()->get( PaymentProcessingService::class )->capture(
			PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, (float) $order->get_total() ),
			$provider
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $second_outcome->get_status(), 'The direct second capture call must still complete against the recorded response.' );
		$this->assertCount( 2, $http_client->requests, 'A direct second capture call, bypassing the controller guard, must dispatch its own transport request.' );

		$capture_notes_after_direct_repeat = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes_after_direct_repeat, "A genuine second capture must not duplicate the capture note, matching the client's note-once dedup (os:1663)." );
	}

	/**
	 * @testdox Capture sends the admin request's rounded total and its success webhook keeps the captured order paid.
	 */
	public function test_capture_charge_keeps_the_amount_read_in_admin(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_recorded_manual_capture_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '10.40' );
		$order->save();
		update_option( 'woocommerce_price_num_decimals', '0' );
		$order     = $this->reload( $order );
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$this->create_unpaid_order_platform( $transport );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ) );
		$this->sut->register();
		$this->fire_order_hook( 'woocommerce_order_action_capture_charge', $order );

		$body = json_decode( $transport->requests[0]['body'], true );
		$this->assertSame( 1000, $body['amount_to_capture'] );
		$this->assertSame( (string) $order->get_id(), $body['level3']['merchant_reference'] );
		$this->assertTrue( $this->reload( $order )->has_status( OrderStatus::PROCESSING ) );
		$notes                               = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$event                               = $this->recorded_payment_success_event( $order, self::RECORDED_INTENT, self::RECORDED_CHARGE );
		$event['data']['object']['currency'] = 'eur';
		$event['data']['object']['amount']   = 1000;
		wc_get_container()->get( EventIngestor::class )->process( $event );

		$this->assertSame( OrderStatus::PROCESSING, $this->reload( $order )->get_status() );
		$this->assertSame( wp_list_pluck( $notes, 'id' ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' ) );
	}

	/**
	 * @testdox A succeeded payment records the charge or the hold for the amount the platform received.
	 * @testWith ["EUR", 0, 900, "on-hold"]
	 *           ["EUR", 0, 1000, "on-hold"]
	 *           ["EUR", 2, 1040, "processing"]
	 *           ["USD", 2, 1040, "processing"]
	 *           ["USD", 2, 1000, "on-hold"]
	 *
	 * @param string $currency Order and charge currency.
	 * @param int    $decimals Precision when the event is applied.
	 * @param int    $amount   Amount received by the platform.
	 * @param string $status   Expected order status.
	 */
	public function test_success_records_the_amount_at_order_currency_precision( string $currency, int $decimals, int $amount, string $status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_unpaid_order( 'requires_action' );
		$order->set_currency( $currency );
		$order->set_total( '10.40' );
		$order->save();
		update_option( 'woocommerce_price_num_decimals', $decimals );
		$order = $this->reload( $order );

		$request = wc_get_container()->get( WooPaymentsOrderDetailsService::class )->build_create_payment_intent_request_from_order( $order, 'cus_order', 'pm_order', false );
		$this->assertSame( 0 === $decimals ? 1000 : 1040, $request['amount'] );

		$event                               = $this->recorded_payment_success_event( $order, 'pi_unpaid', 'ch_unpaid' );
		$event['data']['object']['currency'] = strtolower( $currency );
		$event['data']['object']['amount']   = $amount;
		wc_get_container()->get( EventIngestor::class )->process( $event );

		$this->assertSame( $status, $this->reload( $order )->get_status() );
		$this->assertSame( OrderStatus::ON_HOLD === $status ? 1 : 0, $this->count_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
	}

	/**
	 * @testdox A status change to $order_status on an order storing "$stored_status" reads the intent once and sends the $operation for the intent it read, whose transaction ID is "$transaction_id".
	 *
	 * Client 11.3.0 reads the order's stored intent whenever the stored status is neither succeeded nor canceled, and
	 * captures or cancels that intent when it awaits capture with an uncaptured succeeded charge
	 * (class-wc-payments-order-service.php:2327-2350, :2381-2418). The live intent and the capture answer are the
	 * platform's recorded manual-capture authorization and capture (Fixtures/platform-manual-capture.json).
	 *
	 * @dataProvider status_changes_on_an_order_whose_stored_status_is_not_settled
	 *
	 * @param string $operation      Capture or cancel.
	 * @param string $order_status   Status the order changes to.
	 * @param string $hook           Status hook.
	 * @param string $stored_status  Intent status the order stores.
	 * @param string $transaction_id Transaction ID the order stores.
	 */
	public function test_status_change_sends_the_operation_for_the_intent_it_read( string $operation, string $order_status, string $hook, string $stored_status, string $transaction_id ): void {
		$order = $this->create_recorded_authorization_order( $stored_status, $order_status );
		$order->set_transaction_id( $transaction_id );
		$order->save();
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertSame( array( 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' ), $reads->getArrayCopy(), 'One read of the stored intent.' );
		$this->assertSame( array( $this->operation_path( $operation ) ), $this->request_paths( $transport ), 'The operation is sent once, for the intent read.' );
		$this->assertSame( PaymentProcessingService::OPERATION_CAPTURE === $operation ? 'succeeded' : 'canceled', wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * Status changes on an order whose stored intent status is empty or not yet settled.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function status_changes_on_an_order_whose_stored_status_is_not_settled(): array {
		$rows = array();
		foreach ( $this->status_change_operation_data() as $name => $row ) {
			foreach ( array( '', 'requires_action', 'processing' ) as $stored_status ) {
				$rows[ $name . ', stored "' . $stored_status . '"' ] = array_merge( $row, array( $stored_status, 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' ) );
			}
			$rows[ $name . ', the transaction ID names an earlier attempt' ] = array_merge( $row, array( 'requires_action', 'pi_earlier_attempt' ) );
		}

		return $rows;
	}

	/**
	 * @testdox A status change to $order_status on an order storing requires_capture sends the $operation for the stored intent, not the transaction ID, with no read, retried: $retried.
	 *
	 * Client 11.3.0 captures or cancels the order's stored intent on the status change (class-wc-payments-order-service.php:2330,
	 * :2386). The order screen's actions keep the transaction ID, as its capture_charge() and cancel_authorization() do
	 * (class-wc-payment-gateway-wcpay.php:4341, :4443). The target is the stored intent, so the operation service reads
	 * nothing either: the transport receives the operation alone.
	 *
	 * @dataProvider stored_authorization_status_changes
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 * @param bool   $retried      Whether the order payment lock refuses the status change, so its retry sends the operation.
	 */
	public function test_status_change_on_the_stored_authorization_acts_on_the_stored_intent( string $operation, string $order_status, string $hook, bool $retried ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', $order_status );
		$order->set_transaction_id( 'pi_earlier_attempt' );
		$order->save();
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, $reads ) );
		$this->sut->register();

		if ( $retried ) {
			$this->while_lock_is_held(
				$order,
				function () use ( $hook, $order ): void {
					$this->fire_order_hook( $hook, $order );
				}
			);
			$this->assertSame( array(), $this->request_paths( $transport ) );
			$jobs = as_get_scheduled_actions(
				array(
					'hook'     => self::RETRY_HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			);
			$this->assertCount( 1, $jobs );
			\ActionScheduler::runner()->process_action( (int) reset( $jobs ) );
		} else {
			$this->fire_order_hook( $hook, $order );
		}

		$this->assertCount( 0, $reads, 'The stored authorization is not read.' );
		$this->assertSame( array( $this->operation_path( $operation ) ), $this->request_paths( $transport ) );
	}

	/**
	 * Status changes on the stored authorization, sent at once or by their retry.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public function stored_authorization_status_changes(): array {
		$rows = array();
		foreach ( $this->status_change_operation_data() as $name => $row ) {
			$rows[ $name ]               = array_merge( $row, array( false ) );
			$rows[ $name . ', retried' ] = array_merge( $row, array( true ) );
		}

		return $rows;
	}

	/**
	 * @testdox A status change to $order_status on an order storing requires_capture but no intent ID sends no $operation, retried: $retried.
	 *
	 * Client 11.3.0 captures or cancels on the status change only the order's stored intent, and does nothing without one
	 * (class-wc-payments-order-service.php:2330-2331, :2386-2387).
	 *
	 * @dataProvider stored_authorization_status_changes
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 * @param bool   $retried      Whether a retry of the status change runs instead of the status change.
	 */
	public function test_status_change_without_a_stored_intent_sends_nothing( string $operation, string $order_status, string $hook, bool $retried ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', $order_status );
		$order->delete_meta_data( '_intent_id' );
		$order->save();
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, $reads ) );
		$this->sut->register();

		if ( $retried ) {
			do_action( self::RETRY_HOOK, $order->get_id(), $operation, 1 );
		} else {
			$this->fire_order_hook( $hook, $order );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertCount( 0, $reads );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $order_status, $order->get_status() );
	}

	/**
	 * @testdox A status change to Completed captures the authorization and records the capture on the order already in that paid status.
	 *
	 * Client 11.3.0's status-change capture has no paid check: it captures, adds the capture note and stores the intent
	 * status, and does not complete the payment (class-wc-payments-order-service.php:2381-2429).
	 */
	public function test_status_change_to_completed_records_the_capture_on_the_completed_order(): void {
		$order     = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::COMPLETED );
		$transport = $this->create_operation_transport( 'capture' );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_completed', $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( $this->operation_path( 'capture' ) ), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $order, 'was <strong>successfully captured</strong>' );
	}

	/**
	 * @testdox A status change to $order_status on a connected store's front request sends the $operation for the stored authorization and writes its note, with the WooPayments checkout off.
	 *
	 * A fulfilment callback, XML-RPC or a plugin's cron event run from a page changes an order's status on a front request.
	 * The connected front request's classes load through the payments bootstrap, as WooCommerce loads them, with the
	 * platform on a fake transport. Client 11.3.0 hooks the same status changes on every request, gateway enabled or not
	 * (class-wc-payment-gateway-wcpay.php:783-784, class-wc-payments.php:628).
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 */
	public function test_a_connected_front_request_sends_the_operation_on_a_status_change( string $operation, string $order_status ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$transport = $this->create_operation_transport( $operation );
		$client    = new Client();
		$client->init( $transport, wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( TransportLog::class ) );
		wc_get_container()->replace( Client::class, $client );
		wc_get_container()->reset_all_resolved();
		// A warm account cache, as on a store in use: no account refresh runs, so the platform sees only the intent request.
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'        => 'acct_front_row',
					'country'           => 'US',
					'is_live'           => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
					'fraud_services'    => array( 'stripe' => array() ),
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);

		try {
			$bootstrap = new PaymentsBootstrap(
				static fn(): array => WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ][ RequestTypeUtil::FRONT ],
				static fn(): bool => false
			);
			$bootstrap->register( wc_get_container(), RequestTypeUtil::FRONT );

			wc_get_order( $order->get_id() )->update_status( $order_status );
		} finally {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}

		$is_capture = PaymentProcessingService::OPERATION_CAPTURE === $operation;
		$order      = wc_get_order( $order->get_id() );
		$this->assertSame( array( $this->operation_path( $operation ) ), $this->request_paths( $transport ), 'The status change sends exactly one request, for the stored authorization.' );
		if ( $is_capture ) {
			$this->assertSame( 1099, json_decode( (string) $transport->requests[0]['body'], true )['amount_to_capture'] ?? null, 'The capture is for the order total.' );
		}
		$this->assertSame( $order_status, $order->get_status() );
		$this->assertSame( $is_capture ? 'succeeded' : 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $is_capture ? 'was <strong>successfully captured</strong>' : 'was successfully <strong>cancelled</strong>' ) );
		$this->assertSame( $is_capture ? 1 : 0, $this->count_pending_fee_details_jobs( $order ), 'A capture queues the Fee details job; a cancel queues none.' );
	}

	/**
	 * @testdox A status change to Completed captures once and leaves the payment uncompleted, with one capture note, one Fee details job, the date paid the change set and the captured payment as the transaction ID: $_dataName.
	 *
	 * Client 11.3.0's status-change capture captures, adds its capture note once, schedules the Fee details job and stores
	 * the intent status; it never completes the payment, so the order status and the date paid are the merchant's change
	 * (class-wc-payments-order-service.php:2381-2429). The writer that completes a capture (:2193-2219, :2216) is not on
	 * this path. The client writes no transaction ID there; the built-in capture records the captured payment as the
	 * transaction ID, filling an empty one and replacing another attempt's with one warning naming both.
	 *
	 * @dataProvider status_changes_to_completed_that_capture
	 *
	 * @param string $from_status   Order status before the change.
	 * @param string $stored_status Intent status the order stores.
	 * @param bool   $retried       Whether the order payment lock refuses the change, so its retry captures.
	 * @param string $stored_txn    Transaction ID the order stores: the captured intent, none, or another attempt's.
	 */
	public function test_status_change_to_completed_captures_without_completing_the_payment( string $from_status, string $stored_status, bool $retried, string $stored_txn = self::RECORDED_INTENT ): void {
		$order     = $this->create_recorded_authorization_order_storing( $stored_txn, $stored_status, $from_status );
		$logger    = RecordingWcLogger::install();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, new \ArrayObject() ) );
		$this->sut->register();
		$hooks = $this->count_payment_completion_hooks();

		if ( $retried ) {
			$this->while_lock_is_held(
				$order,
				static function () use ( $order ): void {
					$order->update_status( OrderStatus::COMPLETED );
				}
			);
			$this->assertSame( array(), $this->request_paths( $transport ), 'The refused change sends nothing.' );
			$jobs = as_get_scheduled_actions(
				array(
					'hook'     => self::RETRY_HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			);
			$this->assertCount( 1, $jobs );
			\ActionScheduler::runner()->process_action( (int) reset( $jobs ) );
		} else {
			$order->update_status( OrderStatus::COMPLETED );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame(
			array(
				'woocommerce_pre_payment_complete' => 0,
				'woocommerce_payment_complete_order_status_completed' => 0,
				'woocommerce_payment_complete'     => 0,
			),
			$this->payment_completion_hook_fires_since( $hooks ),
			'The status-change capture fires no payment completion hook.'
		);
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CAPTURE ) ), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertNotNull( $order->get_date_paid(), 'The status change sets the date paid.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'to Completed.' ), 'One status change note.' );
		$this->assertSame( 0, $this->count_payment_complete_notes( $order ) );
		$this->assertSame( $retried ? 1 : 0, $this->count_notes( $order, $this->pending_note( PaymentProcessingService::OPERATION_CAPTURE ) ) );
		$this->assertSame( 1, $this->count_pending_fee_details_jobs( $order ) );
		$this->assertSame( self::RECORDED_INTENT, $order->get_transaction_id(), 'The captured payment is the transaction ID.' );
		$replaced = $this->replacement_lines( $logger );
		if ( '' === $stored_txn || self::RECORDED_INTENT === $stored_txn ) {
			$this->assertCount( 0, $replaced, 'Nothing different was replaced, so no line is written.' );
		} else {
			$this->assertCount( 1, $replaced, 'One line names the replaced transaction ID.' );
			$this->assertSame( $stored_txn, $replaced[0]['replaced_transaction_id'] );
			$this->assertSame( self::RECORDED_INTENT, $replaced[0]['payment_reference'] );
		}
	}

	/**
	 * Status changes to Completed that capture: the stored authorization, at once or by its retry, and an order whose
	 * stored status is not settled, whose intent is read; on an order whose transaction ID names the authorization, names
	 * nothing, or names an earlier attempt.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3?:string}>
	 */
	public function status_changes_to_completed_that_capture(): array {
		return array(
			'on hold, stored authorization'          => array( OrderStatus::ON_HOLD, 'requires_capture', false ),
			'on hold, stored authorization, retried' => array( OrderStatus::ON_HOLD, 'requires_capture', true ),
			'pending, the intent is read'            => array( OrderStatus::PENDING, 'requires_action', false ),
			'pending, the intent is read, retried'   => array( OrderStatus::PENDING, 'requires_action', true ),
			'on hold, stored authorization, no transaction ID' => array( OrderStatus::ON_HOLD, 'requires_capture', false, '' ),
			'pending, the intent is read, no transaction ID' => array( OrderStatus::PENDING, 'requires_action', false, '' ),
			'on hold, stored authorization, another attempt\'s transaction ID' => array( OrderStatus::ON_HOLD, 'requires_capture', false, 'pi_earlier_attempt' ),
		);
	}

	/**
	 * @testdox A status change to Completed whose capture note fails after the order was saved keeps the captured payment as the transaction ID, and the reconciliation reports its context saved.
	 *
	 * The order names another attempt; the capture records the captured payment in the lifecycle's save, before its note.
	 * A note callback then throws, so the reconciliation runs and finds the transaction ID already naming the payment.
	 */
	public function test_status_change_capture_whose_note_fails_after_the_save_keeps_the_captured_payment(): void {
		$order     = $this->create_recorded_authorization_order_storing( 'pi_earlier_attempt', 'requires_capture', OrderStatus::ON_HOLD );
		$logger    = RecordingWcLogger::install();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();
		$throws = 0;
		add_action(
			'woocommerce_order_note_added',
			static function ( $note_id ) use ( &$throws ): void {
				$note = get_comment( $note_id );
				if ( $note instanceof \WP_Comment && str_contains( $note->comment_content, 'was <strong>successfully captured</strong>' ) ) {
					++$throws;
					throw new RuntimeException( 'A note callback failed.' );
				}
			}
		);

		$order->update_status( OrderStatus::COMPLETED );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 1, $throws, 'The capture note reached the failing callback.' );
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CAPTURE ) ), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertSame( self::RECORDED_INTENT, $order->get_transaction_id() );
		$apply_failures = array_values( array_filter( $logger->contexts, static fn( array $context ): bool => array_key_exists( 'reconciliation_persisted', $context ) ) );
		$this->assertCount( 1, $apply_failures );
		$this->assertTrue( $apply_failures[0]['reconciliation_persisted'] );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( PaymentProcessingService::OPERATION_CAPTURE ) ) );
	}

	/**
	 * @testdox A refund made outside WP Admin on the payment a status change captured, on an order that had no transaction ID, adds a refund to the order.
	 *
	 * Client 11.3.0 finds the order by its stored charge and makes the refund row (class-wc-payments-webhook-processing-service.php:1265-1273);
	 * the built-in code owns a paid order's events by its transaction ID, which the capture now names.
	 */
	public function test_refund_made_outside_after_a_status_change_capture_without_a_transaction_id_is_the_orders_own(): void {
		$order = $this->capture_by_status_change( StoredPaymentRecords::authorization_without_transaction_id( self::RECORDED_INTENT, 'requires_capture', OrderStatus::ON_HOLD, self::RECORDED_CHARGE ) );
		$event = $this->recorded_partial_refund_event( $order, 500 );

		wc_get_container()->get( EventIngestor::class )->process( $event );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( '-5.00', $refunds[0]->get_total() );
		$this->assertSame( $event['data']['object']['refunds']['data'][0]['id'], $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was successfully processed using WooPayments' ), 'One refund note.' );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'does not pay this order' ), 'No other-charge note.' );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
	}

	/**
	 * @testdox A dispute opened on the payment a status change captured, on an order that had no transaction ID, puts the order on hold with the dispute recorded.
	 *
	 * Client 11.3.0 finds the order by its stored charge and holds it (class-wc-payments-webhook-processing-service.php:1136-1158);
	 * the built-in code owns a paid order's events by its transaction ID, which the capture now names.
	 */
	public function test_dispute_after_a_status_change_capture_without_a_transaction_id_holds_the_order(): void {
		$order = $this->capture_by_status_change( StoredPaymentRecords::authorization_without_transaction_id( self::RECORDED_INTENT, 'requires_capture', OrderStatus::ON_HOLD, self::RECORDED_CHARGE ) );
		$event = RecordedPlatformEvents::body( 'platform-dispute-created-events.json', 'accept_case_created' );

		$event['data']['object']['charge']         = self::RECORDED_CHARGE;
		$event['data']['object']['payment_intent'] = self::RECORDED_INTENT;
		$event['data']['object']['amount']         = 1099;

		wc_get_container()->get( EventIngestor::class )->process( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertContains( $event['data']['object']['id'], (array) $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Payment has been disputed' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'does not pay this order' ), 'No other-charge note.' );
	}

	/**
	 * @testdox After a status change to Completed replaced another attempt's transaction ID, a payment success is the order's own only for the captured payment: $label.
	 *
	 * The captured payment's success changes nothing a second time; an earlier attempt's late success is recorded as
	 * another charge and leaves the stored intent and charge on the captured payment.
	 *
	 * @testWith ["the captured payment's success, delivered twice", "pi_3UJhOgBzWlxcwgpP0AOgQE2z", "ch_3UJhOgBzWlxcwgpP0NcK21bQ", 2, 0]
	 *           ["the earlier attempt's late success", "pi_earlier_attempt", "ch_earlier_attempt", 1, 1]
	 *
	 * @param string $label               Row label.
	 * @param string $intent_id           Intent whose success is delivered.
	 * @param string $charge_id           Its charge.
	 * @param int    $deliveries          How many times the event is delivered.
	 * @param int    $other_charge_notes  Other-charge notes expected.
	 */
	public function test_payment_success_after_a_status_change_capture_replaced_another_attempt( string $label, string $intent_id, string $charge_id, int $deliveries, int $other_charge_notes ): void {
		unset( $label );
		$order = $this->capture_by_status_change( StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_earlier_attempt', self::RECORDED_INTENT, 'requires_capture', OrderStatus::ON_HOLD, self::RECORDED_CHARGE ) );
		$event = $this->recorded_payment_success_event( $order, $intent_id, $charge_id );

		for ( $i = 0; $i < $deliveries; $i++ ) {
			wc_get_container()->get( EventIngestor::class )->process( $event );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $other_charge_notes, $this->count_notes_containing( $order, 'does not pay this order' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ), 'The capture note only.' );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'was <strong>successfully charged</strong>' ), 'No success note.' );
		$this->assertSame( self::RECORDED_INTENT, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RECORDED_CHARGE, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( self::RECORDED_INTENT, $order->get_transaction_id() );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
	}

	/**
	 * @testdox After a status change to Completed replaced another attempt's transaction ID, the authorizations list refuses to capture that attempt, before any platform request.
	 *
	 * The list acts only on the intent the transaction ID names, which is the captured payment.
	 */
	public function test_authorizations_list_refuses_to_capture_an_earlier_attempt_after_a_status_change_capture(): void {
		$order      = $this->capture_by_status_change( StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_earlier_attempt', self::RECORDED_INTENT, 'requires_capture', OrderStatus::ON_HOLD, self::RECORDED_CHARGE ) );
		$api_client = $this->createMock( Client::class );
		$api_client->expects( $this->never() )->method( $this->anything() );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( $this->anything() );
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $api_client, $processing, new WooPaymentsProvider() );
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_earlier_attempt' );

		$response = $controller->capture_authorization( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'wcpay_intent_order_mismatch', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}

	/**
	 * Capture the recorded authorization by changing the order to Completed, then put the store in test mode, the mode the
	 * recorded events were delivered in.
	 *
	 * @param WC_Order $order Authorized order on the recorded intent.
	 * @return WC_Order The order read again after the capture.
	 */
	private function capture_by_status_change( WC_Order $order ): WC_Order {
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		$order->update_status( OrderStatus::COMPLETED );

		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CAPTURE ) ), $this->request_paths( $transport ) );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A charge.refunded event the platform forwarded (Fixtures/platform-charge-dispute-events.json), pointed at the order's
	 * stored charge and intent, for a partial refund of the given amount.
	 *
	 * @param WC_Order $order         Order.
	 * @param int      $refund_amount Refund amount in minor units.
	 * @return array<string,mixed>
	 */
	private function recorded_partial_refund_event( WC_Order $order, int $refund_amount ): array {
		$event  = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', 'card_charge_refunded_in_full' );
		$charge = &$event['data']['object'];

		$charge['id']                           = (string) $order->get_meta( '_charge_id', true );
		$charge['payment_intent']               = (string) $order->get_meta( '_intent_id', true );
		$charge['metadata']['order_id']         = (string) $order->get_id();
		$charge['metadata']['order_key']        = $order->get_order_key();
		$charge['amount_refunded']              = $refund_amount;
		$charge['refunded']                     = false;
		$charge['refunds']['data'][0]['charge'] = $charge['id'];
		$charge['refunds']['data'][0]['amount'] = $refund_amount;
		unset( $charge );

		return $event;
	}

	/**
	 * A payment_intent.succeeded event the platform forwarded (Fixtures/platform-payment-intent-events.json), for the given
	 * manual-capture intent and its charge, whose metadata names the order.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 * @param string   $charge_id Charge ID.
	 * @return array<string,mixed>
	 */
	private function recorded_payment_success_event( WC_Order $order, string $intent_id, string $charge_id ): array {
		$event  = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'card_checkout_succeeded' );
		$intent = &$event['data']['object'];

		$event['id']                                    = 'evt_' . substr( $intent_id, 3 );
		$intent['id']                                   = $intent_id;
		$intent['capture_method']                       = 'manual';
		$intent['latest_charge']                        = $charge_id;
		$intent['charges']['data'][0]['id']             = $charge_id;
		$intent['charges']['data'][0]['payment_intent'] = $intent_id;
		$intent['metadata']['order_id']                 = (string) $order->get_id();
		$intent['metadata']['order_key']                = $order->get_order_key();
		unset( $intent );

		return $event;
	}

	/**
	 * @testdox A capture outside the status change completes the payment as before: $label.
	 *
	 * Client 11.3.0's capture writer completes the payment (class-wc-payments-order-service.php:2193-2219, :2216). The
	 * REST authorization capture reaches it on any order status (admin/class-wc-rest-payments-orders-controller.php:368-406),
	 * and the order screen's capture on an On-hold order (class-wc-payment-gateway-wcpay.php:4408).
	 *
	 * @testWith ["REST capture of a Completed order", "rest", "completed", 1, 1, 0, 0]
	 *           ["order screen capture of an On-hold order", "order_screen", "on-hold", 1, 0, 1, 1]
	 *
	 * @param string $label                   Row label.
	 * @param string $trigger                 Capture path: `rest` or `order_screen`.
	 * @param string $from_status             Order status before the capture.
	 * @param int    $pre_payment_complete    Expected woocommerce_pre_payment_complete fires.
	 * @param int    $completed_status_action Expected woocommerce_payment_complete_order_status_completed fires.
	 * @param int    $payment_complete        Expected woocommerce_payment_complete fires.
	 * @param int    $payment_note            Expected payment complete notes.
	 */
	public function test_capture_outside_the_status_change_completes_the_payment( string $label, string $trigger, string $from_status, int $pre_payment_complete, int $completed_status_action, int $payment_complete, int $payment_note ): void {
		unset( $label );
		$order     = $this->create_recorded_authorization_order( 'requires_capture', $from_status );
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$provider  = $this->create_provider_over_fake_transport( $transport );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider, $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();
		$hooks = $this->count_payment_completion_hooks();

		if ( 'rest' === $trigger ) {
			// The REST authorization capture's call (WooPaymentsAuthorizationsRestController::handle_authorization_action()).
			wc_get_container()->get( PaymentProcessingService::class )->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		} else {
			$this->fire_order_hook( 'woocommerce_order_action_capture_charge', $order );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame(
			array(
				'woocommerce_pre_payment_complete' => $pre_payment_complete,
				'woocommerce_payment_complete_order_status_completed' => $completed_status_action,
				'woocommerce_payment_complete'     => $payment_complete,
			),
			$this->payment_completion_hook_fires_since( $hooks )
		);
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CAPTURE ) ), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status(), 'An order with nothing to process completes.' );
		$this->assertNotNull( $order->get_date_paid() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( $payment_note, $this->count_payment_complete_notes( $order ) );
	}

	/**
	 * @testdox A capture stamps the fraud box from the stored outcome for every payment method: $_dataName.
	 *
	 * The capture answers the recorded capture (Fixtures/platform-manual-capture.json) with the authorization's fraud
	 * outcome; the Amazon Pay rows give its charge Stripe's `amazon_pay` payment method details. The order holds what the
	 * client's authorized or held-for-review writer leaves (class-wc-payments-order-service.php:2153-2156, :2262-2264).
	 * Client 11.3.0's order screen capture reaches its capture writer, which stamps `review_allowed` when the stored outcome
	 * is review and `allow` otherwise, for every payment method (class-wc-payment-gateway-wcpay.php:4408;
	 * class-wc-payments-order-service.php:529-530, :2209-2213). The status-change capture writes the same record as that
	 * writer; the client's status-change capture writes no fraud box (:2381-2429), so there an allowed authorization keeps
	 * `allow` and one held for review keeps `review`.
	 *
	 * @dataProvider provider_captures_and_their_fraud_box
	 *
	 * @param string $trigger             Capture path: `order_screen` or `status_change`.
	 * @param string $payment_method_type Payment method type of the charge.
	 * @param string $stored_outcome      Fraud outcome the authorization stored.
	 * @param string $expected_box        Fraud meta box type after the capture.
	 */
	public function test_capture_stamps_the_fraud_box_from_the_stored_outcome( string $trigger, string $payment_method_type, string $stored_outcome, string $expected_box ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$order->update_meta_data( '_wcpay_fraud_outcome_status', $stored_outcome );
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', $stored_outcome );
		$captured                              = $this->load_recorded_manual_capture_entry( 'capture_full_amount' )['body'];
		$captured['metadata']['fraud_outcome'] = $stored_outcome;
		if ( 'amazon_pay' === $payment_method_type ) {
			$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay' );
			$captured['payment_method_types']                         = array( 'amazon_pay' );
			$captured['payment_method_options']                       = array( 'amazon_pay' => array() );
			$captured['charges']['data'][0]['payment_method_details'] = array(
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
		$order->save();
		$transport           = new FakeHttpClient();
		$transport->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $captured ),
		);
		$this->sut           = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		if ( 'status_change' === $trigger ) {
			$order->update_status( OrderStatus::COMPLETED );
		} else {
			$this->fire_order_hook( 'woocommerce_order_action_capture_charge', $order );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CAPTURE ) ), $this->request_paths( $transport ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $expected_box, $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
	}

	/**
	 * Captures, the payment method and stored outcome, and the fraud box each leaves.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function provider_captures_and_their_fraud_box(): array {
		return array(
			'order screen, card, allowed'                => array( 'order_screen', 'card', 'allow', 'allow' ),
			'order screen, card, held for review'        => array( 'order_screen', 'card', 'review', 'review_allowed' ),
			'order screen, Amazon Pay, allowed'          => array( 'order_screen', 'amazon_pay', 'allow', 'allow' ),
			'order screen, Amazon Pay, held for review'  => array( 'order_screen', 'amazon_pay', 'review', 'review_allowed' ),
			'status change, card, allowed'               => array( 'status_change', 'card', 'allow', 'allow' ),
			'status change, Amazon Pay, allowed'         => array( 'status_change', 'amazon_pay', 'allow', 'allow' ),
			'status change, card, held for review'       => array( 'status_change', 'card', 'review', 'review_allowed' ),
			'status change, Amazon Pay, held for review' => array( 'status_change', 'amazon_pay', 'review', 'review_allowed' ),
		);
	}

	/**
	 * @testdox A cancel stores the canceled intent's fraud outcome as blocked after review only from the order screen: $_dataName.
	 *
	 * The cancel answers the recorded canceled authorization (Fixtures/platform-payment-intent-events.json,
	 * in_person_canceled) with the row's fraud outcome in its metadata; a re-read row first answers Stripe's error for an
	 * intent that is already canceled (https://docs.stripe.com/error-codes#payment-intent-unexpected-state), then the
	 * canceled intent. The order holds what the client's authorized or held-for-review writer leaves
	 * (class-wc-payments-order-service.php:2153-2156, :2262-2264), nothing for a block. Client 11.3.0's order screen cancel
	 * reaches its cancelled-authorization writer, with the cancel answer or the re-read intent, which stores a block or
	 * review outcome of that intent with the review_blocked box (class-wc-payment-gateway-wcpay.php:4436-4468;
	 * class-wc-payments-order-service.php:480-481, :2009-2014). Its status-change cancel writes no fraud meta (:2327-2369).
	 *
	 * @dataProvider provider_cancels_and_their_fraud_box
	 *
	 * @param string $trigger             Cancel path: `order_screen` or `status_change`.
	 * @param string $payment_method_type Payment method type of the authorization.
	 * @param string $intent_outcome      Fraud outcome in the canceled intent's metadata.
	 * @param bool   $re_read             Whether the cancel is refused and the re-read intent answers it.
	 * @param string $expected_outcome    Fraud outcome status after the cancel.
	 * @param string $expected_box        Fraud meta box type after the cancel.
	 */
	public function test_cancel_stores_the_fraud_box_of_a_blocked_authorization( string $trigger, string $payment_method_type, string $intent_outcome, bool $re_read, string $expected_outcome, string $expected_box ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		if ( 'block' !== $intent_outcome ) {
			$order->update_meta_data( '_wcpay_fraud_outcome_status', $intent_outcome );
			$order->update_meta_data( '_wcpay_fraud_meta_box_type', $intent_outcome );
		}
		$canceled                              = array_merge( $this->recorded_intent( 'canceled_authorization' ), array( 'id' => 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' ) );
		$canceled['metadata']['fraud_outcome'] = $intent_outcome;
		$canceled['payment_method_types']      = array( $payment_method_type );
		if ( 'amazon_pay' === $payment_method_type ) {
			$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay' );
		}
		$order->save();
		$answer    = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $canceled ),
		);
		$transport = new FakeHttpClient();
		if ( $re_read ) {
			$transport->responses = array(
				array(
					'response' => array( 'code' => 400 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'type'    => 'invalid_request_error',
								'code'    => 'payment_intent_unexpected_state',
								'message' => 'You cannot cancel this PaymentIntent because it has a status of canceled.',
							),
						)
					),
				),
				$answer,
			);
		} else {
			$transport->response = $answer;
		}
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		if ( 'status_change' === $trigger ) {
			$order->update_status( OrderStatus::CANCELLED );
		} else {
			$this->fire_order_hook( 'woocommerce_order_action_cancel_authorization', $order );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $this->operation_path( PaymentProcessingService::OPERATION_CANCEL ), $this->request_paths( $transport )[0] ?? '' );
		$this->assertCount( $re_read ? 2 : 1, $transport->requests );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $expected_outcome, $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
		$this->assertSame( $expected_box, $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
	}

	/**
	 * Cancels, the payment method and the intent's outcome, and the fraud outcome and box each leaves.
	 *
	 * @return array<string,array{string,string,string,bool,string,string}>
	 */
	public function provider_cancels_and_their_fraud_box(): array {
		return array(
			'order screen, card, held for review'          => array( 'order_screen', 'card', 'review', false, 'review', 'review_blocked' ),
			'order screen, card, blocked'                  => array( 'order_screen', 'card', 'block', false, 'block', 'review_blocked' ),
			'order screen, card, allowed'                  => array( 'order_screen', 'card', 'allow', false, 'allow', 'allow' ),
			'order screen, Amazon Pay, held for review'    => array( 'order_screen', 'amazon_pay', 'review', false, 'review', 'review_blocked' ),
			'order screen, Amazon Pay, allowed'            => array( 'order_screen', 'amazon_pay', 'allow', false, 'allow', 'allow' ),
			'order screen, card, held for review, re-read' => array( 'order_screen', 'card', 'review', true, 'review', 'review_blocked' ),
			'status change, card, held for review'         => array( 'status_change', 'card', 'review', false, 'review', 'review' ),
			'status change, Amazon Pay, held for review'   => array( 'status_change', 'amazon_pay', 'review', false, 'review', 'review' ),
			'status change, card, allowed'                 => array( 'status_change', 'card', 'allow', false, 'allow', 'allow' ),
			'status change, card, held for review, re-read' => array( 'status_change', 'card', 'review', true, 'review', 'review' ),
		);
	}

	/**
	 * @testdox The order screen's $operation action on an order storing requires_capture acts on the transaction ID, read first when it is not the stored intent.
	 *
	 * Client 11.3.0's capture_charge() and cancel_authorization() act on the order's transaction ID
	 * (class-wc-payment-gateway-wcpay.php:4341, :4443). The built-in store reads a transaction ID other than the stored
	 * intent first, and sends the operation because the platform's record names this order.
	 *
	 * @testWith ["capture", "woocommerce_order_action_capture_charge"]
	 *           ["cancel", "woocommerce_order_action_cancel_authorization"]
	 *
	 * @param string $operation Capture or cancel.
	 * @param string $action    Order action hook.
	 */
	public function test_order_screen_action_acts_on_the_transaction_id( string $operation, string $action ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$order->set_transaction_id( 'pi_earlier_attempt' );
		$order->save();
		$transport            = $this->create_operation_transport( $operation );
		$transport->responses = array(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'id'       => 'pi_earlier_attempt',
						'status'   => 'requires_capture',
						'metadata' => array( 'order_id' => (string) $order->get_id() ),
					)
				),
			),
		);
		$this->sut            = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( $action, wc_get_order( $order->get_id() ) );

		$this->assertSame( array( 'intentions/pi_earlier_attempt?test_mode=1', 'intentions/pi_earlier_attempt/' . $operation ), $this->request_paths( $transport ) );
	}

	/**
	 * @testdox An operation refused because its intent is not shown to be the order's adds the edit screen error only from the order screen: $_dataName.
	 *
	 * The refusal carries its note and error message, so no generic failure note is added and no retry is scheduled. The
	 * error is core's after-save error for edit screens, shown on the reload (WC_Admin_Meta_Boxes::add_error()). Client
	 * 11.3.0 sends these operations with no check (class-wc-payment-gateway-wcpay.php:4333-4350, :4436-4448).
	 *
	 * @dataProvider provider_operation_outcomes_and_their_edit_screen_error
	 *
	 * @param string $hook         Order action or status hook.
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order is saved with.
	 * @param bool   $refused      Whether the provider refuses the operation as a target of another order.
	 * @param bool   $adds_error   Whether the edit screen error is added.
	 */
	public function test_refused_target_of_another_order_adds_the_edit_screen_error( string $hook, string $operation, string $order_status, bool $refused, bool $adds_error ): void {
		set_current_screen( 'edit-shop_order' );
		\WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$message                               = 'WooPayments did not capture this payment: the transaction ID names a payment of another order. See the order notes.';
		$outcome                               = $refused
			? new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => WooPaymentsPaymentOperationService::ERROR_OPERATION_TARGET_OF_ANOTHER_ORDER,
					PaymentOutcome::DATA_ERROR_MESSAGE => $message,
					'operation'                        => $operation,
					PaymentOutcome::DATA_NOTE          => 'WooPayments did not capture this payment.',
				)
			)
			: new PaymentOutcome( PaymentProcessingService::OPERATION_CAPTURE === $operation ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_CANCELED, 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order                                 = $this->create_recorded_authorization_order( 'requires_capture', $order_status );
		$this->sut                             = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_fixed_outcome_provider( $outcome ) );
		$this->sut->register();

		try {
			$this->fire_order_hook( $hook, wc_get_order( $order->get_id() ) );
			$errors = \WC_Admin_Meta_Boxes::$meta_box_errors;
		} finally {
			\WC_Admin_Meta_Boxes::$meta_box_errors = array();
		}

		$this->assertSame( $adds_error ? array( $message ) : array(), $errors );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
	}

	/**
	 * Order screen actions and status changes, refused as a target of another order or sent.
	 *
	 * @return array<string,array{string,string,string,bool,bool}>
	 */
	public function provider_operation_outcomes_and_their_edit_screen_error(): array {
		return array(
			'order screen capture, refused'       => array( 'woocommerce_order_action_capture_charge', 'capture', OrderStatus::ON_HOLD, true, true ),
			'order screen cancel, refused'        => array( 'woocommerce_order_action_cancel_authorization', 'cancel', OrderStatus::ON_HOLD, true, true ),
			'order screen capture, sent'          => array( 'woocommerce_order_action_capture_charge', 'capture', OrderStatus::ON_HOLD, false, false ),
			'status change to Completed, refused' => array( 'woocommerce_order_status_completed', 'capture', OrderStatus::COMPLETED, true, false ),
			'status change to Cancelled, refused' => array( 'woocommerce_order_status_cancelled', 'cancel', OrderStatus::CANCELLED, true, false ),
		);
	}

	/**
	 * @testdox The order screen's $operation on a transaction ID outside the intent form sends nothing and adds the failure note once.
	 *
	 * Client 11.3.0 refuses the ID before any request (core/server/request/class-capture-intention.php:42-45,
	 * class-cancel-intention.php:40-43), and its order screen action then ends on the critical-error screen; here the
	 * save completes with the generic failure note, and the order keeps its status, intent and intent status.
	 *
	 * @testWith ["capture", "woocommerce_order_action_capture_charge"]
	 *           ["cancel", "woocommerce_order_action_cancel_authorization"]
	 *
	 * @param string $operation Capture or cancel.
	 * @param string $action    Order action hook.
	 */
	public function test_order_screen_action_on_a_transaction_id_outside_the_form_sends_nothing( string $operation, string $action ): void {
		$order = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$order->set_transaction_id( 'ORDER123' );
		$order->save();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( $action, wc_get_order( $order->get_id() ) );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 0, $transport->request_count );
		$this->assertSame( 1, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( OrderStatus::ON_HOLD, $fresh->get_status() );
		$this->assertSame( 'pi_3UJhOgBzWlxcwgpP0AOgQE2z', $fresh->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_capture', $fresh->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox A status change to $order_status whose read intent ID is outside the intent form sends no $operation and keeps the stored intent status.
	 *
	 * The order stores processing, so the status change reads the intent; the read here answers the recorded
	 * authorization (Fixtures/platform-manual-capture.json). The refused operation writes nothing over the stored status.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 */
	public function test_status_change_on_a_read_intent_id_outside_the_form_keeps_the_stored_status( string $operation, string $order_status, string $hook ): void {
		$order = $this->create_recorded_authorization_order( 'processing', $order_status );
		$order->set_transaction_id( 'PI_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intent_id', 'PI_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->save();
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertSame( array( 'PI_3UJhOgBzWlxcwgpP0AOgQE2z' ), $reads->getArrayCopy() );
		$this->assertSame( 0, $transport->request_count );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * @testdox A $operation on the status change is checked against the order read under its own lock: $_dataName.
	 *
	 * Client 11.3.0 acts on the intent it read (class-wc-payments-order-service.php:2327-2350, :2381-2418). Here the order
	 * changes after the read and before the operation's lock: the operation is refused when the order no longer stores the
	 * intent read (and retried, with a new read) or stores a settled status, and otherwise acts on the read.
	 *
	 * @dataProvider orders_changed_between_the_read_and_the_lock
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 * @param array  $change       Meta another writer stores between the read and the lock.
	 * @param bool   $sends        Whether the operation is sent.
	 * @param bool   $retries      Whether a retry is scheduled.
	 */
	public function test_status_change_operation_is_checked_against_the_order_under_its_lock( string $operation, string $order_status, string $hook, array $change, bool $sends, bool $retries ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_action', $order_status );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$platform  = $this->create_platform(
			$this->recorded_intent( 'authorize_manual_capture' ),
			null,
			$reads,
			static function () use ( $order, $change ): void {
				$changed = wc_get_order( $order->get_id() );
				foreach ( $change as $key => $value ) {
					$changed->update_meta_data( $key, $value );
				}
				$changed->save_meta_data();
			}
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $platform );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertCount( 1, $reads );
		$this->assertSame( $sends ? array( $this->operation_path( $operation ) ) : array(), $this->request_paths( $transport ) );
		$this->assertSame( $retries ? array( 1 ) : array(), $this->get_pending_retries( $order ) );
		$this->assertSame( $retries ? 1 : 0, $this->count_notes( $order, $this->pending_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		if ( ! $sends ) {
			$stored = wc_get_order( $order->get_id() );
			foreach ( $change as $key => $value ) {
				$this->assertSame( $value, $stored->get_meta( $key, true ), "A refused operation leaves {$key} as the other writer stored it." );
			}
		}
	}

	/**
	 * Changes another writer stores between the status change's read and the operation's lock.
	 *
	 * @return array<string,array{string,string,string,array<string,string>,bool,bool}>
	 */
	public function orders_changed_between_the_read_and_the_lock(): array {
		$rows = array();
		foreach ( $this->status_change_operation_data() as $name => $row ) {
			$settled = PaymentProcessingService::OPERATION_CAPTURE === $row[0] ? 'succeeded' : 'canceled';
			$rows[ $name . ', the order stores another intent' ]           = array_merge(
				$row,
				array(
					array(
						'_intent_id'        => 'pi_newer_attempt',
						'_intention_status' => 'requires_action',
					),
					false,
					true,
				)
			);
			$rows[ $name . ', the order stores a settled status' ]         = array_merge( $row, array( array( '_intention_status' => $settled ), false, false ) );
			$rows[ $name . ', the order stores another unsettled status' ] = array_merge( $row, array( array( '_intention_status' => 'processing' ), true, false ) );
			$rows[ $name . ', the order stores the authorization' ]        = array_merge( $row, array( array( '_intention_status' => 'requires_capture' ), true, false ) );
		}

		return $rows;
	}

	/**
	 * @testdox A $operation on the status change of an order storing the authorization is checked against the order read under its own lock: $_dataName.
	 *
	 * The status hook's order object is loaded before another request changes the order, and the operation claims the
	 * order payment lock after. Client 11.3.0 acts on the order as its hook read it, with no lock
	 * (class-wc-payments-order-service.php:2327-2350, :2381-2418). Here the order read under the lock decides: a capture
	 * is not sent once another payment method took the order over, and neither operation is sent once the order stores a
	 * settled status or another intent (retried, so it follows the new intent). A cancel on a taken-over order still goes,
	 * since it takes no money.
	 *
	 * @dataProvider stored_authorizations_changed_between_the_hook_and_the_lock
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 * @param array  $change       Payment method or meta another request stores between the hook and the lock.
	 * @param bool   $sends        Whether the operation is sent for the authorization the hook found.
	 * @param string $refusal      The refusal value the status change logs, or empty for none.
	 */
	public function test_status_change_on_the_stored_authorization_is_checked_against_the_order_under_its_lock( string $operation, string $order_status, string $hook, array $change, bool $sends, string $refusal ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_capture', $order_status );
		$notes     = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$settled   = $change['_intention_status'] ?? '';
		if ( in_array( $settled, array( 'succeeded', 'canceled' ), true ) ) {
			// A sent operation meets the platform's refusal and the read after it, as on a live store.
			$transport->responses = array(
				self::platform_refusal( PaymentProcessingService::OPERATION_CAPTURE === $operation ? 'This PaymentIntent could not be captured because it has already been captured.' : 'You cannot cancel this PaymentIntent because it has a status of ' . $settled . '.' ),
				self::status_answer( self::RECORDED_INTENT, $settled ),
			);
		}
		$logger    = RecordingWcLogger::install();
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, $reads ) );
		$this->sut->register();
		$this->change_the_order_before_its_status_hook( $hook, $change );

		$this->fire_order_hook( $hook, $order );

		$retries = 'intent_changed' === $refusal;
		$lines   = array_values( array_filter( $logger->contexts, static fn( array $context ): bool => isset( $context['refusal'] ) ) );
		$this->assertCount( 0, $reads, 'The stored authorization is not read.' );
		$this->assertSame( $sends ? array( $this->operation_path( $operation ) ) : array(), $this->request_paths( $transport ) );
		$this->assertSame( '' === $refusal ? array() : array( $refusal ), array_column( $lines, 'refusal' ), 'One warning line names the refusal.' );
		$this->assertSame( $retries ? array( 1 ) : array(), $this->get_pending_retries( $order ) );
		$this->assertSame( $retries ? 1 : 0, $this->count_notes( $order, $this->pending_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'failed' ), 'No failure note of any kind.' );
		$stored = wc_get_order( $order->get_id() );
		if ( '' !== $refusal ) {
			$this->assertSame( $notes + ( $retries ? 1 : 0 ), count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ), 'A refusal adds no note but the pending note of a retry.' );
			$expected = array_merge(
				array(
					'payment_method'    => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'_intent_id'        => self::RECORDED_INTENT,
					'_intention_status' => 'requires_capture',
				),
				$change
			);
			$actual   = array(
				'payment_method'    => $stored->get_payment_method(),
				'_intent_id'        => $stored->get_meta( '_intent_id', true ),
				'_intention_status' => $stored->get_meta( '_intention_status', true ),
			);
			$this->assertSame( $expected, $actual, 'A refusal leaves the payment record as the other request stored it, its intent included.' );
		}
		if ( ! $retries ) {
			return;
		}

		$jobs = as_get_scheduled_actions(
			array(
				'hook'     => self::RETRY_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		$this->assertCount( 1, $jobs );
		$this->assertArrayNotHasKey( 'intent_id', reset( $jobs )->get_args(), 'The retry decides again on the stored authorization, with no read intent.' );
		$transport->response['body'] = str_replace( self::RECORDED_INTENT, 'pi_newer_attempt', (string) $transport->response['body'] );
		\ActionScheduler::runner()->process_action( (int) array_key_first( $jobs ) );

		$this->assertCount( 0, $reads );
		$this->assertSame( array( 'intentions/pi_newer_attempt/' . $operation ), $this->request_paths( $transport ), 'One retry later, the operation follows the intent the order now stores.' );
	}

	/**
	 * Payment methods and meta another request stores between a status hook and the operation's lock, on an order storing
	 * the authorization: whether the operation is sent for that authorization, and the refusal logged.
	 *
	 * @return array<string,array{string,string,string,array<string,string>,bool,string}>
	 */
	public function stored_authorizations_changed_between_the_hook_and_the_lock(): array {
		$operations = $this->status_change_operation_data();
		$capture    = $operations['capture'];
		$cancel     = $operations['cancel'];
		$taken_over = array( 'payment_method' => 'cod' );
		$newer      = array(
			'_intent_id'        => 'pi_newer_attempt',
			'_intention_status' => 'requires_capture',
		);
		$unchanged  = array( '_intention_status' => 'requires_capture' );

		return array(
			'capture, another payment method took the order over' => array_merge( $capture, array( $taken_over, false, 'payment_method_changed' ) ),
			'cancel, another payment method took the order over' => array_merge( $cancel, array( $taken_over, true, '' ) ),
			'capture, the order stores a settled status' => array_merge( $capture, array( array( '_intention_status' => 'succeeded' ), false, 'intent_settled' ) ),
			'cancel, the order stores a settled status'  => array_merge( $cancel, array( array( '_intention_status' => 'canceled' ), false, 'intent_settled' ) ),
			'cancel, a success event stored succeeded'   => array_merge( $cancel, array( array( '_intention_status' => 'succeeded' ), false, 'intent_settled' ) ),
			'capture, the order stores another authorization' => array_merge( $capture, array( $newer, false, 'intent_changed' ) ),
			'cancel, the order stores another authorization' => array_merge( $cancel, array( $newer, false, 'intent_changed' ) ),
			'capture, the order still stores the authorization' => array_merge( $capture, array( $unchanged, true, '' ) ),
			'cancel, the order still stores the authorization' => array_merge( $cancel, array( $unchanged, true, '' ) ),
		);
	}

	/**
	 * @testdox A $operation on the status change of an order whose intent was read is checked against the payment method of the order read under its own lock.
	 *
	 * Another payment method takes the order over between the status change's read and the operation's lock. The capture
	 * is not sent; the cancel takes no money, so it goes as before.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 */
	public function test_status_change_on_a_read_intent_is_refused_once_another_payment_method_took_the_order_over( string $operation, string $order_status, string $hook ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_action', $order_status );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$platform  = $this->create_platform(
			$this->recorded_intent( 'authorize_manual_capture' ),
			null,
			$reads,
			static function () use ( $order ): void {
				$changed = wc_get_order( $order->get_id() );
				$changed->set_payment_method( 'cod' );
				$changed->save();
			}
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $platform );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$is_capture = PaymentProcessingService::OPERATION_CAPTURE === $operation;
		$this->assertCount( 1, $reads );
		$this->assertSame( $is_capture ? array() : array( $this->operation_path( $operation ) ), $this->request_paths( $transport ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->pending_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( 'cod', wc_get_order( $order->get_id() )->get_payment_method() );
	}

	/**
	 * @testdox A status change to Completed saved on an order another payment method took over after its load sends no capture.
	 *
	 * Core's own status save: another request takes the order over after the save and before the capture's lock. On the
	 * posts store the save reads the meta again before the hook, so the change lands at the hook, as in a live store.
	 */
	public function test_a_status_change_saved_on_an_order_another_payment_method_took_over_sends_nothing(): void {
		$order     = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();
		$this->change_the_order_before_its_status_hook( 'woocommerce_order_status_completed', array( 'payment_method' => 'cod' ) );

		wc_get_order( $order->get_id() )->update_status( OrderStatus::COMPLETED );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertSame( array( 'cod', OrderStatus::COMPLETED, 'requires_capture' ), array( $order->get_payment_method(), $order->get_status(), $order->get_meta( '_intention_status', true ) ) );
	}

	/**
	 * @testdox A change to Cancelled releases the WooPayments authorization an order kept when another payment method took it over: $_dataName.
	 *
	 * The takeover kept the authorization the order stores as requires_capture. Client 11.3.0 cancels it on the change to
	 * Cancelled whatever the order's payment method (class-wc-payments-order-service.php:2327-2359). Only WooPayments's own
	 * values change; the other payment method's are left as they were.
	 *
	 * @dataProvider orders_another_payment_method_took_over
	 *
	 * @param string $payment_method Payment method that took the order over.
	 * @param string $transaction_id Order transaction ID after the takeover.
	 */
	public function test_cancelled_releases_the_authorization_another_payment_method_kept( string $payment_method, string $transaction_id ): void {
		$order     = $this->create_taken_over_order( $payment_method, $transaction_id, 'requires_capture', OrderStatus::CANCELLED );
		$meta      = $this->stored_meta( $order );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$this->assertCount( 0, $reads, 'The stored authorization is not read.' );
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CANCEL ) ), $this->request_paths( $transport ) );
		$this->assert_only_the_woopayments_values_changed( $order, $meta, $payment_method, $transaction_id );
	}

	/**
	 * @testdox A change to Cancelled reads a payment still processing that an order kept when another payment method took it over, and releases it once it is an authorization.
	 *
	 * The takeover kept the payment the order stores as processing. Client 11.3.0 reads it and cancels a live authorization
	 * (class-wc-payments-order-service.php:2327-2359).
	 */
	public function test_cancelled_reads_and_releases_a_processing_payment_another_payment_method_kept(): void {
		$order     = $this->create_taken_over_order( 'cheque', self::RECORDED_INTENT, 'processing', OrderStatus::CANCELLED );
		$meta      = $this->stored_meta( $order );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$this->assertSame( array( self::RECORDED_INTENT ), $reads->getArrayCopy() );
		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CANCEL ) ), $this->request_paths( $transport ) );
		$this->assert_only_the_woopayments_values_changed( $order, $meta, 'cheque', self::RECORDED_INTENT );
	}

	/**
	 * @testdox A change to Cancelled on an order Check payments took over writes the cancelled note naming WooPayments and Check payments.
	 *
	 * Client 11.3.0 writes "Payment authorization was successfully cancelled" there (class-wc-payments-order-service.php:2862),
	 * which does not say whose authorization it was, and the other gateway may show a payment intent too.
	 */
	public function test_cancelled_note_names_the_woopayments_authorization_on_an_order_another_payment_method_took_over(): void {
		$order = $this->create_taken_over_order( 'cheque', self::RECORDED_INTENT, 'requires_capture', OrderStatus::CANCELLED );
		$order->set_payment_method_title( 'Check payments' );
		$order->save();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CANCEL ) ), $this->request_paths( $transport ) );
		$this->assertSame( 1, $this->count_notes( $order, 'The earlier WooPayments payment authorization was successfully <strong>cancelled</strong> (' . $this->recorded_intent_link() . '). The order&#039;s payment by Check payments was not changed.' ) );
		$this->assertSame( 0, $this->count_notes( $order, 'Payment authorization was successfully <strong>cancelled</strong> (' . $this->recorded_intent_link() . ').' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was successfully <strong>cancelled</strong>' ) );
	}

	/**
	 * @testdox A change to Cancelled does not repeat the cancelled note the extension already wrote on an order another payment method took over.
	 *
	 * The extension's request can die between its note and its save (class-wc-payments-order-service.php:2351, :2359), which
	 * leaves the order storing the authorization with the note on it and no note identity. The old wording stays an
	 * equivalent of the new one, so the note is found by its text.
	 */
	public function test_cancelled_does_not_repeat_the_extensions_cancelled_note_on_an_order_another_payment_method_took_over(): void {
		$order = $this->create_taken_over_order( 'cheque', self::RECORDED_INTENT, 'requires_capture', OrderStatus::CANCELLED );
		$order->set_payment_method_title( 'Check payments' );
		$order->save();
		$order->add_order_note( 'Payment authorization was successfully <strong>cancelled</strong> (' . $this->recorded_intent_link() . ').' );
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$this->assertSame( array( $this->operation_path( PaymentProcessingService::OPERATION_CANCEL ) ), $this->request_paths( $transport ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was successfully <strong>cancelled</strong>' ) );
		$this->assertSame( 1, $this->count_notes( $order, 'Payment authorization was successfully <strong>cancelled</strong> (' . $this->recorded_intent_link() . ').' ) );
	}

	/**
	 * @testdox A change to Completed on an order another payment method took over sends nothing, not even a read: $_dataName.
	 *
	 * The order's other payment method may have been paid, so a capture of the kept authorization would take the money a
	 * second time. Client 11.3.0 captures it (class-wc-payments-order-service.php:2381-2418).
	 *
	 * @dataProvider completed_orders_another_payment_method_took_over
	 *
	 * @param string $stored_status Intent status the order stores.
	 * @param bool   $real_path     Whether the change runs over the shared processing service and a fake platform, or over
	 *                              a processing service that must not be asked to capture.
	 */
	public function test_completed_sends_nothing_for_an_order_another_payment_method_took_over( string $stored_status, bool $real_path ): void {
		$order     = $this->create_taken_over_order( 'cod', self::RECORDED_INTENT, $stored_status, OrderStatus::COMPLETED );
		$notes     = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$reads     = new \ArrayObject();
		$transport = null;
		$platform  = $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads );
		if ( $real_path ) {
			$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CAPTURE );
			$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $platform );
		} else {
			$processing_service = $this->createMock( PaymentProcessingService::class );
			$processing_service->expects( $this->never() )->method( 'capture' );
			$this->sut = $this->create_controller( $processing_service, null, $platform );
		}
		$logger = RecordingWcLogger::install();
		// Whichever test first builds the mailer in a process gets its order status emails hooked, and the mailer then logs this
		// order's skipped email (it has no billing address). The email is not what this test watches.
		add_filter( 'woocommerce_email_log_enabled', '__return_false' );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_completed', $order );

		$stored = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $reads, 'The kept payment is not read.' );
		if ( null !== $transport ) {
			// Only the real path reaches a transport; over the mock, the never() expectation above is the capture check.
			$this->assertSame( array(), $this->request_paths( $transport ), 'No request is sent.' );
		}
		$this->assertSame( array(), $logger->lines, 'No warning line.' );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
		$this->assertSame(
			array( 'cod', self::RECORDED_INTENT, OrderStatus::COMPLETED, $stored_status, '0.59' ),
			array( $stored->get_payment_method(), $stored->get_transaction_id(), $stored->get_status(), $stored->get_meta( '_intention_status', true ), $stored->get_meta( '_wcpay_transaction_fee', true ) )
		);
	}

	/**
	 * Stored statuses of a payment an order kept when another payment method took it over, on each processing path.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function completed_orders_another_payment_method_took_over(): array {
		return array(
			'stored authorization, processing service'     => array( 'requires_capture', false ),
			'stored authorization, platform'               => array( 'requires_capture', true ),
			'payment still processing, processing service' => array( 'processing', false ),
			'payment still processing, platform'           => array( 'processing', true ),
		);
	}

	/**
	 * @testdox A queued $operation retry on an order another payment method took over follows the change to $order_status.
	 *
	 * The retry goes through the status change's own check, so it cancels the kept authorization and never captures it.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_a_queued_retry_follows_the_cancel_on_an_order_another_payment_method_took_over( string $operation, string $order_status ): void {
		$this->set_ownership( 'builtin' );
		$order     = $this->create_taken_over_order( 'cheque', self::RECORDED_INTENT, 'requires_capture', $order_status );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, $reads ) );
		$this->sut->register();
		$this->register_retry_listener();
		$action_id = as_schedule_single_action( time() - 1, self::RETRY_HOOK, $this->retry_args( $order, $operation, 1 ), 'woocommerce_payments' );

		\ActionScheduler::runner()->process_action( $action_id );

		$is_cancel = PaymentProcessingService::OPERATION_CANCEL === $operation;
		$this->assertCount( 0, $reads );
		$this->assertSame( $is_cancel ? array( $this->operation_path( $operation ) ) : array(), $this->request_paths( $transport ) );
		$this->assertSame( $is_cancel ? 'canceled' : 'requires_capture', wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * @testdox A change to Cancelled sends nothing for an order of another payment method with nothing to release: $_dataName.
	 *
	 * @dataProvider orders_of_another_payment_method_with_nothing_to_release
	 *
	 * @param string $payment_method Order payment method.
	 * @param string $stored_status  Intent status the order stores; empty for an order that never had a WooPayments attempt.
	 */
	public function test_cancelled_sends_nothing_for_another_payment_methods_order_with_nothing_to_release( string $payment_method, string $stored_status ): void {
		if ( '' === $stored_status ) {
			$order = wc_create_order();
			$order->set_payment_method( $payment_method );
			$order->set_status( OrderStatus::CANCELLED );
			$order->save();
		} else {
			$order = $this->create_taken_over_order( $payment_method, self::RECORDED_INTENT, $stored_status, OrderStatus::CANCELLED );
		}
		$notes     = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$stored = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $reads );
		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array( $payment_method, $stored_status ), array( $stored->get_payment_method(), $stored->get_meta( '_intention_status', true ) ) );
	}

	/**
	 * Orders of another payment method a change to Cancelled leaves alone: a kept payment already settled, or no
	 * WooPayments attempt at all.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function orders_of_another_payment_method_with_nothing_to_release(): array {
		return array(
			'check payments, kept payment captured'       => array( 'cheque', 'succeeded' ),
			'check payments, kept authorization canceled' => array( 'cheque', 'canceled' ),
			'cash on delivery, never a WooPayments attempt' => array( 'cod', '' ),
		);
	}

	/**
	 * Orders another payment method took over, by the transaction ID it left: an offline method keeps the WooPayments
	 * intent there, a card gateway names its own payment.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function orders_another_payment_method_took_over(): array {
		return array(
			'check payments'                => array( 'cheque', self::RECORDED_INTENT ),
			'card gateway, its own payment' => array( 'stripe', 'ch_other_gateway_payment' ),
		);
	}

	/**
	 * The order's stored meta, by key, as the data store holds it.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	private function stored_meta( WC_Order $order ): array {
		$meta = array();
		foreach ( wc_get_order( $order->get_id() )->get_meta_data() as $entry ) {
			$meta[ $entry->key ] = maybe_serialize( $entry->value );
		}
		ksort( $meta );

		return $meta;
	}

	/**
	 * Assert that releasing the kept authorization changed only WooPayments's own values on the order and left one
	 * cancelled note.
	 *
	 * @param WC_Order             $order          Order before the change.
	 * @param array<string,string> $meta_before    The order's stored meta before the change.
	 * @param string               $payment_method Payment method that took the order over.
	 * @param string               $transaction_id Order transaction ID after the takeover.
	 */
	private function assert_only_the_woopayments_values_changed( WC_Order $order, array $meta_before, string $payment_method, string $transaction_id ): void {
		$stored   = wc_get_order( $order->get_id() );
		$expected = array_merge( $meta_before, array( '_intention_status' => 'canceled' ) );
		unset( $expected['_wcpay_transaction_fee'], $expected['_wcpay_net'] );
		$this->assertSame( $expected, $this->stored_meta( $stored ), 'Only the intent status is written, and only the fee and net are deleted.' );
		$this->assertSame( 'canceled', $stored->get_meta( '_intention_status', true ) );
		$this->assertSame( self::RECORDED_INTENT, $stored->get_meta( '_intent_id', true ) );
		$this->assertFalse( $stored->meta_exists( '_wcpay_transaction_fee' ) );
		$this->assertFalse( $stored->meta_exists( '_wcpay_net' ) );
		$this->assertFalse( $stored->meta_exists( '_wcpay_fraud_meta_box_type' ) );
		$this->assertFalse( $stored->meta_exists( '_wcpay_fraud_outcome_status' ) );
		$this->assertSame(
			array( $payment_method, $transaction_id, OrderStatus::CANCELLED, 'cus_other_gateway', 'kept' ),
			array( $stored->get_payment_method(), $stored->get_transaction_id(), $stored->get_status(), $stored->get_meta( '_stripe_customer_id', true ), $stored->get_meta( '_other_gateway_marker', true ) )
		);
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was successfully <strong>cancelled</strong>' ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( PaymentProcessingService::OPERATION_CANCEL ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->pending_note( PaymentProcessingService::OPERATION_CANCEL ) ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
	}

	/**
	 * Store a change through a second order object when a status hook fires, before the operation the hook runs claims
	 * the order payment lock, as another request writing the order then would.
	 *
	 * @param string               $hook   Status hook.
	 * @param array<string,string> $change Payment method or meta to store.
	 */
	private function change_the_order_before_its_status_hook( string $hook, array $change ): void {
		add_action(
			$hook,
			static function ( $order_id ) use ( $change ): void {
				$other = wc_get_order( $order_id );
				foreach ( $change as $key => $value ) {
					if ( 'payment_method' === $key ) {
						$other->set_payment_method( $value );
					} else {
						$other->update_meta_data( $key, $value );
					}
				}
				$other->save();
			},
			9
		);
	}

	/**
	 * @testdox A status change whose intent no longer awaits capture does not store the status it read once the order changed after the read: $_dataName.
	 *
	 * The status read is stored only while the order still stores the intent read and no settled status. Client 11.3.0
	 * stores the status it read (class-wc-payments-order-service.php:2358, :2417); here another writer changed the order
	 * between the read and the store.
	 *
	 * @dataProvider orders_changed_before_the_status_read_is_stored
	 *
	 * @param array $change Meta another writer stores between the read and the store.
	 */
	public function test_status_read_is_not_stored_once_the_order_changed( array $change ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_action', OrderStatus::CANCELLED );
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( PaymentProcessingService::OPERATION_CANCEL );
		$platform  = $this->create_platform(
			$this->recorded_intent( 'canceled_authorization' ),
			null,
			$reads,
			static function () use ( $order, $change ): void {
				$changed = wc_get_order( $order->get_id() );
				foreach ( $change as $key => $value ) {
					$changed->update_meta_data( $key, $value );
				}
				$changed->save_meta_data();
			}
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $platform );
		$this->sut->register();

		$this->fire_order_hook( 'woocommerce_order_status_cancelled', $order );

		$this->assertCount( 1, $reads );
		$this->assertSame( array(), $this->request_paths( $transport ) );
		$stored = wc_get_order( $order->get_id() );
		foreach ( $change as $key => $value ) {
			$this->assertSame( $value, $stored->get_meta( $key, true ), "The status read does not replace {$key} as the other writer stored it." );
		}
	}

	/**
	 * Changes another writer stores between the status change's read and its store of the status read.
	 *
	 * @return array<string,array{array<string,string>}>
	 */
	public function orders_changed_before_the_status_read_is_stored(): array {
		return array(
			'the order stores another intent'   => array(
				array(
					'_intent_id'        => 'pi_newer_attempt',
					'_intention_status' => 'requires_action',
				),
			),
			'the order stores a settled status' => array( array( '_intention_status' => 'succeeded' ) ),
		);
	}

	/**
	 * @testdox A $operation retry of a status change on an intent it read reads again and sends the operation for that intent, not for the transaction ID, even once the order stores the authorization.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 */
	public function test_retry_of_a_status_change_on_a_read_intent_never_acts_on_the_transaction_id( string $operation, string $order_status, string $hook ): void {
		$order = $this->create_recorded_authorization_order( 'requires_action', $order_status );
		$order->set_transaction_id( 'pi_earlier_attempt' );
		$order->save();
		$reads     = new \ArrayObject();
		$transport = $this->create_operation_transport( $operation );
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->while_lock_is_held(
			$order,
			function () use ( $hook, $order ): void {
				$this->fire_order_hook( $hook, $order );
			}
		);
		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertSame( array( 1 ), $this->get_pending_retries( $order ) );
		$stored = wc_get_order( $order->get_id() );
		$stored->update_meta_data( '_intention_status', 'requires_capture' );
		$stored->save();

		$jobs = as_get_scheduled_actions(
			array(
				'hook'     => self::RETRY_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $jobs );
		\ActionScheduler::runner()->process_action( (int) reset( $jobs ) );

		$this->assertCount( 2, $reads, 'The status change and its retry each read the intent once.' );
		$this->assertSame( array( $this->operation_path( $operation ) ), $this->request_paths( $transport ) );
	}

	/**
	 * @testdox A status change runs no $operation on $_dataName.
	 *
	 * Client 11.3.0 reads nothing for a stored succeeded or canceled status (TERMINAL_INTENT_STATUSES,
	 * class-wc-payments-order-service.php:89-92), and acts only on a live intent that awaits capture with an uncaptured
	 * succeeded charge, storing the status it read (:2335-2357, :2394-2418).
	 *
	 * @dataProvider status_changes_that_run_no_operation
	 *
	 * @param string      $operation       Capture or cancel.
	 * @param string      $order_status    Status the order changes to.
	 * @param string      $hook            Status hook.
	 * @param string      $stored_status   Intent status the order stores.
	 * @param string|null $live_pair       Recorded intent the platform reports, or null when nothing is read.
	 * @param string      $expected_stored Intent status the order stores afterwards.
	 */
	public function test_status_change_runs_no_operation( string $operation, string $order_status, string $hook, string $stored_status, ?string $live_pair, string $expected_stored ): void {
		$order      = $this->create_recorded_authorization_order( $stored_status, $order_status );
		$reads      = new \ArrayObject();
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'capture' );
		$processing->expects( $this->never() )->method( 'cancel' );
		$this->sut = $this->create_controller( $processing, null, $this->create_platform( null === $live_pair ? null : $this->recorded_intent( $live_pair ), null, $reads ) );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertCount( null === $live_pair ? 0 : 1, $reads );
		$this->assertSame( $order_status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( $expected_stored, wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * Status changes whose stored status is settled, and ones whose live intent no longer awaits capture.
	 *
	 * @return array<string,array{string,string,string,string,string|null,string}>
	 */
	public function status_changes_that_run_no_operation(): array {
		$rows = array();
		foreach ( $this->status_change_operation_data() as $name => $row ) {
			$rows[ $name . ', the order stores succeeded' ]   = array_merge( $row, array( 'succeeded', null, 'succeeded' ) );
			$rows[ $name . ', the order stores canceled' ]    = array_merge( $row, array( 'canceled', null, 'canceled' ) );
			$rows[ $name . ', the live intent was captured' ] = array_merge( $row, array( 'requires_action', 'capture_full_amount', 'succeeded' ) );
			$rows[ $name . ', the live intent was canceled' ] = array_merge( $row, array( '', 'canceled_authorization', 'canceled' ) );
		}

		return $rows;
	}

	/**
	 * @testdox A status change to $order_status whose intent read fails keeps the new status, adds the client's failure note and runs no $operation.
	 *
	 * Client 11.3.0 catches the read's exception and adds its failure note (class-wc-payments-order-service.php:2359-2368,
	 * :2419-2426); the order keeps the status it changed to.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 */
	public function test_status_change_whose_intent_read_fails_keeps_the_status_with_the_failure_note( string $operation, string $order_status, string $hook ): void {
		$order      = $this->create_recorded_authorization_order( 'requires_action', $order_status );
		$reads      = new \ArrayObject();
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( $operation );
		$this->sut = $this->create_controller( $processing, null, $this->create_platform( null, new ApiException( 'Request timed out.', 'wcpay_http_request_failed', 504 ), $reads ) );
		$this->sut->register();

		$this->fire_order_hook( $hook, $order );

		$this->assertCount( 1, $reads );
		$this->assertSame( $order_status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'requires_action', wc_get_order( $order->get_id() )->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * @testdox A $operation retry on an order whose status change found the lock held reads the intent again and runs once the lock is free.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changes to.
	 * @param string $hook         Status hook.
	 */
	public function test_retry_of_a_refused_status_change_reads_the_intent_again( string $operation, string $order_status, string $hook ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_action', $order_status );
		$reads     = new \ArrayObject();
		$calls     = new \ArrayObject();
		$outcome   = new PaymentOutcome(
			PaymentProcessingService::OPERATION_CAPTURE === $operation ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_CANCELED,
			'pi_3UJhOgBzWlxcwgpP0AOgQE2z',
			'',
			'',
			'',
			array( PaymentOutcome::DATA_NOTE => 'Provider ' . $operation . ' success note.' )
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_fixed_outcome_provider( $outcome, $calls ), $this->create_platform( $this->recorded_intent( 'authorize_manual_capture' ), null, $reads ) );
		$this->sut->register();

		$this->while_lock_is_held(
			$order,
			function () use ( $hook, $order ): void {
				$this->fire_order_hook( $hook, $order );
			}
		);
		$this->assertCount( 0, $calls );
		$this->assertSame( array( 1 ), $this->get_pending_retries( $order ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the Action Scheduler job the controller scheduled.
		do_action( self::RETRY_HOOK, $order->get_id(), $operation, 1 );

		$this->assertCount( 2, $reads, 'The status change and its retry each read the intent once.' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 1, $this->count_notes( $order, 'Provider ' . $operation . ' success note.' ) );
	}

	/**
	 * @testdox A $operation on the status change that the order payment lock refuses is retried after the request, with one pending note.
	 *
	 * Client 11.1.0 captures on Completed and cancels on Cancelled with no lock (class-wc-payments-order-service.php:1793-1886).
	 * Here a refused operation runs again 1 minute later (the webhook retry cadence), the order says it is pending, and
	 * no failure note is written yet.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 * @param string $hook         Status hook.
	 */
	public function test_refused_status_change_operation_is_retried_with_one_pending_note( string $operation, string $order_status, string $hook ): void {
		$order = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );

		$before = time();
		$this->while_lock_is_held(
			$order,
			function () use ( $hook, $order ): void {
				$this->fire_order_hook( $hook, $order );
			}
		);

		$this->assertCount( 0, $calls, 'The provider is not called while the lock is held.' );
		$this->assertSame( array( 1 ), $this->get_pending_retries( $order ) );
		$next = as_next_scheduled_action( self::RETRY_HOOK, $this->retry_args( $order, $operation, 1 ), 'woocommerce_payments' );
		$this->assertGreaterThanOrEqual( $before + MINUTE_IN_SECONDS, $next );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, $next );
		$this->assertSame( 1, $this->count_notes( $order, $this->pending_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * @testdox A $operation status change refused again while its first retry is queued adds no second pending note or retry.
	 *
	 * The merchant sets the order back to On hold and then to $order_status again while the lock is still held.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_repeated_refused_status_change_keeps_one_retry_and_one_pending_note( string $operation, string $order_status ): void {
		$order = $this->create_authorized_order();
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );

		$this->while_lock_is_held(
			$order,
			static function () use ( $order, $order_status ): void {
				$order->update_status( $order_status );
				$order->update_status( OrderStatus::ON_HOLD );
				$order->update_status( $order_status );
			}
		);

		$this->assertCount( 0, $calls );
		$this->assertSame( array( 1 ), $this->get_pending_retries( $order ) );
		$this->assertSame( 1, $this->count_notes( $order, $this->pending_note( $operation ) ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * @testdox A $operation retry refused again is retried at 10 and then 60 minutes, with no new pending note, and the failure note comes only after the last.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_refused_retries_write_the_failure_note_only_after_the_last( string $operation, string $order_status ): void {
		$order = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$order->add_order_note( $this->pending_note( $operation ) );
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );

		$delays_after_retry = array(
			1 => 10 * MINUTE_IN_SECONDS,
			2 => HOUR_IN_SECONDS,
		);
		foreach ( $delays_after_retry as $retry => $delay ) {
			$before = time();
			$this->run_retry_while_lock_is_held( $order, $operation, $retry );

			$next = as_next_scheduled_action( self::RETRY_HOOK, $this->retry_args( $order, $operation, $retry + 1 ), 'woocommerce_payments' );
			$this->assertGreaterThanOrEqual( $before + $delay, $next, "Retry $retry schedules the next one." );
			$this->assertLessThanOrEqual( time() + $delay, $next );
			$this->assertSame( 1, $this->count_notes( $order, $this->pending_note( $operation ) ), "Retry $retry adds no pending note." );
			$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ), "Retry $retry adds no failure note." );
		}

		$pending_before_last = $this->get_pending_retries( $order );
		$this->run_retry_while_lock_is_held( $order, $operation, 3 );

		$this->assertCount( 0, $calls );
		$this->assertSame( $pending_before_last, $this->get_pending_retries( $order ), 'The last retry schedules none.' );
		$this->assertSame( 1, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( 1, $this->count_notes( $order, $this->pending_note( $operation ) ) );
	}

	/**
	 * @testdox A $operation refused on a status change with a retry left is logged as a notice; the order screen's action and the last retry are warnings.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_lock_refusal_is_a_notice_only_when_the_status_change_retries_it( string $operation, string $order_status ): void {
		$order        = $this->create_authorized_order();
		$order_screen = $this->create_authorized_order();
		$calls        = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );
		$logger = RecordingWcLogger::install();

		$this->while_lock_is_held(
			$order,
			static function () use ( $order, $order_status ): void {
				$order->update_status( $order_status );
			}
		);
		$this->while_lock_is_held(
			$order_screen,
			static function () use ( $order_screen, $operation ): void {
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the order screen's action.
				do_action( PaymentProcessingService::OPERATION_CAPTURE === $operation ? 'woocommerce_order_action_capture_charge' : 'woocommerce_order_action_cancel_authorization', $order_screen );
			}
		);
		$this->run_retry_while_lock_is_held( $order, $operation, 3 );

		$this->assertCount( 0, $calls );
		$refusals = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'order payment lock refused: ' ) ) );
		$this->assertSame( array( 'notice', 'warning', 'warning' ), array_column( $refusals, 0 ) );
		$this->assertStringContainsString( sprintf( 'order %d, refused %s', $order->get_id(), $operation ), $refusals[0][1] );
		$this->assertStringContainsString( sprintf( 'order %d, refused %s', $order_screen->get_id(), $operation ), $refusals[1][1] );
	}

	/**
	 * @testdox A $operation retry with the lock free runs the operation once and the order gets its success note.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_retry_runs_the_operation_once_the_lock_is_free( string $operation, string $order_status ): void {
		$order = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$order->add_order_note( $this->pending_note( $operation ) );
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the Action Scheduler job the controller scheduled.
		do_action( self::RETRY_HOOK, $order->get_id(), $operation, 1 );

		$this->assertSame(
			array(
				array(
					'operation' => $operation,
					'order_id'  => $order->get_id(),
					'amount'    => PaymentProcessingService::OPERATION_CAPTURE === $operation ? 42.5 : null,
				),
			),
			$calls->getArrayCopy(),
			'The retry sends the same operation for the same order, a capture for the order total (os:1868-1871).'
		);
		$this->assertSame( 1, $this->count_notes( $order, 'Provider ' . $operation . ' success note.' ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
	}

	/**
	 * @testdox A $operation retry does nothing once the order no longer matches its guard: $_dataName.
	 *
	 * @dataProvider stale_retry_data
	 *
	 * @param string $operation     Capture or cancel.
	 * @param string $order_status  Order status when the retry runs.
	 * @param string $intent_status `_intention_status` when the retry runs.
	 */
	public function test_retry_stops_when_the_order_no_longer_matches( string $operation, string $order_status, string $intent_status ): void {
		$order = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $intent_status, $order_status );
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );
		$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the Action Scheduler job the controller scheduled.
		do_action( self::RETRY_HOOK, $order->get_id(), $operation, 1 );

		$this->assertCount( 0, $calls );
		$this->assertCount( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), $this->get_pending_retries( $order ) );
	}

	/**
	 * @testdox A retry that falls due once $_dataName completes with client 11.1.0's failure note and changes nothing else.
	 *
	 * The built-in WooPayments queued the retry on an active store; the retry then runs where the order actions controller
	 * is not loaded. Client 11.1.0 writes this note when a status-change capture or cancel fails
	 * (class-wc-payments-order-service.php:1827-1831, :1880-1884) and captures only on the change to Completed, hiding
	 * Capture on paid orders.
	 *
	 * @dataProvider retry_after_ownership_loss_data
	 *
	 * @param string $ownership    What changed since the retry was queued.
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 * @param string $gateway_id   Order payment method.
	 */
	public function test_retry_the_built_in_cannot_run_completes_with_the_failure_note( string $ownership, string $operation, string $order_status, string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): void {
		$this->set_ownership( $ownership );
		$order = $this->create_authorized_order( $gateway_id, 'requires_capture', $order_status );
		$order->add_order_note( $this->pending_note( $operation ) );
		$http_requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $response ) use ( &$http_requests ) {
				++$http_requests;
				return $response;
			}
		);

		$cron_classes = wc_get_container()->get( WooPaymentsSetupTier::class )->get_classes_for_request( 'cron' );
		$this->assertContains( WooPaymentsStatusChangeRetryListener::class, $cron_classes, 'A cron request registers the retry listener.' );
		$this->assertNotContains( WooPaymentsOrderAdminActionsController::class, $cron_classes, 'The built-in capture path is not loaded.' );
		$this->register_retry_listener();
		$action_id = as_schedule_single_action( time() - 1, self::RETRY_HOOK, $this->retry_args( $order, $operation, 1 ), 'woocommerce_payments' );

		\ActionScheduler::runner()->process_action( $action_id );

		$this->assertSame( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler::store()->get_status( (string) $action_id ) );
		$this->assertSame( 1, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $order_status, $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 0, $http_requests, 'No platform call.' );
	}

	/**
	 * @testdox A retry of a status change on an intent it read that falls due once the built-in cannot run notes the failure only while the order still waits on that intent: $_dataName.
	 *
	 * The retry's order stores the intent the status change read, with a status that is not settled; client 11.1.0 writes
	 * this note when a status-change capture or cancel fails (class-wc-payments-order-service.php:1827-1831, :1880-1884).
	 *
	 * @dataProvider read_intent_retry_after_ownership_loss_data
	 *
	 * @param string $ownership     What changed since the retry was queued.
	 * @param string $operation     Capture or cancel.
	 * @param string $order_status  Status the order changed to.
	 * @param string $stored_intent Intent the order stores when the retry runs.
	 * @param string $stored_status Intent status the order stores when the retry runs.
	 * @param bool   $notes         Whether the failure note is written.
	 */
	public function test_retry_on_a_read_intent_the_built_in_cannot_run_notes_the_failure( string $ownership, string $operation, string $order_status, string $stored_intent, string $stored_status, bool $notes ): void {
		$this->set_ownership( $ownership );
		$order = $this->create_recorded_authorization_order( $stored_status, $order_status );
		$order->update_meta_data( '_intent_id', $stored_intent );
		$order->save();
		$http_requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $response ) use ( &$http_requests ) {
				++$http_requests;
				return $response;
			}
		);
		$this->register_retry_listener();
		$action_id = as_schedule_single_action( time() - 1, self::RETRY_HOOK, array_merge( $this->retry_args( $order, $operation, 1 ), array( 'intent_id' => 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' ) ), 'woocommerce_payments' );

		\ActionScheduler::runner()->process_action( $action_id );

		$this->assertSame( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler::store()->get_status( (string) $action_id ) );
		$this->assertSame( $notes ? 1 : 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
		$this->assertSame( $order_status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 0, $http_requests, 'No platform call.' );
	}

	/**
	 * Retries of a status change on a read intent, where the built-in WooPayments no longer runs them.
	 *
	 * @return array<string,array{string,string,string,string,string,bool}>
	 */
	public function read_intent_retry_after_ownership_loss_data(): array {
		$read = 'pi_3UJhOgBzWlxcwgpP0AOgQE2z';

		return array(
			'the extension is active, capture'          => array( 'extension', PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED, $read, 'requires_action', true ),
			'the feature is off, cancel'                => array( 'feature_off', PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED, $read, '', true ),
			'the order stores another intent, capture'  => array( 'extension', PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED, 'pi_newer_attempt', 'requires_action', false ),
			'the order stores a settled status, cancel' => array( 'feature_off', PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED, $read, 'canceled', false ),
		);
	}

	/**
	 * Ways the order actions controller stops loading, with each status-change operation, and a cancel on an order another
	 * payment method took over, which the built-in would also have run.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3?:string}>
	 */
	public function retry_after_ownership_loss_data(): array {
		return array(
			'the extension is active, capture' => array( 'extension', PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED ),
			'the extension is active, cancel'  => array( 'extension', PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED ),
			'the feature is off, capture'      => array( 'feature_off', PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED ),
			'the feature is off, cancel'       => array( 'feature_off', PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED ),
			'the built-in lost its account record, capture' => array( 'account_lost', PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED ),
			'the extension is active, cancel, the order is paid by another gateway' => array( 'extension', PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED, 'cheque' ),
		);
	}

	/**
	 * @testdox A retry after the extension took over payments notes nothing once the order changed: $_dataName.
	 *
	 * @dataProvider changed_order_after_ownership_loss_data
	 *
	 * @param string $operation     Capture or cancel.
	 * @param string $gateway_id    Order payment method.
	 * @param string $order_status  Order status when the retry runs.
	 * @param string $intent_status `_intention_status` when the retry runs.
	 */
	public function test_retry_after_the_built_in_stops_owning_leaves_a_changed_order_alone( string $operation, string $gateway_id, string $order_status, string $intent_status ): void {
		$this->set_ownership( 'extension' );
		$order        = $this->create_authorized_order( $gateway_id, $intent_status, $order_status );
		$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->register_retry_listener();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the Action Scheduler job the controller scheduled.
		do_action( self::RETRY_HOOK, $order->get_id(), $operation, 1 );

		$this->assertCount( $notes_before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Orders a retry finds changed after the extension took over payments.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function changed_order_after_ownership_loss_data(): array {
		$gateway = WooPaymentsPersistenceVocabulary::GATEWAY_ID;

		return array(
			'capture, the order is no longer completed' => array( PaymentProcessingService::OPERATION_CAPTURE, $gateway, OrderStatus::PROCESSING, 'requires_capture' ),
			'capture, the extension captured it'        => array( PaymentProcessingService::OPERATION_CAPTURE, $gateway, OrderStatus::COMPLETED, 'succeeded' ),
			'cancel, the order is no longer cancelled'  => array( PaymentProcessingService::OPERATION_CANCEL, $gateway, OrderStatus::ON_HOLD, 'requires_capture' ),
			'capture, the order is paid by another gateway' => array( PaymentProcessingService::OPERATION_CAPTURE, 'cod', OrderStatus::COMPLETED, 'requires_capture' ),
		);
	}

	/**
	 * @testdox While the built-in WooPayments owns payments, a $operation retry runs the operation and the retry listener adds nothing.
	 *
	 * @dataProvider status_change_operation_data
	 *
	 * @param string $operation    Capture or cancel.
	 * @param string $order_status Status the order changed to.
	 */
	public function test_retry_while_the_built_in_owns_payments_runs_the_operation_alone( string $operation, string $order_status ): void {
		$this->set_ownership( 'builtin' );
		$order = $this->create_authorized_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'requires_capture', $order_status );
		$calls = new \ArrayObject();
		$this->register_controller_with_provider( $operation, $calls );
		$this->register_retry_listener();
		$action_id = as_schedule_single_action( time() - 1, self::RETRY_HOOK, $this->retry_args( $order, $operation, 1 ), 'woocommerce_payments' );

		\ActionScheduler::runner()->process_action( $action_id );

		$this->assertCount( 1, $calls, 'The retry runs the operation once.' );
		$this->assertSame( 1, $this->count_notes( $order, 'Provider ' . $operation . ' success note.' ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( $operation ) ) );
	}

	/**
	 * Put the store in an ownership state after the built-in WooPayments was connected and active.
	 *
	 * @param string $ownership builtin (it still owns payments), account_lost (it still owns payments, and an account read that
	 *                          found no account wrote the available tier, WooPaymentsAccountService::sync_setup_tier()),
	 *                          extension (the extension is active) or feature_off.
	 */
	private function set_ownership( string $ownership ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, 'account_lost' === $ownership ? WooPaymentsSetupTier::AVAILABLE : WooPaymentsSetupTier::ACTIVE );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'feature_off' === $ownership ? 'no' : 'yes' );
		if ( 'extension' === $ownership ) {
			update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		}
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->assertSame( in_array( $ownership, array( 'builtin', 'account_lost' ), true ), $arbiter->is_builtin_owner() );
	}

	/**
	 * Register the retry listener from the container.
	 */
	private function register_retry_listener(): void {
		$this->retry_listener = wc_get_container()->get( WooPaymentsStatusChangeRetryListener::class );
		$this->retry_listener->register();
	}

	/**
	 * Status-change operations and the order status and hook that trigger each.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function status_change_operation_data(): array {
		return array(
			'capture' => array( PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED, 'woocommerce_order_status_completed' ),
			'cancel'  => array( PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED, 'woocommerce_order_status_cancelled' ),
		);
	}

	/**
	 * Orders a retry finds changed since the refusal.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function stale_retry_data(): array {
		return array(
			'capture, the order is no longer completed' => array( PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::PROCESSING, 'requires_capture' ),
			'capture, the authorization was captured'   => array( PaymentProcessingService::OPERATION_CAPTURE, OrderStatus::COMPLETED, 'succeeded' ),
			'cancel, the order is no longer cancelled'  => array( PaymentProcessingService::OPERATION_CANCEL, OrderStatus::ON_HOLD, 'requires_capture' ),
			'cancel, the authorization was canceled'    => array( PaymentProcessingService::OPERATION_CANCEL, OrderStatus::CANCELLED, 'canceled' ),
		);
	}

	/**
	 * Register the controller over the shared processing service and a provider whose operation succeeds with a note.
	 *
	 * @param string       $operation Capture or cancel.
	 * @param \ArrayObject $calls     Records capture and cancel calls.
	 */
	private function register_controller_with_provider( string $operation, \ArrayObject $calls ): void {
		$outcome   = new PaymentOutcome(
			PaymentProcessingService::OPERATION_CAPTURE === $operation ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_CANCELED,
			'pi_authorized',
			'',
			'',
			'',
			array( PaymentOutcome::DATA_NOTE => 'Provider ' . $operation . ' success note.' )
		);
		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_fixed_outcome_provider( $outcome, $calls ) );
		$this->sut->register();
	}

	/**
	 * Run a callback while another operation holds the order payment lock.
	 *
	 * @param WC_Order $order    Order.
	 * @param callable $callback Callback.
	 */
	private function while_lock_is_held( WC_Order $order, callable $callback ): void {
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $lock->claim( $order, $vocabulary, 'evt_holder', 'payment status update' );
		$this->assertNotNull( $token );
		try {
			$callback();
		} finally {
			$lock->release( $order, $vocabulary, $token );
		}
	}

	/**
	 * Run one retry job while another operation holds the order payment lock.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 * @param int      $retry     Retry number.
	 */
	private function run_retry_while_lock_is_held( WC_Order $order, string $operation, int $retry ): void {
		$this->while_lock_is_held(
			$order,
			static function () use ( $order, $operation, $retry ): void {
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the Action Scheduler job the controller scheduled.
				do_action( self::RETRY_HOOK, $order->get_id(), $operation, $retry );
			}
		);
	}

	/**
	 * The retry job's arguments.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $operation Capture or cancel.
	 * @param int      $retry     Retry number.
	 * @return array<string,int|string>
	 */
	private function retry_args( WC_Order $order, string $operation, int $retry ): array {
		return array(
			'order_id'  => $order->get_id(),
			'operation' => $operation,
			'retry'     => $retry,
		);
	}

	/**
	 * Retry numbers of the order's pending retry jobs.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private function get_pending_retries( WC_Order $order ): array {
		$retries = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => self::RETRY_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		) as $action ) {
			$args = $action->get_args();
			if ( $order->get_id() === ( $args['order_id'] ?? null ) ) {
				$retries[] = (int) $args['retry'];
			}
		}
		sort( $retries );

		return $retries;
	}

	/**
	 * Count the order's notes with exactly this content.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $content Note content.
	 * @return int
	 */
	private function count_notes( WC_Order $order, string $content ): int {
		return count( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ): bool => $content === $note->content ) );
	}

	/**
	 * The recorded authorization's intent as a cancelled note links it: its transaction page, in the note's own markup.
	 *
	 * @return string
	 */
	private function recorded_intent_link(): string {
		return '<a href="' . admin_url( 'admin.php?page=wc-admin&path=%2Fpayments%2Ftransactions%2Fdetails&id=' . self::RECORDED_INTENT ) . '" target="_blank" rel="noopener noreferrer">' . self::RECORDED_INTENT . '</a>';
	}

	/**
	 * Count the order's notes that contain this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text Text the note contains.
	 * @return int
	 */
	private function count_notes_containing( WC_Order $order, string $text ): int {
		return count( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ): bool => str_contains( (string) $note->content, $text ) ) );
	}

	/**
	 * Count the order's payment complete notes, the ones WC_Order::payment_complete() adds.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private function count_payment_complete_notes( WC_Order $order ): int {
		return count(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => 'Payment complete.' === $note->content || 0 === strpos( (string) $note->content, 'Payment via ' )
			)
		);
	}

	/**
	 * How many times each payment completion hook has fired so far.
	 *
	 * @return array<string,int>
	 */
	private function count_payment_completion_hooks(): array {
		$counts = array();
		foreach ( array( 'woocommerce_pre_payment_complete', 'woocommerce_payment_complete_order_status_completed', 'woocommerce_payment_complete' ) as $hook ) {
			$counts[ $hook ] = did_action( $hook );
		}

		return $counts;
	}

	/**
	 * How many times each payment completion hook fired since the counts given.
	 *
	 * @param array<string,int> $before Counts from count_payment_completion_hooks().
	 * @return array<string,int>
	 */
	private function payment_completion_hook_fires_since( array $before ): array {
		$fires = array();
		foreach ( $this->count_payment_completion_hooks() as $hook => $count ) {
			$fires[ $hook ] = $count - $before[ $hook ];
		}

		return $fires;
	}

	/**
	 * Count the order's pending Fee details jobs.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private function count_pending_fee_details_jobs( WC_Order $order ): int {
		$jobs = as_get_scheduled_actions(
			array(
				'hook'     => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);

		return count( array_filter( $jobs, static fn( $job ): bool => $order->get_id() === ( $job->get_args()['order_id'] ?? null ) ) );
	}

	/**
	 * The note a first refused status-change operation writes.
	 *
	 * @param string $operation Capture or cancel.
	 * @return string
	 */
	private function pending_note( string $operation ): string {
		return PaymentProcessingService::OPERATION_CAPTURE === $operation
			? 'The payment capture is pending and will be retried automatically.'
			: 'Canceling the payment authorization is pending and will be retried automatically.';
	}

	/**
	 * The failure note client 11.1.0 writes for a capture or cancel that did not complete.
	 *
	 * @param string $operation Capture or cancel.
	 * @return string
	 */
	private function failure_note( string $operation ): string {
		return PaymentProcessingService::OPERATION_CAPTURE === $operation
			? 'Capture authorization <strong>failed</strong> to complete.'
			: 'Canceling authorization <strong>failed</strong> to complete.';
	}

	/**
	 * Create the controller with explicit dependencies.
	 *
	 * @param PaymentProcessingService|null $processing_service Processing service override.
	 * @param WooPaymentsProvider|null      $provider           Provider override.
	 * @param Client|null                   $api_client         Platform client override; by default a read answers nothing.
	 * @return WooPaymentsOrderAdminActionsController
	 */
	private function create_controller( ?PaymentProcessingService $processing_service = null, ?WooPaymentsProvider $provider = null, ?Client $api_client = null ): WooPaymentsOrderAdminActionsController {
		$controller = new WooPaymentsOrderAdminActionsController();
		$controller->init(
			$processing_service ?? $this->createMock( PaymentProcessingService::class ),
			$provider ?? new WooPaymentsProvider(),
			wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class ),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class ),
			$api_client ?? $this->createMock( Client::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			wc_get_container()->get( WooPaymentsLogger::class )
		);

		return $controller;
	}

	/**
	 * Create a saved order with the requested authorization facts.
	 *
	 * @param string $gateway_id    Payment gateway ID.
	 * @param string $intent_status Intent status.
	 * @param string $order_status  Order status.
	 * @return WC_Order
	 */
	private function create_authorized_order( string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID, string $intent_status = 'requires_capture', string $order_status = 'on-hold' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $gateway_id );
		$order->set_currency( 'USD' );
		$order->set_total( '42.50' );
		$order->set_status( $order_status );
		$order->set_transaction_id( 'pi_authorized' );
		$order->update_meta_data( '_intent_id', 'pi_authorized' );
		$order->update_meta_data( '_intention_status', $intent_status );
		$order->save();

		return $order;
	}

	/**
	 * Create a saved WooPayments order on the recorded manual-capture authorization's intent, storing the given status.
	 *
	 * @param string $stored_status Intent status the order stores; empty for none.
	 * @param string $order_status  Status the order is saved with, before its status hook fires.
	 * @return WC_Order
	 */
	private function create_recorded_authorization_order( string $stored_status, string $order_status ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.99' );
		$order->set_status( $order_status );
		$order->set_transaction_id( 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intent_id', 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		if ( '' !== $stored_status ) {
			$order->update_meta_data( '_intention_status', $stored_status );
		}
		$order->save();

		return $order;
	}

	/**
	 * Create a saved order another payment method took over from a WooPayments attempt on the recorded manual-capture
	 * authorization, which the takeover kept: the attempt's intent, stored status, mode, fee and net, a customer ID the
	 * other gateway also writes, and one value of the other gateway's own.
	 *
	 * @param string $payment_method Payment method that took the order over.
	 * @param string $transaction_id Order transaction ID after the takeover.
	 * @param string $stored_status  Intent status the order stores.
	 * @param string $order_status   Status the order is saved with, before its status hook fires.
	 * @return WC_Order
	 */
	private function create_taken_over_order( string $payment_method, string $transaction_id, string $stored_status, string $order_status ): WC_Order {
		$order = $this->create_recorded_authorization_order( $stored_status, $order_status );
		$order->set_payment_method( $payment_method );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.59' );
		$order->update_meta_data( '_wcpay_net', '10.40' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_other_gateway' );
		$order->update_meta_data( '_other_gateway_marker', 'kept' );
		$order->save();

		return $order;
	}

	/**
	 * Create a saved WooPayments order on the recorded manual-capture authorization, storing the given status, whose
	 * transaction ID names that authorization, names nothing, or names another attempt.
	 *
	 * @param string $stored_txn    Transaction ID: the recorded intent, '' or another attempt's.
	 * @param string $stored_status Intent status the order stores.
	 * @param string $order_status  Status the order is saved with, before its status hook fires.
	 * @return WC_Order
	 */
	private function create_recorded_authorization_order_storing( string $stored_txn, string $stored_status, string $order_status ): WC_Order {
		$charge_id = 'requires_capture' === $stored_status ? self::RECORDED_CHARGE : '';
		if ( self::RECORDED_INTENT === $stored_txn ) {
			return $this->create_recorded_authorization_order( $stored_status, $order_status );
		}

		return '' === $stored_txn
			? StoredPaymentRecords::authorization_without_transaction_id( self::RECORDED_INTENT, $stored_status, $order_status, $charge_id )
			: StoredPaymentRecords::authorization_with_another_attempts_transaction_id( $stored_txn, self::RECORDED_INTENT, $stored_status, $order_status, $charge_id );
	}

	/**
	 * The recorded contexts of the lines that name a transaction ID a completion replaced.
	 *
	 * @param RecordingWcLogger $logger Recording logger.
	 * @return array<int,array<string,mixed>>
	 */
	private function replacement_lines( RecordingWcLogger $logger ): array {
		return array_values( array_filter( $logger->contexts, static fn( array $context ): bool => 'completion_replaced_a_different_transaction_id' === ( $context['reason'] ?? '' ) ) );
	}

	/**
	 * A recorded platform intent: the manual-capture authorization and its capture (Fixtures/platform-manual-capture.json),
	 * or a canceled authorization the platform forwarded (Fixtures/platform-payment-intent-events.json, in_person_canceled).
	 *
	 * @param string $pair `authorize_manual_capture`, `capture_full_amount` or `canceled_authorization`.
	 * @return array<string,mixed>
	 */
	private function recorded_intent( string $pair ): array {
		if ( 'canceled_authorization' === $pair ) {
			return RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'in_person_canceled' )['data']['object'];
		}

		return $this->load_recorded_manual_capture_entry( $pair )['body'];
	}

	/**
	 * Create a platform client whose intent read answers the given intent or throws, recording each read.
	 *
	 * @param array<string,mixed>|null $intent  Intent the read answers.
	 * @param \Throwable|null          $failure Throwable the read throws instead.
	 * @param \ArrayObject             $reads   Records the ID of each read.
	 * @param callable|null            $on_read Runs during each read, as another request writing the order then would.
	 * @return Client
	 */
	private function create_platform( ?array $intent, ?\Throwable $failure, \ArrayObject $reads, ?callable $on_read = null ): Client {
		$client = $this->createMock( Client::class );
		$client->method( 'get_payment_intention' )->willReturnCallback(
			static function ( string $intent_id ) use ( $intent, $failure, $reads, $on_read ): array {
				$reads[] = $intent_id;
				if ( null !== $on_read ) {
					$on_read();
				}
				if ( null !== $failure ) {
					throw $failure;
				}

				return $intent ?? array();
			}
		);

		return $client;
	}

	/**
	 * Create a fake platform transport that answers one capture or cancel of the recorded authorization: the recorded
	 * capture (Fixtures/platform-manual-capture.json), or the recorded canceled authorization the platform forwarded
	 * (Fixtures/platform-payment-intent-events.json, in_person_canceled) for this intent.
	 *
	 * @param string $operation Capture or cancel.
	 * @return FakeHttpClient
	 */
	private function create_operation_transport( string $operation ): FakeHttpClient {
		$body = PaymentProcessingService::OPERATION_CAPTURE === $operation
			? $this->load_recorded_manual_capture_entry( 'capture_full_amount' )['body']
			: array_merge( $this->recorded_intent( 'canceled_authorization' ), array( 'id' => 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' ) );

		$transport           = new FakeHttpClient();
		$transport->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);

		return $transport;
	}

	/**
	 * The platform path of a capture or cancel of the recorded authorization.
	 *
	 * @param string $operation Capture or cancel.
	 * @return string
	 */
	private function operation_path( string $operation ): string {
		return 'intentions/pi_3UJhOgBzWlxcwgpP0AOgQE2z/' . ( PaymentProcessingService::OPERATION_CAPTURE === $operation ? 'capture' : 'cancel' );
	}

	/**
	 * The paths of the requests a fake transport received, each from its `intentions/` segment.
	 *
	 * @param FakeHttpClient $transport Fake transport.
	 * @return string[]
	 */
	private function request_paths( FakeHttpClient $transport ): array {
		return array_map(
			static fn( array $request ): string => (string) strstr( (string) $request['path'], 'intentions/' ),
			$transport->requests
		);
	}

	/**
	 * Create a WooPayments provider that returns a fixed capture/cancel outcome.
	 *
	 * @param PaymentOutcome               $fixed_outcome Fixed operation outcome.
	 * @param \ArrayObject<int,mixed>|null $calls         Records each capture and cancel call's operation, order ID and amount, when given.
	 * @return WooPaymentsProvider
	 */
	private function create_fixed_outcome_provider( PaymentOutcome $fixed_outcome, ?\ArrayObject $calls = null ): WooPaymentsProvider {
		return new class( $fixed_outcome, $calls ) extends WooPaymentsProvider {
			/**
			 * Fixed operation outcome.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $fixed_outcome;

			/**
			 * Capture and cancel call recorder.
			 *
			 * @var \ArrayObject<int,mixed>|null
			 */
			private ?\ArrayObject $calls;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome               $fixed_outcome Fixed operation outcome.
			 * @param \ArrayObject<int,mixed>|null $calls         Capture and cancel call recorder.
			 */
			public function __construct( PaymentOutcome $fixed_outcome, ?\ArrayObject $calls ) {
				$this->fixed_outcome = $fixed_outcome;
				$this->calls         = $calls;
			}

			/**
			 * Return the WooPayments provider ID.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return WooPaymentsPersistenceVocabulary::GATEWAY_ID;
			}

			/**
			 * Return the WooPayments persistence profile.
			 *
			 * @return ProviderPersistenceVocabularyInterface
			 */
			public function get_persistence_vocabulary(): ProviderPersistenceVocabularyInterface {
				return new WooPaymentsPersistenceVocabulary();
			}

			/**
			 * Return the fixed capture outcome.
			 *
			 * @param PaymentOperationContext $context       Payment context.
			 * @param string                  $operation_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
				unset( $operation_key );
				$this->record( 'capture', $context );

				return $this->fixed_outcome;
			}

			/**
			 * Return the fixed cancel outcome.
			 *
			 * @param PaymentOperationContext $context       Payment context.
			 * @param string                  $operation_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
				unset( $operation_key );
				$this->record( 'cancel', $context );

				return $this->fixed_outcome;
			}

			/**
			 * Record a capture or cancel call.
			 *
			 * @param string                  $operation Capture or cancel.
			 * @param PaymentOperationContext $context   Payment context.
			 */
			private function record( string $operation, PaymentOperationContext $context ): void {
				if ( null !== $this->calls ) {
					$this->calls[] = array(
						'operation' => $operation,
						'order_id'  => $context->get_order_id(),
						'amount'    => $context->get_amount(),
					);
				}
			}

			/**
			 * Preserve the fixed outcome through provider-effect application.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );

				return $outcome;
			}

			/**
			 * Skip post-lifecycle effects for the fixed outcome.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 */
			public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $context, $outcome, $operation );
			}
		};
	}

	/**
	 * Fire an order action hook with the order, or an order status hook with the order ID, the order and a transition.
	 *
	 * @param string   $hook  Hook name.
	 * @param WC_Order $order Order.
	 */
	private function fire_order_hook( string $hook, WC_Order $order ): void {
		$args = 0 === strpos( $hook, 'woocommerce_order_status_' ) ? array( $order->get_id(), $order, array() ) : array( $order );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order hooks.
		do_action( $hook, ...$args );
	}

	/**
	 * Assert that an order has a note containing text.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $expected Expected note fragment.
	 */
	private function assert_order_has_note_containing( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( (string) $note->content, $expected ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "Missing order note containing: {$expected}" );
	}

	/**
	 * Create a saved order matching REC-CAP's authorized state: on-hold, manual-capture
	 * intent `requires_capture`, a physical line item so the captured order needs
	 * processing (matching the client's post-capture status).
	 *
	 * @return WC_Order
	 */
	private function create_recorded_manual_capture_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$order->set_total( '10.99' );
		$order->set_status( 'on-hold' );
		$order->set_transaction_id( 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intent_id', 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		return $order;
	}

	/**
	 * Load one REC-CAP recorded entry's HTTP status, content type and response body by pair key.
	 *
	 * @param string $pair Fixture pair key (`authorize_manual_capture` or `capture_full_amount`).
	 * @return array{http_status:int,content_type:string,body:array<string,mixed>}
	 */
	private function load_recorded_manual_capture_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/platform-manual-capture.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status'  => (int) $entry['response']['http_status'],
					'content_type' => (string) $entry['response']['content_type'],
					'body'         => $entry['response']['body'],
				);
			}
		}

		$this->fail( "REC-CAP fixture has no entry for pair '$pair'." );
	}

	/**
	 * Build a real WooPayments provider wired to the real gateway adapter and API client
	 * over a fake transport, the pattern used by
	 * {@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationServiceTest::create_provider_over_fake_transport()}.
	 *
	 * @param FakeHttpClient $http_client Fake transport queued with the recorded response(s).
	 * @return WooPaymentsProvider
	 */
	private function create_provider_over_fake_transport( FakeHttpClient $http_client ): WooPaymentsProvider {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'get_cached_account_data', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => $fallback
		);
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$api_client = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );

		$payment_operations = new WooPaymentsPaymentOperationService();
		$payment_operations->init(
			$api_client,
			$this->createMock( WooPaymentsCustomerService::class ),
			$this->createMock( WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			$this->createMock( WooPaymentsFraudRulesetService::class ),
			$this->createMock( WooPaymentsChargeAmbiguityService::class ),
			$this->createMock( WooPaymentsRefundAmbiguityService::class ),
			wc_get_container()->get( WooPaymentsLogger::class ),
			wc_get_container()->get( WooPaymentsLevel3Service::class )
		);

		$provider = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			$api_client,
			$account_service,
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * @testdox After a failed capture read again as $read_status, the capture's own payment_intent.succeeded completes the order as a capture on an edited order total, with the capture note and no payment note.
	 *
	 * A failed capture stores requires_capture beside the intent whatever the read says, unless the authorization expired,
	 * so the event is the capture of the stored authorization: it is recorded as one, with no amount check, as client
	 * 11.3.0 completes it (class-wc-payments-order-service.php:484-485, :2193-2219). A capture passes through processing
	 * on its way to succeeded. The merchant edits the order total after the failure, so an amount check would hold the order.
	 *
	 * @testWith ["succeeded"]
	 *           ["processing"]
	 *
	 * @param string $read_status Status the read after the failure answers.
	 */
	public function test_payment_success_after_a_failed_capture_read_again_as_succeeded_or_processing_completes_it_as_a_capture( string $read_status ): void {
		$order     = $this->create_recorded_authorization_order( 'requires_capture', OrderStatus::ON_HOLD );
		$read      = array_merge( $this->recorded_intent( 'capture_full_amount' ), array( 'status' => $read_status ) );
		$transport = $this->create_transport( self::platform_bad_gateway(), self::http_json( 200, $read ) );
		$this->run_operation_from( 'order screen', PaymentProcessingService::OPERATION_CAPTURE, $order, $transport );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'intentions/' . self::RECORDED_INTENT . '/capture', 'intentions/' . self::RECORDED_INTENT . '?test_mode=1' ), $this->request_paths( $transport ), 'The capture was sent, then the intent was read again.' );
		$this->assertSame( array(), $transport->responses, 'Both queued answers were used.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, self::CAPTURE_FAILURE_NOTE ), 'The failed capture is noted once.' );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ), 'The failure keeps the authorization.' );
		$order->set_total( '15.00' );
		$order->save();

		wc_get_container()->get( EventIngestor::class )->process( $this->recorded_payment_success_event( wc_get_order( $order->get_id() ), self::RECORDED_INTENT, self::RECORDED_CHARGE ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status(), 'The payment is complete; the order has nothing to ship.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'was <strong>successfully charged</strong>' ), 'No payment note.' );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'does not pay this order' ) );
	}

	/**
	 * @testdox A failed order screen capture of an earlier attempt the transaction ID names, beside the stored authorization, that the platform captured: the failure records the captured attempt, a move to Completed sends only its capture, and its own payment_intent.succeeded on an edited order total leaves the Completed order as it is, with no amount hold.
	 *
	 * The order screen acts on the transaction ID (class-wc-payment-gateway-wcpay.php:4341 in client 11.3.0), which names
	 * the attempt E, while the stored intent A is a live authorization. The platform answers the capture of E with a 502
	 * after Stripe captured it, and the read says succeeded. The failure writes E with requires_capture, so A is no longer
	 * named: the move to Completed captures E, which the platform refuses as already captured, and never A, so nobody is
	 * charged twice. E's own event then finds a paid order: it stores succeeded and the paid-order note, and neither checks
	 * the edited total nor completes a payment.
	 */
	public function test_failed_capture_of_an_earlier_attempt_the_platform_captured_never_collects_the_stored_authorization(): void {
		$order     = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_earlier', 'pi_A' );
		$transport = $this->create_transport(
			self::intent_answer( 'pi_earlier', 'requires_capture', $order ),
			self::platform_bad_gateway(),
			self::status_answer( 'pi_earlier', 'succeeded' ),
			self::platform_refusal( 'This PaymentIntent could not be captured because it has a status of succeeded.' ),
			self::status_answer( 'pi_earlier', 'succeeded' )
		);

		$this->run_operation_from( 'order screen', PaymentProcessingService::OPERATION_CAPTURE, $order, $transport );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'intentions/pi_earlier?test_mode=1', 'intentions/pi_earlier/capture', 'intentions/pi_earlier?test_mode=1' ), $this->request_paths( $transport ) );
		$this->assertSame( array( 'pi_earlier', 'requires_capture', 'pi_earlier' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id() ), 'The failure records E.' );

		$order->update_status( OrderStatus::COMPLETED );

		$captures = array_values( array_filter( $this->request_paths( $transport ), static fn( string $path ): bool => '/capture' === substr( $path, -8 ) ) );
		$this->assertSame( array( 'intentions/pi_earlier/capture', 'intentions/pi_earlier/capture' ), $captures, 'Completed captures E again, never A.' );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = wc_get_order( $order->get_id() );
		$order->set_total( '15.00' );
		$order->save();

		wc_get_container()->get( EventIngestor::class )->process( $this->recorded_payment_success_event( $order, 'pi_earlier', 'ch_earlier' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::COMPLETED, $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'pi_earlier</a> (charge <code>ch_earlier</code>)' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'succeeded, but this order already had a paid status, so its status was not changed' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'was <strong>successfully charged</strong>' ), 'No payment note.' );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'does not pay this order' ), 'No amount hold.' );
	}

	/**
	 * @testdox A capture refused on the intent the transaction ID names, on an order that stores no intent, stores that intent with requires_capture.
	 *
	 * A failed capture records its target and requires_capture, so the order names the authorization it acted on.
	 */
	public function test_failed_capture_on_an_order_storing_no_intent_stores_its_target(): void {
		$order     = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_A', '' );
		$transport = $this->create_transport(
			self::intent_answer( 'pi_A', 'requires_capture', $order ),
			self::platform_refusal( 'The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.' ),
			self::intent_answer( 'pi_A', 'requires_capture', $order )
		);

		$this->run_operation_from( 'order screen', PaymentProcessingService::OPERATION_CAPTURE, $order, $transport );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 3, count( $transport->requests ) );
		$this->assertSame( array( 'pi_A', 'requires_capture', 'pi_A' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id() ) );
	}

	/**
	 * @testdox A list capture of the authorization the transaction ID names, beside a stored declined retry, that fails and reads requires_capture again stores that authorization, so the order screen offers its capture.
	 *
	 * A 3DS attempt A sets the transaction ID; a declined retry B stores its intent and requires_payment_method; A is then
	 * approved outside the store. The list offers A, the transaction ID. The failure records A with requires_capture.
	 */
	public function test_failed_list_capture_beside_a_stored_decline_stores_the_authorization(): void {
		$order     = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_A', 'pi_B', 'requires_payment_method', OrderStatus::FAILED, '' );
		$transport = $this->create_transport( self::intent_answer( 'pi_A', 'requires_capture', $order ), self::platform_bad_gateway(), self::intent_answer( 'pi_A', 'requires_capture', $order ) );

		$this->run_operation_from( 'list', PaymentProcessingService::OPERATION_CAPTURE, $order, $transport );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'intentions/pi_A?test_mode=1', 'intentions/pi_A/capture', 'intentions/pi_A?test_mode=1' ), $this->request_paths( $transport ) );
		$this->assertSame( array( 'pi_A', 'requires_capture', 'pi_A' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id() ) );
		$this->assertSame( array( 'capture_charge', 'cancel_authorization' ), $this->order_screen_actions( $order ) );
	}

	/**
	 * @testdox A list capture of the authorization the transaction ID names, beside a stored declined retry, that fails after the platform captured stores that authorization with requires_capture, and its own payment_intent.succeeded completes the order as a capture.
	 *
	 * A failed capture read again as succeeded writes its target and requires_capture over the stored status that is not an
	 * authorization, as client 11.3.0 writes requires_capture after any failed capture (class-wc-payment-gateway-wcpay.php:4418),
	 * so the capture's own event takes the capture path (class-wc-payments-order-service.php:484-485).
	 */
	public function test_failed_list_capture_read_again_as_succeeded_beside_a_stored_decline_completes_as_a_capture(): void {
		$order     = StoredPaymentRecords::authorization_with_another_attempts_transaction_id( 'pi_A', 'pi_B', 'requires_payment_method', OrderStatus::FAILED, '' );
		$transport = $this->create_transport( self::intent_answer( 'pi_A', 'requires_capture', $order ), self::platform_bad_gateway(), self::intent_answer( 'pi_A', 'succeeded', $order ) );

		$this->run_operation_from( 'list', PaymentProcessingService::OPERATION_CAPTURE, $order, $transport );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'intentions/pi_A?test_mode=1', 'intentions/pi_A/capture', 'intentions/pi_A?test_mode=1' ), $this->request_paths( $transport ) );
		$this->assertSame( array( 'pi_A', 'requires_capture', 'pi_A' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id() ) );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		wc_get_container()->get( EventIngestor::class )->process( $this->recorded_payment_success_event( $order, 'pi_A', 'ch_A' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertTrue( $order->is_paid(), 'The capture pays the order.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'was <strong>successfully charged</strong>' ), 'No payment note.' );
	}

	/**
	 * @testdox A failed order screen capture on an order object loaded before another request stored an authorization keeps that authorization named, with requires_capture.
	 *
	 * The order screen acts on the object its request loaded, before the capture claims the order payment lock. The stale
	 * object stores the earlier attempt with requires_capture; another request then stores the authorization A. The failure
	 * writes the earlier attempt and requires_capture, which the stale object already holds, so the save changes nothing
	 * and A stays stored. The row uses the order screen because the CPT store's status save reads the meta again before
	 * the status hook fires.
	 */
	public function test_failed_capture_on_an_order_object_loaded_before_an_authorization_was_stored_keeps_it(): void {
		$order = StoredPaymentRecords::authorization( 'pi_earlier', 'ch_earlier' );
		$stale = wc_get_order( $order->get_id() );
		// The request that loaded the order has read its meta before the other request writes; the CPT store reads it lazily.
		$stale->get_meta_data();
		$transport = $this->create_transport(
			self::platform_refusal( 'This PaymentIntent could not be captured because it has a status of requires_payment_method.' ),
			self::status_answer( 'pi_earlier', 'requires_payment_method' )
		);
		$other     = wc_get_order( $order->get_id() );
		$other->update_meta_data( '_intent_id', 'pi_A' );
		$other->save();

		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $this->create_provider_over_fake_transport( $transport ), $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();
		$this->fire_order_hook( 'woocommerce_order_action_capture_charge', $stale );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'intentions/pi_earlier/capture', 'intentions/pi_earlier?test_mode=1' ), $this->request_paths( $transport ) );
		$this->assertSame( array( 'pi_A', 'requires_capture', 'pi_earlier' ), array( $order->get_meta( '_intent_id', true ), $order->get_meta( '_intention_status', true ), $order->get_transaction_id() ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, self::CAPTURE_FAILURE_NOTE ) );
	}

	/**
	 * Run a capture or cancel of an order the way a merchant starts it, over a platform transport: the order screen's
	 * action, the authorizations list on the transaction ID, or a status change to Completed or Cancelled.
	 *
	 * @param string         $trigger   `order screen`, `list` or `status change`.
	 * @param string         $operation Capture or cancel.
	 * @param WC_Order       $order     Order.
	 * @param FakeHttpClient $transport Platform transport the provider sends through.
	 * @return mixed The list's answer, or null.
	 */
	private function run_operation_from( string $trigger, string $operation, WC_Order $order, FakeHttpClient $transport ) {
		$provider   = $this->create_provider_over_fake_transport( $transport );
		$is_capture = PaymentProcessingService::OPERATION_CAPTURE === $operation;
		if ( 'list' === $trigger ) {
			$transaction_id = (string) $order->get_transaction_id();
			$controller     = new WooPaymentsAuthorizationsRestController();
			$controller->init( $this->create_platform( self::intent_body( $transaction_id, 'requires_capture', $order ), null, new \ArrayObject() ), wc_get_container()->get( PaymentProcessingService::class ), $provider );
			$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . ( $is_capture ? '/capture_authorization' : '/cancel_authorization' ) );
			$request->set_param( 'order_id', $order->get_id() );
			$request->set_param( 'payment_intent_id', $transaction_id );

			return $is_capture ? $controller->capture_authorization( $request ) : $controller->cancel_authorization( $request );
		}

		$this->sut = $this->create_controller( wc_get_container()->get( PaymentProcessingService::class ), $provider, $this->create_platform( null, null, new \ArrayObject() ) );
		$this->sut->register();
		if ( 'status change' === $trigger ) {
			wc_get_order( $order->get_id() )->update_status( $is_capture ? OrderStatus::COMPLETED : OrderStatus::CANCELLED );

			return null;
		}

		$this->fire_order_hook( $is_capture ? 'woocommerce_order_action_capture_charge' : 'woocommerce_order_action_cancel_authorization', wc_get_order( $order->get_id() ) );

		return null;
	}

	/**
	 * Get the authorization actions the order screen offers for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function order_screen_actions( WC_Order $order ): array {
		global $theorder;
		$theorder = wc_get_order( $order->get_id() );

		return array_keys( $this->create_controller()->handle_woocommerce_order_actions( array() ) );
	}

	/**
	 * Create a platform transport that answers the given responses in order.
	 *
	 * @param array<string,mixed> ...$responses Transport responses.
	 * @return FakeHttpClient
	 */
	private function create_transport( array ...$responses ): FakeHttpClient {
		$transport            = new FakeHttpClient();
		$transport->responses = $responses;

		return $transport;
	}

	/**
	 * Build a JSON transport response.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded body.
	 * @return array<string,mixed>
	 */
	private static function http_json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * The platform's 502 when its own Stripe call failed (wpcom `class-platform-failure-exception.php:30`): the call may
	 * have gone through.
	 *
	 * @return array<string,mixed>
	 */
	private static function platform_bad_gateway(): array {
		return self::http_json(
			502,
			array(
				'code'    => 'wcpay_request_failure',
				'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
				'data'    => array( 'status' => 502 ),
			)
		);
	}

	/**
	 * The platform's 400 for a capture or cancel Stripe refused, with Stripe's message (HAND-BUILT envelope as in
	 * WooPaymentsPaymentOperationServiceTest's failed-cancel row; the messages are the ones recorded on a local store).
	 *
	 * @param string $message Stripe's message.
	 * @return array<string,mixed>
	 */
	private static function platform_refusal( string $message ): array {
		return self::http_json(
			400,
			array(
				'code'    => 'wcpay_bad_request',
				'message' => $message,
				'data'    => array( 'status' => 400 ),
			)
		);
	}

	/**
	 * A platform answer for an intent read, reduced to its ID, status, amounts and the order it names.
	 *
	 * @param string   $intent_id Intent ID.
	 * @param string   $status    Intent status.
	 * @param WC_Order $order     Order its metadata names.
	 * @return array<string,mixed>
	 */
	private static function intent_answer( string $intent_id, string $status, WC_Order $order ): array {
		return self::http_json( 200, self::intent_body( $intent_id, $status, $order ) );
	}

	/**
	 * An intent reduced to its ID, status, amounts and the order it names.
	 *
	 * @param string   $intent_id Intent ID.
	 * @param string   $status    Intent status.
	 * @param WC_Order $order     Order its metadata names.
	 * @return array<string,mixed>
	 */
	private static function intent_body( string $intent_id, string $status, WC_Order $order ): array {
		return array(
			'id'                => $intent_id,
			'status'            => $status,
			'amount'            => 1099,
			'amount_capturable' => 'requires_capture' === $status ? 1099 : 0,
			'currency'          => 'usd',
			'metadata'          => array( 'order_id' => (string) $order->get_id() ),
		);
	}

	/**
	 * A platform answer for an intent read, reduced to its ID and status.
	 *
	 * @param string $intent_id Intent ID.
	 * @param string $status    Intent status.
	 * @return array<string,mixed>
	 */
	private static function status_answer( string $intent_id, string $status ): array {
		return self::http_json(
			200,
			array(
				'id'     => $intent_id,
				'status' => $status,
			)
		);
	}

	/**
	 * @testdox The unpaid-order check is hooked last on core's cancel filter, with the order.
	 */
	public function test_registers_the_unpaid_order_check_last(): void {
		$this->sut = $this->create_controller();

		$this->sut->register();

		$this->assertSame( PHP_INT_MAX, has_filter( 'woocommerce_cancel_unpaid_order', array( $this->sut, 'handle_woocommerce_cancel_unpaid_order' ) ) );
	}

	/**
	 * @testdox Core's hold-stock job cancels, with no read by the check, an unpaid order of another gateway and a WooPayments order that stores no payment intent to check, and keeps one made in WP Admin.
	 *
	 * Another gateway's order and an order made in WP Admin are answered as they came. An order storing no intent, a
	 * setup intent or a canceled intent has nothing to check.
	 */
	public function test_unpaid_orders_without_a_payment_to_check_are_answered_as_they_came(): void {
		$other_gateway = $this->create_unpaid_order( 'requires_action' );
		$other_gateway->set_payment_method( 'cod' );
		$other_gateway->save();
		$this->age_unpaid_order( $other_gateway );
		$admin_made = $this->create_unpaid_order( 'requires_action' );
		$admin_made->set_created_via( 'admin' );
		$admin_made->save();
		$this->age_unpaid_order( $admin_made );
		$no_intent    = $this->create_unpaid_order( '', '' );
		$setup_intent = $this->create_unpaid_order( 'requires_action', 'seti_unpaid' );
		$canceled     = $this->create_unpaid_order( 'canceled' );
		$transport    = $this->create_transport();

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array( 'intentions/pi_unpaid?test_mode=0' ), $this->request_paths( $transport ), 'The check reads nothing in the order\'s mode; the Cancelled listener reads only the other gateway\'s order, in the store\'s, and stands down on the timer\'s cancel of a WooPayments order.' );
		foreach ( array( $other_gateway, $no_intent, $setup_intent, $canceled ) as $order ) {
			$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
		}
		$this->assertSame( OrderStatus::PENDING, $this->reload( $admin_made )->get_status() );
	}

	/**
	 * @testdox The unpaid-order check returns another callback's answer unchanged when it does not keep the order.
	 */
	public function test_unpaid_order_check_returns_the_incoming_answer_unchanged(): void {
		$this->sut = $this->create_controller();
		$unmarked  = $this->create_unpaid_order( '', '' );
		$marked    = $this->create_unpaid_order( 'requires_action' );

		$this->assertSame( 'yes', $this->sut->handle_woocommerce_cancel_unpaid_order( 'yes', $unmarked ) );
		$this->assertSame( 'yes', $this->sut->handle_woocommerce_cancel_unpaid_order( 'yes', null ) );
		$this->assertSame( 0, $this->sut->handle_woocommerce_cancel_unpaid_order( 0, $marked ) );
		$this->assertNull( $this->sut->handle_woocommerce_cancel_unpaid_order( null, $marked ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps, with no platform read, an unpaid WooPayments order that has a paid date.
	 */
	public function test_unpaid_order_with_a_paid_date_is_kept_unread(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$order->set_date_paid( time() - HOUR_IN_SECONDS );
		$order->save();
		$this->age_unpaid_order( $order );
		$transport = $this->create_transport();

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job keeps, with no platform read, an unpaid order whose order payment lock is held, and reads it once the lock expired.
	 */
	public function test_unpaid_order_whose_lock_is_held_is_kept_unread_until_the_lock_expires(): void {
		global $wpdb;
		$order      = $this->create_unpaid_order( 'requires_action' );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->assertNotNull( wc_get_container()->get( OrderPaymentLock::class )->claim( $order, $vocabulary, 'pi_other', 'payment operation' ) );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array(), $this->request_paths( $transport ), 'A held lock keeps the order unread.' );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );

		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 10 ) ), array( 'option_name' => '_transient_timeout_' . $vocabulary->get_order_lock_key( $order ) ) );
		$this->age_unpaid_order( $order );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 1, $this->request_paths( $transport ), 'An expired lock is free.' );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job completes, instead of cancelling, an unpaid order whose stored $stored_status intent the platform reads as succeeded.
	 *
	 * A Pending order can store succeeded after a request died between its two writes.
	 *
	 * @testWith ["requires_action"]
	 *           ["succeeded"]
	 *
	 * @param string $stored_status Intent status the order stores.
	 */
	public function test_unpaid_order_whose_payment_succeeded_is_completed( string $stored_status ): void {
		$order     = $this->create_unpaid_order( $stored_status );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'succeeded' ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertSame( array( 'intentions/pi_unpaid?test_mode=1' ), $this->request_paths( $transport ), 'One read, in the order\'s own mode.' );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'successfully charged' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
		$this->assertFalse( UnpaidOrderTimeoutMarker::is_marked( $order ) );
	}

	/**
	 * @testdox The hold-stock job settles a payment in full even when the job reads the order with fewer decimals.
	 */
	public function test_unpaid_order_whose_stored_total_matches_the_charge_is_completed(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$order->set_currency( 'EUR' );
		$order->set_total( '10.40' );
		$order->save();
		$this->age_unpaid_order( $order );
		update_option( 'woocommerce_price_num_decimals', '0' );
		$intent             = self::unpaid_intent( $order, 'succeeded', 1040 );
		$intent['currency'] = 'eur';
		$this->run_unpaid_order_job( $this->create_transport( self::http_json( 200, $intent ) ) );

		$this->assertSame( OrderStatus::PROCESSING, $this->reload( $order )->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'successfully charged' ) );
	}

	/**
	 * @testdox Core's hold-stock job puts an unpaid order whose payment the platform holds as an authorization On hold, authorized, and releases nothing.
	 */
	public function test_unpaid_order_whose_payment_is_authorized_goes_on_hold(): void {
		$order      = $this->create_unpaid_order( 'requires_action' );
		$transport  = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_capture' ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->request_paths( $transport ), 'Only the read: no cancel is sent.' );
	}

	/**
	 * @testdox Core's hold-stock job puts On hold, with the mismatch note, an unpaid order whose succeeded payment is for another amount.
	 */
	public function test_unpaid_order_whose_payment_is_for_another_amount_goes_on_hold(): void {
		$order     = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'succeeded', 500 ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order whose payment is disputed, with the disputed-payment note once.
	 */
	public function test_unpaid_order_whose_payment_is_disputed_is_kept_with_its_note(): void {
		$order     = $this->create_unpaid_order( 'requires_action' );
		$disputed  = self::http_json( 200, self::unpaid_intent( $order, 'succeeded', 1099, array( 'disputed' => true ) ) );
		$transport = $this->create_transport( $disputed, $disputed );

		$this->run_unpaid_order_job( $transport );
		$this->age_unpaid_order( $order );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'is disputed' ) );
	}

	/**
	 * @testdox A payment found on an unpaid order that cannot be applied keeps it, and core's job goes on to the next order.
	 */
	public function test_unpaid_order_whose_payment_cannot_be_applied_is_kept_and_the_job_goes_on(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$next  = $this->create_unpaid_order( '', '' );
		$next->set_payment_method( 'cod' );
		$next->save();
		$this->age_unpaid_order( $next );
		$confirmation = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation->method( 'apply_attached_payment_intent' )->willThrowException( new RuntimeException( 'Lifecycle failed.' ) );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'succeeded' ) ) );

		$this->run_unpaid_order_job( $transport, null, array( WooPaymentsIntentConfirmationService::class => $confirmation ) );

		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $next )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job cancels an unpaid order whose payment holds no money of its own, $_dataName, with one read across the check and the Cancelled listener.
	 *
	 * The Cancelled listener stands down on the timer's cancel: it reads and stores nothing.
	 *
	 * @testWith ["awaiting action", "requires_action", {}, {}]
	 *           ["fully refunded", "succeeded", {"refunded": true, "amount_refunded": 1099}, {}]
	 *           ["paying another order", "succeeded", {}, {"order_id": "999999"}]
	 *
	 * @param string              $case_name Case name.
	 * @param string              $status    Status the platform reads.
	 * @param array<string,mixed> $charge    Charge fields.
	 * @param array<string,mixed> $metadata  Intent metadata replacing the order's.
	 */
	public function test_unpaid_order_without_money_of_its_own_is_cancelled_with_one_read( string $case_name, string $status, array $charge, array $metadata ): void {
		unset( $case_name );
		$order  = $this->create_unpaid_order( 'requires_payment_method' );
		$intent = self::unpaid_intent( $order, $status, 1099, $charge );
		if ( array() !== $metadata ) {
			$intent['metadata'] = $metadata;
		}
		$transport = $this->create_transport( self::http_json( 200, $intent ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ), 'The listener reads nothing.' );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'The listener stores nothing.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job, reading an unpaid order's payment that answers $_dataName, $expected.
	 *
	 * An answer with no usable status cannot be used, as a failed read cannot, so the order is kept inside its day. The
	 * checkout's duplicate-payment check counts the same answers as leaving the payment unknown.
	 *
	 * @dataProvider unpaid_order_intent_answers
	 *
	 * @param array<string,mixed>|callable $answer   Transport response, or a callable building it from the order.
	 * @param string                       $expected "cancels it" or "keeps it with no note".
	 */
	public function test_unpaid_order_intent_answer_with_no_usable_status_keeps_the_order( $answer, string $expected ): void {
		$order               = $this->create_unpaid_order( 'requires_payment_method' );
		$transport           = $this->create_transport();
		$transport->response = is_callable( $answer ) ? $answer( $order ) : $answer;

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		if ( 'cancels it' === $expected ) {
			$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
			return;
		}
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'could not be checked' ) + $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * Answers to an unpaid order's intent read, as the transport returns them, and what the job does with the order.
	 *
	 * @return array<string,array{0:array<string,mixed>|callable,1:string}>
	 */
	public function unpaid_order_intent_answers(): array {
		$with_status = static function ( $status ): callable {
			return static function ( WC_Order $order ) use ( $status ): array {
				$intent           = self::unpaid_intent( $order, 'succeeded' );
				$intent['status'] = $status;
				return self::http_json( 200, $intent );
			};
		};
		$raw         = static function ( string $content_type, string $body ): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => $content_type ),
				'body'     => $body,
			);
		};

		return array(
			'an intent awaiting a payment method' => array(
				static function ( WC_Order $order ): array {
					return self::http_json( 200, self::unpaid_intent( $order, 'requires_payment_method' ) );
				},
				'cancels it',
			),
			'a null JSON body'                    => array( $raw( 'application/json', 'null' ), 'keeps it with no note' ),
			'an empty body'                       => array( $raw( 'application/json', '' ), 'keeps it with no note' ),
			'a page that is not JSON'             => array( $raw( 'text/html; charset=UTF-8', '<html><body>Bad gateway</body></html>' ), 'keeps it with no note' ),
			'an intent with no status'            => array(
				static function ( WC_Order $order ): array {
					$intent = self::unpaid_intent( $order, 'succeeded' );
					unset( $intent['status'] );
					return self::http_json( 200, $intent );
				},
				'keeps it with no note',
			),
			'an intent whose status is a number'  => array( $with_status( 1 ), 'keeps it with no note' ),
			'an intent whose status is empty'     => array( $with_status( '' ), 'keeps it with no note' ),
			'a server error'                      => array( self::platform_error( 500 ), 'keeps it with no note' ),
		);
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order whose payment cannot be read, reads it again at the next run, and lets core cancel it a day after core could first cancel it, with one note and no further read.
	 */
	public function test_unpaid_order_whose_payment_cannot_be_read_is_kept_for_a_day(): void {
		$order     = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport();

		$transport->response = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'could not be checked' ) + $this->count_notes_containing( $order, 'Unpaid order cancelled' ), 'Kept with no note.' );

		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW - MINUTE_IN_SECONDS );
		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status(), 'Still kept a minute before the day ends.' );

		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 3, $this->request_paths( $transport ), 'The Cancelled listener does not read again.' );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( PaymentProcessingService::OPERATION_CANCEL ) ), 'The listener stands down: no failure note.' );
	}

	/**
	 * @testdox Core's hold-stock job puts On hold, authorized, an unpaid order whose authorization could not be read at one run and is read at the next.
	 */
	public function test_unpaid_order_authorization_unread_at_one_run_goes_on_hold_at_the_next(): void {
		$order      = $this->create_unpaid_order( 'requires_action' );
		$transport  = $this->create_transport( self::platform_bad_gateway(), self::http_json( 200, self::unpaid_intent( $order, 'requires_capture' ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->age_unpaid_order( $order );
		$this->run_unpaid_order_job( $transport, $processing );

		$this->assertSame( OrderStatus::ON_HOLD, $this->reload( $order )->get_status() );
		$this->assertCount( 2, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox After a transport error, core's hold-stock job reads no other order in the run and keeps them all; one server error, an answer it cannot use, or a refused read, stops nothing: $_dataName.
	 *
	 * @dataProvider unpaid_order_read_failures
	 *
	 * @param string $case_name Case name.
	 * @param mixed  $response  Transport response to every read.
	 * @param int    $reads     Reads expected for the two orders.
	 */
	public function test_unpaid_order_read_failure_stops_the_run_at_once_only_for_a_transport_error( string $case_name, $response, int $reads ): void {
		unset( $case_name );
		$first               = $this->create_unpaid_order( 'requires_action' );
		$second              = $this->create_unpaid_order( 'requires_action' );
		$transport           = $this->create_transport();
		$transport->response = $response;

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( $reads, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $first )->get_status() );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $second )->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $first, 'could not be checked' ) + $this->count_notes_containing( $second, 'could not be checked' ) );
	}

	/**
	 * Read failures of the unpaid-order check and how many of two orders' reads the run makes.
	 *
	 * @return array<string,array{0:string,1:mixed,2:int}>
	 */
	public function unpaid_order_read_failures(): array {
		return array(
			'transport error'     => array( 'transport error', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 1 ),
			'server error'        => array( 'server error', self::platform_bad_gateway(), 2 ),
			'answer of no status' => array( 'answer of no status', self::http_json( 200, array( 'id' => 'pi_unpaid' ) ), 2 ),
			'refused read'        => array(
				'refused read',
				self::http_json(
					404,
					array(
						'code'    => 'resource_missing',
						'message' => 'No such payment_intent.',
						'data'    => array( 'status' => 404 ),
					)
				),
				2,
			),
		);
	}

	/**
	 * @testdox Core's hold-stock job never cancels an unpaid order that, while its payment is read as awaiting action, $_dataName.
	 *
	 * @dataProvider unpaid_order_changes_during_the_read
	 *
	 * @param string $case_name Case name.
	 * @param string $change    The change another request makes: `key`, `lock`, or the status it saves.
	 * @param string $expected  Order status at the end.
	 */
	public function test_unpaid_order_changed_while_its_payment_is_read_is_kept( string $case_name, string $change, string $expected ): void {
		unset( $case_name );
		$order     = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );
		$order_id  = $order->get_id();
		$changed   = false;
		add_filter(
			'wcpay_api_request_response',
			function ( $response ) use ( &$changed, $order_id, $change ) {
				if ( $changed ) {
					return $response;
				}
				$changed = true;
				$stored  = $this->reload( wc_get_order( $order_id ) );
				if ( 'lock' === $change ) {
					wc_get_container()->get( OrderPaymentLock::class )->claim( $stored, new WooPaymentsPersistenceVocabulary(), 'pi_other', 'payment operation' );
				} elseif ( 'key' === $change ) {
					$stored->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'charge_key_new' );
					$stored->save_meta_data();
				} else {
					$stored->set_status( $change );
					$stored->save();
				}

				return $response;
			}
		);

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertTrue( $changed );
		$this->assertSame( $expected, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ), 'Core never cancels it.' );
	}

	/**
	 * Changes another request makes to an unpaid order while its payment is read.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function unpaid_order_changes_during_the_read(): array {
		return array(
			'a checkout keeps a new charge key' => array( 'a checkout keeps a new charge key', 'key', OrderStatus::PENDING ),
			'another operation claims its lock' => array( 'another operation claims its lock', 'lock', OrderStatus::PENDING ),
			'a payment moves it to Processing'  => array( 'a payment moves it to Processing', OrderStatus::PROCESSING, OrderStatus::PROCESSING ),
			'the merchant puts it On hold'      => array( 'the merchant puts it On hold', OrderStatus::ON_HOLD, OrderStatus::ON_HOLD ),
		);
	}

	/**
	 * @testdox A merchant's own cancel after the unpaid-order check read the order reads it again and releases its authorization.
	 *
	 * The check's answer is taken only by the cancel of an order core's job cancelled.
	 */
	public function test_a_hand_cancel_after_the_unpaid_order_check_reads_again_and_releases_the_authorization(): void {
		$order      = $this->create_unpaid_order( 'requires_action' );
		$transport  = $this->create_transport(
			self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ),
			self::http_json( 200, self::unpaid_intent( $order, 'requires_capture' ) )
		);
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->once() )->method( 'cancel' )->willReturn( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_unpaid' ) );
		$this->sut = $this->create_controller( $processing, null, $this->create_unpaid_order_platform( $transport ) );
		$this->sut->register();

		try {
			$this->assertTrue( $this->sut->handle_woocommerce_cancel_unpaid_order( true, $order ) );
			$this->reload( $order )->update_status( OrderStatus::CANCELLED );
		} finally {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}

		$this->assertCount( 2, $this->request_paths( $transport ), 'The hand cancel reads the payment again.' );
	}

	/**
	 * @testdox Core's hold-stock job settles from the account's intents an unpaid order that keeps only a charge key: a $status payment of its own goes $expected, with one lookup from the time the key was kept to the order's last change and no release.
	 *
	 * @testWith ["succeeded", "processing"]
	 *           ["requires_capture", "on-hold"]
	 *
	 * @param string $status   Status of the order's own intent in the list.
	 * @param string $expected Order status at the end.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_is_settled_from_the_payment_the_lookup_finds( string $status, string $expected ): void {
		$kept_at    = time() - 3 * HOUR_IN_SECONDS;
		$order      = $this->create_key_only_order( $kept_at );
		$changed_at = $this->reload( $order )->get_date_modified()->getTimestamp();
		$transport  = $this->create_transport( self::http_json( 200, self::intent_list( self::key_only_intent( $order, $status ) ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertSame( array( $kept_at - HOUR_IN_SECONDS, max( $kept_at, $changed_at ) + HOUR_IN_SECONDS ), $this->listed_window( $transport ), 'One account list, from an hour before the key was kept to an hour after the later of that and the last change.' );
		$this->assertSame( $expected, $order->get_status() );
		$this->assertSame( 'pi_unpaid', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job puts On hold, with the mismatch note, an unpaid order keeping only a charge key whose own payment the lookup finds is for another amount.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_whose_payment_is_for_another_amount_goes_on_hold(): void {
		$order     = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$transport = $this->create_transport( self::http_json( 200, self::intent_list( self::key_only_intent( $order, 'succeeded', 500 ) ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order keeping only a charge key whose own payment the lookup finds disputed, with the disputed-payment note naming that payment once.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_whose_payment_is_disputed_is_kept_with_its_note(): void {
		$order                                      = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$disputed                                   = self::key_only_intent( $order, 'succeeded' );
		$disputed['charges']['data'][0]['disputed'] = true;
		$transport                                  = $this->create_transport( self::http_json( 200, self::intent_list( $disputed ) ), self::http_json( 200, self::intent_list( $disputed ) ) );

		$this->run_unpaid_order_job( $transport );
		$this->age_unpaid_order( $order );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, '(pi_unpaid) is disputed' ) );
	}

	/**
	 * @testdox Core's hold-stock job looks up an unpaid order keeping a charge key with no time kept from the order's creation.
	 */
	public function test_unpaid_order_keeping_a_charge_key_with_no_time_kept_is_looked_up_from_its_creation(): void {
		$order     = $this->create_key_only_order( null );
		$created   = (int) $order->get_date_created()->getTimestamp();
		$transport = $this->create_transport( self::http_json( 200, self::intent_list() ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array( $created - HOUR_IN_SECONDS, $created + HOUR_IN_SECONDS ), $this->listed_window( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job cancels an unpaid order keeping only a charge key when the lookup finds no money of its own: $_dataName.
	 *
	 * @dataProvider key_only_lookups_without_money
	 *
	 * @param string                         $case_name Case name.
	 * @param array<int,array<string,mixed>> $intents   Intents the account list returns, as built for the order.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_is_cancelled_when_the_lookup_finds_no_money( string $case_name, array $intents ): void {
		unset( $case_name );
		$order = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$list  = array();
		foreach ( $intents as $intent ) {
			$list[] = array_replace_recursive( self::key_only_intent( $order, (string) $intent['status'] ), $intent );
		}
		$transport = $this->create_transport( self::http_json( 200, self::intent_list( ...$list ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ), 'One lookup; the Cancelled listener reads nothing.' );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'could not be checked' ) );
	}

	/**
	 * Account lists in which an unpaid order keeping only a charge key has no money of its own.
	 *
	 * @return array<string,array{0:string,1:array<int,array<string,mixed>>}>
	 */
	public function key_only_lookups_without_money(): array {
		return array(
			'no intent'                => array( 'no intent', array() ),
			'its own intent declined'  => array( 'its own intent declined', array( array( 'status' => 'requires_payment_method' ) ) ),
			'its own payment refunded' => array(
				'its own payment refunded',
				array(
					array(
						'status'  => 'succeeded',
						'charges' => array(
							'data' => array(
								array(
									'refunded'        => true,
									'amount_refunded' => 1099,
								),
							),
						),
					),
				),
			),
			'another order\'s payment' => array(
				'another order\'s payment',
				array(
					array(
						'status'   => 'succeeded',
						'metadata' => array(
							'order_id'  => '999999',
							'order_key' => 'wc_order_other',
						),
					),
				),
			),
		);
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order keeping only a charge key while the lookup cannot tell whether it was paid, $_dataName, and applies nothing.
	 *
	 * A loose match is an intent naming the order by ID without its key: it may be another store's order, so it never pays.
	 *
	 * @dataProvider key_only_lookups_that_cannot_tell
	 *
	 * @param string $case_name Case name.
	 * @param mixed  $response  Transport response to the account list.
	 * @param string $mode      Mode the order kept with the key; `store` for the store's.
	 * @param int    $reads     Requests expected.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_is_kept_while_the_lookup_cannot_tell( string $case_name, $response, string $mode, int $reads ): void {
		unset( $case_name );
		$order = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS, 'store' === $mode ? WooPaymentsOrderMode::PRODUCTION : $mode );
		if ( is_callable( $response ) ) {
			$response = $response( $order );
		}
		$transport           = $this->create_transport();
		$transport->response = $response;

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( $reads, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ), 'Nothing applied.' );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'could not be checked' ) + $this->count_notes_containing( $order, 'Unpaid order cancelled' ), 'Kept with no note.' );
	}

	/**
	 * Lookups that cannot tell whether an unpaid order keeping only a charge key was paid.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string,3:int}>
	 */
	public function key_only_lookups_that_cannot_tell(): array {
		return array(
			'more intents than one page'     => array(
				'more intents than one page',
				static fn( WC_Order $order ): array => self::http_json( 200, array( 'has_more' => true ) + self::intent_list( self::key_only_intent( $order, 'requires_payment_method' ) ) ),
				'store',
				1,
			),
			'a loose match holding money'    => array(
				'a loose match holding money',
				static fn( WC_Order $order ): array => self::http_json( 200, self::intent_list( self::unpaid_intent( $order, 'succeeded' ) ) ),
				'store',
				1,
			),
			'a refused list'                 => array(
				'a refused list',
				self::http_json(
					400,
					array(
						'code'    => 'invalid_request_error',
						'message' => 'Invalid created filter.',
						'data'    => array( 'status' => 400 ),
					)
				),
				'store',
				1,
			),
			'an answer with no list'         => array( 'an answer with no list', self::http_json( 200, array( 'object' => 'list' ) ), 'store', 1 ),
			'the key kept in the other mode' => array( 'the key kept in the other mode', self::http_json( 200, array( 'data' => array() ) ), WooPaymentsOrderMode::TEST, 0 ),
		);
	}

	/**
	 * @testdox Core's hold-stock job lets core cancel, with the one note, an unpaid order keeping only a charge key that the lookup could not tell about for a day after core could first cancel it.
	 */
	public function test_unpaid_order_keeping_only_a_charge_key_the_lookup_cannot_tell_about_is_cancelled_a_day_after_its_creation(): void {
		$order               = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$transport           = $this->create_transport();
		$transport->response = self::http_json( 200, array( 'has_more' => true ) + self::intent_list() );

		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW - MINUTE_IN_SECONDS );
		$this->run_unpaid_order_job( $transport );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status(), 'Kept a minute before the day ends.' );

		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps, with no platform read, an unpaid order whose order payment lock is held while it $_dataName.
	 *
	 * A checkout holds the lock while its charge is in flight, before any intent is stored.
	 *
	 * @testWith ["keeps only a charge key", "key"]
	 *           ["stores the last attempt's canceled intent", "canceled"]
	 *
	 * @param string $case_name Case name.
	 * @param string $stores    What the order stores: `key` for only a charge key, `canceled` for a canceled intent.
	 */
	public function test_unpaid_order_whose_lock_is_held_is_kept_unread_whatever_it_stores( string $case_name, string $stores ): void {
		unset( $case_name );
		$order = 'key' === $stores ? $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS ) : $this->create_unpaid_order( 'canceled' );
		$this->assertNotNull( wc_get_container()->get( OrderPaymentLock::class )->claim( $order, new WooPaymentsPersistenceVocabulary(), 'charge_key_kept', 'payment operation' ) );
		$transport = $this->create_transport( self::http_json( 200, self::intent_list() ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array(), $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job puts On hold, instead of cancelling, an unpaid order whose stored intent the platform reads as a Multibanco voucher still awaiting payment.
	 */
	public function test_unpaid_order_whose_multibanco_voucher_awaits_payment_goes_on_hold(): void {
		$order                             = $this->create_unpaid_order( 'requires_action' );
		$voucher                           = self::unpaid_intent( $order, 'requires_action' );
		$voucher['payment_method_options'] = array( 'multibanco' => array() );
		$voucher['next_action']            = array(
			'type'                       => 'multibanco_display_details',
			'multibanco_display_details' => array(
				'entity'     => '12345',
				'reference'  => '123 456 789',
				'expires_at' => time() + 3 * DAY_IN_SECONDS,
			),
		);
		$transport                         = $this->create_transport( self::http_json( 200, $voucher ) );
		$processing                        = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::ON_HOLD, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps a subscription's first order while its payment still awaits the shopper's action, with one read, no note and nothing written.
	 */
	public function test_unpaid_subscription_parent_with_an_open_payment_step_is_kept_with_nothing_written(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->relate_subscription_to_unpaid_order( $order, 'parent' );
		$changed    = $this->reload( $order )->get_date_modified()->getTimestamp();
		$transport  = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertSame( array( 'intentions/pi_unpaid?test_mode=1' ), $this->request_paths( $transport ), 'One read, no cancel request.' );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'No note while it is kept.' );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $changed, $order->get_date_modified()->getTimestamp(), 'Keeping writes nothing, so the window does not move.' );
	}

	/**
	 * @testdox Core's hold-stock job reads a kept subscription's first order again at the next run and completes it once the shopper paid.
	 */
	public function test_unpaid_subscription_parent_kept_for_an_open_payment_step_settles_at_the_next_run(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->relate_subscription_to_unpaid_order( $order, 'parent' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ), self::http_json( 200, self::unpaid_intent( $order, 'succeeded' ) ) );

		$this->run_unpaid_order_job( $transport );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job still cancels an unpaid order with one read when $_dataName.
	 *
	 * @testWith ["it is no subscription's first order and awaits the shopper's action", "", "requires_action", false]
	 *           ["it is a subscription's first order past its keeping window", "parent", "requires_action", true]
	 *           ["it is a subscription's first order whose payment method is gone", "parent", "requires_payment_method", false]
	 *           ["it is a subscription's first order whose payment was canceled", "parent", "canceled", false]
	 *           ["it is a subscription's switch order, not its first", "switch", "requires_action", false]
	 *
	 * @param string $case_name Case name.
	 * @param string $relation  How a subscription relates to the order; empty for none.
	 * @param string $status    Status the platform reads.
	 * @param bool   $past      Whether the order is past its keeping window.
	 */
	public function test_unpaid_order_is_cancelled_unless_a_subscriptions_first_order_awaits_the_shoppers_action( string $case_name, string $relation, string $status, bool $past ): void {
		unset( $case_name );
		$order = $this->create_unpaid_order( 'requires_action' );
		if ( '' !== $relation ) {
			$this->relate_subscription_to_unpaid_order( $order, $relation );
		}
		if ( $past ) {
			$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		}
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, $status ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps Pending an order that is both a resubscribe order and a new subscription's first order, while its payment awaits the shopper's action.
	 */
	public function test_unpaid_resubscribe_order_that_is_a_subscriptions_first_order_is_kept(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->relate_subscription_to_unpaid_order( $order, 'resubscribe' );
		$this->relate_subscription_to_unpaid_order( $order, 'parent' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->assertCount( 1, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox Core's hold-stock job still cancels a subscription's first order whose stored intent the platform reads as another order's, one read, though it awaits action.
	 */
	public function test_unpaid_subscription_parent_whose_open_intent_is_another_orders_is_cancelled(): void {
		$order              = $this->create_unpaid_order( 'requires_action' );
		$intent             = self::unpaid_intent( $order, 'requires_action' );
		$intent['metadata'] = array( 'order_id' => '999999' );
		$this->relate_subscription_to_unpaid_order( $order, 'parent' );
		$transport = $this->create_transport( self::http_json( 200, $intent ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Without WooCommerce Subscriptions, core's hold-stock job cancels an unpaid order whose payment awaits the shopper's action, as before.
	 *
	 * Runs in its own process, so no WooCommerce Subscriptions double declared by another test is loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unpaid_order_awaiting_action_is_cancelled_without_subscriptions(): void {
		$this->assertFalse( function_exists( 'wcs_order_contains_subscription' ), 'WooCommerce Subscriptions must not be loaded.' );
		$order     = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job puts On hold, not keeps Pending, a subscription's first order whose Multibanco voucher awaits payment.
	 */
	public function test_unpaid_subscription_parent_whose_multibanco_voucher_awaits_payment_goes_on_hold(): void {
		$order                             = $this->create_unpaid_order( 'requires_action' );
		$voucher                           = self::unpaid_intent( $order, 'requires_action' );
		$voucher['payment_method_options'] = array( 'multibanco' => array() );
		$voucher['next_action']            = array(
			'type'                       => 'multibanco_display_details',
			'multibanco_display_details' => array(
				'entity'     => '12345',
				'reference'  => '123 456 789',
				'expires_at' => time() + 3 * DAY_IN_SECONDS,
			),
		);
		$this->relate_subscription_to_unpaid_order( $order, 'parent' );

		$this->run_unpaid_order_job( $this->create_transport( self::http_json( 200, $voucher ) ) );

		$this->assertSame( OrderStatus::ON_HOLD, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox After a lookup's server error, core's hold-stock job looks up no unpaid order keeping only a charge key for the rest of the run; a read's server error or a refused lookup stops nothing: $_dataName.
	 *
	 * Core's job takes the orders in the order they were created.
	 *
	 * @testWith ["a lookup's server error", "key", 502, 1]
	 *           ["a read's server error before a lookup", "intent", 502, 2]
	 *           ["a refused lookup", "key", 400, 2]
	 *
	 * @param string $case_name Case name.
	 * @param string $first     What the first order stores: `key` for only a charge key, `intent` for an intent.
	 * @param int    $code      HTTP status of every answer.
	 * @param int    $reads     Requests expected for the two orders.
	 */
	public function test_unpaid_order_lookup_failure_stops_the_run_only_for_a_transport_or_server_error( string $case_name, string $first, int $code, int $reads ): void {
		unset( $case_name );
		$orders              = array(
			'key' === $first ? $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS ) : $this->create_unpaid_order( 'requires_action' ),
			$this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS ),
		);
		$transport           = $this->create_transport();
		$transport->response = 502 === $code ? self::platform_bad_gateway() : self::http_json(
			$code,
			array(
				'code'    => 'invalid_request_error',
				'message' => 'Refused.',
				'data'    => array( 'status' => $code ),
			)
		);

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( $reads, $this->request_paths( $transport ) );
		foreach ( $orders as $order ) {
			$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		}
	}

	/**
	 * @testdox On core's hold-stock cancel of an unpaid order with no money, the Cancelled listener reads, releases and stores nothing.
	 */
	public function test_the_timer_cancel_of_an_order_with_no_money_is_left_alone_by_the_cancelled_listener(): void {
		$order      = $this->create_unpaid_order( 'requires_action' );
		$transport  = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ), self::http_json( 200, self::unpaid_intent( $order, 'requires_capture' ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertSame( array( 'intentions/pi_unpaid?test_mode=1' ), $this->request_paths( $transport ), 'Only the check\'s read.' );
		$this->assertSame( OrderStatus::CANCELLED, $order->get_status() );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 0, $this->count_notes( $order, $this->failure_note( PaymentProcessingService::OPERATION_CANCEL ) ) );
	}

	/**
	 * @testdox Core's hold-stock job cancels an unpaid order storing an authorization the platform reads as another order's, and releases nothing.
	 */
	public function test_the_timer_cancel_never_releases_a_stored_authorization(): void {
		$order              = $this->create_unpaid_order( 'requires_capture' );
		$intent             = self::unpaid_intent( $order, 'requires_capture' );
		$intent['metadata'] = array( 'order_id' => '999999' );
		$transport          = $this->create_transport( self::http_json( 200, $intent ) );
		$processing         = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
		$this->assertCount( 1, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox A merchant's own cancel of an order the timer once cancelled and that was restored releases its stored authorization, as before, even when core clears the marker after the listener.
	 */
	public function test_a_hand_cancel_of_an_order_the_timer_once_cancelled_releases_the_authorization(): void {
		$order = $this->create_unpaid_order( 'requires_capture' );
		$order->update_meta_data( UnpaidOrderTimeoutMarker::META_KEY, 'yes' );
		$order->set_status( OrderStatus::ON_HOLD );
		$order->save();
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->once() )->method( 'cancel' )->willReturn( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_unpaid' ) );
		$transport = $this->create_transport();
		$markers   = array();
		foreach ( $GLOBALS['wp_filter']['woocommerce_order_status_cancelled']->callbacks[10] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof UnpaidOrderTimeoutMarker ) {
				$markers[] = $callback['function'];
				remove_action( 'woocommerce_order_status_cancelled', $callback['function'], 10 );
			}
		}
		$this->assertNotEmpty( $markers, 'Core clears the marker on other cancellations.' );
		$this->sut = $this->create_controller( $processing, null, $this->create_unpaid_order_platform( $transport ) );
		$this->sut->register();
		foreach ( $markers as $marker ) {
			add_action( 'woocommerce_order_status_cancelled', $marker, 10, 2 );
		}

		try {
			$this->reload( $order )->update_status( OrderStatus::CANCELLED );
		} finally {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}

		$this->assertSame( array(), $this->request_paths( $transport ), 'The stored authorization, with no read, as before.' );
		$this->assertFalse( UnpaidOrderTimeoutMarker::is_marked( $this->reload( $order ) ), 'Core cleared the marker, after the listener.' );
	}

	/**
	 * @testdox A merchant's own cancel of another order during core's hold-stock cancel reads that order and releases its authorization.
	 */
	public function test_a_hand_cancel_during_the_timer_cancel_of_another_order_releases_the_authorization(): void {
		$timed_out = $this->create_unpaid_order( 'requires_action' );
		$held      = $this->create_unpaid_order( 'requires_action' );
		$held->set_status( OrderStatus::ON_HOLD );
		$held->save();
		$transport  = $this->create_transport( self::http_json( 200, self::unpaid_intent( $timed_out, 'requires_action' ) ), self::http_json( 200, self::unpaid_intent( $held, 'requires_capture' ) ) );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->once() )->method( 'cancel' )->willReturn( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_unpaid' ) );
		add_action(
			'woocommerce_order_status_cancelled',
			function ( $order_id ) use ( $timed_out, $held ) {
				if ( (int) $order_id === $timed_out->get_id() ) {
					$this->reload( $held )->update_status( OrderStatus::CANCELLED );
				}
			},
			5
		);

		$this->run_unpaid_order_job( $transport, $processing );

		$this->assertSame( array( 'intentions/pi_unpaid?test_mode=1', 'intentions/pi_unpaid?test_mode=0' ), $this->request_paths( $transport ), 'The check\'s read, then the hand cancel\'s own.' );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $held )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock cancel of an order paid another way releases the WooPayments authorization it still stores, as before.
	 */
	public function test_the_timer_cancel_of_an_order_paid_another_way_releases_its_leftover_authorization(): void {
		$order = $this->create_unpaid_order( 'requires_capture' );
		$order->set_payment_method( 'cod' );
		$order->save();
		$this->age_unpaid_order( $order );
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->once() )->method( 'cancel' )->willReturn( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_unpaid' ) );
		$transport = $this->create_transport();

		$this->run_unpaid_order_job( $transport, $processing );

		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
		$this->assertSame( array(), $this->request_paths( $transport ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order created days ago but last changed two hours ago on its first unreadable answer, with no note.
	 */
	public function test_unpaid_order_window_counts_from_the_last_change(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->age_unpaid_order( $order, 3 * DAY_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) );
	}

	/**
	 * @testdox Core's hold-stock job keeps an unpaid order on its first unreadable answer under a two-day hold-stock setting.
	 */
	public function test_unpaid_order_window_adds_the_hold_stock_time(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->age_unpaid_order( $order, 49 * HOUR_IN_SECONDS, 49 * HOUR_IN_SECONDS );
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();

		$this->run_unpaid_order_job( $transport, null, array(), '2880' );

		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job keeps an unreadable unpaid order 23 hours after core could cancel it, and past the day cancels it with the cancel note once, even when a later callback keeps it on one run.
	 */
	public function test_unpaid_order_window_ends_a_day_after_core_could_cancel_it_with_the_note_once(): void {
		$inside              = $this->create_unpaid_order( 'requires_action' );
		$past                = $this->create_unpaid_order( 'requires_action' );
		$transport           = $this->create_transport();
		$transport->response = self::http_json( 200, array( 'id' => 'pi_unpaid' ) );
		$this->age_unpaid_order( $inside, null, HOUR_IN_SECONDS + 23 * HOUR_IN_SECONDS );
		$this->age_unpaid_order( $past, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$veto = static fn( $should_cancel, $order ) => $order instanceof WC_Order && $order->get_id() === $past->get_id() ? false : $should_cancel;

		$this->run_unpaid_order_job( $transport, null, array(), '60', static fn() => add_filter( 'woocommerce_cancel_unpaid_order', $veto, PHP_INT_MAX, 2 ) );
		remove_filter( 'woocommerce_cancel_unpaid_order', $veto, PHP_INT_MAX );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $past )->get_status(), 'The later callback kept it.' );
		$this->run_unpaid_order_job( $transport );

		$this->assertSame( OrderStatus::PENDING, $this->reload( $inside )->get_status() );
		$this->assertSame( 0, $this->count_notes( $inside, self::UNPAID_CANCEL_NOTE ) );
		$past = $this->reload( $past );
		$this->assertSame( OrderStatus::CANCELLED, $past->get_status() );
		$this->assertSame( 1, $this->count_notes( $past, self::UNPAID_CANCEL_NOTE ) );
	}

	/**
	 * @testdox Core's hold-stock job never cancels an unreadable unpaid order whose record shows a stored $stored_status payment: past the window it stays Pending, with no cancel request and the kept note once.
	 *
	 * @testWith ["succeeded"]
	 *           ["requires_capture"]
	 *           ["processing"]
	 *
	 * @param string $stored_status Intent status the order stores.
	 */
	public function test_unpaid_order_whose_record_shows_a_payment_is_never_cancelled( string $stored_status ): void {
		$order = $this->create_unpaid_order( $stored_status );
		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();
		$processing          = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );
		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ), 'One read a run, and no cancel request.' );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 1, $this->count_notes( $order, self::UNPAID_KEPT_NOTE ) );
		$this->assertSame( 0, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) );
	}

	/**
	 * @testdox An unpaid order kept because its record shows a payment is read again at the next run and goes $expected when the platform answers $status.
	 *
	 * @testWith ["requires_capture", "on-hold"]
	 *           ["succeeded", "processing"]
	 *
	 * @param string $status   Status the platform reads at the second run.
	 * @param string $expected Order status at the end.
	 */
	public function test_unpaid_order_kept_for_its_recorded_payment_settles_once_read( string $status, string $expected ): void {
		$order = $this->create_unpaid_order( $status );
		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$transport = $this->create_transport( self::platform_error( 429 ), self::http_json( 200, self::unpaid_intent( $order, $status ) ) );

		$this->run_unpaid_order_job( $transport );
		$this->assertSame( 1, $this->count_notes( $order, self::UNPAID_KEPT_NOTE ) );
		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( $expected, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Accepted: a save of an unpaid order inside its window, which core reads as its last change, starts the day again.
	 */
	public function test_unpaid_order_saved_inside_the_window_starts_the_day_again(): void {
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->age_unpaid_order( $order, 2 * DAY_IN_SECONDS, self::UNPAID_WINDOW - HOUR_IN_SECONDS );
		$order = $this->reload( $order );
		$order->update_meta_data( '_shipping_note', 'Leave at the door.' );
		$order->save();
		$this->age_unpaid_order( $order, 2 * DAY_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status(), 'A day from the save, not from the first change.' );
	}

	/**
	 * @testdox The kept note on an unpaid order whose record shows a payment leaves its last-change date as it was.
	 */
	public function test_unpaid_order_kept_note_does_not_move_its_last_change(): void {
		$order = $this->create_unpaid_order( 'succeeded' );
		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$before              = $this->reload( $order )->get_date_modified()->getTimestamp();
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertSame( 1, $this->count_notes( $order, self::UNPAID_KEPT_NOTE ) );
		$this->assertSame( $before, $order->get_date_modified()->getTimestamp() );
	}

	/**
	 * @testdox Past its window, core's hold-stock job never cancels an unpaid order that, while its unreadable payment is read, $_dataName, and writes no note.
	 *
	 * @dataProvider unpaid_order_changes_during_an_unreadable_read
	 *
	 * @param string $case_name Case name.
	 * @param string $change    `paid` for a payment that moves the order to Processing, `lock` for a claimed lock.
	 * @param string $expected  Order status at the end.
	 */
	public function test_unpaid_order_past_its_window_is_read_again_before_its_cancel( string $case_name, string $change, string $expected ): void {
		unset( $case_name );
		$order = $this->create_unpaid_order( 'requires_action' );
		$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		$order_id  = $order->get_id();
		$transport = $this->create_transport_calling(
			function () use ( $order_id, $change ) {
				$stored = $this->reload( wc_get_order( $order_id ) );
				if ( 'lock' === $change ) {
					wc_get_container()->get( OrderPaymentLock::class )->claim( $stored, new WooPaymentsPersistenceVocabulary(), 'pi_other', 'payment operation' );
				} else {
					$stored->set_status( OrderStatus::PROCESSING );
					$stored->set_date_paid( time() );
					$stored->save();
				}
			},
			self::transport_timeout()
		);

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertSame( $expected, $order->get_status() );
		$this->assertSame( 0, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) + $this->count_notes( $order, self::UNPAID_KEPT_NOTE ) );
	}

	/**
	 * Changes another request makes to an unpaid order past its window while its unreadable payment is read.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function unpaid_order_changes_during_an_unreadable_read(): array {
		return array(
			'a payment moves it to Processing'  => array( 'a payment moves it to Processing', 'paid', OrderStatus::PROCESSING ),
			'another operation claims its lock' => array( 'another operation claims its lock', 'lock', OrderStatus::PENDING ),
		);
	}

	/**
	 * @testdox An unpaid order deleted while its payment is read is kept with a log line, and core's job goes on to the next order: $_dataName.
	 *
	 * @testWith ["with no money", false]
	 *           ["past its window, unreadable", true]
	 *
	 * @param string $case_name Case name.
	 * @param bool   $past      Whether the order is past its window and its read fails.
	 */
	public function test_unpaid_order_deleted_during_its_read_is_kept_and_the_job_goes_on( string $case_name, bool $past ): void {
		unset( $case_name );
		$order = $this->create_unpaid_order( 'requires_action' );
		if ( $past ) {
			$this->age_unpaid_order( $order, null, self::UNPAID_WINDOW + MINUTE_IN_SECONDS );
		}
		$next = $this->create_unpaid_order( '', '' );
		$next->set_payment_method( 'cod' );
		$next->save();
		$this->age_unpaid_order( $next );
		$order_id                   = $order->get_id();
		$transport                  = $this->create_transport_calling(
			static function () use ( $order_id ) {
				wc_get_order( $order_id )->delete( true );
			},
			$past ? self::transport_timeout() : self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) )
		);
		$settings                   = get_option( 'woocommerce_woocommerce_payments_settings', array() );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['enable_logging'] = 'yes';
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );
		$logger = RecordingWcLogger::install();

		$this->run_unpaid_order_job( $transport );

		$this->assertFalse( wc_get_order( $order_id ) );
		$this->assertCount( 1, array_filter( $logger->get_errors(), static fn( array $line ): bool => false !== strpos( $line[1], 'could not be read again before its cancel' ) ) );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $next )->get_status() );
	}

	/**
	 * @testdox A payment found on an unpaid order that cannot be applied is logged even with WooPayments logging off, and the order is kept.
	 */
	public function test_unpaid_order_payment_that_cannot_be_applied_is_logged_with_logging_off(): void {
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$this->assertFalse( wc_get_container()->get( WooPaymentsLogger::class )->can_log() );
		$order        = $this->create_unpaid_order( 'requires_action' );
		$confirmation = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation->expects( $this->once() )->method( 'apply_attached_payment_intent' )->willThrowException( new RuntimeException( 'Lifecycle failed.' ) );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'succeeded' ) ) );
		$logger    = RecordingWcLogger::install();

		$this->run_unpaid_order_job( $transport, null, array( WooPaymentsIntentConfirmationService::class => $confirmation ) );

		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->assertCount( 1, array_filter( $logger->get_errors(), static fn( array $line ): bool => false !== strpos( $line[1], 'could not be applied' ) ) );
	}

	/**
	 * @testdox The unpaid-order check returns another callback's 'yes' as it came for an order whose payment holds no money.
	 */
	public function test_unpaid_order_check_returns_the_incoming_answer_after_a_read_with_no_money(): void {
		$order     = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );
		$this->sut = $this->create_controller( null, null, $this->create_unpaid_order_platform( $transport ) );

		try {
			$this->assertSame( 'yes', $this->sut->handle_woocommerce_cancel_unpaid_order( 'yes', $order ) );
		} finally {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}
		$this->assertCount( 1, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox Three store-wide refusals in a row, $code, stop core's hold-stock job's reads for the run, and every order is kept.
	 *
	 * @testWith [401]
	 *           [403]
	 *           [429]
	 *           [500]
	 *           [502]
	 *
	 * @param int $code HTTP status of every answer.
	 */
	public function test_unpaid_order_reads_stop_after_three_store_wide_refusals( int $code ): void {
		$orders              = $this->create_unpaid_orders( 5 );
		$transport           = $this->create_transport();
		$transport->response = self::platform_error( $code );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 3, $this->request_paths( $transport ) );
		foreach ( $orders as $order ) {
			$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		}
	}

	/**
	 * @testdox An answer about one intent, $code, never stops core's hold-stock job's reads: ten orders, ten reads.
	 *
	 * @testWith [404]
	 *           [400]
	 *
	 * @param int $code HTTP status of every answer.
	 */
	public function test_unpaid_order_reads_never_stop_on_an_answer_about_one_intent( int $code ): void {
		$this->create_unpaid_orders( 10 );
		$transport           = $this->create_transport();
		$transport->response = self::platform_error( $code );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 10, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox Any answer the check can use, or an answer about one intent, starts the refusals count again: $_dataName.
	 *
	 * @testWith ["two 429s, a usable answer, two 429s", [429, 429, 200, 429, 429]]
	 *           ["two 500s, a not-found, two 500s", [500, 500, 404, 500, 500]]
	 *
	 * @param string $case_name Case name.
	 * @param int[]  $codes     HTTP status of each answer, in core's order; 200 for a usable answer with no money.
	 */
	public function test_unpaid_order_refusals_count_only_in_a_row( string $case_name, array $codes ): void {
		unset( $case_name );
		$orders    = $this->create_unpaid_orders( count( $codes ) );
		$responses = array();
		foreach ( $codes as $index => $code ) {
			$responses[] = 200 === $code ? self::http_json( 200, self::unpaid_intent( $orders[ $index ], 'requires_action' ) ) : self::platform_error( $code );
		}
		$transport = $this->create_transport( ...$responses );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( count( $codes ), $this->request_paths( $transport ) );
	}

	/**
	 * @testdox An answer the store could not read starts the refusals count again: two 500s, an unreadable answer and two 500s read all five orders.
	 *
	 * The platform answered, so the store is not being refused; only the checkout's duplicate check counts such an answer
	 * as leaving the payment unknown.
	 */
	public function test_unpaid_order_refusals_count_starts_again_after_an_unreadable_answer(): void {
		$this->create_unpaid_orders( 5 );
		$unreadable = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => 'not json',
		);
		$transport  = $this->create_transport( self::platform_error( 500 ), self::platform_error( 500 ), $unreadable, self::platform_error( 500 ), self::platform_error( 500 ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 5, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox A $_dataName stops core's hold-stock job's reads for the run at once.
	 *
	 * @dataProvider unpaid_order_reads_stopping_at_once
	 *
	 * @param string $case_name Case name.
	 * @param mixed  $response  Transport response to every read.
	 */
	public function test_unpaid_order_reads_stop_at_once_on_a_transport_error_or_a_gateway_timeout( string $case_name, $response ): void {
		unset( $case_name );
		$this->create_unpaid_orders( 3 );
		$transport           = $this->create_transport();
		$transport->response = $response;

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 1, $this->request_paths( $transport ) );
	}

	/**
	 * Answers that stop the unpaid-order reads at once.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function unpaid_order_reads_stopping_at_once(): array {
		return array(
			'transport error' => array( 'transport error', self::transport_timeout() ),
			'gateway timeout' => array( 'gateway timeout', self::platform_error( 504 ) ),
		);
	}

	/**
	 * @testdox One order whose read always answers a server error, first in core's order, stops nothing: the orders behind it are read and decided.
	 */
	public function test_unpaid_order_whose_read_always_fails_does_not_stop_the_run(): void {
		$orders    = $this->create_unpaid_orders( 5 );
		$responses = array( self::platform_error( 500 ) );
		foreach ( array_slice( $orders, 1 ) as $order ) {
			$responses[] = self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) );
		}
		$transport = $this->create_transport( ...$responses );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 5, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $orders[0] )->get_status() );
		foreach ( array_slice( $orders, 1 ) as $order ) {
			$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
		}
	}

	/**
	 * @testdox A PHP failure in one unpaid order's read counts as a refusal and does not stop the reads at once.
	 */
	public function test_unpaid_order_read_php_failure_does_not_stop_the_run_at_once(): void {
		$first     = $this->create_unpaid_order( 'requires_action' );
		$second    = $this->create_unpaid_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $first, 'requires_action' ) ), self::http_json( 200, self::unpaid_intent( $second, 'requires_action' ) ) );
		$thrown    = false;
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) use ( &$thrown ) {
				if ( ! $thrown ) {
					$thrown = true;
					throw new RuntimeException( 'A response filter failed.' );
				}

				return $response;
			}
		);

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $this->reload( $first )->get_status() );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $second )->get_status() );
	}

	/**
	 * @testdox Each run of core's hold-stock job starts its unpaid-order reads afresh, on a controller that served an earlier run.
	 */
	public function test_unpaid_order_reads_start_afresh_at_each_run(): void {
		$this->create_unpaid_orders( 4 );
		$transport           = $this->create_transport();
		$transport->response = self::platform_error( 429 );
		$manage_stock        = get_option( 'woocommerce_manage_stock' );
		$hold_minutes        = get_option( 'woocommerce_hold_stock_minutes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', '60' );
		$this->sut = $this->create_controller( null, null, $this->create_unpaid_order_platform( $transport ) );
		$this->sut->register();
		$this->assertSame( 10, has_action( 'woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders' ), 'Core\'s job runs after the reset.' );

		try {
			do_action( 'woocommerce_cancel_unpaid_orders' );
			$this->assertCount( 3, $this->request_paths( $transport ), 'The first run stops after three refusals.' );
			do_action( 'woocommerce_cancel_unpaid_orders' );
		} finally {
			remove_action( 'woocommerce_cancel_unpaid_orders', array( $this->sut, 'handle_woocommerce_cancel_unpaid_orders' ), 9 );
			remove_filter( 'woocommerce_cancel_unpaid_order', array( $this->sut, 'handle_woocommerce_cancel_unpaid_order' ), PHP_INT_MAX );
			as_unschedule_all_actions( 'woocommerce_cancel_unpaid_orders' );
			update_option( 'woocommerce_manage_stock', $manage_stock );
			update_option( 'woocommerce_hold_stock_minutes', $hold_minutes );
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}

		$this->assertCount( 6, $this->request_paths( $transport ), 'The second run reads again.' );
	}

	/**
	 * @testdox A lookup that cannot check, refused with $code, never counts toward stopping the reads: four orders keeping only a charge key, four lookups.
	 *
	 * Recorded departure: the lookup does not tell a store-wide refusal from any other.
	 *
	 * @testWith [401]
	 *           [403]
	 *           [429]
	 *
	 * @param int $code HTTP status of every list answer.
	 */
	public function test_unpaid_order_lookup_that_cannot_check_never_counts( int $code ): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		}
		$transport           = $this->create_transport();
		$transport->response = self::platform_error( $code );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 4, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox A lookup's transport error stops core's hold-stock job's reads for the run at once.
	 */
	public function test_unpaid_order_lookup_transport_error_stops_the_run_at_once(): void {
		$this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$this->create_unpaid_order( 'requires_action' );
		$transport           = $this->create_transport();
		$transport->response = self::transport_timeout();

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 1, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox A lookup that answers starts the refusals count again, as a usable read does.
	 */
	public function test_unpaid_order_lookup_answer_starts_the_count_again(): void {
		$first  = $this->create_unpaid_order( 'requires_action' );
		$second = $this->create_unpaid_order( 'requires_action' );
		$keyed  = $this->create_key_only_order( time() - 3 * HOUR_IN_SECONDS );
		$third  = $this->create_unpaid_order( 'requires_action' );
		$fourth = $this->create_unpaid_order( 'requires_action' );
		unset( $first, $second, $keyed, $third, $fourth );
		$transport = $this->create_transport( self::platform_error( 429 ), self::platform_error( 429 ), self::http_json( 200, self::intent_list() ), self::platform_error( 429 ), self::platform_error( 429 ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 5, $this->request_paths( $transport ) );
	}

	/**
	 * @testdox Money found on an unpaid order is not applied when, while it is read, the order $_dataName.
	 *
	 * @testWith ["stores a newer attempt's intent", "intent"]
	 *           ["moves to another payment method", "method"]
	 *
	 * @param string $case_name Case name.
	 * @param string $change    `intent` for a newer intent stored, `method` for another payment method.
	 */
	public function test_unpaid_order_money_is_not_applied_over_a_change_during_the_read( string $case_name, string $change ): void {
		unset( $case_name );
		$order     = $this->create_unpaid_order( 'requires_capture' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_capture' ) ) );
		$order_id  = $order->get_id();
		$changed   = false;
		add_filter(
			'wcpay_api_request_response',
			function ( $response ) use ( &$changed, $order_id, $change ) {
				if ( ! $changed ) {
					$changed = true;
					$stored  = $this->reload( wc_get_order( $order_id ) );
					if ( 'intent' === $change ) {
						$stored->update_meta_data( '_intent_id', 'pi_new' );
					} else {
						$stored->set_payment_method( 'cod' );
					}
					$stored->save();
				}

				return $response;
			}
		);

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertTrue( $changed );
		$this->assertSame( OrderStatus::PENDING, $order->get_status(), 'Nothing applied, and the order kept.' );
		$this->assertSame( 'intent' === $change ? 'pi_new' : 'pi_unpaid', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox Core's hold-stock job looks up the kept charge key of an unpaid order that also stores an earlier attempt's $_dataName intent, and a payment of its own the lookup finds pays the order with no cancel.
	 *
	 * @testWith ["awaiting action", "requires_action", 2]
	 *           ["canceled", "canceled", 1]
	 *
	 * @param string $case_name     Case name.
	 * @param string $stored_status Status of the earlier attempt's stored intent.
	 * @param int    $requests      Requests expected: the stored intent's read, when not canceled, and the lookup.
	 */
	public function test_unpaid_order_with_a_kept_key_beside_a_stored_intent_is_settled_from_the_lookup( string $case_name, string $stored_status, int $requests ): void {
		unset( $case_name );
		$order       = $this->create_kept_key_order( $stored_status );
		$found       = self::key_only_intent( $order, 'succeeded' );
		$found['id'] = 'pi_retry';
		$responses   = 'canceled' === $stored_status ? array() : array( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ) );
		$responses[] = self::http_json( 200, self::intent_list( $found ) );
		$transport   = $this->create_transport( ...$responses );
		$processing  = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$order = $this->reload( $order );
		$this->assertCount( $requests, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'Unpaid order cancelled' ) );
	}

	/**
	 * @testdox Core's hold-stock job cancels, as before, an unpaid order storing an intent with no money beside a kept charge key when the lookup finds nothing.
	 */
	public function test_unpaid_order_with_a_kept_key_and_no_money_found_is_cancelled(): void {
		$order     = $this->create_kept_key_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ), self::http_json( 200, self::intent_list() ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::CANCELLED, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job keeps, with no note, an unpaid order storing an intent with no money beside a kept charge key while the lookup cannot tell.
	 */
	public function test_unpaid_order_with_a_kept_key_the_lookup_cannot_tell_about_is_kept(): void {
		$order     = $this->create_kept_key_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'requires_action' ) ), self::http_json( 200, array( 'has_more' => true ) + self::intent_list() ) );

		$this->run_unpaid_order_job( $transport );

		$order = $this->reload( $order );
		$this->assertCount( 2, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( 0, $this->count_notes( $order, self::UNPAID_CANCEL_NOTE ) );
	}

	/**
	 * @testdox Core's hold-stock job applies, with no lookup, the money an unpaid order's stored intent holds beside a kept charge key.
	 */
	public function test_unpaid_order_with_a_kept_key_and_money_in_its_stored_intent_is_not_looked_up(): void {
		$order     = $this->create_kept_key_order( 'requires_action' );
		$transport = $this->create_transport( self::http_json( 200, self::unpaid_intent( $order, 'succeeded' ) ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertCount( 1, $this->request_paths( $transport ) );
		$this->assertSame( OrderStatus::PROCESSING, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox Core's hold-stock job settles an unpaid order whose kept charge key a later attempt sent after the key's first hour: the lookup's window ends an hour after the order's last change, and the payment it finds is applied, not cancelled.
	 *
	 * Attempt 1 keeps the key and dies before sending it. The shopper retries 80 minutes later: the same key creates the
	 * intent, and that request dies after the platform's answer.
	 */
	public function test_unpaid_order_lookup_covers_an_attempt_after_the_key_was_kept(): void {
		$kept_at = time() - 4 * HOUR_IN_SECONDS;
		$order   = $this->create_key_only_order( $kept_at );
		$this->age_unpaid_order( $order, null, 4 * HOUR_IN_SECONDS - 80 * MINUTE_IN_SECONDS );
		$retried    = $this->reload( $order )->get_date_modified()->getTimestamp();
		$intent     = self::key_only_intent( $order, 'succeeded' );
		$transport  = $this->create_transport_answering(
			static function ( string $path ) use ( $intent, $retried ): array {
				parse_str( (string) wp_parse_url( $path, PHP_URL_QUERY ), $query );

				return self::http_json( 200, (int) ( $query['created']['lte'] ?? 0 ) >= $retried ? self::intent_list( $intent ) : self::intent_list() );
			}
		);
		$processing = $this->createMock( PaymentProcessingService::class );
		$processing->expects( $this->never() )->method( 'cancel' );

		$this->run_unpaid_order_job( $transport, $processing );

		$this->assertSame( array( $kept_at - HOUR_IN_SECONDS, $retried + HOUR_IN_SECONDS ), $this->listed_window( $transport ) );
		$this->assertSame( OrderStatus::PROCESSING, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox The lookup's window for an unpaid order kept unchecked is the same on two runs: from an hour before the key was kept to an hour after the order's last change.
	 */
	public function test_unpaid_order_lookup_window_does_not_move_between_runs(): void {
		$kept_at             = time() - 5 * HOUR_IN_SECONDS;
		$order               = $this->create_key_only_order( $kept_at );
		$modified            = (int) $this->reload( $order )->get_date_modified()->getTimestamp();
		$transport           = $this->create_transport();
		$transport->response = self::http_json( 200, array( 'has_more' => true ) + self::intent_list() );
		$windows             = array();

		for ( $run = 0; $run < 2; $run++ ) {
			$this->run_unpaid_order_job( $transport );
			$windows[]           = $this->listed_window( $transport );
			$transport->requests = array();
		}

		$this->assertSame( OrderStatus::PENDING, $this->reload( $order )->get_status() );
		$this->assertSame( array( $kept_at - HOUR_IN_SECONDS, $modified + HOUR_IN_SECONDS ), $windows[0] );
		$this->assertSame( $windows[0], $windows[1] );
	}

	/**
	 * @testdox The lookup's window ends an hour after the key was kept when the key was kept after the order's last change.
	 */
	public function test_unpaid_order_lookup_window_ends_after_a_key_kept_later_than_the_last_change(): void {
		$kept_at = time() - 2 * HOUR_IN_SECONDS;
		$order   = $this->create_key_only_order( $kept_at );
		$this->age_unpaid_order( $order, null, 4 * HOUR_IN_SECONDS );
		$transport = $this->create_transport( self::http_json( 200, self::intent_list() ) );

		$this->run_unpaid_order_job( $transport );

		$this->assertSame( array( $kept_at - HOUR_IN_SECONDS, $kept_at + HOUR_IN_SECONDS ), $this->listed_window( $transport ) );
	}

	/**
	 * Create a Pending checkout order for a shipped product, paid through WooPayments, storing an intent, last changed three
	 * hours ago.
	 *
	 * @param string $stored_status Intent status the order stores.
	 * @param string $intent_id     Intent the order stores in the order's test mode; empty for none.
	 * @return WC_Order
	 */
	private function create_unpaid_order( string $stored_status, string $intent_id = 'pi_unpaid' ): WC_Order {
		$order = wc_create_order();
		$order->add_product( \WC_Helper_Product::create_simple_product() );
		$order->set_created_via( 'checkout' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.99' );
		$order->set_status( OrderStatus::PENDING );
		if ( '' !== $intent_id ) {
			$order->update_meta_data( '_intent_id', $intent_id );
			$order->update_meta_data( '_intention_status', $stored_status );
			$order->update_meta_data( '_wcpay_mode', 'test' );
		}
		$order->save();
		$this->age_unpaid_order( $order );

		return $order;
	}

	/**
	 * Relate a subscription to an unpaid order, as WooCommerce Subscriptions' order detector reports it.
	 *
	 * @param WC_Order $order    Unpaid order.
	 * @param string   $relation `parent`, `resubscribe` or `switch`.
	 */
	private function relate_subscription_to_unpaid_order( WC_Order $order, string $relation ): void {
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ][ $relation ] = array( 999999 );
	}

	/**
	 * Date an order's last change back, three hours by default, past core's hold time, and its creation when given.
	 *
	 * @param WC_Order $order        Order.
	 * @param int|null $created_ago  Seconds since the order's creation, or null to leave it.
	 * @param int      $modified_ago Seconds since the order's last change.
	 */
	private function age_unpaid_order( WC_Order $order, ?int $created_ago = null, int $modified_ago = 3 * HOUR_IN_SECONDS ): void {
		global $wpdb;
		$modified = gmdate( 'Y-m-d H:i:s', time() - $modified_ago );
		$created  = null === $created_ago ? null : gmdate( 'Y-m-d H:i:s', time() - $created_ago );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update(
				OrdersTableDataStore::get_orders_table_name(),
				array_filter(
					array(
						'date_updated_gmt' => $modified,
						'date_created_gmt' => $created,
					)
				),
				array( 'id' => $order->get_id() )
			);
		} else {
			$dates = array(
				'post_modified'     => get_date_from_gmt( $modified ),
				'post_modified_gmt' => $modified,
			);
			if ( null !== $created ) {
				$dates['post_date']     = get_date_from_gmt( $created );
				$dates['post_date_gmt'] = $created;
			}
			$wpdb->update( $wpdb->posts, $dates, array( 'ID' => $order->get_id() ) );
		}
		$this->reload( $order );
	}

	/**
	 * Read an order again past the order caches.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order
	 */
	private function reload( WC_Order $order ): WC_Order {
		clean_post_cache( $order->get_id() );
		wc_get_container()->get( OrderCache::class )->remove( $order->get_id() );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );

		return $reloaded;
	}

	/**
	 * Run core's hold-stock job once, as one request: stock management on, a 60-minute hold by default, a new controller
	 * hooked, and the platform client over the given transport, which every service the job resolves uses too.
	 *
	 * @param FakeHttpClient                $transport      Platform transport.
	 * @param PaymentProcessingService|null $processing     Processing service the Cancelled listener uses; a mock by default.
	 * @param array<string,object>          $services       Container services to replace for the run.
	 * @param string                        $hold           Hold-stock minutes.
	 * @param callable|null                 $after_register Called once the controller is hooked, to hook after it.
	 */
	private function run_unpaid_order_job( FakeHttpClient $transport, ?PaymentProcessingService $processing = null, array $services = array(), string $hold = '60', ?callable $after_register = null ): void {
		$manage_stock = get_option( 'woocommerce_manage_stock' );
		$hold_minutes = get_option( 'woocommerce_hold_stock_minutes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', $hold );
		$controller = $this->create_controller( $processing, null, $this->create_unpaid_order_platform( $transport ) );
		foreach ( $services as $class => $service ) {
			wc_get_container()->replace( $class, $service );
		}
		$controller->register();
		if ( null !== $after_register ) {
			$after_register();
		}

		try {
			wc_cancel_unpaid_orders();
		} finally {
			remove_filter( 'woocommerce_cancel_unpaid_order', array( $controller, 'handle_woocommerce_cancel_unpaid_order' ), PHP_INT_MAX );
			remove_action( 'woocommerce_cancel_unpaid_orders', array( $controller, 'handle_woocommerce_cancel_unpaid_orders' ), 9 );
			remove_action( 'woocommerce_order_status_cancelled', array( $controller, 'handle_woocommerce_order_status_cancelled' ) );
			as_unschedule_all_actions( 'woocommerce_cancel_unpaid_orders' );
			update_option( 'woocommerce_manage_stock', $manage_stock );
			update_option( 'woocommerce_hold_stock_minutes', $hold_minutes );
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}
	}

	/**
	 * Create the platform client over a fake transport and put it in the container, with a warm account cache so the
	 * transport sees only the requests the code under test makes.
	 *
	 * @param FakeHttpClient $transport Platform transport.
	 * @return Client
	 */
	private function create_unpaid_order_platform( FakeHttpClient $transport ): Client {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'        => 'acct_unpaid',
					'country'           => 'US',
					'is_live'           => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
					'fraud_services'    => array( 'stripe' => array() ),
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		$client = new Client();
		$client->init( $transport, wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( TransportLog::class ) );
		wc_get_container()->replace( Client::class, $client );
		wc_get_container()->reset_all_resolved();

		return $client;
	}

	/**
	 * An intent of the order, with one charge when its status holds money.
	 *
	 * @param WC_Order            $order  Order its metadata names.
	 * @param string              $status Intent status.
	 * @param int                 $amount Amount in cents.
	 * @param array<string,mixed> $charge Charge fields replacing the defaults.
	 * @return array<string,mixed>
	 */
	private static function unpaid_intent( WC_Order $order, string $status, int $amount = 1099, array $charge = array() ): array {
		$charges = in_array( $status, array( 'succeeded', 'requires_capture' ), true )
			? array(
				array_merge(
					array(
						'id'              => 'ch_unpaid',
						'object'          => 'charge',
						'payment_method'  => 'pm_unpaid',
						'status'          => 'succeeded',
						'captured'        => 'succeeded' === $status,
						'amount'          => $amount,
						'amount_refunded' => 0,
						'refunded'        => false,
						'disputed'        => false,
					),
					$charge
				),
			)
			: array();

		return array(
			'id'                => 'pi_unpaid',
			'object'            => 'payment_intent',
			'status'            => $status,
			'amount'            => $amount,
			'amount_capturable' => 'requires_capture' === $status ? $amount : 0,
			'currency'          => 'usd',
			'customer'          => 'cus_unpaid',
			'payment_method'    => 'pm_unpaid',
			'metadata'          => array( 'order_id' => (string) $order->get_id() ),
			'charges'           => array( 'data' => $charges ),
		);
	}

	/**
	 * Create a Pending checkout order that keeps a charge key and stores no intent, as a checkout request that died after
	 * the platform's answer leaves it, last changed three hours ago.
	 *
	 * @param int|null $kept_at Unix time the key was kept, or null for a key kept with no time.
	 * @param string   $mode    Mode the key was kept in.
	 * @return WC_Order
	 */
	private function create_key_only_order( ?int $kept_at, string $mode = WooPaymentsOrderMode::PRODUCTION ): WC_Order {
		$order = $this->create_unpaid_order( '', '' );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'charge_key_kept' );
		if ( null !== $kept_at ) {
			$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META, (string) $kept_at );
		}
		$order->update_meta_data( '_wcpay_mode', $mode );
		$order->save();
		$this->age_unpaid_order( $order );

		return $order;
	}

	/**
	 * Get the creation window of the one account list the transport received.
	 *
	 * @param FakeHttpClient $transport Platform transport.
	 * @return array<int,int> The window's start and end.
	 */
	private function listed_window( FakeHttpClient $transport ): array {
		$this->assertCount( 1, $transport->requests );
		parse_str( (string) wp_parse_url( (string) $transport->requests[0]['path'], PHP_URL_QUERY ), $query );

		return array( (int) ( $query['created']['gte'] ?? 0 ), (int) ( $query['created']['lte'] ?? 0 ) );
	}

	/**
	 * An intent of the order as the account list returns it, its metadata naming the order by ID and key.
	 *
	 * @param WC_Order $order  Order its metadata names.
	 * @param string   $status Intent status.
	 * @param int      $amount Amount in cents.
	 * @return array<string,mixed>
	 */
	private static function key_only_intent( WC_Order $order, string $status, int $amount = 1099 ): array {
		$intent                          = self::unpaid_intent( $order, $status, $amount );
		$intent['metadata']['order_key'] = $order->get_order_key();

		return $intent;
	}

	/**
	 * A PaymentIntents list page holding the given intents and no more.
	 *
	 * @param array<string,mixed> ...$intents Intents.
	 * @return array<string,mixed>
	 */
	private static function intent_list( array ...$intents ): array {
		return array(
			'object'   => 'list',
			'data'     => $intents,
			'has_more' => false,
		);
	}

	/**
	 * Create Pending checkout orders storing an awaiting-action intent, in the order core's job takes them.
	 *
	 * @param int $count How many.
	 * @return WC_Order[]
	 */
	private function create_unpaid_orders( int $count ): array {
		$orders = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$orders[] = $this->create_unpaid_order( 'requires_action' );
		}

		return $orders;
	}

	/**
	 * Create a Pending checkout order storing an earlier attempt's intent beside the charge key a later attempt kept, in the
	 * store's mode, as a retry that died after the platform's answer leaves it.
	 *
	 * @param string $stored_status Status of the stored intent.
	 * @return WC_Order
	 */
	private function create_kept_key_order( string $stored_status ): WC_Order {
		$order = $this->create_unpaid_order( $stored_status );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'charge_key_kept' );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META, (string) ( time() - 3 * HOUR_IN_SECONDS ) );
		$order->update_meta_data( '_wcpay_mode', WooPaymentsOrderMode::PRODUCTION );
		$order->save();
		$this->age_unpaid_order( $order );

		return $order;
	}

	/**
	 * A transport timeout, as the HTTP API reports it.
	 *
	 * @return \WP_Error
	 */
	private static function transport_timeout(): \WP_Error {
		return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}

	/**
	 * A platform error answer with the given HTTP status.
	 *
	 * @param int $code HTTP status.
	 * @return array<string,mixed>
	 */
	private static function platform_error( int $code ): array {
		return self::http_json(
			$code,
			array(
				'code'    => 'wcpay_error',
				'message' => 'Refused.',
				'data'    => array( 'status' => $code ),
			)
		);
	}

	/**
	 * Create a platform transport that runs a callback, as another request would meanwhile, then answers every request
	 * with the given response.
	 *
	 * @param callable $meanwhile Runs once, at the first request.
	 * @param mixed    $response  Transport response.
	 * @return FakeHttpClient
	 */
	private function create_transport_calling( callable $meanwhile, $response ): FakeHttpClient {
		$transport           = new class( $meanwhile ) extends FakeHttpClient {
			/**
			 * Runs once, at the first request.
			 *
			 * @var callable|null
			 */
			private $meanwhile;

			/**
			 * Keep the callback.
			 *
			 * @param callable $meanwhile Runs once, at the first request.
			 */
			public function __construct( callable $meanwhile ) {
				$this->meanwhile = $meanwhile;
			}

			/**
			 * Run the callback once, then answer as the fake transport does.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           Path.
			 * @param array       $headers        Headers.
			 * @param string|null $body           Body.
			 * @param int         $timeout        Timeout.
			 * @param bool        $use_user_token Whether the user token is used.
			 * @param bool        $blocking       Whether the request blocks.
			 * @return mixed
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				if ( null !== $this->meanwhile ) {
					$meanwhile       = $this->meanwhile;
					$this->meanwhile = null;
					$meanwhile();
				}

				return parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
			}
		};
		$transport->response = $response;

		return $transport;
	}

	/**
	 * Create a platform transport that answers each request from its path.
	 *
	 * @param callable $answer Gets the request path and returns the transport response.
	 * @return FakeHttpClient
	 */
	private function create_transport_answering( callable $answer ): FakeHttpClient {
		return new class( $answer ) extends FakeHttpClient {
			/**
			 * Answers a request from its path.
			 *
			 * @var callable
			 */
			private $answer;

			/**
			 * Keep the callback.
			 *
			 * @param callable $answer Answers a request from its path.
			 */
			public function __construct( callable $answer ) {
				$this->answer = $answer;
			}

			/**
			 * Record the request as the fake transport does, then answer it from its path.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           Path.
			 * @param array       $headers        Headers.
			 * @param string|null $body           Body.
			 * @param int         $timeout        Timeout.
			 * @param bool        $use_user_token Whether the user token is used.
			 * @param bool        $blocking       Whether the request blocks.
			 * @return mixed
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );

				return ( $this->answer )( $path );
			}
		};
	}
}
