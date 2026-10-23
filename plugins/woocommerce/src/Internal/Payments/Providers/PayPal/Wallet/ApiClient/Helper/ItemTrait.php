<?php
/**
 * PayPal item helper.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

trait ItemTrait {

	/**
	 * Cleans up item strings (title and description for example) and prepares them for sending to PayPal.
	 *
	 * @param string $text Item string.
	 * @return string
	 */
	protected function prepare_item_string( string $text ): string {
		$text      = strip_shortcodes( wp_strip_all_tags( $text ) );
		$truncated = substr( $text, 0, 127 );

		return $truncated ? $truncated : '';
	}

	/**
	 * Prepares the sku for sending to PayPal.
	 *
	 * @param string $sku Item sku.
	 * @return string
	 */
	protected function prepare_sku( string $sku ): string {
		$truncated = substr( wp_strip_all_tags( $sku ), 0, 127 );

		return $truncated ? $truncated : '';
	}
}
