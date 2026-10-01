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

// Client 11.1.0 has the Overview's account data in the page (`class-wc-payments-admin.php:1020-1062`); the server preloads the shell the same way.
const getPreloadedOverviewShell = (): WooPaymentsOverviewShell | null => {
	const shell = getWooPaymentsSettingsBootstrap().overviewShell;

	return shell &&
		typeof shell === 'object' &&
		'account' in shell &&
		'account_status' in shell
		? ( shell as WooPaymentsOverviewShell )
		: null;
};

export const WooPaymentsOverviewPage = () => {
	const [ preloadedShell ] = useState( getPreloadedOverviewShell );
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
	const [ hasOverviewError, setOverviewError ] = useState( false );
	const [ hasPayoutsError, setPayoutsError ] = useState( false );
	const [ shell, setShell ] = useState< WooPaymentsOverviewShell | null >(
		preloadedShell
	);
	const [ isShellSettled, setShellSettled ] = useState( !! preloadedShell );
	const [ disputes, setDisputes ] = useState< WooPaymentsOverviewDispute[] >(
		[]
	);
	const [ updateBusinessDetailsShell, setUpdateBusinessDetailsShell ] =
		useState< WooPaymentsOverviewShell | null >( null );
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
		setOverviewError( false );
		setSelectedCurrency(
			getSelectedBalanceCurrency( nextOverview, currency )
		);
		setRecentPayouts( recent.data );
		setPayoutsError( false );

		return deposit;
	};

	const accountStatus = shell?.account_status.status ?? '';
	// Client 11.1.0 `overview/index.js:113-116`: these accounts get a reduced page.
	const isAccountRejectedOrUnderReview =
		accountStatus.startsWith( 'rejected' ) ||
		accountStatus === 'under_review';
	// Client 11.1.0 `overview/index.js:306-367` mounts neither card, so neither request runs, for these accounts.
	const showBalanceAndPayouts =
		isShellSettled && ! isAccountRejectedOrUnderReview;

	useEffect( () => {
		if ( ! showBalanceAndPayouts ) {
			return;
		}

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
				setOverviewError( false );
				setSelectedCurrency( currency );
			} catch {
				if ( isMounted ) {
					// Client 11.1.0 `data/deposits/resolvers.js:61-71`: one snackbar, never the server's message.
					dispatch( 'core/notices' ).createErrorNotice(
						__(
							"Error retrieving all payouts' overviews.",
							'woocommerce'
						)
					);
					setOverview( null );
					setOverviewError( true );
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
	}, [ showBalanceAndPayouts ] );

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
					setPayoutsError( false );
				}
			} catch {
				if ( isMounted ) {
					// Client 11.1.0 `data/deposits/resolvers.js:130-136`.
					dispatch( 'core/notices' ).createErrorNotice(
						__( 'Error retrieving payouts.', 'woocommerce' )
					);
					setRecentPayouts( [] );
					setPayoutsError( true );
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

		const loadDisputes = ( nextShell: WooPaymentsOverviewShell ) => {
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
		};

		if ( preloadedShell ) {
			loadDisputes( preloadedShell );

			return () => {
				isMounted = false;
			};
		}

		getWooPaymentsOverviewShell()
			.then( ( nextShell ) => {
				if ( ! isMounted ) {
					return;
				}

				setShell( nextShell );
				setShellSettled( true );
				loadDisputes( nextShell );
			} )
			.catch( () => {
				if ( isMounted ) {
					setShell( null );
					setShellSettled( true );
					setDisputes( [] );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ preloadedShell ] );

	const showStripeBanner =
		!! shell?.account.connected && ! isAccountRejectedOrUnderReview;

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
		  } )
		: [];
	// Client 11.1.0 `overview/index.js:117-118,140-144`.
	const shouldShowConnectionSuccessModal =
		!! shell &&
		new URLSearchParams( window.location.search ).get(
			'wcpay-connection-success'
		) === '1' &&
		! shell.account.test_mode_onboarding &&
		shell.account_status.payments_enabled &&
		shell.account_status.deposits_enabled;
	// Client 11.1.0 `overview/index.js:134-139,385`: the account status decides, not whether payments are enabled.
	const showAccountStatusSections =
		!! shell?.account.connected && ! isAccountRejectedOrUnderReview;
	const shouldLoadDisputeReadiness =
		showAccountStatusSections &&
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
			{ shell && ! isAccountRejectedOrUnderReview && (
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
			{ showBalanceAndPayouts && (
				<>
					<AccountBalancesCard
						isLoading={ isLoading }
						hasError={ hasOverviewError }
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
						hasError={ hasPayoutsError }
						overview={ overview }
						recentPayouts={ recentPayouts }
						selectedCurrency={ selectedCurrency || undefined }
						accountStatus={ shell?.account_status }
					/>
				</>
			) }
			{ shell && (
				<>
					<AccountDetailsCard
						accountDetails={ shell.account_details }
						accountFees={ shell.account_fees }
						accountLink={ shell.account_status.account_link }
						isTestModeOnboarding={
							shell.account.test_mode_onboarding
						}
						onboardingUrl={ shell.urls.onboarding }
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
			{ showAccountStatusSections && (
				<Suspense fallback={ null }>
					<InboxNotifications />
				</Suspense>
			) }
			{ shouldShowConnectionSuccessModal && shell && (
				<ConnectionSuccessModal
					isDismissed={ shell.is_connection_success_modal_dismissed }
				/>
			) }
			<SpotlightPromotion />
		</div>
	);
};
