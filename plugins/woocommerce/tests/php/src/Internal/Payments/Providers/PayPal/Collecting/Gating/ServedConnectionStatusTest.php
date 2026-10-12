<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedAuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedCommonRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\AuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\CommonRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use ArrayObject;
use ReflectionProperty;
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
	 * The platform apps' token and token rate-limit transients.
	 */
	private const TOKEN_TRANSIENTS = array(
		'wc_paypal_wallet_bearer_platform_ppcp-bearer',
		'wc_paypal_wallet_bearer_merchant_app_ppcp-bearer',
		'wc_paypal_wallet_rate_platform_bearer-circuit-state',
		'wc_paypal_wallet_rate_merchant_app_bearer-circuit-state',
	);

	/**
	 * Boot a container over a ready fake transport, for the state the test stored.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * The transient the wallet caches its product statuses in, the card (DCC) status among them.
	 */
	private const PRODUCT_STATUS_TRANSIENT = 'woocommerce-ppcp-cache-product-status';

	/**
	 * Start every test without an earlier test's seller status.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->forget_the_cached_seller_status();
	}

	/**
	 * Forget the seller status ProductStatus keeps for the rest of the request, as a new request would.
	 */
	private function forget_the_cached_seller_status(): void {
		$property = new ReflectionProperty( ProductStatus::class, 'seller_status' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * A disconnect request.
	 *
	 * @param bool $reset Whether the request asks for a full reset ("Start over").
	 * @return WP_REST_Request
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	private function disconnect_request( bool $reset = false ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/authenticate/disconnect' );
		$request->set_param( 'reset', $reset );

		return $request;
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
	 * Answer the seller status read with the given capabilities and products, and count the reads.
	 *
	 * @param array $capabilities The capabilities, as PayPal lists them.
	 * @param array $products     The products, as PayPal lists them.
	 * @return \ArrayObject The URLs of the seller status reads.
	 */
	private function answer_seller_status( array $capabilities, array $products = array() ): \ArrayObject {
		$reads = new \ArrayObject();
		$this->stub_http(
			function ( $request, $url ) use ( $capabilities, $products, $reads ) {
				unset( $request );
				if ( false === strpos( (string) $url, '/merchant-integrations/' ) ) {
					return new \WP_Error( 'unrouted', 'Unrouted' );
				}
				$reads[] = (string) $url;

				return $this->http_response(
					200,
					(string) wp_json_encode(
						array(
							'merchant_id'  => 'M2',
							'capabilities' => $capabilities,
							'products'     => $products,
						)
					)
				);
			}
		);

		return $reads;
	}

	/**
	 * @testdox Should report the platform merchant's seller type, resolved from its seller status as the wallet resolves a first-party one: $expected.
	 * @testWith ["SEND_INVOICE", "ACTIVE", "business"]
	 *           ["COMMERCIAL_ENTITY", "ACTIVE", "business"]
	 *           ["SEND_INVOICE", "INACTIVE", "unknown"]
	 *           ["PAYPAL_CHECKOUT", "ACTIVE", "unknown"]
	 *
	 * @param string $capability The capability the seller status lists.
	 * @param string $status     Its status.
	 * @param string $expected   The seller type the settings app gets.
	 */
	public function test_platform_connected_store_reports_the_seller_type( string $capability, string $status, string $expected ): void {
		$this->set_platform_connected();
		$reads    = $this->answer_seller_status(
			array(
				array(
					'name'   => $capability,
					'status' => $status,
				),
			)
		);
		$endpoint = $this->boot()->get( 'settings.rest.common' );

		foreach ( array( $endpoint->get_details(), $endpoint->get_merchant_details() ) as $response ) {
			$this->assertSame( $expected, $response->get_data()['merchant']['sellerType'] );
		}
		$this->assertCount( 1, $reads, 'One seller status read, cached for the second load' );
		$this->assertStringEndsWith( '/merchant-integrations/M2', $reads[0] );
		$this->assertSame( array(), $this->stored_wallet_options(), 'No woocommerce-ppcp-* option is written' );
	}

	/**
	 * @testdox Should report a business seller from a subscribed PPCP_CUSTOM product, as the probe's platform merchant lists it.
	 */
	public function test_platform_connected_store_reports_business_from_the_custom_product(): void {
		$this->set_platform_connected();
		$this->answer_seller_status(
			array(),
			array(
				array(
					'name'           => 'PPCP_CUSTOM',
					'vetting_status' => 'SUBSCRIBED',
				),
			)
		);

		$this->assertSame( 'business', $this->boot()->get( 'settings.rest.common' )->get_merchant_details()->get_data()['merchant']['sellerType'] );
	}

	/**
	 * @testdox Should keep the wallet's unknown seller type when the seller status cannot be read.
	 */
	public function test_platform_connected_store_keeps_unknown_when_the_read_fails(): void {
		$this->set_platform_connected();

		$merchant = $this->boot()->get( 'settings.rest.common' )->get_merchant_details()->get_data()['merchant'];

		$this->assertSame( 'unknown', $merchant['sellerType'] );
		$this->assertTrue( $merchant['isConnected'], 'The rest of the details stay' );
	}

	/**
	 * @testdox Should keep the wallet's seller type, and read no seller status, on a collecting store.
	 */
	public function test_collecting_store_keeps_the_wallets_seller_type(): void {
		$this->set_collecting();
		$reads = $this->answer_seller_status(
			array(
				array(
					'name'   => 'SEND_INVOICE',
					'status' => 'ACTIVE',
				),
			)
		);

		$merchant = $this->boot()->get( 'settings.rest.common' )->get_merchant_details()->get_data()['merchant'];

		$this->assertSame( 'unknown', $merchant['sellerType'] );
		$this->assertCount( 0, $reads );
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
	 * @testdox Should disconnect while served ($state, reset: $reset) as the wallet does, then leave the served state as after a first-party connect, keeping the held order, without a call to the transport's subscriptions.
	 * @testWith ["platform_connected", true]
	 *           ["platform_connected", false]
	 *           ["collecting", true]
	 *           ["collecting", false]
	 *
	 * @param string $state The served state.
	 * @param bool   $reset Whether the request asks for a full reset.
	 */
	public function test_disconnect_leaves_the_served_state( string $state, bool $reset ): void {
		$this->set_served_state( $state );
		$this->set_wallet_option( Options::SELLER_STATUS, array( 'payments_receivable' => true ) );
		$this->set_wallet_option( Options::PWC_CHECK, array( 'available' => true ) );
		$this->set_wallet_option( Options::WEBHOOKS, array( 'platform' => 'WH-PLATFORM' ) );
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_NATIVE );
		$this->set_wallet_option( SettingsModel::OPTION_KEY, array( 'brand_name' => 'Stored brand' ) );
		foreach ( self::TOKEN_TRANSIENTS as $name ) {
			$this->set_wallet_transient( $name, 'cached' );
		}
		$order = $this->held_order();
		$order->add_order_note( 'Payment held by PayPal.' );
		$transport = new FakePlatformTransport();
		$endpoint  = $this->boot_container( array( new TransportBindingModule( $transport ) ) )->get( 'settings.rest.authentication' );
		$served_at = new ArrayObject();
		add_action(
			'woocommerce_paypal_payments_merchant_disconnected',
			static function () use ( $served_at ): void {
				$served_at[] = ( new ConnectionState() )->resolve();
			}
		);

		$this->assertInstanceOf( PlatformServedAuthenticationRestEndpoint::class, $endpoint );
		$body = $endpoint->disconnect( $this->disconnect_request( $reset ) )->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertSame( 'OK', $body['data'] );
		foreach ( array( Options::PLATFORM, Options::COLLECTING, Options::SELLER_STATUS, Options::PWC_CHECK, PayPalWalletBootstrap::LAST_OWNER_OPTION ) as $name ) {
			$this->assertFalse( get_option( $name ), "$name is deleted" );
		}
		foreach ( self::TOKEN_TRANSIENTS as $name ) {
			$this->assertFalse( get_transient( $name ), "$name is deleted" );
		}
		$this->assertSame( array( $state ), $served_at->getArrayCopy(), 'The wallet disconnected while the store was still served' );
		$this->assertSame( ConnectionState::DORMANT, ( new ConnectionState() )->resolve() );
		$this->assertArrayNotHasKey( DCCProductStatus::KEY, (array) get_transient( self::PRODUCT_STATUS_TRANSIENT ), 'No card status is left behind' );
		$this->assertSame( array( 'platform' => 'WH-PLATFORM' ), get_option( Options::WEBHOOKS ), 'The record of the transport\'s subscriptions stays' );
		$this->assertSame( $reset, ! get_option( SettingsModel::OPTION_KEY ), 'Only "Start over" deletes the wallet settings' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'UNILATERAL', $order->get_meta( RefundLock::HELD_CAPTURE_META_KEY ) );
		$this->assertNotSame( '', $order->get_meta( HeldCapture::HELD_AT_META_KEY ) );
		$this->assertContains( 'Payment held by PayPal.', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );

		$this->assertSame( array(), $transport->calls_to( 'unsubscribe_webhooks' ) );
		$this->assertSame( array(), $transport->calls_to( 'subscribe_webhooks' ) );
		$urls = array_column( $this->http_requests, 'url' );
		$this->assertCount( 'platform_connected' === $state ? 1 : 0, $urls, 'Only the wallet\'s card status refill reads the seller status, and only for a platform merchant; the clear after leaving makes no request' );
		foreach ( $this->http_requests as $sent ) {
			$this->assertNotSame( 'DELETE', $sent['request']['method'] ?? '', "No DELETE request: {$sent['url']}" );
			$this->assertStringEndsWith( '/merchant-integrations/M2', $sent['url'] );
		}
	}

	/**
	 * @testdox Should not carry the platform merchant's card eligibility into a later collecting state.
	 */
	public function test_card_status_from_the_platform_merchant_does_not_survive_into_collecting(): void {
		$this->set_platform_connected();
		$this->answer_seller_status(
			array(),
			array(
				array(
					'name'           => 'PPCP_CUSTOM',
					'vetting_status' => 'SUBSCRIBED',
					'capabilities'   => array( 'CUSTOM_CARD_PROCESSING' ),
				),
			)
		);
		$this->set_wallet_transient( self::PRODUCT_STATUS_TRANSIENT, array() );
		$cached_during = new ArrayObject();
		add_action(
			'woocommerce_paypal_payments_merchant_disconnected',
			static function () use ( $cached_during ): void {
				add_action(
					'woocommerce_paypal_payments_clear_apm_product_status',
					static function () use ( $cached_during ): void {
						$cached_during[] = ( (array) get_transient( self::PRODUCT_STATUS_TRANSIENT ) )[ DCCProductStatus::KEY ]['value'] ?? null;
					},
					30
				);
			}
		);

		$this->boot()->get( 'settings.rest.authentication' )->disconnect( $this->disconnect_request() );

		$this->assertSame( array( ProductStatus::STATE_IS_ENABLED ), $cached_during->getArrayCopy(), 'The wallet\'s refill cached the platform merchant\'s card eligibility' );
		$this->assertArrayNotHasKey( DCCProductStatus::KEY, (array) get_transient( self::PRODUCT_STATUS_TRANSIENT ) );

		$this->set_collecting();
		$this->forget_the_cached_seller_status();
		$dcc = $this->boot()->get( 'wcgateway.helper.dcc-product-status' );

		$this->assertFalse( $dcc->is_active(), 'A collecting store has no card eligibility' );
	}

	/**
	 * @testdox Should run only the wallet's disconnect for a store that is not served, leaving a collecting option that first-party credentials outrank.
	 */
	public function test_disconnect_of_a_store_that_is_not_served_keeps_the_platform_options(): void {
		$this->set_first_party_connected();
		$this->set_collecting();
		$container = $this->boot();
		$endpoint  = new PlatformServedAuthenticationRestEndpoint(
			$container->get( 'settings.service.authentication_manager' ),
			$container->get( 'settings.service.data-manager' ),
			$container->get( 'collecting.connection-state' ),
			$container->get( 'collecting.state' ),
			$container->get( 'wcgateway.helper.dcc-product-status' )
		);

		$body = $endpoint->disconnect( $this->disconnect_request() )->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertFalse( GeneralSettings::read_connection_from_options()['connected'], 'The wallet disconnected the first-party merchant' );
		$this->assertNotFalse( get_option( Options::COLLECTING ), 'Nothing of the platform state is deleted' );
	}

	/**
	 * @testdox Should answer the disconnect route through the REST server with success while platform connected, and leave the platform.
	 */
	public function test_disconnect_route_succeeds_through_the_rest_server(): void {
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
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertFalse( get_option( 'woocommerce_paypal_wallet_platform' ) );
	}
}
