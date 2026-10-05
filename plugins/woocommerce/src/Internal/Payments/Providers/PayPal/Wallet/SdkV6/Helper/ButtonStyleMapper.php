<?php
/**
 * Maps admin button settings to v6 Web Component styles.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * Maps the saved button styling to the SDK v6 button styles.
 */
class ButtonStyleMapper {

	/**
	 * Maps admin color names to v6 CSS class names.
	 */
	private const COLOR_MAP = array(
		'gold'   => 'paypal-gold',
		'blue'   => 'paypal-blue',
		'white'  => 'paypal-white',
		'black'  => 'paypal-black',
		'silver' => 'paypal-white',
	);

	/**
	 * Maps admin shape names to v6 border-radius values.
	 */
	private const SHAPE_MAP = array(
		'pill' => '24px',
		'rect' => '4px',
	);

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;

	/**
	 * ButtonStyleMapper constructor.
	 *
	 * @param SettingsProvider $settings_provider The settings provider.
	 */
	public function __construct( SettingsProvider $settings_provider ) {
		$this->settings_provider = $settings_provider;
	}

	/**
	 * Returns v6-compatible styles for a given context.
	 *
	 * Color and shape come from the styling DTOs, the same source the v5
	 * SmartButton reads. There is no height, because the styling DTOs have no
	 * height field; SdkV6Manager::button_height() supplies one per context
	 * instead, matching the per-location defaults v5 sends.
	 *
	 * @param string $context The page context (product, cart, checkout, mini-cart).
	 * @return array{colorClass: string, borderRadius: string}
	 */
	public function styles_for_context( string $context ): array {
		$styling = $this->settings_provider->button_styling( $context );
		$color   = $styling->color ? $styling->color : 'gold';
		$shape   = $styling->shape ? $styling->shape : 'pill';

		return array(
			'colorClass'   => self::COLOR_MAP[ $color ] ?? 'paypal-gold',
			'borderRadius' => self::SHAPE_MAP[ $shape ] ?? '24px',
		);
	}
}
