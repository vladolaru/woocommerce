<?php
/**
 * Get authorizations summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get authorizations summary.
 *
 * @since 11.4.0
 */
final class GetAuthorizationsSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/authorizations/summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-authorizations-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'       => __( 'Get authorizations summary', 'woocommerce' ),
			'description' => __( 'Return aggregate counts and total authorized amount for pending authorizations. Answers \'how much money is held in uncaptured authorizations?\'.', 'woocommerce' ),
		);
	}
}
