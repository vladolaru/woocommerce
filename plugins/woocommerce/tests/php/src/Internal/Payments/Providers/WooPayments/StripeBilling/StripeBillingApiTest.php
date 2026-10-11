<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_Unit_Test_Case;

/**
 * Contract of the Stripe Billing platform calls, against responses recorded from the local platform
 * (`Fixtures/rec-t63-billing-api.json`); paths, methods and params follow client 11.1.0.
 */
class StripeBillingApiTest extends WC_Unit_Test_Case {

	private const FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';

	/**
	 * Blog ID the recordings were made on.
	 */
	private const RECORDED_BLOG_ID = 4;

	/**
	 * @testdox $pair sends the recorded request and returns the recorded response.
	 * @dataProvider platform_calls
	 *
	 * @param string   $pair Fixture entry.
	 * @param callable $call Call under test, given the API, the recorded request params and the path ID.
	 */
	public function test_platform_call_matches_the_recording( string $pair, callable $call ): void {
		$entry                     = $this->get_entry( $pair );
		list( $sut, $http_client ) = $this->make_sut( $entry );

		$result = $call( $sut, $this->get_call_params( $entry ), $this->get_path_id( $entry ) );

		$this->assertSame( $entry['request']['method'], $http_client->last_method );
		$this->assertSame( $this->get_recorded_path( $entry ), $http_client->last_path );
		$this->assertFalse( $http_client->last_use_user_token, 'Stripe Billing calls are signed with the store token.' );
		if ( 'POST' === $entry['request']['method'] ) {
			$this->assertSame( $entry['request']['body'], json_decode( (string) $http_client->last_body, true ) );
		}
		if ( 'update_price' !== $pair ) {
			$this->assertSame( $entry['response']['body'], $result );
		}
	}

	/** @return array<string,array{string,callable}> */
	public static function platform_calls(): array {
		return array(
			'get_product_by_id'                     => array( 'get_product_by_id', static fn( StripeBillingApi $api, array $params, string $id ) => $api->get_product_by_id( $id ) ),
			'create_product'                        => array( 'create_product', static fn( StripeBillingApi $api, array $params ) => $api->create_product( $params ) ),
			'update_product'                        => array( 'update_product', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_product( $id, $params ) ),
			'update_price'                          => array( 'update_price', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_price( $id, $params ) ),
			'get_subscription'                      => array( 'get_subscription', static fn( StripeBillingApi $api, array $params, string $id ) => $api->get_subscription( $id ) ),
			'create_subscription'                   => array( 'create_subscription', static fn( StripeBillingApi $api, array $params ) => $api->create_subscription( $params ) ),
			'update_subscription'                   => array( 'update_subscription', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_subscription( $id, $params ) ),
			'cancel_subscription'                   => array( 'cancel_subscription', static fn( StripeBillingApi $api, array $params, string $id ) => $api->cancel_subscription( $id ) ),
			'update_subscription_item'              => array( 'update_subscription_item', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_subscription_item( $id, $params ) ),
			'charge_invoice'                        => array( 'charge_invoice', static fn( StripeBillingApi $api, array $params, string $id ) => $api->charge_invoice( $id, $params ) ),
			'update_invoice'                        => array( 'update_invoice', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_invoice( $id, $params ) ),
			'get_currency_minimum_recurring_amount' => array( 'get_currency_minimum_recurring_amount', static fn( StripeBillingApi $api, array $params, string $currency ) => $api->get_currency_minimum_recurring_amount( $currency ) ),
			'update_charge'                         => array( 'update_charge', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_charge( $id, $params ) ),
			'update_transaction'                    => array( 'update_transaction', static fn( StripeBillingApi $api, array $params, string $id ) => $api->update_transaction( $id, $params ) ),
		);
	}

	/**
	 * @testdox An amount below the platform minimum fails with the minimum and the platform's currency, as the client's Amount_Too_Small_Exception (client `class-wc-payments-api-client.php:2845-2851`).
	 */
	public function test_amount_too_small_carries_the_minimum(): void {
		$entry       = $this->get_entry( 'error_amount_too_small' );
		list( $sut ) = $this->make_sut( $entry );

		try {
			$sut->create_subscription( $this->get_call_params( $entry ) );
			$this->fail( 'An amount below the minimum must fail.' );
		} catch ( StripeBillingException $exception ) {
			$this->assertSame( StripeBillingException::AMOUNT_TOO_SMALL, $exception->get_error_code() );
			$this->assertSame( $entry['response']['body']['data']['minimum_amount'], $exception->get_data()['minimum_amount'] );
			$this->assertSame( $entry['response']['body']['data']['currency'], $exception->get_data()['currency'], 'The currency is passed on as the platform sends it.' );
			$this->assertSame( $entry['response']['body']['message'], $exception->getMessage() );
		}
	}

	/**
	 * @testdox A customer with subscriptions in another currency fails with that currency, as the client's Cannot_Combine_Currencies_Exception.
	 */
	public function test_mixed_currencies_carry_the_existing_currency(): void {
		$entry       = $this->get_entry( 'error_combine_currencies' );
		list( $sut ) = $this->make_sut( $entry );

		try {
			$sut->create_subscription( $this->get_call_params( $entry ) );
			$this->fail( 'A second currency on the customer must fail.' );
		} catch ( StripeBillingException $exception ) {
			$this->assertSame( StripeBillingException::CANNOT_COMBINE_CURRENCIES, $exception->get_error_code() );
			// The recorded message ends "... with currency usd.".
			$this->assertSame( 'USD', $exception->get_data()['currency'] );
			$this->assertSame( $entry['response']['body']['message'], $exception->getMessage() );
		}
	}

	/**
	 * @testdox An ID that could change the request path is refused before any request, as the client does.
	 */
	public function test_unsafe_id_is_refused_before_the_request(): void {
		list( $sut, $http_client ) = $this->make_sut( $this->get_entry( 'cancel_subscription' ) );

		try {
			$sut->cancel_subscription( 'sub_1/../../accounts' );
			$this->fail( 'An unsafe ID must be refused.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_route_validation_failure', $exception->get_error_code() );
		}
		$this->assertSame( 0, $http_client->request_count );
	}

	/**
	 * @testdox An empty product or price ID fails with the client's own code before any request; a non-empty ID still has to be safe (client `class-wc-payments-api-client.php:1462-1510`).
	 * @testWith ["update_product", "", "wcpay_mandatory_product_id_missing", "Product ID is required"]
	 *           ["update_product", "  ", "wcpay_mandatory_product_id_missing", "Product ID is required"]
	 *           ["update_price", "", "wcpay_mandatory_price_id_missing", "Price ID is required"]
	 *           ["update_price", "price_1/../accounts", "wcpay_route_validation_failure", "Route param validation failed."]
	 *
	 * @param string $method     API method.
	 * @param string $id         ID passed.
	 * @param string $error_code Expected failure code.
	 * @param string $message    Expected failure message.
	 */
	public function test_empty_product_or_price_id_is_refused_before_the_request( string $method, string $id, string $error_code, string $message ): void {
		list( $sut, $http_client ) = $this->make_sut( $this->get_entry( $method ) );

		try {
			$sut->$method( $id, array( 'active' => 'false' ) );
			$this->fail( 'The ID must be refused.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( $error_code, $exception->get_error_code() );
			$this->assertSame( $message, $exception->getMessage() );
			$this->assertSame( 400, $exception->get_http_code() );
		}
		$this->assertSame( 0, $http_client->request_count );
	}

	/**
	 * @testdox The minimum recurring amount is cast to an integer whatever JSON type the platform answers with (client `class-wc-payments-api-client.php:2516`).
	 */
	public function test_minimum_recurring_amount_is_cast_to_an_integer(): void {
		$entry                     = $this->get_entry( 'get_currency_minimum_recurring_amount' );
		$entry['response']['body'] = (string) $entry['response']['body'];
		list( $sut )               = $this->make_sut( $entry );

		$this->assertSame( 100, $sut->get_currency_minimum_recurring_amount( 'usd' ) );
	}

	/**
	 * Build the API over a fake transport that answers with the entry's recorded response.
	 *
	 * @param array<string,mixed> $entry Fixture entry.
	 * @return array{0: StripeBillingApi, 1: FakeWooPaymentsHttpClient}
	 */
	private function make_sut( array $entry ): array {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = self::RECORDED_BLOG_ID;
		$http_client->response = array(
			'response' => array( 'code' => (int) $entry['response']['http_status'] ),
			'headers'  => array( 'content-type' => $entry['response']['content_type'] ),
			'body'     => wp_json_encode( $entry['response']['body'] ),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( WooPaymentsTransportLog::class ) );

		$sut = new StripeBillingApi();
		$sut->init( $api_client );

		return array( $sut, $http_client );
	}

	/**
	 * Get a fixture entry.
	 *
	 * @param string $pair Entry name.
	 * @return array<string,mixed>
	 */
	private function get_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$fixture = json_decode( (string) file_get_contents( self::FIXTURE ), true );
		foreach ( $fixture['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return $entry;
			}
		}

		$this->fail( "Fixture entry $pair is missing." );
	}

	/**
	 * Get the params the caller passed: the recorded body without the test-mode flag the transport adds.
	 *
	 * @param array<string,mixed> $entry Fixture entry.
	 * @return array<string,mixed>
	 */
	private function get_call_params( array $entry ): array {
		$body = is_array( $entry['request']['body'] ) ? $entry['request']['body'] : array();
		unset( $body['test_mode'] );

		return $body;
	}

	/**
	 * Get the recorded request path, without the recording's transport note.
	 *
	 * @param array<string,mixed> $entry Fixture entry.
	 * @return string
	 */
	private function get_recorded_path( array $entry ): string {
		return strtok( (string) $entry['request']['path'], ' ' );
	}

	/**
	 * Get the Stripe ID the recorded path ends with, before any `/pay` action.
	 *
	 * @param array<string,mixed> $entry Fixture entry.
	 * @return string
	 */
	private function get_path_id( array $entry ): string {
		$path = (string) strtok( $this->get_recorded_path( $entry ), '?' );
		$path = preg_replace( '#/pay$#', '', $path );

		return (string) substr( (string) strrchr( (string) $path, '/' ), 1 );
	}
}
