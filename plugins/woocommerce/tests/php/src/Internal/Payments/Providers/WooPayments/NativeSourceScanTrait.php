<?php
/**
 * NativeSourceScanTrait file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

/**
 * Shared token_get_all()-based scanning primitives for the `WooPaymentsPlugin*ContractTest`
 * classes that pin plugin-11.1.0 fixtures against the native source tree.
 *
 * Each consuming test keeps its own matching rule (which call shapes count as a hit); this trait
 * only owns what every one of them needs: which files to scan, how to walk their tokens, literal
 * resolution, and a plain literal harvest. Constant (`self::`/`static::`/`ClassName::`) resolution
 * is not here yet; it lands with the hook-names test (Task 5), the first consumer that needs it.
 *
 * @since 11.2.0
 */
trait NativeSourceScanTrait {

	/**
	 * Collect native files with the given extensions under the given absolute directory roots
	 * (PHP: `array( 'php' )`; client: `array( 'js', 'jsx', 'ts', 'tsx' )`).
	 *
	 * @param array<int,string> $roots      Absolute directory paths.
	 * @param array<int,string> $extensions Lowercase file extensions, without the dot.
	 * @return array<int,string> Absolute file paths, sorted.
	 */
	private function native_collect_files( array $roots, array $extensions ): array {
		$files = array();

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}

				if ( ! in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
					continue;
				}

				$path = $file->getPathname();
				if ( $this->native_is_excluded_path( $path ) ) {
					continue;
				}

				$files[] = $path;
			}
		}

		sort( $files );
		return array_values( array_unique( $files ) );
	}

	/**
	 * Whether a path is a test file or fixture the scan must ignore.
	 *
	 * @param string $path Absolute file path.
	 */
	private function native_is_excluded_path( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );

		if ( false !== strpos( $normalized, '/tests/' ) || false !== strpos( $normalized, '/test/' ) || false !== strpos( $normalized, '/__tests__/' ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(test|spec)\.[a-z]+$/', $normalized );
	}

	/**
	 * Tokenize a PHP file.
	 *
	 * @param string $file Absolute file path.
	 * @return array<int,mixed> PHP tokens.
	 */
	private function native_tokenize( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading immutable local production source for a scanning assertion.
		return token_get_all( (string) file_get_contents( $file ) );
	}

	/**
	 * Resolve a `T_CONSTANT_ENCAPSED_STRING` token's raw source text to its PHP string value.
	 *
	 * Uses `eval()` on the literal token text alone (never on surrounding code): safe here
	 * because the input is this repository's own source, not attacker-controlled, and it is the
	 * only way to reproduce PHP's own single/double-quote escaping rules exactly.
	 *
	 * @param string $token_text Raw token text, including its quotes.
	 * @return string|null
	 */
	private function native_resolve_literal( string $token_text ): ?string {
		$quote = $token_text[0] ?? '';
		if ( ( "'" !== $quote && '"' !== $quote ) || substr( $token_text, -1 ) !== $quote ) {
			return null;
		}
		if ( '"' === $quote && ( false !== strpos( $token_text, '$' ) || false !== strpos( $token_text, '{' ) ) ) {
			// Double-quoted literals with interpolation never resolve to a fixed name; reject
			// rather than risk mis-resolving one.
			return null;
		}

		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- literal-token-only, see docblock.
			$value = eval( "return {$token_text};" );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Strip whitespace and comment tokens from a token slice.
	 *
	 * @param array<int,mixed> $tokens Token slice.
	 * @return array<int,mixed>
	 */
	private function native_strip_trivia( array $tokens ): array {
		return array_values(
			array_filter(
				$tokens,
				static function ( $token ) {
					return ! ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );
				}
			)
		);
	}

	/**
	 * Harvest every resolvable string literal from the given PHP files.
	 *
	 * @param array<int,string> $php_files Absolute PHP file paths.
	 * @return array<int,string> Resolved literal values (not de-duplicated).
	 */
	private function native_all_php_string_literals( array $php_files ): array {
		$literals = array();

		foreach ( $php_files as $path ) {
			foreach ( $this->native_tokenize( $path ) as $token ) {
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$value = $this->native_resolve_literal( $token[1] );
					if ( null !== $value ) {
						$literals[] = $value;
					}
				}
			}
		}

		return $literals;
	}
}
