<?php
/**
 * MultiCurrencyCacheRenderingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Owns cache-rendering auto-detection and recommendation state.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyCacheRenderingService {

	/** Cache auto-detection completion option. */
	public const AUTODETECT_DONE_OPTION = 'wcpay_multi_currency_cache_autodetect_done';

	/** Cache recommendation dismissal option. */
	public const DISMISSED_OPTION = 'wcpay_multi_currency_cache_recommendation_dismissed';

	/**
	 * Caching environment detector.
	 *
	 * @var MultiCurrencyCachingEnvironment
	 */
	private MultiCurrencyCachingEnvironment $caching_environment;

	/**
	 * Initialize the cache-rendering service.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyCachingEnvironment $caching_environment Caching environment detector.
	 */
	final public function init( MultiCurrencyCachingEnvironment $caching_environment ): void {
		$this->caching_environment = $caching_environment;
	}

	/**
	 * Enable cache rendering once for an eligible unconfigured store.
	 *
	 * @since 11.2.0
	 */
	public function maybe_auto_enable_cache_rendering_mode(): void {
		if ( 'yes' === get_option( self::AUTODETECT_DONE_OPTION ) ) {
			return;
		}

		if ( ! $this->is_cache_optimized_feature_enabled() ) {
			return;
		}

		$missing_rendering_mode = new \stdClass();
		$rendering_mode         = get_option( MultiCurrencyFrontendProjectionService::OPTION_PREFIX . '_rendering_mode', $missing_rendering_mode );
		if ( $missing_rendering_mode !== $rendering_mode ) {
			update_option( self::AUTODETECT_DONE_OPTION, 'yes', false );
			return;
		}

		if ( $this->caching_environment->is_page_caching_active() ) {
			add_option(
				MultiCurrencyFrontendProjectionService::OPTION_PREFIX . '_rendering_mode',
				MultiCurrencyFrontendProjectionService::RENDERING_MODE_CACHE,
				'',
				true
			);
		}

		update_option( self::AUTODETECT_DONE_OPTION, 'yes', false );
	}

	/**
	 * Tell whether cache rendering should be recommended to the merchant.
	 *
	 * @since 11.2.0
	 *
	 * @return bool Whether cache rendering should be recommended.
	 */
	public function should_recommend_cache_mode(): bool {
		if ( ! $this->is_cache_optimized_feature_enabled() ) {
			return false;
		}

		if ( MultiCurrencyFrontendProjectionService::RENDERING_MODE_SPEED !== get_option( MultiCurrencyFrontendProjectionService::OPTION_PREFIX . '_rendering_mode', MultiCurrencyFrontendProjectionService::RENDERING_MODE_SPEED ) ) {
			return false;
		}

		if ( 'yes' === get_option( self::DISMISSED_OPTION ) ) {
			return false;
		}

		return $this->caching_environment->is_page_caching_active();
	}

	/**
	 * Tell whether the cache recommendation is dismissed.
	 *
	 * @since 11.2.0
	 *
	 * @return bool Whether the recommendation is dismissed.
	 */
	public function is_cache_recommendation_dismissed(): bool {
		return 'yes' === get_option( self::DISMISSED_OPTION );
	}

	/**
	 * Tell whether the cache-optimized rendering feature is enabled.
	 *
	 * @return bool Whether the feature is enabled.
	 */
	private function is_cache_optimized_feature_enabled(): bool {
		return '1' === get_option( MultiCurrencyFrontendProjectionService::CACHE_FEATURE_FLAG_OPTION, '1' );
	}
}
