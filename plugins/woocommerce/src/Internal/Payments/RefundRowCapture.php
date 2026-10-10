<?php
/**
 * RefundRowCapture class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;
use WC_Order_Refund;

/**
 * Remembers, for this request, the refund row WooCommerce is about to refund through a gateway.
 *
 * `wc_create_refund()` first saves the refund inside `update_taxes()`, then fires `woocommerce_create_refund` with it,
 * saves it again and, for a refund through the gateway, calls `wc_refund_payment()` in the same request
 * (includes/wc-order-functions.php:657,672-676). The refund is kept here and handed to the refund call once, so the call
 * links its own row even when another request saved a newer one meanwhile. A direct `wc_refund_payment()` caller with
 * no such row leaves nothing to take.
 *
 * @since 11.2.0
 * @internal
 */
class RefundRowCapture implements RegisterHooksInterface {

	/**
	 * Refund row meta key marking a row created to be refunded through a gateway the runtime owns, so code looking for
	 * the merchant's manual refunds never takes it for one, even while its refund call is still running.
	 *
	 * It is written at the row's first save, before WooCommerce says whether the refund goes through the gateway, and
	 * removed at `woocommerce_create_refund` when it does not (monitor ruling 2026-10-10 16:30).
	 *
	 * @since 11.2.0
	 */
	public const GATEWAY_REFUND_META = '_wc_provider_gateway_refund';

	/**
	 * Provider gateways controller.
	 *
	 * @var ProviderGatewaysController
	 */
	private ProviderGatewaysController $gateways_controller;

	/**
	 * The refunds this request is refunding through a gateway, one per wc_create_refund() call not yet handed over,
	 * innermost call last, each with the site it was created on.
	 *
	 * @var array<int,array{refund:WC_Order_Refund,blog_id:int}>
	 */
	private array $refunds = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param ProviderGatewaysController $gateways_controller Provider gateways controller.
	 */
	final public function init( ProviderGatewaysController $gateways_controller ): void {
		$this->gateways_controller = $gateways_controller;
	}

	/**
	 * Register the hook, once.
	 *
	 * @internal
	 */
	public function register(): void {
		if ( false === has_action( 'woocommerce_before_order_refund_object_save', array( $this, 'handle_before_refund_save' ) ) ) {
			add_action( 'woocommerce_before_order_refund_object_save', array( $this, 'handle_before_refund_save' ), 10, 1 );
		}
		if ( false === has_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ) ) ) {
			add_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ), 10, 2 );
		}
	}

	/**
	 * Mark a new refund of an order paid through one of the runtime's gateways before its first save.
	 *
	 * WooCommerce saves the row in wc_create_refund() before it fires `woocommerce_create_refund`, so the marker must
	 * already be there for another request never to take the row for a manual record (Codex review 204 F1).
	 *
	 * @internal
	 *
	 * @param mixed $refund Refund about to be saved.
	 */
	public function handle_before_refund_save( $refund ): void {
		if ( $refund instanceof WC_Order_Refund && 0 === $refund->get_id() ) {
			$this->mark_if_runtime_gateway_refund( $refund );
		}
	}

	/**
	 * Keep a refund WooCommerce is about to refund through a gateway, on top of those kept for calls still running.
	 *
	 * A refund that does not go through the gateway leaves what was kept alone, so a manual refund a callback creates
	 * inside a gateway refund's call never takes that call's row away (Codex review 204 F3). It loses the marker its first
	 * save gave it; the save that follows this hook stores the removal. Refunds through other plugins' gateways are never
	 * marked.
	 *
	 * @internal
	 *
	 * @param mixed $refund Refund being created.
	 * @param mixed $args   The arguments wc_create_refund() was called with.
	 */
	public function handle_create_refund( $refund, $args ): void {
		if ( ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		if ( ! is_array( $args ) || empty( $args['refund_payment'] ) ) {
			$refund->delete_meta_data( self::GATEWAY_REFUND_META );
			return;
		}

		$this->refunds[] = array(
			'refund'  => $refund,
			'blog_id' => get_current_blog_id(),
		);
		if ( '' === $refund->get_meta( self::GATEWAY_REFUND_META, true ) ) {
			$this->mark_if_runtime_gateway_refund( $refund );
		}
	}

	/**
	 * Mark a refund with GATEWAY_REFUND_META when its order was paid through one of the runtime's gateways.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private function mark_if_runtime_gateway_refund( WC_Order_Refund $refund ): void {
		if ( ! isset( $this->gateways_controller ) || 0 >= $refund->get_parent_id() ) {
			return;
		}

		$order = wc_get_order( $refund->get_parent_id() );
		if ( $order instanceof WC_Order && $this->gateways_controller->owns_gateway( (string) $order->get_payment_method() ) ) {
			$refund->update_meta_data( self::GATEWAY_REFUND_META, 'yes' );
		}
	}

	/**
	 * Take the row ID of the refund kept for the innermost wc_create_refund() call still running, once.
	 *
	 * A gateway refund call belongs to that innermost call: WooCommerce refunds through the gateway before it returns.
	 * Only a saved refund of this order on this site, for this call's amount, is handed over; the innermost refund is
	 * forgotten either way, and those kept for outer calls stay. Amounts compare at the rounding precision, not the
	 * display one, so two amounts that only display the same never match (Codex review 204 F4).
	 *
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Refund amount of the call.
	 * @return int|null The row ID, or null when nothing applies.
	 */
	public function consume( WC_Order $order, float $amount ): ?int {
		$kept = array_pop( $this->refunds );
		if ( null === $kept || get_current_blog_id() !== $kept['blog_id'] ) {
			return null;
		}

		$refund    = $kept['refund'];
		$precision = wc_get_rounding_precision();
		if (
			0 >= $refund->get_id()
			|| $order->get_id() !== $refund->get_parent_id()
			|| wc_format_decimal( $amount, $precision ) !== wc_format_decimal( (float) $refund->get_amount(), $precision )
		) {
			return null;
		}

		return $refund->get_id();
	}
}
