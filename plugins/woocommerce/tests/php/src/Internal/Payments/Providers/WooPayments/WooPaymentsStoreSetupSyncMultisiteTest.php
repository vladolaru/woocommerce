<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;

/**
 * Store setup sync on a multisite network.
 */
class WooPaymentsStoreSetupSyncMultisiteTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should list a network-active plugin among the site's active plugins in the store setup snapshot.
	 * @group multisite
	 */
	public function test_store_setup_sync_lists_network_active_plugins(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		update_option( 'active_plugins', array() );
		// The file exists in the test install, so WordPress keeps it as a valid network-active plugin.
		update_site_option( 'active_sitewide_plugins', array( 'hello.php' => time() ) );
		// get_plugins() answers from this cache, so the headers are the test's own: WordPress keeps it as the 'plugins' entry
		// of the 'plugins' cache group, keyed by plugin folder, '' for the plugins root (wp-admin/includes/plugin.php
		// get_plugins()).
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'hello.php' => array(
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					),
				),
			),
			'plugins'
		);

		$snapshots  = array();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_available', 'send_store_setup' ) )
			->getMock();
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'send_store_setup' )->willReturnCallback(
			static function ( array $snapshot ) use ( &$snapshots ): array {
				$snapshots[] = $snapshot;
				return array( 'result' => 'success' );
			}
		);

		$service = new WooPaymentsOperationalQueueService();
		$service->init( new RecordingActionSchedulerService(), $api_client, wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsDuplicateGatewayMethodsService::class ), wc_get_container()->get( WooPaymentsWooPayEligibility::class ) );

		try {
			$service->handle_wcpay_store_setup_sync();
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
			delete_site_option( 'active_sitewide_plugins' );
		}

		$this->assertCount( 1, $snapshots, 'The store setup sync sends one snapshot.' );
		$this->assertSame( array( 'Hello Dolly' ), array_column( $snapshots[0]['wp_setup']['active_plugins'], 'name' ) );
	}
}
