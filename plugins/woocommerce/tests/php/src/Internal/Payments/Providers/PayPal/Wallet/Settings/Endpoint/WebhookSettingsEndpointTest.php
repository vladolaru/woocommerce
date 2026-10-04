<?php
/**
 * Tests for the webhook settings endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\WebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The webhook data the settings screen shows: this site's own webhook out of the ones on the connected PayPal app, which
 * can hold the webhooks of other sites, and the failure answers when there is none or the list cannot be fetched.
 *
 * @group paypal-wallet
 */
class WebhookSettingsEndpointTest extends WalletTestCase {

	private const OWN_URL = 'https://mysite.com/wp-json/paypal/v1/incoming';

	/**
	 * The PayPal webhook list endpoint mock.
	 *
	 * @var WebhookEndpoint|MockInterface
	 */
	private $webhook_endpoint;

	/**
	 * The registrar mock.
	 *
	 * @var WebhookRegistrar|MockInterface
	 */
	private $webhook_registrar;

	/**
	 * The simulation mock.
	 *
	 * @var WebhookSimulation|MockInterface
	 */
	private $webhook_simulation;

	/**
	 * Build the collaborator mocks.
	 */
	public function setUp(): void {
		parent::setUp();

		// No stored webhook ID, so a webhook counts as this site's own by its URL only.
		$this->set_wallet_option( WebhookRegistrar::KEY, array() );

		$this->webhook_endpoint   = $this->mock( WebhookEndpoint::class );
		$this->webhook_registrar  = $this->mock( WebhookRegistrar::class );
		$this->webhook_simulation = $this->mock( WebhookSimulation::class );
	}

	/**
	 * The endpoint under test, with a resolver that knows this site's incoming webhook URL.
	 *
	 * @return WebhookSettingsEndpoint
	 */
	private function create_endpoint(): WebhookSettingsEndpoint {
		$incoming_webhook_endpoint = $this->mock( IncomingWebhookEndpoint::class );
		$incoming_webhook_endpoint->shouldReceive( 'url' )->andReturn( self::OWN_URL );

		return new WebhookSettingsEndpoint(
			$this->webhook_endpoint,
			$this->webhook_registrar,
			$this->webhook_simulation,
			new OwnWebhookResolver( $incoming_webhook_endpoint )
		);
	}

	/**
	 * Given a PayPal account with a foreign site's webhook listed first and this site's own webhook second, the own
	 * webhook's URL and its event names in lower case are returned. Reading the first entry of the list would have shown
	 * the foreign webhook instead.
	 *
	 * @testdox Should return this site's own webhook when a foreign webhook is listed first.
	 */
	public function test_get_webhooks_returns_own_webhook_when_foreign_webhook_is_listed_first(): void {
		$foreign_webhook = new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array( (object) array( 'name' => 'PAYMENT.CAPTURE.COMPLETED' ) ), 'FOREIGN' );
		$own_webhook     = new Webhook( self::OWN_URL, array( (object) array( 'name' => 'CHECKOUT.ORDER.APPROVED' ) ), 'OWN' );
		$this->webhook_endpoint->shouldReceive( 'list' )->andReturn( array( $foreign_webhook, $own_webhook ) );

		$data = $this->create_endpoint()->get_webhooks()->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( self::OWN_URL, $data['data']['url'] );
		$this->assertSame( array( 'checkout.order.approved' ), $data['data']['events'] );
	}

	/**
	 * @testdox Should report that no webhooks were found when the PayPal account only has the webhooks of other sites.
	 */
	public function test_get_webhooks_reports_failure_when_only_foreign_webhooks_exist(): void {
		$foreign_webhook = new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array(), 'FOREIGN' );
		$this->webhook_endpoint->shouldReceive( 'list' )->andReturn( array( $foreign_webhook ) );

		$data = $this->create_endpoint()->get_webhooks()->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'No webhooks found.', $data['message'] );
	}

	/**
	 * @testdox Should answer with a failure instead of an error when the list of PayPal webhooks cannot be fetched.
	 */
	public function test_get_webhooks_reports_failure_when_listing_webhooks_throws(): void {
		$this->webhook_endpoint->shouldReceive( 'list' )->andThrow( new RuntimeException( 'API unavailable' ) );

		$data = $this->create_endpoint()->get_webhooks()->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'No webhooks found.', $data['message'] );
	}

	/**
	 * @testdox Should re-subscribe the webhooks and return them, or report a failed subscription.
	 */
	public function test_resubscribe_registers_the_webhooks_and_returns_them(): void {
		$own_webhook = new Webhook( self::OWN_URL, array( (object) array( 'name' => 'PAYMENT.CAPTURE.REFUNDED' ) ), 'OWN' );
		$this->webhook_endpoint->shouldReceive( 'list' )->andReturn( array( $own_webhook ) );
		$this->webhook_registrar->shouldReceive( 'register' )->once()->andReturn( true );
		$endpoint = $this->create_endpoint();

		$data = $endpoint->resubscribe_webhooks()->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( array( 'payment.capture.refunded' ), $data['data']['events'] );

		$this->webhook_registrar->shouldReceive( 'register' )->once()->andReturn( false );

		$data = $endpoint->resubscribe_webhooks()->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'Webhook subscription failed.', $data['message'] );
	}

	/**
	 * @testdox Should start the webhook simulation and report its state, and turn a failure of either into an error answer.
	 */
	public function test_simulation_start_and_state(): void {
		$this->webhook_simulation->shouldReceive( 'start' )->once();
		$this->webhook_simulation->shouldReceive( 'get_state' )->once()->andReturn( 'waiting' );
		$endpoint = $this->create_endpoint();

		$this->assertTrue( $endpoint->simulate_webhooks_start()->get_data()['success'] );
		$this->assertSame( 'waiting', $endpoint->check_simulated_webhook_state()->get_data()['data']['state'] );

		$this->webhook_simulation->shouldReceive( 'start' )->once()->andThrow( new RuntimeException( 'Cannot start' ) );
		$this->webhook_simulation->shouldReceive( 'get_state' )->once()->andThrow( new RuntimeException( 'No state' ) );

		$started = $endpoint->simulate_webhooks_start()->get_data();
		$state   = $endpoint->check_simulated_webhook_state()->get_data();

		$this->assertFalse( $started['success'] );
		$this->assertSame( 'Cannot start', $started['message'] );
		$this->assertFalse( $state['success'] );
		$this->assertSame( 'No state', $state['message'] );
	}
}
