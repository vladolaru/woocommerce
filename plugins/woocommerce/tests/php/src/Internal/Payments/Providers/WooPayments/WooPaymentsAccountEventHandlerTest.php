<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
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
	 * @testdox process() fails closed for an event type outside the supported account lifecycle set.
	 *
	 * Native-only fail-closed guard with no client counterpart: client 11.1.0's webhook
	 * dispatch switch (`includes/class-wc-payments-webhook-processing-service.php:175-243`)
	 * has no `default` case, so an unmatched event type is silently ignored there. The exact
	 * exception message is native's own wording, so it is intentionally not asserted here.
	 */
	public function test_process_throws_for_unsupported_event_type(): void {
		$handler = new WooPaymentsAccountEventHandler();
		$handler->init(
			$this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->getMock(),
			$this->getMockBuilder( WooPaymentsTokenService::class )->disableOriginalConstructor()->getMock()
		);

		$this->expectException( InvalidArgumentException::class );

		$handler->process( 'customer.created', array( 'id' => 'acct_123' ) );
	}

	/**
	 * @testdox account.updated refreshes account data and clears preserved payment method caches.
	 */
	public function test_account_updated_refreshes_account_data_and_clears_payment_method_caches(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'refresh_account_data_strict' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data_strict' )
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
	 * @testdox account.deleted resets account state, refreshes account data, and clears preserved payment method caches.
	 */
	public function test_account_deleted_cleans_state_refreshes_account_data_and_clears_payment_method_caches(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_pending_account_deletion_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_preserved_account_id' )
			->willReturn( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'mark_account_deletion_pending' )
			->with( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data_strict' )
			->willReturn( array() );
		$account_service->expects( $this->once() )
			->method( 'clear_pending_account_deletion' );

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
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_pending_account_deletion_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_preserved_account_id' )
			->willReturn( 'acct_current' );
		$account_service->expects( $this->never() )
			->method( 'mark_account_deletion_pending' );
		$account_service->expects( $this->never() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->never() )
			->method( 'refresh_account_data_strict' );
		$account_service->expects( $this->never() )
			->method( 'clear_pending_account_deletion' );

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
	 * @testdox account.deleted continues pending cleanup retries after the local account cache was cleared.
	 */
	public function test_account_deleted_continues_pending_cleanup_retry(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion' ) )
			->getMock();
			$account_service->expects( $this->once() )
				->method( 'get_pending_account_deletion_id' )
				->willReturn( 'acct_123' );
			$account_service->expects( $this->once() )
				->method( 'get_preserved_account_id' )
				->willReturn( '' );
			$account_service->expects( $this->once() )
				->method( 'mark_account_deletion_pending' )
				->with( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data_strict' )
			->willReturn( array() );
		$account_service->expects( $this->once() )
			->method( 'clear_pending_account_deletion' );

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
	 * @testdox account.deleted ignores stale pending markers when a different account is connected.
	 */
	public function test_account_deleted_ignores_stale_pending_marker_for_different_account(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_pending_account_deletion_id' )
			->willReturn( 'acct_deleted' );
		$account_service->expects( $this->once() )
			->method( 'get_preserved_account_id' )
			->willReturn( 'acct_current' );
		$account_service->expects( $this->never() )
			->method( 'mark_account_deletion_pending' );
		$account_service->expects( $this->never() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->never() )
			->method( 'refresh_account_data_strict' );
		$account_service->expects( $this->never() )
			->method( 'clear_pending_account_deletion' );

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
	 * @testdox account.deleted keeps the pending marker when strict refresh fails after cleanup.
	 */
	public function test_account_deleted_keeps_pending_marker_when_refresh_fails(): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_pending_account_deletion_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_preserved_account_id' )
			->willReturn( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'mark_account_deletion_pending' )
			->with( 'acct_123' );
		$account_service->expects( $this->once() )
			->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->once() )
			->method( 'refresh_account_data_strict' )
			->willThrowException( new RuntimeException( 'Temporary refresh failure.' ) );
		$account_service->expects( $this->never() )
			->method( 'clear_pending_account_deletion' );

		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clear_all_cached_payment_methods' ) )
			->getMock();
		$token_service->expects( $this->never() )
			->method( 'clear_all_cached_payment_methods' );

		$sut = $this->create_ingestor_with_account_services( $account_service, $token_service );

		$this->expectException( RuntimeException::class );

		$sut->process( $this->create_account_event( 'account.deleted' ) );
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
			->onlyMethods( array( 'cleanup_after_account_reset', 'refresh_account_data_strict', 'get_preserved_account_id', 'get_pending_account_deletion_id', 'mark_account_deletion_pending', 'clear_pending_account_deletion', 'get_gateway_setting' ) )
			->getMock();
		$account_service->expects( $this->once() )
			->method( 'get_pending_account_deletion_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_preserved_account_id' )
			->willReturn( '' );
		$account_service->expects( $this->once() )
			->method( 'get_gateway_setting' )
			->with( 'enabled', 'no' )
			->willReturn( 'yes' );
		$account_service->expects( $this->never() )->method( 'mark_account_deletion_pending' );
		$account_service->expects( $this->never() )->method( 'cleanup_after_account_reset' );
		$account_service->expects( $this->never() )->method( 'refresh_account_data_strict' );
		$account_service->expects( $this->never() )->method( 'clear_pending_account_deletion' );

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
	 * Create an ingestor with mocked account event collaborators.
	 *
	 * @param WooPaymentsAccountService $account_service Account service mock.
	 * @param WooPaymentsTokenService   $token_service   Token service mock.
	 * @return WooPaymentsEventIngestor
	 */
	private function create_ingestor_with_account_services( WooPaymentsAccountService $account_service, WooPaymentsTokenService $token_service ): WooPaymentsEventIngestor {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$runtime = new WooPaymentsLegacyRuntime();
		$runtime->init( new LegacyRuntimeProxy( true ) );

		$account_event_handler = new WooPaymentsAccountEventHandler();
		$account_event_handler->init( $account_service, $token_service );

		$sut = new WooPaymentsEventIngestor();
		$sut->init(
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new LegacyProxy(),
			$runtime,
			new class() extends WooPaymentsApiClient {},
			$this->create_dispute_event_handler( $runtime, new class() extends WooPaymentsApiClient {} ),
			wc_get_container()->get( WooPaymentsRefundEventHandler::class ),
			$account_event_handler,
			$this->create_notification_event_handler()
		);

		return $sut;
	}

	/**
	 * Create an account lifecycle event.
	 *
	 * @param string $type       Event type.
	 * @param string $account_id Account ID.
	 * @return array<string,mixed>
	 */
	private function create_account_event( string $type, string $account_id = 'acct_123' ): array {
		return array(
			'id'       => 'evt_account',
			'type'     => $type,
			'livemode' => false,
			'data'     => array(
				'object' => array(
					'id' => $account_id,
				),
			),
		);
	}
}
