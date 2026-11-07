<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;

/**
 * Runs a callback with given calls on the PHP call stack, for the Multi-Currency compatibility checks that look for a
 * caller in the backtrace (`Class->method`, `Class::method`).
 *
 * WooCommerce's own methods run for real until a hook inside them, where the callback runs and the rest of the method is
 * skipped, so the check finds them on the real stack. An extension's call is declared around the callback instead, and
 * only the request context from request_context() sees it, so no extension class is declared and the tests run in
 * process.
 */
final class CallStack {

	/**
	 * Extension calls declared around the running callback, the first one outermost.
	 *
	 * @var string[]
	 */
	private static array $extension_calls = array();

	/**
	 * A request context whose backtrace check finds the declared extension calls, or else reads the real call stack.
	 *
	 * @return RequestContext
	 */
	public static function request_context(): RequestContext {
		return new class() extends RequestContext {
			/**
			 * Tell whether any of the calls is a declared extension call or appears on the real call stack.
			 *
			 * @param string[] $calls Expected calls.
			 * @return bool
			 */
			public function is_call_in_backtrace( array $calls ): bool {
				foreach ( $calls as $call ) {
					if ( in_array( $call, CallStack::extension_calls(), true ) ) {
						return true;
					}
				}

				return parent::is_call_in_backtrace( $calls );
			}
		};
	}

	/**
	 * The extension calls declared around the running callback.
	 *
	 * @return string[]
	 */
	public static function extension_calls(): array {
		return self::$extension_calls;
	}

	/**
	 * Run a callback with the calls on the stack, the first one outermost.
	 *
	 * @param string[] $calls    Calls as the backtrace check spells them.
	 * @param callable $callback Work to run innermost.
	 * @return mixed The callback's result.
	 */
	public static function run( array $calls, callable $callback ) {
		if ( array() === $calls ) {
			return $callback();
		}

		$call  = array_shift( $calls );
		$inner = static fn() => self::run( $calls, $callback );

		switch ( $call ) {
			case 'WC_Cart->add_to_cart':
				// The quantity filter runs before add_to_cart() looks the product up.
				return self::inside_hook( 'woocommerce_add_to_cart_quantity', $inner, static fn() => ( new \WC_Cart() )->add_to_cart( 0 ) );
			case 'WC_Cart->calculate_totals':
				return self::inside_hook( 'woocommerce_before_calculate_totals', $inner, static fn() => self::cart_with_one_item()->calculate_totals() );
			case 'WC_Cart->get_product_subtotal':
				return self::inside_hook( 'woocommerce_cart_product_subtotal', $inner, static fn() => ( new \WC_Cart() )->get_product_subtotal( self::product(), 1 ) );
			case 'WC_Cart_Totals->calculate_item_totals':
				return self::inside_hook(
					'woocommerce_get_discounted_price',
					$inner,
					static function () {
						$cart = self::cart_with_one_item();
						return new \WC_Cart_Totals( $cart );
					}
				);
			case 'Automattic\WooCommerce\Internal\Admin\Notes\NewSalesRecord::sum_sales_for_date':
				// The order query applies its results filter inside the sum.
				return self::inside_hook( 'woocommerce_order_query', $inner, static fn() => \Automattic\WooCommerce\Internal\Admin\Notes\NewSalesRecord::sum_sales_for_date( '2000-01-01' ) );
			case 'WC_Discounts->apply_coupon':
				// A coupon that does not exist fails validation, whose error message is filtered inside apply_coupon().
				return self::inside_hook( 'woocommerce_coupon_error', $inner, static fn() => ( new \WC_Discounts() )->apply_coupon( new \WC_Coupon() ) );
		}

		return self::as_extension_call( $call, $inner );
	}

	/**
	 * Run the trigger until the hook fires, run the callback inside it, and skip the rest of the trigger.
	 *
	 * @param string   $hook     Hook fired inside the method.
	 * @param callable $inner    Work to run inside the hook.
	 * @param callable $trigger  Call that reaches the hook.
	 * @return mixed The work's result.
	 */
	private static function inside_hook( string $hook, callable $inner, callable $trigger ) {
		$result = null;
		$failed = null;
		// A failed assertion inside the work is held and rethrown, so the method's own catch blocks cannot swallow it.
		$probe = static function () use ( $inner, &$result, &$failed ) {
			try {
				$result = $inner();
			} catch ( \Throwable $e ) {
				$failed = $e;
			}
			throw new CallStackStop();
		};
		add_filter( $hook, $probe, PHP_INT_MIN );

		try {
			$trigger();
			throw new \LogicException( "The {$hook} hook did not fire." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test helper message.
		} catch ( CallStackStop $stop ) {
			unset( $stop );
			if ( null !== $failed ) {
				throw $failed;
			}
			return $result;
		} finally {
			remove_filter( $hook, $probe, PHP_INT_MIN );
		}
	}

	/**
	 * Run the work with an extension's call declared, for the request context from request_context() to find.
	 *
	 * @param string   $call  Call as `Class->method` or `Class::method`, spelled as the compatibility class lists it.
	 * @param callable $inner Work to run inside the call.
	 * @return mixed The work's result.
	 */
	private static function as_extension_call( string $call, callable $inner ) {
		self::$extension_calls[] = $call;

		try {
			return $inner();
		} finally {
			array_pop( self::$extension_calls );
		}
	}

	/**
	 * A cart holding one product, so cart totals run past their empty-cart return.
	 *
	 * @return \WC_Cart
	 */
	private static function cart_with_one_item(): \WC_Cart {
		$cart = new \WC_Cart();
		$cart->set_cart_contents(
			array(
				'item' => array(
					'key'          => 'item',
					'product_id'   => 0,
					'variation_id' => 0,
					'variation'    => array(),
					'quantity'     => 1,
					'data'         => self::product(),
				),
			)
		);

		return $cart;
	}

	/**
	 * An unsaved simple product with a price.
	 *
	 * @return \WC_Product_Simple
	 */
	private static function product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '10' );

		return $product;
	}
}
