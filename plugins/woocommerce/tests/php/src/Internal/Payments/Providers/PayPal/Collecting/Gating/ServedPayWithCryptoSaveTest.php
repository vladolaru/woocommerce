<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests the settings app's REST routes on a platform-connected store with Pay with Crypto available.
 *
 * Two cases guard the server side of the app's Save: the payment route turns the method on, and no other request of a
 * full Save writes it back off. Neither depends on the D1 fix. The D1 regression guard is the onboarding route's answer
 * (`test_the_onboarding_route_reports_the_gateway_refresh_done`, and `ServedSettingsPanelTest` for both served states);
 * the app's reaction to it is pinned by the settings app's `usePaymentGatewayRefresh.test.js`.
 *
 * @group paypal-wallet
 */
class ServedPayWithCryptoSaveTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The option the Pay with Crypto gateway's settings are stored in.
	 */
	private const PWC_OPTION = 'woocommerce_ppcp-pwc_settings';

	/**
	 * WooCommerce's gateway list before the test rebuilt it.
	 *
	 * @var array
	 */
	private array $original_gateways = array();

	/**
	 * The store currency before the test.
	 *
	 * @var mixed
	 */
	private $original_currency;

	/**
	 * The REST server before the test replaced it.
	 *
	 * @var mixed
	 */
	private $original_server;

	/**
	 * A platform-connected store whose Pay with Crypto check was accepted, the wallet gateway on and Pay with Crypto off,
	 * booted as a served request, with a fresh REST server and an administrator.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_gateways = WC()->payment_gateways()->payment_gateways;
		$this->original_currency = get_option( 'woocommerce_currency' );
		$this->original_server   = $GLOBALS['wp_rest_server'] ?? null;
		update_option( 'woocommerce_currency', 'USD' );

		$this->set_platform_connected();
		$this->set_wallet_option(
			Options::PWC_CHECK,
			array(
				'available'   => true,
				'checked_at'  => 1760000000,
				'environment' => 'sandbox',
				'merchant_id' => 'M2',
			)
		);
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			)
		);
		$this->set_wallet_option(
			self::PWC_OPTION,
			array(
				'enabled'     => 'no',
				'title'       => 'Pay with Crypto',
				'description' => 'Pay with top wallets and coins.',
			)
		);

		remove_all_actions( 'after_setup_theme' );
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		do_action( 'after_setup_theme' );
		WC()->payment_gateways()->init();

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server fires rest_api_init, which registers the routes of the container booted above.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Put WooCommerce's gateway list, the currency and the REST server back.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->original_gateways;
			update_option( 'woocommerce_currency', $this->original_currency );
			$GLOBALS['wp_rest_server'] = $this->original_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Send a request to one of the settings app's routes.
	 *
	 * @param string $method The HTTP method.
	 * @param string $route  The route under wc/v3/wc_paypal.
	 * @param array  $body   The JSON body, for a POST.
	 * @return WP_REST_Response
	 */
	private function app_request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wc/v3/wc_paypal/' . $route );
		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * The data of a route, as the settings app hydrates its store with it.
	 *
	 * @param string $route The route under wc/v3/wc_paypal.
	 * @return array
	 */
	private function app_data( string $route ): array {
		$response = $this->app_request( 'GET', $route );
		$this->assertSame( 200, $response->get_status(), "GET $route" );

		return $response->get_data()['data'];
	}

	/**
	 * Whether the stored Pay with Crypto settings have the gateway on, read past the options cache.
	 *
	 * @return string|null
	 */
	private function stored_pwc_enabled(): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PWC_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The stored row, past the options cache.
		$row = is_string( $raw ) ? maybe_unserialize( $raw ) : null;

		return is_array( $row ) ? ( $row['enabled'] ?? null ) : null;
	}

	/**
	 * @testdox Should turn Pay with Crypto on through the payment route on a platform-connected store.
	 */
	public function test_payment_route_turns_pay_with_crypto_on(): void {
		$methods = $this->app_data( 'payment' );
		$this->assertArrayHasKey( PWCGateway::ID, $methods, 'The settings app lists Pay with Crypto' );
		$this->assertFalse( $methods[ PWCGateway::ID ]['enabled'] );
		$methods[ PWCGateway::ID ]['enabled'] = true;

		$response = $this->app_request( 'POST', 'payment', $methods );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['data'][ PWCGateway::ID ]['enabled'] );
		$this->assertSame( 'yes', $this->stored_pwc_enabled() );
	}

	/**
	 * @testdox Should report the gateway refresh as done through the onboarding route of a platform-connected store.
	 */
	public function test_the_onboarding_route_reports_the_gateway_refresh_done(): void {
		// While this reads false, the settings app runs its gateway refresh (reset, then reload the payment methods) on
		// the first Payment Methods visit after each page load. A reload that lands after the merchant's toggle puts the
		// stored "off" back, and the Save sends it (D1).
		$onboarding = $this->app_data( 'onboarding' );

		$this->assertTrue( $onboarding['gatewaysSynced'] );
		$this->assertTrue( $onboarding['gatewaysRefreshed'] );
	}

	/**
	 * @testdox Should keep Pay with Crypto on when the other requests of a full Save run after the payment route.
	 */
	public function test_no_other_save_request_writes_pay_with_crypto_off(): void {
		$bodies = array();
		foreach ( array( 'settings', 'styling', 'todos', 'pay_later_messaging' ) as $route ) {
			$bodies[ $route ] = $this->app_data( $route );
		}
		$onboarding                           = $this->app_data( 'onboarding' );
		$onboarding['gatewaysRefreshed']      = true;
		$methods                              = $this->app_data( 'payment' );
		$methods[ PWCGateway::ID ]['enabled'] = true;

		// The payment request first, then every other request of the Save, each reading the store after the write.
		$statuses = array( 'payment' => $this->app_request( 'POST', 'payment', $methods )->get_status() );
		for ( $i = 1; $i <= 3; $i++ ) {
			$statuses[ "onboarding $i" ] = $this->app_request( 'POST', 'onboarding', $onboarding )->get_status();
		}
		foreach ( $bodies as $route => $body ) {
			$statuses[ $route ] = $this->app_request( 'POST', $route, $body )->get_status();
		}

		$this->assertSame( array_fill_keys( array_keys( $statuses ), 200 ), $statuses );
		$this->assertSame( 'yes', $this->stored_pwc_enabled(), 'No later request of the Save writes Pay with Crypto back off' );
		$this->assertTrue( $this->app_data( 'payment' )[ PWCGateway::ID ]['enabled'], 'The reload after the Save reads it on' );
	}
}
