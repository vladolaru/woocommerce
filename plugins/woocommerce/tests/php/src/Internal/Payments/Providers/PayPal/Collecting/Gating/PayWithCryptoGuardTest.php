<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PayWithCryptoGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the filter that keeps the Pay with Crypto gateway off a collecting store.
 *
 * @group paypal-wallet
 */
class PayWithCryptoGuardTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var PayWithCryptoGuard
	 */
	private $sut;

	/**
	 * Build the SUT over the stored options.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new PayWithCryptoGuard( new ConnectionState() );
	}

	/**
	 * Put the store in one of the states the guard leaves alone.
	 *
	 * @param string $state The fixture: platform, first_party or dormant.
	 */
	private function set_state( string $state ): void {
		if ( 'platform' === $state ) {
			$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		} elseif ( 'first_party' === $state ) {
			$this->set_wallet_option(
				'woocommerce-ppcp-data-common',
				array(
					'merchant_email' => 'm@example.com',
					'merchant_id'    => 'M1',
					'client_id'      => 'id',
					'client_secret'  => 'secret',
				)
			);
		}
	}

	/**
	 * @testdox Should remove the Pay with Crypto gateway from WooCommerce's list while the store collects.
	 */
	public function test_removes_the_gateway_while_collecting(): void {
		$this->set_collecting();
		$pwc    = (object) array( 'id' => GatewayIds::PWC );
		$paypal = (object) array( 'id' => 'ppcp-gateway' );

		$this->assertSame( array( $paypal, 'WC_Gateway_COD' ), $this->sut->handle_woocommerce_payment_gateways( array( $paypal, $pwc, 'WC_Gateway_COD' ) ) );
	}

	/**
	 * @testdox Should leave the list alone in the connected states and on a dormant store.
	 * @testWith ["platform"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The fixture.
	 */
	public function test_leaves_the_list_alone_outside_collecting( string $state ): void {
		$this->set_state( $state );
		$gateways = array( (object) array( 'id' => GatewayIds::PWC ) );

		$this->assertSame( $gateways, $this->sut->handle_woocommerce_payment_gateways( $gateways ) );
	}

	/**
	 * @testdox Should hand a non-array value back unchanged.
	 */
	public function test_returns_a_non_array_unchanged(): void {
		$this->set_collecting();

		$this->assertSame( 'not a list', $this->sut->handle_woocommerce_payment_gateways( 'not a list' ) );
	}

	/**
	 * @testdox Should keep Pay with Crypto out of the real gateway list of a collecting store whose shared cache holds a stale "yes".
	 */
	public function test_the_guard_wins_over_a_stale_cached_yes_in_the_real_filter_chain(): void {
		$this->set_collecting();
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			)
		);
		update_option( 'woocommerce_currency', 'USD' );
		// The shared entry in the shape the wallet's ProductStatusResultCache stores, as an earlier answer left it.
		$this->set_wallet_transient(
			'woocommerce-ppcp-cache-product-status',
			array(
				'products_pwc_enabled' => array(
					'value'      => 'yes',
					'expires_at' => 0,
				),
			)
		);
		remove_all_actions( 'after_setup_theme' );
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		do_action( 'after_setup_theme' );

		$with_guard = apply_filters( 'woocommerce_payment_gateways', array() );
		remove_all_filters( 'woocommerce_payment_gateways', 100 );
		$without_guard = apply_filters( 'woocommerce_payment_gateways', array() );

		$is_pwc = static fn( $gateway ): bool => $gateway instanceof PWCGateway;
		$this->assertCount( 1, array_filter( $without_guard, $is_pwc ), 'The wallet\'s filter adds the gateway on the stale "yes"' );
		$this->assertCount( 0, array_filter( $with_guard, $is_pwc ), 'The guard at priority 100 takes it out again' );
	}
}
