<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Blocks\Payments\Integrations;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments;
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

		foreach ( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_deregister_script( 'stripe' );
		wp_reset_postdata();
		parent::tearDown();
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

		$asset_api = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'register_script', 'register_style' ) )
			->getMock();
		$asset_api
			->expects( $this->once() )
			->method( 'register_script' )
			->with(
				'wc-payment-method-woopayments',
				'assets/client/blocks/wc-payment-method-woopayments.js',
				array( 'stripe', 'wc-blocks-checkout' )
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

		$this->assertSame( array( 'wc-payment-method-woopayments' ), $integration->get_payment_method_script_handles() );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertSame( 'https://js.stripe.com/v3/', wp_scripts()->registered['stripe']->src );
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
			->expects( $this->exactly( 2 ) )
			->method( 'register_script' )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout' ),
				),
				array(
					'wc-payment-method-woopayments-woopay',
					'assets/client/blocks/wc-payment-method-woopayments-woopay.js',
					array( 'wc-blocks-checkout' ),
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
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay' ),
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
			->expects( $this->exactly( 2 ) )
			->method( 'register_script' )
			->withConsecutive(
				array(
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout' ),
				),
				array(
					'wc-payment-method-woopayments-express-checkout',
					'assets/client/blocks/wc-payment-method-woopayments-express-checkout.js',
					array( 'stripe', 'wc-blocks-checkout' ),
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
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-express-checkout' ),
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

		$this->assertSame( array( 'wc-payment-method-woopayments' ), $handles );
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
			array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout' ),
			$handles
		);
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments-express-checkout', 'enqueued' ) );
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
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

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
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways' ) )
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
		$this->assertSame( array( 'wc-payment-method-woopayments' ), $integrations[0]->get_payment_method_script_handles() );
		$this->assertSame( array( 'wc-payment-method-woopayments' ), $integrations[1]->get_payment_method_script_handles() );
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
	 * @testdox Should expose Blocks payment method data for the current native WooPayments gateway definition.
	 */
	public function test_get_payment_method_data_uses_current_gateway_definition(): void {
		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		$klarna_gateway          = new NativeWooPaymentsGateway( $payment_method_registry->get( 'klarna' ) );
		$asset_api               = $this->getMockBuilder( AssetApi::class )
			->disableOriginalConstructor()
			->getMock();
		$bridge                  = $this->getMockBuilder( WooPaymentsCheckoutBridge::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_blocks_payment_method_data', 'should_expose_checkout_surface' ) )
			->getMock();
		$bridge->method( 'should_expose_checkout_surface' )->willReturn( true );
		$bridge
			->expects( $this->once() )
			->method( 'get_blocks_payment_method_data' )
			->with( $klarna_gateway->get_payment_method_definition() )
			->willReturn(
				array(
					'gatewayId'          => 'woocommerce_payments_klarna',
					'title'              => 'Klarna',
					'paymentMethodTypes' => array( 'klarna' ),
				)
			);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

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
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$express_checkout_service = $this->create_express_checkout_service( true );

		$integration = new WooPayments( $asset_api, $this->create_runtime_arbiter(), $bridge, $provider, $this->create_woopay_session_service(), $express_checkout_service );

		$this->assertSame(
			array(
				'title'                 => 'WooPayments',
				'gatewayId'             => 'woocommerce_payments',
				'expressCheckoutParams' => array(
					'enabled_methods' => array( 'payment_request' ),
					'button_context'  => 'checkout',
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
			)
		);

		return $service;
	}
}
