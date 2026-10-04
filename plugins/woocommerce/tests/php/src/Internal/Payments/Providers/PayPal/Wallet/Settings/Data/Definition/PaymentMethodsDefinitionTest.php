<?php
/**
 * Tests for the payment methods definition.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The list of payment methods the settings UI shows: PayPal, Venmo and Pay Later, plus whatever third parties add through
 * the three group filters. The APM group is `apply_filters( ..._group_apm, array() )`:
 * the cases pin that the hook is fired, that its additions are honoured and that the group is empty
 * without a listener (the wallet defines no local payment method and no Pay upon Invoice fields).
 *
 * The shell empties the APM group in a booted wallet; the cases that read the real group remove that listener first and
 * the test case restores the hooks after every test.
 *
 * @group paypal-wallet
 */
class PaymentMethodsDefinitionTest extends WalletTestCase {

	private const GROUP_HOOKS = array(
		'woocommerce_paypal_payments_gateway_group_paypal',
		'woocommerce_paypal_payments_gateway_group_cards',
		'woocommerce_paypal_payments_gateway_group_apm',
	);

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodsDefinition
	 */
	private $sut;

	/**
	 * Build the definition over real settings models and start with no listener on the group filters.
	 */
	public function setUp(): void {
		parent::setUp();

		// The test case restores the hooks after each test.
		foreach ( self::GROUP_HOOKS as $hook ) {
			remove_all_filters( $hook );
		}

		$this->sut = new PaymentMethodsDefinition( new PaymentSettings(), new GeneralSettings( 'US', 'USD', false ) );
	}

	/**
	 * Hide the APM group the way a booted wallet does, so a case sees PayPal, Venmo and Pay Later only.
	 */
	private function hide_the_apm_group(): void {
		add_filter( 'woocommerce_paypal_payments_gateway_group_apm', '__return_empty_array', 99 );
	}

	/**
	 * The method IDs of a group.
	 *
	 * @param array $group A group of method entries.
	 * @return string[]
	 */
	private function ids( array $group ): array {
		return array_column( $group, 'id' );
	}

	/**
	 * @testdox Should list PayPal, Venmo and Pay Later as the PayPal group, in that order (wallet).
	 */
	public function test_paypal_group_lists_paypal_venmo_and_pay_later(): void {
		$group = $this->sut->group_paypal_methods();

		$this->assertSame( array( 'ppcp-gateway', 'venmo', 'pay-later' ), $this->ids( $group ) );
		$this->assertSame( 'payment-method-paypal', $group[0]['icon'] );
		$this->assertSame( 'payment-method-venmo', $group[1]['icon'] );
		$this->assertArrayHasKey( 'paypalShowLogo', $group[0]['fields'], 'Only PayPal has a field' );
		$this->assertFalse( $group[1]['fields'], 'Venmo has no fields' );
		$this->assertFalse( $group[2]['fields'], 'Pay Later has no fields' );
	}

	/**
	 * @testdox Should define exactly PayPal, Venmo and Pay Later, disabled until switched on, when the card and APM groups are empty (wallet).
	 */
	public function test_definitions_are_the_three_paypal_methods(): void {
		$this->hide_the_apm_group();

		$definitions = $this->sut->get_definitions();

		$this->assertSame( array( 'ppcp-gateway', 'venmo', 'pay-later' ), array_keys( $definitions ) );
		foreach ( $definitions as $id => $definition ) {
			$this->assertSame( $id, $definition['id'] );
			$this->assertFalse( $definition['enabled'], "$id should start disabled" );
			$this->assertSame( 'warning', $definition['warningSeverity'] );
		}
		$this->assertSame( 'Checkout page title', $definitions['ppcp-gateway']['fields']['checkoutPageTitle']['label'] );
		$this->assertArrayHasKey( 'paypalShowLogo', $definitions['ppcp-gateway']['fields'] );
		$this->assertArrayNotHasKey( 'fields', $definitions['venmo'], 'A method without fields has no field list' );
	}

	/**
	 * @testdox Should keep firing the card and APM group filters and honour what a listener adds to them (wallet).
	 */
	public function test_group_filters_stay_fired_and_additions_are_honoured(): void {
		$cards_calls = $this->spy_filter(
			'woocommerce_paypal_payments_gateway_group_cards',
			array(
				array(
					'id'          => 'third-party-card',
					'title'       => 'Third party card',
					'description' => 'A card method added by a plugin',
					'icon'        => 'payment-method-card',
				),
			)
		);
		$apm_calls   = $this->spy_filter(
			'woocommerce_paypal_payments_gateway_group_apm',
			array(
				array(
					'id'          => 'third-party-apm',
					'title'       => 'Third party APM',
					'description' => 'A local method added by a plugin',
					'icon'        => 'payment-method-apm',
				),
			)
		);

		$definitions = $this->sut->get_definitions();

		$this->assertGreaterThanOrEqual( 1, count( $cards_calls ) );
		$this->assertGreaterThanOrEqual( 1, count( $apm_calls ) );
		$this->assertSame( array(), $cards_calls[0][0], 'The card group filter should start from an empty array' );
		$this->assertArrayHasKey( 'third-party-card', $definitions );
		$this->assertArrayHasKey( 'third-party-apm', $definitions );
		$this->assertSame( 'Third party APM', $definitions['third-party-apm']['itemTitle'] );
		$this->assertSame( array( 'third-party-apm' ), $this->ids( $this->sut->group_apms() ) );
	}

	/**
	 * @testdox Should leave the APM group empty when nothing is hooked to it, and define no local payment method (wallet).
	 */
	public function test_apm_group_is_empty_without_a_listener(): void {
		remove_all_filters( 'woocommerce_paypal_payments_gateway_group_apm' );

		$this->assertSame( array(), $this->sut->group_apms() );
		$this->assertSame( array( 'ppcp-gateway', 'venmo', 'pay-later' ), array_keys( $this->sut->get_definitions() ) );
	}
}
