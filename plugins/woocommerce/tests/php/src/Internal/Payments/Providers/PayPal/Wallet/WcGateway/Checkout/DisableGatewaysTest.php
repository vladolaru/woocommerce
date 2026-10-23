<?php
/**
 * Tests for the gateway disabler.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout\DisableGateways;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * Which gateways the checkout keeps, with the gateway list stubbed out of the handler's way: the subscription, location
 * and PayPal continuation rules are isolated by giving every other collaborator its "do nothing" answer.
 *
 * @group paypal-wallet
 */
class DisableGatewaysTest extends WalletTestCase {

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * The button context mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * The payment gateways object WooCommerce had before the test.
	 *
	 * @var mixed
	 */
	private $original_payment_gateways;

	/**
	 * Hide WooCommerce's gateway list from the handler and build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_payment_gateways = WC()->payment_gateways;
		WC()->payment_gateways           = null;

		$this->settings_provider = $this->mock( SettingsProvider::class );

		$this->settings_status = $this->mock( SettingsStatus::class );
		$this->settings_status->allows( 'is_smart_button_enabled_for_location' )->andReturn( true );

		$this->subscription_helper = $this->mock( SubscriptionHelper::class );
		$this->subscription_helper->allows( 'cart_contains_subscription' )->andReturn( false );

		$this->context = $this->mock( Context::class );
		$this->context->allows( 'is_paypal_continuation' )->andReturn( false );
	}

	/**
	 * Give WooCommerce its gateway list back.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways = $this->original_payment_gateways;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should hide the PayPal gateway when the subscription cart cannot be processed.
	 */
	public function test_handler_hides_paypal_gateway_when_subscription_cart_not_processable(): void {
		$this->subscription_helper->allows( 'subscription_cart_processable' )
			->with( $this->settings_provider )
			->andReturn( false );

		$methods = $this->create_handler()->handler( array( PayPalGateway::ID => 'paypal-gateway' ) );

		$this->assertArrayNotHasKey( PayPalGateway::ID, $methods, 'A cart PayPal cannot process must not show a disabled button' );
	}

	/**
	 * @testdox Should keep the PayPal gateway when the cart's subscription can be processed.
	 */
	public function test_handler_keeps_paypal_gateway_when_subscription_cart_is_processable(): void {
		$this->subscription_helper->allows( 'subscription_cart_processable' )
			->with( $this->settings_provider )
			->andReturn( true );

		$methods = $this->create_handler()->handler( array( PayPalGateway::ID => 'paypal-gateway' ) );

		$this->assertArrayHasKey( PayPalGateway::ID, $methods );
	}

	/**
	 * @testdox Should leave an unrelated gateway list alone when PayPal is not offered.
	 */
	public function test_handler_returns_other_gateways_untouched_without_paypal(): void {
		$methods = $this->create_handler()->handler( array( 'bacs' => 'bacs-gateway' ) );

		$this->assertSame( array( 'bacs' => 'bacs-gateway' ), $methods );
	}

	/**
	 * @testdox Should hide the PayPal gateway for a subscription cart when the checkout button location is off.
	 */
	public function test_handler_hides_paypal_gateway_for_subscription_cart_without_checkout_buttons(): void {
		$this->settings_status = $this->mock( SettingsStatus::class );
		$this->settings_status->allows( 'is_smart_button_enabled_for_location' )->with( 'checkout' )->andReturn( false );
		$this->subscription_helper = $this->mock( SubscriptionHelper::class );
		$this->subscription_helper->allows( 'cart_contains_subscription' )->andReturn( true );
		$this->subscription_helper->allows( 'subscription_cart_processable' )->andReturn( true );

		$methods = $this->create_handler()->handler(
			array(
				PayPalGateway::ID => 'paypal-gateway',
				'bacs'            => 'bacs-gateway',
			)
		);

		$this->assertSame( array( 'bacs' => 'bacs-gateway' ), $methods );
	}

	/**
	 * @testdox Should offer PayPal alone while the checkout continues a PayPal session.
	 */
	public function test_handler_offers_only_paypal_during_continuation(): void {
		$this->context = $this->mock( Context::class );
		$this->context->allows( 'is_paypal_continuation' )->andReturn( true );
		$this->subscription_helper->allows( 'subscription_cart_processable' )->andReturn( true );

		$methods = $this->create_handler()->handler(
			array(
				PayPalGateway::ID => 'paypal-gateway',
				'bacs'            => 'bacs-gateway',
			)
		);

		$this->assertSame( array( PayPalGateway::ID => 'paypal-gateway' ), $methods );
	}

	/**
	 * Build the system under test.
	 *
	 * @return DisableGateways
	 */
	private function create_handler(): DisableGateways {
		return new DisableGateways(
			$this->settings_provider,
			$this->settings_status,
			$this->subscription_helper,
			$this->context
		);
	}
}
