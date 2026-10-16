<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Recording API client for native WooPayments settings tests.
 */
class RecordingSettingsApiClient extends WooPaymentsApiClient {

	/**
	 * Last account settings payload.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_account_settings = null;

	/**
	 * Last fraud ruleset payload.
	 *
	 * @var array<int|string,mixed>|null
	 */
	public ?array $last_fraud_ruleset = null;

	/**
	 * Latest fraud ruleset response.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $latest_fraud_ruleset_response = null;

	/**
	 * Latest fraud ruleset exception.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $latest_fraud_ruleset_exception = null;

	/**
	 * Exception the fraud ruleset save throws, when set.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $save_fraud_ruleset_exception = null;

	/**
	 * Account update exception.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $update_account_exception = null;

	/**
	 * Exception a capability request throws, when set.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $request_capability_exception = null;

	/**
	 * Fraud ruleset save count.
	 *
	 * @var int
	 */
	public int $fraud_ruleset_saves = 0;

	/**
	 * Latest fraud ruleset request count.
	 *
	 * @var int
	 */
	public int $latest_fraud_ruleset_requests = 0;

	/**
	 * Capability requests.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $capability_requests = array();

	/**
	 * Update account settings.
	 *
	 * @param array<string,mixed> $account_settings Account settings.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the test fixture is configured to fail.
	 */
	public function update_account( array $account_settings ): array {
		$this->last_account_settings = $account_settings;

		if ( $this->update_account_exception instanceof WooPaymentsApiException ) {
			throw $this->update_account_exception;
		}

		return $account_settings;
	}

	/**
	 * Request a payment method capability.
	 *
	 * @param string $capability_id Capability ID.
	 * @param bool   $requested Whether the capability is requested.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the test fixture is configured to fail.
	 */
	public function request_capability( string $capability_id, bool $requested ): array {
		$this->capability_requests[] = array(
			'capability_id' => $capability_id,
			'requested'     => $requested,
		);

		if ( $this->request_capability_exception instanceof WooPaymentsApiException ) {
			throw $this->request_capability_exception;
		}

		return array( 'status' => 'active' );
	}

	/**
	 * Save fraud ruleset.
	 *
	 * @param array<int|string,mixed> $ruleset_config Ruleset config.
	 * @return array<string,mixed>
	 */
	public function save_fraud_ruleset( array $ruleset_config ): array {
		if ( $this->save_fraud_ruleset_exception instanceof WooPaymentsApiException ) {
			throw $this->save_fraud_ruleset_exception;
		}

		++$this->fraud_ruleset_saves;
		$this->last_fraud_ruleset = $ruleset_config;

		return array( 'success' => true );
	}

	/**
	 * Get latest fraud ruleset.
	 *
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the test fixture is configured to fail.
	 */
	public function get_latest_fraud_ruleset(): array {
		++$this->latest_fraud_ruleset_requests;

		if ( $this->latest_fraud_ruleset_exception instanceof WooPaymentsApiException ) {
			throw $this->latest_fraud_ruleset_exception;
		}

		return $this->latest_fraud_ruleset_response ?? array();
	}
}
