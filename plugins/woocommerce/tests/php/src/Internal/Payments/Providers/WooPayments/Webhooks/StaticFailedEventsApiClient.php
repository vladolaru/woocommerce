<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * API client test double that answers the failed-events fetch with a fixed page.
 */
class StaticFailedEventsApiClient extends WooPaymentsApiClient {

	/**
	 * Failed-events response.
	 *
	 * @var array<string,mixed>
	 */
	private array $response;

	/**
	 * Constructor.
	 *
	 * The default is an empty page in the envelope client 11.1.0 reads
	 * (`includes/class-wc-payments-webhook-reliability-service.php:100-121`): `data` holds the failed events,
	 * `has_more` asks for the next page.
	 *
	 * @param array<string,mixed> $response Failed-events response.
	 */
	public function __construct(
		array $response = array(
			'data'     => array(),
			'has_more' => false,
		)
	) {
		$this->response = $response;
	}

	/**
	 * Get failed webhook events.
	 *
	 * @return array<string,mixed>
	 */
	public function get_failed_webhook_events(): array {
		return $this->response;
	}
}
