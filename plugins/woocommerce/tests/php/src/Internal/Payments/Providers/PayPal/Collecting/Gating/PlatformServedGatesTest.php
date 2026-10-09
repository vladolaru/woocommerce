<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedGates;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the filters that keep authorize-only and saved PayPal and Venmo off while the platform serves the store.
 *
 * @group paypal-wallet
 */
class PlatformServedGatesTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PlatformServedGates
	 */
	private $sut;

	/**
	 * Build the SUT over the stored options.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new PlatformServedGates( new ConnectionState() );
	}

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
	}

	/**
	 * @testdox Should force the capture intent in the case each call site uses while the platform serves the store.
	 *
	 * @testWith ["AUTHORIZE", "CAPTURE"]
	 *           ["CAPTURE", "CAPTURE"]
	 *           ["authorize", "capture"]
	 *           ["capture", "capture"]
	 *
	 * @param string $intent   The intent the call site passes.
	 * @param string $expected The intent the filter returns.
	 */
	public function test_forces_capture_while_collecting( string $intent, string $expected ): void {
		$this->set_collecting();

		$this->assertSame( $expected, $this->sut->handle_woocommerce_paypal_payments_order_intent( $intent ) );
	}

	/**
	 * @testdox Should answer the upper-case capture intent for a value that is not a string while the platform serves the store.
	 */
	public function test_forces_upper_case_capture_for_a_non_string(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );

		$this->assertSame( 'CAPTURE', $this->sut->handle_woocommerce_paypal_payments_order_intent( null ) );
	}

	/**
	 * @testdox Should leave the intent alone when the platform does not serve the store.
	 */
	public function test_keeps_the_intent_when_not_served(): void {
		$this->assertSame( 'AUTHORIZE', $this->sut->handle_woocommerce_paypal_payments_order_intent( 'AUTHORIZE' ) );
	}

	/**
	 * @testdox Should mark saved PayPal and Venmo unavailable and keep the other features while the platform serves the store.
	 */
	public function test_hides_saved_paypal_and_venmo_while_collecting(): void {
		$this->set_collecting();

		$features = $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features(
			array(
				'save_paypal_and_venmo' => array( 'enabled' => true ),
				'installments'          => array( 'enabled' => true ),
			)
		);

		$this->assertFalse( $features['save_paypal_and_venmo']['enabled'] );
		$this->assertTrue( $features['installments']['enabled'], 'Only the vault feature changes' );
	}

	/**
	 * @testdox Should leave the features alone when the platform does not serve the store, or when they are not an array.
	 */
	public function test_keeps_the_features_when_not_served_or_malformed(): void {
		$features = array( 'save_paypal_and_venmo' => array( 'enabled' => true ) );

		$this->assertSame( $features, $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features( $features ) );

		$this->set_collecting();
		$this->assertSame( 'oops', $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features( 'oops' ) );
	}
}
