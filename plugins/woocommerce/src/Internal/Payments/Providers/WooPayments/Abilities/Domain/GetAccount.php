<?php
/**
 * Get WooPayments account state.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get WooPayments account state.
 *
 * @since 11.4.0
 */
final class GetAccount extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/accounts';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-account';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'       => __( 'Get WooPayments account state', 'woocommerce' ),
			'description' => __( 'Return the merchant\'s WooPayments account state: onboarding status, country, store and customer currencies, KYC requirements, deadlines, and test/live mode flags.', 'woocommerce' ),
		);
	}
}
