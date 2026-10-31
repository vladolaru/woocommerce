<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsExpressCheckoutController class.
 */
class WooPaymentsExpressCheckoutControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsExpressCheckoutController|null
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsExpressCheckoutController ) {
			foreach ( $this->get_expected_frontend_hooks() as $hook => list( $method, $priority ) ) {
				remove_action( $hook, array( $this->sut, $method ), $priority );
			}
			remove_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) );
		}

		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		$this->set_order_pay_query_var( 0 );
		unset( $_GET['change_payment_method'] );
		wp_dequeue_script( 'wc-woopayments-express-checkout' );
		wp_dequeue_style( 'wc-woopayments-express-checkout' );
		wp_dequeue_script( 'wp-hooks' );
		wp_deregister_script( 'wc-woopayments-express-checkout' );
		wp_deregister_style( 'wc-woopayments-express-checkout' );
		wp_deregister_script( 'stripe' );
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * @dataProvider provider_runtime_ownership_hook_registration
	 * @testdox Should register the frontend hooks at their placement priorities ($_dataName).
	 *
	 * @param bool                    $payment_request_on        Whether payment request is available.
	 * @param array<string,int|false> $expected_priorities       Expected has_action() result per frontend hook.
	 * @param int|false               $expected_tracks_priority  Expected has_filter() result for the Tracks properties filter.
	 */
	public function test_registers_frontend_hooks( bool $payment_request_on, array $expected_priorities, $expected_tracks_priority ): void {
		$this->sut = $this->create_controller( $payment_request_on );

		$this->sut->register();

		foreach ( $this->get_expected_frontend_hooks() as $hook => list( $method ) ) {
			$this->assertSame( $expected_priorities[ $hook ], has_action( $hook, array( $this->sut, $method ) ), "Unexpected registration for {$hook}." );
		}
		$this->assertSame( $expected_tracks_priority, has_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) ) );
	}

	/**
	 * Payment request states. Registration does not wait on payment request settings.
	 *
	 * @return array<string,array{bool,array<string,int|false>,int|false}>
	 */
	public function provider_runtime_ownership_hook_registration(): array {
		$priorities = array_map(
			static function ( array $hook ): int {
				return $hook[1];
			},
			$this->get_expected_frontend_hooks()
		);

		return array(
			'owner, payment request on'  => array( true, $priorities, 10 ),
			'owner, payment request off' => array( false, $priorities, 10 ),
		);
	}

	/**
	 * @testdox Should enqueue separate express checkout assets and localize ECE params on checkout.
	 */
	public function test_enqueue_frontend_assets_loads_separate_express_checkout_bundle(): void {
		$this->sut = $this->create_controller( true );
		$this->set_checkout_shortcode_page();

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertSame( 'https://js.stripe.com/v3/', wp_scripts()->registered['stripe']->src );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( 'var wcpayExpressCheckoutParams', $localized_data );
		$this->assertStringContainsString( '"enabled_methods":["payment_request"]', $localized_data );
		$this->assertContains( 'wp-hooks', wp_scripts()->registered['wc-woopayments-express-checkout']->deps, 'The extension compatibility filters need wp-hooks on checkout too.' );
		$this->assertContains( 'wp-a11y', wp_scripts()->registered['wc-woopayments-express-checkout']->deps, 'A click on the refresh overlay is announced through wp.a11y (client 11.2.0 shortcode-buttons-express/index.js:67-80).' );
	}

	/**
	 * @testdox Should enqueue express checkout assets on custom checkout shortcode pages.
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_loads_on_checkout_shortcode_page(): void {
		$this->sut = $this->create_controller( true );
		$page_id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
		$this->assertNotSame( $page_id, wc_get_page_id( 'checkout' ), 'Precondition: not the store checkout page.' );
		$this->assertFalse( defined( 'WOOCOMMERCE_CHECKOUT' ), 'Precondition: no inherited checkout constant.' );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should not load express checkout on an archive whose term ID equals a checkout page's post ID.
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_skips_an_archive_whose_term_id_matches_a_checkout_page(): void {
		$this->sut = $this->create_controller( true );
		$term_id   = self::factory()->category->create( array( 'name' => 'Empty archive' ) );
		// A checkout page whose post ID is the category's term ID (import_id asks wp_insert_post() for that ID).
		$page_id = wp_insert_post(
			array(
				'import_id'    => $term_id,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Checkout with the term ID',
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$this->assertSame( $term_id, $page_id, 'The fixture needs a page whose ID equals the term ID.' );
		$this->go_to( get_category_link( $term_id ) );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * Client 11.2.0 loads no express checkout on a subscription's change payment method page
	 * (class-wc-payments-express-checkout-button-handler.php:83-86). On that page the order-pay route names a subscription
	 * that needs payment (an unpaid parent or last renewal), so the service would offer the button; it stands in here.
	 *
	 * @testdox Should enqueue express checkout assets with pay-for-order context on $scenario.
	 * @testWith ["an order-pay page", false, true]
	 *           ["the change payment method page, with none", true, false]
	 *
	 * @param string $scenario              Scenario description.
	 * @param bool   $change_payment_method Whether the request carries change_payment_method.
	 * @param bool   $loaded                Whether the assets load and the params are localized.
	 */
	public function test_enqueue_frontend_assets_on_order_pay_pages( string $scenario, bool $change_payment_method, bool $loaded ): void {
		unset( $scenario );
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, $service );
		$this->set_checkout_shortcode_page();
		$this->set_order_pay_query_var( 123 );
		if ( $change_payment_method ) {
			$_GET['change_payment_method'] = '123';
		}

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( $loaded, wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertSame( $loaded, wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertSame( $loaded ? array( 'pay_for_order' ) : array(), $service->contexts );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		if ( $loaded ) {
			$this->assertIsString( $localized_data );
			$this->assertStringContainsString( '"button_context":"pay_for_order"', $localized_data );
		} else {
			$this->assertFalse( $localized_data );
		}
	}

	/**
	 * @testdox Should enqueue express checkout assets with product context on product pages.
	 */
	public function test_enqueue_frontend_assets_loads_on_product_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, $service );
		$this->set_current_product();

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-hooks', 'enqueued' ) );
		$this->assertSame( array( 'product' ), $service->contexts );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"button_context":"product"', $localized_data );
		$this->assertStringContainsString( '"label":"Merchant (via WooCommerce)"', $localized_data );
	}

	/**
	 * @testdox Should not enqueue classic ECE assets on checkout block pages.
	 */
	public function test_enqueue_frontend_assets_skips_checkout_block_pages(): void {
		$this->sut = $this->create_controller( true );
		$page_id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * Pages whose block or shortcode the password form, or a `<!--nextpage-->` break, keeps off the rendered content, and
	 * whether the classic express script loads there, with which button context.
	 *
	 * The page content is read raw, as has_block() reads it, and the checkout and product shortcodes count on the visited
	 * page itself. The cart page loads the script unless its Cart block excludes it, so its rows fail when only the Cart block
	 * term misreads the page.
	 *
	 * @return array<string,array{0:string,1:bool,2:string,3:string|null}>
	 */
	public function provide_pages_whose_content_the_rendered_page_hides(): array {
		$cart_block     = '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->';
		$checkout_block = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->';
		$page_break     = '<!-- wp:paragraph --><p>First page</p><!-- /wp:paragraph --><!--nextpage-->';

		return array(
			'password cart page holding the Cart block' => array( $cart_block, true, 'cart page', null ),
			'cart page with the Cart block after a page break' => array( $page_break . $cart_block, false, 'cart page', null ),
			'password checkout page holding the Checkout block' => array( $checkout_block, true, 'checkout page', null ),
			'checkout page with the Checkout block after a page break' => array( $page_break . $checkout_block, false, 'checkout page', null ),
			'password page with the checkout shortcode' => array( '[woocommerce_checkout]', true, 'page', 'checkout' ),
			'checkout shortcode after a page break'     => array( $page_break . '[woocommerce_checkout]', false, 'page', 'checkout' ),
			'password page with a product shortcode'    => array( '[product_page id="1"]', true, 'page', 'product' ),
			'product shortcode after a page break'      => array( $page_break . '[product_page id="1"]', false, 'page', 'product' ),
		);
	}

	/**
	 * @testdox Should read the raw page content for the Blocks exclusion and the shortcode surfaces: $_dataName.
	 * @dataProvider provide_pages_whose_content_the_rendered_page_hides
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string      $content          Post content.
	 * @param bool        $password         Whether the post is password protected.
	 * @param string      $request          `checkout page` or `cart page`: the post is that store page, visited. `page`: a
	 *                                      page that is neither, visited.
	 * @param string|null $expected_context Button context the express script loads with, or null when it does not load.
	 */
	public function test_enqueue_frontend_assets_reads_the_raw_page_content( string $content, bool $password, string $request, ?string $expected_context ): void {
		$page_options = array(
			'checkout page' => 'woocommerce_checkout_page_id',
			'cart page'     => 'woocommerce_cart_page_id',
		);
		$service      = new RecordingExpressCheckoutService();
		$this->sut    = $this->create_controller( true, $service );
		$post_id      = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_content'  => $content,
				'post_password' => $password ? 'secret' : '',
			)
		);
		if ( isset( $page_options[ $request ] ) ) {
			update_option( $page_options[ $request ], $post_id );
		}
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'cart page' === $request, is_cart(), 'Precondition: only the store cart page is a cart.' );
		$this->assertSame( $password, post_password_required( $post_id ), 'Precondition: the password form renders instead of the content.' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( null !== $expected_context, wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertSame( null === $expected_context ? array() : array( $expected_context ), $service->contexts );
	}

	/**
	 * Pages whose checkout or product shortcode lives outside the visited page itself: in the post an archive's loop holds,
	 * or escaped as `[[product_page]]`, which WordPress prints as text. Client 11.2.0 reads only the main query's page and
	 * skips escaped tags (class-wc-payments-express-checkout-button-helper.php:255-259, :284-286, :975-1031).
	 *
	 * @return array<string,array{0:string|null,1:string|null,2:string|null,3:string|null}>
	 */
	public function provide_shortcodes_outside_the_visited_page(): array {
		return array(
			"checkout shortcode in an archive's loop post" => array( null, null, '[woocommerce_checkout]', null ),
			"product shortcode in an archive's loop post"  => array( null, null, '[product_page id="1"]', null ),
			'cart page whose loop post holds a product shortcode' => array( '[woocommerce_cart]', 'woocommerce_cart_page_id', '[product_page id="1"]', 'cart' ),
			'escaped product shortcode on a page'          => array( '[[product_page id="1"]]', null, null, null ),
			'product shortcode after an escaped one on a page' => array( '[[product_page id="1"]] [product_page id="1"]', null, null, 'product' ),
		);
	}

	/**
	 * @testdox Should read the checkout and product shortcodes from the visited page only, skipping escaped tags: $_dataName.
	 * @dataProvider provide_shortcodes_outside_the_visited_page
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string|null $page_content     Content of the visited page, or null for a search page.
	 * @param string|null $page_option      WooCommerce page option pointing at the visited page, or null for none.
	 * @param string|null $loop_content     Content of a post the global loop holds, or null for none.
	 * @param string|null $expected_context Button context the express script loads with, or null when it does not load.
	 */
	public function test_enqueue_frontend_assets_reads_only_the_visited_page( ?string $page_content, ?string $page_option, ?string $loop_content, ?string $expected_context ): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, $service );
		$this->reset_frontend_surface_state();
		if ( null === $page_content ) {
			$this->go_to( home_url( '/?s=nothing-matches' ) );
			$this->assertFalse( is_singular(), 'Precondition: not a singular view.' );
		} else {
			$page_id = self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => $page_content,
				)
			);
			if ( null !== $page_option ) {
				update_option( $page_option, $page_id );
			}
			$this->reset_cart_checkout_page_cache();
			$this->go_to( get_permalink( $page_id ) );
		}
		if ( null !== $loop_content ) {
			$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_content' => $loop_content ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post of the page's loop.
		}
		$this->assertSame( null !== $page_option, is_cart(), 'Precondition: only the store cart page is a cart.' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( null !== $expected_context, wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		// Joined, because an array comparison failure cannot travel back from the separate process.
		$this->assertSame( (string) $expected_context, implode( ',', $service->contexts ) );
	}

	/**
	 * @testdox Should render the WooPay button, then the ECE container, then one OR separator in one wrapper on checkout.
	 */
	public function test_display_express_checkout_buttons_renders_woopay_and_ece_in_one_wrapper(): void {
		$this->sut = $this->create_controller( true, null, '<div id="wcpay-woopay-button"></div>' );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		// Client 11.1.0 class-wc-payments-express-checkout-button-display-handler.php:134-149.
		$this->assertSame(
			'<div class="wcpay-express-checkout-wrapper"><div id="wcpay-woopay-button"></div><div id="wcpay-express-checkout-element"></div>' .
			'<p id="wcpay-express-checkout-button-separator" style="margin-top:1.5em;text-align:center;">&mdash; OR &mdash;</p></div>',
			$output
		);
	}

	/**
	 * @testdox Should start the OR separator hidden when no WooPay button shows, for the wallet to reveal.
	 */
	public function test_display_express_checkout_buttons_starts_separator_hidden_without_woopay(): void {
		$this->sut = $this->create_controller( true );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		// Client 11.1.0 class-wc-payments-express-checkout-button-display-handler.php:115, :131.
		$this->assertStringContainsString( '<p id="wcpay-express-checkout-button-separator" style="margin-top:1.5em;text-align:center;" hidden>', $output );
		$this->assertSame( 1, substr_count( $output, 'wcpay-express-checkout-button-separator' ) );
	}

	/**
	 * @testdox Should render the WooPay button and its separator when the Express Checkout Element is off.
	 */
	public function test_display_express_checkout_buttons_renders_woopay_alone_when_ece_is_off(): void {
		$this->sut = $this->create_controller( false, null, '<div id="wcpay-woopay-button"></div>' );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wcpay-express-checkout-wrapper"><div id="wcpay-woopay-button"></div><p id="wcpay-express-checkout-button-separator"', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-element', $output );
		$this->assertStringNotContainsString( ' hidden>', $output );
	}

	/**
	 * @testdox Should render the ECE container and the OR separator with pay-for-order context on order-pay pages.
	 */
	public function test_display_express_checkout_buttons_renders_on_order_pay_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, $service );
		$this->set_checkout_shortcode_page();
		$this->set_order_pay_query_var( 123 );

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-express-checkout-wrapper', $output );
		// Client 11.1.0 renders it wherever is_checkout() holds, which includes order-pay
		// (class-wc-payments-express-checkout-button-display-handler.php:112-118; recorded on a local client 11.1.0
		// order-pay page: a centred "— OR —" below the buttons).
		$this->assertStringContainsString( '<p id="wcpay-express-checkout-button-separator" style="margin-top:1.5em;text-align:center;" hidden>', $output );
		$this->assertSame( array( 'pay_for_order' ), $service->contexts );
	}

	/**
	 * @testdox Should render the ECE container with product context on product pages.
	 */
	public function test_display_express_checkout_buttons_renders_on_product_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, $service );
		$this->set_current_product();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-express-checkout-wrapper', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
		$this->assertSame( array( 'product' ), $service->contexts );
	}

	/**
	 * @testdox Should use the live SKU shortcode product for localized data and the rendered Express Checkout container.
	 */
	public function test_product_page_shortcode_with_sku_uses_live_product_for_express_checkout(): void {
		$escaped_product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Escaped Widget',
				'price'         => '9.87',
				'regular_price' => '9.87',
				'virtual'       => true,
			)
		);
		$live_product    = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Live SKU Widget',
				'price'         => '12.34',
				'regular_price' => '12.34',
				'virtual'       => true,
			)
		);
		$live_product->set_sku( 'live-shortcode-sku' );
		$live_product->save();

		$content = '[[product_page id="' . $escaped_product->get_id() . '"]] [product_page sku="live-shortcode-sku"]';
		$this->set_current_page_with_content( $content );
		unset( $GLOBALS['product'] );

		$this->sut = $this->create_controller( true, $this->create_real_service() );
		$this->sut->enqueue_frontend_assets();

		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"label":"Live SKU Widget"', $localized_data );
		$this->assertStringContainsString( '"amount":1234', $localized_data );
		$this->assertStringNotContainsString( 'Escaped Widget', $localized_data );

		$this->sut->register();
		$this->setExpectedDeprecated( 'Theme without comments.php' );
		$output = do_shortcode( $content );

		$this->assertStringContainsString( 'id="wcpay-express-checkout-element"', $output );
	}

	/**
	 * @testdox Should render no ECE container or assets when payment request is disabled.
	 */
	public function test_display_express_checkout_buttons_renders_nothing_when_payment_request_is_disabled(): void {
		$this->sut = $this->create_controller( false );
		$this->set_checkout_shortcode_page();

		$this->sut->enqueue_frontend_assets();
		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertFalse( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should mark Apple Pay and Google Pay load/click events as all-store Tracks events.
	 */
	public function test_add_tracking_event_properties_marks_platform_wallet_events_as_all_store_events(): void {
		$this->sut = $this->create_controller( true );

		$this->assertSame(
			array( 'record_event_data' => array( 'track_on_all_stores' => true ) ),
			$this->sut->add_tracking_event_properties( array(), 'wcpay_applepay_button_load' )
		);
		$this->assertSame(
			array( 'record_event_data' => array( 'track_on_all_stores' => true ) ),
			$this->sut->add_tracking_event_properties( array(), 'wcpay_gpay_button_click' )
		);
		$this->assertSame( array(), $this->sut->add_tracking_event_properties( array(), 'wcpay_woopay_button_click' ) );
	}

	/**
	 * @dataProvider provide_valid_ingress_redirect_targets
	 * @testdox Should stash each valid express checkout redirect target exactly once and only redirect to My Account.
	 *
	 * @param string $target       Expected stashed target.
	 * @param bool   $allow_host  Whether the target host is explicitly allowed.
	 */
	public function test_handle_express_checkout_redirect_stashes_valid_targets_once( string $target, bool $allow_host ): void {
		$this->sut = $this->create_controller( true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( $target );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		$allowed_hosts = null;
		if ( $allow_host ) {
			$allowed_hosts = static function ( array $hosts ): array {
				$hosts[] = 'allowed.example';
				return $hosts;
			};
			add_filter( 'allowed_redirect_hosts', $allowed_hosts );
		}

		try {
			$this->sut->handle_express_checkout_redirect();

			$nonempty_cookie_values = array_values(
				array_filter(
					$cookie_values,
					static function ( string $value ): bool {
						return '' !== $value;
					}
				)
			);
			$this->assertSame( array( $target ), $nonempty_cookie_values );
			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
		} finally {
			if ( $allow_host && null !== $allowed_hosts ) {
				remove_filter( 'allowed_redirect_hosts', $allowed_hosts );
			}
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * Provide valid ingress redirect targets.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provide_valid_ingress_redirect_targets(): array {
		return array(
			'local absolute target'   => array( home_url( '/checkout/' ), false ),
			'relative target'         => array( '/checkout/', false ),
			'allowed external target' => array( 'https://allowed.example/checkout/', true ),
			'retained percent escape' => array( home_url( '/checkout/?next=%2Faccount' ), false ),
		);
	}

	/**
	 * @dataProvider provider_rejected_ingress_redirect_targets
	 * @testdox Should redirect to My Account without persisting a rejected redirect target ($_dataName).
	 *
	 * @param mixed  $query_value     Raw redirect query value.
	 * @param string $rejected_target Target that must never reach the cookie.
	 */
	public function test_handle_express_checkout_redirect_does_not_persist_rejected_target( $query_value, string $rejected_target ): void {
		$this->sut = $this->create_controller( true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = $query_value;
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		try {
			$this->sut->handle_express_checkout_redirect();

			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
			$this->assertNotContains( $rejected_target, $cookie_values );
			$this->assertSame( array(), array_values( array_filter( $cookie_values ) ) );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * Redirect query values the controller must not stash.
	 *
	 * @return array<string,array{mixed,string}>
	 */
	public function provider_rejected_ingress_redirect_targets(): array {
		return array(
			'external'        => array( rawurlencode( 'https://evil.example/phish' ), 'https://evil.example/phish' ),
			'scheme-relative' => array( rawurlencode( '//evil.example/phish' ), '//evil.example/phish' ),
			'non-string'      => array( array( 'https://evil.example/phish' ), 'https://evil.example/phish' ),
		);
	}

	/**
	 * @testdox Should not redirect on an invalid nonce.
	 */
	public function test_handle_express_checkout_redirect_ignores_invalid_nonce(): void {
		$this->sut = $this->create_controller( true );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( home_url( '/checkout/' ) );
		$_GET['_wpnonce']                            = 'invalid';

		$captured_redirect = null;
		$capture_redirect  = static function ( $location ) use ( &$captured_redirect ) {
			$captured_redirect = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		$this->sut->handle_express_checkout_redirect();

		$this->assertNull( $captured_redirect );

		remove_filter( 'wp_redirect', $capture_redirect );
		unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
	}

	/**
	 * @dataProvider provide_valid_cookie_redirect_targets
	 * @testdox Should preserve valid cookie target $target byte-for-byte and clear it exactly once ($_dataName).
	 *
	 * @param string $target     Valid cookie redirect target.
	 * @param bool   $allow_host Whether the target host is explicitly allowed.
	 * @param string $filter     Core redirect filter to reach the controller through after register(), or '' to call it directly.
	 */
	public function test_consume_login_redirect_url_preserves_valid_cookie_and_clears_it_once( string $target, bool $allow_host, string $filter ): void {
		$this->sut = $this->create_controller( true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = $target;
		$cookie_values                                  = array();
		$capture_cookie                                 = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$allowed_hosts = null;
		if ( $allow_host ) {
			$allowed_hosts = static function ( array $hosts ): array {
				$hosts[] = 'allowed.example';
				return $hosts;
			};
			add_filter( 'allowed_redirect_hosts', $allowed_hosts );
		}

		try {
			if ( '' === $filter ) {
				$result = $this->sut->consume_login_redirect_url( home_url( '/my-account/' ) );
			} else {
				$this->sut->register();
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core's hook, fired as WC_Form_Handler does after login or registration.
				$result = apply_filters( $filter, home_url( '/my-account/' ) );
			}

			$this->assertSame( $target, $result );
			$this->assertSame( array( '' ), $cookie_values );
		} finally {
			if ( $allow_host && null !== $allowed_hosts ) {
				remove_filter( 'allowed_redirect_hosts', $allowed_hosts );
			}
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * Provide valid cookie redirect targets. The filter rows are the shopper who logs in or registers from the express
	 * checkout login dialog and returns to the product or cart page it stashed (WC_Form_Handler::process_login() and
	 * process_registration()).
	 *
	 * @return array<string,array{string,bool,string}>
	 */
	public function provide_valid_cookie_redirect_targets(): array {
		return array(
			'local absolute target retains percent escape' => array( home_url( '/checkout/?next=%2Faccount' ), false, '' ),
			'relative target'                              => array( '/checkout/', false, '' ),
			'allowed external target'                      => array( 'https://allowed.example/checkout/', true, '' ),
			'product page through the login filter'        => array( home_url( '/product/beanie/' ), false, 'woocommerce_login_redirect' ),
			'cart page through the registration filter'    => array( home_url( '/cart/' ), false, 'woocommerce_registration_redirect' ),
		);
	}

	/**
	 * @dataProvider provider_login_redirect_fallbacks
	 * @testdox Should return the caller's fallback, of any type, when the cookie is absent or invalid, and clear an invalid cookie once ($_dataName).
	 *
	 * @param bool              $has_cookie            Whether the redirect cookie is present.
	 * @param mixed             $cookie_value          Redirect cookie value.
	 * @param mixed             $fallback              Caller-provided fallback.
	 * @param array<int,string> $expected_cookie_values Expected target-cookie writes.
	 */
	public function test_consume_login_redirect_url_returns_the_fallback_for_an_absent_or_invalid_cookie( bool $has_cookie, $cookie_value, $fallback, array $expected_cookie_values ): void {
		$this->sut = $this->create_controller( true );

		if ( $has_cookie ) {
			$_COOKIE['wcpay_express_checkout_redirect_url'] = $cookie_value;
		} else {
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		try {
			$result = $this->sut->consume_login_redirect_url( $fallback );

			$this->assertSame( $expected_cookie_values, $cookie_values );
			$this->assertSame( $fallback, $result );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * Absent or invalid redirect cookies, with string and non-string caller fallbacks.
	 *
	 * @return array<string,array{bool,mixed,mixed,array<int,string>}>
	 */
	public function provider_login_redirect_fallbacks(): array {
		return array(
			'absent cookie with array fallback'   => array( false, null, array( 'fallback' ), array() ),
			'invalid cookie with object fallback' => array( true, 'https://evil.example/phish', new \stdClass(), array( '' ) ),
			'absent cookie, string fallback'      => array( false, null, home_url( '/my-account/' ), array() ),
			'external cookie'                     => array( true, 'https://evil.example/phish', home_url( '/my-account/' ), array( '' ) ),
			'array cookie'                        => array( true, array( 'https://evil.example/phish' ), home_url( '/my-account/' ), array( '' ) ),
			'scheme-relative cookie'              => array( true, '//evil.example/phish', home_url( '/my-account/' ), array( '' ) ),
		);
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool                                   $payment_request_on Whether payment request should be available.
	 * @param WooPaymentsExpressCheckoutService|null $service            Optional service double.
	 * @param string                                 $woopay_button      WooPay placeholder markup the WooPay controller returns.
	 * @return WooPaymentsExpressCheckoutController
	 */
	private function create_controller( bool $payment_request_on, ?WooPaymentsExpressCheckoutService $service = null, string $woopay_button = '' ): WooPaymentsExpressCheckoutController {
		$service = $service ?? new RecordingExpressCheckoutService();
		if ( $service instanceof RecordingExpressCheckoutService ) {
			$service->should_show_payment_request_button = $payment_request_on;
		}

		$woopay_controller = $this->createStub( SessionController::class );
		$woopay_controller->method( 'get_express_checkout_button_html' )->willReturn( $woopay_button );

		$controller = new WooPaymentsExpressCheckoutController();
		$controller->init( $service, $this->createStub( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService::class ), $woopay_controller, new WooPaymentsFrontendAssets() );

		return $controller;
	}

	/**
	 * Create the real Express Checkout service for controller integration tests.
	 *
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_real_service(): WooPaymentsExpressCheckoutService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'is_test_mode_enabled', 'get_gateway_setting', 'is_payment_request_enabled' ) )
			->getMock();
		$account_service->method( 'get_account_id' )->willReturn( 'acct_123' );
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'          => 'US',
				'payments_enabled' => true,
				'capabilities'     => array(),
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				$settings = array(
					'manual_capture'                   => 'no',
					'payment_request_button_type'      => 'default',
					'payment_request_button_theme'     => 'dark',
					'payment_request_button_size'      => 'medium',
					'express_checkout_product_methods' => array( 'payment_request' ),
				);

				return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
			}
		);

		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );

		$service = new WooPaymentsExpressCheckoutService();
		$service->init( $account_service, $provider, $tracking_controller );

		return $service;
	}

	/**
	 * Get expected frontend hooks, callbacks and priorities. The button priorities are the client 11.1.0 ones
	 * (class-wc-payments-express-checkout-button-display-handler.php:93-96); 21 puts the cart buttons below
	 * WooCommerce's "Proceed to checkout" button (priority 20).
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	private function get_expected_frontend_hooks(): array {
		return array(
			'wp_enqueue_scripts'                           => array( 'enqueue_frontend_assets', 10 ),
			'woocommerce_after_add_to_cart_form'           => array( 'display_express_checkout_buttons', 1 ),
			'woocommerce_checkout_before_customer_details' => array( 'display_express_checkout_buttons', 1 ),
			'woocommerce_proceed_to_checkout'              => array( 'display_express_checkout_buttons', 21 ),
			'woocommerce_pay_order_before_payment'         => array( 'display_express_checkout_buttons', 1 ),
		);
	}

	/**
	 * Set the current order-pay query var.
	 *
	 * @param int $order_id Order ID.
	 */
	private function set_order_pay_query_var( int $order_id ): void {
		global $wp;

		if ( ! is_object( $wp ) ) {
			$wp = new \WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( $order_id > 0 ) {
			$wp->query_vars['order-pay'] = $order_id;
			return;
		}

		unset( $wp->query_vars['order-pay'] );
	}

	/**
	 * Set the current request to a classic checkout shortcode page.
	 */
	private function set_checkout_shortcode_page(): void {
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_cart_page_id' );

		update_option( 'woocommerce_checkout_page_id', $this->set_current_page_with_content( '[woocommerce_checkout]' ) );
	}

	/**
	 * Set the current request to a product page.
	 */
	private function set_current_product(): void {
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_cart_page_id' );

		$product = \WC_Helper_Product::create_simple_product( true );
		global $post;
		$post               = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $product;
		$this->go_to( get_permalink( $product->get_id() ) );
		setup_postdata( $post );
		add_filter( 'woocommerce_is_product', '__return_true' );
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		wp_reset_postdata();
		$this->set_order_pay_query_var( 0 );
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Set the current request to a page containing the given content.
	 *
	 * @param string $content Page content.
	 * @return int
	 */
	private function set_current_page_with_content( string $content ): int {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		global $post;
		$this->go_to( get_permalink( $page_id ) );
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		return $page_id;
	}
}
