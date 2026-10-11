<?php
/**
 * Tests for the Pay with Crypto payment method of the block checkout.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCPaymentMethod;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The block checkout's Pay with Crypto method registers the compiled script and hands the checkout the gateway's id,
 * title, description and icon.
 *
 * @group paypal-wallet
 */
class PWCPaymentMethodTest extends WalletTestCase {

	/**
	 * The asset getter mock.
	 *
	 * @var AssetGetter&MockInterface
	 */
	private $asset_getter;

	/**
	 * The gateway mock.
	 *
	 * @var PWCGateway&MockInterface
	 */
	private $gateway;

	/**
	 * The payment method under test.
	 *
	 * @var PWCPaymentMethod
	 */
	private PWCPaymentMethod $payment_method;

	/**
	 * Build the payment method over mocks.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->asset_getter = $this->mock( AssetGetter::class );
		$this->gateway      = $this->mock( PWCGateway::class );

		$this->gateway->title       = 'Pay with Crypto';
		$this->gateway->description = 'Pay with top wallets and coins.';
		$this->gateway->icon        = 'https://example.test/static/images/pwc.svg';

		$this->payment_method = new PWCPaymentMethod( $this->asset_getter, '1.2.3', $this->gateway );
	}

	/**
	 * Deregister the script the test registered.
	 */
	public function tearDown(): void {
		wp_deregister_script( 'ppcp-pwc-payment-method' );

		parent::tearDown();
	}

	/**
	 * @testdox Should be named after the gateway and always active.
	 */
	public function test_is_named_after_the_gateway_and_active(): void {
		$this->assertSame( 'ppcp-pwc', $this->payment_method->get_name() );
		$this->assertTrue( $this->payment_method->is_active() );
	}

	/**
	 * @testdox Should register the ppcp-pwc-payment-method script with the asset getter's URL and the assets version.
	 */
	public function test_registers_the_script(): void {
		$this->asset_getter->shouldReceive( 'get_asset_url' )->with( 'pwc-payment-method.js' )->andReturn( 'https://example.test/assets/ppcp-local-alternative-payment-methods-js-pwc-payment-method.js' );

		$handles = $this->payment_method->get_payment_method_script_handles();

		$this->assertSame( array( 'ppcp-pwc-payment-method' ), $handles );
		$script = wp_scripts()->query( 'ppcp-pwc-payment-method' );
		$this->assertNotFalse( $script );
		$this->assertSame( 'https://example.test/assets/ppcp-local-alternative-payment-methods-js-pwc-payment-method.js', $script->src );
		$this->assertSame( '1.2.3', $script->ver );
		$this->assertSame( array(), $script->deps );
		$this->assertSame( array( 'group' => 1 ), $script->extra );
	}

	/**
	 * @testdox Should hand the checkout the gateway's id, title, description and icon.
	 */
	public function test_hands_the_checkout_the_gateway_data(): void {
		$this->assertSame(
			array(
				'id'          => 'ppcp-pwc',
				'title'       => 'Pay with Crypto',
				'description' => 'Pay with top wallets and coins.',
				'icon'        => 'https://example.test/static/images/pwc.svg',
			),
			$this->payment_method->get_payment_method_data()
		);
	}
}
