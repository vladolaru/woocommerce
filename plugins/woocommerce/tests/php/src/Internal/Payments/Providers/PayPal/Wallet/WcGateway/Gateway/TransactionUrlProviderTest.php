<?php
/**
 * Tests for the transaction URL base provider.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\TransactionUrlProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * The transaction link of an order points to the sandbox or the live PayPal site: the payment mode stored on the order
 * wins, and the current environment decides only for an order without one.
 *
 * @group paypal-wallet
 */
class TransactionUrlProviderTest extends WalletTestCase {

	/**
	 * @testdox Should pick the transaction URL base from the stored payment mode, then from the environment.
	 *
	 * @dataProvider data_transaction_url_base
	 *
	 * @param string $order_payment_mode The payment mode stored on the order, empty for none.
	 * @param bool   $is_sandbox         Whether the store is in the sandbox environment.
	 * @param string $expected_result    The expected URL base.
	 */
	public function test_get_transaction_url_base( string $order_payment_mode, bool $is_sandbox, string $expected_result ): void {
		$testee = new TransactionUrlProvider( 'sandbox.example.com', 'example.com', new Environment( $is_sandbox ) );

		$order = new WC_Order();
		if ( '' !== $order_payment_mode ) {
			$order->update_meta_data( PayPalGateway::ORDER_PAYMENT_MODE_META_KEY, $order_payment_mode );
		}

		$this->assertSame( $expected_result, $testee->get_transaction_url_base( $order ) );
	}

	/**
	 * The payment mode on the order and the environment, with the URL base each leads to.
	 *
	 * @return array<string, array{string, bool, string}>
	 */
	public function data_transaction_url_base(): array {
		return array(
			'a sandbox order wins over a live environment' => array( 'sandbox', false, 'sandbox.example.com' ),
			'a live order wins over a sandbox environment' => array( 'live', true, 'example.com' ),
			'no stored mode in a sandbox environment'      => array( '', true, 'sandbox.example.com' ),
			'no stored mode in a live environment'         => array( '', false, 'example.com' ),
		);
	}
}
