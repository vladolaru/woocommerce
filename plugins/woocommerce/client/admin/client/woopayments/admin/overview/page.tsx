/**
 * External dependencies
 */
import {
	createInterpolateElement,
	lazy,
	Suspense,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { Card, ExternalLink } from '@wordpress/components';
import { recordEvent } from '@woocommerce/tracks';
import { __experimentalErrorBoundary as ErrorBoundary } from '@woocommerce/components';
import type { LoadError } from '@stripe/connect-js';

/**
 * Internal dependencies
 */
import { WooPaymentsAccountSettings } from '~/woopayments/settings/account-settings';
import { SetupLivePaymentsModal } from '~/woopayments/settings/account-mode-notice';
import { getWooPaymentsSettingsBootstrap } from '~/woopayments/settings/bootstrap';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsOverviewDisputes,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
	submitWooPaymentsInstantDeposit,
} from './data';
import { AccountBalancesCard } from './components/account-balances-card';
import { PayoutsOverviewCard } from './components/payouts-overview-card';
import { AccountDetailsCard } from './components/account-details-card';
import { ActiveLoanSummaryCard } from './components/active-loan-summary-card';
import { DisputeReadinessCard } from './components/dispute-readiness-card';
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
	WooPaymentsOverviewDispute,
	WooPaymentsOverviewShell,
} from './types';
import {
	getSelectedBalanceCurrency,
	getSettingsPaymentsProviderRouteUrl,
} from './utils';
import { SpotlightPromotion } from '../../promotions/spotlight';
import {
	OverviewModeNotice,
	OverviewNotices,
} from './components/overview-notices';
import { buildOverviewTasks } from './components/overview-tasks';
import { OverviewTaskList } from './components/overview-task-list';
import { UpdateBusinessDetailsModal } from './components/update-business-details-modal';
import { ConnectionSuccessModal } from './components/connection-success-modal';
import type { StripeNotificationsChange } from './components/stripe-notifications-banner';
import StripeSpinner from '~/settings-payments/onboarding/providers/woopayments/components/stripe-spinner';
import BannerNotice from '~/settings-payments/onboarding/providers/woopayments/components/banner-notice';

const InboxNotifications = lazy( () =>
	import( './components/inbox-notifications' ).then( ( module ) => ( {
		default: module.InboxNotifications,
	} ) )
);

const StripeNotificationsBanner = lazy(
	() => import( './components/stripe-notifications-banner' )
);

const getErrorMessage = ( error: unknown ) => {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}

	return __( 'Unable to load WooPayments payout data.', 'woocommerce' );
};

export const WooPaymentsOverviewPage = () => {
	const [ overview, setOverview ] =
		useState< WooPaymentsDepositsOverview | null >( null );
	const [ recentPayouts, setRecentPayouts ] = useState<
		WooPaymentsDeposit[]
	>( [] );
	const [ selectedCurrency, setSelectedCurrency ] = useState< string | null >(
		null
	);
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isPayoutsLoading, setIsPayoutsLoading ] = useState( false );
	const [ overviewErrorMessage, setOverviewErrorMessage ] = useState<
		string | null
	>( null );
	const [ payoutsErrorMessage, setPayoutsErrorMessage ] = useState<
		string | null
	>( null );
	const [ shell, setShell ] = useState< WooPaymentsOverviewShell | null >(
		null
	);
	const [ disputes, setDisputes ] = useState< WooPaymentsOverviewDispute[] >(
		[]
	);
	const [ updateBusinessDetailsShell, setUpdateBusinessDetailsShell ] =
		useState< WooPaymentsOverviewShell | null >( null );
	const [ isGoLiveModalVisible, setGoLiveModalVisible ] = useState( false );
	// Client 11.1.0 `overview/index.js:72-92`: the update-details task stays hidden unless the Stripe banner fails to load.
	const [ bannerLoadError, setBannerLoadError ] =
		useState< LoadError | null >( null );
	const [ isBannerShown, setBannerShown ] = useState( false );
	const [ hasBannerFailed, setBannerFailed ] = useState( false );
	const [ isBannerLoading, setBannerLoading ] = useState( true );
	const [ bannerCountMemo, setBannerCountMemo ] = useState( 0 );

	// Client 11.1.0 `tos/request.ts:30-45`: record the KYC completion once, then clear the flag.
	useEffect( () => {
		const tracked = getWooPaymentsSettingsBootstrap()
			.trackStripeConnected as { is_existing_stripe_account?: boolean };
		if ( ! tracked || ! window.wcTracks?.isEnabled ) {
			return;
		}
		recordEvent( 'wcpay_stripe_connected', {
			is_existing_stripe_account: tracked.is_existing_stripe_account,
		} );
		void apiFetch( {
			path: '/wc/v3/payments/tos/stripe_track_connected',
			method: 'POST',
		} );
	}, [] );

	const reloadOverviewAndPayouts = async ( currency: string ) => {
		const deposit = await submitWooPaymentsInstantDeposit( currency );
		const [ nextOverview, recent ] = await Promise.all( [
			getWooPaymentsDepositsOverview(),
			getWooPaymentsRecentDeposits( currency ),
		] );

		setOverview( nextOverview );
		setOverviewErrorMessage( null );
		setSelectedCurrency(
			getSelectedBalanceCurrency( nextOverview, currency )
		);
		setRecentPayouts( recent.data );
		setPayoutsErrorMessage( null );

		return deposit;
	};

	useEffect( () => {
		let isMounted = true;

		const loadOverview = async () => {
			setIsLoading( true );

			try {
				const nextOverview = await getWooPaymentsDepositsOverview();
				const currency = getSelectedBalanceCurrency(
					nextOverview,
					null
				);

				if ( ! isMounted ) {
					return;
				}

				setOverview( nextOverview );
				setOverviewErrorMessage( null );
				setSelectedCurrency( currency );
			} catch ( error ) {
				if ( isMounted ) {
					setOverview( null );
					setOverviewErrorMessage( getErrorMessage( error ) );
					setSelectedCurrency( '' );
				}
			} finally {
				if ( isMounted ) {
					setIsLoading( false );
				}
			}
		};

		void loadOverview();

		return () => {
			isMounted = false;
		};
	}, [] );

	useEffect( () => {
		if ( selectedCurrency === null ) {
			return;
		}

		let isMounted = true;

		const loadRecentPayouts = async () => {
			setIsPayoutsLoading( true );

			try {
				const recent =
					await getWooPaymentsRecentDeposits( selectedCurrency );

				if ( isMounted ) {
					setRecentPayouts( recent.data );
					setPayoutsErrorMessage( null );
				}
			} catch ( error ) {
				if ( isMounted ) {
					setRecentPayouts( [] );
					setPayoutsErrorMessage( getErrorMessage( error ) );
				}
			} finally {
				if ( isMounted ) {
					setIsPayoutsLoading( false );
				}
			}
		};

		void loadRecentPayouts();

		return () => {
			isMounted = false;
		};
	}, [ selectedCurrency ] );

	useEffect( () => {
		let isMounted = true;

		getWooPaymentsOverviewShell()
			.then( ( nextShell ) => {
				if ( ! isMounted ) {
					return;
				}

				setShell( nextShell );

				if (
					! nextShell.account.connected ||
					nextShell.disputes_awaiting_response_count === 0
				) {
					setDisputes( [] );
					return;
				}

				getWooPaymentsOverviewDisputes()
					.then( ( response ) => {
						if ( isMounted ) {
							setDisputes( response.data ?? [] );
						}
					} )
					.catch( () => {
						if ( isMounted ) {
							setDisputes( [] );
						}
					} );
			} )
			.catch( () => {
				if ( isMounted ) {
					setShell( null );
					setDisputes( [] );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	const accountStatus = shell?.account_status.status ?? '';
	const showStripeBanner =
		!! shell?.account.connected &&
		! accountStatus.startsWith( 'rejected' ) &&
		accountStatus !== 'under_review';

	// Client 11.1.0 `overview/index.js:179-235`, per Stripe's custom notification-banner behavior.
	const handleNotificationsChange = ( {
		total,
		actionRequired,
	}: StripeNotificationsChange ) => {
		if ( actionRequired > 0 || total > 0 ) {
			setBannerShown( true );
			recordEvent( 'wcpay_overview_stripe_notifications_banner_update', {
				action_required_count: actionRequired,
				total_count: total,
			} );
			setBannerCountMemo( total );
		} else {
			// Everything was addressed since the last change: ask for a refresh.
			if ( bannerCountMemo > 0 ) {
				dispatch( 'core/notices' ).createSuccessNotice(
					__(
						'Updates take a moment to appear. Please refresh the page in a minute.',
						'woocommerce'
					),
					{
						actions: [
							{
								label: __( 'Refresh', 'woocommerce' ),
								url: getSettingsPaymentsProviderRouteUrl(
									'/woopayments/overview'
								),
							},
						],
						explicitDismiss: true,
					}
				);
				recordEvent(
					'wcpay_overview_stripe_notifications_banner_action_completed'
				);
			}
			setBannerShown( false );
		}
		setBannerLoading( false );
	};

	const tasks = shell
		? buildOverviewTasks( {
				shell,
				disputes,
				// Client 11.1.0 `overview/index.js:72-74,105-109`: only after the banner fails to load.
				showUpdateDetailsTask:
					showStripeBanner &&
					( !! bannerLoadError || hasBannerFailed ),
				onOpenUpdateBusinessDetails: setUpdateBusinessDetailsShell,
				// Client 11.1.0 `overview/task-list/tasks/go-live-task.tsx:12` opens the modal directly.
				onActivatePayments: () => setGoLiveModalVisible( true ),
		  } )
		: [];
	const shouldShowConnectionSuccessModal =
		!! shell &&
		new URLSearchParams( window.location.search ).get(
			'wcpay-connection-success'
		) === '1' &&
		! shell.account.test_mode_onboarding &&
		shell.account.can_process_payments &&
		shell.account_status.deposits_enabled;
	const hasWorkingConnectedAccount =
		!! shell && shell.account.connected && shell.account.working;
	const shouldLoadDisputeReadiness =
		hasWorkingConnectedAccount &&
		!! shell?.feature_flags?.dispute_readiness_overview;

	return (
		<div className="woocommerce-woopayments-overview__content">
			<h1 className="woocommerce-woopayments-overview__title">
				{ __( 'Overview', 'woocommerce' ) }
			</h1>
			<OverviewNotices />
			{ shell && (
				<OverviewModeNotice
					account={ shell.account }
					setupUrl={ shell.urls.setup }
				/>
			) }
			{ bannerLoadError?.error.type === 'invalid_request_error' && (
				<BannerNotice
					status="warning"
					icon={ true }
					isDismissible={ false }
				>
					{ createInterpolateElement(
						__(
							'Some account related notifications require HTTPS and cannot be displayed. View them on our financial partner’s website. <a>See details</a>',
							'woocommerce'
						),
						{
							a: (
								<ExternalLink href="https://woocommerce.com/document/woopayments/startup-guide/#requirements">
									<></>
								</ExternalLink>
							),
						}
					) }
				</BannerNotice>
			) }
			{ showStripeBanner && (
				<>
					{ isBannerLoading && accountStatus !== 'complete' && (
						<Card>
							<div className="stripe-notifications-banner-loader">
								<StripeSpinner />
							</div>
						</Card>
					) }
					<div
						className="stripe-notifications-banner-wrapper"
						style={ { display: isBannerShown ? 'block' : 'none' } }
					>
						{ /* Client 11.1.0 `overview/index.js:326`; also catches a failed chunk load. */ }
						<ErrorBoundary
							onError={ () => {
								setBannerFailed( true );
								setBannerLoading( false );
							} }
						>
							<Suspense fallback={ null }>
								<StripeNotificationsBanner
									// Client 11.1.0 keeps the wrapper hidden on a session failure (`overview/index.js:318-325`); unlike its endless spinner, stop loading (inbox N-139).
									onInitError={ () => {
										setBannerFailed( true );
										setBannerLoading( false );
									} }
									onLoadError={ ( loadError ) => {
										setBannerLoadError( loadError );
										setBannerLoading( false );
									} }
									onNotificationsChange={
										handleNotificationsChange
									}
								/>
							</Suspense>
						</ErrorBoundary>
					</div>
				</>
			) }
			{ shell && (
				<OverviewTaskList
					tasks={ tasks }
					visibility={ shell.overview_tasks_visibility }
				/>
			) }
			{ updateBusinessDetailsShell && (
				<UpdateBusinessDetailsModal
					shell={ updateBusinessDetailsShell }
					onClose={ () => setUpdateBusinessDetailsShell( null ) }
				/>
			) }
			{ isGoLiveModalVisible && (
				<SetupLivePaymentsModal
					from="WCPAY_GO_LIVE_TASK"
					source="wcpay-go-live-task"
					setupUrl={ shell?.urls.setup }
					onClose={ () => setGoLiveModalVisible( false ) }
				/>
			) }
			<div className="woocommerce-woopayments-overview__cards">
				<AccountBalancesCard
					isLoading={ isLoading }
					errorMessage={ overviewErrorMessage }
					overview={ overview }
					selectedCurrency={ selectedCurrency || undefined }
					onCurrencyChange={ setSelectedCurrency }
					onInstantPayoutSubmit={ reloadOverviewAndPayouts }
					instantDepositsPreviouslyEligible={
						!! shell?.instant_deposits_previously_eligible
					}
				/>
				<PayoutsOverviewCard
					isLoading={ isLoading || isPayoutsLoading }
					errorMessage={ payoutsErrorMessage }
					overview={ overview }
					recentPayouts={ recentPayouts }
					selectedCurrency={ selectedCurrency || undefined }
				/>
			</div>
			{ shell && (
				<>
					<AccountDetailsCard
						accountDetails={ shell.account_details }
						accountFees={ shell.account_fees }
						accountLink={ shell.account_status.account_link }
					/>
					<DisputeReadinessCard
						enabled={ shouldLoadDisputeReadiness }
						focusAfterDismissId="woocommerce-woopayments-balance-heading"
					/>
					<ActiveLoanSummaryCard
						hasActiveLoan={
							!! shell.account_loans?.has_active_loan
						}
						loans={ shell.account_loans?.loans }
					/>
				</>
			) }
			{ hasWorkingConnectedAccount && (
				<Suspense fallback={ null }>
					<InboxNotifications />
				</Suspense>
			) }
			{ shouldShowConnectionSuccessModal && shell && (
				<ConnectionSuccessModal
					isDismissed={ shell.is_connection_success_modal_dismissed }
				/>
			) }
			<WooPaymentsAccountSettings headingLevel={ 2 } />
			<SpotlightPromotion />
		</div>
	);
};
