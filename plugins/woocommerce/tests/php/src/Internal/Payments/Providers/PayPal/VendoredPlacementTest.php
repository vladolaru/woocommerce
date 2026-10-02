<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WC_Unit_Test_Case;

/**
 * Guards the placement rule: WooCommerce\PayPalCommerce code lives only inside the vendored tree.
 */
class VendoredPlacementTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should find no WooCommerce\PayPalCommerce namespace declaration in src/ outside the vendored directory.
	 */
	public function test_no_extension_namespace_outside_vendored_tree(): void {
		$src_dir      = dirname( PayPalWalletBootstrap::VENDORED_DIR, 5 ); // plugins/woocommerce/src.
		$vendored_dir = realpath( PayPalWalletBootstrap::VENDORED_DIR );
		$violations   = array();

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = $file->getRealPath();
			if ( 0 === strpos( $path, $vendored_dir ) ) {
				continue;
			}
			$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match( '/^\s*namespace\s+WooCommerce\\\\PayPalCommerce\b/m', $head ) ) {
				$violations[] = substr( $path, strlen( $src_dir ) + 1 );
			}
		}

		$this->assertSame( array(), $violations, 'Extension code must stay inside the vendored directory' );
	}
}
