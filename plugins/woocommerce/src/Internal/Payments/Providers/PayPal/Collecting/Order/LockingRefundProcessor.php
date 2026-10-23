<?php
/**
 * LockingRefundProcessor class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use WC_Order;

/**
 * The wallet's refund processor, refusing the refund of an order whose refunds are locked.
 *
 * It wraps the processor the container built and hands every call to it. The gateway's process_refund() returns the
 * processor's bool and does not catch, so a refusal is thrown: wc_refund_payment() turns the exception into a WP_Error
 * carrying its message, which the order screen and the REST API show. A false return would show a generic error instead.
 * It is the wallet's RuntimeException, the class the wallet's own refund code throws.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class LockingRefundProcessor extends RefundProcessor {

	/**
	 * The wrapped processor.
	 *
	 * @var RefundProcessor
	 */
	private RefundProcessor $inner;

	/**
	 * The refund lock.
	 *
	 * @var RefundLock
	 */
	private RefundLock $lock;

	/**
	 * Constructor. The parent's collaborators are not needed: every call goes to the wrapped processor.
	 *
	 * @param RefundProcessor $inner The wrapped processor.
	 * @param RefundLock      $lock  The refund lock.
	 */
	public function __construct( RefundProcessor $inner, RefundLock $lock ) {
		$this->inner = $inner;
		$this->lock  = $lock;
	}

	/**
	 * Refuse a locked refund, or else process it with the wrapped processor.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order   $wc_order The WooCommerce order.
	 * @param float|null $amount   The refund amount.
	 * @param string     $reason   The reason for the refund.
	 * @return bool
	 *
	 * @throws RuntimeException When the order's refunds are locked.
	 */
	public function process( WC_Order $wc_order, ?float $amount = null, string $reason = '' ): bool {
		if ( $this->lock->is_locked( $wc_order ) ) {
			throw new RuntimeException( esc_html( RefundLock::message() ) );
		}

		return $this->inner->process( $wc_order, $amount, $reason );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order    $order    The PayPal order.
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param float    $amount   The refund amount.
	 * @param string   $reason   The reason for the refund.
	 * @return string The PayPal refund ID.
	 *
	 * @throws RuntimeException When the refund fails.
	 */
	public function refund( Order $order, WC_Order $wc_order, float $amount, string $reason = '' ): string {
		return $this->inner->refund( $order, $wc_order, $amount, $reason );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order $order The PayPal order.
	 *
	 * @throws RuntimeException When the void fails.
	 */
	public function void( Order $order ): void {
		$this->inner->void( $order );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order $order The PayPal order.
	 * @return string One of the REFUND_MODE_ constants.
	 */
	public function determine_refund_mode( Order $order ): string {
		return $this->inner->determine_refund_mode( $order );
	}
}
