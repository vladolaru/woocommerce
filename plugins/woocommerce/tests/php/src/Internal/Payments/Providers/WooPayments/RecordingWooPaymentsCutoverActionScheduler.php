<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;

/**
 * Recording cutover scheduler test double.
 */
class RecordingWooPaymentsCutoverActionScheduler extends WooPaymentsCutoverActionScheduler {

	/** @var int Number of explicit async dispatch requests. */
	public int $dispatch_count = 0;

	/** @var bool Whether dispatch observed a released state lease. */
	public bool $dispatch_observed_released_lease = false;

	/** @var WooPaymentsCutoverStateStore Cutover state store. */
	private WooPaymentsCutoverStateStore $state_store;

	/**
	 * Initialize the recording scheduler.
	 *
	 * @param WooPaymentsCutoverStateStore $state_store Cutover state store.
	 */
	public function __construct( WooPaymentsCutoverStateStore $state_store ) {
		$this->state_store = $state_store;
	}

	/**
	 * Record one explicit async dispatch request.
	 */
	public function dispatch_async(): void {
		++$this->dispatch_count;
		$record = $this->state_store->get_record();

		$this->dispatch_observed_released_lease = is_array( $record ) && null === $record['lease_token'] && null === $record['lease_expires_at'];
	}
}
