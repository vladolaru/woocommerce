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
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use WP_UnitTestCase;

/**
 * Tests for the Blocks WooPayments integration.
 */
class WooPaymentsTest extends WP_UnitTestCase {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_upe_available_payment_methods' );

		foreach ( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts', 'wc-payment-method-woopayments-common', 'wc-payment-method-woopayments-woopay-common' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_deregister_script( 'stripe' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
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
	 * @testdox Should require checkout bridge readiness before the Blocks payment method activates.
	 */
	public function test_is_active_requires_checkout_bridge_readiness(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( false );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require provider readiness before the Blocks payment method activates.
	 */
	public function test_is_active_requires_provider_readiness(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( false );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require native runtime ownership before the Blocks payment method activates.
	 */
	public function test_is_active_requires_native_runtime_ownership(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter( false ), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );

		$this->assertFalse( $integration->is_active() );
	}

	/**
	 * @testdox Should require method-level gateway availability before a split Blocks method activates.
	 */
	public function test_is_active_requires_split_gateway_availability(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$gateway     = $this->getMockBuilder( NativeWooPaymentsGateway::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$gateway->id = 'woocommerce_payments_klarna';
		$gateway->expects( $this->once() )->method( 'is_available' )->willReturn( false );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service(), $gateway );

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

		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );

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

		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$woopay_session_service = $this->create_woopay_session_service( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $woopay_session_service, $this->create_express_checkout_service() );

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

		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$express_checkout_service = $this->create_express_checkout_service( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $express_checkout_service );

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ),
			$integration->get_payment_method_script_handles()
		);
	}

	/**
	 * @testdox Should not enqueue Blocks WooPayments styles on classic checkout shortcode pages.
	 */
	public function test_get_payment_method_script_handles_does_not_enqueue_blocks_styles_on_classic_checkout_shortcode_pages(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
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
		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service( true ), $this->create_express_checkout_service( true ) );

		$handles = $integration->get_payment_method_script_handles();

		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $handles );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue Blocks WooPayments styles on checkout block pages.
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
		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service( true ), $this->create_express_checkout_service( true ) );

		$handles = $integration->get_payment_method_script_handles();

		$this->assertSame(
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts' ),
			$handles
		);
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
		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service( true ), $this->create_express_checkout_service( true ) );
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

		$dependencies = $this->get_cart_block_frontend_dependencies( $this->create_registered_integration( false, true ) );

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

		$dependencies = $this->get_cart_block_frontend_dependencies( $this->create_registered_integration( true, false ) );

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

		$api          = new Api( $this->create_payment_method_registry( $this->create_registered_integration() ), $this->createMock( AssetDataRegistry::class ) );
		$dependencies = $api->add_payment_method_script_dependencies( array(), 'wc-checkout-block-frontend' );

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
	 * @testdox Should source Blocks payment method data from the checkout bridge.
	 */
	public function test_get_payment_method_data_uses_checkout_bridge_config(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$bridge
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

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );

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
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		$card_gateway            = new NativeWooPaymentsGateway( $payment_method_registry->get( 'card' ) );
		$klarna_gateway          = new NativeWooPaymentsGateway( $payment_method_registry->get( 'klarna' ) );
		$provider                = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( array( $card_gateway, $klarna_gateway ) );

		$integration  = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );
		$integrations = $integration->get_payment_method_integrations();

		$this->assertSame(
			array(
				'woocommerce_payments',
				'woocommerce_payments_klarna',
			),
			array_map(
				static fn( WooPayments $payment_method ): string => $payment_method->get_name(),
				$integrations
			)
		);
		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integrations[0]->get_payment_method_script_handles() );
		$this->assertSame( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts' ), $integrations[1]->get_payment_method_script_handles() );
	}

	/**
	 * @testdox Split-gateway Blocks integrations of one registration share one config holder, so the bridge builds the shared config once.
	 */
	public function test_split_gateway_integrations_share_one_config_holder(): void {
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$holders   = array();
		$bridge->method( 'get_blocks_payment_method_data' )->willReturnCallback(
			static function ( $supports, $definition, $shared = null ) use ( &$holders ): array {
				$holders[] = $shared;
				return array();
			}
		);
		$registry = new WooPaymentsPaymentMethodRegistry();
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( false );
		$provider->method( 'get_payment_gateways' )->willReturn( array( new NativeWooPaymentsGateway( $registry->get( 'card' ) ), new NativeWooPaymentsGateway( $registry->get( 'klarna' ) ) ) );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );
		foreach ( $integration->get_payment_method_integrations() as $payment_method ) {
			$payment_method->get_payment_method_data();
		}

		$this->assertCount( 2, $holders );
		$this->assertInstanceOf( \ArrayObject::class, $holders[0] );
		$this->assertSame( $holders[0], $holders[1] );
	}

	/**
	 * @testdox Blocks integration publication honors the registry availability filter.
	 */
	public function test_get_payment_method_integrations_honors_registry_availability_filter(): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn( array $payment_method_ids ): array => array_values( array_diff( $payment_method_ids, array( 'bancontact' ) ) )
		);
		$gateway_adapter = $this->getMockBuilder( WooPaymentsProviderGatewayAdapter::class )
			->disableOriginalConstructor()
			->getMock();
		$api_client      = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->getMock();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->getMock();
		$provider        = new WooPaymentsProvider();
		$provider->init( $gateway_adapter, $api_client, $account_service, new WooPaymentsPaymentMethodRegistry() );
		$asset_api       = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge          = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->getMock();
		$integration     = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service() );
		$integration_ids = array_map(
			static fn( WooPayments $payment_method ): string => $payment_method->get_name(),
			$integration->get_payment_method_integrations()
		);

		$this->assertNotContains( 'woocommerce_payments_bancontact', $integration_ids, 'A filtered method should not be registered with Blocks checkout.' );
		$this->assertContains( 'woocommerce_payments', $integration_ids, 'Unfiltered methods should remain registered with Blocks checkout.' );
	}

	/**
	 * @testdox Should expose Blocks payment method data for the current split gateway definition with the card gateway's supports.
	 *
	 * Client 11.1.0 registers one Blocks method that sends the card gateway's `$supports`, so a split gateway sends them too.
	 */
	public function test_get_payment_method_data_uses_current_gateway_definition(): void {
		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		$card_gateway            = new NativeWooPaymentsGateway( $payment_method_registry->get( 'card' ) );
		$card_gateway->supports  = array( 'products', 'refunds', 'tokenization', 'add_payment_method' );
		$klarna_gateway          = new NativeWooPaymentsGateway( $payment_method_registry->get( 'klarna' ) );
		$this->assertNotSame( $card_gateway->supports, $klarna_gateway->supports );
		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$bridge
			->expects( $this->once() )
			->method( 'get_blocks_payment_method_data' )
			->with( array( 'products', 'refunds', 'tokenization', 'add_payment_method' ), $klarna_gateway->get_payment_method_definition() )
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

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $this->create_express_checkout_service(), $klarna_gateway );

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
		$bridge    = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$bridge->method( 'get_blocks_payment_method_data' )->willReturn(
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

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $express_checkout_service );

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
	 * @param bool $should_native_register Whether native should own the runtime.
	 * @return NativePaymentsRuntimeArbiter
	 */
	private function create_runtime_arbiter( bool $should_native_register = true ): NativePaymentsRuntimeArbiter {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $should_native_register );

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
		$bridge = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		return new WooPayments(
			Package::container()->get( AssetApi::class ),
			$this->create_runtime_arbiter(),
			$bridge,
			$provider,
			$this->create_woopay_session_service( $show_woopay_button ),
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
	 * Create a WooPay session service mock.
	 *
	 * @param bool $should_show_button Whether the WooPay button should be available.
	 * @return WooPaymentsWooPaySessionService
	 */
	private function create_woopay_session_service( bool $should_show_button = false ): WooPaymentsWooPaySessionService {
		$service = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
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
