<?php
/**
 * MultiCurrencyCompatibilityProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Projects multi-currency compatibility metadata without registering hooks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyCompatibilityProjectionService {

	/**
	 * Project switching-disable reasons from explicit non-mutating inputs.
	 *
	 * @param array<string,mixed> $query_args               Query arguments.
	 * @param bool                $subscription_context     Whether a subscription context disables switching.
	 * @param bool                $external_filter_disabled Whether external filters disable switching.
	 * @return string[]
	 *
	 * @since 11.0.0
	 */
	public static function get_switching_disable_reasons(
		array $query_args = array(),
		bool $subscription_context = false,
		bool $external_filter_disabled = false
	): array {
		$reasons = array();

		if ( array_key_exists( 'pay_for_order', $query_args ) ) {
			$reasons[] = 'pay_for_order';
		}

		if ( $subscription_context ) {
			$reasons[] = 'subscription_context';
		}

		if ( $external_filter_disabled ) {
			$reasons[] = 'external_filter';
		}

		return $reasons;
	}

	/**
	 * Project whether currency switching should be disabled.
	 *
	 * @param array<string,mixed> $query_args               Query arguments.
	 * @param bool                $subscription_context     Whether a subscription context disables switching.
	 * @param bool                $external_filter_disabled Whether external filters disable switching.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public static function should_disable_currency_switching(
		array $query_args = array(),
		bool $subscription_context = false,
		bool $external_filter_disabled = false
	): bool {
		return ! empty( self::get_switching_disable_reasons( $query_args, $subscription_context, $external_filter_disabled ) );
	}
}
