<?php
/**
 * Tests for the product blocks helpers.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\ProductBlocks;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * What the product blocks decide: whether a placement is on, whether a template renders the blocks, where messaging
 * goes, and when the classic product render is given back to a page builder. The block detection reads real block
 * markup through WordPress.
 *
 * @group paypal-wallet
 */
class ProductBlocksTest extends WalletTestCase {

	private const RENDER_HOOK = 'ppcp_test_after_add_to_cart_form';

	/**
	 * Start from a request in which the classic render was not redirected.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_static( 'neutralized_from', null );
		$this->set_static( 'classic_render_restored', false );
	}

	/**
	 * Put the state of the request back, because it lives in static properties.
	 */
	public function tearDown(): void {
		try {
			$this->set_static( 'neutralized_from', null );
			$this->set_static( 'classic_render_restored', false );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should report the product Pay Later placement as the settings status does: location enabled is $location_enabled.
	 * @dataProvider on_off_provider
	 *
	 * @param bool $location_enabled Whether the settings status reports the location as enabled.
	 */
	public function test_is_messaging_enabled_reflects_settings_status_for_product_location( bool $location_enabled ): void {
		$settings_status = $this->mock( SettingsStatus::class );
		$settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'product' )->andReturn( $location_enabled );

		$this->assertSame( $location_enabled, ProductBlocks::is_messaging_enabled( $settings_status ) );
	}

	/**
	 * @testdox Should report the product Smart Buttons placement as the settings status does: location enabled is $location_enabled.
	 * @dataProvider on_off_provider
	 *
	 * @param bool $location_enabled Whether the settings status reports the location as enabled.
	 */
	public function test_is_buttons_enabled_reflects_settings_status_for_product_location( bool $location_enabled ): void {
		$settings_status = $this->mock( SettingsStatus::class );
		$settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'product' )->andReturn( $location_enabled );

		$this->assertSame( $location_enabled, ProductBlocks::is_buttons_enabled( $settings_status ) );
	}

	/**
	 * A location that is on and one that is off.
	 *
	 * @return array
	 */
	public function on_off_provider(): array {
		return array(
			'location enabled'  => array( true ),
			'location disabled' => array( false ),
		);
	}

	/**
	 * @testdox Should report that v6 does not own the page, without resolving the service, when the container does not register 'sdk-v6.owns-current-page'.
	 */
	public function test_v6_owns_current_page_is_false_when_service_is_not_registered(): void {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->with( 'sdk-v6.owns-current-page' )->andReturn( false );
		$container->shouldNotReceive( 'get' );

		$this->assertFalse( ProductBlocks::v6_owns_current_page( $container ) );
	}

	/**
	 * @testdox Should return what the registered predicate resolves to: $owns_current_page.
	 * @dataProvider on_off_provider
	 *
	 * @param bool $owns_current_page What the predicate resolves to.
	 */
	public function test_v6_owns_current_page_reflects_the_registered_predicate( bool $owns_current_page ): void {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->with( 'sdk-v6.owns-current-page' )->andReturn( true );
		$container->shouldReceive( 'get' )->with( 'sdk-v6.owns-current-page' )->andReturn(
			static function () use ( $owns_current_page ): bool {
				return $owns_current_page;
			}
		);

		$this->assertSame( $owns_current_page, ProductBlocks::v6_owns_current_page( $container ) );
	}

	/**
	 * @testdox Should report that the template does not render the blocks when no block template content applies.
	 */
	public function test_template_renders_blocks_is_false_when_content_is_null(): void {
		$this->assertFalse( ProductBlocks::template_renders_blocks( null ) );
	}

	/**
	 * @testdox Should report that the template does not render the blocks when it delegates to the classic template, even with an add-to-cart anchor, because the classic template must render them itself.
	 */
	public function test_template_renders_blocks_is_false_when_legacy_template_block_is_present(): void {
		$content = '<!-- wp:woocommerce/legacy-template {"template":"single-product"} /-->'
			. '<!-- wp:woocommerce/add-to-cart-form /-->';

		$this->assertFalse( ProductBlocks::template_renders_blocks( $content ) );
	}

	/**
	 * @testdox Should report that the template renders the blocks, so the classic render stands down, when it holds $label.
	 * @dataProvider template_renders_blocks_provider
	 *
	 * @param string $label   What the template holds.
	 * @param string $content The template content.
	 */
	public function test_template_renders_blocks_is_true_when_an_anchor_or_explicit_block_is_present( string $label, string $content ): void {
		unset( $label );

		$this->assertTrue( ProductBlocks::template_renders_blocks( $content ) );
	}

	/**
	 * Template contents that place a block or an anchor.
	 *
	 * @return array
	 */
	public function template_renders_blocks_provider(): array {
		return array(
			'add-to-cart-form anchor present'             => array( 'an add-to-cart-form anchor', '<!-- wp:woocommerce/add-to-cart-form /-->' ),
			'add-to-cart-with-options anchor present'     => array(
				'an add-to-cart-with-options anchor',
				'<!-- wp:woocommerce/add-to-cart-with-options -->'
				. '<!-- wp:woocommerce/product-buttons /-->'
				. '<!-- /wp:woocommerce/add-to-cart-with-options -->',
			),
			'smart buttons block explicitly placed'       => array( 'the smart buttons block', '<!-- wp:woocommerce-paypal-payments/product-smart-buttons /-->' ),
			'pay later messaging block explicitly placed' => array( 'the messaging block', '<!-- wp:woocommerce-paypal-payments/product-paylater-messages /-->' ),
			'single-product price anchor only'            => array( 'only the price anchor', '<!-- wp:woocommerce/product-price {"isDescendentOfSingleProductTemplate":true} /-->' ),
		);
	}

	/**
	 * @testdox Should report that the template does not render the blocks when it holds only unrelated blocks.
	 */
	public function test_template_renders_blocks_is_false_when_only_unrelated_blocks_are_present(): void {
		$content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';

		$this->assertFalse( ProductBlocks::template_renders_blocks( $content ) );
	}

	/**
	 * @testdox Should place the messaging block after the product price block when no filter overrides the placement.
	 */
	public function test_messaging_placement_defaults_to_after_the_product_price(): void {
		$this->assertSame(
			array(
				'anchor'   => array( 'woocommerce/product-price' ),
				'position' => 'after',
			),
			ProductBlocks::messaging_placement()
		);
	}

	/**
	 * @testdox Should normalize a valid placement override to a list of anchors and fall back to the default for an invalid one: $label.
	 * @dataProvider messaging_placement_provider
	 *
	 * @param string $label    What the override is.
	 * @param mixed  $filtered What the filter returns.
	 * @param array  $expected The placement.
	 */
	public function test_messaging_placement_honours_valid_overrides_and_falls_back_otherwise( string $label, $filtered, array $expected ): void {
		unset( $label );
		add_filter(
			'woocommerce_paypal_payments_product_messages_block_placement',
			static function () use ( $filtered ) {
				return $filtered;
			}
		);

		$this->assertSame( $expected, ProductBlocks::messaging_placement() );
	}

	/**
	 * Overrides of the messaging placement with the placement they give.
	 *
	 * @return array
	 */
	public function messaging_placement_provider(): array {
		$default = array(
			'anchor'   => array( 'woocommerce/product-price' ),
			'position' => 'after',
		);

		return array(
			'string anchor'                    => array(
				'a string anchor',
				array(
					'anchor'   => 'woocommerce/post-title',
					'position' => 'before',
				),
				array(
					'anchor'   => array( 'woocommerce/post-title' ),
					'position' => 'before',
				),
			),
			'array anchor'                     => array(
				'an array anchor',
				array(
					'anchor'   => array( 'a/one', 'a/two' ),
					'position' => 'last_child',
				),
				array(
					'anchor'   => array( 'a/one', 'a/two' ),
					'position' => 'last_child',
				),
			),
			'array anchor drops invalid items' => array(
				'an array anchor with invalid items',
				array(
					'anchor'   => array( 'a/one', '', 5 ),
					'position' => 'after',
				),
				array(
					'anchor'   => array( 'a/one' ),
					'position' => 'after',
				),
			),
			'non-array filter result'          => array( 'a non-array result', 'nope', $default ),
			'invalid position'                 => array(
				'an invalid position',
				array(
					'anchor'   => 'a/one',
					'position' => 'middle',
				),
				$default,
			),
			'missing position'                 => array( 'a missing position', array( 'anchor' => 'a/one' ), $default ),
			'empty string anchor'              => array(
				'an empty string anchor',
				array(
					'anchor'   => '',
					'position' => 'after',
				),
				$default,
			),
			'empty anchor array'               => array(
				'an empty anchor array',
				array(
					'anchor'   => array(),
					'position' => 'after',
				),
				$default,
			),
			'anchor array of non-strings'      => array(
				'an anchor array of non-strings',
				array(
					'anchor'   => array( 1, null ),
					'position' => 'after',
				),
				$default,
			),
			'anchor array of empty strings'    => array(
				'an anchor array of empty strings',
				array(
					'anchor'   => array( '', '' ),
					'position' => 'after',
				),
				$default,
			),
			'non-string non-array anchor'      => array(
				'an anchor that is a number',
				array(
					'anchor'   => 42,
					'position' => 'after',
				),
				$default,
			),
		);
	}

	/**
	 * @testdox Should let a price block qualify as the messaging anchor only when it belongs to the single product and not a query loop: $label.
	 * @dataProvider single_product_price_anchor_provider
	 *
	 * @param string $label    What the anchor is.
	 * @param mixed  $anchor   The parsed anchor block.
	 * @param bool   $expected Whether messaging may be inserted at it.
	 */
	public function test_is_single_product_price_anchor( string $label, $anchor, bool $expected ): void {
		unset( $label );

		$this->assertSame( $expected, ProductBlocks::is_single_product_price_anchor( $anchor ) );
	}

	/**
	 * Anchor blocks with the answer.
	 *
	 * @return array
	 */
	public function single_product_price_anchor_provider(): array {
		return array(
			'price of the single product' => array(
				'the price of the single product',
				array(
					'blockName' => 'woocommerce/product-price',
					'attrs'     => array( 'isDescendentOfSingleProductTemplate' => true ),
				),
				true,
			),
			'price inside a query loop'   => array(
				'a price inside a query loop',
				array(
					'blockName' => 'woocommerce/product-price',
					'attrs'     => array( 'isDescendentOfQueryLoop' => true ),
				),
				false,
			),
			'price flagged for both'      => array(
				'a price flagged for both',
				array(
					'blockName' => 'woocommerce/product-price',
					'attrs'     => array(
						'isDescendentOfSingleProductTemplate' => true,
						'isDescendentOfQueryLoop' => true,
					),
				),
				false,
			),
			'price without attributes'    => array( 'a price without attributes', array( 'blockName' => 'woocommerce/product-price' ), false ),
			'other anchor block'          => array( 'another anchor block', array( 'blockName' => 'woocommerce/add-to-cart-form' ), true ),
			'non-array anchor'            => array( 'an anchor that is a string', 'woocommerce/product-price', false ),
			'null anchor'                 => array( 'a null anchor', null, false ),
		);
	}

	/**
	 * @testdox Should report that no page builder replaced the template when WordPress is about to render the core canvas, spelled as $spelling.
	 * @dataProvider canvas_template_provider
	 *
	 * @param string $spelling How the path of the canvas is written.
	 */
	public function test_builder_overrode_template_is_false_for_the_core_canvas( string $spelling ): void {
		$canvas   = $this->canvas_template();
		$template = array(
			'core'           => $canvas,
			'backslashes'    => str_replace( '/', '\\', $canvas ),
			'double slashes' => '/' . str_replace( '/', '//', substr( $canvas, 1 ) ),
		)[ $spelling ];

		$this->assertFalse( ProductBlocks::builder_overrode_template( $template ) );
	}

	/**
	 * Spellings of the path of the canvas.
	 *
	 * @return array
	 */
	public function canvas_template_provider(): array {
		return array(
			'canvas path as core builds it'   => array( 'core' ),
			'canvas path with backslashes'    => array( 'backslashes' ),
			'canvas path with double slashes' => array( 'double slashes' ),
		);
	}

	/**
	 * @testdox Should report that a page builder replaced the template when it supplies its own template file.
	 */
	public function test_builder_overrode_template_is_true_for_another_template(): void {
		$template = '/var/www/wp-content/plugins/elementor/modules/page-templates/templates/canvas.php';

		$this->assertTrue( ProductBlocks::builder_overrode_template( $template ) );
	}

	/**
	 * @testdox Should report that no page builder replaced the template when the value is not a usable path: $label.
	 * @dataProvider unusable_template_provider
	 *
	 * @param string $label    What the value is.
	 * @param mixed  $template The template_include value.
	 */
	public function test_builder_overrode_template_is_false_for_unusable_values( string $label, $template ): void {
		unset( $label );

		$this->assertFalse( ProductBlocks::builder_overrode_template( $template ) );
	}

	/**
	 * Values that are not a template path.
	 *
	 * @return array
	 */
	public function unusable_template_provider(): array {
		return array(
			'null'         => array( 'null', null ),
			'array'        => array( 'an array', array( 'canvas.php' ) ),
			'empty string' => array( 'an empty string', '' ),
		);
	}

	/**
	 * @testdox Should bridge the parked callbacks back onto the original hook only once when the classic render is restored twice for a page builder.
	 */
	public function test_restore_classic_render_bridges_onto_the_original_hook_once(): void {
		$this->set_static( 'neutralized_from', self::RENDER_HOOK );
		$fired = 0;
		add_action(
			'ppcp_product_blocks_render_noop',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		do_action( self::RENDER_HOOK );
		$this->assertSame( 0, $fired, 'The parked callback does not run from the original hook before the restore' );

		ProductBlocks::restore_classic_render_for_builders( '/var/www/wp-content/plugins/elementor/canvas.php' );
		ProductBlocks::restore_classic_render_for_builders( '/var/www/wp-content/plugins/elementor/canvas.php' );
		do_action( self::RENDER_HOOK );

		$this->assertSame( 1, $fired, 'The parked callback runs once from the original hook, not once per restore' );
		$this->assertCount( 1, $GLOBALS['wp_filter'][ self::RENDER_HOOK ]->callbacks[30], 'One bridge, at priority 30' );
	}

	/**
	 * @testdox Should bridge nothing back when the template about to render is the core block-template canvas, because the blocks render the page.
	 */
	public function test_restore_classic_render_does_nothing_when_core_canvas_renders(): void {
		$this->set_static( 'neutralized_from', self::RENDER_HOOK );

		ProductBlocks::restore_classic_render_for_builders( $this->canvas_template() );

		$this->assertFalse( has_action( self::RENDER_HOOK ) );
		$this->assertFalse( $this->get_static( 'classic_render_restored' ), 'The restore did not run' );
	}

	/**
	 * @testdox Should bridge nothing back when a page builder replaces the template but the classic render was never redirected on this request.
	 */
	public function test_restore_classic_render_does_nothing_when_render_was_not_redirected(): void {
		$this->set_static( 'neutralized_from', null );

		ProductBlocks::restore_classic_render_for_builders( '/var/www/wp-content/plugins/elementor/canvas.php' );

		$this->assertFalse( $this->get_static( 'classic_render_restored' ), 'The restore did not run' );
	}

	/**
	 * The path of the core block-template canvas.
	 *
	 * @return string
	 */
	private function canvas_template(): string {
		return ABSPATH . WPINC . '/template-canvas.php';
	}

	/**
	 * Read a static property of ProductBlocks.
	 *
	 * @param string $property The property.
	 * @return mixed
	 */
	private function get_static( string $property ) {
		$reflection = new ReflectionProperty( ProductBlocks::class, $property );
		$reflection->setAccessible( true );

		return $reflection->getValue();
	}

	/**
	 * Set a static property of ProductBlocks.
	 *
	 * @param string $property The property.
	 * @param mixed  $value    The value.
	 */
	private function set_static( string $property, $value ): void {
		$reflection = new ReflectionProperty( ProductBlocks::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( null, $value );
	}
}
