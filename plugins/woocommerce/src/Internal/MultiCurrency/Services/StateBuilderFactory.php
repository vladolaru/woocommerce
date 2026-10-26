<?php
/**
 * StateBuilderFactory class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;

/**
 * Holds the request's shared Multi-Currency state builder, over the rate service that holds the automatic-rate provider.
 *
 * @since 11.0.0
 * @internal
 */
class StateBuilderFactory {

	/**
	 * Rate service holding the automatic-rate provider.
	 *
	 * @var RateService
	 */
	private RateService $rate_service;

	/**
	 * Request-local state invalidation coordinator.
	 *
	 * @var StateInvalidator
	 */
	private StateInvalidator $state_invalidator;

	/**
	 * Builder shared by every consumer.
	 *
	 * @var StateBuilder|null
	 */
	private ?StateBuilder $shared_builder = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param RateService      $rate_service      Rate service holding the automatic-rate provider.
	 * @param StateInvalidator $state_invalidator Request-local state invalidation coordinator.
	 */
	final public function init( RateService $rate_service, StateInvalidator $state_invalidator ): void {
		$this->rate_service      = $rate_service;
		$this->state_invalidator = $state_invalidator;
	}

	/**
	 * Get the request's shared state builder, attaching the option and user listeners that invalidate its state.
	 *
	 * Every consumer shares one builder, so the state is built once per request, with one Multi-Currency instance per
	 * request in client 11.1.0 `includes/compat/multi-currency/wc-payments-multi-currency.php:35-50`. It rebuilds after
	 * reset() and when its stored inputs change: a Multi-Currency option, the store currency, the user or the rate provider.
	 *
	 * @return StateBuilder
	 */
	public function get_state_builder(): StateBuilder {
		if ( null === $this->shared_builder ) {
			$this->shared_builder = $this->build_state_builder( new LocalizationService() );
		}

		// A listener that is missing (first call, or removed by a hook reset) may have missed a change, so rebuild too.
		$missed_changes = false;
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			if ( false === has_action( $hook, array( $this, 'handle_option_change' ) ) ) {
				add_action( $hook, array( $this, 'handle_option_change' ) );
				$missed_changes = true;
			}
		}
		if ( false === has_action( 'set_current_user', array( $this->state_invalidator, 'invalidate' ) ) ) {
			add_action( 'set_current_user', array( $this->state_invalidator, 'invalidate' ) );
			$missed_changes = true;
		}
		if ( $missed_changes ) {
			$this->state_invalidator->invalidate();
		}

		return $this->shared_builder;
	}

	/**
	 * Build a separate state builder over the given localization, outside the shared builder and its listeners.
	 *
	 * Only the Multi-Currency shadow comparison uses it, so that its reads write no locale transients.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization boundary.
	 * @return StateBuilder
	 */
	public function create_separate( MultiCurrencyLocalizationInterface $localization_service ): StateBuilder {
		return $this->build_state_builder( $localization_service );
	}

	/**
	 * Drop the shared state when an option it reads changes.
	 *
	 * @internal
	 *
	 * @param mixed $option Option name.
	 */
	public function handle_option_change( $option ): void {
		// The rate cache counts too: the store currency lifecycle clears it through its own cache instance. The builder's own
		// refresh writing it costs one more build after a refresh.
		if ( ! is_string( $option ) ) {
			return;
		}

		if ( 'woocommerce_currency' === $option || 0 === strpos( $option, 'wcpay_multi_currency_' ) ) {
			$this->state_invalidator->invalidate();
		}
	}

	/**
	 * Build a state builder over the given localization.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization boundary.
	 * @return StateBuilder
	 */
	private function build_state_builder( MultiCurrencyLocalizationInterface $localization_service ): StateBuilder {
		return new StateBuilder(
			$localization_service,
			$this->rate_service,
			new DatabaseCache(),
			$this->state_invalidator
		);
	}
}
