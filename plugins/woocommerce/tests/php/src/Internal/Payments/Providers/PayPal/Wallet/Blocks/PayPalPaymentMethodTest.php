<?php
/**
 * Tests for the PayPal payment method of the block checkout.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\PayPalPaymentMethod;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\SmartButtonInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\UpdateShippingEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelView;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The data the block checkout gets for the PayPal payment method: whether the standard "Place order" row is offered, the
 * features the gateway supports, and the shipping update endpoint with a nonce that WordPress accepts.
 *
 * @group paypal-wallet
 */
class PayPalPaymentMethodTest extends WalletTestCase {

	/**
	 * The asset getter mock.
	 *
	 * @var AssetGetter&MockInterface
	 */
	private $asset_getter;

	/**
	 * The smart button mock.
	 *
	 * @var SmartButtonInterface&MockInterface
	 */
	private $smart_button;

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $plugin_settings;

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * The gateway mock.
	 *
	 * @var PayPalGateway&MockInterface
	 */
	private $gateway;

	/**
	 * The cancellation view mock.
	 *
	 * @var CancelView&MockInterface
	 */
	private $cancellation_view;

	/**
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * Build the collaborators and an empty cart.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->asset_getter        = $this->mock( AssetGetter::class );
		$this->smart_button        = $this->mock( SmartButtonInterface::class );
		$this->plugin_settings     = $this->mock( SettingsProvider::class );
		$this->settings_status     = $this->mock( SettingsStatus::class );
		$this->gateway             = $this->mock( PayPalGateway::class );
		$this->cancellation_view   = $this->mock( CancelView::class );
		$this->session_handler     = $this->mock( SessionHandler::class );
		$this->subscription_helper = $this->mock( SubscriptionHelper::class );

		$this->gateway->id       = PayPalGateway::ID;
		$this->gateway->title    = 'PayPal';
		$this->gateway->icon     = 'https://example.test/paypal.svg';
		$this->gateway->supports = array( 'products' );
		$this->gateway->shouldReceive( 'get_description' )->andReturn( 'Pay with PayPal' );

		$this->session_handler->shouldReceive( 'funding_source' )->andReturn( 'paypal' );

		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	/**
	 * Build the payment method over the mocks.
	 *
	 * @param array $script_data            The script data of the v5 smart button, empty under SDK v6.
	 * @param bool  $add_place_order_method Whether the non-express method is added.
	 * @param bool  $use_place_order        Whether the standard "Place order" button replaces the PayPal buttons.
	 * @return PayPalPaymentMethod
	 */
	private function create_testee( array $script_data, bool $add_place_order_method, bool $use_place_order ): PayPalPaymentMethod {
		$this->smart_button->shouldReceive( 'script_data' )->andReturn( $script_data );

		return new PayPalPaymentMethod(
			$this->asset_getter,
			'1.0.0',
			$this->smart_button,
			$this->plugin_settings,
			$this->settings_status,
			$this->gateway,
			false,
			$this->cancellation_view,
			$this->session_handler,
			$this->subscription_helper,
			$add_place_order_method,
			$use_place_order,
			array()
		);
	}

	/**
	 * Say what the cart and the settings allow for a subscription.
	 *
	 * @param bool $cart_contains_subscription Whether the cart contains a subscription.
	 * @param bool $can_save_vault_token       Whether a vault token can be saved.
	 * @param bool $accept_manual_renewals     Whether manual renewals are accepted.
	 */
	private function stub_subscription_state( bool $cart_contains_subscription, bool $can_save_vault_token, bool $accept_manual_renewals ): void {
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( $cart_contains_subscription );
		$this->subscription_helper->shouldReceive( 'accept_manual_renewals' )->andReturn( $accept_manual_renewals );
		$this->plugin_settings->shouldReceive( 'can_save_vault_token' )->andReturn( $can_save_vault_token );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( false );
	}

	/**
	 * @testdox Should enable the standard "Place order" row only when a vault token can be saved or manual renewals are accepted for a subscription: $label.
	 * @dataProvider place_order_enabled_provider
	 *
	 * @param string $label                         What the case is.
	 * @param array  $script_data                   The script data of the v5 smart button.
	 * @param bool   $cart_contains_subscription    Whether the cart contains a subscription.
	 * @param bool   $can_save_vault_token          Whether a vault token can be saved.
	 * @param bool   $accept_manual_renewals        Whether manual renewals are accepted.
	 * @param bool   $add_place_order_method        Whether the non-express method is added.
	 * @param bool   $use_place_order               Whether the standard button replaces the PayPal buttons.
	 * @param bool   $expected                      Whether the row is enabled.
	 * @param bool   $assert_smart_buttons_disabled Whether the smart buttons must be off, as under SDK v6.
	 */
	public function test_place_order_enabled(
		string $label,
		array $script_data,
		bool $cart_contains_subscription,
		bool $can_save_vault_token,
		bool $accept_manual_renewals,
		bool $add_place_order_method,
		bool $use_place_order,
		bool $expected,
		bool $assert_smart_buttons_disabled
	): void {
		unset( $label );
		$this->stub_subscription_state( $cart_contains_subscription, $can_save_vault_token, $accept_manual_renewals );

		$data = $this->create_testee( $script_data, $add_place_order_method, $use_place_order )->get_payment_method_data();

		$this->assertSame( $expected, $data['placeOrderEnabled'] );

		if ( $assert_smart_buttons_disabled ) {
			$this->assertFalse( $data['smartButtonsEnabled'] );
		}
	}

	/**
	 * Carts, vaulting capabilities and SDK versions, with the state of the row. Under SDK v6 the v5 script data is empty,
	 * so the smart buttons of the row stay off.
	 *
	 * @return array
	 */
	public function place_order_enabled_provider(): array {
		return array(
			'sdk v6, subscription cart, vaulting on, automatic renewals' => array( 'sdk v6, subscription cart, vaulting on, automatic renewals', array(), true, true, false, true, false, true, true ),
			'sdk v6, subscription cart, vaulting off, automatic renewals' => array( 'sdk v6, subscription cart, vaulting off, automatic renewals', array(), true, false, false, true, false, false, true ),
			'sdk v6, subscription cart, vaulting off, manual renewals accepted' => array( 'sdk v6, subscription cart, vaulting off, manual renewals accepted', array(), true, false, true, true, false, true, true ),
			'sdk v6, no subscription in cart'             => array( 'sdk v6, no subscription in cart', array(), false, false, false, true, false, true, true ),
			'sdk v5, subscription cart, vaulting on'      => array(
				'sdk v5, subscription cart, vaulting on',
				array(
					'context'              => 'checkout-block',
					'can_save_vault_token' => true,
				),
				true,
				true,
				false,
				true,
				false,
				true,
				false,
			),
			'place-order method filtered off'             => array( 'place-order method filtered off', array(), false, false, false, false, false, false, true ),
			'standard button replaces the PayPal buttons' => array( 'the standard button replaces the PayPal buttons', array(), false, false, false, false, true, true, true ),
		);
	}

	/**
	 * @testdox Should keep the "Place order" row for a free trial subscription cart that can be vaulted, because a first-time buyer is handled by the vault approval redirect of the gateway.
	 */
	public function test_place_order_enabled_for_free_trial_cart(): void {
		$this->stub_subscription_state( true, true, false );

		$data = $this->create_testee( array(), true, false )->get_payment_method_data();

		$this->assertTrue( $data['placeOrderEnabled'] );
	}

	/**
	 * @testdox Should declare to WooCommerce Blocks exactly the features the gateway supports.
	 */
	public function test_supported_features_come_from_the_gateway(): void {
		$this->gateway->supports = array( 'products', 'subscriptions', 'tokenization' );
		$this->stub_subscription_state( false, false, false );

		$data = $this->create_testee( array(), true, false )->get_payment_method_data();

		$this->assertSame( array( 'products', 'subscriptions', 'tokenization' ), $data['supportedFeatures'] );
	}

	/**
	 * @testdox Should hand the block the shipping update endpoint with a nonce WordPress accepts for that endpoint, and no need for shipping for an empty cart.
	 */
	public function test_hands_the_block_a_verifiable_shipping_update_nonce(): void {
		$this->stub_subscription_state( false, false, false );

		$data = $this->create_testee( array(), true, false )->get_payment_method_data();

		$this->assertStringContainsString( 'wc-ajax=' . UpdateShippingEndpoint::ENDPOINT, $data['ajax']['update_shipping']['endpoint'] );
		$this->assertNotFalse( wp_verify_nonce( $data['ajax']['update_shipping']['nonce'], UpdateShippingEndpoint::nonce() ) );
		$this->assertFalse( $data['needShipping'] );
	}

	/**
	 * @testdox Should describe the gateway to the block, and let the data filter change it.
	 */
	public function test_describes_the_gateway_and_applies_the_data_filter(): void {
		$this->stub_subscription_state( false, false, false );
		add_filter(
			'woocommerce_paypal_payments_blocks_payment_method_data',
			static function ( $data ) {
				$data['placeOrderButtonLabel'] = 'Pay now';
				return $data;
			}
		);

		$data = $this->create_testee( array(), true, false )->get_payment_method_data();

		$this->assertSame( PayPalGateway::ID, $data['id'] );
		$this->assertSame( 'PayPal', $data['title'] );
		$this->assertSame( 'Pay with PayPal', $data['description'] );
		$this->assertSame( 'paypal', $data['fundingSource'] );
		$this->assertSame( 'Pay now', $data['placeOrderButtonLabel'] );
	}
}
