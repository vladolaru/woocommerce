<?php
/**
 * List payouts.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * List payouts.
 *
 * @since 11.4.0
 */
final class GetDeposits extends AbstractAbility {
	protected const ROUTE            = '/wc/v3/payments/deposits';
	protected const COLLECTION_KEY   = 'deposits';
	protected const LIST_COUNT_FIELD = 'total_count';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-deposits';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'List payouts', 'woocommerce' ),
			'description'  => __( 'List payouts (Stripe deposits) with status, date-range, and currency filters. Answers \'show me my recent payouts\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => self::get_list_input_properties() + self::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Record selectors accepted by both payout routes.
	 *
	 * @since 11.4.0
	 */
	public static function get_filter_properties(): array {
		$string = array( 'type' => 'string' );
		$list   = array(
			'type'  => 'array',
			'items' => $string,
		);
		return array(
			'match'             => $string,
			'store_currency_is' => $string,
			'date_before'       => $string,
			'date_after'        => $string,
			'date_between'      => $list,
			'status_is'         => $string + array( 'description' => __( 'Payout status (paid|pending|in_transit|canceled|failed).', 'woocommerce' ) ),
			'status_is_not'     => $string,
		);
	}
}
