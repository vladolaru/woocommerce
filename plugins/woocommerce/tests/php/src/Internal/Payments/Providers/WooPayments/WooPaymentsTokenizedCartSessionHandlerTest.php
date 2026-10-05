<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments tokenized cart session handler.
 */
class WooPaymentsTokenizedCartSessionHandlerTest extends WC_Unit_Test_Case {

	/**
	 * WooCommerce session before the test.
	 *
	 * @var object|null
	 */
	private $original_session;

	/**
	 * Handlers initialized by a test, whose shutdown save is removed afterwards.
	 *
	 * @var WooPaymentsTokenizedCartSessionHandler[]
	 */
	private array $handlers = array();

	/**
	 * Log lines written during the test.
	 *
	 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
	 */
	private array $log_lines = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_session = WC()->session;
		add_filter( 'woocommerce_logger_log_message', array( $this, 'record_log_line' ), 10, 3 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->handlers as $handler ) {
			remove_action( 'shutdown', array( $handler, 'save_data' ), 20 );
		}
		WC()->session = $this->original_session;
		wc_empty_cart();
		remove_filter( 'woocommerce_logger_log_message', array( $this, 'record_log_line' ), 10 );
		remove_all_filters( 'woocommerce_session_handler' );
		remove_all_filters( 'wcpay_dev_mode' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_bacs_settings' );
		WC()->payment_gateways()->init();
		unset(
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_IS_EPHEMERAL_CART'],
			$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ]
		);
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Record a log line.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Level.
	 * @param array<string,mixed> $context Context.
	 * @return string
	 */
	public function record_log_line( $message, $level, $context ) {
		$this->log_lines[] = array(
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => is_array( $context ) ? $context : array(),
		);

		return $message;
	}

	/**
	 * @testdox Should create an isolated guest session when no tokenized session header is present.
	 */
	public function test_creates_isolated_guest_session_without_cookie_header(): void {
		$handler = new WooPaymentsTokenizedCartSessionHandler();
		$handler->init();

		$this->assertStringStartsWith( 't_', $handler->get_customer_id() );
		$this->assertSame( array(), $handler->get( 'cart' ) );
	}

	/**
	 * @testdox Should create an isolated tokenized guest session for logged-in shoppers.
	 */
	public function test_creates_isolated_guest_session_for_logged_in_shopper(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$handler = new WooPaymentsTokenizedCartSessionHandler();
		$handler->init();

		$this->assertNotSame( (string) $user_id, $handler->get_customer_id() );
		$this->assertStringStartsWith( 't_', $handler->get_customer_id() );
	}

	/**
	 * @testdox Should restore the isolated session from a valid tokenized session header.
	 */
	public function test_restores_isolated_session_from_valid_token(): void {
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'] = JsonWebToken::create(
			array(
				'session_id' => 't_existing_native_session',
				'exp'        => time() + HOUR_IN_SECONDS,
				'iss'        => 'woopayments/product-page',
			),
			'@' . wp_salt()
		);

		$handler = new WooPaymentsTokenizedCartSessionHandler();
		$handler->init();

		$this->assertSame( 't_existing_native_session', $handler->get_customer_id() );
		$this->assertSame( array(), $handler->get( 'cart' ) );
	}

	/**
	 * @testdox Should save and reload tokenized cart data by tokenized session ID.
	 */
	public function test_saves_and_reloads_isolated_session_data(): void {
		$handler = new WooPaymentsTokenizedCartSessionHandler();
		$handler->init();
		$session_id = $handler->get_customer_id();

		$handler->set( 'cart', array( 'test-key' => array( 'quantity' => 2 ) ) );
		$handler->save_data();

		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'] = JsonWebToken::create(
			array(
				'session_id' => $session_id,
				'exp'        => time() + HOUR_IN_SECONDS,
				'iss'        => 'woopayments/product-page',
			),
			'@' . wp_salt()
		);

		$loaded = new WooPaymentsTokenizedCartSessionHandler();
		$loaded->init();

		$this->assertSame( array( 'test-key' => array( 'quantity' => 2 ) ), $loaded->get( 'cart' ) );
	}

	/**
	 * @testdox A Store API checkout that creates an account keeps paying from the tokenized session and leaves the shopper's browser session alone.
	 *
	 * Store API Checkout::process_customer() logs the new customer in through wc_set_customer_auth_cookie(), which calls
	 * WC()->session->init_session_cookie() (wc-user-functions.php:312-319).
	 */
	public function test_store_api_checkout_creating_an_account_keeps_the_tokenized_session(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();
		$product = \WC_Helper_Product::create_simple_product( true, array( 'virtual' => true ) );

		// The shopper's browser session: a guest row with its own cart and the cookie that names it. Core's cookie is
		// "customer|expiration|expiring|hash", the hash being hash_hmac( 'md5', "customer|expiration", wp_hash( … ) )
		// (class-wc-session-handler.php:316-318, :352-357).
		$browser_id      = wc_rand_hash( 't_', 30 );
		$browser_value   = maybe_serialize( array( 'cart' => maybe_serialize( array( 'browser-line' => array( 'quantity' => 3 ) ) ) ) );
		$browser_expiry  = time() + 2 * DAY_IN_SECONDS;
		$browser_message = $browser_id . '|' . $browser_expiry;
		$this->insert_session_row( $browser_id, $browser_value, $browser_expiry );
		$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $browser_message . '|' . ( time() + DAY_IN_SECONDS ) . '|' . hash_hmac( 'md5', $browser_message, wp_hash( $browser_message ) );

		// The product-page tokenized session the express checkout pays from.
		$token_session_id = wc_rand_hash( 't_', 30 );
		$this->set_token_header( $token_session_id );
		$handler = $this->init_handler();
		add_filter( 'woocommerce_session_handler', static fn() => WooPaymentsTokenizedCartSessionHandler::class );
		WC()->session = $handler;
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'billing_address'  => array(
					'first_name' => 'Express',
					'last_name'  => 'Shopper',
					'company'    => '',
					'address_1'  => '1 Main St',
					'address_2'  => '',
					'city'       => 'San Francisco',
					'state'      => 'CA',
					'postcode'   => '94110',
					'country'    => 'US',
					'phone'      => '4155550100',
					'email'      => 'express-account@example.com',
				),
				'shipping_address' => array(
					'first_name' => 'Express',
					'last_name'  => 'Shopper',
					'company'    => '',
					'address_1'  => '1 Main St',
					'address_2'  => '',
					'city'       => 'San Francisco',
					'state'      => 'CA',
					'postcode'   => '94110',
					'country'    => 'US',
					'phone'      => '',
				),
				'create_account'   => true,
				'payment_method'   => 'bacs',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$user_id = (int) $data['customer_id'];
		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( $user_id, get_current_user_id() );

		WC()->session->save_data();

		$this->assertSame( $handler, WC()->session );
		$this->assertSame( $token_session_id, $handler->get_customer_id(), 'The checkout must keep running on the tokenized session.' );
		$token_row = $this->get_session_row( $token_session_id );
		$this->assertIsArray( $token_row );
		$this->assertSame( (string) $user_id, $token_row['token_customer_id'], 'The tokenized session now belongs to the new account.' );
		// WC_Cart_Session::destroy_cart_session() unsets the cart key (class-wc-cart-session.php:313-316, abstract-wc-session.php:115-120).
		$this->assertArrayNotHasKey( 'cart', $token_row, 'The payment empties the tokenized cart it paid for.' );
		$this->assertSame( $browser_value, $this->get_session_value( $browser_id ), 'The browser session is left for core to migrate on the next request.' );
		$this->assertNull( $this->get_session_value( (string) $user_id ), 'Nothing is written into a session for the new user.' );

		// The same shopper, now logged in, can keep using the token.
		$this->assertSame( $token_session_id, $this->init_handler()->get_customer_id() );

		// The shopper's next normal request: core's handler, with the browser cookie and the new account logged in, migrates
		// the browser guest session to the account and deletes the guest row (class-wc-session-handler.php:186-189, :218-246).
		$browser = new \WC_Session_Handler();
		$browser->init();
		remove_action( 'shutdown', array( $browser, 'save_data' ), 20 );
		remove_action( 'woocommerce_set_cart_cookies', array( $browser, 'set_customer_session_cookie' ), 10 );
		remove_action( 'wp', array( $browser, 'maybe_set_customer_session_cookie' ), 99 );
		remove_action( 'template_redirect', array( $browser, 'destroy_session_if_empty' ), 999 );
		remove_action( 'wp_logout', array( $browser, 'destroy_session' ) );

		$this->assertSame( (string) $user_id, $browser->get_customer_id() );
		$this->assertSame( array( 'browser-line' => array( 'quantity' => 3 ) ), maybe_unserialize( $this->get_session_row( (string) $user_id )['cart'] ?? '' ), "The account's session holds the browser cart." );
		$this->assertNull( $this->get_session_value( $browser_id ), 'The guest row is gone after the migration.' );
	}

	/**
	 * @testdox Rebinding a saved session to a newly logged-in account is saved even when nothing else in the session changes.
	 *
	 * WC_Session_Handler::save_data() writes only a dirty session (class-wc-session-handler.php:563-565).
	 */
	public function test_rebinding_on_login_is_saved_without_other_session_changes(): void {
		$session_id = $this->save_session_started_by( 0 );
		$this->set_token_header( $session_id );
		$handler = $this->init_handler();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$handler->init_session_cookie();
		$handler->save_data();

		$this->assertSame( (string) $user_id, $this->get_session_row( $session_id )['token_customer_id'] ?? null );
		$this->assertSame( $session_id, $this->init_handler()->get_customer_id(), 'The account can load the session on its next request.' );
	}

	/**
	 * @testdox A tokenized session started by one shopper is refused for another, and the refusal is logged when logging is on.
	 *
	 * @dataProvider other_customer_provider
	 *
	 * @param string $minted_by  Who started the session: 'user' or 'guest'.
	 * @param string $replayed_by Who sends the token: 'other user' or 'guest'.
	 */
	public function test_refuses_a_token_from_another_customer( string $minted_by, string $replayed_by ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$minting_user = 'user' === $minted_by ? self::factory()->user->create() : 0;
		$session_id   = $this->save_session_started_by( $minting_user );

		wp_set_current_user( 'other user' === $replayed_by ? self::factory()->user->create() : 0 );
		$this->set_token_header( $session_id );

		$refused = null;
		try {
			$this->init_handler();
		} catch ( RuntimeException $exception ) {
			$refused = $exception;
		}

		$this->assertInstanceOf( RuntimeException::class, $refused );
		$lines = array_values( array_filter( $this->log_lines, static fn( array $line ): bool => 'Tokenized cart session customer mismatch.' === $line['message'] ) );
		// WC_Logger applies the message filter once per log handler, so one line can be seen more than once.
		$this->assertNotSame( array(), $lines );
		$this->assertSame( 'error', $lines[0]['level'] );
		$this->assertSame( WooPaymentsLogger::SOURCE, $lines[0]['context']['source'] ?? '' );
		$this->assertSame( (string) $minting_user, $lines[0]['context']['session_customer_id'] ?? null );
	}

	/**
	 * @testdox A refused token writes no log line while WooPayments logging and dev mode are off, as client 11.1.0's Logger.
	 */
	public function test_refusal_log_line_follows_the_logging_setting(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$session_id = $this->save_session_started_by( self::factory()->user->create() );
		$this->set_token_header( $session_id );

		$this->expectException( RuntimeException::class );
		try {
			$this->init_handler();
		} finally {
			$this->assertSame( array(), array_filter( $this->log_lines, static fn( array $line ): bool => 'Tokenized cart session customer mismatch.' === $line['message'] ) );
		}
	}

	/**
	 * Shoppers a token is replayed by.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function other_customer_provider(): array {
		return array(
			"a user's token sent by another user" => array( 'user', 'other user' ),
			"a user's token sent by a guest"      => array( 'user', 'guest' ),
			"a guest's token sent by a user"      => array( 'guest', 'other user' ),
		);
	}

	/**
	 * @testdox A tokenized session loads for the user who started it, and a guest's for a guest.
	 *
	 * @testWith ["user"]
	 *           ["guest"]
	 *
	 * @param string $customer Who starts and sends the token.
	 */
	public function test_loads_a_token_for_the_customer_who_started_it( string $customer ): void {
		$user_id    = 'user' === $customer ? self::factory()->user->create() : 0;
		$session_id = $this->save_session_started_by( $user_id );

		wp_set_current_user( $user_id );
		$this->set_token_header( $session_id );
		$handler = $this->init_handler();

		$this->assertSame( $session_id, $handler->get_customer_id() );
		$this->assertSame( array( 'line' => array( 'quantity' => 1 ) ), $handler->get( 'cart' ) );
	}

	/**
	 * Save a tokenized session started by a customer.
	 *
	 * @param int $user_id User ID, 0 for a guest.
	 * @return string Session ID.
	 */
	private function save_session_started_by( int $user_id ): string {
		wp_set_current_user( $user_id );
		$handler = $this->init_handler();
		$handler->set( 'cart', array( 'line' => array( 'quantity' => 1 ) ) );
		$handler->save_data();
		wp_set_current_user( 0 );

		return $handler->get_customer_id();
	}

	/**
	 * Create and initialize a handler.
	 *
	 * @return WooPaymentsTokenizedCartSessionHandler
	 */
	private function init_handler(): WooPaymentsTokenizedCartSessionHandler {
		$handler          = new WooPaymentsTokenizedCartSessionHandler();
		$this->handlers[] = $handler;
		$handler->init();

		return $handler;
	}

	/**
	 * Send a signed tokenized session header for a session ID, as the session controller mints it.
	 *
	 * @param string $session_id Session ID.
	 */
	private function set_token_header( string $session_id ): void {
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'] = JsonWebToken::create(
			array(
				'session_id' => $session_id,
				'exp'        => time() + HOUR_IN_SECONDS,
				'iss'        => 'woopayments/product-page',
			),
			WooPaymentsTokenizedCartSessionHandler::get_token_secret()
		);
	}

	/**
	 * Insert a session row.
	 *
	 * @param string $key    Session key.
	 * @param string $value  Serialized session value.
	 * @param int    $expiry Expiry timestamp.
	 */
	private function insert_session_row( string $key, string $value, int $expiry ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $key,
				'session_value'  => $value,
				'session_expiry' => $expiry,
			)
		);
	}

	/**
	 * Read a stored session value, bypassing the cache.
	 *
	 * @param string $key Session key.
	 * @return string|null
	 */
	private function get_session_value( string $key ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ) );
	}

	/**
	 * Read a stored session's data, bypassing the cache.
	 *
	 * @param string $key Session key.
	 * @return array<string,mixed>|null
	 */
	private function get_session_row( string $key ): ?array {
		$value = $this->get_session_value( $key );

		return null === $value ? null : (array) maybe_unserialize( $value );
	}
}
