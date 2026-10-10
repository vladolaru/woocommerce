<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedAuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedCommonRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\AuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\CommonRestEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WP_REST_Request;

/**
 * Tests for the settings app's "Connection status" block on a store the platform serves: the merchant details it reads
 * and the Disconnect button's route.
 *
 * @group paypal-wallet
 */
class ServedConnectionStatusTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * Boot a container over a ready fake transport, for the state the test stored.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * Put the store in a served state.
	 *
	 * @param string $state `platform_connected` or `collecting`.
	 */
	private function set_served_state( string $state ): void {
		if ( 'platform_connected' === $state ) {
			$this->set_platform_connected();
			return;
		}
		$this->set_collecting();
	}

	/**
	 * Every stored woocommerce-ppcp-* option and ppcp-webhook, by name, as the database holds them.
	 *
	 * @return array<string, string>
	 */
	private function stored_wallet_options(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A test snapshot of the raw rows, past the options cache.
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s ORDER BY option_name",
				$wpdb->esc_like( 'woocommerce-ppcp-' ) . '%',
				'ppcp-webhook'
			),
			ARRAY_A
		);

		return array_column( $rows, 'option_value', 'option_name' );
	}

	/**
	 * @testdox Should report the platform merchant as connected, with its merchant ID, sandbox environment and payee email, in the common details and the merchant route.
	 */
	public function test_platform_connected_store_reports_the_platform_merchant(): void {
		$this->set_platform_connected();
		$endpoint = $this->boot()->get( 'settings.rest.common' );

		$this->assertInstanceOf( PlatformServedCommonRestEndpoint::class, $endpoint );
		foreach ( array( $endpoint->get_details(), $endpoint->get_merchant_details() ) as $response ) {
			$merchant = $response->get_data()['merchant'];
			$this->assertTrue( $merchant['isConnected'] );
			$this->assertTrue( $merchant['isSandbox'] );
			$this->assertSame( 'M2', $merchant['id'] );
			$this->assertSame( 'payee@example.com', $merchant['email'] );
			$this->assertSame( '', $merchant['clientId'], 'The store has no client ID of its own' );
			$this->assertSame( '', $merchant['clientSecret'] );
		}
	}

	/**
	 * @testdox Should report a production platform connection as not sandbox.
	 */
	public function test_platform_connected_store_reports_a_production_environment(): void {
		$this->set_wallet_option(
			'woocommerce_paypal_wallet_platform',
			array(
				'merchant_id' => 'M3',
				'payee_email' => 'payee@example.com',
				'environment' => 'production',
			)
		);

		$merchant = $this->boot()->get( 'settings.rest.common' )->get_merchant_details()->get_data()['merchant'];

		$this->assertTrue( $merchant['isConnected'] );
		$this->assertFalse( $merchant['isSandbox'] );
		$this->assertSame( 'M3', $merchant['id'] );
	}

	/**
	 * @testdox Should keep the not-connected merchant details of a collecting store, which has no PayPal account yet.
	 */
	public function test_collecting_store_keeps_the_wallets_merchant_details(): void {
		$this->set_collecting();

		$merchant = $this->boot()->get( 'settings.rest.common' )->get_merchant_details()->get_data()['merchant'];

		$this->assertFalse( $merchant['isConnected'] );
		$this->assertSame( '', $merchant['id'] );
		$this->assertSame( '', $merchant['email'] );
	}

	/**
	 * @testdox Should leave the common route and the authentication route as the wallet's own for a first-party connected store.
	 */
	public function test_first_party_store_keeps_the_wallets_routes(): void {
		$this->set_first_party_connected();
		$container = $this->boot();

		$common = $container->get( 'settings.rest.common' );
		$this->assertSame( CommonRestEndpoint::class, get_class( $common ) );
		$this->assertSame( AuthenticationRestEndpoint::class, get_class( $container->get( 'settings.rest.authentication' ) ) );
		$merchant = $common->get_merchant_details()->get_data()['merchant'];
		$this->assertTrue( $merchant['isConnected'] );
		$this->assertSame( 'TESTMERCHANTID', $merchant['id'] );
		$this->assertSame( 'merchant@example.com', $merchant['email'] );
	}

	/**
	 * @testdox Should leave the common route and the authentication route as the wallet's own for a dormant store.
	 */
	public function test_dormant_store_keeps_the_wallets_routes(): void {
		$container = $this->boot();

		$this->assertSame( CommonRestEndpoint::class, get_class( $container->get( 'settings.rest.common' ) ) );
		$this->assertSame( AuthenticationRestEndpoint::class, get_class( $container->get( 'settings.rest.authentication' ) ) );
	}

	/**
	 * @testdox Should refuse a disconnect, with or without a reset, while served ($state), and write nothing.
	 * @testWith ["platform_connected", true]
	 *           ["platform_connected", false]
	 *           ["collecting", true]
	 *
	 * @param string $state The served state.
	 * @param bool   $reset Whether the request asks for a full reset.
	 */
	public function test_disconnect_is_refused_while_served( string $state, bool $reset ): void {
		$this->set_served_state( $state );
		$this->set_wallet_option( SettingsModel::OPTION_KEY, array( 'brand_name' => 'Stored brand' ) );
		$endpoint      = $this->boot()->get( 'settings.rest.authentication' );
		$disconnected  = $this->spy_filter( 'woocommerce_paypal_payments_merchant_disconnected' );
		$stored_before = $this->stored_wallet_options();
		$request       = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/authenticate/disconnect' );
		$request->set_param( 'reset', $reset );

		$this->assertInstanceOf( PlatformServedAuthenticationRestEndpoint::class, $endpoint );
		$body = $endpoint->disconnect( $request )->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'WooCommerce manages this PayPal connection, so it cannot be disconnected here.', $body['message'] );
		$this->assertCount( 0, $disconnected, 'The wallet did not disconnect' );
		$this->assertNotSame( array(), $stored_before, 'The store has wallet options to compare' );
		$this->assertSame( $stored_before, $this->stored_wallet_options(), 'No woocommerce-ppcp-* option and no ppcp-webhook is written or deleted' );
		$this->assertNotFalse( get_option( 'platform_connected' === $state ? 'woocommerce_paypal_wallet_platform' : 'woocommerce_paypal_wallet_collecting' ), 'The served state stays' );
	}

	/**
	 * @testdox Should refuse the disconnect route through the REST server while platform connected.
	 */
	public function test_disconnect_route_is_refused_through_the_rest_server(): void {
		$this->set_platform_connected();
		$this->boot();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$previous_server           = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server fires rest_api_init, which registers the routes of the container booted above.
		try {
			$response = rest_do_request( new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/authenticate/disconnect' ) );
		} finally {
			$GLOBALS['wp_rest_server'] = $previous_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
			wp_set_current_user( 0 );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertNotFalse( get_option( 'woocommerce_paypal_wallet_platform' ) );
	}
}
