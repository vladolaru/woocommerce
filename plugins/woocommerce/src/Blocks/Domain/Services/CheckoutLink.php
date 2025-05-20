<?php
/**
 * Functionality that takes a static URL, constructs a cart, and redirects to the checkout with a cart session.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Domain\Services;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\StoreApi\Utilities\SessionUtils;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
defined( 'ABSPATH' ) || exit;

/**
 * Checkout Link class.
 */
final class CheckoutLink {
	/**
	 * Initialize the checkout link service.
	 */
	public function init() {
		add_action( 'init', array( $this, 'add_checkout_link_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
		add_action( 'template_redirect', array( $this, 'handle_checkout_link_endpoint' ) );
	}

	/**
	 * Add the checkout link endpoint.
	 */
	public function add_checkout_link_endpoint() {
		add_rewrite_rule( '^checkout-link$', 'index.php?checkout-link=true', 'top' );
	}

	/**
	 * Add the checkout link query var.
	 *
	 * @param array $vars The query vars.
	 * @return array The query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'checkout-link';
		$vars[] = 'products';
		$vars[] = 'coupon';
		return $vars;
	}

	/**
	 * Handle the checkout link endpoint.
	 *
	 * Example: https://store.local/checkout-link/?products=18,19&coupon=test
	 *
	 * hhttps://store.local/checkout/?session=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjoidF9hM2Y2OTE3Yjg5N2Y4YzE0YzExMzZjNTI4ZTM0YjEiLCJleHAiOjE3NDc5MTk4NzAsImlzcyI6InN0b3JlLWFwaSIsImlhdCI6MTc0Nzc0NzA3MH0.-ZRy3KnqSJxON6PeZE9GCEx3fq3ckM4ITzVr2u5GFas
	 *
	 * @return void
	 */
	public function handle_checkout_link_endpoint() {
		if ( ! get_query_var( 'checkout-link' ) ) {
			return;
		}

		$controller = new CartController();
		$controller->empty_cart();

		// Populate cart with products.
		$products = wp_parse_id_list( get_query_var( 'products' ) ?? '' );
		foreach ( $products as $product_id ) {
			try {
				$controller->add_to_cart(
					[
						'id'       => $product_id,
						'quantity' => 1,
					]
				);
			} catch ( \Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
		}

		// Apply coupon if provided.
		$coupon = get_query_var( 'coupon' ) ?? '';

		if ( wc_coupons_enabled() && ! empty( $coupon ) ) {
			try {
				$controller->apply_coupon( wc_format_coupon_code( wp_unslash( $coupon ) ) );
			} catch ( \Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
		}

		// If the user is logged in, the session persists and is tied to the user ID.
		if ( is_user_logged_in() ) {
			$redirect_url = wc_get_checkout_url();
		} else {
			$session_token = SessionUtils::get_cart_token_for_customer( wc()->session->get_customer_id() );
			$redirect_url  = add_query_arg( 'session', $session_token, wc_get_checkout_url() );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Generate a cart token.
	 *
	 * @return string
	 */
	private function generate_cart_token() {
		return JsonWebToken::create(
			[
				'user_id' => SessionUtils::generate_customer_id(),
				'exp'     => time() + DAY_IN_SECONDS,
				'iss'     => 'checkout-link',
			],
			SessionUtils::get_cart_token_secret()
		);
	}
}
