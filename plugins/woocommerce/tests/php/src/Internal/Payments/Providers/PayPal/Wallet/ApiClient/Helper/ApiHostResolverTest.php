<?php
/**
 * Tests for the API host resolver.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The host the wallet talks to: PayPal's API once a merchant is connected, the connect service while onboarding, each
 * in its sandbox or production flavor.
 *
 * The host comes from constants the bootstrap defines when it boots, and the unit tests do not boot the wallet. A
 * constant cannot be undefined, so defining them in the shared process would let PayPalWalletBootstrapTest pass its
 * "defined after boot" check without the bootstrap defining anything. Each case therefore runs in a separate process
 * and defines the constants there, from the bootstrap's own list. The expected hosts are read from that list too,
 * because PHPUnit runs the data providers before any setUp().
 *
 * @group paypal-wallet
 */
class ApiHostResolverTest extends WalletTestCase {

	/**
	 * Define the host constants the way the bootstrap does when the wallet has not booted. Only call it in a test that
	 * runs in a separate process.
	 */
	private function define_host_constants(): void {
		foreach ( PayPalWalletBootstrap::get_extension_constants() as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}

	/**
	 * A merchant in each connection status and environment, and the host that matches it.
	 *
	 * @return array<string, array{bool, bool, string}>
	 */
	public function data_connection_state(): array {
		$constants = PayPalWalletBootstrap::get_extension_constants();

		return array(
			'connected merchant on sandbox resolves to the live sandbox API'         => array( true, true, $constants['PAYPAL_SANDBOX_API_URL'] ),
			'connected merchant on production resolves to the live production API'   => array( true, false, $constants['PAYPAL_API_URL'] ),
			'onboarding merchant on sandbox resolves to the sandbox connect service' => array( false, true, $constants['CONNECT_WOO_SANDBOX_URL'] ),
			'onboarding merchant on production resolves to the connect service'      => array( false, false, $constants['CONNECT_WOO_URL'] ),
		);
	}

	/**
	 * GIVEN a merchant in a given connection status and environment
	 * WHEN the current API host is resolved
	 * THEN the host matching that connection status and environment is returned
	 *
	 * @testdox Should resolve the host from the connection status and the environment.
	 * @dataProvider data_connection_state
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool   $is_connected   Whether the merchant is connected.
	 * @param bool   $is_sandbox     Whether the merchant is on sandbox.
	 * @param string $expected_host  The host that matches.
	 */
	public function test_host_reflects_connection_status_and_environment( bool $is_connected, bool $is_sandbox, string $expected_host ): void {
		$this->define_host_constants();

		$connection_state = new ConnectionState( $is_connected, new Environment( $is_sandbox ) );

		$testee = new ApiHostResolver( $connection_state );

		$this->assertSame( $expected_host, $testee->host() );
	}

	/**
	 * GIVEN a merchant who is not yet connected, resolved once before onboarding completes
	 * WHEN the merchant's connection state switches to connected mid-request, as ConnectionState::connect() does right
	 * after onboarding finishes, and the host is resolved again on the same resolver instance
	 * THEN the second resolution reflects the new connection state instead of a host cached from before the switch
	 *
	 * @testdox Should follow a connection that changes mid-request instead of caching the first host.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_host_reflects_connection_state_changes_mid_request_without_caching(): void {
		$this->define_host_constants();

		$constants        = PayPalWalletBootstrap::get_extension_constants();
		$connection_state = new ConnectionState( false, new Environment( false ) );

		$testee = new ApiHostResolver( $connection_state );

		$host_before_connect = $testee->host();

		$connection_state->connect( false );

		$host_after_connect = $testee->host();

		$this->assertSame( $constants['CONNECT_WOO_URL'], $host_before_connect );
		$this->assertSame( $constants['PAYPAL_API_URL'], $host_after_connect );
		$this->assertNotSame( $host_before_connect, $host_after_connect );
	}
}
