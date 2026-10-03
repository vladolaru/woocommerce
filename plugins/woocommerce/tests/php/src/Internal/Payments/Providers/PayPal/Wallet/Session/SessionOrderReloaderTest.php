<?php
/**
 * Tests for the PayPal wallet session order reloader (ported from the extension's SessionOrderReloaderTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Session
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Session;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionOrderReloader;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use stdClass;
use WC_Session;
use WC_Session_Handler;

/**
 * Re-fetching a pending PayPal order stored in the shopper's session, on the checkout page only and at most every 15
 * seconds per order.
 *
 * The extension's tests fixed the clock; the real clock runs here, so times are taken from time() and compared with a
 * small tolerance, and the interval's skip case keeps a few seconds of margin instead of exactly one.
 *
 * @group paypal-wallet
 */
class SessionOrderReloaderTest extends WalletTestCase {

	/**
	 * Mirrors the private SessionOrderReloader::RELOAD_INTERVAL_SECONDS constant.
	 */
	private const INTERVAL = 15;

	/**
	 * The order endpoint mock.
	 *
	 * @var OrderEndpoint|\Mockery\MockInterface
	 */
	private $order_endpoint;

	/**
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler|\Mockery\MockInterface
	 */
	private $session_handler;

	/**
	 * The WooCommerce session the test replaced, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * The number of times the "wp" action had run when the test started, restored on tearDown (null when never run).
	 *
	 * @var int|null
	 */
	private $original_wp_action_count;

	/**
	 * Build the collaborators and make the request look like a checkout page request after the main query ran, unless
	 * a test says otherwise.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session         = WC()->session;
		$this->original_wp_action_count = $GLOBALS['wp_actions']['wp'] ?? null;

		$this->order_endpoint  = $this->mock( OrderEndpoint::class );
		$this->session_handler = $this->mock( SessionHandler::class );

		$this->main_query_ran( true );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
	}

	/**
	 * Restore the session and the action count.
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
			if ( null === $this->original_wp_action_count ) {
				unset( $GLOBALS['wp_actions']['wp'] );
			} else {
				$GLOBALS['wp_actions']['wp'] = $this->original_wp_action_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Make did_action( 'wp' ) report whether the main query ran.
	 *
	 * @param bool $ran Whether the main query ran.
	 */
	private function main_query_ran( bool $ran ): void {
		$GLOBALS['wp_actions']['wp'] = $ran ? 1 : 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the request stage.
	}

	/**
	 * The reloader under test.
	 *
	 * @return SessionOrderReloader
	 */
	private function create_reloader(): SessionOrderReloader {
		return new SessionOrderReloader( $this->order_endpoint, $this->mock( LoggerInterface::class )->shouldIgnoreMissing() );
	}

	/**
	 * Make WC()->session a plain session backed by the given array.
	 *
	 * @param array<string, mixed> $store The session data.
	 * @return WC_Session|\Mockery\MockInterface
	 */
	private function session_with( array &$store ) {
		return $this->install_session( $this->mock( WC_Session::class ), $store );
	}

	/**
	 * Make WC()->session a WC_Session_Handler double, distinct from the plain WC_Session: only the handler triggers the
	 * immediate save_data() call.
	 *
	 * @param array<string, mixed> $store The session data.
	 * @return WC_Session_Handler|\Mockery\MockInterface
	 */
	private function session_handler_with( array &$store ) {
		return $this->install_session( $this->mock( WC_Session_Handler::class ), $store );
	}

	/**
	 * Back a session mock with the array and install it as WC()->session.
	 *
	 * @param \Mockery\MockInterface $session The session mock.
	 * @param array<string, mixed>   $store   The session data.
	 * @return \Mockery\MockInterface
	 */
	private function install_session( $session, array &$store ) {
		$session->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? null;
			}
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			static function ( string $key, $value ) use ( &$store ): void {
				$store[ $key ] = $value;
			}
		);

		WC()->session = $session;

		return $session;
	}

	/**
	 * A session order with the given PayPal ID and status.
	 *
	 * @param string $id     The PayPal order ID.
	 * @param string $status The order status.
	 * @return Order
	 */
	private function order_with( string $id, string $status ): Order {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'id' )->andReturn( $id );
		$order->shouldReceive( 'status' )->andReturn( new OrderStatus( $status ) );

		return $order;
	}

	/**
	 * Assert the session recorded a reload of the order just now.
	 *
	 * @param array<string, mixed> $store    The session data.
	 * @param string               $order_id The PayPal order ID.
	 */
	private function assert_reload_recorded( array $store, string $order_id ): void {
		$this->assertArrayHasKey( SessionOrderReloader::LAST_RELOAD_SESSION_KEY, $store );
		$mark = $store[ SessionOrderReloader::LAST_RELOAD_SESSION_KEY ];
		$this->assertSame( $order_id, $mark['order_id'] );
		$this->assertEqualsWithDelta( time(), $mark['time'], 5, 'The reload time should be now' );
	}

	/**
	 * @testdox Should fetch a CREATED order again, replace the session order and record the reload time when it was never reloaded.
	 */
	public function test_fetches_and_replaces_created_order_never_reloaded_before(): void {
		$store = array();
		$this->session_with( $store );

		$order       = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );
		$fresh_order = $this->mock( Order::class );

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andReturn( $fresh_order );
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $fresh_order );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assert_reload_recorded( $store, 'WC-ORDER-1' );
	}

	/**
	 * A standard WooCommerce session handler only persists session data at shutdown by default.
	 *
	 * @testdox Should save the session immediately, before asking PayPal, so a concurrent request sees the reload mark.
	 */
	public function test_persists_reload_mark_immediately_with_session_handler(): void {
		$store      = array();
		$wc_session = $this->session_handler_with( $store );

		$order       = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );
		$fresh_order = $this->mock( Order::class );

		$wc_session->shouldReceive( 'save_data' )->once()->ordered();
		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->ordered()->andReturn( $fresh_order );
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $fresh_order );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assert_reload_recorded( $store, 'WC-ORDER-1' );
	}

	/**
	 * @testdox Should not query PayPal or touch the session for an order with the terminal status $status.
	 *
	 * @dataProvider terminal_status_provider
	 *
	 * @param string $status The PayPal order status.
	 */
	public function test_skips_terminal_statuses( string $status ): void {
		$store = array();
		$this->session_with( $store );

		$order = $this->order_with( 'WC-ORDER-1', $status );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assertArrayNotHasKey( SessionOrderReloader::LAST_RELOAD_SESSION_KEY, $store );
	}

	/**
	 * Statuses that need no reload.
	 *
	 * @return array<string, array<string>>
	 */
	public function terminal_status_provider(): array {
		return array(
			'approved order is left alone'  => array( OrderStatus::APPROVED ),
			'completed order is left alone' => array( OrderStatus::COMPLETED ),
			'voided order is left alone'    => array( OrderStatus::VOIDED ),
		);
	}

	/**
	 * @testdox Should fetch nothing and store nothing when there is no session order.
	 */
	public function test_skips_null_order(): void {
		$store = array();
		$this->session_with( $store );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( null, $this->session_handler );

		$this->assertSame( array(), $store );
	}

	/**
	 * @testdox Should fetch nothing when the WooCommerce instance has no session to read or write.
	 */
	public function test_skips_when_wc_session_is_not_set(): void {
		WC()->session = null;

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Should reload only once per reloader instance, even for another reloadable order.
	 */
	public function test_only_reloads_once_per_instance(): void {
		$store = array();
		$this->session_with( $store );

		$order       = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );
		$other_order = $this->order_with( 'WC-ORDER-2', OrderStatus::CREATED );
		$fresh_order = $this->mock( Order::class );

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andReturn( $fresh_order );
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $fresh_order );

		$reloader = $this->create_reloader();
		$reloader->maybe_reload( $order, $this->session_handler );
		$reloader->maybe_reload( $other_order, $this->session_handler );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The same order reloaded a few seconds ago is skipped while inside the interval and fetched once it elapsed; a
	 * different order within the interval is fetched regardless. The skip case sits well inside the interval (the real
	 * clock can tick between building the session and the check), the fetch cases at or beyond it.
	 *
	 * @testdox Should reload across instances by the interval: $case_name.
	 *
	 * @dataProvider repeat_reload_provider
	 *
	 * @param string $case_name                 Case name.
	 * @param string $stored_order_id      The order ID the session recorded.
	 * @param int    $seconds_since_stored How long ago it was recorded.
	 * @param string $requested_order_id   The order ID being read now.
	 * @param bool   $expects_fetch        Whether PayPal should be asked.
	 */
	public function test_reload_across_instances_respects_interval(
		string $case_name,
		string $stored_order_id,
		int $seconds_since_stored,
		string $requested_order_id,
		bool $expects_fetch
	): void {
		unset( $case_name );
		$store = array(
			SessionOrderReloader::LAST_RELOAD_SESSION_KEY => array(
				'order_id' => $stored_order_id,
				'time'     => time() - $seconds_since_stored,
			),
		);
		$this->session_with( $store );

		$order       = $this->order_with( $requested_order_id, OrderStatus::CREATED );
		$fresh_order = $this->mock( Order::class );

		if ( $expects_fetch ) {
			$this->order_endpoint->shouldReceive( 'order' )->once()->with( $requested_order_id )->andReturn( $fresh_order );
			$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $fresh_order );
		} else {
			$this->order_endpoint->shouldNotReceive( 'order' );
			$this->session_handler->shouldNotReceive( 'replace_order' );
		}

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Interval cases.
	 *
	 * @return array<string, array>
	 */
	public function repeat_reload_provider(): array {
		return array(
			'same order well inside the interval is skipped'     => array( 'inside', 'WC-ORDER-1', self::INTERVAL - 5, 'WC-ORDER-1', false ),
			'same order at the interval boundary is fetched'     => array( 'boundary', 'WC-ORDER-1', self::INTERVAL, 'WC-ORDER-1', true ),
			'different order within the interval is fetched'     => array( 'other order', 'WC-ORDER-1', 5, 'WC-ORDER-2', true ),
		);
	}

	/**
	 * @testdox Should forget only the session order, and store no replacement, when PayPal answers a runtime exception with code 404.
	 */
	public function test_forgets_session_order_on_runtime_exception_with_404_code(): void {
		$store = array();
		$this->session_with( $store );

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andThrow( new RuntimeException( 'Not found', 404 ) );

		$this->session_handler->shouldReceive( 'forget_order' )->once();
		$this->session_handler->shouldNotReceive( 'destroy_session_data' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Should forget only the session order when PayPal's API exception says the order is gone: $case_name.
	 *
	 * @dataProvider not_found_api_exception_provider
	 *
	 * @param string $case_name        Case name.
	 * @param int    $status_code The HTTP status code of the exception.
	 * @param string $name        The PayPal error name.
	 */
	public function test_forgets_session_order_on_not_found_api_exception( string $case_name, int $status_code, string $name ): void {
		unset( $case_name );
		$store = array();
		$this->session_with( $store );

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$response       = new stdClass();
		$response->name = $name;

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andThrow( new PayPalApiException( $response, $status_code ) );

		$this->session_handler->shouldReceive( 'forget_order' )->once();
		$this->session_handler->shouldNotReceive( 'destroy_session_data' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Ways the API exception identifies a missing order.
	 *
	 * @return array<string, array>
	 */
	public function not_found_api_exception_provider(): array {
		return array(
			'identified by 404 status code'         => array( '404 status code', 404, 'SOME_OTHER_NAME' ),
			'identified by RESOURCE_NOT_FOUND name' => array( 'RESOURCE_NOT_FOUND name', 500, 'RESOURCE_NOT_FOUND' ),
		);
	}

	/**
	 * @testdox Should keep the session order, and record the reload so the failing fetch is not retried within the interval, when PayPal fails for another reason.
	 */
	public function test_keeps_session_order_and_suppresses_retries_on_other_exception(): void {
		$store = array();
		$this->session_with( $store );

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andThrow( new RuntimeException( 'Server error', 500 ) );

		$this->session_handler->shouldNotReceive( 'forget_order' );
		$this->session_handler->shouldNotReceive( 'destroy_session_data' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assert_reload_recorded( $store, 'WC-ORDER-1' );
	}

	/**
	 * A pending order read on a non-checkout page, for example the home page with the mini-cart or a mini-cart
	 * fragments refresh, must not cost a PayPal request.
	 *
	 * @testdox Should not query PayPal or record a reload on a non-checkout page.
	 */
	public function test_skips_non_checkout_requests(): void {
		$store = array();
		$this->session_with( $store );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		add_filter( 'woocommerce_is_checkout', '__return_false' );

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assertSame( array(), $store );
	}

	/**
	 * Before the main query ran, for example on wp_loaded or during a Store API request from the block mini-cart (REST
	 * never reaches "wp"), is_checkout() is not even consulted.
	 *
	 * @testdox Should not query PayPal, or ask whether this is the checkout, before the main query ran.
	 */
	public function test_skips_requests_before_the_main_query(): void {
		$store = array();
		$this->session_with( $store );
		$this->main_query_ran( false );

		$consulted = false;
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		add_filter(
			'woocommerce_is_checkout',
			static function ( $is_checkout ) use ( &$consulted ) {
				$consulted = true;
				return $is_checkout;
			}
		);

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assertSame( array(), $store );
		$this->assertFalse( $consulted, 'is_checkout() must not run before the main query' );
	}

	/**
	 * The classic checkout AJAX submission follows the page load, which already refreshed the session order.
	 *
	 * @testdox Should not query PayPal or record a reload during an AJAX request, even on the checkout page.
	 */
	public function test_skips_ajax_requests_even_on_checkout(): void {
		$store = array();
		$this->session_with( $store );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$order = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );

		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->session_handler->shouldNotReceive( 'replace_order' );

		$this->create_reloader()->maybe_reload( $order, $this->session_handler );

		$this->assertSame( array(), $store );
	}

	/**
	 * @testdox Should still reload on a later checkout read after an earlier read of the same instance was skipped.
	 */
	public function test_skipped_read_does_not_block_later_checkout_reload(): void {
		$store = array();
		$this->session_with( $store );

		$order       = $this->order_with( 'WC-ORDER-1', OrderStatus::CREATED );
		$fresh_order = $this->mock( Order::class );

		$this->order_endpoint->shouldReceive( 'order' )->once()->with( 'WC-ORDER-1' )->andReturn( $fresh_order );
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $fresh_order );

		$reloader = $this->create_reloader();

		$this->main_query_ran( false );
		$reloader->maybe_reload( $order, $this->session_handler );

		$this->main_query_ran( true );
		$reloader->maybe_reload( $order, $this->session_handler );

		$this->assert_reload_recorded( $store, 'WC-ORDER-1' );
	}
}
