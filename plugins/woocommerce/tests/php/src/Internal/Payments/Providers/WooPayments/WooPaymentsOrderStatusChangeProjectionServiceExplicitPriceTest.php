<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderStatusChangeProjectionService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the `should_use_explicit_price` key the order-edit script reads for the disputed order notice.
 *
 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1040` and `components/disputed-order-notice/index.js:147,183`.
 */
class WooPaymentsOrderStatusChangeProjectionServiceExplicitPriceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderStatusChangeProjectionService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->sut = wc_get_container()->get( WooPaymentsOrderStatusChangeProjectionService::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should project should_use_explicit_price from Multi-Currency and an additional enabled currency.
	 *
	 * @testWith [true, true]
	 *           [false, false]
	 *
	 * @param bool $multi_currency_enabled Whether Multi-Currency is on.
	 * @param bool $expected               Expected projected flag.
	 */
	public function test_projects_the_explicit_price_flag( bool $multi_currency_enabled, bool $expected ): void {
		$arbiter = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_core_register' ) )
			->getMock();
		$arbiter->method( 'should_core_register' )->willReturn( $multi_currency_enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );

		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();

		$this->assertSame( $expected, $this->sut->get_config( $order )['should_use_explicit_price'] );
	}
}
