<?php
/**
 * Tests for the FraudNet script loading rules.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Assets
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Assets;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Assets\FraudNetAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\GatewayRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use ReflectionMethod;
use WC_Session;

/**
 * Where the `ppcp-fraudnet` script is loaded: on every page that shows a PayPal button, and on the checkout when a payment
 * gateway of the extension is enabled. The device data it sends is what PayPal ties to the client metadata ID that core
 * sends with every order.
 *
 * @group paypal-wallet
 */
class FraudNetAssetsTest extends WalletTestCase {

	/**
	 * The gateway repository mock.
	 *
	 * @var GatewayRepository&MockInterface
	 */
	private $gateway_repository;

	/**
	 * The page context mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The session WC() held before the test, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Create the mocks every case shares.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->gateway_repository = $this->mock( GatewayRepository::class );
		$this->context            = $this->mock( Context::class );
		$this->settings_provider  = $this->mock( SettingsProvider::class );
		$this->original_session   = WC()->session;
	}

	/**
	 * Restore the session and drop the script a case enqueued (the test case restores the hooks).
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
			wp_dequeue_script( 'ppcp-fraudnet' );
			wp_deregister_script( 'ppcp-fraudnet' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the assets object over the shared mocks.
	 *
	 * @param bool $fraudnet_enabled Whether FraudNet is enabled.
	 * @return FraudNetAssets
	 */
	private function make_sut( bool $fraudnet_enabled = true ): FraudNetAssets {
		$asset_getter = $this->mock( AssetGetter::class );
		$asset_getter->shouldReceive( 'get_asset_url' )->andReturn( 'https://example.com/fraudnet.js' );
		$environment = $this->mock( Environment::class );
		$environment->shouldReceive( 'is_sandbox' )->andReturn( true );

		return new FraudNetAssets(
			$asset_getter,
			'1.0.0',
			new FraudNet( 'MERCHANT_checkout-page' ),
			$environment,
			$this->settings_provider,
			$this->gateway_repository,
			$this->mock( SessionHandler::class ),
			$fraudnet_enabled,
			$this->context
		);
	}

	/**
	 * Whether the script would load for the given page context and enabled gateways.
	 *
	 * @param string   $context         The page context.
	 * @param string[] $enabled_gateways The enabled gateway IDs of the extension.
	 * @param string[] $button_locations The locations that show the PayPal buttons.
	 * @param bool     $fraudnet_enabled Whether FraudNet is enabled.
	 * @return bool
	 */
	private function should_load( string $context, array $enabled_gateways, array $button_locations, bool $fraudnet_enabled = true ): bool {
		$this->context->shouldReceive( 'context' )->andReturn( $context );
		$this->gateway_repository->shouldReceive( 'get_enabled_ppcp_gateway_ids' )->andReturn( $enabled_gateways );
		$this->settings_provider->shouldReceive( 'smart_button_locations' )->andReturn( $button_locations );

		$method = new ReflectionMethod( FraudNetAssets::class, 'should_load_fraudnet_script' );
		$method->setAccessible( true );

		return $method->invoke( $this->make_sut( $fraudnet_enabled ) );
	}

	/**
	 * @testdox Should not load the script when no gateway of the extension is enabled.
	 */
	public function test_no_enabled_gateway_loads_nothing(): void {
		$this->assertFalse( $this->should_load( 'checkout', array(), array( 'checkout' ) ) );
	}

	/**
	 * @testdox Should load the script on the checkout when only the PayPal gateway is enabled and the checkout shows the buttons.
	 */
	public function test_checkout_with_buttons_loads_the_script(): void {
		$this->assertTrue( $this->should_load( 'checkout', array( 'ppcp-gateway' ), array( 'checkout' ) ) );
	}

	/**
	 * @testdox Should not load the script on the checkout when the checkout shows no buttons.
	 */
	public function test_checkout_without_buttons_loads_nothing(): void {
		$this->assertFalse( $this->should_load( 'checkout', array( 'ppcp-gateway' ), array( 'cart' ) ) );
	}

	/**
	 * @testdox Should load the script on the checkout without any button location when another gateway of the extension is enabled besides the PayPal one.
	 */
	public function test_checkout_with_another_enabled_gateway_loads_the_script_without_buttons(): void {
		$this->assertTrue( $this->should_load( 'checkout', array( 'ppcp-gateway', 'ppcp-other-gateway' ), array() ) );
	}

	/**
	 * @testdox Should not load the script on the checkout when another gateway of the extension is enabled but FraudNet is disabled.
	 */
	public function test_checkout_with_another_enabled_gateway_loads_nothing_when_fraudnet_is_disabled(): void {
		$this->assertFalse( $this->should_load( 'checkout', array( 'ppcp-gateway', 'ppcp-other-gateway' ), array(), false ) );
	}

	/**
	 * @testdox Should load the script on a product page when the product page or the mini cart shows the buttons.
	 */
	public function test_product_page_follows_the_product_and_mini_cart_locations(): void {
		$this->assertTrue( $this->should_load( 'product', array( 'ppcp-gateway' ), array( 'mini-cart' ) ) );
	}

	/**
	 * @testdox Should not load the script when FraudNet is disabled.
	 */
	public function test_disabled_fraudnet_loads_nothing(): void {
		$this->assertFalse( $this->should_load( 'checkout', array( 'ppcp-gateway' ), array( 'checkout' ), false ) );
	}

	/**
	 * @testdox Should enqueue the ppcp-fraudnet script with its configuration on wp_enqueue_scripts when the rules say so.
	 */
	public function test_register_assets_enqueues_the_script_with_its_config(): void {
		$session_data = new \ArrayObject();
		$session      = $this->mock( WC_Session::class );
		$session->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $key ) use ( $session_data ) {
				return $session_data[ $key ] ?? null;
			}
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			static function ( string $key, $value ) use ( $session_data ): void {
				$session_data[ $key ] = $value;
			}
		);
		WC()->session = $session;
		$this->context->shouldReceive( 'context' )->andReturn( 'checkout' );
		$this->gateway_repository->shouldReceive( 'get_enabled_ppcp_gateway_ids' )->andReturn( array( 'ppcp-gateway' ) );
		$this->settings_provider->shouldReceive( 'smart_button_locations' )->andReturn( array( 'checkout' ) );
		remove_all_actions( 'wp_enqueue_scripts' );

		$this->make_sut()->register_assets();
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'ppcp-fraudnet', 'enqueued' ), 'The script should be enqueued' );
		$data = (string) wp_scripts()->get_data( 'ppcp-fraudnet', 'data' );
		$this->assertStringContainsString( 'FraudNetConfig', $data );
		$this->assertStringContainsString( 'MERCHANT_checkout-page', $data );
		$this->assertStringContainsString( (string) $session_data['ppcp_fraudnet_session_id'], $data );
	}
}
