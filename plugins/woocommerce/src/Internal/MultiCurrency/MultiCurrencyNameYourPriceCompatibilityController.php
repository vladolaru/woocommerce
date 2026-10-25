<?php
/**
 * MultiCurrencyNameYourPriceCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Converts Name Your Price amounts between currencies: the minimum, maximum and suggested prices, and a cart item's entered
 * price, kept with the currency it was entered in and converted when the shopper switches currency.
 *
 * A cart item whose entered currency can no longer be converted is removed with an error notice.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyNameYourPriceCompatibilityController implements RegisterHooksInterface {

	private const NYP_CURRENCY_META_KEY = '_wcpay_multi_currency_nyp_currency';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Price converter, built on first use.
	 *
	 * @var MultiCurrencyPriceConverter|null
	 */
	private ?MultiCurrencyPriceConverter $price_converter = null;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

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
	 * @param MultiCurrencyRuntimeArbiter      $arbiter               Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 * @param MultiCurrencyServiceFactory      $service_factory       Multi-Currency service factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyServiceFactory $service_factory ): void {
		$this->arbiter               = $arbiter;
		$this->state_builder_factory = $state_builder_factory;
		$this->service_factory       = $service_factory;
	}

	/**
	 * Register Name Your Price compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_name_your_price_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_name_your_price_hooks();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_name_your_price_hooks' ), 20 );
	}

	/**
	 * Register Name Your Price compatibility hooks after supported runtimes have loaded.
	 *
	 * @internal
	 */
	public function register_name_your_price_hooks(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_name_your_price_runtime_available() ) {
			return;
		}

		add_filter( 'wc_nyp_raw_minimum_price', array( $this, 'get_nyp_prices' ) );
		add_filter( 'wc_nyp_raw_maximum_price', array( $this, 'get_nyp_prices' ) );
		add_filter( 'wc_nyp_raw_suggested_price', array( $this, 'get_nyp_prices' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_initial_currency' ), 20, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'convert_cart_currency' ), 20, 2 );
		add_filter( 'woocommerce_pre_remove_cart_item_from_session', array( $this, 'remove_unconvertible_cart_item' ), 10, 4 );
		add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ), 50, 2 );
		add_filter( 'wc_nyp_edit_in_cart_args', array( $this, 'edit_in_cart_args' ), 10, 2 );
		add_filter( 'wc_nyp_get_initial_price', array( $this, 'get_initial_price' ), 10, 3 );
	}

	/**
	 * Convert raw NYP prices for the selected currency.
	 *
	 * @param mixed $price Raw price.
	 * @return mixed
	 */
	public function get_nyp_prices( $price ) {
		if ( ! $price ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Store the selected currency and original NYP value when a cart item is added.
	 *
	 * @param array<mixed> $cart_item    Cart item data.
	 * @param int          $product_id   Product ID.
	 * @param int          $variation_id Variation ID.
	 * @return array<mixed>
	 */
	public function add_initial_currency( array $cart_item, int $product_id, int $variation_id ): array {
		$nyp_id = $variation_id ? $variation_id : $product_id;

		if ( ! $this->is_name_your_price_product( $nyp_id ) || ! isset( $cart_item['nyp'] ) ) {
			return $cart_item;
		}

		$cart_item['nyp_currency'] = $this->get_selected_currency_code();
		$cart_item['nyp_original'] = $cart_item['nyp'];

		return $cart_item;
	}

	/**
	 * Convert the stored NYP cart value when the selected currency changes.
	 *
	 * @param array<mixed> $cart_item Cart item data.
	 * @param array<mixed> $values    Session values.
	 * @return array<mixed>
	 */
	public function convert_cart_currency( array $cart_item, array $values ): array {
		unset( $values );

		if ( ! $this->is_name_your_price_function_available() || ! isset( $cart_item['nyp_original'], $cart_item['nyp_currency'] ) ) {
			return $cart_item;
		}

		$product = $cart_item['data'] ?? null;
		if ( $product instanceof \WC_Product ) {
			$product->update_meta_data( self::NYP_CURRENCY_META_KEY, $cart_item['nyp_currency'] );
		}

		$selected_currency_code = $this->get_selected_currency_code();

		if ( $cart_item['nyp_currency'] === $selected_currency_code ) {
			$cart_item['nyp'] = $cart_item['nyp_original'];
		} else {
			$cart_item['nyp'] = $this->get_price_converter()->get_raw_conversion(
				(float) $cart_item['nyp_original'],
				$selected_currency_code,
				(string) $cart_item['nyp_currency']
			);
		}

		return $this->set_name_your_price_cart_item( $cart_item );
	}

	/**
	 * Remove a NYP cart item whose price was entered in a currency that can no longer be converted, before the cart loads it.
	 *
	 * Removing the item with an error notice, as core does for an item that can no longer be purchased, keeps the cart
	 * usable when the conversion fails, reaches Store API carts as a cart error, and never charges the entered amount in
	 * another currency.
	 *
	 * @internal
	 *
	 * @param mixed $remove  Whether an earlier callback already removes the item.
	 * @param mixed $key     Cart item key.
	 * @param mixed $values  Cart item session values.
	 * @param mixed $product Cart item product.
	 * @return mixed
	 */
	public function remove_unconvertible_cart_item( $remove, $key, $values, $product ) {
		unset( $key );

		if ( $remove || ! is_array( $values ) || ! isset( $values['nyp_original'], $values['nyp_currency'] ) || ! $this->is_name_your_price_function_available() ) {
			return $remove;
		}

		$selected_currency_code = $this->get_selected_currency_code();
		if ( $values['nyp_currency'] === $selected_currency_code ) {
			return $remove;
		}

		try {
			$this->get_price_converter()->get_raw_conversion( (float) $values['nyp_original'], $selected_currency_code, (string) $values['nyp_currency'] );
			return $remove;
		} catch ( InvalidCurrencyException | InvalidCurrencyRateException $e ) {
			// Notice functions load with the front-end includes or wc_load_cart(), not when code calls WC()->initialize_cart() directly.
			if ( $product instanceof \WC_Product && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %1$s: product name. %2$s: product permalink. */
						__( '%1$s has been removed from your cart because its price was entered in a currency this store no longer accepts. You can add it back to your cart <a href="%2$s">here</a>.', 'woocommerce' ),
						esc_html( $product->get_name() ),
						esc_url( $product->get_permalink() )
					),
					'error'
				);
			}

			return true;
		}
	}

	/**
	 * Tell whether product-price conversion runs: not for NYP products, nor for cart items still in their entered currency.
	 *
	 * @param bool  $should_convert Existing product conversion decision.
	 * @param mixed $product        Product.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert, $product ): bool {
		$product_currency = $product instanceof \WC_Product ? (string) $product->get_meta( self::NYP_CURRENCY_META_KEY ) : '';

		// Skip cart items still in the currency their price was entered in, and NYP products.
		return $should_convert
			&& $product_currency !== $this->get_selected_currency_code()
			&& ! $this->is_name_your_price_product( $product );
	}

	/**
	 * Add selected currency to cart edit args.
	 *
	 * @param array<mixed> $args      Cart edit args.
	 * @param array<mixed> $cart_item Cart item.
	 * @return array<mixed>
	 */
	public function edit_in_cart_args( array $args, array $cart_item ): array {
		unset( $cart_item );

		$args['nyp_currency'] = $this->get_selected_currency_code();

		return $args;
	}

	/**
	 * Convert initial cart-edit price from its request currency to selected currency.
	 *
	 * @param mixed  $initial_price Initial price.
	 * @param mixed  $product       Product.
	 * @param string $suffix        Request key suffix.
	 * @return mixed
	 */
	public function get_initial_price( $initial_price, $product, string $suffix ) {
		unset( $product );

		$raw_price_key       = 'nyp_raw' . $suffix;
		$has_raw_price       = $this->has_request_value( $raw_price_key );
		$has_source_currency = $this->has_request_value( 'nyp_currency' );
		$source_currency     = $this->get_sanitized_request_value( 'nyp_currency' );
		$selected_currency   = $this->get_selected_currency_code();

		if ( ! $has_raw_price || ! $has_source_currency || $source_currency === $selected_currency ) {
			return $initial_price;
		}

		$raw_price = (float) $this->get_sanitized_request_value( $raw_price_key );

		return $this->get_price_converter()->get_raw_conversion( $raw_price, $selected_currency, $source_currency );
	}

	/**
	 * Check if Name Your Price runtime is available.
	 *
	 * @return bool
	 */
	protected function is_name_your_price_runtime_available(): bool {
		return class_exists( 'WC_Name_Your_Price' );
	}

	/**
	 * Check if the Name Your Price function is available.
	 *
	 * @return bool
	 */
	protected function is_name_your_price_function_available(): bool {
		return function_exists( 'WC_Name_Your_Price' );
	}

	/**
	 * Tell whether a product is NYP-enabled.
	 *
	 * @param mixed $product Product or product ID.
	 * @return bool
	 */
	protected function is_name_your_price_product( $product ): bool {
		$is_nyp = array( 'WC_Name_Your_Price_Helpers', 'is_nyp' );

		if ( ! class_exists( 'WC_Name_Your_Price_Helpers' ) || ! is_callable( $is_nyp ) ) {
			return false;
		}

		return (bool) call_user_func( $is_nyp, $product );
	}

	/**
	 * Run the Name Your Price cart item normalizer.
	 *
	 * @param array<mixed> $cart_item Cart item.
	 * @return array<mixed>
	 */
	protected function set_name_your_price_cart_item( array $cart_item ): array {
		if ( ! function_exists( 'WC_Name_Your_Price' ) ) {
			return $cart_item;
		}

		$name_your_price = \WC_Name_Your_Price();
		if (
			! is_object( $name_your_price )
			|| ! isset( $name_your_price->cart )
			|| ! is_object( $name_your_price->cart )
			|| ! is_callable( array( $name_your_price->cart, 'set_cart_item' ) )
		) {
			return $cart_item;
		}

		$normalized_cart_item = call_user_func( array( $name_your_price->cart, 'set_cart_item' ), $cart_item );

		return is_array( $normalized_cart_item ) ? $normalized_cart_item : $cart_item;
	}

	/**
	 * Check if a request value exists.
	 *
	 * @param string $key Request key.
	 * @return bool
	 */
	private function has_request_value( string $key ): bool {
		return isset( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooPayments-compatible cart edit request.
	}

	/**
	 * Get a sanitized scalar request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function get_sanitized_request_value( string $key ): string {
		if ( ! $this->has_request_value( $key ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooPayments-compatible cart edit request.
		$request_value = wp_unslash( $_REQUEST[ $key ] );

		if ( ! is_scalar( $request_value ) ) {
			return '';
		}

		$clean_value = wc_clean( (string) $request_value );

		return is_string( $clean_value ) ? $clean_value : '';
	}

	/**
	 * Get selected currency code.
	 *
	 * @return string
	 */
	private function get_selected_currency_code(): string {
		return $this->get_state_builder()->build()->get_selected_currency()->get_code();
	}

	/**
	 * Get the price converter, building it on first use.
	 *
	 * @return MultiCurrencyPriceConverter
	 */
	private function get_price_converter(): MultiCurrencyPriceConverter {
		if ( null === $this->price_converter ) {
			$this->price_converter = $this->service_factory->create_price_converter();
		}

		return $this->price_converter;
	}

	/**
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->get_state_builder();
		}

		return $this->state_builder;
	}
}
