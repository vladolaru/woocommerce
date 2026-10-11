<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingMinimumAmountHandler;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Minimum recurring amount for WooCommerce Subscriptions.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-subscription-minimum-amount-handler.php`
 * and `includes/subscriptions/class-wc-payments-subscription-minimum-amount-handler.php`). The USD minimum is the local
 * platform recording `get_currency_minimum_recurring_amount` in `Fixtures/rec-t63-billing-api.json`.
 */
class StripeBillingMinimumAmountHandlerTest extends WC_Unit_Test_Case {

	/**
	 * Recorded USD minimum, in cents.
	 */
	private const RECORDED_USD_MINIMUM = 100;

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingMinimumAmountHandler
	 */
	private StripeBillingMinimumAmountHandler $sut;

	/**
	 * Stripe Billing platform calls.
	 *
	 * @var StripeBillingApi&MockObject
	 */
	private $api;

	/**
	 * Set up the handler over a mocked platform, with no cached minimum.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_transient( 'WCPAY_SUBSCRIPTION_MINIMUM_RECURRING_AMOUNTS_USD' );
		delete_transient( 'WCPAY_SUBSCRIPTION_MINIMUM_RECURRING_AMOUNTS_GBP' );

		$this->api = $this->createMock( StripeBillingApi::class );
		$this->sut = new StripeBillingMinimumAmountHandler();
		$this->sut->init( $this->api );
	}

	/**
	 * @testdox Should ask the platform once a day per currency, whatever its case, and give the minimum in major units.
	 */
	public function test_gets_the_minimum_from_the_platform_once_per_currency(): void {
		$this->api->expects( $this->exactly( 2 ) )
			->method( 'get_currency_minimum_recurring_amount' )
			->willReturnMap(
				array(
					array( 'usd', self::RECORDED_USD_MINIMUM ),
					array( 'gbp', 60 ),
				)
			);

		$this->assertSame( 1.0, $this->sut->get_minimum_recurring_amount( false, 'usd' ) );
		$this->assertSame( 1.0, $this->sut->get_minimum_recurring_amount( false, 'usd' ) );
		$this->assertSame( 1.0, $this->sut->get_minimum_recurring_amount( false, 'USD' ) );
		$this->assertSame( 0.6, $this->sut->get_minimum_recurring_amount( false, 'gbp' ) );
		$this->assertSame( self::RECORDED_USD_MINIMUM, get_transient( 'WCPAY_SUBSCRIPTION_MINIMUM_RECURRING_AMOUNTS_USD' ), 'The plugin reads the same transient.' );
	}

	/**
	 * @testdox Should give and cache no minimum when the platform refuses the currency.
	 */
	public function test_caches_no_minimum_when_the_platform_refuses(): void {
		$this->api->expects( $this->once() )
			->method( 'get_currency_minimum_recurring_amount' )
			->willThrowException( new WooPaymentsApiException( 'Currency not supported.', 'invalid_request_error', 400 ) );

		$this->assertSame( 0.0, $this->sut->get_minimum_recurring_amount( false, 'usd' ) );
		$this->assertSame( 0.0, $this->sut->get_minimum_recurring_amount( false, 'usd' ) );
		$this->assertSame( 0, get_transient( 'WCPAY_SUBSCRIPTION_MINIMUM_RECURRING_AMOUNTS_USD' ) );
	}
}
