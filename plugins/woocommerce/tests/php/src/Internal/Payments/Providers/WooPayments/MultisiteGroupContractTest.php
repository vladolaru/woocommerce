<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The shared multisite runner selects tests by their PHPUnit group.
 */
class MultisiteGroupContractTest extends TestCase {

	/**
	 * @testdox Tests that require multisite belong to the group selected by the multisite runner.
	 */
	public function test_multisite_required_tests_are_selected_by_the_runner(): void {
		$files   = Finder::create()->files()->in( dirname( __DIR__, 5 ) )->name( '*.php' )->contains( 'ms-required' );
		$parser  = ( new ParserFactory() )->createForNewestSupportedVersion();
		$finder  = new NodeFinder();
		$missing = array();

		foreach ( $files as $file ) {
			$nodes = $parser->parse( $file->getContents() );
			foreach ( $finder->findInstanceOf( $nodes, ClassLike::class ) as $class ) {
				$groups = $this->groups( $class );
				if ( in_array( 'multisite', $groups, true ) ) {
					continue;
				}

				$path = $file->getRelativePathname();
				if ( in_array( 'ms-required', $groups, true ) ) {
					$missing[] = $path . ':' . $class->getStartLine();
				}

				foreach ( $class->getMethods() as $method ) {
					$name = $method->name->toString();
					// The upstream post-types test is outside this contract.
					if ( 'includes/class-wc-post-types-test.php' === $path && 'test_deferred_flush_is_scoped_to_the_current_site' === $name ) {
						continue;
					}

					$groups = $this->groups( $method );
					if ( in_array( 'ms-required', $groups, true ) && ! in_array( 'multisite', $groups, true ) ) {
						$missing[] = $path . '::' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), $missing, 'Add the multisite group to these tests so the shared runner executes them.' );
	}

	/**
	 * Read group annotations from a class or method docblock.
	 *
	 * @param Node $node Declaration to inspect.
	 * @return string[]
	 */
	private function groups( Node $node ): array {
		preg_match_all( '~^\s*(?:/\*\*|\*)\s*@group[ \t]+([^\s*]+)~m', (string) $node->getDocComment(), $matches );
		return $matches[1];
	}
}
