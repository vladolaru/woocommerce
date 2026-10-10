<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Rest;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Rest\CollectingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\ProviderRow;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\DetachesShellCallbacks;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the REST routes of the PayPal Wallet collecting panel, under `wc/v3/paypal-wallet`.
 *
 * @group paypal-wallet
 */
class CollectingRestEndpointTest extends WalletTestCase {
	use BootsCollectingContainer;
	use DetachesShellCallbacks;
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var CollectingRestEndpoint
	 */
	private $sut;

	/**
	 * The transport the routes reach: a fake, or a mock for a failure.
	 *
	 * @var FakePlatformTransport|PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The reconciler check-status runs, or null for none.
	 *
	 * @var Reconciler|null
	 */
	private ?Reconciler $reconciler = null;

	/**
	 * The owner the arbiter reports.
	 *
	 * @var string
	 */
	private string $owner = PayPalWalletRuntimeArbiter::OWNER_NATIVE;

	/**
	 * The REST server before the test replaced it.
	 *
	 * @var mixed
	 */
	private $previous_server;

	/**
	 * Build the endpoint over the stored options and a ready fake transport, and register its routes on a fresh server.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->transport = new FakePlatformTransport();
		$this->build_sut();

		$this->previous_server     = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server fires rest_api_init, which registers the routes below.
		// The shell registers the same routes over the store's real transport, and the first registration answers.
		$this->detach_shell_callbacks( 'rest_api_init', OwnerIndependent::class );
		add_action(
			'rest_api_init',
			function (): void {
				$this->sut->register_routes();
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Put the REST server back.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wp_rest_server'] = $this->previous_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the endpoint over the current transport and reconciler.
	 */
	private function build_sut(): void {
		$options = new Options();
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )->onlyMethods( array( 'get_runtime_owner' ) )->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturnCallback(
			function (): string {
				return $this->owner;
			}
		);
		$this->sut = new CollectingRestEndpoint(
			new CollectingState( $options, new HeldOrders() ),
			new ConnectionState( $options ),
			new HeldOrders(),
			$options,
			new Dismissals(),
			function () {
				return $this->transport;
			},
			function () {
				return $this->reconciler;
			},
			$arbiter
		);
	}

	/**
	 * Put the store in the collecting state with a payee that can still change.
	 */
	private function set_collecting_unbound(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'TRACK-1',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
	}

	/**
	 * Dispatch a request to a route of the endpoint.
	 *
	 * @param string $method The HTTP method.
	 * @param string $route  The route after the namespace, such as `/collecting/payee`.
	 * @param array  $params The body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . CollectingRestEndpoint::NAMESPACE . $route );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * @testdox Should refuse every route to a user who cannot manage WooCommerce: $method $route.
	 * @testWith ["GET", "/collecting"]
	 *           ["POST", "/collecting/payee"]
	 *           ["POST", "/collecting/check-status"]
	 *           ["POST", "/collecting/referral"]
	 *           ["POST", "/collecting/dismiss"]
	 *
	 * @param string $method The HTTP method.
	 * @param string $route  The route.
	 */
	public function test_refuses_a_user_without_manage_woocommerce( string $method, string $route ): void {
		$this->set_collecting_unbound();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch(
			$method,
			$route,
			array(
				'email'   => 'new@example.com',
				'surface' => ProviderRow::SURFACE,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->transport->calls_to( 'referral_link' ), 'Nothing reached the transport' );
		$this->assertSame( 'payee@example.com', get_option( Options::COLLECTING )['payee_email'], 'Nothing was written' );
	}

	/**
	 * @testdox Should answer the collecting state, the payee, the held orders with their deadlines and the transport status.
	 */
	public function test_get_answers_the_collecting_panel_data(): void {
		$this->set_collecting_unbound();
		$older = $this->held_order( time() - 5 * DAY_IN_SECONDS );
		$newer = $this->held_order( time() - DAY_IN_SECONDS );

		$response = $this->dispatch( 'GET', '/collecting' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( ConnectionState::COLLECTING, $data['state'] );
		$this->assertSame( 'payee@example.com', $data['payee_email'] );
		$this->assertTrue( $data['can_change_payee_email'] );
		$this->assertSame( 'no_account', $data['merchant_state'], 'No seller status was read yet' );
		$this->assertTrue( $data['transport_ready'] );
		$this->assertSame( 2, $data['held_orders_count'] );
		$this->assertSame( array( $older->get_id(), $newer->get_id() ), array_column( $data['held_orders'], 'id' ), 'Oldest first: the earliest deadline leads' );
		$this->assertSame( $older->get_order_number(), $data['held_orders'][0]['number'] );
		$this->assertSame( ( new HeldOrders() )->deadline_for( $older ), $data['held_orders'][0]['deadline'] );
		$this->assertSame( ( new HeldOrders() )->deadline_for( $newer ), $data['held_orders'][1]['deadline'] );
		$this->assertSame( $data['held_orders'][0]['deadline'], $data['earliest_deadline'] );
	}

	/**
	 * @testdox Should read the merchant state from the last seller status: receivable $receivable, email confirmed $confirmed.
	 * @testWith [false, false, "no_account"]
	 *           [true, false, "email_unconfirmed"]
	 *           [true, true, "confirmed_not_connected"]
	 *
	 * @param bool   $receivable Whether payments are receivable.
	 * @param bool   $confirmed  Whether the primary email is confirmed.
	 * @param string $expected   The merchant state.
	 */
	public function test_get_reads_the_merchant_state_from_the_cached_seller_status( bool $receivable, bool $confirmed, string $expected ): void {
		$this->set_collecting_unbound();
		$this->set_wallet_option(
			Options::SELLER_STATUS,
			array(
				'payments_receivable'     => $receivable,
				'primary_email_confirmed' => $confirmed,
				'checked_at'              => time(),
			)
		);

		$data = $this->dispatch( 'GET', '/collecting' )->get_data();

		$this->assertSame( $expected, $data['merchant_state'] );
		$this->assertSame( array(), $this->transport->calls_to( 'seller_status' ), 'GET reads no seller status from PayPal' );
	}

	/**
	 * @testdox Should report a platform-connected store as connected, with no payee change allowed and no held order.
	 */
	public function test_get_reports_a_platform_connected_store(): void {
		$this->set_platform_connected();

		$data = $this->dispatch( 'GET', '/collecting' )->get_data();

		$this->assertSame( ConnectionState::PLATFORM_CONNECTED, $data['state'] );
		$this->assertSame( 'connected', $data['merchant_state'] );
		$this->assertFalse( $data['can_change_payee_email'] );
		$this->assertSame( array(), $data['held_orders'] );
		$this->assertNull( $data['earliest_deadline'] );
	}

	/**
	 * @testdox Should change the payee while it is not bound, and answer the new panel data.
	 */
	public function test_payee_changes_an_unbound_payee(): void {
		$this->set_collecting_unbound();

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'new@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'new@example.com', $response->get_data()['payee_email'] );
		$this->assertSame( 'new@example.com', get_option( Options::COLLECTING )['payee_email'] );
		$this->assertSame( 'TRACK-1', get_option( Options::COLLECTING )['tracking_id'], 'The tracking ID stays' );
	}

	/**
	 * @testdox Should refuse to change a bound payee with a 409 and the state's message, and write nothing.
	 */
	public function test_payee_refuses_a_bound_payee(): void {
		$this->set_collecting();

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'new@example.com' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_payee_bound', $response->get_data()['code'] );
		$this->assertSame( 'The payee email cannot change: the store is not collecting or the payee is bound.', $response->get_data()['message'] );
		$this->assertSame( 'payee@example.com', get_option( Options::COLLECTING )['payee_email'] );
	}

	/**
	 * @testdox Should refuse a payee that is not an email address with a 400.
	 */
	public function test_payee_refuses_an_invalid_email(): void {
		$this->set_collecting_unbound();

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'not-an-email' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'payee@example.com', get_option( Options::COLLECTING )['payee_email'] );
	}

	/**
	 * @testdox Should enter the collecting state from a dormant store, in the transport's environment, with the gateway turned on.
	 */
	public function test_payee_enters_collecting_from_a_dormant_store(): void {
		$this->set_wallet_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );
		$this->set_wallet_option( Options::COLLECTING, array() );

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'first@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$collecting = get_option( Options::COLLECTING );
		$this->assertSame( 'first@example.com', $collecting['payee_email'] );
		$this->assertSame( 'sandbox', $collecting['environment'], 'The transport reports the sandbox' );
		$this->assertSame( 'yes', get_option( 'woocommerce_ppcp-gateway_settings' )['enabled'], 'Entering turns the gateway on' );
		$this->assertSame( ConnectionState::COLLECTING, $response->get_data()['state'] );
	}

	/**
	 * @testdox Should refuse to enter the collecting state with a 503 when the transport is not configured.
	 */
	public function test_payee_refuses_to_enter_without_a_ready_transport(): void {
		$this->transport = new FakePlatformTransport( array( 'ready' => false ) );
		$this->set_wallet_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'first@example.com' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_transport_not_configured', $response->get_data()['code'] );
		$this->assertStringContainsString( 'transport is not configured', $response->get_data()['message'] );
		$this->assertFalse( get_option( Options::COLLECTING ), 'The store does not start collecting' );
		$this->assertSame( array( 'enabled' => 'no' ), get_option( 'woocommerce_ppcp-gateway_settings' ), 'The gateway is not turned on' );
	}

	/**
	 * @testdox Should refuse to enter the collecting state with a 409 from a dormant store the PayPal Payments extension owns, and write nothing.
	 */
	public function test_payee_refuses_to_enter_when_core_does_not_own_the_wallet(): void {
		$this->owner = PayPalWalletRuntimeArbiter::OWNER_EXTENSION;
		$this->set_wallet_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'first@example.com' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_not_owned', $response->get_data()['code'] );
		$this->assertFalse( get_option( Options::COLLECTING ), 'The store does not start collecting' );
		$this->assertSame( array( 'enabled' => 'no' ), get_option( 'woocommerce_ppcp-gateway_settings' ), 'The gateway is not turned on' );
	}

	/**
	 * @testdox Should refuse a payee change with a 409 on a platform-connected store.
	 */
	public function test_payee_refuses_a_connected_store(): void {
		$this->set_platform_connected();

		$response = $this->dispatch( 'POST', '/collecting/payee', array( 'email' => 'new@example.com' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_already_connected', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should ask the transport for the THIRD_PARTY referral of the store's tracking ID and payee email, returning to the wallet settings.
	 */
	public function test_referral_answers_the_transport_link(): void {
		$this->set_collecting_unbound();

		$response = $this->dispatch( 'POST', '/collecting/referral' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( array( 'TRACK-1', PayPalWalletBootstrap::get_settings_url(), 'payee@example.com' ) ), $this->transport->calls_to( 'referral_link' ) );
		$this->assertSame( array( 'url' => 'https://www.sandbox.paypal.com/fake-referral?tracking_id=TRACK-1' ), $response->get_data() );
	}

	/**
	 * @testdox Should refuse the referral with a 409 when the store is not collecting.
	 */
	public function test_referral_refuses_a_store_that_is_not_collecting(): void {
		$response = $this->dispatch( 'POST', '/collecting/referral' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array(), $this->transport->calls_to( 'referral_link' ) );
	}

	/**
	 * @testdox Should answer 503 "transport not configured" from the transport-bound route $route when the transport is not ready.
	 * @testWith ["/collecting/referral"]
	 *           ["/collecting/check-status"]
	 *
	 * @param string $route The route.
	 */
	public function test_transport_bound_routes_need_a_ready_transport( string $route ): void {
		$this->set_collecting_unbound();
		$this->transport = new FakePlatformTransport( array( 'ready' => false ) );

		$response = $this->dispatch( 'POST', $route );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_transport_not_configured', $response->get_data()['code'] );
		$this->assertStringContainsString( 'transport is not configured', $response->get_data()['message'] );
		$this->assertSame( array(), $this->transport->calls_to( 'referral_link' ) );
		$this->assertSame( array(), $this->transport->calls_to( 'seller_status' ) );
	}

	/**
	 * @testdox Should answer 502 without the transport's message when PayPal refuses the referral.
	 */
	public function test_referral_reports_a_transport_failure(): void {
		$this->set_collecting_unbound();
		$failing = $this->createMock( PlatformTransport::class );
		$failing->method( 'is_ready' )->willReturn( true );
		$failing->method( 'referral_link' )->willThrowException( new RuntimeException( 'secret detail' ) );
		$this->transport = $failing;

		$response = $this->dispatch( 'POST', '/collecting/referral' );

		$this->assertSame( 502, $response->get_status() );
		$this->assertStringNotContainsString( 'secret detail', $response->get_data()['message'] );
	}

	/**
	 * @testdox Should run the reconcile and answer the merchant state the seller status gives: receivable, email not confirmed.
	 */
	public function test_check_status_answers_the_merchant_state_from_the_seller_status(): void {
		$this->set_collecting_unbound();
		$this->transport  = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( '', true, false, true ) ) );
		$this->reconciler = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) )->get( 'collecting.reconciler' );

		$response = $this->dispatch( 'POST', '/collecting/check-status' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( array( 'TRACK-1' ) ), $this->transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'email_unconfirmed', $response->get_data()['merchant_state'] );
		$this->assertSame( 'incomplete', $response->get_data()['check'] );
		$this->assertSame( ConnectionState::COLLECTING, $response->get_data()['state'] );
	}

	/**
	 * @testdox Should complete the setup when the seller status is complete, and answer the store as connected.
	 */
	public function test_check_status_completes_the_setup(): void {
		$this->set_collecting_unbound();
		$this->transport  = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'MERCHANT-9', true, true, true ) ) );
		$this->reconciler = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) )->get( 'collecting.reconciler' );

		$data = $this->dispatch( 'POST', '/collecting/check-status' )->get_data();

		$this->assertSame( 'connected', $data['merchant_state'] );
		$this->assertSame( ConnectionState::PLATFORM_CONNECTED, $data['state'] );
		$this->assertSame( 'MERCHANT-9', get_option( Options::PLATFORM )['merchant_id'] );
	}

	/**
	 * @testdox Should check onboarding before reading any held capture, and read at most one batch of captures.
	 */
	public function test_check_status_checks_onboarding_first_and_reads_one_batch(): void {
		$this->set_collecting_unbound();
		$this->transport  = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'MERCHANT-9', true, true, true ) ) );
		$this->reconciler = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) )->get( 'collecting.reconciler' );
		for ( $i = 1; $i <= Reconciler::BATCH_SIZE + 1; $i++ ) {
			$order = $this->held_order( 1000 + $i );
			$order->set_transaction_id( 'CAPTURE-' . $i );
			$order->save();
		}
		$reads            = 0;
		$onboarding_first = true;
		$this->stub_http(
			function ( $request, $url ) use ( &$reads, &$onboarding_first ) {
				unset( $request );
				if ( false !== strpos( (string) $url, '/v2/payments/captures/' ) ) {
					++$reads;
					$onboarding_first = $onboarding_first && 1 === count( $this->transport->calls_to( 'seller_status' ) );
				}
				return new \WP_Error( 'offline', 'Offline' );
			}
		);

		$data = $this->dispatch( 'POST', '/collecting/check-status' )->get_data();

		$this->assertSame( 'completed', $data['check'] );
		$this->assertTrue( $onboarding_first, 'The seller status was read before any capture' );
		$this->assertSame( Reconciler::BATCH_SIZE, $reads );
		$this->assertNotFalse( as_next_scheduled_action( Reconciler::HOOK, array( Reconciler::BATCH_SIZE, true ), Reconciler::GROUP ), 'The rest is queued' );
		as_unschedule_all_actions( Reconciler::HOOK, null, Reconciler::GROUP );
	}

	/**
	 * @testdox Should answer 503 from check-status when the wallet is not running, so no reconciler exists.
	 */
	public function test_check_status_needs_a_running_wallet(): void {
		$this->set_collecting_unbound();

		$response = $this->dispatch( 'POST', '/collecting/check-status' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wc_paypal_wallet_not_running', $response->get_data()['code'] );
	}

	/**
	 * @testdox Should store the dismissal of an allowed surface for the current user.
	 */
	public function test_dismiss_stores_the_dismissal(): void {
		$this->set_first_order( 42 );

		$response = $this->dispatch( 'POST', '/collecting/dismiss', array( 'surface' => ProviderRow::SURFACE ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'dismissed' => true ), $response->get_data() );
		$this->assertTrue( ( new Dismissals() )->is_dismissed( ProviderRow::SURFACE, get_current_user_id(), 42 ) );
	}

	/**
	 * @testdox Should refuse a surface outside the allow-list with a 400 and store nothing.
	 */
	public function test_dismiss_refuses_an_unknown_surface(): void {
		$response = $this->dispatch( 'POST', '/collecting/dismiss', array( 'surface' => 'anything-else' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_user_meta( get_current_user_id(), Dismissals::META_PREFIX . 'anything-else', true ) );
	}
}
