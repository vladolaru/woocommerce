<?php
/**
 * WooPayments ability registration.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Attach WooPayments definitions when WordPress builds the abilities registry.
 *
 * @since 11.4.0
 */
final class AbilitiesRegistrar implements RegisterHooksInterface {
	private const ABILITY_CLASSES = array(
		Domain\GetAccount::class,
		Domain\GetDepositsOverview::class,
		Domain\GetActiveLoanSummary::class,
		Domain\GetAuthorizations::class,
		Domain\GetAuthorizationsSummary::class,
	);

	/**
	 * Let plugins opt in any time before the first registry build.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_ability_definition_classes', array( $this, 'append_classes' ) );
	}

	/**
	 * Append provider reads after earlier definitions when the store opts in.
	 *
	 * @since 11.4.0
	 *
	 * @internal
	 *
	 * @param mixed $classes Definitions from earlier filters.
	 * @return mixed Filtered definitions, or the original non-array value.
	 */
	public function append_classes( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		/**
		 * Filters whether WooPayments registers its abilities. Only literal false disables registration.
		 *
		 * @since 11.4.0
		 *
		 * @param bool $enabled Whether to register the WooPayments abilities. Default false.
		 */
		if ( false === apply_filters( 'woocommerce_payments_abilities_enabled', false ) ) {
			return $classes;
		}

		return array_merge( $classes, self::ABILITY_CLASSES );
	}
}
