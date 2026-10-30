<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Blocks\Payments\Integrations;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api;
use Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry as WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use WC_Unit_Test_Case;

/**
 * Tests for the Blocks WooPayments integration.
 */
class WooPaymentsTest extends WC_Unit_Test_Case {

	/**
	 * Temporary theme root a test wrote, or an empty string.
	 *
	 * @var string
	 */
	private $temp_theme_root = '';

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->delete_temp_theme_root();
		remove_all_filters( 'wcpay_upe_available_payment_methods' );

		foreach ( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_deregister_script( 'stripe' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
		wp_dequeue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		wp_deregister_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		$this->reset_container_replacements();
		wp_reset_postdata();
		$this->reset_cart_checkout_page_cache();
		parent::tearDown();
	}

	/**
	 * Registers a script the mocked asset API was asked for, so the integration sees it as registered.
	 *
	 * @param string $handle Script handle.
	 */
	public function register_script_for_real( string $handle ): void {
		wp_register_script( $handle, false, array(), '1.0', true );
	}

	/**
	 * @testdox Should require checkout readiness before the Blocks payment method activates.
	 */
	public function test_is_active_requires_checkout_readiness(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( false );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require provider readiness before the Blocks payment method activates.
	 */
	public function test_is_active_requires_provider_readiness(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( false );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require native runtime ownership before the Blocks payment method activates.
	 */
	public function test_is_active_requires_native_runtime_ownership(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter( false ), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require method-level gateway availability before a split Blocks method activates.
	 */
	public function test_is_active_requires_split_gateway_availability(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$gateway     = $this->getMockBuilder( WooPaymentsGateway::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$gateway->id = 'woocommerce_payments_klarna';
		$gateway->expects( $this->once() )->method( 'is_available' )->willReturn( false );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $gateway );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should register a core-owned Blocks asset handle for WooPayments.
	 */
	public function test_get_payment_method_script_handles_registers_core_owned_woopayments_blocks_script(): void {
		wp_deregister_script( 'stripe' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api
			->expects( $this->exactly( 4 ) )
			->method( 'register_script' )
			->willReturnCallback( array( $this, 'register_script_for_real' ) )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments-fraud-scripts',
					'assets/client/blocks/wc-payment-method-woopayments-fraud-scripts.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-common',
					'assets/client/blocks/wc-payment-method-woopayments-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-woopay-common',
					'assets/client/blocks/wc-payment-method-woopayments-woopay-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ),
				)
			);
		$asset_api
			->expects( $this->once() )
			->method( 'register_style' )
			->with(
				'wc-payment-method-woopayments',
				'assets/client/blocks/wc-payment-method-woopayments.css',
				array(),
				'all',
				true
			);

		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );

		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integration->get_payment_method_script_handles() );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertSame( 'https://js.stripe.com/v3/', wp_scripts()->registered['stripe']->src );
		// The card script loads FingerprintJS as an external from the vendored handle.
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
		$this->assertSame( WC()->plugin_url() . '/assets/js/fingerprintjs/fp.umd.min.js', wp_scripts()->registered['wc-woopayments-fingerprintjs']->src );
	}

	/**
	 * @testdox Should register WooPay Blocks assets only when the WooPay button is available.
	 */
	public function test_get_payment_method_script_handles_registers_woopay_assets_only_when_button_is_available(): void {
		wp_deregister_script( 'stripe' );
		$this->set_current_post_to_checkout_block();

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api
			->expects( $this->exactly( 5 ) )
			->method( 'register_script' )
			->willReturnCallback( array( $this, 'register_script_for_real' ) )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments-fraud-scripts',
					'assets/client/blocks/wc-payment-method-woopayments-fraud-scripts.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-common',
					'assets/client/blocks/wc-payment-method-woopayments-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-woopay-common',
					'assets/client/blocks/wc-payment-method-woopayments-woopay-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ),
				),
				array(
					'wc-payment-method-woopayments-woopay',
					'assets/client/blocks/wc-payment-method-woopayments-woopay.js',
					array( 'wc-blocks-checkout', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ),
				)
			);
		$asset_api
			->expects( $this->exactly( 2 ) )
			->method( 'register_style' )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.css',
					array(),
					'all',
					true,
				),
				array(
					'wc-payment-method-woopayments-woopay',
					'assets/client/blocks/wc-payment-method-woopayments-woopay.css',
					array(),
					'all',
					true,
				)
			);

		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$woopay_session_service = $this->create_woopay_eligibility( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $woopay_session_service, $this->create_express_checkout_service() );

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-fraud-scripts' ),
			$integration->get_payment_method_script_handles()
		);
	}

	/**
	 * @testdox Should register payment-request Blocks assets only when the ECE button is available.
	 */
	public function test_get_payment_method_script_handles_registers_payment_request_assets_only_when_button_is_available(): void {
		wp_deregister_script( 'stripe' );
		$this->set_current_post_to_checkout_block();

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api
			->expects( $this->exactly( 5 ) )
			->method( 'register_script' )
			->willReturnCallback( array( $this, 'register_script_for_real' ) )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments-fraud-scripts',
					'assets/client/blocks/wc-payment-method-woopayments-fraud-scripts.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-common',
					'assets/client/blocks/wc-payment-method-woopayments-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments-woopay-common',
					'assets/client/blocks/wc-payment-method-woopayments-woopay-common.js',
					array(),
					false,
				),
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ),
				),
				array(
					'wc-payment-method-woopayments-express-checkout',
					'assets/client/blocks/wc-payment-method-woopayments-express-checkout.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common' ),
				)
			);
		$asset_api
			->expects( $this->exactly( 2 ) )
			->method( 'register_style' )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.css',
					array(),
					'all',
					true,
				),
				array(
					'wc-payment-method-woopayments-express-checkout',
					'assets/client/blocks/wc-payment-method-woopayments-express-checkout.css',
					array(),
					'all',
					true,
				)
			);

		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$express_checkout_service = $this->create_express_checkout_service( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $express_checkout_service );

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ),
			$integration->get_payment_method_script_handles()
		);
	}

	/**
	 * @testdox Should not enqueue Blocks WooPayments styles or scripts on the classic shortcode checkout page.
	 *
	 * Blocks asks for the payment method script handles on every page (Payments\Api::verify_payment_methods_dependencies()),
	 * but no Cart or Checkout block renders here, so nothing loads. Client 11.2.0 loads its Blocks styles on this page.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_payment_method_script_handles_does_not_enqueue_blocks_styles_on_classic_checkout_shortcode_pages(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( is_checkout(), 'Precondition: the classic checkout page.' );

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api->method( 'register_style' )->willReturnCallback(
			function ( string $handle ): void {
				wp_register_style( $handle, false, array(), 'test' );
			}
		);
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ) );
		$integration->initialize();

		foreach ( $integration->get_payment_method_script_handles() as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), $handle . ' is not enqueued.' );
		}
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue Blocks WooPayments styles on checkout block pages once the Checkout block renders.
	 */
	public function test_get_payment_method_script_handles_enqueues_blocks_styles_on_checkout_block_pages(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api->method( 'register_style' )->willReturnCallback(
			function ( string $handle ): void {
				wp_register_style( $handle, false, array(), 'test' );
			}
		);
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ) );
		$integration->initialize();

		$handles = $integration->get_payment_method_script_handles();

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ),
			$handles
		);
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ), 'The styles wait for the block render.' );

		do_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after' );

		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should load the full Blocks payment stack on admin requests, where the block editor previews the cart and checkout.
	 */
	public function test_get_payment_method_script_handles_loads_the_full_stack_in_admin(): void {
		set_current_screen( 'edit-page' );

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api->method( 'register_style' )->willReturnCallback(
			function ( string $handle ): void {
				wp_register_style( $handle, false, array(), 'test' );
			}
		);
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ) );
		$handles     = $integration->get_payment_method_script_handles();
		set_current_screen( 'front' );

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ),
			$handles
		);
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * @testdox Should keep the card stack and Stripe.js off the Blocks cart when no express or WooPay button renders there, and keep the fraud scripts.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_cart_without_buttons_depends_only_on_the_fraud_scripts(): void {
		$this->go_to_block_page( 'woocommerce/cart', 'woocommerce_cart_page_id' );

		$dependencies = $this->get_cart_block_frontend_dependencies( $this->create_registered_integration() );

		$this->assertSame( array( 'wc-payment-method-woopayments-fraud-scripts' ), $dependencies );
		$this->assertTrue( wp_script_is( 'wc-payment-method-woopayments-fraud-scripts', 'registered' ) );
		$loaded = $this->get_dependency_closure( $dependencies );
		$this->assertNotContains( 'wc-payment-method-woopayments', $loaded );
		$this->assertNotContains( 'stripe', $loaded );
		$this->assertNotContains( 'wc-woopayments-fingerprintjs', $loaded );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * @testdox Should load the express checkout script and Stripe.js on the Blocks cart without the card stack.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_cart_with_express_checkout_loads_what_express_needs(): void {
		$this->go_to_block_page( 'woocommerce/cart', 'woocommerce_cart_page_id' );

		$dependencies = $this->render_block_assets( $this->create_registered_integration( false, true ), 'woocommerce/cart' );

		$this->assertSame( array( 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ), $dependencies );
		$loaded = $this->get_dependency_closure( $dependencies );
		$this->assertContains( 'stripe', $loaded );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		// A webpack entry runs only once its shared chunks have loaded; the cart skips the WooPay email check chunk.
		$this->assertContains( 'wc-payment-method-woopayments-common', $loaded );
		$this->assertNotContains( 'wc-payment-method-woopayments-woopay-common', $loaded );
		$this->assertNotContains( 'wc-payment-method-woopayments', $loaded );
		$this->assertNotContains( 'wc-woopayments-fingerprintjs', $loaded );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * @testdox Should load the WooPay button script on the Blocks cart without the card stack or Stripe.js.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_cart_with_woopay_loads_what_woopay_needs(): void {
		$this->go_to_block_page( 'woocommerce/cart', 'woocommerce_cart_page_id' );

		$dependencies = $this->render_block_assets( $this->create_registered_integration( true, false ), 'woocommerce/cart' );

		$this->assertSame( array( 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-fraud-scripts' ), $dependencies );
		$loaded = $this->get_dependency_closure( $dependencies );
		$this->assertContains( 'wc-payment-method-woopayments-common', $loaded );
		$this->assertContains( 'wc-payment-method-woopayments-woopay-common', $loaded );
		$this->assertNotContains( 'wc-payment-method-woopayments', $loaded );
		$this->assertNotContains( 'stripe', $loaded );
		$this->assertNotContains( 'wc-woopayments-fingerprintjs', $loaded );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * @testdox Should keep the card stack, Stripe.js, FingerprintJS and the fraud scripts on the Blocks checkout.
	 */
	public function test_blocks_checkout_keeps_the_card_stack(): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );

		$dependencies = $this->render_block_assets( $this->create_registered_integration(), 'woocommerce/checkout' );

		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $dependencies );
		$loaded = $this->get_dependency_closure( $dependencies );
		$this->assertContains( 'stripe', $loaded );
		$this->assertContains( 'wc-payment-method-woopayments-common', $loaded );
		$this->assertContains( 'wc-payment-method-woopayments-woopay-common', $loaded );
		// The card script's built asset file lists FingerprintJS (a build external); the integration's part is registering its handle.
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * The cart and checkout pages, each with its block in the page content, from a template saved in the database, and
	 * from the active theme's template file.
	 *
	 * @return array<string,array{0:string,1:string,2:string[]}>
	 */
	public function provide_cart_and_checkout_pages_by_block_source(): array {
		$checkout_handles = array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' );
		$cart_handles     = array( 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' );

		return array(
			'checkout, block in the page content' => array( 'woocommerce/checkout', 'content', $checkout_handles ),
			'checkout, page-checkout DB template' => array( 'woocommerce/checkout', 'db_template', $checkout_handles ),
			'checkout, theme page-checkout file'  => array( 'woocommerce/checkout', 'theme_template', $checkout_handles ),
			'cart, block in the page content'     => array( 'woocommerce/cart', 'content', $cart_handles ),
			'cart, page-cart DB template'         => array( 'woocommerce/cart', 'db_template', $cart_handles ),
			'cart, theme page-cart file'          => array( 'woocommerce/cart', 'theme_template', $cart_handles ),
		);
	}

	/**
	 * @testdox Should register the WooPay and express methods, and load the Blocks styles when the block renders, on the cart and checkout pages however the block renders: $_dataName.
	 * @dataProvider provide_cart_and_checkout_pages_by_block_source
	 *
	 * A separate process, because the test switches the theme, and an earlier test can define WOOCOMMERCE_CART or
	 * WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string   $block_name       Block the page renders.
	 * @param string   $source           Where the block comes from: `content`, `db_template` or `theme_template`.
	 * @param string[] $expected_handles Expected payment method dependencies of the block's frontend script.
	 */
	public function test_cart_and_checkout_pages_register_express_methods_however_the_block_renders( string $block_name, string $source, array $expected_handles ): void {
		$this->go_to_page_rendered_by( $block_name, $source );
		$this->assertSame( 'content' === $source, has_block( $block_name ), 'Precondition: only the content row holds the block in the page.' );
		$this->assertSame( 'db_template' === $source, CartCheckoutUtils::is_overridden_by_custom_template_content( $block_name ), 'Precondition: only the DB row has a template the page check reads.' );
		if ( 'theme_template' === $source ) {
			$this->assertTrue( wp_is_block_theme(), 'Precondition: a block theme.' );
			$template = get_block_template( get_stylesheet() . '//page-' . str_replace( 'woocommerce/', '', $block_name ) );
			$this->assertSame( 'theme', $template->source ?? null, 'Precondition: the template comes from the theme file.' );
			$this->assertTrue( has_block( $block_name, $template->content ), 'Precondition: the theme template renders the block.' );
		}
		$integration = $this->create_registered_integration( true, true );
		$integration->initialize();

		$api          = new Api( $this->create_payment_method_registry( $integration ), $this->createMock( AssetDataRegistry::class ) );
		$dependencies = $api->add_payment_method_script_dependencies( array(), 'wc-' . str_replace( 'woocommerce/', '', $block_name ) . '-block-frontend' );

		$this->assertSame( $expected_handles, $dependencies );
		$styles = array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout' );
		foreach ( $styles as $style ) {
			$this->assertFalse( wp_style_is( $style, 'enqueued' ), $style . ' waits for the block render.' );
		}

		do_action( 'woocommerce_blocks_enqueue_' . str_replace( 'woocommerce/', '', $block_name ) . '_block_scripts_after' );

		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
		$this->assertSame( 'woocommerce/checkout' === $block_name, wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ), 'The card style loads on the checkout only.' );
	}

	/**
	 * @testdox Should expose the fraud-prevention token on the cart and checkout pages, and any Cart block page, only while card-testing protection is on.
	 *
	 * The Blocks express script reads the token only from window.wcpayFraudPreventionToken. A paragraph-only cart or checkout
	 * page stands for one whose block comes from a custom page template.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testWith ["woocommerce/cart", "woocommerce_cart_page_id", true, true]
	 *           ["woocommerce/checkout", "woocommerce_checkout_page_id", true, true]
	 *           ["woocommerce/cart", "woocommerce_cart_page_id", false, false]
	 *           ["woocommerce/checkout", "woocommerce_checkout_page_id", false, false]
	 *           ["core/paragraph", "woocommerce_myaccount_page_id", true, false]
	 *           ["core/paragraph", "woocommerce_checkout_page_id", true, true]
	 *           ["core/paragraph", "woocommerce_checkout_page_id", false, false]
	 *           ["core/paragraph", "woocommerce_cart_page_id", true, true]
	 *           ["core/paragraph", "woocommerce_cart_page_id", false, false]
	 *           ["woocommerce/cart", "woocommerce_myaccount_page_id", true, true]
	 *           ["woocommerce/cart", "woocommerce_myaccount_page_id", false, false]
	 *
	 * @param string $block_name    Block the page holds.
	 * @param string $page_option   WooCommerce page option that points at the page.
	 * @param bool   $protection_on Whether the account has card-testing protection on.
	 * @param bool   $expected      Whether the token script should be enqueued.
	 */
	public function test_blocks_pages_expose_the_fraud_prevention_token_while_protection_is_on( string $block_name, string $page_option, bool $protection_on, bool $expected ): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'card_testing_protection_eligible' => $protection_on ) );
		$session = new \WC_Session_Handler();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$fraud_prevention_service = new WooPaymentsFraudPreventionService( $session );
		$fraud_prevention_service->init( $account_service );
		wc_get_container()->replace( WooPaymentsFraudPreventionService::class, $fraud_prevention_service );
		$this->go_to_block_page( $block_name, $page_option );

		$this->get_cart_block_frontend_dependencies( $this->create_registered_integration( false, true ) );

		$this->assertSame( $expected, wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		if ( $expected ) {
			$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
			$this->assertStringContainsString( "window.wcpayFraudPreventionToken = 'known-token';", implode( '', array_filter( (array) $inline, 'is_string' ) ) );
		}
	}

	/**
	 * @testdox Should keep the card stack on the cart page when its content also holds the Checkout block.
	 */
	public function test_cart_page_with_the_checkout_block_keeps_the_card_stack(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart --><!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		update_option( 'woocommerce_cart_page_id', $page_id );
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );

		$api          = new Api( $this->create_payment_method_registry( $this->create_registered_integration() ), $this->createMock( AssetDataRegistry::class ) );
		$dependencies = $api->add_payment_method_script_dependencies( array(), 'wc-checkout-block-frontend' );

		$this->assertContains( 'wc-payment-method-woopayments', $dependencies );
		$loaded = $this->get_dependency_closure( $dependencies );
		$this->assertContains( 'stripe', $loaded );
		// The card script's built asset file lists FingerprintJS (a build external); the integration's part is registering its handle.
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
	}

	/**
	 * Blocks pages and the button context client 11.1.0 get_button_context() gives them: a page holding the Cart block is
	 * the cart, before the checkout (class-wc-payments-express-checkout-button-helper.php:275-277, :450-468).
	 *
	 * A paragraph-only cart or checkout page stands for one whose block comes from a page template. The page content is read
	 * raw, so a password-protected page and a page split by `<!--nextpage-->` still count by the block they hold. A page that
	 * is a cart and not a checkout gets no card script, so a protected or split page holding both blocks keeps it only while
	 * its Checkout block still counts.
	 *
	 * @return array<string,array{0:string,1:string|null,2:string,3:bool,4?:string}>
	 */
	public function provide_blocks_pages_and_button_contexts(): array {
		$cart      = '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->';
		$checkout  = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->';
		$paragraph = '<!-- wp:paragraph --><p>Page</p><!-- /wp:paragraph -->';

		return array(
			'Cart block on the cart page'           => array( $cart, 'woocommerce_cart_page_id', 'cart', false ),
			'Cart block on another page'            => array( $cart, null, 'cart', false ),
			'Cart and Checkout blocks on a page'    => array( $cart . $checkout, null, 'cart', true ),
			'Checkout block on the checkout page'   => array( $checkout, 'woocommerce_checkout_page_id', 'checkout', true ),
			'Cart page from a page template'        => array( $paragraph, 'woocommerce_cart_page_id', 'cart', false ),
			'Checkout page from a page template'    => array( $paragraph, 'woocommerce_checkout_page_id', 'checkout', true ),
			'Password page with the Cart block'     => array( $cart, null, 'cart', false, 'secret' ),
			'Cart block after a page break'         => array( $paragraph . '<!--nextpage-->' . $cart, null, 'cart', false ),
			'Checkout block after a page break'     => array( $paragraph . '<!--nextpage-->' . $checkout, null, 'checkout', true ),
			'Password page with the Checkout block' => array( $checkout, null, 'checkout', true, 'secret' ),
			'Password page with both blocks'        => array( $cart . $checkout, null, 'cart', true, 'secret' ),
			'Checkout block after a Cart block and a page break' => array( $cart . '<!--nextpage-->' . $checkout, null, 'cart', true ),
		);
	}

	/**
	 * @testdox Should ask for the WooPay and express buttons of the page's location, and build the Blocks data for it, on any cart or checkout page and any page holding the block: $_dataName.
	 * @dataProvider provide_blocks_pages_and_button_contexts
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string      $content          Page content.
	 * @param string|null $page_option      WooCommerce page option pointing at the page, or null for none.
	 * @param string      $expected_context Expected button context.
	 * @param bool        $expected_card    Whether the card script is listed.
	 * @param string      $password         Page password, or an empty string for none.
	 */
	public function test_button_context_counts_any_cart_block_page_as_the_cart( string $content, ?string $page_option, string $expected_context, bool $expected_card, string $password = '' ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_content'  => $content,
				'post_password' => $password,
			)
		);
		if ( null !== $page_option ) {
			update_option( $page_option, $page_id );
		}
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
		$this->assertSame( '' !== $password, post_password_required(), 'Precondition: the password form renders instead of the content.' );

		$contexts = array();
		$record   = static function ( string $context ) use ( &$contexts ): bool {
			$contexts[] = $context;
			return true;
		};
		$express  = $this->getMockBuilder( WooPaymentsExpressCheckoutService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_payment_request_button', 'get_express_checkout_params' ) )
			->getMock();
		$express->method( 'should_show_payment_request_button' )->willReturnCallback( $record );
		$express->method( 'get_express_checkout_params' )->willReturnCallback(
			static function ( string $context ) use ( &$contexts ): array {
				$contexts[] = $context;
				return array( 'button_context' => $context );
			}
		);
		$woopay = $this->getMockBuilder( Eligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_woopay_button' ) )
			->getMock();
		$woopay->method( 'should_show_woopay_button' )->willReturnCallback( $record );
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$data_contexts = array();
		$assets->method( 'get_blocks_payment_method_data' )->willReturnCallback(
			static function ( ...$args ) use ( &$data_contexts ): array {
				$data_contexts[] = $args[2] ?? 'none';
				return array();
			}
		);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array() );
		$integration = new WooPayments( $this->createMock( AssetApi::class ), $this->create_runtime_arbiter(), $assets, $provider, $woopay, $express );

		$handles = $integration->get_payment_method_script_handles();
		$data    = $integration->get_payment_method_data();

		$this->assertContains( 'wc-payment-method-woopayments-woopay', $handles );
		$this->assertContains( 'wc-payment-method-woopayments-express-checkout', $handles );
		$this->assertSame( $expected_card, in_array( 'wc-payment-method-woopayments', $handles, true ), 'A cart that is not a checkout gets no card script.' );
		$this->assertSame( array( $expected_context ), array_values( array_unique( $contexts ) ) );
		$this->assertSame( $expected_context, $data['expressCheckoutParams']['button_context'] ?? null );
		$this->assertSame( array( $expected_context ), $data_contexts, 'The Blocks data reads the WooPay button location of the page.' );
	}

	/**
	 * @testdox Should source Blocks payment method data from the checkout assets class.
	 */
	public function test_get_payment_method_data_uses_checkout_assets_config(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$assets
			->expects( $this->once() )
			->method( 'get_blocks_payment_method_data' )
			->willReturn(
				array(
					'title' => 'WooPayments',
				)
			);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array() );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );

		$this->assertSame(
			array(
				'title'     => 'WooPayments',
				'gatewayId' => 'woocommerce_payments',
			),
			$integration->get_payment_method_data()
		);
	}

	/**
	 * @testdox Should publish one Blocks integration instance for each native WooPayments gateway.
	 */
	public function test_get_payment_method_integrations_publishes_one_instance_per_native_gateway(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		$card_gateway            = new WooPaymentsGateway( $payment_method_registry->get( 'card' ) );
		$klarna_gateway          = new WooPaymentsGateway( $payment_method_registry->get( 'klarna' ) );
		$alipay_gateway          = new WooPaymentsGateway( $payment_method_registry->get( 'alipay' ) );
		$provider                = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array( $card_gateway, $klarna_gateway, $alipay_gateway ) );

		$integration  = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );
		$integrations = $integration->get_payment_method_integrations();

		$this->assertSame(
			array(
				'woocommerce_payments',
				'woocommerce_payments_klarna',
				'woocommerce_payments_alipay',
			),
			array_map(
				static fn( WooPayments $payment_method ): string => $payment_method->get_name(),
				$integrations
			)
		);
		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integrations[0]->get_payment_method_script_handles() );
		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integrations[1]->get_payment_method_script_handles() );
		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integrations[2]->get_payment_method_script_handles() );
	}

	/**
	 * @testdox Split-gateway Blocks integrations get byte-identical shared config data, built once per request, and each gateway's config passes the filter.
	 *
	 * Client 11.1.0 registers one Blocks method and builds its payment fields config once; the split integrations share one
	 * build of the gateway-independent part.
	 */
	public function test_split_gateway_integrations_get_one_shared_config_build(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, string $url ) {
				unset( $parsed_args );
				return false !== strpos( $url, 'public-api.wordpress.com' ) ? new \WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' ) : $preempt;
			},
			10,
			3
		);
		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_publishable_key', 'get_account_id', 'get_cached_account_data', 'get_gateway_setting', 'can_process_payments', 'is_test_mode_enabled' ) )
			->getMock();
		$account->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account->method( 'get_account_id' )->willReturn( 'acct_123' );
		$account->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account->method( 'get_gateway_setting' )->willReturnCallback( static fn( string $key, $fallback = null ) => $fallback );
		$account->method( 'can_process_payments' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$styles = $this->getMockBuilder( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService::class )
			->onlyMethods( array( 'get_or_create_styles_cache_version' ) )
			->getMock();
		$styles->method( 'get_or_create_styles_cache_version' )->willReturn( 'styles-v1' );
		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$account,
			$this->createMock( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService::class ),
			$this->createMock( Eligibility::class ),
			$styles,
			$this->createMock( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController::class ),
			new WooPaymentsPaymentMethodRegistry()
		);
		$registry = new WooPaymentsPaymentMethodRegistry();
		$gateways = array( new WooPaymentsGateway( $registry->get( 'card' ) ), new WooPaymentsGateway( $registry->get( 'klarna' ) ), new WooPaymentsGateway( $registry->get( 'affirm' ) ) );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( $gateways );
		$provider->method( 'get_gateway_for_method' )->willReturn( $gateways[0] );
		$builds   = 0;
		$filtered = 0;
		// The config base applies this filter once per build.
		add_filter(
			'wc_payments_account_id_for_intent_confirmation',
			static function ( $value ) use ( &$builds ) {
				++$builds;
				return $value;
			}
		);
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( $config ) use ( &$filtered ) {
				++$filtered;
				return $config;
			}
		);

		$integration  = new WooPayments( $this->createMock( AssetApi::class ), $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );
		$gateway_keys = array_flip( array( 'gatewayId', 'paymentMethodsConfig', 'paymentMethodTypes', 'title', 'description', 'expressCheckoutParams' ) );
		$shared       = array();
		foreach ( $integration->get_payment_method_integrations() as $payment_method ) {
			$shared[ $payment_method->get_name() ] = (string) wp_json_encode( array_diff_key( $payment_method->get_payment_method_data(), $gateway_keys ) );
		}

		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), array_keys( $shared ) );
		$this->assertStringContainsString( '"publishableKey":"pk_test_123"', $shared['woocommerce_payments'] );
		$this->assertSame( $shared['woocommerce_payments'], $shared['woocommerce_payments_klarna'], 'Klarna gets the card integration\'s shared config data.' );
		$this->assertSame( $shared['woocommerce_payments'], $shared['woocommerce_payments_affirm'], 'Affirm gets the card integration\'s shared config data.' );
		$this->assertSame( 1, $builds, 'The shared config is built once for the three integrations.' );
		$this->assertSame( 3, $filtered, 'Each gateway config passes the wcpay_payment_fields_js_config filter.' );
	}

	/**
	 * Client 11.1.0 builds a gateway for every registered definition (`includes/class-wc-payments.php:616-645`); the
	 * availability filter only shapes the settings list (`includes/class-wc-payment-gateway-wcpay.php:4848-4879`).
	 *
	 * @testdox Should register the Blocks integration of a payment method the availability filter removes.
	 */
	public function test_get_payment_method_integrations_ignores_the_availability_filter(): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn( array $payment_method_ids ): array => array_values( array_diff( $payment_method_ids, array( 'sepa_debit' ) ) )
		);
		$payment_operations = $this->getMockBuilder( WooPaymentsPaymentOperationService::class )
			->disableOriginalConstructor()
			->getMock();
		$api_client         = $this->getMockBuilder( Client::class )
			->disableOriginalConstructor()
			->getMock();
		$account_service    = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->getMock();
		$provider           = new WooPaymentsProvider();
		$provider->init( $payment_operations, $api_client, $account_service, new WooPaymentsPaymentMethodRegistry(), wc_get_container()->get( WooPaymentsOrderEffectApplier::class ) );
		$asset_api       = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets          = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->getMock();
		$integration     = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service() );
		$integration_ids = array_map(
			static fn( WooPayments $payment_method ): string => $payment_method->get_name(),
			$integration->get_payment_method_integrations()
		);

		$this->assertContains( 'woocommerce_payments_sepa_debit', $integration_ids, 'A method the filter removes keeps its Blocks integration.' );
		$this->assertContains( 'woocommerce_payments', $integration_ids, 'The card integration stays registered.' );
	}

	/**
	 * @testdox Should expose Blocks payment method data for the current split gateway definition with the card gateway's supports.
	 *
	 * Client 11.1.0 registers one Blocks method that sends the card gateway's `$supports`, so a split gateway sends them too.
	 */
	public function test_get_payment_method_data_uses_current_gateway_definition(): void {
		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		$card_gateway            = new WooPaymentsGateway( $payment_method_registry->get( 'card' ) );
		// A card list unlike the Klarna gateway's, so the test shows which list Blocks receives.
		$card_gateway->supports = array( 'products', 'refunds', 'tokenization', 'add_payment_method', 'subscriptions' );
		$klarna_gateway         = new WooPaymentsGateway( $payment_method_registry->get( 'klarna' ) );
		$this->assertNotSame( $card_gateway->supports, $klarna_gateway->supports );
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$assets
			->expects( $this->once() )
			->method( 'get_blocks_payment_method_data' )
			->with( array( 'products', 'refunds', 'tokenization', 'add_payment_method', 'subscriptions' ), $klarna_gateway->get_payment_method_definition() )
			->willReturn(
				array(
					'gatewayId'          => 'woocommerce_payments_klarna',
					'title'              => 'Klarna',
					'paymentMethodTypes' => array( 'klarna' ),
				)
			);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array( $card_gateway, $klarna_gateway ) );
		$provider->method( 'get_gateway_for_method' )->willReturnMap( array( array( 'card', $card_gateway ) ) );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $klarna_gateway );

		$this->assertSame(
			array(
				'gatewayId'          => 'woocommerce_payments_klarna',
				'title'              => 'Klarna',
				'paymentMethodTypes' => array( 'klarna' ),
			),
			$integration->get_payment_method_data()
		);
	}

	/**
	 * @testdox Should include payment-request ECE params in Blocks payment method data when available.
	 */
	public function test_get_payment_method_data_includes_express_checkout_params_when_available(): void {
		$this->set_current_post_to_checkout_block();

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$assets    = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$assets->method( 'get_blocks_payment_method_data' )->willReturn(
			array(
				'title' => 'WooPayments',
			)
		);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array() );
		$express_checkout_service = $this->create_express_checkout_service( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $assets, $provider, $this->create_woopay_eligibility(), $express_checkout_service );

		$this->assertSame(
			array(
				'title'                 => 'WooPayments',
				'gatewayId'             => 'woocommerce_payments',
				'expressCheckoutParams' => array(
					'enabled_methods' => array( 'payment_request' ),
					'button_context'  => 'checkout',
					'product'         => array(
						'total' => array(
							'label' => 'Merchant (via WooCommerce)',
						),
					),
				),
			),
			$integration->get_payment_method_data()
		);
	}

	/**
	 * Create a runtime arbiter mock.
	 *
	 * @param bool $is_builtin_owner Whether native should own the runtime.
	 * @return WooPaymentsRuntimeArbiter
	 */
	private function create_runtime_arbiter( bool $is_builtin_owner = true ): WooPaymentsRuntimeArbiter {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $is_builtin_owner );

		return $arbiter;
	}

	/**
	 * Set the current global post to a checkout block page.
	 */
	private function set_current_post_to_checkout_block(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
	}

	/**
	 * Visit a published page holding one block and make it the matching WooCommerce page.
	 *
	 * @param string $block_name  Block name, such as `woocommerce/cart`.
	 * @param string $page_option WooCommerce page option that should point at the page.
	 */
	private function go_to_block_page( string $block_name, string $page_option ): void {
		$class   = 'wp-block-' . str_replace( '/', '-', $block_name );
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => sprintf( '<!-- wp:%1$s --><div class="%2$s"></div><!-- /wp:%1$s -->', $block_name, $class ),
			)
		);
		update_option( $page_option, $page_id );

		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
	}

	/**
	 * Reset the cart and checkout page checks WooCommerce caches for the request.
	 */
	private function reset_cart_checkout_page_cache(): void {
		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Create an active WooPayments Blocks integration that registers through the real Blocks asset API.
	 *
	 * @param bool $show_woopay_button  Whether the WooPay button is available.
	 * @param bool $show_express_button Whether the Apple Pay and Google Pay buttons are available.
	 * @return WooPayments
	 */
	private function create_registered_integration( bool $show_woopay_button = false, bool $show_express_button = false ): WooPayments {
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		return new WooPayments(
			Package::container()->get( AssetApi::class ),
			$this->create_runtime_arbiter(),
			$assets,
			$provider,
			$this->create_woopay_eligibility( $show_woopay_button ),
			$this->create_express_checkout_service( $show_express_button )
		);
	}

	/**
	 * Create a payment method registry holding one integration.
	 *
	 * @param WooPayments $integration Integration to register.
	 * @return PaymentMethodRegistry
	 */
	private function create_payment_method_registry( WooPayments $integration ): PaymentMethodRegistry {
		$registry = new PaymentMethodRegistry();
		$registry->register( $integration );

		return $registry;
	}

	/**
	 * Get the payment method dependencies the Blocks payments API adds to the Cart block frontend script.
	 *
	 * @param WooPayments $integration Integration to register.
	 * @return string[]
	 */
	private function get_cart_block_frontend_dependencies( WooPayments $integration ): array {
		$api = new Api( $this->create_payment_method_registry( $integration ), $this->createMock( AssetDataRegistry::class ) );

		return $api->add_payment_method_script_dependencies( array(), 'wc-cart-block-frontend' );
	}

	/**
	 * Run the asset steps of a Cart or Checkout block render, in AbstractBlock::render_callback()'s order: the block
	 * script's payment method dependencies first, then the block's render action.
	 *
	 * @param WooPayments $integration Integration to register and initialize.
	 * @param string      $block_name  `woocommerce/cart` or `woocommerce/checkout`.
	 * @return string[] The payment method dependencies of the block's frontend script.
	 */
	private function render_block_assets( WooPayments $integration, string $block_name ): array {
		$block = 'woocommerce/cart' === $block_name ? 'cart' : 'checkout';
		$integration->initialize();
		$api          = new Api( $this->create_payment_method_registry( $integration ), $this->createMock( AssetDataRegistry::class ) );
		$dependencies = $api->add_payment_method_script_dependencies( array(), 'wc-' . $block . '-block-frontend' );

		do_action( 'woocommerce_blocks_enqueue_' . $block . '_block_scripts_after' );

		return $dependencies;
	}

	/**
	 * Make a published page holding only a paragraph the store's cart or checkout page, have the given source render the
	 * page's Cart or Checkout block, and visit the page.
	 *
	 * @param string $block_name `woocommerce/cart` or `woocommerce/checkout`.
	 * @param string $source     `content`: the block is in the page content. `db_template`: a `page-cart` or
	 *                           `page-checkout` template saved in the database renders it. `theme_template`: the active
	 *                           block theme's own template file renders it.
	 */
	private function go_to_page_rendered_by( string $block_name, string $source ): void {
		$block        = str_replace( 'woocommerce/', '', $block_name );
		$block_markup = sprintf( '<!-- wp:%1$s --><div class="wp-block-woocommerce-%2$s"></div><!-- /wp:%1$s -->', $block_name, $block );
		$template     = '<!-- wp:template-part {"slug":"header"} /-->' . $block_markup;

		if ( 'db_template' === $source ) {
			switch_theme( 'twentytwentyfour' );
			$template_id = self::factory()->post->create(
				array(
					'post_type'    => 'wp_template',
					'post_name'    => 'page-' . $block,
					'post_title'   => 'Page: ' . $block,
					'post_status'  => 'publish',
					'post_content' => $template,
				)
			);
			wp_set_post_terms( $template_id, array( get_stylesheet() ), 'wp_theme' );
			wp_cache_delete( 'wp_template-ids', 'woocommerce_blocks' );
		} elseif ( 'theme_template' === $source ) {
			$this->switch_to_theme_with_template( 'page-' . $block, $template );
		}

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'content' === $source ? $block_markup : '<!-- wp:paragraph --><p>Page</p><!-- /wp:paragraph -->',
			)
		);
		update_option( 'woocommerce_' . $block . '_page_id', $page_id );
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
	}

	/**
	 * Write a minimal block theme holding one page template to a temporary theme root, and activate it.
	 *
	 * @param string $slug    Template slug, such as `page-checkout`.
	 * @param string $content Template markup.
	 */
	private function switch_to_theme_with_template( string $slug, string $content ): void {
		$this->temp_theme_root = get_temp_dir() . 'wc-woopayments-blocks-test-themes-' . wp_generate_password( 8, false );
		$theme_dir             = $this->temp_theme_root . '/wc-woopayments-blocks-test-theme';
		wp_mkdir_p( $theme_dir . '/templates' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture files in a temporary directory.
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: WooPayments Blocks Test Theme\n*/\n" );
		file_put_contents( $theme_dir . '/templates/index.html', '<!-- wp:post-content /-->' );
		file_put_contents( $theme_dir . '/templates/' . $slug . '.html', $content );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		register_theme_directory( $this->temp_theme_root );
		search_theme_directories( true );
		switch_theme( 'wc-woopayments-blocks-test-theme' );
	}

	/**
	 * Delete the temporary theme root switch_to_theme_with_template() wrote, if any.
	 */
	private function delete_temp_theme_root(): void {
		if ( '' === $this->temp_theme_root ) {
			return;
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->temp_theme_root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $this->temp_theme_root );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->temp_theme_root = '';
	}

	/**
	 * Get every registered script handle the given handles load, including their dependencies.
	 *
	 * @param string[] $handles Script handles.
	 * @return string[]
	 */
	private function get_dependency_closure( array $handles ): array {
		$loaded  = array();
		$pending = $handles;
		while ( ! empty( $pending ) ) {
			$handle = array_shift( $pending );
			if ( in_array( $handle, $loaded, true ) ) {
				continue;
			}
			$loaded[] = $handle;
			if ( isset( wp_scripts()->registered[ $handle ] ) ) {
				$pending = array_merge( $pending, wp_scripts()->registered[ $handle ]->deps );
			}
		}

		return $loaded;
	}

	/**
	 * Create a WooPay eligibility mock.
	 *
	 * @param bool $should_show_button Whether the WooPay button should be available.
	 * @return Eligibility
	 */
	private function create_woopay_eligibility( bool $should_show_button = false ): Eligibility {
		$service = $this->getMockBuilder( Eligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_woopay_button' ) )
			->getMock();
		$service->method( 'should_show_woopay_button' )->willReturn( $should_show_button );

		return $service;
	}

	/**
	 * Create an express checkout service mock.
	 *
	 * @param bool $should_show_button Whether the payment-request button should be available.
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_express_checkout_service( bool $should_show_button = false ): WooPaymentsExpressCheckoutService {
		$service = $this->getMockBuilder( WooPaymentsExpressCheckoutService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_payment_request_button', 'get_express_checkout_params' ) )
			->getMock();
		$service->method( 'should_show_payment_request_button' )->willReturn( $should_show_button );
		$service->method( 'get_express_checkout_params' )->willReturn(
			array(
				'enabled_methods' => array( 'payment_request' ),
				'button_context'  => 'checkout',
				'product'         => array(
					'total' => array(
						'label' => 'Merchant (via WooCommerce)',
					),
				),
			)
		);

		return $service;
	}
}
