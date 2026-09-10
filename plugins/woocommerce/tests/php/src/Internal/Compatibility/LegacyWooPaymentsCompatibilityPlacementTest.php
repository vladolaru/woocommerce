<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Compatibility;

use WC_Unit_Test_Case;

/**
 * Tests the placement and removal contract for legacy WooPayments compatibility symbols.
 */
class LegacyWooPaymentsCompatibilityPlacementTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The placement scanner recognizes every sanctioned plugin-shaped declaration.
	 */
	public function test_scanner_recognizes_sanctioned_declarations(): void {
		$plugin_directory = WC()->plugin_path();
		$expected_symbols = array(
			$plugin_directory . '/src/Internal/Payments/Providers/WooPayments/Compat/legacy/class-wc-payments.php'          => array( 'WC_Payments' ),
			$plugin_directory . '/src/Internal/Payments/Providers/WooPayments/Compat/legacy/class-wc-payments-features.php' => array( 'WC_Payments_Features' ),
			$plugin_directory . '/src/Internal/MultiCurrency/Compat/legacy/MultiCurrency.php'                              => array( 'WCPay\\MultiCurrency\\MultiCurrency' ),
		);

		foreach ( $expected_symbols as $file => $symbols ) {
			$this->assertSame( $symbols, $this->get_plugin_shaped_declarations( $file ), $file . ' must remain visible to the compatibility placement scanner.' );
		}
	}

	/**
	 * @testdox The placement scanner ignores declaration trivia and consumer-only class references.
	 */
	public function test_scanner_handles_declaration_trivia_and_anonymous_classes(): void {
		$namespaced_source = <<<'PHP'
<?php
namespace /* legal trivia */ WCPay\OutsideCompat;
class Facade {}
PHP;
		$consumer_source   = <<<'PHP'
<?php
$consumer = new class extends WC_Payments_Adapter {};
$reference = WC_Payments_Features::class;
PHP;

		$this->assertSame( array( 'WCPay\\OutsideCompat\\Facade' ), $this->get_plugin_shaped_declarations_from_source( $namespaced_source ) );
		$this->assertSame( array(), $this->get_plugin_shaped_declarations_from_source( $consumer_source ) );
	}

	/**
	 * @testdox Plugin-shaped compatibility declarations stay inside explicit Compat boundaries.
	 */
	public function test_plugin_shaped_declarations_stay_inside_compat_boundaries(): void {
		$plugin_directory = WC()->plugin_path();
		$violations       = array();

		foreach ( array( 'src', 'includes' ) as $source_directory ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugin_directory . '/' . $source_directory ) );

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}

				$path = str_replace( '\\', '/', $file->getPathname() );
				if ( false !== strpos( $path, '/Compat/' ) ) {
					continue;
				}

				foreach ( $this->get_plugin_shaped_declarations( $file->getPathname() ) as $declaration ) {
					$violations[] = substr( $path, strlen( $plugin_directory ) + 1 ) . ': ' . $declaration;
				}
			}
		}

		$this->assertSame( array(), $violations, 'Plugin-shaped compatibility declarations must stay in a removable Compat directory.' );
	}

	/**
	 * @testdox Each compatibility loader has exactly one composition-root registration.
	 */
	public function test_each_loader_has_exactly_one_composition_root_registration(): void {
		$woocommerce_file = WC()->plugin_path() . '/includes/class-woocommerce.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading immutable local production source for a placement assertion.
		$source = (string) file_get_contents( $woocommerce_file );

		$loaders = array(
			'WooPayments facade loader'   => 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Compat\\LegacyFacadeLoader',
			'MultiCurrency facade loader' => 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Compat\\LegacyMultiCurrencyFacadeLoader',
		);

		$registrations = $this->get_composition_root_registrations( $source );

		foreach ( $loaders as $label => $loader ) {
			$this->assertSame( 1, count( array_filter( $registrations, static fn( string $registration ): bool => 0 === strcasecmp( $loader, $registration ) ) ), $label . ' must have exactly one dependency-injection registration in the WooCommerce composition root.' );
		}
	}

	/**
	 * @testdox Composition-root registration counting resolves aliases and ignores formatting trivia.
	 */
	public function test_composition_root_registration_counting_resolves_aliases_and_trivia(): void {
		$payments_loader       = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Compat\\LegacyFacadeLoader';
		$multi_currency_loader = 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Compat\\LegacyMultiCurrencyFacadeLoader';
		$source                = <<<'PHP'
<?php
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\{LegacyAdminLinkHandler, LegacyFacadeLoader as PaymentsCompatibilityLoader};
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter, Automattic\WooCommerce\Internal\MultiCurrency\Compat\LegacyMultiCurrencyFacadeLoader as MultiCurrencyCompatibilityLoader;
$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\LegacyFacadeLoader::class )->register();
$container
	->get( PaymentsCompatibilityLoader /* trivia */ :: class )
	->register();
$container->get( MultiCurrencyCompatibilityLoader::class )->register();
PHP;

		$this->assertSame( array( $payments_loader, $payments_loader, $multi_currency_loader ), $this->get_composition_root_registrations( $source ) );
	}

	/**
	 * @testdox Each removable compatibility boundary documents its removal contract.
	 */
	public function test_each_compatibility_boundary_has_removal_documentation(): void {
		$plugin_directory = WC()->plugin_path();
		$readme_files     = array(
			$plugin_directory . '/src/Internal/Payments/Providers/WooPayments/Compat/README.md',
			$plugin_directory . '/src/Internal/MultiCurrency/Compat/README.md',
		);

		foreach ( $readme_files as $readme_file ) {
			$this->assertFileExists( $readme_file, $readme_file . ' must document the compatibility boundary and its removal.' );
		}
	}

	/**
	 * Get plugin-shaped type declarations from a PHP source file.
	 *
	 * @param string $file Source file.
	 * @return list<string>
	 */
	private function get_plugin_shaped_declarations( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading immutable local production source for a placement assertion.
		$source = (string) file_get_contents( $file );

		return $this->get_plugin_shaped_declarations_from_source( $source );
	}

	/**
	 * Get plugin-shaped type declarations from PHP source.
	 *
	 * @param string $source PHP source.
	 * @return list<string>
	 */
	private function get_plugin_shaped_declarations_from_source( string $source ): array {
		if ( false === stripos( $source, 'WC_Payments' ) && false === stripos( $source, 'WCPay' ) ) {
			return array();
		}

		$tokens             = token_get_all( $source );
		$namespace          = '';
		$declarations       = array();
		$declaration_tokens = array( T_CLASS, T_INTERFACE, T_TRAIT );

		if ( defined( 'T_ENUM' ) ) {
			$declaration_tokens[] = constant( 'T_ENUM' );
		}

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_NAMESPACE === $token[0] ) {
				$namespace = $this->read_namespace( $tokens, $index + 1 );
				continue;
			}

			if ( ! in_array( $token[0], $declaration_tokens, true ) ) {
				continue;
			}

			$type_name = $this->read_type_name( $tokens, $index + 1 );
			if ( null === $type_name ) {
				continue;
			}

			if ( '' === $namespace && 0 === stripos( $type_name, 'WC_Payments' ) ) {
				$declarations[] = $type_name;
			}

			if ( 0 === strcasecmp( $namespace, 'WCPay' ) || 0 === stripos( $namespace, 'WCPay\\' ) ) {
				$declarations[] = $namespace . '\\' . $type_name;
			}
		}

		return $declarations;
	}

	/**
	 * Read a namespace name from PHP tokens.
	 *
	 * @param array<int,mixed> $tokens      PHP tokens.
	 * @param int              $start_index First token after T_NAMESPACE.
	 * @return string
	 */
	private function read_namespace( array $tokens, int $start_index ): string {
		$namespace = '';

		for ( $index = $start_index, $count = count( $tokens ); $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( ';' === $token || '{' === $token ) {
				break;
			}

			if ( is_array( $token ) && $this->is_name_token( $token[0] ) ) {
				$namespace .= $token[1];
			}
		}

		return trim( $namespace );
	}

	/**
	 * Read a declared type name from PHP tokens.
	 *
	 * @param array<int,mixed> $tokens      PHP tokens.
	 * @param int              $start_index First token after the declaration keyword.
	 * @return string|null
	 */
	private function read_type_name( array $tokens, int $start_index ): ?string {
		for ( $index = $start_index, $count = count( $tokens ); $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) ) {
				if ( T_STRING === $token[0] ) {
					return $token[1];
				}

				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				return null;
			}

			return null;
		}

		return null;
	}

	/**
	 * Read dependency-injection registrations from a PHP composition root.
	 *
	 * @param string $source PHP source.
	 * @return list<string>
	 */
	private function get_composition_root_registrations( string $source ): array {
		$tokens        = $this->get_significant_tokens( $source );
		$imports       = $this->get_class_imports( $tokens );
		$registrations = array();

		for ( $index = 0, $count = count( $tokens ); $index < $count; ++$index ) {
			if ( ! $this->tokens_match( $tokens, $index, array( '$container', '->', 'get', '(' ) ) ) {
				continue;
			}

			$name_index = $index + 4;
			$class_name = $this->read_class_name( $tokens, $name_index );
			if ( null === $class_name || ! $this->tokens_match( $tokens, $name_index, array( '::', 'class', ')', '->', 'register', '(', ')', ';' ) ) ) {
				continue;
			}

			$registrations[] = $this->resolve_class_name( $class_name, $imports );
		}

		return $registrations;
	}

	/**
	 * Remove whitespace and comments while retaining token identity.
	 *
	 * @param string $source PHP source.
	 * @return list<array{id:int|null,text:string}>
	 */
	private function get_significant_tokens( string $source ): array {
		$significant_tokens = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ), true ) ) {
					continue;
				}

				$significant_tokens[] = array(
					'id'   => $token[0],
					'text' => $token[1],
				);
				continue;
			}

			$significant_tokens[] = array(
				'id'   => null,
				'text' => $token,
			);
		}

		return $significant_tokens;
	}

	/**
	 * Read top-level class imports from significant PHP tokens.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens Significant PHP tokens.
	 * @return array<string,string>
	 */
	private function get_class_imports( array $tokens ): array {
		$imports = array();
		$depth   = 0;

		for ( $index = 0, $count = count( $tokens ); $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( '{' === $token['text'] ) {
				++$depth;
				continue;
			}
			if ( '}' === $token['text'] ) {
				--$depth;
				continue;
			}
			if ( 0 !== $depth || T_USE !== $token['id'] ) {
				continue;
			}

			$use_tokens = array();
			for ( $use_index = $index + 1; $use_index < $count && ';' !== $tokens[ $use_index ]['text']; ++$use_index ) {
				$use_tokens[] = $tokens[ $use_index ];
			}
			$index   = $use_index;
			$imports = array_merge( $imports, $this->parse_class_imports( $use_tokens ) );
		}

		return $imports;
	}

	/**
	 * Parse one top-level class-import statement.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens Tokens between T_USE and the terminating semicolon.
	 * @return array<string,string>
	 */
	private function parse_class_imports( array $tokens ): array {
		$group_start = null;
		$group_end   = null;
		foreach ( $tokens as $index => $token ) {
			if ( '{' === $token['text'] ) {
				$group_start = $index;
			}
			if ( '}' === $token['text'] ) {
				$group_end = $index;
			}
		}

		$prefix       = '';
		$entry_tokens = $tokens;
		if ( null !== $group_start && null !== $group_end && $group_end > $group_start ) {
			$prefix       = $this->join_name_tokens( array_slice( $tokens, 0, $group_start ) );
			$entry_tokens = array_slice( $tokens, $group_start + 1, $group_end - $group_start - 1 );
		}

		$imports = array();
		$entries = array( array() );
		foreach ( $entry_tokens as $token ) {
			if ( ',' === $token['text'] ) {
				$entries[] = array();
				continue;
			}
			$entries[ count( $entries ) - 1 ][] = $token;
		}

		foreach ( $entries as $entry ) {
			$import = $this->parse_class_import( $entry, $prefix );
			if ( null !== $import ) {
				$imports[ strtolower( $import['alias'] ) ] = $import['class'];
			}
		}

		return $imports;
	}

	/**
	 * Parse one class import entry.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens Import entry tokens.
	 * @param string                               $prefix Grouped-use prefix.
	 * @return array{alias:string,class:string}|null
	 */
	private function parse_class_import( array $tokens, string $prefix ): ?array {
		$name_tokens  = array();
		$alias        = '';
		$reading_name = true;

		foreach ( $tokens as $token ) {
			if ( T_FUNCTION === $token['id'] || T_CONST === $token['id'] ) {
				return null;
			}
			if ( T_AS === $token['id'] ) {
				$reading_name = false;
				continue;
			}
			if ( ! $this->is_name_token( $token['id'] ) ) {
				continue;
			}
			if ( $reading_name ) {
				$name_tokens[] = $token;
			} else {
				$alias .= $token['text'];
			}
		}

		$name = $this->join_name_tokens( $name_tokens );
		if ( '' === $name ) {
			return null;
		}

		$class_name = '' === $prefix ? $name : rtrim( $prefix, '\\' ) . '\\' . ltrim( $name, '\\' );
		if ( '' === $alias ) {
			$parts = explode( '\\', $class_name );
			$alias = (string) end( $parts );
		}

		return array(
			'alias' => $alias,
			'class' => ltrim( $class_name, '\\' ),
		);
	}

	/**
	 * Join PHP name tokens.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens PHP name tokens.
	 */
	private function join_name_tokens( array $tokens ): string {
		$name = '';
		foreach ( $tokens as $token ) {
			if ( $this->is_name_token( $token['id'] ) ) {
				$name .= $token['text'];
			}
		}

		return $name;
	}

	/**
	 * Read a class name and advance to the first token after it.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens  Significant PHP tokens.
	 * @param int                                  $index   Current token index, advanced by reference.
	 * @return string|null
	 */
	private function read_class_name( array $tokens, int &$index ): ?string {
		$class_name = '';

		$count = count( $tokens );
		while ( $index < $count && $this->is_name_token( $tokens[ $index ]['id'] ) ) {
			$class_name .= $tokens[ $index ]['text'];
			++$index;
		}

		return '' === $class_name ? null : $class_name;
	}

	/**
	 * Determine whether token texts match at an offset.
	 *
	 * @param list<array{id:int|null,text:string}> $tokens    Significant PHP tokens.
	 * @param int                                  $offset    Starting offset.
	 * @param array                                $expected  Expected token texts.
	 */
	private function tokens_match( array $tokens, int $offset, array $expected ): bool {
		foreach ( $expected as $expected_text ) {
			if ( ! isset( $tokens[ $offset ] ) || 0 !== strcasecmp( $expected_text, $tokens[ $offset ]['text'] ) ) {
				return false;
			}
			++$offset;
		}

		return true;
	}

	/**
	 * Resolve an imported or fully qualified class name.
	 *
	 * @param string               $class_name Class name from source.
	 * @param array<string,string> $imports    Lowercase aliases mapped to class names.
	 */
	private function resolve_class_name( string $class_name, array $imports ): string {
		$class_name = ltrim( $class_name, '\\' );
		$parts      = explode( '\\', $class_name, 2 );
		$alias      = strtolower( $parts[0] );

		if ( ! isset( $imports[ $alias ] ) ) {
			return $class_name;
		}

		return $imports[ $alias ] . ( isset( $parts[1] ) ? '\\' . $parts[1] : '' );
	}

	/**
	 * Whether a token can contribute to a PHP name.
	 *
	 * @param int|null $token_id Token ID.
	 */
	private function is_name_token( ?int $token_id ): bool {
		$name_token_ids = array( T_STRING, T_NS_SEPARATOR );

		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $token_name ) {
			if ( defined( $token_name ) ) {
				$name_token_ids[] = constant( $token_name );
			}
		}

		return in_array( $token_id, $name_token_ids, true );
	}
}
