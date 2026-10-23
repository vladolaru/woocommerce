<?php
/**
 * Tests for the hooked blocks registrar service of the Pay Later WooCommerce Blocks module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\HookedBlocksRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The 'paylater-wc-blocks.hooked-blocks-registrar' service, resolved from the real module services file against a
 * container that only serves 'wcgateway.settings.status'.
 *
 * PayLaterWCBlocksModule::run() reads this service and calls register() on it to auto-insert the cart and checkout Pay
 * Later messaging blocks into block-theme templates through the Block Hooks API.
 *
 * @group paypal-wallet
 */
class ServicesTest extends WalletTestCase {

	/**
	 * Resolve the registrar from the real services file.
	 *
	 * @param SettingsStatus $settings_status The settings status.
	 * @return HookedBlocksRegistrar
	 */
	private function resolve_registrar( SettingsStatus $settings_status ): HookedBlocksRegistrar {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'wcgateway.settings.status' )->andReturn( $settings_status );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/PayLaterWCBlocks/services.php';

		return $services['paylater-wc-blocks.hooked-blocks-registrar']( $container );
	}

	/**
	 * @testdox Should produce a HookedBlocksRegistrar when the container serves a SettingsStatus instance.
	 */
	public function test_factory_returns_a_hooked_blocks_registrar(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ) );

		$this->assertInstanceOf( HookedBlocksRegistrar::class, $registrar );
	}

	/**
	 * @testdox Should wire the shared hooked_block_types filter and one filter for each of the cart and checkout messaging blocks, so the Block Hooks API can insert them into block-theme templates.
	 */
	public function test_register_wires_the_hooked_block_filters(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ) );

		$registrar->register();

		$this->assertNotFalse( has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_woocommerce-paypal-payments/cart-paylater-messages', array( $registrar, 'gate_insertion' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_woocommerce-paypal-payments/checkout-paylater-messages', array( $registrar, 'gate_insertion' ) ) );
	}

	/**
	 * @testdox Should hook the cart and checkout messaging blocks as the last child of the cart and checkout totals blocks on a block theme.
	 */
	public function test_insertions_anchor_the_messaging_blocks_to_the_totals_blocks(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ) );

		$this->assertSame(
			array( 'woocommerce-paypal-payments/cart-paylater-messages' ),
			$registrar->add_hooked_block_types( array(), 'last_child', 'woocommerce/cart-totals-block', null )
		);
		$this->assertSame(
			array( 'woocommerce-paypal-payments/checkout-paylater-messages' ),
			$registrar->add_hooked_block_types( array(), 'last_child', 'woocommerce/checkout-totals-block', null )
		);
	}

	/**
	 * @testdox Should drop each block when the Pay Later messaging placement of its location is switched off: $location is $enabled.
	 * @dataProvider location_provider
	 *
	 * @param string $location The location of the placement.
	 * @param string $block    The block of the location.
	 * @param bool   $enabled  Whether the placement is on.
	 */
	public function test_each_insertion_follows_the_placement_of_its_location( string $location, string $block, bool $enabled ): void {
		$settings_status = $this->mock( SettingsStatus::class );
		$settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( $location )->andReturn( $enabled );
		$registrar    = $this->resolve_registrar( $settings_status );
		$parsed_block = array( 'blockName' => $block );

		$result = $registrar->gate_insertion( $parsed_block, $block, 'last_child', null, null );

		$this->assertSame( $enabled ? $parsed_block : null, $result );
	}

	/**
	 * Locations with their block and the state of the placement.
	 *
	 * @return array
	 */
	public function location_provider(): array {
		return array(
			'cart on'      => array( 'cart', 'woocommerce-paypal-payments/cart-paylater-messages', true ),
			'cart off'     => array( 'cart', 'woocommerce-paypal-payments/cart-paylater-messages', false ),
			'checkout on'  => array( 'checkout', 'woocommerce-paypal-payments/checkout-paylater-messages', true ),
			'checkout off' => array( 'checkout', 'woocommerce-paypal-payments/checkout-paylater-messages', false ),
		);
	}
}
