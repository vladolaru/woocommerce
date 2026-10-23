<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Seams;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\ApiModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\Orders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\SavePaymentMethodsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WC_Order;

/**
 * Tests for seam 10, the create-order body filter fired from `Orders::create()`, and for the Pay with Crypto order a
 * platform-connected store sends through it: the payee, the seller's assertion and the order's pin.
 *
 * @group paypal-wallet
 */
class CryptoOrderSeamTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The order creation requests the stub answered: one array( 'url', 'headers', 'body' ) per request, the body decoded.
	 *
	 * @var array<int, array{url: string, headers: array, body: mixed}>
	 */
	private array $creations = array();

	/**
	 * The order reads the stub answered: one array( 'url', 'headers' ) per request.
	 *
	 * @var array<int, array{url: string, headers: array}>
	 */
	private array $reads = array();

	/**
	 * Drop the notices the gateway may add.
	 */
	public function tearDown(): void {
		wc_clear_notices();

		parent::tearDown();
	}

	/**
	 * A crypto order creation body, as the gateway builds it.
	 *
	 * @return array
	 */
	private function crypto_body(): array {
		return array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => 'default',
					'amount'       => array(
						'currency_code' => 'USD',
						'value'         => '10.00',
					),
				),
			),
			'payment_source' => array(
				'crypto' => array(
					'country_code' => 'US',
					'name'         => array(
						'given_name' => 'Ada',
						'surname'    => 'Lovelace',
					),
				),
			),
		);
	}

	/**
	 * Answer every order creation with an order that awaits the payer, and every token request with a token; record the
	 * creations.
	 */
	private function stub_paypal(): void {
		$this->stub_http(
			function ( $request, $url ) {
				if ( false !== strpos( (string) $url, 'v1/oauth2/token' ) ) {
					return $this->http_response( 200, '{"access_token":"token-first-party","expires_in":32400}' );
				}
				if ( 1 === preg_match( '#/v2/checkout/orders$#', (string) $url ) ) {
					$this->creations[] = array(
						'url'     => (string) $url,
						'headers' => (array) ( $request['headers'] ?? array() ),
						'body'    => json_decode( (string) ( $request['body'] ?? '' ), true ),
					);

					return $this->http_response(
						200,
						(string) wp_json_encode(
							array(
								'id'     => 'PP-CRYPTO-1',
								'status' => 'PAYER_ACTION_REQUIRED',
								'links'  => array(
									array(
										'rel'  => 'payer-action',
										'href' => 'https://www.sandbox.paypal.test/checkoutnow?token=PP-CRYPTO-1',
									),
								),
							)
						)
					);
				}

				if ( 1 === preg_match( '#/v2/checkout/orders/[^/]+$#', (string) $url ) ) {
					$this->reads[] = array(
						'url'     => (string) $url,
						'headers' => (array) ( $request['headers'] ?? array() ),
					);

					return $this->http_response( 200, '{"id":"PP-CRYPTO-1","purchase_units":[{"payee":{"merchant_id":"TYPED","email_address":"typed@example.com"}}]}' );
				}

				return new \WP_Error( 'unrouted', 'Unrouted' );
			}
		);
	}

	/**
	 * The Orders endpoint over a canned bearer.
	 *
	 * @param string $host The API host.
	 * @return Orders
	 */
	private function orders( string $host = 'https://api.example.test' ): Orders {
		return new Orders( $host, $this->make_bearer( false ), new NullLogger() );
	}

	/**
	 * @testdox Should fire the create-order body filter with the body, an empty payment method and empty request data, and send the filtered body.
	 */
	public function test_create_fires_the_body_filter_and_sends_its_result(): void {
		$this->stub_paypal();
		$calls = $this->spy_filter( 'ppcp_create_order_request_body_data', array( 'filtered' => true ) );

		$this->orders()->create( $this->crypto_body() );

		$this->assertCount( 1, $calls );
		$this->assertSame( array( $this->crypto_body(), '', array() ), $calls[0] );
		$this->assertSame( array( 'filtered' => true ), $this->creations[0]['body'] );
	}

	/**
	 * @testdox Should send the body as built when a callback of the body filter returns a value that is not an array.
	 */
	public function test_create_ignores_a_filtered_value_that_is_not_an_array(): void {
		$this->stub_paypal();
		$this->spy_filter( 'ppcp_create_order_request_body_data', 'not a body' );

		$this->orders()->create( $this->crypto_body() );

		$this->assertSame( $this->crypto_body(), $this->creations[0]['body'] );
	}

	/**
	 * The callbacks on the create-order body filter that a module's run() added: closures bound to an instance of the class.
	 *
	 * @param string $module_class The module class.
	 * @return int
	 */
	private function body_filter_callbacks_of( string $module_class ): int {
		$found = 0;
		foreach ( $GLOBALS['wp_filter']['ppcp_create_order_request_body_data']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( $callback['function'] instanceof \Closure && ( new \ReflectionFunction( $callback['function'] ) )->getClosureThis() instanceof $module_class ) {
					++$found;
				}
			}
		}

		return $found;
	}

	/**
	 * @testdox Should leave a crypto body unchanged through the wallet's own callbacks of the body filter: the line item names and the vault attributes.
	 */
	public function test_the_wallets_callbacks_leave_a_crypto_body_unchanged(): void {
		$item_name_callbacks = $this->body_filter_callbacks_of( ApiModule::class );
		// The wallet's line item name callback, registered by the API module on every boot.
		$this->boot_container();
		// The vault callback, registered once wallet saving is on, as the module test registers it.
		$settings = $this->mock( SettingsProvider::class );
		$settings->shouldReceive( 'save_paypal_and_venmo' )->andReturn( true );
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'save-payment-methods.eligible' )->andReturn( true );
		$container->shouldReceive( 'get' )->with( 'settings.settings-provider' )->andReturn( $settings );
		$vault_callbacks = $this->body_filter_callbacks_of( SavePaymentMethodsModule::class );
		remove_all_actions( 'after_setup_theme' );
		( new SavePaymentMethodsModule() )->run( $container );
		do_action( 'after_setup_theme' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'ppcp_customer_id', 'CUSTOMER-1' );
		wp_set_current_user( $user_id );
		$this->stub_paypal();

		$this->orders()->create( $this->crypto_body() );

		$this->assertSame( $item_name_callbacks + 1, $this->body_filter_callbacks_of( ApiModule::class ), 'The boot added the line item name callback' );
		$this->assertSame( $vault_callbacks + 1, $this->body_filter_callbacks_of( SavePaymentMethodsModule::class ), 'The module added the vault callback' );
		$this->assertSame( $this->crypto_body(), $this->creations[0]['body'] );
	}

	/**
	 * @testdox Should not fire the body filter for an order creation without the crypto payment source.
	 */
	public function test_create_fires_no_filter_without_the_crypto_source(): void {
		$this->stub_paypal();
		$calls = $this->spy_filter( 'ppcp_create_order_request_body_data', array( 'filtered' => true ) );
		$body  = $this->crypto_body();
		unset( $body['payment_source'] );

		$this->orders()->create( $body );

		$this->assertCount( 0, $calls );
		$this->assertSame( $body, $this->creations[0]['body'] );
	}

	/**
	 * @testdox Should name no payee on the manual connection's check order of a store the platform serves, and read it back with the typed-in credentials alone: $state.
	 * @testWith ["collecting"]
	 *           ["platform_connected"]
	 *
	 * @param string $state The store state.
	 */
	public function test_the_manual_connection_check_order_names_no_payee( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} else {
			$this->set_platform_connected();
		}
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		// A platform app token is cached, as on a store that has made platform calls.
		$this->set_wallet_transient( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', '{"access_token":"token-platform","expires_in":32400}' );
		$this->stub_paypal();
		// The body AuthenticationManager::request_payee() sends with the credentials the merchant typed in.
		$check_body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount' => array(
						'currency_code' => 'USD',
						'value'         => 1.0,
					),
				),
			),
		);

		// On the PayPal host the transport uses, as request_payee() builds its Orders with the typed-in credentials.
		$orders = $this->orders( 'https://api.platform.fake.test' );

		$orders->create( $check_body );
		$orders->order( 'PP-CRYPTO-1' );

		$this->assertArrayNotHasKey( 'payee', $this->creations[0]['body']['purchase_units'][0] );
		$this->assertFalse( isset( $this->creations[0]['headers']['PayPal-Auth-Assertion'] ), 'No assertion' );
		$this->assertCount( 1, $this->reads );
		$this->assertSame( 'Bearer bearer', $this->reads[0]['headers']['Authorization'] ?? null, 'The read-back keeps the typed-in credentials' );
		$this->assertFalse( isset( $this->reads[0]['headers']['PayPal-Auth-Assertion'] ), 'No assertion on the read-back' );
	}

	/**
	 * Run the Pay with Crypto gateway of a booted container on a USD order, with a cart to empty.
	 *
	 * @param ContainerInterface $container The booted container.
	 * @return array{0: array, 1: WC_Order} The gateway's result, and the order read back.
	 */
	private function pay_with_crypto( ContainerInterface $container ): array {
		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( GatewayIds::PWC );
		$order->set_billing_country( 'US' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_total( '10.00' );
		$order->save();
		$this->stub_paypal();

		$result = $container->get( 'ppcp-local-apms.pwc.wc-gateway' )->process_payment( $order->get_id() );

		return array( $result, wc_get_order( $order->get_id() ) );
	}

	/**
	 * @testdox Should name the platform merchant as payee, send the seller's assertion and pin the order to the platform app for a crypto order of a platform-connected store.
	 */
	public function test_a_platform_connected_crypto_order_is_made_for_the_seller(): void {
		$this->set_platform_connected();
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );

		list( $result, $order ) = $this->pay_with_crypto( $container );

		$this->assertSame( 'success', $result['result'], 'The gateway created the order' );
		$this->assertCount( 1, $this->creations );
		$this->assertSame( 'https://api.platform.fake.test/v2/checkout/orders', $this->creations[0]['url'] );
		$this->assertSame( 'M2', $this->creations[0]['body']['purchase_units'][0]['payee']['merchant_id'] ?? null );
		$this->assertTrue( isset( $this->creations[0]['headers']['PayPal-Auth-Assertion'] ), 'The request carries the seller\'s assertion' );
		$this->assertSame( 'Bearer token-' . PlatformTransport::APP_PLATFORM, $this->creations[0]['headers']['Authorization'] ?? null );
		$this->assertSame( 'PP-CRYPTO-1', $order->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$this->assertSame( PlatformTransport::APP_PLATFORM, $order->get_meta( OrderAppContext::ORDER_APP_META_KEY ), 'Pinned by the gateway\'s own save' );
	}

	/**
	 * @testdox Should add no payee, no assertion and no pin to a crypto order of a first-party store.
	 */
	public function test_a_first_party_crypto_order_is_left_as_the_wallet_builds_it(): void {
		$this->set_first_party_connected();
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );

		list( $result, $order ) = $this->pay_with_crypto( $container );

		$this->assertSame( 'success', $result['result'], 'The gateway created the order' );
		$this->assertCount( 1, $this->creations );
		$this->assertArrayNotHasKey( 'payee', $this->creations[0]['body']['purchase_units'][0] );
		$this->assertFalse( isset( $this->creations[0]['headers']['PayPal-Auth-Assertion'] ), 'No assertion' );
		$this->assertSame( 'PP-CRYPTO-1', $order->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$this->assertSame( '', $order->get_meta( OrderAppContext::ORDER_APP_META_KEY ), 'No pin' );
	}
}
