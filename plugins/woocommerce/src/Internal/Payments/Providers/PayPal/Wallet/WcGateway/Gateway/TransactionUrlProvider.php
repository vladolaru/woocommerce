<?php
/**
 * Service able to provide transaction url base (URL with the placeholder instead of an actual transaction id)
 * based on the given WC Order.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;

/**
 * Class TransactionUrlProvider
 */
class TransactionUrlProvider {

	/**
	 * Transaction URL base used for sandbox payments.
	 *
	 * @var string
	 */
	protected $transaction_url_base_sandbox;

	/**
	 * Transaction URL base used for live payments.
	 *
	 * @var string
	 */
	protected $transaction_url_base_live;

	/**
	 * The environment.
	 *
	 * @var Environment
	 */
	protected $environment;

	/**
	 * TransactionUrlProvider constructor.
	 *
	 * @param string      $transaction_url_base_sandbox URL for sandbox orders.
	 * @param string      $transaction_url_base_live URL for live orders.
	 * @param Environment $environment The environment.
	 */
	public function __construct(
		string $transaction_url_base_sandbox,
		string $transaction_url_base_live,
		Environment $environment
	) {

		$this->transaction_url_base_sandbox = $transaction_url_base_sandbox;
		$this->transaction_url_base_live    = $transaction_url_base_live;
		$this->environment                  = $environment;
	}

	/**
	 * Return transaction url base
	 *
	 * @param \WC_Order $order WC order to get payment type from.
	 *
	 * @return string
	 */
	public function get_transaction_url_base( \WC_Order $order ): string {
		$order_payment_mode = $order->get_meta( PayPalGateway::ORDER_PAYMENT_MODE_META_KEY, true );

		// Gateways that do not store the payment mode on the order fall back to the
		// current environment, to avoid defaulting to the live URL.
		if ( ! $order_payment_mode ) {
			$order_payment_mode = $this->environment->is_sandbox() ? 'sandbox' : 'live';
		}

		return 'sandbox' === $order_payment_mode ? $this->transaction_url_base_sandbox : $this->transaction_url_base_live;
	}
}
