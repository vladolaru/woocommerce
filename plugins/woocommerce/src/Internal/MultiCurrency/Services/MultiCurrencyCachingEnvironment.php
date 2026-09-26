<?php
/**
 * MultiCurrencyCachingEnvironment class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Detects high-confidence page-caching signals for Multi-Currency settings.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyCachingEnvironment {

	/**
	 * Tell whether page caching is active after the public compatibility filter.
	 *
	 * @since 11.2.0
	 *
	 * @return bool Whether page caching is active.
	 */
	public function is_page_caching_active(): bool {
		$is_active = $this->is_detected_page_caching_active();

		/**
		 * Filters whether Multi-Currency page caching is active.
		 *
		 * @since 11.2.0
		 *
		 * @param bool $is_active Whether a supported page-cache environment was detected.
		 */
		return (bool) apply_filters( 'wcpay_multi_currency_page_caching_active', $is_active );
	}

	/**
	 * Tell whether a supported page-cache environment is detected.
	 *
	 * @return bool Whether a supported environment is detected.
	 */
	private function is_detected_page_caching_active(): bool {
		if ( $this->is_constant_defined( 'WP_CACHE' ) && $this->get_constant_value( 'WP_CACHE' ) && $this->does_file_exist( $this->get_content_directory() . '/advanced-cache.php' ) ) {
			return true;
		}

		if ( $this->is_constant_defined( 'LSCWP_V' ) ) {
			return true;
		}

		if ( $this->is_class_available( 'WpFastestCache' ) ) {
			return true;
		}

		if ( $this->is_constant_defined( 'IS_ATOMIC' ) || $this->is_constant_defined( 'ATOMIC_SITE_ID' ) ) {
			return true;
		}

		return $this->is_constant_defined( 'IS_PRESSABLE' );
	}

	/**
	 * Tell whether a constant is defined.
	 *
	 * @since 11.2.0
	 *
	 * @param string $name Constant name.
	 * @return bool Whether the constant is defined.
	 */
	protected function is_constant_defined( string $name ): bool {
		return defined( $name );
	}

	/**
	 * Get a constant value.
	 *
	 * @since 11.2.0
	 *
	 * @param string $name Constant name.
	 * @return mixed Constant value.
	 */
	protected function get_constant_value( string $name ) {
		return constant( $name );
	}

	/**
	 * Get the current content directory.
	 *
	 * @since 11.2.0
	 *
	 * @return string Content directory.
	 */
	protected function get_content_directory(): string {
		return WP_CONTENT_DIR;
	}

	/**
	 * Tell whether a file exists.
	 *
	 * @since 11.2.0
	 *
	 * @param string $path File path.
	 * @return bool Whether the file exists.
	 */
	protected function does_file_exist( string $path ): bool {
		return file_exists( $path );
	}

	/**
	 * Tell whether a class is available.
	 *
	 * @since 11.2.0
	 *
	 * @param string $class_name Class name.
	 * @return bool Whether the class is available.
	 */
	protected function is_class_available( string $class_name ): bool {
		return class_exists( $class_name );
	}
}
