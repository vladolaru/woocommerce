<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Seams;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Tests for the wallet seams the collecting state hooks into: the order and refund processors and the refund fees
 * updater enter the order's pinned app before their first PayPal call, and the PayPal meta write records the pin.
 *
 * The token endpoint answers each app by its basic credentials, so the Authorization of a call names the app that
 * signed it.
 *
 * @group paypal-wallet
 */
class OrderProcessorSeamTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * The dummy credentials.
	 */
	private const CREDENTIALS = array(
		'platform_client_id'         => 'platform-id',
		'platform_client_secret'     => 'platform-secret',
		'partner_merchant_id'        => 'PARTNER1',
		'merchant_app_client_id'     => 'merchant-app-id',
		'merchant_app_client_secret' => 'merchant-app-secret',
		'sandbox'                    => true,
	);

	/**
	 * The gateway list before the test.
	 *
	 * @var array
	 */
	private array $saved_gateways = array();

	/**
	 * PayPal order IDs the stub answers as approved, so the order processor patches and captures them.
	 *
	 * @var string[]
	 */
	private array $approved = array();

	/**
	 * The status details the stub's capture answers with: empty for a completed capture, else a pending one for the reason.
	 *
	 * @var string
	 */
	private string $pending_reason = '';

	/**
	 * Claim the per-app token transients and answer the token endpoint by app, every order GET with a completed order
	 * (or an approved one for the IDs in $approved), a patch with 204 and a capture with a completed capture.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->saved_gateways = WC()->payment_gateways()->payment_gateways;
		foreach ( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ) as $app ) {
			foreach ( array( 'wc_paypal_wallet_bearer_' . $app . '_ppcp-bearer', 'wc_paypal_wallet_rate_' . $app . '_bearer-circuit-state' ) as $name ) {
				$this->set_wallet_transient( $name, 'claimed' );
				delete_transient( $name );
			}
		}
		$this->stub_http(
			function ( $request, $url ) {
				if ( false !== strpos( $url, 'v1/oauth2/token' ) ) {
					$app = 'Basic ' . base64_encode( 'platform-id:platform-secret' ) === $request['headers']['Authorization'] ? 'platform' : 'merchant_app'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Recognizes the app by its basic credentials.
					return $this->http_response( 200, '{"access_token":"token-' . $app . '","expires_in":32400}' );
				}
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( 'PATCH' === $request['method'] ) {
					return $this->http_response( 204, '' );
				}
				if ( '/capture' === substr( $path, -8 ) ) {
					$capture_state = '' === $this->pending_reason ? '"status":"COMPLETED"' : '"status":"PENDING","status_details":{"reason":"' . $this->pending_reason . '"}';
					return $this->http_response( 201, '{"id":"' . basename( dirname( $path ) ) . '","status":"COMPLETED","intent":"CAPTURE","purchase_units":[{"reference_id":"default","amount":{"currency_code":"USD","value":"10.00"},"payments":{"captures":[{"id":"CAPTURE-1",' . $capture_state . ',"amount":{"currency_code":"USD","value":"10.00"},"final_capture":true,"seller_protection":{"status":"NOT_ELIGIBLE"}}]}}]}' );
				}
				$id     = basename( $path );
				$status = in_array( $id, $this->approved, true ) ? 'APPROVED' : 'COMPLETED';
				return $this->http_response( 200, '{"id":"' . $id . '","status":"' . $status . '","intent":"CAPTURE"}' );
			}
		);
	}

	/**
	 * Put the gateway list back. It is restored as it was, not rebuilt: a rebuild would run the booted wallet's own
	 * gateway filter, which the parent removes only afterwards, and keep its gateway for later tests.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->saved_gateways;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Set the store state.
	 *
	 * @param string $state `collecting` or `platform_connected`.
	 */
	private function set_state( string $state ): void {
		if ( ConnectionState::PLATFORM_CONNECTED === $state ) {
			$this->set_wallet_option(
				Options::PLATFORM,
				array(
					'merchant_id' => 'M2',
					'tracking_id' => 'abc',
					'payee_email' => 'connected@example.com',
					'environment' => 'sandbox',
				)
			);
			return;
		}
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
	}

	/**
	 * Boot the wallet with the direct transport over the dummy credentials.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		$transport = new DirectPlatformTransport( self::CREDENTIALS, new NullLogger(), new OrderAppContext(), new Options() );

		return $this->boot_container( array( new TransportBindingModule( $transport ) ) );
	}

	/**
	 * A wallet order with a PayPal order ID, pinned to an app or not.
	 *
	 * @param string      $paypal_id The PayPal order ID.
	 * @param string|null $pin       The pinned app, or null for none.
	 * @return WC_Order
	 */
	private function wallet_order( string $paypal_id, ?string $pin ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $paypal_id );
		$order->save();
		if ( null !== $pin ) {
			OrderPin::record( $order, $pin );
			$order->save();
		}

		return $order;
	}

	/**
	 * The Authorization headers of the requests on one PayPal order, in order: its GETs, or every call on it.
	 *
	 * @param string $paypal_id The PayPal order ID.
	 * @param bool   $all       Whether to include the patches and the capture, not only the GETs.
	 * @return string[]
	 */
	private function get_authorizations( string $paypal_id, bool $all = false ): array {
		$authorizations = array();
		$order_path     = '/v2/checkout/orders/' . $paypal_id;
		foreach ( $this->http_requests as $request ) {
			$path = (string) wp_parse_url( $request['url'], PHP_URL_PATH );
			if ( $all ? 0 === strpos( $path, $order_path ) : ( 'GET' === ( $request['request']['method'] ?? 'GET' ) && $path === $order_path ) ) {
				$authorizations[] = $request['request']['headers']['Authorization'];
			}
		}

		return $authorizations;
	}

	/**
	 * Assert every GET of a PayPal order (or every call on it) was signed by one app, and that there was at least one.
	 *
	 * @param string $app       The app.
	 * @param string $paypal_id The PayPal order ID.
	 * @param bool   $all       Whether to include the patches and the capture, not only the GETs.
	 */
	private function assert_gets_signed_by( string $app, string $paypal_id, bool $all = false ): void {
		$authorizations = $this->get_authorizations( $paypal_id, $all );

		$this->assertNotEmpty( $authorizations, "$paypal_id was fetched" );
		$this->assertSame( array_fill( 0, count( $authorizations ), 'Bearer token-' . $app ), $authorizations, "Every GET of $paypal_id is signed by $app" );
	}

	/**
	 * Add a gateway double with the wallet's ID that supports refunds, as the refund processor checks for one.
	 */
	private function register_refund_gateway(): void {
		WC()->payment_gateways()->payment_gateways = array(
			new class() extends WC_Payment_Gateway {
				/**
				 * A PayPal gateway that supports refunds.
				 */
				public function __construct() {
					$this->id       = PayPalGateway::ID;
					$this->supports = array( 'products', 'refunds' );
				}
			},
		);
	}

	/**
	 * @testdox Should fetch the PayPal order in OrderProcessor::process() through the app the order is pinned to, not the one the transport picks.
	 * @testWith ["collecting", "platform"]
	 *           ["platform_connected", "merchant_app"]
	 *
	 * @param string $state The store state.
	 * @param string $pin   The pinned app, the one the transport would not pick.
	 */
	public function test_order_processor_enters_the_pinned_app( string $state, string $pin ): void {
		$this->set_state( $state );
		$container = $this->boot();

		$container->get( 'wcgateway.order-processor' )->process( $this->wallet_order( 'PP-SEAM-1', $pin ) );

		$this->assert_gets_signed_by( $pin, 'PP-SEAM-1' );
	}

	/**
	 * @testdox Should fetch an order that is not pinned yet through the app the transport picks, the one that created it.
	 * @testWith ["collecting", "merchant_app"]
	 *           ["platform_connected", "platform"]
	 *
	 * @param string $state The store state.
	 * @param string $pick  The app the transport picks.
	 */
	public function test_order_processor_lets_an_unpinned_order_go_through_the_pick( string $state, string $pick ): void {
		$this->set_state( $state );
		$container = $this->boot();
		$container->get( 'collecting.order-app-context' )->enter( PlatformTransport::APP_MERCHANT_APP === $pick ? PlatformTransport::APP_PLATFORM : PlatformTransport::APP_MERCHANT_APP );

		$container->get( 'wcgateway.order-processor' )->process( $this->wallet_order( 'PP-SEAM-2', null ) );

		$this->assert_gets_signed_by( $pick, 'PP-SEAM-2' );
	}

	/**
	 * @testdox Should sign each order's calls with its own app when two orders pinned to different apps are processed in one request.
	 */
	public function test_two_orders_in_one_request_each_use_their_own_app(): void {
		$this->set_state( ConnectionState::COLLECTING );
		$container = $this->boot();
		$processor = $container->get( 'wcgateway.order-processor' );
		$first     = $this->wallet_order( 'PP-FIRST', PlatformTransport::APP_MERCHANT_APP );
		$second    = $this->wallet_order( 'PP-SECOND', PlatformTransport::APP_PLATFORM );

		$processor->process( $first );
		$processor->process( $second );
		$container->get( 'wcgateway.helper.refund-fees-updater' )->update( $first );

		$this->assert_gets_signed_by( PlatformTransport::APP_MERCHANT_APP, 'PP-FIRST' );
		$this->assert_gets_signed_by( PlatformTransport::APP_PLATFORM, 'PP-SECOND' );
	}

	/**
	 * @testdox Should sign a pinned, an unpinned and a pinned order in one request each with its own app, inheriting nothing.
	 * @testWith ["collecting", "platform", "merchant_app"]
	 *           ["platform_connected", "merchant_app", "platform"]
	 *
	 * @param string $state The store state.
	 * @param string $pin   The app the pinned orders are pinned to, the one the transport would not pick.
	 * @param string $pick  The app the transport picks.
	 */
	public function test_pinned_unpinned_pinned_orders_inherit_nothing( string $state, string $pin, string $pick ): void {
		$this->set_state( $state );
		$processor = $this->boot()->get( 'wcgateway.order-processor' );

		$processor->process( $this->wallet_order( 'PP-A', $pin ) );
		$processor->process( $this->wallet_order( 'PP-B', null ) );
		$processor->process( $this->wallet_order( 'PP-C', $pin ) );

		$this->assert_gets_signed_by( $pin, 'PP-A' );
		$this->assert_gets_signed_by( $pick, 'PP-B' );
		$this->assert_gets_signed_by( $pin, 'PP-C' );
	}

	/**
	 * @testdox Should pin an approved order through process() itself, sign its fetch, patch and capture with the pick, and leave the context afterwards.
	 * @testWith ["collecting", "merchant_app"]
	 *           ["platform_connected", "platform"]
	 *
	 * @param string $state The store state.
	 * @param string $pick  The app the transport picks.
	 */
	public function test_process_of_an_approved_order_pins_it_and_leaves_the_context( string $state, string $pick ): void {
		$this->set_state( $state );
		$this->approved = array( 'PP-APPROVED' );
		$container      = $this->boot();
		$wc_order       = $this->wallet_order( 'PP-APPROVED', null );
		$wc_order->set_total( '10.00' );
		$wc_order->save();

		$container->get( 'wcgateway.order-processor' )->process( $wc_order );

		$methods = array_map(
			static function ( array $request ): string {
				return $request['request']['method'] ?? 'GET';
			},
			$this->http_requests
		);
		$this->assertContains( 'PATCH', $methods, 'The approved order is patched' );
		$this->assertStringEndsWith( '/v2/checkout/orders/PP-APPROVED/capture', end( $this->http_requests )['url'], 'The approved order is captured last' );
		$this->assert_gets_signed_by( $pick, 'PP-APPROVED', true );
		$this->assertSame( $pick, OrderPin::app( wc_get_order( $wc_order->get_id() ) ) );
		$this->assertTrue( OrderPin::is_pinned( wc_get_order( $wc_order->get_id() ) ) );
		$this->assertFalse( $container->get( 'collecting.order-app-context' )->is_entered(), 'The after-hook leaves the order context' );
	}

	/**
	 * @testdox Should record a capture PayPal answers pending for an unclaimed payee as held, put the order on hold and claim the first order.
	 */
	public function test_process_of_a_held_capture_records_it_and_claims_the_first_order(): void {
		$this->set_state( ConnectionState::COLLECTING );
		$this->approved       = array( 'PP-HELD' );
		$this->pending_reason = 'UNILATERAL';
		$first_orders         = 0;
		add_action(
			'woocommerce_paypal_wallet_first_order',
			static function () use ( &$first_orders ) {
				++$first_orders;
			}
		);
		$container = $this->boot();
		$wc_order  = $this->wallet_order( 'PP-HELD', null );
		$wc_order->set_total( '10.00' );
		$wc_order->save();

		$container->get( 'wcgateway.order-processor' )->process( $wc_order );

		$saved = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'on-hold', $saved->get_status(), 'The wallet still puts the order on hold' );
		$this->assertSame( 'UNILATERAL', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( 'CAPTURE-1', $saved->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) );
		$this->assertSame( 1, $first_orders );
		$this->assertTrue( (bool) get_option( Options::COLLECTING )['payee_bound'] );
		$this->assertSame( 1, ( new HeldOrders() )->count() );
	}

	/**
	 * @testdox Should fetch the PayPal order in RefundProcessor::process() through the app the order is pinned to.
	 */
	public function test_refund_processor_enters_the_pinned_app(): void {
		$this->set_state( ConnectionState::PLATFORM_CONNECTED );
		$this->register_refund_gateway();
		$container = $this->boot();

		$result = $container->get( 'wcgateway.processor.refunds' )->process( $this->wallet_order( 'PP-SEAM-3', PlatformTransport::APP_MERCHANT_APP ), 5.0, 'reason' );

		$this->assertFalse( $result, 'The completed order has no capture to refund' );
		$this->assert_gets_signed_by( PlatformTransport::APP_MERCHANT_APP, 'PP-SEAM-3' );
	}

	/**
	 * @testdox Should fetch the PayPal order in RefundFeesUpdater::update() through the app the order is pinned to.
	 * @testWith ["collecting", "platform"]
	 *           ["platform_connected", "merchant_app"]
	 *
	 * @param string $state The store state.
	 * @param string $pin   The pinned app, the one the transport would not pick.
	 */
	public function test_refund_fees_updater_enters_the_pinned_app( string $state, string $pin ): void {
		$this->set_state( $state );
		$container = $this->boot();

		$container->get( 'wcgateway.helper.refund-fees-updater' )->update( $this->wallet_order( 'PP-SEAM-4', $pin ) );

		$this->assert_gets_signed_by( $pin, 'PP-SEAM-4' );
	}

	/**
	 * @testdox Should pin the order to the app that signs its calls when the PayPal meta is written: the entered one, or else the pick.
	 * @testWith ["collecting", null, "merchant_app"]
	 *           ["collecting", "platform", "platform"]
	 *           ["platform_connected", null, "platform"]
	 *           ["platform_connected", "merchant_app", "merchant_app"]
	 *
	 * @param string      $state    The store state.
	 * @param string|null $entered  The app entered before the write, or null for none.
	 * @param string      $expected The pinned app.
	 */
	public function test_paypal_meta_write_records_the_pin( string $state, ?string $entered, string $expected ): void {
		$this->set_state( $state );
		$container = $this->boot();
		if ( null !== $entered ) {
			$container->get( 'collecting.order-app-context' )->enter( $entered );
		}
		$wc_order = $this->wallet_order( 'PP-OLD', null );

		$container->get( 'wcgateway.order-processor' )->add_paypal_meta( $wc_order, new Order( 'PP-NEW', array(), new OrderStatus( OrderStatus::CREATED ) ), $container->get( 'settings.environment' ) );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'PP-NEW', $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) );
		$this->assertSame( $expected, $reloaded->get_meta( OrderAppContext::ORDER_APP_META_KEY, true ) );
	}
}
