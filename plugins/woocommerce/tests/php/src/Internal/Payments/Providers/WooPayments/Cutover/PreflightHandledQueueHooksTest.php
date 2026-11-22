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
 * A job missing from the list is read as an action nothing handles, so a pending one holds the switch back until it
 * runs.
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
	 * Inherited scheduling methods that take no hook, each with the property holding the hook they schedule.
	 * WooCommerce Subscriptions' `WCS_Background_Upgrader::schedule_repair()` schedules `$this->scheduled_hook`.
	 */
	private const INHERITED_SCHEDULING_CALLS = array(
		'schedule_repair' => '$this->scheduled_hook',
	);

	/**
	 * Pattern for one part of a hook expression: a string literal, a class constant or a property of `$this`.
	 */
	private const OPERAND = '\'[^\']*\'|[\\\\\w]+::[A-Z][A-Z0-9_]*|\$this->\w+';

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
		$this->assertContains( 'wcpay_migrate_subscription_retry', $scheduled );
		$this->assertContains( 'wcpay_schedule_subscription_migrations', $scheduled );
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
		$names       = implode( '|', array_merge( array_keys( self::SCHEDULING_CALLS ), array_keys( self::INHERITED_SCHEDULING_CALLS ) ) );
		preg_match_all( '/\b(' . $names . ')\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( isset( self::INHERITED_SCHEDULING_CALLS[ $match[1][0] ] ) ) {
				$expressions[] = self::INHERITED_SCHEDULING_CALLS[ $match[1][0] ];
				continue;
			}

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
	 * The hook names in a hook expression, with the scanned prefixes. Each run of parts joined by `.` is one name; a part
	 * the scan cannot read is kept as `*` in a joined name, so the test fails on it instead of reading a wrong name.
	 *
	 * @param string $expression Hook expression.
	 * @param string $source     The file's PHP source, which names its class, namespace and imports.
	 * @return string[]
	 */
	private function resolve_hooks( string $expression, string $source ): array {
		$values = array();
		preg_match_all( '/(?:' . self::OPERAND . ')(?:\s*\.\s*(?:' . self::OPERAND . '))*/', $expression, $chains );
		foreach ( $chains[0] as $chain ) {
			preg_match_all( '/' . self::OPERAND . '/', $chain, $parts );
			$value = '';
			foreach ( $parts[0] as $part ) {
				$part_value = $this->resolve_operand( $part, $source );
				if ( null === $part_value && 1 === count( $parts[0] ) ) {
					continue 2;
				}
				$value .= $part_value ?? '*';
			}
			$values[] = $value;
		}

		return array_values(
			array_filter(
				$values,
				static function ( string $value ): bool {
					foreach ( self::SCANNED_PREFIXES as $prefix ) {
						if ( 0 === strpos( $value, $prefix ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * The string value of one part of a hook expression. A property of `$this` is read from its declared default.
	 *
	 * @param string $operand A string literal, a class constant or a property of `$this`.
	 * @param string $source  The file's PHP source.
	 * @return string|null The value, or null when the source does not give it as a string.
	 */
	private function resolve_operand( string $operand, string $source ): ?string {
		if ( "'" === $operand[0] ) {
			return substr( $operand, 1, -1 );
		}
		if ( preg_match( '/^\$this->(\w+)$/', $operand, $property ) ) {
			if ( ! preg_match( '/\$' . preg_quote( $property[1], '/' ) . '\s*=\s*(' . self::OPERAND . ')\s*;/', $source, $declared ) ) {
				return null;
			}
			// A local variable copied from the property, such as `$hook = $this->hook;`, is not its default.
			return 0 === strpos( $declared[1], '$this->' ) ? null : $this->resolve_operand( $declared[1], $source );
		}
		list( $class_name, $constant ) = explode( '::', $operand );

		return $this->read_string_constant( $this->resolve_class( $class_name, $source ), $constant );
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
