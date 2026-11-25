<?php
/**
 * NoticeService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WP_Error;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTracks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderMode;

/**
 * Picks the notice the WooPayments settings page shows the current user, the one-and-done follow-up to a store's first
 * live sale, and records the user's actions on it, as client 11.3.0 shows only that notice
 * (`includes/admin/attach-rate/class-wc-payments-admin-notices.php`).
 *
 * It answers nothing while the WooPayments extension owns payments.
 *
 * @since 11.2.0
 * @internal
 */
class NoticeService {
	/**
	 * Existing site-scoped one-and-done eligibility cache.
	 */
	private const ONE_AND_DONE_ELIGIBLE_TRANSIENT = 'wcpay_one_and_done_eligible';

	/**
	 * Existing permanent one-and-done ineligibility marker.
	 */
	private const ONE_AND_DONE_PERMANENTLY_INELIGIBLE_OPTION = 'wcpay_one_and_done_permanently_ineligible';

	/**
	 * Supported actions for each settings notice.
	 */
	private const ACTIONS = array(
		'one_and_done' => array( 'shown', 'dismiss', 'snooze', 'cta' ),
	);

	/**
	 * The WooPayments extension's user meta keys for the one-and-done notice, so a dismissal or snooze carries over.
	 */
	private const USER_META_KEYS = array(
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
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Current time provider.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Set the clock used for notice markers.
	 *
	 * @since 11.2.0
	 *
	 * @param callable|null $clock Optional test clock.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Initialize the notice service.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Current account service.
	 * @param WooPaymentsRuntimeArbiter $runtime_arbiter Runtime owner arbiter.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsRuntimeArbiter $runtime_arbiter ): void {
		$this->account_service = $account_service;
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Work out the current user's settings notice, refreshing the cached eligibility.
	 *
	 * @since 11.2.0
	 *
	 * @return array|null
	 */
	public function refresh_notice_for_current_user(): ?array {
		$user_id = get_current_user_id();
		if ( 0 === $user_id || $this->runtime_arbiter->is_extension_owner() ) {
			return null;
		}
		// Client 11.1.0 has_active_payments_account() opens every notice's eligibility and rules out test-drive accounts.
		if ( ! $this->account_service->has_working_account() || $this->account_service->has_test_account() ) {
			return null;
		}

		return $this->refresh_one_and_done_notice( $user_id );
	}

	/**
	 * Get the first-sale recovery notice for the current user.
	 *
	 * @param int $user_id Current user ID.
	 * @return array|null
	 */
	private function refresh_one_and_done_notice( int $user_id ): ?array {
		$now = (int) call_user_func( $this->clock );
		if ( ! $this->can_consider_one_and_done( $user_id, $now ) ) {
			return null;
		}

		$eligibility = get_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
		if ( '0' === $eligibility ) {
			return null;
		}
		if ( false === $eligibility ) {
			$wcpay_live_order_ids = $this->query_live_woopayments_order_ids( 2 );
			if ( 2 <= count( $wcpay_live_order_ids ) ) {
				update_option( self::ONE_AND_DONE_PERMANENTLY_INELIGIBLE_OPTION, '1', false );
				delete_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
				return null;
			}
			if ( 1 !== count( $wcpay_live_order_ids ) ) {
				set_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT, '0', HOUR_IN_SECONDS );
				return null;
			}

			$other_gateway_ids = array_values(
				array_filter(
					array_keys( WC()->payment_gateways()->payment_gateways() ),
					static function ( string $gateway_id ): bool {
						return ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $gateway_id );
					}
				)
			);
			if ( ! empty( $other_gateway_ids ) ) {
				$other_gateway_order_ids = wc_get_orders(
					array(
						'payment_method' => $other_gateway_ids,
						'limit'          => 1,
						'orderby'        => 'none',
						'return'         => 'ids',
						'status'         => array( OrderInternalStatus::COMPLETED, OrderInternalStatus::PROCESSING ),
					)
				);
				if ( ! empty( $other_gateway_order_ids ) ) {
					update_option( self::ONE_AND_DONE_PERMANENTLY_INELIGIBLE_OPTION, '1', false );
					delete_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
					return null;
				}
			}

			$first_order = wc_get_order( (int) reset( $wcpay_live_order_ids ) );
			$order_date  = $first_order ? $first_order->get_date_created() : null;
			$eligibility = $order_date && $now >= $order_date->getTimestamp() + 7 * DAY_IN_SECONDS ? '1' : '0';
			set_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT, $eligibility, HOUR_IN_SECONDS );
			if ( '0' === $eligibility ) {
				return null;
			}
		}

		$action_base = 'wc-admin/settings/payments/woopayments/admin-notices/one_and_done/';
		return array(
			'id'        => 'one_and_done',
			'message'   => __( "Your store made its first sale. Now bring more shoppers in with Woo's marketing tools.", 'woocommerce' ),
			'primary'   => array(
				'kind'  => 'navigate_and_dismiss',
				'label' => __( 'Promote my store', 'woocommerce' ),
				'href'  => admin_url( 'admin.php?page=wc-admin&path=/marketing' ),
			),
			'secondary' => array(
				'kind'  => 'snooze',
				'label' => __( 'Maybe later', 'woocommerce' ),
			),
			'_links'    => array(
				'shown'   => array( 'href' => rest_url( $action_base . 'shown' ) ),
				'dismiss' => array( 'href' => rest_url( $action_base . 'dismiss' ) ),
				'snooze'  => array( 'href' => rest_url( $action_base . 'snooze' ) ),
				'cta'     => array( 'href' => rest_url( $action_base . 'cta' ) ),
			),
		);
	}

	/**
	 * Check cheap first-sale predicates before any order query.
	 *
	 * @param int $user_id Current user ID.
	 * @param int $now     Current timestamp.
	 * @return bool
	 */
	private function can_consider_one_and_done( int $user_id, int $now ): bool {
		// Client 11.3.0 compute_eligibility() also rules out test mode, now that no other notice shows there.
		if ( get_option( self::ONE_AND_DONE_PERMANENTLY_INELIGIBLE_OPTION ) || ! $this->account_service->can_process_payments() || ! $this->account_service->has_live_account() || $this->account_service->has_test_account() || $this->account_service->is_test_mode_enabled() || $this->account_service->is_dev_mode_enabled() ) {
			return false;
		}
		if ( get_user_meta( $user_id, self::USER_META_KEYS['one_and_done']['dismiss'], true ) ) {
			return false;
		}
		$snoozed_at = (int) get_user_meta( $user_id, self::USER_META_KEYS['one_and_done']['snooze'], true );

		return 0 === $snoozed_at || $now >= $snoozed_at + 7 * DAY_IN_SECONDS;
	}

	/**
	 * Query a bounded set of paid live WooPayments order IDs.
	 *
	 * Client 11.1.0 one-and-done notice Q1 matches `_wcpay_mode = Order_Mode::PRODUCTION` (includes/admin/attach-rate/class-wc-payments-one-and-done-notice.php:166-180).
	 *
	 * @param int $limit Maximum IDs to return.
	 * @return int[]
	 */
	private function query_live_woopayments_order_ids( int $limit ): array {
		/**
		 * Order IDs returned by the ID-only query.
		 *
		 * @var int[] $order_ids
		 */
		$order_ids = wc_get_orders(
			array(
				'payment_method' => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'limit'          => $limit,
				'orderby'        => 'none',
				'return'         => 'ids',
				'status'         => array( OrderInternalStatus::COMPLETED, OrderInternalStatus::PROCESSING ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => WooPaymentsPersistenceVocabulary::MODE_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => WooPaymentsOrderMode::PRODUCTION,
			)
		);

		return $order_ids;
	}

	/**
	 * Record a current-user notice action.
	 *
	 * @since 11.2.0
	 *
	 * @param string $notice_id Notice identifier.
	 * @param string $action    Action identifier.
	 * @return bool|WP_Error
	 */
	public function record_action( string $notice_id, string $action ) {
		if ( ! isset( self::ACTIONS[ $notice_id ] ) || ! in_array( $action, self::ACTIONS[ $notice_id ], true ) ) {
			return new WP_Error( 'woocommerce_woopayments_invalid_notice_action', __( 'Invalid notice action.', 'woocommerce' ) );
		}
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return new WP_Error( 'woocommerce_woopayments_notice_user_required', __( 'Sign in to change this notice.', 'woocommerce' ) );
		}
		if ( $this->runtime_arbiter->is_extension_owner() ) {
			return new WP_Error( 'woocommerce_woopayments_notice_extension_owned', __( 'The WooPayments extension manages this notice.', 'woocommerce' ) );
		}
		// A CTA click also dismisses the notice.
		$marker   = 'cta' === $action ? 'dismiss' : $action;
		$meta_key = self::USER_META_KEYS[ $notice_id ][ $marker ];
		$now      = (int) call_user_func( $this->clock );
		if ( metadata_exists( 'user', $user_id, $meta_key ) ) {
			$existing_marker = (int) get_user_meta( $user_id, $meta_key, true );
			if ( 'snooze' !== $action || $now < $existing_marker + 7 * DAY_IN_SECONDS ) {
				return true;
			}
			$stored = update_user_meta( $user_id, $meta_key, $now );
		} else {
			$stored = add_user_meta( $user_id, $meta_key, $now, true );
		}
		$changed = (bool) $stored;
		if ( ! $stored ) {
			$existing_marker = (int) get_user_meta( $user_id, $meta_key, true );
			$stored          = metadata_exists( 'user', $user_id, $meta_key ) && ( 'snooze' !== $action || $now < $existing_marker + 7 * DAY_IN_SECONDS );
		}
		if ( ! $stored ) {
			return new WP_Error( 'woocommerce_woopayments_notice_action_failed', __( 'Could not update the notice.', 'woocommerce' ) );
		}
		delete_transient( self::ONE_AND_DONE_ELIGIBLE_TRANSIENT );
		if ( $changed ) {
			$this->record_tracks_event( $notice_id . ':' . $action );
		}

		return true;
	}

	/**
	 * Record the client 11.1.0 attach-rate notice Tracks event (`class-wc-payments-abstract-admin-notice.php:196,222,446,546`
	 * and the notices' handle_cta()/hide_notice() overrides) once this user's notice state changed.
	 *
	 * @param string $notice_action Notice identifier and action, joined by a colon.
	 */
	private function record_tracks_event( string $notice_action ): void {
		switch ( $notice_action ) {
			case 'one_and_done:shown':
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_one_and_done_notice_shown' );
				break;
			case 'one_and_done:dismiss':
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_one_and_done_notice_dismissed' );
				break;
			case 'one_and_done:snooze':
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_one_and_done_notice_snoozed' );
				break;
			case 'one_and_done:cta':
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_one_and_done_notice_cta_clicked', array( 'destination' => 'marketing' ) );
				break;
		}
	}
}
