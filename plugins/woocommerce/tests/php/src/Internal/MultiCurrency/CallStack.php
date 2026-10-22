<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

/**
 * Runs a callback with given calls on the PHP call stack, for the Multi-Currency compatibility checks that look for a
 * caller in the backtrace (`Class->method`, `Class::method`).
 *
 * WooCommerce's own methods run for real until a hook inside them, where the callback runs and the rest of the method is
 * skipped. An extension's class is declared as a stub whose method runs the callback, so a test that names one must run in
 * its own process: the stub would otherwise stay declared for every later test.
 */
final class CallStack {

	/**
	 * Extension classes the compatibility checks look for, with the methods each is called through (`::` for static), as
	 * client 11.1.0 names them in `includes/multi-currency/` (cited per row).
	 *
	 * The stubs are test scaffolding: each declared method takes the callback CallStack passes and runs it, which the real
	 * extension method's signature does not; only the class and method names, and static or not, come from the source.
	 */
	private const EXTENSION_METHODS = array(
		'WC_Product_Booking'           => array( 'get_price_html' ), // Client 11.1.0 Compatibility/WooCommerceBookings.php:108,142.
		'WC_Bookings_Cost_Calculation' => array( '::calculate_booking_cost' ), // Client 11.1.0 Compatibility/WooCommerceBookings.php:98.
		'WC_Deposits_Cart_Manager'     => array( 'deposits_form_output' ), // Client 11.1.0 Compatibility/WooCommerceDeposits.php:73.
		'WC_Shipping_Fedex'            => array( 'set_settings', 'per_item_shipping', 'box_shipping', 'get_fedex_api_request', 'get_fedex_requests', 'process_result' ), // Client 11.1.0 Compatibility/WooCommerceFedEx.php:22-27.
		'WC_Shipping_UPS'              => array( 'per_item_shipping', 'box_shipping', 'calculate_shipping' ), // Client 11.1.0 Compatibility/WooCommerceUPS.php:44-46.
		'WC_Points_Rewards_Discount'   => array( 'get_discount_data' ), // Client 11.1.0 Compatibility/WooCommercePointsAndRewards.php:59.
		'WC_Subscription'              => array( 'get_formatted_order_total' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:331.
		'WCS_Template_Loader'          => array( '::get_my_subscriptions' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:330.
		'WCS_Cart_Renewal'             => array( 'setup_cart' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:230.
		'WCS_Cart_Early_Renewal'       => array( 'setup_cart' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:284.
		'WC_Subscriptions_Cart'        => array( '::set_subscription_prices_for_calculation' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:122.
		'WC_Subscriptions_Product'     => array( '::get_sign_up_fee' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:127.
		'WCS_Switch_Totals_Calculator' => array( 'apportion_sign_up_fees' ), // Client 11.1.0 Compatibility/WooCommerceSubscriptions.php:130.
		'Automattic\WooCommerce\Admin\Notes\NewSalesRecord' => array( '::sum_sales_for_date', '::possibly_add_note' ), // Client 11.1.0 Compatibility.php:263-264.
	);

	/**
	 * Stub classes this process declared.
	 *
	 * @var array<string,bool>
	 */
	private static array $declared = array();

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
			case 'WC_Discounts->apply_coupon':
				// A coupon that does not exist fails validation, whose error message is filtered inside apply_coupon().
				return self::inside_hook( 'woocommerce_coupon_error', $inner, static fn() => ( new \WC_Discounts() )->apply_coupon( new \WC_Coupon() ) );
		}

		return self::through_stub( $call, $inner );
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
	 * Run the work through a stub of the extension's class and method.
	 *
	 * @param string   $call  Call as `Class->method` or `Class::method`.
	 * @param callable $inner Work to run inside the method.
	 * @return mixed The work's result.
	 */
	private static function through_stub( string $call, callable $inner ) {
		$is_static                   = false !== strpos( $call, '::' );
		list( $class_name, $method ) = explode( $is_static ? '::' : '->', $call, 2 );
		self::declare_stub( $class_name );

		return $is_static ? $class_name::$method( $inner ) : ( new $class_name() )->$method( $inner );
	}

	/**
	 * Declare an extension class as a stub with every method the checks look for.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	private static function declare_stub( string $class_name ): void {
		if ( isset( self::$declared[ $class_name ] ) ) {
			return;
		}
		if ( ! isset( self::EXTENSION_METHODS[ $class_name ] ) ) {
			throw new \LogicException( "No stub is known for {$class_name}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test helper message.
		}
		if ( class_exists( $class_name, false ) ) {
			throw new \LogicException( "{$class_name} already exists in this process; run the test in its own process." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test helper message.
		}

		$position  = strrpos( $class_name, '\\' );
		$namespace = false === $position ? '' : substr( $class_name, 0, $position );
		$short     = false === $position ? $class_name : substr( $class_name, $position + 1 );
		$methods   = '';
		foreach ( self::EXTENSION_METHODS[ $class_name ] as $method ) {
			$methods .= 0 === strpos( $method, '::' )
				? 'public static function ' . substr( $method, 2 ) . '( callable $c ) { return $c(); } '
				: 'public function ' . $method . '( callable $c ) { return $c(); } ';
		}
		eval( "namespace {$namespace} { class {$short} { {$methods} } }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test stub of an extension class the backtrace checks name.
		self::$declared[ $class_name ] = true;
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
