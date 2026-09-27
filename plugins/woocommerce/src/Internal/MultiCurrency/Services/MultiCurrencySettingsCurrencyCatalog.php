<?php
/**
 * MultiCurrencySettingsCurrencyCatalog class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;

/**
 * Builds the complete static currency catalog used by Multi-Currency settings.
 *
 * Runtime state intentionally contains only currencies that have usable rates.
 * This catalog keeps configured currencies manageable while they await one.
 * Authorized divergence: plan.md Decision 5; data/task-4.2-disable-providerless-design.md:27.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
final class MultiCurrencySettingsCurrencyCatalog {

	private const OPTION_PREFIX = 'wcpay_multi_currency';

	/**
	 * Localization service.
	 *
	 * @var MultiCurrencyLocalizationInterface
	 */
	private MultiCurrencyLocalizationInterface $localization_service;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder
	 */
	private MultiCurrencyStateBuilder $state_builder;

	/**
	 * Rate service.
	 *
	 * @var MultiCurrencyRateService
	 */
	private MultiCurrencyRateService $rate_service;

	/**
	 * Constructor.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization service.
	 * @param MultiCurrencyStateBuilder          $state_builder State builder.
	 * @param MultiCurrencyRateService           $rate_service Rate service.
	 */
	public function __construct( MultiCurrencyLocalizationInterface $localization_service, MultiCurrencyStateBuilder $state_builder, MultiCurrencyRateService $rate_service ) {
		$this->localization_service = $localization_service;
		$this->state_builder        = $state_builder;
		$this->rate_service         = $rate_service;
	}

	/**
	 * Get the settings currency catalog.
	 *
	 * @return array<string,mixed>
	 */
	public function get_store_currencies(): array {
		$state        = $this->state_builder->build();
		$default      = $state->get_default_currency();
		$default_code = $default->get_code();
		$runtime      = $state->get_available_currencies();
		$available    = array( $default_code => $default->jsonSerialize() );
		$catalog      = $this->get_catalog_code_lookup();

		foreach ( get_woocommerce_currencies() as $currency_code => $currency_name ) {
			unset( $currency_name );
			$currency_code = strtoupper( (string) $currency_code );
			if ( $default_code === $currency_code || ! isset( $catalog[ $currency_code ] ) ) {
				continue;
			}

			$available[ $currency_code ] = isset( $runtime[ $currency_code ] )
				? $runtime[ $currency_code ]->jsonSerialize()
				: $this->get_currency_without_rate( $currency_code );
		}

		$enabled = array( $default_code => $available[ $default_code ] );
		foreach ( $this->get_configured_currency_codes() as $currency_code ) {
			if ( $default_code !== $currency_code ) {
				$enabled[ $currency_code ] = $available[ $currency_code ];
			}
		}

		return array(
			'available'       => $available,
			'enabled'         => $enabled,
			'default'         => $available[ $default_code ],
			'automatic_rates' => $this->get_automatic_rates_descriptor(),
		);
	}

	/**
	 * Tell whether a code can be enabled: a WooCommerce currency, limited to the
	 * store currency and the rate provider's supported currencies when a provider is available.
	 *
	 * @param string $currency_code Currency code.
	 * @return bool
	 */
	public function contains( string $currency_code ): bool {
		return isset( $this->get_catalog_code_lookup()[ strtoupper( $currency_code ) ] );
	}

	/**
	 * Get the codes that can be enabled, keyed by code.
	 *
	 * Mirrors WooPayments 11.1.0 MultiCurrency::get_account_available_currencies(),
	 * with the module's rate provider standing in for the payments account.
	 *
	 * @return array<string,true>
	 */
	private function get_catalog_code_lookup(): array {
		if ( ! $this->rate_service->has_available_provider() ) {
			return array_fill_keys( array_map( 'strtoupper', array_keys( get_woocommerce_currencies() ) ), true );
		}

		$codes   = $this->rate_service->get_supported_currency_codes();
		$codes[] = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );

		return array_fill_keys( $codes, true );
	}

	/**
	 * Get known configured currency codes in their stored order.
	 *
	 * @return string[]
	 */
	public function get_configured_currency_codes(): array {
		$configured = get_option( self::OPTION_PREFIX . '_enabled_currencies', array() );
		$configured = is_array( $configured ) ? $configured : array();
		$catalog    = $this->get_catalog_code_lookup();
		$codes      = array();

		foreach ( $configured as $currency_code ) {
			if ( ! is_scalar( $currency_code ) ) {
				continue;
			}

			$currency_code = strtoupper( (string) $currency_code );
			if ( '' === $currency_code || ! isset( $catalog[ $currency_code ] ) || in_array( $currency_code, $codes, true ) ) {
				continue;
			}

			$codes[] = $currency_code;
		}

		return $codes;
	}

	/**
	 * Get automatic-rate source information for settings clients.
	 *
	 * @return array{available:bool,source:string|null}
	 */
	public function get_automatic_rates_descriptor(): array {
		return array(
			'available' => $this->rate_service->has_available_provider(),
			'source'    => $this->rate_service->get_automatic_rate_source_id(),
		);
	}

	/**
	 * Get a static currency DTO for a currency with no usable runtime rate.
	 *
	 * @param string $currency_code Currency code.
	 * @return array<string,mixed>
	 */
	private function get_currency_without_rate( string $currency_code ): array {
		$format = $this->localization_service->get_currency_format( $currency_code );

		return array(
			'id'              => strtolower( $currency_code ),
			'code'            => $currency_code,
			'name'            => get_woocommerce_currencies()[ $currency_code ],
			'rate'            => null,
			'symbol'          => get_woocommerce_currency_symbol( $currency_code ),
			'symbol_position' => (string) $format['currency_pos'],
			'is_zero_decimal' => 0 === (int) $format['num_decimals'],
			'is_default'      => false,
			'charm'           => 0.0,
			'rounding'        => '0',
			'last_updated'    => null,
		);
	}
}
