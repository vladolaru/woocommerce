<?php
/**
 * Get balance summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get balance summary.
 *
 * @since 11.4.0
 */
final class GetBalance extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/reports/balance';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-balance';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get balance summary', 'woocommerce' ),
			'description'  => __( 'Return the balance summary (charges, refunds, disputes, fees, payouts, ending balance) for a currency over a date range.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'required'             => array( 'date_start', 'date_end', 'currency' ),
				'properties'           => array(
					'date_start' => array(
						'type'        => 'string',
						'description' => __( 'Start of the period (ISO-8601 date-time).', 'woocommerce' ),
					),
					'date_end'   => array(
						'type'        => 'string',
						'description' => __( 'End of the period (ISO-8601 date-time).', 'woocommerce' ),
					),
					'currency'   => array(
						'type'        => 'string',
						'description' => __( 'Three-letter ISO-4217 currency code.', 'woocommerce' ),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Refuse empty strings that satisfy the required string schema.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error Balance summary or an input or route error.
	 */
	public static function execute( $input = null ) {
		$params = (array) $input;
		foreach ( array( 'date_start', 'date_end', 'currency' ) as $field ) {
			if ( '' === ( $params[ $field ] ?? '' ) ) {
				return new \WP_Error(
					'wcpay_missing_' . $field,
					/* translators: %s: Required balance report field. */
					sprintf( __( 'A %s is required to read the balance summary.', 'woocommerce' ), $field )
				);
			}
		}
		return parent::execute( $params );
	}
}
