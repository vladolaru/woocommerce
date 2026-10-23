<?php
/**
 * Caches the API results for the ProductStatus class.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

/**
 * Caches the product status results in a transient, with per-key expiry.
 */
class ProductStatusResultCache {
	private const CACHE_KEY = 'woocommerce-ppcp-cache-product-status';

	/**
	 * Whether the cached results were already loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * The cache.
	 *
	 * @var array
	 */
	private array $cache = array();

	/**
	 * Returns the cached value for the key, or an empty string when missing or expired.
	 *
	 * @param string $key The cache key.
	 *
	 * @return string
	 */
	public function get( string $key ): string {
		$this->load();

		if ( ! isset( $this->cache[ $key ] ) ) {
			return '';
		}

		$entry = $this->cache[ $key ];
		$now   = $this->get_time();

		if ( ! empty( $entry['expires_at'] ) && $entry['expires_at'] < $now ) {
			$this->clear( $key );

			return '';
		}

		return $entry['value'] ?? '';
	}

	/**
	 * Stores a value in the cache.
	 *
	 * @param string $key The cache key.
	 * @param string $value The value to store.
	 * @param int    $expiration The lifetime in seconds, 0 for no expiry.
	 */
	public function set( string $key, string $value, int $expiration = 0 ): void {
		$this->load();

		$this->cache[ $key ] = array(
			'value'      => $value,
			'expires_at' => $expiration > 0 ? $this->get_time() + $expiration : 0,
		);

		$this->save();
	}

	/**
	 * Removes a key from the cache.
	 *
	 * @param string $key The cache key.
	 */
	public function clear( string $key ): void {
		$this->load();

		unset( $this->cache[ $key ] );
		$this->save();
	}

	/**
	 * Loads the cache from storage once per request.
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->cache  = array_map(
			static fn( $value ) => (array) $value,
			$this->load_from_storage()
		);
		$this->loaded = true;
	}

	/**
	 * Writes the cache to storage.
	 */
	private function save(): void {
		$this->save_to_storage( $this->cache );
	}

	/**
	 * Low-level data retrieval; can be overridden for testing.
	 */
	protected function load_from_storage(): array {
		$data = get_transient( self::CACHE_KEY );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Low-level data storage; can be overridden for testing.
	 *
	 * @param array $data The data to store.
	 */
	protected function save_to_storage( array $data ): void {
		set_transient( self::CACHE_KEY, $data );
	}

	/**
	 * Low-level time access for expiration control; can be overridden for testing.
	 */
	protected function get_time(): int {
		return time();
	}
}
