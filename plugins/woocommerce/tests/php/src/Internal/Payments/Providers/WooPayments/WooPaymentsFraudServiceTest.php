<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the WooPaymentsFraudService class.
 */
class WooPaymentsFraudServiceTest extends WC_Unit_Test_Case {

	/**
	 * Captured URLs of intercepted public fraud-services config requests.
	 *
	 * @var string[]
	 */
	private array $intercepted_request_urls = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->intercepted_request_urls = array();
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_session_store_id' );
		delete_transient( 'woocommerce_woopayments_public_fraud_services' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woocommerce_woopayments_fraud_services_config' );
		remove_all_filters( 'woocommerce_woopayments_fraud_service_config' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Should swap in the sandbox beacon key and drop it from the sift config when test mode is enabled.
	 */
	public function test_prepares_sift_config_in_test_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services(
			array(
				'sift' => array(
					'beacon_key'         => 'prod_beacon',
					'sandbox_beacon_key' => 'sandbox_beacon',
				),
			)
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertArrayHasKey( 'sift', $config );
		$this->assertSame( 'sandbox_beacon', $config['sift']['beacon_key'] );
		$this->assertArrayNotHasKey( 'sandbox_beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should keep the production beacon key and drop the sandbox key when test mode is disabled.
	 */
	public function test_prepares_sift_config_in_live_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		$this->seed_account_fraud_services(
			array(
				'sift' => array(
					'beacon_key'         => 'prod_beacon',
					'sandbox_beacon_key' => 'sandbox_beacon',
				),
			)
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( 'prod_beacon', $config['sift']['beacon_key'] );
		$this->assertArrayNotHasKey( 'sandbox_beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should ship no beacon key at all in test mode when the platform sent no sandbox key.
	 */
	public function test_drops_beacon_key_in_test_mode_without_sandbox_key(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services(
			array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) )
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertArrayNotHasKey( 'beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should inject an empty sift user_id and a session_id entry for logged-out shoppers.
	 */
	public function test_injects_sift_identity_keys_for_logged_out_shopper(): void {
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( '', $config['sift']['user_id'] );
		$this->assertArrayHasKey( 'session_id', $config['sift'] );
	}

	/**
	 * @testdox Should use the shopper's WooPayments customer ID as the sift user_id on the front end.
	 */
	public function test_sift_user_id_is_customer_id_for_logged_in_shopper(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_sift_shopper' );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( 'cus_sift_shopper', $config['sift']['user_id'] );
	}

	/**
	 * @testdox Should derive the sift session_id from the persisted store ID and the WooCommerce session customer.
	 */
	public function test_sift_session_id_derives_from_persisted_store_id(): void {
		update_option( 'wcpay_session_store_id', 'store_abc' );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		WC()->initialize_session();

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame(
			'store_abc_' . (string) WC()->session->get_customer_id(),
			$config['sift']['session_id']
		);
	}

	/**
	 * @testdox Should fall back to the platform's public fraud-services config when the account payload has none, and cache it.
	 */
	public function test_falls_back_to_cached_public_config(): void {
		$this->intercept_public_config_request(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'sift' => array( 'beacon_key' => 'public_beacon' ) ) ),
			)
		);

		$sut    = $this->make_sut();
		$config = $sut->get_fraud_services_config();

		$this->assertSame( 'public_beacon', $config['sift']['beacon_key'] );
		$this->assertCount( 1, $this->intercepted_request_urls );
		$this->assertStringContainsString( 'wcpay/accounts/fraud_services', $this->intercepted_request_urls[0] );

		// A second read must come from the cache, not a second platform request.
		$sut->get_fraud_services_config();
		$this->assertCount( 1, $this->intercepted_request_urls );
	}

	/**
	 * @testdox Should default to a bare stripe service entry when neither account nor public config is available.
	 */
	public function test_defaults_to_stripe_service_when_nothing_available(): void {
		$this->intercept_public_config_request( new WP_Error( 'http_request_failed', 'no route to platform' ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array( 'stripe' => array() ), $config );
	}

	/**
	 * @testdox Should respect an explicitly empty account fraud-services list without falling back.
	 */
	public function test_respects_explicitly_empty_account_config(): void {
		$this->seed_account_fraud_services( array() );
		$this->intercept_public_config_request( new WP_Error( 'http_request_failed', 'must not be called' ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array(), $config );
		$this->assertCount( 0, $this->intercepted_request_urls );
	}

	/**
	 * @testdox Should expose the whole prepared config through the fraud-services filter.
	 */
	public function test_whole_config_filter_applies_to_prepared_config(): void {
		$this->seed_account_fraud_services( array( 'stripe' => array() ) );

		add_filter(
			'woocommerce_woopayments_fraud_services_config',
			static function ( array $config ): array {
				$config['sift'] = array( 'beacon_key' => 'beacon_test' );
				return $config;
			}
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array(), $config['stripe'] );
		$this->assertSame( array( 'beacon_key' => 'beacon_test' ), $config['sift'] );
	}

	/**
	 * @testdox Should let the per-service filter disable a single service by returning null.
	 */
	public function test_per_service_filter_can_disable_a_service(): void {
		$this->seed_account_fraud_services(
			array(
				'stripe' => array(),
				'sift'   => array( 'beacon_key' => 'prod_beacon' ),
			)
		);

		add_filter(
			'woocommerce_woopayments_fraud_service_config',
			function ( $config, string $service_id ) {
				return 'sift' === $service_id ? null : $config;
			},
			10,
			2
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertNull( $config['sift'] );
		$this->assertSame( array(), $config['stripe'] );
	}

	/**
	 * Seed the preserved account payload with a fraud-services config.
	 *
	 * Written through the account service's own cache writer so the cache
	 * envelope is valid and no account refresh fires mid-test.
	 *
	 * @param array<string,mixed> $fraud_services Fraud-services config to store on the account payload.
	 */
	private function seed_account_fraud_services( array $fraud_services ): void {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );
		$account_service->cache_account_data(
			array(
				'account_id'     => 'acct_fraud_service_test',
				'is_live'        => true,
				'fraud_services' => $fraud_services,
			)
		);
	}

	/**
	 * Block every platform request, answering only the public fraud-services
	 * config request with the given response.
	 *
	 * Unit tests must never reach the real platform: without this, the
	 * public-config fallback would fetch production's actual fraud config.
	 *
	 * @param array<string,mixed>|WP_Error $response Response (or error) the fraud-services request should receive.
	 */
	private function intercept_public_config_request( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, string $url ) use ( $response ) {
				if ( false !== strpos( $url, 'fraud_services' ) ) {
					$this->intercepted_request_urls[] = $url;

					return $response;
				}
				if ( false !== strpos( $url, 'public-api.wordpress.com' ) ) {
					return new WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' );
				}

				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Create the service under test with real collaborators.
	 *
	 * @return WooPaymentsFraudService
	 */
	private function make_sut(): WooPaymentsFraudService {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );

		$api_client = new WooPaymentsApiClient();

		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, new WooPaymentsSessionService() );

		$sut = new WooPaymentsFraudService();
		$sut->init( $account_service, $customer_service, new WooPaymentsSessionService(), $api_client );

		return $sut;
	}
}
