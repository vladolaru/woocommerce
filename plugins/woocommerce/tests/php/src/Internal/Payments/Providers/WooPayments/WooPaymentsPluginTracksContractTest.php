<?php
/**
 * WooPaymentsPluginTracksContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use WC_Unit_Test_Case;

/**
 * Pins the plugin 11.1.0 Tracks event call-site surface (`plugin-11.1.0-tracks.json`) against
 * native, so an event neither recorded nor recorded as an allowed difference or a known gap is a
 * build-time failure instead of a silent analytics regression.
 *
 * Honest limit (D3): this proves native has a call site that would record the wire name, not that
 * it fires at runtime. A call site inside dead code would pass; runtime emission is a live-store
 * residual (draft issue 14).
 *
 * See `.agents/scratchpad/sessions/2026-09-03-native-payments-program-orientation/`
 * `plan-task-t4-bc-inventories.md`, "4. Tracks event continuity".
 *
 * @since 11.2.0
 */
class WooPaymentsPluginTracksContractTest extends WC_Unit_Test_Case {

	use NativeSourceScanTrait;

	/**
	 * Native PHP scan roots.
	 *
	 * @var array<int,string>
	 */
	private const PHP_SCAN_ROOTS = array(
		'src/Internal/Payments',
		'src/Internal/MultiCurrency',
		'src/Internal/Admin/Settings/PaymentsProviders/WooPayments',
	);

	/**
	 * Native client (JS/TS) scan roots. Two are glob prefixes rather than whole directories
	 * (the WooPay order-status admin script and the legacy frontend bundle share their parent
	 * directory with unrelated files), expanded in `native_client_scan_paths()`.
	 *
	 * @var array<int,string>
	 */
	private const CLIENT_SCAN_ROOTS = array(
		'client/admin/client/woopayments',
		'client/admin/client/settings-payments',
		'client/admin/client/wp-admin-scripts/woopayments-*',
		'client/blocks/assets/js/extensions/payment-methods/woopayments',
		'client/legacy/js/frontend/woopayments-*.js',
	);

	/**
	 * PHP recorders. `object` `true` matches `->name(`; `false` matches a bare/free-function
	 * `name(`; a `static-ClassName` string matches `ClassName::name(`. `prefix` is prepended to
	 * the resolved literal to form the wire name.
	 *
	 * `wc_admin_record_tracks_event`, `record_tracks_event` and `WC_Tracks::record_event` all
	 * carry `prefix => ''` here even though `WC_Tracks::record_event()` itself prepends `wcadmin_`
	 * on the wire: the fixture's own `wire_name` for that same call shape is already the bare call
	 * name, unprefixed (`extract-tracks.php`'s `$PHP_RECORDERS` table), so both sides agree and the
	 * comparison is still exact.
	 *
	 * @var array<string,array{object:bool|string,prefix:string}>
	 */
	private const PHP_RECORDERS = array(
		'record_user_event'            => array(
			'object' => true,
			'prefix' => 'wcpay_',
		),
		'record_registration_event'    => array(
			'object' => true,
			'prefix' => 'wcpay_',
		),
		'record_tracks_event'          => array(
			'object' => true,
			'prefix' => '',
		),
		'wc_admin_record_tracks_event' => array(
			'object' => false,
			'prefix' => '',
		),
		'record_event'                 => array(
			'object' => 'static-WC_Tracks',
			'prefix' => '',
		),
	);

	/**
	 * Fixture keys native intentionally does not carry, with the recorded authority.
	 *
	 * `data/bc-inventory-undecided-classified.tsv` tracks rows classified DECIDED, plus the
	 * MISSING_SURFACE/UNDECIDED rows the controller resolved to a superseded-by-NOX or dead-code
	 * authority (Task 0 of plan-task-t4-bc-inventories.md).
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_DIFFERENCES = array(
		'wcadmin_payments_transactions_risk_review_list_review_button_click' => 'plan.md T.7 Step 6 (d): dead code in client 11.1.0 (RiskReviewList is not mounted).',
		'wcadmin_wcpay_connect_account_clicked'          => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_* onboarding events).',
		'wcadmin_wcpay_connect_account_kyc_modal_opened' => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_* onboarding events).',
		'wcadmin_wcpay_dispute_outcome_action_clicked'   => 'data/client-delta-10.8.0-11.1.0.tsv:12, :15, :17, :58, :61 (10.9.0 Dispute Outcome View and its Tracks events, n/a plugin-only)',
		'wcadmin_wcpay_dispute_outcome_recommendations_section_viewed' => 'data/client-delta-10.8.0-11.1.0.tsv:12, :15, :17, :58, :61 (10.9.0 Dispute Outcome View and its Tracks events, n/a plugin-only)',
		'wcadmin_wcpay_dispute_outcome_viewed'           => 'data/client-delta-10.8.0-11.1.0.tsv:12, :15, :17, :58, :61 (10.9.0 Dispute Outcome View and its Tracks events, n/a plugin-only)',
		'wcadmin_wcpay_onboarding_flow_eligibility_modal_closed' => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_embedded_step_change' => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_exited'           => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_hidden'           => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_redirected'       => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_started'          => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_flow_step_completed'   => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_onboarding_kyc_exit'              => 'plan.md T.7 Step 6 (d): superseded by NOX (settings_payments_woopayments_onboarding_* events, WooPaymentsService EVENT_PREFIX).',
		'wcadmin_wcpay_reports_feedback_cancel'          => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_dismiss'         => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_submit'          => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_submit_error'    => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_thumbs_down'     => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_thumbs_up'       => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_reports_feedback_view'            => 'data/client-delta-10.8.0-11.1.0.tsv:4 (10.9.0 inline Reports feedback survey, n/a plugin-only)',
		'wcadmin_wcpay_review_prompt_action'             => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12)',
		'wcadmin_wcpay_review_prompt_shown'              => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12)',
		'wcadmin_wcpay_subscriptions_account_not_connected_product_modal_dismiss' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcadmin_wcpay_subscriptions_account_not_connected_product_modal_finish_setup' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcadmin_wcpay_subscriptions_account_not_connected_product_modal_view' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcadmin_wcpay_subscriptions_empty_state_create_product' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcadmin_wcpay_subscriptions_empty_state_finish_setup' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcadmin_wcpay_subscriptions_empty_state_view'   => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
		'wcpay_account_connect_finished'                 => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_account_connect_start'                    => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_account_connect_wpcom_connection_failure' => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_account_connect_wpcom_connection_start'   => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_account_connect_wpcom_connection_success' => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_onboarding_flow_reset'                    => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_onboarding_test_account_disable'          => 'plan.md T.7 Step 6 (d): superseded by the NOX settings_payments_woopayments_* onboarding events; record an old-to-new name map.',
		'wcpay_subscriptions_account_not_connected_save_product' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded)',
	);

	/**
	 * Fixture keys that are recorded parity defects, scheduled for a later fix (D8, T.7 Step 6).
	 * Unlike ALLOWED_DIFFERENCES, these are not permanent: `test_allowed_differences_are_not_stale`
	 * fails once native records the event, forcing the fix to remove the entry here too.
	 *
	 * @var array<string,string>
	 */
	private const KNOWN_GAPS = array(
		'wcadmin_payments_transactions_details_cancel_charge_button_click' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_payments_transactions_details_capture_charge_button_click' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_payments_transactions_details_refund_modal_close' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_payments_transactions_uncaptured_list_capture_charge_button_click' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_deposits_row_click'                 => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_accept_click'               => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_accept_modal_view'          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_help_link_clicked'          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_readiness_card_dismissed'   => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_readiness_overview_viewed'  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_readiness_signal_cta_clicked' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_dispute_readiness_statement_descriptor_confirmed' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_fraud_outcome_transactions_download' => 'plan.md T.7 Step 6 (b): merchant-visible feature gap, scheduled to port.',
		'wcadmin_wcpay_fraud_protection_order_details_link_clicked' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_fraud_protection_transaction_reviewed_merchant_approved' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_fraud_protection_transaction_reviewed_merchant_blocked' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_inbox_action_dismissed'             => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_merchant_settings_file_upload_started' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_merchant_settings_file_upload_success' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_merchant_settings_upload_failed'    => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_order_dispute_notice_action_click'  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_order_dispute_notice_view'          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_overview_currency_select_change'    => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_overview_sandbox_mode_learn_more_clicked' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_overview_stripe_notifications_banner_action_completed' => 'plan.md T.7 Step 6 (b): merchant-visible feature gap, scheduled to port.',
		'wcadmin_wcpay_overview_stripe_notifications_banner_update' => 'plan.md T.7 Step 6 (b): merchant-visible feature gap, scheduled to port.',
		'wcadmin_wcpay_overview_task_click'                => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_overview_test_mode_learn_more_clicked' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_reports_balance_export_error'       => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_reports_balance_export_success'     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_reports_fees_date_filter_change'    => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_reports_fees_filter_change'         => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_settings_deposits_manage_in_stripe_click' => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_wcpay_stripe_connected'                   => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_woopay_disabled'                          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_woopay_enabled'                           => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_woopay_express_button_locations_updated'  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_woopay_global_theme_support_disabled'     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcadmin_woopay_global_theme_support_enabled'      => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_account_referral'                           => 'plan.md T.7 Step 6 (b): merchant-visible feature gap, scheduled to port.',
		'wcpay_capital_view_offer_redirect'                => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_cart_page_view'                             => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_checkout_save_my_info_privacy_policy_click' => 'plan.md T.7 Step 6 (a): WooPay save-my-info consent copy/country dropdown, scheduled to port.',
		'wcpay_checkout_save_my_info_tos_click'            => 'plan.md T.7 Step 6 (a): WooPay save-my-info consent copy/country dropdown, scheduled to port.',
		'wcpay_checkout_woopay_save_my_info_country_click' => 'plan.md T.7 Step 6 (a): WooPay save-my-info consent copy/country dropdown, scheduled to port.',
		'wcpay_checkout_woopay_save_my_info_mobile_enter'  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_edit_order_refund_failure'                  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_edit_order_refund_success'                  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_first_live_sale'                            => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_kyc_reminder_merchant_returned'             => 'plan.md T.7 Step 6 (b): merchant-visible feature gap, scheduled to port.',
		'wcpay_merchant_captured_auth'                     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_one_and_done_notice_cta_clicked'            => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_one_and_done_notice_dismissed'              => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_one_and_done_notice_shown'                  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_one_and_done_notice_snoozed'                => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_pay_for_order_page_view'                    => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_payment_method_disabled'                    => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_payment_method_enabled'                     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_post_kyc_activation_notice_cta_clicked'     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_post_kyc_activation_notice_dismissed'       => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_post_kyc_activation_notice_shown'           => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_post_kyc_activation_notice_snoozed'         => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_product_page_view'                          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_test_to_live_notice_cta_clicked'            => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_test_to_live_notice_dismissed'              => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_test_to_live_notice_shown'                  => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_test_to_live_notice_snoozed'                => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_wcpay_proceed_to_checkout_button_click'     => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
		'wcpay_woopay_registered'                          => 'plan.md T.7 Step 6 (c): Tracks event on a surface native already has, scheduled to port.',
	);

	/**
	 * Loaded fixture.
	 *
	 * @var array<string,mixed>
	 */
	private static array $fixture;

	/**
	 * Load the plugin-11.1.0-tracks.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-tracks.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		self::$fixture = json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * @testdox The fixture is complete: its declared count matches its entries, and every wire name is unique.
	 */
	public function test_fixture_is_complete(): void {
		$fixture = self::$fixture;

		$this->assertSame( $fixture['count'], count( $fixture['entries'] ), 'count must match the number of entries.' );

		$wire_names = array_column( $fixture['entries'], 'wire_name' );
		$this->assertSame( array_unique( $wire_names ), $wire_names, 'Every wire_name must be unique.' );
	}

	/**
	 * @testdox Native has a call site recording every plugin 11.1.0 Tracks event, or the difference is recorded.
	 */
	public function test_native_records_every_plugin_tracks_event_or_allows_it(): void {
		$native   = $this->native_tracks_wire_names();
		$failures = array();

		foreach ( self::$fixture['entries'] as $entry ) {
			$wire_name = $entry['wire_name'];
			if ( isset( self::ALLOWED_DIFFERENCES[ $wire_name ] ) || isset( self::KNOWN_GAPS[ $wire_name ] ) ) {
				continue;
			}

			if ( ! isset( $native[ $wire_name ] ) ) {
				$failures[] = $wire_name . ' (plugin_site: ' . $entry['plugin_site'] . ')';
			}
		}

		$this->assertSame( array(), $failures, "Native must record every plugin 11.1.0 Tracks event or list it in ALLOWED_DIFFERENCES/KNOWN_GAPS:\n" . implode( "\n", $failures ) );
	}

	/**
	 * @testdox No ALLOWED_DIFFERENCES or KNOWN_GAPS event is stale: native must not already record it.
	 */
	public function test_allowed_differences_are_not_stale(): void {
		$native = $this->native_tracks_wire_names();
		$stale  = array();

		foreach ( array_merge( array_keys( self::ALLOWED_DIFFERENCES ), array_keys( self::KNOWN_GAPS ) ) as $wire_name ) {
			if ( isset( $native[ $wire_name ] ) ) {
				$stale[] = $wire_name;
			}
		}

		$this->assertSame( array(), $stale, "These allowances are now recorded natively; remove the entry:\n" . implode( "\n", $stale ) );
	}

	/**
	 * Collect every Tracks wire name native has a call site for, across PHP and client sources.
	 *
	 * @return array<string,bool> Wire names as keys (presence set).
	 */
	private function native_tracks_wire_names(): array {
		$names       = array();
		$plugin_path = WC()->plugin_path();

		$php_roots = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::PHP_SCAN_ROOTS
		);
		foreach ( $this->native_php_tracks_wire_names( $this->native_collect_files( $php_roots, array( 'php' ) ) ) as $name ) {
			$names[ $name ] = true;
		}

		foreach ( $this->native_client_scan_paths( $plugin_path ) as $file ) {
			foreach ( $this->native_js_tracks_wire_names( $file ) as $name ) {
				$names[ $name ] = true;
			}
		}

		return $names;
	}

	/**
	 * Resolve `CLIENT_SCAN_ROOTS` (two of which are glob prefixes) to concrete JS/TS files.
	 *
	 * @param string $plugin_path Absolute plugin directory.
	 * @return array<int,string> Absolute file paths.
	 */
	private function native_client_scan_paths( string $plugin_path ): array {
		$files      = array();
		$extensions = array( 'js', 'jsx', 'ts', 'tsx' );

		foreach ( self::CLIENT_SCAN_ROOTS as $root ) {
			$absolute = $plugin_path . '/' . $root;
			if ( false === strpos( $absolute, '*' ) ) {
				$files = array_merge( $files, $this->native_collect_files( array( $absolute ), $extensions ) );
				continue;
			}

			foreach ( glob( $absolute ) as $match ) {
				if ( is_dir( $match ) ) {
					$files = array_merge( $files, $this->native_collect_files( array( $match ), $extensions ) );
				} elseif ( is_file( $match ) ) {
					$files[] = $match;
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Collect wire names from PHP recorder call sites in the given files.
	 *
	 * @param array<int,string> $files Absolute PHP file paths.
	 * @return array<int,string>
	 */
	private function native_php_tracks_wire_names( array $files ): array {
		$names = array();

		foreach ( $files as $path ) {
			$tokens = $this->native_tokenize( $path );
			$count  = count( $tokens );

			for ( $index = 0; $index < $count; $index++ ) {
				$token = $tokens[ $index ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( self::PHP_RECORDERS[ $token[1] ] ) ) {
					continue;
				}
				$spec = self::PHP_RECORDERS[ $token[1] ];

				$preceding_index = $index - 1;
				while ( $preceding_index >= 0 && is_array( $tokens[ $preceding_index ] ) && T_WHITESPACE === $tokens[ $preceding_index ][0] ) {
					--$preceding_index;
				}
				$preceded_by_object           = ( $preceding_index >= 0 && is_array( $tokens[ $preceding_index ] ) && T_OBJECT_OPERATOR === $tokens[ $preceding_index ][0] );
				$preceded_by_colon            = ( $preceding_index >= 0 && is_array( $tokens[ $preceding_index ] ) && T_DOUBLE_COLON === $tokens[ $preceding_index ][0] );
				$preceded_by_function_keyword = ( $preceding_index >= 0 && is_array( $tokens[ $preceding_index ] ) && T_FUNCTION === $tokens[ $preceding_index ][0] );
				if ( $preceded_by_function_keyword ) {
					continue; // Method declaration, not a call.
				}

				if ( is_string( $spec['object'] ) && 0 === strpos( $spec['object'], 'static-' ) ) {
					$want_class = substr( $spec['object'], strlen( 'static-' ) );
					if ( ! $preceded_by_colon ) {
						continue;
					}
					$class_index = $preceding_index - 1;
					while ( $class_index >= 0 && is_array( $tokens[ $class_index ] ) && T_WHITESPACE === $tokens[ $class_index ][0] ) {
						--$class_index;
					}
					$class_name = ( $class_index >= 0 && is_array( $tokens[ $class_index ] ) ) ? ltrim( (string) $tokens[ $class_index ][1], '\\' ) : null;
					if ( $class_name !== $want_class ) {
						continue;
					}
				} elseif ( $spec['object'] !== $preceded_by_object ) {
					continue;
				}

				$open_index = $index + 1;
				while ( $open_index < $count && is_array( $tokens[ $open_index ] ) && T_WHITESPACE === $tokens[ $open_index ][0] ) {
					++$open_index;
				}
				if ( $open_index >= $count || '(' !== $tokens[ $open_index ] ) {
					continue;
				}

				list( $args, $close_index ) = $this->native_php_split_call_args( $tokens, $open_index );
				if ( null === $close_index || empty( $args ) ) {
					continue;
				}

				$first_arg = $this->native_strip_trivia( $args[0] );
				if ( 1 === count( $first_arg ) && is_array( $first_arg[0] ) && T_CONSTANT_ENCAPSED_STRING === $first_arg[0][0] ) {
					$literal = $this->native_resolve_literal( $first_arg[0][1] );
					if ( null !== $literal ) {
						$names[] = $spec['prefix'] . $literal;
					}
				}
			}
		}

		return $names;
	}

	/**
	 * Split a PHP function-call's arguments into token slices, mirroring the plan's extractors.
	 *
	 * @param array<int,mixed> $tokens      Token stream.
	 * @param int              $open_index  Index of the call's opening `(`.
	 * @return array{0:array<int,array<int,mixed>>,1:?int}
	 */
	private function native_php_split_call_args( array $tokens, int $open_index ): array {
		$n         = count( $tokens );
		$depth     = 0;
		$args      = array();
		$current   = array();
		$close_idx = null;

		for ( $k = $open_index; $k < $n; $k++ ) {
			$t = $tokens[ $k ];
			if ( '(' === $t || '[' === $t || '{' === $t ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $t || ']' === $t || '}' === $t ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[]    = $current;
					$close_idx = $k;
					break;
				}
			} elseif ( 1 === $depth && ',' === $t ) {
				$args[]  = $current;
				$current = array();
				continue;
			}
			if ( $depth >= 1 ) {
				$current[] = $t;
			}
		}

		return array( $args, $close_idx );
	}

	/**
	 * Collect wire names from client (JS/TS) recorder call sites in one file.
	 *
	 * Handles the shapes the review's matching-rule fixes require: a plain literal; a ternary
	 * with any condition (`cond ? 'a' : 'b'`); a template literal over a same-file ternary-bound
	 * const (`` `${prefix}_suffix` ``); a bare identifier looked up from a same-file `*_EVENTS`
	 * map; and a `methodConfig.loadEvent`/`.clickEvent` property reference, resolved by harvesting
	 * every `loadEvent:`/`clickEvent:` literal in the same file.
	 *
	 * @param string $file Absolute JS/TS file path.
	 * @return array<int,string>
	 */
	private function native_js_tracks_wire_names( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading immutable local production source for a scanning assertion.
		$code = (string) file_get_contents( $file );

		$is_legacy_frontend = false !== strpos( str_replace( '\\', '/', $file ), '/client/legacy/js/frontend/woopayments-' );

		$recorders = array(
			'recordEvent'                => array(
				'arg'    => 0,
				'prefix' => 'wcadmin_',
			),
			'recordWooPaymentsUserEvent' => array(
				'arg'    => 1,
				'prefix' => 'wcpay_',
			),
		);
		if ( $is_legacy_frontend ) {
			$recorders['recordUserEvent'] = array(
				'arg'    => 0,
				'prefix' => 'wcpay_',
			);
		}

		$names = array();

		foreach ( $recorders as $function_name => $spec ) {
			foreach ( $this->native_js_find_calls( $code, $function_name ) as $args ) {
				if ( ! isset( $args[ $spec['arg'] ] ) ) {
					continue;
				}

				foreach ( $this->native_js_resolve_argument( $args[ $spec['arg'] ], $code ) as $resolved ) {
					$names[] = $spec['prefix'] . $resolved;
				}
			}
		}

		return $names;
	}

	/**
	 * Find every call to a bare function name in JS/TS source and return each call's top-level
	 * argument strings (bracket- and string-depth aware, so a nested `(`, `,` or quote inside an
	 * argument never mis-splits it).
	 *
	 * @param string $code          Full file source.
	 * @param string $function_name Function name (never preceded by `.` — a method call on some
	 *                              other object is not this recorder).
	 * @return array<int,array<int,string>> One argument-list per call site.
	 */
	private function native_js_find_calls( string $code, string $function_name ): array {
		$calls = array();
		if ( ! preg_match_all( '/(?<![.\w$])' . preg_quote( $function_name, '/' ) . '\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $calls;
		}

		foreach ( $matches[0] as $match ) {
			$open_pos = $match[1] + strlen( $match[0] ) - 1;
			$args     = $this->native_js_call_arguments( $code, $open_pos );
			if ( null !== $args ) {
				$calls[] = $args;
			}
		}

		return $calls;
	}

	/**
	 * Walk a call's argument list once, from the `(` at `$open_pos` to its matching `)`, and
	 * return the top-level argument strings — skipping over nested brackets, quoted strings,
	 * template literals and comments so a nested `(`, `,` or quote inside an argument never
	 * mis-splits or mis-closes it.
	 *
	 * @param string $code     Full file source.
	 * @param int    $open_pos Index of the opening `(`.
	 * @return array<int,string>|null Trimmed top-level argument strings, or null if `)` is never reached.
	 */
	private function native_js_call_arguments( string $code, int $open_pos ): ?array {
		$len     = strlen( $code );
		$depth   = 1;
		$args    = array();
		$current = '';

		for ( $i = $open_pos + 1; $i < $len; $i++ ) {
			$ch = $code[ $i ];

			if ( '(' === $ch || '[' === $ch || '{' === $ch ) {
				++$depth;
				$current .= $ch;
				continue;
			}
			if ( ')' === $ch || ']' === $ch || '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( '' !== trim( $current ) ) {
						$args[] = trim( $current );
					}
					return $args;
				}
				$current .= $ch;
				continue;
			}
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$end      = $this->native_js_skip_string( $code, $i );
				$current .= substr( $code, $i, $end - $i + 1 );
				$i        = $end;
				continue;
			}
			if ( '/' === $ch && '/' === ( $code[ $i + 1 ] ?? '' ) ) {
				$next_newline = strpos( $code, "\n", $i );
				$i            = ( false === $next_newline ) ? $len : $next_newline;
				continue;
			}
			if ( '/' === $ch && '*' === ( $code[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $code, '*/', $i + 2 );
				$i   = ( false === $end ) ? $len : $end + 1;
				continue;
			}
			if ( ',' === $ch && 1 === $depth ) {
				$args[]  = trim( $current );
				$current = '';
				continue;
			}
			$current .= $ch;
		}

		return null; // Never reached a matching `)`.
	}

	/**
	 * Skip a quoted string or template literal starting at `$code[$start]`, returning the index
	 * of its closing quote.
	 *
	 * @param string $code  Full file source.
	 * @param int    $start Index of the opening quote.
	 */
	private function native_js_skip_string( string $code, int $start ): int {
		$quote = $code[ $start ];
		$len   = strlen( $code );
		$i     = $start + 1;

		while ( $i < $len && $code[ $i ] !== $quote ) {
			if ( '\\' === $code[ $i ] ) {
				++$i;
			}
			++$i;
		}

		return min( $i, $len - 1 );
	}

	/**
	 * Resolve one JS/TS call argument to the wire-name suffixes it can produce (0, 1 or 2).
	 *
	 * @param string $argument Trimmed argument text.
	 * @param string $code     Full file source, for cross-referencing a same-file const or map.
	 * @return array<int,string>
	 */
	private function native_js_resolve_argument( string $argument, string $code ): array {
		// A plain string, or a template literal with no interpolation.
		if ( preg_match( '/^([\'"`])((?:\\\\.|(?!\1)[\s\S])*)\1$/', $argument, $m ) && false === strpos( $m[2], '${' ) ) {
			return array( $this->native_js_unescape( $m[2] ) );
		}

		// A template literal over a single interpolated identifier: `${ident}suffix`.
		if ( preg_match( '/^`\$\{\s*([A-Za-z_$][\w$]*)\s*\}([^`$]*)`$/', $argument, $m ) ) {
			$suffix = $m[2];
			return array_map(
				function ( string $branch ) use ( $suffix ) {
					return $branch . $suffix;
				},
				$this->native_js_ternary_branches_for_identifier( $code, $m[1] )
			);
		}

		// A ternary of two literals, whatever the condition expression is.
		if ( preg_match( '/^([\s\S]+?)\?\s*([\'"])((?:\\\\.|(?!\2)[\s\S])*?)\2\s*:\s*([\'"])((?:\\\\.|(?!\4)[\s\S])*?)\4$/', $argument, $m ) ) {
			return array( $this->native_js_unescape( $m[3] ), $this->native_js_unescape( $m[5] ) );
		}

		// `methodConfig.loadEvent` / `.clickEvent`: harvest every such literal in the same file.
		if ( preg_match( '/\.(loadEvent|clickEvent)$/', $argument ) ) {
			return $this->native_js_harvest_property_literals( $code, array( 'loadEvent', 'clickEvent' ) );
		}

		// A bare identifier resolved through a same-file `*_EVENTS` map.
		if ( preg_match( '/^[A-Za-z_$][\w$]*$/', $argument ) ) {
			return $this->native_js_harvest_events_map( $code );
		}

		return array();
	}

	/**
	 * Find `const IDENT = <cond> ? 'A' : 'B';`-shaped ternary branches for the given identifier
	 * anywhere in the file (the identifier is function-local in every native site this proves).
	 *
	 * @param string $code       Full file source.
	 * @param string $identifier Identifier name.
	 * @return array<int,string>
	 */
	private function native_js_ternary_branches_for_identifier( string $code, string $identifier ): array {
		$pattern = '/\b' . preg_quote( $identifier, '/' ) . '\s*=\s*[\s\S]+?\?\s*([\'"])((?:\\\\.|(?!\1)[\s\S])*?)\1\s*:\s*([\'"])((?:\\\\.|(?!\3)[\s\S])*?)\3\s*;/';
		if ( ! preg_match( $pattern, $code, $m ) ) {
			return array();
		}

		return array( $this->native_js_unescape( $m[2] ), $this->native_js_unescape( $m[4] ) );
	}

	/**
	 * Harvest every literal value assigned to any of the given object-property names in the file
	 * (`name: 'literal'`), the way the express-checkout `loadEvent`/`clickEvent` config objects do.
	 *
	 * @param string            $code       Full file source.
	 * @param array<int,string> $properties Property names.
	 * @return array<int,string>
	 */
	private function native_js_harvest_property_literals( string $code, array $properties ): array {
		$values  = array();
		$pattern = '/\b(?:' . implode( '|', array_map( fn( $p ) => preg_quote( $p, '/' ), $properties ) ) . ')\s*:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';

		if ( preg_match_all( $pattern, $code, $matches ) ) {
			foreach ( $matches[2] as $value ) {
				$values[] = $this->native_js_unescape( $value );
			}
		}

		return $values;
	}

	/**
	 * Harvest every literal value of a same-file `const X..._EVENTS: Record<string,string> = {…}`
	 * map (values only, never the keys).
	 *
	 * @param string $code Full file source.
	 * @return array<int,string>
	 */
	private function native_js_harvest_events_map( string $code ): array {
		if ( ! preg_match( '/const\s+[A-Za-z_$][\w$]*_EVENTS\s*(?::[^=]+)?=\s*\{([\s\S]*?)\n\s*\};/', $code, $m ) ) {
			return array();
		}

		$values = array();
		if ( preg_match_all( '/:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', $m[1], $matches ) ) {
			foreach ( $matches[2] as $value ) {
				$values[] = $this->native_js_unescape( $value );
			}
		}

		return $values;
	}

	/**
	 * Unescape a JS single/double/template-quoted string body.
	 *
	 * @param string $value Raw string body (without its surrounding quotes).
	 */
	private function native_js_unescape( string $value ): string {
		return str_replace( array( "\\'", '\\"', '\\`', '\\\\' ), array( "'", '"', '`', '\\' ), $value );
	}
}
