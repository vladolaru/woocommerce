<?php
/**
 * WooPaymentsAdminNoticeService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WP_Error;

/**
 * Current-user notice state for the WooPayments settings page.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAdminNoticeService {
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

		return null;
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
			? in_array( $stage, array( 7, 14, 30 ), true )
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

		return true;
	}
}
