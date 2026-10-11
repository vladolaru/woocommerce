<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;

/**
 * Recording API client for Apple Pay domain registration tests.
 */
class RecordingApplePayDomainApiClient extends WooPaymentsApiClient {

	/**
	 * Registered domains.
	 *
	 * @var array<int,string>
	 */
	public array $registered_domains = array();

	/**
	 * Response returned by register_apple_pay_domain().
	 *
	 * @var array<string,mixed>
	 */
	public array $response = array(
		'id'        => 'domain_123',
		'apple_pay' => array( 'status' => 'active' ),
	);

	/**
	 * Exception returned by register_apple_pay_domain().
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $exception = null;

	/**
	 * Register an Apple Pay domain.
	 *
	 * @param string $domain_name Domain name.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When configured to fail.
	 */
	public function register_apple_pay_domain( string $domain_name ): array {
		$this->registered_domains[] = $domain_name;

		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return $this->response;
	}
}
