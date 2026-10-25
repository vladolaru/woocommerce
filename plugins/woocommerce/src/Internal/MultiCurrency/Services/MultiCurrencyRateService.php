<?php
/**
 * MultiCurrencyRateService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;

/**
 * Gives exchange rates: a currency's stored manual rate, or the rate from the automatic-rate provider that WooPayments
 * sets; and tells whether a provider is set, available and connected.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyRateService {

	/**
	 * Request-local state invalidation coordinator.
	 *
	 * @var MultiCurrencyStateInvalidator
	 */
	private MultiCurrencyStateInvalidator $state_invalidator;

	/**
	 * The automatic-rate provider, or null until one is set.
	 *
	 * @var CurrencyRateProviderInterface|null
	 */
	private ?CurrencyRateProviderInterface $provider = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyStateInvalidator $state_invalidator Request-local state invalidation coordinator.
	 */
	final public function init( MultiCurrencyStateInvalidator $state_invalidator ): void {
		$this->state_invalidator = $state_invalidator;
	}

	/**
	 * Set the automatic-rate provider, and invalidate the currency state built without it.
	 *
	 * The WooPayments provider calls this when its Multi-Currency classes register. It is reachable through the container
	 * but is not a supported extension point: the last caller wins, and a supported way to add a rate source would be a
	 * filter where this service takes its provider.
	 *
	 * @internal
	 *
	 * @param CurrencyRateProviderInterface $provider Automatic-rate provider.
	 *
	 * @since 11.2.0
	 */
	public function set_provider( CurrencyRateProviderInterface $provider ): void {
		$this->provider = $provider;
		$this->state_invalidator->invalidate();
	}

	/**
	 * Tell whether an automatic-rate provider is available.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function has_available_provider(): bool {
		return null !== $this->get_available_provider();
	}

	/**
	 * Tell whether an automatic-rate provider is set.
	 *
	 * @return bool
	 *
	 * @since 11.2.0
	 */
	public function has_provider(): bool {
		return null !== $this->provider;
	}

	/**
	 * Get the source of automatic rates: the provider's ID, also while it is unavailable, so settings can describe the
	 * outage without treating automatic rates as having no source.
	 *
	 * @return string|null
	 *
	 * @since 11.2.0
	 */
	public function get_automatic_rate_source_id(): ?string {
		return null === $this->provider ? null : $this->provider->get_id();
	}

	/**
	 * Tell whether the provider's account is connected; false without a provider or when the provider fails.
	 *
	 * @return bool
	 *
	 * @since 11.2.0
	 */
	public function is_provider_connected(): bool {
		if ( null === $this->provider ) {
			return false;
		}

		try {
			return $this->provider->is_connected();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the exchange rate for a target currency.
	 *
	 * @param string $from_currency Source currency.
	 * @param string $to_currency   Target currency.
	 * @return float|null
	 */
	public function get_rate( string $from_currency, string $to_currency ): ?float {
		$from_currency = strtolower( $from_currency );
		$to_currency   = strtolower( $to_currency );
		$rate_type     = get_option( 'wcpay_multi_currency_exchange_rate_' . $to_currency, 'automatic' );

		if ( 'manual' === $rate_type ) {
			return $this->get_manual_rate( $to_currency );
		}

		$provider = $this->get_available_provider();
		if ( ! $provider ) {
			return null;
		}

		try {
			$rates = $provider->get_currency_rates( $from_currency, array( $to_currency ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( isset( $rates['currencies'] ) && is_array( $rates['currencies'] ) ) {
			$rates = $rates['currencies'];
		}

		return $this->extract_rate( $rates, $to_currency );
	}

	/**
	 * Get automatic rates from the available provider.
	 *
	 * @param string        $from_currency Source currency.
	 * @param string[]|null $currencies_to Target currencies, or null for all supported.
	 * @return array<string,float>|null
	 *
	 * @since 11.0.0
	 */
	public function get_rates( string $from_currency, ?array $currencies_to = null ): ?array {
		$provider = $this->get_available_provider();
		if ( ! $provider ) {
			return null;
		}

		try {
			$rates = $provider->get_currency_rates(
				strtolower( $from_currency ),
				null === $currencies_to ? null : array_map( 'strtolower', $currencies_to )
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( isset( $rates['currencies'] ) && is_array( $rates['currencies'] ) ) {
			$rates = $rates['currencies'];
		}

		return $this->normalize_rates( $rates );
	}

	/**
	 * Get provider-supported WooCommerce currency codes.
	 *
	 * @return string[]
	 *
	 * @since 11.0.0
	 */
	public function get_supported_currency_codes(): array {
		$provider = $this->get_available_provider();
		if ( ! $provider ) {
			return array();
		}

		$wc_currencies        = array_keys( get_woocommerce_currencies() );
		$supported_currencies = $provider->get_supported_currencies();
		if ( empty( $supported_currencies ) ) {
			return $wc_currencies;
		}

		return array_values(
			array_intersect(
				array_values( array_unique( array_map( 'strtoupper', $supported_currencies ) ) ),
				$wc_currencies
			)
		);
	}

	/**
	 * Get the provider when automatic rates are available from it.
	 *
	 * @return CurrencyRateProviderInterface|null
	 */
	private function get_available_provider(): ?CurrencyRateProviderInterface {
		return null !== $this->provider && $this->provider->is_available() ? $this->provider : null;
	}

	/**
	 * Get a currency's stored manual rate.
	 *
	 * @param string $to_currency Target currency.
	 * @return float|null
	 */
	private function get_manual_rate( string $to_currency ): ?float {
		$rate = get_option( 'wcpay_multi_currency_manual_rate_' . $to_currency, null );

		return $this->normalize_rate( $rate );
	}

	/**
	 * Extract a target rate from a provider response.
	 *
	 * @param array<string,mixed> $rates       Provider rates.
	 * @param string              $to_currency Target currency.
	 * @return float|null
	 */
	private function extract_rate( array $rates, string $to_currency ): ?float {
		$rate = $rates[ $to_currency ] ?? $rates[ strtoupper( $to_currency ) ] ?? null;

		return $this->normalize_rate( $rate );
	}

	/**
	 * Normalize provider rates, keyed by upper-case currency code.
	 *
	 * The rates land in the cache the WooPayments extension shares, and it reads them by upper-case code
	 * (client 11.1.0 includes/multi-currency/MultiCurrency.php:1719).
	 *
	 * @param array<string,mixed> $rates Provider rates.
	 * @return array<string,float>
	 */
	private function normalize_rates( array $rates ): array {
		$normalized_rates = array();

		foreach ( $rates as $currency_code => $rate ) {
			$normalized_rate = $this->normalize_rate( $rate );
			if ( null === $normalized_rate ) {
				continue;
			}

			$normalized_rates[ strtoupper( (string) $currency_code ) ] = $normalized_rate;
		}

		return $normalized_rates;
	}

	/**
	 * Normalize a rate value.
	 *
	 * @param mixed $rate Rate value.
	 * @return float|null
	 */
	private function normalize_rate( $rate ): ?float {
		if ( ! is_numeric( $rate ) || 0 >= (float) $rate ) {
			return null;
		}

		return (float) $rate;
	}
}
