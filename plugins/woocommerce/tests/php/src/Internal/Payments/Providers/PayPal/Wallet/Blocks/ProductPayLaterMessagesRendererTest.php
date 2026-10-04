<?php
/**
 * Tests for the renderer of the product Pay Later messaging block.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\ProductPayLaterMessagesRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;

/**
 * The placeholder markup the block renders for the product Pay Later messaging, and how the layout follows the SDK v6
 * stack. The block is a real block type, rendered the way WordPress renders a block in a post.
 *
 * @group paypal-wallet
 */
class ProductPayLaterMessagesRendererTest extends WalletTestCase {

	private const BLOCK = 'wallet-test/product-paylater-messages';

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * The partner attribution mock.
	 *
	 * @var PartnerAttribution&MockInterface
	 */
	private $partner_attribution;

	/**
	 * Build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings_status     = $this->mock( SettingsStatus::class );
		$this->partner_attribution = $this->mock( PartnerAttribution::class );
		$this->partner_attribution->shouldReceive( 'get_bn_code' )->andReturn( 'Woo_PPCP' );
	}

	/**
	 * A container that serves the collaborators.
	 *
	 * @param bool|null $owns_current_page True or false when the 'sdk-v6.owns-current-page' service is registered and
	 *                                     answers accordingly, null when the service is absent altogether.
	 * @return ContainerInterface
	 */
	private function create_container( ?bool $owns_current_page ): ContainerInterface {
		return $this->container_with_v6_ownership(
			$owns_current_page,
			array(
				'wcgateway.settings.status'      => $this->settings_status,
				'api.helper.partner-attribution' => $this->partner_attribution,
			)
		);
	}

	/**
	 * Register a block that renders through the renderer, and render it.
	 *
	 * @param ProductPayLaterMessagesRenderer $renderer  The renderer.
	 * @param ContainerInterface              $container The container.
	 * @return string The HTML of the block.
	 */
	private function render( ProductPayLaterMessagesRenderer $renderer, ContainerInterface $container ): string {
		$this->register_block_for_test(
			self::BLOCK,
			array(
				'render_callback' => static function ( array $attributes ) use ( $renderer, $container ): string {
					return $renderer->render( $attributes, $container );
				},
			)
		);

		return $this->render_block_for_test( self::BLOCK, array( 'ppcpId' => 'ppcp-product-paylater-messages' ) );
	}

	/**
	 * Say whether the product placement is on.
	 *
	 * @param bool $enabled Whether the product Pay Later messaging placement is on.
	 */
	private function messaging_enabled_for_product( bool $enabled ): void {
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'product' )->andReturn( $enabled );
	}

	/**
	 * @testdox Should render an empty string, so no placeholder markup reaches the page, when the product Pay Later messaging placement is disabled.
	 */
	public function test_renders_empty_string_when_messaging_is_disabled_for_product(): void {
		$this->messaging_enabled_for_product( false );

		$html = $this->render( new ProductPayLaterMessagesRenderer( array() ), $this->create_container( false ) );

		$this->assertSame( '', $html );
	}

	/**
	 * @testdox Should render the placeholder with the product placement and the flex style attributes, and without the text-only attributes, on a page v6 does not own.
	 */
	public function test_renders_flex_layout_attributes_when_v6_does_not_own_the_current_page(): void {
		$this->messaging_enabled_for_product( true );
		$renderer = new ProductPayLaterMessagesRenderer(
			array(
				'layout'     => 'flex',
				'flex_color' => 'blue',
				'flex_ratio' => '8x1',
			)
		);

		$html = $this->render( $renderer, $this->create_container( false ) );

		$this->assertStringContainsString( 'id="ppcp-product-paylater-messages"', $html );
		$this->assertStringContainsString( 'class="ppcp-messages"', $html );
		$this->assertStringContainsString( 'data-partner-attribution-id="Woo_PPCP"', $html );
		$this->assertStringContainsString( 'data-pp-placement="product"', $html );
		$this->assertStringContainsString( 'data-pp-style-layout="flex"', $html );
		$this->assertStringContainsString( 'data-pp-style-color="blue"', $html );
		$this->assertStringContainsString( 'data-pp-style-ratio="8x1"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-type=', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-position=', $html );
	}

	/**
	 * @testdox Should render the text style attributes, and not the flex-only ones, for a text layout when the v6 service is not registered.
	 */
	public function test_renders_text_layout_attributes_when_v6_does_not_own_the_current_page(): void {
		$this->messaging_enabled_for_product( true );
		$renderer = new ProductPayLaterMessagesRenderer(
			array(
				'layout'    => 'text',
				'logo'      => 'primary',
				'position'  => 'left',
				'color'     => 'black',
				'text_size' => '12',
			)
		);

		$html = $this->render( $renderer, $this->create_container( null ) );

		$this->assertStringContainsString( 'data-pp-style-layout="text"', $html );
		$this->assertStringContainsString( 'data-pp-style-logo-type="primary"', $html );
		$this->assertStringContainsString( 'data-pp-style-logo-position="left"', $html );
		$this->assertStringContainsString( 'data-pp-style-text-color="black"', $html );
		$this->assertStringContainsString( 'data-pp-style-text-size="12"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-color=', $html );
		$this->assertStringNotContainsString( 'data-pp-style-ratio=', $html );
	}

	/**
	 * @testdox Should render a text message, without the flex-only attributes, when v6 owns the page, even if the renderer was configured with a flex layout, because the v6 messaging component only styles text.
	 */
	public function test_coerces_to_text_layout_when_v6_owns_the_current_page_even_with_flex_layout_config(): void {
		$this->messaging_enabled_for_product( true );
		$renderer = new ProductPayLaterMessagesRenderer(
			array(
				'layout'     => 'flex',
				'flex_color' => 'blue',
				'flex_ratio' => '8x1',
			)
		);

		$html = $this->render( $renderer, $this->create_container( true ) );

		$this->assertStringContainsString( 'data-pp-style-layout="text"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-color=', $html );
		$this->assertStringNotContainsString( 'data-pp-style-ratio=', $html );
	}

	/**
	 * @testdox Should always report the placement as product, whatever the layout.
	 */
	public function test_placement_is_always_product(): void {
		$this->messaging_enabled_for_product( true );

		$html = $this->render( new ProductPayLaterMessagesRenderer( array( 'layout' => 'text' ) ), $this->create_container( false ) );

		$this->assertStringContainsString( 'data-pp-placement="product"', $html );
	}

	/**
	 * @testdox Should wrap the placeholder in the wrapper element of the block, with the class WordPress gives the block.
	 */
	public function test_wraps_the_placeholder_in_the_block_wrapper(): void {
		$this->messaging_enabled_for_product( true );

		$html = $this->render( new ProductPayLaterMessagesRenderer( array() ), $this->create_container( false ) );

		$this->assertStringStartsWith( '<div class="wp-block-wallet-test-product-paylater-messages"><div ', $html );
		$this->assertStringContainsString( ' id="ppcp-product-paylater-messages" ', $html );
		$this->assertStringEndsWith( '></div></div>', $html );
	}
}
