<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountMilestonesController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use ActionScheduler;
use WC_Data_Store;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAccountMilestonesController class.
 */
class WooPaymentsAccountMilestonesControllerTest extends WC_Unit_Test_Case {

	/**
	 * Created services whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsAccountMilestonesController[]
	 */
	private array $services = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->services as $service ) {
			$this->remove_account_milestone_hooks( $service );
		}

		remove_all_filters( 'wcpay_test_mode' );
		delete_option( 'wcpay_instant_deposits_previously_eligible' );
		delete_option( 'wcpay_post_kyc_activation_email_sent_stages' );
		delete_option( 'wcpay_post_kyc_activation_emails_scheduled' );
		delete_option( 'wcpay_kyc_completion_date' );
		delete_option( 'wcpay_kyc_submitted_date' );
		delete_option( 'wcpay_has_live_sale' );
		delete_option( 'wcpay_test_mode_enabled_date' );
		delete_transient( 'wcpay_test_to_live_eligible' );
		delete_transient( 'wcpay_post_kyc_activation_eligible' );
		delete_transient( 'wcpay_one_and_done_eligible' );
		$this->delete_instant_deposit_note();
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_store_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_compliance_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_notification' );
		remove_all_filters( 'woocommerce_email_preview_dummy_order' );
		remove_all_filters( 'woocommerce_email_preview_dummy_address' );
		remove_all_filters( 'woocommerce_email_preview_placeholders' );
		$this->reset_mailer_emails();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wcpay_post_kyc_activation_email_send', null, WooPaymentsActionSchedulerService::GROUP_ID );
			as_unschedule_all_actions( 'wcpay_post_kyc_activation_email_send', null, 'woocommerce-payments' );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Account milestone hooks are registered when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_account_milestone_hooks_when_builtin_owns_runtime(): void {
		$service = $this->create_service();

		$service->register();

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) ) );
	}

	/**
	 * @testdox A live WooPayments paid-status transition records the sale and invalidates cached post-KYC eligibility.
	 * @testWith ["woocommerce_payments"]
	 *           ["woocommerce_payments_ideal"]
	 *
	 * @param string $payment_method WooPayments gateway ID stored on the order.
	 */
	public function test_live_woopayments_order_status_transition_invalidates_post_kyc_eligibility( string $payment_method ): void {
		$service = $this->create_service();
		$service->register();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		// Plugin 11.1.0 `maybe_record_first_live_sale()` matches `Order_Mode::PRODUCTION` (class-wc-payments-order-service.php:305).
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );

		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );
	}

	/**
	 * @testdox A paid transition after the live-sale marker exists clears the cached one-and-done result unless it is a test-mode card order: $_dataName.
	 * @dataProvider provide_one_and_done_cache_transitions
	 *
	 * @param string $payment_method Payment gateway ID.
	 * @param string $mode           WooPayments order mode.
	 * @param string $cached_value   Cached one-and-done eligibility.
	 * @param string $new_status     Paid status the order moves to.
	 * @param bool   $expect_cleared Whether the cached result is cleared.
	 */
	public function test_paid_order_status_transition_clears_one_and_done_cache_unless_test_card_order( string $payment_method, string $mode, string $cached_value, string $new_status, bool $expect_cleared ): void {
		$service = $this->create_service();
		$order   = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		if ( '' !== $mode ) {
			$order->update_meta_data( '_wcpay_mode', $mode );
		}
		$order->save();
		update_option( 'wcpay_has_live_sale', '1', false );
		set_transient( 'wcpay_one_and_done_eligible', $cached_value, HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order->get_id(), OrderStatus::PENDING, $new_status, $order );

		$this->assertSame( $expect_cleared ? false : $cached_value, get_transient( 'wcpay_one_and_done_eligible' ) );
		$this->assertSame( '1', get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Paid transitions with both cached results.
	 *
	 * @return array<string,array{string,string,string,string,bool}>
	 */
	public static function provide_one_and_done_cache_transitions(): array {
		return array(
			'second live WooPayments order with positive cache' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', '1', OrderStatus::PROCESSING, true ),
			'alternate gateway order with negative cache' => array( 'cod', '', '0', OrderStatus::COMPLETED, true ),
			// Client 11.1.0 keeps the cache only for a test-mode card gateway order (admin/attach-rate/class-wc-payments-one-and-done-notice.php:103-106).
			'test-mode iDEAL order with positive cache'   => array( 'woocommerce_payments_ideal', 'test', '1', OrderStatus::PROCESSING, true ),
			'test-mode card order keeps the cache'        => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'test', '1', OrderStatus::PROCESSING, false ),
		);
	}

	/**
	 * @testdox Ineligible order-status transitions leave the live-sale marker and post-KYC eligibility unchanged.
	 * @dataProvider provide_ineligible_order_status_transitions
	 *
	 * @param string $payment_method Payment method stored on the order.
	 * @param string $mode           WooPayments mode stored on the order.
	 * @param string $new_status     Target order status supplied by the hook.
	 * @param bool   $valid_order    Whether the hook receives a WC_Order.
	 */
	public function test_ineligible_order_status_transitions_preserve_post_kyc_eligibility( string $payment_method, string $mode, string $new_status, bool $valid_order ): void {
		$service  = $this->create_service();
		$order    = new \stdClass();
		$order_id = 123;
		if ( $valid_order ) {
			$order = wc_create_order();
			$this->assertInstanceOf( WC_Order::class, $order );
			$order->set_payment_method( $payment_method );
			$order->update_meta_data( '_wcpay_mode', $mode );
			$order->save();
			$order_id = $order->get_id();
		}
		delete_option( 'wcpay_has_live_sale' );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );

		$service->handle_woocommerce_order_status_changed( $order_id, OrderStatus::PENDING, $new_status, $order );

		$this->assertFalse( get_option( 'wcpay_has_live_sale' ), 'An ineligible transition must not record a live sale.' );
		$this->assertSame( '1', get_transient( 'wcpay_post_kyc_activation_eligible' ), 'An ineligible transition must not invalidate cached eligibility.' );
	}

	/**
	 * Ineligible order-status hook inputs.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public static function provide_ineligible_order_status_transitions(): array {
		return array(
			'test-mode WooPayments order' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'test', OrderStatus::PROCESSING, true ),
			// The account-mode slug is not an order mode; plugin 11.1.0 matches only `prod`.
			'account-mode live value'     => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'live', OrderStatus::PROCESSING, true ),
			'wrong payment gateway'       => array( 'cod', 'prod', OrderStatus::PROCESSING, true ),
			'unpaid order status'         => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', OrderStatus::PENDING, true ),
			'invalid order object'        => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', OrderStatus::PROCESSING, false ),
		);
	}

	/**
	 * @testdox Should stamp the test-mode enable date and clear notice eligibility on a mode flip.
	 */
	public function test_test_mode_toggle_stamps_and_clears_bookkeeping(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		set_transient( 'wcpay_test_to_live_eligible', '1', 100 );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', 100 );
		$service = $this->create_service();
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$enabled_date = (int) get_option( 'wcpay_test_mode_enabled_date' );
		$this->assertGreaterThan( 0, $enabled_date );
		$this->assertFalse( get_transient( 'wcpay_test_to_live_eligible' ) );
		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );

		$this->assertFalse( get_option( 'wcpay_test_mode_enabled_date' ), 'Disabling test mode must restart the nudge clock.' );
	}

	/**
	 * @testdox Should preserve the original enable date across saves that do not flip the mode.
	 */
	public function test_test_mode_bookkeeping_preserves_enable_date_without_a_flip(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option( 'wcpay_test_mode_enabled_date', 1234567890, false );
		$service = $this->create_service();
		$service->register();

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'test_mode'      => 'yes',
				'enable_logging' => 'yes',
			)
		);

		$this->assertSame( 1234567890, (int) get_option( 'wcpay_test_mode_enabled_date' ), 'A save without a mode flip must not restamp the enable date.' );
	}

	/**
	 * @testdox Instant deposit eligibility refresh creates the inbox note and reminder only for an eligible account: $_dataName.
	 * @dataProvider provide_instant_deposit_eligibility
	 *
	 * @param bool $eligible The account's instant deposit eligibility flag.
	 */
	public function test_instant_deposit_eligibility_refresh_creates_note_and_reminder_only_when_eligible( bool $eligible ): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );

		$service->handle_wcpay_instant_deposits_inbox_note(
			array(
				// Client 11.1.0 reads this flag in is_instant_deposits_eligible() (includes/class-wc-payments-account.php:2842-2847).
				'instant_deposits_eligible' => $eligible,
			)
		);

		$this->assertSame( $eligible, (bool) get_option( 'wcpay_instant_deposits_previously_eligible', false ) );
		$this->assertSame( $eligible, array() !== $this->get_instant_deposit_note_ids() );
		$this->assertCount( $eligible ? 1 : 0, $scheduler->scheduled_jobs );
		if ( $eligible ) {
			$this->assertSame( 'wcpay_instant_deposit_reminder', $scheduler->scheduled_jobs[0]['hook'] );
			$this->assertSame( array(), $scheduler->scheduled_jobs[0]['args'] );
			$this->assertGreaterThanOrEqual( time() + 90 * DAY_IN_SECONDS - 5, $scheduler->scheduled_jobs[0]['timestamp'] ?? 0 );
		}
	}

	/**
	 * Instant deposit eligibility flags in the refreshed account data.
	 *
	 * @return array<string,array{bool}>
	 */
	public static function provide_instant_deposit_eligibility(): array {
		return array(
			'eligible account'   => array( true ),
			'ineligible account' => array( false ),
		);
	}

	/**
	 * @testdox Instant deposit reminder refreshes the note from cached account data.
	 */
	public function test_instant_deposit_reminder_refreshes_note_from_cached_account_data(): void {
		$scheduler       = new RecordingActionSchedulerService();
		$account_service = $this->create_account_service(
			array(
				// Client 11.1.0 reads this flag in is_instant_deposits_eligible() (includes/class-wc-payments-account.php:2842-2847).
				'instant_deposits_eligible' => true,
			),
			false
		);
		$service         = $this->create_service( $scheduler, $account_service );

		$service->handle_wcpay_instant_deposit_reminder();

		$this->assertNotSame( array(), $this->get_instant_deposit_note_ids() );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertSame( 'wcpay_instant_deposit_reminder', $scheduler->scheduled_jobs[0]['hook'] );
	}

	/**
	 * @testdox Recording the KYC completion date schedules no post-KYC reminder email.
	 *
	 * Client 11.3.0 `tests/unit/test-class-retired-kyc-emails.php` `test_recording_kyc_no_longer_schedules_emails`.
	 */
	public function test_recording_kyc_completion_schedules_no_reminder_email(): void {
		$service = $this->create_service( new WooPaymentsActionSchedulerService() );
		$service->register();

		add_option( 'wcpay_kyc_completion_date', time() - DAY_IN_SECONDS );

		foreach ( array( 7, 14, 30 ) as $stage ) {
			$this->assertFalse( as_has_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( $stage ), 'woocommerce-payments' ), "Stage {$stage} must not be scheduled." );
		}
		$this->assertFalse( as_has_scheduled_action( 'wcpay_post_kyc_activation_email_send' ) );
		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_emails_scheduled' ) );
	}

	/**
	 * @testdox A live account refresh records no KYC completion date.
	 */
	public function test_live_account_refresh_records_no_kyc_completion_date(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$service->register();
		update_option( 'wcpay_kyc_submitted_date', time() - HOUR_IN_SECONDS, false );

		/**
		 * Fires after WooPayments account data is refreshed.
		 *
		 * @since 11.0.0
		 */
		do_action(
			'woocommerce_payments_account_refreshed',
			array(
				// The fields client 11.2.0 maybe_record_kyc_completion_date() checked (includes/class-wc-payments-account.php).
				'payments_enabled' => true,
				'is_live'          => true,
				'is_test_drive'    => false,
				'created'          => time() - MONTH_IN_SECONDS,
			)
		);

		$this->assertFalse( get_option( 'wcpay_kyc_completion_date' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox WooCommerce's email list carries the in-person payment receipt under its WooPayments keys and templates, and no post-KYC reminder.
	 */
	public function test_email_list_carries_the_ipp_receipt_and_no_post_kyc_reminder(): void {
		$service = $this->create_service();
		$service->register();

		/** This filter is documented in WooCommerce's WC_Emails::init(). */
		$emails = apply_filters( 'woocommerce_email_classes', array() );

		$this->assertArrayNotHasKey( 'WC_Payments_Email_Post_Kyc_Activation', $emails );
		$this->assertArrayHasKey( 'WC_Payments_Email_IPP_Receipt', $emails );
		$this->assertInstanceOf( WooPaymentsIppReceiptEmail::class, $emails['WC_Payments_Email_IPP_Receipt'] );
		$this->assertSame( 'new_receipt', $emails['WC_Payments_Email_IPP_Receipt']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_new_receipt_settings', $emails['WC_Payments_Email_IPP_Receipt']->get_option_key() );
		$this->assertSame( 'emails/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_html );
		$this->assertSame( 'emails/plain/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_plain );
	}

	/**
	 * @testdox A reminder job the WooPayments extension queued completes without sending on a store the reminder would have suited.
	 *
	 * Client 11.3.0 `tests/unit/test-class-retired-kyc-emails.php` `test_residual_action_completes_without_sending`.
	 */
	public function test_queued_reminder_job_completes_without_sending(): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		// A live account out of test mode with no live sale, which client 11.2.0 sent the 7-day reminder to.
		$account_service = $this->create_account_service(
			array(
				'is_live'           => true,
				'is_test_drive'     => false,
				'payments_enabled'  => true,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments' => 'active',
				),
			),
			false,
			true,
			false
		);
		$service         = $this->create_service( new RecordingActionSchedulerService(), $account_service );
		$service->register();
		$this->reset_mailer_emails();
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);
		$action_id = as_schedule_single_action( time() - 60, 'wcpay_post_kyc_activation_email_send', array( 7 ), 'woocommerce-payments' );

		ActionScheduler::runner()->process_action( $action_id, 'test' );

		$this->assertSame( 'complete', ActionScheduler::store()->get_status( (string) $action_id ) );
		$this->assertSame( 0, $mails );
		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
	}

	/**
	 * Create an account milestones controller.
	 *
	 * @param WooPaymentsActionSchedulerService|null $scheduler       Scheduler service.
	 * @param WooPaymentsAccountService|null         $account_service Account service.
	 * @return WooPaymentsAccountMilestonesController
	 */
	private function create_service(
		?WooPaymentsActionSchedulerService $scheduler = null,
		?WooPaymentsAccountService $account_service = null
	): WooPaymentsAccountMilestonesController {
		$service = new WooPaymentsAccountMilestonesController();
		$service->init(
			$scheduler ?? new RecordingActionSchedulerService(),
			$account_service ?? $this->create_account_service()
		);

		$this->services[] = $service;

		return $service;
	}

	/**
	 * Create an account service mock.
	 *
	 * @param array<string,mixed> $account_data         Account data returned by the mock.
	 * @param bool                $test_mode            Whether test mode is enabled.
	 * @param bool                $can_process_payments Whether the account can process payments.
	 * @param bool                $test_account         Whether the account is a test-drive account.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $account_data = array(), bool $test_mode = true, bool $can_process_payments = true, bool $test_account = false ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_cached_account_data', 'can_process_payments', 'has_test_account', 'is_payment_request_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'can_process_payments' )->willReturn( $can_process_payments );
		$account_service->method( 'has_test_account' )->willReturn( $test_account );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( true );
		// Gateway settings are read through the real get_gateway_setting(), which needs the proxy.
		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( wc_get_container()->get( LegacyProxy::class ) );
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), $database_cache );

		return $account_service;
	}

	/**
	 * Remove the hooks a controller registered.
	 *
	 * @param WooPaymentsAccountMilestonesController $service Controller instance.
	 */
	private function remove_account_milestone_hooks( WooPaymentsAccountMilestonesController $service ): void {
		remove_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) );
		remove_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $service, 'maybe_handle_test_mode_toggle' ) );
		remove_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_ipp_receipt_email' ) );
	}


	/**
	 * Rebuild WooCommerce's cached email registry under the currently registered filters.
	 */
	private function reset_mailer_emails(): void {
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			WC()->mailer()->emails = array();
			WC()->mailer()->init();
		}
	}

	/**
	 * Get preserved instant deposit note IDs.
	 *
	 * @return int[]
	 */
	private function get_instant_deposit_note_ids(): array {
		$data_store = WC_Data_Store::load( 'admin-note' );
		$note_ids   = $data_store->get_notes_with_name( 'wc-payments-notes-instant-deposits-eligible' );

		return array_map( 'absint', $note_ids );
	}

	/**
	 * Delete preserved instant deposit notes.
	 */
	private function delete_instant_deposit_note(): void {
		$data_store = WC_Data_Store::load( 'admin-note' );
		foreach ( $this->get_instant_deposit_note_ids() as $note_id ) {
			$note = \Automattic\WooCommerce\Admin\Notes\Notes::get_note( $note_id );
			if ( $note instanceof \Automattic\WooCommerce\Admin\Notes\Note ) {
				$data_store->delete( $note );
			}
		}
	}
}
