<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin;

use Automattic\WooCommerce\Admin\Features\Features;
use Automattic\WooCommerce\Internal\Admin\WcPayWelcomePage;
use ReflectionClass;
use ReflectionProperty;
use WC_Unit_Test_Case;

/**
 * Tests for the deprecated WcPayWelcomePage stub.
 */
class WcPayWelcomePageTest extends WC_Unit_Test_Case {

	use DeprecatedStubAssertionsTrait;

	/**
	 * Reset the stub's cached instance so each test sees the constructor run.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_instance();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// A static instance cache on the stub, which no base class resets.
			$this->reset_instance();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should autoload the stub and resolve the legacy Admin\Features alias to it.
	 */
	public function test_class_and_alias_resolve_to_the_stub(): void {
		Features::get_instance();

		$this->assertTrue( class_exists( WcPayWelcomePage::class ), 'The stub class must autoload.' );
		$this->assertTrue( class_exists( 'Automattic\WooCommerce\Admin\Features\WcPayWelcomePage' ), 'The legacy alias must be registered.' );
		$this->assertSame( WcPayWelcomePage::class, ( new ReflectionClass( 'Automattic\WooCommerce\Admin\Features\WcPayWelcomePage' ) )->getName() );
		$this->assertSame( 'welcome_page', WcPayWelcomePage::INCENTIVE_TYPE );
	}

	/**
	 * @testdox Should cover every public method the stub declares.
	 */
	public function test_every_public_method_is_covered(): void {
		$this->assert_covers_every_public_method( WcPayWelcomePage::class, array( '__construct', 'instance', 'has_incentive' ) );
	}

	/**
	 * @testdox Should emit deprecation notices from the constructor and instance(), and return one shared instance without side effects.
	 */
	public function test_instance_is_a_deprecated_no_op(): void {
		$this->setExpectedDeprecated( WcPayWelcomePage::class . '::instance' );
		$this->setExpectedDeprecated( WcPayWelcomePage::class . '::__construct' );

		$first  = $this->call_without_side_effects( array( WcPayWelcomePage::class, 'instance' ) );
		$second = $this->call_without_side_effects( array( WcPayWelcomePage::class, 'instance' ) );

		$this->assertInstanceOf( WcPayWelcomePage::class, $first );
		$this->assertSame( $first, $second, 'instance() must keep returning the same object.' );
	}

	/**
	 * @testdox Should emit a deprecation notice and report no incentive without side effects.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $skip_wcpay_active Argument passed to has_incentive().
	 */
	public function test_has_incentive_is_a_deprecated_no_op( bool $skip_wcpay_active ): void {
		$this->setExpectedDeprecated( WcPayWelcomePage::class . '::__construct' );
		$this->setExpectedDeprecated( WcPayWelcomePage::class . '::has_incentive' );

		$result = $this->call_without_side_effects(
			static function () use ( $skip_wcpay_active ) {
				return ( new WcPayWelcomePage() )->has_incentive( $skip_wcpay_active );
			}
		);

		$this->assertFalse( $result );
	}

	/**
	 * Clear the stub's static instance cache.
	 */
	private function reset_instance(): void {
		if ( ! class_exists( WcPayWelcomePage::class ) ) {
			return;
		}

		$property = new ReflectionProperty( WcPayWelcomePage::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}
