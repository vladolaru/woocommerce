<?php
/**
 * Base class for the ported PayPal wallet tests.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
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
	 * Close Mockery and delete the options the test wrote.
	 */
	public function tearDown(): void {
		try {
			foreach ( $this->written_options as $name ) {
				delete_option( $name );
			}
			$this->written_options = array();
			remove_all_filters( 'pre_http_request' );
			Mockery::close();
		} finally {
			parent::tearDown();
		}
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
	 *                                          test can sequence responses or key them by URL.
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
