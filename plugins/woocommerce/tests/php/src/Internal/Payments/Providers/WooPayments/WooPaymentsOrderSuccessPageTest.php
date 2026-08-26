<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderSuccessPage;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderSuccessPage class.
 */
class WooPaymentsOrderSuccessPageTest extends WC_Unit_Test_Case {

	/**
	 * Registered order-success pages to clean up.
	 *
	 * @var WooPaymentsOrderSuccessPage[]
	 */
	private array $registered_pages = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wp']->query_vars['order-received'], $_GET['key'] );
		foreach ( $this->registered_pages as $page ) {
			remove_action( 'woocommerce_thankyou', array( $page, 'record_order_success_page_view' ) );
			remove_action( 'woocommerce_before_thankyou', array( $page, 'register_payment_method_title_override' ) );
			remove_action( 'woocommerce_before_thankyou', array( $page, 'maybe_render_multibanco_payment_instructions' ) );
			remove_action( 'woocommerce_order_details_before_order_table', array( $page, 'unregister_payment_method_title_override' ) );
			remove_action( 'woocommerce_order_details_before_order_table', array( $page, 'maybe_render_multibanco_payment_instructions' ) );
			remove_action( 'wp_enqueue_scripts', array( $page, 'enqueue_assets' ) );
		}
		wp_dequeue_script( 'wc-woopayments-order-success' );
		wp_deregister_script( 'wc-woopayments-order-success' );
		wp_dequeue_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-appearance' );
		wp_dequeue_style( 'wc-woopayments-order-success' );
		wp_deregister_style( 'wc-woopayments-order-success' );

		parent::tearDown();
	}

	/**
	 * @testdox Should track the main WooPayments thank-you page once and ignore ineligible orders.
	 */
	public function test_tracks_only_main_woopayments_order_success_page_once(): void {
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'record_user_event' ) )
			->getMock();
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( $event_name, $properties );
				return true;
			}
		);

		$page = $this->create_page( true, $tracker );
		$page->register();
		$page->register();
		$this->registered_pages[] = $page;

		$main_order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $main_order );
		$main_order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$main_order->save();

		$split_order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $split_order );
		$split_order->set_payment_method( OrderPaymentStore::GATEWAY_ID_PREFIX . 'klarna' );
		$split_order->save();

		$this->assertSame( 10, has_action( 'woocommerce_thankyou', array( $page, 'record_order_success_page_view' ) ) );
		$page->record_order_success_page_view( $main_order->get_id() );
		$page->record_order_success_page_view( $split_order->get_id() );
		$page->record_order_success_page_view( 0 );

		$this->assertSame(
			array(
				array(
					'order_success_page_view',
					array( 'record_event_data' => array( 'track_on_all_stores' => true ) ),
				),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should register Multibanco order-success hooks only when native owns runtime.
	 */
	public function test_registers_multibanco_order_success_hooks_only_when_native_owns_runtime(): void {
		$plugin_owned = $this->create_page( false );
		$native_owned = $this->create_page( true );

		$plugin_owned->register();

		$this->assertFalse( has_action( 'woocommerce_before_thankyou', array( $plugin_owned, 'maybe_render_multibanco_payment_instructions' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_details_before_order_table', array( $plugin_owned, 'maybe_render_multibanco_payment_instructions' ) ) );

		$native_owned->register();
		$this->registered_pages[] = $native_owned;

		$this->assertSame( 10, has_action( 'woocommerce_before_thankyou', array( $native_owned, 'maybe_render_multibanco_payment_instructions' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_before_thankyou', array( $native_owned, 'register_payment_method_title_override' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_details_before_order_table', array( $native_owned, 'maybe_render_multibanco_payment_instructions' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_details_before_order_table', array( $native_owned, 'unregister_payment_method_title_override' ) ) );
		$this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $native_owned, 'enqueue_assets' ) ) );
	}

	/**
	 * @testdox Should enqueue native order-success assets on the order-received page.
	 */
	public function test_enqueues_order_success_assets_on_order_received_page(): void {
		$page = $this->create_page( true );
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		try {
			$page->enqueue_assets();
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		}

		$this->assertTrue( wp_script_is( 'wc-woopayments-order-success', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-order-success', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-woopayments-appearance', 'registered' ) );
		$this->assertContains( 'wc-woopayments-appearance', wp_scripts()->registered['wc-woopayments-order-success']->deps );
	}

	/**
	 * @testdox Should render Multibanco voucher instructions for on-hold Multibanco orders.
	 */
	public function test_renders_multibanco_voucher_instructions_for_on_hold_multibanco_orders(): void {
		$page  = $this->create_page( true );
		$order = $this->create_multibanco_order();

		$output = $this->render_multibanco_instructions( $page, $order );

		$this->assertStringContainsString( 'wc-payment-gateway-multibanco-instructions-container', $output );
		$this->assertStringContainsString( '/assets/images/payment-methods/multibanco-instructions.svg', $output );
		$this->assertStringContainsString( 'Your order is on hold until payment is received. Please follow the payment instructions by the expiry date.', $output );
		$this->assertStringContainsString( 'Payment instructions', $output );
		$this->assertStringContainsString( 'Entity', $output );
		$this->assertStringContainsString( '12345', $output );
		$this->assertStringContainsString( 'Reference', $output );
		$this->assertStringContainsString( '123 456 789', $output );
		$this->assertStringContainsString( 'Amount', $output );
		$this->assertStringContainsString( '123.45', $output );
		$this->assertStringContainsString( 'https://pay.stripe.com/multibanco/voucher', $output );
		$this->assertStringContainsString( 'aria-label="Copy entity: 12345"', $output );
		$this->assertStringContainsString( 'aria-label="Copy reference: 123 456 789"', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
	}

	/**
	 * @testdox Should render Multibanco instructions when the hook passes an order object.
	 */
	public function test_renders_multibanco_voucher_instructions_when_hook_passes_order_object(): void {
		$page  = $this->create_page( true );
		$order = $this->create_multibanco_order();

		$output = $this->render_multibanco_instructions_from_hook_payload( $page, $order );

		$this->assertStringContainsString(
			'wc-payment-gateway-multibanco-instructions-container',
			$output,
			'Order details hooks pass a WC_Order object and should not fatal.'
		);
	}

	/**
	 * @testdox Should not render Multibanco instructions for non-Multibanco orders.
	 */
	public function test_does_not_render_multibanco_instructions_for_non_multibanco_orders(): void {
		$page  = $this->create_page( true );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->save();

		$output = $this->render_multibanco_instructions( $page, $order );

		$this->assertSame( '', $output );
	}

	/**
	 * @testdox Should render the registry-backed LPM title on the order-received page.
	 */
	public function test_filters_lpm_payment_method_title_on_order_received_page(): void {
		$page  = $this->create_page( true );
		$order = $this->create_multibanco_order();
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		try {
			$title = $page->filter_payment_method_title( 'Multibanco', $order );
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		}

		$this->assertStringContainsString( 'wc-payment-lpm-logo--multibanco', $title );
		$this->assertStringContainsString( '/assets/images/payment-methods/multibanco-logo.svg', $title );
		$this->assertStringContainsString( '/assets/images/payment-methods/multibanco-logo-dark.svg', $title );
	}

	/**
	 * @testdox Should render express-wallet titles without applying LPM logo filters.
	 */
	public function test_filters_express_payment_method_title_without_lpm_logo_filters(): void {
		$page  = $this->create_page( true );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->update_meta_data( '_wcpay_express_checkout_payment_method', 'apple_pay' );
		$order->update_meta_data( 'last4', '4242' );
		$order->save();

		$lpm_filter_calls = 0;
		$lpm_filter       = static function ( $url ) use ( &$lpm_filter_calls ) {
			++$lpm_filter_calls;
			return $url;
		};
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		add_filter( 'wc_payments_thank_you_page_bnpl_payment_method_logo_url', $lpm_filter );
		add_filter( 'wc_payments_thank_you_page_lpm_payment_method_logo_url', $lpm_filter );

		try {
			$title = $page->filter_payment_method_title( 'Payment Request', $order );
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
			remove_filter( 'wc_payments_thank_you_page_bnpl_payment_method_logo_url', $lpm_filter );
			remove_filter( 'wc_payments_thank_you_page_lpm_payment_method_logo_url', $lpm_filter );
		}

		$this->assertSame( 0, $lpm_filter_calls );
		$this->assertStringContainsString( 'wc-payment-card-logo', $title );
		$this->assertStringContainsString( '4242', $title );
	}

	/**
	 * @testdox Should render card brand and last four on the order-received page.
	 */
	public function test_filters_card_payment_method_title_on_order_received_page(): void {
		$page  = $this->create_page( true );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->update_meta_data( '_card_brand', 'visa' );
		$order->update_meta_data( 'last4', '4242' );
		$order->save();
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		try {
			$title = $page->filter_payment_method_title( 'Credit / Debit Cards', $order );
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		}

		$this->assertStringContainsString( '/assets/images/payment-methods-cards/visa.svg', $title );
		$this->assertStringContainsString( '4242', $title );
	}

	/**
	 * @testdox Should preserve the stored title away from the order-received page.
	 */
	public function test_preserves_payment_method_title_away_from_order_received_page(): void {
		$page  = $this->create_page( true );
		$order = $this->create_multibanco_order();

		$this->assertSame( 'Stored title', $page->filter_payment_method_title( 'Stored title', $order ) );
	}

	/**
	 * @testdox Should tell the shopper on the thank-you page when a duplicate-order payment was prevented.
	 */
	public function test_thankyou_text_gains_notice_for_prevented_duplicate_order(): void {
		$page = $this->create_page( true );

		$_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_ORDER_PAID ] = 'yes';
		try {
			$text = $page->add_notice_previous_paid_order( 'Thank you.' );
		} finally {
			unset( $_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_ORDER_PAID ] );
		}

		$this->assertStringStartsWith( 'Thank you.', $text );
		$this->assertStringContainsString( 'woocommerce-info', $text );
		$this->assertStringContainsString( 'duplicate order', $text );
	}

	/**
	 * @testdox Should tell the shopper on the thank-you page when a second payment for the same order was prevented.
	 */
	public function test_thankyou_text_gains_notice_for_prevented_second_payment(): void {
		$page = $this->create_page( true );

		$_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_SUCCESSFUL_INTENT ] = 'yes';
		try {
			$text = $page->add_notice_previous_successful_intent( 'Thank you.' );
		} finally {
			unset( $_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_SUCCESSFUL_INTENT ] );
		}

		$this->assertStringStartsWith( 'Thank you.', $text );
		$this->assertStringContainsString( 'woocommerce-info', $text );
		$this->assertStringContainsString( 'multiple payments for the same order', $text );
	}

	/**
	 * @testdox Should leave the thank-you text alone without the duplicate-prevention flags.
	 */
	public function test_thankyou_text_unchanged_without_prevention_flags(): void {
		$page = $this->create_page( true );

		$this->assertSame( 'Thank you.', $page->add_notice_previous_paid_order( 'Thank you.' ) );
		$this->assertSame( 'Thank you.', $page->add_notice_previous_successful_intent( 'Thank you.' ) );
	}

	/**
	 * @testdox Should hook both duplicate-prevention notices into the order-received text when native owns the runtime.
	 */
	public function test_register_hooks_duplicate_prevention_notices(): void {
		$page = $this->create_page( true );

		$page->register();

		try {
			$this->assertSame( 11, has_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'add_notice_previous_paid_order' ) ) );
			$this->assertSame( 11, has_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'add_notice_previous_successful_intent' ) ) );
		} finally {
			remove_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'add_notice_previous_paid_order' ), 11 );
			remove_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'add_notice_previous_successful_intent' ), 11 );
		}
	}

	/**
	 * @testdox Should hook the failed-order copy replacement into the order-received text when native owns the runtime.
	 */
	public function test_register_hooks_failed_order_copy_replacement(): void {
		$page = $this->create_page( true );
		$page->register();

		try {
			$this->assertSame( 11, has_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'replace_order_received_text_for_failed_orders' ) ) );
			$this->assertSame( 10, has_action( 'wp_footer', array( $page, 'output_footer_scripts' ) ) );
		} finally {
			remove_filter( 'woocommerce_thankyou_order_received_text', array( $page, 'replace_order_received_text_for_failed_orders' ), 11 );
			remove_action( 'wp_footer', array( $page, 'output_footer_scripts' ) );
		}

		$plugin_owned = $this->create_page( false );
		$plugin_owned->register();
		$this->assertFalse( has_filter( 'woocommerce_thankyou_order_received_text', array( $plugin_owned, 'replace_order_received_text_for_failed_orders' ) ) );
	}

	/**
	 * @testdox Should replace the order-received copy for a failed WooPayments order and hide the block status description.
	 */
	public function test_replaces_order_received_text_for_failed_orders(): void {
		$page  = $this->create_page( true );
		$order = $this->create_thankyou_order( 'failed', OrderPaymentStore::GATEWAY_ID );

		$text = $page->replace_order_received_text_for_failed_orders( 'Thank you.' );

		$this->assertStringContainsString( 'Unfortunately, your order has failed.', $text );
		$this->assertStringContainsString( 'href="' . esc_url( wc_get_checkout_url() ) . '"', $text );
		$this->assertStringContainsString( 'try checking out again', $text );

		ob_start();
		$page->output_footer_scripts();
		$this->assertStringContainsString( '.wc-block-order-confirmation-status-description', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should re-check the live intent for a still-pending redirect-method order and report its failure.
	 */
	public function test_replaces_order_received_text_when_redirect_intent_failed(): void {
		$api_client = $this->create_api_client_mock(
			array(
				'status'             => 'requires_payment_method',
				'last_payment_error' => array( 'code' => 'payment_intent_authentication_failure' ),
			)
		);
		$page       = $this->create_page( true, null, $api_client );
		$this->create_thankyou_order( 'pending', OrderPaymentStore::GATEWAY_ID_PREFIX . 'wechat_pay', 'pi_wechat' );

		$this->assertStringContainsString( 'Unfortunately, your order has failed.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ) );
	}

	/**
	 * @testdox Should leave the order-received copy alone when the redirect intent is not failed.
	 */
	public function test_keeps_order_received_text_when_redirect_intent_is_pending(): void {
		$api_client = $this->create_api_client_mock( array( 'status' => 'processing' ) );
		$page       = $this->create_page( true, null, $api_client );
		$this->create_thankyou_order( 'pending', OrderPaymentStore::GATEWAY_ID_PREFIX . 'wechat_pay', 'pi_wechat' );

		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ) );

		ob_start();
		$page->output_footer_scripts();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should not fetch the intent for non-redirect, paid, foreign-gateway or wrong-key orders.
	 */
	public function test_keeps_order_received_text_outside_the_redirect_failure_case(): void {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->never() )->method( 'get_payment_intention' );
		$page = $this->create_page( true, null, $api_client );

		$this->create_thankyou_order( 'pending', OrderPaymentStore::GATEWAY_ID, 'pi_card' );
		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ), 'A pending card order is not a redirect return.' );

		$this->create_thankyou_order( 'processing', OrderPaymentStore::GATEWAY_ID_PREFIX . 'wechat_pay', 'pi_wechat' );
		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ), 'A paid order needs no re-check.' );

		$this->create_thankyou_order( 'failed', 'cod' );
		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ), 'Other gateways keep their own copy.' );

		$this->create_thankyou_order( 'failed', OrderPaymentStore::GATEWAY_ID );
		$_GET['key'] = 'wc_order_wrong';
		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ), 'The order key must match.' );
	}

	/**
	 * @testdox Should keep the default copy when the live intent cannot be fetched.
	 */
	public function test_keeps_order_received_text_when_intent_fetch_fails(): void {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'get_payment_intention' )->willThrowException( new WooPaymentsApiException( 'Nope.', 'wcpay_error', 500 ) );
		$page = $this->create_page( true, null, $api_client );
		$this->create_thankyou_order( 'pending', OrderPaymentStore::GATEWAY_ID_PREFIX . 'wechat_pay', 'pi_wechat' );

		$this->assertSame( 'Thank you.', $page->replace_order_received_text_for_failed_orders( 'Thank you.' ) );
	}

	/**
	 * @testdox Should receive the API client from the container so the redirect re-check is wired in production.
	 */
	public function test_container_wires_the_api_client(): void {
		$page     = wc_get_container()->get( WooPaymentsOrderSuccessPage::class );
		$property = new \ReflectionProperty( WooPaymentsOrderSuccessPage::class, 'api_client' );
		$property->setAccessible( true );

		$this->assertInstanceOf( WooPaymentsApiClient::class, $property->getValue( $page ) );
	}

	/**
	 * Create an API client mock answering the intent fetch.
	 *
	 * @param array $intent Intent payload to return.
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client_mock( array $intent ): WooPaymentsApiClient {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )->method( 'get_payment_intention' )->with( 'pi_wechat' )->willReturn( array_merge( array( 'id' => 'pi_wechat' ), $intent ) );

		return $api_client;
	}

	/**
	 * Create an order and point the order-received request at it.
	 *
	 * @param string $status         Order status.
	 * @param string $payment_method Payment method id.
	 * @param string $intent_id      Optional intent id stored on the order.
	 * @return WC_Order
	 */
	private function create_thankyou_order( string $status, string $payment_method, string $intent_id = '' ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		$order->set_total( '10.00' );
		$order->set_status( $status );
		if ( '' !== $intent_id ) {
			$order->update_meta_data( '_intent_id', $intent_id );
		}
		$order->save();

		$GLOBALS['wp']->query_vars['order-received'] = $order->get_id();
		$_GET['key']                                 = $order->get_order_key();

		return $order;
	}

	/**
	 * Create an order-success page controller.
	 *
	 * @param bool                                       $native_register Whether native should own runtime.
	 * @param WooPaymentsFrontendTrackingController|null $tracker        Optional tracking controller.
	 * @param WooPaymentsApiClient|null                  $api_client     Optional API client.
	 * @return WooPaymentsOrderSuccessPage
	 */
	private function create_page( bool $native_register, ?WooPaymentsFrontendTrackingController $tracker = null, ?WooPaymentsApiClient $api_client = null ): WooPaymentsOrderSuccessPage {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$page = new class() extends WooPaymentsOrderSuccessPage {
			/**
			 * Skip the plugin-parity one-second wait before the intent re-check.
			 */
			protected function wait_before_intent_recheck(): void {}
		};
		$page->init( $arbiter, new WooPaymentsPaymentMethodRegistry(), $account_service, $tracker, $api_client );

		return $page;
	}

	/**
	 * Create an on-hold Multibanco order with voucher meta.
	 *
	 * @return WC_Order
	 */
	private function create_multibanco_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID_PREFIX . 'multibanco' );
		$order->set_status( 'on-hold' );
		$order->set_currency( 'EUR' );
		$order->set_total( '123.45' );
		$order->update_meta_data( '_wcpay_multibanco_reference', '123 456 789' );
		$order->update_meta_data( '_wcpay_multibanco_entity', '12345' );
		$order->update_meta_data( '_wcpay_multibanco_url', 'https://pay.stripe.com/multibanco/voucher' );
		$order->update_meta_data( '_wcpay_multibanco_expiry', (string) ( time() + DAY_IN_SECONDS ) );
		$order->save();

		return $order;
	}

	/**
	 * Render Multibanco instructions for an order.
	 *
	 * @param WooPaymentsOrderSuccessPage $page  Order-success page controller.
	 * @param WC_Order                    $order Order to render.
	 * @return string
	 */
	private function render_multibanco_instructions( WooPaymentsOrderSuccessPage $page, WC_Order $order ): string {
		ob_start();
		$page->maybe_render_multibanco_payment_instructions( $order->get_id() );
		return (string) ob_get_clean();
	}

	/**
	 * Render Multibanco instructions for a hook payload.
	 *
	 * @param WooPaymentsOrderSuccessPage $page  Order-success page controller.
	 * @param WC_Order                    $order Order passed by the hook.
	 * @return string
	 */
	private function render_multibanco_instructions_from_hook_payload( WooPaymentsOrderSuccessPage $page, WC_Order $order ): string {
		ob_start();
		$page->maybe_render_multibanco_payment_instructions( $order );
		return (string) ob_get_clean();
	}
}
