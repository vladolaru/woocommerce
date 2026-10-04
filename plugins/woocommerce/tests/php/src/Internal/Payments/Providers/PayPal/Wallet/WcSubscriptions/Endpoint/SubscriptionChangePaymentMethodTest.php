<?php
/**
 * Tests for the change-payment-method endpoint of WooCommerce Subscriptions (ported from the extension's
 * SubscriptionChangePaymentMethodTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Endpoint\SubscriptionChangePaymentMethod;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use WC_Order;
use WC_Payment_Token_CC;

require_once __DIR__ . '/WcsGetSubscriptionStub.php';

/**
 * Real users, orders as subscriptions, real payment tokens, and the real AJAX response; the request body is a mock of
 * the request reader and `wcs_get_subscription()` is answered from a list the test fills (the plugin is not loaded in
 * core's suite).
 *
 * Task 7 phase A: nothing in this endpoint is PayPal-hosted, so every case is `wallet` and holds across the cut.
 *
 * @group paypal-wallet
 */
class SubscriptionChangePaymentMethodTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The user who owns the subscription and sends the request.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * Another customer.
	 *
	 * @var int
	 */
	private int $other_id;

	/**
	 * Build the request reader and two customers.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data = $this->mock( RequestData::class );
		$this->owner_id     = self::factory()->user->create();
		$this->other_id     = self::factory()->user->create();
	}

	/**
	 * Forget the subscriptions and log out.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wallet_test_wcs_subscriptions'] = array();
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A subscription (an order here) for the given customer, known to `wcs_get_subscription()`.
	 *
	 * @param int $customer_id The customer.
	 * @return WC_Order
	 */
	private function subscription_for( int $customer_id ): WC_Order {
		$subscription = wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( 'bacs' );
		$subscription->save();

		$GLOBALS['wallet_test_wcs_subscriptions'][ $subscription->get_id() ] = $subscription;

		return $subscription;
	}

	/**
	 * A saved payment token for the given customer.
	 *
	 * @param int $customer_id The customer.
	 * @return WC_Payment_Token_CC
	 */
	private function token_for( int $customer_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'token-' . $customer_id );
		$token->set_gateway_id( 'ppcp-gateway' );
		$token->set_user_id( $customer_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '1234' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2099' );
		$token->save();

		return $token;
	}

	/**
	 * Make the request reader return the given request.
	 *
	 * @param int $subscription_id The subscription ID in the request.
	 * @param int $token_id        The payment token ID in the request.
	 */
	private function request( int $subscription_id, int $token_id ): void {
		$this->request_data->shouldReceive( 'read_request' )
			->once()
			->andReturn(
				array(
					'subscription_id'     => $subscription_id,
					'payment_method'      => 'ppcp-gateway',
					'wc_payment_token_id' => $token_id,
				)
			);
	}

	/**
	 * Run the endpoint and return the JSON it sent.
	 *
	 * @return array
	 */
	private function handle(): array {
		$sut = new SubscriptionChangePaymentMethod( $this->request_data );

		return $this->run_ajax_handler( array( $sut, 'handle_request' ) );
	}

	/**
	 * A customer owns a subscription but names another customer's subscription ID: the victim's subscription stays
	 * untouched.
	 *
	 * @testdox Should answer 403 and leave the subscription alone when it belongs to another user (wallet).
	 */
	public function test_returns_403_when_subscription_belongs_to_different_user(): void {
		$victim_subscription = $this->subscription_for( $this->other_id );
		$token               = $this->token_for( $this->owner_id );
		wp_set_current_user( $this->owner_id );
		$this->request( $victim_subscription->get_id(), $token->get_id() );

		$response = $this->handle();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission to modify this subscription', $response['data']['message'] );
		$this->assertSame( 'bacs', wc_get_order( $victim_subscription->get_id() )->get_payment_method(), 'The victim\'s subscription keeps its payment method' );
	}

	/**
	 * The user owns the subscription but supplies a token that belongs to another user: nothing changes.
	 *
	 * @testdox Should answer 403 and change nothing when the payment token belongs to another user (wallet).
	 */
	public function test_returns_403_when_payment_token_belongs_to_different_user(): void {
		$subscription = $this->subscription_for( $this->owner_id );
		$foreign      = $this->token_for( $this->other_id );
		wp_set_current_user( $this->owner_id );
		$this->request( $subscription->get_id(), $foreign->get_id() );

		$response = $this->handle();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission to use this payment token', $response['data']['message'] );
		$reloaded = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'bacs', $reloaded->get_payment_method() );
		$this->assertSame( array(), $reloaded->get_payment_tokens() );
	}

	/**
	 * @testdox Should set the payment method, attach the token and save when the user owns both (wallet).
	 */
	public function test_updates_payment_method_and_token_when_ownership_checks_pass(): void {
		$subscription = $this->subscription_for( $this->owner_id );
		$token        = $this->token_for( $this->owner_id );
		wp_set_current_user( $this->owner_id );
		$this->request( $subscription->get_id(), $token->get_id() );

		$response = $this->handle();

		$this->assertTrue( $response['success'] );
		$reloaded = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'ppcp-gateway', $reloaded->get_payment_method() );
		$this->assertSame( array( $token->get_id() ), array_map( 'intval', array_values( $reloaded->get_payment_tokens() ) ) );
	}
}
