<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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
	 * Create the service with a stubbed arbiter.
	 *
	 * @param bool $native_register Whether the native runtime owns registration.
	 * @return WooPaymentsCurrencyComplianceNotice
	 */
	private function create_service( bool $native_register ): WooPaymentsCurrencyComplianceNotice {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$service = new WooPaymentsCurrencyComplianceNotice();
		$service->init( $arbiter );

		return $service;
	}
}
