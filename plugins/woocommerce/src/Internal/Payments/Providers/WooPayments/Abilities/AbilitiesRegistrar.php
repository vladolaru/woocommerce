<?php
/**
 * WooPayments ability registration.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
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
		Domain\GetTransactions::class,
		Domain\GetTransactionsSummary::class,
		Domain\GetFraudOutcomes::class,
		Domain\GetBalance::class,
		Domain\GetFeesSummary::class,
		Domain\GetDisputes::class,
		Domain\GetDisputesSummary::class,
		Domain\GetDispute::class,
		Domain\GetCharge::class,
		Domain\GetPaymentIntent::class,
		Domain\GetTimeline::class,
		Domain\GetDeposits::class,
		Domain\GetDepositsSummary::class,
		Domain\RefundCharge::class,
		Domain\UploadDisputeEvidenceFile::class,
		Domain\SubmitDisputeEvidence::class,
	);

	/**
	 * Effective setup tier of the current site.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Initialize the registrar.
	 *
	 * @since 11.4.0
	 * @internal
	 *
	 * @param WooPaymentsSetupTier $setup_tier Site setup tier.
	 */
	final public function init( WooPaymentsSetupTier $setup_tier ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->setup_tier = $setup_tier;
	}

	/**
	 * Let plugins opt in any time before the first registry build.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_ability_definition_classes', array( $this, 'append_classes' ) );
	}

	/**
	 * Append provider abilities after earlier definitions when the store opts in.
	 *
	 * @since 11.4.0
	 *
	 * @internal
	 *
	 * @param mixed $classes Definitions from earlier filters.
	 * @return mixed Filtered definitions, or the original non-array value.
	 */
	public function append_classes( $classes ) {
		if ( ! is_array( $classes ) || ! $this->is_enabled_for_current_site() ) {
			return $classes;
		}

		return array_merge( $classes, self::ABILITY_CLASSES );
	}

	/**
	 * Check the current site's opt-in and effective setup tier.
	 *
	 * @since 11.4.0
	 * @internal
	 */
	public function is_enabled_for_current_site(): bool {
		/**
		 * Filters whether WooPayments registers its abilities. Only literal false disables registration.
		 *
		 * @since 11.4.0
		 *
		 * @param bool $enabled Whether to register the WooPayments abilities. Default false.
		 */
		if ( false === apply_filters( 'woocommerce_payments_abilities_enabled', false ) ) {
			return false;
		}

		return in_array( $this->setup_tier->get_effective_tier(), array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true );
	}
}
