<?php
/**
 * Tests for the save payment methods module (ported from the extension's SavePaymentMethodsModuleTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\SavePaymentMethodsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use WC_Order;

/**
 * What the module asks PayPal to vault on the create-order request, and the settings gate that decides whether it does.
 *
 * The extension's last case ("the gate passes via card saving, but wallet saving is off") rests on a premise the cards
 * cut removed: the gate no longer reads card saving. It is replaced by a gate case in which wallet saving is the only
 * setting that can be read.
 * The Apple Pay vaulting cases stay while the Apple Pay code does.
 *
 * @group paypal-wallet
 */
class SavePaymentMethodsModuleTest extends WalletTestCase {

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * The service container mock.
	 *
	 * @var ContainerInterface&MockInterface
	 */
	private $container;

	/**
	 * Mock the container the module reads its services from.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings            = $this->mock( SettingsProvider::class );
		$this->subscription_helper = $this->mock( SubscriptionHelper::class );

		$this->container = $this->mock( ContainerInterface::class );
		$this->container->shouldReceive( 'get' )->with( 'save-payment-methods.eligible' )->andReturn( true );
		$this->container->shouldReceive( 'get' )->with( 'settings.settings-provider' )->andReturn( $this->settings );
		$this->container->shouldReceive( 'get' )->with( 'wc-subscriptions.helper' )->andReturn( $this->subscription_helper );
	}

	/**
	 * Run the module and fire the deferred `after_setup_theme` registration, which the wallet saving setting gates.
	 *
	 * Core's hooks are restored after each test, so the module's callbacks do not leak into other tests. The hooks the
	 * module registers are cleared first, so only its callbacks run.
	 */
	private function run_module(): void {
		remove_all_actions( 'after_setup_theme' );
		remove_all_filters( 'ppcp_create_order_request_body_data' );
		remove_all_filters( 'woocommerce_paypal_payments_localized_script_data' );

		( new SavePaymentMethodsModule() )->run( $this->container );

		do_action( 'after_setup_theme' );
	}

	/**
	 * A create-order request body for an Apple Pay payment source.
	 *
	 * @return array
	 */
	private function apple_pay_order_data(): array {
		return array(
			'payment_source' => array(
				'apple_pay' => array(
					'experience_context' => array( 'return_url' => 'https://example.test' ),
				),
			),
		);
	}

	/**
	 * Let the subscription helper answer the three questions the module asks about the purchase.
	 *
	 * @param bool $cart_contains_subscription Whether the cart holds a subscription.
	 */
	private function subscription_purchase( bool $cart_contains_subscription ): void {
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( $cart_contains_subscription );
		$this->subscription_helper->shouldReceive( 'current_product_is_subscription' )->andReturn( false );
		$this->subscription_helper->shouldReceive( 'order_pay_contains_subscription' )->andReturn( false );
	}

	/**
	 * @testdox Apple Pay purchase of a subscription requests vaulting, so a token is stored for later renewals
	 */
	public function test_adds_vault_attributes_for_apple_pay_subscription(): void {
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->subscription_purchase( true );

		$this->run_module();

		$result = apply_filters(
			'ppcp_create_order_request_body_data',
			$this->apple_pay_order_data(),
			PayPalGateway::ID,
			array( 'funding_source' => 'apple_pay' )
		);

		$this->assertSame(
			'ON_SUCCESS',
			$result['payment_source']['apple_pay']['attributes']['vault']['store_in_vault']
		);
		// The experience context must be preserved.
		$this->assertArrayHasKey( 'experience_context', $result['payment_source']['apple_pay'] );
		// The PayPal wallet attributes must not be added for Apple Pay.
		$this->assertArrayNotHasKey( 'usage_type', $result['payment_source']['apple_pay']['attributes']['vault'] );
	}

	/**
	 * @testdox The saved PayPal customer ID is attached, so the token is vaulted against the right customer
	 */
	public function test_adds_customer_id_when_available(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, '_ppcp_target_customer_id', 'CUST-123' );

		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->subscription_purchase( true );

		$this->run_module();

		$result = apply_filters(
			'ppcp_create_order_request_body_data',
			$this->apple_pay_order_data(),
			PayPalGateway::ID,
			array( 'funding_source' => 'apple_pay' )
		);

		$this->assertSame(
			'CUST-123',
			$result['payment_source']['apple_pay']['attributes']['customer']['id']
		);
	}

	/**
	 * @testdox A one-off Apple Pay purchase is not vaulted
	 */
	public function test_does_not_vault_apple_pay_without_subscription(): void {
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->subscription_purchase( false );

		$this->run_module();

		$result = apply_filters(
			'ppcp_create_order_request_body_data',
			$this->apple_pay_order_data(),
			PayPalGateway::ID,
			array( 'funding_source' => 'apple_pay' )
		);

		$this->assertArrayNotHasKey( 'attributes', $result['payment_source']['apple_pay'] );
	}

	/**
	 * Replaces the extension's "gate passes via card saving, but wallet saving is off" case.
	 *
	 * The settings mock has exactly one expectation: save_paypal_and_venmo(), once. Mockery throws on any other settings
	 * read, so widening the gate (for example back to also reading card saving) fails this test.
	 *
	 * @testdox Should set up no vaulting when wallet saving is off, reading no other setting (wallet).
	 */
	public function test_does_not_set_up_vaulting_when_wallet_saving_is_off(): void {
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->once()->andReturn( false );

		$this->run_module();

		$this->assertFalse(
			has_filter( 'ppcp_create_order_request_body_data' ),
			'The request body filter is registered although wallet saving is off.'
		);
		$this->assertFalse(
			has_filter( 'woocommerce_paypal_payments_localized_script_data' ),
			'The script data filter is registered although wallet saving is off.'
		);

		$data   = $this->apple_pay_order_data();
		$result = apply_filters(
			'ppcp_create_order_request_body_data',
			$data,
			PayPalGateway::ID,
			array( 'funding_source' => 'apple_pay' )
		);

		$this->assertSame( $data, $result );
	}

	/**
	 * Fire the after-order-processor hook for a PayPal order whose vault result carries the given payment source.
	 *
	 * @param string $source_name The payment source name in the vault result (for example "card" or "paypal").
	 * @param int    $customer_id The WooCommerce customer who owns the order.
	 * @param object $tokens      The WooCommercePaymentTokens mock the module reads from the container.
	 */
	private function process_vaulted_order( string $source_name, int $customer_id, $tokens ): void {
		$this->settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$this->container->shouldReceive( 'get' )->with( 'wc-payment-tokens.wc-payment-tokens' )->andReturn( $tokens );

		remove_all_actions( 'woocommerce_paypal_payments_after_order_processor' );
		$this->run_module();

		$wc_order = new WC_Order();
		$wc_order->set_customer_id( $customer_id );
		$wc_order->set_payment_method( PayPalGateway::ID );
		$wc_order->save();

		$properties = (object) array(
			'attributes'    => (object) array(
				'vault' => (object) array(
					'id'       => 'VAULT-TOKEN-1',
					'customer' => (object) array( 'id' => 'PP-CUSTOMER-1' ),
				),
			),
			'email_address' => 'buyer@example.test',
		);
		$order      = new Order( 'PP-ORDER-1', array(), new OrderStatus( OrderStatus::COMPLETED ), new PaymentSource( $source_name, $properties ) );

		do_action( 'woocommerce_paypal_payments_after_order_processor', $wc_order, $order );
	}

	/**
	 * Covers the order-processing path; the AJAX endpoint's card case lives in CreatePaymentTokenTest.
	 *
	 * @testdox Should create no WooCommerce token for a card vault result, and still remember the PayPal customer (wallet).
	 */
	public function test_card_vault_result_creates_no_token_after_order_processing(): void {
		$user_id = self::factory()->user->create();
		$tokens  = $this->mock( WooCommercePaymentTokens::class );
		$tokens->shouldNotReceive( 'create_payment_token_paypal' );
		$tokens->shouldNotReceive( 'create_payment_token_venmo' );
		$tokens->shouldNotReceive( 'create_payment_token_applepay' );

		$this->process_vaulted_order( 'card', $user_id, $tokens );

		$this->assertSame( 'PP-CUSTOMER-1', get_user_meta( $user_id, '_ppcp_target_customer_id', true ) );
	}

	/**
	 * The control for the card case: the same harness does create a token for a PayPal vault result.
	 *
	 * @testdox Should create a PayPal token with the buyer email for a paypal vault result (wallet).
	 */
	public function test_paypal_vault_result_creates_token_after_order_processing(): void {
		$user_id = self::factory()->user->create();
		$tokens  = $this->mock( WooCommercePaymentTokens::class );
		$tokens->shouldReceive( 'create_payment_token_paypal' )->once()->with( $user_id, 'VAULT-TOKEN-1', 'buyer@example.test' )->andReturn( 1 );

		$this->process_vaulted_order( 'paypal', $user_id, $tokens );
	}
}
