<?php
/**
 * Tests for the renderer of the cart and checkout Pay Later blocks.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks\PayLaterWCBlocksRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;

/**
 * The placeholder markup of the cart Pay Later placement and how its layout follows the SDK v6 stack. The renderer is
 * configured through its constructor, not through block attributes.
 *
 * @group paypal-wallet
 */
class PayLaterWCBlocksRendererTest extends WalletTestCase {

	private const ATTRIBUTES = array(
		'ppcpId'  => 'ppcp-cart-paylater-messages',
		'blockId' => 'woocommerce-paypal-payments/cart-paylater-messages',
	);

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
	 * Build the collaborators, with the cart placement on.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings_status = $this->mock( SettingsStatus::class );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'cart' )->andReturn( true )->byDefault();

		$this->partner_attribution = $this->mock( PartnerAttribution::class );
		$this->partner_attribution->shouldReceive( 'get_bn_code' )->andReturn( 'Woo_PPCP' );
	}

	/**
	 * A container that serves the collaborators.
	 *
	 * @param bool|null $owns_current_page True or false when the 'sdk-v6.owns-current-page' service is registered and
	 *                                     answers accordingly, null when the service is absent altogether (the v6 module
	 *                                     is not loaded on this site).
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
	 * @testdox Should render a text message with the text style attributes, and no flex-only attributes, when v6 owns the page even if the renderer was configured with a flex layout.
	 */
	public function test_coerces_to_text_layout_when_v6_owns_the_current_page_even_with_flex_layout_config(): void {
		$renderer = new PayLaterWCBlocksRenderer(
			array(
				'placement'  => 'cart',
				'layout'     => 'flex',
				'position'   => 'left',
				'logo'       => 'primary',
				'text_size'  => '12',
				'color'      => 'black',
				'flex_color' => 'blue',
				'flex_ratio' => '8x1',
			)
		);

		$html = $renderer->render( self::ATTRIBUTES, 'cart', $this->create_container( true ) );

		$this->assertStringContainsString( 'data-pp-style-layout="text"', $html );
		$this->assertStringContainsString( 'data-pp-style-logo-type="primary"', $html );
		$this->assertStringContainsString( 'data-pp-style-logo-position="left"', $html );
		$this->assertStringContainsString( 'data-pp-style-text-color="black"', $html );
		$this->assertStringContainsString( 'data-pp-style-text-size="12"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-color=', $html );
		$this->assertStringNotContainsString( 'data-pp-style-ratio=', $html );
	}

	/**
	 * @testdox Should keep rendering a flex message with its flex style attributes when the v6 service is not registered.
	 */
	public function test_keeps_flex_layout_when_sdk_v6_module_is_not_loaded(): void {
		$renderer = new PayLaterWCBlocksRenderer(
			array(
				'placement'  => 'cart',
				'layout'     => 'flex',
				'flex_color' => 'blue',
				'flex_ratio' => '8x1',
			)
		);

		$html = $renderer->render( self::ATTRIBUTES, 'cart', $this->create_container( null ) );

		$this->assertStringContainsString( 'data-pp-style-layout="flex"', $html );
		$this->assertStringContainsString( 'data-pp-style-color="blue"', $html );
		$this->assertStringContainsString( 'data-pp-style-ratio="8x1"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-type=', $html );
	}

	/**
	 * @testdox Should keep rendering a flex message with its flex style attributes when the v6 module is loaded but does not own the page, because v5 can still draw the banner on a page it owns.
	 */
	public function test_keeps_flex_layout_when_v6_module_is_loaded_but_does_not_own_the_current_page(): void {
		$renderer = new PayLaterWCBlocksRenderer(
			array(
				'placement'  => 'cart',
				'layout'     => 'flex',
				'flex_color' => 'blue',
				'flex_ratio' => '8x1',
			)
		);

		$html = $renderer->render( self::ATTRIBUTES, 'cart', $this->create_container( false ) );

		$this->assertStringContainsString( 'data-pp-style-layout="flex"', $html );
		$this->assertStringContainsString( 'data-pp-style-color="blue"', $html );
		$this->assertStringContainsString( 'data-pp-style-ratio="8x1"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-type=', $html );
	}

	/**
	 * @testdox Should render the placeholder with its ID, the block name, the partner attribution and the configured placement.
	 */
	public function test_renders_the_placeholder_with_the_block_name_and_the_placement(): void {
		$renderer = new PayLaterWCBlocksRenderer( array( 'placement' => 'payment' ) );

		$html = $renderer->render( self::ATTRIBUTES, 'cart', $this->create_container( false ) );

		$this->assertStringContainsString( 'id="ppcp-cart-paylater-messages"', $html );
		$this->assertStringContainsString( 'data-block-name="woocommerce-paypal-payments/cart-paylater-messages"', $html );
		$this->assertStringContainsString( 'data-partner-attribution-id="Woo_PPCP"', $html );
		$this->assertStringContainsString( 'data-pp-placement="payment"', $html );
		$this->assertStringContainsString( 'data-pp-style-layout="text"', $html );
	}

	/**
	 * @testdox Should render nothing when the placement of the location is switched off in the settings.
	 */
	public function test_renders_nothing_when_the_placement_is_disabled(): void {
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'cart' )->andReturn( false );
		$renderer = new PayLaterWCBlocksRenderer( array( 'placement' => 'cart' ) );

		$html = $renderer->render( self::ATTRIBUTES, 'cart', $this->create_container( false ) );

		$this->assertEmpty( $html );
	}
}
