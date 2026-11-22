<?php
/**
 * Get processing-fee summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get processing-fee summary.
 *
 * @since 11.4.0
 */
final class GetFeesSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/reports/fees/summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-fees-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get processing-fee summary', 'woocommerce' ),
			'description'  => __( 'Return aggregate processing-fee totals (count, fees, net) over a date range. Answers \'how much did I pay in fees this period?\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => array(
					'date_before'         => array( 'type' => 'string' ),
					'date_after'          => array( 'type' => 'string' ),
					'date_between'        => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'match'               => array( 'type' => 'string' ),
					'deposit_id'          => array( 'type' => 'string' ),
					'payment_method_type' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
	}
}
