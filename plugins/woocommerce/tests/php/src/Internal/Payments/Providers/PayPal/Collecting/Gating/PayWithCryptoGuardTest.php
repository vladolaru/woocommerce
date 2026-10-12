<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PayWithCryptoGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the filter that keeps the Pay with Crypto gateway off a collecting store.
 *
 * @group paypal-wallet
 */
class PayWithCryptoGuardTest extends WalletTestCase {

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
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
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
}
