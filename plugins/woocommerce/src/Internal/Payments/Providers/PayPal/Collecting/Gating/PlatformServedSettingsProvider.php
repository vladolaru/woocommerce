<?php
/**
 * PlatformServedSettingsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * The wallet's settings provider for a store the platform serves: saved PayPal and Venmo always reads as off.
 *
 * Every checkout reader of the setting (buttons, block method, vault component, Subscriptions mode, SDK v6) asks the
 * provider, so vaulting stays inert. The merchant's stored setting is only read, never changed.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedSettingsProvider extends SettingsProvider {

	/**
	 * Saved PayPal and Venmo is off while the platform serves the store.
	 *
	 * @since 11.3.0
	 *
	 * @return bool Always false.
	 */
	public function save_paypal_and_venmo(): bool {
		return false;
	}
}
