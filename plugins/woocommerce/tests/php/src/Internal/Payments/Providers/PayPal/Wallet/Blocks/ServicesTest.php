<?php
/**
 * Tests for the product hooked blocks registrar service of the blocks module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\HookedBlocksRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The 'blocks.product-hooked-blocks-registrar' service, resolved from the real blocks services file against a container
 * that serves the settings status and the messages apply helper, and says whether the Pay Later configurator service is
 * available.
 *
 * ProductBlocks::register() reads this service and calls register() on it to auto-insert the product Smart Buttons and
 * Pay Later messaging blocks into block-theme templates through the Block Hooks API.
 *
 * @group paypal-wallet
 */
class ServicesTest extends WalletTestCase {

	private const SMART_BUTTONS_BLOCK = 'woocommerce-paypal-payments/product-smart-buttons';
	private const MESSAGING_BLOCK     = 'woocommerce-paypal-payments/product-paylater-messages';

	/**
	 * Resolve the registrar from the real services file.
	 *
	 * @param SettingsStatus $settings_status        The settings status.
	 * @param MessagesApply  $messages_apply         The messages apply helper.
	 * @param bool           $configurator_available Whether the Pay Later configurator service is available.
	 * @return HookedBlocksRegistrar
	 */
	private function resolve_registrar( SettingsStatus $settings_status, MessagesApply $messages_apply, bool $configurator_available = true ): HookedBlocksRegistrar {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'wcgateway.settings.status' )->andReturn( $settings_status );
		$container->shouldReceive( 'get' )->with( 'button.helper.messages-apply' )->andReturn( $messages_apply );
		$container->shouldReceive( 'has' )->with( 'paylater-configurator.factory.config' )->andReturn( $configurator_available );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/Blocks/services.php';

		return $services['blocks.product-hooked-blocks-registrar']( $container );
	}

	/**
	 * A messages apply helper that says whether Pay Later applies to the merchant's country.
	 *
	 * @param bool $for_country Whether it applies.
	 * @return MessagesApply
	 */
	private function messages_apply_for_country( bool $for_country ): MessagesApply {
		$messages_apply = $this->mock( MessagesApply::class );
		$messages_apply->shouldReceive( 'for_country' )->andReturn( $for_country );

		return $messages_apply;
	}

	/**
	 * @testdox Should produce a HookedBlocksRegistrar when the container serves the required collaborators.
	 */
	public function test_factory_returns_a_hooked_blocks_registrar(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ), $this->messages_apply_for_country( true ) );

		$this->assertInstanceOf( HookedBlocksRegistrar::class, $registrar );
	}

	/**
	 * @testdox Should wire the shared filter, the Smart Buttons filter and the messaging filter when Pay Later applies to the country and the configurator is available.
	 */
	public function test_register_wires_both_block_filters_when_messaging_applies_to_the_country(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ), $this->messages_apply_for_country( true ), true );

		$registrar->register();

		$this->assertNotFalse( has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_' . self::SMART_BUTTONS_BLOCK, array( $registrar, 'gate_insertion' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_' . self::MESSAGING_BLOCK, array( $registrar, 'gate_insertion' ) ) );
	}

	/**
	 * @testdox Should hook messaging after the product price block only, and the Smart Buttons block after both add-to-cart blocks, on a block theme.
	 */
	public function test_insertions_anchor_messaging_to_price_and_buttons_to_add_to_cart(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ), $this->messages_apply_for_country( true ), true );

		$this->assertSame( array( self::MESSAGING_BLOCK ), $registrar->add_hooked_block_types( array(), 'after', 'woocommerce/product-price', null ) );
		$this->assertSame( array( self::SMART_BUTTONS_BLOCK ), $registrar->add_hooked_block_types( array(), 'after', 'woocommerce/add-to-cart-form', null ) );
		$this->assertSame( array( self::SMART_BUTTONS_BLOCK ), $registrar->add_hooked_block_types( array(), 'after', 'woocommerce/add-to-cart-with-options', null ) );
		$this->assertSame( array(), $registrar->add_hooked_block_types( array(), 'before', 'woocommerce/product-price', null ) );
	}

	/**
	 * @testdox Should keep the messaging block at the price of the single product and drop it at a price in a query loop.
	 */
	public function test_messaging_insertion_is_restricted_to_the_single_product_price(): void {
		$settings_status = $this->mock( SettingsStatus::class );
		$settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'product' )->andReturn( true );
		$registrar    = $this->resolve_registrar( $settings_status, $this->messages_apply_for_country( true ), true );
		$parsed_block = array( 'blockName' => self::MESSAGING_BLOCK );

		$kept    = $registrar->gate_insertion(
			$parsed_block,
			self::MESSAGING_BLOCK,
			'after',
			array(
				'blockName' => 'woocommerce/product-price',
				'attrs'     => array( 'isDescendentOfSingleProductTemplate' => true ),
			),
			null
		);
		$dropped = $registrar->gate_insertion(
			$parsed_block,
			self::MESSAGING_BLOCK,
			'after',
			array(
				'blockName' => 'woocommerce/product-price',
				'attrs'     => array( 'isDescendentOfQueryLoop' => true ),
			),
			null
		);

		$this->assertSame( $parsed_block, $kept );
		$this->assertNull( $dropped );
	}

	/**
	 * @testdox Should wire only the Smart Buttons filter when Pay Later does not apply to the merchant's country, since that surface is not country-gated.
	 */
	public function test_register_wires_only_the_smart_buttons_filter_when_messaging_does_not_apply_to_the_country(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ), $this->messages_apply_for_country( false ), true );

		$registrar->register();

		$this->assertNotFalse( has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_' . self::SMART_BUTTONS_BLOCK, array( $registrar, 'gate_insertion' ) ) );
		$this->assertFalse( has_filter( 'hooked_block_' . self::MESSAGING_BLOCK ), 'No messaging filter is wired' );
	}

	/**
	 * @testdox Should wire only the Smart Buttons filter when the Pay Later configurator service is not available.
	 */
	public function test_register_wires_only_the_smart_buttons_filter_when_configurator_service_is_unavailable(): void {
		$registrar = $this->resolve_registrar( $this->mock( SettingsStatus::class ), $this->messages_apply_for_country( true ), false );

		$registrar->register();

		$this->assertNotFalse( has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ) );
		$this->assertNotFalse( has_filter( 'hooked_block_' . self::SMART_BUTTONS_BLOCK, array( $registrar, 'gate_insertion' ) ) );
		$this->assertFalse( has_filter( 'hooked_block_' . self::MESSAGING_BLOCK ), 'No messaging filter is wired' );
	}
}
