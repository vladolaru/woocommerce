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
 * The list of payment methods the settings UI shows: PayPal, Venmo and Pay Later, Pay with Crypto as the only alternative
 * payment method, plus whatever third parties add through the three group filters. The cases pin that the hooks are
 * fired, that their additions are honoured and that the APM group holds Pay with Crypto before its filter runs (the
 * wallet defines no other local payment method and no Pay upon Invoice fields).
 *
 * The cases start with no listener on the group filters, so the Pay with Crypto module's flag filter does not run;
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
	 * Empty the APM group, so a case sees PayPal, Venmo and Pay Later only.
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
	 * @testdox Should list PayPal, Venmo and Pay Later as the PayPal group, in that order.
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
	 * @testdox Should define exactly PayPal, Venmo and Pay Later, disabled until switched on, when the card and APM groups are emptied.
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
	 * @testdox Should keep firing the card and APM group filters and honour what a listener adds to them.
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
	 * @testdox Should list Pay with Crypto as the only APM, with its icon, title and description, when nothing is hooked to the group.
	 */
	public function test_apm_group_lists_pay_with_crypto(): void {
		$group = $this->sut->group_apms();

		$this->assertSame( array( 'ppcp-pwc' ), $this->ids( $group ) );
		$this->assertSame( 'payment-method-pwc', $group[0]['icon'] );
		$this->assertSame( 'Pay with Crypto', $group[0]['title'] );
		$this->assertSame(
			'A PayPal-powered checkout option letting customers pay with cryptocurrency. You receive funds in USD, settled directly to your PayPal balance — no crypto exposure, no chargeback risk.',
			$group[0]['description'],
			'The admin description is the gateway\'s method description'
		);
	}

	/**
	 * @testdox Should hand the Pay with Crypto method to the woocommerce_paypal_payments_gateway_group_apm filter.
	 */
	public function test_apm_group_filter_receives_pay_with_crypto(): void {
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_gateway_group_apm' );

		$this->sut->group_apms();

		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'ppcp-pwc' ), $this->ids( $calls[0][0] ) );
	}

	/**
	 * @testdox Should define Pay with Crypto after the PayPal methods when the card group is empty.
	 */
	public function test_definitions_carry_pay_with_crypto(): void {
		$definitions = $this->sut->get_definitions();

		$this->assertSame( array( 'ppcp-gateway', 'venmo', 'pay-later', 'ppcp-pwc' ), array_keys( $definitions ) );
		$this->assertSame( 'Pay with Crypto', $definitions['ppcp-pwc']['itemTitle'] );
		$this->assertSame( 'payment-method-pwc', $definitions['ppcp-pwc']['icon'] );
		$this->assertArrayHasKey( 'checkoutPageTitle', $definitions['ppcp-pwc']['fields'], 'Pay with Crypto keeps the base fields' );
	}

	/**
	 * @testdox Should give Pay with Crypto its admin and checkout titles and descriptions as the APM defaults.
	 */
	public function test_apm_defaults_hold_pay_with_crypto(): void {
		$this->assertSame(
			array(
				'ppcp-pwc' => array(
					'method_title'       => 'Pay with Crypto',
					'method_description' => 'A PayPal-powered checkout option letting customers pay with cryptocurrency. You receive funds in USD, settled directly to your PayPal balance — no crypto exposure, no chargeback risk.',
					'title'              => 'Pay with Crypto',
					'description'        => 'Pay with top wallets and coins.',
				),
			),
			PaymentMethodsDefinition::get_apm_defaults()
		);
	}
}
