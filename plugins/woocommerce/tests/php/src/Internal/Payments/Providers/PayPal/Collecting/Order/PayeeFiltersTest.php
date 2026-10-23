<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\PayeeFilters;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Patch;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PatchCollection;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests for the payee the collecting state puts on new PayPal orders and on the JS SDK.
 *
 * @group paypal-wallet
 */
class PayeeFiltersTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * Set the store state.
	 *
	 * @param string $state `collecting`, `platform_connected` or `dormant`.
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
		} elseif ( ConnectionState::COLLECTING === $state ) {
			$this->set_wallet_option(
				Options::COLLECTING,
				array(
					'payee_email' => 'pay+ee@example.com',
					'tracking_id' => 'abc',
					'environment' => 'sandbox',
					'payee_bound' => false,
				)
			);
		}
	}

	/**
	 * Boot the wallet with the fake transport.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * Turn the PayPal gateway on, so the wallet builds real smart buttons.
	 */
	private function enable_gateway(): void {
		$this->set_wallet_option( 'woocommerce_' . PayPalGateway::ID . '_settings', array( 'enabled' => 'yes' ) );
	}

	/**
	 * The filters over the stored state.
	 *
	 * @return PayeeFilters
	 */
	private function sut(): PayeeFilters {
		return new PayeeFilters( new ConnectionState(), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
	}

	/**
	 * Create a PayPal order through the wallet's order endpoint and return the decoded body it sent.
	 *
	 * @param ContainerInterface $container The container.
	 * @return array
	 */
	private function create_order_body( ContainerInterface $container ): array {
		$this->stub_http( $this->http_response( 201, '{"id":"PP-NEW","status":"CREATED","intent":"CAPTURE"}' ) );

		$container->get( 'api.endpoint.order' )->create(
			array(
				new PurchaseUnit( new Amount( new Money( 10.0, 'USD' ) ), array(), null, 'first' ),
				new PurchaseUnit( new Amount( new Money( 5.0, 'USD' ) ), array(), null, 'second' ),
			),
			'NO_SHIPPING'
		);

		$this->assertCount( 1, $this->http_requests );
		$this->assertStringEndsWith( '/v2/checkout/orders', $this->http_requests[0]['url'] );

		return json_decode( $this->http_requests[0]['request']['body'], true );
	}

	/**
	 * @testdox Should create a PayPal order with intent CAPTURE paying the payee email while collecting and the merchant ID once platform connected.
	 * @testWith ["collecting", {"email_address": "pay+ee@example.com"}]
	 *           ["platform_connected", {"merchant_id": "M2"}]
	 *
	 * @param string $state The store state.
	 * @param array  $payee The payee every purchase unit carries.
	 */
	public function test_create_order_body_carries_the_payee( string $state, array $payee ): void {
		$this->set_state( $state );

		$body = $this->create_order_body( $this->boot() );

		$this->assertSame( 'CAPTURE', $body['intent'] );
		$this->assertCount( 2, $body['purchase_units'] );
		foreach ( $body['purchase_units'] as $unit ) {
			$this->assertSame( $payee, $unit['payee'] );
		}
	}

	/**
	 * @testdox Should leave the create-order body without a payee on a store the platform does not serve.
	 */
	public function test_create_order_body_is_untouched_when_not_served(): void {
		$body = $this->create_order_body( $this->boot() );

		foreach ( $body['purchase_units'] as $unit ) {
			$this->assertArrayNotHasKey( 'payee', $unit );
		}
	}

	/**
	 * @testdox Should hand back a create-order body it cannot read unchanged, and replace a payee another filter set.
	 */
	public function test_create_order_body_shapes(): void {
		$this->set_state( ConnectionState::COLLECTING );
		$sut = $this->sut();

		$this->assertSame( 'not an array', $sut->handle_ppcp_create_order_request_body_data( 'not an array' ) );
		$this->assertSame( array( 'intent' => 'CAPTURE' ), $sut->handle_ppcp_create_order_request_body_data( array( 'intent' => 'CAPTURE' ) ) );
		$this->assertSame( array( 'purchase_units' => 'nope' ), $sut->handle_ppcp_create_order_request_body_data( array( 'purchase_units' => 'nope' ) ) );
		$this->assertSame(
			array(
				'purchase_units' => array(
					'not a unit',
					array(
						'reference_id' => 'a',
						'payee'        => array( 'email_address' => 'pay+ee@example.com' ),
					),
				),
			),
			$sut->handle_ppcp_create_order_request_body_data(
				array(
					'purchase_units' => array(
						'not a unit',
						array(
							'reference_id' => 'a',
							'payee'        => array( 'merchant_id' => 'SOMEONE' ),
						),
					),
				)
			)
		);
	}

	/**
	 * @testdox Should keep the payee on each whole purchase unit an order patch replaces or adds, and leave other operations alone.
	 * @testWith ["collecting", {"email_address": "pay+ee@example.com"}]
	 *           ["platform_connected", {"merchant_id": "M2"}]
	 *
	 * @param string $state The store state.
	 * @param array  $payee The payee every whole purchase unit carries.
	 */
	public function test_patch_body_keeps_the_payee( string $state, array $payee ): void {
		$this->set_state( $state );
		$unit = ( new PurchaseUnit( new Amount( new Money( 10.0, 'USD' ) ) ) )->to_array();
		$this->stub_http( $this->http_response( 204, '' ) );

		$this->boot()->get( 'api.endpoint.order' )->patch(
			'PP-1',
			new PatchCollection(
				new Patch( 'replace', "/purchase_units/@reference_id=='default'", $unit ),
				new Patch( 'add', "/purchase_units/@reference_id=='second'", $unit ),
				new Patch( 'replace', "/purchase_units/@reference_id=='default'/amount", $unit['amount'] ),
				new Patch( 'remove', "/purchase_units/@reference_id=='third'", array() )
			)
		);

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( 'PATCH', $this->http_requests[0]['request']['method'] );
		$body = json_decode( $this->http_requests[0]['request']['body'], true );
		$this->assertSame( $payee, $body[0]['value']['payee'], 'A replaced unit keeps the payee' );
		$this->assertSame( $payee, $body[1]['value']['payee'], 'An added unit gets the payee' );
		$this->assertArrayNotHasKey( 'payee', $body[2]['value'], 'A field inside a unit is not a unit' );
		$this->assertSame( array(), $body[3]['value'], 'A removal is left alone' );
	}

	/**
	 * @testdox Should leave an order patch untouched on a store the platform does not serve, and hand back a body it cannot read.
	 */
	public function test_patch_body_is_untouched_when_not_served_or_unreadable(): void {
		$unit  = array( 'reference_id' => 'default' );
		$patch = array(
			array(
				'op'    => 'replace',
				'path'  => "/purchase_units/@reference_id=='default'",
				'value' => $unit,
			),
		);

		$this->assertSame( $patch, $this->sut()->handle_ppcp_patch_order_request_body_data( $patch ), 'Not served' );

		$this->set_state( ConnectionState::COLLECTING );
		$sut = $this->sut();
		$this->assertSame( 'nope', $sut->handle_ppcp_patch_order_request_body_data( 'nope' ) );
		$malformed = array(
			'not a patch',
			array(
				'op'    => 'replace',
				'path'  => 42,
				'value' => $unit,
			),
			array(
				'op'    => 'replace',
				'path'  => "/purchase_units/@reference_id=='default'",
				'value' => 'not a unit',
			),
			array(
				'op'    => 'move',
				'path'  => '/purchase_units/0',
				'value' => $unit,
			),
		);
		$this->assertSame( $malformed, $sut->handle_ppcp_patch_order_request_body_data( $malformed ) );
		$indexed = $sut->handle_ppcp_patch_order_request_body_data(
			array(
				array(
					'op'    => 'replace',
					'path'  => '/purchase_units/0',
					'value' => $unit,
				),
			)
		);
		$this->assertSame( array( 'email_address' => 'pay+ee@example.com' ), $indexed[0]['value']['payee'], 'A unit addressed by index is a unit' );
	}

	/**
	 * @testdox Should load the SDK with the payee email while collecting and the merchant ID once platform connected, in the URL params and the URL.
	 * @testWith ["collecting", "pay+ee@example.com"]
	 *           ["platform_connected", "M2"]
	 *
	 * @param string $state       The store state.
	 * @param string $merchant_id The SDK merchant-id.
	 */
	public function test_script_data_carries_the_sdk_merchant_id( string $state, string $merchant_id ): void {
		$this->set_state( $state );
		$this->enable_gateway();

		$data = $this->boot()->get( 'button.smart-button' )->script_data();

		$this->assert_sdk_merchant_id( $merchant_id, $data );
	}

	/**
	 * @testdox Should load the block checkout SDK with the same merchant-id, read from the same script data.
	 * @testWith ["collecting", "pay+ee@example.com"]
	 *           ["platform_connected", "M2"]
	 *
	 * @param string $state       The store state.
	 * @param string $merchant_id The SDK merchant-id.
	 */
	public function test_block_checkout_data_carries_the_sdk_merchant_id( string $state, string $merchant_id ): void {
		$this->set_state( $state );
		$this->enable_gateway();

		$data = $this->boot()->get( 'blocks.method' )->get_payment_method_data();

		$this->assert_sdk_merchant_id( $merchant_id, $data['scriptData'] );
	}

	/**
	 * @testdox Should leave the SDK script data without a merchant-id on a store the platform does not serve.
	 */
	public function test_script_data_is_untouched_when_not_served(): void {
		$data = $this->sut()->handle_woocommerce_paypal_payments_localized_script_data(
			array(
				'url'        => 'https://www.paypal.com/sdk/js?client-id=abc',
				'url_params' => array( 'client-id' => 'abc' ),
			)
		);

		$this->assertSame( array( 'client-id' => 'abc' ), $data['url_params'] );
		$this->assertSame( 'https://www.paypal.com/sdk/js?client-id=abc', $data['url'] );
	}

	/**
	 * @testdox Should hand back script data it cannot read unchanged.
	 */
	public function test_script_data_shapes(): void {
		$this->set_state( ConnectionState::COLLECTING );
		$sut = $this->sut();

		$this->assertNull( $sut->handle_woocommerce_paypal_payments_localized_script_data( null ) );
		$this->assertSame( array(), $sut->handle_woocommerce_paypal_payments_localized_script_data( array() ) );
		$this->assertSame( array( 'url_params' => 'nope' ), $sut->handle_woocommerce_paypal_payments_localized_script_data( array( 'url_params' => 'nope' ) ) );
		$this->assertSame(
			array( 'url_params' => array( 'merchant-id' => 'pay+ee@example.com' ) ),
			$sut->handle_woocommerce_paypal_payments_localized_script_data( array( 'url_params' => array() ) ),
			'Without a URL only the params change'
		);
	}

	/**
	 * Assert the script data loads the SDK with a merchant-id, in the params the JS reads and in the URL built from them.
	 *
	 * @param string $merchant_id The expected merchant-id.
	 * @param array  $data        The script data.
	 */
	private function assert_sdk_merchant_id( string $merchant_id, array $data ): void {
		$this->assertSame( $merchant_id, $data['url_params']['merchant-id'] );
		$this->assertStringStartsWith( 'https://www.paypal.com/sdk/js?', $data['url'] );
		wp_parse_str( (string) wp_parse_url( $data['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $merchant_id, $query['merchant-id'], 'The URL decodes to the same merchant-id' );
		$this->assertSame( $data['url_params']['client-id'], $query['client-id'], 'The rest of the URL is kept' );
	}
}
