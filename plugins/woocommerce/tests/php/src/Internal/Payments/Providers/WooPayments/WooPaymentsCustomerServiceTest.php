<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCustomerService class.
 */
class WooPaymentsCustomerServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( WC()->session ) {
			WC()->session->set( 'wcpay_customer_id', null );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Logged-in shoppers should use mode-aware user storage for WooPayments customer IDs.
	 */
	public function test_get_or_create_customer_id_uses_mode_aware_user_storage_for_logged_in_customers(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'merchant' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_live', 'cus_test' ) );

		$live_sut = $this->create_sut( false, $api_client );
		$this->assertSame( 'cus_live', $live_sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_live', get_user_option( '_wcpay_customer_id_live', $user_id ) );

		delete_user_option( $user_id, '_wcpay_customer_id_live' );

		$test_sut = $this->create_sut( true, $api_client );
		$this->assertSame( 'cus_test', $test_sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_test', get_user_option( '_wcpay_customer_id_test', $user_id ) );
	}

	/**
	 * @testdox Guest shoppers should use session storage for WooPayments customer IDs.
	 */
	public function test_get_or_create_customer_id_uses_session_storage_for_guests(): void {
		$order      = $this->create_checkout_order();
		$api_client = $this->create_customer_api_client( array( 'cus_guest' ) );

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_guest', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_guest', WC()->session ? WC()->session->get( 'wcpay_customer_id' ) : null );
		$this->assertSame( 'cus_guest', $sut->get_or_create_customer_id_for_order( $order ) );
	}

	/**
	 * @testdox Orders with WooPayments customer meta should reuse that customer before user storage.
	 */
	public function test_get_or_create_customer_id_reuses_order_customer_meta_before_user_storage(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'renewal-customer' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_user' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_order' );
		$order->save();

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_order', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_order', get_user_option( '_wcpay_customer_id_live', $user_id ) );
	}

	/**
	 * @testdox Without lock contention and no stored ID, the customer is created once and persisted.
	 */
	public function test_get_or_create_customer_id_creates_once_without_lock_contention(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'first-checkout' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_created' ) );

		$sut = $this->create_sut( false, $api_client );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		$this->assertSame( 'cus_created', $result );
		$this->assertCount( 1, $api_client->created_customers );
		$this->assertSame( 'cus_created', get_user_option( '_wcpay_customer_id_live', $user_id ) );
		// The advisory lock is released once creation completes.
		$this->assertFalse( wp_cache_get( 'wcpay_customer_create_' . $user_id, 'woopayments' ) );
	}

	/**
	 * @testdox Losing the creation lock should reuse the concurrently created customer instead of creating a duplicate.
	 */
	public function test_get_or_create_customer_id_reuses_customer_created_by_concurrent_request(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'concurrent' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_should_not_be_created' ) );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$sut = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$sut->init( $api_client, $account_service );

		// The fast-path read misses; the re-read after losing the lock finds the
		// ID that the winning concurrent request just persisted.
		$sut->method( 'get_customer_id_by_user_id' )
			->willReturnOnConsecutiveCalls( null, 'cus_existing' );

		// Simulate another concurrent request already holding the creation lock.
		wp_cache_add( 'wcpay_customer_create_' . $user_id, 1, 'woopayments', 10 );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		$this->assertSame( 'cus_existing', $result );
		$this->assertSame( array(), $api_client->created_customers );

		wp_cache_delete( 'wcpay_customer_create_' . $user_id, 'woopayments' );
	}

	/**
	 * @testdox Losing the lock while the ID is still unstored still creates and must not release the lock owner's key.
	 */
	public function test_get_or_create_customer_id_creates_on_fall_through_without_releasing_others_lock(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'fall-through' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_fallthrough' ) );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$sut = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$sut->init( $api_client, $account_service );

		// Both the fast-path read and the re-read after losing the lock miss: the
		// lock holder has not persisted the ID yet, so this request must create.
		$sut->method( 'get_customer_id_by_user_id' )->willReturn( null );

		// Simulate another concurrent request already holding the creation lock.
		wp_cache_add( 'wcpay_customer_create_' . $user_id, 1, 'woopayments', 10 );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		// No regression: the customer is still created and persisted.
		$this->assertSame( 'cus_fallthrough', $result );
		$this->assertCount( 1, $api_client->created_customers );

		// Ownership: this request never acquired the lock, so it must not have
		// deleted the lock owner's key on the way out.
		$this->assertNotFalse( wp_cache_get( 'wcpay_customer_create_' . $user_id, 'woopayments' ) );

		wp_cache_delete( 'wcpay_customer_create_' . $user_id, 'woopayments' );
	}

	/**
	 * @testdox Recreating a missing customer should replace the persisted customer ID.
	 */
	public function test_recreate_customer_replaces_a_missing_customer_id_and_updates_storage(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'recreate-me' ) );
		$order   = $this->create_checkout_order( $user_id );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_old' );

		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_new', $sut->recreate_customer_for_order( $order ) );
		$this->assertSame( 'cus_new', get_user_option( '_wcpay_customer_id_live', $user_id ) );
	}

	/**
	 * @testdox Should update the customer's default WooPayments payment method.
	 */
	public function test_set_default_payment_method_for_customer_updates_invoice_settings(): void {
		$api_client = $this->create_customer_api_client( array() );
		$sut        = $this->create_sut( false, $api_client );

		$sut->set_default_payment_method_for_customer( 'cus_test', 'pm_default' );

		$this->assertSame( 'cus_test', $api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'pm_default', $api_client->updated_customers[0]['customer_data']['invoice_settings']['default_payment_method'] );
	}

	/**
	 * @testdox Should retrieve payment methods for a customer through the API client.
	 */
	public function test_get_payment_methods_for_customer_delegates_to_api_client(): void {
		$api_client                          = $this->create_customer_api_client( array() );
		$api_client->payment_methods_by_type = array(
			'card' => array(
				array(
					'id'   => 'pm_card',
					'type' => 'card',
				),
			),
		);
		$sut                                 = $this->create_sut( false, $api_client );
		$result                              = $sut->get_payment_methods_for_customer( 'cus_test', 'card' );

		$this->assertSame( 'pm_card', $result[0]['id'] );
		$this->assertSame(
			array(
				array(
					'customer_id' => 'cus_test',
					'type'        => 'card',
					'limit'       => 100,
				),
			),
			$api_client->payment_methods_requests
		);
	}

	/**
	 * @testdox Should return no payment methods for an empty customer ID.
	 */
	public function test_get_payment_methods_for_customer_returns_empty_for_missing_customer(): void {
		$api_client = $this->create_customer_api_client( array() );
		$sut        = $this->create_sut( false, $api_client );

		$result = $sut->get_payment_methods_for_customer( '', 'card' );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $api_client->payment_methods_requests );
	}

	/**
	 * @testdox Should return no payment methods when the remote customer is missing.
	 */
	public function test_get_payment_methods_for_customer_returns_empty_for_missing_remote_customer(): void {
		$api_client                            = $this->create_customer_api_client( array() );
		$api_client->payment_methods_exception = new WooPaymentsApiException( 'Missing customer.', 'resource_missing', 404 );
		$sut                                   = $this->create_sut( false, $api_client );

		$result = $sut->get_payment_methods_for_customer( 'cus_missing', 'card' );

		$this->assertSame( array(), $result );
		$this->assertSame( 'cus_missing', $api_client->payment_methods_requests[0]['customer_id'] );
	}

	/**
	 * @testdox Should rethrow payment method API errors other than missing remote customers.
	 */
	public function test_get_payment_methods_for_customer_rethrows_unhandled_api_errors(): void {
		$api_client                            = $this->create_customer_api_client( array() );
		$api_client->payment_methods_exception = new WooPaymentsApiException( 'Forbidden.', 'wcpay_forbidden', 403 );
		$sut                                   = $this->create_sut( false, $api_client );

		$this->expectException( WooPaymentsApiException::class );
		$this->expectExceptionMessage( 'Forbidden.' );

		$sut->get_payment_methods_for_customer( 'cus_test', 'card' );
	}

	/**
	 * @testdox Registering hooks should add the WooPayments customer-data eraser to the GDPR registry.
	 */
	public function test_register_adds_personal_data_eraser(): void {
		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$sut->register();

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( $sut, 'register_personal_data_eraser' ) ) );

		$erasers = $sut->register_personal_data_eraser( array() );
		$this->assertArrayHasKey( 'woocommerce-payments-customer', $erasers );
		$this->assertSame( array( $sut, 'erase_customer_data' ), $erasers['woocommerce-payments-customer']['callback'] );
	}

	/**
	 * @testdox Erasing personal data should delete all stored WooPayments customer IDs for the user.
	 */
	public function test_erase_customer_data_deletes_stored_customer_ids(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-me@example.com' ) );

		update_user_option( $user_id, '_wcpay_customer_id', 'cus_deprecated' );
		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_live' );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_test' );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'erase-me@example.com' );

		$this->assertFalse( get_user_option( '_wcpay_customer_id', $user_id ) );
		$this->assertFalse( get_user_option( '_wcpay_customer_id_live', $user_id ) );
		$this->assertFalse( get_user_option( '_wcpay_customer_id_test', $user_id ) );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @testdox Erasing personal data for an unknown email should return the done shape without errors.
	 */
	public function test_erase_customer_data_for_unknown_email_reports_nothing_removed(): void {
		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @testdox Erasing personal data for a user without any stored IDs should report nothing removed.
	 */
	public function test_erase_customer_data_without_stored_ids_reports_nothing_removed(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'clean@example.com' ) );
		unset( $user_id );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'clean@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Create a customer service System Under Test.
	 *
	 * @param bool                 $test_mode  Whether test mode is enabled.
	 * @param WooPaymentsApiClient $api_client Native API client mock.
	 * @return WooPaymentsCustomerService
	 */
	private function create_sut( bool $test_mode, WooPaymentsApiClient $api_client ): WooPaymentsCustomerService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		$sut = new WooPaymentsCustomerService();
		$sut->init( $api_client, $account_service );

		return $sut;
	}

	/**
	 * Create a concrete API-client double for customer creation.
	 *
	 * @param string[] $customer_ids Customer IDs to return in order.
	 * @return WooPaymentsApiClient
	 */
	private function create_customer_api_client( array $customer_ids ): WooPaymentsApiClient {
		return new class( $customer_ids ) extends WooPaymentsApiClient {
			/**
			 * Customer IDs to return.
			 *
			 * @var string[]
			 */
			private array $customer_ids;

			/**
			 * Updated customer payloads.
			 *
			 * @var array<int,array{customer_id:string,customer_data:array<string,mixed>}>
			 */
			public array $updated_customers = array();

			/**
			 * Created customer payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $created_customers = array();

			/**
			 * Payment methods keyed by type.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $payment_methods_by_type = array();

			/**
			 * Payment method list requests.
			 *
			 * @var array<int,array{customer_id:string,type:string,limit:int}>
			 */
			public array $payment_methods_requests = array();

			/**
			 * Optional exception thrown by payment method list requests.
			 *
			 * @var WooPaymentsApiException|null
			 */
			public ?WooPaymentsApiException $payment_methods_exception = null;

			/**
			 * Constructor.
			 *
			 * @param string[] $customer_ids Customer IDs to return.
			 */
			public function __construct( array $customer_ids ) {
				$this->customer_ids = $customer_ids;
			}

			/**
			 * Create a customer.
			 *
			 * @param array<string,mixed> $customer_data Customer data.
			 * @return string
			 */
			public function create_customer( array $customer_data ): string {
				$this->created_customers[] = $customer_data;

				return (string) array_shift( $this->customer_ids );
			}

			/**
			 * Update a customer.
			 *
			 * @param string              $customer_id Customer ID.
			 * @param array<string,mixed> $customer_data Customer data.
			 */
			public function update_customer( string $customer_id, array $customer_data = array() ): void {
				$this->updated_customers[] = array(
					'customer_id'   => $customer_id,
					'customer_data' => $customer_data,
				);
			}

			/**
			 * Retrieve customer payment methods.
			 *
			 * @param string $customer_id Customer ID.
			 * @param string $type        Payment method type.
			 * @param int    $limit       Result limit.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured.
			 */
			public function get_payment_methods( string $customer_id, string $type, int $limit = 100 ): array {
				$this->payment_methods_requests[] = array(
					'customer_id' => $customer_id,
					'type'        => $type,
					'limit'       => $limit,
				);

				if ( null !== $this->payment_methods_exception ) {
					throw $this->payment_methods_exception;
				}

				return array(
					'data' => $this->payment_methods_by_type[ $type ] ?? array(),
				);
			}
		};
	}

	/**
	 * Create a checkout order fixture.
	 *
	 * @param int $customer_id Customer ID.
	 * @return WC_Order
	 */
	private function create_checkout_order( int $customer_id = 0 ): WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_billing_phone( '+40123456789' );
		$order->set_billing_address_1( '1 Core St' );
		$order->set_billing_city( 'Bucharest' );
		$order->set_billing_postcode( '010101' );
		$order->set_billing_country( 'RO' );
		$order->set_shipping_first_name( 'Ada' );
		$order->set_shipping_last_name( 'Lovelace' );
		$order->set_shipping_address_1( '1 Core St' );
		$order->set_shipping_city( 'Bucharest' );
		$order->set_shipping_postcode( '010101' );
		$order->set_shipping_country( 'RO' );
		$order->save();

		return $order;
	}
}
