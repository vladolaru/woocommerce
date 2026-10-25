<?php
/**
 * MultiCurrencyTrackingController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackingProjectionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Registers native multi-currency tracker data when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyTrackingController implements RegisterHooksInterface {

	private const TRACKER_HOOK = 'woocommerce_tracker_data';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Tracking projection service.
	 *
	 * @var MultiCurrencyTrackingProjectionService|null
	 */
	private ?MultiCurrencyTrackingProjectionService $tracking_projection_service = null;

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
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		add_filter( self::TRACKER_HOOK, array( $this, 'add_tracker_data' ), 50 );
	}

	/**
	 * Add native multi-currency data to the WooCommerce tracker payload.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $data WooCommerce tracker data.
	 * @return array<string,mixed>
	 */
	public function add_tracker_data( array $data ): array {
		return $this->get_tracking_projection_service()->project_tracker_data( $data, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * Get the tracking projection service.
	 *
	 * @return MultiCurrencyTrackingProjectionService
	 */
	private function get_tracking_projection_service(): MultiCurrencyTrackingProjectionService {
		if ( null === $this->tracking_projection_service ) {
			$this->tracking_projection_service = $this->service_factory->create_tracking_projection_service();
		}

		return $this->tracking_projection_service;
	}
}
