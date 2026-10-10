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
 * Remembers, for this request, the refund row WooCommerce is about to refund through a gateway, and marks the refunds
 * the merchant records without one.
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
	 * Refund row meta key marking a refund the merchant recorded without refunding through a gateway.
	 *
	 * It is written at `woocommerce_create_refund` when wc_create_refund() is not asked to refund the payment, and stored by
	 * the save that follows the hook (includes/wc-order-functions.php:672-674). Only a row with the mark is taken for the
	 * merchant's record of an earlier refund, so a row still inside its datastore write, with no mark yet, never is
	 * (Codex review 205 R1, monitor ruling 2026-10-10 17:40).
	 *
	 * @since 11.2.0
	 */
	public const MANUAL_REFUND_META = '_wc_manual_refund_record';

	/**
	 * Provider gateways controller.
	 *
	 * @var ProviderGatewaysController
	 */
	private ProviderGatewaysController $gateways_controller;

	/**
	 * The refunds this request is refunding through one of the runtime's gateways, one per wc_create_refund() call
	 * not yet handed over or ended, innermost call last, each with the site it was created on.
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
	 * Register the hooks, once.
	 *
	 * @internal
	 */
	public function register(): void {
		if ( false === has_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ) ) ) {
			add_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ), 10, 2 );
		}
		foreach ( array( 'woocommerce_refund_created', 'woocommerce_delete_order_refund' ) as $hook ) {
			if ( false === has_action( $hook, array( $this, 'forget_refund' ) ) ) {
				add_action( $hook, array( $this, 'forget_refund' ), 10, 1 );
			}
		}
	}

	/**
	 * Keep a refund WooCommerce is about to refund through one of the runtime's gateways, next to those kept for calls
	 * still running.
	 *
	 * A refund that does not go through the gateway gets MANUAL_REFUND_META, which the save that follows this hook stores,
	 * and leaves what was kept alone, so a manual refund a callback creates inside a gateway refund's call never takes that
	 * call's row away (Codex review 204 F3). Refunds through other plugins' gateways are not kept: no refund call of the
	 * runtime takes them (Codex review 205 R2).
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
			$refund->update_meta_data( self::MANUAL_REFUND_META, 'yes' );
			return;
		}

		if ( ! $this->is_runtime_gateway_refund( $refund ) ) {
			return;
		}

		$this->refunds[] = array(
			'refund'  => $refund,
			'blog_id' => get_current_blog_id(),
		);
	}

	/**
	 * Forget a kept refund once its wc_create_refund() call has ended: WooCommerce created it, or it was deleted.
	 *
	 * Its gateway call can no longer come (Codex review 205 R2, monitor ruling 2026-10-10 17:25).
	 *
	 * @internal
	 *
	 * @param mixed $refund_id ID of the refund created or deleted.
	 */
	public function forget_refund( $refund_id ): void {
		$refund_id = absint( $refund_id );
		$blog_id   = get_current_blog_id();
		foreach ( $this->refunds as $index => $kept ) {
			if ( $blog_id === $kept['blog_id'] && $refund_id === $kept['refund']->get_id() ) {
				unset( $this->refunds[ $index ] );
			}
		}
		$this->refunds = array_values( $this->refunds );
	}

	/**
	 * Forget the refund kept for a refund call that returns before the refund runs.
	 *
	 * For a gateway that refuses before the processing service takes the row, so no later call takes it (Codex review
	 * 205 R2).
	 *
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Refund amount of the call.
	 */
	public function forget_for_call( WC_Order $order, float $amount ): void {
		$this->consume( $order, $amount );
	}

	/**
	 * Whether a refund's order was paid through one of the runtime's gateways.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return bool
	 */
	private function is_runtime_gateway_refund( WC_Order_Refund $refund ): bool {
		if ( ! isset( $this->gateways_controller ) || 0 >= $refund->get_parent_id() ) {
			return false;
		}

		$order = wc_get_order( $refund->get_parent_id() );

		return $order instanceof WC_Order && $this->gateways_controller->owns_gateway( (string) $order->get_payment_method() );
	}

	/**
	 * Take the row ID of the refund kept for this refund call, once.
	 *
	 * The call's refund is the innermost kept one that is a saved refund of this order on this site, for this call's
	 * amount (Codex review 205 R2, monitor ruling 2026-10-10 17:25); the others stay kept for their own calls. Amounts
	 * compare at the rounding precision, not the display one, so two amounts that only display the same never match
	 * (Codex review 204 F4).
	 *
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Refund amount of the call.
	 * @return int|null The row ID, or null when nothing applies.
	 */
	public function consume( WC_Order $order, float $amount ): ?int {
		$blog_id   = get_current_blog_id();
		$precision = wc_get_rounding_precision();
		$call      = wc_format_decimal( $amount, $precision );
		for ( $index = count( $this->refunds ) - 1; $index >= 0; $index-- ) {
			$refund = $this->refunds[ $index ]['refund'];
			if (
				$blog_id === $this->refunds[ $index ]['blog_id']
				&& 0 < $refund->get_id()
				&& $order->get_id() === $refund->get_parent_id()
				&& wc_format_decimal( (float) $refund->get_amount(), $precision ) === $call
			) {
				array_splice( $this->refunds, $index, 1 );

				return $refund->get_id();
			}
		}

		return null;
	}
}
