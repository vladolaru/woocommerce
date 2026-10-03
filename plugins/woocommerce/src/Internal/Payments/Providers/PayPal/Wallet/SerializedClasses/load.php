<?php
/**
 * Loads the classes whose names are part of the stored data format.
 *
 * The wallet saves these DTOs as PHP objects in options, so the class name sits inside the stored value, and the
 * extension reads the same options. Renaming them would leave core unable to read what the extension wrote
 * (__PHP_Incomplete_Class) and the extension unable to read what core wrote. They therefore keep the extension's
 * names, and the forked code reaches them through aliases in the wallet namespace, so no forked file changes.
 *
 * The files sit outside any PSR-4 match on purpose, so Composer's optimized classmap skips them and this loader is
 * what defines them when core boots the wallet (the extension is not loaded then, and the guards below keep a
 * second definition from ever being declared). The Jetpack autoloader's manifest still lists them, because it
 * scans PSR-4 roots as plain classmaps: with the extension active, its loader can serve core's copies of these
 * three classes. That is harmless only while the files stay byte-identical to the extension's, so any upstream
 * change to modules/ppcp-settings/src/DTO/ for these classes has to be mirrored here in the same release.
 *
 * This is a compatibility shim, not a design to keep. The extension saves DTO objects because AbstractDataModel::save()
 * stores its data as is. An upstream change will make it store arrays and read either shape; once that release is
 * the floor for coexistence, core switches to arrays and deletes this directory.
 *
 * @since 11.3.0
 * @internal
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet;

foreach ( array( 'LocationStylingDTO', 'PayLaterMessagingDTO', 'OAuthConnectionDTO' ) as $wallet_serialized_class ) {
	$wallet_original_name = 'WooCommerce\\PayPalCommerce\\Settings\\DTO\\' . $wallet_serialized_class;
	$wallet_alias_name    = __NAMESPACE__ . '\\Settings\\DTO\\' . $wallet_serialized_class;

	if ( ! class_exists( $wallet_original_name, false ) ) {
		require_once __DIR__ . '/' . $wallet_serialized_class . '.php';
	}
	if ( ! class_exists( $wallet_alias_name, false ) ) {
		class_alias( $wallet_original_name, $wallet_alias_name );
	}
}
unset( $wallet_serialized_class, $wallet_original_name, $wallet_alias_name );
