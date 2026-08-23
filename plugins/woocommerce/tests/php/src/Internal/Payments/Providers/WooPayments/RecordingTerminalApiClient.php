<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;

/**
 * Recording API client for native mobile REST tests.
 */
class RecordingTerminalApiClient extends WooPaymentsApiClient {
	/**
	 * Connection token response.
	 *
	 * @var array<string,mixed>
	 */
	public array $connection_token_response = array();

	/**
	 * Last terminal intent payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_terminal_intent_payload = array();

	/**
	 * Prepared terminal payment calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $prepared_terminal_payments = array();

	/**
	 * Terminal reader registration response.
	 *
	 * @var array<string,mixed>
	 */
	public array $terminal_reader_response = array();

	/**
	 * Exception thrown by register_terminal_reader when set.
	 *
	 * @var \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException|null
	 */
	public $register_reader_exception = null;

	/**
	 * Last registered terminal reader payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_registered_reader = array();

	/**
	 * Terminal readers response.
	 *
	 * @var array<string,mixed>
	 */
	public array $terminal_readers_response = array();

	/**
	 * Reader charge summary response.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $reader_charge_summary_response = array();

	/**
	 * Reader charge summary calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $reader_charge_summary_calls = array();

	/**
	 * Transaction response.
	 *
	 * @var array<string,mixed>
	 */
	public array $transaction_response = array();

	/**
	 * Terminal locations response.
	 *
	 * @var array<string,mixed>
	 */
	public array $terminal_locations_response = array();

	/**
	 * Terminal location response.
	 *
	 * @var array<string,mixed>
	 */
	public array $terminal_location_response = array();

	/**
	 * Terminal location lookup calls.
	 *
	 * @var string[]
	 */
	public array $terminal_location_calls = array();

	/**
	 * Last created location payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_created_location = array();

	/**
	 * Payment intent response.
	 *
	 * @var array<string,mixed>
	 */
	public array $payment_intention_response = array();

	/**
	 * Captured intent response.
	 *
	 * @var array<string,mixed>
	 */
	public array $captured_intention_response = array();

	/**
	 * Exception thrown by capture_intention when set.
	 *
	 * @var \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException|null
	 */
	public $captured_intention_exception = null;

	/**
	 * Last metadata passed to capture_intention.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_capture_metadata = array();

	/**
	 * Queue of get_payment_intention responses, shifted per call when non-empty.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $payment_intention_response_queue = array();

	/**
	 * File contents response.
	 *
	 * @var array<string,mixed>
	 */
	public array $file_contents_response = array();

	/**
	 * Last get_file_contents call: array( file_id, as_account ).
	 *
	 * @var array<int,mixed>
	 */
	public array $last_file_contents_request = array();

	/**
	 * Charge response.
	 *
	 * @var array<string,mixed>
	 */
	public array $charge_response = array();

	/**
	 * Updated customer calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $updated_customers = array();

	/**
	 * Created customer calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $created_customers = array();

	/**
	 * Created customer ID response.
	 *
	 * @var string
	 */
	public string $created_customer_id = 'cus_created';

	/**
	 * Optional update-customer exception.
	 *
	 * @var WooPaymentsApiException|null
	 */
	public ?WooPaymentsApiException $update_customer_exception = null;

	/**
	 * Create a terminal connection token.
	 *
	 * @return array<string,mixed>
	 */
	public function create_terminal_connection_token(): array {
		return $this->connection_token_response;
	}

	/**
	 * Create a terminal payment intent.
	 *
	 * @param array<string,mixed> $request_data Request payload.
	 * @return array<string,mixed>
	 */
	public function create_terminal_payment_intention( array $request_data ): array {
		$this->last_terminal_intent_payload = $request_data;

		return array( 'id' => 'pi_terminal' );
	}

	/**
	 * Prepare a terminal payment.
	 *
	 * @param string $intent_id Intent ID.
	 * @param int    $order_id  Order ID.
	 * @return array<string,mixed>
	 */
	public function prepare_terminal_payment( string $intent_id, int $order_id ): array {
		$this->prepared_terminal_payments[] = array(
			'intent_id' => $intent_id,
			'order_id'  => $order_id,
		);

		return array( 'status' => 'collecting_payment_method' );
	}

	/**
	 * Get terminal readers.
	 *
	 * @return array<string,mixed>
	 */
	public function get_terminal_readers(): array {
		return $this->terminal_readers_response;
	}

	/**
	 * Register a terminal reader.
	 *
	 * @param string                   $location          Location ID.
	 * @param string                   $registration_code Registration code.
	 * @param string|null              $label             Reader label.
	 * @param array<string,mixed>|null $metadata          Reader metadata.
	 * @return array<string,mixed>
	 */
	public function register_terminal_reader( string $location, string $registration_code, ?string $label = null, ?array $metadata = null ): array {
		if ( null !== $this->register_reader_exception ) {
			throw $this->register_reader_exception;
		}

		$this->last_registered_reader = array(
			'location'          => $location,
			'registration_code' => $registration_code,
			'label'             => $label,
			'metadata'          => $metadata,
		);

		return $this->terminal_reader_response;
	}

	/**
	 * Get reader charge summary.
	 *
	 * @param string      $charge_date    Charge date.
	 * @param string|null $transaction_id Transaction ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_readers_charge_summary( string $charge_date, ?string $transaction_id = null ): array {
		$this->reader_charge_summary_calls[] = array(
			'charge_date'    => $charge_date,
			'transaction_id' => $transaction_id,
		);

		return $this->reader_charge_summary_response;
	}

	/**
	 * Get a WooPayments transaction.
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return array<string,mixed>
	 */
	public function get_transaction( string $transaction_id ): array {
		unset( $transaction_id );

		return $this->transaction_response;
	}

	/**
	 * Get terminal locations.
	 *
	 * @return array<string,mixed>
	 */
	public function get_terminal_locations(): array {
		return $this->terminal_locations_response;
	}

	/**
	 * Get one terminal location.
	 *
	 * @param string $location_id Location ID.
	 * @return array<string,mixed>
	 */
	public function get_terminal_location( string $location_id ): array {
		$this->terminal_location_calls[] = $location_id;

		return $this->terminal_location_response;
	}

	/**
	 * Create a terminal location.
	 *
	 * @param string              $display_name Display name.
	 * @param array<string,mixed> $address      Address.
	 * @param array<string,mixed> $metadata     Metadata.
	 * @return array<string,mixed>
	 */
	public function create_terminal_location( string $display_name, array $address, array $metadata = array() ): array {
		$this->last_created_location = array(
			'display_name' => $display_name,
			'address'      => $address,
			'metadata'     => $metadata,
		);

		return array(
			'id'           => 'tml_created',
			'display_name' => $display_name,
			'address'      => $address,
			'livemode'     => false,
		);
	}

	/**
	 * Retrieve a WooPayments PaymentIntent.
	 *
	 * @param string $intent_id Intent ID.
	 * @return array<string,mixed>
	 */
	public function get_payment_intention( string $intent_id ): array {
		unset( $intent_id );

		if ( array() !== $this->payment_intention_response_queue ) {
			return array_shift( $this->payment_intention_response_queue );
		}

		return $this->payment_intention_response;
	}

	/**
	 * Retrieve provider file contents.
	 *
	 * @param string $file_id    Provider file ID.
	 * @param bool   $as_account Whether to fetch the file as the connected account.
	 * @return array<string,mixed>
	 */
	public function get_file_contents( string $file_id, bool $as_account = true ): array {
		$this->last_file_contents_request = array( $file_id, $as_account );

		return $this->file_contents_response;
	}

	/**
	 * Capture a payment intention.
	 *
	 * @param string              $intent_id          Intent ID.
	 * @param int                 $amount_to_capture Amount to capture.
	 * @param array<string,mixed> $metadata           Intent metadata.
	 * @param array<string,mixed> $level3            Level 3 data.
	 * @return array<string,mixed>
	 */
	public function capture_intention( string $intent_id, int $amount_to_capture, array $metadata = array(), array $level3 = array() ): array {
		unset( $intent_id, $amount_to_capture );

		$this->last_capture_metadata = $metadata;

		if ( null !== $this->captured_intention_exception ) {
			throw $this->captured_intention_exception;
		}

		return $this->captured_intention_response;
	}

	/**
	 * Get a WooPayments charge.
	 *
	 * @param string $charge_id Charge ID.
	 * @return array<string,mixed>
	 */
	public function get_charge( string $charge_id ): array {
		unset( $charge_id );

		return $this->charge_response;
	}

	/**
	 * Update a WooPayments customer.
	 *
	 * @param string              $customer_id   Customer ID.
	 * @param array<string,mixed> $customer_data Customer data.
	 */
	public function update_customer( string $customer_id, array $customer_data = array() ): void {
		$this->updated_customers[] = array(
			'customer_id'   => $customer_id,
			'customer_data' => $customer_data,
		);

		if ( $this->update_customer_exception instanceof WooPaymentsApiException ) {
			throw $this->update_customer_exception;
		}
	}

	/**
	 * Create a WooPayments customer.
	 *
	 * @param array<string,mixed> $customer_data Customer data.
	 * @return string
	 */
	public function create_customer( array $customer_data ): string {
		$this->created_customers[] = $customer_data;

		return $this->created_customer_id;
	}
}
