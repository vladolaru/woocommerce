<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountMilestonesController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
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
		remove_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
		remove_filter( 'pre_wp_mail', '__return_true' );
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
		unset( $_GET['wcpay_referrer'], $_GET['wcpay_referrer_stage'], $_GET['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		parent::tearDown();
	}

	/**
	 * @testdox Account milestone hooks are registered when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_account_milestone_hooks_when_builtin_owns_runtime(): void {
		$service = $this->create_service();

		$service->register();

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_record_kyc_completion_date' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) ) );
		$this->assertSame( 10, has_action( 'add_option_wcpay_kyc_completion_date', array( $service, 'handle_add_option_wcpay_kyc_completion_date' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $service, 'handle_wcpay_post_kyc_activation_email_cta' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) ) );
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
	 * @testdox Referrer CTA handler redirects only for an allowlisted stage and a manager, without requiring a nonce: $_dataName.
	 * @dataProvider provider_referrer_cta_clicks
	 *
	 * @param string $role            Role of the current user.
	 * @param string $stage           The referrer stage in the CTA link.
	 * @param bool   $expect_redirect Whether the handler records the click and redirects.
	 */
	public function test_referrer_cta_redirects_only_for_a_recorded_click( string $role, string $stage, bool $expect_redirect ): void {
		$service = $this->create_service();
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		// The email CTA link carries no nonce; the handler must still record the click.
		$_GET['wcpay_referrer']       = 'post_kyc_email';
		$_GET['wcpay_referrer_stage'] = $stage;

		$redirected = false;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirected ) {
				$redirected = true;
				// Stand in for the handler's exit so the test run is not terminated.
				throw new \Exception( esc_html( (string) $location ) );
			}
		);

		try {
			$service->handle_wcpay_post_kyc_activation_email_cta();
		} catch ( \Exception $e ) {
			unset( $e );
		}

		$this->assertSame( $expect_redirect, $redirected, $expect_redirect ? 'An allowlisted stage should record the event and redirect without a nonce.' : 'The handler must not record analytics or trigger a redirect.' );
	}

	/**
	 * CTA clicks the handler records, and those it must not record.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public static function provider_referrer_cta_clicks(): array {
		return array(
			'allowlisted stage'                 => array( 'administrator', '7', true ),
			// Stage 3 is not in POST_KYC_STAGE_DAYS.
			'a stage that is not allowlisted'   => array( 'administrator', '3', false ),
			'a user without manage_woocommerce' => array( 'customer', '7', false ),
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
	 * @testdox Post-KYC completion schedules activation emails under the hyphenated woocommerce-payments group.
	 *
	 * Client 11.1.0 class-wc-payments-post-kyc-activation-email-service.php:113 schedules each stage
	 * under the literal 'woocommerce-payments', not the scheduler service's 'woocommerce_payments'.
	 */
	public function test_post_kyc_completion_schedules_emails_under_the_hyphenated_group(): void {
		$service = $this->create_service( new WooPaymentsActionSchedulerService() );

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		foreach ( array( 7, 14, 30 ) as $stage ) {
			$this->assertIsInt( as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( $stage ), 'woocommerce-payments' ), "Stage {$stage} must be scheduled under woocommerce-payments." );
		}
		$this->assertFalse( as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', null, WooPaymentsActionSchedulerService::GROUP_ID ) );
	}

	/**
	 * @testdox Dismissing a post-KYC notice leaves email eligibility, sent stages, and scheduled delivery unchanged.
	 */
	public function test_post_kyc_notice_dismissal_does_not_change_email_delivery(): void {
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$scheduled_at = time() + DAY_IN_SECONDS;
		wp_set_current_user( $user_id );
		set_transient( 'wcpay_post_kyc_activation_eligible', '1', HOUR_IN_SECONDS );
		as_schedule_single_action(
			$scheduled_at,
			'wcpay_post_kyc_activation_email_send',
			array( 7 ),
			'woocommerce-payments',
			true
		);
		$scheduled_before = as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( 7 ), 'woocommerce-payments' );
		$notice_service   = new NoticeService( static fn(): int => 1700000000 );
		$notice_service->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter::class ) );

		$this->assertTrue( $notice_service->record_action( 'post_kyc_activation', 'dismiss', 7 ) );

		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );
		$this->assertFalse( get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
		$this->assertSame( $scheduled_before, as_next_scheduled_action( 'wcpay_post_kyc_activation_email_send', array( 7 ), 'woocommerce-payments' ) );
		$this->assertSame( $scheduled_at, $scheduled_before );
	}

	/**
	 * @testdox Post-KYC completion schedules no activation emails when another request already claimed the gate: $_dataName.
	 * @dataProvider provider_post_kyc_gate_already_claimed
	 *
	 * @param callable $claim_gate Writes the gate marker as the concurrent request left it.
	 */
	public function test_post_kyc_completion_does_not_schedule_when_the_gate_is_claimed( callable $claim_gate ): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$claim_gate();

		$service->handle_add_option_wcpay_kyc_completion_date( 'wcpay_kyc_completion_date', time() );

		$this->assertSame( array(), $scheduler->scheduled_jobs, 'No activation email stages should be scheduled once the gate row exists.' );
	}

	/**
	 * Gate markers a concurrent request left behind.
	 *
	 * @return array<string,array{callable}>
	 */
	public static function provider_post_kyc_gate_already_claimed(): array {
		return array(
			'marker in the option cache' => array(
				static function (): void {
					update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );
				},
			),
			// The cache still records the option as missing, as it would for a read just before the other insert committed.
			// add_option() then skips its existence check and runs INSERT ... ON DUPLICATE KEY UPDATE, which affects no row
			// for the same value and returns false.
			'marker missed by the cache' => array(
				static function (): void {
					update_option( 'wcpay_post_kyc_activation_emails_scheduled', '1', false );
					wp_cache_delete( 'wcpay_post_kyc_activation_emails_scheduled', 'options' );
					$notoptions = wp_cache_get( 'notoptions', 'options' );
					$notoptions = is_array( $notoptions ) ? $notoptions : array();
					$notoptions['wcpay_post_kyc_activation_emails_scheduled'] = true;
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				},
			),
		);
	}

	/**
	 * @testdox Account refresh records post-KYC completion once and schedules staged emails.
	 */
	public function test_account_refresh_records_post_kyc_completion_and_schedules_staged_emails(): void {
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
				// The fields client 11.1.0 maybe_record_kyc_completion_date() checks (includes/class-wc-payments-account.php:2683-2710).
				'payments_enabled' => true,
				'is_live'          => true,
				'is_test_drive'    => false,
				'created'          => time() - MONTH_IN_SECONDS,
			)
		);

		$post_kyc_jobs = array_values(
			array_filter(
				$scheduler->scheduled_jobs,
				static fn( array $job ): bool => 'wcpay_post_kyc_activation_email_send' === $job['hook']
			)
		);

		$this->assertSame( '1', get_option( 'wcpay_post_kyc_activation_emails_scheduled' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'wcpay_kyc_completion_date', 0 ) );
		$this->assertSame(
			array( array( 7 ), array( 14 ), array( 30 ) ),
			array_column( $post_kyc_jobs, 'args' )
		);
	}

	/**
	 * @testdox Account refresh does not overwrite an existing post-KYC completion date.
	 */
	public function test_account_refresh_does_not_overwrite_existing_post_kyc_completion_date(): void {
		$scheduler         = new RecordingActionSchedulerService();
		$service           = $this->create_service( $scheduler );
		$existing_kyc_date = time() - YEAR_IN_SECONDS;

		update_option( 'wcpay_kyc_completion_date', $existing_kyc_date, false );
		$service->maybe_record_kyc_completion_date(
			array(
				// The fields client 11.1.0 maybe_record_kyc_completion_date() checks (includes/class-wc-payments-account.php:2683-2710).
				'payments_enabled' => true,
				'is_live'          => true,
				'is_test_drive'    => false,
				'created'          => time() - MONTH_IN_SECONDS,
			)
		);

		$this->assertSame( $existing_kyc_date, (int) get_option( 'wcpay_kyc_completion_date', 0 ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox Post-KYC activation email registration preserves the WooPayments email settings key.
	 */
	public function test_post_kyc_activation_email_registration_preserves_settings_key(): void {
		$service = $this->create_service();
		$emails  = $service->add_post_kyc_activation_email( array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_Post_Kyc_Activation', $emails );
		$this->assertSame( 'wcpay_post_kyc_activation', $emails['WC_Payments_Email_Post_Kyc_Activation']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_wcpay_post_kyc_activation_settings', $emails['WC_Payments_Email_Post_Kyc_Activation']->get_option_key() );
		$this->assertSame( 'emails/post-kyc-activation.php', $emails['WC_Payments_Email_Post_Kyc_Activation']->template_html );
		$this->assertSame( 'emails/plain/post-kyc-activation.php', $emails['WC_Payments_Email_Post_Kyc_Activation']->template_plain );
	}

	/**
	 * @testdox IPP receipt email registration preserves the WooPayments email settings key and template paths.
	 */
	public function test_ipp_receipt_email_registration_preserves_settings_key_and_template_paths(): void {
		$service = $this->create_service();
		$emails  = $service->add_ipp_receipt_email( array() );

		$this->assertArrayHasKey( 'WC_Payments_Email_IPP_Receipt', $emails );
		$this->assertInstanceOf( WooPaymentsIppReceiptEmail::class, $emails['WC_Payments_Email_IPP_Receipt'] );
		$this->assertSame( 'new_receipt', $emails['WC_Payments_Email_IPP_Receipt']->id );
		$this->assertSame( 'woocommerce_woocommerce_payments_new_receipt_settings', $emails['WC_Payments_Email_IPP_Receipt']->get_option_key() );
		$this->assertSame( 'emails/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_html );
		$this->assertSame( 'emails/plain/customer-ipp-receipt.php', $emails['WC_Payments_Email_IPP_Receipt']->template_plain );
	}

	/**
	 * @testdox Post-KYC activation email jobs record the stage only after a delivery: $_dataName.
	 * @dataProvider provider_post_kyc_email_job_stage_recording
	 *
	 * @param callable             $send_mail            pre_wp_mail filter that decides the delivery.
	 * @param callable             $arrange_store        Sets up the store state the row needs.
	 * @param array<int,int>|false $expected_sent_stages The sent stages option after the job.
	 * @param string|false         $expected_live_sale   The live-sale marker option after the job.
	 */
	public function test_post_kyc_activation_email_job_records_the_stage_only_after_delivery( callable $send_mail, callable $arrange_store, $expected_sent_stages, $expected_live_sale ): void {
		update_option( 'wcpay_kyc_completion_date', time() - 8 * DAY_IN_SECONDS, false );
		$arrange_store();

		// Account flags per client 11.1.0 `includes/class-wc-payments-account.php:367-377`; card_payments in the
		// capabilities map, as the client's account validity check reads it (`:290-292`).
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

		add_filter( 'pre_wp_mail', $send_mail );
		try {
			$service->handle_wcpay_post_kyc_activation_email_send( 7 );
		} finally {
			remove_filter( 'pre_wp_mail', $send_mail );
		}

		$this->assertSame( $expected_sent_stages, get_option( 'wcpay_post_kyc_activation_email_sent_stages' ) );
		$this->assertSame( $expected_live_sale, get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Delivery outcomes and store states, with the sent stages and live-sale marker each should leave.
	 *
	 * @return array<string,array{callable,callable,array<int,int>|false,string|false}>
	 */
	public static function provider_post_kyc_email_job_stage_recording(): array {
		$nothing = static function (): void {};

		return array(
			'delivered'                         => array( '__return_true', $nothing, array( 7 ), false ),
			// A later job can retry the stage.
			'delivery failed'                   => array( '__return_false', $nothing, false, false ),
			'the email registry omits it'       => array(
				'__return_true',
				static function (): void {
					add_filter( 'woocommerce_email_classes', '__return_empty_array', 20 );
				},
				false,
				false,
			),
			// The order exists before the service registers, so only the job's own live-sale check can find it.
			'the store already has a live sale' => array(
				'__return_true',
				static function (): void {
					$order = wc_create_order();
					$order->set_payment_method( 'woocommerce_payments' );
					$order->set_status( 'completed' );
					$order->update_meta_data( '_wcpay_mode', 'prod' );
					$order->save();
				},
				false,
				'1',
			),
		);
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
		remove_action( 'add_option_wcpay_kyc_completion_date', array( $service, 'handle_add_option_wcpay_kyc_completion_date' ) );
		remove_action( 'wcpay_post_kyc_activation_email_send', array( $service, 'handle_wcpay_post_kyc_activation_email_send' ) );
		remove_action( 'admin_init', array( $service, 'handle_wcpay_post_kyc_activation_email_cta' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'handle_wcpay_instant_deposits_inbox_note' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'maybe_record_kyc_completion_date' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $service, 'maybe_handle_test_mode_toggle' ) );
		remove_action( 'woocommerce_order_status_changed', array( $service, 'handle_woocommerce_order_status_changed' ) );
		remove_filter( 'woocommerce_email_classes', array( $service, 'add_post_kyc_activation_email' ) );
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
