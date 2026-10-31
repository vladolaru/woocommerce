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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry as WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsBlocksPaymentMethodsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
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

		foreach ( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-woopay', 'wc-payment-method-woopayments-express-checkout', 'wc-payment-method-woopayments-fraud-scripts', 'wc-payment-method-woopayments-common' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_deregister_script( 'stripe' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
		wp_deregister_script( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE );
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
	 * The store states the Blocks payment method's activity depends on: the stored setup tier, whether the WooPayments
	 * feature is on, and whether the provider (the platform connection and the account) can take payments.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:bool}>
	 */
	public function provide_store_states_for_activity(): array {
		return array(
			'the store takes payments'         => array( WooPaymentsSetupTier::ACTIVE, 'yes', true, true ),
			'the WooPayments feature is off'   => array( WooPaymentsSetupTier::ACTIVE, 'no', true, false ),
			'checkout is not on yet'           => array( WooPaymentsSetupTier::CONNECTED, 'yes', true, false ),
			'the account cannot take payments' => array( WooPaymentsSetupTier::ACTIVE, 'yes', false, false ),
		);
	}

	/**
	 * @testdox Should activate the Blocks payment method only while its gateway is available: $_dataName.
	 * @dataProvider provide_store_states_for_activity
	 *
	 * Client 11.2.0 reads Blocks activity from the gateway alone (class-wc-payments-blocks-payment-method.php:45-47). The
	 * gateway is offered only in the active tier, which only a store the built-in WooPayments owns reaches, and only while
	 * the provider can take payments.
	 *
	 * @param string $stored_tier          Stored setup tier.
	 * @param string $feature              The WooPayments feature option.
	 * @param bool   $can_process_payments Whether the provider can take payments.
	 * @param bool   $expected             Whether the payment method is active.
	 */
	public function test_is_active_follows_the_gateways_availability( string $stored_tier, string $feature, bool $can_process_payments, bool $expected ): void {
		update_option( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier, true );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_gateway_enabled', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( \Automattic\WooCommerce\Proxies\LegacyProxy::class ), wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache::class ) );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			)
		);
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( $can_process_payments );
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_expose_checkout_surface' ) )
			->getMock();
		$assets->method( 'should_expose_checkout_surface' )->willReturn( $can_process_payments );
		$settings = static fn(): array => array( 'enabled' => 'yes' );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings );

		try {
			$gateway = new WooPaymentsGateway();
			$gateway->init( new \Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService(), $provider, null, null, $account_service );
			$integration = new WooPayments( $this->createMock( AssetApi::class ), $gateway, $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

			$this->assertSame( $expected, $integration->is_active() );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		}
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

		$integration = new WooPayments( $asset_api, $gateway, $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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
			->expects( $this->exactly( 3 ) )
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
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common' ),
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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common' ),
				),
				array(
					'wc-payment-method-woopayments-woopay',
					'assets/client/blocks/wc-payment-method-woopayments-woopay.js',
					array( 'wc-blocks-checkout', 'wc-payment-method-woopayments-common' ),
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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $woopay_session_service, $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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
					'wc-payment-method-woopayments',
					'assets/client/blocks/wc-payment-method-woopayments.js',
					array( 'stripe', 'wc-blocks-checkout', 'wc-payment-method-woopayments-common' ),
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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility(), $express_checkout_service, $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );
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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );
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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility( true ), $this->create_express_checkout_service( true ), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );
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
		$this->assertNotContains( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE, $loaded );
		$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $loaded );
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
		// A webpack entry runs only once its shared chunk has loaded; the express script skips the WooPay protocol.
		$this->assertContains( 'wc-payment-method-woopayments-common', $loaded );
		$this->assertNotContains( 'wc-woopayments-common', $loaded, 'The shared helpers are in the shared chunk.' );
		$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $loaded );
		$this->assertNotContains( 'wc-payment-method-woopayments', $loaded );
		$this->assertNotContains( 'wc-woopayments-fingerprintjs', $loaded );
		// The express script's built asset file lists the appearance utility (a build external); the integration's part is registering its handle.
		$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE, 'registered' ) );
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
		$this->assertNotContains( 'wc-woopayments-common', $loaded, 'The shared helpers are in the shared chunk.' );
		$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $loaded );
		$this->assertNotContains( 'wc-payment-method-woopayments', $loaded );
		$this->assertNotContains( 'stripe', $loaded );
		$this->assertNotContains( 'wc-woopayments-fingerprintjs', $loaded );
		$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE, 'registered' ) );
		$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, 'registered' ), 'An unregistered dependency would make WordPress drop the script.' );
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
		$this->assertNotContains( 'wc-woopayments-common', $loaded, 'The shared helpers are in the shared chunk.' );
		$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $loaded );
		// The card script's built asset file lists FingerprintJS and the appearance utility (build externals); the integration's part is registering their handles.
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
		$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE, 'registered' ) );
		$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, 'registered' ), 'An unregistered dependency would make WordPress drop the script.' );
		$this->assertTrue( wp_style_is( 'wc-payment-method-woopayments', 'enqueued' ) );
	}

	/**
	 * Card payment data whose WooPay flags turn the Blocks WooPay email check on or off, as the card script reads them.
	 *
	 * @return array<string,array{0:mixed,1:mixed,2:bool}>
	 */
	public function provide_woopay_email_check_flags(): array {
		return array(
			'WooPay and its email input on'      => array( true, true, true ),
			// The email input flag does not follow the WooPay setting (WooPay\SessionService::get_woopay_frontend_config()).
			'WooPay off'                         => array( false, true, false ),
			'WooPay on with the email input off' => array( true, false, false ),
			'a filtered "0", which the browser reads as on' => array( true, '0', true ),
			'a null WooPay flag, which the browser reads as off' => array( null, true, false ),
			'a null email input flag, which the browser reads as off' => array( true, null, false ),
		);
	}

	/**
	 * @testdox Should load the WooPay protocol with the Blocks card script only when the card data turns the WooPay email check on: $_dataName.
	 *
	 * @dataProvider provide_woopay_email_check_flags
	 *
	 * @param mixed $woopay_enabled      The card data's isWooPayEnabled.
	 * @param mixed $email_input_enabled The card data's isWooPayEmailInputEnabled.
	 * @param bool  $listed              Whether the card script lists the protocol.
	 */
	public function test_blocks_card_lists_the_woopay_protocol_only_with_the_email_check( $woopay_enabled, $email_input_enabled, bool $listed ): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );
		$integration = $this->create_registered_integration(
			false,
			false,
			array(
				'isWooPayEnabled'           => $woopay_enabled,
				'isWooPayEmailInputEnabled' => $email_input_enabled,
			)
		);

		$dependencies = $this->render_block_assets( $integration, 'woocommerce/checkout', true );

		$deps   = wp_scripts()->registered['wc-payment-method-woopayments']->deps;
		$loaded = $this->get_dependency_closure( $dependencies );
		if ( $listed ) {
			$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'The card script wires the WooPay email check from window.wc.woopayments.woopay.' );
			$this->assertTrue( wp_script_is( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, 'registered' ), 'An unregistered dependency would make WordPress drop the card script.' );
		} else {
			$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'Without the email check the card script never calls window.wc.woopayments.woopay.' );
			$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $loaded, 'Without the WooPay button nothing else on the page loads the protocol.' );
		}
		$this->assertContains( 'wc-payment-method-woopayments-common', $deps, 'The card keeps its other dependencies.' );
	}

	/**
	 * Steps of a Blocks page and the card data flags of each read in order, with the read the page publishes. Each read's
	 * flags are isWooPayEnabled and isWooPayEmailInputEnabled, which a wcpay_payment_fields_js_config callback can change
	 * between reads. See run_card_page_steps() for the steps.
	 *
	 * @return array<string,array{0:string[],1:array<int,array{0:bool,1:bool}>,2:int}>
	 */
	public function provide_card_page_steps(): array {
		$on  = array( true, true );
		$off = array( true, false );

		return array(
			'read of the integration before registration, off then published on' => array( array( 'read the integration', 'register', 'checkout data' ), array( $off, $on ), 1 ),
			'read of the integration before registration, on then published off' => array( array( 'read the integration', 'register', 'checkout data' ), array( $on, $off ), 1 ),
			'read of the registry before registration, off then published on' => array( array( 'read the registry', 'register', 'checkout data' ), array( $off, $on ), 1 ),
			'read of the registry before registration, on then published off' => array( array( 'read the registry', 'register', 'checkout data' ), array( $on, $off ), 1 ),
			'read after registration, off then published on' => array( array( 'register', 'read the integration', 'checkout data' ), array( $off, $on ), 1 ),
			'read after registration, on then published off' => array( array( 'register', 'read the integration', 'checkout data' ), array( $on, $off ), 1 ),
			'earlier read in the action, off then published on' => array( array( 'register', 'hook an earlier read', 'checkout data' ), array( $off, $on ), 1 ),
			'earlier read in the action, on then published off' => array( array( 'register', 'hook an earlier read', 'checkout data' ), array( $on, $off ), 1 ),
			'later read in the action, published on then off' => array( array( 'register', 'hook a later read', 'checkout data' ), array( $on, $off ), 0 ),
			'later read in the action, published off then on' => array( array( 'register', 'hook a later read', 'checkout data' ), array( $off, $on ), 0 ),
			'second data step, published on then off' => array( array( 'register', 'checkout data', 'checkout data' ), array( $on, $off ), 0 ),
			'second data step, published off then on' => array( array( 'register', 'checkout data', 'checkout data' ), array( $off, $on ), 0 ),
			'more data steps, published on, then on, then off' => array( array( 'register', 'checkout data', 'checkout data', 'checkout data' ), array( $on, $on, $off ), 0 ),
			'read after publication, published on then off' => array( array( 'register', 'checkout data', 'read the integration' ), array( $on, $off ), 0 ),
			'read after publication, published off then on' => array( array( 'register', 'checkout data', 'read the integration' ), array( $off, $on ), 0 ),
		);
	}

	/**
	 * @testdox Should keep the WooPay protocol on the Blocks card script exactly when the card data the page publishes needs it, whatever other reads hold: $_dataName.
	 *
	 * @dataProvider provide_card_page_steps
	 *
	 * @param string[]                        $steps          The page's steps, in order.
	 * @param array<int,array{0:bool,1:bool}> $sequence       The flags of each card data read, in order.
	 * @param int                             $published_read Index of the read the page publishes.
	 */
	public function test_card_woopay_protocol_follows_the_read_blocks_publishes( array $steps, array $sequence, int $published_read ): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );
		$this->filter_card_woopay_flags( $sequence );
		$page = $this->create_card_page( $this->create_card_page_assets() );

		$this->run_card_page_steps( $page, $steps );

		$this->assert_card_protocol_matches_published_flags( $page['data_registry'], $sequence[ $published_read ] );
	}

	/**
	 * Card data flags of the first and second reads on a page, in both directions of the WooPay email check.
	 *
	 * @return array<string,array{0:array{0:bool,1:bool},1:array{0:bool,1:bool}}>
	 */
	public function provide_two_card_reads(): array {
		return array(
			'off, then on' => array( array( false, true ), array( true, true ) ),
			'on, then off' => array( array( true, true ), array( true, false ) ),
		);
	}

	/**
	 * @testdox Should let the first of the Cart and Checkout blocks on one page decide the WooPay protocol, and the second change nothing: $_dataName.
	 *
	 * @dataProvider provide_two_card_reads
	 *
	 * @param array{0:bool,1:bool} $first  The Cart block's flags.
	 * @param array{0:bool,1:bool} $second The Checkout block's flags.
	 */
	public function test_second_block_on_a_page_changes_nothing( array $first, array $second ): void {
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
		$this->filter_card_woopay_flags( array( $first, $second ) );
		$page = $this->create_card_page( $this->create_card_page_assets() );

		$this->run_card_page_steps( $page, array( 'register', 'cart data', 'register', 'checkout data' ) );

		$this->assert_card_protocol_matches_published_flags( $page['data_registry'], $first );
	}

	/**
	 * @testdox Should let the Mini-Cart's read decide the WooPay protocol when the card script is already registered, and the Checkout block's read change nothing: $_dataName.
	 *
	 * @dataProvider provide_two_card_reads
	 *
	 * The Mini-Cart skips its data on the store's cart and checkout pages, so it publishes first only on another page that
	 * holds the Checkout block. It registers no payment scripts itself: they are registered already only with unified
	 * editor assets, which ask for them on init.
	 *
	 * @param array{0:bool,1:bool} $first  The Mini-Cart's flags.
	 * @param array{0:bool,1:bool} $second The Checkout block's flags.
	 */
	public function test_mini_cart_publishing_first_decides_when_the_card_script_is_registered( array $first, array $second ): void {
		$this->go_to_block_page( 'woocommerce/checkout', null );
		$this->filter_card_woopay_flags( array( $first, $second ) );
		$page = $this->create_card_page( $this->create_card_page_assets() );

		$this->run_card_page_steps( $page, array( 'register', 'cart data', 'register', 'checkout data' ) );

		$this->assert_card_protocol_matches_published_flags( $page['data_registry'], $first );
	}

	/**
	 * Pages where the Mini-Cart publishes before the card script is registered: its steps, the flags of each card data read
	 * in order, and whether the card script ends up listing the WooPay protocol. Read 0 is the Mini-Cart's, which publishes,
	 * and read 1 is the registration's. The rows with different flags are the accepted case of a config callback that
	 * changes its answer within a request: the registration's read decides, and the card's guard bounds a mismatch.
	 *
	 * @return array<string,array{0:string[],1:array<int,array{0:bool,1:bool}>,2:bool}>
	 */
	public function provide_mini_cart_first_pages(): array {
		$on         = array( true, true );
		$off        = array( true, false );
		$woopay_off = array( false, true );
		$page       = array( 'cart data', 'register', 'checkout data' );
		$editor     = array( 'cart data', 'register in the editor', 'cart data', 'checkout data' );
		$later_read = array( 'cart data', 'register', 'read the integration', 'checkout data' );

		return array(
			'ordinary page, email check on'           => array( $page, array( $on ), true ),
			'ordinary page, email check off'          => array( $page, array( $off ), false ),
			'ordinary page, WooPay off'               => array( $page, array( $woopay_off ), false ),
			'block editor, email check on'            => array( $editor, array( $on ), true ),
			'block editor, email check off'           => array( $editor, array( $off ), false ),
			'block editor, WooPay off'                => array( $editor, array( $woopay_off ), false ),
			'later reads change nothing, on then off' => array( $later_read, array( $on, $on, $off ), true ),
			'later reads change nothing, off then on' => array( $later_read, array( $off, $off, $on ), false ),
			'registration reads on, the page published off' => array( $page, array( $off, $on ), true ),
			'registration reads off, the page published on' => array( $page, array( $on, $off ), false ),
		);
	}

	/**
	 * @testdox Should let the card script's registration set the WooPay protocol when the Mini-Cart published first: $_dataName.
	 *
	 * @dataProvider provide_mini_cart_first_pages
	 *
	 * @param string[]                        $steps    The page's steps, in order.
	 * @param array<int,array{0:bool,1:bool}> $sequence The flags of each card data read, in order.
	 * @param bool                            $listed   Whether the card script lists the protocol.
	 */
	public function test_card_script_registered_after_the_mini_cart_published_sets_the_protocol( array $steps, array $sequence, bool $listed ): void {
		$this->go_to_block_page( 'woocommerce/checkout', null );
		$this->filter_card_woopay_flags( $sequence );
		$page = $this->create_card_page( $this->create_card_page_assets() );
		if ( in_array( 'register in the editor', $steps, true ) ) {
			set_current_screen( 'edit-page' );
		}

		$this->run_card_page_steps( $page, $steps );
		set_current_screen( 'front' );

		$published = $this->get_published_card_data( $page['data_registry'] );
		$this->assertSame( $sequence[0][0], $published['isWooPayEnabled'] ?? null, 'Precondition: the Mini-Cart publishes its read.' );
		$this->assertSame( $sequence[0][1], $published['isWooPayEmailInputEnabled'] ?? null, 'Precondition: the Mini-Cart publishes its read.' );
		$deps = wp_scripts()->registered['wc-payment-method-woopayments']->deps;
		if ( $listed ) {
			$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'The registration read wires the email check.' );
		} else {
			$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'The registration read leaves the email check off.' );
		}
	}

	/**
	 * @testdox Should read the card data once, in the Mini-Cart's pass, when printing the scripts first registers the card script on a page without a Cart or Checkout block.
	 *
	 * A block theme renders its header Mini-Cart before wp_head, so its pass comes before the print-time dependency check.
	 */
	public function test_card_script_registered_after_the_mini_cart_during_print_makes_no_read(): void {
		$this->go_to_block_page( 'core/paragraph', null );
		$counts = $this->count_config_builds_and_filter_calls();
		$page   = $this->create_card_page( $this->create_card_page_assets() );

		$this->run_card_page_steps( $page, array( 'cart data', 'print' ) );

		$this->assertTrue( $page['data_registry']->exists( 'paymentMethodData' ), 'Precondition: the Mini-Cart publishes.' );
		$this->assertTrue( wp_script_is( 'wc-payment-method-woopayments', 'registered' ), 'Precondition: printing the scripts registers the card script.' );
		$this->assertSame( 1, $counts['filtered'], 'Only the Mini-Cart reads the card data.' );
	}

	/**
	 * Reads of the card data that publish nothing, with the email check off.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_pure_reads(): array {
		return array(
			'of the integration' => array( 'read the integration' ),
			'of the registry'    => array( 'read the registry' ),
		);
	}

	/**
	 * @testdox Should leave the card script's dependencies untouched on a read outside the two enqueue data actions: $_dataName.
	 *
	 * @dataProvider provide_pure_reads
	 *
	 * @param string $read The read, a step of run_card_page_steps().
	 */
	public function test_read_outside_the_enqueue_data_actions_leaves_the_dependencies_untouched( string $read ): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );
		$this->filter_card_woopay_flags( array( array( true, false ) ) );
		$page = $this->create_card_page( $this->create_card_page_assets() );
		$this->run_card_page_steps( $page, array( 'register' ) );
		$deps = wp_scripts()->registered['wc-payment-method-woopayments']->deps;
		$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'Precondition: the card script lists the protocol.' );

		$this->run_card_page_steps( $page, array( $read ) );

		$this->assertSame( $deps, wp_scripts()->registered['wc-payment-method-woopayments']->deps );
	}

	/**
	 * @testdox Should decide the Blocks card's WooPay protocol afresh on the next request, with nothing carried over: $_dataName.
	 *
	 * @dataProvider provide_two_card_reads
	 *
	 * @param array{0:bool,1:bool} $first  The first request's flags.
	 * @param array{0:bool,1:bool} $second The second request's flags.
	 */
	public function test_next_request_decides_the_card_woopay_protocol_afresh( array $first, array $second ): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );
		$this->filter_card_woopay_flags( array( $first, $second ) );
		$assets = $this->create_card_page_assets();
		$page   = $this->create_card_page( $assets );
		$this->run_card_page_steps( $page, array( 'register', 'checkout data' ) );
		$this->assert_card_protocol_matches_published_flags( $page['data_registry'], $first );

		// The next request: a fresh script registration and data registry, on the same service.
		wp_deregister_script( 'wc-payment-method-woopayments' );
		$page = $this->create_card_page( $assets );
		$this->run_card_page_steps( $page, array( 'register', 'checkout data' ) );

		$this->assert_card_protocol_matches_published_flags( $page['data_registry'], $second );
	}

	/**
	 * @testdox Should read the data registry Blocks publishes to on an integration the registrar builds, so a read after publication changes nothing.
	 */
	public function test_registered_card_reads_the_data_registry_blocks_publishes_to(): void {
		$this->go_to_block_page( 'woocommerce/checkout', 'woocommerce_checkout_page_id' );
		$this->filter_card_woopay_flags( array( array( true, true ), array( true, false ) ) );
		$payment_methods = new PaymentMethodRegistry();
		$this->create_split_registrar( $this->create_card_page_assets(), array( $this->create_card_gateway() ) )->handle_woocommerce_blocks_payment_method_type_registration( $payment_methods );
		$data_registry = Package::container()->get( AssetDataRegistry::class );
		$data          = new \ReflectionProperty( AssetDataRegistry::class, 'data' );
		$data->setAccessible( true );
		$saved_data = $data->getValue( $data_registry );
		$data->setValue( $data_registry, array_diff_key( $saved_data, array_flip( array( 'paymentMethodData', 'paymentMethodSortOrder' ) ) ) );

		try {
			$api = new Api( $payment_methods, $data_registry );
			$this->run_card_page_steps(
				array(
					'integration'     => null,
					'payment_methods' => $payment_methods,
					'api'             => $this->hook_payment_data( $api ),
					'data_registry'   => $data_registry,
				),
				array( 'register', 'checkout data', 'checkout data' )
			);

			$this->assert_card_protocol_matches_published_flags( $data_registry, array( true, true ) );
		} finally {
			$data->setValue( $data_registry, $saved_data );
		}
	}

	/**
	 * Create real checkout assets for a card page, over account A.
	 *
	 * @return WooPaymentsCheckoutAssets
	 */
	private function create_card_page_assets(): WooPaymentsCheckoutAssets {
		$account_data = self::ACCOUNT_A;

		return $this->create_checkout_assets( $this->create_checkout_account( $account_data ) );
	}

	/**
	 * Create the parts of a Blocks page with the card: the integration, the real payment method registry and Blocks
	 * payments API, and a real data registry, for one request. Only this API's callback runs on the two enqueue data actions.
	 *
	 * @param WooPaymentsCheckoutAssets $assets Checkout assets to build the card data with.
	 * @return array{integration:WooPayments,payment_methods:PaymentMethodRegistry,api:Api,data_registry:AssetDataRegistry}
	 */
	private function create_card_page( WooPaymentsCheckoutAssets $assets ): array {
		$data_registry = new AssetDataRegistry( Package::container()->get( AssetApi::class ) );
		$integration   = $this->create_registered_integration( false, false, null, $assets, $data_registry );
		$integration->initialize();
		$payment_methods = $this->create_payment_method_registry( $integration );

		return array(
			'integration'     => $integration,
			'payment_methods' => $payment_methods,
			'api'             => $this->hook_payment_data( new Api( $payment_methods, $data_registry ) ),
			'data_registry'   => $data_registry,
		);
	}

	/**
	 * Make a Blocks payments API's data callback the only one on the two enqueue data actions, as Api::init() hooks it.
	 *
	 * @param Api $api Blocks payments API.
	 * @return Api The same API.
	 */
	private function hook_payment_data( Api $api ): Api {
		foreach ( array( 'woocommerce_blocks_checkout_enqueue_data', 'woocommerce_blocks_cart_enqueue_data' ) as $action ) {
			remove_all_actions( $action );
			add_action( $action, array( $api, 'add_payment_method_script_data' ) );
		}

		return $api;
	}

	/**
	 * Run steps of a Blocks page. `register` is the Checkout block's script registration step, which
	 * AbstractBlock::render_callback() runs before the block's data, and `register in the editor` is the one
	 * AbstractBlock::enqueue_editor_assets() runs with unified editor assets off. `print` is wp_print_scripts with only the
	 * API's dependency check on it, as Api::init() hooks it. `checkout data` and `cart data` fire the Checkout
	 * block's and the Cart or Mini-Cart block's enqueue data action. The read steps read the card data, publishing nothing.
	 * The hook steps add a read of the card data to the checkout action, before or after the API's callback.
	 *
	 * @param array{integration:WooPayments|null,payment_methods:PaymentMethodRegistry,api:Api,data_registry:AssetDataRegistry} $page  The page, from create_card_page().
	 * @param string[]                                                                                                          $steps The steps, in order.
	 */
	private function run_card_page_steps( array $page, array $steps ): void {
		$read = array( $page['integration'], 'get_payment_method_data' );
		foreach ( $steps as $step ) {
			if ( 'register' === $step ) {
				$page['api']->add_payment_method_script_dependencies( array(), 'wc-checkout-block-frontend' );
			} elseif ( 'register in the editor' === $step ) {
				$page['api']->add_payment_method_script_dependencies( array(), 'wc-checkout-block' );
			} elseif ( 'print' === $step ) {
				remove_all_actions( 'wp_print_scripts' );
				add_action( 'wp_print_scripts', array( $page['api'], 'verify_payment_methods_dependencies' ), 1 );
				do_action( 'wp_print_scripts' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			} elseif ( 'checkout data' === $step ) {
				do_action( 'woocommerce_blocks_checkout_enqueue_data' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			} elseif ( 'cart data' === $step ) {
				do_action( 'woocommerce_blocks_cart_enqueue_data' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			} elseif ( 'read the integration' === $step ) {
				$page['integration']->get_payment_method_data();
			} elseif ( 'read the registry' === $step ) {
				$page['payment_methods']->get_all_registered_script_data();
			} elseif ( 'hook an earlier read' === $step ) {
				add_action( 'woocommerce_blocks_checkout_enqueue_data', $read, 5 );
			} elseif ( 'hook a later read' === $step ) {
				add_action( 'woocommerce_blocks_checkout_enqueue_data', $read, 20 );
			}
		}
	}

	/**
	 * Assert the page published the given flags and the card script lists the WooPay protocol exactly when they turn the
	 * WooPay email check on.
	 *
	 * @param AssetDataRegistry    $data_registry Data registry of the page.
	 * @param array{0:bool,1:bool} $flags         The flags the page must publish.
	 */
	private function assert_card_protocol_matches_published_flags( AssetDataRegistry $data_registry, array $flags ): void {
		$published = $this->get_published_card_data( $data_registry );
		$this->assertSame( $flags[0], $published['isWooPayEnabled'] ?? null, 'Precondition: the page publishes the expected read.' );
		$this->assertSame( $flags[1], $published['isWooPayEmailInputEnabled'] ?? null, 'Precondition: the page publishes the expected read.' );
		$deps = wp_scripts()->registered['wc-payment-method-woopayments']->deps;
		if ( $flags[0] && $flags[1] ) {
			$this->assertContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'The published data wires the email check, which calls window.wc.woopayments.woopay.' );
		} else {
			$this->assertNotContains( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $deps, 'The published data leaves the email check off, so the page needs no protocol.' );
		}
	}

	/**
	 * Set the card data's WooPay flags through wcpay_payment_fields_js_config, one pair per card data read in order;
	 * the last pair stays for any further read.
	 *
	 * @param array<int,array{0:bool,1:bool}> $sequence The flags of each read.
	 */
	private function filter_card_woopay_flags( array $sequence ): void {
		$reads = new \ArrayObject( array( 'count' => 0 ) );
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( $config ) use ( $sequence, $reads ) {
				$flags                               = $sequence[ min( $reads['count'], count( $sequence ) - 1 ) ];
				$reads['count']                      = $reads['count'] + 1;
				$config['isWooPayEnabled']           = $flags[0];
				$config['isWooPayEmailInputEnabled'] = $flags[1];
				return $config;
			}
		);
	}

	/**
	 * Get the card's payment data a data registry holds for the page.
	 *
	 * @param AssetDataRegistry $asset_registry Data registry.
	 * @return array<string,mixed>
	 */
	private function get_published_card_data( AssetDataRegistry $asset_registry ): array {
		$property = new \ReflectionProperty( AssetDataRegistry::class, 'data' );
		$property->setAccessible( true );

		return $property->getValue( $asset_registry )['paymentMethodData']['woocommerce_payments'] ?? array();
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
		$integration = new WooPayments( $this->createMock( AssetApi::class ), $this->create_card_gateway(), $provider, $assets, $woopay, $express, $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

		$this->assertSame(
			array(
				'title'     => 'WooPayments',
				'gatewayId' => 'woocommerce_payments',
			),
			$integration->get_payment_method_data()
		);
	}

	/**
	 * Connected account A's cached data.
	 */
	private const ACCOUNT_A = array(
		'account_id'           => 'acct_A',
		'live_publishable_key' => 'pk_live_A',
		'test_publishable_key' => 'pk_test_A',
		'payments_enabled'     => true,
		'details_submitted'    => true,
		'country'              => 'US',
	);

	/**
	 * Connected account B's cached data.
	 */
	private const ACCOUNT_B = array(
		'account_id'           => 'acct_B',
		'live_publishable_key' => 'pk_live_B',
		'test_publishable_key' => 'pk_test_B',
		'payments_enabled'     => true,
		'details_submitted'    => true,
		'country'              => 'US',
	);

	/**
	 * @testdox Split-gateway Blocks integrations get byte-identical shared config data, built once per registration, and each gateway's config passes the filter.
	 *
	 * Client 11.1.0 registers one Blocks method and builds its payment fields config once; the split integrations share one
	 * build of the gateway-independent part.
	 */
	public function test_split_gateway_integrations_get_one_shared_config_build(): void {
		$account_data = self::ACCOUNT_A;
		$registrar    = $this->create_split_registrar( $this->create_checkout_assets( $this->create_checkout_account( $account_data ) ) );
		$counts       = $this->count_config_builds_and_filter_calls();
		$gateway_keys = array_flip( array( 'gatewayId', 'paymentMethodsConfig', 'paymentMethodTypes', 'title', 'description', 'expressCheckoutParams' ) );

		$shared = array_map(
			static fn( array $data ): string => (string) wp_json_encode( array_diff_key( $data, $gateway_keys ) ),
			$this->run_registration_pass( $registrar )
		);

		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), array_keys( $shared ) );
		$this->assertStringContainsString( '"publishableKey":"pk_live_A"', $shared['woocommerce_payments'] );
		$this->assertSame( $shared['woocommerce_payments'], $shared['woocommerce_payments_klarna'], 'Klarna gets the card integration\'s shared config data.' );
		$this->assertSame( $shared['woocommerce_payments'], $shared['woocommerce_payments_affirm'], 'Affirm gets the card integration\'s shared config data.' );
		$this->assertSame( 1, $counts['builds'], 'The shared config is built once for the three integrations.' );
		$this->assertSame( 3, $counts['filtered'], 'Each gateway config passes the wcpay_payment_fields_js_config filter.' );
	}

	/**
	 * @testdox The next Blocks registration on the same checkout assets builds the shared config again when $change.
	 *
	 * Each registration used to get its own holder for the shared config. The checkout assets service can outlive a
	 * request (a worker, WP-CLI, the test framework), and run_in_test_mode_context() changes the mode within one.
	 *
	 * @testWith ["the account switches to test mode"]
	 *           ["another account connects"]
	 *           ["the page turns from an empty-cart checkout into a subscription's change payment method page"]
	 *
	 * @param string $change What changes between the two registrations.
	 */
	public function test_next_registration_rebuilds_the_shared_config( string $change ): void {
		$account_data = self::ACCOUNT_A;
		$account      = $this->create_checkout_account( $account_data );
		$registrar    = $this->create_split_registrar( $this->create_checkout_assets( $account ) );
		$counts       = $this->count_config_builds_and_filter_calls();
		$first        = $this->run_registration_pass( $registrar )['woocommerce_payments'];
		$this->assertSame( 'pk_live_A', $first['publishableKey'] );
		$this->assertFalse( $first['testMode'] );
		$this->assertArrayNotHasKey( 'isChangingPayment', $first );
		$this->assertSame( 3, $counts['filtered'], 'The first registration passes each gateway config through the filter.' );
		$counts['builds']   = 0;
		$counts['filtered'] = 0;

		global $wp;
		$second = array();
		try {
			if ( 'the account switches to test mode' === $change ) {
				$account->run_in_test_mode_context(
					true,
					function () use ( $registrar, &$second ): void {
						$second = $this->run_registration_pass( $registrar )['woocommerce_payments'];
					}
				);
			} elseif ( 'another account connects' === $change ) {
				$account_data = self::ACCOUNT_B;
				$second       = $this->run_registration_pass( $registrar )['woocommerce_payments'];
			} else {
				$this->register_legacy_proxy_function_mocks(
					array(
						'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
					)
				);
				WooCommerceSubscriptionsDoubles::load_subscription_detector();
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
				$wp->query_vars['order-pay']                                  = 123;
				$_GET['change_payment_method']                                = '123';
				$second = $this->run_registration_pass( $registrar )['woocommerce_payments'];
			}
		} finally {
			unset( $_GET['change_payment_method'], $wp->query_vars['order-pay'], $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		}

		if ( 'the account switches to test mode' === $change ) {
			$this->assertSame( 'pk_test_A', $second['publishableKey'] );
			$this->assertTrue( $second['testMode'] );
		} elseif ( 'another account connects' === $change ) {
			$this->assertSame( 'acct_B', $second['accountId'] );
			$this->assertSame( 'pk_live_B', $second['publishableKey'] );
		} else {
			$this->assertSame( 0, $counts['filtered'], 'A change payment method config skips the wcpay_payment_fields_js_config filter.' );
			$this->assertTrue( $second['isChangingPayment'] ?? false, 'The change payment method page gets its own config.' );
		}
		$this->assertSame( 1, $counts['builds'], 'The second registration builds its own shared config.' );
	}

	/**
	 * @testdox The next Blocks registration on the same checkout assets carries the next guest's session values, though nothing in the config key changes.
	 *
	 * Two guests share every key input (user 0, the account, the mode, an empty cart), so only the registration's own
	 * reset keeps the second guest from the first guest's Sift session and WooPay cart token. No init runs between them,
	 * as in a long-running process that registers twice.
	 */
	public function test_next_registration_carries_the_next_guests_session_values(): void {
		$account_data = array_merge(
			self::ACCOUNT_A,
			array(
				'fraud_services' => array(
					'sift' => array(
						'beacon_key'         => 'live-beacon',
						'sandbox_beacon_key' => 'sandbox-beacon',
					),
				),
			)
		);
		$account      = $this->create_checkout_account( $account_data );
		// The WooPay config carries the Store API cart token of the shopper's session (WooPay/SessionService.php:351-358).
		$woopay = $this->getMockBuilder( SessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config' ) )
			->getMock();
		$woopay->method( 'get_woopay_frontend_config' )->willReturnCallback(
			static fn(): array => array( 'woopayMinimumSessionData' => array( 'store_api_token' => CartTokenUtils::get_cart_token( (string) WC()->session->get_customer_id() ) ) )
		);
		$assets        = $this->create_checkout_assets( $account, $woopay );
		$fraud_service = new WooPaymentsFraudService();
		$fraud_service->init( $account, $this->createMock( WooPaymentsCustomerService::class ), new WooPaymentsSessionService(), $this->createMock( Client::class ) );
		$property = new \ReflectionProperty( WooPaymentsCheckoutAssets::class, 'fraud_service' );
		$property->setAccessible( true );
		$property->setValue( $assets, $fraud_service );
		$registrar = $this->create_split_registrar( $assets );
		$counts    = $this->count_config_builds_and_filter_calls();
		wp_set_current_user( 0 );
		$original_session = WC()->session;

		try {
			WC()->session          = $this->create_guest_session( 'guest-a' );
			$first                 = $this->run_registration_pass( $registrar )['woocommerce_payments'];
			WC()->session          = $this->create_guest_session( 'guest-b' );
			$second                = $this->run_registration_pass( $registrar )['woocommerce_payments'];
			$expected_sift_session = ( new WooPaymentsSessionService() )->get_sift_session_id();
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( 'guest-a', CartTokenUtils::get_cart_token_payload( $first['woopayMinimumSessionData']['store_api_token'] )['user_id'], 'Precondition: the first guest\'s cart token.' );
		$this->assertSame( 'guest-b', CartTokenUtils::get_cart_token_payload( $second['woopayMinimumSessionData']['store_api_token'] )['user_id'], 'The cart token of the next guest.' );
		$this->assertNotSame( $first['fraudServices']['sift']['session_id'], $second['fraudServices']['sift']['session_id'] );
		$this->assertSame( $expected_sift_session, $second['fraudServices']['sift']['session_id'], 'The Sift session of the next guest.' );
		$this->assertSame( 2, $counts['builds'], 'Each registration builds the shared config once.' );
	}

	/**
	 * Create a guest's WooCommerce session with the given customer ID.
	 *
	 * @param string $customer_id Session customer ID.
	 * @return \WC_Session
	 */
	private function create_guest_session( string $customer_id ): \WC_Session {
		$session  = new \WC_Session_Handler();
		$property = new \ReflectionProperty( \WC_Session::class, '_customer_id' );
		$property->setAccessible( true );
		$property->setValue( $session, $customer_id );

		return $session;
	}

	/**
	 * Create an account service double over the given cached account data; the test can swap the data between calls.
	 * Its mode, publishable key, account ID and payment readiness come from the real methods.
	 *
	 * @param array<string,mixed> $account_data Cached account data, read by reference.
	 * @return WooPaymentsAccountService
	 */
	private function create_checkout_account( array &$account_data ): WooPaymentsAccountService {
		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_onboarding_enabled' ) )
			->getMock();
		$account->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( &$account_data ): array {
				return $account_data;
			}
		);
		$account->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				unset( $key );
				return $fallback;
			}
		);
		$account->method( 'is_test_mode_onboarding_enabled' )->willReturn( false );

		return $account;
	}

	/**
	 * Create real checkout assets over the given account service, with platform requests blocked.
	 *
	 * @param WooPaymentsAccountService $account Account service.
	 * @param SessionService|null       $woopay  WooPay session service, or null for a double that adds nothing.
	 * @return WooPaymentsCheckoutAssets
	 */
	private function create_checkout_assets( WooPaymentsAccountService $account, ?SessionService $woopay = null ): WooPaymentsCheckoutAssets {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, string $url ) {
				unset( $parsed_args );
				return false !== strpos( $url, 'public-api.wordpress.com' ) ? new \WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' ) : $preempt;
			},
			10,
			3
		);
		$styles = $this->getMockBuilder( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService::class )
			->onlyMethods( array( 'get_or_create_styles_cache_version' ) )
			->getMock();
		$styles->method( 'get_or_create_styles_cache_version' )->willReturn( 'styles-v1' );
		$assets = new WooPaymentsCheckoutAssets();
		$assets->init(
			$account,
			$woopay ?? $this->createMock( SessionService::class ),
			$this->createMock( Eligibility::class ),
			$styles,
			$this->createMock( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		return $assets;
	}

	/**
	 * Create the Blocks payment methods registrar over the given checkout assets, on an active store with card, Klarna
	 * and Affirm split gateways.
	 *
	 * @param WooPaymentsCheckoutAssets $assets   Checkout assets.
	 * @param WooPaymentsGateway[]|null $gateways Gateways to register instead of the split ones.
	 * @return WooPaymentsBlocksPaymentMethodsController
	 */
	private function create_split_registrar( WooPaymentsCheckoutAssets $assets, ?array $gateways = null ): WooPaymentsBlocksPaymentMethodsController {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$gateways = $gateways ?? array( new WooPaymentsGateway( $registry->get( 'card' ) ), new WooPaymentsGateway( $registry->get( 'klarna' ) ), new WooPaymentsGateway( $registry->get( 'affirm' ) ) );
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_payment_gateways', 'get_gateway_for_method' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->method( 'get_payment_gateways' )->willReturn( $gateways );
		$provider->method( 'get_gateway_for_method' )->willReturn( $gateways[0] );
		$tier_arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_enabled', 'get_runtime_owner' ) )
			->getMock();
		$tier_arbiter->method( 'is_builtin_enabled' )->willReturn( true );
		$tier_arbiter->method( 'get_runtime_owner' )->willReturn( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		$setup_tier = new WooPaymentsSetupTier();
		$setup_tier->init( $tier_arbiter );

		$registrar = new WooPaymentsBlocksPaymentMethodsController();
		$registrar->init( $setup_tier, $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets() );

		return $registrar;
	}

	/**
	 * Count shared config builds, through the account filter the config base applies once per build, and
	 * wcpay_payment_fields_js_config calls.
	 *
	 * @return \ArrayObject<string,int> The `builds` and `filtered` counts.
	 */
	private function count_config_builds_and_filter_calls(): \ArrayObject {
		$counts = new \ArrayObject(
			array(
				'builds'   => 0,
				'filtered' => 0,
			)
		);
		add_filter(
			'wc_payments_account_id_for_intent_confirmation',
			static function ( $value ) use ( $counts ) {
				$counts['builds'] = $counts['builds'] + 1;
				return $value;
			}
		);
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( $config ) use ( $counts ) {
				$counts['filtered'] = $counts['filtered'] + 1;
				return $config;
			}
		);

		return $counts;
	}

	/**
	 * Run one Blocks registration pass as Blocks does: register the payment methods on a fresh registry, then read each
	 * one's data.
	 *
	 * @param WooPaymentsBlocksPaymentMethodsController $registrar Blocks payment methods registrar.
	 * @return array<string,array<string,mixed>> Payment method data by payment method name.
	 */
	private function run_registration_pass( WooPaymentsBlocksPaymentMethodsController $registrar ): array {
		$registry = new PaymentMethodRegistry();
		$registrar->handle_woocommerce_blocks_payment_method_type_registration( $registry );
		$data = array();
		foreach ( $registry->get_all_registered() as $name => $payment_method ) {
			$data[ $name ] = $payment_method->get_payment_method_data();
		}

		return $data;
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

		$integration = new WooPayments( $asset_api, $klarna_gateway, $provider, $assets, $this->create_woopay_eligibility(), $this->create_express_checkout_service(), $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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

		$integration = new WooPayments( $asset_api, $this->create_card_gateway(), $provider, $assets, $this->create_woopay_eligibility(), $express_checkout_service, $this->get_fraud_prevention_service(), $this->get_frontend_assets(), $this->createMock( AssetDataRegistry::class ) );

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
	 * @param string      $block_name  Block name, such as `woocommerce/cart`.
	 * @param string|null $page_option WooCommerce page option that should point at the page, or null to leave it an ordinary page.
	 */
	private function go_to_block_page( string $block_name, ?string $page_option ): void {
		$class   = 'wp-block-' . str_replace( '/', '-', $block_name );
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => sprintf( '<!-- wp:%1$s --><div class="%2$s"></div><!-- /wp:%1$s -->', $block_name, $class ),
			)
		);
		if ( null !== $page_option ) {
			update_option( $page_option, $page_id );
		}

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
	 * @param bool                           $show_woopay_button  Whether the WooPay button is available.
	 * @param bool                           $show_express_button Whether the Apple Pay and Google Pay buttons are available.
	 * @param array<string,mixed>|null       $payment_method_data The Blocks payment method data the checkout assets build, or null to leave it unbuilt.
	 * @param WooPaymentsCheckoutAssets|null $assets              Checkout assets to build the data with, or null for a double.
	 * @param AssetDataRegistry|null         $asset_data_registry Data registry the page publishes, or null for a double.
	 * @return WooPayments
	 */
	private function create_registered_integration( bool $show_woopay_button = false, bool $show_express_button = false, ?array $payment_method_data = null, ?WooPaymentsCheckoutAssets $assets = null, ?AssetDataRegistry $asset_data_registry = null ): WooPayments {
		if ( null === $assets ) {
			$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
				->disableOriginalConstructor()
				->onlyMethods( null === $payment_method_data ? array( 'should_expose_checkout_surface' ) : array( 'should_expose_checkout_surface', 'get_blocks_payment_method_data' ) )
				->getMock();
			$assets->method( 'should_expose_checkout_surface' )->willReturn( true );
			if ( null !== $payment_method_data ) {
				$assets->method( 'get_blocks_payment_method_data' )->willReturn( $payment_method_data );
			}
		}
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		return new WooPayments(
			Package::container()->get( AssetApi::class ),
			$this->create_card_gateway(),
			$provider,
			$assets,
			$this->create_woopay_eligibility( $show_woopay_button ),
			$this->create_express_checkout_service( $show_express_button ),
			$this->get_fraud_prevention_service(),
			$this->get_frontend_assets(),
			$asset_data_registry ?? $this->createMock( AssetDataRegistry::class )
		);
	}

	/**
	 * Create the card gateway a Blocks integration stands for, reported available.
	 *
	 * @return WooPaymentsGateway
	 */
	private function create_card_gateway(): WooPaymentsGateway {
		$gateway = $this->getMockBuilder( WooPaymentsGateway::class )
			->setConstructorArgs( array( ( new WooPaymentsPaymentMethodRegistry() )->get( 'card' ) ) )
			->onlyMethods( array( 'is_available' ) )
			->getMock();
		$gateway->method( 'is_available' )->willReturn( true );

		return $gateway;
	}

	/**
	 * Get the fraud-prevention token service the container serves.
	 *
	 * @return WooPaymentsFraudPreventionService
	 */
	private function get_fraud_prevention_service(): WooPaymentsFraudPreventionService {
		return wc_get_container()->get( WooPaymentsFraudPreventionService::class );
	}

	/**
	 * Get the shared shopper script registrar the container serves.
	 *
	 * @return WooPaymentsFrontendAssets
	 */
	private function get_frontend_assets(): WooPaymentsFrontendAssets {
		return wc_get_container()->get( WooPaymentsFrontendAssets::class );
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
	 * script's payment method dependencies first, then the block's payment method data when asked, then the block's
	 * render action.
	 *
	 * @param WooPayments $integration Integration to register and initialize.
	 * @param string      $block_name  `woocommerce/cart` or `woocommerce/checkout`.
	 * @param bool        $with_data   Whether the block also reads the payment method data, as its enqueue_data() step does.
	 * @return string[] The payment method dependencies of the block's frontend script.
	 */
	private function render_block_assets( WooPayments $integration, string $block_name, bool $with_data = false ): array {
		$block = 'woocommerce/cart' === $block_name ? 'cart' : 'checkout';
		$integration->initialize();
		$api          = new Api( $this->create_payment_method_registry( $integration ), $this->createMock( AssetDataRegistry::class ) );
		$dependencies = $api->add_payment_method_script_dependencies( array(), 'wc-' . $block . '-block-frontend' );
		if ( $with_data ) {
			$data_action = 'woocommerce_blocks_' . $block . '_enqueue_data';
			remove_all_actions( $data_action );
			add_action( $data_action, array( $api, 'add_payment_method_script_data' ) );
			do_action( $data_action ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		}

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
