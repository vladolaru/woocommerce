<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyComplianceNotice;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments currency compliance notice.
 */
class WooPaymentsCurrencyComplianceNoticeTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_currency' );
		delete_option( 'woocommerce_price_num_decimals' );
		remove_all_actions( 'admin_notices' );

		parent::tearDown();
	}

	/**
	 * @testdox Should register the admin notice only when the native runtime owns registration
	 */
	public function test_registers_admin_notice_only_when_native_runtime_owns_registration(): void {
		$gated = $this->create_service( false );
		$gated->register();

		$this->assertFalse( has_action( 'admin_notices', array( $gated, 'display_isk_decimal_notice' ) ) );

		$active = $this->create_service( true );
		$active->register();

		$this->assertNotFalse( has_action( 'admin_notices', array( $active, 'display_isk_decimal_notice' ) ) );
		// Client 11.1.0 `class-wc-payments-admin.php:171`: the unsupported currency notice runs last.
		$this->assertSame( 9999, has_action( 'admin_notices', array( $active, 'display_not_supported_currency_notice' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $gated, 'display_not_supported_currency_notice' ) ) );
	}

	/**
	 * @testdox Should warn managers when the account does not support the store currency, like the client
	 *
	 * Source: plugin 11.1.0 `class-wc-payments-admin.php:249-273` with the gateway's `is_available_for_current_currency()` (`class-wc-payment-gateway-wcpay.php:1058-1069`).
	 */
	public function test_displays_unsupported_currency_notice_when_the_account_does_not_support_the_store_currency(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_currency', 'EUR' );

		$output = $this->render_unsupported_currency_notice( $this->create_service( true, array( 'usd', 'gbp' ) ) );

		$this->assertStringContainsString( '<div id="wcpay-unsupported-currency-notice" class="notice notice-warning">', $output );
		$this->assertMatchesRegularExpression( '#<b>\s*Unsupported currency:\s*</b>#', $output, 'The client prints the bold label without the currency code.' );
		$this->assertStringContainsString( 'The selected currency is not available for the country set in your WooPayments account.', $output );
	}

	/**
	 * @testdox Should not warn when the store currency is supported, the account lists no currencies, or the user cannot manage the store
	 */
	public function test_does_not_display_unsupported_currency_notice_otherwise(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_currency', 'EUR' );

		$this->assertSame( '', $this->render_unsupported_currency_notice( $this->create_service( true, array( 'usd', 'eur' ) ) ), 'A supported store currency never warns.' );
		$this->assertSame( '', $this->render_unsupported_currency_notice( $this->create_service( true, array() ) ), 'An account without currency data never warns.' );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( '', $this->render_unsupported_currency_notice( $this->create_service( true, array( 'usd' ) ) ) );
	}

	/**
	 * @testdox Should warn managers about ISK stores with non-zero price decimals like the reference client
	 */
	public function test_displays_isk_decimal_notice_only_for_misconfigured_isk_stores(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_currency', 'ISK' );
		update_option( 'woocommerce_price_num_decimals', 2 );

		$service = $this->create_service( true );

		$this->assertStringContainsString( 'wcpay-unsupported-currency-notice', $this->render_notice( $service ) );
		$this->assertStringContainsString( 'does not accept decimals', $this->render_notice( $service ) );
		// Client 11.1.0 `class-wc-payments-admin.php:299`: the bold label carries no currency code; the sentence names the currency.
		$this->assertMatchesRegularExpression( '#<b>\s*Unsupported currency:\s*</b>#', $this->render_notice( $service ) );

		update_option( 'woocommerce_price_num_decimals', 0 );

		$this->assertSame( '', $this->render_notice( $service ), 'Zero decimals is a valid ISK configuration.' );

		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_num_decimals', 2 );

		$this->assertSame( '', $this->render_notice( $service ), 'Non-ISK currencies never warn.' );
	}

	/**
	 * @testdox Should not warn shoppers or users without store management capabilities
	 */
	public function test_does_not_display_notice_without_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		update_option( 'woocommerce_currency', 'ISK' );
		update_option( 'woocommerce_price_num_decimals', 2 );

		$this->assertSame( '', $this->render_notice( $this->create_service( true ) ) );
	}

	/**
	 * Render the notice output.
	 *
	 * @param WooPaymentsCurrencyComplianceNotice $service Service under test.
	 * @return string
	 */
	private function render_notice( WooPaymentsCurrencyComplianceNotice $service ): string {
		ob_start();
		$service->display_isk_decimal_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Render the unsupported currency notice output.
	 *
	 * @param WooPaymentsCurrencyComplianceNotice $service Service under test.
	 * @return string
	 */
	private function render_unsupported_currency_notice( WooPaymentsCurrencyComplianceNotice $service ): string {
		ob_start();
		$service->display_not_supported_currency_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Create the service with a stubbed arbiter and account.
	 *
	 * @param bool     $native_register      Whether the native runtime owns registration.
	 * @param string[] $supported_currencies Customer currencies the account supports.
	 * @return WooPaymentsCurrencyComplianceNotice
	 */
	private function create_service( bool $native_register, array $supported_currencies = array() ): WooPaymentsCurrencyComplianceNotice {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'get_customer_supported_currencies' )->willReturn( $supported_currencies );

		$service = new WooPaymentsCurrencyComplianceNotice();
		$service->init( $arbiter, $account );

		return $service;
	}
}
