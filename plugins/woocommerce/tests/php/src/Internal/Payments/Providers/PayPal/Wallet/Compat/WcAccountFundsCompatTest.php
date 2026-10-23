<?php
/**
 * Tests for the Account Funds compatibility layer.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WcAccountFundsCompat;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * The store credit that the Account Funds for WooCommerce plugin applies through `set_total()`, which the wallet reports
 * as an extra discount so PayPal does not get a negative tax total.
 *
 * The plugin is not installed on the test site and its cart class cannot be declared in the shared test process, so the
 * cart cases pin the guard path only: with the class absent, nothing is reported. Left out for that reason:
 * - the Store API figure converted to minor units while the cart is partly paid with credit;
 * - the Store API figure unchanged while the cart total was not reduced;
 * - the cart credit ignored while the cart total was not reduced;
 * - a negative applied amount clamped to zero;
 * - the applied credit added to the extra discount while the plugin is present.
 *
 * @group paypal-wallet
 */
class WcAccountFundsCompatTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var WcAccountFundsCompat
	 */
	private $sut;

	/**
	 * Build the compat layer.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WcAccountFundsCompat();
	}

	/**
	 * An order that holds the given credit as order meta, without storing it.
	 *
	 * @param mixed $funds_used The value of the `_funds_used` meta, or null for an order without that meta.
	 * @return WC_Order
	 */
	private function order_with_funds_used( $funds_used ): WC_Order {
		$order = new WC_Order();
		if ( null !== $funds_used ) {
			$order->update_meta_data( '_funds_used', $funds_used );
		}

		return $order;
	}

	/**
	 * Given an order that Account Funds partly paid with a direct `set_total()` call, recorded as order meta, the credit
	 * is added to the extra discount so the breakdown accounts for it.
	 *
	 * @testdox Should add the credit used on the order to the extra discount.
	 */
	public function test_order_extra_discount_reports_credit_used_on_order(): void {
		$result = $this->sut->order_extra_discount( 10.0, $this->order_with_funds_used( '12.50' ) );

		$this->assertSame( 22.5, $result );
	}

	/**
	 * Orders without a store credit amount that can be reported.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_no_reportable_order_credit(): array {
		return array(
			'meta absent, WordPress returns empty string' => array( null ),
			'negative credit is clamped to zero'          => array( '-5.00' ),
		);
	}

	/**
	 * @testdox Should leave the extra discount unchanged for an order without a reportable credit.
	 *
	 * @dataProvider data_no_reportable_order_credit
	 *
	 * @param mixed $meta_value The `_funds_used` meta, or null for none.
	 */
	public function test_order_extra_discount_unchanged_when_no_reportable_credit( $meta_value ): void {
		$result = $this->sut->order_extra_discount( 10.0, $this->order_with_funds_used( $meta_value ) );

		$this->assertSame( 10.0, $result );
	}

	/**
	 * Given the Account Funds plugin is not installed, so its cart class never exists, no credit is reported and the
	 * extra discount is returned unchanged.
	 *
	 * @testdox Should report no credit for the cart when the Account Funds cart class is absent.
	 */
	public function test_cart_extra_discount_reports_no_credit_when_kestrel_class_absent(): void {
		$this->assertFalse( class_exists( '\Kestrel\Account_Funds\Cart' ), 'Precondition: the Account Funds plugin is not installed on the test site' );

		$result = $this->sut->cart_extra_discount( 5.0, WC()->cart );

		$this->assertSame( 5.0, $result );
	}

	/**
	 * @testdox Should report no credit for the Store API cart when the Account Funds cart class is absent.
	 */
	public function test_store_api_cart_extra_discount_unchanged_when_kestrel_class_absent(): void {
		$this->assertFalse( class_exists( '\Kestrel\Account_Funds\Cart' ), 'Precondition: the Account Funds plugin is not installed on the test site' );

		$this->assertSame( 100, $this->sut->store_api_cart_extra_discount( 100 ) );
	}

	/**
	 * Given the compat class is wired up, all three extra discount filters that balance the breakdown are attached.
	 *
	 * @testdox Should attach the cart, order and Store API extra discount filters when registered.
	 */
	public function test_register_attaches_all_three_extra_discount_filters(): void {
		$this->sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_cart_extra_discount', array( $this->sut, 'cart_extra_discount' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_order_extra_discount', array( $this->sut, 'order_extra_discount' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_store_api_cart_extra_discount', array( $this->sut, 'store_api_cart_extra_discount' ) ) );
	}

	/**
	 * @testdox Should add the credit of an order to the extra discount through the registered filter.
	 */
	public function test_registered_order_filter_adds_the_credit(): void {
		$this->sut->register();

		$result = apply_filters( 'woocommerce_paypal_payments_order_extra_discount', 1.5, $this->order_with_funds_used( '4.00' ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( 5.5, $result );
	}
}
