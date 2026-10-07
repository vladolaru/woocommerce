<?php
/**
 * Minimal WooPayments main-file declaration fixture.
 *
 * The plugin's main file declares only WC_Payments_Features while it loads (client 11.1.0 woocommerce-payments.php:28
 * requires includes/class-wc-payments-features.php at file scope); WC_Payments is required later, in wcpay_init().
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The fixture reproduces the plugin-owned global declaration that collides during activation.
/**
 * WooPayments feature class main-file fixture.
 */
class WC_Payments_Features {

	/**
	 * Identify the fixture declaration.
	 */
	public const DECLARATION_OWNER = 'plugin';
}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps
