<?php
/**
 * MultiCurrencyCacheRenderingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Turns cache-optimized rendering on once for a store with a detected page cache and no rendering mode chosen, and tells
 * whether the settings recommend that mode or the merchant dismissed the recommendation.
 *
 * @since 11.2.0
 * @internal
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

		$missing_rendering_mode = new \stdClass();
		$rendering_mode         = get_option( MultiCurrencyDisplayService::RENDERING_MODE_OPTION, $missing_rendering_mode );
		if ( $missing_rendering_mode !== $rendering_mode ) {
			update_option( self::AUTODETECT_DONE_OPTION, 'yes', true );
			return;
		}

		if ( $this->caching_environment->is_page_caching_active() ) {
			add_option(
				MultiCurrencyDisplayService::RENDERING_MODE_OPTION,
				MultiCurrencyDisplayService::RENDERING_MODE_CACHE,
				'',
				true
			);
		}

		update_option( self::AUTODETECT_DONE_OPTION, 'yes', true );
	}

	/**
	 * Tell whether cache rendering should be recommended to the merchant.
	 *
	 * @since 11.2.0
	 *
	 * @return bool Whether cache rendering should be recommended.
	 */
	public function should_recommend_cache_mode(): bool {
		if ( MultiCurrencyDisplayService::RENDERING_MODE_SPEED !== get_option( MultiCurrencyDisplayService::RENDERING_MODE_OPTION, MultiCurrencyDisplayService::RENDERING_MODE_SPEED ) ) {
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
}
