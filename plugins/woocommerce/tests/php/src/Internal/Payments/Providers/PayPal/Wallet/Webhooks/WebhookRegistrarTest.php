<?php
/**
 * Tests for the PayPal wallet webhook registrar.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookOrchestrator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Registering and unregistering the PayPal webhook subscription: only this site's webhooks are ever deleted.
 *
 * @group paypal-wallet
 */
class WebhookRegistrarTest extends WalletTestCase {

	/**
	 * The webhook factory mock.
	 *
	 * @var WebhookFactory|\Mockery\MockInterface
	 */
	private $webhook_factory;

	/**
	 * The PayPal webhook endpoint mock.
	 *
	 * @var WebhookEndpoint|\Mockery\MockInterface
	 */
	private $endpoint;

	/**
	 * The incoming webhook endpoint mock.
	 *
	 * @var IncomingWebhookEndpoint|\Mockery\MockInterface
	 */
	private $incoming_webhook_endpoint;

	/**
	 * The last webhook event storage mock.
	 *
	 * @var WebhookEventStorage|\Mockery\MockInterface
	 */
	private $last_webhook_event_storage;

	/**
	 * The webhook simulation mock.
	 *
	 * @var WebhookSimulation|\Mockery\MockInterface
	 */
	private $webhook_simulation;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|\Mockery\MockInterface
	 */
	private $logger;

	/**
	 * Messages the logger received at warning level.
	 *
	 * @var string[]
	 */
	private $logged_warnings = array();

	/**
	 * IDs of the webhooks the endpoint was asked to delete.
	 *
	 * @var string[]
	 */
	private $deleted = array();

	/**
	 * Build the collaborators and store a webhook for this install, so a test can see it removed.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->webhook_factory            = $this->mock( WebhookFactory::class );
		$this->endpoint                   = $this->mock( WebhookEndpoint::class );
		$this->incoming_webhook_endpoint  = $this->mock( IncomingWebhookEndpoint::class );
		$this->last_webhook_event_storage = $this->mock( WebhookEventStorage::class );
		$this->webhook_simulation         = $this->mock( WebhookSimulation::class );
		$this->logger                     = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();

		$this->logger->shouldReceive( 'warning' )->andReturnUsing(
			function ( string $message ): void {
				$this->logged_warnings[] = $message;
			}
		);
		$this->endpoint->shouldReceive( 'delete' )->andReturnUsing(
			function ( Webhook $webhook ): void {
				$this->deleted[] = $webhook->id();
			}
		)->byDefault();

		// An earlier registration of this install, under an ID none of the tests list, so it is only ever matched by the stored ID test.
		$this->set_wallet_option( WebhookRegistrar::KEY, array( 'id' => 'PREVIOUS' ) );
	}

	/**
	 * The registrar under test; the orchestrator only wraps the callback with a lock, so it just runs the callback.
	 *
	 * @return WebhookRegistrar
	 */
	private function create_registrar(): WebhookRegistrar {
		$orchestrator = $this->mock( WebhookOrchestrator::class );
		$orchestrator->shouldReceive( 'with_lock' )->andReturnUsing(
			static function ( string $name, callable $callback ) {
				unset( $name );
				return $callback();
			}
		);

		return new WebhookRegistrar(
			$this->webhook_factory,
			$this->endpoint,
			$this->incoming_webhook_endpoint,
			$this->last_webhook_event_storage,
			$this->webhook_simulation,
			$orchestrator,
			$this->logger,
			new OwnWebhookResolver( $this->incoming_webhook_endpoint )
		);
	}

	/**
	 * Make this install's incoming webhook URL the given one.
	 *
	 * @param string $url The URL.
	 */
	private function own_url_is( string $url ): void {
		$this->incoming_webhook_endpoint->shouldReceive( 'url' )->andReturn( $url );
	}

	/**
	 * Assert the stored webhook option was deleted.
	 */
	private function assert_stored_webhook_was_deleted(): void {
		$this->assertFalse( get_option( WebhookRegistrar::KEY ), 'The stored webhook option should be deleted' );
	}

	/**
	 * @testdox Should delete only the webhook matching this site's host and path, and warn about the one on a foreign host.
	 */
	public function test_unregister_deletes_webhook_matching_full_url_identity_and_skips_foreign_host(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andReturn(
			array(
				new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array(), 'OWN' ),
				new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array(), 'FOREIGN' ),
			)
		);
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assertSame( array( 'OWN' ), $this->deleted );
		$this->assertCount( 1, $this->logged_warnings );
		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * @testdox Should still delete the webhook when its host differs from this site's only by letter case.
	 */
	public function test_unregister_matches_host_case_insensitively(): void {
		$this->own_url_is( 'https://MySite.com/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array( new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array(), 'OWN' ) ) );
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assertSame( array( 'OWN' ), $this->deleted );
		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * A webhook on the same host under a different path is a sibling shop, for example in a subdirectory multisite.
	 *
	 * @testdox Should leave a webhook on the same host under a different path in place and warn about it.
	 */
	public function test_unregister_treats_same_host_different_path_as_foreign(): void {
		$this->own_url_is( 'https://example.com/shop-a/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array( new Webhook( 'https://example.com/shop-b/wp-json/paypal/v1/incoming', array(), 'SIBLING' ) ) );
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assertSame( array(), $this->deleted );
		$this->assertCount( 1, $this->logged_warnings );
		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * The webhook's host changed since this install registered it (an NGROK_HOST rotation or a domain migration), but
	 * the ID this install stored still identifies it.
	 *
	 * @testdox Should delete a webhook matched by the stored ID even though its host no longer matches this install's endpoint.
	 */
	public function test_unregister_deletes_webhook_matched_by_stored_id_despite_host_change(): void {
		$this->set_wallet_option(
			WebhookRegistrar::KEY,
			array(
				'id'  => 'STORED_ID',
				'url' => 'https://old-host.com/wp-json/paypal/v1/incoming',
			)
		);
		$this->own_url_is( 'https://new-host.com/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array( new Webhook( 'https://old-host.com/wp-json/paypal/v1/incoming', array(), 'STORED_ID' ) ) );
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assertSame( array( 'STORED_ID' ), $this->deleted );
		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * @testdox Should leave a webhook whose URL has no parseable host in place.
	 */
	public function test_unregister_skips_webhook_with_unparseable_host(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array( new Webhook( 'not-a-valid-url', array(), 'BROKEN' ) ) );
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assertSame( array(), $this->deleted );
		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * @testdox Should still delete the stored webhook option and clear the last event storage when listing the webhooks fails.
	 */
	public function test_unregister_still_clears_local_state_when_listing_webhooks_fails(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );
		$this->endpoint->shouldReceive( 'list' )->andThrow( new RuntimeException( 'API unavailable' ) );
		$this->endpoint->shouldNotReceive( 'delete' );
		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();

		$this->create_registrar()->unregister();

		$this->assert_stored_webhook_was_deleted();
	}

	/**
	 * @testdox Should create a webhook for this site's incoming endpoint, store it, start the simulation and report success when none is registered.
	 */
	public function test_register_creates_webhook_and_reports_success(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );
		$this->incoming_webhook_endpoint->shouldReceive( 'handled_event_types' )->andReturn( array( 'CHECKOUT.ORDER.APPROVED' ) );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array() );

		$new_webhook     = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array( 'CHECKOUT.ORDER.APPROVED' ) );
		$created_webhook = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array( 'CHECKOUT.ORDER.APPROVED' ), 'NEW-ID' );

		$this->webhook_factory->shouldReceive( 'for_url_and_events' )
			->with( 'https://mysite.com/wp-json/paypal/v1/incoming', array( 'CHECKOUT.ORDER.APPROVED' ) )
			->andReturn( $new_webhook );
		$this->endpoint->shouldReceive( 'create' )->with( $new_webhook )->andReturn( $created_webhook );

		$this->last_webhook_event_storage->shouldReceive( 'clear' )->twice();
		$this->webhook_simulation->shouldReceive( 'start' )->once()->with( $created_webhook );

		$result = $this->create_registrar()->register();

		$this->assertTrue( $result );
		$this->assertSame( $created_webhook->to_array(), get_option( WebhookRegistrar::KEY ) );
	}

	/**
	 * @testdox Should report failure and store nothing when PayPal returns no ID for the created webhook.
	 */
	public function test_register_reports_failure_when_created_webhook_has_no_id(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );
		$this->incoming_webhook_endpoint->shouldReceive( 'handled_event_types' )->andReturn( array( 'CHECKOUT.ORDER.APPROVED' ) );
		$this->endpoint->shouldReceive( 'list' )->andReturn( array() );

		$new_webhook     = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array( 'CHECKOUT.ORDER.APPROVED' ) );
		$created_webhook = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array( 'CHECKOUT.ORDER.APPROVED' ) );

		$this->webhook_factory->shouldReceive( 'for_url_and_events' )->andReturn( $new_webhook );
		$this->endpoint->shouldReceive( 'create' )->with( $new_webhook )->andReturn( $created_webhook );

		$this->last_webhook_event_storage->shouldReceive( 'clear' )->once();
		$this->webhook_simulation->shouldNotReceive( 'start' );

		$result = $this->create_registrar()->register();

		$this->assertFalse( $result );
		$this->assert_stored_webhook_was_deleted();
	}
}
