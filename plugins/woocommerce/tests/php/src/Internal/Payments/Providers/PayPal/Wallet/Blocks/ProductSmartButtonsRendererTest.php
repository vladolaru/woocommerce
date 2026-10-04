<?php
/**
 * Tests for the renderer of the product Smart Buttons block.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\ProductSmartButtonsRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use WC_Helper_Product;

/**
 * The wrapper the block renders for the product Smart Buttons, on a real single product page or off it. The block is a
 * real block type, rendered the way WordPress renders a block in a post.
 *
 * @group paypal-wallet
 */
class ProductSmartButtonsRendererTest extends WalletTestCase {

	private const BLOCK = 'wallet-test/product-smart-buttons';

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * Build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings_status = $this->mock( SettingsStatus::class );
	}

	/**
	 * A container that serves the settings status.
	 *
	 * @param bool|null $owns_current_page True or false when the 'sdk-v6.owns-current-page' service is registered and
	 *                                     answers accordingly, null when the service is absent altogether.
	 * @return ContainerInterface
	 */
	private function create_container( ?bool $owns_current_page ): ContainerInterface {
		return $this->container_with_v6_ownership(
			$owns_current_page,
			array( 'wcgateway.settings.status' => $this->settings_status )
		);
	}

	/**
	 * Make the request a page of a product, or the front page.
	 *
	 * @param bool $product_page Whether the request is the page of a product.
	 */
	private function visit( bool $product_page ): void {
		if ( $product_page ) {
			$product = WC_Helper_Product::create_simple_product();
			$this->go_to( get_permalink( $product->get_id() ) );
			$this->assertTrue( is_product(), 'The request is a product page' );
			return;
		}

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_product(), 'The request is not a product page' );
	}

	/**
	 * Say whether the product placement is on.
	 *
	 * @param bool $enabled Whether the product Smart Buttons placement is on.
	 */
	private function buttons_enabled_for_product( bool $enabled ): void {
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'product' )->andReturn( $enabled );
	}

	/**
	 * Register a block that renders through the renderer, and render it.
	 *
	 * @param ContainerInterface $container The container.
	 * @return string The HTML of the block.
	 */
	private function render( ContainerInterface $container ): string {
		$renderer = new ProductSmartButtonsRenderer();
		$this->register_block_for_test(
			self::BLOCK,
			array(
				'render_callback' => static function ( array $attributes ) use ( $renderer, $container ): string {
					return $renderer->render( $attributes, $container );
				},
			)
		);

		return $this->render_block_for_test( self::BLOCK );
	}

	/**
	 * @testdox Should render an empty string, so no button wrapper reaches the page, when the product Smart Buttons placement is disabled.
	 */
	public function test_renders_empty_string_when_buttons_are_disabled_for_product(): void {
		$this->visit( true );
		$this->buttons_enabled_for_product( false );

		$html = $this->render( $this->create_container( false ) );

		$this->assertSame( '', $html );
	}

	/**
	 * @testdox Should render an empty string, since the wrapper only belongs on product pages, when the request is not a single product page.
	 */
	public function test_renders_empty_string_when_current_request_is_not_a_product_page(): void {
		$this->visit( false );
		$this->buttons_enabled_for_product( true );

		$html = $this->render( $this->create_container( false ) );

		$this->assertSame( '', $html );
	}

	/**
	 * @testdox Should mount the wrapper into the v5 button id, so the existing v5 front-end pipeline hydrates it, when v6 does not own the product page.
	 */
	public function test_renders_v5_wrapper_id_when_v6_does_not_own_the_current_page(): void {
		$this->visit( true );
		$this->buttons_enabled_for_product( true );

		$html = $this->render( $this->create_container( false ) );

		$this->assertStringContainsString( 'class="ppc-button-wrapper"', $html );
		$this->assertStringContainsString( 'id="ppc-button-ppcp-gateway"', $html );
		$this->assertStringNotContainsString( 'id="ppc-button-ppcp-gateway-v6"', $html );
	}

	/**
	 * @testdox Should mount the wrapper into the v5 button id when the v6 service is not registered at all.
	 */
	public function test_renders_v5_wrapper_id_when_the_v6_service_is_not_registered(): void {
		$this->visit( true );
		$this->buttons_enabled_for_product( true );

		$html = $this->render( $this->create_container( null ) );

		$this->assertStringContainsString( 'id="ppc-button-ppcp-gateway"', $html );
	}

	/**
	 * @testdox Should mount the wrapper into the v6 button id, so the v6 script hydrates it instead of v5, when v6 owns the product page.
	 */
	public function test_renders_v6_wrapper_id_when_v6_owns_the_current_page(): void {
		$this->visit( true );
		$this->buttons_enabled_for_product( true );

		$html = $this->render( $this->create_container( true ) );

		$this->assertStringContainsString( 'id="ppc-button-ppcp-gateway-v6"', $html );
	}

	/**
	 * @testdox Should fire the button wrapper actions around the mount point, inside the wrapper and in this order, so third-party integrations that hook them keep working.
	 */
	public function test_fires_the_button_wrapper_actions_around_the_mount_point(): void {
		$this->visit( true );
		$this->buttons_enabled_for_product( true );
		add_action(
			'ppcp_start_button_wrapper_ppcp_gateway',
			static function (): void {
				echo '[start]';
			}
		);
		add_action(
			'ppcp_end_button_wrapper_ppcp_gateway',
			static function (): void {
				echo '[end]';
			}
		);
		add_action(
			'woocommerce_paypal_payments_single_product_button_render',
			static function (): void {
				echo '[rendered]';
			}
		);

		$html = $this->render( $this->create_container( false ) );

		$this->assertStringContainsString(
			'<div class="ppc-button-wrapper">[start]<div id="ppc-button-ppcp-gateway"></div>[end][rendered]</div>',
			$html
		);
		$this->assertStringStartsWith( '<div class="wp-block-wallet-test-product-smart-buttons">', $html );
	}
}
