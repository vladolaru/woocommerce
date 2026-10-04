<?php
/**
 * Tests for the registrar that auto-inserts PayPal blocks through the Block Hooks API.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\HookedBlocksRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_Block_Template;

/**
 * Which blocks the registrar offers at which anchor, and when it drops an insertion. The theme is a real block theme
 * or a real classic theme.
 *
 * @group paypal-wallet
 */
class HookedBlocksRegistrarTest extends WalletTestCase {

	private const CART_BLOCK     = 'woocommerce-paypal-payments/cart-paylater-messages';
	private const CHECKOUT_BLOCK = 'woocommerce-paypal-payments/checkout-paylater-messages';

	/**
	 * Two insertions: one enabled, one disabled.
	 *
	 * @return array<string, array{anchor:string, position:string, enabled:callable}>
	 */
	private function insertions(): array {
		return array(
			self::CART_BLOCK     => array(
				'anchor'   => 'woocommerce/cart-totals-block',
				'position' => 'last_child',
				'enabled'  => static function (): bool {
					return true;
				},
			),
			self::CHECKOUT_BLOCK => array(
				'anchor'   => 'woocommerce/checkout-totals-block',
				'position' => 'last_child',
				'enabled'  => static function (): bool {
					return false;
				},
			),
		);
	}

	/**
	 * Insertions whose cart block carries an anchor filter.
	 *
	 * @param callable $anchor_filter The anchor filter.
	 * @return array<string, array<string, mixed>>
	 */
	private function insertions_with_anchor_filter( callable $anchor_filter ): array {
		return array(
			self::CART_BLOCK => array(
				'anchor'        => 'woocommerce/cart-totals-block',
				'position'      => 'last_child',
				'enabled'       => static function (): bool {
					return true;
				},
				'anchor_filter' => $anchor_filter,
			),
		);
	}

	/**
	 * @testdox Should wire the shared hooked_block_types filter and one hooked_block filter for every insertion.
	 */
	public function test_register_wires_the_hooked_block_filters(): void {
		$registrar = new HookedBlocksRegistrar( $this->insertions() );
		$this->assertFalse( has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ), 'Nothing is wired before register()' );

		$registrar->register();

		$this->assertSame( 10, has_filter( 'hooked_block_types', array( $registrar, 'add_hooked_block_types' ) ) );
		$this->assertSame( 10, has_filter( 'hooked_block_' . self::CART_BLOCK, array( $registrar, 'gate_insertion' ) ) );
		$this->assertSame( 10, has_filter( 'hooked_block_' . self::CHECKOUT_BLOCK, array( $registrar, 'gate_insertion' ) ) );
	}

	/**
	 * @testdox Should return the hooked block types unchanged, without its block, when the active theme is not a block theme.
	 */
	public function test_add_hooked_block_types_returns_unchanged_list_on_non_block_theme(): void {
		$this->use_theme( 'storefront' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( array( 'some/existing-block' ), 'last_child', 'woocommerce/cart-totals-block', null );

		$this->assertSame( array( 'some/existing-block' ), $result );
	}

	/**
	 * @testdox Should turn a non-array hooked block types value into an empty array when the active theme is not a block theme: $label.
	 * @dataProvider non_array_hooked_block_types_provider
	 *
	 * @param string $label           What the value is.
	 * @param mixed  $non_array_value The value an earlier filter callback left.
	 */
	public function test_add_hooked_block_types_coerces_non_array_input_to_empty_array( string $label, $non_array_value ): void {
		unset( $label );
		$this->use_theme( 'storefront' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( $non_array_value, 'last_child', 'woocommerce/cart-totals-block', null );

		$this->assertSame( array(), $result );
	}

	/**
	 * Values that are not a list of block types.
	 *
	 * @return array
	 */
	public function non_array_hooked_block_types_provider(): array {
		return array(
			'null input'   => array( 'null', null ),
			'false input'  => array( 'false', false ),
			'string input' => array( 'a string', 'unexpected' ),
		);
	}

	/**
	 * @testdox Should append only the matching insertion, and keep the block types already there, on a block theme.
	 */
	public function test_add_hooked_block_types_appends_only_matching_insertion_on_block_theme(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( array( 'some/pre-existing-block' ), 'last_child', 'woocommerce/cart-totals-block', null );

		$this->assertSame( array( 'some/pre-existing-block', self::CART_BLOCK ), $result );
	}

	/**
	 * @testdox Should append nothing when no insertion matches the anchor and the position on a block theme.
	 */
	public function test_add_hooked_block_types_appends_nothing_when_no_insertion_matches_on_block_theme(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( array(), 'first_child', 'woocommerce/order-summary-block', null );

		$this->assertSame( array(), $result );
	}

	/**
	 * @testdox Should append nothing when the anchor matches but the position does not on a block theme.
	 */
	public function test_add_hooked_block_types_appends_nothing_for_another_position_on_block_theme(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( array(), 'first_child', 'woocommerce/cart-totals-block', null );

		$this->assertSame( array(), $result );
	}

	/**
	 * @testdox Should turn a non-array hooked block types value into an empty array before appending the matching block type on a block theme.
	 */
	public function test_add_hooked_block_types_coerces_non_array_input_before_appending_on_block_theme(): void {
		$this->use_theme( 'twentytwentyfour' );
		$registrar = new HookedBlocksRegistrar( $this->insertions() );

		$result = $registrar->add_hooked_block_types( null, 'last_child', 'woocommerce/cart-totals-block', null );

		$this->assertSame( array( self::CART_BLOCK ), $result );
	}

	/**
	 * @testdox Should append the block type for any anchor in an anchor list, and not for an anchor outside it.
	 */
	public function test_add_hooked_block_types_appends_for_any_anchor_in_an_anchor_list(): void {
		$this->use_theme( 'twentytwentyfour' );
		$multi_anchor_block = 'woocommerce-paypal-payments/product-smart-buttons';
		$registrar          = new HookedBlocksRegistrar(
			array(
				$multi_anchor_block => array(
					'anchor'   => array( 'a/one', 'a/two' ),
					'position' => 'after',
					'enabled'  => static function (): bool {
						return true;
					},
				),
			)
		);

		$this->assertSame( array( $multi_anchor_block ), $registrar->add_hooked_block_types( array(), 'after', 'a/one', null ) );
		$this->assertSame( array( $multi_anchor_block ), $registrar->add_hooked_block_types( array(), 'after', 'a/two', null ) );
		$this->assertSame( array(), $registrar->add_hooked_block_types( array(), 'after', 'a/three', null ) );
	}

	/**
	 * @testdox Should return null unchanged, without consulting any enabled predicate, when the parsed block is already null.
	 */
	public function test_gate_insertion_returns_null_unchanged_when_parsed_block_is_already_null(): void {
		$consulted = false;
		$registrar = new HookedBlocksRegistrar(
			array(
				self::CART_BLOCK => array(
					'anchor'   => 'woocommerce/cart-totals-block',
					'position' => 'last_child',
					'enabled'  => static function () use ( &$consulted ): bool {
						$consulted = true;
						return true;
					},
				),
			)
		);

		$result = $registrar->gate_insertion( null, self::CART_BLOCK, 'last_child', null, null );

		$this->assertNull( $result );
		$this->assertFalse( $consulted, 'No predicate runs for a dropped block' );
	}

	/**
	 * @testdox Should return the parsed block unchanged for a block type that is not one of its own.
	 */
	public function test_gate_insertion_returns_parsed_block_unchanged_for_unknown_block_type(): void {
		$registrar    = new HookedBlocksRegistrar( $this->insertions() );
		$parsed_block = array( 'blockName' => 'some/other-block' );

		$result = $registrar->gate_insertion( $parsed_block, 'some/other-block', 'last_child', null, null );

		$this->assertSame( $parsed_block, $result );
	}

	/**
	 * @testdox Should return the parsed block when the enabled predicate returns true.
	 */
	public function test_gate_insertion_returns_parsed_block_when_enabled_predicate_returns_true(): void {
		$registrar    = new HookedBlocksRegistrar( $this->insertions() );
		$parsed_block = array( 'blockName' => self::CART_BLOCK );

		$result = $registrar->gate_insertion( $parsed_block, self::CART_BLOCK, 'last_child', null, null );

		$this->assertSame( $parsed_block, $result );
	}

	/**
	 * @testdox Should return null, which suppresses the insertion for this render, when the enabled predicate returns false.
	 */
	public function test_gate_insertion_returns_null_when_enabled_predicate_returns_false(): void {
		$registrar    = new HookedBlocksRegistrar( $this->insertions() );
		$parsed_block = array( 'blockName' => self::CHECKOUT_BLOCK );

		$result = $registrar->gate_insertion( $parsed_block, self::CHECKOUT_BLOCK, 'last_child', null, null );

		$this->assertNull( $result );
	}

	/**
	 * @testdox Should consult the enabled predicate to decide the outcome.
	 */
	public function test_gate_insertion_consults_the_enabled_predicate(): void {
		$consulted = false;
		$registrar = new HookedBlocksRegistrar(
			array(
				self::CART_BLOCK => array(
					'anchor'   => 'woocommerce/cart-totals-block',
					'position' => 'last_child',
					'enabled'  => static function () use ( &$consulted ): bool {
						$consulted = true;
						return true;
					},
				),
			)
		);

		$registrar->gate_insertion( array( 'blockName' => self::CART_BLOCK ), self::CART_BLOCK, 'last_child', null, null );

		$this->assertTrue( $consulted );
	}

	/**
	 * @testdox Should keep the block when the anchor filter accepts the anchor block.
	 */
	public function test_gate_insertion_keeps_block_when_anchor_filter_accepts_the_anchor(): void {
		$registrar    = new HookedBlocksRegistrar(
			$this->insertions_with_anchor_filter(
				static function (): bool {
					return true;
				}
			)
		);
		$parsed_block = array( 'blockName' => self::CART_BLOCK );

		$result = $registrar->gate_insertion( $parsed_block, self::CART_BLOCK, 'last_child', array( 'blockName' => 'a/anchor' ), null );

		$this->assertSame( $parsed_block, $result );
	}

	/**
	 * @testdox Should return null, so the block is not inserted at that anchor, when the anchor filter rejects the anchor block.
	 */
	public function test_gate_insertion_drops_block_when_anchor_filter_rejects_the_anchor(): void {
		$registrar = new HookedBlocksRegistrar(
			$this->insertions_with_anchor_filter(
				static function (): bool {
					return false;
				}
			)
		);

		$result = $registrar->gate_insertion( array( 'blockName' => self::CART_BLOCK ), self::CART_BLOCK, 'last_child', array( 'blockName' => 'a/anchor' ), null );

		$this->assertNull( $result );
	}

	/**
	 * @testdox Should decide by the enabled predicate alone when the insertion has no anchor filter.
	 */
	public function test_gate_insertion_ignores_anchor_when_entry_has_no_anchor_filter(): void {
		$registrar    = new HookedBlocksRegistrar( $this->insertions() );
		$parsed_block = array( 'blockName' => self::CART_BLOCK );

		$result = $registrar->gate_insertion( $parsed_block, self::CART_BLOCK, 'last_child', 'not-an-array', null );

		$this->assertSame( $parsed_block, $result );
	}

	/**
	 * @testdox Should return null without consulting the anchor filter when the anchor is not a parsed block array.
	 */
	public function test_gate_insertion_drops_block_without_consulting_filter_when_anchor_is_not_an_array(): void {
		$consulted = false;
		$registrar = new HookedBlocksRegistrar(
			$this->insertions_with_anchor_filter(
				static function () use ( &$consulted ): bool {
					$consulted = true;
					return true;
				}
			)
		);

		$result = $registrar->gate_insertion( array( 'blockName' => self::CART_BLOCK ), self::CART_BLOCK, 'last_child', null, null );

		$this->assertNull( $result );
		$this->assertFalse( $consulted, 'The anchor filter only judges parsed blocks' );
	}

	/**
	 * @testdox Should drop the block only when the context content already contains it: $label.
	 * @dataProvider context_provider
	 *
	 * @param string $label         What the context is.
	 * @param mixed  $context       The template, the pattern or whatever a third party passed.
	 * @param bool   $expected_kept Whether the block is kept.
	 */
	public function test_gate_insertion_drops_block_already_present_in_context( string $label, $context, bool $expected_kept ): void {
		unset( $label );
		$registrar    = new HookedBlocksRegistrar( $this->insertions() );
		$parsed_block = array( 'blockName' => self::CART_BLOCK );

		$result = $registrar->gate_insertion( $parsed_block, self::CART_BLOCK, 'last_child', null, $context );

		$this->assertSame( $expected_kept ? $parsed_block : null, $result );
	}

	/**
	 * Contexts with and without the cart block in their content.
	 *
	 * @return array
	 */
	public function context_provider(): array {
		$with_block    = '<!-- wp:' . self::CART_BLOCK . ' /-->';
		$without_block = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';

		$template_with          = new WP_Block_Template();
		$template_with->content = $with_block;

		$template_without          = new WP_Block_Template();
		$template_without->content = $without_block;

		return array(
			'template already containing the block'    => array( 'template with the block', $template_with, false ),
			'pattern already containing the block'     => array( 'pattern with the block', array( 'content' => $with_block ), false ),
			'template without the block'               => array( 'template without the block', $template_without, true ),
			'pattern without the block'                => array( 'pattern without the block', array( 'content' => $without_block ), true ),
			'pattern without content'                  => array( 'pattern without content', array(), true ),
			'context that is neither array nor object' => array( 'a string', 'unexpected', true ),
			'null context'                             => array( 'null', null, true ),
		);
	}
}
