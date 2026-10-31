<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFraudPreventionService class.
 */
class WooPaymentsFraudPreventionServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// The script registry is a global the base test case does not reset; a printed script also stays marked done.
			wp_dequeue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
			wp_deregister_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
			wp_scripts()->done = array_values( array_diff( wp_scripts()->done, array( WooPaymentsFraudPreventionService::TOKEN_NAME ) ) );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should generate and persist a fraud-prevention token in the WooCommerce session.
	 */
	public function test_get_token_generates_and_persists_session_token(): void {
		$session = $this->create_session();
		$sut     = $this->create_service( true, $session );

		$token = $sut->get_token();

		$this->assertSame( 16, strlen( $token ), 'Fraud-prevention tokens should match the extension token length.' );
		$this->assertSame( $token, $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
		$this->assertSame( $token, $sut->get_token(), 'Subsequent reads should reuse the session token.' );
	}

	/**
	 * @testdox Should verify only the exact session fraud-prevention token.
	 */
	public function test_verify_token_accepts_exact_session_token_only(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$sut = $this->create_service( true, $session );

		$this->assertTrue( $sut->verify_token( 'known-token' ) );
		$this->assertFalse( $sut->verify_token( 'tampered-token' ) );
		$this->assertFalse( $sut->verify_token( null ) );
	}

	/**
	 * @testdox Should regenerate the fraud-prevention token in the same session key.
	 */
	public function test_regenerate_token_replaces_session_token(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'old-token' );
		$sut = $this->create_service( true, $session );

		$new_token = $sut->regenerate_token();

		$this->assertSame( 16, strlen( $new_token ) );
		$this->assertNotSame( 'old-token', $new_token );
		$this->assertSame( $new_token, $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * @testdox Should read card-testing protection eligibility from cached WooPayments account data.
	 */
	public function test_is_enabled_reads_card_testing_protection_eligibility_from_account_cache(): void {
		$this->assertTrue( $this->create_service( true )->is_enabled() );
		$this->assertFalse( $this->create_service( false )->is_enabled() );
	}

	/**
	 * @testdox Should expose the token to shopper scripts once per page, only when protection applies.
	 */
	public function test_maybe_enqueue_token_script_is_a_guarded_singleton(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );

		$disabled = $this->create_service( false, $session );
		$disabled->maybe_enqueue_token_script();
		$this->assertFalse( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );

		$sut = $this->create_service( true, $session );
		$sut->maybe_enqueue_token_script();
		$this->assertTrue( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
		$this->assertStringContainsString( "window.wcpayFraudPreventionToken = 'known-token';", implode( '', array_filter( (array) $inline, 'is_string' ) ) );

		// A second owner calling again must not duplicate the inline script.
		$sut->maybe_enqueue_token_script();
		$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
		$this->assertCount( 1, array_filter( (array) $inline, 'is_string' ) );

		wp_dequeue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		wp_deregister_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
	}

	/**
	 * @testdox Should not expose the token on the checkout page without a WooCommerce session.
	 */
	public function test_maybe_enqueue_token_script_needs_a_session(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'card_testing_protection_eligible' => true ) );
		$sut = new WooPaymentsFraudPreventionService();
		$sut->init( $account_service );
		$wc_session   = WC()->session;
		WC()->session = null;

		try {
			$sut->maybe_enqueue_token_script();
		} finally {
			WC()->session = $wc_session;
		}

		$this->assertFalse( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
	}

	/**
	 * @testdox Should print the token in the footer where a Checkout or Cart block renders on an ordinary page, only while protection is on.
	 *
	 * The page is not a cart or checkout page and holds no block in its content, as when the block comes from a template
	 * assigned to it, so the page check does not match. The head prints before the block renders, as in a classic theme.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT, which would match the page check.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testWith ["woocommerce_blocks_enqueue_checkout_block_scripts_after", true]
	 *           ["woocommerce_blocks_enqueue_cart_block_scripts_after", true]
	 *           ["woocommerce_blocks_enqueue_checkout_block_scripts_after", false]
	 *           ["woocommerce_blocks_enqueue_cart_block_scripts_after", false]
	 *
	 * @param string $action        Block render action.
	 * @param bool   $protection_on Whether the account has card-testing protection on.
	 */
	public function test_block_render_actions_print_the_token_on_an_ordinary_page( string $action, bool $protection_on ): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$sut = $this->create_service( $protection_on, $session );
		$sut->register();
		$this->go_to_page( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );

		$sut->maybe_enqueue_token_script();
		$this->assertFalse( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ), 'The page check must not match an ordinary page.' );

		$this->print_scripts( 'wp_print_head_scripts' );
		do_action( $action );
		$footer = $this->print_scripts( 'wp_print_footer_scripts' );

		$this->assertSame( $protection_on ? 1 : 0, substr_count( $footer, "window.wcpayFraudPreventionToken = 'known-token';" ) );
	}

	/**
	 * @testdox Should not print the token where a block renders without a WooCommerce session.
	 */
	public function test_block_render_actions_need_a_session(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'card_testing_protection_eligible' => true ) );
		$sut = new WooPaymentsFraudPreventionService();
		$sut->init( $account_service );
		$sut->register();
		$wc_session   = WC()->session;
		WC()->session = null;

		try {
			do_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after' );
			do_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after' );
		} finally {
			WC()->session = $wc_session;
		}

		$this->assertFalse( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
	}

	/**
	 * @testdox Should print one token when the page check and a block render both ask for it.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $page_check_first Whether the page check runs before the block renders.
	 */
	public function test_page_check_and_block_render_print_one_token( bool $page_check_first ): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$sut = $this->create_service( true, $session );
		$sut->register();

		if ( $page_check_first ) {
			$sut->maybe_enqueue_token_script();
		}
		do_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after' );
		if ( ! $page_check_first ) {
			$sut->maybe_enqueue_token_script();
		}
		$footer = $this->print_scripts( 'wp_print_footer_scripts' );

		$this->assertCount( 1, array_filter( (array) wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' ), 'is_string' ) );
		$this->assertSame( 1, substr_count( $footer, 'window.wcpayFraudPreventionToken = ' ) );
	}

	/**
	 * @testdox Should expose the token on the add payment method page only while protection is on.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CHECKOUT, which would match the page without its add payment method term.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $protection_on Whether the account has card-testing protection on.
	 */
	public function test_add_payment_method_page_exposes_the_token_while_protection_is_on( bool $protection_on ): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$sut     = $this->create_service( $protection_on, $session );
		$page_id = $this->go_to_page( '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->' );
		update_option( 'woocommerce_myaccount_page_id', $page_id );
		$GLOBALS['wp']->query_vars['add-payment-method'] = '';
		$this->assertTrue( is_add_payment_method_page() );
		$this->assertFalse( is_checkout() );

		$sut->maybe_enqueue_token_script();

		$this->assertSame( $protection_on, wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		if ( $protection_on ) {
			$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
			$this->assertStringContainsString( "window.wcpayFraudPreventionToken = 'known-token';", implode( '', array_filter( (array) $inline, 'is_string' ) ) );
		}
	}

	/**
	 * Visit a published page with the given content, with WooCommerce's cart and checkout page checks reset.
	 *
	 * @param string $content Page content.
	 * @return int Page ID.
	 */
	private function go_to_page( string $content ): int {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
		$this->go_to( get_permalink( $page_id ) );

		return $page_id;
	}

	/**
	 * Run a script printing function and return its output.
	 *
	 * @param callable $printer Script printing function, such as `wp_print_footer_scripts`.
	 * @return string
	 */
	private function print_scripts( callable $printer ): string {
		ob_start();
		$printer();

		return (string) ob_get_clean();
	}

	/**
	 * Create a fraud-prevention service with controlled account eligibility.
	 *
	 * @param bool             $eligible Whether the account is eligible for card-testing protection.
	 * @param \WC_Session|null $session  Optional session.
	 * @return WooPaymentsFraudPreventionService
	 */
	private function create_service( bool $eligible, ?\WC_Session $session = null ): WooPaymentsFraudPreventionService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( array( 'card_testing_protection_eligible' => $eligible ) );

		$service = new WooPaymentsFraudPreventionService( $session ?? $this->create_session() );
		$service->init( $account_service );

		return $service;
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return \WC_Session
	 */
	private function create_session(): \WC_Session {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}
}
