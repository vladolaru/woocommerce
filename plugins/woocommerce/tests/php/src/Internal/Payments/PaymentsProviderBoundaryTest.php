<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use WC_Unit_Test_Case;

/**
 * Keeps the payments runtime provider-agnostic: no file in `src/Internal/Payments/` outside `Providers/WooPayments/`
 * may use or name `Providers\WooPayments` classes.
 *
 * The committed `woocommerce-payments-runtime` skill, which AGENTS.md lists for this folder, states the same rule.
 */
class PaymentsProviderBoundaryTest extends WC_Unit_Test_Case {

	private const PAYMENTS_DIRECTORY = 'src/Internal/Payments/';

	private const PROVIDER_DIRECTORY = 'Providers/WooPayments/';

	/**
	 * @testdox The scanner finds provider references in code and strings and ignores them in comments.
	 *
	 * @dataProvider provider_reference_sources
	 *
	 * @param string $source   PHP source.
	 * @param bool   $expected Whether the source references the provider.
	 */
	public function test_scanner_finds_code_references_and_ignores_comments( string $source, bool $expected ): void {
		$this->assertSame( $expected, $this->references_provider( "<?php\nnamespace Automattic\\WooCommerce\\Internal\\Payments;\n" . $source ) );
	}

	/**
	 * Sources for the scanner test.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provider_reference_sources(): array {
		return array(
			'use import'                      => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;', true ),
			'namespace alias import'          => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;', true ),
			'group import'                    => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\{WooPayments\WooPaymentsProvider};', true ),
			'relative new'                    => array( '$provider = new Providers\WooPayments\WooPaymentsProvider();', true ),
			'qualified static call'           => array( '\Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::instance();', true ),
			'instanceof'                      => array( '$is = $provider instanceof Providers\WooPayments\WooPaymentsProvider;', true ),
			'class string'                    => array( '$id = \'Automattic\\\\WooCommerce\\\\Internal\\\\Payments\\\\Providers\\\\WooPayments\\\\WooPaymentsProvider\';', true ),
			'line comment'                    => array( '// Mirrors Providers\WooPayments\WooPaymentsProvider.', false ),
			'docblock'                        => array( '/** {@see Providers\WooPayments\WooPaymentsProviderGatewayAdapter} */', false ),
			'plugin prose and slug'           => array( '$file = \'woocommerce-payments/woocommerce-payments.php\'; $label = \'WooPayments\';', false ),
			'lower-case use import'           => array( 'use automattic\woocommerce\internal\payments\providers\woopayments\WooPaymentsProvider;', true ),
			'mixed-case relative new'         => array( '$provider = new providers\WOOPAYMENTS\WooPaymentsProvider();', true ),
			'group import, not first'         => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\{ Foo, WooPayments\WooPaymentsProvider };', true ),
			'multi-line group import of a function, not first' => array( "use Automattic\\WooCommerce\\Internal\\Payments\\Providers\\{\n\tFoo,\n\tfunction woopayments\\bar,\n};", true ),
			'group import of other providers' => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\{ Foo, Bar\Baz };', false ),
			'group import of a nested WooPayments namespace' => array( 'use Automattic\WooCommerce\Internal\Payments\Providers\{ Foo, Other\WooPayments\Bar };', false ),
			'group import in a comment'       => array( '# use Automattic\WooCommerce\Internal\Payments\Providers\{ Foo, WooPayments\Bar };', false ),
			'lower-case name in a docblock'   => array( '/** @see providers\woopayments\WooPaymentsProvider */', false ),
		);
	}

	/**
	 * @testdox No payments runtime file outside the provider folder references the WooPayments provider.
	 */
	public function test_no_runtime_file_references_the_provider(): void {
		$this->assertSame( array(), $this->referencing_files(), 'The payments runtime must not reference Providers\WooPayments. Route new provider needs through the provider contracts.' );
	}

	/**
	 * Payments runtime files that reference the provider, relative to `src/Internal/Payments/`.
	 *
	 * @return array<int,string>
	 */
	private function referencing_files(): array {
		$payments_directory = WC()->plugin_path() . '/' . self::PAYMENTS_DIRECTORY;
		$referencing        = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $payments_directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $payments_directory ) );
			if ( 0 === strpos( $path, self::PROVIDER_DIRECTORY ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a placement assertion.
			if ( $this->references_provider( (string) file_get_contents( $file->getPathname() ) ) ) {
				$referencing[] = $path;
			}
		}

		sort( $referencing );

		return $referencing;
	}

	/**
	 * Tell whether PHP source names the `Providers\WooPayments` namespace outside comments.
	 *
	 * PHP namespace names are case-insensitive, and a group import can name `WooPayments\...` as any of its items.
	 *
	 * @param string $source PHP source.
	 * @return bool
	 */
	private function references_provider( string $source ): bool {
		return 1 === preg_match( '/Providers\\\\+(?:\{(?:[^{}]*,)?\s*(?:(?:function|const)\s+)?)?WooPayments\b/i', $this->code_without_comments( $source ) );
	}

	/**
	 * Strip comments from PHP source.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function code_without_comments( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}
}
