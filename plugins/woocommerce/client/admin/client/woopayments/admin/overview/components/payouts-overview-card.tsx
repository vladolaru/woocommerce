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
import { __, sprintf } from '@wordpress/i18n';
import { calendar } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
	WooPaymentsOverviewAccountStatus,
} from '../types';
import {
	formatPayoutSiteDate,
	formatWooPaymentsAmount,
	getAmountForCurrency,
	getSelectedBalanceCurrency,
} from '../utils';
import {
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../../utils';
import { getPayoutStatusLabel } from '../../payout-status';
import { formatExplicitCurrency } from '../../currency';
import { getPayoutScheduleText, PayoutSchedule } from './payout-schedule';
import { StatusChip, type StatusChipType } from './status-chip';
import { LoadablePlaceholder } from './loadable-placeholder';

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
				// Client 11.1.0 `components/deposits-overview/deposit-notices.tsx:203-216`.
				<ExternalLink
					href={ accountLinkWithSource }
					onClick={ () =>
						recordEvent( 'wcpay_account_details_link_clicked', {
							from: 'WCPAY_PAYOUTS',
							source: 'wcpay-payout-failure-notice',
						} )
					}
				>
					{ __( 'update your bank account details', 'woocommerce' ) }
				</ExternalLink>
			) : (
				__( 'Update your bank account details.', 'woocommerce' )
			) }
			{ accountLinkWithSource ? '.' : '' }
		</PayoutNotice>
	);
};

const getPayoutDetailsRoute = ( payout: WooPaymentsDeposit ) =>
	`/woopayments/payouts/details?id=${ encodeURIComponent( payout.id ) }`;

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
						{ /* Client 11.1.0 `recent-deposits-list.tsx:46-51`: the icon and the date share one flex row. */ }
						<span className="woocommerce-woopayments-overview__payout-date">
							<Icon icon={ calendar } size={ 17 } />
							<a
								href={ getSettingsPaymentsProviderRouteUrl(
									getPayoutDetailsRoute( payout )
								) }
								onClick={ handleSettingsPaymentsProviderRouteClick(
									getPayoutDetailsRoute( payout )
								) }
							>
								{ formatPayoutSiteDate( payout ) }
							</a>
						</span>
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
	hasError = false,
	overview,
	recentPayouts,
	selectedCurrency,
	accountStatus,
}: {
	isLoading: boolean;
	/** The recent payouts read failed; the page raised the snackbar, so the history section stays out, as in the client. */
	hasError?: boolean;
	overview: WooPaymentsDepositsOverview | null;
	recentPayouts: WooPaymentsDeposit[];
	selectedCurrency?: string;
	/** The Overview shell's account status, which carries what client 11.1.0 reads from `wcpaySettings.accountStatus`. */
	accountStatus?: Pick<
		WooPaymentsOverviewAccountStatus,
		'account_link' | 'deposits'
	>;
} ) => {
	const historyUrl = getSettingsPaymentsProviderRouteUrl(
		'/woopayments/payouts'
	);
	const hasRecentPayouts =
		! isLoading && ! hasError && recentPayouts.length > 0;
	let historyStatusMessage: string = __(
		'No recent payouts.',
		'woocommerce'
	);

	if ( isLoading ) {
		historyStatusMessage = __( 'Loading payouts…', 'woocommerce' );
	} else if ( hasRecentPayouts ) {
		historyStatusMessage = __( 'Payout history loaded.', 'woocommerce' );
	}

	const history = hasError ? null : (
		<CardBody className="woocommerce-woopayments-overview__history">
			<h3>{ __( 'Payout history', 'woocommerce' ) }</h3>
			<p
				className={
					hasRecentPayouts || isLoading
						? 'screen-reader-text'
						: 'woocommerce-woopayments-overview__status'
				}
				role="status"
				aria-live="polite"
			>
				{ historyStatusMessage }
			</p>
			{ isLoading && (
				<LoadablePlaceholder isBlock>
					Block placeholder
				</LoadablePlaceholder>
			) }
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

	// Client 11.1.0 `components/deposits-overview/index.tsx:32-73`.
	if ( isLoading ) {
		return (
			<PayoutsCard
				isLoading
				summary={
					<CardBody className="woocommerce-woopayments-overview__schedule">
						<LoadablePlaceholder>
							{ __(
								'Available funds are automatically dispatched every day.',
								'woocommerce'
							) }
						</LoadablePlaceholder>
					</CardBody>
				}
				history={ history }
				footer={
					<CardFooter className="woocommerce-woopayments-overview__payouts-footer">
						<LoadablePlaceholder>
							{ __( 'View full payout history', 'woocommerce' ) }
						</LoadablePlaceholder>
						<LoadablePlaceholder>
							{ __( 'Change payout schedule', 'woocommerce' ) }
						</LoadablePlaceholder>
					</CardFooter>
				}
			/>
		);
	}

	if ( ! overview ) {
		if ( ! hasError && recentPayouts.length === 0 ) {
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
	// Client 11.1.0 `components/deposits-overview/index.tsx:82-113`: the cached account's payout fields, and only `deposits_blocked` suspends.
	const accountDeposits = accountStatus?.deposits;
	const isPayoutsUnrestricted =
		accountDeposits?.restrictions === 'deposits_unrestricted';
	const hasCompletedWaitingPeriod =
		!! accountDeposits?.completed_waiting_period;
	const isPayoutsSuspended = !! overview.account.deposits_blocked;
	const minimumPayoutAmount =
		accountDeposits?.minimum_scheduled_deposit_amounts?.[
			currency.toLowerCase()
		] ?? 0;
	const isBelowMinimumPayout =
		availableFunds > 0 && availableFunds < minimumPayoutAmount;
	const hasNegativeBalance = totalFunds < 0;
	const hasScheduleText =
		isPayoutsUnrestricted &&
		!! getPayoutScheduleText( overview.account.deposits_schedule );
	const hasErroredExternalAccount =
		overview.account.default_external_accounts?.some(
			( externalAccount ) =>
				externalAccount.currency.toLowerCase() ===
					currency.toLowerCase() &&
				externalAccount.status === 'errored'
		) ?? false;
	const accountLink = accountStatus?.account_link;
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
		pendingFunds === 0
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
					{ hasScheduleText && (
						<CardBody className="woocommerce-woopayments-overview__schedule">
							<PayoutSchedule
								depositsSchedule={
									overview.account.deposits_schedule
								}
							/>
						</CardBody>
					) }

					{ hasNotices && (
						<CardBody className="woocommerce-woopayments-overview__notices">
							{ /* Client 11.1.0 `components/deposits-overview/index.tsx:161-194`: suspended replaces every other notice, which keep this order. */ }
							{ isPayoutsSuspended ? (
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
							) : (
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
									{ hasErroredExternalAccount && (
										<FailedPayoutNotice
											accountLink={ accountLink }
										/>
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
								</>
							) }
						</CardBody>
					) }
				</>
			}
		/>
	);
};
