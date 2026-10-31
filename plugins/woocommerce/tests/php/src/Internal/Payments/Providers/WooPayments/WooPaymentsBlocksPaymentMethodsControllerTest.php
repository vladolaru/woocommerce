<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Payments\Api;
use Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry as WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsBlocksPaymentMethodsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Unit_Test_Case;

/**
 * Tests for the registrar of the WooPayments Blocks payment methods.
 */
class WooPaymentsBlocksPaymentMethodsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_upe_available_payment_methods' );
		foreach ( array( 'wc-payment-method-woopayments', 'wc-payment-method-woopayments-fraud-scripts', 'wc-payment-method-woopayments-common', 'wc-woopayments-woopay-common', 'wc-woopayments-appearance' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_deregister_script( 'stripe' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
		wp_dequeue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		wp_deregister_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		parent::tearDown();
	}

	/**
	 * @testdox Should publish one Blocks integration instance for each native WooPayments gateway.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART for the rest of the run, which drops the card
	 * script from the handles.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registers_one_blocks_payment_method_per_native_gateway(): void {
		$assets = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
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

		$integrations = $this->register_payment_methods( $this->create_sut( $provider, $assets ) );

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
	 * Client 11.1.0 builds a gateway for every registered definition (`includes/class-wc-payments.php:616-645`); the
	 * availability filter only shapes the settings list (`includes/class-wc-payment-gateway-wcpay.php:4848-4879`).
	 *
	 * @testdox Should register the Blocks integration of a payment method the availability filter removes.
	 */
	public function test_registers_the_blocks_payment_method_of_a_payment_method_the_availability_filter_removes(): void {
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
		$assets          = $this->getMockBuilder( WooPaymentsCheckoutAssets::class )
			->disableOriginalConstructor()
			->getMock();
		$integration_ids = array_map(
			static fn( WooPayments $payment_method ): string => $payment_method->get_name(),
			$this->register_payment_methods( $this->create_sut( $provider, $assets ) )
		);

		$this->assertContains( 'woocommerce_payments_sepa_debit', $integration_ids, 'A method the filter removes keeps its Blocks integration.' );
		$this->assertContains( 'woocommerce_payments', $integration_ids, 'The card integration stays registered.' );
	}

	/**
	 * Provide the stores below the active tier: the stored tier, whether the WooPayments feature is on and who owns payments.
	 *
	 * @return array<string,array{0:string,1:bool,2:string}>
	 */
	public function provide_stores_below_the_active_tier(): array {
		return array(
			'disabled'                      => array( WooPaymentsSetupTier::ACTIVE, false, WooPaymentsRuntimeArbiter::OWNER_NONE ),
			'available, the extension owns' => array( WooPaymentsSetupTier::ACTIVE, true, WooPaymentsRuntimeArbiter::OWNER_EXTENSION ),
			'connected'                     => array( WooPaymentsSetupTier::CONNECTED, true, WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
		);
	}

	/**
	 * @testdox Bundled integrations remain registered without resolving WooPayments below the active tier: $_dataName.
	 * @dataProvider provide_stores_below_the_active_tier
	 *
	 * @param string $stored_tier     Stored setup tier.
	 * @param bool   $feature_enabled Whether the WooPayments feature is on.
	 * @param string $owner           Payments runtime owner.
	 */
	public function test_registers_no_woopayments_method_below_the_active_tier( string $stored_tier, bool $feature_enabled, string $owner ): void {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_gateways' ) )
			->getMock();
		$provider->expects( $this->never() )->method( 'get_payment_gateways' );
		$sut      = $this->create_sut( $provider, $this->createMock( WooPaymentsCheckoutAssets::class ), $this->create_setup_tier( $stored_tier, $feature_enabled, $owner ) );
		$registry = new PaymentMethodRegistry();

		( new Api( $registry, $this->createMock( AssetDataRegistry::class ) ) )->register_payment_method_integrations( $registry );
		$sut->handle_woocommerce_blocks_payment_method_type_registration( $registry );

		$this->assertSame(
			array( 'cheque', 'paypal', 'bacs', 'cod' ),
			array_keys( $registry->get_all_registered() ),
			'Bundled payment integrations should remain available below the active tier.'
		);
	}

	/**
	 * @testdox Resolves and registers WooPayments once for the active tier.
	 */
	public function test_registers_woopayments_after_the_bundled_methods_on_the_active_tier(): void {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_gateways' ) )
			->getMock();
		$provider->expects( $this->once() )->method( 'get_payment_gateways' )->willReturn( array( new WooPaymentsGateway() ) );
		$sut      = $this->create_sut( $provider, $this->createMock( WooPaymentsCheckoutAssets::class ) );
		$registry = new PaymentMethodRegistry();

		( new Api( $registry, $this->createMock( AssetDataRegistry::class ) ) )->register_payment_method_integrations( $registry );
		$sut->handle_woocommerce_blocks_payment_method_type_registration( $registry );

		$this->assertSame(
			array( 'cheque', 'paypal', 'bacs', 'cod', 'woocommerce_payments' ),
			array_keys( $registry->get_all_registered() ),
			'WooPayments should join the bundled integrations for the active tier.'
		);
	}

	/**
	 * @testdox Should register WooPayments right after the bundled Blocks payment methods, which Blocks registers from the callback it adds while it loads, when $_dataName.
	 *
	 * @testWith ["Blocks has loaded"]
	 *           ["Blocks loads later"]
	 *
	 * @param string $when When the registrar registers its hooks.
	 */
	public function test_registers_right_after_the_bundled_methods( string $when ): void {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_gateways' ) )
			->getMock();
		$provider->method( 'get_payment_gateways' )->willReturn( array( new WooPaymentsGateway() ) );
		$sut = $this->create_sut( $provider, $this->createMock( WooPaymentsCheckoutAssets::class ) );

		if ( 'Blocks has loaded' === $when ) {
			$sut->register();
		} else {
			global $wp_actions;
			$loaded = $wp_actions['woocommerce_blocks_loaded'] ?? null;
			// Only the registrar's callback runs; the hook snapshot restores the others when the test ends.
			remove_all_actions( 'woocommerce_blocks_loaded' );
			unset( $wp_actions['woocommerce_blocks_loaded'] );
			try {
				$sut->register();
				// Hooked directly, the callback would run before Blocks adds its own during plugins_loaded.
				$this->assertFalse( has_action( 'woocommerce_blocks_payment_method_type_registration', array( $sut, 'handle_woocommerce_blocks_payment_method_type_registration' ) ) );
				/** This action is documented in src/Blocks/Domain/Bootstrap.php */
				do_action( 'woocommerce_blocks_loaded' );
			} finally {
				if ( null === $loaded ) {
					unset( $wp_actions['woocommerce_blocks_loaded'] );
				} else {
					$wp_actions['woocommerce_blocks_loaded'] = $loaded; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
		}
		$registry = new PaymentMethodRegistry();
		/** This action is documented in src/Blocks/Integrations/IntegrationRegistry.php */
		do_action( 'woocommerce_blocks_payment_method_type_registration', $registry );

		$names = array_keys( $registry->get_all_registered() );
		$this->assertSame( array( 'cheque', 'paypal', 'bacs', 'cod', 'woocommerce_payments' ), array_slice( $names, array_search( 'cheque', $names, true ), 5 ) );
	}

	/**
	 * Create the registrar over the given provider and checkout assets.
	 *
	 * @param WooPaymentsProvider       $provider   Native WooPayments provider.
	 * @param WooPaymentsCheckoutAssets $assets     Checkout assets.
	 * @param WooPaymentsSetupTier|null $setup_tier Setup tier store, or null for an active store.
	 * @return WooPaymentsBlocksPaymentMethodsController
	 */
	private function create_sut( WooPaymentsProvider $provider, WooPaymentsCheckoutAssets $assets, ?WooPaymentsSetupTier $setup_tier = null ): WooPaymentsBlocksPaymentMethodsController {
		$woopay = $this->getMockBuilder( Eligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_woopay_button' ) )
			->getMock();
		$woopay->method( 'should_show_woopay_button' )->willReturn( false );
		$express = $this->getMockBuilder( WooPaymentsExpressCheckoutService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_show_payment_request_button' ) )
			->getMock();
		$express->method( 'should_show_payment_request_button' )->willReturn( false );

		$sut = new WooPaymentsBlocksPaymentMethodsController();
		$sut->init(
			$setup_tier ?? $this->create_setup_tier( WooPaymentsSetupTier::ACTIVE, true, WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
			$provider,
			$assets,
			$woopay,
			$express,
			wc_get_container()->get( WooPaymentsFraudPreventionService::class ),
			wc_get_container()->get( WooPaymentsFrontendAssets::class )
		);

		return $sut;
	}

	/**
	 * Create a setup tier store whose effective tier follows the given stored tier, feature switch and payments owner.
	 *
	 * @param string $stored_tier     Stored setup tier.
	 * @param bool   $feature_enabled Whether the WooPayments feature is on.
	 * @param string $owner           Payments runtime owner.
	 * @return WooPaymentsSetupTier
	 */
	private function create_setup_tier( string $stored_tier, bool $feature_enabled, string $owner ): WooPaymentsSetupTier {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_enabled', 'get_runtime_owner', 'has_run_woopayments_extension' ) )
			->getMock();
		$arbiter->method( 'is_builtin_enabled' )->willReturn( $feature_enabled );
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$arbiter->method( 'has_run_woopayments_extension' )->willReturn( false );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		$setup_tier = new WooPaymentsSetupTier();
		$setup_tier->init( $arbiter );

		return $setup_tier;
	}

	/**
	 * Run the registrar's callback on a fresh Blocks payment method registry.
	 *
	 * @param WooPaymentsBlocksPaymentMethodsController $sut Registrar.
	 * @return WooPayments[] The registered payment methods, in registration order.
	 */
	private function register_payment_methods( WooPaymentsBlocksPaymentMethodsController $sut ): array {
		$registry = new PaymentMethodRegistry();
		$sut->handle_woocommerce_blocks_payment_method_type_registration( $registry );

		return array_values( $registry->get_all_registered() );
	}
}
