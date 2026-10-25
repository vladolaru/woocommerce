<?php
/**
 * MultiCurrencyStoreCurrencyLifecycleController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency store-currency lifecycle hooks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyStoreCurrencyLifecycleController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Store-currency lifecycle service.
	 *
	 * @var MultiCurrencyStoreCurrencyLifecycleService|null
	 */
	private ?MultiCurrencyStoreCurrencyLifecycleService $lifecycle_service = null;

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
	 * Register store-currency lifecycle hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( 'init', array( $this, 'handle_init' ), 10 );
	}

	/**
	 * Handle the init hook.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$this->get_lifecycle_service()->synchronize_store_currency();
	}

	/**
	 * Get the lifecycle service.
	 *
	 * @return MultiCurrencyStoreCurrencyLifecycleService
	 */
	private function get_lifecycle_service(): MultiCurrencyStoreCurrencyLifecycleService {
		if ( null === $this->lifecycle_service ) {
			$this->lifecycle_service = $this->service_factory->create_store_currency_lifecycle_service();
		}

		return $this->lifecycle_service;
	}
}
