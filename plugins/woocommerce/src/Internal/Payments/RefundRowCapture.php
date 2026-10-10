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
 * `wc_create_refund()` fires `woocommerce_create_refund` with the refund just before it saves it, and then, for a refund
 * through the gateway, calls `wc_refund_payment()` in the same request (includes/wc-order-functions.php:672-676). The
 * refund is kept here and handed to the refund call once, so the call links its own row even when another request saved
 * a newer one meanwhile. A direct `wc_refund_payment()` caller with no such row leaves nothing to take.
 *
 * @since 11.2.0
 * @internal
 */
class RefundRowCapture implements RegisterHooksInterface {

	/**
	 * Refund row meta key marking a row created to be refunded through a gateway the runtime owns, so code looking for
	 * the merchant's manual refunds never takes it for one, even while its refund call is still running.
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
	 * The refund the current request is refunding through a gateway, saved or about to be.
	 *
	 * @var WC_Order_Refund|null
	 */
	private ?WC_Order_Refund $refund = null;

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
		if ( false === has_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ) ) ) {
			add_action( 'woocommerce_create_refund', array( $this, 'handle_create_refund' ), 10, 2 );
		}
	}

	/**
	 * Keep a refund WooCommerce is about to refund through a gateway; any other refund it creates clears what was kept.
	 *
	 * A refund through one of the runtime's gateways also gets GATEWAY_REFUND_META, saved with the row (monitor ruling
	 * 2026-10-10 15:05). Refunds through other plugins' gateways are left untouched.
	 *
	 * @internal
	 *
	 * @param mixed $refund Refund being created.
	 * @param mixed $args   The arguments wc_create_refund() was called with.
	 */
	public function handle_create_refund( $refund, $args ): void {
		$this->refund = $refund instanceof WC_Order_Refund && is_array( $args ) && ! empty( $args['refund_payment'] ) ? $refund : null;
		if ( null === $this->refund ) {
			return;
		}

		$order = wc_get_order( $this->refund->get_parent_id() );
		if ( $order instanceof WC_Order && isset( $this->gateways_controller ) && $this->gateways_controller->owns_gateway( (string) $order->get_payment_method() ) ) {
			$this->refund->update_meta_data( self::GATEWAY_REFUND_META, 'yes' );
		}
	}

	/**
	 * Take the kept refund's row ID for a refund call, once.
	 *
	 * Only a saved refund of this order, for this call's amount, is handed over; whatever was kept is forgotten either way.
	 *
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Refund amount of the call.
	 * @return int|null The row ID, or null when nothing applies.
	 */
	public function consume( WC_Order $order, float $amount ): ?int {
		$refund       = $this->refund;
		$this->refund = null;
		if (
			null === $refund
			|| 0 >= $refund->get_id()
			|| $order->get_id() !== $refund->get_parent_id()
			|| wc_format_decimal( $amount, wc_get_price_decimals() ) !== wc_format_decimal( (float) $refund->get_amount(), wc_get_price_decimals() )
		) {
			return null;
		}

		return $refund->get_id();
	}
}
