<?php
/**
 * Registered WooPayments dispute acceptance.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\AcceptDispute;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\DisputeWriteTestCase;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;

/** Exercise acceptance through the registered ability and the merchant close route. */
class AcceptDisputeTest extends DisputeWriteTestCase {
	protected const NAME = 'woocommerce-payments/accept-dispute';
	private const ROUTE  = '/wc/v3/payments/disputes/';

	/** @testdox An ID alone closes once through the merchant route, clears the caches and returns the enriched lost dispute. */
	public function test_accept_uses_the_merchant_close_route(): void {
		$logger = RecordingWcLogger::install();
		$order  = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_meta_data( '_charge_id', 'ch_disputed' );
		$order->save();
		$this->prime_dispute_caches();
		$charge = array(
			'id'              => 'ch_disputed',
			'billing_details' => array(
				'address' => array(
					'line1'   => '1 Main Street',
					'city'    => 'Boston',
					'country' => 'US',
				),
			),
		);
		$this->respond_in_turn(
			$this->dispute(
				array(
					'status' => 'lost',
					'charge' => $charge,
				)
			)
		);

		$response = $this->run_request( 'POST' );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_single_close( 'du_test' );
		$data = $response->get_data();
		$this->assertSame( 'du_test', $data['id'] );
		$this->assertSame( 'lost', $data['status'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertStringContainsString( '1 Main Street, Boston', $data['charge']['billing_details']['formatted_address'] );
		foreach ( self::CACHE_KEYS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
		$this->assert_close_audit( $logger, 'completed' );
	}

	/** @testdox Missing and non-string IDs fail schema validation and the callback keeps the extension's backup refusal. */
	public function test_missing_dispute_id_sends_nothing(): void {
		foreach ( array( array(), array( 'dispute_id' => null ), array( 'dispute_id' => 123 ) ) as $input ) {
			$result = $this->sut->execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}
		foreach ( array( null, array(), array( 'dispute_id' => null ), array( 'dispute_id' => 123 ), array( 'dispute_id' => '' ) ) as $input ) {
			$result = AcceptDispute::execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'wcpay_missing_dispute_id', $result->get_error_code() );
			$this->assertSame( 'A dispute_id is required to accept a dispute.', $result->get_error_message() );
		}
		$result = $this->sut->execute( array( 'dispute_id' => '' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_missing_dispute_id', $result->get_error_code() );
		$this->assert_nothing_sent();
	}

	/** @testdox Extra confirmation or submission fields are refused regardless of their value. */
	public function test_schema_refuses_extra_fields(): void {
		foreach ( array( 'confirm', 'submit' ) as $field ) {
			foreach ( array( true, false, 'true', '1', 1 ) as $value ) {
				$result = $this->sut->execute(
					array(
						'dispute_id' => 'du_test',
						$field       => $value,
					)
				);
				$this->assertWPError( $result );
				$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
			}
		}
		$this->assert_nothing_sent();
	}

	/** @testdox Reserved words in any case and IDs containing a non-word character stop before dispatch. */
	public function test_dispute_id_refusals_send_nothing(): void {
		foreach ( array( 'summary', 'SuMmArY', 'download', 'DownLoad', 'status_counts', 'Status_Counts', 'du_1/close', 'du-1', 'du 1', "du_1\n" ) as $id ) {
			$result = $this->sut->execute( array( 'dispute_id' => $id ) );
			$this->assertWPError( $result, $id );
			$this->assertSame( 'wcpay_dispute_close_failed', $result->get_error_code(), $id );
			$this->assertSame( 'Route param validation failed.', $result->get_error_message(), $id );
			$this->assertNull( $result->get_error_data(), $id );
		}
		$this->assert_nothing_sent();
	}

	/**
	 * @testdox Word-only IDs, including reserved words with suffixes, reach their own close route.
	 * @testWith ["du_test"]
	 *           ["dp_legacy"]
	 *           ["download_1"]
	 *           ["status_counts_1"]
	 *
	 * @param string $id Dispute ID.
	 */
	public function test_accepted_dispute_ids_reach_close( string $id ): void {
		$this->respond_in_turn(
			$this->dispute(
				array(
					'id'     => $id,
					'status' => 'lost',
				)
			)
		);
		$result = $this->sut->execute( array( 'dispute_id' => $id ) );
		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assert_single_close( $id );
	}

	/**
	 * @testdox Provider refusals reach the caller with status 500, preserve caches, and log only listed error codes.
	 * @testWith ["resource_missing", "resource_missing"]
	 *           ["unlisted_close_error", "unknown_error"]
	 *
	 * @param string $code     Provider error code.
	 * @param string $log_code Expected safe code.
	 */
	public function test_route_failure_keeps_caches_and_returns_close_error( string $code, string $log_code ): void {
		$logger  = RecordingWcLogger::install();
		$message = 'The provider refused this close.';
		$this->prime_dispute_caches();
		$this->respond_in_turn( $this->error_response( $code, $message, 400 ) );

		$response = $this->run_request( 'POST' );

		$this->assertSame( 500, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wcpay_dispute_close_failed', $data['code'] );
		$this->assertSame( 'Error: ' . $message, $data['message'] );
		$this->assertSame( array( 'route_error_code' => $code ), $data['data'] );
		$this->assert_single_close( 'du_test' );
		foreach ( self::CACHE_KEYS as $key ) {
			$this->assertSame( array( 'stale' => true ), get_option( $key ), $key );
		}
		$this->assert_close_audit(
			$logger,
			'failed',
			array(
				'api_code'    => $log_code,
				'http_status' => 400,
			)
		);
		$this->assertSame(
			array(
				"AbilitiesRegistrar delegation failed [POST /wc/v3/payments/disputes/du_test/close] ($log_code)",
				"Dispute close failed ($log_code).",
			),
			$this->ability_log_messages( $logger )
		);
	}

	/** @testdox A failure escaping dispatch preserves its exception for the caller and only its safe code in the log. */
	public function test_throwable_uses_close_error(): void {
		$logger = RecordingWcLogger::install();
		foreach ( array( new \RuntimeException( 'Private detail' ), new ApiException( 'Private detail', 'resource_missing' ) ) as $failure ) {
			$throw = static function ( $response, $server, $request ) use ( $failure ) {
				if ( self::ROUTE . 'du_test/close' === $request->get_route() ) {
					throw $failure;
				}
				return $response;
			};
			add_filter( 'rest_pre_dispatch', $throw, 10, 3 );
			$result = $this->sut->execute( array( 'dispute_id' => 'du_test' ) );
			remove_filter( 'rest_pre_dispatch', $throw );
			$this->assertWPError( $result );
			$this->assertSame( 'wcpay_dispute_close_failed', $result->get_error_code() );
			$this->assertSame( $failure->getMessage(), $result->get_error_message() );
			$this->assertSame( array( 'exception' => $failure ), $result->get_error_data() );
		}
		$this->assertSame( array( 'Dispute close failed (unknown_error).', 'Dispute close failed (resource_missing).' ), $this->ability_log_messages( $logger ) );
		$this->assertSame( 0, $this->http->request_count );
	}

	/** @testdox Guests and customers cannot close a dispute. */
	public function test_permission_is_required(): void {
		wp_set_current_user( 0 );
		$this->assert_close_forbidden();
		$this->login_as_role( 'customer' );
		$this->assert_close_forbidden();
	}

	/** @testdox Withdrawing opt-in prevents an already discovered ability from closing a dispute. */
	public function test_opt_out_after_discovery(): void {
		add_filter( self::GATE, '__return_false', 20 );
		$this->assert_close_forbidden();
	}

	/** @testdox A merchant cannot use a retained ability after the current site loses eligibility. */
	public function test_site_no_longer_eligible(): void {
		$this->assertTrue( wc_get_container()->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::AVAILABLE ) );
		$this->assert_close_forbidden();
	}

	/** @testdox Neither reading nor deleting the run resource can close the dispute. */
	public function test_rest_run_requires_post(): void {
		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$response = $this->run_request( $method );
			$this->assertSame( 405, $response->get_status(), $method );
			$this->assertSame( 'rest_ability_invalid_method', $response->get_data()['code'] );
		}
		$this->assert_nothing_sent();
	}

	/** @testdox An order lookup failure after the close still returns the lost dispute. */
	public function test_completed_close_survives_order_enrichment_failure(): void {
		$this->respond_in_turn( $this->dispute( array( 'status' => 'lost' ) ) );
		add_filter(
			'woocommerce_order_query_args',
			static function ( $args ) {
				if ( array( 'ch_disputed' ) === ( $args['meta_value'] ?? null ) ) {
					throw new \RuntimeException( 'Order lookup failed.' );
				}
				return $args;
			}
		);
		$result = $this->sut->execute( array( 'dispute_id' => 'du_test' ) );
		$this->assertSame(
			$this->dispute(
				array(
					'status' => 'lost',
					'order'  => null,
				)
			),
			$result
		);
		$this->assert_single_close( 'du_test' );
	}

	/** Prime all three caches before closing. */
	private function prime_dispute_caches(): void {
		foreach ( self::CACHE_KEYS as $key ) {
			update_option( $key, array( 'stale' => true ) );
		}
	}

	/** Assert refusal at the registered permission check. */
	private function assert_close_forbidden(): void {
		$result = $this->sut->execute( array( 'dispute_id' => 'du_test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assert_nothing_sent();
	}

	/**
	 * Check the method, path and arguments at both boundaries.
	 *
	 * @param string $id Expected ID.
	 */
	private function assert_single_close( string $id ): void {
		$this->assertSame( array( array( 'POST', self::ROUTE . $id . '/close', array() ) ), $this->dispatches );
		$this->assertSame( 1, $this->http->request_count );
		$this->assertSame( 'POST', $this->http->last_method );
		$this->assertSame( '/sites/123/wcpay/disputes/' . $id . '/close', $this->http->last_path );
		$this->assertIsString( $this->http->last_body );
		$this->assertSame( array( 'test_mode' => false ), json_decode( $this->http->last_body, true ) );
	}

	/**
	 * Run the registered ability using the method's input location.
	 *
	 * @param string $method HTTP method.
	 */
	private function run_request( string $method ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . self::NAME . '/run' );
		if ( 'POST' === $method ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( '{"input":{"dispute_id":"du_test"}}' );
		} else {
			$request->set_query_params( array( 'input' => array( 'dispute_id' => 'du_test' ) ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Collect every Ability-source message to detect extra lines containing provider text.
	 *
	 * @param RecordingWcLogger $logger Captured log.
	 */
	private function ability_log_messages( RecordingWcLogger $logger ): array {
		return array_column( array_filter( $logger->lines, static fn( $line ) => 'woopayments-abilities' === $line[2] ), 1 );
	}

	/**
	 * Assert the ordered merchant audit events and their context.
	 *
	 * @param RecordingWcLogger $logger Captured log.
	 * @param string            $outcome Close outcome.
	 * @param array             $extra Additional outcome context.
	 */
	private function assert_close_audit( RecordingWcLogger $logger, string $outcome, array $extra = array() ): void {
		$events = array();
		foreach ( $logger->lines as $index => $line ) {
			if ( 0 !== strpos( $line[1], 'WooPayments dispute close ' ) ) {
				continue;
			}
			$context = $logger->contexts[ $index ];
			$this->assertSame( 'close', $context['action'] );
			$this->assertSame( 'du_test', $context['dispute_id'] );
			$this->assertSame( get_current_user_id(), $context['user_id'] );
			if ( $events ) {
				$this->assertSame( $extra, array_intersect_key( $context, $extra ) );
			}
			$events[] = $line[1];
		}
		$this->assertSame( array( 'WooPayments dispute close requested.', "WooPayments dispute close $outcome." ), $events );
	}
}
