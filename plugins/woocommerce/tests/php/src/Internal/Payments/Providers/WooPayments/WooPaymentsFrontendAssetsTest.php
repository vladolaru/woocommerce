<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use WP_UnitTestCase;

/**
 * Tests for the shared WooPayments frontend assets and the current-surface lookup.
 */
class WooPaymentsFrontendAssetsTest extends WP_UnitTestCase {

	/**
	 * Restore the global post after each test.
	 */
	public function tearDown(): void {
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * @testdox The page the request renders answers the block and shortcode checks.
	 */
	public function test_current_post_is_the_queried_page(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->[product_page id="1"]',
			)
		);
		$this->go_to( get_permalink( $page_id ) );

		$this->assertSame( $page_id, WooPaymentsFrontendAssets::get_current_post()->ID );
		$this->assertTrue( WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' ) );
		$this->assertFalse( WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/cart' ) );
		$this->assertTrue( WooPaymentsFrontendAssets::current_post_has_shortcode( 'product_page' ) );
	}

	/**
	 * @testdox An archive is not mistaken for the page whose ID equals the archive term's ID.
	 */
	public function test_archive_term_id_is_not_read_as_a_post_id(): void {
		$term_id = self::factory()->category->create( array( 'name' => 'Empty archive' ) );
		// A checkout page whose post ID is the category's term ID (import_id asks wp_insert_post() for that ID).
		$page_id = wp_insert_post(
			array(
				'import_id'    => $term_id,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Checkout with the term ID',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		$this->assertSame( $term_id, $page_id, 'The fixture needs a page whose ID equals the term ID.' );

		$this->go_to( get_category_link( $term_id ) );

		$this->assertSame( $term_id, get_queried_object_id() );
		$this->assertNull( WooPaymentsFrontendAssets::get_current_post() );
		$this->assertFalse( WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' ) );
	}

	/**
	 * @testdox Without a queried post, the global post answers, as has_block() without a post does.
	 */
	public function test_global_post_answers_without_a_queried_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->',
			)
		);
		$this->go_to( home_url( '/?s=nothing-matches' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The global post of a loop.

		$this->assertSame( $post_id, WooPaymentsFrontendAssets::get_current_post()->ID );
		$this->assertTrue( WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/cart' ) );
	}
}
