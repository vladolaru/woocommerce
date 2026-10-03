<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingChangePaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Update payment method flow for Stripe-billed subscriptions whose renewal failed.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-subscription-change-payment-method.php`
 * and `includes/subscriptions/class-wc-payments-subscription-change-payment-method-handler.php`). Stripe IDs are those of the
 * recorded test-clock chain in `Fixtures/rec-t63-invoice-events.json`, whose renewal invoice failed.
 */
class StripeBillingChangePaymentMethodHandlerTest extends WC_Unit_Test_Case {

	private const CLOCK_SUBSCRIPTION_ID  = 'sub_1UM1XLBzWlxcwgpPwEbcmZJt';
	private const FAILED_INVOICE_ID      = 'in_1UM1Y0BzWlxcwgpPefRU4SSy';
	private const SUBSCRIPTION_EDIT_CAP  = 'edit_shop_subscription_payment_method';
	private const REDIRECT_EXCEPTION_TAG = 'redirected to ';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingChangePaymentMethodHandler
	 */
	private StripeBillingChangePaymentMethodHandler $sut;

	/**
	 * Set up the handler over the module's services.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerceSubscriptionsDoubles::load();

		$this->sut = new StripeBillingChangePaymentMethodHandler();
		$this->sut->init( wc_get_container()->get( StripeBillingSubscriptionService::class ), wc_get_container()->get( StripeBillingInvoiceService::class ) );
	}

	/**
	 * Clear the subscription registries and the request.
	 */
	public function tearDown(): void {
		try {
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$_GET['change_payment_method'],
				$_GET['pay_for_order'],
				$_GET['key'],
				$_GET['order_id']
			);
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Only a Stripe-billed subscription on hold after its last order failed, with an invoice still pending, can have its payment method updated: $label.
	 * @testWith ["needs an update", "", true]
	 *           ["active", "status", false]
	 *           ["last order pending", "last_order", false]
	 *           ["no pending invoice", "pending_invoice", false]
	 *           ["no Stripe subscription", "stripe_subscription", false]
	 *           ["paid with another gateway", "gateway", false]
	 *           ["not a subscription", "subscription", false]
	 *
	 * @param string $label    Case name.
	 * @param string $breaker  What is changed from a subscription that needs an update.
	 * @param bool   $expected Whether the payment method can be updated.
	 */
	public function test_tells_which_subscriptions_need_a_new_payment_method( string $label, string $breaker, bool $expected ): void {
		unset( $label );
		list( $subscription, $order ) = $this->create_subscription_needing_update();
		$this->break_subscription( $subscription, $order, $breaker );

		$this->assertSame( $expected, $this->sut->can_update_payment_method( false, $subscription ) );
		$this->assertTrue( $this->sut->can_update_payment_method( true, $subscription ), 'An update allowed for another reason stays allowed.' );
	}

	/**
	 * @testdox Should replace the "Change payment" action with "Update payment method" only for a subscription that needs it.
	 */
	public function test_replaces_the_change_payment_action(): void {
		list( $subscription, $order ) = $this->create_subscription_needing_update();
		$default_actions              = array(
			'suspend'               => array(
				'name' => 'Suspend',
				'url'  => 'https://example.org/suspend',
			),
			'change_payment_method' => array(
				'name' => 'Change payment',
				'url'  => 'https://example.org/change',
			),
		);

		$actions = $this->sut->update_subscription_change_payment_button( $default_actions, $subscription );

		$this->assertSame( $default_actions['suspend'], $actions['suspend'] );
		$this->assertSame( 'Update payment method', $actions['change_payment_method']['name'] );
		$this->assert_update_payment_url( $subscription, $actions['change_payment_method']['url'] );

		$this->break_subscription( $subscription, $order, 'last_order' );
		$this->assertSame( $default_actions, $this->sut->update_subscription_change_payment_button( $default_actions, $subscription ) );
	}

	/**
	 * @testdox Should send the "Pay" action of a failed invoice order to the update payment method page, and drop it when the subscription is gone.
	 */
	public function test_points_the_pay_action_to_the_update_page(): void {
		list( $subscription, $order ) = $this->create_subscription_needing_update();
		$cancel_only                  = array( 'cancel' => array( 'url' => 'https://example.org/cancel' ) );
		$with_pay                     = $cancel_only + array( 'pay' => array( 'url' => 'https://example.org/pay' ) );

		$this->assertSame( $cancel_only, $this->sut->update_order_pay_button( $cancel_only, $order ), 'Without a Pay action there is nothing to change.' );

		$actions = $this->sut->update_order_pay_button( $with_pay, $order );
		$this->assertSame( $with_pay['cancel'], $actions['cancel'] );
		$this->assert_update_payment_url( $subscription, $actions['pay']['url'] );

		$plain_order = \WC_Helper_Order::create_order();
		$this->assertSame( $with_pay, $this->sut->update_order_pay_button( $with_pay, $plain_order ), 'An order without an invoice keeps its Pay action.' );

		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ] );
		$this->assertSame( $cancel_only, $this->sut->update_order_pay_button( $with_pay, $order ), 'An invoice order is never paid directly.' );
	}

	/**
	 * @testdox Should retitle the change payment method page and explain the failed renewal only for a subscription that needs it.
	 */
	public function test_retitles_the_change_payment_method_page(): void {
		list( $subscription, $order ) = $this->create_subscription_needing_update();

		$this->assertSame( 'Update payment details', $this->sut->change_payment_method_page_title( 'Change payment method', $subscription ) );
		$this->assertSame( "Your subscription's last renewal failed payment. Please update your payment details so we can reattempt payment.", $this->sut->change_payment_method_page_notice( 'Choose a new payment method.', $subscription ) );

		$this->break_subscription( $subscription, $order, 'last_order' );
		$this->assertSame( 'Change payment method', $this->sut->change_payment_method_page_title( 'Change payment method', $subscription ) );
		$this->assertSame( 'Choose a new payment method.', $this->sut->change_payment_method_page_notice( 'Choose a new payment method.', $subscription ) );
	}

	/**
	 * @testdox Should say "Update and retry payment" on the change payment method button only while updating a subscription that needs it.
	 */
	public function test_says_the_payment_is_retried_on_the_button(): void {
		list( $subscription, $order ) = $this->create_subscription_needing_update();

		$this->assertSame( 'Change payment method', $this->sut->change_payment_method_form_submit_text( 'Change payment method' ), 'Not on the change payment method page.' );

		$_GET['change_payment_method'] = (string) $subscription->get_id();
		$this->assertSame( 'Update and retry payment', $this->sut->change_payment_method_form_submit_text( 'Change payment method' ) );

		$this->break_subscription( $subscription, $order, 'last_order' );
		$this->assertSame( 'Change payment method', $this->sut->change_payment_method_form_submit_text( 'Change payment method' ) );
	}

	/**
	 * @testdox Should redirect the pay-for-order page of a failed invoice order to the update payment method page, unless the customer may not change it, the key is wrong or the customer is already changing the payment method.
	 */
	public function test_redirects_paying_a_failed_invoice_order_to_the_update_page(): void {
		list( $subscription, $order ) = $this->create_subscription_needing_update();
		wp_set_current_user( $order->get_customer_id() );
		add_filter( 'wp_redirect', array( $this, 'stop_at_redirect' ) );

		$_GET['pay_for_order'] = 'true';
		$_GET['order_id']      = (string) $order->get_id();
		$_GET['key']           = $order->get_order_key();
		$this->assertNull( $this->get_redirect_location(), 'A customer who may not change the payment method is not redirected.' );

		add_filter( 'user_has_cap', array( $this, 'grant_subscription_payment_method_edit' ) );
		$_GET['key'] = 'wc_order_wrong';
		$this->assertNull( $this->get_redirect_location(), 'A wrong order key is not redirected.' );

		$_GET['key']                   = $order->get_order_key();
		$_GET['change_payment_method'] = (string) $subscription->get_id();
		$this->assertNull( $this->get_redirect_location(), 'The update payment method page itself is not redirected.' );

		unset( $_GET['change_payment_method'] );
		$this->assert_update_payment_url( $subscription, (string) $this->get_redirect_location() );
	}

	/**
	 * Grant the WooCommerce Subscriptions capability to change a subscription's payment method.
	 *
	 * @param array<string,bool> $allcaps Capabilities of the user.
	 * @return array<string,bool>
	 */
	public function grant_subscription_payment_method_edit( $allcaps ) {
		$allcaps[ self::SUBSCRIPTION_EDIT_CAP ] = true;

		return $allcaps;
	}

	/**
	 * Stop the request at the redirect, carrying its location.
	 *
	 * @param string $location Redirect location.
	 * @throws \RuntimeException Always.
	 */
	public function stop_at_redirect( $location ): void {
		throw new \RuntimeException( self::REDIRECT_EXCEPTION_TAG . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test control flow, not output.
	}

	/**
	 * Run the pay-for-order redirect and get where it went, or null when it did not redirect.
	 *
	 * @return string|null
	 */
	private function get_redirect_location(): ?string {
		try {
			$this->sut->redirect_pay_for_order_to_update_payment_method();
		} catch ( \RuntimeException $exception ) {
			$this->assertStringStartsWith( self::REDIRECT_EXCEPTION_TAG, $exception->getMessage() );

			return substr( $exception->getMessage(), strlen( self::REDIRECT_EXCEPTION_TAG ) );
		}

		return null;
	}

	/**
	 * Assert a URL is the subscription's update payment method page: its pay-for-order page with the change flag and a valid nonce.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $url          URL.
	 */
	private function assert_update_payment_url( WC_Order $subscription, string $url ): void {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( (string) $subscription->get_id(), $query['change_payment_method'] ?? null );
		$this->assertSame( 'true', $query['pay_for_order'] ?? null );
		$this->assertSame( $subscription->get_order_key(), $query['key'] ?? null );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'] ?? '' ) );
	}

	/**
	 * Create a Stripe-billed subscription on hold whose renewal order failed, with the renewal invoice still pending.
	 *
	 * @return array{0:SubscriptionDouble,1:WC_Order}
	 */
	private function create_subscription_needing_update(): array {
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		$subscription = new SubscriptionDouble();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_status( 'on-hold' );
		$subscription->update_meta_data( StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY, self::CLOCK_SUBSCRIPTION_ID );
		$subscription->update_meta_data( StripeBillingInvoiceService::PENDING_INVOICE_ID_KEY, self::FAILED_INVOICE_ID );
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		$order = \WC_Helper_Order::create_order( $customer_id );
		$order->set_status( 'failed' );
		$order->update_meta_data( StripeBillingInvoiceService::ORDER_INVOICE_ID_KEY, self::FAILED_INVOICE_ID );
		$order->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ]['renewal'][] = $subscription->get_id();

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( SubscriptionDouble::class, $subscription );

		return array( $subscription, $order );
	}

	/**
	 * Change one thing that makes a subscription need a new payment method.
	 *
	 * @param SubscriptionDouble $subscription Subscription.
	 * @param WC_Order           $order        Its failed renewal order.
	 * @param string             $breaker      What to change; empty for nothing.
	 */
	private function break_subscription( SubscriptionDouble $subscription, WC_Order $order, string $breaker ): void {
		switch ( $breaker ) {
			case 'status':
				$subscription->set_status( 'active' );
				break;
			case 'last_order':
				$order->set_status( 'pending' );
				$order->save();
				break;
			case 'pending_invoice':
				$subscription->delete_meta_data( StripeBillingInvoiceService::PENDING_INVOICE_ID_KEY );
				break;
			case 'stripe_subscription':
				$subscription->delete_meta_data( StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY );
				break;
			case 'gateway':
				$subscription->set_payment_method( 'bacs' );
				break;
			case 'subscription':
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array();
				break;
		}
		$subscription->save();
	}
}
