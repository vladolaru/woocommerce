<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Unit_Test_Case;

/**
 * Tests for the NativeWooPaymentsGateway class.
 */
class NativeWooPaymentsGatewayTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Checkout provider data should carry the WooPay save-user opt-in and fire the save action.
	 */
	public function test_checkout_provider_data_carries_woopay_save_user_opt_in(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$sut      = new NativeWooPaymentsGateway( $registry->get( 'card' ) );

		$action_fired = 0;
		add_action(
			'woocommerce_payments_save_user_in_woopay',
			function () use ( &$action_fired ): void {
				++$action_fired;
			}
		);

		$method = new \ReflectionMethod( NativeWooPaymentsGateway::class, 'get_checkout_provider_data' );
		$method->setAccessible( true );

		try {
			$_POST['save_user_in_woopay'] = 'true';
			$provider_data                = $method->invoke( $sut );

			$this->assertTrue( $provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );

			unset( $_POST['save_user_in_woopay'] );
			$bare_provider_data = $method->invoke( $sut );

			$this->assertFalse( $bare_provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );
		} finally {
			unset( $_POST['save_user_in_woopay'] );
			remove_all_actions( 'woocommerce_payments_save_user_in_woopay' );
		}
	}

	/**
	 * @testdox Should use the custom place-order button contract for payment-list wallets.
	 */
	public function test_payment_list_wallets_use_custom_place_order_button_without_ordinary_fields(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();

		foreach ( array( 'apple_pay', 'google_pay' ) as $payment_method_id ) {
			$sut = new NativeWooPaymentsGateway( $registry->get( $payment_method_id ) );

			$this->assertTrue( $sut->has_custom_place_order_button, "{$payment_method_id} should register a custom place-order button." );
			$this->assertFalse( $sut->has_fields, "{$payment_method_id} should not render ordinary payment fields." );
		}
	}

	/**
	 * @testdox Should retain ordinary fields for non-express payment methods.
	 */
	public function test_non_express_payment_methods_retain_ordinary_fields(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$sut      = new NativeWooPaymentsGateway( $registry->get( 'card' ) );

		$this->assertFalse( $sut->has_custom_place_order_button );
		$this->assertTrue( $sut->has_fields );
	}

	/**
	 * @testdox Should render country-aware definition branding for a classic split gateway.
	 */
	public function test_classic_split_gateway_icon_uses_country_aware_definition_branding(): void {
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new NativeWooPaymentsGateway( $registry->get( 'afterpay_clearpay' ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$property = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'account_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $account_service );

		$filter_calls = 0;
		$filter       = static function ( string $icon, string $gateway_id ) use ( &$filter_calls ): string {
			++$filter_calls;
			return $icon . '<span data-gateway-id="' . esc_attr( $gateway_id ) . '"></span>';
		};
		add_filter( 'woocommerce_gateway_icon', $filter, 10, 2 );

		try {
			$icon = $sut->get_icon();
		} finally {
			remove_filter( 'woocommerce_gateway_icon', $filter, 10 );
		}

		$this->assertStringContainsString( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $icon );
		$this->assertStringContainsString( 'alt="Cash App Afterpay"', $icon );
		$this->assertStringNotContainsString( '/assets/images/payment-methods/visa.svg', $icon );
		$this->assertStringContainsString( 'data-gateway-id="woocommerce_payments_afterpay_clearpay"', $icon );
		$this->assertSame( 1, $filter_calls );
	}
}
