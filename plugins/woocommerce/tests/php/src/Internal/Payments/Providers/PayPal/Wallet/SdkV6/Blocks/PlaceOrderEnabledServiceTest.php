<?php
/**
 * Tests for the service that decides whether the block checkout offers the standard PayPal row.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The 'sdk-v6.blocks.place-order-enabled' service, resolved from the real SdkV6 services file against a container
 * that serves the given configuration and sane defaults.
 *
 * V6PaymentMethod exposes the resolved bool as the "place_order_enabled" entry of its payment method data, which
 * decides whether the v6 block checkout offers the non-express PayPal row (the standard "Place order" button).
 *
 * @group paypal-wallet
 */
class PlaceOrderEnabledServiceTest extends WalletTestCase {

	private const FILTER = 'woocommerce_paypal_payments_blocks_add_place_order_method';

	/**
	 * Resolve the service callable from the real services file.
	 *
	 * @param array              $config              Container values that override the defaults.
	 * @param SettingsProvider   $settings_provider   The settings provider.
	 * @param SubscriptionHelper $subscription_helper The subscription helper.
	 * @return callable
	 */
	private function resolve_service( array $config, SettingsProvider $settings_provider, SubscriptionHelper $subscription_helper ): callable {
		$values = array_merge(
			array(
				'settings.settings-provider' => $settings_provider,
				'wc-subscriptions.helper'    => $subscription_helper,
				'button.client_id'           => '',
			),
			$config
		);

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $key ) use ( $values ) {
				return $values[ $key ];
			}
		);

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SdkV6/services.php';

		return $services['sdk-v6.blocks.place-order-enabled']( $container );
	}

	/**
	 * A settings provider that can or cannot vault PayPal and Venmo.
	 *
	 * @param bool $can_save Whether the "save PayPal and Venmo" setting is on.
	 * @return SettingsProvider
	 */
	private function settings_provider_that_can_vault( bool $can_save ): SettingsProvider {
		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'save_paypal_and_venmo' )->andReturn( $can_save );

		return $settings_provider;
	}

	/**
	 * A subscription helper that reports a fixed cart.
	 *
	 * @param bool $has_subscription Whether the cart contains a subscription.
	 * @return SubscriptionHelper
	 */
	private function subscription_helper_with_cart( bool $has_subscription ): SubscriptionHelper {
		$subscription_helper = $this->mock( SubscriptionHelper::class );
		$subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( $has_subscription );

		return $subscription_helper;
	}

	/**
	 * @testdox Should offer the place order row for a non-subscription cart when no callback is hooked and offer the filter its default of true.
	 */
	public function test_happy_path_offers_the_place_order_row(): void {
		$calls = $this->spy_filter( self::FILTER );

		$place_order_enabled = $this->resolve_service( array(), $this->settings_provider_that_can_vault( false ), $this->subscription_helper_with_cart( false ) );

		$this->assertTrue( $place_order_enabled() );
		$this->assertCount( 1, $calls, 'The filter runs once' );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default' );
	}

	/**
	 * @testdox Should disable the row when a callback switches the filter off.
	 */
	public function test_filter_callback_disables_the_row(): void {
		add_filter( self::FILTER, '__return_false' );

		$place_order_enabled = $this->resolve_service( array(), $this->settings_provider_that_can_vault( false ), $this->subscription_helper_with_cart( false ) );

		$this->assertFalse( $place_order_enabled() );
	}

	/**
	 * @testdox Should honour a callback registered after the provider was resolved, because the filter is read when the closure is called.
	 */
	public function test_filter_callback_registered_after_resolution_is_still_honoured(): void {
		$place_order_enabled = $this->resolve_service( array(), $this->settings_provider_that_can_vault( false ), $this->subscription_helper_with_cart( false ) );

		add_filter( self::FILTER, '__return_false' );

		$this->assertFalse( $place_order_enabled() );
	}

	/**
	 * @testdox Should enable the row for a subscription cart only when the renewals can be vaulted: $save_paypal_and_venmo and client ID "$client_id" give $expected_enabled.
	 * @dataProvider vaulting_capability_provider
	 *
	 * @param bool   $save_paypal_and_venmo Whether the "save PayPal and Venmo" setting is on.
	 * @param string $client_id             The configured client ID.
	 * @param bool   $expected_enabled      Whether the row is offered.
	 */
	public function test_subscription_cart_only_enabled_when_renewals_can_be_vaulted( bool $save_paypal_and_venmo, string $client_id, bool $expected_enabled ): void {
		$calls               = $this->spy_filter( self::FILTER );
		$place_order_enabled = $this->resolve_service(
			array( 'button.client_id' => $client_id ),
			$this->settings_provider_that_can_vault( $save_paypal_and_venmo ),
			$this->subscription_helper_with_cart( true )
		);

		$this->assertSame( $expected_enabled, $place_order_enabled() );
		$this->assertCount( 1, $calls, 'The filter runs once' );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default' );
	}

	/**
	 * Vaulting capabilities of a subscription cart.
	 *
	 * @return array
	 */
	public function vaulting_capability_provider(): array {
		return array(
			'cannot vault: neither setting nor client id' => array( false, '', false ),
			'cannot vault: setting on but no client id'   => array( true, '', false ),
			'cannot vault: client id set but setting off' => array( false, 'client-123', false ),
			'can vault: both setting and client id present' => array( true, 'client-123', true ),
		);
	}

	/**
	 * @testdox Should enable the row for a cart without a subscription even when the merchant cannot vault, because the vaulting check only applies to subscription carts.
	 */
	public function test_non_subscription_cart_is_unaffected_by_vaulting_conditions(): void {
		$calls               = $this->spy_filter( self::FILTER );
		$place_order_enabled = $this->resolve_service(
			array( 'button.client_id' => '' ),
			$this->settings_provider_that_can_vault( false ),
			$this->subscription_helper_with_cart( false )
		);

		$this->assertTrue( $place_order_enabled() );
		$this->assertCount( 1, $calls, 'The filter runs once' );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default' );
	}

	/**
	 * @testdox Should read the cart again on every call, so the same provider flips from enabled to disabled when the cart gains a subscription.
	 */
	public function test_re_evaluates_cart_state_on_each_invocation(): void {
		$subscription_helper = $this->mock( SubscriptionHelper::class );
		$subscription_helper->shouldReceive( 'cart_contains_subscription' )->twice()->andReturn( false, true );
		$calls = $this->spy_filter( self::FILTER );

		$place_order_enabled = $this->resolve_service(
			array( 'button.client_id' => '' ),
			$this->settings_provider_that_can_vault( false ),
			$subscription_helper
		);

		$this->assertTrue( $place_order_enabled(), 'Without a subscription the row is offered' );
		$this->assertFalse( $place_order_enabled(), 'With a subscription and no vaulting the row is not offered' );
		$this->assertCount( 2, $calls, 'The filter runs on every call' );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default on the first call' );
		$this->assertTrue( $calls[1][0], 'The filter receives true as its default on the second call' );
	}
}
