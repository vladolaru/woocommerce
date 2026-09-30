/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	ExternalLink,
	Icon,
	Notice,
} from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { calendar } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
import moment from 'moment';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsDeposit, WooPaymentsDepositsOverview } from '../types';
import {
	formatPayoutSiteDate,
	formatWooPaymentsAmount,
	getAmountForCurrency,
	getMonthlyAnchorLabel,
	getSelectedBalanceCurrency,
} from '../utils';
import { getSettingsPaymentsProviderRouteUrl } from '../../utils';
import { getPayoutStatusLabel } from '../../payout-status';
import { formatExplicitCurrency } from '../../currency';
import { HelpPopover } from './help-popover';
import { StatusChip, type StatusChipType } from './status-chip';

const PAYOUT_SCHEDULE_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/';
const SUSPENDED_PAYOUTS_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/why-payouts-suspended/';
const NEW_ACCOUNT_WAITING_PERIOD_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#new-accounts';
const NEGATIVE_BALANCE_DOCS_URL =
	'https://woocommerce.com/document/woopayments/fees/account-showing-negative-balance/';
const MINIMUM_PAYOUT_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#minimum-payout-amounts';
const PAYOUTS_HEADING_ID = 'woocommerce-woopayments-payouts-heading';
const PENDING_FUNDS_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#pending-funds';

// Client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:47-51`: the English anchor names the day, moment's current locale (the site's, from WordPress) names it on screen.
const formatScheduleAnchor = ( weeklyAnchor: string ) =>
	moment()
		.locale( 'en' )
		.day( weeklyAnchor )
		.locale( moment.locale() )
		.format( 'dddd' );

// Client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:29-104`.
const getScheduleText = ( overview: WooPaymentsDepositsOverview ) => {
	const schedule = overview.account.deposits_schedule;
	const interval = schedule?.interval;
	let message = '';

	if ( ! interval || interval === 'manual' ) {
		return null;
	}

	if ( interval === 'daily' ) {
		message = __(
			'Available funds are automatically dispatched <strong>every day</strong>.',
			'woocommerce'
		);
	} else if ( interval === 'weekly' && schedule.weekly_anchor ) {
		message = sprintf(
			/* translators: %s: Day of the week. */
			__(
				'Available funds are automatically dispatched <strong>every %s</strong>.',
				'woocommerce'
			),
			formatScheduleAnchor( schedule.weekly_anchor )
		);
	} else if ( interval === 'monthly' && schedule.monthly_anchor ) {
		message =
			schedule.monthly_anchor === 31
				? __(
						'Available funds are automatically dispatched <strong>on the last day of every month</strong>.',
						'woocommerce'
				  )
				: sprintf(
						/* translators: %s: Day of the month. */
						__(
							'Available funds are automatically dispatched <strong>on the %s of every month</strong>.',
							'woocommerce'
						),
						getMonthlyAnchorLabel( schedule.monthly_anchor )
				  );
	}

	return message
		? createInterpolateElement( message, { strong: <strong /> } )
		: null;
};

const PayoutNotice = ( { children }: { children: ReactNode } ) => (
	<Notice status="warning" isDismissible={ false }>
		{ children }
	</Notice>
);

// Client 11.1.0 `components/deposit-status-chip/index.tsx:18-24`.
const PAYOUT_STATUS_CHIP_TYPES: Record< string, StatusChipType > = {
	pending: 'warning',
	in_transit: 'primary',
	paid: 'success',
	failed: 'error',
	canceled: 'info',
};

const FailedPayoutNotice = ( { accountLink }: { accountLink?: string } ) => {
	const accountLinkWithSource = accountLink
		? addQueryArgs( accountLink, {
				from: 'WCPAY_PAYOUTS',
				source: 'wcpay-payout-failure-notice',
		  } )
		: '';

	return (
		<PayoutNotice>
			{ __(
				'Payouts are currently paused because a recent payout failed. Please',
				'woocommerce'
			) }{ ' ' }
			{ accountLinkWithSource ? (
				<a
					href={ accountLinkWithSource }
					onClick={ () =>
						recordEvent( 'wcpay_account_details_link_clicked', {
							from: 'WCPAY_PAYOUTS',
							source: 'wcpay-payout-failure-notice',
						} )
					}
				>
					{ __( 'update your bank account details', 'woocommerce' ) }
				</a>
			) : (
				__( 'Update your bank account details.', 'woocommerce' )
			) }
			{ accountLinkWithSource ? '.' : '' }
		</PayoutNotice>
	);
};

// Client 11.1.0 `components/deposits-overview/recent-deposits-list.tsx`.
const RecentPayoutsList = ( {
	payouts,
}: {
	payouts: WooPaymentsDeposit[];
} ) => (
	<table className="woocommerce-woopayments-overview__payouts-table">
		<thead>
			<tr>
				<th scope="col">{ __( 'Dispatch date', 'woocommerce' ) }</th>
				<th scope="col">{ __( 'Status', 'woocommerce' ) }</th>
				<th scope="col">{ __( 'Amount', 'woocommerce' ) }</th>
			</tr>
		</thead>
		<tbody>
			{ payouts.map( ( payout ) => (
				<tr key={ payout.id }>
					<td>
						<Icon icon={ calendar } size={ 17 } />
						<a
							href={ getSettingsPaymentsProviderRouteUrl(
								`/woopayments/payouts/details?id=${ encodeURIComponent(
									payout.id
								) }`
							) }
							aria-label={ sprintf(
								/* translators: %s: Payout ID. */
								__( 'View payout %s details', 'woocommerce' ),
								payout.id
							) }
						>
							{ formatPayoutSiteDate( payout ) }
						</a>
					</td>
					<td>
						<StatusChip
							message={ getPayoutStatusLabel( payout ) }
							type={
								PAYOUT_STATUS_CHIP_TYPES[ payout.status ] ??
								'info'
							}
						/>
					</td>
					<td>
						{ formatWooPaymentsAmount(
							payout.amount,
							payout.currency
						) }
					</td>
				</tr>
			) ) }
		</tbody>
	</table>
);

// Client 11.1.0 `components/overview-card`. The history keeps its place in every state, so its status region stays mounted from loading to loaded.
const PayoutsCard = ( {
	isLoading,
	summary,
	history,
	footer,
}: {
	isLoading: boolean;
	summary?: ReactNode;
	history: ReactNode;
	footer?: ReactNode;
} ) => (
	<Card
		as="section"
		className="woocommerce-woopayments-overview__payouts-card"
		aria-labelledby={ PAYOUTS_HEADING_ID }
		aria-busy={ isLoading }
	>
		<CardHeader>
			<h2
				id={ PAYOUTS_HEADING_ID }
				className="woocommerce-woopayments-overview-card__title"
			>
				{ __( 'Payouts', 'woocommerce' ) }
			</h2>
		</CardHeader>
		{ summary }
		{ history }
		{ footer }
	</Card>
);

export const PayoutsOverviewCard = ( {
	isLoading,
	errorMessage,
	overview,
	recentPayouts,
	selectedCurrency,
}: {
	isLoading: boolean;
	errorMessage: string | null;
	overview: WooPaymentsDepositsOverview | null;
	recentPayouts: WooPaymentsDeposit[];
	selectedCurrency?: string;
} ) => {
	const historyUrl = getSettingsPaymentsProviderRouteUrl(
		'/woopayments/payouts'
	);
	const hasRecentPayouts =
		! isLoading && ! errorMessage && recentPayouts.length > 0;
	let historyStatusMessage: string = __(
		'No recent payouts.',
		'woocommerce'
	);

	if ( isLoading ) {
		historyStatusMessage = __( 'Loading payouts…', 'woocommerce' );
	} else if ( errorMessage ) {
		historyStatusMessage = errorMessage;
	} else if ( hasRecentPayouts ) {
		historyStatusMessage = __( 'Payout history loaded.', 'woocommerce' );
	}

	const history = (
		<CardBody className="woocommerce-woopayments-overview__history">
			<h3>{ __( 'Payout history', 'woocommerce' ) }</h3>
			<p
				className={
					hasRecentPayouts
						? 'screen-reader-text'
						: 'woocommerce-woopayments-overview__status'
				}
				role={ errorMessage ? 'alert' : 'status' }
				aria-live={ errorMessage ? 'assertive' : 'polite' }
			>
				{ historyStatusMessage }
			</p>
			{ hasRecentPayouts && (
				<RecentPayoutsList payouts={ recentPayouts } />
			) }
		</CardBody>
	);
	const renderFooter = ( canChangePayoutSchedule: boolean ) =>
		( hasRecentPayouts || canChangePayoutSchedule ) && (
			<CardFooter className="woocommerce-woopayments-overview__payouts-footer">
				{ hasRecentPayouts && (
					<Button
						variant="secondary"
						href={ historyUrl }
						onClick={ () =>
							recordEvent(
								'wcpay_overview_deposits_view_history_click'
							)
						}
						__next40pxDefaultSize
					>
						{ __( 'View full payout history', 'woocommerce' ) }
					</Button>
				) }
				{ canChangePayoutSchedule && (
					<Button
						variant="tertiary"
						href={ `${ getSettingsPaymentsProviderRouteUrl(
							'/woopayments/settings'
						) }#payout-schedule` }
						onClick={ () =>
							recordEvent(
								'wcpay_overview_deposits_change_schedule_click'
							)
						}
						__next40pxDefaultSize
					>
						{ __( 'Change payout schedule', 'woocommerce' ) }
					</Button>
				) }
			</CardFooter>
		);

	if ( isLoading ) {
		return <PayoutsCard isLoading history={ history } />;
	}

	if ( ! overview ) {
		if ( ! errorMessage && recentPayouts.length === 0 ) {
			return null;
		}

		return (
			<PayoutsCard
				isLoading={ false }
				history={ history }
				footer={ renderFooter( false ) }
			/>
		);
	}

	const currency = getSelectedBalanceCurrency( overview, selectedCurrency );
	const availableFunds = getAmountForCurrency(
		overview.balance?.available,
		currency
	);
	const pendingFunds = getAmountForCurrency(
		overview.balance?.pending,
		currency
	);
	const totalFunds = availableFunds + pendingFunds;
	const hasCompletedWaitingPeriod =
		overview.account.completed_waiting_period ?? true;
	const isPayoutsSuspended =
		overview.account.deposits_blocked ||
		overview.account.deposits_enabled === false ||
		overview.account.deposits_disabled;
	const minimumPayoutAmount =
		overview.account.minimum_scheduled_deposit_amounts?.[
			currency.toLowerCase()
		] ?? 0;
	const isBelowMinimumPayout =
		availableFunds > 0 &&
		minimumPayoutAmount > 0 &&
		availableFunds < minimumPayoutAmount;
	const hasNegativeBalance = totalFunds < 0;
	const scheduleText = getScheduleText( overview );
	const hasErroredExternalAccount =
		overview.account.default_external_accounts?.some(
			( externalAccount ) =>
				externalAccount.currency.toLowerCase() ===
					currency.toLowerCase() &&
				externalAccount.status === 'errored'
		) ?? false;
	const accountLink =
		typeof overview.account.account_link === 'string'
			? overview.account.account_link
			: undefined;
	const canChangePayoutSchedule =
		! isPayoutsSuspended && hasCompletedWaitingPeriod;
	const isAwaitingPendingFunds =
		availableFunds === 0 && pendingFunds > 0 && hasCompletedWaitingPeriod;
	const hasNotices =
		isPayoutsSuspended ||
		hasErroredExternalAccount ||
		! hasCompletedWaitingPeriod ||
		hasNegativeBalance ||
		isBelowMinimumPayout ||
		isAwaitingPendingFunds;

	if (
		! hasCompletedWaitingPeriod &&
		availableFunds === 0 &&
		pendingFunds === 0 &&
		recentPayouts.length === 0
	) {
		return null;
	}

	return (
		<PayoutsCard
			isLoading={ false }
			history={ history }
			footer={ renderFooter( canChangePayoutSchedule ) }
			summary={
				<>
					{ scheduleText && (
						<CardBody className="woocommerce-woopayments-overview__schedule">
							{ /* Its own element: an interpolated fragment among siblings trips React's missing-key warning. */ }
							<span>{ scheduleText }</span>
							<HelpPopover
								label={ __(
									'Payout schedule tooltip',
									'woocommerce'
								) }
							>
								{ createInterpolateElement(
									__(
										'The timing and amount of your payouts may vary due to several factors. Check out our <a>payout schedule guide</a> for details.',
										'woocommerce'
									),
									{
										a: (
											<ExternalLink
												href={
													PAYOUT_SCHEDULE_DOCS_URL
												}
											>
												<></>
											</ExternalLink>
										),
									}
								) }
							</HelpPopover>
						</CardBody>
					) }

					{ hasNotices && (
						<CardBody className="woocommerce-woopayments-overview__notices">
							{ isPayoutsSuspended && (
								<PayoutNotice>
									{ __(
										'Your payouts are temporarily suspended.',
										'woocommerce'
									) }{ ' ' }
									<ExternalLink
										href={ SUSPENDED_PAYOUTS_DOCS_URL }
									>
										{ __( 'Learn more', 'woocommerce' ) }
									</ExternalLink>
								</PayoutNotice>
							) }
							{ hasErroredExternalAccount && (
								<FailedPayoutNotice
									accountLink={ accountLink }
								/>
							) }
							{ ! isPayoutsSuspended && (
								<>
									{ ! hasCompletedWaitingPeriod && (
										<PayoutNotice>
											{ __(
												'Payout scheduling becomes available after the standard 7-day waiting period for new accounts is complete.',
												'woocommerce'
											) }{ ' ' }
											<ExternalLink
												href={
													NEW_ACCOUNT_WAITING_PERIOD_DOCS_URL
												}
											>
												{ __(
													'Learn more',
													'woocommerce'
												) }
											</ExternalLink>
										</PayoutNotice>
									) }
									{ hasNegativeBalance && (
										<PayoutNotice>
											{ sprintf(
												/* translators: %s: WooPayments */
												__(
													'Payouts may be interrupted while your %s balance remains negative.',
													'woocommerce'
												),
												'WooPayments'
											) }{ ' ' }
											<ExternalLink
												href={
													NEGATIVE_BALANCE_DOCS_URL
												}
											>
												{ __( 'Why?', 'woocommerce' ) }
											</ExternalLink>
										</PayoutNotice>
									) }
									{ isBelowMinimumPayout && (
										<PayoutNotice>
											{ sprintf(
												/* translators: %s: formatted minimum payout amount. */
												__(
													'Payouts are paused while your available funds balance remains below %s.',
													'woocommerce'
												),
												// Client 11.1.0 `components/deposits-overview/index.tsx:186`.
												formatExplicitCurrency(
													minimumPayoutAmount,
													currency
												)
											) }{ ' ' }
											<ExternalLink
												href={ MINIMUM_PAYOUT_DOCS_URL }
											>
												{ __(
													'Learn more',
													'woocommerce'
												) }
											</ExternalLink>
										</PayoutNotice>
									) }
									{ isAwaitingPendingFunds && (
										<PayoutNotice>
											{ __(
												'You have no funds available.',
												'woocommerce'
											) }{ ' ' }
											<ExternalLink
												href={ PENDING_FUNDS_DOCS_URL }
											>
												{ __( 'Why?', 'woocommerce' ) }
											</ExternalLink>
										</PayoutNotice>
									) }
								</>
							) }
						</CardBody>
					) }
				</>
			}
		/>
	);
};
