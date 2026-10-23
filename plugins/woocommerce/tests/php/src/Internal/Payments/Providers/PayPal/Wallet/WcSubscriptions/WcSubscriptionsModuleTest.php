<?php
/**
 * Tests for the WooCommerce Subscriptions module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\WcSubscriptionsModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery;
use ReflectionClass;

/**
 * The module's private mode lookup delegates to the helper and adds nothing of its own; the gateway only advertises
 * the subscription features when the mode lets PayPal vault, and the payment-complete listener leaves a subscription
 * that PayPal bills itself alone.
 *
 * @group paypal-wallet
 */
class WcSubscriptionsModuleTest extends WalletTestCase {

	/**
	 * Resolve the mode through the module's private method.
	 *
	 * @param SettingsProvider   $settings_provider   The settings provider.
	 * @param SubscriptionHelper $subscription_helper The subscription helper.
	 * @return string
	 */
	private function invoke_get_subscriptions_mode( SettingsProvider $settings_provider, SubscriptionHelper $subscription_helper ): string {
		$module = new WcSubscriptionsModule();
		$method = ( new ReflectionClass( $module ) )->getMethod( 'get_subscriptions_mode' );
		$method->setAccessible( true );

		return $method->invoke( $module, $settings_provider, $subscription_helper );
	}

	/**
	 * Assert that the module returns exactly the mode the helper resolves, asking it once with the same provider.
	 *
	 * @param string $resolved_mode The mode the helper resolves.
	 */
	private function assert_delegates_to_helper( string $resolved_mode ): void {
		$settings_provider = $this->mock( SettingsProvider::class );

		$subscription_helper = $this->mock( SubscriptionHelper::class );
		$subscription_helper->expects( 'resolve_subscription_mode' )
			->once()
			->with( $settings_provider )
			->andReturn( $resolved_mode );

		$this->assertSame( $resolved_mode, $this->invoke_get_subscriptions_mode( $settings_provider, $subscription_helper ) );
	}

	/**
	 * @testdox Should return the $mode mode exactly as the helper resolves it, asking once with the same settings provider.
	 *
	 * @testWith ["disable_paypal_subscriptions"]
	 *           ["vaulting_api"]
	 *
	 * @param string $mode The mode the helper resolves.
	 */
	public function test_get_subscriptions_mode_delegates_to_subscription_helper( string $mode ): void {
		$this->assert_delegates_to_helper( $mode );
	}

	/**
	 * Run the module on a container whose helper reports WooCommerce Subscriptions active, with the real mode rules.
	 *
	 * @param bool $vaulting Whether "Save PayPal and Venmo" is on.
	 */
	private function run_module( bool $vaulting ): void {
		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->allows( 'save_paypal_and_venmo' )->andReturn( $vaulting );

		$subscription_helper = Mockery::mock( SubscriptionHelper::class )->makePartial();
		$subscription_helper->allows( 'plugin_is_active' )->andReturn( true );
		$subscription_helper->allows( 'accept_manual_renewals' )->andReturn( false );

		$services  = array(
			'wc-subscriptions.helper'    => $subscription_helper,
			'settings.settings-provider' => $settings_provider,
		);
		$container = $this->mock( ContainerInterface::class );
		$container->allows( 'get' )->andReturnUsing(
			static function ( string $id ) use ( $services ) {
				return $services[ $id ];
			}
		);

		( new WcSubscriptionsModule() )->run( $container );
	}

	/**
	 * @testdox Should add the subscription features to the PayPal gateway when "Save PayPal and Venmo" is on.
	 */
	public function test_gateway_supports_the_subscription_features_when_vaulting_is_on(): void {
		$this->run_module( true );

		$supports = apply_filters( 'woocommerce_paypal_payments_paypal_gateway_supports', array( 'products' ) );

		$this->assertSame( 'products', $supports[0], 'The features already there stay first.' );
		$this->assertContains( 'subscriptions', $supports );
		$this->assertContains( 'subscription_payment_method_change', $supports );
		$this->assertContains( 'multiple_subscriptions', $supports );
	}

	/**
	 * @testdox Should leave the PayPal gateway features alone when "Save PayPal and Venmo" is off.
	 */
	public function test_gateway_supports_no_subscription_feature_when_vaulting_is_off(): void {
		$this->run_module( false );

		$supports = apply_filters( 'woocommerce_paypal_payments_paypal_gateway_supports', array( 'products' ) );

		$this->assertSame( array( 'products' ), $supports );
	}

	/**
	 * @testdox Should leave the PayPal gateway features alone when the subscription mode filter disables PayPal for subscriptions.
	 */
	public function test_gateway_supports_no_subscription_feature_when_the_mode_filter_disables_it(): void {
		$this->run_module( true );
		add_filter( 'woocommerce_paypal_payments_subscription_mode_disabled', '__return_true' );

		try {
			$supports = apply_filters( 'woocommerce_paypal_payments_paypal_gateway_supports', array( 'products' ) );
		} finally {
			remove_filter( 'woocommerce_paypal_payments_subscription_mode_disabled', '__return_true' );
		}

		$this->assertSame( array( 'products' ), $supports );
	}

	/**
	 * A subscription double with the methods the payment-complete listener calls.
	 *
	 * @param string $paypal_subscription_id The `ppcp_subscription` meta the subscription carries.
	 * @return \Mockery\MockInterface
	 */
	private function paid_subscription( string $paypal_subscription_id ) {
		$subscription = Mockery::mock();
		$subscription->allows( 'get_payment_method' )->andReturn( PayPalGateway::ID );
		$subscription->allows( 'get_meta' )->with( 'ppcp_subscription' )->andReturn( $paypal_subscription_id );

		return $subscription;
	}

	/**
	 * @testdox Should leave a subscription that carries a PayPal subscription ID alone when its payment completes.
	 */
	public function test_payment_complete_listener_skips_a_subscription_billed_by_paypal(): void {
		$this->run_module( true );
		$subscription = $this->paid_subscription( 'I-123' );
		$subscription->expects( 'get_related_orders' )->never();

		do_action( 'woocommerce_subscription_payment_complete', $subscription );
	}

	/**
	 * @testdox Should look at the related orders of a subscription without a PayPal subscription ID when its payment completes.
	 */
	public function test_payment_complete_listener_handles_a_subscription_without_a_paypal_subscription_id(): void {
		$this->run_module( true );
		$subscription = $this->paid_subscription( '' );
		$subscription->expects( 'get_related_orders' )->once()->andReturn( array( 1, 2 ) );

		do_action( 'woocommerce_subscription_payment_complete', $subscription );
	}
}
