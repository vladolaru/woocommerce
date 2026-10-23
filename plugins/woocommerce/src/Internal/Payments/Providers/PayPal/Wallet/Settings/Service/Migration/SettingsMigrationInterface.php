<?php
/**
 * Interface for settings migration classes.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

/**
 * Interface SettingsMigrationInterface
 *
 * Defines the contract for all settings migration classes.
 */
interface SettingsMigrationInterface {
	/**
	 * Migrates legacy settings to new data structure.
	 *
	 * @return void
	 */
	public function migrate(): void;
}
