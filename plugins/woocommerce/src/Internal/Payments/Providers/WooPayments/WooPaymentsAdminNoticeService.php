<?php
/**
 * WooPaymentsAdminNoticeService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use WP_Error;

/**
 * Current-user notice state for the WooPayments settings page.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAdminNoticeService {
	/**
	 * Existing site-scoped test-to-live eligibility cache.
	 */
	private const TEST_TO_LIVE_ELIGIBLE_TRANSIENT = 'wcpay_test_to_live_eligible';

	/**
	 * Existing site-scoped post-KYC activation eligibility cache.
	 */
	private const POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT = 'wcpay_post_kyc_activation_eligible';

	/**
	 * Supported post-KYC activation stages.
	 */
	private const POST_KYC_ACTIVATION_STAGES = array( 7, 14, 30 );

	/**
	 * Duplicate inbox note previously produced for the same journey.
	 */
	private const TEST_TO_LIVE_NOTE_NAME = 'wc-payments-notes-test-to-live';

	/**
	 * Supported actions for each settings notice.
	 */
	private const ACTIONS = array(
		'test_to_live'        => array( 'shown', 'dismiss', 'snooze' ),
		'post_kyc_activation' => array( 'shown', 'dismiss' ),
		'one_and_done'        => array( 'shown', 'dismiss', 'snooze' ),
	);

	/**
	 * Preserved client user-meta keys for non-staged notices.
	 */
	private const USER_META_KEYS = array(
		'test_to_live' => array(
			'shown'   => 'wcpay_test_to_live_notice_shown',
			'dismiss' => 'wcpay_test_to_live_notice_dismissed',
			'snooze'  => 'wcpay_test_to_live_notice_snoozed',
		),
		'one_and_done' => array(
			'shown'   => 'wcpay_one_and_done_notice_shown',
			'dismiss' => 'wcpay_one_and_done_notice_dismissed_at',
			'snooze'  => 'wcpay_one_and_done_notice_snoozed_at',
		),
	);

	/**
	 * Current account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Current time provider.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Set the clock used for notice markers.
	 *
	 * @param callable|null $clock Optional test clock.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Initialize the notice service.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Current account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Get the current settings notice, if any.
	 *
	 * @since 11.2.0
	 *
	 * @return array|null
	 */
	public function get_notice_for_current_user(): ?array {
		if ( 0 === get_current_user_id() || ! $this->account_service->has_working_account() ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( metadata_exists( 'user', $user_id, self::USER_META_KEYS['test_to_live']['shown'] ) || metadata_exists( 'user', $user_id, self::USER_META_KEYS['test_to_live']['dismiss'] ) || metadata_exists( 'user', $user_id, self::USER_META_KEYS['test_to_live']['snooze'] ) ) {
			Notes::delete_notes_with_name( self::TEST_TO_LIVE_NOTE_NAME );
		}

		if ( ! $this->account_service->is_test_mode_enabled() ) {
			return $this->get_post_kyc_notice( $user_id );
		}
		if ( $this->account_service->is_dev_mode_enabled() ) {
			return null;
		}

		$now          = (int) call_user_func( $this->clock );
		$enabled_date = (int) get_option( 'wcpay_test_mode_enabled_date', 0 );
		if ( 0 === $enabled_date || $now < $enabled_date + 7 * DAY_IN_SECONDS ) {
			return null;
		}

		if ( get_user_meta( $user_id, self::USER_META_KEYS['test_to_live']['dismiss'], true ) ) {
			return null;
		}
		$snoozed_at = (int) get_user_meta( $user_id, self::USER_META_KEYS['test_to_live']['snooze'], true );
		if ( 0 < $snoozed_at && $now < $snoozed_at + 7 * DAY_IN_SECONDS ) {
			return null;
		}

		if ( false === get_transient( self::TEST_TO_LIVE_ELIGIBLE_TRANSIENT ) ) {
			$orders = wc_get_orders(
				array(
					'payment_method' => OrderPaymentStore::GATEWAY_ID,
					'limit'          => 1,
					'orderby'        => 'none',
					'return'         => 'ids',
					'status'         => array( OrderInternalStatus::COMPLETED, OrderInternalStatus::PROCESSING ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'       => '_wcpay_mode',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'     => 'test',
				)
			);
			if ( empty( $orders ) ) {
				return null;
			}
			set_transient( self::TEST_TO_LIVE_ELIGIBLE_TRANSIENT, '1', HOUR_IN_SECONDS );
		}

		$can_go_live = $this->account_service->has_live_account();
		$primary_url = $can_go_live ? Utils::wc_payments_settings_url( '/woopayments/settings' ) : Utils::wc_payments_settings_url( '/woopayments/onboarding' );
		$action_base = 'wc-admin/settings/payments/woopayments/admin-notices/test_to_live/';
		Notes::delete_notes_with_name( self::TEST_TO_LIVE_NOTE_NAME );

		return array(
			'id'        => 'test_to_live',
			'message'   => __( "You're ready to take real payments. Switch from test mode to start charging customers.", 'woocommerce' ),
			'primary'   => array(
				'kind'  => $can_go_live ? 'disable_test_mode' : 'onboard',
				'label' => __( 'Turn on live payments', 'woocommerce' ),
				'href'  => $primary_url,
			),
			'secondary' => array(
				'kind'  => 'snooze',
				'label' => __( 'Maybe later', 'woocommerce' ),
			),
			'_links'    => array(
				'shown'   => array( 'href' => rest_url( $action_base . 'shown' ) ),
				'dismiss' => array( 'href' => rest_url( $action_base . 'dismiss' ) ),
				'snooze'  => array( 'href' => rest_url( $action_base . 'snooze' ) ),
			),
		);
	}

	/**
	 * Get the oldest due post-KYC activation stage for the current user.
	 *
	 * @param int $user_id Current user ID.
	 * @return array|null
	 */
	private function get_post_kyc_notice( int $user_id ): ?array {
		if ( ! $this->account_service->can_process_payments() || ! $this->account_service->has_live_account() || $this->account_service->has_test_account() || $this->account_service->is_dev_mode_enabled() || get_option( 'wcpay_has_live_sale' ) ) {
			return null;
		}

		$now      = (int) call_user_func( $this->clock );
		$kyc_date = (int) get_option( 'wcpay_kyc_completion_date', 0 );
		if ( 0 === $kyc_date || $now < $kyc_date + 7 * DAY_IN_SECONDS || $now >= $kyc_date + 60 * DAY_IN_SECONDS ) {
			return null;
		}

		$messages         = array(
			7  => __( 'Your store is open. Now bring in your first customer.', 'woocommerce' ),
			14 => __( 'Two weeks on, still no first sale?', 'woocommerce' ),
			30 => __( "A month in. Let's get your first sale.", 'woocommerce' ),
		);
		$selected_stage   = null;
		$selected_message = null;
		foreach ( $messages as $stage => $message ) {
			if ( $now < $kyc_date + $stage * DAY_IN_SECONDS || get_user_meta( $user_id, sprintf( 'wcpay_post_kyc_activation_%d_dismissed', $stage ), true ) ) {
				continue;
			}
			$selected_stage   = $stage;
			$selected_message = $message;
			break;
		}
		if ( null === $selected_stage || null === $selected_message ) {
			return null;
		}

		if ( false === get_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT ) ) {
			$orders = wc_get_orders(
				array(
					'payment_method' => OrderPaymentStore::GATEWAY_ID,
					'limit'          => 1,
					'orderby'        => 'none',
					'return'         => 'ids',
					'status'         => array( OrderInternalStatus::COMPLETED, OrderInternalStatus::PROCESSING ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'       => '_wcpay_mode',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'     => array( 'production', 'prod', 'live' ),
					'meta_compare'   => 'IN',
				)
			);
			if ( ! empty( $orders ) ) {
				update_option( 'wcpay_has_live_sale', '1', true );
				delete_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
				return null;
			}
			set_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT, '1', HOUR_IN_SECONDS );
		}

		$action_base = 'wc-admin/settings/payments/woopayments/admin-notices/post_kyc_activation/';
		return array(
			'id'      => 'post_kyc_activation',
			'stage'   => $selected_stage,
			'message' => $selected_message,
			'primary' => array(
				'kind'  => 'navigate_and_dismiss',
				'label' => __( 'Promote my store', 'woocommerce' ),
				'href'  => admin_url( 'admin.php?page=wc-admin&path=/marketing' ),
			),
			'_links'  => array(
				'shown'   => array( 'href' => rest_url( $action_base . 'shown' ) ),
				'dismiss' => array( 'href' => rest_url( $action_base . 'dismiss' ) ),
			),
		);
	}

	/**
	 * Record a current-user notice action.
	 *
	 * @since 11.2.0
	 *
	 * @param string   $notice_id Notice identifier.
	 * @param string   $action    Action identifier.
	 * @param int|null $stage     Optional post-KYC stage.
	 * @return bool|WP_Error
	 */
	public function record_action( string $notice_id, string $action, ?int $stage = null ) {
		$valid_stage = 'post_kyc_activation' === $notice_id
			? in_array( $stage, self::POST_KYC_ACTIVATION_STAGES, true )
			: null === $stage;
		if ( ! isset( self::ACTIONS[ $notice_id ] ) || ! in_array( $action, self::ACTIONS[ $notice_id ], true ) || ! $valid_stage ) {
			return new WP_Error( 'woocommerce_woopayments_invalid_notice_action', __( 'Invalid notice action.', 'woocommerce' ) );
		}
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return new WP_Error( 'woocommerce_woopayments_notice_user_required', __( 'Sign in to change this notice.', 'woocommerce' ) );
		}

		$meta_key = 'post_kyc_activation' === $notice_id
			? sprintf( 'wcpay_post_kyc_activation_%d_%s', $stage, 'dismiss' === $action ? 'dismissed' : 'shown' )
			: self::USER_META_KEYS[ $notice_id ][ $action ];
		if ( metadata_exists( 'user', $user_id, $meta_key ) ) {
			return true;
		}
		if ( ! add_user_meta( $user_id, $meta_key, (int) call_user_func( $this->clock ), true ) ) {
			return new WP_Error( 'woocommerce_woopayments_notice_action_failed', __( 'Could not update the notice.', 'woocommerce' ) );
		}
		if ( 'post_kyc_activation' === $notice_id ) {
			delete_transient( self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
		}
		if ( 'test_to_live' === $notice_id ) {
			delete_transient( self::TEST_TO_LIVE_ELIGIBLE_TRANSIENT );
			Notes::delete_notes_with_name( self::TEST_TO_LIVE_NOTE_NAME );
		}

		return true;
	}
}
