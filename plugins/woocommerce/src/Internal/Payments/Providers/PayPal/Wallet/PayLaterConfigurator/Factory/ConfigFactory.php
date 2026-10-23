<?php
/**
 * The factory the Pay Later messaging configurator configs.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PayLaterMessagingSettings;

/**
 * Class ConfigFactory.
 */
class ConfigFactory {
	/**
	 * Returns the configurator config from the PayLaterMessagingSettings.
	 *
	 * @param PayLaterMessagingSettings $settings The pay later messaging settings.
	 */
	public function from_settings( PayLaterMessagingSettings $settings ): array {
		return array(
			'cart'             => $settings->get_location_config( 'cart' ),
			'checkout'         => $settings->get_location_config( 'checkout' ),
			'product'          => $settings->get_location_config( 'product' ),
			'shop'             => $settings->get_location_config( 'shop' ),
			'home'             => $settings->get_location_config( 'home' ),
			'custom_placement' => array( $settings->get_location_config( 'custom_placement' ) ),
		);
	}
}
