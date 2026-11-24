<?php
/**
 * Registered WooPayments dispute evidence updates.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\DisputeIdentifier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\SubmitDisputeEvidence;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;

/** Exercise evidence updates through the dispute route the evidence screen posts to. */
class SubmitDisputeEvidenceTest extends AbilityTestCase {
	private const NAME        = 'woocommerce-payments/submit-dispute-evidence';
	private const DISPUTE_URL = '/sites/123/wcpay/disputes/du_test';
	private const CACHE_KEYS  = array(
		WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY,
		WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE,
		WooPaymentsDatabaseCache::ACTIVE_DISPUTES_KEY,
	);

	/** @var FakeHttpClient Platform transport. */
	private $http;

	/** @var \WP_Ability Registered evidence ability. */
	private $sut;

	/** @var \WP_REST_Server|null Server in place before this test. */
	private $original_server;

	/** @var WooPaymentsDisputesRestController Dispute routes served during this test. */
	private $controller;

	/** Discover the ability on a connected store whose dispute routes use a fake platform transport. */
	public function setUp(): void {
		parent::setUp();
		$this->http = new FakeHttpClient();
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
		$this->install_root();
		add_filter( self::GATE, '__return_true' );

		$this->original_server     = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null;
		$this->controller          = $container->get( WooPaymentsDisputesRestController::class );
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		rest_get_server();

		$ability = wp_get_ability( self::NAME );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->sut = $ability;
	}

	/** Restore the REST server and the original transport. */
	public function tearDown(): void {
		try {
			remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
			$GLOBALS['wp_rest_server'] = $this->original_server;
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox A draft goes through the dispute route: one read, one update, the dispute caches cleared and the order added.
	 */
	public function test_draft_goes_through_the_dispute_route(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_charge_id', 'ch_disputed' );
		$order->save();
		foreach ( self::CACHE_KEYS as $key ) {
			update_option( $key, array( 'stale' => true ) );
		}
		$this->respond_in_turn( $this->dispute(), $this->dispute( array( 'evidence' => array( 'product_description' => 'Draft' ) ) ) );

		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'evidence'   => array( 'product_description' => 'Draft' ),
				'metadata'   => array( 'note' => 'kept' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'du_test', $result['id'] );
		$this->assertSame( $order->get_id(), $result['order']['id'] );
		$this->assertSame( array( 'GET', 'POST' ), array_column( $this->http->requests, 'method' ) );
		$this->assertSame( array( self::DISPUTE_URL . '?test_mode=0', self::DISPUTE_URL ), array_column( $this->http->requests, 'path' ) );
		$body = $this->sent_body();
		$this->assertSame( array( 'product_description' => 'Draft' ), $body['evidence'] );
		$this->assertFalse( $body['submit'] );
		$this->assertSame( array( 'note' => 'kept' ), $body['metadata'] );
		foreach ( self::CACHE_KEYS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
	}

	/**
	 * @testdox Reading submit as a boolean cast or a yes-like string would submit "$_dataName" evidence to the card network.
	 * @testWith [true, true]
	 *           ["true", false]
	 *           ["1", false]
	 *           [1, false]
	 *           ["false", false]
	 *           ["0", false]
	 *
	 * @param mixed $submit   Caller's submit value, which passes the boolean schema.
	 * @param bool  $expected Whether the update submits.
	 */
	public function test_only_boolean_true_submits( $submit, bool $expected ): void {
		$this->respond_in_turn( $this->dispute(), $this->dispute() );

		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'submit'     => $submit,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $expected, $this->sent_body()['submit'] );
	}

	/**
	 * Direct calls apply the extension's metadata limits before any request.
	 *
	 * @dataProvider metadata_refusals
	 *
	 * @param array  $metadata Metadata sent.
	 * @param string $code     Expected refusal.
	 */
	public function test_metadata_refusals_send_nothing( array $metadata, string $code ): void {
		$result = SubmitDisputeEvidence::execute(
			array(
				'dispute_id' => 'du_test',
				'metadata'   => $metadata,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/** Metadata each limit refuses. */
	public static function metadata_refusals(): array {
		return array(
			'eleven keys'      => array( array_fill_keys( range( 'a', 'k' ), 'v' ), 'wcpay_too_many_metadata_keys' ),
			'41-byte key'      => array( array( str_repeat( 'k', 41 ) => 'v' ), 'wcpay_invalid_metadata_key' ),
			'integer key'      => array( array( 1 => 'v' ), 'wcpay_invalid_metadata_key' ),
			'501-byte value'   => array( array( 'k' => str_repeat( 'v', 501 ) ), 'wcpay_invalid_metadata_value' ),
			'non-string value' => array( array( 'k' => 5 ), 'wcpay_invalid_metadata_value' ),
		);
	}

	/**
	 * @testdox The schema refuses eleven metadata keys before execution.
	 */
	public function test_schema_refuses_eleven_metadata_keys(): void {
		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'metadata'   => array_fill_keys( range( 'a', 'k' ), 'v' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox Ten keys, a 40-byte key and a 500-byte value are sent.
	 */
	public function test_metadata_limits_are_inclusive(): void {
		$metadata                          = array_fill_keys( range( 'a', 'h' ), 'v' );
		$metadata[ str_repeat( 'k', 40 ) ] = 'v';
		$metadata['long']                  = str_repeat( 'v', 500 );
		$this->respond_in_turn( $this->dispute(), $this->dispute() );

		$result = SubmitDisputeEvidence::execute(
			array(
				'dispute_id' => 'du_test',
				'metadata'   => $metadata,
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 10, $metadata );
		$this->assertSame( $metadata, $this->sent_body()['metadata'] );
	}

	/**
	 * @testdox A missing, empty or non-string dispute ID is refused before any request.
	 */
	public function test_missing_dispute_id_sends_nothing(): void {
		foreach ( array( array(), array( 'dispute_id' => 123 ), null ) as $input ) {
			$result = SubmitDisputeEvidence::execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'wcpay_missing_dispute_id', $result->get_error_code() );
			$this->assertSame( 'A dispute_id is required to submit evidence.', $result->get_error_message() );
		}
		$result = $this->sut->execute( array( 'dispute_id' => '' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_missing_dispute_id', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A dispute ID the update route does not serve, "$_dataName", is refused before any request.
	 * @testWith ["download"]
	 *           ["DOWNLOAD"]
	 *           ["Download"]
	 *           ["summary"]
	 *           ["du_1/close"]
	 *           ["du-1"]
	 *           ["du 1"]
	 *           ["du_1\n"]
	 *           ["status_counts"]
	 *           ["STATUS_COUNTS"]
	 *
	 * @param string $dispute_id Caller's dispute ID.
	 */
	public function test_dispute_id_refusals_send_nothing( string $dispute_id ): void {
		$this->respond_in_turn( $this->dispute(), $this->dispute() );

		$result = $this->sut->execute( array( 'dispute_id' => $dispute_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_dispute_evidence_failed', $result->get_error_code() );
		$this->assertSame( 'Route param validation failed.', $result->get_error_message() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A $_dataName failure answers the extension's code with the route's message, and no ability log line holds the provider's text.
	 * @testWith ["update", "unknown_error"]
	 *           ["read", "resource_missing"]
	 *           ["exception", "unknown_error"]
	 *
	 * @param string $failing  Which step fails.
	 * @param string $log_code Code the ability's log line holds.
	 */
	public function test_route_failure( string $failing, string $log_code ): void {
		$logger  = RecordingWcLogger::install();
		$message = 'The provider refused evidence for du_test.';
		foreach ( self::CACHE_KEYS as $key ) {
			update_option( $key, array( 'stale' => true ) );
		}
		if ( 'update' === $failing ) {
			$this->respond_in_turn( $this->dispute(), $this->error_response( 'evidence_rejected', $message, 400 ) );
		} elseif ( 'read' === $failing ) {
			$this->respond_in_turn( $this->error_response( 'resource_missing', $message, 404 ) );
		} else {
			add_filter(
				'rest_pre_dispatch',
				static function () use ( $message ) {
					throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test exception.
				}
			);
		}

		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'evidence'   => array( 'uncategorized_text' => 'Private evidence text' ),
				'submit'     => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_dispute_evidence_failed', $result->get_error_code() );
		$this->assertStringContainsString( $message, $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertArrayNotHasKey( 'status', $data );
		if ( 'exception' === $failing ) {
			$this->assertInstanceOf( \RuntimeException::class, $data['exception'] );
			$this->assertSame( 0, $this->http->request_count );
		} else {
			$this->assertSame( 'update' === $failing ? 'evidence_rejected' : 'resource_missing', $data['route_error_code'] );
			$this->assertSame( 'update' === $failing ? array( 'GET', 'POST' ) : array( 'GET' ), array_column( $this->http->requests, 'method' ) );
		}
		foreach ( self::CACHE_KEYS as $key ) {
			$this->assertSame( array( 'stale' => true ), get_option( $key ), $key );
		}

		$own_lines = array();
		foreach ( $logger->lines as $line ) {
			if ( 'woopayments-abilities' !== $line[2] ) {
				continue;
			}
			$this->assertStringNotContainsString( $message, $line[1] );
			$this->assertStringNotContainsString( 'Private evidence text', $line[1] );
			if ( 0 === strpos( $line[1], 'Dispute evidence submission' ) ) {
				$own_lines[] = $line;
			}
		}
		$this->assertSame( array( array( 'error', "Dispute evidence submission failed ($log_code).", 'woopayments-abilities' ) ), $own_lines );
		if ( 'exception' !== $failing ) {
			$this->assertContains( array( 'error', "AbilitiesRegistrar delegation failed [POST /wc/v3/payments/disputes/du_test] ($log_code)", 'woopayments-abilities' ), $logger->lines );
		}
	}

	/**
	 * @testdox An update on a Visa compliance dispute carries the fee acknowledgement, as the extension's client adds it.
	 */
	public function test_noncompliant_dispute_carries_fee_acknowledgement(): void {
		$this->respond_in_turn( $this->dispute( array( 'reason' => 'noncompliant' ) ), $this->dispute() );

		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'evidence'   => array( 'uncategorized_text' => 'Draft' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'uncategorized_text' => 'Draft',
				'enhanced_evidence'  => array( 'visa_compliance' => array( 'fee_acknowledged' => 'true' ) ),
			),
			$this->sent_body()['evidence']
		);
	}

	/**
	 * @testdox A document field takes a file ID and sends it unchanged.
	 */
	public function test_document_field_takes_a_file_id(): void {
		$this->respond_in_turn( $this->dispute(), $this->dispute() );

		$this->assertIsArray(
			$this->sut->execute(
				array(
					'dispute_id' => 'du_test',
					'evidence'   => array( 'receipt' => 'file_test' ),
				)
			)
		);
		$this->assertSame( array( 'receipt' => 'file_test' ), $this->sent_body()['evidence'] );
	}

	/**
	 * @testdox A customer, guest, or manager whose store withdrew opt-in after discovery cannot update evidence.
	 * @testWith ["customer"]
	 *           ["guest"]
	 *           ["opt-out"]
	 *
	 * @param string $caller Caller or store state.
	 */
	public function test_refused_permission( string $caller ): void {
		if ( 'opt-out' === $caller ) {
			add_filter( self::GATE, '__return_false', 20 );
		} elseif ( 'guest' === $caller ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as_role( $caller );
		}

		$result = $this->sut->execute( array( 'dispute_id' => 'du_test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A retained ability cannot update evidence after the current store leaves the connected tier.
	 */
	public function test_execution_rechecks_current_site_tier(): void {
		$this->assertTrue( $this->sut->check_permissions( array( 'dispute_id' => 'du_test' ) ) );
		$this->assertTrue( wc_get_container()->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::AVAILABLE ) );

		$result = $this->sut->execute( array( 'dispute_id' => 'du_test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox The evidence fields keep the extension's order: nine text fields, then nine document fields.
	 */
	public function test_evidence_fields_keep_the_extension_order(): void {
		$inventory = wp_json_file_decode( dirname( __DIR__ ) . '/fixtures/abilities-11.3.0.json', array( 'associative' => true ) );
		$record    = current( array_filter( $inventory, static fn( $entry ) => self::NAME === $entry['name'] ) );

		$this->assertSame(
			array_keys( $record['input_schema']['properties']['evidence']['properties'] ),
			array_keys( $this->sut->get_input_schema()['properties']['evidence']['properties'] )
		);
	}

	/**
	 * @testdox Every fixed route name under the disputes base, taken from the registered routes, is refused as a dispute ID by both dispute abilities.
	 */
	public function test_registered_route_names_are_refused_as_dispute_ids(): void {
		$base  = '/wc/v3/payments/disputes/';
		$names = array();
		foreach ( array_keys( rest_get_server()->get_routes( 'wc/v3' ) ) as $route ) {
			if ( 0 !== strpos( $route, $base ) ) {
				continue;
			}
			$segment = explode( '/', substr( $route, strlen( $base ) ) )[0];
			if ( 1 === preg_match( '/\A\w+\z/', $segment ) ) {
				$names[ $segment ] = true;
			}
		}
		$this->assertNotEmpty( $names );

		foreach ( array_keys( $names ) as $name ) {
			foreach ( array( $name, strtoupper( $name ) ) as $dispute_id ) {
				$this->assertFalse( DisputeIdentifier::is_accepted( $dispute_id ), $dispute_id );
				$submitted = $this->sut->execute( array( 'dispute_id' => $dispute_id ) );
				$this->assertWPError( $submitted, $dispute_id );
				$this->assertSame( 'wcpay_dispute_evidence_failed', $submitted->get_error_code(), $dispute_id );
				$read = wp_get_ability( self::PREFIX . 'get-dispute' )->execute( array( 'dispute_id' => $dispute_id ) );
				$this->assertWPError( $read, $dispute_id );
				$this->assertSame( 'wcpay_invalid_dispute_id', $read->get_error_code(), $dispute_id );
			}
		}
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox The dispute read refuses "$_dataName" with no request.
	 * @testWith ["download"]
	 *           ["Download"]
	 *           ["status_counts"]
	 *           ["Status_Counts"]
	 *
	 * @param string $dispute_id Fixed route name sent as a dispute ID.
	 */
	public function test_dispute_read_refuses_fixed_route_names( string $dispute_id ): void {
		$this->respond_in_turn( $this->dispute() );

		$result = wp_get_ability( self::PREFIX . 'get-dispute' )->execute( array( 'dispute_id' => $dispute_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_invalid_dispute_id', $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox The dispute read still sends one lookup for "$_dataName", an ID it accepted before.
	 * @testWith ["du_test"]
	 *           ["dp_legacy"]
	 *           ["DU_UPPER"]
	 *           ["123"]
	 *           ["_"]
	 *           ["summaryx"]
	 *           ["xdownload"]
	 *           ["status_counts_1"]
	 *
	 * @param string $dispute_id Dispute ID the read accepted before.
	 */
	public function test_dispute_read_unchanged_for_accepted_ids( string $dispute_id ): void {
		$this->respond_in_turn( $this->dispute( array( 'id' => $dispute_id ) ) );

		$result = wp_get_ability( self::PREFIX . 'get-dispute' )->execute( array( 'dispute_id' => $dispute_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $dispute_id, $result['id'] );
		$this->assertSame( array( 'GET' ), array_column( $this->http->requests, 'method' ) );
		$this->assertSame( array( '/sites/123/wcpay/disputes/' . $dispute_id . '?test_mode=0' ), array_column( $this->http->requests, 'path' ) );
	}

	/**
	 * @testdox A submit with object-shaped evidence and metadata sends what the caller gave, not empty evidence.
	 */
	public function test_submit_keeps_object_evidence(): void {
		$this->respond_in_turn( $this->dispute(), $this->dispute( array( 'status' => 'under_review' ) ) );

		$result = $this->sut->execute(
			array(
				'dispute_id' => 'du_test',
				'evidence'   => (object) array(
					'product_description' => 'Real evidence',
					'receipt'             => 'file_test',
				),
				'metadata'   => (object) array( 'note' => 'kept' ),
				'submit'     => true,
			)
		);

		$this->assertIsArray( $result );
		$body = $this->sent_body();
		$this->assertTrue( $body['submit'] );
		$this->assertSame(
			array(
				'product_description' => 'Real evidence',
				'receipt'             => 'file_test',
			),
			$body['evidence']
		);
		$this->assertSame( array( 'note' => 'kept' ), $body['metadata'] );
	}

	/**
	 * @testdox The metadata limits apply to object-shaped metadata: $_dataName.
	 * @dataProvider object_metadata_refusals
	 *
	 * @param \stdClass $metadata Metadata sent as an object.
	 * @param string    $code     Expected refusal.
	 * @param bool      $direct   Whether to call the method directly, past the schema's key count.
	 */
	public function test_object_metadata_limits_apply( \stdClass $metadata, string $code, bool $direct ): void {
		$input  = array(
			'dispute_id' => 'du_test',
			'metadata'   => $metadata,
			'submit'     => true,
		);
		$result = $direct ? SubmitDisputeEvidence::execute( $input ) : $this->sut->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/** Object-shaped metadata each limit refuses. */
	public static function object_metadata_refusals(): array {
		return array(
			'eleven keys'    => array( (object) array_fill_keys( range( 'a', 'k' ), 'v' ), 'wcpay_too_many_metadata_keys', true ),
			'41-byte key'    => array( (object) array( str_repeat( 'k', 41 ) => 'v' ), 'wcpay_invalid_metadata_key', false ),
			'501-byte value' => array( (object) array( 'k' => str_repeat( 'v', 501 ) ), 'wcpay_invalid_metadata_value', false ),
		);
	}

	/**
	 * @testdox Through the REST run endpoint, submit sent as $label saves a draft.
	 * @testWith ["\"false\"", "false"]
	 *           ["\"0\"", "0"]
	 *           ["0", 0]
	 *           ["false", false]
	 *           ["omitted", null]
	 *
	 * @param string $label  Form of the value, for the test name.
	 * @param mixed  $submit Value sent, or null to leave the key out.
	 */
	public function test_rest_draft_forms_stay_drafts( string $label, $submit ): void {
		$this->respond_in_turn( $this->dispute(), $this->dispute() );
		$input = array( 'dispute_id' => 'du_test' );
		if ( null !== $submit ) {
			$input['submit'] = $submit;
		}
		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . self::NAME . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->sent_body()['submit'] );
	}

	/**
	 * Build a platform dispute.
	 *
	 * @param array $fields Fields to override.
	 */
	private function dispute( array $fields = array() ): array {
		return array_merge(
			array(
				'id'     => 'du_test',
				'status' => 'needs_response',
				'reason' => 'fraudulent',
				'charge' => 'ch_disputed',
			),
			$fields
		);
	}

	/**
	 * Build a platform error response.
	 *
	 * @param string $code    Platform error code.
	 * @param string $message Platform error message.
	 * @param int    $status  HTTP status.
	 */
	private function error_response( string $code, string $message, int $status ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
		);
	}

	/**
	 * Queue the transport's JSON responses, one per request.
	 *
	 * @param array ...$bodies Platform bodies, or a status and body from error_response().
	 */
	private function respond_in_turn( array ...$bodies ): void {
		foreach ( $bodies as $body ) {
			$status = 200;
			if ( isset( $body['status'], $body['body'] ) && is_int( $body['status'] ) ) {
				$status = $body['status'];
				$body   = $body['body'];
			}
			$this->http->responses[] = array(
				'response' => array( 'code' => $status ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			);
		}
	}

	/** Decode the last update body sent to the platform. */
	private function sent_body(): array {
		$this->assertSame( 'POST', $this->http->last_method );
		$this->assertSame( self::DISPUTE_URL, $this->http->last_path );
		return json_decode( (string) $this->http->last_body, true );
	}
}
