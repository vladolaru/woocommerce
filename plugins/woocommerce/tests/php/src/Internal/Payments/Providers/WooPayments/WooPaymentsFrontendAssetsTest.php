<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use WC_Unit_Test_Case;

/**
 * Tests for the shared WooPayments frontend assets and the current-page lookup.
 */
class WooPaymentsFrontendAssetsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsFrontendAssets
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsFrontendAssets();
	}

	/**
	 * Restore the global post and the shared script handles after each test.
	 */
	public function tearDown(): void {
		wp_reset_postdata();
		wp_deregister_script( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::FINGERPRINT_SCRIPT_HANDLE );
		parent::tearDown();
	}

	/**
	 * @testdox Should register the $handle handle with its own source, and keep a registration made before it.
	 * @testWith ["register_stripe_script", "stripe", "https://js.stripe.com/v3/"]
	 *           ["register_appearance_script", "wc-woopayments-appearance", "/assets/js/frontend/utils/woopayments-appearance"]
	 *           ["register_fingerprint_script", "wc-woopayments-fingerprintjs", "/assets/js/fingerprintjs/fp.umd.min.js"]
	 *
	 * @param string $method Registration method.
	 * @param string $handle Script handle it registers.
	 * @param string $source Part of the script source it registers.
	 */
	public function test_registers_each_shared_handle_unless_already_registered( string $method, string $handle, string $source ): void {
		wp_deregister_script( $handle );

		$this->sut->$method();

		$this->assertTrue( wp_script_is( $handle, 'registered' ) );
		$this->assertStringContainsString( $source, wp_scripts()->registered[ $handle ]->src );

		wp_deregister_script( $handle );
		wp_register_script( $handle, 'https://example.com/registered-first.js', array(), '1.0', true );

		$this->sut->$method();

		$this->assertSame( 'https://example.com/registered-first.js', wp_scripts()->registered[ $handle ]->src, 'Another plugin registered the handle first.' );
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
	 * @testdox Inside a secondary loop the loop's post answers, as the WooPayments extension's has_block() without a post does.
	 */
	public function test_secondary_loop_post_answers_like_has_block(): void {
		$host_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$loop_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		$this->go_to( get_permalink( $host_id ) );
		$GLOBALS['post'] = get_post( $loop_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post of a secondary loop.

		$this->assertSame( has_block( 'woocommerce/checkout' ), WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' ) );
		$this->assertTrue( WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' ) );
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
