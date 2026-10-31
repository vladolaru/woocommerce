<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\WCPayPromotion;

use Automattic\WooCommerce\Admin\Features\Features;
use Automattic\WooCommerce\Internal\Admin\WCPayPromotion\Init;
use Automattic\WooCommerce\Tests\Internal\Admin\DeprecatedStubAssertionsTrait;
use ReflectionClass;
use WC_Unit_Test_Case;

/**
 * Tests for the deprecated WCPayPromotion\Init stub.
 */
class InitTest extends WC_Unit_Test_Case {

	use DeprecatedStubAssertionsTrait;

	/**
	 * @testdox Should autoload the stub and resolve the legacy Admin\Features alias to it.
	 */
	public function test_class_and_alias_resolve_to_the_stub(): void {
		Features::get_instance();

		$this->assertTrue( class_exists( Init::class ), 'The stub class must autoload.' );
		$this->assertTrue( class_exists( 'Automattic\WooCommerce\Admin\Features\WcPayPromotion\Init' ), 'The legacy alias must be registered.' );
		$this->assertSame( Init::class, ( new ReflectionClass( 'Automattic\WooCommerce\Admin\Features\WcPayPromotion\Init' ) )->getName() );
	}

	/**
	 * @testdox Should cover every public method the stub declares.
	 */
	public function test_every_public_method_is_covered(): void {
		$this->assert_covers_every_public_method( Init::class, array_keys( $this->method_cases() ) );
	}

	/**
	 * @testdox Should emit a deprecation notice, return a neutral value and cause no side effects for $method.
	 * @dataProvider method_cases
	 *
	 * @param string       $method   Method name.
	 * @param array<mixed> $args     Call arguments.
	 * @param mixed        $expected Expected neutral return value.
	 */
	public function test_method_is_a_deprecated_no_op( string $method, array $args, $expected ): void {
		$this->setExpectedDeprecated( Init::class . '::' . $method );

		$result = $this->call_without_side_effects(
			static function () use ( $method, $args ) {
				return call_user_func_array( array( Init::class, $method ), $args );
			}
		);

		$this->assertSame( $expected, $result );
	}

	/**
	 * Every public stub method with its call arguments and neutral return value.
	 *
	 * @return array<string,array{0:string,1:array<mixed>,2:mixed}>
	 */
	public function method_cases(): array {
		return array(
			'possibly_register_pre_install_wc_pay_promotion_gateway' => array( 'possibly_register_pre_install_wc_pay_promotion_gateway', array( array( 'WC_Gateway_BACS' ) ), array( 'WC_Gateway_BACS' ) ),
			'can_show_promotion'               => array( 'can_show_promotion', array(), false ),
			'set_gateway_top_of_list'          => array( 'set_gateway_top_of_list', array( array( 'bacs' => 0 ) ), array( 'bacs' => 0 ) ),
			'get_wc_pay_promotion_spec'        => array( 'get_wc_pay_promotion_spec', array( false ), false ),
			'get_promotions'                   => array( 'get_promotions', array(), array() ),
			'get_cached_or_default_promotions' => array( 'get_cached_or_default_promotions', array(), array() ),
			'is_woopay_eligible'               => array( 'is_woopay_eligible', array(), false ),
			'delete_specs_transient'           => array( 'delete_specs_transient', array(), null ),
			'get_specs'                        => array( 'get_specs', array(), array() ),
			'load_payment_method_promotions'   => array( 'load_payment_method_promotions', array(), null ),
		);
	}
}
