<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * PaymentsController tests.
 *
 * @class PaymentsController
 */
class PaymentsControllerTest extends WC_Unit_Test_Case {
	/**
	 * Payments service mock.
	 *
	 * @var Payments|MockObject
	 */
	private $payments;

	/**
	 * WooPayments extension runtime mock.
	 *
	 * @var WooPaymentsExtensionRuntime|MockObject
	 */
	private $extension_runtime;

	/**
	 * System under test.
	 *
	 * @var PaymentsController
	 */
	private PaymentsController $sut;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->payments          = $this->createMock( Payments::class );
		$this->extension_runtime = $this->getMockBuilder( WooPaymentsExtensionRuntime::class )
			->onlyMethods( array( 'is_loaded', 'is_account_onboarded_from_cache' ) )
			->getMock();
		$this->sut               = new PaymentsController();
		$this->sut->init( $this->payments, $this->extension_runtime );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @testdox Should skip Core Payments menu when WooPayments owns the onboarded account menu.
	 */
	public function test_add_menu_skips_core_payments_menu_when_woopayments_account_is_onboarded(): void {
		global $menu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate admin menu assertions in this test.
		$menu = array();

		$this->extension_runtime
			->expects( $this->once() )
			->method( 'is_loaded' )
			->willReturn( true );
		$this->extension_runtime
			->expects( $this->once() )
			->method( 'is_account_onboarded_from_cache' )
			->willReturn( true );

		$this->sut->add_menu();

		$payment_menu_items = array_filter(
			$menu,
			static fn( $menu_item ) => is_array( $menu_item ) && isset( $menu_item[2] ) && str_starts_with( (string) $menu_item[2], 'admin.php?page=wc-settings&tab=checkout' )
		);

		$this->assertSame( array(), array_values( $payment_menu_items ) );
	}

	/**
	 * @testdox Should keep the Core Payments settings menu when the WooPayments extension runtime is not loaded.
	 */
	public function test_add_menu_keeps_core_payments_menu_when_builtin_owns_payments(): void {
		global $menu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate admin menu assertions in this test.
		$menu = array();

		$this->extension_runtime
			->expects( $this->once() )
			->method( 'is_loaded' )
			->willReturn( false );
		$this->extension_runtime
			->expects( $this->never() )
			->method( 'is_account_onboarded_from_cache' );

		$this->sut->add_menu();

		$payment_menu_items = array_values(
			array_filter(
				$menu,
				static fn( $menu_item ) => is_array( $menu_item ) && isset( $menu_item[2] ) && 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM === (string) $menu_item[2]
			)
		);

		$this->assertCount( 1, $payment_menu_items );
		$this->assertSame( 'Payments', $payment_menu_items[0][0] );
	}
}
