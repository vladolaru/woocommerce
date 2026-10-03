<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use WC_Unit_Test_Case;

/**
 * The fork lives in core's namespace; the extension's namespace appears only where core detects the extension itself.
 */
class ForkPlacementTest extends WC_Unit_Test_Case {

	/**
	 * Files allowed to mention the extension's namespace: the shell's init-function probe and the provider's extension branch (the stored DTOs are allowed by directory).
	 */
	private const ALLOWED = array(
		'Internal/Payments/Providers/PayPal/PayPalWalletBootstrap.php',
		'Internal/Admin/Settings/PaymentsProviders/PayPal.php',
	);

	/**
	 * Directory of the DTOs that keep the extension's class names because PHP stores the name inside saved objects.
	 */
	private const SERIALIZED_CLASSES_DIR = 'Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/';

	/**
	 * @testdox Should contain no reference to the extension's namespace outside the two detection sites and the stored DTOs.
	 */
	public function test_no_extension_namespace_in_src(): void {
		$src_dir    = WC_ABSPATH . 'src';
		$violations = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $src_dir ) + 1 );
			if ( in_array( $relative, self::ALLOWED, true ) || 0 === strpos( $relative, self::SERIALIZED_CLASSES_DIR ) ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), 'WooCommerce\\PayPalCommerce' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local source files.
				$violations[] = $relative;
			}
		}
		$this->assertSame( array(), $violations, 'Only the detection sites may name the extension namespace' );
	}

	/**
	 * @testdox Should not carry the vendored tree or its autoloader any more.
	 */
	public function test_vendored_tree_is_gone(): void {
		$this->assertDirectoryDoesNotExist( WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/woocommerce-paypal-payments' );
		$this->assertFileExists( WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/PPCP.php' );
	}

	/**
	 * @testdox Should use core's text domain in the forked code, except the inbox note source and the shared base name.
	 */
	public function test_text_domain_is_core(): void {
		$wallet_dir = WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet';
		$hits       = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $wallet_dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// WalletProperties keeps the extension's base name, which the inbox note source and the version option share.
			if ( 'WalletProperties.php' === $file->getFilename() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $line ) {
				if ( false !== strpos( $line, "'woocommerce-paypal-payments'" ) && false === strpos( $line, 'set_source(' ) ) {
					$hits[] = substr( $file->getPathname(), strlen( $wallet_dir ) + 1 );
				}
			}
		}
		$this->assertSame( array(), array_values( array_unique( $hits ) ) );
	}
}
