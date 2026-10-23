<?php

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets;

/**
 * Returns the URLs/paths for plugin assets.
 */
class AssetGetter {
	/**
	 * The base URL of the plugin.
	 *
	 * @var string
	 */
	protected string $base_plugin_url;

	/**
	 * The path of the plugin folder.
	 *
	 * @var string
	 */
	protected string $plugin_folder_path;

	/**
	 * The module name.
	 *
	 * @var string
	 */
	protected string $module_name;

	/**
	 * AssetGetter constructor.
	 *
	 * @param string $base_plugin_url   The base URL of the plugin.
	 * @param string $plugin_folder_path The path of the plugin folder.
	 * @param string $module_name        The module name.
	 */
	public function __construct(
		string $base_plugin_url,
		string $plugin_folder_path,
		string $module_name
	) {
		$this->base_plugin_url    = $base_plugin_url;
		$this->plugin_folder_path = $plugin_folder_path;
		$this->module_name        = $module_name;
	}

	/**
	 * Returns URL for the compiled asset in the wallet's built-assets directory.
	 *
	 * @param string $asset_name The asset name like 'index.js'.
	 */
	public function get_asset_url( string $asset_name ): string {
		$compiled_name = $this->get_compiled_asset_name( $asset_name );

		return $this->base_plugin_url . $compiled_name;
	}

	/**
	 * Returns the path of the .asset.php file for the compiled asset in the wallet's built-assets directory.
	 *
	 * @param string $asset_name The asset name like 'index.js'.
	 */
	public function get_asset_php_path( string $asset_name ): string {
		$compiled_name = $this->get_compiled_asset_name( $asset_name );
		$without_ext   = pathinfo( $compiled_name, PATHINFO_FILENAME );

		return trailingslashit( $this->plugin_folder_path ) . "$without_ext.asset.php";
	}

	/**
	 * Returns the dependencies and version webpack generated for a compiled asset.
	 *
	 * Use this over hardcoding an empty dependency list. The WP dependency-extraction
	 * webpack plugin rewrites `@wordpress/*` imports to `window.wp.*` globals and
	 * records the matching script handles here.
	 * Registering without them may cause failure in some cases depending on the page and environment.
	 *
	 * The version is a content hash, so it also busts caches on rebuild where
	 * the plugin version would not.
	 *
	 * @param string $asset_name       The asset name like 'index.js'.
	 * @param string $fallback_version Version to use when the file is absent.
	 * @return array{dependencies: string[], version: string}
	 */
	public function get_asset_data( string $asset_name, string $fallback_version ): array {
		$path = $this->get_asset_php_path( $asset_name );

		$asset = file_exists( $path ) ? require $path : array();

		return array(
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : $fallback_version,
		);
	}

	/**
	 * Returns URL for the static asset (images, ...) copied to static/<module>/ by the wallet build.
	 *
	 * @param string $asset_name The asset name like 'images/icon.svg'.
	 */
	public function get_static_asset_url( string $asset_name ): string {
		return $this->base_plugin_url . "static/{$this->module_name}/$asset_name";
	}

	/**
	 * Returns the file name of a compiled asset, prefixed with the module name and the asset type.
	 *
	 * @param string $asset_name The asset name like 'index.js'.
	 */
	protected function get_compiled_asset_name( string $asset_name ): string {
		$type = pathinfo( $asset_name, PATHINFO_EXTENSION );

		$asset_name = str_replace( '/', '-', $asset_name );

		return "{$this->module_name}-$type-$asset_name";
	}
}
