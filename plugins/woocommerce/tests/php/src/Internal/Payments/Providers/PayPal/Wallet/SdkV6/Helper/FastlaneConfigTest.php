<?php
/**
 * Tests for the Fastlane page configuration (ported from the extension's FastlaneConfigTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\FastlaneConfig;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The gates in front of Fastlane rendering. Fastlane goes in task 8; the file stays until then.
 *
 * @group paypal-wallet
 */
class FastlaneConfigTest extends WalletTestCase {

	/**
	 * Build the system under test.
	 *
	 * @param SubscriptionHelper|null $subscription_helper The subscription helper, or one with no subscriptions.
	 * @param callable|null           $is_eligible         The eligibility callable, or an eligible one.
	 * @return FastlaneConfig
	 */
	private function create_config( ?SubscriptionHelper $subscription_helper = null, ?callable $is_eligible = null ): FastlaneConfig {
		return new FastlaneConfig(
			$subscription_helper ?? $this->no_subscriptions(),
			$is_eligible ?? static fn(): bool => true
		);
	}

	/**
	 * A helper for a cart without subscriptions.
	 *
	 * @return SubscriptionHelper
	 */
	private function no_subscriptions(): SubscriptionHelper {
		$helper = $this->mock( SubscriptionHelper::class );
		$helper->shouldReceive( 'cart_contains_subscription' )->andReturn( false );

		return $helper;
	}

	/**
	 * A helper for a cart with a subscription.
	 *
	 * @return SubscriptionHelper
	 */
	private function subscription_in_cart(): SubscriptionHelper {
		$helper = $this->mock( SubscriptionHelper::class );
		$helper->shouldReceive( 'cart_contains_subscription' )->andReturn( true );

		return $helper;
	}

	/**
	 * An eligibility callable that fails the test when it runs.
	 *
	 * @return callable
	 */
	private function fail_if_called(): callable {
		return function (): bool {
			$this->fail( 'The eligibility callable must not be invoked.' );

			return false;
		};
	}

	/**
	 * @testdox Should not render before wp_loaded has run, with a doing-it-wrong notice (fastlane).
	 */
	public function test_not_rendered_before_wp_loaded_has_run(): void {
		global $wp_actions;
		$original = $wp_actions['wp_loaded'] ?? 0;
		unset( $wp_actions['wp_loaded'] );
		$this->setExpectedIncorrectUsage( FastlaneConfig::class . '::should_render' );

		try {
			$config = $this->create_config( $this->no_subscriptions(), $this->fail_if_called() );

			$this->assertFalse( $config->should_render( 'checkout' ) );
		} finally {
			$wp_actions['wp_loaded'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the action count the guard test removed.
		}
	}

	/**
	 * @testdox Should render only on the checkout and checkout-block contexts: $context (fastlane).
	 * @dataProvider context_provider
	 *
	 * @param string $context  The page context.
	 * @param bool   $expected Whether Fastlane renders there.
	 */
	public function test_rendered_only_on_supported_contexts( string $context, bool $expected ): void {
		$this->assertSame( $expected, $this->create_config()->should_render( $context ) );
	}

	/**
	 * Contexts and whether Fastlane supports them.
	 *
	 * @return array
	 */
	public function context_provider(): array {
		return array(
			'classic checkout is supported' => array( 'checkout', true ),
			'checkout block is supported'   => array( 'checkout-block', true ),
			'product page is not supported' => array( 'product', false ),
			'cart page is not supported'    => array( 'cart', false ),
			'pay-now page is not supported' => array( 'pay-now', false ),
			'mini-cart is not supported'    => array( 'mini-cart', false ),
			'an unknown context is refused' => array( 'some-unknown-context', false ),
		);
	}

	/**
	 * @testdox Should not render for a logged-in buyer (fastlane).
	 */
	public function test_not_rendered_when_buyer_is_logged_in(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( $this->create_config()->should_render( 'checkout' ) );
	}

	/**
	 * @testdox Should not render when the cart holds a subscription (fastlane).
	 */
	public function test_not_rendered_when_cart_contains_subscription(): void {
		$config = $this->create_config( $this->subscription_in_cart(), $this->fail_if_called() );

		$this->assertFalse( $config->should_render( 'checkout' ) );
	}

	/**
	 * @testdox Should not render when the merchant is not eligible (fastlane).
	 */
	public function test_not_rendered_when_not_eligible(): void {
		$config = $this->create_config( $this->no_subscriptions(), static fn(): bool => false );

		$this->assertFalse( $config->should_render( 'checkout' ) );
	}

	/**
	 * @testdox Should render when nothing blocks it (fastlane).
	 */
	public function test_rendered_when_nothing_blocks_it(): void {
		$config = $this->create_config( $this->no_subscriptions(), static fn(): bool => true );

		$this->assertTrue( $config->should_render( 'checkout' ) );
	}

	/**
	 * @testdox Should not call the eligibility callable at construction (fastlane).
	 */
	public function test_construction_does_not_invoke_eligibility_callable(): void {
		$this->create_config( $this->no_subscriptions(), $this->fail_if_called() );

		$this->addToAssertionCount( 1 );
	}
}
