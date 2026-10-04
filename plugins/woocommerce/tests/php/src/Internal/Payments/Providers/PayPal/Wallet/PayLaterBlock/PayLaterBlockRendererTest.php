<?php
/**
 * Tests for the renderer of the Pay Later block.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock\PayLaterBlockRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;

/**
 * The placeholder markup of the Pay Later block and how its layout follows the SDK v6 stack. The block is a real block
 * type, rendered the way WordPress renders a block in a post.
 *
 * @group paypal-wallet
 */
class PayLaterBlockRendererTest extends WalletTestCase {

	private const BLOCK = 'wallet-test/paylater-block';

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
	 * Build the collaborators, with the custom placement on.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings_status = $this->mock( SettingsStatus::class );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'custom_placement' )->andReturn( true )->byDefault();

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
	 * Register a block that renders through the renderer, and render it with the given attributes.
	 *
	 * @param ContainerInterface $container  The container.
	 * @param array              $attributes The attributes of the block.
	 * @return string The HTML of the block.
	 */
	private function render( ContainerInterface $container, array $attributes ): string {
		$renderer = new PayLaterBlockRenderer();
		$this->register_block_for_test(
			self::BLOCK,
			array(
				'render_callback' => static function ( array $block_attributes ) use ( $renderer, $container ): string {
					return $renderer->render( $block_attributes, $container );
				},
			)
		);

		return $this->render_block_for_test( self::BLOCK, $attributes );
	}

	/**
	 * @testdox Should render a text message with the text style attributes, and no flex-only attributes, when v6 owns the page even if the block was saved with a flex layout.
	 */
	public function test_coerces_to_text_layout_when_v6_owns_the_current_page_even_with_flex_layout_attribute(): void {
		$html = $this->render(
			$this->create_container( true ),
			array(
				'id'        => 'ppcp-1',
				'layout'    => 'flex',
				'logo'      => 'primary',
				'position'  => 'left',
				'color'     => 'black',
				'size'      => '12',
				'flexColor' => 'blue',
				'flexRatio' => '8x1',
			)
		);

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
		$html = $this->render(
			$this->create_container( null ),
			array(
				'id'        => 'ppcp-1',
				'layout'    => 'flex',
				'flexColor' => 'blue',
				'flexRatio' => '8x1',
			)
		);

		$this->assertStringContainsString( 'data-pp-style-layout="flex"', $html );
		$this->assertStringContainsString( 'data-pp-style-color="blue"', $html );
		$this->assertStringContainsString( 'data-pp-style-ratio="8x1"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-type=', $html );
	}

	/**
	 * @testdox Should keep rendering a flex message with its flex style attributes when the v6 module is loaded but does not own the page, because v5 can still draw the banner on a page it owns.
	 */
	public function test_keeps_flex_layout_when_v6_module_is_loaded_but_does_not_own_the_current_page(): void {
		$html = $this->render(
			$this->create_container( false ),
			array(
				'id'        => 'ppcp-1',
				'layout'    => 'flex',
				'flexColor' => 'blue',
				'flexRatio' => '8x1',
			)
		);

		$this->assertStringContainsString( 'data-pp-style-layout="flex"', $html );
		$this->assertStringContainsString( 'data-pp-style-color="blue"', $html );
		$this->assertStringContainsString( 'data-pp-style-ratio="8x1"', $html );
		$this->assertStringNotContainsString( 'data-pp-style-logo-type=', $html );
	}

	/**
	 * @testdox Should render the placeholder with its ID, the partner attribution and the block wrapper, and no placement while the placement is auto.
	 */
	public function test_renders_the_placeholder_without_a_placement_while_auto(): void {
		$html = $this->render( $this->create_container( false ), array( 'id' => 'ppcp-1' ) );

		$this->assertStringStartsWith( '<div id="ppcp-paylater-message-block" class="wp-block-wallet-test-paylater-block"><div ', $html );
		$this->assertStringContainsString( ' id="ppcp-1" class="ppcp-messages" data-partner-attribution-id="Woo_PPCP"', $html );
		$this->assertStringNotContainsString( 'data-pp-placement', $html );
	}

	/**
	 * @testdox Should set the placement of the placeholder when the block names one.
	 */
	public function test_sets_the_placement_when_it_is_not_auto(): void {
		$html = $this->render(
			$this->create_container( false ),
			array(
				'id'        => 'ppcp-1',
				'placement' => 'product',
			)
		);

		$this->assertStringContainsString( 'data-pp-placement="product"', $html );
	}

	/**
	 * @testdox Should render an empty string when the custom placement is switched off in the settings.
	 */
	public function test_renders_empty_string_when_the_custom_placement_is_disabled(): void {
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'custom_placement' )->andReturn( false );

		$html = $this->render( $this->create_container( false ), array( 'id' => 'ppcp-1' ) );

		$this->assertSame( '', $html );
	}

	/**
	 * @testdox Should render an empty string when the Pay Later block feature flag filter returns false.
	 */
	public function test_renders_empty_string_when_the_feature_flag_is_off(): void {
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.paylater_block_enabled', '__return_false' );

		$html = $this->render( $this->create_container( false ), array( 'id' => 'ppcp-1' ) );

		$this->assertSame( '', $html );
	}
}
