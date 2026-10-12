<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AssertedRefundSigner;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AuthAssertion;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;

/**
 * Tests for the signer that sends a capture refund, a crypto order creation and the platform app's order and capture
 * reads through the platform app with the seller's assertion.
 *
 * @group paypal-wallet
 */
class AssertedRefundSignerTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	private const REFUND_URL = 'https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1/refund';

	private const CREATE_URL = 'https://api.platform.fake.test/v2/checkout/orders';

	/**
	 * The System Under Test.
	 *
	 * @var AssertedRefundSigner
	 */
	private AssertedRefundSigner $sut;

	/**
	 * The order app context of the System Under Test.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * Build the signer over a fake transport.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->context = new OrderAppContext();
		$this->sut     = $this->signer( new FakePlatformTransport(), new RecordingLogger(), $this->context );
	}

	/**
	 * A signer over the given transport.
	 *
	 * @param PlatformTransport    $transport The transport.
	 * @param RecordingLogger      $logger    The logger.
	 * @param OrderAppContext|null $context   The order app context, or a new one.
	 * @return AssertedRefundSigner
	 */
	private function signer( PlatformTransport $transport, RecordingLogger $logger, ?OrderAppContext $context = null ): AssertedRefundSigner {
		return new AssertedRefundSigner( new ConnectionState(), $transport, $context ?? new OrderAppContext(), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), $logger );
	}

	/**
	 * Request arguments signed by the merchant app, as the refund endpoint builds them for a merchant-app order.
	 *
	 * @param string $method The HTTP method.
	 * @return array
	 */
	private function args( string $method = 'POST' ): array {
		return array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer token-merchant_app',
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			),
			'body'    => '{"amount":{"value":"5.00","currency_code":"USD"}}',
		);
	}

	/**
	 * Order creation arguments, as the Orders endpoint builds them for the Pay with Crypto gateway, or with another body.
	 *
	 * @param array|null $payment_source The body's payment source, or null for none.
	 * @param string     $method         The HTTP method.
	 * @return array
	 */
	private function create_args( ?array $payment_source = array( 'crypto' => array( 'country_code' => 'US' ) ), string $method = 'POST' ): array {
		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount' => array(
						'currency_code' => 'USD',
						'value'         => '10.00',
					),
				),
			),
		);
		if ( null !== $payment_source ) {
			$body['payment_source'] = $payment_source;
		}

		return array(
			'method'  => $method,
			'headers' => array(
				'Authorization'     => 'Bearer token-platform',
				'Content-Type'      => 'application/json',
				'PayPal-Request-Id' => 'ppcp-1',
			),
			'body'    => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * @testdox Should sign a crypto order creation of a platform-connected store with the platform app's token and the seller's assertion: $url.
	 * @testWith ["https://api.platform.fake.test/v2/checkout/orders"]
	 *           ["https://api.platform.fake.test/v2/checkout/orders/"]
	 *           ["https://api.platform.fake.test/v2/checkout/orders?debug=1"]
	 *           ["https://api.merchant-app.fake.test/v2/checkout/orders"]
	 *
	 * @param string $url The request URL.
	 */
	public function test_signs_a_crypto_order_creation_for_the_connected_seller( string $url ): void {
		$this->set_platform_connected();

		$signed = $this->sut->handle_ppcp_request_args( $this->create_args(), $url );

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertSame( AuthAssertion::header( 'client-platform', 'M2' )['PayPal-Auth-Assertion'], $signed['headers']['PayPal-Auth-Assertion'] );
		$this->assertSame( 'ppcp-1', $signed['headers']['PayPal-Request-Id'], 'Other headers stay' );
		$this->assertSame( $this->create_args()['body'], $signed['body'] );
	}

	/**
	 * @testdox Should leave an order creation that is not a crypto one, or a crypto body on another order call, as the wallet signed it: $description.
	 * @testWith ["a PayPal button order", "POST", "https://api.platform.fake.test/v2/checkout/orders", {"paypal": {}}]
	 *           ["an order with no payment source", "POST", "https://api.platform.fake.test/v2/checkout/orders", null]
	 *           ["a GET on the create path", "GET", "https://api.platform.fake.test/v2/checkout/orders", {"crypto": {}}]
	 *           ["a confirm-payment-source call", "POST", "https://api.platform.fake.test/v2/checkout/orders/PP-1/confirm-payment-source", {"crypto": {}}]
	 *           ["a capture", "POST", "https://api.platform.fake.test/v2/checkout/orders/PP-1/capture", {"crypto": {}}]
	 *           ["a path that only starts with the create path", "POST", "https://api.platform.fake.test/v2/checkout/orders-x", {"crypto": {}}]
	 *           ["a creation on another host", "POST", "https://api.example.com/v2/checkout/orders", {"crypto": {}}]
	 *
	 * @param string     $description    What the request is.
	 * @param string     $method         The HTTP method.
	 * @param string     $url            The request URL.
	 * @param array|null $payment_source The body's payment source.
	 */
	public function test_leaves_other_order_calls_alone( string $description, string $method, string $url, ?array $payment_source ): void {
		unset( $description );
		$this->set_platform_connected();
		$args = $this->create_args( $payment_source, $method );

		$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, $url ) );
	}

	/**
	 * @testdox Should leave an order creation alone when its body is not JSON or names no payment source object.
	 */
	public function test_leaves_an_unreadable_creation_body_alone(): void {
		$this->set_platform_connected();
		foreach ( array( 'not json', '"crypto"', '{"payment_source":"crypto"}' ) as $body ) {
			$args         = $this->create_args();
			$args['body'] = $body;

			$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, self::CREATE_URL ), $body );
		}
		$args = $this->create_args();
		unset( $args['body'] );
		$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, self::CREATE_URL ), 'No body' );
	}

	/**
	 * @testdox Should leave a crypto order creation alone unless the store is platform connected: $state.
	 * @testWith ["collecting"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The store state.
	 */
	public function test_leaves_a_crypto_creation_alone_unless_platform_connected( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} elseif ( 'first_party' === $state ) {
			$this->set_platform_connected();
			$this->set_first_party_connected();
		}

		$this->assertSame( $this->create_args(), $this->sut->handle_ppcp_request_args( $this->create_args(), self::CREATE_URL ) );
		$this->assertSame( $this->create_args(), $this->sut->handle_ppcp_retry_request_args( $this->create_args(), self::CREATE_URL ) );
	}

	/**
	 * @testdox Should sign a capture refund of a platform-connected store with the platform app's token and the seller's assertion.
	 */
	public function test_signs_a_capture_refund_for_the_connected_seller(): void {
		$this->set_platform_connected();

		$signed = $this->sut->handle_ppcp_request_args( $this->args(), self::REFUND_URL );

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertSame( AuthAssertion::header( 'client-platform', 'M2' )['PayPal-Auth-Assertion'], $signed['headers']['PayPal-Auth-Assertion'] );
		$this->assertSame( 'application/json', $signed['headers']['Content-Type'], 'Other headers stay' );
		$this->assertSame( $this->args()['body'], $signed['body'] );
	}

	/**
	 * @testdox Should sign the retry of a capture refund with the platform app too, after the retry filter that re-signs with the order's app.
	 */
	public function test_signs_the_retry_of_a_capture_refund(): void {
		$this->set_platform_connected();

		$signed = $this->sut->handle_ppcp_retry_request_args( $this->args(), self::REFUND_URL );

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertArrayHasKey( 'PayPal-Auth-Assertion', $signed['headers'] );
	}

	/**
	 * @testdox Should leave every other request of a platform-connected store's merchant-app order on its own app: $description.
	 * @testWith ["the order GET before the refund", "GET", "https://api.merchant-app.fake.test/v2/checkout/orders/PP-1"]
	 *           ["a capture read", "GET", "https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1"]
	 *           ["a refund read", "GET", "https://api.merchant-app.fake.test/v2/payments/refunds/REFUND-1"]
	 *           ["a GET on the refund path", "GET", "https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1/refund"]
	 *           ["an authorization void", "POST", "https://api.merchant-app.fake.test/v2/payments/authorizations/AUTH-1/void"]
	 *           ["an order capture", "POST", "https://api.merchant-app.fake.test/v2/checkout/orders/PP-1/capture"]
	 *           ["a refund on another host", "POST", "https://api.example.com/v2/payments/captures/CAPTURE-1/refund"]
	 *
	 * @param string $description What the request is.
	 * @param string $method      The HTTP method.
	 * @param string $url         The request URL.
	 */
	public function test_leaves_other_requests_alone( string $description, string $method, string $url ): void {
		unset( $description );
		$this->set_platform_connected();
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );

		$this->assertSame( $this->args( $method ), $this->sut->handle_ppcp_request_args( $this->args( $method ), $url ) );
	}

	/**
	 * @testdox Should leave a capture refund alone unless the store is platform connected: $state.
	 * @testWith ["collecting"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The store state.
	 */
	public function test_leaves_a_refund_alone_unless_platform_connected( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} elseif ( 'first_party' === $state ) {
			$this->set_platform_connected();
			$this->set_first_party_connected();
		}

		$this->assertSame( $this->args(), $this->sut->handle_ppcp_request_args( $this->args(), self::REFUND_URL ) );
	}

	/**
	 * @testdox Should pass a value that is not an array, or a URL that is not a string, through unchanged.
	 */
	public function test_passes_malformed_values_through(): void {
		$this->set_platform_connected();

		$this->assertSame( 'x', $this->sut->handle_ppcp_request_args( 'x', self::REFUND_URL ) );
		$this->assertSame( $this->args(), $this->sut->handle_ppcp_request_args( $this->args(), null ) );
	}

	/**
	 * @testdox Should sign the retry with a fresh platform token, not the cached one that failed.
	 */
	public function test_the_retry_gets_a_fresh_platform_token(): void {
		$this->set_platform_connected();
		foreach ( array( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', 'wc_paypal_wallet_rate_platform_bearer-circuit-state' ) as $name ) {
			$this->set_wallet_transient( $name, 'claimed' );
			delete_transient( $name );
		}
		$issued = 0;
		$this->stub_http(
			function ( $request, $url ) use ( &$issued ) {
				unset( $request );
				if ( false === strpos( (string) $url, 'v1/oauth2/token' ) ) {
					return new \WP_Error( 'unrouted', 'Unrouted' );
				}
				++$issued;
				return $this->http_response( 200, '{"access_token":"token-platform-' . $issued . '","expires_in":32400}' );
			}
		);
		$transport = new DirectPlatformTransport(
			array(
				'platform_client_id'         => 'platform-id',
				'platform_client_secret'     => 'platform-secret',
				'partner_merchant_id'        => 'PARTNER1',
				'merchant_app_client_id'     => 'merchant-app-id',
				'merchant_app_client_secret' => 'merchant-app-secret',
				'sandbox'                    => true,
			),
			new NullLogger(),
			new OrderAppContext(),
			new Options()
		);
		$sut       = $this->signer( $transport, new RecordingLogger() );
		$url       = 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1/refund';

		$first = $sut->handle_ppcp_request_args( $this->args(), $url );
		$retry = $sut->handle_ppcp_retry_request_args( $first, $url );

		$this->assertSame( 'Bearer token-platform-1', $first['headers']['Authorization'] );
		$this->assertSame( 'Bearer token-platform-2', $retry['headers']['Authorization'], 'The failed token is dropped' );
		$this->assertSame( AuthAssertion::header( 'platform-id', 'M2' )['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
	}

	/**
	 * @testdox Should sign the retry of a crypto order creation with a fresh platform token and the seller's assertion.
	 */
	public function test_the_retry_of_a_crypto_creation_gets_a_fresh_platform_token(): void {
		$this->set_platform_connected();
		foreach ( array( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', 'wc_paypal_wallet_rate_platform_bearer-circuit-state' ) as $name ) {
			$this->set_wallet_transient( $name, 'claimed' );
			delete_transient( $name );
		}
		$issued = 0;
		$this->stub_http(
			function ( $request, $url ) use ( &$issued ) {
				unset( $request );
				if ( false === strpos( (string) $url, 'v1/oauth2/token' ) ) {
					return new \WP_Error( 'unrouted', 'Unrouted' );
				}
				++$issued;
				return $this->http_response( 200, '{"access_token":"token-platform-' . $issued . '","expires_in":32400}' );
			}
		);
		$transport = new DirectPlatformTransport(
			array(
				'platform_client_id'         => 'platform-id',
				'platform_client_secret'     => 'platform-secret',
				'partner_merchant_id'        => 'PARTNER1',
				'merchant_app_client_id'     => 'merchant-app-id',
				'merchant_app_client_secret' => 'merchant-app-secret',
				'sandbox'                    => true,
			),
			new NullLogger(),
			new OrderAppContext(),
			new Options()
		);
		$sut       = $this->signer( $transport, new RecordingLogger() );
		$url       = 'https://api-m.sandbox.paypal.com/v2/checkout/orders';

		$first = $sut->handle_ppcp_request_args( $this->create_args(), $url );
		$retry = $sut->handle_ppcp_retry_request_args( $first, $url );

		$this->assertSame( 'Bearer token-platform-1', $first['headers']['Authorization'] );
		$this->assertSame( 'Bearer token-platform-2', $retry['headers']['Authorization'], 'The failed token is dropped' );
		$this->assertSame( AuthAssertion::header( 'platform-id', 'M2' )['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
	}

	/**
	 * Read arguments, as the wallet's order and capture reads build them: no method, the call's app bearer.
	 *
	 * @return array
	 */
	private function read_args(): array {
		return array(
			'headers' => array(
				'Authorization' => 'Bearer token-platform',
				'Content-Type'  => 'application/json',
			),
		);
	}

	/**
	 * Cache a platform app token, as PerAppBearer stores one it was issued.
	 *
	 * @param string $token The token value.
	 */
	private function cache_platform_token( string $token = 'token-platform' ): void {
		$this->set_wallet_transient( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', '{"access_token":"' . $token . '","expires_in":32400}' );
	}

	/**
	 * @testdox Should sign a platform app's read of an order or a capture of a platform-connected store: $url, entered: $entered.
	 * @testWith ["https://api.platform.fake.test/v2/checkout/orders/PP-1", true]
	 *           ["https://api.platform.fake.test/v2/checkout/orders/PP-1", false]
	 *           ["https://api.platform.fake.test/v2/checkout/orders/PP-1/", true]
	 *           ["https://api.platform.fake.test/v2/checkout/orders/PP-1?fields=payment_source", true]
	 *           ["https://api.platform.fake.test/v2/payments/captures/CAPTURE-1", true]
	 *           ["https://api.platform.fake.test/v2/payments/captures/CAPTURE-1", false]
	 *
	 * @param string $url     The request URL.
	 * @param bool   $entered Whether the order's platform pin is entered; when not, the transport's pick names the app.
	 */
	public function test_signs_a_platform_app_read( string $url, bool $entered ): void {
		$this->set_platform_connected();
		$this->cache_platform_token();
		if ( $entered ) {
			$this->context->enter( PlatformTransport::APP_PLATFORM );
		}

		$signed = $this->sut->handle_ppcp_request_args( $this->read_args(), $url );

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertSame( AuthAssertion::header( 'client-platform', 'M2' )['PayPal-Auth-Assertion'], $signed['headers']['PayPal-Auth-Assertion'] );
		$this->assertSame( 'application/json', $signed['headers']['Content-Type'], 'Other headers stay' );
	}

	/**
	 * @testdox Should leave a platform app's read alone when it carries another token, such as the manual connection's check of typed-in credentials.
	 */
	public function test_leaves_a_read_with_another_token_alone(): void {
		$this->set_platform_connected();
		$this->cache_platform_token();
		$args                             = $this->read_args();
		$args['headers']['Authorization'] = 'Bearer token-typed-in';

		$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, 'https://api.platform.fake.test/v2/checkout/orders/PP-1' ) );
		$this->assertSame( $args, $this->sut->handle_ppcp_retry_request_args( $args, 'https://api.platform.fake.test/v2/checkout/orders/PP-1' ) );
	}

	/**
	 * @testdox Should sign no read, and ask for no token, when no platform app token is cached.
	 */
	public function test_signs_no_read_without_a_cached_platform_token(): void {
		$this->set_platform_connected();
		$this->set_wallet_transient( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', 'claimed' );
		delete_transient( 'wc_paypal_wallet_bearer_platform_ppcp-bearer' );
		$transport = new FakePlatformTransport();
		$sut       = $this->signer( $transport, new RecordingLogger() );

		$this->assertSame( $this->read_args(), $sut->handle_ppcp_request_args( $this->read_args(), 'https://api.platform.fake.test/v2/checkout/orders/PP-1' ) );
		$this->assertSame( array(), $transport->calls_to( 'bearer' ), 'No token is asked for' );
		$this->assertSame( array(), $transport->calls_to( 'assertion_header' ) );
	}

	/**
	 * @testdox Should sign a platform app's read and its retry with the real transport: the cached token, then a fresh one, each with the assertion.
	 */
	public function test_a_read_and_its_retry_with_the_real_transport(): void {
		$this->set_platform_connected();
		foreach ( array( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', 'wc_paypal_wallet_rate_platform_bearer-circuit-state' ) as $name ) {
			$this->set_wallet_transient( $name, 'claimed' );
			delete_transient( $name );
		}
		$issued = 0;
		$this->stub_http(
			function ( $request, $url ) use ( &$issued ) {
				unset( $request );
				if ( false === strpos( (string) $url, 'v1/oauth2/token' ) ) {
					return new \WP_Error( 'unrouted', 'Unrouted' );
				}
				++$issued;
				return $this->http_response( 200, '{"access_token":"token-platform-' . $issued . '","expires_in":32400}' );
			}
		);
		$transport = new DirectPlatformTransport(
			array(
				'platform_client_id'         => 'platform-id',
				'platform_client_secret'     => 'platform-secret',
				'partner_merchant_id'        => 'PARTNER1',
				'merchant_app_client_id'     => 'merchant-app-id',
				'merchant_app_client_secret' => 'merchant-app-secret',
				'sandbox'                    => true,
			),
			new NullLogger(),
			new OrderAppContext(),
			new Options()
		);
		$sut       = $this->signer( $transport, new RecordingLogger() );
		$url       = 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PP-1';
		$args      = array( 'headers' => array( 'Authorization' => 'Bearer ' . $transport->bearer( PlatformTransport::APP_PLATFORM )->bearer()->token() ) );

		$first = $sut->handle_ppcp_request_args( $args, $url );
		$retry = $sut->handle_ppcp_retry_request_args( $first, $url );

		$this->assertSame( 'Bearer token-platform-1', $first['headers']['Authorization'] );
		$this->assertSame( AuthAssertion::header( 'platform-id', 'M2' )['PayPal-Auth-Assertion'], $first['headers']['PayPal-Auth-Assertion'] );
		$this->assertSame( 'Bearer token-platform-2', $retry['headers']['Authorization'], 'The failed token is dropped' );
		$this->assertSame( $first['headers']['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
	}

	/**
	 * @testdox Should leave a read of an order pinned to the merchant app on that app's own token: $url.
	 * @testWith ["https://api.merchant-app.fake.test/v2/checkout/orders/PP-1"]
	 *           ["https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1"]
	 *
	 * @param string $url The request URL.
	 */
	public function test_leaves_a_merchant_app_read_alone( string $url ): void {
		$this->set_platform_connected();
		$this->cache_platform_token();
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );
		$args                             = $this->read_args();
		$args['headers']['Authorization'] = 'Bearer token-merchant_app';

		$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, $url ) );
		$this->assertSame( $args, $this->sut->handle_ppcp_retry_request_args( $args, $url ) );
	}

	/**
	 * @testdox Should leave an order read alone unless the store is platform connected: $state.
	 * @testWith ["collecting"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The store state.
	 */
	public function test_leaves_a_read_alone_unless_platform_connected( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} elseif ( 'first_party' === $state ) {
			$this->set_platform_connected();
			$this->set_first_party_connected();
		}
		$this->context->enter( PlatformTransport::APP_PLATFORM );
		$this->cache_platform_token();
		$url = 'https://api.platform.fake.test/v2/checkout/orders/PP-1';

		$this->assertSame( $this->read_args(), $this->sut->handle_ppcp_request_args( $this->read_args(), $url ) );
		$this->assertSame( $this->read_args(), $this->sut->handle_ppcp_retry_request_args( $this->read_args(), $url ) );
	}

	/**
	 * @testdox Should leave every other read through the platform app alone: $description.
	 * @testWith ["a call under an order", "GET", "https://api.platform.fake.test/v2/checkout/orders/PP-1/something"]
	 *           ["the order list path", "GET", "https://api.platform.fake.test/v2/checkout/orders"]
	 *           ["a GET on the refund path", "GET", "https://api.platform.fake.test/v2/payments/captures/CAPTURE-1/refund"]
	 *           ["a refund read", "GET", "https://api.platform.fake.test/v2/payments/refunds/REFUND-1"]
	 *           ["an authorization read", "GET", "https://api.platform.fake.test/v2/payments/authorizations/AUTH-1"]
	 *           ["a path that only starts with the order path", "GET", "https://api.platform.fake.test/v2/checkout/orders-x/PP-1"]
	 *           ["an order read on another host", "GET", "https://api.example.com/v2/checkout/orders/PP-1"]
	 *           ["a PATCH of an order", "PATCH", "https://api.platform.fake.test/v2/checkout/orders/PP-1"]
	 *           ["a DELETE of an order", "DELETE", "https://api.platform.fake.test/v2/checkout/orders/PP-1"]
	 *
	 * @param string $description What the request is.
	 * @param string $method      The HTTP method.
	 * @param string $url         The request URL.
	 */
	public function test_leaves_other_platform_reads_alone( string $description, string $method, string $url ): void {
		unset( $description );
		$this->set_platform_connected();
		$this->cache_platform_token();
		$this->context->enter( PlatformTransport::APP_PLATFORM );
		$args           = $this->read_args();
		$args['method'] = $method;

		$this->assertSame( $args, $this->sut->handle_ppcp_request_args( $args, $url ) );
	}

	/**
	 * @testdox Should keep the assertion on the retry of a platform app's order read through the module's filters, with no order context entered.
	 */
	public function test_the_module_keeps_the_assertion_on_a_retried_read(): void {
		$this->set_platform_connected();
		$this->cache_platform_token();
		$transport = new FakePlatformTransport();
		$this->boot_container( array( new TransportBindingModule( $transport ) ) );
		$url = 'https://api.platform.fake.test/v2/checkout/orders/PP-1';

		$signed = apply_filters( 'ppcp_request_args', $this->read_args(), $url ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$retry  = apply_filters( 'ppcp_retry_request_args', $signed, $url ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertArrayHasKey( 'PayPal-Auth-Assertion', $signed['headers'] );
		$this->assertSame( 'Bearer token-platform', $retry['headers']['Authorization'] );
		$this->assertSame( $signed['headers']['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
	}

	/**
	 * A transport whose platform token cannot be issued.
	 *
	 * @return PlatformTransport
	 */
	private function transport_without_a_token(): PlatformTransport {
		$transport = $this->mock( PlatformTransport::class );
		$transport->shouldReceive( 'is_ready' )->andReturn( true );
		$transport->shouldReceive( 'host' )->andReturnUsing(
			static function ( string $app ): string {
				return 'https://api.' . str_replace( '_', '-', $app ) . '.fake.test';
			}
		);
		$transport->shouldReceive( 'assertion_header' )->andReturn( array( 'PayPal-Auth-Assertion' => 'eyJhbGciOiJub25lIn0.e30.' ) );
		$transport->shouldReceive( 'bearer' )->andThrow( new RuntimeException( 'offline' ) );

		return $transport;
	}

	/**
	 * @testdox Should leave the first request as it was, and log, when no platform token can be issued.
	 */
	public function test_a_failed_signature_leaves_the_request_alone(): void {
		$this->set_platform_connected();
		$logger = new RecordingLogger();
		$sut    = $this->signer( $this->transport_without_a_token(), $logger );

		$this->assertSame( $this->args(), $sut->handle_ppcp_request_args( $this->args(), self::REFUND_URL ) );
		$this->assertCount( 1, $logger->records );
		$this->assertSame( 'warning', $logger->records[0]['level'] );
	}

	/**
	 * @testdox Should drop the assertion from a retry it cannot re-sign, so the order app's bearer never goes out with the seller's assertion.
	 */
	public function test_a_failed_retry_signature_drops_the_assertion(): void {
		$this->set_platform_connected();
		$sut = $this->signer( $this->transport_without_a_token(), new RecordingLogger() );
		// The wallet's own retry filter re-signed with the order's app before this one runs.
		$args                                     = $this->args();
		$args['headers']['PayPal-Auth-Assertion'] = 'eyJhbGciOiJub25lIn0.e30.';

		$retry = $sut->handle_ppcp_retry_request_args( $args, self::REFUND_URL );

		$this->assertSame( 'Bearer token-merchant_app', $retry['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'PayPal-Auth-Assertion', $retry['headers'] );
	}

	/**
	 * @testdox Should drop the assertion from a platform app's read retry it cannot re-sign, so the bearer and the assertion never split.
	 */
	public function test_a_failed_read_retry_signature_drops_the_assertion(): void {
		$this->set_platform_connected();
		$this->cache_platform_token();
		$context = new OrderAppContext();
		$context->enter( PlatformTransport::APP_PLATFORM );
		$sut                                      = $this->signer( $this->transport_without_a_token(), new RecordingLogger(), $context );
		$args                                     = $this->read_args();
		$args['headers']['PayPal-Auth-Assertion'] = 'eyJhbGciOiJub25lIn0.e30.';

		$retry = $sut->handle_ppcp_retry_request_args( $args, 'https://api.platform.fake.test/v2/checkout/orders/PP-1' );

		$this->assertSame( 'Bearer token-platform', $retry['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'PayPal-Auth-Assertion', $retry['headers'] );
	}

	/**
	 * @testdox Should neither sign nor log anything while the transport is not configured.
	 */
	public function test_nothing_happens_without_a_ready_transport(): void {
		$this->set_platform_connected();
		$logger = new RecordingLogger();
		$sut    = $this->signer( new NotReadyTransport( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) ), $logger );

		$this->assertSame( $this->args(), $sut->handle_ppcp_request_args( $this->args(), 'https://api-m.paypal.com/v2/checkout/orders' ) );
		$this->assertSame( $this->args(), $sut->handle_ppcp_request_args( $this->args(), self::REFUND_URL ) );
		$this->assertSame( array(), $logger->records, 'No misleading refund warning' );
	}

	/**
	 * @testdox Should be registered by the collecting module on the request and retry filters while the platform serves the store.
	 */
	public function test_the_module_registers_the_signer(): void {
		$this->set_platform_connected();
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );

		$signed = apply_filters( 'ppcp_request_args', $this->args(), self::REFUND_URL ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$retry  = apply_filters( 'ppcp_retry_request_args', $this->args(), self::REFUND_URL ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertArrayHasKey( 'PayPal-Auth-Assertion', $signed['headers'] );
		$this->assertSame( 'Bearer token-platform', $retry['headers']['Authorization'], 'The retry stays on the platform app after the order-app retry filter' );
		$this->assertArrayHasKey( 'PayPal-Auth-Assertion', $retry['headers'] );
	}

	/**
	 * @testdox Should keep the assertion on the retry of a crypto order creation through the module's filters, whose order-app retry filter leaves it alone.
	 */
	public function test_the_module_keeps_the_assertion_on_a_retried_crypto_creation(): void {
		$this->set_platform_connected();
		$transport = new FakePlatformTransport();
		$this->boot_container( array( new TransportBindingModule( $transport ) ) );

		$signed = apply_filters( 'ppcp_request_args', $this->create_args(), self::CREATE_URL ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$retry  = apply_filters( 'ppcp_retry_request_args', $signed, self::CREATE_URL ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( 'Bearer token-platform', $signed['headers']['Authorization'] );
		$this->assertArrayHasKey( 'PayPal-Auth-Assertion', $signed['headers'] );
		$this->assertSame( 'Bearer token-platform', $retry['headers']['Authorization'] );
		$this->assertSame( $signed['headers']['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
		$this->assertSame( array( array( PlatformTransport::APP_PLATFORM ), array( PlatformTransport::APP_PLATFORM ) ), $transport->calls_to( 'assertion_header' ), 'Signed once for the request and once for the retry' );
	}
}
