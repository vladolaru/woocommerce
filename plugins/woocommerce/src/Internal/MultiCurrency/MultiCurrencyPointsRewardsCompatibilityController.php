<?php
/**
 * MultiCurrencyPointsRewardsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;

/**
 * Registers native multi-currency Points and Rewards compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyPointsRewardsCompatibilityController implements RegisterHooksInterface {

	private const POINTS_REWARDS_DISCOUNT_DATA_CALLS = array(
		'WC_Points_Rewards_Discount->get_discount_data',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter      $arbiter               Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory ): void {
		$this->arbiter               = $arbiter;
		$this->state_builder_factory = $state_builder_factory;
	}

	/**
	 * Register Points and Rewards compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_points_rewards_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_points_rewards_filters();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_points_rewards_filters' ), 20 );
	}

	/**
	 * Register Points and Rewards compatibility filters after supported runtimes have loaded.
	 *
	 * The ratios convert outside wp-admin only.
	 *
	 * @internal
	 */
	public function register_points_rewards_filters(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_points_rewards_runtime_available() || is_admin() ) {
			return;
		}

		add_filter( 'option_wc_points_rewards_earn_points_ratio', array( $this, 'convert_points_ratio' ), 50 );
		add_filter( 'option_wc_points_rewards_redeem_points_ratio', array( $this, 'convert_points_ratio' ), 50 );
	}

	/**
	 * Convert the monetary side of a Points and Rewards ratio to the selected currency.
	 *
	 * @param string $ratio Store currency points ratio.
	 * @return string
	 */
	public function convert_points_ratio( string $ratio = '' ): string {
		$state             = $this->get_state_builder()->build();
		$selected_currency = $state->get_selected_currency();
		$default_currency  = $state->get_default_currency();

		if (
			$selected_currency->get_code() === $default_currency->get_code()
			|| MultiCurrencyRequestContext::is_call_in_backtrace( self::POINTS_REWARDS_DISCOUNT_DATA_CALLS )
		) {
			return $ratio;
		}

		// The ratio is the extension's option text, which may lack a ':' part; a missing part reads as 0.
		$parts  = explode( ':', $ratio );
		$points = (float) ( $parts[0] ?? 0 );
		$value  = (float) ( $parts[1] ?? 0 );

		return $points . ':' . ( $value * $selected_currency->get_rate() );
	}

	/**
	 * Check if Points and Rewards runtime is available.
	 *
	 * @return bool
	 */
	protected function is_points_rewards_runtime_available(): bool {
		return class_exists( 'WC_Points_Rewards' );
	}

	/**
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->create();
		}

		return $this->state_builder;
	}
}
