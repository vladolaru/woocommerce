<?php
/**
 * Tests for the compatibility module's service definitions.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * What the compatibility module registers: the script names that cache plugins must not minify, and the plugin checks.
 *
 * @group paypal-wallet
 */
class CompatServicesTest extends WalletTestCase {

	/**
	 * The service definitions, as the module returns them.
	 *
	 * @return array<string, callable>
	 */
	private function services(): array {
		return ( new CompatModule() )->services();
	}

	/**
	 * Resolve a service that needs no collaborators.
	 *
	 * @param string $id The service ID.
	 * @return mixed
	 */
	private function resolve( string $id ) {
		$services = $this->services();
		$this->assertArrayHasKey( $id, $services );

		return $services[ $id ]( $this->mock( ContainerInterface::class ) );
	}

	/**
	 * @testdox Should list the wallet's own script names and files for the cache plugin exclusions.
	 */
	public function test_script_exclusions_list_the_wallet_scripts(): void {
		$names = $this->resolve( 'compat.plugin-script-names' );
		$files = $this->resolve( 'compat.plugin-script-file-names' );

		$this->assertContains( 'ppcp-smart-button', $names );
		$this->assertContains( 'ppcp-gateway-settings', $names );
		$this->assertContains( 'ppcp-fraudnet', $names );
		$this->assertContains( 'button.js', $files );
		$this->assertContains( 'gateway-settings.js', $files );
		$this->assertContains( 'fraudnet.js', $files );
	}

	/**
	 * @testdox Should define the Name Your Price and Bookings plugin checks, which the module reads.
	 */
	public function test_plugin_checks_the_module_reads(): void {
		$this->assertIsBool( $this->resolve( 'compat.nyp.is_supported_plugin_version_active' ) );
		$this->assertIsBool( $this->resolve( 'compat.wc_bookings.is_supported_plugin_version_active' ) );
	}

	/**
	 * @testdox Should not define the shipment tracking services or list the tracking scripts in the cache plugin exclusions.
	 */
	public function test_tracking_layer_is_gone(): void {
		$services = $this->services();

		foreach ( array(
			'compat.shiptastic.is_supported_plugin_version_active',
			'compat.wc_shipment_tracking.is_supported_plugin_version_active',
			'compat.ywot.is_supported_plugin_version_active',
			'compat.dhl.is_supported_plugin_version_active',
			'compat.shipstation.is_supported_plugin_version_active',
			'compat.wc_shipping_tax.is_supported_plugin_version_active',
			'compat.asset_getter',
			'compat.assets',
		) as $id ) {
			$this->assertArrayNotHasKey( $id, $services, "$id belonged to the shipment tracking layer" );
		}

		$names = $this->resolve( 'compat.plugin-script-names' );
		$files = $this->resolve( 'compat.plugin-script-file-names' );

		$this->assertNotContains( 'ppcp-tracking', $names );
		$this->assertNotContains( 'ppcp-tracking-compat', $names );
		$this->assertNotContains( 'order-edit-page.js', $files );
		$this->assertNotContains( 'tracking-compat.js', $files );
	}

	/**
	 * @testdox Should keep the legacy PayPal Express and blueprint services the module wires.
	 */
	public function test_wallet_services_are_defined(): void {
		$services = $this->services();

		foreach ( array(
			'compat.ppec.mock-gateway',
			'compat.ppec.billing-agreement-converter',
			'compat.ppec.subscriptions-handler',
			'compat.blueprint.is_available',
			'compat.blueprint.bootstrap',
		) as $id ) {
			$this->assertArrayHasKey( $id, $services, "$id is a wallet service" );
		}
	}
}
