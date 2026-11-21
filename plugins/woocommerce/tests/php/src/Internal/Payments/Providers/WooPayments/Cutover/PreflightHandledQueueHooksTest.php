<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use WC_Unit_Test_Case;

/**
 * Keeps the preflight's list of handled queue hooks complete: every Action Scheduler job the built-in WooPayments
 * schedules under the prefixes the preflight scans is on that list, or named here with the reason it is not.
 *
 * A job missing from the list is read as an action nothing handles, so a pending one holds the switch back, and
 * nothing unschedules it when the merchant returns to the extension.
 */
class PreflightHandledQueueHooksTest extends WC_Unit_Test_Case {

	/**
	 * Hook prefixes the preflight scans the queue for.
	 */
	private const SCANNED_PREFIXES = array( 'wcpay_', 'woocommerce_woopayments_' );

	/**
	 * Scheduling functions and the position of the hook among their arguments.
	 */
	private const SCHEDULING_CALLS = array(
		'schedule_job'                 => 0,
		'schedule_recurring_job'       => 0,
		'as_enqueue_async_action'      => 0,
		'as_schedule_single_action'    => 1,
		'as_schedule_recurring_action' => 2,
	);

	/**
	 * Scheduled hooks that are deliberately not on the handled list, each with the reason.
	 *
	 * @return array<string,string>
	 */
	private function exceptions(): array {
		return array(
			CutoverActionScheduler::ACTION_HOOK => 'The cutover job is the switch itself; the preflight skips it by its hook and group.',
		);
	}

	/**
	 * @testdox Every job the built-in WooPayments schedules is on the handled list or named as an exception.
	 */
	public function test_every_scheduled_job_is_handled_or_an_exception(): void {
		$handled   = ( new \ReflectionClassConstant( PreflightService::class, 'BUILTIN_HANDLED_QUEUE_HOOKS' ) )->getValue();
		$scheduled = $this->scheduled_hooks();

		$unlisted = array_values( array_diff( array_keys( $scheduled ), $handled, array_keys( $this->exceptions() ) ) );
		$this->assertSame( array(), $unlisted, 'Add the hook to PreflightService::BUILTIN_HANDLED_QUEUE_HOOKS, or name it in exceptions() with the reason. Scheduled in: ' . wp_json_encode( array_intersect_key( $scheduled, array_flip( $unlisted ) ) ) );
	}

	/**
	 * @testdox The scan finds the jobs it must, and every named exception is still scheduled.
	 */
	public function test_the_scan_finds_known_jobs_and_no_exception_is_stale(): void {
		$scheduled = array_keys( $this->scheduled_hooks() );

		$this->assertGreaterThanOrEqual( 15, count( $scheduled ) );
		$this->assertContains( 'woocommerce_woopayments_retry_stripe_billing_first_invoice', $scheduled );
		$this->assertContains( 'wcpay_webhook_process_event', $scheduled );
		$this->assertSame( array(), array_values( array_diff( array_keys( $this->exceptions() ), $scheduled ) ), 'A named exception is no longer scheduled; remove it.' );
	}

	/**
	 * Hooks the payments source schedules under the scanned prefixes, each with the files that schedule it.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function scheduled_hooks(): array {
		$directory = WC()->plugin_path() . '/src/Internal/Payments/';
		$hooks     = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a completeness assertion.
			$source = $this->without_comments( (string) file_get_contents( $file->getPathname() ) );
			foreach ( $this->hook_expressions( $source ) as $expression ) {
				foreach ( $this->resolve_hooks( $expression, $source ) as $hook ) {
					$hooks[ $hook ][] = basename( $file->getPathname() );
				}
			}
		}

		ksort( $hooks );
		return array_map( 'array_unique', $hooks );
	}

	/**
	 * Strip comments, so a call named in a docblock is not read as a call.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function without_comments( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * The expression passed as the hook of each scheduling call in the source, without the arguments of nested calls.
	 *
	 * @param string $source PHP source without comments.
	 * @return string[]
	 */
	private function hook_expressions( string $source ): array {
		$expressions = array();
		$names       = implode( '|', array_keys( self::SCHEDULING_CALLS ) );
		preg_match_all( '/\b(' . $names . ')\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$offset = $match[0][1] + strlen( $match[0][0] );
			$depth  = 1;
			$args   = array( '' );
			for ( $i = $offset, $length = strlen( $source ); $i < $length && $depth > 0; $i++ ) {
				$char = $source[ $i ];
				if ( '(' === $char || '[' === $char ) {
					++$depth;
				} elseif ( ')' === $char || ']' === $char ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $char && 1 === $depth ) {
					$args[] = '';
					continue;
				}
				$args[ count( $args ) - 1 ] .= $char;
			}

			$expression = $args[ self::SCHEDULING_CALLS[ $match[1][0] ] ] ?? '';
			do {
				$expression = preg_replace( '/\([^()]*\)/', '', $expression, -1, $replaced );
			} while ( $replaced > 0 );
			$expressions[] = $expression;
		}

		return $expressions;
	}

	/**
	 * The hook names in a hook expression: string literals and class constants, with the scanned prefixes.
	 *
	 * @param string $expression Hook expression.
	 * @param string $source     The file's PHP source, which names its class, namespace and imports.
	 * @return string[]
	 */
	private function resolve_hooks( string $expression, string $source ): array {
		$values = array();
		if ( preg_match_all( "/'([^']*)'/", $expression, $literals ) ) {
			$values = $literals[1];
		}
		if ( preg_match_all( '/([\\\\\w]+)::([A-Z][A-Z0-9_]*)/', $expression, $constants, PREG_SET_ORDER ) ) {
			foreach ( $constants as $constant ) {
				$value = $this->read_string_constant( $this->resolve_class( $constant[1], $source ), $constant[2] );
				if ( null !== $value ) {
					$values[] = $value;
				}
			}
		}

		return array_values(
			array_filter(
				$values,
				static function ( $value ): bool {
					foreach ( self::SCANNED_PREFIXES as $prefix ) {
						if ( is_string( $value ) && 0 === strpos( $value, $prefix ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Read a string constant from a class's source file, without loading the class: some payments classes extend
	 * classes of plugins that are not installed in the test environment.
	 *
	 * @param string|null $class_name Full class name.
	 * @param string      $name       Constant name.
	 * @return string|null The value, or null when the constant is not a string literal there.
	 */
	private function read_string_constant( ?string $class_name, string $name ): ?string {
		if ( null === $class_name || 0 !== strpos( $class_name, 'Automattic\\WooCommerce\\' ) ) {
			return null;
		}

		$path = WC()->plugin_path() . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'Automattic\\WooCommerce\\' ) ) ) . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a completeness assertion.
		$source = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		return preg_match( '/\bconst\s+' . preg_quote( $name, '/' ) . "\\s*=\\s*'([^']*)'\\s*;/", $source, $match ) ? $match[1] : null;
	}

	/**
	 * Resolve a class name written in the source to its full name.
	 *
	 * @param string $name   Name as written.
	 * @param string $source The file's PHP source.
	 * @return string|null
	 */
	private function resolve_class( string $name, string $source ): ?string {
		preg_match( '/^namespace\s+([\w\\\\]+);/m', $source, $namespace );
		$namespace = $namespace[1] ?? '';
		if ( in_array( $name, array( 'self', 'static' ), true ) ) {
			return preg_match( '/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $source, $class ) ? $namespace . '\\' . $class[1] : null;
		}
		if ( 0 === strpos( $name, '\\' ) ) {
			return ltrim( $name, '\\' );
		}
		$first = strtok( $name, '\\' );
		if ( preg_match( '/^use\s+([\w\\\\]+\\\\' . preg_quote( (string) $first, '/' ) . ');/m', $source, $import ) ) {
			return $import[1] . substr( $name, strlen( (string) $first ) );
		}

		return $namespace . '\\' . $name;
	}
}
