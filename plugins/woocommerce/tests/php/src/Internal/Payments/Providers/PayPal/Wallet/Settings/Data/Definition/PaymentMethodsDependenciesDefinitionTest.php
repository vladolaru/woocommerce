<?php
/**
 * Tests for the payment methods dependencies definition.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDependenciesDefinition;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Which payment methods depend on which: every wallet method needs the PayPal gateway.
 *
 * @group paypal-wallet
 */
class PaymentMethodsDependenciesDefinitionTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodsDependenciesDefinition
	 */
	private $sut;

	/**
	 * Build the definition with no listener on the dependencies filter.
	 */
	public function setUp(): void {
		parent::setUp();

		// The test case restores the hooks after each test.
		remove_all_filters( 'woocommerce_paypal_payments_payment_method_dependencies' );

		$this->sut = new PaymentMethodsDependenciesDefinition();
	}

	/**
	 * @testdox Should make Pay with Crypto, Venmo and Pay Later depend on the PayPal gateway.
	 */
	public function test_wallet_methods_depend_on_the_paypal_gateway(): void {
		$dependencies = $this->sut->get_payment_method_dependencies();

		foreach ( array( 'ppcp-pwc', 'venmo', 'pay-later' ) as $method_id ) {
			$this->assertArrayHasKey( $method_id, $dependencies );
			$this->assertSame( array( 'ppcp-gateway' ), $dependencies[ $method_id ], "$method_id should depend on the PayPal gateway only" );
		}
	}
}
