<?php
/**
 * Get payouts overview.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get payouts overview.
 *
 * @since 11.4.0
 */
final class GetDepositsOverview extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/deposits/overview-all';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-deposits-overview';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'       => __( 'Get payouts overview', 'woocommerce' ),
			'description' => __( 'Return a per-currency overview of upcoming and recent payouts (Stripe deposits). Answers \'when is my next payout and how much?\'.', 'woocommerce' ),
		);
	}
}
