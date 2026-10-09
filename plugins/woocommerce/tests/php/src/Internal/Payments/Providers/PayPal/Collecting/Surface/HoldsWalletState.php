<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Order;

/**
 * Puts a store in the states the surfaces read: collecting, platform connected, first-party connected, with a first order
 * and held orders. Used by tests that extend WalletTestCase, which removes the options again.
 */
trait HoldsWalletState {

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => true,
			)
		);
	}

	/**
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'tracking_id' => 'abc',
				'payee_email' => 'payee@example.com',
				'environment' => 'sandbox',
			)
		);
	}

	/**
	 * Store the shared settings option as a connected merchant, the way the wallet's GeneralSettings model saves it.
	 */
	private function set_first_party_connected(): void {
		$this->set_wallet_option(
			'woocommerce-ppcp-data-common',
			array(
				'merchant_connected' => true,
				'sandbox_merchant'   => true,
				'merchant_id'        => 'TESTMERCHANTID',
				'merchant_email'     => 'merchant@example.com',
				'client_id'          => 'test-client-id',
				'client_secret'      => 'test-client-secret',
			)
		);
	}

	/**
	 * Record the first order, as the claim does: an autoloaded option written once.
	 *
	 * @param int $order_id The order ID.
	 */
	private function set_first_order( int $order_id ): void {
		add_option( Options::FIRST_ORDER, $order_id, '', true );
	}

	/**
	 * An on-hold wallet order that PayPal holds. Its creation date is the time it was held, as in a real capture.
	 *
	 * @param int|null $held_at When PayPal began to hold the payment, as a UTC timestamp; now when null.
	 * @return WC_Order
	 */
	private function held_order( ?int $held_at = null ): WC_Order {
		$order   = wc_create_order();
		$held_at = $held_at ?? time();
		$order->set_payment_method( PayPalGateway::ID );
		$order->set_date_created( $held_at );
		$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
		$order->update_meta_data( HeldCapture::HELD_AT_META_KEY, (string) $held_at );
		$order->set_status( 'on-hold' );
		$order->save();

		return $order;
	}
}
