<?php
/**
 * Tests for the add-payment-method manager.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreateSetupToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\AddPaymentMethodManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\ClientTokenEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use ReflectionClass;

/**
 * When the add-payment-method surfaces load and what their bootstrap script receives.
 *
 * @group paypal-wallet
 */
class AddPaymentMethodManagerTest extends WalletTestCase {

	/**
	 * The asset getter mock.
	 *
	 * @var AssetGetter&MockInterface
	 */
	private $asset_getter;

	/**
	 * The environment mock.
	 *
	 * @var Environment&MockInterface
	 */
	private $environment;

	/**
	 * The button context mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * Build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->asset_getter = $this->mock( AssetGetter::class );
		$this->environment  = $this->mock( Environment::class );
		$this->context      = $this->mock( Context::class );
	}

	/**
	 * Deregister what enqueue() registered.
	 */
	public function tearDown(): void {
		try {
			wp_deregister_script( 'wc-ppcp-sdk-v6-add-payment-method' );
			wp_dequeue_script( 'wc-ppcp-sdk-v6-add-payment-method' );
			wp_deregister_style( 'wc-ppcp-sdk-v6-add-payment-method' );
			wp_dequeue_style( 'wc-ppcp-sdk-v6-add-payment-method' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the manager.
	 *
	 * @param bool $paypal_vaulting_enabled Whether PayPal vaulting is on.
	 * @return AddPaymentMethodManager
	 */
	private function create_sut( bool $paypal_vaulting_enabled = false ): AddPaymentMethodManager {
		return new AddPaymentMethodManager(
			$this->asset_getter,
			'1.0.0',
			$this->environment,
			$this->context,
			$paypal_vaulting_enabled
		);
	}

	/**
	 * @testdox Should load only for a logged-in buyer with PayPal vaulting on the add-payment-method page: $scenario.
	 * @dataProvider should_load_provider
	 *
	 * @param string $scenario                   What the row covers.
	 * @param bool   $is_logged_in               Whether the buyer is logged in.
	 * @param bool   $paypal_vaulting_enabled    Whether PayPal vaulting is on.
	 * @param bool   $is_add_payment_method_page Whether it is the add-payment-method page.
	 * @param bool   $expected                   Whether the surfaces load.
	 */
	public function test_should_load_on_current_page(
		string $scenario,
		bool $is_logged_in,
		bool $paypal_vaulting_enabled,
		bool $is_add_payment_method_page,
		bool $expected
	): void {
		unset( $scenario );
		if ( $is_logged_in ) {
			wp_set_current_user( self::factory()->user->create() );
		}
		$this->context->shouldReceive( 'is_add_payment_method_page' )->andReturn( $is_add_payment_method_page );

		$this->assertSame( $expected, $this->create_sut( $paypal_vaulting_enabled )->should_load_on_current_page() );
	}

	/**
	 * Login, vaulting and page combinations.
	 *
	 * @return array
	 */
	public function should_load_provider(): array {
		return array(
			'logged out never loads'                       => array( 'logged out', false, true, true, false ),
			'logged in but no vaulting enabled does not load' => array( 'no vaulting', true, false, true, false ),
			'paypal vaulting but wrong page does not load' => array( 'wrong page', true, true, false, false ),
			'paypal vaulting on the page loads'            => array( 'paypal only', true, true, true, true ),
		);
	}

	/**
	 * @testdox Should build the bootstrap data with the button, the endpoints and no card keys.
	 */
	public function test_script_data(): void {
		$this->environment->shouldReceive( 'is_sandbox' )->andReturn( false );

		$data = $this->invoke_script_data( $this->create_sut( true ) );

		$this->assertSame( '#' . AddPaymentMethodManager::WRAPPER_ID, $data['button']['wrapper'] );
		$this->assertSame( 'paypal-gold', $data['button']['color_class'] );

		$this->assertSame( \WC_AJAX::get_endpoint( ClientTokenEndpoint::ENDPOINT ), $data['ajax']['client_token']['endpoint'] );
		$this->assertSame( wp_create_nonce( ClientTokenEndpoint::nonce() ), $data['ajax']['client_token']['nonce'] );
		$this->assertSame( \WC_AJAX::get_endpoint( CreateSetupToken::ENDPOINT ), $data['ajax']['create_setup_token']['endpoint'] );
		$this->assertSame( wp_create_nonce( CreateSetupToken::nonce() ), $data['ajax']['create_setup_token']['nonce'] );
		$this->assertSame( \WC_AJAX::get_endpoint( CreatePaymentToken::ENDPOINT ), $data['ajax']['create_payment_token']['endpoint'] );
		$this->assertSame( wp_create_nonce( CreatePaymentToken::nonce() ), $data['ajax']['create_payment_token']['nonce'] );

		$this->assertArrayNotHasKey( 'card_fields', $data, 'The card fields data is gone with the card gateway' );
		$this->assertArrayNotHasKey( 'verification_method', $data, 'The card setup token needs no verification method' );
	}

	/**
	 * Read the private script_data() the way enqueue() does.
	 *
	 * @param AddPaymentMethodManager $sut The manager.
	 * @return array
	 */
	private function invoke_script_data( AddPaymentMethodManager $sut ): array {
		$method = ( new ReflectionClass( $sut ) )->getMethod( 'script_data' );
		$method->setAccessible( true );

		return $method->invoke( $sut );
	}

	/**
	 * @testdox Should register the bootstrap script with the asset data dependencies and version.
	 */
	public function test_enqueue_registers_script_with_asset_data_dependencies_and_version(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->context->shouldReceive( 'is_add_payment_method_page' )->andReturn( true );
		$this->environment->shouldReceive( 'is_sandbox' )->andReturn( false );
		$this->asset_getter->shouldReceive( 'get_asset_url' )
			->with( 'boot-add-payment-method.js' )
			->andReturn( 'https://example.com/assets/boot-add-payment-method.js' );
		$this->asset_getter->shouldReceive( 'get_asset_data' )
			->with( 'boot-add-payment-method.js', '1.0.0' )
			->andReturn(
				array(
					'dependencies' => array( 'wp-data' ),
					'version'      => 'deadbeef',
				)
			);

		$this->create_sut( true )->enqueue();

		$script = wp_scripts()->registered['wc-ppcp-sdk-v6-add-payment-method'] ?? null;
		$this->assertNotNull( $script, 'The bootstrap script must be registered' );
		$this->assertSame( 'https://example.com/assets/boot-add-payment-method.js', $script->src );
		$this->assertSame( array( 'wp-data' ), $script->deps );
		$this->assertSame( 'deadbeef', $script->ver );
		$this->assertTrue( wp_script_is( 'wc-ppcp-sdk-v6-add-payment-method', 'enqueued' ) );
		$this->assertStringContainsString( 'wc_ppcp_sdk_v6_save', (string) wp_scripts()->get_data( 'wc-ppcp-sdk-v6-add-payment-method', 'data' ) );
	}

	/**
	 * @testdox Should register nothing when the surfaces do not load on the page.
	 */
	public function test_enqueue_does_nothing_when_should_not_load_on_current_page(): void {
		$this->create_sut( true )->enqueue();

		$this->assertArrayNotHasKey( 'wc-ppcp-sdk-v6-add-payment-method', wp_scripts()->registered );
	}
}
