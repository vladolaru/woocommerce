<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use InvalidArgumentException;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAccountEventHandler class.
 */
class WooPaymentsAccountEventHandlerTest extends WC_Unit_Test_Case {

	use WooPaymentsEventHandlerTestTrait;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		parent::tearDown();
	}

	/**
	 * @testdox is_supported_event() matches only the account lifecycle event types.
	 *
	 * Mirrors client 11.1.0's webhook dispatch switch
	 * (`includes/class-wc-payments-webhook-processing-service.php:205-225`), which handles
	 * `account.updated` and `account.deleted` and no other account-shaped event type.
	 */
	public function test_is_supported_event_matches_account_lifecycle_types_only(): void {
		$handler = new WooPaymentsAccountEventHandler();
		$handler->init(
			$this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->getMock(),
			$this->getMockBuilder( WooPaymentsTokenService::class )->disableOriginalConstructor()->getMock()
		);

		$this->assertTrue( $handler->is_supported_event( 'account.updated' ) );
		$this->assertTrue( $handler->is_supported_event( 'account.deleted' ) );
		$this->assertFalse( $handler->is_supported_event( 'customer.created' ) );
	}

	/**
	 * @testdox account.updated refreshes account data and clears the payment method caches.
	 *
	 * Mirrors client 11.1.0's non-strict `account.updated` handling
	 * (`includes/class-wc-payments-webhook-processing-service.php:205-208`), which never
	 * fails the webhook on a refresh failure. See C25 (verification-ledger.md V435).
	 */
	public function test_account_updated_refreshes_account_data_and_clears_payment_method_caches(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'refresh_account_data' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data' )
			->willReturn( array( 'account_id' => 'acct_123' ) );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $account_service, $token_service );

		$sut->process( $this->create_account_event( 'account.updated' ) );
	}

	/**
	 * @testdox account.updated clears every cached payment method row and acknowledges the webhook on a store with more than 500 of them.
	 *
	 * Client 11.1.0 clears all of them in one delete and acknowledges the event (class-wc-payments-token-service.php:168-189, webhook-processing-service.php:205-207).
	 */
	public function test_account_updated_clears_every_cached_payment_method_row(): void {
		global $wpdb;

		$meta_rows   = array();
		$option_rows = array();
		for ( $i = 1; $i <= 501; $i++ ) {
			$meta_rows[]   = $wpdb->prepare( '(%d, %s, %s)', 1000000 + $i, '_wcpay_payment_methods', 'a:0:{}' );
			$option_rows[] = $wpdb->prepare( '(%s, %s, %s)', 'wcpay_pm_cus_' . $i, 'a:0:{}', 'off' );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Each row is prepared above.
		$wpdb->query( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES " . implode( ',', $meta_rows ) );
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $option_rows ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'refresh_account_data' ) )
			->getMock();
		$account_service->method( 'refresh_account_data' )->willReturn( array( 'account_id' => 'acct_123' ) );

		$sut = $this->create_ingestor_with_account_services( $account_service, new WooPaymentsTokenService() );

		$sut->process( $this->create_account_event( 'account.updated' ) );

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", '_wcpay_payment_methods' ) ), 'Every cached payment method user meta row should be deleted.' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", 'wcpay\_pm\_%' ) ), 'Every cached wcpay_pm_ option should be deleted.' );
	}

	/**
	 * @testdox account.deleted resets account state, refreshes account data, and clears the payment method caches.
	 */
	public function test_account_deleted_cleans_state_refreshes_account_data_and_clears_payment_method_caches(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data', 'get_stored_account_id' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_stored_account_id' )
			->willReturn( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data' )
			->willReturn( array() );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $account_service, $token_service );

		$sut->process( $this->create_account_event( 'account.deleted' ) );
	}

	/**
	 * @testdox account.deleted ignores stale account deletion events for a different connected account.
	 */
	public function test_account_deleted_ignores_stale_delete_for_different_account(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data', 'get_stored_account_id' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_stored_account_id' )
			->willReturn( 'acct_current' );
		$account_service->expects( $this->never() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->never() )
			->method( 'refresh_account_data' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->never() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $account_service, $token_service );

		$sut->process( $this->create_account_event( 'account.deleted', 'acct_deleted' ) );
	}

	/**
	 * @testdox account.deleted acknowledges the webhook with the account state reset when the account refresh fails.
	 *
	 * Client 11.1.0 runs the reset, the non-strict refresh and the payment method cache clear, and answers 200
	 * whatever the refresh returns (webhook-processing-service.php:209-221). The webhook controller turns only a
	 * thrown exception into a 500, so process() returning is the 200.
	 */
	public function test_account_deleted_acknowledges_with_state_reset_when_refresh_fails(): void {
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $this->create_account_service_with_failing_refresh(), $token_service );
		$this->store_connected_account_state();

		$sut->process( $this->create_account_event( 'account.deleted', 'acct_123', true ) );

		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertSame( 'no', $settings['enabled'], 'The gateway should be disabled by the account reset.' );
		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'], 'The enabled payment methods should be reset to card.' );
		$this->assertFalse( get_option( 'woocommerce_woopayments_nox_profile' ), 'The onboarding profile should be cleared by the account reset.' );
	}

	/**
	 * @testdox A repeated account.deleted for the same account finds nothing to clean once the first one reset the account state.
	 *
	 * A redelivery of the same event is skipped by the ingestor once the first delivery was acknowledged, so this sends
	 * the deletion again as a new event.
	 */
	public function test_repeated_account_deleted_finds_nothing_to_clean(): void {
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->once() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $this->create_account_service_with_failing_refresh(), $token_service );
		$this->store_connected_account_state();
		$new_profile = array( 'onboarding' => array( 'steps' => array( 'business_verification' => array( 'status' => 'started' ) ) ) );

		$sut->process( $this->create_account_event( 'account.deleted', 'acct_123', true ) );
		// The merchant starts a new onboarding before the deletion arrives again.
		update_option( 'woocommerce_woopayments_nox_profile', $new_profile );
		$repeated_event       = $this->create_account_event( 'account.deleted', 'acct_123', true );
		$repeated_event['id'] = 'evt_account_repeated';
		$sut->process( $repeated_event );

		$this->assertSame( $new_profile, get_option( 'woocommerce_woopayments_nox_profile' ), 'The repeated deletion should not reset the new onboarding.' );
	}

	/**
	 * @testdox account.deleted fails closed when the event account ID is missing.
	 */
	public function test_account_deleted_fails_closed_for_missing_account_id(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->getMock();
		$token_service   = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->getMock();
		$sut             = $this->create_ingestor_with_account_services( $account_service, $token_service );
		$event           = $this->create_account_event( 'account.deleted' );
		unset( $event['data']['object']['id'] );

		$this->expectException( InvalidArgumentException::class );

		$sut->process( $event );
	}

	/**
	 * @testdox account.deleted fails closed and changes no state when the gateway is enabled but no local account is on record.
	 *
	 * Native-only money-safety hardening with no client counterpart: client 11.1.0's
	 * webhook dispatch for `account.deleted`
	 * (`includes/class-wc-payments-webhook-processing-service.php:210-220`) always resets
	 * and refreshes account data unconditionally. Native refuses to do that when it cannot
	 * verify the deletion belongs to the currently connected account while the gateway is
	 * still enabled, since resetting live payment state on an unverified event risks leaving
	 * payments running against an account that may already be gone.
	 */
	public function test_account_deleted_fails_closed_when_gateway_enabled_without_local_account(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data', 'get_stored_account_id', 'get_gateway_setting' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_stored_account_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_gateway_setting' )
			->with( 'enabled', 'no' )
			->willReturn( 'yes' );
		$account_service->expects( $this->never() )->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->never() )->method( 'refresh_account_data' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->never() )->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $account_service, $token_service );

		$this->expectException( RuntimeException::class );

		$sut->process( $this->create_account_event( 'account.deleted' ) );
	}

	/**
	 * Store the state of a live store connected to account acct_123: the account cache, enabled gateway settings and an onboarding profile.
	 */
	private function store_connected_account_state(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'           => 'acct_123',
					'live_publishable_key' => 'pk_live_123',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'yes',
				'test_mode'                      => 'no',
				'upe_enabled_payment_method_ids' => array( 'card', 'klarna' ),
			)
		);
		update_option( 'woocommerce_woopayments_nox_profile', array( 'onboarding' => array( 'steps' => array( 'payments' => array( 'status' => 'completed' ) ) ) ) );
	}

	/**
	 * Create the account service of a connected store whose account fetch fails.
	 *
	 * Shape: a platform error answer to `GET accounts`, which the API client raises as a WooPaymentsApiException
	 * with the answer's code and HTTP status.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_with_failing_refresh(): WooPaymentsAccountService {
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the store has a platform connection.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Fail the account fetch.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @throws WooPaymentsApiException Always.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				throw new WooPaymentsApiException( 'Internal server error.', 'wcpay_server_error', 500 );
			}
		};

		$account_service = new class( $api_client ) extends WooPaymentsAccountService {
			/**
			 * API client double.
			 *
			 * @var WooPaymentsApiClient
			 */
			private WooPaymentsApiClient $api_client;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiClient $api_client API client double.
			 */
			public function __construct( WooPaymentsApiClient $api_client ) {
				$this->api_client = $api_client;
			}

			/**
			 * Get the API client double.
			 *
			 * @return WooPaymentsApiClient|null
			 */
			protected function get_api_client(): ?WooPaymentsApiClient {
				return $this->api_client;
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );

		return $account_service;
	}

	/**
	 * Create an ingestor with mocked account event collaborators.
	 *
	 * @param WooPaymentsAccountService $account_service Account service mock.
	 * @param WooPaymentsTokenService   $token_service   Token service mock.
	 * @return WooPaymentsEventIngestor
	 */
	private function create_ingestor_with_account_services( WooPaymentsAccountService $account_service, WooPaymentsTokenService $token_service ): WooPaymentsEventIngestor {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$account_event_handler = new WooPaymentsAccountEventHandler();
		$account_event_handler->init( $account_service, $token_service );

		return $this->build_event_ingestor(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new class() extends WooPaymentsApiClient {},
			$this->create_dispute_event_handler( new class() extends WooPaymentsApiClient {} ),
			wc_get_container()->get( WooPaymentsRefundEventHandler::class ),
			$account_event_handler,
			$this->create_notification_event_handler()
		);
	}

	/**
	 * Create an account lifecycle event.
	 *
	 * @param string $type       Event type.
	 * @param string $account_id Account ID.
	 * @param bool   $livemode   Whether the event comes from live mode.
	 * @return array<string,mixed>
	 */
	private function create_account_event( string $type, string $account_id = 'acct_123', bool $livemode = false ): array {
		return array(
			'id'       => 'evt_account',
			'type'     => $type,
			'livemode' => $livemode,
			'data'     => array(
				'object' => array(
					'id' => $account_id,
				),
			),
		);
	}
}
