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
	 * @testdox The $_dataName platform call sends the expected method, path, query, body and token and blocking flags, and returns the expected value.
	 * @dataProvider provider_platform_request_contracts
	 *
	 * @param callable                 $send                 Makes the one call on the client and returns its result.
	 * @param bool                     $test_mode            Whether the account is in test mode.
	 * @param bool                     $test_mode_onboarding Whether the account is onboarding in test mode.
	 * @param mixed                    $response_body        Decoded body the platform answers with.
	 * @param string                   $method               Expected HTTP method.
	 * @param string                   $path                 Expected path, without the query string.
	 * @param array<string,mixed>      $query                Expected parsed query string.
	 * @param array<string,mixed>|null $body                 Expected decoded body, or null when no body is sent.
	 * @param bool                     $body_is_subset       Whether $body lists only some of the body's keys.
	 * @param bool                     $use_user_token       Whether the request is signed with the user token.
	 * @param bool                     $blocking             Whether the request waits for the answer.
	 * @param mixed                    $result               Expected return value.
	 */
	public function test_platform_request_contract( callable $send, bool $test_mode, bool $test_mode_onboarding, $response_body, string $method, string $path, array $query, ?array $body, bool $body_is_subset, bool $use_user_token, bool $blocking, $result ): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response_body ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( $test_mode, $test_mode_onboarding ), $this->transport_log() );

		$actual = $send( $sut );

		$sent_query = array();
		parse_str( (string) wp_parse_url( $http_client->last_path, PHP_URL_QUERY ), $sent_query );
		$sent_body = null === $http_client->last_body ? null : json_decode( $http_client->last_body, true );

		$this->assertSame( $method, $http_client->last_method );
		$this->assertSame( $path, explode( '?', $http_client->last_path, 2 )[0] );
		$this->assertSame( $query, $sent_query );
		$this->assertSame( $body, $body_is_subset ? array_intersect_key( (array) $sent_body, (array) $body ) : $sent_body );
		$this->assertSame( $use_user_token, $http_client->last_use_user_token );
		$this->assertSame( $blocking, $http_client->last_blocking );
		$this->assertSame( $result, $actual );
	}

	/**
	 * One row per platform call: send, test mode, onboarding test mode, platform answer, method, path, query, body,
	 * body is a subset, user token, blocking, result. The token and blocking flags follow client 11.1.0.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_platform_request_contracts(): array {
		// Synthetic transport fixtures: the client returns each decoded answer as it comes, so these rows check that the
		// answer comes back unchanged, not the platform's shapes. Field names follow the object each one stands for where
		// there is one (Stripe PaymentMethod and list objects, the Apple Pay domain, a PaymentIntent); $export carries
		// export_id, the field the admin export flow reads.
		$account_link      = array(
			'url'   => 'https://connect.stripe.com/setup/session',
			'state' => 'state_test',
		);
		$login_link        = array( 'url' => 'https://connect.stripe.com/express/login_test' );
		$embedded_session  = array(
			'client_secret'   => 'cs_test',
			'expires_at'      => 1781740800,
			'account_id'      => 'acct_native',
			'is_live'         => false,
			'publishable_key' => 'pk_test_native',
		);
		$payment_method    = array(
			'id'   => 'pm_test',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$payment_methods   = array(
			'data' => array(
				array(
					'id'   => 'pm_card',
					'type' => 'card',
				),
			),
		);
		$apple_pay_domain  = array(
			'id'        => 'domain_123',
			'apple_pay' => array( 'status' => 'active' ),
		);
		$timeline          = array( 'data' => array( array( 'type' => 'captured' ) ) );
		$woopay_compat     = array(
			'incompatible_extensions' => array( 'bad-extension' ),
			'adapted_extensions'      => array( 'woocommerce-points-and-rewards' ),
			'available_countries'     => array( 'US', 'BR' ),
		);
		$onboarding_fields = array(
			'business_types' => array(
				array(
					'key'  => 'individual',
					'name' => 'Individual',
				),
			),
		);
		$fraud_ruleset     = array(
			array(
				'key'     => 'avs_verification',
				'outcome' => 'block',
			),
		);
		$account           = array(
			'account_id'           => 'acct_native_123',
			'test_publishable_key' => 'pk_test_native',
			'is_live'              => false,
		);
		$failed_events     = array(
			'data'     => array(
				array(
					'id'   => 'evt_failed_1',
					'type' => 'payment_intent.succeeded',
				),
			),
			'has_more' => true,
		);
		$terminal_intent   = array(
			'id'     => 'pi_terminal',
			'status' => 'requires_capture',
		);
		// The platform answers with the PaymentIntent, which it prepares only before confirmation (wpcom class-intentions-controller.php:626, :660).
		$prepared_intent = array(
			'id'     => 'pi_terminal',
			'object' => 'payment_intent',
			'status' => 'requires_confirmation',
		);
		$empty_list      = array( 'data' => array() );
		$counted_list    = array(
			'data'        => array(),
			'total_count' => 0,
		);
		$export          = array( 'export_id' => 'exp_test' );
		$fraud_outcome   = array(
			'id'      => 'fo_latest',
			'outcome' => 'review',
		);
		$dispute         = array(
			'id'     => 'dp_test',
			'reason' => 'fraudulent',
		);
		$success         = array( 'success' => true );

		return array(
			'create_account_link'                    => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_account_link(
					array(
						'type'       => 'complete_kyc_link',
						'return_url' => 'https://example.com/return',
					)
				),
				false,
				false,
				$account_link,
				'POST',
				'/sites/123/wcpay/links',
				array(),
				array(
					'test_mode'  => false,
					'type'       => 'complete_kyc_link',
					'return_url' => 'https://example.com/return',
				),
				false,
				true,
				true,
				$account_link,
			),
			// Pinned WooPayments 11.1.0: Get_Account_Login_Data sends test mode only when onboarding in test mode.
			'create_login_link'                      => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_login_link( home_url( '/overview' ) ),
				true,
				false,
				$login_link,
				'POST',
				'/sites/123/wcpay/accounts/login_links',
				array(),
				array(
					'test_mode'    => false,
					'redirect_url' => home_url( '/overview' ),
				),
				false,
				true,
				true,
				$login_link,
			),
			'create_embedded_account_session'        => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_embedded_account_session(),
				false,
				false,
				$embedded_session,
				'POST',
				'/sites/123/wcpay/accounts/embedded/session',
				array(),
				array( 'test_mode' => false ),
				true,
				true,
				true,
				$embedded_session,
			),
			'create_customer'                        => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_customer(
					array(
						'name'  => 'Ada Lovelace',
						'email' => 'ada@example.com',
					)
				),
				false,
				false,
				array( 'id' => 'cus_test' ),
				'POST',
				'/sites/123/wcpay/customers',
				array(),
				array( 'name' => 'Ada Lovelace' ),
				true,
				false,
				true,
				'cus_test',
			),
			'update_customer'                        => array(
				static function ( WooPaymentsApiClient $sut ) {
					$sut->update_customer( 'cus_test', array( 'email' => 'ada@example.com' ) );
					return null;
				},
				false,
				false,
				array(),
				'POST',
				'/sites/123/wcpay/customers/cus_test',
				array(),
				array( 'email' => 'ada@example.com' ),
				true,
				false,
				true,
				null,
			),
			'get_payment_method'                     => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_payment_method( 'pm_test' ),
				false,
				false,
				$payment_method,
				'GET',
				'/sites/123/wcpay/payment_methods/pm_test',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				$payment_method,
			),
			'get_payment_methods'                    => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_payment_methods( 'cus_test', 'card' ),
				true,
				true,
				$payment_methods,
				'GET',
				'/sites/123/wcpay/payment_methods',
				array(
					'test_mode' => '1',
					'customer'  => 'cus_test',
					'type'      => 'card',
					'limit'     => '100',
				),
				null,
				false,
				false,
				true,
				$payment_methods,
			),
			'register_apple_pay_domain'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->register_apple_pay_domain( 'example.test' ),
				true,
				true,
				$apple_pay_domain,
				'POST',
				'/sites/123/wcpay/payment_method_domains',
				array(),
				array(
					'test_mode'   => true,
					'domain_name' => 'example.test',
					'enabled'     => 'true',
				),
				true,
				false,
				true,
				$apple_pay_domain,
			),
			'track_order'                            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->track_order(
					array(
						'id'                 => 42,
						'_payment_method_id' => 'pm_test',
					),
					true
				),
				true,
				true,
				array( 'result' => 'success' ),
				'POST',
				'/sites/123/wcpay/tracking/order',
				array(),
				array(
					'test_mode'  => true,
					'order_data' => array(
						'id'                 => 42,
						'_payment_method_id' => 'pm_test',
					),
					'update'     => true,
				),
				true,
				false,
				true,
				array( 'result' => 'success' ),
			),
			'update_payment_method'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->update_payment_method( 'pm_test', array( 'billing_details' => array( 'email' => 'ada@example.com' ) ) ),
				true,
				true,
				array( 'id' => 'pm_test' ),
				'POST',
				'/sites/123/wcpay/payment_methods/pm_test',
				array(),
				array(
					'test_mode'       => true,
					'billing_details' => array( 'email' => 'ada@example.com' ),
				),
				true,
				false,
				true,
				array( 'id' => 'pm_test' ),
			),
			'detach_payment_method'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->detach_payment_method( 'pm_test' ),
				true,
				true,
				array( 'id' => 'pm_test' ),
				'POST',
				'/sites/123/wcpay/payment_methods/pm_test/detach',
				array(),
				array( 'test_mode' => true ),
				true,
				false,
				true,
				array( 'id' => 'pm_test' ),
			),
			'get_timeline'                           => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_timeline( 'pi_test' ),
				false,
				false,
				$timeline,
				'GET',
				'/sites/123/wcpay/timeline/pi_test',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				$timeline,
			),
			'update_compatibility_data'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->update_compatibility_data( array( 'woocommerce_version' => '11.0.0' ) ),
				false,
				false,
				array( 'result' => 'ok' ),
				'POST',
				'/sites/123/wcpay/compatibility',
				array(),
				array(
					'test_mode'          => false,
					'compatibility_data' => array( 'woocommerce_version' => '11.0.0' ),
				),
				true,
				false,
				true,
				array( 'result' => 'ok' ),
			),
			// A global route, without the site prefix, as client 11.1.0's get_woopay_compatibility() sends it.
			'get_woopay_compatibility'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_woopay_compatibility(),
				false,
				false,
				$woopay_compat,
				'GET',
				'/wcpay/woopay/compatibility',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				$woopay_compat,
			),
			'get_onboarding_fields_data'             => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_onboarding_fields_data( 'en_US' ),
				true,
				true,
				$onboarding_fields,
				'GET',
				'/wcpay/onboarding/fields_data',
				array(
					'test_mode' => '1',
					'locale'    => 'en_US',
				),
				null,
				false,
				true,
				true,
				$onboarding_fields,
			),
			'finalize_onboarding_embedded_kyc'       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->finalize_onboarding_embedded_kyc( 'en_US', 'wcadmin-settings-page', array( 'wcpay-promo-test' ) ),
				false,
				false,
				array(
					'success' => true,
					'mode'    => 'live',
				),
				'POST',
				'/sites/123/wcpay/onboarding/embedded/finalize',
				array(),
				array(
					'test_mode'      => false,
					'locale'         => 'en_US',
					'source'         => 'wcadmin-settings-page',
					'actioned_notes' => array( 'wcpay-promo-test' ),
				),
				true,
				true,
				true,
				array(
					'success' => true,
					'mode'    => 'live',
				),
			),
			'delete_account'                         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->delete_account( true ),
				false,
				false,
				array( 'result' => 'success' ),
				'POST',
				'/sites/123/wcpay/accounts/delete',
				array(),
				array( 'test_mode' => true ),
				true,
				true,
				true,
				array( 'result' => 'success' ),
			),
			'update_account'                         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->update_account(
					array(
						'statement_descriptor'   => 'NATIVE STORE',
						'business_support_email' => 'support@example.test',
					)
				),
				true,
				true,
				$success,
				'POST',
				'/sites/123/wcpay/accounts',
				array(),
				array(
					'test_mode'              => true,
					'statement_descriptor'   => 'NATIVE STORE',
					'business_support_email' => 'support@example.test',
				),
				true,
				true,
				true,
				$success,
			),
			'add_account_tos_agreement'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->add_account_tos_agreement( 'settings-popup', 'merchant_admin' ),
				false,
				false,
				$success,
				'POST',
				'/sites/123/wcpay/accounts/tos_agreements',
				array(),
				array(
					'test_mode' => false,
					'source'    => 'settings-popup',
					'user_name' => 'merchant_admin',
				),
				true,
				true,
				true,
				$success,
			),
			'request_capability'                     => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->request_capability( 'link_payments', true ),
				true,
				true,
				array( 'status' => 'active' ),
				'POST',
				'/sites/123/wcpay/accounts/capabilities',
				array(),
				array(
					'capability_id' => 'link_payments',
					'requested'     => true,
				),
				true,
				true,
				true,
				array( 'status' => 'active' ),
			),
			'get_file'                               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_file( 'file_logo', false ),
				false,
				false,
				array(
					'id'      => 'file_logo',
					'purpose' => 'business_logo',
				),
				'GET',
				'/sites/123/wcpay/files/file_logo',
				array(
					'test_mode'  => '0',
					'as_account' => '0',
				),
				null,
				false,
				false,
				true,
				array(
					'id'      => 'file_logo',
					'purpose' => 'business_logo',
				),
			),
			'get_file_contents'                      => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_file_contents( 'file_logo', false ),
				false,
				false,
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				),
				'GET',
				'/sites/123/wcpay/files/file_logo/contents',
				array(
					'test_mode'  => '0',
					'as_account' => '0',
				),
				null,
				false,
				false,
				true,
				array(
					'content_type' => 'image/png',
					'file_content' => 'TE9HTw==',
				),
			),
			'save_fraud_ruleset'                     => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->save_fraud_ruleset( $fraud_ruleset ),
				false,
				false,
				$success,
				'POST',
				'/sites/123/wcpay/fraud_ruleset',
				array(),
				array( 'ruleset_config' => $fraud_ruleset ),
				true,
				false,
				true,
				$success,
			),
			'get_latest_fraud_ruleset'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_latest_fraud_ruleset(),
				false,
				false,
				array( 'ruleset_config' => $fraud_ruleset ),
				'GET',
				'/sites/123/wcpay/fraud_ruleset',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				array( 'ruleset_config' => $fraud_ruleset ),
			),
			'get_account'                            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_account( 'store_123' ),
				false,
				true,
				$account,
				'GET',
				'/sites/123/wcpay/accounts',
				array(
					'test_mode'            => '1',
					'woocommerce_store_id' => 'store_123',
				),
				null,
				false,
				false,
				true,
				$account,
			),
			'get_failed_webhook_events'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_failed_webhook_events(),
				true,
				true,
				$failed_events,
				'POST',
				'/sites/123/wcpay/webhook/failed_events',
				array(),
				array( 'test_mode' => true ),
				true,
				false,
				true,
				$failed_events,
			),
			'create_terminal_connection_token'       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_terminal_connection_token(),
				true,
				true,
				array( 'secret' => 'cnctok_test_secret' ),
				'POST',
				'/sites/123/wcpay/terminal/connection_tokens',
				array(),
				array( 'test_mode' => true ),
				true,
				false,
				true,
				array( 'secret' => 'cnctok_test_secret' ),
			),
			'get_address_autocomplete_token'         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_address_autocomplete_token(),
				false,
				false,
				array( 'token' => 'address.jwt.token' ),
				'POST',
				'/sites/123/wcpay/address-autocomplete-token',
				array(),
				array( 'test_mode' => false ),
				true,
				false,
				true,
				array( 'token' => 'address.jwt.token' ),
			),
			'create_terminal_payment_intention'      => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_terminal_payment_intention(
					array(
						'amount'               => 1234,
						'currency'             => 'usd',
						'capture_method'       => 'manual',
						'metadata'             => array( 'order_number' => '100' ),
						'payment_method_types' => array( 'card_present' ),
					)
				),
				false,
				false,
				$terminal_intent,
				'POST',
				'/sites/123/wcpay/intentions',
				array(),
				array(
					'amount'               => 1234,
					'capture_method'       => 'manual',
					'payment_method_types' => array( 'card_present' ),
				),
				true,
				false,
				true,
				$terminal_intent,
			),
			'prepare_terminal_payment'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->prepare_terminal_payment( 'pi_terminal', 42 ),
				false,
				false,
				$prepared_intent,
				'POST',
				'/sites/123/wcpay/intentions/pi_terminal/prepare_terminal_payment',
				array(),
				array( 'order_id' => 42 ),
				true,
				false,
				true,
				$prepared_intent,
			),
			'register_terminal_reader'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->register_terminal_reader( 'tml_test', 'code_123', 'Counter', array( 'channel' => 'pos' ) ),
				false,
				false,
				array( 'id' => 'tmr_test' ),
				'POST',
				'/sites/123/wcpay/terminal/readers',
				array(),
				array(
					'location'          => 'tml_test',
					'registration_code' => 'code_123',
					'label'             => 'Counter',
					'metadata'          => array( 'channel' => 'pos' ),
				),
				true,
				false,
				true,
				array( 'id' => 'tmr_test' ),
			),
			'create_terminal_location'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_terminal_location(
					'Store',
					array(
						'country' => 'US',
						'line1'   => '123 Main',
					),
					array( 'source' => 'native' )
				),
				false,
				false,
				array( 'id' => 'tml_test' ),
				'POST',
				'/sites/123/wcpay/terminal/locations',
				array(),
				array(
					'display_name' => 'Store',
					'address'      => array(
						'country' => 'US',
						'line1'   => '123 Main',
					),
					'metadata'     => array( 'source' => 'native' ),
				),
				true,
				false,
				true,
				array( 'id' => 'tml_test' ),
			),
			'get_terminal_readers'                   => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_terminal_readers(),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/terminal/readers',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'get_terminal_locations'                 => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_terminal_locations(),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/terminal/locations',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'get_terminal_location'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_terminal_location( 'tml_test' ),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/terminal/locations/tml_test',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'get_readers_charge_summary'             => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_readers_charge_summary( '2026-06-17', 'txn_test' ),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/reader-charges/summary',
				array(
					'test_mode'      => '1',
					'charge_date'    => '2026-06-17',
					'transaction_id' => 'txn_test',
				),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'get_transaction'                        => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_transaction( 'txn_test' ),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/transactions/txn_test',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'update_terminal_location'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->update_terminal_location( 'tml_test', 'Updated', array( 'line1' => '456 Market' ) ),
				false,
				false,
				array( 'id' => 'tml_test' ),
				'POST',
				'/sites/123/wcpay/terminal/locations/tml_test',
				array(),
				array(
					'display_name' => 'Updated',
					'address'      => array( 'line1' => '456 Market' ),
				),
				true,
				false,
				true,
				array( 'id' => 'tml_test' ),
			),
			'get_dispute_summary'                    => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_dispute_summary( 'du_test' ),
				true,
				true,
				array(
					'disputed_amount' => 500,
					'currency'        => 'usd',
				),
				'GET',
				'/sites/123/wcpay/disputes/du_test/summary',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				array(
					'disputed_amount' => 500,
					'currency'        => 'usd',
				),
			),
			'get_charge, hyphenated id'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_charge( 'ch_abc-123' ),
				false,
				false,
				array( 'id' => 'ch_abc-123' ),
				'GET',
				'/sites/123/wcpay/charges/ch_abc-123',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				array( 'id' => 'ch_abc-123' ),
			),
			'get_deposits_overview'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_deposits_overview(),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/deposits/overview-all',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_deposits'                           => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_deposits(
					array(
						'page'              => 2,
						'pagesize'          => 25,
						'sort'              => 'date',
						'direction'         => 'desc',
						'store_currency_is' => 'usd',
						'status_is'         => 'paid',
					)
				),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/deposits',
				array(
					'test_mode'         => '1',
					'page'              => '2',
					'pagesize'          => '25',
					'sort'              => 'date',
					'direction'         => 'desc',
					'store_currency_is' => 'usd',
					'status_is'         => 'paid',
				),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_deposits_summary'                   => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_deposits_summary(
					array(
						'store_currency_is' => 'usd',
						'status_is_not'     => 'failed',
					)
				),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/deposits/summary',
				array(
					'test_mode'         => '1',
					'store_currency_is' => 'usd',
					'status_is_not'     => 'failed',
				),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_deposit'                            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_deposit( 'po_test' ),
				false,
				false,
				array( 'id' => 'po_test' ),
				'GET',
				'/sites/123/wcpay/deposits/po_test',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				array( 'id' => 'po_test' ),
			),
			'start_deposits_export'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->start_deposits_export(
					array(
						'status_is'         => 'paid',
						'store_currency_is' => 'usd',
					),
					'merchant@example.com',
					'en_US'
				),
				true,
				true,
				$export,
				'POST',
				'/sites/123/wcpay/deposits/download',
				array(),
				array(
					'test_mode'         => true,
					'status_is'         => 'paid',
					'store_currency_is' => 'usd',
					'user_email'        => 'merchant@example.com',
					'locale'            => 'en_US',
				),
				true,
				false,
				true,
				$export,
			),
			'get_payouts_export_url'                 => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_payouts_export_url( 'poexp_test' ),
				true,
				true,
				array( 'exported_deposits' => 42 ),
				'GET',
				'/sites/123/wcpay/deposits/download/poexp_test',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				array( 'exported_deposits' => 42 ),
			),
			'manual_deposit'                         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->manual_deposit( 'instant', 'usd' ),
				true,
				true,
				array( 'exported_deposits' => 42 ),
				'POST',
				'/sites/123/wcpay/deposits',
				array(),
				array(
					'type'     => 'instant',
					'currency' => 'usd',
				),
				true,
				false,
				true,
				array( 'exported_deposits' => 42 ),
			),
			'get_capital_active_loan_summary'        => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_capital_active_loan_summary(),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/capital/active_loan_summary',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'get_capital_loans'                      => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_capital_loans(),
				true,
				true,
				$empty_list,
				'GET',
				'/sites/123/wcpay/capital/loans',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$empty_list,
			),
			'create_capital_link'                    => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_capital_link(
					'https://example.test/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
					'https://example.test/wp-admin/admin.php?wcpay-loan-offer'
				),
				true,
				true,
				array( 'url' => 'https://capital.example.test/view-offer' ),
				'POST',
				'/sites/123/wcpay/accounts/capital_links',
				array( 'test_mode' => '1' ),
				array(
					'type'        => 'capital_financing_offer',
					'return_url'  => 'https://example.test/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
					'refresh_url' => 'https://example.test/wp-admin/admin.php?wcpay-loan-offer',
				),
				false,
				true,
				true,
				array( 'url' => 'https://capital.example.test/view-offer' ),
			),
			'get_transactions'                       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_transactions(
					array(
						'page'       => 2,
						'pagesize'   => 25,
						'deposit_id' => 'po_test',
					)
				),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/transactions',
				array(
					'test_mode'  => '1',
					'page'       => '2',
					'pagesize'   => '25',
					'deposit_id' => 'po_test',
				),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_transactions_summary'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_transactions_summary( array( 'store_currency_is' => 'usd' ), 'po_test' ),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/transactions/summary',
				array(
					'test_mode'         => '1',
					'store_currency_is' => 'usd',
					'deposit_id'        => 'po_test',
				),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_transactions_search_autocomplete'   => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_transactions_search_autocomplete( 'Ada' ),
				true,
				true,
				array(
					array(
						'customer_name'  => 'Ada Lovelace',
						'customer_email' => 'ada@example.com',
					),
				),
				'GET',
				'/sites/123/wcpay/transactions/search',
				array(
					'test_mode'   => '1',
					'search_term' => 'Ada',
				),
				null,
				false,
				false,
				true,
				array( array( 'label' => 'Ada Lovelace (ada@example.com)' ) ),
			),
			'get_latest_fraud_outcome'               => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_latest_fraud_outcome( 'pi_test' ),
				true,
				true,
				array(
					$fraud_outcome,
					array(
						'id'      => 'fo_previous',
						'outcome' => 'allow',
					),
				),
				'GET',
				'/sites/123/wcpay/fraud_outcomes/order_id/pi_test',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$fraud_outcome,
			),
			'get_latest_fraud_outcome, empty answer' => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_latest_fraud_outcome( 'pi_test' ),
				true,
				true,
				array(),
				'GET',
				'/sites/123/wcpay/fraud_outcomes/order_id/pi_test',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				array(),
			),
			'start_transactions_export'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->start_transactions_export( array( 'type_is' => 'charge' ), 'merchant@example.com', 'po_test', 'en_US' ),
				true,
				true,
				$export,
				'POST',
				'/sites/123/wcpay/transactions/download',
				array(),
				array(
					'type_is'    => 'charge',
					'user_email' => 'merchant@example.com',
					'deposit_id' => 'po_test',
					'locale'     => 'en_US',
				),
				true,
				false,
				true,
				$export,
			),
			'get_transactions_export_url'            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_transactions_export_url( 'txexp-test.01==' ),
				true,
				true,
				$counted_list,
				'GET',
				'/sites/123/wcpay/transactions/download/txexp-test.01==',
				array( 'test_mode' => '1' ),
				null,
				false,
				false,
				true,
				$counted_list,
			),
			'get_disputes'                           => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_disputes( array( 'status_is' => 'needs_response' ) ),
				false,
				false,
				$dispute,
				'GET',
				'/sites/123/wcpay/disputes',
				array(
					'test_mode' => '0',
					'status_is' => 'needs_response',
				),
				null,
				false,
				false,
				true,
				$dispute,
			),
			// The exact query also proves the filters are not wrapped in a list (no `0[currency_is]`).
			'get_disputes_summary'                   => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_disputes_summary( array( 'currency_is' => 'usd' ) ),
				false,
				false,
				$dispute,
				'GET',
				'/sites/123/wcpay/disputes/summary',
				array(
					'test_mode'   => '0',
					'currency_is' => 'usd',
				),
				null,
				false,
				false,
				true,
				$dispute,
			),
			'get_dispute'                            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_dispute( 'dp_test' ),
				false,
				false,
				$dispute,
				'GET',
				'/sites/123/wcpay/disputes/dp_test',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				$dispute,
			),
			'start_disputes_export'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->start_disputes_export( array( 'status_is' => 'needs_response' ), 'merchant@example.com', 'en_US' ),
				false,
				false,
				$export,
				'POST',
				'/sites/123/wcpay/disputes/download',
				array(),
				array(
					'status_is'  => 'needs_response',
					'user_email' => 'merchant@example.com',
					'locale'     => 'en_US',
				),
				true,
				false,
				true,
				$export,
			),
			'get_disputes_export_url'                => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_disputes_export_url( 'dpexp-test.01==' ),
				false,
				false,
				$dispute,
				'GET',
				'/sites/123/wcpay/disputes/download/dpexp-test.01==',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				$dispute,
			),
			'get_dispute_status_counts'              => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_dispute_status_counts(),
				false,
				false,
				array( 'count' => 3 ),
				'GET',
				'/sites/123/wcpay/disputes/status_counts',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				array( 'count' => 3 ),
			),
			'get_authorizations_summary'             => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_authorizations_summary(),
				false,
				false,
				array( 'count' => 3 ),
				'GET',
				'/sites/123/wcpay/authorizations/summary',
				array( 'test_mode' => '0' ),
				null,
				false,
				false,
				true,
				array( 'count' => 3 ),
			),
			'activate_pm_promotion'                  => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->activate_pm_promotion( 'klarna-promo__spotlight' ),
				false,
				false,
				$success,
				'POST',
				'/sites/123/wcpay/payment_method_promotions/klarna-promo__spotlight/activate',
				array(),
				array( 'test_mode' => false ),
				false,
				false,
				true,
				$success,
			),
			'save_vat_details'                       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->save_vat_details( 'RO123456', 'ACME SRL', '1 Market Street' ),
				false,
				false,
				$success,
				'POST',
				'/sites/123/wcpay/vat',
				array(),
				array(
					'name'       => 'ACME SRL',
					'address'    => '1 Market Street',
					'vat_number' => 'RO123456',
				),
				true,
				false,
				true,
				$success,
			),
			// A non-blocking request returns nothing, whatever the platform would answer.
			'send_store_setup'                       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->send_store_setup( array( 'gateway' => array( 'enabled' => true ) ) ),
				false,
				true,
				array( 'result' => 'ok' ),
				'POST',
				'/sites/123/wcpay/accounts/store_setup',
				array(),
				array(
					'test_mode' => true,
					'snapshot'  => array( 'gateway' => array( 'enabled' => true ) ),
				),
				true,
				false,
				false,
				array(),
			),
			'get_currency_rates'                     => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_currency_rates( 'usd' ),
				false,
				false,
				array(),
				'GET',
				'/sites/123/transact/currency/rates',
				array(
					'test_mode'     => '0',
					'currency_from' => 'usd',
				),
				null,
				false,
				false,
				true,
				array(),
			),
			'get_currency_rates, targets'            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_currency_rates( 'usd', array( 'eur', 'gbp' ) ),
				false,
				false,
				array(
					'eur' => 0.92,
					'gbp' => 0.79,
				),
				'GET',
				'/sites/123/transact/currency/rates',
				array(
					'test_mode'     => '0',
					'currency_from' => 'usd',
					'currencies_to' => array( 'eur', 'gbp' ),
				),
				null,
				false,
				false,
				true,
				array(
					'eur' => 0.92,
					'gbp' => 0.79,
				),
			),
			'get_currency_rates, no targets'         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->get_currency_rates( 'usd' ),
				true,
				true,
				array( 'eur' => 0.92 ),
				'GET',
				'/sites/123/transact/currency/rates',
				array(
					'test_mode'     => '1',
					'currency_from' => 'usd',
				),
				null,
				false,
				false,
				true,
				array( 'eur' => 0.92 ),
			),
			// Test mode comes from onboarding test mode, as in client 11.1.0's update_woopay().
			'update_woopay'                          => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->update_woopay( array( 'webhook_secret' => 'woopay_webhook_signing_secret_value' ) ),
				true,
				false,
				array( 'result' => 'success' ),
				'POST',
				'/sites/123/wcpay/accounts/platform_checkout',
				array(),
				array(
					'test_mode'      => false,
					'webhook_secret' => 'woopay_webhook_signing_secret_value',
				),
				false,
				false,
				true,
				array( 'result' => 'success' ),
			),
		);
	}

	/**
	 * @testdox Should fire the wpcay_get_account_login_data filter once with the login request object.
	 *
	 * Pinned WooPayments 11.1.0: Get_Account_Login_Data::$hook and Request::send()/apply_filters( $hook, $this ), no extra arguments.
	 */
	public function test_create_login_link_fires_the_login_data_filter_with_the_request(): void {
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
		// Stripe API reference, "The Refund object"); a full recorded body is in Fixtures/platform-refunds.json.
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
	 * @testdox The $_dataName request posts its exact body to its path with the caller's or a minted idempotency key, and returns the platform's answer.
	 * @dataProvider provider_intent_request_bodies
	 *
	 * @param callable            $send        Makes the one call on the client and returns its result.
	 * @param string              $result_id   ID the platform answers with.
	 * @param string              $path        Expected path.
	 * @param string              $key_pattern Pattern the Idempotency-Key header must match.
	 * @param array<string,mixed> $body        Expected decoded body, exactly.
	 */
	public function test_intent_request_body( callable $send, string $result_id, string $path, string $key_pattern, array $body ): void {
		list( $sut, $http_client ) = $this->make_sut( false, array( 'id' => $result_id ) );

		$result = $send( $sut );

		$this->assertSame( $result_id, $result['id'] );
		$this->assertSame( $path, $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertMatchesRegularExpression( $key_pattern, $http_client->last_headers['Idempotency-Key'] ?? '' );
		$this->assertSame( $body, json_decode( (string) $http_client->last_body, true ) );
	}

	/**
	 * One row per intent request: send, result ID, path, Idempotency-Key pattern, exact body. A request with no caller key
	 * gets a UUIDv4. Pinned WooPayments 11.1.0: Refund_Charge, Capture_Intention, Cancel_Intention,
	 * Create_And_Confirm_Intention, Create_And_Confirm_Setup_Intention and Create_Setup_Intention DEFAULT_PARAMS and
	 * get_api(). The confirm flag goes on the wire as the string "true" or "false".
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_intent_request_bodies(): array {
		$minted_key = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
		$level3     = array(
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
		);

		return array(
			'full free-text refund'            => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->refund_charge( 'ch_test', null, 'Customer requested a full refund', 'merchant_dashboard', 'idem_full_refund' ),
				're_full',
				'/sites/123/wcpay/refunds',
				'/^idem_full_refund\z/',
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
			),
			'capture with metadata and level3' => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->capture_intention( 'pi_test', 975, array( 'order_id' => '123' ), $level3 ),
				'pi_test',
				'/sites/123/wcpay/intentions/pi_test/capture',
				$minted_key,
				array(
					'test_mode'         => false,
					'amount_to_capture' => 975,
					'metadata'          => array( 'order_id' => '123' ),
					'level3'            => $level3,
				),
			),
			'capture with empty metadata'      => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->capture_intention( 'pi_test', 975, array(), array() ),
				'pi_test',
				'/sites/123/wcpay/intentions/pi_test/capture',
				$minted_key,
				array(
					'test_mode'         => false,
					'amount_to_capture' => 975,
					'metadata'          => array(),
					'level3'            => array(),
				),
			),
			'cancel'                           => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->cancel_intention( 'pi_test' ),
				'pi_test',
				'/sites/123/wcpay/intentions/pi_test/cancel',
				$minted_key,
				array( 'test_mode' => false ),
			),
			'create and confirm payment'       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_and_confirm_payment_intention(
					array(
						'amount'               => 1000,
						'currency'             => 'usd',
						'customer'             => 'cus_test',
						'metadata'             => array( 'order_id' => '123' ),
						'payment_method'       => 'pm_test',
						'payment_method_types' => array( 'card' ),
					),
					'idem_charge'
				),
				'pi_test',
				'/sites/123/wcpay/intentions',
				'/^idem_charge\z/',
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
			),
			'create and confirm setup'         => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_and_confirm_setup_intention(
					array(
						'customer'             => 'cus_test',
						'metadata'             => array( 'order_id' => '123' ),
						'payment_method'       => 'pm_test',
						'payment_method_types' => array( 'sepa_debit' ),
					),
					'idem_setup'
				),
				'seti_test',
				'/sites/123/wcpay/setup_intents',
				'/^idem_setup\z/',
				array(
					'test_mode'            => false,
					'customer'             => 'cus_test',
					'metadata'             => array( 'order_id' => '123' ),
					'payment_method'       => 'pm_test',
					'payment_method_types' => array( 'sepa_debit' ),
					'confirm'              => 'true',
				),
			),
			'unconfirmed setup'                => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_setup_intention(
					array(
						'customer'             => 'cus_test',
						'metadata'             => array( 'order_id' => '123' ),
						'payment_method_types' => array( 'card' ),
					),
					'idem_setup_unconfirmed'
				),
				'seti_unconfirmed',
				'/sites/123/wcpay/setup_intents',
				'/^idem_setup_unconfirmed\z/',
				array(
					'test_mode'            => false,
					'customer'             => 'cus_test',
					'metadata'             => array( 'order_id' => '123' ),
					'payment_method_types' => array( 'card' ),
					'confirm'              => 'false',
				),
			),
		);
	}

	/**
	 * @testdox A refund request sends its key as the refund_attempt metadata, the exact body recorded on local WPCOM.
	 *
	 * Recorded in `Fixtures/platform-refund-errors.json`, pair `reused_key_identical_params_replays_refund`: the request
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
		$fixture = file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-refund-errors.json' );
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
	 * @testdox Should retry idempotent write requests when the transport has no HTTP response.
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
	 * @testdox Should source test mode from the account service.
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
	 * @testdox The $_dataName platform error reaches the caller as an exception carrying its details.
	 * @dataProvider provider_platform_error_envelopes
	 *
	 * @param array<string,mixed> $response_body Decoded platform error body.
	 * @param int                 $status        HTTP status of the platform answer.
	 * @param array<string,mixed> $expected      Exception getter name => expected value.
	 */
	public function test_platform_error_envelope_maps_to_the_exception( array $response_body, int $status, array $expected ): void {
		$exception = $this->capture_api_error( $response_body, $status );

		foreach ( $expected as $getter => $value ) {
			$this->assertSame( $value, $exception->$getter(), $getter );
		}
	}

	/**
	 * One row per platform error shape: body, status, exception getter => expected value. The top-level amount_too_small
	 * message is thrown unwrapped, as the client does; the seller message is kept for card_declined only, and
	 * amount_too_large on an uncaptured intent is rewritten so the merchant is not told to contact support.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_platform_error_envelopes(): array {
		return array(
			// The nested error object (client 11.1.0 class-wc-payments-api-client.php:2852-2872).
			'structured card error'                   => array(
				array(
					'error' => array(
						'type'         => 'card_error',
						'code'         => 'card_declined',
						'decline_code' => 'insufficient_funds',
						'message'      => 'Card declined for request req_private.',
					),
				),
				402,
				array(
					'get_error_code'   => 'card_declined',
					'get_error_type'   => 'card_error',
					'get_decline_code' => 'insufficient_funds',
					'getMessage'       => 'Error: Card declined for request req_private.',
					'get_http_code'    => 402,
				),
			),
			// The top-level amount_too_small envelope with its data (client :2845-2851).
			'top-level amount_too_small with data'    => array(
				array(
					'code'    => 'amount_too_small',
					'message' => 'Amount must be at least $0.50 usd',
					'data'    => array(
						'minimum_amount' => 50,
						'currency'       => 'usd',
					),
				),
				400,
				array(
					'get_error_code' => 'amount_too_small',
					'getMessage'     => 'Amount must be at least $0.50 usd',
					'get_error_data' => array(
						'minimum_amount' => 50,
						'currency'       => 'usd',
					),
					'get_http_code'  => 400,
				),
			),
			// The nested error object's param (client :2872).
			'error object param'                      => array(
				array(
					'error' => array(
						'code'    => 'invalid_request_error',
						'message' => 'Invalid statement descriptor.',
						'param'   => 'statement_descriptor',
					),
				),
				400,
				array(
					'get_error_code' => 'invalid_request_error',
					'get_error_data' => array( 'param' => 'statement_descriptor' ),
				),
			),
			// The decline's seller message, read for card_declined only (client :2914-2915).
			'card_declined seller message'            => array(
				array(
					'error' => array(
						'code'           => 'card_declined',
						'message'        => 'Your card was declined.',
						'type'           => 'card_error',
						'decline_code'   => 'do_not_honor',
						'payment_intent' => array(
							'id'      => 'pi_failed_test',
							'status'  => 'requires_payment_method',
							'charges' => array( 'data' => array( array( 'outcome' => array( 'seller_message' => 'The bank did not return any further details with this decline.' ) ) ) ),
						),
					),
				),
				402,
				array(
					'get_payment_intent_id' => 'pi_failed_test',
					'get_merchant_message'  => 'The bank did not return any further details with this decline.',
					'get_error_code'        => 'card_declined',
					'get_decline_code'      => 'do_not_honor',
				),
			),
			// The same seller message on another code, which the client does not read (client :2914).
			'seller message ignored for expired_card' => array(
				array(
					'error' => array(
						'code'           => 'expired_card',
						'message'        => 'Your card has expired.',
						'type'           => 'card_error',
						'payment_intent' => array(
							'id'      => 'pi_failed_test',
							'charges' => array( 'data' => array( array( 'outcome' => array( 'seller_message' => 'The card has expired.' ) ) ) ),
						),
					),
				),
				402,
				array(
					'get_merchant_message'  => '',
					'get_payment_intent_id' => 'pi_failed_test',
				),
			),
			// The fraud-rule block with its ruleset results (client :2920-2925).
			'fraud ruleset results data'              => array(
				array(
					'error' => array(
						'code'    => 'wcpay_blocked_by_fraud_rule',
						'message' => 'Transaction blocked by fraud rules.',
					),
					'data'  => array( 'ruleset_results' => array( 'international_ip_address' => 'block' ) ),
				),
				400,
				array(
					'get_error_code' => 'wcpay_blocked_by_fraud_rule',
					'get_error_data' => array( 'ruleset_results' => array( 'international_ip_address' => 'block' ) ),
				),
			),
			// amount_too_large on an intent awaiting capture, rewritten (client :2859-2864).
			'amount_too_large awaiting capture'       => array(
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
				),
				400,
				array(
					'get_error_code' => 'amount_too_large',
					'getMessage'     => 'Error: The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.',
				),
			),
			// amount_too_large on any other intent, kept as sent (client :2859).
			'amount_too_large not awaiting capture'   => array(
				array(
					'error' => array(
						'code'    => 'amount_too_large',
						'message' => 'Amount must be no more than $999,999.99 usd.',
						'type'    => 'invalid_request_error',
					),
				),
				400,
				array( 'getMessage' => 'Error: Amount must be no more than $999,999.99 usd.' ),
			),
			// A nested error with message_code instead of code (client :2853).
			'code from message_code'                  => array(
				array(
					'error' => array(
						'message_code' => 'wcpay_platform_message_code',
						'message'      => 'Platform-coded failure.',
					),
				),
				400,
				array( 'get_error_code' => 'wcpay_platform_message_code' ),
			),
			// A nested error with only a type (client :2869).
			'code from the error type'                => array(
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'message' => 'Typed failure without a code.',
					),
				),
				400,
				array( 'get_error_code' => 'invalid_request_error' ),
			),
		);
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
			'webhook_secret'         => 'leak-webhook-secret',
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
	 * (WooPaymentsPaymentOperationService::send_refund()), so a log handler's own exception must never replace it. The error
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
		// One error line records the failed read (register row 297 decides the line, not its words).
		$this->assertCount( 1, array_filter( $logger->entries, static fn( array $entry ): bool => 'error' === $entry['level'] ), 'The read-failure line is written.' );
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
	 * @testdox Should require explicit payment method types for the $_dataName SetupIntent.
	 * @dataProvider provider_setup_intents_without_payment_method_types
	 *
	 * @param callable $send Makes the one call on the client.
	 */
	public function test_setup_intention_requires_explicit_payment_method_types( callable $send ): void {
		$sut = new WooPaymentsApiClient();
		$sut->init( new FakeWooPaymentsHttpClient(), $this->create_account_service( false ), $this->transport_log() );

		try {
			$send( $sut );
			$this->fail( 'Expected missing payment method types to be rejected.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 400, $exception->get_http_code() );
		}
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function provider_setup_intents_without_payment_method_types(): array {
		return array(
			'confirmed'   => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->create_and_confirm_setup_intention(
					array(
						'customer'       => 'cus_test',
						'payment_method' => 'pm_test',
					),
					'idem_setup'
				),
			),
			'unconfirmed' => array( static fn( WooPaymentsApiClient $sut ) => $sut->create_setup_intention( array( 'customer' => 'cus_test' ), 'idem_setup_unconfirmed' ) ),
		);
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
	 * @testdox The $_dataName call refuses its invalid route parameter with a 400 before sending anything.
	 * @dataProvider provider_invalid_route_parameters
	 *
	 * @param callable $send Makes the one call on the client.
	 * @param string   $code Expected error code.
	 */
	public function test_invalid_route_parameter_is_refused_before_transport( callable $send, string $code ): void {
		$http_client = new FakeWooPaymentsHttpClient();
		$sut         = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );

		try {
			$send( $sut );
			$this->fail( 'Expected the invalid route parameter to be refused.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( $code, $exception->get_error_code() );
			$this->assertSame( 400, $exception->get_http_code() );
		}

		$this->assertSame( 0, $http_client->request_count );
	}

	/**
	 * One row per call that puts a caller value in the request path or query: send, expected error code.
	 *
	 * @return array<string,array{0:callable,1:string}>
	 */
	public function provider_invalid_route_parameters(): array {
		$route = 'wcpay_route_validation_failure';

		return array(
			'list_payment_intentions'     => array( static fn( WooPaymentsApiClient $sut ) => $sut->list_payment_intentions( 'cus&customer=cus_other' ), $route ),
			'list_charge_refunds'         => array( static fn( WooPaymentsApiClient $sut ) => $sut->list_charge_refunds( 'ch_a&charge=ch_b' ), $route ),
			'get_payment_methods'         => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_payment_methods( 'cus-test', 'card' ), $route ),
			'get_dispute_summary'         => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_dispute_summary( '../du_test' ), $route ),
			'get_charge'                  => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_charge( '' ), $route ),
			'get_deposit'                 => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_deposit( '../po_test' ), $route ),
			'get_authorization'           => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_authorization( '../pi_auth' ), $route ),
			'get_transactions_export_url' => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_transactions_export_url( '../txexp_test' ), $route ),
			'get_fraud_outcomes'          => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_fraud_outcomes( array( 'status' => 'invalid' ) ), 'invalid_fraud_outcome_status' ),
			'get_latest_fraud_outcome'    => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_latest_fraud_outcome( 'pi-test' ), $route ),
			'get_disputes_export_url'     => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_disputes_export_url( 'dpexp%2Ftest' ), $route ),
			'get_document'                => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_document( '../vat_invoice-123' ), $route ),
			'get_payouts_export_url'      => array( static fn( WooPaymentsApiClient $sut ) => $sut->get_payouts_export_url( '../poexp_test' ), $route ),
		);
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
	 * Recorded on a local WPCOM platform (`Fixtures/platform-refund-list.json`): the platform proxies Stripe's refunds
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
	 * Load one recorded F458 (a) refund list entry by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{request:array{query:array<string,mixed>},response:array{body:array<string,mixed>}}
	 */
	private function load_recorded_refund_list_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-refund-list.json' );
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
	 * The body is the recorded platform response in Fixtures/platform-public-fraud-services.json.
	 *
	 * @testdox Should send the WooPayments user agent on the public fraud services request.
	 */
	public function test_fetch_public_fraud_services_config_sends_the_woopayments_user_agent(): void {
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
	 * @testdox Should start $_dataName onboarding through its platform endpoint with the account payload, the user token and the store identity.
	 * @dataProvider provider_onboarding_init_requests
	 *
	 * @param callable            $send          Makes the one call on the client and returns its result.
	 * @param array<string,mixed> $response_body Decoded body the platform answers with, returned as is.
	 * @param string              $path          Expected path.
	 * @param array<string,mixed> $body_values   Expected body values, a dot naming a key one level down.
	 */
	public function test_onboarding_init_request( callable $send, array $response_body, string $path, array $body_values ): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response_body ),
		);

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( true, null, 'store_123' ), $this->transport_log() );

		$result = $send( $sut );
		$body   = json_decode( (string) $http_client->last_body, true );

		$this->assertSame( $response_body, $result );
		$this->assertSame( $path, $http_client->last_path );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertIsArray( $body );
		foreach ( $body_values as $key => $value ) {
			$this->assertSame( array( true, $value ), self::param_at( $body, $key ), $key );
		}
		$this->assertTrue( $http_client->last_use_user_token );
		$this->assert_store_identity_in_onboarding_payload( $body );
	}

	/**
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_onboarding_init_requests(): array {
		$caller_payload = array(
			'site_data.site_locale'      => 'en_US',
			'user_data.email'            => 'merchant@example.com',
			'account_data.business_type' => 'individual',
			'actioned_notes'             => array( 'wcpay-promo-test' ),
			'test_mode'                  => true,
		);

		return array(
			'hosted'       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->initialize_onboarding( false, 'https://example.test/return', array( 'site_locale' => 'en_US' ), array( 'email' => 'merchant@example.com' ), array( 'business_type' => 'individual' ), array( 'wcpay-promo-test' ), false, 'ref_test' ),
				array(
					'url'   => 'https://connect.example.test',
					'state' => 'state_test',
				),
				'/sites/123/wcpay/onboarding/init',
				$caller_payload + array(
					'return_url'                  => 'https://example.test/return',
					'create_live_account'         => false,
					'collect_payout_requirements' => false,
					'referral_code'               => 'ref_test',
				),
			),
			'embedded KYC' => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->initialize_onboarding_embedded_kyc( true, array( 'site_locale' => 'en_US' ), array( 'email' => 'merchant@example.com' ), array( 'business_type' => 'individual' ), array( 'wcpay-promo-test' ) ),
				array(
					'client_secret'   => 'secret_test',
					'publishable_key' => 'pk_test',
				),
				'/sites/123/wcpay/onboarding/embedded',
				$caller_payload + array( 'create_live_account' => true ),
			),
		);
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
	 * @testdox Should pass the $_dataName onboarding payload, store identity included, through the existing onboarding payload filter and send what it returns.
	 * @dataProvider provider_onboarding_data_args_filter
	 *
	 * @param callable            $send        Makes the one call on the client.
	 * @param array<string,mixed> $seen_values Values the filter callback must see.
	 * @param array<string,mixed> $body_values Expected body values besides the ones the callback set.
	 */
	public function test_onboarding_data_args_filter( callable $send, array $seen_values, array $body_values ): void {
		list( $sut, $http_client ) = $this->make_sut( true );
		$seen                      = array();
		$filter                    = static function ( array $args ) use ( &$seen ): array {
			$seen                       = $args;
			$args['compatibility_data'] = array( 'woocommerce' => '11.0.0' );
			$args['account_data']['woocommerce_store_id'] = 'store_123';
			return $args;
		};
		add_filter( 'wc_payments_get_onboarding_data_args', $filter );

		try {
			$send( $sut );
		} finally {
			remove_filter( 'wc_payments_get_onboarding_data_args', $filter );
		}

		$body = json_decode( (string) $http_client->last_body, true );

		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'woocommerce_store_id', $seen, 'Filter callbacks see the store ID.' );
		$this->assertArrayHasKey( 'compatibility_data', $seen, 'Filter callbacks see the compatibility data.' );
		foreach ( $seen_values as $key => $value ) {
			$this->assertSame( array( true, $value ), self::param_at( $seen, $key ), $key );
		}
		$this->assertSame( array( 'woocommerce' => '11.0.0' ), $body['compatibility_data'] );
		$this->assertSame( 'store_123', $body['account_data']['woocommerce_store_id'] );
		foreach ( $body_values as $key => $value ) {
			$this->assertSame( array( true, $value ), self::param_at( $body, $key ), $key );
		}
	}

	/**
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_onboarding_data_args_filter(): array {
		return array(
			'hosted'       => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->initialize_onboarding( false, 'https://example.test/return', array( 'site_locale' => 'en_US' ), array( 'email' => 'merchant@example.com' ), array( 'business_type' => 'individual' ), array(), true, 'ref_test' ),
				array( 'collect_payout_requirements' => true ),
				array(
					'return_url'                  => 'https://example.test/return',
					'collect_payout_requirements' => true,
					'referral_code'               => 'ref_test',
				),
			),
			'embedded KYC' => array(
				static fn( WooPaymentsApiClient $sut ) => $sut->initialize_onboarding_embedded_kyc( true, array( 'site_locale' => 'en_US' ), array( 'email' => 'merchant@example.com' ), array( 'business_type' => 'individual' ), array() ),
				array( 'create_live_account' => true ),
				array( 'create_live_account' => true ),
			),
		);
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
	}

	/**
	 * @testdox Should preserve authorization summary filters and the summary hook.
	 */
	public function test_get_authorizations_summary_preserves_filters_and_summary_hook(): void {
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
	 * @testdox Should preserve the Capital link request filter object.
	 */
	public function test_create_capital_link_preserves_request_filter_object(): void {
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
	 * @testdox Closing a dispute posts the recorded body: only `test_mode`, never an empty body.
	 *
	 * REC-5b R-d `accept_close` (`Fixtures/platform-disputes.json`): the caller passes
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
	 * REC-5b R-d `win_update_pre_read`/`win_update_submit` (`Fixtures/platform-disputes.json`):
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
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-disputes.json' );
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
	 * @testdox The dispute status count request for the admin badge runs its request filter.
	 *
	 * The authorization summary's filter is pinned by test_get_authorizations_summary_preserves_filters_and_summary_hook.
	 */
	public function test_dispute_status_count_request_runs_its_request_filter(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'count' => 3 ) ),
		);

		$dispute_hook_called = false;

		$dispute_filter = function ( WooPaymentsApiRequest $request ) use ( &$dispute_hook_called ): WooPaymentsApiRequest {
			$dispute_hook_called = true;
			$this->assertSame( 'disputes/status_counts', $request->get_api() );
			$this->assertSame( 'GET', $request->get_method() );
			$request->set_param( 'status_is', 'needs_response' );

			return $request;
		};

		$sut = new WooPaymentsApiClient();
		$sut->init( $http_client, $this->create_account_service( false ), $this->transport_log() );
		add_filter( 'wcpay_get_dispute_status_counts', $dispute_filter );

		try {
			$sut->get_dispute_status_counts();
			$dispute_path = $http_client->last_path;
		} finally {
			remove_filter( 'wcpay_get_dispute_status_counts', $dispute_filter );
		}

		$this->assertTrue( $dispute_hook_called, 'Dispute badge count requests should run the preserved request filter.' );
		$this->assertStringContainsString( 'status_is=needs_response', $dispute_path );
		$this->assertStringContainsString( 'test_mode=0', $dispute_path );
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
	public function test_get_documents_preserves_request_filter_contract(): void {
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
	 * @testdox Should validate VAT through the preserved VAT request filter.
	 */
	public function test_validate_vat_preserves_request_filter_contract(): void {
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
	 * @testdox Should retrieve reporting balance summary through the preserved request object and filter.
	 */
	public function test_get_reporting_balance_summary_preserves_request_filter_contract(): void {
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
	 * The platform's minimum recurring amount endpoint answers with a bare integer (`Fixtures/platform-stripe-billing-api.json`).
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
	 * @testdox A platform fraud-flagged decline or card-testing error rotates the card-testing prevention token; ordinary declines leave it alone.
	 *
	 * Client 11.1.0 rotates on a nested error's decline code and on a top-level error code (wc-payment-api/class-wc-payments-api-client.php:2867, :2873-2874).
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

		// Each platform error reaches the client through a public request, as a 402 answer to a refund.
		// An ordinary decline must not rotate.
		$this->capture_api_error(
			array(
				'error' => array(
					'code'         => 'card_declined',
					'decline_code' => 'insufficient_funds',
					'message'      => 'declined',
				),
			),
			402
		);
		$this->assertSame( $original_token, $fraud_prevention_service->get_token() );

		// A fraud-flagged decline must rotate.
		$this->capture_api_error(
			array(
				'error' => array(
					'code'         => 'card_declined',
					'decline_code' => 'fraudulent',
					'message'      => 'declined',
				),
			),
			402
		);
		$rotated_token = $fraud_prevention_service->get_token();
		$this->assertNotSame( $original_token, $rotated_token );

		// The platform's own card-testing refusal, a top-level code and message, rotates it again (client 11.1.0
		// class-wc-payments-api-client.php:2873-2874; the fraudulent decline code above is :2867).
		$this->capture_api_error(
			array(
				'code'    => 'wcpay_card_testing_prevention',
				'message' => 'blocked',
			),
			402
		);
		$this->assertNotSame( $rotated_token, $fraud_prevention_service->get_token() );

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
