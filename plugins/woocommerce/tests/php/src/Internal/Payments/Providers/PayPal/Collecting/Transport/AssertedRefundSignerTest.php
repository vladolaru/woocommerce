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
 * Tests for the signer that sends a capture refund through the platform app with the seller's assertion.
 *
 * @group paypal-wallet
 */
class AssertedRefundSignerTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	private const REFUND_URL = 'https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1/refund';

	/**
	 * The System Under Test.
	 *
	 * @var AssertedRefundSigner
	 */
	private AssertedRefundSigner $sut;

	/**
	 * Build the signer over a fake transport.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new AssertedRefundSigner( new ConnectionState(), new FakePlatformTransport(), new RecordingLogger() );
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
	 * @testdox Should leave every other request of a platform-connected store on its own app: $description.
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
		$sut       = new AssertedRefundSigner( new ConnectionState(), $transport, new RecordingLogger() );
		$url       = 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1/refund';

		$first = $sut->handle_ppcp_request_args( $this->args(), $url );
		$retry = $sut->handle_ppcp_retry_request_args( $first, $url );

		$this->assertSame( 'Bearer token-platform-1', $first['headers']['Authorization'] );
		$this->assertSame( 'Bearer token-platform-2', $retry['headers']['Authorization'], 'The failed token is dropped' );
		$this->assertSame( AuthAssertion::header( 'platform-id', 'M2' )['PayPal-Auth-Assertion'], $retry['headers']['PayPal-Auth-Assertion'] );
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
		$sut    = new AssertedRefundSigner( new ConnectionState(), $this->transport_without_a_token(), $logger );

		$this->assertSame( $this->args(), $sut->handle_ppcp_request_args( $this->args(), self::REFUND_URL ) );
		$this->assertCount( 1, $logger->records );
		$this->assertSame( 'warning', $logger->records[0]['level'] );
	}

	/**
	 * @testdox Should drop the assertion from a retry it cannot re-sign, so the order app's bearer never goes out with the seller's assertion.
	 */
	public function test_a_failed_retry_signature_drops_the_assertion(): void {
		$this->set_platform_connected();
		$sut = new AssertedRefundSigner( new ConnectionState(), $this->transport_without_a_token(), new RecordingLogger() );
		// The wallet's own retry filter re-signed with the order's app before this one runs.
		$args                                     = $this->args();
		$args['headers']['PayPal-Auth-Assertion'] = 'eyJhbGciOiJub25lIn0.e30.';

		$retry = $sut->handle_ppcp_retry_request_args( $args, self::REFUND_URL );

		$this->assertSame( 'Bearer token-merchant_app', $retry['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'PayPal-Auth-Assertion', $retry['headers'] );
	}

	/**
	 * @testdox Should neither sign nor log anything while the transport is not configured.
	 */
	public function test_nothing_happens_without_a_ready_transport(): void {
		$this->set_platform_connected();
		$logger = new RecordingLogger();
		$sut    = new AssertedRefundSigner( new ConnectionState(), new NotReadyTransport( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) ), $logger );

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
}
