<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventsProvider;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFailedEventsProvider class.
 */
class WooPaymentsFailedEventsProviderTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * @testdox Failed webhook events are fetched from the native API client and normalized.
	 */
	public function test_get_failed_webhook_events_uses_native_api_client(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Get failed webhook events.
			 *
			 * @return array<string,mixed>
			 */
			public function get_failed_webhook_events(): array {
				return array(
					'data'     => array(
						array(
							'id' => 'evt_1',
						),
						'not-an-event',
					),
					'has_more' => true,
				);
			}
		};
		$provider   = new WooPaymentsFailedEventsProvider();
		$provider->init( $api_client );

		$result = $provider->get_failed_webhook_events();

		$this->assertSame( array( array( 'id' => 'evt_1' ) ), $result['data'] );
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * @testdox API failures return an empty page instead of aborting the Action Scheduler request.
	 */
	public function test_get_failed_webhook_events_returns_empty_page_on_api_failure(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Get failed webhook events.
			 *
			 * @throws WooPaymentsApiException Always.
			 */
			public function get_failed_webhook_events(): array {
				throw new WooPaymentsApiException( 'Remote fetch failed.', 'wcpay_failed_events_unavailable', 500 );
			}
		};
		$provider   = new WooPaymentsFailedEventsProvider();
		$provider->init( $api_client );

		$result = $provider->get_failed_webhook_events();

		$this->assertSame( array(), $result['data'] );
		$this->assertFalse( $result['has_more'] );
	}

	/**
	 * @testdox A platform error fetching failed events is logged with its status and code, never its message.
	 *
	 * Client 11.1.0 appends the platform's message (`class-wc-payments-webhook-reliability-service.php`).
	 */
	public function test_fetch_failure_log_leaves_out_platform_text(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			// phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
			/**
			 * Fail the fetch as the platform does.
			 *
			 * @throws WooPaymentsApiException Always.
			 */
			public function get_failed_webhook_events(): array {
				throw WooPaymentsFailedEventsProviderTest::make_provider_error();
			}
		};
		$provider   = new WooPaymentsFailedEventsProvider();
		$provider->init( $api_client );
		$logger = RecordingWcLogger::install();

		$provider->get_failed_webhook_events();

		$context = $this->get_logged_context( $logger, 'Can not fetch failed events from the server.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}
}
