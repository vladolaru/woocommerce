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
use WC_Unit_Test_Case;
use WP_Error;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Real WordPress and WooCommerce under the test (no Brain Monkey): options are written and cleaned, filters are real,
 * collaborators are Mockery mocks as in the extension's own tests.
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
	 * The screen and query arguments before simulate_admin_request() replaced them, or null when it was not called.
	 *
	 * @var array{screen: mixed, get: array}|null
	 */
	private ?array $request_before_simulation = null;

	/**
	 * Block the network: answer every request with a WP_Error until the test stubs HTTP.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_responder = static function ( $request, $url ) {
			unset( $request );
			return new WP_Error( 'unstubbed_http', "Unstubbed request to $url" );
		};
	}

	/**
	 * Delete the options the test wrote and put back the request. Mockery is closed by MockeryPHPUnitIntegration.
	 */
	public function tearDown(): void {
		try {
			foreach ( $this->written_options as $name ) {
				delete_option( $name );
			}
			$this->written_options = array();
			remove_all_filters( 'pre_http_request' );
			$this->restore_request();
		} finally {
			parent::tearDown();
		}
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
