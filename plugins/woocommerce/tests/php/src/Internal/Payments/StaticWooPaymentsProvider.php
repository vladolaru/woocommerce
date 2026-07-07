<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Payment_Gateway;

/**
 * Static WooPayments provider for registry tests.
 */
class StaticWooPaymentsProvider extends WooPaymentsProvider {

	/**
	 * Whether the provider can process payments.
	 *
	 * @var bool
	 */
	private bool $can_process_payments;

	/**
	 * Payment gateways published by the provider.
	 *
	 * @var array<int,WC_Payment_Gateway>
	 */
	private array $payment_gateways;

	/**
	 * Constructor.
	 *
	 * @param bool                          $can_process_payments Whether the provider can process payments.
	 * @param array<int,WC_Payment_Gateway> $payment_gateways Payment gateways published by the provider.
	 */
	public function __construct( bool $can_process_payments, array $payment_gateways = array() ) {
		$this->can_process_payments = $can_process_payments;
		$this->payment_gateways     = $payment_gateways;
	}

	/**
	 * Tell whether WooPayments can currently process native money operations.
	 *
	 * @return bool
	 */
	public function can_process_payments(): bool {
		return $this->can_process_payments;
	}

	/**
	 * Get payment gateway instances published by the provider.
	 *
	 * @return array<int,WC_Payment_Gateway>
	 */
	public function get_payment_gateways(): array {
		return $this->payment_gateways;
	}
}
