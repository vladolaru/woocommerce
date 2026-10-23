<?php
/**
 * Tests for the to-dos definition.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\TodosDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The "Enable Pay with Crypto" to-do: what it opens and that its eligibility follows the tenth eligibility argument.
 * The settings app shows a to-do only when its `isEligible` callable answers true.
 *
 * @group paypal-wallet
 */
class TodosDefinitionTest extends WalletTestCase {

	/**
	 * Build the definition over an eligibility service whose other nine checks are true.
	 *
	 * @param bool $is_enable_pwc_eligible Whether the Pay with Crypto to-do is eligible.
	 * @return TodosDefinition
	 */
	private function create_definition( bool $is_enable_pwc_eligible ): TodosDefinition {
		// The test case restores the hooks after each test.
		remove_all_filters( 'woocommerce_paypal_payments_todos_list' );

		return new TodosDefinition(
			new TodosEligibilityService( true, true, true, true, true, true, true, true, true, $is_enable_pwc_eligible ),
			$this->mock( GeneralSettings::class ),
			$this->mock( TodosModel::class )
		);
	}

	/**
	 * @testdox Should list the Enable Pay with Crypto to-do, opening the Pay with Crypto method on the Payment Methods tab.
	 */
	public function test_lists_the_enable_pwc_todo(): void {
		$todos = $this->create_definition( true )->get();

		$this->assertArrayHasKey( 'enable_pwc', $todos );
		$this->assertSame( 'Enable Pay with Crypto', $todos['enable_pwc']['title'] );
		$this->assertSame( 'Enable customers to pay with cryptocurrency, and receive payments in USD in your PayPal balance.', $todos['enable_pwc']['description'] );
		$this->assertSame(
			array(
				'type'      => 'tab',
				'tab'       => 'payment_methods',
				'section'   => 'ppcp-pwc',
				'highlight' => 'ppcp-pwc',
			),
			$todos['enable_pwc']['action']
		);
		$this->assertSame( 15, $todos['enable_pwc']['priority'] );
		$this->assertArrayNotHasKey( 'apply_for_pwc', $todos, 'The application to-do is not restored' );
	}

	/**
	 * @testdox Should make the Enable Pay with Crypto to-do eligible only when its check passes.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $eligible The tenth eligibility argument.
	 */
	public function test_enable_pwc_follows_its_check( bool $eligible ): void {
		$todos = $this->create_definition( $eligible )->get();

		$this->assertSame( $eligible, $todos['enable_pwc']['isEligible']() );
		$this->assertTrue( $todos['apply_for_working_capital']['isEligible'](), 'The other to-dos keep their own checks' );
	}
}
