<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
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
	 * Calls that forward hooks checked at their originating scheduling calls.
	 * Each entry matches one caller, scheduling method and hook expression.
	 */
	private const FORWARDED_HOOKS = array(
		'WooPaymentsActionSchedulerService::schedule_job as_schedule_single_action $hook'                     => 'Forwards the hook supplied to schedule_job().',
		'WooPaymentsActionSchedulerService::schedule_recurring_job as_schedule_recurring_action $hook'        => 'Forwards the hook supplied to schedule_recurring_job().',
		"WooPaymentsActionSchedulerService::handle_action_scheduler_init schedule_job \$job['hook']"          => 'Replays a job saved by schedule_job() before initialization.',
		'WooPaymentsCanceledAuthorizationFeeRemediationService::schedule_job as_schedule_single_action $hook' => 'Forwards the hook supplied to the private remediation scheduler.',
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
		$parser    = ( new ParserFactory() )->createForNewestSupportedVersion();
		$traverser = new NodeTraverser( new NameResolver(), new ParentConnectingVisitor( true ) );
		$finder    = new NodeFinder();
		$constants = array();
		$calls     = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source for a completeness assertion.
			$source = (string) file_get_contents( $file->getPathname() );
			$nodes  = $traverser->traverse( $parser->parse( $source ) );
			foreach ( $finder->findInstanceOf( $nodes, Node\Stmt\ClassLike::class ) as $class ) {
				$class_name = $this->class_name( $class );
				if ( '' === $class_name ) {
					continue;
				}
				foreach ( $class->getConstants() as $declaration ) {
					foreach ( $declaration->consts as $constant ) {
						if ( $constant->value instanceof Node\Scalar\String_ ) {
							$constants[ $class_name . '::' . $constant->name ] = $constant->value->value;
						}
					}
				}
			}

			$file_name = substr( $file->getPathname(), strlen( $directory ) );
			foreach ( $this->scheduling_calls( $nodes, $file_name, $source ) as $call ) {
				$calls[] = $call;
			}
		}

		$hooks     = array();
		$forwarded = self::FORWARDED_HOOKS;
		foreach ( $calls as list( $expression, $class_name, $caller, $location ) ) {
			if ( isset( $forwarded[ $caller ] ) ) {
				unset( $forwarded[ $caller ] );
				continue;
			}

			$branches = $expression instanceof Expr\Ternary ? array( $expression->if, $expression->else ) : array( $expression );
			foreach ( $branches as $branch ) {
				$hook = $this->resolve_hook( $branch, $class_name, $constants );
				$this->assertNotNull( $hook, "Cannot resolve scheduled hook at $location ($caller)." );
				foreach ( self::SCANNED_PREFIXES as $prefix ) {
					if ( 0 === strpos( $hook, $prefix ) ) {
						$hooks[ $hook ][] = $location;
					}
				}
			}
		}
		$this->assertSame( array(), array_keys( $forwarded ), 'Remove stale forwarded hook exceptions.' );
		ksort( $hooks );
		return $hooks;
	}

	/**
	 * Find scheduling calls in any scope, including the forwarding call inside a new wrapper.
	 *
	 * @param Node[] $nodes  Parsed source.
	 * @param string $file   Relative source path.
	 * @param string $source Original PHP source.
	 * @return \Generator
	 */
	private function scheduling_calls( array $nodes, string $file, string $source ): \Generator {
		foreach ( ( new NodeFinder() )->findInstanceOf( $nodes, Expr\CallLike::class ) as $call ) {
			$name = $call->name ?? null;
			if ( ! $name instanceof Node\Identifier && ! $name instanceof Node\Name ) {
				continue;
			}
			$name = strtolower( $name instanceof Node\Name ? $name->getLast() : $name->toString() );
			if ( ! isset( self::SCHEDULING_CALLS[ $name ] ) && 'schedule_repair' !== $name ) {
				continue;
			}
			if ( $call->isFirstClassCallable() ) {
				continue;
			}

			$class  = null;
			$method = '';
			$node   = $call;
			while ( $node->hasAttribute( 'weak_parent' ) ) {
				$node = $node->getAttribute( 'weak_parent' )->get();
				if ( $node instanceof Node\Stmt\ClassMethod ) {
					$method = $node->name->toString();
				}
				if ( $node instanceof Node\Stmt\ClassLike ) {
					$class = $node;
					break;
				}
			}

			if ( 'schedule_repair' === $name ) {
				// The inherited WCS_Background_Upgrader method schedules the hook in this property.
				$property   = $class ? $class->getProperty( 'scheduled_hook' ) : null;
				$expression = null;
				foreach ( $property->props ?? array() as $item ) {
					if ( 'scheduled_hook' === $item->name->toString() ) {
						$expression = $item->default;
					}
				}
			} else {
				$argument   = $call->getArg( 'hook', self::SCHEDULING_CALLS[ $name ] );
				$expression = $argument->value ?? null;
			}
			$hook_text = $expression ? substr( $source, $expression->getStartFilePos(), $expression->getEndFilePos() - $expression->getStartFilePos() + 1 ) : '(missing hook)';
			$caller    = ( $class->name ?? '' ) . '::' . $method . ' ' . $name . ' ' . $hook_text;
			$location  = $file . ':' . $call->name->getStartLine();
			yield array( $expression, $this->class_name( $class ), $caller, $location );
		}
	}

	/**
	 * Get the fully qualified class name assigned by the parser.
	 *
	 * @param Node\Stmt\ClassLike|null $declaration Enclosing class, when present.
	 * @return string
	 */
	private function class_name( ?Node\Stmt\ClassLike $declaration ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP-Parser's resolved class name.
		return (string) ( $declaration->namespacedName ?? '' );
	}

	/**
	 * Resolve a complete hook expression using string constants from the source, without loading optional plugins.
	 *
	 * @param Expr|null            $expression Hook expression.
	 * @param string               $class_name Enclosing class.
	 * @param array<string,string> $constants  Declared string constants.
	 * @return string|null
	 */
	private function resolve_hook( ?Expr $expression, string $class_name, array $constants ): ?string {
		if ( $expression instanceof Node\Scalar\String_ ) {
			return $expression->value;
		}
		if ( $expression instanceof Expr\ClassConstFetch && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier ) {
			$name = $expression->class->toString();
			if ( in_array( $name, array( 'self', 'static' ), true ) ) {
				$name = $class_name;
			}
			return $constants[ $name . '::' . $expression->name ] ?? null;
		}
		if ( $expression instanceof Expr\BinaryOp\Concat ) {
			$left  = $this->resolve_hook( $expression->left, $class_name, $constants );
			$right = $this->resolve_hook( $expression->right, $class_name, $constants );
			return null === $left || null === $right ? null : $left . $right;
		}

		return null;
	}
}
