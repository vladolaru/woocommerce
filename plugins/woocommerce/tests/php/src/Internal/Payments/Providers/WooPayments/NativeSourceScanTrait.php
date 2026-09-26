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
 * resolution, a plain literal harvest, and constant (`self::`/`static::`/`ClassName::`) resolution
 * (the hook-names test, Task 5, is the first consumer that needs it).
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

	/**
	 * The index of the next non-trivia token at or after the given index.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $from   Index to start looking from (inclusive).
	 */
	private function native_next_significant_token_index( array $tokens, int $from ): ?int {
		for ( $i = $from, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * The previous non-trivia token at or before the given index.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $from   Index to start looking from (exclusive).
	 * @return mixed|null
	 */
	private function native_previous_significant_token( array $tokens, int $from ) {
		for ( $i = $from - 1; $i >= 0; $i-- ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $token;
		}

		return null;
	}

	/**
	 * Read a possibly-qualified name (`Foo`, `Foo\Bar`, `\Foo\Bar`) starting at the given index, as
	 * a run of `T_STRING`/`T_NS_SEPARATOR` (PHP 8 tokenizes it as one `T_NAME_QUALIFIED`/
	 * `T_NAME_FULLY_QUALIFIED` token; both forms are handled).
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $from   Index to start reading from.
	 * @return string
	 */
	private function native_read_qualified_name( array $tokens, int $from ): string {
		$name = '';

		for ( $i = $from, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NS_SEPARATOR ), true ) ) {
				$name .= $token[1];
				continue;
			}

			if ( is_array( $token ) && defined( 'T_NAME_QUALIFIED' ) && in_array( $token[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$name .= $token[1];
				continue;
			}

			break;
		}

		return ltrim( $name, '\\' );
	}

	/**
	 * The fully-qualified class/interface/trait name a file's own top-level type declares, from its
	 * `namespace` statement and its `class|interface|trait Name` declaration. This codebase follows
	 * PSR-4 (one such declaration per file), so the file's own type is what `self::`/`static::`
	 * resolve against anywhere in it.
	 *
	 * @param string $file Absolute file path.
	 * @return string|null
	 */
	private function native_file_own_class( string $file ): ?string {
		$tokens    = $this->native_tokenize( $file );
		$namespace = '';
		$class     = null;

		for ( $i = 0, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_NAMESPACE === $token[0] ) {
				$namespace = $this->native_read_qualified_name( $tokens, $i + 1 );
				continue;
			}

			if ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				$previous = $this->native_previous_significant_token( $tokens, $i );
				if ( is_array( $previous ) && T_DOUBLE_COLON === $previous[0] ) {
					// `Foo::class`, not a declaration.
					continue;
				}

				$name_index = $this->native_next_significant_token_index( $tokens, $i + 1 );
				$name_token = null !== $name_index ? $tokens[ $name_index ] : null;
				if ( is_array( $name_token ) && T_STRING === $name_token[0] ) {
					$class = $name_token[1];
					break;
				}
			}
		}

		if ( null === $class ) {
			return null;
		}

		return '' === $namespace ? $class : $namespace . '\\' . $class;
	}

	/**
	 * The `use Foo\Bar\Baz;` / `use Foo\Bar\Baz as Alias;` import map of a file: short/alias name to
	 * fully-qualified class name. Grouped (`use Foo\{Bar, Baz};`) and `use function`/`use const`
	 * imports are not produced by this codebase's class references and are skipped.
	 *
	 * @param string $file Absolute file path.
	 * @return array<string,string>
	 */
	private function native_file_use_imports( string $file ): array {
		$tokens  = $this->native_tokenize( $file );
		$imports = array();

		for ( $i = 0, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_USE !== $token[0] ) {
				continue;
			}

			$next_index = $this->native_next_significant_token_index( $tokens, $i + 1 );
			$next       = null !== $next_index ? $tokens[ $next_index ] : null;
			if ( is_array( $next ) && in_array( $next[0], array( T_FUNCTION, T_CONST ), true ) ) {
				continue;
			}

			$name_start = $i + 1;
			$fqcn       = $this->native_read_qualified_name( $tokens, $name_start );
			if ( '' === $fqcn || false !== strpos( $fqcn, '{' ) ) {
				continue;
			}

			// Advance past the name we just read to look for an `as Alias` clause before the `;`.
			$cursor = $name_start;
			for ( $j = $name_start, $jcount = count( $tokens ); $j < $jcount; $j++ ) {
				$candidate = $tokens[ $j ];
				if ( is_array( $candidate ) && in_array( $candidate[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NS_SEPARATOR ), true ) ) {
					$cursor = $j;
					continue;
				}
				if ( defined( 'T_NAME_QUALIFIED' ) && is_array( $candidate ) && in_array( $candidate[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$cursor = $j;
					continue;
				}
				break;
			}

			$after_index = $this->native_next_significant_token_index( $tokens, $cursor + 1 );
			$after       = null !== $after_index ? $tokens[ $after_index ] : null;
			$alias       = null;
			if ( is_array( $after ) && T_AS === $after[0] ) {
				$alias_index = $this->native_next_significant_token_index( $tokens, $cursor + 2 );
				$alias_token = null !== $alias_index ? $tokens[ $alias_index ] : null;
				if ( is_array( $alias_token ) && T_STRING === $alias_token[0] ) {
					$alias = $alias_token[1];
				}
			}

			$short                       = false !== strrpos( $fqcn, '\\' ) ? substr( $fqcn, strrpos( $fqcn, '\\' ) + 1 ) : $fqcn;
			$imports[ $alias ?? $short ] = $fqcn;
		}

		return $imports;
	}

	/**
	 * Resolve a `self::CONST`, `static::CONST`, or `ClassName::CONST` reference found in a native
	 * source file to its runtime string value, via the class's own namespace/use-import context and
	 * `ReflectionClassConstant`, which reads a constant's value regardless of its visibility (unlike
	 * `constant()`/`defined()`, which only see a `public` one from outside the class) — needed here
	 * because several of the constants this scanner resolves are declared `private`. The classes in
	 * the scanned roots autoload under PHPUnit, so reflection sees the real declared value.
	 *
	 * @param string $file       Absolute file path the reference was found in.
	 * @param string $class_ref  The token text before `::` (`self`, `static`, `parent`, or a class name).
	 * @param string $const_name The constant name after `::`.
	 * @return string|null
	 */
	private function native_resolve_constant_reference( string $file, string $class_ref, string $const_name ): ?string {
		if ( in_array( $class_ref, array( 'self', 'static', 'parent' ), true ) ) {
			$fqcn = $this->native_file_own_class( $file );
		} else {
			$imports = $this->native_file_use_imports( $file );
			$fqcn    = $imports[ $class_ref ] ?? null;

			if ( null === $fqcn ) {
				$own_class = $this->native_file_own_class( $file );
				$namespace = null !== $own_class && false !== strrpos( $own_class, '\\' )
					? substr( $own_class, 0, strrpos( $own_class, '\\' ) )
					: '';
				$fqcn      = '' === $namespace ? $class_ref : $namespace . '\\' . $class_ref;
			}
		}

		if ( null === $fqcn || ! class_exists( $fqcn ) || ! ( new \ReflectionClass( $fqcn ) )->hasConstant( $const_name ) ) {
			return null;
		}

		$value = ( new \ReflectionClassConstant( $fqcn, $const_name ) )->getValue();

		return is_string( $value ) ? $value : null;
	}
}
