<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ConnectBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnerReferrals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests for the collecting module's extensions that route the wallet's API calls through the platform transport.
 *
 * @group paypal-wallet
 */
class TransportExtensionsTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * The services the transport extensions replace.
	 */
	private const EXTENDED_SERVICES = array(
		'api.bearer',
		'api.host-resolver',
		'api.host',
		'api.merchant_email',
		'api.merchant_id',
		'api.partner_merchant_id',
		'button.client_id',
		'api.endpoint.partner-referrals',
		'api.endpoint.partner-referrals-sandbox',
		'api.endpoint.partner-referrals-production',
	);

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
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
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'tracking_id' => 'abc',
				'payee_email' => 'connected@example.com',
				'environment' => 'sandbox',
			)
		);
	}

	/**
	 * Boot the container with a transport bound as `collecting.transport`.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return ContainerInterface
	 */
	private function boot_with( FakePlatformTransport $transport ): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( $transport ) ) );
	}

	/**
	 * @testdox Should sign the wallet's calls with the context bearer and resolve hosts through the context while collecting.
	 */
	public function test_collecting_store_uses_the_context_bearer_and_host_resolver(): void {
		$this->set_collecting();

		$container = $this->boot_with( new FakePlatformTransport() );

		$this->assertInstanceOf( ContextBearer::class, $container->get( 'api.bearer' ) );
		$this->assertSame( 'token-merchant_app', $container->get( 'api.bearer' )->bearer()->token(), 'Without an order context the transport picks the app' );
		$this->assertInstanceOf( ContextHostResolver::class, $container->get( 'api.host-resolver' ) );
		$this->assertSame( 'https://api.merchant-app.fake.test', $container->get( 'api.host-resolver' )->host() );
	}

	/**
	 * @testdox Should share one order app context between the context bearer and the context host resolver.
	 */
	public function test_bearer_and_host_resolver_share_the_order_app_context(): void {
		$this->set_collecting();
		$container = $this->boot_with( new FakePlatformTransport() );

		$container->get( 'collecting.order-app-context' )->enter( PlatformTransport::APP_PLATFORM );

		$this->assertSame( 'token-platform', $container->get( 'api.bearer' )->bearer()->token() );
		$this->assertSame( 'https://api.platform.fake.test', $container->get( 'api.host-resolver' )->host() );
	}

	/**
	 * @testdox Should use the transport's host for the app of the call as the wallet's API host while collecting.
	 * @testWith ["merchant_app", "https://api.merchant-app.fake.test"]
	 *           ["platform", "https://api.platform.fake.test"]
	 *
	 * @param string $pick     The app the transport picks.
	 * @param string $expected The host.
	 */
	public function test_collecting_store_api_host_is_the_transport_host( string $pick, string $expected ): void {
		$this->set_collecting();

		$this->assertSame( $expected, $this->boot_with( new FakePlatformTransport( array( 'pick' => $pick ) ) )->get( 'api.host' ) );
	}

	/**
	 * @testdox Should use the collecting payee as the merchant email and no merchant ID while collecting.
	 */
	public function test_collecting_store_merchant_email_and_id(): void {
		$this->set_collecting();

		$container = $this->boot_with( new FakePlatformTransport() );

		$this->assertSame( 'payee@example.com', $container->get( 'api.merchant_email' ) );
		$this->assertSame( '', $container->get( 'api.merchant_id' ) );
	}

	/**
	 * @testdox Should use the platform payee and merchant ID once the store is platform connected.
	 */
	public function test_platform_connected_store_merchant_email_and_id(): void {
		$this->set_platform_connected();

		$container = $this->boot_with( new FakePlatformTransport() );

		$this->assertSame( 'connected@example.com', $container->get( 'api.merchant_email' ) );
		$this->assertSame( 'M2', $container->get( 'api.merchant_id' ) );
		$this->assertInstanceOf( ContextBearer::class, $container->get( 'api.bearer' ) );
	}

	/**
	 * @testdox Should use the transport's partner merchant ID while collecting.
	 */
	public function test_collecting_store_partner_merchant_id(): void {
		$this->set_collecting();

		$this->assertSame( 'PARTNER-FAKE', $this->boot_with( new FakePlatformTransport() )->get( 'api.partner_merchant_id' ) );
	}

	/**
	 * @testdox Should load the SDK with the platform's client ID, whatever app the transport would pick.
	 */
	public function test_collecting_store_button_client_id_is_the_platform_client_id(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_MERCHANT_APP ) );

		$this->assertSame( 'client-platform', $this->boot_with( $transport )->get( 'button.client_id' ) );
		$this->assertSame( array( array( PlatformTransport::APP_PLATFORM ) ), $transport->calls_to( 'sdk_client_id' ) );
	}

	/**
	 * @testdox Should build the partner referrals endpoints on the platform's host and bearer while collecting.
	 * @testWith ["api.endpoint.partner-referrals"]
	 *           ["api.endpoint.partner-referrals-sandbox"]
	 *           ["api.endpoint.partner-referrals-production"]
	 *
	 * @param string $service The endpoint service.
	 */
	public function test_collecting_store_partner_referrals_use_the_platform( string $service ): void {
		$this->set_collecting();
		$this->stub_http( $this->http_response( 201, '{"links":[{"rel":"action_url","href":"https://example.com/onboard"}]}' ) );
		$endpoint = $this->boot_with( new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_MERCHANT_APP ) ) )->get( $service );

		$this->assertInstanceOf( PartnerReferrals::class, $endpoint );
		$this->assertSame( 'https://example.com/onboard', $endpoint->signup_link( array() ) );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( 'https://api.platform.fake.test/v2/customer/partner-referrals', $this->http_requests[0]['url'] );
		$this->assertSame( 'Bearer token-platform', $this->http_requests[0]['request']['headers']['Authorization'] );
	}

	/**
	 * @testdox Should keep the wallet's own values, and never ask the transport, when the platform does not serve the store.
	 */
	public function test_store_not_served_by_the_platform_keeps_the_wallet_values(): void {
		$transport = new FakePlatformTransport();
		$container = $this->boot_with( $transport );

		$this->assertInstanceOf( ConnectBearer::class, $container->get( 'api.bearer' ) );
		$this->assertSame( ApiHostResolver::class, get_class( $container->get( 'api.host-resolver' ) ) );
		$this->assertSame( $container->get( 'api.production-host' ), $container->get( 'api.host' ) );
		$this->assertSame( '', $container->get( 'api.merchant_email' ) );
		$this->assertSame( '', $container->get( 'api.merchant_id' ) );
		$this->assertSame( $container->get( 'api.partner_merchant_id-production' ), $container->get( 'api.partner_merchant_id' ) );
		$this->assertSame( CONNECT_WOO_CLIENT_ID, $container->get( 'button.client_id' ) );
		$this->assert_partner_referrals_signup( $container, 'api.endpoint.partner-referrals', $container->get( 'api.production-host' ) );
		$this->assert_partner_referrals_signup( $container, 'api.endpoint.partner-referrals-sandbox', CONNECT_WOO_SANDBOX_URL );
		$this->assert_partner_referrals_signup( $container, 'api.endpoint.partner-referrals-production', CONNECT_WOO_URL );
		$this->assertSame( array(), $transport->calls, 'The transport is never asked anything' );
	}

	/**
	 * @testdox Should keep the first-party values for a first-party connected store, even with a collecting option left behind.
	 */
	public function test_first_party_store_keeps_the_wallet_values(): void {
		$this->set_wallet_option(
			'woocommerce-ppcp-data-common',
			array(
				'merchant_connected' => true,
				'sandbox_merchant'   => false,
				'merchant_id'        => 'M1',
				'merchant_email'     => 'merchant@example.com',
				'client_id'          => 'client-id',
				'client_secret'      => 'client-secret',
			)
		);
		$this->set_collecting();
		$transport = new FakePlatformTransport();

		$container = $this->boot_with( $transport );

		$this->assertInstanceOf( PayPalBearer::class, $container->get( 'api.bearer' ) );
		$this->assertSame( ApiHostResolver::class, get_class( $container->get( 'api.host-resolver' ) ) );
		$this->assertSame( 'merchant@example.com', $container->get( 'api.merchant_email' ) );
		$this->assertSame( 'M1', $container->get( 'api.merchant_id' ) );
		$this->assertSame( 'client-id', $container->get( 'button.client_id' ) );
		$this->assertSame( array(), $transport->calls, 'The transport is never asked anything' );
	}

	/**
	 * @testdox Should bind the not-ready transport and build every extended service without throwing until a call needs the platform.
	 */
	public function test_collecting_store_with_the_not_ready_transport_builds_every_service(): void {
		$this->set_collecting();

		$container = $this->boot_container();

		$this->assertInstanceOf( NotReadyTransport::class, $container->get( 'collecting.transport' ) );
		foreach ( self::EXTENDED_SERVICES as $service ) {
			$this->assertNotNull( $container->get( $service ), "$service resolves" );
		}
		$this->assertInstanceOf( ContextBearer::class, $container->get( 'api.bearer' ), 'The bearer is the context bearer even before the transport is ready' );
		$this->assertSame( $container->get( 'api.sandbox-host' ), $container->get( 'api.host' ), 'The host stays the wallet\'s until the transport is ready' );
		$this->assertSame( $container->get( 'api.partner_merchant_id-sandbox' ), $container->get( 'api.partner_merchant_id' ) );
		$this->assertSame( CONNECT_WOO_SANDBOX_CLIENT_ID, $container->get( 'button.client_id' ) );
		$this->assertSame( ( new ApiHostResolver( $container->get( 'settings.connection-state' ) ) )->host(), $container->get( 'api.host-resolver' )->host(), 'The host resolver answers the wallet\'s host until the transport is ready' );
		$this->assertSame( 'payee@example.com', $container->get( 'api.merchant_email' ) );
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * @testdox Should share one order app context, not entered, across the container.
	 */
	public function test_order_app_context_is_shared_and_not_entered(): void {
		$this->set_collecting();
		$container = $this->boot_with( new FakePlatformTransport() );

		$context = $container->get( 'collecting.order-app-context' );

		$this->assertInstanceOf( OrderAppContext::class, $context );
		$this->assertSame( $context, $container->get( 'collecting.order-app-context' ) );
		$this->assertFalse( $context->is_entered() );
	}

	/**
	 * Assert a partner referrals endpoint posts to the given host.
	 *
	 * @param ContainerInterface $container The container.
	 * @param string             $service   The endpoint service.
	 * @param string             $host      The expected host.
	 */
	private function assert_partner_referrals_signup( ContainerInterface $container, string $service, string $host ): void {
		$this->http_requests = array();
		$this->stub_http( $this->http_response( 201, '{"links":[{"rel":"action_url","href":"https://example.com/onboard"}]}' ) );

		$container->get( $service )->signup_link( array() );

		$this->assertSame( trailingslashit( $host ) . 'v2/customer/partner-referrals', $this->http_requests[0]['url'], "$service posts to $host" );
	}
}
