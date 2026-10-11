<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin\Features\OnboardingTasks\Tasks;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks\WooCommercePayments;
use Automattic\WooCommerce\Tests\Internal\Admin\DeprecatedStubAssertionsTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the deprecated WooCommercePayments onboarding task stub.
 */
class WooCommercePaymentsTest extends WC_Unit_Test_Case {

	use DeprecatedStubAssertionsTrait;

	/**
	 * @testdox Should autoload the stub as an onboarding task.
	 */
	public function test_class_autoloads_as_a_task(): void {
		$this->assertTrue( class_exists( WooCommercePayments::class ), 'The stub class must autoload.' );
		$this->assertTrue( is_subclass_of( WooCommercePayments::class, Task::class ), 'The stub must stay an onboarding Task.' );
	}

	/**
	 * @testdox Should cover every public method the stub declares.
	 */
	public function test_every_public_method_is_covered(): void {
		$this->assert_covers_every_public_method( WooCommercePayments::class, array_keys( $this->method_cases() ) );
	}

	/**
	 * @testdox Should emit a deprecation notice, return a neutral value and cause no side effects for $method.
	 * @dataProvider method_cases
	 *
	 * @param string $method   Method name.
	 * @param mixed  $expected Expected neutral return value.
	 */
	public function test_method_is_a_deprecated_no_op( string $method, $expected ): void {
		$this->setExpectedDeprecated( WooCommercePayments::class . '::' . $method );
		$sut = new WooCommercePayments();

		$result = $this->call_without_side_effects(
			static function () use ( $sut, $method ) {
				return $sut->$method();
			}
		);

		$this->assertSame( $expected, $result );
	}

	/**
	 * Every public stub method with its neutral return value.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function method_cases(): array {
		return array(
			'get_id'                         => array( 'get_id', 'woocommerce-payments' ),
			'get_image_url'                  => array( 'get_image_url', '' ),
			'get_image_alt'                  => array( 'get_image_alt', '' ),
			'get_title'                      => array( 'get_title', '' ),
			'get_badge'                      => array( 'get_badge', '' ),
			'get_content'                    => array( 'get_content', '' ),
			'get_additional_data'            => array( 'get_additional_data', null ),
			'get_time'                       => array( 'get_time', '' ),
			'get_action_label'               => array( 'get_action_label', '' ),
			'is_complete'                    => array( 'is_complete', false ),
			'can_view'                       => array( 'can_view', false ),
			'is_requested'                   => array( 'is_requested', false ),
			'is_installed'                   => array( 'is_installed', false ),
			'is_wcpay_active'                => array( 'is_wcpay_active', false ),
			'is_connected'                   => array( 'is_connected', false ),
			'is_account_partially_onboarded' => array( 'is_account_partially_onboarded', false ),
			'get_suggestion'                 => array( 'get_suggestion', null ),
			'is_supported'                   => array( 'is_supported', false ),
			'has_other_ecommerce_gateways'   => array( 'has_other_ecommerce_gateways', false ),
			'get_action_url'                 => array( 'get_action_url', '' ),
		);
	}
}
