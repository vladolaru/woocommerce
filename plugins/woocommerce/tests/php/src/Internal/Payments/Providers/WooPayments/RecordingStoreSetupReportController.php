<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreSetupReportController;

/**
 * Store setup report controller that counts the syncs it is asked for instead of sending them.
 */
class RecordingStoreSetupReportController extends WooPaymentsStoreSetupReportController {

	/**
	 * Number of store setup syncs asked for.
	 *
	 * @var int
	 */
	public int $sync_count = 0;

	/**
	 * Count the sync instead of sending the report.
	 */
	public function handle_wcpay_store_setup_sync(): void {
		++$this->sync_count;
	}
}
