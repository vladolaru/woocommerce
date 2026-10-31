<?php
/**
 * WooPaymentsWooCommerceUpdateListenerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\ExtensionSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreSetupReportController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooCommerceUpdateListener;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the duties a WooCommerce update runs for a connected WooPayments store.
 */
class WooPaymentsWooCommerceUpdateListenerTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var WooPaymentsWooCommerceUpdateListener
	 */
	private WooPaymentsWooCommerceUpdateListener $sut;

	/**
	 * Account service double.
	 *
	 * @var WooPaymentsAccountService&MockObject
	 */
	private $account_service;

	/**
	 * Store setup report double.
	 *
	 * @var WooPaymentsStoreSetupReportController&MockObject
	 */
	private $store_setup_report;

	/**
	 * WooPay extension sync double.
	 *
	 * @var ExtensionSync&MockObject
	 */
	private $woopay_extension_sync;

	/**
	 * Set up the listener with service doubles in the container.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->account_service       = $this->createMock( WooPaymentsAccountService::class );
		$this->store_setup_report    = $this->createMock( WooPaymentsStoreSetupReportController::class );
		$this->woopay_extension_sync = $this->createMock( ExtensionSync::class );
		wc_get_container()->replace( WooPaymentsAccountService::class, $this->account_service );
		wc_get_container()->replace( WooPaymentsStoreSetupReportController::class, $this->store_setup_report );
		wc_get_container()->replace( ExtensionSync::class, $this->woopay_extension_sync );
		$this->sut = new WooPaymentsWooCommerceUpdateListener();
	}

	/**
	 * Remove the listener's hook and the container replacements.
	 */
	public function tearDown(): void {
		remove_action( 'woocommerce_updated', array( $this->sut, 'handle_woocommerce_updated' ) );
		wc_get_container()->reset_replacement( WooPaymentsAccountService::class );
		wc_get_container()->reset_replacement( WooPaymentsStoreSetupReportController::class );
		wc_get_container()->reset_replacement( ExtensionSync::class );
		parent::tearDown();
	}

	/**
	 * @testdox A WooCommerce update refreshes the account, queues a store setup sync and drops the retired WooPay schedule.
	 */
	public function test_woocommerce_update_runs_the_update_duties(): void {
		// Client 11.1.0 runs these on its own update action: class-wc-payments-account.php:143,147 and
		// woopay/class-woopay-scheduler.php:50.
		$this->account_service->expects( $this->once() )->method( 'clear_cache' );
		$this->store_setup_report->expects( $this->once() )->method( 'queue_store_setup_sync' );
		$this->woopay_extension_sync->expects( $this->once() )->method( 'remove_retired_validation_schedule' );
		$this->sut->register();

		do_action( 'woocommerce_updated' );
	}

	/**
	 * @testdox Registering the listener runs none of the update duties; they wait for a WooCommerce update.
	 */
	public function test_registration_runs_no_update_duty(): void {
		$this->account_service->expects( $this->never() )->method( 'clear_cache' );
		$this->store_setup_report->expects( $this->never() )->method( 'queue_store_setup_sync' );
		$this->woopay_extension_sync->expects( $this->never() )->method( 'remove_retired_validation_schedule' );

		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_updated', array( $this->sut, 'handle_woocommerce_updated' ) ) );
	}
}
