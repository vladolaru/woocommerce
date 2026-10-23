<?php
/**
 * Tests for the v6 block payment method.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\SdkV6Manager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks\V6PaymentMethod;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The script handle and the data the block payment method hands to WooCommerce Blocks.
 *
 * @group paypal-wallet
 */
class V6PaymentMethodTest extends WalletTestCase {

	/**
	 * The SDK manager mock.
	 *
	 * @var SdkV6Manager&MockInterface
	 */
	private $manager;

	/**
	 * The asset getter mock.
	 *
	 * @var AssetGetter&MockInterface
	 */
	private $asset_getter;

	/**
	 * The PayPal gateway mock.
	 *
	 * @var PayPalGateway&MockInterface
	 */
	private $gateway;

	/**
	 * Build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->manager      = $this->mock( SdkV6Manager::class );
		$this->asset_getter = $this->mock( AssetGetter::class );
		$this->gateway      = $this->mock( PayPalGateway::class );
	}

	/**
	 * Deregister the block script.
	 */
	public function tearDown(): void {
		try {
			wp_deregister_script( 'wc-ppcp-sdk-v6-blocks' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the system under test.
	 *
	 * @param callable|null $place_order_enabled The place-order-enabled provider.
	 * @return V6PaymentMethod
	 */
	private function create_sut( ?callable $place_order_enabled = null ): V6PaymentMethod {
		return new V6PaymentMethod(
			$this->manager,
			$this->asset_getter,
			'1.0.0',
			$this->gateway,
			null,
			null,
			'',
			$place_order_enabled
		);
	}

	/**
	 * Give the PayPal gateway the properties the payment method data reads.
	 */
	private function stub_gateway_for_payment_method_data(): void {
		$this->manager->shouldReceive( 'script_data' )->andReturn( array() );
		$this->gateway->shouldReceive( 'get_description' )->andReturn( 'Pay with PayPal.' );
		$this->gateway->title    = 'PayPal';
		$this->gateway->icon     = 'https://example.com/paypal-icon.png';
		$this->gateway->supports = array( 'products' );
	}

	/**
	 * @testdox Should register the block script with the webpack dependencies, version and WooCommerce translations and return its handle.
	 */
	public function test_get_payment_method_script_handles_passes_through_webpack_dependencies_and_version(): void {
		$this->asset_getter->shouldReceive( 'get_asset_url' )->with( 'checkout-block.js' )->andReturn( 'https://example.com/assets/checkout-block.js' );
		$this->asset_getter->shouldReceive( 'get_asset_data' )->with( 'checkout-block.js', '1.0.0' )->andReturn(
			array(
				'dependencies' => array( 'wp-data', 'wp-element', 'wp-i18n' ),
				'version'      => 'deadbeef',
			)
		);

		$handles = $this->create_sut()->get_payment_method_script_handles();

		$this->assertSame( array( 'wc-ppcp-sdk-v6-blocks' ), $handles );
		$script = wp_scripts()->registered['wc-ppcp-sdk-v6-blocks'] ?? null;
		$this->assertNotNull( $script, 'The block script must be registered' );
		$this->assertSame( 'https://example.com/assets/checkout-block.js', $script->src );
		$this->assertSame( array( 'wp-data', 'wp-element', 'wp-i18n' ), $script->deps );
		$this->assertSame( 'deadbeef', $script->ver );
		$this->assertSame( 'woocommerce', $script->textdomain, 'The bundle has translatable strings of its own' );
	}

	/**
	 * @testdox Should return no handles and register nothing when there is no compiled bundle URL.
	 */
	public function test_get_payment_method_script_handles_returns_empty_array_when_no_asset_url(): void {
		$this->asset_getter->shouldReceive( 'get_asset_url' )->with( 'checkout-block.js' )->andReturn( '' );

		$handles = $this->create_sut()->get_payment_method_script_handles();

		$this->assertSame( array(), $handles );
		$this->assertArrayNotHasKey( 'wc-ppcp-sdk-v6-blocks', wp_scripts()->registered );
	}

	/**
	 * @testdox Should expose the gateway icon as a single entry shaped for the payment method icons.
	 */
	public function test_get_payment_method_data_exposes_icon_shaped_for_payment_method_icons(): void {
		$this->stub_gateway_for_payment_method_data();

		$data = $this->create_sut()->get_payment_method_data();

		$this->assertSame(
			array(
				array(
					'id'  => 'paypal',
					'alt' => 'PayPal',
					'src' => 'https://example.com/paypal-icon.png',
				),
			),
			$data['icon']
		);
	}

	/**
	 * @testdox Should leave out place_order_enabled when no provider was supplied.
	 */
	public function test_get_payment_method_data_omits_place_order_enabled_when_no_provider_supplied(): void {
		$this->stub_gateway_for_payment_method_data();

		$data = $this->create_sut()->get_payment_method_data();

		$this->assertArrayNotHasKey( 'place_order_enabled', $data );
	}

	/**
	 * @testdox Should reflect the provider's current answer on each call.
	 */
	public function test_get_payment_method_data_reflects_current_place_order_enabled_state_on_each_call(): void {
		$this->stub_gateway_for_payment_method_data();

		$cart_has_subscription = false;
		$place_order_enabled   = static function () use ( &$cart_has_subscription ): bool {
			return ! $cart_has_subscription;
		};
		$sut                   = $this->create_sut( $place_order_enabled );

		$first_call_data = $sut->get_payment_method_data();
		$this->assertTrue( $first_call_data['place_order_enabled'] );

		$cart_has_subscription = true;
		$second_call_data      = $sut->get_payment_method_data();
		$this->assertFalse( $second_call_data['place_order_enabled'] );
	}
}
