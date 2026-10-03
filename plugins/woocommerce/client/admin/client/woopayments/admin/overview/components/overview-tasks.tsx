/**
 * External dependencies
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
import moment from 'moment';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewDispute,
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

// Client 11.1.0 `dispute-task.tsx:31-44` and `disputes/utils.ts:46-50`: the cached row's `due_by`, a UTC date string.
const getDisputeDueMoment = ( dispute: WooPaymentsOverviewDispute ) => {
	if ( typeof dispute.due_by !== 'string' || dispute.due_by === '' ) {
		return null;
	}

	const dueMoment = moment.utc( dispute.due_by );

	return dueMoment.isValid() ? dueMoment : null;
};

const getDisputeId = ( dispute: WooPaymentsOverviewDispute ) =>
	dispute.dispute_id ?? '';

const getDisputeChargeId = ( dispute: WooPaymentsOverviewDispute ) =>
	dispute.charge_id ?? '';

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

// Client 11.1.0 `overview/task-list/tasks/reconnect-task.tsx:13-53`.
const buildReconnectTask = (
	wpcomReconnectUrl: string
): WooPaymentsOverviewTask | null => {
	if ( ! wpcomReconnectUrl ) {
		return null;
	}

	return {
		key: 'reconnect-wpcom-user',
		level: 1,
		title: sprintf(
			/* translators: %s: Payment provider name. */
			__( 'Reconnect %s', 'woocommerce' ),
			'WooPayments'
		),
		// The client's expandable task renders this as additional info above the button; native's tasks do not expand, so it goes in the content.
		content: (
			<div className="woocommerce-task__additional-info">
				{ sprintf(
					/* translators: %s: Payment provider name. */
					__(
						'%s is missing a connected WordPress.com account. Some functionality will be limited without a connected account.',
						'woocommerce'
					),
					'WooPayments'
				) }
			</div>
		),
		actionLabel: __( 'Reconnect', 'woocommerce' ),
		href: addQueryArgs( wpcomReconnectUrl, {
			from: 'WCPAY_OVERVIEW',
			source: 'wcpay-reconnect-wpcom-user-task',
		} ),
		onClick: () =>
			recordEvent( 'wcpay_overview_task_click', {
				task: 'reconnect-wpcom',
				source: 'wcpay-reconnect-wpcom-task',
			} ),
		showActionButton: true,
	};
};

// Client 11.1.0 `disputes/utils.ts:41-61` `isDueWithin()`: due within the window and not yet past due.
export const isDisputeDueWithinDays = (
	dispute: WooPaymentsOverviewDispute,
	days: number,
	now = Date.now()
) => {
	const dueMoment = getDisputeDueMoment( dispute );

	if ( ! dueMoment ) {
		return false;
	}

	const nowMoment = moment.utc( now );

	return (
		dueMoment.diff( nowMoment, 'days', true ) <= days &&
		! nowMoment.isAfter( dueMoment )
	);
};

// Client 11.1.0 `overview/task-list/tasks/dispute-task.tsx:35-221` and `tasks.tsx:71-74`.
const buildDisputeTask = (
	disputes: WooPaymentsOverviewDispute[]
): WooPaymentsOverviewTask | null => {
	const activeDisputes = disputes
		.filter( ( dispute ) => getDisputeDueMoment( dispute ) !== null )
		.sort(
			( a, b ) =>
				( getDisputeDueMoment( a )?.valueOf() ?? 0 ) -
				( getDisputeDueMoment( b )?.valueOf() ?? 0 )
		);
	const countDueWithinDays = ( days: number ) =>
		activeDisputes.filter( ( dispute ) =>
			isDisputeDueWithinDays( dispute, days )
		).length;
	const numDisputesDueWithin7Days = countDueWithinDays( 7 );

	if ( numDisputesDueWithin7Days === 0 ) {
		return null;
	}

	const activeDisputeCount = activeDisputes.length;
	const numDisputesDueWithin24h = countDueWithinDays( 1 );
	// Red within 72 hours, yellow before.
	const isUrgent = countDueWithinDays( 3 ) >= 1;
	const onClick = () =>
		recordEvent( 'wcpay_overview_task_click', {
			task: 'dispute-resolution-task',
			active_dispute_count: activeDisputeCount,
		} );

	if ( activeDisputeCount === 1 ) {
		const dispute = activeDisputes[ 0 ];
		const chargeId = getDisputeChargeId( dispute );
		const dueMoment = getDisputeDueMoment( dispute ) as moment.Moment;
		const amountFormatted = formatTaskCurrency(
			dispute.amount ?? 0,
			dispute.currency
		);

		return {
			key: `dispute-resolution-task-${ getDisputeId( dispute ) }`,
			level: 1,
			title:
				numDisputesDueWithin24h >= 1
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
					  ),
			content:
				numDisputesDueWithin24h >= 1
					? sprintf(
							/* translators: %s: Response deadline time in the site timezone, for example "11:59 PM". */
							__( 'Respond today by %s', 'woocommerce' ),
							dateI18n(
								'g:i A',
								dueMoment.toISOString(),
								undefined
							)
					  )
					: sprintf(
							/* translators: 1: Response deadline date in the site date format, 2: Time left, for example "2 days". */
							__(
								'By %1$s – %2$s left to respond',
								'woocommerce'
							),
							dateI18n(
								getDateSettings().formats.date,
								dueMoment.toISOString(),
								undefined
							),
							dueMoment.fromNow( true )
					  ),
			actionLabel: __( 'Respond now', 'woocommerce' ),
			href: getSettingsPaymentsProviderRouteUrl(
				`/woopayments/transactions/details?id=${ encodeURIComponent(
					chargeId
				) }`
			),
			onClick,
			showActionButton: true,
			isUrgent,
		};
	}

	const currencies = Array.from(
		new Set(
			activeDisputes
				.map( ( dispute ) => dispute.currency?.toLowerCase() )
				.filter( Boolean )
		)
	);
	const title =
		currencies.length === 1
			? sprintf(
					/* translators: 1: Number of disputes, 2: Total disputed amount. */
					__(
						'Respond to %1$d active disputes for a total of %2$s',
						'woocommerce'
					),
					activeDisputeCount,
					formatTaskCurrency(
						activeDisputes.reduce(
							( total, dispute ) =>
								total + ( dispute.amount ?? 0 ),
							0
						),
						currencies[ 0 ]
					)
			  )
			: sprintf(
					/* translators: %d: Number of disputes. */
					_n(
						'Respond to %d active dispute',
						'Respond to %d active disputes',
						activeDisputeCount,
						'woocommerce'
					),
					activeDisputeCount
			  );

	return {
		key: `dispute-resolution-task-${ activeDisputes
			.map( getDisputeId )
			.join( '-' ) }`,
		level: 1,
		title,
		content:
			numDisputesDueWithin24h >= 1
				? sprintf(
						/* translators: %d: Number of disputes due within 24 hours. */
						__(
							'Final day to respond to %d of the disputes',
							'woocommerce'
						),
						numDisputesDueWithin24h
				  )
				: sprintf(
						/* translators: %d: Number of disputes due within 7 days. */
						__(
							'Last week to respond to %d of the disputes',
							'woocommerce'
						),
						numDisputesDueWithin7Days
				  ),
		actionLabel: __( 'See disputes', 'woocommerce' ),
		href: getSettingsPaymentsProviderRouteUrl(
			'/woopayments/disputes?filter=awaiting_response'
		),
		onClick,
		showActionButton: true,
		isUrgent,
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
	disputes,
	showUpdateDetailsTask = true,
	onOpenUpdateBusinessDetails,
}: {
	shell: WooPaymentsOverviewShell;
	disputes: WooPaymentsOverviewDispute[];
	showUpdateDetailsTask?: boolean;
	onOpenUpdateBusinessDetails: ( shell: WooPaymentsOverviewShell ) => void;
} ): WooPaymentsOverviewTask[] =>
	[
		showUpdateDetailsTask &&
			buildUpdateBusinessDetailsTask( {
				shell,
				onOpenUpdateBusinessDetails,
			} ),
		buildReconnectTask( shell.wpcom_reconnect_url ),
		buildDisputeTask( disputes ),
		// No go-live task: client 11.1.0 `overview/index.js:105` calls getTasks() without showGoLiveTask, so the
		// task shows only on WC Home (`WooPaymentsGoLiveTask` and `woopayments/home-tasks/go-live-task.tsx`).
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
