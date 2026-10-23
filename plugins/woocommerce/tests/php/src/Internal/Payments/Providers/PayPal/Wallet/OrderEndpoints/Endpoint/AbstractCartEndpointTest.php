<?php
/**
 * Tests for the base class of the cart endpoints.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\AbstractCartEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use stdClass;
use WC_Session;

/**
 * The shutdown session write the cart endpoints remove when they have nothing to persist.
 *
 * @group paypal-wallet
 */
class AbstractCartEndpointTest extends WalletTestCase {

	/**
	 * A concrete cart endpoint that exposes prevent_session_persistence(), which is protected.
	 *
	 * @return AbstractCartEndpoint
	 */
	private function testable_endpoint(): AbstractCartEndpoint {
		return new class() extends AbstractCartEndpoint {
			/**
			 * Nothing to handle: only the session method is under test.
			 */
			protected function handle_data(): void {}

			/**
			 * Call the protected method.
			 */
			public function call_prevent_session_persistence(): void {
				$this->prevent_session_persistence();
			}
		};
	}

	/**
	 * @testdox Should remove the session save_data shutdown hook, so nothing this request wrote to the session overwrites a concurrent request's changes.
	 */
	public function test_removes_the_session_save_data_shutdown_hook(): void {
		$this->use_own_wc_session();
		$callback = array( WC()->session, 'save_data' );
		add_action( 'shutdown', $callback, 20 );
		$this->assertSame( 20, has_action( 'shutdown', $callback ), 'The session write is registered before the call' );

		$this->testable_endpoint()->call_prevent_session_persistence();

		$this->assertFalse( has_action( 'shutdown', $callback ) );
	}

	/**
	 * @testdox Should remove nothing and raise no error when WooCommerce has no usable session: $scenario.
	 * @dataProvider missing_session_provider
	 *
	 * @param string $scenario What is wrong with the session.
	 * @param mixed  $session The value WC()->session holds.
	 */
	public function test_does_nothing_when_no_usable_session_is_present( string $scenario, $session ): void {
		unset( $scenario );
		$this->use_own_wc_session();
		WC()->session = $session;

		$unrelated_session = $this->mock( WC_Session::class );
		$callback          = array( $unrelated_session, 'save_data' );
		add_action( 'shutdown', $callback, 20 );

		$this->testable_endpoint()->call_prevent_session_persistence();

		$this->assertSame( 20, has_action( 'shutdown', $callback ), 'The write of another session stays registered' );
	}

	/**
	 * Values of WC()->session that are not a session.
	 *
	 * @return array
	 */
	public function missing_session_provider(): array {
		return array(
			'session property was never initialized' => array( 'never initialized', null ),
			'session property is not a WC_Session'   => array( 'not a WC_Session', new stdClass() ),
		);
	}
}
