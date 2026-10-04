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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
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
			remove_filter( 'woocommerce_order_email_verification_required', array( $page, 'maybe_skip_email_verification_after_payment' ) );
			remove_action( 'woocommerce_email_order_details', array( $page, 'add_multibanco_payment_instructions_to_order_on_hold_email' ) );
		}
		if ( WC() && WC()->session ) {
			WC()->session->set( 'wcpay_paid_intent_id', null );
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
			->onlyMethods( array( 'queue_user_event' ) )
			->getMock();
		$tracker->method( 'queue_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties = array() ) use ( &$recorded_events ): void {
				$recorded_events[] = array( $event_name, $properties );
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
				array( 'order_success_page_view', array() ),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should queue a guest's thank-you page view for the footer script instead of recording it during render, like client 11.1.0.
	 */
	public function test_order_success_page_view_renders_without_recording_and_queues_for_the_footer_script(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( 0 );
		$recorder_calls = 0;
		$http_calls     = 0;
		$count_recorder = static function ( $properties ) use ( &$recorder_calls ) {
			++$recorder_calls;
			return $properties;
		};
		$count_http     = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'wcpay_tracks_event_properties', $count_recorder );
		add_filter( 'pre_http_request', $count_http );
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( true );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_cached_account_data', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'can_process_payments' )->willReturn( true );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'platform_checkout_eligible' => true ) );
		$account_service->method( 'get_gateway_setting' )->willReturn( 'yes' );
		$tracker = new WooPaymentsFrontendTrackingController();
		$tracker->init( $arbiter, $account_service );
		$page  = $this->create_page( true, $tracker );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->save();

		try {
			$page->record_order_success_page_view( $order->get_id() );

			$this->assertSame( 0, $recorder_calls );
			$this->assertSame( 0, $http_calls );
			$tracker->enqueue_frontend_events_script();
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );
		} finally {
			remove_filter( 'wcpay_tracks_event_properties', $count_recorder );
			remove_filter( 'pre_http_request', $count_http );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
		}

		$this->assertSame( 1, preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) );
		$params = json_decode( $matches[1], true );
		$this->assertSame(
			array(
				array(
					'event'      => 'order_success_page_view',
					'properties' => array(
						'record_event_data' => array(
							'is_admin_event'      => false,
							'track_on_all_stores' => true,
						),
					),
				),
			),
			$params['events']
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
	 * @testdox Should enqueue native order-success assets on the order-received page of a WooPayments order.
	 * @testWith ["woocommerce_payments"]
	 *           ["woocommerce_payments_multibanco"]
	 *
	 * @param string $payment_method Order payment method.
	 */
	public function test_enqueues_order_success_assets_on_order_received_page( string $payment_method ): void {
		$page = $this->create_page( true );
		$this->create_thankyou_order( 'processing', $payment_method );
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
	 * @testdox Should not enqueue native order-success assets on the order-received page of another gateway's order or a key mismatch.
	 * @testWith ["bacs", true]
	 *           ["cod", true]
	 *           ["woocommerce_payments", false]
	 *
	 * @param string $payment_method Order payment method.
	 * @param bool   $key_matches    Whether the request carries the order key.
	 */
	public function test_skips_order_success_assets_for_orders_not_shown_as_woopayments_orders( string $payment_method, bool $key_matches ): void {
		$page = $this->create_page( true );
		$this->create_thankyou_order( 'processing', $payment_method );
		if ( ! $key_matches ) {
			$_GET['key'] = 'wc_order_mismatch';
		}
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		try {
			$page->enqueue_assets();
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		}

		$this->assertFalse( wp_script_is( 'wc-woopayments-order-success', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-order-success', 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue native order-success assets on the view-order page only for the customer who can view the WooPayments order.
	 * @testWith [true, true]
	 *           [false, false]
	 *
	 * @param bool $is_owner        Whether the current user owns the order.
	 * @param bool $expect_enqueued Whether the assets should be enqueued.
	 */
	public function test_enqueues_order_success_assets_on_view_order_page_for_the_order_owner( bool $is_owner, bool $expect_enqueued ): void {
		$page        = $this->create_page( true );
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->save();
		$myaccount_page_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $myaccount_page_id );
		$this->go_to( get_permalink( $myaccount_page_id ) );
		$GLOBALS['wp']->query_vars['view-order'] = $order->get_id();
		wp_set_current_user( $is_owner ? $customer_id : $this->factory->user->create( array( 'role' => 'customer' ) ) );

		try {
			$page->enqueue_assets();
		} finally {
			unset( $GLOBALS['wp']->query_vars['view-order'] );
		}

		$this->assertSame( $expect_enqueued, wp_script_is( 'wc-woopayments-order-success', 'enqueued' ) );
		$this->assertSame( $expect_enqueued, wp_style_is( 'wc-woopayments-order-success', 'enqueued' ) );
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
	 * Client 11.1.0 class-wc-payments-order-success-page.php:40,593-690.
	 *
	 * @testdox The customer on-hold email ($format) carries the Multibanco entity, reference, amount and expiry.
	 * @testWith ["html"]
	 *           ["plain"]
	 *
	 * @param string $format Email format.
	 */
	public function test_on_hold_email_carries_multibanco_payment_instructions( string $format ): void {
		$page = $this->create_page( true );
		$page->register();
		$this->registered_pages[] = $page;
		$order                    = $this->create_multibanco_order();
		$order->update_meta_data( '_wcpay_multibanco_expiry', '1798761600' );
		$order->save();

		$output = $this->render_customer_email( 'WC_Email_Customer_On_Hold_Order', $order, 'plain' === $format );

		$this->assertStringContainsString( 'plain' === $format ? 'Multibanco Payment instructions' : 'Payment instructions', $output, 'Client :606 (plain) and :658 (HTML) headings.' );
		$this->assertStringContainsString( '12345', $output, 'Client :615/:672 entity.' );
		$this->assertStringContainsString( '123 456 789', $output, 'Client :616/:676 reference.' );
		$this->assertStringContainsString( '123.45', $output, 'Client :617/:680 amount.' );
		$this->assertStringContainsString( 'January 1, 2027 12:00 am', $output, 'Client :601 expiry in the store date and time format.' );
	}

	/**
	 * Client 11.1.0 class-wc-payments-order-success-page.php:594 returns early for these.
	 *
	 * @testdox Emails add no Multibanco instructions for another payment method or another email.
	 * @testWith ["WC_Email_Customer_On_Hold_Order", "woocommerce_payments"]
	 *           ["WC_Email_Customer_Processing_Order", "woocommerce_payments_multibanco"]
	 *
	 * @param string $email_class    Email class.
	 * @param string $payment_method Order payment method.
	 */
	public function test_emails_add_no_multibanco_instructions_outside_multibanco_on_hold_email( string $email_class, string $payment_method ): void {
		$page = $this->create_page( true );
		$page->register();
		$this->registered_pages[] = $page;
		$order                    = $this->create_multibanco_order();
		$order->set_payment_method( $payment_method );
		$order->save();

		$output = $this->render_customer_email( $email_class, $order, false );

		$this->assertStringNotContainsString( '123 456 789', $output );
		$this->assertStringNotContainsString( 'Enter the entity number, reference number, and amount.', $output );
	}

	/**
	 * @testdox Should render the registry-backed LPM title and alt text on the order-received page, not the incoming title.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payments-order-success-page.php:333-369`
	 * (`show_lpm_payment_method_name`): the LPM wrapper class, logo, and alt text all carry the
	 * method's own registry title, not whatever title WooCommerce core passed in. Each row passes an
	 * incoming title that never matches the registry's own title, so a rendered `alt` equal to the
	 * registry title proves the lookup happened rather than an echo of the input.
	 *
	 * @dataProvider lpm_payment_method_title_provider
	 *
	 * @param string      $method_id          Split gateway payment method ID.
	 * @param string      $icon_fragment      Expected light icon path fragment.
	 * @param string|null $dark_icon_fragment Expected dark icon path fragment, or null when the method has none.
	 * @param string      $expected_alt       Expected `alt` attribute (the registry's own title).
	 */
	public function test_filters_lpm_payment_method_title_on_order_received_page( string $method_id, string $icon_fragment, ?string $dark_icon_fragment, string $expected_alt ): void {
		$page = $this->create_page( true );
		if ( 'multibanco' === $method_id ) {
			$order = $this->create_multibanco_order();
		} else {
			$order = wc_create_order();
			$this->assertInstanceOf( WC_Order::class, $order );
			$order->set_payment_method( OrderPaymentStore::GATEWAY_ID_PREFIX . $method_id );
			$order->save();
		}
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		try {
			$title = $page->filter_payment_method_title( 'Not the registry title', $order );
		} finally {
			remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		}

		$this->assertStringContainsString( "wc-payment-lpm-logo--$method_id", $title );
		$this->assertStringContainsString( $icon_fragment, $title );
		$this->assertStringContainsString( 'alt="' . $expected_alt . '"', $title, 'The alt text must come from the payment method registry, not the incoming title.' );
		if ( null !== $dark_icon_fragment ) {
			$this->assertStringContainsString( $dark_icon_fragment, $title );
		}
	}

	/**
	 * LPM payment-method title fixtures.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:string}>
	 */
	public function lpm_payment_method_title_provider(): array {
		return array(
			'Multibanco' => array( 'multibanco', '/assets/images/payment-methods/multibanco-logo.svg', '/assets/images/payment-methods/multibanco-logo-dark.svg', 'Multibanco' ),
			'Alipay'     => array( 'alipay', '/assets/images/payment-methods/alipay-logo-color.svg', null, 'Alipay' ),
		);
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

		$this->assertStringContainsString( '/assets/images/payment-methods/visa-card-color.svg', $title );
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
	 * @testdox Should register the paid-intent email-verification exception only when native owns runtime.
	 */
	public function test_registers_paid_intent_email_verification_exception_only_when_native_owns_runtime(): void {
		$plugin_owned = $this->create_page( false );
		$native_owned = $this->create_page( true );

		$plugin_owned->register();
		$this->assertFalse( has_filter( 'woocommerce_order_email_verification_required', array( $plugin_owned, 'maybe_skip_email_verification_after_payment' ) ) );

		$native_owned->register();
		$this->registered_pages[] = $native_owned;
		$this->assertSame( 10, has_filter( 'woocommerce_order_email_verification_required', array( $native_owned, 'maybe_skip_email_verification_after_payment' ) ) );

		global $wp_filter;
		$callbacks = $wp_filter['woocommerce_order_email_verification_required']->callbacks[10];
		$matching  = array_filter(
			$callbacks,
			static function ( array $callback ) use ( $native_owned ): bool {
				return array( $native_owned, 'maybe_skip_email_verification_after_payment' ) === $callback['function'];
			}
		);

		$this->assertCount( 1, $matching );
		$this->assertSame( 3, reset( $matching )['accepted_args'] );
	}

	/**
	 * @testdox Should waive order-received email verification for the exact session-paid native intent.
	 */
	public function test_skips_email_verification_for_matching_session_paid_intent(): void {
		$page  = $this->create_page( true );
		$order = $this->create_thankyou_order( 'processing', OrderPaymentStore::GATEWAY_ID, 'pi_mock' );
		WC()->session->set( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY, 'pi_mock' );
		$page->register();
		$this->registered_pages[] = $page;

		$this->assertFalse( $page->maybe_skip_email_verification_after_payment( true, $order, 'order-received' ) );
		$this->assertFalse( apply_filters( 'woocommerce_order_email_verification_required', true, $order, 'order-received' ) );
	}

	/**
	 * @testdox Should preserve email-verification requirements outside the exact paid-intent session match.
	 *
	 * @dataProvider email_verification_exception_negative_cases
	 *
	 * @param mixed  $required Incoming filter value.
	 * @param string $context Filter context.
	 * @param bool   $pass_order Whether to pass a WC order to the callback.
	 * @param string $payment_method Payment method on the test order.
	 * @param string $order_intent_id Order PaymentIntent ID.
	 * @param bool   $has_session Whether a WC session is available.
	 * @param string $session_intent_id Session PaymentIntent ID.
	 */
	public function test_keeps_email_verification_requirement_without_exact_paid_intent_match( $required, string $context, bool $pass_order, string $payment_method, string $order_intent_id, bool $has_session, string $session_intent_id ): void {
		$page             = $this->create_page( true );
		$order            = $this->create_thankyou_order( 'processing', $payment_method, $order_intent_id );
		$original_session = WC()->session;

		if ( $has_session ) {
			WC()->session->set( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY, $session_intent_id );
		} else {
			WC()->session = null;
		}

		try {
			$result = $page->maybe_skip_email_verification_after_payment( $required, $pass_order ? $order : 'not-an-order', $context );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( $required, $result );
	}

	/**
	 * Provide contexts that must not waive email verification.
	 *
	 * @return array<string,array{0:mixed,1:string,2:bool,3:string,4:string,5:bool,6:string}>
	 */
	public function email_verification_exception_negative_cases(): array {
		return array(
			'false input'              => array( false, 'order-received', true, OrderPaymentStore::GATEWAY_ID, 'pi_mock', true, 'pi_mock' ),
			'nonboolean input'         => array( 'required', 'order-received', true, OrderPaymentStore::GATEWAY_ID, 'pi_mock', true, 'pi_mock' ),
			'wrong context'            => array( true, 'checkout', true, OrderPaymentStore::GATEWAY_ID, 'pi_mock', true, 'pi_mock' ),
			'non-order input'          => array( true, 'order-received', false, OrderPaymentStore::GATEWAY_ID, 'pi_mock', true, 'pi_mock' ),
			'provider-suffixed method' => array( true, 'order-received', true, OrderPaymentStore::GATEWAY_ID_PREFIX . 'wechat_pay', 'pi_mock', true, 'pi_mock' ),
			'other method'             => array( true, 'order-received', true, 'cod', 'pi_mock', true, 'pi_mock' ),
			'absent session'           => array( true, 'order-received', true, OrderPaymentStore::GATEWAY_ID, 'pi_mock', false, 'pi_mock' ),
			'empty session intent'     => array( true, 'order-received', true, OrderPaymentStore::GATEWAY_ID, 'pi_mock', true, '' ),
			'empty order intent'       => array( true, 'order-received', true, OrderPaymentStore::GATEWAY_ID, '', true, 'pi_mock' ),
			'mismatching intent'       => array( true, 'order-received', true, OrderPaymentStore::GATEWAY_ID, 'pi_order', true, 'pi_session' ),
		);
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
	 * Render a customer email for an order as WooCommerce sends it.
	 *
	 * @param string   $email_class Email class name.
	 * @param WC_Order $order       Order the email is about.
	 * @param bool     $plain_text  Whether to render the plain-text version.
	 * @return string
	 */
	private function render_customer_email( string $email_class, WC_Order $order, bool $plain_text ): string {
		$email         = WC()->mailer()->get_emails()[ $email_class ];
		$email->object = $order;

		return $plain_text ? $email->get_content_plain() : $email->get_content_html();
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
