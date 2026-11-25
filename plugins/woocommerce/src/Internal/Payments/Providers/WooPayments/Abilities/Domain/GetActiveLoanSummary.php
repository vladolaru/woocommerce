<?php
/**
 * Get active Capital loan summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get active Capital loan summary.
 *
 * @since 11.4.0
 */
final class GetActiveLoanSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/capital/active_loan_summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-active-loan-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'       => __( 'Get active Capital loan summary', 'woocommerce' ),
			'description' => __( 'Return the merchant\'s active Stripe Capital loan summary. Answers \'how much do I still owe on my Capital loan and what is the daily repayment rate?\'. Returns an empty shape if no loan is active.', 'woocommerce' ),
		);
	}
}
