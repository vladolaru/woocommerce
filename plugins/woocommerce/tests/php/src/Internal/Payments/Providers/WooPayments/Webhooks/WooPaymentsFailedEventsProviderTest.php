<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsFailedEventsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFailedEventsProvider class.
 */
class WooPaymentsFailedEventsProviderTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * @testdox Failed webhook events are fetched from the API client and normalized.
	 */
	public function test_get_failed_webhook_events_uses_the_api_client(): void {
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
	 * @testdox A platform error fetching failed events returns an empty page and is logged with its status and code, never its message.
	 *
	 * The empty page keeps the Action Scheduler request going. Client 11.1.0 appends the platform's message to the line
	 * (`class-wc-payments-webhook-reliability-service.php`).
	 *
	 * @dataProvider provider_fetch_failures
	 *
	 * @param WooPaymentsApiException $failure         Error the API client throws.
	 * @param int                     $expected_status Logged HTTP status.
	 * @param string                  $expected_code   Logged error code.
	 */
	public function test_fetch_failure_log_leaves_out_platform_text( WooPaymentsApiException $failure, int $expected_status, string $expected_code ): void {
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'get_failed_webhook_events' )->willThrowException( $failure );
		$provider = new WooPaymentsFailedEventsProvider();
		$provider->init( $api_client );
		$logger = RecordingWcLogger::install();

		$result = $provider->get_failed_webhook_events();

		$this->assertSame( array(), $result['data'] );
		$this->assertFalse( $result['has_more'] );
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( $expected_status, $expected_code ), array( $context['http_status'], $context['error_code'] ) );
		$this->assertSame( 'woopayments', $context['source'] ?? null, 'Fetch failures land in the WooPayments log with every other webhook line.' );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Platform errors the failed-events fetch can meet.
	 *
	 * @return array<string,array{0:WooPaymentsApiException,1:int,2:string}>
	 */
	public function provider_fetch_failures(): array {
		return array(
			'server error'                => array( new WooPaymentsApiException( 'Remote fetch failed.', 'wcpay_failed_events_unavailable', 500 ), 500, 'unknown_error' ),
			'error carrying shopper text' => array( self::make_provider_error(), 404, 'resource_missing' ),
		);
	}
}
