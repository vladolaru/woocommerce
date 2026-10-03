<?php
/**
 * Tests for the PayPal wallet own-webhook resolver (ported from the extension's OwnWebhookResolverTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use stdClass;

/**
 * Which of the webhooks on the connected PayPal REST app belongs to this install: by stored ID, or by host and path.
 *
 * @group paypal-wallet
 */
class OwnWebhookResolverTest extends WalletTestCase {

	/**
	 * The incoming webhook endpoint mock, which knows this install's webhook URL.
	 *
	 * @var IncomingWebhookEndpoint|\Mockery\MockInterface
	 */
	private $incoming_webhook_endpoint;

	/**
	 * Build the collaborator mock.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->incoming_webhook_endpoint = $this->mock( IncomingWebhookEndpoint::class );
	}

	/**
	 * The resolver under test.
	 *
	 * @return OwnWebhookResolver
	 */
	private function create_resolver(): OwnWebhookResolver {
		return new OwnWebhookResolver( $this->incoming_webhook_endpoint );
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
	 * @testdox Should normalize "$url" to the same identity as the plain URL: host and path only.
	 *
	 * @dataProvider identity_normalization_provider
	 *
	 * @param string $url A variant of the webhook URL.
	 */
	public function test_identity_normalizes_url_variants( string $url ): void {
		$this->assertSame( 'mysite.com/wp-json/paypal/v1/incoming', $this->create_resolver()->identity( $url ) );
	}

	/**
	 * URLs that differ only by host case, a trailing slash, a port, a query string, or the scheme.
	 *
	 * @return array<string, array<string>>
	 */
	public function identity_normalization_provider(): array {
		return array(
			'plain'                => array( 'https://mysite.com/wp-json/paypal/v1/incoming' ),
			'host case differs'    => array( 'https://MySite.com/wp-json/paypal/v1/incoming' ),
			'trailing slash'       => array( 'https://mysite.com/wp-json/paypal/v1/incoming/' ),
			'port is ignored'      => array( 'https://mysite.com:8080/wp-json/paypal/v1/incoming' ),
			'query string ignored' => array( 'https://mysite.com/wp-json/paypal/v1/incoming?token=abc' ),
			'scheme is ignored'    => array( 'http://mysite.com/wp-json/paypal/v1/incoming' ),
		);
	}

	/**
	 * @testdox Should return an empty identity for the unparseable URL "$url".
	 *
	 * @dataProvider unparseable_url_provider
	 *
	 * @param string $url A URL with no host.
	 */
	public function test_identity_returns_empty_string_for_unparseable_url( string $url ): void {
		$this->assertSame( '', $this->create_resolver()->identity( $url ) );
	}

	/**
	 * URLs that cannot be parsed into a host.
	 *
	 * @return array<string, array<string>>
	 */
	public function unparseable_url_provider(): array {
		return array(
			'not a valid URL' => array( 'not-a-valid-url' ),
			'empty string'    => array( '' ),
		);
	}

	/**
	 * A webhook whose host has since changed (an NGROK_HOST rotation or a domain migration) is still ours by its stored ID.
	 *
	 * @testdox Should recognize a webhook by its stored ID even when its host no longer matches.
	 */
	public function test_is_own_true_when_stored_id_matches_despite_host_change(): void {
		$this->set_wallet_option(
			WebhookRegistrar::KEY,
			array(
				'id'  => 'STORED_ID',
				'url' => 'https://old-host.com/wp-json/paypal/v1/incoming',
			)
		);
		$this->own_url_is( 'https://new-host.com/wp-json/paypal/v1/incoming' );

		$webhook = new Webhook( 'https://old-host.com/wp-json/paypal/v1/incoming', array(), 'STORED_ID' );

		$this->assertTrue( $this->create_resolver()->is_own( $webhook ) );
	}

	/**
	 * @testdox Should recognize a webhook by host and path when no ID was stored.
	 */
	public function test_is_own_true_on_url_identity_match_with_no_stored_id(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$webhook = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array(), 'SOME_ID' );

		$this->assertTrue( $this->create_resolver()->is_own( $webhook ) );
	}

	/**
	 * @testdox Should treat a webhook on the same host but another path (a sibling subdirectory install) as foreign.
	 */
	public function test_is_own_false_for_same_host_different_path(): void {
		$this->own_url_is( 'https://example.com/shop/wp-json/paypal/v1/incoming' );

		$webhook = new Webhook( 'https://example.com/staging/wp-json/paypal/v1/incoming', array(), 'SIBLING' );

		$this->assertFalse( $this->create_resolver()->is_own( $webhook ) );
	}

	/**
	 * @testdox Should treat a webhook registered for a different host as foreign.
	 */
	public function test_is_own_false_for_foreign_host(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$webhook = new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array(), 'FOREIGN' );

		$this->assertFalse( $this->create_resolver()->is_own( $webhook ) );
	}

	/**
	 * @testdox Should treat a webhook whose URL has no parseable host as foreign.
	 */
	public function test_is_own_false_for_unparseable_webhook_url(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$webhook = new Webhook( 'not-a-valid-url', array(), 'BROKEN' );

		$this->assertFalse( $this->create_resolver()->is_own( $webhook ) );
	}

	/**
	 * Regression case for PCP-6885: the previous code took list()[0], so a foreign webhook returned first on the PayPal
	 * app hid the own webhook entirely.
	 *
	 * @testdox Should find the own webhook even when a foreign webhook is listed first.
	 */
	public function test_find_own_returns_own_webhook_when_foreign_webhook_is_listed_first(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$foreign_webhook = new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array(), 'FOREIGN' );
		$own_webhook     = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array(), 'OWN' );

		$found = $this->create_resolver()->find_own( array( $foreign_webhook, $own_webhook ) );

		$this->assertNotNull( $found );
		$this->assertSame( 'OWN', $found->id() );
	}

	/**
	 * @testdox Should find no own webhook when none of the listed webhooks belongs to this install.
	 */
	public function test_find_own_returns_null_when_no_webhook_matches(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$foreign_webhook = new Webhook( 'https://other-clone.com/wp-json/paypal/v1/incoming', array(), 'FOREIGN' );

		$this->assertNull( $this->create_resolver()->find_own( array( $foreign_webhook ) ) );
	}

	/**
	 * @testdox Should find no own webhook in an empty list.
	 */
	public function test_find_own_returns_null_for_empty_list(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$this->assertNull( $this->create_resolver()->find_own( array() ) );
	}

	/**
	 * @testdox Should skip values that are not webhooks and still find the own webhook.
	 */
	public function test_find_own_skips_non_webhook_values(): void {
		$this->own_url_is( 'https://mysite.com/wp-json/paypal/v1/incoming' );

		$own_webhook = new Webhook( 'https://mysite.com/wp-json/paypal/v1/incoming', array(), 'OWN' );

		$found = $this->create_resolver()->find_own( array( new stdClass(), 'a-string', $own_webhook ) );

		$this->assertNotNull( $found );
		$this->assertSame( 'OWN', $found->id() );
	}
}
