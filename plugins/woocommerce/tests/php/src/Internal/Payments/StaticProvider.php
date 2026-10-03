<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderContract;
use WC_Payment_Gateway;

/**
 * Static provider for registry tests.
 */
class StaticProvider implements PaymentGatewayProviderContract {

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
	 * Provider ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Number of provider readiness checks.
	 *
	 * @var int
	 */
	public int $can_process_payments_calls = 0;

	/**
	 * Constructor.
	 *
	 * @param bool                          $can_process_payments Whether the provider can process payments.
	 * @param array<int,WC_Payment_Gateway> $payment_gateways Payment gateways published by the provider.
	 * @param string                        $id Provider ID.
	 */
	public function __construct( bool $can_process_payments, array $payment_gateways = array(), string $id = 'static_provider' ) {
		$this->can_process_payments = $can_process_payments;
		$this->payment_gateways     = $payment_gateways;
		$this->id                   = $id;
	}

	/**
	 * Set current provider readiness.
	 *
	 * @param bool $can_process_payments Whether the provider can process payments.
	 */
	public function set_can_process_payments( bool $can_process_payments ): void {
		$this->can_process_payments = $can_process_payments;
	}

	/**
	 * Tell whether the provider can currently process native money operations.
	 *
	 * @return bool
	 */
	public function can_process_payments(): bool {
		++$this->can_process_payments_calls;

		return $this->can_process_payments;
	}

	/**
	 * Get the provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
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
