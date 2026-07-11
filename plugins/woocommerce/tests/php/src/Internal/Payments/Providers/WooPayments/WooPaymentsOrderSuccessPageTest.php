<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
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
		foreach ( $this->registered_pages as $page ) {
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
	 * Create an order-success page controller.
	 *
	 * @param bool $native_register Whether native should own runtime.
	 * @return WooPaymentsOrderSuccessPage
	 */
	private function create_page( bool $native_register ): WooPaymentsOrderSuccessPage {
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

		$page = new WooPaymentsOrderSuccessPage();
		$page->init( $arbiter, new WooPaymentsPaymentMethodRegistry(), $account_service );

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
