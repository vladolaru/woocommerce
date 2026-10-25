<?php
/**
 * MultiCurrencyTrackerController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackerDataBuilder;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Adds the Multi-Currency part, order counts included, to the WooCommerce tracker payload.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyTrackerController implements RegisterHooksInterface {

	private const TRACKER_HOOK = 'woocommerce_tracker_data';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Tracker data builder, built on first use.
	 *
	 * @var MultiCurrencyTrackerDataBuilder|null
	 */
	private ?MultiCurrencyTrackerDataBuilder $tracker_data_builder = null;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param MultiCurrencyServiceFactory $service_factory Multi-Currency service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyServiceFactory $service_factory ): void {
		$this->arbiter         = $arbiter;
		$this->service_factory = $service_factory;
	}

	/**
	 * Register tracker data hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_filter( self::TRACKER_HOOK, array( $this, 'add_tracker_data' ), 50 );
	}

	/**
	 * Add the Multi-Currency data to the WooCommerce tracker payload.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $data WooCommerce tracker data.
	 * @return array<string,mixed>
	 */
	public function add_tracker_data( array $data ): array {
		return $this->get_tracker_data_builder()->build( $data, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * Get the tracker data builder.
	 *
	 * @return MultiCurrencyTrackerDataBuilder
	 */
	private function get_tracker_data_builder(): MultiCurrencyTrackerDataBuilder {
		if ( null === $this->tracker_data_builder ) {
			$this->tracker_data_builder = $this->service_factory->create_tracker_data_builder();
		}

		return $this->tracker_data_builder;
	}
}
