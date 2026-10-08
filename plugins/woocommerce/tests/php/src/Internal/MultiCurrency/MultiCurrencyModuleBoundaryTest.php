<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use WC_Unit_Test_Case;

/**
 * Keeps the Multi-Currency module independent of the WooPayments provider.
 */
class MultiCurrencyModuleBoundaryTest extends WC_Unit_Test_Case {

	/**
	 * Multi-Currency files that may name the WooPayments provider, relative to `src/Internal/MultiCurrency/`.
	 *
	 * The Multi-Currency arbiter follows whoever owns WooPayments, because the WooPayments extension ships its own
	 * Multi-Currency module, so it reads the WooPayments runtime arbiter; it is the one place Multi-Currency does.
	 */
	private const ALLOWED_FILES = array(
		'MultiCurrencyRuntimeArbiter.php',
	);

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
			$source        = (string) file_get_contents( $file->getPathname() );
			$relative_path = substr( $file->getPathname(), strlen( $directory ) + 1 );
			if ( false !== strpos( $source, 'Internal\\Payments\\Providers\\WooPayments' ) && ! in_array( $relative_path, self::ALLOWED_FILES, true ) ) {
				$offenders[] = $relative_path;
			}
		}

		sort( $offenders );

		$this->assertSame( array(), $offenders, 'WooPayments provider implementation details must not appear in production Multi-Currency files: ' . implode( ', ', $offenders ) );
	}
}
