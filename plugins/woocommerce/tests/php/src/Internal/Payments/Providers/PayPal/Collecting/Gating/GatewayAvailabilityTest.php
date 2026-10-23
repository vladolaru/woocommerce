<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests that the wallet gateway is an available payment method on a collecting store, as the block checkout's
 * continuation after a PayPal approval needs it to be.
 *
 * @group paypal-wallet
 */
class GatewayAvailabilityTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * WooCommerce's gateway list before the test rebuilt it.
	 *
	 * @var array
	 */
	private array $original_gateways = array();

	/**
	 * Remember WooCommerce's gateway list.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Put WooCommerce's gateway list back as it was. Rebuilding it instead would pick up gateway filters added after
	 * WooCommerce first built it, and change what later tests see (PaymentRestEndpointTest saves through the list).
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->original_gateways;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Put the store in the collecting state with the gateway row WP-CLI or the gateway's own settings API writes.
	 */
	private function set_collecting_with_gateway_on(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			)
		);
	}

	/**
	 * Boot the wallet with a ready transport and rebuild WooCommerce's gateway list from it, as a served request does.
	 *
	 * @return ContainerInterface
	 */
	private function boot_and_load_gateways(): ContainerInterface {
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		WC()->payment_gateways()->init();

		return $container;
	}

	/**
	 * @testdox Should list the wallet gateway among the available payment gateways on a collecting store.
	 */
	public function test_collecting_store_gateway_is_available(): void {
		$this->set_collecting_with_gateway_on();
		$this->boot_and_load_gateways();

		$available = WC()->payment_gateways()->get_available_payment_gateways();

		$this->assertArrayHasKey( PayPalGateway::ID, $available, 'The Store API lists only these, and the block checkout continuation offers only those' );
		$this->assertInstanceOf( PayPalGateway::class, $available[ PayPalGateway::ID ] );
	}

	/**
	 * @testdox Should keep the wallet gateway off the available list, with no merchant email, on a collecting store whose transport is not configured.
	 */
	public function test_collecting_store_without_a_ready_transport_offers_no_gateway(): void {
		$this->set_collecting_with_gateway_on();
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport( array( 'ready' => false ) ) ) ) );
		WC()->payment_gateways()->init();

		$this->assertSame( '', $container->get( 'settings.settings-provider' )->merchant_email() );
		$this->assertArrayNotHasKey( PayPalGateway::ID, WC()->payment_gateways()->get_available_payment_gateways(), 'No buttons can render, so the gateway is not offered' );
	}

	/**
	 * @testdox Should report the payee as the merchant email of a collecting store, for the wallet's readers of the settings.
	 */
	public function test_collecting_store_merchant_email_is_the_payee(): void {
		$this->set_collecting_with_gateway_on();

		$provider = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) )->get( 'settings.settings-provider' );

		$this->assertSame( 'payee@example.com', $provider->merchant_email() );
	}

	/**
	 * @testdox Should keep the wallet gateway off the available list on a store still collecting, only because the gateway is off.
	 */
	public function test_collecting_store_gateway_turned_off_is_not_available(): void {
		// The row first and then the state: turning the gateway off on a collecting store would leave the state.
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'no',
				'ppcp'    => '',
			)
		);
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$container = $this->boot_and_load_gateways();

		$this->assertIsArray( get_option( Options::COLLECTING ), 'The store is still collecting' );
		$this->assertTrue( $container->get( 'collecting.connection-state' )->is_served_by_platform(), 'The platform serves the store' );
		$this->assertSame( 'payee@example.com', $container->get( 'settings.settings-provider' )->merchant_email(), 'The disabler has a merchant email, so it is not the reason' );
		$gateway = WC()->payment_gateways()->payment_gateways()[ PayPalGateway::ID ] ?? null;
		$this->assertInstanceOf( PayPalGateway::class, $gateway, 'The wallet\'s gateway is registered' );
		$this->assertSame( 'no', $gateway->enabled );
		$this->assertFalse( $gateway->is_available(), 'WooCommerce\'s own check refuses a gateway that is off' );
		$this->assertArrayNotHasKey( PayPalGateway::ID, WC()->payment_gateways()->get_available_payment_gateways() );
	}
}
