<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use WC_Unit_Test_Case;

/**
 * Keeps the Multi-Currency module independent of the WooPayments provider.
 */
class MultiCurrencyModuleBoundaryTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should keep WooPayments provider implementation details out of production Multi-Currency code.
	 */
	public function test_production_multi_currency_code_is_provider_neutral(): void {
		$directory = WC()->plugin_path() . '/src/Internal/MultiCurrency';
		$offenders = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) ) as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local production source for domain-boundary regression coverage.
			$source = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $source, 'Internal\\Payments\\Providers\\WooPayments' ) ) {
				$offenders[] = substr( $file->getPathname(), strlen( $directory ) + 1 );
			}
		}

		sort( $offenders );

		$this->assertSame( array(), $offenders, 'WooPayments provider implementation details must not appear in production Multi-Currency files: ' . implode( ', ', $offenders ) );
	}
}
