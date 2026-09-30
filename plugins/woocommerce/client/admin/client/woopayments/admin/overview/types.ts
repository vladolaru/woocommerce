/**
 * External dependencies
 */
import type { ReactNode } from 'react';

export type WooPaymentsDepositStatus =
	| 'paid'
	| 'pending'
	| 'in_transit'
	| 'canceled'
	| 'failed';

export interface WooPaymentsMoneyAmount {
	amount: number;
	currency: string;
	source_types?: Record< string, number >;
}

export interface WooPaymentsInstantBalance {
	amount: number;
	currency: string;
	fee: number;
	net: number;
	fee_percentage: number;
}

export interface WooPaymentsDepositSchedule {
	delay_days?: number;
	interval?: 'manual' | 'daily' | 'weekly' | 'monthly';
	weekly_anchor?: string;
	monthly_anchor?: number;
}

export interface WooPaymentsExternalAccount {
	currency: string;
	status: string;
}

export interface WooPaymentsDepositsAccount {
	default_currency: string;
	account_link?: string | false;
	deposits_enabled?: boolean;
	deposits_blocked?: boolean;
	deposits_disabled?: boolean;
	deposits_schedule?: WooPaymentsDepositSchedule;
	default_external_accounts?: WooPaymentsExternalAccount[];
}

export interface WooPaymentsDeposit {
	id: string;
	date: number | string;
	type: string;
	amount: number;
	status: WooPaymentsDepositStatus | string;
	bankAccount?: string | null;
	bank_reference_key?: string | null;
	currency: string | null;
	automatic?: boolean;
	fee?: number;
	fee_percentage?: number;
	created?: number;
	failure_code?: string;
	failure_message?: string;
}

export interface WooPaymentsDepositsOverview {
	deposit?: {
		last_paid?: WooPaymentsDeposit[];
	};
	balance?: {
		pending?: WooPaymentsMoneyAmount[];
		available?: WooPaymentsMoneyAmount[];
		instant?: WooPaymentsInstantBalance[];
	};
	account: WooPaymentsDepositsAccount;
}

export interface WooPaymentsDepositsListResponse {
	data: WooPaymentsDeposit[];
	total_count: number;
}

export interface WooPaymentsDepositsSummary {
	store_currencies?: string[];
	count?: number;
	total?: number;
	currency?: string;
}

export interface WooPaymentsDepositsQuery {
	page?: number | string;
	pagesize?: number | string;
	sort?: string;
	direction?: 'asc' | 'desc' | string;
	match?: string;
	store_currency_is?: string;
	date_before?: string;
	date_after?: string;
	date_between?: string;
	status_is?: string;
	status_is_not?: string;
}

export interface WooPaymentsOverviewAccount {
	id: string;
	mode: string;
	connected: boolean;
	working: boolean;
	can_process_payments: boolean;
	details_submitted: boolean;
	test_mode: boolean;
	test_mode_onboarding: boolean;
	dev_mode: boolean;
	test_drive: boolean;
	sandbox: boolean;
	live: boolean;
}

export interface WooPaymentsOverviewRequirementError {
	code?: string;
	reason?: string;
	requirement?: string;
}

/** The cached account's payout fields, as client 11.1.0 reads `wcpaySettings.accountStatus.deposits`. */
export interface WooPaymentsOverviewAccountDeposits {
	restrictions: string;
	completed_waiting_period: boolean;
	minimum_scheduled_deposit_amounts: Record< string, number >;
}

export interface WooPaymentsOverviewAccountStatus {
	status: string;
	current_deadline: number | null;
	past_due: boolean;
	account_link: string;
	requirements: {
		errors?: WooPaymentsOverviewRequirementError[];
	};
	details_submitted: boolean;
	payments_enabled: boolean;
	deposits_enabled: boolean;
	deposits?: WooPaymentsOverviewAccountDeposits;
}

export interface WooPaymentsOverviewTasksVisibility {
	dismissed_todo_tasks: string[];
	deleted_todo_tasks: string[];
	remind_me_later_todo_tasks: Record< string, number >;
}

export interface WooPaymentsOverviewAccountDetailsStatus {
	text?: string;
	background_color?: string;
	popover?: {
		text?: string;
		cta_text?: string;
		cta_link?: string;
	};
	[ key: string ]: unknown;
}

export interface WooPaymentsOverviewAccountDetailsBanner {
	text?: string;
	background_color?: string;
	icon?: string;
	cta_text?: string;
	cta_link?: string;
	[ key: string ]: unknown;
}

export interface WooPaymentsOverviewAccountDetails {
	account_status: WooPaymentsOverviewAccountDetailsStatus;
	payout_status: WooPaymentsOverviewAccountDetailsStatus;
	banner?: WooPaymentsOverviewAccountDetailsBanner | null;
}

export interface WooPaymentsOverviewAccountFee {
	payment_method: string;
	fee: {
		base?: Record< string, unknown >;
		discount?: Record< string, unknown >[];
		[ key: string ]: unknown;
	};
}

export interface WooPaymentsOverviewShell {
	account: WooPaymentsOverviewAccount;
	account_status: WooPaymentsOverviewAccountStatus;
	show_update_details_task: boolean;
	overview_tasks_visibility: WooPaymentsOverviewTasksVisibility;
	is_connection_success_modal_dismissed: boolean;
	disputes_awaiting_response_count: number | null;
	account_details?: WooPaymentsOverviewAccountDetails | null;
	account_fees?: WooPaymentsOverviewAccountFee[];
	feature_flags?: {
		dispute_readiness_overview?: boolean;
	};
	account_loans?: {
		has_active_loan?: boolean;
		/** `<loan id>|<status>` strings, as client 11.1.0 `wcpaySettings.accountLoans.loans`. */
		loans?: string[];
	};
	instant_deposits_previously_eligible?: boolean;
	wpcom_reconnect_url: string;
	urls: {
		overview_page?: string;
		settings?: string;
		onboarding?: string;
		setup?: string;
	};
}

/** A cached dispute row from `wc/v3/payments/disputes`, as client 11.1.0 `types/disputes.d.ts` `CachedDispute` reads it. */
export interface WooPaymentsOverviewDispute {
	dispute_id?: string;
	charge_id?: string;
	amount?: number;
	currency?: string;
	/** UTC `Y-m-d H:i:s`. */
	due_by?: string;
}

export interface WooPaymentsOverviewDisputesResponse {
	data?: WooPaymentsOverviewDispute[];
	total_count?: number;
}

export interface WooPaymentsAccountSession {
	clientSecret?: string;
	expiresAt?: number;
	accountId?: string;
	isLive?: boolean;
	publishableKey?: string;
	locale?: string;
}

export type WooPaymentsDisputeReadinessSignalStatus = 'complete' | 'incomplete';

export interface WooPaymentsDisputeReadinessSignal {
	id: string;
	status: WooPaymentsDisputeReadinessSignalStatus;
	label: string;
	description?: string;
	actionLabel?: string;
	actionUrl?: string;
	reason?: string;
	reviewPrompt?: {
		text: string;
		currentDescriptor: string;
		confirmLabel: string;
		updateLabel: string;
	};
}

export interface WooPaymentsDisputeReadinessPayload {
	overview?: {
		enabled: boolean;
		hidden?: boolean;
		score?: number;
		total?: number;
		state?: WooPaymentsDisputeReadinessSignalStatus;
		isDismissed?: boolean;
		completeSignalIds?: string[];
		incompleteSignalIds?: string[];
		signals?: WooPaymentsDisputeReadinessSignal[];
	};
}

export interface WooPaymentsOverviewTask {
	key: string;
	title: string;
	content?: ReactNode;
	additionalInfo?: ReactNode;
	actionLabel?: string;
	href?: string;
	onClick?: () => void;
	completed?: boolean;
	level?: number;
	showActionButton?: boolean;
	isDismissable?: boolean;
	isDeletable?: boolean;
	allowSnooze?: boolean;
	/** Client 11.1.0 `dispute-task.tsx` `data-urgent`: a dispute is due within 72 hours. */
	isUrgent?: boolean;
}
