<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMobileRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
use ReflectionClass;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments zero-decimal currency authority.
 */
class WooPaymentsCurrencyUtilsAuthorityTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Currency utilities are the only owner of the zero-decimal currency catalog.
	 */
	public function test_currency_utils_is_the_only_zero_decimal_authority(): void {
		$consumers = array(
			WooPaymentsRefundEventHandler::class,
			WooPaymentsDisputeEventHandler::class,
			WooPaymentsOrderDataService::class,
			WooPaymentsMobileRestController::class,
			WooPaymentsPaymentDetailsRestController::class,
			WooPaymentsOrderEffects::class,
			WooPaymentsDuplicatePaymentPreventionService::class,
		);

		foreach ( $consumers as $consumer ) {
			$this->assertFalse(
				( new ReflectionClass( $consumer ) )->hasConstant( 'ZERO_DECIMAL_CURRENCIES' ),
				$consumer . ' must use WooPaymentsCurrencyUtils instead of owning a zero-decimal currency catalog.'
			);
		}
	}
}
