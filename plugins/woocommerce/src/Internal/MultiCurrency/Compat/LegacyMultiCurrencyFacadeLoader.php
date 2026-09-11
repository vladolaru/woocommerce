<?php
/**
 * LegacyMultiCurrencyFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Loads and serves the legacy WooPayments MultiCurrency facade while Core owns the runtime.
 *
 * @since 11.0.0
 * @internal Transitional compatibility boundary scheduled for removal in WooCommerce 12.0.0.
 */
class LegacyMultiCurrencyFacadeLoader implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Request-local price projection service.
	 *
	 * @var MultiCurrencyPriceProjectionService|null
	 */
	private ?MultiCurrencyPriceProjectionService $price_projection_service = null;

	/**
	 * Request-local state builder shared by facade projections and state reads.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * Whether a facade call successfully projected a product price during this request.
	 *
	 * @var bool
	 */
	private bool $projected_product_price = false;

	/**
	 * Initialize the loader.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Set the price projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyPriceProjectionService $price_projection_service Price projection service.
	 */
	public function set_price_projection_service( MultiCurrencyPriceProjectionService $price_projection_service ): void {
		$this->price_projection_service = $price_projection_service;
	}

	/**
	 * Set the state builder.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyStateBuilder $state_builder State builder.
	 */
	public function set_state_builder( MultiCurrencyStateBuilder $state_builder ): void {
		$this->state_builder = $state_builder;
	}

	/**
	 * Schedule the facade declaration after every active plugin file has loaded.
	 */
	public function register() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->load();
			return;
		}

		if ( false === has_action( 'plugins_loaded', array( $this, 'load' ) ) ) {
			add_action( 'plugins_loaded', array( $this, 'load' ), 0 );
		}
	}

	/**
	 * Load the legacy facade when Core owns MultiCurrency.
	 */
	public function load(): void {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ) ) {
			require_once __DIR__ . '/legacy/MultiCurrency.php';
		}
	}

	/**
	 * Project a price through the native runtime and record successful product projections.
	 *
	 * @param mixed  $amount Price amount.
	 * @param string $type   Price type.
	 * @return float
	 */
	public function get_price( $amount, string $type ): float {
		$projected_amount = $this->get_price_projection_service()->get_price( $amount, $type );

		if ( 'product' === $type ) {
			$this->projected_product_price = true;
		}

		return $projected_amount;
	}

	/**
	 * Project a raw conversion through the native runtime.
	 *
	 * @param float  $amount        Amount.
	 * @param string $to_currency   Target currency code.
	 * @param string $from_currency Source currency code.
	 * @return float
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency ): float {
		if ( '' === $from_currency ) {
			$from_currency = $this->get_default_currency()->get_code();
		}

		return $this->get_price_projection_service()->get_raw_conversion( $amount, $to_currency, $from_currency );
	}

	/**
	 * Get the selected currency from native request state.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_selected_currency(): MultiCurrencyCurrency {
		return $this->get_state_builder()->build()->get_selected_currency();
	}

	/**
	 * Get the store's default currency from native request state.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_default_currency(): MultiCurrencyCurrency {
		return $this->get_state_builder()->build()->get_default_currency();
	}

	/**
	 * Tell whether the legacy facade already projected a product price in this request.
	 *
	 * @return bool
	 */
	public function did_project_product_price(): bool {
		return $this->projected_product_price;
	}

	/**
	 * Get the request-local price projection service.
	 *
	 * @return MultiCurrencyPriceProjectionService
	 */
	private function get_price_projection_service(): MultiCurrencyPriceProjectionService {
		if ( null === $this->price_projection_service ) {
			$this->price_projection_service = wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class )->create_price_projection_service( null, $this->get_state_builder() );
		}

		return $this->price_projection_service;
	}

	/**
	 * Get the request-local state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create();
		}

		return $this->state_builder;
	}
}
