<?php
/**
 * Tests for the free-trial subscription helper.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery;
use Mockery\MockInterface;
use WC_Cart;
use WC_Helper_Product;
use WC_Product;
use WC_Subscriptions_Product;

require_once __DIR__ . '/WcSubscriptionsProductStub.php';

/**
 * Real products with real meta, a Mockery cart assigned to WooCommerce, and a stand-in for WooCommerce Subscriptions'
 * product class (`WcSubscriptionsProductStub.php`), which core's suite does not load.
 *
 * The case "a subscription item with a connected plan does not require vaulting" pins that the product meta of a plan
 * linked while on the extension (`ppcp_subscription_plan`) is still read: core keeps those reads (stored data stays).
 *
 * @group paypal-wallet
 */
class FreeTrialSubscriptionHelperTest extends WalletTestCase {

	/**
	 * The cart WooCommerce had before the test.
	 *
	 * @var mixed
	 */
	private $original_cart;

	/**
	 * Remember the cart.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_cart = WC()->cart;
	}

	/**
	 * Give the cart back and forget the stand-in's subscription products.
	 */
	public function tearDown(): void {
		try {
			WC()->cart = $this->original_cart;
			WC_Subscriptions_Product::$subscription_product_ids = array();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A cart mock holding the given items and emptiness.
	 *
	 * @param array $items    The cart items, each with a 'data' product.
	 * @param bool  $is_empty Whether the cart is empty.
	 * @return WC_Cart&MockInterface
	 */
	private function cart_with_items( array $items, bool $is_empty = false ) {
		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( $is_empty );

		if ( ! $is_empty ) {
			$cart->shouldReceive( 'get_cart' )->andReturn( $items );
		}

		return $cart;
	}

	/**
	 * A real product, registered as a subscription or not, with or without the hosted-plan meta.
	 *
	 * @param bool      $is_subscription Whether the product counts as a subscription.
	 * @param bool|null $has_plan_meta   Whether `ppcp_subscription_plan` is set (only when it is a subscription).
	 * @return WC_Product
	 */
	private function product( bool $is_subscription, ?bool $has_plan_meta = null ): WC_Product {
		$product = WC_Helper_Product::create_simple_product();

		if ( $is_subscription ) {
			WC_Subscriptions_Product::$subscription_product_ids[] = $product->get_id();
		}
		if ( $has_plan_meta ) {
			$product->update_meta_data( 'ppcp_subscription_plan', 'PLAN-1' );
			$product->save();
		}

		return $product;
	}

	/**
	 * @testdox Should not require vaulting when WooCommerce Subscriptions is not active.
	 */
	public function test_cart_requires_vaulting_false_when_wcs_plugin_not_active(): void {
		$helper = new TestableFreeTrialSubscriptionHelper( false );

		$this->assertFalse( $helper->cart_requires_vaulting() );
	}

	/**
	 * @testdox Should not require vaulting when there is no cart.
	 */
	public function test_cart_requires_vaulting_false_when_no_cart_present(): void {
		WC()->cart = null;

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertFalse( $helper->cart_requires_vaulting() );
	}

	/**
	 * @testdox Should not require vaulting when the cart is empty.
	 */
	public function test_cart_requires_vaulting_false_when_cart_is_empty(): void {
		WC()->cart = $this->cart_with_items( array(), true );

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertFalse( $helper->cart_requires_vaulting() );
	}

	/**
	 * @testdox Should not require vaulting when the cart holds only a non-subscription product.
	 */
	public function test_cart_requires_vaulting_false_when_cart_has_no_subscription_item(): void {
		WC()->cart = $this->cart_with_items( array( array( 'data' => $this->product( false ) ) ) );

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertFalse( $helper->cart_requires_vaulting() );
	}

	/**
	 * Renewals for that item are billed by PayPal against the connected plan, not from a vaulted payment method.
	 *
	 * @testdox Should not require vaulting when the subscription item carries a PayPal plan meta (stored plan meta stays read).
	 */
	public function test_cart_requires_vaulting_false_when_subscription_item_has_connected_plan(): void {
		WC()->cart = $this->cart_with_items( array( array( 'data' => $this->product( true, true ) ) ) );

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertFalse( $helper->cart_requires_vaulting() );
	}

	/**
	 * @testdox Should require vaulting when the subscription item has no PayPal plan meta, since renewals are paid from a vaulted payment method.
	 */
	public function test_cart_requires_vaulting_true_when_subscription_item_has_no_connected_plan(): void {
		WC()->cart = $this->cart_with_items( array( array( 'data' => $this->product( true, false ) ) ) );

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertTrue( $helper->cart_requires_vaulting() );
	}

	/**
	 * The frontend re-answers the free-trial question against a live total by combining this cart-shape answer with a
	 * total of its own, so this method must never read the cart total: a Mockery cart without a `get_total()`
	 * expectation fails the test the moment the method is called.
	 *
	 * @testdox Should answer from the cart items without ever reading the cart total.
	 */
	public function test_cart_requires_vaulting_never_reads_the_cart_total(): void {
		WC()->cart = $this->cart_with_items( array( array( 'data' => $this->product( true, false ) ) ) );

		$helper = new TestableFreeTrialSubscriptionHelper( true );

		$this->assertTrue( $helper->cart_requires_vaulting() );
	}

	/**
	 * @testdox Should call a cart a free trial only when it requires vaulting and the total is zero or below: $name.
	 *
	 * @dataProvider free_trial_cart_provider
	 *
	 * @param string $name                   Case name.
	 * @param bool   $cart_requires_vaulting Whether the cart shape requires vaulting.
	 * @param string $total                  The cart total.
	 * @param bool   $expected               Whether the cart is a free trial.
	 */
	public function test_is_free_trial_cart_requires_both_cart_shape_and_non_positive_total( string $name, bool $cart_requires_vaulting, string $total, bool $expected ): void {
		unset( $name );

		$helper = Mockery::mock( FreeTrialSubscriptionHelper::class )->makePartial();
		$helper->shouldReceive( 'cart_requires_vaulting' )->andReturn( $cart_requires_vaulting );

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'get_total' )->with( 'numeric' )->andReturn( $total );
		WC()->cart = $cart;

		$this->assertSame( $expected, $helper->is_free_trial_cart() );
	}

	/**
	 * Cart shape and total combinations.
	 *
	 * @return array<string, array{string, bool, string, bool}>
	 */
	public function free_trial_cart_provider(): array {
		return array(
			'qualifying cart with a zero total is a free trial'     => array( 'zero total', true, '0.00', true ),
			'qualifying cart with a negative total is a free trial' => array( 'negative total', true, '-5.00', true ),
			'qualifying cart with a positive total is not free'     => array( 'positive total', true, '19.99', false ),
			'non-qualifying cart with a zero total is not free'     => array( 'non-qualifying, zero', false, '0.00', false ),
			'non-qualifying cart with a positive total is not free' => array( 'non-qualifying, positive', false, '19.99', false ),
		);
	}

	/**
	 * @testdox Should not call a cart a free trial when there is no cart, even if the shape would require vaulting.
	 */
	public function test_is_free_trial_cart_false_when_cart_requires_vaulting_but_no_cart_present(): void {
		$helper = Mockery::mock( FreeTrialSubscriptionHelper::class )->makePartial();
		$helper->shouldReceive( 'cart_requires_vaulting' )->andReturn( true );

		WC()->cart = null;

		$this->assertFalse( $helper->is_free_trial_cart() );
	}
}
