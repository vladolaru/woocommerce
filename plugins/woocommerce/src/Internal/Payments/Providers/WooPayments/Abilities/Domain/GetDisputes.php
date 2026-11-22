<?php
/**
 * List disputes.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * List disputes.
 *
 * @since 11.4.0
 */
final class GetDisputes extends AbstractAbility {
	protected const ROUTE          = '/wc/v3/payments/disputes';
	protected const COLLECTION_KEY = 'disputes';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-disputes';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'List disputes', 'woocommerce' ),
			'description'  => __( 'List disputes with status and date-range filters. Answers \'which disputes need response?\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => self::get_list_input_properties() + self::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Record selectors accepted by both dispute routes.
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
			'search'            => $list,
			'status_is'         => $string + array( 'description' => __( 'Dispute status (warning_needs_response|needs_response|under_review|won|lost|warning_under_review|warning_closed).', 'woocommerce' ) ),
			'status_is_not'     => $string,
		);
	}
}
