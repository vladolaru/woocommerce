<?php
/**
 * Tests for the WooCommerce Subscriptions helper (ported from the extension's SubscriptionHelperTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery;
use Mockery\MockInterface;
use WC_Helper_Product;

/**
 * Real WordPress state (product page, order-pay endpoint, filters) with a Mockery partial of
 * the helper where a test fixes the signals the method under test reads from its own class. WooCommerce Subscriptions
 * is not loaded in core's suite, so `plugin_is_active()` is really false here.
 *
 * @group paypal-wallet
 */
class SubscriptionHelperTest extends WalletTestCase {

	/**
	 * The query variables WordPress had before the test.
	 *
	 * @var mixed
	 */
	private $original_query_vars;

	/**
	 * Remember the query variables.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_query_vars = $GLOBALS['wp']->query_vars ?? null;
	}

	/**
	 * Give the query variables back and leave the product page.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wp']->query_vars = $this->original_query_vars;
			$this->go_to( home_url( '/' ) );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A partial mock of the helper with the given methods returning fixed values.
	 *
	 * @param array<string, mixed> $signals Method name => return value.
	 * @return SubscriptionHelper&MockInterface
	 */
	private function partial_helper( array $signals = array() ) {
		$helper = Mockery::mock( SubscriptionHelper::class )->makePartial();
		foreach ( $signals as $method => $value ) {
			$helper->shouldReceive( $method )->andReturn( $value );
		}

		return $helper;
	}

	/**
	 * A settings provider mock.
	 *
	 * @param bool   $save_paypal_and_venmo Whether "Save PayPal and Venmo" is on.
	 * @param string $client_id             The merchant client ID ('' for a merchant who is not connected).
	 * @return SettingsProvider&MockInterface
	 */
	private function settings_provider( bool $save_paypal_and_venmo, string $client_id = '' ) {
		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->allows( 'save_paypal_and_venmo' )->andReturn( $save_paypal_and_venmo );
		$settings_provider->allows( 'merchant_data' )->andReturn( new MerchantConnectionDTO( false, $client_id, '', '' ) );

		return $settings_provider;
	}

	/**
	 * @testdox Should report no renewal in the cart when WooCommerce Subscriptions is not active (wallet).
	 */
	public function test_cart_contains_renewal_returns_false_when_subscriptions_plugin_not_active(): void {
		$this->assertFalse( ( new SubscriptionHelper() )->cart_contains_renewal() );
	}

	/**
	 * @testdox Should list no subscription location when nothing on the page is a subscription (wallet).
	 */
	public function test_locations_with_subscription_product_when_nothing_is_present(): void {
		$helper = $this->partial_helper(
			array(
				'current_product_is_subscription' => false,
				'order_pay_contains_subscription' => false,
				'cart_contains_subscription'      => false,
				'cart_contains_renewal'           => false,
			)
		);

		$this->assertSame(
			array(
				'product'  => false,
				'payorder' => false,
				'cart'     => false,
			),
			$helper->locations_with_subscription_product()
		);
	}

	/**
	 * @testdox Should list the product location on a subscription product page (wallet).
	 */
	public function test_locations_with_subscription_product_on_product_page(): void {
		$product = WC_Helper_Product::create_simple_product();
		$this->go_to( get_permalink( $product->get_id() ) );

		$helper = $this->partial_helper(
			array(
				'current_product_is_subscription' => true,
				'order_pay_contains_subscription' => false,
				'cart_contains_subscription'      => false,
				'cart_contains_renewal'           => false,
			)
		);

		$this->assertSame(
			array(
				'product'  => true,
				'payorder' => false,
				'cart'     => false,
			),
			$helper->locations_with_subscription_product()
		);
	}

	/**
	 * @testdox Should list the pay order location on the classic order-pay endpoint (wallet).
	 */
	public function test_locations_with_subscription_product_on_classic_order_pay_endpoint(): void {
		$GLOBALS['wp']->query_vars['order-pay'] = 123;

		$helper = $this->partial_helper(
			array(
				'current_product_is_subscription' => false,
				'order_pay_contains_subscription' => true,
				'cart_contains_subscription'      => false,
				'cart_contains_renewal'           => false,
			)
		);

		$this->assertSame(
			array(
				'product'  => false,
				'payorder' => true,
				'cart'     => false,
			),
			$helper->locations_with_subscription_product()
		);
	}

	/**
	 * Regression for PCP-2649: WooCommerce Subscriptions can route a manual renewal through the cart or the Checkout
	 * block instead of the classic order-pay endpoint, so a cart-based renewal is "payorder", not "cart".
	 *
	 * @testdox Should classify a renewal in the cart as pay order and not as cart (wallet).
	 */
	public function test_locations_with_subscription_product_when_cart_contains_renewal(): void {
		$helper = $this->partial_helper(
			array(
				'current_product_is_subscription' => false,
				'order_pay_contains_subscription' => false,
				'cart_contains_subscription'      => true,
				'cart_contains_renewal'           => true,
			)
		);

		$this->assertSame(
			array(
				'product'  => false,
				'payorder' => true,
				'cart'     => false,
			),
			$helper->locations_with_subscription_product()
		);
	}

	/**
	 * @testdox Should classify a new subscription in the cart as cart (wallet).
	 */
	public function test_locations_with_subscription_product_when_cart_contains_new_subscription(): void {
		$helper = $this->partial_helper(
			array(
				'current_product_is_subscription' => false,
				'order_pay_contains_subscription' => false,
				'cart_contains_subscription'      => true,
				'cart_contains_renewal'           => false,
			)
		);

		$this->assertSame(
			array(
				'product'  => false,
				'payorder' => false,
				'cart'     => true,
			),
			$helper->locations_with_subscription_product()
		);
	}

	/**
	 * @testdox Should resolve no subscriptions mode when WooCommerce Subscriptions is not active (wallet).
	 */
	public function test_resolve_subscription_mode_returns_empty_string_when_plugin_not_active(): void {
		$helper = $this->partial_helper( array( 'plugin_is_active' => false ) );

		$this->assertSame( '', $helper->resolve_subscription_mode( $this->settings_provider( true ) ) );
	}

	/**
	 * @testdox Should resolve the disabled mode when the mode-disabled filter forces it, whatever the vaulting setting (wallet).
	 */
	public function test_resolve_subscription_mode_returns_disabled_when_forced_by_filter(): void {
		$seen = $this->spy_filter( 'woocommerce_paypal_payments_subscription_mode_disabled', true );

		$helper = $this->partial_helper( array( 'plugin_is_active' => true ) );

		$this->assertSame(
			SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_DISABLED,
			$helper->resolve_subscription_mode( $this->settings_provider( true ) )
		);
		$this->assertCount( 1, $seen, 'The filter is applied once' );
		$this->assertFalse( $seen[0][0], 'The filter starts from false' );
	}

	/**
	 * @testdox Should resolve the mode from the vaulting setting: $name (wallet).
	 *
	 * @dataProvider wallet_subscription_mode_provider
	 *
	 * @param string $name                  Case name.
	 * @param bool   $save_paypal_and_venmo Whether vaulting is on.
	 * @param string $expected_mode         The expected mode.
	 */
	public function test_resolve_subscription_mode_decides_between_disabled_and_vaulting( string $name, bool $save_paypal_and_venmo, string $expected_mode ): void {
		unset( $name );

		$helper = $this->partial_helper( array( 'plugin_is_active' => true ) );

		$this->assertSame( $expected_mode, $helper->resolve_subscription_mode( $this->settings_provider( $save_paypal_and_venmo ) ) );
	}

	/**
	 * The mode rows: vaulting decides.
	 *
	 * @return array<string, array{string, bool, string}>
	 */
	public function wallet_subscription_mode_provider(): array {
		return array(
			'vaulting disabled resolves to the disabled mode' => array( 'vaulting disabled', false, SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_DISABLED ),
			'vaulting enabled resolves to the vaulting API'   => array( 'vaulting enabled', true, SubscriptionHelper::SUBSCRIPTION_MODE_VALUE_VAULTING ),
		);
	}

	/**
	 * @testdox Should treat a cart without a subscription as processable without checking the button (wallet).
	 */
	public function test_subscription_cart_processable_returns_true_when_cart_has_no_subscription(): void {
		$helper = $this->partial_helper( array( 'cart_contains_subscription' => false ) );
		$helper->shouldNotReceive( 'paypal_subscription_button_allowed' );

		$this->assertTrue( $helper->subscription_cart_processable( $this->settings_provider( true ) ) );
	}

	/**
	 * A vault token can only be saved with both a connected merchant and "Save PayPal and Venmo".
	 *
	 * @testdox Should decide a subscription cart from the vault token signal: $name (wallet).
	 *
	 * @dataProvider wallet_wiring_provider
	 *
	 * @param string $name                         Case name.
	 * @param bool   $save_paypal_and_venmo        Whether vaulting is on.
	 * @param string $client_id                    The merchant client ID.
	 * @param bool   $expected_can_save_vault_token Whether a vault token can be saved.
	 * @param bool   $button_allowed_result        What `paypal_subscription_button_allowed()` answers.
	 */
	public function test_subscription_cart_processable_wires_the_vault_token_signal( string $name, bool $save_paypal_and_venmo, string $client_id, bool $expected_can_save_vault_token, bool $button_allowed_result ): void {
		unset( $name );

		$settings_provider = $this->settings_provider( $save_paypal_and_venmo, $client_id );

		$helper = $this->partial_helper( array( 'cart_contains_subscription' => true ) );
		$helper->shouldReceive( 'paypal_subscription_button_allowed' )
			->with( $expected_can_save_vault_token )
			->andReturn( $button_allowed_result );

		$this->assertSame( $button_allowed_result, $helper->subscription_cart_processable( $settings_provider ) );
	}

	/**
	 * The vault token rows.
	 *
	 * @return array<string, array{string, bool, string, bool, bool}>
	 */
	public function wallet_wiring_provider(): array {
		return array(
			'vaulting enabled and a connected merchant'  => array( 'vaulting enabled and connected', true, 'client-id-123', true, true ),
			'manual-renewal-only subscription'           => array( 'manual renewals only', false, '', false, true ),
			'vaulting enabled but no connected merchant' => array( 'no connected merchant', true, '', false, false ),
		);
	}

	/**
	 * End to end: the button eligibility is not stubbed.
	 *
	 * @testdox Should hide the gateway for a subscription cart when vaulting is disabled and there are no manual renewals (wallet).
	 */
	public function test_subscription_cart_processable_end_to_end_hides_gateway_when_vaulting_disabled(): void {
		$helper = $this->partial_helper(
			array(
				'cart_contains_subscription' => true,
				'accept_manual_renewals'     => false,
			)
		);

		$this->assertFalse( $helper->subscription_cart_processable( $this->settings_provider( false ) ) );
	}

	/**
	 * The rule shared by the classic cart, block cart, mini-cart and the gateway filter.
	 *
	 * @testdox Should decide the button for a subscription cart from manual renewals and the vault token: $name (wallet).
	 *
	 * @dataProvider button_allowed_provider
	 *
	 * @param string $name                  Case name.
	 * @param bool   $cart_has_subscription Whether the cart holds a subscription.
	 * @param bool   $manual_renewals       Whether manual renewals are accepted.
	 * @param bool   $can_save_vault_token  Whether a vault token can be saved.
	 * @param bool   $expected              Whether the button is allowed.
	 */
	public function test_paypal_subscription_button_allowed_decides_from_renewals_and_vault_token( string $name, bool $cart_has_subscription, bool $manual_renewals, bool $can_save_vault_token, bool $expected ): void {
		unset( $name );

		$helper = $this->partial_helper(
			array(
				'cart_contains_subscription' => $cart_has_subscription,
				'accept_manual_renewals'     => $manual_renewals,
			)
		);

		$this->assertSame( $expected, $helper->paypal_subscription_button_allowed( $can_save_vault_token ) );
	}

	/**
	 * Cases for the button rule.
	 *
	 * @return array<string, array{string, bool, bool, bool, bool}>
	 */
	public function button_allowed_provider(): array {
		return array(
			'no subscription in the cart is always allowed' => array( 'no subscription', false, false, false, true ),
			'manual renewals are allowed without a vault token' => array( 'manual renewals', true, true, false, true ),
			'a savable vault token is allowed' => array( 'savable vault token', true, false, true, true ),
			'no manual renewals and no savable vault token is hidden' => array( 'nothing to renew with', true, false, false, false ),
		);
	}
}
