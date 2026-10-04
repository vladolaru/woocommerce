<?php
/**
 * Base class for the PayPal wallet tests.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Error;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use WC_Customer;
use WC_Session_Handler;
use WC_Tax;
use WC_Unit_Test_Case;
use WP_Error;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Real WordPress and WooCommerce under the test (no Brain Monkey): options are written and cleaned, filters are real,
 * collaborators are Mockery mocks as in the extension's own tests.
 *
 * The shopper's shipping address is put back after each test, because WC()->customer lives on the WC() singleton that
 * neither the database rollback nor WC_Unit_Test_Case resets. Tax rates added through insert_flat_tax_rate() are
 * deleted again, and so is a session set through use_own_wc_session().
 *
 * Outgoing HTTP never reaches the network: every request is answered with a WP_Error (code "unstubbed_http") unless the
 * test calls stub_http(), so a forgotten stub or an unexpected extra request surfaces through the code's own error path
 * and through the test's request assertions.
 */
abstract class WalletTestCase extends WC_Unit_Test_Case {

	// Counts the Mockery expectations as assertions, so a test that only sets expectations is not flagged as risky.
	use MockeryPHPUnitIntegration;

	/**
	 * Options written through set_wallet_option(), deleted on tearDown.
	 *
	 * @var string[]
	 */
	private array $written_options = array();

	/**
	 * Transients written through set_wallet_transient(), deleted on tearDown.
	 *
	 * @var string[]
	 */
	private array $written_transients = array();

	/**
	 * Ids of the tax rates added through insert_flat_tax_rate(), deleted on tearDown.
	 *
	 * @var int[]
	 */
	private array $inserted_tax_rates = array();

	/**
	 * The shipping address of WC()->customer when the test started, or null when the shop had no customer yet.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $original_customer_address = null;

	/**
	 * The session WC() had before use_own_wc_session() replaced it.
	 *
	 * @var mixed
	 */
	private $original_session = null;

	/**
	 * Whether use_own_wc_session() replaced the session.
	 *
	 * @var bool
	 */
	private bool $session_replaced = false;

	/**
	 * The screen and query arguments before simulate_admin_request() replaced them, or null when it was not called.
	 *
	 * @var array{screen: mixed, get: array}|null
	 */
	private ?array $request_before_simulation = null;

	/**
	 * Block the network: answer every request with a WP_Error until the test stubs HTTP, and remember the address of
	 * the customer.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_customer_address = WC()->customer instanceof WC_Customer ? $this->read_customer_address( WC()->customer ) : null;

		$this->http_responder = static function ( $request, $url ) {
			unset( $request );
			return new WP_Error( 'unstubbed_http', "Unstubbed request to $url" );
		};
	}

	/**
	 * Delete the options, the transients and the tax rates the test wrote, put back the request and the customer address
	 * and the session. Mockery is closed by MockeryPHPUnitIntegration.
	 */
	public function tearDown(): void {
		try {
			foreach ( $this->inserted_tax_rates as $rate_id ) {
				WC_Tax::_delete_tax_rate( $rate_id ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			}
			$this->inserted_tax_rates = array();
			$this->restore_customer_address();
			if ( $this->session_replaced ) {
				WC()->session           = $this->original_session;
				$this->original_session = null;
				$this->session_replaced = false;
			}
			foreach ( $this->written_options as $name ) {
				delete_option( $name );
			}
			$this->written_options = array();
			foreach ( $this->written_transients as $name ) {
				delete_transient( $name );
			}
			$this->written_transients = array();
			remove_all_filters( 'pre_http_request' );
			$this->restore_request();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The shipping address of a customer, the way the customer was built.
	 *
	 * @param WC_Customer $customer The customer.
	 * @return array<string, string>
	 */
	private function read_customer_address( WC_Customer $customer ): array {
		return array(
			'country'    => $customer->get_shipping_country( 'edit' ),
			'state'      => $customer->get_shipping_state( 'edit' ),
			'postcode'   => $customer->get_shipping_postcode( 'edit' ),
			'city'       => $customer->get_shipping_city( 'edit' ),
			'address_1'  => $customer->get_shipping_address_1( 'edit' ),
			'first_name' => $customer->get_shipping_first_name( 'edit' ),
			'last_name'  => $customer->get_shipping_last_name( 'edit' ),
		);
	}

	/**
	 * Put the shipping address of the customer back. A pristine customer holds the store's base location, not empty
	 * strings, and tax rates only apply to a customer who has a country. Nothing is put back when the shop had no
	 * customer before the test.
	 */
	private function restore_customer_address(): void {
		$customer = WC()->customer;
		$address  = $this->original_customer_address;
		if ( null === $address || ! $customer instanceof WC_Customer ) {
			return;
		}

		$customer->set_shipping_country( $address['country'] );
		$customer->set_shipping_state( $address['state'] );
		$customer->set_shipping_postcode( $address['postcode'] );
		$customer->set_shipping_city( $address['city'] );
		$customer->set_shipping_address_1( $address['address_1'] );
		$customer->set_shipping_first_name( $address['first_name'] );
		$customer->set_shipping_last_name( $address['last_name'] );
		$this->original_customer_address = null;
	}

	/**
	 * Add a tax rate that applies to every country, to the standard tax class and to shipping, and delete it again on
	 * tearDown. Tax is only calculated when the woocommerce_calc_taxes option is "yes" and the customer has a country.
	 *
	 * @param string $rate The rate in percent.
	 * @return int The ID of the rate.
	 */
	protected function insert_flat_tax_rate( string $rate = '10.0000' ): int {
		$rate_id = WC_Tax::_insert_tax_rate( // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => $rate,
				'tax_rate_name'     => 'Tax',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);

		$this->inserted_tax_rates[] = $rate_id;

		return $rate_id;
	}

	/**
	 * Give WooCommerce a session of its own for the rest of the test (the purchase unit's custom ID and the fees of
	 * the cart come from it) and put the original back on tearDown.
	 *
	 * @param WC_Session_Handler|null $session The session, a new real handler when none is given.
	 */
	protected function use_own_wc_session( ?WC_Session_Handler $session = null ): void {
		if ( ! $this->session_replaced ) {
			$this->original_session = WC()->session;
			$this->session_replaced = true;
		}
		WC()->session = $session ?? new WC_Session_Handler();
	}

	/**
	 * Make the rest of the test run as an admin request with the given query arguments. The screen and the query
	 * arguments are put back on tearDown.
	 *
	 * @param array $query The query arguments of the request.
	 */
	protected function simulate_admin_request( array $query ): void {
		if ( null === $this->request_before_simulation ) {
			$this->request_before_simulation = array(
				'screen' => $GLOBALS['current_screen'] ?? null,
				'get'    => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		set_current_screen( 'dashboard' );
		$_GET = $query;
	}

	/**
	 * Put back what simulate_admin_request() replaced.
	 */
	private function restore_request(): void {
		if ( null === $this->request_before_simulation ) {
			return;
		}

		if ( null === $this->request_before_simulation['screen'] ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			set_current_screen( $this->request_before_simulation['screen'] );
		}
		$_GET                            = $this->request_before_simulation['get']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->request_before_simulation = null;
	}

	/**
	 * Write an option for the test and remember it for cleanup.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 */
	protected function set_wallet_option( string $name, $value ): void {
		$this->written_options[] = $name;
		update_option( $name, $value );
	}

	/**
	 * Write a transient for the test and remember it for cleanup. Also use it to claim a transient the code under test
	 * writes itself, so the test leaves none behind.
	 *
	 * @param string $name       Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Time until expiration in seconds, 0 for none.
	 */
	protected function set_wallet_transient( string $name, $value, int $expiration = 0 ): void {
		$this->written_transients[] = $name;
		set_transient( $name, $value, $expiration );
	}

	/**
	 * A Mockery mock of the given class or interface.
	 *
	 * @param string $class_name Class or interface name.
	 * @return MockInterface
	 */
	protected function mock( string $class_name ): MockInterface {
		return Mockery::mock( $class_name );
	}

	/**
	 * Answer outgoing HTTP requests with the given response, or with whatever the given callable returns.
	 *
	 * WP_HTTP_TestCase (the parent) already listens on pre_http_request and records each request in $http_requests
	 * as array( 'url' => string, 'request' => array ); this sets its responder.
	 *
	 * @param array|WP_Error|callable $response A response from http_response(), a WP_Error, or a callable that receives
	 *                                          the request arguments array and the URL and returns one of those, so a
	 *                                          test can sequence responses or key them by URL. A callable must return an
	 *                                          array or a WP_Error: any other value, such as false, lets the request
	 *                                          through to the network.
	 */
	protected function stub_http( $response ): void {
		if ( ! $response instanceof WP_Error && is_callable( $response ) ) {
			$this->http_responder = $response;
			return;
		}

		$this->http_responder = static function () use ( $response ) {
			return $response;
		};
	}

	/**
	 * A canned HTTP response in the shape WordPress hands back from wp_remote_get().
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return array
	 */
	protected function http_response( int $code, string $body ): array {
		return array(
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'headers'  => new CaseInsensitiveDictionary( array() ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Register a filter that records every call and returns the given value, or its first argument when none is given.
	 *
	 * @param string $hook         The hook.
	 * @param mixed  ...$overrides The value to return (at most one).
	 * @return ArrayObject One entry per call, holding the arguments the callback received.
	 */
	protected function spy_filter( string $hook, ...$overrides ): ArrayObject {
		$calls = new ArrayObject();

		add_filter(
			$hook,
			static function () use ( $calls, $overrides ) {
				$args    = func_get_args();
				$calls[] = $args;

				return array() === $overrides ? $args[0] : $overrides[0];
			},
			10,
			5
		);

		return $calls;
	}

	/**
	 * Run an AJAX handler the way the AJAX call does and return the JSON it sent.
	 *
	 * wp_send_json_*() ends in wp_die(), so the die handler throws an Error to stop there. It is an Error and not an
	 * Exception because endpoints catch Exception around their wp_send_json_*() calls, and a real wp_die() exits. The
	 * handler must end that way: a handler that returns without sending a response fails the test.
	 *
	 * @param callable $handler The handler, for example array( $endpoint, 'handle_request' ).
	 * @return array The decoded response ("success" and "data").
	 */
	protected function run_ajax_handler( callable $handler ): array {
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function ( $message ) {
					throw new Error( 'wp_die:' . esc_html( (string) $message ) );
				};
			}
		);
		add_filter( 'wp_doing_ajax', '__return_true' );

		$finished = true;
		ob_start();
		try {
			$handler();
			$finished = false;
		} catch ( Error $e ) {
			if ( 0 !== strpos( $e->getMessage(), 'wp_die:' ) ) {
				throw $e;
			}
		} finally {
			$body = (string) ob_get_clean();
		}

		$this->assertTrue( $finished, 'The handler should end the request with a JSON response' );
		$response = json_decode( $body, true );
		$this->assertIsArray( $response, 'The handler should send JSON, got: ' . $body );

		return $response;
	}

	/**
	 * A Bearer mock that hands out a token whose value is "bearer".
	 *
	 * @param bool $once Whether bearer() must be called exactly once (true) or any number of times (false).
	 * @return Bearer
	 */
	protected function make_bearer( bool $once = true ): Bearer {
		$token = Mockery::mock( Token::class );
		$token->shouldReceive( 'token' )->andReturn( 'bearer' );

		$bearer      = Mockery::mock( Bearer::class );
		$expectation = $bearer->shouldReceive( 'bearer' )->andReturn( $token );
		if ( $once ) {
			$expectation->once();
		}

		return $bearer;
	}
}
