/**
 * External dependencies
 */
import { ExternalLink, Notice } from '@wordpress/components';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsOverviewShell,
} from './overview/data';
import { PayoutSchedule } from './overview/components/payout-schedule';
import type {
	WooPaymentsDepositsAccount,
	WooPaymentsOverviewAccountStatus,
} from './overview/types';
import { saveOption } from '../settings/data/actions';

type WooPaymentsPayoutsNoticesWindow = typeof window & {
	wcSettings?: {
		admin?: {
			woopaymentsSettings?: { isNextDepositNoticeDismissed?: boolean };
		};
	};
};

const getPreloadedSettings = () =>
	( window as WooPaymentsPayoutsNoticesWindow ).wcSettings?.admin
		?.woopaymentsSettings;

// Client 11.1.0 `deposits/utils/index.ts:46-56`.
const AUTOMATIC_SCHEDULE_INTERVALS = [ 'daily', 'weekly', 'monthly' ];

/**
 * The payout schedule and failed-payout notices above the payouts list, as client 11.1.0 `deposits/index.tsx:28-145` shows them.
 */
export const WooPaymentsPayoutsNotices = () => {
	const [ account, setAccount ] =
		useState< WooPaymentsDepositsAccount | null >( null );
	const [ accountStatus, setAccountStatus ] =
		useState< WooPaymentsOverviewAccountStatus | null >( null );
	const [ isScheduleNoticeDismissed, setIsScheduleNoticeDismissed ] =
		useState(
			() => getPreloadedSettings()?.isNextDepositNoticeDismissed === true
		);

	useEffect( () => {
		let isMounted = true;

		getWooPaymentsDepositsOverview()
			.then( ( overview ) => isMounted && setAccount( overview.account ) )
			.catch( () => undefined );
		getWooPaymentsOverviewShell()
			.then(
				( shell ) =>
					isMounted && setAccountStatus( shell.account_status )
			)
			.catch( () => undefined );

		return () => {
			isMounted = false;
		};
	}, [] );

	const dismissScheduleNotice = () => {
		setIsScheduleNoticeDismissed( true );
		// The client also marks its localized settings, so the notice stays hidden when the page is opened again without a reload.
		const preloadedSettings = getPreloadedSettings();
		if ( preloadedSettings ) {
			preloadedSettings.isNextDepositNoticeDismissed = true;
		}
		void saveOption( 'wcpay_next_deposit_notice_dismissed', true );
	};

	const hasErroredExternalAccount =
		account?.default_external_accounts?.some(
			( externalAccount ) => externalAccount.status === 'errored'
		) ?? false;
	const showScheduleNotice =
		accountStatus?.deposits?.restrictions === 'deposits_unrestricted' &&
		!! accountStatus.deposits.completed_waiting_period &&
		!! account &&
		! isScheduleNoticeDismissed &&
		AUTOMATIC_SCHEDULE_INTERVALS.includes(
			account.deposits_schedule?.interval ?? ''
		) &&
		! hasErroredExternalAccount;
	const accountLink = accountStatus?.account_link
		? addQueryArgs( accountStatus.account_link, {
				from: 'WCPAY_PAYOUTS',
				source: 'wcpay-payout-failure-notice',
		  } )
		: '';

	return (
		<>
			{ showScheduleNotice && (
				<Notice status="info" onRemove={ dismissScheduleNotice }>
					<PayoutSchedule
						depositsSchedule={ account?.deposits_schedule }
					/>
				</Notice>
			) }
			{ hasErroredExternalAccount && accountLink !== '' && (
				<Notice status="warning" isDismissible={ false }>
					{ createInterpolateElement(
						__(
							'Payouts are currently paused because a recent payout failed. Please <a>update your bank account details</a>.',
							'woocommerce'
						),
						{
							a: (
								<ExternalLink
									href={ accountLink }
									onClick={ () =>
										recordEvent(
											'wcpay_account_details_link_clicked',
											{
												from: 'WCPAY_PAYOUTS',
												source: 'wcpay-payout-failure-notice',
											}
										)
									}
								>
									<></>
								</ExternalLink>
							),
						}
					) }
				</Notice>
			) }
		</>
	);
};
