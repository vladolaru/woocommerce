/**
 * External dependencies
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
import moment from 'moment';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewDispute,
	WooPaymentsOverviewDisputesSummary,
	WooPaymentsOverviewShell,
	WooPaymentsOverviewTask,
	WooPaymentsOverviewTasksVisibility,
} from '../types';
import {
	formatWooPaymentsAmount,
	getSettingsPaymentsProviderRouteUrl,
} from '../utils';
import {
	formatRequirementDeadline,
	getRequirementErrorMessages,
} from './requirement-error-messages';

export const formatTaskCurrency = ( amount: number, currency?: string ) =>
	formatWooPaymentsAmount( amount, currency );

// Client 11.1.0 `overview/task-list/tasks/update-business-details-task.tsx:18-171`.
const buildUpdateBusinessDetailsTask = ( {
	shell,
	onOpenUpdateBusinessDetails,
}: {
	shell: WooPaymentsOverviewShell;
	onOpenUpdateBusinessDetails: ( shell: WooPaymentsOverviewShell ) => void;
} ): WooPaymentsOverviewTask => {
	const accountStatus = shell.account_status;
	const { status, details_submitted: detailsSubmitted } = accountStatus;
	const currentDeadline = accountStatus.current_deadline;
	const errorMessages = getRequirementErrorMessages(
		accountStatus.requirements?.errors
	);
	const hasMultipleErrors = errorMessages.length > 1;
	const hasSingleError = errorMessages.length === 1;
	const completed = status === 'complete' || status === 'enabled';
	let content: ReactNode = '';

	if ( status === 'restricted_soon' && currentDeadline ) {
		const updateBy = sprintf(
			/* translators: %s: Formatted requirements deadline, for example "5pm Oct 5, 2026". */
			__(
				'Update by %s to avoid a disruption in payouts.',
				'woocommerce'
			),
			formatRequirementDeadline( currentDeadline )
		);
		const [ errorMessage ] = errorMessages;

		if ( ! hasSingleError ) {
			content = updateBy;
		} else if ( typeof errorMessage === 'string' ) {
			content = `${ errorMessage } ${ updateBy }`;
		} else {
			content = (
				<>
					{ errorMessage } { updateBy }
				</>
			);
		}
	} else if ( status === 'restricted' && accountStatus.past_due ) {
		if ( hasSingleError ) {
			content = errorMessages[ 0 ];
		} else if ( ! detailsSubmitted ) {
			content = __(
				'Payments and payouts are disabled for this account until setup is completed.',
				'woocommerce'
			);
		} else {
			content = __(
				'Payments and payouts are disabled for this account until missing business information is updated.',
				'woocommerce'
			);
		}
	}

	let actionLabel: string = __( 'Update', 'woocommerce' );
	if ( hasMultipleErrors ) {
		actionLabel = __( 'More details', 'woocommerce' );
	} else if ( ! detailsSubmitted ) {
		actionLabel = __( 'Finish setup', 'woocommerce' );
	}

	const task: WooPaymentsOverviewTask = {
		key: detailsSubmitted ? 'update-business-details' : 'complete-setup',
		level: 1,
		title: detailsSubmitted
			? sprintf(
					/* translators: %s: Payment provider name. */
					__( 'Update %s business details', 'woocommerce' ),
					'WooPayments'
				)
			: sprintf(
					/* translators: %s: Payment provider name. */
					__( 'Finish setting up %s', 'woocommerce' ),
					'WooPayments'
				),
		content,
		actionLabel,
		completed,
		showActionButton: true,
	};

	// The client ignores clicks on a complete or enabled account.
	if ( completed ) {
		return { ...task, onClick: () => undefined };
	}

	if ( ! hasMultipleErrors && ! detailsSubmitted ) {
		return {
			...task,
			href: getSettingsPaymentsProviderRouteUrl(
				'/woopayments/onboarding?source=wcpay-finish-setup-task&from=WCPAY_OVERVIEW'
			),
			onClick: () =>
				recordEvent( 'wcpay_account_details_link_clicked', {
					source: 'wcpay-finish-setup-task',
				} ),
		};
	}

	if ( hasMultipleErrors ) {
		return { ...task, onClick: () => onOpenUpdateBusinessDetails( shell ) };
	}

	// Test-drive accounts have no account link; the client still opens a blank tab there, native opens nothing (N-194).
	const accountLink = accountStatus.account_link;
	const accountLinkWithSource = accountLink
		? addQueryArgs( accountLink, {
				from: 'WCPAY_OVERVIEW',
				source: 'wcpay-update-business-details-task',
			} )
		: '';

	return {
		...task,
		onClick: () => {
			recordEvent( 'wcpay_account_details_link_clicked', {
				source: 'wcpay-update-business-details-task',
			} );
			if ( accountLinkWithSource ) {
				window.open( accountLinkWithSource, '_blank' );
			}
		},
	};
};

// Client 11.2.0 `disputes/utils.ts:45-65` `isDueWithin()`: due within the window and not yet past due.
const isDueWithinDays = ( dueMoment: moment.Moment, days: number ) => {
	const nowMoment = moment.utc();

	return (
		dueMoment.diff( nowMoment, 'days', true ) <= days &&
		! nowMoment.isAfter( dueMoment )
	);
};

// A date in the site timezone, as client 11.2.0 `utils/date-time.ts` `formatDateTimeFromString()` shows a UTC date string.
const formatSiteDate = ( format: string, dateMoment: moment.Moment ) =>
	dateI18n( format, dateMoment.toISOString(), undefined );

// Client 11.2.0 `overview/task-list/tasks/dispute-task.tsx:19-176`, from the awaiting-response disputes summary and, for a
// single dispute, its row.
const buildDisputeTask = (
	summary: WooPaymentsOverviewDisputesSummary | null,
	activeDispute?: WooPaymentsOverviewDispute
): WooPaymentsOverviewTask | null => {
	const activeDisputeCount = summary?.count ?? 0;
	const dueMoment = summary?.earliest_due_by
		? moment.utc( summary.earliest_due_by )
		: null;
	const isPastDue = !! dueMoment && dueMoment.isBefore( moment.utc() );

	if (
		activeDisputeCount === 0 ||
		! dueMoment ||
		( ! isPastDue && ! isDueWithinDays( dueMoment, 7 ) )
	) {
		return null;
	}

	// The platform sends `[]` for no amounts (a PHP empty array).
	const amounts = summary?.amount_by_currency;
	const amountEntries =
		amounts && ! Array.isArray( amounts )
			? Object.entries( amounts ).filter(
					( [ currency, amount ] ) =>
						currency !== '' &&
						typeof amount === 'number' &&
						Number.isFinite( amount )
				)
			: [];
	const chargeId = activeDispute?.charge_id ?? '';
	const canOpenSingleDispute = activeDisputeCount === 1 && !! chargeId;
	// "Last day" means due today in the site timezone, by calendar day.
	const isDueToday =
		! isPastDue &&
		formatSiteDate( 'Y-m-d', dueMoment ) ===
			formatSiteDate( 'Y-m-d', moment.utc() );

	let title: string;
	if ( activeDisputeCount === 1 && amountEntries.length === 1 ) {
		const amountFormatted = formatTaskCurrency(
			amountEntries[ 0 ][ 1 ],
			amountEntries[ 0 ][ 0 ]
		);

		title = isDueToday
			? sprintf(
					/* translators: %s: Disputed amount. */
					__(
						'Respond to a dispute for %s – Last day',
						'woocommerce'
					),
					amountFormatted
				)
			: sprintf(
					/* translators: %s: Disputed amount. */
					__( 'Respond to a dispute for %s', 'woocommerce' ),
					amountFormatted
				);
	} else if ( activeDisputeCount === 1 ) {
		title = isDueToday
			? __( 'Respond to an active dispute – Last day', 'woocommerce' )
			: __( 'Respond to an active dispute', 'woocommerce' );
	} else if ( amountEntries.length === 1 ) {
		title = sprintf(
			/* translators: 1: Number of disputes, 2: Total disputed amount. */
			__(
				'Respond to %1$d active disputes for a total of %2$s',
				'woocommerce'
			),
			activeDisputeCount,
			formatTaskCurrency(
				amountEntries[ 0 ][ 1 ],
				amountEntries[ 0 ][ 0 ]
			)
		);
	} else {
		title = sprintf(
			/* translators: %d: Number of disputes. */
			__( 'Respond to %d active disputes', 'woocommerce' ),
			activeDisputeCount
		);
	}

	let content: string;
	if ( isPastDue ) {
		content = __( 'Response overdue', 'woocommerce' );
	} else if ( isDueToday ) {
		content = sprintf(
			/* translators: %s: Response deadline time in the site timezone, for example "11:59 PM". */
			__( 'Respond today by %s', 'woocommerce' ),
			formatSiteDate( 'g:i A', dueMoment )
		);
	} else {
		content = sprintf(
			/* translators: 1: Response deadline date in the site date format, 2: Time left, for example "2 days". */
			__( 'By %1$s – %2$s left to respond', 'woocommerce' ),
			formatSiteDate( getDateSettings().formats.date, dueMoment ),
			dueMoment.fromNow( true )
		);
	}

	return {
		key: `dispute-resolution-task-${
			activeDispute?.dispute_id ?? 'summary'
		}`,
		level: 1,
		title,
		content,
		actionLabel: canOpenSingleDispute
			? __( 'Respond now', 'woocommerce' )
			: __( 'See disputes', 'woocommerce' ),
		href: getSettingsPaymentsProviderRouteUrl(
			canOpenSingleDispute
				? `/woopayments/transactions/details?id=${ encodeURIComponent(
						chargeId
					) }`
				: '/woopayments/disputes?filter=awaiting_response'
		),
		onClick: () =>
			recordEvent( 'wcpay_overview_task_click', {
				task: 'dispute-resolution-task',
				active_dispute_count: activeDisputeCount,
			} ),
		showActionButton: true,
		// Red when overdue or due within 72 hours, yellow before.
		isUrgent: isPastDue || isDueWithinDays( dueMoment, 3 ),
	};
};

// Client 11.1.0 `task-list/tasks.tsx:99-110` `taskSort()`, applied in `overview/index.js:105-111`: completed tasks last, then by level.
const compareTasks = (
	a: WooPaymentsOverviewTask,
	b: WooPaymentsOverviewTask
) => {
	if ( !! a.completed !== !! b.completed ) {
		return a.completed ? 1 : -1;
	}

	return ( a.level || 3 ) - ( b.level || 3 );
};

export const buildOverviewTasks = ( {
	shell,
	disputesSummary,
	activeDispute,
	showUpdateDetailsTask = true,
	onOpenUpdateBusinessDetails,
}: {
	shell: WooPaymentsOverviewShell;
	disputesSummary: WooPaymentsOverviewDisputesSummary | null;
	activeDispute?: WooPaymentsOverviewDispute;
	showUpdateDetailsTask?: boolean;
	onOpenUpdateBusinessDetails: ( shell: WooPaymentsOverviewShell ) => void;
} ): WooPaymentsOverviewTask[] =>
	[
		showUpdateDetailsTask &&
			buildUpdateBusinessDetailsTask( {
				shell,
				onOpenUpdateBusinessDetails,
			} ),
		buildDisputeTask( disputesSummary, activeDispute ),
		// No go-live task: client 11.1.0 `overview/index.js:105` calls getTasks() without showGoLiveTask, so the
		// task shows only on WC Home (`GoLiveTask` and `woopayments/home-tasks/go-live-task.tsx`).
	]
		.filter( ( task ): task is WooPaymentsOverviewTask => !! task )
		.sort( compareTasks );

// Native once let merchants dismiss or snooze these tasks, which the client never allows, so stored entries for them are ignored.
const ALWAYS_VISIBLE_TASK_KEYS = [
	'update-business-details',
	'complete-setup',
];

export const getVisibleOverviewTasks = (
	tasks: WooPaymentsOverviewTask[],
	visibility: WooPaymentsOverviewTasksVisibility,
	now = Date.now()
) =>
	tasks.filter(
		( task ) =>
			ALWAYS_VISIBLE_TASK_KEYS.includes( task.key ) ||
			( ! visibility.deleted_todo_tasks.includes( task.key ) &&
				! visibility.dismissed_todo_tasks.includes( task.key ) &&
				( ! visibility.remind_me_later_todo_tasks[ task.key ] ||
					visibility.remind_me_later_todo_tasks[ task.key ] < now ) )
	);
