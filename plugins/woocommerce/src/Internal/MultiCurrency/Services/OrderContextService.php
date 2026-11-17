<?php
/**
 * OrderContextService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Tells whether prices are formatted for an order in My Account or at checkout, where the order's currency replaces the selected one.
 *
 * @since 11.0.0
 * @internal
 */
class OrderContextService {

	/**
	 * Optional provider for deterministic backtrace summaries.
	 *
	 * @var callable|null
	 */
	private $backtrace_summary_provider;

	/**
	 * Constructor.
	 *
	 * @param callable|null $backtrace_summary_provider Optional backtrace summary provider.
	 */
	public function __construct( ?callable $backtrace_summary_provider = null ) {
		$this->backtrace_summary_provider = $backtrace_summary_provider;
	}

	/**
	 * Tell whether order currency should override selected currency formatting.
	 *
	 * @return bool
	 */
	public function should_use_order_currency(): bool {
		if ( ! $this->is_order_page() ) {
			return false;
		}

		return $this->is_call_in_backtrace(
			array(
				'WC_Shortcode_My_Account::view_order',
				'WC_Shortcode_Checkout::order_received',
				'WC_Shortcode_Checkout::order_pay',
				'WC_Order->get_formatted_order_total',
			)
		);
	}

	/**
	 * Tell whether an order-confirmation block is rendering the order a single-order page names.
	 *
	 * Block confirmation pages render the order's amounts without the shortcode calls above, so the block's render counts
	 * when the page names an order (client 11.3.0 `includes/multi-currency/FrontendCurrencies.php:685-689`). It is kept apart
	 * from should_use_order_currency(), which also decides when the order currency is set for the rest of the request.
	 *
	 * @return bool
	 */
	public function is_rendering_order_confirmation(): bool {
		if ( ! $this->is_order_page() || null === $this->get_order_id_from_query_vars() ) {
			return false;
		}

		return $this->is_call_in_backtrace( array( 'Automattic\WooCommerce\Blocks\BlockTypes\OrderConfirmation\AbstractOrderConfirmationBlock->render' ) );
	}

	/**
	 * Get the ID of the order a single-order page names: order-pay, then order-received, then view-order.
	 *
	 * As client 11.3.0 `FrontendCurrencies::get_order_id_from_query_vars()` (`includes/multi-currency/FrontendCurrencies.php:475-485`):
	 * the first of those vars that is set decides, and a value that is not a positive integer names no order.
	 *
	 * @return int|null The order ID, or null when the page names no order.
	 */
	public function get_order_id_from_query_vars(): ?int {
		global $wp;

		if ( ! $wp instanceof \WP ) {
			return null;
		}

		foreach ( array( 'order-pay', 'order-received', 'view-order' ) as $query_var ) {
			if ( ! empty( $wp->query_vars[ $query_var ] ) ) {
				$order_id = absint( $wp->query_vars[ $query_var ] );

				return 0 < $order_id ? $order_id : null;
			}
		}

		return null;
	}

	/**
	 * Tell whether this is a My Account or checkout page about orders.
	 *
	 * @return bool
	 */
	private function is_order_page(): bool {
		return $this->is_page_with_vars( array( 'my-account', 'checkout' ), array( 'order-received', 'order-pay', 'orders', 'view-order' ) );
	}

	/**
	 * Tell whether the current request has one of the given page and query vars.
	 *
	 * @param array<int, string> $pages Page slugs.
	 * @param array<int, string> $vars  Query var names.
	 * @return bool
	 */
	private function is_page_with_vars( array $pages, array $vars ): bool {
		global $wp;

		if ( ! $wp instanceof \WP || empty( $wp->query_vars['pagename'] ) ) {
			return false;
		}

		if ( ! in_array( $wp->query_vars['pagename'], $pages, true ) ) {
			return false;
		}

		foreach ( $vars as $var ) {
			if ( isset( $wp->query_vars[ $var ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether one of the expected calls is present in the current backtrace.
	 *
	 * @param array<int, string> $calls Expected call summaries.
	 * @return bool
	 */
	private function is_call_in_backtrace( array $calls ): bool {
		$backtrace = null === $this->backtrace_summary_provider
			? wp_debug_backtrace_summary( null, 0, false ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			: call_user_func( $this->backtrace_summary_provider );

		if ( ! is_array( $backtrace ) ) {
			return false;
		}

		foreach ( $calls as $call ) {
			if ( in_array( $call, $backtrace, true ) ) {
				return true;
			}
		}

		return false;
	}
}
