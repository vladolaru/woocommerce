/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
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

const DAY_IN_MS = 24 * 60 * 60 * 1000;

export const formatTaskCurrency = ( amount: number, currency?: string ) =>
	formatWooPaymentsAmount( amount, currency );

const normalizeTimestamp = ( value?: number | string | null ) => {
	if ( value === undefined || value === null || value === '' ) {
		return null;
	}

	if ( typeof value === 'number' ) {
		return value < 10000000000 ? value * 1000 : value;
	}

	const numericValue = Number( value );
	if ( Number.isFinite( numericValue ) ) {
		return numericValue < 10000000000 ? numericValue * 1000 : numericValue;
	}

	const parsedValue = new Date( value ).getTime();

	return Number.isNaN( parsedValue ) ? null : parsedValue;
};

const formatTaskDate = ( value?: number | string | null ) => {
	const timestamp = normalizeTimestamp( value );

	if ( timestamp === null ) {
		return '';
	}

	return new Date( timestamp ).toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
};

const getDisputeDueTimestamp = ( dispute: WooPaymentsOverviewDispute ) =>
	normalizeTimestamp(
		dispute.evidence_due_by ??
			dispute.evidence_details?.due_by ??
			dispute.due_by
	);

const getDisputeId = ( dispute: WooPaymentsOverviewDispute ) =>
	dispute.dispute_id || dispute.id || '';

const getDisputeChargeId = ( dispute: WooPaymentsOverviewDispute ) => {
	if ( typeof dispute.charge === 'string' ) {
		return dispute.charge;
	}

	return dispute.charge_id || dispute.charge?.id || dispute.id || '';
};

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

	// Like the client, an empty link (test-drive accounts) still opens a blank tab.
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
			window.open( accountLinkWithSource, '_blank' );
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
		additionalInfo: sprintf(
			/* translators: %s: Payment provider name. */
			__(
				'%s is missing a connected WordPress.com account. Some functionality will be limited without a connected account.',
				'woocommerce'
			),
			'WooPayments'
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

export const isDisputeDueWithinDays = (
	dispute: WooPaymentsOverviewDispute,
	days: number,
	now = Date.now()
) => {
	const dueTimestamp = getDisputeDueTimestamp( dispute );

	return dueTimestamp !== null && dueTimestamp <= now + days * DAY_IN_MS;
};

const buildDisputeTask = (
	disputes: WooPaymentsOverviewDispute[]
): WooPaymentsOverviewTask | null => {
	const urgentDisputes = disputes
		.filter( ( dispute ) => isDisputeDueWithinDays( dispute, 7 ) )
		.sort(
			( a, b ) =>
				( getDisputeDueTimestamp( a ) ?? 0 ) -
				( getDisputeDueTimestamp( b ) ?? 0 )
		);

	if ( urgentDisputes.length === 0 ) {
		return null;
	}

	// Client 11.1.0 `overview/task-list/tasks/dispute-task.tsx:52-56`.
	const onClick = () =>
		recordEvent( 'wcpay_overview_task_click', {
			task: 'dispute-resolution-task',
			active_dispute_count: urgentDisputes.length,
		} );

	if ( urgentDisputes.length === 1 ) {
		const dispute = urgentDisputes[ 0 ];
		const chargeId = getDisputeChargeId( dispute );

		return {
			key: `dispute-resolution-task-${ getDisputeId( dispute ) }`,
			level: 1,
			title: sprintf(
				/* translators: %s: Disputed amount. */
				__( 'Respond to a dispute for %s', 'woocommerce' ),
				formatTaskCurrency( dispute.amount ?? 0, dispute.currency )
			),
			content: sprintf(
				/* translators: %s: Dispute response deadline. */
				__( 'Respond by %s.', 'woocommerce' ),
				formatTaskDate( getDisputeDueTimestamp( dispute ) )
			),
			actionLabel: __( 'Respond now', 'woocommerce' ),
			href: getSettingsPaymentsProviderRouteUrl(
				`/woopayments/transactions/details?id=${ encodeURIComponent(
					chargeId
				) }`
			),
			onClick,
			showActionButton: true,
		};
	}

	const currencies = Array.from(
		new Set(
			urgentDisputes
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
					urgentDisputes.length,
					formatTaskCurrency(
						urgentDisputes.reduce(
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
						urgentDisputes.length,
						'woocommerce'
					),
					urgentDisputes.length
			  );

	return {
		key: `dispute-resolution-task-${ urgentDisputes
			.map( getDisputeId )
			.join( '-' ) }`,
		level: 1,
		title,
		content: sprintf(
			/* translators: %d: Number of disputes due soon. */
			_n(
				'Last week to respond to %d dispute.',
				'Last week to respond to %d disputes.',
				urgentDisputes.length,
				'woocommerce'
			),
			urgentDisputes.length
		),
		actionLabel: __( 'See disputes', 'woocommerce' ),
		href: getSettingsPaymentsProviderRouteUrl(
			'/woopayments/disputes?filter=awaiting_response'
		),
		onClick,
		showActionButton: true,
	};
};

const buildGoLiveTask = ( {
	shell,
	onActivatePayments,
}: {
	shell: WooPaymentsOverviewShell;
	onActivatePayments: () => void;
} ): WooPaymentsOverviewTask | null => {
	if (
		! shell.account.connected ||
		shell.account.live ||
		shell.account.dev_mode ||
		! ( shell.account.test_drive || shell.account.test_mode_onboarding )
	) {
		return null;
	}

	// Client 11.1.0 `overview/task-list/tasks/go-live-task.tsx:29-33`.
	const onClick = () => {
		recordEvent( 'wcpay_overview_task_click', {
			task: 'go-live',
			source: 'wcpay-go-live-task',
		} );
		onActivatePayments();
	};

	return {
		key: 'go-live-payments',
		level: 3,
		title: __( 'Activate payments', 'woocommerce' ),
		content: __( '10 minutes', 'woocommerce' ),
		onClick,
		showActionButton: false,
	};
};

export const buildOverviewTasks = ( {
	shell,
	disputes,
	showUpdateDetailsTask = true,
	onOpenUpdateBusinessDetails,
	onActivatePayments,
}: {
	shell: WooPaymentsOverviewShell;
	disputes: WooPaymentsOverviewDispute[];
	showUpdateDetailsTask?: boolean;
	onOpenUpdateBusinessDetails: ( shell: WooPaymentsOverviewShell ) => void;
	onActivatePayments: () => void;
} ): WooPaymentsOverviewTask[] =>
	[
		showUpdateDetailsTask &&
			buildUpdateBusinessDetailsTask( {
				shell,
				onOpenUpdateBusinessDetails,
			} ),
		buildReconnectTask( shell.wpcom_reconnect_url ),
		buildDisputeTask( disputes ),
		buildGoLiveTask( { shell, onActivatePayments } ),
	].filter( Boolean ) as WooPaymentsOverviewTask[];

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
