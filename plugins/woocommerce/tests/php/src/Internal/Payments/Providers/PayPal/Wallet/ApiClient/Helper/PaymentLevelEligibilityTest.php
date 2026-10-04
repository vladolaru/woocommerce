<?php
/**
 * Tests for the Level 2/3 payment eligibility (ported from the extension's PaymentLevelEligibilityTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PaymentLevelEligibility;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * Whether a payment qualifies for Level 2/3 processing: country, currency and payment method, each behind a filter.
 *
 * The filter's default method list is GatewayIds::CREDIT_CARD. The tests use the literal ID, so they pin its value
 * and not just the constant's name.
 *
 * @group paypal-wallet
 */
class PaymentLevelEligibilityTest extends WalletTestCase {

	private const CREDIT_CARD_GATEWAY = 'ppcp-credit-card-gateway';

	/**
	 * The currency getter mock.
	 *
	 * @var CurrencyGetter&MockInterface
	 */
	private $currency_getter;

	/**
	 * Build the currency getter.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->currency_getter = $this->mock( CurrencyGetter::class );
	}

	/**
	 * @testdox Should be eligible when country, currency, payment method and the final filter all agree.
	 */
	public function test_is_eligible_when_all_conditions_met(): void {
		$this->currency_getter->expects( 'get' )->once()->andReturn( 'USD' );

		$result = ( new PaymentLevelEligibility( 'US', $this->currency_getter ) )->is_eligible( self::CREDIT_CARD_GATEWAY );

		$this->assertTrue( $result );
	}

	/**
	 * @testdox Should not be eligible in a country the filter does not allow.
	 */
	public function test_is_not_eligible_with_invalid_country(): void {
		$result = ( new PaymentLevelEligibility( 'GB', $this->currency_getter ) )->is_eligible( self::CREDIT_CARD_GATEWAY );

		$this->assertFalse( $result );
	}

	/**
	 * @testdox Should not be eligible in a currency the filter does not allow.
	 */
	public function test_is_not_eligible_with_invalid_currency(): void {
		$this->currency_getter->expects( 'get' )->once()->andReturn( 'EUR' );

		$result = ( new PaymentLevelEligibility( 'US', $this->currency_getter ) )->is_eligible( self::CREDIT_CARD_GATEWAY );

		$this->assertFalse( $result );
	}

	/**
	 * @testdox Should not be eligible for a payment method the filter does not allow.
	 */
	public function test_is_not_eligible_with_invalid_payment_method(): void {
		$this->currency_getter->expects( 'get' )->once()->andReturn( 'USD' );

		$result = ( new PaymentLevelEligibility( 'US', $this->currency_getter ) )->is_eligible( 'paypal' );

		$this->assertFalse( $result );
	}

	/**
	 * @testdox Should let a merchant filter allow the PayPal gateway for Level 2/3 processing.
	 */
	public function test_payment_method_filter_can_allow_the_paypal_gateway(): void {
		$this->currency_getter->expects( 'get' )->once()->andReturn( 'USD' );
		add_filter(
			'woocommerce_paypal_payments_level_processing_payment_methods',
			static function ( $methods ) {
				$methods[] = 'ppcp-gateway';
				return $methods;
			}
		);

		$result = ( new PaymentLevelEligibility( 'US', $this->currency_getter ) )->is_eligible( 'ppcp-gateway' );

		$this->assertTrue( $result );
	}
}
