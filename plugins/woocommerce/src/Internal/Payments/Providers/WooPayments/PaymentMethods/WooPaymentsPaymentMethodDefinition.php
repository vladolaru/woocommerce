<?php
/**
 * WooPaymentsPaymentMethodDefinition interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods;

/**
 * Definition contract for native WooPayments payment methods.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native WooPayments settings runtime.
 */
interface WooPaymentsPaymentMethodDefinition {

	/**
	 * Get the internal payment method ID.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Get duplicate-detection keywords for the payment method.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array;

	/**
	 * Get the Stripe capability/payment method key.
	 *
	 * @return string
	 */
	public function get_stripe_id(): string;

	/**
	 * Get the account capability key that controls payment method availability.
	 *
	 * @return string
	 */
	public function get_account_capability_key(): string;

	/**
	 * Tell whether this definition should publish a WooCommerce payment gateway.
	 *
	 * Internal method definitions may still have a gateway instance for processing and settings.
	 *
	 * @return bool
	 */
	public function should_publish_gateway(): bool;

	/**
	 * Get the Stripe PaymentMethod type.
	 *
	 * @return string
	 */
	public function get_stripe_payment_method_type(): string;

	/**
	 * Get the customer-facing title.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_title( ?string $account_country = null ): string;

	/**
	 * Get the customer-facing description.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_description( ?string $account_country = null ): string;

	/**
	 * Get supported currencies.
	 *
	 * An empty array means all currencies.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string[]
	 */
	public function get_supported_currencies( ?string $account_country = null ): array;

	/**
	 * Get supported countries.
	 *
	 * An empty array means all countries.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string[]
	 */
	public function get_supported_countries( ?string $account_country = null ): array;

	/**
	 * Get payment method capabilities.
	 *
	 * @return string[]
	 */
	public function get_capabilities(): array;

	/**
	 * Get the light icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_icon_asset_path( ?string $account_country = null ): string;

	/**
	 * Get the dark icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_dark_icon_asset_path( ?string $account_country = null ): string;

	/**
	 * Get the settings icon asset path.
	 *
	 * @param string|null $account_country Optional merchant account country.
	 * @return string
	 */
	public function get_settings_icon_asset_path( ?string $account_country = null ): string;

	/**
	 * Get currency/country amount limits in minor units.
	 *
	 * @return array<string,array<string,array{min:int,max:int}>>
	 */
	public function get_limits_per_currency(): array;

	/**
	 * Tell whether the method is available for a merchant country and checkout currency.
	 *
	 * @param string $currency        Checkout currency.
	 * @param string $account_country Merchant account country.
	 * @return bool
	 */
	public function is_available_for( string $currency, string $account_country ): bool;

	/**
	 * Get the minimum amount for a currency/country pair.
	 *
	 * @param string $currency Currency code.
	 * @param string $country  Country code.
	 * @return int|null
	 */
	public function get_minimum_amount( string $currency, string $country ): ?int;

	/**
	 * Get the maximum amount for a currency/country pair.
	 *
	 * @param string $currency Currency code.
	 * @param string $country  Country code.
	 * @return int|null
	 */
	public function get_maximum_amount( string $currency, string $country ): ?int;
}
