<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPageSurface;

/**
 * Replace WooPaymentsPageSurface, so a test states which shopper page the request is.
 *
 * Core's is_cart() and is_checkout() stay true for the rest of the PHP process once any code defines WOOCOMMERCE_CART or
 * WOOCOMMERCE_CHECKOUT, so a test of a page that is neither cannot ask them. The using class resets the replacement in
 * its tearDown().
 */
trait PageSurfaceReplacement {

	/**
	 * Replace the page surface so the page reads as a cart or a checkout only as the case says; both `*_surface()` methods stay real.
	 *
	 * @param bool $is_cart     Whether the page reads as a cart.
	 * @param bool $is_checkout Whether the page reads as a checkout.
	 */
	private function replace_page_surface( bool $is_cart, bool $is_checkout ): void {
		$surface = $this->getMockBuilder( WooPaymentsPageSurface::class )->onlyMethods( array( 'is_cart', 'is_checkout' ) )->getMock();
		$surface->method( 'is_cart' )->willReturn( $is_cart );
		$surface->method( 'is_checkout' )->willReturn( $is_checkout );
		wc_get_container()->replace( WooPaymentsPageSurface::class, $surface );
	}

	/**
	 * Replace the page surface with one that reads the visited page as core's is_cart() and is_checkout() do, without their constant term.
	 *
	 * Each predicate answers core's filter or CartCheckoutUtils' page check, so the store page options and the visited page
	 * decide, whatever constant an earlier test defined. If core adds a term to is_cart() or is_checkout(), this needs it too.
	 */
	private function use_visited_page_surface(): void {
		$surface = $this->getMockBuilder( WooPaymentsPageSurface::class )->onlyMethods( array( 'is_cart', 'is_checkout' ) )->getMock();
		$surface->method( 'is_cart' )->willReturnCallback(
			static function (): bool {
				/** This filter is documented in includes/wc-conditional-functions.php */
				return apply_filters( 'woocommerce_is_cart', false ) || CartCheckoutUtils::is_cart_page();
			}
		);
		$surface->method( 'is_checkout' )->willReturnCallback(
			static function (): bool {
				/** This filter is documented in includes/wc-conditional-functions.php */
				return apply_filters( 'woocommerce_is_checkout', false ) || CartCheckoutUtils::is_checkout_page();
			}
		);
		wc_get_container()->replace( WooPaymentsPageSurface::class, $surface );
	}
}
