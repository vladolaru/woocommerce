<?php
/**
 * Tests for the mock PayPal Express Checkout gateway.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\MockGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\PPECHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The gateway that stands in for PayPal Express Checkout so its subscriptions can renew.
 *
 * The compat layer registers the gateway unconditionally (see SubscriptionsHandlerTest), so what keeps it off the
 * WooCommerce payments settings screen is that it presents itself as a shell gateway. WooCommerce recognises a shell
 * only when both the method title and the method description are empty, so setting either one, even by accident, would
 * silently put the gateway back on that screen.
 *
 * @group paypal-wallet
 */
class MockGatewayTest extends WalletTestCase {

	/**
	 * @testdox Should expose an empty method title and method description, so it stays off the settings screen.
	 */
	public function test_exposes_an_empty_method_title_and_method_description_to_stay_off_the_settings_screen(): void {
		$gateway = new MockGateway( 'PayPal (Legacy)' );

		$this->assertSame( '', $gateway->get_method_title() );
		$this->assertSame( '', $gateway->get_method_description() );
		$this->assertTrue(
			wc_get_container()->get( PaymentsProviders::class )->is_shell_payment_gateway( $gateway ),
			'WooCommerce must recognise the gateway as a shell and leave it out of the Payments settings screen'
		);
	}

	/**
	 * @testdox Should still expose the given title for the order and subscription screens.
	 */
	public function test_still_exposes_the_given_title_for_order_and_subscription_screens(): void {
		$gateway = new MockGateway( 'PayPal (Legacy)' );

		$this->assertSame( 'PayPal (Legacy)', $gateway->title );
		$this->assertSame( PPECHelper::PPEC_GATEWAY_ID, $gateway->id );
	}

	/**
	 * @testdox Should be available in the admin only, so it never shows at the checkout.
	 */
	public function test_is_available_in_the_admin_only(): void {
		$gateway = new MockGateway( 'PayPal (Legacy)' );

		$this->assertFalse( $gateway->is_available(), 'Not available on the front end' );

		$this->simulate_admin_request( array() );

		$this->assertTrue( $gateway->is_available(), 'Available in the admin, where subscriptions are managed' );
	}

	/**
	 * @testdox Should support the subscription features the renewals need.
	 */
	public function test_supports_subscriptions(): void {
		$gateway = new MockGateway( 'PayPal (Legacy)' );

		$this->assertTrue( $gateway->supports( 'subscriptions' ) );
		$this->assertTrue( $gateway->supports( 'subscription_payment_method_change_admin' ) );
		$this->assertFalse( $gateway->supports( 'refunds' ) );
	}
}
