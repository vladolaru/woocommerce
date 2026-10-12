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
	 * @testdox Should register the ppcp-pwc-payment-method script with the dependencies and version of the build's asset file, plus wc-settings.
	 */
	public function test_registers_the_script_with_the_asset_files_dependencies(): void {
		$dir = trailingslashit( get_temp_dir() ) . 'pwc-payment-method-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$asset_file = $dir . '/ppcp-local-alternative-payment-methods-js-pwc-payment-method.asset.php';
		file_put_contents( $asset_file, "<?php return array( 'dependencies' => array( 'react-jsx-runtime', 'wc-blocks-registry' ), 'version' => 'abc123' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$asset_getter   = new AssetGetter( 'https://example.test/assets/', $dir, 'ppcp-local-alternative-payment-methods' );
		$payment_method = new PWCPaymentMethod( $asset_getter, '1.2.3', $this->gateway );

		try {
			$handles = $payment_method->get_payment_method_script_handles();
		} finally {
			wp_delete_file( $asset_file );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}

		$this->assertSame( array( 'ppcp-pwc-payment-method' ), $handles );
		$script = wp_scripts()->query( 'ppcp-pwc-payment-method' );
		$this->assertNotFalse( $script );
		$this->assertSame( 'https://example.test/assets/ppcp-local-alternative-payment-methods-js-pwc-payment-method.js', $script->src );
		$this->assertSame( array( 'react-jsx-runtime', 'wc-blocks-registry', 'wc-settings' ), $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertSame( array( 'group' => 1 ), $script->extra );
	}

	/**
	 * @testdox Should register the script with only wc-settings and the assets version when the build's asset file is missing.
	 */
	public function test_registers_the_script_with_the_fallbacks_when_the_asset_file_is_missing(): void {
		$asset_getter   = new AssetGetter( 'https://example.test/assets/', trailingslashit( get_temp_dir() ) . 'pwc-payment-method-test-missing', 'ppcp-local-alternative-payment-methods' );
		$payment_method = new PWCPaymentMethod( $asset_getter, '1.2.3', $this->gateway );

		$payment_method->get_payment_method_script_handles();

		$script = wp_scripts()->query( 'ppcp-pwc-payment-method' );
		$this->assertNotFalse( $script );
		$this->assertSame( array( 'wc-settings' ), $script->deps );
		$this->assertSame( '1.2.3', $script->ver );
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
