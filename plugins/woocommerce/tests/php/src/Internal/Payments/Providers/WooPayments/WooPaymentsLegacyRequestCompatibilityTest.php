<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsActivatePmPromotionRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountCapitalLinkRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetPmPromotionsRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDepositsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaginatedListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportingBalanceSummaryRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_Unit_Test_Case;

/**
 * Tests for legacy WooPayments request aliases and their send contracts.
 */
class WooPaymentsLegacyRequestCompatibilityTest extends WC_Unit_Test_Case {

	/**
	 * The original API client registered in the container.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $original_api_client;

	/**
	 * Fake provider transport.
	 *
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_api_client   = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->http_client           = new FakeWooPaymentsHttpClient();
		$this->http_client->blog_id  = 123;
		$this->http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'data'             => array(),
					'transport_marker' => 'decoded',
				)
			),
		);

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $this->create_account_service() );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );

		$this->register_legacy_aliases();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( array_keys( $this->request_filter_contracts() ) as $hook ) {
			remove_all_filters( $hook );
		}

		wc_get_container()->replace( WooPaymentsApiClient::class, $this->original_api_client );

		parent::tearDown();
	}

	/**
	 * @testdox Every native request compatibility class is exposed under its legacy alias.
	 * @dataProvider legacy_alias_provider
	 *
	 * @param class-string $native_class Native request class.
	 * @param class-string $legacy_class Legacy request alias.
	 */
	public function test_registers_every_legacy_request_alias( string $native_class, string $legacy_class ): void {
		$this->assertTrue( class_exists( $legacy_class, false ), $legacy_class . ' should be registered without autoloading the extension.' );
		$this->assertTrue( is_a( $native_class, $legacy_class, true ), $native_class . ' should satisfy ' . $legacy_class . '.' );
	}

	/**
	 * @testdox Unknown custom parameters set through the compatibility API survive unchanged.
	 * @dataProvider concrete_request_provider
	 *
	 * @param class-string $native_class Native request class.
	 * @param class-string $legacy_class Legacy request alias.
	 */
	public function test_unknown_custom_set_param_survives_unchanged( string $native_class, string $legacy_class ): void {
		$request = $this->create_request( $native_class );
		$value   = array(
			'nested'  => array( 'alpha' => 1 ),
			'enabled' => false,
		);

		$result = $request->set( 'extension_custom_param', $value );

		$this->assertSame( $request, $result, 'set() should preserve the oracle fluent return contract.' );
		$this->assertInstanceOf( $legacy_class, $request, 'The custom-param behavior should be exposed through the legacy alias.' );
		$this->assertSame( $value, $request->get_params()['extension_custom_param'], 'Custom parameter values should not be normalized or dropped.' );
	}

	/**
	 * @testdox send() applies exactly the mapped legacy filter and transports its custom parameter.
	 * @dataProvider request_filter_contract_provider
	 *
	 * @param class-string $native_class Native request class.
	 * @param string       $hook Legacy request filter.
	 * @param string       $api Expected API path.
	 * @param string       $method Expected HTTP method.
	 * @param bool         $returns_raw Whether send should return the raw transport response.
	 * @param bool         $uses_user_token Whether transport should use the connection-owner token.
	 * @param bool         $dynamic_hook Whether the operation assigns its hook dynamically.
	 */
	public function test_send_applies_distinct_filter_and_preserves_transport_semantics(
		string $native_class,
		string $hook,
		string $api,
		string $method,
		bool $returns_raw,
		bool $uses_user_token,
		bool $dynamic_hook
	): void {
		$hook_calls       = 0;
		$unexpected_calls = 0;
		$request          = $this->create_request( $native_class, $api, $method );

		if ( WooPaymentsFraudOutcomeTransactionsListRequest::class === $native_class ) {
			$this->http_client->response['body'] = wp_json_encode(
				array(
					array(
						'order_id'          => PHP_INT_MAX,
						'payment_intent_id' => 'pi_missing',
					),
				)
			);
		}

		if ( $dynamic_hook ) {
			$request->assign_hook( $hook );
		}

		foreach ( array_keys( $this->request_filter_contracts() ) as $other_hook ) {
			if ( $hook === $other_hook ) {
				continue;
			}

			add_filter(
				$other_hook,
				static function ( $filtered_request ) use ( &$unexpected_calls ) {
					++$unexpected_calls;

					return $filtered_request;
				}
			);
		}

		add_filter(
			$hook,
			static function ( $filtered_request ) use ( &$hook_calls, $hook ) {
				++$hook_calls;
				$filtered_request->set( 'extension_wire_param', $hook );

				return $filtered_request;
			}
		);

		$result = $request->send();

		$this->assertSame( $hook, $request->get_hook(), 'The request should retain its operation-specific hook.' );
		$this->assertSame( 1, $hook_calls, 'send() should apply the mapped request filter exactly once.' );
		$this->assertSame( 0, $unexpected_calls, 'send() should not collapse distinct legacy hooks.' );
		$this->assertSame( $method, $this->http_client->last_method, 'send() should retain the request HTTP method.' );
		$this->assertSame( $uses_user_token, $this->http_client->last_use_user_token, 'send() should retain the request token mode.' );
		$this->assertSame( $api, strtok( preg_replace( '#^/sites/123/wcpay/#', '', $this->http_client->last_path ), '?' ), 'send() should retain the request API path.' );
		$this->assertSame( $hook, $this->get_transported_param( 'extension_wire_param' ), 'The filter-added custom parameter should reach transport unchanged.' );

		$decoded_response = json_decode( (string) $this->http_client->response['body'], true );

		if ( WooPaymentsApiRequest::class === $native_class || WooPaymentsReportingBalanceSummaryRequest::class === $native_class ) {
			$this->assertSame( $decoded_response, $result, 'Get_Request and reporting requests should retain their array formatter.' );
		} elseif ( WooPaymentsFraudOutcomeTransactionsListRequest::class === $native_class ) {
			$this->assertSame( array(), $result, 'Fraud-outcome requests should apply their custom array formatter.' );
		} else {
			$this->assertInstanceOf( 'WCPay\Core\Server\Response', $result, 'Normally formatted requests should return the legacy Response type.' );
			$this->assertSame(
				$returns_raw ? $this->http_client->response : $decoded_response,
				$result->to_array(),
				'Response should wrap the exact raw or decoded transport value supplied to format_response().'
			);
		}
	}

	/**
	 * @testdox Legacy Response is immutable ArrayAccess with an exact to_array view.
	 */
	public function test_legacy_response_array_access_and_to_array_contract(): void {
		$response_class = 'WCPay\Core\Server\Response';
		$data           = array( 'answer' => 42 );
		$response       = new $response_class( $data );

		$this->assertInstanceOf( \ArrayAccess::class, $response );
		$this->assertTrue( isset( $response['answer'] ) );
		$this->assertSame( 42, $response['answer'] );
		$this->assertSame( $data, $response->to_array() );
	}

	/**
	 * @testdox Legacy Response rejects writes and unsets.
	 * @dataProvider response_mutation_provider
	 *
	 * @param string $operation Mutation operation.
	 */
	public function test_legacy_response_is_immutable( string $operation ): void {
		$response_class = 'WCPay\Core\Server\Response';
		$response       = new $response_class( array( 'answer' => 42 ) );

		$this->expectException( \Throwable::class );

		if ( 'set' === $operation ) {
			$response['answer'] = 43;
		} else {
			unset( $response['answer'] );
		}
	}

	/**
	 * @testdox assign_hook keeps the oracle untyped override contract.
	 */
	public function test_assign_hook_has_no_return_type_and_allows_oracle_shaped_override(): void {
		$method = new \ReflectionMethod( WooPaymentsPaginatedListRequest::class, 'assign_hook' );

		$this->assertFalse( $method->hasReturnType(), 'assign_hook() should not add a return type absent from the oracle.' );

		$request = new class() extends WooPaymentsPaginatedListRequest {
			// phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found -- The untyped override is the compatibility behavior under test.
			/**
			 * Assign an operation-specific WordPress request filter.
			 *
			 * @param string $hook WordPress filter name.
			 */
			public function assign_hook( string $hook ) {
				parent::assign_hook( $hook );
			}
			// phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found

			/**
			 * Get the test API path.
			 *
			 * @return string
			 */
			public function get_api(): string {
				return 'transactions';
			}
		};

		$request->assign_hook( 'oracle_shaped_hook' );
		$this->assertSame( 'oracle_shaped_hook', $request->get_hook() );
	}

	/**
	 * Provide immutable Response mutations.
	 *
	 * @return array<string,array{string}>
	 */
	public function response_mutation_provider(): array {
		return array(
			'set'   => array( 'set' ),
			'unset' => array( 'unset' ),
		);
	}

	/**
	 * Provide every native-to-legacy request alias.
	 *
	 * @return array<string,array{class-string,class-string}>
	 */
	public function legacy_alias_provider(): array {
		return array(
			'base request'              => array( WooPaymentsPaginatedListRequest::class, 'WCPay\Core\Server\Request' ),
			'paginated request'         => array( WooPaymentsPaginatedListRequest::class, 'WCPay\Core\Server\Request\Paginated' ),
			'authorizations list'       => array( WooPaymentsAuthorizationsListRequest::class, 'WCPay\Core\Server\Request\List_Authorizations' ),
			'deposits list'             => array( WooPaymentsDepositsListRequest::class, 'WCPay\Core\Server\Request\List_Deposits' ),
			'disputes list'             => array( WooPaymentsDisputesListRequest::class, 'WCPay\Core\Server\Request\List_Disputes' ),
			'documents list'            => array( WooPaymentsDocumentsListRequest::class, 'WCPay\Core\Server\Request\List_Documents' ),
			'fraud outcomes list'       => array( WooPaymentsFraudOutcomeTransactionsListRequest::class, 'WCPay\Core\Server\Request\List_Fraud_Outcome_Transactions' ),
			'transactions list'         => array( WooPaymentsTransactionsListRequest::class, 'WCPay\Core\Server\Request\List_Transactions' ),
			'reporting balance summary' => array( WooPaymentsReportingBalanceSummaryRequest::class, 'WCPay\Core\Server\Request\Get_Reporting_Balance_Summary' ),
			'generic get request'       => array( WooPaymentsApiRequest::class, 'WCPay\Core\Server\Request\Get_Request' ),
			'get PM promotions'         => array( WooPaymentsGetPmPromotionsRequest::class, 'WCPay\Core\Server\Request\Get_PM_Promotions' ),
			'activate PM promotion'     => array( WooPaymentsActivatePmPromotionRequest::class, 'WCPay\Core\Server\Request\Activate_PM_Promotion' ),
			'get account capital link'  => array( WooPaymentsGetAccountCapitalLinkRequest::class, 'WCPay\Core\Server\Request\Get_Account_Capital_Link' ),
		);
	}

	/**
	 * Provide every concrete native-to-legacy request alias.
	 *
	 * @return array<string,array{class-string,class-string}>
	 */
	public function concrete_request_provider(): array {
		$aliases = $this->legacy_alias_provider();
		unset( $aliases['base request'], $aliases['paginated request'] );

		return $aliases;
	}

	/**
	 * Provide fixed and dynamic request-filter contracts.
	 *
	 * @return array<string,array{class-string,string,string,string,bool,bool,bool}>
	 */
	public function request_filter_contract_provider(): array {
		$contracts = array();
		foreach ( $this->request_filter_contracts() as $hook => $contract ) {
			$contracts[ $hook ] = array_merge( array( $contract['class'], $hook ), array_values( array_diff_key( $contract, array( 'class' => true ) ) ) );
		}

		return $contracts;
	}

	/**
	 * Get the complete filter-contract map.
	 *
	 * @return array<string,array{class:class-string,api:string,method:string,raw:bool,user_token:bool,dynamic:bool}>
	 */
	private function request_filter_contracts(): array {
		return array(
			'wcpay_list_authorizations_request'           => array(
				'class'      => WooPaymentsAuthorizationsListRequest::class,
				'api'        => 'authorizations',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_list_deposits_request'                 => array(
				'class'      => WooPaymentsDepositsListRequest::class,
				'api'        => 'deposits',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_list_disputes_request'                 => array(
				'class'      => WooPaymentsDisputesListRequest::class,
				'api'        => 'disputes',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_list_documents_request'                => array(
				'class'      => WooPaymentsDocumentsListRequest::class,
				'api'        => 'documents',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_list_fraud_outcome_transactions_request' => array(
				'class'      => WooPaymentsFraudOutcomeTransactionsListRequest::class,
				'api'        => 'fraud_outcomes/status/allow',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_list_fraud_outcome_transactions_summary_request' => array(
				'class'      => WooPaymentsFraudOutcomeTransactionsListRequest::class,
				'api'        => 'fraud_outcomes/status/allow',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => array(
				'class'      => WooPaymentsFraudOutcomeTransactionsListRequest::class,
				'api'        => 'fraud_outcomes/status/allow',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_fraud_outcome_transactions_export_request' => array(
				'class'      => WooPaymentsFraudOutcomeTransactionsListRequest::class,
				'api'        => 'fraud_outcomes/status/allow',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_list_transactions_request'             => array(
				'class'      => WooPaymentsTransactionsListRequest::class,
				'api'        => 'transactions',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_get_reporting_balance_summary_request' => array(
				'class'      => WooPaymentsReportingBalanceSummaryRequest::class,
				'api'        => 'reporting/balance_summary',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wc_pay_get_authorizations_summary'           => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'authorizations/summary',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_authorization_request'             => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'authorizations/pi_test',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_dispute_status_counts'             => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'disputes/status_counts',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_validate_vat_request'                  => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'vat/RO123',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_active_loan_summary_request'       => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'capital/active_loan_summary',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_loans_request'                     => array(
				'class'      => WooPaymentsApiRequest::class,
				'api'        => 'capital/loans',
				'method'     => 'GET',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => true,
			),
			'wcpay_get_pm_promotions_request'             => array(
				'class'      => WooPaymentsGetPmPromotionsRequest::class,
				'api'        => 'payment_method_promotions',
				'method'     => 'GET',
				'raw'        => true,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_activate_pm_promotion_request'         => array(
				'class'      => WooPaymentsActivatePmPromotionRequest::class,
				'api'        => 'payment_method_promotions/promo_test/activate',
				'method'     => 'POST',
				'raw'        => false,
				'user_token' => false,
				'dynamic'    => false,
			),
			'wcpay_get_account_capital_link'              => array(
				'class'      => WooPaymentsGetAccountCapitalLinkRequest::class,
				'api'        => 'accounts/capital_links',
				'method'     => 'POST',
				'raw'        => false,
				'user_token' => true,
				'dynamic'    => false,
			),
		);
	}

	/**
	 * Create a fully initialized request for an alias/filter row.
	 *
	 * @param class-string $native_class Native request class.
	 * @param string       $api Dynamic API path.
	 * @param string       $method Dynamic HTTP method.
	 * @return WooPaymentsPaginatedListRequest
	 */
	private function create_request( string $native_class, string $api = '', string $method = 'GET' ): WooPaymentsPaginatedListRequest {
		switch ( $native_class ) {
			case WooPaymentsApiRequest::class:
				return WooPaymentsApiRequest::create( array(), '' !== $api ? $api : 'capital/loans', $method );
			case WooPaymentsGetPmPromotionsRequest::class:
				return WooPaymentsGetPmPromotionsRequest::from_store_context( array( 'locale' => 'en_US' ) );
			case WooPaymentsActivatePmPromotionRequest::class:
				return WooPaymentsActivatePmPromotionRequest::from_id( 'promo_test' );
			case WooPaymentsGetAccountCapitalLinkRequest::class:
				return WooPaymentsGetAccountCapitalLinkRequest::from_urls( 'https://example.test/return', 'https://example.test/refresh' );
			case WooPaymentsReportingBalanceSummaryRequest::class:
				return WooPaymentsReportingBalanceSummaryRequest::from_params(
					array(
						'date_start' => '2026-06-01T00:00:00Z',
						'date_end'   => '2026-06-30T23:59:59Z',
						'currency'   => 'usd',
					)
				);
			case WooPaymentsFraudOutcomeTransactionsListRequest::class:
				$request = new WooPaymentsFraudOutcomeTransactionsListRequest();
				$request->set_status( 'allow' );

				return $request;
			default:
				return new $native_class();
		}
	}

	/**
	 * Read a parameter from the fake outgoing request.
	 *
	 * @param string $key Parameter name.
	 * @return mixed
	 */
	private function get_transported_param( string $key ) {
		if ( 'GET' === $this->http_client->last_method ) {
			$query = array();
			parse_str( (string) wp_parse_url( $this->http_client->last_path, PHP_URL_QUERY ), $query );

			return $query[ $key ] ?? null;
		}

		$body = json_decode( (string) $this->http_client->last_body, true );

		return is_array( $body ) ? ( $body[ $key ] ?? null ) : null;
	}

	/**
	 * Register all concrete and base legacy aliases.
	 */
	private function register_legacy_aliases(): void {
		WooPaymentsAuthorizationsListRequest::register_legacy_alias();
		WooPaymentsDepositsListRequest::register_legacy_alias();
		WooPaymentsDisputesListRequest::register_legacy_alias();
		WooPaymentsDocumentsListRequest::register_legacy_alias();
		WooPaymentsFraudOutcomeTransactionsListRequest::register_legacy_alias();
		WooPaymentsTransactionsListRequest::register_legacy_alias();
		WooPaymentsReportingBalanceSummaryRequest::register_legacy_alias();
		WooPaymentsApiRequest::register_legacy_aliases();
		WooPaymentsGetPmPromotionsRequest::register_legacy_aliases();
		WooPaymentsActivatePmPromotionRequest::register_legacy_aliases();
		WooPaymentsGetAccountCapitalLinkRequest::register_legacy_aliases();
	}

	/**
	 * Create an account service test double.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( false );

		return $account_service;
	}
}
