<?php
/**
 * WooPaymentsAccountMilestonesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Follows the WooPayments account through its milestones: KYC completion and the post-KYC activation emails, the
 * Instant Deposits note, the store's first live sale and the date test mode was turned on. It adds the WooPayments
 * emails, the post-KYC activation email and the in-person payment receipt, to WooCommerce's email list.
 *
 * Their hooks, options, transients and notes keep the WooPayments extension's names, so jobs it queued and state it
 * stored carry over after the switch.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAccountMilestonesController implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook of the Instant Deposits eligibility reminder.
	 *
	 * @var string
	 */
	const INSTANT_DEPOSIT_REMINDER_ACTION = 'wcpay_instant_deposit_reminder';

	/**
	 * Action Scheduler hook that sends a post-KYC activation email.
	 *
	 * @var string
	 */
	const POST_KYC_ACTIVATION_EMAIL_SEND_ACTION = 'wcpay_post_kyc_activation_email_send';

	/**
	 * Action Scheduler group of the post-KYC activation email jobs.
	 *
	 * @var string
	 */
	const POST_KYC_ACTIVATION_EMAIL_GROUP = 'woocommerce-payments';

	/**
	 * The post-KYC activation email's key in WooCommerce's email list, the WooPayments extension's class name: the email
	 * settings use it as the section of this email's settings page, and this class uses it to find the email.
	 *
	 * @var string
	 */
	const POST_KYC_ACTIVATION_EMAIL_CLASS_KEY = 'WC_Payments_Email_Post_Kyc_Activation';

	/**
	 * Inbox note name of the Instant Deposits eligibility note.
	 *
	 * @var string
	 */
	private const INSTANT_DEPOSIT_NOTE_NAME = 'wc-payments-notes-instant-deposits-eligible';

	/**
	 * Option recording that the merchant has been eligible for Instant Deposits.
	 *
	 * @var string
	 */
	private const INSTANT_DEPOSITS_PREVIOUSLY_ELIGIBLE_OPTION = 'wcpay_instant_deposits_previously_eligible';

	/**
	 * Option holding the date the account's KYC was seen complete.
	 *
	 * @var string
	 */
	private const KYC_COMPLETION_DATE_OPTION = 'wcpay_kyc_completion_date';

	/**
	 * Option holding the date the merchant submitted KYC.
	 *
	 * @var string
	 */
	public const KYC_SUBMITTED_DATE_OPTION = 'wcpay_kyc_submitted_date';

	/**
	 * Option listing the post-KYC activation email stages already sent.
	 *
	 * @var string
	 */
	private const POST_KYC_EMAIL_SENT_STAGES_OPTION = 'wcpay_post_kyc_activation_email_sent_stages';

	/**
	 * Option marking that the post-KYC activation emails are scheduled.
	 *
	 * @var string
	 */
	private const POST_KYC_EMAILS_SCHEDULED_OPTION = 'wcpay_post_kyc_activation_emails_scheduled';

	/**
	 * Option marking the store's first live WooPayments sale.
	 *
	 * @var string
	 */
	public const HAS_LIVE_SALE_OPTION = 'wcpay_has_live_sale';

	/**
	 * Option holding the time test mode was turned on.
	 *
	 * @var string
	 */
	private const TEST_MODE_ENABLED_DATE_OPTION = 'wcpay_test_mode_enabled_date';

	/**
	 * Transient caching whether the test-to-live notice applies.
	 *
	 * @var string
	 */
	private const TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT = 'wcpay_test_to_live_eligible';

	/**
	 * Transient caching whether a post-KYC activation email applies.
	 *
	 * @var string
	 */
	private const POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT = 'wcpay_post_kyc_activation_eligible';

	/**
	 * Transient caching the one-and-done notice eligibility.
	 *
	 * @var string
	 */
	private const ONE_AND_DONE_ELIGIBLE_TRANSIENT = 'wcpay_one_and_done_eligible';

	/**
	 * Post-KYC activation email stages in days after KYC completion.
	 *
	 * @var int[]
	 */
	private const POST_KYC_STAGE_DAYS = array( 7, 14, 30 );

	/**
	 * Grace window for stale post-KYC activation email actions.
	 *
	 * @var int
	 */
	private const POST_KYC_STALE_GRACE_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * Instant Deposit reminder cadence.
	 *
	 * @var int
	 */
	private const INSTANT_DEPOSIT_REMINDER_DELAY = 90 * DAY_IN_SECONDS;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsActionSchedulerService $scheduler       Action Scheduler service.
	 * @param WooPaymentsAccountService         $account_service WooPayments account service.
	 */
	final public function init(
		WooPaymentsActionSchedulerService $scheduler,
		WooPaymentsAccountService $account_service
	): void {
		$this->scheduler       = $scheduler;
		$this->account_service = $account_service;
	}

	/**
	 * Register the account milestone hooks.
	 */
	public function register() {
		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'handle_wcpay_instant_deposits_inbox_note' ) );
		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'maybe_record_kyc_completion_date' ) );
		add_action( self::INSTANT_DEPOSIT_REMINDER_ACTION, array( $this, 'handle_wcpay_instant_deposit_reminder' ), 10, 0 );
		add_action( 'add_option_' . self::KYC_COMPLETION_DATE_OPTION, array( $this, 'handle_add_option_wcpay_kyc_completion_date' ), 10, 2 );
		add_action( self::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION, array( $this, 'handle_wcpay_post_kyc_activation_email_send' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'handle_wcpay_post_kyc_activation_email_cta' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_post_kyc_activation_email' ), 10, 1 );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_ipp_receipt_email' ), 10, 1 );
		add_action( 'update_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( $this, 'maybe_handle_test_mode_toggle' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_woocommerce_order_status_changed' ), 10, 4 );
	}

	/**
	 * Record a qualifying live WooPayments sale when an order reaches a paid status.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 *
	 * @param mixed $order_id   Order ID supplied by the hook.
	 * @param mixed $old_status Previous order status supplied by the hook.
	 * @param mixed $new_status New order status supplied by the hook.
	 * @param mixed $order      Order supplied by the hook.
	 */
	public function handle_woocommerce_order_status_changed( $order_id, $old_status, $new_status, $order ): void {
		unset( $order_id, $old_status );

		if ( ! $order instanceof WC_Order || ! in_array( $new_status, array( OrderStatus::PROCESSING, OrderStatus::COMPLETED ), true ) ) {
			return;
		}

		$has_cached_one_and_done_eligibility = false !== get_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
		$has_live_sale                       = (bool) get_option( self::HAS_LIVE_SALE_OPTION );
		if ( ! $has_cached_one_and_done_eligibility && $has_live_sale ) {
			return;
		}

		$payment_method = $order->get_payment_method();
		$mode           = WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $payment_method ) ? $order->get_meta( '_wcpay_mode', true ) : null;
		if ( $has_cached_one_and_done_eligibility && ! ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $payment_method && WooPaymentsOrderMode::TEST === $mode ) ) {
			delete_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
		}

		if ( $has_live_sale || WooPaymentsOrderMode::PRODUCTION !== $mode ) {
			return;
		}

		update_option( self::HAS_LIVE_SALE_OPTION, '1', true );
		delete_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
		// Client 11.1.0 records the same event (class-wc-payments-order-service.php:295-309).
		WooPaymentsTracks::record_wcadmin_event( 'wcpay_first_live_sale' );
	}

	/**
	 * Track test-mode flips for the go-live nudge, as client 11.1.0's onboarding service does
	 * (class-wc-payments-onboarding-service.php:1106, :1673-1681).
	 *
	 * Enable stamps the enable date so the nudge clock starts; disable clears it so re-entering test mode restarts the clock; both drop the notice-eligibility transients.
	 *
	 * @param mixed $old_value Previous settings option value.
	 * @param mixed $new_value New settings option value.
	 */
	public function maybe_handle_test_mode_toggle( $old_value, $new_value ): void {
		$old_test_mode = is_array( $old_value ) ? ( $old_value['test_mode'] ?? 'no' ) : 'no';
		$new_test_mode = is_array( $new_value ) ? ( $new_value['test_mode'] ?? 'no' ) : 'no';

		if ( $old_test_mode === $new_test_mode ) {
			return;
		}

		if ( 'yes' === $new_test_mode ) {
			if ( ! get_option( self::TEST_MODE_ENABLED_DATE_OPTION ) ) {
				update_option( self::TEST_MODE_ENABLED_DATE_OPTION, time(), false );
			}
		} else {
			delete_option( self::TEST_MODE_ENABLED_DATE_OPTION );
		}

		delete_transient( self::TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT );
		delete_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
	}

	/**
	 * Refresh the Instant Deposit eligibility inbox note from account data.
	 *
	 * @internal
	 *
	 * @param mixed $account Account data from the WooPayments account cache refresh hook.
	 */
	public function handle_wcpay_instant_deposits_inbox_note( $account ): void {
		if ( ! is_array( $account ) || empty( $account ) ) {
			return;
		}

		if ( empty( $account['instant_deposits_eligible'] ) ) {
			return;
		}

		update_option( self::INSTANT_DEPOSITS_PREVIOUSLY_ELIGIBLE_OPTION, true );

		try {
			$this->add_instant_deposit_note();
			$this->schedule_instant_deposit_note_reminder();
		} catch ( Throwable $exception ) {
			$this->log_exception( 'Failed to refresh WooPayments Instant Deposit eligibility note.', $exception );
		}
	}

	/**
	 * Handle the Instant Deposits eligibility reminder action.
	 *
	 * @internal
	 */
	public function handle_wcpay_instant_deposit_reminder(): void {
		try {
			$this->delete_instant_deposit_note();
		} catch ( Throwable $exception ) {
			$this->log_exception(
				'Failed to delete WooPayments Instant Deposit eligibility note.',
				$exception,
				array( 'action' => self::INSTANT_DEPOSIT_REMINDER_ACTION )
			);
		}

		$this->handle_wcpay_instant_deposits_inbox_note( $this->account_service->get_cached_account_data() );
	}

	/**
	 * Record the first live KYC completion observation from account refresh data.
	 *
	 * @internal
	 *
	 * @param mixed $account Account data from the WooPayments account cache refresh hook.
	 */
	public function maybe_record_kyc_completion_date( $account ): void {
		if ( ! is_array( $account ) || empty( $account ) ) {
			return;
		}

		if ( empty( $account['payments_enabled'] ) || empty( $account['is_live'] ) || ! empty( $account['is_test_drive'] ) ) {
			return;
		}

		if ( get_option( self::KYC_COMPLETION_DATE_OPTION ) ) {
			return;
		}

		$kyc_submitted_date = (int) get_option( self::KYC_SUBMITTED_DATE_OPTION, 0 );
		$account_created    = (int) ( $account['created'] ?? 0 );

		if ( $kyc_submitted_date ) {
			$completion_date = time();
		} elseif ( $account_created ) {
			$completion_date = $account_created;
		} else {
			return;
		}

		update_option( self::KYC_COMPLETION_DATE_OPTION, $completion_date, false );
	}

	/**
	 * Schedule the post-KYC activation email stages when KYC completion is recorded.
	 *
	 * @internal
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 */
	public function handle_add_option_wcpay_kyc_completion_date( $option_name, $value ): void {
		if ( self::KYC_COMPLETION_DATE_OPTION !== $option_name ) {
			return;
		}

		$kyc_date = (int) $value;
		if ( ! $kyc_date ) {
			return;
		}

		// Atomically claim the scheduling. add_option() performs an INSERT that returns false when the row already
		// exists, so only the first of two concurrent account-refresh handlers proceeds to schedule the email stages.
		// This avoids the time-of-check/time-of-use race a get_option()/update_option() pair would leave open and the
		// duplicate merchant emails it could send.
		if ( ! add_option( self::POST_KYC_EMAILS_SCHEDULED_OPTION, '1', '', false ) ) {
			return;
		}

		$now = time();
		foreach ( self::POST_KYC_STAGE_DAYS as $stage ) {
			$send_at = $kyc_date + $stage * DAY_IN_SECONDS;
			if ( $send_at < $now - self::POST_KYC_STALE_GRACE_SECONDS ) {
				continue;
			}

			$this->scheduler->schedule_job(
				self::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
				array( $stage ),
				max( $send_at, $now + MINUTE_IN_SECONDS ),
				self::POST_KYC_ACTIVATION_EMAIL_GROUP
			);
		}
	}

	/**
	 * Handle the staged post-KYC activation email action.
	 *
	 * @internal
	 *
	 * @param mixed $stage Stage day.
	 */
	public function handle_wcpay_post_kyc_activation_email_send( $stage ): void {
		$stage = (int) $stage;
		if ( ! in_array( $stage, self::POST_KYC_STAGE_DAYS, true ) ) {
			return;
		}

		$sent_stages = $this->get_post_kyc_sent_stages();
		if ( in_array( $stage, $sent_stages, true ) ) {
			return;
		}

		$kyc_date = (int) get_option( self::KYC_COMPLETION_DATE_OPTION, 0 );
		if ( ! $kyc_date ) {
			return;
		}

		if ( time() > $kyc_date + $stage * DAY_IN_SECONDS + self::POST_KYC_STALE_GRACE_SECONDS ) {
			return;
		}

		if ( ! $this->is_post_kyc_activation_email_eligible() ) {
			return;
		}

		$email = $this->get_post_kyc_activation_email();
		if ( ! $email || ! $email->is_enabled() || ! $email->get_recipient() ) {
			return;
		}

		if ( ! $email->trigger( $stage ) ) {
			return;
		}

		$sent_stages[] = $stage;
		update_option( self::POST_KYC_EMAIL_SENT_STAGES_OPTION, array_values( array_unique( $sent_stages ) ), false );
	}

	/**
	 * Register the post-KYC activation email class.
	 *
	 * @internal
	 *
	 * @param mixed $email_classes WooCommerce email classes.
	 * @return mixed
	 */
	public function add_post_kyc_activation_email( $email_classes ) {
		if ( ! is_array( $email_classes ) ) {
			return $email_classes;
		}

		$email_classes[ self::POST_KYC_ACTIVATION_EMAIL_CLASS_KEY ] = new WooPaymentsPostKycActivationEmail();

		return $email_classes;
	}

	/**
	 * Register the IPP receipt email class.
	 *
	 * @internal
	 *
	 * @param mixed $email_classes WooCommerce email classes.
	 * @return mixed
	 */
	public function add_ipp_receipt_email( $email_classes ) {
		if ( ! is_array( $email_classes ) ) {
			return $email_classes;
		}

		$email = new WooPaymentsIppReceiptEmail();
		$email->init_hooks();

		$email_classes[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] = $email;

		return $email_classes;
	}

	/**
	 * Track clicks from the post-KYC activation email CTA.
	 *
	 * @internal
	 */
	public function handle_wcpay_post_kyc_activation_email_cta(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcpay_referrer'] ) || 'post_kyc_email' !== $_GET['wcpay_referrer'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Note: this handler intentionally does not verify a nonce. It fires from a post-KYC activation email CTA that
		// merchants may open days after it is sent, so a short-lived nonce would be expired for most legitimate clicks.
		// The CSRF exposure is limited and acceptable: the handler checks the `manage_woocommerce` capability, only
		// records a single telemetry event whose `stage` must be in the POST_KYC_STAGE_DAYS allowlist, and performs a
		// safe same-page redirect via wp_safe_redirect( remove_query_arg( ... ) ). The worst a forged request can do is
		// record one allowlisted-stage analytics event.
		$stage = isset( $_GET['wcpay_referrer_stage'] ) ? (int) $_GET['wcpay_referrer_stage'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $stage, self::POST_KYC_STAGE_DAYS, true ) ) {
			return;
		}

		if ( class_exists( '\WC_Tracks' ) ) {
			WooPaymentsTracks::record_wcadmin_event( 'wcpay_post_kyc_activation_email_cta_clicked', array( 'stage' => $stage ) );
		}

		wp_safe_redirect( remove_query_arg( array( 'wcpay_referrer', 'wcpay_referrer_stage' ) ) );
		exit;
	}

	/**
	 * Add the Instant Deposits eligibility note when it does not already exist.
	 */
	private function add_instant_deposit_note(): void {
		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		if ( ! empty( $data_store->get_notes_with_name( self::INSTANT_DEPOSIT_NOTE_NAME ) ) ) {
			return;
		}

		$note = new Note();
		$note->set_title(
			sprintf(
				/* translators: %s: WooPayments. */
				__( "You're now eligible to receive Instant Payouts with %s", 'woocommerce' ),
				'WooPayments'
			)
		);
		$note->set_content(
			sprintf(
				/* translators: 1: WooPayments, 2: Instant Payouts documentation URL. */
				__( 'Get immediate access to your funds when you need them - including nights, weekends, and holidays. With %1$s\' <a href="%2$s">Instant Payouts feature</a>, you\'re able to transfer your earnings to a debit card within minutes.', 'woocommerce' ),
				'WooPayments',
				esc_url( 'https://woocommerce.com/document/woopayments/payouts/instant-payouts/' )
			)
		);
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::INSTANT_DEPOSIT_NOTE_NAME );
		$note->set_source( 'woocommerce-payments' );
		$note->add_action(
			self::INSTANT_DEPOSIT_NOTE_NAME,
			__( 'Request an instant payout', 'woocommerce' ),
			'https://woocommerce.com/document/woopayments/payouts/instant-payouts/#request-an-instant-payout',
			'unactioned',
			true
		);
		$note->save();
	}

	/**
	 * Delete the Instant Deposits eligibility note.
	 */
	private function delete_instant_deposit_note(): void {
		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		$note_ids   = $data_store->get_notes_with_name( self::INSTANT_DEPOSIT_NOTE_NAME );

		foreach ( $note_ids as $note_id ) {
			$note = Notes::get_note( (int) $note_id );
			if ( $note instanceof Note ) {
				$data_store->delete( $note );
			}
		}
	}

	/**
	 * Schedule the next Instant Deposits eligibility reminder.
	 */
	private function schedule_instant_deposit_note_reminder(): void {
		$this->scheduler->schedule_job( self::INSTANT_DEPOSIT_REMINDER_ACTION, array(), time() + self::INSTANT_DEPOSIT_REMINDER_DELAY );
	}

	/**
	 * Get post-KYC activation stages already sent.
	 *
	 * @return int[]
	 */
	private function get_post_kyc_sent_stages(): array {
		$sent_stages = get_option( self::POST_KYC_EMAIL_SENT_STAGES_OPTION, array() );
		if ( ! is_array( $sent_stages ) ) {
			return array();
		}

		return array_values(
			array_intersect(
				self::POST_KYC_STAGE_DAYS,
				array_map( 'intval', $sent_stages )
			)
		);
	}

	/**
	 * Tell whether the store is eligible for a post-KYC activation email.
	 *
	 * @return bool
	 */
	private function is_post_kyc_activation_email_eligible(): bool {
		if ( ! $this->account_service->can_process_payments() ) {
			return false;
		}

		if ( $this->account_service->has_test_account() ) {
			return false;
		}

		if ( $this->account_service->is_test_mode_enabled() ) {
			return false;
		}

		if ( ! get_option( self::KYC_COMPLETION_DATE_OPTION ) ) {
			return false;
		}

		return ! $this->has_live_sale();
	}

	/**
	 * Tell whether the store has a live WooPayments sale: the recorded flag, or else one query for a paid live order through
	 * the card gateway, which records the flag when it finds one, as client 11.1.0 `has_live_sale()` does
	 * (`includes/class-wc-payments-order-service.php:360-382`). Recording it drops the cached post-KYC eligibility, as the
	 * client's listener on the flag's first write does (`includes/admin/attach-rate/class-wc-payments-post-kyc-activation-notice.php:68-73`).
	 * The query only checks that one order exists, so it asks for no sort.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function has_live_sale(): bool {
		if ( get_option( self::HAS_LIVE_SALE_OPTION ) ) {
			return true;
		}

		$orders = wc_get_orders(
			array(
				'payment_method' => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'limit'          => 1,
				'orderby'        => 'none',
				'return'         => 'ids',
				'status'         => array( OrderInternalStatus::COMPLETED, OrderInternalStatus::PROCESSING ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => '_wcpay_mode',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => WooPaymentsOrderMode::PRODUCTION,
			)
		);

		if ( ! empty( $orders ) ) {
			update_option( self::HAS_LIVE_SALE_OPTION, '1', true );
			delete_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
			return true;
		}

		return false;
	}

	/**
	 * Get the post-KYC activation email instance.
	 *
	 * @return WooPaymentsPostKycActivationEmail|null
	 */
	private function get_post_kyc_activation_email(): ?WooPaymentsPostKycActivationEmail {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ self::POST_KYC_ACTIVATION_EMAIL_CLASS_KEY ] ?? null;

		return $email instanceof WooPaymentsPostKycActivationEmail ? $email : null;
	}

	/**
	 * Log a failed Instant Deposits note job without fataling the request.
	 *
	 * The exception's message is left out. The class, code, trace and, for a platform error, its status and code are
	 * logged instead, whatever the logging setting.
	 *
	 * @param string              $message   Log message.
	 * @param Throwable           $exception Exception thrown.
	 * @param array<string,mixed> $context   Context added to the line, such as the Action Scheduler hook.
	 */
	private function log_exception( string $message, Throwable $exception, array $context = array() ): void {
		wc_get_logger()->error(
			$message,
			array_merge( $context, WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
		);
	}
}
