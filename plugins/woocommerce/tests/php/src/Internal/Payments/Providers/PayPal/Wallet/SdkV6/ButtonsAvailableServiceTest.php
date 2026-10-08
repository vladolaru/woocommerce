<?php
/**
 * Tests for the service that says whether the v6 buttons may show at all.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\AddPaymentMethodManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The 'sdk-v6.buttons-available' service, resolved from the real SdkV6 services file. It holds the two checks the v5
 * button factory makes before it renders anything: the merchant is connected, and the PayPal gateway is on.
 *
 * @group paypal-wallet
 */
class ButtonsAvailableServiceTest extends WalletTestCase {

	/**
	 * The connection and gateway states, and whether the buttons may show.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: bool}>
	 */
	public function data_states(): array {
		return array(
			'connected, gateway on'      => array( true, true, true ),
			'connected, gateway off'     => array( true, false, false ),
			'not connected, gateway on'  => array( false, true, false ),
			'not connected, gateway off' => array( false, false, false ),
		);
	}

	/**
	 * @testdox Should make the buttons available only when the merchant is connected and the PayPal gateway is on.
	 * @dataProvider data_states
	 *
	 * @param bool $connected       Whether the merchant is connected.
	 * @param bool $gateway_enabled Whether the PayPal gateway is on.
	 * @param bool $expected        Whether the buttons may show.
	 */
	public function test_buttons_available_needs_a_connection_and_the_gateway_on( bool $connected, bool $gateway_enabled, bool $expected ): void {
		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'gateway_enabled' )->with( PayPalGateway::ID )->andReturn( $gateway_enabled );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'settings.flag.is-connected' )->andReturn( $connected );
		$container->shouldReceive( 'get' )->with( 'settings.settings-provider' )->andReturn( $settings_provider );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SdkV6/services.php';

		$this->assertSame( $expected, $services['sdk-v6.buttons-available']( $container ) );
	}

	/**
	 * The add-payment-method page offers the PayPal save button only while the buttons are available, even with
	 * "Save PayPal and Venmo" on.
	 *
	 * @testdox Should not load the add-payment-method button when the buttons are not available: $buttons_available.
	 * @testWith [true, true]
	 *           [false, false]
	 *
	 * @param bool $buttons_available Whether the merchant is connected and the PayPal gateway is on.
	 * @param bool $expected          Whether the page loads the save button.
	 */
	public function test_add_payment_method_manager_needs_the_buttons_available( bool $buttons_available, bool $expected ): void {
		wp_set_current_user( $this->factory->user->create() );

		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$context = $this->mock( Context::class );
		$context->shouldReceive( 'is_add_payment_method_page' )->andReturn( true );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'settings.settings-provider' )->andReturn( $settings_provider );
		$container->shouldReceive( 'get' )->with( 'sdk-v6.asset-getter' )->andReturn( $this->mock( AssetGetter::class ) );
		$container->shouldReceive( 'get' )->with( 'ppcp.asset-version' )->andReturn( '1.0.0' );
		$container->shouldReceive( 'get' )->with( 'settings.environment' )->andReturn( $this->mock( Environment::class ) );
		$container->shouldReceive( 'get' )->with( 'button.helper.context' )->andReturn( $context );
		$container->shouldReceive( 'get' )->with( 'sdk-v6.buttons-available' )->andReturn( $buttons_available );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SdkV6/services.php';
		$manager  = $services['sdk-v6.add-payment-method-manager']( $container );

		$this->assertInstanceOf( AddPaymentMethodManager::class, $manager );
		$this->assertSame( $expected, $manager->should_load_on_current_page() );
	}
}
