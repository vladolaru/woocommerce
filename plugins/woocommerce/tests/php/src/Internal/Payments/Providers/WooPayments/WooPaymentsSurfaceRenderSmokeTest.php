<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Unit_Test_Case;

/**
 * Render smoke tests for the shopper-facing surfaces the native WooPayments runtime must not break.
 *
 * These cover the contracts that the storefront stays reachable and the My Account entry surface keeps
 * working while the native payments runtime owns payments. They assert renderability and the absence of
 * fatals, not payment behaviour, and mostly require no payment provider; the one test that needs the
 * gateway to resolve as available stubs only WooPaymentsProvider::can_process_payments(), documented on
 * that test.
 */
class WooPaymentsSurfaceRenderSmokeTest extends WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @testdox Should confirm the native runtime is actually enabled, so the surface assertions are not vacuous.
	 */
	public function test_native_runtime_is_enabled_for_these_assertions(): void {
		$this->assertTrue(
			$this->get_arbiter()->is_native_runtime_enabled(),
			'The native payments runtime must be enabled, otherwise the surface assertions in this class prove nothing about it.'
		);
	}

	/**
	 * @testdox Should keep the public storefront product listing renderable while the native runtime is enabled.
	 */
	public function test_storefront_product_listing_renders_with_native_runtime_enabled(): void {
		$product = \WC_Helper_Product::create_simple_product();

		$markup = do_shortcode( '[products limit="1"]' );

		$this->assertIsString( $markup, 'The storefront product listing must render to markup.' );
		$this->assertNotSame( '', trim( $markup ), 'The storefront product listing must not render empty while native payments is enabled.' );

		$product->delete( true );
	}

	/**
	 * @testdox Should resolve available payment gateways on the storefront without error while the native runtime is enabled.
	 */
	public function test_available_payment_gateways_resolve_on_the_storefront(): void {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		$this->assertIsArray( $gateways, 'Available payment gateway resolution must not fail while native payments is enabled.' );

		foreach ( $gateways as $gateway_id => $gateway ) {
			$this->assertInstanceOf(
				\WC_Payment_Gateway::class,
				$gateway,
				"Available gateway '{$gateway_id}' must be a usable gateway object while native payments is enabled."
			);
			$this->assertTrue(
				$gateway->is_available(),
				"Available gateway '{$gateway_id}' must report itself as available."
			);
		}
	}

	/**
	 * @testdox Should keep the My Account entry surface renderable for an authenticated customer.
	 */
	public function test_my_account_entry_surface_renders_for_authenticated_customer(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$markup = do_shortcode( '[woocommerce_my_account]' );

		$this->assertIsString( $markup, 'The My Account entry surface must render to markup.' );
		$this->assertNotSame( '', trim( $markup ), 'The My Account entry surface must not render empty while native payments is enabled.' );
	}

	/**
	 * @testdox Should keep the core My Account navigation intact while the native runtime is enabled.
	 */
	public function test_my_account_navigation_remains_intact(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$menu_items = wc_get_account_menu_items();

		// The saved payment methods entry is deliberately not asserted here: WooCommerce hides it unless a
		// tokenization-capable gateway is configured, which is a separate contract covered by the gateway's
		// saved-card support tests.
		foreach ( array( 'dashboard', 'orders', 'edit-account', 'customer-logout' ) as $expected_item ) {
			$this->assertArrayHasKey(
				$expected_item,
				$menu_items,
				"The core My Account entry '{$expected_item}' must survive while native payments is enabled."
			);
		}
	}

	/**
	 * @testdox Should offer the My Account payment-methods menu entry once native saved cards are enabled.
	 *
	 * Unlike the other smokes in this class, this one needs a real, available `NativeWooPaymentsGateway`
	 * instance in `WC_Payment_Gateways`: `wc_get_account_menu_items()` only offers the entry for a
	 * gateway `WC_Payment_Gateways::get_available_payment_gateways()` reports, and availability in turn
	 * requires `WooPaymentsProvider::can_process_payments()`. Only that provider dependency is stubbed
	 * (through the container, following the `wc_payment_gateways_initialized` injection pattern already
	 * used by WooPaymentsMoneyMovementRestControllerTest); the gateway itself, its settings, and core's
	 * menu-building all stay real.
	 */
	public function test_my_account_menu_offers_payment_methods_when_native_saved_cards_enabled(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$provider = $this->createMock( WooPaymentsProvider::class );
		$provider->method( 'can_process_payments' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsProvider::class, $provider );
		wc_get_container()->reset_all_resolved();

		$settings_filter = static function () {
			return array(
				'enabled'     => 'yes',
				'saved_cards' => 'yes',
				'test_mode'   => 'yes',
			);
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );

		$gateway_initializer = static function ( \WC_Payment_Gateways $wc_payment_gateways ): void {
			$wc_payment_gateways->payment_gateways = array( new NativeWooPaymentsGateway() );
		};
		add_action( 'wc_payment_gateways_initialized', $gateway_initializer, 100 );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();

		try {
			$menu_items = wc_get_account_menu_items();
		} finally {
			remove_action( 'wc_payment_gateways_initialized', $gateway_initializer, 100 );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $settings_filter );
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}

		$this->assertArrayHasKey(
			'payment-methods',
			$menu_items,
			'My Account must offer a payment-methods entry once native WooPayments reports saved-card support.'
		);
	}

	/**
	 * Get the native payments runtime arbiter from the container.
	 *
	 * @return NativePaymentsRuntimeArbiter
	 */
	private function get_arbiter(): NativePaymentsRuntimeArbiter {
		return wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
	}
}
