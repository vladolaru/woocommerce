/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewAccountStatus,
	WooPaymentsOverviewShell,
} from '../../overview/types';

/**
 * The Overview shell `:8889` returned on 2026-09-30 for a complete test-drive account, with test-mode onboarding
 * off so every section can render. Tests override the account status fields a state changes.
 *
 * @param accountStatus Account status fields to override.
 * @param overrides     Top-level shell fields to override.
 */
export const createRecordedOverviewShell = (
	accountStatus: Partial< WooPaymentsOverviewAccountStatus > = {},
	overrides: Partial< WooPaymentsOverviewShell > = {}
): WooPaymentsOverviewShell => ( {
	account: {
		id: 'acct_recorded',
		mode: 'test',
		connected: true,
		working: true,
		can_process_payments: true,
		details_submitted: true,
		test_mode: true,
		test_mode_onboarding: false,
		dev_mode: true,
		test_drive: true,
		sandbox: false,
		live: false,
	},
	account_status: {
		status: 'complete',
		current_deadline: null,
		past_due: false,
		account_link: '',
		requirements: { errors: [] },
		details_submitted: true,
		payments_enabled: true,
		deposits_enabled: true,
		...accountStatus,
	},
	show_update_details_task: false,
	overview_tasks_visibility: {
		dismissed_todo_tasks: [],
		deleted_todo_tasks: [],
		remind_me_later_todo_tasks: {},
	},
	is_connection_success_modal_dismissed: false,
	disputes_awaiting_response_count: 0,
	account_details: {
		account_status: { text: 'Connected', background_color: 'green' },
		payout_status: {
			text: 'Active',
			background_color: 'green',
			popover: {
				text: "Your next payout will be sent in 2 days based on your daily schedule. Arrival time depends on your bank's processing speed.",
				cta_text: 'Learn more about payouts',
				cta_link:
					'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#new-accounts',
			},
		},
		banner: null,
	},
	account_fees: [],
	feature_flags: { dispute_readiness_overview: true },
	account_loans: { has_active_loan: false, loans: [] },
	instant_deposits_previously_eligible: false,
	wpcom_reconnect_url: '',
	urls: {
		overview_page:
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview',
		settings:
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings',
		onboarding:
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding',
		setup: 'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding',
	},
	...overrides,
} );
