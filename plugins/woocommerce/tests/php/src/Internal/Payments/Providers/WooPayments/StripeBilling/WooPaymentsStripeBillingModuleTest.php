<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\LateLoadedSubscriptions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Load condition of the Stripe Billing module (client 11.1.0 `includes/class-wc-payments-features.php:306-318`).
 *
 * Cases that need WooCommerce Subscriptions run in a separate process, since its class cannot be unloaded.
 */
class WooPaymentsStripeBillingModuleTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should not load without WooCommerce Subscriptions, even with the toggle on.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_load_without_subscriptions(): void {
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( true );

		$this->assertFalse( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * @testdox Should load with WooCommerce Subscriptions while the toggle is off, without enabling Stripe Billing.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loads_with_subscriptions_while_the_toggle_is_off(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );

		$sut = $this->register_module( true );

		$this->assertTrue( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * @testdox Should enable Stripe Billing when WooCommerce Subscriptions is active and the toggle is on.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enables_stripe_billing_with_subscriptions_and_the_toggle_on(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( true );

		$this->assertTrue( $sut->is_loaded() );
		$this->assertTrue( $sut->is_stripe_billing_enabled() );

		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );
		$this->assertFalse( $sut->is_stripe_billing_enabled(), 'Turning the toggle off must stop new Stripe Billing subscriptions in the same request.' );
	}

	/**
	 * @testdox Should not load while the WooPayments plugin owns payments.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_load_while_the_plugin_owns_payments(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );

		$sut = $this->register_module( false );

		$this->assertFalse( $sut->is_loaded() );
		$this->assertFalse( $sut->is_stripe_billing_enabled() );
	}

	/**
	 * @testdox Should leave the Stripe product IDs, legacy price IDs and hashes off a duplicated product, whatever the toggle (client `class-wc-payments-product-service.php:125`, `:312-324`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_duplicating_a_product_leaves_out_its_stripe_ids_and_hashes(): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );
		$this->register_module( true );

		$stripe_meta = array(
			'_wcpay_product_hash'          => '157edb40778acb45ff5dce71451e7ff1',
			'_wcpay_product_id_live'       => 'prod_VMl1fRBycUk6wP',
			'_wcpay_product_id_test'       => 'prod_VMl10VL0VK371N',
			'_wcpay_product_price_hash'    => 'c1f0e9b4d2a7c3e8f5b6a9d0e1f2a3b4',
			'_wcpay_product_price_id_live' => 'price_1UM1WdBzWlxcwgpPTdu1MSjd',
			'_wcpay_product_price_id_test' => 'price_1UM1VFBzWlxcwgpPfqFOGkZ2',
		);
		$product     = \WC_Helper_Product::create_simple_product();
		foreach ( $stripe_meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->update_meta_data( '_rec_t63_other_meta', 'copied' );
		$product->save();

		$duplicate = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $product->get_id() ) );
		$duplicate = wc_get_product( $duplicate->get_id() );

		$this->assertSame( 'copied', $duplicate->get_meta( '_rec_t63_other_meta' ), 'Other meta is still copied.' );
		foreach ( array_keys( $stripe_meta ) as $key ) {
			$this->assertFalse( $duplicate->meta_exists( $key ), "$key must not be copied." );
		}
	}

	/**
	 * @testdox Should tell a Stripe-billed subscription and its renewal order apart once loaded (client `class-wc-payments-subscription-service.php:292-294`, `:312-324`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tells_stripe_billed_subscriptions_and_orders_once_loaded(): void {
		$this->load_subscriptions();
		$sut                          = $this->register_module( true );
		list( $subscription, $order ) = $this->create_stripe_billed_subscription_and_renewal();

		$this->assertTrue( $sut->is_stripe_billed_subscription( $subscription ) );
		$this->assertTrue( $sut->is_stripe_billed_order( $order ) );
	}

	/**
	 * @testdox Should call nothing Stripe-billed while the module is not loaded.
	 */
	public function test_calls_nothing_stripe_billed_while_not_loaded(): void {
		$sut                          = $this->register_module( true );
		list( $subscription, $order ) = $this->create_stripe_billed_subscription_and_renewal();

		try {
			$this->assertFalse( $sut->is_stripe_billed_subscription( $subscription ) );
			$this->assertFalse( $sut->is_stripe_billed_order( $order ) );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}
	}

	/**
	 * @testdox A recurring payment carries the Stripe Billing fee context only when the order's subscription is Stripe-billed, toggle off included (client OrderServiceTest provider_subscription_details, `src/Internal/Service/OrderService.php:104-121`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @testWith ["initial", "parent", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "wcpay_subscription"]
	 *           ["renewal", "renewal", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "wcpay_subscription"]
	 *           ["initial", "parent", "", "regular_subscription"]
	 *           ["renewal", "renewal", "", "regular_subscription"]
	 *
	 * @param string $subscription_payment  Subscription payment type.
	 * @param string $relation              How the order relates to its subscription.
	 * @param string $wcpay_subscription_id Stripe subscription ID of the subscription, empty when it is tokenized.
	 * @param string $expected              Payment context sent to the platform.
	 */
	public function test_sends_the_stripe_billing_fee_context_only_for_stripe_billed_orders( string $subscription_payment, string $relation, string $wcpay_subscription_id, string $expected ): void {
		$this->load_subscriptions();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '0' );
		$this->register_module( true );
		list( $subscription, $order ) = $this->create_stripe_billed_subscription_and_renewal();
		$subscription->update_meta_data( '_wcpay_subscription_id', $wcpay_subscription_id );
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ] = array( $relation => array( $subscription->get_id() ) );

		$metadata = WooPaymentsIntentRequestBuilder::metadata_from_order( $order, 'recurring', $subscription_payment );

		$this->assertSame( $subscription_payment, $metadata['subscription_payment'] );
		$this->assertSame( $expected, $metadata['payment_context'] );
	}

	/**
	 * @testdox A recurring payment keeps the regular context while the module is not loaded (client test_get_payment_metadata_marks_subscription_as_regular_when_stripe_billing_not_loaded).
	 */
	public function test_sends_the_regular_context_while_not_loaded(): void {
		$this->register_module( true );
		list( , $order ) = $this->create_stripe_billed_subscription_and_renewal();

		try {
			$metadata = WooPaymentsIntentRequestBuilder::metadata_from_order( $order, 'recurring', 'renewal' );

			$this->assertSame( 'regular_subscription', $metadata['payment_context'] );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}
	}

	/**
	 * Create a subscription billed by Stripe Billing on the recorded main chain, and a renewal order of it.
	 *
	 * @return array{0:WC_Order,1:WC_Order}
	 */
	private function create_stripe_billed_subscription_and_renewal(): array {
		WooCommerceSubscriptionsDoubles::load();

		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->update_meta_data( '_wcpay_subscription_id', 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );
		$subscription->save();
		$order = \WC_Helper_Order::create_order();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][]                                   = $subscription->get_id();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ]['renewal'][] = $subscription->get_id();

		return array( wc_get_order( $subscription->get_id() ), $order );
	}

	/**
	 * Build and register the module with the given ownership decision.
	 *
	 * @param bool $native_owns Whether native owns payments.
	 * @return WooPaymentsStripeBillingModule
	 */
	private function register_module( bool $native_owns ): WooPaymentsStripeBillingModule {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );

		$sut = new WooPaymentsStripeBillingModule();
		$sut->init( $arbiter );
		$sut->register();

		return $sut;
	}

	/**
	 * Load a WooCommerce Subscriptions stand-in.
	 */
	private function load_subscriptions(): void {
		require_once __DIR__ . '/../Fixtures/LateLoadedSubscriptions.php';
		class_alias( LateLoadedSubscriptions::class, 'WC_Subscriptions' );
	}
}
