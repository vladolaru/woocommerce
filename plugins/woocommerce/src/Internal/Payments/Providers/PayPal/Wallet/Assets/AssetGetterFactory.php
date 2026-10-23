<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets;

/**
 * Creates asset getters scoped to a module.
 */
class AssetGetterFactory {
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
	 * AssetGetterFactory constructor.
	 *
	 * @param string $base_plugin_url   The base URL of the plugin.
	 * @param string $plugin_folder_path The path of the plugin folder.
	 */
	public function __construct(
		string $base_plugin_url,
		string $plugin_folder_path
	) {
		$this->base_plugin_url    = $base_plugin_url;
		$this->plugin_folder_path = $plugin_folder_path;
	}

	/**
	 * Creates the asset getter for a module.
	 *
	 * @param string $module_name The module name.
	 */
	public function for_module( string $module_name ): AssetGetter {
		return new AssetGetter(
			$this->base_plugin_url,
			$this->plugin_folder_path,
			$module_name
		);
	}
}
