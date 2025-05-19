<?php
/**
 * Functionality that takes a static URL, constructs a cart, and redirects to the checkout with a cart session.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Domain\Services;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\StoreApi\SessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\Utilities\SessionUtils;


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

		add_filter(
			'woocommerce_session_handler',
			function ( $handler ) {
				if ( $this->is_checkout_link_request() ) {
					$_SERVER['HTTP_CART_TOKEN'] = $this->generate_cart_token();
					return SessionHandler::class;
				} elseif ( $this->has_cart_session() ) {
					$_SERVER['HTTP_CART_TOKEN'] = wc_clean( wp_unslash( $_GET['session'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return SessionHandler::class;
				}
				return $handler;
			}
		);
	}

	/**
	 * Returns true if the request is a checkout-link request.
	 *
	 * @return bool
	 */
	public function is_checkout_link_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		return ( false !== strpos( wc_clean( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'checkout-link' ) );
	}

	/**
	 * Returns true if the request has a cart session.
	 *
	 * @return bool
	 */
	public function has_cart_session() {
		return ! empty( $_GET['session'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * @return void
	 */
	public function handle_checkout_link_endpoint() {
		if ( ! get_query_var( 'checkout-link' ) ) {
			return;
		}

		wc_empty_cart();
		$products        = wp_parse_id_list( get_query_var( 'products' ) ?? '' );
		$coupon          = get_query_var( 'coupon' ) ?? '';
		$cart_controller = new CartController();

			// Populate cart with products.
		foreach ( $products as $product_id ) {
			try {
				$cart_controller->add_to_cart(
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
		if ( ! empty( $coupon ) ) {
			$cart_controller->apply_coupon( $coupon );
		}

		wp_safe_redirect( add_query_arg( 'session', SessionUtils::get_cart_token(), wc_get_checkout_url() ) );
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
