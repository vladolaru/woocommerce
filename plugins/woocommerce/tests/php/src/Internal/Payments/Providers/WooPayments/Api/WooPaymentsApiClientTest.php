<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsActivatePmPromotionRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountCapitalLinkRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountLoginDataRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetPmPromotionsRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportingBalanceSummaryRequest;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsApiClient class.
 */
class WooPaymentsApiClientTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// The container's API client, which some tests replace to model a connected store.
			$this->reset_container_replacements();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should create account links through the site-scoped user-token endpoint.
	 */
	public function test_create_account_link_posts_forwarded_arguments_with_user_token(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'url'   => 'https://connect.stripe.com/setup/session',
					'state' => 'state_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_account_link(
			array(
				'type'       => 'complete_kyc_link',
				'return_url' => 'https://example.com/return',
			)
		);

		$this->assertSame( 'https://connect.stripe.com/setup/session', $result['url'] );
		$this->assertSame( '/sites/123/wcpay/links', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assertSame(
			array(
				'test_mode'  => false,
				'type'       => 'complete_kyc_link',
				'return_url' => 'https://example.com/return',
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox Should create dashboard login links with the user token and the onboarding test-mode flag.
	 *
	 * Pinned WooPayments 11.1.0: Get_Account_Login_Data (`accounts/login_links`, POST, user token, test mode only when onboarding in test mode).
	 */
	public function test_create_login_link_posts_redirect_url_with_user_token(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'url' => 'https://connect.stripe.com/express/login_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true, false ), $this->transport_log() );

		$result = $sut->create_login_link( home_url( '/overview' ) );

		$this->assertSame( 'https://connect.stripe.com/express/login_test', $result['url'] );
		$this->assertSame( '/sites/123/wcpay/accounts/login_links', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assertSame(
			array(
				'test_mode'    => false,
				'redirect_url' => home_url( '/overview' ),
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox Should fire the plugin's wpcay_get_account_login_data filter once with the login request object.
	 *
	 * Pinned WooPayments 11.1.0: Get_Account_Login_Data::$hook and Request::send()/apply_filters( $hook, $this ), no extra arguments.
	 */
	public function test_create_login_link_fires_legacy_login_data_filter_with_the_request(): void {
		list( $sut ) = $this->make_login_link_sut( true );

		$captured = array();
		$filter   = static function ( ...$args ) use ( &$captured ) {
			$captured[] = $args;

			return $args[0];
		};

		add_filter( 'wpcay_get_account_login_data', $filter, 10, 99 );
		try {
			$sut->create_login_link( home_url( '/overview' ) );
		} finally {
			remove_filter( 'wpcay_get_account_login_data', $filter, 10 );
		}

		$this->assertCount( 1, $captured, 'The filter should fire exactly once per login link.' );
		$this->assertCount( 1, $captured[0], 'The plugin passes only the request object.' );

		$request = $captured[0][0];
		$this->assertInstanceOf( WooPaymentsGetAccountLoginDataRequest::class, $request );
		$this->assertSame( 'accounts/login_links', $request->get_api() );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertTrue( $request->should_use_user_token() );
		$this->assertSame( home_url( '/overview' ), $request->get_param( 'redirect_url' ) );
		$this->assertSame( 'true', $request->get_param( 'test_mode' ) );
	}

	/**
	 * @testdox Should send the login request as changed by the wpcay_get_account_login_data filter.
	 */
	public function test_create_login_link_sends_the_filtered_login_request(): void {
		list( $sut, $http_client ) = $this->make_login_link_sut( false );

		$filter = static function ( $request ) {
			$request->set_redirect_url( home_url( '/filtered-overview' ) );
			$request->set_param( 'extension_param', 'kept' );

			return $request;
		};

		add_filter( 'wpcay_get_account_login_data', $filter );
		try {
			$result = $sut->create_login_link( home_url( '/overview' ) );
		} finally {
			remove_filter( 'wpcay_get_account_login_data', $filter );
		}

		$this->assertSame( 'https://connect.stripe.com/express/login_test', $result['url'] );
		$this->assertSame( '/sites/123/wcpay/accounts/login_links', $http_client->last_path );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assertSame(
			array(
				'test_mode'       => false,
				'redirect_url'    => home_url( '/filtered-overview' ),
				'extension_param' => 'kept',
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox Should refuse a non-request value returned by the wpcay_get_account_login_data filter without sending anything.
	 *
	 * @testWith [null]
	 *           ["https://attacker.example/login"]
	 *           [{"redirect_url": "http://example.org/overview"}]
	 *
	 * @param mixed $filtered Value the filter returns.
	 */
	public function test_create_login_link_rejects_an_invalid_filtered_value( $filtered ): void {
		list( $sut, $http_client ) = $this->make_login_link_sut( false );

		$filter = static function () use ( $filtered ) {
			return $filtered;
		};

		add_filter( 'wpcay_get_account_login_data', $filter );
		try {
			$sut->create_login_link( home_url( '/overview' ) );
			$this->fail( 'An invalid filtered login request must not be sent.' );
		} catch ( WooPaymentsApiException $e ) {
			$this->assertSame( 'wcpay_invalid_filtered_request', $e->get_error_code() );
		} finally {
			remove_filter( 'wpcay_get_account_login_data', $filter );
		}

		$this->assertSame( '', $http_client->last_path, 'Nothing should reach the transport.' );
	}

	/**
	 * @testdox Should refuse a login redirect URL outside the allowed redirect hosts, as the plugin's set_redirect_url() does.
	 *
	 * Pinned WooPayments 11.1.0: Get_Account_Login_Data::set_redirect_url() and Request::validate_redirect_url().
	 */
	public function test_create_login_link_rejects_a_filtered_redirect_url_outside_the_allowed_hosts(): void {
		list( $sut, $http_client ) = $this->make_login_link_sut( false );

		$filter = static function ( $request ) {
			$request->set_redirect_url( 'https://attacker.example/overview' );

			return $request;
		};

		add_filter( 'wpcay_get_account_login_data', $filter );
		try {
			$sut->create_login_link( home_url( '/overview' ) );
			$this->fail( 'A redirect URL outside the allowed hosts must be refused.' );
		} catch ( WooPaymentsApiException $e ) {
			$this->assertSame( 'wcpay_core_invalid_request_parameter_invalid_redirect_url', $e->get_error_code() );
		} finally {
			remove_filter( 'wpcay_get_account_login_data', $filter );
		}

		$this->assertSame( '', $http_client->last_path, 'Nothing should reach the transport.' );
	}

	/**
	 * Build an API client whose fake transport answers a login-link request.
	 *
	 * @param bool $test_mode_onboarding Whether the account is onboarding in test mode.
	 * @return array{0: WooPaymentsApiClient, 1: FakeWooPaymentsHttpClient}
	 */
	private function make_login_link_sut( bool $test_mode_onboarding ): array {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'url' => 'https://connect.stripe.com/express/login_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false, $test_mode_onboarding ), $this->transport_log() );

		return array( $sut, $http_client );
	}

	/**
	 * @testdox Should build the site-scoped WPCOM endpoint and lift idempotency_key into the request headers.
	 *
	 * Pinned WooPayments 11.1.0: Refund_Charge::get_api() and ::DEFAULT_PARAMS.
	 */
	public function test_request_lifts_idempotency_key_and_preserves_filtered_params(): void {
		// Reduced fixture: the platform answers with the Stripe Refund object, payment_intent expanded (wpcom
		// wp-content/rest-api-plugins/endpoints/wcpay/class-refunds-controller.php:114-149, process_refund();
		// Stripe API reference, "The Refund object"); a full recorded body is in Fixtures/rec-5a-refunds.json.
		// Only `id` is kept: this test is about the request. WooPayments 11.1.0 reads currency, id, status and
		// balance_transaction from it (includes/class-wc-payment-gateway-wcpay.php:2978, :3009).
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
		);

		$sut    = new WooPaymentsApiClient();
		$filter = static function ( array $params ): array {
			$params['metadata']['filtered'] = 'yes';
			return $params;
		};

		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_api_request_params', $filter, 10, 3 );

		try {
			$result = $sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} finally {
			remove_filter( 'wcpay_api_request_params', $filter, 10 );
		}

		$this->assertSame( 're_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/refunds', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'application/json; charset=utf-8', $http_client->last_headers['Content-Type'] );
		$this->assertSame( WooPaymentsClientVersion::get_user_agent(), $http_client->last_headers['User-Agent'] );
		$this->assertSame( 'idem_test', $http_client->last_headers['Idempotency-Key'] );
		$this->assertArrayHasKey( 'X-Request-Initiated', $http_client->last_headers );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame(
			array(
				'test_mode' => false,
				'charge'    => 'ch_test',
				'metadata'  => array(
					'refund_source'          => 'native_transport',
					'merchant_refund_reason' => 'requested_by_customer',
					'refund_attempt'         => 'idem_test',
					'filtered'               => 'yes',
				),
				'amount'    => 250,
				'reason'    => 'requested_by_customer',
			),
			$body
		);
	}

	/**
	 * @testdox A $filter_name callback that returns something other than an array is ignored: the request goes out with the values it had.
	 * @testWith ["wcpay_api_request_params"]
	 *           ["wcpay_api_request_headers"]
	 *
	 * AGENTS.md: validate a filter's final value before using it. A null here used to reach http_build_query(),
	 * wp_json_encode() and array_key_exists(), turning every platform call into a fatal.
	 *
	 * @param string $filter_name Request filter.
	 */
	public function test_a_non_array_request_filter_result_is_ignored( string $filter_name ): void {
		// Same reduced Refund object as the test above (wpcom class-refunds-controller.php:114-149; Stripe "The Refund object").
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		$filter = static fn() => null;
		add_filter( $filter_name, $filter );

		try {
			$result = $sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} finally {
			remove_filter( $filter_name, $filter );
		}

		$this->assertSame( 're_test', $result['id'] );
		$this->assertSame( 'idem_test', $http_client->last_headers['Idempotency-Key'] );
		$this->assertSame( WooPaymentsClientVersion::get_user_agent(), $http_client->last_headers['User-Agent'] );
		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertSame( array( 'ch_test', 250 ), array( $body['charge'] ?? null, $body['amount'] ?? null ) );
	}

	/**
	 * @testdox Should send null amount and provider reason defaults for a full free-text refund.
	 *
	 * Pinned WooPayments 11.1.0: Refund_Charge::DEFAULT_PARAMS and Request::get_params().
	 */
	public function test_refund_charge_sends_null_defaults_for_full_free_text_refund(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_full' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		$result = $sut->refund_charge( 'ch_test', null, 'Customer requested a full refund', 'merchant_dashboard', 'idem_full_refund' );

		$this->assertSame( 're_full', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/refunds', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'idem_full_refund', $http_client->last_headers['Idempotency-Key'] );
		$this->assertSame(
			array(
				'test_mode' => false,
				'charge'    => 'ch_test',
				'metadata'  => array(
					'refund_source'          => 'merchant_dashboard',
					'merchant_refund_reason' => 'Customer requested a full refund',
					'refund_attempt'         => 'idem_full_refund',
				),
				'amount'    => null,
				'reason'    => null,
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox A refund request sends its key as the refund_attempt metadata, the exact body recorded on local WPCOM.
	 *
	 * Recorded in `Fixtures/rec-f458-refund-errors.json`, pair `reused_key_identical_params_replays_refund`: the request
	 * carried `metadata.refund_attempt` equal to its Idempotency-Key, and the platform answered the identical resend with
	 * the original refund, marker included, so the charge's refund list can show which refund a call made.
	 */
	public function test_refund_charge_sends_its_key_as_the_refund_attempt_metadata(): void {
		$recorded                  = $this->load_recorded_refund_error_entry( 'reused_key_identical_params_replays_refund' );
		$sent                      = $recorded['request']['body'];
		list( $sut, $http_client ) = $this->make_sut( true, $recorded['response']['body'] );

		$result = $sut->refund_charge( (string) $sent['charge'], (int) $sent['amount'], (string) $sent['metadata']['merchant_refund_reason'], (string) $sent['metadata']['refund_source'], (string) $recorded['request']['idempotency_key'] );

		$this->assertSame( $recorded['response']['body']['id'], $result['id'] );
		$this->assertSame( $recorded['request']['idempotency_key'], $http_client->last_headers['Idempotency-Key'] ?? null );
		$this->assertSame( $sent, json_decode( (string) $http_client->last_body, true ), 'The body must match the recorded request, marker included.' );
	}

	/**
	 * @testdox A wcpay_api_request_params callback cannot change a locked key on a $_dataName request; its other changes reach the body and one warning names the keys.
	 * @dataProvider locked_money_request_data
	 *
	 * The keys are the ones WooPayments 11.1.0 kept from its request filters (includes/core/server/class-request.php:528-569)
	 * plus the values the refund hold and the kept charge key read back.
	 *
	 * @param callable            $send          Sends the request through the client.
	 * @param callable            $callback      wcpay_api_request_params callback.
	 * @param string              $path          API path sent.
	 * @param array<int,string>   $changed_keys  Locked keys the callback changes, in lock order.
	 * @param string|null         $caller_key    Idempotency key the caller passed; null when the client mints one.
	 * @param array<string,mixed> $mutable       Mutable key and the value the callback gave it.
	 * @param array<int,string>   $callback_values Values the callback set, which the warning must not show.
	 */
	public function test_a_request_params_callback_cannot_change_a_locked_key( callable $send, callable $callback, string $path, array $changed_keys, ?string $caller_key, array $mutable, array $callback_values ): void {
		list( $sut, $http_client ) = $this->make_sut( false );
		$send( $sut );
		$logger = RecordingWcLogger::install();
		add_filter( 'wcpay_api_request_params', $callback, 10, 3 );

		$send( $sut );

		$built = json_decode( (string) $http_client->requests[0]['body'], true );
		$sent  = json_decode( (string) $http_client->requests[1]['body'], true );
		$this->assertSame( '/sites/123/wcpay/' . $path, $http_client->requests[1]['path'] );
		foreach ( $changed_keys as $key ) {
			$this->assertSame( self::param_at( $built, $key ), self::param_at( $sent, $key ), "The locked key {$key} must reach the body as the store built it." );
		}
		$this->assertSame( array( true, $mutable['value'] ), self::param_at( $sent, $mutable['key'] ), 'A key the lock does not hold must keep the callback\'s value.' );

		$sent_key = $http_client->requests[1]['headers']['Idempotency-Key'] ?? '';
		if ( null !== $caller_key ) {
			$this->assertSame( $caller_key, $sent_key, 'The caller\'s idempotency key must be sent.' );
		} else {
			$this->assertTrue( wp_is_uuid( $sent_key, 4 ), 'A request without a caller key must send a minted UUID.' );
		}

		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) );
		$this->assertCount( 1, $warnings, 'One warning per request.' );
		$this->assertSame(
			'A callback changed values the store locks on the WooPayments POST ' . $path . ' request (wcpay_api_request_params changed ' . implode( ', ', $changed_keys ) . '). The values the store built were sent instead.',
			$warnings[0][1]
		);
		foreach ( $callback_values as $value ) {
			$this->assertStringNotContainsString( $value, $warnings[0][1], 'The warning names keys, never values.' );
		}
	}

	/**
	 * Locked money requests, each with a callback that changes every locked key it can and one mutable key.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function locked_money_request_data(): array {
		$order_metadata = array(
			'order_id'     => 12,
			'order_number' => '12',
			'order_key'    => 'wc_order_built',
		);

		return array(
			'charge'            => array(
				static function ( WooPaymentsApiClient $sut ) use ( $order_metadata ): void {
					$sut->create_and_confirm_payment_intention(
						array(
							'amount'                     => 1000,
							'currency'                   => 'usd',
							'customer'                   => 'cus_built',
							'payment_method'             => 'pm_built',
							'payment_method_types'       => array( 'card' ),
							'payment_method_update_data' => array( 'billing_details' => array( 'name' => 'Built Name' ) ),
							'return_url'                 => 'https://example.org/built-return',
							'metadata'                   => $order_metadata,
						),
						'idem_charge'
					);
				},
				static function ( array $params ): array {
					$params['amount']                     = 1;
					$params['currency']                   = 'eur';
					$params['customer']                   = 'cus_callback';
					$params['payment_method']             = 'pm_callback';
					$params['confirmation_token']         = 'ctoken_callback';
					$params['payment_method_update_data'] = array( 'billing_details' => array( 'name' => 'Callback Name' ) );
					$params['return_url']                 = 'https://example.org/callback-return';
					$params['metadata']['order_id']       = 99;
					$params['idempotency_key']            = 'idem_callback';
					$params['description']                = 'Callback description';
					unset( $params['metadata']['order_key'] );
					return $params;
				},
				'intentions',
				array( 'amount', 'currency', 'payment_method', 'confirmation_token', 'payment_method_update_data', 'return_url', 'customer', 'metadata.order_id', 'metadata.order_key', 'idempotency_key' ),
				'idem_charge',
				array(
					'key'   => 'description',
					'value' => 'Callback description',
				),
				array( 'cus_callback', 'pm_callback', 'ctoken_callback', 'Callback Name', 'callback-return', 'idem_callback' ),
			),
			'capture'           => array(
				static function ( WooPaymentsApiClient $sut ) use ( $order_metadata ): void {
					$sut->capture_intention( 'pi_built', 500, $order_metadata );
				},
				static function ( array $params ): array {
					$params['amount_to_capture']     = 1;
					$params['metadata']['order_id']  = 99;
					$params['metadata']['order_key'] = 'wc_order_callback';
					$params['idempotency_key']       = 'idem_callback';
					$params['level3']                = array( 'merchant_reference' => 'callback' );
					return $params;
				},
				'intentions/pi_built/capture',
				array( 'amount_to_capture', 'metadata.order_id', 'metadata.order_key', 'idempotency_key' ),
				null,
				array(
					'key'   => 'level3',
					'value' => array( 'merchant_reference' => 'callback' ),
				),
				array( 'wc_order_callback', 'idem_callback' ),
			),
			'refund'            => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->refund_charge( 'ch_built', 250, 'requested_by_customer', 'merchant_dashboard', 'idem_refund' );
				},
				static function ( array $params ): array {
					$params['charge']          = 'ch_callback';
					$params['amount']          = 999;
					$params['metadata']        = array( 'replaced' => 'yes' );
					$params['idempotency_key'] = 'idem_callback';
					return $params;
				},
				'refunds',
				array( 'charge', 'amount', 'metadata.refund_attempt', 'idempotency_key' ),
				'idem_refund',
				array(
					'key'   => 'metadata.replaced',
					'value' => 'yes',
				),
				array( 'ch_callback', '999', 'idem_callback' ),
			),
			'keyless refund'    => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->refund_charge( 'ch_built', 250, 'requested_by_customer', 'transaction_details_no_order', '' );
				},
				static function ( array $params ): array {
					$params['metadata']['refund_attempt'] = 'attempt_callback';
					$params['idempotency_key']            = 'idem_callback';
					$params['reason']                     = 'duplicate';
					return $params;
				},
				'refunds',
				array( 'metadata.refund_attempt', 'idempotency_key' ),
				null,
				array(
					'key'   => 'reason',
					'value' => 'duplicate',
				),
				array( 'attempt_callback', 'idem_callback' ),
			),
			'confirmed setup'   => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->create_and_confirm_setup_intention(
						array(
							'customer'             => 'cus_built',
							'payment_method'       => 'pm_built',
							'payment_method_types' => array( 'card' ),
						),
						'idem_setup'
					);
				},
				static function ( array $params ): array {
					$params['customer']       = 'cus_callback';
					$params['confirm']        = 'false';
					$params['payment_method'] = 'pm_callback';
					unset( $params['idempotency_key'] );
					return $params;
				},
				'setup_intents',
				array( 'customer', 'confirm', 'idempotency_key' ),
				'idem_setup',
				array(
					'key'   => 'payment_method',
					'value' => 'pm_callback',
				),
				array( 'cus_callback' ),
			),
			'unconfirmed setup' => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->create_setup_intention(
						array(
							'customer'             => 'cus_built',
							'payment_method_types' => array( 'card' ),
						)
					);
				},
				static function ( array $params ): array {
					$params['confirm']         = 'true';
					$params['idempotency_key'] = 'idem_callback';
					$params['description']     = 'Callback description';
					return $params;
				},
				'setup_intents',
				array( 'confirm', 'idempotency_key' ),
				null,
				array(
					'key'   => 'description',
					'value' => 'Callback description',
				),
				array( 'idem_callback' ),
			),
			// Kills a restore that sets a nested key without first rebuilding a scalar parent as an array (it throws).
			'scalar metadata'   => array(
				static function ( WooPaymentsApiClient $sut ) use ( $order_metadata ): void {
					$sut->create_and_confirm_payment_intention(
						array(
							'amount'               => 1000,
							'currency'             => 'usd',
							'customer'             => 'cus_built',
							'payment_method'       => 'pm_built',
							'payment_method_types' => array( 'card' ),
							'metadata'             => $order_metadata,
						),
						'idem_charge'
					);
				},
				static function ( array $params ): array {
					$params['metadata']    = 'replaced';
					$params['description'] = 'Callback description';
					return $params;
				},
				'intentions',
				array( 'metadata.order_id', 'metadata.order_key' ),
				'idem_charge',
				array(
					'key'   => 'description',
					'value' => 'Callback description',
				),
				array( 'replaced' ),
			),
			// Kills a loose comparison, which would take '1000' for the built 1000 and send the string.
			'type-only amount'  => array(
				static function ( WooPaymentsApiClient $sut ) use ( $order_metadata ): void {
					$sut->create_and_confirm_payment_intention(
						array(
							'amount'               => 1000,
							'currency'             => 'usd',
							'customer'             => 'cus_built',
							'payment_method'       => 'pm_built',
							'payment_method_types' => array( 'card' ),
							'metadata'             => $order_metadata,
						),
						'idem_charge'
					);
				},
				static function ( array $params ): array {
					$params['amount']      = '1000';
					$params['description'] = 'Callback description';
					return $params;
				},
				'intentions',
				array( 'amount' ),
				'idem_charge',
				array(
					'key'   => 'description',
					'value' => 'Callback description',
				),
				array(),
			),
			// Kills a restore that skips a built null, which would let a full refund go out for the callback's amount.
			'full refund'       => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->refund_charge( 'ch_built', null, 'requested_by_customer', 'merchant_dashboard', 'idem_refund' );
				},
				static function ( array $params ): array {
					$params['amount'] = 500;
					$params['reason'] = 'duplicate';
					return $params;
				},
				'refunds',
				array( 'amount' ),
				'idem_refund',
				array(
					'key'   => 'reason',
					'value' => 'duplicate',
				),
				array( '500' ),
			),
		);
	}

	/**
	 * @testdox A wcpay_api_request_headers callback cannot change the Idempotency-Key the store set on a $_dataName request, nor add another-case copy.
	 * @dataProvider idempotency_header_request_data
	 *
	 * @param callable $send            Sends the request through the client.
	 * @param string   $path            API path sent.
	 * @param bool     $replace_header  Whether the callback replaces the header, besides adding a lowercase copy.
	 */
	public function test_a_request_headers_callback_cannot_change_the_idempotency_key( callable $send, string $path, bool $replace_header ): void {
		list( $sut, $http_client ) = $this->make_sut( false );
		$logger                    = RecordingWcLogger::install();
		$built_key                 = null;
		$callback                  = static function ( array $headers ) use ( &$built_key, $replace_header ): array {
			$built_key = $headers['Idempotency-Key'] ?? null;
			if ( $replace_header ) {
				$headers['Idempotency-Key'] = 'idem_callback';
			}
			$headers['idempotency-key'] = 'idem_callback_lowercase';
			return $headers;
		};
		add_filter( 'wcpay_api_request_headers', $callback );

		$send( $sut );

		$headers = $http_client->last_headers;
		$this->assertIsString( $built_key, 'The store sets an idempotency key on every POST.' );
		$this->assertSame( $built_key, $headers['Idempotency-Key'] ?? null, 'The key the store set must be sent.' );
		$copies = array_filter( array_keys( $headers ), static fn( $name ): bool => 0 === strcasecmp( (string) $name, 'Idempotency-Key' ) );
		$this->assertSame( array( 'Idempotency-Key' ), array_values( $copies ), 'Header names are case-insensitive, so another-case copy must not be sent.' );

		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) );
		$this->assertCount( 1, $warnings, 'One warning per request.' );
		$this->assertSame( 'A callback changed values the store locks on the WooPayments POST ' . $path . ' request (wcpay_api_request_headers changed Idempotency-Key). The values the store built were sent instead.', $warnings[0][1] );
	}

	/**
	 * POST requests whose Idempotency-Key the store sets: a caller key, a minted key on a money request, and a minted key elsewhere.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function idempotency_header_request_data(): array {
		$refund = static function ( WooPaymentsApiClient $sut ): void {
			$sut->refund_charge( 'ch_built', 250, 'requested_by_customer', 'merchant_dashboard', 'idem_refund' );
		};

		return array(
			'refund with a caller key'          => array( $refund, 'refunds', true ),
			'capture with a minted key'         => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->capture_intention( 'pi_built', 500 );
				},
				'intentions/pi_built/capture',
				true,
			),
			'customer update'                   => array(
				static function ( WooPaymentsApiClient $sut ): void {
					$sut->update_customer( 'cus_built', array( 'description' => 'Built' ) );
				},
				'customers/cus_built',
				true,
			),
			'refund with only a lowercase copy' => array( $refund, 'refunds', false ),
		);
	}

	/**
	 * @testdox A wcpay_api_request_params callback that limits a charge to cards still reaches the body, with no warning.
	 *
	 * The callback is the one Megurio Subscriptions for WooCommerce 1.1.0 (wordpress.org) hooks at priority 20:
	 * includes/class-megurio-subscriptions-for-woocommerce.php:159, callback :2061-2070.
	 */
	public function test_a_payment_method_types_callback_still_reaches_the_charge_body(): void {
		list( $sut, $http_client ) = $this->make_sut( false );
		$logger                    = RecordingWcLogger::install();
		$callback                  = static function ( $params ) {
			$params['payment_method_types'] = array( 'card' );
			unset( $params['automatic_payment_methods'] );
			return $params;
		};
		add_filter( 'wcpay_api_request_params', $callback, 20, 3 );

		$sut->create_and_confirm_payment_intention(
			array(
				'amount'                    => 1000,
				'currency'                  => 'usd',
				'customer'                  => 'cus_built',
				'payment_method'            => 'pm_built',
				'payment_method_types'      => array( 'card', 'link' ),
				// Stripe's PaymentIntent create parameter (API reference, "Create a PaymentIntent": automatic_payment_methods.enabled), which the callback unsets.
				'automatic_payment_methods' => array( 'enabled' => true ),
				'metadata'                  => array( 'order_id' => 12 ),
			),
			'idem_charge'
		);

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertSame( array( 'card' ), $body['payment_method_types'] ?? null );
		$this->assertArrayNotHasKey( 'automatic_payment_methods', $body, 'The key the callback unset must stay unset.' );
		$this->assertSame( array(), array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ), 'A mutable key changes nothing the lock holds.' );
	}

	/**
	 * @testdox A log handler that throws on the lock warning does not stop the request: it goes out once, with the values the store built.
	 *
	 * Kills a transport log that writes the always-on warning outside its try/catch.
	 */
	public function test_a_throwing_warning_handler_does_not_stop_a_locked_request(): void {
		$logger = new class() extends RecordingWcLogger {
			/**
			 * Throw on a warning, record every other line.
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 * @throws \RuntimeException On a warning.
			 */
			public function log( $level, $message, $context = array() ) {
				if ( 'warning' === $level ) {
					throw new \RuntimeException( 'Log handler failed.' );
				}

				parent::log( $level, $message, $context );
			}
		};
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);
		list( $sut, $http_client ) = $this->make_sut( false );
		$callback                  = static function ( array $params ): array {
			$params['amount'] = 999;
			return $params;
		};
		add_filter( 'wcpay_api_request_params', $callback, 10, 3 );

		$sut->refund_charge( 'ch_built', 250, 'requested_by_customer', 'merchant_dashboard', 'idem_refund' );

		$this->assertCount( 1, $http_client->requests, 'The refund must still be sent.' );
		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertSame( 250, $body['amount'] ?? null, 'The amount the store built must be sent.' );
	}

	/**
	 * @testdox A wcpay_api_request_params callback still changes a lookup GET on a locked path, with no warning.
	 *
	 * The ruling leaves the lookup GETs open, as on the client. Kills a lock that drops its POST method check.
	 */
	public function test_a_lookup_get_on_a_locked_path_keeps_a_callbacks_params(): void {
		list( $sut, $http_client ) = $this->make_sut( false );
		$logger                    = RecordingWcLogger::install();
		$callback                  = static function ( array $params ): array {
			$params['charge'] = 'ch_callback';
			return $params;
		};
		add_filter( 'wcpay_api_request_params', $callback, 10, 3 );

		$sut->list_charge_refunds( 'ch_built' );

		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertStringStartsWith( '/sites/123/wcpay/refunds?', $http_client->last_path );
		$this->assertStringContainsString( 'charge=ch_callback', $http_client->last_path );
		$this->assertSame( array(), array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ), 'A lookup GET is not locked.' );
	}

	/**
	 * Read a request param, a dot naming a key one level down.
	 *
	 * @param array<int|string,mixed> $params Request params.
	 * @param string                  $key    Param name.
	 * @return array{0:bool,1:mixed} Whether the param is present, and its value.
	 */
	private static function param_at( array $params, string $key ): array {
		$path   = explode( '.', $key, 2 );
		$parent = $path[0];
		if ( ! array_key_exists( $parent, $params ) ) {
			return array( false, null );
		}
		if ( ! isset( $path[1] ) ) {
			return array( true, $params[ $parent ] );
		}

		return is_array( $params[ $parent ] ) && array_key_exists( $path[1], $params[ $parent ] ) ? array( true, $params[ $parent ][ $path[1] ] ) : array( false, null );
	}

	/**
	 * Load one recorded F458 (b) or (c) refund request entry by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{request:array{idempotency_key:string,body:array<string,mixed>},response:array{body:array<string,mixed>}}
	 */
	private function load_recorded_refund_error_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/Fixtures/rec-f458-refund-errors.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry;
			}
		}

		$this->fail( "REC F458 (b)/(c) fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Should truncate the merchant refund reason to the platform's 500-character metadata limit.
	 *
	 * Pinned WooPayments 11.1.0: Refund_Charge::set_full_reason().
	 */
	public function test_refund_charge_truncates_merchant_reason_to_metadata_limit(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$long_reason = str_repeat( 'r', 620 );
		$sut->refund_charge( 'ch_test', 250, $long_reason, 'native_transport', 'idem_test' );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertNull( $body['reason'], 'A free-text reason is not one of the provider reason enums.' );
		$this->assertSame( str_repeat( 'r', 500 ), $body['metadata']['merchant_refund_reason'], 'The platform rejects metadata values over 500 characters; the tail must be dropped, not the refund.' );
	}

	/**
	 * @testdox Should send the full capture body and a transport idempotency key.
	 *
	 * Pinned WooPayments 11.1.0: Capture_Intention::get_api() and ::set_metadata().
	 */
	public function test_capture_intention_sends_amount_metadata_and_level3(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_test',
					'status' => 'succeeded',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->capture_intention(
			'pi_test',
			975,
			array( 'order_id' => '123' ),
			array(
				'merchant_reference' => 'order_123',
				'line_items'         => array(
					array(
						'product_code'    => 'sku_123',
						'quantity'        => 1,
						'unit_cost'       => 975,
						'tax_amount'      => 0,
						'discount_amount' => 0,
					),
				),
			)
		);

		$this->assertSame( 'pi_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/intentions/pi_test/capture', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertNotEmpty( $http_client->last_headers['Idempotency-Key'] ?? '' );
		$this->assertSame(
			array(
				'test_mode'         => false,
				'amount_to_capture' => 975,
				'metadata'          => array( 'order_id' => '123' ),
				'level3'            => array(
					'merchant_reference' => 'order_123',
					'line_items'         => array(
						array(
							'product_code'    => 'sku_123',
							'quantity'        => 1,
							'unit_cost'       => 975,
							'tax_amount'      => 0,
							'discount_amount' => 0,
						),
					),
				),
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox Should keep explicitly empty capture metadata and default Level 3 on the wire.
	 *
	 * Pinned WooPayments 11.1.0: Capture_Intention::DEFAULT_PARAMS and ::set_metadata().
	 */
	public function test_capture_intention_sends_explicit_empty_metadata_and_default_level3(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'pi_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		$sut->capture_intention( 'pi_test', 975, array(), array() );

		$this->assertSame(
			array(
				'test_mode'         => false,
				'amount_to_capture' => 975,
				'metadata'          => array(),
				'level3'            => array(),
			),
			json_decode( (string) $http_client->last_body, true )
		);
	}

	/**
	 * @testdox Should send only the transport mode when canceling an intention.
	 *
	 * Pinned WooPayments 11.1.0: Cancel_Intention::get_api() and ::get_method().
	 */
	public function test_cancel_intention_sends_only_transport_mode(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_test',
					'status' => 'canceled',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->cancel_intention( 'pi_test' );

		$this->assertSame( 'pi_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/intentions/pi_test/cancel', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertNotEmpty( $http_client->last_headers['Idempotency-Key'] ?? '' );
		$this->assertSame( array( 'test_mode' => false ), json_decode( (string) $http_client->last_body, true ) );
	}

	/**
	 * @testdox Should generate reference transport headers for non-GET requests without caller idempotency keys.
	 */
	public function test_post_request_generates_transport_headers_without_caller_idempotency_key(): void {
		// Reduced fixture: the platform proxies Stripe POST /v1/customers and returns the Stripe Customer object
		// (wpcom wp-content/rest-api-plugins/endpoints/wcpay/class-customers-controller.php:200-223,
		// create_customer(); Stripe API reference, "The Customer object"). Only `id` is kept, the one field
		// WooPayments 11.1.0 reads (includes/wc-payment-api/class-wc-payments-api-client.php:1376-1384).
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'cus_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_customer(
			array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.com',
			)
		);

		$this->assertSame( 'cus_test', $result );
		$this->assertSame( '/sites/123/wcpay/customers', $http_client->last_path );
		$this->assertStringStartsWith( '/sites/123/wcpay/', $http_client->last_path );
		$this->assertStringNotContainsString( '/transact/', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'application/json; charset=utf-8', $http_client->last_headers['Content-Type'] );
		$this->assertSame( WooPaymentsClientVersion::get_user_agent(), $http_client->last_headers['User-Agent'] );
		$this->assertNotEmpty( $http_client->last_headers['Idempotency-Key'] ?? '' );
		$this->assertNotEmpty( $http_client->last_headers['X-Request-Initiated'] ?? '' );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertArrayNotHasKey( 'idempotency_key', $body );
	}

	/**
	 * @testdox Should not generate idempotency headers for GET requests.
	 */
	public function test_get_request_does_not_generate_idempotency_header(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'pm_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$filter = static function ( array $params ): array {
			$params['idempotency_key'] = 'ignored_for_get';
			return $params;
		};

		add_filter( 'wcpay_api_request_params', $filter, 10, 3 );

		try {
			$result = $sut->get_payment_method( 'pm_test' );
		} finally {
			remove_filter( 'wcpay_api_request_params', $filter, 10 );
		}

		$this->assertSame( 'pm_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/payment_methods/pm_test?test_mode=0', $http_client->last_path );
		$this->assertStringNotContainsString( '/transact/', $http_client->last_path );
		$this->assertStringNotContainsString( 'idempotency_key', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $http_client->last_headers );
		$this->assertArrayHasKey( 'X-Request-Initiated', $http_client->last_headers );
	}

	/**
	 * @testdox Should retry idempotent write requests when the native transport has no HTTP response.
	 */
	public function test_post_request_retries_transport_failure_with_idempotency_key(): void {
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => 'cus_retry' ) ),
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_customer(
			array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.com',
			)
		);

		$this->assertSame( 'cus_retry', $result );
		$this->assertSame( 2, $http_client->request_count );
		$this->assertNotEmpty( $http_client->requests[0]['headers']['Idempotency-Key'] ?? '' );
		$this->assertSame( $http_client->requests[0]['headers']['Idempotency-Key'], $http_client->requests[1]['headers']['Idempotency-Key'] );
		$this->assertNotEmpty( $http_client->requests[0]['headers']['X-Request-Initiated'] ?? '' );
		$this->assertNotEmpty( $http_client->requests[1]['headers']['X-Request-Initiated'] ?? '' );
	}

	/**
	 * @testdox Should stop transient transport retries after the reference retry budget.
	 */
	public function test_post_request_stops_transient_transport_retries_after_retry_limit(): void {
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => 'cus_after_limit' ) ),
			),
		);

		$sut = new class() extends WooPaymentsApiClient {
			/**
			 * Recorded retry backoffs.
			 *
			 * @var array<int, int>
			 */
			public array $retry_backoffs = array();

			/**
			 * Record retry backoffs without sleeping in the unit test.
			 *
			 * @param int $backoff_microseconds Base retry backoff in microseconds.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				$this->retry_backoffs[] = $backoff_microseconds;
			}
		};
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->create_customer(
				array(
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				)
			);
			$this->fail( 'Expected the transient transport error to surface after retry exhaustion.' );
		} catch ( WooPaymentsApiException $exception ) {
			// Client 11.1.0 class-wc-payments-http.php:118-124 wraps every transport WP_Error in a Connection_Exception.
			$this->assertSame( 'wcpay_http_request_failed', $exception->get_error_code() );
			$this->assertSame( 500, $exception->get_http_code() );
			$this->assertSame( 'Http request failed. Reason: Could not connect to WPCOM.', $exception->getMessage() );
			$this->assertTrue( $exception->has_ambiguous_outcome(), 'A transport failure keeps its ambiguous charge outcome.' );
		}

		$this->assertSame( 4, $http_client->request_count, 'The retry budget is three retries after the initial attempt.' );
		$this->assertSame( $http_client->requests[0]['headers']['Idempotency-Key'], $http_client->requests[3]['headers']['Idempotency-Key'] );
		$this->assertSame( array( 250000, 500000, 1000000 ), $sut->retry_backoffs );
	}

	/**
	 * @testdox Should not retry deterministic local transport readiness failures.
	 *
	 * Source: client 11.1.0 includes/wc-payment-api/class-wc-payments-http.php:59-65 (wcpay_wpcom_not_connected, HTTP 409).
	 */
	public function test_post_request_does_not_retry_local_transport_readiness_failure(): void {
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			new WP_Error( 'wcpay_wpcom_not_connected', 'Site is not connected to WordPress.com.' ),
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => 'cus_should_not_retry' ) ),
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->create_customer(
				array(
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				)
			);
			$this->fail( 'Expected the local transport readiness failure to surface.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_wpcom_not_connected', $exception->get_error_code() );
			$this->assertSame( 409, $exception->get_http_code() );
			$this->assertSame( 'Site is not connected to WordPress.com.', $exception->getMessage() );
			$this->assertFalse( $exception->has_ambiguous_outcome(), 'A local readiness failure never reached the platform.' );
		}

		$this->assertSame( 1, $http_client->request_count );
		$this->assertNotEmpty( $http_client->requests[0]['headers']['Idempotency-Key'] ?? '' );
	}

	/**
	 * @testdox Should not retry GET requests because they do not carry idempotency headers.
	 */
	public function test_get_request_does_not_retry_transport_failure_without_idempotency_key(): void {
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			new WP_Error( 'http_request_failed', 'Could not connect to WPCOM.' ),
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => 'pm_test' ) ),
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->get_payment_method( 'pm_test' );
			$this->fail( 'Expected the native transport request to surface a WooPaymentsApiException.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_http_request_failed', $exception->get_error_code() );
		}

		$this->assertSame( 1, $http_client->request_count );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $http_client->requests[0]['headers'] );
	}

	/**
	 * @testdox Should source test mode from the Core-owned account service.
	 */
	public function test_request_sources_test_mode_from_account_service(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'cus_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->create_customer(
			array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.com',
			)
		);

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should allow historical intent reads to select an explicit account mode.
	 */
	public function test_get_payment_intention_for_mode_overrides_current_account_mode(): void {
		list( $sut, $http_client ) = $this->make_sut( false, array( 'id' => 'pi_history' ) );

		$sut->get_payment_intention_for_mode( 'pi_history', true );

		$this->assertSame( '/sites/123/wcpay/intentions/pi_history?test_mode=1', $http_client->last_path );
	}

	/**
	 * @testdox Should preserve structured card error details from failed native transport requests.
	 */
	public function test_request_preserves_structured_card_error_details(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 402 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'type'         => 'card_error',
						'code'         => 'card_declined',
						'decline_code' => 'insufficient_funds',
						'message'      => 'Card declined for request req_private.',
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
			$this->fail( 'Expected the native transport request to surface a WooPaymentsApiException.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'card_declined', $exception->get_error_code() );
			$this->assertSame( 'card_error', $exception->get_error_type() );
			$this->assertSame( 'insufficient_funds', $exception->get_decline_code() );
			$this->assertSame( 'Error: Card declined for request req_private.', $exception->getMessage() );
			$this->assertSame( 402, $exception->get_http_code() );
		}
	}

	/**
	 * @testdox Should ignore malformed structured error metadata without warnings or diagnostic loss.
	 */
	public function test_request_ignores_malformed_structured_error_metadata(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 402 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'type'         => array( 'card_error' ),
						'code'         => 'card_declined',
						'decline_code' => array( 'insufficient_funds' ),
						'message'      => 'Malformed metadata for request req_private.',
					),
				)
			),
		);
		$warnings              = array();
		$error_handler         = static function ( int $error_level, string $error_message ) use ( &$warnings ): bool {
			if ( E_WARNING !== $error_level ) {
				return false;
			}

			$warnings[] = $error_message;

			return true;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test instrumentation verifies malformed metadata emits no warnings.
		set_error_handler( $error_handler );

		try {
			try {
				$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
				$this->fail( 'Expected malformed provider metadata to retain the API failure.' );
			} catch ( WooPaymentsApiException $exception ) {
				$this->assertSame( 'card_declined', $exception->get_error_code() );
				$this->assertSame( '', $exception->get_error_type() );
				$this->assertSame( '', $exception->get_decline_code() );
				$this->assertSame( 'Error: Malformed metadata for request req_private.', $exception->getMessage() );
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
	}

	/**
	 * Trigger a provider API error through the fake transport and capture the thrown exception.
	 *
	 * @param array<string,mixed> $response_body Decoded provider error body.
	 * @param int                 $response_code HTTP status code.
	 * @return WooPaymentsApiException
	 */
	private function capture_api_error( array $response_body, int $response_code = 400 ): WooPaymentsApiException {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => $response_code ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response_body ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception;
		}

		$this->fail( 'Expected the provider error response to surface a WooPaymentsApiException.' );
	}

	/**
	 * @testdox Should preserve the platform data payload on API errors so amount_too_small keeps its minimum.
	 */
	public function test_api_error_preserves_top_level_amount_too_small_data(): void {
		$exception = $this->capture_api_error(
			array(
				'code'    => 'amount_too_small',
				'message' => 'Amount must be at least $0.50 usd',
				'data'    => array(
					'minimum_amount' => 50,
					'currency'       => 'usd',
				),
			)
		);

		$this->assertSame( 'amount_too_small', $exception->get_error_code() );
		$this->assertSame( 'Amount must be at least $0.50 usd', $exception->getMessage(), 'The plugin throws the platform message unwrapped for amount_too_small.' );
		$this->assertSame(
			array(
				'minimum_amount' => 50,
				'currency'       => 'usd',
			),
			$exception->get_error_data()
		);
		$this->assertSame( 400, $exception->get_http_code() );
	}

	/**
	 * @testdox Should capture the error-object param so account-field rejections keep their attribution.
	 */
	public function test_api_error_captures_error_object_param(): void {
		$exception = $this->capture_api_error(
			array(
				'error' => array(
					'code'    => 'invalid_request_error',
					'message' => 'Invalid statement descriptor.',
					'param'   => 'statement_descriptor',
				),
			)
		);

		$this->assertSame( 'invalid_request_error', $exception->get_error_code() );
		$this->assertSame( 'statement_descriptor', $exception->get_error_data()['param'] );
	}

	/**
	 * @testdox Should preserve the failed payment intent id and the card_declined seller message from the error envelope.
	 */
	public function test_api_error_preserves_intent_id_and_seller_message(): void {
		$exception = $this->capture_api_error(
			array(
				'error' => array(
					'code'           => 'card_declined',
					'message'        => 'Your card was declined.',
					'type'           => 'card_error',
					'decline_code'   => 'do_not_honor',
					'payment_intent' => array(
						'id'      => 'pi_failed_test',
						'status'  => 'requires_payment_method',
						'charges' => array(
							'data' => array(
								array(
									'outcome' => array(
										'seller_message' => 'The bank did not return any further details with this decline.',
									),
								),
							),
						),
					),
				),
			),
			402
		);

		$this->assertSame( 'pi_failed_test', $exception->get_payment_intent_id() );
		$this->assertSame( 'The bank did not return any further details with this decline.', $exception->get_merchant_message() );
		$this->assertSame( 'card_declined', $exception->get_error_code() );
		$this->assertSame( 'do_not_honor', $exception->get_decline_code() );
	}

	/**
	 * @testdox Should keep the seller message for card_declined only, matching the plugin extraction guard.
	 */
	public function test_api_error_ignores_seller_message_for_other_decline_codes(): void {
		$exception = $this->capture_api_error(
			array(
				'error' => array(
					'code'           => 'expired_card',
					'message'        => 'Your card has expired.',
					'type'           => 'card_error',
					'payment_intent' => array(
						'id'      => 'pi_failed_test',
						'charges' => array(
							'data' => array(
								array(
									'outcome' => array(
										'seller_message' => 'The card has expired.',
									),
								),
							),
						),
					),
				),
			),
			402
		);

		$this->assertSame( '', $exception->get_merchant_message() );
		$this->assertSame( 'pi_failed_test', $exception->get_payment_intent_id() );
	}

	/**
	 * @testdox Should preserve top-level data alongside an error envelope, so fraud ruleset results survive.
	 */
	public function test_api_error_preserves_fraud_ruleset_results_data(): void {
		$ruleset_results = array( 'international_ip_address' => 'block' );
		$exception       = $this->capture_api_error(
			array(
				'error' => array(
					'code'    => 'wcpay_blocked_by_fraud_rule',
					'message' => 'Transaction blocked by fraud rules.',
				),
				'data'  => array( 'ruleset_results' => $ruleset_results ),
			)
		);

		$this->assertSame( 'wcpay_blocked_by_fraud_rule', $exception->get_error_code() );
		$this->assertSame( array( 'ruleset_results' => $ruleset_results ), $exception->get_error_data() );
	}

	/**
	 * @testdox Should rewrite the amount_too_large capture error so the merchant is not told to contact support.
	 */
	public function test_api_error_rewrites_amount_too_large_for_uncaptured_intents(): void {
		$exception = $this->capture_api_error(
			array(
				'error' => array(
					'code'           => 'amount_too_large',
					'message'        => 'Amount must be no more than $999,999.99 usd. If you need to process larger amounts, contact support.',
					'type'           => 'invalid_request_error',
					'payment_intent' => array(
						'id'     => 'pi_auth_test',
						'status' => 'requires_capture',
					),
				),
			)
		);

		$this->assertSame( 'amount_too_large', $exception->get_error_code() );
		$this->assertSame( 'Error: The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.', $exception->getMessage() );
	}

	/**
	 * @testdox Should pass the amount_too_large message through untouched when the intent is not awaiting capture.
	 */
	public function test_api_error_keeps_raw_amount_too_large_message_without_requires_capture(): void {
		$exception = $this->capture_api_error(
			array(
				'error' => array(
					'code'    => 'amount_too_large',
					'message' => 'Amount must be no more than $999,999.99 usd.',
					'type'    => 'invalid_request_error',
				),
			)
		);

		$this->assertSame( 'Error: Amount must be no more than $999,999.99 usd.', $exception->getMessage() );
	}

	/**
	 * @testdox Should fall back to message_code and then the error type when the envelope carries no code.
	 */
	public function test_api_error_code_falls_back_to_message_code_then_type(): void {
		$message_code_exception = $this->capture_api_error(
			array(
				'error' => array(
					'message_code' => 'wcpay_platform_message_code',
					'message'      => 'Platform-coded failure.',
				),
			)
		);
		$this->assertSame( 'wcpay_platform_message_code', $message_code_exception->get_error_code() );

		$type_exception = $this->capture_api_error(
			array(
				'error' => array(
					'type'    => 'invalid_request_error',
					'message' => 'Typed failure without a code.',
				),
			)
		);
		$this->assertSame( 'invalid_request_error', $type_exception->get_error_code() );
	}

	/**
	 * Install a recording logger as the WooCommerce logger.
	 *
	 * @return object Recording logger with a public $entries array.
	 */
	private function install_recording_logger(): object {
		$logger = new class() implements \WC_Logger_Interface {
			/**
			 * Logged entries.
			 *
			 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
			 */
			public array $entries = array();

			/**
			 * Add a log entry.
			 *
			 * @param string $handle  File handle.
			 * @param string $message Log message.
			 * @param string $level   Log level.
			 * @return bool
			 */
			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				$this->log( $level, $message, array( 'source' => $handle ) );

				return true;
			}

			/**
			 * Record a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function log( $level, $message, $context = array() ) {
				$this->entries[] = array(
					'level'   => (string) $level,
					'message' => (string) $message,
					'context' => (array) $context,
				);
			}

			/**
			 * Log an emergency message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function emergency( $message, $context = array() ) {
				$this->log( 'emergency', $message, $context );
			}

			/**
			 * Log an alert message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function alert( $message, $context = array() ) {
				$this->log( 'alert', $message, $context );
			}

			/**
			 * Log a critical message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function critical( $message, $context = array() ) {
				$this->log( 'critical', $message, $context );
			}

			/**
			 * Log an error message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function error( $message, $context = array() ) {
				$this->log( 'error', $message, $context );
			}

			/**
			 * Log a warning message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function warning( $message, $context = array() ) {
				$this->log( 'warning', $message, $context );
			}

			/**
			 * Log a notice message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function notice( $message, $context = array() ) {
				$this->log( 'notice', $message, $context );
			}

			/**
			 * Log an info message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function info( $message, $context = array() ) {
				$this->log( 'info', $message, $context );
			}

			/**
			 * Log a debug message.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function debug( $message, $context = array() ) {
				$this->log( 'debug', $message, $context );
			}
		};

		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ): object {
				return $logger;
			}
		);

		return $logger;
	}

	/**
	 * @testdox Should log a correlated, redacted request/response pair when transport logging is enabled.
	 */
	public function test_transport_logs_correlated_redacted_request_and_response(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			// Synthetic transport input, not a platform answer: a Stripe Refund object (Stripe API reference, "The Refund
			// object") has no client_secret. It is added to prove the response line is redacted, as the client logs whatever
			// body the platform answers (class-wc-payments-api-client.php:2780-2784).
			'body'     => wp_json_encode(
				array(
					'id'            => 're_test',
					'client_secret' => 'secret_private_value',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->refund_charge( 'ch_test', 250, 'shopper emailed john@example.com', 'native_transport', 'idem_test' );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$request_entries  = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API REQUEST (' ) ) );
		$response_entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API RESPONSE (' ) ) );

		$this->assertCount( 1, $request_entries );
		$this->assertCount( 1, $response_entries );
		$this->assertSame( 'info', $request_entries[0]['level'] );
		$this->assertSame( 'woopayments', $request_entries[0]['context']['source'] );
		$this->assertStringContainsString( 'POST /sites/123/wcpay/refunds', $request_entries[0]['message'] );

		$this->assertSame( '(redacted)', $request_entries[0]['context']['body']['metadata']['merchant_refund_reason'] ?? null, 'The free-text refund reason can carry PII and must never be logged.' );
		$this->assertSame( '(redacted)', $response_entries[0]['context']['body']['client_secret'] ?? null );
		$this->assertSame( 're_test', $response_entries[0]['context']['body']['id'] ?? null );

		preg_match( '/^API REQUEST \(([^)]+)\)/', $request_entries[0]['message'], $request_id );
		preg_match( '/^API RESPONSE \(([^)]+)\)/', $response_entries[0]['message'], $response_id );
		$this->assertNotEmpty( $request_id[1] ?? '' );
		$this->assertSame( $request_id[1] ?? '', $response_id[1] ?? null, 'The response must correlate to its request by id.' );
	}

	/**
	 * @testdox Should redact the WooPay webhook secret from transport logs.
	 */
	public function test_transport_logs_redact_woopay_webhook_secret(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'result' => 'success' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->update_woopay( array( 'webhook_secret' => 'woopay_webhook_signing_secret_value' ) );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$request_entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API REQUEST (' ) ) );

		$this->assertCount( 1, $request_entries );
		$this->assertSame( '(redacted)', $request_entries[0]['context']['body']['webhook_secret'] ?? null, 'The WooPay webhook signing secret must never be logged in cleartext.' );
	}

	/**
	 * @testdox Should redact GET query strings in transport logs and keep the lifted idempotency key out of logged bodies.
	 */
	public function test_transport_logs_redact_query_strings_and_lifted_idempotency_key(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => array() ) ),
		);

		$sut    = new WooPaymentsApiClient();
		$filter = static function ( array $params ): array {
			$params['email'] = 'john@example.com';
			return $params;
		};

		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_api_request_params', $filter, 10, 3 );

		try {
			$sut->get_terminal_locations();
			$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_private' );
		} finally {
			remove_filter( 'wcpay_api_request_params', $filter, 10 );
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$request_entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API REQUEST (' ) ) );
		$this->assertCount( 2, $request_entries );

		$get_entry = $request_entries[0];
		$this->assertStringContainsString( 'GET /sites/123/wcpay/terminal/locations?', $get_entry['message'] );
		$this->assertStringContainsString( 'email=%28redacted%29', $get_entry['message'], 'Redactable params must be masked in the logged query string.' );
		$this->assertStringNotContainsString( 'john%40example.com', $get_entry['message'] );
		$this->assertStringContainsString( 'email=john%40example.com', (string) $http_client->requests[0]['path'], 'The wire request itself must keep the real value.' );

		foreach ( $request_entries as $entry ) {
			$this->assertStringNotContainsString( 'idem_private', wp_json_encode( $entry ), 'The lifted idempotency key must not appear in logged bodies.' );
		}
	}

	/**
	 * @testdox Should log an error line for API errors when transport logging is enabled.
	 */
	public function test_transport_logs_api_error_line(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();

		try {
			$this->capture_api_error(
				array(
					'error' => array(
						'code'    => 'card_declined',
						'message' => 'Your card was declined.',
						'type'    => 'card_error',
					),
				),
				402
			);
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$error_entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ) );
		$this->assertNotEmpty( $error_entries );
		$this->assertSame( 'Your card was declined. (card_declined)', $error_entries[0]['message'] );
	}

	/**
	 * @testdox Should stay silent when neither dev mode nor the logging setting enables transport logging.
	 */
	public function test_transport_logging_is_gated_off_by_default(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			remove_filter( 'wcpay_dev_mode', '__return_false' );
		}

		$this->assertSame( array(), $logger->entries, 'Transport logging must be opt-in: dev mode or the enable_logging gateway setting.' );
	}

	/**
	 * @testdox The transport log redacts nothing while logging is off, and the request, the response and the error line once it is on.
	 *
	 * Redaction looks at every string value, so a store with logging off must not pay for it; the subclass counts each
	 * redaction. The error envelope is the one client 11.1.0 parses (class-wc-payments-api-client.php:2852-2871: error.code,
	 * error.message, error.type) and logs as "<message> (<code>)" (:2912).
	 */
	public function test_transport_log_redaction_runs_only_when_the_log_is_written(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => 'No such subscription.',
						'type'    => 'invalid_request_error',
					),
				)
			),
		);
		$transport_log         = new class() extends WooPaymentsTransportLog {
			/**
			 * Redactions made.
			 *
			 * @var int
			 */
			public int $redactions = 0;

			/**
			 * Count the redaction, then redact.
			 *
			 * @param mixed $input Params, body, message or code.
			 * @return mixed
			 */
			public function redact( $input ) {
				++$this->redactions;

				return parent::redact( $input );
			}
		};
		$transport_log->init( wc_get_container()->get( WooPaymentsLogger::class ) );
		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $transport_log );
		$send = static function () use ( $sut ): void {
			try {
				$sut->send_site_request( array( 'note' => 'https://shop.example.test/?key=wc_order_1' ), 'subscriptions', 'POST' );
			} catch ( WooPaymentsApiException $exception ) {
				unset( $exception );
			}
		};

		try {
			$send();
			$redactions_while_off = $transport_log->redactions;
			$entries_while_off    = $logger->entries;
			update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
			$send();
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			remove_filter( 'wcpay_dev_mode', '__return_false' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertSame( array(), $entries_while_off );
		$this->assertSame( 0, $redactions_while_off, 'Nothing is redacted while logging is off.' );
		$this->assertSame( 4, $transport_log->redactions, 'With logging on, the params, the response body, and the error message and code are each redacted once.' );
		$this->assertCount( 1, array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API REQUEST (' ) ), 'The request line is written.' );
		$this->assertCount( 1, array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API RESPONSE (' ) ), 'The response line is written.' );
		$this->assertNotEmpty( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ), 'The error line is written.' );
	}

	/**
	 * @testdox Should log transport traffic in dev mode without the logging setting.
	 */
	public function test_transport_logs_in_dev_mode_without_setting(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		add_filter( 'wcpay_dev_mode', '__return_true' );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			remove_filter( 'wcpay_dev_mode', '__return_true' );
		}

		$this->assertNotEmpty( $logger->entries );
	}

	/**
	 * @testdox The gated transport log redacts session, checkout and credential keys at any depth, lists included, whatever their case.
	 *
	 * The request params are the store's own. The response body is defensive input rather than a platform shape: the
	 * client logs whatever body the platform answers, after API_KEYS_TO_REDACT (class-wc-payments-api-client.php:2780-2784).
	 */
	public function test_transport_log_redacts_session_and_credential_keys_at_any_depth(): void {
		$secrets = array(
			'platform_checkout_key'  => 'leak-platform-checkout-key',
			'session'                => 'leak-session',
			'session_id'             => 'leak-session-id',
			'woopay_session'         => 'leak-woopay-session',
			'token'                  => 'leak-token',
			'access_token'           => 'leak-access-token',
			'blog_token'             => 'leak-blog-token',
			'user_token'             => 'leak-user-token',
			'signature'              => 'leak-signature',
			'sig'                    => 'leak-sig',
			'secret'                 => 'leak-secret',
			'webhook_signing_secret' => 'leak-suffix-secret',
			'publishable_key'        => 'leak-suffix-key',
			'Authorization'          => 'leak-authorization',
			'Cookie'                 => 'leak-cookie',
			'Session_Key'            => 'leak-mixed-case-suffix-key',
			'X_Client_Secret'        => 'leak-mixed-case-suffix-secret',
		);
		$params  = array(
			'top'  => $secrets,
			'list' => array( array( 'nested' => $secrets ) ),
		);

		$logger = $this->log_transport_request( $params, array( 'data' => array( 'items' => array( $secrets ) ) ) );

		$request  = $this->get_transport_entry( $logger, 'API REQUEST (' );
		$response = $this->get_transport_entry( $logger, 'API RESPONSE (' );
		foreach ( array_keys( $secrets ) as $key ) {
			$this->assertSame( '(redacted)', $request['context']['body']['top'][ $key ] ?? null, "Request key $key at depth one." );
			$this->assertSame( '(redacted)', $request['context']['body']['list'][0]['nested'][ $key ] ?? null, "Request key $key inside a list." );
			$this->assertSame( '(redacted)', $response['context']['body']['data']['items'][0][ $key ] ?? null, "Response key $key inside a list." );
		}
		$this->assertStringNotContainsString( 'leak-', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox The gated transport log keeps only the scheme and host of any URL value and replaces Stripe secret, restricted key and client secret values whole, whatever their key.
	 *
	 * The error line reads the envelope client 11.1.0 parses (class-wc-payments-api-client.php:2852-2871: error.code,
	 * error.message, error.type) and logs "<message> (<code>)" (:2912).
	 */
	public function test_transport_log_redacts_urls_and_secret_shaped_values(): void {
		$params = array(
			'return_to' => 'https://shop.example.test/checkout/?key=wc_order_leak&session=leak',
			'notes'     => array( 'Contact via https://pay.example.test/r#access_token=leak' ),
			'live'      => 'sk_live_51Leak',
			'test'      => 'rk_test_51Leak',
			'unrelated' => 'pi_3Leak_secret_Leak',
		);
		$logger = $this->log_transport_request(
			$params,
			array(
				'error' => array(
					'code'    => 'resource_missing',
					'message' => 'No such setup intent: seti_1Leak_secret_Leak; see https://pay.example.test/r?key=leak',
					'type'    => 'invalid_request_error',
				),
			),
			404
		);

		$body = $this->get_transport_entry( $logger, 'API REQUEST (' )['context']['body'];
		$this->assertSame( 'https://shop.example.test/(redacted)', $body['return_to'] );
		$this->assertSame( array( 'Contact via https://pay.example.test/(redacted)' ), $body['notes'] );
		$this->assertSame( array( '(redacted)', '(redacted)', '(redacted)' ), array( $body['live'], $body['test'], $body['unrelated'] ) );
		$errors = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ) );
		$this->assertSame( array( '(redacted) (resource_missing)' ), array_column( $errors, 'message' ), 'An error message holding a client secret is replaced whole.' );
		$this->assertStringNotContainsString( 'eak', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox The gated transport log never carries the request headers: neither the Authorization header Jetpack signs with nor the blog token.
	 *
	 * The request goes through the real WooPaymentsHttpClient and Jetpack's Client::remote_request(), which signs it with
	 * the blog token (`token-key.blog-secret` in the jetpack_options store, the format Jetpack's Tokens class reads);
	 * pre_http_request answers in WP_Http::request()'s array shape. Its body is synthetic transport-only input, not a
	 * platform answer: the client logs whatever body the platform answers (class-wc-payments-api-client.php:2780-2784).
	 */
	public function test_transport_log_never_carries_the_signed_headers(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();
		\Jetpack_Options::update_option( 'id', 123 );
		\Jetpack_Options::update_option( 'blog_token', 'leaktokenkey.leakblogsecret' );
		\Jetpack_Options::update_option( 'time_diff', 0 );
		$sent_headers = array();
		$capture      = static function ( $preempt, array $args ) use ( &$sent_headers ) {
			$sent_headers = $args['headers'];

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'result' => 'success' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 2 );
		$http_client = new class() extends \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient {
			/**
			 * Treat the store as connected; signing reads the blog token itself.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}
		};
		$sut         = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->send_site_request( array( 'note' => 'signed' ), 'subscriptions', 'POST' );
		} finally {
			remove_filter( 'pre_http_request', $capture, 10 );
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
			\Jetpack_Options::delete_option( array( 'id', 'blog_token', 'time_diff' ) );
		}

		$this->assertStringContainsString( 'leaktokenkey', (string) ( $sent_headers['Authorization'] ?? '' ), 'The request was signed with the blog token.' );
		$this->assertNotEmpty( $logger->entries );
		foreach ( $logger->entries as $entry ) {
			$this->assertArrayNotHasKey( 'headers', $entry['context'] );
			$this->assertArrayNotHasKey( 'request', $entry['context'] );
		}
		$logged = (string) wp_json_encode( $logger->entries );
		foreach ( array( 'leaktokenkey', 'leakblogsecret', (string) ( $sent_headers['Authorization'] ?? 'unsigned' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $logged );
		}
	}

	/**
	 * @testdox The gated transport log redacts the shopper's name, email and country the Stripe Billing transaction update sends.
	 *
	 * The params are exactly what StripeBillingInvoiceService::update_transaction_details() sends (client
	 * class-wc-payments-invoice-service.php:339-347 sends the same four).
	 */
	public function test_transport_log_redacts_the_transaction_update_shopper_details(): void {
		$logger = $this->log_transport_request(
			array(
				'customer_first_name' => 'Janeleak',
				'customer_last_name'  => 'Doeleak',
				'customer_email'      => 'janeleak@example.com',
				'customer_country'    => 'DE',
			),
			array( 'result' => 'success' )
		);

		$request = $this->get_transport_entry( $logger, 'API REQUEST (' );
		foreach ( array( 'customer_first_name', 'customer_last_name', 'customer_email', 'customer_country' ) as $key ) {
			$this->assertSame( '(redacted)', $request['context']['body'][ $key ] ?? null, $key );
		}
		$this->assertStringNotContainsString( 'leak', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox The gated transport log keeps none of a key-bearing URL's query, a signature two levels down and a client secret under an unknown key.
	 *
	 * The response body is synthetic transport-only input, not a platform answer: the client logs whatever body the platform
	 * answers (class-wc-payments-api-client.php:2780-2784).
	 */
	public function test_transport_log_redacts_a_combined_body(): void {
		$logger = $this->log_transport_request(
			array(
				'redirect' => 'https://shop.example.test/order-received/12/?key=wc_order_combinedleak',
				'payment'  => array( 'proof' => array( 'signature' => 'combinedleak-signature' ) ),
				'opaque'   => 'pi_3Combinedleak_secret_Combinedleak',
			),
			array( 'result' => 'success' )
		);

		$request = $this->get_transport_entry( $logger, 'API REQUEST (' );
		$this->assertSame( '(redacted)', $request['context']['body']['payment']['proof']['signature'] ?? null );
		$this->assertSame( '(redacted)', $request['context']['body']['opaque'] ?? null );
		$this->assertStringStartsWith( 'https://shop.example.test', (string) ( $request['context']['body']['redirect'] ?? '' ), 'The URL keeps its host.' );
		$this->assertStringNotContainsString( 'ombinedleak', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox The gated transport log keeps only the host of a dashboard login link, and the caller still gets the whole link.
	 *
	 * The answer is Stripe's documented Login Link object (https://docs.stripe.com/api/accounts/login_link/create), which
	 * the platform's accounts/login_links route returns; LegacyAdminLinkHandler::handle_login_request() reaches this call.
	 */
	public function test_transport_log_keeps_only_the_host_of_a_login_link(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger                = $this->install_recording_logger();
		$link                  = 'https://connect.stripe.com/express/acct_1032D82eZvKYlo2C/F44eiGHh5sEV';
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'object'  => 'login_link',
					'created' => 1686084879,
					'url'     => $link,
				)
			),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true, false ), $this->transport_log() );

		try {
			$result = $sut->create_login_link( home_url( '/overview' ) );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertSame( $link, $result['url'] );
		$this->assertSame( 'https://connect.stripe.com/(redacted)', $this->get_transport_entry( $logger, 'API RESPONSE (' )['context']['body']['url'] ?? null );
		$this->assertStringNotContainsString( 'F44eiGHh5sEV', (string) wp_json_encode( $logger->entries ) );
	}

	/**
	 * @testdox The gated transport log redacts $_dataName in request bodies, response bodies, GET parameters and the error line.
	 *
	 * Each value sits under a key no list names. The error envelope is the one client 11.1.0 parses
	 * (class-wc-payments-api-client.php:2852-2871: error.code, error.message, error.type) and logs as "<message> (<code>)"
	 * (:2912); its message carries the value as defensive input, since the client logs whatever text the platform answers
	 * (:2780-2784). The GET answer is synthetic transport-only input.
	 *
	 * @dataProvider provide_encoded_and_embedded_credentials
	 *
	 * @param string $value  Value sent and answered.
	 * @param string $logged What the log may show in its place.
	 */
	public function test_transport_log_redacts_encoded_and_embedded_credentials( string $value, string $logged ): void {
		$post_logger = $this->log_transport_request(
			array( 'note' => $value ),
			array(
				'error' => array(
					'code'    => 'resource_missing',
					'message' => $value,
					'type'    => 'invalid_request_error',
				),
			),
			404
		);
		$get_logger  = $this->log_transport_request( array( 'note' => $value ), array( 'data' => array() ), 200, 'GET' );

		$this->assertSame( $logged, $this->get_transport_entry( $post_logger, 'API REQUEST (' )['context']['body']['note'] ?? null, 'Request body.' );
		$this->assertSame( $logged, $this->get_transport_entry( $post_logger, 'API RESPONSE (' )['context']['body']['error']['message'] ?? null, 'Response body.' );
		$errors = array_values( array_filter( $post_logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ) );
		$this->assertSame( array( "$logged (resource_missing)" ), array_column( $errors, 'message' ), 'Error line.' );
		$this->assertStringEndsWith( '&' . http_build_query( array( 'note' => $logged ) ), $this->get_transport_entry( $get_logger, 'API REQUEST (' )['message'], 'GET parameter, after the test_mode flag every request carries.' );
		$this->assertDoesNotMatchRegularExpression( '/leak/i', (string) wp_json_encode( array( $post_logger->entries, $get_logger->entries ) ) );
	}

	/**
	 * Encoded and embedded credentials, each with what the log may show in its place.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_encoded_and_embedded_credentials(): array {
		return array(
			'JSON text with sensitive keys'             => array( '{"session":"leak-json-session","Authorization":"Bearer leak-json-bearer"}', '(redacted)' ),
			'a JSON list with a mixed-case key suffix'  => array( '[{"Session_Key":"leak-json-list"}]', '(redacted)' ),
			'JSON text with an escaped URL query'       => array( '{"return":"https:\/\/shop.example.test\/?key=leak-escaped-json"}', '(redacted)' ),
			'a JSON fragment with an escaped URL query' => array( '"return":"https:\/\/shop.example.test\/?key=leak-escaped-fragment"', '(redacted)' ),
			'percent-encoded JSON with a session key'   => array( '%7B%22session%22%3A%22leak-encoded-json%22%7D', '(redacted)' ),
			'a percent-encoded secret key'              => array( 'sk%5Flive%5FLeakEncoded', '(redacted)' ),
			'a percent-encoded URL with a query'        => array( 'https%3A%2F%2Fshop.example.test%2F%3Fkey%3Dleak-encoded-url', '(redacted)' ),
			'a secret key glued to a prefix'            => array( 'prefixsk_live_LeakGlued', '(redacted)' ),
			'a client secret glued to a prefix'         => array( 'ref7pi_3LeakGlued_secret_LeakGlued', '(redacted)' ),
			'a URL glued to a prefix'                   => array( 'seehttps://shop.example.test/?key=leak-glued-url', 'seehttps://shop.example.test/(redacted)' ),
			'JSON text with a unicode-escaped secret'   => array( '{"note":"sk\u005flive\u005fLeakUnicode"}', '(redacted)' ),
			'JSON text after leading whitespace'        => array( ' {"session":"leak-padded-json"}', '(redacted)' ),
			'deeply nested JSON text'                   => array( str_repeat( '{"a":', 10 ) . '{"session":"leak-deep-json"}' . str_repeat( '}', 10 ), '(redacted)' ),
			'JSON text with a percent-encoded key'      => array( '{"%73ession":"leak-encoded-key"}', '(redacted)' ),
			'JSON text with percent-encoded quotes'     => array( '{%22session%22:%22leak-encoded-quotes%22}', '(redacted)' ),
			// Stripe's documented shapes (https://docs.stripe.com/api/accounts/login_link/create, https://docs.stripe.com/api/account_links/object): the credential is the path.
			'a Stripe login link'                       => array( 'https://connect.stripe.com/express/acct_1032D82eZvKYlo2C/F44eiGHh5sEV', 'https://connect.stripe.com/(redacted)' ),
			'a Stripe account onboarding link in text'  => array( 'Continue at https://connect.stripe.com/setup/c/acct_1Mt0CORHFI4mz9Rw/TqckGNUHg2mG today', 'Continue at https://connect.stripe.com/(redacted) today' ),
			'a plain URL'                               => array( 'https://shop.example.test/leak-path', 'https://shop.example.test/(redacted)' ),
			'a URL with credentials and a port'         => array( 'https://user:leak-password@shop.example.test:8443/a', 'https://shop.example.test/(redacted)' ),
		);
	}

	/**
	 * @testdox The gated transport log keeps a clean value of 2048 bytes and replaces a longer one whole.
	 *
	 * The length check runs before any pattern, so a long value cannot make the log slow. The response body is synthetic
	 * transport-only input.
	 */
	public function test_transport_log_replaces_values_longer_than_2048_bytes_whole(): void {
		$params = array(
			'at_bound'     => str_repeat( 'a', 2048 ),
			'beyond_bound' => str_repeat( 'a', 2049 ),
		);

		$body = $this->get_transport_entry( $this->log_transport_request( $params, array( 'data' => array() ) ), 'API REQUEST (' )['context']['body'];

		$this->assertSame( $params['at_bound'], $body['at_bound'] );
		$this->assertSame( '(redacted)', $body['beyond_bound'] );
	}

	/**
	 * @testdox Transport lines carry the request context of every WooPayments line, with the request path only and no referrer.
	 *
	 * Client 11.1.0 writes the request, response and error lines through its gated logger
	 * (includes/wc-payment-api/class-wc-payments-api-client.php:2731-2737, :2779-2784, :2912; includes/class-logger.php:40-41,
	 * :89-91, :140-153), which merges Logger_Context::get_context() into every line (src/Internal/Logger.php:64-69, fields at
	 * src/Internal/LoggerContext.php:140-164). Native leaves out the referrer and the query string, as on its other lines.
	 */
	public function test_transport_lines_carry_the_request_context(): void {
		$server                  = $_SERVER;
		$_SERVER['REQUEST_URI']  = '/checkout/order-pay/123/?pay_for_order=true&key=wc_order_context';
		$_SERVER['HTTP_REFERER'] = 'https://shop.example.test/checkout/order-pay/123/?key=wc_order_context';

		try {
			// The error envelope is the one client 11.1.0 parses: error.code, error.message, error.type
			// (includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871).
			$logger = $this->log_transport_request(
				array( 'note' => 'context' ),
				array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => 'No such subscription.',
						'type'    => 'invalid_request_error',
					),
				),
				404
			);
		} finally {
			$_SERVER = $server;
		}

		$error_entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ) );
		$this->assertCount( 1, $error_entries, 'The platform error line is written.' );
		$entries = array(
			'request'  => $this->get_transport_entry( $logger, 'API REQUEST (' ),
			'response' => $this->get_transport_entry( $logger, 'API RESPONSE (' ),
			'error'    => $error_entries[0],
		);
		foreach ( $entries as $line => $entry ) {
			$this->assertSame( '/checkout/order-pay/123/', $entry['context']['REQUEST_URI'] ?? null, 'The ' . $line . ' line carries the request path without its query.' );
			$this->assertArrayHasKey( 'WOOPAYMENTS_MODE', $entry['context'], 'The ' . $line . ' line carries the mode.' );
			$this->assertArrayHasKey( 'WP_USER', $entry['context'], 'The ' . $line . ' line carries the user.' );
			$this->assertArrayHasKey( 'DOING_CRON', $entry['context'], 'The ' . $line . ' line carries the request type.' );
			$this->assertArrayNotHasKey( 'HTTP_REFERER', $entry['context'], 'The ' . $line . ' line leaves out the referrer.' );
			$this->assertSame( 'woopayments', $entry['context']['source'] );
		}
		$this->assertSame( 'info', $entries['request']['level'] );
		$this->assertSame( 'info', $entries['response']['level'] );
		$this->assertSame( 'No such subscription. (resource_missing)', $entries['error']['message'] );
	}

	/**
	 * @testdox A log handler that throws on the response line never turns the platform's answer into a failure.
	 *
	 * The throw comes after the platform answered: a logging failure there must not make a completed request look failed.
	 */
	public function test_failing_log_handler_never_fails_a_request_the_platform_answered(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = new class() extends RecordingWcLogger {
			/**
			 * Throw on the response line, record every other line.
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 * @throws \RuntimeException On the response line.
			 */
			public function log( $level, $message, $context = array() ) {
				if ( 0 === strpos( (string) $message, 'API RESPONSE (' ) ) {
					throw new \RuntimeException( 'Log handler failed.' );
				}

				parent::log( $level, $message, $context );
			}
		};
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);
		$http_client          = new FakeWooPaymentsHttpClient();
		$http_client->blog_id = 123;
		// A synthetic transport sentinel, not a recorded platform answer: the test checks only that the answer comes back.
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'sub_answered' ) ),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$result = $sut->send_site_request( array( 'note' => 'answered' ), 'subscriptions', 'POST' );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertSame( array( 'id' => 'sub_answered' ), $result );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertCount( 1, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'API REQUEST (' ) ), 'The request line before the failure is written.' );
	}

	/**
	 * @testdox A log handler that throws on the error line of a 500 answer still lets the platform error reach the caller.
	 *
	 * The refund hold records an ambiguous refund answer only when send_refund() catches a WooPaymentsApiException
	 * (WooPaymentsProviderGatewayAdapter::send_refund()), so a log handler's own exception must never replace it. The error
	 * envelope is the one client 11.1.0 parses: error.code, error.message, error.type
	 * (includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871).
	 */
	public function test_failing_log_handler_on_the_error_line_lets_the_platform_error_reach_the_caller(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = new class() extends RecordingWcLogger {
			/**
			 * Throw on the platform error line, record every other line.
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 * @throws \RuntimeException On the platform error line.
			 */
			public function log( $level, $message, $context = array() ) {
				if ( 'error' === $level && false !== strpos( (string) $message, '(api_error)' ) ) {
					throw new \RuntimeException( 'Log handler failed.' );
				}

				parent::log( $level, $message, $context );
			}
		};
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 500 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 'api_error',
						'message' => 'An unknown error occurred.',
						'type'    => 'api_error',
					),
				)
			),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		$caught = null;

		try {
			$sut->send_site_request( array( 'note' => 'server error' ), 'subscriptions', 'POST' );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertInstanceOf( WooPaymentsApiException::class, $caught, 'The platform error reaches the caller, not the log handler\'s exception.' );
		$this->assertSame( 500, $caught->get_http_code() );
		$this->assertTrue( $caught->has_ambiguous_outcome(), 'A 500 answer stays ambiguous, so the refund hold records it.' );
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox A settings read that throws after the platform answered leaves the answer as it is and writes the read-failure line.
	 *
	 * The gate reads the gateway settings again for the response line. WooPaymentsAccountService catches a failing read,
	 * writes its read-failure line and treats logging as off, so the failure never reaches the platform request.
	 */
	public function test_settings_read_that_throws_after_the_answer_never_fails_the_request(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger       = $this->install_recording_logger();
		$answered     = false;
		$on_answer    = static function ( $response ) use ( &$answered ) {
			$answered = true;

			return $response;
		};
		$failing_read = static function ( $value ) use ( &$answered ) {
			if ( $answered ) {
				throw new \RuntimeException( 'Settings read failed.' );
			}

			return $value;
		};
		add_filter( 'wcpay_api_request_response', $on_answer );
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $failing_read );
		$http_client          = new FakeWooPaymentsHttpClient();
		$http_client->blog_id = 123;
		// A synthetic transport sentinel, not a recorded platform answer: the test checks only that the answer comes back.
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'sub_read_failure' ) ),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$result = $sut->send_site_request( array( 'note' => 'read failure' ), 'subscriptions', 'POST' );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $failing_read );
			remove_filter( 'wcpay_api_request_response', $on_answer );
			remove_filter( 'wcpay_dev_mode', '__return_false' );
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		$this->assertSame( array( 'id' => 'sub_read_failure' ), $result );
		$this->assertSame( 1, $http_client->request_count );
		$read_failures = array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] && 'Native WooPayments could not read the gateway settings; treating it as missing.' === $entry['message'] );
		$this->assertNotEmpty( $read_failures, 'The read-failure line is written.' );
		$this->assertCount( 1, array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], 'API REQUEST (' ) ), 'The request line before the answer is written.' );
	}

	/**
	 * Send one request through the API client with the gated transport log on, answered with the given body.
	 *
	 * @param array<string,mixed> $params        Request params.
	 * @param array<string,mixed> $response_body Decoded response body.
	 * @param int                 $status        HTTP status.
	 * @param string              $method        HTTP method.
	 * @return object Recording logger.
	 */
	private function log_transport_request( array $params, array $response_body, int $status = 200, string $method = 'POST' ): object {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger = $this->install_recording_logger();

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response_body ),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->send_site_request( $params, 'subscriptions', $method );
		} catch ( WooPaymentsApiException $exception ) {
			unset( $exception );
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		return $logger;
	}

	/**
	 * Get the one transport log entry whose message starts with a prefix.
	 *
	 * @param object $logger Recording logger.
	 * @param string $prefix Message prefix.
	 * @return array{level:string,message:string,context:array<string,mixed>}
	 */
	private function get_transport_entry( object $logger, string $prefix ): array {
		$entries = array_values( array_filter( $logger->entries, static fn( array $entry ): bool => 0 === strpos( $entry['message'], $prefix ) ) );
		$this->assertCount( 1, $entries );

		return $entries[0];
	}

	/**
	 * @testdox Should apply the preserved WooPayments response filter after transport requests.
	 */
	public function test_request_applies_preserved_response_filter_after_transport_requests(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'https://local.test/wp-cron.php?doing_wp_cron=1' ),
			'body'     => '',
		);
		$filter_observations   = array();
		$filter                = static function ( $response, string $method, string $url, string $api ) use ( &$filter_observations ): array {
			$filter_observations = array(
				'method'        => $method,
				'url'           => $url,
				'api'           => $api,
				'response_code' => wp_remote_retrieve_response_code( $response ),
			);

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => 're_filtered' ) ),
			);
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_api_request_response', $filter, 10, 4 );

		try {
			$result = $sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );
		} finally {
			remove_filter( 'wcpay_api_request_response', $filter, 10 );
		}

		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 're_filtered', $result['id'] );
		$this->assertSame( 302, $filter_observations['response_code'] );
		$this->assertSame( 'POST', $filter_observations['method'] );
		$this->assertSame( 'refunds', $filter_observations['api'] );
		$this->assertStringStartsWith( 'https://public-api.wordpress.com/wpcom/v2/sites/%s/wcpay/refunds', $filter_observations['url'] );
	}

	/**
	 * @testdox Should create an embedded account session through the account-scoped user-token endpoint.
	 */
	public function test_create_embedded_account_session_posts_to_account_scoped_user_token_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'client_secret'   => 'cs_test',
					'expires_at'      => 1781740800,
					'account_id'      => 'acct_native',
					'is_live'         => false,
					'publishable_key' => 'pk_test_native',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_embedded_account_session();

		$this->assertSame( 'cs_test', $result['client_secret'] );
		$this->assertSame( '/sites/123/wcpay/accounts/embedded/session', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertTrue( $http_client->last_use_user_token, 'Embedded account sessions must use the connection-owner user token.' );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertFalse( $body['test_mode'] );
	}

	/**
	 * @testdox Should fail the embedded account session once, without retrying, on the platform's session failure (wcpay_server_error, HTTP 500).
	 */
	public function test_create_embedded_account_session_throws_on_platform_server_error(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array(
				'code'    => 500,
				'message' => 'Internal Server Error',
			),
			'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
			// Platform Server_Exception::to_wp_error() (wcpay core/exceptions/class-server-exception.php).
			'body'     => wp_json_encode(
				array(
					'code'    => 'wcpay_server_error',
					'message' => 'Unexpected server error.',
					'data'    => array( 'status' => 500 ),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->create_embedded_account_session();
			$this->fail( 'A platform 500 must fail the embedded account session.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_server_error', $exception->get_error_code() );
			$this->assertSame( 500, $exception->get_http_code() );
		}
		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Should create a customer through the native transport customers endpoint.
	 */
	public function test_create_customer_posts_to_customers_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'cus_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$customer_id = $sut->create_customer(
			array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.com',
			)
		);

		$this->assertSame( 'cus_test', $customer_id );
		$this->assertSame( '/sites/123/wcpay/customers', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame( 'Ada Lovelace', $body['name'] );
	}

	/**
	 * @testdox Should update an existing customer through the native transport customer resource endpoint.
	 */
	public function test_update_customer_posts_to_customer_resource(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$sut->update_customer(
			'cus_test',
			array(
				'email' => 'ada@example.com',
			)
		);

		$this->assertSame( '/sites/123/wcpay/customers/cus_test', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame( 'ada@example.com', $body['email'] );
	}

	/**
	 * @testdox Should create and confirm native WooPayments PaymentIntents with one payment credential and lifted idempotency.
	 *
	 * Pinned WooPayments 11.1.0: Create_And_Confirm_Intention::DEFAULT_PARAMS and ::get_api().
	 */
	public function test_create_and_confirm_payment_intention_lifts_idempotency_and_preserves_request_shape(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'      => 'pi_test',
					'status'  => 'succeeded',
					'charges' => array(
						'total_count' => 0,
						'data'        => array(),
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_and_confirm_payment_intention(
			array(
				'amount'               => 1000,
				'currency'             => 'usd',
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method'       => 'pm_test',
				'payment_method_types' => array( 'card' ),
			),
			'idem_charge'
		);

		$this->assertSame( 'pi_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/intentions', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'idem_charge', $http_client->last_headers['Idempotency-Key'] );

		// Intentional wire-serialization check: the boolean confirm flag must be coerced to the string "true" on the wire for server compatibility.
		$this->assertStringContainsString( '"confirm":"true"', (string) $http_client->last_body );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame(
			array(
				'test_mode'            => false,
				'amount'               => 1000,
				'currency'             => 'usd',
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method'       => 'pm_test',
				'payment_method_types' => array( 'card' ),
				'confirm'              => 'true',
				'capture_method'       => 'automatic',
			),
			$body
		);
	}

	/**
	 * @testdox Should create and confirm native WooPayments SetupIntents through the setup_intents endpoint.
	 *
	 * Pinned WooPayments 11.1.0: Create_And_Confirm_Setup_Intention::DEFAULT_PARAMS and ::get_api().
	 */
	public function test_create_and_confirm_setup_intention_posts_to_setup_intents_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'            => 'seti_test',
					'status'        => 'succeeded',
					'client_secret' => 'seti_test_secret_abc',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_and_confirm_setup_intention(
			array(
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method'       => 'pm_test',
				'payment_method_types' => array( 'sepa_debit' ),
			),
			'idem_setup'
		);

		$this->assertSame( 'seti_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/setup_intents', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'idem_setup', $http_client->last_headers['Idempotency-Key'] );

		// Intentional wire-serialization check: the boolean confirm flag must be coerced to the string "true" on the wire for server compatibility.
		$this->assertStringContainsString( '"confirm":"true"', (string) $http_client->last_body );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame(
			array(
				'test_mode'            => false,
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method'       => 'pm_test',
				'payment_method_types' => array( 'sepa_debit' ),
				'confirm'              => 'true',
			),
			$body
		);
	}

	/**
	 * @testdox Should require explicit payment method types when confirming native WooPayments SetupIntents.
	 */
	public function test_create_and_confirm_setup_intention_requires_explicit_payment_method_types(): void {
		$sut = new WooPaymentsApiClient();
		$sut->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->create_and_confirm_setup_intention(
				array(
					'customer'       => 'cus_test',
					'payment_method' => 'pm_test',
				),
				'idem_setup'
			);
			$this->fail( 'Expected missing payment method types to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 400, $exception->get_http_code() );
		}
	}

	/**
	 * @testdox Should create unconfirmed native WooPayments SetupIntents with the server-compatible confirm flag.
	 *
	 * Pinned WooPayments 11.1.0: Create_Setup_Intention::DEFAULT_PARAMS and ::get_api().
	 */
	public function test_create_setup_intention_serializes_confirm_as_false_string(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'            => 'seti_unconfirmed',
					'status'        => 'requires_confirmation',
					'client_secret' => 'seti_unconfirmed_secret_abc',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_setup_intention(
			array(
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method_types' => array( 'card' ),
			),
			'idem_setup_unconfirmed'
		);

		$this->assertSame( 'seti_unconfirmed', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/setup_intents', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'idem_setup_unconfirmed', $http_client->last_headers['Idempotency-Key'] );

		// Intentional wire-serialization check: the boolean confirm flag must be coerced to the string "false" on the wire for server compatibility.
		$this->assertStringContainsString( '"confirm":"false"', (string) $http_client->last_body );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame(
			array(
				'test_mode'            => false,
				'customer'             => 'cus_test',
				'metadata'             => array( 'order_id' => '123' ),
				'payment_method_types' => array( 'card' ),
				'confirm'              => 'false',
			),
			$body
		);
	}

	/**
	 * @testdox Should require explicit payment method types when creating native WooPayments SetupIntents.
	 */
	public function test_create_setup_intention_requires_explicit_payment_method_types(): void {
		$sut = new WooPaymentsApiClient();
		$sut->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->create_setup_intention(
				array(
					'customer' => 'cus_test',
				),
				'idem_setup_unconfirmed'
			);
			$this->fail( 'Expected missing payment method types to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 400, $exception->get_http_code() );
		}
	}

	/**
	 * @testdox Should retrieve payment method details through the native transport payment methods endpoint.
	 */
	public function test_get_payment_method_reads_payment_methods_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'   => 'pm_test',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_payment_method( 'pm_test' );

		$this->assertSame( 'pm_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/payment_methods/pm_test?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
	}

	/**
	 * @testdox Should retrieve customer payment methods through the native transport payment methods list endpoint.
	 */
	public function test_get_payment_methods_reads_customer_payment_methods_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array(
							'id'   => 'pm_card',
							'type' => 'card',
						),
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->get_payment_methods( 'cus_test', 'card' );

		$this->assertSame( 'pm_card', $result['data'][0]['id'] );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );

		$this->assertStringStartsWith( '/sites/123/wcpay/payment_methods?', $http_client->last_path );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( 'cus_test', $query['customer'] );
		$this->assertSame( 'card', $query['type'] );
		$this->assertSame( '100', $query['limit'] );
		$this->assertSame( '1', $query['test_mode'] );
	}

	/**
	 * @testdox Listing a customer's payment intents reads the platform's intentions list with the customer and limit as query args.
	 *
	 * The shape is the one recorded on a local WPCOM platform for a test-mode store: Stripe's list object proxied as the
	 * connected account (`object`, `data`, `has_more`, `url`), newest first, each intent created by the store carrying
	 * the order's `order_id` and `order_key` metadata (https://docs.stripe.com/api/payment_intents/list).
	 */
	public function test_list_payment_intentions_reads_the_customer_intentions_list(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'object'   => 'list',
				'data'     => array(
					array(
						'id'       => 'pi_listed',
						'status'   => 'succeeded',
						'metadata' => array( 'order_id' => '42' ),
					),
				),
				'has_more' => false,
			)
		);

		$result = $sut->list_payment_intentions( 'cus_listed', 100 );

		$this->assertSame( 'pi_listed', $result['data'][0]['id'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
		$this->assertStringStartsWith( '/sites/123/wcpay/intentions?', $http_client->last_path );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'test_mode' => '1',
				'customer'  => 'cus_listed',
				'limit'     => '100',
			),
			$query
		);
	}

	/**
	 * @testdox Listing payment intents refuses an invalid customer ID before sending anything.
	 */
	public function test_list_payment_intentions_rejects_invalid_customer_id(): void {
		$http_client = new FakeWooPaymentsHttpClient();
		$sut         = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->list_payment_intentions( 'cus&customer=cus_other' );
			$this->fail( 'Expected an invalid customer ID to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_route_validation_failure', $exception->get_error_code() );
			$this->assertSame( 0, $http_client->request_count );
		}
	}

	/**
	 * @testdox Listing the account's payment intents created within a time range reads the platform's intentions list with created[gte], created[lte] and limit as query args.
	 *
	 * The platform's GET intentions route declares `created` and forwards declared args to Stripe's PaymentIntents list
	 * unchanged (wpcom `wcpay/class-intentions-controller.php:198-210`, `class-base-controller.php:425-430`), and Stripe
	 * filters by creation time with `created[gte]` and `created[lte]`, both inclusive
	 * (https://docs.stripe.com/api/payment_intents/list). Recorded on a local WPCOM platform: both bounds are honoured,
	 * alone or together, inclusive and cut at the second.
	 */
	public function test_list_payment_intentions_created_between_reads_the_account_intentions_list(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'object'   => 'list',
				'data'     => array(
					array(
						'id'      => 'pi_recent',
						'created' => 1700000100,
					),
				),
				'has_more' => false,
			)
		);

		$result = $sut->list_payment_intentions_created_between( 1700000000, 1700007500, 100 );

		$this->assertSame( 'pi_recent', $result['data'][0]['id'] );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
		$this->assertStringStartsWith( '/sites/123/wcpay/intentions?', $http_client->last_path );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'test_mode' => '1',
				'created'   => array(
					'gte' => '1700000000',
					'lte' => '1700007500',
				),
				'limit'     => '100',
			),
			$query
		);
		$this->assertArrayNotHasKey( 'customer', $query );
	}

	/**
	 * @testdox Listing a charge's refunds sends the recorded GET refunds request and returns the platform's list unchanged.
	 *
	 * Recorded on a local WPCOM platform (`Fixtures/rec-f458-refund-list.json`): the platform proxies Stripe's refunds
	 * list for the charge, newest first, each item unexpanded and carrying the metadata the store sent, and `has_more`
	 * is the only sign that a page is incomplete (`count` is the page size).
	 *
	 * @dataProvider recorded_refund_list_data
	 *
	 * @param string $pair REC F458 (a) fixture pair key.
	 */
	public function test_list_charge_refunds_sends_the_recorded_request_and_returns_the_list( string $pair ): void {
		$recorded                  = $this->load_recorded_refund_list_entry( $pair );
		list( $sut, $http_client ) = $this->make_sut( true, $recorded['response']['body'] );

		$result = $sut->list_charge_refunds( (string) $recorded['request']['query']['charge'], (int) $recorded['request']['query']['limit'] );

		$this->assertSame( $recorded['response']['body'], $result );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $http_client->last_headers, 'A list read carries no idempotency key.' );
		$this->assertStringStartsWith( '/sites/123/wcpay/refunds?', $http_client->last_path );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array_map( static fn( $value ): string => true === $value ? '1' : (string) $value, $recorded['request']['query'] ),
			$query,
			"The $pair request must send the recorded query args, in order."
		);
	}

	/**
	 * Recorded refund list pairs: a complete page and a page with more to read.
	 *
	 * @return array<string,array{string}>
	 */
	public function recorded_refund_list_data(): array {
		return array(
			'complete page, limit 100' => array( 'list_limit_100_complete' ),
			'incomplete page, limit 2' => array( 'list_limit_2_has_more' ),
		);
	}

	/**
	 * @testdox Listing a charge's refunds makes one attempt on a transport failure and reports it as ambiguous.
	 *
	 * A GET carries no idempotency key, so the transport retry loop allows no retry; the caller decides what a failed
	 * read means.
	 */
	public function test_list_charge_refunds_makes_one_attempt_on_a_transport_failure(): void {
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->responses = array(
			new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
		);
		$sut                    = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->list_charge_refunds( 'ch_3UOv4vBzWlxcwgpP0ALlMGAw' );
			$this->fail( 'Expected the transport failure to surface.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertTrue( $exception->has_ambiguous_outcome() );
		}

		$this->assertSame( 1, $http_client->request_count );
	}

	/**
	 * @testdox Listing a charge's refunds refuses an invalid charge ID before sending anything.
	 */
	public function test_list_charge_refunds_rejects_invalid_charge_id(): void {
		$http_client = new FakeWooPaymentsHttpClient();
		$sut         = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->list_charge_refunds( 'ch_a&charge=ch_b' );
			$this->fail( 'Expected an invalid charge ID to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_route_validation_failure', $exception->get_error_code() );
			$this->assertSame( 0, $http_client->request_count );
		}
	}

	/**
	 * Load one recorded F458 (a) refund list entry by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{request:array{query:array<string,mixed>},response:array{body:array<string,mixed>}}
	 */
	private function load_recorded_refund_list_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/Fixtures/rec-f458-refund-list.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry;
			}
		}

		$this->fail( "REC F458 (a) fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Should reject invalid customer IDs before interpolating customer payment method requests.
	 */
	public function test_get_payment_methods_rejects_invalid_customer_id(): void {
		$sut = new WooPaymentsApiClient();
		$sut->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->get_payment_methods( 'cus-test', 'card' );
			$this->fail( 'Expected invalid customer IDs to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_route_validation_failure', $exception->get_error_code() );
			$this->assertSame( 400, $exception->get_http_code() );
		}
	}

	/**
	 * @testdox Should preserve the Apple Pay payment-method domain registration endpoint and body shape.
	 */
	public function test_register_apple_pay_domain_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'id'        => 'domain_123',
				'apple_pay' => array( 'status' => 'active' ),
			)
		);

		$result = $sut->register_apple_pay_domain( 'example.test' );
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'domain_123', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/payment_method_domains', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'example.test', $body['domain_name'] );
		$this->assertSame( 'true', $body['enabled'] );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should track order payloads through the native transport tracking endpoint.
	 */
	public function test_track_order_posts_to_tracking_order_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'result' => 'success',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->track_order(
			array(
				'id'                 => 42,
				'_payment_method_id' => 'pm_test',
			),
			true
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '/sites/123/wcpay/tracking/order', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 42, $body['order_data']['id'] );
		$this->assertSame( 'pm_test', $body['order_data']['_payment_method_id'] );
		$this->assertTrue( $body['update'] );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should update payment method billing details through the native transport.
	 */
	public function test_update_payment_method_posts_billing_details(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'pm_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->update_payment_method(
			'pm_test',
			array(
				'billing_details' => array(
					'email' => 'ada@example.com',
				),
			)
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'pm_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/payment_methods/pm_test', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'ada@example.com', $body['billing_details']['email'] );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should detach payment methods through the native transport.
	 */
	public function test_detach_payment_method_posts_to_payment_method_detach_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'pm_test' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->detach_payment_method( 'pm_test' );

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'pm_test', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/payment_methods/pm_test/detach', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should retrieve timeline events through the native transport.
	 */
	public function test_get_timeline_reads_timeline_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array(
							'type' => 'captured',
						),
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_timeline( 'pi_test' );

		$this->assertSame( 'captured', $result['data'][0]['type'] );
		$this->assertSame( '/sites/123/wcpay/timeline/pi_test?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
	}

	/**
	 * @testdox Should send store setup snapshots through the native transport.
	 */
	public function test_send_store_setup_posts_snapshot(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'result' => 'ok' ) ),
		);

		$account_service = $this->create_account_service( false, true );
		$sut             = new WooPaymentsApiClient();
		$sut->init( $http_client, $account_service, $this->transport_log() );

		$result = $sut->send_store_setup(
			array(
				'gateway' => array(
					'enabled' => true,
				),
			)
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( array(), $result );
		$this->assertSame( '/sites/123/wcpay/accounts/store_setup', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertFalse( $http_client->last_blocking );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['snapshot']['gateway']['enabled'] );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should update compatibility data through the native transport.
	 */
	public function test_update_compatibility_data_posts_compatibility_payload(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'result' => 'ok' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->update_compatibility_data(
			array(
				'woocommerce_version' => '11.0.0',
			)
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'ok', $result['result'] );
		$this->assertSame( '/sites/123/wcpay/compatibility', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( '11.0.0', $body['compatibility_data']['woocommerce_version'] );
		$this->assertFalse( $body['test_mode'] );
	}

	/**
	 * @testdox Should retrieve WooPay compatibility data through the native transport.
	 */
	public function test_get_woopay_compatibility_reads_woopay_compatibility_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'incompatible_extensions' => array( 'bad-extension' ),
					'adapted_extensions'      => array( 'woocommerce-points-and-rewards' ),
					'available_countries'     => array( 'US', 'BR' ),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'get_woopay_compatibility' ), 'WooPaymentsApiClient should expose get_woopay_compatibility().' );

		$result = $sut->get_woopay_compatibility();

		$this->assertSame( array( 'bad-extension' ), $result['incompatible_extensions'] );
		$this->assertSame( array( 'woocommerce-points-and-rewards' ), $result['adapted_extensions'] );
		$this->assertSame( array( 'US', 'BR' ), $result['available_countries'] );
		$this->assertSame( '/sites/123/wcpay/woopay/compatibility?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertFalse( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should retrieve recommended payment methods through the public recommendations endpoint.
	 */
	public function test_get_recommended_payment_methods_reads_public_recommendations_endpoint(): void {
		$captured_url  = '';
		$captured_args = array();
		$filter        = static function ( $preempt, array $parsed_args, string $url ) use ( &$captured_url, &$captured_args ) {
			$captured_url  = $url;
			$captured_args = $parsed_args;

			// Reduced fixture: the platform returns a JSON list of items carrying id, type, category, title,
			// description, priority, required, icon and an optional notice (wpcom
			// wp-content/rest-api-plugins/endpoints/wcpay/service/class-payment-methods-service.php:179-203,
			// item defaults :345-360, notice :378-390). Only `id` and `title` are kept, the two keys
			// WooPayments 11.1.0 requires of every item (includes/class-wc-payments-account.php:698-713).
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						array(
							'id'    => 'card',
							'title' => 'Cards',
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			$sut    = new WooPaymentsApiClient();
			$result = $sut->get_recommended_payment_methods( 'GB', 'en_US' );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		$this->assertSame( 'card', $result[0]['id'] );
		$this->assertStringStartsWith( 'https://public-api.wordpress.com/wpcom/v2/wcpay/payment_methods/recommended?', $captured_url );
		$this->assertStringContainsString( 'country_code=GB', $captured_url );
		$this->assertStringContainsString( 'locale=en_US', $captured_url );
		$this->assertSame( WooPaymentsClientVersion::get_user_agent(), $captured_args['user-agent'] );
		$this->assertSame( 70, $captured_args['timeout'] );
		$this->assertTrue( $captured_args['sslverify'] );
	}

	/**
	 * The body is the recorded platform response in Fixtures/rec-t63-public-fraud-services.json.
	 *
	 * @testdox Should send the native client identity on the public fraud services request.
	 */
	public function test_fetch_public_fraud_services_config_sends_the_client_identity(): void {
		$captured_url  = '';
		$captured_args = array();
		$filter        = static function ( $preempt, array $parsed_args, string $url ) use ( &$captured_url, &$captured_args ) {
			$captured_url  = $url;
			$captured_args = $parsed_args;

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
				'body'     => '{"stripe":[]}',
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			$result = ( new WooPaymentsApiClient() )->fetch_public_fraud_services_config();
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		$this->assertSame( array( 'stripe' => array() ), $result );
		$this->assertStringEndsWith( '/wpcom/v2/wcpay/accounts/fraud_services', $captured_url );
		$this->assertSame( WooPaymentsClientVersion::get_user_agent(), $captured_args['user-agent'] );
	}

	/**
	 * @testdox Should build recommendation URLs from the Jetpack WPCOM JSON API base.
	 */
	public function test_get_recommended_payment_methods_uses_jetpack_wpcom_api_base(): void {
		$captured_url = '';
		$http_filter  = static function ( $preempt, array $parsed_args, string $url ) use ( &$captured_url ) {
			unset( $parsed_args );
			$captured_url = $url;

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => '[]',
			);
		};

		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		// Set the override rather than filtering the default: Constants reads an
		// override first, a real define() second, and the default-value filter
		// only when neither exists. Filtering was therefore a no-op wherever the
		// constant is actually defined, and it lost to the baseline
		// EnvironmentIsolation sets. This asserts the client follows the
		// configured base, so it has to be the base that actually applies.
		Constants::set_constant( 'JETPACK__WPCOM_JSON_API_BASE', 'http://wpcom.localhost:30001' );

		try {
			$sut = new WooPaymentsApiClient();
			$sut->get_recommended_payment_methods( 'GB', 'en_US' );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			Constants::set_constant( 'JETPACK__WPCOM_JSON_API_BASE', 'https://public-api.wordpress.com' );
		}

		$this->assertStringStartsWith( 'http://wpcom.localhost:30001/wpcom/v2/wcpay/payment_methods/recommended?', $captured_url );
	}

	/**
	 * @testdox Should retrieve onboarding field data through the native transport onboarding endpoint.
	 */
	public function test_get_onboarding_fields_data_reads_onboarding_fields_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'business_types' => array(
						array(
							'key'  => 'individual',
							'name' => 'Individual',
						),
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->get_onboarding_fields_data( 'en_US' );

		$this->assertSame( 'individual', $result['business_types'][0]['key'] );
		$this->assertSame( '/wcpay/onboarding/fields_data?test_mode=1&locale=en_US', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should initialize onboarding through the native onboarding endpoint.
	 */
	public function test_initialize_onboarding_posts_account_payload(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'url'   => 'https://connect.example.test',
					'state' => 'state_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true, null, 'store_123' ), $this->transport_log() );

		$result = $sut->initialize_onboarding(
			false,
			'https://example.test/return',
			array( 'site_locale' => 'en_US' ),
			array( 'email' => 'merchant@example.com' ),
			array( 'business_type' => 'individual' ),
			array( 'wcpay-promo-test' ),
			false,
			'ref_test'
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'state_test', $result['state'] );
		$this->assertSame( '/sites/123/wcpay/onboarding/init', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'https://example.test/return', $body['return_url'] );
		$this->assertFalse( $body['create_live_account'] );
		$this->assertSame( 'en_US', $body['site_data']['site_locale'] );
		$this->assertSame( 'merchant@example.com', $body['user_data']['email'] );
		$this->assertSame( 'individual', $body['account_data']['business_type'] );
		$this->assertSame( array( 'wcpay-promo-test' ), $body['actioned_notes'] );
		$this->assertFalse( $body['collect_payout_requirements'] );
		$this->assertSame( 'ref_test', $body['referral_code'] );
		$this->assertTrue( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assert_store_identity_in_onboarding_payload( $body );
	}

	/**
	 * Assert an onboarding payload carries the store ID and compatibility data, as the client's filter callbacks add them
	 * (client 11.1.0 `includes/class-wc-payments-onboarding-service.php:1491-1496`, `includes/class-compatibility-service.php:75-77`).
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 */
	private function assert_store_identity_in_onboarding_payload( array $body ): void {
		$this->assertSame( 'store_123', $body['woocommerce_store_id'] );
		$this->assertIsArray( $body['compatibility_data'] );
		$this->assertSame( WooPaymentsClientVersion::VERSION, $body['compatibility_data']['woopayments_version'] );
		$this->assertSame( WC_VERSION, $body['compatibility_data']['woocommerce_version'] );
		$this->assertSame( get_stylesheet(), $body['compatibility_data']['blog_theme'] );
	}

	/**
	 * @testdox Should preserve the existing onboarding payload filter for native onboarding.
	 */
	public function test_initialize_onboarding_applies_onboarding_data_args_filter(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'url' => false ) ),
		);
		$filtered_args         = array();
		$filter                = static function ( array $args ) use ( &$filtered_args ): array {
			$filtered_args                                = $args;
			$args['compatibility_data']                   = array( 'woocommerce' => '11.0.0' );
			$args['account_data']['woocommerce_store_id'] = 'store_123';
			return $args;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );
		add_filter( 'wc_payments_get_onboarding_data_args', $filter );

		try {
			$sut->initialize_onboarding(
				false,
				'https://example.test/return',
				array( 'site_locale' => 'en_US' ),
				array( 'email' => 'merchant@example.com' ),
				array( 'business_type' => 'individual' ),
				array(),
				true,
				'ref_test'
			);
		} finally {
			remove_filter( 'wc_payments_get_onboarding_data_args', $filter );
		}

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertIsArray( $body );
		$this->assertSame( 'https://example.test/return', $body['return_url'] );
		$this->assertTrue( $filtered_args['collect_payout_requirements'] );
		$this->assertArrayHasKey( 'woocommerce_store_id', $filtered_args, 'Filter callbacks see the store ID.' );
		$this->assertArrayHasKey( 'compatibility_data', $filtered_args, 'Filter callbacks see the compatibility data.' );
		$this->assertTrue( $body['collect_payout_requirements'] );
		$this->assertSame( array( 'woocommerce' => '11.0.0' ), $body['compatibility_data'] );
		$this->assertSame( 'store_123', $body['account_data']['woocommerce_store_id'] );
		$this->assertSame( 'ref_test', $body['referral_code'] );
	}

	/**
	 * @testdox Should initialize embedded KYC through the native onboarding endpoint.
	 */
	public function test_initialize_onboarding_embedded_kyc_posts_account_payload(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'client_secret'   => 'secret_test',
					'publishable_key' => 'pk_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true, null, 'store_123' ), $this->transport_log() );

		$result = $sut->initialize_onboarding_embedded_kyc(
			true,
			array( 'site_locale' => 'en_US' ),
			array( 'email' => 'merchant@example.com' ),
			array( 'business_type' => 'individual' ),
			array( 'wcpay-promo-test' )
		);

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'secret_test', $result['client_secret'] );
		$this->assertSame( '/sites/123/wcpay/onboarding/embedded', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['create_live_account'] );
		$this->assertSame( 'en_US', $body['site_data']['site_locale'] );
		$this->assertSame( 'merchant@example.com', $body['user_data']['email'] );
		$this->assertSame( 'individual', $body['account_data']['business_type'] );
		$this->assertSame( array( 'wcpay-promo-test' ), $body['actioned_notes'] );
		$this->assertTrue( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assert_store_identity_in_onboarding_payload( $body );
	}

	/**
	 * @testdox Should preserve the existing onboarding payload filter for embedded KYC.
	 */
	public function test_initialize_onboarding_embedded_kyc_applies_onboarding_data_args_filter(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'client_secret' => 'secret_test' ) ),
		);
		$filter                = static function ( array $args ): array {
			$args['compatibility_data']                   = array( 'woocommerce' => '11.0.0' );
			$args['account_data']['woocommerce_store_id'] = 'store_123';
			return $args;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );
		add_filter( 'wc_payments_get_onboarding_data_args', $filter );

		try {
			$sut->initialize_onboarding_embedded_kyc(
				true,
				array( 'site_locale' => 'en_US' ),
				array( 'email' => 'merchant@example.com' ),
				array( 'business_type' => 'individual' ),
				array()
			);
		} finally {
			remove_filter( 'wc_payments_get_onboarding_data_args', $filter );
		}

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertIsArray( $body );
		$this->assertSame( array( 'woocommerce' => '11.0.0' ), $body['compatibility_data'] );
		$this->assertSame( 'store_123', $body['account_data']['woocommerce_store_id'] );
	}

	/**
	 * @testdox Should finalize embedded KYC through the native onboarding endpoint.
	 */
	public function test_finalize_onboarding_embedded_kyc_posts_locale_source_and_notes(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'success' => true,
					'mode'    => 'live',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->finalize_onboarding_embedded_kyc( 'en_US', 'wcadmin-settings-page', array( 'wcpay-promo-test' ) );

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/sites/123/wcpay/onboarding/embedded/finalize', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'en_US', $body['locale'] );
		$this->assertSame( 'wcadmin-settings-page', $body['source'] );
		$this->assertSame( array( 'wcpay-promo-test' ), $body['actioned_notes'] );
		$this->assertFalse( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should delete the connected account through the native accounts endpoint.
	 */
	public function test_delete_account_posts_to_accounts_delete_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'result' => 'success',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->delete_account( true );

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '/sites/123/wcpay/accounts/delete', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should update connected account settings through the native accounts endpoint.
	 */
	public function test_update_account_posts_to_accounts_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->update_account(
			array(
				'statement_descriptor'   => 'NATIVE STORE',
				'business_support_email' => 'support@example.test',
			)
		);
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/sites/123/wcpay/accounts', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'NATIVE STORE', $body['statement_descriptor'] );
		$this->assertSame( 'support@example.test', $body['business_support_email'] );
		$this->assertTrue( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should add account ToS agreements through the user-token accounts endpoint.
	 */
	public function test_add_account_tos_agreement_posts_to_accounts_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'add_account_tos_agreement' ), 'WooPaymentsApiClient should expose add_account_tos_agreement().' );

		$result = $sut->add_account_tos_agreement( 'settings-popup', 'merchant_admin' );
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/sites/123/wcpay/accounts/tos_agreements', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'settings-popup', $body['source'] );
		$this->assertSame( 'merchant_admin', $body['user_name'] );
		$this->assertFalse( $body['test_mode'] );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should request account capabilities through the user-token native endpoint.
	 */
	public function test_request_capability_posts_to_capabilities_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'status' => 'active' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->request_capability( 'link_payments', true );
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( '/sites/123/wcpay/accounts/capabilities', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'link_payments', $body['capability_id'] );
		$this->assertTrue( $body['requested'] );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should forward valid file uploads larger than the WooPay logo UI limit.
	 */
	public function test_upload_file_forwards_valid_files_larger_than_woopay_logo_ui_limit(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'file_dispute_evidence' ) ),
		);
		$tmp_file              = tempnam( sys_get_temp_dir(), 'wcpay-large-file-' );
		$this->assertIsString( $tmp_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture temp file.
		file_put_contents( $tmp_file, str_repeat( 'x', 510001 ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/file' );
		$request->set_param( 'purpose', 'dispute_evidence' );
		$request->set_param( 'as_account', true );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'large-evidence.pdf',
					'type'     => 'application/pdf',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 510001,
				),
			)
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$result = $sut->upload_file( $request );
			$body   = json_decode( (string) $http_client->last_body, true );

			$this->assertSame( 'file_dispute_evidence', $result['id'] );
			$this->assertSame( '/sites/123/wcpay/files', $http_client->last_path );
			$this->assertSame( 'POST', $http_client->last_method );
			$this->assertIsArray( $body );
			$this->assertSame( base64_encode( str_repeat( 'x', 510001 ) ), $body['file'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected provider payload encoding.
			$this->assertSame( 'large-evidence.pdf', $body['file_name'] );
			$this->assertSame( 'application/pdf', $body['file_type'] );
			$this->assertSame( 'dispute_evidence', $body['purpose'] );
			$this->assertTrue( $body['as_account'] );
		} finally {
			wp_delete_file( $tmp_file );
		}
	}

	/**
	 * @testdox Should wrap provider file upload failures with the preserved evidence upload code.
	 */
	public function test_upload_file_wraps_provider_failures_with_evidence_upload_error_code(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 413 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 'file_too_large',
						'message' => 'The uploaded file is too large.',
					),
				)
			),
		);
		$tmp_file              = tempnam( sys_get_temp_dir(), 'wcpay-upload-error-' );
		$this->assertIsString( $tmp_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture temp file.
		file_put_contents( $tmp_file, 'evidence' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/file' );
		$request->set_param( 'purpose', 'dispute_evidence' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'evidence.pdf',
					'type'     => 'application/pdf',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 8,
				),
			)
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->upload_file( $request );
			$this->fail( 'Expected the provider file upload failure to be wrapped.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_evidence_file_upload_error', $exception->get_error_code() );
			$this->assertSame( 413, $exception->get_http_code() );
			$this->assertStringContainsString( 'The uploaded file is too large.', $exception->getMessage() );
		} finally {
			wp_delete_file( $tmp_file );
		}
	}

	/**
	 * @testdox Should fetch file details and contents through native file endpoints.
	 */
	public function test_get_file_details_and_contents_use_native_file_endpoints(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'      => 'file_logo',
					'purpose' => 'business_logo',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$file  = $sut->get_file( 'file_logo', false );
		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( 'file_logo', $file['id'] );
		$this->assertSame( '/sites/123/wcpay/files/file_logo', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( '0', $query['as_account'] );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( 'GET', $http_client->last_method );

		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				)
			),
		);

		$contents = $sut->get_file_contents( 'file_logo', false );
		$query    = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( 'image/png', $contents['content_type'] );
		$this->assertSame( 'TE9HTw==', $contents['file_content'] );
		$this->assertSame( '/sites/123/wcpay/files/file_logo/contents', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( '0', $query['as_account'] );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should save fraud rulesets through the native fraud ruleset endpoint.
	 */
	public function test_save_fraud_ruleset_posts_to_fraud_ruleset_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->save_fraud_ruleset(
			array(
				array(
					'key'     => 'avs_verification',
					'outcome' => 'block',
				),
			)
		);
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/sites/123/wcpay/fraud_ruleset', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 'avs_verification', $body['ruleset_config'][0]['key'] );
	}

	/**
	 * @testdox Should read the latest fraud ruleset through the native fraud ruleset endpoint.
	 */
	public function test_get_latest_fraud_ruleset_reads_from_fraud_ruleset_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'ruleset_config' => array(
						array(
							'key'     => 'avs_verification',
							'outcome' => 'block',
						),
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_latest_fraud_ruleset();

		$this->assertSame(
			array(
				array(
					'key'     => 'avs_verification',
					'outcome' => 'block',
				),
			),
			$result['ruleset_config']
		);
		$this->assertSame( '/sites/123/wcpay/fraud_ruleset?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should fetch account data through the native accounts endpoint with the WooCommerce store ID.
	 */
	public function test_get_account_fetches_accounts_endpoint_with_store_id(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'account_id'           => 'acct_native_123',
					'test_publishable_key' => 'pk_test_native',
					'is_live'              => false,
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false, true ), $this->transport_log() );

		$result = $sut->get_account( 'store_123' );

		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( 'acct_native_123', $result['account_id'] );
		$this->assertSame( '/sites/123/wcpay/accounts', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( 'store_123', $query['woocommerce_store_id'] );
		$this->assertSame( '1', $query['test_mode'] );
		$this->assertFalse( $http_client->last_use_user_token );
		$this->assertNull( $http_client->last_body );
	}

	/**
	 * @testdox Should fetch failed webhook events through the native WooPayments endpoint.
	 */
	public function test_get_failed_webhook_events_posts_to_failed_events_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data'     => array(
						array(
							'id'   => 'evt_failed_1',
							'type' => 'payment_intent.succeeded',
						),
					),
					'has_more' => true,
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->get_failed_webhook_events();
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/webhook/failed_events', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['test_mode'] );
		$this->assertSame( 'evt_failed_1', $result['data'][0]['id'] );
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * @testdox Should create terminal connection tokens through the preserved WPCOM endpoint.
	 */
	public function test_create_terminal_connection_token_posts_to_terminal_connection_tokens_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'secret' => 'cnctok_test_secret',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->create_terminal_connection_token();
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/terminal/connection_tokens', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'cnctok_test_secret', $result['secret'] );
		$this->assertIsArray( $body );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox Should create address autocomplete tokens through the preserved WPCOM endpoint.
	 */
	public function test_get_address_autocomplete_token_posts_to_address_autocomplete_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'token' => 'address.jwt.token',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'get_address_autocomplete_token' ), 'WooPaymentsApiClient should expose get_address_autocomplete_token().' );

		$result = $sut->get_address_autocomplete_token();
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/address-autocomplete-token', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( 'address.jwt.token', $result['token'] );
		$this->assertIsArray( $body );
		$this->assertFalse( $body['test_mode'] );
	}

	/**
	 * @testdox Should create terminal intents through the native intentions endpoint.
	 */
	public function test_create_terminal_payment_intention_posts_terminal_payment_payload(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_terminal',
					'status' => 'requires_capture',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->create_terminal_payment_intention(
			array(
				'amount'               => 1234,
				'currency'             => 'usd',
				'capture_method'       => 'manual',
				'metadata'             => array( 'order_number' => '100' ),
				'payment_method_types' => array( 'card_present' ),
			)
		);
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'pi_terminal', $result['id'] );
		$this->assertSame( '/sites/123/wcpay/intentions', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 1234, $body['amount'] );
		$this->assertSame( 'manual', $body['capture_method'] );
		$this->assertSame( array( 'card_present' ), $body['payment_method_types'] );
	}

	/**
	 * @testdox Should prepare terminal payments through the preserved intent subresource.
	 */
	public function test_prepare_terminal_payment_posts_to_intent_prepare_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			// The platform answers with the PaymentIntent, which it prepares only before confirmation (wpcom class-intentions-controller.php:626, :660).
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_terminal',
					'object' => 'payment_intent',
					'status' => 'requires_confirmation',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->prepare_terminal_payment( 'pi_terminal', 42 );
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/intentions/pi_terminal/prepare_terminal_payment', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		$this->assertSame( 42, $body['order_id'] );
		$this->assertSame( 'requires_confirmation', $result['status'] );
	}

	/**
	 * @testdox Should proxy terminal reader registration and location operations through preserved endpoints.
	 */
	public function test_terminal_reader_and_location_methods_use_preserved_endpoints(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id' => 'tmr_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$sut->register_terminal_reader( 'tml_test', 'code_123', 'Counter', array( 'channel' => 'pos' ) );
		$reader_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/terminal/readers', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $reader_body );
		$this->assertSame( 'tml_test', $reader_body['location'] );
		$this->assertSame( 'code_123', $reader_body['registration_code'] );
		$this->assertSame( 'Counter', $reader_body['label'] );
		$this->assertSame( array( 'channel' => 'pos' ), $reader_body['metadata'] );

		$sut->create_terminal_location(
			'Store',
			array(
				'country' => 'US',
				'line1'   => '123 Main',
			),
			array( 'source' => 'native' )
		);
		$location_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/terminal/locations', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $location_body );
		$this->assertSame( 'Store', $location_body['display_name'] );
		$this->assertSame( 'US', $location_body['address']['country'] );
		$this->assertSame( array( 'source' => 'native' ), $location_body['metadata'] );
	}

	/**
	 * @testdox Should retrieve terminal readers through the preserved GET endpoint.
	 */
	public function test_get_terminal_readers_uses_preserved_get_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'data' => array() ) );

		$sut->get_terminal_readers();

		$this->assertSame( '/sites/123/wcpay/terminal/readers?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve terminal locations through the preserved GET endpoint.
	 */
	public function test_get_terminal_locations_uses_preserved_get_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'data' => array() ) );

		$sut->get_terminal_locations();

		$this->assertSame( '/sites/123/wcpay/terminal/locations?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve a single terminal location through the preserved GET endpoint.
	 */
	public function test_get_terminal_location_uses_preserved_get_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'data' => array() ) );

		$sut->get_terminal_location( 'tml_test' );

		$this->assertSame( '/sites/123/wcpay/terminal/locations/tml_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve the readers charge summary through the preserved GET endpoint with query names.
	 */
	public function test_get_readers_charge_summary_uses_preserved_get_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'data' => array() ) );

		$sut->get_readers_charge_summary( '2026-06-17', 'txn_test' );

		$this->assertStringStartsWith( '/sites/123/wcpay/reader-charges/summary?', $http_client->last_path );
		$this->assertStringContainsString( 'test_mode=1', $http_client->last_path );
		$this->assertStringContainsString( 'charge_date=2026-06-17', $http_client->last_path );
		$this->assertStringContainsString( 'transaction_id=txn_test', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve a single transaction through the preserved GET endpoint.
	 */
	public function test_get_transaction_uses_preserved_get_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'data' => array() ) );

		$sut->get_transaction( 'txn_test' );

		$this->assertSame( '/sites/123/wcpay/transactions/txn_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should update and delete terminal locations through preserved resource endpoints.
	 */
	public function test_terminal_location_mutations_use_preserved_resource_endpoints(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id' => 'tml_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$sut->update_terminal_location( 'tml_test', 'Updated', array( 'line1' => '456 Market' ) );
		$update_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/terminal/locations/tml_test', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $update_body );
		$this->assertSame( 'Updated', $update_body['display_name'] );
		$this->assertSame( '456 Market', $update_body['address']['line1'] );

		$sut->delete_terminal_location( 'tml_test' );

		$this->assertSame( '/sites/123/wcpay/terminal/locations/tml_test?test_mode=0', $http_client->last_path );
		$this->assertSame( 'DELETE', $http_client->last_method );
	}

	/**
	 * @testdox Should send DELETE parameters in the query string with no request body, so the Jetpack signature verifies.
	 */
	public function test_delete_request_sends_params_in_query_string_without_body(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'deleted' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->delete_terminal_location( 'tml_test' );

		$this->assertSame( '/sites/123/wcpay/terminal/locations/tml_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'DELETE', $http_client->last_method );
		$this->assertNull( $http_client->last_body, 'A DELETE body would be signed with a body-hash the platform rejects for non-POST/PUT/PATCH methods.' );
	}

	/**
	 * @testdox Should retrieve dispute summary through the preserved disputes endpoint.
	 */
	public function test_get_dispute_summary_uses_preserved_disputes_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'disputed_amount' => 500,
					'currency'        => 'usd',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$result = $sut->get_dispute_summary( 'du_test' );

		$this->assertSame( '/sites/123/wcpay/disputes/du_test/summary?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( 500, $result['disputed_amount'] );
		$this->assertSame( 'usd', $result['currency'] );
	}

	/**
	 * @testdox Should reject invalid dispute summary route identifiers.
	 */
	public function test_get_dispute_summary_rejects_invalid_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_dispute_summary( '../du_test' );
	}

	/**
	 * @testdox Should accept hyphenated route identifiers when interpolating resource paths.
	 */
	public function test_get_charge_accepts_hyphenated_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'id' => 'ch_abc-123' ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_charge( 'ch_abc-123' );

		$this->assertSame( '/sites/123/wcpay/charges/ch_abc-123?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( 'ch_abc-123', $result['id'] );
	}

	/**
	 * @testdox Should reject empty route identifiers before path interpolation.
	 */
	public function test_get_charge_rejects_empty_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_charge( '' );
	}

	/**
	 * @testdox Should retrieve the payouts overview through the preserved deposits endpoint.
	 */
	public function test_get_deposits_overview_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_deposits_overview();

		$this->assertSame( '/sites/123/wcpay/deposits/overview-all?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve the payouts list through the preserved deposits endpoint with query names.
	 */
	public function test_get_deposits_uses_preserved_endpoint_and_query_names(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_deposits(
			array(
				'page'              => 2,
				'pagesize'          => 25,
				'sort'              => 'date',
				'direction'         => 'desc',
				'store_currency_is' => 'usd',
				'status_is'         => 'paid',
			)
		);

		$this->assertStringStartsWith( '/sites/123/wcpay/deposits?', $http_client->last_path );
		$this->assertStringContainsString( 'test_mode=1', $http_client->last_path );
		$this->assertStringContainsString( 'page=2', $http_client->last_path );
		$this->assertStringContainsString( 'pagesize=25', $http_client->last_path );
		$this->assertStringContainsString( 'sort=date', $http_client->last_path );
		$this->assertStringContainsString( 'direction=desc', $http_client->last_path );
		$this->assertStringContainsString( 'store_currency_is=usd', $http_client->last_path );
		$this->assertStringContainsString( 'status_is=paid', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve the payouts summary through the preserved deposits endpoint with query names.
	 */
	public function test_get_deposits_summary_uses_preserved_endpoint_and_query_names(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_deposits_summary(
			array(
				'store_currency_is' => 'usd',
				'status_is_not'     => 'failed',
			)
		);

		$this->assertStringStartsWith( '/sites/123/wcpay/deposits/summary?', $http_client->last_path );
		$this->assertStringContainsString( 'test_mode=1', $http_client->last_path );
		$this->assertStringContainsString( 'store_currency_is=usd', $http_client->last_path );
		$this->assertStringContainsString( 'status_is_not=failed', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve payout details and reject unsafe payout identifiers.
	 */
	public function test_get_deposit_uses_preserved_detail_endpoint_and_validates_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id' => 'po_test',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_deposit( 'po_test' );

		$this->assertSame( '/sites/123/wcpay/deposits/po_test?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( 'po_test', $result['id'] );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_deposit( '../po_test' );
	}

	/**
	 * @testdox Should preserve the deposits export endpoint and body fields.
	 */
	public function test_get_deposits_export_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'exported_deposits' => 42 ) );

		$sut->get_deposits_export(
			array(
				'status_is'         => 'paid',
				'store_currency_is' => 'usd',
			),
			'merchant@example.com',
			'en_US'
		);
		$export_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/deposits/download', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $export_body );
		$this->assertTrue( $export_body['test_mode'] );
		$this->assertSame( 'paid', $export_body['status_is'] );
		$this->assertSame( 'usd', $export_body['store_currency_is'] );
		$this->assertSame( 'merchant@example.com', $export_body['user_email'] );
		$this->assertSame( 'en_US', $export_body['locale'] );
	}

	/**
	 * @testdox Should preserve the payouts export URL endpoint.
	 */
	public function test_get_payouts_export_url_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'exported_deposits' => 42 ) );

		$sut->get_payouts_export_url( 'poexp_test' );

		$this->assertSame( '/sites/123/wcpay/deposits/download/poexp_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the manual payout endpoint and body fields.
	 */
	public function test_manual_deposit_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array( 'exported_deposits' => 42 ) );

		$sut->manual_deposit( 'instant', 'usd' );
		$deposit_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/deposits', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $deposit_body );
		$this->assertSame( 'instant', $deposit_body['type'] );
		$this->assertSame( 'usd', $deposit_body['currency'] );
	}

	/**
	 * @testdox Should retrieve authorizations through the preserved list endpoint and request hook.
	 */
	public function test_get_authorizations_preserves_list_endpoint_and_request_hook(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array( 'payment_intent_id' => 'pi_auth' ),
					),
				)
			),
		);
		$observed_request      = null;
		$filter                = static function ( WooPaymentsAuthorizationsListRequest $request ) use ( &$observed_request ): WooPaymentsAuthorizationsListRequest {
			$observed_request = $request;
			$request->set_param( 'pagesize', 50 );
			$request->set_param( 'customer_email_is', 'ada@example.com' );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'get_authorizations' ), 'WooPaymentsApiClient should expose get_authorizations().' );

		add_filter( 'wcpay_list_authorizations_request', $filter );

		try {
			$result = $sut->get_authorizations(
				array(
					'page'      => 2,
					'pagesize'  => 25,
					'sort'      => 'created',
					'direction' => 'desc',
				)
			);
		} finally {
			remove_filter( 'wcpay_list_authorizations_request', $filter );
		}

		$this->assertSame( 'pi_auth', $result['data'][0]['payment_intent_id'] );
		$this->assertInstanceOf( WooPaymentsAuthorizationsListRequest::class, $observed_request );
		$this->assertSame( 'authorizations', $observed_request->get_api() );
		$this->assertSame( 'GET', $observed_request->get_method() );
		$this->assertSame( '/sites/123/wcpay/authorizations?test_mode=0&page=2&pagesize=50&sort=created&direction=desc&limit=100&customer_email_is=ada%40example.com', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
	}

	/**
	 * @testdox Should retrieve a single authorization through the preserved detail endpoint and request hook.
	 */
	public function test_get_authorization_preserves_detail_endpoint_and_request_hook(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'payment_intent_id' => 'pi_auth',
					'is_captured'       => false,
				)
			),
		);
		$observed_request      = null;
		$filter                = static function ( WooPaymentsApiRequest $request ) use ( &$observed_request ): WooPaymentsApiRequest {
			$observed_request = $request;

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'get_authorization' ), 'WooPaymentsApiClient should expose get_authorization().' );

		add_filter( 'wcpay_get_authorization_request', $filter );

		try {
			$result = $sut->get_authorization( 'pi_auth' );
		} finally {
			remove_filter( 'wcpay_get_authorization_request', $filter );
		}

		$this->assertSame( 'pi_auth', $result['payment_intent_id'] );
		$this->assertInstanceOf( WooPaymentsApiRequest::class, $observed_request );
		$this->assertSame( 'authorizations/pi_auth', $observed_request->get_api() );
		$this->assertSame( 'GET', $observed_request->get_method() );
		$this->assertSame( '/sites/123/wcpay/authorizations/pi_auth?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_authorization( '../pi_auth' );
	}

	/**
	 * @testdox Should preserve authorization summary filters and the legacy summary hook.
	 */
	public function test_get_authorizations_summary_preserves_filters_and_legacy_hook(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'count' => 3,
				)
			),
		);
		$observed_request      = null;
		$filter                = static function ( WooPaymentsApiRequest $request ) use ( &$observed_request ): WooPaymentsApiRequest {
			$observed_request = $request;
			$request->set_param( 'pagesize', 50 );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );
		add_filter( 'wc_pay_get_authorizations_summary', $filter );

		try {
			$result = $sut->get_authorizations_summary(
				array(
					'page'     => 2,
					'pagesize' => 25,
				)
			);
		} finally {
			remove_filter( 'wc_pay_get_authorizations_summary', $filter );
		}

		$this->assertSame( 3, $result['count'] );
		$this->assertInstanceOf( WooPaymentsApiRequest::class, $observed_request );
		$this->assertSame( 'authorizations/summary', $observed_request->get_api() );
		$this->assertSame( '/sites/123/wcpay/authorizations/summary?test_mode=1&page=2&pagesize=50', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should retrieve Capital active loan summary and loans through preserved endpoints.
	 */
	public function test_capital_admin_methods_use_preserved_endpoints(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->get_capital_active_loan_summary();

		$this->assertSame( '/sites/123/wcpay/capital/active_loan_summary?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );

		$sut->get_capital_loans();

		$this->assertSame( '/sites/123/wcpay/capital/loans?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertNull( $http_client->last_body );
	}

	/**
	 * @testdox Should create Capital financing offer links through the preserved accounts endpoint.
	 */
	public function test_create_capital_link_posts_financing_offer_payload_to_capital_links_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'url' => 'https://capital.example.test/view-offer',
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'create_capital_link' ), 'WooPaymentsApiClient should expose create_capital_link().' );

		$result = $sut->create_capital_link(
			'https://example.test/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
			'https://example.test/wp-admin/admin.php?wcpay-loan-offer'
		);
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( 'https://capital.example.test/view-offer', $result['url'] );
		$this->assertSame( '/sites/123/wcpay/accounts/capital_links?test_mode=1', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assertSame(
			array(
				'type'        => 'capital_financing_offer',
				'return_url'  => 'https://example.test/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
				'refresh_url' => 'https://example.test/wp-admin/admin.php?wcpay-loan-offer',
			),
			$body
		);
	}

	/**
	 * @testdox Should preserve legacy Capital request filters before dispatch.
	 */
	public function test_capital_admin_methods_preserve_legacy_request_filters(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(),
				)
			),
		);

		$summary_filter = function ( WooPaymentsApiRequest $request ): WooPaymentsApiRequest {
			$this->assertSame( 'capital/active_loan_summary', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'include', 'details' );

			return $request;
		};
		$loans_filter   = function ( WooPaymentsApiRequest $request ): WooPaymentsApiRequest {
			$this->assertSame( 'capital/loans', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'limit', 25 );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );
		add_filter( 'wcpay_get_active_loan_summary_request', $summary_filter );
		add_filter( 'wcpay_get_loans_request', $loans_filter );

		try {
			$sut->get_capital_active_loan_summary();
			$summary_path = $http_client->last_path;

			$sut->get_capital_loans();
			$loans_path = $http_client->last_path;
		} finally {
			remove_filter( 'wcpay_get_active_loan_summary_request', $summary_filter );
			remove_filter( 'wcpay_get_loans_request', $loans_filter );
		}

		$this->assertStringContainsString( 'include=details', $summary_path );
		$this->assertStringContainsString( 'test_mode=1', $summary_path );
		$this->assertStringContainsString( 'limit=25', $loans_path );
		$this->assertStringContainsString( 'test_mode=1', $loans_path );
	}

	/**
	 * @testdox Should preserve the legacy Capital link request filter object.
	 */
	public function test_create_capital_link_preserves_legacy_request_filter_object(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'url' => 'https://capital.example.test/filtered-offer',
				)
			),
		);

		$observed_request = null;
		$filter           = function ( WooPaymentsGetAccountCapitalLinkRequest $request ) use ( &$observed_request ): WooPaymentsGetAccountCapitalLinkRequest {
			$observed_request = $request;

			$this->assertSame( 'accounts/capital_links', $request->get_api() );
			$this->assertSame( 'POST', $request->get_method() );
			$this->assertTrue( $request->should_use_user_token() );
			$this->assertSame( 'capital_financing_offer', $request->get_param( 'type' ) );
			$this->assertSame( 'https://example.test/return', $request->get_param( 'return_url' ) );
			$this->assertSame( 'https://example.test/refresh', $request->get_param( 'refresh_url' ) );
			$request->set_type( 'filtered_capital_financing_offer' );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$this->assertTrue( method_exists( $sut, 'create_capital_link' ), 'WooPaymentsApiClient should expose create_capital_link().' );

		add_filter( 'wcpay_get_account_capital_link', $filter );

		try {
			$sut->create_capital_link( 'https://example.test/return', 'https://example.test/refresh' );
		} finally {
			remove_filter( 'wcpay_get_account_capital_link', $filter );
		}

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertInstanceOf( WooPaymentsApiRequest::class, $observed_request );
		$this->assertSame( 'filtered_capital_financing_offer', $body['type'] );
		$this->assertSame( '/sites/123/wcpay/accounts/capital_links?test_mode=1', $http_client->last_path );
		$this->assertTrue( $http_client->last_use_user_token );
	}

	/**
	 * @testdox Should pass the request object to the Capital request filters.
	 */
	public function test_capital_admin_methods_pass_the_request_object_to_their_filters(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(),
				)
			),
		);

		$observed_summary_request = null;
		$observed_loans_request   = null;

		$summary_filter = function ( WooPaymentsApiRequest $request ) use ( &$observed_summary_request ): WooPaymentsApiRequest {
			$observed_summary_request = $request;
			$this->assertSame( 'capital/active_loan_summary', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'include', 'details' );

			return $request;
		};
		$loans_filter   = function ( WooPaymentsApiRequest $request ) use ( &$observed_loans_request ): WooPaymentsApiRequest {
			$observed_loans_request = $request;
			$this->assertSame( 'capital/loans', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'limit', 25 );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );
		add_filter( 'wcpay_get_active_loan_summary_request', $summary_filter );
		add_filter( 'wcpay_get_loans_request', $loans_filter );

		try {
			$sut->get_capital_active_loan_summary();
			$summary_path = $http_client->last_path;
			$sut->get_capital_loans();
			$loans_path = $http_client->last_path;
		} finally {
			remove_filter( 'wcpay_get_active_loan_summary_request', $summary_filter );
			remove_filter( 'wcpay_get_loans_request', $loans_filter );
		}

		$this->assertInstanceOf( WooPaymentsApiRequest::class, $observed_summary_request );
		$this->assertInstanceOf( WooPaymentsApiRequest::class, $observed_loans_request );
		$this->assertStringContainsString( 'include=details', $summary_path );
		$this->assertStringContainsString( 'test_mode=1', $summary_path );
		$this->assertStringContainsString( 'limit=25', $loans_path );
		$this->assertStringContainsString( 'test_mode=1', $loans_path );
	}

	/**
	 * @testdox Should preserve the transactions list endpoint and query names.
	 */
	public function test_get_transactions_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_transactions(
			array(
				'page'       => 2,
				'pagesize'   => 25,
				'deposit_id' => 'po_test',
			)
		);

		$this->assertStringStartsWith( '/sites/123/wcpay/transactions?', $http_client->last_path );
		$this->assertStringContainsString( 'test_mode=1', $http_client->last_path );
		$this->assertStringContainsString( 'page=2', $http_client->last_path );
		$this->assertStringContainsString( 'pagesize=25', $http_client->last_path );
		$this->assertStringContainsString( 'deposit_id=po_test', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the transactions summary endpoint and query names.
	 */
	public function test_get_transactions_summary_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_transactions_summary( array( 'store_currency_is' => 'usd' ), 'po_test' );

		$this->assertStringStartsWith( '/sites/123/wcpay/transactions/summary?', $http_client->last_path );
		$this->assertStringContainsString( 'store_currency_is=usd', $http_client->last_path );
		$this->assertStringContainsString( 'deposit_id=po_test', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the transactions search autocomplete endpoint.
	 */
	public function test_get_transactions_search_autocomplete_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				array(
					'customer_name'  => 'Ada Lovelace',
					'customer_email' => 'ada@example.com',
				),
			)
		);

		$sut->get_transactions_search_autocomplete( 'Ada' );

		$this->assertStringStartsWith( '/sites/123/wcpay/transactions/search?', $http_client->last_path );
		$this->assertStringContainsString( 'search_term=Ada', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the fraud outcomes endpoint and move status into the path.
	 */
	public function test_get_fraud_outcomes_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_fraud_outcomes(
			array(
				'status'      => 'review',
				'search_term' => 'Ada',
			)
		);

		$this->assertStringStartsWith( '/sites/123/wcpay/fraud_outcomes/status/review?', $http_client->last_path );
		$this->assertStringContainsString( 'search_term=Ada', $http_client->last_path );
		$this->assertStringNotContainsString( 'status=review', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the latest fraud outcome endpoint and unwrap the first response item.
	 */
	public function test_get_latest_fraud_outcome_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				array(
					'id'      => 'fo_latest',
					'outcome' => 'review',
				),
				array(
					'id'      => 'fo_previous',
					'outcome' => 'allow',
				),
			)
		);

		$response = $sut->get_latest_fraud_outcome( 'pi_test' );

		$this->assertSame( '/sites/123/wcpay/fraud_outcomes/order_id/pi_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame(
			array(
				'id'      => 'fo_latest',
				'outcome' => 'review',
			),
			$response
		);
	}

	/**
	 * @testdox Should preserve empty latest fraud outcome responses.
	 */
	public function test_get_latest_fraud_outcome_preserves_empty_response(): void {
		list( $sut, $http_client ) = $this->make_sut( true, array() );

		$response = $sut->get_latest_fraud_outcome( 'pi_test' );

		$this->assertSame( '/sites/123/wcpay/fraud_outcomes/order_id/pi_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( array(), $response );
	}

	/**
	 * @testdox Should preserve the single-transaction detail endpoint in admin context.
	 */
	public function test_get_transaction_admin_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_transaction( 'txn_test' );

		$this->assertSame( '/sites/123/wcpay/transactions/txn_test?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the transactions export endpoint and body fields.
	 */
	public function test_get_transactions_export_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_transactions_export( array( 'type_is' => 'charge' ), 'merchant@example.com', 'po_test', 'en_US' );
		$export_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/transactions/download', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $export_body );
		$this->assertSame( 'charge', $export_body['type_is'] );
		$this->assertSame( 'merchant@example.com', $export_body['user_email'] );
		$this->assertSame( 'po_test', $export_body['deposit_id'] );
		$this->assertSame( 'en_US', $export_body['locale'] );
	}

	/**
	 * @testdox Should preserve the transactions export URL endpoint.
	 */
	public function test_get_transactions_export_url_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'data'        => array(),
				'total_count' => 0,
			)
		);

		$sut->get_transactions_export_url( 'txexp-test.01==' );

		$this->assertSame( '/sites/123/wcpay/transactions/download/txexp-test.01==?test_mode=1', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * Build a fresh API client and fake transport for disputes admin endpoint tests.
	 *
	 * @return array{0: WooPaymentsApiClient, 1: FakeWooPaymentsHttpClient}
	 */
	private function make_disputes_sut(): array {
		return $this->make_sut(
			false,
			array(
				'id'     => 'dp_test',
				'reason' => 'fraudulent',
			)
		);
	}

	/**
	 * @testdox Should preserve the disputes list endpoint and query names.
	 */
	public function test_get_disputes_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->get_disputes( array( 'status_is' => 'needs_response' ) );

		$this->assertStringStartsWith( '/sites/123/wcpay/disputes?', $http_client->last_path );
		$this->assertStringContainsString( 'test_mode=0', $http_client->last_path );
		$this->assertStringContainsString( 'status_is=needs_response', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the disputes summary endpoint and query names.
	 */
	public function test_get_disputes_summary_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->get_disputes_summary( array( 'currency_is' => 'usd' ) );

		$this->assertStringStartsWith( '/sites/123/wcpay/disputes/summary?', $http_client->last_path );
		$this->assertStringContainsString( 'currency_is=usd', $http_client->last_path );
		$this->assertStringNotContainsString( '0%5Bcurrency_is%5D', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the single-dispute detail endpoint.
	 */
	public function test_get_dispute_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->get_dispute( 'dp_test' );

		$this->assertSame( '/sites/123/wcpay/disputes/dp_test?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve the dispute update endpoint and body fields.
	 */
	public function test_update_dispute_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->update_dispute( 'dp_test', array( 'customer_name' => 'Ada' ), true, array( 'order_id' => 123 ) );
		$update_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/disputes/dp_test', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $update_body );
		$this->assertArrayHasKey( 'evidence', $update_body );
		$this->assertSame( 'Ada', $update_body['evidence']['customer_name'] );
		$this->assertTrue( $update_body['submit'] );
		$this->assertArrayHasKey( 'metadata', $update_body );
		$this->assertSame( 123, $update_body['metadata']['order_id'] );
	}

	/**
	 * @testdox Should preserve the dispute close endpoint.
	 */
	public function test_close_dispute_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->close_dispute( 'dp_test' );

		$this->assertSame( '/sites/123/wcpay/disputes/dp_test/close', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
	}

	/**
	 * @testdox Closing a dispute posts the recorded body: only `test_mode`, never an empty body.
	 *
	 * REC-5b R-d `accept_close` (`Fixtures/rec-5b-disputes.json`): the caller passes
	 * no fields, but the wire body is not literally empty. {@see WooPaymentsApiClient::request()}
	 * always merges in `test_mode`, so `close_dispute()` posts exactly `{"test_mode": true}`
	 * (client `includes/admin/class-wc-rest-payments-disputes-controller.php:164-167`, `api:748-757`).
	 */
	public function test_close_dispute_posts_to_close_route_with_recorded_body(): void {
		$recorded              = $this->load_recorded_dispute_entry( 'accept_close' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => $recorded['http_status'] ),
			'headers'  => array( 'content-type' => $recorded['content_type'] ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->close_dispute( 'du_1UJbToBzWlxcwgpP9bUeBTQY' );

		$this->assertSame( '/sites/123/wcpay/disputes/du_1UJbToBzWlxcwgpP9bUeBTQY/close', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );

		$sent = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $sent );
		$this->assertCount( 1, $sent, 'The close-dispute wire body must carry only test_mode, never an empty body and never evidence fields.' );
		$this->assertArrayHasKey( 'test_mode', $sent );
		$this->assertTrue( $sent['test_mode'] );
	}

	/**
	 * @testdox Updating a dispute reads it first, then posts evidence, submit and metadata over a second request.
	 *
	 * REC-5b R-d `win_update_pre_read`/`win_update_submit` (`Fixtures/rec-5b-disputes.json`):
	 * `update_dispute()` makes two transport calls in order, a GET then a POST
	 * (client `api:689-722`). With reason `fraudulent` (not `noncompliant`) the
	 * Visa `enhanced_evidence` flag is never added, and `submit` travels as a JSON
	 * boolean, not a string.
	 */
	public function test_update_dispute_reads_dispute_then_posts_evidence_submit_and_metadata(): void {
		$pre_read               = $this->load_recorded_dispute_entry( 'win_update_pre_read' );
		$submit                 = $this->load_recorded_dispute_entry( 'win_update_submit' );
		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			array(
				'response' => array( 'code' => $pre_read['http_status'] ),
				'headers'  => array( 'content-type' => $pre_read['content_type'] ),
				'body'     => wp_json_encode( $pre_read['body'] ),
			),
			array(
				'response' => array( 'code' => $submit['http_status'] ),
				'headers'  => array( 'content-type' => $submit['content_type'] ),
				'body'     => wp_json_encode( $submit['body'] ),
			),
		);
		$sut                    = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->update_dispute(
			'du_1UJbTtBzWlxcwgpPSQwkqPRE',
			array(
				'product_description' => 'REC-5b recording: one-off consulting session delivered online.',
				'uncategorized_text'  => 'winning_evidence',
			),
			true,
			array( '__product_type' => 'offline_service' )
		);

		$this->assertSame( 2, $http_client->request_count, 'update_dispute() must make exactly two transport calls: a GET, then a POST.' );
		$this->assertSame( 'GET', $http_client->requests[0]['method'] );
		$this->assertStringStartsWith( '/sites/123/wcpay/disputes/du_1UJbTtBzWlxcwgpPSQwkqPRE?', $http_client->requests[0]['path'] );
		$this->assertSame( 'POST', $http_client->requests[1]['method'] );
		$this->assertSame( '/sites/123/wcpay/disputes/du_1UJbTtBzWlxcwgpPSQwkqPRE', $http_client->requests[1]['path'] );

		$sent = json_decode( (string) $http_client->requests[1]['body'], true );
		$this->assertIsArray( $sent );
		$this->assertSame( array( 'test_mode', 'evidence', 'submit', 'metadata' ), array_keys( $sent ), 'The update-dispute wire body must carry only these four keys; a noncompliant enhanced_evidence flag must not appear for a fraudulent dispute.' );
		$this->assertTrue( $sent['test_mode'] );
		$this->assertSame( 'REC-5b recording: one-off consulting session delivered online.', $sent['evidence']['product_description'] );
		$this->assertSame( 'winning_evidence', $sent['evidence']['uncategorized_text'] );
		$this->assertArrayNotHasKey( 'enhanced_evidence', $sent['evidence'] );
		$this->assertTrue( $sent['submit'] );
		$this->assertSame( array( '__product_type' => 'offline_service' ), $sent['metadata'] );
	}

	/**
	 * @testdox Updating a noncompliant dispute adds the Visa compliance enhanced-evidence flag the pre-read reason requires.
	 *
	 * REC-5b never recorded a `noncompliant` dispute (the test card used to record
	 * R-d always produces `fraudulent`, per `data/rec-5b-disputes.md`). This test
	 * takes the recorded `win_update_pre_read` GET response and overrides only its
	 * `reason` field to `noncompliant` (labelled below), then feeds the recorded
	 * `win_update_submit` POST response unchanged. Client `api:698-708`: when the
	 * pre-read dispute's reason is `noncompliant`, `update_dispute()` merges
	 * `evidence.enhanced_evidence.visa_compliance.fee_acknowledged = 'true'` into
	 * the evidence it already carries, rather than replacing it.
	 */
	public function test_update_dispute_adds_visa_compliance_enhanced_evidence_for_noncompliant_reason(): void {
		$pre_read = $this->load_recorded_dispute_entry( 'win_update_pre_read' );
		$submit   = $this->load_recorded_dispute_entry( 'win_update_submit' );

		// Override: REC-5b's pre-read body is fed back with its `reason` replaced by
		// `noncompliant`; every other field is the recorded response, unchanged.
		$noncompliant_pre_read_body           = $pre_read['body'];
		$noncompliant_pre_read_body['reason'] = 'noncompliant';

		$http_client            = new FakeWooPaymentsHttpClient();
		$http_client->blog_id   = 123;
		$http_client->responses = array(
			array(
				'response' => array( 'code' => $pre_read['http_status'] ),
				'headers'  => array( 'content-type' => $pre_read['content_type'] ),
				'body'     => wp_json_encode( $noncompliant_pre_read_body ),
			),
			array(
				'response' => array( 'code' => $submit['http_status'] ),
				'headers'  => array( 'content-type' => $submit['content_type'] ),
				'body'     => wp_json_encode( $submit['body'] ),
			),
		);
		$sut                    = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true ), $this->transport_log() );

		$sut->update_dispute(
			'du_1UJbTtBzWlxcwgpPSQwkqPRE',
			array(
				'product_description' => 'REC-5b recording: one-off consulting session delivered online.',
				'uncategorized_text'  => 'winning_evidence',
			),
			true,
			array( '__product_type' => 'offline_service' )
		);

		$sent = json_decode( (string) $http_client->requests[1]['body'], true );
		$this->assertIsArray( $sent );
		$this->assertSame(
			array(
				'visa_compliance' => array(
					'fee_acknowledged' => 'true',
				),
			),
			$sent['evidence']['enhanced_evidence'],
			'A noncompliant pre-read reason must add exactly the Visa compliance flag client api:698-708 builds.'
		);
		// The rest of the evidence the caller passed must still be present, not replaced.
		$this->assertSame( 'REC-5b recording: one-off consulting session delivered online.', $sent['evidence']['product_description'] );
		$this->assertSame( 'winning_evidence', $sent['evidence']['uncategorized_text'] );
	}

	/**
	 * Load one recorded REC-5b R-d dispute entry's HTTP status, content type and response body by pair key.
	 *
	 * @param string $pair REC-5b fixture pair key.
	 * @return array{http_status:int,content_type:string,body:array<string,mixed>}
	 */
	private function load_recorded_dispute_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/rec-5b-disputes.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status'  => (int) $entry['response']['http_status'],
					'content_type' => (string) $entry['response']['content_type'],
					'body'         => $entry['response']['body'],
				);
			}
		}

		$this->fail( "REC-5b fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox Should preserve the disputes export endpoint and body fields.
	 */
	public function test_get_disputes_export_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->get_disputes_export( array( 'status_is' => 'needs_response' ), 'merchant@example.com', 'en_US' );
		$export_body = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( '/sites/123/wcpay/disputes/download', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $export_body );
		$this->assertSame( 'needs_response', $export_body['status_is'] );
		$this->assertSame( 'merchant@example.com', $export_body['user_email'] );
		$this->assertSame( 'en_US', $export_body['locale'] );
	}

	/**
	 * @testdox Should preserve the disputes export URL endpoint.
	 */
	public function test_get_disputes_export_url_uses_preserved_endpoint(): void {
		list( $sut, $http_client ) = $this->make_disputes_sut();

		$sut->get_disputes_export_url( 'dpexp-test.01==' );

		$this->assertSame( '/sites/123/wcpay/disputes/download/dpexp-test.01==?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should reject unsafe transaction export identifiers.
	 */
	public function test_get_transactions_export_url_rejects_invalid_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_transactions_export_url( '../txexp_test' );
	}

	/**
	 * @testdox Should reject invalid fraud outcome statuses.
	 */
	public function test_get_fraud_outcomes_rejects_invalid_status(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_fraud_outcomes( array( 'status' => 'invalid' ) );
	}

	/**
	 * @testdox Should reject unsafe latest fraud outcome identifiers.
	 */
	public function test_get_latest_fraud_outcome_rejects_invalid_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_latest_fraud_outcome( 'pi-test' );
	}

	/**
	 * @testdox Should reject unsafe dispute export identifiers.
	 */
	public function test_get_disputes_export_url_rejects_invalid_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_disputes_export_url( 'dpexp%2Ftest' );
	}

	/**
	 * @testdox Should call preserved admin badge count endpoints.
	 */
	public function test_gets_preserved_admin_badge_count_endpoints(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'count' => 3 ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$sut->get_dispute_status_counts();

		$this->assertSame( '/sites/123/wcpay/disputes/status_counts?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );

		$sut->get_authorizations_summary();

		$this->assertSame( '/sites/123/wcpay/authorizations/summary?test_mode=0', $http_client->last_path );
		$this->assertSame( 'GET', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve admin badge count legacy request filters.
	 */
	public function test_admin_badge_count_methods_preserve_legacy_request_filters(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'count' => 3 ) ),
		);

		$dispute_hook_called       = false;
		$authorization_hook_called = false;

		$dispute_filter = function ( WooPaymentsApiRequest $request ) use ( &$dispute_hook_called ): WooPaymentsApiRequest {
			$dispute_hook_called = true;
			$this->assertSame( 'disputes/status_counts', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'status_is', 'needs_response' );

			return $request;
		};

		$authorization_filter = function ( WooPaymentsApiRequest $request ) use ( &$authorization_hook_called ): WooPaymentsApiRequest {
			$authorization_hook_called = true;
			$this->assertSame( 'authorizations/summary', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'manual_capture', true );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_get_dispute_status_counts', $dispute_filter );
		add_filter( 'wc_pay_get_authorizations_summary', $authorization_filter );

		try {
			$sut->get_dispute_status_counts();
			$dispute_path = $http_client->last_path;

			$sut->get_authorizations_summary();
			$authorization_path = $http_client->last_path;
		} finally {
			remove_filter( 'wcpay_get_dispute_status_counts', $dispute_filter );
			remove_filter( 'wc_pay_get_authorizations_summary', $authorization_filter );
		}

		$this->assertTrue( $dispute_hook_called, 'Dispute badge count requests should run the preserved request filter.' );
		$this->assertStringContainsString( 'status_is=needs_response', $dispute_path );
		$this->assertStringContainsString( 'test_mode=0', $dispute_path );
		$this->assertTrue( $authorization_hook_called, 'Authorization summary requests should run the preserved request filter.' );
		$this->assertStringContainsString( 'manual_capture=true', $authorization_path );
		$this->assertStringContainsString( 'test_mode=0', $authorization_path );
	}

	/**
	 * @testdox Should fetch payment method promotions through the preserved platform endpoint.
	 */
	public function test_get_pm_promotions_uses_preserved_platform_endpoint(): void {
		$http_client          = new FakeWooPaymentsHttpClient();
		$http_client->blog_id = 123;
		// The platform answers with the promotions list and a cache-for header in seconds (client 11.1.0
		// `includes/class-wc-payments-pm-promotions-service.php:224-254`); entries carry the fields the client requires and normalizes at `:643`, `:844-912`.
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'content-type' => 'application/json',
				'cache-for'    => '3600',
			),
			'body'     => wp_json_encode(
				array(
					array(
						'id'             => 'klarna-promo__spotlight',
						'promo_id'       => 'klarna-promo',
						'payment_method' => 'klarna',
						'type'           => 'spotlight',
						'title'          => 'Activate Klarna',
						'description'    => 'Offer flexible payments.',
						'cta_label'      => 'Activate now',
						'tc_url'         => 'https://example.com/terms',
						'tc_label'       => 'See terms',
					),
				)
			),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_pm_promotions(
			array(
				'dismissals' => array( 'klarna-promo__spotlight' => 1781740800 ),
				'locale'     => 'en_US',
			)
		);
		$query  = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( 'klarna-promo__spotlight', $result['promotions'][0]['id'] );
		$this->assertSame( '3600', $result['cache_for'], 'The platform cache-for header comes back with the promotions.' );
		$this->assertSame( '/sites/123/wcpay/payment_method_promotions', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( 'en_US', $query['locale'] );
		$this->assertSame(
			array( 'klarna-promo__spotlight' => 1781740800 ),
			json_decode( (string) $query['dismissals'], true )
		);
	}

	/**
	 * The client rejects an undecodable JSON body before the promotions service sees it, so the service caches the error
	 * for six hours instead of an empty list (client 11.1.0 `includes/wc-payment-api/class-wc-payments-api-client.php:2826-2834`,
	 * `includes/class-wc-payments-pm-promotions-service.php:202-221`).
	 *
	 * @testdox Should reject a promotions response with status $status whose JSON body cannot be decoded.
	 * @testWith [200]
	 *           [201]
	 *
	 * @param int $status HTTP status of the response.
	 */
	public function test_get_pm_promotions_rejects_an_undecodable_json_body( int $status ): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"promotions":',
		);
		$sut                   = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );
		$this->expectExceptionMessage( 'Unable to decode response from WooPayments.' );

		$sut->get_pm_promotions( array( 'locale' => 'en_US' ) );
	}

	/**
	 * @testdox Should activate payment method promotions through the preserved platform endpoint.
	 */
	public function test_activate_pm_promotion_uses_preserved_platform_endpoint(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->activate_pm_promotion( 'klarna-promo__spotlight' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/sites/123/wcpay/payment_method_promotions/klarna-promo__spotlight/activate', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
	}

	/**
	 * @testdox Should preserve payment method promotion legacy request filters.
	 */
	public function test_pm_promotion_methods_preserve_legacy_request_filters(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$get_hook_called      = false;
		$activate_hook_called = false;
		$get_filter           = function ( WooPaymentsApiRequest $request ) use ( &$get_hook_called ): WooPaymentsApiRequest {
			$get_hook_called = true;
			$this->assertSame( 'payment_method_promotions', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'locale', 'fr_FR' );

			return $request;
		};
		$activate_filter      = function ( WooPaymentsApiRequest $request ) use ( &$activate_hook_called ): WooPaymentsApiRequest {
			$activate_hook_called = true;
			$this->assertSame( 'payment_method_promotions/klarna-promo__spotlight/activate', $request->get_api() );
			$this->assertSame( 'POST', $request->get_method() );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_get_pm_promotions_request', $get_filter );
		add_filter( 'wcpay_activate_pm_promotion_request', $activate_filter );

		try {
			$sut->get_pm_promotions( array( 'locale' => 'en_US' ) );
			$get_path = $http_client->last_path;

			$sut->activate_pm_promotion( 'klarna-promo__spotlight' );
		} finally {
			remove_filter( 'wcpay_get_pm_promotions_request', $get_filter );
			remove_filter( 'wcpay_activate_pm_promotion_request', $activate_filter );
		}

		$this->assertTrue( $get_hook_called, 'PM promotions list requests should run the preserved request filter.' );
		$this->assertStringContainsString( 'locale=fr_FR', $get_path );
		$this->assertTrue( $activate_hook_called, 'PM promotion activation requests should run the preserved request filter.' );
	}

	/**
	 * @testdox Should pass their request objects to the payment method promotion filters.
	 */
	public function test_pm_promotion_methods_pass_their_request_objects_to_their_filters(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$observed_get_request      = null;
		$observed_activate_request = null;
		$get_filter                = function ( WooPaymentsGetPmPromotionsRequest $request ) use ( &$observed_get_request ): WooPaymentsGetPmPromotionsRequest {
			$observed_get_request = $request;
			$this->assertSame( 'payment_method_promotions', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$this->assertTrue( $request->should_return_raw_response() );
			$request->set_store_context_params( array( 'locale' => 'fr_FR' ) );

			return $request;
		};
		$activate_filter           = function ( WooPaymentsActivatePmPromotionRequest $request ) use ( &$observed_activate_request ): WooPaymentsActivatePmPromotionRequest {
			$observed_activate_request = $request;
			$this->assertSame( 'klarna-promo__spotlight', $request->get_id() );
			$this->assertSame( 'payment_method_promotions/klarna-promo__spotlight/activate', $request->get_api() );
			$this->assertSame( 'POST', $request->get_method() );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_get_pm_promotions_request', $get_filter );
		add_filter( 'wcpay_activate_pm_promotion_request', $activate_filter );

		try {
			$sut->get_pm_promotions( array( 'locale' => 'en_US' ) );
			$get_path = $http_client->last_path;

			$sut->activate_pm_promotion( 'klarna-promo__spotlight' );
		} finally {
			remove_filter( 'wcpay_get_pm_promotions_request', $get_filter );
			remove_filter( 'wcpay_activate_pm_promotion_request', $activate_filter );
		}

		$this->assertInstanceOf( WooPaymentsGetPmPromotionsRequest::class, $observed_get_request );
		$this->assertInstanceOf( WooPaymentsActivatePmPromotionRequest::class, $observed_activate_request );
		$this->assertStringContainsString( 'locale=fr_FR', $get_path );
	}

	/**
	 * @testdox Should list documents through the preserved documents request object and filter.
	 */
	public function test_get_documents_preserves_legacy_request_filter_contract(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => array() ) ),
		);
		$observed_request      = null;

		$filter = function ( WooPaymentsDocumentsListRequest $request ) use ( &$observed_request ): WooPaymentsDocumentsListRequest {
			$observed_request = $request;
			$this->assertSame( 'documents', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_type_is( 'vat_invoice' );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_list_documents_request', $filter );

		try {
			$result = $sut->get_documents(
				array(
					'page'     => 2,
					'pagesize' => 50,
					'match'    => 'all',
					'type_is'  => 'statement',
					'ignored'  => 'drop-me',
				)
			);
		} finally {
			remove_filter( 'wcpay_list_documents_request', $filter );
		}

		$this->assertSame( array( 'data' => array() ), $result );
		$this->assertInstanceOf( WooPaymentsDocumentsListRequest::class, $observed_request );
		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( '/sites/123/wcpay/documents', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( '2', $query['page'] );
		$this->assertSame( '50', $query['pagesize'] );
		$this->assertSame( 'date', $query['sort'] );
		$this->assertSame( 'desc', $query['direction'] );
		$this->assertSame( '100', $query['limit'] );
		$this->assertSame( 'all', $query['match'] );
		$this->assertSame( 'vat_invoice', $query['type_is'] );
	}

	/**
	 * @testdox Should request documents summary with filter-only params.
	 */
	public function test_get_documents_summary_forwards_filter_only_params(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'count' => 3 ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_documents_summary(
			array(
				'match'       => 'all',
				'date_before' => '2026-06-18',
				'type_is'     => 'vat_invoice',
				'page'        => 2,
				'pagesize'    => 50,
			)
		);

		$this->assertSame( array( 'count' => 3 ), $result );
		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( '/sites/123/wcpay/documents/summary', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( 'all', $query['match'] );
		$this->assertSame( '2026-06-18', $query['date_before'] );
		$this->assertSame( 'vat_invoice', $query['type_is'] );
		$this->assertArrayNotHasKey( 'page', $query );
		$this->assertArrayNotHasKey( 'pagesize', $query );
	}

	/**
	 * @testdox Should download document responses without JSON decoding.
	 */
	public function test_get_document_returns_raw_document_response(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(
				'content-type'        => 'application/pdf',
				'content-disposition' => 'attachment; filename="invoice.pdf"',
			),
			'body'     => '%PDF document',
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->get_document( 'vat_invoice-123' );

		$this->assertSame( $http_client->response, $result );
		$this->assertSame( '/sites/123/wcpay/documents/vat_invoice-123?test_mode=0', $http_client->last_path );
	}

	/**
	 * @testdox Should reject unsafe document identifiers.
	 */
	public function test_get_document_rejects_invalid_route_identifier(): void {
		$sut = new WooPaymentsApiClient();
		$sut->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_document( '../vat_invoice-123' );
	}

	/**
	 * @testdox Should validate VAT through the preserved VAT request filter.
	 */
	public function test_validate_vat_preserves_legacy_request_filter_contract(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'is_valid' => true ) ),
		);
		$hook_called           = false;

		$filter = function ( WooPaymentsApiRequest $request ) use ( &$hook_called ): WooPaymentsApiRequest {
			$hook_called = true;
			$this->assertSame( 'vat/RO123456', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_validate_vat_request', $filter );

		try {
			$result = $sut->validate_vat( 'RO123456' );
		} finally {
			remove_filter( 'wcpay_validate_vat_request', $filter );
		}

		$this->assertSame( array( 'is_valid' => true ), $result );
		$this->assertTrue( $hook_called, 'VAT validation should run the preserved request filter.' );
		$this->assertSame( '/sites/123/wcpay/vat/RO123456?test_mode=0', $http_client->last_path );
	}

	/**
	 * @testdox Should URL-encode unsafe characters in the VAT route parameter exactly once.
	 */
	public function test_validate_vat_encodes_route_parameter(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'is_valid' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		// The REST controller hands validate_vat() a decoded value, so a space or
		// slash must be encoded exactly once before path interpolation.
		$result = $sut->validate_vat( 'CHE 123/4' );

		$this->assertSame( array( 'is_valid' => true ), $result );
		$this->assertSame( '/sites/123/wcpay/vat/CHE%20123%2F4?test_mode=0', $http_client->last_path );
		$this->assertStringNotContainsString( 'CHE 123', $http_client->last_path );
		$this->assertStringNotContainsString( '%2520', $http_client->last_path );
	}

	/**
	 * @testdox Should save VAT details with optional VAT number, name, and address.
	 */
	public function test_save_vat_details_posts_optional_vat_payload(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$result = $sut->save_vat_details( 'RO123456', 'ACME SRL', '1 Market Street' );

		$this->assertSame( array( 'success' => true ), $result );
		$this->assertSame( '/sites/123/wcpay/vat', $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );

		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertIsArray( $body );
		$this->assertSame( 'RO123456', $body['vat_number'] );
		$this->assertSame( 'ACME SRL', $body['name'] );
		$this->assertSame( '1 Market Street', $body['address'] );
	}

	/**
	 * @testdox Should reject unsafe payout export identifiers.
	 */
	public function test_get_payouts_export_url_rejects_invalid_route_identifier(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array() ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		$this->expectException( WooPaymentsApiException::class );

		$sut->get_payouts_export_url( '../poexp_test' );
	}

	/**
	 * @testdox Should retrieve reporting balance summary through the preserved request object and filter.
	 */
	public function test_get_reporting_balance_summary_preserves_legacy_request_filter_contract(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => array( 'available' => array() ) ) ),
		);
		$observed_request      = null;

		$filter = function ( WooPaymentsReportingBalanceSummaryRequest $request ) use ( &$observed_request ): WooPaymentsReportingBalanceSummaryRequest {
			$observed_request = $request;
			$this->assertSame( 'reporting/balance_summary', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_currency( 'EUR' );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_get_reporting_balance_summary_request', $filter );

		try {
			$result = $sut->get_reporting_balance_summary(
				array(
					'date_start' => '2026-06-01T00:00:00Z',
					'date_end'   => '2026-06-19T23:59:59Z',
					'currency'   => 'usd',
					'ignored'    => 'drop-me',
				)
			);
		} finally {
			remove_filter( 'wcpay_get_reporting_balance_summary_request', $filter );
		}

		$this->assertSame( array( 'data' => array( 'available' => array() ) ), $result );
		$this->assertInstanceOf( WooPaymentsReportingBalanceSummaryRequest::class, $observed_request );
		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( '/sites/123/wcpay/reporting/balance_summary', strtok( $http_client->last_path, '?' ) );
		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( '2026-06-01T00:00:00Z', $query['date_start'] );
		$this->assertSame( '2026-06-19T23:59:59Z', $query['date_end'] );
		$this->assertSame( 'eur', $query['currency'] );
		$this->assertArrayNotHasKey( 'ignored', $query );
	}

	/**
	 * @testdox Should reject invalid reporting balance summary currencies before transport.
	 */
	public function test_get_reporting_balance_summary_rejects_invalid_currency_before_transport(): void {
		$http_client          = new FakeWooPaymentsHttpClient();
		$http_client->blog_id = 123;

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->get_reporting_balance_summary(
				array(
					'date_start' => '2026-06-01T00:00:00Z',
					'date_end'   => '2026-06-19T23:59:59Z',
					'currency'   => 'usd1',
				)
			);
			$this->fail( 'Invalid reporting currency should throw before transport.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 400, $exception->get_http_code() );
		}

		$this->assertSame( '', $http_client->last_path );
	}

	/**
	 * @testdox Should retrieve currency rates through the Transact API endpoint.
	 */
	public function test_get_currency_rates_uses_transact_endpoint(): void {
		list( $sut, $http_client ) = $this->make_sut( false );

		$sut->get_currency_rates( 'usd' );

		$this->assertSame( '/sites/123/transact/currency/rates', strtok( $http_client->last_path, '?' ) );
	}

	/**
	 * @testdox Should preserve currency rates response and request semantics.
	 */
	public function test_get_currency_rates_preserves_response_and_request_semantics(): void {
		list( $sut, $http_client ) = $this->make_sut(
			false,
			array(
				'eur' => 0.92,
				'gbp' => 0.79,
			)
		);

		$result = $sut->get_currency_rates( 'usd', array( 'eur', 'gbp' ) );

		$this->assertSame(
			array(
				'eur' => 0.92,
				'gbp' => 0.79,
			),
			$result
		);
		$this->assertSame( 'GET', $http_client->last_method );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $http_client->last_headers );

		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( '0', $query['test_mode'] );
		$this->assertSame( 'usd', $query['currency_from'] );
		$this->assertSame( array( 'eur', 'gbp' ), $query['currencies_to'] );
	}

	/**
	 * @testdox Should omit target currencies when requesting all supported rates.
	 */
	public function test_get_currency_rates_omits_target_currencies_when_none_requested(): void {
		list( $sut, $http_client ) = $this->make_sut(
			true,
			array(
				'eur' => 0.92,
			)
		);

		$result = $sut->get_currency_rates( 'usd' );

		$this->assertSame( array( 'eur' => 0.92 ), $result );

		$query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $query );

		$this->assertSame( '1', $query['test_mode'] );
		$this->assertSame( 'usd', $query['currency_from'] );
		$this->assertArrayNotHasKey( 'currencies_to', $query );
	}

	/**
	 * @testdox Should reject missing currency_from before transport.
	 */
	public function test_get_currency_rates_rejects_missing_currency_from_before_transport(): void {
		$http_client          = new FakeWooPaymentsHttpClient();
		$http_client->blog_id = 123;

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$sut->get_currency_rates( '' );
			$this->fail( 'Missing currency_from should throw before transport.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_mandatory_currency_from_missing', $exception->get_error_code() );
			$this->assertSame( 400, $exception->get_http_code() );
		}

		$this->assertSame( '', $http_client->last_path );
	}

	/**
	 * Get the container's transport log, gated by the store's real WooPayments logging setting.
	 *
	 * @return WooPaymentsTransportLog
	 */
	private function transport_log(): WooPaymentsTransportLog {
		return wc_get_container()->get( WooPaymentsTransportLog::class );
	}

	/**
	 * Create a WooPayments account service mock.
	 *
	 * @param bool      $test_mode            Whether WooPayments should run in test mode.
	 * @param bool|null $test_mode_onboarding Whether WooPayments should use test-mode onboarding.
	 * @param string    $store_id             WooCommerce store ID.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, ?bool $test_mode_onboarding = null, string $store_id = '' ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_woocommerce_store_id' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode_onboarding ?? $test_mode );
		$account_service->method( 'get_woocommerce_store_id' )->willReturn( $store_id );

		return $account_service;
	}

	/**
	 * @testdox A site request returns the decoded body whatever its JSON type, as the client's request() does.
	 *
	 * The platform's minimum recurring amount endpoint answers with a bare integer (`Fixtures/rec-t63-billing-api.json`).
	 */
	public function test_send_site_request_returns_a_bare_integer_body(): void {
		list( $sut, $http_client ) = $this->make_sut( true, 100 );

		$result = $sut->send_site_request( array(), 'subscriptions/minimum_amount/usd', 'GET' );

		$this->assertSame( 100, $result );
		$this->assertSame( '/sites/123/wcpay/subscriptions/minimum_amount/usd?test_mode=1', $http_client->last_path );
		$this->assertFalse( $http_client->last_use_user_token );
	}

	/**
	 * Build an initialized API client backed by a fresh fake HTTP client.
	 *
	 * Each endpoint scenario gets its own fake so that one failing endpoint
	 * cannot mask the request recorded by a later endpoint.
	 *
	 * @param bool  $test_mode     Whether WooPayments should run in test mode.
	 * @param mixed $response_body Decoded body the fake transport should return.
	 * @return array{0: WooPaymentsApiClient, 1: FakeWooPaymentsHttpClient}
	 */
	private function make_sut( bool $test_mode, $response_body = array() ): array {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response_body ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( $test_mode ), $this->transport_log() );

		return array( $sut, $http_client );
	}

	/**
	 * @testdox A platform fraud-flagged decline rotates the card-testing prevention token; ordinary declines leave it alone.
	 */
	public function test_platform_fraud_decline_rotates_the_fraud_prevention_token(): void {
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );
		WC()->initialize_session();
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id'                       => 'acct_rotation',
				'is_live'                          => true,
				'card_testing_protection_eligible' => true,
			)
		);
		$fraud_prevention_service = wc_get_container()->get( WooPaymentsFraudPreventionService::class );
		$original_token           = $fraud_prevention_service->get_token();

		$client = new WooPaymentsApiClient();
		$client->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );
		$throw_method = new \ReflectionMethod( $client, 'throw_api_error' );
		$throw_method->setAccessible( true );

		// An ordinary decline must not rotate.
		try {
			$throw_method->invoke(
				$client,
				array(
					'error' => array(
						'code'         => 'card_declined',
						'decline_code' => 'insufficient_funds',
						'message'      => 'declined',
					),
				),
				402
			);
			$this->fail( 'throw_api_error must throw.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( $original_token, $fraud_prevention_service->get_token() );
		}

		// A fraud-flagged decline must rotate.
		try {
			$throw_method->invoke(
				$client,
				array(
					'error' => array(
						'code'         => 'card_declined',
						'decline_code' => 'fraudulent',
						'message'      => 'declined',
					),
				),
				402
			);
			$this->fail( 'throw_api_error must throw.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertNotSame( $original_token, $fraud_prevention_service->get_token() );
		}

		// The container's account service memoizes the account cache per
		// instance; reset the shared instance to a non-eligible payload so the
		// card-testing gate disarms for every suite that runs after this one.
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id' => 'acct_rotation',
				'is_live'    => true,
			)
		);
		delete_option( 'wcpay_account_data' );
	}
}
