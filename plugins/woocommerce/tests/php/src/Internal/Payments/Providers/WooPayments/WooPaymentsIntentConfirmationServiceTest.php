<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentConfirmationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentConfirmationService class.
 */
class WooPaymentsIntentConfirmationServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Confirming an intent for an order fetches it once and saves its intent and payment method IDs on the order.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`): RECORD swap. The intent is now REC-3DS-2's real completed
	 * challenge (`Fixtures/platform-3ds-challenge-results.json`, pair `classic_checkout_challenge_completed`):
	 * a classic checkout that finished a hosted 3DS challenge, obtained via one API-only GET of the
	 * resulting PaymentIntent through native transport after a real Playwright run.
	 */
	public function test_confirm_intent_for_order_fetches_the_intent_once_and_saves_intent_and_payment_method_ids(): void {
		$order = $this->create_woopayments_order( '10.99' );

		$recorded              = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( WooPaymentsTransportLog::class ) );
		$sut = $this->create_intent_confirmation_service( $api_client, null, $account_service );

		$sut->confirm_intent_for_order( $order, (string) $recorded['body']['id'], false );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $recorded['body']['id'], $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( $recorded['body']['payment_method'], $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 1, $http_client->request_count );
		$this->assertStringContainsString( 'intentions/' . rawurlencode( (string) $recorded['body']['id'] ), $http_client->last_path );
	}

	/**
	 * @testdox Confirming the intent leaves stock alone for a subscription payment method change and reduces it otherwise ($_dataName).
	 *
	 * Client 11.1.0 applies a payment method change's order status inside with_stock_reduction_disabled()
	 * (class-wc-payment-gateway-wcpay.php:4337-4345, helper :5483-5499), so completing the subscription's failed renewal
	 * through the change does not take stock twice. The intent is the same recording as the test above.
	 *
	 * @dataProvider stock_reduction_cases
	 *
	 * @param bool $is_payment_method_change Whether the confirm is a subscription payment method change.
	 * @param int  $expected_stock           Stock left after the confirm.
	 */
	public function test_confirm_intent_for_order_keeps_stock_for_a_payment_method_change( bool $is_payment_method_change, int $expected_stock ): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
		$order = $this->create_woopayments_order( '10.99' );
		$order->add_product( $product, 3 );
		$order->set_total( '10.99' );
		$order->save();

		$recorded              = $this->load_recorded_manual_3ds_entry( 'classic_checkout_challenge_completed' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $recorded['body'] ),
		);
		$account_service       = $this->create_account_service( false );
		$api_client            = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( WooPaymentsTransportLog::class ) );
		$sut = $this->create_intent_confirmation_service( $api_client, null, $account_service );

		$sut->confirm_intent_for_order( $order, (string) $recorded['body']['id'], false, $is_payment_method_change );

		$this->assertSame( $recorded['body']['id'], wc_get_order( $order->get_id() )->get_meta( '_intent_id', true ), 'The confirm applied the intent.' );
		$this->assertSame( $expected_stock, wc_get_product( $product->get_id() )->get_stock_quantity() );
		$this->assertFalse( has_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false' ), 'Stock reduction is back on after the confirm.' );
	}

	/** @return array<string,array{bool,int}> */
	public static function stock_reduction_cases(): array {
		return array(
			'payment method change' => array( true, 10 ),
			'regular confirm'       => array( false, 7 ),
		);
	}

	/**
	 * Create the intent confirmation service with real collaborators over the given API client.
	 *
	 * @param WooPaymentsApiClient         $api_client      API client.
	 * @param WooPaymentsTokenService|null $token_service   Token service, or null for one whose details lookup finds nothing.
	 * @param WooPaymentsAccountService    $account_service Account service.
	 * @return WooPaymentsIntentConfirmationService
	 */
	private function create_intent_confirmation_service( WooPaymentsApiClient $api_client, ?WooPaymentsTokenService $token_service, WooPaymentsAccountService $account_service ): WooPaymentsIntentConfirmationService {
		$token_service  = $token_service ?? $this->create_token_service();
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			new WooPaymentsOrderNoteService(),
			new WooPaymentsPaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class )
		);

		$service = new WooPaymentsIntentConfirmationService();
		$service->init(
			$api_client,
			wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class ),
			$token_service,
			$account_service,
			$effect_applier
		);

		return $service;
	}

	/**
	 * Create a token service whose payment method details lookup finds nothing.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service(): WooPaymentsTokenService {
		$details_service = new class() extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Get payment method details.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				return array();
			}
		};

		$sut = new WooPaymentsTokenService();
		$sut->init( $details_service, new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ) );

		return $sut;
	}

	/**
	 * Create a WooPayments account service mock.
	 *
	 * @param bool $test_mode Whether WooPayments should run in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'is_test_mode_enabled', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();

		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		return $account_service;
	}

	/**
	 * Load one recorded 3DS challenge entry's response body by pair key.
	 *
	 * @param string $pair Fixture pair key.
	 * @return array{body:array<string,mixed>}
	 */
	private function load_recorded_manual_3ds_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/platform-3ds-challenge-results.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array( 'body' => $entry['response']['body'] );
			}
		}

		$this->fail( "The 3DS challenge fixture has no entry for pair '$pair'." );
	}

	/**
	 * Create a WooPayments test order.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
