<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Rest;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PayPal;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\ProviderRow;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Payment_Gateway;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests that the setup notice on the PayPal Wallet row survives the Payments providers REST route.
 *
 * The route keeps only the keys its schema names, so the `_notice` the provider adds has to be in that schema.
 *
 * @group paypal-wallet
 */
class PaymentsProvidersRestTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The route that lists the providers.
	 */
	private const ROUTE = '/wc-admin/settings/payments/providers';

	/**
	 * The REST server the test registers the controller on.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * The Payments service the controller reads, with the providers each test sets.
	 *
	 * @var Payments&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $payments;

	/**
	 * Register the controller over a mocked Payments service and sign in an administrator.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->payments = $this->getMockBuilder( Payments::class )->getMock();
		$controller     = new PaymentsRestController();
		$controller->init( $this->payments );
		$this->server = $this->create_rest_server_with_routes(
			array(
				function () use ( $controller ) {
					$controller->register_routes( true );
				},
			),
			true
		);
	}

	/**
	 * Drop the REST server and the signed-in user.
	 */
	public function tearDown(): void {
		try {
			$this->clear_rest_server();
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build a payment gateway with the given ID.
	 *
	 * @param string $id The gateway ID.
	 * @return WC_Payment_Gateway
	 */
	private function fake_gateway( string $id ): WC_Payment_Gateway {
		$gateway                 = $this->getMockBuilder( WC_Payment_Gateway::class )->onlyMethods( array( 'get_method_title' ) )->getMock();
		$gateway->id             = $id;
		$gateway->extension_type = PaymentsProviders::EXTENSION_TYPE_WPORG;
		$gateway->plugin_file    = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';
		$gateway->method_title   = 'Gateway ' . $id;
		$gateway->method( 'get_method_title' )->willReturn( 'Gateway ' . $id );

		return $gateway;
	}

	/**
	 * Make the Payments service return the PayPal row and a plain gateway row, built the way the provider list builds them.
	 */
	private function serve_providers(): void {
		$paypal = new PayPal( wc_get_container()->get( LegacyProxy::class ) );
		$plain  = new PaymentsProviders\PaymentGateway( wc_get_container()->get( LegacyProxy::class ) );

		$this->payments->method( 'get_payment_providers' )->willReturn(
			array(
				array( '_type' => PaymentsProviders::TYPE_GATEWAY ) + $paypal->get_details( $this->fake_gateway( 'ppcp-gateway' ), 0 ),
				array( '_type' => PaymentsProviders::TYPE_GATEWAY ) + $plain->get_details( $this->fake_gateway( 'other-gateway' ), 1 ),
			)
		);
	}

	/**
	 * Get the providers from the route.
	 *
	 * @return array[] The providers, keyed by ID.
	 */
	private function get_providers_by_id(): array {
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertSame( 200, $response->get_status(), (string) ( $response->get_data()['data']['exception_message'] ?? '' ) );
		$by_id = array();
		foreach ( $response->get_data()['providers'] as $provider ) {
			$by_id[ $provider['id'] ] = $provider;
		}

		return $by_id;
	}

	/**
	 * @testdox Should carry the "Complete setup to receive your payment" notice on the PayPal row, and no `_notice` on a row without one.
	 */
	public function test_the_route_carries_the_notice_of_a_collecting_store_with_a_first_order(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		( new ProviderRow() )->register();
		$this->serve_providers();

		$providers = $this->get_providers_by_id();

		$this->assertArrayHasKey( 'ppcp-gateway', $providers );
		$notice = $providers['ppcp-gateway']['_notice'] ?? null;
		$this->assertIsArray( $notice, 'The notice reaches the client' );
		$this->assertSame( 'Complete setup to receive your payment', $notice['title'] );
		$this->assertSame( 'A customer placed an order and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', $notice['text'] );
		$this->assertSame( 'Complete setup', $notice['action_label'] );
		$this->assertNotEmpty( $notice['action_url'] );
		$this->assertTrue( $notice['dismissible'] );
		$this->assertStringContainsString( 'wc-ajax=wc_paypal_wallet_dismiss_notice', $notice['dismiss_url'] );
		$this->assertArrayNotHasKey( '_notice', $providers['other-gateway'] ?? array(), 'A row without a notice has no `_notice` key' );
	}

	/**
	 * @testdox Should carry no `_notice` on any row when the store has no first order to report.
	 */
	public function test_the_route_carries_no_notice_without_a_first_order(): void {
		$this->set_collecting();
		( new ProviderRow() )->register();
		$this->serve_providers();

		$providers = $this->get_providers_by_id();

		$this->assertArrayHasKey( 'ppcp-gateway', $providers );
		$this->assertArrayNotHasKey( '_notice', $providers['ppcp-gateway'] );
		$this->assertArrayNotHasKey( '_notice', $providers['other-gateway'] );
	}

	/**
	 * @testdox Should give the PayPal row a connected, onboarded sandbox account once the platform connected the merchant, and a not connected one while collecting.
	 * @testWith ["platform_connected", true]
	 *           ["collecting", false]
	 *
	 * @param string $state     The wallet state to put the store in.
	 * @param bool   $connected Whether the row reads as connected.
	 */
	public function test_the_route_carries_the_connection_state_of_a_served_store( string $state, bool $connected ): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();
		if ( 'platform_connected' === $state ) {
			$this->set_platform_connected();
		} else {
			$this->set_collecting();
		}
		$this->serve_providers();

		try {
			$providers = $this->get_providers_by_id();
		} finally {
			remove_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
			wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();
		}

		$row = $providers['ppcp-gateway'];
		$this->assertSame( $connected, $row['state']['account_connected'] );
		$this->assertSame( ! $connected, $row['state']['needs_setup'] );
		$this->assertSame( $connected, $row['onboarding']['state']['started'] );
		$this->assertSame( $connected, $row['onboarding']['state']['completed'] );
		if ( $connected ) {
			$this->assertTrue( $row['onboarding']['state']['test_mode'], 'The platform connection is a sandbox one' );
		}
	}

	/**
	 * @testdox Should describe the `_notice` of a provider in the route schema, as an optional read-only object.
	 */
	public function test_the_route_schema_describes_the_notice(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'OPTIONS', self::ROUTE ) );
		$schema   = $response->get_data()['schema'] ?? array();

		$provider_schema = $schema['properties']['providers']['items'] ?? array();
		$notice_schema   = $provider_schema['properties']['_notice'] ?? array();

		$this->assertSame( 'object', $notice_schema['type'] ?? null );
		$this->assertTrue( $notice_schema['readonly'] ?? false );
		$this->assertSame(
			array( 'title', 'text', 'action_label', 'action_url', 'dismissible', 'dismiss_url' ),
			array_keys( $notice_schema['properties'] ?? array() )
		);
		$this->assertNotContains( '_notice', $provider_schema['required'] ?? array() );
	}
}
