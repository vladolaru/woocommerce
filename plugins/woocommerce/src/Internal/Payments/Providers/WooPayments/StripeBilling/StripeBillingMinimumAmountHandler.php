<?php
/**
 * StripeBillingMinimumAmountHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;

defined( 'ABSPATH' ) || exit;

/**
 * Tells WooCommerce Subscriptions the smallest recurring amount Stripe Billing can charge in a currency.
 *
 * Client 11.1.0 does the same in `includes/subscriptions/class-wc-payments-subscription-minimum-amount-handler.php`; the
 * amount is cached under its transient key, so the WooPayments extension and this code share it.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingMinimumAmountHandler {

	/**
	 * Start of the transient key holding the minimum amount of a currency; the key ends with the currency and is uppercased.
	 */
	public const MINIMUM_RECURRING_AMOUNT_TRANSIENT_KEY = 'wcpay_subscription_minimum_recurring_amounts';

	/**
	 * Stripe Billing platform calls.
	 *
	 * @var StripeBillingApi
	 */
	private StripeBillingApi $api;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingApi $api Stripe Billing platform calls.
	 */
	final public function init( StripeBillingApi $api ): void {
		$this->api = $api;
	}

	/**
	 * Get the minimum recurring amount for a currency from the platform, cached for a day; 0 when the platform refuses.
	 *
	 * The value WooCommerce Subscriptions passes in is ignored, as client 11.1.0 ignores it
	 * (class-wc-payments-subscription-minimum-amount-handler.php:67-68).
	 *
	 * @internal
	 *
	 * @param mixed $minimum_amount Minimum amount passed by the filter, or false.
	 * @param mixed $currency_code  Currency code.
	 * @return mixed The minimum amount in the currency's major unit, or the filter value when the currency is not a string.
	 */
	public function get_minimum_recurring_amount( $minimum_amount, $currency_code ) {
		if ( ! is_string( $currency_code ) ) {
			return $minimum_amount;
		}

		$transient_key  = strtoupper( self::MINIMUM_RECURRING_AMOUNT_TRANSIENT_KEY . '_' . $currency_code );
		$minimum_amount = get_transient( $transient_key );

		if ( false === $minimum_amount ) {
			try {
				$minimum_amount = $this->api->get_currency_minimum_recurring_amount( $currency_code );
			} catch ( ApiException $exception ) {
				// Currency not supported or another platform error.
				$minimum_amount = 0;
			}
			set_transient( $transient_key, $minimum_amount, DAY_IN_SECONDS );
		}

		return WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $minimum_amount, $currency_code );
	}
}
