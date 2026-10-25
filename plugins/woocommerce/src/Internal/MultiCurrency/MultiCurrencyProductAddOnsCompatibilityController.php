<?php
/**
 * MultiCurrencyProductAddOnsCompatibilityController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Converts WooCommerce Product Add-Ons prices to the selected currency, and marks a product whose add-ons already
 * converted its price so it is not converted twice.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyProductAddOnsCompatibilityController implements RegisterHooksInterface {

	private const ADDONS_CONVERTED_META_KEY = '_wcpay_multi_currency_addons_converted';

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
	 * Price converter, built on first use.
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
	 * Register Product Add-ons compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( $this->is_product_addons_runtime_available() || did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_product_addons_hooks();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'register_product_addons_hooks' ), 20 );
	}

	/**
	 * Register Product Add-ons compatibility hooks after supported runtimes have loaded.
	 *
	 * Add-on prices convert on the storefront outside cron; the product page's price calculation converts in Ajax requests.
	 *
	 * @internal
	 */
	public function register_product_addons_hooks(): void {
		if ( ! $this->arbiter->is_builtin_owner() || ! $this->is_product_addons_runtime_available() ) {
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() ) {
			add_filter( 'woocommerce_product_addons_option_price_raw', array( $this, 'get_addons_price' ), 50, 2 );
			add_filter( 'woocommerce_product_addons_price_raw', array( $this, 'get_addons_price' ), 50, 2 );
			add_filter( 'woocommerce_product_addons_params', array( $this, 'product_addons_params' ), 50 );
			add_filter( 'woocommerce_product_addons_get_item_data', array( $this, 'get_item_data' ), 50, 3 );
			add_filter( 'woocommerce_product_addons_update_product_price', array( $this, 'update_product_price' ), 50, 4 );
			add_filter( 'woocommerce_product_addons_order_line_item_meta', array( $this, 'order_line_item_meta' ), 50, 4 );
			add_filter( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_product_price', array( $this, 'should_convert_product_price' ), 50, 2 );
		}

		if ( wp_doing_ajax() ) {
			add_filter( 'woocommerce_product_addons_ajax_get_product_price_including_tax', array( $this, 'get_product_calculation_price' ), 50, 3 );
			add_filter( 'woocommerce_product_addons_ajax_get_product_price_excluding_tax', array( $this, 'get_product_calculation_price' ), 50, 3 );
		}
	}

	/**
	 * Tell whether product-price conversion runs: not for a product whose add-ons already converted its price.
	 *
	 * @param bool  $should_convert Existing product conversion decision.
	 * @param mixed $product        Product.
	 * @return bool
	 */
	public function should_convert_product_price( bool $should_convert, $product ): bool {
		return $should_convert
			&& ! ( $product instanceof \WC_Product && 1 === (int) $product->get_meta( self::ADDONS_CONVERTED_META_KEY ) );
	}

	/**
	 * Convert raw add-on prices for the selected currency.
	 *
	 * @param mixed $price Raw add-on price.
	 * @param mixed $type  Add-on type metadata.
	 * @return mixed
	 */
	public function get_addons_price( $price, $type ) {
		$price_type = is_array( $type ) ? (string) ( $type['price_type'] ?? '' ) : '';

		// A percentage-based add-on's price is a percentage, not an amount.
		if ( 'percentage_based' === $price_type ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Update Product Add-ons script params with selected-currency formatting.
	 *
	 * @param array<mixed> $params Product Add-ons params.
	 * @return array<mixed>
	 */
	public function product_addons_params( array $params ): array {
		$params['currency_format_num_decimals'] = wc_get_price_decimals();
		$params['currency_format_decimal_sep']  = wc_get_price_decimal_separator();
		$params['currency_format_thousand_sep'] = wc_get_price_thousand_separator();

		return $params;
	}

	/**
	 * Rebuild cart item add-on display data with converted price labels.
	 *
	 * @param array<mixed> $addon_data Existing add-on data.
	 * @param array<mixed> $addon      Add-on data.
	 * @param array<mixed> $cart_item  Cart item data.
	 * @return array<mixed>
	 */
	public function get_item_data( $addon_data, $addon, $cart_item ): array {
		$price              = $cart_item['addons_price_before_calc'] ?? ( $addon['price'] ?? 0 );
		$value              = (string) ( $addon['value'] ?? '' );
		$display            = (string) ( $addon['display'] ?? '' );
		$addon_price        = $addon['price'] ?? 0;
		$addon_price_type   = (string) ( $addon['price_type'] ?? '' );
		$addon_field_type   = (string) ( $addon['field_type'] ?? '' );
		$add_price_to_value = $this->should_add_cart_price_to_value( $cart_item );

		if ( 0.0 === (float) $addon_price ) {
			$value .= '';
		} elseif ( 'percentage_based' === $addon_price_type && 0.0 === (float) $price ) {
			$value .= '';
		} elseif ( 'custom_price' === $addon_field_type && $addon_price ) {
			$formatted_price = $this->format_addon_price( $addon_price, $cart_item['data'] ?? null );
			/* translators: %1$s custom addon price in cart */
			$value  .= sprintf( _x( ' (%1$s)', 'custom price addon price in cart', 'woocommerce' ), $formatted_price );
			$display = $value;
		} elseif ( 'flat_fee' === $addon_price_type && $addon_price ) {
			$formatted_price = $this->format_addon_price( $this->get_addon_display_price( $addon ), $cart_item['data'] ?? null );
			/* translators: %1$s flat fee addon price in cart */
			$value .= sprintf( _x( ' (+ %1$s)', 'flat fee addon price in cart', 'woocommerce' ), $formatted_price );
		} elseif ( 'quantity_based' === $addon_price_type && $addon_price && $add_price_to_value ) {
			$formatted_price = $this->format_addon_price( $this->get_addon_display_price( $addon ), $cart_item['data'] ?? null );
			/* translators: %1$s addon price in cart */
			$value .= sprintf( _x( ' (%1$s)', 'quantity based addon price in cart', 'woocommerce' ), $formatted_price );
		} elseif ( 'percentage_based' === $addon_price_type && $addon_price && $add_price_to_value ) {
			$product = $this->get_product_by_id( (int) ( $cart_item['product_id'] ?? 0 ) );
			if ( null !== $product ) {
				$converted_price = $this->get_price_converter()->get_price( $price, 'product' );
				// @phpstan-ignore argument.type (set_price() formats a float price itself, through wc_format_decimal())
				$product->set_price( $converted_price * ( (float) $addon_price / 100 ) );
				// @phpstan-ignore argument.type (the extension writes the integer 1, client WooCommerceProductAddOns.php:163; meta keeps any scalar)
				$product->update_meta_data( self::ADDONS_CONVERTED_META_KEY, 1 );

				$cart_price = WC()->cart->get_product_price( $product );
				/* translators: %1$s addon price in cart */
				$value .= sprintf( _x( ' (%1$s)', 'percentage based addon price in cart', 'woocommerce' ), $cart_price );
			}
		}

		return array(
			'name'    => $addon['name'] ?? ( $addon_data['name'] ?? '' ),
			'value'   => $value,
			'display' => $display,
		);
	}

	/**
	 * Update Product Add-ons calculated product prices for the selected currency.
	 *
	 * @param array<mixed> $updated_prices Prices updated by Product Add-ons.
	 * @param array<mixed> $cart_item      Cart item data.
	 * @param array<mixed> $prices         Original prices.
	 * @param mixed        $product        Product.
	 * @return array<string,float>
	 */
	public function update_product_price( $updated_prices, $cart_item, $prices, $product = null ): array {
		unset( $updated_prices, $product );

		$price                       = $this->get_price_converter()->get_price( $prices['price'] ?? 0, 'product' );
		$regular_price               = $this->get_price_converter()->get_price( $prices['regular_price'] ?? 0, 'product' );
		$sale_price                  = $this->get_price_converter()->get_price( $prices['sale_price'] ?? 0, 'product' );
		$flat_fees                   = 0.0;
		$quantity                    = (float) ( $cart_item['quantity'] ?? 0 );
		$price_before_addons         = $price;
		$regular_price_before_addons = $regular_price;
		$sale_price_before_addons    = $sale_price;

		if ( empty( $price ) ) {
			$credit_amount = $this->get_smart_coupons_credit_amount( $cart_item );
			if ( null !== $credit_amount ) {
				$price         = $credit_amount;
				$regular_price = $credit_amount;
				$sale_price    = $credit_amount;
			}
		}

		foreach ( (array) ( $cart_item['addons'] ?? array() ) as $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}

			$addon_price = $this->get_addon_calculation_price( $addon );
			$price_type  = (string) ( $addon['price_type'] ?? '' );

			switch ( $price_type ) {
				case 'percentage_based':
					$price         += (float) ( $price_before_addons * ( $addon_price / 100 ) );
					$regular_price += (float) ( $regular_price_before_addons * ( $addon_price / 100 ) );
					$sale_price    += (float) ( $sale_price_before_addons * ( $addon_price / 100 ) );
					break;
				case 'flat_fee':
					$flat_fee       = $quantity > 0 ? (float) ( $addon_price / $quantity ) : 0.0;
					$price         += $flat_fee;
					$regular_price += $flat_fee;
					$sale_price    += $flat_fee;
					$flat_fees     += $flat_fee;
					break;
				default:
					$price         += (float) $addon_price;
					$regular_price += (float) $addon_price;
					$sale_price    += (float) $addon_price;
					break;
			}
		}

		$cart_product = $cart_item['data'] ?? null;
		if ( $cart_product instanceof \WC_Product ) {
			// @phpstan-ignore argument.type (the extension writes the integer 1, client WooCommerceProductAddOns.php:245; meta keeps any scalar)
			$cart_product->update_meta_data( self::ADDONS_CONVERTED_META_KEY, 1 );
		}

		return array(
			'price'                => $price,
			'regular_price'        => $regular_price,
			'sale_price'           => $sale_price,
			'addons_flat_fees_sum' => $flat_fees,
		);
	}

	/**
	 * Rebuild order line item add-on meta with converted display prices.
	 *
	 * @param array<mixed> $meta_data Existing meta data.
	 * @param array<mixed> $addon     Add-on data.
	 * @param mixed        $item      Order item.
	 * @param array<mixed> $values    Order item values.
	 * @return array<mixed>
	 */
	public function order_line_item_meta( array $meta_data, array $addon, $item, array $values ): array {
		$add_price_to_value = $this->should_add_order_price_to_value( $item );
		$value              = isset( $addon['timestamp'] ) ? $addon['timestamp'] : ( $addon['value'] ?? '' );
		$addon_price        = $addon['price'] ?? 0;
		$addon_price_type   = (string) ( $addon['price_type'] ?? '' );
		$addon_field_type   = (string) ( $addon['field_type'] ?? '' );

		if ( $addon_price && $add_price_to_value ) {
			$product          = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$calculated_price = $this->get_order_line_item_addon_price( $addon, $product );
			$formatted_price  = html_entity_decode(
				wp_strip_all_tags( $this->format_addon_price( $calculated_price, $values['data'] ?? null ) ),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);

			if ( 'flat_fee' === $addon_price_type ) {
				/* translators: %1$s flat fee addon price in order */
				$value .= sprintf( _x( ' (+ %1$s)', 'flat fee addon price in order', 'woocommerce' ), $formatted_price );
			} elseif ( 'quantity_based' === $addon_price_type || 'percentage_based' === $addon_price_type ) {
				/* translators: %1$s addon price in order */
				$value .= sprintf( _x( ' (%1$s)', 'addon price in order', 'woocommerce' ), $formatted_price );
			} elseif ( 'custom_price' === $addon_field_type ) {
				/* translators: %1$s custom addon price in order */
				$value = sprintf( _x( ' (%1$s)', 'custom addon price in order', 'woocommerce' ), $formatted_price );
			}

			$meta_data['raw_price'] = $this->get_price_converter()->get_price( $addon_price, 'product' );
		}

		$meta_data['value'] = $value;

		return $meta_data;
	}

	/**
	 * Convert Product Add-ons Ajax calculation prices by unit price.
	 *
	 * @param float       $price    Price.
	 * @param int         $quantity Quantity.
	 * @param \WC_Product $product  Product.
	 * @return float
	 */
	public function get_product_calculation_price( float $price, int $quantity, \WC_Product $product ): float {
		unset( $product );

		if ( $quantity <= 0 ) {
			return $price;
		}

		return $this->get_price_converter()->get_price( $price / $quantity, 'product' ) * $quantity;
	}

	/**
	 * Check if Product Add-ons runtime is available.
	 *
	 * @return bool
	 */
	protected function is_product_addons_runtime_available(): bool {
		return class_exists( 'WC_Product_Addons' );
	}

	/**
	 * Get a product by ID.
	 *
	 * @param int $product_id Product ID.
	 * @return \WC_Product|null
	 */
	protected function get_product_by_id( int $product_id ): ?\WC_Product {
		$product = wc_get_product( $product_id );

		return $product ? $product : null;
	}

	/**
	 * Format an add-on display price.
	 *
	 * @param mixed $price   Price.
	 * @param mixed $product Product.
	 * @return string
	 */
	protected function format_addon_price( $price, $product ): string {
		$display_price = $price;
		$helper        = array( 'WC_Product_Addons_Helper', 'get_product_addon_price_for_display' );

		if ( class_exists( 'WC_Product_Addons_Helper' ) && is_callable( $helper ) ) {
			$display_price = call_user_func( $helper, $price, $product );
		}

		return wc_price( $display_price );
	}

	/**
	 * Check if cart item values should include add-on prices.
	 *
	 * @param array<mixed> $cart_item Cart item.
	 * @return bool
	 */
	protected function should_add_cart_price_to_value( array $cart_item ): bool {
		/**
		 * Filters whether Product Add-ons cart item values should include add-on prices.
		 *
		 * @param bool         $add_price_to_value Whether to include add-on prices in cart item values.
		 * @param array<mixed> $cart_item          Cart item data.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( 'woocommerce_addons_add_cart_price_to_value', false, $cart_item );
	}

	/**
	 * Check if order item values should include add-on prices.
	 *
	 * @param mixed $item Order item.
	 * @return bool
	 */
	protected function should_add_order_price_to_value( $item ): bool {
		/**
		 * Filters whether Product Add-ons order item values should include add-on prices.
		 *
		 * @param bool  $add_price_to_value Whether to include add-on prices in order item values.
		 * @param mixed $item               Order item.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( 'woocommerce_addons_add_order_price_to_value', false, $item );
	}

	/**
	 * Get a converted add-on display price.
	 *
	 * A quantity add-on's price is split per unit, converted, then multiplied back by the entered quantity.
	 *
	 * @param array<mixed> $addon Add-on data.
	 * @return float
	 */
	private function get_addon_display_price( array $addon ): float {
		$price = (float) ( $addon['price'] ?? 0 );
		$value = (float) ( $addon['value'] ?? 0 );

		if ( 'input_multiplier' === (string) ( $addon['field_type'] ?? '' ) && 0.0 !== $value ) {
			return $this->get_price_converter()->get_price( $price / $value, 'product' ) * $value;
		}

		return $this->get_price_converter()->get_price( $price, 'product' );
	}

	/**
	 * Get an add-on price for product price calculation.
	 *
	 * @param array<mixed> $addon Add-on data.
	 * @return float
	 */
	private function get_addon_calculation_price( array $addon ): float {
		$price      = (float) ( $addon['price'] ?? 0 );
		$field_type = (string) ( $addon['field_type'] ?? '' );
		$price_type = (string) ( $addon['price_type'] ?? '' );

		if ( 'percentage_based' === $price_type || 'custom_price' === $field_type ) {
			return $price;
		}

		return $this->get_addon_display_price( $addon );
	}

	/**
	 * Get an add-on price for order line item display.
	 *
	 * @param array<mixed> $addon   Add-on data.
	 * @param mixed        $product Product.
	 * @return float
	 */
	private function get_order_line_item_addon_price( array $addon, $product ): float {
		$price      = (float) ( $addon['price'] ?? 0 );
		$field_type = (string) ( $addon['field_type'] ?? '' );
		$price_type = (string) ( $addon['price_type'] ?? '' );

		if ( 'percentage_based' === $price_type && $product instanceof \WC_Product && 0.0 !== (float) $product->get_price() ) {
			return (float) $product->get_price() * ( $price / 100 );
		}

		if ( 'custom_price' === $field_type ) {
			return $price;
		}

		return $this->get_addon_display_price( $addon );
	}

	/**
	 * Get Smart Coupons self-declared gift credit amount.
	 *
	 * @param array<mixed> $cart_item Cart item data.
	 * @return float|null
	 */
	private function get_smart_coupons_credit_amount( array $cart_item ): ?float {
		$credit_called = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reads third-party cart price fixture during Product Add-ons calculation.
		if ( isset( $_POST['credit_called'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized recursively by wc_clean after wp_unslash.
			$credit_called = wc_clean( wp_unslash( $_POST['credit_called'] ) );
		}

		if ( is_array( $credit_called ) ) {
			$product_id = $this->get_cart_item_product_id( $cart_item );
			if ( null !== $product_id && isset( $credit_called[ $product_id ] ) ) {
				return (float) $credit_called[ $product_id ];
			}
		}

		return ! empty( $cart_item['credit_amount'] ) ? (float) $cart_item['credit_amount'] : null;
	}

	/**
	 * Get the cart item product ID.
	 *
	 * @param array<mixed> $cart_item Cart item data.
	 * @return int|null
	 */
	private function get_cart_item_product_id( array $cart_item ): ?int {
		$product = $cart_item['data'] ?? null;

		return $product instanceof \WC_Product ? $product->get_id() : null;
	}

	/**
	 * Get the price converter.
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
