<?php
/**
 * MultiCurrencyDepositsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Registers native multi-currency Deposits compatibility hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyDepositsCompatibilityController implements RegisterHooksInterface {

	private const DEPOSITS_FORM_OUTPUT_CALLS = array(
		'WC_Deposits_Cart_Manager->deposits_form_output',
	);

	private const CART_TOTALS_CALLS = array(
		'WC_Cart->calculate_totals',
	);

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
	 * Price projection service.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

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
	 * Register Deposits compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		if ( $this->is_deposits_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_deposits_hooks();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_deposits_hooks' ), 20 );
	}

	/**
	 * Register Deposits compatibility hooks after supported runtimes have loaded.
	 *
	 * Deposits 2.0.1 and later convert their own amounts, so the hooks register only for earlier versions.
	 *
	 * @internal
	 */
	public function register_deposits_hooks(): void {
		$deposits_version = $this->get_deposits_version();

		if (
			! $this->arbiter->should_core_register()
			|| ! $this->is_deposits_runtime_available()
			|| ( null !== $deposits_version && version_compare( $deposits_version, '2.0.1', '>=' ) )
		) {
			return;
		}

		add_filter( 'woocommerce_get_cart_contents', array( $this, 'modify_cart_item_deposit_amounts' ) );
		add_filter( 'woocommerce_product_get__wc_deposit_amount', array( $this, 'modify_cart_item_deposit_amount_meta' ), 10, 2 );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'maybe_convert_product_prices_for_deposits' ), 10, 2 );
		add_action( 'woocommerce_deposits_create_order', array( $this, 'modify_order_currency' ) );
	}

	/**
	 * Convert deposit amounts on cart items for the selected currency.
	 *
	 * @param array<mixed> $cart_contents Cart contents.
	 * @return array<mixed>
	 */
	public function modify_cart_item_deposit_amounts( array $cart_contents ): array {
		if ( wc_get_container()->get( WooPaymentsExtensionMultiCurrencyFacadeLoader::class )->did_convert_product_price() ) {
			return $cart_contents;
		}

		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			if ( is_array( $cart_item ) && ! empty( $cart_item['is_deposit'] ) && array_key_exists( 'deposit_amount', $cart_item ) ) {
				$cart_contents[ $cart_item_key ]['deposit_amount'] = $this->get_price_converter()->get_price(
					(float) $cart_item['deposit_amount'],
					'product'
				);
			}
		}

		return $cart_contents;
	}

	/**
	 * Convert percent-based deposit amount meta while Deposits renders its form.
	 *
	 * @param mixed $amount  Amount.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function modify_cart_item_deposit_amount_meta( $amount, $product ) {
		if (
			'percent' !== $this->get_product_deposit_type( $product )
			|| ! MultiCurrencyRequestContext::is_call_in_backtrace( self::DEPOSITS_FORM_OUTPUT_CALLS )
		) {
			return $amount;
		}

		return $this->get_price_converter()->get_price( (float) $amount, 'product' );
	}

	/**
	 * Tell whether native product-price conversion should run during Deposits calculations.
	 *
	 * @param bool  $result  Existing product conversion decision.
	 * @param mixed $product Product.
	 * @return bool
	 */
	public function maybe_convert_product_prices_for_deposits( bool $result, $product ): bool {
		if ( ! $result ) {
			return false;
		}

		return 'plan' !== $this->get_product_deposit_type( $product )
			|| ! MultiCurrencyRequestContext::is_call_in_backtrace( self::CART_TOTALS_CALLS );
	}

	/**
	 * Align remaining-payment order currency to the original deposited order currency.
	 *
	 * @param int $order_id Created order ID.
	 */
	public function modify_order_currency( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_items = $order->get_items();
		if ( empty( $order_items ) ) {
			return;
		}

		$first_order_item = reset( $order_items );
		if ( ! $first_order_item instanceof \WC_Order_Item ) {
			return;
		}

		$original_order_id = wc_get_order_item_meta( $first_order_item->get_id(), '_original_order_id', true );
		if ( ! $original_order_id ) {
			return;
		}

		$original_order = wc_get_order( (int) $original_order_id );
		if ( ! $original_order instanceof \WC_Order ) {
			return;
		}

		$saved_currency    = $order->get_currency( 'view' );
		$original_currency = $original_order->get_currency( 'view' );

		if ( '' !== $saved_currency && '' !== $original_currency && $saved_currency !== $original_currency ) {
			$order->set_currency( $original_currency );
			$order->save();
		}
	}

	/**
	 * Check if Deposits runtime is available.
	 *
	 * @return bool
	 */
	protected function is_deposits_runtime_available(): bool {
		return class_exists( 'WC_Deposits' );
	}

	/**
	 * Get Deposits version.
	 *
	 * @return string|null
	 */
	protected function get_deposits_version(): ?string {
		return defined( 'WC_DEPOSITS_VERSION' ) ? (string) constant( 'WC_DEPOSITS_VERSION' ) : null;
	}

	/**
	 * Gets the deposit type of a product if deposits are enabled for the product.
	 *
	 * @param mixed $product Product.
	 * @return string|false
	 */
	protected function get_product_deposit_type( $product ) {
		$deposits_enabled = array( 'WC_Deposits_Product_Manager', 'deposits_enabled' );
		$get_deposit_type = array( 'WC_Deposits_Product_Manager', 'get_deposit_type' );

		if (
			! class_exists( 'WC_Deposits_Product_Manager' )
			|| ! is_callable( $deposits_enabled )
			|| ! is_callable( $get_deposit_type )
		) {
			return false;
		}

		if ( ! call_user_func( $deposits_enabled, $product ) ) {
			return false;
		}

		return (string) call_user_func( $get_deposit_type, $product );
	}

	/**
	 * Get the price projection service.
	 *
	 * @return MultiCurrencyPriceConverter
	 */
	private function get_price_converter(): MultiCurrencyPriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = $this->service_factory->create_price_converter();
		}

		return $this->price_converter;
	}
}
