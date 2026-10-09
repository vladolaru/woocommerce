<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\PlatformServedWebhookRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\PlatformServedWebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests that the wallet's webhook registration never touches the platform's subscriptions or writes `ppcp-webhook` while
 * the platform serves the store, and what the settings app's webhook status reports then.
 *
 * @group paypal-wallet
 */
class PlatformServedWebhooksTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * Both apps' subscriptions.
	 */
	private const SUBSCRIPTIONS = array(
		PlatformTransport::APP_PLATFORM     => 'WH-PLATFORM',
		PlatformTransport::APP_MERCHANT_APP => 'WH-MERCHANT',
	);

	/**
	 * The booted container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The fake transport.
	 *
	 * @var FakePlatformTransport
	 */
	private FakePlatformTransport $transport;

	/**
	 * Boot a collecting store over a fake transport holding both subscriptions.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_collecting();
		$this->set_wallet_option( Options::WEBHOOKS, self::SUBSCRIPTIONS );
		$this->transport = new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) );
		$this->container = $this->boot_container( array( new TransportBindingModule( $this->transport ) ) );
	}

	/**
	 * Assert that nothing reached PayPal and that the platform's subscriptions and the wallet's option are as they were.
	 */
	private function assert_untouched(): void {
		$this->assertSame( array(), $this->http_requests, 'No request to PayPal' );
		$this->assertFalse( get_option( 'ppcp-webhook' ), 'ppcp-webhook is not written' );
		$this->assertSame( self::SUBSCRIPTIONS, get_option( Options::WEBHOOKS ), 'The platform subscriptions are kept' );
	}

	/**
	 * @testdox Should register and unregister nothing while the platform serves the store.
	 */
	public function test_registrar_does_nothing_while_served(): void {
		$registrar = $this->container->get( 'webhook.registrar' );

		$this->assertInstanceOf( PlatformServedWebhookRegistrar::class, $registrar );
		$this->assertTrue( $registrar->register(), 'The platform subscriptions count as registered' );
		$registrar->unregister();

		$this->assert_untouched();
	}

	/**
	 * @testdox Should do nothing when a first-party connect fires the wallet's register event on a store the platform serves.
	 */
	public function test_register_event_does_nothing_while_served(): void {
		do_action( WebhookRegistrar::EVENT_HOOK );

		$this->assert_untouched();
	}

	/**
	 * @testdox Should hand registration to the wallet's registrar once the store is no longer served.
	 */
	public function test_registrar_delegates_once_not_served(): void {
		$registrar = $this->container->get( 'webhook.registrar' );
		delete_option( Options::COLLECTING );

		$registrar->register();

		$this->assertNotEmpty( $this->http_requests, 'The wallet\'s registrar ran' );
	}

	/**
	 * @testdox Should report the platform's subscriptions as the webhook status, and resubscribe to nothing, while served.
	 */
	public function test_settings_webhook_status_while_served(): void {
		$endpoint = $this->container->get( 'settings.rest.webhooks' );
		$this->assertInstanceOf( PlatformServedWebhookSettingsEndpoint::class, $endpoint );

		foreach ( array( $endpoint->get_webhooks(), $endpoint->resubscribe_webhooks() ) as $response ) {
			$body = $response->get_data();
			$this->assertTrue( $body['success'] );
			$this->assertTrue( $body['data']['served_by_platform'] );
			$this->assertSame( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ), $body['data']['apps'] );
			$this->assertStringEndsWith( '/paypal/v1/incoming', $body['data']['url'] );
			$this->assertSame( array(), $body['data']['events'] );
		}

		$this->assert_untouched();
	}

	/**
	 * @testdox Should verify a delivery to the listener route with the collecting endpoint, never the wallet's stored-webhook check.
	 */
	public function test_listener_route_is_verified_by_the_collecting_endpoint(): void {
		$previous_server           = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server fires rest_api_init, which registers the listener route of the container booted in setUp().
		try {
			$response = rest_do_request( $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OTHER' ) ) );
		} finally {
			$GLOBALS['wp_rest_server'] = $previous_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the REST server.
		}

		$this->assertSame( 200, $response->get_status(), 'The wallet\'s own check would answer 401: no ppcp-webhook' );
		$this->assertNotEmpty( $this->transport->calls_to( 'verify_webhook' ) );
		$this->assertFalse( get_option( 'ppcp-webhook' ) );
	}
}
