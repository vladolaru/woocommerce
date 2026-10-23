<?php
/**
 * Tests for the PayPal wallet vault payment capture endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\CapturePayPalPayment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;

/**
 * Creating a PayPal order from a saved vault token sends the order's purchase unit and the vault ID as the payment source.
 *
 * @group paypal-wallet
 */
class CapturePayPalPaymentTest extends WalletTestCase {

	/**
	 * Build the endpoint under test; the order creation request is answered with a canned success.
	 *
	 * @param PurchaseUnit|null $purchase_unit The purchase unit from_wc_order() returns; defaults to one whose to_array() is empty.
	 * @return CapturePayPalPayment
	 */
	private function make_testee( ?PurchaseUnit $purchase_unit = null ): CapturePayPalPayment {
		if ( ! $purchase_unit ) {
			$purchase_unit = $this->mock( PurchaseUnit::class );
			$purchase_unit->shouldReceive( 'to_array' )->andReturn( array() );
		}

		$purchase_unit_factory = $this->mock( PurchaseUnitFactory::class );
		$purchase_unit_factory->shouldReceive( 'from_wc_order' )->once()->andReturn( $purchase_unit );
		$purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );

		$order_factory = $this->mock( OrderFactory::class );
		$order_factory->shouldReceive( 'from_paypal_response' )->andReturn( $this->mock( Order::class ) );

		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'payment_intent' )->andReturn( 'capture' );

		$this->stub_http( $this->http_response( 200, '{"id":"MOCK-ORDER-ID"}' ) );

		return new CapturePayPalPayment(
			'https://api.paypal.com',
			$this->make_bearer( false ),
			$order_factory,
			$purchase_unit_factory,
			$settings_provider,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * The decoded JSON body of the one request the endpoint sent.
	 *
	 * @return array
	 */
	private function sent_body(): array {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );
		$this->assertSame( 'https://api.paypal.com/v2/checkout/orders', $this->http_requests[0]['url'] );

		return (array) json_decode( $this->http_requests[0]['request']['body'], true );
	}

	/**
	 * A saved-token payment builds its purchase units from the WC order, never from the cart (PCP-6702).
	 *
	 * from_wc_cart() always produces an empty invoice_id and a session-based custom_id, so the order must be the
	 * single source of truth regardless of the current request. The mock expectations in make_testee() fail the test
	 * if from_wc_order() is not called or from_wc_cart() is.
	 *
	 * @testdox Should build the purchase unit from the WC order, never from the cart.
	 */
	public function test_create_order_builds_purchase_unit_from_wc_order(): void {
		$testee = $this->make_testee();

		$testee->create_order( 'vault-abc-123', $this->mock( WC_Order::class ) );

		$this->assertArrayHasKey( 'purchase_units', $this->sent_body() );
	}

	/**
	 * PayPal's Orders v2 API only reads custom_id and invoice_id from inside a purchase unit, so leaving them at the
	 * request root (the PCP-6702 bug) means PayPal silently ignores them.
	 *
	 * @testdox Should send no top-level custom_id or invoice_id.
	 */
	public function test_create_order_does_not_send_top_level_custom_id_or_invoice_id(): void {
		$testee = $this->make_testee();

		$testee->create_order( 'vault-abc-123', $this->mock( WC_Order::class ) );

		$body = $this->sent_body();
		$this->assertArrayNotHasKey( 'custom_id', $body );
		$this->assertArrayNotHasKey( 'invoice_id', $body );
	}

	/**
	 * @testdox Should forward the purchase unit's custom_id and invoice_id untouched.
	 */
	public function test_create_order_forwards_purchase_unit_custom_id_and_invoice_id(): void {
		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'to_array' )->andReturn(
			array(
				'custom_id'  => '14121',
				'invoice_id' => 'PP-FLYERALARMDigital-422136',
			)
		);
		$testee = $this->make_testee( $purchase_unit );

		$testee->create_order( 'vault-abc-123', $this->mock( WC_Order::class ) );

		$body = $this->sent_body();
		$this->assertSame( '14121', $body['purchase_units'][0]['custom_id'] ?? null );
		$this->assertSame( 'PP-FLYERALARMDigital-422136', $body['purchase_units'][0]['invoice_id'] ?? null );
	}

	/**
	 * @testdox Should key the payment source by the given name and carry the vault ID.
	 */
	public function test_create_order_forwards_vault_id_and_payment_source_name(): void {
		$testee = $this->make_testee();

		$testee->create_order( 'vault-venmo-1', $this->mock( WC_Order::class ), 'venmo' );

		$body = $this->sent_body();
		$this->assertSame( 'vault-venmo-1', $body['payment_source']['venmo']['vault_id'] ?? null );
	}

	/**
	 * @testdox Should default the payment source to PayPal when no name is given.
	 */
	public function test_create_order_defaults_payment_source_name_to_paypal(): void {
		$testee = $this->make_testee();

		$testee->create_order( 'vault-abc-123', $this->mock( WC_Order::class ) );

		$body = $this->sent_body();
		$this->assertArrayHasKey( 'paypal', $body['payment_source'] ?? array() );
	}
}
