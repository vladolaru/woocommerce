<?php
/**
 * MultiCurrencyTrackingController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
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
	 * HPOS enabled resolver.
	 *
	 * @var callable|null
	 */
	private $hpos_enabled_resolver = null;

	/**
	 * Runtime service factory.
	 *
	 * @var MultiCurrencyRuntimeServiceFactory
	 */
	private MultiCurrencyRuntimeServiceFactory $runtime_service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter        $arbiter                 Runtime owner arbiter.
	 * @param MultiCurrencyRuntimeServiceFactory $runtime_service_factory Runtime service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRuntimeServiceFactory $runtime_service_factory ): void {
		$this->arbiter                 = $arbiter;
		$this->runtime_service_factory = $runtime_service_factory;
	}

	/**
	 * Set the tracking projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyTrackingProjectionService $tracking_projection_service Tracking projection service.
	 */
	public function set_tracking_projection_service( MultiCurrencyTrackingProjectionService $tracking_projection_service ): void {
		$this->tracking_projection_service = $tracking_projection_service;
	}

	/**
	 * Set the HPOS enabled resolver.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param callable $hpos_enabled_resolver Resolver returning true when HPOS order storage is enabled.
	 */
	public function set_hpos_enabled_resolver( callable $hpos_enabled_resolver ): void {
		$this->hpos_enabled_resolver = $hpos_enabled_resolver;
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
		return $this->get_tracking_projection_service()->project_tracker_data( $data, $this->is_hpos_enabled() );
	}

	/**
	 * Get the tracking projection service.
	 *
	 * @return MultiCurrencyTrackingProjectionService
	 */
	private function get_tracking_projection_service(): MultiCurrencyTrackingProjectionService {
		if ( null === $this->tracking_projection_service ) {
			$this->tracking_projection_service = $this->runtime_service_factory->create_tracking_projection_service();
		}

		return $this->tracking_projection_service;
	}

	/**
	 * Tell whether HPOS order storage is enabled.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled(): bool {
		if ( null !== $this->hpos_enabled_resolver ) {
			return (bool) call_user_func( $this->hpos_enabled_resolver );
		}

		return OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
