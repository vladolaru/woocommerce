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
		add_action( 'parse_request', array( $this, 'handle_session_cookie' ) );
		add_action( 'template_redirect', array( $this, 'handle_checkout_link_endpoint' ) );
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
		$vars[] = 'session';
		return $vars;
	}

	/**
	 * Initialize the session cookie based on the query string parameter.
	 *
	 * https://store.local/checkout/?session=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjoidF8zM2Q4OWY2NjJhN2E4NjE5Y2IzZDhiOWM4NjljZmM0YmNmYjZhNzA0IiwiZXhwIjoxNzQ3OTE1Nzg4LCJpc3MiOiJzdG9yZS1hcGkiLCJpYXQiOjE3NDc3NDI5ODh9.WvPBuMIPiQh9_FNAVIxwg_arH_4qidZ7xrbbGpF3fU8
	 */
	public function handle_session_cookie() {
		if ( ! get_query_var( 'session' ) ) {
			return;
		}

		$controller = new CartController();
		$controller->empty_cart();

		$session_token = get_query_var( 'session' );

		if ( SessionUtils::validate_cart_token( $session_token ) ) {
			$payload         = JsonWebToken::get_parts( $session_token )->payload;
			$session_handler = new \WC_Session_Handler();
			ob_start();
			var_dump( $session_handler->has_session() );
			var_dump( $payload->user_id );
			var_dump( substr( $payload->user_id, 0, 2 ) === 't_' );
			var_dump( headers_sent() );

			if ( substr( $payload->user_id, 0, 2 ) === 't_' && ! $session_handler->has_session() ) {
				$session_handler->set_customer_id( $payload->user_id );
				$session_handler->set_customer_session_cookie( true );
				// wp_safe_redirect( add_query_arg( 'session', $session_token, wc_get_checkout_url() ) );
				// exit;
			}
		}

		// wp_safe_redirect( wc_get_checkout_url() );
		// exit;
	}

	/**
	 * Handle the checkout link endpoint.
	 *
	 * Example: https://store.local/checkout-link/?products=18,19&coupon=test
	 * https://store.local/checkout-link/?products=18,19,aav,test,123222&coupon=test
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
			$session_token = SessionUtils::get_cart_token_for_customer( SessionUtils::generate_customer_id() );
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
