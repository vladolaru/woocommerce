<?php
/**
 * Tests for the Pay Later button locations service.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Settings;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The button locations service keeps the locations the merchant selected for the smart buttons, with their labels.
 * It runs from the module's own service definitions over a container that serves the settings provider.
 *
 * @group paypal-wallet
 */
class LocationsTest extends WalletTestCase {

	/**
	 * Resolve the button locations service with the given selection in the settings.
	 *
	 * @param string[] $selected_locations The locations the settings provider reports as selected.
	 * @return array<string, string> The locations with their labels.
	 */
	private function resolve_button_locations( array $selected_locations ): array {
		$services = ( new WCGatewayModule() )->services();

		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'smart_button_locations' )->andReturn( $selected_locations );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( $id ) use ( $services, $settings_provider, &$container ) {
				if ( 'settings.settings-provider' === $id ) {
					return $settings_provider;
				}

				return $services[ $id ]( $container );
			}
		);

		return $services['wcgateway.settings.pay-later.button-locations']( $container );
	}

	/**
	 * @testdox Should list the selected locations with their labels, in the order of the full location list.
	 *
	 * @dataProvider data_pay_later_button_locations
	 *
	 * @param string[]              $selected_locations The selected locations.
	 * @param array<string, string> $expected_result    The locations with their labels.
	 */
	public function test_pay_later_button_locations( array $selected_locations, array $expected_result ): void {
		$this->assertSame( $expected_result, $this->resolve_button_locations( $selected_locations ) );
	}

	/**
	 * The selected locations and the result.
	 *
	 * @return array<string, array{string[], array<string, string>}>
	 */
	public function data_pay_later_button_locations(): array {
		return array(
			'all four locations'                  => array(
				array( 'product', 'cart', 'checkout', 'mini-cart' ),
				array(
					'product'   => 'Single Product',
					'cart'      => 'Classic Cart',
					'checkout'  => 'Classic Checkout',
					'mini-cart' => 'Mini Cart',
				),
			),
			'cart and checkout'                   => array(
				array( 'cart', 'checkout' ),
				array(
					'cart'     => 'Classic Cart',
					'checkout' => 'Classic Checkout',
				),
			),
			'no location'                         => array( array(), array() ),
			'a selection in another order'        => array(
				array( 'checkout', 'product' ),
				array(
					'product'  => 'Single Product',
					'checkout' => 'Classic Checkout',
				),
			),
			'a location the buttons do not offer' => array(
				array( 'shop', 'cart' ),
				array( 'cart' => 'Classic Cart' ),
			),
		);
	}
}
