<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\MerchantlessPartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests that the wallet's seller status is never asked for while the store has no merchant ID: a collecting store.
 *
 * @group paypal-wallet
 */
class MerchantlessPartnersEndpointTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * Boot the container with a ready fake transport, so the wallet's calls would be signed and sent.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

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
	 * The requests sent to PayPal's partner merchant-integrations endpoint.
	 *
	 * @return array[]
	 */
	private function seller_status_requests(): array {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false !== strpos( $entry['url'], '/v1/customer/partners/' );
				}
			)
		);
	}

	/**
	 * @testdox Should refuse the seller status on a collecting store with the wallet's exception and send nothing.
	 */
	public function test_collecting_store_seller_status_sends_nothing(): void {
		$this->set_collecting();
		$this->stub_http( $this->http_response( 403, '{"name":"NOT_AUTHORIZED","message":"no"}' ) );

		$endpoint = $this->boot()->get( 'api.endpoint.partners' );

		$this->assertInstanceOf( PartnersEndpoint::class, $endpoint, 'The wallet\'s type is kept for its readers' );
		try {
			$endpoint->seller_status();
			$this->fail( 'A store with no merchant ID has no seller status' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( array(), $this->seller_status_requests(), 'No seller status request with an empty merchant ID' );
		}
	}

	/**
	 * @testdox Should send no seller status request when the wallet refreshes its product statuses on a collecting store.
	 */
	public function test_collecting_store_product_status_refresh_sends_nothing(): void {
		$this->set_collecting();
		$this->stub_http( $this->http_response( 403, '{"name":"NOT_AUTHORIZED","message":"no"}' ) );
		$container = $this->boot();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing the wallet's action as a plugin update does.
		do_action( 'woocommerce_paypal_payments_clear_apm_product_status' );
		$dcc = $container->get( 'wcgateway.helper.dcc-product-status' );

		$this->assertInstanceOf( DCCProductStatus::class, $dcc );
		$this->assertFalse( $dcc->is_active(), 'Card fields stay unavailable while collecting' );
		$this->assertSame( array(), $this->seller_status_requests(), 'No seller status request with an empty merchant ID' );
	}

	/**
	 * @testdox Should keep the wallet's seller status call for a platform-connected store, with its merchant ID.
	 */
	public function test_platform_connected_store_keeps_the_seller_status_call(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'environment' => 'sandbox',
			)
		);
		$this->stub_http( $this->http_response( 200, '{"merchant_id":"M2","products":[],"capabilities":[]}' ) );

		$endpoint = $this->boot()->get( 'api.endpoint.partners' );
		$endpoint->seller_status();

		$this->assertNotInstanceOf( MerchantlessPartnersEndpoint::class, $endpoint );
		$requests = $this->seller_status_requests();
		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/merchant-integrations/M2', $requests[0]['url'] );
	}
}
