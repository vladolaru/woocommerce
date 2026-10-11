<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the renewal charge an extension reaches through the WooPayments gateway.
 *
 * `scheduled_subscription_payment( $amount, $order )` on the gateway WooCommerce registers as `woocommerce_payments` is
 * the supported way for an extension to charge an order's saved token with the customer absent, with or without
 * WooCommerce Subscriptions; the WooPayments extension has the same method (client 11.1.0
 * `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:402`). Each case takes the gateway the
 * way such an extension does and records the charge the gateway asks for.
 */
class NativeWooPaymentsGatewayRenewalEntryPointsTest extends WC_Unit_Test_Case {

	/**
	 * Records the charge instead of sending it.
	 *
	 * @var RecordingPaymentProcessingService
	 */
	private RecordingPaymentProcessingService $processing_service;

	/**
	 * Route payment processing to the recording double for everything the container builds from here on.
	 */
	public function setUp(): void {
		parent::setUp();
		wc_get_container()->reset_all_resolved();
		$this->processing_service = new RecordingPaymentProcessingService();
		wc_get_container()->replace( PaymentProcessingService::class, $this->processing_service );
	}

	/**
	 * Drop the container replacement and the services resolved with it.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Without WooCommerce Subscriptions, a renewal through the gateway charges the order's saved token off-session.
	 *
	 * Runs in its own process, so no WooCommerce Subscriptions double declared by another test is loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_renewal_without_subscriptions_charges_the_saved_token(): void {
		$this->assert_subscriptions_not_loaded();
		$order = $this->create_order_paid_with( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token = $this->add_saved_card( $order );

		$this->get_card_gateway()->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$this->assertSame( 1, $this->processing_service->checkout_attempt_count, 'The renewal must be charged once.' );
		$context = $this->processing_service->last_checkout_context;
		$this->assertSame( $order->get_id(), $context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $context->get_gateway_id() );
		$this->assertSame(
			array(
				'payment_token'       => (string) $token->get_id(),
				'save_payment_method' => false,
			),
			$context->get_payment_data(),
			'The charge must use the order\'s saved token and not save it again.'
		);
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $token->get_display_name(),
			),
			$context->get_provider_data(),
			'The charge must be a merchant-initiated renewal.'
		);
	}

	/**
	 * @testdox Without WooCommerce Subscriptions, a renewal of an order with no saved token fails the order without charging.
	 *
	 * Runs in its own process, so no WooCommerce Subscriptions double declared by another test is loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_renewal_without_subscriptions_or_saved_token_fails_the_order(): void {
		$this->assert_subscriptions_not_loaded();
		$order = $this->create_order_paid_with( WooPaymentsPersistenceVocabulary::GATEWAY_ID );

		$this->get_card_gateway()->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$this->assertSame( 0, $this->processing_service->checkout_attempt_count, 'Nothing may be charged without a saved token.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertContains(
			'Subscription renewal failed: No saved payment method found.',
			array_map( static fn( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) )
		);
	}

	/**
	 * @testdox A renewal through a split payment method gateway charges under that gateway's own ID.
	 */
	public function test_renewal_through_a_split_gateway_charges_under_its_own_id(): void {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'sepa_debit' );
		$this->assertNotNull( $definition );
		$gateway = new NativeWooPaymentsGateway( $definition );
		$order   = $this->create_order_paid_with( $gateway->id );
		$this->add_saved_card( $order );

		$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$this->assertSame( 1, $this->processing_service->checkout_attempt_count, 'The renewal must be charged once.' );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_sepa_debit', $this->processing_service->last_checkout_context->get_gateway_id() );
	}

	/**
	 * Fail early when a WooCommerce Subscriptions symbol the renewal could use is loaded.
	 */
	private function assert_subscriptions_not_loaded(): void {
		$this->assertFalse( function_exists( 'wcs_get_subscriptions_for_renewal_order' ), 'WooCommerce Subscriptions must not be loaded.' );
		$this->assertFalse( class_exists( 'WC_Subscriptions', false ), 'WooCommerce Subscriptions must not be loaded.' );
	}

	/**
	 * Get the card gateway the container builds, which WooCommerce registers as `woocommerce_payments` and the legacy
	 * `WC_Payments::get_gateway()` returns.
	 *
	 * @return NativeWooPaymentsGateway
	 */
	private function get_card_gateway(): NativeWooPaymentsGateway {
		return wc_get_container()->get( NativeWooPaymentsGateway::class );
	}

	/**
	 * Create a pending order paid with the given gateway, for a registered customer.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return WC_Order
	 */
	private function create_order_paid_with( string $gateway_id ): WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( self::factory()->user->create() );
		$order->set_payment_method( $gateway_id );
		$order->set_total( '12.00' );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * Save a card for the order's customer and attach it to the order.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Payment_Token_CC
	 */
	private function add_saved_card( WC_Order $order ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $order->get_customer_id() );
		$token->set_token( 'pm_renewal' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();
		$order->add_payment_token( $token );
		$order->save();

		return $token;
	}
}
